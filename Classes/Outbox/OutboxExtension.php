<?php

namespace Neuedaten\FreezedDesk\Outbox;

use Neuedaten\FreezedDesk\Agent\AgentGuide;
use Neuedaten\FreezedDesk\Agent\AgentSection;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Extension\AbstractExtension;
use Neuedaten\FreezedDesk\Web\Controllers\OutboxController;
use Neuedaten\FreezedDesk\Web\Router;

/**
 * The outbox as a module of Desk: active when desk.outbox is configured.
 * Adds the outbox page with the "Senden" button, the overview panel, its
 * part of the agent guide and of `desk:status`.
 */
final class OutboxExtension extends AbstractExtension
{
    public function name(): string
    {
        return 'outbox';
    }

    public function enabled(DeskContext $context): bool
    {
        return Outbox::isConfigured($context);
    }

    public function agentSections(DeskContext $context): array
    {
        $bin = AgentGuide::binary($context);
        $outbox = new Outbox($context);
        $config = $outbox->config();
        $rules = [
            'Never push the outbox: "Senden" in the desk UI is a person\'s step' . ($config['push'] === 'ui' ? ' (the CLI refuses it).' : '.'),
            'Read results with outbox:status; pull is safe.',
        ];

        return [new AgentSection('outbox', 'Outbox', implode("\n", [
            '## Outbox',
            '',
            'Approved records of ' . implode(', ', array_map(static fn (string $t): string => '`' . $t . '`', $config['types'])) . ' become messages that a server publishes at their time. Desk keeps the state of every message:',
            '',
            '- `pending` known, not on the server; `queued` on the server, waiting for its time; `sent` published (with URL);',
            '- `failed` the platform refused it (see the error); `unknown` the server cannot tell whether it went out -- a person checks and decides; `withdrawn` taken back before its time.',
            '',
            'A message whose record changes after it was sent is not sent again ("already published, the change has no effect"). A message that was not pushed before its time stays out; the gap remains.',
            '',
            '```bash',
            $bin . 'outbox:status [--plan]     # every message and what a push would do now',
            $bin . 'outbox:push --dry-run      # what "Senden" would push (the push itself is a person\'s step)',
            $bin . 'outbox:pull                # results into the records (system fields only)',
            '```',
        ]), source: 'desk:outbox', topics: ['outbox', 'social'], commands: [
            ['command' => $bin . 'outbox:status [--plan]', 'description' => 'State of every message.'],
            ['command' => $bin . 'outbox:push --dry-run', 'description' => 'What a push would do.'],
            ['command' => $bin . 'outbox:pull', 'description' => 'Fetch results.'],
        ], rules: $rules)];
    }

    public function routes(Router $router): void
    {
        $router->add('GET', '/outbox', [OutboxController::class, 'index'], 'outbox:status');
        $router->add('POST', '/outbox/push', [OutboxController::class, 'push'], 'ui:sending is a person\'s step (B2.8); the CLI has outbox:push --dry-run');
        $router->add('POST', '/outbox/pull', [OutboxController::class, 'pull'], 'outbox:pull');
        $router->add('POST', '/outbox/resolve', [OutboxController::class, 'resolve'], 'ui:deciding about an unknown message is a person\'s step (B3.4)');
    }

    public function navigation(DeskContext $context): array
    {
        $counts = (new OutboxRepository($context))->counts();
        $problems = $counts['failed'] + $counts['unknown'];

        return [['label' => $context->t('outbox.title'), 'href' => '/outbox', 'badge' => $problems > 0 ? $problems : null, 'level' => $problems > 0 ? 'error' : '']];
    }

    public function dashboardPanels(DeskContext $context): array
    {
        $outbox = new Outbox($context);
        $status = $outbox->status();
        $problems = array_values(array_filter($status['messages'], static fn (array $m): bool => in_array($m['state'], ['failed', 'unknown'], true)));
        $late = is_array($status['health']) && (($status['health']['timer']['late'] ?? false) === true);
        $expiring = [];
        foreach ((array) ($status['health']['channels'] ?? []) as $name => $channel) {
            $expires = is_array($channel) ? ($channel['tokenExpiresAt'] ?? null) : null;
            if (is_string($expires) && strtotime($expires) !== false && strtotime($expires) < time() + 14 * 86400) {
                $expiring[] = ['channel' => (string) $name, 'expiresAt' => $expires];
            }
        }

        return [['partial' => 'Outbox/DashboardPanel', 'variables' => [
            'outboxCounts' => $status['counts'],
            'outboxProblems' => $problems,
            'outboxLate' => $late,
            'outboxHealth' => $status['health'],
            'outboxExpiring' => $expiring,
            'outboxLastPush' => $status['lastPush'],
        ]]];
    }

    public function status(DeskContext $context): array
    {
        $status = (new Outbox($context))->status();

        return [
            'counts' => $status['counts'],
            'problems' => array_values(array_map(
                static fn (array $m): array => ['key' => $m['key'], 'state' => $m['state'], 'error' => $m['error']],
                array_filter($status['messages'], static fn (array $m): bool => in_array($m['state'], ['failed', 'unknown'], true))
            )),
            'lastPush' => $status['lastPush'],
            'lastPull' => $status['lastPull'],
            'serverLate' => (bool) ($status['health']['timer']['late'] ?? false),
        ];
    }
}
