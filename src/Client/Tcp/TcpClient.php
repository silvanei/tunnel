<?php

declare(strict_types=1);

namespace S3\Tunnel\Client\Tcp;

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use S3\Tunnel\Shared\Tcp\Message\AuthMessage;
use S3\Tunnel\Shared\Tcp\Message\GoodByMessage;
use S3\Tunnel\Shared\Tcp\Message\RandomSubdomainMessage;
use S3\Tunnel\Shared\Tcp\Message\RequestMessage;
use S3\Tunnel\Shared\Tcp\Message\ResponseMessage;
use S3\Tunnel\Shared\Tcp\Session\EncryptedSession;
use S3\Tunnel\Shared\Tcp\Session\SimpleCryptBox;
use S3\Tunnel\Shared\Tcp\TcpPacker;
use Swoole\Constant;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client;

final class TcpClient
{
    private Client $client;
    private EncryptedSession $encryptedSession;
    private string $domain;
    private string $targetHost;
    private string $accessToken;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Channel $eventChannel,
    ) {
        $this->domain = getenv('SERVER_DOMAIN') ?: 'tunnel.localhost';
        $this->targetHost = getenv('TARGET_HOST') ?: 'echo-server';
        $this->accessToken = getenv('GITHUB_ACCESS_TOKEN') ?: '';
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
                $this->logger->debug(sprintf('Receive %s message', $message::class), (array)$message);
                match ($message::class) {
                    GoodByMessage::class => $this->goodBye($message),
                    RandomSubdomainMessage::class => $this->randomSubdomainMessage($message),
                    RequestMessage::class => $this->dispatch($message),
                    default => $this->client->close(),
                };
            }
        }
    }

    private function dispatch(RequestMessage $message): void
    {
        ['host' => $host, 'scheme' => $schema, 'port' => $port] = $this->parseUrl($this->targetHost);
        $uri = (new Uri($message->uri))
            ->withScheme($schema)
            ->withHost($host)
            ->withPort($port)
            ->withQuery($message->query);
        $psrRequest = (new \GuzzleHttp\Psr7\Request(
            method: $message->method,
            uri: $uri,
            headers: [...$message->header, ...['host' => $host]],
            body: $message->body,
            version: $message->version,
        ));
        $guzzleClient = new \GuzzleHttp\Client(['verify' => false, 'allow_redirects' => true]);
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
            'event' => 'transaction',
            'date' => date('d/m/Y H:i:s'),
            'uri' => "{$psrRequest->getMethod()} {$psrRequest->getUri()}",
            'transaction' => $this->formatRaw($psrRequest, $psrResponse),
        ]);
    }

    private function connect(): void
    {
        ['host' => $host] = $this->parseUrl($this->domain);

        $this->client = new Client(SWOOLE_TCP);
        $this->client->set([
            Constant::OPTION_OPEN_LENGTH_CHECK => true,
            Constant::OPTION_PACKAGE_LENGTH_TYPE => 'N',
            Constant::OPTION_PACKAGE_LENGTH_OFFSET => 0,
            Constant::OPTION_PACKAGE_BODY_OFFSET => 4,
            Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
            Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_DEBUG,
        ]);
        while (! $this->client->connect($host, 9502)) {
            $this->logger->info('Try connect!!!');
            $this->logger->error($this->client->errMsg);
            sleep(2);
        }
        $this->logger->info("Connected on $host:9502");

        $cryptoBox = new SimpleCryptBox();
        $this->client->send(TcpPacker::pack($cryptoBox->boxPublicKey));
        $this->logger->debug('Send public key to server');

        $serverPublicKey = TcpPacker::unpack($this->client->recv());
        $this->logger->debug('Receive public key from server');

        $this->encryptedSession = new EncryptedSession($cryptoBox, $serverPublicKey);
        $authMessage = new AuthMessage($this->accessToken);
        $encryptedAuthMessage = $this->encryptedSession->encrypt($authMessage);
        $this->client->send(TcpPacker::pack($encryptedAuthMessage));
        $this->logger->debug('Send auth message to server', (array)$authMessage);
    }

    private function randomSubdomainMessage(RandomSubdomainMessage $message): void
    {
        ['host' => $host, 'scheme' => $schema, 'port' => $port] = $this->parseUrl($this->domain);
        $port = match ($port) {
            80, 443 => '',
            default => ":$port",
        };

        $this->logger->info('http://127.0.0.1:9505/');
        $this->logger->info("$schema://$message->value.$host{$port}");

        $this->eventChannel->push([
            'requestId' => $message->value,
            'event' => 'random-subdomain',
            'date' => date('d/m/Y H:i:s'),
            'uri' => "$schema://$message->value.$host{$port}",
        ]);
    }

    private function goodBye(GoodByMessage $message): void
    {
        $this->logger->info('Receive good by message', (array)$message);
        $this->client->close();
    }

    /** @return array{host: string, scheme: string, port: int} */
    private function parseUrl(string $url): array
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $schema = (string)parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT);
        if (empty($port)) {
            $port = match ($schema) {
                'https' => 443,
                default => 80,
            };
        }

        return ['host' => $host, 'scheme' => $schema, 'port' => (int)$port];
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
