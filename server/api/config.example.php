<?php

/**
 * Configuration of the reference endpoint. Copy to config.php next to
 * index.php (outside git) and adjust. The SQLite file and the forms folder
 * must lie outside the web root.
 */
return [
    // Absolute path of the SQLite database that stores submissions.
    'database' => __DIR__ . '/../../var/inbox.sqlite',

    // Folder with the form definitions (a copy of the project's desk/forms/).
    'forms' => __DIR__ . '/forms',

    // Bearer token that Desk sends when fetching (desk.inbox.tokenEnv).
    'token' => getenv('INBOX_TOKEN') ?: 'change-me',

    // Secret for the form tokens (any long random string).
    'secret' => getenv('INBOX_SECRET') ?: 'change-me-too',

    // Days to keep submissions after they were acknowledged.
    'retentionDays' => 90,

    // Rate limit: submissions per IP hash and hour.
    'perHour' => 20,

    // Optional notification mail: null, or ['to' => 'redaktion@example.org', 'from' => 'site@example.org'].
    'mail' => null,

    // Where the browser is sent after a successful post without JavaScript.
    'thanksUrl' => '/danke.html',
];
