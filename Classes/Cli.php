<?php

namespace Neuedaten\FreezedDesk;

use Neuedaten\Freezed\Exception\PathNotAllowedException;
use Neuedaten\Freezed\Services\CommandRegistryService;
use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\Freezed\Services\LogService;
use Neuedaten\Freezed\Services\ProjectPathsService;
use Neuedaten\FreezedDesk\Commands\CommandInterface;
use Neuedaten\FreezedDesk\Config\DeskConfig;
use Neuedaten\FreezedDesk\Exception\ConflictException;
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
        // JSON interface, for scripts and agents (docs/agents.md, docs/cli.md).
        'schema' => Commands\SchemaCommand::class,
        'list' => Commands\ListCommand::class,
        'get' => Commands\GetCommand::class,
        'put' => Commands\PutCommand::class,
        'validate' => Commands\ValidateCommand::class,
        'refs' => Commands\RefsCommand::class,
        'revisions' => Commands\RevisionsCommand::class,
        'revision' => Commands\RevisionCommand::class,
        'restore' => Commands\RestoreCommand::class,
        'delete' => Commands\DeleteCommand::class,
        'publish' => Commands\PublishCommand::class,
        'unpublish' => Commands\UnpublishCommand::class,
        'archive' => Commands\ArchiveCommand::class,
        'reorder' => Commands\ReorderCommand::class,
        'media:add' => Commands\MediaAddCommand::class,
        'media:list' => Commands\MediaListCommand::class,
        'media:get' => Commands\MediaGetCommand::class,
        'media:update' => Commands\MediaUpdateCommand::class,
        'media:delete' => Commands\MediaDeleteCommand::class,
        'media:usage' => Commands\MediaUsageCommand::class,
        'media:prune' => Commands\MediaPruneCommand::class,
        'inbox:list' => Commands\InboxListCommand::class,
        'inbox:show' => Commands\InboxShowCommand::class,
        'inbox:assign' => Commands\InboxAssignCommand::class,
        'inbox:set' => Commands\InboxSetCommand::class,
        'actions' => Commands\ActionsCommand::class,
        'action' => Commands\ActionCommand::class,
        'status' => Commands\OverviewCommand::class,
        'preview-url' => Commands\PreviewUrlCommand::class,
        'outbox' => Commands\OutboxCommand::class,
        'outbox:push' => Commands\OutboxPushCommand::class,
        'outbox:pull' => Commands\OutboxPullCommand::class,
        'outbox:status' => Commands\OutboxStatusCommand::class,
        'reviews' => Commands\ReviewsCommand::class,
        'review:list' => Commands\ReviewListCommand::class,
        'review:done' => Commands\ReviewDoneCommand::class,
        'agent' => Commands\AgentCommand::class,
    ];

    /** Commands that answer in JSON; their errors are JSON too. */
    public const JSON_COMMANDS = [
        'schema', 'list', 'get', 'put', 'validate', 'refs', 'revisions', 'revision', 'restore', 'delete', 'publish', 'unpublish', 'archive', 'reorder',
        'media:add', 'media:list', 'media:get', 'media:update', 'media:delete', 'media:usage', 'media:prune',
        'inbox:list', 'inbox:show', 'inbox:assign', 'inbox:set', 'actions', 'status', 'preview-url',
        'outbox', 'outbox:push', 'outbox:pull', 'outbox:status',
        'reviews', 'review:list', 'review:done',
    ];

    /** The raw arguments of the freezed-desk binary, for repeatable options (Commands\Options). */
    public static array $argv = [];

    /** @var resource|null Where commands print; tests capture it. */
    public static $stdout = null;

    /** @return resource */
    public static function out()
    {
        return self::$stdout ?? STDOUT;
    }

    /** @param string[] $argv */
    public static function run(array $argv): int
    {
        self::$argv = array_values(array_filter(array_slice($argv, 1), static fn (string $arg): bool => str_starts_with($arg, '--')));
        $positional = [];
        $options = self::parseOptions(array_slice($argv, 1), $positional);

        $command = array_shift($positional) ?? 'serve';
        $command = preg_replace('/^desk:?/', '', $command) ?: 'serve';

        if (in_array($command, ['help', '-h', '--help'], true) || isset($options['help'])) {
            fwrite(Cli::out(), self::help());
            return 0;
        }
        if (in_array($command, ['version', '-V', '--version'], true) || isset($options['version'])) {
            fwrite(Cli::out(), 'Freezed Desk ' . self::version() . PHP_EOL);
            return 0;
        }

        $json = isset($options['json']) || in_array($command, self::JSON_COMMANDS, true) || str_contains($command, ':');

        try {
            self::loadProject($options);
            $class = self::COMMANDS[$command] ?? null;
            if ($class === null) {
                // Commands of packages (desk:social:plan …) are registered with
                // the core; the freezed-desk binary finds them there too.
                $registered = CommandRegistryService::getInstance()->get('desk:' . $command);
                if ($registered === null) {
                    fwrite(STDERR, 'freezed-desk: unknown command "' . $command . '". Run freezed-desk help.' . PHP_EOL);

                    return 1;
                }

                return $registered->execute($positional, $options);
            }
            /** @var CommandInterface $instance */
            $instance = new $class();

            return $instance->execute($positional, $options);
        } catch (ConflictException $exception) {
            if ($json) {
                fwrite(Cli::out(), json_encode(['error' => $exception->getMessage(), 'current' => Commands\GetCommand::portable(DeskContext::get(), $exception->current)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

                return 1;
            }

            return self::fail($exception, $json);
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
            fwrite(Cli::out(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
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
            'optionArgs' => self::$argv,
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

JSON interface (for scripts and agents, see docs/cli.md and docs/agents.md):
  agent [<topic>]   Print the guide to this project's desk for an agent.
                    --json structured, --topics lists the topics.
  schema [<type>]   The schema as JSON.
  list <type>       Records of a type. --status:, --q:, --where:field=value
                    (repeatable), --from:, --to:, --range:, --order:,
                    --referencing:<type>/<slug>, --by:, --unseen, --fields:,
                    --validation, --limit:, --offset:
  get <type>/<slug> A record as JSON with revision and validation.
                    --export prints the template variables.
  put <type>[/<slug>]
                    Create or update a record from JSON on stdin or --file:.
                    Only the given fields change. --if-revision:<n>
  validate <type>/<slug> [--publishing]
  refs <type>/<slug>
  revisions <type>/<slug>, revision <type>/<slug> <n> [--diff],
  restore <type>/<slug> <n>
  publish, unpublish, archive, delete <type>/<slug> … | <type> --where:…
  reorder <type> <slug> <slug> …
  media:add <file>  Add a file to the library. --alt:, --caption:, --credit:,
                    --license:, --focal:x,y, --extra:{…}
  media:list        The library. --q:, --kind:, --origin:, --where:, --unused
  media:get, media:usage, media:update, media:delete <id|file>
  media:prune --generated [--older-than:90d]
  inbox:list, inbox:show <id>, inbox:assign <id> <type>/<slug>,
  inbox:set <id> --status: --note:
  actions, action <name>, action <type>/<slug> <name>
  status            The overview as JSON.
  preview-url <type>/<slug>
  outbox push|pull|status
                    The outbox (docs/outbox.md). push may be reserved for
                    the UI (desk.outbox.push).
  review:list [<type>] [--queue:open]
                    Review queues (docs/review.md): counts per type, or the
                    records of one queue.
  reviews [<type>[/<slug>]] [--open] [--snapshot]
                    A record's reviews, or the open points to work through.
  review:done <point-id> … | <type>/<slug> --all [--note:…] [--reopen]
                    Mark review points as done (reviews themselves are made
                    in the UI).

Commands that change something take --dry-run. The CLI acts as "cli", or
as "agent" with --actor:agent or DESK_ACTOR=agent (docs/approval.md).

Options:
  --json            Errors as JSON on stdout (the JSON commands do this anyway).
  --verbose         Show detailed output.
  --quiet           Only show errors.
  --log[=<path>]    Write the full log to a file.

Environment:
  FREEZED_ROOT      Override the project root directory.
  DESK_ACTOR        "agent" records changes as the agent's (default "cli").

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
