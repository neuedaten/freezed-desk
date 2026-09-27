<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk delete <type>/<slug> [<type>/<slug> …]` or
 * `delete <type> --where:…` — remove records for good (their revisions
 * included; media stays). --dry-run lists what would go.
 */
class DeleteCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        if ($args === []) {
            throw new DeskException('Usage: freezed-desk delete <type>/<slug> [<type>/<slug> …] | <type> --where:field=value [--dry-run]');
        }
        $items = self::targets($context, $args, $options);

        self::change($context, $options, static function () use ($context, $items): void {
            foreach ($items as $item) {
                $context->repository()->delete($item->id);
            }
        });

        $deleted = array_map(static fn ($item): array => ['type' => $item->type, 'slug' => $item->slug, 'id' => $item->id], $items);
        if (count($deleted) === 1) {
            self::printJson(['deleted' => !self::isDryRun($options)] + $deleted[0] + (self::isDryRun($options) ? ['dryRun' => true] : []));
        } else {
            self::printJson(['deleted' => self::isDryRun($options) ? [] : $deleted, 'wouldDelete' => self::isDryRun($options) ? $deleted : [], 'dryRun' => self::isDryRun($options)]);
        }

        return 0;
    }
}
