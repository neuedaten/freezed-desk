<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server;

use Neuedaten\FreezedDesk\Tests\Server\Support\OutboxTestCase;
use Neuedaten\FreezedDesk\Tests\Server\Support\ScriptedAdapter;

final class RunnerTest extends OutboxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScriptedAdapter::reset();
    }

    protected function config(): array
    {
        return parent::config() + ['adapters' => ['scripted' => ['class' => ScriptedAdapter::class]]];
    }

    private function queue(string $key, string $channel, string $at = '2027-05-06T10:00:00Z'): void
    {
        $response = $this->request('POST', '/messages', $this->message($key, $channel, $at));
        self::assertSame(200, $response->status, $response->body);
    }

    private function sentLog(): array
    {
        $file = $this->directory . '/sent.log';

        return is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    }

    public function testSendsDueMessagesAndRecordsResults(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log', 'file' => $this->directory . '/sent.log'], 'mastodon' => ['adapter' => 'log', 'file' => $this->directory . '/sent.log']]);
        $this->queue('posts/a#bluesky', 'bluesky');
        $this->queue('posts/a#mastodon', 'mastodon');
        $this->queue('posts/later#bluesky', 'bluesky', '2027-05-06T12:00:00Z');

        $lines = $this->runner()->run();

        self::assertSame('sent', $this->state('posts/a#bluesky'));
        self::assertSame('sent', $this->state('posts/a#mastodon'));
        self::assertSame('queued', $this->state('posts/later#bluesky'));
        self::assertCount(2, $this->sentLog());
        self::assertCount(2, $lines);
        self::assertMatchesRegularExpression('/^2027-05-06T10:00:00Z posts\/a#bluesky bluesky sent \d+\.\d{2}s log-[a-f0-9]{12}$/', $lines[0]);
        self::assertStringNotContainsString('Hallo', implode("\n", $lines), 'no payload text in the runner output');

        $results = $this->services()->store->results(0);
        self::assertSame(['sent', 'sent'], array_column($results, 'state'));
        self::assertStringStartsWith('log-', (string) $results[0]['remoteId']);
        self::assertSame('2027-05-06T10:00:00Z', $this->services()->store->get('runner.lastRun'));

        // A second pass sends nothing again.
        $this->runner()->run();
        self::assertCount(2, $this->sentLog());
    }

    public function testTemporaryErrorsAreRetriedWithBackoffThenFail(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log', 'fail' => 'temporary']]);
        $this->queue('k', 'bluesky');

        $this->runner()->run();
        $row = $this->services()->store->message('k');
        self::assertSame(['queued', 1, '2027-05-06T10:05:00Z'], [$row['state'], (int) $row['attempts'], $row['next_attempt_at']]);
        self::assertSame([], $this->mailer->mails);

        $this->advance('+4 minutes');
        $this->runner()->run();
        self::assertSame(1, (int) $this->services()->store->message('k')['attempts'], 'not before the backoff');

        $this->advance('+1 minute'); // 10:05
        $this->runner()->run();
        $row = $this->services()->store->message('k');
        self::assertSame(['queued', 2, '2027-05-06T10:15:00Z'], [$row['state'], (int) $row['attempts'], $row['next_attempt_at']]);

        $this->advance('+10 minutes'); // 10:15, third attempt
        $this->runner()->run();
        $row = $this->services()->store->message('k');
        self::assertSame(['failed', 3], [$row['state'], (int) $row['attempts']]);
        self::assertStringContainsString('Simulated temporary failure', (string) $row['error']);
        self::assertSame(['Outbox: k failed (bluesky)'], $this->mailer->subjects());
        self::assertSame(['failed'], array_column($this->services()->store->results(0), 'state'), 'retries create no events');

        $this->advance('+1 hour');
        $this->runner()->run();
        self::assertSame(3, (int) $this->services()->store->message('k')['attempts']);
    }

    public function testTemporaryErrorThenSuccess(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log', 'fail' => 'temporary', 'failTimes' => 1]]);
        $this->queue('k', 'bluesky');
        $this->runner()->run();
        $this->advance('+5 minutes');
        $this->runner()->run();
        $row = $this->services()->store->message('k');
        self::assertSame(['sent', 2, null], [$row['state'], (int) $row['attempts'], $row['error']]);
    }

    public function testNoRetryAfterThirtyMinutes(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log', 'fail' => 'temporary']]);
        $this->queue('k', 'bluesky');
        $this->runner()->run();
        $this->advance('+40 minutes'); // the timer was down in between
        $this->runner()->run();
        $row = $this->services()->store->message('k');
        self::assertSame(['failed', 2], [$row['state'], (int) $row['attempts']]);
    }

    public function testPermanentErrorFailsAtOnce(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log', 'fail' => 'permanent']]);
        $this->queue('k', 'bluesky');
        $this->runner()->run();
        self::assertSame('failed', $this->state('k'));
        self::assertCount(1, $this->mailer->mails);
        self::assertSame('redaktion@example.org', $this->mailer->mails[0]['to']);
    }

    public function testAcceptedErrorBecomesUnknownAndIsNeverRetried(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log', 'fail' => 'accepted']]);
        $this->queue('k', 'bluesky');
        $this->runner()->run();
        self::assertSame('unknown', $this->state('k'));
        self::assertSame(['Outbox: k unknown (bluesky)'], $this->mailer->subjects());

        $this->advance('+1 day');
        $this->runner()->run();
        self::assertSame(1, (int) $this->services()->store->message('k')['attempts']);
        self::assertSame(['unknown'], array_column($this->services()->store->results(0), 'state'));
    }

    public function testHttpExceptionAfterSendingIsUnknownOtherThrowablesFail(): void
    {
        $this->reconfigure([
            'a' => ['adapter' => 'scripted', 'throw' => 'http-sent'],
            'b' => ['adapter' => 'scripted', 'throw' => 'http'],
            'c' => ['adapter' => 'scripted', 'throw' => 'runtime'],
        ]);
        $this->queue('ka', 'a');
        $this->queue('kb', 'b');
        $this->queue('kc', 'c');
        $this->runner()->run();
        self::assertSame(['unknown', 'failed', 'failed'], [$this->state('ka'), $this->state('kb'), $this->state('kc')]);
    }

    public function testExpiredLeaseBecomesUnknownAndIsNotSentAgain(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log', 'file' => $this->directory . '/sent.log']]);
        $this->queue('k', 'bluesky');
        // A runner claimed it and died before publishing.
        self::assertTrue($this->services()->store->claim('k', '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z'));

        $this->advance('+5 minutes');
        $this->runner()->run();
        self::assertSame('sending', $this->state('k'), 'the lease still runs');

        $this->advance('+6 minutes');
        $lines = $this->runner()->run();
        self::assertSame('unknown', $this->state('k'));
        self::assertStringContainsString('k bluesky unknown', $lines[0]);
        self::assertSame(['Outbox: k unknown (bluesky)'], $this->mailer->subjects());
        self::assertSame([], $this->sentLog());

        $this->advance('+1 hour');
        $this->runner()->run();
        self::assertSame([], $this->sentLog());
        self::assertCount(1, $this->mailer->mails);
    }

    public function testMessageThatNoLongerFitsFails(): void
    {
        $this->reconfigure(['instagram' => ['adapter' => 'log']]);
        $asset = $this->upload(self::jpeg());
        $this->request('POST', '/messages', $this->message('k', 'instagram', '2027-05-06T10:00:00Z', 'Bild', [$asset]));
        unlink($this->directory . '/var/outbox-media/' . $asset['sha256'] . '.jpg');
        $this->runner()->run();
        $row = $this->services()->store->message('k');
        self::assertSame('failed', $row['state']);
        self::assertStringContainsString('Files missing', (string) $row['error']);
    }

    public function testPrunesMediaAndAcknowledgedResults(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log']]);
        $media = $this->directory . '/var/outbox-media';
        $sent = $this->upload(self::jpeg('s'));
        $queued = $this->upload(self::jpeg('q'));
        $orphanOld = $this->upload(self::jpeg('o'));
        $orphanNew = $this->upload(self::jpeg('n'));
        // File times follow the test clock, not the real one.
        touch($media . '/' . $orphanNew['sha256'] . '.jpg', $this->now->modify('+14 days')->getTimestamp());
        touch($media . '/' . $orphanOld['sha256'] . '.jpg', $this->now->modify('-15 days')->getTimestamp());
        touch($media . '/.' . $orphanOld['sha256'] . '.abc123.part', $this->now->modify('-2 days')->getTimestamp());

        $this->request('POST', '/messages', $this->message('sent', 'bluesky', '2027-05-06T10:00:00Z', 'x', [$sent]));
        $this->request('POST', '/messages', $this->message('later', 'bluesky', '2027-07-01T10:00:00Z', 'x', [$queued]));
        $this->runner()->run();
        self::assertSame('sent', $this->state('sent'));
        $this->services()->store->ack([1]);

        $this->advance('+13 days');
        $this->runner()->run();
        self::assertFileExists($media . '/' . $sent['sha256'] . '.jpg', 'kept within retainDays');
        self::assertFileDoesNotExist($media . '/' . $orphanOld['sha256'] . '.jpg');
        self::assertFileDoesNotExist($media . '/.' . $orphanOld['sha256'] . '.abc123.part');

        $this->advance('+2 days');
        $this->runner()->run();
        self::assertFileDoesNotExist($media . '/' . $sent['sha256'] . '.jpg');
        self::assertFileExists($media . '/' . $queued['sha256'] . '.jpg', 'still needed');
        self::assertFileExists($media . '/' . $orphanNew['sha256'] . '.jpg', 'fresh upload not used yet');

        self::assertSame(1, (int) $this->services()->store->pdo()->query('SELECT COUNT(*) FROM results WHERE acked_at IS NOT NULL')->fetchColumn());
        $this->advance('+80 days');
        $this->runner()->run();
        self::assertSame(0, (int) $this->services()->store->pdo()->query('SELECT COUNT(*) FROM results WHERE acked_at IS NOT NULL')->fetchColumn());
    }

    public function testCollectsMetricsAtMostEveryTwentyHours(): void
    {
        $this->reconfigure(['stats' => ['adapter' => 'scripted', 'metrics' => ['reach' => 120, 'likes' => '7', 'bogus' => 1]]]);
        $this->queue('k', 'stats');
        $this->runner()->run();
        self::assertSame(1, ScriptedAdapter::$calls['metrics']);
        $metrics = $this->services()->store->metrics();
        self::assertSame(['reach' => 120, 'likes' => 7], (array) $metrics[0]['values']);
        self::assertSame('remote-1', $metrics[0]['remoteId']);

        $this->advance('+19 hours');
        $this->runner()->run();
        self::assertSame(1, ScriptedAdapter::$calls['metrics']);
        $this->advance('+2 hours');
        $this->runner()->run();
        self::assertSame(2, ScriptedAdapter::$calls['metrics']);

        $this->advance('+61 days');
        $this->runner()->run();
        self::assertSame(2, ScriptedAdapter::$calls['metrics'], 'only posts of the last 60 days');
    }

    public function testBrokenMetricsDoNotStopSending(): void
    {
        $this->reconfigure(['stats' => ['adapter' => 'scripted', 'metrics' => 'throw']]);
        $this->queue('k', 'stats');
        $lines = $this->runner()->run();
        self::assertSame('sent', $this->state('k'));
        self::assertStringContainsString('metrics k stats failed', implode("\n", $lines));
        $this->advance('+10 minutes');
        $this->runner()->run();
        self::assertSame(1, ScriptedAdapter::$calls['metrics'], 'a failed call waits for the interval too');
    }

    public function testHealthIsCachedAndTokenExpiryMailedOncePerDay(): void
    {
        $this->reconfigure(['stats' => ['adapter' => 'scripted', 'tokenExpiresAt' => '2027-05-16T00:00:00+00:00']]);
        $this->runner()->run();
        self::assertSame(1, ScriptedAdapter::$calls['health']);
        self::assertSame(['Outbox: token of stats expires in 9 days'], $this->mailer->subjects());

        $this->advance('+30 minutes');
        $this->runner()->run();
        self::assertSame(1, ScriptedAdapter::$calls['health'], 'cached for an hour');

        $this->advance('+1 hour');
        $this->runner()->run();
        self::assertSame(2, ScriptedAdapter::$calls['health']);
        self::assertCount(1, $this->mailer->mails, 'one mail per day');

        $this->advance('+1 day');
        $this->runner()->run();
        self::assertCount(2, $this->mailer->mails);
    }
}
