<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use Laminas\Stratigility\MiddlewarePipe;
use S3\Tunnel\Client\Http\Action\HomeAction;
use S3\Tunnel\Client\Http\HttpServer;
use S3\Tunnel\Client\Tcp\TcpClient;
use S3\Tunnel\Shared\Http\Middleware\DispatcherHandlerMiddleware;
use S3\Tunnel\Shared\Logger\Logger;
use Swoole\Constant;
use Swoole\Coroutine\Channel;
use Swoole\Http\Server;

use function FastRoute\simpleDispatcher;

chdir(dirname(__DIR__));
require 'vendor/autoload.php';
(function () {
    $logger = new Logger('http-server-client');
    $eventChannel = new Channel(100);
    $tcpClient = new TcpClient($logger, $eventChannel);
    $dispatcher = simpleDispatcher(function (RouteCollector $router) {
        $router->addRoute('GET', '/', new HomeAction());
    });
    $httpMiddlewarePipe = new MiddlewarePipe();
    $httpMiddlewarePipe->pipe(new DispatcherHandlerMiddleware($dispatcher));
    $httpServer = new HttpServer($httpMiddlewarePipe, $tcpClient, $logger, $eventChannel);

    $http = new Server('0.0.0.0', 9505);
    $http->set([
        Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
    ]);
    $http->on(Constant::EVENT_START, $httpServer->start(...));
    $http->on(Constant::EVENT_REQUEST, $httpServer->request(...));
    $http->on(Constant::EVENT_CLOSE, $httpServer->close(...));
    $http->start();
})();
