<?php

declare(strict_types=1);

namespace Quillstack\Queue;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Quillstack\Queue\Exceptions\NoHandlerException;
use Throwable;

/**
 * Takes messages off a queue and gives them to whatever handles them.
 *
 * A message which fails is put back to be tried again, a few times, waiting a little longer
 * each time. Once it has been tried enough it is set aside rather than kept in the way of
 * everything behind it.
 */
class Worker
{
    public function __construct(
        private readonly Queue $queue,
        private readonly HandlerRegistry $handlers,
        private readonly ContainerInterface $container,
        private readonly int $tries = 3,
        private readonly int $backoff = 10
    ) {
        //
    }

    /**
     * Handles one message, and says whether there was one.
     */
    public function runOne(string $queue = Queue::DEFAULT): ?Envelope
    {
        $envelope = $this->queue->pop($queue);

        if ($envelope === null) {
            return null;
        }

        try {
            $this->handle($envelope);
            // Only now is the queue allowed to forget it.
            $this->queue->ack($envelope);
        } catch (Throwable $throwable) {
            $this->recover($envelope, $throwable);
        }

        return $envelope;
    }

    /**
     * Handles everything waiting, and says how many there were. A message put back to be
     * tried later is not picked up again here: this run ends when nothing is due.
     */
    public function runAll(string $queue = Queue::DEFAULT): int
    {
        $handled = 0;

        while ($this->runOne($queue) !== null) {
            ++$handled;
        }

        return $handled;
    }

    private function handle(Envelope $envelope): void
    {
        $handlerClass = $this->handlers->handlerForQueue($envelope->message, $envelope->queue);

        /** @var Handler $handler */
        $handler = $this->container->get($handlerClass);
        $handler->handle($envelope->message);
    }

    /**
     * A message nobody handles will not start being handled by waiting, so it is set aside
     * at once. Anything else is tried again until it has been tried enough.
     */
    private function recover(Envelope $envelope, Throwable $throwable): void
    {
        // The message was counted as attempted when the queue handed it over.
        $attempts = $envelope->attempts;

        if ($throwable instanceof NoHandlerException || $attempts >= $this->tries) {
            $this->queue->fail($envelope, $throwable->getMessage());
            $this->log($envelope, $throwable);

            return;
        }

        $this->queue->release($envelope, $this->backoff * $attempts);
    }

    private function log(Envelope $envelope, Throwable $throwable): void
    {
        if (!$this->container->has(LoggerInterface::class)) {
            return;
        }

        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);

        $logger->error('A queued message was set aside: ' . $throwable->getMessage(), [
            'message' => $envelope->message::class,
            'id' => $envelope->id,
            'attempts' => $envelope->attempts,
            'exception' => $throwable::class,
        ]);
    }
}
