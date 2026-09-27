<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Inbox\InboxRepository;

/**
 * `freezed-desk inbox:list [--status:new,open] [--limit:]` — submissions in
 * the inbox (A1.7), newest first. Default: new and open.
 */
class InboxListCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $statuses = isset($options['status']) && is_string($options['status']) ? array_filter(explode(',', $options['status'])) : ['new', 'open'];
        if ($statuses === ['all']) {
            $statuses = null;
        }
        foreach ($statuses ?? [] as $status) {
            if (!in_array($status, InboxRepository::STATUSES, true)) {
                throw new DeskException(sprintf('Unknown inbox status "%s" (%s, all).', $status, implode(', ', InboxRepository::STATUSES)));
            }
        }

        self::printJson(['entries' => array_map(
            static fn (array $entry): array => InboxShowCommand::describe($context, $entry, full: false),
            $context->inbox()->all($statuses, (int) ($options['limit'] ?? 0), (int) ($options['offset'] ?? 0))
        )]);

        return 0;
    }
}
