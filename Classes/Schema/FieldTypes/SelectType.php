<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\ProvidesExtraVariables;
use Neuedaten\FreezedDesk\Schema\SchemaLoader;

/**
 * One key out of a fixed list ('options' => ['ochre' => 'Ocker', …]), or
 * several with 'multiple' => true. Exports the key(s) and, alongside, the
 * label(s) as <name>Label.
 */
class SelectType extends AbstractFieldType implements ProvidesExtraVariables
{
    public function name(): string
    {
        return 'select';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if ($this->isMultiple($field)) {
            if ($input === null || $input === '') {
                return [];
            }
            $values = is_array($input) ? $input : [$input];
            $keys = [];
            foreach ($values as $value) {
                $key = self::stringOrNull($value);
                if ($key !== null) {
                    $keys[] = $key;
                }
            }

            return array_values(array_unique($keys));
        }

        if (is_array($input)) {
            $input = $input[0] ?? null;
        }

        return self::stringOrNull($input);
    }

    public function defaultValue(FieldDefinition $field, DeskContext $context): mixed
    {
        if ($field->hasDefault) {
            return $field->default;
        }

        return $this->isMultiple($field) ? [] : null;
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        $options = $this->options($field);
        $keys = $this->isMultiple($field) ? (array) $value : ($value === null ? [] : [$value]);

        foreach ($keys as $key) {
            if (!array_key_exists((string) $key, $options)) {
                return [$context->t('validation.option', ['value' => (string) $key])];
            }
        }

        return [];
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        return $value;
    }

    public function extraVariables(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        $options = $this->options($field);

        if ($this->isMultiple($field)) {
            $labels = [];
            foreach ((array) $value as $key) {
                $labels[] = $options[(string) $key] ?? (string) $key;
            }

            return [$field->name . 'Label' => $labels];
        }

        return [$field->name . 'Label' => $value === null ? '' : ($options[(string) $value] ?? (string) $value)];
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        $options = $this->options($field);
        $keys = is_array($value) ? $value : [$value];
        $words = [];
        foreach ($keys as $key) {
            if ($key !== null) {
                $words[] = $options[(string) $key] ?? (string) $key;
            }
        }

        return implode(' ', $words);
    }

    public function validateDefinition(FieldDefinition $field, SchemaLoader $schemas): array
    {
        $options = $field->get('options');
        if (!is_array($options) || $options === []) {
            return ['a select field needs a non-empty "options" array (key => label).'];
        }

        return [];
    }

    /**
     * @return array<string, string> key => label
     */
    public function options(FieldDefinition $field): array
    {
        $raw = $field->get('options', []);
        $options = [];
        foreach ((array) $raw as $key => $label) {
            if (is_array($label)) {
                $key = (string) ($label['value'] ?? $key);
                $label = (string) ($label['label'] ?? $key);
            } elseif (is_int($key)) {
                $key = (string) $label;
            }
            $options[(string) $key] = (string) $label;
        }

        return $options;
    }

    private function isMultiple(FieldDefinition $field): bool
    {
        return (bool) $field->get('multiple', false);
    }
}
