<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * What the outbox module answers: a status, headers and either a body or a
 * byte range of a file (media delivery). outbox.php emits it; tests read it.
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly ?string $file = null,
        public readonly int $offset = 0,
        public readonly int $length = 0,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers + [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    public static function error(string $message, int $status, array $extra = [], array $headers = []): self
    {
        return self::json(['error' => $message] + $extra, $status, $headers);
    }

    /** @return array<string, mixed> The decoded JSON body (tests, logging). */
    public function data(): array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
