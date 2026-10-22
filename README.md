# nuBuilder Next 0.8.373 – a modern front end for nuBuilder 4

> [!WARNING]
> **Alpha version – not ready for production.**
> nuBuilder Next is still under heavy development. Functions, screens and its own database tables can change from one version to
> the next, and parts of it (e.g. SQL Server) are not verified yet. Try it on a **copy** of your nuBuilder database, keep backups,
> and do not use it for data you cannot afford to lose.

nuBuilder Next runs on nuBuilder 4's own tables (`zzzzsys_*`) on **MySQL / MariaDB** or **SQL Server** – side by side with a live
nuBuilder on the same database. Forms, reports, procedures, users and access levels made in nuBuilder keep working in both.
nuBuilder's tables keep their structure: nuBuilder Next only adds its own tables (`zzzzsys_nunext_*`, created automatically),
creates nuBuilder's settings table `zzzzsys_config` only on request and changes columns only when you confirm it in the
structure check of an update.

What it adds: users and access control with admin roles, the form runtime and form builder, reports with PDF runner, report
designer and SQL builder, a customisable start page, list views, an audit trail, a notification centre, a REST API, dashboards,
branding, demo mode, a security check, backups and signed updates from the browser – in eleven languages, with light and dark mode.

- **Changelog:** [CHANGELOG.md](CHANGELOG.md)
- **Documentation:** in the app, user menu → *Documentation* (`?p=help`) – source `docs/nunext-docs.htm`
- **Development:** [CLAUDE.md](CLAUDE.md) (rules, architecture, conventions, tests, release) · open checks: [TODO.md](TODO.md)

## Requirements
- PHP 8.1 or newer (8.2+ recommended) with `pdo_mysql` or `pdo_sqlsrv`
- MySQL / MariaDB or SQL Server; an existing nuBuilder 4 database or an empty one
- Apache with `AllowOverride All` (for the `.htaccess` files) or nginx with the rules shown in Admin tools → *Security check*

## Install
1. Download **`nunext.zip`** from the [releases](../../releases) (the install package – `nunext-dev.zip` is only for the
   development) and unpack it into the web folder, e.g. next to or inside your nuBuilder folder.
2. Open it in the browser: while `nuconfig.php` is missing, the **installer** runs and writes `nuconfig.php`. It asks for the setup
   token from `_install/setup-token.txt` (read it by SFTP) – or create an empty file `install.allow` first.
   By hand instead: rename `nuconfig-sample.php` to `nuconfig.php` and enter your settings (an existing nuBuilder `nuconfig.php`
   also works); for an empty database run `install/schema_mysql.sql` (+ `schema_mysql_forms.sql`, + `notes_snippets_mysql.sql`) or
   `install/schema_mssql.sql`. The package ships only the sample, so a new version never overwrites your `nuconfig.php`.
3. Sign in as globeadmin or with any nuBuilder user.
4. Open **Admin tools → Security check** and fix what is red: HTTPS, a long globeadmin password, `$nuConfigBaseURL`, "Files on
   the web" (the web server must not hand out `core/`, `install/`, `README.md` …) and the PHP version.
5. Optional: cron for the housekeeping – `*/15 * * * * php /var/www/html/nunext/cron.php` as the web server's user.
6. Optional, for the WYSIWYG editor: TinyMCE is not in the package – copy `<nuBuilder>/third_party/tinymce/` to
   `<nuBuilder Next>/assets/vendor/tinymce/`.

## Updating
**Admin tools → Update** (globeadmin): choose the new `nunext.zip` – or copy it by FTP into the folder `_update/` and pick it
there – then *Check package* and *Install*. Packages are signed; the check shows "Signed by nuBuilder Next" and refuses a package
that was changed. A backup is made first (optionally with an SQL dump) and can be rolled back; database steps run automatically.

If the update page cannot be used (e.g. PHP may not write to the folder): unpack the new `nunext.zip` over the old folder (your
`nuconfig.php` and `_backup/` stay) and log in as globeadmin – the database steps run on the first request. Replace **all** files:
pages, `core/`, `lang/` and `assets/` belong together. If PHP OPcache runs with `opcache.validate_timestamps=0`, reload PHP-FPM /
the web server afterwards.

## Compatibility notes
- Login: `sus_login_name` + `sus_login_password`; globeadmin from `nuconfig.php` is checked first (as in nuBuilder).
- Passwords: bcrypt and legacy md5 are accepted; md5 is upgraded to bcrypt on login (`$nuConfigPasswordUpgrade = false` keeps
  md5 while an old nuBuilder must still read it; `$nuUseMd5PasswordHash` is honoured).
- Rights come from nuBuilder's access levels (`zzzzsys_access_form`, `zzzzsys_access_php`, permission items); the system screens
  are protected by rights on nuBuilder's system forms (`nuuser`, `nuaccess`, `nuform` …). Globeadmin may do everything.
- nuBuilder writes only edited fields, keeps its JSON formats (reports, SQL builder, form layouts) and its PHP / JavaScript
  functions – nuBuilder Next does the same, so records, forms and reports stay usable in nuBuilder.
- Language: `sus_language` with nuBuilder's names (German, English, French, Italian, Spanish, Portuguese, Russian, Chinese
  (Simplified), Dutch, Polish, Arabic); empty → the browser's language.
- Own sessions (cookie `nunext`); a shared login with nuBuilder is possible (`$nuConfigSharedLogin`).
- Differences to nuBuilder and what is not ported: `docs/porting-review.md`.

## Licence
GPL-3.0, like nuBuilder – see [LICENSE](LICENSE).
