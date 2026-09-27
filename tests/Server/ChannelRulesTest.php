<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server;

use DeskOutbox\ChannelRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChannelRulesTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function lengths(): iterable
    {
        yield 'ascii' => ['Hallo', 'chars', 5];
        yield 'umlauts precomposed' => ['Grüße aus Essen', 'chars', 15];
        yield 'umlauts graphemes' => ['Grüße aus Essen', 'graphemes', 15];
        yield 'decomposed umlaut is two chars, one grapheme' => ["u\u{0308}", 'chars', 2];
        yield 'decomposed umlaut grapheme' => ["u\u{0308}", 'graphemes', 1];
        yield 'emoji with skin tone chars' => ['👍🏽', 'chars', 2];
        yield 'emoji with skin tone grapheme' => ['👍🏽', 'graphemes', 1];
        yield 'family emoji grapheme' => ['👨‍👩‍👧', 'graphemes', 1];
        yield 'flag grapheme' => ['🇩🇪', 'graphemes', 1];
    }

    #[DataProvider('lengths')]
    public function testLength(string $text, string $mode, int $expected): void
    {
        self::assertSame($expected, ChannelRules::length($text, $mode));
    }

    public function testHashtagsAndMentions(): void
    {
        $text = "Kaffee am #Baldeneysee #Ruhrgebiet2027 mit @perlen.bsky.social und @ruhr_cafe! #1 #ÄÖÜ foo#bar &#39; mail@example.org";
        self::assertSame(['Baldeneysee', 'Ruhrgebiet2027', 'ÄÖÜ'], ChannelRules::hashtags($text));
        self::assertSame(['perlen.bsky.social', 'ruhr_cafe'], ChannelRules::mentions($text));
    }

    public function testBlueskyCountsGraphemes(): void
    {
        $fits = str_repeat('👍🏽', 300);
        self::assertSame([], ChannelRules::check('bluesky', ['kind' => 'text', 'text' => $fits]));
        $problems = ChannelRules::check('bluesky', ['kind' => 'text', 'text' => $fits . 'ä']);
        self::assertSame(['channel.length'], array_column($problems, 'rule'));
    }

    public function testInstagramLimits(): void
    {
        $media = [['sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg']];
        self::assertSame([], ChannelRules::check('instagram', ['kind' => 'image', 'text' => str_repeat('ä', 2200), 'media' => $media]));

        $tags = implode(' ', array_map(static fn (int $i): string => '#tag' . $i, range(1, 31)));
        $problems = ChannelRules::check('instagram', ['kind' => 'image', 'text' => str_repeat('ä', 2201) . ' ' . $tags, 'media' => $media]);
        self::assertSame(['channel.length', 'channel.hashtags'], array_column($problems, 'rule'));

        self::assertSame(['channel.media'], array_column(ChannelRules::check('instagram', ['kind' => 'story', 'text' => 'x']), 'rule'));
        self::assertSame(['channel.kind'], array_column(ChannelRules::check('instagram', ['kind' => 'text', 'text' => 'x']), 'rule'));
        self::assertSame(['channel.carousel'], array_column(ChannelRules::check('instagram', ['kind' => 'carousel', 'text' => 'x', 'media' => $media]), 'rule'));
        self::assertSame(['channel.reel'], array_column(ChannelRules::check('instagram', ['kind' => 'reel', 'text' => 'x', 'media' => $media]), 'rule'));
        self::assertSame(['channel.mime'], array_column(ChannelRules::check('instagram', ['kind' => 'image', 'text' => 'x', 'media' => [['sha256' => 'b', 'mime' => 'image/png']]]), 'rule'));
        self::assertSame(['channel.size'], array_column(ChannelRules::check('instagram', ['kind' => 'image', 'text' => 'x', 'media' => $media], [str_repeat('a', 64) => ['size' => 9 * 1024 * 1024]]), 'rule'));
    }

    public function testBlueskyImagesAreScaledNotRefused(): void
    {
        $media = [['sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg']];
        self::assertSame([], ChannelRules::check('bluesky', ['kind' => 'image', 'text' => 'x', 'media' => $media], [str_repeat('a', 64) => ['size' => 5000000]]));
        $five = array_fill(0, 5, $media[0]);
        self::assertSame(['channel.media'], array_column(ChannelRules::check('bluesky', ['kind' => 'carousel', 'text' => 'x', 'media' => $five]), 'rule'));
    }

    public function testChannelsWithoutRules(): void
    {
        self::assertSame([], ChannelRules::check('whatsapp', ['kind' => 'text', 'text' => str_repeat('x', 100000)]));
        self::assertSame([], ChannelRules::for('mail'));
        self::assertContains('instagram', ChannelRules::channels());
    }
}
