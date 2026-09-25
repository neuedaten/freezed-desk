<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * Anything JSON can hold. The escape hatch: edited as JSON text, exported
 * as decoded data.
 */
class JsonType extends AbstractFieldType
{
    public function name(): string
    {
        return 'json';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if (is_string($input)) {
            $trimmed = trim($input);
            if ($trimmed === '') {
                return null;
            }
            try {
                return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return new JsonInvalid($input);
            }
        }

        return $input;
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        return $value instanceof JsonInvalid ? [$context->t('validation.json')] : [];
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        return $value instanceof JsonInvalid ? null : $value;
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        return '';
    }
}

/**
 * Marker for JSON text that did not parse; it never reaches storage because
 * validate() rejects it.
 */
final class JsonInvalid
{
    public function __construct(public readonly string $text)
    {
    }
}
