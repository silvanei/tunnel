<?php

declare(strict_types=1);

namespace Test\S3\Tunnel\Tcp;

use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use S3\Tunnel\Tcp\TcpPacker;
use S3\Tunnel\Tcp\TcpServer;
use Swoole\Http\Server as HttpServer;
use S3\Tunnel\Shared\GitHub\GitHubService;
use S3\Tunnel\Tcp\Message\AuthMessage;
use S3\Tunnel\Tcp\Message\GoodByMessage;
use S3\Tunnel\Tcp\Message\ResponseMessage;
use S3\Tunnel\Tcp\RandomSubdomainMessage;
use S3\Tunnel\Tcp\Request\DispatchRequestMessageContext;
use S3\Tunnel\Tcp\ResponseMessageChannel;
use S3\Tunnel\Tcp\Session\EncryptedSession;
use S3\Tunnel\Tcp\Session\EncryptedSessionContext;
use S3\Tunnel\Tcp\Session\SimpleCryptBox;
use stdClass;
use Test\S3\Tunnel\TunnelTestCase;

class TcpServerTest extends TunnelTestCase
{
    private EncryptedSession $clientEncryptedSession;
    private EncryptedSession $serverEncryptedSession;
    private TcpServer $tcpServer;

    /** @var GitHubService&MockInterface */
    private GitHubService $gitHubService;
    /** @var LoggerInterface&MockInterface */
    private LoggerInterface $logger;
    /** @var HttpServer&MockInterface */
    private HttpServer $httpServer;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->gitHubService = Mockery::namedMock(GitHubService::class);
        $this->httpServer = Mockery::mock(HttpServer::class);

