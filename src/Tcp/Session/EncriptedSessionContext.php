<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp\Session;

final class EncriptedSessionContext
{
    /** @var EncriptedSession[] */
    private static array $context = [];

    public static function set(int $sessionId, EncriptedSession $encriptedSession): void
    {
        self::$context[$sessionId] = $encriptedSession;
    }

    public static function get(int $sessionId): ?EncriptedSession
    {
        return self::$context[$sessionId] ?? null;
    }

    public static function delete(int $sessionId): void
    {
        unset(self::$context[$sessionId]);
    }
}
