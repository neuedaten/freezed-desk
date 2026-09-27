<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * Turns the channels of the configuration into adapters.
 *
 * An adapter name resolves in this order:
 *
 *   1. 'adapters' => ['name' => ['class' => 'Vendor\\XyzAdapter', 'file' => '/path/XyzAdapter.php']]
 *   2. the built-in log, mail and webhook
 *   3. <adapterPath>/<Name>Adapter.php with class DeskOutbox\Adapters\<Name>Adapter,
 *      for every folder in 'adapterPaths' ("instagram" → InstagramAdapter.php,
 *      "google-business" → GoogleBusinessAdapter.php)
 *
 * so the social package ships its adapters as files next to the built-in
 * ones, or in a folder of its own, without a Composer autoloader on the
 * server.
 */
final class AdapterRegistry
{
    public const BUILT_IN = [
        'log' => 'DeskOutbox\\Adapters\\LogAdapter',
        'mail' => 'DeskOutbox\\Adapters\\MailAdapter',
        'webhook' => 'DeskOutbox\\Adapters\\WebhookAdapter',
    ];

    /** @var array<string, ChannelAdapter> */
    private array $instances = [];

    /** @var array<string, array<string, mixed>> */
    private readonly array $channels;

    /** @var string[] */
    private readonly array $paths;

    /** @var array<string, array{class?: string, file?: string}> */
    private readonly array $custom;

    /** @param array<string, mixed> $config The outbox configuration. */
    public function __construct(array $config, private readonly AdapterContext $context)
    {
        $channels = [];
        foreach ((array) ($config['channels'] ?? []) as $name => $settings) {
            if (is_array($settings)) {
                $channels[(string) $name] = $settings;
            }
        }
        $this->channels = $channels;
        $this->paths = array_values(array_map('strval', (array) ($config['adapterPaths'] ?? [dirname(__DIR__) . '/adapters'])));
        $this->custom = array_filter((array) ($config['adapters'] ?? []), 'is_array');
    }

    /** @return string[] Configured channel names. */
    public function channels(): array
    {
        return array_keys($this->channels);
    }

    public function has(string $channel): bool
    {
        return isset($this->channels[$channel]);
    }

    public function adapterName(string $channel): string
    {
        $settings = $this->channels[$channel] ?? throw new \InvalidArgumentException('Unknown channel "' . $channel . '".');

        return (string) ($settings['adapter'] ?? $channel);
    }

    /** The adapter of a channel, built once per registry. */
    public function forChannel(string $channel): ChannelAdapter
    {
        if (isset($this->instances[$channel])) {
            return $this->instances[$channel];
        }
        $settings = $this->channels[$channel] ?? throw new \InvalidArgumentException('Unknown channel "' . $channel . '".');
        $class = $this->classFor($this->adapterName($channel));
        unset($settings['adapter']);
        $adapter = new $class($settings, $this->context);
        if (!$adapter instanceof ChannelAdapter) {
            throw new \RuntimeException('Adapter class ' . $class . ' does not implement DeskOutbox\\ChannelAdapter.');
        }

        return $this->instances[$channel] = $adapter;
    }

    /** @return class-string<ChannelAdapter> */
    public function classFor(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/i', $name)) {
            throw new \InvalidArgumentException('Invalid adapter name "' . $name . '".');
        }

        if (isset($this->custom[$name])) {
            $class = (string) ($this->custom[$name]['class'] ?? '');
            $file = (string) ($this->custom[$name]['file'] ?? '');
            if (!class_exists($class, false) && $file !== '') {
                if (!is_file($file)) {
                    throw new \RuntimeException('Adapter "' . $name . '": file ' . $file . ' not found.');
                }
                require_once $file;
            }
            if ($class === '' || !class_exists($class)) {
                throw new \RuntimeException('Adapter "' . $name . '": class ' . ($class !== '' ? $class : '(none)') . ' not found.');
            }

            return $class;
        }

        $base = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', strtolower($name)))) . 'Adapter';
        $class = self::BUILT_IN[$name] ?? 'DeskOutbox\\Adapters\\' . $base;
        if (class_exists($class, false)) {
            return $class;
        }
        $paths = isset(self::BUILT_IN[$name]) ? [dirname(__DIR__) . '/adapters', ...$this->paths] : $this->paths;
        foreach ($paths as $path) {
            $file = rtrim($path, '/') . '/' . $base . '.php';
            if (is_file($file)) {
                require_once $file;
                if (class_exists($class, false)) {
                    return $class;
                }
            }
        }
        if (class_exists($class)) {
            return $class;
        }

        throw new \RuntimeException('Unknown adapter "' . $name . '": no ' . $base . '.php in ' . implode(', ', $paths) . ' and no entry in "adapters".');
    }
}
