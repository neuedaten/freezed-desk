<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * A link: {href, label, target}. href may be an absolute URL, a relative
 * path or a CONTENT:<type>/<slug> reference for freezed:link.
 */
class LinkType extends AbstractFieldType
{
    public function name(): string
    {
        return 'link';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if (is_string($input)) {
            $input = ['href' => $input];
        }
        if (!is_array($input)) {
            return null;
        }

        $href = self::stringOrNull($input['href'] ?? null);
        $label = self::stringOrNull($input['label'] ?? null);
        $target = self::stringOrNull($input['target'] ?? null);

        if ($href === null && $label === null) {
            return null;
        }

        return [
            'href' => $href ?? '',
            'label' => $label ?? '',
            'target' => $target === '_blank' ? '_blank' : '',
        ];
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if ($value === null) {
            return [];
        }
        $href = (string) ($value['href'] ?? '');
        if ($href === '') {
            return [$context->t('validation.linkHref')];
        }
        if (preg_match('/^\s*(javascript|data|vbscript):/i', $href)) {
            return [$context->t('validation.url')];
        }

        return [];
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        if (!is_array($value)) {
            return null;
        }

        return [
            'href' => (string) ($value['href'] ?? ''),
            'label' => (string) ($value['label'] ?? ''),
            'target' => (string) ($value['target'] ?? ''),
        ];
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        return is_array($value) ? (string) ($value['label'] ?? '') : '';
    }
}
