<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Export\Portable;

/**
 * `freezed-desk export` — one JSON file per record below data/export/<type>/
 * plus data/export/media.json, deterministic and therefore diff-able. Files
 * of records that no longer exist are removed; anything else in the folder
 * is left alone.
 */
class ExportCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $log = LogService::getInstance();
        $portable = new Portable($context);
        $root = $context->config->exportPath();

        if (!is_dir($root) && !@mkdir($root, 0777, true) && !is_dir($root)) {
            throw new DeskException('Could not create ' . $root . '.');
        }

        $count = 0;
        foreach ($context->schemas()->all() as $schema) {
            $directory = $root . '/' . $schema->slug;
            if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new DeskException('Could not create ' . $directory . '.');
            }

            $written = [];
            foreach ($context->repository()->find($schema->slug)->anyStatus()->all() as $item) {
                $record = [
                    'type' => $item->type,
                    'slug' => $item->slug,
                    'variant' => $item->variant,
                    'status' => $item->status->value,
                    'sort' => $item->sort,
                    'createdAt' => $item->createdAt,
                    'updatedAt' => $item->updatedAt,
                    'publishedAt' => $item->publishedAt,
                    'fields' => $portable->fromStored($schema, $item->data),
                ];
                $file = $directory . '/' . str_replace('/', '__', $item->slug) . '.json';
                self::writeJson($file, $record);
                $written[$file] = true;
                $count++;
            }

            foreach (glob($directory . '/*.json') ?: [] as $stale) {
                if (!isset($written[$stale])) {
                    unlink($stale);
                    $log->info('Removed ' . $stale);
                }
            }
        }

        $media = [];
        foreach ($context->media()->all() as $entry) {
            $media[] = $entry->toExport();
        }
        usort($media, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));
        self::writeJson($root . '/media.json', $media);

        $log->success(sprintf('Exported %d record%s and %d media entr%s to %s', $count, $count === 1 ? '' : 's', count($media), count($media) === 1 ? 'y' : 'ies', $root));

        return 0;
    }

    public static function writeJson(string $file, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_get_contents($file) === $json) {
            return; // unchanged: keep the modification time for git and rsync
        }
        if (file_put_contents($file, $json) === false) {
            throw new DeskException('Could not write ' . $file . '.');
        }
    }
}
