<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * The HTTP side of the outbox (B3.1). Paths are relative to …/outbox:
 *
 *   PUT    /assets/<sha256>             upload a file (Bearer), raw body, Content-Type = mime
 *   GET    /assets/<sha256>             {"exists": true, "size": n} or 404 (Bearer)
 *   GET    /media/<sha256>.<ext>        the file for the platforms, no token, Range support
 *   POST   /messages                    queue or replace a message (Bearer)
 *   GET    /messages?state=             status list without payloads (Bearer)
 *   DELETE /messages/<key>              withdraw (Bearer)
 *   POST   /messages/<key>/resolve      a person's decision after "unknown" (Bearer)
 *   GET    /results?since=<id>          result events (Bearer)
 *   POST   /results/ack                 {"ids": […]} (Bearer)
 *   GET    /health                      timer, queue and channel state (Bearer)
 *   GET    /metrics?since=<ISO>         numbers of sent posts (Bearer)
 *
 * <key> is URL-encoded (rawurlencode), since keys contain "/" and "#".
 * Where the web server refuses encoded slashes in a path, the same works
 * as DELETE /messages?key=<key> and POST /messages/resolve?key=<key>.
 * Errors are {"error": "…"} with a matching status. handle() never throws.
 */
final class Api
{
    public const RESULTS_LIMIT = 500;

    public const KEY_MAX = 300;

    /** Minutes without a runner pass after which health reports "late" (B3.9). */
    public const LATE_MINUTES = 30;

