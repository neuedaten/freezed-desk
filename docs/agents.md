# Working with Desk from scripts and agents

Everything an editor does in the UI can be done from the command line with
JSON in and JSON out. This is the interface for scripts, automations and
LLM agents: no browser, no HTML, no hidden state -- the record you get is
the record you put back. Two steps stay with people on purpose: approving a
type with `approval: 'ui'` and sending the outbox ([approval.md](approval.md),
[outbox.md](outbox.md)).

## Start here

```bash
export DESK_ACTOR=agent
vendor/bin/freezed-desk agent
```

prints a guide to *this* project's desk. An agent reads it once and knows
the project. (`vendor/bin/freezed desk:agent` is the same command through
the core CLI.) The guide writes its commands as
`vendor/bin/freezed desk:<command>` when the project has
`vendor/bin/freezed`, else as `vendor/bin/freezed-desk <command>` or, without
either, `freezed-desk <command>`.

`DESK_ACTOR=agent` makes every change the agent's: revisions, lists and
the overview show it, and people see what to review
([approval.md](approval.md)). Without it the CLI acts as `cli`; it never
acts as `editor`.

### What the guide holds

The full guide has these sections, in this order:

1. **Generated from the schema**: how to call the CLI, the commands, the
   record JSON, one section per type (every field with its type, options,
   vocabulary, whether it is required, internal or a system field; variants;
   record actions; whether the type needs approval by a person) and a short
   workflow.
2. **Sections of extensions**: modules and packages describe their own
   commands, states and rules, so a project need not copy them. The outbox
   adds one when `desk.outbox` is configured; a package such as
   freezed-desk-social adds its own.
3. **The project's files** in `desk/agent/*.md` (`desk.agentPath`), in
   alphabetical order: what only the project knows -- editorial rules,
   style, the workflow of a topic.
4. **Rules**: what an agent does not do (below), and the types that need
   approval by a person.

A project file may start with a front matter naming the types it is about:

```markdown
---
types: [posts, entries]
---

# Social media posts

Write in the second person, factual, the first line names the km …
```

### Topics

```bash
vendor/bin/freezed-desk agent --topics          # {"topics": ["entries", "social", "outbox"]}
vendor/bin/freezed-desk agent social
```

A topic is the name of a project file (`desk/agent/social.md` is `social`)
or a topic an extension section declares. `agent <topic>` prints the
introduction and the commands, the project file, the extension sections of
that topic (the one named like the topic first), the record JSON and the
generated sections of the types the file and those sections name, and the
rules. An unknown topic ends with exit code 1 and lists the topics.

### Structured

`agent --json` (and `agent <topic> --json`) gives the same guide as data,
for tools that do not want Markdown:

```json
{
    "sections": [
        {"id": "intro", "title": "Desk of this project", "source": "desk", "topics": [], "types": [], "commands": [], "rules": ["Set DESK_ACTOR=agent for every call."], "markdown": "# Desk of this project\n…"},
        {"id": "project:social", "title": "Social media posts", "source": "desk/agent/social.md", "topics": ["social"], "types": ["posts"], "commands": [], "rules": [], "markdown": "…"}
    ],
    "commands": [{"command": "vendor/bin/freezed desk:list <type> …", "description": "…", "section": "commands"}],
    "rules": [{"rule": "Never approve: …", "section": "rules"}]
}
```

Section ids: `intro`, `commands`, `records`, `type:<type>`, `workflow`,
`rules`, `project:<topic>`, and the ids extensions choose (`outbox`).

### Rules for agents

The guide states them, and Desk enforces the first two:

- Never approve: records of a type with `approval: 'ui'` are published only
  in the UI. The CLI refuses it; do not work around it.
- Never send: pushing the outbox is a person's step in the UI.
- Never delete records or media unless told to; archive or leave a note.
- Never copy internal fields (contacts, notes) into any text.
- Use `--if-revision` on `put` and re-read the record on a conflict; never
  overwrite a person's change.

`get` without `--export` prints internal fields too. Whether an agent may
read them is a question of how far the agent is trusted, not of the CLI; an
agent with shell access can read the database anyway.

## Commands

The most used ones; [cli.md](cli.md) lists every command and option.

