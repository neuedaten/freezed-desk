<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * A message as Desk pushes it (POST …/outbox/messages).
 *
 * The payload has the same shape on every channel; an adapter uses what it
 * needs:
 *
 *     kind     "image" | "carousel" | "reel" | "story" | "text" | "link"
 *     text     the full text as it is posted (caption with hashtags, credit, link)
 *     media    [{sha256, mime, role: "image"|"video"|"cover", alt}]  in order
 *     link     {url, title, description, thumb: sha256|null} | null
 *     lang     "de"
 *     options  channel-specific extras, e.g. {"subject": "…"} for mail
 */
final class Message
{
    /**
     * @param array<string, mixed> $payload
     * @param Asset[]              $assets
     */
    public function __construct(
        public readonly string $key,
        public readonly string $channel,
        public readonly \DateTimeImmutable $at,
        public readonly array $payload,
        public readonly array $assets = [],
    ) {
    }

    /** @param array<string, mixed> $data Decoded JSON of the POST body or a stored row. */
    public static function fromArray(array $data): self
    {
        $assets = [];
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (is_array($asset)) {
                $assets[] = Asset::fromArray($asset);
            }
        }

        return new self(
            key: (string) ($data['key'] ?? ''),
            channel: (string) ($data['channel'] ?? ''),
            at: (new \DateTimeImmutable((string) ($data['at'] ?? 'now')))->setTimezone(new \DateTimeZone('UTC')),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            assets: $assets,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'channel' => $this->channel,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
            'payload' => $this->payload,
            'assets' => array_map(static fn (Asset $a): array => $a->toArray(), $this->assets),
        ];
    }

    public function text(): string
    {
        return (string) ($this->payload['text'] ?? '');
    }

    public function kind(): string
    {
        return (string) ($this->payload['kind'] ?? 'text');
    }

    /** @return array<int, array{sha256: string, mime: string, role: string, alt: string}> */
    public function media(): array
    {
        $media = [];
        foreach ((array) ($this->payload['media'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['sha256'])) {
                $media[] = [
                    'sha256' => (string) $entry['sha256'],
                    'mime' => (string) ($entry['mime'] ?? ''),
                    'role' => (string) ($entry['role'] ?? 'image'),
                    'alt' => (string) ($entry['alt'] ?? ''),
                ];
            }
        }

        return $media;
    }

    /** @return array{url: string, title: string, description: string, thumb: ?string}|null */
    public function link(): ?array
    {
        $link = $this->payload['link'] ?? null;
        if (!is_array($link) || ($link['url'] ?? '') === '') {
            return null;
        }

        return [
            'url' => (string) $link['url'],
            'title' => (string) ($link['title'] ?? ''),
            'description' => (string) ($link['description'] ?? ''),
            'thumb' => isset($link['thumb']) && $link['thumb'] !== '' ? (string) $link['thumb'] : null,
        ];
    }

    public function option(string $name, mixed $default = null): mixed
    {
        $options = $this->payload['options'] ?? [];

        return is_array($options) && array_key_exists($name, $options) ? $options[$name] : $default;
    }
}
