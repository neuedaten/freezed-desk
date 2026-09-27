<?php

namespace Neuedaten\FreezedDesk\Outbox;

/**
 * A message the outbox pushes to the server (B2.3): one post on one
 * channel at one time.
 *
 * key      unique and stable, e.g. "posts/2027-05-06-serie-km-70#instagram"
 * channel  a channel configured on the server (instagram, bluesky, whatsapp …)
 * at       when to publish, with time zone
 * payload  JSON for the channel's adapter: kind, text, media [{sha256, mime, role, alt}],
 *          link {url, title, description, thumb}, lang, options (see server/outbox/lib/Message.php)
 * assets   the files the payload references, uploaded before the message
 */
final readonly class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload
     * @param OutboxAsset[]        $assets
     */
    public function __construct(
        public string $key,
        public string $channel,
        public \DateTimeImmutable $at,
        public array $payload,
        public array $assets = [],
    ) {
    }

    /**
     * Over everything that is sent: a changed hash means the server's copy
     * is outdated (B2.4).
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode([
            $this->channel,
            $this->at->format(\DateTimeInterface::ATOM),
            $this->payload,
            array_map(static fn (OutboxAsset $a): array => [$a->sha256, $a->mime, $a->role], $this->assets),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> The JSON body of POST …/outbox/messages. */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'channel' => $this->channel,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
            'payload' => $this->payload,
            'assets' => array_map(static fn (OutboxAsset $a): array => ['sha256' => $a->sha256, 'mime' => $a->mime, 'role' => $a->role, 'size' => $a->size], $this->assets),
            'hash' => $this->hash(),
        ];
    }
}
