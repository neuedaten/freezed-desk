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
        ];
    }

    public static function latest(): int
    {
        return max(array_keys(self::all()));
    }
}
