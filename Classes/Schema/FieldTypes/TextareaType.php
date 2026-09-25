<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

/**
 * Several lines of plain text. Exported as is; line breaks are the
 * template's business (f:format.nl2br).
 */
class TextareaType extends TextType
{
    public function name(): string
    {
        return 'textarea';
    }
}
