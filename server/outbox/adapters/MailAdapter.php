<?php

declare(strict_types=1);

namespace DeskOutbox\Adapters;

use DeskOutbox\AdapterContext;
use DeskOutbox\AdapterException;
use DeskOutbox\AssetUrls;
use DeskOutbox\ChannelAdapter;
use DeskOutbox\Message;
use DeskOutbox\Result;

/**
 * Sends the text and the files to an address (B3.7), for channels without
 * an API such as WhatsApp channels: a person forwards it from the phone.
 *
 * Settings:
 *
 *     'to'   => 'redaktion@example.org',
 *     'from' => 'outbox@example.org',   // optional, else mail.from of the outbox
 *
 * The subject is payload options.subject, else "<channel>: <first line>".
 */
final class MailAdapter implements ChannelAdapter
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly array $settings,
        private readonly AdapterContext $context,
    ) {
    }

    public function validate(Message $message): array
    {
        $problems = [];
        if (!filter_var((string) ($this->settings['to'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'Mail adapter: the channel has no valid "to" address.';
        }
        if (trim($message->text()) === '' && $message->media() === []) {
            $problems[] = 'Mail adapter: the message has neither text nor files.';
        }

        return $problems;
    }

    public function publish(Message $message, AssetUrls $assets): Result
    {
        $mailer = $this->context->mailer ?? throw new AdapterException('Mail adapter: no mailer configured.');

        $text = $message->text();
        $link = $message->link();
        if ($link !== null && !str_contains($text, $link['url'])) {
            $text = rtrim($text) . "\n\n" . $link['url'];
        }

        $attachments = [];
        foreach ($message->media() as $position => $media) {
            $path = $assets->path($media['sha256']);
            if (!is_file($path)) {
                throw new AdapterException('Mail adapter: file ' . substr($media['sha256'], 0, 12) . ' is missing on the server.');
            }
            $attachments[] = [
                'path' => $path,
                'name' => sprintf('%02d-%s.%s', $position + 1, substr($media['sha256'], 0, 12), $assets->asset($media['sha256'])->extension()),
                'mime' => $assets->mime($media['sha256']),
            ];
        }

        $from = isset($this->settings['from']) ? (string) $this->settings['from'] : null;
        if (!$mailer->send((string) $this->settings['to'], $this->subject($message), $text, $attachments, $from)) {
            // mail() returns false when the local MTA refused it: nothing left the server.
            throw new AdapterException('Mail adapter: the mail could not be handed to the mail system.', temporary: true);
        }

        return new Result('mail-' . substr(hash('sha256', $message->key . "\n" . $text), 0, 12), '');
    }

    public function metrics(string $remoteId): array
    {
        return [];
    }

    public function health(): array
    {
        return ['ok' => true, 'message' => 'Mail to ' . (string) ($this->settings['to'] ?? '?') . '.', 'tokenExpiresAt' => null];
    }

    private function subject(Message $message): string
    {
        $subject = trim((string) $message->option('subject', ''));
        if ($subject !== '') {
            return $subject;
        }
        $first = '';
        foreach (preg_split('/\R/u', $message->text()) ?: [] as $line) {
            if (trim($line) !== '') {
                $first = trim($line);
                break;
            }
        }
        if (mb_strlen($first) > 80) {
            $first = rtrim(mb_substr($first, 0, 79)) . '…';
        }

        return $message->channel . ': ' . ($first !== '' ? $first : $message->key);
    }
}
