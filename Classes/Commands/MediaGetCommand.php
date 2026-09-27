<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Media\Media;

/**
 * `freezed-desk media:get <id|file>` — one file of the library as JSON:
 * metadata, extra fields (A7), origin, the absolute path and the records
 * that use it.
 */
class MediaGetCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $media = self::media($context, $args[0] ?? throw new DeskException('Usage: freezed-desk media:get <id|file>'));
        self::printJson(self::describe($context, $media));

        return 0;
    }

    /** A file by id or by its path below the media root. */
    public static function media(DeskContext $context, string $reference): Media
    {
        $reference = trim($reference);
        $media = ctype_digit($reference) ? $context->media()->get((int) $reference) : $context->media()->findByFile($reference);

        return $media ?? throw new NotFoundException(sprintf('No media "%s" in the library (media:list shows them).', $reference));
    }

    /** @return array<string, mixed> */
    public static function describe(DeskContext $context, Media $media): array
    {
        $usages = [];
        foreach ($context->media()->usages($media->id) as $usage) {
            $usages[] = ['type' => $usage['item']->type, 'slug' => $usage['item']->slug, 'title' => $usage['item']->title, 'field' => $usage['field']];
        }

        return $media->toExport() + [
            'id' => $media->id,
            'path' => $context->media()->absolutePath($media),
            'usedBy' => $usages,
        ];
    }
}
