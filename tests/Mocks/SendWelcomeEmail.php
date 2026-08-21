<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

/**
 * A message carries only what it is about, which is what lets it be written down and read
 * back somewhere else entirely.
 */
final class SendWelcomeEmail
{
    public function __construct(public readonly string $email)
    {
        //
    }
}
