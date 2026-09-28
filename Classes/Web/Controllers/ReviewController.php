<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Export\PreviewUrl;
use Neuedaten\FreezedDesk\Review\ReviewPresenter;
use Neuedaten\FreezedDesk\Review\ReviewRepository;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

/**
 * Reviews (docs/review.md): the queues, the review screen that shows a
 * record read-only with a note under every field, and marking points done.
 */
class ReviewController extends Controller
{
    // ------------------------------------------------------- overview ---

    public function index(Request $request, array $params): Response
    {
        $reviews = $this->context->reviews();
        $types = [];
        foreach ($reviews->types() as $schema) {
            $counts = $reviews->counts($schema);
            $types[] = ['schema' => $schema, 'open' => $counts['open'], 'queues' => $this->tabs($counts)];
        }

        return $this->view('Review/Index', [
            'types' => $types,
            'openTotal' => $reviews->openTotal(),
        ]);
    }

    public function queue(Request $request, array $params): Response
    {
        $schema = $this->reviewSchema((string) $params['type']);
        $queue = $this->queueParam($request);
        $reviews = $this->context->reviews();

        $rows = [];
        foreach ($reviews->queue($schema, $queue) as $item) {
            $rows[] = ['item' => $item, 'state' => $reviews->state($item)];
        }

        return $this->view('Review/Queue', [
            'schema' => $schema,
            'queue' => $queue,
            'queueLabel' => $this->t('review.queue.' . $queue),
            'tabs' => $this->tabs($reviews->counts($schema)),
            'rows' => $rows,
            'first' => $rows[0]['item'] ?? null,
        ]);
    }

    /**
     * @param array<string, int> $counts
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function tabs(array $counts): array
    {
        return array_map(fn (string $q): array => ['key' => $q, 'label' => $this->t('review.queue.' . $q), 'count' => $counts[$q] ?? 0], ReviewRepository::QUEUES);
    }

    // --------------------------------------------------------- screen ---

    public function show(Request $request, array $params): Response
    {
        $schema = $this->reviewSchema((string) $params['type']);
        $item = $this->item($schema, $params);

        return $this->screen($schema, $item, $this->queueParam($request));
    }

    public function submit(Request $request, array $params): Response
    {
        $schema = $this->reviewSchema((string) $params['type']);
        $item = $this->item($schema, $params);
        $queue = $this->queueParam($request);
        $reviews = $this->context->reviews();

        $posted = ['notes' => [], 'comment' => (string) $request->post('comment', '')];
        $points = [['field' => null, 'tags' => [], 'text' => $posted['comment']]];
        $notes = $request->post('notes', []);
        foreach (is_array($notes) ? $notes : [] as $field => $note) {
            if (!is_array($note)) {
                continue;
            }
            $entry = [
                'tags' => array_values(array_map('strval', (array) ($note['tags'] ?? []))),
                'text' => (string) ($note['text'] ?? ''),
            ];
            $posted['notes'][(string) $field] = $entry;
            $points[] = ['field' => (string) $field] + $entry;
        }

        // The record changed while it was on screen: show the new state,
        // keep what was typed.
        if ((string) $request->post('hash', '') !== $reviews->hash($item)) {
            return $this->screen($schema, $item, $queue, $posted, $this->t('review.conflict'), 409);
        }
        $decision = (string) $request->post('decision', '');
        if (!in_array($decision, ReviewRepository::DECISIONS, true)) {
            return $this->screen($schema, $item, $queue, $posted, $this->t('review.noDecision'), 422);
        }

        $result = $reviews->submit($item, $decision, $points, (string) ($_SERVER['PHP_AUTH_USER'] ?? ''));
        $this->flash('success', $this->t('review.saved.' . $decision, ['title' => $item->title]));
        if ($result['rejected'] !== null) {
            $this->flash('error', $this->t('review.notPublished', ['reason' => $result['rejected']]));
        }

        [, $next] = $this->neighbours($schema, $item, $queue);
        if ($next === null) {
            $this->flash('success', $this->t('review.endOfQueue'));

            return $this->redirect('/review/' . $schema->slug . '?queue=' . $queue);
        }

        return $this->redirect('/review/' . $schema->slug . '/' . $next . '?queue=' . $queue);
    }

    /**
     * @param array{notes?: array<string, array{tags: string[], text: string}>, comment?: string} $posted
     */
    private function screen(TypeSchema $schema, Item $item, string $queue, array $posted = [], ?string $error = null, int $status = 200): Response
    {
        $reviews = $this->context->reviews();
        [$previous, $next, $position, $total] = $this->neighbours($schema, $item, $queue);
        $presenter = new ReviewPresenter($this->context);
        $presented = $presenter->present($schema, $item);
        $tags = $reviews->tags();

        // Points of earlier reviews still open, by field ("" = general).
        $open = [];
        foreach ($reviews->openPoints([$item->id]) as $point) {
            $open[$point['field'] ?? ''][] = $point + ['tagLabels' => $this->tagLabels($point['tags'], $tags)];
        }

        $fields = [];
        foreach ($presented['fields'] as $field) {
            $note = $posted['notes'][$field['name']] ?? ['tags' => [], 'text' => ''];
            $options = [];
            foreach ($tags as $key => $label) {
                $options[] = ['key' => $key, 'label' => $label, 'checked' => in_array($key, $note['tags'], true)];
            }
            $fields[] = $field + ['tags' => $options, 'text' => $note['text'], 'open' => $open[$field['name']] ?? []];
        }

        $state = $reviews->state($item);

        return $this->view('Review/Show', [
            'schema' => $schema,
            'item' => $item,
            'queue' => $queue,
            'queueLabel' => $this->t('review.queue.' . $queue),
            'fields' => $fields,
            'urls' => $presenter->urls($schema, $item),
            'emptyFields' => implode(', ', $presented['empty']),
            'generalOpen' => $open[''] ?? [],
            'comment' => $posted['comment'] ?? '',
            'hash' => $reviews->hash($item),
            'state' => $state,
            'lastReview' => $state['review'],
            'historyCount' => count($reviews->forItem($item->id)),
            'previous' => $previous,
            'next' => $next,
            'position' => $position,
            'total' => $total,
            'error' => $error,
            'previewUrl' => (new PreviewUrl($this->context))->of($item),
            'validation' => $this->context->validation()->ofItem($item, true),
            'decisions' => ReviewRepository::DECISIONS,
        ], $status);
    }

