<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Inbox\InboxClient;

/**
 * `freezed-desk inbox` — fetch new submissions from the configured endpoint.
 */
class InboxCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $count = (new InboxClient($context))->fetch();

        LogService::getInstance()->success(sprintf('%d new submission%s fetched (%d open).', $count, $count === 1 ? '' : 's', $context->inbox()->count(['new', 'open'])));

        return 0;
    }
}
