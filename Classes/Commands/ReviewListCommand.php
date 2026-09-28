<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Review\ReviewRepository;

/**
 * `freezed-desk review:list [<type>] [--queue:open]` — the review queues
 * (docs/review.md). Without a type: the number of records per queue for
 * every type offered for review. With a type: the records of one queue
 * (default "open": never reviewed or changed since), in list order, with
 * their review state.
 */
class ReviewListCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $reviews = $context->reviews();
        $queue = isset($options['queue']) && is_string($options['queue']) ? $options['queue'] : 'open';
        if (!in_array($queue, ReviewRepository::QUEUES, true)) {
            throw new DeskException(sprintf('--queue: takes %s.', implode(', ', ReviewRepository::QUEUES)));
        }

        $type = $args[0] ?? null;
        if ($type === null) {
            $types = [];
            foreach ($reviews->types() as $schema) {
                $types[$schema->slug] = $reviews->counts($schema);
            }
            self::printJson(['types' => $types, 'openPoints' => $reviews->openTotal()]);

            return 0;
        }

        if (!$reviews->reviewable($type)) {
            throw new DeskException(sprintf('Type "%s" is not offered for review (%s).', $type, implode(', ', array_keys($reviews->types())) ?: 'none'));
        }
        $records = [];
        foreach ($reviews->queue($context->schemas()->get($type), $queue) as $item) {
            $state = $reviews->state($item);
            $records[] = [
                'slug' => $item->slug,
                'title' => $item->title,
                'status' => $item->status->value,
                'review' => $state['state'],
                'lastReview' => $state['review'] === null ? null : [
                    'decision' => $state['review']['decision'],
                    'at' => $state['review']['createdAt'],
                    'reviewer' => $state['review']['reviewer'],
                    'revision' => $state['review']['revision'],
                ],
                'openPoints' => $state['openPoints'],
            ];
        }
        self::printJson(['type' => $type, 'queue' => $queue, 'records' => $records]);

        return 0;
    }
}
