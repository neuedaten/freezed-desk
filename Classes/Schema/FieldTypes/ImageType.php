<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * One image from the media library, stored as its media id and exported as
 * {id, src, alt, caption, credit, license, width, height, focal, mime}. src
 * is relative to the media root, for freezed:image with context="media".
 */
class ImageType extends AbstractFieldType
{
    public function name(): string
    {
        return 'image';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        return self::mediaId($input, $context);
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_int($value) || $context->media()->get($value) === null) {
            return [$context->t('validation.media', ['id' => is_scalar($value) ? (string) $value : '?'])];
        }

        return [];
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        if (!is_int($value)) {
            return null;
        }

        return $context->media()->get($value)?->toVariables();
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        return '';
    }

    /**
     * Accepts an id, a numeric string, a {id} / {file} array (the latter is
     * how the export refers to media) or an empty value.
     */
    public static function mediaId(mixed $input, DeskContext $context): ?int
    {
        if (is_array($input)) {
            if (isset($input['id'])) {
                $input = $input['id'];
            } elseif (isset($input['file']) && is_string($input['file'])) {
                return $context->media()->findByFile($input['file'])?->id;
            } else {
                return null;
            }
        }
        if ($input === null || $input === '' || $input === false) {
            return null;
        }
        if (is_int($input)) {
            return $input;
        }
        if (is_string($input) && ctype_digit(trim($input))) {
            return (int) trim($input);
        }

        return null;
    }
}
