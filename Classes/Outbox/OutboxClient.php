<?php

namespace Neuedaten\FreezedDesk\Outbox;

use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * The Desk side of the outbox protocol (B1, docs/outbox.md), with the
 * Bearer token from the environment variable named by desk.outbox.tokenEnv.
 * Only Desk talks to the server; an agent never needs the token.
 */
final class OutboxClient
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly OutboxTransport $transport = new CurlTransport(),
    ) {
    }

    public function assetExists(string $sha256): bool
    {
        return $this->call('GET', '/assets/' . $sha256, allow: [404])['status'] === 200;
    }

    public function putAsset(OutboxAsset $asset): void
    {
        $stream = fopen($asset->file, 'rb');
        if ($stream === false) {
            throw new DeskException('Cannot read ' . $asset->file . '.');
        }
        try {
            $this->call('PUT', '/assets/' . $asset->sha256, $stream, ['Content-Type' => $asset->mime]);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Queue (or replace) a message. A message the server has already sent
     * or is sending comes back as {"conflict": true, "state": …}; a message
     * it refuses as {"problems": […]}.
     *
     * @return array<string, mixed>
     */
    public function postMessage(OutboxMessage $message): array
    {
        $response = $this->call('POST', '/messages', json_encode($message->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json'], allow: [409, 422]);
        $data = self::decode($response['body']);
        if ($response['status'] === 409) {
            return ['conflict' => true, 'state' => (string) ($data['state'] ?? ''), 'error' => (string) ($data['error'] ?? '')];
        }
        if ($response['status'] === 422) {
            $problems = [];
            foreach ((array) ($data['problems'] ?? []) as $problem) {
                $problems[] = is_array($problem) ? (string) ($problem['message'] ?? json_encode($problem)) : (string) $problem;
            }

            return ['problems' => $problems !== [] ? $problems : [(string) ($data['error'] ?? 'refused')]];
        }

        return $data;
    }

    /**
     * Withdraw a queued message. A message that is already out comes back
     * as {"conflict": true, "state": …}.
     *
     * @return array<string, mixed>
     */
    public function withdraw(string $key): array
    {
        $response = $this->call('DELETE', '/messages/' . rawurlencode($key), allow: [404, 409]);
        $data = self::decode($response['body']);

        return match ($response['status']) {
            404 => ['missing' => true],
            409 => ['conflict' => true, 'state' => (string) ($data['state'] ?? '')],
            default => $data + ['ok' => true],
        };
    }

    /**
     * A person's decision about a message in state "unknown" (B3.4): it is
     * out (sent), it is not (failed), or send it again (queued).
     *
     * @return array<string, mixed>
     */
    public function resolve(string $key, string $state, string $url = '', string $note = ''): array
    {
        return self::decode($this->call('POST', '/messages/' . rawurlencode($key) . '/resolve', json_encode(['state' => $state, 'url' => $url, 'note' => $note], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json'])['body']);
    }

    /** @return OutboxResult[] */
    public function results(int $since): array
    {
        $data = self::decode($this->call('GET', '/results?since=' . $since)['body']);

        return array_map(OutboxResult::fromArray(...), array_values(array_filter((array) ($data['results'] ?? []), 'is_array')));
    }

    /** @param int[] $ids */
    public function ack(array $ids): void
    {
        if ($ids !== []) {
            $this->call('POST', '/results/ack', json_encode(['ids' => array_values($ids)], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
        }
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return self::decode($this->call('GET', '/health')['body']);
    }

    /** @return array<int, array<string, mixed>> Messages as the server has them. */
    public function messages(?string $state = null): array
    {
        $data = self::decode($this->call('GET', '/messages' . ($state !== null ? '?state=' . rawurlencode($state) : ''))['body']);

        return array_values(array_filter((array) ($data['messages'] ?? []), 'is_array'));
    }

    /** @return array<int, array<string, mixed>> Metrics of sent messages (C9.1). */
    public function metrics(?string $since = null): array
    {
        $data = self::decode($this->call('GET', '/metrics' . ($since !== null ? '?since=' . rawurlencode($since) : ''))['body']);

        return array_values(array_filter((array) ($data['metrics'] ?? []), 'is_array'));
    }

    /**
     * @param array<string, string> $headers
     * @param int[] $allow Error statuses the caller handles itself.
     * @return array{status: int, body: string}
     */
    private function call(string $method, string $path, mixed $body = null, array $headers = [], array $allow = []): array
    {
        $response = $this->transport->request($method, rtrim($this->url, '/') . $path, $headers + [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ], $body);
        $status = $response['status'];
        if (($status < 200 || $status >= 300) && !in_array($status, $allow, true)) {
            $data = json_decode($response['body'], true);
            $message = is_array($data) && isset($data['error']) ? (string) $data['error'] : mb_strimwidth($response['body'], 0, 300, '…');
            throw new DeskException(sprintf('Outbox %s %s: HTTP %d %s', $method, strtok($path, '?'), $status, $message));
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private static function decode(string $body): array
    {
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }
}
