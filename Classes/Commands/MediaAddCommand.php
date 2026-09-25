<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk media:add <file>` — copy a file into the library and print
 * its record as JSON. Options: --alt:, --caption:, --credit:, --license:,
 * --focal:<x>,<y>. A file the library already holds (same content) is not
 * stored twice; its record is printed, with the given metadata applied.
 */
class MediaAddCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $file = $args[0] ?? throw new DeskException('Usage: freezed-desk media:add <file> [--alt:…]');
        if (!is_file($file)) {
            throw new DeskException('File not found: ' . $file);
        }

        $meta = [];
        foreach (['alt', 'caption', 'credit', 'license'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                $meta[$key] = $options[$key];
            }
        }
        if (isset($options['focal']) && preg_match('/^\s*([\d.]+)\s*,\s*([\d.]+)\s*$/', (string) $options['focal'], $m)) {
            $meta['focal'] = ['x' => (float) $m[1], 'y' => (float) $m[2]];
        }

        $media = $context->media()->store($file, basename($file), $meta, move: false);
        if ($meta !== []) {
            $media = $context->media()->update($media->id, $meta);
        }

        self::printJson($media->toVariables() + ['file' => $media->file]);

        return 0;
    }
}
