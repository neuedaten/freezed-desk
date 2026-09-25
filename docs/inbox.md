# Inbox module

Optional. The site's forms post to an endpoint on the web server; Desk
fetches the submissions from there. The module defines a protocol, not a
hosting decision.

## 1. Forms as files

`desk/forms/report.php`:

```php
<?php

return [
    'label' => 'Eintrag melden',
    'fields' => [
        'kind' => ['type' => 'select', 'label' => 'Art', 'required' => true,
                   'options' => ['change' => 'Änderung', 'missing' => 'Fehlt', 'error' => 'Fehler']],
        'item' => ['type' => 'text', 'label' => 'Eintrag'],
        'message' => ['type' => 'textarea', 'label' => 'Nachricht', 'required' => true, 'maxLength' => 2000],
        'name' => ['type' => 'text', 'label' => 'Name'],
        'email' => ['type' => 'email', 'label' => 'E-Mail', 'required' => true],
    ],
    'item' => ['field' => 'item', 'type' => 'entries'],   // assigns a submission to the record with that slug
    'spam' => ['honeypot' => 'website', 'minSeconds' => 3],
];
```

Field types: `text`, `textarea`, `email`, `url`, `number`, `bool`, `select`,
`date`; options `required`, `maxLength`, `minLength`, `min`, `max`,
`options`, `pattern`. The rules live in one dependency-free file,
`server/api/lib/FormRules.php`, used by the package and by the endpoint.

## 2. The form in the site

In a template of the site:

```html
<html xmlns:desk="http://typo3.org/ns/Neuedaten/FreezedDesk/ViewHelpers">
…
<desk:form name="report" action="/api/v1/report" item="{slug}" />
```

renders a plain `<form>` with a field per declaration, the honeypot, a
hidden token field and a submit button. A project that wants its own markup
adds a partial `Desk/Form` to its theme; it receives `form` (the
definition), `action`, `item`, `class` and `submit`. Without JavaScript the
form posts and the endpoint redirects to the thanks page; a small script can
fetch the token from `data-token-url`, post as JSON and show errors inline.

## 3. The endpoint

`server/api/` of the package is a reference: `index.php` plus
`lib/FormRules.php` and `config.example.php`, no dependencies. Copy it to
`static/api/` of the project (the build copies `static/` to `public/`), copy
the form files to `static/api/forms/`, create `config.php` from the example
and keep the SQLite file outside the web root.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/token` | A form token (timestamp plus HMAC) |
| POST | `/api/v1/<form>` | A submission, validated with the form file |
| GET | `/api/v1/inbox?since=<id>` | Submissions newer than `<id>`, Bearer token |
| POST | `/api/v1/inbox/ack` | `{"ids": […]}` confirms what Desk stored |

Spam rules: honeypot empty, token older than `minSeconds` and younger than a
day, hourly limit per IP hash. A notification mail per submission is
optional. Everything else -- newsletter, files, other channels -- is the
project's to add; Desk only needs the two inbox endpoints and the JSON shape
`{id, form, receivedAt, fields}`.

## 4. Fetching

```php
'desk' => [
    'inbox' => ['url' => 'https://example.org/api/v1/inbox', 'tokenEnv' => 'DESK_INBOX_TOKEN'],
],
```

```bash
export DESK_INBOX_TOKEN=…
vendor/bin/freezed-desk inbox
```

or the button in the UI. New submissions are stored once (form plus remote
id), the endpoint is told which ones, and the last id is remembered. A
submission whose form declares an `item` field is assigned to the record with
that slug; otherwise assign it by hand. "Apply change" opens the record with
the submission beside the form.
