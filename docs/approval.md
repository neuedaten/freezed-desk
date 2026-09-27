# Actors, approval and conflicts

People and agents work on the same records, sometimes at the same time.
Desk records who made each change, can reserve publishing a type for a
person in the UI, refuses to overwrite a change it did not see, and runs
the project's own checks before a record goes out.

## Who changed a record

Every change is recorded with an actor:

| Actor | Who |
|---|---|
| `editor` | A person in the desk UI. The UI always writes as `editor` |
| `cli` | The command line, the default |
| `agent` | The command line with `--actor:agent` or `DESK_ACTOR=agent` |
| `import` | `import` and `seed`, or PHP (`$context->actAs(Actor::Import)`); not selectable with `--actor:` |

```bash
export DESK_ACTOR=agent                               # every call of this shell
vendor/bin/freezed-desk put posts/herbst < post.json

vendor/bin/freezed-desk put posts/herbst --actor:agent < post.json   # one call
```

`--actor:` wins over `DESK_ACTOR`. The CLI accepts `cli` and `agent` only;
anything else, `editor` included, ends with an error and changes nothing:

```json
{"error": "The CLI acts as \"cli\" or \"agent\", not \"editor\" (--actor: / DESK_ACTOR). Changes as \"editor\" are made in the desk UI."}
```

The agent guide (`desk:agent`) tells agents to set `DESK_ACTOR=agent`.

### Revision numbers

Every record has a `revision`: 1 when it is created, one more with every
save, status changes included. `get` and `list` print it together with
`updatedBy`, the actor of the last change.

Earlier states are kept as revisions (`desk.revisions`, default 50 per
record). Each keeps its number, its actor and when it was saved:

```bash
vendor/bin/freezed-desk revisions posts/herbst
```

```json
{
    "type": "posts",
    "slug": "herbst",
    "revisions": [
        {"revision": 4, "actor": "agent", "savedAt": "2026-09-27T10:12:03+02:00", "status": "draft", "current": true},
        {"revision": 3, "actor": "editor", "savedAt": "2026-09-27T09:40:51+02:00", "status": "published", "current": false,
         "replacedAt": "2026-09-27T10:12:03+02:00", "replacedBy": "agent", "id": 118}
    ]
}
```

`replacedBy` is the note of the change that replaced the state: the actor
for `put`, `ui` for the form, `status` for a status change, `restore:<n>`
for a restore, `outbox` for results written by the outbox.

`revision <type>/<slug> <n>` prints one earlier state, `--diff` the fields
that differ from the current one. `restore <type>/<slug> <n>` brings it
back as a new change on top.

### Changes not yet seen by a person

Opening a record in the UI marks its current revision as seen. A record
whose last change came from `agent` and that nobody has opened since is
"from the agent, not yet seen":

- the overview lists these records,
- the lists highlight their rows and cards,
- the list filter "Changed by" offers "From the agent, not yet seen" (when
  the type declares `updatedBy` in `listFilters`),
- `list <type> --unseen` prints them, `list <type> --by:agent` every record
  the agent changed last,
- `status` counts them as `unseenAgentChanges`.

Opening a record changes nothing else, not even `updatedAt`.

## Approval by a person

```php
// desk/types/posts.php
return [
    'label' => 'Posts',
    'approval' => 'ui',
    'fields' => [/* … */],
];
```

With `approval: 'ui'` only a person in the UI can publish a record of the
type. For such a type the UI calls the statuses "Draft", "Approved" and
"Rejected" (`draft`, `published`, `archived`) and the buttons "Approve" and
"Withdraw approval". Nothing else changes: approved records are published
records, so status filters, bulk actions and `DeskSource` work as before.

From the command line (`cli` and `agent`):

- `publish` of a record that is not published is refused with exit code 1
  and the record stays as it was. With several records, each one is listed
  under `rejected`.
- `put` with `"status": "published"` for a new record or a draft is refused
  the same way.
- `put` that changes the content of an approved record is saved, but the
  record falls back to draft and has to be approved again. The answer says
  so under `notices`. Content is every field except the system fields, plus
  the slug and the variant.
- `restore` never publishes a record that is not published now; restoring
  an approved record to other content sends it back to draft.
- `unpublish` and `archive` always work.

```bash
DESK_ACTOR=agent vendor/bin/freezed-desk publish posts/herbst
```

```json
{"error": "\"herbst\" (Posts) is approved in the desk UI only, not from the CLI."}
```

Messages like this one follow `desk.locale`; the examples here use `en`.

```bash
echo '{"fields": {"caption": "Neuer Text"}}' | DESK_ACTOR=agent vendor/bin/freezed-desk put posts/herbst
```

```json
{
    "slug": "herbst",
    "status": "draft",
    "revision": 6,
    "updatedBy": "agent",
    "notices": ["The approved record was changed and is a draft again. It needs a new approval."]
}
```

(shortened). The actor `import` is exempt from these rules: `import` and
`seed` restore data rather than make an editorial change, so they publish
records of such a type and approved records stay approved, whatever
`DESK_ACTOR` says.

### System fields

