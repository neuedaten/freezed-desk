<?php

namespace Neuedaten\FreezedDesk\Storage;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * Field-by-field differences between two states of a record, for the
 * revisions view, `revision --diff` and the conflict view of the form.
 */
final class Diff
{
    public function __construct(private readonly DeskContext $context)
    {
    }

    /**
     * @param array<string, mixed> $then A snapshot (slug, variant, status, data).
     * @param array<string, mixed> $now  Another snapshot.
     * @return array<int, array{field: string, label: string, then: string, now: string}>
     */
    public function between(TypeSchema $schema, array $then, array $now): array
    {
        $rows = [];
        foreach (['slug' => 'ui.slug', 'variant' => 'ui.variant', 'status' => 'ui.status'] as $key => $label) {
            if (($then[$key] ?? null) !== ($now[$key] ?? null)) {
                $rows[] = ['field' => $key, 'label' => $this->context->t($label), 'then' => (string) ($then[$key] ?? ''), 'now' => (string) ($now[$key] ?? '')];
            }
        }
        $thenData = is_array($then['data'] ?? null) ? $then['data'] : [];
        $nowData = is_array($now['data'] ?? null) ? $now['data'] : [];
        foreach ($schema->fields as $name => $field) {
            $a = $thenData[$name] ?? null;
            $b = $nowData[$name] ?? null;
            if ($a == $b) {
                continue;
            }
            $rows[] = ['field' => $name, 'label' => $field->label, 'then' => self::pretty($a), 'now' => self::pretty($b)];
        }

        return $rows;
    }

    public static function pretty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
