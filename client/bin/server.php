<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use S3\Tunnel\Client\Tcp\TcpClient;
use Swoole\Constant;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

chdir(dirname(__DIR__));
require '../vendor/autoload.php';
(function () {
    $logger = new Logger('http-server-client');
    $logger->useLoggingLoopDetection(detectCycles: false);
    $logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

    $eventChannel = new Channel(100);
    $randomSubDomainChannel = new Channel(1);
    $tcpClient = new TcpClient($logger, $eventChannel);
    $streamConnection = [];

    $http = new Server('0.0.0.0', 9505);
    $http->set([
        Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_DEBUG,
        Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
        Constant::OPTION_ENABLE_STATIC_HANDLER => true,
        Constant::OPTION_DOCUMENT_ROOT => dirname(__DIR__) . '/public',
        Constant::OPTION_HTTP_AUTOINDEX => true,
        Constant::OPTION_HTTP_INDEX_FILES => ['index.html'],
        Constant::OPTION_HTTP_COMPRESSION => true,
        Constant::OPTION_HTTP_COMPRESSION_LEVEL => 5,
    ]);
    $http->on(Constant::EVENT_CLOSE, static function (Server $server, int $fd, int $reactorId) use (&$streamConnection, $logger) {
        $logger->debug("Close connection $fd");
        unset($streamConnection[$fd]);
    });
    $http->on(Constant::EVENT_REQUEST, static function (Request $request, Response $response) use ($http, $eventChannel, $randomSubDomainChannel, $logger, &$streamConnection) {
        if ($request->server['request_uri'] == '/stream') {
            $response->header("Content-Type", "text/event-stream");
            $response->header("Cache-Control", "no-cache");
            $response->header("Connection", "keep-alive");
            $response->header("X-Accel-Buffering", "no");
            $response->header('Content-Encoding', '');
            $response->header("Content-Length", '');
            $response->end();
            $streamConnection[$response->fd] = $response->fd;
            $logger->debug("Create a new stream: $response->fd");
            return;
        }

        if ($request->server['request_uri'] == '/random-subdomain') {
            $randomSubDomain = $randomSubDomainChannel->pop();
            $response->status(200);
            $response->header('Content-Type', 'text/plain');
            $response->end($randomSubDomain);
            $randomSubDomainChannel->push($randomSubDomain);
        }

        $response->status(404);
        $response->end();
    });
    $http->on(Constant::EVENT_START, static function (Server $server) use (&$streamConnection, $tcpClient, $eventChannel, $randomSubDomainChannel) {
        go($tcpClient->start(...));
        go(function () use (&$streamConnection, $eventChannel, $randomSubDomainChannel, $server) {
            while ($event = $eventChannel->pop()) {
                if ($event['event'] === 'random-subdomain') {
                    if ($randomSubDomainChannel->isFull()) {
                        $randomSubDomainChannel->pop();
                    }
                    $randomSubDomainChannel->push($event['uri']);
                }
                foreach ($streamConnection as $fd) {
                    $data = "id: {$event['requestId']}\n";
                    $data .= "event: {$event['event']}\n";
                    $data .= 'data: ' . json_encode($event) . "\n\n";
                    $server->send($fd, $data);
                }
            }
        });
    });
    $http->start();
})();
