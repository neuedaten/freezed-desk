<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server\Support;

use DeskOutbox\Mailer;

final class CollectingMailer implements Mailer
{
    /** @var array<int, array{to: string, subject: string, text: string, attachments: array, from: ?string}> */
    public array $mails = [];

    public bool $accept = true;

    public function send(string $to, string $subject, string $text, array $attachments = [], ?string $from = null): bool
    {
        $this->mails[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'attachments' => $attachments, 'from' => $from];

        return $this->accept;
    }

    /** @return string[] */
    public function subjects(): array
    {
        return array_column($this->mails, 'subject');
    }
}
