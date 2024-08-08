<?php

declare(strict_types=1);

namespace S3\Tunnel\Client\Http;

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
use Psr\Log\LoggerInterface;
use S3\Tunnel\Client\Http\Action\HomeAction;
use S3\Tunnel\Client\Tcp\TcpClient;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

use function FastRoute\simpleDispatcher;

final class HttpServer
{
    private readonly Dispatcher $dispatcher;
    /** @var int[] $streamConnection */
    private array $streamConnection;
    private Channel $randomSubDomainChannel;

    public function __construct(
        private readonly TcpClient $tcpClient,
        private readonly LoggerInterface $logger,
        private readonly Channel $eventChannel,
    ) {
        $this->streamConnection = [];
        $this->randomSubDomainChannel = new Channel();

        $this->dispatcher = simpleDispatcher(function (RouteCollector $router) {
            $router->addRoute('GET', '/', new HomeAction());
        });
    }

    public function request(Request $swooleRequest, Response $swooleResponse): void
    {
        if ($swooleRequest->server['request_uri'] == '/stream') {
            $swooleResponse->header('Content-Type', 'text/event-stream');
            $swooleResponse->header('Cache-Control', 'no-cache');
            $swooleResponse->header('Connection', 'keep-alive');
            $swooleResponse->header('X-Accel-Buffering', 'no');
            $swooleResponse->header('Content-Encoding', '');
            $swooleResponse->header('Content-Length', '');
            $swooleResponse->end();
            $this->streamConnection[$swooleResponse->fd] = $swooleResponse->fd;
            $this->logger->info("Create a new stream: $swooleResponse->fd");
            return;
        }

        if ($swooleRequest->server['request_uri'] == '/random-subdomain') {
            $randomSubDomain = $this->randomSubDomainChannel->pop();
            $swooleResponse->status(200);
            $swooleResponse->header('Content-Type', 'text/plain');
            $swooleResponse->end($randomSubDomain);
            $this->randomSubDomainChannel->push($randomSubDomain);
        }

        $psrRequest = $this->parseRequest($swooleRequest);
        $routeInfo = $this->dispatcher->dispatch(httpMethod: $swooleRequest->getMethod() ?: 'GET', uri: $swooleRequest->server['request_uri'] ?? '/');
        $psrResponse = match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND => new TextResponse(text: 'Not Found', status: 404),
            Dispatcher::METHOD_NOT_ALLOWED => new TextResponse(text: 'Method Not Allowed', status: 405),
            Dispatcher::FOUND => $this->handleRequest($psrRequest, $routeInfo[1]),
            default => new TextResponse(text: 'Internal Server Error', status: 500),
        };
        $this->emitResponse($swooleResponse, $psrResponse);
    }

    public function close(Server $server, int $fd, int $reactorId): void
    {
        $this->logger->info("Close connection $fd");
        unset($this->streamConnection[$fd]);
    }

    public function start(Server $server): void
    {
        go($this->tcpClient->start(...));
        go(function () use ($server) {
            while ($event = $this->eventChannel->pop()) {
                /** @var array{event: string, requestId: string, uri: string} $event */
                if ($event['event'] === 'random-subdomain') {
                    if ($this->randomSubDomainChannel->isFull()) {
                        $this->randomSubDomainChannel->pop();
                    }
                    $this->randomSubDomainChannel->push($event['uri']);
                }
                foreach ($this->streamConnection as $fd) {
                    $data = "id: {$event['requestId']}\n";
                    $data .= "event: {$event['event']}\n";
                    $data .= 'data: ' . json_encode($event) . "\n\n";
                    $server->send($fd, $data);
                }
            }
        });
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
