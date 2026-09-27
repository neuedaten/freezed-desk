<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Media\Media;

/**
 * `freezed-desk media:prune --generated [--orphaned] [--older-than:90d]
 * [--dry-run]` — delete generated files whose record is gone or archived
 * (A8.4). Uploads are never pruned. --orphaned is the default and the only
 * mode for now, spelled out so the command reads like what it does.
 */
class MediaPruneCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        if (!Options::flag($options, 'generated')) {
            throw new DeskException('media:prune only removes generated files: freezed-desk media:prune --generated --orphaned [--older-than:90d] [--dry-run]');
        }
        $days = null;
        if (isset($options['older-than'])) {
            if (!preg_match('/^(\d+)d?$/', (string) $options['older-than'], $m)) {
                throw new DeskException('--older-than: takes days, e.g. 90d.');
            }
            $days = (int) $m[1];
        }

        $pruned = $context->media()->pruneGenerated(true, $days, self::isDryRun($options));
        self::printJson([
            'pruned' => array_map(static fn (Media $m): array => ['id' => $m->id, 'file' => $m->file, 'generatedBy' => $m->generatedBy], $pruned),
            'count' => count($pruned),
            'dryRun' => self::isDryRun($options),
        ]);

        return 0;
    }
}
