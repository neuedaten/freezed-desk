<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server\Support;

use DeskOutbox\AdapterContext;
use DeskOutbox\AssetUrls;
use DeskOutbox\ChannelAdapter;
use DeskOutbox\HttpException;
use DeskOutbox\Message;
use DeskOutbox\Result;

/**
 * An adapter whose behaviour the settings script: what publish() throws,
 * what metrics() and health() answer. Counts its calls.
 */
final class ScriptedAdapter implements ChannelAdapter
{
    /** @var array<string, int> */
    public static array $calls = ['publish' => 0, 'metrics' => 0, 'health' => 0];

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings, private readonly AdapterContext $context)
    {
    }

    public static function reset(): void
    {
        self::$calls = ['publish' => 0, 'metrics' => 0, 'health' => 0];
    }

    public function validate(Message $message): array
    {
        return (array) ($this->settings['problems'] ?? []);
    }

    public function publish(Message $message, AssetUrls $assets): Result
    {
        self::$calls['publish']++;
        match ($this->settings['throw'] ?? null) {
            'http-sent' => throw new HttpException('timeout after upload', true),
            'http' => throw new HttpException('could not connect', false),
            'runtime' => throw new \RuntimeException('bug'),
            default => null,
        };

        return new Result('remote-' . self::$calls['publish'], 'https://social.example/p/' . self::$calls['publish']);
    }

    public function metrics(string $remoteId): array
    {
        self::$calls['metrics']++;
        if (($this->settings['metrics'] ?? null) === 'throw') {
            throw new \RuntimeException('metrics down');
        }

        return (array) ($this->settings['metrics'] ?? []);
    }

    public function health(): array
    {
        self::$calls['health']++;

        return ['ok' => (bool) ($this->settings['ok'] ?? true), 'message' => 'scripted', 'tokenExpiresAt' => $this->settings['tokenExpiresAt'] ?? null];
    }
}
