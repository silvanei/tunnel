<?php

declare(strict_types=1);

namespace S3\Tunnel\Server\Tcp;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class ProcessManager
{
    public static function spawn(callable $process): int
    {
        return Coroutine::create(static function () use ($process) {
            $mailbox = new Channel();
            Coroutine::defer(static fn() => $mailbox->close());

            Coroutine::getContext()['mailbox'] = $mailbox;
            $process($mailbox);
        });
    }

    public static function send(int $cid, mixed $message): void
    {
        Coroutine::create(static function () use ($cid, $message) {
            /** @var ?Channel $context */
            $context = (Coroutine::getContext($cid)['mailbox'] ?? null);
            $context?->push($message);
        });
    }

    public static function kill(int $cid): void
    {
        Coroutine::cancel($cid);
    }
}
