# Getting started

This walks through a project with one desk type. It assumes a Freezed
project (`freezed install`) with Desk required through Composer.

## 1. Configure

`freezed.config.php`:

```php
<?php

use Neuedaten\FreezedDesk\Desk;
use Neuedaten\FreezedDesk\Source\DeskSource;

return [
    'siteUrl' => 'https://example.org',

    // Uploads live here; templates reach them with context="media".
    'assetRoots' => [
        'media' => 'data/media',
    ],

    'variables' => array_merge([
        'siteName' => 'Example',
    ], Desk::variables('site')),

    'contentTypes' => [
        'pages' => ['targetDirectory' => '', 'targetFileExtension' => 'html'],
        'entries' => [
            'targetDirectory' => 'entries',
            'targetFileExtension' => 'html',
            'source' => DeskSource::class,
        ],
    ],

    'desk' => [
        'actions' => [
            'build' => 'vendor/bin/freezed build',
        ],
    ],
];
```

Desk needs no key of its own to run: every setting under `desk` has a
default. Add `data/` to `.gitignore` (but keep `data/export/`).

## 2. Declare a type

`desk/types/entries.php`:

```php
<?php

return [
    'label' => 'Einträge',
    'labelSingular' => 'Eintrag',
    'listColumns' => ['title', 'variant', 'status'],
    'variants' => [
        'standard' => ['label' => 'Standard', 'template' => 'index'],
        'plus'     => ['label' => 'Plus',     'template' => 'plus'],
    ],
    'fields' => [
        'title'  => ['type' => 'text', 'label' => 'Name', 'required' => true],
        'teaser' => ['type' => 'text', 'label' => 'Teaser', 'maxLength' => 160],
        'body'   => ['type' => 'markdown', 'label' => 'Text'],
        'hero'   => ['type' => 'image', 'label' => 'Titelbild'],
    ],
];
```

The file name is the type slug. It must match the folder `content/entries/`
and the key under `contentTypes`. A type without either is a desk-only type
(a vocabulary, the site settings): stored and edited, never built.

## 3. Write the templates

`content/entries/index.html` (and `plus.html` for the second variant):

```html
<f:layout name="page" />
<f:section name="content">
    <h1>{title}</h1>
    <p>{teaser}</p>
    <f:if condition="{hero}">
        <img src="{freezed:image(src: hero.src, context: 'media', width: 1200)}" alt="{hero.alt}">
    </f:if>
    {body -> f:format.raw()}
</f:section>
```

## 4. Start

```bash
vendor/bin/freezed desk
```

Opens the UI at http://localhost:8081, creates `data/desk.sqlite` and
`data/media/` on first start. Create a record, publish it, and build:

```bash
vendor/bin/freezed build
```

Or run everything together:

```bash
vendor/bin/freezed run --desk
```

serves the site at http://localhost:8080, the UI at :8081, and rebuilds
after every save in Desk -- the source reports its version to `freezed
watch`. The "Preview" button in the UI opens the built page there.

## 5. Check what a template sees

```bash
vendor/bin/freezed desk:show entries/my-first-entry
```

prints the variables exactly as `build` merges them: site-wide, content type,
record. (`vendor/bin/freezed-desk show …` is the same command through Desk's
own binary.)

## 6. Fill a new project quickly

Write a seed file and run `freezed desk:seed seed.json`, see
[export-import.md](export-import.md). Scripts and agents edit records as
JSON through the CLI, see [agents.md](agents.md).
