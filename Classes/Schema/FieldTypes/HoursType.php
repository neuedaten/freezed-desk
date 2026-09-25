<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\AbstractFieldType;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * Opening hours: up to several time ranges per weekday plus a free note
 * ("Feiertage geschlossen"). Stored as
 *
 *     {days: {mon: [{from: "11:00", to: "22:00"}], tue: [], …}, note: "…"}
 *
 * and exported as a list of days -- {key, label, ranges, closed} -- plus the
 * note, so a template can print a table without any logic. No "open now":
 * the site is static.
 */
class HoursType extends AbstractFieldType
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const LABELS = [
        'de' => ['mon' => 'Montag', 'tue' => 'Dienstag', 'wed' => 'Mittwoch', 'thu' => 'Donnerstag', 'fri' => 'Freitag', 'sat' => 'Samstag', 'sun' => 'Sonntag'],
        'en' => ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'],
    ];

    public function name(): string
    {
        return 'hours';
    }

    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed
    {
        if (!is_array($input)) {
            return null;
        }
        $rawDays = $input['days'] ?? $input;
        $days = [];
        $hasRange = false;

        foreach (self::DAYS as $day) {
            $days[$day] = [];
            $ranges = $rawDays[$day] ?? [];
            if (is_string($ranges)) {
                $ranges = $this->parseRanges($ranges);
            }
            foreach (is_array($ranges) ? $ranges : [] as $range) {
                if (is_string($range)) {
                    $range = $this->parseRanges($range)[0] ?? null;
                }
                if (!is_array($range)) {
                    continue;
                }
                $from = $this->time($range['from'] ?? $range[0] ?? null);
                $to = $this->time($range['to'] ?? $range[1] ?? null);
                if ($from === null && $to === null) {
                    continue;
                }
                $days[$day][] = ['from' => $from ?? '', 'to' => $to ?? ''];
                $hasRange = true;
            }
        }

        $note = self::stringOrNull($input['note'] ?? null) ?? '';

        if (!$hasRange && $note === '') {
            return null;
        }

        return ['days' => $days, 'note' => $note];
    }

    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array
    {
        if ($value === null) {
            return [];
        }
        foreach ($value['days'] ?? [] as $day => $ranges) {
            foreach ($ranges as $range) {
                if (!preg_match('/^\d{2}:\d{2}$/', $range['from']) || !preg_match('/^\d{2}:\d{2}$/', $range['to'])) {
                    return [$context->t('validation.time', ['day' => $this->labels($field, $context)[$day] ?? $day])];
                }
            }
        }

        return [];
    }

    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed
    {
        if (!is_array($value)) {
            return null;
        }
        $labels = $this->labels($field, $context);
        $days = [];
        foreach (self::DAYS as $day) {
            $ranges = $value['days'][$day] ?? [];
            $days[] = [
                'key' => $day,
                'label' => $labels[$day],
                'ranges' => array_values($ranges),
                'closed' => $ranges === [],
            ];
        }

        return ['days' => $days, 'note' => (string) ($value['note'] ?? '')];
    }

    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string
    {
        return is_array($value) ? (string) ($value['note'] ?? '') : '';
    }

    /** @return array<string, string> */
    private function labels(FieldDefinition $field, DeskContext $context): array
    {
        $custom = $field->get('dayLabels');
        if (is_array($custom)) {
            return array_replace(self::LABELS['en'], $custom);
        }

        return self::LABELS[$context->translator()->locale] ?? self::LABELS['en'];
    }

    private function time(mixed $input): ?string
    {
        $value = self::stringOrNull($input);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $value, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $value;
    }

    /** "11:00-14:00, 17:00-22:00" → ranges */
    private function parseRanges(string $text): array
    {
        $ranges = [];
        foreach (preg_split('/\s*[,;]\s*/', trim($text)) ?: [] as $part) {
            if (preg_match('/^(\d{1,2}[:.]\d{2})\s*[-–]\s*(\d{1,2}[:.]\d{2})$/', $part, $m)) {
                $ranges[] = ['from' => $m[1], 'to' => $m[2]];
            }
        }

        return $ranges;
    }
}
