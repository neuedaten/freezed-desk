<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Media\Media;

/**
 * `freezed-desk media:list` — the library as JSON. Options: --q:<search>,
 * --kind:images|files, --limit:, --offset:.
 */
class MediaListCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $usage = $context->media()->usageCounts();
        $items = $context->media()->all(
            (string) ($options['q'] ?? ''),
            (string) ($options['kind'] ?? 'all'),
            (int) ($options['limit'] ?? 0),
            (int) ($options['offset'] ?? 0)
        );

        self::printJson([
            'media' => array_map(static fn (Media $m): array => [
                'id' => $m->id,
                'file' => $m->file,
                'name' => $m->originalName,
                'mime' => $m->mime,
                'width' => $m->width,
                'height' => $m->height,
                'alt' => $m->alt,
                'caption' => $m->caption,
                'credit' => $m->credit,
                'usedIn' => $usage[$m->id] ?? 0,
            ], $items),
        ]);

        return 0;
    }
}
