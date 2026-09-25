<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * A sortable list of images, stored as media ids. Option: max.
 */
class ImagesType extends AbstractFieldType
{
    public function name(): string
    {
        return 'images';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if ($input === null || $input === '') {
            return [];
        }
        if (is_string($input)) {
            $input = explode(',', $input);
        }
        if (!is_array($input)) {
            return [];
        }

        $ids = [];
        foreach ($input as $entry) {
            $id = ImageType::mediaId($entry, $context);
            if ($id !== null && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function defaultValue(FieldDefinition $field, DeskContext $context): mixed
    {
        return [];
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
        foreach ($value as $id) {
            if (!is_int($id) || $context->media()->get($id) === null) {
                $errors[] = $context->t('validation.media', ['id' => is_scalar($id) ? (string) $id : '?']);
            }
        }

        return $errors;
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        $export = [];
        foreach (is_array($value) ? $value : [] as $id) {
            $media = is_int($id) ? $context->media()->get($id) : null;
            if ($media !== null) {
                $export[] = $media->toVariables();
            }
        }

        return $export;
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        return '';
    }
}