    /**
     * The records before and after this one in the queue, found by its
     * place in the type's list, so it works also for a record that just left
     * the queue or was never in it.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null, 3: int} previous id, next id, 1-based position in the queue (null: not in it), queue size
     */
    private function neighbours(TypeSchema $schema, Item $item, string $queue): array
    {
        $reviews = $this->context->reviews();
        $inQueue = array_map(static fn (Item $i): int => $i->id, $reviews->queue($schema, $queue));
        $all = array_map(static fn (Item $i): int => $i->id, $reviews->queue($schema, 'all'));
        $index = array_search($item->id, $all, true);
        $members = array_flip($inQueue);

        $previous = null;
        $next = null;
        if ($index !== false) {
            for ($i = $index - 1; $i >= 0 && $previous === null; $i--) {
                $previous = isset($members[$all[$i]]) ? $all[$i] : null;
            }
            for ($i = $index + 1, $n = count($all); $i < $n && $next === null; $i++) {
                $next = isset($members[$all[$i]]) ? $all[$i] : null;
            }
        }
        $position = array_search($item->id, $inQueue, true);

        return [$previous, $next, $position === false ? null : $position + 1, count($inQueue)];
    }

    // -------------------------------------------------------- history ---

    public function history(Request $request, array $params): Response
    {
        $schema = $this->schema((string) $params['type']);
        $item = $this->item($schema, $params);
        $reviews = $this->context->reviews();
        $tags = $reviews->tags();

        $list = [];
        foreach ($reviews->forItem($item->id) as $review) {
            $review['points'] = array_map(fn (array $p): array => $p + [
                'label' => $this->fieldLabel($schema, $p['field']),
                'tagLabels' => $this->tagLabels($p['tags'], $tags),
            ], $review['points']);
            $list[] = $review;
        }

        return $this->view('Review/History', [
            'schema' => $schema,
            'item' => $item,
            'reviews' => $list,
            'reviewable' => $reviews->reviewable($schema->slug),
        ]);
    }

    // ----------------------------------------------------------- done ---

    public function pointDone(Request $request, array $params): Response
    {
        $point = $this->context->reviews()->markDone($this->intParam($params, 'id'), (string) $request->post('note', ''));
        $this->flash('success', $this->t('review.pointDone'));

        return $this->back($request, $point['reviewId']);
    }

    public function pointReopen(Request $request, array $params): Response
    {
        $point = $this->context->reviews()->reopen($this->intParam($params, 'id'));

        return $this->back($request, $point['reviewId']);
    }

    public function reviewDone(Request $request, array $params): Response
    {
        $id = $this->intParam($params, 'id');
        $marked = $this->context->reviews()->markReviewDone($id, (string) $request->post('note', ''));
        $this->flash('success', $this->t('review.pointsDone', ['count' => count($marked)]));

        return $this->back($request, $id);
    }

    // -------------------------------------------------------- helpers ---

    /** Back to where the button was, else the record's review history. */
    private function back(Request $request, int $reviewId): Response
    {
        $back = (string) $request->post('back', '');
        if ($back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//')) {
            return $this->redirect($back);
        }
        $review = $this->context->reviews()->require($reviewId);
        $item = $this->context->repository()->require($review['itemId']);

        return $this->redirect('/types/' . $item->type . '/' . $item->id . '/reviews');
    }

    private function reviewSchema(string $type): TypeSchema
    {
        $schema = $this->schema($type);
        if (!$this->context->reviews()->reviewable($schema->slug)) {
            throw new NotFoundException($this->t('ui.notFound'));
        }

        return $schema;
    }

    private function item(TypeSchema $schema, array $params): Item
    {
        $item = $this->context->repository()->get($this->intParam($params, 'id'));
        if ($item === null || $item->type !== $schema->slug) {
            throw new NotFoundException($this->t('ui.notFound'));
        }

        return $item;
    }

    private function queueParam(Request $request): string
    {
        $queue = (string) $request->input('queue', 'open');

        return in_array($queue, ReviewRepository::QUEUES, true) ? $queue : 'open';
    }

    private function fieldLabel(TypeSchema $schema, ?string $field): string
    {
        if ($field === null) {
            return $this->t('review.general');
        }

        return $schema->field($field)?->label ?? $field;
    }

    /**
     * @param string[] $keys
     * @param array<string, string> $tags
     * @return string[]
     */
    private function tagLabels(array $keys, array $tags): array
    {
        return array_map(static fn (string $key): string => $tags[$key] ?? $key, $keys);
    }
}
