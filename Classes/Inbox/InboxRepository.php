<?php

namespace Neuedaten\FreezedDesk\Inbox;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Storage\Database;

/**
 * Submissions that were fetched from the website's endpoint. Status: new
 * (unseen), open, done, spam.
 */
final class InboxRepository
{
    public const STATUSES = ['new', 'open', 'done', 'spam'];

    public function __construct(private readonly DeskContext $context)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->context->database()->fetchOne('SELECT * FROM inbox WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::hydrate($row);
    }

    /** @return array<string, mixed> */
    public function require(int $id): array
    {
        return $this->get($id) ?? throw new NotFoundException('Inbox entry #' . $id . ' does not exist.');
    }

    /**
     * @param string[]|null $statuses
     * @return array<int, array<string, mixed>>
     */
    public function all(?array $statuses = null, int $limit = 0, int $offset = 0): array
    {
        $sql = 'SELECT * FROM inbox';
        $params = [];
        if ($statuses !== null && $statuses !== []) {
            $placeholders = [];
            foreach (array_values($statuses) as $i => $status) {
                $placeholders[] = ':s' . $i;
                $params['s' . $i] = $status;
            }
            $sql .= ' WHERE status IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY received_at DESC, id DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }

        return array_map(self::hydrate(...), $this->context->database()->fetchAll($sql, $params));
    }

    /** @param string[]|null $statuses */
    public function count(?array $statuses = null): int
    {
        if (!$this->context->database()->exists()) {
            return 0;
        }
        try {
            if ($statuses === null || $statuses === []) {
                return (int) $this->context->database()->fetchValue('SELECT COUNT(*) FROM inbox');
            }
            $placeholders = [];
            $params = [];
            foreach (array_values($statuses) as $i => $status) {
                $placeholders[] = ':s' . $i;
                $params['s' . $i] = $status;
            }

            return (int) $this->context->database()->fetchValue('SELECT COUNT(*) FROM inbox WHERE status IN (' . implode(', ', $placeholders) . ')', $params);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Store a fetched submission; one with a known (form, remote id) is
     * skipped. Returns the local id, or null when skipped.
     *
     * @param array<string, mixed> $payload
     */
    public function add(?string $remoteId, string $form, array $payload, ?string $receivedAt = null, ?int $itemId = null): ?int
    {
        $database = $this->context->database();
        if ($remoteId !== null) {
            $exists = $database->fetchValue('SELECT id FROM inbox WHERE form = :form AND remote_id = :remote', ['form' => $form, 'remote' => $remoteId]);
            if ($exists !== null) {
                return null;
            }
        }

        if ($itemId === null) {
            $itemId = $this->matchItem($form, $payload);
        }

        $database->execute(
            'INSERT INTO inbox (remote_id, form, item_id, payload, status, note, received_at) VALUES (:remote, :form, :item, :payload, :status, :note, :received)',
            [
                'remote' => $remoteId,
                'form' => $form,
                'item' => $itemId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'new',
                'note' => '',
                'received' => $receivedAt ?? Database::now(),
            ]
        );

        return $database->lastInsertId();
    }

    /**
     * @param array<string, mixed> $changes status, note, itemId
     */
    public function update(int $id, array $changes): array
    {
        $entry = $this->require($id);
        $status = isset($changes['status']) && in_array($changes['status'], self::STATUSES, true) ? $changes['status'] : $entry['status'];
        $note = array_key_exists('note', $changes) ? trim((string) $changes['note']) : $entry['note'];
        $itemId = array_key_exists('itemId', $changes) ? ($changes['itemId'] === null || $changes['itemId'] === '' ? null : (int) $changes['itemId']) : $entry['itemId'];
        $handledAt = in_array($status, ['done', 'spam'], true) ? ($entry['handledAt'] ?? Database::now()) : null;

        $this->context->database()->execute(
            'UPDATE inbox SET status = :status, note = :note, item_id = :item, handled_at = :handled WHERE id = :id',
            ['status' => $status, 'note' => $note, 'item' => $itemId, 'handled' => $handledAt, 'id' => $id]
        );

        return $this->require($id);
    }

    public function delete(int $id): void
    {
        $this->context->database()->execute('DELETE FROM inbox WHERE id = :id', ['id' => $id]);
    }

    /**
     * The record a submission is about, by the slug in the form's item
     * field, when the form declares one.
     *
     * @param array<string, mixed> $payload
     */
    private function matchItem(string $form, array $payload): ?int
    {
        if (!$this->context->forms()->has($form)) {
            return null;
        }
        $definition = $this->context->forms()->get($form);
        $field = $definition->itemField;
        $type = $definition->itemType;
        if ($field === null || $type === null || !isset($payload[$field]) || !is_string($payload[$field])) {
            return null;
        }
        $slug = trim($payload[$field]);
        if ($slug === '' || !$this->context->schemas()->has($type)) {
            return null;
        }

        return $this->context->repository()->findBySlug($type, $slug)?->id;
    }

    /** @return array<string, mixed> */
    private static function hydrate(array $row): array
    {
        $payload = json_decode((string) $row['payload'], true);

        return [
            'id' => (int) $row['id'],
            'remoteId' => $row['remote_id'] !== null ? (string) $row['remote_id'] : null,
            'form' => (string) $row['form'],
            'itemId' => $row['item_id'] !== null ? (int) $row['item_id'] : null,
            'payload' => is_array($payload) ? $payload : [],
            'status' => (string) $row['status'],
            'note' => (string) $row['note'],
            'receivedAt' => (string) $row['received_at'],
            'handledAt' => $row['handled_at'] !== null ? (string) $row['handled_at'] : null,
        ];
    }
}
