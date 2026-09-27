<?php

namespace Neuedaten\FreezedDesk\Media;

/**
 * One uploaded file: where it is (relative to the media root), what it is
 * and what an editor said about it.
 */
final readonly class Media
{
    public const ORIGINS = ['upload', 'import', 'generated'];

    /**
     * @param array<string, mixed>      $extra       Values of the project's media fields (desk.media.fields, A7).
     * @param array<string, mixed>|null $generatedBy {type, id, generator} of a generated file (A8).
     */
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
        public array $extra = [],
        public string $origin = 'upload',
        public ?array $generatedBy = null,
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
            extra: self::decode($row['extra'] ?? null) ?? [],
            origin: (string) ($row['origin'] ?? 'upload'),
            generatedBy: self::decode($row['generated_by'] ?? null),
        );
    }

    /** @return array<string, mixed>|null */
    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime, 'video/');
    }

    public function isGenerated(): bool
    {
        return $this->origin === 'generated';
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
            'extra' => $this->extra,
            'origin' => $this->origin,
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
            'extra' => $this->extra === [] ? new \stdClass() : $this->extra,
            'origin' => $this->origin,
            'generatedBy' => $this->generatedBy,
        ];
    }
}
