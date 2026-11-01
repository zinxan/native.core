<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use Closure;
use JsonException;
use ZXN\NativeWatch\Core\Transport\SendResult;

final class Outbox
{
    /** @var resource|null */
    private mixed $lock = null;

    public function __construct(
        private readonly string $path,
        private readonly int $maxEvents = 100,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T|null
     */
    public function synchronized(Closure $operation): mixed
    {
        if ($this->lock !== null) {
            return $operation();
        }

        if (! is_dir($this->path) && ! @mkdir($this->path, 0700, true) && ! is_dir($this->path)) {
            return null;
        }

        $lock = @fopen($this->path.'/.consent.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return null;
        }

        $this->lock = $lock;

        try {
            return $operation();
        } finally {
            $this->lock = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Store the event on disk until it is sent.
     *
     * Returns false when the outbox cannot be written.
     *
     * @param  array<string, mixed>  $event
     */
    public function push(array $event): bool
    {
        if (! is_dir($this->path) && ! @mkdir($this->path, 0755, true) && ! is_dir($this->path)) {
            return false;
        }

        try {
            $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return false;
        }

        $file = $this->path.'/'.sprintf('%.6F', microtime(true)).'-'.bin2hex(random_bytes(4)).'.json';

        if (@file_put_contents($file.'.tmp', $json) === false || ! @rename($file.'.tmp', $file)) {
            return false;
        }

        $this->trim();

        return true;
    }

    /**
     * Send stored events, oldest first, and stop at the first network failure.
     *
     * Returns the number of events that left the outbox.
     *
     * @param  Closure(array<string, mixed>): SendResult  $send
     */
    public function flush(Closure $send, int $limit = 10): int
    {
        return $this->flushReport($send, $limit)->removed;
    }

    /** @param Closure(array<string, mixed>): SendResult $send */
    public function flushReport(Closure $send, int $limit = 10): FlushResult
    {
        $accepted = $rejected = $unreadable = $failed = $removed = 0;

        foreach (array_slice($this->files(), 0, $limit) as $file) {
            $event = $this->read($file);

            if ($event === null) {
                $unreadable++;
            } else {
                $result = $send($event);

                if ($result === SendResult::Failed) {
                    $failed++;
                    break;
                }

                $result === SendResult::Sent ? $accepted++ : $rejected++;
            }

            if (@unlink($file)) {
                $removed++;
            }
        }

        return new FlushResult($accepted, $rejected, $unreadable, $failed, $this->count(), $removed);
    }

    /**
     * Get the number of events that wait to be sent.
     */
    public function count(): int
    {
        return count($this->files());
    }

    public function clear(): bool
    {
        $cleared = true;

        foreach ([...$this->files(), ...(glob($this->path.'/*.tmp') ?: [])] as $file) {
            if (is_file($file) && ! @unlink($file)) {
                $cleared = false;
            }
        }

        return $cleared;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->path.'/*.json') ?: [];

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $file): ?array
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        try {
            $event = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($event) ? $event : null;
    }

    /**
     * Drop the oldest events, so a crash loop cannot fill the disk.
     */
    private function trim(): void
    {
        $files = $this->files();

        foreach (array_slice($files, 0, max(0, count($files) - $this->maxEvents)) as $file) {
            @unlink($file);
        }
    }
}
