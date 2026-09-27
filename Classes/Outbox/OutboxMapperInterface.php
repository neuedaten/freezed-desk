<?php

namespace Neuedaten\FreezedDesk\Outbox;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * Turns a published record into outbox messages and writes the results
 * back (B2.2). The project (or a package such as freezed-desk-social)
 * names its mapper in desk.outbox.mapper.
 */
interface OutboxMapperInterface
{
    /**
     * The messages of a published record; [] when it has nothing to send.
     *
     * @return OutboxMessage[]
     */
    public function messages(Item $item, DeskContext $context): array;

    /**
     * The fields to store in the record for a result, e.g. the URL of the
     * published post. Only fields with system: true (A3.4), so an approved
     * record stays approved.
     *
     * @return array<string, mixed> field => value (stored shape)
     */
    public function applyResult(Item $item, OutboxResult $result): array;
}
