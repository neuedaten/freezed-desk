<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Web\Controllers\ApiController;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A media entry as array (src, alt, thumb, url, isImage, …) or null:
 * <f:variable name="m" value="{desk:media(id: value)}" />
 */
class MediaViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('id', 'mixed', 'Media id', false);
    }

    public function render(): ?array
    {
        $id = $this->arguments['id'];
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        $media = DeskContext::get()->media()->get((int) $id);

        return $media === null ? null : ApiController::mediaJson($media);
    }
}
