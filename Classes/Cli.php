<?php

namespace Neuedaten\FreezedDesk;

use Neuedaten\Freezed\Exception\PathNotAllowedException;
use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\Freezed\Services\LogService;
use Neuedaten\Freezed\Services\ProjectPathsService;
use Neuedaten\FreezedDesk\Commands\CommandInterface;
use Neuedaten\FreezedDesk\Config\DeskConfig;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Exception\ValidationException;

/**
 * The freezed-desk command line: resolves the project like the core's
 * bin/freezed does, loads freezed.config.php into the core's ConfigService
 * and dispatches to a command.
 *
 * The same commands are registered with the core (composer.json,
 * extra.freezed.commands), so `freezed desk:show` equals `freezed-desk show`.
 * This binary remains for a project that calls Desk directly.
 */
final class Cli
{
    public const CONFIG_FILE = 'freezed.config.php';

    /** @var array<string, class-string<CommandInterface>> */
    public const COMMANDS = [
        'serve' => Commands\ServeCommand::class,
        'migrate' => Commands\MigrateCommand::class,
        'show' => Commands\ShowCommand::class,
        'export' => Commands\ExportCommand::class,
        'import' => Commands\ImportCommand::class,
        'seed' => Commands\SeedCommand::class,
        'media:check' => Commands\MediaCheckCommand::class,
        'inbox' => Commands\InboxCommand::class,
        // JSON interface, for scripts and agents (docs/agents.md).
        'schema' => Commands\SchemaCommand::class,
        'list' => Commands\ListCommand::class,
        'get' => Commands\GetCommand::class,
        'put' => Commands\PutCommand::class,
        'delete' => Commands\DeleteCommand::class,
        'publish' => Commands\PublishCommand::class,
        'unpublish' => Commands\UnpublishCommand::class,
        'archive' => Commands\ArchiveCommand::class,
        'media:add' => Commands\MediaAddCommand::class,
        'media:list' => Commands\MediaListCommand::class,
        'agent' => Commands\AgentCommand::class,
    ];

    /** Commands that answer in JSON; their errors are JSON too. */
    public const JSON_COMMANDS = ['schema', 'list', 'get', 'put', 'delete', 'publish', 'unpublish', 'archive', 'media:add', 'media:list'];

    /** @param string[] $argv */
    public static function run(array $argv): int
    {
        $positional = [];
        $options = self::parseOptions(array_slice($argv, 1), $positional);

        $command = array_shift($positional) ?? 'serve';
        $command = preg_replace('/^desk:?/', '', $command) ?: 'serve';

        if (in_array($command, ['help', '-h', '--help'], true) || isset($options['help'])) {
            fwrite(STDOUT, self::help());
            return 0;
        }
        if (in_array($command, ['version', '-V', '--version'], true) || isset($options['version'])) {
            fwrite(STDOUT, 'Freezed Desk ' . self::version() . PHP_EOL);
            return 0;
        }

        if (!isset(self::COMMANDS[$command])) {
            fwrite(STDERR, 'freezed-desk: unknown command "' . $command . '". Run freezed-desk help.' . PHP_EOL);
            return 1;
        }

        $json = isset($options['json']) || in_array($command, self::JSON_COMMANDS, true);

        try {
            self::loadProject($options);
            $class = self::COMMANDS[$command];
            /** @var CommandInterface $instance */
            $instance = new $class();

            return $instance->execute($positional, $options);
        } catch (DeskException | PathNotAllowedException $exception) {
            return self::fail($exception, $json);
        }
    }

    /**
     * Report a failure: as JSON on stdout for the JSON commands (and with
     * --json), as an error line otherwise. Exit code 1 either way.
     */
    public static function fail(\Throwable $exception, bool $json): int
    {
        if ($json) {
            $payload = ['error' => $exception->getMessage()];
            if ($exception instanceof ValidationException) {
                $payload['errors'] = $exception->errors;
            }
            fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } else {
            LogService::getInstance()->error($exception->getMessage());
        }

        return 1;
    }

    /**
     * Load the project configuration into the core services and boot the
     * desk context. Public so the web router can do the same.
     *
     * @param array<string, mixed> $options Parsed CLI options (verbose, quiet, log, …).
     */
    public static function bootstrap(array $options = [], ?string $projectRoot = null): DeskContext
    {
        self::loadProject($options, $projectRoot);

        return self::ensureBooted($options);
    }

    /**
     * The desk context, booted from the core's configuration when it is
     * not yet. This is where a command lands when the core's CLI dispatched
     * it: the configuration is loaded, the directories are not validated
     * yet -- Desk creates the media folder first, then validates.
     *
     * @param array<string, mixed> $options
     */
    public static function ensureBooted(array $options = []): DeskContext
    {
        if (DeskContext::isBooted()) {
            return DeskContext::get();
        }

        // The media folder is an assetRoot, and the core refuses a root that
        // does not exist yet. Desk owns that folder, so it creates it before
        // the core looks -- the one convention a fresh project needs no
        // command for.
        $deskConfig = DeskConfig::fromCore();
        $deskConfig->ensureMediaFolder();

        ProjectPathsService::getInstance()->validate();

        return DeskContext::boot($deskConfig);
    }

