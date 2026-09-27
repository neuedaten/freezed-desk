<?php

namespace Neuedaten\FreezedDesk\Schema;

/**
 * One field of a type, as declared in desk/types/<type>.php:
 *
 *     'title' => ['type' => 'text', 'label' => 'Name', 'required' => true],
 *
 * The raw declaration stays available through get(), so a field type can
 * read its own options (maxLength, step, to, of, …) without every option
 * needing a property here.
 */
final class FieldDefinition
{
    /**
     * @param array<string, mixed>                 $options The declaration as written, type and label included.
     * @param array<string, FieldDefinition>|null  $fields  Sub-fields of a "group".
     * @param FieldDefinition|null                 $of      Element definition of a "list".
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly array $options = [],
        public readonly ?array $fields = null,
        public readonly ?FieldDefinition $of = null,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public bool $required {
        get => (bool) ($this->options['required'] ?? false);
    }

    /** Never exported to templates (contacts, notes, agreements). */
    public bool $internal {
        get => (bool) ($this->options['internal'] ?? false);
    }

    public bool $readonly {
        get => (bool) ($this->options['readonly'] ?? false);
    }

    /**
     * Written by Desk or a package (rendered assets, outbox results), never
     * by the form or `put`. Read-only in the UI; a change to it alone does
     * not send an approved record back to draft (A3.4).
     */
    public bool $system {
        get => (bool) ($this->options['system'] ?? false);
    }

    public ?string $help {
        get => isset($this->options['help']) ? (string) $this->options['help'] : null;
    }

    /** Column width hint for the UI: "full", "half", "third" (default full). */
    public string $width {
        get => (string) ($this->options['width'] ?? 'full');
    }

    public bool $hasDefault {
        get => array_key_exists('default', $this->options);
    }

    public mixed $default {
        get => $this->options['default'] ?? null;
    }

    /** @return array<string, mixed> For desk:show and the schema endpoint. */
    public function toArray(): array
    {
        $array = ['name' => $this->name] + $this->options;
        $array['type'] = $this->type;
        $array['label'] = $this->label;

        if ($this->fields !== null) {
            $array['fields'] = array_map(static fn (FieldDefinition $f): array => $f->toArray(), $this->fields);
        }
        if ($this->of !== null) {
            $array['of'] = $this->of->toArray();
        }

        return $array;
    }
}
