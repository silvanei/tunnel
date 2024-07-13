<?php

declare(strict_types=1);

namespace S3\Tunnel\Shared\Tcp;

final class TcpPacker
{
    private const int HEAD_LENGTH = 4;

    public static function pack(string $message): string
    {
        return pack('N', strlen($message)) . $message;
    }

    public static function unpack(string $data): string
    {
        return substr($data, self::HEAD_LENGTH);
    }
}
