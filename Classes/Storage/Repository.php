<?php

namespace Neuedaten\FreezedDesk\Storage;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\ApprovalException;
use Neuedaten\FreezedDesk\Exception\ConflictException;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\Slugger;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * Reads and writes records. Every write normalises and validates the
 * fields against the type's schema, keeps a revision of the previous state
 * and rebuilds the relation and media indexes of the record.
 */
final class Repository
{
    /** @var array<int, Item|null> */
    private array $cache = [];

    /** @var string[] */
    private array $notices = [];

    private bool $lastRejectionsApproval = false;

    public function __construct(private readonly DeskContext $context)
    {
    }

    // ----------------------------------------------------------- read ---

    public function find(string $type): Query
    {
        return new Query($this->context, $type);
    }

    public function get(int $id): ?Item
    {
        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }
        $row = $this->context->database()->fetchOne('SELECT * FROM items WHERE id = :id', ['id' => $id]);

        return $this->cache[$id] = $row === null ? null : Item::fromRow($row);
    }

    public function require(int $id): Item
    {
        return $this->get($id) ?? throw new NotFoundException('Record #' . $id . ' does not exist.');
    }

    public function findBySlug(string $type, string $slug): ?Item
    {
        $row = $this->context->database()->fetchOne('SELECT * FROM items WHERE type = :type AND slug = :slug', ['type' => $type, 'slug' => $slug]);

        return $row === null ? null : $this->cache[(int) $row['id']] = Item::fromRow($row);
    }

    /**
     * The one record of a single type, whatever its status.
     */
    public function findSingle(string $type): ?Item
    {
        return $this->find($type)->anyStatus()->first();
    }

    /**
     * @return array<string, int> status => count for one type.
     */
    public function counts(string $type): array
    {
        $counts = array_fill_keys(Status::values(), 0);
        foreach ($this->context->database()->fetchAll('SELECT status, COUNT(*) AS n FROM items WHERE type = :type GROUP BY status', ['type' => $type]) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Records that reference the given record through a relation field.
     *
     * @return array<int, array{item: Item, field: string}>
     */
    public function referencing(int $id): array
    {
        $rows = $this->context->database()->fetchAll(
            'SELECT i.*, r.field AS via FROM relations r JOIN items i ON i.id = r.from_id WHERE r.to_id = :id ORDER BY i.type, i.title COLLATE NOCASE',
            ['id' => $id]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['item' => Item::fromRow($row), 'field' => (string) $row['via']];
        }

        return $result;
    }

    /**
     * The newest change of a type, for `freezed watch`: null while the type
     * has no records.
     */
    public function version(string $type): ?string
    {
        $row = $this->context->database()->fetchOne(
            'SELECT MAX(updated_at) AS updated, COUNT(*) AS n FROM items WHERE type = :type',
            ['type' => $type]
        );
        if ($row === null || (int) $row['n'] === 0) {
            return null;
        }
        $media = $this->context->database()->fetchValue('SELECT MAX(updated_at) FROM media');

        return $row['updated'] . ':' . $row['n'] . ':' . ($media ?? '');
    }

    /** Records changed most recently, across types. @return Item[] */
    public function recent(int $limit = 10): array
    {
        return array_map(Item::fromRow(...), $this->context->database()->fetchAll(
            'SELECT * FROM items ORDER BY updated_at DESC LIMIT ' . (int) $limit
        ));
    }

    // ---------------------------------------------------------- write ---

    /**
     * Create or update a record.
     *
     * $input may hold "slug", "variant", "status", "sort" and "fields"
     * (raw field values keyed by name). Keys that are missing keep their
     * current value (on update) or take their default (on create): a form
     * therefore sends every field, a seed file only what it knows.
     *
     * The actor of the context (A3) is recorded with the change. For a type
     * with approval: 'ui', only the editor (the UI) may publish: from the
     * CLI, publishing a draft is refused, and a change to the content of a
     * published record sends it back to draft (system fields excepted). The
     * schema's guard always runs, its validate callback when the record is
     * or stays published (A5).
     *
     * @param array<string, mixed> $input
     * @param array<string, string|null>|null $timestamps createdAt / updatedAt / publishedAt to keep (import).
     * @param bool $deferRelations Skip the required check on relation fields (first pass of an import).
     * @param bool $validateFields False to skip field validation (a status change to draft or archived).
     * @param int|null $ifRevision Save only when the record is still at this revision (A4).
     * @throws ValidationException
     * @throws ApprovalException When the actor may not publish this type.
     * @throws ConflictException When $ifRevision is outdated.
     */
    public function save(string $type, array $input, ?int $id = null, string $note = '', ?array $timestamps = null, bool $deferRelations = false, bool $validateFields = true, ?int $ifRevision = null): Item
    {
        if ($this->context->readOnly) {
            throw new DeskException('The desk database is open read-only.');
        }

        $this->notices = [];
        $actor = $this->context->actor();
        $schema = $this->context->schemas()->get($type);
        $existing = $id === null ? null : $this->require($id);
        if ($existing !== null && $existing->type !== $type) {
            throw new DeskException(sprintf('Record #%d is of type "%s", not "%s".', $id, $existing->type, $type));
        }
        if ($ifRevision !== null && $existing !== null && $existing->revision !== $ifRevision) {
            throw new ConflictException($existing, $ifRevision);
        }

        $rawFields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
        $errors = [];

        // Fields: normalise what was sent, keep or default the rest. System
        // fields are never taken from the form.
        $data = [];
        foreach ($schema->fields as $name => $field) {
            $fieldType = $this->context->fieldTypes()->get($field->type);
            $accepted = array_key_exists($name, $rawFields) && !$field->readonly && !($field->system && $actor->isHuman());
            if ($accepted) {
                $data[$name] = $fieldType->normalize($rawFields[$name], $field, $this->context);
            } elseif ($existing !== null && array_key_exists($name, $existing->data)) {
                $data[$name] = $existing->data[$name];
            } else {
                $data[$name] = $fieldType->defaultValue($field, $this->context);
            }

            if (!$validateFields) {
                continue;
            }
            if ($field->required && $fieldType->isEmpty($data[$name]) && !($deferRelations && $field->type === 'relation')) {
                $errors[$name] = $this->context->t('validation.required');
                continue;
            }
            $fieldErrors = $fieldType->validate($data[$name], $field, $this->context);
            if ($fieldErrors !== []) {
                $errors[$name] = implode(' ', $fieldErrors);
            }
        }

        // Title, from the title field.
        $title = $schema->titleField !== null ? trim((string) $this->scalar($data[$schema->titleField] ?? null)) : $schema->labelSingular;

        // Variant.
        $variant = isset($input['variant']) && is_string($input['variant']) && $input['variant'] !== ''
            ? $input['variant']
            : ($existing?->variant ?? $schema->defaultVariant());
        if ($schema->variant($variant) === null) {
            $errors['variant'] = $this->context->t('validation.variant', ['variant' => $variant]);
        } elseif ($schema->built) {
            $template = $schema->variant($variant)['template'];
            $missing = $this->missingTemplate($type, $template);
            if ($missing !== null) {
                $errors['variant'] = $this->context->t('validation.template', ['file' => $missing]);
            }
        }

        // Status.
        $status = array_key_exists('status', $input)
            ? Status::fromInput($input['status'], $existing?->status ?? Status::Draft)
            : ($existing?->status ?? Status::Draft);

        // Slug: as given, else from slugFrom / title; unique per type.
        $slug = isset($input['slug']) && is_string($input['slug']) ? trim($input['slug'], " \t\n\r/") : '';
        if ($slug === '') {
            $slug = $existing?->slug ?? '';
        }
        if ($slug === '') {
            $source = $schema->slugFrom !== null ? (string) $this->scalar($data[$schema->slugFrom] ?? null) : $title;
            $slug = $schema->single ? $type : Slugger::slugify($source !== '' ? $source : $title);
            $slug = $this->uniqueSlug($type, $slug !== '' ? $slug : 'record', $existing?->id);
        }
        if (!Slugger::isValid($slug)) {
            $errors['slug'] = $this->context->t('validation.slug');
        } else {
            $other = $this->findBySlug($type, $slug);
            if ($other !== null && $other->id !== $existing?->id) {
                $errors['slug'] = $this->context->t('validation.slugTaken', ['slug' => $slug]);
            }
        }

        // Approval (A3.3, A3.4): only a person in the UI publishes.
        if ($schema->needsUiApproval() && !$actor->isHuman() && $actor !== Actor::Import && $status === Status::Published) {
            if ($existing?->status !== Status::Published) {
                throw new ApprovalException($this->context->t('approval.refused', ['type' => $schema->labelSingular, 'slug' => $slug]));
            }
            if ($this->contentChanged($schema, $existing, $data, $slug, $variant)) {
                $status = Status::Draft;
                $this->notices[] = $this->context->t('approval.reset');
            }
        }

        // The schema's own rules (A5): the guard always, validate when the
        // record is to be published or stays published.
        if ($validateFields) {
            $validation = $this->context->validation();
            $errors += $validation->run($schema->guardCallback, $schema, $data, $existing, $status === Status::Published, $actor, $slug);
            if ($status === Status::Published) {
                $errors += $validation->run($schema->validateCallback, $schema, $data, $existing, true, $actor, $slug);
            }
        }

        if ($schema->single && $existing === null) {
            $other = $this->findSingle($type);
            if ($other !== null) {
                throw new DeskException(sprintf('Type "%s" is single and already has its record (#%d).', $type, $other->id));
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $sort = array_key_exists('sort', $input) && is_numeric($input['sort']) ? (int) $input['sort'] : ($existing?->sort ?? 0);
        $now = Database::now();
        $updatedAt = $timestamps['updatedAt'] ?? $now;
        $createdAt = $timestamps['createdAt'] ?? $existing?->createdAt ?? $now;
        $publishedAt = array_key_exists('publishedAt', $timestamps ?? []) ? $timestamps['publishedAt'] : $existing?->publishedAt;
        if ($status === Status::Published && $publishedAt === null) {
            $publishedAt = $now;
        }

        $search = $this->searchText($schema, $data, $title);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $database = $this->context->database();

        return $database->transaction(function () use ($database, $existing, $type, $slug, $variant, $status, $title, $sort, $json, $search, $createdAt, $updatedAt, $publishedAt, $note, $schema, $data, $actor): Item {
            if ($existing !== null) {
                $this->storeRevision($existing, $note);
                $database->execute(
                    'UPDATE items SET slug = :slug, variant = :variant, status = :status, title = :title, sort = :sort, data = :data, search = :search, created_at = :created, updated_at = :updated, published_at = :published, revision = :revision, updated_by = :actor WHERE id = :id',
                    [
                        'slug' => $slug, 'variant' => $variant, 'status' => $status->value, 'title' => $title, 'sort' => $sort,
                        'data' => $json, 'search' => $search, 'created' => $createdAt, 'updated' => $updatedAt, 'published' => $publishedAt,
                        'revision' => $existing->revision + 1, 'actor' => $actor->value, 'id' => $existing->id,
                    ]
                );
                $id = $existing->id;
            } else {
                $database->execute(
                    'INSERT INTO items (type, slug, variant, status, title, sort, data, search, created_at, updated_at, published_at, revision, updated_by) VALUES (:type, :slug, :variant, :status, :title, :sort, :data, :search, :created, :updated, :published, 1, :actor)',
                    [
                        'type' => $type, 'slug' => $slug, 'variant' => $variant, 'status' => $status->value, 'title' => $title, 'sort' => $sort,
                        'data' => $json, 'search' => $search, 'created' => $createdAt, 'updated' => $updatedAt, 'published' => $publishedAt,
                        'actor' => $actor->value,
                    ]
                );
                $id = $database->lastInsertId();
            }

            $this->reindex($id, $schema, $data);
            unset($this->cache[$id]);

            return $this->require($id);
        });
    }

    /**
     * Write fields with system: true -- rendered assets, outbox results --
     * without the checks meant for editorial changes: an approved record
     * stays approved (A3.4) and a failing validate callback does not stop
     * the machine from recording what happened. Recorded as "cli" (B2.6),
     * also when called from the UI process.
     *
     * @param array<string, mixed> $fields field => raw value
     */
    public function saveSystemFields(int $id, array $fields, string $note = 'system'): Item
    {
        $item = $this->require($id);
        $schema = $this->context->schemas()->get($item->type);
        foreach (array_keys($fields) as $name) {
            if (!($schema->field((string) $name)?->system ?? false)) {
                throw new DeskException(sprintf('Field "%s" of type "%s" is not a system field; saveSystemFields() only writes those.', $name, $item->type));
            }
        }
        $actor = $this->context->actor();
        if ($actor->isHuman()) {
            $this->context->actAs(Actor::Cli);
        }
        try {
            return $this->save($item->type, ['fields' => $fields], $item->id, $note, validateFields: false);
        } finally {
            $this->context->actAs($actor);
        }
    }

    /**
     * Messages of the last save() that did not stop it, e.g. "sent back to
     * draft, approve again" (A3.4).
     *
     * @return string[]
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * Change the status of records without touching their fields. Publishing
     * validates the record (a page must not be built from a broken record);
     * setting a record to draft or archived always works. Every record is
     * tried; the ones that could not be changed are listed with the reason
     * (A5.5).
     *
     * @param int[] $ids
     * @return array{changed: int[], rejected: array<int, string>} Ids changed, id => reason.
     */
    public function changeStatus(array $ids, Status $status): array
    {
        $changed = [];
        $rejected = [];
        $this->lastRejectionsApproval = true;
        foreach ($ids as $id) {
            $item = $this->get((int) $id);
            if ($item === null || $item->status === $status) {
                continue;
            }
            try {
                $this->save($item->type, ['status' => $status->value], $item->id, 'status', validateFields: $status === Status::Published);
                $changed[] = $item->id;
            } catch (ValidationException | ApprovalException $exception) {
                $rejected[$item->id] = $item->title . ': ' . $exception->getMessage();
                $this->lastRejectionsApproval = $this->lastRejectionsApproval && $exception instanceof ApprovalException;
            }
        }

        return ['changed' => $changed, 'rejected' => $rejected];
    }

    /**
     * changeStatus() for callers that want an exception when a record could
     * not be changed.
     *
     * @param int[] $ids
     * @throws ValidationException When a record cannot be published.
     * @throws ApprovalException When publishing is reserved for the UI.
     */
    public function setStatus(array $ids, Status $status): int
    {
        $result = $this->changeStatus($ids, $status);
        if ($result['rejected'] !== []) {
            $message = implode(' ', $result['rejected']);
            if ($this->lastRejectionsApproval) {
                throw new ApprovalException($message);
            }
            throw new ValidationException(['_' => $message]);
        }

        return count($result['changed']);
    }

    /**
     * A person opened the record in the UI: its current revision counts as
     * seen (the filter "from the agent, not yet seen", A3.5). Changes
     * nothing else, not even updated_at.
     */
    public function markSeen(int $id): void
    {
        if ($this->context->readOnly) {
            return;
        }
        $this->context->database()->execute('UPDATE items SET seen_revision = revision WHERE id = :id AND (seen_revision IS NULL OR seen_revision < revision)', ['id' => $id]);
        unset($this->cache[$id]);
    }

    public function delete(int $id): void
    {
        if ($this->context->readOnly) {
            throw new DeskException('The desk database is open read-only.');
        }
        $database = $this->context->database();
        $database->transaction(function () use ($database, $id): void {
            $database->execute('DELETE FROM relations WHERE from_id = :id OR to_id = :id', ['id' => $id]);
            $database->execute('DELETE FROM media_usage WHERE item_id = :id', ['id' => $id]);
            $database->execute('DELETE FROM revisions WHERE item_id = :id', ['id' => $id]);
            $database->execute('UPDATE inbox SET item_id = NULL WHERE item_id = :id', ['id' => $id]);
            $database->execute('DELETE FROM items WHERE id = :id', ['id' => $id]);
        });
        unset($this->cache[$id]);
    }

    /**
     * Re-order records of a type: the given ids get sort 0, 1, 2, …
     *
     * @param int[] $ids
     */
    public function reorder(array $ids): void
    {
        $database = $this->context->database();
        $database->transaction(function () use ($database, $ids): void {
            foreach (array_values($ids) as $position => $id) {
                $database->execute('UPDATE items SET sort = :sort WHERE id = :id', ['sort' => $position, 'id' => (int) $id]);
                unset($this->cache[(int) $id]);
            }
        });
    }

    /**
     * Rebuild the relation and media indexes of every record, e.g. after an
     * import. Cheap enough to run on demand.
     */
    public function reindexAll(): int
    {
        $count = 0;
        foreach ($this->context->database()->fetchAll('SELECT * FROM items') as $row) {
            $item = Item::fromRow($row);
            if (!$this->context->schemas()->has($item->type)) {
                continue;
            }
            $schema = $this->context->schemas()->get($item->type);
            $this->context->database()->transaction(function () use ($item, $schema): void {
                $this->reindex($item->id, $schema, $item->data);
                $this->context->database()->execute('UPDATE items SET search = :search WHERE id = :id', [
                    'search' => $this->searchText($schema, $item->data, $item->title),
                    'id' => $item->id,
                ]);
            });
            $count++;
        }
        $this->cache = [];

        return $count;
    }

    // ------------------------------------------------------ revisions ---

    /**
     * Earlier states of a record, newest first. "number" is the revision the
     * state had (the current record is at $item->revision), "actor" who
     * produced it, "savedAt" when; "note" and "createdAt" describe the
     * change that replaced it.
     *
     * @return array<int, array{id: int, number: int|null, actor: string, savedAt: string|null, createdAt: string, note: string, data: array<string, mixed>}>
     */
    public function revisions(int $itemId): array
    {
        $rows = $this->context->database()->fetchAll('SELECT * FROM revisions WHERE item_id = :id ORDER BY id DESC', ['id' => $itemId]);
        $revisions = [];
        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            $revisions[] = [
                'id' => (int) $row['id'],
                'number' => isset($row['number']) ? (int) $row['number'] : null,
                'actor' => (string) ($row['actor'] ?? ''),
                'savedAt' => isset($row['saved_at']) ? (string) $row['saved_at'] : null,
                'createdAt' => (string) $row['created_at'],
                'note' => (string) $row['note'],
                'data' => is_array($data) ? $data : [],
            ];
        }

        return $revisions;
    }

    /** @return array{id: int, number: int|null, actor: string, savedAt: string|null, createdAt: string, note: string, data: array<string, mixed>}|null */
    public function revision(int $itemId, int $revisionId): ?array
    {
        foreach ($this->revisions($itemId) as $revision) {
            if ($revision['id'] === $revisionId) {
                return $revision;
            }
        }

        return null;
    }

    /** The kept state with the given revision number, or null. */
    public function revisionByNumber(int $itemId, int $number): ?array
    {
        foreach ($this->revisions($itemId) as $revision) {
            if ($revision['number'] === $number) {
                return $revision;
            }
        }

        return null;
    }

    /**
     * Bring back the fields of an earlier state. From the CLI, a type with
     * approval: 'ui' keeps its current status (and falls back to draft when
     * the content changes), so a restore cannot publish.
     */
    public function restoreRevision(int $itemId, int $revisionId, ?int $ifRevision = null): Item
    {
        $item = $this->require($itemId);
        $revision = $this->revision($itemId, $revisionId) ?? throw new NotFoundException('Revision #' . $revisionId . ' does not exist.');
        $snapshot = $revision['data'];
        $schema = $this->context->schemas()->get($item->type);
        $status = $snapshot['status'] ?? $item->status->value;
        if ($schema->needsUiApproval() && !$this->context->actor()->isHuman() && $status === Status::Published->value && !$item->isPublished()) {
            $status = $item->status->value;
        }

        return $this->save($item->type, [
            'slug' => $snapshot['slug'] ?? $item->slug,
            'variant' => $snapshot['variant'] ?? $item->variant,
            'status' => $status,
            'sort' => $snapshot['sort'] ?? $item->sort,
            'fields' => $snapshot['data'] ?? [],
        ], $item->id, 'restore:' . ($revision['number'] ?? $revisionId), ifRevision: $ifRevision);
    }

    // ------------------------------------------------------- settings ---

    public function setting(string $key, ?string $default = null): ?string
    {
        $value = $this->context->database()->fetchValue('SELECT value FROM settings WHERE key = :key', ['key' => $key]);

        return $value === null ? $default : (string) $value;
    }

    public function setSetting(string $key, string $value): void
    {
        $this->context->database()->execute(
            'INSERT INTO settings (key, value) VALUES (:key, :value) ON CONFLICT (key) DO UPDATE SET value = excluded.value',
            ['key' => $key, 'value' => $value]
        );
    }

    // ------------------------------------------------------- internal ---

    private function storeRevision(Item $item, string $note): void
    {
        $keep = $this->context->config->revisions();
        if ($keep <= 0) {
            return;
        }
        $database = $this->context->database();
        $database->execute(
            'INSERT INTO revisions (item_id, data, created_at, note, number, actor, saved_at) VALUES (:item, :data, :created, :note, :number, :actor, :saved)',
            [
                'item' => $item->id,
                'data' => json_encode($item->snapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created' => Database::now(),
                'note' => $note,
                'number' => $item->revision,
                'actor' => $item->updatedBy,
                'saved' => $item->updatedAt,
            ]
        );
        $database->execute(
            'DELETE FROM revisions WHERE item_id = :item AND id NOT IN (SELECT id FROM revisions WHERE item_id = :item2 ORDER BY id DESC LIMIT :keep)',
            ['item' => $item->id, 'item2' => $item->id, 'keep' => $keep]
        );
    }

    /**
     * Did a change touch the content of a record: any field but the system
     * fields, the slug or the variant?
     *
     * @param array<string, mixed> $data
     */
    private function contentChanged(TypeSchema $schema, Item $existing, array $data, string $slug, string $variant): bool
    {
        if ($slug !== $existing->slug || $variant !== $existing->variant) {
            return true;
        }
        foreach ($schema->fields as $name => $field) {
            if ($field->system) {
                continue;
            }
            if (json_encode($data[$name] ?? null) !== json_encode($existing->data[$name] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private function reindex(int $id, TypeSchema $schema, array $data): void
    {
        $database = $this->context->database();
        $database->execute('DELETE FROM relations WHERE from_id = :id', ['id' => $id]);
        $database->execute('DELETE FROM media_usage WHERE item_id = :id', ['id' => $id]);

        foreach ($this->collectReferences($schema->fields, $data) as [$field, $kind, $targetId, $sort]) {
            if ($kind === 'relation') {
                $database->execute(
                    'INSERT OR IGNORE INTO relations (from_id, to_id, field, sort) VALUES (:from, :to, :field, :sort)',
                    ['from' => $id, 'to' => $targetId, 'field' => $field, 'sort' => $sort]
                );
            } else {
                $database->execute(
                    'INSERT OR IGNORE INTO media_usage (item_id, media_id, field) VALUES (:item, :media, :field)',
                    ['item' => $id, 'media' => $targetId, 'field' => $field]
                );
            }
        }
    }

    /**
     * Every relation target and media id inside the data, including those
     * inside groups and lists.
     *
     * @param array<string, FieldDefinition> $fields
     * @param array<string, mixed> $data
     * @return \Generator<int, array{0: string, 1: string, 2: int, 3: int}>
     */
    private function collectReferences(array $fields, array $data, string $prefix = ''): \Generator
    {
        foreach ($fields as $name => $field) {
            $value = $data[$name] ?? null;
            $path = $prefix . $name;

            switch ($field->type) {
                case 'relation':
                    foreach (is_array($value) ? $value : [] as $sort => $pair) {
                        if (isset($pair['id'])) {
                            yield [$path, 'relation', (int) $pair['id'], (int) $sort];
                        }
                    }
                    break;
                case 'image':
                    if (is_int($value)) {
                        yield [$path, 'media', $value, 0];
                    }
                    break;
                case 'images':
                case 'files':
                    foreach (is_array($value) ? $value : [] as $sort => $mediaId) {
                        if (is_int($mediaId)) {
                            yield [$path, 'media', $mediaId, (int) $sort];
                        }
                    }
                    break;
                case 'group':
                    yield from $this->collectReferences($field->fields ?? [], is_array($value) ? $value : [], $path . '.');
                    break;
                case 'list':
                    foreach (is_array($value) ? $value : [] as $row) {
                        yield from $this->collectReferences([$name => $field->of], [$name => $row], $prefix);
                    }
                    break;
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function searchText(TypeSchema $schema, array $data, string $title): string
    {
        $words = [$title];
        foreach ($schema->fields as $name => $field) {
            $type = $this->context->fieldTypes()->get($field->type);
            $words[] = $type->searchText($data[$name] ?? null, $field, $this->context);
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($words))) ?? ''));
    }

    private function uniqueSlug(string $type, string $slug, ?int $ignoreId): string
    {
        $candidate = $slug;
        $n = 2;
        while (($other = $this->findBySlug($type, $candidate)) !== null && $other->id !== $ignoreId) {
            $candidate = $slug . '-' . $n++;
        }

        return $candidate;
    }

    /**
     * The template file a built type's variant needs, when it is missing.
     */
    private function missingTemplate(string $type, string $template): ?string
    {
        $contentPath = \Neuedaten\Freezed\Services\ProjectPathsService::getInstance()->getLexicalPath('contentPath');
        $file = $contentPath . '/' . $type . '/' . $template . '.html';
        if (is_file($file)) {
            return null;
        }
        $projectRoot = $this->context->config->projectRoot;

        return str_starts_with($file, $projectRoot) ? ltrim(substr($file, strlen($projectRoot)), '/') : $file;
    }

    private function scalar(mixed $value): string|int|float
    {
        return is_scalar($value) ? $value : '';
    }
}
