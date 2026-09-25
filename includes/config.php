<?php

/**
 * Built-in defaults for the "desk" key of freezed.config.php. Every key can
 * be overridden in the project; a project without a "desk" key runs with
 * exactly these values.
 */
return [
    // Folder for the SQLite database, uploaded media and the JSON export,
    // relative to the project root. The one place in a project with state.
    'dataPath' => 'data',

    // File name of the SQLite database below dataPath.
    'database' => 'desk.sqlite',

    // Name of the assetRoots entry uploads go to. Its folder must lie below
    // dataPath, so templates reach uploads with context="media".
    'mediaRoot' => 'media',

    // Schema files, one per type (desk/types/<type>.php).
    'typesPath' => 'desk/types',

    // Reusable field groups (desk/fields/<name>.php), used with 'use' => '<name>'.
    'fieldsPath' => 'desk/fields',

    // Form definitions for the inbox module (desk/forms/<name>.php).
    'formsPath' => 'desk/forms',

    // Project overlays for the desk UI theme (desk/themes/<name>/templates/…).
    'themesPath' => 'desk/themes',

    // Sub-folder of dataPath for desk:export / desk:import.
    'exportPath' => 'export',

    // The desk web UI. Binds to localhost on purpose; another host is only
    // accepted together with desk.auth (see docs/configuration.md).
    'serve' => [
        'host' => 'localhost',
        'port' => 8081,
    ],

    // Where "Preview" links point to: the site as served by `freezed serve`.
    // null derives it from the core's serve.host / serve.port.
    'previewUrl' => null,

    // Shell commands offered as buttons in the UI, run from the project root.
    'actions' => [
        'build' => 'vendor/bin/freezed build',
    ],

    // Basic authentication for running desk on a server:
    // ['user' => 'editor', 'passwordHashEnv' => 'DESK_PASSWORD_HASH']
    // The environment variable holds a password_hash() value.
    'auth' => null,

    // Remote inbox endpoint to fetch submissions from:
    // ['url' => 'https://example.org/api/v1/inbox', 'tokenEnv' => 'DESK_INBOX_TOKEN']
    'inbox' => null,

    // Revisions kept per record. 0 disables revisions.
    'revisions' => 50,

    // Language of the UI and its messages: "de" or "en".
    'locale' => 'de',

    // Time zone for timestamps and their display, e.g. 'Europe/Berlin'.
    // null takes the system's zone (/etc/localtime), falling back to UTC.
    'timezone' => null,

    // Upload rules.
    'upload' => [
        'maxBytes' => 50 * 1024 * 1024,
        'mimeTypes' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml',
            'application/pdf',
        ],
    ],
];
