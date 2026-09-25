<?php

namespace Neuedaten\FreezedDesk\Media;

/**
 * One uploaded file: where it is (relative to the media root), what it is
 * and what an editor said about it.
 */
final readonly class Media
{
    public function __construct(
        public int $id,
        public string $file,
        public string $hash,
        public string $mime,
        public int $size,
        public ?int $width,
        public ?int $height,
        public string $alt,
        public string $caption,
        public string $credit,
        public string $license,
        public ?float $focalX,
        public ?float $focalY,
        public string $originalName,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            file: (string) $row['file'],
            hash: (string) $row['hash'],
            mime: (string) $row['mime'],
            size: (int) $row['size'],
            width: isset($row['width']) ? (int) $row['width'] : null,
            height: isset($row['height']) ? (int) $row['height'] : null,
            alt: (string) $row['alt'],
            caption: (string) $row['caption'],
            credit: (string) $row['credit'],
            license: (string) $row['license'],
            focalX: isset($row['focal_x']) ? (float) $row['focal_x'] : null,
            focalY: isset($row['focal_y']) ? (float) $row['focal_y'] : null,
            originalName: (string) $row['original_name'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isSvg(): bool
    {
        return $this->mime === 'image/svg+xml';
    }

    /**
     * The template variable: src relative to the media root, so a template
     * writes {freezed:image(src: hero.src, context: 'media', width: 1200)}.
     *
     * @return array<string, mixed>
     */
    public function toVariables(): array
    {
        return [
            'id' => $this->id,
            'src' => $this->file,
            'alt' => $this->alt,
            'caption' => $this->caption,
            'credit' => $this->credit,
            'license' => $this->license,
            'width' => $this->width,
            'height' => $this->height,
            'focal' => [
                'x' => $this->focalX ?? 0.5,
                'y' => $this->focalY ?? 0.5,
            ],
            'mime' => $this->mime,
            'size' => $this->size,
            'name' => $this->originalName,
        ];
    }

    /** @return array<string, mixed> For the JSON export, keyed by file. */
    public function toExport(): array
    {
        return [
            'file' => $this->file,
            'hash' => $this->hash,
            'mime' => $this->mime,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'caption' => $this->caption,
            'credit' => $this->credit,
            'license' => $this->license,
            'focal' => $this->focalX === null ? null : ['x' => $this->focalX, 'y' => $this->focalY],
            'originalName' => $this->originalName,
            'createdAt' => $this->createdAt,
        ];
    }
}
