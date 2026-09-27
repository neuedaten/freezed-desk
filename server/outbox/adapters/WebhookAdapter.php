<?php

declare(strict_types=1);

namespace DeskOutbox\Adapters;

use DeskOutbox\AdapterContext;
use DeskOutbox\AdapterException;
use DeskOutbox\AssetUrls;
use DeskOutbox\ChannelAdapter;
use DeskOutbox\HttpException;
use DeskOutbox\Message;
use DeskOutbox\Result;

/**
 * POSTs the message as JSON to a URL (B3.7): for a service that publishes
 * itself, an automation tool, or a channel that is not built in yet.
 *
 * Settings:
 *
 *     'url'     => 'https://hooks.example.org/outbox',
 *     'secret'  => '…',   // optional: X-Outbox-Signature: sha256=<hmac of the body>
 *     'timeout' => 30,
 *
 * Body: Message::toArray(), each asset with its public "url". A 2xx answer
 * means sent; its JSON may carry "id" and "url" of the post. 429 and 5xx
 * are temporary, other statuses final.
 */
final class WebhookAdapter implements ChannelAdapter
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly array $settings,
        private readonly AdapterContext $context,
    ) {
    }

    public function validate(Message $message): array
    {
        $url = (string) ($this->settings['url'] ?? '');

        return filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)
            ? []
            : ['Webhook adapter: the channel has no valid "url".'];
    }

    public function publish(Message $message, AssetUrls $assets): Result
    {
        $data = $message->toArray();
        foreach ($data['assets'] as $index => $asset) {
            $data['assets'][$index]['url'] = $assets->url($asset['sha256']);
        }
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $secret = (string) ($this->settings['secret'] ?? '');
        if ($secret !== '') {
            $headers['X-Outbox-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        try {
            $response = $this->context->http->request('POST', (string) $this->settings['url'], $headers, $body, (int) ($this->settings['timeout'] ?? 30));
        } catch (HttpException $exception) {
            // Sent but unanswered: the receiver may have acted on it, so never retry.
            throw new AdapterException('Webhook: ' . $exception->getMessage(), temporary: !$exception->sent, accepted: $exception->sent, previous: $exception);
        }

        if (!$response->ok()) {
            throw new AdapterException(
                'Webhook answered HTTP ' . $response->status . ': ' . AdapterException::excerpt($response->body),
                temporary: $response->isTemporaryError(),
            );
        }

        $json = $response->json();
        $id = is_scalar($json['id'] ?? null) && (string) $json['id'] !== ''
            ? (string) $json['id']
            : 'webhook-' . substr(hash('sha256', $message->key . "\n" . $body), 0, 12);
        $url = is_string($json['url'] ?? null) ? $json['url'] : '';

        return new Result($id, $url);
    }

    public function metrics(string $remoteId): array
    {
        return [];
    }

    public function health(): array
    {
        return ['ok' => true, 'message' => 'Webhook to ' . (string) parse_url((string) ($this->settings['url'] ?? ''), PHP_URL_HOST) . '.', 'tokenExpiresAt' => null];
    }
}
