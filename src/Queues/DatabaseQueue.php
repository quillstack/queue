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

    /**
     * @param int $visibility how long a worker has to say what became of a message
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = self::TABLE,
        ?ClockInterface $clock = null,
        private readonly int $visibility = self::VISIBILITY
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
     * The claim is an `UPDATE` guarded by `reserved_at IS NULL`, and the message is handed
     * over only to whoever the database says changed the row. One statement, so there is no
     * window between deciding and taking, and `SELECT … FOR UPDATE SKIP LOCKED` is not needed
     * to say so — which matters, because SQLite does not have it.
     *
     * The row stays. That is the difference between a message a dead worker took with it and
     * one that comes back when the reservation runs out.
     *
     * A worker that loses the race moves to the next candidate rather than waiting.
     */
    public function pop(string $queue = self::DEFAULT): ?Envelope
    {
        $this->returnAbandoned();

        $now = $this->now();
        $name = $this->quoted();

        $rows = $this->connection->select(
            "SELECT id, payload FROM {$name}"
            . ' WHERE queue = :queue AND reserved_at IS NULL AND available_at <= :now'
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

            $reserved = $envelope->reservedAs($this->nextId());

            $claimed = $this->connection->execute(
                "UPDATE {$name} SET reserved_at = :now, token = :token, attempts = :attempts,"
                . ' payload = :payload WHERE id = :id AND reserved_at IS NULL',
                [
                    'now' => $now,
                    'token' => $reserved->reservation,
                    'attempts' => $reserved->attempts,
                    'payload' => serialize($reserved),
                    'id' => $id,
                ]
            );

            if ($claimed === 1) {
                return $reserved;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function ack(Envelope $envelope): bool
    {
        if ($envelope->reservation === '') {
            return false;
        }

        $removed = $this->connection->execute(
            'DELETE FROM ' . $this->quoted() . ' WHERE id = :id AND token = :token',
            ['id' => $envelope->id, 'token' => $envelope->reservation]
        );

        return $removed === 1;
    }

    /**
     * Rows nobody spoke for become waiting messages again.
     */
    private function returnAbandoned(): void
    {
        $this->connection->execute(
            'UPDATE ' . $this->quoted() . ' SET reserved_at = NULL, token = NULL'
            . ' WHERE reserved_at IS NOT NULL AND reserved_at <= :expired',
            ['expired' => $this->now() - $this->visibility]
        );
    }

    /**
     * {@inheritDoc}
     */
    public function release(Envelope $envelope, null|int|DateInterval $delay = null): Envelope
    {
        $this->deleteReservation($envelope);

        $released = $envelope->retryAt(Delay::until($delay, $this->now()));
        $this->insert($released, $released->queue);

        return $released;
    }

    private function deleteReservation(Envelope $envelope): void
    {
        if ($envelope->reservation === '') {
            $this->deleteById($envelope->id);

            return;
        }

        $this->connection->execute(
            'DELETE FROM ' . $this->quoted() . ' WHERE id = :id AND token = :token',
            ['id' => $envelope->id, 'token' => $envelope->reservation]
        );
    }

    /**
     * {@inheritDoc}
     */
    public function fail(Envelope $envelope, string $reason): void
    {
        $this->deleteReservation($envelope);
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
            . ' reserved_at BIGINT DEFAULT NULL,'
            . ' token VARCHAR(32) DEFAULT NULL,'
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
            . ' (id, queue, payload, attempts, available_at, created_at, reserved_at, token, reason)'
            . ' VALUES (:id, :queue, :payload, :attempts, :available_at, :created_at, NULL, NULL, :reason)',
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
