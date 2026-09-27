<?php

namespace Neuedaten\FreezedDesk;

use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Extension\ExtensionInterface;
use Neuedaten\FreezedDesk\Outbox\OutboxExtension;

/**
 * The extensions of this project: the modules that ship with Desk (the
 * outbox), the ones packages declare in composer.json
 * (extra.freezed-desk.extensions) and the ones in desk.extensions. Only
 * enabled ones are returned.
 */
final class Extensions implements \IteratorAggregate
{
    /** Modules of Desk itself. */
    private const BUILT_IN = [OutboxExtension::class];

    /** @param ExtensionInterface[] $extensions */
    private function __construct(private readonly array $extensions)
    {
    }

    public static function discover(DeskContext $context): self
    {
        $classes = self::BUILT_IN;
        foreach (self::packageDeclarations() as $class) {
            $classes[] = $class;
        }
        foreach ((array) $context->config->get('extensions', []) as $class) {
            if (is_string($class) && $class !== '') {
                $classes[] = ltrim($class, '\\');
            }
        }

        $extensions = [];
        foreach (array_unique($classes) as $class) {
            if (!class_exists($class)) {
                throw new DeskException(sprintf('Desk extension "%s" does not exist. Check the package\'s autoloading or desk.extensions.', $class));
            }
            $extension = new $class();
            if (!$extension instanceof ExtensionInterface) {
                throw new DeskException(sprintf('Desk extension "%s" must implement %s.', $class, ExtensionInterface::class));
            }
            if ($extension->enabled($context)) {
                $extensions[$extension->name()] = $extension;
            }
        }

        return new self($extensions);
    }

    /** @return array<string, ExtensionInterface> */
    public function all(): array
    {
        return $this->extensions;
    }

    public function has(string $name): bool
    {
        return isset($this->extensions[$name]);
    }

    public function get(string $name): ?ExtensionInterface
    {
        return $this->extensions[$name] ?? null;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->extensions);
    }

    /**
     * extra.freezed-desk.extensions of every installed package (and the
     * root package), read through Composer's runtime data like the core's
     * command registry does.
     *
     * @return string[]
     */
    private static function packageDeclarations(): array
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return [];
        }
        $classes = [];
        $seen = [];
        foreach (\Composer\InstalledVersions::getAllRawData() as $data) {
            foreach ($data['versions'] ?? [] as $info) {
                $installPath = $info['install_path'] ?? null;
                if (!is_string($installPath) || $installPath === '') {
                    continue;
                }
                $real = realpath(rtrim($installPath, '/\\') . '/composer.json');
                if ($real === false || isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;
                $json = json_decode((string) @file_get_contents($real), true);
                foreach ((array) ($json['extra']['freezed-desk']['extensions'] ?? []) as $class) {
                    if (is_string($class) && $class !== '') {
                        $classes[] = ltrim($class, '\\');
                    }
                }
            }
        }

        return $classes;
    }
}
