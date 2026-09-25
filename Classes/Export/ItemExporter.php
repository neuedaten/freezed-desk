<?php

namespace Neuedaten\FreezedDesk\Export;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Schema\ProvidesExtraVariables;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\Status;

/**
 * Turns a record into the variables a template sees: every non-internal
 * field through its field type, the standard variables of a record, and
 * whatever the type's "variables" callback adds.
 */
final class ItemExporter
{
    public function __construct(private readonly DeskContext $context)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(Item $item, ?TypeSchema $schema = null, bool $withCallback = true): array
    {
        $schema ??= $this->context->schemas()->get($item->type);
        $variables = $this->standardVariables($item, $schema);

        foreach ($schema->fields as $name => $field) {
            if ($field->internal) {
                continue;
            }
            $type = $this->context->fieldTypes()->get($field->type);
            $value = array_key_exists($name, $item->data) ? $item->data[$name] : $type->defaultValue($field, $this->context);
            $variables[$name] = $type->export($value, $field, $this->context);
            if ($type instanceof ProvidesExtraVariables) {
                $variables += $type->extraVariables($value, $field, $this->context);
            }
        }

        if ($withCallback && $schema->variablesCallback !== null) {
            $extra = ($schema->variablesCallback)($variables, $this->context->repository(), $item);
            if (!is_array($extra)) {
                throw new DeskException(sprintf(
                    'The "variables" callback of type "%s" must return an array, got %s (record %s).',
                    $schema->slug,
                    get_debug_type($extra),
                    $item->slug
                ));
            }
            $variables = array_replace($variables, $extra);
        }

        return $variables;
    }

    /**
     * @return array<string, mixed>
     */
    public function standardVariables(Item $item, TypeSchema $schema): array
    {
        $variant = $schema->variant($item->variant);

        return [
            'id' => $item->id,
            'slug' => $item->slug,
            'variant' => $item->variant,
            'variantLabel' => $variant['label'] ?? $item->variant,
            'status' => $item->status->value,
            'createdAt' => $item->createdAt,
            'updatedAt' => $item->updatedAt,
            'publishedAt' => $item->publishedAt,
            'lastmod' => substr($item->updatedAt, 0, 10),
        ];
    }

    /**
     * References for a list of {type, id} pairs: {id, type, slug, title,
     * variant, ref}. ref is CONTENT:<type>/<slug> for built types (for
     * freezed:link) and null for desk-only types. Pairs whose record is
     * missing or not published are left out, so a template never links to
     * a page that does not exist.
     *
     * @param array<int, array{type: string, id: int}> $pairs
     * @param string[] $exportFields Field names of the target to include (relations excluded).
     * @return array<int, array<string, mixed>>
     */
    public function refs(array $pairs, array $exportFields = [], bool $anyStatus = false): array
    {
        $refs = [];
        foreach ($pairs as $pair) {
            $id = $pair['id'] ?? null;
            if (!is_int($id)) {
                continue;
            }
            $item = $this->context->repository()->get($id);
            if ($item === null || (!$anyStatus && $item->status !== Status::Published)) {
                continue;
            }
            if (!$this->context->schemas()->has($item->type)) {
                continue;
            }
            $schema = $this->context->schemas()->get($item->type);

            $ref = [
                'id' => $item->id,
                'type' => $item->type,
                'slug' => $item->slug,
                'title' => $item->title,
                'variant' => $item->variant,
                'ref' => $schema->built ? 'CONTENT:' . $item->type . '/' . $item->slug : null,
            ];

            foreach ($exportFields as $name) {
                $field = $schema->field((string) $name);
                if ($field === null || $field->internal || $field->type === 'relation') {
                    continue;
                }
                $type = $this->context->fieldTypes()->get($field->type);
                $value = array_key_exists($field->name, $item->data) ? $item->data[$field->name] : $type->defaultValue($field, $this->context);
                $ref[$field->name] = $type->export($value, $field, $this->context);
                if ($type instanceof ProvidesExtraVariables) {
                    $ref += $type->extraVariables($value, $field, $this->context);
                }
            }

            $refs[] = $ref;
        }

        return $refs;
    }
}
