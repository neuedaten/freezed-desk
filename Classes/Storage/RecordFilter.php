<?php

namespace Neuedaten\FreezedDesk\Storage;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * Filters and orders for a list of records, the same for the CLI
 * (`list --where:… --order:… --from:… --to:… --referencing:…`, A1.1) and
 * the list filters of the UI (listFilters, A6.1).
 *
 * Conditions: "field=value", "field!=value", "field>value", "field>=value",
 * "field<value", "field<=value", "field~text" (contains, case-insensitive).
 * An empty value matches an empty field ("hero=" = no hero). Relation
 * fields take a slug, "type/slug" or an id; select fields with several
 * values match when any value matches; bool fields take true/false/1/0/
 * yes/no. Besides the fields: slug, title, status, variant, updatedBy,
 * revision, createdAt, updatedAt, publishedAt.
 *
 * Dates: an ISO date or datetime, or "now", "today", "tomorrow",
 * "yesterday", "+14d", "-7d", "+2w", "+1m". A date-only upper bound
 * includes that day.
 */
final class RecordFilter
{
    private const STANDARD = ['slug', 'title', 'status', 'variant', 'updatedBy', 'revision', 'createdAt', 'updatedAt', 'publishedAt', 'sort', 'id'];

    public function __construct(
        private readonly DeskContext $context,
        private readonly TypeSchema $schema,
    ) {
    }

