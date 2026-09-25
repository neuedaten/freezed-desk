<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\FieldTypes\FeaturesType;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * `freezed-desk schema [<type>]` — the schema as JSON: every type with its
 * fields, variants and settings; a features field carries its vocabulary,
 * a relation field its target types, so a caller knows what values fit.
 */
class SchemaCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $type = $args[0] ?? null;

        if ($type !== null) {
            self::printJson(self::describe($context, $context->schemas()->get($type)));

            return 0;
        }

        $all = [];
        foreach ($context->schemas()->all() as $schema) {
            $all[$schema->slug] = self::describe($context, $schema);
        }
        self::printJson([
            'types' => $all,
            'statuses' => ['draft', 'published', 'archived'],
            'standardFields' => ['id', 'slug', 'variant', 'status', 'sort', 'createdAt', 'updatedAt', 'publishedAt'],
        ]);

        return 0;
    }

    /** @return array<string, mixed> */
    public static function describe(DeskContext $context, TypeSchema $schema): array
    {
        $fields = [];
        foreach ($schema->fields as $name => $field) {
            $fields[$name] = self::describeField($context, $field);
        }

        return [
            'slug' => $schema->slug,
            'label' => $schema->label,
            'labelSingular' => $schema->labelSingular,
            'built' => $schema->built,
            'single' => $schema->single,
            'titleField' => $schema->titleField,
            'slugFrom' => $schema->slugFrom,
            'orderBy' => $schema->orderBy,
            'variants' => $schema->variants,
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private static function describeField(DeskContext $context, FieldDefinition $field): array
    {
        $described = $field->toArray();
        unset($described['name']);

        if ($field->type === 'features') {
            $type = $context->fieldTypes()->get('features');
            if ($type instanceof FeaturesType) {
                $described['vocabulary'] = $type->vocabulary($field, $context);
            }
        }
        if ($field->fields !== null) {
            $described['fields'] = [];
            foreach ($field->fields as $subName => $sub) {
                $described['fields'][$subName] = self::describeField($context, $sub);
            }
        }
        if ($field->of !== null) {
            $described['of'] = self::describeField($context, $field->of);
        }

        return $described;
    }
}