| Command | Output |
|---|---|
| `schema [<type>]` | The schema as JSON. Relation fields carry their target types, features fields their vocabulary, select fields their options |
| `list <type> [--status:] [--q:] [--where:…] [--from: --to:] [--order:] [--fields:] [--validation] [--limit:] [--offset:]` | `{type, records: [{id, slug, title, status, variant, revision, updatedBy, updatedAt}]}`; default: drafts and published |
| `get <type>/<slug>` | The record: `{id, type, slug, title, variant, status, sort, revision, updatedBy, createdAt, updatedAt, publishedAt, fields, validation}` |
| `get <type>/<slug> --export` | The template variables instead (Markdown as HTML, references resolved) |
| `put <type>[/<slug>] [--if-revision:<n>] [--dry-run]` | Create or update from JSON on stdin (or `--file:<path>`), prints the saved record plus `created` and `notices` |
| `validate <type>/<slug> [--publishing]` | The messages of the schema's checks, without saving |
| `refs <type>/<slug>` | Records that reference this one |
| `revisions <type>/<slug>`, `revision <type>/<slug> <n> [--diff]`, `restore <type>/<slug> <n>` | Earlier states |
| `delete <type>/<slug> …` | Remove records |
| `publish`, `unpublish`, `archive <type>/<slug> …` | Change the status; publishing validates. Also `<type> --where:…` |
| `media:add <file> [--alt:] [--caption:] [--credit:] [--license:] [--focal:x,y] [--extra:'{…}'] [--origin:upload\|import]` | Copy a file into the library, print its record with `file`. `--extra:` sets extra fields, `--origin:import` marks a file a third party delivered |
| `media:list [--q:] [--kind:images\|videos\|files] [--where:…]` | The library |
| `media:get`, `media:update`, `media:usage <id\|file>` | One file, its metadata, where it is used |
| `action <type>/<slug> <name>` | Run a record action of the schema (e.g. rendering) |
| `status` | The overview as JSON |
| `reviews [<type>]`, `reviews <type>/<slug>` | Open review points to work through, grouped by record; a record's review history ([review.md](review.md)) |
| `review:done <point-id> … --note:"…"` | Mark a review point done after the record was changed as it asks |

Errors are JSON on stdout with exit code 1:

```json
{"error": "categories: Pflichtfeld.", "errors": {"categories": "Pflichtfeld."}}
```

A conflict carries the current record:

```json
{"error": "conflict: entries/seeblick is at revision 8, the change was based on revision 7. Get the record again and re-apply the change.", "current": {…}}
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

`get` adds `id`, `type`, `title`, `sort`, `revision`, `updatedBy`, the
timestamps and `validation`:

```json
"validation": {"errors": {"teaser": "Zu lang für die Karte."}, "warnings": {}, "blocking": false}
```

`errors` block publishing; `warnings` never do. In a draft, errors are
hints (`blocking` is false); `validate --publishing` shows whether they
would block. `blocking` is true for a published record with errors.

### `put` semantics

- Only the fields in `fields` change. Leave out what should stay.
- A bare object of fields (without `fields`) is accepted as well; keys named
  like a field of the type are fields, `slug`, `variant`, `status`, `sort`
  are the record's settings. The keys `get` adds (`id`, `revision`,
  `validation` …) are ignored, so a record `get` printed can be sent back
  as it is.
- Unknown field names are refused, with a hint to `schema`.
- System fields (`system: true`: rendered files, outbox results) are
  ignored; Desk and its packages write them.
- `put <type>` without a slug creates a record; the slug comes from the JSON
  or is derived from the title. `put <type>/<slug>` updates that record, or
  creates it with that slug when it does not exist.
- A relation target is `{type, slug}`; it must exist. A single relation may
  be given as one object or a one-element list.
- A media reference is `{file}` with the `file` value `media:add` or
  `media:list` printed.
- Validation runs on every save: a required field may be empty only while
  the record is a draft, and the schema's `validate` rules block publishing
  the same way. `status: "published"` with an incomplete record is refused,
  the record is not saved. A schema's `guard` rules can refuse a save in
  any status.
- For a type with `approval: 'ui'`, `status: "published"` is refused from
  the CLI, and a change to an approved record sends it back to draft; the
  answer says so under `notices`.
- `--if-revision:<n>` saves only when the record is still at revision `n`.
- Every save keeps a revision; `unpublish` never fails.

## A session, end to end

```bash
export DESK_ACTOR=agent
vendor/bin/freezed-desk agent                       # learn the project
vendor/bin/freezed-desk list entries --q:see        # find the record
vendor/bin/freezed-desk get entries/seeblick > r.json          # note "revision"
# … edit r.json …
vendor/bin/freezed-desk put entries/seeblick --if-revision:7 < r.json
vendor/bin/freezed-desk media:add photo.jpg --alt:"Terrasse am See"
echo '{"fields": {"hero": {"file": "2026/09/photo-1a2b3c4d.jpg"}}}' | vendor/bin/freezed-desk put entries/seeblick
vendor/bin/freezed-desk validate entries/seeblick --publishing
vendor/bin/freezed-desk publish entries/seeblick    # refused for types with approval: 'ui'
vendor/bin/freezed build
```

On a conflict (exit code 1, `"error": "conflict: …"`), a person saved in
between: read `current` or `get` the record again, re-apply the change to
that state and `put` it with the new revision.

`get --export` shows what the templates receive after all that, and
`vendor/bin/freezed run --desk` keeps the site rebuilding while records
change, from the UI or from the command line.

## Why a CLI and not an API

A static site has no server to host one, and the desk runs where the
project is. The command line is already there, takes JSON on both ends and
needs no token, port or session. An agent that can run commands can do
everything; a tool wrapper (MCP or the like) would only relay the same
commands.
