<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Support;

use Neuedaten\Freezed\Services\AssetRootService;
use Neuedaten\Freezed\Services\CommandRegistryService;
use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\Freezed\Services\ProjectPathsService;
use Neuedaten\FreezedDesk\Cli;
use Neuedaten\FreezedDesk\Commands\CommandInterface;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Storage\Actor;
use PHPUnit\Framework\TestCase;

/**
 * A throwaway Freezed project per test: freezed.config.php, desk/types/,
 * a data folder. Boots the core services and a fresh DeskContext, and runs
 * commands the way the CLI does, capturing their JSON.
 */
abstract class DeskTestCase extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/desk-test-' . bin2hex(random_bytes(6));
        foreach (['desk/types', 'desk/agent', 'content', 'themes', 'static', 'data/media'] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }
        // macOS: the temp folder is behind a symlink; Desk compares real paths.
        $this->root = (string) realpath($this->root);
        $this->writeConfig();
        putenv('DESK_ACTOR');
    }

    protected function tearDown(): void
    {
        DeskContext::reset();
        self::removeDirectory($this->root);
        putenv('DESK_ACTOR');
        parent::tearDown();
    }

    /** @param array<string, mixed> $desk The project's "desk" settings. */
    protected function writeConfig(array $desk = [], array $extra = []): void
    {
        $config = array_replace_recursive([
            'siteUrl' => 'https://example.org',
            'assetRoots' => ['media' => 'data/media'],
            'contentTypes' => [],
            'desk' => ['timezone' => 'Europe/Berlin', 'revisions' => 50] + $desk,
        ], $extra);
        file_put_contents($this->root . '/freezed.config.php', '<?php return ' . var_export($config, true) . ';');
        $this->boot();
    }

    protected function type(string $slug, string $php): void
    {
        file_put_contents($this->root . '/desk/types/' . $slug . '.php', $php);
        $this->boot();
    }

    protected function boot(): DeskContext
    {
        DeskContext::reset();
        $config = include $this->root . '/freezed.config.php';
        $core = ConfigService::getInstance();
        $core->setConfig($config);
        $core->setValue('[projectRoot]', $this->root);
        $core->setValue('[buildConfig]', []);
        $core->setValue('[cli]', ['php' => PHP_BINARY, 'bin' => 'freezed', 'optionArgs' => []]);
        ProjectPathsService::getInstance()->reset();
        AssetRootService::getInstance()->reset();
        CommandRegistryService::getInstance()->reset();

        return Cli::ensureBooted([]);
    }

    protected function context(): DeskContext
    {
        return DeskContext::get();
    }

    /**
     * Run a command like the CLI: options as "--key:value" tokens, JSON
     * output decoded.
     *
     * @param string[] $args
     * @param string[] $optionTokens
     * @return array{exit: int, json: mixed, output: string}
     */
    protected function desk(string $command, array $args = [], array $optionTokens = [], ?string $stdin = null, ?string $actor = null): array
    {
        $positional = [];
        $options = Cli::parseOptions($optionTokens, $positional);
        ConfigService::getInstance()->setValue('[cli][optionArgs]', $optionTokens);
        $actor === null ? putenv('DESK_ACTOR') : putenv('DESK_ACTOR=' . $actor);

        $class = Cli::COMMANDS[$command] ?? throw new \LogicException('Unknown command ' . $command);
        /** @var CommandInterface $instance */
        $instance = new $class();

        if ($stdin !== null) {
            $file = $this->root . '/stdin.json';
            file_put_contents($file, $stdin);
            $options['file'] = $file;
        }

        $stream = fopen('php://memory', 'w+');
        $exit = StdoutCapture::run($stream, static fn (): int => $instance->execute($args, $options));
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        putenv('DESK_ACTOR');
        DeskContext::get()->actAs(Actor::Cli);

        return ['exit' => $exit, 'json' => json_decode($output, true), 'output' => $output];
    }

    protected static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
