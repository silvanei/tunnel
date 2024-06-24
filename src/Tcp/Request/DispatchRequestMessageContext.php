<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp\Request;

final class DispatchRequestMessageContext
{
    /** @var DispatchRequestMessage[] */
    private static array $context = [];

    public static function set(string $subdomain, DispatchRequestMessage $dispatchRequestMessage): void
    {
        self::$context[$subdomain] = $dispatchRequestMessage;
    }

    public static function get(string $subdomain): ?DispatchRequestMessage
    {
        return self::$context[$subdomain] ?? null;
    }

    public static function delete(string $subdomain): void
    {
        unset(self::$context[$subdomain]);
    }
}
