<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Tcp\Session;

use S3\Tunnel\Shared\Tcp\Session\EncryptedSession;

final class EncryptedSessionContext
{
    /** @var EncryptedSession[] */
    private static array $context = [];

    public static function set(int $sessionId, EncryptedSession $encryptedSession): void
    {
        self::$context[$sessionId] = $encryptedSession;
    }

    public static function get(int $sessionId): ?EncryptedSession
    {
        return self::$context[$sessionId] ?? null;
    }

    public static function delete(int $sessionId): void
    {
        unset(self::$context[$sessionId]);
    }
}
