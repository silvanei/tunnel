<?php

declare(strict_types=1);

use S3\Tunnel\Shared\Logger\Logger;
use S3\Tunnel\Support\Watch;

require 'vendor/autoload.php';

$logger = new Logger('watch-client');
$watch = new Watch($logger, dirForWatch: ['src'], sleep: 1);
$watch->start(static fn() => require 'client.php');
