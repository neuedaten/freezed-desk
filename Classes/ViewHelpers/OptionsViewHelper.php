<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\FieldTypes\SelectType;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The options of a select field as key => label.
 */
class OptionsViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('field', 'mixed', 'Field definition', true);
    }

    public function render(): array
    {
        $field = $this->arguments['field'];
        if (!$field instanceof FieldDefinition) {
            return [];
        }
        $type = DeskContext::get()->fieldTypes()->get('select');

        return $type instanceof SelectType ? $type->options($field) : [];
    }
}
