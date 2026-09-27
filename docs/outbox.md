# Outbox module

Optional. The mirror image of the [inbox](inbox.md): Desk pushes messages
of approved records to an endpoint on the web server, a timer there
publishes each one at its time through a channel adapter, and Desk pulls
the results back into the records. Desk stays on the editor's machine; the
machine need not be awake when a post goes out.

```text
Desk                                    Server (server/outbox/)
────────────────────────────────        ───────────────────────────────────────
record published, time ahead
  └─ mapper → message(s)
outbox push ── PUT  …/outbox/assets/<sha256> ─►  file in mediaPath,
                                                 public at …/outbox/media/<sha256>.<ext>
            ── POST …/outbox/messages ────────►  table messages (queued)
            ── DELETE …/outbox/messages/<key> ►  withdrawn (only queued, failed)

                                                 timer: outbox-run.php
                                                   queued + due → sending
                                                   adapter → sent | failed | unknown

outbox pull ◄─ GET  …/outbox/results?since= ───  result events
            ── POST …/outbox/results/ack ─────►
  └─ mapper writes the result into the record (system fields)
```

`…` is the prefix of the project's API, e.g. `/api/v1`. The outbox is a
module of the existing API entry `api/index.php`, not an endpoint of its
own.

## 1. Desk: configuration

```php
'desk' => [
    'outbox' => [
        'url' => 'https://example.org/api/v1/outbox',
        'tokenEnv' => 'DESK_OUTBOX_TOKEN',
        'types' => ['posts'],
        'mapper' => \App\Desk\PostsMapper::class,
        'push' => 'ui',
    ],
],
```

| Key | Default | Meaning |
|---|---|---|
| `url` | | Base URL of the outbox module. Without it the module is off. Must be `https://`; plain `http://` only for `localhost`, `127.0.0.1` or `::1` |
| `tokenEnv` | `DESK_OUTBOX_TOKEN` | Environment variable holding the Bearer token |
| `types` | `[]` | Types whose published records produce messages |
| `mapper` | | Class implementing `OutboxMapperInterface` |
| `push` | `ui` | `ui`: only the "Send" button in the UI pushes; `any`: the CLI may push too |

The token is read from the environment only, never from
`freezed.config.php`, and is separate from the inbox token. Only Desk needs
it; an agent working through the CLI does not. The Desk side needs the PHP
extension `curl`.

When `desk.outbox` is set, the UI has an "Outbox" page, the overview a
panel for problems, `desk:agent` a section on the outbox and `desk:status`
an `extensions.outbox` part.

## 2. The mapper

The mapper turns a published record into messages and writes results back.
The project writes it, or a package such as freezed-desk-social brings one.

```php
namespace Neuedaten\FreezedDesk\Outbox;

interface OutboxMapperInterface
{
    /** @return OutboxMessage[] The messages of a published record; [] when it has nothing to send. */
    public function messages(Item $item, DeskContext $context): array;

    /** @return array<string, mixed> Fields to store for a result: system fields only, in stored shape. */
    public function applyResult(Item $item, OutboxResult $result): array;
}
```

A compact mapper for a type with the fields `text`, `at` (datetime),
`channels` (select, multiple), `image` (image) and `results` (json,
`system: true`):

```php
<?php

namespace App\Desk;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Outbox\OutboxAsset;
use Neuedaten\FreezedDesk\Outbox\OutboxMapperInterface;
use Neuedaten\FreezedDesk\Outbox\OutboxMessage;
use Neuedaten\FreezedDesk\Outbox\OutboxResult;
use Neuedaten\FreezedDesk\Storage\Item;

final class PostsMapper implements OutboxMapperInterface
{
    public function messages(Item $item, DeskContext $context): array
    {
        $assets = [];
        $media = [];
        if (is_int($item->data['image'] ?? null)) {
            $file = $context->media()->require($item->data['image']);
            $asset = OutboxAsset::fromFile($context->media()->absolutePath($file));
            $assets[] = $asset;
            $media[] = ['sha256' => $asset->sha256, 'mime' => $asset->mime, 'role' => 'image', 'alt' => $file->alt];
        }

        $messages = [];
        foreach ((array) ($item->data['channels'] ?? []) as $channel) {
            $messages[] = new OutboxMessage(
                key: 'posts/' . $item->slug . '#' . $channel,
                channel: (string) $channel,
                at: new \DateTimeImmutable((string) $item->data['at']),   // desk.timezone
                payload: ['kind' => $media === [] ? 'text' : 'image', 'text' => (string) $item->data['text'], 'media' => $media, 'lang' => 'de'],
                assets: $assets,
            );
        }

        return $messages;
    }

    public function applyResult(Item $item, OutboxResult $result): array
    {
        $results = is_array($item->data['results'] ?? null) ? $item->data['results'] : [];
        $results[$result->channel] = ['state' => $result->state, 'url' => $result->url, 'remoteId' => $result->remoteId];

        return ['results' => $results];
    }
}
```

