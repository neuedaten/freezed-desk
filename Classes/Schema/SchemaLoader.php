<?php

namespace Neuedaten\FreezedDesk\Schema;

use Neuedaten\FreezedDesk\Config\DeskConfig;
use Neuedaten\FreezedDesk\Exception\SchemaException;

/**
 * Reads desk/types/<type>.php into TypeSchema objects, resolves 'use'
 * references to desk/fields/<name>.php and validates the declarations.
 *
 * Everything is checked once per process, so a broken schema is reported
 * before a record is touched: unknown field types, reserved names, relation
 * targets that do not exist, variants without a template name.
 */
final class SchemaLoader
{
    private const TYPE_SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    private const FIELD_NAME_PATTERN = '/^[a-z][A-Za-z0-9_]*$/';

    private const TEMPLATE_PATTERN = '#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#';

    /** @var array<string, TypeSchema>|null */
    private ?array $schemas = null;

    /** @var array<string, array<string, mixed>> Cache of desk/fields/<name>.php */
    private array $fieldGroups = [];

    /**
     * @param string[] $builtTypes Slugs that have a contentTypes entry in freezed.config.php.
     */
    public function __construct(
        private readonly DeskConfig $config,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly array $builtTypes = [],
    ) {
    }

    public function fieldTypes(): FieldTypeRegistry
    {
        return $this->fieldTypes;
    }

    /**
     * @return array<string, TypeSchema> Keyed by slug, in file name order.
     */
    public function all(): array
    {
        if ($this->schemas !== null) {
            return $this->schemas;
        }

        $directory = $this->config->typesPath();
        $schemas = [];

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $slug = basename($file, '.php');
            if (!preg_match(self::TYPE_SLUG_PATTERN, $slug)) {
                throw new SchemaException(sprintf('Type file "%s": "%s" is not a valid type slug (lowercase letters, digits, "-" and "_").', $file, $slug));
            }
            $schemas[$slug] = $this->loadFile($slug, $file);
        }

        $this->schemas = $schemas;
        $this->validateAll();

