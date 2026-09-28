<?php

namespace Neuedaten\FreezedDesk\Storage;

/**
 * Schema versions of the desk database. Append a new version, never edit an
 * applied one; Database::migrate() runs whatever is newer than the file's
 * user_version.
 */
final class Migrations
{
    /**
     * @return array<int, string[]> version => SQL statements
     */
    public static function all(): array
    {
        return [
            1 => [
                'CREATE TABLE items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL,
                    slug TEXT NOT NULL,
                    variant TEXT NOT NULL DEFAULT \'standard\',
                    status TEXT NOT NULL DEFAULT \'draft\',
                    title TEXT NOT NULL DEFAULT \'\',
                    sort INTEGER NOT NULL DEFAULT 0,
                    data TEXT NOT NULL DEFAULT \'{}\',
                    search TEXT NOT NULL DEFAULT \'\',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    published_at TEXT NULL,
                    UNIQUE (type, slug)
                )',
                'CREATE INDEX items_type_status ON items (type, status)',
                'CREATE INDEX items_updated ON items (updated_at)',
                'CREATE TABLE relations (
                    from_id INTEGER NOT NULL REFERENCES items (id) ON DELETE CASCADE,
                    to_id INTEGER NOT NULL,
                    field TEXT NOT NULL,
                    sort INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (from_id, field, to_id)
                )',
                'CREATE INDEX relations_to ON relations (to_id)',
                'CREATE TABLE media (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file TEXT NOT NULL UNIQUE,
                    hash TEXT NOT NULL,
                    mime TEXT NOT NULL,
                    size INTEGER NOT NULL DEFAULT 0,
                    width INTEGER NULL,
                    height INTEGER NULL,
                    alt TEXT NOT NULL DEFAULT \'\',
                    caption TEXT NOT NULL DEFAULT \'\',
                    credit TEXT NOT NULL DEFAULT \'\',
                    license TEXT NOT NULL DEFAULT \'\',
                    focal_x REAL NULL,
                    focal_y REAL NULL,
                    original_name TEXT NOT NULL DEFAULT \'\',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )',
                'CREATE INDEX media_hash ON media (hash)',
                'CREATE TABLE media_usage (
                    item_id INTEGER NOT NULL REFERENCES items (id) ON DELETE CASCADE,
                    media_id INTEGER NOT NULL,
                    field TEXT NOT NULL,
                    PRIMARY KEY (item_id, media_id, field)
                )',
                'CREATE INDEX media_usage_media ON media_usage (media_id)',
                'CREATE TABLE revisions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    item_id INTEGER NOT NULL REFERENCES items (id) ON DELETE CASCADE,
                    data TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    note TEXT NOT NULL DEFAULT \'\'
                )',
                'CREATE INDEX revisions_item ON revisions (item_id, id)',
                'CREATE TABLE inbox (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    remote_id TEXT NULL,
                    form TEXT NOT NULL,
                    item_id INTEGER NULL,
                    payload TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT \'new\',
                    note TEXT NOT NULL DEFAULT \'\',
                    received_at TEXT NOT NULL,
                    handled_at TEXT NULL
                )',
                'CREATE UNIQUE INDEX inbox_remote ON inbox (form, remote_id)',
                'CREATE INDEX inbox_status ON inbox (status)',
                'CREATE TABLE settings (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )',
            ],
            // 0.2: who changed a record (actor) and a running revision number
            // for conflict protection; media extras and generated media.
            2 => [
                'ALTER TABLE items ADD COLUMN revision INTEGER NOT NULL DEFAULT 1',
                'ALTER TABLE items ADD COLUMN updated_by TEXT NOT NULL DEFAULT \'\'',
                'ALTER TABLE items ADD COLUMN seen_revision INTEGER NULL',
                'ALTER TABLE revisions ADD COLUMN number INTEGER NULL',
                'ALTER TABLE revisions ADD COLUMN actor TEXT NOT NULL DEFAULT \'\'',
                'ALTER TABLE revisions ADD COLUMN saved_at TEXT NULL',
                // Number the revisions kept so far and continue from there.
                'UPDATE revisions SET number = (SELECT COUNT(*) FROM revisions r2 WHERE r2.item_id = revisions.item_id AND r2.id <= revisions.id)',
                'UPDATE items SET revision = 1 + (SELECT COUNT(*) FROM revisions r WHERE r.item_id = items.id)',
                'CREATE INDEX items_updated_by ON items (type, updated_by)',
                'ALTER TABLE media ADD COLUMN extra TEXT NOT NULL DEFAULT \'{}\'',
                'ALTER TABLE media ADD COLUMN origin TEXT NOT NULL DEFAULT \'upload\'',
                'ALTER TABLE media ADD COLUMN generated_by TEXT NULL',
                'ALTER TABLE media ADD COLUMN generated_key TEXT NULL',
                'CREATE UNIQUE INDEX media_generated_key ON media (generated_key)',
                'CREATE INDEX media_origin ON media (origin)',
            ],
            // 0.2: the outbox, one row per message pushed to the server.
            3 => [
                'CREATE TABLE outbox (
                    key TEXT PRIMARY KEY,
                    item_id INTEGER NULL,
                    channel TEXT NOT NULL,
                    at TEXT NOT NULL,
                    hash TEXT NOT NULL,
                    state TEXT NOT NULL DEFAULT \'pending\',
                    pushed_at TEXT NULL,
                    remote_id TEXT NULL,
                    url TEXT NULL,
                    error TEXT NULL,
                    notice TEXT NULL,
                    updated_at TEXT NOT NULL
                )',
                'CREATE INDEX outbox_item ON outbox (item_id)',
                'CREATE INDEX outbox_state ON outbox (state)',
            ],
            // 0.3: reviews, a person's decision about a record with points
            // to work through; kept as history with the state reviewed.
            4 => [
                'CREATE TABLE reviews (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    uid TEXT NOT NULL UNIQUE,
                    item_id INTEGER NOT NULL REFERENCES items (id) ON DELETE CASCADE,
                    revision INTEGER NOT NULL,
                    hash TEXT NOT NULL,
                    decision TEXT NOT NULL,
                    reviewer TEXT NOT NULL DEFAULT \'\',
                    snapshot TEXT NOT NULL DEFAULT \'{}\',
                    created_at TEXT NOT NULL
                )',
                'CREATE INDEX reviews_item ON reviews (item_id, id)',
                'CREATE TABLE review_points (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    review_id INTEGER NOT NULL REFERENCES reviews (id) ON DELETE CASCADE,
                    field TEXT NULL,
                    tags TEXT NOT NULL DEFAULT \'[]\',
                    text TEXT NOT NULL DEFAULT \'\',
                    done_at TEXT NULL,
                    done_by TEXT NOT NULL DEFAULT \'\',
                    done_note TEXT NOT NULL DEFAULT \'\'
                )',
                'CREATE INDEX review_points_review ON review_points (review_id)',
                'CREATE INDEX review_points_open ON review_points (done_at)',
            ],
        ];
    }

    public static function latest(): int
    {
        return max(array_keys(self::all()));
    }
}
