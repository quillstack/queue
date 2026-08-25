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

    /**
     * Handed out and not yet spoken for, by reservation.
     *
     * @var array<string, array{envelope: Envelope, until: int}>
     */
    private array $reserved = [];

    private int $pushed = 0;

    private ClockInterface $clock;

    /**
     * @param int $visibility how long a worker has to say what became of a message before the
     *                        queue decides nobody is coming back for it
     */
    public function __construct(?ClockInterface $clock = null, private readonly int $visibility = self::VISIBILITY)
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
        $this->returnAbandoned();

        $now = $this->now();

        foreach ($this->queues[$queue] ?? [] as $index => $envelope) {
            if (!$envelope->isAvailableAt($now)) {
                continue;
            }

            unset($this->queues[$queue][$index]);

            $reserved = $envelope->reservedAs($this->nextId());
            $this->reserved[$reserved->reservation] = [
                'envelope' => $reserved,
                'until' => $now + $this->visibility,
            ];

            return $reserved;
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function ack(Envelope $envelope): bool
    {
        if (!isset($this->reserved[$envelope->reservation])) {
            return false;
        }

        unset($this->reserved[$envelope->reservation]);

        return true;
    }

    /**
     * Anything nobody spoke for goes back where it was.
     */
    private function returnAbandoned(): void
    {
        $now = $this->now();

        foreach ($this->reserved as $reservation => $held) {
            if ($held['until'] > $now) {
                continue;
            }

            unset($this->reserved[$reservation]);
            $this->queues[$held['envelope']->queue][] = $held['envelope'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope
    {
        unset($this->reserved[$envelope->reservation]);

        $released = $envelope->retryAt(Delay::until($delay, $this->now()));
        $this->queues[$released->queue][] = $released;

        return $released;
    }

    /**
     * {@inheritDoc}
     */
    public function fail(Envelope $envelope, string $reason): void
    {
        unset($this->reserved[$envelope->reservation]);

        $this->failed[] = $envelope;
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $queue = self::DEFAULT): int
    {
        $reserved = 0;

        foreach ($this->reserved as $held) {
            if ($held['envelope']->queue === $queue) {
                ++$reserved;
            }
        }

        return count($this->queues[$queue] ?? []) + $reserved;
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