`applyResult()` may return system fields only (`system: true`, see
[approval.md](approval.md)); anything else stops the pull with an error.
Writing system fields keeps an approved record approved.

### Messages

`OutboxMessage(string $key, string $channel, \DateTimeImmutable $at, array $payload, OutboxAsset[] $assets = [])`:

| Part | Meaning |
|---|---|
| `key` | Unique and stable, e.g. `posts/2027-05-06-serie-km-70#instagram`. Up to 300 characters, no control characters |
| `channel` | A channel configured on the server |
| `at` | When to publish; sent as ISO 8601 with its offset, stored in UTC on the server |
| `payload` | JSON for the channel's adapter, see below |
| `assets` | The files the payload references. `OutboxAsset::fromFile($path, $role = 'image')` hashes the file; JPEG, PNG and MP4 only |

The payload has the same shape on every channel; an adapter uses what it
needs:

| Key | Value |
|---|---|
| `kind` | `image`, `carousel`, `reel`, `story`, `text` or `link` |
| `text` | The full text as it is posted: caption, hashtags, credit, link |
| `media` | `[{sha256, mime, role, alt}]` in order; `role` is `image`, `video` or `cover` |
| `link` | `{url, title, description, thumb}` or null; `thumb` is a sha256 or null |
| `lang` | e.g. `de` |
| `options` | Channel-specific extras, e.g. `{"subject": "…"}` for the mail adapter |

Every sha256 used in `media` or `link.thumb` must be listed in `assets`.
The message's hash covers channel, time, payload and assets; a changed hash
means the server's copy is out of date.

## 3. States, push and pull

Desk keeps its own record of every message (table `outbox`):

| State | Meaning |
|---|---|
| `pending` | Known to Desk, not on the server |
| `queued` | On the server, waiting for its time |
| `sending` | The server is publishing it right now |
| `sent` | Published; the URL of the post when the platform has one |
| `failed` | The platform refused it, or the retries ran out; see the error |
| `unknown` | The server cannot tell whether it went out; a person decides |
| `withdrawn` | Taken back before its time |

### Push

A push first plans, message by message, over every published record of
`types`:

| Action | When |
|---|---|
| `push` | New, or changed (hash) while still waiting, and the time is ahead |
| `unchanged` | On the server as it is: `queued` or `failed` with the same hash, or sending, sent or unknown with the same hash |
| `withdraw` | `queued` or `failed` on the server, but the record is no longer published, gone, or no longer yields this message |
| `late` | Not pushed before its time: it stays out, the slot remains empty |
| `frozen` | Changed after it was sent, or while it is sending or unknown: the change has no effect any more |
| `problem` | Refused by the channel limits (`ChannelRules`); fix the record |

Then it uploads the assets of every `push` (only those the server does not
have yet), then the message, and withdraws what is to be withdrawn. What
the server has sent, is sending or reports as unknown is never pushed
again. A failed message is pushed again only when it changed (its hash)
and its time is still ahead; unchanged, pushing it again would change
nothing. A push is idempotent: a second one right after the first
changes nothing.

With `push: 'ui'` (the default) the CLI refuses `outbox push`; `--dry-run`
is always allowed and shows the plan. Pushing is a person's step, done with
"Send" on the outbox page.

```bash
vendor/bin/freezed-desk outbox:push --dry-run
```

```json
{
    "pushed": ["posts/herbst#bluesky", "posts/herbst#instagram"],
    "withdrawn": [],
    "unchanged": 3,
    "late": [],
    "frozen": [],
    "problems": {},
    "errors": {},
    "dryRun": true
}
```

In a dry run `pushed` and `withdrawn` list what would happen. A push exits
with 1 when a message could not be pushed (`errors`) or does not fit its
channel (`problems`).

### Pull

A pull fetches the result events since the last one, updates Desk's record
of each message, lets the mapper write the result into the record (as
`cli`, system fields only), acknowledges the events and remembers the last
id. It also asks the server for its health, which the overview and the
outbox page show. A pull is always allowed, safe to repeat and meant to run
on a timer, e.g. every 15 minutes with launchd:

