<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Export\Portable;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\RecordFilter;

/**
 * `freezed-desk list <type>` — the records of a type as JSON:
 * {id, slug, title, status, variant, revision, updatedBy, updatedAt}.
 *
 * Options (A1.1, the same filters the list in the UI offers):
 *   --status:draft|published|archived|all   default: not archived
 *   --q:<search>
 *   --where:<field><op><value>              repeatable; = != > >= < <= ~ (see RecordFilter)
 *   --from:<date> --to:<date> [--date:<field>]   date range, e.g. --from:today --to:+14d
 *   --range:today|next7|next14|future|past
 *   --referencing:<type>/<slug>             records that reference this one
 *   --by:agent|editor|cli|import            last changed by
 *   --unseen                                changed by the agent, not yet opened by a person
 *   --order:<field>,-<field>                default: the schema's orderBy
 *   --fields:<a>,<b>                        add these fields (portable form) to each record
 *   --validation                            add the messages of the schema's checks
 *   --limit:<n> --offset:<n>
 */
class ListCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $type = $args[0] ?? throw new DeskException('Usage: freezed-desk list <type> [--status:] [--where:] [--order:] [--limit:]');
        $schema = $context->schemas()->get($type);
        $filter = new RecordFilter($context, $schema);

        $query = $context->repository()->find($type);
        $status = (string) ($options['status'] ?? '');
        match ($status) {
            'draft', 'published', 'archived' => $query->withStatus($status),
            'all' => $query->anyStatus(),
            '' => $query->notArchived(),
            default => throw new DeskException('--status: takes draft, published, archived or all.'),
        };
        if (isset($options['q'])) {
            $query->search((string) $options['q']);
        }
        foreach (Options::all('where', $options) as $condition) {
            $filter->where($query, $condition);
        }
        if (isset($options['from']) || isset($options['to'])) {
            $filter->dateRange($query, isset($options['from']) ? (string) $options['from'] : null, isset($options['to']) ? (string) $options['to'] : null, isset($options['date']) ? (string) $options['date'] : null);
        }
        if (isset($options['range'])) {
            $filter->namedRange($query, (string) $options['range'], isset($options['date']) ? (string) $options['date'] : null);
        }
        if (isset($options['referencing'])) {
            [$targetType, $targetSlug] = self::target((string) $options['referencing']);
            $filter->referencing($query, GetCommand::find($context, $targetType, $targetSlug));
        }
        if (isset($options['by'])) {
            $by = (string) $options['by'];
            $query->filter(static fn (Item $item): bool => $item->updatedBy === $by);
        }
        if (Options::flag($options, 'unseen')) {
            $query->filter(static fn (Item $item): bool => $item->isUnseenAgentChange());
        }
        if (isset($options['order']) && is_string($options['order'])) {
            $filter->order($query, $options['order']);
        } else {
            $query->ordered();
        }
        if (isset($options['limit'])) {
            $query->limit((int) $options['limit']);
        }
        if (isset($options['offset'])) {
            $query->offset((int) $options['offset']);
        }

        $extraFields = [];
        if (isset($options['fields']) && is_string($options['fields'])) {
            foreach (array_filter(array_map('trim', explode(',', $options['fields']))) as $name) {
                if (!$schema->hasField($name)) {
                    throw new DeskException(sprintf('Unknown field "%s" for type "%s".', $name, $type));
                }
                $extraFields[] = $name;
            }
        }
        $withValidation = Options::flag($options, 'validation');
        $portable = new Portable($context);

        self::printJson([
            'type' => $type,
            'records' => array_map(static function (Item $item) use ($context, $schema, $portable, $extraFields, $withValidation): array {
                $record = [
                    'id' => $item->id,
                    'slug' => $item->slug,
                    'title' => $item->title,
                    'status' => $item->status->value,
                    'variant' => $item->variant,
                    'revision' => $item->revision,
                    'updatedBy' => $item->updatedBy,
                    'updatedAt' => $item->updatedAt,
                ];
                if ($extraFields !== []) {
                    $record['fields'] = $portable->fromStored($schema, array_intersect_key($item->data, array_flip($extraFields)));
                }
                if ($withValidation) {
                    $validation = $context->validation()->ofItem($item);
                    $record['validation'] = ['errors' => (object) $validation['errors'], 'warnings' => (object) $validation['warnings']];
                }

                return $record;
            }, $query->all()),
        ]);

        return 0;
    }
}
