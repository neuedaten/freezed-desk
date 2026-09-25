<?php

namespace Neuedaten\FreezedDesk\Schema;

use Neuedaten\FreezedDesk\Exception\SchemaException;
use Neuedaten\FreezedDesk\Schema\FieldTypes;

/**
 * The field types Desk knows. A project may register more through
 * 'desk' => ['fieldTypes' => [MyType::class]] -- each a FieldTypeInterface
 * with a matching partial Field/<Name>.html in desk/themes.
 */
final class FieldTypeRegistry
{
    /** @var array<string, FieldTypeInterface> */
    private array $types = [];

    public function __construct()
    {
        foreach ([
            new FieldTypes\TextType(),
            new FieldTypes\TextareaType(),
            new FieldTypes\MarkdownType(),
            new FieldTypes\BoolType(),
            new FieldTypes\NumberType(),
            new FieldTypes\DateType(),
            new FieldTypes\DateTimeType(),
            new FieldTypes\SelectType(),
            new FieldTypes\LinkType(),
            new FieldTypes\ImageType(),
            new FieldTypes\ImagesType(),
            new FieldTypes\FilesType(),
            new FieldTypes\RelationType(),
            new FieldTypes\FeaturesType(),
            new FieldTypes\HoursType(),
            new FieldTypes\GeoType(),
            new FieldTypes\ListType(),
            new FieldTypes\GroupType(),
            new FieldTypes\JsonType(),
        ] as $type) {
            $this->register($type);
        }
    }

    public function register(FieldTypeInterface $type): void
    {
        $this->types[$type->name()] = $type;
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    public function get(string $name): FieldTypeInterface
    {
        return $this->types[$name] ?? throw new SchemaException('Unknown field type "' . $name . '". Known types: ' . implode(', ', array_keys($this->types)) . '.');
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->types);
    }
}
