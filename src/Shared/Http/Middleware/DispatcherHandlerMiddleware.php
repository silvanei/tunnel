<?php

declare(strict_types=1);

namespace S3\Tunnel\Shared\Http\Middleware;

use FastRoute\Dispatcher;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DispatcherHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private Dispatcher $dispatcher)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeInfo = $this->dispatcher->dispatch(httpMethod: $request->getMethod(), uri: (string)$request->getUri());
        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND => new TextResponse(text: 'Not Found', status: 404),
            Dispatcher::METHOD_NOT_ALLOWED => new TextResponse(text: 'Method Not Allowed', status: 405),
            Dispatcher::FOUND => $routeInfo[1]->handle($request),
            default => new TextResponse(text: 'Internal Server Error', status: 500),
        };
    }
}
