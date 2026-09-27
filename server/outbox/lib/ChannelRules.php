<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * The limits of each channel, in one dependency-free file (like
 * FormRules.php for the inbox): Desk checks a message with it before
 * pushing, an agent sees the problems in desk:social:check, and the server
 * checks again right before sending. Change a number here and every side
 * applies it.
 *
 * Checked against the platforms' documentation on 2026-09-26:
 * Instagram Content Publishing (Graph API), Facebook Pages API, Bluesky
 * (app.bsky.feed.post, app.bsky.embed.images). Channels without an entry
 * (mail, webhook, log, whatsapp via mail) have no limits.
 */
final class ChannelRules
{
    public const RULES = [
        'instagram' => [
            'label' => 'Instagram',
            'textMax' => 2200,
            'count' => 'chars',
            'hashtagsMax' => 30,
            'mentionsMax' => 20,
            'kinds' => ['image', 'carousel', 'reel', 'story'],
            'mediaMin' => 1,
            'mediaMax' => 10,
            'carouselMin' => 2,
            'mimes' => ['image/jpeg', 'video/mp4'],
            'imageBytesMax' => 8 * 1024 * 1024,
            'videoBytesMax' => 300 * 1024 * 1024,
            'linksClickable' => false,
        ],
        'facebook' => [
            'label' => 'Facebook',
            'textMax' => 63206,
            'count' => 'chars',
            'kinds' => ['image', 'carousel', 'reel', 'text', 'link'],
            'mediaMax' => 10,
            'mimes' => ['image/jpeg', 'image/png', 'video/mp4'],
            'imageBytesMax' => 10 * 1024 * 1024,
            'videoBytesMax' => 1024 * 1024 * 1024,
            'linksClickable' => true,
        ],
        'bluesky' => [
            'label' => 'Bluesky',
            'textMax' => 300,
            'count' => 'graphemes',
            'kinds' => ['text', 'link', 'image', 'carousel'],
            'mediaMax' => 4,
            'mimes' => ['image/jpeg', 'image/png'],
            // Blob limit of app.bsky.embed.images; the adapter scales down before uploading.
            'imageBytesMax' => 1000000,
            'imageBytesScaled' => true,
            'linksClickable' => true,
        ],
    ];

    /** @return array<string, mixed> The rules of a channel, [] for a channel without limits. */
    public static function for(string $channel): array
    {
        return self::RULES[$channel] ?? [];
    }

