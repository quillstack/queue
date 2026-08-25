<?php

declare(strict_types=1);

namespace Quillstack\Queue;

use DateInterval;
use Quillstack\Queue\Exceptions\UnknownTopicException;

/**
 * Publishing one message to everything that subscribed.
 *
 * A queue hands a message to exactly one worker, which is what you want for work that must
 * happen once. A topic hands it to every subscriber, which is what you want when one thing
 * happening means several unrelated things should follow — an order placed is a receipt to
 * send, a figure to record and a warehouse to tell, and none of the three should be able to
 * stop the other two.
 *
 * How the fan-out happens is the implementation's business. `Topics\QueueTopic` does it by
 * putting a message on each subscriber's queue; a broker which fans out on its own is told
 * once and does the rest.
 */
interface Topic
{
    /**
     * Publishes the message to everything that subscribed to the topic.
     *
     * Nothing comes back. The point of a topic is that the publisher does not know who is
     * listening, and handing back a receipt for each subscriber would tell it exactly that —
     * which a broker doing its own fan-out could not do anyway. What was published is a
     * question for the queues, which can be asked with `size()`.
     *
     * @throws UnknownTopicException where nothing subscribes to the topic, because a
     *                               misspelled one doing nothing quietly is found weeks later
     */
    public function publish(object $message, string $topic, null|int|DateInterval $delay = null): void;
}
