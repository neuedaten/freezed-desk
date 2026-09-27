<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The server parts are dependency-free and have no Composer autoloading on
// the server; tests load them the way the endpoint does.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'DeskOutbox\\')) {
        $relative = str_replace('\\', '/', substr($class, strlen('DeskOutbox\\')));
        foreach (['/server/outbox/lib/', '/server/outbox/adapters/'] as $directory) {
            $file = dirname(__DIR__) . $directory . basename($relative) . '.php';
            if (is_file($file)) {
                require_once $file;

                return;
            }
        }
    }
});
