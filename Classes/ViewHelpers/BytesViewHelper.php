<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\FreezedDesk\Media\MediaRepository;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class BytesViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'int', 'Bytes', false);
    }

    public function render(): string
    {
        return MediaRepository::formatBytes((int) ($this->arguments['value'] ?? $this->renderChildren()));
    }
}
