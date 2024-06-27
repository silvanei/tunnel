<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Uri;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use S3\Tunnel\Tcp\Message\AuthMessage;
use S3\Tunnel\Tcp\Message\GoodByMessage;
use S3\Tunnel\Tcp\Message\RequestMessage;
use S3\Tunnel\Tcp\Message\ResponseMessage;
use S3\Tunnel\Tcp\RandomSubdomainMessage;
use S3\Tunnel\Tcp\Session\EncryptedSession;
use S3\Tunnel\Tcp\Session\SimpleCryptBox;
use S3\Tunnel\Tcp\TcpPacker;
use Swoole\Constant;
use Swoole\Coroutine\Client;

use function Swoole\Coroutine\run;

require 'vendor/autoload.php';

run(static function () {
    $logger = new Logger('tcp-client');
    $logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

    [$client, $encryptedSession] = connect($logger);

    while (true) {
        $received = $client->recv(2);
        if ($received === '' && $client->errMsg !== '') {
            $client->close();
            [$client, $encryptedSession] = connect($logger);
            continue;
        }

        if (is_string($received) && strlen($received) > 0) {
            $message = TcpPacker::unpack($received);
            $message = $encryptedSession->decrypt($message);
            $logger->debug('Receive', (array)$message);
            match ($message::class) {
                GoodByMessage::class => goodBye($logger, $client, $message),
                RandomSubdomainMessage::class => $logger->debug("http://{$message->value}.tunnel.localhost:9500"),
                RequestMessage::class => dispatch($client, $encryptedSession, $message),
                default => $client->close(),
            };
        }
    }
});

function dispatch(Client $client, EncryptedSession $encryptedSession, RequestMessage $message): void
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
    $response = $guzzleClient->sendRequest($psrRequest);
    $response = $response->withoutHeader('Transfer-Encoding');

    $response = new ResponseMessage(
        $message->requestId,
        status: $response->getStatusCode(),
        header: $response->getHeaders(),
        body: $response->getBody()->getContents(),
        reason: $response->getReasonPhrase(),
    );

    $client->send(TcpPacker::pack($encryptedSession->encrypt($response)));
}

function connect(Logger $logger): array
{
    $client = new Client(SWOOLE_TCP);
    $client->set([
        Constant::OPTION_OPEN_LENGTH_CHECK => true,
        Constant::OPTION_PACKAGE_LENGTH_TYPE => 'N',
        Constant::OPTION_PACKAGE_LENGTH_OFFSET => 0,
        Constant::OPTION_PACKAGE_BODY_OFFSET => 4,
        Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
        Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_DEBUG,
    ]);
    while (! $client->connect('tunnel-server', 9502)) {
        $logger->debug('Try connect!!!');
        $logger->debug($client->errMsg);
        sleep(2);
    }

    $criptoBox = new SimpleCryptBox();
    $client->send(TcpPacker::pack($criptoBox->boxPublicKey));

    $serverPublicKey = TcpPacker::unpack($client->recv());
    $logger->debug('Receive public key');

    $encryptedSession = new EncryptedSession($criptoBox, $serverPublicKey);
    $authMessage = new AuthMessage(accessToken: getenv('GITHUB_ACCESS_TOKEN'));
    $authMessage = $encryptedSession->encrypt($authMessage);
    $client->send(TcpPacker::pack($authMessage));
    return [$client, $encryptedSession];
}

function goodBye(Logger $logger, Client $client, GoodByMessage $message): void
{
    $logger->debug($message->body);
    $client->close();
}
