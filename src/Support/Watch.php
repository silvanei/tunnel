<?php

declare(strict_types=1);

namespace S3\Tunnel\Support;

use Psr\Log\LoggerInterface;
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

    /** @param string[] $dirForWatch */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $dirForWatch = [],
        private readonly int $sleep = 0,
    ) {
        $this->inotify = inotify_init();
    }

    /** @param callable(): void $callback */
    public function start(callable $callback): void
    {
        $pid = $this->startProcess($callback);
        $this->watching = $this->addWatch();
        while ($events = inotify_read($this->inotify)) {
            $namedEvents = array_map(fn(array $event) => $event['name'], $events);
            $namedEvents = array_unique($namedEvents);
            $namedEvents = array_filter($namedEvents, fn(string $event) => $event !== '' && ! str_ends_with($event, '~'));
            foreach ($namedEvents as $event) {
                $this->logger->debug("Inotify: $event");
                Process::kill($pid, SIGINT);
                $pid = $this->startProcess($callback);
            }
        }
    }

    /** @param callable(): void $callback */
    private function startProcess(callable $callback): int
    {
        $this->removeWatch();
        sleep($this->sleep);
        $pid = (new Process($callback))->start();
        $this->logger->debug("Start with PID: $pid");
        $this->watching = $this->addWatch();
        return $pid;
    }

    /** @return void */
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
