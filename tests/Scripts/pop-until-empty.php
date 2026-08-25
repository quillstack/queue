<?php

declare(strict_types=1);

/**
 * One worker in the race that `TestDatabaseQueue::noMessageIsHandedToTwoWorkers` runs.
 *
 * It exists as a script because the thing being tested is what two operating system processes
 * do to the same table at the same time, which cannot be staged inside one of them.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Quillstack\Db\Connection;
use Quillstack\Queue\Queues\DatabaseQueue;

$queue = new DatabaseQueue(new Connection('sqlite:' . $argv[1]));

$empty = 0;

while ($empty < 3) {
    $envelope = $queue->pop();

    if ($envelope === null) {
        ++$empty;
        usleep(1000);

        continue;
    }

    $empty = 0;
    echo $envelope->message->email, "\n";

    // A worker says what became of the message; without it the queue rightly hands it
    // back out once the reservation runs out.
    $queue->ack($envelope);
}
