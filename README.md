# Quillstack Queue

[![Tests](https://github.com/quillstack/queue/actions/workflows/tests.yml/badge.svg)](https://github.com/quillstack/queue/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/quillstack/queue.svg)](https://packagist.org/packages/quillstack/queue)
[![Downloads](https://img.shields.io/packagist/dt/quillstack/queue.svg)](https://packagist.org/packages/quillstack/queue)
[![PHP Version](https://img.shields.io/packagist/php-v/quillstack/queue)](https://packagist.org/packages/quillstack/queue)
[![StyleCI](https://github.styleci.io/repos/1342206597/shield?branch=main)](https://github.styleci.io/repos/1342206597?branch=main)
[![CodeFactor](https://www.codefactor.io/repository/github/quillstack/queue/badge)](https://www.codefactor.io/repository/github/quillstack/queue)
[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=quillstack_queue&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=quillstack_queue)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=quillstack_queue&metric=coverage)](https://sonarcloud.io/summary/new_code?id=quillstack_queue)
[![Maintainability](https://sonarcloud.io/api/project_badges/measure?project=quillstack_queue&metric=sqale_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_queue)
[![Reliability](https://sonarcloud.io/api/project_badges/measure?project=quillstack_queue&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_queue)
[![Security](https://sonarcloud.io/api/project_badges/measure?project=quillstack_queue&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_queue)
[![License](https://img.shields.io/packagist/l/quillstack/queue)](https://github.com/quillstack/queue/blob/main/LICENSE)

A simple queue: messages, handlers and workers.

## Why this exists

A queue has one hard requirement and everything else is convenience: **a message has to survive
being written down in one process and read back in another**. That is why a message here carries
only what it is about, and what to do with it is a separate class the container builds — a
message holding its own dependencies cannot be written down at all.

The rest is what an application actually needs from a queue and no more: a delay, named queues,
retries with a backoff, and somewhere for the messages that will not work to sit so they are not
in the way of the ones behind them.

## Requirements

- PHP 8.1 or newer

## Installation

```shell
composer require quillstack/queue
```

## Usage

### Messages and handlers

A message carries only what it is about. What is done with it is a handler, built by the
container, which asks for what it needs through its constructor:

```php
final class SendWelcomeEmail
{
    public function __construct(public readonly string $email)
    {
    }
}

final class SendWelcomeEmailHandler implements Handler
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function handle(object $message): void
    {
        $this->mailer->welcome($message->email);
    }
}
```

They are kept apart on purpose. A message which carried its own dependencies could not be
written down and read back in another process, which is the one thing a queue has to do.

```php
$handlers = new HandlerRegistry();
$handlers->handle(SendWelcomeEmail::class, SendWelcomeEmailHandler::class);
```

A handler registered for a class answers anything extending or implementing it, so one
handler can take a whole family of messages through an interface they share.

### Pushing

```php
$queue->push(new SendWelcomeEmail('radek@quillstack.com'));
```

A message can wait before anybody sees it, given in seconds or as a `DateInterval`, and can
go on a queue of its own:

```php
$queue->push(new SendReminder($id), 'emails', 3600);
$queue->push(new ChargeCard($id), delay: new DateInterval('PT5M'));
```

### Working

```php
$worker = new Worker($queue, $handlers, $container, tries: 3, backoff: 10);

$worker->runOne();   // one message, or nothing when there is none
$worker->runAll();   // everything due now, and how many there were
```

A message which fails goes back on the queue to be tried again, waiting ten seconds longer
each time. Once it has been tried enough it is set aside, so it is not in the way of
everything behind it, and written to the log when a PSR-3 logger is configured. A message
nobody handles is set aside at once: waiting will not give it a handler.

```php
$queue->failed();   // the messages which will not be tried again
```

### Where the messages live

`ArrayQueue` keeps them for as long as the process runs, which is what a test wants and what
a single request needs when whatever it queued is handled before the response goes out.

`FileQueue` writes them to a directory, one file each, so a worker in another process picks
up what a request put there:

```php
$queue = new FileQueue(new LocalStorage(), __DIR__ . '/var/queue');
```

The file is taken away before the message is handed over, so two workers reading the same
directory cannot both be given it.

`DatabaseQueue` keeps them in a table, so a worker on **another machine** picks them up. A
directory is one machine; an API behind a load balancer is not, and it already has a database:

```php
use Quillstack\Db\Connection;
use Quillstack\Queue\Queues\DatabaseQueue;

$queue = new DatabaseQueue(new Connection('pgsql:host=localhost;dbname=app', 'app', $password));
$queue->migrate();   // creates the table if it is not there; safe to run on every deploy
```

`quillstack/db` is not required by this package — install it if you want this driver.

The claim is the `DELETE` itself, and the message is handed over only to whichever worker the
database says removed the row. One statement, so there is no moment between deciding to take a
message and taking it. That is why there is no `SELECT … FOR UPDATE SKIP LOCKED` here: MySQL and
Postgres have it, SQLite does not, and this needs neither.

It is verified the only way it can be — [six processes emptying one table at
once](https://github.com/quillstack/queue/blob/main/tests/Unit/TestDatabaseQueue.php), asserting every message was handled exactly once. Two
sequential `pop` calls would prove nothing, since the first takes the row out of reach.

`RedisQueue` keeps them in Redis instead, which needs `ext-redis`:

```php
use Quillstack\Queue\Queues\RedisQueue;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$queue = new RedisQueue($redis);
```

There is nothing to create: a list per queue, a sorted set per queue for what is held back, and
`RPOP` hands a message to one client and nobody else. Verified the same way as the table —
[six processes emptying one queue at
once](https://github.com/quillstack/queue/blob/main/tests/Unit/TestRedisQueue.php).

**Start with the table.** It needs no infrastructure an API does not already have, and one
fewer thing that can be down at three in the morning is worth more than the microseconds. Move
to Redis when the queue is busy enough that the messages are worth keeping out of the database,
or when something else already runs Redis and this can share it.

### Saying what became of a message

`pop` hands a message over and **holds** it rather than removing it. The worker then says what
happened:

```php
$envelope = $queue->pop();

$queue->ack($envelope);              // handled; the queue may forget it
$queue->release($envelope, 30);      // not now; try again in thirty seconds
$queue->fail($envelope, 'why');      // never; set it aside
```

`Worker` does all three for you. This matters when you drive a queue yourself.

**If nothing says, the message comes back.** A worker killed between being handed a message and
finishing it used to take the message with it, and nothing anywhere said so. Now the reservation
runs out and the next worker finds it waiting. How long that takes is the driver's `$visibility`
— sixty seconds unless you say otherwise:

```php
$queue = new DatabaseQueue($connection, DatabaseQueue::TABLE, null, visibility: 300);
```

Being handed a message is what counts as an attempt, not giving one back. A message which kills
the process handling it is never given back at all, and counting only the polite failures would
have it handed out for ever instead of eventually set aside.

A worker which took longer than its reservation has had the message given to somebody else, and
its `ack()` is refused — it returns `false` rather than throwing away work another worker is in
the middle of.

### Topics

A queue hands a message to exactly one worker, which is what you want for work that must happen
once. A topic hands it to everything that subscribed, which is what you want when one thing
happening means several unrelated things should follow:

```php
use Quillstack\Queue\Subscriptions;
use Quillstack\Queue\Topics\QueueTopic;

$subscriptions = (new Subscriptions())
    ->subscribe('orders', 'orders.email')
    ->subscribe('orders', 'orders.ledger')
    ->subscribe('orders', 'orders.warehouse');

$topic = new QueueTopic($queue, $subscriptions);

$topic->publish(new OrderPlaced($id), 'orders');
```

`Topic` is the interface and `QueueTopic` is the way to be one out of a queue — one message per
subscriber. A broker which fans out on its own is told once and does the rest, which is the same
idea and not the same work, so it is a different implementation rather than a special case.

Nothing comes back from `publish()`. A publisher does not know who is listening — that is what a
topic is for — and handing it a receipt per subscriber would tell it exactly that. What was
published is a question for the queues.

A subscriber is a queue, so there is nothing new to run: three ordinary workers, with the
ordinary retries, delays and dead letters. `QueueTopic` is written against the `Queue` interface
rather than against any driver, so it works over an array, a directory, a table or Redis without
changing a line.

```shell
./bin/quill queue:work orders.email
./bin/quill queue:work orders.ledger
./bin/quill queue:work orders.warehouse
```

Each subscriber gets a message of its own. That is the whole point — a receipt that will not
send is retried and eventually set aside without the warehouse ever hearing about it.

Subscribers do different things with the same message, so handlers can be registered against
the queue rather than against the message:

```php
$handlers
    ->handleOn('orders.email', OrderPlaced::class, SendReceipt::class)
    ->handleOn('orders.ledger', OrderPlaced::class, RecordSale::class);
```

`handle()` still registers a handler for a message everywhere; `handleOn()` wins over it on
that one queue. Both are read by `handlerForQueue()`, which is what the worker asks —
`handlerFor()` is unchanged and still answers for a message without regard to where it came
from.

Publishing to a topic nobody subscribes to is refused:

```php
$topic->publish(new OrderPlaced($id), 'oredrs');   // UnknownTopicException
```

A misspelled topic that quietly does nothing is found weeks later by somebody asking why the
emails stopped.

**Publishing is one push per subscriber, not one atomic act.** Where two subscribers must both
hear or neither, `DatabaseQueue` is backed by a connection which has transactions, and the
publish goes inside one:

```php
$connection->transaction(fn () => $topic->publish(new OrderPlaced($id), 'orders'));
```

#### A topic or an event?

[quillstack/events](https://github.com/quillstack/events) dispatches an event to its listeners
now, in this process, before the response goes out — and if a listener throws, the request
knows. A topic hands the message to somebody else to do later, and the request is finished
whatever happens next. Use an event when the answer depends on it. Use a topic when it does not.

### Time

All three take a PSR-20 clock, which decides when a message is due. Without one they read the
wall clock. `Quillstack\Clock\FrozenClock` stands still until it is moved, so a test can
watch a delay pass without waiting for it.

## Benchmark

Measured with [quillstack/benchmark](https://github.com/quillstack/benchmark) on a thousand
messages pushed and handled in memory. Runs are interleaved and unconcurrent, each figure is the
median of five, and PHP is 8.5.7.

| | Version |
| --- | --- |
| quillstack/queue | v0.6.0 |
| symfony/messenger | v7.4.17 |

| | Per message | Relative |
| --- | --- | --- |
| **quillstack/queue** | **1.48 µs** | — |
| symfony/messenger | 2.29 µs | 1.5× |

**A microsecond either way is not what a queue costs.** What a queue costs is the transport — a
row written to a database, a message sent to Redis, an HTTP call to SQS — and that is three or
four orders of magnitude more than either number here. This table measures the part that does not
matter, and says so.

Here is the part that does. A thousand messages pushed and popped through each driver, everything
on one machine, median of five:

| Driver | Per push and pop | |
| --- | --- | --- |
| `ArrayQueue` | 3.1 µs | never leaves the process |
| `RedisQueue` | 271 µs | a few round trips to Redis |
| `DatabaseQueue` | 769 µs | SQLite, committing each statement |
| `FileQueue` | 1180 µs | a file written, a directory read |

Read these as the shape of the thing rather than as numbers to quote. Redis and the database
were both local here; across a network the round trips are what you will be paying for, and the
ordering between those two can change with the hardware underneath. What does not change is the
first line: keeping messages inside the process costs nothing and survives nothing.

`FileQueue` is last because its `pop` reads the directory to find what is due, so it gets slower
as messages pile up. That is a fine trade for a queue which is usually empty and a poor one for
a queue which is not.

What matters is what each reaches. `symfony/messenger` has transports for AMQP, Redis, Doctrine,
Amazon SQS, Beanstalkd and more, message serialization across processes, routing rules, retry
strategies you can replace, failure transports, and a middleware stack around handling. This has
an array, a directory of files, a database table, a worker, and topics.

The database table is the one that matters for an API with more than one instance, and it needs
no infrastructure that is not already there. Reach for Messenger when you want a broker —
AMQP's routing, SQS's durability, a transport this does not have — or when a message must
survive the worker that was handling it being killed. Reach for this when a table is enough,
and there is a great deal less to understand.

## Tests

```shell
composer test
```

Coverage needs phpdbg:

```shell
composer test:coverage
```

## The rest of Quillstack

This is one component of [Quillstack](https://github.com/quillstack), a PHP framework which is
as simple to use as it is strict about what it does.

- [quillstack/di](https://github.com/quillstack/di) — what builds a handler
- [quillstack/clock](https://github.com/quillstack/clock) — what decides whether a message is due
- [quillstack/logger](https://github.com/quillstack/logger) — where a message that failed is written
- [quillstack/framework](https://github.com/quillstack/framework) — where the worker command lives

## License

MIT. See [LICENSE](LICENSE).
