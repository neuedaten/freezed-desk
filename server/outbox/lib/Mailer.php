<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * Sends a mail, optionally with attachments (absolute paths). The default
 * implementation uses PHP's mail() (the host relays it); tests collect.
 */
interface Mailer
{
    /** @param array<int, array{path: string, name: string, mime: string}> $attachments */
    public function send(string $to, string $subject, string $text, array $attachments = [], ?string $from = null): bool;
}
