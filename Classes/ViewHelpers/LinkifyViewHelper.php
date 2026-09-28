<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\FreezedDesk\Review\ReviewPresenter;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A plain text, escaped, with line breaks kept and web addresses as links
 * that open in a new window: {point.text -> desk:linkify()}.
 */
class LinkifyViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    protected $escapeChildren = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'string', 'The text', false);
    }

    public function render(): string
    {
        $value = $this->arguments['value'] ?? $this->renderChildren();

        return nl2br(ReviewPresenter::linkify(is_scalar($value) ? (string) $value : ''), false);
    }
}
