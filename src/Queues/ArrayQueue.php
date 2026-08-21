<?php

declare(strict_types=1);

namespace Quillstack\Queue\Queues;

use DateInterval;
use Psr\Clock\ClockInterface;
use Quillstack\Clock\SystemClock;
use Quillstack\Queue\Delay;
use Quillstack\Queue\Envelope;
use Quillstack\Queue\Queue;

/**
 * Keeps messages for as long as the process runs, which is what a test wants and what a
 * single request needs when whatever it queued is handled before the response goes out.
 */
class ArrayQueue implements Queue
{
    /**
     * @var array<string, Envelope[]>
     */
    private array $queues = [];

    /**
     * @var Envelope[]
     */
    private array $failed = [];

    private int $pushed = 0;

    private ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
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

        $this->queues[$queue][] = $envelope;

        return $envelope;
    }

    /**
     * {@inheritDoc}
     */
    public function pop(string $queue = self::DEFAULT): ?Envelope
    {
        $now = $this->now();

        foreach ($this->queues[$queue] ?? [] as $index => $envelope) {
            if (!$envelope->isAvailableAt($now)) {
                continue;
            }

            unset($this->queues[$queue][$index]);

            return $envelope;
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope
    {
        $released = $envelope->retryAt(Delay::until($delay, $this->now()));
        $this->queues[$released->queue][] = $released;

        return $released;
    }

    /**
     * {@inheritDoc}
     */
    public function fail(Envelope $envelope, string $reason): void
    {
        $this->failed[] = $envelope;
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $queue = self::DEFAULT): int
    {
        return count($this->queues[$queue] ?? []);
    }

    /**
     * {@inheritDoc}
     */
    public function failed(): array
    {
        return $this->failed;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function nextId(): string
    {
        return 'message-' . ++$this->pushed;
    }
}
