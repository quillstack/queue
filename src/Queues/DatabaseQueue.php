<?php

declare(strict_types=1);

namespace Quillstack\Queue\Queues;

use DateInterval;
use Psr\Clock\ClockInterface;
use Quillstack\Clock\SystemClock;
use Quillstack\Db\Connection;
use Quillstack\Queue\Delay;
use Quillstack\Queue\Envelope;
use Quillstack\Queue\Queue;

/**
 * Keeps messages in a database table, so a worker on another machine picks up what a request
 * put there.
 *
 * `FileQueue` reaches one directory, which means one machine. An API behind a load balancer
 * has more than one, and they already share a database — so this needs no infrastructure that
 * is not there anyway.
 */
class DatabaseQueue implements Queue
{
    /**
     * @var string
     */
    public const TABLE = 'queue_messages';

    /**
     * @var string
     */
    private const FAILED = 'failed';

    private ClockInterface $clock;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = self::TABLE,
        ?ClockInterface $clock = null
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * {@inheritDoc}
     */
    public function push(object $message, string $queue = self::DEFAULT, null|int|DateInterval $delay = null): Envelope
    {
        $envelope = new Envelope(
            $this->nextId(),
            $message,
            $queue,
            0,
            Delay::until($delay, $this->now())
        );

        $this->insert($envelope, $queue);

        return $envelope;
    }

    /**
     * {@inheritDoc}
     *
     * The claim is the `DELETE` itself, and the message is handed over only to whoever it
     * says removed the row. One statement, so there is no window between deciding and taking:
     * exactly one worker can delete a given row, on every engine, and `SELECT … FOR UPDATE
     * SKIP LOCKED` is not needed to say so — which matters, because SQLite does not have it.
     *
     * A worker that loses the race moves to the next candidate rather than waiting.
     */
    public function pop(string $queue = self::DEFAULT): ?Envelope
    {
        $now = $this->now();
        $name = $this->quoted();

        $rows = $this->connection->select(
            "SELECT id, payload FROM {$name}"
            . ' WHERE queue = :queue AND available_at <= :now'
            . ' ORDER BY available_at ASC, created_at ASC, id ASC',
            ['queue' => $queue, 'now' => $now]
        );

        foreach ($rows as $row) {
            $id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
            $envelope = $this->unpack($row['payload'] ?? null);

            if ($id === '' || $envelope === null) {
                $this->deleteById($id);

                continue;
            }

            if ($this->claim($id)) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope
    {
        $released = $envelope->retryAt(Delay::until($delay, $this->now()));
        $this->insert($released, $released->queue);

        return $released;
    }

    /**
     * {@inheritDoc}
     */
    public function fail(Envelope $envelope, string $reason): void
    {
        $this->insert($envelope, self::FAILED, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $queue = self::DEFAULT): int
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS waiting FROM ' . $this->quoted() . ' WHERE queue = :queue',
            ['queue' => $queue]
        );

        return is_scalar($row['waiting'] ?? null) ? (int) $row['waiting'] : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function failed(): array
    {
        $rows = $this->connection->select(
            'SELECT payload FROM ' . $this->quoted()
            . ' WHERE queue = :queue ORDER BY created_at ASC, id ASC',
            ['queue' => self::FAILED]
        );

        $failed = [];

        foreach ($rows as $row) {
            $envelope = $this->unpack($row['payload'] ?? null);

            if ($envelope !== null) {
                $failed[] = $envelope;
            }
        }

        return $failed;
    }

    /**
     * Creates the table, if it is not there already.
     *
     * The index is what keeps `pop` from reading the whole table, and it is written per
     * dialect because MySQL has no `CREATE INDEX IF NOT EXISTS` and wants it inside the
     * `CREATE TABLE` instead.
     */
    public function migrate(): void
    {
        $name = $this->quoted();
        $mysql = $this->connection->dialect()->name() === 'mysql';
        $index = $mysql ? ', KEY queue_messages_pop (queue, available_at)' : '';

        $this->connection->execute(
            "CREATE TABLE IF NOT EXISTS {$name} ("
            . ' id VARCHAR(32) NOT NULL,'
            . ' queue VARCHAR(64) NOT NULL,'
            . ' payload TEXT NOT NULL,'
            . ' attempts INTEGER NOT NULL DEFAULT 0,'
            . ' available_at BIGINT NOT NULL,'
            . ' created_at BIGINT NOT NULL,'
            . ' reason TEXT DEFAULT NULL,'
            . " PRIMARY KEY (id){$index})"
        );

        if (!$mysql) {
            $this->connection->execute(
                'CREATE INDEX IF NOT EXISTS queue_messages_pop ON ' . $this->quoted()
                . ' (queue, available_at)'
            );
        }
    }

    private function insert(Envelope $envelope, string $queue, ?string $reason = null): void
    {
        $this->connection->execute(
            'INSERT INTO ' . $this->quoted()
            . ' (id, queue, payload, attempts, available_at, created_at, reason)'
            . ' VALUES (:id, :queue, :payload, :attempts, :available_at, :created_at, :reason)',
            [
                // A released message keeps its id, and the row it came from is gone, so
                // there is nothing for it to collide with.
                'id' => $envelope->id,
                'queue' => $queue,
                'payload' => serialize($envelope),
                'attempts' => $envelope->attempts,
                'available_at' => $envelope->availableAt,
                'created_at' => (int) (microtime(true) * 1_000_000),
                'reason' => $reason,
            ]
        );
    }

    /**
     * Takes the row away, and says whether this was the caller that took it.
     */
    private function claim(string $id): bool
    {
        return $this->deleteById($id) === 1;
    }

    private function deleteById(string $id): int
    {
        if ($id === '') {
            return 0;
        }

        return $this->connection->execute(
            'DELETE FROM ' . $this->quoted() . ' WHERE id = :id',
            ['id' => $id]
        );
    }

    private function unpack(mixed $payload): ?Envelope
    {
        $envelope = is_string($payload) ? @unserialize($payload) : false;

        return $envelope instanceof Envelope ? $envelope : null;
    }

    private function quoted(): string
    {
        return $this->connection->dialect()->quoteIdentifier($this->table);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function nextId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
