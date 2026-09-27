<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;

/**
 * `freezed-desk migrate` — create the database or bring it up to date, and
 * check the schema files while at it.
 */
class MigrateCommand extends AbstractCommand
{
    protected function answersInJson(): bool
    {
        return false;
    }

    protected function run(DeskContext $context, array $args, array $options): int
    {
        $log = LogService::getInstance();

        $existed = $context->database()->exists();
        $context->database()->pdo(); // opens and migrates
        $messages = $context->database()->migrate();

        foreach ($messages as $message) {
            $log->notice($message);
        }

        $schemas = $context->schemas()->all();
        $log->success(sprintf(
            '%s %s (schema version %d), %d type%s: %s',
            $existed ? 'Database up to date:' : 'Database created:',
            $context->database()->path,
            $context->database()->userVersion(),
            count($schemas),
            count($schemas) === 1 ? '' : 's',
            $schemas === [] ? '(none in ' . $context->config->typesPath() . ')' : implode(', ', array_keys($schemas))
        ));

        return 0;
    }
}
