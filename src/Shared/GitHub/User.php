<?php

declare(strict_types=1);

namespace S3\Tunnel\Shared\GitHub;

final readonly class User
{
    public function __construct(
        public string $avatar,
        public string $name,
        public string $token,
    ) {
    }
}
