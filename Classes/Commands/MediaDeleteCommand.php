<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk media:delete <id|file> [--force] [--dry-run]` — remove a
 * file and its record. Refused while a record uses it, unless --force.
 */
class MediaDeleteCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $media = MediaGetCommand::media($context, $args[0] ?? throw new DeskException('Usage: freezed-desk media:delete <id|file> [--force]'));
        $described = MediaGetCommand::describe($context, $media);

        self::change($context, $options, static fn () => $context->media()->delete($media->id, force: Options::flag($options, 'force')));
        self::printJson(['deleted' => !self::isDryRun($options), 'file' => $media->file, 'id' => $media->id, 'usedBy' => $described['usedBy'], 'dryRun' => self::isDryRun($options)]);

        return 0;
    }
}
