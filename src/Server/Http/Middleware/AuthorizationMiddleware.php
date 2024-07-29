<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Http\Middleware;

use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use S3\Tunnel\Shared\GitHub\GitHubService;
use S3\Tunnel\Shared\GitHub\User;

final readonly class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(private GitHubService $githubService)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $publicTarget = ['/authentication', '/github/authorization-callback', '/google7b953163902ce6a3.html'];
        if (in_array($request->getRequestTarget(), $publicTarget, true)) {
            return $handler->handle($request);
        }

        /** @var SessionInterface $session **/
        $session = $request->getAttribute(SessionInterface::class);
        /** @var ?string $githubToken */
        $githubToken = $session->get('cr_github_access_token');
        if (! $githubToken) {
            return (new RedirectResponse('/authentication'));
        }

        $user = $this->githubService->user($githubToken);
        if (! $user) {
            return (new RedirectResponse('/authentication'));
        }

        $request = $request->withAttribute(User::class, $user);

        return $handler->handle($request);
    }
}
