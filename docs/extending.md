# Extending Desk

## Own field types

A field type is a class implementing
`Neuedaten\FreezedDesk\Schema\FieldTypeInterface` (or extending
`AbstractFieldType`) and a partial `Field/<name>.html` for the form:

```php
'desk' => [
    'fieldTypes' => [\App\Desk\ColorType::class],
],
```

```
desk/themes/10_project/templates/partials/Field/color.html
```

The partial receives `field` (the definition), `name` (the input name to
use), `value` (the stored value) and `id`.

`normalize()` turns input into the stored shape and must accept its own
output; `validate()` returns messages; `export()` returns the template
variable; `searchText()` feeds the full-text search. Implement
`ProvidesExtraVariables` to export more than one variable (as `select` does
with `<name>Label`).

## Own UI templates

Any template, layout, partial or static file of `themes/00_desk/` in the
package can be overridden from `desk/themes/<name>/` in the project, later
folders winning. Two partials exist only to be overridden: `Head` (tags after
`desk.css`, e.g. a stylesheet `css/theme.css`) and `Brand` (the name in the
top bar), so a theme rarely needs its own layout. Templates use the `desk` ViewHelpers (`desk:t`, `desk:date`,
`desk:media`, `desk:record`, `desk:refs`, `desk:options`, `desk:vocabulary`,
`desk:query`, `desk:json`, `desk:bytes`).

## Package themes

Desk ships a second theme, `neuedaten` (the NEUEDATEN corporate design:
Source Sans 3, black, white and violet, square controls). Choose it with

```php
'desk' => ['theme' => 'neuedaten'],
```

It is added after `themes/00_desk` and before the project's overlays, so a
project can still override single files of it. It adds `css/theme.css` over
`desk.css` and changes only tokens and the rules that differ. It is light
only.

## Reading the desk from PHP

`Neuedaten\FreezedDesk\Desk::repository()` gives the repository (see
[templates.md](templates.md) for the query API), `Desk::media()` the media
library (the same as `Desk::context()->media()`), `Desk::context()` the
whole context: schemas, media library, exporter, database. Inside a build the
database is opened read-only.

## What Desk uses from the core

Three things the core added for packages like Desk (Freezed 0.14):

1. The command registry: the package declares its commands under
   `extra.freezed.commands` in `composer.json`, so `freezed desk` and
   `freezed desk:show` work through `bin/freezed`. A command implements
   `Neuedaten\Freezed\Commands\CommandInterface` and validates the
   project's directories itself (Desk creates the media folder first).
2. `freezed run --<command>` starts a registered command next to the dev
   server (`freezed run --desk`).
3. `RenderService::renderFile()` renders a template file with layout,
   partial and component roots and extra ViewHelper namespaces; the desk UI
   is rendered through it, with the core's `freezed` ViewHelpers and
   components available.

## Own commands

A project or package can add commands the same way: a class implementing
the core's `CommandInterface` (or Desk's `AbstractCommand`, which boots the
desk context and hands it to `run()`), registered under `commands` in
`freezed.config.php` or `extra.freezed.commands` in a `composer.json`.

## Extensions

Commands alone are registered with the core. Everything else a module or
package adds to Desk -- its part of the agent guide, UI pages, panels,
translations, its part of `desk:status` -- comes through an extension: a
class implementing `Neuedaten\FreezedDesk\Extension\ExtensionInterface`.
`AbstractExtension` implements every method with "nothing", so an extension
overrides only what it needs. The outbox is such an extension, built into
Desk; Desk depends on no extension, extensions depend on Desk.

A package declares its extension in its `composer.json`:

```json
{
    "extra": {
        "freezed": {
            "commands": {"desk:social:plan": "Vendor\\Social\\Commands\\PlanCommand"}
        },
        "freezed-desk": {
            "extensions": ["Vendor\\Social\\DeskExtension"]
        }
    }
}
```

A project adds more with `desk.extensions`, a list of class names. Desk
refuses to start when a declared class does not exist or does not implement
the interface. Only extensions whose `enabled()` returns true take part.

```php
<?php

namespace Vendor\Social;

use Neuedaten\FreezedDesk\Agent\AgentSection;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Extension\AbstractExtension;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Item;
use Neuedaten\FreezedDesk\Web\Router;

final class DeskExtension extends AbstractExtension
{
    public function name(): string
    {
        return 'social';
    }

    public function enabled(DeskContext $context): bool
    {
        return $context->schemas()->has('posts');
    }

    public function agentSections(DeskContext $context): array
    {
        return [new AgentSection(
            id: 'social',
            title: 'Social media posts',
            markdown: "## Social media posts\n\n…",
            source: 'vendor/social',
            topics: ['social'],
            types: ['posts'],
            commands: [['command' => 'vendor/bin/freezed-desk social:todo --json', 'description' => 'Open work, one entry per post.']],
            rules: ['Never change the source of a planned post.'],
        )];
    }

    public function routes(Router $router): void
    {
        $router->add('GET', '/social', [Controllers\SocialController::class, 'index'], 'social:todo');
        $router->add('POST', '/social/approve', [Controllers\SocialController::class, 'approve'], 'ui:approving is a person\'s step');
    }

    public function themeRoot(): ?string
    {
        return dirname(__DIR__) . '/desk-theme';
    }

    public function navigation(DeskContext $context): array
    {
        return [['label' => $context->t('social.title'), 'href' => '/social', 'badge' => 3, 'level' => 'error']];
    }

    public function dashboardPanels(DeskContext $context): array
    {
        return [['partial' => 'Social/DashboardPanel', 'variables' => ['due' => []]]];
    }

    public function recordPanels(DeskContext $context, TypeSchema $schema, Item $item): array
    {
        return $schema->slug === 'posts'
            ? [['partial' => 'Social/Preview', 'variables' => ['channels' => []], 'position' => 'main']]
            : [];
    }

    public function status(DeskContext $context): array
    {
        return ['open' => 0];
    }

    protected function languageDirectory(): ?string
    {
        return dirname(__DIR__) . '/lang';
    }
}
```

