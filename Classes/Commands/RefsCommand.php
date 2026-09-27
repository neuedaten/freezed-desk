<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk refs <type>/<slug>` — the records that reference this one
 * through a relation field (A1.2): {refs: [{type, slug, title, status, field}]}.
 */
class RefsCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk refs <type>/<slug>'));
        $item = GetCommand::find($context, $type, $slug);

        $refs = [];
        foreach ($context->repository()->referencing($item->id) as $reference) {
            $refs[] = [
                'type' => $reference['item']->type,
                'slug' => $reference['item']->slug,
                'title' => $reference['item']->title,
                'status' => $reference['item']->status->value,
                'field' => $reference['field'],
            ];
        }
        self::printJson(['type' => $item->type, 'slug' => $item->slug, 'refs' => $refs]);

        return 0;
    }
}
