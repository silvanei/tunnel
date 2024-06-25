<?php

declare(strict_types=1);

namespace S3\Tunnel\Http;

use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Tcp\Message\RequestMessage;
use S3\Tunnel\Tcp\Request\DispatchRequestMessageContext;
use Swoole\Coroutine;

final readonly class TcpDispatchController implements RequestHandlerInterface
{
    public function __construct(private float $timeout = 30)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $subdomain = $request->getAttribute(name: 'subdomain', default: '');
        $dispatchRequestMessage = DispatchRequestMessageContext::get($subdomain);
        if (! $dispatchRequestMessage) {
            return new TextResponse(text: 'Not Found', status: 404);
        }

        $requestId = md5(Coroutine::getCid() . '::' . $subdomain);
        $message = new RequestMessage(
            requestId: $requestId,
            method: $request->getMethod(),
            uri: (string)$request->getUri(),
            header: $request->getHeaders(),
            body: $request->getBody()->getContents(),
            version: '1.1',
            query: http_build_query($request->getQueryParams()),
            get: $request->getQueryParams(),
        );
        return $dispatchRequestMessage->dispatch($message, $this->timeout);
    }
}
