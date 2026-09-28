<?php

namespace Neuedaten\FreezedDesk\Review;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\FieldTypes\FeaturesType;
use Neuedaten\FreezedDesk\Schema\FieldTypes\HoursType;
use Neuedaten\FreezedDesk\Schema\FieldTypes\SelectType;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * A record as the review screen shows it: every field read-only, one
 * block per top-level field, as HTML. Every value is escaped here; links
 * in texts, Markdown and link fields open in a new window.
 */
final class ReviewPresenter
{
    /** Web addresses in plain text: http(s)://… and www.… */
    private const URL_PATTERN = '~\b(?:https?://|www\.)[^\s<>"\']+~iu';

    public function __construct(private readonly DeskContext $context)
    {
    }

    /**
     * @return array{fields: array<int, array{name: string, label: string, type: string, internal: bool, system: bool, html: string}>, empty: string[]}
     *         The fields with a value, and the labels of the empty ones.
     */
    public function present(TypeSchema $schema, Item $item): array
    {
        $fields = [];
        $empty = [];
        foreach ($schema->fields as $name => $field) {
            $value = $item->data[$name] ?? null;
            if ($this->isEmpty($field, $value)) {
                $empty[] = $field->label;
                continue;
            }
            $fields[] = [
                'name' => $name,
                'label' => $field->label,
                'type' => $field->type,
                'internal' => $field->internal,
                'system' => $field->system,
                'html' => $this->value($field, $value),
            ];
        }

        return ['fields' => $fields, 'empty' => $empty];
    }

    /**
     * Every web address in any field of the record, internal ones included,
     * in field order and each once, with the fields it appears in.
     *
     * @return array<int, array{href: string, text: string, fields: string[]}>
     */
    public function urls(TypeSchema $schema, Item $item): array
    {
        $urls = [];
        foreach ($schema->fields as $name => $field) {
            $values = [$item->data[$name] ?? null];
            array_walk_recursive($values, function (mixed $value) use (&$urls, $field): void {
                if (!is_string($value) || $value === '') {
                    return;
                }
                foreach (self::findUrls($value) as [$url]) {
                    $href = stripos($url, 'www.') === 0 ? 'https://' . $url : $url;
                    $urls[$href] ??= ['href' => $href, 'text' => $url, 'fields' => []];
                    if (!in_array($field->label, $urls[$href]['fields'], true)) {
                        $urls[$href]['fields'][] = $field->label;
                    }
                }
            });
        }

        return array_values($urls);
    }

