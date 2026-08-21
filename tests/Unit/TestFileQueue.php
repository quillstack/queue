<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Unit;

use Quillstack\Clock\FrozenClock;
use Quillstack\LocalStorage\LocalStorage;
use Quillstack\Queue\Exceptions\MessageNotStoredException;
use Quillstack\Queue\Queue;
use Quillstack\Queue\Queues\FileQueue;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmail;
use Quillstack\Queue\Tests\Mocks\ThrowingStorage;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;
use Quillstack\UnitTests\Types\AssertNull;

class TestFileQueue
{
    private string $directory;
    private FrozenClock $clock;
    private FileQueue $queue;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertNull $assertNull,
        private AssertExceptions $assertExceptions
    ) {
        $this->directory = sys_get_temp_dir() . '/quillstack-queue-' . getmypid() . '-' . uniqid();
        $this->clock = new FrozenClock();
        $this->queue = new FileQueue(new LocalStorage(), $this->directory, $this->clock);
    }

    /**
     * Written down by one process and read back by another, which is the whole point of
     * putting them on a disk.
     */
    public function amessageSurvivesTheObjectThatPushedIt()
    {
        $this->queue->push(new SendWelcomeEmail('radek@quillstack.com'));

        $another = new FileQueue(new LocalStorage(), $this->directory, $this->clock);

        $this->assertEqual->equal(1, $another->size());
        $this->assertEqual->equal('radek@quillstack.com', $another->pop()->message->email);
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

    public function queuesAreKeptApart()
    {
        $this->queue->push(new SendWelcomeEmail('a@example.com'), 'emails');

        $this->assertEqual->equal(1, $this->queue->size('emails'));
        $this->assertEqual->equal(0, $this->queue->size());
    }

    /**
     * A queue named something a directory cannot be called still works.
     */
    public function anameWhichIsNotAFileNameStillWorks()
    {
        $this->queue->push(new SendWelcomeEmail('a@example.com'), 'emails/high priority');

        $this->assertEqual->equal(1, $this->queue->size('emails/high priority'));
    }

    public function amessageHeldBackWaits()
    {
        $this->queue->push(new SendWelcomeEmail('later@example.com'), Queue::DEFAULT, 60);

        $this->assertNull->isNull($this->queue->pop());

        $this->clock->sleep(60);
        $this->assertEqual->equal('later@example.com', $this->queue->pop()->message->email);
    }

    public function amessagePutBackIsCountedAsTried()
    {
        $envelope = $this->queue->push(new SendWelcomeEmail('again@example.com'));
        $this->queue->pop();

        $this->queue->release($envelope, 30);
        $this->clock->sleep(30);

        $this->assertEqual->equal(1, $this->queue->pop()->attempts);
    }

    public function whatIsSetAsideIsKeptApart()
    {
        $envelope = $this->queue->push(new SendWelcomeEmail('lost@example.com'));
        $this->queue->pop();

        $this->queue->fail($envelope, 'nothing handles it');

        $this->assertEqual->equal(1, count($this->queue->failed()));
        $this->assertEqual->equal('lost@example.com', $this->queue->failed()[0]->message->email);
        $this->assertEqual->equal(0, $this->queue->size());
    }

    /**
     * A file holding something else than a message is thrown away rather than trusted.
     */
    public function afileWhichIsNotAMessageIsDiscarded()
    {
        $this->queue->push(new SendWelcomeEmail('a@example.com'));
        $path = (string) (glob($this->directory . '/default/*.message') ?: [])[0];
        file_put_contents($path, 'not a message at all');

        $this->assertNull->isNull($this->queue->pop());
        $this->assertEqual->equal(0, $this->queue->size());
    }

    /**
     * A path which exists but is a file, not a directory, cannot hold messages either.
     */
    public function aplaceWhichIsNotADirectoryIsReported()
    {
        $path = sys_get_temp_dir() . '/quillstack-queue-file-' . getmypid();
        file_put_contents($path, 'a file, not a directory');

        try {
            $this->assertExceptions->expect(MessageNotStoredException::class);

            (new FileQueue(new LocalStorage(), $path))->push(new SendWelcomeEmail('a@example.com'));
        } finally {
            @unlink($path);
        }
    }

    /**
     * A file listed and then taken away before it could be read is passed over rather than
     * ending the run.
     */
    public function afileWhichDisappearsIsPassedOver()
    {
        $this->queue->push(new SendWelcomeEmail('a@example.com'));

        $queue = new FileQueue(new ThrowingStorage(), $this->directory, $this->clock);

        $this->assertNull->isNull($queue->pop());
        $this->assertEqual->equal([], $queue->failed());
    }

    public function adirectoryWhichCannotBeMadeIsReported()
    {
        $this->assertExceptions->expect(MessageNotStoredException::class);

        (new FileQueue(new LocalStorage(), "/quillstack\0impossible"))
            ->push(new SendWelcomeEmail('a@example.com'));
    }
}