```bash
DESK_OUTBOX_TOKEN=… vendor/bin/freezed-desk outbox:pull
```

```json
{"results": 2, "applied": ["posts/herbst#bluesky", "posts/herbst#instagram"], "health": {"ok": true, …}, "dryRun": false}
```

### Status

```bash
vendor/bin/freezed-desk outbox:status [--plan] [--remote]
```

prints `counts` per state, every message Desk knows (`key`, `itemId`,
`channel`, `at`, `hash`, `state`, `pushedAt`, `remoteId`, `url`, `error`,
`notice`, `updatedAt`), `lastPush`, `lastPull`, the last `health` and the
`push` setting. `--plan` adds what a push would do now, `--remote` the
server's own list of messages and its health. `outbox push|pull|status` is
the same as `outbox:push|pull|status`.

### The outbox page

"Outbox" in the sidebar, with a badge when messages failed or are unknown:

- "Goes out with Send": the plan without the unchanged messages, with the
  channel problems of each.
- "Send (n)" pushes, after a confirmation; it counts messages to push and
  to withdraw, and is disabled when there are none.
- "Fetch results" pulls.
- The state of every message, with the error, a notice (late, frozen) and a
  link to the published post.
- For an `unknown` message, "Decide": check on the platform, then "It is
  out" (`sent`, optionally with the address of the post), "It is not out"
  (`failed`) or "Send again" (`queued`). Deciding is a person's step and has
  no CLI command.
- A warning when the server's timer has not run for more than 30 minutes.

The overview shows failed and unknown messages, the late timer and tokens
that expire within 14 days.

## 4. The server

`server/outbox/` of the package is a reference: PHP without dependencies
(the extensions PDO SQLite, JSON and, for the webhook adapter, curl), a
SQLite database and a media folder outside the web root. Copy it next to
the API entry of the [inbox](inbox.md), so that it is `api/outbox/` (in a
Freezed project `static/api/outbox/`, which the build copies to
`public/api/outbox/`). The reference `api/index.php` hands every path below
`/outbox` to the module when its configuration has an `outbox` key:

```php
if (($path === '/outbox' || str_starts_with($path, '/outbox/')) && is_array($config['outbox'] ?? null)) {
    require_once __DIR__ . '/outbox/outbox.php';
    desk_outbox_handle($config['outbox'], $method, substr($path, 7) ?: '/');
}
```

An API entry of the project's own needs the same three lines.

### Configuration

The `outbox` key of the configuration that `api/index.php` loads
(`config.php` next to it), from `outbox/config.example.php`:

```php
'outbox' => [
    'token' => getenv('OUTBOX_TOKEN') ?: '',
    'database' => '/var/www/example/var/outbox.sqlite',
    'mediaPath' => '/var/www/example/var/outbox-media',
    'publicBase' => 'https://example.org/api/v1/outbox/media',
    'maxBytes' => 200 * 1024 * 1024,
    'retainDays' => 14,
    'mail' => ['to' => 'redaktion@example.org', 'from' => 'outbox@example.org'],
    'log' => '/var/www/example/var/outbox.log',
    'channels' => [
        'test' => ['adapter' => 'log', 'file' => '/var/www/example/var/outbox-sent.log'],
        'whatsapp' => ['adapter' => 'mail', 'to' => 'redaktion@example.org'],
    ],
],
```

| Key | Default | Meaning |
|---|---|---|
| `token` | `''` | Bearer token Desk sends. An empty token locks every protected route |
| `database` | required | SQLite file with messages, results, metrics and adapter state |
| `mediaPath` | required | Folder of the uploaded files. Outside the docroot and outside what a deploy replaces (`rsync --delete`), e.g. `var/` next to `public/` |
| `publicBase` | `''` | Public URL of the media route; the platforms fetch the files from there |
| `maxBytes` | 200 MB | Largest upload |
| `retainDays` | 14 | Days a file is kept after the last message using it was sent, failed or withdrawn |
| `mail` | `null` | `{to, from}` for mails about failed and unknown messages and expiring tokens; sent with PHP's `mail()` |
| `log` | `null` | Log file of the timer: one line per message, no tokens, no texts |
| `channels` | `[]` | Channel name as Desk uses it => `adapter` plus the adapter's settings. `rules` maps a differently named channel to the limits of another, e.g. `'rules' => 'instagram'` for `instagram-test` |
| `adapterPaths` | `[<module>/adapters]` | Folders searched for further adapters |
| `adapters` | `[]` | Adapters outside the naming scheme: `'custom' => ['class' => 'Vendor\\CustomAdapter', 'file' => '/path/CustomAdapter.php']` |

