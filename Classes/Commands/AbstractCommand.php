<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\Cli;
use Neuedaten\FreezedDesk\DeskContext;

/**
 * Boots the desk context from the core's configuration (which is loaded
 * whether the command came in through `freezed` or `freezed-desk`), then
 * runs.
 */
abstract class AbstractCommand implements CommandInterface
{
    public function execute(array $args, array $options): int
    {
        $context = Cli::ensureBooted($options);

        return $this->run($context, $args, $options);
    }

    /**
     * @param string[]             $args
     * @param array<string, mixed> $options
     */
    abstract protected function run(DeskContext $context, array $args, array $options): int;

    /**
     * "entries/seeblick" → ['entries', 'seeblick'], "entries" → ['entries', null].
     *
     * @return array{0: string, 1: string|null}
     */
    protected static function target(string $argument): array
    {
        [$type, $slug] = array_pad(explode('/', trim($argument, '/'), 2), 2, null);

        return [(string) $type, $slug === '' ? null : $slug];
    }

    protected static function printJson(mixed $data): void
    {
        fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
