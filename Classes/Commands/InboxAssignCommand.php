<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk inbox:assign <id> <type>/<slug>|none [--dry-run]` — link a
 * submission to the record it is about (A1.7).
 */
class InboxAssignCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $id = $args[0] ?? throw new DeskException('Usage: freezed-desk inbox:assign <id> <type>/<slug>|none');
        $target = $args[1] ?? throw new DeskException('Usage: freezed-desk inbox:assign <id> <type>/<slug>|none');
        if (!ctype_digit($id)) {
            throw new DeskException('The inbox id is a number (inbox:list shows them).');
        }
        $itemId = null;
        if ($target !== 'none') {
            [$type, $slug] = self::target($target);
            $itemId = GetCommand::find($context, $type, $slug)->id;
        }

        $entry = self::change($context, $options, static fn (): array => InboxShowCommand::describe($context, $context->inbox()->update((int) $id, ['itemId' => $itemId]), full: true));
        self::printJson($entry + ['dryRun' => self::isDryRun($options)]);

        return 0;
    }
}
