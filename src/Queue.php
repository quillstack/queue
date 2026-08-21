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
     * Puts a message on the queue, optionally held back for a while.
     */
    public function push(object $message, string $queue = self::DEFAULT, null|int|DateInterval $delay = null): Envelope;

    /**
     * Takes the next message which may be handled now, or nothing when there is none.
     */
    public function pop(string $queue = self::DEFAULT): ?Envelope;

    /**
     * Puts a message back, to be tried again after the given wait.
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope;

    /**
     * Says the message will not be tried again.
     */
    public function fail(Envelope $envelope, string $reason): void;

    /**
     * How many messages are waiting.
     */
    public function size(string $queue = self::DEFAULT): int;

    /**
     * The messages which will not be tried again.
     *
     * @return Envelope[]
     */
    public function failed(): array;
}