    private function isEmpty(FieldDefinition $field, mixed $value): bool
    {
        if ($this->context->fieldTypes()->get($field->type)->isEmpty($value)) {
            return true;
        }
        if ($field->type === 'group' && is_array($value) && $field->fields !== null) {
            foreach ($field->fields as $name => $sub) {
                if (!$this->isEmpty($sub, $value[$name] ?? null)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function value(FieldDefinition $field, mixed $value): string
    {
        switch ($field->type) {
            case 'text':
                return '<p>' . self::linkify((string) $value) . '</p>';
            case 'textarea':
                return '<p>' . nl2br(self::linkify((string) $value), false) . '</p>';
            case 'markdown':
                return '<div class="review-markdown">' . self::newWindow($this->context->markdown()->toHtml((string) $value)) . '</div>';
            case 'bool':
                return '<p>' . self::e($this->context->t($value ? 'ui.yes' : 'ui.no')) . '</p>';
            case 'number':
                return '<p>' . self::e((string) $value . ($field->get('unit') ? ' ' . $field->get('unit') : '')) . '</p>';
            case 'date':
                return '<p>' . self::e(self::date((string) $value, 'd.m.Y')) . '</p>';
            case 'datetime':
                return '<p>' . self::e(self::date((string) $value, 'd.m.Y H:i')) . '</p>';
            case 'select':
                return '<p>' . self::e($this->selectLabels($field, $value)) . '</p>';
            case 'link':
                return '<p>' . $this->link(is_array($value) ? $value : ['href' => (string) $value]) . '</p>';
            case 'relation':
                return $this->relations($value);
            case 'image':
            case 'images':
            case 'files':
                return $this->media($value);
            case 'geo':
                return $this->geo(is_array($value) ? $value : []);
            case 'hours':
                return $this->hours($field, $value);
            case 'features':
                return $this->features($field, $value);
            case 'group':
                return $this->group($field, is_array($value) ? $value : []);
            case 'list':
                return $this->listValue($field, is_array($value) ? $value : []);
            case 'json':
                return '<details class="review-json"><summary>JSON</summary><pre>' . self::e((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
            default:
                return is_scalar($value)
                    ? '<p>' . self::linkify((string) $value) . '</p>'
                    : '<pre>' . self::e((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }
    }

    private function selectLabels(FieldDefinition $field, mixed $value): string
    {
        $type = $this->context->fieldTypes()->get('select');
        $labels = $type instanceof SelectType ? $type->options($field) : [];
        $keys = is_array($value) ? $value : [$value];

        return implode(', ', array_map(static fn ($key): string => $labels[(string) $key] ?? (string) $key, $keys));
    }

    /** @param array<string, mixed> $value {href, label, target} */
    private function link(array $value): string
    {
        $href = trim((string) ($value['href'] ?? ''));
        $label = trim((string) ($value['label'] ?? ''));
        if (!self::isWebAddress($href)) {
            return self::e($label !== '' ? $label . ' (' . $href . ')' : $href);
        }

        return self::anchor($href, $label !== '' ? $label : $href) . ($label !== '' ? ' <span class="muted">' . self::e($href) . '</span>' : '');
    }

    private function relations(mixed $value): string
    {
        $parts = [];
        foreach ((array) $value as $pair) {
            $target = is_array($pair) && isset($pair['id']) ? $this->context->repository()->get((int) $pair['id']) : null;
            if ($target === null) {
                continue;
            }
            $parts[] = '<a class="chip chip--link" href="/types/' . self::e($target->type) . '/' . $target->id . '" target="_blank" rel="noopener">' . self::e($target->title) . '</a>';
        }

        return '<div class="chips">' . implode(' ', $parts) . '</div>';
    }

    private function media(mixed $value): string
    {
        $ids = [];
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (is_int($entry)) {
                $ids[] = $entry;
            } elseif (is_array($entry) && isset($entry['image']) && is_int($entry['image'])) {
                $ids[] = $entry['image'];
            }
        }
        $cards = [];
        foreach ($ids as $id) {
            $media = $this->context->media()->get($id);
            if ($media === null) {
                continue;
            }
            $meta = array_filter([$media->alt, $media->credit !== '' ? '© ' . $media->credit : '']);
            $cards[] = '<a class="review-media" href="/media/' . $media->id . '" target="_blank" rel="noopener">'
                . ($media->isImage() || $media->isVideo()
                    ? '<img src="/media/thumb/' . $media->id . '" alt="' . self::e($media->alt) . '" loading="lazy">'
                    : '<span class="media-card__file">' . self::e(basename($media->file)) . '</span>')
                . ($meta !== [] ? '<span class="review-media__meta">' . self::e(implode(' · ', $meta)) . '</span>' : '')
                . '</a>';
        }

        return '<div class="review-media-grid">' . implode('', $cards) . '</div>';
    }

    /** @param array<string, mixed> $value */
    private function geo(array $value): string
    {
        $lat = $value['lat'] ?? null;
        $lon = $value['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return '';
        }
        $zoom = is_numeric($value['zoom'] ?? null) ? (int) $value['zoom'] : 16;
        $url = sprintf('https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=%3$d/%1$s/%2$s', $lat, $lon, $zoom);

        return '<p>' . self::e($lat . ', ' . $lon) . ' · ' . self::anchor($url, 'OpenStreetMap') . '</p>';
    }

    private function hours(FieldDefinition $field, mixed $value): string
    {
        $type = $this->context->fieldTypes()->get('hours');
        $export = $type instanceof HoursType ? $type->export($value, $field, $this->context) : null;
        if (!is_array($export)) {
            return '';
        }
        $rows = [];
        foreach ($export['days'] as $day) {
            $ranges = array_map(static fn (array $r): string => $r['from'] . '–' . $r['to'], $day['ranges']);
            $rows[] = '<dt>' . self::e($day['label']) . '</dt><dd>' . self::e($day['closed'] ? $this->context->t('ui.hours.closed') : implode(', ', $ranges)) . '</dd>';
        }
        $note = $export['note'] !== '' ? '<p>' . self::linkify($export['note']) . '</p>' : '';

        return '<dl class="dl dl--small">' . implode('', $rows) . '</dl>' . $note;
    }

    private function features(FieldDefinition $field, mixed $value): string
    {
        $type = $this->context->fieldTypes()->get('features');
        $export = $type instanceof FeaturesType ? $type->export($value, $field, $this->context) : [];
        $items = [];
        foreach ($export as $feature) {
            $shown = $feature['value'] === true ? '' : ': ' . (is_scalar($feature['value']) ? (string) $feature['value'] : '');
            $items[] = '<li>' . self::e($feature['label'] . $shown) . '</li>';
        }

        return '<ul class="review-list">' . implode('', $items) . '</ul>';
    }

    /** @param array<string, mixed> $value */
    private function group(FieldDefinition $field, array $value): string
    {
        $rows = [];
        foreach ($field->fields ?? [] as $name => $sub) {
            $subValue = $value[$name] ?? null;
            if ($this->isEmpty($sub, $subValue)) {
                continue;
            }
            $rows[] = '<dt>' . self::e($sub->label) . '</dt><dd>' . $this->value($sub, $subValue) . '</dd>';
        }

        return '<dl class="dl review-dl">' . implode('', $rows) . '</dl>';
    }

    /** @param array<int, mixed> $value */
    private function listValue(FieldDefinition $field, array $value): string
    {
        $items = [];
        foreach ($value as $entry) {
            if ($field->of === null || $this->isEmpty($field->of, $entry)) {
                continue;
            }
            $items[] = '<li>' . $this->value($field->of, $entry) . '</li>';
        }

        return '<ul class="review-list">' . implode('', $items) . '</ul>';
    }

    // -------------------------------------------------------- helpers ---

    /** Escape a text and turn the web addresses in it into links. */
    public static function linkify(string $text): string
    {
        $html = '';
        $offset = 0;
        foreach (self::findUrls($text) as [$url, $position]) {
            $html .= self::e(substr($text, $offset, $position - $offset));
            $html .= self::anchor(stripos($url, 'www.') === 0 ? 'https://' . $url : $url, $url);
            $offset = $position + strlen($url);
        }

        return $html . self::e(substr($text, $offset));
    }

    /**
     * Web addresses in a text with their byte offset. Punctuation after an
     * address belongs to the sentence, and so does a closing bracket the
     * address did not open.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function findUrls(string $text): array
    {
        if (!preg_match_all(self::URL_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $urls = [];
        foreach ($matches[0] as [$url, $position]) {
            $url = rtrim($url, '.,;:!?');
            while (preg_match('/[)\]]$/', $url) && substr_count($url, '(') + substr_count($url, '[') < substr_count($url, ')') + substr_count($url, ']')) {
                $url = rtrim(substr($url, 0, -1), '.,;:!?');
            }
            $urls[] = [$url, $position];
        }

        return $urls;
    }

    /** Links in converted Markdown open in a new window. */
    public static function newWindow(string $html): string
    {
        return (string) preg_replace('/<a (?![^>]*\btarget=)/i', '<a target="_blank" rel="noopener noreferrer" ', $html);
    }

    private static function anchor(string $href, string $label): string
    {
        return '<a href="' . self::e($href) . '" target="_blank" rel="noopener noreferrer">' . self::e($label) . '</a>';
    }

    private static function isWebAddress(string $href): bool
    {
        return (bool) preg_match('~^(https?://|mailto:|tel:)~i', $href);
    }

    private static function date(string $value, string $format): string
    {
        try {
            return (new \DateTimeImmutable($value))->format($format);
        } catch (\Exception) {
            return $value;
        }
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
