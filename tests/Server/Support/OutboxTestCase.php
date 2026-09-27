<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server\Support;

use DeskOutbox\Api;
use DeskOutbox\Request;
use DeskOutbox\Response;
use DeskOutbox\Runner;
use DeskOutbox\Services;
use PHPUnit\Framework\TestCase;

/**
 * A complete outbox in a temporary folder: database, media folder, a clock
 * the test moves, a fake HTTP client and a collecting mailer.
 */
abstract class OutboxTestCase extends TestCase
{
    protected const TOKEN = 'test-token-0123456789';

    protected string $directory;

    protected \DateTimeImmutable $now;

    protected FakeHttpClient $http;

    protected CollectingMailer $mailer;

    /** @var string[] */
    protected array $logged = [];

    /** @var array<string, mixed> */
    protected array $channels = [
        'instagram' => ['adapter' => 'log'],
        'bluesky' => ['adapter' => 'log'],
    ];

    private ?Services $services = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/desk-outbox-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0770, true);
        $this->now = new \DateTimeImmutable('2027-05-06T10:00:00Z');
        $this->http = new FakeHttpClient();
        $this->mailer = new CollectingMailer();
    }

    protected function tearDown(): void
    {
        $this->services = null;
        self::remove($this->directory);
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        return [
            'token' => self::TOKEN,
            'database' => $this->directory . '/var/outbox.sqlite',
            'mediaPath' => $this->directory . '/var/outbox-media',
            'publicBase' => 'https://example.org/api/v1/outbox/media',
            'mail' => ['to' => 'redaktion@example.org', 'from' => 'outbox@example.org'],
            'channels' => $this->channels,
        ];
    }

    protected function services(): Services
    {
        return $this->services ??= new Services(
            $this->config(),
            fn (): \DateTimeImmutable => $this->now,
            $this->http,
            $this->mailer,
            function (string $line): void {
                $this->logged[] = $line;
            },
        );
    }

    /** Start over with a new configuration (same folder, same database). */
    protected function reconfigure(array $channels): void
    {
        $this->channels = $channels;
        $this->services = null;
    }

    protected function runner(): Runner
    {
        return new Runner($this->services());
    }

    protected function advance(string $modify): void
    {
        $this->now = $this->now->modify($modify);
    }

    /** @param array<string, string> $headers */
    protected function request(string $method, string $path, mixed $body = null, array $headers = [], array $query = [], bool $auth = true): Response
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers += ['Content-Type' => 'application/json'];
        }
        if ($auth) {
            $headers += ['Authorization' => 'Bearer ' . self::TOKEN];
        }

        return (new Api($this->services()))->handle(new Request($method, $path, $query, $headers, $body));
    }

    /** A tiny valid JPEG-looking file (the magic bytes are what the server checks). */
    protected static function jpeg(string $seed = 'a'): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat($seed, 64) . "\xFF\xD9";
    }

    /** Upload bytes and return the asset entry for a message. */
    protected function upload(string $bytes, string $mime = 'image/jpeg'): array
    {
        $sha = hash('sha256', $bytes);
        $response = $this->request('PUT', '/assets/' . $sha, $bytes, ['Content-Type' => $mime]);
        self::assertContains($response->status, [200, 201], $response->body);

        return ['sha256' => $sha, 'mime' => $mime, 'role' => 'image', 'size' => strlen($bytes)];
    }

    /** @return array<string, mixed> */
    protected function message(string $key, string $channel, string $at, string $text = 'Hallo Ruhrgebiet', array $assets = []): array
    {
        return [
            'key' => $key,
            'channel' => $channel,
            'at' => $at,
            'payload' => [
                'kind' => $assets === [] ? 'text' : 'image',
                'text' => $text,
                'media' => array_map(static fn (array $a): array => ['sha256' => $a['sha256'], 'mime' => $a['mime'], 'role' => 'image', 'alt' => 'Bild'], $assets),
            ],
            'assets' => $assets,
            'hash' => hash('sha256', $key . $text),
        ];
    }

    protected function state(string $key): ?string
    {
        $row = $this->services()->store->message($key);

        return $row === null ? null : (string) $row['state'];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path . '/' . $name);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
