<?php

namespace Neuedaten\FreezedDesk\Schema;

use Neuedaten\FreezedDesk\DeskContext;

abstract class AbstractFieldType implements FieldTypeInterface
{
    public function defaultValue(FieldDefinition $field, DeskContext $context): mixed
    {
        return $field->hasDefault ? $field->default : null;
    }

    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    public function validateDefinition(FieldDefinition $field, SchemaLoader $schemas): array
    {
        return [];
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        return [];
    }

    protected static function stringOrNull(mixed $input): ?string
    {
        if ($input === null) {
            return null;
        }
        if (is_scalar($input)) {
            $string = trim((string) $input);
            return $string === '' ? null : $string;
        }

        return null;
    }
}
