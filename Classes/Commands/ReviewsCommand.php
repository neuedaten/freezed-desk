<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * `freezed-desk reviews [<type>[/<slug>]] [--open] [--snapshot]` — reviews
 * and their points (docs/review.md).
 *
 * With a record: its reviews, newest first; --snapshot adds the state that
 * was reviewed. Without one (or with a type): the open points to work
 * through, grouped by record, oldest first -- the list for whoever reworks
 * the records. --open limits a record's reviews to those with open points.
 */
class ReviewsCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $reviews = $context->reviews();
        [$type, $slug] = isset($args[0]) ? self::target($args[0]) : [null, null];

        if ($type !== null && $slug !== null) {
            $item = GetCommand::find($context, $type, $slug);
            $list = [];
            foreach ($reviews->forItem($item->id, withSnapshot: Options::flag($options, 'snapshot')) as $review) {
                if (Options::flag($options, 'open') && $review['openPoints'] === 0) {
                    continue;
                }
                $list[] = self::describe($context, $item, $review);
            }
            self::printJson([
                'type' => $item->type,
                'slug' => $item->slug,
                'title' => $item->title,
                'review' => $reviews->reviewable($item->type) ? $reviews->state($item)['state'] : null,
                'reviews' => $list,
            ]);

            return 0;
        }

        $itemIds = null;
        if ($type !== null) {
            $context->schemas()->get($type);
            $itemIds = array_map(static fn (Item $i): int => $i->id, $context->repository()->find($type)->anyStatus()->all());
        }
        $records = [];
        foreach ($reviews->openPoints($itemIds) as $point) {
            $item = $context->repository()->get($point['itemId']);
            if ($item === null) {
                continue;
            }
            $key = $item->type . '/' . $item->slug;
            $records[$key] ??= ['type' => $item->type, 'slug' => $item->slug, 'title' => $item->title, 'status' => $item->status->value, 'points' => []];
            $records[$key]['points'][] = self::point($context, $item, $point) + [
                'review' => ['decision' => $point['decision'], 'at' => $point['reviewedAt'], 'reviewer' => $point['reviewer'], 'revision' => $point['revision']],
            ];
        }
        self::printJson(['records' => array_values($records)]);

        return 0;
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    public static function describe(DeskContext $context, Item $item, array $review): array
    {
        $result = [
            'id' => $review['id'],
            'decision' => $review['decision'],
            'at' => $review['createdAt'],
            'reviewer' => $review['reviewer'],
            'revision' => $review['revision'],
            'openPoints' => $review['openPoints'],
            'points' => array_map(static fn (array $p): array => self::point($context, $item, $p), $review['points']),
        ];
        if (isset($review['snapshot'])) {
            $result['snapshot'] = $review['snapshot'];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $point
     * @return array<string, mixed>
     */
    private static function point(DeskContext $context, Item $item, array $point): array
    {
        $tags = $context->reviews()->tags();
        $field = $point['field'];

        return [
            'id' => $point['id'],
            'field' => $field,
            'label' => $field === null ? $context->t('review.general') : ($context->schemas()->get($item->type)->field($field)?->label ?? $field),
            'tags' => $point['tags'],
            'tagLabels' => array_map(static fn (string $k): string => $tags[$k] ?? $k, $point['tags']),
            'text' => $point['text'],
            'open' => $point['open'],
            'doneAt' => $point['doneAt'],
            'doneBy' => $point['doneBy'],
            'doneNote' => $point['doneNote'],
        ];
    }
}
