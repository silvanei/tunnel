<?php

declare(strict_types=1);

namespace S3\Tunnel\Http;

use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Shared\GitHub\GitHubService;

final readonly class AuthorizationController implements RequestHandlerInterface
{
    public function __construct(private GithubService $githubService)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $code = $request->getQueryParams()['code'] ?? null;
        $error = $request->getQueryParams()['error'] ?? null;
        if (! $code || $error) {
            return new TextResponse(text: 'Internal server error', status: 500);
        }

        $accessToken = $this->githubService->accessToken($code);
        if (! $accessToken) {
            return new TextResponse(text: 'Internal server error', status: 500);
        }

        $cookie = "cr_github_access_token=$accessToken; Max-Age=2592000; Path=/; HttpOnly";
        return (new RedirectResponse('/'))
            ->withAddedHeader('Set-Cookie', $cookie);
    }
}
