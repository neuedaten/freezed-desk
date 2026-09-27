<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Support;

use Neuedaten\FreezedDesk\Cli;

final class StdoutCapture
{
    /**
     * @param resource $stream
     * @param callable(): int $callback
     */
    public static function run($stream, callable $callback): int
    {
        $previous = Cli::$stdout;
        Cli::$stdout = $stream;
        try {
            return $callback();
        } finally {
            Cli::$stdout = $previous;
        }
    }
}
