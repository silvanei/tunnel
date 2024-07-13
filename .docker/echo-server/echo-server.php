<?php

declare(strict_types=1);

use S3\Tunnel\Shared\Logger\Logger;
use Swoole\Constant;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require 'vendor/autoload.php';
(function () {
    $logger = new Logger('echo-server');

    $http = new Server('0.0.0.0', 80);
    $http->set([
        Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_DEBUG,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
        Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
    ]);
    $http->on(Constant::EVENT_START, static fn() => $logger->debug('Start echo-server'));
    $http->on(Constant::EVENT_REQUEST, static function(Request $swooleRequest, Response $swooleResponse) use ($logger) {
        $logger->debug('Receive request');

        $swooleResponse->setStatusCode(200);
        $swooleResponse->setHeader('Content-Type', 'application/json');
        $swooleResponse->end(json_encode(
            [
                'method' => $swooleRequest->getMethod(),
                'path' => $swooleRequest->server['request_uri'],
                'headers' => $swooleRequest->header,
                'parsedQueryParams' => $swooleRequest->get,
                'rawContent' => $swooleRequest->rawContent(),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
    });

    $http->start();
})();
