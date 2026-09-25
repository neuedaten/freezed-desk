# Changelog

All notable changes to this project are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions
follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-09-25

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
