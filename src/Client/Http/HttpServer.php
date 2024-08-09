<?php

declare(strict_types=1);

namespace S3\Tunnel\Client\Http;

use Laminas\Stratigility\MiddlewarePipeInterface;
use Psr\Log\LoggerInterface;
use S3\Tunnel\Client\Tcp\TcpClient;
use S3\Tunnel\Shared\Http\BaseHttpServer;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

final class HttpServer extends BaseHttpServer
{
    /** @var int[] $streamConnection */
    private array $streamConnection;
    private Channel $randomSubDomainChannel;

    public function __construct(
        MiddlewarePipeInterface $httpMiddlewarePipe,
        private readonly TcpClient $tcpClient,
        private readonly LoggerInterface $logger,
        private readonly Channel $eventChannel,
    ) {
        parent::__construct($httpMiddlewarePipe);

        $this->streamConnection = [];
        $this->randomSubDomainChannel = new Channel();
    }

    public function request(Request $swooleRequest, Response $swooleResponse): void
    {
        if ($swooleRequest->server['request_uri'] == '/stream') {
            $swooleResponse->header('Content-Type', 'text/event-stream');
            $swooleResponse->header('Cache-Control', 'no-cache');
            $swooleResponse->header('Connection', 'keep-alive');
            $swooleResponse->header('X-Accel-Buffering', 'no');
            $swooleResponse->header('Content-Encoding', '');
            $swooleResponse->header('Content-Length', '');
            $swooleResponse->end();
            $this->streamConnection[$swooleResponse->fd] = $swooleResponse->fd;
            $this->logger->info("Create a new stream: $swooleResponse->fd");
            return;
        }

        if ($swooleRequest->server['request_uri'] == '/random-subdomain') {
            $randomSubDomain = $this->randomSubDomainChannel->pop();
            $swooleResponse->status(200);
            $swooleResponse->header('Content-Type', 'text/plain');
            $swooleResponse->end($randomSubDomain);
            $this->randomSubDomainChannel->push($randomSubDomain);
        }

        $this->dispatch($swooleRequest, $swooleResponse);
    }

    public function close(Server $server, int $fd, int $reactorId): void
    {
        $this->logger->info("Close connection $fd");
        unset($this->streamConnection[$fd]);
    }

    public function start(Server $server): void
    {
        go($this->tcpClient->start(...));
        go(function () use ($server) {
            while ($event = $this->eventChannel->pop()) {
                /** @var array{event: string, requestId: string, uri: string} $event */
                if ($event['event'] === 'random-subdomain') {
                    if ($this->randomSubDomainChannel->isFull()) {
                        $this->randomSubDomainChannel->pop();
                    }
                    $this->randomSubDomainChannel->push($event['uri']);
                }
                foreach ($this->streamConnection as $fd) {
                    $data = "id: {$event['requestId']}\n";
                    $data .= "event: {$event['event']}\n";
                    $data .= 'data: ' . json_encode($event) . "\n\n";
                    $server->send($fd, $data);
                }
            }
        });
    }
}
