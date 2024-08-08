<?php

declare(strict_types=1);

namespace S3\Tunnel\Client\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Server\Http\Response\ViewModel;

final readonly class HomeAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new ViewModel('client');
    }
}
