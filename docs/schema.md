# Schema and field types

One file per type in `desk/types/`, returning an array. The file name is the
type slug.

```php
<?php
// desk/types/entries.php

return [
    'label' => 'Einträge',
    'labelSingular' => 'Eintrag',
    'titleField' => 'title',          // default: 'title' when such a field exists
    'slugFrom' => 'title',            // slug proposal; default: titleField
    'orderBy' => ['km' => 'ASC'],     // default list order; default: sort, then title
    'listColumns' => ['title', 'variant', 'km', 'spot', 'status'],
    'single' => false,                // true: exactly one record, no list

    'variants' => [
        'standard' => ['label' => 'Standard', 'template' => 'index'],
        'plus'     => ['label' => 'Plus',     'template' => 'plus'],
    ],

    'fields' => [
        // …
    ],

    'variables' => function (array $item, \Neuedaten\FreezedDesk\Storage\Repository $repo): array {
        return [];
    },
];
```

## Type settings

| Key | Meaning |
|---|---|
| `label`, `labelSingular` | Names in the UI |
| `titleField` | The field that names a record in lists and pickers. Required unless a field `title` exists or the type is `single` |
| `slugFrom` | Field the slug is proposed from (default: `titleField`). The slug stays editable |
| `orderBy` | Default order: `['field' => 'ASC'|'DESC', …]`. When the first key is `sort`, the list offers drag-and-drop ordering |
| `listColumns` | Columns of the table view: field names, or `slug`, `variant`, `status`, `sort`, `updatedAt`, `createdAt`, `publishedAt`, `updatedBy` (who changed the record last). An `image`, `images` or `files` field shows its first file as a thumbnail (a video its still), with the count behind it |
| `single` | One record (site settings, navigation). `Desk::variables('<type>')` exports it |
| `variants` | See below. Missing: one variant `standard` with template `index` |
| `variables` | Callback adding variables at export, see [templates.md](templates.md) |
| `approval` | `'ui'`: only a person in the UI publishes ("approves") records of this type, see [approval.md](approval.md) |
| `validate`, `warnings`, `guard` | The project's checks, callbacks `function (array $fields, ?Item $item, ValidationContext $ctx): array`, see below |
| `listViews` | Views of the list: `table`, `cards`, `agenda`, see below. Default: `table` |
| `listFilters` | Fields offered as filters above the list, plus `updatedBy`, see below |
| `actions` | Record actions: commands run for one record or a selection, see below |

A type is **built** when `contentTypes.<type>` exists in `freezed.config.php`
(with `'source' => DeskSource::class`) and `content/<type>/` holds the
templates. Otherwise it is **desk-only**: stored, edited, used as the target
of relations and vocabularies, never rendered.

## Variants

Every type has at least one variant. A variant is a key with a label and a
template name in `content/<type>/`. The record carries its variant; the
source sets the item's `template` from it, and the variable `variant` (plus
`variantLabel`) is available in the template.

All fields are always visible in the form, whatever the variant: the
template decides what it shows. Switching the variant loses no data. For a
built type, Desk refuses to save a record whose variant template is missing
in `content/<type>/`.

## Standard fields

Every record has, regardless of its schema: `id`, `slug`, `variant`, `status`
(`draft`, `published`, `archived`), `createdAt`, `updatedAt`, `publishedAt`,
`sort`. These names are reserved. `DeskSource` delivers published records
only. `updatedAt` is exported as `lastmod` (date part), so the core's sitemap
is right without further ado.

## Field declaration

```php
'teaser' => ['type' => 'text', 'label' => 'Teaser', 'maxLength' => 160, 'width' => 'half'],
```

Options every field understands:

| Option | Meaning |
|---|---|
| `label` | Label in the form (default: the name) |
| `help` | Text below the control |
| `required` | Must not be empty |
| `default` | Value of a new record |
| `internal` | Stored, never exported (contacts, agreements) |
| `readonly` | Shown, not editable |
| `system` | Written by Desk or a package (rendered files, outbox results), never by the form or `put`; shown read-only. A change to it alone keeps an approved record approved ([approval.md](approval.md)) |
| `width` | `full` (default), `half`, `third` |

