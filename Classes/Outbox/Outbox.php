<?php

namespace Neuedaten\FreezedDesk\Outbox;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Storage\Database;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * The outbox (B2): Desk pushes messages of approved records to a server,
 * which publishes them on time; Desk pulls the results back into the
 * records.
 *
 *     'outbox' => [
 *         'url' => 'https://example.org/api/v1/outbox',
 *         'tokenEnv' => 'DESK_OUTBOX_TOKEN',
 *         'types' => ['posts'],
 *         'mapper' => Vendor\Social\PostsMapper::class,
 *         'push' => 'ui',          // "ui": only the "Senden" button pushes; "any": the CLI may too
 *     ],
 *
 * push() is idempotent: new or changed messages are uploaded (assets
 * first), messages whose record is no longer approved are withdrawn,
 * messages the server has already sent are never pushed again. pull()
 * only writes system fields.
 */
final class Outbox
{
    private ?OutboxMapperInterface $mapper = null;

    private ?OutboxRepository $repository = null;

    public function __construct(
        private readonly DeskContext $context,
        private readonly ?OutboxTransport $transport = null,
    ) {
    }

    public static function isConfigured(DeskContext $context): bool
    {
        $config = $context->config->get('outbox');

        return is_array($config) && !empty($config['url']);
    }

    /** @return array{url: string, tokenEnv: string, types: string[], mapper: string, push: string} */
    public function config(): array
    {
        $config = $this->context->config->get('outbox');
        if (!is_array($config) || empty($config['url'])) {
            throw new DeskException('The outbox is not configured: add desk.outbox with url, tokenEnv, types and mapper (docs/outbox.md).');
        }
        $url = (string) $config['url'];
        $host = (string) parse_url($url, PHP_URL_HOST);
        // Only HTTPS carries the token (N3); plain HTTP only to this machine, for tests.
        if (!str_starts_with($url, 'https://') && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new DeskException('desk.outbox.url must use https:// (the token travels with every request).');
        }
        $push = (string) ($config['push'] ?? 'ui');
        if (!in_array($push, ['ui', 'any'], true)) {
            throw new DeskException('desk.outbox.push must be "ui" or "any".');
        }

        return [
            'url' => (string) $config['url'],
            'tokenEnv' => (string) ($config['tokenEnv'] ?? 'DESK_OUTBOX_TOKEN'),
            'types' => array_values(array_map('strval', (array) ($config['types'] ?? []))),
            'mapper' => (string) ($config['mapper'] ?? ''),
            'push' => $push,
        ];
    }

    public function repository(): OutboxRepository
    {
        return $this->repository ??= new OutboxRepository($this->context);
    }

    public function mapper(): OutboxMapperInterface
    {
        if ($this->mapper !== null) {
            return $this->mapper;
        }
        $class = $this->config()['mapper'];
        if ($class === '' || !class_exists($class)) {
            throw new DeskException(sprintf('desk.outbox.mapper "%s" is not a class.', $class));
        }
        $mapper = new $class();
        if (!$mapper instanceof OutboxMapperInterface) {
            throw new DeskException(sprintf('desk.outbox.mapper "%s" must implement %s.', $class, OutboxMapperInterface::class));
        }

        return $this->mapper = $mapper;
    }

    public function client(): OutboxClient
    {
        $config = $this->config();
        $token = (string) getenv($config['tokenEnv']);
        if ($token === '') {
            throw new DeskException(sprintf('The outbox token is empty: set the environment variable %s (never in freezed.config.php).', $config['tokenEnv']));
        }

        return new OutboxClient($config['url'], $token, $this->transport ?? new CurlTransport());
    }

    /**
     * Pushing is a person's step (B2.8): with push: 'ui', only the UI may
     * push. A dry run is always allowed.
     */
    public function assertMayPush(): void
    {
        if ($this->config()['push'] === 'ui' && !$this->context->actor()->isHuman()) {
            throw new DeskException($this->context->t('outbox.pushUiOnly'));
        }
    }

