<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Server\Http\Response\ViewModel;
use S3\Tunnel\Shared\GitHub\User;

final readonly class HomeAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new ViewModel('home', ['user' => $request->getAttribute(User::class)]);
    }
}
