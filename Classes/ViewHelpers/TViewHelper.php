<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\FreezedDesk\DeskContext;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A UI string: {desk:t(key: 'ui.save')} or {desk:t(key: 'ui.records', params: {count: 3})}.
 */
class TViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('key', 'string', 'Translation key', true);
        $this->registerArgument('params', 'array', 'Placeholder values', false, []);
    }

    public function render(): string
    {
        return DeskContext::get()->t((string) $this->arguments['key'], (array) $this->arguments['params']);
    }
}
