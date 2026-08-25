<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Unit;

use Quillstack\Clock\FrozenClock;
use Quillstack\Db\Connection;
use Quillstack\Queue\Queue;
use Quillstack\Queue\Queues\DatabaseQueue;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmail;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;
use Quillstack\UnitTests\Types\AssertNull;

/**
 * Against a real SQLite file rather than a double, because what is being tested is what the
 * database does when two workers ask for the same row — which a double would simply agree to.
 */
class TestDatabaseQueue
{
    private string $file;
    private FrozenClock $clock;
    private Connection $connection;
    private DatabaseQueue $queue;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertNull $assertNull,
        private AssertBoolean $assertBoolean
    ) {
        $this->file = sys_get_temp_dir() . '/quillstack-queue-db-' . getmypid() . '-' . uniqid() . '.sqlite';
        $this->clock = new FrozenClock();
        $this->connection = new Connection('sqlite:' . $this->file);
        $this->queue = new DatabaseQueue($this->connection, DatabaseQueue::TABLE, $this->clock);
        $this->queue->migrate();
    }

    public function __destruct()
    {
        @unlink($this->file);
    }

    /**
     * The reason this driver exists: a worker which shares nothing with the pusher but the
     * database still gets the message.
     */
    public function aMessageSurvivesTheProcessThatPushedIt()
    {
        $this->queue->push(new SendWelcomeEmail('radek@quillstack.com'));

        $elsewhere = new DatabaseQueue(
            new Connection('sqlite:' . $this->file),
            DatabaseQueue::TABLE,
            $this->clock
        );

        $this->assertEqual->equal(1, $elsewhere->size());
        $this->assertEqual->equal('radek@quillstack.com', $elsewhere->pop()->message->email);
    }

    public function anEmptyQueueGivesNothing()
    {
        $this->assertNull->isNull($this->queue->pop());
        $this->assertEqual->equal(0, $this->queue->size());
    }

    public function messagesComeBackInTheOrderTheyWentIn()
    {
        $this->queue->push(new SendWelcomeEmail('first@example.com'));
        $this->queue->push(new SendWelcomeEmail('second@example.com'));

        $this->assertEqual->equal('first@example.com', $this->queue->pop()->message->email);
        $this->assertEqual->equal('second@example.com', $this->queue->pop()->message->email);
    }

    /**
     * Six processes emptying the same table at once, and every message handed to exactly one
     * of them.
     *
     * Two sequential `pop` calls would prove nothing here — the first deletes the row, so the
     * second finds an empty table whatever the claim does. The property only exists between
     * real processes, so the test uses real ones.
     */
    public function noMessageIsHandedToTwoWorkers()
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->queue->push(new SendWelcomeEmail("user{$i}@example.com"));
        }

        $script = dirname(__DIR__) . '/Scripts/pop-until-empty.php';
        $workers = [];

        for ($i = 0; $i < 6; $i++) {
            $workers[] = proc_open(
                [PHP_BINDIR . '/php', $script, $this->file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i]
            );
        }

        $handled = [];

        foreach ($pipes as $pair) {
            $handled = array_merge($handled, array_filter(explode("\n", (string) stream_get_contents($pair[1]))));
            fclose($pair[1]);
            fclose($pair[2]);
        }

        foreach ($workers as $worker) {
            if (is_resource($worker)) {
                proc_close($worker);
            }
        }

        // Every one of them, once each, and nothing left behind.
        $this->assertEqual->equal(120, count($handled));
        $this->assertEqual->equal(120, count(array_unique($handled)));
        $this->assertEqual->equal(0, $this->queue->size());
    }

    public function aDelayedMessageIsNotGivenOutEarly()
    {
        $this->queue->push(new SendWelcomeEmail('later@example.com'), Queue::DEFAULT, 60);

        $this->assertNull->isNull($this->queue->pop());

        $this->clock->sleep(60);

        $this->assertEqual->equal('later@example.com', $this->queue->pop()->message->email);
    }

    public function namedQueuesDoNotSeeEachOther()
    {
        $this->queue->push(new SendWelcomeEmail('mail@example.com'), 'emails');

        $this->assertNull->isNull($this->queue->pop());
        $this->assertEqual->equal(0, $this->queue->size());
        $this->assertEqual->equal(1, $this->queue->size('emails'));
        $this->assertEqual->equal('mail@example.com', $this->queue->pop('emails')->message->email);
    }

    public function aReleasedMessageComesBackHavingBeenTriedOnceMore()
    {
        $this->queue->push(new SendWelcomeEmail('retry@example.com'));

        $envelope = $this->queue->pop();
        $this->queue->release($envelope, 30);

        $this->assertNull->isNull($this->queue->pop());

        $this->clock->sleep(30);

        $again = $this->queue->pop();

        // One for the first hand-out and one for this, which release does not add to.
        $this->assertEqual->equal(2, $again->attempts);
        $this->assertEqual->equal('retry@example.com', $again->message->email);
    }

    public function aFailedMessageIsOutOfTheWayButStillThere()
    {
        $this->queue->push(new SendWelcomeEmail('broken@example.com'));

        $this->queue->fail($this->queue->pop(), 'the handler threw');

        $this->assertEqual->equal(0, $this->queue->size());
        $this->assertNull->isNull($this->queue->pop());

        $failed = $this->queue->failed();

        $this->assertEqual->equal(1, count($failed));
        $this->assertEqual->equal('broken@example.com', $failed[0]->message->email);
    }

    /**
     * Running it twice is what an application does on every deploy.
     */
    public function migratingTwiceIsNotAnError()
    {
        $this->queue->migrate();
        $this->queue->push(new SendWelcomeEmail('still@example.com'));

        $this->assertEqual->equal(1, $this->queue->size());
    }

    public function aRowWhoseMessageCannotBeReadIsNotHandedOut()
    {
        $this->queue->push(new SendWelcomeEmail('fine@example.com'));

        $this->connection->execute(
            'UPDATE ' . DatabaseQueue::TABLE . ' SET payload = :payload',
            ['payload' => 'not a serialized envelope']
        );

        $this->assertNull->isNull($this->queue->pop());
        $this->assertEqual->equal(0, $this->queue->size());
    }
}