    public function __construct(private readonly Services $services)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->route($request);
        } catch (\Throwable $exception) {
            // The message only: payloads and tokens never reach the log (B3.11).
            $this->services->log(sprintf('%s %s failed: %s in %s:%d', $request->method, $this->logPath($request->path), $exception->getMessage(), basename($exception->getFile()), $exception->getLine()));

            return Response::error('Server error', 500);
        }
    }

    private function route(Request $request): Response
    {
        $path = '/' . trim($request->path, '/');
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;

        if (preg_match('#^/media/([^/]+)$#', $path, $m)) {
            return $this->allow($method, ['GET'], fn (): Response => $this->media($request, $m[1]));
        }

        $routes = [
            '#^/assets/([^/]+)$#' => ['PUT' => 'putAsset', 'GET' => 'getAsset'],
            '#^/messages$#' => ['POST' => 'postMessage', 'GET' => 'listMessages', 'DELETE' => 'deleteMessage'],
            '#^/messages/resolve$#' => ['POST' => 'resolveMessage'],
            '#^/messages/([^/]+)$#' => ['DELETE' => 'deleteMessage'],
            '#^/messages/([^/]+)/resolve$#' => ['POST' => 'resolveMessage'],
            '#^/results$#' => ['GET' => 'results'],
            '#^/results/ack$#' => ['POST' => 'ack'],
            '#^/health$#' => ['GET' => 'health'],
            '#^/metrics$#' => ['GET' => 'metrics'],
        ];
        foreach ($routes as $pattern => $handlers) {
            if (!preg_match($pattern, $path, $m)) {
                continue;
            }
            $handler = $handlers[$method] ?? null;

            return $this->allow($method, array_keys($handlers), function () use ($request, $handler, $m): Response {
                if (!$this->authorized($request)) {
                    return Response::error('Unauthorized', 401, [], ['WWW-Authenticate' => 'Bearer']);
                }

                // The key comes URL-encoded in the path, or as ?key= where a web
                // server refuses encoded slashes in paths (Apache without AllowEncodedSlashes).
                $argument = isset($m[1]) ? rawurldecode($m[1]) : (string) ($request->query('key') ?? '');

                return $this->{$handler}($request, $argument);
            });
        }

        return Response::error('Not found', 404);
    }

    /** @param string[] $methods */
    private function allow(string $method, array $methods, \Closure $then): Response
    {
        if (!in_array($method, $methods, true)) {
            $allowed = in_array('GET', $methods, true) ? [...$methods, 'HEAD'] : $methods;

            return Response::error('Method not allowed', 405, [], ['Allow' => implode(', ', $allowed)]);
        }

        return $then();
    }

    private function authorized(Request $request): bool
    {
        $token = (string) ($this->services->config['token'] ?? '');
        $given = $request->bearer();

        return $token !== '' && $given !== '' && hash_equals($token, $given);
    }

    // -------------------------------------------------------------- assets ---

    private function putAsset(Request $request, string $sha): Response
    {
        $sha = strtolower($sha);
        if (!Asset::isValidSha($sha)) {
            return Response::error('Invalid sha256', 400);
        }
        $mime = strtolower(trim(explode(';', (string) $request->header('content-type'))[0]));
        $extension = Asset::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            return Response::error('Type not allowed', 415, ['allowed' => array_keys(Asset::MIME_EXTENSIONS)]);
        }
        $maxBytes = (int) $this->services->config['maxBytes'];
        $declared = $request->header('content-length');
        if ($declared !== null && ctype_digit($declared) && (int) $declared > $maxBytes) {
            return Response::error('File too large', 413, ['maxBytes' => $maxBytes]);
        }
        if (($existing = $this->services->mediaFile($sha)) !== null) {
            return Response::json(['ok' => true, 'existed' => true, 'size' => (int) filesize($existing)]);
        }

        $directory = $this->services->mediaPath();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the media folder.');
        }
        $temp = $directory . '/.' . $sha . '.' . bin2hex(random_bytes(6)) . '.part';
        $out = fopen($temp, 'xb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write to the media folder.');
        }

        // Stream to disk and hash on the way: a 200 MB video never sits in memory.
        $in = $request->stream();
        $context = hash_init('sha256');
        $size = 0;
        $head = '';
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    fclose($out);
                    @unlink($temp);

                    return Response::error('File too large', 413, ['maxBytes' => $maxBytes]);
                }
                if (strlen($head) < 16) {
                    $head .= substr($chunk, 0, 16 - strlen($head));
                }
                hash_update($context, $chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Writing the upload failed (disk full?).');
                }
            }
            fclose($out);
        } catch (\Throwable $exception) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($temp);
            throw $exception;
        }

        $actual = hash_final($context);
        if (!hash_equals($sha, $actual)) {
            @unlink($temp);

            return Response::error('Checksum mismatch', 422, ['expected' => $sha, 'actual' => $actual]);
        }
        if ($size === 0 || !self::looksLike($mime, $head)) {
            @unlink($temp);

            return Response::error('Content does not match ' . $mime, 422);
        }

        $target = $directory . '/' . $sha . '.' . $extension;
        if (is_file($target)) {
            @unlink($temp);

            return Response::json(['ok' => true, 'existed' => true, 'size' => (int) filesize($target)]);
        }
        @chmod($temp, 0640);
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException('Cannot move the upload into place.');
        }

        return Response::json(['ok' => true, 'existed' => false, 'size' => $size], 201);
    }

    private function getAsset(Request $request, string $sha): Response
    {
        $file = $this->services->mediaFile(strtolower($sha));
        if ($file === null) {
            return Response::error('Not found', 404, ['exists' => false]);
        }

        return Response::json(['exists' => true, 'size' => (int) filesize($file)]);
    }

    /** The file starts like the declared type: a PNG called video/mp4 is refused. */
    private static function looksLike(string $mime, string $head): bool
    {
        return match ($mime) {
            'image/jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'video/mp4' => substr($head, 4, 4) === 'ftyp',
            default => false,
        };
    }

    // --------------------------------------------------------------- media ---

    /**
     * Public delivery for the platforms (B3.2): no token, the sha256 is not
     * guessable. Single byte ranges for video players and crawlers.
     */
    private function media(Request $request, string $name): Response
    {
        if (!preg_match('/^([a-f0-9]{64})\.(jpg|png|mp4)$/', $name, $m)) {
            return Response::error('Not found', 404);
        }
        $mime = array_search($m[2], Asset::MIME_EXTENSIONS, true);
        $file = $this->services->mediaPath() . '/' . $m[1] . '.' . $m[2];
        if ($mime === false || !is_file($file)) {
            return Response::error('Not found', 404);
        }
        $size = (int) filesize($file);
        $headers = [
            'Content-Type' => (string) $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=86400',
            'X-Robots-Tag' => 'noindex',
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT',
            'ETag' => '"' . $m[1] . '"',
        ];

        $range = $request->header('range');
        if ($range === null || $range === '' || str_contains($range, ',')) {
            // No range, or several (allowed to answer with the whole file).
            return new Response(200, $headers + ['Content-Length' => (string) $size], '', $file, 0, $size);
        }
        $ifRange = $request->header('if-range');
        if ($ifRange !== null && $ifRange !== $headers['ETag'] && $ifRange !== $headers['Last-Modified']) {
            return new Response(200, $headers + ['Content-Length' => (string) $size], '', $file, 0, $size);
        }

        $bounds = self::range($range, $size);
        if ($bounds === null) {
            return new Response(416, $headers + ['Content-Range' => 'bytes */' . $size, 'Content-Length' => '0']);
        }
        [$start, $end] = $bounds;
        $length = $end - $start + 1;

        return new Response(206, $headers + [
            'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $size),
            'Content-Length' => (string) $length,
        ], '', $file, $start, $length);
    }

    /** @return array{int, int}|null First and last byte, null when unsatisfiable or malformed. */
    private static function range(string $header, int $size): ?array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $m) || ($m[1] === '' && $m[2] === '') || $size === 0) {
            return null;
        }
        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix === 0) {
                return null;
            }

            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $start > $end) {
            return null;
        }

        return [$start, $end];
    }

    // ------------------------------------------------------------ messages ---

    private function postMessage(Request $request): Response
    {
        $data = $request->json();
        if ($data === null) {
            return Response::error('Invalid JSON body', 400);
        }

        $key = is_string($data['key'] ?? null) ? trim($data['key']) : '';
        if ($key === '' || mb_strlen($key) > self::KEY_MAX || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return Response::error('Invalid key', 422, ['problems' => [['rule' => 'message.key', 'message' => 'The key must be 1 to ' . self::KEY_MAX . ' characters without control characters.']]]);
        }
        $channel = is_string($data['channel'] ?? null) ? $data['channel'] : '';
        if (!$this->services->registry->has($channel)) {
            return Response::error('Unknown channel', 422, ['problems' => [['rule' => 'message.channel', 'message' => 'Channel "' . $channel . '" is not configured on the server.']], 'channels' => $this->services->registry->channels()]);
        }
        $at = is_string($data['at'] ?? null) ? Time::parse($data['at']) : null;
        if ($at === null) {
            return Response::error('Invalid time', 422, ['problems' => [['rule' => 'message.at', 'message' => '"at" must be ISO 8601 with a time zone, e.g. 2027-05-06T18:30:00+02:00.']]]);
        }
        if (!is_array($data['payload'] ?? null) || (array_is_list($data['payload']) && $data['payload'] !== [])) {
            return Response::error('Invalid payload', 422, ['problems' => [['rule' => 'message.payload', 'message' => '"payload" must be an object.']]]);
        }
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (!is_array($asset) || !Asset::isValidSha(strtolower((string) ($asset['sha256'] ?? '')))) {
                return Response::error('Invalid assets', 422, ['problems' => [['rule' => 'message.assets', 'message' => 'Every asset needs a sha256 (64 hex characters).']]]);
            }
        }

        $message = Message::fromArray(['key' => $key, 'at' => Time::format($at)] + $data);
        $check = $this->services->check($message);
        if ($check['missing'] !== []) {
            return Response::error('Missing assets', 422, ['missing' => array_values(array_unique($check['missing']))]);
        }
        if ($check['problems'] !== []) {
            return Response::error('Message does not fit the channel', 422, ['problems' => $check['problems']]);
        }

        $hash = is_string($data['hash'] ?? null) && $data['hash'] !== ''
            ? $data['hash']
            : hash('sha256', json_encode([$message->payload, $data['assets'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $blocked = $this->services->store->upsert($message, $hash);
        if ($blocked !== null) {
            return Response::error('Message is ' . $blocked . ' and cannot be replaced', 409, ['key' => $key, 'state' => $blocked]);
        }

        return Response::json(['ok' => true, 'key' => $key, 'state' => 'queued']);
    }

    private function listMessages(Request $request): Response
    {
        $state = $request->query('state');
        if ($state !== null && $state !== '' && !in_array($state, Store::STATES, true)) {
            return Response::error('Invalid state', 422, ['states' => Store::STATES]);
        }

        return Response::json(['messages' => $this->services->store->listMessages($state !== '' ? $state : null)]);
    }

    private function deleteMessage(Request $request, string $key): Response
    {
        if ($key === '') {
            return Response::error('Missing key', 400);
        }
        $result = $this->services->store->withdraw($key);

        return match ($result['outcome']) {
            'missing' => Response::error('Not found', 404, ['key' => $key]),
            'conflict' => Response::error('Message is ' . $result['state'] . ' and cannot be withdrawn', 409, ['key' => $key, 'state' => $result['state']]),
            default => Response::json(['ok' => true, 'key' => $key, 'state' => 'withdrawn']),
        };
    }

    private function resolveMessage(Request $request, string $key): Response
    {
        if ($key === '') {
            return Response::error('Missing key', 400);
        }
        $data = $request->json();
        if ($data === null) {
            return Response::error('Invalid JSON body', 400);
        }
        $state = (string) ($data['state'] ?? '');
        if (!in_array($state, ['sent', 'failed', 'queued'], true)) {
            return Response::error('Invalid state', 422, ['states' => ['sent', 'failed', 'queued']]);
        }
        $text = static fn (string $name, int $max): string => is_scalar($data[$name] ?? null) ? mb_substr(trim((string) $data[$name]), 0, $max) : '';
        $url = $text('url', 2000);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            return Response::error('Invalid url', 422);
        }

        $result = $this->services->store->resolve($key, $state, $url, $text('remoteId', 300), $text('note', 1000));

        return match ($result['outcome']) {
            'missing' => Response::error('Not found', 404, ['key' => $key]),
            'conflict' => Response::error('Message is ' . $result['state'] . '; resolve to ' . $state . ' is not allowed', 409, ['key' => $key, 'state' => $result['state']]),
            default => Response::json(['ok' => true, 'key' => $key, 'state' => $state]),
        };
    }

    // ------------------------------------------------------------- results ---

    private function results(Request $request): Response
    {
        $since = max(0, (int) ($request->query('since') ?? 0));

        return Response::json(['results' => $this->services->store->results($since, self::RESULTS_LIMIT)]);
    }

    private function ack(Request $request): Response
    {
        $data = $request->json();
        if ($data === null || !is_array($data['ids'] ?? null)) {
            return Response::error('Expected {"ids": [...]}', 400);
        }

        return Response::json(['ok' => true, 'acknowledged' => $this->services->store->ack($data['ids'])]);
    }

    // -------------------------------------------------------------- health ---

    private function health(Request $request): Response
    {
        $now = $this->services->now();
        $lastRun = $this->services->store->get('runner.lastRun');
        $last = $lastRun !== null ? Time::parse($lastRun) : null;
        $minutes = $last !== null ? intdiv(max(0, $now->getTimestamp() - $last->getTimestamp()), 60) : null;
        $late = $minutes === null || $minutes > self::LATE_MINUTES;

        $counts = $this->services->store->counts();
        $channels = [];
        foreach ($this->services->channelHealth() as $name => $entry) {
            $channels[$name] = [
                'adapter' => $entry['adapter'],
                'ok' => $entry['ok'],
                'message' => $entry['message'],
                'tokenExpiresAt' => $entry['tokenExpiresAt'],
            ];
        }
        $channelsOk = array_reduce($channels, static fn (bool $ok, array $c): bool => $ok && $c['ok'], true);

        return Response::json([
            'ok' => !$late && $channelsOk,
            'timer' => ['lastRun' => $last !== null ? Time::format($last) : null, 'minutesSince' => $minutes, 'late' => $late],
            'queue' => [
                'queued' => $counts['queued'],
                'sending' => $counts['sending'],
                'failed' => $counts['failed'],
                'unknown' => $counts['unknown'],
            ],
            'channels' => (object) $channels,
        ]);
    }

    // ------------------------------------------------------------- metrics ---

    private function metrics(Request $request): Response
    {
        $since = $request->query('since');
        $from = null;
        if ($since !== null && $since !== '') {
            $time = Time::parseLoose($since);
            if ($time === null) {
                return Response::error('Invalid since', 422, ['hint' => 'ISO 8601, e.g. 2027-05-01 or 2027-05-01T00:00:00Z']);
            }
            $from = Time::format($time);
        }
        $rows = array_values(array_filter(
            $this->services->store->metrics($from),
            static fn (array $row): bool => (array) $row['values'] !== [],
        ));

        return Response::json(['metrics' => $rows]);
    }

    /** Keys may be personal (slugs); the log gets the route only. */
    private function logPath(string $path): string
    {
        return (string) preg_replace('#^/(messages|assets|media)/.+$#', '/$1/…', $path);
    }
}
