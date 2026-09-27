<?php

namespace Neuedaten\FreezedDesk\Media;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\SchemaException;
use Neuedaten\FreezedDesk\Exception\ValidationException;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;

/**
 * The project's extra fields on media (desk.media.fields, A7), declared
 * like schema fields:
 *
 *     'media' => ['fields' => [
 *         'socialOk' => ['type' => 'bool', 'label' => 'Für Social Media freigegeben',
 *                        'default' => ['upload' => true, 'import' => false]],
 *     ]],
 *
 * Allowed types: bool, text, textarea, select, date. "default" may be one
 * value or one per origin (upload, import, generated).
 */
final class MediaFields
{
    public const TYPES = ['bool', 'text', 'textarea', 'select', 'date'];

    /** @var array<string, FieldDefinition>|null */
    private ?array $fields = null;

    public function __construct(private readonly DeskContext $context)
    {
    }

    /** @return array<string, FieldDefinition> */
    public function all(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }
        $declarations = $this->context->config->get('media.fields', []);
        if (!is_array($declarations)) {
            throw new SchemaException('desk.media.fields must be an array of field declarations.');
        }
        foreach ($declarations as $name => $declaration) {
            $type = is_array($declaration) ? ($declaration['type'] ?? null) : null;
            if (!in_array($type, self::TYPES, true)) {
                throw new SchemaException(sprintf('desk.media.fields: "%s" must have one of the types %s.', $name, implode(', ', self::TYPES)));
            }
            if (is_array($declaration['default'] ?? null) && $type !== 'select') {
                // The per-origin default is resolved here, not by the field type.
                unset($declarations[$name]['default']);
            }
        }

        return $this->fields = $this->context->schemas()->buildFields($declarations, 'desk.media.fields');
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * Initial values for a new file of the given origin.
     *
     * @return array<string, mixed>
     */
    public function defaults(string $origin): array
    {
        $values = [];
        $declarations = (array) $this->context->config->get('media.fields', []);
        foreach ($this->all() as $name => $field) {
            $default = $declarations[$name]['default'] ?? null;
            if (is_array($default) && $field->type !== 'select') {
                $default = $default[$origin] ?? null;
            }
            $values[$name] = $default === null
                ? $this->context->fieldTypes()->get($field->type)->defaultValue($field, $this->context)
                : $this->context->fieldTypes()->get($field->type)->normalize($default, $field, $this->context);
        }

        return $values;
    }

    /**
     * Normalise and validate given values, merged over the current ones.
     * Unknown names are refused.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function merge(array $current, array $given): array
    {
        $fields = $this->all();
        $errors = [];
        foreach ($given as $name => $value) {
            $field = $fields[(string) $name] ?? null;
            if ($field === null) {
                $errors[(string) $name] = sprintf('Unknown media field "%s"%s.', $name, $fields === [] ? ' (desk.media.fields declares none)' : ' (known: ' . implode(', ', array_keys($fields)) . ')');
                continue;
            }
            $type = $this->context->fieldTypes()->get($field->type);
            $normalised = $type->normalize($value, $field, $this->context);
            $problems = $type->validate($normalised, $field, $this->context);
            if ($problems !== []) {
                $errors[(string) $name] = implode(' ', $problems);
                continue;
            }
            $current[(string) $name] = $normalised;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $current;
    }
}
