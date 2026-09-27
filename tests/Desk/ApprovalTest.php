<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Desk;

use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Storage\Status;
use Neuedaten\FreezedDesk\Tests\Support\DeskTestCase;

/**
 * A3: who changed a record, and approval reserved for a person in the UI.
 */
final class ApprovalTest extends DeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->type('posts', <<<'PHP'
<?php
return [
    'label' => 'Posts',
    'approval' => 'ui',
    'fields' => [
        'title' => ['type' => 'text', 'required' => true],
        'caption' => ['type' => 'textarea'],
        'results' => ['type' => 'json', 'system' => true],
    ],
];
PHP);
        $this->type('notes', "<?php return ['fields' => ['title' => ['type' => 'text']]];");
    }

    public function testCliPublishOfApprovalTypeIsRefusedAndChangesNothing(): void
    {
        $this->desk('put', ['posts/x'], [], '{"fields": {"title": "X", "caption": "Hallo"}}', actor: 'agent');
        $result = $this->desk('publish', ['posts/x'], [], actor: 'agent');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Desk-Oberfläche', $result['json']['error']);
        $item = $this->context()->repository()->findBySlug('posts', 'x');
        self::assertSame(Status::Draft, $item->status);
        self::assertSame('agent', $item->updatedBy);
    }

    public function testPutWithStatusPublishedIsRefusedFromTheCli(): void
    {
        $result = $this->desk('put', ['posts/y'], [], '{"status": "published", "fields": {"title": "Y"}}');

        self::assertSame(1, $result['exit']);
        self::assertNull($this->context()->repository()->findBySlug('posts', 'y'));
    }

    public function testEditorApprovesAndAgentChangeSendsBackToDraft(): void
    {
        $this->desk('put', ['posts/x'], [], '{"fields": {"title": "X", "caption": "Eins"}}', actor: 'agent');
        $item = $this->context()->repository()->findBySlug('posts', 'x');

        $this->context()->actAs(Actor::Editor);
        $this->context()->repository()->setStatus([$item->id], Status::Published);
        self::assertSame(Status::Published, $this->context()->repository()->require($item->id)->status);

        $result = $this->desk('put', ['posts/x'], [], '{"fields": {"caption": "Zwei"}}', actor: 'agent');
        self::assertSame(0, $result['exit']);
        self::assertSame('draft', $result['json']['status']);
        self::assertNotEmpty($result['json']['notices']);
    }

    public function testSystemFieldsKeepAnApprovedRecordApproved(): void
    {
        $this->desk('put', ['posts/x'], [], '{"fields": {"title": "X"}}');
        $item = $this->context()->repository()->findBySlug('posts', 'x');
        $this->context()->actAs(Actor::Editor);
        $this->context()->repository()->setStatus([$item->id], Status::Published);
        $this->context()->actAs(Actor::Cli);

        $saved = $this->context()->repository()->saveSystemFields($item->id, ['results' => ['instagram' => ['url' => 'https://example.org/p/1']]], 'outbox');

        self::assertSame(Status::Published, $saved->status);
        self::assertSame('cli', $saved->updatedBy);
        self::assertSame('https://example.org/p/1', $saved->data['results']['instagram']['url']);
    }

    public function testPutIgnoresSystemFieldsAndTheUiNeverWritesThem(): void
    {
        $this->desk('put', ['posts/x'], [], '{"fields": {"title": "X", "results": {"forged": true}}}');
        self::assertNull($this->context()->repository()->findBySlug('posts', 'x')->data['results']);

        $item = $this->context()->repository()->findBySlug('posts', 'x');
        $this->context()->actAs(Actor::Editor);
        $saved = $this->context()->repository()->save('posts', ['fields' => ['results' => ['forged' => true], 'title' => 'X2']], $item->id);
        self::assertNull($saved->data['results']);
    }

    public function testTypesWithoutApprovalPublishFromTheCli(): void
    {
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A"}}');
        $result = $this->desk('publish', ['notes/a']);

        self::assertSame(0, $result['exit']);
        self::assertSame('published', $result['json']['status']);
    }

    public function testTheCliCannotActAsEditor(): void
    {
        $result = $this->desk('put', ['notes/a'], ['--actor:editor'], '{"fields": {"title": "A"}}');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('editor', $result['json']['error']);
    }

    public function testRevisionsRecordTheActorOfEachState(): void
    {
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A"}}', actor: 'agent');
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "B"}}');
        $revisions = $this->desk('revisions', ['notes/a'])['json']['revisions'];

        self::assertSame([2, 1], array_column($revisions, 'revision'));
        self::assertSame(['cli', 'agent'], array_column($revisions, 'actor'));
    }

    public function testUnseenAgentChangesUntilAPersonOpensTheRecord(): void
    {
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A"}}', actor: 'agent');
        self::assertCount(1, $this->desk('list', ['notes'], ['--unseen'])['json']['records']);

        $item = $this->context()->repository()->findBySlug('notes', 'a');
        $this->context()->repository()->markSeen($item->id);
        self::assertCount(0, $this->desk('list', ['notes'], ['--unseen'])['json']['records']);
    }

    public function testExportAndImportKeepAnApprovedRecordApproved(): void
    {
        $this->desk('put', ['posts/x'], [], '{"fields": {"title": "X"}}');
        $item = $this->context()->repository()->findBySlug('posts', 'x');
        $this->context()->actAs(Actor::Editor);
        $this->context()->repository()->setStatus([$item->id], Status::Published);

        self::assertSame(0, $this->desk('export')['exit']);
        $this->context()->repository()->delete($item->id);
        self::assertSame(0, $this->desk('import')['exit']);

        $imported = $this->context()->repository()->findBySlug('posts', 'x');
        self::assertSame(Status::Published, $imported->status);
        self::assertSame('import', $imported->updatedBy);
    }
}