| Method | Adds |
|---|---|
| `name()` | A short name; the key under `extensions` in `desk:status` |
| `enabled(DeskContext)` | Whether the extension takes part, e.g. only when it is configured |
| `agentSections(DeskContext)` | Sections of `desk:agent`, after the generated part and before the project's files. `topics` make a section part of `desk:agent <topic>`, `types` add the generated description of those types to it, `commands` and `rules` feed `desk:agent --json` |
| `routes(Router)` | UI routes, see below |
| `themeRoot()` | A folder laid over the desk theme, see below |
| `navigation(DeskContext)` | Sidebar links `{label, href, badge, level}`; `badge` a number or text, `level` `error` for a highlighted badge |
| `dashboardPanels(DeskContext)` | Panels on the overview: `{partial, variables}` |
| `recordPanels(DeskContext, TypeSchema, Item)` | Panels on a record's page: `{partial, variables, position}`, `position` `main` (above the fields) or `side` |
| `status(DeskContext)` | The extension's part of `desk:status`; an empty array leaves it out |
| `translations(string $locale)` | UI strings, key => text. `AbstractExtension` reads `<languageDirectory()>/<locale>.php`; the English file is the fallback for other locales |

### Routes

`$router->add(string $method, string $pattern, array $handler, string $cli)`.
Patterns have `{name}` segments and a trailing `{name...}` for the rest of
the path. The handler is `[ControllerClass, 'method']` with a controller
extending `Neuedaten\FreezedDesk\Web\Controllers\Controller`; a method takes
`(Request $request, array $params)` and returns a `Response`. The base class
gives `$this->context`, `view($template, $variables, $status)`,
`redirect()`, `flash($type, $message)`, `t($key, $params)` and `schema($type)`.
POST requests carry the CSRF token (`_token`, or the `X-CSRF-Token`
header), which the application checks before the controller runs. Every
action in the UI is the change of `editor`.

The last argument names the CLI command that does the same (`social:todo`,
as registered, without `desk:`), or `ui:<reason>` for what exists only in a
browser or is a person's step by design. The CLI can do everything the UI
can; Desk's own test checks this for its routes, a package should check its
routes the same way (`$app->router->routes()` lists them with `cli`).

### Templates, partials and static files

`themeRoot()` returns a folder with the layout of a desk theme:

```
desk-theme/
    templates/templates/Social/Index.html      # $this->view('Social/Index', …)
    templates/partials/Social/Preview.html     # a record panel
    templates/partials/Social/DashboardPanel.html
    static/css/social.css                      # served as /assets/css/social.css
```

Extension folders come after `themes/00_desk` and before the theme of
`desk.theme` and the project's overlays in `desk/themes/`, so the project
can still override every file. A dashboard panel's partial receives `vars`
(its variables) and `csrfToken`; a record panel's partial also `item`,
`schema` and `values` (the form's current field values). Pages use the
layout `Desk` like Desk's own templates.

### Tests

Desk's tests are in `tests/` of the package, the reference server's in
`tests/Server/`:

```bash
composer test          # or vendor/bin/phpunit
```

## Generated media and system fields

A package that renders files from a record (images, videos) stores them as
generated media:

```php
use Neuedaten\FreezedDesk\Desk;

$media = Desk::context()->media()->addGenerated('/tmp/render/slide-1.jpg', [
    'alt' => 'Kilometer 70: Hof am Fluss',
    'extra' => ['socialOk' => true],
], ['type' => 'posts', 'id' => $item->id, 'generator' => 'social:slide-1']);
```

`addGenerated(string $path, array $meta, array $generatedBy): Media` copies
the file below `generated/<type>/<id>/` of the media folder. `$meta` takes
`alt`, `caption`, `credit`, `license`, `focal` and `extra`; `$generatedBy`
names the record and the generator. A file generated before with the same
`{type, id, generator}` is replaced in place -- same media id, so
references stay valid -- and an unchanged file is left alone. Generated
files are hidden in the library by default, are no duplicates of uploads,
and `media:prune --generated` removes them once their record is gone or
archived. Their origin is `generated`; `generatedBy` is part of `media:get`
and of the export.

The record keeps such results in fields with `system: true`, written with

```php
Desk::repository()->saveSystemFields($item->id, ['assets' => [$media->id]], 'render');
```

which refuses other fields, skips the checks and keeps an approved record
approved ([approval.md](approval.md)). `Desk::context()->media()->ffmpeg()`
gives the configured ffmpeg (`desk.ffmpeg`): `available()`, `run(array
$arguments, ?int &$exitCode)`, `probe($video)` and `still($video, $target)`;
it throws a clear error when a package needs it and it is not configured.
