<?php

/**
 * Reference endpoint for the Desk inbox module. No dependencies; drop the
 * api/ folder into static/ of a Freezed project (the build copies it to
 * public/api/) and point the web server's PHP at it.
 *
 *   GET  /api/v1/token            a form token (JSON {"token": …}; also embedded by JS)
 *   POST /api/v1/<form>           a submission (form-encoded or JSON), validated
 *                                 with desk/forms/<form>.php, stored in SQLite
 *   GET  /api/v1/inbox?since=<id> submissions newer than <id>, Bearer token
 *   POST /api/v1/inbox/ack        {"ids": […]} marks them fetched, Bearer token
 *
 *   /api/v1/outbox/…              the outbox module (Bearer token of its own), when
 *                                 $config['outbox'] is set; see outbox/outbox.php. The
 *                                 module is server/outbox/ copied to api/outbox/.
 *
 * Spam rules: honeypot field must be empty, token must be at least
 * spam.minSeconds old, per-IP-hash hourly limit. Everything else -- mail,
 * newsletter, whatever the site needs -- is the project's to add.
 */

declare(strict_types=1);

require __DIR__ . '/lib/FormRules.php';

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : require __DIR__ . '/config.example.php';

header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = preg_replace('#^.*/api/v1#', '', $path) ?? '';
$path = '/' . trim($path, '/');

try {
    // Outbox module: everything below /outbox, before the inbox database is opened.
    if (($path === '/outbox' || str_starts_with($path, '/outbox/')) && is_array($config['outbox'] ?? null)) {
        require_once __DIR__ . '/outbox/outbox.php';
        desk_outbox_handle($config['outbox'], $method, substr($path, 7) ?: '/');
    }

    $db = inbox_db($config);

    if ($method === 'GET' && $path === '/token') {
        inbox_json(['token' => inbox_token($config)]);
    }

    if ($path === '/inbox' && $method === 'GET') {
        inbox_require_bearer($config);
        $since = (int) ($_GET['since'] ?? 0);
        $statement = $db->prepare('SELECT id, form, received_at, payload FROM submissions WHERE id > :since AND spam = 0 ORDER BY id LIMIT 200');
        $statement->execute(['since' => $since]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'form' => $row['form'],
                'receivedAt' => $row['received_at'],
                'fields' => json_decode((string) $row['payload'], true) ?: [],
            ];
        }
        inbox_json(['items' => $items]);
    }

    if ($path === '/inbox/ack' && $method === 'POST') {
        inbox_require_bearer($config);
        $body = json_decode((string) file_get_contents('php://input'), true);
        $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? []))));
        if ($ids !== []) {
            $db->exec('UPDATE submissions SET fetched_at = \'' . gmdate('c') . '\' WHERE id IN (' . implode(',', $ids) . ')');
        }
        $cutoff = gmdate('c', time() - 86400 * (int) ($config['retentionDays'] ?? 90));
        $db->exec('DELETE FROM submissions WHERE fetched_at IS NOT NULL AND fetched_at < \'' . $cutoff . '\'');
        inbox_json(['ok' => true, 'acknowledged' => count($ids)]);
    }

    if ($method === 'POST' && preg_match('#^/([a-z0-9_-]+)$#', $path, $m)) {
        $formName = $m[1];
        $formFile = rtrim((string) $config['forms'], '/') . '/' . $formName . '.php';
        if (!is_file($formFile)) {
            inbox_json(['error' => 'Unknown form'], 404);
        }
        $form = require $formFile;

        $isJson = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
        $input = $isJson ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_POST;

        // Spam rules.
        $honeypot = (string) ($form['spam']['honeypot'] ?? 'website');
        if (!empty($input[$honeypot])) {
            inbox_json(['ok' => true], 200); // pretend
        }
        $minSeconds = (int) ($form['spam']['minSeconds'] ?? 3);
        if (!inbox_token_valid($config, (string) ($input['_token'] ?? ''), $minSeconds)) {
            inbox_json(['error' => 'Token missing or expired. Reload the page and try again.', 'errors' => ['_token' => 'invalid']], 422);
        }
        $ipHash = hash('sha256', ($config['secret'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $count = $db->prepare('SELECT COUNT(*) FROM submissions WHERE ip_hash = :ip AND received_at > :since');
        $count->execute(['ip' => $ipHash, 'since' => gmdate('c', time() - 3600)]);
        if ((int) $count->fetchColumn() >= (int) ($config['perHour'] ?? 20)) {
            inbox_json(['error' => 'Too many submissions. Please try again later.'], 429);
        }

        $result = desk_form_validate($form, $input);
        if ($result['errors'] !== []) {
            inbox_json(['error' => 'Please check the form.', 'errors' => $result['errors']], 422);
        }

        $statement = $db->prepare('INSERT INTO submissions (form, payload, ip_hash, received_at, spam) VALUES (:form, :payload, :ip, :received, 0)');
        $statement->execute([
            'form' => $formName,
            'payload' => json_encode($result['values'], JSON_UNESCAPED_UNICODE),
            'ip' => $ipHash,
            'received' => gmdate('c'),
        ]);

        inbox_notify($config, $form, $formName, $result['values']);

        if ($isJson || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            inbox_json(['ok' => true]);
        }
        header('Location: ' . ($config['thanksUrl'] ?? '/'), true, 303);
        exit;
    }

    inbox_json(['error' => 'Not found'], 404);
} catch (Throwable $exception) {
    inbox_json(['error' => 'Server error: ' . $exception->getMessage()], 500);
}

// ------------------------------------------------------------ helpers ---

function inbox_db(array $config): PDO
{
    $path = (string) $config['database'];
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0770, true);
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form TEXT NOT NULL,
        payload TEXT NOT NULL,
        ip_hash TEXT NOT NULL,
        received_at TEXT NOT NULL,
        fetched_at TEXT NULL,
        spam INTEGER NOT NULL DEFAULT 0
    )');

    return $db;
}

function inbox_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function inbox_require_bearer(array $config): void
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $token = preg_match('/^Bearer\s+(.+)$/i', $header, $m) ? trim($m[1]) : '';
    if ($token === '' || !hash_equals((string) $config['token'], $token)) {
        inbox_json(['error' => 'Unauthorized'], 401);
    }
}

/** A stateless token: timestamp + HMAC. */
function inbox_token(array $config): string
{
    $time = (string) time();

    return $time . '.' . hash_hmac('sha256', $time, (string) $config['secret']);
}

function inbox_token_valid(array $config, string $token, int $minSeconds): bool
{
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $token, $m)) {
        return false;
    }
    $time = (int) $m[1];
    if (!hash_equals(hash_hmac('sha256', (string) $time, (string) $config['secret']), $m[2])) {
        return false;
    }
    $age = time() - $time;

    return $age >= $minSeconds && $age <= 86400;
}

function inbox_notify(array $config, array $form, string $formName, array $values): void
{
    $mail = $config['mail'] ?? null;
    if (!is_array($mail) || empty($mail['to'])) {
        return;
    }
    $lines = [];
    foreach ($values as $key => $value) {
        $label = (string) ($form['fields'][$key]['label'] ?? $key);
        $lines[] = $label . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value);
    }
    $subject = ($form['label'] ?? $formName) . ' (' . $formName . ')';
    @mail(
        (string) $mail['to'],
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        implode("\n", $lines),
        'From: ' . (string) ($mail['from'] ?? $mail['to']) . "\r\nContent-Type: text/plain; charset=UTF-8"
    );
}
