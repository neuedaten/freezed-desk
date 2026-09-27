<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Desk;

use Neuedaten\FreezedDesk\Tests\Support\DeskTestCase;

/**
 * A4 (conflict protection) and A5 (validate, warnings, guard).
 */
final class ConflictAndValidationTest extends DeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->type('notes', <<<'PHP'
<?php
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Validation\ValidationContext;
return [
    'fields' => [
        'title' => ['type' => 'text'],
        'text' => ['type' => 'textarea'],
        'source' => ['type' => 'text'],
    ],
    'validate' => function (array $fields, ?Item $item, ValidationContext $ctx): array {
        return str_contains((string) $fields['text'], '€') ? ['text' => 'Keine Preise (Regel price).'] : [];
    },
    'warnings' => fn (array $fields): array => mb_strlen((string) $fields['text']) > 20 ? ['text' => 'Lang.'] : [],
    'guard' => function (array $fields, ?Item $item, ValidationContext $ctx): array {
        if ($item !== null && $ctx->actor->value === 'agent' && $fields['source'] !== $item->data['source']) {
            return ['source' => 'Der Agent tauscht die Quelle nicht.'];
        }
        return [];
    },
];
PHP);
    }

    public function testIfRevisionRefusesAnOutdatedChange(): void
    {
        $created = $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A"}}')['json'];
        self::assertSame(1, $created['revision']);
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "B"}}');

        $result = $this->desk('put', ['notes/a'], ['--if-revision:1'], '{"fields": {"title": "C"}}');

        self::assertSame(1, $result['exit']);
        self::assertStringStartsWith('conflict', $result['json']['error']);
        self::assertSame('B', $result['json']['current']['title']);
        self::assertSame(2, $result['json']['current']['revision']);
        self::assertSame('B', $this->context()->repository()->findBySlug('notes', 'a')->title);

        $ok = $this->desk('put', ['notes/a'], ['--if-revision:2'], '{"fields": {"title": "C"}}');
        self::assertSame(0, $ok['exit']);
        self::assertSame(3, $ok['json']['revision']);
    }

    public function testValidateIsAHintInADraftAndBlocksPublishing(): void
    {
        $saved = $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A", "text": "Nur 5 €"}}');
        self::assertSame(0, $saved['exit']);
        self::assertSame(['text' => 'Keine Preise (Regel price).'], $saved['json']['validation']['errors']);
        self::assertFalse($saved['json']['validation']['blocking']);

        $check = $this->desk('validate', ['notes/a'], ['--publishing']);
        self::assertSame(1, $check['exit']);
        self::assertFalse($check['json']['ok']);

        $publish = $this->desk('publish', ['notes/a']);
        self::assertSame(1, $publish['exit']);
        self::assertStringContainsString('Keine Preise', $publish['json']['error']);
    }

    public function testWarningsNeverBlock(): void
    {
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A", "text": "Ein ziemlich langer Text ohne Preis"}}');
        $publish = $this->desk('publish', ['notes/a']);

        self::assertSame(0, $publish['exit']);
        self::assertSame(['text' => 'Lang.'], $publish['json']['validation']['warnings']);
    }

    public function testGuardBlocksEverySave(): void
    {
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A", "source": "eins"}}');
        $result = $this->desk('put', ['notes/a'], [], '{"fields": {"source": "zwei"}}', actor: 'agent');

        self::assertSame(1, $result['exit']);
        self::assertSame(['source' => 'Der Agent tauscht die Quelle nicht.'], $result['json']['errors']);
        self::assertSame(0, $this->desk('put', ['notes/a'], [], '{"fields": {"source": "zwei"}}')['exit']);
    }

    public function testBulkPublishNamesEveryRejectedRecord(): void
    {
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A", "text": "ok"}}');
        $this->desk('put', ['notes/b'], [], '{"fields": {"title": "B", "text": "3 €"}}');
        $this->desk('put', ['notes/c'], [], '{"fields": {"title": "C", "text": "1 €"}}');

        $result = $this->desk('publish', ['notes'], ['--where:title~'], null);
        self::assertSame(1, $result['exit']);
        self::assertSame(1, $result['json']['changed']);
        self::assertSame(['b', 'c'], array_column($result['json']['rejected'], 'slug'));
    }

    public function testDryRunKeepsNothing(): void
    {
        $result = $this->desk('put', ['notes/a'], ['--dry-run'], '{"fields": {"title": "A"}}');

        self::assertSame(0, $result['exit']);
        self::assertTrue($result['json']['dryRun']);
        self::assertSame('A', $result['json']['title']);
        self::assertNull($this->context()->repository()->findBySlug('notes', 'a'));
    }

    public function testRevisionDiffAndRestore(): void
    {
        $this->desk('put', ['notes/a'], [], '{"fields": {"title": "A", "text": "eins"}}');
        $this->desk('put', ['notes/a'], [], '{"fields": {"text": "zwei"}}');

        $diff = $this->desk('revision', ['notes/a', '1'], ['--diff'])['json'];
        self::assertSame([['field' => 'text', 'then' => 'eins', 'now' => 'zwei']], $diff['diff']);

        $restored = $this->desk('restore', ['notes/a', '1'])['json'];
        self::assertSame('eins', $restored['fields']['text']);
        self::assertSame(3, $restored['revision']);
    }
}
