<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp;

use S3\Tunnel\Tcp\Message\ResponseMessage;
use Swoole\Coroutine\Channel;

final class ResponseMessageChannel
{
    /** @var Channel[] */
    private static array $context = [];

    public static function create(string $requestId): Channel
    {
        self::$context[$requestId] = new Channel();
        return self::$context[$requestId];
    }

    public static function send(string $requestId, ResponseMessage $message): void
    {
        (self::$context[$requestId] ?? null)?->push($message);
    }

    public static function delete(string $requestId): void
    {
        (self::$context[$requestId] ?? null)?->close();
        unset(self::$context[$requestId]);
    }
}
