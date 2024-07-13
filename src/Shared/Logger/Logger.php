<?php

declare(strict_types=1);

namespace S3\Tunnel\Shared\Logger;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

final class Logger extends AbstractLogger
{
    private LoggerInterface $monolog;

    public function __construct(string $name)
    {
        $level = Level::fromName(getenv('LOG_LEVEL') ?: Level::Info->name);

        $this->monolog = new Monolog($name);
        $this->monolog->useLoggingLoopDetection(detectCycles: false);
        $this->monolog->pushHandler(new StreamHandler('php://stdout', $level));
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->monolog->log($level, $message, $context);
    }
}