    /** @return string[] Channels that have limits. */
    public static function channels(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * Problems of a message on a channel. Each problem is
     * ['rule' => id, 'message' => text]; an empty list means the message fits.
     *
     * @param array<string, mixed> $payload The message payload (kind, text, media, link).
     * @param array<string, array{mime?: string, size?: int}> $assets sha256 => asset facts, where known.
     * @return array<int, array{rule: string, message: string}>
     */
    public static function check(string $channel, array $payload, array $assets = []): array
    {
        $rules = self::for($channel);
        if ($rules === []) {
            return [];
        }
        $label = (string) $rules['label'];
        $problems = [];
        $text = (string) ($payload['text'] ?? '');
        $kind = (string) ($payload['kind'] ?? 'text');
        $media = array_values(array_filter((array) ($payload['media'] ?? []), 'is_array'));

        if (isset($rules['kinds']) && !in_array($kind, $rules['kinds'], true)) {
            $problems[] = ['rule' => 'channel.kind', 'message' => sprintf('%s does not support posts of kind "%s" (%s).', $label, $kind, implode(', ', $rules['kinds']))];
        }

        $length = self::length($text, (string) ($rules['count'] ?? 'chars'));
        if (isset($rules['textMax']) && $length > $rules['textMax']) {
            $problems[] = ['rule' => 'channel.length', 'message' => sprintf('%s allows %d %s, the text has %d.', $label, $rules['textMax'], $rules['count'] === 'graphemes' ? 'characters (graphemes)' : 'characters', $length)];
        }

        if (isset($rules['hashtagsMax']) && count(self::hashtags($text)) > $rules['hashtagsMax']) {
            $problems[] = ['rule' => 'channel.hashtags', 'message' => sprintf('%s allows %d hashtags, the text has %d.', $label, $rules['hashtagsMax'], count(self::hashtags($text)))];
        }
        if (isset($rules['mentionsMax']) && count(self::mentions($text)) > $rules['mentionsMax']) {
            $problems[] = ['rule' => 'channel.mentions', 'message' => sprintf('%s allows %d mentions, the text has %d.', $label, $rules['mentionsMax'], count(self::mentions($text)))];
        }

        if (isset($rules['mediaMin']) && count($media) < $rules['mediaMin'] && $kind !== 'text' && $kind !== 'link') {
            $problems[] = ['rule' => 'channel.media', 'message' => sprintf('%s needs at least %d image or video.', $label, $rules['mediaMin'])];
        }
        if (isset($rules['mediaMax']) && count($media) > $rules['mediaMax']) {
            $problems[] = ['rule' => 'channel.media', 'message' => sprintf('%s allows %d images or videos per post, the message has %d.', $label, $rules['mediaMax'], count($media))];
        }
        if ($kind === 'carousel' && isset($rules['carouselMin']) && count($media) < $rules['carouselMin']) {
            $problems[] = ['rule' => 'channel.carousel', 'message' => sprintf('A %s carousel needs at least %d items.', $label, $rules['carouselMin'])];
        }
        if ($kind === 'reel' && !array_filter($media, static fn (array $m): bool => str_starts_with((string) ($m['mime'] ?? ''), 'video/'))) {
            $problems[] = ['rule' => 'channel.reel', 'message' => sprintf('A %s reel needs a video.', $label)];
        }

        foreach ($media as $position => $entry) {
            $sha = (string) ($entry['sha256'] ?? '');
            $mime = (string) ($entry['mime'] ?? ($assets[$sha]['mime'] ?? ''));
            $size = (int) ($assets[$sha]['size'] ?? 0);
            if (isset($rules['mimes']) && $mime !== '' && !in_array($mime, $rules['mimes'], true)) {
                $problems[] = ['rule' => 'channel.mime', 'message' => sprintf('%s does not take %s (item %d); allowed: %s.', $label, $mime, $position + 1, implode(', ', $rules['mimes']))];
            }
            $isVideo = str_starts_with($mime, 'video/');
            $max = $isVideo ? ($rules['videoBytesMax'] ?? null) : ($rules['imageBytesMax'] ?? null);
            if ($max !== null && $size > $max && !(!$isVideo && ($rules['imageBytesScaled'] ?? false))) {
                $problems[] = ['rule' => 'channel.size', 'message' => sprintf('%s allows %s per %s, item %d has %s.', $label, self::bytes($max), $isVideo ? 'video' : 'image', $position + 1, self::bytes($size))];
            }
        }

        return $problems;
    }

    /**
     * Length of a text as the channel counts it: characters (code points)
     * or graphemes (Bluesky: "ä" and "👍🏽" are one each).
     */
    public static function length(string $text, string $mode = 'chars'): int
    {
        if ($mode === 'graphemes') {
            if (function_exists('grapheme_strlen')) {
                $length = grapheme_strlen($text);
                if (is_int($length)) {
                    return $length;
                }
            }

            return (int) preg_match_all('/\X/u', $text);
        }

        return mb_strlen($text, 'UTF-8');
    }

    /** @return string[] Hashtags without "#", in order. */
    public static function hashtags(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}_&#])#([\p{L}\p{N}_]*\p{L}[\p{L}\p{N}_]*)/u', $text, $matches);

        return $matches[1];
    }

    /** @return string[] Mentions without "@", in order. */
    public static function mentions(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}_.@\/])@([A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_])?)/u', $text, $matches);

        return $matches[1];
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
