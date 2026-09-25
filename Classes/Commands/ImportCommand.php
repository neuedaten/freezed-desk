<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * `freezed-desk import` — read data/export/ back. Records are matched by
 * type and slug and updated in place; relations are resolved in a second
 * pass, so the order of files does not matter.
 */
class ImportCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $log = LogService::getInstance();
        $root = $args[0] ?? $context->config->exportPath();

        if (!is_dir($root)) {
            throw new DeskException('Export folder not found: ' . $root);
        }

        $mediaFile = $root . '/media.json';
        $mediaCount = 0;
        if (is_file($mediaFile)) {
            foreach (self::readJson($mediaFile) as $entry) {
                if (is_array($entry)) {
                    $context->media()->importRow($entry);
                    $mediaCount++;
                }
            }
        }

        $records = [];
        foreach ($context->schemas()->all() as $schema) {
            foreach (glob($root . '/' . $schema->slug . '/*.json') ?: [] as $file) {
                $record = self::readJson($file);
                if (!is_array($record) || !isset($record['slug'])) {
                    $log->warning('Skipped ' . $file . ': not a record.');
                    continue;
                }
                $records[] = [$schema, $record, $file];
            }
        }

        $imported = 0;
        $failed = 0;

        // Pass 1: everything but relations, so every slug exists.
        // Pass 2: relations.
        foreach ([false, true] as $relationPass) {
            foreach ($records as [$schema, $record, $file]) {
                /** @var TypeSchema $schema */
                $fields = [];
                foreach ((array) ($record['fields'] ?? []) as $name => $value) {
                    $field = $schema->field((string) $name);
                    if ($field === null || (($field->type === 'relation') !== $relationPass)) {
                        continue;
                    }
                    $fields[$name] = $value;
                }

                $existing = $context->repository()->findBySlug($schema->slug, (string) $record['slug']);
                try {
                    $context->repository()->save($schema->slug, [
                        'slug' => (string) $record['slug'],
                        'variant' => $record['variant'] ?? null,
                        'status' => $relationPass ? ($record['status'] ?? null) : 'draft',
                        'sort' => $record['sort'] ?? null,
                        'fields' => $fields,
                    ], $existing?->id, 'import', [
                        'createdAt' => $record['createdAt'] ?? null,
                        'updatedAt' => $record['updatedAt'] ?? null,
                        'publishedAt' => $record['publishedAt'] ?? null,
                    ], deferRelations: !$relationPass);
                    if (!$relationPass) {
                        $imported++;
                    }
                } catch (ValidationException $exception) {
                    $failed++;
                    $log->error(sprintf('%s: %s', self::relative($file, $root), $exception->getMessage()));
                }
            }
        }

        $log->success(sprintf('Imported %d record%s and %d media entr%s from %s%s', $imported, $imported === 1 ? '' : 's', $mediaCount, $mediaCount === 1 ? 'y' : 'ies', $root, $failed > 0 ? ' (' . $failed . ' failed)' : ''));

        return $failed > 0 ? 1 : 0;
    }

    public static function readJson(string $file): mixed
    {
        try {
            return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DeskException($file . ' is not valid JSON: ' . $exception->getMessage());
        }
    }

    private static function relative(string $file, string $root): string
    {
        return str_starts_with($file, $root) ? ltrim(substr($file, strlen($root)), '/') : $file;
    }
}
