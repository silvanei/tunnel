<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp\Session;

final readonly class EncriptedSession
{
    public function __construct(private SimpleCryptBox $criptoBox, private string $publicKey)
    {
    }

    public function encrypt(object $message): string
    {
        $serializedMessage = serialize($message);
        return $this->criptoBox->encrypt($serializedMessage, $this->publicKey);
    }

    public function decrypt(string $ciphertext): object
    {
        $serializedMessage = $this->criptoBox->decrypt($ciphertext, $this->publicKey);
        return unserialize($serializedMessage);
    }
}
