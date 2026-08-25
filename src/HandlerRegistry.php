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
     * What is registered for the queue the message arrived on wins over what is registered
     * for the message everywhere, so a subscriber can do its own thing without stopping
     * anything else from having a default.
     *
     * @return class-string<Handler>
     */
    public function handlerFor(object $message, ?string $queue = null): string
    {
        $searched = $queue === null ? [] : [$this->onQueue[$queue] ?? []];
        $searched[] = $this->handlers;

        foreach ($searched as $handlers) {
            foreach ($handlers as $messageClass => $handlerClass) {
                if ($message instanceof $messageClass) {
                    return $handlerClass;
                }
            }
        }

        throw new NoHandlerException(
            'Nothing handles ' . $message::class . ($queue === null ? '' : " on the {$queue} queue")
        );
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
