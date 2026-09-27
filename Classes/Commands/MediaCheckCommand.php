<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;

/**
 * `freezed-desk media:check` — compare the media library with the media
 * folder. --adopt adds files without a record to the library, --prune
 * removes records whose file is gone.
 */
class MediaCheckCommand extends AbstractCommand
{
    protected function answersInJson(): bool
    {
        return false;
    }

    protected function run(DeskContext $context, array $args, array $options): int
    {
        $context->config->validateMediaRoot();
        $log = LogService::getInstance();
        $media = $context->media();

        $result = $media->check();

        foreach ($result['missing'] as $entry) {
            $log->warning(sprintf('Missing file: %s (media #%d, %s)', $entry->file, $entry->id, $entry->originalName));
            if (!empty($options['prune'])) {
                $media->delete($entry->id, force: true);
                $log->notice('  removed record #' . $entry->id);
            }
        }

        foreach ($result['orphans'] as $file) {
            $log->warning('File without record: ' . $file);
            if (!empty($options['adopt'])) {
                $stored = $media->store($media->root() . '/' . $file, basename($file), [], move: false, origin: 'import');
                $log->notice('  adopted as media #' . $stored->id . ' (' . $stored->file . ')');
                if ($stored->file !== $file) {
                    @unlink($media->root() . '/' . $file);
                }
            }
        }

        foreach ($result['unused'] as $entry) {
            $log->info(sprintf('Unused: %s (media #%d)', $entry->file, $entry->id));
        }

        $log->success(sprintf(
            '%d media entries, %d missing, %d orphaned, %d unused (run with --verbose to list unused).',
            $media->count(),
            count($result['missing']),
            count($result['orphans']),
            count($result['unused'])
        ));

        return 0;
    }
}
