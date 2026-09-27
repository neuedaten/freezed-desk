<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Desk;

use Neuedaten\FreezedDesk\Cli;
use Neuedaten\FreezedDesk\Tests\Support\DeskTestCase;
use Neuedaten\FreezedDesk\Web\App;

/**
 * A2 (agent guide from project files) and A1.13 (every UI route has a CLI
 * command).
 */
final class GuideAndRoutesTest extends DeskTestCase
{
    public function testEveryUiRouteHasACliCommand(): void
    {
        $this->writeConfig(['outbox' => ['url' => 'https://example.org/api/v1/outbox', 'types' => [], 'mapper' => 'X']]);
        $this->type('notes', "<?php return ['fields' => ['title' => ['type' => 'text']]];");
        $app = new App($this->context());

        $routes = $app->router->routes();
        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            $label = $route['method'] . ' ' . $route['pattern'];
            self::assertNotSame('', $route['cli'], $label . ' names no CLI command.');
            if (str_starts_with($route['cli'], 'ui:')) {
                self::assertGreaterThan(8, strlen($route['cli']), $label . ' must say why it is UI only.');
                continue;
            }
            self::assertArrayHasKey($route['cli'], Cli::COMMANDS, $label . ' names the unknown command ' . $route['cli']);
        }
    }

    public function testComposerRegistersEveryCommand(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $registered = $composer['extra']['freezed']['commands'];
        foreach (Cli::COMMANDS as $name => $class) {
            $key = $name === 'serve' ? 'desk' : 'desk:' . $name;
            self::assertSame($class, $registered[$key] ?? null, $key . ' is missing in composer.json extra.freezed.commands');
        }
    }

    public function testProjectFilesJoinTheGuideAndTopicsPrintTheirTypes(): void
    {
        $this->type('entries', "<?php return ['label' => 'Einträge', 'fields' => ['title' => ['type' => 'text'], 'notes' => ['type' => 'textarea', 'internal' => true]]];");
        $this->type('posts', "<?php return ['label' => 'Posts', 'approval' => 'ui', 'fields' => ['title' => ['type' => 'text']]];");
        file_put_contents($this->root . '/desk/agent/social.md', "---\ntypes: [posts]\n---\n\n# Social\n\nDu-Form.\n");
        file_put_contents($this->root . '/desk/agent/entries.md', "---\ntypes: [entries, spots]\n---\n\n# Einträge\n\nKeine Preise.\n");

        $full = $this->desk('agent')['output'];
        self::assertStringContainsString('# Einträge', $full);
        self::assertStringContainsString('# Social', $full);
        self::assertLessThan(strpos($full, '# Social'), strpos($full, '# Einträge'));
        self::assertStringContainsString('Never approve', $full);
        self::assertStringContainsString('DESK_ACTOR=agent', $full);

        $topic = $this->desk('agent', ['social'])['output'];
        self::assertStringContainsString('Du-Form.', $topic);
        self::assertStringContainsString('### `posts`', $topic);
        self::assertStringContainsString('Approval by a person', $topic);
        self::assertStringNotContainsString('### `entries`', $topic);
        self::assertStringNotContainsString('Keine Preise', $topic);

        $json = $this->desk('agent', ['social'], ['--json'])['json'];
        self::assertContains('project:social', array_column($json['sections'], 'id'));
        self::assertNotEmpty($json['commands']);
        self::assertNotEmpty($json['rules']);

        self::assertSame(['entries', 'social'], $this->desk('agent', [], ['--topics'])['json']['topics']);
        self::assertSame(1, $this->desk('agent', ['nope'])['exit']);
    }
}
