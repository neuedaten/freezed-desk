<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk media:usage <id|file>` — the records that use a file.
 */
class MediaUsageCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $media = MediaGetCommand::media($context, $args[0] ?? throw new DeskException('Usage: freezed-desk media:usage <id|file>'));
        self::printJson(['file' => $media->file, 'id' => $media->id, 'usedBy' => MediaGetCommand::describe($context, $media)['usedBy']]);

        return 0;
    }
}
