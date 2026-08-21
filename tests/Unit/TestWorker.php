<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Unit;

use Psr\Log\LoggerInterface;
use Quillstack\Clock\FrozenClock;
use Quillstack\DI\Container;
use Quillstack\Queue\HandlerRegistry;
use Quillstack\Queue\Queues\ArrayQueue;
use Quillstack\Queue\Tests\Mocks\ChargeCard;
use Quillstack\Queue\Tests\Mocks\FailingHandler;
use Quillstack\Queue\Tests\Mocks\Mailbox;
use Quillstack\Queue\Tests\Mocks\MockLogger;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmail;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmailHandler;
use Quillstack\Queue\Worker;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertNull;

class TestWorker
{
    private FrozenClock $clock;
    private ArrayQueue $queue;
    private HandlerRegistry $handlers;
    private Container $container;
    private Mailbox $mailbox;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertNull $assertNull
    ) {
        $this->clock = new FrozenClock();
        $this->queue = new ArrayQueue($this->clock);
        $this->handlers = new HandlerRegistry();
        $this->mailbox = new Mailbox();
        $this->container = new Container([Mailbox::class => $this->mailbox]);
    }

    private function worker(int $tries = 3): Worker
    {
        return new Worker($this->queue, $this->handlers, $this->container, $tries, 10);
    }

    public function amessageReachesItsHandler()
    {
        $this->handlers->handle(SendWelcomeEmail::class, SendWelcomeEmailHandler::class);
        $this->queue->push(new SendWelcomeEmail('radek@quillstack.com'));

        $this->worker()->runOne();

        $this->assertEqual->equal(['radek@quillstack.com'], $this->mailbox->sent);
        $this->assertEqual->equal(0, $this->queue->size());
    }

    public function anEmptyQueueGivesTheWorkerNothingToDo()
    {
        $this->assertNull->isNull($this->worker()->runOne());
        $this->assertEqual->equal(0, $this->worker()->runAll());
    }

    public function everythingWaitingIsHandled()
    {
        $this->handlers->handle(SendWelcomeEmail::class, SendWelcomeEmailHandler::class);

        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $this->queue->push(new SendWelcomeEmail($email));
        }

        $this->assertEqual->equal(3, $this->worker()->runAll());
        $this->assertEqual->equal(['a@example.com', 'b@example.com', 'c@example.com'], $this->mailbox->sent);
    }

    /**
     * A message which fails is put back, waiting a little longer each time, and set aside
     * once it has been tried enough.
     */
    public function afailingMessageIsTriedAgainAndThenSetAside()
    {
        $failing = new FailingHandler();
        $this->container->addToConfig([FailingHandler::class => $failing]);
        $this->handlers->handle(ChargeCard::class, FailingHandler::class);
        $this->queue->push(new ChargeCard(100));

        $worker = $this->worker(3);

        $worker->runOne();
        $this->assertEqual->equal(1, $failing->tried);
        $this->assertEqual->equal(0, count($this->queue->failed()));

        // Ten seconds after the first failure, twenty after the second.
        $this->clock->sleep(10);
        $worker->runOne();
        $this->assertEqual->equal(2, $failing->tried);

        $this->clock->sleep(20);
        $worker->runOne();

        $this->assertEqual->equal(3, $failing->tried);
        $this->assertEqual->equal(1, count($this->queue->failed()));
        $this->assertEqual->equal(0, $this->queue->size());
    }

    public function whatIsPutBackIsNotPickedUpAgainInTheSameRun()
    {
        $this->container->addToConfig([FailingHandler::class => new FailingHandler()]);
        $this->handlers->handle(ChargeCard::class, FailingHandler::class);
        $this->queue->push(new ChargeCard(100));

        // Without the wait being respected this would never stop.
        $this->assertEqual->equal(1, $this->worker()->runAll());
    }

    /**
     * Waiting will not make a message somebody forgot to handle handleable, so it is set
     * aside at once rather than tried three times first.
     */
    public function amessageNobodyHandlesIsSetAsideAtOnce()
    {
        $this->queue->push(new ChargeCard(100));

        $this->worker()->runOne();

        $this->assertEqual->equal(1, count($this->queue->failed()));
        $this->assertEqual->equal(0, $this->queue->size());
    }

    /**
     * What was set aside is written to the log, when the application configured one, so
     * nothing disappears without a word.
     */
    public function whatIsSetAsideIsWrittenToTheLog()
    {
        $logger = new MockLogger();
        $this->container->addToConfig([
            LoggerInterface::class => $logger,
            FailingHandler::class => new FailingHandler(),
        ]);
        $this->handlers->handle(ChargeCard::class, FailingHandler::class);
        $this->queue->push(new ChargeCard(100));

        $this->worker(1)->runOne();

        $this->assertEqual->equal(1, count($logger->entries));
        $this->assertEqual->equal('error', $logger->entries[0]['level']);
        $this->assertEqual->equal(
            'A queued message was set aside: the card was declined',
            $logger->entries[0]['message']
        );
        $this->assertEqual->equal(ChargeCard::class, $logger->entries[0]['context']['message']);
        $this->assertEqual->equal(1, $logger->entries[0]['context']['attempts']);
    }

    /**
     * Nothing breaks when no logger was configured, which is the default.
     */
    public function withoutALoggerTheMessageIsStillSetAside()
    {
        $this->container->addToConfig([FailingHandler::class => new FailingHandler()]);
        $this->handlers->handle(ChargeCard::class, FailingHandler::class);
        $this->queue->push(new ChargeCard(100));

        $this->worker(1)->runOne();

        $this->assertEqual->equal(1, count($this->queue->failed()));
    }

    public function ahandlerIsFoundThroughWhatTheMessageIs()
    {
        $this->handlers->handle(SendWelcomeEmail::class, SendWelcomeEmailHandler::class);

        $this->assertEqual->equal(
            SendWelcomeEmailHandler::class,
            $this->handlers->handlerFor(new SendWelcomeEmail('a@example.com'))
        );
        $this->assertEqual->equal(
            [SendWelcomeEmail::class => SendWelcomeEmailHandler::class],
            $this->handlers->all()
        );
    }
}
