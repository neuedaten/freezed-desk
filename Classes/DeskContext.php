<?php

namespace Neuedaten\FreezedDesk;

use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\FreezedDesk\Config\DeskConfig;
use Neuedaten\FreezedDesk\Export\ItemExporter;
use Neuedaten\FreezedDesk\Export\Markdown;
use Neuedaten\FreezedDesk\Inbox\FormLoader;
use Neuedaten\FreezedDesk\Inbox\InboxRepository;
use Neuedaten\FreezedDesk\Media\MediaRepository;
use Neuedaten\FreezedDesk\Schema\FieldTypeRegistry;
use Neuedaten\FreezedDesk\Schema\SchemaLoader;
use Neuedaten\FreezedDesk\Storage\Database;
use Neuedaten\FreezedDesk\Storage\Repository;

/**
 * Everything Desk needs at runtime, built lazily from one DeskConfig: the
 * schema loader, the database, the repositories, the exporter.
 *
 * There is one booted context per process (get()), used by the CLI, the web
 * UI and DeskSource inside a build. A build opens the database read-only, so
 * a build never writes.
 */
final class DeskContext
{
    private static ?self $instance = null;

    private ?FieldTypeRegistry $fieldTypes = null;
    private ?SchemaLoader $schemas = null;
    private ?Database $database = null;
    private ?Repository $repository = null;
    private ?MediaRepository $media = null;
    private ?ItemExporter $exporter = null;
    private ?Markdown $markdown = null;
    private ?Translator $translator = null;
    private ?FormLoader $forms = null;
    private ?InboxRepository $inbox = null;

    /**
     * @param string[] $builtTypes Slugs with a contentTypes entry in the core config.
     */
    private function __construct(
        public readonly DeskConfig $config,
        public readonly bool $readOnly,
        private readonly array $builtTypes,
    ) {
    }

    /**
     * Boot the process-wide context. Validation (dataPath inside the project
     * and so on) is skipped only for the throwaway context Desk::variables()
     * uses while freezed.config.php is still being loaded.
     */
    public static function boot(DeskConfig $config, bool $readOnly = false, bool $validate = true): self
    {
        if ($validate) {
            $config->validate();
        }

        date_default_timezone_set($config->timezone());

        return self::$instance = new self($config, $readOnly, self::builtTypesFromCore());
    }

    /**
     * The booted context, or one booted from the core's configuration on
     * first use (this is how DeskSource finds its way in a build).
     */
    public static function get(bool $readOnly = false): self
    {
        return self::$instance ??= self::boot(DeskConfig::fromCore(), $readOnly);
    }

    public static function isBooted(): bool
    {
        return self::$instance !== null;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * A context that is not registered as the process-wide instance.
     */
    public static function detached(DeskConfig $config, bool $readOnly, array $builtTypes = []): self
    {
        return new self($config, $readOnly, $builtTypes);
    }

    /** @return string[] */
    public static function builtTypesFromCore(): array
    {
        $contentTypes = ConfigService::getInstance()->getValue('[contentTypes]');

        return is_array($contentTypes) ? array_map('strval', array_keys($contentTypes)) : [];
    }

    /** @return string[] */
    public function builtTypes(): array
    {
        return $this->builtTypes;
    }

    /** @return array<string, mixed>|null The core's contentTypes.<type> entry. */
    public function contentTypeConfig(string $type): ?array
    {
        $config = ConfigService::getInstance()->getValue('[contentTypes][' . $type . ']');

        return is_array($config) ? $config : null;
    }

    public function fieldTypes(): FieldTypeRegistry
    {
        if ($this->fieldTypes === null) {
            $this->fieldTypes = new FieldTypeRegistry();
            foreach ((array) $this->config->get('fieldTypes', []) as $class) {
                if (is_string($class) && class_exists($class)) {
                    $this->fieldTypes->register(new $class());
                }
            }
        }

        return $this->fieldTypes;
    }

    public function schemas(): SchemaLoader
    {
        return $this->schemas ??= new SchemaLoader($this->config, $this->fieldTypes(), $this->builtTypes);
    }

    public function database(): Database
    {
        return $this->database ??= new Database($this->config->databasePath(), $this->readOnly);
    }

    public function repository(): Repository
    {
        return $this->repository ??= new Repository($this);
    }

    public function media(): MediaRepository
    {
        return $this->media ??= new MediaRepository($this);
    }

    public function exporter(): ItemExporter
    {
        return $this->exporter ??= new ItemExporter($this);
    }

    public function markdown(): Markdown
    {
        return $this->markdown ??= new Markdown();
    }

    public function translator(): Translator
    {
        return $this->translator ??= new Translator((string) $this->config->get('locale', 'de'));
    }

    public function t(string $key, array $params = []): string
    {
        return $this->translator()->t($key, $params);
    }

    public function forms(): FormLoader
    {
        return $this->forms ??= new FormLoader($this);
    }

    public function inbox(): InboxRepository
    {
        return $this->inbox ??= new InboxRepository($this);
    }
}
