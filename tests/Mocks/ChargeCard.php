<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Mocks;

final class ChargeCard
{
    public function __construct(public readonly int $amount)
    {
        //
    }
}
