<?php

namespace Neuedaten\FreezedDesk\Storage;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * A query over the records of one type, as used in "variables" callbacks
 * and script sources:
 *
 *     $repo->find('entries')->where('spot', $item['spot'])->whereNot('id', $item['id'])
 *          ->orderByDistance('km', $item['km'])->limit(3)->refs();
 *
 * Published records only, unless anyStatus() / notArchived() say otherwise.
 * Status and search go to SQLite; field filters and sorting run on the
 * decoded records, which is plenty for the sizes a desk holds.
 */
final class Query
{
    /** @var string[]|null Status values to match; null = any. */
    private ?array $statuses = [Status::Published->value];

    /** @var array<int, \Closure(Item): bool> */
    private array $filters = [];

    private ?string $search = null;

    /** @var array<int, array{0: string, 1: string}|\Closure> */
    private array $order = [];

    private ?int $limit = null;

    private int $offset = 0;

    private bool $useSchemaOrder = false;

    public function __construct(
        private readonly DeskContext $context,
        public readonly string $type,
    ) {
    }

    // --------------------------------------------------------- status ---

    public function anyStatus(): self
    {
        $this->statuses = null;

        return $this;
    }

    public function published(): self
    {
        $this->statuses = [Status::Published->value];

        return $this;
    }

    public function notArchived(): self
    {
        $this->statuses = [Status::Draft->value, Status::Published->value];

        return $this;
    }

    public function withStatus(Status|string ...$statuses): self
    {
        $this->statuses = array_map(static fn (Status|string $s): string => $s instanceof Status ? $s->value : $s, $statuses);

        return $this;
    }

    // -------------------------------------------------------- filters ---

    /**
     * Records whose field equals the value. For relation fields, the value
     * may be an id, a {type, id} pair, an exported reference or a list of
     * those; the record matches when it references any of them.
     */
    public function where(string $field, mixed $value): self
    {
        $this->filters[] = fn (Item $item): bool => self::matches($item->value($field), $value);

        return $this;
    }

