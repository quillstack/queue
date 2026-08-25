<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Unit;

use Quillstack\Clock\FrozenClock;
use Quillstack\Db\Connection;
use Quillstack\LocalStorage\LocalStorage;
use Quillstack\Queue\Queue;
use Quillstack\Queue\Queues\ArrayQueue;
use Quillstack\Queue\Queues\DatabaseQueue;
use Quillstack\Queue\Queues\FileQueue;
use Quillstack\Queue\Queues\RedisQueue;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmail;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;
use Quillstack\UnitTests\Types\AssertNull;
use Redis;

/**
 * What every driver has to do about a worker that never came back.
 *
 * Before this, `pop` removed the message: a process killed between being handed one and
 * finishing it took the message with it, and nothing anywhere said so. Every driver had it,
 * and the README said so as a limitation rather than a defect. This is the test that makes it
 * one behaviour instead of four, and it runs against all four.
 */
class TestReservations
{
    private FrozenClock $clock;
    private string $directory;
    private string $database;
    private string $prefix;
    private ?Redis $redis = null;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertNull $assertNull
    ) {
        $this->clock = new FrozenClock();
        $unique = getmypid() . '-' . uniqid();
        $this->directory = sys_get_temp_dir() . '/quillstack-reservations-' . $unique;
        $this->database = sys_get_temp_dir() . '/quillstack-reservations-' . $unique . '.sqlite';
        $this->prefix = 'quillstack:reservations:' . $unique;

        if (extension_loaded('redis')) {
            $url = parse_url(getenv('REDIS_URL') ?: 'tcp://127.0.0.1:6379');
            $redis = new Redis();

            try {
                $this->redis = @$redis->connect($url['host'] ?? '127.0.0.1', $url['port'] ?? 6379, 1.0)
                    ? $redis
                    : null;
            } catch (\Throwable) {
                $this->redis = null;
            }
        }
    }

    public function __destruct()
    {
        @unlink($this->database);

        if ($this->redis instanceof Redis) {
            foreach ((array) $this->redis->keys($this->prefix . '*') as $key) {
                $this->redis->del((string) $key);
            }
        }
    }

    /**
     * One driver per name, so a failure says which.
     *
     * @return array<string, Queue>
     */
    private function drivers(): array
    {
        $drivers = [
            'array' => new ArrayQueue($this->clock, 60),
            'file' => new FileQueue(new LocalStorage(), $this->directory . '-' . uniqid(), $this->clock, 60),
        ];

        $database = new DatabaseQueue(
            new Connection('sqlite:' . $this->database . '-' . uniqid()),
            DatabaseQueue::TABLE,
            $this->clock,
            60
        );
        $database->migrate();
        $drivers['database'] = $database;

        if ($this->redis instanceof Redis) {
            $drivers['redis'] = new RedisQueue($this->redis, $this->prefix . ':' . uniqid(), $this->clock, 60);
        }

        return $drivers;
    }

    /**
     * The whole reason for the change: a worker handed a message and killed before it could
     * say anything leaves the message where the next worker will find it.
     */
    public function aMessageNobodyAnsweredForComesBack()
    {
        foreach ($this->drivers() as $name => $queue) {
            $queue->push(new SendWelcomeEmail("{$name}@example.com"));

            $taken = $queue->pop();
            $this->assertEqual->equal("{$name}@example.com", $taken->message->email);

            // Nothing is said about it. Another worker asks, and is told nothing is due.
            $this->assertNull->isNull($queue->pop());

            $this->clock->sleep(60);

            $again = $queue->pop();

            $this->assertEqual->equal("{$name}@example.com", $again->message->email);

            // And it is on its second attempt, so a message which kills workers eventually
            // stops being handed out rather than going round for ever.
            $this->assertEqual->equal(2, $again->attempts);
        }
    }

    public function anAcknowledgedMessageDoesNotComeBack()
    {
        foreach ($this->drivers() as $name => $queue) {
            $queue->push(new SendWelcomeEmail("{$name}@example.com"));

            $this->assertBoolean->isTrue($queue->ack($queue->pop()));

            $this->clock->sleep(600);

            $this->assertNull->isNull($queue->pop());
            $this->assertEqual->equal(0, $queue->size());
        }
    }

    /**
     * A worker which took too long has had the message given to somebody else, and must not be
     * able to throw away work that other worker is in the middle of.
     */
    public function aLateAcknowledgementIsRefused()
    {
        foreach ($this->drivers() as $name => $queue) {
            $queue->push(new SendWelcomeEmail("{$name}@example.com"));

            $slow = $queue->pop();

            $this->clock->sleep(60);

            $other = $queue->pop();
            $this->assertEqual->equal("{$name}@example.com", $other->message->email);

            // The slow one finally finishes and says so. It is holding a reservation the queue
            // is no longer keeping.
            $this->assertBoolean->isFalse($queue->ack($slow));

            // And the message the other worker holds is still theirs to finish.
            $this->assertEqual->equal(1, $queue->size());
            $this->assertBoolean->isTrue($queue->ack($other));
        }
    }

    public function aMessageGivenBackIsNotHeldAnyMore()
    {
        foreach ($this->drivers() as $name => $queue) {
            $queue->push(new SendWelcomeEmail("{$name}@example.com"));

            $queue->release($queue->pop(), 0);

            // Available again at once, rather than waiting out a reservation nobody holds.
            $this->assertEqual->equal("{$name}@example.com", $queue->pop()->message->email);
            $this->assertEqual->equal(1, $queue->size());
        }
    }

    public function aMessageSetAsideIsNotHeldAnyMore()
    {
        foreach ($this->drivers() as $name => $queue) {
            $queue->push(new SendWelcomeEmail("{$name}@example.com"));
            $queue->fail($queue->pop(), 'nothing handles it');

            $this->assertEqual->equal(0, $queue->size());

            $this->clock->sleep(600);

            $this->assertNull->isNull($queue->pop());
        }
    }
}
