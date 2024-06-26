<?php

declare(strict_types=1);

namespace S3\Tunnel\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Http\Response\ViewModel;

final readonly class AuthenticationAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new ViewModel('authentication');
    }
}
