<?php

namespace Neuedaten\FreezedDesk\Exception;

use Neuedaten\FreezedDesk\Storage\Item;

/**
 * `put --if-revision:<n>` (or a form) was based on a revision that is no
 * longer the current one: someone else saved in between. Nothing was
 * written; $current is the record as it is now.
 */
final class ConflictException extends DeskException
{
    public function __construct(public readonly Item $current, public readonly int $expected)
    {
        parent::__construct(sprintf(
            'conflict: %s/%s is at revision %d, the change was based on revision %d. Get the record again and re-apply the change.',
            $current->type,
            $current->slug,
            $current->revision,
            $expected
        ));
    }
}
