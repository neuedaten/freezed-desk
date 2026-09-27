<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk media:update <id|file> [--alt:] [--caption:] [--credit:]
 * [--license:] [--focal:x,y|none] [--extra:'{"socialOk": true}'] [--dry-run]`
 * — change the metadata of a file (A1.4). Only the given options change.
 */
class MediaUpdateCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $media = MediaGetCommand::media($context, $args[0] ?? throw new DeskException('Usage: freezed-desk media:update <id|file> [--alt:] [--extra:{…}]'));

        $meta = [];
        foreach (['alt', 'caption', 'credit', 'license'] as $key) {
            if (isset($options[$key])) {
                $meta[$key] = $options[$key] === true ? '' : (string) $options[$key];
            }
        }
        if (isset($options['focal'])) {
            if (preg_match('/^\s*([\d.]+)\s*,\s*([\d.]+)\s*$/', (string) $options['focal'], $m)) {
                $meta['focal'] = ['x' => (float) $m[1], 'y' => (float) $m[2]];
            } elseif (in_array((string) $options['focal'], ['none', ''], true)) {
                $meta['focal'] = null;
            } else {
                throw new DeskException('--focal: takes "x,y" (0..1 each) or "none".');
            }
        }
        if (isset($options['extra'])) {
            try {
                $extra = json_decode((string) $options['extra'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new DeskException('--extra: must be a JSON object: ' . $exception->getMessage());
            }
            if (!is_array($extra)) {
                throw new DeskException('--extra: must be a JSON object, e.g. --extra:\'{"socialOk": true}\'.');
            }
            $meta['extra'] = $extra;
        }
        if ($meta === []) {
            throw new DeskException('Nothing to change: give --alt:, --caption:, --credit:, --license:, --focal: or --extra:.');
        }

        $result = self::change($context, $options, static fn (): array => MediaGetCommand::describe($context, $context->media()->update($media->id, $meta)));
        if (self::isDryRun($options)) {
            $result['dryRun'] = true;
        }
        self::printJson($result);

        return 0;
    }
}
