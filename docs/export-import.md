# Export, import and seeds

## Export

```bash
vendor/bin/freezed-desk export
```

writes one file per record, `data/export/<type>/<slug>.json`, plus
`data/export/media.json`, deterministically ordered and therefore diff-able.
Relations are written as `{type, slug}`, media as `{file}`, so the files
survive a round trip through git. Files of records that no longer exist are
removed; anything else in the folder is left alone.

```json
{
    "type": "entries",
    "slug": "seeblick",
    "variant": "plus",
    "status": "published",
    "sort": 0,
    "createdAt": "2026-09-25T10:36:20+02:00",
    "updatedAt": "2026-09-25T10:58:03+02:00",
    "publishedAt": "2026-09-25T10:36:20+02:00",
    "fields": {
        "title": "Seeblick",
        "hero": {"file": "2026/09/lake-2fa16935.jpg"},
        "spot": [{"type": "spots", "slug": "baldeneysee"}]
    }
}
```

Media files themselves are not part of the export; they are binary and live
in `data/media/`.

`data/export/` belongs in git, `data/` otherwise does not. A nightly export
(launchd, cron) plus a commit is a fine backup of the content.

## Import

```bash
vendor/bin/freezed-desk import            # from data/export
vendor/bin/freezed-desk import some/dir   # from elsewhere
```

Records are matched by type and slug and updated in place, timestamps are
kept, relations are resolved in a second pass. A record whose relations fail
validation stays a draft and is reported. The import acts as `import`, not
as `cli` or `agent`, so `approval: 'ui'` does not block it
([approval.md](approval.md)).

## Building without Desk

The export is the fallback: a project can build from it with the core's own
JSON source if the desk package is ever removed. Point a small script source
at the files -- the export format is the record, not the template variables,
so a script has to run the fields through an exporter of its own or keep
things simple. That is the price of not depending on Desk; in practice the
package stays.

## Seeds

`seed` fills a new project from a hand-written file:

```json
{
  "media": [
    {"key": "lake", "source": "images/lake.jpg", "alt": "Baldeneysee", "credit": "…"}
  ],
  "items": [
    {"type": "site", "fields": {"siteName": "Perlen an der Ruhr"}},
    {"type": "categories", "slug": "essen-trinken", "status": "published",
     "fields": {"title": "Essen & Trinken"}},
    {"type": "spots", "status": "published",
     "fields": {"title": "Baldeneysee", "hero": {"media": "lake"}}},
    {"type": "entries", "status": "published", "variant": "plus",
     "fields": {"title": "Seeblick", "spot": {"type": "spots", "slug": "baldeneysee"},
                "categories": [{"type": "categories", "slug": "essen-trinken"}],
                "hours": {"days": {"mon": "11:00-22:00", "sat": "10:00-14:00, 17:00-23:00"}}}}
  ]
}
```

- `source` paths are relative to the seed file; the file is copied into the
  library (once: the hash decides).
- A media entry may carry `extra` (values of `desk.media.fields`) and
  `origin` (`upload`, the default, or `import`), which picks the extra
  fields' defaults, as with `media:add --extra: --origin:`. Both apply when
  the file enters the library.
- `{"media": "<key>"}` refers to a media entry of the same file.
- `{"type", "slug"}` refers to a record; slugs of records without an explicit
  slug are derived from the title, as the UI would.
- Records are matched by type and slug, so a seed can run again.
- The file may also be a plain list of items, or an object of `type => [items]`.
- Relations are resolved in a second pass; records failing there stay drafts.
- Like `import`, a seed acts as `import`, so types with `approval: 'ui'` are
  seeded with the status the file gives.
