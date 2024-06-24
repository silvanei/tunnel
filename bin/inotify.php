<?php

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Swoole\Process;

require 'vendor/autoload.php';

final class Inotify
{
    private const MASK = IN_MODIFY | IN_CREATE | IN_MOVE | IN_DELETE;

    /** @var false|resource */
    private $inotify;
    private array $watching = [];
    private Logger $logger;

    public function __construct(private readonly array $dirForWatch = [])
    {
        $this->inotify = inotify_init();
        $this->logger = new Logger('inotify');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
    }

    public function start(): void
    {
        $pid = $this->startProcess();
        $this->watching = $this->addWatch();
        while ($events = inotify_read($this->inotify)) {
            $namedEvents = array_filter($events, fn(array $event) => $event['name'] !== '');
            foreach ($namedEvents as $event) {
                $this->logger->debug("Inotify: {$event['name']}");
                Process::kill($pid, SIGINT);
                sleep(1);
                $pid = $this->startProcess();
                $this->removeWatch();
                $this->watching = $this->addWatch();
            }
        }
    }

    private function startProcess(): int
    {
        $pid = (new Process(static fn () => require 'public/index.php'))->start();
        $this->logger->debug("Start with PID: $pid");
        return $pid;
    }

    public function __destruct()
    {
        $this->removeWatch();
        fclose($this->inotify);
        $this->logger->debug('Finish');
    }

    private function addWatch(): array
    {
        $directories = [];
        foreach ($this->dirForWatch as $d) {
            $directory = new RecursiveDirectoryIterator($d);
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                fn (SplFileInfo $fileInfo, $key, $iterator)=> $iterator->hasChildren() || ($fileInfo->isDir() && $fileInfo->getFilename() === '.')
            );
            $iterator = new RecursiveIteratorIterator($filter);
            $directories[] = $iterator;
        }

        $watching = [];
        /** @var RecursiveDirectoryIterator<SplFileInfo>[] $directories */
        foreach ($directories as $files) {
            foreach ($files as $file) {
                $watching[] = inotify_add_watch($this->inotify, $file->getPath(), self::MASK);
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

(new Inotify(dirForWatch: ['public', 'src']))->start();