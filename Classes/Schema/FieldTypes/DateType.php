<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * A calendar date, stored and exported as "YYYY-MM-DD".
 */
class DateType extends AbstractFieldType
{
    protected const FORMAT = 'Y-m-d';

    public function name(): string
    {
        return 'date';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        $value = self::stringOrNull($input);
        if ($value === null) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value; // reported by validate()
        }

        return $date->format(static::FORMAT);
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if ($value === null) {
            return [];
        }
        $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!' . static::FORMAT, $value) : false;
        if ($parsed === false || $parsed->format(static::FORMAT) !== $value) {
            return [$context->t('validation.' . $this->name())];
        }

        return [];
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        return is_string($value) ? $value : null;
    }
}
