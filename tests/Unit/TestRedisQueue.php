<?php

declare(strict_types=1);

namespace Quillstack\Queue\Tests\Unit;

use Quillstack\Clock\FrozenClock;
use Quillstack\Queue\Queue;
use Quillstack\Queue\Queues\RedisQueue;
use Quillstack\Queue\Tests\Mocks\SendWelcomeEmail;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\Types\AssertBoolean;
use Quillstack\UnitTests\Types\AssertNull;
use Redis;

/**
 * Against a real Redis. A double would agree to whatever the driver asked of it, and what is
 * worth testing here is what Redis does — that `RPOP` hands a message to one client, and that
 * a held-back message becomes due exactly once.
 *
 * Skipped where there is no Redis to talk to, so the suite still runs on a machine without
 * one. `REDIS_URL` says where to look; the default is a local server.
 */
class TestRedisQueue
{
    private ?Redis $redis = null;
    private FrozenClock $clock;
    private string $prefix;

    public function __construct(
        private AssertEqual $assertEqual,
        private AssertNull $assertNull,
        private AssertBoolean $assertBoolean
    ) {
        $this->clock = new FrozenClock();
        $this->prefix = 'quillstack:test:' . getmypid() . ':' . uniqid();
        $this->redis = self::connect();
    }

    public function __destruct()
    {
        if ($this->redis instanceof Redis) {
            $keys = $this->redis->keys($this->prefix . '*');

            foreach (is_array($keys) ? $keys : [] as $key) {
                $this->redis->del($key);
            }
        }
    }

    private static function connect(): ?Redis
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        $url = parse_url(getenv('REDIS_URL') ?: 'tcp://127.0.0.1:6379');
        $redis = new Redis();

        try {
            $connected = @$redis->connect($url['host'] ?? '127.0.0.1', $url['port'] ?? 6379, 1.0);
        } catch (\Throwable) {
            return null;
        }

        return $connected === true ? $redis : null;
    }

    private function queue(): ?RedisQueue
    {
        return $this->redis instanceof Redis
            ? new RedisQueue($this->redis, $this->prefix, $this->clock)
            : null;
    }

    public function aMessageSurvivesTheObjectThatPushedIt()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $queue->push(new SendWelcomeEmail('radek@quillstack.com'));

        $elsewhere = new RedisQueue($this->redis, $this->prefix, $this->clock);

        $this->assertEqual->equal(1, $elsewhere->size());
        $this->assertEqual->equal('radek@quillstack.com', $elsewhere->pop()->message->email);
    }

    public function anEmptyQueueGivesNothing()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $this->assertNull->isNull($queue->pop());
        $this->assertEqual->equal(0, $queue->size());
    }

    public function messagesComeBackInTheOrderTheyWentIn()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $queue->push(new SendWelcomeEmail('first@example.com'));
        $queue->push(new SendWelcomeEmail('second@example.com'));

        $this->assertEqual->equal('first@example.com', $queue->pop()->message->email);
        $this->assertEqual->equal('second@example.com', $queue->pop()->message->email);
    }

    public function aDelayedMessageIsNotGivenOutEarly()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $queue->push(new SendWelcomeEmail('later@example.com'), Queue::DEFAULT, 60);

        $this->assertNull->isNull($queue->pop());
        $this->assertEqual->equal(1, $queue->size());

        $this->clock->sleep(60);

        $this->assertEqual->equal('later@example.com', $queue->pop()->message->email);
    }

    /**
     * A held-back message is moved out of the sorted set by whoever removed it, so two workers
     * asking at the same moment cannot both put it in the list.
     */
    public function aDelayedMessageBecomesDueOnlyOnce()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $queue->push(new SendWelcomeEmail('once@example.com'), Queue::DEFAULT, 60);
        $this->clock->sleep(60);

        $one = new RedisQueue($this->redis, $this->prefix, $this->clock);
        $two = new RedisQueue($this->redis, $this->prefix, $this->clock);

        $got = array_filter([$one->pop(), $two->pop()]);

        $this->assertEqual->equal(1, count($got));
        $this->assertEqual->equal(0, $queue->size());
    }

    public function namedQueuesDoNotSeeEachOther()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $queue->push(new SendWelcomeEmail('mail@example.com'), 'emails');

        $this->assertNull->isNull($queue->pop());
        $this->assertEqual->equal(0, $queue->size());
        $this->assertEqual->equal(1, $queue->size('emails'));
        $this->assertEqual->equal('mail@example.com', $queue->pop('emails')->message->email);
    }

    public function aReleasedMessageComesBackHavingBeenTriedOnceMore()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $queue->push(new SendWelcomeEmail('retry@example.com'));

        $queue->release($queue->pop(), 30);

        $this->assertNull->isNull($queue->pop());

        $this->clock->sleep(30);

        $again = $queue->pop();

        $this->assertEqual->equal(1, $again->attempts);
        $this->assertEqual->equal('retry@example.com', $again->message->email);
    }

    public function aFailedMessageIsOutOfTheWayButStillThere()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $queue->push(new SendWelcomeEmail('broken@example.com'));
        $queue->fail($queue->pop(), 'the handler threw');

        $this->assertEqual->equal(0, $queue->size());

        $failed = $queue->failed();

        $this->assertEqual->equal(1, count($failed));
        $this->assertEqual->equal('broken@example.com', $failed[0]->message->email);
    }

    /**
     * Six processes emptying the same queue at once, and every message handed to exactly one
     * of them. `RPOP` is what makes that true, and this is what says it is.
     */
    public function noMessageIsHandedToTwoWorkers()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        for ($i = 1; $i <= 120; $i++) {
            $queue->push(new SendWelcomeEmail("user{$i}@example.com"));
        }

        $script = dirname(__DIR__) . '/Scripts/redis-pop-until-empty.php';
        $pipes = [];
        $workers = [];

        for ($i = 0; $i < 6; $i++) {
            $workers[] = proc_open(
                [PHP_BINDIR . '/php', $script, $this->prefix],
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

        $this->assertEqual->equal(120, count($handled));
        $this->assertEqual->equal(120, count(array_unique($handled)));
        $this->assertEqual->equal(0, $queue->size());
    }

    public function aMessageThatCannotBeReadIsNotHandedOut()
    {
        $queue = $this->queue();

        if ($queue === null) {
            return;
        }

        $this->redis->lPush($this->prefix . ':default', 'not a serialized envelope');

        $this->assertNull->isNull($queue->pop());
    }
}
