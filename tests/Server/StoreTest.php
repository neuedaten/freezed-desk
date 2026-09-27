<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server;

use DeskOutbox\Message;
use DeskOutbox\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $file;

    private \DateTimeImmutable $now;

    private Store $store;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/desk-outbox-store-' . bin2hex(random_bytes(6)) . '/outbox.sqlite';
        $this->now = new \DateTimeImmutable('2027-05-06T10:00:00Z');
        $this->store = new Store($this->file, fn (): \DateTimeImmutable => $this->now);
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->file) . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir(dirname($this->file));
    }

    private function message(string $key = 'posts/a#instagram', string $at = '2027-05-06T09:00:00Z', string $text = 'Hallo'): Message
    {
        return Message::fromArray(['key' => $key, 'channel' => 'instagram', 'at' => $at, 'payload' => ['kind' => 'text', 'text' => $text]]);
    }

    public function testSchemaIsVersionedAndReopenable(): void
    {
        self::assertSame(1, $this->store->schemaVersion());
        $again = new Store($this->file);
        self::assertSame(1, $again->schemaVersion());
        self::assertSame('wal', $again->pdo()->query('PRAGMA journal_mode')->fetchColumn());
    }

    public function testTimesAreStoredInUtc(): void
    {
        $this->store->upsert(Message::fromArray(['key' => 'k', 'channel' => 'instagram', 'at' => '2027-05-06T18:30:00+02:00', 'payload' => []]), 'h');
        self::assertSame('2027-05-06T16:30:00Z', $this->store->message('k')['at_utc']);
        self::assertSame('2027-05-06T10:00:00Z', $this->store->message('k')['created_at']);
    }

    public function testClaimIsAtomicAcrossConnections(): void
    {
        $this->store->upsert($this->message(), 'h');
        $second = new Store($this->file, fn (): \DateTimeImmutable => $this->now);

        self::assertTrue($this->store->claim('posts/a#instagram', '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z'));
        self::assertFalse($second->claim('posts/a#instagram', '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z'));

        $row = $this->store->message('posts/a#instagram');
        self::assertSame('sending', $row['state']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('2027-05-06T10:00:00Z', $row['first_attempt_at']);
    }

    public function testClaimRespectsDueTimeAndBackoff(): void
    {
        $this->store->upsert($this->message(at: '2027-05-06T11:00:00Z'), 'h');
        self::assertSame([], $this->store->dueKeys('2027-05-06T10:00:00Z'));
        self::assertFalse($this->store->claim('posts/a#instagram', '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z'));

        self::assertTrue($this->store->claim('posts/a#instagram', '2027-05-06T11:00:00Z', '2027-05-06T11:10:00Z'));
        self::assertTrue($this->store->requeue('posts/a#instagram', '2027-05-06T11:05:00Z', 'HTTP 503'));
        self::assertSame([], $this->store->dueKeys('2027-05-06T11:04:59Z'));
        self::assertSame(['posts/a#instagram'], $this->store->dueKeys('2027-05-06T11:05:00Z'));
    }

    public function testUpsertReplacesOnlyReplaceableStates(): void
    {
        $key = 'posts/a#instagram';
        self::assertNull($this->store->upsert($this->message(), 'h1'));
        self::assertNull($this->store->upsert($this->message(text: 'Neu'), 'h2'));
        self::assertSame('h2', $this->store->message($key)['hash']);

        // failed → replaced and reset
        $this->store->claim($key, '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z');
        $this->store->markFailed($key, 'boom');
        self::assertNull($this->store->upsert($this->message(text: 'Nochmal'), 'h3'));
        $row = $this->store->message($key);
        self::assertSame(['queued', 0, null, null], [$row['state'], (int) $row['attempts'], $row['error'], $row['first_attempt_at']]);

        // withdrawn → queued again
        $this->store->withdraw($key);
        self::assertNull($this->store->upsert($this->message(), 'h4'));
        self::assertSame('queued', $this->store->message($key)['state']);

        // sending, sent and unknown are never replaced
        $this->store->claim($key, '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z');
        self::assertSame('sending', $this->store->upsert($this->message(text: 'X'), 'h5'));
        $this->store->markSent($key, 'remote-1', 'https://example.org/p/1');
        self::assertSame('sent', $this->store->upsert($this->message(text: 'X'), 'h5'));
        self::assertSame('h4', $this->store->message($key)['hash']);
    }

    public function testWithdrawRules(): void
    {
        self::assertSame('missing', $this->store->withdraw('nope')['outcome']);
        $this->store->upsert($this->message(), 'h');
        self::assertSame('withdrawn', $this->store->withdraw('posts/a#instagram')['outcome']);
        self::assertSame('already', $this->store->withdraw('posts/a#instagram')['outcome']);

        $this->store->upsert($this->message('b'), 'h');
        $this->store->claim('b', '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z');
        self::assertSame(['outcome' => 'conflict', 'state' => 'sending'], $this->store->withdraw('b'));

        self::assertSame(['withdrawn'], array_column($this->store->results(0), 'state'));
    }

    public function testResolveOnlyFromUnknownOrFailedToQueued(): void
    {
        $this->store->upsert($this->message(), 'h');
        self::assertSame('conflict', $this->store->resolve('posts/a#instagram', 'sent')['outcome']);

        $this->store->claim('posts/a#instagram', '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z');
        $this->store->markUnknown('posts/a#instagram', 'timeout');
        $result = $this->store->resolve('posts/a#instagram', 'sent', 'https://instagram.com/p/x', '1789');
        self::assertSame('resolved', $result['outcome']);
        $row = $this->store->message('posts/a#instagram');
        self::assertSame(['sent', '1789', 'https://instagram.com/p/x'], [$row['state'], $row['remote_id'], $row['url']]);
        self::assertSame('conflict', $this->store->resolve('posts/a#instagram', 'queued')['outcome']);

        $this->store->upsert($this->message('c'), 'h');
        $this->store->claim('c', '2027-05-06T10:00:00Z', '2027-05-06T10:10:00Z');
        $this->store->markFailed('c', 'bad');
        self::assertSame('conflict', $this->store->resolve('c', 'sent')['outcome']);
        self::assertSame('resolved', $this->store->resolve('c', 'queued')['outcome']);
        self::assertSame('queued', $this->store->message('c')['state']);

        self::assertSame(['unknown', 'sent', 'failed', 'queued'], array_column($this->store->results(0), 'state'));
    }

    public function testFinishingNeedsSendingState(): void
    {
        $this->store->upsert($this->message(), 'h');
        self::assertFalse($this->store->markSent('posts/a#instagram', 'r', ''));
        self::assertSame('queued', $this->store->message('posts/a#instagram')['state']);
        self::assertSame([], $this->store->results(0));
    }

    public function testResultsAckAndPrune(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->store->upsert($this->message($key), 'h');
            $this->store->withdraw($key);
        }
        $results = $this->store->results(0);
        self::assertSame([1, 2, 3], array_column($results, 'id'));
        self::assertSame(['a', 'b', 'c'], array_column($results, 'key'));
        self::assertSame([3], array_column($this->store->results(2), 'id'));

        self::assertSame(2, $this->store->ack([1, 2, 99]));
        self::assertSame([3], array_column($this->store->results(0), 'id'));

        self::assertSame(0, $this->store->pruneResults('2027-05-06T09:00:00Z'));
        self::assertSame(2, $this->store->pruneResults('2027-05-06T10:00:01Z'));
    }

    public function testStateStore(): void
    {
        self::assertNull($this->store->get('x'));
        $this->store->set('x', '1');
        $this->store->set('x', '2');
        self::assertSame('2', $this->store->get('x'));
        $this->store->set('x', null);
        self::assertNull($this->store->get('x'));
    }

    public function testCountsAndList(): void
    {
        $this->store->upsert($this->message('a'), 'h');
        $this->store->upsert($this->message('b'), 'h');
        $this->store->withdraw('b');
        $counts = $this->store->counts();
        self::assertSame(1, $counts['queued']);
        self::assertSame(1, $counts['withdrawn']);
        self::assertSame(0, $counts['unknown']);
        $list = $this->store->listMessages('queued');
        self::assertCount(1, $list);
        self::assertSame(['key', 'channel', 'at', 'state', 'attempts', 'remoteId', 'url', 'error', 'updatedAt'], array_keys($list[0]));
    }
}
