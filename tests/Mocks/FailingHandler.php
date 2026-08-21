<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

use Quillstack\Queue\Handler;
use RuntimeException;

class FailingHandler implements Handler
{
    public int $tried = 0;

    /**
     * {@inheritDoc}
     */
    public function handle(object $message): void
    {
        ++$this->tried;

        throw new RuntimeException('the card was declined');
    }
}
