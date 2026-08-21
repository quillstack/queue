<?php

declare(strict_types=1);

namespace Quillstack\Queue;

use DateInterval;
use DateTimeImmutable;

/**
 * How long a message is held back, given as seconds or as a DateInterval.
 */
final class Delay
{
    public static function until(null|int|DateInterval $delay, int $now): int
    {
        if ($delay === null) {
            return $now;
        }

        if ($delay instanceof DateInterval) {
            $reference = new DateTimeImmutable('@0');

            return $now + ($reference->add($delay)->getTimestamp() - $reference->getTimestamp());
        }

        return $now + $delay;
    }
}
