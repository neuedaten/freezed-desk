<?php

namespace Neuedaten\FreezedDesk\Schema;

use Neuedaten\FreezedDesk\DeskContext;

/**
 * A field type that exports more than one variable, e.g. a select exports
 * the key as <name> and the label as <name>Label.
 */
interface ProvidesExtraVariables
{
    /**
     * @return array<string, mixed> Extra variables, keyed by full variable name.
     */
    public function extraVariables(mixed $value, FieldDefinition $field, DeskContext $context): array;
}
