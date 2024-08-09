<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Laminas\Stratigility\MiddlewarePipe;
use Mezzio\Session\Cache\CacheSessionPersistence;
use Mezzio\Session\SessionMiddleware;
use S3\Tunnel\Server\Http\Controller\AuthenticationAction;
use S3\Tunnel\Server\Http\Controller\AuthorizationAction;
use S3\Tunnel\Server\Http\Controller\GoogleAction;
use S3\Tunnel\Server\Http\Controller\HomeAction;
use S3\Tunnel\Server\Http\Controller\TcpDispatchAction;
use S3\Tunnel\Server\Http\HttpServer;
use S3\Tunnel\Server\Http\Middleware\AuthorizationMiddleware;
use S3\Tunnel\Server\Tcp\TcpServer;
use S3\Tunnel\Shared\GitHub\GitHubService;
use S3\Tunnel\Shared\Http\Middleware\DispatcherHandlerMiddleware;
use S3\Tunnel\Shared\Logger\Logger;
use Swoole\Constant;
use Swoole\Http\Server;

use function FastRoute\simpleDispatcher;

chdir(dirname(__DIR__));
require 'vendor/autoload.php';
(function () {
    $httpLogger = new Logger('http-server');
    $githubService = new GitHubService();
    $dispatcher = simpleDispatcher(function (RouteCollector $router) use ($githubService) {
        $router->addRoute('GET', '/', new HomeAction());
        $router->addRoute('GET', '/google7b953163902ce6a3.html', new GoogleAction());
        $router->addRoute('GET', '/authentication', new AuthenticationAction());
        $router->addRoute('GET', '/github/authorization-callback', new AuthorizationAction($githubService));
    });
    $httpMiddlewarePipe = new MiddlewarePipe();
    $httpMiddlewarePipe->pipe(new SessionMiddleware(new CacheSessionPersistence(new CacheItemPoolDecorator(new Filesystem()), 'TUNNEL-SESSION-ID')));
    $httpMiddlewarePipe->pipe(new AuthorizationMiddleware($githubService));
    $httpMiddlewarePipe->pipe(new DispatcherHandlerMiddleware($dispatcher));

    $tcpMiddlewarePipe = new MiddlewarePipe();
    $tcpMiddlewarePipe->pipe(new RequestHandlerMiddleware(new TcpDispatchAction()));
    $httpServer = new HttpServer($httpMiddlewarePipe, $tcpMiddlewarePipe);

    $http = new Server('0.0.0.0', 9501);
    $http->set([
        Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_DEBUG,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
        Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
    ]);
    $http->on(Constant::EVENT_REQUEST, $httpServer->request(...));

    $tcpLogger = new Logger('tcp-server');
    $tcpServer = new TcpServer($tcpLogger, $githubService);
    $tcp = $http->listen('0.0.0.0', 9502, SWOOLE_TCP);
    $tcp->set([
        Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_DEBUG,
        Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
        Constant::OPTION_OPEN_LENGTH_CHECK => true,
        Constant::OPTION_PACKAGE_LENGTH_TYPE => 'N',
        Constant::OPTION_PACKAGE_LENGTH_OFFSET => 0,
        Constant::OPTION_PACKAGE_BODY_OFFSET => 4,
    ]);
    $tcp->on(Constant::EVENT_CLOSE, $tcpServer->close(...));
    $tcp->on(Constant::EVENT_RECEIVE, $tcpServer->receive(...));

    $httpLogger->info('http-server listen on 0.0.0.0:9501');
    $tcpLogger->info('tcp-server listen on 0.0.0.0:9502');
    $http->start();
})();
