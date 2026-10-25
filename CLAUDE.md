# nuBuilder Next – notes for development (also for a new Claude session)

nuBuilder Next ("nuNext") is a modern PHP front end for **nuBuilder 4**. It runs on nuBuilder's own `zzzzsys_*` tables, side by side
with a live nuBuilder on the same database, and adds only its own tables (`zzzzsys_nunext_*`). One owner/developer (not named here – see the rule below).
Short user-facing description and install: `README.md` (alpha warning on top, quick links, overview and features, install – the GitHub front page, keep it concise);
the full **changelog**: `CHANGELOG.md` (0.8.373; newest first, "Earlier versions (0.2–0.7)" at the end). User documentation (shown in the app, `?p=help`):
one file per page in `docs/pages/`, listed in reading order in `docs/toc.json` (0.8.493; tabs Guide / Code reference). Open points to verify: `TODO.md`. Differences to nuBuilder / not ported: `docs/pages/12-02-porting.htm`.

## How work is delivered
- Every request ends as a **new version**: bump, changelog, manifest, zips sent to the owner, who installs `nunext.zip` with Admin
  tools → *Update*. Version scheme 0.8.x, one step per delivery (0.8.201 → 0.8.202).
- Use **`python3 dev/release.py <version> "<title>" <changes.txt>`** – checks (php -l of all files, check_lang, `dev/tests/run.php`,
  PHPStan level 2 if `../phpstan.phar` exists, ESLint if `../node_modules/eslint` exists – see "Test environment"), README title, CHANGELOG.md
  section, `NU_VERSION`, the manifest (hashes of every **deployed** file + the `changes` entry), the Ed25519 signature and
  two zips: **`../nunext.zip`** (install package: without CLAUDE.md, TODO.md, .gitignore, dev/, .github/ – these are readable on a web
  server) and **`../nunext-dev.zip`** (everything). Send both; the owner sends `nunext-dev.zip` to the next session.
- **Signing key**: `../nunext-signing.key` (outside the project, never in a zip; also `$NUNEXT_SIGNING_KEY` or `~/.nunext-signing.key`).
  The owner keeps it and uploads it with the dev zip – put it next to the project folder. Without it the release is unsigned (the
  update page warns). Public key: `NuUpdate::SIGN_PUBLIC`. Several requests in one turn can share one version.
- Before releasing also: a test in the browser (see "Test environment"), and after it **`php dev/inspect.php <test installation>
  ../nunext.zip`** (= `NuUpdate::inspect`): `ok=true`, the new version, `signature=valid`, `errors=[]`.
