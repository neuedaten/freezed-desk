<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk reorder <type> <slug> <slug> …` — the order of a type that
 * sorts by "sort" (A1.6), like drag and drop in the list. The named records
 * come first in the given order, the others keep their order behind them.
 */
class ReorderCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $type = array_shift($args) ?? throw new DeskException('Usage: freezed-desk reorder <type> <slug> <slug> …');
        $schema = $context->schemas()->get($type);
        if (array_key_first($schema->orderBy) !== 'sort') {
            throw new DeskException(sprintf('Type "%s" is ordered by %s, not by "sort"; reordering would have no effect.', $type, array_key_first($schema->orderBy)));
        }
        if ($args === []) {
            throw new DeskException('Name the records in their new order: freezed-desk reorder ' . $type . ' <slug> <slug> …');
        }

        $ids = [];
        foreach ($args as $slug) {
            $ids[] = GetCommand::find($context, $type, $slug)->id;
        }
        foreach ($context->repository()->find($type)->anyStatus()->ordered()->all() as $item) {
            if (!in_array($item->id, $ids, true)) {
                $ids[] = $item->id;
            }
        }

        $order = self::change($context, $options, static function () use ($context, $type, $ids): array {
            $context->repository()->reorder($ids);

            return array_map(static fn ($item): string => $item->slug, $context->repository()->find($type)->anyStatus()->ordered()->all());
        });
        self::printJson(['type' => $type, 'order' => $order, 'dryRun' => self::isDryRun($options)]);

        return 0;
    }
}
