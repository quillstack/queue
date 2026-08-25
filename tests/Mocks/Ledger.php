<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

/**
 * A second place a handler can leave a mark, so a test can tell two subscribers apart.
 */
final class Ledger
{
    /**
     * @var string[]
     */
    public array $recorded = [];
}
