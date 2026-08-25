<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

use Quillstack\Queue\Handler;

/**
 * Answers the same message as SendWelcomeEmailHandler, and does something else with it. Two
 * subscribers doing the same thing would not be a fan-out worth having.
 */
class RecordWelcomeHandler implements Handler
{
    public function __construct(private readonly Ledger $ledger)
    {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function handle(object $message): void
    {
        if (!$message instanceof SendWelcomeEmail) {
            return;
        }

        $this->ledger->recorded[] = $message->email;
    }
}
