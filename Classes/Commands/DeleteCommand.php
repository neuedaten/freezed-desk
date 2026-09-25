<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk delete <type>/<slug>` — remove a record for good (its
 * revisions included; media stays).
 */
class DeleteCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk delete <type>/<slug>'));
        $item = GetCommand::find($context, $type, $slug);
        $context->repository()->delete($item->id);
        self::printJson(['deleted' => true, 'type' => $item->type, 'slug' => $item->slug, 'id' => $item->id]);

        return 0;
    }
}
