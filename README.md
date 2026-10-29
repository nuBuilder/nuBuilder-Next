# nuBuilder Next 0.8.677 – a modern front end for nuBuilder Forte 4

**An open-source, low-code platform for building and running web-based database applications – built on nuBuilder Forte 4.**

> [!WARNING]
> **Alpha version – not ready for production.**
> nuBuilder Next is still under heavy development. Functions, screens and its own database tables can change from one version to
> the next. New versions may introduce breaking changes, and compatibility with previous versions or existing nuBuilder Forte
> applications is not guaranteed. Some parts, such as SQL Server support, have not yet been verified.
>
> **For now, do not use it on an existing nuBuilder Forte (4.x) database. Install it on a new, empty database only.**

---

### Quick Links:

- 📦 [Releases](https://github.com/nuBuilder/nuBuilder-Next/releases) – download `nunext.zip`
- 📝 [Changelog](https://github.com/nuBuilder/nuBuilder-Next/blob/main/CHANGELOG.md)
- 💬 [nuBuilder Forte Community Forum](https://forum.nubuilder.cloud/)
- 📖 Documentation – in the app, user menu → *Documentation* (`?p=help`, tabs Guide and Code reference); source: one file per page in `docs/pages/`, in reading order in `docs/toc.json`

---

## Overview

**nuBuilder Next** is a modern front end for nuBuilder Forte 4. It runs on nuBuilder Forte's own tables (`zzzzsys_*`) on **MySQL / MariaDB** or **SQL Server** – side by side with a live nuBuilder Forte on the same database. Forms, reports, procedures, users and access levels made in nuBuilder Forte keep working in both.

nuBuilder Forte's tables keep their structure: nuBuilder Next only adds its own tables (`zzzzsys_nunext_*`, created automatically), creates nuBuilder Forte's settings table `zzzzsys_config` only on request and changes columns only when you confirm it in the structure check of an update.

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
- ✅ **Your Language, Your Theme** – eleven languages (Arabic right-to-left), light, dark or automatic mode, five built-in designs plus your own (colours, buttons, fields, tabs, fonts – per access level or chosen by the user); checked against WCAG 2.1 A/AA.

### 🏗️ For Building Business Applications

- ✅ **Low-Code Form Designer** – design a form where it runs: select, drag and arrange objects, a properties panel for every object type, the list's columns on the list itself, code and history in one place, drafts you publish when ready, keyboard shortcuts (rename, copy and paste objects – also between forms –, undo) and a safe mode for broken form code; a New form wizard that picks the table's columns and their order; inputs, selects, lookups, sub-forms, charts, HTML, a WYSIWYG editor and run buttons; change a column's type with a data check first; text or UUID keys for new tables; nuBuilder Forte's form events keep working.
- ✅ **Page Forms** – your own PHP apps inside nuBuilder Next, with its login, rights and a stable page API; work on a draft while users keep the live version, publish it and roll back to earlier versions.
- ✅ **Report Designer & SQL Builder** – design reports visually, build queries without writing SQL and run reports as PDF.
- ✅ **Dashboard Builder** – KPI tiles, charts (ApexCharts) and tables built from SQL, on the start page or on any form.
- ✅ **Code Where You Need It** – PHP procedures, JavaScript hooks and hash variables as in nuBuilder Forte; a code editor with syntax colours, search / replace, one-click formatting for PHP, SQL, JavaScript, HTML and CSS, and an Insert menu with every documented function plus suggestions while typing.
- ✅ **E-mail & Templates** – SMTP settings with a test mail and a connection check that sends nothing, login with OAuth 2 (Google, Microsoft 365), e-mail templates with field placeholders and an e-mail log.
- ✅ **CSV Import** – into any table, with automatic encoding / separator detection and column mapping.
- ✅ **Form Cloner & App Cloner** – copy a single form (or just write its SQL) or the whole application.
- ✅ **AI Prompt Generator** – builds a complete prompt with your table structure to paste into any AI tool; new or changed forms written by an AI, checked before they are created.
- ✅ **REST API** – OAuth 2.0 client credentials, notifications, reports and unattended updates, with a playground to try it.
- ✅ **Compatibility Check** – scans the code of your nuBuilder Forte application and shows which functions are supported.
- ✅ **Sample Application** – customers, orders, reports and dashboards to learn from.

### 🛡️ For Administrators

- ✅ **Customisable Start Page** – for the globeadmin and admin roles: tiles in groups or tabs, tile sizes, favourites, own and shared layouts, search with Ctrl+K. Users get a start page per access level (forms, reports, procedures, links – tiles shown by permission item, team or department) that they can personalise.
- ✅ **Advanced User Management** – users with custom fields of your own, access levels (also additional ones per user, with an end date, and a view of the effective rights), admin roles and permission items; CSV user import with a template; a welcome e-mail with a set-password link; own and shared list views.
- ✅ **Security & Authentication** – two-step login, one-time login links, login rate limit and a built-in Security check.
- ✅ **Act as User** – see the application exactly as a user sees it (view only or full access).
- ✅ **Audit Trail & Log** – every change per field, with history and restore; a log of logins, exports and admin changes.
- ✅ **Send Notifications** – to chosen users, departments or teams, with read receipts.
- ✅ **Maintenance from the Browser** – signed updates with backup, rollback and a preview of database changes; a `nuconfig.php` editor that checks before saving and inserts missing variables; Global code – nuBuilder Forte's Setup code (Header, Style, Include) plus global JavaScript / CSS for every page, with ready-made templates; System status; logged-in users (log out idle ones); phpMyAdmin, Adminer and File Manager links; housekeeping cron.
- ✅ **Easy Start** – installer (also a database set-up when `nuconfig.php` was written by hand), setup wizard, a tutorial in three versions (basic, in depth, coming from nuBuilder Forte) and built-in documentation (F1 opens the page for the screen you are on).
- ✅ **Branding & Demo Mode** – logo (shown everywhere, only on the sign-in pages or nowhere) and environment label; read-only demo users.

---

## Requirements
- PHP 8.1 or newer (8.2+ recommended) with `pdo_mysql` or `pdo_sqlsrv`
- MySQL / MariaDB or SQL Server with a new, empty database (not an existing nuBuilder Forte database for now)
- Apache with `AllowOverride All` (for the `.htaccess` files) or nginx with the rules shown in Admin tools → *Security check*

## Install
1. Download **`nunext.zip`** from the [releases](../../releases) (the install package – `nunext-dev.zip` is only for the
   development) and unpack it into the web folder.
2. Open it in the browser: while `nuconfig.php` is missing, the **installer** runs and writes `nuconfig.php`. It asks for the setup
   token from `_install/setup-token.txt` (read it by SFTP) – or create an empty file `install.allow` first.
   By hand instead: rename `nuconfig-sample.php` to `nuconfig.php` and enter your settings (an existing nuBuilder Forte `nuconfig.php`
   also works); on an empty database (or with tables missing) the globeadmin then gets the page *Set up the database* –
   or run `install/schema_mysql.sql` (+ `schema_mysql_forms.sql`, + `notes_snippets_mysql.sql`) or `install/schema_mssql.sql` yourself. The package ships only the sample, so a new version never overwrites your `nuconfig.php`.
3. Sign in as globeadmin or with any nuBuilder Forte user.
4. Open **Admin tools → Security check** and fix what is red: HTTPS, a long globeadmin password, `$nuConfigBaseURL`, "Files on
   the web" (the web server must not hand out `core/`, `install/`, `README.md` …) and the PHP version.
5. Optional: cron for the housekeeping – `*/15 * * * * php /var/www/html/nunext/cron.php` as the web server's user.
6. Third-party code lives in `vendor/`: `vendor/bundled/` comes with the package, `vendor/packs/` holds the signed **library packs**
   (TCPDF for reports, TinyMCE, phpMyAdmin, Adminer, Uppy – from the GitHub release `libraries`; PHPMailer is in the
   package). The installer offers them (step *Libraries*), later **Update → Libraries**. Without internet: copy the
   `nunext-lib-*.zip` files into `_install/` or `_update/` first.

## Updating
**Admin tools → Update** (globeadmin): choose the new `nunext.zip` – or copy it by FTP into the folder `_update/` and pick it
there, or let the page download the newest release from GitHub – then *Check package* and *Install*. Packages are signed; the check shows "Signed by nuBuilder Next" and refuses a package
that was changed. A backup is made first (with an SQL dump of nuBuilder's `zzzzsys_*` tables – all an update can change – or of the whole database) and can be rolled back; database steps run automatically.

If the update page cannot be used (e.g. PHP may not write to the folder): unpack the new `nunext.zip` over the old folder (your
`nuconfig.php` and `_backup/` stay) and log in as globeadmin – the database steps run on the first request. Replace **all** files:
pages, `core/`, `lang/` and `assets/` belong together. If PHP OPcache runs with `opcache.validate_timestamps=0`, reload PHP-FPM /
the web server afterwards.

## Compatibility notes
- Login: `sus_login_name` + `sus_login_password`; globeadmin from `nuconfig.php` is checked first (as in nuBuilder Forte).
- Passwords: bcrypt and legacy md5 are accepted; md5 is upgraded to bcrypt on login (`$nuConfigPasswordUpgrade = false` keeps
  md5 while an old nuBuilder Forte must still read it; `$nuUseMd5PasswordHash` is honoured).
- Rights come from nuBuilder Forte's access levels (`zzzzsys_access_form`, `zzzzsys_access_php`, permission items); the system screens
  are protected by rights on nuBuilder Forte's system forms (`nuuser`, `nuaccess`, `nuform` …). Globeadmin may do everything.
- nuBuilder Forte writes only edited fields, keeps its JSON formats (reports, SQL builder, form layouts) and its PHP / JavaScript
  functions – nuBuilder Next does the same, so records, forms and reports stay usable in nuBuilder Forte.
- Language: `sus_language` with nuBuilder Forte's names (German, English, French, Italian, Spanish, Portuguese, Russian, Chinese
  (Simplified), Dutch, Polish, Arabic); empty → the browser's language.
- Own sessions (cookie `nunext`); a shared login with nuBuilder Forte is possible (`$nuConfigSharedLogin`).
- Differences to nuBuilder Forte and what is not ported: help → Code reference → *Migrating from nuBuilder Forte 4.9* (`docs/pages/12-02-porting.htm`).

---

## Licence
GPL-3.0, like nuBuilder Forte – see [LICENSE](LICENSE).
