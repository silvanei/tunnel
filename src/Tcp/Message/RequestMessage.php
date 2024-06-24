<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp\Message;

final readonly class RequestMessage
{
    public function __construct(
        public string $requestId,
        public string $method,
        public string $uri,
        /** @var array<string, array<string, string>> */
        public array $header,
        public string $body,
        public string $version,
        public string $query,
        /** @var array<string, mixed> */
        public array $get,
    ) {
    }
}
