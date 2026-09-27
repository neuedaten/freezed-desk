<?php

namespace Neuedaten\FreezedDesk\Config;

use Neuedaten\Freezed\Services\AssetRootService;
use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\Freezed\Services\FileService;
use Neuedaten\Freezed\Services\ProjectPathsService;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * The "desk" key of freezed.config.php, merged over the package defaults in
 * includes/config.php, plus the absolute paths derived from it.
 *
 * Desk follows the core's file rule: dataPath is a sub-directory of the
 * project and overlaps none of the directories a build reads or empties.
 * validate() checks that before the UI starts or a command writes anything.
 */
final class DeskConfig
{
    /** @var array<string, mixed> */
    private readonly array $values;

    private bool $validated = false;

    /**
     * @param array<string, mixed> $projectValues The project's "desk" array (may be empty).
     */
    public function __construct(
        public readonly string $projectRoot,
        array $projectValues = [],
        private readonly ?string $siteName = null,
    ) {
        $this->values = array_replace_recursive(self::defaults(), $projectValues);
    }

    /**
     * Build from the core's ConfigService, i.e. inside `freezed build`, the
     * desk CLI or the web UI, once the project configuration is loaded.
     */
    public static function fromCore(): self
    {
        $config = ConfigService::getInstance();
        $projectRoot = (string) $config->getValue('[projectRoot]');
        $desk = $config->getValue('[desk]');
        $siteName = $config->getValue('[variables][siteName]');

        return new self($projectRoot, is_array($desk) ? $desk : [], is_string($siteName) ? $siteName : null);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return include __DIR__ . '/../../includes/config.php';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * The project's name for the UI: desk.projectName, else the site's
     * siteName variable, else the name of the project folder.
     */
    public function projectName(): string
    {
        foreach ([$this->get('projectName'), $this->siteName] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return basename(rtrim($this->projectRoot, '/')) ?: $this->projectRoot;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    // ------------------------------------------------------------ paths ---

    public function dataPath(): string
    {
        return $this->absolute((string) $this->get('dataPath', 'data'));
    }

    public function databasePath(): string
    {
        return $this->dataPath() . '/' . (string) $this->get('database', 'desk.sqlite');
    }

    public function sessionPath(): string
    {
        return $this->dataPath() . '/desk.session';
    }

    public function exportPath(): string
    {
        return $this->dataPath() . '/' . trim((string) $this->get('exportPath', 'export'), '/');
    }

    public function typesPath(): string
    {
        return $this->absolute((string) $this->get('typesPath', 'desk/types'));
    }

    public function fieldsPath(): string
    {
        return $this->absolute((string) $this->get('fieldsPath', 'desk/fields'));
    }

    public function formsPath(): string
    {
        return $this->absolute((string) $this->get('formsPath', 'desk/forms'));
    }

    /** Project Markdown files for the agent guide (desk/agent/<topic>.md, A2.1). */
    public function agentPath(): string
    {
        return $this->absolute((string) $this->get('agentPath', 'desk/agent'));
    }

    public function themesPath(): string
    {
        return $this->absolute((string) $this->get('themesPath', 'desk/themes'));
    }

    /**
     * Absolute path of the package theme named by desk.theme (a folder next
     * to themes/00_desk), or null for the plain desk theme.
     *
     * @throws DeskException
     */
    public function packageThemePath(): ?string
    {
        $name = $this->get('theme');
        if ($name === null || $name === '') {
            return null;
        }
        if (!is_string($name) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) || $name === '00_desk') {
            throw new DeskException('desk.theme must be the name of a theme shipped with Desk, got ' . json_encode($name) . '.');
        }

        $path = dirname(__DIR__, 2) . '/themes/' . $name;
        if (!is_dir($path)) {
            throw new DeskException('desk.theme "' . $name . '" does not exist. Available: ' . implode(', ', self::packageThemes()) . '.');
        }

        return $path;
    }

    /** @return string[] Names of the themes shipped with Desk besides 00_desk. */
    public static function packageThemes(): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 2) . '/themes/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (basename($directory) !== '00_desk') {
                $names[] = basename($directory);
            }
        }

        return $names;
    }

    public function mediaRootName(): string
    {
        return (string) $this->get('mediaRoot', 'media');
    }

    /**
     * Absolute path of the media folder: the assetRoots entry named by
     * mediaRoot when the project declares one, otherwise dataPath/media.
     */
    public function mediaPath(): string
    {
        $assetRoots = ConfigService::getInstance()->getValue('[assetRoots]');
        $name = $this->mediaRootName();

        if (is_array($assetRoots) && isset($assetRoots[$name]) && is_string($assetRoots[$name])) {
            return FileService::virtualRealpath($this->projectRoot . '/' . $assetRoots[$name]);
        }

        return $this->dataPath() . '/' . $name;
    }

    /**
     * The time zone to run in: desk.timezone, else the system's, else UTC.
     */
    public function timezone(): string
    {
        $configured = $this->get('timezone');
        if (is_string($configured) && $configured !== '' && in_array($configured, \DateTimeZone::listIdentifiers(), true)) {
            return $configured;
        }

        $link = @readlink('/etc/localtime');
        if (is_string($link) && preg_match('#zoneinfo/(.+)$#', $link, $m) && in_array($m[1], \DateTimeZone::listIdentifiers(), true)) {
            return $m[1];
        }

        return date_default_timezone_get() ?: 'UTC';
    }

    public function revisions(): int
    {
        return max(0, (int) $this->get('revisions', 50));
    }

    /** @return array<string, string> */
    public function actions(): array
    {
        $actions = $this->get('actions', []);
        if (!is_array($actions)) {
            return [];
        }

        $result = [];
        foreach ($actions as $name => $command) {
            if (is_string($command) && trim($command) !== '') {
                $result[(string) $name] = $command;
            }
        }

        return $result;
    }

    /**
     * Base URL of the served site for preview links, without trailing slash.
     */
    public function previewUrl(): string
    {
        $configured = $this->get('previewUrl');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $core = ConfigService::getInstance();
        $host = (string) ($core->getValue('[serve][host]') ?: 'localhost');
        $port = (int) ($core->getValue('[serve][port]') ?: 8080);

        return 'http://' . $host . ':' . $port;
    }

    /**
     * Create the media folder when it is declared below dataPath and missing,
     * so the core's asset root check passes on a fresh project. Nothing
     * happens for a folder elsewhere: that is a project decision.
     */
    public function ensureMediaFolder(): void
    {
        $mediaPath = $this->mediaPath();
        $projectRoot = FileService::getProjectRootRealPath();

        if (is_dir($mediaPath) || !FileService::isInside($mediaPath, $projectRoot) || !FileService::isInside($mediaPath, $this->dataPath())) {
            return;
        }

        @mkdir($mediaPath, 0777, true);
    }

    // ------------------------------------------------------- validation ---

    /**
     * Refuse a configuration under which Desk would keep state outside the
     * project or inside a folder the build owns.
     *
     * @throws DeskException
     */
    public function validate(): void
    {
        if ($this->validated) {
            return;
        }

        $dataPathValue = (string) $this->get('dataPath', '');
        if (trim($dataPathValue, '/\\ ') === '' || FileService::isAbsolutePath($dataPathValue)) {
            throw new DeskException('desk.dataPath must be a non-empty path relative to the project root, got "' . $dataPathValue . '".');
        }

        $projectRoot = FileService::getProjectRootRealPath();
        $dataPath = $this->dataPath();

        if ($dataPath === $projectRoot || !FileService::isInside($dataPath, $projectRoot)) {
            throw new DeskException('desk.dataPath "' . $dataPathValue . '" is the project directory itself or lies outside it. It must be a sub-directory of the project.');
        }

        $paths = ProjectPathsService::getInstance();
        $paths->validate();

        foreach (ProjectPathsService::DIRECTORY_KEYS as $key) {
            $other = $paths->getLexicalPath($key);
            $this->assertNoOverlap('desk.dataPath', $dataPath, $key, $other);
        }

        foreach (['typesPath', 'fieldsPath', 'formsPath', 'themesPath', 'agentPath'] as $key) {
            $value = (string) $this->get($key, '');
            if (trim($value, '/\\ ') === '' || FileService::isAbsolutePath($value)) {
                throw new DeskException('desk.' . $key . ' must be a non-empty path relative to the project root.');
            }
            $absolute = $this->absolute($value);
            if ($absolute === $projectRoot || !FileService::isInside($absolute, $projectRoot)) {
                throw new DeskException('desk.' . $key . ' "' . $value . '" must lie inside the project.');
            }
        }

        $this->packageThemePath();

        $this->validated = true;
    }

    /**
     * The media folder must be a declared assetRoot below dataPath. Checked
     * separately, because a build does not need it, but uploads do.
     *
     * @throws DeskException
     */
    public function validateMediaRoot(): void
    {
        $this->validate();

        $name = $this->mediaRootName();
        $assetRoots = ConfigService::getInstance()->getValue('[assetRoots]');

        if (!is_array($assetRoots) || !isset($assetRoots[$name])) {
            throw new DeskException(sprintf(
                'desk.mediaRoot "%s" is not declared in assetRoots. Add \'assetRoots\' => [\'%s\' => \'%s/%s\'] to freezed.config.php so templates can reach uploads with context="%s".',
                $name,
                $name,
                trim((string) $this->get('dataPath'), '/'),
                $name,
                $name
            ));
        }

        $mediaPath = $this->mediaPath();
        if (!FileService::isInside($mediaPath, $this->dataPath())) {
            throw new DeskException(sprintf(
                'assetRoots.%s ("%s") must lie below desk.dataPath ("%s").',
                $name,
                $assetRoots[$name],
                $this->get('dataPath')
            ));
        }

        if (!is_dir($mediaPath)) {
            if (!@mkdir($mediaPath, 0777, true) && !is_dir($mediaPath)) {
                throw new DeskException('Could not create the media folder ' . $mediaPath . '.');
            }
        }

        // Let the core check the root the way it does for a build.
        AssetRootService::getInstance()->reset();
        AssetRootService::getInstance()->getRoot($name);
    }

    private function assertNoOverlap(string $keyA, string $pathA, string $keyB, string $pathB): void
    {
        $candidatesA = array_unique([$pathA, realpath($pathA) ?: $pathA]);
        $candidatesB = array_unique([$pathB, realpath($pathB) ?: $pathB]);

        foreach ($candidatesA as $a) {
            foreach ($candidatesB as $b) {
                if (FileService::isInside($a, $b) || FileService::isInside($b, $a)) {
                    throw new DeskException(sprintf(
                        '%s (%s) overlaps %s (%s) in freezed.config.php. Desk keeps its data in a folder of its own that no build reads from or empties.',
                        $keyA,
                        $pathA,
                        $keyB,
                        $pathB
                    ));
                }
            }
        }
    }

    private function absolute(string $relative): string
    {
        return FileService::virtualRealpath($this->projectRoot . '/' . $relative);
    }
}
