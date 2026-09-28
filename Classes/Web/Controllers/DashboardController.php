<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Storage\Status;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

class DashboardController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $repository = $this->context->repository();
        $schemas = $this->context->schemas()->all();

        $types = [];
        foreach ($schemas as $schema) {
            $types[$schema->slug] = ['schema' => $schema, 'counts' => $repository->counts($schema->slug)];
        }

        $drafts = [];
        foreach ($schemas as $schema) {
            if ($schema->single) {
                continue;
            }
            foreach ($repository->find($schema->slug)->withStatus(Status::Draft)->orderBy('updatedAt', 'DESC')->limit(10)->all() as $item) {
                $drafts[] = ['item' => $item, 'schema' => $schema];
            }
        }
        usort($drafts, static fn (array $a, array $b): int => strcmp($b['item']->updatedAt, $a['item']->updatedAt));

        $recent = [];
        foreach ($repository->recent(10) as $item) {
            if (isset($schemas[$item->type])) {
                $recent[] = ['item' => $item, 'schema' => $schemas[$item->type]];
            }
        }

        $lastBuild = json_decode((string) $repository->setting('lastBuild', ''), true);

        $unseen = [];
        foreach ($schemas as $schema) {
            foreach ($repository->find($schema->slug)->notArchived()->filter(static fn ($item): bool => $item->isUnseenAgentChange())->orderBy('updatedAt', 'DESC')->all() as $item) {
                $unseen[] = ['item' => $item, 'schema' => $schema];
            }
        }

        $panels = [];
        foreach ($this->context->extensions() as $extension) {
            foreach ($extension->dashboardPanels($this->context) as $panel) {
                $panels[] = $panel;
            }
        }

        return $this->view('Dashboard/Index', [
            'types' => $types,
            'drafts' => array_slice($drafts, 0, 10),
            'recent' => $recent,
            'inboxOpen' => $this->context->inbox()->count(['new', 'open']),
            'lastBuild' => is_array($lastBuild) ? $lastBuild : null,
            'mediaCount' => $this->context->media()->count(),
            'unseen' => array_slice($unseen, 0, 20),
            'unseenCount' => count($unseen),
            'panels' => $panels,
            'review' => \Neuedaten\FreezedDesk\Commands\OverviewCommand::review($this->context),
        ]);
    }
}
