<?php

declare(strict_types=1);

use S3\Tunnel\Support\Watch;

require 'vendor/autoload.php';

$watch = new Watch(dirForWatch: ['src', 'templates']);
$watch->start(static fn() => require 'server.php');
