<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Media\Media;
use Neuedaten\FreezedDesk\Storage\Query;

/**
 * `freezed-desk media:list` — the library as JSON. Options: --q:<search>,
 * --kind:images|videos|files, --origin:upload|import|generated (generated
 * files are left out otherwise), --where:<extra field>=<value> (repeatable,
 * e.g. --where:socialOk=true), --unused, --limit:, --offset:.
 */
class MediaListCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $usage = $context->media()->usageCounts();
        $fields = $context->media()->fields();

        $filters = [];
        foreach (Options::all('where', $options) as $condition) {
            if (!preg_match('/^([A-Za-z][A-Za-z0-9_]*)(!=|=)(.*)$/', $condition, $m)) {
                throw new DeskException(sprintf('media:list --where: takes field=value or field!=value, got "%s".', $condition));
            }
            [, $name, $operator, $raw] = $m;
            $known = ['alt', 'caption', 'credit', 'license', 'mime', 'origin'];
            if (!$fields->has($name) && !in_array($name, $known, true)) {
                throw new DeskException(sprintf('Unknown media field "%s" (extra fields: %s).', $name, implode(', ', array_keys($fields->all())) ?: 'none'));
            }
            $isBool = $fields->has($name) && $fields->all()[$name]->type === 'bool';
            $value = $isBool ? in_array(strtolower($raw), ['1', 'true', 'yes', 'ja'], true) : $raw;
            $filters[] = static function (Media $media) use ($name, $operator, $value, $raw, $known): bool {
                $stored = in_array($name, $known, true) ? $media->{$name} : ($media->extra[$name] ?? null);
                $match = $raw === '' ? ($stored === null || $stored === '' || $stored === false) : Query::matches($stored, $value);

                return $operator === '=' ? $match : !$match;
            };
        }
        if (Options::flag($options, 'unused')) {
            $filters[] = static fn (Media $media): bool => !isset($usage[$media->id]);
        }

        $origin = isset($options['origin']) ? (string) $options['origin'] : null;
        $items = $context->media()->all(
            (string) ($options['q'] ?? ''),
            (string) ($options['kind'] ?? 'all'),
            (int) ($options['limit'] ?? 0),
            (int) ($options['offset'] ?? 0),
            $origin,
            withGenerated: false,
            filters: $filters,
        );

        self::printJson([
            'media' => array_map(static fn (Media $m): array => [
                'id' => $m->id,
                'file' => $m->file,
                'name' => $m->originalName,
                'mime' => $m->mime,
                'width' => $m->width,
                'height' => $m->height,
                'alt' => $m->alt,
                'caption' => $m->caption,
                'credit' => $m->credit,
                'focal' => $m->focalX === null ? null : ['x' => $m->focalX, 'y' => $m->focalY],
                'extra' => (object) $m->extra,
                'origin' => $m->origin,
                'usedIn' => $usage[$m->id] ?? 0,
            ], $items),
        ]);

        return 0;
    }
}
