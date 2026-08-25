<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Unit;

use Quillstack\Clock\FrozenClock;
use Quillstack\DI\Container;
use Quillstack\Queue\Exceptions\UnknownTopicException;
use Quillstack\Queue\HandlerRegistry;
use Quillstack\Queue\Queues\ArrayQueue;
use Quillstack\Queue\Subscriptions;
use Quillstack\Queue\Tests\Mocks\Ledger;
use Quillstack\Queue\Tests\Mocks\Mailbox;
use Quillstack\Queue\Tests\Mocks\RecordWelcomeHandler;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmail;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmailHandler;
use Quillstack\Queue\Topic;
use Quillstack\Queue\Worker;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestTopic
{
    private FrozenClock $clock;
    private ArrayQueue $queue;
    private Subscriptions $subscriptions;
    private Topic $topic;
    private HandlerRegistry $handlers;
    private Container $container;
    private Mailbox $mailbox;
    private Ledger $ledger;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertExceptions $assertExceptions
    ) {
        $this->clock = new FrozenClock();
        $this->queue = new ArrayQueue($this->clock);
        $this->subscriptions = new Subscriptions();
        $this->topic = new Topic($this->queue, $this->subscriptions);
        $this->handlers = new HandlerRegistry();
        $this->mailbox = new Mailbox();
        $this->ledger = new Ledger();
        $this->container = new Container([
            Mailbox::class => $this->mailbox,
            Ledger::class => $this->ledger,
        ]);
    }

    private function worker(): Worker
    {
        return new Worker($this->queue, $this->handlers, $this->container, 3, 10);
    }

    public function everySubscriberGetsItsOwnMessage()
    {
        $this->subscriptions
            ->subscribe('welcome', 'welcome.email')
            ->subscribe('welcome', 'welcome.ledger');

        $published = $this->topic->publish(new SendWelcomeEmail('radek@quillstack.com'), 'welcome');

        $this->assertEqual->equal(2, count($published));
        $this->assertEqual->equal(1, $this->queue->size('welcome.email'));
        $this->assertEqual->equal(1, $this->queue->size('welcome.ledger'));
    }

    /**
     * The reason for a fan-out at all: each subscriber does something different with the same
     * message. One handler per message class could not say that.
     */
    public function eachSubscriberDoesItsOwnThing()
    {
        $this->subscriptions
            ->subscribe('welcome', 'welcome.email')
            ->subscribe('welcome', 'welcome.ledger');

        $this->handlers
            ->handleOn('welcome.email', SendWelcomeEmail::class, SendWelcomeEmailHandler::class)
            ->handleOn('welcome.ledger', SendWelcomeEmail::class, RecordWelcomeHandler::class);

        $this->topic->publish(new SendWelcomeEmail('radek@quillstack.com'), 'welcome');

        $this->worker()->runAll('welcome.email');
        $this->worker()->runAll('welcome.ledger');

        $this->assertEqual->equal(['radek@quillstack.com'], $this->mailbox->sent);
        $this->assertEqual->equal(['radek@quillstack.com'], $this->ledger->recorded);
    }

    /**
     * One subscriber failing is the other subscribers' business not at all. That only holds
     * because each got a message of its own.
     */
    public function oneSubscriberFailingLeavesTheOthersAlone()
    {
        $this->subscriptions
            ->subscribe('welcome', 'welcome.email')
            ->subscribe('welcome', 'welcome.ledger');

        // Nothing handles it on welcome.email, so that subscriber's copy is set aside.
        $this->handlers->handleOn('welcome.ledger', SendWelcomeEmail::class, RecordWelcomeHandler::class);

        $this->topic->publish(new SendWelcomeEmail('radek@quillstack.com'), 'welcome');

        $this->worker()->runAll('welcome.email');
        $this->worker()->runAll('welcome.ledger');

        $this->assertEqual->equal(['radek@quillstack.com'], $this->ledger->recorded);
        $this->assertEqual->equal([], $this->mailbox->sent);
        $this->assertEqual->equal(1, count($this->queue->failed()));
    }

    /**
     * A misspelled topic doing nothing quietly is found weeks later by somebody asking why
     * the emails stopped.
     */
    public function publishingToATopicNobodySubscribesToIsRefused()
    {
        $this->assertExceptions->expect(UnknownTopicException::class);

        $this->topic->publish(new SendWelcomeEmail('radek@quillstack.com'), 'wlecome');
    }

    public function subscribingTwiceDoesNotSendItTwice()
    {
        $this->subscriptions
            ->subscribe('welcome', 'welcome.email')
            ->subscribe('welcome', 'welcome.email');

        $published = $this->topic->publish(new SendWelcomeEmail('radek@quillstack.com'), 'welcome');

        $this->assertEqual->equal(1, count($published));
        $this->assertEqual->equal(1, $this->queue->size('welcome.email'));
    }

    public function aDelayHoldsEverySubscriberBack()
    {
        $this->subscriptions
            ->subscribe('welcome', 'welcome.email')
            ->subscribe('welcome', 'welcome.ledger');

        $this->handlers
            ->handleOn('welcome.email', SendWelcomeEmail::class, SendWelcomeEmailHandler::class)
            ->handleOn('welcome.ledger', SendWelcomeEmail::class, RecordWelcomeHandler::class);

        $this->topic->publish(new SendWelcomeEmail('radek@quillstack.com'), 'welcome', 60);

        $this->worker()->runAll('welcome.email');
        $this->assertEqual->equal([], $this->mailbox->sent);

        $this->clock->sleep(60);

        $this->worker()->runAll('welcome.email');
        $this->assertEqual->equal(['radek@quillstack.com'], $this->mailbox->sent);
    }

    public function aQueueHandlerWinsOverTheOneRegisteredEverywhere()
    {
        $this->subscriptions->subscribe('welcome', 'welcome.ledger');

        $this->handlers
            ->handle(SendWelcomeEmail::class, SendWelcomeEmailHandler::class)
            ->handleOn('welcome.ledger', SendWelcomeEmail::class, RecordWelcomeHandler::class);

        $this->topic->publish(new SendWelcomeEmail('radek@quillstack.com'), 'welcome');
        $this->worker()->runAll('welcome.ledger');

        $this->assertEqual->equal(['radek@quillstack.com'], $this->ledger->recorded);
        $this->assertEqual->equal([], $this->mailbox->sent);
    }

    /**
     * A queue nobody registered anything special for still uses the ordinary handler, so
     * adding topics takes nothing away from a queue that was working before.
     */
    public function aQueueWithNothingOfItsOwnFallsBackToTheOrdinaryHandler()
    {
        $this->subscriptions->subscribe('welcome', 'welcome.email');
        $this->handlers->handle(SendWelcomeEmail::class, SendWelcomeEmailHandler::class);

        $this->topic->publish(new SendWelcomeEmail('radek@quillstack.com'), 'welcome');
        $this->worker()->runAll('welcome.email');

        $this->assertEqual->equal(['radek@quillstack.com'], $this->mailbox->sent);
    }

    public function whatIsSubscribedCanBeAsked()
    {
        $this->subscriptions->subscribe('welcome', 'welcome.email');

        $this->assertBoolean->isTrue($this->subscriptions->has('welcome'));
        $this->assertBoolean->isFalse($this->subscriptions->has('nothing'));
        $this->assertEqual->equal(['welcome.email'], $this->subscriptions->queuesFor('welcome'));
    }
}
