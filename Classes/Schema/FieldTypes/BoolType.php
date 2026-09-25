<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

class BoolType extends AbstractFieldType
{
    public function name(): string
    {
        return 'bool';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if (is_bool($input)) {
            return $input;
        }
        if ($input === null) {
            return false;
        }
        if (is_string($input)) {
            return in_array(strtolower(trim($input)), ['1', 'true', 'on', 'yes', 'ja'], true);
        }

        return (bool) $input;
    }

    public function defaultValue(FieldDefinition $field, DeskContext $context): mixed
    {
        return $field->hasDefault ? (bool) $field->default : false;
    }

    public function isEmpty(mixed $value): bool
    {
        return $value === null;
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        return (bool) $value;
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        return '';
    }
}
