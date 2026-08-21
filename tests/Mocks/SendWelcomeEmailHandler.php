<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

use Quillstack\Queue\Handler;

class SendWelcomeEmailHandler implements Handler
{
    public function __construct(private readonly Mailbox $mailbox)
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

        $this->mailbox->sent[] = $message->email;
    }
}
