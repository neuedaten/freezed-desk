# Templates and variables

A built type reads its items from `DeskSource`. `content/<type>/` holds only
the templates (`index.html` and one file per variant) and shared assets; the
records come from the database.

## What a template sees

For every published record, in this order (later wins):

1. site-wide `variables` from `freezed.config.php`,
2. the content type's `variables`,
3. the record: standard variables and every non-internal field, exported by
   its field type (see [schema.md](schema.md)),
4. what the type's `variables` callback returns.

`freezed-desk show <type>/<slug>` prints the result.

Standard variables: `id`, `slug`, `variant`, `variantLabel`, `status`,
`createdAt`, `updatedAt`, `publishedAt`, `lastmod`.

## Images

```html
<img src="{freezed:image(src: hero.src, context: 'media', width: 1200, fileType: 'webp')}"
     alt="{hero.alt}" style="object-position: {hero.focal.x * 100}% {hero.focal.y * 100}%">
<f:for each="{gallery}" as="image">…</f:for>
```

`src` is relative to the media root; the core scales, caches and copies. The
focal point is exported as fractions 0..1.

## Markdown

`{body -> f:format.raw()}` -- the export is HTML already, raw HTML in the
source was stripped, unsafe links dropped.

## Relations and links

```html
<f:if condition="{spot}">
    <freezed:link href="{spot.ref}">{spot.title}</freezed:link>
</f:if>
<f:for each="{categories}" as="category">
    <freezed:link href="{category.ref}">{category.title}</freezed:link>
</f:for>
```

## The `variables` callback

Project-specific computed variables, plain PHP, run once per record at build
time:

```php
'variables' => function (array $item, \Neuedaten\FreezedDesk\Storage\Repository $repo): array {
    return [
        'neighbours' => $repo->find('entries')
            ->where('spot', $item['spot'])
            ->whereNot('id', $item['id'])
            ->orderByDistance('km', $item['km'])
            ->limit(3)
            ->refs(['teaser', 'km']),
    ];
},
```

`$item` is the exported record (as above, without the callback's own
result), `$repo` the repository. The query API:

| Call | Meaning |
|---|---|
| `find($type)` | Published records of a type |
| `->anyStatus()`, `->notArchived()`, `->withStatus('draft', …)` | Other status sets |
| `->where($field, $value)`, `->whereNot()`, `->whereIn()` | Field equals value; for relation fields any id in the value matches (an id, a `{type, id}` pair, an exported reference or a list of those) |
| `->whereFeature($key, $value = true)` | Records with that feature |
| `->filter(fn (Item $item): bool => …)` | Anything else |
| `->search($text)` | Full-text search |
| `->orderBy($field, 'ASC'|'DESC')`, `->orderByDistance($field, $value)`, `->ordered()` | Sorting; `ordered()` applies the type's `orderBy` |
| `->limit($n)`, `->offset($n)` | |
| `->all()`, `->first()`, `->count()`, `->ids()` | `Item` objects |
| `->refs($exportFields = [])` | Exported references, as a relation field would export them |
| `->variables()` | Full exported variables of every match |

`Item` has `id`, `type`, `slug`, `variant`, `status`, `title`, `data` (stored
field values) and `value('address.city')`.

## Script sources

A script source (`'source' => 'data/search.php'`) can read the desk the same
way, e.g. to build a search index:

```php
<?php
use Neuedaten\FreezedDesk\Desk;

$entries = Desk::repository()->find('entries')->ordered()->variables();

return [[
    'slug' => 'search',
    'variables' => ['targetFileName' => 'search.json', 'entries' => $entries],
]];
```

## Site-wide variables from a single type

```php
'variables' => array_merge(['siteName' => 'Example'], Desk::variables('site')),
```

`Desk::variables()` runs while the configuration is loaded, so it locates
the project by the calling file and uses the desk defaults; pass overrides as
second argument when `dataPath` or `database` differ. Without a database or
record it returns an empty array, so a fresh project builds before Desk was
ever used.

Called from a page's variables file instead of `freezed.config.php`, the
calling file is not in the project root: pass the root as third argument.

```php
// content/pages/about/variables.php
Desk::variables('site', [], dirname(__DIR__, 3));
```

`Desk::media()` gives the media library the same way, e.g. to look up a
file's metadata in page variables; `Desk::repository()` reads records.

## Overriding the desk UI

Desk's own templates are Fluid too. A project overrides any of them by
placing a folder below `desk/themes/`:

```
desk/themes/10_project/templates/partials/Field/markdown.html
desk/themes/10_project/static/css/desk.css
```

Later folders win, template by template, exactly like themes in the core.
