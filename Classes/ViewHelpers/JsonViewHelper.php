<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * JSON for data attributes: data-config="{desk:json(value: config)}".
 * Fluid escapes the result for the attribute.
 */
class JsonViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'mixed', 'Value to encode', false);
    }

    public function render(): string
    {
        $value = $this->arguments['value'] ?? $this->renderChildren();

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
