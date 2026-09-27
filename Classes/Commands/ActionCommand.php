<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\Cli;
use Neuedaten\FreezedDesk\Actions\ActionRunner;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk action <name>` runs a global action (desk.actions),
 * `freezed-desk action <type>/<slug> <name>` a record action of the schema
 * (A1.8, A9). The command's output goes to stdout as it comes, its exit
 * code is this command's exit code.
 */
class ActionCommand extends AbstractCommand
{
    protected function answersInJson(): bool
    {
        return false;
    }

    protected function run(DeskContext $context, array $args, array $options): int
    {
        $runner = new ActionRunner($context);
        $write = static function (string $chunk): void {
            fwrite(Cli::out(), $chunk);
        };

        if (count($args) >= 2) {
            [$type, $slug] = self::target($args[0]);
            $item = GetCommand::find($context, $type, $slug);

            return $runner->run($runner->recordCommand($item, $args[1]), $write);
        }

        $name = $args[0] ?? throw new DeskException('Usage: freezed-desk action <name> | action <type>/<slug> <name>');
        $exitCode = $runner->run($runner->globalCommand($name), $write, 'action:' . $name);
        if ($name === 'build') {
            $context->repository()->setSetting('lastBuild', (string) $context->repository()->setting('action:build', ''));
        }

        return $exitCode;
    }
}
