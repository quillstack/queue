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
sequential `pop` calls would prove nothing, since the first deletes the row.

**What it does not do:** a message is gone from the table the moment a worker is handed it, so a
worker killed mid-handling loses it. That is true of `FileQueue` too — the contract here is that
`pop` takes the message away, and there is no acknowledgement step to hold it until the handler
returns. If you need a message to survive the worker dying, this is not yet that.

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

What matters is what each reaches. `symfony/messenger` has transports for AMQP, Redis, Doctrine,
Amazon SQS, Beanstalkd and more, message serialization across processes, routing rules, retry
strategies you can replace, failure transports, and a middleware stack around handling. This has
an array, a directory of files, a database table, and a worker.

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
