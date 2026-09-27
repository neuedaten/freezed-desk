<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\ApprovalException;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Storage\Status;

/**
 * Base of `publish`, `unpublish` and `archive`: one record
 * (<type>/<slug>), several, or a type with --where: conditions (A1.5).
 * Publishing validates each record; the other two always work. Records
 * that could not be changed are listed with the reason, and the exit code
 * is 1 then. --dry-run shows the outcome without keeping it.
 */
abstract class StatusCommand extends AbstractCommand
{
    abstract protected function status(): Status;

    protected function run(DeskContext $context, array $args, array $options): int
    {
        if ($args === []) {
            throw new DeskException('Usage: freezed-desk ' . strtolower(substr((new \ReflectionClass($this))->getShortName(), 0, -7)) . ' <type>/<slug> [<type>/<slug> …] | <type> --where:field=value [--dry-run]');
        }
        $items = self::targets($context, $args, $options);
        $status = $this->status();

        $result = self::change($context, $options, static function () use ($context, $items, $status): array {
            $outcome = $context->repository()->changeStatus(array_map(static fn ($item): int => $item->id, $items), $status);
            $records = [];
            foreach ($items as $item) {
                $current = $context->repository()->require($item->id);
                $records[] = ['type' => $current->type, 'slug' => $current->slug, 'status' => $current->status->value, 'revision' => $current->revision];
            }

            return $outcome + ['records' => $records];
        });

        // A single record keeps the answer of 0.1: the record, or the error.
        if (count($items) === 1 && count($args) === 1 && Options::all('where', $options) === []) {
            if ($result['rejected'] !== []) {
                $message = (string) reset($result['rejected']);
                if ($status === Status::Published && $context->schemas()->get($items[0]->type)->needsUiApproval() && !$context->actor()->isHuman()) {
                    throw new ApprovalException($message);
                }
                throw new ValidationException(['_' => $message]);
            }
            $record = GetCommand::portable($context, $context->repository()->require($items[0]->id));
            if (self::isDryRun($options)) {
                $record['dryRun'] = true;
            }
            self::printJson($record);

            return 0;
        }

        $rejected = [];
        foreach ($result['rejected'] as $id => $message) {
            $item = $context->repository()->get($id);
            $rejected[] = ['type' => $item?->type, 'slug' => $item?->slug, 'error' => $message];
        }
        self::printJson([
            'status' => $status->value,
            'changed' => count($result['changed']),
            'rejected' => $rejected,
            'records' => $result['records'],
            'dryRun' => self::isDryRun($options),
        ]);

        return $rejected === [] ? 0 : 1;
    }
}
