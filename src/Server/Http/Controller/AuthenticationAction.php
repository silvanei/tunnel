<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Server\Http\Response\ViewModel;

final readonly class AuthenticationAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $clientId = getenv('GITHUB_TOKEN') ?: '';
        return new ViewModel('authentication', ['client_id' => $clientId]);
    }
}
