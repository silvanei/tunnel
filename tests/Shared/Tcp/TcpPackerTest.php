<?php

declare(strict_types=1);

namespace Test\S3\Tunnel\Shared\Tcp;

use PHPUnit\Framework\TestCase;
use S3\Tunnel\Shared\Tcp\TcpPacker;

final class TcpPackerTest extends TestCase
{
    public function testPackShouldAddExpectedLenghtHeader(): void
    {
        $message = 'Hello, World!';
        $packedMessage = TcpPacker::pack($message);

        $expectedLength = pack('N', strlen($message));
        $this->assertEquals($expectedLength . $message, $packedMessage);
    }

    public function testUnpackShouldRemoveLenghtHeader(): void
    {
        $message = 'Hello, World!';
        $packedMessage = TcpPacker::pack($message);

        $unpackedMessage = TcpPacker::unpack($packedMessage);
        $this->assertEquals($message, $unpackedMessage);
    }

    public function testPackAndUnpackShouldPreserveMessage(): void
    {
        $message = 'Hello, World!';
        $packedMessage = TcpPacker::pack($message);
        $unpackedMessage = TcpPacker::unpack($packedMessage);

        $this->assertEquals($message, $unpackedMessage);
    }
}
