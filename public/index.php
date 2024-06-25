<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use S3\Tunnel\Http\HttpServer;
use S3\Tunnel\Http\TcpDispatchController;
use S3\Tunnel\Tcp\TcpServer;
use Swoole\Constant;
use Swoole\Http\Server;

chdir(dirname(__DIR__));
require 'vendor/autoload.php';
(function () {
    $logger = new Logger('http-server');
    $logger->useLoggingLoopDetection(detectCycles: false);
    $logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

    $httpServer = new HttpServer(new TcpDispatchController());
    $http = new Server('0.0.0.0', 9501);
    $http->set([
        Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_DEBUG,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
        Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
    ]);
    $http->on(Constant::EVENT_REQUEST, $httpServer->request(...));

    $logger = new Logger('tcp-server');
    $logger->useLoggingLoopDetection(detectCycles: false);
    $logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

    $tcpServer = new TcpServer($logger);
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

    $http->start();
})();
