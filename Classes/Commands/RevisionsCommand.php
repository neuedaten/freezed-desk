<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk revisions <type>/<slug>` — the states of a record, newest
 * first (A1.3): the current one, then every kept earlier one, each with its
 * revision number, who produced it (actor) and when.
 */
class RevisionsCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk revisions <type>/<slug>'));
        $item = GetCommand::find($context, $type, $slug);

        $states = [[
            'revision' => $item->revision,
            'actor' => $item->updatedBy,
            'savedAt' => $item->updatedAt,
            'status' => $item->status->value,
            'current' => true,
        ]];
        foreach ($context->repository()->revisions($item->id) as $revision) {
            $states[] = [
                'revision' => $revision['number'],
                'actor' => $revision['actor'],
                'savedAt' => $revision['savedAt'],
                'status' => (string) ($revision['data']['status'] ?? ''),
                'current' => false,
                'replacedAt' => $revision['createdAt'],
                'replacedBy' => $revision['note'],
                'id' => $revision['id'],
            ];
        }
        self::printJson(['type' => $item->type, 'slug' => $item->slug, 'revisions' => $states]);

        return 0;
    }
}
