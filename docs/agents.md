# Working with Desk from scripts and agents

Everything an editor does in the UI can be done from the command line with
JSON in and JSON out. This is the interface for scripts, automations and
LLM agents: no browser, no HTML, no hidden state -- the record you get is
the record you put back.

## Start here

```bash
vendor/bin/freezed-desk agent
```

prints a guide to *this* project's desk, generated from its schema: the
types, every field with its type, options and vocabulary, the commands, the
JSON shapes and the rules. An agent reads it once and knows the project.
(`vendor/bin/freezed desk:agent` is the same command through the core CLI.)

## Commands

| Command | Output |
|---|---|
| `schema [<type>]` | The schema as JSON. Relation fields carry their target types, features fields their vocabulary, select fields their options |
| `list <type> [--status:] [--q:] [--limit:] [--offset:]` | `{type, records: [{id, slug, title, status, variant, updatedAt}]}`; default: drafts and published |
| `get <type>/<slug>` | The record: `{id, type, slug, title, variant, status, sort, createdAt, updatedAt, publishedAt, fields}` |
| `get <type>/<slug> --export` | The template variables instead (Markdown as HTML, references resolved) |
| `put <type>[/<slug>]` | Create or update from JSON on stdin (or `--file:<path>`), prints the saved record plus `created` |
| `delete <type>/<slug>` | Remove the record |
| `publish`, `unpublish`, `archive <type>/<slug>` | Change the status; publishing validates |
| `media:add <file> [--alt:] [--caption:] [--credit:] [--license:] [--focal:x,y]` | Copy a file into the library, print its record with `file` |
| `media:list [--q:] [--kind:images|files]` | The library |

Errors are JSON on stdout with exit code 1:

```json
{"error": "categories: Pflichtfeld.", "errors": {"categories": "Pflichtfeld."}}
```

## The record format

`get` and `put` speak the portable format that `export` writes too:
fields as stored, relations as `{type, slug}`, media as `{file}`. It is
stable across ids and machines.

```json
{
    "slug": "seeblick",
    "variant": "plus",
    "status": "published",
    "fields": {
        "title": "Seeblick",
        "teaser": "Café am Ufer",
        "km": 23.4,
        "body": "# Willkommen\n\nEin *Café* am See.",
        "hero": {"file": "2026/09/lake-2fa16935.jpg"},
        "gallery": [{"file": "2026/09/lake-2fa16935.jpg"}],
        "spot": [{"type": "spots", "slug": "baldeneysee"}],
        "categories": [{"type": "categories", "slug": "biergaerten"}],
        "address": {"street": "Uferweg 1", "zip": "45134", "city": "Essen"},
        "geo": {"lat": 51.4, "lon": 7.02},
        "hours": {"days": {"mon": [{"from": "11:00", "to": "22:00"}], "sat": "10:00-14:00, 17:00-23:00"}, "note": "Feiertage geschlossen"},
        "features": {"boat": true, "dogs": "angeleint", "seats": 120},
        "goodToKnow": ["Parkplatz vorhanden"],
        "website": {"href": "https://seeblick.example", "label": "seeblick.example", "target": ""},
        "color": "ochre"
    }
}
```

### `put` semantics

- Only the fields in `fields` change. Leave out what should stay.
- A bare object of fields (without `fields`) is accepted as well; keys named
  like a field of the type are fields, `slug`, `variant`, `status`, `sort`
  are the record's settings.
- Unknown field names are refused, with a hint to `schema`.
- `put <type>` without a slug creates a record; the slug comes from the JSON
  or is derived from the title. `put <type>/<slug>` updates that record, or
  creates it with that slug when it does not exist.
- A relation target is `{type, slug}`; it must exist. A single relation may
  be given as one object or a one-element list.
- A media reference is `{file}` with the `file` value `media:add` or
  `media:list` printed.
- Validation runs on every save: a required field may be empty only while
  the record is a draft. `status: "published"` with an incomplete record is
  refused, the record is not saved.
- Every save keeps a revision; `unpublish` never fails.

## A session, end to end

```bash
vendor/bin/freezed-desk agent                       # learn the project
vendor/bin/freezed-desk list entries --q:see        # find the record
vendor/bin/freezed-desk get entries/seeblick > r.json
# … edit r.json …
vendor/bin/freezed-desk put entries/seeblick < r.json
vendor/bin/freezed-desk media:add photo.jpg --alt:"Terrasse am See"
echo '{"fields": {"hero": {"file": "2026/09/photo-1a2b3c4d.jpg"}}}' | vendor/bin/freezed-desk put entries/seeblick
vendor/bin/freezed-desk publish entries/seeblick
vendor/bin/freezed build
```

`get --export` shows what the templates receive after all that, and
`vendor/bin/freezed run --desk` keeps the site rebuilding while records
change, from the UI or from the command line.

## Why a CLI and not an API

A static site has no server to host one, and the desk runs where the
project is. The command line is already there, takes JSON on both ends and
needs no token, port or session. An agent that can run commands can do
everything; a tool wrapper (MCP or the like) would only relay the same
commands.
