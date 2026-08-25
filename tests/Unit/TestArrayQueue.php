<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Unit;

use DateInterval;
use Quillstack\Clock\FrozenClock;
use Quillstack\Queue\Queue;
use Quillstack\Queue\Queues\ArrayQueue;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmail;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertNull;

class TestArrayQueue
{
    private FrozenClock $clock;
    private ArrayQueue $queue;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertNull $assertNull
    ) {
        $this->clock = new FrozenClock();
        $this->queue = new ArrayQueue($this->clock);
    }

    public function whatIsPushedComesBackOut()
    {
        $this->queue->push(new SendWelcomeEmail('radek@quillstack.com'));

        $this->assertEqual->equal(1, $this->queue->size());

        $envelope = $this->queue->pop();

        $this->assertEqual->equal('radek@quillstack.com', $envelope->message->email);
        $this->assertEqual->equal(Queue::DEFAULT, $envelope->queue);

        // Handing it over is the attempt, so it is counted here rather than when somebody
        // gives it back — a message which kills the worker is never given back at all.
        $this->assertEqual->equal(1, $envelope->attempts);

        // Still the queue's business until somebody says what became of it.
        $this->assertEqual->equal(1, $this->queue->size());

        $this->queue->ack($envelope);

        $this->assertEqual->equal(0, $this->queue->size());
    }

    public function anEmptyQueueGivesNothing()
    {
        $this->assertNull->isNull($this->queue->pop());
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
        $this->queue->push(new SendWelcomeEmail('b@example.com'), 'payments');

        $this->assertEqual->equal(1, $this->queue->size('emails'));
        $this->assertEqual->equal(0, $this->queue->size());
        $this->assertNull->isNull($this->queue->pop());
        $this->assertEqual->equal('a@example.com', $this->queue->pop('emails')->message->email);
    }

    /**
     * A message held back is not handed over before its time, and everything behind it waits
     * no longer than it has to.
     */
    public function amessageHeldBackWaits()
    {
        $this->queue->push(new SendWelcomeEmail('later@example.com'), Queue::DEFAULT, 60);

        $this->assertNull->isNull($this->queue->pop());

        $this->clock->sleep(60);
        $this->assertEqual->equal('later@example.com', $this->queue->pop()->message->email);
    }

    public function theWaitMayBeGivenAsAnInterval()
    {
        $this->queue->push(new SendWelcomeEmail('later@example.com'), Queue::DEFAULT, new DateInterval('PT1H'));

        $this->clock->sleep(3599);
        $this->assertNull->isNull($this->queue->pop());

        $this->clock->sleep(1);
        $this->assertEqual->equal('later@example.com', $this->queue->pop()->message->email);
    }

    public function whatIsDueIsTakenBeforeWhatIsNot()
    {
        $this->queue->push(new SendWelcomeEmail('later@example.com'), Queue::DEFAULT, 60);
        $this->queue->push(new SendWelcomeEmail('now@example.com'));

        $this->assertEqual->equal('now@example.com', $this->queue->pop()->message->email);
    }

    public function amessagePutBackIsCountedAsTried()
    {
        $this->queue->push(new SendWelcomeEmail('again@example.com'));

        $envelope = $this->queue->pop();

        // Counted when it was handed over, not when it is given back.
        $this->assertEqual->equal(1, $envelope->attempts);

        $released = $this->queue->release($envelope, 30);

        $this->assertEqual->equal(1, $released->attempts);
        $this->assertNull->isNull($this->queue->pop());

        $this->clock->sleep(30);
        $this->assertEqual->equal(2, $this->queue->pop()->attempts);
    }

    public function whatIsSetAsideIsKeptApart()
    {
        $this->queue->push(new SendWelcomeEmail('lost@example.com'));
        $envelope = $this->queue->pop();

        $this->queue->fail($envelope, 'nothing handles it');

        $this->assertEqual->equal(1, count($this->queue->failed()));
        $this->assertEqual->equal('lost@example.com', $this->queue->failed()[0]->message->email);
        $this->assertEqual->equal(0, $this->queue->size());
    }
}
