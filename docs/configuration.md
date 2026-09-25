# Configuration

Everything lives under the `desk` key of `freezed.config.php`. Every key has
a default in `includes/config.php` of the package; a project without a `desk`
key runs with these values.

| Key | Default | Meaning |
|---|---|---|
| `dataPath` | `data` | Folder for the SQLite file, uploads, export, session and cache, relative to the project |
| `database` | `desk.sqlite` | File name of the database below `dataPath` |
| `mediaRoot` | `media` | Name of the `assetRoots` entry uploads go to |
| `typesPath` | `desk/types` | Schema files |
| `fieldsPath` | `desk/fields` | Reusable field groups |
| `formsPath` | `desk/forms` | Form definitions of the inbox module |
| `themesPath` | `desk/themes` | Project overlays of the UI theme |
| `exportPath` | `export` | Sub-folder of `dataPath` for `desk:export` |
| `serve.host` | `localhost` | Host of the UI; another host needs `auth` |
| `serve.port` | `8081` | |
| `previewUrl` | `null` | Base URL of the served site for "Preview" links; `null` derives it from the core's `serve` settings |
| `actions` | `['build' => 'vendor/bin/freezed build']` | Shell commands offered as buttons |
| `auth` | `null` | `['user' => …, 'passwordHashEnv' => …]` for Basic authentication |
| `inbox` | `null` | `['url' => …, 'tokenEnv' => …]`, see [inbox.md](inbox.md) |
| `revisions` | `50` | Revisions kept per record, `0` disables them |
| `locale` | `de` | UI language: `de` or `en` |
| `timezone` | `null` | Time zone, e.g. `Europe/Berlin`; `null` takes the system's |
| `upload.maxBytes` | 50 MB | Size limit for uploads |
| `upload.mimeTypes` | images, SVG, PDF | Accepted MIME types |
| `fieldTypes` | `[]` | Extra field type classes, see [extending.md](extending.md) |

## The file rule

Desk follows the core's rule for directories. `dataPath` must be a
sub-directory of the project and must not contain or lie inside `content/`,
`themes/`, `static/`, `public/` or the image cache. The media folder must be a
declared `assetRoots` entry below `dataPath`, so templates reach uploads with
`context="media"` and the core applies its own checks to it. Desk refuses to
start otherwise and says why.

A symlink `data/media -> /Volumes/Bilder` is fine: it is a decision made in
the project, exactly as for the core's asset roots.

Desk creates `data/media/` on start when it is missing, because the core
refuses an asset root that does not exist.

## Running on a server

The UI binds to `localhost`. To run it elsewhere (several editors, a build
button on the server), set both a host and authentication:

```php
'desk' => [
    'serve' => ['host' => '0.0.0.0', 'port' => 8081],
    'auth' => ['user' => 'redaktion', 'passwordHashEnv' => 'DESK_PASSWORD_HASH'],
],
```

```bash
export DESK_PASSWORD_HASH='$2y$10$…'      # from password_hash('secret', PASSWORD_DEFAULT)
vendor/bin/freezed-desk serve
```

The hash is read from the environment, never from the config file. Without
`auth`, `--host` other than localhost is refused. PHP's built-in server is
meant for one editor at a time; put a real web server in front for more, with
`includes/router.php` of the package as the front controller and the
environment variables `FREEZED_ROOT` and `DESK_AUTOLOAD` set.