Keep tokens out of the repository: in the environment, or in a
configuration file outside git.

### Endpoints

All paths below `…/outbox`. Every route but `/media/…` needs
`Authorization: Bearer <token>`. `<key>` is URL-encoded (it contains `/`
and `#`); where the web server refuses encoded slashes, `DELETE
/messages?key=<key>` and `POST /messages/resolve?key=<key>` do the same.
Errors are `{"error": "…"}` with a matching status, sometimes with more
keys.

| Method | Path | Purpose |
|---|---|---|
| PUT | `/assets/<sha256>` | Upload a file |
| GET | `/assets/<sha256>` | Whether the server has a file |
| GET | `/media/<sha256>.<ext>` | The file for the platforms, no token |
| POST | `/messages` | Queue or replace a message |
| GET | `/messages?state=<state>` | The messages, without payloads |
| DELETE | `/messages/<key>` | Withdraw |
| POST | `/messages/<key>/resolve` | A person's decision |
| GET | `/results?since=<id>` | Result events |
| POST | `/results/ack` | Acknowledge events |
| GET | `/health` | Timer, queue and channels |
| GET | `/metrics?since=<ISO>` | Numbers of sent posts |

**PUT `/assets/<sha256>`**: the raw file as body, `Content-Type`
`image/jpeg`, `image/png` or `video/mp4`. The server streams it to disk,
recomputes the sha256 and checks that the content starts like the declared
type. `201 {"ok": true, "existed": false, "size": n}`, or `200` with
`"existed": true` when the file is there already. `400` invalid sha256,
`413 {"maxBytes"}` too large, `415 {"allowed"}` other type, `422` checksum
mismatch (`{"expected", "actual"}`) or content that does not match.

**GET `/assets/<sha256>`**: `{"exists": true, "size": n}`, or `404` with
`"exists": false`.

**GET `/media/<sha256>.<ext>`** (`jpg`, `png`, `mp4`): no token, the sha256
is not guessable. `Content-Type`, `Content-Length`, `Accept-Ranges`,
`ETag`, `X-Robots-Tag: noindex`; single byte ranges (`206`, `416`) for
video. HEAD works too.

**POST `/messages`**:

```json
{
    "key": "posts/herbst#instagram",
    "channel": "instagram",
    "at": "2027-05-06T18:30:00+02:00",
    "payload": {"kind": "image", "text": "…", "media": [{"sha256": "…", "mime": "image/jpeg", "role": "image", "alt": "…"}], "lang": "de"},
    "assets": [{"sha256": "…", "mime": "image/jpeg", "role": "image", "size": 482113}],
    "hash": "…"
}
```

