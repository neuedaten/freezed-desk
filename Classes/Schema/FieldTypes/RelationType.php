<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\SchemaLoader;

/**
 * References to records of one or more types ('to' => 'spots' or
 * ['entries', 'spots']). Stored as a list of {type, id}; a single relation
 * ('multiple' absent or false) keeps at most one.
 *
 * Exported as references {id, type, slug, title, variant, ref} -- ref being
 * CONTENT:<type>/<slug> for freezed:link -- a single one (or null) for a
 * single relation, a list for a multiple one. Only published targets are
 * exported. 'export' => ['teaser', 'hero'] adds those fields of the target
 * to each reference (its own relations excluded, so the export stays flat).
 */
class RelationType extends AbstractFieldType
{
    public function name(): string
    {
        return 'relation';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        $targets = $this->targets($field);

        if ($input === null || $input === '' || $input === false) {
            return [];
        }
        if (is_string($input) && str_contains($input, ',')) {
            $input = explode(',', $input);
        }
        if (!is_array($input) || isset($input['type']) || isset($input['id']) || isset($input['slug'])) {
            $input = [$input];
        }

        $pairs = [];
        foreach ($input as $entry) {
            $pair = $this->pair($entry, $targets, $context);
            if ($pair !== null && !in_array($pair, $pairs, true)) {
                $pairs[] = $pair;
            }
        }

        if (!$this->isMultiple($field)) {
            $pairs = array_slice($pairs, 0, 1);
        }

        return $pairs;
    }

    public function defaultValue(FieldDefinition $field, DeskContext $context): mixed
    {
        return [];
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if (!is_array($value)) {
            return [$context->t('validation.list')];
        }
        $errors = [];
        $targets = $this->targets($field);
        foreach ($value as $pair) {
            $type = (string) ($pair['type'] ?? '');
            $id = $pair['id'] ?? null;
            if (!in_array($type, $targets, true)) {
                $errors[] = $context->t('validation.relationType', ['type' => $type]);
                continue;
            }
            $item = is_int($id) ? $context->repository()->get($id) : null;
            if ($item === null || $item->type !== $type) {
                $errors[] = $context->t('validation.relationTarget', ['type' => $type, 'id' => (string) $id]);
            }
        }
        $max = $field->get('max');
        if (is_int($max) && count($value) > $max) {
            $errors[] = $context->t('validation.maxItems', ['max' => $max]);
        }

        return $errors;
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        $pairs = is_array($value) ? $value : [];
        $exportFields = $field->get('export', []);
        $refs = $context->exporter()->refs($pairs, is_array($exportFields) ? $exportFields : []);

        if ($this->isMultiple($field)) {
            return $refs;
        }

        return $refs[0] ?? null;
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        $words = [];
        foreach (is_array($value) ? $value : [] as $pair) {
            $item = isset($pair['id']) && is_int($pair['id']) ? $context->repository()->get($pair['id']) : null;
            if ($item !== null) {
                $words[] = $item->title;
            }
        }

        return implode(' ', $words);
    }

    public function validateDefinition(FieldDefinition $field, SchemaLoader $schemas): array
    {
        $targets = $this->targets($field);
        if ($targets === []) {
            return ['a relation needs "to" => "<type>" or ["<type>", …].'];
        }
        foreach ($targets as $target) {
            if (!$schemas->has($target)) {
                return [sprintf('relation target "%s" is not a type (no %s.php in the types folder).', $target, $target)];
            }
        }

        return [];
    }

    /** @return string[] */
    public function targets(FieldDefinition $field): array
    {
        $to = $field->get('to');
        if (is_string($to) && $to !== '') {
            return [$to];
        }

        return is_array($to) ? array_values(array_filter(array_map('strval', $to))) : [];
    }

    public function isMultiple(FieldDefinition $field): bool
    {
        return (bool) $field->get('multiple', false);
    }

    /**
     * @param string[] $targets
     * @return array{type: string, id: int}|null
     */
    private function pair(mixed $entry, array $targets, DeskContext $context): ?array
    {
        if (is_string($entry)) {
            $entry = trim($entry);
            if ($entry === '') {
                return null;
            }
            if (str_contains($entry, ':')) {
                [$type, $id] = explode(':', $entry, 2);
                $entry = ['type' => $type, 'id' => ctype_digit($id) ? (int) $id : null, 'slug' => ctype_digit($id) ? null : $id];
            } elseif (ctype_digit($entry)) {
                $entry = (int) $entry;
            } else {
                $entry = ['slug' => $entry];
            }
        }

        if (is_int($entry)) {
            // A bare id is only unambiguous with one target type.
            $entry = ['type' => count($targets) === 1 ? $targets[0] : null, 'id' => $entry];
        }

        if (!is_array($entry)) {
            return null;
        }

        $type = isset($entry['type']) ? (string) $entry['type'] : (count($targets) === 1 ? $targets[0] : null);
        if ($type === null) {
            return null;
        }

        $id = $entry['id'] ?? null;
        if ($id === null && isset($entry['slug']) && is_string($entry['slug'])) {
            $id = $context->repository()->findBySlug($type, $entry['slug'])?->id;
        }
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (!is_int($id)) {
            return null;
        }

        return ['type' => $type, 'id' => $id];
    }
}
