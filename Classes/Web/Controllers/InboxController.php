<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Inbox\InboxClient;
use Neuedaten\FreezedDesk\Inbox\InboxRepository;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

class InboxController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $status = (string) $request->get('status', '');
        $statuses = match ($status) {
            'new', 'open', 'done', 'spam' => [$status],
            'all' => null,
            default => ['new', 'open'],
        };

        $entries = [];
        foreach ($this->context->inbox()->all($statuses) as $entry) {
            $entries[] = $this->decorate($entry);
        }

        $client = new InboxClient($this->context);

        return $this->view('Inbox/Index', [
            'entries' => $entries,
            'status' => $status,
            'configured' => $client->isConfigured(),
            'counts' => [
                'open' => $this->context->inbox()->count(['new', 'open']),
                'done' => $this->context->inbox()->count(['done']),
                'spam' => $this->context->inbox()->count(['spam']),
            ],
        ]);
    }

    public function fetch(Request $request, array $params): Response
    {
        try {
            $count = (new InboxClient($this->context))->fetch();
            $this->flash('success', $this->t('ui.inbox.fetched', ['count' => $count]));
        } catch (DeskException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        return $this->redirect('/inbox');
    }

    public function show(Request $request, array $params): Response
    {
        $entry = $this->context->inbox()->require($this->intParam($params, 'id'));
        if ($entry['status'] === 'new') {
            $entry = $this->context->inbox()->update($entry['id'], ['status' => 'open']);
        }

        return $this->view('Inbox/Show', ['entry' => $this->decorate($entry)]);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->intParam($params, 'id');
        $changes = [];
        if ($request->post('status') !== null) {
            $changes['status'] = (string) $request->post('status');
        }
        if ($request->post('note') !== null) {
            $changes['note'] = (string) $request->post('note');
        }
        if ($request->post('item') !== null) {
            $item = (string) $request->post('item');
            if ($item === '') {
                $changes['itemId'] = null;
            } elseif (str_contains($item, ':')) {
                $changes['itemId'] = (int) explode(':', $item, 2)[1];
            } elseif (ctype_digit($item)) {
                $changes['itemId'] = (int) $item;
            }
        }
        $this->context->inbox()->update($id, $changes);
        $this->flash('success', $this->t('ui.saved'));

        return $this->redirect((string) ($request->post('back') ?: '/inbox/' . $id));
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function decorate(array $entry): array
    {
        $item = $entry['itemId'] !== null ? $this->context->repository()->get($entry['itemId']) : null;
        $form = $this->context->forms()->has($entry['form']) ? $this->context->forms()->get($entry['form']) : null;

        $fields = [];
        $labels = $form?->fields() ?? [];
        foreach ($entry['payload'] as $key => $value) {
            $fields[] = [
                'name' => (string) $key,
                'label' => (string) ($labels[$key]['label'] ?? $key),
                'value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            ];
        }

        $summary = '';
        foreach (['message', 'text', 'nachricht', 'body'] as $key) {
            if (isset($entry['payload'][$key]) && is_string($entry['payload'][$key])) {
                $summary = mb_strimwidth(trim($entry['payload'][$key]), 0, 120, '…');
                break;
            }
        }

        return $entry + [
            'item' => $item,
            'formLabel' => $form?->label ?? $entry['form'],
            'fields' => $fields,
            'summary' => $summary,
            'itemTypes' => $form?->itemType !== null ? [$form->itemType] : array_keys($this->context->schemas()->built()),
        ];
    }
}
