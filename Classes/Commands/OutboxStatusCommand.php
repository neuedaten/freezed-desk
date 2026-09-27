<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk outbox:status [--remote] [--plan]` — see OutboxCommand.
 * --plan adds what a push would do now, message by message.
 */
class OutboxStatusCommand extends AbstractCommand
{
    public function run(DeskContext $context, array $args, array $options): int
    {
        $outbox = OutboxCommand::outbox($context);
        $status = $outbox->status();
        if (Options::flag($options, 'plan')) {
            $status['plan'] = array_map(static fn (array $entry): array => [
                'action' => $entry['action'],
                'key' => $entry['key'],
                'channel' => $entry['channel'],
                'at' => $entry['at'],
                'record' => $entry['item'] === null ? null : $entry['item']->type . '/' . $entry['item']->slug,
                'problems' => $entry['problems'],
            ], $outbox->plan());
        }
        if (Options::flag($options, 'remote')) {
            try {
                $status['remote'] = ['messages' => $outbox->client()->messages(), 'health' => $outbox->client()->health()];
            } catch (DeskException $exception) {
                $status['remote'] = ['error' => $exception->getMessage()];
            }
        }
        self::printJson($status);

        return 0;
    }
}
