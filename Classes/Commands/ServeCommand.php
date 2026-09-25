<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk serve` (the default command) — the desk UI on PHP's
 * built-in web server, the way `freezed serve` serves public/.
 *
 * Binds to localhost unless --host says otherwise, and another host is
 * only accepted together with desk.auth: the UI is a writing tool for the
 * project folder, and without a password it must not be reachable from
 * elsewhere.
 */
class ServeCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $log = LogService::getInstance();
        $config = $context->config;

        $config->validateMediaRoot();
        foreach ($context->database()->migrate() as $message) {
            $log->notice($message);
        }
        $context->schemas()->all(); // fail early on a broken schema

        $host = (string) ($options['host'] ?? $config->get('serve.host', 'localhost'));
        $port = (int) ($options['port'] ?? $config->get('serve.port', 8081));

        if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true) && !is_array($config->get('auth'))) {
            throw new DeskException(sprintf(
                'Refusing to bind the desk UI to "%s" without desk.auth. Set \'auth\' => [\'user\' => …, \'passwordHashEnv\' => …] in freezed.config.php to run desk on a server.',
                $host
            ));
        }

        $router = dirname(__DIR__, 2) . '/includes/router.php';
        $docRoot = dirname(__DIR__, 2) . '/themes/00_desk/static';

        putenv('FREEZED_ROOT=' . $config->projectRoot);
        if (!getenv('DESK_AUTOLOAD')) {
            // Started through `freezed desk`: tell the server process where
            // the autoloader is (bin/freezed-desk does this itself).
            $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
            putenv('DESK_AUTOLOAD=' . dirname((string) $loader, 2) . '/autoload.php');
        }
        putenv('DESK_OPTIONS=' . json_encode(array_intersect_key($options, array_flip(['verbose', 'quiet', 'log']))));

        $log->notice(sprintf('Desk for %s at http://%s:%d (Ctrl+C to stop)', $config->projectRoot, $host, $port));

        // Run the server as a child process and pass SIGINT/SIGTERM on to
        // it, so `freezed run --desk` can stop the UI together with the
        // dev server (proc_terminate reaches this process, not php -S).
        $process = proc_open(
            [PHP_BINARY, '-S', $host . ':' . $port, '-t', $docRoot, $router],
            [
                0 => ['file', 'php://stdin', 'r'],
                1 => ['file', 'php://stdout', 'w'],
                2 => ['file', 'php://stderr', 'w'],
            ],
            $pipes,
            $config->projectRoot
        );
        if (!is_resource($process)) {
            throw new DeskException('Could not start the built-in PHP server.');
        }

        $stop = static function () use ($process): void {
            if (is_resource($process)) {
                proc_terminate($process);
            }
        };
        register_shutdown_function($stop);
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $handler = static function () use ($stop): void {
                $stop();
                exit(0);
            };
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGTERM, $handler);
        }

        // Wait without blocking in proc_close(): a blocking wait would keep
        // the signal handler from running until the server exits by itself.
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            usleep(200000);
        }
        $exitCode = (int) $status['exitcode'];
        proc_close($process);

        return $exitCode;
    }
}
