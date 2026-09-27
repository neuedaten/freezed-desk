<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\Status;

/**
 * `freezed-desk status` — the overview of the UI as JSON (A1.9): counts per
 * type, drafts, recent changes, open inbox, the last build and actions, and
 * what the extensions report (outbox errors, open social posts …).
 */
class OverviewCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $repository = $context->repository();
        $schemas = $context->schemas()->all();
        $describe = static fn (Item $item): array => [
            'type' => $item->type, 'slug' => $item->slug, 'title' => $item->title, 'status' => $item->status->value,
            'updatedAt' => $item->updatedAt, 'updatedBy' => $item->updatedBy,
        ];

        $types = [];
        $drafts = [];
        $unseen = 0;
        foreach ($schemas as $schema) {
            $types[$schema->slug] = $repository->counts($schema->slug) + ['label' => $schema->label];
            if ($schema->single) {
                continue;
            }
            foreach ($repository->find($schema->slug)->withStatus(Status::Draft)->orderBy('updatedAt', 'DESC')->limit(10)->all() as $item) {
                $drafts[] = $item;
            }
            $unseen += $repository->find($schema->slug)->notArchived()->filter(static fn (Item $i): bool => $i->isUnseenAgentChange())->count();
        }
        usort($drafts, static fn (Item $a, Item $b): int => strcmp($b->updatedAt, $a->updatedAt));

        $lastBuild = json_decode((string) $repository->setting('lastBuild', ''), true);
        $actions = [];
        foreach (array_keys($context->config->actions()) as $name) {
            $last = json_decode((string) $repository->setting('action:' . $name, ''), true);
            $actions[$name] = is_array($last) ? $last : null;
        }

        $extensions = [];
        foreach ($context->extensions() as $name => $extension) {
            $status = $extension->status($context);
            if ($status !== []) {
                $extensions[$name] = $status;
            }
        }

        self::printJson([
            'types' => $types,
            'drafts' => array_map($describe, array_slice($drafts, 0, 10)),
            'recent' => array_map($describe, $repository->recent(10)),
            'unseenAgentChanges' => $unseen,
            'inbox' => ['open' => $context->inbox()->count(['new', 'open'])],
            'media' => ['count' => $context->media()->count()],
            'lastBuild' => is_array($lastBuild) ? $lastBuild : null,
            'actions' => $actions,
            'extensions' => (object) $extensions,
        ]);

        return 0;
    }
}
