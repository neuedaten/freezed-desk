<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\FreezedDesk\Cli;

/**
 * Options that may be given more than once (--where:a=1 --where:b=2). The
 * parsed options keep only the last value, so these are read from the raw
 * tokens: the core keeps them in [cli][optionArgs], the freezed-desk binary
 * in Cli::$argv.
 */
final class Options
{
    /**
     * @param array<string, mixed> $options The parsed options, as fallback.
     * @return string[]
     */
    public static function all(string $name, array $options = []): array
    {
        $tokens = ConfigService::getInstance()->getValue('[cli][optionArgs]');
        if (!is_array($tokens) || $tokens === []) {
            $tokens = Cli::$argv;
        }

        $values = [];
        foreach ($tokens as $token) {
            if (!is_string($token) || !str_starts_with($token, '--' . $name)) {
                continue;
            }
            $rest = substr($token, strlen($name) + 2);
            if ($rest !== '' && ($rest[0] === ':' || $rest[0] === '=')) {
                $values[] = substr($rest, 1);
            }
        }
        if ($values === [] && isset($options[$name]) && is_string($options[$name])) {
            $values[] = $options[$name];
        }

        return $values;
    }

    /** A flag or a value that means yes (--dry-run, --dry-run:1). */
    public static function flag(array $options, string $name): bool
    {
        $value = $options[$name] ?? false;

        return $value === true || in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }
}
