<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * One pass of the timer (outbox-run.php, every 5 minutes; B3.3 to B3.5, B3.9):
 *
 *   1. messages stuck in sending past their lease → unknown, mail (never re-sent)
 *   2. due queued messages: claim, check again, publish, record the outcome
 *   3. channel health (cached 1 h), mail when a token expires within 14 days
 *   4. metrics of sent posts, pruning of old media files and result events
 *   5. remember the run for GET …/health
 *
 * Each step catches its own errors, so a broken metrics call never stops a
 * post from going out. One log line per message: key, channel, state,
 * duration and the error text, never tokens or payloads (B3.11).
 */
final class Runner
{
    public const LEASE_SECONDS = 600;

    public const MAX_ATTEMPTS = 3;

    public const RETRY_WINDOW_SECONDS = 1800;

    /** Minutes to wait before attempt n+1, by the attempt number n that failed. */
    public const BACKOFF_MINUTES = [1 => 5, 2 => 10, 3 => 15];

    public const TOKEN_WARN_DAYS = 14;

    public const METRICS_DAYS = 60;

    public const METRICS_INTERVAL_SECONDS = 20 * 3600;

    public const RESULTS_RETAIN_DAYS = 90;

    /** @var \Closure(string): void */
    private readonly \Closure $output;

    /** @var string[] */
    private array $lines = [];

    /** @param \Closure(string): void|null $output Receives each summary line (stdout in outbox-run.php). */
    public function __construct(private readonly Services $services, ?\Closure $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    /** @return string[] The summary lines of this pass. */
    public function run(): array
    {
        $this->lines = [];
        $this->step('leases', fn () => $this->expireLeases());
        $this->step('send', fn () => $this->sendDue());
        $this->step('health', fn () => $this->checkHealth());
        $this->step('metrics', fn () => $this->collectMetrics());
        $this->step('prune', fn () => $this->prune());
        $this->services->store->set('runner.lastRun', Time::format($this->services->now()));

        return $this->lines;
    }

    private function step(string $name, \Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $exception) {
            $this->line(sprintf('step %s failed: %s', $name, AdapterException::excerpt($exception->getMessage())));
        }
    }

    // ------------------------------------------------------------- sending ---

    private function expireLeases(): void
    {
        $now = Time::format($this->services->now());
        foreach ($this->services->store->expiredLeases($now) as $row) {
            $error = 'The runner stopped while sending (lease expired). Check the channel and resolve the message.';
            if ($this->services->store->markUnknown((string) $row['key'], $error)) {
                $this->report((string) $row['key'], (string) $row['channel'], 'unknown', 0.0, $error);
                $this->mailProblem((string) $row['key'], (string) $row['channel'], 'unknown', $error);
            }
        }
    }

    private function sendDue(): void
    {
        $store = $this->services->store;
        foreach ($store->dueKeys(Time::format($this->services->now())) as $key) {
            $now = $this->services->now();
            $lease = Time::format($now->modify('+' . self::LEASE_SECONDS . ' seconds'));
            if (!$store->claim($key, Time::format($now), $lease)) {
                continue; // Taken by another runner, withdrawn or replaced meanwhile.
            }
            $row = $store->message($key);
            if ($row === null) {
                continue;
            }
            $this->publish($row);
        }
    }

    /** @param array<string, mixed> $row A claimed message (state sending). */
    private function publish(array $row): void
    {
        $store = $this->services->store;
        $key = (string) $row['key'];
        $channel = (string) $row['channel'];
        $started = microtime(true);
        $duration = static fn (): float => microtime(true) - $started;

        try {
            $message = Store::toMessage($row);
            if (!$this->services->registry->has($channel)) {
                throw new AdapterException('Channel "' . $channel . '" is no longer configured on the server.');
            }
            $check = $this->services->check($message);
            if ($check['missing'] !== []) {
                throw new AdapterException('Files missing on the server: ' . implode(', ', array_map(static fn (string $s): string => substr($s, 0, 12), $check['missing'])) . '.');
            }
            if ($check['problems'] !== []) {
                throw new AdapterException('Does not fit the channel: ' . implode(' ', array_column($check['problems'], 'message')));
            }
            $result = $this->services->registry->forChannel($channel)->publish($message, $this->services->assetUrls($message->assets));
        } catch (AdapterException $exception) {
            $this->failed($row, $exception->getMessage(), $exception->temporary, $exception->accepted, $duration());

            return;
        } catch (HttpException $exception) {
            $this->failed($row, $exception->getMessage(), false, $exception->sent, $duration());

            return;
        } catch (\Throwable $exception) {
            $this->failed($row, get_class($exception) . ': ' . $exception->getMessage(), false, false, $duration());

            return;
        }

        $store->markSent($key, $result->remoteId, $result->url);
        $this->report($key, $channel, 'sent', $duration(), $result->url !== '' ? $result->url : $result->remoteId);
    }

    /** @param array<string, mixed> $row */
    private function failed(array $row, string $error, bool $temporary, bool $accepted, float $duration): void
    {
        $store = $this->services->store;
        $key = (string) $row['key'];
        $channel = (string) $row['channel'];
        $error = AdapterException::excerpt($error, 1000);

        if ($accepted) {
            // The platform may have the post: never again automatically (B3.4).
            $store->markUnknown($key, $error);
            $this->report($key, $channel, 'unknown', $duration, $error);
            $this->mailProblem($key, $channel, 'unknown', $error);

            return;
        }

        $attempts = (int) $row['attempts'];
        $now = $this->services->now();
        $first = Time::parse((string) ($row['first_attempt_at'] ?? '')) ?? $now;
        $withinWindow = $now->getTimestamp() - $first->getTimestamp() < self::RETRY_WINDOW_SECONDS;
        if ($temporary && $attempts < self::MAX_ATTEMPTS && $withinWindow) {
            $wait = self::BACKOFF_MINUTES[$attempts] ?? 15;
            $next = Time::format($now->modify('+' . $wait . ' minutes'));
            $store->requeue($key, $next, $error);
            $this->report($key, $channel, 'queued', $duration, sprintf('attempt %d failed, next at %s: %s', $attempts, $next, $error));

            return;
        }

        $store->markFailed($key, $error);
        $this->report($key, $channel, 'failed', $duration, $error);
        $this->mailProblem($key, $channel, 'failed', $error);
    }

