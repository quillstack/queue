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

    /**
     * @param int $visibility how long a worker has to say what became of a message
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $directory,
        ?ClockInterface $clock = null,
        private readonly int $visibility = self::VISIBILITY
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
     * The file is renamed out of the way before the message is handed over, so two workers
     * reading the same directory cannot both be given it — and unlike deleting it, the message
     * is still there if the worker never comes back.
     */
    public function pop(string $queue = self::DEFAULT): ?Envelope
    {
        $this->returnAbandoned($queue);

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

            $reserved = $envelope->reservedAs($this->nextId());
            $held = $this->reservedPath($queue, $reserved->reservation, $now + $this->visibility);

            // Whoever's rename succeeds is the one holding it.
            if (@rename($path, $held)) {
                $this->storage->save($held, serialize($reserved));

                return $reserved;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function ack(Envelope $envelope): bool
    {
        $held = $this->findReserved($envelope);

        if ($held === null) {
            return false;
        }

        return @unlink($held);
    }

    /**
     * Anything nobody spoke for goes back to being an ordinary waiting message.
     */
    private function returnAbandoned(string $queue): void
    {
        $now = $this->now();
        $paths = glob($this->directoryFor($queue) . '/*.reserved');

        foreach ($paths === false ? [] : $paths as $path) {
            $parts = explode('-', basename($path, '.reserved'));
            $until = (int) $parts[0];

            if ($until > $now) {
                continue;
            }

            $envelope = $this->read($path);

            if ($envelope === null) {
                @unlink($path);

                continue;
            }

            $this->write($envelope, $queue);
            @unlink($path);
        }
    }

    private function reservedPath(string $queue, string $reservation, int $until): string
    {
        return sprintf('%s/%011d-%s.reserved', $this->directoryFor($queue), $until, $reservation);
    }

    private function findReserved(Envelope $envelope): ?string
    {
        if ($envelope->reservation === '') {
            return null;
        }

        $paths = glob($this->directoryFor($envelope->queue) . '/*-' . $envelope->reservation . '.reserved');

        return $paths === false || $paths === [] ? null : $paths[0];
    }

    /**
     * {@inheritDoc}
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope
    {
        $held = $this->findReserved($envelope);

        if ($held !== null) {
            @unlink($held);
        }

        $released = $envelope->retryAt(Delay::until($delay, $this->now()));
        $this->write($released, $released->queue);

        return $released;
    }

    /**
     * {@inheritDoc}
     */
    public function fail(Envelope $envelope, string $reason): void
    {
        $held = $this->findReserved($envelope);

        if ($held !== null) {
            @unlink($held);
        }

        $this->write($envelope, self::FAILED);
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $queue = self::DEFAULT): int
    {
        $held = glob($this->directoryFor($queue) . '/*.reserved');

        return count($this->paths($queue)) + ($held === false ? 0 : count($held));
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
