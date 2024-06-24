<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp\Message;

final readonly class GoodByMessage
{
    public function __construct(
        public string $body = '',
    ) {
    }
}
