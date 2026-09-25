<?php

namespace Neuedaten\FreezedDesk\Inbox;

/**
 * A form of the website, from desk/forms/<name>.php. See
 * server/api/lib/FormRules.php for the file format.
 */
final class FormDefinition
{
    /**
     * @param array<string, mixed> $declaration The file's array as written.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $declaration,
    ) {
    }

    public string $label {
        get => (string) ($this->declaration['label'] ?? ucfirst($this->name));
    }

    /** @return array<string, array<string, mixed>> */
    public function fields(): array
    {
        $fields = [];
        foreach ((array) ($this->declaration['fields'] ?? []) as $name => $field) {
            if (is_array($field)) {
                $fields[(string) $name] = $field;
            }
        }

        return $fields;
    }

    /** The field that names the record a submission is about, and its type. */
    public ?string $itemField {
        get => isset($this->declaration['item']['field']) ? (string) $this->declaration['item']['field'] : null;
    }

    public ?string $itemType {
        get => isset($this->declaration['item']['type']) ? (string) $this->declaration['item']['type'] : null;
    }

    public string $honeypot {
        get => (string) ($this->declaration['spam']['honeypot'] ?? 'website');
    }

    public int $minSeconds {
        get => (int) ($this->declaration['spam']['minSeconds'] ?? 3);
    }
}
