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
| `listColumns` | Columns of the list: field names, or `slug`, `variant`, `status`, `updatedAt`, `createdAt`, `publishedAt` |
| `single` | One record (site settings, navigation). `Desk::variables('<type>')` exports it |
| `variants` | See below. Missing: one variant `standard` with template `index` |
| `variables` | Callback adding variables at export, see [templates.md](templates.md) |

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
| `image` | media id | `{id, src, alt, caption, credit, license, width, height, focal: {x, y}, mime, size, name}` or null | |
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

## No block editor

There is no `blocks` field and no page builder. Freely designed pages are
folder pages under `content/pages/`, as in the core. Desk types are
structured records with fixed fields.
