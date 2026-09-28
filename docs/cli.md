# CLI

Every command is available in two spellings that do the same thing:

```bash
vendor/bin/freezed desk:<command> [options]     # through the core's CLI (command registry)
vendor/bin/freezed-desk <command> [options]     # Desk's own binary
```

`freezed desk` (no suffix) starts the UI, and `freezed run --desk` starts it
next to the dev server. The project is found like the core finds it:
`FREEZED_ROOT`, or the nearest `freezed.config.php` upwards from the
working directory. Commands of packages (`desk:social:plan` …) are
registered with the core; `freezed-desk social:plan` finds them there too.

The CLI can do everything the UI can, with two exceptions that are
deliberate: approving a type with `approval: 'ui'` ([approval.md](approval.md))
and sending the outbox when `desk.outbox.push` is `ui`
([outbox.md](outbox.md)). Every route of the UI names the command that does
the same; a test of the package fails when a route has none.

## Conventions

- **JSON.** The commands marked JSON below print JSON on stdout. Their
  errors are JSON too, with exit code 1:

  ```json
  {"error": "categories: Pflichtfeld.", "errors": {"categories": "Pflichtfeld."}}
  ```

  `errors` (field => message, `_` for the record as a whole) comes with
  validation errors. A conflict (`put --if-revision:`) answers
  `{"error": "conflict: …", "current": <record as get prints it>}`. The
  other commands log for people; `--json` makes them report errors as JSON
  as well.
- **`--dry-run`.** Commands that change something take `--dry-run` (also
  `--dry-run:1`): they run the change inside a transaction that is rolled
  back, skip file operations, and print what would have happened, with
  `"dryRun": true`. Not covered: `media:add`, `export`, `import`, `seed`,
  `media:check --adopt/--prune`, `inbox` and `action`.
- **Actor.** The CLI records its changes as `cli`, or as `agent` with
  `--actor:agent` or `DESK_ACTOR=agent`; it never acts as `editor`
  ([approval.md](approval.md)). `import` and `seed` always act as
  `import`: they restore data, so approval rules do not block them.
- **Records** are named `<type>/<slug>`; a single type may be named by its
  type alone. Bulk commands take several records, or a type with `--where:`
  conditions, or both.
- **Repeatable options** (`--where:`) may be given several times. Values
  follow `:` or `=` (`--status:draft`, `--status=draft`).
- Options of the core: `--verbose`, `--quiet`, `--log`, `--log=<path>`.

## Setup and site

| Command | Purpose |
|---|---|
| `serve` (default) | Start the UI. `--host:`, `--port:` |
| `migrate` | Create the database or bring it up to date, check the schema files |
| `show` | List the types with counts |
| `show <type>` | List the records of a type |
| `show <type>/<slug>` | Print the variables a template sees. `--raw` prints the stored record |
| `export` | Write `data/export/<type>/<slug>.json` and `media.json` |
| `import [folder]` | Read an export back. Acts as `import` |
| `seed <file>` | Create or update records and media from a JSON file. Acts as `import` |
| `media:check` | Report missing and orphaned files. `--adopt` adds files without a record, `--prune` removes records whose file is gone |
| `inbox` | Fetch submissions from the configured endpoint |
| `help`, `version` | |

## Records (JSON)

| Command | Purpose |
|---|---|
| `schema [<type>]` | The schema: every type with fields, options, variants and settings; relation fields carry their targets, features fields their vocabulary |
| `list <type>` | Records of a type, see below |
| `get <type>/<slug>` | One record with `revision`, `updatedBy` and `validation`. `--export` prints the template variables instead |
| `put <type>[/<slug>]` | Create or update from JSON on stdin or `--file:<path>`. `--if-revision:<n>`, `--dry-run` |
| `validate <type>/<slug>` | The messages of the schema's checks, without saving. `--publishing` checks as if the record were to be published. Exit code 1 when an error blocks |
| `refs <type>/<slug>` | Records that reference this one: `{refs: [{type, slug, title, status, field}]}` |
| `revisions <type>/<slug>` | The current state and every kept earlier one, newest first, with revision number, actor and time |
| `revision <type>/<slug> <n>` | One earlier state. `--diff` adds the fields that differ from the current state |
| `restore <type>/<slug> <n>` | Bring an earlier state back as a new change. `--if-revision:<m>`, `--dry-run` |
| `publish`, `unpublish`, `archive` | Change the status of `<type>/<slug> …` or `<type> --where:…`. `--dry-run` |
| `delete` | Remove records for good (revisions included, media stays): `<type>/<slug> …` or `<type> --where:…`. `--dry-run` lists what would go |
| `reorder <type> <slug> <slug> …` | The order of a type that sorts by `sort`: the named records first, in this order, the others behind them. `--dry-run` |
| `preview-url <type>/<slug>` | `{type, slug, url, published}`: the address of the built page as `freezed serve` serves it; `url` is null for a type that is not built |

