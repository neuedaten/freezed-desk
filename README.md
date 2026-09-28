# Freezed Desk

Desk is the editorial backend for [Freezed](https://github.com/neuedaten/freezed):
structured records in a SQLite file, a local web UI to edit them, and a content
source that feeds them into `freezed build`. The core stays what it is --
templates in, static files out, no runtime. Desk is the one place in a project
where state is allowed, and it lives in exactly one folder of the project.

```
freezed.config.php ─┐
desk/types/*.php  ──┼──▶  Desk UI (local)  ──▶  data/desk.sqlite, data/media/
                    │                                    │
                    └──▶  freezed build  ◀── DeskSource ◀┘
                                │
                                ▼
                             public/
```

- **Schema**: one PHP file per type declares the fields. No file, no field.
- **Storage**: `data/desk.sqlite` plus `data/media/`. `desk:export` writes a
  git-friendly JSON copy.
- **Source**: `DeskSource` turns published records into items, like a
  `variables.php` would.
- **UI**: `freezed desk`, rendered with Fluid, overridable per project.
- **CLI**: everything as JSON in and out, for scripts and agents (`freezed desk:agent`).

## Requirements

PHP 8.4 or newer with `pdo_sqlite`, Freezed 0.14 or newer. GD is used for
thumbnails when present, `intl` for slugs of non-Latin titles.

## Install

```bash
composer require neuedaten/freezed-desk
```

Then, in `freezed.config.php`:

```php
use Neuedaten\FreezedDesk\Desk;
use Neuedaten\FreezedDesk\Source\DeskSource;

return [
    'assetRoots' => ['media' => 'data/media'],
    'variables' => array_merge(['siteName' => 'Example'], Desk::variables('site')),
    'contentTypes' => [
        'pages' => ['targetDirectory' => '', 'targetFileExtension' => 'html'],
        'entries' => ['targetDirectory' => 'entries', 'targetFileExtension' => 'html', 'source' => DeskSource::class],
    ],
];
```

Declare a type in `desk/types/entries.php`, put its templates into
`content/entries/`, and start the UI:

```bash
vendor/bin/freezed desk            # the UI at http://localhost:8081
vendor/bin/freezed run --desk      # site at :8080 rebuilt on every save, UI at :8081
```

See [docs/getting-started.md](docs/getting-started.md) for the walk-through.

## Documentation

- [Getting started](docs/getting-started.md)
- [Configuration](docs/configuration.md)
- [Schema and field types](docs/schema.md)
- [Templates and variables](docs/templates.md)
- [CLI](docs/cli.md)
- [The UI](docs/ui.md)
- [Export, import and seeds](docs/export-import.md)
- [Actors, approval and conflicts](docs/approval.md)
- [Inbox module](docs/inbox.md)
- [Outbox module](docs/outbox.md)
- [Review](docs/review.md)
- [Scripts and agents: the JSON CLI](docs/agents.md)
- [Extending Desk](docs/extending.md)

## License

MIT, see [LICENSE](LICENSE).
