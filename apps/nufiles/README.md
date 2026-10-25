# nuFiles – file manager page for nuBuilder Next

Replaces Tiny File Manager (TFM). A nuBuilder Next **page form** (`nufiles`, built in since 0.8.479: `apps/nufiles/`) that uses only what nuBuilder Next already ships: Bootstrap 5.3.8, Bootstrap Icons 1.13.2, CodeMirror 5.65.21 and `nupage.js`. It has no login of its own and no third-party code. It runs on nuBuilder Next's session, access levels, permission items, CSRF protection and password confirmation.

Version 1.0.0 · page API 1 · part of nuBuilder Next since 0.8.479 (tested with 0.8.460 and 0.8.479; PHP 8.2/8.3, MariaDB, Chromium)

## Features

- Shares: named folders from the settings. A share can be inside or outside the web root, or be the web root itself (type `code`).
- Browse, sort, search names (`*`/`?` patterns), info, copy path, keyboard shortcuts (Del, F2, Backspace, Ctrl+A).
- Uploads: chunked and resumable, several files at once, drag & drop of files and folders, a progress panel, an optional "replace files with the same name".
- New folder or file, rename, copy or move (also between shares), delete into the recycle bin, restore, purge.
- Text and code editor (CodeMirror): syntax colours, search/replace, Ctrl+S, Windows line ends kept, conflict detection, PHP and JSON syntax check before saving, older versions kept.
- Preview of images, PDF, audio and video. Download of files, and of folders or a selection as a zip.
- Zip and unzip, protected against zip-slip and zip bombs.
- Log table of all changes (globeadmin view), recycle bin with automatic purge.
- English and German ("du"; de-CH writes ss), light, dark and auto themes, small screens.

## Install

nuBuilder Next brings this folder (`apps/nufiles/`, since 0.8.481; 0.8.479–0.8.480 had it in `custom/pages/nufiles/`, which older
versions refused to install) and updates its files with every release – the settings (in the database) and the data folder
`custom/pages/nufiles/_data/` stay. A copy in `custom/pages/nufiles/` takes precedence over the built-in one. Open *File Manager* (start page, user menu, form ⋮ menu or `nuVendorLogin('TFM')`) as globeadmin and click
*Set up the File Manager*: it creates the page form `nufiles` (open mode tab) with two shares – *Uploads* (the folder of the upload
procedure `nu_upload_file_template`) and *Web root (code)*. Then:

1. Access levels: give the form to the access levels that should see it (the globeadmin always has it). Which shares and rights a user gets comes from the `grants` in the settings.
2. Permission items (optional): create items such as `FILES_UPLOADS` and assign them to users.
3. Web server: the data folder must not be served. The default `custom/pages/nufiles/_data` is covered by nuBuilder Next's `custom/.htaccess` on Apache. On **nginx**, set `data_dir` to a folder outside the web root, or deny `/custom/` (see SECURITY below).

Do not edit the files of `apps/nufiles/` – an update replaces them. Copy the folder to `custom/pages/` (same or another code) for your own version.

The log table `nufiles_log` is created on first use (MySQL/MariaDB and SQL Server).

## Settings (form builder → Settings, JSON)

