<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * A single line of text. Options: maxLength, minLength, pattern (regex),
 * placeholder.
 */
class TextType extends AbstractFieldType
{
    public function name(): string
    {
        return 'text';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        $value = self::stringOrNull($input);

        return $value === null ? null : str_replace(["\r\n", "\r"], "\n", $value);
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if ($value === null) {
            return [];
        }
        $errors = [];
        $length = mb_strlen((string) $value);

        $max = $field->get('maxLength');
        if (is_int($max) && $length > $max) {
            $errors[] = $context->t('validation.maxLength', ['max' => $max, 'length' => $length]);
        }
        $min = $field->get('minLength');
        if (is_int($min) && $length < $min) {
            $errors[] = $context->t('validation.minLength', ['min' => $min]);
        }
        $pattern = $field->get('pattern');
        if (is_string($pattern) && $pattern !== '' && !preg_match('/' . str_replace('/', '\/', $pattern) . '/u', (string) $value)) {
            $errors[] = $context->t('validation.pattern');
        }

        return $errors;
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        return $value === null ? '' : (string) $value;
    }
}
