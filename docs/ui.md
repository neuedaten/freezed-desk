# The UI

`freezed-desk serve` starts PHP's built-in server with a router script of
the package, the way `freezed serve` serves `public/`. Sessions live in
`data/desk.session/`, never in `/tmp`. Every form carries a CSRF token.
Everything saved in the UI is recorded as the change of `editor`, a person
([approval.md](approval.md)).

The header shows which project the desk runs in, with its path on hover;
the browser tab carries the name too. It is `desk.projectName`, else the
site's `siteName`, else the project folder ([configuration.md](configuration.md)).

| Screen | What it does |
|---|---|
| Overview | Counts per type, changes by the agent not yet seen, drafts, recent changes with who made them, open inbox items, last build, action buttons, panels of extensions (outbox problems …) |
| Type list | Views from `listViews` (table, cards, agenda), full-text search, status filter, the filters of `listFilters`, sorting, "referenced by" filter, bulk publish / draft / archive / delete and bulk record actions, drag-and-drop ordering when the type orders by `sort` |
| Record | Form from the schema, all fields visible, variant and slug at the side, relation search, media picker with upload, sortable lists, the checks' messages, record actions, revisions with diff and restore, "Preview" opening the built page, what references the record, panels of extensions |
| Media | Upload by drag and drop, duplicate detection by hash, alt text, caption, credit, license, focal point by click, extra fields, origin, where a file is used, thumbnails and video stills |
| Inbox | Submissions from the site, assignment to a record, note, status, "apply change" opening the record with the submission beside it |
| Outbox | What goes out with "Send", the state of every message, decisions about unknown ones ([outbox.md](outbox.md)) |
| Review | Queues per type; a record read-only with a note under every field, the decision (approve, resubmit, defer, block) and every link of the record at the side; the history per record ([review.md](review.md)) |
| Actions | One button per `desk.actions` entry, output streamed live, exit code shown |
| Folder types | Content types that read folders (`pages`) are listed read-only with their paths |

## Lists

A type with several `listViews` shows a switch above the list:

- **Table**: the columns of `listColumns`. An `image`, `images` or `files`
  column shows the first file as a thumbnail, a video its still.
- **Cards**: a card per record with the preview image, title, status, date,
  who changed it last and the fields of the view.
- **Agenda**: the cards grouped by day of the view's date field, earliest
  first, records without a date at the end. It shows up to 300 records at
  once, without pages; combine it with a date filter such as "Next 14
  days".

The filters of `listFilters` sit next to the search: a select per field,
date and datetime fields with the periods today, next 7 days, next 14
days, upcoming and past plus a free range of "From" and "To" dates (either
may stay empty; it combines with a period), and "Changed by" with editor,
agent, CLI, import and "From the agent, not yet seen". "Reset filters" clears them. Filters live in the URL, so a
filtered list can be bookmarked.

Each row and card shows who changed the record last. Records changed by the
agent and not yet opened by a person are highlighted; opening one marks it
seen. Records with messages from the schema's checks carry an icon, `!`
for errors and `i` for warnings, with the messages as tooltip. For a type
with `approval: 'ui'` the list checks as if every record were to be
approved, so the icon shows what blocks approval.

Bulk actions work on the selected rows or cards. Publishing checks every
record on its own; the records that could not be changed are listed with
the reason. Record actions with `bulk` run once per selected record, with
their output below the list.

## Approval

For a type with `approval: 'ui'` the UI speaks of approval: the statuses
are "Draft", "Approved" and "Rejected", the buttons "Approve" and "Withdraw
approval", in the form and as bulk actions in the list. The record's side
column says that approval happens only in the UI. Approving validates like
publishing; a record with a blocking message stays a draft.

## Record page

- **Messages**: errors and warnings of the schema's checks above the
  fields, each linking to its field. In a draft, errors are marked as hints:
  saving works, approving does not until they are fixed.
- **System fields** (`system: true`) are shown read-only, never sent with
  the form.
- The side column shows status, dates, who changed the record last and its
  revision.
- **Record actions** of the schema appear as buttons at the side (only
  those whose `when` allows it). The output streams below the buttons; the
  page reloads when the action ended with exit code 0, so rendered files
  show up.
- **Conflicts**: the form sends the revision it was opened at. When someone
  else (a person or the agent) saved in the meantime, nothing is
  overwritten. The form comes back with the input kept, a notice naming both
  revisions and a table of the fields that differ between the state the
  editor started from and the one saved since. "Save my version anyway"
  then saves over it.
- **Revisions** list who produced each state and when; each can be
  compared with the current state and restored.
- **Review**: open points of the record's reviews above the fields, each with
  "Done" and an optional note; "Reviews (n)" opens the history, "Review"
  the review screen. The side column shows the review state.
- Extensions add panels above the fields or in the side column.

## Rules baked in

- Publishing validates the record; a record with a missing required field, a
  missing variant template or a blocking message of the schema's checks
  stays a draft. Setting a record to draft or archived always works.
- A single type has one record and no list; the link in the navigation opens
  it directly.
- Desk-only types appear in their own group.
- Uploads are checked by MIME type (`desk.upload.mimeTypes`), an SVG with
  script or event handlers is refused, a file the library already holds
  (same hash) is not stored twice. Generated files are hidden in the
  library unless the origin filter asks for them.
- Deleting a record removes its relations index and revisions; media stays.
  Deleting a media file that records still use asks for confirmation.

## The UI and the CLI

Every route of the UI names the CLI command that does the same, and a test
of the package fails when a route has none. The exceptions say why they
exist only in the browser:

| UI | CLI |
|---|---|
| Overview | `status` |
| List, search, filters | `list` |
| Save a record | `put` |
| Bulk status change, approve | `publish`, `unpublish`, `archive` (approving a type with `approval: 'ui'` is refused from the CLI) |
| Delete | `delete` |
| Drag-and-drop ordering | `reorder` |
| Record action, bulk record action | `action <type>/<slug> <name>` |
| Record page | `get` |
| Preview | `preview-url` |
| Revisions, restore | `revisions`, `revision`, `restore` |
| Media library, upload | `media:list`, `media:add` |
| Media page, save, delete | `media:get`, `media:update`, `media:delete` |
| Inbox, fetch, submission, save | `inbox:list`, `inbox`, `inbox:show`, `inbox:set` |
| Actions | `actions`, `action <name>` |
| Outbox page, fetch results | `outbox:status`, `outbox:pull` |
| Outbox "Send" | `outbox:push` only with `desk.outbox.push` `any`; by default sending is a person's step and the CLI has `outbox:push --dry-run` |
| Outbox "Decide" | UI only: deciding about an unknown message is a person's step |
| Review queues, review screen, history | `review:list`, `reviews` |
| Review decision | UI only: a review is a person's judgement, like approving |
| Review point done, reopen | `review:done` |
| Thumbnails, files for the browser, folder types | UI only; `media:get` gives the path |

## Browser support

Modern browsers; no framework, no build step. JavaScript is used for the
relation and media pickers, list sorting, the focal point and live action
output. Forms work without it, apart from those pickers.
