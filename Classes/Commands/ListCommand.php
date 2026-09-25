<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * `freezed-desk list <type>` — the records of a type as JSON:
 * {id, slug, title, status, variant, updatedAt}. Options: --status:draft|
 * published|archived|all (default: not archived), --q:<search>,
 * --limit:<n>, --offset:<n>.
 */
class ListCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $type = $args[0] ?? throw new DeskException('Usage: freezed-desk list <type> [--status:] [--q:] [--limit:]');
        $context->schemas()->get($type);

        $query = $context->repository()->find($type)->ordered();
        $status = (string) ($options['status'] ?? '');
        match ($status) {
            'draft', 'published', 'archived' => $query->withStatus($status),
            'all' => $query->anyStatus(),
            default => $query->notArchived(),
        };
        if (isset($options['q'])) {
            $query->search((string) $options['q']);
        }
        if (isset($options['limit'])) {
            $query->limit((int) $options['limit']);
        }
        if (isset($options['offset'])) {
            $query->offset((int) $options['offset']);
        }

        self::printJson([
            'type' => $type,
            'records' => array_map(static fn (Item $item): array => [
                'id' => $item->id,
                'slug' => $item->slug,
                'title' => $item->title,
                'status' => $item->status->value,
                'variant' => $item->variant,
                'updatedAt' => $item->updatedAt,
            ], $query->all()),
        ]);

        return 0;
    }
}
