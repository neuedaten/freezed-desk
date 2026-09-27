<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\Actions\ActionRunner;
use Neuedaten\FreezedDesk\DeskContext;

/**
 * `freezed-desk actions` — the global actions (desk.actions) and the record
 * actions of every type, with their last run (A1.8).
 */
class ActionsCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $global = [];
        foreach ($context->config->actions() as $name => $command) {
            $last = json_decode((string) $context->repository()->setting('action:' . $name, ''), true);
            $global[] = ['name' => $name, 'command' => $command, 'last' => is_array($last) ? $last : null];
        }
        $records = [];
        $runner = new ActionRunner($context);
        foreach ($context->schemas()->all() as $schema) {
            foreach ($runner->recordActions($schema) as $name => $action) {
                $records[] = ['type' => $schema->slug, 'name' => $name] + $action;
            }
        }
        self::printJson(['actions' => $global, 'recordActions' => $records]);

        return 0;
    }
}
