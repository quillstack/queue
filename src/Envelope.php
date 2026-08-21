<?php

declare(strict_types=1);

namespace Quillstack\Queue;

/**
 * A message on its way through a queue, with what the queue needs to know about it: which
 * queue it is on, how many times it has been tried, and when it may be tried next.
 */
final class Envelope
{
    public function __construct(
        public readonly string $id,
        public readonly object $message,
        public readonly string $queue = Queue::DEFAULT,
        public readonly int $attempts = 0,
        public readonly int $availableAt = 0
    ) {
        //
    }

    /**
     * The same message, counted as tried once more and held back until the given moment.
     */
    public function retryAt(int $availableAt): self
    {
        return new self($this->id, $this->message, $this->queue, $this->attempts + 1, $availableAt);
    }

    /**
     * Whether the message may be handled now.
     */
    public function isAvailableAt(int $moment): bool
    {
        return $this->availableAt <= $moment;
    }
}
