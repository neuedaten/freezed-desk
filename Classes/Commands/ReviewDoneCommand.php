<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk review:done <point-id> … [--note:…] [--reopen] [--dry-run]`
 * or `review:done <type>/<slug> --all [--note:…]` — mark review points as
 * worked through (docs/review.md). The note says what was done; it stays in
 * the history with who marked the point and when. --reopen opens points
 * again.
 */
class ReviewDoneCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        if ($args === []) {
            throw new DeskException('Usage: freezed-desk review:done <point-id> … [--note:…] [--reopen] | review:done <type>/<slug> --all [--note:…]');
        }
        $reviews = $context->reviews();
        $note = isset($options['note']) && is_string($options['note']) ? $options['note'] : '';
        $reopen = Options::flag($options, 'reopen');

        $ids = [];
        if (Options::flag($options, 'all')) {
            [$type, $slug] = self::target($args[0]);
            $item = GetCommand::find($context, $type, (string) $slug);
            $ids = array_map(static fn (array $p): int => $p['id'], $reviews->openPoints([$item->id]));
        } else {
            foreach ($args as $argument) {
                if (!ctype_digit($argument)) {
                    throw new DeskException(sprintf('"%s" is not a point id (reviews --open lists them).', $argument));
                }
                $ids[] = (int) $argument;
            }
        }

        $points = self::change($context, $options, static function () use ($reviews, $ids, $note, $reopen): array {
            $changed = [];
            foreach ($ids as $id) {
                $changed[] = $reopen ? $reviews->reopen($id) : $reviews->markDone($id, $note);
            }

            return $changed;
        });

        self::printJson([
            'points' => array_map(static fn (array $p): array => ['id' => $p['id'], 'field' => $p['field'], 'open' => $p['open'], 'doneAt' => $p['doneAt'], 'doneBy' => $p['doneBy'], 'doneNote' => $p['doneNote']], $points),
            'dryRun' => self::isDryRun($options),
        ]);

        return 0;
    }
}
