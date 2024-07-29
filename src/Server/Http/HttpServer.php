<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Laminas\Stratigility\MiddlewarePipe;
use Mezzio\Session\Cache\CacheSessionPersistence;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Server\Http\Controller\AuthenticationAction;
use S3\Tunnel\Server\Http\Controller\AuthorizationAction;
use S3\Tunnel\Server\Http\Controller\GoogleAction;
use S3\Tunnel\Server\Http\Controller\HomeAction;
use S3\Tunnel\Server\Http\Controller\TcpDispatchAction;
use S3\Tunnel\Server\Http\Middleware\AuthorizationMiddleware;
use S3\Tunnel\Shared\GitHub\GitHubService;
use Swoole\Http\Request;
use Swoole\Http\Response;

use function FastRoute\simpleDispatcher;

final readonly class HttpServer
{
    private Dispatcher $dispatcher;

    public function __construct(
        private TcpDispatchAction $tcpDispatchController,
        private GitHubService $githubService,
    ) {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $router) {
            $router->addRoute('GET', '/', new HomeAction());
            $router->addRoute('GET', '/google7b953163902ce6a3.html', new GoogleAction());
            $router->addRoute('GET', '/authentication', new AuthenticationAction());
            $router->addRoute('GET', '/github/authorization-callback', new AuthorizationAction($this->githubService));
        });
    }

    public function request(Request $swooleRequest, Response $swooleResponse): void
    {
        $psrRequest = $this->parseRequest($swooleRequest);
        $subdomain = $this->extractSubdomain($swooleRequest);
        if ($subdomain === '') {
            $routeInfo = $this->dispatcher->dispatch(httpMethod: $swooleRequest->getMethod() ?: 'GET', uri: $swooleRequest->server['request_uri'] ?? '/');
            $psrResponse = match ($routeInfo[0]) {
                Dispatcher::NOT_FOUND => new TextResponse(text: 'Not Found', status: 404),
                Dispatcher::METHOD_NOT_ALLOWED => new TextResponse(text: 'Method Not Allowed', status: 405),
                Dispatcher::FOUND => $this->handleRequest($psrRequest, $routeInfo[1]),
                default => new TextResponse(text: 'Internal Server Error', status: 500),
            };
            $this->emitResponse($swooleResponse, $psrResponse);
            return;
        }

        $psrResponse = $this->handleRequest($psrRequest->withAttribute('subdomain', $subdomain), $this->tcpDispatchController);
        $this->emitResponse($swooleResponse, $psrResponse);
    }

    private function extractSubdomain(Request $request): string
    {
        $domain = getenv('SERVER_DOMAIN') ?: 'tunnel.localhost';
        preg_match("/(?<subdomain>.*).$domain/", $request->header['host'], $matches);
        return $matches['subdomain'] ?? '';
    }

    private function parseRequest(Request $request): ServerRequestInterface
    {
        return new ServerRequest(
            serverParams: $request->server ?? [],
            uploadedFiles: $request->files ?? [],
            uri: $request->server['request_uri'] ?? '/',
            method: $request->getMethod() ?: 'GET',
            body: (new StreamFactory())->createStream(content: $request->rawContent() ?: ''),
            headers: $request->header ?? [],
            cookieParams: $request->cookie ?? [],
            queryParams: $request->get ?? [],
        );
    }

    private function handleRequest(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $middlewarePipe = new MiddlewarePipe();
        if (! $request->getAttribute('subdomain')) {
            $middlewarePipe->pipe(new SessionMiddleware(
                new CacheSessionPersistence(
                    new CacheItemPoolDecorator(
                        new Filesystem()
                    ),
                    'TUNNEL-SESSION-ID',
                )
            ));
            $middlewarePipe->pipe(new AuthorizationMiddleware($this->githubService));
        }
        $middlewarePipe->pipe(new RequestHandlerMiddleware($handler));
        return $middlewarePipe->handle($request);
    }

    private function emitResponse(Response $swooleResponse, ResponseInterface $psrResponse): void
    {
        $psrResponse = $this->setCookie($psrResponse, $swooleResponse);
        foreach ($psrResponse->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->setHeader($name, $value);
            }
        }
        $swooleResponse->setStatusCode($psrResponse->getStatusCode(), $psrResponse->getReasonPhrase());
        $swooleResponse->end($psrResponse->getBody()->getContents());
    }

    private function setCookie(ResponseInterface $psrResponse, Response $swooleResponse): ResponseInterface
    {
        if (! $psrResponse->hasHeader('Set-Cookie')) {
            return $psrResponse;
        }

        $cookies = $psrResponse->getHeader('Set-Cookie');
        foreach ($cookies as $string) {
            if (! $attributes = preg_split('/\s*;\s*/', $string, -1, PREG_SPLIT_NO_EMPTY)) {
                continue;
            }

            $nameAndValue = explode('=', array_shift($attributes), 2);
            $cookie = ['name' => $nameAndValue[0], 'value' => isset($nameAndValue[1]) ? urldecode($nameAndValue[1]) : ''];
            while ($attribute = array_shift($attributes)) {
                $attribute = explode('=', $attribute, 2);
                $attributeName = strtolower($attribute[0]);
                $attributeValue = $attribute[1] ?? null;

                if (in_array($attributeName, ['expires', 'domain', 'path', 'samesite'], true)) {
                    $cookie[$attributeName] = $attributeValue;
                    continue;
                }

                if (in_array($attributeName, ['secure', 'httponly'], true)) {
                    $cookie[$attributeName] = true;
                    continue;
                }

                if ($attributeName === 'max-age') {
                    $cookie['expires'] = time() + (int)$attributeValue;
                }
            }

            $swooleResponse->setCookie(...$cookie);
        }

        return $psrResponse->withoutHeader('Set-Cookie');
    }
}
