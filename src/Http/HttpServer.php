<?php

declare(strict_types=1);

namespace S3\Tunnel\Http;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Laminas\Stratigility\MiddlewarePipe;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

use function FastRoute\simpleDispatcher;

final readonly class HttpServer
{
    private Dispatcher $dispatcher;

    public function __construct(private readonly TcpDispatchController $tcpDispatchController)
    {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $router) {
            $router->addRoute('GET', '/', new HelloWorldController());
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
        var_dump($request->header['host']);
        preg_match('/(?<subdomain>.*).tunnel.localhost/', $request->header['host'], $matches);
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
        $middlewarePipe->pipe(new RequestHandlerMiddleware($handler));
        return $middlewarePipe->handle($request);
    }

    private function emitResponse(Response $swooleResponse, ResponseInterface $psrResponse): void
    {
        foreach ($psrResponse->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->setHeader($name, $value);
            }
        }

        $swooleResponse->setStatusCode($psrResponse->getStatusCode(), $psrResponse->getReasonPhrase());
        $swooleResponse->end($psrResponse->getBody()->getContents());
    }
}
