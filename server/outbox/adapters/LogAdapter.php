<?php

declare(strict_types=1);

namespace DeskOutbox\Adapters;

use DeskOutbox\AdapterContext;
use DeskOutbox\AdapterException;
use DeskOutbox\AssetUrls;
use DeskOutbox\ChannelAdapter;
use DeskOutbox\Message;
use DeskOutbox\Result;
use DeskOutbox\Time;

/**
 * Publishes nothing: writes one line per message (B3.7). For tests, for the
 * acceptance run before any account is connected, and for trying a new
 * channel's messages on the live server.
 *
 * Settings:
 *
 *     'file'      => '/var/www/example/var/outbox-log.txt',  // else the runner log
 *     'fail'      => 'temporary' | 'permanent' | 'accepted',  // simulate a failure
 *     'failTimes' => 2,                                      // fail only the first n attempts per message
 */
final class LogAdapter implements ChannelAdapter
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly array $settings,
        private readonly AdapterContext $context,
    ) {
    }

    public function validate(Message $message): array
    {
        $fail = (string) ($this->settings['fail'] ?? '');

        return $fail !== '' && !in_array($fail, ['temporary', 'permanent', 'accepted'], true)
            ? ['Log adapter: "fail" must be temporary, permanent or accepted.']
            : [];
    }

    public function publish(Message $message, AssetUrls $assets): Result
    {
        $this->simulateFailure($message);

        $media = array_map(static fn (array $m): string => substr($m['sha256'], 0, 12), $message->media());
        $line = sprintf(
            '%s %s %s kind=%s chars=%d media=%s',
            Time::format($this->context->now()),
            $message->channel,
            $message->key,
            $message->kind(),
            mb_strlen($message->text()),
            $media === [] ? '-' : implode(',', $media),
        );
        $file = (string) ($this->settings['file'] ?? '');
        if ($file !== '') {
            if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new AdapterException('Log adapter: cannot write ' . basename($file) . '.', temporary: true);
            }
        } else {
            $this->context->log('log adapter: ' . $line);
        }

        return new Result('log-' . substr(hash('sha256', $message->key . "\n" . $message->text()), 0, 12), '');
    }

    public function metrics(string $remoteId): array
    {
        return [];
    }

    public function health(): array
    {
        return ['ok' => true, 'message' => 'Log adapter, publishes nothing.', 'tokenExpiresAt' => null];
    }

    private function simulateFailure(Message $message): void
    {
        $fail = (string) ($this->settings['fail'] ?? '');
        if ($fail === '') {
            return;
        }
        if (isset($this->settings['failTimes'])) {
            $counter = 'log.failures.' . $message->key;
            $failures = (int) ($this->context->state->get($counter) ?? 0);
            if ($failures >= (int) $this->settings['failTimes']) {
                return;
            }
            $this->context->state->set($counter, (string) ($failures + 1));
        }
        throw match ($fail) {
            'temporary' => new AdapterException('Simulated temporary failure.', temporary: true),
            'accepted' => new AdapterException('Simulated failure after the platform took the post.', accepted: true),
            default => new AdapterException('Simulated permanent failure.'),
        };
    }
}
