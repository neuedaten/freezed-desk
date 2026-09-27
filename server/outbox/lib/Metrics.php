<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * The names of the numbers an adapter may return from metrics() (C9). One
 * vocabulary for every platform, so Desk can sum them across channels;
 * whatever a platform does not give is left out.
 */
final class Metrics
{
    public const KEYS = ['reach', 'impressions', 'interactions', 'likes', 'comments', 'shares', 'saves', 'linkClicks', 'views', 'quotes'];

    /**
     * Keep the known keys with numeric values.
     *
     * @param array<mixed> $values
     * @return array<string, int|float>
     */
    public static function clean(array $values): array
    {
        $clean = [];
        foreach (self::KEYS as $key) {
            if (isset($values[$key]) && is_numeric($values[$key])) {
                $clean[$key] = $values[$key] + 0;
            }
        }

        return $clean;
    }
}
