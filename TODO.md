# nuBuilder Next – to do

## 0.8.372 Update from the folder _update – please verify
- Copy nunext.zip by FTP into <nuBuilder Next>/_update/ (the Update page creates the folder with a deny-all .htaccess – check once
  that https://…/nunext/_update/<file>.zip is refused, Security check → Files on the web), then Update → "Packages in the folder
  _update" → Check package → Install. The zip is deleted there after the install; unsigned zips are listed but cannot be chosen.

## 0.8.371 Users list on phones – please verify
- Below 576 px: Import and Print sit in a ⋮ menu right of Add (Back, Add, ⋮, view menu); larger screens unchanged.
- Other lists with several buttons (forms, procedures, reports …) can use the same option ('more') – say which ones.

## 0.8.370 nuSetSelect2 / $.fn.select2 – please verify
- Form code that calls nuSetSelect2(id, obj) or uses Select2 itself ($('#x').select2({…}), .select2('open' | 'data' | 'destroy' …))
  gets Tom Select now (before: a placeholder that did nothing). Admin tools → Compatibility check lists the form code that calls it –
  open those forms once. Select2 options without a Tom Select counterpart (templateResult, ajax, matcher …) are ignored.

## 0.8.369 nuScroll – please verify
- Input type nuScroll did nothing in nuBuilder Next (plain text field). Now ↑ / ↓ step through the object's list as in nuBuilder (a
  value not in the list: ↓ starts with the first entry, ↑ with the last – nuBuilder skipped the first), own text still possible.
- The list is also a <datalist>: on a phone tap the field and choose (iOS Safari / Android Chrome show it above the keyboard or as a
  menu); on a desktop a small arrow in the field. Try it once on your phone – the look depends on the browser.

## 0.8.368 Accessibility, PHPStan level 3 – please verify
- Colours: grey outline buttons, "+ Add", links on the grey page, the active tab and the red Save are a shade darker in light mode,
  red / green texts and buttons lighter in dark mode (WCAG AA 4.5:1) – look at a few pages in both modes.
- CodeMirror has dark colours in dark mode now (it kept the light ones everywhere): procedures, form code, builder, nuconfig.php.
- Screen readers: fields without a label (sub-form cells, lookup code / description, selects with search) get a name; the logo
  link in dark mode, pager buttons and status icons too. Tables and boxes that scroll can be reached with Tab.
- Phones: help tables scroll inside their box, the update page's file field fits.
- dev/tests/a11y.js finds no critical / serious points on ~40 pages (light, dark, phone). Moderate / minor points are listed but
  not fixed yet (e.g. heading order, landmark regions) – run it with LEVEL=critical,serious,moderate to see them.

## 0.8.367 Security review of the parts after 0.8.207 – please verify
- Fixed: the history of a record (audit trail) showed every audited column to whoever could open the form – also admin-only /
  hidden fields and columns that only other forms show. Now only the columns of the form and its sub-forms the user sees
  (globeadmin and the audit right: all). Restore: refused for a field the user does not see or may not change (read-only, hidden)
  and for a record his list does not show ("Records only through the form's list").
- Fixed (defence in depth): a notification link that is not http(s)://, index.php… or ?… (written past the form, e.g. by SQL) is not
  sent to the bell / page / e-mail.
