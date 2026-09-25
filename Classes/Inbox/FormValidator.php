<?php

namespace Neuedaten\FreezedDesk\Inbox;

use Neuedaten\FreezedDesk\DeskContext;

/**
 * Validates a submission with the same rules the server endpoint applies
 * (server/api/lib/FormRules.php).
 */
final class FormValidator
{
    public function __construct(private readonly DeskContext $context)
    {
        require_once dirname(__DIR__, 2) . '/server/api/lib/FormRules.php';
    }

    /**
     * @param array<string, mixed> $input
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(FormDefinition $form, array $input): array
    {
        return desk_form_validate(
            $form->declaration,
            $input,
            fn (string $key, array $params = []): string => $this->context->t('validation.' . $key, $params)
        );
    }
}
