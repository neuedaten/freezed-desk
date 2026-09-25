<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

/**
 * A date with time, stored as "YYYY-MM-DDTHH:MM" (the value of an HTML
 * datetime-local input) and exported as "YYYY-MM-DDTHH:MM:SS".
 */
class DateTimeType extends DateType
{
    protected const FORMAT = 'Y-m-d\TH:i';

    public function name(): string
    {
        return 'datetime';
    }

    public function export(mixed $value, \Neuedaten\FreezedDesk\Schema\FieldDefinition $field, \Neuedaten\FreezedDesk\DeskContext $context): mixed
    {
        return is_string($value) && $value !== '' ? $value . ':00' : null;
    }
}
