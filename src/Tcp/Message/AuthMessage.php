<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp\Message;

final readonly class AuthMessage
{
    public function __construct(
        public string $user = '',
        public string $pass = '',
    ) {
    }
}
