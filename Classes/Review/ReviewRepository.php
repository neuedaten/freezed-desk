<?php

namespace Neuedaten\FreezedDesk\Review;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\ApprovalException;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Export\Portable;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Database;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\Status;

/**
 * Reviews (docs/review.md): a person goes through records one by one and
 * decides -- approve, resubmit, defer, block -- with notes per field and a
 * general comment. Every decision is kept with its date, the reviewer and
 * the state that was reviewed, so the history can be referred to later.
 *
 * The notes are "points": a field (or none for the general comment), quick
 * tags and a text. A point stays open until someone marks it done; a point
 * with nothing but the tag "ok" only confirms and is never open.
 *
 * Whether a record needs a review is decided by its content hash, not its
 * revision: publishing or a system field (rendered files) changes the
 * revision, not what a reviewer looked at.
 */
final class ReviewRepository
{
    public const DECISIONS = ['approve', 'resubmit', 'defer', 'block'];

    /** Quick notes offered under every field, unless desk.review.tags says otherwise. */
    public const TAGS = ['ok', 'unsure', 'source', 'remove', 'more', 'rephrase'];

    /** The tag that confirms a field: alone and without text, the point is never open. */
    public const CONFIRM_TAG = 'ok';

    /**
     * Queues of the review screen. "open": never reviewed or changed since
     * the last review; the decisions: the last review decided so; "points":
     * open points left.
     */
    public const QUEUES = ['open', 'new', 'changed', 'resubmit', 'defer', 'block', 'approve', 'points', 'all'];

    /** @var array<int, array<string, mixed>>|null item id => latest review (without snapshot) */
    private ?array $latest = null;

    /** @var array<int, int>|null item id => open points */
    private ?array $openCounts = null;

    public function __construct(private readonly DeskContext $context)
    {
    }

    // ---------------------------------------------------------- setup ---

    /** @return array<string, string> Quick notes, key => label. */
    public function tags(): array
    {
        $configured = $this->context->config->get('review.tags');
        if (is_array($configured) && $configured !== []) {
            $tags = [];
            foreach ($configured as $key => $label) {
                $tags[(string) $key] = (string) $label;
            }

            return $tags;
        }
        $tags = [];
        foreach (self::TAGS as $key) {
            $tags[$key] = $this->context->t('review.tag.' . $key);
        }

        return $tags;
    }

    /**
     * The types offered for review: desk.review.types in its order, else
     * every type that is not single.
     *
     * @return array<string, TypeSchema>
     */
    public function types(): array
    {
        $schemas = $this->context->schemas()->all();
        $configured = $this->context->config->get('review.types');
        $slugs = is_array($configured) ? array_map('strval', $configured) : array_keys($schemas);
        $types = [];
        foreach ($slugs as $slug) {
            if (isset($schemas[$slug]) && !$schemas[$slug]->single) {
                $types[$slug] = $schemas[$slug];
            }
        }

        return $types;
    }

    public function reviewable(string $type): bool
    {
        return isset($this->types()[$type]);
    }

