<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Storage\Status;

/**
 * Base of `publish`, `unpublish` and `archive <type>/<slug>`. Publishing
 * validates the record; the other two always work.
 */
abstract class StatusCommand extends AbstractCommand
{
    abstract protected function status(): Status;

    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk ' . strtolower(substr((new \ReflectionClass($this))->getShortName(), 0, -7)) . ' <type>/<slug>'));
        $item = GetCommand::find($context, $type, $slug);
        $context->repository()->setStatus([$item->id], $this->status());
        self::printJson(GetCommand::portable($context, $context->repository()->require($item->id)));

        return 0;
    }
}
