<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Desk;

use Neuedaten\FreezedDesk\Tests\Support\DeskTestCase;

/**
 * A1.1 list filters, A1.2 refs, A7 media extra fields, A8 generated media.
 */
final class ListAndMediaTest extends DeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeConfig(['media' => ['fields' => [
            'socialOk' => ['type' => 'bool', 'label' => 'Social', 'default' => ['upload' => true, 'import' => false]],
        ]]]);
        $this->type('spots', "<?php return ['fields' => ['title' => ['type' => 'text']]];");
        $this->type('posts', <<<'PHP'
<?php
return [
    'orderBy' => ['at' => 'ASC'],
    'listViews' => ['agenda' => ['field' => 'at'], 'table'],
    'listFilters' => ['format', 'spot', 'at', 'updatedBy'],
    'fields' => [
        'title' => ['type' => 'text'],
        'format' => ['type' => 'select', 'options' => ['perle' => 'Perle', 'serie' => 'Serie']],
        'spot' => ['type' => 'relation', 'to' => 'spots'],
        'at' => ['type' => 'datetime'],
        'km' => ['type' => 'number'],
        'hero' => ['type' => 'image'],
    ],
];
PHP);
        $this->desk('put', ['spots/kemnade'], [], '{"fields": {"title": "Kemnader See"}}');
        $today = (new \DateTimeImmutable('today'));
        foreach ([['a', 'perle', 1, 73], ['b', 'serie', 5, 20], ['c', 'perle', 30, 150]] as [$slug, $format, $days, $km]) {
            $at = $today->modify('+' . $days . ' days')->format('Y-m-d') . 'T17:00';
            $spot = $slug === 'a' ? '{"type": "spots", "slug": "kemnade"}' : 'null';
            $this->desk('put', ['posts/' . $slug], [], sprintf('{"fields": {"title": "%s", "format": "%s", "at": "%s", "km": %d, "spot": %s}}', strtoupper($slug), $format, $at, $km, $spot));
        }
    }

    public function testWhereOrderAndDateRange(): void
    {
        self::assertSame(['a', 'c'], $this->slugs(['--where:format=perle']));
        self::assertSame(['c', 'a'], $this->slugs(['--where:format=perle', '--order:-km']));
        self::assertSame(['a', 'b'], $this->slugs(['--from:today', '--to:+14d']));
        self::assertSame(['b', 'c'], $this->slugs(['--where:km>=20', '--where:spot=']));
        self::assertSame(['a'], $this->slugs(['--where:spot=kemnade']));
        self::assertSame(['a'], $this->slugs(['--referencing:spots/kemnade']));
        self::assertSame(['a', 'b'], $this->slugs(['--range:next14']));
    }

    public function testListCanAddFieldsAndRefsShowsReferences(): void
    {
        $list = $this->desk('list', ['posts'], ['--where:format=serie', '--fields:format,km'])['json'];
        self::assertSame(['format' => 'serie', 'km' => 20], $list['records'][0]['fields']);

        $refs = $this->desk('refs', ['spots/kemnade'])['json'];
        self::assertSame([['type' => 'posts', 'slug' => 'a', 'title' => 'A', 'status' => 'draft', 'field' => 'spot']], $refs['refs']);
    }

    public function testUnknownFieldsAreErrors(): void
    {
        $result = $this->desk('list', ['posts'], ['--where:nope=1']);
        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('nope', $result['json']['error']);
    }

    public function testMediaExtraFieldsDefaultsUpdateAndFilter(): void
    {
        $file = $this->image('a.png');
        $added = $this->desk('media:add', [$file], ['--alt:Ein Bild'])['json'];
        self::assertTrue($added['extra']['socialOk']);

        $updated = $this->desk('media:update', [$added['file']], ['--extra:{"socialOk": false}', '--caption:Neu'])['json'];
        self::assertFalse($updated['extra']['socialOk']);
        self::assertSame('Neu', $updated['caption']);

        self::assertCount(0, $this->desk('media:list', [], ['--where:socialOk=true'])['json']['media']);
        self::assertCount(1, $this->desk('media:list', [], ['--where:socialOk=false'])['json']['media']);

        $refused = $this->desk('media:update', [$added['file']], ['--extra:{"nope": 1}']);
        self::assertSame(1, $refused['exit']);
    }

    public function testGeneratedMediaReplaceInPlaceStayHiddenAndArePruned(): void
    {
        $post = $this->context()->repository()->findBySlug('posts', 'a');
        $media = $this->context()->media();

        $first = $media->addGenerated($this->image('one.png', 10), ['alt' => 'Kachel'], ['type' => 'posts', 'id' => $post->id, 'generator' => 'social:feed:1']);
        $second = $media->addGenerated($this->image('two.png', 20), ['alt' => 'Kachel'], ['type' => 'posts', 'id' => $post->id, 'generator' => 'social:feed:1']);

        self::assertSame($first->id, $second->id);
        self::assertNotSame($first->file, $second->file);
        self::assertFileDoesNotExist($media->root() . '/' . $first->file);
        self::assertSame('generated', $second->origin);
        self::assertFalse($second->extra['socialOk']);
        self::assertCount(0, $this->desk('media:list')['json']['media']);
        self::assertCount(1, $this->desk('media:list', [], ['--origin:generated'])['json']['media']);

        // Same bytes as an upload: no duplicate either way.
        $upload = $this->desk('media:add', [$this->image('two-again.png', 20)])['json'];
        self::assertNotSame($second->id, $upload['id']);

        self::assertSame(0, $this->desk('media:prune', [], ['--generated'])['json']['count']);
        $this->desk('archive', ['posts/a']);
        $dry = $this->desk('media:prune', [], ['--generated', '--dry-run'])['json'];
        self::assertSame(1, $dry['count']);
        self::assertNotNull($media->get($second->id));
        self::assertSame(1, $this->desk('media:prune', [], ['--generated'])['json']['count']);
        self::assertNull($this->context()->media()->get($second->id));
    }

    /** @param string[] $options */
    private function slugs(array $options): array
    {
        $result = $this->desk('list', ['posts'], $options);
        self::assertSame(0, $result['exit'], $result['output']);

        return array_column($result['json']['records'], 'slug');
    }

    private function image(string $name, int $shade = 0): string
    {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, $shade, 100, 200));
        $file = $this->root . '/' . $name;
        imagepng($image, $file);

        return $file;
    }
}
