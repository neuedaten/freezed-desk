<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The records behind a stored relation value (list of {type, id}), as
 * arrays from desk:record, missing ones left out.
 */
class RefsViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'mixed', 'Stored relation value', false);
    }

    public function render(): array
    {
        $refs = [];
        foreach ((array) ($this->arguments['value'] ?? []) as $pair) {
            $record = is_array($pair) ? RecordViewHelper::describe($pair['id'] ?? null) : null;
            if ($record !== null) {
                $refs[] = $record;
            }
        }

        return $refs;
    }
}
