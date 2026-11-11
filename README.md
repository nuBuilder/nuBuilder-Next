# nuBuilder Next 0.8.1131 – a modern front end for nuBuilder Forte 4

**An open-source, low-code platform for building and running web-based database applications – built on nuBuilder Forte 4.**

> [!WARNING]
> **Alpha version – not ready for production.** Functions, screens and its own tables can still change from one version to the
> next. Before using it on an existing nuBuilder Forte database, **back up the database and the files** – better still, try it on a
> copy first.

**Links:** [Releases](https://github.com/nuBuilder/nuBuilder-Next/releases) ·
[Features](FEATURES.md) · [Changelog](CHANGELOG.md) · [Forum](https://forum.nubuilder.cloud/) ·
documentation inside the app (user menu → *Documentation*, or F1)

## What it is

nuBuilder Next is a new front end for nuBuilder Forte 4. It runs on nuBuilder Forte's own tables on **MySQL / MariaDB** or
**SQL Server**, side by side with a live nuBuilder Forte on the same database: forms, reports, procedures, users and access levels
work in both. nuBuilder Next only adds tables of its own.

## Highlights

- **Form designer** – design forms and lists where they run, with drafts, history, validation rules and phone layouts.
- **Forms and reports with AI** – describe a form or a report, let any AI chat (or a connected AI service) write it, check it and apply it.
- **Lists that do more** – advanced search, coloured badges, totals and groups with subtotals, export to Excel, CSV or print.
- **Start page and dashboards** – tiles, favourites, search over everything, KPI tiles and charts.
- **Secure by default** – two-step login, passkeys, single sign-on, password policy, audit trail, a built-in security check.
- **Integration** – REST API, webhooks, e-mail templates, data import and export.
- **Managed in the browser** – installer, setup wizard, signed updates with backup and rollback.
- **For everyone** – phones, tablets and desktops, light and dark mode, eleven languages.

The full list: [FEATURES.md](FEATURES.md).

## Requirements

- PHP 8.1 or newer (8.2 recommended) with `pdo_mysql` or `pdo_sqlsrv`, `mbstring`, `openssl`, `zip` and `sodium`
- MySQL / MariaDB or SQL Server – a new, empty database or an existing nuBuilder Forte 4.9 database
- Apache with `AllowOverride All`, or nginx with the rules from the documentation

## Install

1. Download **`nunext.zip`** from the [releases](../../releases) and unpack it into a web folder.
2. Open it in the browser – the installer guides you (it asks for the setup token in `_install/setup-token.txt`).
3. Sign in as globeadmin and open **Security check** – fix what is red.
4. Optional: a cron job for housekeeping – `*/15 * * * * php /path/to/nunext/nucron.php`.

Prefer to set it up by hand, or install without internet? See *Installation* in the documentation.

## Updating

Start page → **Update**: upload the new `nunext.zip` or let the page fetch the newest release from GitHub, then *Check package*
and *Install*. Packages are signed, a backup is made first, and an update can be rolled back.

## nuBuilder Forte compatibility

nuBuilder Next uses nuBuilder Forte's tables, formats and PHP / JavaScript functions, so applications keep working in both. The
**Compatibility check** scans your application's code; the remaining differences are listed in the documentation
(*Code reference → Migrating from nuBuilder Forte 4.9*).

## Licence

GPL-3.0, like nuBuilder Forte – see [LICENSE](LICENSE).
