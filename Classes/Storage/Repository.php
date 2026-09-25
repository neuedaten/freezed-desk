<?php

namespace Neuedaten\FreezedDesk\Storage;

use Neuedaten\FreezedDesk\DeskContext;
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
     * @param array<string, mixed> $input
     * @param array<string, string|null>|null $timestamps createdAt / updatedAt / publishedAt to keep (import).
     * @param bool $deferRelations Skip the required check on relation fields (first pass of an import).
     * @param bool $validateFields False to skip field validation (a status change to draft or archived).
     * @throws ValidationException
     */
    public function save(string $type, array $input, ?int $id = null, string $note = '', ?array $timestamps = null, bool $deferRelations = false, bool $validateFields = true): Item
    {
        if ($this->context->readOnly) {
            throw new DeskException('The desk database is open read-only.');
        }

        $schema = $this->context->schemas()->get($type);
        $existing = $id === null ? null : $this->require($id);
        if ($existing !== null && $existing->type !== $type) {
            throw new DeskException(sprintf('Record #%d is of type "%s", not "%s".', $id, $existing->type, $type));
        }

        $rawFields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
        $errors = [];

        // Fields: normalise what was sent, keep or default the rest.
        $data = [];
        foreach ($schema->fields as $name => $field) {
            $fieldType = $this->context->fieldTypes()->get($field->type);
            if (array_key_exists($name, $rawFields) && !$field->readonly) {
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

        return $database->transaction(function () use ($database, $existing, $type, $slug, $variant, $status, $title, $sort, $json, $search, $createdAt, $updatedAt, $publishedAt, $note, $schema, $data): Item {
            if ($existing !== null) {
                $this->storeRevision($existing, $note);
                $database->execute(
                    'UPDATE items SET slug = :slug, variant = :variant, status = :status, title = :title, sort = :sort, data = :data, search = :search, created_at = :created, updated_at = :updated, published_at = :published WHERE id = :id',
                    [
                        'slug' => $slug, 'variant' => $variant, 'status' => $status->value, 'title' => $title, 'sort' => $sort,
                        'data' => $json, 'search' => $search, 'created' => $createdAt, 'updated' => $updatedAt, 'published' => $publishedAt, 'id' => $existing->id,
                    ]
                );
                $id = $existing->id;
            } else {
                $database->execute(
                    'INSERT INTO items (type, slug, variant, status, title, sort, data, search, created_at, updated_at, published_at) VALUES (:type, :slug, :variant, :status, :title, :sort, :data, :search, :created, :updated, :published)',
                    [
                        'type' => $type, 'slug' => $slug, 'variant' => $variant, 'status' => $status->value, 'title' => $title, 'sort' => $sort,
                        'data' => $json, 'search' => $search, 'created' => $createdAt, 'updated' => $updatedAt, 'published' => $publishedAt,
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
     * Change the status of records without touching their fields. Publishing
     * validates the record (a page must not be built from a broken record);
     * setting a record to draft or archived always works.
     *
     * @param int[] $ids
     * @throws ValidationException When a record cannot be published.
     */
    public function setStatus(array $ids, Status $status): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $item = $this->get((int) $id);
            if ($item === null || $item->status === $status) {
                continue;
            }
            try {
                $this->save($item->type, ['status' => $status->value], $item->id, 'status', validateFields: $status === Status::Published);
            } catch (ValidationException $exception) {
                throw new ValidationException(['_' => $item->title . ': ' . $exception->getMessage()]);
            }
            $count++;
        }

        return $count;
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
     * @return array<int, array{id: int, createdAt: string, note: string, data: array<string, mixed>}>
     */
    public function revisions(int $itemId): array
    {
        $rows = $this->context->database()->fetchAll('SELECT * FROM revisions WHERE item_id = :id ORDER BY id DESC', ['id' => $itemId]);
        $revisions = [];
        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            $revisions[] = [
                'id' => (int) $row['id'],
                'createdAt' => (string) $row['created_at'],
                'note' => (string) $row['note'],
                'data' => is_array($data) ? $data : [],
            ];
        }

        return $revisions;
    }

    /** @return array{id: int, createdAt: string, note: string, data: array<string, mixed>}|null */
    public function revision(int $itemId, int $revisionId): ?array
    {
        foreach ($this->revisions($itemId) as $revision) {
            if ($revision['id'] === $revisionId) {
                return $revision;
            }
        }

        return null;
    }

    public function restoreRevision(int $itemId, int $revisionId): Item
    {
        $item = $this->require($itemId);
        $revision = $this->revision($itemId, $revisionId) ?? throw new NotFoundException('Revision #' . $revisionId . ' does not exist.');
        $snapshot = $revision['data'];

        return $this->save($item->type, [
            'slug' => $snapshot['slug'] ?? $item->slug,
            'variant' => $snapshot['variant'] ?? $item->variant,
            'status' => $snapshot['status'] ?? $item->status->value,
            'sort' => $snapshot['sort'] ?? $item->sort,
            'fields' => $snapshot['data'] ?? [],
        ], $item->id, 'restore:' . $revisionId);
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
            'INSERT INTO revisions (item_id, data, created_at, note) VALUES (:item, :data, :created, :note)',
            [
                'item' => $item->id,
                'data' => json_encode($item->snapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created' => Database::now(),
                'note' => $note,
            ]
        );
        $database->execute(
            'DELETE FROM revisions WHERE item_id = :item AND id NOT IN (SELECT id FROM revisions WHERE item_id = :item2 ORDER BY id DESC LIMIT :keep)',
            ['item' => $item->id, 'item2' => $item->id, 'keep' => $keep]
        );
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
