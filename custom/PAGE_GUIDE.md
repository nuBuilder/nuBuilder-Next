# Writing a nuBuilder Next page (form type "page")

A page is a PHP app in `custom/pages/<form code>/` that nuBuilder Next runs after it has checked the login
and the user's access to the page form. You never write login code. The folder name is exactly the form
code (letters, digits, `_` and `-`; case matters on Linux).

API version: 1 (`$nu->apiVersion()`).

## Hard rules
1. The first line after `<?php` is:  `defined('NU_PAGE') || exit;`   (every .php file, also secrets.php)
2. Use only the `$nu` object for user, rights, data and URLs. Never require nuconfig.php,
   never create your own PDO, never read zzzzsys_session.
3. Never call session_start(), session_name(), session_destroy(), session_regenerate_id().
   Store your own state in `$nu->session()`.
4. SQL: prepared statements only (`$nu->all($sql, [$a, $b])`). Never put request values
   into SQL text. Table / column names from the request must be checked against a fixed list.
5. Output: escape every value with `$nu->h()`. JSON with `$nu->json($data)`.
6. Every POST needs the CSRF token: `<?= $nu->csrfField() ?>` in forms, `NuPage.fetch()` in JS
   (or the header `X-CSRF-Token: <?= $nu->h($nu->csrfToken()) ?>`). nuBuilder Next rejects POSTs without it.
7. Links to your own page: `$nu->url(['action' => 'x'])`. Files in assets/: `$nu->asset('assets/app.css')`.
   Never hard-code the server path or the domain. Relative links that are only a query (`href="?view=x"`,
   `fetch('?id=1')`, `header('Location: ?x=1')`) drop the page from the address – build them from `$nu->url()`.
   A GET form needs the hidden fields `p=page` and `f=<form code>` (or use POST).
8. Access: the access level decides who may open the page. Finer rules with
   `$nu->require($nu->hasPermission('...') || $nu->inTeam('...'));`
   `$nu->recordId()` is NOT an access check: verify the user may see that record.
9. Slow work (remote calls, big queries): call `$nu->releaseSession()` first.
10. Configuration (lists, colours, limits) belongs in the page settings JSON (form builder),
    read with `$nu->setting('key')`, not in constants.
    Secrets (API keys, passwords) go in `secrets.php` in the page folder
    (`return ['key' => '...'];`, guard line first), read with `$nu->secret('key')`.
    Never put secrets in the settings JSON, in JS or in HTML.
