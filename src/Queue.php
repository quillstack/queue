<?php

declare(strict_types=1);

namespace Quillstack\Queue;

use DateInterval;

/**
 * Somewhere to put a message so that something else can pick it up later.
 */
interface Queue
{
    /**
     * @var string
     */
    public const DEFAULT = 'default';

    /**
     * How long a worker has to say what became of a message, in seconds, where a driver is not
     * told otherwise.
     *
     * @var int
     */
    public const VISIBILITY = 60;

    /**
     * Puts a message on the queue, optionally held back for a while.
     */
    public function push(object $message, string $queue = self::DEFAULT, null|int|DateInterval $delay = null): Envelope;

    /**
     * Hands over the next message which may be handled now, or nothing when there is none.
     *
     * The message is reserved rather than removed: it is out of the way of other workers, and
     * it is still there. Say what became of it with `ack()`, `release()` or `fail()`, and if
     * nothing says, it comes back on its own once the reservation runs out.
     *
     * That is what makes a worker safe to kill. Before, a message handed over was gone, so a
     * process which died between being given a message and finishing it took the message with
     * it — and nothing anywhere said so.
     */
    public function pop(string $queue = self::DEFAULT): ?Envelope;

    /**
     * Says the message was handled, and the queue may forget it.
     *
     * Only the worker holding the current reservation can say so. One which took longer than
     * the reservation lasts has had the message given to somebody else, and its acknowledgement
     * is refused rather than throwing away work another worker is in the middle of.
     */
    public function ack(Envelope $envelope): bool;

    /**
     * Puts a message back, to be tried again after the given wait.
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope;

    /**
     * Says the message will not be tried again.
     */
    public function fail(Envelope $envelope, string $reason): void;

    /**
     * How many messages the queue is still holding: waiting, held back, or handed to a worker
     * which has not yet said what became of them.
     *
     * Reserved messages count because they are still the queue's business — one may come back.
     */
    public function size(string $queue = self::DEFAULT): int;

    /**
     * The messages which will not be tried again.
     *
     * @return Envelope[]
     */
    public function failed(): array;
}
