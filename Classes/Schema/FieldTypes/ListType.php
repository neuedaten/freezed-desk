<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\SchemaLoader;

/**
 * A sortable list of values of one field type ('of'). Options: max, min.
 * Rows whose value is empty are dropped.
 */
class ListType extends AbstractFieldType
{
    public function name(): string
    {
        return 'list';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if ($input === null || $input === '') {
            return [];
        }
        if (!is_array($input)) {
            $input = [$input];
        }

        $of = $field->of;
        $type = $context->fieldTypes()->get($of->type);
        $values = [];
        foreach ($input as $row) {
            $value = $type->normalize($row, $of, $context);
            if (!$type->isEmpty($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function defaultValue(FieldDefinition $field, DeskContext $context): mixed
    {
        return $field->hasDefault ? (array) $field->default : [];
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if (!is_array($value)) {
            return [$context->t('validation.list')];
        }
        $errors = [];
        $max = $field->get('max');
        if (is_int($max) && count($value) > $max) {
            $errors[] = $context->t('validation.maxItems', ['max' => $max]);
        }
        $min = $field->get('min');
        if (is_int($min) && count($value) < $min) {
            $errors[] = $context->t('validation.minItems', ['min' => $min]);
        }

        $of = $field->of;
        $type = $context->fieldTypes()->get($of->type);
        foreach ($value as $index => $row) {
            foreach ($type->validate($row, $of, $context) as $error) {
                $errors[] = '#' . ($index + 1) . ': ' . $error;
            }
        }

        return $errors;
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        if (!is_array($value)) {
            return [];
        }
        $of = $field->of;
        $type = $context->fieldTypes()->get($of->type);

        return array_values(array_map(static fn (mixed $row): mixed => $type->export($row, $of, $context), $value));
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        if (!is_array($value)) {
            return '';
        }
        $type = $context->fieldTypes()->get($field->of->type);

        return implode(' ', array_filter(array_map(static fn (mixed $row): string => $type->searchText($row, $field->of, $context), $value)));
    }

    public function validateDefinition(FieldDefinition $field, SchemaLoader $schemas): array
    {
        if ($field->of === null) {
            return ['a list needs an "of" declaration.'];
        }
        if (in_array($field->of->type, ['list'], true)) {
            return ['a list of lists is not supported; use a list of groups.'];
        }

        return [];
    }
}
