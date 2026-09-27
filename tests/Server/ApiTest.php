<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server;

use Neuedaten\FreezedDesk\Tests\Server\Support\OutboxTestCase;
use Neuedaten\FreezedDesk\Tests\Server\Support\ScriptedAdapter;

final class ApiTest extends OutboxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScriptedAdapter::reset();
    }

    protected function config(): array
    {
        return parent::config() + ['maxBytes' => 1000, 'adapters' => ['scripted' => ['class' => ScriptedAdapter::class]]];
    }

    // ---------------------------------------------------------- routing ---

    public function testAuthRoutingAndMethods(): void
    {
        self::assertSame(401, $this->request('GET', '/results', auth: false)->status);
        self::assertSame(401, $this->request('GET', '/results', headers: ['Authorization' => 'Bearer wrong'])->status);
        self::assertSame('Bearer', $this->request('GET', '/health', auth: false)->header('WWW-Authenticate'));
        self::assertSame(200, $this->request('GET', '/results')->status);

        $notFound = $this->request('GET', '/nothing');
        self::assertSame([404, ['error' => 'Not found']], [$notFound->status, $notFound->data()]);
        $wrong = $this->request('PATCH', '/messages');
        self::assertSame(405, $wrong->status);
        self::assertSame('POST, GET, DELETE, HEAD', $wrong->header('Allow'));
        self::assertSame('application/json; charset=UTF-8', $wrong->header('Content-Type'));
    }

    public function testEmptyConfiguredTokenLocksEverything(): void
    {
        $api = new \DeskOutbox\Api(new \DeskOutbox\Services(['token' => ''] + $this->config()));
        self::assertSame(401, $api->handle(new \DeskOutbox\Request('GET', '/results', [], ['Authorization' => 'Bearer '], null))->status);
    }

    // ----------------------------------------------------------- assets ---

    public function testAssetUploadIsVerifiedAndIdempotent(): void
    {
        $bytes = self::jpeg();
        $sha = hash('sha256', $bytes);

        self::assertSame(404, $this->request('GET', '/assets/' . $sha)->status);

        $created = $this->request('PUT', '/assets/' . $sha, $bytes, ['Content-Type' => 'image/jpeg']);
        self::assertSame(201, $created->status);
        self::assertSame(['ok' => true, 'existed' => false, 'size' => strlen($bytes)], $created->data());
        self::assertSame($bytes, file_get_contents($this->directory . '/var/outbox-media/' . $sha . '.jpg'));

        $again = $this->request('PUT', '/assets/' . $sha, $bytes, ['Content-Type' => 'image/jpeg']);
        self::assertSame([200, true], [$again->status, $again->data()['existed']]);

        self::assertSame(['exists' => true, 'size' => strlen($bytes)], $this->request('GET', '/assets/' . strtoupper($sha))->data());
        self::assertSame([], glob($this->directory . '/var/outbox-media/.*.part'));
    }

    public function testAssetUploadRefusals(): void
    {
        $bytes = self::jpeg();
        $sha = hash('sha256', $bytes);
        self::assertSame(400, $this->request('PUT', '/assets/xyz', $bytes, ['Content-Type' => 'image/jpeg'])->status);
        self::assertSame(415, $this->request('PUT', '/assets/' . $sha, $bytes, ['Content-Type' => 'image/gif'])->status);

        $mismatch = $this->request('PUT', '/assets/' . str_repeat('0', 64), $bytes, ['Content-Type' => 'image/jpeg']);
        self::assertSame(422, $mismatch->status);
        self::assertSame($sha, $mismatch->data()['actual']);

        self::assertSame(422, $this->request('PUT', '/assets/' . $sha, $bytes, ['Content-Type' => 'video/mp4'])->status, 'magic bytes');

        $big = "\xFF\xD8\xFF" . str_repeat('x', 1000);
        self::assertSame(413, $this->request('PUT', '/assets/' . hash('sha256', $big), $big, ['Content-Type' => 'image/jpeg', 'Content-Length' => '1003'])->status);
        self::assertSame(413, $this->request('PUT', '/assets/' . hash('sha256', $big), $big, ['Content-Type' => 'image/jpeg'])->status, 'counted while streaming');

        self::assertSame([], array_values(array_diff(scandir($this->directory . '/var/outbox-media') ?: [], ['.', '..'])), 'nothing left behind');
    }

    public function testUploadStreamsFromAResource(): void
    {
        $bytes = "\x89PNG\r\n\x1A\n" . random_bytes(500);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $request = new \DeskOutbox\Request('PUT', '/assets/' . hash('sha256', $bytes), [], ['Authorization' => 'Bearer ' . self::TOKEN, 'Content-Type' => 'image/png; charset=binary'], $stream);
        self::assertSame(201, (new \DeskOutbox\Api($this->services()))->handle($request)->status);
        self::assertFileExists($this->directory . '/var/outbox-media/' . hash('sha256', $bytes) . '.png');
    }

    // ------------------------------------------------------------ media ---

    public function testMediaIsPublicWithRanges(): void
    {
        $bytes = self::jpeg();
        $sha = $this->upload($bytes)['sha256'];
        $size = strlen($bytes);

        $full = $this->request('GET', '/media/' . $sha . '.jpg', auth: false);
        self::assertSame(200, $full->status);
        self::assertSame($this->directory . '/var/outbox-media/' . $sha . '.jpg', $full->file);
        self::assertSame([0, $size], [$full->offset, $full->length]);
        self::assertSame('image/jpeg', $full->header('Content-Type'));
        self::assertSame((string) $size, $full->header('Content-Length'));
        self::assertSame('bytes', $full->header('Accept-Ranges'));
        self::assertSame('noindex', $full->header('X-Robots-Tag'));
        self::assertSame('public, max-age=86400', $full->header('Cache-Control'));

        $part = $this->request('GET', '/media/' . $sha . '.jpg', headers: ['Range' => 'bytes=10-19'], auth: false);
        self::assertSame([206, 10, 10], [$part->status, $part->offset, $part->length]);
        self::assertSame('bytes 10-19/' . $size, $part->header('Content-Range'));
        self::assertSame('10', $part->header('Content-Length'));

        $open = $this->request('GET', '/media/' . $sha . '.jpg', headers: ['Range' => 'bytes=60-'], auth: false);
        self::assertSame('bytes 60-' . ($size - 1) . '/' . $size, $open->header('Content-Range'));
        $suffix = $this->request('GET', '/media/' . $sha . '.jpg', headers: ['Range' => 'bytes=-5'], auth: false);
        self::assertSame([206, $size - 5, 5], [$suffix->status, $suffix->offset, $suffix->length]);
        $clamped = $this->request('GET', '/media/' . $sha . '.jpg', headers: ['Range' => 'bytes=0-99999'], auth: false);
        self::assertSame([206, $size], [$clamped->status, $clamped->length]);

        foreach (['bytes=' . $size . '-', 'bytes=5-2', 'items=0-1', 'bytes=-0'] as $bad) {
            $refused = $this->request('GET', '/media/' . $sha . '.jpg', headers: ['Range' => $bad], auth: false);
            self::assertSame(416, $refused->status, $bad);
            self::assertSame('bytes */' . $size, $refused->header('Content-Range'));
        }
        self::assertSame(200, $this->request('GET', '/media/' . $sha . '.jpg', headers: ['Range' => 'bytes=0-1,4-5'], auth: false)->status, 'several ranges: whole file');

        self::assertSame(200, $this->request('HEAD', '/media/' . $sha . '.jpg', auth: false)->status);
        self::assertSame(404, $this->request('GET', '/media/' . $sha . '.png', auth: false)->status);
        self::assertSame(404, $this->request('GET', '/media/' . str_repeat('a', 64) . '.jpg', auth: false)->status);
        self::assertSame(404, $this->request('GET', '/media/..%2F..%2Foutbox.sqlite', auth: false)->status);
        self::assertSame(404, $this->request('GET', '/media/../outbox.sqlite', auth: false)->status);
        self::assertSame(405, $this->request('PUT', '/media/' . $sha . '.jpg', auth: false)->status);
    }

    // --------------------------------------------------------- messages ---

    public function testMessageValidation(): void
    {
        $at = '2027-05-06T18:30:00+02:00';
        self::assertSame(400, $this->request('POST', '/messages', 'not json', ['Content-Type' => 'application/json'])->status);

        $noKey = $this->request('POST', '/messages', ['key' => '', 'channel' => 'bluesky', 'at' => $at, 'payload' => []]);
        self::assertSame(422, $noKey->status);
        self::assertSame('message.key', $noKey->data()['problems'][0]['rule']);
        self::assertSame(422, $this->request('POST', '/messages', $this->message(str_repeat('k', 301), 'bluesky', $at))->status);

        $channel = $this->request('POST', '/messages', $this->message('k', 'myspace', $at));
        self::assertSame([422, 'Unknown channel'], [$channel->status, $channel->data()['error']]);
        self::assertSame(['instagram', 'bluesky'], $channel->data()['channels']);

        foreach (['2027-05-06 18:30', '2027-05-06T18:30:00', 'tomorrow', '2027-02-31T10:00:00Z'] as $bad) {
            self::assertSame('message.at', $this->request('POST', '/messages', $this->message('k', 'bluesky', $bad))->data()['problems'][0]['rule'] ?? null, $bad);
        }

        $missing = $this->request('POST', '/messages', $this->message('k', 'bluesky', $at, 'x', [['sha256' => str_repeat('b', 64), 'mime' => 'image/jpeg', 'role' => 'image', 'size' => 1]]));
        self::assertSame([422, 'Missing assets', [str_repeat('b', 64)]], [$missing->status, $missing->data()['error'], $missing->data()['missing']]);

        $long = $this->request('POST', '/messages', $this->message('k', 'bluesky', $at, str_repeat('ä', 301)));
        self::assertSame(422, $long->status);
        self::assertSame('channel.length', $long->data()['problems'][0]['rule']);

        $instagramText = $this->request('POST', '/messages', $this->message('k', 'instagram', $at));
        self::assertContains('channel.kind', array_column($instagramText->data()['problems'], 'rule'));

        $payload = $this->message('k', 'bluesky', $at);
        $payload['payload']['media'] = [['sha256' => str_repeat('c', 64), 'mime' => 'image/jpeg']];
        self::assertSame('asset.listed', $this->request('POST', '/messages', $payload)->data()['problems'][0]['rule']);

        self::assertSame(0, $this->services()->store->counts()['queued']);
    }

    public function testAdapterValidationIsApplied(): void
    {
        $this->reconfigure(['x' => ['adapter' => 'scripted', 'problems' => ['Board missing.']]]);
        $response = $this->request('POST', '/messages', $this->message('k', 'x', '2027-05-06T10:00:00Z'));
        self::assertSame(422, $response->status);
        self::assertSame([['rule' => 'adapter', 'message' => 'Board missing.']], $response->data()['problems']);
    }

    public function testUpsertWithdrawResolveAndList(): void
    {
        $at = '2027-05-06T12:00:00+02:00';
        $ok = $this->request('POST', '/messages', $this->message('posts/a#bluesky', 'bluesky', $at));
        self::assertSame(['ok' => true, 'key' => 'posts/a#bluesky', 'state' => 'queued'], $ok->data());
        self::assertSame(200, $this->request('POST', '/messages', $this->message('posts/a#bluesky', 'bluesky', $at, 'Neu'))->status);

        $list = $this->request('GET', '/messages')->data()['messages'];
        self::assertSame('2027-05-06T10:00:00Z', $list[0]['at']);
        self::assertArrayNotHasKey('payload', $list[0]);
        self::assertSame(422, $this->request('GET', '/messages', query: ['state' => 'bogus'])->status);
        self::assertSame([], $this->request('GET', '/messages', query: ['state' => 'sent'])->data()['messages']);

        $key = rawurlencode('posts/a#bluesky');
        self::assertSame(['ok' => true, 'key' => 'posts/a#bluesky', 'state' => 'withdrawn'], $this->request('DELETE', '/messages/' . $key)->data());
        self::assertSame(200, $this->request('DELETE', '/messages/' . $key)->status);
        self::assertSame(404, $this->request('DELETE', '/messages/' . rawurlencode('nope'))->status);
        self::assertSame(200, $this->request('DELETE', '/messages', query: ['key' => 'posts/a#bluesky'])->status, 'key as query');
        self::assertSame(400, $this->request('DELETE', '/messages')->status);

        // Resolve only after unknown (or failed → queued).
        $this->request('POST', '/messages', $this->message('posts/b#bluesky', 'bluesky', $at));
        $conflict = $this->request('POST', '/messages/' . rawurlencode('posts/b#bluesky') . '/resolve', ['state' => 'sent']);
        self::assertSame([409, 'queued'], [$conflict->status, $conflict->data()['state']]);
        self::assertSame(422, $this->request('POST', '/messages/' . rawurlencode('posts/b#bluesky') . '/resolve', ['state' => 'deleted'])->status);
        self::assertSame(404, $this->request('POST', '/messages/x/resolve', ['state' => 'sent'])->status);

        $store = $this->services()->store;
        $store->claim('posts/b#bluesky', '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z');
        $store->markUnknown('posts/b#bluesky', 'timeout');
        self::assertSame(409, $this->request('DELETE', '/messages/' . rawurlencode('posts/b#bluesky'))->status);
        self::assertSame(409, $this->request('POST', '/messages', $this->message('posts/b#bluesky', 'bluesky', $at))->status);
        self::assertSame(422, $this->request('POST', '/messages/resolve', ['state' => 'sent', 'url' => 'javascript:alert(1)'], query: ['key' => 'posts/b#bluesky'])->status);
        $resolved = $this->request('POST', '/messages/resolve', ['state' => 'sent', 'url' => 'https://bsky.app/profile/x/post/1', 'remoteId' => 'at://x/1', 'note' => 'checked'], query: ['key' => 'posts/b#bluesky']);
        self::assertSame(['ok' => true, 'key' => 'posts/b#bluesky', 'state' => 'sent'], $resolved->data());

        $results = $this->request('GET', '/results')->data()['results'];
        self::assertSame(['withdrawn', 'unknown', 'sent'], array_column($results, 'state'));
        self::assertSame(['id', 'key', 'channel', 'state', 'remoteId', 'url', 'error', 'at', 'updatedAt'], array_keys($results[0]));
        self::assertSame('https://bsky.app/profile/x/post/1', $results[2]['url']);
    }

    public function testResultsSinceAndAck(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->request('POST', '/messages', $this->message($key, 'bluesky', '2027-05-06T10:00:00Z'));
            $this->request('DELETE', '/messages/' . $key);
        }
        self::assertSame([2, 3], array_column($this->request('GET', '/results', query: ['since' => '1'])->data()['results'], 'id'));
        self::assertSame(400, $this->request('POST', '/results/ack', ['nope' => 1])->status);
        self::assertSame(['ok' => true, 'acknowledged' => 2], $this->request('POST', '/results/ack', ['ids' => [1, 2]])->data());
        self::assertSame([3], array_column($this->request('GET', '/results')->data()['results'], 'id'));
    }

    // ---------------------------------------------------- health, metrics ---

    public function testHealth(): void
    {
        $this->reconfigure(['bluesky' => ['adapter' => 'log'], 'x' => ['adapter' => 'scripted', 'ok' => false, 'tokenExpiresAt' => '2027-06-01T00:00:00Z']]);
        $health = $this->request('GET', '/health')->data();
        self::assertFalse($health['ok']);
        self::assertSame(['lastRun' => null, 'minutesSince' => null, 'late' => true], $health['timer']);
        self::assertSame(['queued' => 0, 'sending' => 0, 'failed' => 0, 'unknown' => 0], $health['queue']);
        self::assertSame(['adapter' => 'scripted', 'ok' => false, 'message' => 'scripted', 'tokenExpiresAt' => '2027-06-01T00:00:00Z'], $health['channels']['x']);
        self::assertTrue($health['channels']['bluesky']['ok']);

        $this->request('GET', '/health');
        self::assertSame(1, ScriptedAdapter::$calls['health'], 'cached');

        $this->reconfigure(['bluesky' => ['adapter' => 'log']]);
        $this->request('POST', '/messages', $this->message('k', 'bluesky', '2027-05-07T10:00:00Z'));
        $this->runner()->run();
        $this->advance('+20 minutes');
        $health = $this->request('GET', '/health')->data();
        self::assertTrue($health['ok']);
        self::assertSame(['lastRun' => '2027-05-06T10:00:00Z', 'minutesSince' => 20, 'late' => false], $health['timer']);
        self::assertSame(1, $health['queue']['queued']);

        $this->advance('+11 minutes');
        self::assertTrue($this->request('GET', '/health')->data()['timer']['late']);
    }

    public function testMetrics(): void
    {
        $this->reconfigure(['stats' => ['adapter' => 'scripted', 'metrics' => ['reach' => 50]], 'bluesky' => ['adapter' => 'log']]);
        $this->request('POST', '/messages', $this->message('k', 'stats', '2027-05-06T10:00:00Z'));
        $this->request('POST', '/messages', $this->message('l', 'bluesky', '2027-05-06T10:00:00Z'));
        $this->runner()->run();

        $metrics = $this->request('GET', '/metrics')->data()['metrics'];
        self::assertCount(1, $metrics, 'channels without numbers are left out');
        self::assertSame([
            'key' => 'k', 'channel' => 'stats', 'remoteId' => 'remote-1', 'url' => 'https://social.example/p/1',
            'sentAt' => '2027-05-06T10:00:00Z', 'fetchedAt' => '2027-05-06T10:00:00Z', 'values' => ['reach' => 50],
        ], $metrics[0]);
        self::assertCount(1, $this->request('GET', '/metrics', query: ['since' => '2027-05-06'])->data()['metrics']);
        self::assertSame([], $this->request('GET', '/metrics', query: ['since' => '2027-05-07T00:00:00Z'])->data()['metrics']);
        self::assertSame(422, $this->request('GET', '/metrics', query: ['since' => 'yesterday'])->status);
    }

    public function testServerErrorsAreLoggedWithoutDetailsInTheResponse(): void
    {
        $this->reconfigure(['broken' => ['adapter' => 'does-not-exist']]);
        $response = $this->request('POST', '/messages', $this->message('secret-slug', 'broken', '2027-05-06T10:00:00Z', 'Geheimer Text'));
        self::assertSame([500, ['error' => 'Server error']], [$response->status, $response->data()]);
        $log = implode("\n", $this->logged);
        self::assertStringContainsString('Unknown adapter "does-not-exist"', $log);
        self::assertStringNotContainsString('Geheimer Text', $log);
        self::assertStringNotContainsString(self::TOKEN, $log);
    }

    // ------------------------------------------------------- acceptance ---

    /**
     * Abnahme Outbox (B): a record with two channels on the log adapter is
     * sent at the planned time, results reach Desk, a second push changes
     * nothing, a withdrawn message never goes out, and a runner that dies
     * while sending leaves "unknown" plus a mail and no second post.
     */
    public function testAcceptance(): void
    {
        $sentLog = $this->directory . '/sent.log';
        $this->reconfigure([
            'instagram' => ['adapter' => 'log', 'file' => $sentLog],
            'bluesky' => ['adapter' => 'log', 'file' => $sentLog],
        ]);
        $planned = '2027-05-06T14:00:00+02:00'; // 12:00 UTC

        // Desk pushes: assets first, then the messages.
        $image = self::jpeg('p');
        $sha = hash('sha256', $image);
        self::assertSame(404, $this->request('GET', '/assets/' . $sha)->status);
        $asset = $this->upload($image);
        $instagram = $this->message('posts/2027-05-06-km-70#instagram', 'instagram', $planned, 'Kilometer 70 #Ruhr', [$asset]);
        $bluesky = $this->message('posts/2027-05-06-km-70#bluesky', 'bluesky', $planned, 'Kilometer 70 🌊', [$asset]);
        self::assertSame(200, $this->request('POST', '/messages', $instagram)->status);
        self::assertSame(200, $this->request('POST', '/messages', $bluesky)->status);

        // A third message is withdrawn before it is due (record back to draft).
        $draft = $this->message('posts/2027-05-06-km-71#bluesky', 'bluesky', $planned, 'Entwurf');
        $this->request('POST', '/messages', $draft);
        self::assertSame(200, $this->request('DELETE', '/messages/' . rawurlencode($draft['key']))->status);

        // Before the planned time nothing goes out.
        $this->runner()->run();
        self::assertFileDoesNotExist($sentLog);

        // At the planned time (next timer tick) both are sent.
        $this->now = new \DateTimeImmutable('2027-05-06T12:05:00Z');
        $this->runner()->run();
        self::assertCount(2, file($sentLog));
        self::assertSame('sent', $this->state($instagram['key']));
        self::assertSame('sent', $this->state($bluesky['key']));
        self::assertSame('withdrawn', $this->state($draft['key']));

        // Desk pulls the results and acknowledges them.
        $results = $this->request('GET', '/results', query: ['since' => '0'])->data()['results'];
        self::assertSame(['withdrawn', 'sent', 'sent'], array_column($results, 'state'));
        self::assertSame('2027-05-06T12:00:00Z', $results[1]['at']);
        $this->request('POST', '/results/ack', ['ids' => array_column($results, 'id')]);
        self::assertSame([], $this->request('GET', '/results')->data()['results']);

        // A second push changes nothing for sent messages.
        $second = $this->request('POST', '/messages', $instagram);
        self::assertSame([409, 'sent'], [$second->status, $second->data()['state']]);
        self::assertSame(409, $this->request('DELETE', '/messages/' . rawurlencode($bluesky['key']))->status);
        $this->advance('+5 minutes');
        $this->runner()->run();
        self::assertCount(2, file($sentLog));
        self::assertSame([], $this->request('GET', '/results')->data()['results']);

        // The runner dies while sending: unknown, a mail, no second post.
        $crash = $this->message('posts/2027-05-07-km-72#bluesky', 'bluesky', '2027-05-06T12:10:00Z', 'Absturz');
        $this->request('POST', '/messages', $crash);
        $this->advance('+5 minutes'); // 12:15
        self::assertTrue($this->services()->store->claim($crash['key'], '2027-05-06T12:15:00Z', '2027-05-06T12:25:00Z'));
        $this->advance('+15 minutes'); // 12:30, lease over
        $this->runner()->run();
        self::assertSame('unknown', $this->state($crash['key']));
        self::assertSame(['Outbox: posts/2027-05-07-km-72#bluesky unknown (bluesky)'], $this->mailer->subjects());
        self::assertCount(2, file($sentLog));
        self::assertSame(1, $this->request('GET', '/health')->data()['queue']['unknown']);
        self::assertSame(['unknown'], array_column($this->request('GET', '/results')->data()['results'], 'state'));

        $this->advance('+1 hour');
        $this->runner()->run();
        self::assertCount(2, file($sentLog));
        self::assertCount(1, $this->mailer->mails);
    }
}
