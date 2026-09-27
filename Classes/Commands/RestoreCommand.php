<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk restore <type>/<slug> <n> [--if-revision:<m>] [--dry-run]`
 * — bring back an earlier state (A1.3). This is a new change on top, the
 * current state stays in the revisions. A type with approval: 'ui' is not
 * published by a restore from the CLI.
 */
class RestoreCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk restore <type>/<slug> <n>'));
        $number = $args[1] ?? throw new DeskException('Usage: freezed-desk restore <type>/<slug> <n>');
        $item = GetCommand::find($context, $type, $slug);
        $revision = RevisionCommand::find($context, $item, $number);
        $ifRevision = isset($options['if-revision']) && is_numeric($options['if-revision']) ? (int) $options['if-revision'] : null;

        $result = self::change($context, $options, static function () use ($context, $item, $revision, $ifRevision): array {
            $restored = $context->repository()->restoreRevision($item->id, $revision['id'], $ifRevision);

            return GetCommand::portable($context, $restored) + ['restoredFrom' => $revision['number'], 'notices' => $context->repository()->notices()];
        });
        if (self::isDryRun($options)) {
            $result['dryRun'] = true;
        }
        self::printJson($result);

        return 0;
    }
}
