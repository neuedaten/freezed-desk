<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server;

use DeskOutbox\AdapterContext;
use DeskOutbox\AdapterRegistry;
use DeskOutbox\Adapters\LogAdapter;
use DeskOutbox\Adapters\MailAdapter;
use Neuedaten\FreezedDesk\Tests\Server\Support\FakeHttpClient;
use Neuedaten\FreezedDesk\Tests\Server\Support\MemoryState;
use PHPUnit\Framework\TestCase;

final class AdapterRegistryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/desk-outbox-registry-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    private function context(): AdapterContext
    {
        return new AdapterContext(new FakeHttpClient(), new MemoryState(), static function (string $line): void {
        });
    }

    public function testBuiltInAdaptersAndSettingsWithoutAdapterKey(): void
    {
        $registry = new AdapterRegistry(['channels' => [
            'test' => ['adapter' => 'log', 'file' => '/tmp/x'],
            'whatsapp' => ['adapter' => 'mail', 'to' => 'a@example.org'],
        ]], $this->context());

        self::assertSame(['test', 'whatsapp'], $registry->channels());
        self::assertInstanceOf(LogAdapter::class, $registry->forChannel('test'));
        self::assertInstanceOf(MailAdapter::class, $registry->forChannel('whatsapp'));
        self::assertSame($registry->forChannel('test'), $registry->forChannel('test'));
        self::assertSame('mail', $registry->adapterName('whatsapp'));
    }

    public function testAdapterFromPathByNamingScheme(): void
    {
        $class = 'FancyThing' . bin2hex(random_bytes(3));
        $name = strtolower('fancy-thing' . substr($class, 10));
        $file = ucfirst('fancy') . 'Thing' . substr($class, 10) . 'Adapter.php';
        file_put_contents($this->directory . '/' . $file, $this->adapterSource('DeskOutbox\\Adapters', ucfirst('fancy') . 'Thing' . substr($class, 10) . 'Adapter'));

        $registry = new AdapterRegistry([
            'adapterPaths' => [$this->directory],
            'channels' => ['fancy' => ['adapter' => $name, 'answer' => 42]],
        ], $this->context());
        $adapter = $registry->forChannel('fancy');
        self::assertSame(['answer' => 42], $adapter->health()['settings']);
    }

    public function testExplicitAdapterClassAndFile(): void
    {
        $class = 'Custom' . bin2hex(random_bytes(3)) . 'Adapter';
        file_put_contents($this->directory . '/custom.php', $this->adapterSource('Vendor\\Outbox', $class));
        $registry = new AdapterRegistry([
            'adapters' => ['custom' => ['class' => 'Vendor\\Outbox\\' . $class, 'file' => $this->directory . '/custom.php']],
            'channels' => ['c' => ['adapter' => 'custom']],
        ], $this->context());
        self::assertSame('Vendor\\Outbox\\' . $class, $registry->forChannel('c')::class);
    }

    public function testUnknownAdapterAndChannel(): void
    {
        $registry = new AdapterRegistry(['adapterPaths' => [$this->directory], 'channels' => ['x' => ['adapter' => 'nothing-here']]], $this->context());
        try {
            $registry->forChannel('x');
            self::fail('Expected an exception');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Unknown adapter "nothing-here"', $exception->getMessage());
            self::assertStringContainsString('NothingHereAdapter.php', $exception->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $registry->forChannel('missing');
    }

    public function testInvalidAdapterName(): void
    {
        $registry = new AdapterRegistry(['channels' => ['x' => ['adapter' => '../etc/passwd']]], $this->context());
        $this->expectException(\InvalidArgumentException::class);
        $registry->forChannel('x');
    }

    private function adapterSource(string $namespace, string $class): string
    {
        return <<<PHP
            <?php
            declare(strict_types=1);
            namespace {$namespace};
            final class {$class} implements \\DeskOutbox\\ChannelAdapter
            {
                public function __construct(private readonly array \$settings, private readonly \\DeskOutbox\\AdapterContext \$context) {}
                public function validate(\\DeskOutbox\\Message \$message): array { return []; }
                public function publish(\\DeskOutbox\\Message \$message, \\DeskOutbox\\AssetUrls \$assets): \\DeskOutbox\\Result { return new \\DeskOutbox\\Result('x'); }
                public function metrics(string \$remoteId): array { return []; }
                public function health(): array { return ['ok' => true, 'message' => '', 'tokenExpiresAt' => null, 'settings' => \$this->settings]; }
            }
            PHP;
    }
}
