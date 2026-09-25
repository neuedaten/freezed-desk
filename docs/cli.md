# CLI

Every command is available in two spellings that do the same thing:

```bash
vendor/bin/freezed desk:<command> [options]     # through the core's CLI (command registry)
vendor/bin/freezed-desk <command> [options]     # Desk's own binary
```

`freezed desk` (no suffix) starts the UI, and `freezed run --desk` starts it
next to the dev server. The project is found like the core finds it:
`FREEZED_ROOT`, or the nearest `freezed.config.php` upwards from the
working directory.

| Command | Purpose |
|---|---|
| `serve` (default) | Start the UI. `--host:`, `--port:` |
| `migrate` | Create the database or bring it up to date, check the schema files |
| `show` | List the types with counts |
| `show <type>` | List the records of a type |
| `show <type>/<slug>` | Print the variables a template sees. `--raw` prints the stored record |
| `export` | Write `data/export/<type>/<slug>.json` and `media.json` |
| `import [folder]` | Read an export back |
| `seed <file>` | Create or update records and media from a JSON file |
| `media:check` | Report missing and orphaned files. `--adopt`, `--prune` |
| `inbox` | Fetch submissions from the configured endpoint |
| `agent` | Print a guide to this project's desk for an agent |
| `schema [<type>]` | The schema as JSON |
| `list <type>` | Records as JSON. `--status:`, `--q:`, `--limit:`, `--offset:` |
| `get <type>/<slug>` | A record as JSON; `--export` prints the template variables |
| `put <type>[/<slug>]` | Create or update a record from JSON on stdin or `--file:` |
| `delete`, `publish`, `unpublish`, `archive <type>/<slug>` | |
| `media:add <file>` | Add a file to the library. `--alt:`, `--caption:`, `--credit:`, `--license:`, `--focal:x,y` |
| `media:list` | The library as JSON. `--q:`, `--kind:` |
| `help`, `version` | |

The JSON commands are described in [agents.md](agents.md). Options as in
the core: `--verbose`, `--quiet`, `--log`, `--log=<path>`; `--json` makes
every command report errors as JSON.

The commands are registered with the core through `extra.freezed.commands`
in the package's `composer.json`; a project can override or remove one with
the `commands` key of `freezed.config.php`.
