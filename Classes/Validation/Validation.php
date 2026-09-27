<?php

namespace Neuedaten\FreezedDesk\Validation;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\Status;

/**
 * Runs the callbacks of a schema (A5): "validate" (blocks publishing, a hint
 * in a draft), "warnings" (never blocks) and "guard" (blocks every save,
 * whatever the status -- for rules such as "an agent must not change the
 * source of a planned post").
 *
 * Messages are keyed by field name, or "_" for the record as a whole.
 */
final class Validation
{
    public function __construct(private readonly DeskContext $context)
    {
    }

    /**
     * The messages of a stored record, as `get`, `validate` and the lists
     * show them.
     *
     * @return array{errors: array<string, string>, warnings: array<string, string>, publishing: bool, blocking: bool}
     */
    public function ofItem(Item $item, ?bool $publishing = null): array
    {
        $schema = $this->context->schemas()->get($item->type);
        $publishing ??= $item->status === Status::Published;

        $errors = $this->requiredErrors($schema, $item->data, $publishing)
            + $this->run($schema->validateCallback, $schema, $item->data, $item, $publishing, $this->context->actor(), $item->slug);
        $warnings = $this->run($schema->warningsCallback, $schema, $item->data, $item, $publishing, $this->context->actor(), $item->slug);

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'publishing' => $publishing,
            'blocking' => $publishing && $errors !== [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public function run(?\Closure $callback, TypeSchema $schema, array $data, ?Item $item, bool $publishing, Actor $actor, string $slug = ''): array
    {
        if ($callback === null) {
            return [];
        }
        $messages = $callback($data, $item, new ValidationContext($publishing, $actor, $schema, $this->context, $slug));
        if (!is_array($messages)) {
            throw new DeskException(sprintf('A validation callback of type "%s" must return an array of field => message, got %s.', $schema->slug, get_debug_type($messages)));
        }

        $result = [];
        foreach ($messages as $field => $message) {
            if (is_array($message)) {
                $message = implode(' ', array_map('strval', $message));
            }
            $message = trim((string) $message);
            if ($message !== '') {
                $field = (string) $field;
                $result[$field] = isset($result[$field]) ? $result[$field] . ' ' . $message : $message;
            }
        }

        return $result;
    }

    /**
     * Required fields that are empty -- they only matter when publishing,
     * a draft may be incomplete.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function requiredErrors(TypeSchema $schema, array $data, bool $publishing): array
    {
        if (!$publishing) {
            return [];
        }
        $errors = [];
        foreach ($schema->fields as $name => $field) {
            if ($field->required && $this->context->fieldTypes()->get($field->type)->isEmpty($data[$name] ?? null)) {
                $errors[$name] = $this->context->t('validation.required');
            }
        }

        return $errors;
    }
}
