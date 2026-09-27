<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * What an adapter gets from the runner besides its settings: HTTP, a log
 * line writer, a small key-value store in the outbox database (for a
 * refreshed token, a cached session) and the clock.
 */
final class AdapterContext
{
    /**
     * @param \Closure(string): void $log     One line, no tokens, no full payloads.
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        public readonly HttpClient $http,
        public readonly StateStore $state,
        private readonly \Closure $log,
        private readonly ?\Closure $clock = null,
        public readonly ?Mailer $mailer = null,
    ) {
    }

    public function log(string $line): void
    {
        ($this->log)($line);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
