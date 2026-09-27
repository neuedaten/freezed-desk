<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * Wires the outbox from its configuration: store, mailer, HTTP client,
 * adapter context and registry. Shared by the HTTP module (Api) and the
 * timer (Runner); tests pass a clock, an HTTP fake and a collecting mailer.
 */
final class Services
{
    public const DEFAULTS = [
        'token' => '',
        'database' => '',
        'mediaPath' => '',
        'publicBase' => '',
        'maxBytes' => 200 * 1024 * 1024,
        'retainDays' => 14,
        'mail' => null,
        'log' => null,
        'adapters' => [],
        'channels' => [],
    ];

    /** Seconds a channel's health() answer is reused. */
    public const HEALTH_TTL = 3600;

    /** @var array<string, mixed> */
    public readonly array $config;

    public readonly Store $store;

    public readonly Mailer $mailer;

    public readonly AdapterContext $context;

    public readonly AdapterRegistry $registry;

    /** @var \Closure(): \DateTimeImmutable */
    public readonly \Closure $clock;

    /** @var \Closure(string): void */
    private readonly \Closure $logger;

    /**
     * @param array<string, mixed> $config The 'outbox' key of private/config.php.
     * @param \Closure(): \DateTimeImmutable|null $clock
     * @param \Closure(string): void|null $logger Where log lines go; default error_log().
     */
    public function __construct(
        array $config,
        ?\Closure $clock = null,
        ?HttpClient $http = null,
        ?Mailer $mailer = null,
        ?\Closure $logger = null,
    ) {
        $config += self::DEFAULTS;
        $config['adapterPaths'] ??= [dirname(__DIR__) . '/adapters'];
        foreach (['database', 'mediaPath'] as $required) {
            if ((string) $config[$required] === '') {
                throw new \RuntimeException('Outbox configuration: "' . $required . '" is missing.');
            }
        }
        $this->config = $config;
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->logger = $logger ?? static function (string $line): void {
            error_log('outbox: ' . $line);
        };
        $this->store = new Store((string) $config['database'], $this->clock);
        $this->mailer = $mailer ?? new PhpMailer(is_array($config['mail']) ? ($config['mail']['from'] ?? null) : null);
        $this->context = new AdapterContext(
            http: $http ?? new CurlHttpClient(),
            state: $this->store,
            log: $this->logger,
            clock: $this->clock,
            mailer: $this->mailer,
        );
        $this->registry = new AdapterRegistry($config, $this->context);
    }

    public function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    public function log(string $line): void
    {
        ($this->logger)($line);
    }

    public function mediaPath(): string
    {
        return rtrim((string) $this->config['mediaPath'], '/');
    }

