<?php

declare(strict_types=1);

namespace Test\S3\Tunnel\Tcp;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use S3\Tunnel\Shared\GitHub\GitHubService;
use S3\Tunnel\Tcp\Session\EncryptedSessionContext;
use S3\Tunnel\Tcp\TcpPacker;
use S3\Tunnel\Tcp\TcpServer;
use Swoole\Http\Server as HttpServer;

class TcpServerTest extends TestCase
{
    public function testReceive_ShouldCreateEncryptedSessionContext(): void
    {
        $gitHubService = $this->createMock(GitHubService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('debug')
            ->with('Receive public key');

        $httpServer = $this->createMock(HttpServer::class);
        $httpServer
            ->expects($this->once())
            ->method('send');

        $tcpServer = new TcpServer($logger, $gitHubService);
        $tcpServer->receive(server: $httpServer, fd: 1, reactorId: 1, data: TcpPacker::pack('fake-public-key'));

        $this->assertIsObject(EncryptedSessionContext::get(1));
    }
}
