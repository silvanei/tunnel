<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Http\Controller;

use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\TextResponse;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Shared\GitHub\GitHubService;

final readonly class AuthorizationAction implements RequestHandlerInterface
{
    public function __construct(private GitHubService $githubService)
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

        /** @var SessionInterface $session **/
        $session = $request->getAttribute(SessionInterface::class);
        $session->set('cr_github_access_token', $accessToken);
        return (new RedirectResponse('/'));
    }
}
