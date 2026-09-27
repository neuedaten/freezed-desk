# Changelog

All notable changes to this project are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions
follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0-beta] - 2026-09-27

### Added

- The UI shows which project it runs in: the name in the header (the path
  on hover) and in the browser tab. `desk.projectName`, else the site's
  `siteName`, else the project folder.
- Everything the UI does has a CLI command (A1), JSON in and out, with
  `--dry-run` wherever something changes: `list` with `--where:` (repeatable;
  = != > >= < <= ~), `--from:`/`--to:`/`--range:`, `--order:`,
  `--referencing:`, `--by:`, `--unseen`, `--fields:`; `refs`, `revisions`,
  `revision --diff`, `restore`, `validate`, `reorder`; bulk `publish`,
  `unpublish`, `archive`, `delete` for several records or `--where:`;
  `media:get`, `media:update`, `media:delete`, `media:usage`, `media:prune`;
  `inbox:list`, `inbox:show`, `inbox:assign`, `inbox:set`; `actions`,
  `action`; `status`; `preview-url`. A test fails when a UI route has no CLI
  equivalent. Commands of packages (`desk:social:…`) run through the
  freezed-desk binary as well.
- Actors (A3): every change records who made it (`editor` in the UI, `cli`,
  `agent` with `DESK_ACTOR=agent` or `--actor:agent`, `import`); revisions
  have a number and an actor; lists and the overview show changes by the
  agent that no person has opened yet.
- `approval: 'ui'` in a schema: only a person in the UI publishes
  ("approves") records of the type; from the CLI publishing is refused and a
  content change sends an approved record back to draft. Fields with
  `system: true` are written by Desk and packages only.
- Conflict protection (A4): `get` prints `revision`, `put --if-revision:<n>`
  refuses an outdated change with the current record; the form shows a
  conflict with a diff instead of overwriting.
- Schema callbacks `validate` (blocks publishing, a hint in a draft),
  `warnings` (never blocks) and `guard` (blocks every save) (A5); `get` and
  lists carry the messages.
- List views `table`, `cards` and `agenda`, list filters for select, bool,
  relation, date and datetime fields and the actor, preview images from image
  and files fields, bulk approval with a message per rejected record (A6).
- Media extra fields (`desk.media.fields`) with defaults per origin, in the
  library, the CLI, the template variables and the export (A7).
- Generated media (`addGenerated()`, replaced in place, hidden in the library
  by default, no duplicates of uploads), videos (MP4) with a still through
  `desk.ffmpeg`, `media:prune --generated` (A8).
- Record actions in the schema (`actions`) with live output on the record
  page and as bulk actions (A9).
- The agent guide reads the project's `desk/agent/*.md` (`desk.agentPath`),
  prints one topic with `desk:agent <topic>`, a structured form with
  `--json`, and names what an agent must not do (A2).
- Extensions: modules and packages add agent guide sections, UI routes,
  pages, panels, sidebar links and translations
  (`extra.freezed-desk.extensions`, `desk.extensions`).
- The outbox (B): Desk pushes messages of approved records to a server,
  which publishes them on time and reports back. `outbox push|pull|status`,
  the "Senden" button (pushing is the UI's alone with `push: 'ui'`), a
  dependency-free reference endpoint in `server/outbox/` with log, mail and
  webhook adapters, `outbox-run.php` for a timer, never sending twice, shared
  channel limits in `server/outbox/lib/ChannelRules.php`.
- `media:add --extra:'{…}'` sets extra fields of a new file,
  `--origin:upload|import` picks their per-origin defaults; seed media
  entries take `extra` and `origin` the same way.
- List filters in the UI: a free from/to date range for date and datetime
  fields besides the named periods.
- `Desk::media()` for the media library; `Desk::variables()` takes the
  project root as third argument, for calls from a page's variables file.
- PHPUnit tests (`composer test`).

### Changed

- JSON errors look the same whether a command runs through `freezed-desk`
  or `freezed desk:…`.
- The preview link follows a `targetFileName` set by a type's `variables`
  callback.
- `import` and `seed` act as `import`, so `approval: 'ui'` does not block
  them.
- Outbox: a `queued` or `failed` message with an unchanged hash counts as
  unchanged and is not pushed again; `queued` and `failed` messages that
  are no longer current are withdrawn; `desk.outbox.url` must use https,
  except for localhost.
- The agent guide writes commands as `vendor/bin/freezed desk:<command>`
  when the project has `vendor/bin/freezed`.

### Added (theme)

- `desk.theme` chooses a theme shipped with Desk; the first one is
  `neuedaten`, the NEUEDATEN corporate design (Source Sans 3 bundled, no
  external requests).
- Partials `Head` and `Brand` in the layout, so a theme can add a stylesheet
  and replace the top bar's name without copying the layout.

## [0.1.0-beta] - 2026-09-25

### Added

- Schema files (`desk/types/<type>.php`) with 19 field types, reusable field
  groups (`desk/fields/`), variants with a template per variant, single
  types, desk-only types and a `variables` callback per type.
- SQLite storage below `data/` with records, relations, media metadata,
  revisions, inbox and settings; migrations run on demand.
- `DeskSource`, the content source for `freezed build`: published records
  become items with the template of their variant; `getVersion()` lets
  `freezed watch` rebuild after every save.
- `Desk::variables('site')` for site-wide variables from a single type.
- CLI `freezed-desk` with `serve`, `migrate`, `show`, `export`, `import`,
  `seed`, `media:check` and `inbox`.
- The desk UI: overview, lists with search, filters, sorting, bulk actions and
  drag-and-drop ordering, forms with every field type, relation and media
  pickers, revisions with diff and restore, media library with upload,
  duplicate detection, focal point and usage, actions with live output, folder
  types read-only.
- Inbox module: form definitions in `desk/forms/`, `desk:form` ViewHelper for
  site templates, a dependency-free reference endpoint in `server/api/`, and
  fetching submissions into the desk.
- Optional Basic authentication (`desk.auth`) for running on a server.
- German and English UI strings (`desk.locale`).
- Registered with the core's command registry: `freezed desk`,
  `freezed desk:<command>` and `freezed run --desk` (Freezed 0.14).
- The UI renders through the core's `RenderService::renderFile()`.
- JSON interface for scripts and agents: `schema`, `list`, `get`, `put`,
  `delete`, `publish`, `unpublish`, `archive`, `media:add`, `media:list`, and
  `agent`, which prints a generated guide to the project's desk.

[Unreleased]: https://github.com/neuedaten/freezed-desk/compare/v0.2.0-beta...HEAD
[0.2.0-beta]: https://github.com/neuedaten/freezed-desk/compare/v0.1.0-beta...v0.2.0-beta
[0.1.0-beta]: https://github.com/neuedaten/freezed-desk/releases/tag/v0.1.0-beta
