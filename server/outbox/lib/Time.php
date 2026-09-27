<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * One timestamp format for the whole outbox (B3.10): UTC, ISO 8601, second
 * precision, "Z" suffix. A single format keeps string comparisons in SQLite
 * correct (at_utc <= :now), so every stored time goes through format().
 */
final class Time
{
    public const FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function format(\DateTimeInterface $time): string
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /**
     * An ISO 8601 date-time with an explicit zone ("Z" or "+02:00"), as Desk
     * sends it. A time without a zone is refused: the server cannot know
     * which local time was meant.
     */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})$/i', trim($value))) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
        // Reject overflowing dates such as 2027-02-31, which PHP silently rolls over.
        if ($time->format('Y-m-d') !== substr(trim($value), 0, 10)) {
            return null;
        }

        return $time->setTimezone(new \DateTimeZone('UTC'));
    }

    /** A date or date-time for query filters (?since=2027-05-01 or a full timestamp). */
    public static function parseLoose(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return self::parse(trim($value) . 'T00:00:00Z');
        }

        return self::parse($value);
    }
}
