<?php

namespace Neuedaten\FreezedDesk\Exception;

/**
 * A record that cannot be saved. Carries one message per field (keyed by the
 * field name, "slug", "variant" or "_" for the record as a whole).
 */
class ValidationException extends DeskException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', array_map(
            static fn (string $field, string $message): string => ($field === '_' ? '' : $field . ': ') . $message,
            array_keys($errors),
            $errors
        )));
    }
}
