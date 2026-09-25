<?php

namespace Neuedaten\FreezedDesk\Schema;

/**
 * Turns a title into a slug the way a folder name in content/ would be
 * written: lowercase ASCII, words joined with "-", umlauts spelled out.
 */
final class Slugger
{
    private const REPLACEMENTS = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ẞ' => 'ss',
        '&' => ' und ', '@' => ' at ',
    ];

    public static function slugify(string $text): string
    {
        $text = strtr($text, self::REPLACEMENTS);

        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                $text = $transliterator->transliterate($text) ?: $text;
            }
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }

    /**
     * True for a slug that the core accepts as an item name: it may contain
     * "/" for nested output paths, but no ".." segment and no empty segment.
     */
    public static function isValid(string $slug): bool
    {
        if ($slug === '' || str_contains($slug, '//') || str_starts_with($slug, '/') || str_ends_with($slug, '/')) {
            return false;
        }

        foreach (explode('/', $slug) as $segment) {
            if ($segment === '..' || $segment === '.' || !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $segment)) {
                return false;
            }
        }

        return true;
    }
}
