<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\SchemaLoader;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * Attributes from a vocabulary type ('from' => 'features'), e.g.
 * "Bootsanleger: ja". The vocabulary's records declare what a feature is:
 * key (unique), label, kind (bool | text | number | select), options (for
 * select), icon, group. A new feature is a record, not a schema change.
 *
 * Stored as {key: value}; exported as a list of {key, label, value, kind,
 * icon, group} in vocabulary order, only for features that have a value.
 */
class FeaturesType extends AbstractFieldType
{
    public function name(): string
    {
        return 'features';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if (!is_array($input)) {
            return [];
        }

        $vocabulary = $this->vocabulary($field, $context);
        $value = [];
        foreach ($input as $key => $raw) {
            $key = (string) $key;
            $kind = $vocabulary[$key]['kind'] ?? 'bool';

            switch ($kind) {
                case 'bool':
                    $bool = is_bool($raw) ? $raw : in_array(strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes', 'ja'], true);
                    if ($bool) {
                        $value[$key] = true;
                    }
                    break;
                case 'number':
                    if ($raw !== null && $raw !== '' && is_numeric(is_string($raw) ? str_replace(',', '.', $raw) : $raw)) {
                        $number = (is_string($raw) ? str_replace(',', '.', $raw) : $raw) + 0;
                        $value[$key] = $number;
                    }
                    break;
                default:
                    $string = self::stringOrNull($raw);
                    if ($string !== null) {
                        $value[$key] = $string;
                    }
            }
        }

        return $value;
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
        $vocabulary = $this->vocabulary($field, $context);
        foreach ($value as $key => $raw) {
            $entry = $vocabulary[(string) $key] ?? null;
            if ($entry === null) {
                continue; // an unknown key is ignored on export, not an error
            }
            if ($entry['kind'] === 'select' && !in_array((string) $raw, $entry['options'], true)) {
                return [$entry['label'] . ': ' . $context->t('validation.option', ['value' => (string) $raw])];
            }
        }

        return [];
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        $value = is_array($value) ? $value : [];
        $export = [];
        foreach ($this->vocabulary($field, $context) as $key => $entry) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $export[] = [
                'key' => $key,
                'label' => $entry['label'],
                'value' => $value[$key],
                'kind' => $entry['kind'],
                'icon' => $entry['icon'],
                'group' => $entry['group'],
            ];
        }

        return $export;
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        $vocabulary = $this->vocabulary($field, $context);
        $words = [];
        foreach (is_array($value) ? array_keys($value) : [] as $key) {
            $words[] = $vocabulary[(string) $key]['label'] ?? (string) $key;
        }

        return implode(' ', $words);
    }

    public function validateDefinition(FieldDefinition $field, SchemaLoader $schemas): array
    {
        $from = $field->get('from');
        if (!is_string($from) || $from === '') {
            return ['a features field needs "from" => "<vocabulary type>".'];
        }
        if (!$schemas->has($from)) {
            return [sprintf('vocabulary type "%s" is not a type (no %s.php in the types folder).', $from, $from)];
        }
        $schema = $schemas->get($from);
        if (!$schema->hasField('key')) {
            return [sprintf('vocabulary type "%s" needs a text field "key".', $from)];
        }

        return [];
    }

    /**
     * The vocabulary as key => {label, kind, options, icon, group}, in the
     * vocabulary type's list order. Archived records are left out.
     *
     * @return array<string, array{label: string, kind: string, options: string[], icon: string, group: string}>
     */
    public function vocabulary(FieldDefinition $field, DeskContext $context): array
    {
        $from = (string) $field->get('from');
        static $cache = [];
        if (isset($cache[$from])) {
            return $cache[$from];
        }

        $vocabulary = [];
        foreach ($context->repository()->find($from)->notArchived()->ordered()->all() as $item) {
            /** @var Item $item */
            $key = (string) ($item->data['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $kind = (string) ($item->data['kind'] ?? 'bool');
            $options = $item->data['options'] ?? [];
            $vocabulary[$key] = [
                'label' => (string) ($item->data['label'] ?? $item->title),
                'kind' => in_array($kind, ['bool', 'text', 'number', 'select'], true) ? $kind : 'bool',
                'options' => is_array($options) ? array_values(array_map('strval', $options)) : [],
                'icon' => (string) ($item->data['icon'] ?? ''),
                'group' => (string) ($item->data['group'] ?? ''),
            ];
        }

        return $cache[$from] = $vocabulary;
    }
}
