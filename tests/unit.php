<?php

declare(strict_types=1);

return [
    \Quillstack\Queue\Tests\Unit\TestArrayQueue::class,
    \Quillstack\Queue\Tests\Unit\TestFileQueue::class,
    \Quillstack\Queue\Tests\Unit\TestDatabaseQueue::class,
    \Quillstack\Queue\Tests\Unit\TestReservations::class,
    \Quillstack\Queue\Tests\Unit\TestWorker::class,
    \Quillstack\Queue\Tests\Unit\TestTopic::class,
    \Quillstack\Queue\Tests\Unit\TestRedisQueue::class,
];
