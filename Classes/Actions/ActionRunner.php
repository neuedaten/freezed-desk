<?php

namespace Neuedaten\FreezedDesk\Actions;

use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Database;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * Runs the shell commands behind actions: the global ones from
 * desk.actions and the record actions of a schema (A9). The UI streams the
 * output to the browser, the CLI to stdout; both get the exit code.
 *
 * A command that starts with "desk:" (or another registered command of the
 * core) runs through the project's freezed binary with the PHP that runs
 * Desk, so "desk:social:render posts/{slug}" needs no path. Placeholders
 * of record actions: {type}, {slug}, {id}, {ref} (= type/slug), each
 * shell-escaped.
 */
final class ActionRunner
{
    public function __construct(private readonly DeskContext $context)
    {
    }

    /**
     * The global action's command line.
     *
     * @throws NotFoundException
     */
    public function globalCommand(string $name): string
    {
        $command = $this->context->config->actions()[$name] ?? throw new NotFoundException(sprintf('No action "%s" (desk.actions).', $name));

        return $this->expand($command);
    }

    /**
     * Record actions of a type that apply to the record ("when").
     *
     * @return array<string, array{label: string, command: string, bulk: bool}>
     */
    public function recordActions(TypeSchema $schema, ?Item $item = null): array
    {
        $actions = [];
        foreach ($schema->actions as $name => $action) {
            if ($item !== null && $action['when'] !== null && !($action['when'])($item)) {
                continue;
            }
            $actions[$name] = ['label' => $action['label'], 'command' => $action['command'], 'bulk' => $action['bulk']];
        }

        return $actions;
    }

    /**
     * A record action's command line for one record.
     *
     * @throws NotFoundException When the type has no such action or it does not apply.
     */
    public function recordCommand(Item $item, string $name): string
    {
        $schema = $this->context->schemas()->get($item->type);
        $action = $this->recordActions($schema, $item)[$name]
            ?? throw new NotFoundException(sprintf('Type "%s" has no action "%s" for %s.', $item->type, $name, $item->slug));

        return $this->expand(strtr($action['command'], [
            '{type}' => escapeshellarg($item->type),
            '{slug}' => escapeshellarg($item->slug),
            '{id}' => (string) $item->id,
            '{ref}' => escapeshellarg($item->type . '/' . $item->slug),
        ]));
    }

    /**
     * Run a command line from the project root. $write receives the output
     * as it comes; the exit code is returned and remembered as the last run
     * of $record (a settings key), when given.
     *
     * @param callable(string): void $write
     */
    public function run(string $command, callable $write, ?string $record = null): int
    {
        $projectRoot = $this->context->config->projectRoot;
        $started = microtime(true);

        $process = proc_open(
            $command . ' 2>&1',
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w']],
            $pipes,
            $projectRoot,
            array_merge(getenv(), ['FREEZED_ROOT' => $projectRoot, 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'])
        );
        if (!is_resource($process)) {
            $write("Could not start the command.\n");

            return 1;
        }

        stream_set_blocking($pipes[1], false);
        $status = ['running' => true, 'exitcode' => -1];
        while (true) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $write($chunk);
                continue;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $rest = stream_get_contents($pipes[1]);
                if (is_string($rest) && $rest !== '') {
                    $write($rest);
                }
                break;
            }
            usleep(50000);
        }
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = (int) ($status['exitcode'] ?? 1);
        }

        if ($record !== null && !$this->context->readOnly) {
            $this->context->repository()->setSetting($record, (string) json_encode([
                'at' => Database::now(),
                'exit' => $exitCode,
                'seconds' => round(microtime(true) - $started, 1),
            ]));
        }

        return $exitCode;
    }

    /** "desk:…" and other registered core commands run through the freezed binary. */
    private function expand(string $command): string
    {
        $trimmed = ltrim($command);
        $name = strtok($trimmed, " \t") ?: '';
        if ($name === 'desk' || str_starts_with($name, 'desk:')) {
            $cli = ConfigService::getInstance()->getValue('[cli]');
            $binary = is_array($cli) && is_string($cli['bin'] ?? null) ? $cli['bin'] : 'vendor/bin/freezed';
            $php = is_array($cli) && is_string($cli['php'] ?? null) ? $cli['php'] : PHP_BINARY;

            return escapeshellarg($php) . ' ' . escapeshellarg($binary) . ' ' . $trimmed;
        }

        return $command;
    }
}
