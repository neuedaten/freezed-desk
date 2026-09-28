<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Export\Portable;

/**
 * `freezed-desk export` — one JSON file per record below data/export/<type>/
 * plus data/export/media.json, deterministic and therefore diff-able. The
 * review history of a record goes to data/export/_reviews/<type>/<slug>.json.
 * Files of records that no longer exist are removed; anything else in the
 * folder is left alone.
 */
class ExportCommand extends AbstractCommand
{
    /** Sub-folder of the export for review histories; no type can be named so. */
    public const REVIEWS = '_reviews';

    protected function answersInJson(): bool
    {
        return false;
    }

    protected function run(DeskContext $context, array $args, array $options): int
    {
        $log = LogService::getInstance();
        $portable = new Portable($context);
        $root = $context->config->exportPath();

        if (!is_dir($root) && !@mkdir($root, 0777, true) && !is_dir($root)) {
            throw new DeskException('Could not create ' . $root . '.');
        }

        $count = 0;
        $reviewCount = 0;
        foreach ($context->schemas()->all() as $schema) {
            $directory = $root . '/' . $schema->slug;
            if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new DeskException('Could not create ' . $directory . '.');
            }

            $written = [];
            $reviewDirectory = $root . '/' . self::REVIEWS . '/' . $schema->slug;
            $reviewsWritten = [];
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

                $reviews = $context->reviews()->exportItem($item);
                if ($reviews !== []) {
                    if (!is_dir($reviewDirectory) && !@mkdir($reviewDirectory, 0777, true) && !is_dir($reviewDirectory)) {
                        throw new DeskException('Could not create ' . $reviewDirectory . '.');
                    }
                    $reviewFile = $reviewDirectory . '/' . str_replace('/', '__', $item->slug) . '.json';
                    self::writeJson($reviewFile, ['type' => $item->type, 'slug' => $item->slug, 'reviews' => $reviews]);
                    $reviewsWritten[$reviewFile] = true;
                    $reviewCount += count($reviews);
                }
            }

            foreach (array_merge(glob($directory . '/*.json') ?: [], glob($reviewDirectory . '/*.json') ?: []) as $stale) {
                if (!isset($written[$stale]) && !isset($reviewsWritten[$stale])) {
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

        $log->success(sprintf('Exported %d record%s, %d media entr%s and %d review%s to %s', $count, $count === 1 ? '' : 's', count($media), count($media) === 1 ? 'y' : 'ies', $reviewCount, $reviewCount === 1 ? '' : 's', $root));

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