    /**
     * Add one condition to the query.
     *
     * @throws DeskException On an unknown field or a malformed condition.
     */
    public function where(Query $query, string $condition): void
    {
        if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9_.]*)\s*(!=|>=|<=|=|>|<|~)(.*)$/s', $condition, $m)) {
            throw new DeskException(sprintf('Cannot read the condition "%s". Write field=value (also !=, >, >=, <, <=, ~).', $condition));
        }
        [, $name, $operator, $raw] = $m;
        $raw = trim($raw);
        $field = $this->field($name);

        if ($field !== null && $field->type === 'relation') {
            if (!in_array($operator, ['=', '!='], true)) {
                throw new DeskException(sprintf('Relation field "%s" only takes = and !=.', $name));
            }
            if ($raw === '') {
                $query->filter(fn (Item $item): bool => ($operator === '=') === ($item->value($name) === null || $item->value($name) === []));

                return;
            }
            $ids = $this->relationIds($field, $raw);
            $query->filter(static fn (Item $item): bool => ($operator === '=') === ($ids !== [] && Query::matches($item->value($name), $ids)));

            return;
        }

        $kind = $this->kind($name, $field);

        // "at=2027-05-06" or "at=today" on a datetime field means that day.
        if ($kind === 'datetime' && $operator === '=' && $raw !== '' && $raw !== 'now' && preg_match('/^(\d{4}-\d{2}-\d{2}|today|tomorrow|yesterday|[+-]\d+[dwmy])$/', $raw)) {
            $this->where($query, $name . '>=' . $raw);
            $this->where($query, $name . '<=' . $raw);

            return;
        }

        $value = $this->parseValue($raw, $kind, $operator === '<=' || $operator === '>' ? 'upper' : 'lower');

        $query->filter(static function (Item $item) use ($name, $operator, $value, $raw, $kind): bool {
            $stored = $item->value($name);
            $empty = $stored === null || $stored === '' || $stored === [];

            if ($raw === '' && in_array($operator, ['=', '!='], true)) {
                return ($operator === '=') === $empty;
            }

            return match ($operator) {
                '=' => self::equals($stored, $value, $kind),
                '!=' => !self::equals($stored, $value, $kind),
                '~' => !$empty && mb_stripos(is_array($stored) ? implode(' ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $stored)) : (string) $stored, (string) $value) !== false,
                default => !$empty && self::compare($stored, $value, $kind, $operator),
            };
        });
    }

    /**
     * Records whose date field lies in [from, to]. Either bound may be null.
     */
    public function dateRange(Query $query, ?string $from, ?string $to, ?string $fieldName = null): void
    {
        $fieldName ??= $this->defaultDateField();
        if ($fieldName === null) {
            throw new DeskException(sprintf('Type "%s" has no date or datetime field; name one with --date:<field>.', $this->schema->slug));
        }
        $kind = $this->kind($fieldName, $this->field($fieldName));
        if ($kind !== 'date' && $kind !== 'datetime') {
            throw new DeskException(sprintf('"%s" is not a date or datetime field.', $fieldName));
        }
        if ($from !== null && $from !== '') {
            $this->where($query, $fieldName . '>=' . $from);
        }
        if ($to !== null && $to !== '') {
            $this->where($query, $fieldName . '<=' . $to);
        }
    }

    /**
     * A named range of the UI: today, next7, next14, past, future.
     */
    public function namedRange(Query $query, string $range, ?string $fieldName = null): void
    {
        match ($range) {
            'today' => $this->dateRange($query, 'today', 'today', $fieldName),
            'next7' => $this->dateRange($query, 'today', '+7d', $fieldName),
            'next14' => $this->dateRange($query, 'today', '+14d', $fieldName),
            'future' => $this->dateRange($query, 'now', null, $fieldName),
            'past' => $this->where($query, ($fieldName ?? $this->defaultDateField() ?? 'updatedAt') . '<now'),
            default => throw new DeskException(sprintf('Unknown range "%s" (today, next7, next14, future, past).', $range)),
        };
    }

    /**
     * Records that reference the given record ("wird verwendet von").
     */
    public function referencing(Query $query, Item $target): void
    {
        $ids = [];
        foreach ($this->context->repository()->referencing($target->id) as $reference) {
            $ids[$reference['item']->id] = true;
        }
        $query->filter(static fn (Item $item): bool => isset($ids[$item->id]));
    }

    /**
     * "--order:at,-updatedAt": ascending, "-" for descending.
     */
    public function order(Query $query, string $order): void
    {
        foreach (array_filter(array_map('trim', explode(',', $order))) as $part) {
            $direction = str_starts_with($part, '-') ? 'DESC' : 'ASC';
            $name = ltrim($part, '-+');
            if ($this->field($name) === null && !in_array($name, self::STANDARD, true)) {
                throw new DeskException(sprintf('Cannot order by "%s": not a field of %s.', $name, $this->schema->slug));
            }
            $query->orderBy($name, $direction);
        }
    }

    /** The first date or datetime field: the agenda field, else the first in the schema. */
    public function defaultDateField(): ?string
    {
        if (isset($this->schema->listViews['agenda']['field'])) {
            return (string) $this->schema->listViews['agenda']['field'];
        }

        return array_key_first(array_filter($this->schema->fields, static fn (FieldDefinition $f): bool => in_array($f->type, ['date', 'datetime'], true)));
    }

    /**
     * A point in time as "Y-m-d\TH:i" (the stored shape of datetime fields),
     * from an absolute or relative expression.
     */
    public static function resolveDate(string $expression, string $bound = 'lower'): string
    {
        $expression = trim($expression);
        $today = new \DateTimeImmutable('today');

        $date = match (true) {
            $expression === 'now' => new \DateTimeImmutable('now'),
            $expression === 'today' => $today,
            $expression === 'tomorrow' => $today->modify('+1 day'),
            $expression === 'yesterday' => $today->modify('-1 day'),
            (bool) preg_match('/^([+-]\d+)([dwmy])$/', $expression, $m) => $today->modify($m[1] . ' ' . ['d' => 'days', 'w' => 'weeks', 'm' => 'months', 'y' => 'years'][$m[2]]),
            default => null,
        };

        $dateOnly = $date !== null ? $expression !== 'now' : (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $expression);
        if ($date === null) {
            try {
                $date = new \DateTimeImmutable($expression);
            } catch (\Exception) {
                throw new DeskException(sprintf('Cannot read the date "%s" (use YYYY-MM-DD, YYYY-MM-DDTHH:MM, today, +14d …).', $expression));
            }
        }
        if ($dateOnly) {
            $date = $bound === 'upper' ? $date->setTime(23, 59, 59) : $date->setTime(0, 0);
        }

        return $date->format('Y-m-d\TH:i:s');
    }

    // ------------------------------------------------------- internal ---

    private function field(string $name): ?FieldDefinition
    {
        $top = explode('.', $name, 2)[0];
        $field = $this->schema->field($top);
        if ($field === null && !in_array($name, self::STANDARD, true)) {
            throw new DeskException(sprintf('Unknown field "%s" for type "%s".', $name, $this->schema->slug));
        }
        if ($field !== null && str_contains($name, '.') && $field->fields !== null) {
            return $field->fields[explode('.', $name, 2)[1]] ?? $field;
        }

        return $field;
    }

    private function kind(string $name, ?FieldDefinition $field): string
    {
        if ($field === null) {
            return match ($name) {
                'createdAt', 'updatedAt', 'publishedAt' => 'datetime',
                'revision', 'sort', 'id' => 'number',
                default => 'text',
            };
        }

        return match ($field->type) {
            'number' => 'number',
            'bool' => 'bool',
            'date' => 'date',
            'datetime' => 'datetime',
            default => 'text',
        };
    }

    private function parseValue(string $raw, string $kind, string $bound): mixed
    {
        if ($raw === '') {
            return '';
        }

        return match ($kind) {
            'bool' => in_array(strtolower($raw), ['1', 'true', 'yes', 'ja', 'on'], true),
            'number' => is_numeric($raw) ? (float) $raw : throw new DeskException(sprintf('"%s" is not a number.', $raw)),
            'date', 'datetime' => self::resolveDate($raw, $bound),
            default => $raw,
        };
    }

    private static function equals(mixed $stored, mixed $value, string $kind): bool
    {
        if ($kind === 'date' || $kind === 'datetime') {
            return is_string($stored) && $stored !== '' && self::normaliseDate($stored) === self::normaliseDate((string) $value);
        }

        return Query::matches($stored, $value);
    }

    private static function compare(mixed $stored, mixed $value, string $kind, string $operator): bool
    {
        if ($kind === 'date' || $kind === 'datetime') {
            $left = self::normaliseDate((string) $stored);
            $right = self::normaliseDate((string) $value);
            $result = strcmp($left, $right);
        } elseif (is_numeric($stored) && is_numeric($value)) {
            $result = (float) $stored <=> (float) $value;
        } else {
            $result = strnatcasecmp((string) (is_scalar($stored) ? $stored : ''), (string) $value);
        }

        return match ($operator) {
            '>' => $result > 0,
            '>=' => $result >= 0,
            '<' => $result < 0,
            '<=' => $result <= 0,
            default => false,
        };
    }

    /** Stored dates ("2027-05-06", "2027-05-06T17:00", ISO with offset) as a comparable local string. */
    private static function normaliseDate(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . 'T00:00:00';
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s');
    }

    /** @return int[] */
    private function relationIds(FieldDefinition $field, string $raw): array
    {
        $ids = [];
        foreach (array_map('trim', explode('|', $raw)) as $reference) {
            if (ctype_digit($reference)) {
                $ids[] = (int) $reference;
                continue;
            }
            if (str_contains($reference, '/')) {
                [$type, $slug] = explode('/', $reference, 2);
                $item = $this->context->repository()->findBySlug($type, $slug);
            } else {
                $item = null;
                foreach ((array) $field->get('to') as $type) {
                    $item ??= $this->context->repository()->findBySlug((string) $type, $reference);
                }
            }
            if ($item === null) {
                throw new DeskException(sprintf('"%s" names no record that field "%s" can reference.', $reference, $field->name));
            }
            $ids[] = $item->id;
        }

        return $ids;
    }
}