Field names are camelCase and must not be a reserved name.

## Field types

| Type | Stores | Exports | Options |
|---|---|---|---|
| `text` | string | string (`''` when empty) | `maxLength`, `minLength`, `pattern`, `placeholder` |
| `textarea` | string | string | as `text` |
| `markdown` | Markdown | HTML (CommonMark + tables, autolinks, strikethrough; raw HTML stripped) | |
| `bool` | bool | bool | |
| `number` | int or float | number | `min`, `max`, `step` (1 makes it an integer), `integer`, `unit` |
| `date` | `YYYY-MM-DD` | the same | |
| `datetime` | `YYYY-MM-DDTHH:MM` | `YYYY-MM-DDTHH:MM:SS` | |
| `select` | key, or list of keys | key(s) plus `<name>Label` | `options` (`key => label`), `multiple` |
| `link` | `{href, label, target}` | the same; `href` may be `CONTENT:type/slug` | |
| `image` | media id | `{id, src, alt, caption, credit, license, width, height, focal: {x, y}, mime, size, name, extra, origin}` or null | |
| `images` | list of media ids | list of the above | `max` |
| `files` | list of media ids | list of the above (any accepted file type) | `max` |
| `relation` | list of `{type, id}` | a reference, or a list of references | `to` (type or list of types), `multiple`, `max`, `export` |
| `features` | `{key: value}` | list of `{key, label, value, kind, icon, group}` | `from` (vocabulary type) |
| `hours` | ranges per weekday plus note | `{days: [{key, label, ranges, closed}], note}` | `dayLabels` |
| `geo` | `{lat, lon, zoom?}` | the same | |
| `list` | list of one field type | list | `of` (field declaration), `min`, `max` |
| `group` | object of sub-fields | object | `fields` |
| `json` | anything | anything | |

### Relations

```php
'spot' => ['type' => 'relation', 'to' => 'spots', 'label' => 'Spot'],
'categories' => ['type' => 'relation', 'to' => 'categories', 'multiple' => true, 'required' => true],
'related' => ['type' => 'relation', 'to' => ['entries', 'spots'], 'multiple' => true, 'export' => ['teaser', 'hero']],
```

A reference is `{id, type, slug, title, variant, ref}`; `ref` is
`CONTENT:<type>/<slug>` for built types, so a template writes
`<freezed:link href="{spot.ref}">{spot.title}</freezed:link>` and the core
decides the URL. Desk knows no URLs. A single relation exports one reference
(or null), a multiple one a list. Only published targets are exported.

`export` names fields of the target to include in each reference (its own
relations excluded, so the export stays flat and finite). The UI offers a
search field; a record's page lists what references it.

### Features (vocabulary)

```php
'features' => ['type' => 'features', 'from' => 'features'],
```

`features` is a desk-only type whose records declare the attributes:
`key` (text, unique), `label`, `kind` (`bool`, `text`, `number`, `select`),
`options` (list of text, for select), `icon`, `group`. A new attribute is a
record, not a schema change. The form shows a checkbox, input or select per
attribute; the export lists only attributes with a value, in vocabulary
order.

### Field groups (`use`)

Reusable groups live in `desk/fields/<name>.php` and return a declaration:

```php
<?php
// desk/fields/address.php
return [
    'type' => 'group',
    'label' => 'Adresse',
    'fields' => [
        'street' => ['type' => 'text', 'label' => 'Straße'],
        'zip' => ['type' => 'text', 'label' => 'PLZ', 'width' => 'third'],
        'city' => ['type' => 'text', 'label' => 'Ort'],
    ],
];
```

A file may also return just the `fields` array. Use it with
`'address' => ['use' => 'address']`; keys given next to `use` override the
group's (`['use' => 'address', 'label' => 'Anschrift']`).

### Lists

```php
'goodToKnow' => ['type' => 'list', 'of' => ['type' => 'text'], 'label' => 'Gut zu wissen'],
'parking' => ['type' => 'list', 'of' => ['type' => 'group', 'fields' => [
    'name' => ['type' => 'text'],
    'places' => ['type' => 'number', 'integer' => true],
]]],
```

