<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\Cli;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\Freezed\Exception\PathNotAllowedException;
use Neuedaten\FreezedDesk\Exception\ConflictException;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\NotFoundException;
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Storage\RecordFilter;

/**
 * Boots the desk context from the core's configuration (which is loaded
 * whether the command came in through `freezed` or `freezed-desk`), sets
 * the actor (A3.2), then runs.
 */
abstract class AbstractCommand implements CommandInterface
{
    public function execute(array $args, array $options): int
    {
        $json = $this->answersInJson() || isset($options['json']);
        try {
            $context = Cli::ensureBooted($options);
            $context->actAs(self::actorFrom($options));

            return $this->run($context, $args, $options);
        } catch (ConflictException $exception) {
            if (!$json) {
                return Cli::fail($exception, false);
            }
            self::printJson(['error' => $exception->getMessage(), 'current' => GetCommand::portable(DeskContext::get(), $exception->current)]);

            return 1;
        } catch (DeskException | PathNotAllowedException $exception) {
            // The same answer whether the command came through freezed-desk or `freezed desk:…`.
            return Cli::fail($exception, $json);
        }
    }

    /**
     * Whether the command answers in JSON (then its errors are JSON too).
     * Commands that print for people override this.
     */
    protected function answersInJson(): bool
    {
        return true;
    }

    /**
     * The actor of a CLI process: --actor:, else DESK_ACTOR, else "cli".
     * Only "cli" and "agent" are accepted -- "editor" is the UI's alone.
     *
     * @param array<string, mixed> $options
     */
    public static function actorFrom(array $options): Actor
    {
        $value = $options['actor'] ?? getenv('DESK_ACTOR');
        if ($value === false || $value === null || $value === '' || $value === true) {
            return Actor::Cli;
        }
        $value = strtolower(trim((string) $value));
        if (!in_array($value, Actor::CLI_CHOICES, true)) {
            throw new DeskException(sprintf('The CLI acts as "cli" or "agent", not "%s" (--actor: / DESK_ACTOR). Changes as "editor" are made in the desk UI.', $value));
        }

        return Actor::from($value);
    }

    /**
     * Run a change, or with --dry-run show what it would do without keeping
     * it (A1.12).
     *
     * @template T
     * @param array<string, mixed> $options
     * @param callable(): T $change
     * @return T
     */
    protected static function change(DeskContext $context, array $options, callable $change): mixed
    {
        return Options::flag($options, 'dry-run') ? $context->dryRun($change) : $change();
    }

    protected static function isDryRun(array $options): bool
    {
        return Options::flag($options, 'dry-run');
    }

    /**
     * The records a bulk command works on: "posts/a posts/b", or a type with
     * --where: conditions ("posts --where:format=perle"), or both.
     *
     * @param string[] $args
     * @param array<string, mixed> $options
     * @return Item[]
     */
    protected static function targets(DeskContext $context, array $args, array $options): array
    {
        $items = [];
        $whereType = null;
        foreach ($args as $argument) {
            [$type, $slug] = self::target($argument);
            if ($slug === null && !$context->schemas()->get($type)->single) {
                $whereType = $type;
                continue;
            }
            $item = GetCommand::find($context, $type, $slug);
            $items[$item->id] = $item;
        }

        $conditions = Options::all('where', $options);
        if ($conditions !== []) {
            if ($whereType === null) {
                throw new DeskException('--where: needs a type: <command> <type> --where:field=value');
            }
            $query = $context->repository()->find($whereType)->anyStatus();
            $filter = new RecordFilter($context, $context->schemas()->get($whereType));
            foreach ($conditions as $condition) {
                $filter->where($query, $condition);
            }
            foreach ($query->all() as $item) {
                $items[$item->id] = $item;
            }
        } elseif ($whereType !== null) {
            throw new DeskException(sprintf('Give records as %1$s/<slug>, or select them with %1$s --where:field=value.', $whereType));
        }

        if ($items === []) {
            throw new NotFoundException('No records given or none match.');
        }

        return array_values($items);
    }

    /**
     * @param string[]             $args
     * @param array<string, mixed> $options
     */
    abstract protected function run(DeskContext $context, array $args, array $options): int;

    /**
     * "entries/seeblick" → ['entries', 'seeblick'], "entries" → ['entries', null].
     *
     * @return array{0: string, 1: string|null}
     */
    protected static function target(string $argument): array
    {
        [$type, $slug] = array_pad(explode('/', trim($argument, '/'), 2), 2, null);

        return [(string) $type, $slug === '' ? null : $slug];
    }

    protected static function printJson(mixed $data): void
    {
        fwrite(Cli::out(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
