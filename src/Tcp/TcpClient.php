<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp;

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use S3\Tunnel\Tcp\Message\AuthMessage;
use S3\Tunnel\Tcp\Message\GoodByMessage;
use S3\Tunnel\Tcp\Message\RequestMessage;
use S3\Tunnel\Tcp\Message\ResponseMessage;
use S3\Tunnel\Tcp\Session\EncryptedSession;
use S3\Tunnel\Tcp\Session\SimpleCryptBox;
use Swoole\Constant;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client;

final class TcpClient
{
    private Client $client;
    private EncryptedSession $encryptedSession;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Channel $eventChannel,
    ) {
    }

    public function start(): void
    {
        $this->connect();
        while (true) {
            $received = $this->client->recv(2);
            if ($received === '' && $this->client->errMsg !== '') {
                $this->client->close();
                $this->connect();
                continue;
            }

            if (is_string($received) && strlen($received) > 0) {
                $message = TcpPacker::unpack($received);
                $message = $this->encryptedSession->decrypt($message);
                $this->logger->debug('Receive', (array)$message);
                match ($message::class) {
                    GoodByMessage::class => $this->goodBye($message),
                    RandomSubdomainMessage::class => $this->logger->debug("http://{$message->value}.tunnel.localhost:9500"),
                    RequestMessage::class => $this->dispatch($message),
                    default => $this->client->close(),
                };
            }
        }
    }

    private function dispatch(RequestMessage $message): void
    {
        $targetHost = 'echo-server';
        $uri = (new Uri($message->uri))
            ->withScheme('http')
            ->withHost($targetHost)
            ->withQuery($message->query);
        $psrRequest = (new \GuzzleHttp\Psr7\Request(
            method: $message->method,
            uri: $uri,
            headers: [...$message->header, ...['host' => $targetHost]],
            body: $message->body,
            version: $message->version,
        ));
        $guzzleClient = new \GuzzleHttp\Client();
        $psrResponse = $guzzleClient->sendRequest($psrRequest);
        $psrResponse = $psrResponse->withoutHeader('Transfer-Encoding');

        $responseMessage = new ResponseMessage(
            $message->requestId,
            status: $psrResponse->getStatusCode(),
            header: $psrResponse->getHeaders(),
            body: $psrResponse->getBody()->getContents(),
            reason: $psrResponse->getReasonPhrase(),
        );

        $this->client->send(TcpPacker::pack($this->encryptedSession->encrypt($responseMessage)));

        $this->eventChannel->push([
            'requestId' => $message->requestId,
            'date' => date('d/m/Y H:i:s'),
            'uri' => "{$psrRequest->getMethod()} {$psrRequest->getUri()}",
            'transaction' => $this->formatRaw($psrRequest, $psrResponse),
        ]);
    }

    private function connect(): void
    {
        $this->client = new Client(SWOOLE_TCP);
        $this->client->set([
            Constant::OPTION_OPEN_LENGTH_CHECK => true,
            Constant::OPTION_PACKAGE_LENGTH_TYPE => 'N',
            Constant::OPTION_PACKAGE_LENGTH_OFFSET => 0,
            Constant::OPTION_PACKAGE_BODY_OFFSET => 4,
            Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
            Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_DEBUG,
        ]);
        while (! $this->client->connect('tunnel-server', 9502)) {
            $this->logger->debug('Try connect!!!');
            $this->logger->debug($this->client->errMsg);
            sleep(2);
        }

        $cryptoBox = new SimpleCryptBox();
        $this->client->send(TcpPacker::pack($cryptoBox->boxPublicKey));

        $serverPublicKey = TcpPacker::unpack($this->client->recv());
        $this->logger->debug('Receive public key');

        $this->encryptedSession = new EncryptedSession($cryptoBox, $serverPublicKey);
        $authMessage = new AuthMessage(accessToken: getenv('GITHUB_ACCESS_TOKEN'));
        $authMessage = $this->encryptedSession->encrypt($authMessage);
        $this->client->send(TcpPacker::pack($authMessage));
    }

    private function goodBye(GoodByMessage $message): void
    {
        $this->logger->debug($message->body);
        $this->client->close();
    }

    private function formatRaw(RequestInterface $request, ResponseInterface $response): string
    {
        $request->getBody()->rewind();
        $response->getBody()->rewind();

        return <<<RAW
        Request:
        
        {$request->getMethod()} {$request->getRequestTarget()} HTTP/1.1
        {$this->formatHeader($request->getHeaders())}
        {$request->getBody()->getContents()}

        Response:
                    
        HTTP/1.1 {$response->getStatusCode()} {$response->getReasonPhrase()}
        {$this->formatHeader($response->getHeaders())}
        {$response->getBody()->getContents()}
        RAW;
    }

    /** @param string[][] $headers */
    private function formatHeader(array $headers): string
    {
        $header = '';
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $header .= sprintf("%s: %s\n", $name, $value);
            }
        }
        return $header;
    }
}
