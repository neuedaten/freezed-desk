<?php

/**
 * The outbox timer (B3.3): publishes due messages, marks stuck ones as
 * unknown, checks channel health, collects metrics and prunes old files.
 * Run it every 5 minutes, as the application's user:
 *
 *     php api/outbox/outbox-run.php [--config=/path/to/config.php]
 *
 * (on php-schleuder-1 through a systemd timer calling app-console.sh). It
 * lives in the docroot with the rest of api/, so it refuses to do anything
 * unless started from the command line.
 *
 * The configuration is private/config.php two levels above the api folder
 * (like api/index.php finds it), its 'outbox' key; else api/config.php of
 * the reference endpoint. --config= names another file, which may return
 * the full configuration or the outbox part alone.
 *
 * Prints one line per message (key, channel, state, duration, error) and
 * appends them to the 'log' file if configured. Exit code 0 after a pass,
 * also when single messages failed; 1 when the configuration is missing,
 * 0 with a note when another pass is still running.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/outbox.php';
desk_outbox_autoload();

$configFile = null;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $configFile = substr($argument, 9);
    }
}
if ($configFile === null) {
    foreach ([dirname(__DIR__, 3) . '/private/config.php', dirname(__DIR__) . '/config.php'] as $candidate) {
        if (is_file($candidate)) {
            $configFile = $candidate;
            break;
        }
    }
}
if ($configFile === null || !is_file($configFile)) {
    fwrite(STDERR, "outbox-run: no configuration found (private/config.php or --config=).\n");
    exit(1);
}
$loaded = require $configFile;
$config = is_array($loaded['outbox'] ?? null) ? $loaded['outbox'] : (is_array($loaded) && isset($loaded['database'], $loaded['mediaPath']) ? $loaded : null);
if ($config === null) {
    fwrite(STDERR, "outbox-run: the configuration has no 'outbox' section.\n");
    exit(1);
}

// One pass at a time: a slow video upload must not overlap the next timer tick.
$lockFile = dirname((string) $config['database']) . '/outbox-run.lock';
if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0770, true);
}
$lock = fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "outbox-run: cannot open the lock file.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, gmdate('Y-m-d\TH:i:s\Z') . " another pass is still running\n");
    exit(0);
}

try {
    $services = new DeskOutbox\Services($config, logger: static function (string $line): void {
        fwrite(STDERR, $line . "\n");
    });
    (new DeskOutbox\Runner($services, static function (string $line): void {
        fwrite(STDOUT, $line . "\n");
    }))->run();
} catch (Throwable $exception) {
    fwrite(STDERR, 'outbox-run: ' . $exception->getMessage() . "\n");
    flock($lock, LOCK_UN);
    exit(1);
}

flock($lock, LOCK_UN);
exit(0);