`200 {"ok": true, "key", "state": "queued"}`. A message with the same key
is replaced while it is queued, failed or withdrawn; when it is sending,
sent or unknown the answer is `409 {"key", "state"}` and nothing changes.
`422` with `{"problems": [{"rule", "message"}]}` for an invalid key, an
unknown channel (plus `channels`), a time without zone, a payload that is
not an object, the adapter's own checks or the channel limits; `422
{"missing": [sha256, …]}` when files were not uploaded first. `400` for
invalid JSON.

**GET `/messages?state=`**: `{"messages": [{key, channel, at, state,
attempts, remoteId, url, error, updatedAt}]}`, optionally only one state.

**DELETE `/messages/<key>`**: `200 {"ok": true, "key", "state":
"withdrawn"}` for a queued or failed message (also when it was withdrawn
already), `404` when unknown, `409 {"state"}` when it is sending, sent or
unknown.

**POST `/messages/<key>/resolve`**: `{"state": "sent"|"failed"|"queued",
"url": "…", "remoteId": "…", "note": "…"}` (all but `state` optional; `url`
must be http or https). From `unknown` all three are allowed, from `failed`
only `queued` (a manual retry); otherwise `409`.

**GET `/results?since=<id>`**: `{"results": [{id, key, channel, state,
remoteId, url, error, at, updatedAt}]}`, unacknowledged events after `id`,
oldest first, at most 500. An event is written for every change a person or
Desk must know about: sent, failed, unknown, withdrawn, and every decision.
A retry after a temporary error writes none.

**POST `/results/ack`**: `{"ids": [1, 2]}` → `{"ok": true, "acknowledged":
n}`. Acknowledged events are deleted after 90 days.

**GET `/health`**:

```json
{
    "ok": true,
    "timer": {"lastRun": "2027-05-06T16:25:00+00:00", "minutesSince": 3, "late": false},
    "queue": {"queued": 4, "sending": 0, "failed": 0, "unknown": 0},
    "channels": {"test": {"adapter": "log", "ok": true, "message": "Log adapter, publishes nothing.", "tokenExpiresAt": null}}
}
```

`late` is true when the timer has not run for more than 30 minutes (or
never). The channels' health comes from the adapters and is cached for an
hour.

**GET `/metrics?since=<ISO>`**: `{"metrics": [{key, channel, remoteId,
url, sentAt, fetchedAt, values}]}`, the numbers the timer collected,
fetched at or after `since` (a date or date-time). `values` uses the keys
`reach`, `impressions`, `interactions`, `likes`, `comments`, `shares`,
`saves`, `linkClicks`, `views`, as far as the platform gives them. Entries
without numbers are left out.

### The timer

`outbox-run.php` does one pass:

1. Messages in `sending` whose lease (10 minutes) has run out become
   `unknown`, with a mail. They are never sent again automatically.
2. Due `queued` messages are claimed atomically (`sending`, lease 10
   minutes), checked again (files, adapter, channel limits) and handed to
   the adapter; the outcome is stored.
3. Channel health; a mail when a token expires within 14 days, at most one
   per channel and day.
4. Metrics of posts sent in the last 60 days, at most every 20 hours per
   post; pruning of old files and acknowledged events.
5. The time of the pass, for `/health`.

Each step catches its own errors. Only one pass runs at a time (a lock file
next to the database); a second one exits with a note. It prints one line
per message (key, channel, state, duration, error) and appends it to `log`.
Exit code 0 after a pass, also when single messages failed; 1 when the
configuration is missing.

It looks for its configuration in `private/config.php` of the folder three
levels above its own (next to the docroot when the module is
`public/api/outbox/`), then in `api/config.php`; `--config=<file>` names another file,
which may return the whole configuration or the outbox part alone. It runs
from the command line only; over the web it answers 404.

Run it every 5 minutes as the application's user, e.g. with systemd:

```ini
# /etc/systemd/system/example-outbox.service
[Unit]
Description=Outbox of example.org

[Service]
Type=oneshot
User=example
ExecStart=/usr/bin/php /var/www/example/public/api/outbox/outbox-run.php --config=/var/www/example/private/config.php
```

```ini
# /etc/systemd/system/example-outbox.timer
[Unit]
Description=Outbox of example.org every 5 minutes

[Timer]
OnCalendar=*:0/5
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
systemctl enable --now example-outbox.timer
```

Times are stored in UTC and shown in `desk.timezone`. A message goes out
with the first pass at or after its time, so up to 5 minutes late.

### Never twice

- A message is claimed with a lease before its adapter is called. A runner
  that dies while sending leaves the message in `sending`; after the lease
  it becomes `unknown`, never `queued` again, and the editors get a mail.
  Whether the post is out, a person decides on the outbox page.
- An adapter reports a failure as temporary (another attempt may help:
  network, HTTP 429, 5xx before the platform took the post) or not. Only
  temporary failures are retried: at most 3 attempts within 30 minutes,
  after 5, 10 and 15 minutes; then `failed`.
- A failure after which the platform may have taken the post (a timeout
  after the request went out) makes the message `unknown` at once; it is
  never retried.
- Sent, sending and unknown messages cannot be replaced or withdrawn, and
  Desk never pushes them again.

### Channel limits

`server/outbox/lib/ChannelRules.php` holds the limits per channel, without
dependencies, like `FormRules.php` for the inbox. Desk checks each message
with it before pushing (action `problem`), the server again when the
message is queued and right before sending, and packages can use it for
their own checks. It knows `instagram`, `facebook` and `bluesky` (text
length in characters or graphemes, hashtags, mentions, kinds of post,
number, type and size of files); channels without an entry have no limits.
Change a number there and every side applies it.

### Built-in adapters

| Adapter | Does | Settings |
|---|---|---|
| `log` | Publishes nothing, writes one line per message. For tests and trial runs | `file` (else the runner log); `fail`: `temporary`, `permanent` or `accepted` to simulate a failure; `failTimes`: fail only the first n attempts per message |
| `mail` | Sends the text and the files to an address, for channels without an API (a person forwards it) | `to`; `from` (else `mail.from`). Subject: `options.subject` of the payload, else `<channel>: <first line>` |
| `webhook` | POSTs the message as JSON to a URL, each asset with its public `url` | `url`; `secret` (adds `X-Outbox-Signature: sha256=<HMAC of the body>`); `timeout` (30 s). A 2xx answer means sent and may carry `id` and `url` of the post; 429 and 5xx are temporary |

### Writing an adapter

An adapter is a class in the namespace `DeskOutbox\Adapters` implementing
`DeskOutbox\ChannelAdapter`, constructed with the channel's settings (the
channel's array without `adapter`) and an `AdapterContext`:

```php
<?php

