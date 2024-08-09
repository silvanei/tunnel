<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Http;

use Laminas\Stratigility\MiddlewarePipeInterface;
use S3\Tunnel\Shared\Http\BaseHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class HttpServer extends BaseHttpServer
{
    public function __construct(
        MiddlewarePipeInterface $httpMiddlewarePipe,
        private readonly MiddlewarePipeInterface $tcpMiddlewarePipe,
    ) {
        parent::__construct($httpMiddlewarePipe);
    }

    public function request(Request $swooleRequest, Response $swooleResponse): void
    {
        $subdomain = $this->extractSubdomain($swooleRequest);
        if ($subdomain === '') {
            $this->dispatch($swooleRequest, $swooleResponse);
        }

        $psrRequest = $this->parseRequest($swooleRequest);
        $psrResponse = $this->tcpMiddlewarePipe->handle($psrRequest->withAttribute('subdomain', $subdomain));
        $this->emitResponse($swooleResponse, $psrResponse);
    }

    private function extractSubdomain(Request $request): string
    {
        $domain = getenv('SERVER_DOMAIN') ?: 'tunnel.localhost';
        preg_match("/(?<subdomain>.*).$domain/", $request->header['host'], $matches);
        return $matches['subdomain'] ?? '';
    }
}
