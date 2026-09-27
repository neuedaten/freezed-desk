<?php

namespace Neuedaten\FreezedDesk\Web;

use Neuedaten\Freezed\Services\RenderService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * Renders the UI through the core's RenderService, the same Fluid setup the
 * site is built with (freezed ViewHelpers, components, build variable). The
 * package theme (themes/00_desk) comes first; every folder below the
 * project's desk/themes/ is added after it and therefore overrides it,
 * template by template, partial by partial -- the core's theme rule.
 */
final class View
{
    /** @var array<string, mixed> Variables every template gets. */
    private array $shared = [];

    public function __construct(private readonly DeskContext $context)
    {
    }

    public function share(string $name, mixed $value): void
    {
        $this->shared[$name] = $value;
    }

    /**
     * @param string $template "Records/Index" → templates/Records/Index.html
     * @param array<string, mixed> $variables
     */
    public function render(string $template, array $variables = []): string
    {
        $roots = $this->themeRoots();
        $file = $this->templateFile($template, $roots);

        return RenderService::getInstance()->renderFile(
            $file,
            array_merge($this->shared, $variables),
            [
                'templateRootPaths' => array_map(static fn (string $r): string => $r . '/templates/templates/', $roots),
                'layoutRootPaths' => array_map(static fn (string $r): string => $r . '/templates/layouts/', $roots),
                'partialRootPaths' => array_map(static fn (string $r): string => $r . '/templates/partials/', $roots),
                'componentRootPaths' => array_map(static fn (string $r): string => $r . '/templates/components/', $roots),
            ],
            ['desk' => 'Neuedaten\\FreezedDesk\\ViewHelpers']
        );
    }

    /**
     * The template file for "Records/Index": the last theme root that has
     * it wins.
     *
     * @param string[] $roots
     */
    private function templateFile(string $template, array $roots): string
    {
        foreach (array_reverse($roots) as $root) {
            $candidate = $root . '/templates/templates/' . $template . '.html';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new DeskException('Desk UI template "' . $template . '" not found in ' . implode(', ', $roots));
    }

    /**
     * Theme folders in priority order: the package theme, the theme chosen
     * with desk.theme, then the project's overlays.
     *
     * @return string[]
     */
    public function themeRoots(): array
    {
        $roots = [dirname(__DIR__, 2) . '/themes/00_desk'];
        // Extensions add their pages and partials; a theme and the project
        // still override them.
        foreach ($this->context->extensions() as $extension) {
            $root = $extension->themeRoot();
            if ($root !== null && is_dir($root)) {
                $roots[] = $root;
            }
        }
        $packageTheme = $this->context->config->packageThemePath();
        if ($packageTheme !== null) {
            $roots[] = $packageTheme;
        }
        $overlayRoot = $this->context->config->themesPath();
        foreach (glob($overlayRoot . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $roots[] = $directory;
        }

        return $roots;
    }

    /**
     * Resolve a static asset of the UI (css, js, icons), overlays first.
     */
    public function staticFile(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        foreach (array_reverse($this->themeRoots()) as $root) {
            $candidate = $root . '/static/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
