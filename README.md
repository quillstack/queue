# Quillstack Queue

[![Tests](https://github.com/quillstack/queue/actions/workflows/tests.yml/badge.svg)](https://github.com/quillstack/queue/actions/workflows/tests.yml)

A simple queue: messages, handlers and workers.

## Installation

```shell
composer require quillstack/queue
```

## Messages and handlers

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

## Pushing

```php
$queue->push(new SendWelcomeEmail('radek@quillstack.com'));
```

A message can wait before anybody sees it, given in seconds or as a `DateInterval`, and can
go on a queue of its own:

```php
$queue->push(new SendReminder($id), 'emails', 3600);
$queue->push(new ChargeCard($id), delay: new DateInterval('PT5M'));
```

## Working

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

## Where the messages live

`ArrayQueue` keeps them for as long as the process runs, which is what a test wants and what
a single request needs when whatever it queued is handled before the response goes out.

`FileQueue` writes them to a directory, one file each, so a worker in another process picks
up what a request put there:

```php
$queue = new FileQueue(new LocalStorage(), __DIR__ . '/var/queue');
```

The file is taken away before the message is handed over, so two workers reading the same
directory cannot both be given it.

## Time

Both take a PSR-20 clock, which decides when a message is due. Without one they read the
wall clock. `Quillstack\Clock\FrozenClock` stands still until it is moved, so a test can
watch a delay pass without waiting for it.

## Tests

```shell
composer test
```

Coverage needs phpdbg:

```shell
composer test:coverage
```

## License

MIT. See [LICENSE](LICENSE).
