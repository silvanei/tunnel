<?php

declare(strict_types=1);

namespace S3\Tunnel\Support;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Swoole\Process;

final class Watch
{
    private const int MASK = IN_MODIFY | IN_CREATE | IN_MOVE | IN_DELETE;

    /** @var resource */
    private $inotify;
    /** @var int[]  */
    private array $watching = [];
    private Logger $logger;

    /** @param string[] $dirForWatch */
    public function __construct(
        private readonly array $dirForWatch = [],
    ) {
        $this->inotify = inotify_init();
        $this->logger = new Logger('watch-server');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
    }

    public function start(callable $callback): void
    {
        $pid = $this->startProcess($callback);
        $this->watching = $this->addWatch();
        while ($events = inotify_read($this->inotify)) {
            $namedEvents = array_filter($events, fn(array $event) => $event['name'] !== '');
            foreach ($namedEvents as $event) {
                $this->logger->debug("Inotify: {$event['name']}");
                Process::kill($pid, SIGINT);
                sleep(1);
                $pid = $this->startProcess($callback);
                $this->removeWatch();
                $this->watching = $this->addWatch();
            }
        }
    }

    private function startProcess(callable $callback): int
    {
        $pid = (new Process($callback))->start();
        $this->logger->debug("Start with PID: $pid");
        return $pid;
    }

    public function __destruct()
    {
        $this->removeWatch();
        fclose($this->inotify);
        $this->logger->debug('Finish');
    }

    /** @return int[] */
    private function addWatch(): array
    {
        $directories = [];
        foreach ($this->dirForWatch as $d) {
            $directory = new RecursiveDirectoryIterator($d);
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                fn(SplFileInfo $fileInfo, $key, $iterator) => $iterator->hasChildren() || ($fileInfo->isDir() && $fileInfo->getFilename() === '.')
            );
            $iterator = new RecursiveIteratorIterator($filter);
            $directories[] = $iterator;
        }

        $watching = [];
        /** @var RecursiveDirectoryIterator<SplFileInfo>[] $directories */
        foreach ($directories as $files) {
            foreach ($files as $file) {
                $watching[] = (int)inotify_add_watch($this->inotify, $file->getPath(), self::MASK);
            }
        }
        return $watching;
    }

    private function removeWatch(): void
    {
        foreach ($this->watching as $watch) {
            @inotify_rm_watch($this->inotify, $watch);
        }
    }
}
