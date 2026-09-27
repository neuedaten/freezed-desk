<?php

namespace Neuedaten\FreezedDesk\Extension;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Web\Router;

/**
 * An extension that contributes nothing yet; override what you need.
 */
abstract class AbstractExtension implements ExtensionInterface
{
    public function enabled(DeskContext $context): bool
    {
        return true;
    }

    public function agentSections(DeskContext $context): array
    {
        return [];
    }

    public function routes(Router $router): void
    {
    }

    public function themeRoot(): ?string
    {
        return null;
    }

    public function navigation(DeskContext $context): array
    {
        return [];
    }

    public function dashboardPanels(DeskContext $context): array
    {
        return [];
    }

    public function recordPanels(DeskContext $context, TypeSchema $schema, Item $item): array
    {
        return [];
    }

    public function status(DeskContext $context): array
    {
        return [];
    }

    public function translations(string $locale): array
    {
        $file = $this->languageDirectory() === null ? null : $this->languageDirectory() . '/' . $locale . '.php';
        if ($file === null || !is_file($file)) {
            return [];
        }
        $strings = include $file;

        return is_array($strings) ? $strings : [];
    }

    /** A folder with <locale>.php files returning key => text, or null. */
    protected function languageDirectory(): ?string
    {
        return null;
    }
}
