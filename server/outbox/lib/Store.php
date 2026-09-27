<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * The outbox database (SQLite, outside the web root). Holds the messages
 * with their state, the result events Desk pulls, the collected metrics and
 * the key-value state of adapters and the runner.
 *
 * States of a message:
 *
 *     queued ──claim──► sending ──► sent
 *        ▲                 │  └───► failed ──resolve──► queued
 *        │ (temporary)     │
 *        └─────────────────┤
 *                          └──────► unknown ──resolve──► sent | failed | queued
 *     queued | failed ──withdraw──► withdrawn
 *
 * A message never goes from sending back to queued without the runner's
 * decision, and never from unknown anywhere without a person (B3.4).
 * Every change to sent, failed, unknown or withdrawn, and every resolve,
 * appends one row to results: the event log that Desk pulls.
 */
final class Store implements StateStore
{
    public const STATES = ['queued', 'sending', 'sent', 'failed', 'unknown', 'withdrawn'];

    /** States in which a message still needs its files. */
    public const ACTIVE_STATES = ['queued', 'sending', 'unknown'];

    /** States in which Desk may replace a message by pushing it again. */
    public const REPLACEABLE_STATES = ['queued', 'failed', 'withdrawn'];

    private readonly \PDO $db;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * Migrations by schema version (PRAGMA user_version). Add a new entry
     * for every change; never edit a released one.
     *
     * @var array<int, string[]>
     */
    private const MIGRATIONS = [
        1 => [
            'CREATE TABLE messages (
                key TEXT PRIMARY KEY,
                channel TEXT NOT NULL,
                at_utc TEXT NOT NULL,
                payload TEXT NOT NULL,
                assets TEXT NOT NULL DEFAULT \'[]\',
                hash TEXT NOT NULL DEFAULT \'\',
                state TEXT NOT NULL CHECK (state IN (\'queued\', \'sending\', \'sent\', \'failed\', \'unknown\', \'withdrawn\')),
                attempts INTEGER NOT NULL DEFAULT 0,
                first_attempt_at TEXT NULL,
                next_attempt_at TEXT NULL,
                lease_until TEXT NULL,
                remote_id TEXT NULL,
                url TEXT NULL,
                error TEXT NULL,
                sent_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                last_used_at TEXT NULL
            )',
            'CREATE INDEX messages_state_at ON messages (state, at_utc)',
            'CREATE TABLE results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT NOT NULL,
                channel TEXT NOT NULL,
                state TEXT NOT NULL,
                remote_id TEXT NULL,
                url TEXT NULL,
                error TEXT NULL,
                at_utc TEXT NOT NULL,
                created_at TEXT NOT NULL,
                acked_at TEXT NULL
            )',
            'CREATE INDEX results_open ON results (acked_at, id)',
            'CREATE TABLE metrics (
                key TEXT PRIMARY KEY,
                channel TEXT NOT NULL,
                remote_id TEXT NOT NULL,
                values_json TEXT NOT NULL DEFAULT \'{}\',
                fetched_at TEXT NULL,
                checked_at TEXT NOT NULL
            )',
            'CREATE TABLE state (
                key TEXT PRIMARY KEY,
                value TEXT NULL,
                updated_at TEXT NOT NULL
            )',
        ],
    ];

    /** @param \Closure(): \DateTimeImmutable|null $clock */
    public function __construct(string $path, ?\Closure $clock = null)
    {
        $directory = dirname($path);
        if ($path !== ':memory:' && !is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        $this->db = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $this->db->exec('PRAGMA journal_mode = WAL');
        }
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->migrate();
    }

    public function pdo(): \PDO
    {
        return $this->db;
    }

    public function now(): string
    {
        return Time::format(($this->clock)());
    }

    public function schemaVersion(): int
    {
        return (int) $this->db->query('PRAGMA user_version')->fetchColumn();
    }

    private function migrate(): void
    {
        $version = $this->schemaVersion();
        foreach (self::MIGRATIONS as $target => $statements) {
            if ($target <= $version) {
                continue;
            }
            $this->transaction(function () use ($statements, $target): void {
                foreach ($statements as $sql) {
                    $this->db->exec($sql);
                }
                $this->db->exec('PRAGMA user_version = ' . $target);
            });
        }
    }

    /**
     * Run $work in a write transaction. BEGIN IMMEDIATE takes the write lock
     * up front, so a read-then-write (upsert, withdraw) cannot interleave
     * with the runner's claim.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->db->inTransaction()) {
            return $work();
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->db->exec('COMMIT');

            return $result;
        } catch (\Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }
    }

    // ------------------------------------------------------------ messages ---

    /** @return array<string, mixed>|null The raw row. */
    public function message(string $key): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM messages WHERE key = :key');
        $statement->execute(['key' => $key]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** Rebuild the Message an adapter gets from a stored row. */
    public static function toMessage(array $row): Message
    {
        return Message::fromArray([
            'key' => $row['key'],
            'channel' => $row['channel'],
            'at' => $row['at_utc'],
            'payload' => json_decode((string) $row['payload'], true) ?: [],
            'assets' => json_decode((string) $row['assets'], true) ?: [],
        ]);
    }

    /**
     * Insert or replace a message. Returns null when it is queued now, or
     * the current state when that state does not allow a replacement
     * (sending, sent, unknown): what went out, or may have, is never
     * overwritten.
     */
    public function upsert(Message $message, string $hash): ?string
    {
        return $this->transaction(function () use ($message, $hash): ?string {
            $now = $this->now();
            $existing = $this->message($message->key);
            $values = [
                'key' => $message->key,
                'channel' => $message->channel,
                'at' => Time::format($message->at),
                'payload' => self::encode($message->payload),
                'assets' => self::encode(array_map(static fn (Asset $a): array => $a->toArray(), $message->assets)),
                'hash' => $hash,
                'now' => $now,
            ];
            if ($existing === null) {
                $this->db->prepare('INSERT INTO messages (key, channel, at_utc, payload, assets, hash, state, attempts, created_at, updated_at)
                    VALUES (:key, :channel, :at, :payload, :assets, :hash, \'queued\', 0, :now, :now)')->execute($values);

                return null;
            }
            if (!in_array($existing['state'], self::REPLACEABLE_STATES, true)) {
                return (string) $existing['state'];
            }
            $this->db->prepare('UPDATE messages SET channel = :channel, at_utc = :at, payload = :payload, assets = :assets, hash = :hash,
                    state = \'queued\', attempts = 0, first_attempt_at = NULL, next_attempt_at = NULL, lease_until = NULL,
                    remote_id = NULL, url = NULL, error = NULL, sent_at = NULL, updated_at = :now
                WHERE key = :key')->execute($values);

            return null;
        });
    }

    /**
     * Withdraw a message that has not gone out.
     *
     * @return array{outcome: 'withdrawn'|'already'|'conflict'|'missing', state: ?string}
     */
    public function withdraw(string $key): array
    {
        return $this->transaction(function () use ($key): array {
            $row = $this->message($key);
            if ($row === null) {
                return ['outcome' => 'missing', 'state' => null];
            }
            if ($row['state'] === 'withdrawn') {
                return ['outcome' => 'already', 'state' => 'withdrawn'];
            }
            if (!in_array($row['state'], ['queued', 'failed'], true)) {
                return ['outcome' => 'conflict', 'state' => (string) $row['state']];
            }
            $now = $this->now();
            $this->db->prepare('UPDATE messages SET state = \'withdrawn\', next_attempt_at = NULL, lease_until = NULL, updated_at = :now, last_used_at = :now WHERE key = :key')
                ->execute(['key' => $key, 'now' => $now]);
            $this->addResult($key);

            return ['outcome' => 'withdrawn', 'state' => 'withdrawn'];
        });
    }

    /**
     * A person's decision (B3.4): from unknown to sent, failed or queued,
     * and from failed to queued for a manual retry.
     *
     * @return array{outcome: 'resolved'|'conflict'|'missing', state: ?string}
     */
    public function resolve(string $key, string $state, string $url = '', string $remoteId = '', string $note = ''): array
    {
        return $this->transaction(function () use ($key, $state, $url, $remoteId, $note): array {
            $row = $this->message($key);
            if ($row === null) {
                return ['outcome' => 'missing', 'state' => null];
            }
            $from = (string) $row['state'];
            $allowed = $from === 'unknown' ? ['sent', 'failed', 'queued'] : ($from === 'failed' ? ['queued'] : []);
            if (!in_array($state, $allowed, true)) {
                return ['outcome' => 'conflict', 'state' => $from];
            }
            $now = $this->now();
            if ($state === 'sent') {
                $this->db->prepare('UPDATE messages SET state = \'sent\', remote_id = :remote, url = :url, error = NULL, lease_until = NULL,
                        sent_at = :now, updated_at = :now, last_used_at = :now WHERE key = :key')
                    ->execute(['key' => $key, 'remote' => $remoteId !== '' ? $remoteId : $row['remote_id'], 'url' => $url !== '' ? $url : $row['url'], 'now' => $now]);
            } elseif ($state === 'failed') {
                $this->db->prepare('UPDATE messages SET state = \'failed\', error = :error, lease_until = NULL, updated_at = :now, last_used_at = :now WHERE key = :key')
                    ->execute(['key' => $key, 'error' => $note !== '' ? $note : ($row['error'] ?? 'Marked as failed.'), 'now' => $now]);
            } else {
                $this->db->prepare('UPDATE messages SET state = \'queued\', attempts = 0, first_attempt_at = NULL, next_attempt_at = NULL,
                        lease_until = NULL, error = NULL, updated_at = :now WHERE key = :key')
                    ->execute(['key' => $key, 'now' => $now]);
            }
            $this->addResult($key);

            return ['outcome' => 'resolved', 'state' => $state];
        });
    }

    /**
     * Keys of queued messages that are due, oldest first.
     *
     * @return string[]
     */
    public function dueKeys(string $now): array
    {
        $statement = $this->db->prepare('SELECT key FROM messages WHERE state = \'queued\' AND at_utc <= :now
            AND (next_attempt_at IS NULL OR next_attempt_at <= :now) ORDER BY at_utc, key');
        $statement->execute(['now' => $now]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Take a due message for sending. Atomic: of two runners, only the one
     * whose UPDATE changed the row may send it.
     */
    public function claim(string $key, string $now, string $leaseUntil): bool
    {
        $statement = $this->db->prepare('UPDATE messages SET state = \'sending\', lease_until = :lease, attempts = attempts + 1,
                first_attempt_at = COALESCE(first_attempt_at, :now), updated_at = :now
            WHERE key = :key AND state = \'queued\' AND at_utc <= :now AND (next_attempt_at IS NULL OR next_attempt_at <= :now)');
        $statement->execute(['key' => $key, 'now' => $now, 'lease' => $leaseUntil]);

        return $statement->rowCount() === 1;
    }

    public function markSent(string $key, string $remoteId, string $url): bool
    {
        return $this->finish($key, 'sent', 'remote_id = :remote, url = :url, error = NULL, sent_at = :now, last_used_at = :now', ['remote' => $remoteId, 'url' => $url]);
    }

    public function markFailed(string $key, string $error): bool
    {
        return $this->finish($key, 'failed', 'error = :error, last_used_at = :now', ['error' => self::shorten($error)]);
    }

    public function markUnknown(string $key, string $error): bool
    {
        return $this->finish($key, 'unknown', 'error = :error', ['error' => self::shorten($error)]);
    }

    /** Back to queued after a temporary error; no result event (nothing Desk must record). */
    public function requeue(string $key, string $nextAttemptAt, string $error): bool
    {
        $statement = $this->db->prepare('UPDATE messages SET state = \'queued\', next_attempt_at = :next, lease_until = NULL, error = :error, updated_at = :now
            WHERE key = :key AND state = \'sending\'');
        $statement->execute(['key' => $key, 'next' => $nextAttemptAt, 'error' => self::shorten($error), 'now' => $this->now()]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, string> $params */
    private function finish(string $key, string $state, string $set, array $params): bool
    {
        return $this->transaction(function () use ($key, $state, $set, $params): bool {
            $statement = $this->db->prepare('UPDATE messages SET state = \'' . $state . '\', lease_until = NULL, updated_at = :now, ' . $set . '
                WHERE key = :key AND state = \'sending\'');
            $statement->execute(['key' => $key, 'now' => $this->now()] + $params);
            if ($statement->rowCount() !== 1) {
                return false;
            }
            $this->addResult($key);

            return true;
        });
    }

    /**
     * Messages in sending whose lease ran out: the runner that took them
     * died or hung. They are never sent again automatically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expiredLeases(string $now): array
    {
        $statement = $this->db->prepare('SELECT * FROM messages WHERE state = \'sending\' AND (lease_until IS NULL OR lease_until < :now) ORDER BY key');
        $statement->execute(['now' => $now]);

        return $statement->fetchAll();
    }

    /**
     * The status view for Desk: no payload text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(?string $state = null): array
    {
        $sql = 'SELECT key, channel, at_utc, state, attempts, remote_id, url, error, updated_at FROM messages';
        $params = [];
        if ($state !== null) {
            $sql .= ' WHERE state = :state';
            $params['state'] = $state;
        }
        $statement = $this->db->prepare($sql . ' ORDER BY at_utc, key');
        $statement->execute($params);

        return array_map(static fn (array $row): array => [
            'key' => $row['key'],
            'channel' => $row['channel'],
            'at' => $row['at_utc'],
            'state' => $row['state'],
            'attempts' => (int) $row['attempts'],
            'remoteId' => $row['remote_id'],
            'url' => $row['url'],
            'error' => $row['error'],
            'updatedAt' => $row['updated_at'],
        ], $statement->fetchAll());
    }

    /** @return array<string, int> state => number of messages, every state present */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($this->db->query('SELECT state, COUNT(*) AS n FROM messages GROUP BY state')->fetchAll() as $row) {
            $counts[(string) $row['state']] = (int) $row['n'];
        }

        return $counts;
    }

    // ------------------------------------------------------------- results ---

    private function addResult(string $key): void
    {
        $row = $this->message($key);
        if ($row === null) {
            return;
        }
        $this->db->prepare('INSERT INTO results (key, channel, state, remote_id, url, error, at_utc, created_at)
            VALUES (:key, :channel, :state, :remote, :url, :error, :at, :now)')->execute([
            'key' => $row['key'],
            'channel' => $row['channel'],
            'state' => $row['state'],
            'remote' => $row['remote_id'],
            'url' => $row['url'],
            'error' => $row['error'],
            'at' => $row['at_utc'],
            'now' => $this->now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> Unacknowledged events after $since, oldest first. */
    public function results(int $since, int $limit = 500): array
    {
        $statement = $this->db->prepare('SELECT * FROM results WHERE id > :since AND acked_at IS NULL ORDER BY id LIMIT ' . max(1, $limit));
        $statement->execute(['since' => $since]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'key' => $row['key'],
            'channel' => $row['channel'],
            'state' => $row['state'],
            'remoteId' => $row['remote_id'],
            'url' => $row['url'],
            'error' => $row['error'],
            'at' => $row['at_utc'],
            'updatedAt' => $row['created_at'],
        ], $statement->fetchAll());
    }

    /** @param int[] $ids */
    public function ack(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare('UPDATE results SET acked_at = ? WHERE acked_at IS NULL AND id IN (' . $placeholders . ')');
        $statement->execute([$this->now(), ...$ids]);

        return $statement->rowCount();
    }

    public function pruneResults(string $ackedBefore): int
    {
        $statement = $this->db->prepare('DELETE FROM results WHERE acked_at IS NOT NULL AND acked_at < :before');
        $statement->execute(['before' => $ackedBefore]);

        return $statement->rowCount();
    }

    // --------------------------------------------------------------- media ---

    /**
     * Files still needed (queued, sending, unknown) and the last use of the
     * others (sent, failed, withdrawn), for pruning the media folder.
     *
     * @return array{active: array<string, true>, lastUse: array<string, string>}
     */
    public function mediaUsage(): array
    {
        $active = [];
        $lastUse = [];
        foreach ($this->db->query('SELECT state, assets, last_used_at, updated_at FROM messages')->fetchAll() as $row) {
            foreach ((array) (json_decode((string) $row['assets'], true) ?: []) as $asset) {
                $sha = strtolower((string) ($asset['sha256'] ?? ''));
                if ($sha === '') {
                    continue;
                }
                if (in_array($row['state'], self::ACTIVE_STATES, true)) {
                    $active[$sha] = true;
                    continue;
                }
                $used = (string) ($row['last_used_at'] ?? $row['updated_at']);
                if (!isset($lastUse[$sha]) || $used > $lastUse[$sha]) {
                    $lastUse[$sha] = $used;
                }
            }
        }

        return ['active' => $active, 'lastUse' => $lastUse];
    }

    // ------------------------------------------------------------- metrics ---

    /**
     * Sent messages (sent after $sentAfter) whose numbers were not checked
     * since $checkedBefore.
     *
     * @return array<int, array{key: string, channel: string, remote_id: string}>
     */
    public function metricsDue(string $sentAfter, string $checkedBefore): array
    {
        $statement = $this->db->prepare('SELECT m.key, m.channel, m.remote_id FROM messages m LEFT JOIN metrics x ON x.key = m.key
            WHERE m.state = \'sent\' AND m.remote_id IS NOT NULL AND m.remote_id <> \'\' AND m.sent_at >= :after
              AND (x.checked_at IS NULL OR x.checked_at < :before)
            ORDER BY m.sent_at');
        $statement->execute(['after' => $sentAfter, 'before' => $checkedBefore]);

        return $statement->fetchAll();
    }

    /**
     * Record a metrics call. $values null = the call failed: only the check
     * time moves on (so a broken platform is not asked every five minutes),
     * the last numbers stay.
     *
     * @param array<string, int|float>|null $values
     */
    public function saveMetrics(string $key, string $channel, string $remoteId, ?array $values): void
    {
        $now = $this->now();
        if ($values === null) {
            $this->db->prepare('INSERT INTO metrics (key, channel, remote_id, checked_at) VALUES (:key, :channel, :remote, :now)
                ON CONFLICT (key) DO UPDATE SET checked_at = excluded.checked_at')
                ->execute(['key' => $key, 'channel' => $channel, 'remote' => $remoteId, 'now' => $now]);

            return;
        }
        $this->db->prepare('INSERT INTO metrics (key, channel, remote_id, values_json, fetched_at, checked_at) VALUES (:key, :channel, :remote, :values, :now, :now)
            ON CONFLICT (key) DO UPDATE SET channel = excluded.channel, remote_id = excluded.remote_id, values_json = excluded.values_json,
                fetched_at = excluded.fetched_at, checked_at = excluded.checked_at')
            ->execute(['key' => $key, 'channel' => $channel, 'remote' => $remoteId, 'values' => self::encode($values === [] ? new \stdClass() : $values), 'now' => $now]);
    }

    /**
     * Collected numbers fetched at or after $since (all when null).
     *
     * @return array<int, array<string, mixed>>
     */
    public function metrics(?string $since = null): array
    {
        $sql = 'SELECT x.key, x.channel, x.remote_id, x.values_json, x.fetched_at, m.url, m.sent_at FROM metrics x
            LEFT JOIN messages m ON m.key = x.key WHERE x.fetched_at IS NOT NULL';
        $params = [];
        if ($since !== null) {
            $sql .= ' AND x.fetched_at >= :since';
            $params['since'] = $since;
        }
        $statement = $this->db->prepare($sql . ' ORDER BY m.sent_at, x.key');
        $statement->execute($params);

        return array_map(static fn (array $row): array => [
            'key' => $row['key'],
            'channel' => $row['channel'],
            'remoteId' => $row['remote_id'],
            'url' => $row['url'],
            'sentAt' => $row['sent_at'],
            'fetchedAt' => $row['fetched_at'],
            'values' => (object) (json_decode((string) $row['values_json'], true) ?: []),
        ], $statement->fetchAll());
    }

    // --------------------------------------------------------------- state ---

    public function get(string $key): ?string
    {
        $statement = $this->db->prepare('SELECT value FROM state WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    public function set(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->db->prepare('DELETE FROM state WHERE key = :key')->execute(['key' => $key]);

            return;
        }
        $this->db->prepare('INSERT INTO state (key, value, updated_at) VALUES (:key, :value, :now)
            ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
            ->execute(['key' => $key, 'value' => $value, 'now' => $this->now()]);
    }

    // ------------------------------------------------------------- helpers ---

    private static function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Error texts are for people; keep them short (C7.2). */
    private static function shorten(string $text): string
    {
        return mb_strlen($text) > 1000 ? mb_substr($text, 0, 1000) . '…' : $text;
    }
}
