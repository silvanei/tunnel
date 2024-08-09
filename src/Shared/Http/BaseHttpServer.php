<?php

declare(strict_types=1);

namespace S3\Tunnel\Shared\Http;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Stratigility\MiddlewarePipe;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

abstract class BaseHttpServer
{
    public function __construct(
        private readonly MiddlewarePipeInterface $middlewarePipe = new MiddlewarePipe(),
    ) {
    }

    public function dispatch(Request $swooleRequest, Response $swooleResponse): void
    {
        $psrRequest = $this->parseRequest($swooleRequest);
        $psrResponse = $this->middlewarePipe->handle($psrRequest);
        $this->emitResponse($swooleResponse, $psrResponse);
    }

    protected function parseRequest(Request $request): ServerRequestInterface
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

    protected function emitResponse(Response $swooleResponse, ResponseInterface $psrResponse): void
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
