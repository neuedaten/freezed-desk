<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Export\Portable;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * `freezed-desk get <type>/<slug>` — one record as JSON in the portable
 * form (fields as stored, relations as {type, slug}, media as {file}); the
 * same shape `put` accepts and `export` writes. --export prints the
 * template variables instead.
 */
class GetCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk get <type>/<slug>'));
        $item = self::find($context, $type, $slug);

        if (isset($options['export'])) {
            self::printJson($context->exporter()->export($item));

            return 0;
        }

        self::printJson(self::portable($context, $item));

        return 0;
    }

    public static function find(DeskContext $context, string $type, ?string $slug): Item
    {
        $schema = $context->schemas()->get($type);
        if ($slug === null) {
            if (!$schema->single) {
                throw new DeskException('Give the record as <type>/<slug>.');
            }

            return $context->repository()->findSingle($type) ?? throw new NotFoundException(sprintf('Type "%s" has no record yet.', $type));
        }

        return $context->repository()->findBySlug($type, $slug)
            ?? throw new NotFoundException(sprintf('No record "%s" of type "%s".', $slug, $type));
    }

    /** @return array<string, mixed> */
    public static function portable(DeskContext $context, Item $item): array
    {
        $schema = $context->schemas()->get($item->type);

        return [
            'id' => $item->id,
            'type' => $item->type,
            'slug' => $item->slug,
            'title' => $item->title,
            'variant' => $item->variant,
            'status' => $item->status->value,
            'sort' => $item->sort,
            'createdAt' => $item->createdAt,
            'updatedAt' => $item->updatedAt,
            'publishedAt' => $item->publishedAt,
            'fields' => (new Portable($context))->fromStored($schema, $item->data),
        ];
    }
}