- Checked, no change needed: notifications (rights to send / see / change, own department / team only, CSRF on the bell's POST),
  report download links (random token stored as hash, download counted in one statement, jobs bound to the API client), one-time
  login tokens (constant-time compare, compare-and-swap, counted for the login limit, masked for demo visitors), REST API (scopes
  AND the service user's rights, admin-equivalent levels only by the globeadmin, service users not changeable, HMAC secrets, report
  runs per minute, unattended update only with a signed package, https only, redirects https only).
- Open (decide): the history of a DELETED record is shown to whoever can open the form – "Records only through the form's list"
  cannot check a record that is gone. The record ids are random, the list of deleted records needs the audit right.
- Open (decide): POST /v1/system/update downloads from any https address the API client names (also internal addresses of the
  server's network) – the package must be signed anyway; an allow-list of hosts would close it completely.

## 0.8.366 Large data, indexes, browser cache – please verify
- Load test (dev/tests/load.js, 500 users, 100,000 customers / orders, 100,000 audit rows, 1,000 notifications, 200,000 log rows):
  every page ≤ 330 ms, the bell 8 ms per check – the notification and audit volume points above are covered. On the live server the
  numbers depend on the host (InfinityFree: shared CPU); the slowest are full-text searches of the log and of big browse lists.
- Sub-form link columns get an index: the form builder adds it when a sub-form is saved; the sample application on install / refresh.
  Existing forms built before 0.8.366: open each sub-form object in the builder and save, or add the index by hand.
- Browser cache: assets/.htaccess (mod_headers / mod_expires) – Lighthouse "efficient cache lifetimes" should be gone on Apache;
  check the response headers of a .css file once (Cache-Control: public, max-age=31536000, immutable). nginx: rule in Help → Security.

## 0.8.365 More automatic tests – please verify
- The user form's "Create login link" did nothing unless `$nuConfigAutoLogin` was on (its form was only rendered then) – fixed; try it once.
- Now tested at every release (dev/tests/e2e.js, 37 tests): nuBuilder hooks (Before Save / Before Delete / After Save / Before Edit,
  a lookup's After Browse with #ROW#, an object's JavaScript event, a hidden procedure), lookup by code, permission items, admin
  roles (view only, 403 elsewhere), demo mode ("Try the demo", no save), one-time login link (once only), user CSV import (the new
  user logs in), branding, dashboards (data per access level), login rate limit. compat.js: logins across both (25 tests).

## 0.8.364 Cross-tests against nuBuilder – please verify
- New records: fields you did not touch and that are empty are no longer written (column default / NULL, as nuBuilder) – forms whose
  code relies on '' instead of NULL in new records (e.g. `WHERE x = ''`) behave like in nuBuilder now.
- Negative numbers show the minus after the prefix as nuBuilder: `€ -99,95`, `USD -500.00` (brackets formats unchanged).
- SQL builder: the SQL text is rebuilt only after a change in the builder; clause rows keep their ids.
- Later, on a COPY of a real nuBuilder database: `dev/tests/compat.js` (see CLAUDE.md) – it tests every application form in both.

## 0.8.363 Start page: list in 2 columns – please verify
- Customise → Tiles (or ▾ next to Customise) → "List in 2 columns": full width, row by row; one column below 768 px. Dragging tiles in
  Customise, favourites filter, tabs display, dark mode.

## 0.8.362 PHP2Shell warning – please verify
- Update PHP on every server (8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 or newer, or the distribution's / host's patched package), then restart
  PHP-FPM / Apache. Security check → "PHP version" must be green; the self-test's PHP check must be OK.
- Distribution packages with a backported fix keep a lower version number (e.g. Ubuntu's 8.3.6) and still show red – a newer
  check could read the distribution's patch level; decide whether that is wanted.
- Open decision: raise the minimum for install / update from PHP 8.1 to 8.2 (8.1 gets no official fix).

## 0.8.361 Tests – please verify
- Self-test (developer mode → Admin tools → Self-test) on the development server: all OK? Warnings for e-mail / housekeeping are expected
  where those are not set up.

## 0.8.357 Notification centre – please verify
- Polling every 60 s per open tab: on large installations (hundreds of users online) watch the server load; the interval is NuNotify::POLL.
- "Live" notifications are matched in PHP against the last 300 current notifications of the installation – enough for now; a SQL filter would be the next step.
- E-mails go out in the request that publishes (up to 2000 recipients) – for big audiences a queue would be better.
- SQL Server not tested (tables and queries are plain).

## 0.8.350 Audit trail – please verify
- On SQL Server: restoring a deleted row of a table with an IDENTITY key is refused (no `SET IDENTITY_INSERT`); MySQL / MariaDB tested.
- Large imports: one audit row per changed column – a 100 000-row CSV import with 10 columns writes 1 000 000 rows (exclude the table if not wanted).
- Not captured: changes by PHP code / procedures with their own SQL (nuRunQuery …), nuBuilder itself, phpMyAdmin. Database triggers would be the next step if needed.

## 0.8.346 Database changes of an update – please verify
- The first package that shows the list is the one AFTER 0.8.346 (an installation must already run 0.8.346 to read install/db_changes.json); older packages show "not described".
- New step = new entry in `NuMigrate::CHANGES` (release.py stops otherwise). Own tables: missing columns are now added automatically (NuSchema::create) – check on SQL Server.

## 0.8.341 Demo mode – please verify
- `$nuConfigIsDemo`: the demo globeadmin login of a live nuBuilder config works in both apps (same name / password).
- Demo data reset on SQL Server: tables with an IDENTITY column need `SET IDENTITY_INSERT` – not handled yet; MySQL / MariaDB tested.
- Save limit counts per IP address: visitors behind one proxy / company network share the 10 saves (`$nuConfigTrustProxy` for the real address).

## 0.8.340 Reports through the REST API – please verify
- `"async": true` on PHP-FPM: the report should run right after the 202 answer (`fastcgi_finish_request`); tested only with PHP's built-in server, where the job runs at the first status request.
- E-mail delivery (`"email": {…}`) – not sent in the test environment (no mail server).
- Very large reports: memory and time limit (`nu_time_limit(300)`); async jobs on servers without PHP-FPM still run inside the status request.
- Possible next step: report runs in the API playground show the PDF (the playground reads JSON only, so `Accept: application/pdf` is not offered there).

## 0.8.339 One-time login link (nuBuilder's LOGIN_TOKEN) – please verify
- A link from nuBuilder (or another system that writes `sus_json` LOGIN_TOKEN) logs in once in nuBuilder Next. The other direction
  (a link made in nuBuilder Next logs in to nuBuilder, a password set in nuBuilder Next works there) is tested automatically since 0.8.365.
- SQL Server: the token is replaced with `UPDATE … WHERE sus_json = ?` (compare and swap on NVARCHAR(MAX)) – not tested on a real SQL Server.
- E-mail with the link (user form envelope, API `"email": true`): not sent in the test environment (no mail server) – check the text and the button.
- nuBuilder's older md5 login path compares the token with `LIKE` and the typed password (`%` may match any token) – consider reporting it to nuBuilder.

## 0.8.329 List views – please verify / next steps
- Users and Access levels lists run on core/nulistview.php + core/nulistdefs.php; tested with Chromium (views, editor, phone cards,
  shared views for an access level, defaults, Print with the view's columns, export / import). SQL Server: the catalogue SQL
  (subqueries, CASE WHEN EXISTS) not tested on a real server.
- 0.8.334: forms, procedures, reports, files (tiles / table), items, translations, permission items, prompts, log and e-mail log run on
  list views too – check them once with real data (column choice of the default views, sort, filters, Print). Debug results keep their
  own message list (selection, delete, auto refresh). Not yet: e-mail templates, SQL builder, dashboards, API clients, logged-in users.
- Later: form browse lists (key 'form:<id>', columns from the form builder, views per access level).

## 0.8.328 Tutorial – please verify
- Read the tutorial (docs/nunext-tutorial.htm, 36 lessons) once as the owner: wording, order, exercises; add screenshots later
  (asked for "no pictures for now"). Keep it in step with new features – like the docs.
- Tested with Chromium: every lesson renders, every page link answers 200, every documentation anchor exists, progress / banner /
  hide, role user (sample_anna, 15 lessons), user without role (403), phone width.

## 0.8.327 User import – please verify
- Users → Import… (new tab) → target "Users": skipped logins, access level by code or for all, passwords hashed + change at first
  login, welcome e-mail option. Tested with Chromium on MariaDB (zzzzsys_user is MyISAM there: the user import checks all rows
  first and writes afterwards, no transaction needed). Welcome e-mails from the import not tested with a real mail server.
- SQL Server / SQLite not tested for the user import.

## 0.8.323 Admin roles – please verify
- Default roles, user form "Admin role", start page / Ctrl+K / help filtered, pages refused (403) without the right, "View only" pages,
  no over-granting, own role locked – tested with Chromium (sample_anna as Developer and manager role, sample_carla as Support).
- Go through the pages once with a view-only role: some pages may still offer a button that only fails on the server (403).
- Not in the roles yet: the form runtime's developer menu (⋮ → arrange objects, Properties) stays globeadmin only; error details
  (SQL messages) are shown to the globeadmin only.

## 0.8.321 Start page search, pins, Ctrl+K – please verify
- Pin entries of ▾ menus, Customize → Add tile → "Page of nuBuilder Next", start page search (English words too), Ctrl+K on every
  page (globeadmin). Tested with Chromium; Safari / Firefox: Ctrl+K vs. Cmd+K, the search field's clear button.
- Ideas: Ctrl+K for users (limited to their rights), help chapters and records (e.g. a customer by name) in the search.

## 0.8.316 Admin tools on the start page – please verify
- Fresh start page: groups System, Look, Data, Integration (display Tabs: own tab "Admin tools"); a start page customized before gets
  them once at the end (only tools not placed yet; saved right away, layout v2). System status (?p=vendor): time zone, missing tools,
  InnoDB, demo mode; its badge is cached 5 minutes in the session.
- Texts that still say "Admin tools → X" mean the tab / group of that name – check that they read well.

## 0.8.315 Deutsch (DE / CH) – please verify
- lang/de.php is German German now (ß after a long vowel / diphthong from a word list – außerhalb, Größe, schließen; „quotes“);
  lang/de-CH.php is generated (ß → ss, „“ → «»). Read the German texts once for ß words the list missed (php dev/lang_variants.php --de
  finds known Swiss spellings). Shortcuts stay "Ctrl" (German keyboards say "Strg").
- Austria / Liechtenstein: no own version yet (browser de-AT / de-LI → Deutsch (DE)).

## 0.8.314 English (UK / Australia / US) – please verify
- lang/en.php is British English now; lang/en-US.php is generated (dev/lang_variants.php, word list + exceptions) – read the generated
  texts once; lang/en-AU.php is empty (Australia spells like the UK) – add texts by hand if needed.
- Variant per user (sus_json NUNEXT_LANG_VARIANT): user form, language menu, e-mails in the recipient's variant; nuBuilder keeps "English".

## 0.8.312 Setup wizard – please verify
- First sign-in after a new installation and on an existing nuBuilder (nuconfig.php present): language, time zone, title, label,
  password change (signs out), 2FA, sample data. Tested here with Chromium on PHP's built-in server, also with a read-only nuconfig.php.
- $nuConfigSettingsFromDB = true: values go to zzzzsys_config where the row exists – not tested with a filled settings table.
- Tabs in the underline look (0.8.312): check nuBuilder form tabs, vertical tabs and dark mode on a real application.

## 0.8.301 Unattended update (command line / API) – please verify
- On a real server: php cron.php update as the web server's user (file owner), with --url over https and a proxy; disabled
  proc_open (second phase then on the next cron run / update page); the API through a TLS proxy; OPcache with validate_timestamps=0.
- Idea for later: update feed (a URL with the newest version; cron checks, e-mails or installs within a time window).

## 0.8.298 Dashboards – please verify
- Start page per access level (user), HTML object <div data-nu-dashboard="CODE"> on launch and edit forms, new record ("after saving"),
  SQL / SQL builder / PHP procedure sources, date filter, test run – tested here with the sample data (Chromium, MariaDB).
- Safari: style-src-attr / style nonce for ApexCharts on the start page (Chrome tested); SQL Server: read-only run of widgets
  (BEGIN TRAN … ROLLBACK) and the new tables – not tested on a real SQL Server.
- Ideas for later: drag & drop of widgets, more chart types (heatmap, mixed), CSV export of a widget, filters besides the date.

## 0.8.297 Branding – please verify
- Upload a logo (PNG / SVG) and a dark-mode logo, accent colour, label: top bar, sign-in pages (also with $nuWelcomeBodyInnerHTML),
  form pages, dark mode, phone. Tested here with Chromium on PHP's built-in server.
- $nuConfigEnvironmentLabel / $nuConfigEnvironmentColor in nuconfig.php on the test copy: label read-only on the page and wins.
- SQL Server: logos in zzzzsys_file and the setting row – not tested on a real SQL Server.

## 0.8.295 App cloner: snapshot, streaming, size-limited batches, UTC – please verify
- Verified on MariaDB 10.11: direct copy and SQL file 0 differences, also with the source session in time zone +05:00, a table with
  1.5 MB BLOB rows and max_allowed_packet 4 MB, "Empty it first", and while another process kept writing (both tables equal in the copy).
- Please verify on MySQL 8 and on shared hosting: START TRANSACTION WITH CONSISTENT SNAPSHOT and unbuffered queries are allowed there
  (if the snapshot cannot be started, the clone runs without it).

## 0.8.291 App cloner 1:1 – verified on MariaDB 10.11, please verify on MySQL 8
- Test database with every object kind (dev: see changelog 0.8.291) cloned directly and as SQL file: 0 differences (definitions, every
  byte of every row, sequences, views, routines incl. sql_mode, trigger order, events); also on a server in strict MySQL-8-like
  sql_mode, with "Empty it first", and for the full nuBuilder test database (157 objects).
- MySQL 8 differences not tested here: invisible columns / functional indexes, DEFAULT (expression), utf8mb4_0900 collations.

## 0.8.282 Adminer – please verify
- Apache: third_party/adminer/ gets a deny-all .htaccess – check that adminer.php is not served directly (Security check probes it);
  nginx: block it by hand.
- SQL Server: Adminer through dbadmin.php (driver mssql, pdo_sqlsrv / sqlsrv extension) and zzzzsys_session created by NuVendor on
  SQL Server – not tested on a real SQL Server.

## 0.8.276 saved Cloner / App cloner settings – please verify
- Open a configuration saved with nuBuilder's App cloner / Cloner in nuBuilder Next and the other way round (zzzzsys_app_cloner,
  zzzzsys_cloner) – especially the tables list (cla_opt_2_include) and subforms / iframe forms (clo_subforms: form ids).
- SQL Server: both tables created by update step 0004 (TINYINT / NVARCHAR) – not tested on a real SQL Server.

## 0.8.207 security review – done, please verify
All points S1–S26 and B1–B10 of the review (0.8.206) are built; details in CHANGELOG.md "0.8.207" and Help → Security. To check on the live server:
- [ ] **Files on the web** (Admin tools → Security check): all green? On Debian / Ubuntu Apache's default `AllowOverride None` ignores every
      `.htaccess` – set `AllowOverride All` for the web folder, or nginx rules (Help → Security). If the site answers 500 after the update,
      the server forbids a directive of the new root `.htaccess` (`<FilesMatch>` / `Require`): delete that file by SFTP and report it.
- [ ] The globeadmin is logged out once after the update (session now bound to name + password); new session cookies belong to the
      nuBuilder Next folder only. Shared login with nuBuilder ($nuConfigSharedLogin) and phpMyAdmin / File Manager still work?
- [ ] "Only the globeadmin grants admin rights" is **on** by default: users who manage users or access levels (nuuser / nuaccess rights)
      can no longer change admin-equivalent users, their own level or its rights – intended for your users?
- [ ] "Forgot password" without `$nuConfigBaseURL`: uses the address the globeadmin last used; set `$nuConfigBaseURL` to be sure.
- [ ] Before switching on "Do not save read-only and hidden fields" / "Records only through the form's list": try your forms as a user
      (JavaScript that fills hidden fields; browse SQL that needs Before Browse code is not checked).
- [ ] Cron: `*/15 * * * * sudo -u www-data php /var/www/html/nunext/cron.php` – run as the web server's user (files it creates must stay
      writable for PHP).
- [ ] **Signing key**: `nunext-signing.key` (Ed25519, delivered separately with 0.8.207) signs the packages; keep it private and outside the
      project. A new development session needs it next to the project folder (`../nunext-signing.key`) – without it `dev/release.py`
      builds unsigned packages (warning on the update page). Losing it means: new key pair + `NuUpdate::SIGN_PUBLIC` in a release.
- [ ] Continue development from **`nunext-dev.zip`** (with CLAUDE.md, TODO.md, dev/, .github/); `nunext.zip` is only for installing.
- [ ] Update from GitHub (0.8.374): on the live servers check that the host allows outgoing HTTPS to github.com (InfinityFree may not) –
      the section shows the error otherwise; a cron with `php cron.php update --github` updates unattended.
- [ ] B1/B4: the project is on GitHub now (nuBuilder/nuBuilder-Next, public) – `.github/workflows/ci.yml` runs lint, PHPStan,
      ESLint, unit tests and the browser smoke test on MariaDB; the SQL Server job is written but not verified (no form tables in
      `schema_mssql.sql`, see "Install scripts").
- [ ] SQL Server (untested here): read-only preview (BEGIN TRAN … ROLLBACK, `PDO::SQLSRV_ATTR_QUERY_TIMEOUT`), `NuDB::escapeString`.
- [ ] B8 only partly done: `NuApiClient`, `NuApiPlayground` and `NuReportEngine` are in their own files; the pages with most logic
      (`pages/nuuser.php`, `nuemail.php`, `nuform.php`) still mix actions and HTML – move the actions into core classes step by step.
- [ ] Since 0.8.224 the package contains all libraries of `assets/vendor/` (except TinyMCE). They were rebuilt from the official packages
      (CodeMirror 5.65.21 bundle = core + matchbrackets + closebrackets + modes xml/javascript/css/htmlmixed/clike/php/sql; add-ons file =
      comment/dialog/searchcursor/search/jump-to-line; formatter = nuBuilder's beautify / sql-formatter + Prettier 3.3.3 + plugin-php 0.25.0).
      Check the code editors (procedures, form builder, double-click editor) and Format on the live server. The package is now ~2.4 MB:
      PHP's default upload_max_filesize of 2M is too small (update page → Server → PHP upload limit).
- [ ] TCPDF 7 (0.8.232, optional): with the Composer bundle tcpdf-7.0.19-for-nunext.zip in `<nuBuilder Next>/third_party/tcpdf/` (PHP ≥ 8.2)
      run your own reports once and compare with TCPDF 6 – the samples are identical, other features (HTML cells, barcodes, images)
      go through TCPDF 7's compatibility layer. nuBuilder itself keeps TCPDF 6. TCPDF 6 gets no more fixes (end of life).
- [ ] Tiny File Manager ≤ 2.6 (nuBuilder ships 2.6) has an unfixed path traversal (CVE-2025-15138): remove `third_party/tinyfilemanager`
      when not needed, watch for a new version.

## For the developer to verify (assumptions made in 0.7)
- [ ] **phpMyAdmin**: copy `<nuBuilder>/third_party/nudb/` to `<nuBuilder Next>/third_party/nudb/` (with nuBuilder's `config.inc.php`).
      Assumption: the unchanged nuBuilder config works, because it reads `../../nuconfig.php` (= nuBuilder Next's) and checks cookie `nu_PMA`
      against a `zzzzsys_session` row with `global_access = 1`. Tested locally with nuBuilder 4.9's nudb.
- [ ] **Tiny File Manager**: copy `<nuBuilder>/third_party/tinyfilemanager/` to `<nuBuilder Next>/third_party/tinyfilemanager/` (with `config.php`).
      Same mechanism, cookie `nu_TFM`. Assumptions to check:
  - [ ] It opens with `$root_path = $_SERVER['DOCUMENT_ROOT']` (nuBuilder's setting) – the whole web root. Restrict in `config.php` if wanted.
  - [ ] Its CSS/JS come from CDNs (jsdelivr, cdnjs) – the server must be allowed to load them.
  - [ ] Only the globeadmin gets the menu entry; nuBuilder's `$nuConfigFileMangerUsers` (non-admin users) is not supported.
- [ ] The tool login row in `zzzzsys_session` (`zzzzsys_session_id` = `nx…`, `sss_access.session.nunext_vendor`) – check that nuBuilder's own
      session clean-up does not trip over it, and that the nuBuilder list on *Logged-in users* hides it.
- [ ] Cookie path `/` (as nuBuilder): opening phpMyAdmin from nuBuilder and from nuBuilder Next overwrites the same cookie – harmless, but test both.
- [ ] Tools in another place (e.g. directly inside nuBuilder): `$nuConfigPMAURL` / `$nuConfigTFMURL` – untested.
- [ ] Logged-in users: an ended session now logs the user out with the very next request (one indexed read per request).
- [ ] Since 0.8.207 `.htaccess` files block core/, pages/, lang/, install/, docs/, dev/ and the notes (Apache 2.2 + 2.4) – nginx: rules in Help → Security.

## 0.8.160 nuconfig.php editor – please verify
- [ ] On the live server (InfinityFree etc.): the web server may write nuconfig.php and its folder – otherwise the page is read only.
- [ ] A `nuconfig.php` shared with nuBuilder (NU_CONFIG or nuBuilder's own file): saving changes nuBuilder's settings too.
- [ ] Demo mode: the page refuses demo users (tested in code only, not with a demo login).
- [ ] MSSQL: the database check of the new settings – only tested on MySQL / MariaDB.

## 0.8.140 demo mode – please verify
- [ ] Demo visitors see all data and code of the application (as in nuBuilder) – use a database with demo data, and check procedures /
      form code for passwords or API keys before switching it on.
- [ ] Procedures with "Demo access" (nuBuilder's default for new procedures is on) run read only: those that write now end with
      "Not available in the demo" instead of writing (nuBuilder lets them write) – intended?
- [ ] Form code (Before Browse / Before Edit) that writes to real tables shows the demo message instead of the list; temporary tables
      (#TABLE_ID#) work. Check your forms as the demo user (Act as user on the demo user).
- [ ] MSSQL: the database guard was tested on MySQL / MariaDB only (temporary tables #… and SELECT … INTO are handled).

## 0.8.134 buttons after the title – please verify
- [ ] Forms with their own CSS or JavaScript that relied on the action buttons sitting at the right edge (e.g. absolute positions
      inside `#nuActionHolder`, or `nuSetToolbarStyle`-like tweaks in the setup header): check them on a wide screen.

## 0.8.17 act as user – please verify
- [ ] Full access: records saved while acting carry the user in nuBuilder's audit columns (by design); only nuBuilder Next's log knows it was the globeadmin.
- [ ] nuBuilder hooks (Before Edit / Before Browse) still run in view-only mode – PHP that writes data on opening a form would still write.

## 0.8.14 permission items – please verify
- [ ] Permission items on your nuBuilder database: users with items assigned in nuBuilder show them ticked in nuBuilder Next, and nuBuilder still sees changes made in nuBuilder Next.
- [ ] MSSQL: tables created by nuBuilder Next (`NuPermission::ensureTables`) – only tested on MySQL / MariaDB.

## 0.8.12 code formatter – please verify
- [ ] Format your own PHP procedures / events: Prettier rewrites style (spacing, quotes, braces) – check that the result suits you; Undo reverts it.
- [ ] T-SQL formatting (MSSQL) – only tested with simple statements.

## Install scripts
- [ ] MSSQL: `install/schema_mssql.sql` has no form tables (`zzzzsys_form`, `zzzzsys_tab`, `zzzzsys_browse`, `zzzzsys_object`, `zzzzsys_event`, `zzzzsys_php`) –
      on an empty MSSQL database the form builder and forms do not work yet (MySQL: `schema_mysql_forms.sql`).

## 0.8.4 hash variables – please verify
- [ ] Setup → Header on the live server: now run as JavaScript (as nuBuilder). Check that your global functions (nuLoadEditGlobal …) work and nothing shows as text.
- [ ] `#USER_PERMISSIONS#` reads nuBuilder's `zzzzsys_user_permission` / `zzzzsys_permission_item` – untested with real permission data.
- [ ] Code that relied on raw (unescaped) hash values, e.g. a field holding a list `'a','b'` used in `IN (#list#)`, gets escaped quotes – use a property set in PHP instead.

## 0.8.2 sample data – please verify
- [ ] Install the sample data on the live server and click through all sample forms in nuBuilder Next **and** nuBuilder (Admin tools → Sample data).
- [ ] Sample data on MSSQL – only tested on MySQL / MariaDB (nuBuilder database and nuBuilder Next's install scripts).
- [ ] The sample procedures and the start page use hash variables `#USER_FIRST_NAME#` etc. – empty for the globeadmin (as in nuBuilder).

## 0.8.1 installer – please verify
- [ ] On the live server: the installer only appears while `nuconfig.php` is missing – keep a copy of yours when testing.
- [ ] Since 0.8.207 every visitor needs the token (or install.allow), except PHP's built-in server opened from the same computer.
- [ ] `_install/.htaccess` denies access to the token on Apache 2.4; on nginx the token file is readable while the installer runs (add a rule or install from localhost).
- [ ] MSSQL install with nuBuilder Next's scripts (`schema_mssql.sql`) – only tested on MySQL/MariaDB here.
- [ ] Import of `nubuilder4.sql` on shared hosting with low `max_execution_time` (the installer asks for 600 s).

## 0.8.0 – please verify
- [ ] First update to 0.8.0 by hand (the update page comes with it); from then on Admin tools → Update.
- [ ] On the live server: is the nuBuilder Next folder writable for PHP (Update page → Server)? Otherwise updates stay manual.
- [ ] nginx or other servers that ignore `.htaccess`: set `$nuConfigBackupPath` outside the web root (backups contain nuBuilder Next's tables, incl. 2FA data).
- [ ] Rollback restores program files only; database steps are not undone (they are additive so far).
- [ ] Since 0.8.207 packages are signed (Ed25519) and checked; unsigned ones show a warning.

## 0.7.2 – please verify
- [ ] Delete the old file names after the update (list in CHANGELOG.md, section 0.7.2).
- [ ] `nuRefreshSelectObject` with a subform's form id and no prefix refreshes that field in every row (nuBuilder only refreshes `#selectId`).
- [ ] `nuRefreshDisplayObject` does not mark the form as edited (display objects are not saved); nuBuilder uses nuSetValue, which does.
- [ ] `nuSubformAddFilter` on form-layout subforms puts the filters in a bar above the rows (nuBuilder only has grid subforms).

## Translations (0.7.1) – please verify
- [ ] Not translated yet: options of static select lists, placeholders, nuBuilder Next's own messages (they come from `lang/de.php` / `en.php`).
- [ ] Switching the menu language to EN writes `sus_language = 'English'` → no translations for that user (also in nuBuilder).

## Code snippets
- [ ] `cot_scope` ("Selectable in": Forms / Custom Code, Setup / Header, SQL, Procedures/PHP): store the scope and offer
      snippets in the matching code editors of the builder (nuBuilder's "Insert-Snippet" lookups). The install SQL leaves the field out for now.

## 0.8.183 reports – please verify
- [ ] Compare a few of your nuBuilder reports printed by nuBuilder and by nuBuilder Next (same TCPDF): page breaks, grown fields, group sums.
- [ ] Sorting and grouping happen in PHP (NULL first, numbers as numbers, text case-insensitive) – nuBuilder sorts in MySQL with the table's collation.
- [ ] Text wrapping counts UTF-8 characters correctly (nuBuilder measures bytes) – long non-ASCII texts may break at a different place.
- [ ] Report sections are printed without their designer color (nuBuilder draws no section background either).
- [ ] `nuPreviewReport(code)` and `nuEmailReportPDF(code, to, subject, body, fileName, callback)` are nuBuilder Next functions (nuBuilder has no such functions) – are these the signatures you want?
- [ ] Not ported: nuBuilder's "Fast report" (form nufrlaunch) and the SQL builder of a form's browse SQL (zzzzsys_select rows with sse_system).
- [ ] TCPDF is taken from nuBuilder (third_party/tcpdf) – not bundled (27 MB with fonts). Bundle a slim copy?

## 0.8.197 backups with database – please verify
- [ ] Restoring the database from a backup is manual (SQL only download → phpMyAdmin). An automatic "Restore database" (dry run, type the database name, backup first) was left out on purpose – build it?
- [ ] Time limits on shared hosting: a large dump can be stopped by the server; the next page view reports it. Check with your largest database.
- [ ] SQLite: no SQL dump – the option is greyed out. (SQL Server: supported since 0.8.198, see below.)

## 0.8.198 App cloner on SQL Server – please verify (written without an SQL Server to test against)
The SQL generation was checked with simulated catalog data and a T-SQL parser; a real SQL Server was not available. Please try:
- [ ] Dry run and Run into a new database on the same server (same login): tables, row counts compared at the end, the app opens with the copy's nuconfig.php.
- [ ] Download SQL and run the file with SSMS or `sqlcmd -i` into an empty database; run it a second time on the same database (it should replace everything).
- [ ] A table with IDENTITY, a varbinary / image column (e.g. files), nvarchar with umlauts, datetime / datetime2 values.
- [ ] A view that uses a function, a procedure, a DML trigger, a foreign key with ON DELETE CASCADE.
- [ ] Mode "Add to it" on a database that has the tables already (tables are left as they are, rows are added – duplicates fail).
- [ ] Rights: a login without VIEW DEFINITION – functions / views should be reported, not break the run.
- [ ] Backups with "whole database" on SQL Server (Update page) and the estimate shown next to the option.

## 0.8.245 delete form with its table – please verify
- [ ] Form builder → Delete form → "Also delete the table": after more than 15 minutes without password confirmation the dialog
      shows "Confirm password" and comes back to the dialog afterwards (not tested here). On SQL Server: DROP TABLE of the form's table.

## 0.8.263 start page edit mode – please verify
- [ ] Customize on a real phone / tablet (iOS Safari, Android Chrome): hold a tile, drag it to another group, into Hidden and over a
      tab title; a quick swipe must still scroll. Tested here with Chromium and simulated touch only.

## 0.8.264 tool cookies – please verify
- [ ] phpMyAdmin / Tiny File Manager open from the start page after the update (cookie now only for their folder, SameSite=Strict;
      the old cookies with path / are removed on the next launch). With $nuConfigPMAURL / $nuConfigTFMURL: path of that URL.
- [ ] Hardened Tiny File Manager config.php (tfm-config-hardened.zip, NUNEXT-VERSION 2.6+git.4149143.nu2) deployed on the server.
- [ ] Hardened phpMyAdmin config.inc.php (pma-config-hardened.zip, NUNEXT-VERSION 5.2.3+nu2) deployed in third_party/nudb.

## Bootstrap 6 (alpha in October 2026) – later
- [ ] Stay on Bootstrap 5.3.8; look again at the beta. Since 0.8.244 all of nuBuilder Next's JavaScript uses Bootstrap only through
      `assets/js/nubs.js` (`nuBs.*`) – a switch changes that file (Bootstrap 6 has no `window.bootstrap`: set it there for form code),
      plus CSS / markup changes (variables, removed classes) to check page by page.

## Not supported yet
- [ ] phpMyAdmin / Tiny File Manager on MSSQL (nuBuilder doesn't support it either).
- [ ] JavaScript functions that are only stubs (console warning): `nuPrintExcludeColumns`, `nuResizeBrowseColumns`, `nuSetBrowseColumnWidth`,
      `nuOpenWiki`, `nuSetSnippetFormFilter`, `nuSelect2`, `nuSetSelect2`, `nuEnableMultiSelect`, `nuShowFile`, `nuSetDateFormat`,
      `nuCreateDialog`, `nuSetFormValue`, `nuAccessFormSetButtonIcons`, `nuBrowseStyleStatusColumn`, `nuSetTabHelpButtons`, `nuDragElement`,
      `nuSetSideBarStyle`, `nuShowVersionInfo`, `nuHasHiddenModalDragDialog`. Use Admin tools → Compatibility check to see which ones your forms call.
- [ ] `nuSetValidationErrorTitle()` does nothing.
- [ ] Shared login only nuBuilder → nuBuilder Next; nuBuilder Next → nuBuilder would need a change in nuBuilder.

## Accessibility
- [ ] Define what "Accessibility features" (`sus_accessibility_features`) should do in nuBuilder Next. The switch on the user form is hidden
      (`NU_FEATURE_ACCESSIBILITY` in `core/nuapp.php`); saving a user keeps the stored value.

## Documentation
- [ ] `docs/nunext-docs.htm` is English only. Extend it with every new feature (chapters are filtered per user: `data-doc="admin"`,
      `data-acl="<form id>"`).

## Ideas – what could come next (rated 0.8.292)
Rating 1–10: **10 = must have** in a modern business-app framework · 7–8 important · 5–6 useful · 1–4 nice to have.
Checked against what nuBuilder Next already has (October 2026) – only real gaps. Effort: S = days, M = 1–2 weeks, L = more.

### Must have (9–10)
- [ ] **10 · Test on a real SQL Server** (L) – App cloner, backups, installer and forms on MSSQL were written without one (see 0.8.198).
      A feature that is claimed but never ran is a risk; a Docker SQL Server in CI would settle it (see Docker below).
- [ ] **9 · Protection against concurrent edits** (M) – two users open the same record, the second save silently overwrites the first.
      Optimistic locking: remember a version / hash when the record is opened, check it on save, show "changed by Anna at 10:42 – reload or
      overwrite". Standard in every modern framework; nuBuilder has none.
- [ ] **9 · Scheduled automatic backups** (S–M) – today only "Back up now" and the backup before an update. Daily / weekly by cron.php
      with the database, a keep rule, optional copy to another folder / server (SFTP, S3) and a warning on the start page when the last
      backup is too old.
- [ ] **9 · Deploy changes from a test to the live system** (L) – export forms, procedures, reports, access levels, settings as one
      versioned package (JSON), show a diff on the target and apply selected parts. The App cloner copies everything, the form history
      works per form – neither covers "develop on test, publish to live".
- [ ] **9 · Change history of data records** (M) – who changed which field from what to what, per record, viewable in the form
      (⋮ menu). Audit columns only hold the last change; the log covers admin changes, not the application's data. Often a legal need.

### Important (7–8)
- [ ] **8 · Single sign-on with OpenID Connect** (M) – Microsoft Entra ID / Google / Keycloak login for companies; access level by group.
      (The "OAuth" in the code is the REST API's client credentials, not a user login.)
- [ ] **8 · Passkeys (WebAuthn)** (M) – as a second factor besides the authenticator app, later passwordless.
- [ ] **8 · OpenAPI description of the REST API** (S–M) – generated from forms and rights, Swagger-style view in the API playground;
      lets other systems generate clients.
- [ ] **8 · Accessibility audit (WCAG 2.2 AA)** (M) – keyboard use of forms and lists, screen reader labels, contrast in both themes;
      required for public-sector customers (see also "Accessibility" above).
- [ ] **7 · Record-level rights** (M) – rules per access level like "only own department" without writing them into every browse SQL
      (today: hash variables in each form's SQL).
- [ ] **7 · Excel (.xlsx) export and import** (S) – lists, subforms and reports to xlsx; CSV exists, but users expect Excel.
- [ ] **7 · Scheduled procedures** (S–M) – run a procedure hourly / daily / monthly through cron.php, with log and e-mail on error
      (reminders, data cleanup, interfaces).
- [ ] **7 · Password policy** (S) – besides the minimum length: check against known leaked passwords (k-anonymity, no password leaves
      the server in full), optional complexity / expiry per access level.
- [ ] **7 · Webhooks** (S–M) – call a URL after save / delete of chosen forms (n8n, Zapier, Teams, other systems), with retry and log.
- [ ] **7 · Global search / command palette** (M) – Ctrl+K: find forms, records (chosen fields), settings and pages from anywhere.

### Useful (5–6)
- [ ] **6 · Workflow / approvals** (L) – status field + allowed transitions + who may do them + notification, defined without code.
- [ ] **6 · In-app notifications** (M) – bell in the top bar (assigned record, approval waiting, job failed), optional e-mail digest.
- [ ] **6 · Recycle bin for records** (M) – deleted records restorable for n days (soft delete per form, opt-in).
- [x] **6 · Dashboard builder** (M) – done in 0.8.298 (start page per access level and HTML objects of any form).
- [ ] **6 · Docker image / docker-compose** (S) – quick install and demo; makes CI with MySQL 8, MariaDB and SQL Server possible and
      would close several "please verify" points above.
- [ ] **6 · Health / status endpoint** (S) – JSON for monitoring tools: database reachable, disk space, age of last backup and cron run.
- [ ] **5–6 · The 18 settings marked "Not yet"** on the Settings page – each small (S): sticky list headers, close after save, user form
      labels, keep session alive, browser tab title, calendar options … Do the ones users ask for.
- [x] **5 · Branding** (S) – done in 0.8.297 (logo, accent colour, environment label).
- [ ] **5 · Secrets encrypted at rest** (S) – SMTP password, API secrets and similar values in the database encrypted with a key from
      nuconfig.php.
- [ ] **5 · E-mail queue** (M) – send in the background with retry; failed mails visible and resendable (today sent while saving).
- [ ] **5 · GDPR helpers** (M) – export / erase all data of one person across tables; retention rules per table.
- [ ] **5 · Smarter code editor** (M) – autocomplete of tables, columns, hash variables and nuBuilder functions in SQL / PHP / JS.

### Nice to have (1–4)
- [ ] **4 · Installable app (PWA)** – icon on the phone, faster start; offline only for reading.
- [ ] **4 · "Who is editing" presence** – show that someone else has the record open (pairs with concurrent-edit protection).
- [ ] **4 · AI help in the form builder** – create a form / table from a description (goes beyond the prompt generator).
- [ ] **3 · File storage on S3 / WebDAV** instead of the server folder / database.
- [ ] **3 · Multi-tenancy** – several customers separated in one installation.
- [ ] **2 · GraphQL API** besides REST.
- [ ] **2 · Plugin system** for third-party extensions.

**Suggested order:** concurrent-edit protection → scheduled backups → record change history → Docker + SQL Server / MySQL 8 tests →
deploy test → live → SSO / passkeys → xlsx, scheduled procedures, webhooks.
