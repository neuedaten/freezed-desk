<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * Key-value state of adapters, e.g. "instagram.accessToken" after a
 * refresh. Implemented by the outbox Store (SQLite) and by an in-memory
 * store in tests.
 */
interface StateStore
{
    public function get(string $key): ?string;

    public function set(string $key, ?string $value): void;
}
