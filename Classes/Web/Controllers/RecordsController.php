<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Actions\ActionRunner;
use Neuedaten\FreezedDesk\Exception\ConflictException;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Export\PreviewUrl;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Diff;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\Query;
use Neuedaten\FreezedDesk\Storage\RecordFilter;
use Neuedaten\FreezedDesk\Storage\Status;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

/**
 * Lists and forms for the records of a type.
 */
class RecordsController extends Controller
{
    private const PER_PAGE = 50;

    /** The agenda shows a period, not pages. */
    private const AGENDA_MAX = 300;

    // ----------------------------------------------------------- list ---

    public function index(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $repository = $this->context->repository();

        if ($schema->single) {
            $item = $repository->findSingle($schema->slug);

            return $this->redirect('/types/' . $schema->slug . '/' . ($item?->id ?? 'new'));
        }

        $status = (string) $request->get('status', '');
        $q = (string) $request->get('q', '');
        $sort = (string) $request->get('sort', '');
        $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $rel = (string) $request->get('rel', '');
        $feature = (string) $request->get('feature', '');
        $page = max(1, (int) $request->get('page', 1));
        $view = (string) $request->get('view', $schema->defaultListView());
        if (!isset($schema->listViews[$view])) {
            $view = $schema->defaultListView();
        }
        $filterValues = $request->get('f', []);
        $filterValues = is_array($filterValues) ? $filterValues : [];

        $query = $repository->find($schema->slug)->search($q);
        match ($status) {
            'draft', 'published', 'archived' => $query->withStatus($status),
            'all' => $query->anyStatus(),
            default => $query->notArchived(),
        };

        if ($rel !== '' && ctype_digit($rel)) {
            $relId = (int) $rel;
            $query->filter(function (Item $item) use ($schema, $relId): bool {
                foreach ($schema->relationFields() as $name => $field) {
                    foreach ((array) ($item->data[$name] ?? []) as $pair) {
                        if ((int) ($pair['id'] ?? 0) === $relId) {
                            return true;
                        }
                    }
                }

                return false;
            });
        }
        if ($feature !== '') {
            $featureField = array_key_first(array_filter($schema->fields, static fn (FieldDefinition $f): bool => $f->type === 'features'));
            if ($featureField !== null) {
                $query->whereFeature($feature, true, $featureField);
            }
        }
        $filters = $this->applyFilters($schema, $query, $filterValues);

        $sortable = array_merge(array_keys($schema->fields), ['slug', 'variant', 'status', 'updatedAt', 'createdAt', 'publishedAt', 'sort', 'updatedBy']);
        if ($view === 'agenda') {
            $query->orderBy((string) $schema->listViews['agenda']['field'], 'ASC');
        } elseif ($sort !== '' && in_array($sort, $sortable, true)) {
            $query->orderBy($sort, $dir);
        } else {
            $query->ordered();
        }

        $all = $query->all();
        $total = count($all);
        $perPage = $view === 'agenda' ? self::AGENDA_MAX : self::PER_PAGE;
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);

