# The UI

`freezed-desk serve` starts PHP's built-in server with a router script of
the package, the way `freezed serve` serves `public/`. Sessions live in
`data/desk.session/`, never in `/tmp`. Every form carries a CSRF token.

| Screen | What it does |
|---|---|
| Overview | Counts per type, drafts, recent changes, open inbox items, last build, action buttons |
| Type list | Columns from `listColumns`, full-text search, status filter, sorting, "referenced by" filter, bulk publish / draft / archive / delete, drag-and-drop ordering when the type orders by `sort` |
| Record | Form from the schema, all fields visible, variant and slug at the side, relation search, media picker with upload, sortable lists, revisions with diff and restore, "Preview" opening the built page, what references the record |
| Media | Upload by drag and drop, duplicate detection by hash, alt text, caption, credit, license, focal point by click, where a file is used, thumbnails |
| Inbox | Submissions from the site, assignment to a record, note, status, "apply change" opening the record with the submission beside it |
| Actions | One button per `desk.actions` entry, output streamed live, exit code shown |
| Folder types | Content types that read folders (`pages`) are listed read-only with their paths |

## Rules baked in

- Publishing validates the record; a record with a missing required field or
  a missing variant template stays a draft. Setting a record to draft or
  archived always works.
- A single type has one record and no list; the link in the navigation opens
  it directly.
- Desk-only types appear in their own group.
- Uploads are checked by MIME type (`desk.upload.mimeTypes`), an SVG with
  script or event handlers is refused, a file the library already holds
  (same hash) is not stored twice.
- Deleting a record removes its relations index and revisions; media stays.
  Deleting a media file that records still use asks for confirmation.

## Browser support

Modern browsers; no framework, no build step. JavaScript is used for the
relation and media pickers, list sorting, the focal point and live action
output. Forms work without it, apart from those pickers.
