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
folders winning. Templates use the `desk` ViewHelpers (`desk:t`, `desk:date`,
`desk:media`, `desk:record`, `desk:refs`, `desk:options`, `desk:vocabulary`,
`desk:query`, `desk:json`, `desk:bytes`).

## Reading the desk from PHP

`Neuedaten\FreezedDesk\Desk::repository()` gives the repository (see
[templates.md](templates.md) for the query API), `Desk::context()` the whole
context: schemas, media library, exporter, database. Inside a build the
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