- **Fast releases, gate on request (0.8.389, the owner's rule):** a normal request ends with a FAST release – `dev/release.py` WITHOUT
  `--gate` (php -l, check_lang, unit tests, PHPStan, ESLint; browser suites skipped; manifest `"gated": false` → the update page shows
  "Not fully tested") plus the e2e tests of the changed areas (`ONLY=…`) and a browser look; both zips are sent; **no git push**.
  Only when the owner says **"gate"**: a new version with `python3 dev/release.py <version> "<title>" <file> --gate` (full gate: smoke,
  all e2e, compat; manifest `"gated": true`, `last_gate`), then sync to the git mirror, commit and `git push origin main` (everything since
  the last gate in one push). **Account without a GitHub connection (since 0.8.451, the owner's rule):** a gate runs the full test
  suite locally and releases with `--gate`, but nothing is synced, committed or pushed; do **not** remind the owner about gates
  (release.py only prints the count).
- **GitHub release** (the owner creates it by hand after a gate): when asked for the tag / release notes, follow
  **`dev/release-notes.md`** – release settings (tag `v<version>`, title, only `nunext.zip`, pre-release), the structure of the notes
  (link to CHANGELOG.md, alpha note, Highlights, Security, After updating) and an example; it records the last published release –
  update that line once the owner has published.

### Checklist for every request (the usual flow)
1. Starting a session: unpack `nunext-dev.zip` (folder `nunext/`), put `nunext-signing.key` next to it (`../nunext-signing.key`),
   rebuild the test environment (below). Read `TODO.md` and the newest `CHANGELOG.md` sections.
2. Short request → build it; "suggest …" → numbered options with a recommendation and wait; "do recommendations" / "ok all" → build.
3. New texts: write a JSON file and run `python3 dev/lang_add.py texts.json` (all eleven languages in one go, after an anchor key),
   then `php dev/lang_variants.php` and `php dev/check_lang.php`. Never edit `lang/en-US.php` / `lang/de-CH.php`.
4. Sync to the test site, test in the browser (Playwright), look at screenshots; run `dev/tests/smoke.js` and the end-to-end suite
   `BASE=http://127.0.0.1:8099/nunext/ DB="-unu -pnu nutest" PKG=../nunext.zip NODE_PATH=$(npm root -g) node dev/tests/e2e.js`
   (113 tests in 0.8.484: forms, sub-forms, nuBuilder hooks, lookups, permission items, rights, admin roles, demo mode (needs SITE=<site folder>),
   login links, user import, audit, notifications, list views, favourites, branding, dashboards, login limit, CSV import, update check, self-test, CSRF …;
   it saves the whole database first and puts it back – `ONLY=name1,name2` runs single tests). Add a test for every new feature.
   The test site runs with `$nuConfigDevMode = true`, so the in-app self-test (`?p=selftest`, core/nuselftest.php) runs too.
   **Cross-tests against nuBuilder itself** (0.8.364): `NUB=http://127.0.0.1:8099/ BASE=…/nunext/ DB=… node dev/tests/compat.js` – real
   nuBuilder 4.9 and nuBuilder Next on the same database: browse / edit / save-without-change for EVERY application form (so it also
   runs against a copy of a real nuBuilder database), typed input → same row, sub-form rows across both, reports, and the metadata
   editors (user, access level, procedure, SQL builder, report designer, permission item, e-mail template, form builder): a save
   without a change must leave nuBuilder's rows as they were. Accepted differences: `dev/tests/compat-known.json` (each with a reason).
   **Release gate** (`--gate`, only when the owner says "gate"): with `NUNEXT_TEST_SITE=<site folder> NUNEXT_TEST_BASE=<url> NUNEXT_TEST_NUB=<nuBuilder url>`
   set, `dev/release.py --gate` syncs the code and runs smoke, e2e and compat – a failing test stops the release. Set them in every session.
   **Large data** (0.8.366): `SITE=<site> node dev/tests/load.js` fills 500 users, 100,000 customers / orders (300,000 items), 100,000 audit
   rows, 1,000 notifications, 200,000 log rows, times ~25 pages + the bell (budgets 1500 ms / 150 ms) and prints MariaDB's slow / no-index
   queries; puts the database back. ~3 minutes – start it in the background (`setsid nohup … > log &`; the shell call stops after 10
   minutes and a stopped run leaves the data: restore the dump it names, kill a still running query first). In release.py: NUNEXT_TEST_LOAD=1.
   Result 0.8.366 (MariaDB 10.11, PHP built-in server): every page ≤ 330 ms, bell 8 ms. Sub-form link columns need an index
   (`NuDDL::ensureIndex`, set by the form builder and the sample application).
   **Accessibility** (0.8.368): `node dev/tests/a11y.js` – axe-core (WCAG 2.1 A/AA) on ~40 pages in light, dark (theme set like a user:
   localStorage nunext-theme before the page) and phone width (390 px: nothing wider than the screen); critical / serious fail.
   axe-core: `npm install --no-save --prefix .. axe-core@4.10.2`. In release.py: NUNEXT_TEST_A11Y=1. Rules that keep it green:
   colours in the "accessibility" blocks at the end of nuapp.css (4.5:1), assets/js/nuapp.js names unnamed fields (label, sub-form
   column title, lookup) and makes scrolling boxes focusable – new pages need nothing extra; icons with aria-label need role="img".
   **PHPStan level 3** (0.8.368); 4 / 5 tried – only false alarms (see dev/phpstan.neon).
5. Put the test database back as it was (see "Test database discipline").
6. Docs (the matching page in `docs/pages/`, see `docs/toc.json`), tutorial, `TODO.md`, this file when the architecture changes.
7. Changelog text in a scratch file ("- " bullets, continuation lines indented by two spaces) → `python3 dev/release.py 0.8.x "Title" file`.
8. `php dev/inspect.php <site> ../nunext.zip`, then send **both** `../nunext.zip` and `../nunext-dev.zip`; answer with a short
   summary of what changed (no step-by-step recap).

### A new feature usually touches
own tables → `NuSchema::TABLES` in core/nuinfra.php (bump `NuSchema::VERSION`; missing columns are added automatically) · a database
step → `NuMigrate::steps()` + `NuMigrate::CHANGES` in core/nuupdate.php · a class-only file → `NU_AUTOLOAD` in core/nuapp.php · a page →
`$routes` in index.php + pages/nu<route>.php (JSON routes `['json' => true]`) · read-only POST actions for demo visitors →
`NuDemo::READ_POSTS` · an admin right → `NuAdmin::RIGHTS` · a start page tile → `NuHome::catalog()`, `TILE_RIGHTS`, `PAGES`, `PAGE_TILE`
(and `subTiles()` / `actions()` for ▾ menus) · a system list → `NuListDefs` (+ `LISTS`) · a pseudo form for access levels → `NuAcl::FORM_*`
· clean-up → a step in `NuHousekeeping::run()` · PHP API for form code → core/form/nuphp_api.php · REST → `NuApi` routes +
`NuApiClient::SCOPES` · log events → `ev_*` texts · JS in `assets/js/<page>.js` (strict CSP) · a PHP function for form code (core/form/nuphp_*.php) or a JS `api('…')` →
an entry in `docs/nunext-functions.json` (Help → function references, core/nufnref.php; dev/tests/run.php fails otherwise) · a library
pack → `NuLibs::SLOTS` + `BY_NAME` + `detectVersion()` + `selfTest()`, `LIB_SLOTS` in dev/release.py, a row in `NuSecurity::libraries()`, the
use map in `NuLibraries::all()`, text `upd_libuse_*`, the slot list in dev/libpack.php and the table in the help (#copy-files). Each entry needs
an example `e`, parameter descriptions `p` {name: text} and – when it returns a value – a sample result `x` (0.8.456: PHP / JavaScript tabs,
wiki-style cards with signature, parameters, returns, example, result / console.log(), "Also see"; NuFnRef::tabs() / entry(), nuhelp.js fnTab / reveal).

## The owner's standing rules
- **No personal details of the developer** (name, e-mail, etc.) in any file of the project – code, docs, README, CLAUDE.md,
  changelog, manifest, comments. Refer to "the owner" / "the developer".
- **Always update the changelog** (CHANGELOG.md section `## 0.8.x – title` + manifest `changes`, both via `dev/release.py`) and **bump the
  version**, unless the owner says otherwise.
- **All texts in all eleven languages** (`lang/en, de, fr, it, es, pt, nl, pl, ru, zh, ar.php`; Arabic is right to left). No hard-coded
  UI text. New keys go next to related keys in every file; `dev/check_lang.php` must report "all languages complete".
  **English is British** (`lang/en.php` = en-GB: colour, customise, cancelled); `lang/en-US.php` is GENERATED from it by
  `dev/lang_variants.php` (word list, run by release.py – never edit it), `lang/en-AU.php` is a hand-kept overlay (usually empty).
  Variants: `NU_LANG_VARIANTS`, `nu_lang()` stays 'en' / 'de', `nu_lang_variant()` / `nu_lang_choice()` = en-GB / en-AU / en-US,
  de-DE / de-CH (0.8.314 / 0.8.315).
- **Update the docs** (English only; the matching page in `docs/pages/` – a new page = a new file + an entry in `docs/toc.json`; mark advanced topics
  with `data-level="advanced"`; **no version numbers in the text** – history is CHANGELOG.md; page template and rules in the comment of toc.json), the **tutorial** (`docs/nunext-tutorial.htm`, 0.8.328 – lessons for the
  globeadmin, structure in its header comment; lesson ids are progress keys, never rename them) and **`TODO.md`** when relevant; remove TODO points that are done or
  outdated. The README stays short (what it is, requirements, install, updating, compatibility,
  licence) – new features are described in the CHANGELOG.md section of their version and in the docs.
- **Upgrade baseline 0.8.321** (not released yet; both servers were on 0.8.321): no upgrade paths from earlier nuBuilder Next versions
  (old table names, old stored formats, old cookies, obsolete-file list – removed in 0.8.322). New migration steps and conversions are
  written for versions from the baseline on; the owner says when to move the baseline and drop the paths below it. nuBuilder
  compatibility (old nuBuilder databases, md5 passwords, missing nuBuilder tables) is NOT upgrade code and stays.
- **Do not add new variables to `nuconfig-sample.php`** unless asked.
- **Existing nuBuilder databases are blocked for now** (0.8.380, `NuInstall::ORIGIN`): the installer refuses a database with nuBuilder's
  tables; an installation made by hand on one (setting `db_origin` = 'nubuilder', step 0014) shows only a notice; installations from
  before 0.8.380 ('legacy') keep working with a warning for the globeadmin. **`$nuConfigAllowExistingNuDB = true;`** in nuconfig.php
  allows it – a hidden switch for the developer: never in README, nuconfig-sample.php or the help. The test site runs without it
  (its database is 'legacy' – the start page shows the warning); e2e test `existing_db`.
- **nuBuilder compatibility first**: same tables, same JSON formats (reports, SQL builder, form layouts), same PHP/JS function names
  and results as nuBuilder 4.9 – a form or report must keep working in nuBuilder. `dev/tests/compat.js` checks it against nuBuilder
  itself (0.8.364). Rules found that way: nuBuilder writes only EDITED fields (a new record's untouched empty field stays NULL –
  nuruntime.js `untouchedNew`; NuDB::update skips unchanged columns of nuBuilder's zzzzsys_ tables, NULL = ''); posted line ends are
  LF (index.php); child rows keep their ids (SQL builder clauses); JSON keeps the stored value types (report layout: `NuReport::keepTypes`,
  borderWidth / fontSize as text); negative numbers `€ -99,95` (minus after the prefix).
- Explanatory texts under fields are usually not wanted any more: show them as **tooltip of an (i) icon** next to the label
  (pattern below). The owner asks for this field by field.
- Requests are short; ask only when a wrong guess is expensive. When the plan has several parts the answer is often "ok all".
- **No effort or time estimates** in suggestions or answers (no "one to two weeks", "S / M / L", "a day of work") – the work is done by AI
  and goes much faster; describe scope and steps instead.
- On "suggest how …": give concrete proposals with numbered options (A/B/C … with 1/2/3) and a recommendation; implement the one picked
  ("do recommendations" = the recommended ones). Answers to the owner are short: what changed, where, what to verify.
- Every delivery: both zips are sent with the file tool, also when only docs changed.
- **German** (0.8.315): `lang/de.php` is German German (de-DE: ß – außerhalb, Größe – and „quotes“); `lang/de-CH.php` (Swiss: ss, «»)
  is GENERATED from it by `dev/lang_variants.php` – never edit it. Neutral wording, no "Sie".

## Architecture
```
index.php            router: ?p=<route> → pages/nu<route>.php ($routes list with json/public flags), CSRF check on every POST,
                     login guard, maintenance mode, demo-mode guard. nuBuilder auto login ?u=… → pages/nuautologin.php
api.php              REST API entry (api.php/v1/…) → core/nuapi.php
core/nuapp.php       NU_VERSION, NU_AUTOLOAD (feature classes loaded on first use – add new class-only files there), requires the
                     rest, config (nu_config / nu_cfg), security headers + CSP, strict sessions (cookie path = folder), helpers
core/nudb.php        NuDB: PDO wrapper for mysql and sqlsrv (sqlite only for tests): run/all/one/value/insert/update/delete/page,
                     tableExists, ident(); every query passes NuDemo::check()
core/nuauth.php      login, sessions (cookie nunext), password hashing (bcrypt, md5 upgrade; md5 storage while $nuUseMd5PasswordHash is on,
                     NuAuth::md5Storage), NuAuth::user(), loginToken() (one-time login links), demo globeadmin sessions (via 'demo_ga')
core/nuacl.php       rights from zzzzsys_access_form; constants FORM_* = nuBuilder's system form ids (nuuser, nuaccess, nuform …)
core/nuinfra.php     own tables (NuSchema::ensure/create; 0.8.462 gap analysis B2: ensure() changes the database only when mayChange() –
                     CLI, NuSchema::allow() (installer, the globeadmin's sign-in in NuAuth::start), or present() + a signed-in session and
                     not NuInstall::existingBlocked() (also true before the set-up on a database with nuBuilder's tables); NuAuth::startupHook
                     refuses other users with login_not_ready while !present(); e2e gap_schema), NuLog::write(event, object, recordId, details), tokens, sessions,
                     nu_client_ip() (last X-Forwarded-For entry), nu_https() (also behind a TLS proxy), nu_base_url()
core/nusecurity.php  NuSecurity (0.8.207): security settings (JSON row 'security'), login rate limit (counted in the log),
                     admin-equivalent rights (ADMIN_FORMS / restricted()), password confirmation (requireRecentAuth), protectDir,
                     secret masking (secretName, demoMask – also sus_json secrets), library versions, checks() for ?p=securitycheck,
                     mailBaseUrl(), reauthMinutes() (longer in developer mode: setting reauth_minutes_dev), setting login_token
core/nuhousekeeping.php  purges (tokens, sessions, API rows, log retention, temp files, API report PDFs) and the demo data reset –
                     cron.php (CLI) or now and then a page view
core/nudemo.php      demo mode: users ($nuConfigDemoUser) and nuBuilder's demo globeadmin ($nuConfigIsDemo, gaDemo()), request / JSON / database
                     guards, HIDDEN_TABLES + touchesHidden() for typed SQL, save limit (SAVES_PER_HOUR 10 per IP, 100 overall)
core/nudemodata.php  demo data snapshot (backup folder, demo-snapshot.json) and reset of the allowed forms' tables (setting 'demo_reset')
core/nuaudit.php     audit trail (zzzzsys_nunext_audit, one row per changed column, change_id per save): begin()/record()/end() around
                     NuRecord::save/delete (sub-form rows too, form_id = the form the change was made in) and NuImport::run; settings row
                     'audit' {on, keep_days, exclude} (on defaults to $nuConfigLogRecordChanges, replaces the log's record_* events);
                     history(), restore() (value only while unchanged since, deleted row only while the key is free), purge() (housekeeping);
                     pages ?p=audit (NuListDefs 'audit', pseudo form nunextaudit, admin right 'audit') and ?p=audithistory&f=&r=
core/nunotify.php    notification centre (0.8.357): zzzzsys_nunext_notification (+ _user: recipient / read / hidden / confirmed per user), audience JSON
                     {all, users, departments, teams, positions, access}, mode live (matched when read) / fixed (stored at publishing, always for
                     must_confirm); senders: globeadmin, admin right 'notifications' = change, permission items NUNEXT_NOTIFY / NUNEXT_NOTIFY_OWN (own
                     department / team); the bell = nu_bell() in core/nulayout.php + assets/js/nunotify.js (polls ?p=notifyapi every 60 s; GET does not
                     refresh the idle time – NU_PASSIVE), pages/nunotifications.php (mine / manage / edit / receipts), nuNextNotify(), API /v1/notifications
core/nuselftest.php  self-test (?p=selftest, developer mode only): integration checks in groups system / data / forms / rights / features;
                     form checks write into the sample application and clean up (also audit rows); new checks = entries in checks()
core/nulogintoken.php nuBuilder's single-use LOGIN_TOKEN in sus_json (+ LOGIN_TOKEN_EXPIRES): create, link, rotate (compare and swap), clear
core/nulistview.php  list views of the system lists (NuListView: prepare/card/page, views, editor, defaults); core/nulistdefs.php the lists
                     (columns, filters, card layout, SQL) – a new system list is a NuListDefs entry, not hand-written HTML
core/nuhome.php      start page: tiles, Ctrl+K search, saved layouts (list_key 'home' in zzzzsys_nunext_list_view); view (0.8.420, View panel):
                     layout key view {style, desc, nav, density, icon, … tabStyle, sizes.tablet / sizes.phone} – cleanView / legacyView (layouts
                     without view: size + display) / compat (size + display derived on every save); personal views per layout (overlays /
                     setOverlay: sus_json NUNEXT_HOME_VIEW, setting home_view_ga, session for demo) – POST view_me / view_me_clear (everyone),
                     view_layout (editable layout only); one tile markup for all styles (pages/nuhome.php), classes + container queries in
                     assets/css/nuhome.css, precedence / panel / tooltips in assets/js/nuhome.js; e2e home_view
                     user start pages (0.8.425): users without an admin role get the tile page too – NuHome::forLevel(access, preview)
                     switches the class: list key home_user (access_ids [access id] or ["*"] = default; userLayouts(): own > default >
                     autoLayout() = forms + reports of the level), own tiles only (userOnly(); kinds form, report, procedure, url, page in
                     USER_PAGES), cond {permission, team, department, position} on tiles and groups (cleanCond / condOk – not applied in the
                     editor preview), user overlay fav / hide (setMine, POST mine / mine_clear, Personalise in nuhome.js); editor
                     ?p=home&for=<id|*> with the right homelayout (link on the access level form, sub tile s_user_home), resetLevel();
                     procedure tiles run through ?p=home&run=<id> (NuPhp rights); hook nu_home_user (NuHomeHook::useUserPage,
                     #HOME_PAGE# / #HOME_ACCESS#); "My forms and rights" only while acting as the user; e2e home_user
core/nuhomehook.php  start page hooks (0.8.378): procedure nu_home runs while the tile page is built (pages/nuhome.php: NuHomeHook::run +
                     apply); PHP functions nuHomeSetBadge / SetHint / HideTile / AddGroup / AddTile (x_ keys, NuHome::cleanTile) / Banner /
                     SetData / Cache in core/form/nuphp_api.php; errors → page without changes + alert for the globeadmin + Debug results;
                     off: $nuConfigHomeHooks = false (settings row, step 0013), ?safe=1 (globeadmin); JavaScript (0.8.379): the procedure's sph_javascript served by
                     ?p=homejs&v=<hash> (pages/nuhomejs.php, NuHomeHook::serveJs) after nuhome.js, which exposes window.nuHome (on / off / tiles /
                     tile / data; tile objects from the data-nu-* attributes of the tile markup)
core/nuincludes.php  NuIncludes (0.8.443/0.8.444): $nuConfigIncludeJS / CSS / PHP (nuBuilder Next's folder, then nuBuilder's), Setup → Include
                     elements (setupElements), CSP sources for form pages, missing() for the Security check; page ?p=setupcode
                     (pages/nusetupcode.php, admin right setupcode) edits set_header / set_style / set_include. Tabs that a script opens
                     after loading: data-nu-tab-restore (hidden by nutheme.js until DOMContentLoaded, 0.8.444)
core/numailoauth.php NuMailOAuth (0.8.445): SMTP login with OAuth 2 (auth type XOAUTH2) – Google / Microsoft 365 / other; settings row mail_oauth
                     (secrets sealed with $nuConfigSecretKey, else "plain:"), consent via pages/numailoauth.php (?p=mailoauth&go=1 → meta refresh,
                     because form-action 'self' blocks a POST redirect to another site; back at oauth.php = p=mailoauth), token refresh in
                     accessToken(), PHPMailer token provider core/numailoauthtoken.php; NuMailer::send / check use it; e2e mail_oauth with
                     dev/tests/fakeoauth.py + fakesmtp.py (XOAUTH2)
core/nuuserfields.php NuUserFields (0.8.454): custom user fields – definitions zzzzsys_nunext_user_field (step 0019), values
                     zzzzsys_nunext_user_ext (one row per user, one REAL column per field, made with NuDDL in the collation of zzzzsys_user –
                     joinable USING (zzzzsys_user_id)); page ?p=userfields (admin right userfields, CSV of a field before deleting), user form
                     tab Additional fields (type in brackets), ?p=account (My account: edit_by 'user'), users list columns / filters (NuListDefs, in_list), CSV import,
                     #USER_<CODE># (NuHash), nuNextUserField / nuNextSetUserField (PHP), nuNextUserField (JS, N.user.fields), REST "fields",
                     start page cond.fields; e2e user_fields
core/nuupload.php    NuUpload (0.8.459): uploads of nuBuilder's file objects with target "File System" (sob_input_file_target 1) with Uppy –
                     NuRender::isUppy / uppyField (div + the object's HTML with nuBuilder's replacements #uppy_div# …, no data-nu-field: never
                     saved / validated / column proposal), Uppy = library pack slot 'uppy' (NuLibs::uppy(), uppyVersion() = core version;
                     pages/nuform.php loads it where a form uses it, notice form_uppy_missing), nucompat.js nuUppyCreate (endpoint
                     core/nuapi.php → index.php?p=upload + X-CSRF-Token, meta form_id, Dashboard theme = dark mode) / nuUppySetLanguage
                     (<script> for locales/<code>.min.js), route pages/nuupload.php (json; CSRF error as {error, message}; NuSecurity::
                     dangerousUploadName refuses .php … before the procedure; NuPhp::procedure with the form's hash; answer = NuPhp::$result
                     – the procedure's $result, as nuEvalSafe – and its HTTP status; log file_upload / file_upload_refused); templates HTML
                     (nuBuilder's snippet, unchanged) and PROCEDURE (hardened nu_upload_file_template) with nuBuilder's row ids, step
                     0020-upload-template (ensureTemplates, when missing); builder: File target select + HTML, htmlTemplate() on switch; e2e uppy_upload
core/nusidebar.php   NuSidebar (0.8.460): optional left side bar – favourites (= the start page's ☆ tiles, NuHome; favToggle) and recently used
                     (zzzzsys_nunext_recent, step 0021, KEEP 10, 8 shown; pages of PAGE_KEYS + form:<id>, recorded by nu_layout_start also while the side bar is off,
                     start page tiles through POST action used = sendBeacon → recordTile, 0.8.492; a drawer on every width since 0.8.513, no icon rail; the start page's row Recently used = the same list, drawn by
                     pages/nuhome.php); on / off per
                     user (sus_json NUNEXT_SIDEBAR, globeadmin: setting sidebar_ga, demo: session), default sidebar_default (globeadmin, user menu);
                     drawn by nu_sidebar() in core/nulayout.php (.nuShell wrapper), POST route ?p=sidebar (toggle / default / clear / fav, demo READ_POSTS),
                     assets/js/nusidebar.js (drawer, button #nuSbOpen left of the logo); e2e sidebar
core/nucustomcode.php NuCustomCode (0.8.523): Admin tools → Custom code (pages/nusetupcode.php, route setupcode, right setupcode) – Form pages =
                     nuBuilder's Setup Header / Style / Include (form-only, unchanged), All pages = Global JS & CSS in settings rows custom_js /
                     custom_css / custom_scope {forms, app, login}; served as files ?p=customcode&t=js|css&v=<hash> (public route; without a session
                     only while scope login is on); CSS in nu_head before the page's styles, JS after nuapp.js (form pages: in pages/nuform.php
                     before Setup → Include / Header, so a form's nuOnLoad wins); never on setupcode / update, ?safe=1 (globeadmin / right
                     setupcode); $context app / login (nu_center_start, login page) / none (error pages). Page hook: <body data-nu-page
                     data-nu-mode data-nu-form data-nu-admin> (bodyAttrs) + nuOnPageLoad(page, info) / event nu:pageload (assets/js/nuapp.js;
                     form pages: nuruntime.js after nuOnLoad); hookClashes() → editor warning + compatibility check G9; e2e custom_code
core/nufilemanager.php NuFileManager (0.8.479, handover nuFiles): File Manager = the page form nufiles (built-in page apps/nufiles/,
                     NuPage::BUILTIN – never ship files under custom/ except the shippedCustom list: older versions refuse the package, 0.8.481;
                     data in custom/pages/nufiles/_data via \$nu->dataDir()), route ?p=filemanager (pages/nufilemanager.php: redirect to the page, or setup()
                     for the globeadmin – page form + default settings: shares uploads (= upload template folder) + webroot (code), reauth
                     code); entries: tile 'tfm', user menu, form ⋮, nuVendorLogin('TFM'); admin right filemanager; Tiny File Manager removed
                     (step 0023-retire-tfm: NuLibs::retire + third_party/tinyfilemanager of nuBuilder Next). Page API added: $nu->vendor /
                     recentAuth / reauthUrl / dataDir / dangerousName. e2e file_manager
core/nufieldaccess.php NuFieldAccess (0.8.480, handover spec 04): field data access – modes edit / ro / hide / prot / ins / upd / mask / wh per
                     object, default + rules per subject in zzzzsys_nunext_object_access (step 0024); NuSubject (core/nusubject.php) = who a
                     rule applies to (user > perm > team/dept > access > default; best()); resolve() / mode() / readonly() / writable() /
                     shown() / scrubHash(); used by NuRecord::hiddenFor + save (writable, clone_from takes locked values from the DB),
                     NuRender (withheld = nothing, masked = locked text, pageHash for HTML objects), formapi lookup / refresh, breadcrumb,
                     NuAudit history / restore, NuConflict signatures; globeadmin bypass unless Security setting field_access_ga.
                     NuBrowseAccess (core/nubrowseaccess.php, spec 07): column state show / hide / mask:<pattern> from sbr_json
                     nunext.access {default, rules} + the field's mode; NuBrowse::query keeps $cols keyed by browse index, hidden columns never
                     selected / searched / filtered / sorted, masked not searched / filtered / sorted. Page ?p=dataaccess&f= (pages/nudataaccess.php
                     + assets/js/nudataaccess.js: Fields, List columns, By access level, Preview as user; button in the builder);
                     PHP nuFieldAccessMode / nuFieldAccessAllowed; e2e field_access
core/nuprocedure.php NuProcedure::hook(code, hash, vars) (0.8.462, gap analysis B5): nuBuilder's global hook procedures by sph_code –
                     nu_before_save (pages/nuform.php before BB, NuRecord::save before BS, formexport), nu_before_edit, nu_before_delete,
                     nu_startup / nu_invalid_login (NuAuth), nu_check_password_policy (NuAuth::passwordPolicy: user form, own password, reset,
                     REST), nu_after_update (NuUpdate::afterUpdateHook), nu_debug_result_added (NuDebugLog); variables through NuPhp::run
                     $vars (extract) and NuPhp::$vars / $error / $result after the run. Other gap fixes: NuAcl data mode / form type (B3,
                     0.8.461), NuDB::readOnly savepoints (B4), sub-form calc prefix (NuRender calc: "al('" + row prefix) and no edit mark
                     of the blank row (nuCalculateForm, B6), onnuload / onenter (nuruntime.js, B7), nuFORMdata + nuGet/SetNuDataValue
                     (NuRecord::nuFormData / applyFormData), #USER_NAME# = sus_name, nuLookupRecord()->ID, blank SUM of an empty (NULL)
                     report group (NuReportEngine::fieldValue), Compatibility check G1–G8 (NuCompat). Test application of the analysis:
                     dev/tests/gapkit/ (testapp.sql, t08_fixture.sql; e2e ttInstall / ttRemove, tests gap_*; compat.js loads it, GAPKIT=0 not)
core/nucodetemplates.php NuCodeTemplates (0.8.524): built-in templates of the Custom code page – all() = tab → [id, cat, code], CATS
                     (look / page / form / fn), texts ctpl_<id> + ctpl_<id>_d; ▾ Templates per tab + preview dialogue #scodeTplDlg (nusetupcode.js);
                     a new template = an entry + its two texts (run.php checks); e2e custom_code_templates
core/nuhelplink.php  NuHelpLink (0.8.528): F1 help – MAP route → section id of docs/pages (home: admins home-page, else navigation-p);
                     <body data-nu-help> (NuCustomCode::bodyAttrs, app pages only), assets/js/nuapp.js opens it in a new tab; a new page = an
                     entry here (run.php checks route + id); e2e help_f1
core/nuadmin.php     admin roles (NuAdmin::RIGHTS, can/require) · core/numail.php mailer (NuMailer: settings, send, authType(), test probe)
core/nureportapi.php reports through the REST API: list, run (params → hash), PDF files + public token links (?p=reportfile), e-mail, jobs
core/nuupdate.php    update page logic: inspect/install/backup/rollback/maintenance, migrations (steps(): '0001-own-tables',
                     '0002-report-tables', '0003-translate-table', '0004-cloner-tables', '0005-setting-primary-db', '0007-drop-vendor-layout',
                     '0008-admin-roles', '0009-list-views', '0010-home-layouts', '0011-audit', '0012-notifications', '0013-setting-home-hooks', '0014-database-origin', '0015-form-drafts', '0016-pages', '0017-presence', '0018-home-hook-codes' (0.8.438: procedures nunext_home → nu_home), '0019-user-fields' (0.8.454), '0020-upload-template' (0.8.459), '0021-recent' (0.8.460), '0022-upload-template-folder' (0.8.478), '0023-retire-tfm' (0.8.479), '0024-object-access' (0.8.480) – add new steps at the end; 0006 was removed with the baseline;
                     every step needs an entry in NuMigrate::CHANGES – tables + kind + what, release.py stops otherwise), change history,
                     SQL dumps in backups · core/nugithub.php NuGitHub (0.8.374): newest release of a GitHub repository
                     (setting 'github_update', daily look: cron or start / update page of users with the update right, Update tile badge), download into _update/ (only signed kept),
                     cron.php update --github / API {"github": true} · core/nudbchanges.php preview of an update's database changes (install/db_changes.json,
                     written by dev/db_changes.php from NuMigrate::CHANGES + NuSchema::definitions, compared on "Check package" / dry run)
core/form/*.php      form runtime (meta, render, record, browse, hash variables, PHP API = nuBuilder's PHP functions)
                     browse lists (0.8.382): NuBrowse::build() keeps the form's browse SQL as nuBuilder does – columns become
                     nu_c<i>, the primary key nu_pk, ORDER BY items nu_o<k> of an outer SELECT (splitSelect / splitList; UNION … WITH
                     → wrapped as nuX); nu_filter_match via expandFilterMatch(). Access 9: NuRecord::accessOf() runs sob_all_access_condition
                     (read only, hash of the record, cached; error = 2) on render and save · core/nucompat.php NuCompat::findings() + SINCE
                     = the compatibility report's findings (C/M/N ids) with the version that supports them – update SINCE when one is fixed;
                     test application of the report: dev/tests/nub49/ (build_app*.php, e2e nub49_app, nub49_js)
                     form drafts (0.8.386): core/form/nusnapshot.php NuFormSnapshot (capture / apply / diff / hash of a whole form, also
                     used by NuFormHistory), core/form/nuformdraft.php NuFormDraft (zzzzsys_nunext_form_draft, step 0015: start / save with rev
                     check / undo / validate / publish / discard, preview session → NuFormMeta::load() returns the draft; admin right formpublish,
                     security settings form_drafts_required / form_preview_saves), core/form/nuformstore.php NuDraftStore = the builder's write
                     actions on a draft (pages/nubuilderapi.php nb_draft; live actions unchanged, e2e form_drafts_parity compares both)
                     page forms (0.8.387): sfo_type 'page' = PHP app in custom/pages/<sfo_code>/index.php (NuPage::dir(), $nuConfigCustomPagesDir);
                     core/nupage.php NuPage (settings row zzzzsys_nunext_page, pk = form id, step 0016; wrapper / run / asset, headers, errors) +
                     NuPageContext ($nu, API_VERSION 1 – the stable contract, listed in custom/PAGE_GUIDE.md); routes page / pageasset (JSON
                     401 / 400 / 403 / 404 / 500 for fetch requests), assets/js/nupage.js (NuPage.*, in the page) + nupagehost.js (parent side,
                     postMessage checks); builder pages/nubuilder_page.php + assets/js/nubuilderpage.js; updates never touch custom/ except
                     custom/.htaccess, PAGE_GUIDE.md, pages/_template/ (NuUpdate::shippedCustom); e2e page_forms. API versions (0.8.388):
                     NuPage::API_SUPPORTED / API_DEPRECATED / JS_API / JS_DEPRECATED, $nu->api(n) in the page (none = 1), adapters
                     NuPageContextV<n> for older supported versions (contextClass), apiInfo() → manifest page_api (release.py), scan() /
                     check() / report() → update page "Pages" card, builder, forms list badge. Never remove or change a $nu method within a
                     version: add, deprecate, or raise NuPageContext::API_VERSION with an adapter
                     concurrent edits (0.8.401): core/form/nuconflict.php NuConflict – signatures of the record + sub-form rows as opened
                     (nuNext.cc, forRecord()), save payload cc = signatures of the EDITED fields, check() before Before Save and again with a
                     row lock in the save transaction (NuConflictFound rolls back) → {conflict: {fields, by, at}} → dialog in nuruntime.js
                     (showConflict: Mine = take over the new signature, Theirs = field no longer edited); delete: cc_h; presence table
                     zzzzsys_nunext_presence (step 0017, formapi action presence, passive ping every 30 s); off: $nuConfigEditConflictCheck /
                     $nuConfigEditPresence, nuNextConflictCheck(false) / nuNextPresence(false) in Before Edit; e2e edit_conflict
                     forms with AI (0.8.403): core/form/nuformspec.php NuFormSpec – JSON format "nunext-form-1" (fromForm / fromSnapshot,
                     prompt(), parse(), check() → errors / warnings / plan, toSnapshot() on top of the live snapshot: rows keep ids, events, code;
                     "keep": true for types the spec does not describe), create() → new form with sfo_status 0 or NuFormDraft start + save;
                     missing table / columns via NuDDL after a tick; page pages/nuformai.php + assets/js/nuformai.js (copy / paste, no API key);
                     e2e form_ai
                     form JS (0.8.383): HTML-object scripts → type text/nu-deferred (NuRender::deferScripts), run by nuNextRunDeferred();
                     nuAfterSave after the reload (sessionStorage nuNextAfterSave); edited flag reset after nuOnLoad (field nuEdited marks stay)
core/nureport.php    reports: NuReport (data, rights, designer data) · core/nureportengine.php NuReportEngine (nurunpdf.php port, TCPDF)
core/nuapi.php       REST API router (routes with scopes; a route may return a string = file, self::$after = work after the answer)
                     · core/nuapiclient.php clients, SCOPES, tokens, rate limit · core/nuapiplayground.php
core/nusqlselect.php nuBuilder's SQL builder (zzzzsys_select) · core/nuappcloner.php App cloner (MySQL + SQL Server)
core/nulibraries.php library overview of the update page (registry + NuSecurity::libraries(), NUNEXT-VERSION.txt of copied tools)
core/nulibs.php      library packs (0.8.405): TCPDF, PHPMailer, TinyMCE, phpMyAdmin (dir nudb), Adminer, Tiny File Manager as signed zips
                     nunext-lib-<slot>-<version>.zip (nunext-lib.json + lib/, Ed25519 with the nunext.zip key over payload()), installed to
                     vendor/packs/<dir>-<version>/ (0.8.409; temp folder + rename, sha256 per file; vendor/packs/ deny-all, written by ensureBase();
                     deny-all .htaccess for server-only packs, NuLibs::ALLOW for browser packs), active version in vendor/packs/nunext-libs.php
                     ("<?php exit; ?>" + JSON), KEEP older ones for roll back; packs of 0.8.405–0.8.408 in third_party/ are read as an old place
                     (legacy(), only for a slot without a vendor/packs entry; isLegacy()); phpMyAdmin / TFM configs reach nuconfig.php through the
                     shim vendor/nuconfig.php ("../../nuconfig.php" from vendor/packs/<x>/); sources: _update/ (+ _install/ in the
                     installer), upload, GitHub release tag "libraries" (signed libraries.json, setting github_libs, NuGitHub::get, daily with
                     NuGitHub::autoCheck). Lookups use the active pack first: NuReport::tcpdfDir, NuMailer::phpMailerCandidates, NuLibs::tinymce(),
                     NuVendor::dir, dbadmin.php nu_adm_dir() – the old places only while no pack is installed. Update page tab Libraries
                     (actions lp_*, assets/js/nulibpacks.js), installer step libs (nu_install_libs), dev page ?p=libpack (pages/nulibpack.php:
                     key uploaded per request, never stored) and php dev/libpack.php. Tests: run.php (own key), e2e libpacks (needs SITE + the
                     owner's key), dev/tests/install.js LIBPACKS=<folder>. Versions a release needs (0.8.406): install/libraries-required.json
                     {slot: {min, needed}} → manifest requires_libs (release.py) → NuLibs::required() / unmet() → Check package box #updLibReq
                     ("Install the packs first", back to the check), tab status, unattended update "libraries"; e2e libreq. Raise a minimum only
                     after its pack is in the GitHub release "libraries".
core/nuxlsx.php      NuXlsx / NuXlsxSheet / NuXlsxCell (0.8.398): own .xlsx writer (ZipArchive only) – typed cells (strings never formulas),
                     styles, freeze / autofilter / merge / widths; list exports (NuListExport::writeXlsx, ?export=xlsx, ?cols= = visible
                     columns of the print view), form browse lists (formexport&fmt=xlsx), $nu->excel(), procedures (new NuXlsx). Export menu
                     of lists and form browse lists: NuListExport::buttons() / menu() (HTML page, CSV …, Excel; #nuPrintButton = the group)
core/nusqldump.php   SQL dump for backups (uses the cloner's export) · core/nusample.php sample application (VERSION constant)
pages/nu*.php        one file per route; POST → action → nu_flash + nu_redirect (post/redirect/get); render with
                     nu_layout_start(title, active) … nu_layout_end('<script src="' . nu_asset('assets/js/x.js') . '"></script>')
assets/js/nuapp.js   global behaviour: leave guard (form[data-nu-guard]), confirm dialogs (form[data-confirm]), tooltips, pager
                     jump, data-nu-once, red Save, change history, … · assets/js/nu/*.js form runtime (nuruntime.js, nucompat.js)
assets/css/nuapp.css global styles (tokens, dark mode, tabs, buttons) · nuform.css form pages
lang/*.php           return [key => text]; t('key', ...$args) uses sprintf (%s, %d)
docs/toc.json        help (0.8.493, DeepWiki-like): chapters → pages, one file per page in docs/pages/NN-MM-name.htm (one <section id>, one h2,
                     sub-sections div.doc-sub > h3; p.doc-lead / doc-for / doc-see, details.doc-src); tab guide / ref per chapter; pages/nuhelp.php
                     joins and filters them per reader (data-doc="admin" / "dev", data-acl="<form ids>", also on any element) and numbers them;
                     assets/js/nuhelp.js: index chapter → page → h3, search, "Show advanced topics" (data-level="advanced", localStorage
                     nunext-help-advanced, a link into an advanced topic turns it on), Code reference loaded on demand (?p=help&part=ref, ?tab=ref);
                     ids unique over all files and every #link checked by run.php (also: every page in toc.json, no 0.8.x in the text, chapter not
                     more restrictive than its pages); e2e help_tabs / help_pages / help_dev. #devflow (chapter 13, data-doc="dev") = "Development
                     with Claude": keep it in step with this file. Diagrams: inline SVG with classes (strict CSP) – convert a drawn SVG with
                     python3 -I dev/doc_svg.py <file.svg> <id prefix> and paste it as <figure class="doc-figure">; colours .doc-flow in nuapp.css
                     (n neutral/browser, p server, g database/result, a your code/exits; dg-lbl labels). Our own diagrams are drawn in
                     dev/doc_diagrams.py (10, 0.8.496) – change them there and run python3 -I dev/doc_diagrams.py (replaces <figure id="dg-<name>">)
install/             schema_mysql*.sql, schema_mssql.sql, schema_expected.json (structure check of updates), settings defaults
                     nubuilder49_tables.sql (nuBuilder 4.9's tables without data, written by dev/nub49_tables.php from nubuilder4.sql): the
                     installer's upgrade of an uploaded nubuilder4.sql of any 4.x (NuInstall::checkDump / upgradeTo49 / ALTER_49, 0.8.384;
                     dev/tests/nub49/upgrade_check.php for the 4.5–4.9 dumps, dev/tests/install.js runs the wizard)
dev/                 release.py, sign.php, check_lang.php, tests/run.php (unit tests, no DB), tests/smoke.js (browser),
                     phpstan.neon, eslint.config.mjs – not part of the install package
cron.php             housekeeping for cron (CLI only) · .github/workflows/ci.yml · .gitignore
```
Own tables: `zzzzsys_nunext_recent` (side bar, 0.8.460), `zzzzsys_nunext_log`, `_form_history`, `_user_2fa`, `_token` (kinds: pw_reset, report_file …), `_session`, `_setting` (key/value:
`security`, `backup_keep_sql`, `demo_reset`, `mail_auth_type`, `listview_defaults`, `audit`, `notify`, `home_fav_only_ga` …), `_api_*`, `_dashboard`,
`_widget`, `_admin_role`, `_admin_user`, `_list_view` (list views and saved start page layouts – with `fav` favourites), `_audit` (audit trail),
`_notification` + `_notification_user` (notification centre), `_form_draft` (form drafts), `_page` (page forms), `_migration`. Settings pattern: see `NuHome::save()` /
`NuUpdate::setKeepSql()` (NuSchema::ensure(), then update or insert by `zzzzsys_nunext_setting_id`).

## Conventions
- **Every PHP file of core/, core/form/, pages/, lang/ starts with** `<?php` / `declare(strict_types=1);` / `defined('NU_ROOT') || exit;`
  (direct web calls end at once; `dev/tests/run.php` checks it). New folders get the deny-all `.htaccess` (`NuSecurity::DENY`).
- **Dangerous globeadmin pages / actions** call `NuSecurity::requireRecentAuth(nu_url('<route>'))` (password again after 15 min).
- **Rights on nuform / nuprocedure / nuselect / nusetup / nuuser / nuaccess are admin-equivalent** – new code that grants rights or
  assigns access levels must respect `NuSecurity::restricted()` (see pages/nuaccesslevel.php, nuuser.php, core/nuapi.php).
- Ad-hoc SQL typed by users: `NuDemo::readOnlySql()` + `nu_db()->readOnly(fn)`; never show SQL error details to non-globeadmins.
- New log events with possibly secret values: `NuLog::diff()` masks names matching `NuSecurity::secretName()`.
- **Strict CSP** (`script-src 'self'; style-src 'self'`): no inline `<script>`, no `onclick=`, no `style=` attributes and no
  `<style>` injected by JS. Setting `element.style.x` from a JS file is fine. Page behaviour goes into `assets/js/<page>.js`; pass
  data via `data-*` attributes. Form pages relax the CSP for nuBuilder form code.
- Escape output with `h()`. Links with `nu_url('route', [params])` (null/'' params are dropped). Assets with `nu_asset()` (version
  stamp). POST forms need `nu_csrf_field()`. Read input with `nu_post()`, checkboxes with `nu_check()` ('1'/'0').
- **Both databases**: write SQL that works on MySQL and SQL Server, or branch on `nu_db()->type` ('mysql' / 'sqlsrv').
  **Joins / comparisons between nuBuilder Next's own tables and nuBuilder's tables** (e.g. `zzzzsys_nunext_log.user_id` = `zzzzsys_user.zzzzsys_user_id`)
  go through `nu_db()->sameText('a.col', 'b.col')` – live databases mix collations (utf8mb4_general_ci / utf8mb4_unicode_ci → MySQL error 1267, 0.8.331).
  Paging with `NuDB::page()`. New ids with `nu_id()` (nuBuilder style).
- Rights: `NuAcl::can(NuAcl::FORM_X, 'save'|'delete'|'clone'|'add')`, `NuAcl::require(...)`. Admin pages (formerly globeadmin only) use
  **`NuAdmin::require('<right>')`** (core/nuadmin.php, 0.8.323: view on GET, change on POST; `NuAdmin::can($right, 'view'|'change')`) – a new
  admin tool gets an entry in `NuAdmin::RIGHTS` (+ `NuHome::TILE_RIGHTS`). `['globeadmin']` checks are left for things only the real globeadmin does.
- Log every change: `NuLog::write('event_name', table, id, details)`; the log shows `t('ev_event_name')` – add that key.
- **Secrets in the database**: a new table with secrets is either `zzzzsys_nunext_*` or goes into `NuDemo::HIDDEN_TABLES`; secret keys in
  `sus_json` are masked by `NuSecurity::demoMask` (LOGIN_TOKEN, 2FA_, OTP_, PASSWORD_, NUNEXT_AUTOLOGIN) – extend the pattern for new ones.
- **Public links with a token** (one-time login, report PDF): random token, only its hash stored (`NuToken` or HMAC), limited time / use,
  used up in ONE conditional UPDATE (compare and swap), logged; the page is a `'public' => true` route.
- **Settings of nuBuilder Next itself** (not nuconfig variables): a row in `zzzzsys_nunext_setting` or a key of the Security settings
  (`NuSecurity::DEFAULTS` + the form in pages/nusecuritycheck.php); nuBuilder's tables get no new columns.
- Demo mode (`core/nudemo.php`): POSTs are refused except `READ_POSTS` (actions that only read) – add new read-only POST actions there.
- Long tasks / downloads: `session_write_close()` first; `nu_time_limit(0)` where needed. Functions hosts may disable
  (disable_functions) are never called directly – `@` does not stop "Call to undefined function": use `nu_time_limit()`,
  `nu_ignore_abort()`, `nu_ini_get()`, `nu_ini_set()` or a `function_exists()` check (disk_*_space, posix_*, opcache_*) (0.8.273).
- **Third-party code** (0.8.409, `vendor/`): `vendor/bundled/` = libraries of the package (an update replaces them, never deletes –
  `NuUpdate::isLibrary`), paths ONLY through the constants of core/nuapp.php (`NU_VENDOR`, `NU_BS`, `NU_BI`, `NU_TOMSELECT`, `NU_CODEMIRROR`,
  `NU_QRCODE`, `NU_JQUERY`, `NU_JQCONFIRM`, `NU_FORMATTER`, `NU_APEXCHARTS`, `NU_FONTAWESOME`; JS files: the literal `vendor/bundled/…`);
  `vendor/packs/` = library packs (NuLibs, `NU_PACKS`, `NuUpdate::isProtected`); `dev/libsrc/<slot>/` = sources of the packs release.py
  builds into `_install/` of nunext.zip (PHPMailer). Old places (read until the upgrade baseline moves): `assets/vendor/`, `third_party/`,
  `lib/PHPMailer`. Sync to a test site with `rsync -a --exclude /nuconfig.php --exclude /vendor/packs --exclude /_libsrc --exclude _backup` (`vendor/nuconfig.php`
  must come along). Pack sources for *Build library packs*: `../libsrc/<slot>/` next to the project, or `_libsrc/<slot>/` inside it
  (0.8.412, hosts with open_basedir; deny-all, protected, never packaged) – NuLibs::libsrcBases().
- **Libraries**: every library in `vendor/bundled/` has an entry in `vendor/bundled/libraries.json` (name, version, dir, file, detect
  regex, licence, use key `upd_libuse_*`). Changing a library = change files + registry; `dev/release.py` checks that the file shows the
  registered version and copies the list into the manifest (update page: card Libraries, "Libraries that change").
- Lists: `nu_list_footer($total, $page, $per, 'route', $params[, class, param, anchor])` = the pager used everywhere.

### UI patterns (keep them consistent)
- **(i) tooltip** next to a label (outside the `<label>`, so a click does not toggle a switch):
  `<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="…" title="…"><i class="bi bi-info-circle"></i></span>`
  Tooltips are initialised globally in `nuapp.js`.
- **Record buttons** (0.8.199, nuBuilder style): `btn nuBtnAct nuBtnSave` + `bi-floppy`, `btn nuBtnAct` + `bi-copy` (Clone),
  `btn nuBtnAct nuBtnDel` + `bi-trash`. Save turns red via class `nuSaveEdited` – set automatically for `.nuBtnSave` when its form
  changes (nuapp.js); on phones the three show only their icon. Save buttons carry `data-nu-save` (Ctrl+S).
  The Back button next to them: `btn nuBtnAct` + `bi-arrow-left` (0.8.211). Toolbar buttons have the normal Bootstrap size (no
  `btn-sm`) everywhere – lists, own pages and the form runtime (0.8.252); list search fields are 350 px wide.
- **Back** on every own page (0.8.256): `<?= nu_back_button() ?>` (core/nulayout.php) – first button of the buttons row. Title bars
  (0.8.268) always as on the user form: `<div class="nuTitleBar">` h1 · optional `.nuTitleSub` · LAST a `<div class="d-flex flex-wrap gap-2">`
  with Back and the other buttons (`btn nuBtnAct`, normal size) – never buttons as direct children next to the h1. Target: the breadcrumb parent from its `$parent` map, otherwise Home; record pages link to
  their list. New pages: add the button (and a map entry if they sit below another page).
- **Lists**: `table nu-table` – header with the card's background (no grey band), light uppercase labels, a line below (0.8.208).
  Secondary title bar buttons on phones (0.8.371): `NuListView::page(…, ['more' => [['icon', 'label', 'href', 'title', 'blank', 'id'], …]])`
  – buttons from 576 px on, below that a ⋮ menu right of Add with these entries and Print (users list: Import).
- **Tabs**: plain Bootstrap `.nav-tabs`; the underline look (0.8.312: no boxes, grey text, selected = blue text + 2 px blue line below; before: nuBuilder boxes 0.8.266) comes from `nuapp.css` (`--nu-tab-*` variables, `--nu-tab-surface` = background the tabs sit on; dark mode included).
- Confirmations: `<form data-confirm="text" data-confirm-ok="Delete" data-confirm-class="btn btn-danger">`. Combined with `data-nu-once`
  the OK of the dialog uses `requestSubmit()`, so the button still gets its spinner (update page → Install).
- Unsaved-changes guard: `<form data-nu-guard>`. Send-once forms: `<form data-nu-once>` + `data-nu-busy="…"` on the button
  (and check on the server, e.g. one-time token – see "Back up now" in `pages/nuupdate.php`).
- **Bootstrap JavaScript only through `nuBs`** (`assets/js/nubs.js`, 0.8.244): `nuBs.modal(el[, opts])`, `.tooltip`, `.tooltipOf`,
  `.dropdown`, `.tab`, `.toast`, `.collapse`, `.ready()` – never `bootstrap.X…` in our JS (ESLint global `nuBs`). Pages that load
  `bootstrap.bundle.min.js` load `nubs.js` right after it. `window.bootstrap` stays for nuBuilder form code.
- Dark mode: `data-bs-theme` on `<html>`; use Bootstrap variables (`var(--bs-…)`) or own tokens with a `[data-bs-theme="dark"]` variant.
- Icons: Bootstrap Icons only (nuBuilder forms may still use Font Awesome).

## Test environment (rebuild it in a new session)
Nothing of the earlier test setup survives; this is how it was done (Linux container, PHP 8.3, MariaDB, Playwright + Chromium):
1. MariaDB may be missing in a new container: `apt-get install -y mariadb-server rsync`. `service mariadb start`; `mysql -e "CREATE DATABASE nutest; CREATE USER 'nu'@'localhost' IDENTIFIED BY 'nu'; GRANT ALL ON nutest.* TO 'nu'@'localhost';"`
   (for App cloner tests also `GRANT ALL ON nutest_cl.* …`).
2. Copy the package to a test folder (e.g. `<scratch>/site`), open it once → installer writes `nuconfig.php`; or write it by hand
   (`$nuConfigDBHost = "127.0.0.1"; $nuConfigDBName = "nutest"; $nuConfigDBUser = "nu"`, globeadmin `globeadmin` / `nu`).
3. nuBuilder itself (for TCPDF, PHPMailer, Font Awesome, nubuilder4.sql): `git clone --depth 1 https://github.com/nuBuilder/nuBuilder.git <scratch>/nub`.
   Simplest (0.8.207): put the test copy **inside** it – `<scratch>/nub/nunext` – then nuBuilder's libraries are found and the URL is
   `/nunext/…` like on the live server (cookie path!). Import `nub/nubuilder4.sql`, then Admin tools → *Sample data* installs the sample
   application (users sample_anna … sample_eva, random passwords: set known ones with `UPDATE zzzzsys_user SET sus_login_password = …`).
4. Web server (from the nuBuilder folder, larger uploads for update packages):
   `(cd <scratch>/nub && setsid nohup php -d upload_max_filesize=32M -d post_max_size=32M -S 127.0.0.1:8099 -t . > <scratch>/srv.log 2>&1 &)`
   → `http://127.0.0.1:8099/nunext/`. MariaDB and the PHP server **stop between turns** – restart both before testing
   (`service mariadb start; curl -s -o /dev/null http://127.0.0.1:8099/nunext/ || (…start the server…)`).
   Known passwords for the sample users (the tests used `Test1234!`):
   `php -r 'echo password_hash("Test1234!", PASSWORD_BCRYPT);'` → `UPDATE zzzzsys_user SET sus_login_password = '<hash>' WHERE sus_login_name LIKE 'sample_%'`.
5. nuBuilder itself for `compat.js`: `<scratch>/nub/nuconfig.php` = nuBuilder's `nuconfig-sample.php` with `$nuConfigDBName = "nutest"`,
   user / password `nu` → `http://127.0.0.1:8099/` (same PHP server). Its own JS error `nuToast is not defined` (4.9.2) is only noted.
   Browser tests: Node + Playwright (`NODE_PATH=$(npm root -g) node test.js`), Chromium is preinstalled (do not run
   `playwright install`). Log in at `index.php?p=login` with globeadmin / nu. `dev/tests/smoke.js` (BASE=…/nunext/) opens every page.
   Login rate limit: failed logins stay in `zzzzsys_nunext_log` for 15 minutes – delete `login_failed` rows between test runs.
   The installer asks for the token unless PHP's built-in server is used from the same computer.
8. Library pack Uppy for the e2e test `uppy_upload` (skipped without it, 0.8.459): copy nuBuilder's `third_party/uppy/` to `../libsrc/uppy/`,
   `php dev/libpack.php ../libpacks ../nunext-signing.key uppy`, then Update → Libraries → *Upload a pack* on the test site.
7. Static analysis: PHPStan **2.1.17** (the version `.github/workflows/ci.yml` pins – newer ones report false alarms, e.g. 2.3.1
   `closure.unusedUse` on variables that are used) from github.com/phpstan/phpstan/releases/download/2.1.17/phpstan.phar to `../phpstan.phar`; ESLint:
   `npm install --no-save --prefix .. eslint@9.17.0 globals@15.14.0` (both outside the project, release.py finds them).
6. After copying changed files wait ~3 s (OPcache) before reloading. Sync the whole package with
   `rsync -a --exclude /nuconfig.php --exclude /vendor/packs --exclude _backup ./ <site>/` (0.8.409: `/nuconfig.php` – the shim
   `vendor/nuconfig.php` must be copied).

Test database discipline: before a test that changes data, `mysqldump -unu -pnu nutest <tables> > <scratch>/pre.sql`; afterwards
restore it and remove what the new version created, so the next release tests its database step again – e.g.
`DROP TABLE zzzzsys_nunext_audit, zzzzsys_nunext_notification, zzzzsys_nunext_notification_user; DELETE FROM zzzzsys_nunext_migration
WHERE zzzzsys_nunext_migration_id IN ('0011-audit', '0012-notifications'); DELETE FROM zzzzsys_permission_item WHERE pme_ident LIKE
'NUNEXT_NOTIFY%'` (state at 0.8.359: migration rows up to 0010 applied; audit and notification tables absent). The smoke test also
runs the migrations – clean up after it the same way.

Gotchas seen while testing:
- Playwright `click()` waits for the navigation it started – use `{noWaitAfter: true}` to look at a page before it leaves.
- In a form with `<input name="action">`, `form.action` is that input – use `form.getAttribute('action')`.
- Screenshots right after a theme switch or typing can catch CSS transitions (colours half way) – wait ~400 ms.
- The App cloner's Run asks with `window.confirm` (accept the dialog in tests).
- SQL Server could not be tested here (image registries blocked, no pdo_sqlsrv): MSSQL code was checked with simulated catalog data
  and the T-SQL parser `sqlglot` – see the checklist in `TODO.md`.
- Never `pkill -f <name>`: it also matches the shell running the command and ends it – kill by PID (`pgrep -f …`, then `kill`).
- The test database mixes collations on purpose (zzzzsys_user utf8mb4_general_ci, own tables unicode_ci) and zzzzsys_user is MyISAM
  (no transactions – a "dry run" must check first and write afterwards).
- Mail without a real server: a small Python SMTP server on 127.0.0.1:2525 (scratch script) – switch *Use SSL* off for it; or
  `$nuConfigMailLog` (mails written to a file instead, NuMailer::mailLogFile()).
- REST API tests: get a token through the API playground's JSON action `token` (client_ref = the internal client id), then call
  api.php with `Authorization: Bearer …`; give the client's service user the needed rights / scopes for the test and take them back.
- Never use `pkill -f` / `pgrep -f` with a pattern that the shell command itself contains; find the server with
  `ps -eo pid,args | grep "[p]hp .*8099"`.
- Shell JSON / SQL with quotes: pass SQL to `mysql` through stdin (`execSync('mysql …', {input: sql})` in Node), not inside "…".
- Tom Select fields: type into `.ts-control input` of the wrapper and press Enter; options are `.ts-dropdown .option`.
- Long tap on phones: dispatch synthetic `TouchEvent`s (`touchstart`, wait, `touchend`) in `page.evaluate` (context with `hasTouch`).
- Hover delays: move the mouse in small steps (`page.mouse.move`) and wait – tile tooltips need 800 ms of rest.
- The bell polls `?p=notifyapi` every 60 s; a GET there does not refresh the idle time (`NU_PASSIVE`, index.php / NuAuth::user).
- `dev/release.py` refuses a version that is not higher than NU_VERSION: if a release must be rebuilt before it was delivered, set
  NU_VERSION back and remove its CHANGELOG.md section and manifest `changes` entry first (then run with `--no-checks`, the manifest
  signature test fails until the release re-signs it).

## Where things stand (October 2026, 0.8.462)
Newest first, details in CHANGELOG.md; last gate 0.8.463 (full test run, pushed, published by the owner as v0.8.463 – creating GitHub releases is refused for this session type, the owner does it by hand):
0.8.464 help in two tabs: Guide / Code reference (docs/nunext-ref.htm, loaded on demand); 0.8.463 (gate, published v0.8.463);
0.8.462 gap analysis B2 (no own tables from anonymous requests), B5 global hooks, B6 calc in sub-forms, B7 onnuload / onenter, nuFORMdata,
#USER_NAME#, nuLookupRecord ID, NULL group footer, Compatibility check G1–G8, kit tests; 0.8.461 gap analysis B3 data mode / form type,
B4 access condition in the save transaction (savepoint); 0.8.460 optional side bar with favourites and recently used (NuSidebar); 0.8.459 uploads with Uppy (file target "File System", library pack uppy, route ?p=upload, hardened template, see NuUpload); 0.8.458
permission items of the user form as a table; 0.8.457 (gate) smoke / e2e sign-in helpers wait for the navigation (rate limit test was flaky);
0.8.456 function reference: PHP / JavaScript tabs, every function with example, parameters and sample result; 0.8.455 Customise as icon next to ☆, search max 300 px; 0.8.454 (gate) custom user fields (NuUserFields, see Architecture); 0.8.453 tab order Appearance · Content · Layouts; 0.8.452 start page: one Customise button (#nuHomeViewBtn) – panel tabs Content (#nuHomeVpEdit / #nuHomeVpMine click the hidden
#nuHomeCustomize / #nuHomeMine), Appearance (former View; V), Layouts; tab in localStorage nunext-home-cust-tab; tile size gone from the
edit bar; e2e helpers homeTab / homeEdit / homeMine, test home_customise;
0.8.451 (gate) update page → Backups: delete several (NuUpdate::deleteBackups, protectedBackups = newest + newest "before rollback"),
clean-up rule (cleanupRule / cleanupPlan / autoCleanup after install(), setting backup_cleanup, assets/js/nubackups.js, e2e backups_bulk
with dev/tests/fakebackups.py); ?tab=<name> lets the server open a tab (update page, Setup code) so nutheme.js hides [data-nu-tab-restore]
only when #hash ≠ ?tab= (no blank tab when paging); form[data-confirm] skips submit buttons with data-no-confirm; no gate reminders;
0.8.447 builder buttons in a row below the title; 0.8.446 options menu ⋮ without add / print / search / form info / form properties /
object list (shortcuts stay, Ctrl+Shift+M opens the (i) panel); 0.8.445 SMTP login with OAuth 2 (NuMailOAuth, oauth.php, Google /
Microsoft 365 / other, e2e mail_oauth with dev/tests/fakeoauth.py); 0.8.444 Setup code page (set_header / set_style / set_include),
Setup → Include on form pages, no first-tab flash (data-nu-tab-restore); 0.8.443 $nuConfigIncludeJS / CSS, PHP includes from nuBuilder's
folder, nuSetSaveButtonPosition; 0.8.442 nuconfig.php editor "Insert variable" (NuConfigFile::catalog); 0.8.441 count() out of loop
conditions; 0.8.440 release.py without shell; 0.8.439 builder preview Browse | Edit; 0.8.438 hooks nunext_home → nu_home (step 0018),
user form tabs General / Permission items; 0.8.437 page size menu in list footers (?per=); 0.8.436 pager only with several pages;
0.8.435 form info (i) panel for the globeadmin; 0.8.434 e-mail template without code title; 0.8.433 DeepScan clean-up; 0.8.432 e-mail
"Check connection" (NuMailer::check) and foldable template fields; 0.8.425–0.8.431 user start pages per access level, browse search
highlighting and reset, PHP 8.1 curl constant, Tiny File Manager status. Open from the owner's last questions: rename the options menu
entry "Sessions" to "Logged-in users"; nuSetSaveButtonProperties and the full arguments of nuAttachSaveButtonTo (both not built yet).

### Earlier state (0.8.360)
Newest first, details in CHANGELOG.md: 0.8.357/0.8.358 notification centre (bell, panel, must confirm, Manage with recipients
from users / departments / teams / positions / access levels, live or fixed list, read receipts, e-mail, permission items NUNEXT_NOTIFY /
NUNEXT_NOTIFY_OWN, `nuNextNotify()`, API /v1/notifications); 0.8.352–0.8.356 start page: favourites (☆ in Customise, "Favourites" filter
on the page and in Customise), compact tiles show name + description as tooltip (mouse resting 800 ms, long tap on phones) – the size
"Large, description on (i)" of 0.8.352 was removed again; 0.8.350/0.8.351 audit trail (one row per changed column, history per record,
restore, settings, delete dialog with CSV first); 0.8.349 nuBuilder's `<table>_nulog`; 0.8.346–0.8.348 update preview of database
changes (install/db_changes.json); 0.8.345 13 more nuBuilder JS hooks; 0.8.344 number parsing of pasted values; 0.8.342 SMTP authentication method (Automatic / LOGIN / PLAIN / CRAM-MD5) and a test
mail that shows the methods the server offers; 0.8.341 demo mode: stricter SQL check (every word), sus_json masking, 10 saves per hour,
demo data snapshot + reset, `$nuConfigIsDemo` honoured; 0.8.340 reports through the REST API (scope reports:run, public PDF links
?p=reportfile, Accept: application/pdf, e-mail, async jobs); 0.8.339 one-time login links (nuBuilder's LOGIN_TOKEN) in the user form,
PHP `nuCreateLoginToken()`, API `/v1/users/{id}/login-link`; 0.8.338 longer password confirmation in developer mode; 0.8.337 form
browse lists in the look of the system lists; 0.8.336 `$nuUseMd5PasswordHash` honoured (md5 storage, red Security check point);
0.8.333/0.8.334 saved start page layouts and list views for all system lists (0.8.329: users / access levels); 0.8.331 mixed
collations (`sameText`); 0.8.327 user CSV import; 0.8.328 tutorial; 0.8.323 admin roles; 0.8.321 upgrade baseline.
Earlier: 0.8.298 dashboards, 0.8.297 branding, 0.8.295/0.8.291 App cloner 1:1, 0.8.282 Adminer, 0.8.276 saved Cloner configurations,
0.8.207 security review, 0.8.183 reports. Open checks are in `TODO.md` (sections "please verify").

### Earlier notes (0.8.207–0.8.296)
Newest first, details in CHANGELOG.md: 0.8.295 App cloner with consistent snapshot, streaming, size-limited batches, UTC
(ideas from mysqldump); 0.8.291 App cloner 1:1 (verified, test harness described in the changelog); 0.8.282 Adminer via dbadmin.php
+ primary database tool; 0.8.276 saved Cloner / App cloner configurations (zzzzsys_cloner / zzzzsys_app_cloner); settings page with
implementation status tags; $nuConfigEnableDatabaseUpdate. Older state (0.8.207–0.8.244):
0.8.207: security review S1–S26 / B1–B10 built (TODO.md "0.8.207 security review – please verify", Help → Security).
0.8.208–0.8.212: list headers without grey band, maintenance mode ends by itself after a complete update (`NuUpdate::maintenance()`),
spinners on Check package / Install, Back buttons as `nuBtnAct`, `$nuConfigBaseURL` explained (docs + sample comment). 0.8.213: README, Help and these notes checked; libraries missing from the package → TODO.md. 0.8.214: Bootstrap Icons 1.13.2 in the package. 0.8.224: ALL vendor libraries in the package (rebuilt from npm / nuBuilder – composition in TODO.md); keep them in assets/vendor/ – an update never deletes library files. 0.8.229: library overview (update page) + registry assets/vendor/libraries.json.
0.8.230–0.8.240: Libraries card collapsed after Change history; NUNEXT-VERSION.txt for own builds of copied tools (Tiny File Manager:
2.6+git.4149143.nu1, CVE-2025-15138 still open); TCPDF 7 via a Composer folder in third_party/tcpdf (NuReport::tcpdfAutoload, fonts of
tc-lib-pdf-font; the bundle was built from GitHub clones because packagist is blocked here); report designer title row; forms list
status + group filters (archived hidden); users list Position / Code + position filter; lowest TinyMCE editor of a tab fits the window
(250–800 px, legend stays visible); README / Help / TODO checked again (0.8.240). 0.8.241–0.8.244: settings descriptions as (i)
below 800 px, one tooltip at a time, Bootstrap JS through `nuBs` (Bootstrap 6 evaluated: stay on 5.3.8, see TODO.md).
Recent larger pieces (details in CHANGELOG.md): reports with PDF runner, report designer and SQL builder (0.8.183, uses
nuBuilder's TCPDF), ApexCharts (0.8.182), change history on the update page (0.8.186–189), backups with an optional
SQL dump, keep rule and incomplete check (0.8.197), App cloner and backups on SQL Server (0.8.198, **unverified on a real SQL
Server**), nuBuilder-style buttons and tabs (0.8.199). Open checks are in `TODO.md` (sections "please verify").
