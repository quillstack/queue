<?php

declare(strict_types=1);

namespace Quillstack\Queue;

use DateInterval;

/**
 * Publishing one message to everything that subscribed.
 *
 * A queue hands a message to exactly one worker, which is what you want for work that must
 * happen once. A topic hands it to every subscriber, which is what you want when one thing
 * happening means several unrelated things should follow — an order placed is a receipt to
 * send, a figure to record and a warehouse to tell, and none of the three should be able to
 * stop the other two.
 *
 * Each subscriber gets a message of its own on a queue of its own, so a receipt that will not
 * send is retried and eventually set aside without the warehouse ever hearing about it.
 */
class Topic
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Subscriptions $subscriptions
    ) {
        //
    }

    /**
     * Puts the message on every subscriber's queue, and gives back what was put where.
     *
     * This is one push per subscriber rather than one atomic act. Where that matters — where
     * two subscribers must both hear or neither — `DatabaseQueue` is backed by a connection
     * which has transactions, and the publish can be wrapped in one.
     *
     * @return Envelope[]
     */
    public function publish(object $message, string $topic, null|int|DateInterval $delay = null): array
    {
        $published = [];

        foreach ($this->subscriptions->queuesFor($topic) as $queue) {
            $published[] = $this->queue->push($message, $queue, $delay);
        }

        return $published;
    }
}
