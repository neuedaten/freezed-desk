<?php

/**
 * Configuration of the outbox module. It is the 'outbox' key of the
 * project's private/config.php (next to the inbox settings):
 *
 *     return [
 *         …inbox settings…,
 *         'outbox' => require __DIR__ . '/outbox.php',   // or the array inline
 *     ];
 *
 * The database and the media folder must lie outside the web root; the
 * media folder must survive deploys (rsync --delete), so var/ next to
 * public/, not below it. Tokens belong here or in the environment, never in
 * the repository.
 */
return [
    // Bearer token that Desk sends (desk.outbox.tokenEnv). Separate from the inbox token.
    // An empty token locks every protected route.
    'token' => getenv('OUTBOX_TOKEN') ?: '',

    // SQLite database with messages, results, metrics and adapter state.
    'database' => '/var/www/example/var/outbox.sqlite',

    // Uploaded files, served publicly as <publicBase>/<sha256>.<ext>.
    'mediaPath' => '/var/www/example/var/outbox-media',

    // Public URL of the media route; the platforms fetch the files from here.
    'publicBase' => 'https://example.org/api/v1/outbox/media',

    // Largest upload in bytes (videos).
    'maxBytes' => 200 * 1024 * 1024,

    // Days a file is kept after the last message that used it was sent, failed or withdrawn.
    'retainDays' => 14,

    // Where mails about failed and unknown messages and expiring tokens go.
    'mail' => ['to' => 'redaktion@example.org', 'from' => 'outbox@example.org'],

    // Optional log file of the timer: one line per message, no tokens, no texts.
    'log' => '/var/www/example/var/outbox.log',

    // Folders with further adapters: "instagram" loads InstagramAdapter.php
    // (class DeskOutbox\Adapters\InstagramAdapter). Default: the adapters/ folder
    // of the module (api/outbox/adapters/), which also holds log, mail and webhook.
    // 'adapterPaths' => ['/var/www/example/public/api/outbox/adapters'],

    // Adapters outside that naming scheme:
    // 'adapters' => ['custom' => ['class' => 'Vendor\\CustomAdapter', 'file' => '/path/CustomAdapter.php']],

    // Channels: the name Desk uses => the adapter and its settings. 'rules'
    // maps a differently named channel to the limits of ChannelRules
    // (e.g. 'rules' => 'instagram' for a channel "instagram-test").
    'channels' => [
        'test' => ['adapter' => 'log', 'file' => '/var/www/example/var/outbox-sent.log'],
        'whatsapp' => ['adapter' => 'mail', 'to' => 'redaktion@example.org'],
        // 'automation' => ['adapter' => 'webhook', 'url' => 'https://hooks.example.org/outbox', 'secret' => getenv('OUTBOX_WEBHOOK_SECRET') ?: ''],
    ],
];
