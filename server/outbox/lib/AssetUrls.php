<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * Where the platforms fetch a message's files: public URLs below
 * …/outbox/media/<sha256>.<ext> (no token, not guessable), and the local
 * path for adapters that upload the bytes themselves (Bluesky, mail).
 */
final class AssetUrls
{
    /** @param array<string, Asset> $assets sha256 => asset */
    public function __construct(
        private readonly string $publicBase,
        private readonly string $mediaPath,
        private readonly array $assets,
    ) {
    }

    public function url(string $sha256): string
    {
        return rtrim($this->publicBase, '/') . '/' . $sha256 . '.' . $this->asset($sha256)->extension();
    }

    public function path(string $sha256): string
    {
        return rtrim($this->mediaPath, '/') . '/' . $sha256 . '.' . $this->asset($sha256)->extension();
    }

    public function mime(string $sha256): string
    {
        return $this->asset($sha256)->mime;
    }

    public function asset(string $sha256): Asset
    {
        return $this->assets[$sha256] ?? throw new AdapterException('Asset ' . $sha256 . ' is not part of the message.', temporary: false);
    }
}
