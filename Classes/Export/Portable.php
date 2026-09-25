<?php

namespace Neuedaten\FreezedDesk\Export;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * The stored data of a record in a form that survives a round trip through
 * git: relations as {type, slug} instead of ids, media as {file} instead of
 * ids. Reading it back needs no special code -- the relation and image
 * field types accept these shapes in normalize().
 */
final class Portable
{
    public function __construct(private readonly DeskContext $context)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function fromStored(TypeSchema $schema, array $data): array
    {
        return $this->convertFields($schema->fields, $data);
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function convertFields(array $fields, array $data): array
    {
        $result = [];
        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $data)) {
                continue;
            }
            $result[$name] = $this->convertValue($field, $data[$name]);
        }

        return $result;
    }

    private function convertValue(FieldDefinition $field, mixed $value): mixed
    {
        switch ($field->type) {
            case 'relation':
                $pairs = [];
                foreach (is_array($value) ? $value : [] as $pair) {
                    $item = isset($pair['id']) ? $this->context->repository()->get((int) $pair['id']) : null;
                    if ($item !== null) {
                        $pairs[] = ['type' => $item->type, 'slug' => $item->slug];
                    }
                }

                return $pairs;

            case 'image':
                $media = is_int($value) ? $this->context->media()->get($value) : null;

                return $media === null ? null : ['file' => $media->file];

            case 'images':
            case 'files':
                $files = [];
                foreach (is_array($value) ? $value : [] as $id) {
                    $media = is_int($id) ? $this->context->media()->get($id) : null;
                    if ($media !== null) {
                        $files[] = ['file' => $media->file];
                    }
                }

                return $files;

            case 'group':
                return $this->convertFields($field->fields ?? [], is_array($value) ? $value : []);

            case 'list':
                return array_values(array_map(fn (mixed $row): mixed => $this->convertValue($field->of, $row), is_array($value) ? $value : []));

            default:
                return $value;
        }
    }
}
