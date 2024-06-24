<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp\Message;

final readonly class ResponseMessage
{
    public function __construct(
        public string $requestId,
        public int $status,
        /** @var array<string, array<string, string>> */
        public array $header,
        public string $body,
        public string $reason,
    ) {
    }
}
