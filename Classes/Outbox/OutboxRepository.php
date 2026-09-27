<?php

namespace Neuedaten\FreezedDesk\Outbox;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Storage\Database;

/**
 * Desk's own record of every message it pushed (table outbox, B2.4):
 * key, record, channel, time, hash, state and what the server reported.
 *
 * States: pending (known, not pushed), queued (on the server, waiting),
 * sent, failed, unknown (the server does not know whether it went out; a
 * person decides), withdrawn.
 */
final class OutboxRepository
{
    public const STATES = ['pending', 'queued', 'sending', 'sent', 'failed', 'unknown', 'withdrawn'];

    /** States in which a message is out of Desk's hands: never pushed again. */
    public const FINAL = ['sending', 'sent', 'unknown'];

    public function __construct(private readonly DeskContext $context)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $row = $this->context->database()->fetchOne('SELECT * FROM outbox WHERE key = :key', ['key' => $key]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param string[]|null $states
     * @return array<int, array<string, mixed>>
     */
    public function all(?array $states = null, ?int $itemId = null): array
    {
        $sql = 'SELECT * FROM outbox WHERE 1 = 1';
        $params = [];
        if ($states !== null && $states !== []) {
            $placeholders = [];
            foreach (array_values($states) as $i => $state) {
                $placeholders[] = ':s' . $i;
                $params['s' . $i] = $state;
            }
            $sql .= ' AND state IN (' . implode(', ', $placeholders) . ')';
        }
        if ($itemId !== null) {
            $sql .= ' AND item_id = :item';
            $params['item'] = $itemId;
        }
        $sql .= ' ORDER BY at ASC, key ASC';

        return array_map(self::hydrate(...), $this->context->database()->fetchAll($sql, $params));
    }

    /** @return array<string, int> state => count */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($this->context->database()->fetchAll('SELECT state, COUNT(*) AS n FROM outbox GROUP BY state') as $row) {
            $counts[(string) $row['state']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Insert or update a row; only the given columns change.
     *
     * @param array<string, mixed> $values item_id, channel, at, hash, state, pushed_at, remote_id, url, error, notice
     */
    public function upsert(string $key, array $values): void
    {
        $existing = $this->get($key);
        $values['updated_at'] = Database::now();
        if ($existing === null) {
            $row = $values + ['item_id' => null, 'channel' => '', 'at' => '', 'hash' => '', 'state' => 'pending', 'pushed_at' => null, 'remote_id' => null, 'url' => null, 'error' => null, 'notice' => null];
            $columns = array_keys($row);
            $this->context->database()->execute(
                'INSERT INTO outbox (key, ' . implode(', ', $columns) . ') VALUES (:key, :' . implode(', :', $columns) . ')',
                ['key' => $key] + $row
            );

            return;
        }
        $sets = [];
        foreach (array_keys($values) as $column) {
            $sets[] = $column . ' = :' . $column;
        }
        $this->context->database()->execute('UPDATE outbox SET ' . implode(', ', $sets) . ' WHERE key = :key', ['key' => $key] + $values);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): array
    {
        return [
            'key' => (string) $row['key'],
            'itemId' => isset($row['item_id']) ? (int) $row['item_id'] : null,
            'channel' => (string) $row['channel'],
            'at' => (string) $row['at'],
            'hash' => (string) $row['hash'],
            'state' => (string) $row['state'],
            'pushedAt' => $row['pushed_at'] !== null ? (string) $row['pushed_at'] : null,
            'remoteId' => $row['remote_id'] !== null ? (string) $row['remote_id'] : null,
            'url' => $row['url'] !== null ? (string) $row['url'] : null,
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'notice' => $row['notice'] !== null ? (string) $row['notice'] : null,
            'updatedAt' => (string) $row['updated_at'],
        ];
    }
}
