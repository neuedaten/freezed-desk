<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Desk;

use Neuedaten\FreezedDesk\Commands\ExportCommand;
use Neuedaten\FreezedDesk\Exception\ApprovalException;
use Neuedaten\FreezedDesk\Review\ReviewPresenter;
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\Status;
use Neuedaten\FreezedDesk\Tests\Support\DeskTestCase;

/**
 * Reviews (docs/review.md): decisions with points, queues, the status a
 * decision sets, marking points done, the CLI and the export.
 */
final class ReviewTest extends DeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->type('entries', <<<'PHP'
<?php
return [
    'label' => 'Einträge',
    'fields' => [
        'title' => ['type' => 'text', 'required' => true],
        'body' => ['type' => 'markdown'],
        'teaser' => ['type' => 'textarea'],
        'sources' => ['type' => 'textarea', 'internal' => true],
        'rendered' => ['type' => 'json', 'system' => true],
    ],
];
PHP);
        $this->type('site', "<?php return ['single' => true, 'fields' => ['siteName' => ['type' => 'text']]];");
        $this->desk('put', ['entries/a'], [], '{"fields": {"title": "A", "teaser": "Siehe https://example.org/a."}}', actor: 'agent');
        $this->desk('put', ['entries/b'], [], '{"fields": {"title": "B"}}', actor: 'agent');
        $this->desk('put', ['entries/c'], [], '{"fields": {"title": "C"}}', actor: 'agent');
    }

    private function item(string $slug): Item
    {
        $item = $this->context()->repository()->findBySlug('entries', $slug);
        self::assertNotNull($item);

        return $item;
    }

    /** @param array<int, array<string, mixed>> $points */
    private function review(string $slug, string $decision, array $points = []): array
    {
        $this->context()->actAs(Actor::Editor);
        try {
            return $this->context()->reviews()->submit($this->item($slug), $decision, $points, 'bastian');
        } finally {
            $this->context()->actAs(Actor::Cli);
        }
    }

    public function testSingleTypesAreNotOfferedAndOnlyAPersonReviews(): void
    {
        self::assertSame(['entries'], array_keys($this->context()->reviews()->types()));

        $this->expectException(ApprovalException::class);
        $this->context()->reviews()->submit($this->item('a'), 'approve', []);
    }

    public function testPointsAreStoredAndOnlyAConfirmationIsNotOpen(): void
    {
        $result = $this->review('a', 'resubmit', [
            ['field' => null, 'tags' => [], 'text' => 'Bild fehlt.'],
            ['field' => 'title', 'tags' => ['ok'], 'text' => ''],
            ['field' => 'teaser', 'tags' => ['source', 'rephrase', 'nonsense'], 'text' => 'Zu lang'],
            ['field' => 'body', 'tags' => [], 'text' => '  '],
        ]);

        $review = $result['review'];
        self::assertSame('resubmit', $review['decision']);
        self::assertSame('bastian', $review['reviewer']);
        self::assertCount(3, $review['points']);
        self::assertSame(2, $review['openPoints']);
        self::assertSame(['source', 'rephrase'], $review['points'][2]['tags']);
        self::assertTrue($review['points'][1]['confirms']);
        self::assertFalse($review['points'][1]['open']);
        self::assertSame(2, $this->context()->reviews()->openTotal());
    }

    public function testQueuesFollowContentNotStatusOrSystemFields(): void
    {
        $reviews = $this->context()->reviews();
        $schema = $this->context()->schemas()->get('entries');
        self::assertSame(['a', 'b', 'c'], array_map(static fn (Item $i): string => $i->slug, $reviews->queue($schema, 'open')));

        $this->review('a', 'defer');
        $this->review('b', 'block');
        self::assertSame(['c'], array_map(static fn (Item $i): string => $i->slug, $reviews->queue($schema, 'open')));
        self::assertSame(['a'], array_map(static fn (Item $i): string => $i->slug, $reviews->queue($schema, 'defer')));

        // A system field or a status change is not a change of content.
        $this->context()->repository()->saveSystemFields($this->item('a')->id, ['rendered' => ['x' => 1]]);
        $this->context()->repository()->setStatus([$this->item('a')->id], Status::Published);
        self::assertSame('defer', $reviews->state($this->item('a'))['state']);

        // An internal field is content: sources added after "Where is this from?".
        $this->desk('put', ['entries/a'], [], '{"fields": {"sources": "https://example.org/quelle"}}', actor: 'agent');
        $reviews->reset();
        self::assertSame('changed', $reviews->state($this->item('a'))['state']);
        self::assertSame(['a', 'c'], array_map(static fn (Item $i): string => $i->slug, $reviews->queue($schema, 'open')));

        $counts = $reviews->counts($schema);
        self::assertSame(2, $counts['open']);
        self::assertSame(1, $counts['block']);
        self::assertSame(3, $counts['all']);
    }

    public function testApprovePublishesAndBlockUnpublishesAndHoldsBack(): void
    {
        $result = $this->review('a', 'approve');
        self::assertTrue($result['statusChanged']);
        self::assertSame(Status::Published, $this->item('a')->status);

        $this->review('a', 'block', [['field' => 'teaser', 'tags' => ['remove']]]);
        self::assertSame(Status::Draft, $this->item('a')->status);

        // Publishing is refused from the CLI and in the UI while blocked.
        $published = $this->desk('publish', ['entries/a']);
        self::assertSame(1, $published['exit']);
        self::assertStringContainsString('gesperrt', $published['json']['error']);
        $this->context()->actAs(Actor::Editor);
        self::assertNotSame([], $this->context()->repository()->changeStatus([$this->item('a')->id], Status::Published)['rejected']);

        // A later approval lifts the block.
        $this->review('a', 'approve');
        self::assertSame(Status::Published, $this->item('a')->status);
    }

    public function testApprovalKeepsTheReviewWhenTheChecksRefusePublishing(): void
    {
        $this->type('entries', <<<'PHP'
<?php
return [
    'fields' => [
        'title' => ['type' => 'text', 'required' => true],
        'teaser' => ['type' => 'textarea'],
    ],
    'validate' => static fn (array $data): array => ($data['teaser'] ?? '') === '' ? ['teaser' => 'Teaser fehlt.'] : [],
];
PHP);
        $result = $this->review('b', 'approve');

        self::assertFalse($result['statusChanged']);
        self::assertStringContainsString('Teaser fehlt', (string) $result['rejected']);
        self::assertSame('approve', $this->context()->reviews()->latest($this->item('b')->id)['decision']);
        self::assertSame(Status::Draft, $this->item('b')->status);
    }

    public function testCliListsAndMarksPointsDone(): void
    {
        $this->review('a', 'resubmit', [
            ['field' => 'teaser', 'tags' => ['source'], 'text' => ''],
            ['field' => null, 'text' => 'Mehr Kontext'],
        ]);

        $open = $this->desk('reviews', [], [], actor: 'agent')['json'];
        self::assertCount(1, $open['records']);
        self::assertSame('a', $open['records'][0]['slug']);
        $points = $open['records'][0]['points'];
        self::assertSame(['Woher kommt die Info?'], $points[0]['tagLabels']);
        self::assertSame('Allgemein', $points[1]['label']);
        self::assertSame('resubmit', $points[0]['review']['decision']);

        $dry = $this->desk('review:done', [(string) $points[0]['id']], ['--note:Quelle ergänzt', '--dry-run'], actor: 'agent');
        self::assertTrue($dry['json']['dryRun']);
        self::assertSame(2, $this->context()->reviews()->openTotal());

        $done = $this->desk('review:done', [(string) $points[0]['id']], ['--note:Quelle ergänzt'], actor: 'agent');
        self::assertSame(0, $done['exit']);
        self::assertSame('agent', $done['json']['points'][0]['doneBy']);
        self::assertSame('Quelle ergänzt', $done['json']['points'][0]['doneNote']);

        $history = $this->desk('reviews', ['entries/a'])['json'];
        self::assertSame(1, $history['reviews'][0]['openPoints']);

        $this->desk('review:done', ['entries/a'], ['--all']);
        self::assertSame(0, $this->context()->reviews()->openTotal());

        $this->desk('review:done', [(string) $points[0]['id']], ['--reopen']);
        self::assertSame(1, $this->context()->reviews()->openTotal());

        $list = $this->desk('review:list', ['entries'], ['--queue:points'])['json'];
        self::assertSame(['a'], array_column($list['records'], 'slug'));
        self::assertSame(1, $this->desk('review:list', ['site'])['exit']);
        self::assertSame(2, $this->desk('review:list')['json']['types']['entries']['open']);
    }

    public function testExportAndImportKeepTheHistory(): void
    {
        $this->review('a', 'resubmit', [['field' => 'teaser', 'tags' => ['rephrase'], 'text' => 'Kürzer']]);
        $this->desk('export');
        $file = $this->root . '/data/export/' . ExportCommand::REVIEWS . '/entries/a.json';
        self::assertFileExists($file);
        $exported = json_decode((string) file_get_contents($file), true);
        self::assertSame('resubmit', $exported['reviews'][0]['decision']);
        self::assertSame('A', $exported['reviews'][0]['snapshot']['data']['title']);

        // A fresh database gets the records and their history back.
        unlink($this->root . '/data/desk.sqlite');
        $this->boot();
        $this->desk('import');
        $history = $this->context()->reviews()->forItem($this->item('a')->id);
        self::assertCount(1, $history);
        self::assertSame('Kürzer', $history[0]['points'][0]['text']);

        // Importing again adds nothing.
        $this->desk('import');
        self::assertCount(1, $this->context()->reviews()->forItem($this->item('a')->id));

        // A deleted record takes its reviews with it; the export drops the file.
        $this->desk('delete', ['entries/a']);
        $this->desk('export');
        self::assertFileDoesNotExist($file);
    }

    public function testPresenterEscapesAndOpensLinksInANewWindow(): void
    {
        $html = ReviewPresenter::linkify('<b>Mehr</b> auf www.example.org/x, und (https://example.org/y).');
        self::assertStringContainsString('&lt;b&gt;Mehr&lt;/b&gt;', $html);
        self::assertStringContainsString('<a href="https://www.example.org/x" target="_blank" rel="noopener noreferrer">www.example.org/x</a>,', $html);
        self::assertStringContainsString('>https://example.org/y</a>).', $html);

        $this->desk('put', ['entries/a'], [], '{"fields": {"body": "Siehe [Quelle](https://example.org/q) <script>x</script>"}}');
        $presented = (new ReviewPresenter($this->context()))->present($this->context()->schemas()->get('entries'), $this->item('a'));
        $body = array_values(array_filter($presented['fields'], static fn (array $f): bool => $f['name'] === 'body'))[0]['html'];
        self::assertStringContainsString('<a target="_blank" rel="noopener noreferrer" href="https://example.org/q">Quelle</a>', $body);
        self::assertStringNotContainsString('<script>', $body);
        self::assertNotContains('rendered', array_column($presented['fields'], 'name'));

        // Every address of every field, once, with the fields it is in.
        $this->desk('put', ['entries/a'], [], '{"fields": {"sources": "https://example.org/q (abgerufen heute)\nwww.example.org/zwei"}}');
        $urls = (new ReviewPresenter($this->context()))->urls($this->context()->schemas()->get('entries'), $this->item('a'));
        self::assertSame(['https://example.org/q', 'https://example.org/a', 'https://www.example.org/zwei'], array_column($urls, 'href'));
        self::assertSame(['Body', 'Sources'], $urls[0]['fields']);
    }
}