    /**
     * What a push would do, message by message:
     *   push       new or changed, will be uploaded
     *   unchanged  on the server as it is
     *   withdraw   on the server, but its record is no longer approved
     *   late       the message's time has passed before it was pushed: it stays out (the gap remains)
     *   frozen     already published or in the balance on the server; a change has no effect any more
     *   problem    refused by the channel's rules; fix the record
     *
     * @return array<int, array{action: string, key: string, channel: string, at: string, item: Item|null, message: OutboxMessage|null, row: array<string, mixed>|null, problems: string[]}>
     */
    public function plan(): array
    {
        $config = $this->config();
        $mapper = $this->mapper();
        $repository = $this->repository();
        $now = new \DateTimeImmutable('now');
        $plan = [];
        $current = [];

        foreach ($config['types'] as $type) {
            foreach ($this->context->repository()->find($type)->published()->all() as $item) {
                foreach ($mapper->messages($item, $this->context) as $message) {
                    $current[$message->key] = true;
                    $row = $repository->get($message->key);
                    $entry = [
                        'key' => $message->key, 'channel' => $message->channel, 'at' => $message->at->format(\DateTimeInterface::ATOM),
                        'item' => $item, 'message' => $message, 'row' => $row, 'problems' => [],
                    ];

                    if ($row !== null && in_array($row['state'], OutboxRepository::FINAL, true)) {
                        $plan[] = ['action' => $row['hash'] === $message->hash() ? 'unchanged' : 'frozen'] + $entry;
                        continue;
                    }
                    // Queued as it is, or refused by the platform and not changed since: pushing again would change nothing.
                    if ($row !== null && in_array($row['state'], ['queued', 'failed'], true) && $row['hash'] === $message->hash()) {
                        $plan[] = ['action' => 'unchanged'] + $entry;
                        continue;
                    }
                    if ($message->at <= $now) {
                        $plan[] = ['action' => 'late'] + $entry;
                        continue;
                    }
                    $problems = self::channelProblems($message);
                    $plan[] = ['action' => $problems === [] ? 'push' : 'problem', 'problems' => $problems] + $entry;
                }
            }
        }

        // Messages on the server whose record is gone, not approved any
        // more, or no longer has that channel.
        foreach ($repository->all(['queued', 'failed']) as $row) {
            if (isset($current[$row['key']])) {
                continue;
            }
            $plan[] = [
                'action' => 'withdraw',
                'key' => $row['key'], 'channel' => $row['channel'], 'at' => $row['at'],
                'item' => $row['itemId'] !== null ? $this->context->repository()->get($row['itemId']) : null,
                'message' => null, 'row' => $row, 'problems' => [],
            ];
        }

        usort($plan, static fn (array $a, array $b): int => [$a['at'], $a['key']] <=> [$b['at'], $b['key']]);

        return $plan;
    }

