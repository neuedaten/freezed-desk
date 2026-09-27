<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Desk;

use DeskOutbox\Runner;
use DeskOutbox\Services;
use Neuedaten\FreezedDesk\Commands\OutboxCommand;
use Neuedaten\FreezedDesk\Outbox\Outbox;
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Storage\Status;
use Neuedaten\FreezedDesk\Tests\Server\Support\CollectingMailer;
use Neuedaten\FreezedDesk\Tests\Server\Support\FakeHttpClient;
use Neuedaten\FreezedDesk\Tests\Support\DeskTestCase;
use Neuedaten\FreezedDesk\Tests\Support\ServerTransport;
use Neuedaten\FreezedDesk\Tests\Support\TestPostsMapper;

/**
 * B, acceptance: an approved record with two channels and the log adapter
 * is sent with "Senden", published at its time and shown as sent after
 * pull; a second push changes nothing; the CLI cannot push; a record that
 * goes back to draft before its time is withdrawn.
 */
final class OutboxIntegrationTest extends DeskTestCase
{
    private \DateTimeImmutable $serverNow;

    private Services $services;

    private ServerTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DESK_OUTBOX_TOKEN=secret-token');
        $this->writeConfig(['outbox' => [
            'url' => 'https://example.org/api/v1/outbox',
            'tokenEnv' => 'DESK_OUTBOX_TOKEN',
            'types' => ['posts'],
            'mapper' => TestPostsMapper::class,
        ]]);
        $this->type('posts', <<<'PHP'
<?php
return [
    'approval' => 'ui',
    'fields' => [
        'title' => ['type' => 'text'],
        'text' => ['type' => 'textarea'],
        'at' => ['type' => 'datetime'],
        'channels' => ['type' => 'select', 'multiple' => true, 'options' => ['instagram' => 'Instagram', 'bluesky' => 'Bluesky']],
        'image' => ['type' => 'image'],
        'results' => ['type' => 'json', 'system' => true],
    ],
];
PHP);

        $this->serverNow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->services = new Services(
            [
                'token' => 'secret-token',
                'database' => $this->root . '/server/var/outbox.sqlite',
                'mediaPath' => $this->root . '/server/var/outbox-media',
                'publicBase' => 'https://example.org/api/v1/outbox/media',
                'mail' => ['to' => 'redaktion@example.org'],
                'channels' => ['instagram' => ['adapter' => 'log'], 'bluesky' => ['adapter' => 'log']],
            ],
            fn (): \DateTimeImmutable => $this->serverNow,
            new FakeHttpClient(),
            new CollectingMailer(),
            static function (string $line): void {
            },
        );
        $this->transport = new ServerTransport($this->services);
        OutboxCommand::$transport = fn (): ServerTransport => $this->transport;
    }

    protected function tearDown(): void
    {
        putenv('DESK_OUTBOX_TOKEN');
        OutboxCommand::$transport = null;
        parent::tearDown();
    }

    public function testApprovedPostTravelsThroughTheOutbox(): void
    {
        $at = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $image = imagecreatetruecolor(40, 50);
        imagejpeg($image, $this->root . '/tile.jpg');
        $file = $this->desk('media:add', [$this->root . '/tile.jpg'])['json']['file'];
        $this->desk('put', ['posts/perle'], [], json_encode(['fields' => ['title' => 'Perle', 'text' => 'Km 73 · Kemnader See', 'at' => $at, 'channels' => ['instagram', 'bluesky'], 'image' => ['file' => $file]]]), actor: 'agent');
        $this->approve('perle');

        // The CLI may look, but not send.
        $cli = $this->desk('outbox:push');
        self::assertSame(1, $cli['exit']);
        self::assertStringContainsString('Senden', $cli['json']['error']);
        self::assertSame(['posts/perle#bluesky', 'posts/perle#instagram'], $this->desk('outbox:push', [], ['--dry-run'])['json']['pushed']);

        // "Senden" in the UI.
        $summary = $this->outbox(Actor::Editor)->push();
        self::assertSame(['posts/perle#bluesky', 'posts/perle#instagram'], $summary['pushed']);
        self::assertSame([], $summary['errors']);
        self::assertSame(0, count($this->outbox(Actor::Editor)->push()['pushed']), 'a second push changes nothing');

        // The server publishes at the planned time.
        $this->serverNow = $this->serverNow->modify('+3 days');
        (new Runner($this->services))->run();

        $pull = $this->desk('outbox:pull')['json'];
        self::assertSame(2, $pull['results']);
        $post = $this->context()->repository()->findBySlug('posts', 'perle');
        self::assertSame('sent', $post->data['results']['instagram']['state']);
        self::assertSame(Status::Published, $post->status, 'results keep the post approved');
        self::assertSame(['sent'], array_values(array_unique(array_column($this->outbox(Actor::Cli)->repository()->all(), 'state'))));

        // A change after publishing has no effect any more.
        $this->context()->actAs(Actor::Editor);
        $this->context()->repository()->save('posts', ['fields' => ['text' => 'Neu']], $post->id);
        $after = $this->outbox(Actor::Editor)->push();
        self::assertSame([], $after['pushed']);
        self::assertSame(['posts/perle#bluesky', 'posts/perle#instagram'], $after['frozen']);
    }

    public function testDraftBeforeItsTimeIsWithdrawnAndLateMessagesStayOut(): void
    {
        $at = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $this->desk('put', ['posts/a'], [], json_encode(['fields' => ['title' => 'A', 'text' => 'A', 'at' => $at, 'channels' => ['bluesky']]]));
        $this->desk('put', ['posts/late'], [], json_encode(['fields' => ['title' => 'L', 'text' => 'L', 'at' => '2020-01-01T10:00', 'channels' => ['bluesky']]]));
        $this->approve('a');
        $this->approve('late');

        $summary = $this->outbox(Actor::Editor)->push();
        self::assertSame(['posts/a#bluesky'], $summary['pushed']);
        self::assertSame(['posts/late#bluesky'], $summary['late']);

        $this->desk('unpublish', ['posts/a']);
        $withdrawn = $this->outbox(Actor::Editor)->push();
        self::assertSame(['posts/a#bluesky'], $withdrawn['withdrawn']);

        $this->serverNow = $this->serverNow->modify('+3 days');
        self::assertSame([], array_filter((new Runner($this->services))->run(), static fn (string $line): bool => str_contains($line, 'sent')));
    }

    private function approve(string $slug): void
    {
        $item = $this->context()->repository()->findBySlug('posts', $slug);
        $this->context()->actAs(Actor::Editor);
        $this->context()->repository()->setStatus([$item->id], Status::Published);
        $this->context()->actAs(Actor::Cli);
    }

    private function outbox(Actor $actor): Outbox
    {
        $this->context()->actAs($actor);

        return new Outbox($this->context(), $this->transport);
    }
}
