<?php

namespace Neuedaten\FreezedDesk\Schema;

/**
 * The schema of one type: its fields, variants and list settings, as loaded
 * from desk/types/<type>.php by the SchemaLoader.
 *
 * A type is "built" when freezed.config.php has a contentTypes entry of the
 * same slug; DeskSource then delivers its published records to the build.
 * Otherwise it is a desk-only type (a vocabulary, the site settings) that is
 * stored and edited but never rendered.
 */
final class TypeSchema
{
    public const DEFAULT_VARIANT = 'standard';

    public const DEFAULT_TEMPLATE = 'index';

    /** Names every record has regardless of its schema; a field cannot take them. */
    public const RESERVED_FIELD_NAMES = [
        'id', 'slug', 'variant', 'variantLabel', 'status', 'createdAt', 'updatedAt', 'publishedAt',
        'sort', 'lastmod', 'build', 'type', 'template',
    ];

    /** List views a type can offer (listViews). */
    public const LIST_VIEWS = ['table', 'cards', 'agenda'];

    /**
     * @param array<string, FieldDefinition>                          $fields
     * @param array<string, array{label: string, template: string}>   $variants
     * @param array<string, string>                                   $orderBy      field => ASC|DESC
     * @param string[]                                                $listColumns
     * @param string|null                                             $approval     'ui': only a person in the UI can publish (docs/approval.md)
     * @param array<string, array<string, mixed>>                     $listViews    view => options, first is the default
     * @param string[]                                                $listFilters  fields offered as filters in the list
     * @param array<string, array{label: string, command: string, bulk: bool, when: \Closure|null}> $actions record actions
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $labelSingular,
        public readonly array $fields,
        public readonly array $variants,
        public readonly ?string $titleField,
        public readonly ?string $slugFrom,
        public readonly array $orderBy,
        public readonly array $listColumns,
        public readonly bool $single,
        public readonly bool $built,
        public readonly ?\Closure $variablesCallback,
        public readonly string $file,
        public readonly ?string $approval = null,
        public readonly ?\Closure $validateCallback = null,
        public readonly ?\Closure $warningsCallback = null,
        public readonly ?\Closure $guardCallback = null,
        public readonly array $listViews = ['table' => []],
        public readonly array $listFilters = [],
        public readonly array $actions = [],
    ) {
    }

    /** Publishing is reserved for a person in the UI (approval: 'ui'). */
    public function needsUiApproval(): bool
    {
        return $this->approval === 'ui';
    }

    /** @return array<string, FieldDefinition> Fields only the system writes (system: true). */
    public function systemFields(): array
    {
        return array_filter($this->fields, static fn (FieldDefinition $f): bool => $f->system);
    }

    /** @return string The view a list opens with. */
    public function defaultListView(): string
    {
        return (string) (array_key_first($this->listViews) ?? 'table');
    }

    public function field(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /** @return array{label: string, template: string}|null */
    public function variant(string $key): ?array
    {
        return $this->variants[$key] ?? null;
    }

    public function defaultVariant(): string
    {
        return array_key_first($this->variants) ?? self::DEFAULT_VARIANT;
    }

    public bool $hasVariantChoice {
        get => count($this->variants) > 1;
    }

    /** Fields of a type that reference other types, for reverse lookups. */
    public function relationFields(): array
    {
        return array_filter($this->fields, static fn (FieldDefinition $f): bool => $f->type === 'relation');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'labelSingular' => $this->labelSingular,
            'titleField' => $this->titleField,
            'slugFrom' => $this->slugFrom,
            'orderBy' => $this->orderBy,
            'listColumns' => $this->listColumns,
            'single' => $this->single,
            'built' => $this->built,
            'variants' => $this->variants,
            'approval' => $this->approval,
            'listViews' => array_keys($this->listViews),
            'listFilters' => $this->listFilters,
            'actions' => array_map(static fn (array $a): array => ['label' => $a['label'], 'command' => $a['command'], 'bulk' => $a['bulk']], $this->actions),
            'fields' => array_map(static fn (FieldDefinition $f): array => $f->toArray(), $this->fields),
        ];
    }
}
