<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Tcp\Request;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use S3\Tunnel\Server\Tcp\ProcessManager;
use S3\Tunnel\Server\Tcp\ResponseMessageChannel;
use S3\Tunnel\Shared\Tcp\Message\RequestMessage;
use S3\Tunnel\Shared\Tcp\Message\ResponseMessage;
use Swoole\Coroutine;

final readonly class DispatchRequestMessage
{
    public function __construct(private int $tcpSenderCoroutineId, private LoggerInterface $logger)
    {
    }

    public function dispatch(RequestMessage $message, float $timeout = 30): ResponseInterface
    {
        $this->logger->debug('IDA', (array)$message);

        $channel = ResponseMessageChannel::create($message->requestId);
        Coroutine::defer(static fn() => ResponseMessageChannel::delete($message->requestId));

        ProcessManager::send($this->tcpSenderCoroutineId, $message);

        /** @var ResponseMessage $response */
        $response = $channel->pop($timeout);
        $this->logger->debug('VOLTA', (array)$response);

        return match ($channel->errCode) {
            SWOOLE_CHANNEL_OK => new Response(
                body: (new StreamFactory())->createStream($response->body),
                status: $response->status,
                headers: $response->header,
            ),
            SWOOLE_CHANNEL_TIMEOUT,
            SWOOLE_CHANNEL_CLOSED,
            SWOOLE_CHANNEL_CANCELED => new TextResponse(text: 'Gateway Timeout', status: 504),
            default => new TextResponse(text: 'Internal Server Error', status: 500),
        };
    }
}
