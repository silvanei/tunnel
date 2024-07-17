<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Http\Controller;

use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class GoogleAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new TextResponse(text: 'google-site-verification: google7b953163902ce6a3.html', status: 200);
    }
}
