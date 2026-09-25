<?php

namespace Neuedaten\FreezedDesk\Inbox;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * Fetches submissions from the website's endpoint (desk.inbox):
 *
 *   GET  <url>?since=<last id>     → {"items": [{"id", "form", "receivedAt", "fields"}, …]}
 *   POST <url>/ack {"ids": […]}    confirms what was stored
 *
 * both with "Authorization: Bearer <token>", the token read from the
 * environment variable named by desk.inbox.tokenEnv. The last remote id is
 * kept in the settings table, so every fetch only asks for what is new.
 */
final class InboxClient
{
    public function __construct(private readonly DeskContext $context)
    {
    }

    public function isConfigured(): bool
    {
        $inbox = $this->context->config->get('inbox');

        return is_array($inbox) && !empty($inbox['url']);
    }

    /**
     * @return int Number of new submissions stored.
     */
    public function fetch(): int
    {
        $inbox = $this->context->config->get('inbox');
        if (!is_array($inbox) || empty($inbox['url'])) {
            throw new DeskException($this->context->t('ui.inbox.notConfigured'));
        }

        $url = rtrim((string) $inbox['url'], '/');
        $tokenEnv = (string) ($inbox['tokenEnv'] ?? 'DESK_INBOX_TOKEN');
        $token = (string) getenv($tokenEnv);
        if ($token === '') {
            throw new DeskException(sprintf('The inbox token is empty: set the environment variable %s.', $tokenEnv));
        }

        $repository = $this->context->repository();
        $since = (string) $repository->setting('inbox:since', '0');

        $response = $this->request('GET', $url . '?since=' . rawurlencode($since), $token);
        $items = $response['items'] ?? null;
        if (!is_array($items)) {
            throw new DeskException('The inbox endpoint returned no "items" list.');
        }

        $stored = 0;
        $ids = [];
        $maxId = $since;
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'], $item['form'])) {
                continue;
            }
            $remoteId = (string) $item['id'];
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $receivedAt = isset($item['receivedAt']) ? (string) $item['receivedAt'] : null;
            if ($this->context->inbox()->add($remoteId, (string) $item['form'], $fields, $receivedAt) !== null) {
                $stored++;
            }
            $ids[] = $remoteId;
            if (is_numeric($remoteId) && (float) $remoteId > (float) $maxId) {
                $maxId = $remoteId;
            }
        }

        if ($ids !== []) {
            $this->request('POST', $url . '/ack', $token, ['ids' => $ids]);
            $repository->setSetting('inbox:since', $maxId);
        }

        return $stored;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, string $token, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_POSTFIELDS => $payload,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            if ($response === false) {
                throw new DeskException('Inbox request failed: ' . $error);
            }
        } else {
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload ?? '',
                'timeout' => 30,
                'ignore_errors' => true,
            ]]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                throw new DeskException('Inbox request failed: could not reach ' . $url);
            }
            $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        }

        if ($status < 200 || $status >= 300) {
            throw new DeskException(sprintf('Inbox endpoint answered with HTTP %d: %s', $status, mb_strimwidth((string) $response, 0, 300, '…')));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new DeskException('Inbox endpoint returned no JSON.');
        }

        return $decoded;
    }
}
