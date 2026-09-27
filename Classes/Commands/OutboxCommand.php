<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Outbox\Outbox;

/**
 * `freezed-desk outbox push|pull|status [--dry-run]` (docs/outbox.md, B2):
 *
 *   push    new and changed messages of approved records to the server,
 *           withdraw the ones no longer approved. With desk.outbox.push
 *           "ui" (the default) only the "Senden" button in the UI pushes;
 *           the CLI may show what would go out with --dry-run.
 *   pull    results from the server into the records (system fields only).
 *           Safe to run from launchd every 15 minutes.
 *   status  every message Desk knows, with its state; --remote asks the
 *           server for its view as well.
 */
class OutboxCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $sub = $args[0] ?? 'status';

        return match ($sub) {
            'push' => (new OutboxPushCommand())->run($context, array_slice($args, 1), $options),
            'pull' => (new OutboxPullCommand())->run($context, array_slice($args, 1), $options),
            'status' => (new OutboxStatusCommand())->run($context, array_slice($args, 1), $options),
            default => throw new DeskException('Usage: freezed-desk outbox push|pull|status [--dry-run]'),
        };
    }

    /** @var (\Closure(): \Neuedaten\FreezedDesk\Outbox\OutboxTransport)|null Tests hand requests to the server module. */
    public static ?\Closure $transport = null;

    public static function outbox(DeskContext $context): Outbox
    {
        return new Outbox($context, self::$transport === null ? null : (self::$transport)());
    }
}