The record format of `get` and `put` is described in [agents.md](agents.md).

### `list`

```bash
vendor/bin/freezed-desk list posts --where:format=perle --where:channels=instagram --range:next14 --order:at
```

prints `{type, records: [{id, slug, title, status, variant, revision, updatedBy, updatedAt}]}`.

| Option | Meaning |
|---|---|
| `--status:draft\|published\|archived\|all` | Default: not archived |
| `--q:<text>` | Full-text search |
| `--where:<field><op><value>` | Repeatable condition, see below |
| `--from:<date>`, `--to:<date>` | Date range on the first date or datetime field (the agenda field when the type has one); `--date:<field>` names another |
| `--range:today\|next7\|next14\|future\|past` | A named range on the same field |
| `--referencing:<type>/<slug>` | Records that reference this one |
| `--by:agent\|editor\|cli\|import` | Last changed by |
| `--unseen` | Changed by the agent, not yet opened by a person |
| `--order:<field>,-<field>` | Ascending, `-` for descending. Default: the schema's `orderBy` |
| `--fields:<a>,<b>` | Add these fields, in the portable form, under `fields` |
| `--validation` | Add `validation: {errors, warnings}` to each record |
| `--limit:<n>`, `--offset:<n>` | |

Conditions of `--where:` (the same for bulk commands):

| Operator | Meaning |
|---|---|
| `=`, `!=` | Equal, not equal. An empty value matches an empty field: `hero=` has no hero |
| `>`, `>=`, `<`, `<=` | Numbers, dates and text (natural order) |
| `~` | Contains, case-insensitive |

- Relation fields take `=` and `!=` with a slug, `<type>/<slug>` or an id;
  several targets separated by `|` match any of them
  (`spot=baldeneysee|kemnader-see`).
- Select fields with several values match when any value matches.
- Bool fields take `true`, `false`, `1`, `0`, `yes`, `no`.
- Group fields are reached with a dot: `address.city=Essen`.
- Besides the fields: `slug`, `title`, `status`, `variant`, `updatedBy`,
  `revision`, `createdAt`, `updatedAt`, `publishedAt`, `sort`, `id`.

Quote conditions with `<`, `>`, `|` or `~` in the shell
(`'--where:km>20'`).

Dates are ISO dates or date-times, or `now`, `today`, `tomorrow`,
`yesterday`, or relative to today: `+14d`, `-7d`, `+2w`, `+1m`, `+1y`. A
date without time as upper bound includes that day. `at=2027-05-06` (or
`at=today`) on a datetime field means that day.

### Bulk commands

```bash
vendor/bin/freezed-desk archive entries/alt-1 entries/alt-2
vendor/bin/freezed-desk publish posts --where:format=perle '--where:at<+7d' --dry-run
```

With one record, `publish`, `unpublish` and `archive` print the record as
`get` does, or the error. With several, they print
`{status, changed, rejected: [{type, slug, error}], records: [{type, slug, status, revision}], dryRun}`
and exit with 1 when a record was rejected. Publishing checks every record
on its own; setting a record to draft or archived always works.

## Media (JSON)

