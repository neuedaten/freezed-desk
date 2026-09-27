<?php

namespace Neuedaten\FreezedDesk;

use Neuedaten\FreezedDesk\Config\DeskConfig;
use Neuedaten\FreezedDesk\Storage\Repository;

/**
 * The two entry points a project uses from PHP:
 *
 *   Desk::variables('site')   in freezed.config.php, to turn a single type
 *                             into site-wide variables;
 *   Desk::repository()        in a script source (data/search.php) or a
 *                             "variables" callback, to read records.
 */
final class Desk
{
    /**
     * The exported variables of a single type, for the "variables" block of
     * freezed.config.php:
     *
     *     'variables' => array_merge(['siteName' => 'Example'], Desk::variables('site')),
     *
     * This runs while the configuration file is still being loaded, so the
     * project root is taken from the calling file and the "desk" settings
     * from the optional second argument (defaults otherwise). Without a
     * database or without the record it returns an empty array, so a fresh
     * project builds before Desk has been used.
     *
     * @param array<string, mixed> $deskConfig Overrides for the desk defaults (dataPath, database).
     * @return array<string, mixed>
     */
    public static function variables(string $type, array $deskConfig = [], ?string $projectRoot = null): array
    {
        $projectRoot ??= self::callerDirectory();
        $config = new DeskConfig($projectRoot, $deskConfig);

        if (!is_file($config->databasePath())) {
            return [];
        }

        try {
            $context = DeskContext::detached($config, readOnly: true);
            $schema = $context->schemas()->get($type);
            $item = $context->repository()->findSingle($type);
            if ($item === null) {
                return [];
            }

            return $context->exporter()->export($item, $schema);
        } catch (\Throwable $exception) {
            // A broken desk must not stop a build that does not need it; the
            // build reports the same problem as soon as DeskSource runs.
            return [];
        }
    }

    public static function repository(): Repository
    {
        return DeskContext::get()->repository();
    }

    /** The media library, e.g. for addGenerated() in a package (A8.5). */
    public static function media(): \Neuedaten\FreezedDesk\Media\MediaRepository
    {
        return DeskContext::get()->media();
    }

    public static function context(): DeskContext
    {
        return DeskContext::get();
    }

    private static function callerDirectory(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            $file = $frame['file'] ?? null;
            if (is_string($file) && !str_starts_with($file, __DIR__)) {
                return dirname($file);
            }
        }

        return getcwd() ?: '.';
    }
}
