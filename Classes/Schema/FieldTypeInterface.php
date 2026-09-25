<?php

namespace Neuedaten\FreezedDesk\Schema;

use Neuedaten\FreezedDesk\DeskContext;

/**
 * A field type knows three things about a value: how to read it from a form
 * or a JSON file (normalize), whether it is acceptable (validate) and what a
 * template gets to see (export). The stored shape is whatever normalize()
 * returns; it is kept as JSON in the items table.
 */
interface FieldTypeInterface
{
    public function name(): string;

    /**
     * Turn raw input (a form value, a seed/import value or an already stored
     * value) into the stored shape. Must accept its own output unchanged.
     */
    public function normalize(mixed $input, FieldDefinition $field, DeskContext $context): mixed;

    /**
     * @return string[] Error messages; empty when the value is fine.
     */
    public function validate(mixed $value, FieldDefinition $field, DeskContext $context): array;

    /**
     * The template variable for a stored value.
     */
    public function export(mixed $value, FieldDefinition $field, DeskContext $context): mixed;

    /**
     * The value of a field that was never set.
     */
    public function defaultValue(FieldDefinition $field, DeskContext $context): mixed;

    /**
     * True when the value counts as "not filled in" for a required check.
     */
    public function isEmpty(mixed $value): bool;

    /**
     * Words that a full-text search should find for this value.
     */
    public function searchText(mixed $value, FieldDefinition $field, DeskContext $context): string;

    /**
     * Validate the declaration itself (unknown target type, missing option).
     *
     * @return string[] Problems with the declaration.
     */
    public function validateDefinition(FieldDefinition $field, SchemaLoader $schemas): array;
}
