<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Export\PreviewUrl;

/**
 * `freezed-desk preview-url <type>/<slug>` — the address of the built page
 * as `freezed serve` serves it (A1.10); null for a type that is not built.
 */
class PreviewUrlCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk preview-url <type>/<slug>'));
        $item = GetCommand::find($context, $type, $slug);
        self::printJson(['type' => $item->type, 'slug' => $item->slug, 'url' => (new PreviewUrl($context))->of($item), 'published' => $item->isPublished()]);

        return 0;
    }
}
