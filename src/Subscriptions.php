<?php

declare(strict_types=1);

namespace Quillstack\Queue;

use Quillstack\Queue\Exceptions\UnknownTopicException;

/**
 * Who is listening to what.
 *
 * A subscriber is a queue: subscribing `orders` to `orders.email` means a message published
 * to `orders` is put on the `orders.email` queue, where the ordinary worker picks it up with
 * the ordinary retries, delays and dead letters. There is nothing new to run.
 */
class Subscriptions
{
    /**
     * @var array<string, list<string>>
     */
    private array $subscriptions = [];

    public function subscribe(string $topic, string $queue): self
    {
        if (!in_array($queue, $this->subscriptions[$topic] ?? [], true)) {
            $this->subscriptions[$topic][] = $queue;
        }

        return $this;
    }

    /**
     * The queues a message published to this topic goes on, in the order they subscribed.
     *
     * @return list<string>
     */
    public function queuesFor(string $topic): array
    {
        if (!isset($this->subscriptions[$topic])) {
            throw new UnknownTopicException("Nothing subscribes to the topic: {$topic}");
        }

        return $this->subscriptions[$topic];
    }

    public function has(string $topic): bool
    {
        return isset($this->subscriptions[$topic]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->subscriptions;
    }
}