    /**
     * Load freezed.config.php into the core services, the way bin/freezed
     * does. Nothing happens when the core already did it.
     *
     * @param array<string, mixed> $options
     */
    public static function loadProject(array $options = [], ?string $projectRoot = null): void
    {
        $configService = ConfigService::getInstance();
        if (is_string($configService->getValue('[projectRoot]')) && $configService->getValue('[projectRoot]') !== '') {
            return;
        }

        $projectRoot ??= self::resolveProjectRoot();
        $configFile = $projectRoot . DIRECTORY_SEPARATOR . self::CONFIG_FILE;

        if (!is_file($configFile)) {
            throw new DeskException(sprintf('No %s found in %s. Run "freezed install" first.', self::CONFIG_FILE, $projectRoot));
        }

        $config = include $configFile;
        if (!is_array($config)) {
            throw new DeskException($configFile . ' must return an array.');
        }

        $configService = ConfigService::getInstance();
        $configService->setConfig($config);
        $configService->setValue('[projectRoot]', $projectRoot);
        $configService->setValue('[buildConfig]', $options);
        $configService->setValue('[cli]', [
            'php' => PHP_BINARY,
            'bin' => self::coreBinary($projectRoot),
            'optionArgs' => [],
        ]);

        LogService::getInstance()->configureFromBuildConfig($options, $projectRoot);
    }

    /**
     * The project root: FREEZED_ROOT, or the nearest directory upwards from
     * the working directory that holds freezed.config.php (a config above
     * the working directory is only used when it belongs to the current
     * user, as in the core), or the working directory.
     */
    public static function resolveProjectRoot(): string
    {
        $envRoot = getenv('FREEZED_ROOT');
        if ($envRoot && is_dir($envRoot)) {
            return realpath($envRoot) ?: $envRoot;
        }

        $cwd = getcwd() ?: '.';
        $dir = $cwd;
        $previous = null;
        while ($dir && $dir !== $previous) {
            $candidate = $dir . DIRECTORY_SEPARATOR . self::CONFIG_FILE;
            if (file_exists($candidate)) {
                if ($dir !== $cwd && !self::ownedByCurrentUser($candidate)) {
                    throw new DeskException($candidate . ' belongs to another user and is not used. Run freezed-desk inside the project or set FREEZED_ROOT.');
                }

                return $dir;
            }
            $previous = $dir;
            $dir = dirname($dir);
        }

        return $cwd;
    }

    /**
     * @param string[] $args
     * @return array<string, mixed>
     */
    public static function parseOptions(array $args, array &$positional): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $positional[] = $arg;
                continue;
            }
            $option = substr($arg, 2);
            $separator = strcspn($option, ':=');
            if ($separator < strlen($option)) {
                $options[substr($option, 0, $separator)] = substr($option, $separator + 1);
            } elseif ($option !== '') {
                $options[$option] = true;
            }
        }

        return $options;
    }

    public static function version(): string
    {
        $changelog = dirname(__DIR__) . '/CHANGELOG.md';
        if (is_file($changelog) && preg_match('/^##\s*\[(\d+\.\d+\.\d+[^\]]*)\]/m', (string) file_get_contents($changelog), $m)) {
            return $m[1];
        }

        return '0.1.0';
    }

    public static function help(): string
    {
        $version = self::version();

        return <<<TXT
Freezed Desk $version — the editorial backend for Freezed.

Usage:
  freezed-desk <command> [options]

Commands:
  serve             Start the desk UI (default). Options --host:, --port:.
  migrate           Create or update the desk database.
  show <type>[/<slug>]
                    Print the variables a build sees for a record, or list
                    the records of a type. --raw prints the stored data.
  export            Write every record as JSON to data/export/ (git-friendly).
  import            Read data/export/ back into the database.
  seed <file>       Create or update records (and media) from a JSON file.
  media:check       Report missing and orphaned media files.
                    --adopt adds orphans to the library, --prune drops
                    records whose file is gone.
  inbox             Fetch submissions from the configured inbox endpoint.

JSON interface (for scripts and agents, see docs/agents.md):
  agent             Print a guide to this project's desk for an agent.
  schema [<type>]   The schema as JSON.
  list <type>       Records of a type as JSON. --status:, --q:, --limit:
  get <type>/<slug> A record as JSON (fields as stored, relations by slug,
                    media by file). --export prints the template variables.
  put <type>[/<slug>]
                    Create or update a record from JSON on stdin or --file:.
                    Only the given fields change.
  delete, publish, unpublish, archive <type>/<slug>
  media:add <file>  Add a file to the library. --alt:, --caption:, --credit:, --license:
  media:list        The library as JSON. --q:, --kind:images|files
  help, --help      Show this help.
  version           Show the version.

Options:
  --json            Errors as JSON on stdout (the JSON commands do this anyway).
  --verbose         Show detailed output.
  --quiet           Only show errors.
  --log[=<path>]    Write the full log to a file.

Environment:
  FREEZED_ROOT      Override the project root directory.

TXT;
    }

    private static function ownedByCurrentUser(string $file): bool
    {
        if (!function_exists('posix_geteuid') || DIRECTORY_SEPARATOR === '\\') {
            return true;
        }
        $owner = @fileowner($file);

        return $owner === false || $owner === posix_geteuid();
    }

    private static function coreBinary(string $projectRoot): string
    {
        foreach ([
            $projectRoot . '/vendor/bin/freezed',
            dirname(__DIR__, 3) . '/bin/freezed',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'freezed';
    }
}