    // -------------------------------------------------------------- health ---

    private function checkHealth(): void
    {
        $now = $this->services->now();
        $today = $now->format('Y-m-d');
        foreach ($this->services->channelHealth() as $channel => $health) {
            $expires = $health['tokenExpiresAt'] !== null ? Time::parse((string) $health['tokenExpiresAt']) : null;
            if ($expires === null || $expires->getTimestamp() - $now->getTimestamp() > self::TOKEN_WARN_DAYS * 86400) {
                continue;
            }
            $stateKey = 'mail.tokenExpiry.' . $channel;
            if ($this->services->store->get($stateKey) === $today) {
                continue; // At most one mail per channel and day.
            }
            $days = max(0, intdiv($expires->getTimestamp() - $now->getTimestamp(), 86400));
            $sent = $this->services->notify(
                sprintf('Outbox: token of %s expires in %d days', $channel, $days),
                sprintf("The access token of channel \"%s\" (adapter %s) expires on %s.\nRenew it in the server configuration, or posts on this channel will fail.\n\n%s", $channel, $health['adapter'], $health['tokenExpiresAt'], $health['message']),
            );
            if ($sent) {
                $this->services->store->set($stateKey, $today);
            }
            $this->line(sprintf('channel %s token expires %s', $channel, $health['tokenExpiresAt']));
        }
    }

    // ------------------------------------------------------------- metrics ---

    private function collectMetrics(): void
    {
        $store = $this->services->store;
        $now = $this->services->now();
        $sentAfter = Time::format($now->modify('-' . self::METRICS_DAYS . ' days'));
        $checkedBefore = Time::format($now->modify('-' . self::METRICS_INTERVAL_SECONDS . ' seconds'));
        foreach ($store->metricsDue($sentAfter, $checkedBefore) as $row) {
            $channel = (string) $row['channel'];
            try {
                if (!$this->services->registry->has($channel)) {
                    continue;
                }
                $values = Metrics::clean($this->services->registry->forChannel($channel)->metrics((string) $row['remote_id']));
                $store->saveMetrics((string) $row['key'], $channel, (string) $row['remote_id'], $values);
            } catch (\Throwable $exception) {
                $store->saveMetrics((string) $row['key'], $channel, (string) $row['remote_id'], null);
                $this->line(sprintf('metrics %s %s failed: %s', $row['key'], $channel, AdapterException::excerpt($exception->getMessage())));
            }
        }
    }

    // ------------------------------------------------------------- pruning ---

    private function prune(): void
    {
        $now = $this->services->now();
        $this->services->store->pruneResults(Time::format($now->modify('-' . self::RESULTS_RETAIN_DAYS . ' days')));

        $directory = $this->services->mediaPath();
        if (!is_dir($directory)) {
            return;
        }
        $cutoff = $now->getTimestamp() - max(0, (int) $this->services->config['retainDays']) * 86400;
        $usage = $this->services->store->mediaUsage();
        $deleted = 0;
        foreach (scandir($directory) ?: [] as $name) {
            $file = $directory . '/' . $name;
            if (preg_match('/^\.[a-f0-9]{64}\.[a-f0-9]+\.part$/', $name)) {
                // An upload that broke off; a day is plenty for one to finish.
                if ((int) filemtime($file) < $now->getTimestamp() - 86400) {
                    @unlink($file);
                }
                continue;
            }
            if (!preg_match('/^([a-f0-9]{64})\.(jpg|png|mp4)$/', $name, $m) || isset($usage['active'][$m[1]])) {
                continue;
            }
            $lastUse = isset($usage['lastUse'][$m[1]]) ? Time::parse($usage['lastUse'][$m[1]])?->getTimestamp() : null;
            // Never used by any message: count from the upload.
            $reference = $lastUse ?? (int) filemtime($file);
            if ($reference < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }
        if ($deleted > 0) {
            $this->line(sprintf('pruned %d media file(s)', $deleted));
        }
    }

    // ------------------------------------------------------------- output ---

    private function report(string $key, string $channel, string $state, float $seconds, string $detail = ''): void
    {
        $this->line(sprintf('%s %s %s %.2fs%s', $key, $channel, $state, $seconds, $detail !== '' ? ' ' . str_replace(["\r", "\n"], ' ', $detail) : ''));
    }

    private function line(string $text): void
    {
        $line = Time::format($this->services->now()) . ' ' . $text;
        $this->lines[] = $line;
        ($this->output)($line);
        $log = (string) ($this->services->config['log'] ?? '');
        if ($log !== '') {
            @file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function mailProblem(string $key, string $channel, string $state, string $error): void
    {
        $what = $state === 'unknown'
            ? "It is not known whether the post went out. Check the channel, then resolve the message in Desk\n(POST …/outbox/messages/<key>/resolve with state sent, failed or queued). It is never sent again on its own."
            : 'The post was not published. Fix the cause, then push it again from Desk or resolve it to queued.';
        $this->services->notify(
            sprintf('Outbox: %s %s (%s)', $key, $state, $channel),
            sprintf("Message: %s\nChannel: %s\nState: %s\nError: %s\n\n%s", $key, $channel, $state, $error, $what),
        );
    }
}
