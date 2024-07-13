<?php

declare(strict_types=1);

namespace S3\Tunnel\Shared\Tcp\Session;

use Exception;

final readonly class SimpleCryptBox
{
    public string $boxPublicKey;
    private string $boxSecretKey;

    public function __construct()
    {
        $boxKp = sodium_crypto_box_keypair();
        $this->boxSecretKey = sodium_crypto_box_secretkey($boxKp);
        $this->boxPublicKey = sodium_crypto_box_publickey($boxKp);
    }

    public function encrypt(string $plainText, string $publicKey): string
    {
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $this->boxSecretKey,
            $publicKey
        );
        $nonce = random_bytes(SODIUM_CRYPTO_BOX_NONCEBYTES);
        $ciphertext = sodium_crypto_box(
            $plainText,
            $nonce,
            $keypair
        );
        return $nonce . $ciphertext;
    }

    public function decrypt(string $ciphertext, string $publicKey): string
    {
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $this->boxSecretKey,
            $publicKey
        );

        $nonce = substr($ciphertext, 0, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $ciphertext = substr($ciphertext, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $plaintext = sodium_crypto_box_open($ciphertext, $nonce, $keypair);
        if ($plaintext === false) {
            throw new Exception('Malformed message or invalid MAC');
        }
        return $plaintext;
    }
}
