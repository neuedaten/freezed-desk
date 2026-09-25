<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk put <type>[/<slug>]` — create or update a record from JSON
 * on stdin (or --file:<path>):
 *
 *     {"status": "published", "variant": "plus",
 *      "fields": {"title": "Seeblick", "spot": {"type": "spots", "slug": "baldeneysee"},
 *                 "hero": {"file": "2026/09/lake-2fa16935.jpg"}}}
 *
 * Only the given fields change; the record `get` printed can be edited and
 * sent back as it is. Without a slug in the path, one is taken from the
 * JSON or derived from the title (a new record). A slug in the path that
 * no record has creates the record with that slug. Prints the saved record
 * as `get` would; validation errors come as {"error", "errors"} with exit
 * code 1.
 */
class PutCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk put <type>[/<slug>] < record.json'));
        $schema = $context->schemas()->get($type);

        $json = isset($options['file']) && is_string($options['file'])
            ? (string) @file_get_contents($options['file'])
            : (string) stream_get_contents(STDIN);
        if (trim($json) === '') {
            throw new DeskException('No JSON given: pipe the record to stdin or pass --file:<path>.');
        }
        try {
            $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DeskException('Invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($record)) {
            throw new DeskException('The JSON must be an object.');
        }

        // A bare fields object is accepted as well; standard keys that are
        // not fields of the type are taken as the record's settings.
        if (array_key_exists('fields', $record)) {
            $fields = $record['fields'];
        } else {
            $fields = [];
            foreach ($record as $key => $value) {
                if ($schema->hasField((string) $key) || !in_array($key, ['id', 'type', 'title', 'slug', 'variant', 'status', 'sort', 'createdAt', 'updatedAt', 'publishedAt', 'created'], true)) {
                    $fields[$key] = $value;
                }
            }
        }
        if (!is_array($fields)) {
            throw new DeskException('"fields" must be an object.');
        }
        foreach (array_keys($fields) as $name) {
            if (!$schema->hasField((string) $name)) {
                throw new DeskException(sprintf('Unknown field "%s" for type "%s". Run freezed-desk schema %s.', $name, $type, $type));
            }
        }

        $slug ??= isset($record['slug']) && is_string($record['slug']) ? $record['slug'] : null;
        $existing = $slug !== null
            ? $context->repository()->findBySlug($type, $slug)
            : ($schema->single ? $context->repository()->findSingle($type) : null);

        $input = [
            'slug' => $slug,
            'variant' => $record['variant'] ?? null,
            'fields' => $fields,
        ];
        if (array_key_exists('status', $record)) {
            $input['status'] = $record['status'];
        }
        if (array_key_exists('sort', $record)) {
            $input['sort'] = $record['sort'];
        }

        $saved = $context->repository()->save($type, $input, $existing?->id, 'cli');
        self::printJson(GetCommand::portable($context, $saved) + ['created' => $existing === null]);

        return 0;
    }
}
