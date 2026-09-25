<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\FreezedDesk\Web\Request;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The current query string with some parameters changed, for links that
 * keep filters: <a href="{desk:query(set: {page: 2})}">.
 */
class QueryViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('set', 'array', 'Parameters to set (null removes)', false, []);
    }

    public function render(): string
    {
        $request = $this->renderingContext->getVariableProvider()->get('request');
        if (!$request instanceof Request) {
            return '';
        }
        $query = $request->queryWith((array) $this->arguments['set']);

        return $query === '' ? $request->path : $request->path . $query;
    }
}
