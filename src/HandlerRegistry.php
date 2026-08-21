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
     * @param class-string $messageClass
     * @param class-string<Handler> $handlerClass
     */
    public function handle(string $messageClass, string $handlerClass): self
    {
        $this->handlers[$messageClass] = $handlerClass;

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
        foreach ($this->handlers as $messageClass => $handlerClass) {
            if ($message instanceof $messageClass) {
                return $handlerClass;
            }
        }

        throw new NoHandlerException('Nothing handles ' . $message::class);
    }

    /**
     * @return array<class-string, class-string<Handler>>
     */
    public function all(): array
    {
        return $this->handlers;
    }
}