    /**
     * The content a reviewer sees: slug, variant and every field but the
     * system fields. Status and revision are left out on purpose.
     */
    public function hash(Item $item): string
    {
        $data = $item->data;
        if ($this->context->schemas()->has($item->type)) {
            foreach (array_keys($this->context->schemas()->get($item->type)->systemFields()) as $name) {
                unset($data[$name]);
            }
        }
        ksort($data);

        return substr(hash('sha256', (string) json_encode([$item->slug, $item->variant, $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    // --------------------------------------------------------- submit ---

    /**
     * Record a person's review. The decision moves the status along:
     * "approve" publishes a draft (with the usual checks; when they fail the
     * review is kept and the reason returned), "block" takes a published
     * record back to draft and keeps it from being published until a later
     * review decides otherwise.
     *
     * @param array<int, array{field?: string|null, tags?: string[], text?: string}> $points
     * @return array{review: array<string, mixed>, statusChanged: bool, rejected: string|null}
     * @throws ApprovalException When not called by a person in the UI.
     */
    public function submit(Item $item, string $decision, array $points, string $reviewer = ''): array
    {
        if ($this->context->readOnly) {
            throw new DeskException('The desk database is open read-only.');
        }
        if (!$this->context->actor()->isHuman()) {
            throw new ApprovalException($this->context->t('review.uiOnly'));
        }
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new DeskException(sprintf('Unknown review decision "%s" (%s).', $decision, implode(', ', self::DECISIONS)));
        }
        if (!$this->reviewable($item->type)) {
            throw new DeskException(sprintf('Type "%s" is not offered for review (desk.review.types).', $item->type));
        }
        $schema = $this->context->schemas()->get($item->type);
        $points = $this->cleanPoints($schema, $points);
        $now = Database::now();
        $database = $this->context->database();

        $reviewId = $database->transaction(function () use ($database, $item, $decision, $points, $reviewer, $now): int {
            $database->execute(
                'INSERT INTO reviews (uid, item_id, revision, hash, decision, reviewer, snapshot, created_at) VALUES (:uid, :item, :revision, :hash, :decision, :reviewer, :snapshot, :created)',
                [
                    'uid' => bin2hex(random_bytes(8)),
                    'item' => $item->id,
                    'revision' => $item->revision,
                    'hash' => $this->hash($item),
                    'decision' => $decision,
                    'reviewer' => trim($reviewer),
                    'snapshot' => json_encode($item->snapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created' => $now,
                ]
            );
            $reviewId = $database->lastInsertId();
            foreach ($points as $point) {
                $confirms = $point['tags'] === [self::CONFIRM_TAG] && $point['text'] === '';
                $database->execute(
                    'INSERT INTO review_points (review_id, field, tags, text, done_at) VALUES (:review, :field, :tags, :text, :done)',
                    [
                        'review' => $reviewId,
                        'field' => $point['field'],
                        'tags' => json_encode($point['tags']),
                        'text' => $point['text'],
                        'done' => $confirms ? $now : null,
                    ]
                );
            }

            return $reviewId;
        });
        $this->reset();

        $statusChanged = false;
        $rejected = null;
        $target = match (true) {
            $decision === 'approve' && $item->status === Status::Draft => Status::Published,
            $decision === 'block' && $item->status === Status::Published => Status::Draft,
            default => null,
        };
        if ($target !== null) {
            $result = $this->context->repository()->changeStatus([$item->id], $target);
            $statusChanged = $result['changed'] !== [];
            $rejected = $result['rejected'][$item->id] ?? null;
        }

        return ['review' => $this->require($reviewId), 'statusChanged' => $statusChanged, 'rejected' => $rejected];
    }

    /**
     * Keep the points that say something; fields must exist, unknown tags
     * are dropped.
     *
     * @param array<int, array<string, mixed>> $points
     * @return array<int, array{field: string|null, tags: string[], text: string}>
     */
    private function cleanPoints(TypeSchema $schema, array $points): array
    {
        $known = array_keys($this->tags());
        $clean = [];
        foreach ($points as $point) {
            $field = isset($point['field']) && is_string($point['field']) && $point['field'] !== '' ? $point['field'] : null;
            if ($field !== null && !$schema->hasField($field)) {
                throw new DeskException(sprintf('Type "%s" has no field "%s".', $schema->slug, $field));
            }
            $given = is_array($point['tags'] ?? null) ? array_map('strval', $point['tags']) : [];
            $tags = array_values(array_filter($known, static fn (string $tag): bool => in_array($tag, $given, true)));
            $text = trim(str_replace("\r\n", "\n", (string) ($point['text'] ?? '')));
            if ($tags === [] && $text === '') {
                continue;
            }
            $clean[] = ['field' => $field, 'tags' => $tags, 'text' => $text];
        }

        return $clean;
    }

    // ----------------------------------------------------------- read ---

    /** @return array<string, mixed>|null A review with its points. */
    public function get(int $id, bool $withSnapshot = false): ?array
    {
        $row = $this->context->database()->fetchOne('SELECT * FROM reviews WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row, $this->pointsOf([$id])[$id] ?? [], $withSnapshot);
    }

    /** @return array<string, mixed> */
    public function require(int $id, bool $withSnapshot = false): array
    {
        return $this->get($id, $withSnapshot) ?? throw new NotFoundException('Review #' . $id . ' does not exist.');
    }

    /**
     * The reviews of a record, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forItem(int $itemId, bool $withSnapshot = false): array
    {
        $rows = $this->context->database()->fetchAll('SELECT * FROM reviews WHERE item_id = :item ORDER BY id DESC', ['item' => $itemId]);
        $points = $this->pointsOf(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        return array_map(fn (array $row): array => $this->hydrate($row, $points[(int) $row['id']] ?? [], $withSnapshot), $rows);
    }

    /** @return array<string, mixed>|null The latest review of a record, without points. */
    public function latest(int $itemId): ?array
    {
        return $this->latestByItem()[$itemId] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function point(int $id): ?array
    {
        $row = $this->context->database()->fetchOne('SELECT * FROM review_points WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::hydratePoint($row);
    }

    /**
     * Open points, oldest first, with the review they belong to.
     *
     * @param int[]|null $itemIds Only these records.
     * @return array<int, array<string, mixed>>
     */
    public function openPoints(?array $itemIds = null): array
    {
        $sql = 'SELECT p.*, r.item_id, r.decision, r.reviewer, r.created_at AS reviewed_at, r.revision FROM review_points p JOIN reviews r ON r.id = p.review_id WHERE p.done_at IS NULL';
        $params = [];
        if ($itemIds !== null) {
            if ($itemIds === []) {
                return [];
            }
            $placeholders = [];
            foreach (array_values($itemIds) as $i => $id) {
                $placeholders[] = ':i' . $i;
                $params['i' . $i] = (int) $id;
            }
            $sql .= ' AND r.item_id IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY p.id';

        return array_map(static fn (array $row): array => self::hydratePoint($row) + [
            'itemId' => (int) $row['item_id'],
            'decision' => (string) $row['decision'],
            'reviewer' => (string) $row['reviewer'],
            'reviewedAt' => (string) $row['reviewed_at'],
            'revision' => (int) $row['revision'],
        ], $this->context->database()->fetchAll($sql, $params));
    }

    /** @return array<int, int> item id => open points */
    public function openCounts(): array
    {
        if ($this->openCounts === null) {
            $this->openCounts = [];
            foreach ($this->context->database()->fetchAll('SELECT r.item_id, COUNT(*) AS n FROM review_points p JOIN reviews r ON r.id = p.review_id WHERE p.done_at IS NULL GROUP BY r.item_id') as $row) {
                $this->openCounts[(int) $row['item_id']] = (int) $row['n'];
            }
        }

        return $this->openCounts;
    }

    /** Open points of records that are not archived. */
    public function openTotal(): int
    {
        try {
            return (int) $this->context->database()->fetchValue(
                'SELECT COUNT(*) FROM review_points p JOIN reviews r ON r.id = p.review_id JOIN items i ON i.id = r.item_id WHERE p.done_at IS NULL AND i.status != :archived',
                ['archived' => Status::Archived->value]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The last review blocked the record: it must not be published. */
    public function isBlocked(int $itemId): bool
    {
        $decision = $this->context->database()->fetchValue('SELECT decision FROM reviews WHERE item_id = :item ORDER BY id DESC LIMIT 1', ['item' => $itemId]);

        return $decision === 'block';
    }

    // --------------------------------------------------------- queues ---

    /**
     * Where a record stands: state is "new" (never reviewed), "changed"
     * (content differs from the last review) or the last decision.
     *
     * @return array{state: string, decision: string|null, changed: bool, openPoints: int, review: array<string, mixed>|null}
     */
    public function state(Item $item): array
    {
        $latest = $this->latest($item->id);
        $open = $this->openCounts()[$item->id] ?? 0;
        if ($latest === null) {
            return ['state' => 'new', 'decision' => null, 'changed' => false, 'openPoints' => $open, 'review' => null];
        }
        $changed = $latest['hash'] !== $this->hash($item);

        return ['state' => $changed ? 'changed' : $latest['decision'], 'decision' => $latest['decision'], 'changed' => $changed, 'openPoints' => $open, 'review' => $latest];
    }

    /** @param array{state: string, decision: string|null, changed: bool, openPoints: int} $state */
    public static function inQueue(array $state, string $queue): bool
    {
        return match ($queue) {
            'open' => $state['decision'] === null || $state['changed'],
            'new' => $state['decision'] === null,
            'changed' => $state['changed'],
            'resubmit', 'defer', 'block', 'approve' => $state['decision'] === $queue,
            'points' => $state['openPoints'] > 0,
            'all' => true,
            default => false,
        };
    }

    /**
     * The records of a queue, in the order of the type's list. Archived
     * records are never in a queue.
     *
     * @return Item[]
     */
    public function queue(TypeSchema $schema, string $queue): array
    {
        if (!in_array($queue, self::QUEUES, true)) {
            throw new DeskException(sprintf('Unknown review queue "%s" (%s).', $queue, implode(', ', self::QUEUES)));
        }
        $items = [];
        foreach ($this->context->repository()->find($schema->slug)->notArchived()->ordered()->all() as $item) {
            if (self::inQueue($this->state($item), $queue)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @return array<string, int> queue => number of records */
    public function counts(TypeSchema $schema): array
    {
        $counts = array_fill_keys(self::QUEUES, 0);
        foreach ($this->context->repository()->find($schema->slug)->notArchived()->all() as $item) {
            $state = $this->state($item);
            foreach (self::QUEUES as $queue) {
                if (self::inQueue($state, $queue)) {
                    $counts[$queue]++;
                }
            }
        }

        return $counts;
    }

    // ----------------------------------------------------------- done ---

    /**
     * Mark a point as worked through, by whoever acts (editor, agent, cli),
     * with an optional note on what was done.
     *
     * @return array<string, mixed> The point.
     */
    public function markDone(int $pointId, string $note = ''): array
    {
        $point = $this->point($pointId) ?? throw new NotFoundException('Review point #' . $pointId . ' does not exist.');
        if ($point['open']) {
            $this->context->database()->execute(
                'UPDATE review_points SET done_at = :at, done_by = :by, done_note = :note WHERE id = :id',
                ['at' => Database::now(), 'by' => $this->context->actor()->value, 'note' => trim($note), 'id' => $pointId]
            );
            $this->reset();
        }

        return $this->point($pointId);
    }

    /** @return array<string, mixed> The point, open again. */
    public function reopen(int $pointId): array
    {
        $point = $this->point($pointId) ?? throw new NotFoundException('Review point #' . $pointId . ' does not exist.');
        if (!$point['open'] && !$point['confirms']) {
            $this->context->database()->execute('UPDATE review_points SET done_at = NULL, done_by = \'\', done_note = \'\' WHERE id = :id', ['id' => $pointId]);
            $this->reset();
        }

        return $this->point($pointId);
    }

    /**
     * Mark every open point of a review done.
     *
     * @return int[] Ids of the points marked.
     */
    public function markReviewDone(int $reviewId, string $note = ''): array
    {
        $review = $this->require($reviewId);
        $marked = [];
        foreach ($review['points'] as $point) {
            if ($point['open']) {
                $this->markDone($point['id'], $note);
                $marked[] = $point['id'];
            }
        }

        return $marked;
    }

    // --------------------------------------------------- export/import ---

    /**
     * The reviews of a record for data/export/: portable (relations and
     * media in the reviewed state as {type, slug} / {file}), oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportItem(Item $item): array
    {
        $schema = $this->context->schemas()->has($item->type) ? $this->context->schemas()->get($item->type) : null;
        $portable = new Portable($this->context);
        $reviews = [];
        foreach (array_reverse($this->forItem($item->id, withSnapshot: true)) as $review) {
            $snapshot = $review['snapshot'];
            if ($schema !== null && is_array($snapshot['data'] ?? null) && !($snapshot['portable'] ?? false)) {
                $snapshot['data'] = $portable->fromStored($schema, $snapshot['data']);
                $snapshot['portable'] = true;
            }
            $reviews[] = [
                'uid' => $review['uid'],
                'decision' => $review['decision'],
                'reviewer' => $review['reviewer'],
                'createdAt' => $review['createdAt'],
                'revision' => $review['revision'],
                'hash' => $review['hash'],
                'points' => array_map(static fn (array $p): array => [
                    'field' => $p['field'],
                    'tags' => $p['tags'],
                    'text' => $p['text'],
                    'doneAt' => $p['doneAt'],
                    'doneBy' => $p['doneBy'],
                    'doneNote' => $p['doneNote'],
                ], $review['points']),
                'snapshot' => $snapshot,
            ];
        }

        return $reviews;
    }

    /**
     * Bring exported reviews of a record back; reviews already present (by
     * uid) are left alone.
     *
     * @param array<int, mixed> $reviews
     * @return int Reviews added.
     */
    public function importItem(Item $item, array $reviews): int
    {
        $database = $this->context->database();
        $added = 0;
        foreach ($reviews as $review) {
            if (!is_array($review) || !is_string($review['uid'] ?? null) || !in_array($review['decision'] ?? null, self::DECISIONS, true)) {
                continue;
            }
            if ($database->fetchValue('SELECT id FROM reviews WHERE uid = :uid', ['uid' => $review['uid']]) !== null) {
                continue;
            }
            $database->transaction(function () use ($database, $item, $review): void {
                $database->execute(
                    'INSERT INTO reviews (uid, item_id, revision, hash, decision, reviewer, snapshot, created_at) VALUES (:uid, :item, :revision, :hash, :decision, :reviewer, :snapshot, :created)',
                    [
                        'uid' => $review['uid'],
                        'item' => $item->id,
                        'revision' => (int) ($review['revision'] ?? 0),
                        'hash' => (string) ($review['hash'] ?? ''),
                        'decision' => $review['decision'],
                        'reviewer' => (string) ($review['reviewer'] ?? ''),
                        'snapshot' => json_encode(is_array($review['snapshot'] ?? null) ? $review['snapshot'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created' => (string) ($review['createdAt'] ?? Database::now()),
                    ]
                );
                $reviewId = $database->lastInsertId();
                foreach ((array) ($review['points'] ?? []) as $point) {
                    if (!is_array($point)) {
                        continue;
                    }
                    $database->execute(
                        'INSERT INTO review_points (review_id, field, tags, text, done_at, done_by, done_note) VALUES (:review, :field, :tags, :text, :at, :by, :note)',
                        [
                            'review' => $reviewId,
                            'field' => is_string($point['field'] ?? null) ? $point['field'] : null,
                            'tags' => json_encode(array_values(array_map('strval', (array) ($point['tags'] ?? [])))),
                            'text' => (string) ($point['text'] ?? ''),
                            'at' => is_string($point['doneAt'] ?? null) ? $point['doneAt'] : null,
                            'by' => (string) ($point['doneBy'] ?? ''),
                            'note' => (string) ($point['doneNote'] ?? ''),
                        ]
                    );
                }
            });
            $added++;
        }
        $this->reset();

        return $added;
    }

    // ------------------------------------------------------- internal ---

    public function reset(): void
    {
        $this->latest = null;
        $this->openCounts = null;
    }

    /** @return array<int, array<string, mixed>> */
    private function latestByItem(): array
    {
        if ($this->latest === null) {
            $this->latest = [];
            $rows = $this->context->database()->fetchAll(
                'SELECT r.id, r.uid, r.item_id, r.revision, r.hash, r.decision, r.reviewer, r.created_at FROM reviews r JOIN (SELECT item_id, MAX(id) AS id FROM reviews GROUP BY item_id) m ON m.id = r.id'
            );
            foreach ($rows as $row) {
                $this->latest[(int) $row['item_id']] = $this->hydrate($row, null, false);
            }
        }

        return $this->latest;
    }

    /**
     * @param int[] $reviewIds
     * @return array<int, array<int, array<string, mixed>>> review id => points
     */
    private function pointsOf(array $reviewIds): array
    {
        if ($reviewIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($reviewIds) as $i => $id) {
            $placeholders[] = ':r' . $i;
            $params['r' . $i] = $id;
        }
        $points = [];
        foreach ($this->context->database()->fetchAll('SELECT * FROM review_points WHERE review_id IN (' . implode(', ', $placeholders) . ') ORDER BY id', $params) as $row) {
            $points[(int) $row['review_id']][] = self::hydratePoint($row);
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>>|null $points null: not loaded
     * @return array<string, mixed>
     */
    private function hydrate(array $row, ?array $points, bool $withSnapshot): array
    {
        $review = [
            'id' => (int) $row['id'],
            'uid' => (string) $row['uid'],
            'itemId' => (int) $row['item_id'],
            'revision' => (int) $row['revision'],
            'hash' => (string) $row['hash'],
            'decision' => (string) $row['decision'],
            'reviewer' => (string) $row['reviewer'],
            'createdAt' => (string) $row['created_at'],
        ];
        if ($points !== null) {
            $review['points'] = $points;
            $review['openPoints'] = count(array_filter($points, static fn (array $p): bool => $p['open']));
        }
        if ($withSnapshot) {
            $snapshot = json_decode((string) ($row['snapshot'] ?? '{}'), true);
            $review['snapshot'] = is_array($snapshot) ? $snapshot : [];
        }

        return $review;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydratePoint(array $row): array
    {
        $tags = json_decode((string) $row['tags'], true);
        $tags = is_array($tags) ? array_values(array_map('strval', $tags)) : [];
        $text = (string) $row['text'];

        return [
            'id' => (int) $row['id'],
            'reviewId' => (int) $row['review_id'],
            'field' => $row['field'] !== null ? (string) $row['field'] : null,
            'tags' => $tags,
            'text' => $text,
            'open' => $row['done_at'] === null,
            'confirms' => $tags === [self::CONFIRM_TAG] && $text === '',
            'doneAt' => $row['done_at'] !== null ? (string) $row['done_at'] : null,
            'doneBy' => (string) $row['done_by'],
            'doneNote' => (string) $row['done_note'],
        ];
    }
}
