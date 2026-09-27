<?php

namespace Neuedaten\FreezedDesk;

/**
 * UI strings, from lang/<locale>.php. Missing keys fall back to English and
 * then to the key itself, so a typo never breaks a page.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $strings;

    /** @var array<string, string> */
    private array $fallback;

    public function __construct(public readonly string $locale = 'de')
    {
        $this->strings = self::load($locale);
        $this->fallback = $locale === 'en' ? $this->strings : self::load('en');
    }

    /**
     * Add the strings of an extension; keys Desk already has stay Desk's.
     *
     * @param array<string, string> $strings
     * @param array<string, string> $fallback English strings of the extension.
     */
    public function add(array $strings, array $fallback = []): void
    {
        $this->strings += $strings;
        $this->fallback += $fallback;
    }

    /**
     * @param array<string, scalar|null> $params Replaces {name} placeholders.
     */
    public function t(string $key, array $params = []): string
    {
        $text = $this->strings[$key] ?? $this->fallback[$key] ?? $key;

        if ($params === []) {
            return $text;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    /** @return array<string, string> */
    private static function load(string $locale): array
    {
        $file = __DIR__ . '/../lang/' . preg_replace('/[^a-z]/', '', strtolower($locale)) . '.php';
        if (!is_file($file)) {
            return [];
        }
        $strings = include $file;

        return is_array($strings) ? $strings : [];
    }
}
