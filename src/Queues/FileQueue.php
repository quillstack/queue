<?php

declare(strict_types=1);

namespace Quillstack\Queue\Queues;

use DateInterval;
use Psr\Clock\ClockInterface;
use Quillstack\Clock\SystemClock;
use Quillstack\Queue\Delay;
use Quillstack\Queue\Envelope;
use Quillstack\Queue\Exceptions\MessageNotStoredException;
use Quillstack\Queue\Queue;
use Quillstack\StorageInterface\StorageInterface;
use Throwable;

/**
 * Writes messages to a directory, one file each, so a worker in another process picks up
 * what a request put there.
 */
class FileQueue implements Queue
{
    /**
     * @var string
     */
    private const FAILED = 'failed';

    private ClockInterface $clock;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $directory,
        ?ClockInterface $clock = null
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * {@inheritDoc}
     */
    public function push(object $message, string $queue = self::DEFAULT, null|int|DateInterval $delay = null): Envelope
    {
        $envelope = new Envelope(
            $this->nextId(),
            $message,
            $queue,
            0,
            Delay::until($delay, $this->now())
        );

        $this->write($envelope, $queue);

        return $envelope;
    }

    /**
     * {@inheritDoc}
     *
     * The file is taken away before the message is handed over, so two workers reading the
     * same directory cannot both be given it.
     */
    public function pop(string $queue = self::DEFAULT): ?Envelope
    {
        $now = $this->now();

        foreach ($this->paths($queue) as $path) {
            $envelope = $this->read($path);

            if ($envelope === null) {
                $this->storage->delete($path);

                continue;
            }

            if (!$envelope->isAvailableAt($now)) {
                continue;
            }

            if ($this->claim($path)) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope
    {
        $released = $envelope->retryAt(Delay::until($delay, $this->now()));
        $this->write($released, $released->queue);

        return $released;
    }

    /**
     * {@inheritDoc}
     */
    public function fail(Envelope $envelope, string $reason): void
    {
        $this->write($envelope, self::FAILED);
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $queue = self::DEFAULT): int
    {
        return count($this->paths($queue));
    }

    /**
     * {@inheritDoc}
     */
    public function failed(): array
    {
        $failed = [];

        foreach ($this->paths(self::FAILED) as $path) {
            $envelope = $this->read($path);

            if ($envelope !== null) {
                $failed[] = $envelope;
            }
        }

        return $failed;
    }

    private function write(Envelope $envelope, string $queue): void
    {
        $directory = $this->directoryFor($queue);
        $this->makeDirectory($directory);

        // Named so that reading the directory in order reads the messages in order: when
        // they are due first, and then when they were written. The id alone is random, so
        // two messages due at the same second would come back in no order at all.
        $name = sprintf(
            '%011d-%016d-%s.message',
            $envelope->availableAt,
            (int) (microtime(true) * 1_000_000),
            $envelope->id
        );

        $this->storage->save($directory . '/' . $name, serialize($envelope));
    }

    private function makeDirectory(string $directory): void
    {
        try {
            if (is_dir($directory)) {
                return;
            }

            $made = mkdir($directory, 0o775, true) || is_dir($directory);
        } catch (Throwable $throwable) {
            throw new MessageNotStoredException(
                "Unable to create the queue directory: {$directory}",
                0,
                $throwable
            );
        }

        if (!$made) {
            throw new MessageNotStoredException("Unable to create the queue directory: {$directory}");
        }
    }

    private function read(string $path): ?Envelope
    {
        try {
            $contents = $this->storage->get($path);
        } catch (Throwable) {
            return null;
        }

        $envelope = is_string($contents) ? @unserialize($contents) : false;

        return $envelope instanceof Envelope ? $envelope : null;
    }

    /**
     * Takes the file away, and says whether this was the one which took it.
     */
    private function claim(string $path): bool
    {
        return @rename($path, $path . '.claimed') && @unlink($path . '.claimed');
    }

    /**
     * @return string[]
     */
    private function paths(string $queue): array
    {
        $paths = glob($this->directoryFor($queue) . '/*.message');

        if ($paths === false) {
            return [];
        }

        sort($paths);

        return $paths;
    }

    private function directoryFor(string $queue): string
    {
        return $this->directory . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $queue);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function nextId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
