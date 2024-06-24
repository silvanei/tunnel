<?php

declare(strict_types=1);

namespace S3\Tunnel\Tcp;

final readonly class RandomSubdomainMessage
{
    public string $value;

    public function __construct()
    {
        $length = 10;
        $string = '';
        while (($len = strlen($string)) < $length) {
            $size = $length - $len;
            $bytesSize = ceil($size / 3) * 3;
            $bytes = random_bytes(max(1, (int)$bytesSize));
            $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
        }
        $this->value = 'd4kpzt3v9b'; //strtolower($string);
    }
}
