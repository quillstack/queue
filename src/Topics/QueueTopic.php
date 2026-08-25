<?php

declare(strict_types=1);

namespace Quillstack\Queue\Topics;

use DateInterval;
use Quillstack\Queue\Queue;
use Quillstack\Queue\Subscriptions;
use Quillstack\Queue\Topic;

/**
 * A topic made out of a queue: one message per subscriber, on a queue of its own.
 *
 * That is what makes the subscribers independent of each other — a receipt which will not send
 * is retried and eventually set aside without the warehouse ever hearing about it.
 */
class QueueTopic implements Topic
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Subscriptions $subscriptions
    ) {
        //
    }

    /**
     * {@inheritDoc}
     *
     * This is one push per subscriber rather than one atomic act. Where that matters — where
     * two subscribers must both hear or neither — `DatabaseQueue` is backed by a connection
     * which has transactions, and the publish can be wrapped in one.
     */
    public function publish(object $message, string $topic, null|int|DateInterval $delay = null): void
    {
        foreach ($this->subscriptions->queuesFor($topic) as $queue) {
            $this->queue->push($message, $queue, $delay);
        }
    }
}
