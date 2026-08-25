<?php

declare(strict_types=1);

namespace Quillstack\Queue\Queues;

use DateInterval;
use Psr\Clock\ClockInterface;
use Quillstack\Clock\SystemClock;
use Quillstack\Queue\Delay;
use Quillstack\Queue\Envelope;
use Quillstack\Queue\Queue;
use Redis;

/**
 * Keeps messages in Redis, which every instance can reach and none of them has to write to
 * disk.
 *
 * `DatabaseQueue` needs no infrastructure that an API does not already have, and that is the
 * reason to start there. This is what to reach for when the queue is busy enough that the
 * messages are worth keeping out of the database — Redis holds them in memory and hands one
 * over with a single round trip.
 */
class RedisQueue implements Queue
{
    /**
     * @var string
     */
    public const PREFIX = 'quillstack:queue';

    /**
     * @var string
     */
    private const FAILED = 'failed';

    /**
     * How many held-back messages are looked at in one go. A queue where more than this many
     * come due at the same moment simply takes the rest on the next `pop`.
     *
     * @var int
     */
    private const DUE_AT_ONCE = 100;

    private ClockInterface $clock;

    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = self::PREFIX,
        ?ClockInterface $clock = null
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * {@inheritDoc}
     */
    public function push(object $message, string $queue = self::DEFAULT, null|int|DateInterval $delay = null): Envelope
    {
        $now = $this->now();

        $envelope = new Envelope(
            $this->nextId(),
            $message,
            $queue,
            0,
            Delay::until($delay, $now)
        );

        $this->store($envelope, $now);

        return $envelope;
    }

    /**
     * {@inheritDoc}
     *
     * `RPOP` is the claim: Redis hands the message to one client and nobody else, so there is
     * nothing here to get wrong under concurrency. What is held back is a sorted set, and a
     * message becomes due by being removed from it — whoever's `ZREM` says it removed the
     * message is the one that puts it in the list, so it lands there once.
     */
    public function pop(string $queue = self::DEFAULT): ?Envelope
    {
        $this->releaseDue($queue);

        while (true) {
            $payload = $this->redis->rPop($this->key($queue));

            if (!is_string($payload)) {
                return null;
            }

            $envelope = $this->unpack($payload);

            if ($envelope !== null) {
                return $envelope;
            }

            // Unreadable, and already gone from the list. Try the next one.
        }
    }

    /**
     * {@inheritDoc}
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope
    {
        $now = $this->now();
        $released = $envelope->retryAt(Delay::until($delay, $now));

        $this->store($released, $now);

        return $released;
    }

    /**
     * {@inheritDoc}
     */
    public function fail(Envelope $envelope, string $reason): void
    {
        $this->redis->lPush($this->key(self::FAILED), serialize($envelope));
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $queue = self::DEFAULT): int
    {
        $waiting = $this->redis->lLen($this->key($queue));
        $held = $this->redis->zCard($this->delayedKey($queue));

        return (is_int($waiting) ? $waiting : 0) + (is_int($held) ? $held : 0);
    }

    /**
     * {@inheritDoc}
     */
    public function failed(): array
    {
        $payloads = $this->redis->lRange($this->key(self::FAILED), 0, -1);
        $failed = [];

        foreach (is_array($payloads) ? $payloads : [] as $payload) {
            $envelope = is_string($payload) ? $this->unpack($payload) : null;

            if ($envelope !== null) {
                $failed[] = $envelope;
            }
        }

        // Newest is pushed to the head, and the order they failed in reads better.
        return array_reverse($failed);
    }

    /**
     * Puts a message where it belongs: in the list when it may be handled, in the sorted set
     * when it may not yet.
     */
    private function store(Envelope $envelope, int $now): void
    {
        $payload = serialize($envelope);

        if ($envelope->isAvailableAt($now)) {
            $this->redis->lPush($this->key($envelope->queue), $payload);

            return;
        }

        $this->redis->zAdd($this->delayedKey($envelope->queue), $envelope->availableAt, $payload);
    }

    /**
     * Moves everything now due out of the sorted set and into the list.
     */
    private function releaseDue(string $queue): void
    {
        $delayed = $this->delayedKey($queue);

        $due = $this->redis->zRangeByScore($delayed, '-inf', (string) $this->now(), [
            'limit' => [0, self::DUE_AT_ONCE],
        ]);

        foreach (is_array($due) ? $due : [] as $payload) {
            if (!is_string($payload)) {
                continue;
            }

            // Whoever removes it owns it, which is what stops two workers both moving it.
            if ($this->redis->zRem($delayed, $payload) === 1) {
                $this->redis->lPush($this->key($queue), $payload);
            }
        }
    }

    private function unpack(string $payload): ?Envelope
    {
        $envelope = @unserialize($payload);

        return $envelope instanceof Envelope ? $envelope : null;
    }

    private function key(string $queue): string
    {
        return "{$this->prefix}:{$queue}";
    }

    private function delayedKey(string $queue): string
    {
        return "{$this->prefix}:{$queue}:delayed";
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
