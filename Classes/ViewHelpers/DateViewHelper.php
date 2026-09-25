<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A stored timestamp in a readable form: {desk:date(value: item.updatedAt)}.
 */
class DateViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'string', 'ISO date or datetime', false);
        $this->registerArgument('format', 'string', 'PHP date format', false, 'd.m.Y H:i');
    }

    public function render(): string
    {
        $value = $this->arguments['value'] ?? $this->renderChildren();
        if (!is_string($value) || $value === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($value))->format((string) $this->arguments['format']);
        } catch (\Exception) {
            return $value;
        }
    }
}
