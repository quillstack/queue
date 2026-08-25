<?php

declare(strict_types=1);

namespace Quillstack\Queue;

use Quillstack\Queue\Exceptions\NoHandlerException;

/**
 * Says which handler answers which message.
 */
class HandlerRegistry
{
    /**
     * @var array<class-string, class-string<Handler>>
     */
    private array $handlers = [];

    /**
     * Handlers which answer a message only on one queue.
     *
     * A topic hands the same message to several queues, and the point of doing that is that
     * each of them does something different with it. One handler per message class cannot
     * express that.
     *
     * @var array<string, array<class-string, class-string<Handler>>>
     */
    private array $onQueue = [];

    /**
     * @param class-string $messageClass
     * @param class-string<Handler> $handlerClass
     */
    public function handle(string $messageClass, string $handlerClass): self
    {
        $this->handlers[$messageClass] = $handlerClass;

        return $this;
    }

    /**
     * Says which handler answers which message, but only on one queue.
     *
     * @param class-string $messageClass
     * @param class-string<Handler> $handlerClass
     */
    public function handleOn(string $queue, string $messageClass, string $handlerClass): self
    {
        $this->onQueue[$queue][$messageClass] = $handlerClass;

        return $this;
    }

    /**
     * The handler for a message, looked up by its class and then by anything it extends or
     * implements, so one handler can answer a whole family of messages.
     *
     * @return class-string<Handler>
     */
    public function handlerFor(object $message): string
    {
        return $this->lookUp($message, $this->handlers, $message::class);
    }

    /**
     * The handler for a message which arrived on a particular queue.
     *
     * What is registered for that queue wins over what is registered for the message
     * everywhere, so a subscriber to a topic can do its own thing without stopping anything
     * else from having a default.
     *
     * This is a second method rather than an argument on the first because `handlerFor` is
     * overridable, and adding an argument to it would be a fatal error in anything which had
     * overridden it — for a package this many others depend on, that is not worth an
     * argument's worth of tidiness.
     *
     * @return class-string<Handler>
     */
    public function handlerForQueue(object $message, string $queue): string
    {
        $handlers = ($this->onQueue[$queue] ?? []) + $this->handlers;

        return $this->lookUp($message, $handlers, $message::class . " on the {$queue} queue");
    }

    /**
     * Looked up by class and then by anything it extends or implements, so one handler can
     * answer a whole family of messages.
     *
     * @param array<class-string, class-string<Handler>> $handlers
     *
     * @return class-string<Handler>
     */
    private function lookUp(object $message, array $handlers, string $describe): string
    {
        foreach ($handlers as $messageClass => $handlerClass) {
            if ($message instanceof $messageClass) {
                return $handlerClass;
            }
        }

        throw new NoHandlerException('Nothing handles ' . $describe);
    }

    /**
     * @return array<class-string, class-string<Handler>>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    /**
     * @return array<class-string, class-string<Handler>>
     */
    public function allOn(string $queue): array
    {
        return $this->onQueue[$queue] ?? [];
    }
}
