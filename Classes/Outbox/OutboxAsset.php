<?php

namespace Neuedaten\FreezedDesk\Outbox;

use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * A file of a message: a local path plus what the server needs to check
 * it. Created with fromFile(), which hashes the file once.
 */
final readonly class OutboxAsset
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'video/mp4'];

    public function __construct(
        public string $file,
        public string $sha256,
        public string $mime,
        public string $role = 'image',
        public int $size = 0,
    ) {
    }

    public static function fromFile(string $file, string $role = 'image', ?string $mime = null): self
    {
        if (!is_file($file)) {
            throw new DeskException('Outbox asset not found: ' . $file);
        }
        $mime ??= \Neuedaten\FreezedDesk\Media\MediaRepository::detectMime($file);
        if (!in_array($mime, self::MIME_TYPES, true)) {
            throw new DeskException(sprintf('Outbox assets must be JPEG, PNG or MP4; %s is %s.', basename($file), $mime));
        }

        return new self($file, (string) hash_file('sha256', $file), $mime, $role, (int) filesize($file));
    }
}
