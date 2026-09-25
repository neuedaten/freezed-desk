<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * An integer or a decimal. Options: min, max, step (a step of 1 or an
 * "integer" => true makes it an integer field), unit (shown in the UI).
 */
class NumberType extends AbstractFieldType
{
    public function name(): string
    {
        return 'number';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }
        if (is_string($input)) {
            $input = str_replace(',', '.', trim($input));
            if (!is_numeric($input)) {
                return $input; // reported by validate()
            }
        }
        if (!is_numeric($input)) {
            return $input;
        }

        $number = $input + 0;
        if ($this->isInteger($field) || (is_float($number) && floor($number) == $number && !str_contains((string) $input, '.'))) {
            return (int) $number;
        }

        return is_int($number) ? $number : (float) $number;
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_int($value) && !is_float($value)) {
            return [$context->t('validation.number')];
        }

        $errors = [];
        $min = $field->get('min');
        if (is_numeric($min) && $value < $min) {
            $errors[] = $context->t('validation.min', ['min' => $min]);
        }
        $max = $field->get('max');
        if (is_numeric($max) && $value > $max) {
            $errors[] = $context->t('validation.max', ['max' => $max]);
        }
        if ($this->isInteger($field) && !is_int($value)) {
            $errors[] = $context->t('validation.integer');
        }

        return $errors;
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private function isInteger(FieldDefinition $field): bool
    {
        if ($field->get('integer') === true) {
            return true;
        }
        $step = $field->get('step');

        return $step !== null && is_numeric($step) && (float) $step === 1.0 && $field->get('integer') !== false;
    }
}
