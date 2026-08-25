<?php

declare(strict_types=1);

/**
 * One worker in the race that `TestRedisQueue::noMessageIsHandedToTwoWorkers` runs.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Quillstack\Queue\Queues\RedisQueue;

$url = parse_url(getenv('REDIS_URL') ?: 'tcp://127.0.0.1:6379');
$redis = new Redis();
$redis->connect($url['host'] ?? '127.0.0.1', $url['port'] ?? 6379, 1.0);

$queue = new RedisQueue($redis, $argv[1]);

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
}