11. Styling is free: output a full HTML document. Load your own CSS / JS from assets/
    or a CDN (the page's Content-Security-Policy only restricts framing unless the administrator sets one).
12. `exit` works, but `$nu->json()`, `$nu->redirect()`, `$nu->notFound()` and `$nu->deny()` are the clean way to end.

## Porting an existing PHP app
| Before (standalone app) | Page |
| --- | --- |
| `require '/path/to/nuconfig.php'`, `new PDO(...)` | `$dbh = $nu->db();` |
| `session_start()`, reading nuBuilder's session table for the user | `$u = $nu->user();` |
| own permission / department / position queries | `$nu->hasPermission()`, `$nu->inDepartment()`, `$nu->hasPosition()` |
| a role cache in `$_SESSION` | nothing – the rights are read on every request |
| own CSRF token and check | `$nu->csrfField()` / `$nu->csrfToken()` – checked by nuBuilder Next (field `_csrf` or header `X-CSRF-Token`) |
| own security headers | nothing – set by nuBuilder Next |
| configuration constants at the top | `$nu->setting('key')` (page settings JSON) |
| passwords / tokens in the code | `$nu->secret('key')` (secrets.php) |
| images next to the script | `assets/…` + `$nu->asset('assets/logo.svg')` |
| `$_SESSION['my_app']` | `$nu->session()` |
| `session_write_close()` before slow work | `$nu->releaseSession()` |

`$nu->db()` uses real prepared statements on MySQL (placeholders work in `LIMIT ? OFFSET ?` too). Session settings such as
`SET time_zone` on `$nu->db()` apply to this request only.

## Structure
    custom/pages/<form code>/index.php     entry, routes by $nu->action()
    custom/pages/<form code>/*.php         more code (require __DIR__ . '/x.php')
    custom/pages/<form code>/secrets.php   optional: API keys, passwords (never in a package or an export)
    custom/pages/<form code>/assets/       css, js, mjs, map, png, jpg, gif, svg, webp, ico, woff, woff2, ttf, json, txt, pdf
    custom/lib/                            optional shared PHP of the installation

The current directory is the page folder while the page runs.

## Typical index.php
    <?php
    defined('NU_PAGE') || exit;
    $nu->require($nu->hasPermission('my_app'));
    switch ($nu->action()) {
        case 'save':
            $id = $nu->insert('my_table', ['name' => $nu->param('name')]);
            $nu->json(['ok' => true, 'id' => $id]);
        case '':
            $rows = $nu->all('SELECT my_table_id, name FROM my_table ORDER BY name');
            require __DIR__ . '/view.php';
            break;
        default:
            $nu->notFound();
    }

## The $nu object

### Who is logged in
| Method | Result |
| --- | --- |
| `user(): array` | id, login, name, first_name, last_name, display, email, team, department, position, permissions (array of idents), code, language, access_id, access_code, access_name, globeadmin (bool), impersonated (bool). Strings are '' when empty. The globeadmin: id 'globeadmin', e-mail from the configuration, team / department / position ''. |
| `userId(): string` | the user's id |
| `isGlobeadmin(): bool` | |
| `realUser(): ?array` | while the globeadmin (or an administrator) acts as another user: id, login, globeadmin of the real person; else null |
| `hasPermission(string ...$idents): bool` | holds ANY of these permission items (the globeadmin: always true) |
| `permissions(): array` | all permission item idents of the user |
| `inTeam(string ...$teams): bool` | user's team is one of these (trimmed, case-insensitive) |
| `inDepartment(string ...$deps): bool` | department |
| `hasPosition(string ...$pos): bool` | position |
| `can(string $form, string $right = 'view'): bool` | a right on any form (id or code): view, add, save, delete, clone, new, print, create |
| `rights(): array` | rights on THIS page form – use the access level as the app's roles (e.g. save = may edit) |
| `require(bool $ok, string $message = ''): void` | 403 (HTML or JSON) when false |
| `users(array $filter = []): array` | other users: id, login, name, first_name, last_name, display, email, team, department, position. Filter keys: team, department, position (a value or a list), permission (ident), active (true = not expired). Never passwords. |

### Context
| Method | Result |
| --- | --- |
| `form(): array` | id, code, description of the page form |
| `slug(): string` | folder name (= form code) |
| `dir(): string` | absolute folder path |
| `recordId(): ?string` | `r` of the URL, null when absent, '-1' = new. Not an access check. |
| `parent(): ?array` | form_id, form_code, record_id of the form that opened the page (only when the user may view that form), or null |
| `props(): array` | nuSetProperty values of the opening form |
| `hash(string $text): string` | replaces #USER_ID#, #LOGIN_NAME#, #RECORD_ID#, #FORM_CODE#, #PARENT_FORM_ID#, #PARENT_RECORD_ID# … like nuBuilder |
| `setting(string $key, mixed $default = null): mixed` | the page settings JSON, dot paths: `setting('regions.north.colour')` |
| `settings(): array` | the whole settings JSON |
| `secret(string $key, mixed $default = null): mixed` | a value of secrets.php |
| `param(string $name, ?string $default = null): ?string` | GET / POST value as string (POST wins); null when absent or an array |
| `isPost(): bool` | |
| `isAjax(): bool` | the request wants JSON (Accept: application/json or X-Requested-With) |
| `action(): string` | `?action=` of the URL, '' when absent |
| `lang(): string` | user language, e.g. en-GB, de-CH |
| `theme(): string` | light, dark or auto – the user's choice in nuBuilder Next |
| `readOnly(): bool` | true in demo mode and while the globeadmin views as another user: hide write actions |

### Data
| Method | Result |
| --- | --- |
| `db(): PDO` | nuBuilder Next's connection (exceptions on errors, associative rows) |
| `dbType(): string` | mysql or sqlsrv |
| `all(string $sql, array $params = []): array` | all rows |
| `one(string $sql, array $params = []): ?array` | first row or null |
| `value(string $sql, array $params = []): mixed` | first column of the first row |
| `exec(string $sql, array $params = []): int` | affected rows |
| `insert(string $table, array $row): string` | fills `<table>_id` with a new id when missing, returns the id |
| `update(string $table, string $id, array $row): int` | WHERE `<table>_id` = $id |
| `delete(string $table, string $id): int` | WHERE `<table>_id` = $id |
| `transaction(callable $fn): mixed` | $fn receives $nu; rolled back on an exception |
| `newId(): string` | a new nuBuilder-style id |
| `audit(string $table, string $id, string $action, array $before = [], array $after = []): void` | a change in nuBuilder Next's audit trail (action insert / update / delete) |

Table and column names of insert / update / delete must match `^[A-Za-z0-9_]+$` (an exception otherwise, nothing is written).

### Output and links
| Method | Result |
| --- | --- |
| `h(mixed $s): string` | HTML escape |
| `csrfToken(): string` / `csrfField(): string` | the token / a hidden `_csrf` field |
| `url(array $params = []): string` | this page (keeps r, pf, pr, ps): `url(['action' => 'save'])` |
| `asset(string $path): string` | URL of a file in assets/ with a version stamp |
| `formUrl(string $form, ?string $record = null): string` | a nuBuilder Next form (id or code) – list, or a record ('-1' = new) |
| `homeUrl(): string` | the start page |
| `sdk(): string` | the `<script>` tag of the JavaScript helpers (below) |
| `json(mixed $data, int $status = 200): never` | sends JSON and ends |
| `redirect(string $url): never` | same origin only |
| `notFound(string $msg = ''): never` | 404, HTML or JSON by request type |
| `deny(string $msg = ''): never` | 403 |
| `notify(string\|array $userIds, string $title, string $text = '', ?string $link = null): void` | a notification in the bell of these users (ids or logins) |
| `log(string $message, array $data = []): void` | a line in Debug results (flag `page:<form code>`) |
| `session(): array` | by reference: the page's own session store. Read-only after releaseSession(). |
| `releaseSession(): void` | ends the session lock for slow work |
| `apiVersion(): int` | 1 |

## JavaScript (optional)
    <?= $nu->sdk() ?>
then:

    NuPage.csrf                            the CSRF token
    NuPage.fetch(url, options)             fetch() + X-CSRF-Token + Accept: application/json;
                                           401 (signed out) reloads to the login
    NuPage.toast('Saved', 'success')       success | info | warning | danger (inside nuBuilder Next; else the console)
    NuPage.refreshParent()                 reload the form that shows / opened the page
    NuPage.close()                         back, or close the tab
    NuPage.openForm('code', id, {target: 'self' | 'tab'})
    NuPage.setTitle('text')                title of the nuBuilder Next page around the iframe
    NuPage.resize()                        report the height now (automatic when the height is "auto")
    NuPage.inFrame                         true inside nuBuilder Next

Error answers of nuBuilder Next to fetch requests are JSON: `{"error": "login" | "csrf" | "forbidden" | "not_found" | "server" | "read_only", "message": "..."}`.
Long-running polls can add `&passive=1` to the URL: they then do not keep the session alive.

## nuBuilder tables you may read
    zzzzsys_user (sus_*), zzzzsys_access, zzzzsys_permission_item, zzzzsys_user_permission,
    plus all application tables. Prefer $nu->user() / $nu->hasPermission() over reading
    the system tables yourself.

## Testing
    Open the page form in nuBuilder Next. Errors show details (message, file, line) to the globeadmin only.
    Direct URLs to custom/... must NOT work - if they do, tell the administrator.
