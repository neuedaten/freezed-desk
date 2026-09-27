<?php

namespace Neuedaten\FreezedDesk\Storage;

/**
 * One record. The schema fields live in $data (as stored, not as exported);
 * everything else is a column every record has.
 */
final readonly class Item
{
    public const STANDARD_KEYS = ['id', 'slug', 'variant', 'status', 'title', 'sort', 'createdAt', 'updatedAt', 'publishedAt', 'type', 'revision', 'updatedBy'];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $slug,
        public string $variant,
        public Status $status,
        public string $title,
        public int $sort,
        public array $data,
        public string $createdAt,
        public string $updatedAt,
        public ?string $publishedAt,
        public int $revision = 1,
        public string $updatedBy = '',
        public ?int $seenRevision = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $data = json_decode((string) ($row['data'] ?? '{}'), true);

        return new self(
            id: (int) $row['id'],
            type: (string) $row['type'],
            slug: (string) $row['slug'],
            variant: (string) $row['variant'],
            status: Status::fromInput($row['status'] ?? null),
            title: (string) $row['title'],
            sort: (int) $row['sort'],
            data: is_array($data) ? $data : [],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            publishedAt: isset($row['published_at']) ? (string) $row['published_at'] : null,
            revision: (int) ($row['revision'] ?? 1),
            updatedBy: (string) ($row['updated_by'] ?? ''),
            seenRevision: isset($row['seen_revision']) ? (int) $row['seen_revision'] : null,
        );
    }

    /**
     * A stored value by name or dot path ("address.city"), or one of the
     * standard keys (id, slug, variant, status, title, sort, createdAt,
     * updatedAt, publishedAt, type).
     */
    public function value(string $path): mixed
    {
        return match ($path) {
            'id' => $this->id,
            'type' => $this->type,
            'slug' => $this->slug,
            'variant' => $this->variant,
            'status' => $this->status->value,
            'title' => $this->title,
            'sort' => $this->sort,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'publishedAt' => $this->publishedAt,
            'revision' => $this->revision,
            'updatedBy' => $this->updatedBy,
            default => $this->dataValue($path),
        };
    }

    public function isPublished(): bool
    {
        return $this->status === Status::Published;
    }

    /**
     * Changed by the agent since a person last opened it in the UI: the
     * filter "from the agent, not yet seen by a person" (A3.5).
     */
    public function isUnseenAgentChange(): bool
    {
        return $this->updatedBy === Actor::Agent->value && ($this->seenRevision === null || $this->seenRevision < $this->revision);
    }

    /** @return array{type: string, id: int} */
    public function pair(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }

    /** @return array<string, mixed> The record as a plain array, e.g. for revisions. */
    public function snapshot(): array
    {
        return [
            'slug' => $this->slug,
            'variant' => $this->variant,
            'status' => $this->status->value,
            'title' => $this->title,
            'sort' => $this->sort,
            'data' => $this->data,
        ];
    }

    private function dataValue(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
