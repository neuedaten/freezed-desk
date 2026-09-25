<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\FieldTypes\FeaturesType;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The vocabulary of a features field: key => {label, kind, options, icon, group}.
 */
class VocabularyViewHelper extends AbstractViewHelper
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
        $context = DeskContext::get();
        $type = $context->fieldTypes()->get('features');

        return $type instanceof FeaturesType ? $type->vocabulary($field, $context) : [];
    }
}
