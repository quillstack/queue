<?php

declare(strict_types=1);

namespace Quillstack\Queue\Exceptions;

/**
 * Publishing to a topic nobody subscribes to.
 *
 * The alternative is for a misspelled topic to do nothing at all, quietly and successfully,
 * which is the kind of failure that is found weeks later by somebody asking why the emails
 * stopped. A topic exists because something subscribed to it.
 */
class UnknownTopicException extends QueueException
{
}
