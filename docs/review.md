# Review

The review screen is made for going through many records quickly: a record
read-only on the left, one field below the other, a note under every
field; the decision on the right. Every decision is kept with its date,
the reviewer, the revision and the full state that was reviewed. That
history is what the team can refer to later ("approved on 28.09.2026").

## The screen

`Review` in the sidebar lists every type offered for review with its
queues. A queue opens as a list; "Start review" opens its first record.

Left, per field that has a value:

- the value as a reader sees it: Markdown rendered, selects with their
  labels, relations as chips, images as thumbnails with alt text and
  credit, positions with a link to OpenStreetMap. Every web address, in a
  text, in Markdown or in a link field, is a link that opens in a new
  window. Internal fields are shown and marked "internal"; that is where
  the research sources are.
- quick notes to tick: "Correct", "Unsure", "Where is this from?",
  "Remove", "More detail", "Rephrase" (`desk.review.tags` changes them),
- a small text field for a note.
- points of earlier reviews that are still open, in a yellow box.

The empty fields are named in one line at the end.

Right, in a column that stays in view:

- previous and next record of the queue, the position in it; Alt + ← / →
  pages without the mouse,
- the last review, the messages of the schema's checks,
- the general comment,
- the decisions:

| Button | Stored as | What else happens |
|---|---|---|
| Approve | `approve` | A draft is published, with the usual checks. When they refuse, the review is kept and the reason shown. |
| Resubmit | `resubmit` | Nothing; the record needs rework and another review. |
| Defer | `defer` | Nothing; decide later. |
| Block | `block` | A published record goes back to draft. Until a later review decides otherwise, publishing is refused, from the UI and the CLI alike. |

- links to the form, the preview and the review history,
- below, every web address found in any field of the record, internal
  ones included, each once with the fields it appears in.

After a decision the next record of the queue opens. At the end of the
queue the list comes back.

When the record changed while it was on screen (the agent saved in
between), nothing is stored: the screen comes back with the current state
and the notes that were typed.

## Queues

| Queue | Records |
|---|---|
| To review (`open`) | never reviewed, or changed since the last review |
| Never reviewed (`new`) | no review yet |
| Changed since review (`changed`) | the content differs from the state reviewed last |
| Resubmit, Deferred, Blocked, Approved | the last review decided so |
| Open points (`points`) | points left to work through |
| All (`all`) | every record that is not archived |

"Changed" compares the content: slug, variant and every field except the
system fields, internal fields included. Publishing, a status change or a
system field (rendered files, outbox results) does not put a record back
into the queue; adding sources after "Where is this from?" does.

## Points

Every note is a point: a field (or none, for the general comment), the
ticked quick notes and the text. A point stays open until someone marks it
done. A point with nothing but "Correct" confirms the field and is never
open.

The record page shows the open points above the fields, each with
"Done" and an optional note on what was done, and "All done" per review.
The header has "Reviews (n)" for the history and "Review" to open the
record in the review screen. Lists mark records with open points (✎ 2)
and blocked records; the sidebar shows the number of open points.

The history (`/types/<type>/<id>/reviews`) lists every review with its
decision, date, reviewer, the revision reviewed and all points, done ones
with who marked them, when and the note. A point marked done by mistake
can be reopened there.

The reviewer is the Basic auth user (`desk.auth`), else empty.

## From the CLI

Reviews are a person's decision and are made in the UI only; the CLI reads
them and marks points done, which is how the agent works through them:

```bash
vendor/bin/freezed desk:review:list                     # counts per type and queue
vendor/bin/freezed desk:review:list entries --queue:points
vendor/bin/freezed desk:reviews                          # all open points, by record
vendor/bin/freezed desk:reviews entries/seeblick         # the history of one record
vendor/bin/freezed desk:reviews entries/seeblick --snapshot
DESK_ACTOR=agent vendor/bin/freezed desk:review:done 12 --note:"Quelle ergänzt"
vendor/bin/freezed desk:review:done entries/seeblick --all
vendor/bin/freezed desk:review:done 12 --reopen
```

A point marked done records the actor (`editor`, `agent`, `cli`). The
changed record goes back into "To review" by itself.

## Configuration

```php
'desk' => [
    'review' => [
        // Types offered for review, in this order. null: every type that is not single.
        'types' => ['entries', 'spots', 'articles'],
        // Quick notes, key => label. The keys are stored; keep them once used.
        'tags' => ['ok' => 'Stimmt so', 'source' => 'Quelle?', 'rephrase' => 'Umformulieren'],
    ],
],
```

The tag `ok` is the confirming one.

## Storage and export

Reviews live in the tables `reviews` and `review_points` (database schema
version 4; opening the UI or `desk:migrate` adds them). A review keeps the
record's state as JSON (`snapshot`), so the history holds even after the
revisions of the record were pruned.

`desk:export` writes the history of each record to
`data/export/_reviews/<type>/<slug>.json`, the snapshot in the portable
form; `desk:import` brings back the reviews that are missing (matched by
their `uid`). Deleting a record deletes its reviews.

## In templates

The reviews are not part of the template variables. A schema's
`variables` callback can read them, e.g. for "checked on …". `state()`
says `approve` only while the last review approved and the content has not
changed since, so the date disappears with the next edit:

```php
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Storage\Item;

'variables' => function (array $v, $repo, ?Item $item = null): array {
    $state = $item !== null ? DeskContext::get()->reviews()->state($item) : null;

    return ['reviewedAt' => $state !== null && $state['state'] === 'approve' ? $state['review']['createdAt'] : null];
},
```
