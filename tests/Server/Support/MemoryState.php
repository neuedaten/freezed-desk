<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server\Support;

use DeskOutbox\StateStore;

final class MemoryState implements StateStore
{
    /** @var array<string, string> */
    public array $values = [];

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($this->values[$key]);

            return;
        }
        $this->values[$key] = $value;
    }
}
