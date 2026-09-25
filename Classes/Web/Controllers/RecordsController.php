<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\Status;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

/**
 * Lists and forms for the records of a type.
 */
class RecordsController extends Controller
{
    private const PER_PAGE = 50;

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

        $sortable = array_merge(array_keys($schema->fields), ['slug', 'variant', 'status', 'updatedAt', 'createdAt', 'publishedAt', 'sort']);
        if ($sort !== '' && in_array($sort, $sortable, true)) {
            $query->orderBy($sort, $dir);
        } else {
            $query->ordered();
        }

        $all = $query->all();
        $total = count($all);
        $items = array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $columns = $this->columns($schema);
        $rows = [];
        foreach ($items as $item) {
            $rows[] = ['item' => $item, 'cells' => $this->cells($schema, $item, $columns)];
        }

        $relItem = $rel !== '' && ctype_digit($rel) ? $repository->get((int) $rel) : null;
        $sortMode = array_key_first($schema->orderBy) === 'sort' && $sort === '' && $q === '' && $rel === '' && $feature === '';

        return $this->view('Records/Index', [
            'schema' => $schema,
            'rows' => $rows,
            'columns' => $columns,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'q' => $q,
            'status' => $status,
            'sort' => $sort,
            'dir' => $dir,
            'relItem' => $relItem,
            'feature' => $feature,
            'counts' => $repository->counts($schema->slug),
            'sortMode' => $sortMode,
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

        try {
            $saved = $this->context->repository()->save($schema->slug, $input, $item?->id, 'ui');
        } catch (ValidationException $exception) {
            $values = $this->normalize($schema, $input['fields']);
            $this->flash('error', $this->t('ui.errors'));

            return $this->form($schema, $item, $values, $exception->errors, $request, $input, 422);
        }

        $this->flash('success', $action === 'publish' ? $this->t('ui.published') : $this->t('ui.saved'));

        $inbox = $request->post('inbox');
        $suffix = is_string($inbox) && ctype_digit($inbox) ? '?inbox=' . $inbox : '';

        return $this->redirect('/types/' . $schema->slug . '/' . $saved->id . $suffix);
    }

    /**
     * @param array<string, mixed> $values Stored-shape field values.
     * @param array<string, string> $errors
     * @param array<string, mixed> $submitted slug/variant/status as posted (after a failed save).
     */
    private function form(TypeSchema $schema, ?Item $item, array $values, array $errors, Request $request, array $submitted = [], int $status = 200): Response
    {
        $repository = $this->context->repository();

        $inboxEntry = null;
        $inboxId = $request->input('inbox');
        if (is_string($inboxId) && ctype_digit($inboxId)) {
            $inboxEntry = $this->context->inbox()->get((int) $inboxId);
        }

        return $this->view('Records/Edit', [
            'schema' => $schema,
            'item' => $item,
            'values' => $values,
            'errors' => $errors,
            'slug' => $submitted['slug'] ?? $item?->slug ?? '',
            'variant' => $submitted['variant'] ?? $item?->variant ?? $schema->defaultVariant(),
            'status' => $item?->status->value ?? Status::Draft->value,
            'previewUrl' => $item !== null ? $this->previewUrl($schema, $item) : null,
            'referencing' => $item !== null ? $repository->referencing($item->id) : [],
            'revisionCount' => $item !== null ? count($repository->revisions($item->id)) : 0,
            'inboxEntry' => $inboxEntry,
            'isNew' => $item === null,
        ], $status);
    }

    // -------------------------------------------------------- actions ---

    public function status(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $status = Status::fromInput($request->post('status'), $item->status);

        try {
            $this->context->repository()->setStatus([$item->id], $status);
            $this->flash('success', $this->t('ui.saved'));
        } catch (ValidationException $exception) {
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
            try {
                $count = $repository->setStatus($ids, Status::from($action));
                $this->flash('success', $this->t('ui.statusChanged', ['count' => $count]));
            } catch (ValidationException $exception) {
                $this->flash('error', $exception->getMessage());
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

    public function preview(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $url = $this->previewUrl($schema, $item) ?? throw new NotFoundException($this->t('ui.notFound'));

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
            'diff' => $selected !== null ? $this->diff($schema, $selected['data'], $item) : [],
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

    public function previewUrl(TypeSchema $schema, Item $item): ?string
    {
        if (!$schema->built) {
            return null;
        }
        $config = $this->context->contentTypeConfig($schema->slug) ?? [];
        $directory = trim(str_replace('\\', '/', (string) ($config['targetDirectory'] ?? '')), '/');
        $extension = (string) ($config['targetFileExtension'] ?? 'html');
        $file = $item->slug . '.' . $extension;
        $path = '/' . ($directory !== '' ? $directory . '/' : '') . preg_replace('/(^|\/)index\.[a-z0-9]+$/i', '$1', $file);

        return $this->context->config->previewUrl() . $path;
    }

    /**
     * @return array<int, array{name: string, label: string, type: string|null, sortable: bool}>
     */
    private function columns(TypeSchema $schema): array
    {
        $columns = [];
        foreach ($schema->listColumns as $name) {
            $field = $schema->field($name);
            $columns[] = [
                'name' => $name,
                'label' => $field?->label ?? $this->t('ui.' . $name),
                'type' => $field?->type,
                'sortable' => !in_array($field?->type, ['image', 'images', 'files', 'relation', 'features', 'hours', 'geo', 'list', 'group', 'json', 'markdown'], true),
            ];
        }

        return $columns;
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
                        $cell = ['kind' => 'count', 'text' => (string) count((array) $value)];
                        break;
                    case 'bool':
                        $cell = ['kind' => 'bool', 'text' => $value ? '✓' : ''];
                        break;
                    case 'select':
                        $options = $this->context->fieldTypes()->get('select');
                        /** @var \Neuedaten\FreezedDesk\Schema\FieldTypes\SelectType $options */
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

    /**
     * Field-by-field differences between a revision and the current record.
     *
     * @param array<string, mixed> $snapshot
     * @return array<int, array{field: string, label: string, then: string, now: string}>
     */
    private function diff(TypeSchema $schema, array $snapshot, Item $item): array
    {
        $rows = [];
        $current = $item->snapshot();
        foreach (['slug' => $this->t('ui.slug'), 'variant' => $this->t('ui.variant'), 'status' => $this->t('ui.status')] as $key => $label) {
            if (($snapshot[$key] ?? null) !== ($current[$key] ?? null)) {
                $rows[] = ['field' => $key, 'label' => $label, 'then' => (string) ($snapshot[$key] ?? ''), 'now' => (string) ($current[$key] ?? '')];
            }
        }
        $thenData = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        foreach ($schema->fields as $name => $field) {
            $then = $thenData[$name] ?? null;
            $now = $item->data[$name] ?? null;
            if ($then == $now) {
                continue;
            }
            $rows[] = [
                'field' => $name,
                'label' => $field->label,
                'then' => self::pretty($then),
                'now' => self::pretty($now),
            ];
        }

        return $rows;
    }

    private static function pretty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
