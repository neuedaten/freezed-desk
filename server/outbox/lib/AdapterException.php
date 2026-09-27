<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * A publication failed.
 *
 * temporary: another attempt may help (network error, HTTP 429, 5xx before
 * the platform took the post). The runner retries at most 3 times within 30
 * minutes, then gives up with "failed".
 *
 * accepted: the platform may have taken the post although the call failed
 * (a timeout after the publish request was sent). The runner never retries
 * such a message; it becomes "unknown" and a person decides.
 */
final class AdapterException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $temporary = false,
        public readonly bool $accepted = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Shorten a platform response for the error text (B3.11, C7.2). */
    public static function excerpt(string $body, int $length = 300): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));

        return strlen($body) > $length ? substr($body, 0, $length) . '…' : $body;
    }
}
