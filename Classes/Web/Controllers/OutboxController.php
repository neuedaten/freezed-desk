<?php

namespace Neuedaten\FreezedDesk\Web\Controllers;

use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Outbox\Outbox;
use Neuedaten\FreezedDesk\Web\Request;
use Neuedaten\FreezedDesk\Web\Response;

/**
 * The outbox page (B2.10): what goes out with "Senden", the state of every
 * message, errors and "unknown" ones to decide about.
 */
class OutboxController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $outbox = new Outbox($this->context);
        $status = $outbox->status();
        $plan = [];
        $planError = null;
        try {
            foreach ($outbox->plan() as $entry) {
                if ($entry['action'] === 'unchanged') {
                    continue;
                }
                $plan[] = $entry + ['record' => $entry['item'], 'label' => self::channelLabel($entry['key'], $entry['channel'])];
            }
        } catch (DeskException $exception) {
            $planError = $exception->getMessage();
        }
        $toSend = count(array_filter($plan, static fn (array $e): bool => in_array($e['action'], ['push', 'withdraw'], true)));

        $messages = [];
        foreach ($status['messages'] as $message) {
            $messages[] = $message + ['record' => $message['itemId'] !== null ? $this->context->repository()->get($message['itemId']) : null, 'label' => self::channelLabel($message['key'], $message['channel'])];
        }
        usort($messages, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $this->view('Outbox/Index', [
            'plan' => $plan,
            'planError' => $planError,
            'toSend' => $toSend,
            'messages' => $messages,
            'outboxStatus' => $status,
        ]);
    }

    /**
     * The channel as the record names it (the key's part after "#", e.g.
     * "story"), with the server channel when that differs.
     */
    private static function channelLabel(string $key, string $channel): string
    {
        $own = str_contains($key, '#') ? substr($key, (int) strrpos($key, '#') + 1) : $channel;

        return $own === $channel ? $channel : $own . ' (' . $channel . ')';
    }

    /** "Senden": a person's step (B2.8). */
    public function push(Request $request, array $params): Response
    {
        try {
            $summary = (new Outbox($this->context))->push();
            if ($summary['pushed'] === [] && $summary['withdrawn'] === [] && $summary['errors'] === []) {
                $this->flash('success', $this->t('outbox.nothingToSend'));
            } else {
                $this->flash('success', $this->t('outbox.pushed', ['pushed' => count($summary['pushed']), 'withdrawn' => count($summary['withdrawn'])]));
            }
            if ($summary['errors'] !== []) {
                $this->flash('error', $this->t('outbox.pushErrors', ['count' => count($summary['errors'])]) . ' ' . implode(' · ', array_map(static fn (string $k, string $e): string => $k . ': ' . $e, array_keys($summary['errors']), $summary['errors'])));
            }
            foreach ($summary['problems'] as $key => $problems) {
                $this->flash('error', $key . ': ' . implode(' ', $problems));
            }
        } catch (DeskException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        return $this->redirect('/outbox');
    }

    public function pull(Request $request, array $params): Response
    {
        try {
            $result = (new Outbox($this->context))->pull();
            $this->flash('success', $this->t('outbox.pulled', ['count' => $result['results']]));
        } catch (DeskException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        return $this->redirect((string) ($request->post('back') ?: '/outbox'));
    }

    /** A person's decision about an "unknown" message (B3.4). */
    public function resolve(Request $request, array $params): Response
    {
        try {
            (new Outbox($this->context))->resolve((string) $request->post('key', ''), (string) $request->post('state', ''), trim((string) $request->post('url', '')));
            $this->flash('success', $this->t('ui.saved'));
        } catch (DeskException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        return $this->redirect('/outbox');
    }
}