    /** The stored file of an asset, whatever its type; null when not uploaded. */
    public function mediaFile(string $sha256): ?string
    {
        if (!Asset::isValidSha($sha256)) {
            return null;
        }
        foreach (Asset::MIME_EXTENSIONS as $extension) {
            $file = $this->mediaPath() . '/' . $sha256 . '.' . $extension;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** @param Asset[] $assets */
    public function assetUrls(array $assets): AssetUrls
    {
        $map = [];
        foreach ($assets as $asset) {
            $map[$asset->sha256] = $asset;
        }

        return new AssetUrls((string) $this->config['publicBase'], $this->mediaPath(), $map);
    }

    /**
     * Everything that makes a message unsendable: missing files, the
     * adapter's own checks and the channel limits (ChannelRules). Used when
     * Desk queues a message and again right before sending.
     *
     * @return array{missing: string[], problems: array<int, array{rule: string, message: string}>}
     */
    public function check(Message $message): array
    {
        $missing = [];
        $facts = [];
        foreach ($message->assets as $asset) {
            $file = Asset::isValidSha($asset->sha256) ? $this->mediaFile($asset->sha256) : null;
            if ($file === null) {
                $missing[] = $asset->sha256;
                continue;
            }
            $facts[$asset->sha256] = ['mime' => $asset->mime, 'size' => (int) filesize($file)];
        }

        $problems = [];
        foreach ($message->assets as $asset) {
            if (!isset(Asset::MIME_EXTENSIONS[$asset->mime])) {
                $problems[] = ['rule' => 'asset.mime', 'message' => sprintf('Asset %s: type "%s" is not allowed (%s).', substr($asset->sha256, 0, 12), $asset->mime, implode(', ', array_keys(Asset::MIME_EXTENSIONS)))];
            }
        }
        $listed = array_map(static fn (Asset $a): string => $a->sha256, $message->assets);
        $referenced = array_map(static fn (array $m): string => strtolower($m['sha256']), $message->media());
        $thumb = $message->link()['thumb'] ?? null;
        if ($thumb !== null) {
            $referenced[] = strtolower($thumb);
        }
        foreach (array_unique($referenced) as $sha) {
            if (!in_array($sha, $listed, true)) {
                $problems[] = ['rule' => 'asset.listed', 'message' => sprintf('File %s is used in the payload but not listed in "assets".', substr($sha, 0, 12))];
            }
        }

        foreach ($this->registry->forChannel($message->channel)->validate($message) as $problem) {
            $problems[] = ['rule' => 'adapter', 'message' => (string) $problem];
        }
        // Limits by channel name, as Desk checks them; 'rules' => 'instagram' maps a differently named channel.
        $rulesChannel = (string) ($this->config['channels'][$message->channel]['rules'] ?? $message->channel);
        foreach (ChannelRules::check($rulesChannel, $message->payload, $facts) as $problem) {
            $problems[] = $problem;
        }

        return ['missing' => $missing, 'problems' => $problems];
    }

    /**
     * Health of every channel from the adapters' health(). That call may go
     * to the platform, so the answer is cached in the state table for an
     * hour; the runner refreshes it, a request only when it is older.
     *
     * @return array<string, array{adapter: string, ok: bool, message: string, tokenExpiresAt: ?string, checkedAt: string}>
     */
    public function channelHealth(bool $force = false): array
    {
        $now = $this->now();
        $health = [];
        foreach ($this->registry->channels() as $channel) {
            $cached = json_decode((string) $this->store->get('health.' . $channel), true);
            $fresh = is_array($cached) && isset($cached['checkedAt'])
                && ($checked = Time::parse((string) $cached['checkedAt'])) !== null
                && $now->getTimestamp() - $checked->getTimestamp() < self::HEALTH_TTL;
            if (!$force && $fresh) {
                $health[$channel] = $cached;
                continue;
            }
            $entry = ['adapter' => '', 'ok' => false, 'message' => '', 'tokenExpiresAt' => null, 'checkedAt' => Time::format($now)];
            try {
                $entry['adapter'] = $this->registry->adapterName($channel);
                $result = $this->registry->forChannel($channel)->health();
                $entry['ok'] = (bool) ($result['ok'] ?? false);
                $entry['message'] = (string) ($result['message'] ?? '');
                $expires = isset($result['tokenExpiresAt']) && $result['tokenExpiresAt'] !== null ? Time::parse((string) $result['tokenExpiresAt']) : null;
                $entry['tokenExpiresAt'] = $expires !== null ? Time::format($expires) : null;
            } catch (\Throwable $exception) {
                $entry['message'] = 'Health check failed: ' . AdapterException::excerpt($exception->getMessage());
            }
            $this->store->set('health.' . $channel, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $health[$channel] = $entry;
        }

        return $health;
    }

    /** Send a notice to the editorial address (B3.9); false when none is configured. */
    public function notify(string $subject, string $text): bool
    {
        $mail = $this->config['mail'];
        if (!is_array($mail) || (string) ($mail['to'] ?? '') === '') {
            return false;
        }
        try {
            return $this->mailer->send((string) $mail['to'], $subject, $text, [], isset($mail['from']) ? (string) $mail['from'] : null);
        } catch (\Throwable $exception) {
            $this->log('mail failed: ' . $exception->getMessage());

            return false;
        }
    }
}
