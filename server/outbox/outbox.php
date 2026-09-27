<?php

/**
 * Outbox module of the API entry (B3). Copy server/outbox/ next to the
 * project's api/index.php (static/api/outbox/ in a Freezed project) and hand
 * it every path below /outbox:
 *
 *     if ($path === '/outbox' || str_starts_with($path, '/outbox/')) {
 *         require_once __DIR__ . '/outbox/outbox.php';
 *         desk_outbox_handle($config['outbox'], $method, substr($path, 7) ?: '/');
 *     }
 *
 * $config['outbox'] is the configuration from config.example.php. The routes
 * are listed in lib/Api.php; the timer runs outbox-run.php. This file only
 * defines functions, so outbox-run.php and tests can include it.
 */

declare(strict_types=1);

/**
 * Load the outbox classes on demand from lib/. Adapters are loaded by the
 * AdapterRegistry, from the paths the configuration names.
 */
function desk_outbox_autoload(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    spl_autoload_register(static function (string $class): void {
        if (preg_match('/^DeskOutbox\\\\([A-Za-z0-9]+)$/', $class, $m) && is_file(__DIR__ . '/lib/' . $m[1] . '.php')) {
            require_once __DIR__ . '/lib/' . $m[1] . '.php';
        }
    });
}

/**
 * Answer one request below …/outbox and end the script.
 *
 * @param array<string, mixed> $config The 'outbox' key of private/config.php.
 * @param string $path The part after /outbox, e.g. '/messages' or '/media/<sha256>.jpg'.
 */
function desk_outbox_handle(array $config, string $method, string $path): never
{
    desk_outbox_autoload();
    $method = strtoupper($method);
    try {
        $services = new DeskOutbox\Services($config);
        $response = (new DeskOutbox\Api($services))->handle(DeskOutbox\Request::fromGlobals($method, $path));
    } catch (Throwable $exception) {
        // Configuration or database trouble before the API could answer itself.
        error_log('outbox: ' . $exception->getMessage());
        $response = DeskOutbox\Response::error('Server error', 500);
    }
    desk_outbox_emit($response, $method);
}

/** Send a Response: headers, then the body or the file range in chunks. */
function desk_outbox_emit(DeskOutbox\Response $response, string $method = 'GET'): never
{
    // Nothing buffered may precede a 200 MB file, and buffers would hold it in memory.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($response->status);
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    foreach ($response->headers as $name => $value) {
        header($name . ': ' . str_replace(["\r", "\n"], '', $value));
    }
    if ($response->file === null && !isset($response->headers['Content-Length'])) {
        header('Content-Length: ' . strlen($response->body));
    }
    if (strtoupper($method) === 'HEAD') {
        exit;
    }
    if ($response->file === null) {
        echo $response->body;
        exit;
    }

    $handle = fopen($response->file, 'rb');
    if ($handle === false) {
        exit;
    }
    set_time_limit(0);
    fseek($handle, $response->offset);
    $left = $response->length;
    while ($left > 0 && !feof($handle) && connection_status() === CONNECTION_NORMAL) {
        $chunk = fread($handle, min(1024 * 1024, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($handle);
    exit;
}
