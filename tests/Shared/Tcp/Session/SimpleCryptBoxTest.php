<?php

declare(strict_types=1);

namespace Test\S3\Tunnel\Shared\Tcp\Session;

use Exception;
use PHPUnit\Framework\TestCase;
use S3\Tunnel\Shared\Tcp\Session\SimpleCryptBox;

final class SimpleCryptBoxTest extends TestCase
{
    private SimpleCryptBox $serverCryptBox;
    private SimpleCryptBox $clientCryptBox;

    protected function setUp(): void
    {
        $this->serverCryptBox = new SimpleCryptBox();
        $this->clientCryptBox = new SimpleCryptBox();
    }

    public function testEncryptDecrypt_ShouldEncryptAndDecryptWithTwoCryptoBoxInstance(): void
    {
        $plainText = 'Hello, Secure World!';
        $encrypted = $this->serverCryptBox->encrypt($plainText, $this->clientCryptBox->boxPublicKey);
        $decrypted = $this->clientCryptBox->decrypt($encrypted, $this->serverCryptBox->boxPublicKey);

        $this->assertEquals($plainText, $decrypted);
    }

    public function testDecrypt_ShouldTrowException_WhenDecryptWithWrongKey(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Malformed message or invalid MAC');

        $plainText = 'This should fail!';
        $encrypted = $this->serverCryptBox->encrypt($plainText, $this->clientCryptBox->boxPublicKey);

        $wrongCryptBox = new SimpleCryptBox();
        $wrongCryptBox->decrypt($encrypted, $this->serverCryptBox->boxPublicKey);
    }

    public function testEncrypt_ShouldEncryptWithCorrectNonceLength(): void
    {
        $plainText = 'Test Nonce Length';
        $encrypted = $this->serverCryptBox->encrypt($plainText, $this->clientCryptBox->boxPublicKey);

        $nonce = substr($encrypted, 0, SODIUM_CRYPTO_BOX_NONCEBYTES);

        $this->assertEquals(SODIUM_CRYPTO_BOX_NONCEBYTES, strlen($nonce));
    }
}
