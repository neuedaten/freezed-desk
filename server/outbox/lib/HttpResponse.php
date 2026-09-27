<?php

declare(strict_types=1);

namespace DeskOutbox;

final class HttpResponse
{
    /** @param array<string, string> $headers Lower-case names. */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** 429 and 5xx: worth another try. */
    public function isTemporaryError(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }
}
