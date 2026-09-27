<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk inbox:show <id>` — one submission with all its fields and
 * the record it is assigned to.
 */
class InboxShowCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $id = $args[0] ?? throw new DeskException('Usage: freezed-desk inbox:show <id>');
        if (!ctype_digit($id)) {
            throw new DeskException('The inbox id is a number (inbox:list shows them).');
        }
        self::printJson(self::describe($context, $context->inbox()->require((int) $id), full: true));

        return 0;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function describe(DeskContext $context, array $entry, bool $full): array
    {
        $item = $entry['itemId'] !== null ? $context->repository()->get((int) $entry['itemId']) : null;
        $result = [
            'id' => $entry['id'],
            'form' => $entry['form'],
            'status' => $entry['status'],
            'receivedAt' => $entry['receivedAt'],
            'handledAt' => $entry['handledAt'],
            'note' => $entry['note'],
            'item' => $item === null ? null : ['type' => $item->type, 'slug' => $item->slug, 'title' => $item->title],
        ];
        if ($full) {
            $result['fields'] = $entry['payload'];
        } else {
            $summary = '';
            foreach (['message', 'text', 'nachricht', 'body'] as $key) {
                if (isset($entry['payload'][$key]) && is_string($entry['payload'][$key])) {
                    $summary = mb_strimwidth(trim($entry['payload'][$key]), 0, 120, '…');
                    break;
                }
            }
            $result['summary'] = $summary;
        }

        return $result;
    }
}
