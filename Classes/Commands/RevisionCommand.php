<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Export\Portable;
use Neuedaten\FreezedDesk\Storage\Diff;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * `freezed-desk revision <type>/<slug> <n> [--diff]` — one earlier state of
 * a record (A1.3), with --diff the fields that differ from the current
 * state.
 */
class RevisionCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk revision <type>/<slug> <n> [--diff]'));
        $number = $args[1] ?? throw new DeskException('Usage: freezed-desk revision <type>/<slug> <n> [--diff]');
        $item = GetCommand::find($context, $type, $slug);
        $revision = self::find($context, $item, $number);
        $schema = $context->schemas()->get($type);
        $snapshot = $revision['data'];

        $result = [
            'type' => $item->type,
            'slug' => $item->slug,
            'revision' => $revision['number'],
            'actor' => $revision['actor'],
            'savedAt' => $revision['savedAt'],
            'status' => $snapshot['status'] ?? null,
            'variant' => $snapshot['variant'] ?? null,
            'fields' => (new Portable($context))->fromStored($schema, is_array($snapshot['data'] ?? null) ? $snapshot['data'] : []),
        ];
        if (Options::flag($options, 'diff')) {
            $result['diff'] = array_map(
                static fn (array $row): array => ['field' => $row['field'], 'then' => $row['then'], 'now' => $row['now']],
                (new Diff($context))->between($schema, $snapshot, $item->snapshot())
            );
            $result['currentRevision'] = $item->revision;
        }
        self::printJson($result);

        return 0;
    }

    /**
     * An earlier state by its revision number.
     *
     * @return array{id: int, number: int|null, actor: string, savedAt: string|null, createdAt: string, note: string, data: array<string, mixed>}
     */
    public static function find(DeskContext $context, Item $item, string $number): array
    {
        if (!ctype_digit($number)) {
            throw new DeskException('The revision is a number, see freezed-desk revisions ' . $item->type . '/' . $item->slug . '.');
        }
        if ((int) $number === $item->revision) {
            throw new DeskException(sprintf('Revision %s is the current state; get shows it.', $number));
        }

        return $context->repository()->revisionByNumber($item->id, (int) $number)
            ?? throw new NotFoundException(sprintf('%s/%s has no kept revision %s.', $item->type, $item->slug, $number));
    }
}
