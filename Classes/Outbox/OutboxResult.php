<?php

namespace Neuedaten\FreezedDesk\Outbox;

/**
 * What the server reports about a message (GET …/outbox/results): an event
 * per state change -- sent, failed, unknown, withdrawn, queued again.
 */
final readonly class OutboxResult
{
    public function __construct(
        public int $id,
        public string $key,
        public string $channel,
        public string $state,
        public string $remoteId = '',
        public string $url = '',
        public string $error = '',
        public string $at = '',
        public string $updatedAt = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            key: (string) ($data['key'] ?? ''),
            channel: (string) ($data['channel'] ?? ''),
            state: (string) ($data['state'] ?? ''),
            remoteId: (string) ($data['remoteId'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            error: (string) ($data['error'] ?? ''),
            at: (string) ($data['at'] ?? ''),
            updatedAt: (string) ($data['updatedAt'] ?? ''),
        );
    }
}
