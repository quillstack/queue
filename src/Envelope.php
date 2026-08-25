<?php

declare(strict_types=1);

namespace Quillstack\Queue;

/**
 * A message on its way through a queue, with what the queue needs to know about it: which
 * queue it is on, how many times it has been tried, when it may be tried next, and — once a
 * worker has been handed it — which attempt at handling it this is.
 */
final class Envelope
{
    /**
     * @param string $reservation which attempt at handling this is, empty until one is made
     */
    public function __construct(
        public readonly string $id,
        public readonly object $message,
        public readonly string $queue = Queue::DEFAULT,
        public readonly int $attempts = 0,
        public readonly int $availableAt = 0,
        public readonly string $reservation = ''
    ) {
        //
    }

    /**
     * The same message, handed to a worker.
     *
     * The reservation is what `ack()` names, so a worker which took too long and had the
     * message given to somebody else cannot then say it handled it: its reservation is not the
     * one the queue is holding, and the acknowledgement is refused rather than throwing away a
     * message another worker is in the middle of.
     */
    public function reservedAs(string $reservation): self
    {
        return new self(
            $this->id,
            $this->message,
            $this->queue,
            $this->attempts + 1,
            $this->availableAt,
            $reservation
        );
    }

    /**
     * The same message, waiting again until the given moment.
     *
     * The count is not touched here: an attempt is made when a worker is handed the message,
     * not when one gives it back. A message which kills the process handling it is never given
     * back at all, and counting only the polite failures would let it be handed out for ever.
     */
    public function retryAt(int $availableAt): self
    {
        return new self($this->id, $this->message, $this->queue, $this->attempts, $availableAt);
    }

    /**
     * Whether the message may be handled now.
     */
    public function isAvailableAt(int $moment): bool
    {
        return $this->availableAt <= $moment;
    }
}