A field declared with `system: true` is written by Desk or a package, never
by a person or `put`: rendered files, results from the outbox.

```php
'results' => ['type' => 'json', 'label' => 'Ergebnisse', 'system' => true],
```

- The form shows the value read-only and never sends it.
- `put` ignores system fields, so the JSON `get` printed can be sent back
  unchanged.
- A change to system fields alone does not count as a content change: an
  approved record stays approved.
- Packages write them with
  `Repository::saveSystemFields(int $id, array $fields, string $note = 'system')`.
  It refuses fields that are not system fields, skips the checks below, and
  records the change as `cli`, also when it runs in the UI process.

## Conflicts

`get` prints the record's `revision`. `put --if-revision:<n>` saves only
when the record is still at revision `n`. When someone saved in between,
nothing is written and the answer carries the current state:

```bash
vendor/bin/freezed-desk get posts/herbst > post.json      # "revision": 5
# … edit post.json …
vendor/bin/freezed-desk put posts/herbst --if-revision:5 < post.json
```

```json
{
    "error": "conflict: posts/herbst is at revision 6, the change was based on revision 5. Get the record again and re-apply the change.",
    "current": {"slug": "herbst", "revision": 6, "updatedBy": "editor", "fields": {…}}
}
```

Exit code 1. `current` has the same shape as `get`. `--if-revision` has no
effect when the `put` creates the record. `restore` takes it too.

The UI form sends the revision it was opened at. When someone else saved in
the meantime, the form comes back with the input kept, a notice naming both
revisions and a table of the fields that differ between the state the
editor started from and the one saved in the meantime (the table needs that
earlier state among the kept revisions). "Save my version anyway" then
saves over the current state.

## Checks: `validate`, `warnings`, `guard`

Three optional schema keys run the project's own rules. All three have the
same signature and return messages keyed by field name, or by `_` for the
record as a whole:

```php
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Validation\ValidationContext;

return [
    'label' => 'Posts',
    'approval' => 'ui',
    'fields' => [/* … */],

    // Blocks publishing; a hint in a draft.
    'validate' => function (array $fields, ?Item $item, ValidationContext $ctx): array {
        $errors = [];
        if (trim((string) $fields['caption']) === '') {
            $errors['caption'] = 'Der Text fehlt.';
        }
        if (($fields['images'] ?? []) === [] && ($fields['format'] ?? '') !== 'text') {
            $errors['_'] = 'Ein Bild fehlt.';
        }

        return $errors;
    },

    // Never blocks.
    'warnings' => fn (array $fields, ?Item $item, ValidationContext $ctx): array
        => mb_strlen((string) $fields['caption']) > 1800 ? ['caption' => 'Sehr langer Text.'] : [],

    // Blocks every save, whatever the status.
    'guard' => function (array $fields, ?Item $item, ValidationContext $ctx): array {
        if ($item !== null && $ctx->actor === Actor::Agent && $fields['source'] !== ($item->data['source'] ?? null)) {
            return ['source' => 'Die Quelle tauscht nur die Redaktion.'];
        }

        return [];
    },
];
```

`$fields` are the values in their stored shape (a relation is a list of
`{type, id}`, an image a media id). `$item` is the stored record before the
change, `null` for a new one, so a callback can compare old and new values;
when a stored record is checked (`get`, `validate`, lists) it is that
record. `ValidationContext` carries:

| Property | Meaning |
|---|---|
| `publishing` | True when the record is to be published or stays published |
| `actor` | The `Actor` of the change |
| `schema` | The `TypeSchema` |
| `desk` | The `DeskContext`, for lookups in other records or the media library |
| `slug` | The record's slug |

An array as message is joined with spaces; empty messages are dropped.

When they run:

| Callback | On save | When a stored record is checked |
|---|---|---|
| `validate` | Only when the record is published after the save; messages block like a missing required field | Always; they block only when `publishing` is true, in a draft they are hints |
| `warnings` | Never | Always, never blocking |
| `guard` | Every save, drafts included; messages block the save | Not run |

Required fields follow the same rule as `validate`: they are checked when
publishing, a draft may be incomplete. Setting a record to draft or
archived runs no checks and always works; `saveSystemFields()` runs none
either.

A stored record is checked by `get` (under `validation`), by
`validate <type>/<slug>`, by `list --validation`, in the lists of the UI (an
icon with the messages as tooltip) and in the form (a box above the fields).
For a type with `approval: 'ui'` the lists check every record as if it were
to be approved, so the list shows what blocks approval.

```bash
vendor/bin/freezed-desk validate posts/herbst --publishing
```

```json
{
    "type": "posts",
    "slug": "herbst",
    "status": "draft",
    "publishing": true,
    "ok": false,
    "errors": {"caption": "Der Text fehlt."},
    "warnings": {}
}
```

`--publishing` checks as if the record were to be published now; that is
how an agent tests a draft before handing it over. The exit code is 1 when
an error would block, 0 otherwise. `get` prints the same under
`validation` as `{errors, warnings, blocking}`.

Bulk publishing (the CLI with several records or `--where:`, the list's
bulk action in the UI) checks every record on its own: the ones that pass
are published, the others are listed with their messages.
