# nuBuilder Next 0.8.384 – a modern front end for nuBuilder 4

**An open-source, low-code platform for building and running web-based database applications – built on nuBuilder 4.**

> [!WARNING]
> **Alpha version – not ready for production.**
> nuBuilder Next is still under heavy development. Functions, screens and its own database tables can change from one version to
> the next. New versions may introduce breaking changes, and compatibility with previous versions or existing nuBuilder
> applications is not guaranteed. Some parts, such as SQL Server support, have not yet been verified.
>
> **For now, do not use it on an existing nuBuilder database. Install it on a new, empty database only.**

---

### Quick Links:

- 📦 [Releases](https://github.com/nuBuilder/nuBuilder-Next/releases) – download `nunext.zip`
- 📝 [Changelog](https://github.com/nuBuilder/nuBuilder-Next/blob/main/CHANGELOG.md)
- 💬 [nuBuilder Community Forum](https://forum.nubuilder.cloud/)
- 📖 Documentation – in the app, user menu → *Documentation* (`?p=help`); source `docs/nunext-docs.htm`

---

## Overview

**nuBuilder Next** is a modern front end for nuBuilder 4. It runs on nuBuilder's own tables (`zzzzsys_*`) on **MySQL / MariaDB** or **SQL Server** – side by side with a live nuBuilder on the same database. Forms, reports, procedures, users and access levels made in nuBuilder keep working in both.

nuBuilder's tables keep their structure: nuBuilder Next only adds its own tables (`zzzzsys_nunext_*`, created automatically), creates nuBuilder's settings table `zzzzsys_config` only on request and changes columns only when you confirm it in the structure check of an update.

nuBuilder Next streamlines everyday database work:

- **Search, Create, Read, Update, Delete** – plus Clone, Print, Copy to Excel, CSV export and an audit trail with restore.

---

## Why nuBuilder Next?

- **🚀 Rapid Development**: Build forms, reports, dashboards and queries visually – and add code only where you need it.
- **📱 Modern & Responsive**: A clean interface in light and dark mode that works on phones, tablets and desktops.
- **🔒 Secure by Default**: Two-step login, admin roles, an audit trail, a built-in security check and signed updates.
- **🛠️ Managed in the Browser**: Installer, setup wizard and updates with backup and rollback – all from the browser.
- **🌎 Open-Source & Web-Based**: Free (GPL-3.0), in eleven languages – users need nothing but a browser.

---

## Core Features

### 👥 For Everyone

- ✅ **Responsive** – works on phones, tablets and desktops; menus, lists and toolbars adapt to the screen.
- ✅ **Fast Lists** – search, sort and page through records; Print, Copy (pastes as a table into Excel or Word) and CSV export.
- ✅ **Comfortable Editing** – sub-forms, lookups and file uploads; required and unique fields are marked; unsaved changes are shown; Clone in one click.
- ✅ **Dashboards on the Start Page** – KPI tiles, charts and tables for your access level.
- ✅ **Notification Centre** – a bell with the number of unread messages, a page with all notifications, optionally also by e-mail.
- ✅ **Keyboard Shortcuts** – save, clone, delete, search, print and more with Ctrl+Shift+key; the ⋮ menu lists them all.
- ✅ **Secure Sign-in** – two-step login (authenticator app or e-mail code) and *Forgot password* with a one-time e-mail link.
- ✅ **Your Language, Your Theme** – eleven languages (Arabic right-to-left), light, dark or automatic mode; checked against WCAG 2.1 A/AA.

### 🏗️ For Building Business Applications

- ✅ **Low-Code Form Builder** – inputs, selects, lookups, sub-forms, charts, HTML, a WYSIWYG editor and run buttons; nuBuilder's form events keep working.
- ✅ **Report Designer & SQL Builder** – design reports visually, build queries without writing SQL and run reports as PDF.
- ✅ **Dashboard Builder** – KPI tiles, charts (ApexCharts) and tables built from SQL, on the start page or on any form.
- ✅ **Code Where You Need It** – PHP procedures, JavaScript hooks and hash variables as in nuBuilder; a code editor with syntax colours, search / replace and one-click formatting for PHP, SQL, JavaScript, HTML and CSS.
- ✅ **E-mail & Templates** – SMTP settings with a test mail, e-mail templates with field placeholders and an e-mail log.
- ✅ **CSV Import** – into any table, with automatic encoding / separator detection and column mapping.
- ✅ **Cloner & App Cloner** – copy a single form or the whole application.
- ✅ **AI Prompt Generator** – builds a complete prompt with your table structure to paste into any AI tool.
- ✅ **REST API** – OAuth 2.0 client credentials, notifications, reports and unattended updates, with a playground to try it.
- ✅ **Compatibility Check** – scans the code of your nuBuilder application and shows which functions are supported.
- ✅ **Sample Application** – customers, orders, reports and dashboards to learn from.

### 🛡️ For Administrators

- ✅ **Customisable Start Page** – for the globeadmin and admin roles: tiles in groups or tabs, tile sizes, favourites, own and shared layouts, search with Ctrl+K. Normal users see their access level's dashboards and the forms they may open.
- ✅ **Advanced User Management** – users, access levels, admin roles and permission items; CSV user import with a template; a welcome e-mail with a set-password link; own and shared list views.
- ✅ **Security & Authentication** – two-step login, one-time login links, login rate limit and a built-in Security check.
- ✅ **Act as User** – see the application exactly as a user sees it (view only or full access).
- ✅ **Audit Trail & Log** – every change per field, with history and restore; a log of logins, exports and admin changes.
- ✅ **Send Notifications** – to chosen users, departments or teams, with read receipts.
- ✅ **Maintenance from the Browser** – signed updates with backup, rollback and a preview of database changes; a `nuconfig.php` editor that checks before saving; System status; logged-in users; phpMyAdmin, Adminer and File Manager links; housekeeping cron.
- ✅ **Easy Start** – installer, setup wizard, a step-by-step tutorial and built-in documentation.
- ✅ **Branding & Demo Mode** – logo, accent colour and environment label; read-only demo users.

---

## Requirements
- PHP 8.1 or newer (8.2+ recommended) with `pdo_mysql` or `pdo_sqlsrv`
- MySQL / MariaDB or SQL Server with a new, empty database (not an existing nuBuilder database for now)
- Apache with `AllowOverride All` (for the `.htaccess` files) or nginx with the rules shown in Admin tools → *Security check*

## Install
1. Download **`nunext.zip`** from the [releases](../../releases) (the install package – `nunext-dev.zip` is only for the
   development) and unpack it into the web folder.
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
there, or let the page download the newest release from GitHub – then *Check package* and *Install*. Packages are signed; the check shows "Signed by nuBuilder Next" and refuses a package
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

---

## Licence
GPL-3.0, like nuBuilder – see [LICENSE](LICENSE).