| Command | Purpose |
|---|---|
| `media:add <file>` | Copy a file into the library, print its record with `file`. `--alt:`, `--caption:`, `--credit:`, `--license:`, `--focal:x,y`, `--extra:'{"socialOk": true}'` (extra fields), `--origin:upload\|import` (default `upload`; picks the extra fields' defaults). A file the library holds already (same content) is not stored twice; its record is printed with the given metadata applied |
| `media:list` | The library, newest first. `--q:`, `--kind:images\|videos\|files`, `--origin:upload\|import\|generated`, `--where:<field>=<value>` / `!=` (repeatable; extra fields and `alt`, `caption`, `credit`, `license`, `mime`, `origin`), `--unused`, `--limit:`, `--offset:` |
| `media:get <id\|file>` | One file: metadata, `extra`, `origin`, `generatedBy`, the absolute `path` and `usedBy` |
| `media:usage <id\|file>` | The records that use a file |
| `media:update <id\|file>` | Change metadata; only the given options change. `--alt:`, `--caption:`, `--credit:`, `--license:`, `--focal:x,y` or `--focal:none`, `--extra:'{"socialOk": true}'`, `--dry-run` |
| `media:delete <id\|file>` | Remove a file and its record. Refused while a record uses it, unless `--force`. `--dry-run` |
| `media:prune --generated` | Delete generated files whose record is gone or archived. `--orphaned` (the default and only mode), `--older-than:90d`, `--dry-run`. Uploads are never pruned |

Generated files (rendered by a package) are left out of `media:list` unless
`--origin:generated` asks for them. `--extra:` and `--where:` work on the
extra fields of `desk.media.fields` ([configuration.md](configuration.md));
`media:add --origin:import` marks a file a third party delivered, so it
gets the `import` defaults of those fields.

## Inbox (JSON)

| Command | Purpose |
|---|---|
| `inbox:list` | Submissions, newest first, with a summary. `--status:new,open` (default), `--status:all`, `--limit:`, `--offset:` |
| `inbox:show <id>` | One submission with all fields and the record it is assigned to |
| `inbox:assign <id> <type>/<slug>` | Assign it to a record; `none` removes the assignment. `--dry-run` |
| `inbox:set <id>` | `--status:new\|open\|done\|spam`, `--note:…`, `--dry-run` |

## Actions and overview

| Command | Purpose |
|---|---|
| `actions` | JSON: the global actions (`desk.actions`) with their last run, and the record actions of every type |
| `action <name>` | Run a global action |
| `action <type>/<slug> <name>` | Run a record action of the type's schema |
| `status` | JSON: the overview: counts per type, drafts, recent changes, `unseenAgentChanges`, open inbox, `review` (records to review per type, open points), media count, last build and actions, and what extensions report (`extensions.outbox` …) |

`action` streams the command's output to stdout as it comes and exits with
its exit code.

## Outbox (JSON)

| Command | Purpose |
|---|---|
| `outbox:push` | Push new and changed messages, withdraw the ones no longer approved. Refused with `desk.outbox.push` `ui` (the default), except with `--dry-run` |
| `outbox:pull` | Fetch results into the records (system fields only). `--dry-run` |
| `outbox:status` | Every message with its state. `--plan` adds what a push would do, `--remote` the server's view |

`outbox push|pull|status` is the same. See [outbox.md](outbox.md).

## Review (JSON)

| Command | Purpose |
|---|---|
| `review:list [<type>]` | Without a type: records per queue for every type offered for review, and the open points. With a type: the records of `--queue:` (default `open`: never reviewed or changed since), with their review state |
| `reviews [<type>]` | The open review points, grouped by record, oldest first |
| `reviews <type>/<slug>` | A record's reviews, newest first, with every point. `--open` only reviews with open points, `--snapshot` adds the state reviewed |
| `review:done <point-id> …` | Mark points done. `--note:…` what was done, `--reopen`, `--dry-run`. `review:done <type>/<slug> --all` marks every open point of a record |

Reviews themselves are made in the UI only. See [review.md](review.md).

## Agents

| Command | Purpose |
|---|---|
| `agent [<topic>]` | The guide to this project's desk, in Markdown. `--json` structured, `--topics` lists the topics |

See [agents.md](agents.md).

## Environment

| Variable | Meaning |
|---|---|
| `FREEZED_ROOT` | The project root |
| `DESK_ACTOR` | `agent` records changes as the agent's; default `cli` |
| `DESK_INBOX_TOKEN`, `DESK_OUTBOX_TOKEN` | Tokens of the inbox and outbox endpoints; the names are set by `tokenEnv` |

## Registration

The commands are registered with the core through `extra.freezed.commands`
in the package's `composer.json`; a project can override or remove one with
the `commands` key of `freezed.config.php`.