Rows are sortable in the form; empty rows are dropped.

## List views

```php
'listViews' => [
    'agenda' => ['field' => 'at', 'image' => 'assets', 'fields' => ['format', 'channels']],
    'cards' => ['image' => 'assets', 'fields' => ['format', 'channels']],
    'table',
],
```

A list of views, each optionally with options; the first one is the view
the list opens with, the others are offered as a switch above it.

| View | Shows |
|---|---|
| `table` | The columns of `listColumns`, sortable, with drag-and-drop ordering when the type orders by `sort` |
| `cards` | A card per record: preview image, title, status, the actor of the last change, and the `fields` |
| `agenda` | Cards grouped by day of a `date` or `datetime` field, in ascending order, records without a date last |

| Option | Meaning |
|---|---|
| `image` | An `image`, `images` or `files` field for the preview image (its first file). Default: the first such field of the type |
| `field` | `agenda`: the date or datetime field to group by. Default: the first one of the type; a type without one cannot have an agenda |
| `fields` | Fields shown under the title (cards and agenda) |

The agenda field is also the date field the list's `--from:`, `--to:` and
`--range:` use in the CLI.

## List filters

```php
'listFilters' => ['format', 'channels', 'source', 'at', 'updatedBy'],
```

Fields of type `select`, `bool`, `relation`, `date` and `datetime`, plus
`updatedBy`. Each becomes a select above the list:

| Field | Filter |
|---|---|
| `select` | One of the options |
| `bool` | Yes or no |
| `relation` | One of the target records (up to 300 per target type) |
| `date`, `datetime` | Today, next 7 days, next 14 days, upcoming, past |
| `updatedBy` | Editor, agent, CLI, import, or "from the agent, not yet seen" |

The filters are URL parameters (`?f[format]=perle&f[at][range]=next14`); a
date filter also takes a free range as `f[at][from]` and `f[at][to]`, with
the date expressions of the CLI (`today`, `+14d`, `2027-05-01`). The same
conditions are available on the command line, see `list` in [cli.md](cli.md).

## Record actions

```php
'actions' => [
    'render' => [
        'label' => 'Neu rendern',
        'command' => 'desk:social:render {ref}',
        'bulk' => true,
        'when' => fn (\Neuedaten\FreezedDesk\Storage\Item $item): bool => $item->status->value !== 'archived',
    ],
    'check-links' => 'php scripts/check-links.php {slug}',
],
```

A record action is a shell command run from the project root for one
record. It appears on the record's page and, with `bulk`, as a bulk action
of the list, which runs it once per selected record. Output is streamed
live and the exit code shown, as for the global `desk.actions`.

| Key | Meaning |
|---|---|
| `command` | The command line. A string instead of the array is the command alone |
| `label` | The button label (default: the name) |
| `bulk` | Also offered for a selection in the list |
| `when` | `function (Item $item): bool`; the action is hidden (and refused) for records where it returns false |

Placeholders: `{type}`, `{slug}`, `{ref}` (`<type>/<slug>`), each
shell-escaped, and `{id}`. A command starting with `desk:` runs through the
project's `freezed` binary with the PHP that runs Desk, so a registered
command needs no path. Names are lowercase letters, digits, `-` and `_`.

The CLI runs the same: `freezed-desk action <type>/<slug> <name>`;
`freezed-desk actions` lists them.

## Checks

```php
'validate' => function (array $fields, ?Item $item, ValidationContext $ctx): array {
    return trim((string) $fields['caption']) === '' ? ['caption' => 'Der Text fehlt.'] : [];
},
```

(with `use Neuedaten\FreezedDesk\Storage\Item;` and
`use Neuedaten\FreezedDesk\Validation\ValidationContext;` at the top of the
type file). `validate` blocks publishing like a missing required field and shows as a
hint in a draft, `warnings` never blocks, `guard` blocks every save. Each
returns `['field' => 'message']`, or `['_' => 'message']` for the record as
a whole. The details are in [approval.md](approval.md).

## No block editor

There is no `blocks` field and no page builder. Freely designed pages are
folder pages under `content/pages/`, as in the core. Desk types are
structured records with fixed fields.
