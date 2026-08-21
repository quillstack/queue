<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

use Quillstack\LocalStorage\LocalStorage;
use RuntimeException;

/**
 * Reads which fail, the way they do when a file is taken away between being listed and
 * being read.
 */
class ThrowingStorage extends LocalStorage
{
    /**
     * {@inheritDoc}
     */
    public function get(string $path): mixed
    {
        throw new RuntimeException("File taken away: {$path}");
    }
}