    /**
     * Push new and changed messages, withdraw the ones no longer approved.
     *
     * @return array{pushed: string[], withdrawn: string[], unchanged: int, late: string[], frozen: string[], problems: array<string, string[]>, errors: array<string, string>, dryRun: bool}
     */
    public function push(bool $dryRun = false): array
    {
        if (!$dryRun) {
            $this->assertMayPush();
        }
        $plan = $this->plan();
        $summary = ['pushed' => [], 'withdrawn' => [], 'unchanged' => 0, 'late' => [], 'frozen' => [], 'problems' => [], 'errors' => [], 'dryRun' => $dryRun];
        $client = $dryRun ? null : $this->client();
        $repository = $this->repository();
        $uploaded = [];

        foreach ($plan as $entry) {
            $key = $entry['key'];
            switch ($entry['action']) {
                case 'unchanged':
                    $summary['unchanged']++;
                    break;
                case 'late':
                    $summary['late'][] = $key;
                    if (!$dryRun) {
                        $repository->upsert($key, $this->rowValues($entry) + ['notice' => $this->context->t('outbox.late')]);
                    }
                    break;
                case 'frozen':
                    $summary['frozen'][] = $key;
                    if (!$dryRun) {
                        $repository->upsert($key, ['notice' => $this->context->t('outbox.frozen')]);
                    }
                    break;
                case 'problem':
                    $summary['problems'][$key] = $entry['problems'];
                    if (!$dryRun) {
                        $repository->upsert($key, $this->rowValues($entry) + ['error' => implode(' ', $entry['problems'])]);
                    }
                    break;
                case 'withdraw':
                    if ($dryRun) {
                        $summary['withdrawn'][] = $key;
                        break;
                    }
                    try {
                        $answer = $client->withdraw($key);
                        if (!empty($answer['conflict'])) {
                            $repository->upsert($key, ['state' => (string) $answer['state'], 'notice' => $this->context->t('outbox.frozen')]);
                            $summary['frozen'][] = $key;
                        } else {
                            $repository->upsert($key, ['state' => 'withdrawn', 'error' => null]);
                            $summary['withdrawn'][] = $key;
                        }
                    } catch (DeskException $exception) {
                        $summary['errors'][$key] = $exception->getMessage();
                    }
                    break;
                case 'push':
                    /** @var OutboxMessage $message */
                    $message = $entry['message'];
                    if ($dryRun) {
                        $summary['pushed'][] = $key;
                        break;
                    }
                    try {
                        foreach ($message->assets as $asset) {
                            if (!isset($uploaded[$asset->sha256]) && !$client->assetExists($asset->sha256)) {
                                $client->putAsset($asset);
                            }
                            $uploaded[$asset->sha256] = true;
                        }
                        $answer = $client->postMessage($message);
                        if (!empty($answer['conflict'])) {
                            $repository->upsert($key, $this->rowValues($entry) + ['state' => (string) $answer['state'], 'notice' => $this->context->t('outbox.frozen')]);
                            $summary['frozen'][] = $key;
                        } elseif (isset($answer['problems'])) {
                            $repository->upsert($key, $this->rowValues($entry) + ['error' => implode(' ', $answer['problems'])]);
                            $summary['problems'][$key] = $answer['problems'];
                        } else {
                            $repository->upsert($key, $this->rowValues($entry) + [
                                'hash' => $message->hash(), 'state' => 'queued', 'pushed_at' => Database::now(), 'error' => null, 'notice' => null,
                            ]);
                            $summary['pushed'][] = $key;
                        }
                    } catch (DeskException $exception) {
                        $summary['errors'][$key] = $exception->getMessage();
                    }
                    break;
            }
        }

        if (!$dryRun) {
            $this->context->repository()->setSetting('outbox:lastPush', (string) json_encode(['at' => Database::now(), 'pushed' => count($summary['pushed']), 'withdrawn' => count($summary['withdrawn']), 'errors' => count($summary['errors'])]));
        }

        return $summary;
    }