| Key | Default | Meaning |
|---|---|---|
| `shares` | `[]` | List of shares, see below |
| `reauth` | `"code"` | Password confirmation (nuBuilder Next's `reauth_minutes`): `code` = any access to code shares, `code_write` = changes in code shares, `all_write` = every change, `none` |
| `data_dir` | `{PAGE}/_data` | Recycle bin, versions, upload chunks. `{PAGE}`, `{NUNEXT}` and `{NUBUILDER}` are allowed. Best outside the web root |
| `max_upload_mb` | 200 | Default limit per file (a share can set its own) |
| `chunk_mb` | 2 | Upload chunk size (1–32). Must stay below PHP `upload_max_filesize` / `post_max_size` |
| `zip_max_mb` / `zip_max_entries` | 500 / 5000 | Limits for zip, unzip and folder downloads (checked **before** extracting) |
| `copy_max_mb` | 2000 | Limit for one copy |
| `editor_max_kb` | 2048 | Largest file the editor opens |
| `search_max_results` | 500 | Search stops after this many hits (and after 8 s) |
| `list_max_entries` | 10000 | Largest folder listing |
| `trash_days` | 30 | Recycle bin entries older than this are purged (0 = never) |
| `versions_keep` | 10 | Older versions kept per edited file (0 = none) |
| `log_table` | `nufiles_log` | Name of the log table |
| `log_reads` | `false` | Also log list, read, preview |

### A share

```json
{"id": "uploads", "label": "Uploads", "path": "{NUBUILDER}/uploads", "type": "files",
 "extensions": "pdf, png, jpg, docx, xlsx, zip", "max_upload_mb": 100,
 "grants": [{"permission": ["FILES_UPLOADS"], "rights": ["view", "upload", "mkdir", "edit", "rename", "copy", "delete", "zip"]},
            {"access": ["SALES"], "rights": ["view"]}]}
```

| Key | Default | Meaning |
|---|---|---|
| `id` | – | `a-z 0-9 _ -`, at most 40 characters, used in URLs |
| `label` | id | Shown name |
| `path` | – | Existing folder, absolute; may start with `{DOCUMENT_ROOT}`, `{NUNEXT}`, `{NUBUILDER}`, `{PAGE}` |
| `type` | `files` | `files` = documents with the protections below; `code` = **globeadmin only**, no name or content rules (grants are ignored) |
| `readonly` | false | View and download only, also for the globeadmin |
| `extensions` | all | Allow-list of extensions (files shares) |
| `max_upload_mb` | global | Limit per file |
| `show_hidden` | false (code: true) | Show names that start with a dot |
| `web_active_types` | false | Allow HTML, SVG, XML, JS (they would run on the portal's origin if the folder is served) |
| `content_check` | true | Refuse files that contain `<?php` / `<?=` or are HTML/SVG by content |
| `editor`, `zip`, `trash` | true | Switch the editor, zip/unzip and the recycle bin off for this share |
| `deny` | `[]` | Hidden and unreachable names/paths (`fnmatch`, e.g. `".git"`, `"nuconfig.php"`, `"private/*"`) |
| `grants` | `[]` | `{permission: [items] | access: [access level codes], rights: [...]}`. A user gets the union of all grants that match |

Rights are `view`, `upload`, `mkdir`, `edit` (editor, new file), `rename` (also move inside the share), `copy`, `delete` (also restore, replace on upload, move out of the share) and `zip` (zip, unzip). Every right includes `view`. A move into another share needs `delete` on the source and `upload` on the target. A copy into another share needs `upload` on the target. Purge and the log are for the globeadmin only. While the globeadmin views as another user, and in demo mode, everything is read-only.

## Security model

- **Paths**: relative to a share. `..`, NUL and control characters, backslashes, more than 64 levels or 4096 bytes are refused. Every existing path is checked with `realpath()` against the share root, so symlinks that point outside are shown but cannot be opened.
- **Names in files shares**: every dot part is checked against nuBuilder Next's dangerous-upload list (`NuSecurity::dangerousUploadName` when it exists). That means no `x.php.txt` or `.htaccess`, no dot files, no HTML/SVG/JS unless allowed, no Windows-reserved names, no bidi or invisible characters, and the optional extension allow-list applies. The same rules apply to rename, new file, copy or move into the share, unzip and restore.
- **Content**: `<?php` anywhere in the first 64 KB, `<?=` in text, and HTML/SVG/XML by MIME sniffing are refused in files shares. This applies to upload, unzip, copy from another share and editor saves. Note: an *uncompressed* zip that contains PHP source is refused too.
- **Downloads**: always `application/octet-stream` + `attachment`. The preview is inline only for sniffed image, PDF, audio, video and plain-text types. Every file response sends `X-Content-Type-Options: nosniff` and its own CSP with `sandbox` (not for PDF).
- **Page CSP**: `script-src 'self'`, no inline script.
- **Unzip**: entries are planned before anything is written. Absolute and `..` names, symlinks and denied names are skipped and reported. The declared total size and entry count are limited, and each entry is copied with a cap of its declared size, so a lying header gives `err_zip_size`. Archives always unpack into a new folder.
- **Editor**: atomic save (temporary file + rename), a sha1 conflict check, the old version kept, PHP checked with `token_get_all(TOKEN_PARSE)` (no `exec`).
- **Uploads**: tickets are bound to the user, offsets are checked, and a file never grows beyond the size announced at the start. The `.part` file lives in the data folder, and the finished file is checked again before it is moved into place.
- **CSRF**: every POST goes through nuBuilder Next's check (fetch: `X-CSRF-Token`, form download: `_csrf`). Write actions are POST only.
- **Code shares**: globeadmin only, plus a password confirmation (nuBuilder Next's re-authentication, in a small window; the request is retried afterwards).
- **Data folder**: never listed or reachable through any share, even when a share contains it. It gets its own `.htaccess` and `index.html`.

nginx example (if the data folder stays in the page folder):

```nginx
location ^~ /custom/ { deny all; }          # nuBuilder Next serves page assets through index.php?p=pageasset
```

## Files

```
index.php            page shell, CSP, boot data
lib/guard.php        path / name / content guard (NufGuard, NufError)
lib/config.php       settings → shares + rights of the current user (NufConfig)
lib/fs.php           file operations, trash, versions, chunked uploads, zip, sending files (NufFs)
lib/log.php          log table (NufLog)
lib/i18n.php         English / German texts (NufI18n)
lib/api.php          ?action=… JSON endpoints (NufApi)
assets/app.js        the UI (vanilla JavaScript + Bootstrap + CodeMirror)
assets/app.css       styles (light / dark)
assets/reauth.js     closes the password confirmation window
settings.example.json
```

## nuBuilder Next core changes

Done in 0.8.479: C1 (File Manager entries open nuFiles, Tiny File Manager removed), C2 `$nu->vendor()`, C3 `$nu->recentAuth()` /
`$nu->reauthUrl()`, C4 `$nu->dataDir()` (= `<page>/_data`), C5 `$nu->dangerousName()`, C9 (shipped with nuBuilder Next). The page
calls them through `NufApi::future()`, so it still runs on older versions. Open: C6 page cron hook, C7 Security check entries for the
settings, C10 more languages.
