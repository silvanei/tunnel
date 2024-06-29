<?php

declare(strict_types=1);

namespace Test\S3\Tunnel\Tcp;

use PHPUnit\Framework\TestCase;
use S3\Tunnel\Tcp\ProcessManager;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class ProcessManagerTest extends TestCase
{
    public function testSpawnShouldCreateCoroutineProcess(): void
    {
        $coroutineId = ProcessManager::spawn(fn (Channel $mailbox) => $mailbox->pop());
        Coroutine::defer(static fn () => Coroutine::cancel($coroutineId));

        $this->assertTrue(Coroutine::exists($coroutineId));
    }

    public function testSendShouldSendMessageToProcess(): void
    {
        $coroutineId = ProcessManager::spawn(
            fn (Channel $mailbox) => $this->assertSame('some message', $mailbox->pop())
        );

        ProcessManager::send($coroutineId, 'some message');
    }

    public function testKillShouldKillProcess(): void
    {
        $coroutineId = ProcessManager::spawn(fn (Channel $mailbox) => $mailbox->pop());

        $this->assertTrue(Coroutine::exists($coroutineId));

        ProcessManager::kill($coroutineId);

        $this->assertFalse(Coroutine::exists($coroutineId));
    }
}
