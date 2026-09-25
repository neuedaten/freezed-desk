<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\ProvidesExtraVariables;

/**
 * An object of sub-fields ('fields'), e.g. an address. Sub-fields marked
 * internal are stored but not exported, like top-level fields.
 */
class GroupType extends AbstractFieldType
{
    public function name(): string
    {
        return 'group';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        $input = is_array($input) ? $input : [];
        $value = [];
        foreach ($field->fields ?? [] as $name => $sub) {
            $type = $context->fieldTypes()->get($sub->type);
            $value[$name] = array_key_exists($name, $input)
                ? $type->normalize($input[$name], $sub, $context)
                : $type->defaultValue($sub, $context);
        }

        return $value;
    }

    public function defaultValue(FieldDefinition $field, DeskContext $context): mixed
    {
        $value = [];
        foreach ($field->fields ?? [] as $name => $sub) {
            $value[$name] = $context->fieldTypes()->get($sub->type)->defaultValue($sub, $context);
        }

        return $value;
    }

    public function isEmpty(mixed $value): bool
    {
        if (!is_array($value)) {
            return true;
        }
        foreach ($value as $sub) {
            if ($sub !== null && $sub !== '' && $sub !== [] && $sub !== false) {
                return false;
            }
        }

        return true;
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        $errors = [];
        $value = is_array($value) ? $value : [];
        foreach ($field->fields ?? [] as $name => $sub) {
            $type = $context->fieldTypes()->get($sub->type);
            $subValue = $value[$name] ?? null;
            if ($sub->required && $type->isEmpty($subValue)) {
                $errors[] = $sub->label . ': ' . $context->t('validation.required');
                continue;
            }
            foreach ($type->validate($subValue, $sub, $context) as $error) {
                $errors[] = $sub->label . ': ' . $error;
            }
        }

        return $errors;
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        $value = is_array($value) ? $value : [];
        $export = [];
        foreach ($field->fields ?? [] as $name => $sub) {
            if ($sub->internal) {
                continue;
            }
            $type = $context->fieldTypes()->get($sub->type);
            $subValue = array_key_exists($name, $value) ? $value[$name] : $type->defaultValue($sub, $context);
            $export[$name] = $type->export($subValue, $sub, $context);
            if ($type instanceof ProvidesExtraVariables) {
                $export += $type->extraVariables($subValue, $sub, $context);
            }
        }

        return $export;
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        if (!is_array($value)) {
            return '';
        }
        $words = [];
        foreach ($field->fields ?? [] as $name => $sub) {
            if (array_key_exists($name, $value)) {
                $words[] = $context->fieldTypes()->get($sub->type)->searchText($value[$name], $sub, $context);
            }
        }

        return implode(' ', array_filter($words));
    }
}
