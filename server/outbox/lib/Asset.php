<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * A file a message needs, uploaded before the message (PUT …/outbox/assets/<sha256>).
 */
final class Asset
{
    public const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'video/mp4' => 'mp4',
    ];

    public function __construct(
        public readonly string $sha256,
        public readonly string $mime,
        public readonly string $role = 'image',
        public readonly int $size = 0,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sha256: strtolower((string) ($data['sha256'] ?? '')),
            mime: (string) ($data['mime'] ?? ''),
            role: (string) ($data['role'] ?? 'image'),
            size: (int) ($data['size'] ?? 0),
        );
    }

    /** @return array{sha256: string, mime: string, role: string, size: int} */
    public function toArray(): array
    {
        return ['sha256' => $this->sha256, 'mime' => $this->mime, 'role' => $this->role, 'size' => $this->size];
    }

    public function extension(): string
    {
        return self::MIME_EXTENSIONS[$this->mime] ?? 'bin';
    }

    public static function isValidSha(string $sha256): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $sha256);
    }
}
