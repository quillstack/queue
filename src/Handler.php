<?php

declare(strict_types=1);

namespace Quillstack\Queue;

/**
 * What is done with a message once it comes off the queue.
 *
 * A handler is built by the container, so it asks for what it needs through its constructor.
 * The message carries only what it is about, which is what lets it be written down and read
 * back somewhere else entirely.
 */
interface Handler
{
    public function handle(object $message): void;
}
