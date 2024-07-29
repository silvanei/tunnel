<?php

declare(strict_types=1);

use S3\Tunnel\Server\Http\Controller\TcpDispatchAction;
use S3\Tunnel\Server\Http\HttpServer;
use S3\Tunnel\Server\Tcp\TcpServer;
use S3\Tunnel\Shared\GitHub\GitHubService;
use S3\Tunnel\Shared\Logger\Logger;
use Swoole\Constant;
use Swoole\Http\Server;

chdir(dirname(__DIR__));
require 'vendor/autoload.php';
(function () {
    $githubService = new GitHubService();

    $httpLogger = new Logger('http-server');
    $httpServer = new HttpServer(new TcpDispatchAction(), $githubService);
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