declare(strict_types=1);

namespace DeskOutbox\Adapters;

use DeskOutbox\AdapterContext;
use DeskOutbox\AdapterException;
use DeskOutbox\AssetUrls;
use DeskOutbox\ChannelAdapter;
use DeskOutbox\HttpException;
use DeskOutbox\Message;
use DeskOutbox\Result;

final class ExampleAdapter implements ChannelAdapter
{
    public function __construct(private readonly array $settings, private readonly AdapterContext $context)
    {
    }

    /** Problems that make the message unsendable here; checked on queueing and before sending. */
    public function validate(Message $message): array
    {
        return ($this->settings['token'] ?? '') === '' ? ['Example: no token configured.'] : [];
    }

    public function publish(Message $message, AssetUrls $assets): Result
    {
        $images = array_map(fn (array $m): string => $assets->url($m['sha256']), $message->media());
        try {
            $response = $this->context->http->request('POST', 'https://api.example.com/posts', [
                'Authorization' => 'Bearer ' . $this->settings['token'],
                'Content-Type' => 'application/json',
            ], json_encode(['text' => $message->text(), 'images' => $images]));
        } catch (HttpException $e) {
            // No answer: temporary unless the request may have arrived.
            throw new AdapterException('Example: ' . $e->getMessage(), temporary: !$e->sent, accepted: $e->sent);
        }
        if (!$response->ok()) {
            throw new AdapterException('Example: HTTP ' . $response->status . ' ' . AdapterException::excerpt($response->body), temporary: $response->isTemporaryError());
        }
        $json = $response->json();

        return new Result((string) $json['id'], (string) ($json['url'] ?? ''));
    }

    /** @return array<string, int> Keys from DeskOutbox\Metrics::KEYS; [] when the platform gives none. */
    public function metrics(string $remoteId): array
    {
        return [];
    }

    /** @return array{ok: bool, message: string, tokenExpiresAt: ?string} */
    public function health(): array
    {
        return ['ok' => true, 'message' => 'Example API.', 'tokenExpiresAt' => null];
    }
}
```

The contract:

- `validate(Message): string[]` returns problems; empty means fine.
- `publish(Message, AssetUrls): Result` returns the platform's id and the
  public URL of the post (empty when there is none). `AssetUrls` gives
  `url($sha256)` (public, for platforms that fetch the file), `path($sha256)`
  (local, for adapters that upload the bytes) and `mime($sha256)`.
- A failure is an `AdapterException(string $message, bool $temporary =
  false, bool $accepted = false)`. `temporary`: another attempt may help.
  `accepted`: the platform may already have the post; the message becomes
  `unknown` and is never retried. `AdapterException::excerpt($body)`
  shortens a platform answer for the error text. An `HttpException` that
  escapes counts as not temporary, and as accepted when its request went
  out; any other exception as a final failure.
- `metrics(string $remoteId): array` and `health(): array` as above.
  `tokenExpiresAt` (ISO 8601) triggers the mail 14 days before.

`AdapterContext` carries `http` (a `DeskOutbox\HttpClient`: `request($method,
$url, $headers, $body, $timeout)`, throwing `HttpException` with `sent` for
transport errors, never for an HTTP status), `state` (a small key-value
store in the outbox database, e.g. for a refreshed token), `mailer`, `log($line)`
and `now()`. Log lines must not contain tokens or full payloads.

Name the channel's `adapter` `example`, and the registry loads
`ExampleAdapter.php` from the module's `adapters/` folder or from a folder
in `adapterPaths` (`google-business` becomes `GoogleBusinessAdapter.php`).
A class outside that scheme is registered under `adapters` with `class` and
`file`. No Composer autoloader is needed on the server.

The tests of the module are in `tests/Server/` of the package (`composer
test`); an adapter's tests can replace `http` with a fake that replays
recorded answers.
