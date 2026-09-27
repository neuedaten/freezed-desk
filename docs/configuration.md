# Configuration

Everything lives under the `desk` key of `freezed.config.php`. Every key has
a default in `includes/config.php` of the package; a project without a `desk`
key runs with these values.

| Key | Default | Meaning |
|---|---|---|
| `projectName` | `null` | Name of the project in the header and the browser tab of the UI; without it the site's `variables.siteName`, else the project folder's name |
| `dataPath` | `data` | Folder for the SQLite file, uploads, export, session and cache, relative to the project |
| `database` | `desk.sqlite` | File name of the database below `dataPath` |
| `mediaRoot` | `media` | Name of the `assetRoots` entry uploads go to |
| `typesPath` | `desk/types` | Schema files |
| `fieldsPath` | `desk/fields` | Reusable field groups |
| `formsPath` | `desk/forms` | Form definitions of the inbox module |
| `agentPath` | `desk/agent` | The project's Markdown files for the agent guide, see [agents.md](agents.md) |
| `themesPath` | `desk/themes` | Project overlays of the UI theme |
| `theme` | `null` | A theme shipped with Desk laid over the plain one, e.g. `neuedaten`; see [extending.md](extending.md) |
| `exportPath` | `export` | Sub-folder of `dataPath` for `desk:export` |
| `serve.host` | `localhost` | Host of the UI; another host needs `auth` |
| `serve.port` | `8081` | |
| `previewUrl` | `null` | Base URL of the served site for "Preview" links; `null` derives it from the core's `serve` settings |
| `actions` | `['build' => 'vendor/bin/freezed build']` | Shell commands offered as buttons; a command starting with `desk:` runs through the project's `freezed` binary |
| `auth` | `null` | `['user' => …, 'passwordHashEnv' => …]` for Basic authentication |
| `inbox` | `null` | `['url' => …, 'tokenEnv' => …]`, see [inbox.md](inbox.md) |
| `outbox` | `null` | `['url' => …, 'tokenEnv' => …, 'types' => […], 'mapper' => …, 'push' => 'ui']`, see [outbox.md](outbox.md) |
| `revisions` | `50` | Revisions kept per record, `0` disables them |
| `locale` | `de` | UI language: `de` or `en` |
| `timezone` | `null` | Time zone, e.g. `Europe/Berlin`; `null` takes the system's |
| `upload.maxBytes` | 50 MB | Size limit for uploads |
| `upload.mimeTypes` | images, SVG, PDF | Accepted MIME types; add `video/mp4` to accept videos |
| `media.fields` | `[]` | Extra fields on media files, see below |
| `ffmpeg` | `null` | Path of the ffmpeg program, for stills of videos; see below |
| `fieldTypes` | `[]` | Extra field type classes, see [extending.md](extending.md) |
| `extensions` | `[]` | Extension classes besides the ones packages declare, see [extending.md](extending.md) |

## Extra fields on media

A project can give every file in the library fields of its own, declared
like schema fields:

```php
'desk' => [
    'media' => [
        'fields' => [
            'socialOk' => ['type' => 'bool', 'label' => 'Für Social Media freigegeben',
                           'default' => ['upload' => true, 'import' => false]],
            'source' => ['type' => 'select', 'label' => 'Quelle',
                         'options' => ['own' => 'Eigenes Foto', 'press' => 'Pressebild']],
        ],
    ],
],
```

Allowed types: `bool`, `text`, `textarea`, `select`, `date`, with their
usual options. `default` is one value, or one per origin of the file
(`upload`, `import`, `generated`) as an array; for a `select` field an
array is the value itself. The default applies when a file enters the
library, by its origin: uploads in the UI are `upload`; `media:add` is
`upload` unless `--origin:import` says the file came from a third party
(a seed's media entry takes `"origin"` the same way); `media:check --adopt`
adds files as `import`; generated files are `generated`.

The values live in the column `extra` of the media table. They are set
when a file is added with `media:add <file> --extra:'{"socialOk": true}'`
(over the defaults), edited on the file's page in the library and with
`media:update <id|file> --extra:'{"socialOk": true}'` (only the given keys
change, unknown names are refused), filtered with
`media:list --where:socialOk=true`, exported to templates as `extra` of an
image variable (`{hero.extra.socialOk}`) and kept in `media.json` of the
export and by the import.

## Videos and generated files

With `video/mp4` in `upload.mimeTypes` the library accepts videos. Lists,
cards and pickers show a still of the video when `desk.ffmpeg` names the
ffmpeg program:

```php
'desk' => ['ffmpeg' => '/opt/homebrew/bin/ffmpeg'],
```

Without it they show a placeholder; Desk does not look for ffmpeg on its
own. Stills are cached below `dataPath/desk.cache/posters/`.

Every file has an origin: `upload`, `import` or `generated`. Generated
files are made by a package from a record (a rendered image, a video);
they are stored below `generated/<type>/<id>/` of the media folder, hidden
in the library unless the filter "Generated files" (or
`media:list --origin:generated`) asks for them, never count as duplicates
of uploads, and are removed with `media:prune --generated` once their
record is gone or archived. See [extending.md](extending.md) for the PHP
API.

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
