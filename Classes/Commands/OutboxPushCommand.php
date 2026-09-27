<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;

/**
 * `freezed-desk outbox:push [--dry-run]` — see OutboxCommand. Exit code 1
 * when a message could not be pushed.
 */
class OutboxPushCommand extends AbstractCommand
{
    public function run(DeskContext $context, array $args, array $options): int
    {
        $summary = OutboxCommand::outbox($context)->push(self::isDryRun($options));
        self::printJson($summary);

        return $summary['errors'] === [] && $summary['problems'] === [] ? 0 : 1;
    }
}
