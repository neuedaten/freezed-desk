<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk validate <type>/<slug> [--publishing]` — the messages of the
 * schema's checks without saving (A5.4): errors (they block publishing),
 * warnings (they never do). --publishing checks as if the record were to
 * be published now, which is how an agent tests a draft before handing it
 * over. Exit code 1 when an error would block.
 */
class ValidateCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        [$type, $slug] = self::target($args[0] ?? throw new DeskException('Usage: freezed-desk validate <type>/<slug> [--publishing]'));
        $item = GetCommand::find($context, $type, $slug);
        $validation = $context->validation()->ofItem($item, Options::flag($options, 'publishing') ? true : null);

        self::printJson([
            'type' => $item->type,
            'slug' => $item->slug,
            'status' => $item->status->value,
            'publishing' => $validation['publishing'],
            'ok' => $validation['errors'] === [],
            'errors' => (object) $validation['errors'],
            'warnings' => (object) $validation['warnings'],
        ]);

        return $validation['errors'] !== [] && $validation['publishing'] ? 1 : 0;
    }
}
