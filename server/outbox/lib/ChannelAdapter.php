<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * One channel of the outbox (instagram, bluesky, mail …). An adapter turns
 * a queued message into a post on the platform. It is dependency-free: HTTP
 * goes through the HttpClient of the AdapterContext, so tests replay
 * recorded responses.
 *
 * A server configures its channels in private/config.php:
 *
 *     'channels' => [
 *         'instagram' => ['adapter' => 'instagram', 'igUserId' => '…', 'accessToken' => '…'],
 *         'whatsapp'  => ['adapter' => 'mail', 'to' => 'redaktion@example.org'],
 *     ],
 *
 * The settings array of a channel (without "adapter") is the first
 * constructor argument: new XyzAdapter(array $settings, AdapterContext $context).
 */
interface ChannelAdapter
{
    /**
     * Problems that make the message unsendable on this channel, checked
     * when Desk queues it and again right before sending. Empty = fine.
     *
     * @return string[]
     */
    public function validate(Message $message): array;

    /**
     * Publish the message. Returns the platform's id and the public URL of
     * the post. Throws AdapterException when it fails: temporary() tells
     * the runner whether another attempt may help, accepted() whether the
     * platform may already have taken the post (the runner then never
     * retries, the message becomes "unknown").
     *
     * @throws AdapterException
     */
    public function publish(Message $message, AssetUrls $assets): Result;

    /**
     * Numbers of a published post, keys from Metrics::KEYS (reach,
     * impressions, interactions, likes, comments, shares, saves,
     * linkClicks, views). An empty array when the platform gives none.
     *
     * @return array<string, int>
     */
    public function metrics(string $remoteId): array;

    /**
     * State of the channel: ['ok' => bool, 'message' => string,
     * 'tokenExpiresAt' => ISO 8601 or null]. Used by GET …/outbox/health and
     * for the mail when a token expires within 14 days.
     *
     * @return array{ok: bool, message: string, tokenExpiresAt: ?string}
     */
    public function health(): array;
}