        $columns = $this->columns($schema);
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->row($schema, $item, $columns, $view);
        }

        $relItem = $rel !== '' && ctype_digit($rel) ? $repository->get((int) $rel) : null;
        $sortMode = $view === 'table' && array_key_first($schema->orderBy) === 'sort' && $sort === '' && $q === '' && $rel === '' && $feature === '' && $filterValues === [];

        return $this->view('Records/Index', [
            'schema' => $schema,
            'rows' => $rows,
            'groups' => $view === 'agenda' ? $this->agendaGroups($schema, $rows) : [],
            'columns' => $columns,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $perPage),
            'q' => $q,
            'status' => $status,
            'sort' => $sort,
            'dir' => $dir,
            'relItem' => $relItem,
            'feature' => $feature,
            'counts' => $repository->counts($schema->slug),
            'sortMode' => $sortMode,
            'view' => $view,
            'views' => array_keys($schema->listViews),
            'viewOptions' => $schema->listViews[$view],
            'filters' => $filters,
            'filtered' => $filterValues !== [],
            'recordActions' => array_filter((new ActionRunner($this->context))->recordActions($schema), static fn (array $a): bool => $a['bulk']),
            'approvalType' => $schema->needsUiApproval(),
        ]);
    }

    // ----------------------------------------------------------- form ---

    public function create(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        if ($schema->single) {
            $existing = $this->context->repository()->findSingle($schema->slug);
            if ($existing !== null) {
                return $this->redirect('/types/' . $schema->slug . '/' . $existing->id);
            }
        }

        return $this->form($schema, null, $this->defaults($schema), [], $request);
    }

    public function store(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);

        return $this->save($schema, null, $request);
    }

    public function edit(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $this->context->repository()->markSeen($item->id);

        return $this->form($schema, $item, array_replace($this->defaults($schema), $item->data), [], $request);
    }

    public function update(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);

        return $this->save($schema, $item, $request);
    }

    private function save(TypeSchema $schema, ?Item $item, Request $request): Response
    {
        $action = (string) $request->post('action', 'save');
        $status = match ($action) {
            'publish' => Status::Published->value,
            'draft' => Status::Draft->value,
            default => $item?->status->value ?? ($schema->single ? Status::Published->value : Status::Draft->value),
        };

        $rawFields = $request->post('fields', []);
        $input = [
            'slug' => (string) $request->post('slug', ''),
            'variant' => (string) $request->post('variant', ''),
            'status' => $status,
            'fields' => is_array($rawFields) ? $rawFields : [],
        ];

        // The revision the form was opened at (A4.2); "force" carries the
        // current revision after the editor has seen the conflict.
        $base = $request->post('revision');
        $ifRevision = $item !== null && is_string($base) && ctype_digit($base) ? (int) $base : null;

        try {
            $saved = $this->context->repository()->save($schema->slug, $input, $item?->id, 'ui', ifRevision: $ifRevision);
        } catch (ConflictException $exception) {
            $values = $this->normalize($schema, $input['fields']);
            $then = $this->context->repository()->revisionByNumber($exception->current->id, (int) $ifRevision)['data'] ?? null;
            $conflict = [
                'current' => $exception->current->revision,
                'base' => $ifRevision,
                'diff' => $then === null ? [] : (new Diff($this->context))->between($schema, $then, $exception->current->snapshot()),
            ];

            return $this->form($schema, $exception->current, $values, [], $request, $input, 409, $conflict);
        } catch (ValidationException $exception) {
            // The form shows the errors itself; a flash would turn up again on the next page.
            $values = $this->normalize($schema, $input['fields']);

            return $this->form($schema, $item, $values, $exception->errors, $request, $input, 422);
        }

        foreach ($this->context->repository()->notices() as $notice) {
            $this->flash('error', $notice);
        }
        $this->flash('success', $action === 'publish' ? ($schema->needsUiApproval() ? $this->t('approval.approved') : $this->t('ui.published')) : $this->t('ui.saved'));

        $inbox = $request->post('inbox');
        $suffix = is_string($inbox) && ctype_digit($inbox) ? '?inbox=' . $inbox : '';

        return $this->redirect('/types/' . $schema->slug . '/' . $saved->id . $suffix);
    }

    /**
     * @param array<string, mixed> $values Stored-shape field values.
     * @param array<string, string> $errors
     * @param array<string, mixed> $submitted slug/variant/status as posted (after a failed save).
     * @param array<string, mixed>|null $conflict {current, base, diff} after a conflict.
     */
    private function form(TypeSchema $schema, ?Item $item, array $values, array $errors, Request $request, array $submitted = [], int $status = 200, ?array $conflict = null): Response
    {
        $repository = $this->context->repository();

        $inboxEntry = null;
        $inboxId = $request->input('inbox');
        if (is_string($inboxId) && ctype_digit($inboxId)) {
            $inboxEntry = $this->context->inbox()->get((int) $inboxId);
        }

        $panels = ['main' => [], 'side' => []];
        if ($item !== null) {
            foreach ($this->context->extensions() as $extension) {
                foreach ($extension->recordPanels($this->context, $schema, $item) as $panel) {
                    $panels[($panel['position'] ?? 'main') === 'side' ? 'side' : 'main'][] = $panel;
                }
            }
        }

        return $this->view('Records/Edit', [
            'schema' => $schema,
            'item' => $item,
            'values' => $values,
            'errors' => $errors,
            'slug' => $submitted['slug'] ?? $item?->slug ?? '',
            'variant' => $submitted['variant'] ?? $item?->variant ?? $schema->defaultVariant(),
            'status' => $item?->status->value ?? Status::Draft->value,
            'previewUrl' => $item !== null ? (new PreviewUrl($this->context))->of($item) : null,
            'referencing' => $item !== null ? $repository->referencing($item->id) : [],
            'revisionCount' => $item !== null ? count($repository->revisions($item->id)) : 0,
            'inboxEntry' => $inboxEntry,
            'isNew' => $item === null,
            'validation' => $item !== null ? $this->context->validation()->ofItem($item) : null,
            'recordActions' => $item !== null ? (new ActionRunner($this->context))->recordActions($schema, $item) : [],
            'panels' => $panels,
            'conflict' => $conflict,
            'approvalType' => $schema->needsUiApproval(),
            'systemFields' => array_keys($schema->systemFields()),
            'review' => $item !== null ? $this->reviewPanel($schema, $item) : null,
        ], $status);
    }

    /**
     * What the record page shows of its reviews: the state, the open
     * points (with the field they are about) and how many reviews there are.
     *
     * @return array<string, mixed>|null
     */
    private function reviewPanel(TypeSchema $schema, Item $item): ?array
    {
        $reviews = $this->context->reviews();
        $history = $reviews->forItem($item->id);
        $reviewable = $reviews->reviewable($schema->slug);
        if (!$reviewable && $history === []) {
            return null;
        }
        $tags = $reviews->tags();
        $open = [];
        foreach ($history as $review) {
            $points = array_values(array_filter($review['points'], static fn (array $p): bool => $p['open']));
            if ($points === []) {
                continue;
            }
            $open[] = $review + ['open' => array_map(fn (array $p): array => $p + [
                'label' => $p['field'] === null ? $this->t('review.general') : ($schema->field($p['field'])?->label ?? $p['field']),
                'tagLabels' => array_map(static fn (string $k): string => $tags[$k] ?? $k, $p['tags']),
            ], $points)];
        }

        return [
            'reviewable' => $reviewable,
            'state' => $reviews->state($item),
            'count' => count($history),
            'reviews' => array_reverse($open),
            'openCount' => array_sum(array_map(static fn (array $r): int => count($r['open']), $open)),
        ];
    }

    // -------------------------------------------------------- actions ---

    public function status(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $status = Status::fromInput($request->post('status'), $item->status);

        try {
            $this->context->repository()->setStatus([$item->id], $status);
            foreach ($this->context->repository()->notices() as $notice) {
                $this->flash('error', $notice);
            }
            $this->flash('success', $this->t('ui.saved'));
        } catch (ValidationException | DeskException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        return $this->redirect((string) ($request->post('back') ?: '/types/' . $schema->slug . '/' . $item->id));
    }

    public function delete(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);

        $this->context->repository()->delete($item->id);
        $this->flash('success', $this->t('ui.deleted'));

        return $this->redirect('/types/' . $schema->slug);
    }

    public function bulk(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $ids = $this->idList($request);
        $action = (string) $request->post('bulk', '');
        $repository = $this->context->repository();

        $ids = array_values(array_filter($ids, fn (int $id): bool => $repository->get($id)?->type === $schema->slug));

        if ($action === 'delete') {
            foreach ($ids as $id) {
                $repository->delete($id);
            }
            $this->flash('success', $this->t('ui.deletedCount', ['count' => count($ids)]));
        } elseif (in_array($action, Status::values(), true)) {
            // Every record goes through the checks on its own (A5.5, A6.5).
            $result = $repository->changeStatus($ids, Status::from($action));
            $this->flash('success', $this->t('ui.statusChanged', ['count' => count($result['changed'])]));
            if ($result['rejected'] !== []) {
                $this->flash('error', $this->t('ui.bulk.rejected', ['count' => count($result['rejected'])]) . ' ' . implode(' · ', $result['rejected']));
            }
        }

        return $this->redirect((string) ($request->post('back') ?: '/types/' . $schema->slug));
    }

    public function reorder(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $ids = $this->idList($request);
        $repository = $this->context->repository();
        $ids = array_values(array_filter($ids, fn (int $id): bool => $repository->get($id)?->type === $schema->slug));
        $repository->reorder($ids);

        return Response::json(['ok' => true, 'count' => count($ids)]);
    }

    /**
     * Run a record action (A9) with live output, like the global actions.
     */
    public function action(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $runner = new ActionRunner($this->context);
        $command = $runner->recordCommand($item, (string) $params['name']);

        return $this->stream($runner, [$command]);
    }

    /**
     * A bulk record action: the command once per selected record.
     */
    public function bulkAction(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $name = (string) $params['name'];
        $runner = new ActionRunner($this->context);
        if (!($runner->recordActions($schema)[$name]['bulk'] ?? false)) {
            throw new NotFoundException($this->t('ui.notFound'));
        }
        $commands = [];
        foreach ($this->idList($request) as $id) {
            $item = $this->context->repository()->get($id);
            if ($item !== null && $item->type === $schema->slug && isset($runner->recordActions($schema, $item)[$name])) {
                $commands[] = $runner->recordCommand($item, $name);
            }
        }

        return $this->stream($runner, $commands);
    }

    public function preview(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $url = (new PreviewUrl($this->context))->of($item) ?? throw new NotFoundException($this->t('ui.notFound'));

        return $this->redirect($url);
    }

    // ------------------------------------------------------ revisions ---

    public function revisions(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $repository = $this->context->repository();
        $revisions = $repository->revisions($item->id);

        $selectedId = (int) $request->get('rev', $revisions[0]['id'] ?? 0);
        $selected = null;
        foreach ($revisions as $revision) {
            if ($revision['id'] === $selectedId) {
                $selected = $revision;
            }
        }

        return $this->view('Records/Revisions', [
            'schema' => $schema,
            'item' => $item,
            'revisions' => $revisions,
            'selected' => $selected,
            'diff' => $selected !== null ? (new Diff($this->context))->between($schema, $selected['data'], $item->snapshot()) : [],
        ]);
    }

    public function restore(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $this->context->repository()->restoreRevision($item->id, $this->intParam($params, 'revision'));
        $this->flash('success', $this->t('ui.restored'));

        return $this->redirect('/types/' . $schema->slug . '/' . $item->id);
    }

    // -------------------------------------------------------- helpers ---

    /** @param string[] $commands */
    private function stream(ActionRunner $runner, array $commands): Response
    {
        return Response::streamed(function (callable $write) use ($runner, $commands): void {
            set_time_limit(0);
            ignore_user_abort(true);
            $failed = 0;
            foreach ($commands as $command) {
                $write('$ ' . $command . "\n");
                $started = microtime(true);
                $exit = $runner->run($command, $write);
                $failed += $exit === 0 ? 0 : 1;
                $write(sprintf("\n[exit %d] %.1f s\n\n", $exit, microtime(true) - $started));
            }
            if (count($commands) > 1) {
                $write(sprintf("%d / %d ok\n", count($commands) - $failed, count($commands)));
            }
        });
    }

    /**
     * The list filters of the schema (A6.1), applied from ?f[field]=… and
     * described for the toolbar.
     *
     * @param array<string, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(TypeSchema $schema, Query $query, array $values): array
    {
        $recordFilter = new RecordFilter($this->context, $schema);
        $described = [];

        foreach ($schema->listFilters as $name) {
            $value = $values[$name] ?? '';
            if ($name === 'updatedBy') {
                $described[] = ['name' => $name, 'kind' => 'actor', 'label' => $this->t('ui.updatedBy'), 'value' => is_string($value) ? $value : '',
                    'options' => ['editor' => $this->t('actor.editor'), 'agent' => $this->t('actor.agent'), 'cli' => $this->t('actor.cli'), 'import' => $this->t('actor.import'), 'unseen' => $this->t('ui.unseen')]];
                if ($value === 'unseen') {
                    $query->filter(static fn (Item $item): bool => $item->isUnseenAgentChange());
                } elseif (is_string($value) && $value !== '') {
                    $query->filter(static fn (Item $item): bool => $item->updatedBy === $value);
                }
                continue;
            }

            $field = $schema->field($name);
            switch ($field->type) {
                case 'select':
                    /** @var \Neuedaten\FreezedDesk\Schema\FieldTypes\SelectType $select */
                    $select = $this->context->fieldTypes()->get('select');
                    $described[] = ['name' => $name, 'kind' => 'select', 'label' => $field->label, 'value' => is_string($value) ? $value : '', 'options' => $select->options($field)];
                    if (is_string($value) && $value !== '') {
                        $query->where($name, $value);
                    }
                    break;
                case 'bool':
                    $described[] = ['name' => $name, 'kind' => 'select', 'label' => $field->label, 'value' => is_string($value) ? $value : '', 'options' => ['1' => $this->t('ui.yes'), '0' => $this->t('ui.no')]];
                    if ($value === '1' || $value === '0') {
                        $query->where($name, $value === '1');
                    }
                    break;
                case 'relation':
                    $options = [];
                    foreach ((array) $field->get('to') as $targetType) {
                        foreach ($this->context->repository()->find((string) $targetType)->notArchived()->ordered()->limit(300)->all() as $target) {
                            $options[(string) $target->id] = $target->title;
                        }
                    }
                    $described[] = ['name' => $name, 'kind' => 'select', 'label' => $field->label, 'value' => is_string($value) ? $value : '', 'options' => $options];
                    if (is_string($value) && ctype_digit($value)) {
                        $query->where($name, (int) $value);
                    }
                    break;
                case 'date':
                case 'datetime':
                    $range = is_array($value) ? (string) ($value['range'] ?? '') : (is_string($value) ? $value : '');
                    $from = is_array($value) ? (string) ($value['from'] ?? '') : '';
                    $to = is_array($value) ? (string) ($value['to'] ?? '') : '';
                    $described[] = ['name' => $name, 'kind' => 'date', 'label' => $field->label, 'range' => $range, 'from' => $from, 'to' => $to,
                        'options' => ['today' => $this->t('ui.filter.range.today'), 'next7' => $this->t('ui.filter.range.next7'), 'next14' => $this->t('ui.filter.range.next14'), 'future' => $this->t('ui.filter.range.future'), 'past' => $this->t('ui.filter.range.past')]];
                    try {
                        if ($range !== '') {
                            $recordFilter->namedRange($query, $range, $name);
                        }
                        if ($from !== '' || $to !== '') {
                            $recordFilter->dateRange($query, $from !== '' ? $from : null, $to !== '' ? $to : null, $name);
                        }
                    } catch (DeskException $exception) {
                        $this->flash('error', $exception->getMessage());
                    }
                    break;
            }
        }

        return $described;
    }

    /**
     * One row for any view: cells, preview image, validation messages.
     *
     * @param array<int, array{name: string, label: string, type: string|null, sortable: bool}> $columns
     * @return array<string, mixed>
     */
    private function row(TypeSchema $schema, Item $item, array $columns, string $view): array
    {
        $validation = $this->context->validation()->ofItem($item, $schema->needsUiApproval() ? true : null);
        $options = $schema->listViews[$view] ?? [];
        $shown = [];
        if ($view !== 'table') {
            $shownColumns = array_map(fn (string $name): array => $this->column($schema, $name), $options['fields'] ?? []);
            $shown = $this->cells($schema, $item, $shownColumns);
            foreach ($shownColumns as $column) {
                $shown[$column['name']]['label'] = $column['label'];
            }
        }
        $imageField = $options['image'] ?? null;
        $dateField = $options['field'] ?? null;

        return [
            'item' => $item,
            'cells' => $this->cells($schema, $item, $columns),
            'shown' => $shown,
            'image' => $imageField !== null ? $this->firstMedia($item->data[$imageField] ?? null) : null,
            'date' => $dateField !== null ? (string) ($item->data[$dateField] ?? '') : '',
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'],
            'messages' => implode("\n", array_merge(array_values($validation['errors']), array_values($validation['warnings']))),
            'unseen' => $item->isUnseenAgentChange(),
            'review' => $this->context->reviews()->reviewable($schema->slug) ? $this->context->reviews()->state($item) : null,
        ];
    }

    /**
     * Rows of the agenda, grouped by day of the agenda field (A6.2).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{day: string, label: string, rows: array<int, array<string, mixed>>}>
     */
    private function agendaGroups(TypeSchema $schema, array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $day = $row['date'] !== '' ? substr($row['date'], 0, 10) : '';
            if (!isset($groups[$day])) {
                $label = $this->t('ui.noDate');
                if ($day !== '' && ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day)) !== false) {
                    $label = $this->t('day.' . strtolower($date->format('D'))) . ', ' . $date->format('d.m.Y');
                }
                $groups[$day] = ['day' => $day, 'label' => $label, 'rows' => []];
            }
            $groups[$day]['rows'][] = $row;
        }
        uksort($groups, static fn (string $a, string $b): int => $a === '' ? 1 : ($b === '' ? -1 : strcmp($a, $b)));

        return array_values($groups);
    }

    private function firstMedia(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_int($entry)) {
                    return $entry;
                }
                if (is_array($entry) && isset($entry['image']) && is_int($entry['image'])) {
                    return $entry['image'];
                }
            }
        }

        return null;
    }

    private function item(TypeSchema $schema, array $params): Item
    {
        $item = $this->context->repository()->get($this->intParam($params, 'id'));
        if ($item === null || $item->type !== $schema->slug) {
            throw new NotFoundException($this->t('ui.notFound'));
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private function defaults(TypeSchema $schema): array
    {
        $values = [];
        foreach ($schema->fields as $name => $field) {
            $values[$name] = $this->context->fieldTypes()->get($field->type)->defaultValue($field, $this->context);
        }

        return $values;
    }

    /**
     * Submitted raw values in stored shape, to re-render a form after a
     * failed save.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize(TypeSchema $schema, array $raw): array
    {
        $values = $this->defaults($schema);
        foreach ($schema->fields as $name => $field) {
            if (array_key_exists($name, $raw)) {
                $type = $this->context->fieldTypes()->get($field->type);
                try {
                    $values[$name] = $type->normalize($raw[$name], $field, $this->context);
                } catch (\Throwable) {
                    // keep the default
                }
            }
        }

        return $values;
    }

    /** Kept for templates and extensions that link to the built page. */
    public function previewUrl(TypeSchema $schema, Item $item): ?string
    {
        return (new PreviewUrl($this->context))->of($item);
    }

    /**
     * @return array<int, array{name: string, label: string, type: string|null, sortable: bool}>
     */
    private function columns(TypeSchema $schema): array
    {
        return array_map(fn (string $name): array => $this->column($schema, $name), $schema->listColumns);
    }

    /** @return array{name: string, label: string, type: string|null, sortable: bool} */
    private function column(TypeSchema $schema, string $name): array
    {
        $field = $schema->field($name);

        return [
            'name' => $name,
            'label' => $field?->label ?? $this->t('ui.' . $name),
            'type' => $field?->type,
            'sortable' => !in_array($field?->type, ['image', 'images', 'files', 'relation', 'features', 'hours', 'geo', 'list', 'group', 'json', 'markdown'], true),
        ];
    }

    /**
     * Display values for the list, one per column.
     *
     * @param array<int, array{name: string, label: string, type: string|null, sortable: bool}> $columns
     * @return array<string, array<string, mixed>>
     */
    private function cells(TypeSchema $schema, Item $item, array $columns): array
    {
        $cells = [];
        foreach ($columns as $column) {
            $name = $column['name'];
            $field = $schema->field($name);
            $value = $item->value($name);
            $cell = ['kind' => 'text', 'text' => ''];

            if ($field === null) {
                $cell = match ($name) {
                    'status' => ['kind' => 'status', 'text' => $item->status->value],
                    'variant' => ['kind' => 'text', 'text' => $schema->variant($item->variant)['label'] ?? $item->variant],
                    'updatedAt', 'createdAt', 'publishedAt' => ['kind' => 'date', 'text' => (string) $value],
                    'updatedBy' => ['kind' => 'actor', 'text' => $item->updatedBy],
                    default => ['kind' => 'text', 'text' => (string) $value],
                };
            } else {
                switch ($field->type) {
                    case 'relation':
                        $refs = [];
                        foreach ((array) $value as $pair) {
                            $target = isset($pair['id']) ? $this->context->repository()->get((int) $pair['id']) : null;
                            if ($target !== null) {
                                $refs[] = ['title' => $target->title, 'href' => '/types/' . $target->type . '/' . $target->id];
                            }
                        }
                        $cell = ['kind' => 'refs', 'refs' => $refs];
                        break;
                    case 'image':
                        $cell = ['kind' => 'image', 'id' => is_int($value) ? $value : null];
                        break;
                    case 'images':
                    case 'files':
                        // The first file as preview (A6.3), the count behind it.
                        $cell = ['kind' => 'image', 'id' => $this->firstMedia($value), 'count' => count((array) $value)];
                        break;
                    case 'bool':
                        $cell = ['kind' => 'bool', 'text' => $value ? '✓' : ''];
                        break;
                    case 'select':
                        /** @var \Neuedaten\FreezedDesk\Schema\FieldTypes\SelectType $options */
                        $options = $this->context->fieldTypes()->get('select');
                        $labels = $options->options($field);
                        $keys = is_array($value) ? $value : ($value === null ? [] : [$value]);
                        $cell = ['kind' => 'text', 'text' => implode(', ', array_map(static fn ($k): string => $labels[(string) $k] ?? (string) $k, $keys))];
                        break;
                    case 'number':
                        $cell = ['kind' => 'number', 'text' => $value === null ? '' : (string) $value . ($field->get('unit') ? ' ' . $field->get('unit') : '')];
                        break;
                    case 'date':
                    case 'datetime':
                        $cell = ['kind' => 'date', 'text' => (string) $value];
                        break;
                    case 'markdown':
                    case 'textarea':
                        $cell = ['kind' => 'text', 'text' => mb_strimwidth(trim((string) $value), 0, 80, '…')];
                        break;
                    case 'link':
                        $cell = ['kind' => 'text', 'text' => is_array($value) ? (string) ($value['label'] ?: $value['href']) : ''];
                        break;
                    case 'features':
                    case 'list':
                        $cell = ['kind' => 'count', 'text' => (string) count((array) $value)];
                        break;
                    case 'group':
                    case 'hours':
                    case 'geo':
                    case 'json':
                        $cell = ['kind' => 'text', 'text' => $value === null || $value === [] ? '' : '…'];
                        break;
                    default:
                        $cell = ['kind' => 'text', 'text' => is_scalar($value) ? (string) $value : ''];
                }
            }

            $cells[$name] = $cell;
        }

        return $cells;
    }
}
