<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp;

use Psr\Log\LoggerInterface;
use S3\Tunnel\Shared\GitHub\GitHubService;
use S3\Tunnel\Tcp\Message\AuthMessage;
use S3\Tunnel\Tcp\Message\GoodByMessage;
use S3\Tunnel\Tcp\Message\ResponseMessage;
use S3\Tunnel\Tcp\Request\DispatchRequestMessage;
use S3\Tunnel\Tcp\Request\DispatchRequestMessageContext;
use S3\Tunnel\Tcp\Session\EncryptedSession;
use S3\Tunnel\Tcp\Session\EncryptedSessionContext;
use S3\Tunnel\Tcp\Session\SimpleCryptBox;
use Swoole\Coroutine\Channel;
use Swoole\Http\Server as HttpServer;

final class TcpServer
{
    /** @var array<int, callable[]> */
    private array $onConnectionClose;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly GitHubService $gitHubService,
    ) {
    }

    public function receive(HttpServer $server, int $fd, int $reactorId, string $data): void
    {
        $encryptedSession = EncryptedSessionContext::get($fd);
        if (! $encryptedSession) {
            $this->logger->debug('Receive public key');

            $cryptoBox = new SimpleCryptBox();
            $publicKey = TcpPacker::unpack($data);
            EncryptedSessionContext::set($fd, new EncryptedSession($cryptoBox, $publicKey));
            $this->defferConnectionClose($fd, EncryptedSessionContext::delete(...));
            $server->send($fd, TcpPacker::pack($cryptoBox->boxPublicKey));
            return;
        }

        $message = TcpPacker::unpack($data);
        $message = $encryptedSession->decrypt($message);
        match ($message::class) {
            AuthMessage::class => $this->handleAuthMessage($server, $fd, $message),
            ResponseMessage::class => $this->handleResponseMessage($message),
            default => $server->close($fd),
        };
    }

    public function close(HttpServer $server, int $fd, int $reactorId): void
    {
        foreach ($this->onConnectionClose[$fd] as $deffer) {
            $deffer($fd);
        }
        unset($this->onConnectionClose[$fd]);

        $this->logger->debug("Session $fd disconnected");
    }

    private function handleAuthMessage(HttpServer $server, int $fd, AuthMessage $message): void
    {
        $this->logger->debug('Receive auth message', (array)$message);
        $encryptedSession = EncryptedSessionContext::get($fd);
        if (! $encryptedSession) {
            $this->logger->critical('Session not encrypted');
            return;
        }

        if ($this->gitHubService->validateToken($message->accessToken)) {
            $tcpSenderCoroutineId = ProcessManager::spawn(static function (Channel $mailbox) use ($server, $fd, $encryptedSession) {
                while ($message = $mailbox->pop()) {
                    $server->send($fd, TcpPacker::pack($encryptedSession->encrypt($message)));
                }
            });
            $randomSubdomain = new RandomSubdomainMessage();
            DispatchRequestMessageContext::set($randomSubdomain->value, new DispatchRequestMessage($tcpSenderCoroutineId, $this->logger));

            $this->defferConnectionClose($fd, fn() => DispatchRequestMessageContext::delete($randomSubdomain->value));
            $this->defferConnectionClose($fd, fn() => ProcessManager::kill($tcpSenderCoroutineId));

            $server->send($fd, TcpPacker::pack($encryptedSession->encrypt($randomSubdomain)));
            $this->logger->debug('Client authenticated');
            return;
        }

        $goodByMessage = $encryptedSession->encrypt(new GoodByMessage('Bye'));
        $server->send($fd, TcpPacker::pack($goodByMessage));
        $server->close($fd);
    }

    private function handleResponseMessage(ResponseMessage $message): void
    {
        ResponseMessageChannel::send($message->requestId, $message);
    }

    private function defferConnectionClose(int $fd, callable $defer): void
    {
        $this->onConnectionClose[$fd][] = $defer;
    }
}