    public function whereNot(string $field, mixed $value): self
    {
        $this->filters[] = fn (Item $item): bool => !self::matches($item->value($field), $value);

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereIn(string $field, array $values): self
    {
        $this->filters[] = function (Item $item) use ($field, $values): bool {
            foreach ($values as $value) {
                if (self::matches($item->value($field), $value)) {
                    return true;
                }
            }

            return false;
        };

        return $this;
    }

    /**
     * Records with the feature set (or set to the given value) in the
     * "features" field of the type.
     */
    public function whereFeature(string $key, mixed $value = true, string $field = 'features'): self
    {
        $this->filters[] = function (Item $item) use ($key, $value, $field): bool {
            $features = $item->value($field);
            if (!is_array($features) || !array_key_exists($key, $features)) {
                return false;
            }

            return $value === true ? (bool) $features[$key] : $features[$key] == $value;
        };

        return $this;
    }

    /** @param \Closure(Item): bool $filter */
    public function filter(\Closure $filter): self
    {
        $this->filters[] = $filter;

        return $this;
    }

    public function search(?string $text): self
    {
        $text = $text === null ? '' : trim($text);
        $this->search = $text === '' ? null : $text;

        return $this;
    }

    // ---------------------------------------------------------- order ---

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->order[] = [$field, strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC'];

        return $this;
    }

    /** Nearest first, by the absolute difference of a numeric field. */
    public function orderByDistance(string $field, mixed $value): self
    {
        $target = is_numeric($value) ? (float) $value : 0.0;
        $this->order[] = static fn (Item $a, Item $b): int =>
            abs((float) ($a->value($field) ?? 0) - $target) <=> abs((float) ($b->value($field) ?? 0) - $target);

        return $this;
    }

    /** The type's default order (orderBy in its schema). */
    public function ordered(): self
    {
        $this->useSchemaOrder = true;

        return $this;
    }

    public function limit(?int $limit): self
    {
        $this->limit = $limit === null || $limit <= 0 ? null : $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    // -------------------------------------------------------- results ---

    /** @return Item[] */
    public function all(): array
    {
        $items = $this->load();

        foreach ($this->filters as $filter) {
            $items = array_values(array_filter($items, $filter));
        }

        $items = $this->sort($items);

        if ($this->offset > 0 || $this->limit !== null) {
            $items = array_slice($items, $this->offset, $this->limit);
        }

        return $items;
    }

    public function first(): ?Item
    {
        return $this->all()[0] ?? null;
    }

    public function count(): int
    {
        return count($this->all());
    }

    /** @return int[] */
    public function ids(): array
    {
        return array_map(static fn (Item $item): int => $item->id, $this->all());
    }

    /**
     * Exported references {id, type, slug, title, variant, ref} for
     * freezed:link, optionally with extra fields of each record.
     *
     * @param string[] $exportFields
     */
    public function refs(array $exportFields = []): array
    {
        $pairs = array_map(static fn (Item $item): array => $item->pair(), $this->all());

        return $this->context->exporter()->refs($pairs, $exportFields, anyStatus: $this->statuses === null || $this->statuses !== [Status::Published->value]);
    }

    /**
     * The full exported variables of every matching record, as a template
     * would see them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function variables(bool $withCallback = false): array
    {
        $exporter = $this->context->exporter();

        return array_map(fn (Item $item): array => $exporter->export($item, withCallback: $withCallback), $this->all());
    }

    // ------------------------------------------------------- internal ---

    /** @return Item[] */
    private function load(): array
    {
        $sql = 'SELECT * FROM items WHERE type = :type';
        $params = ['type' => $this->type];

        if ($this->statuses !== null) {
            $placeholders = [];
            foreach (array_values($this->statuses) as $i => $status) {
                $placeholders[] = ':status' . $i;
                $params['status' . $i] = $status;
            }
            $sql .= ' AND status IN (' . implode(', ', $placeholders) . ')';
        }

        if ($this->search !== null) {
            $i = 0;
            foreach (preg_split('/\s+/', mb_strtolower($this->search)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $sql .= ' AND search LIKE :search' . $i . ' ESCAPE \'\\\'';
                $params['search' . $i] = '%' . addcslashes($word, '%_\\') . '%';
                $i++;
            }
        }

        $sql .= ' ORDER BY sort ASC, title COLLATE NOCASE ASC, id ASC';

        return array_map(Item::fromRow(...), $this->context->database()->fetchAll($sql, $params));
    }

    /**
     * @param Item[] $items
     * @return Item[]
     */
    private function sort(array $items): array
    {
        $order = $this->order;
        if ($this->useSchemaOrder && $this->context->schemas()->has($this->type)) {
            foreach ($this->context->schemas()->get($this->type)->orderBy as $field => $direction) {
                $order[] = [$field, $direction];
            }
        }
        if ($order === []) {
            return $items;
        }

        usort($items, static function (Item $a, Item $b) use ($order): int {
            foreach ($order as $rule) {
                if ($rule instanceof \Closure) {
                    $result = $rule($a, $b);
                } else {
                    [$field, $direction] = $rule;
                    $result = self::compare($a->value($field), $b->value($field));
                    if ($direction === 'DESC') {
                        $result = -$result;
                    }
                }
                if ($result !== 0) {
                    return $result;
                }
            }

            return $a->id <=> $b->id;
        });

        return $items;
    }

    public static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $a === '' || $a === []) {
            return ($b === null || $b === '' || $b === []) ? 0 : 1;
        }
        if ($b === null || $b === '' || $b === []) {
            return -1;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (int) $a <=> (int) $b;
        }
        if (is_array($a) || is_array($b)) {
            return count((array) $a) <=> count((array) $b);
        }

        return strnatcasecmp((string) $a, (string) $b);
    }

    /**
     * Loose equality that understands relation values: a stored list of
     * {type, id} pairs matches any id in the wanted value.
     */
    public static function matches(mixed $stored, mixed $wanted): bool
    {
        if (is_array($stored) && self::isPairList($stored)) {
            $storedIds = array_map(static fn (array $pair): int => (int) $pair['id'], $stored);
            $wantedIds = self::idsOf($wanted);

            return $wantedIds !== [] && array_intersect($storedIds, $wantedIds) !== [];
        }

        if (is_array($stored)) {
            // A list of scalars (select multiple): contains.
            if (is_array($wanted)) {
                return array_intersect(array_map('strval', $stored), array_map('strval', $wanted)) !== [];
            }

            return in_array((string) $wanted, array_map('strval', $stored), true);
        }

        if (is_array($wanted) && isset($wanted['id']) && is_int($stored)) {
            return $stored === (int) $wanted['id'];
        }

        if (is_bool($stored) || is_bool($wanted)) {
            return (bool) $stored === (bool) $wanted;
        }
        if (is_numeric($stored) && is_numeric($wanted)) {
            return (float) $stored === (float) $wanted;
        }

        return (string) $stored === (string) $wanted;
    }

    private static function isPairList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_is_list($value) && is_array($value[0]) && isset($value[0]['id']) && isset($value[0]['type']);
    }

    /** @return int[] */
    private static function idsOf(mixed $wanted): array
    {
        if ($wanted === null || $wanted === '' || $wanted === []) {
            return [];
        }
        if (is_int($wanted)) {
            return [$wanted];
        }
        if (is_string($wanted)) {
            return ctype_digit($wanted) ? [(int) $wanted] : [];
        }
        if ($wanted instanceof Item) {
            return [$wanted->id];
        }
        if (is_array($wanted)) {
            if (isset($wanted['id'])) {
                return [(int) $wanted['id']];
            }
            $ids = [];
            foreach ($wanted as $entry) {
                foreach (self::idsOf($entry) as $id) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        return [];
    }
}
