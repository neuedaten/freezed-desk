<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * Markdown, stored as written and exported as HTML (CommonMark plus tables,
 * raw HTML stripped). Templates print it with {body -> f:format.raw()}.
 */
class MarkdownType extends TextType
{
    public function name(): string
    {
        return 'markdown';
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $context->markdown()->toHtml((string) $value);
    }
}
