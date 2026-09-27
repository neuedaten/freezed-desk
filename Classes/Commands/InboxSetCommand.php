<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Inbox\InboxRepository;

/**
 * `freezed-desk inbox:set <id> [--status:new|open|done|spam] [--note:…]
 * [--dry-run]` — handle a submission (A1.7).
 */
class InboxSetCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $id = $args[0] ?? throw new DeskException('Usage: freezed-desk inbox:set <id> [--status:] [--note:]');
        if (!ctype_digit($id)) {
            throw new DeskException('The inbox id is a number (inbox:list shows them).');
        }
        $changes = [];
        if (isset($options['status'])) {
            if (!in_array($options['status'], InboxRepository::STATUSES, true)) {
                throw new DeskException(sprintf('--status: takes %s.', implode(', ', InboxRepository::STATUSES)));
            }
            $changes['status'] = $options['status'];
        }
        if (isset($options['note'])) {
            $changes['note'] = $options['note'] === true ? '' : (string) $options['note'];
        }
        if ($changes === []) {
            throw new DeskException('Nothing to change: give --status: or --note:.');
        }

        $entry = self::change($context, $options, static fn (): array => InboxShowCommand::describe($context, $context->inbox()->update((int) $id, $changes), full: true));
        self::printJson($entry + ['dryRun' => self::isDryRun($options)]);

        return 0;
    }
}