        $clientCryptoBox = new SimpleCryptBox();
        $serverCryptoBox = new SimpleCryptBox();
        $this->clientEncryptedSession = new EncryptedSession($clientCryptoBox, $serverCryptoBox->boxPublicKey);
        $this->serverEncryptedSession = new EncryptedSession($serverCryptoBox, $clientCryptoBox->boxPublicKey);
        $this->tcpServer = new TcpServer($this->logger, $this->gitHubService);
    }

    public function testReceive_ShouldCreateEncryptedSessionContext(): void
    {
        $message = new stdClass();
        $message->foo = 'Testing session encrypt and decrypt';
        $clientCryptoBox = new SimpleCryptBox();

        $this->logger->shouldReceive('debug')->once()->with('Receive public key');
        $clientEncryptedSession = null;
        $this->httpServer->shouldReceive('send')->once()->withArgs(function ($fd, $publicKey) use (&$clientEncryptedSession, $clientCryptoBox) {
            $clientEncryptedSession = new EncryptedSession($clientCryptoBox, TcpPacker::unpack($publicKey));
            return true;
        });

        $this->simulateTcpServerReceive(fd: $fd = 1, data: $clientCryptoBox->boxPublicKey);

        $this->assertNotNull($serverEncryptedSession = EncryptedSessionContext::get($fd));
        $this->assertEquals(
            $serverEncryptedSession->decrypt((string)$clientEncryptedSession?->encrypt($message)),
            $clientEncryptedSession?->decrypt($serverEncryptedSession->encrypt($message)),
        );

        $this->logger->shouldReceive('debug')->once()->with("Session $fd disconnected");
        $this->simulateTcpServerClose($fd);
        $this->assertNull(EncryptedSessionContext::get($fd));
    }

    public function testReceive_ShouldHandleAuthMessage(): void
    {
        EncryptedSessionContext::set($fd = 1, $this->serverEncryptedSession);
        $encryptedAuthMessage = $this->clientEncryptedSession->encrypt($authMessage = new AuthMessage(accessToken: 'fake-access-token'));

        $this->gitHubService->shouldReceive('validateToken')->once()->with('fake-access-token')->andReturnTrue();
        $this->logger->shouldReceive('debug')->once()->with('Receive auth message', ['accessToken' => 'fake-access-token']);
        $this->logger->shouldReceive('debug')->once()->with('Client authenticated');
        $expectedRandomSubdomain = null;
        $this->httpServer->shouldReceive('send')->once()->withArgs(function ($fd, $randomSubdomain) use (&$expectedRandomSubdomain) {
            /** @var RandomSubdomainMessage $expectedRandomSubdomain */
            $expectedRandomSubdomain = $this->clientEncryptedSession->decrypt(TcpPacker::unpack($randomSubdomain));
            return true;
        });

        $this->simulateTcpServerReceive(fd: $fd, data: $encryptedAuthMessage);

        $this->assertSame($this->serverEncryptedSession, EncryptedSessionContext::get($fd));
        $this->assertEquals($authMessage, $this->serverEncryptedSession->decrypt($encryptedAuthMessage));
        $this->assertNotNull(DispatchRequestMessageContext::get((string)$expectedRandomSubdomain?->value));

        $this->logger->shouldReceive('debug')->once()->with("Session $fd disconnected");
        $this->simulateTcpServerClose($fd);
        $this->assertNull(DispatchRequestMessageContext::get((string)$expectedRandomSubdomain?->value));
    }

    public function testReceive_ShouldHandleAuthMessage_WhenAccessTokenIsInvalid(): void
    {
        EncryptedSessionContext::set($fd = 1, $this->serverEncryptedSession);
        $authMessage = new AuthMessage(accessToken: 'fake-invalid-access-token');
        $encryptedAuthMessage = $this->clientEncryptedSession->encrypt($authMessage);

        $this->gitHubService->shouldReceive('validateToken')->once()->with('fake-invalid-access-token')->andReturnFalse();
        $this->logger->shouldReceive('debug')->once()->with('Receive auth message', ['accessToken' => 'fake-invalid-access-token']);
        $this->logger->shouldReceive('debug')->once()->with('Sending By message');
        $expectedGoodByMessage = null;
        $this->httpServer->shouldReceive('send')->once()->withArgs(function ($fd, $goodByMessage) use (&$expectedGoodByMessage) {
            $expectedGoodByMessage = $this->clientEncryptedSession->decrypt(TcpPacker::unpack($goodByMessage));
            return true;
        });
        $this->httpServer->shouldReceive('close')->once()->with(1);

        $this->simulateTcpServerReceive(fd: $fd, data: $encryptedAuthMessage);

        $this->assertEquals($expectedGoodByMessage, new GoodByMessage('Bye'));
        $this->assertNotNull(EncryptedSessionContext::get($fd));
    }

    public function testReceive_ShouldHandleResponseMessage(): void
    {
        EncryptedSessionContext::set($fd = 1, $this->serverEncryptedSession);
        $requestId = 'fake-request-id';
        $responseMessage = new ResponseMessage(requestId: $requestId, status: 200, header: [], body: 'body', reason: '');
        $encryptedResponseMessage = $this->clientEncryptedSession->encrypt($responseMessage);
        $channel = ResponseMessageChannel::create($requestId);

        $this->simulateTcpServerReceive(fd: $fd, data: $encryptedResponseMessage);

        $this->assertNotNull(EncryptedSessionContext::get($fd));
        $this->assertEquals($responseMessage, $channel->pop(1));
    }

    public function testReceive_ShouldCloseConnection_WhenMessageNotMapped(): void
    {
        EncryptedSessionContext::set($fd = 1, $this->serverEncryptedSession);
        $notMappedMessage = new stdClass();
        $encryptedResponseMessage = $this->clientEncryptedSession->encrypt($notMappedMessage);

        $this->httpServer->shouldReceive('close')->once()->with(1);

        $this->simulateTcpServerReceive(fd: $fd, data: $encryptedResponseMessage);
    }

    private function simulateTcpServerReceive(int $fd, string $data): void
    {
        $this->tcpServer->receive(server: $this->httpServer, fd: $fd, reactorId: 1, data: TcpPacker::pack($data));
    }

    private function simulateTcpServerClose(int $fd): void
    {
        $this->tcpServer->close(server: $this->httpServer, fd: $fd, reactorId: 1);
    }
}