        return $schemas;
    }

    public function has(string $slug): bool
    {
        return isset($this->all()[$slug]);
    }

    public function get(string $slug): TypeSchema
    {
        return $this->all()[$slug] ?? throw new SchemaException(sprintf(
            'Unknown type "%s": there is no %s/%s.php.',
            $slug,
            $this->relativeTypesPath(),
            $slug
        ));
    }

    /** @return array<string, TypeSchema> Types that are built by the core. */
    public function built(): array
    {
        return array_filter($this->all(), static fn (TypeSchema $s): bool => $s->built);
    }

    /** @return array<string, TypeSchema> Types that are only stored, never rendered. */
    public function deskOnly(): array
    {
        return array_filter($this->all(), static fn (TypeSchema $s): bool => !$s->built);
    }

    /**
     * Build a field definition from a declaration outside a type file, e.g.
     * for form definitions of the inbox module that reuse the field types.
     *
     * @param array<string, mixed> $declaration
     */
    public function buildField(string $name, array $declaration, string $context): FieldDefinition
    {
        if (isset($declaration['use'])) {
            $declaration = $this->resolveUse((string) $declaration['use'], $declaration, $context);
        }

        $type = $declaration['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new SchemaException(sprintf('%s: field "%s" has no "type".', $context, $name));
        }
        if (!$this->fieldTypes->has($type)) {
            throw new SchemaException(sprintf(
                '%s: field "%s" has the unknown type "%s". Known types: %s.',
                $context,
                $name,
                $type,
                implode(', ', $this->fieldTypes->names())
            ));
        }

        $label = isset($declaration['label']) && is_string($declaration['label']) && $declaration['label'] !== ''
            ? $declaration['label']
            : ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $name) ?? $name);

        $fields = null;
        $of = null;

        if ($type === 'group') {
            $sub = $declaration['fields'] ?? null;
            if (!is_array($sub) || $sub === []) {
                throw new SchemaException(sprintf('%s: group field "%s" needs a non-empty "fields" array.', $context, $name));
            }
            $fields = $this->buildFields($sub, $context . ' > ' . $name);
        }

        if ($type === 'list') {
            $element = $declaration['of'] ?? null;
            if (!is_array($element)) {
                throw new SchemaException(sprintf('%s: list field "%s" needs an "of" declaration, e.g. \'of\' => [\'type\' => \'text\'].', $context, $name));
            }
            $of = $this->buildField($name . '[]', $element, $context . ' > ' . $name);
        }

        return new FieldDefinition($name, $type, $label, $declaration, $fields, $of);
    }

    /**
     * @param array<string, mixed> $declarations
     * @return array<string, FieldDefinition>
     */
    public function buildFields(array $declarations, string $context): array
    {
        $fields = [];
        foreach ($declarations as $name => $declaration) {
            $name = (string) $name;
            if (!preg_match(self::FIELD_NAME_PATTERN, $name)) {
                throw new SchemaException(sprintf('%s: "%s" is not a valid field name (camelCase, starting with a lowercase letter).', $context, $name));
            }
            if (!is_array($declaration)) {
                throw new SchemaException(sprintf('%s: field "%s" must be declared as an array.', $context, $name));
            }
            $fields[$name] = $this->buildField($name, $declaration, $context);
        }

        return $fields;
    }

    private function loadFile(string $slug, string $file): TypeSchema
    {
        $context = $this->relativeTypesPath() . '/' . $slug . '.php';

        $declaration = (static fn (string $__file): mixed => include $__file)($file);
        if (!is_array($declaration)) {
            throw new SchemaException($context . ' must return an array.');
        }

        $rawFields = $declaration['fields'] ?? null;
        if (!is_array($rawFields) || $rawFields === []) {
            throw new SchemaException($context . ': "fields" must be a non-empty array.');
        }

        foreach (array_keys($rawFields) as $name) {
            if (in_array((string) $name, TypeSchema::RESERVED_FIELD_NAMES, true)) {
                throw new SchemaException(sprintf(
                    '%s: "%s" is a reserved name (every record has it already) and cannot be a field.',
                    $context,
                    $name
                ));
            }
        }

        $fields = $this->buildFields($rawFields, $context);

        $label = (string) ($declaration['label'] ?? ucfirst($slug));
        $labelSingular = (string) ($declaration['labelSingular'] ?? $label);
        $single = (bool) ($declaration['single'] ?? false);

        $titleField = $declaration['titleField'] ?? (isset($fields['title']) ? 'title' : null);
        if ($titleField !== null && !isset($fields[$titleField])) {
            throw new SchemaException(sprintf('%s: titleField "%s" is not a declared field.', $context, $titleField));
        }
        if ($titleField === null && !$single) {
            throw new SchemaException($context . ': declare a "titleField" or a field named "title", so records have a name in lists.');
        }

        $slugFrom = $declaration['slugFrom'] ?? $titleField;
        if ($slugFrom !== null && !isset($fields[$slugFrom])) {
            throw new SchemaException(sprintf('%s: slugFrom "%s" is not a declared field.', $context, $slugFrom));
        }

        $orderBy = $this->normaliseOrderBy($declaration['orderBy'] ?? null, $fields, $context);

        $listColumns = $declaration['listColumns'] ?? null;
        if ($listColumns === null) {
            $listColumns = array_values(array_filter([$titleField, 'status']));
        }
        if (!is_array($listColumns)) {
            throw new SchemaException($context . ': "listColumns" must be an array of field names.');
        }
        foreach ($listColumns as $column) {
            if (!is_string($column) || (!isset($fields[$column]) && !in_array($column, ['slug', 'variant', 'status', 'updatedAt', 'publishedAt', 'createdAt', 'sort'], true))) {
                throw new SchemaException(sprintf('%s: listColumns names the unknown column "%s".', $context, is_string($column) ? $column : get_debug_type($column)));
            }
        }

        $variants = $this->normaliseVariants($declaration['variants'] ?? null, $context);

        $callback = $declaration['variables'] ?? null;
        if ($callback !== null && !is_callable($callback)) {
            throw new SchemaException($context . ': "variables" must be a callable (function (array $item, Repository $repo): array).');
        }

        return new TypeSchema(
            slug: $slug,
            label: $label,
            labelSingular: $labelSingular,
            fields: $fields,
            variants: $variants,
            titleField: $titleField,
            slugFrom: $slugFrom,
            orderBy: $orderBy,
            listColumns: array_values($listColumns),
            single: $single,
            built: in_array($slug, $this->builtTypes, true),
            variablesCallback: $callback === null ? null : \Closure::fromCallable($callback),
            file: $file,
        );
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @return array<string, string>
     */
    private function normaliseOrderBy(mixed $orderBy, array $fields, string $context): array
    {
        if ($orderBy === null) {
            return ['sort' => 'ASC', 'title' => 'ASC'];
        }
        if (is_string($orderBy)) {
            $orderBy = [$orderBy => 'ASC'];
        }
        if (!is_array($orderBy)) {
            throw new SchemaException($context . ': "orderBy" must be a field name or an array of field => ASC|DESC.');
        }

        $result = [];
        foreach ($orderBy as $field => $direction) {
            if (is_int($field)) {
                $field = $direction;
                $direction = 'ASC';
            }
            $direction = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
            if (!isset($fields[$field]) && !in_array($field, ['slug', 'title', 'sort', 'updatedAt', 'publishedAt', 'createdAt', 'variant', 'status'], true)) {
                throw new SchemaException(sprintf('%s: orderBy names the unknown field "%s".', $context, $field));
            }
            $result[(string) $field] = $direction;
        }

        return $result;
    }

    /**
     * @return array<string, array{label: string, template: string}>
     */
    private function normaliseVariants(mixed $variants, string $context): array
    {
        if ($variants === null || $variants === []) {
            return [TypeSchema::DEFAULT_VARIANT => ['label' => 'Standard', 'template' => TypeSchema::DEFAULT_TEMPLATE]];
        }
        if (!is_array($variants)) {
            throw new SchemaException($context . ': "variants" must be an array of key => [label, template].');
        }

        $result = [];
        foreach ($variants as $key => $variant) {
            $key = (string) $key;
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key)) {
                throw new SchemaException(sprintf('%s: "%s" is not a valid variant key.', $context, $key));
            }
            if (is_string($variant)) {
                $variant = ['label' => ucfirst($key), 'template' => $variant];
            }
            if (!is_array($variant)) {
                throw new SchemaException(sprintf('%s: variant "%s" must be an array with "label" and "template".', $context, $key));
            }
            $template = (string) ($variant['template'] ?? $key);
            if (!preg_match(self::TEMPLATE_PATTERN, $template) || in_array('..', explode('/', $template), true)) {
                throw new SchemaException(sprintf('%s: variant "%s" has the invalid template name "%s".', $context, $key, $template));
            }
            $result[$key] = [
                'label' => (string) ($variant['label'] ?? ucfirst($key)),
                'template' => $template,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $declaration
     * @return array<string, mixed>
     */
    private function resolveUse(string $name, array $declaration, string $context): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw new SchemaException(sprintf('%s: "use" => "%s" is not a valid field group name.', $context, $name));
        }

        if (!isset($this->fieldGroups[$name])) {
            $file = $this->config->fieldsPath() . '/' . $name . '.php';
            if (!is_file($file)) {
                throw new SchemaException(sprintf('%s: field group "%s" not found (looked for %s).', $context, $name, $file));
            }
            $group = (static fn (string $__file): mixed => include $__file)($file);
            if (!is_array($group)) {
                throw new SchemaException($file . ' must return an array.');
            }
            // A bare list of fields is shorthand for a group.
            if (!isset($group['type']) && !isset($group['fields'])) {
                $group = ['type' => 'group', 'fields' => $group];
            }
            if (!isset($group['type'])) {
                $group['type'] = 'group';
            }
            $this->fieldGroups[$name] = $group;
        }

        unset($declaration['use']);

        return array_replace($this->fieldGroups[$name], $declaration);
    }

    private function validateAll(): void
    {
        foreach ($this->schemas ?? [] as $schema) {
            $context = $this->relativeTypesPath() . '/' . $schema->slug . '.php';
            foreach ($this->walkFields($schema->fields) as $path => $field) {
                foreach ($this->fieldTypes->get($field->type)->validateDefinition($field, $this) as $problem) {
                    throw new SchemaException(sprintf('%s: field "%s": %s', $context, $path, $problem));
                }
            }
        }
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @return \Generator<string, FieldDefinition>
     */
    private function walkFields(array $fields, string $prefix = ''): \Generator
    {
        foreach ($fields as $name => $field) {
            yield $prefix . $name => $field;
            if ($field->fields !== null) {
                yield from $this->walkFields($field->fields, $prefix . $name . '.');
            }
            if ($field->of !== null) {
                yield $prefix . $name . '[]' => $field->of;
                if ($field->of->fields !== null) {
                    yield from $this->walkFields($field->of->fields, $prefix . $name . '[].');
                }
            }
        }
    }

    private function relativeTypesPath(): string
    {
        return trim((string) $this->config->get('typesPath', 'desk/types'), '/');
    }
}
