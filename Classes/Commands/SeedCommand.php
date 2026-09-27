<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Schema\Slugger;

/**
 * `freezed-desk seed <file>` — create or update records from a JSON file,
 * the quick way to fill a new project:
 *
 *     {
 *       "media": [
 *         {"key": "lake", "source": "images/lake.jpg", "alt": "Baldeneysee"}
 *       ],
 *       "items": [
 *         {"type": "spots", "slug": "baldeneysee", "status": "published",
 *          "fields": {"title": "Baldeneysee", "hero": {"media": "lake"}}},
 *         {"type": "entries", "fields": {"title": "Seeblick",
 *          "spot": {"type": "spots", "slug": "baldeneysee"}}}
 *       ]
 *     }
 *
 * "source" paths are relative to the seed file; the file is copied into the
 * media library. A field value {"media": "<key>"} refers to a media entry of
 * the same file, {"type", "slug"} to a record. Records are matched by type
 * and slug (the slug is derived from the title when missing), so a seed can
 * be run again. Relations are resolved in a second pass; a record whose
 * relations fail validation stays a draft. The file may also
 * be a plain list of items, or an object of type => items.
 */
class SeedCommand extends AbstractCommand
{
    protected function answersInJson(): bool
    {
        return false;
    }

    protected function run(DeskContext $context, array $args, array $options): int
    {
        // Restoring data, not an editorial change: approved records stay approved (A3).
        $context->actAs(Actor::Import);
        $file = $args[0] ?? throw new DeskException('Usage: freezed-desk seed <file.json>');
        if (!is_file($file)) {
            throw new DeskException('Seed file not found: ' . $file);
        }

        $context = DeskContext::get();
        $log = LogService::getInstance();
        $data = ImportCommand::readJson($file);
        $baseDirectory = dirname(realpath($file) ?: $file);

        [$mediaEntries, $items] = $this->split($data);

        // Media first, so fields can refer to them.
        $mediaKeys = [];
        foreach ($mediaEntries as $index => $entry) {
            $source = (string) ($entry['source'] ?? $entry['file'] ?? '');
            if ($source === '') {
                throw new DeskException('media[' . $index . '] needs a "source" path.');
            }
            $path = str_starts_with($source, '/') ? $source : $baseDirectory . '/' . $source;
            $key = (string) ($entry['key'] ?? basename($source));
            $existing = $context->media()->findByFile($source);
            if ($existing === null && !is_file($path)) {
                throw new DeskException('media "' . $key . '": file not found: ' . $path);
            }
            $media = $existing ?? $context->media()->store($path, basename($source), [
                'alt' => $entry['alt'] ?? '',
                'caption' => $entry['caption'] ?? '',
                'credit' => $entry['credit'] ?? '',
                'license' => $entry['license'] ?? '',
                'focal' => $entry['focal'] ?? null,
                'extra' => is_array($entry['extra'] ?? null) ? $entry['extra'] : [],
            ], move: false, origin: in_array($entry['origin'] ?? null, ['upload', 'import'], true) ? $entry['origin'] : 'upload');
            if ($existing !== null || isset($entry['alt'], $entry['caption'], $entry['credit'], $entry['license'], $entry['focal'])) {
                $media = $context->media()->update($media->id, array_intersect_key($entry, array_flip(['alt', 'caption', 'credit', 'license', 'focal'])));
            }
            $mediaKeys[$key] = $media->id;
        }

        $created = 0;
        $updated = 0;
        $failed = 0;
        $ids = [];

        foreach ([false, true] as $relationPass) {
            foreach ($items as $index => $item) {
                $type = (string) ($item['type'] ?? '');
                if ($type === '' || !$context->schemas()->has($type)) {
                    if (!$relationPass) {
                        $log->error('items[' . $index . ']: unknown type "' . $type . '".');
                        $failed++;
                    }
                    continue;
                }
                $schema = $context->schemas()->get($type);
                $rawFields = is_array($item['fields'] ?? null) ? $item['fields'] : array_diff_key($item, array_flip(['type', 'slug', 'variant', 'status', 'sort', 'id']));

                $fields = [];
                foreach ($rawFields as $name => $value) {
                    $field = $schema->field((string) $name);
                    if ($field === null) {
                        if (!$relationPass) {
                            $log->warning(sprintf('items[%d] (%s): unknown field "%s" ignored.', $index, $type, $name));
                        }
                        continue;
                    }
                    if (($field->type === 'relation') !== $relationPass) {
                        continue;
                    }
                    $fields[$name] = $this->resolveMedia($value, $mediaKeys);
                }

                $existingId = $ids[$index] ?? null;
                if ($existingId === null) {
                    // Match by slug: the given one, or the one the repository
                    // would derive from slugFrom / the title, so a seed
                    // without slugs can be run again without duplicates.
                    $slug = isset($item['slug']) ? (string) $item['slug'] : null;
                    if ($slug === null && !$schema->single) {
                        $source = $rawFields[$schema->slugFrom ?? ''] ?? $rawFields[$schema->titleField ?? ''] ?? null;
                        $slug = is_scalar($source) ? Slugger::slugify((string) $source) : null;
                    }
                    $existing = $schema->single
                        ? $context->repository()->findSingle($type)
                        : ($slug !== null && $slug !== '' ? $context->repository()->findBySlug($type, $slug) : null);
                    $existingId = $existing?->id;
                }

                try {
                    $saved = $context->repository()->save($type, [
                        'slug' => $item['slug'] ?? null,
                        'variant' => $item['variant'] ?? null,
                        // Pass 1 keeps everything a draft; the status is set in
                        // pass 2, once relations are in place and validated.
                        'status' => $relationPass ? ($item['status'] ?? 'draft') : 'draft',
                        'sort' => $item['sort'] ?? null,
                        'fields' => $fields,
                    ], $existingId, 'seed', null, deferRelations: !$relationPass);
                    $ids[$index] = $saved->id;
                    if (!$relationPass) {
                        $existingId === null ? $created++ : $updated++;
                    }
                } catch (ValidationException $exception) {
                    $failed++;
                    $log->error(sprintf('items[%d] (%s%s): %s', $index, $type, isset($item['slug']) ? '/' . $item['slug'] : '', $exception->getMessage()));
                }
            }
        }

        $log->success(sprintf(
            'Seed %s: %d created, %d updated, %d media%s',
            basename($file),
            $created,
            $updated,
            count($mediaKeys),
            $failed > 0 ? ', ' . $failed . ' failed' : ''
        ));

        return $failed > 0 ? 1 : 0;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function split(mixed $data): array
    {
        if (!is_array($data)) {
            throw new DeskException('The seed file must contain a JSON object or list.');
        }

        if (array_is_list($data)) {
            return [[], $data];
        }

        if (isset($data['items']) || isset($data['media'])) {
            return [(array) ($data['media'] ?? []), (array) ($data['items'] ?? [])];
        }

        // type => [items]
        $items = [];
        foreach ($data as $type => $list) {
            foreach ((array) $list as $item) {
                if (is_array($item)) {
                    $items[] = ['type' => (string) $type] + $item;
                }
            }
        }

        return [[], $items];
    }

    /** Replace {"media": "<key>"} with the media id, at any depth. */
    private function resolveMedia(mixed $value, array $mediaKeys): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (isset($value['media']) && is_string($value['media']) && count($value) === 1) {
            return $mediaKeys[$value['media']] ?? throw new DeskException('Unknown media key "' . $value['media'] . '" (declare it under "media").');
        }

        return array_map(fn (mixed $v): mixed => $this->resolveMedia($v, $mediaKeys), $value);
    }
}
