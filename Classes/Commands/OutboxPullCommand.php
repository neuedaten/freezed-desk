<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;

/**
 * `freezed-desk outbox:pull [--dry-run]` — see OutboxCommand.
 */
class OutboxPullCommand extends AbstractCommand
{
    public function run(DeskContext $context, array $args, array $options): int
    {
        self::printJson(OutboxCommand::outbox($context)->pull(self::isDryRun($options)));

        return 0;
    }
}
