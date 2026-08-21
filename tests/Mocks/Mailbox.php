<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

/**
 * Somewhere a handler can leave a mark, so a test can see it ran.
 */
final class Mailbox
{
    /**
     * @var string[]
     */
    public array $sent = [];
}
