<?php

namespace Neuedaten\FreezedDesk\Inbox;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

final class FormLoader
{
    /** @var array<string, FormDefinition>|null */
    private ?array $forms = null;

    public function __construct(private readonly DeskContext $context)
    {
    }

    /** @return array<string, FormDefinition> */
    public function all(): array
    {
        if ($this->forms !== null) {
            return $this->forms;
        }
        $forms = [];
        foreach (glob($this->context->config->formsPath() . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $declaration = (static fn (string $__file): mixed => include $__file)($file);
            if (!is_array($declaration)) {
                throw new DeskException($file . ' must return an array.');
            }
            $forms[$name] = new FormDefinition($name, $declaration);
        }

        return $this->forms = $forms;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): FormDefinition
    {
        return $this->all()[$name] ?? throw new DeskException(sprintf('Unknown form "%s": no %s/%s.php.', $name, trim((string) $this->context->config->get('formsPath', 'desk/forms'), '/'), $name));
    }
}