    /**
     * Fetch results, write them into the records through the mapper
     * (system fields only, as "cli"), acknowledge them, and remember the
     * server's health for the overview (B2.6, B3.9).
     *
     * @return array{results: int, applied: string[], health: array<string, mixed>|null, dryRun: bool}
     */
    public function pull(bool $dryRun = false): array
    {
        $client = $this->client();
        $repository = $this->repository();
        $since = (int) $this->context->repository()->setting('outbox:since', '0');
        $results = $client->results($since);
        $applied = [];
        $ids = [];
        $previousActor = $this->context->actor();

        $apply = function () use ($results, $repository, &$applied, &$ids): void {
            $this->context->actAs(Actor::Cli);
            foreach ($results as $result) {
                $ids[] = $result->id;
                $row = $repository->get($result->key);
                $repository->upsert($result->key, [
                    'state' => $result->state,
                    'remote_id' => $result->remoteId !== '' ? $result->remoteId : ($row['remoteId'] ?? null),
                    'url' => $result->url !== '' ? $result->url : ($row['url'] ?? null),
                    'error' => $result->error !== '' ? $result->error : null,
                ] + ($row === null ? ['channel' => $result->channel, 'at' => $result->at] : []));

                $itemId = $row['itemId'] ?? null;
                $item = $itemId !== null ? $this->context->repository()->get($itemId) : null;
                if ($item === null) {
                    continue;
                }
                $fields = $this->mapper()->applyResult($item, $result);
                if ($fields !== []) {
                    $this->context->repository()->saveSystemFields($item->id, $fields, 'outbox');
                    $applied[] = $result->key;
                }
            }
        };

        try {
            if ($dryRun) {
                $this->context->dryRun($apply);
            } else {
                $apply();
            }
        } finally {
            $this->context->actAs($previousActor);
        }

        if (!$dryRun && $ids !== []) {
            $client->ack($ids);
            $this->context->repository()->setSetting('outbox:since', (string) max($ids));
        }

        $health = null;
        try {
            $health = $client->health();
            if (!$dryRun) {
                $this->context->repository()->setSetting('outbox:health', (string) json_encode(['at' => Database::now()] + $health));
            }
        } catch (DeskException $exception) {
            $health = ['ok' => false, 'error' => $exception->getMessage()];
            if (!$dryRun) {
                $this->context->repository()->setSetting('outbox:health', (string) json_encode(['at' => Database::now()] + $health));
            }
        }
        if (!$dryRun) {
            $this->context->repository()->setSetting('outbox:lastPull', Database::now());
        }

        return ['results' => count($results), 'applied' => $applied, 'health' => $health, 'dryRun' => $dryRun];
    }

    /**
     * A person's decision about a message in state "unknown" (B3.4), passed
     * to the server and recorded here.
     */
    public function resolve(string $key, string $state, string $url = ''): void
    {
        if (!in_array($state, ['sent', 'failed', 'queued'], true)) {
            throw new DeskException('A message is resolved as sent, failed or queued.');
        }
        $this->client()->resolve($key, $state, $url);
        $this->repository()->upsert($key, ['state' => $state, 'url' => $url !== '' ? $url : null, 'notice' => null]);
    }

    /**
     * The state of every message Desk knows, plus the last push, pull and
     * server health (B2.7).
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $health = json_decode((string) $this->context->repository()->setting('outbox:health', ''), true);
        $lastPush = json_decode((string) $this->context->repository()->setting('outbox:lastPush', ''), true);

        return [
            'counts' => $this->repository()->counts(),
            'messages' => $this->repository()->all(),
            'lastPush' => is_array($lastPush) ? $lastPush : null,
            'lastPull' => $this->context->repository()->setting('outbox:lastPull'),
            'health' => is_array($health) ? $health : null,
            'push' => $this->config()['push'],
        ];
    }

    /**
     * Problems of a message by the channel limits shared with the server
     * (server/outbox/lib/ChannelRules.php, B3.8).
     *
     * @return string[]
     */
    public static function channelProblems(OutboxMessage $message): array
    {
        self::loadChannelRules();
        $assets = [];
        foreach ($message->assets as $asset) {
            $assets[$asset->sha256] = ['mime' => $asset->mime, 'size' => $asset->size];
        }

        return array_map(static fn (array $p): string => $p['message'], \DeskOutbox\ChannelRules::check($message->channel, $message->payload, $assets));
    }

    /** The dependency-free rules file of the server part, shared with Desk and packages. */
    public static function loadChannelRules(): void
    {
        if (!class_exists(\DeskOutbox\ChannelRules::class, false)) {
            require_once dirname(__DIR__, 2) . '/server/outbox/lib/ChannelRules.php';
        }
    }

    /** @return array<string, mixed> */
    private function rowValues(array $entry): array
    {
        return [
            'item_id' => $entry['item']?->id,
            'channel' => $entry['channel'],
            'at' => $entry['at'],
        ];
    }
}
