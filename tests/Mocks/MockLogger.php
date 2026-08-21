<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

use Psr\Log\AbstractLogger;
use Stringable;

class MockLogger extends AbstractLogger
{
    /**
     * @var array<int, array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $entries = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
