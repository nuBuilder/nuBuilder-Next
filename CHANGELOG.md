# nuBuilder Next – changelog

Every version, newest first. A new section is written by `dev/release.py`; the same text is shown on the update page
(Admin tools → Update → Change history). Installation and overview: [README.md](README.md).

## 0.8.388 – Page API versions
- Page forms: the page API has a version (1). A page declares the version it is written for with $nu->api(1) as the second line
  of index.php (template, guide and Copy AI prompt include it; no line = 1); an unsupported version stops the page with a clear
  message for the globeadmin. NuPage.version in nupage.js.
- Compatibility rule (guide and Help): within one version methods are only added, never removed or changed; a breaking change
  raises the version and the older one keeps running through an adapter for at least 6 months / 20 releases. Deprecated methods
  keep working and write a notice to Debug results once a day per page (JavaScript: a console warning).
- The update manifest lists the page API of each release (versions, $nu methods, NuPage names, deprecations). "Check package"
  on the update page checks every page form's files against it and lists the pages the new version would break or that use
  deprecated calls – before installing.
- Form builder of a page form: declared API version and the problems with the installed version; the forms list shows a warning
  next to the type of such pages.

## 0.8.387 – Page forms (custom PHP)
- New form type "Page (custom PHP)": a PHP app in custom/pages/<form code>/index.php that nuBuilder Next runs after it has
  checked the login and the access level's view right on the page form – the page writes no login code. Opened like any form
  (menu, start page tile, run button, iframe run object, nuForm(), bookmark); open mode per page: new tab (default), inside
  nuBuilder Next (iframe with breadcrumbs, height "auto" or fixed) or the whole window.
- The page API $nu (version 1): the user (login, names, e-mail, team, department, position, permission items, access level),
  hasPermission / inTeam / inDepartment / hasPosition / can / rights / require, the record and the opening form (only with the
  right on it), nuSetProperty values, hash variables, page settings (JSON, dot paths), secrets.php, database helpers with
  checked table names and the audit trail, URLs, assets, JSON answers, notifications, the debug log and an own session store.
- nupage.js for the page: NuPage.fetch (CSRF token, JSON errors), automatic iframe height, toast, close, refresh of the opening
  form, open a form, title. Messages are accepted only from page iframes of the same origin.
- Security: login and view right before any page code, folder only from the form code (checked, confined to the pages folder),
  CSRF on every POST, JSON answers for fetch requests (401 login, 400 csrf, 403, 404, 500 – error details for the globeadmin only),
  framing by the same origin only, assets only from assets/ with a type whitelist. Every page file starts with the NU_PAGE guard;
  custom/ is blocked by .htaccess, Help → Security has the nginx rule and the security check warns when custom/ is reachable.
- Form builder of a page form: folder status (index.php, guard, secrets.php), open mode, iframe height, settings JSON (checked,
  Format button), Content-Security-Policy, notes; Open page, Create from template, Copy AI prompt (guide, page data and the
  application's tables for any AI). Form codes of pages must be valid folder names.
- custom/PAGE_GUIDE.md (the guide for page authors and AIs) and custom/pages/_template/ come with the package; updates never touch
  the installation's own page folders. Backups can include them ("Include custom pages"). Form cloner and App cloner copy the page
  settings, with a hint to copy the folder by FTP. $nuConfigCustomPagesDir in nuconfig.php can move the pages folder out of the web root.
- Help: chapter "Pages (custom PHP)".
- Database step 0016 (table zzzzsys_nunext_page).

## 0.8.386 – Form drafts
- Form drafts (phase 1): change a form in a draft while users keep working with the live form, then publish it in one step.
  Start draft in the form builder copies the whole form (settings, tabs, objects, events, PHP hooks, list columns, SQL builder
  selects); one draft per form, shown in the forms list as "Draft · author" (filter "With draft"); undo in the draft (20 steps).
- Preview of a draft: only for its author, with a banner; read-only by default, saving in the preview (live data) after a
  confirmation when allowed. Forms the draft opens that have a draft of the same author are previewed too.
- Compare with live: form settings, tabs, objects (added, removed, changed, moved), events, PHP / JavaScript line by line,
  list columns and SQL builder. Publish with a note, the change summary and checks (errors stop, warnings need a tick); the live
  form goes into the history first, is replaced in one transaction and kept as "published vN" (never pruned).
- Conflicts: a live change after the draft was started is shown at publishing; overwriting needs the form code typed in. Two
  windows on one draft: the second save is refused and the builder reloads.
- New admin right "Publish forms (drafts)". Security settings "Change published forms only through drafts" (new forms then start
  as drafts, visible to form designers only; the globeadmin may still change live after a confirmation) and "New drafts: allow
  saving in the preview". While a draft is open, live changes ask first and deleting the form is refused.
- Form history: snapshots now also hold the PHP hooks of lookups and the SQL builder selects (older entries restore as before).
- Help: chapter "Drafts and publishing"; the start page chapter no longer mentions the removed Reset.
- Database step 0015 (table zzzzsys_nunext_form_draft).

## 0.8.385 – Start page profiles
- Start page profiles: ten ready-made layouts – Standard, All tiles, Developer (three tabs), Minimal, Getting started, Users &
  security, Operations, Reporting, Integration & mail, Blank. Tiles a profile does not show are hidden (search and Ctrl+K still find
  them); tiles this installation does not have are left out.
- Customise → "Start from" ▾ (replaces Reset): a profile replaces the page being edited; a blue strip says what was loaded and has
  Undo; nothing is saved until Done, Cancel goes back to the saved layout. "Keep my own tiles" (on by default) moves own tiles into a
  group "My tiles"; Standard with the switch off is what Reset did.
- ▾ → Save as new layout…: new first field "Based on" – the current layout (a copy, as before) or a profile, with its description and
  numbers and "Take my own tiles along"; the name follows the profile until one is typed. The current layout stays unchanged.
- The Customise hint names "Start from"; docs and tutorial updated. e2e test home_profiles.

## 0.8.384 – Installer: nubuilder4.sql of any 4.x version
- Installer: new choice "nubuilder4.sql of a nuBuilder 4.x version" (MySQL / MariaDB) – upload the file (.sql, .sql.gz or .zip)
  or copy it into the folder _install/ by FTP / SFTP and pick it there.
- The file is checked before it runs and again right before the import: it may only create, fill and change nuBuilder's own
  tables (zzzzsys_…) – other tables, other databases, users and rights, files (INTO OUTFILE / LOAD DATA / LOAD_FILE), triggers,
  routines, sub-queries in VALUES and a DEFINER are refused. The uploaded file is removed after the installation.
- A file of an older version (tested with 4.5.1, 4.5.8, 4.6, 4.7, 4.8.1) is brought to the structure of nuBuilder 4.9, as nuBuilder's
  own update does: missing tables from install/nubuilder49_tables.sql, nuBuilder's column list (nuAlterSystemTables) adapted to 4.9's
  own nubuilder4.sql – sal_group VARCHAR(80), set_include TEXT, zzzzsys_email_log keeps DATETIME, the DROP of
  zzzzsys_prompt_generator.sph_template and the pdf_temp columns only where they exist – the *_json columns, 4.5's views replaced.
  A 4.9 file stays as it is; a second run changes nothing. The same upgrade runs after "nuBuilder's complete system" from the
  nuBuilder folder.
- Tests: dev/tests/nub49/upgrade_check.php (each 4.x dump → same structure as 4.9), dev/tests/install.js (the wizard in the browser
  with an uploaded dump, a refused one, sign-in afterwards), unit tests of the file check.

## 0.8.383 – nuBuilder 4.9 compatibility: form JavaScript
- Scripts in HTML objects run as in nuBuilder (compatibility report M1): after jQuery and the nu… functions are loaded, in page
  order and before the form's own JavaScript – `$` and functions they define work again. Other script types stay untouched.
- `nuAfterSave()` / `nuAfterSaveGlobal()` run on the reloaded form after `nuOnLoad` (M7) – their messages and changes stay, and
  `nuRecordId()` is the saved record. Save & Close: `nuCloseAfterSaveGlobal()` returning false keeps the form open.
- `nuFORM` with nuBuilder's methods (M8): getProperty, setProperty, subform, subforms, data, calc, scrollList, breadcrumbs,
  getTables … (no table schema in the browser); `nuSERVERRESPONSE` with the form's properties (N1).
- Hooks (N2): `nuFormatValueCleared` / `nuFormatValueClearedGlobal` – a date field that does not hold a real date is cleared when
  it is left, as in nuBuilder; sub-form filters call `nuSubformFilterOnAddValue(s)` and `nuSubformOnFilterRows`; the flags
  `nuBrowseNoData` / `nuBrowseNoSearchResults` are set.
- More nuBuilder functions (N3): `nuAddActionCheckbox`, `nuGetStartingTab`, `nuSubformFilterArray`, `nuSubformFilterAddObject`,
  `nuDatalistShowAllOnArrowClick`, `nuSearchablePopupUtils`.
- Values set while the form loads (e.g. `nuSetValue` in `nuOnLoad`) no longer turn Save red or ask when leaving (N4); they are
  still saved with the next save.
- Compatibility check: the findings above are marked as supported; N2 and N3 are also found in the application's code.
- Start page: the Customise hint no longer says the start page is the same for every globeadmin – Done saves the layout chosen
  under Layouts.
- Test form tjs (dev/tests/nub49/build_app3.php) and e2e test nub49_js; docs/porting-review.md updated.

## 0.8.382 – nuBuilder 4.9 compatibility: browse lists and access conditions
- Browse lists built the nuBuilder way (compatibility report C1–C6): the form's browse SQL stays as written – table-qualified
  columns, aliases, ORDER BY by alias or position, tables without primary key, duplicate column names and hash variables in
  column expressions all work; search, filters and sorting run on the result columns. UNION / WITH queries are wrapped as before.
- `nu_filter_match` in browse SQL (MySQL and SQL Server), `nuAddBrowseTitleSelect()` and title filters with `multiSelect`
  (several values, checkbox menu).
- Access 9 (access condition, `sob_all_access_condition`): the SELECT is run with the record's hash variables when the form is
  shown and again when it is saved – hidden and read-only fields are never taken from the browser. The form builder offers
  "Condition (SQL)" with the condition field.
- Compatibility check: new card "nuBuilder 4.9 features" – which findings of the compatibility report occur
  in your application and since which version nuBuilder Next supports them.
- Test application of the compatibility report in dev/tests/nub49 and e2e test nub49_app; docs/porting-review.md lists the
  changes.

## 0.8.381 – Update page: folder list
- Update page → Packages in the folder _update: the list is at most 450 px wide and "Check package" sits below it instead of
  next to it.

## 0.8.380 – Existing nuBuilder databases blocked for now
- Existing nuBuilder databases (while nuBuilder Next is being tested): the installer refuses a database that already holds
  nuBuilder's tables ("choose a new, empty database"). An installation set up by hand on an existing nuBuilder database shows
  only a notice with Sign out. Installations made before this version keep working; the globeadmin sees a warning on the start
  page and in the Security check. A new database step 0014 notes where the database came from (setting db_origin: new database
  / installed before / existing nuBuilder database).
- README.md: the alpha warning now also says that new versions may break things and that compatibility with earlier versions
  or existing nuBuilder applications is not guaranteed; requirements and the installer step in the Help say "new, empty database".
- e2e test existing_db.

## 0.8.379 – Start page hooks (JavaScript)
- Start page hooks, part 2 (JavaScript): the JavaScript of the procedure nunext_home (when "JavaScript active" is on) runs on
  the tile start page. The page allows only scripts from files, so it is served as ?p=homejs&v=<hash> (login and a tile page
  user needed, cached until the code changes) and loaded after the page's own script; safe mode and $nuConfigHomeHooks = false
  skip it.
- window.nuHome: on(event, fn) / off – events tile (replayed for the tiles already drawn, called again for tiles added later),
  ready, search (query, shown tiles), tab, favourites, customize; tiles(filter), tile(key) and data (the values of
  nuHomeSetData()). A tile object has key, el, source (builtin, own, pinned, dynamic), kind (form, page, procedure, url, post),
  label, hint, icon, color, badge, group, tab, size, fav, href and setBadge(), setHint(), hide(), show() (respected by the search
  and "Favourites only") and onClick(fn) (return false cancels the tile's action). Every callback runs in try/catch: the error
  goes to the console and – once – to a message for the globeadmin; the other callbacks still run.
- Every tile of the start page carries data-nu-source, data-nu-kind, data-nu-group, data-nu-tab, data-nu-label and
  data-nu-desc.
- Help: "JavaScript: nuHome" in the chapter Start page hooks; e2e test home_hook_js (API, replay, ready, search, hidden tile not
  found, onClick cancels, error once, Customise event, safe mode, file refused for normal users); home_hook_off also checks
  the JavaScript.

## 0.8.378 – Start page hooks (PHP)
- Start page hooks, part 1 (PHP): a procedure with the reserved code nunext_home runs every time the tile start page
  (globeadmin, admin roles) is built and changes only that page view – nuHomeSetBadge($key, $text, $color), nuHomeSetHint,
  nuHomeHideTile, nuHomeAddGroup($id, $title, $tab) and nuHomeAddTile($key, $tile) (keys x_…; a tile has exactly one target –
  form + record, page, procedure + run, url + newtab – and is checked like an own tile of the tile editor), nuHomeBanner($text,
  $level), nuHomeSetData($name, $value) (for JavaScript, next step) and nuHomeCache($seconds). Hash variables of the user plus
  #ADMIN_ROLE#, #HOME_LAYOUT#, #HOME_SIZE#, #HOME_DISPLAY#. Dynamic tiles are drawn like all other tiles (search, compact
  tooltips, every size), are not part of Customise and cannot be favourites; every text is escaped.
- A failing hook (error, exception, refused tile or key) leaves the page without its changes; the globeadmin sees the message
  with a link to the procedure, admin roles see nothing; errors and echo output go to Debug results. Off: $nuConfigHomeHooks =
  false (nuconfig.php or the new Settings row "Start Page Hooks", database step 0013); safe mode for one page view:
  ?p=home&tiles=1&safe=1 (globeadmin). An active hook is listed as information in the Security check and in System status.
  The procedure editor suggests the code nunext_home; draft / archived procedures do not run.
- nuRunQuery() results also offer nuBuilder's PDOStatement methods fetch(), fetchColumn(), fetchObject(), fetchAll(),
  rowCount() and columnCount() (nuBuilder returns a PDOStatement).
- Help: chapter "Start page hooks"; tutorial lesson "Start page hooks"; self-test check; unit tests and e2e tests home_hook
  (badge, hint, hidden, dynamic group / tiles, banner, data, echo in Debug results, search, not in Customise, safe mode, refused
  tile, exception, admin role) and home_hook_off ($nuConfigHomeHooks = false).
- README.md: quick links with full GitHub addresses (releases, changelog), the nuBuilder forum and the documentation.

## 0.8.377 – GitHub check answers as a toast
- Update page → "Look for a new version": the answer is shown as a toast at the top right and the page no longer jumps down
  to the GitHub section. Four answers: a newer version is available (green), up to date, the installed version is newer than
  the newest release on GitHub (e.g. a version not yet published there), no release found / GitHub error (red, stays longer).
  Saving the GitHub settings answers with a toast too.
- The badge next to the newest release says "installed" or "older than installed" instead of "installed or older".
- Flash messages can be toasts for every page: nu_flash(type, message, true) (core/nulayout.php, assets/js/nuapp.js).

## 0.8.376 – Update page: GitHub first
- Update page: the card "Install a new version" shows "Update from GitHub" first (in a frame, "Look for a new version" is the
  main button while nothing newer is known). "Other ways to update" – upload a zip, packages in the folder _update – is folded
  below it; this browser remembers whether it was open (localStorage), and it opens by itself when a signed package waits in
  _update. New texts: hint of the card, "Other ways to update", "Upload a zip".
- Collapses with data-nu-remember-open="key" remember their state for every page (assets/js/nuapp.js); a chevron toggle
  (.nuChevToggle) turns when open.
- e2e test update_layout (order, folded at first, state remembered).
- README.md: the alpha warning now says not to use nuBuilder Next on an existing nuBuilder database for now – only on a new,
  empty database.

## 0.8.375 – README for GitHub
- README.md: new front page for GitHub – one-line summary, quick links (releases, changelog, documentation, development,
  nuBuilder home page and forum), overview, "Why nuBuilder Next?" and the core features for everyone, for building
  applications and for administrators; requirements, install, updating and licence as before. Updating also mentions the
  download from GitHub (0.8.374).

## 0.8.374 – Update from GitHub
- Update page: new section "Update from GitHub". It looks for the newest release of the repository (default
  nuBuilder/nuBuilder-Next, public repositories only) with nunext.zip attached and shows version, date, size, a pre-release badge
  and a link to the release notes. "Download x.y.z and check" downloads the zip into the folder _update/ and continues with the
  usual check page; a download without a valid signature is deleted at once – the signature, not GitHub, decides what is installed.
  Downloads only from GitHub's own addresses (https), at most 64 MB, size compared with GitHub's.
- Settings of the section: repository (owner/name), "Pre-releases count" (default on – nuBuilder Next is alpha) and "Look once a
  day" (default on: cron.php, or the start page / update page of a user who may update, asks GitHub once a day – nobody else waits for it). A newer version shows a green "New: x.y.z" badge on the Update tile
  of the start page.
- Unattended: php cron.php update --github [--dry-run …] and POST api.php/v1/system/update {"github": true} install the newest
  release (an error when it is not newer, unless reinstall).
- Without curl PHP's own HTTPS functions are used. GitHub allows 60 requests per hour per server address; the error is shown
  in the section.
- Tests: unit tests for the release choice, allowed hosts and repository names; e2e test github_update (badge, download button,
  settings; GITHUB=1 also asks GitHub).

## 0.8.373 – Short README, CHANGELOG.md, alpha note
- README.md is short now (about 90 lines, the front page on GitHub): a big alpha warning on top ("not ready for production"),
  what nuBuilder Next is, requirements, install, updating (also from the folder _update/), compatibility notes and the licence.
- The changelog moved to CHANGELOG.md (newest first); the old "0.2–0.7 additions" sections are at its end under "Earlier
  versions (0.2–0.7)". dev/release.py writes new sections there and keeps the version in the README title.
- LICENSE (GPL-3.0, like nuBuilder) is part of the package.
- CHANGELOG.md and LICENSE are protected like README.md: Apache .htaccess, the nginx rules (Security check, Help) and the
  update's file list.
- Help → Updating by hand: the outdated hint to delete old files listed in the README is replaced by a pointer to CHANGELOG.md.

## 0.8.372 – Update from the folder _update
- Update page: packages copied by FTP into <nuBuilder Next>/_update/ are listed next to the file chooser (name, version, size, date,
  signature) and can be installed from there instead of uploading – useful for big packages or a small upload limit. Only packages
  with a valid signature can be chosen (unsigned, broken or foreign zips are listed but not offered); the newest is preselected,
  same or older versions are marked "reinstall". Check package and Install work as with an upload; after a successful install the
  zip is deleted from the folder. The page creates the folder with a deny-all .htaccess; updates never touch it. Logged as
  "Update package taken from the folder _update".
- e2e test update_folder (needs SITE; with PKG also the signed / unsigned case).

## 0.8.371 – Users list on phones: Import and Print in a menu
- Users list on phones (below 576 px): Import and Print are entries of a ⋮ menu right of the Add button instead of two more
  button rows; on larger screens the buttons stay as they were.
- List pages: option 'more' of NuListView::page() for such buttons (shown as buttons from 576 px on, in the ⋮ menu with Print on
  phones) – usable for other lists.
- e2e test users_more_menu (phone and desktop).

## 0.8.370 – nuSetSelect2 and Select2 calls of form code
- nuSetSelect2(id, obj) works (it was a placeholder): makes a select searchable with Tom Select, as nuBuilder does with Select2 – the
  options of nuOnSetSelect2Options are taken over, obj {top, left, width} places the box on forms with absolute layout, the return
  value is the box id (id_select2). nuSelect2(id) (older code): the same.
- Form code that uses Select2 directly: $('#x').select2({…}) builds Tom Select with the Select2 options it knows (placeholder,
  allowClear, maximumSelectionLength, minimumResultsForSearch, tags, data, multiple); .select2('open' | 'close' | 'focus' | 'isOpen'
  | 'data' | 'enable' | 'disable' | 'destroy') work on it. Values set by code ($('#x').val(v).trigger('change')) are taken over.
- Help (JavaScript hooks) and docs/porting-review.md updated; e2e test select2_api.

## 0.8.369 – nuScroll: arrow keys and suggestions on phones
- Input type nuScroll works now (it was a plain text field): the arrow keys ↑ / ↓ step through the list of the object's Scroll
  property (nuBuilder's nuFORM.scrollList) and start over at the end; text that is not in the list can still be typed. A value that
  is not in the list: ↓ starts with the first entry, ↑ with the last (nuBuilder skipped the first entry on the first ↓).
- Phones: the same list as suggestions (<datalist>) – tap the field and choose; on desktops a small arrow in the field opens it.
  One list per field, shared by the rows of a sub-form. Tooltip with the hint (all languages).
- e2e test nuscroll (keys, wrap-around, own text, save, phone).

## 0.8.368 – Accessibility, PHPStan level 3
- Accessibility (dev/tests/a11y.js: axe-core, WCAG 2.1 A/AA, ~40 pages in light, dark and at phone width) – no critical / serious
  findings left:
  - text contrast 4.5:1: grey outline buttons, "+ Add", links on the grey page, the active tab and the red Save a shade darker
    in light mode; red / green texts, red outline buttons, list headings lighter in dark mode;
  - CodeMirror in dark mode: a dark palette for every editor (form code, procedures, builder, nuconfig.php) – it kept its light
    colours on the dark page; line numbers readable;
  - names for screen readers: sub-form cells (column title), lookup code / description, selects with search (Tom Select), the
    code editor's input, the update page's file field, the logo link in dark mode, pager buttons and status icons;
  - boxes that scroll (wide tables, code blocks, the update history) can be reached with the keyboard;
  - phones: help tables scroll inside their box, the update page's file field fits the screen.
- PHPStan raised to level 3 (return types, array shapes): outdated type comments fixed; levels 4 and 5 tried – only false alarms.
- dev/release.py: NUNEXT_TEST_A11Y=1 runs the accessibility scan before a release.

## 0.8.367 – Security review of the newer parts
- Security review of the parts added after 0.8.207 (audit trail, notifications, report links, one-time login tokens, REST API) –
  findings and what was checked in TODO.md.
- Audit trail: the history of a record shows only the columns of the form and its sub-forms that the user sees there (globeadmin and
  the audit right: all) – before, admin-only / hidden fields and columns of other forms on the same table were shown. Restore is
  refused for a field the user does not see or may not change, and for a record his list does not show.
- Notifications: a link other than http(s)://…, index.php… or ?… is not sent to the bell, the page or the e-mail (defence in depth for
  rows written past the form).
- e2e tests audit_hidden_field and notification_link (39 browser tests).

## 0.8.366 – Large data, indexes, browser cache
- Browser cache (Lighthouse "Use efficient cache lifetimes"): assets/.htaccess lets browsers keep scripts, stylesheets and pictures
  for a year without asking again (Cache-Control: public, max-age=31536000, immutable) – every page loads them with ?v=<file time>,
  so a changed file gets a new address; files without a version (fonts, pictures of a stylesheet) one week. Only with Apache's
  mod_headers / mod_expires (tested on Apache 2.4 with and without them); nginx: rule in Help → Security → Web server rules.
- Sub-form link columns get an index: NuDDL::ensureIndex() – the form builder adds it when a sub-form object is saved, the sample
  application on install / refresh (sample_order_item, sample_order, sample_all_line). Without it every record with a sub-form read
  the whole child table.
- Form builder: saving an object keeps its JavaScript event rows when they did not change (they were deleted and inserted again).
- Large data test (dev/tests/load.js): 500 users, 100,000 customers and orders (300,000 items), 100,000 audit rows, 1,000
  notifications, 200,000 log rows; ~25 pages and the bell timed against budgets, MariaDB's slow / no-index queries listed. Result:
  every page within 330 ms, one bell check 8 ms. dev/release.py runs it with NUNEXT_TEST_LOAD=1.
- e2e test subform_index.

## 0.8.365 – More automatic tests
- User form: "Create login link" (one-time login link, 0.8.339) did nothing unless $nuConfigAutoLogin was on – its form was only
  rendered together with the auto-login form. Found by the new test.
- Browser tests (dev/tests/e2e.js) from 23 to 37: nuBuilder form events (Before Save and Before Delete stop with nuDisplayError,
  After Save, Before Edit with nuAddJavascript, a lookup's After Browse with #ROW# and nuSetFormValue), lookup by code, an object's
  JavaScript event, a hidden procedure, permission items (nuUserHasPermission in Before Save), admin roles (view only, 403 elsewhere),
  demo mode ("Try the demo", nothing saved – needs SITE), one-time login link (works once), user CSV import (the new user logs in),
  branding, dashboards (data for the dashboard's access levels only), login rate limit.
- Cross-tests (dev/tests/compat.js): a password set in nuBuilder Next works in nuBuilder, a login link of nuBuilder Next logs in to
  nuBuilder.

## 0.8.364 – Cross-tests against nuBuilder
- Cross-tests against nuBuilder itself (dev/tests/compat.js, development package): real nuBuilder 4.9 and nuBuilder Next on the same
  database. For every application form: browse list (same records, same cells), field values of the first records (main form and
  sub-form rows), and a save without a change must leave the rows as nuBuilder leaves them. Sample application: the same typed input as
  a new record in both gives the same row, sub-form rows added / deleted across both, reports run as PDF in both. nuBuilder Next's
  editors (user, access level, procedure, SQL builder, report designer, permission item, e-mail template, form builder): a save without
  a change leaves nuBuilder's rows exactly as they were and nuBuilder still opens them. Accepted differences with their reason:
  dev/tests/compat-known.json. Runs against a copy of a real nuBuilder database too.
- Fixed with it (differences to nuBuilder):
  - new records: fields not touched and empty are not written (column default / NULL, as nuBuilder – was '' / '0' / #000000 / the
    first option of a select); new sub-form rows the same; the HTML editor field as nuBuilder ('');
  - saves in the editors no longer turn '' into NULL in nuBuilder's tables (NuDB::update writes only changed columns there);
  - text from multi-line fields is saved with LF line ends as in nuBuilder (browsers post CRLF);
  - SQL builder: the SQL text is rebuilt only after a change in the builder; clause rows keep their ids;
  - report designer: values keep their JSON type (border width / font size as text, as nuBuilder's designer);
  - e-mail templates: nuBuilder's template flag is no longer set to '0' on save;
  - negative numbers: the minus after the prefix as nuBuilder (€ -99,95, USD -500.00).
- dev/release.py: with NUNEXT_TEST_BASE / NUNEXT_TEST_SITE / NUNEXT_TEST_NUB set it runs the browser suites (smoke, e2e, compat) and
  stops the release when a test fails.

## 0.8.363 – Start page: list in 2 columns
- Start page (globeadmin): new tile layout "List in 2 columns" – in Customise → Tiles and in the ▾ next to Customise. The list rows
  use the full width in two columns, filled row by row in the order of the group (dragging in Customise works the same way); below
  768 px one column as before. Size key 'list2' (NuHome::SIZES), CSS .nuHomeList2.
- End-to-end test home_list2; Help: start page section.

## 0.8.362 – PHP2Shell warning
- Security check: the PHP version is a red point while PHP lacks the fix for PHP2Shell (CVE-2026-109618 / CVE-2026-109619, remote
  code execution without login, 21.10.2026) – fixed in PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12; PHP 8.1 and older get no official fix
  and are told to move to 8.2.35 or later. The text names the release to update to and notes that distributions / hosts can backport
  the fix without changing the version number (NuSecurity::PHP_FIXED, phpFixNeeded(), phpFixNote()).
- Self-test: the check "PHP and extensions" warns with the same text; the installer's server check shows it next to the PHP version.
- Minimum PHP version for install / update stays 8.1 (open decision in TODO.md).
- Unit tests for the fixed-release table; Help: Security check and Self-test sections.

## 0.8.361 – Self-test and browser tests
- Self-test (Admin tools → Self-test, ?p=selftest): only for the globeadmin in developer mode ($nuConfigDevMode = true). Checks the
  installation in five groups – system (PHP, writable folders, own tables, database steps, nuBuilder's table structure, languages,
  Security check, e-mail), data (sample application, browse list, system lists, report PDF), forms (save, required / unique, change,
  sub-form rows, hash variables, audit trail and restore, delete), rights (access levels, admin roles) and features (notification,
  housekeeping) – with status, details and duration. The form checks write into the sample application and remove their records again.
- Browser tests for the development (dev/tests/e2e.js, not in the install package): 22 end-to-end tests – login, forms, sub-forms,
  validation, clone, delete, search, rights of access levels, audit trail and restore, notifications, list views, start page favourites,
  CSV import, update package check, the self-test, CSRF, include guards, logout. The suite saves the database and puts it back.
- The smoke test also opens the audit trail, notifications and self-test pages.
- Help: section "Self-test (developer mode)".

## 0.8.360 – Developer notes for a new session
- Developer notes for a new session: CLAUDE.md – checklist for every request (start, texts, tests, test database, docs, release, check,
  delivery), what a new feature usually touches, test server command, known sample passwords, test database discipline (state at 0.8.360),
  more test gotchas. README – title and structure with the audit trail, notification centre and update preview.
- New developer tools (only in nunext-dev.zip): dev/inspect.php checks a package like the update page (signature, files, errors);
  dev/lang_add.py adds new texts to all eleven language files from one JSON file.

## 0.8.359 – Documentation checked
- Documentation checked after 0.8.350–0.8.358: help – the log section points to the audit trail, the Support admin role includes the audit
  trail (new roles), admin roles may have "Notifications", REST API table with /v1/notifications; CLAUDE.md – own tables and settings rows
  (audit, notify, favourites), "Where things stand" 0.8.359.

## 0.8.358 – Notification levels in their colour
- Notifications form: the chosen level is shown light in its own colour (info blue, success green, warning yellow, important red) instead of
  the dark grey button; the others stay white. Also in dark mode.

## 0.8.357 – Notification centre
- Notification centre: a bell in the top bar for every signed-in user – red badge with the number of unread notifications, checked every
  60 seconds (not in background tabs; the check does not keep an idle session alive), the bell rings and a short message appears for new ones.
- Bell panel: the latest ten (unread highlighted, level icon, time), mark read / unread, hide, open the link, "Mark all as read",
  "Show all" → My notifications (all / unread / hidden).
- "Must confirm": shown as a dialog on the next page until "Got it"; the time is recorded.
- Notifications → Manage: create, edit, copy, publish (now or at a time), withdraw, delete; title, text (links become clickable, no HTML),
  level, link, expiry, pinned, must confirm, also by e-mail. Recipients combined: everybody, single users, departments, teams, positions,
  access levels – with the live number and names. "Whoever matches at the time" or "Fixed list when published" (exact read receipts).
- Read receipts per notification: who read / confirmed / hid it and when. Housekeeping: e-mails of scheduled notifications, notifications
  deleted after 180 days (setting).
- Who may send: the globeadmin, the admin role right "Notifications" = change, the permission items NUNEXT_NOTIFY (anyone) and
  NUNEXT_NOTIFY_OWN (own department / team only) – both created by the update.
- PHP nuNextNotify([...]) for procedures and form events; REST API POST /v1/notifications, GET / DELETE /v1/notifications/{id}
  (scope notifications:write). New tables zzzzsys_nunext_notification and zzzzsys_nunext_notification_user (database step 0012).
- Start page tile "Notifications"; Help section "Notifications"; tutorial lesson "E-mail".

## 0.8.356 – Tile tooltip after 800 ms
- Compact start page tiles: the tooltip with name and description appears only when the mouse rests 800 ms on a tile – every movement
  starts the wait again, so moving across the tiles shows nothing (before: 350 ms). The long tap on phones stays at 500 ms.

## 0.8.355 – Start page: tile descriptions as tooltip
- Start page: the tile size "Large, description on (i)" of 0.8.352 is removed again; a layout that used it shows "Large with description".
- Compact tiles: name and description as a tooltip – on desktop when the mouse rests on a tile, on phones and tablets with a long tap
  (the tile does not open then, no copy / link menu; a tap elsewhere closes it). The browser's own title tooltip is gone.
- Help: section "Start page".

## 0.8.354 – Customise: favourites
- Customise on the start page: favourite tiles show a ★ in the corner (in the lists next to the name); the tools with ☆ / ★ still appear
  when pointing at a tile.
- Customise: button "Favourites" in the bar shows only the favourite tiles and their groups while editing (starts as on the start page,
  not saved; greyed out without favourites).
- Help: section "Start page".

## 0.8.353 – Start page favourites
- Start page favourites: in Customise, ☆ on a tile marks it as a favourite (★ = favourite, click again to remove); stored with the layout.
- Button "Favourites" next to the start page search: shows only the favourite tiles – groups and tabs without one are hidden, a search
  still finds all tiles. Kept per login; greyed out while the layout has no favourites.
- Help: section "Start page".

## 0.8.352 – Start page: tiles with description on (i)
- Start page: new tile size "Large, description on (i)" (▾ next to Customize, and in Customize): large tiles without the description
  text; a click on the (i) in the top corner of a tile shows the description as a tooltip, a click elsewhere closes it. The tile itself
  still opens its page; + and ▾ buttons stay.
- Help: section "Start page".

## 0.8.351 – Audit trail: delete
- Audit trail → Delete… (globeadmin or admin role right "Audit trail" = change, not in demo mode): a dialog that combines "Only the rows of
  the current search and filters" (exactly the listed rows, all pages) with an age – any, older than 1 / 2 / 3 / 6 / 12 months or before a
  date (a save goes as a whole). Neither = the whole audit trail, confirmed by typing DELETE (checked on the server too).
- The dialog shows the number of rows and saves before deleting; "Download as CSV first" saves exactly these rows with all columns.
- Deleting asks for the password when the last confirmation is too old, runs in batches of 5000 rows and is written to the log
  ("Audit trail entries deleted" with number, filter and date).
- Help: section "Audit trail".

## 0.8.350 – Audit trail
- Audit trail (menu Log → Audit trail, new table zzzzsys_nunext_audit): every change made in the forms, one row per changed column – who (user,
  login, IP), when, action (added / changed / deleted / copied / restored), source (form, sub-form, CSV import, restore), table, record, form,
  parent record of sub-form rows, column, old and new value. One save shares a change id. Captured by nuBuilder Next itself (no database
  triggers): saving and deleting in forms with their sub-form rows, saving a clone, the CSV import. nuBuilder's own zzzzsys_ tables, _nulog
  and the audit columns are not recorded; secret-looking columns are stored as •••, values over 4000 characters are cut.
- Settings (globeadmin or admin role right "Audit trail" = change): on/off (default: $nuConfigLogRecordChanges), keep (for ever … 10 years,
  housekeeping), tables or columns not recorded. While it is on, the log no longer gets the record added / changed / deleted entries.
- The update copies the record changes of the log into the audit trail (database step 0011-audit).
- List with list views, filters (action, table, form, user, record, column, date), search, print and CSV; access via the admin role right
  "Audit trail" or rights on the pseudo form nunextaudit; tab on the log pages; start page: Log tile → Audit trail.
- Record ⋮ menu → "History of this record": all changes of the record and its sub-form rows, newest first, one block per save (rights: view
  on the form). ↺ restores an old value (only while the column still holds the newer value) or a deleted record / sub-form row (only while
  the key is free); needs save on the form, not in demo mode, recorded as "restored".
- Help: section "Audit trail"; tutorial lesson "Log and debug results".

## 0.8.349 – nuBuilder's record log (_nulog)
- nuBuilder's record log <table>_nulog (VARCHAR(1000), JSON {"added": {"user", "time"}, "edited": {"user", "time"}}) is written like nuBuilder: "added" for a new record, "edited" on every save of an existing one (a record from before the column gets "added" unknown / 0), also for sub-form rows that are added or changed and in the CSV import (edited only when the row changed); a value posted for the column is ignored. Before, nuBuilder Next left it untouched.
- Form builder → new table: switch "Add nuBuilder's record log (_nulog)" creates the column as nuBuilder's "nulog" option does.
- Record ⋮ menu: "Added by … · Last edited by …" (name and time) for tables with a _nulog column.
- Help: section "Who added / changed a record" – the _nulog column, nuBuilder Next's audit columns (created_at / created_by / updated_at / updated_by) and the change log.

## 0.8.348 – Update preview: larger column types are no change
- Update page → Database changes of this update: columns whose type in the database is larger than nuBuilder 4.9's (e.g. longtext instead of mediumtext) are no longer listed as changes – they are not changed unless ticked under "Database structure" (not recommended). They are counted in one folded line ("… column(s) have a larger type … – left as they are") with the list, and "No changes to the database" shows when nothing else changes. Same in the dry run of the unattended update ("kept").

## 0.8.347 – Compatibility check: dashboard functions
- Compatibility check: JavaScript functions of nuBuilder Next outside the form runtime count as supported – it reads every script a form page loads, plus assets/js/nuapp.js and assets/js/nudashboard.js. Before, nuNextDashboard / nuNextDashboardRefresh (used by the sample start form sample_home) were reported as missing.

## 0.8.346 – Database changes shown before an update
- Update page → Check package: new block "Database changes of this update", grouped by table – the package's database steps this database has not run yet with the tables they touch and how (new table / new column / column type / data), new tables and columns of nuBuilder Next's own tables, missing columns and other types of nuBuilder's tables (risky type changes marked) and a sample data refresh; each structure change with its SQL for this database ("SQL" link); "No changes to the database" when there is nothing. Nothing is changed by the check.
- Packages carry install/db_changes.json (written by dev/release.py: the steps with their changes from NuMigrate::CHANGES and the own table definitions); packages built before 0.8.346 show "not described" and only the structure comparison.
- Unattended update: the dry run (API "dry_run": true, php cron.php update … --dry-run) returns the same list as "database"; the command line prints it readably.
- nuBuilder Next's own tables: missing columns of existing tables are now added automatically (before: only missing tables were created).

## 0.8.345 – 13 more nuBuilder JavaScript hooks
- Form JavaScript: 13 more of nuBuilder's hooks are called, with nuBuilder's arguments and return values – nuBeforeSaveGlobal (false cancels), nuAfterSaveGlobal, nuAfterDelete, nuAfterDeleteGlobal (window.last_action = 'delete'), nuOnLookupPopulated / nuOnLookupPopulatedGlobal (id, prefix, data – for every lookup, nuBuilder only in sub-form rows), nuOnSetSaved(isSaved), nuOnMessage(messageDiv, errors), nuOnSetCalendarOptions (minDate / maxDate for the browser's date picker), nuOnRawValueFormatted(id, rawValue, format) (a returned value replaces the number), nuOnPopupOpenedGlobal(formId, recordId, filter), nuOnSetSelect2Options (placeholder, allowClear, maximumSelectionLength, minimumResultsForSearch, tags – or {tomselect: {…}} – for Tom Select), nuOnOptionsListLoadedGlobal (each time the ⋮ menu opens). A …Global hook (Setup → header) runs before the form's own, as in nuBuilder.
- Help → JavaScript functions: table of all hooks (when, arguments, return); porting-review.md: the differences of the lookup, calendar, Select2 and options list hooks.

## 0.8.344 – Pasted numbers in other notations
- Number fields (format type Number) accept typed or pasted numbers in other notations – from Excel, calculators or PDFs – and show them in their own format when the field is left: currency signs, letters, %, spaces (also no-break / thin) and apostrophes are ignored; the calculator minus (−), dashes, a trailing minus and brackets mean negative (before, the calculator minus was lost); with both "." and "," the last one is the decimal separator, several of one kind are thousands separators, a single one is the decimal separator when it is the field's own or is not followed by exactly three digits. Before, a number in the other notation silently became a wrong value (1.234,56 in a 1,000.00 field → 1.23).

## 0.8.343 – Documentation checked
- Documentation checked after 0.8.327–0.8.342: help – browse lists in the look of the system lists (0.8.337, form code keeps its ids); README – title and structure (list views, one-time login links, report API, demo data, mailer); CLAUDE.md – architecture of the new classes, own tables and settings rows, migrations 0008–0010, conventions for secrets / public token links / own settings, test gotchas, "Where things stand" 0.8.342.

## 0.8.342 – SMTP authentication method
- E-mail → Settings: new field "Authentication method" – Automatic (as before: PHPMailer takes the first method the server offers, CRAM-MD5 first) or LOGIN, PLAIN, CRAM-MD5 (PHPMailer's AuthType). For servers that offer a method and then refuse it. Stored in nuBuilder Next's own settings (zzzzsys_setup has no field for it), used for everything nuBuilder Next sends, also with the nuconfig.php SMTP settings; shown under "Active configuration" and included in Copy / Paste settings.
- E-mail → Test: with authentication the result shows the methods the server offers and the one used; when sending fails with Automatic it suggests LOGIN (or PLAIN), and it warns when the chosen method is not offered. Read from the server conversation, also when the chosen debug level shows less.

## 0.8.341 – Demo mode: SQL check, save limit, data reset, $nuConfigIsDemo
- Demo mode, security: typed SQL of demo visitors (SQL builder preview, dashboard widgets) may not mention the hidden tables anywhere in the statement – before, a table after a comma, in STRAIGHT_JOIN or in parentheses slipped past the check (e.g. `FROM sample_customer, zzzzsys_user`); also the server catalogs (mysql, sys, performance_schema) are refused. `sus_json` is shown without the login token, two-factor / OTP secrets and the auto-login epoch.
- Demo mode: saving in the allowed forms at most 10 times per hour per visitor (IP address) and 100 times per hour for all demo visitors together (log event "Saved in the demo").
- Demo data reset: System status → Demo mode → Demo data – snapshot of the tables of the allowed forms and their sub-forms, "Reset now", and automatic reset by the housekeeping (never / 6 / 12 hours / 1 / 2 / 7 days, default 1 day; cron.php: demo_reset).
- nuBuilder's `$nuConfigIsDemo` with `$nuConfigDemoDBGlobeadminUsername` / `…Password` is honoured: that login opens a read-only demo globeadmin session (also as a "Try the demo" button); the real globeadmin wins; Settings tag "Used". Developer mode is never on in a demo session.

## 0.8.340 – Reports through the REST API (PDF links, PDF, e-mail, jobs)
- REST API: reports. New scope `reports:run`; rights as in the browser (scope AND the report right of the service user's access level), data-source procedures run as in the browser.
- `GET /v1/reports` lists the reports the client may run with the hash variables they use (`params`). `POST /v1/reports/{id or code}/run` {params, filename, expires_minutes, max_downloads, url, email, async} runs one: `params` become hash variables (the service user's own variables such as USER_ID cannot be passed).
- Download link without login: `index.php?p=reportfile&t=<token>` (`&dl=1` = as attachment), valid 15 minutes and for one download by default (1–1440 minutes, 1–100 downloads), then 410. Only a hash of the token is stored; the PDF waits in the protected temp folder and is deleted after the last download or by the housekeeping (cron.php: report_files). Runs and downloads are logged.
- `Accept: application/pdf` returns the PDF itself; `"email": {to, cc, bcc, subject, body}` sends it as attachment (`"url": false` = e-mail only); `"async": true` answers 202 with a job – `GET /v1/reports/jobs/{job}` gives the status and the result (runs right after the answer on PHP-FPM, otherwise at the first status request).
- Limit of 10 report runs per minute per client (429). API playground: examples for listing, running, background run and job status.

## 0.8.339 – One-time login links (nuBuilder's LOGIN_TOKEN)
- One-time login link – nuBuilder's "Single-User Password Token": `index.php?u=<login name or user id>&p=<token>[&f=&r=]` signs a user in once when the token matches `LOGIN_TOKEN` in `sus_json`; like nuBuilder without a second factor, and the token is then replaced by a new random one. Tokens written by other systems (as in nuBuilder's wiki, at least 20 characters) work unchanged; optional `LOGIN_TOKEN_EXPIRES` (Unix time, nuBuilder Next only).
- Safer than nuBuilder: exact comparison in constant time (no LIKE), the token is replaced in one conditional UPDATE (two clicks at the same moment cannot both log in), never the globeadmin or API service users, expiry date and access level apply, failures count for the login rate limit, every use is logged (Login, via token). The token is removed from the address bar at once; a link opened while somebody else is logged in asks first.
- User form (globeadmin): "One-time login link" – validity 1 hour / 1 day / 7 days / 30 days / no expiry, create (link shown once, copy), create and e-mail (in the user's language), remove token.
- PHP: `nuCreateLoginToken($userId, $minutes = 0, $formId = '', $recordId = '')` returns the link. REST API: `POST /v1/users/{id}/login-link` and `DELETE` with the new scope `users:login_link` (API playground example included).
- Security check: new setting "Accept nuBuilder's one-time login tokens" (on by default); info point with the number of users that have a token (plain text, as in nuBuilder) and "Remove all tokens".

## 0.8.338 – Longer password confirmation in developer mode
- Developer mode (`$nuConfigDevMode = true`): the globeadmin is asked to confirm the password for dangerous actions (update, backups, nuconfig.php, settings, App cloner, API clients …) much less often. New Security setting "Ask for the password again in developer mode (minutes)", default 480 = 8 hours, 0 = never ask; without developer mode "Ask for the password again after (minutes)" applies as before.
- Security check: info point "Developer mode" while `$nuConfigDevMode` is on, with the current confirmation interval – developer mode is not meant for a live system.
- Security settings: the number fields line up even when a label wraps.

## 0.8.337 – Browse lists look like the system lists
- Browse lists of forms look like the system lists: the search field sits inside the list card with the search button on the right (350 px, as on Users or Forms), and the table uses the same header (uppercase labels), row height and padding.
- The search button of a browse list has the nuBuilder id `nuSearchButton`; `nuSearchField`, `nuBrowseTable`, `nuHideSearchField()` and `nuDisableSearchField()` keep working.

## 0.8.336 – Honour $nuUseMd5PasswordHash
- nuBuilder's $nuUseMd5PasswordHash is honoured (nuconfig.php or Settings): with true, new passwords are stored as md5 and never converted to bcrypt – as nuBuilder does, which then accepts md5 only. This applies to the user form, CSV import, password page, reset / welcome link and the REST API. md5 logins then work even when "Accept old md5 passwords" is off.
- Security check: red point "md5 password storage" while the setting is on (insecure – switch it off once no nuBuilder needs it); old md5 passwords are then reported as kept. Settings: the tag of $nuUseMd5PasswordHash is now "Used".
- Switched off again (default): md5 passwords become bcrypt at the next login, as before.

## 0.8.335 – Keep session alive: not needed
- Settings: $nuConfigKeepSessionAlive and $nuConfigKeepSessionAliveInterval are tagged "Not needed" (before: "Not yet") – a session ends only after the inactivity timeout ($nuConfigSessionTimeout / $nuConfigSessionTimeoutGlobeadmin), every click, save or background request counts and PHP keeps sessions at least that long; for long data entry without saving, raise the timeout.

## 0.8.334 – List views for all system lists
- List views for the other system lists: Forms, Procedures, Reports, Files, Items, Translations, Permission items, Prompt generator, Log and E-mail log – View button, Default / Compact / All columns, own and shared views (also for chosen access levels), default view, editor with columns, phone cards and filters, export / import, Print and CSV with the view's columns. Default views show the lists as before.
- More columns and filters: forms (table filter, ID), procedures (category, template, run by everyone), reports (launch form), items (category, language, form, sort order), permission items (has users), prompts (template, prompt text), log and e-mail log (period: last 7 / 30 / 90 days; e-mail log: tag, sender, error).
- Files: display Tiles (the thumbnails as before, default) or Table with a small preview column.
- Filters can now also be text fields and from / to dates (log, e-mail log). Translations use ?lv= for the view (?view= is the tab "Missing").
- Debug results keep their own message list (select, delete, auto refresh).

## 0.8.333 – Start page: saved layouts
- Start page: saved layouts. The ▾ next to Customise lists the layouts and switches at once; "Save as new layout…" copies the current one – personal, or (with the right "Start page layout") shared for every admin or only for chosen admin roles. "Name and visibility…", "Delete layout", "Set as default for everyone".
- Every admin may save and customise own layouts (also without the right "Start page layout"); shared layouts are changed only with that right. Reset puts the default tiles into the current layout.
- The layout of earlier versions becomes the shared layout "Standard" and the default for everyone (database step 0010). Stored like the list views (zzzzsys_nunext_list_view, list "home").

## 0.8.332 – Start page: no version badges
- Start page: the Update tile no longer shows the installed version (still in the user menu → "nuBuilder Next 0.8.x · What's new" and on the Update page).
- Start page: the Sample data tile no longer shows the installed sample version; the yellow badge "update available" stays when a newer sample set comes with nuBuilder Next.

## 0.8.331 – Fix: mixed collations in the users list
- Fix: Users list with columns or filters from nuBuilder Next's own tables (last login, authenticator app, online now, admin role – e.g. the view "All columns") failed with "Illegal mix of collations" (MySQL error 1267) when nuBuilder's tables and nuBuilder Next's tables use different collations (utf8mb4_general_ci / utf8mb4_unicode_ci). Such comparisons now go through NuDB::sameText() (MySQL: compared as bytes, SQL Server: COLLATE DATABASE_DEFAULT).
- The same fix for the user list of an admin role and the API clients list (service user and its access level).

## 0.8.330 – User import template
- User import (Users → Import…): new button "Download template" next to "Load configuration" – a CSV file with the column titles of the user import in the user's language (header row only, separator $nuConfigCSVSeparator or ;, UTF-8 with BOM for Excel). Fill it in with Excel, save as CSV and upload it: "Map automatically" now also recognises these titles, so every column is mapped at once.

## 0.8.329 – List views for users and access levels
- New: list views for the Users and Access levels lists – the View button in the title bar switches between Default (the list as before), Compact, All columns, shared views and own views.
- A view sets the columns and their order, width (auto / narrow / wide) and alignment, sort, rows per page, the filters offered with their default values and whether they start open – and separately the phone: below 768 px every row is a card (title, badge, up to 4 lines).
- Editor (New view… / Edit view…): tabs Columns (drag or arrows), Phone (with preview), Filters, General.
- Personal views for everyone who may open the list, with "Set as my default". Shared views (globeadmin or the new admin right "List views"): for everyone or only for chosen access levels; "Set as default for everyone"; "Share a copy with everyone" for personal views; export / import as JSON.
- More columns for users: first / last / full name, department, team, status, valid until, language, additional 1/2, last login, authenticator app, admin role, online now, password changed, ID; more filters: department, team, language, last login, valid until, authenticator app, online, admin role. Access levels: procedures, reports, 2FA, ID; filters 2FA and "has users".
- Print and Export CSV follow the view's columns and order. Old links (access, pos, state, group …) keep working.
- Built to be extended: column / filter catalogues in core/nulistdefs.php, engine in core/nulistview.php, table zzzzsys_nunext_list_view (database step 0009).

## 0.8.328 – Tutorial for the globeadmin
- New: Tutorial (start page tile in System, user menu, Ctrl+K; index.php?p=tutorial) – a step-by-step introduction for the globeadmin in 36 lessons: Basics (start page, sample data, forms and lists, users, access levels, permission items, acting as a user, admin roles), Building applications (forms and builder, object types, code and hash variables, procedures, reports, dashboards, e-mail, branding, import / export) and Administration (configuration, settings, security, logs, updates and backups, system status, cron, App cloner, REST API, demo mode, migrating a nuBuilder application).
- Each lesson: explanation, links that open the page it is about (or the documentation chapter) in a new tab, and a "Try it" exercise, mostly with the sample data. Progress per person ("Done – next lesson", "Start again"); the tile shows it.
- Start page note "New to nuBuilder Next?" until hidden or finished; shown again right after the setup wizard, whose last page links to the tutorial.
- Users with an admin role see the tutorial with the lessons of their rights. The text is English (docs/nunext-tutorial.htm), the page around it in all languages. No pictures yet.

## 0.8.327 – User import, searchable access level
- Users list: new button "Import…" – opens the CSV import in a new tab with the target "Users (zzzzsys_user)". Available to whoever may add users (globeadmin, access level, admin role "Users"); users with only that right see no other target.
- User import runs the checks of the user form: login required and unique, full name (or first + last name), e-mail, access level by code column or one level for all rows (no admin-equivalent levels when restricted), language (de, de-CH, en-US, German …), valid until. Existing logins are skipped and listed.
- Passwords: optional column, stored as a hash with "change at first login"; never shown in the preview. Rows without a password get a random one and – optional – the welcome e-mail with the link to set their own.
- The check of the user import writes nothing (zzzzsys_user is often MyISAM); "all or nothing" writes no user while a row has a problem. Every created user is logged.
- User form: the access level is searchable (Tom Select, code with the description below).

## 0.8.326 – Admin roles: group headings
- Admin roles, rights table: the groups (System, Data, Development …) are clearly headings now – a grey band with an icon and normal text instead of small grey capitals like the column titles; the rights below are indented.

## 0.8.325 – Start page search placeholder
- Start page search field: placeholder just "Search…" (the hint "(/)" for the keyboard shortcut is gone; / still jumps into the field).

## 0.8.324 – Top bar menus inside the screen on phones
- Phones: the menus of the collapsed top bar (user menu, language, theme) stay inside the screen – the user menu ran off the right edge because Bootstrap places these menus at the button's left edge; they are shifted in and limited to the screen width.

## 0.8.323 – Admin roles
- Admin roles: other users can get a defined part of the globeadmin's rights in nuBuilder Next. A role is a set of rights, one per tool (Update, nuconfig.php, Settings, Security check, Setup wizard, System status, Database, File Manager, CSV import, App cloner, Sample data, forms, procedures, reports, dashboards, prompt generator, users, access levels, logged-in users, act as another user, Admin roles, Branding, start page layout, e-mail, API clients, change log, debug results, compatibility check), each "view" or "change". Start page → Admin roles; the role of a user is chosen on the user form (field "Admin role").
- Five default roles, editable and copyable: Full administrator, Administrator without system, Developer, User manager, Support (read only). Rights that lead to full access in the end (PHP on the server, the whole database, the web folder, passwords) carry a red "full access" badge.
- A user with a role sees the start page tiles, menu entries, Ctrl+K results and admin help of his rights (plus his normal forms); every page checks the right on the server (403 otherwise); view-only pages say so and switch their buttons off. Rights on nuBuilder's system forms are added to the user's access level. nuBuilder itself does not know the roles.
- Managed by the globeadmin and users with the right "Admin roles"; nobody gives rights he does not have, changes his own role or acts as another admin. Log and Security check name the users with admin roles. Migration step 0008 creates the tables zzzzsys_nunext_admin_role / _admin_user with the default roles.

## 0.8.322 – Upgrade baseline 0.8.321
- Upgrade baseline 0.8.321: nuBuilder Next has not been released yet and every installation runs 0.8.321, so the upgrade paths from earlier nuBuilder Next versions are removed – renaming of the nunext_* tables (0.6 and older), the start page layout conversion of 0.8.316, migration step 0006 (step 0001 creates every own table), the list of 78 files removed in earlier versions, deleting the tool cookies of before 0.8.264, sessions without installation id, replacing old .htaccess texts, the welcome e-mail template code nu_welcome_email (before 0.8.100).
- Kept on purpose (nuBuilder compatibility, not upgrade code): migration steps 0002–0005 for older nuBuilder databases, md5 passwords of nuBuilder before 4.5, auto-login links without expiry.
- Removed the texts of the former Admin tools view menu (Full / Compact) in all languages; new migration step 0007 deletes its setting row vendor_layout.

## 0.8.321 – Pins, start page search and Ctrl+K
- Start page (globeadmin): pin any entry of a tile's ▾ menu (Forms → Formats, E-mail → E-mail log, API clients → API playground …) – it becomes a tile of its own right after its tile; a second click takes it off again. Customize → Add tile offers "Page of nuBuilder Next": every tile, page and action that is not on the start page yet.
- Start page search: a field above the tiles (/ jumps into it) filters the tiles as you type, in the current language and in English; "More results" lists matching pages and actions of the ▾ menus, own tiles, forms, procedures and reports. Enter opens the first hit, Esc clears. On wider screens Customize sits next to it.
- Ctrl+K (or the magnifier in the top bar) on every page, globeadmin only: search box for pages, actions, own tiles, forms, procedures and reports, grouped; ↑ ↓ Enter, Ctrl+Enter = new tab; the entries opened last come first (kept in the browser). List from ?p=palette (core/nupalette.php).

## 0.8.320 – Customize at the top right on wider screens
- Start page (globeadmin): on wider screens (from 768 px) Customize and its ▾ sit at the top right above the tiles, the menu opens downwards; on phones they stay at the bottom left.

## 0.8.319 – Reset in the start page edit bar
- Start page, Customise mode: Reset (back to the default groups and tiles – asks first, deletes own tiles) sits in the edit bar now, red, next to Add tile – before it was a small link at the end of the page. Below 1400 px only its icon shows.

## 0.8.318 – Top bar menus above the edit bar
- Start page, Customise mode: the menus of the top bar (language, theme, user) open above the edit bar again, and when scrolling the edit bar stops below the top bar instead of sliding behind it (height of the top bar as CSS variable --nu-nav-h).

## 0.8.317 – Start page list rows aligned
- Start page, sizes List and Wide list: the Database tile (with ▾) lines up with the other rows – its icon and text sat too far left; no double line between a File Manager row and its neighbours.

## 0.8.316 – Admin tools on the start page
- Admin tools merged into the globeadmin's start page: Update, Security check, Settings, nuconfig.php, Setup wizard, System status, Branding, Dashboards, Database, File Manager, CSV import, App cloner, Sample data, API clients, Prompt generator and Compatibility check are tiles now, in the groups System, Look, Data and Integration – customizable like every tile (order, groups, tabs, size, hide, rename). With display Tabs they sit in their own tab "Admin tools".
- Start pages customized before: these groups are added once at the end (only tools not placed yet – nothing moves; a group name already used becomes "Admin tools").
- Badges on tiles: installed version (Update), sample data version / update available, and how many points of System status need a look.
- The Admin tools page (?p=vendor) is now "System status": time zone warning, missing database tool / File Manager, storage engine (InnoDB) and demo mode. Old links still work; the user menu entry is "System status".
- The tools' Back buttons and breadcrumbs lead to the start page; Dashboards got "+" (new dashboard) on its tile.

## 0.8.315 – Deutsch DE / CH
- German in two versions: Deutsch (DE) and Deutsch (CH) in the language menu, the user form and the setup wizard. lang/de.php is German German now (ß – außerhalb, Größe, schließen – and „quotes“); lang/de-CH.php holds the Swiss texts (ss, «quotes») and is generated at every release by dev/lang_variants.php (ß → ss, „“ → «»).
- The page language and the number / date formats of nuBuilder Next's own pages follow the choice (de-DE: 1.234,50 · de-CH: 1'234.50); nuBuilder's formats (zzzzsys_format) stay as they are. nuBuilder keeps "German"; the version is stored in sus_json (NUNEXT_LANG_VARIANT), as for English.
- Without a choice: the user's version, one picked on the sign-in page, the installation's default (setup wizard), else the browser's – de-CH → Deutsch (CH), every other German (plain "de", de-DE, de-AT …) → Deutsch (DE).

## 0.8.314 – English UK / Australia / US
- English in three versions: English (UK), English (Australia) and English (US) in the language menu, the user form and the setup wizard. lang/en.php is British English now (colour, customise, cancelled); lang/en-US.php holds only the texts spelled differently and is generated at every release from a word list with exceptions (dev/lang_variants.php); lang/en-AU.php is a hand-kept overlay (empty for now – Australia spells like the UK).
- The page language and the formats of nuBuilder Next's own pages follow the choice (en-GB / en-AU / en-US); nuBuilder's formats (zzzzsys_format) stay as they are.
- nuBuilder compatibility: sus_language stays "English"; the version is kept in sus_json (NUNEXT_LANG_VARIANT). Without a choice of the user: one picked on the sign-in page, else the installation's default (setup wizard), else the browser's (en-US, en-CA, plain "en" → US; en-AU, en-NZ → Australia; other English → UK). E-mails use the recipient's version.
- Start page (globeadmin): Customize and ▾ at the bottom left.

## 0.8.313 – Access level form without ID
- Access level edit form: the "ID …" text next to the buttons is gone.

## 0.8.312 – Setup wizard, underline tabs
- Setup wizard: right after the globeadmin's sign-in, nuBuilder Next asks for its own settings in five steps – also when nuconfig.php already exists (e.g. nuBuilder Next added to a running nuBuilder). Basics: default language (nuBuilder's Setup → Language), time zone, address, session timeouts (users / globeadmin), nuBuilder folder, shared login. Look & tools: application title, environment label (TEST / DEV), primary database tool, REST API. Security: new globeadmin password (signs out), 2FA for the globeadmin with e-mail, HTTPS check, cron line to copy. Sample data: install or refresh. Summary with old → new values; nothing changes before Apply.
- When it opens: once per sign-in until finished or "Skip, do not show again"; both write $nuConfigShowInstaller = false to nuconfig.php (and note it in the database – enough when nuconfig.php is read-only). $nuConfigShowInstaller = true shows it again; Admin tools → Setup wizard opens it any time. After a new installation the wizard follows the first sign-in.
- Settings are written to nuconfig.php in one save (backup, check); a second assignment on the same line is now found too. With $nuConfigSettingsFromDB, values with a row in zzzzsys_config go there. Read-only nuconfig.php: the lines to add by hand are shown.
- Language: the globeadmin and users without their own language now start in nuBuilder's default language (Setup → Language), unless a language was picked on the sign-in page.
- Tabs in a clean underline look: no boxes, grey text, the selected tab in blue with a 2 px line below; icons and colours unchanged, dark mode included.
- Copy buttons: data-nu-copy="#field" (global).

## 0.8.311 – Procedures list: Category and Status before Description
- Procedures list: columns in the order Code · Group · Category · Status · Description · Run · Launch form · Access levels – also in
  print and export.

## 0.8.310 – Procedures list: Group after Code
- Procedures list: column Group right after Code (before Description) – also in print and export.

## 0.8.309 – Users list without row buttons, access levels list only Clone
- Users list: no Clone / Delete buttons in the rows any more (on any screen) – clone, archive and delete on the user form.
- Access levels list: only the Clone button in the rows (hidden below 768 px) – delete an access level on its page.

## 0.8.308 – Update page: progress window while installing
- Update page: after confirming Install, a window with a spinner shows the version being installed, the steps, and that the page
  must stay open (no reload, no closing, no Back) – it cannot be closed while the install runs; it also explains maintenance mode,
  what happens when the connection drops and the automatic restore.
- Update page on phones: the list of database steps wraps instead of making the page wider than the screen.

## 0.8.307 – Notes list: category filter
- Notes list: filter by category (below the column title Category: All, without category, or one category). A default of nuBuilder
  Next for nuBuilder's form nunotes – the form builder can switch it off, and a nuBuilder update does not remove it.

## 0.8.306 – Access levels list: Group after Code
- Access levels list: column Group right after Code (before Description) – also in print and export.

## 0.8.305 – Start page: larger Customize ▾ on touch screens
- Globeadmin start page on phones and touch screens: Customize and its ▾ are 44 px high, the ▾ is 48 px wide (was 22 px) – easy to
  hit with the thumb.

## 0.8.304 – Start page: tile-size menu fully visible on phones
- Globeadmin start page on phones: the ▾ tile-size menu of Customize (bottom of the page) is scrolled fully into view when it
  opens – before, the last options could be below the screen.

## 0.8.303 – Start page: Customize at the bottom
- Globeadmin start page: Customize (with ▾ for the tile size) moved from the top to the bottom right, below the tiles – the tiles
  start at the top of the page; the size menu opens upwards.

## 0.8.302 – Lists: no row buttons on small screens
- Users and access levels lists: on small screens (below 768 px) the Clone / Delete buttons of the rows are hidden – the list keeps
  its room for the data; both are on the record page.

## 0.8.301 – Unattended update: command line and REST API
- Unattended update without a browser – the update page's steps for scripts, cron jobs and several installations:
  command line "php cron.php update --url=… | --zip=…" and REST API "POST /v1/system/update" (new scope system:update).
- Options: url (https) or zip, sha256, backup auto|files|sql|sql_nologs, schema none|missing|safe|all, sample auto|yes|no,
  reinstall, dry_run (check only: signature, requirements, file changes, database differences, sample data, backup mode).
- Only signed packages; $nuConfigEnableDatabaseUpdate = false blocks it; one update at a time; jobs in _backup/update-job-*.json.
  The database steps run with the new code: the command line starts a new PHP process, the API on GET /v1/system/update/{job};
  pending jobs are also finished by cron.php and the update page. API playground: three new requests.

## 0.8.300 – Update page: update the sample data too
- Update page: "Update the sample data too (version x → y)" next to Install when the sample data is installed – ticked when the
  package brings a newer sample version. After the update, with the new code, the sample data is refreshed (as Sample data →
  Refresh, new passwords for the sample users); the result page shows the version and links to the new passwords.

## 0.8.299 – Sample data with four dashboards
- Sample data (version 14) with four dashboards: sample_sales (start page of sample_manager / sample_sales – KPIs, revenue by day,
  orders by status, top products, latest orders, date filter), sample_products (start page of every sample level – SQL, the SQL
  builder query sample_orders and the new PHP procedure sample_dashboard_data with nuDashboardData), sample_customer (customer form,
  new tab Statistics, #RECORD_ID# and #cus_name#) and sample_country (sample start form: the field home_country filters,
  nuNextDashboardRefresh()). SQL for MySQL and SQL Server; the sample page offers "Refresh".
- Dashboards: charts on a hidden tab are drawn when the tab is opened; axis labels without the text before / after and decimals
  from 100 up (the tooltip shows the full value).

## 0.8.298 – Dashboards: KPI tiles, charts and tables on the start page and on forms
- New: Admin tools → Dashboards (globeadmin) – KPI tiles (value, change ▲▼ against a previous value, colour by thresholds),
  ApexCharts charts (columns, bars, line, area, pie, donut, radial; one series per column) and small tables.
- Data from SQL (one SELECT), a saved SQL builder query or a PHP procedure (nuDashboardData($rows)); always read-only (rolled-back
  READ ONLY transaction, 10 s limit), cached per user (refresh button, optional auto-refresh).
- On the start page of users per access level, and anywhere on any launch or edit form: HTML object
  <div data-nu-dashboard="CODE"></div> (empty in nuBuilder) or nuNextDashboard('CODE', 'element_id'); nuNextDashboardRefresh().
- Hash variables: the user's, #DATE_FROM# / #DATE_TO# (date filter with ranges and custom dates, remembered per browser), on forms
  #RECORD_ID# and the saved record's field values from the database (escaped); also in widget titles. New record: "available after
  saving". Access: form and record rights, the dashboard's access levels, placement checked on the server.
- Editor with Test (preview + rows before saving), live preview of the dashboard, snippet and "used in" list; dark mode, accent
  colour, phones. Strict CSP kept: ApexCharts' CSS as a file, style attributes and a per-page style nonce only on dashboard pages.
- New tables zzzzsys_nunext_dashboard / zzzzsys_nunext_widget (migration 0006). All texts in 11 languages, help page section.

## 0.8.297 – Branding: logo, accent colour, environment label
- New: Admin tools → Branding (globeadmin) – own logo and optional logo for dark mode (PNG, JPG, WebP, GIF, SVG up to 512 KB),
  stored in nuBuilder's zzzzsys_file (codes nunext_logo / nunext_logo_dark: part of backups and App cloner copies); shown in the
  top bar and on the sign-in pages, optionally as browser icon; nuBuilder Next's logo comes back when the file is deleted.
- Title ($nuConfigTitle) next to the logo in the top bar (optional).
- Own accent colour: buttons, links, switches, tabs, focus rings – via a generated, cached stylesheet (strict CSP); lighter in dark
  mode, links darkened automatically when they would be hard to read, black button text for very light colours (with a warning).
- Environment label (e.g. TEST) with its own colour: badge in the top bar and on the sign-in pages, a coloured line at the top,
  [TEST] in the browser tab title. $nuConfigEnvironmentLabel / $nuConfigEnvironmentColor in nuconfig.php take priority (the label
  belongs to the server, not to the copied database).
- Live preview, Reset; SVG logos are served with a sandbox policy. All texts in 11 languages, help page section "Branding".

## 0.8.296 – Docs: backup dump
- Help: the backup section says that the SQL dump is now taken in one consistent snapshot with TIMESTAMP values in UTC and
  size-limited INSERT statements (same code as the App cloner, 0.8.295).
- Developer notes (CLAUDE.md, not in the install package): "Where things stand" brought up to date.

## 0.8.295 – App cloner: snapshot, streaming, size-limited batches, UTC
- App cloner (MySQL / MariaDB), ideas from mysqldump (no code taken over): all tables are read in one consistent snapshot
  (START TRANSACTION WITH CONSISTENT SNAPSHOT) – a record saved during the clone is completely in the copy or not at all.
- Each table is streamed with one unbuffered SELECT instead of LIMIT/OFFSET pages (large tables no longer get slower towards the end).
- INSERT batches are limited by size as well (about 1 MB, at most half of the target's max_allowed_packet): tables with large BLOBs
  no longer break the clone or the import; a single row that is too large is a warning and the clone goes on.
- TIMESTAMP values are read and written in UTC (time_zone '+00:00'): no shift when the servers run in different time zones.
- Faster: the rows of a table are written in one transaction; the SQL file wraps each table in SET autocommit = 0 … COMMIT and
  DISABLE / ENABLE KEYS. The target connection uses native prepares (binary values are no longer escaped to twice their size).
- Also for the SQL dump in backups (same code).

## 0.8.294 – More room below subforms
Forms in Auto / Grid layout: the row after a subform or an iframe gets more room above it – the labels of the next row sat right on the subform box (e.g. Sample: orders, "Quantity" and "Total (after save)" below the items). Sample data (version 13): in Sample: orders also in Absolute layout Quantity / Total and Note moved down a little; Admin tools → Sample data offers "Refresh".

## 0.8.293 – Sample: tabs Run, Subform, Charts, Miscellaneous
Sample data (version 12): form "Sample: all objects" – the tab "Run & subform" is split into "Run" and "Subform" (the subform label no longer sits over the iframe / first subform row), and "New features" into "Charts" (the three charts at the top) and "Miscellaneous" (tooltip icons, info bar, button spinner). Admin tools → Sample data offers "Refresh".

## 0.8.292 – TODO: rated ideas for what could come next
TODO.md: new section "Ideas – what could come next" – 36 suggestions rated 1–10 (10 = must have in a modern business-app framework), grouped into must have / important / useful / nice to have, with effort estimates and a suggested order; checked against what nuBuilder Next already has. Readable in the app under Admin tools → To do (developer mode, TODO.md copied into the installation folder).

## 0.8.291 – App cloner: verified 1:1 copy, 6 fixes
App cloner verified for a 1:1 copy (MySQL / MariaDB): a test database with every object kind – all column types (binary, BIT, geometry, JSON, generated, zero dates, emoji, per-column collations), tables without primary key and with more rows than one copy page, partitions, MyISAM, foreign keys with CASCADE / SET NULL, CHECK constraints, unique / prefix / descending / ignored / fulltext / spatial indexes, a MariaDB sequence, chained views, a view calling a function, functions and procedures with their own sql_mode, triggers with FOLLOWS, events – cloned directly and as SQL file, then compared object by object and byte by byte: now 0 differences (also on a server in strict sql_mode, with "Empty it first", and for the full nuBuilder test database). Fixed on the way:
- A view that calls a stored function stopped the whole clone ("FUNCTION … does not exist"): views are now created after functions and procedures.
- BIT columns were copied wrong (b'10101010' became 0xFF, 0 became 0x30): read and written as numbers now.
- Tables or rows a strict server refuses (zero dates, old ENUM definitions) failed: the copy runs with sql_mode NO_AUTO_VALUE_ON_ZERO only, as mysqldump does (direct copy and SQL file).
- MariaDB sequences lost their settings and current value: CREATE SEQUENCE as in the source + SETVAL; "Empty it first" also drops sequences.
- Functions, procedures, triggers and events are created with their own sql_mode (events also with their time zone), as mysqldump does; triggers in their execution order (a trigger with FOLLOWS failed before when sorted by name).
- Copy pages are ordered by the primary key of every table (before only nuBuilder's <table>_id); geometry columns go into the SQL file as binary.
- An object that cannot be created is now a warning in the log and the rest is still copied (before, the first error stopped the clone).

## 0.8.290 – Adminer designs from third_party/adminer
Adminer designs: adminer.css (and adminer-dark.css of Adminer 5 / 6) placed next to adminer.php in third_party/adminer/ are now applied. Adminer only looks for them in the folder it runs from (through dbadmin.php the nuBuilder Next folder) and links them relative to dbadmin.php, while third_party/adminer/ is blocked for the browser – dbadmin.php now lets Adminer find them there and serves them itself (dbadmin.php?nu_css=…, only these two file names). Tested with Adminer 4.8.1, 5.5.0 and 6.1.2.

## 0.8.289 – Adminer 6: offline cache of its service worker removed
Adminer 6 in Chrome: page without styling after a normal reload (fine after a hard reload, fine in Edge). Adminer 6 installs a service worker that keeps its files (default.css, functions.js …) in the browser's Cache Storage by version – Chrome had stored the files of the damaged adminer.php there, and a hard reload or "Empty cache" does not clear that. dbadmin.php now serves its own worker.js instead of Adminer's: it deletes Adminer's caches ("adminer-…") and caches nothing (the browser's normal cache still holds Adminer's files). Browsers that have Adminer's worker get the new one with their next visit.

## 0.8.288 – Adminer: stale cached scripts are refreshed
Adminer: scripts that a browser kept from a damaged or older adminer.php (e.g. functions.js before the 0.8.287 repair – "ReferenceError: partial … is not defined") are sent again. Adminer gives its files a one-year, "immutable" cache and answers every If-Modified-Since with 304 regardless of the file; dbadmin.php now lets Adminer send them again when the browser's copy is older than the Adminer file in use (repaired copy or a newly copied adminer.php). A browser that does not ask at all still needs one reload with Ctrl+F5.

## 0.8.287 – Adminer: copies with Windows line endings repaired
Adminer showed numbers instead of its texts ("44: nunext", "303" …) with "ReferenceError: partial … is not defined" when adminer.php had been copied with Windows line endings (FTP in text mode, a Windows editor, git autocrlf): Adminer 6 packs its texts with line breaks, which no longer unpack. dbadmin.php now notices such a copy and uses a repaired one (line endings restored, written once as .nunext-lf-<size>-<time>.php next to it, renewed when the file changes); the Security check reports the converted file. The official Adminer files contain no "\r", so the repair restores them exactly.

## 0.8.286 – Adminer: no PHP warnings in its pages
Adminer: "ReferenceError: partial / ajaxSetHtml / tableCheck … is not defined" on servers with display_errors on fixed. Adminer 6 changes session settings with ini_set() without @; because dbadmin.php has already started Adminer's session, PHP printed a warning into the page and into Adminer's background requests (JSON), which broke its scripts. dbadmin.php now switches off display_errors for Adminer – PHP messages still go to the server's error log. This also keeps Adminer 4's PHP 8 notices out of the page. Tested with display_errors on: Adminer 4.8.1, 5.5.0 and 6.1.2.

## 0.8.285 – Adminer 6 manifest fixed
Adminer 6: "Manifest: Line 1, column 1, Syntax error" fixed – Adminer 6 loads a web app manifest (dbadmin.php?manifest=), which dbadmin.php redirected to the database page (HTML). The automatic login and the jump to a table now only happen for the plain start (dbadmin.php or ?nu_table=…, GET); every other request goes to Adminer unchanged.
Security headers: "bluetooth" removed from Permissions-Policy – Chrome does not know it and logged "Unrecognized feature: 'bluetooth'" on every page.

## 0.8.284 – Database menu: star makes a tool the default
Database tile (start page) and Database card (Admin tools): in the ▾ menu a star next to each tool – filled = the default, a click on the empty star makes that tool the default. Saved as $nuConfigPrimaryDatabaseManagement in nuconfig.php (or in the Settings table when $nuConfigSettingsFromDB is on); the Settings row is updated in both cases. Asks for the password when the last confirmation is older than 15 minutes, as the other admin tool actions.

## 0.8.283 – Adminer 6: automatic login fixed
Adminer 6.x: the automatic login of dbadmin.php works again – Adminer 6 asks for a form token at login (verifyLoginToken()), which the login sent by nuBuilder Next does not have, so Adminer showed its login form. Tested with Adminer 4.8.1, 5.4.1, 5.5.0, 6.0.0 and 6.1.2.

## 0.8.282 – Adminer as database tool, primary tool setting
Adminer as database tool besides / instead of phpMyAdmin (not part of the package): copy adminer.php or adminer-<version>.php (5.x recommended, 4.8.1 works) to third_party/adminer/. It never opens directly (deny-all .htaccess; the Security check probes it) but through the new dbadmin.php: it checks the same session row as phpMyAdmin (cookie nu_ADM only for dbadmin.php, globeadmin, activity within the session timeout) and logs Adminer in with the database account of nuconfig.php – no password typed, no other server or account, no permanent login; Adminer's Logout ends the access. Works with MySQL / MariaDB and SQL Server (SQL Server untested).
$nuConfigPrimaryDatabaseManagement = "phpMyAdmin" | "Adminer" (nuconfig.php or Settings – new row, update step 0005; commented line in nuconfig-sample.php): the tool the Database buttons open – start page tile, Admin tools card (new), form ⋮ menu / Ctrl+Shift+E (opens the form's table). Not set: phpMyAdmin when installed, else Adminer. With both installed, ▾ on the tile and the card and the user menu offer the other one. nuVendorLogin('ADM') / nuVendorLogin('DB') in form code. Admin tools: one card explains where to copy phpMyAdmin or Adminer while neither is there. Security check: Adminer version (4.x reported as old).

## 0.8.281 – $nuConfigEnableDatabaseUpdate = false switches updates off
$nuConfigEnableDatabaseUpdate (nuBuilder's setting, in nuconfig.php or zzzzsys_config with $nuConfigSettingsFromDB): set to false, the Update page shows a notice and allows no upload / install of a package, no rollback and no "Apply" of the database structure – the buttons are hidden and the actions are also refused on the server. Backups, downloads and deleting backups still work; the automatic database steps of the installed version still run. Not set or true = as before. The setting is now tagged "Used" on the Settings page.

## 0.8.280 – Settings: tag filter as buttons with counts
Settings: the filter by the nuBuilder Next tag is now a row of buttons with counts – All · Used · Not yet · Not needed – below the search (before: a third drop-down that was easy to miss); the chosen one takes the colour of its tag.

## 0.8.279 – Settings: tags used / not yet / not needed in nuBuilder Next
Settings (zzzzsys_config): every nuBuilder setting has a tag after its title showing what it means for nuBuilder Next – Used (8: read by nuBuilder Next), Not yet (18: not built yet, only nuBuilder uses it for now) or Not needed (27: nuBuilder Next has its own solution or it is always on). Hover / tap the tag for the reason. The switch "only settings nuBuilder Next uses" became a filter by these three; a legend is in the footer. $nuConfigGlobeadminHome is now marked as used (it already was). Developer: the list is NuSettings::STATUS – move a setting to "next" when it is implemented.

## 0.8.278 – Update page: nuBuilder-only tables marked as not needed
Update page, Database structure: nuBuilder tables that only nuBuilder uses (zzzzsys_info, zzzzsys_report_data, zzzzsys_timezone) are listed separately as "not needed by nuBuilder Next", with a note that nuBuilder Next works without them and nuBuilder creates them itself when installed – nothing to do. Other missing tables are still listed as before.

## 0.8.277 – Backups: space before the SQL badge
Update page, Backups: more space between the reason and the SQL badge (SQL size / SQL removed / incomplete).

## 0.8.276 – Saved settings for Cloner and App cloner (nuBuilder tables)
App cloner: saved configurations, as in nuBuilder. Admin tools → App cloner opens a list of configurations (search, Add); a configuration has a code and a description and keeps target database, login (without the password), contents, tables, files and the modes; Save, Clone and Delete as on other pages. They are stored in nuBuilder's table zzzzsys_app_cloner – nuBuilder's App cloner opens the same rows, and the other way round (database / file mode "create" = add / copy over, cla_opt_1–9 = the parts, chosen tables as JSON). The target password is never written; one saved by nuBuilder is used when the field stays empty. Download SQL, Dry run and Run work with the values on the page, saved or not.
Cloner (copy a form): saved settings in nuBuilder's table zzzzsys_cloner – list, Add, Save, Clone, Delete. Source form, destination, tabs, subforms and iframe forms (stored as nuBuilder does: the forms they show), with / without objects, SQL dump, keep keys and notes; access rights and REPLACE INTO in clo_json. The copy button of a form in the Forms list opens new settings for that form.
Update step 0004-cloner-tables creates zzzzsys_cloner and zzzzsys_app_cloner with nuBuilder's structure when they are missing; the installer creates them too.

## 0.8.275 – Translation table created by an update step
New update step 0003-translate-table: creates nuBuilder's translation table zzzzsys_translate when it is missing. New installations already create it; installations made before it was added to the installer got it only when the first translation was saved, so the update page listed it as a missing table.

## 0.8.274 – Test mail: required field marked
- E-mail → Test mail: the required field "To" is marked with a red * (the subject falls back to the default text when empty,
  the diagnosis always has a value).

## 0.8.273 – Fix: Update page on servers with disabled functions
- Fix: the Update page ended with "Call to undefined function disk_total_space()" on servers that switch that function off
  (disable_functions). The disk space row now shows "?" there.
- The same protection for other functions hosts often switch off: set_time_limit, ignore_user_abort, ini_get / ini_set,
  get_current_user (new helpers nu_time_limit, nu_ignore_abort, nu_ini_get, nu_ini_set) – tested with these functions disabled.

## 0.8.272 – Update: free disk space in GB / TB
- Update → Requirements: free disk space in readable units with the total and share, e.g. "1.8 TB free of 1.9 TB (92 %)"
  instead of "1878838 MB". Sizes elsewhere (backups, files, SQL dumps) also switch to GB / TB when they get that large.

## 0.8.271 – Logged-in users laid out like Debug and Log
- Log → Logged-in users: laid out like Debug and Log – the tabs first, then the card with the title (i), Back · Refresh and the list.
  "Log out all others" / "Log out everyone" sit at the right end of the button row (on phones below it), the nuBuilder Next session
  count above the table.

## 0.8.270 – Reports list: row buttons on the left
- Reports list: the report designer and Run (PDF) buttons are in the first column, left of the code (as in the Forms, Access
  levels and Users lists). Look and colours unchanged.

## 0.8.269 – Update nuBuilder Next renamed to Update
- "Update nuBuilder Next" is now just "Update" (page title, start page tile, Admin tools, menus) in all 11 languages; Help updated.

## 0.8.268 – Title bars like the user form
- Title bars like the user form on all pages: title (with subtitle), then a row of their own with Back and the page's buttons in
  the same style (nuBtnAct, normal size) – Logged-in users (Back · Refresh), Security check (Back · Save), Inspect (Back · Copy as
  JSON · Refresh), Update, TODO, E-mail, E-mail templates, Help (Back · view), Compatibility check (Back · switches), Admin tools
  (Back · View), CSV import (Back · steps), API test console (hint as subtitle). Before, Back sat next to the title.

## 0.8.267 – Fix: Print on lists
- Fix: Print on the lists (Users, Access levels, Forms, Procedures, Log, Debug, Reports, Items, Files, Prompts, Permissions,
  Translations, E-mail templates, Formats) opened the same list again in a new tab instead of the print view – a broken link since
  0.8.262. The browser smoke test now checks the print link.

## 0.8.266 – Tabs in nuBuilder's look
- Tabs everywhere (form tabs, Log / Debug / Logged-in users, E-mail, items, procedures, start page …) in nuBuilder's look
  (.nuTab / .nuTabSelected): 28 px high, 13 px Segoe UI, #ddd border with 5 px rounded top corners, the background of the surface
  they sit on, hover #f5f5f5; the selected tab has a 2 px blue top line, semibold black text and opens into the content below.
  Dark mode: the same shapes with dark colours.

## 0.8.265 – Help: hardened phpMyAdmin config
- Help → Admin tools: the hardened phpMyAdmin config.inc.php explained (age check, strict globeadmin check, no database details,
  blowfish_secret, no nuConfigDBPasswordBlank cookie, no version check / error reports, optional $nuPmaNunextOnly). The file itself
  is delivered separately (third_party is not part of the package).

## 0.8.264 – Tool cookies only for the tool folder, SameSite=Strict
- phpMyAdmin / Tiny File Manager login cookies (nu_PMA, nu_TFM, nu_timezone) are only sent to the tool's own folder (e.g.
  /nunext/third_party/tinyfilemanager/ – or the path of $nuConfigPMAURL / $nuConfigTFMURL) instead of the whole domain, and use
  SameSite=Strict (still HttpOnly, Secure on HTTPS). The cookies of earlier versions (path /) are removed on the next launch.
- Help → Admin tools: the cookie and a hardened Tiny File Manager config.php explained (age check, exact user list, no echoed
  values or database details, valid time zone, no demo accounts, no online viewer, optional $nuTfmNunextOnly). The config.php
  itself is delivered separately (third_party is not part of the package).

## 0.8.263 – Start page: edit directly on the page (drag and drop, touch)
- Start page (globeadmin): Customize now edits the page itself instead of opening a separate editor. The tiles stay where they are
  (in the chosen size) and get small tools; a sticky bar holds display, tile size, Add group, Add tile, Cancel and Done.
- Drag and drop with the mouse and on touch screens (hold a tile for a moment, then drag – a quick swipe still scrolls): tiles
  within a group, into another group or into Hidden; groups by their handle; tabs along the tab bar. Moving a tile or group over
  a tab title opens that tab. The page scrolls by itself near the top / bottom edge; Esc cancels a drag.
- In place: group names and the name of the open tab are typed directly; arrows on tiles, groups and tabs as an alternative to
  dragging; eye = hide / show, pencil and bin on own tiles, + after the tabs adds a tab, × deletes the open tab.
- Done saves everything at once, Cancel discards the changes (asks first); leaving the page with unsaved changes asks too.
  Reset moved to the end of the page.
- Texts in all 11 languages; Help → Start page of the globeadmin updated.

## 0.8.262 – Debug: buttons in one style, auto refresh on the right
- Log → Debug: Back, Refresh and Print are next to each other in the same style (height, colour) – also "Delete all". The switch
  "Refresh every 5 s" is at the right end of the button row; on phones it gets its own row below the buttons.

## 0.8.261 – Permission items: group filter always shown
- User form → Permission items: the group selection is always shown (it was hidden when all items are in one group).

## 0.8.260 – User form: permission items filtered by group
- User form → Permission items: a group selection next to the search (shown when there is more than one group). It combines
  with the search; All and None then tick / untick only the items of that group (the others keep their state).
- Texts in all 11 languages; Help → Users updated.

## 0.8.259 – Backups: clearer roll back buttons
- Update page → Backups: download, roll back and delete are one row of equal buttons (same height, same border style as the
  buttons in the other lists). "Roll back" has dark text on white with an amber icon (good contrast) and turns light amber on
  hover; download and delete are bordered icon buttons. Light and dark mode.

## 0.8.258 – Users list: row buttons on the left
- Users list: the clone and remove buttons are in the first column, left of the name (as in the Forms and Access levels lists).
  Look and colours unchanged; without the rights the column is not shown.

## 0.8.257 – Access levels list: row buttons on the left
- Access levels list: the clone and delete buttons are in the first column, left of the code (as in the Forms list). Look and
  colours unchanged; without clone / delete rights the column is not shown.

## 0.8.256 – Back button on every page
- Back (←) on every page of nuBuilder Next: the lists (Users, Access levels, Forms, Procedures, Reports, SQL builder, Items,
  Files, Translations, Prompts, Formats, Permissions, Log, Debug, Logged-in users, E-mail, API clients …) and the admin pages
  (Admin tools, Update, Settings, nuconfig.php, Security check, Compatibility check, CSV import, App cloner, Cloner, API test
  console, TODO, Help, Security, Change password).
- It is the first button of the page's toolbar (or right after the title when there is no toolbar) and goes one step up: to
  the page above it in the breadcrumb (e.g. Settings → Admin tools, Procedures → Forms, Permissions → Users), otherwise to the
  start page. Inspect returns to its record. Not shown when a password change is required.
- Help → Navigation updated.

## 0.8.255 – Back button on form pages and the remaining edit pages
- Form pages: Back (←) is the first toolbar button – from a record to its list, from a list to where it was opened (the Forms list,
  the start page or another form). It follows the breadcrumb and also calls nuOnBeforeGetBreadcrumb(). Not in iframes, lookup
  windows and print view.
- Forms list: forms opened from it show "Start / Forms / <form>" in the breadcrumb, so Back returns to the list (with its search).
- Back also added to: form builder (to the Forms list), files, items, prompts (AI) and translations (back to the list or to
  "missing translations"). The record buttons there and the Back of e-mail templates / e-mail log have the normal size.
- Help → Navigation updated.

## 0.8.254 – Logged-in users: log out all users
- Log → Logged-in users: two new buttons above the list – "Log out all others" (every nuBuilder Next session except your own)
  and "Log out everyone (incl. me)" (also your own; you go to the login page and sign in again). Both ask first and are logged
  ("All sessions ended", with the number of sessions). nuBuilder's own sessions are not touched.
- Texts in all 11 languages; Help → Logged-in users updated.

## 0.8.253 – Forms list: row buttons on the left
- Forms list: the form designer and cloner buttons are in the first column, left of the description (they were at the right end
  of the row). Look and colours unchanged. Users without the designer right do not get the empty column.

## 0.8.252 – Form pages: toolbar and search like the other lists
- Form pages: the toolbar buttons have the same size and style as on the other lists (Forms, Users …) – above the list (Add, Print,
  form designer, ⋮) and in a record (Save, Clone, New, Delete, layout switch), also buttons added with nuAddActionButton().
- The search field above a form's list has the same height and width (350 px) as on the other lists.

## 0.8.251 – Lists: first column without link colour
- Lists (forms, users, procedures, reports, access levels …): the first column (the record's name) is no longer blue – it uses the
  normal text colour, stays bold and is underlined on hover. Light and dark mode.

## 0.8.250 – New form: narrower Type, Layout and table switch
- Forms → New form: the Type and Layout selections are narrower (one third of the card on wide screens instead of half), and the
  "Existing table / New table" switch is only as wide as its two buttons. On phones everything stays full width.

## 0.8.249 – New form: breadcrumb lined up
- Forms → New form: the "Forms" link above the title lines up with the title and the card (it was at the far left of the window).
  All other pages were checked at 1300 and 1700 px width: their links line up with the card below.

## 0.8.248 – $nuConfigTitle in browser tab and login; sessions hint as (i)
- $nuConfigTitle (nuBuilder's setting, in nuconfig.php or Setup → Settings) is used: it replaces "nuBuilder Next" in the browser
  tab ("Forms · <title>") and is shown above "Sign in" on the login page. Without it everything stays as before.
  Settings marks it as used by nuBuilder Next. No new variable in nuconfig-sample.php.
- Log → Logged-in users: the timeout text ("Sessions with activity in the last … minutes …") is now behind an (i) next to the title.
- Help updated (Settings, Logged-in users).

## 0.8.247 – Tabs: renamed or deleted by the globeadmin only
- Form builder → Tabs: only the globeadmin can rename or delete existing tabs. Other users with rights on forms see the titles
  greyed and without the remove button; they can still add tabs (and name the new ones), change the order and the visibility.
- The server checks it too: a tab change that renames or removes an existing tab is refused, and so is an Undo / History restore
  that would rename or remove tabs (for the globeadmin both work as before).
- Texts in all 11 languages; Help → Forms and the form builder updated.

## 0.8.246 – Delete form: globeadmin only
- Form builder: only the globeadmin can delete a form (with its objects, tabs, list columns, events, PHP hooks and access rights).
  Other users with rights on forms no longer see "Delete form"; the server refuses the request as well.
- Texts in all 11 languages; Help → Forms and the form builder updated.

## 0.8.245 – Delete form – optionally with its table (type the name to confirm)
- Form builder → Delete form opens a dialog. The globeadmin can tick "Also delete the table <name> (<n> records)"; then the
  table name must be typed exactly (case matters, pasting is blocked) before "Delete form and table" works. The server checks
  the name again.
- The table is only offered when it is a real table (no view, no zzzzsys table) and no other form uses it – otherwise the
  dialog says why it is kept. Deleting the table also needs a recent password confirmation (link in the dialog, back to the
  dialog afterwards).
- The form is deleted first, then the table; if the table cannot be dropped, a message says so. Log events "Form deleted" and
  "Table deleted" (with the number of records).
- Texts in all 11 languages; Help → Forms and the form builder updated.

## 0.8.244 – Bootstrap JS through one helper (nuBs) – preparation for Bootstrap 6
- Bootstrap's JavaScript is used through one small helper, assets/js/nubs.js (nuBs.modal / tooltip / dropdown / tab / toast /
  collapse / ready). All 13 page scripts and the form runtime use it instead of calling bootstrap.* directly, so a later switch to
  Bootstrap 6 changes one file. Nothing changes on screen.
- window.bootstrap stays available for nuBuilder form code; form code may also use nuBs (Help → JavaScript functions).
- Bootstrap 6 (alpha) was evaluated: nuBuilder Next stays on Bootstrap 5.3.8 for now (TODO.md).

## 0.8.243 – tooltips: only one open at a time
- (i) tooltips everywhere: only one is open at a time – tapping / clicking another (i) closes the previous one, and a tap or click
  anywhere else closes it (on touch screens the tooltips stayed open before). E.g. Settings on a phone.

## 0.8.242 – settings: description behind an (i) on narrow screens
- Admin tools → Settings: below 800 px screen width the long "Description" column is hidden; an (i) next to the title shows the
  description on tap, click or hover. Wider screens are unchanged. Help → Settings updated.

## 0.8.241 – README: menu name Database
- README: the older sections name the globeadmin menu entry "Database" (since 0.8.215) instead of "Database (phpMyAdmin)".

## 0.8.240 – documentation checked (README, TODO, development notes)
- Documentation checked: README (structure lists core/nulibraries.php; "Bundled libraries" names TCPDF 6 / 7 and NUNEXT-VERSION.txt),
  TODO.md (package size ~2.4 MB; new check point for TCPDF 7 with your own reports) and the development notes (state 0.8.240,
  library overview, TCPDF 7 bundle, recent list and editor changes). Help was already up to date.

## 0.8.239 – notes / editors: editor fits the window, legend visible on small screens
- Forms (notes): the lowest editor of a tab now takes exactly the free height of the window – between 250 and 800 px, also less
  than its designed height – so the legend below the form stays visible without scrolling on smaller screens too (e.g. a window
  of about 790 px: editor about 390 px). Before it never got smaller than designed.

## 0.8.238 – notes / editors: legend below the form stays visible
- Forms (notes): the growing editor of 0.8.237 pushed the legend below the form (Required / Unique value) out of the window. It now
  grows only as far as the legend and the bottom margin still fit; the space the form reserves below the editor is no longer
  counted twice, and the editor uses its full designed height (not 300 px) when it cannot grow.

## 0.8.237 – notes / editors: TinyMCE fills the free height (max. 800 px)
- Forms: a WYSIWYG editor (TinyMCE) that is the lowest object of its tab – e.g. the notes form – fills the free height of the window
  (at most 800 px, never less than its designed height). Editors with other objects below them keep their designed height.

## 0.8.236 – users list: Position and Code columns, position filter
- Users list: new columns "Position" (after Department / Team) and "Code" (after Login name), both sortable; on narrow screens
  Position hides below 768 px and Code below 1200 px. New filter "Position" between Access level and Status – All, "(no position)"
  or one of the positions in use. Print / CSV include both columns and the position filter. Texts in all languages; Help updated.

## 0.8.235 – forms list: group filter
- Forms list: new filter "Group" between Type and Status – All, "(no group)" or one of the groups (only groups of the forms you may
  see). The Group column was already there. Print / CSV name the chosen group. Texts in all languages; Help → Lists updated.

## 0.8.234 – forms list: status column and filter (archived hidden by default)
- Forms list: new column "Status" (Active, Draft, Archived – nuBuilder's sfo_status, empty counts as Active) and a filter "Status"
  next to "Type": All except archived (default), All, Active, Draft, Archived. Archived forms are hidden unless chosen. The Print /
  CSV list includes the status. Texts in all languages; Help → Lists updated.

## 0.8.233 – report designer: title in its own row below the toolbar
- Report designer: the report's code, description and source (table / SQL) are no longer squeezed into the toolbar – they stand in a
  row of their own below it, in full length. The toolbar keeps only the buttons.

## 0.8.232 – reports: TCPDF 7 support (Composer installation in third_party/tcpdf)
- Reports: TCPDF 7 is supported. TCPDF 7 is only a facade over tc-lib-pdf (class Com\Tecnick\Pdf\Tcpdf) and needs Composer's
  vendor/autoload.php with the tc-lib-* libraries and the fonts of tc-lib-pdf-font. nuBuilder Next now finds a Composer installation
  in <nuBuilder Next>/third_party/tcpdf/ (vendor/tecnickcom/tcpdf), loads its autoloader and uses tc-lib-pdf-font's fonts.
  TCPDF 7 without its libraries or on PHP < 8.2 gives a clear message instead of "Class … not found". TCPDF 6 works as before.
  Tested with TCPDF 7.0.19 / tc-lib-pdf 8.81.1: the sample reports look the same as with TCPDF 6.
- Help → Installation (Files you copy yourself) explains TCPDF 7. Texts in all languages.

## 0.8.231 – NUNEXT-VERSION.txt for own builds of copied libraries
- Copied libraries (TCPDF, PHPMailer, TinyMCE, phpMyAdmin, Tiny File Manager): an own build or patched copy can carry a file
  NUNEXT-VERSION.txt in its folder (e.g. third_party/tinyfilemanager/). nuBuilder Next reads "version:" from it (e.g.
  2.6+git.4149143.nu1 – compared as 2.6) and lines "CVE-…: open …" / "fixed …". The Libraries card on the update page shows that
  version with a wrench icon whose tooltip shows the whole file; the Security check keeps warning about Tiny File Manager until the
  line "CVE-2025-15138:" starts with "fixed".
- NUNEXT-VERSION.txt is never served on the web: root .htaccess (Apache) and a new nginx rule in Security check / Help; the
  "Files on the web" probe tests it.
- Update page: the Libraries card is shown even without change history (it sat inside that card); folders of nuBuilder's copies
  are shown relative (../third_party/tcpdf/). Help → Installation explains the file with an example. Text in all languages.

## 0.8.230 – update page: Libraries after Change history, collapsed
- Update page: the card "Libraries" comes after "Change history" and is collapsed – its title shows the number of libraries and, in
  yellow, how many need attention (missing in the package, other version, older than the safe version). Click it to open.

## 0.8.229 – update page: library overview, library changes of a package
- Update page: new card "Libraries" – every third-party library with version, folder, use and licence, read from the files on the
  server. "In the package" (Bootstrap, Bootstrap Icons, jQuery, jquery-confirm, Tom Select, CodeMirror, js-beautify, sql-formatter,
  Prettier, @prettier/plugin-php, qrcode-generator, ApexCharts – replaced by an update) and "Copied / from nuBuilder" (TCPDF,
  PHPMailer, TinyMCE, phpMyAdmin, Tiny File Manager – not installed / older than the known safe version are marked).
- Check package: a new line lists the libraries the package changes ("Libraries that change: Tom Select 2.6.2 → 2.7.0") or
  "Libraries: unchanged" – for packages from this version on.
- New registry assets/vendor/libraries.json; dev/release.py checks that each library file shows the registered version and copies
  the list into nunext-manifest.json ("libraries"). README "Bundled libraries", Help → Update and CLAUDE.md updated.

## 0.8.228 – update page: Cancel locked while installing
- Update page: while "Installing…" runs, Cancel is locked too (before it stayed clickable). Clicking it would not have stopped the
  install – the server finishes it anyway – but the page would have left before the closing step, so the "Updated from … to …"
  message was skipped and the uploaded package deleted afterwards. The install now also explicitly keeps running when the browser
  leaves the page (closed tab, other link). Generic: form[data-nu-once] accepts data-nu-lock="<selector>" for more buttons to lock.

## 0.8.227 – lists: search field max. 350 px, filters next to it
- Lists (users, access levels, forms, procedures, reports, SQL builder, log, e-mail, files, items, permissions, prompts,
  translations …): the search field (with its magnifier) is at most 350 px wide, and the filters (funnel) stand right next to it
  instead of after a half-width column. Phones and narrow windows: full width as before.

## 0.8.226 – procedures: "Run the saved version:" before the run buttons
- Procedures: the run row reads "Run [dropdown]  Run the saved version: [Hidden] [New tab]" – the text now stands before the two
  buttons (before it followed them as "Runs the saved version."). In all languages.

## 0.8.225 – procedures: fields in a collapsible Details block
- Procedures (PHP tab): code, description, group, category, status and "Launch from" are in a collapsible block "Details" –
  collapsed for a saved procedure (the closed line shows code – description · group), open for a new procedure and when saving
  failed because of one of these fields. Run and the PHP editor move up. Text in all languages; Help → Procedures updated.

## 0.8.224 – all vendor libraries in the package again
- Package: all libraries under assets/vendor/ are included again (they were left out of the recent packages – an update keeps the
  ones already on the server, but a fresh install from nunext.zip missed them):
  - codemirror-5.65.21/ – codemirror-bundle.js (core, matchbrackets, closebrackets, modes xml, javascript, css, htmlmixed, clike,
    php, sql), codemirror-addons.js (comment, dialog, searchcursor, search, jump-to-line), codemirror.css, LICENSE
  - formatter/ – beautify.min.js, beautify-css.js, beautify-html.min.js, sql-formatter.min.js (as in nuBuilder), prettier-standalone.js
    (Prettier 3.3.3) and prettier-plugin-php.js (0.25.0) with their licences
  - jquery/ (jQuery 3.7.1), jquery-confirm/ (3.3.4), tom-select-2.6.2/, qrcode-generator-2.0.4/
  Only TinyMCE is still copied by hand. Checked: code editors with search / comment, Format for PHP, SQL and JS, form runtime with
  jQuery, jquery-confirm and Tom Select, QR codes.
- The package is now about 3 MB: PHP's default upload_max_filesize (2M) is too small – raise it (update page → Server → PHP upload
  limit) or update by hand.
- README, Help (Installation), TODO.md and CLAUDE.md updated.

## 0.8.223 – Add buttons in the style of the Print button
- "+ Add" buttons (all lists, form runtime, builder): the same style as the Print button next to them – grey text, transparent
  background, light border, normal weight (before light blue with blue semibold text). Dark mode included.

## 0.8.222 – admin tools: "View" instead of "Customize"
- Admin tools: the button for Full / Compact is called "View" (before "Customize"), with an eye icon; the menu shows the two choices
  without the extra heading.

## 0.8.221 – admin tools compact: no description in the Demo mode card
- Admin tools → Customize → Compact: the Demo mode card no longer shows its description ("Off. To switch it on …" / what demo mode
  does) – only the title and the On / Off badge, with the demo users and their problems when it is on. The Storage engine and Demo
  mode cards are as slim as the others.

## 0.8.220 – admin tools: Customize – Full or Compact
- Admin tools: new "Customize" button (top right) with two views – Full (cards with descriptions, as before) and Compact (titles
  only, smaller cards, without the description of the storage engine card). The choice is saved (setting vendor_layout) and needs
  no password confirmation. Texts in all languages; Help → Admin tools updated.

## 0.8.219 – update page: (i) help for "Folder writable"
- Update page, card "Server": a red "Folder writable" gets an (i) icon (hover or click) that explains why and how to fix it – the
  account PHP runs as (e.g. www-data) and the folder path are filled in: chown by SSH (PHP owns the files, or shared with the SFTP
  user through the group: 2775 / 664), or without SSH in the SFTP client: folders to 777 (recursive, folders only – "execute" on a
  folder means "may enter it") for the update and back to 755 afterwards; or update by hand. In all languages.
- Help → Update: the Server card's "Folder writable" and "PHP upload limit" explained.

## 0.8.218 – update page: failed requirements named, PHP upload limit checked
- Update page: "The server does not meet all requirements (see below)" now names the failed requirements right there (with the
  detail, e.g. which folder PHP cannot write to) and links to the "Server" card further down, which is framed red with the failed
  rows highlighted.
- Update page: new requirement "PHP upload limit" – shows upload_max_filesize and post_max_size and is red below 16 MB (PHP's default
  of 2M is too small for some packages). A package that is too big now says so with both values, instead of "The upload failed
  (file too big for upload_max_filesize?)".
- Package: Bootstrap Icons without the old .woff font (only .woff2, which every current browser uses) – the package stays below 2 MB.

## 0.8.217 – admin tools: new order of the cards
- Admin tools: new order – Update nuBuilder Next, Security check, Compatibility check, Prompt generator, Settings, Configuration
  file (nuconfig.php), CSV import, App cloner, API clients, Sample data, Storage engine, Demo mode. The "To do" card (developer mode
  only) comes last; the cards for missing tools (phpMyAdmin, Tiny File Manager) stay at the top.

## 0.8.216 – installer: "nuconfig.php not found" on Install fixed
- Installer: "Install" on an empty MySQL / MariaDB database stopped with "nuconfig.php not found – rename nuconfig-sample.php …".
  Creating nuBuilder's settings table looked up the collation of zzzzsys_object through the normal database connection, which
  needs nuconfig.php – but the installer writes nuconfig.php only afterwards. It now uses the installer's own connection.
  If an installation stopped this way: open the installer again (the database is no longer empty, so it is not imported twice) –
  it writes nuconfig.php; create the settings table later with Admin tools → Settings → Create settings table if it is missing.

## 0.8.215 – globeadmin menu: "Database" instead of "Database (phpMyAdmin)"
- Globeadmin menu: the entry that opens phpMyAdmin is called "Database" (before "Database (phpMyAdmin)") – in all languages.

## 0.8.214 – Bootstrap Icons 1.13.2 in the package
- Package: Bootstrap Icons 1.13.2 is included under assets/vendor/bootstrap-icons-1.13.2/ – bootstrap-icons.min.css, fonts/ (woff2,
  woff) and LICENSE (MIT). A fresh install from nunext.zip shows all icons; an update writes the same files.
- README, Help and TODO.md: the list of libraries that are still not in the package no longer names Bootstrap Icons.

## 0.8.213 – documentation checked: README, Help, development notes
- Documentation checked and brought up to date:
  - README: Install now names nunext.zip vs. nunext-dev.zip, the setup token / install.allow, the Security check, cron and the
    libraries the package does not contain; Updating mentions the signature; Structure lists cron.php, nusecurity, nuhousekeeping,
    the split report / API files, .htaccess and what is only in nunext-dev.zip.
  - Help: requirements (AllowOverride All or nginx rules), install from nunext.zip and the libraries to copy, update chapter
    (spinners, maintenance mode ends by itself).
  - CLAUDE.md (development notes): state 0.8.213, UI patterns for the Back button, lists and confirm + send-once.
  - TODO.md: new open point – Bootstrap Icons, Tom Select, CodeMirror, jQuery, jquery-confirm, QR code generator and the formatter
    are not in the package, so a fresh install from nunext.zip lacks them.

## 0.8.212 – $nuConfigBaseURL explained better
- $nuConfigBaseURL explained better (Help → nuconfig.php settings and the comment in nuconfig-sample.php): the address users open
  nuBuilder Next with, needed for complete links in e-mails, auto-login links and API addresses; what happens while it is empty
  (taken from the page request – can be wrong behind a proxy; "Forgot password" uses the globeadmin's last address).

## 0.8.211 – Back button in the style of Save / Clone / Delete
- Back buttons of the record pages (users, access levels, procedures, reports, SQL builder, permission items, API clients, e-mail
  templates, e-mail log) have the same light background and border as Save, Clone and Delete.

## 0.8.210 – update page: spinner on Check package and Install
- Update page: "Check package" and "Install" show a spinner ("Checking package…" / "Installing…") and are locked until the server
  answers – a second click or Enter does nothing. Install shows it after the confirmation dialog.
- Confirmation dialogs of send-once forms (data-nu-once) now also lock their button after OK (requestSubmit instead of submit).

## 0.8.209 – update: maintenance mode ends when the update is complete
- Update: maintenance mode ends by itself as soon as the update is complete. Before, the "finish" step after the update switched it
  off – when the update logged the globeadmin out (as 0.8.207 does once), that step was lost and maintenance mode stayed on until its
  15 minutes ran out ("Maintenance mode is on until …"). The database steps were not affected – they run on the next page view anyway.

## 0.8.208 – lists: column header without grey band
- Lists (users, access levels, forms, procedures, runtime browse …): the column header has no grey band any more – it has the card's
  background, lighter labels and only a line below. Rows, search, filters, buttons and the record navigator are unchanged.
- User list: the second grey line under the name (the user's "Name" field when it differs from first + last name) stays as before.

## 0.8.207 – security review: all findings fixed (S1–S26, B1–B10), new Admin tools → Security check
- All findings of the 0.8.206 security review are fixed (S1–S26, B1–B10). Points to check on the live server: TODO.md
  "0.8.207 security review" and Help → Security check.
- Changes you notice after the update:
  - The globeadmin is logged out once: the session is now bound to the globeadmin's name + password and to this installation.
  - New Admin tools → Security check with settings stored in the database (not in nuconfig.php; nuconfig-sample.php is unchanged).
    "Only the globeadmin grants admin rights" is on by default.
  - New root .htaccess plus deny-all .htaccess files in core/, pages/, lang/, install/, docs/ and dev/ (Apache 2.2 and 2.4).
    If the site answers 500 after the update, the server forbids a directive: delete the root .htaccess by SFTP.
  - The installer always needs the token or install.allow – except PHP's built-in server opened from the same computer.
  - Update packages are signed (Ed25519); the update page shows valid / unsigned and refuses a package with a wrong signature.
  - The install package nunext.zip has no CLAUDE.md, TODO.md, dev/ and .github/ any more; nunext-dev.zip has everything.
- Admin tools → Security check (globeadmin): HTTPS, base URL, secret key, weak globeadmin password, 2FA, debug mode, display_errors,
  PHP version, auto-login, old md5 passwords, admin-equivalent access levels, hash variables outside quotes, housekeeping,
  log retention, backup folder and library versions (TCPDF, PHPMailer, TinyMCE, phpMyAdmin, Tiny File Manager). "Files on the web"
  requests files that must never be served (README.md, core/*.php, install/*.sql, _backup/ …) and shows the Apache / nginx rules.
  Settings: only the globeadmin grants admin rights, do not save read-only / hidden fields, records only through the form's list,
  accept old md5 passwords, keep the log (days), ask for the password again after (minutes).
- Login: failed logins are counted in the database per name + IP, per name and per IP (15 minutes) – deleting the cookie no longer
  resets the lock. Old md5 passwords can be refused. The session id is strict, the cookie belongs to the nuBuilder Next folder
  and is "secure" on HTTPS (also behind a trusted proxy). A weak globeadmin password shows a notice after login.
- Password confirmation: update, nuconfig.php, settings, app cloner, API clients, vendor tools and impersonation ask for the
  password again when the last login or confirmation is older than the set minutes (default 15).
- Rights: users with rights on users or access levels cannot change admin-equivalent users, their own access level or its rights,
  or grant rights on the admin forms (also through the REST API). Admin forms are marked with a shield in the access level form.
  Runtime forms on the system tables (users, access levels, forms, procedures, selects, setup) save and delete for the
  globeadmin only.
- SQL builder and ad-hoc queries: run only a single SELECT / WITH inside a read-only transaction with a time limit; the preview
  needs the right to change SQL builder queries; error details only for the globeadmin; demo users cannot read system tables.
- E-mail templates: the preview needs rights on the template's form, the test mail the right to change templates (max. 10 per hour).
  "Forgot password" builds links from $nuConfigBaseURL (or the address the globeadmin used last) and allows 10 requests per IP
  per hour.
- Auto-login links: choose a validity (1, 7, 30, 90 days or unlimited) in the user form, "Revoke links" makes all existing links
  invalid; a link opened while another user is logged in asks first.
- Secrets: passwords, tokens and secret fields are masked in the log, in e-mail template previews and for demo users; plain TOTP
  secrets can be encrypted from Admin tools → nuconfig.php.
- Hash variables in SQL are escaped the right way for NO_BACKSLASH_ESCAPES and SQL Server. The compatibility check lists hash
  variables outside quotes in SQL and in SQL inside PHP code.
- Optional (Security check settings): do not save read-only and hidden fields sent by the browser; open, save and delete only
  records that the form's list shows.
- Housekeeping: expired tokens, sessions, API tokens, rate-limit rows, old log rows and temp files are cleaned up – by cron.php
  (*/15 * * * * php …/nunext/cron.php, as the web server's user) or, without cron, now and then on a page view.
- More: security headers (COOP, Permissions-Policy, HSTS on HTTPS), client IP from the last X-Forwarded-For entry behind a trusted
  proxy, the language switch does not write while impersonating, report errors without details for users, database names checked
  in the installer, backup / install / temp folders protected, size limits for form properties, backup downloads logged.
- Development: classes are loaded on demand (autoload); NuApiClient, NuApiPlayground and NuReportEngine are in their own files;
  every PHP file in core/, pages/ and lang/ ends at once when opened directly. dev/release.py runs php -l, the language check,
  unit tests, PHPStan and ESLint, signs the manifest and builds nunext.zip + nunext-dev.zip. New: dev/tests (unit + browser
  smoke test), dev/phpstan.neon, dev/eslint.config.mjs, .github/workflows/ci.yml, .gitignore.
- Help: new chapter "Security check"; auto-login, SQL builder, e-mail templates, installer, rights, compatibility check and
  update chapters updated.

## 0.8.206 – nuconfig.php readable for FTP / SFTP (0644)
- nuconfig.php is now written readable for every account on the server (0644, before 0640) – by the installer and by Admin tools →
  nuconfig.php. PHP writes it as the web server's user (e.g. www-data); with 0640 an FTP / SFTP user could not read or download
  it. It stays read only for FTP: change it with Admin tools → nuconfig.php, or download, edit and upload a copy.
  An existing nuconfig.php gets 0644 the next time it is saved with Admin tools → nuconfig.php.
- The installer keeps copying nuconfig-sample.php (it stays in the folder – it is part of the package and the update check).
  Help chapter "Installer" updated.

## 0.8.205 – start page: no "Updated to …" notice after an update
- Start page (globeadmin): the notice "Updated to nuBuilder Next … (from …, … versions). See what's new." after an update is gone.
  The change history stays on the update page (Admin tools → Update → Change history).
- Removed: NuUpdate::whatsNew() and the texts upd_whatsnew_notice / upd_whatsnew_show (all languages). The stored setting
  `whatsnew_seen` in zzzzsys_nunext_setting is no longer used (harmless, can stay). Help chapter "Start page" updated.

## 0.8.204 – installer: readable setup token, install.allow always accepted
- Installer, setup token: `_install/setup-token.txt` is now written readable for every account on the server (0644, before 0600).
  PHP writes it as the web server's user (e.g. www-data); with 0600 an FTP / SFTP user could not download it ("permission denied").
  A token file left by an older version is repaired on the next visit of the installer. The folder `_install/` is created with 0755.
  Web access stays blocked by `_install/.htaccess` (Apache) as before.
- Installer, `install.allow`: an empty file `install.allow` in the nuBuilder Next folder (created by FTP / SFTP / file manager) is now
  always accepted as proof of access – click Start without a token. Before, it only counted when the token file could not be
  written. The start page shows this option below the token field; the token field is no longer required.
- An empty token field shows "Enter the token or create the file install.allow." and does not count as a wrong try (10 wrong tokens
  still lock the installer for 15 minutes).
- New texts in all languages: ins_allow_alt, ins_err_token_empty. Help chapter "Installer" updated.

## 0.8.203 – Bootstrap 5.3.8 in the package
- Package: Bootstrap 5.3.8 is included again under assets/vendor/bootstrap-5.3.8/ – css/bootstrap.min.css, css/bootstrap.rtl.min.css
  (Arabic), js/bootstrap.bundle.min.js and LICENSE. No .map files (only the browser's developer tools ask for them). Bootstrap Icons
  (assets/vendor/bootstrap-icons-1.13.2/) are still not in the package – keep the folder you have.

## 0.8.202 – notes for development (CLAUDE.md, release and language tools)
- Notes for development – to continue in another session (or with another developer / a new Claude session):
  - **CLAUDE.md** (read automatically by Claude Code): the owner's standing rules (changelog, version, eleven languages, docs, TODO,
    nothing new in nuconfig-sample.php, nuBuilder compatibility, (i) tooltips), architecture, coding and UI conventions (CSP,
    both databases, rights, log, demo mode, buttons, tabs, tooltips, confirm / guard / send-once forms), how to build a test
    installation (MariaDB, PHP server, nuBuilder clone for TCPDF, Playwright) and the gotchas found while testing.
  - **dev/release.py**: one command for a release – README title and changelog section, NU_VERSION, manifest (file hashes and
    the change history entry), ../nunext.zip.
  - **dev/check_lang.php**: compares the eleven language files with en.php (missing keys, extra keys, different %s / %d).
    The folder dev/ is blocked for the web (.htaccess) and not used by the app.
- README: "Structure" brought up to date (all core parts, eleven languages, install, dev) and a new section "Development".

## 0.8.201 – user form: welcome e-mail hint behind an (i) icon
- User form, "Send welcome e-mail": the explanation ("After saving: login name and a personal link to set the password (valid 72
  hours, once). For a new user the password can then stay empty.") is no longer printed below the switch but shown as tooltip of an
  (i) icon right of the label (hover, or keyboard focus); clicking the icon does not toggle the switch. Screen readers still read
  the text with the switch. No new texts.

## 0.8.200 – user form: "Act as user" as the icon before the name
- User form (globeadmin): **Act as user** is no longer a button after Delete but the icon in front of the user name (person with a
  gear, replacing the plain person icon). A click opens the same menu (view only / full access); tooltip "Act as user". Other users,
  new users and the globeadmin's own record keep the person icon. The title bar gets one button less (phones: Back, Save, Clone,
  Delete fit in one row). Log → Sessions keeps its "Act as user" button.
- For developers: NuImpersonate::button() takes an optional $iconOnly.

## 0.8.199 – Save, Clone, Delete and tabs in nuBuilder style
- **Save / Clone / Delete** of records (users, access levels, permission items, procedures, reports, SQL builder queries, files,
  formats, prompts, e-mail templates, translations, settings, nuconfig.php, API clients, password, start page, form builder,
  format and report designer, and nuBuilder forms): one quiet style as in nuBuilder – light background, thin border, dark text with
  a Bootstrap icon (Save: floppy disk instead of the check mark, Clone: copy, Delete: trash). Before, Save was a blue button and
  Delete red-outlined. Save alone changes while there are unsaved changes: red text and icon on a slightly red background with a
  reddish border (dark mode: light red on dark red) – instead of a solid red button.
- Every Save button now turns red when its form has changes (before only forms, users / access levels and the e-mail settings):
  a general rule in assets/js/nuapp.js (.nuBtnSave gets .nuSaveEdited on the first change of its form; changes made by scripts
  while the page loads don't count). Settings and the report designer use the same class instead of switching to btn-danger.
- **Tabs** in nuBuilder style everywhere (forms, E-mail, Log / Debug / Sessions, procedures, reports, start page tabs …): each tab a
  small box with rounded top corners and a thin border on a grey base line; the active tab has a blue top line, bold dark text and
  a slightly darker background (before: underline tabs). Dark mode with matching colors. Vertical form tabs (nuSetVerticalTabs) keep
  their side bar on wider screens and become tab boxes on phones.
- Phones: Save / Clone / Delete still show only their icon (since 0.8.174).
- For developers: classes nuBtnAct (the style), nuBtnSave, nuBtnDel; tab colors as CSS variables --nu-tab-* (assets/css/nuapp.css).

## 0.8.198 – App cloner and database backups on SQL Server
- **App cloner** works with **SQL Server** too (source and target SQL Server; MySQL / MariaDB as before). The structure is read from
  SQL Server's catalog (sys.tables, sys.columns, sys.indexes, sys.sql_modules …): columns with their types, IDENTITY (seed /
  increment), defaults (named constraints), computed columns (PERSISTED), collation where it differs from the database, primary key,
  unique and check constraints, indexes with INCLUDE columns, filters and DESC (XML / spatial / columnstore indexes are reported and
  left out). Views, functions (scalar, inline, table-valued), procedures and DML triggers from their definition – functions before
  views, in creation order; encrypted or unreadable ones are reported. Foreign keys between the copied tables are added after the
  data. Data in batches within SQL Server's limits (1000 rows per INSERT, 2100 parameters), SET IDENTITY_INSERT, binary data as 0x…,
  text as N'…' (Unicode), SET DATEFORMAT ymd. Target: same or other server (port 1433, TrustServerCertificate from nuconfig.php),
  "create database" with the collation, modes stop / add (existing tables left as they are) / empty first.
- **Download SQL** on SQL Server: a T-SQL script with GO between batches (SSMS or sqlcmd); it drops the copied tables' foreign keys
  and tables first, so it can be run again on the same database.
- Page on SQL Server: collation instead of character set, no INSERT IGNORE / REPLACE, "Events" greyed out (SQL Server has none), a
  short hint below the buttons.
- **Backups with the database** (Update page) now also work on SQL Server – same code; size estimate from sys.allocation_units.
- Not tested against a real SQL Server (none was available) – the SQL was checked with simulated catalog data and a T-SQL parser;
  TODO.md has a checklist. MySQL / MariaDB: unchanged (clone and export re-tested).
- Docs (App cloner, Updating), all eleven languages.

## 0.8.197 – backups with the database (SQL), optional
- Update page, card Backups: **Back up now** moved into the card, with **Database**: *none* (default) / *nuBuilder Next tables* /
  *whole database* (size estimates shown) and **without logs** (default on: zzzzsys_debug, the e-mail log, nuBuilder Next's log and
  form history are created empty). The SQL is written by the App cloner's export code (tables, views in dependency order, data in
  batches, binary as hex, functions / procedures / triggers / events without DEFINER, no sessions and one-time tokens) – one code for
  both (core/nusqldump.php uses core/nuappcloner.php) – gzip-compressed into the backup as db/database.sql.gz. MySQL / MariaDB only;
  with MSSQL / SQLite the choice is greyed out.
- Backup list: badge **SQL <size>** (tooltip: what, tables, rows); the download button offers **Whole backup (.zip)** or **SQL only
  (.sql.gz)** (for phpMyAdmin; logged). The header shows the number and total size of the backups.
- Incomplete dumps are recognised: every dump ends with a marker line that is checked after writing – missing marker or an error →
  red badge "SQL incomplete" and a message after "Back up now". If the server stops the script (time limit), no backup is saved, the
  half-written files are removed and the next view of the update page reports it once (with a hint when the database was included).
- Before an update: **Back up the whole database too (≈ size)** next to Install, ticked when the database is smaller than 50 MB.
- **Keep SQL dumps in the newest N backups** (default 5, 0 = all; setting backup_keep_sql in zzzzsys_nunext_setting): older backups
  lose their dump automatically (badge "SQL removed"), their program files stay – they can still be rolled back.
- Backups are sorted by their real time (before: as text, wrong with different time zones). The lock against two backups at once now
  covers every backup (also before an update and before a rollback).
- App cloner: unchanged for you; for developers new options skip_rows and title.
- Docs (Updating → Database in the backup), TODO.md, all eleven languages. New file: core/nusqldump.php.

## 0.8.196 – "Back up now": no double backups
- Update page, **Back up now**: a double click (or Enter pressed twice) made two backups in a row. Now:
  - the button is disabled after the first click and shows "Creating backup…" with a spinner until the page reloads;
  - the server accepts each page view's form only once (one-time token in the session) – a second request with the same form is
    refused with "The backup was already made – the button was clicked more than once." (also without JavaScript, or when the
    browser resends the form);
  - a lock (_backup/.backup.lock) makes sure two backups never run at the same time (two tabs, two globeadmins): "A backup is being
    created right now – please wait until it is finished."
- For developers: form[data-nu-once] sends a form only once; data-nu-busy on its button sets the text shown while it is sent
  (assets/js/nuapp.js; reset when the page comes back from the browser's back/forward cache). The server still has to check.
- All eleven languages.

## 0.8.195 – Debug: hint behind an (i) icon
- Debug page: "Messages from nuDebug() in PHP code, newest first." is no longer printed below the title but shown as tooltip of an
  (i) icon right of "Debug" (hover, or keyboard focus). No new texts.

## 0.8.194 – e-mail log: delete single entries
- E-mail → E-mail log: every row has a delete button (trash icon at the end) and the detail of an entry a **Delete** button. After
  a confirmation the entry is removed; the list stays on the same page with its search and filters. Clicking the button does not open
  the row. Shown only with the delete right of nuBuilder's form nuemaillog (as "Delete entries older than … days"). Each deletion is
  logged ("E-mail log entry deleted", with the subject). All eleven languages.

## 0.8.193 – test mail: hint behind an (i) icon
- E-mail → Test mail: "Sends a mail with the active configuration and shows the SMTP dialogue (passwords are hidden). The mail is
  written to the e-mail log." is no longer printed in the empty right column but shown as tooltip of an (i) icon left of the
  "Send test mail" button (hover, or keyboard focus). The right column now only shows the result after sending. No new texts.

## 0.8.192 – e-mail settings: SSL hint behind an (i) icon
- E-mail → Settings, "Use SSL": the explanation (encryption follows the port: 465 = SSL/TLS, 587 / 25 = STARTTLS, "Use SSL" only for
  other ports) is no longer printed below the switch but shown as tooltip of an (i) icon right of the label (hover, or keyboard
  focus). The icon sits outside the label, so clicking it does not toggle the switch. No new texts.

## 0.8.191 – e-mail: PHPMailer folder behind an (i) icon
- E-mail → Settings, card Active configuration: the PHPMailer folder (or, when PHPMailer is missing, the places searched) is no longer
  printed below the version but shown as tooltip of an (i) icon right of "PHPMailer" (hover, or keyboard focus); the row is now one
  line like the others. Long paths wrap inside the tooltip (wider tooltip, class nuTipWide in assets/css/nuapp.css). No new texts.

## 0.8.190 – e-mail settings: copy / paste as JSON (without password)
- E-mail → Settings: buttons **Copy settings** and **Paste settings** next to Save. Copy puts the settings shown in the form (server,
  port, user name, sender address and name, authentication, SSL) as JSON on the clipboard – never the password. Paste fills the fields
  from that JSON (also nuBuilder's column names set_smtp_host …); a password in it is ignored, the stored password stays. Nothing is
  saved until you click Save. Without clipboard access (http without TLS, permission refused) copy uses a selection and paste opens
  a dialog with a text field. A message next to the buttons says what happened. Paste needs the save right; copy only viewing.
- The Save button of this form now turns red on unsaved changes, as on record pages.
- All eleven languages. New file: assets/js/nuemail.js.

## 0.8.189 – update page: change history 10 per page
- Update page, card Change history: shows 10 versions per page with the same list footer as the backups (total, first / previous /
  page x of y – click to type a page number – / next / last); the links jump back to the card. The newest five on the first page are
  open. The search now runs on the server when you press Enter (version, title and text across all versions, every hit open, paged
  too); clearing the field (×) shows all versions again.
- Both lists page independently (?pg= for the backups, ?hpg= and ?hq= for the history) and keep each other's page.
- For developers: nu_list_footer() takes an optional page parameter name and anchor (two lists on one page). No new texts.

## 0.8.188 – update page: backups 10 per page
- Admin tools → Update nuBuilder Next, card Backups: shows the newest 10 backups; with more, the list footer known from the other
  lists (total, first / previous / page x of y – click to type a page number – / next / last) pages through the rest
  (?p=update&pg=2). After deleting a backup the list stays on the same page. No new texts.

## 0.8.187 – documentation brought up to date
- Help (Documentation), checked against the changes since 0.8.172:
- Run objects: "Reports are not supported yet" removed; type Report and method button / iframe described (iframe with a form,
  a procedure's output or a report).
- Access levels: "Use two-factor authentication" with its (i) hint, status and "Switch on"; report rights (count on the form, set on
  each report); deleting / cloning includes the report rights.
- Editing: on phones Save, Clone and Delete show only their icon.
- Object menu: submenus unfold below their row on narrow screens.
- Start page of the globeadmin: the one-time "What's new" notice after an update.

## 0.8.186 – change history on the update page, "What's new"
- Update page (Admin tools → Update nuBuilder Next): new card "Change history" with every version of the installed package,
  newest first (from nunext-manifest.json). The installed version has a green badge, the last five are open, the others open on click;
  a search field filters by version, title and text, "Open all" / "Close all". Long entries are shown as flowing text with their
  bullet lines and headings.
- User menu (globeadmin): "nuBuilder Next 0.8.x · What's new" opens the change history.
- Start page (globeadmin): after an update – through the update page or by copying the files – a notice "Updated to nuBuilder Next
  0.8.x (from 0.8.y, n versions)" with a button to the change history, shown once. A new installation only remembers its version
  (setting whatsnew_seen in zzzzsys_nunext_setting). All texts in the eleven languages.
- Package: a stray file nothing-0.0.3-py2.py3-none-any.whl that was in the 0.8.185 zip is gone (delete it if you unpacked that zip).

## 0.8.185 – access level: 2FA hint behind an (i) icon
- Access level form, "Use two-factor authentication": the explanation is no longer printed below the switch but shown as tooltip of
  an (i) icon right of the label (hover, or keyboard focus). Text shortened: "Users of this access level must confirm their login with
  a second factor – an authenticator app or a code by e-mail" (without "as in nuBuilder"), in all eleven languages.
- For developers: elements with data-bs-toggle="tooltip" get a Bootstrap tooltip on every page (assets/js/nuapp.js); class nuInfoTip
  for such (i) icons (assets/css/nuapp.css).

## 0.8.184 – own grab cursor (light / dark)
- Things that are dragged show an own hand cursor instead of the browser's: black in light mode, white with a dark outline in dark
  mode; while dragging the closed hand. Used for the field list of the report designer and the tiles when customizing the start page.
  CSS variables --nu-cursor-grab / --nu-cursor-grabbing and the class nuGrab (assets/css/nuapp.css, SVG as data URL – no script,
  works with the strict CSP); the browser's grab / grabbing cursor is the fallback.
- Report designer, read only: the field list shows the normal arrow (nothing can be dragged there).

## 0.8.183 – reports: PDF runner, modern report designer, SQL builder
- **Reports** (nuBuilder's zzzzsys_report, same format – a report works in nuBuilder and in nuBuilder Next): Forms → Tools → Reports
  (?p=reports, rights of nuBuilder's form nubuildreport) and a new start page tile. List with search, group, data source, launch form,
  access count, designer and PDF buttons; record page with code, description, group, data source (procedure filling #TABLE_ID#,
  SQL builder query or table / view), launch form, access levels (zzzzsys_access_report), usage, history; clone, delete (refused
  while run objects use it).
- **PDF runner**: a port of nuBuilder's core/nurunpdf.php with the same layout rules – sections cut over pages, growing fields
  (min. / max. lines), page header and footer on every page, sort groups with headers / footers, SUM() / AVERAGE() per group,
  conditional formatting (#B#rrggbb| / #F#rrggbb|), #page# / #pages# / #code# / #description# / date parts and hash variables in
  labels, images (column, Image:<file code> incl. SVG, URL), KEEP EXACT HEIGHT boxes, "Use HTML". Criteria as in nuBuilder: every hash
  variable that is not a column (e.g. the launch form's fields) is added to the rows. Sorting and sums happen in PHP, so reports also
  run on MSSQL. TCPDF comes from nuBuilder (<nuBuilder>/third_party/tcpdf, <nuBuilder Next>/third_party/tcpdf or $nuConfigTCPDFPath);
  without it the pages say so. No "Powered by TCPDF" link.
- **Running**: run objects of type Report (button: new tab; iframe: launch form or PDF), ?p=report&c=<code> (launch form first, with a
  "Run report" button), and nuRunReport(code[, iframe]), nuRunReportSave(code, tag, callback, fileName) (temp folder + pdf_temp, as
  nuBuilder), new: nuPreviewReport(code) (PDF in a dialog) and nuEmailReportPDF(code, to, subject, body, fileName, callback). Each
  run is logged. The form builder now offers type Report with a report list, and method iframe for procedures and reports too.
- **Report designer** (?p=reportdesigner&id=…): page with rulers and grid, bands in print order with drag handles for their height,
  fields of the data source and launch form, page / system texts and images to drag in; field, text, box, line, image; move (also into
  another band), resize, multi-select (Shift-click, frame), align / same size / distribute, layers, copy / paste / duplicate, keyboard
  (arrows, Del, Ctrl+Z / Y / C / V / D / S), undo / redo, zoom (fits the page on first open); properties in mm and points with
  summary (SUM / AVERAGE), format, font, colors, border, lines; band height / page break / color; report tab with paper, orientation,
  HTML and up to five sort groups (add, order, direction, remove); PDF preview of the unsaved layout; help. Saves nuBuilder's JSON.
- **SQL builder** (?p=sqlbuilder, nuBuilder's zzzzsys_select / zzzzsys_select_clause, rights of form nuselect): tables as boxes
  (click or drag in, alias, all columns or single ones), joins by dragging a column onto another table's column (click: JOIN ↔ LEFT
  JOIN), clauses WHERE / GROUP BY / HAVING / ORDER BY, SQL written like nuBuilder's builder or edited by hand, run preview (first
  50 rows, SELECT only). Stored in nuBuilder's JSON, so nuBuilder opens it too.
- Access levels show the number of report rights; deleting / cloning an access level handles them. Procedures list the reports that
  print from them (and cannot be deleted while they do).
- Database: the four tables are created when missing (update step 0002, install scripts for MySQL and MSSQL).
- Sample application (version 11): reports "customers by country" (table) and "orders by customer" (SQL builder query), tiles on the
  start page, a run object in All objects, rights for all sample access levels.
- Demo mode: designer preview and field list, SQL builder preview work (read only).
- All eleven languages. New files: core/nureport.php, core/nusqlselect.php, core/nutcpdf.php, pages/nureports.php, nureport.php,
  nureportpdf.php, nureportdesigner.php, nusqlbuilder.php, assets/js/nureportdesigner.js, nusqlbuilder.js, nureports.js,
  assets/css/nureportdesigner.css, nusqlbuilder.css.

## 0.8.182 – ApexCharts ($nuConfigIncludeApexCharts) as in nuBuilder
- nuBuilder ships ApexCharts and loads it on every page when $nuConfigIncludeApexCharts is true (off by default); forms then use it in
  their own code (new ApexCharts(element, options).render() – nuBuilder's nuChart and chart objects stay Google Charts).
  nuBuilder Next now does the same: ApexCharts 4.4.0 (MIT, the same file as nuBuilder's, with its locales) is bundled in
  assets/vendor/apexcharts-4.4.0/ and loaded in the head of form pages when $nuConfigIncludeApexCharts is on – so scripts in HTML
  objects can use it too. The setting is read from nuconfig.php or, with $nuConfigSettingsFromDB, from nuBuilder's settings table
  (marked as used on the Settings page).
- A form whose code uses ApexCharts (form JavaScript, Before Edit PHP, HTML objects) while the setting is off shows the globeadmin a
  hint with a **Switch on** button (sets it in nuconfig.php after a confirmation, backup, log "ApexCharts switched on", back to the
  same record) – or a link to Settings when the settings table is used. All eleven languages.
- New vendor files (included in this zip): assets/vendor/apexcharts-4.4.0/ (apexcharts.min.js, LICENSE, locales/*.json).
- For developers: NuConfigFile::enableButton() takes an optional return query (form id and record), NU_APEXCHARTS (core/nuapp.php).

## 0.8.181 – TODO.md: outdated point removed
- Removed "Left out on purpose: nuChart, nuGetPHP" – both exist: nuChart since 0.8.90 (Google Charts, as in nuBuilder), nuGetPHP in
  JavaScript (opens the procedure in nuBuilder Next's procedure editor) and in PHP (returns the procedure record).

## 0.8.180 – nuAutoNumber: last number in the builder, sample records numbered
- The TODO point "nuAutoNumber is not filled yet" was out of date: new records and subform rows get the next number on save
  (sob_input_count + 1, prefix kept: INV7 → INV8, safe against parallel saves – the same result as nuBuilder's nuUpdateCounter).
  Checked again on sample_all. What was missing around it:
- Builder: input type nuAutoNumber shows the field **Last number** (sob_input_count) to set or correct the counter.
- New records show the placeholder "assigned on save" in the empty, read-only field.
- Sample application: the two sample_all records have the numbers 1001 and 1002 (counter at 1002), so the field is no longer empty.
  Applies to newly installed samples. All eleven languages.

## 0.8.179 – display procedures, procedures in iframes, English as default translation language
- Display objects with a **Display procedure** (nuBuilder's sob_display_procedure): the procedure's output (echo) is the value, as in
  nuBuilder; it takes precedence over Display SQL. It gets the form's hash variables (#RECORD_ID#, field values …) and #OBJECT_ID#,
  runs on load and again with nuRefreshDisplayObject / nuSubformRefreshDisplayObject (current values). Like nuBuilder without an
  access check (the procedure belongs to the form); demo mode still blocks writes. Errors: the globeadmin sees "⚠ message".
  The builder offers the field (Type settings of a display object, list of procedures; a code stored by nuBuilder stays selectable).
- Run objects with method **iframe** and a procedure show the procedure's output in the iframe (nuBuilder: nurunphp.php), run
  when the form opens, with the form's hash variables; access rights as for a run button, errors appear inside the iframe.
- Procedures that run while a form is rendered no longer mix their errors, JavaScript or properties into the form's own result
  (NuPhp::isolated()).
- Translations (zzzzsys_translate): when sus_language (users) / set_language (globeadmin) is empty, the reader's language is now
  **English** (no translation), as in nuBuilder – the nuBuilder Next menu language no longer decides.
- Three TODO.md points done. All eleven languages.

## 0.8.178 – nuconfig.php editor: Save checks first, error line marked
- Admin tools → nuconfig.php: Save now checks the text first in the background (the same checks as before: valid PHP, runs without
  error or output, $nuConfigDBName, database connection). With an error nothing is sent for saving: the message appears above the
  editor, the line PHP names is marked red and scrolled into view (the mark goes away with the next edit). Only a text that passes is
  saved. The server still checks again when saving (also without JavaScript).

## 0.8.177 – developer mode: TODO.md under Admin tools
- With nuBuilder's developer mode on ($nuConfigDevMode = true in nuconfig.php, globeadmin) Admin tools shows a card **To do**
  (?p=todo): TODO.md as a page – headings, open / done checkboxes (read only), nested points, code – with the number of open
  points. Without developer mode the card is hidden and the page answers 404; demo users never see it.
  nuconfig-sample.php is unchanged (it already lists $nuConfigDevMode as a comment). New file: pages/nutodo.php.

## 0.8.176 – access level: 2FA hint corrected, status and "Switch on"
- Access level form, "Use two-factor authentication": the hint said nuBuilder Next does not ask for 2FA yet – wrong, it does (since
  0.3: authenticator app or e-mail code, remembered devices). It needs $nuConfig2FAUser = true as in nuBuilder; the form now shows
  whether it is on. While it is off, the globeadmin gets a button **Switch on** (sets it in nuconfig.php after a confirmation – checks,
  backup, log "Two-factor login for users switched on" – and returns to the same access level), or a link to Settings when
  $nuConfigSettingsFromDB takes the value from the settings table. All eleven languages.
- For developers: NuConfigFile::enableForm() – a switch-on form whose button sits inside another form (<button form="…">).

## 0.8.175 – object menu: submenus stay on screen on phones
- Globeadmin object menu (click on a label of an edit form): on narrow screens the submenus (Actions, Move to tab, Access, Align,
  Validation) opened beside the menu and ended up off screen. When there is no room on either side, a submenu now unfolds below its
  row inside the menu (tap to open / close, chevron turns down); the menu scrolls if it gets taller than the window. Wider screens
  keep the submenus beside the menu.

## 0.8.174 – phones: Save, Clone, Delete as icons
- On phones (below 576 px) the Save, Clone and Delete buttons of edit pages (users, access levels, permission items, procedures,
  files, formats …) and of forms show only their icon; Save shows a floppy disk there instead of the check mark. The text stays for
  screen readers and appears as tooltip (title). Back and the other buttons keep their text; desktop and tablets are unchanged.
  CSS in assets/css/nuapp.css (.nuTitleBar buttons with data-nu-save / bi-copy / bi-trash, #nuSaveButton, #nuCloneButton, #nuDeleteButton).

## 0.8.173 – new table: wider Label and Type columns on phones
- Forms → Add → New table, column grid: on narrow screens Label and Type were squeezed to a few characters. They now keep a
  minimum width (Label 8.5rem, Type 10.5rem – long type names such as "Record ID (lookup)" are readable); the grid scrolls
  sideways instead.

## 0.8.172 – documentation brought up to date
- Help (Documentation): user form – Full name first, Code after Team, "Valid until" (was "Expires on"); Act as user is the last
  button after Delete; the Table select of a new form can be searched. (The other changes of 0.8.156–0.8.171 were documented
  with them: tile layout menu and Wide list, nuconfig.php editor, settings / API / secret key buttons, time zone, languages.)

## 0.8.171 – Dutch, Polish and Arabic (right to left)
- Three new interface languages: Nederlands, Polski, العربية (lang/nl.php, pl.php, ar.php – all 2,216 texts, placeholders checked
  against English). Language menu (top bar, login, installer) lists all eleven; the user form's Language select stores nuBuilder's
  names Dutch, Polish, Arabic in sus_language, so nuBuilder's zzzzsys_translate translations match. Browser languages nl / pl / ar are
  recognised (Accept-Language). Code editor toolbar and search dialog translated too; e-mails go out in the user's language.
- Arabic is shown right to left: html dir="rtl" and Bootstrap's RTL stylesheet (assets/vendor/bootstrap-5.3.8/css/bootstrap.rtl.min.css –
  new vendor file, included in this zip). nuBuilder Next's own CSS now uses logical properties (inline-start / -end), so spacing and
  positions flip by themselves; arrows point the reading way; code editors and code stay left to right. E-mails in Arabic are RTL too.
  Numbers and dates use Western digits (locale ar-u-nu-latn). nuBuilder forms with absolute (pixel) positions keep their layout.
- For developers: NU_LANGS_RTL and nu_lang_rtl() (core/nuapp.php).

## 0.8.170 – "Generate key" for $nuConfigSecretKey
- API clients: the notice "$nuConfigSecretKey is empty …" now has a button **Generate key**. It opens a dialog with a random key
  (32 bytes = 64 hex characters, made in the browser with crypto.getRandomValues; New key / Copy; editable) and lists what changes:
  secrets of existing API clients must be rotated (shown with their number), older auto-login links stop working, 2FA secrets
  are encrypted from now on. *Save to nuconfig.php* writes it like the nuconfig.php editor (checks, backup, log "Secret key set" –
  only the length, never the key).
- Only while the key is empty (server-side check): replacing a key would make encrypted 2FA secrets unreadable. Format: 32–128
  characters A–Z a–z 0–9 _ -.

## 0.8.169 – "Switch the API on" button
- API clients and API playground: the notice "The REST API is switched off …" now has a button **Switch the API on**. After a
  confirmation it sets `$nuConfigApi = true;` in nuconfig.php and returns to the same page. Saved like the nuconfig.php editor
  (checks, backup, log "REST API switched on"); not writable → a note instead of the button.
- Generic: NuConfigFile::enableButton() / SWITCHES – a variable must be listed there to be switched on this way.

## 0.8.168 – new form: Table select with search
- Forms → Add (new form), existing table: the *Table* select is now searchable (Tom Select – bundled, Select2-like): type part of
  a table name to filter the list. Choosing a table loads its columns and primary keys as before. Without JavaScript or the library
  the plain select stays.

## 0.8.167 – user edit form: Code after Team
- User edit form: *Code* moved from next to Full name to after Team (row Code · Additional 1 · Additional 2); Full name now uses
  the full width of its row.

## 0.8.166 – user edit form: Full name first
- User edit form: *Full name* is now the first field (with Code next to it), First name / Last name below it. New users start
  in Full name, which fills First / Last name as before.

## 0.8.165 – new nuBuilder Next logo
- The new logo (colour circle + "nuBuilder NEXT") replaces the "nu" square and the text "nuBuilder Next": in the top bar, on the
  login page, on the other sign-in pages (forgot / reset password, 2FA – the ones without an own icon) and in the installer.
- Two images with transparent background: assets/img/nunext-logo.png (dark text) and nunext-logo-dark.png (light text) – the dark
  theme shows the second one. nu_brand_logo() in core/nulayout.php draws it ('lg' = sign-in pages).

## 0.8.164 – user edit form: "Act as user" after Delete
- User edit form: the *Act as user* button now comes last in the button row, after Delete (it was between Reset 2FA and Clone).

## 0.8.163 – time zone: nuBuilder's Setup as fallback, warning on Admin tools
- Times showed the server's zone (e.g. a backup at 15:00 instead of 21:00) when nuconfig.php has no `$nuConfigTimezone` – it is
  written only by nuBuilder Next's installer, nuBuilder's own nuconfig.php does not have it. The time zone is now taken from:
  1. `$nuConfigTimezone` (nuconfig.php, or the settings table with $nuConfigSettingsFromDB), 2. nuBuilder's **Setup → Time zone**
  (zzzzsys_setup / zzzzsys_timezone – what nuBuilder uses; read once per session), 3. the server's default.
- Admin tools: a warning when the server's default is used, with a button to the nuconfig.php editor.
- Times already stored keep their value: backups carry their UTC offset and show correctly now; log entries and audit columns
  written before stay in the server's time.

## 0.8.162 – settings: "Use these settings" sets $nuConfigSettingsFromDB
- Admin tools → Settings: the notice "These settings are not used …" now has a button **Use these settings**. After a confirmation it
  sets `$nuConfigSettingsFromDB = true;` in nuconfig.php (the existing line is changed, its comment kept; without one the line is
  added) and reloads the page. Saved like the nuconfig.php editor: checks, backup, log ("Settings table switched on").
  If nuconfig.php is not writable, the notice says so instead of showing the button.

## 0.8.161 – nuconfig.php editor: tool buttons
- Admin tools → Configuration file (nuconfig.php): the editor now has the code tool buttons of the procedure editor – undo / redo,
  search, replace, toggle comment, show spaces and tabs, Format (Prettier, PHP; Undo reverts it) and full screen (F11, Esc) –
  without the hash variable (#) button. Ctrl+S still saves, also in full screen.

## 0.8.160 – edit nuconfig.php in the browser
- Admin tools → **Configuration file (nuconfig.php)** (`?p=configfile`, globeadmin; also in the ▾ of the Admin tools tile): edits
  nuconfig.php in a code editor (CodeMirror, PHP). Save writes only if the text is valid PHP, starts with <?php, runs without an error
  or output, sets $nuConfigDBName and connects to the database with its settings; *Check* runs the same tests without saving.
- Before writing, the current file is copied to the backup folder as `nuconfig-<YYYYmmdd-His>.php` (newest 10 kept; .php so a
  misconfigured server runs it instead of showing the passwords). *Backups* loads one into the editor, Save restores it.
- Refused if the file changed since the page was opened; written through a temporary file + rename, OPcache invalidated.
  Logged as "nuconfig.php changed" (backup name and size only). Not available in demo mode (the file holds the passwords).
- New: core/nuconfigfile.php, pages/nuconfigfile.php, assets/js/nuconfigfile.js; texts in all eight languages.

## 0.8.159 – start page: "Wide list" tile layout
- New tile layout *Wide list*: the list over the full page width, as before 0.8.158. *List* stays at most 550 px wide.
  Offered in the ▾ menu next to Customize and in Customize → Tiles (all eight languages).

## 0.8.158 – start page: list layout no wider than 550 px
- Globeadmin start page, tiles as *List*: the list is at most 550 px wide (left-aligned) instead of the full page width, so
  the + / ▾ buttons stay next to the text. Narrower screens still use the full width. Large and compact tiles are unchanged.

## 0.8.157 – start page: Prompt generator tile text
- The description of the start page tile *Prompt generator* is now "Generate AI Prompts, Ready to Copy"
  (was "AI prompts with your tables and nuBuilder context – to copy"); the other seven languages say the same.

## 0.8.156 – start page: tile layout from the ▾ next to Customize
- Globeadmin start page: Customize now has a ▾ on its right. It opens a menu "Tiles" with Large with description, Compact and
  List (the current one ticked); a click saves the layout right away and reloads the page – no need to go through Customize.
  Saved like Customize (zzzzsys_nunext_setting, log entry "Start page changed"); in demo mode it shows the demo message.

## 0.8.155 – Ctrl+click on list rows: new tab in front
- Ctrl+click (Mac: Cmd+click) – or Ctrl+Enter – on a record in a list now opens it in a new tab that becomes active: form lists and
  nuBuilder Next's lists (users, access levels, procedures …). Same for run buttons set to "New browser tab". Middle-click still
  opens a background tab. nuOpenTabFront(url) (assets/js/nuapp.js) is the shared helper.

## 0.8.154 – start page Ctrl+click: second attempt
- 0.8.153 did not help: Chrome and Firefox 130+ also open window.open() in the background while the Ctrl+click event is running.
  The tab is now opened right after the click (setTimeout) – no current event with Ctrl any more, the click still allows the pop-up.
  If a pop-up blocker refuses it, the page opens in the current tab.

## 0.8.153 – start page: Ctrl+click opens the tile in a new, active tab
- Ctrl+click (Mac: Cmd+click) on a start page tile, or on an entry of a tile's "▾" / "+" button, opens it in a new tab that comes to
  the front (the browser's own Ctrl+click left it in the background). Middle-click still opens a background tab.

## 0.8.152 – buttons on their own row below the title
- Title bars of lists, forms and record pages: the buttons (Add, Print, Save, Back, ⋮ …) now always sit on a row of their own below
  the title (and its hint), left-aligned – on desktop like on phones. Hints such as the procedures' (i) text now appear between the
  title and the buttons on every screen width.

## 0.8.151 – hash variable search ignores #
- "Insert hash variable" (# menu of the code editors): searching with the # signs (e.g. #USER_ID#) now finds the variable.

## 0.8.150 – permission items: explanation behind (i)
- The permission items list no longer shows the explanation text above the list; the (i) button next to the title shows / hides
  it (like the procedures list).

## 0.8.149 – "Add" right for list Add buttons; access level Delete explains why
- The Add button of a list now needs the form right **Add** (slf_add_button), as in nuBuilder – "New" (slf_new_button) is only the
  New button on the edit screen. Form lists and nuBuilder Next's lists: users, access levels, procedures, items, files, formats,
  translations, permission items, prompts, e-mail templates. Saving a new record still works with either right (e.g. after New).
- Access levels: Delete of a level that users still have was a disabled button that did nothing. It now opens a message
  "Still assigned to N user(s), cannot be deleted." (list and edit page). Generic: a button with data-nu-info shows its text.

## 0.8.148 – list footer on phones
- On small screens (below 576 px) the list footer no longer shows "xx per page" (it was cut off): total on the left, pager on the right.

## 0.8.147 – French, Italian, Spanish, Portuguese, Russian, Simplified Chinese; neutral German
- Six new interface languages: Français, Italiano, Español, Português, Русский, 简体中文 (lang/fr.php, it.php, es.php, pt.php, ru.php,
  zh.php – all 2,165 texts). Language menu (top bar, login, installer) lists all eight; the user form's Language select offers them
  and stores nuBuilder's names (French, Italian, Spanish, Portuguese, Russian, Chinese) in sus_language, so nuBuilder and its
  zzzzsys_translate translations use the same language. Without a user language the browser decides (Accept-Language with q values,
  first supported language; none supported: German as before). Missing texts fall back to English.
- E-mails (welcome, password reset, 2FA codes) go out in the user's language; e-mail templates offer all languages by default.
- Code editor toolbar and search dialog (CodeMirror) translated too. #USER_LANGUAGE# / #LANGUAGE# return the nuBuilder name.
- The help (Documentation) stays English.
- German: no more formal "Sie" – all texts are neutral now (e.g. "Bitte die markierten Felder überprüfen."), including the e-mails.
- For developers: NU_LANGS (core/nuapp.php) lists the languages; nu_lang_code(), nu_locale() are new.

## 0.8.146 – unsaved changes: Save / Discard / Keep editing
- Leaving a record with unsaved changes through a link (Back, breadcrumbs, menu, top bar, start page, …) now opens a dialog
  "Unsaved changes" with **Save** (saves, then opens the link – if the save fails you stay on the record with the error),
  **Discard changes** and **Keep editing**. Esc / ✕ = keep editing.
- Where: form edit screens (also run buttons that open another form), the form builder (also switching to another panel – form,
  object, tabs, list, code, history), users, access levels, permission items, procedures, e-mail templates, e-mail settings, files,
  items, prompts, translations, API clients, settings, change password.
- Sending another form of the page while there are unsaved changes (e.g. Clone) asks Discard / Keep editing first.
- Browser back button, reload and closing the tab: browsers only allow their own question ("Leave site?"), which is shown now on all
  these pages too.
- For developers: nuLeaveGuard.add({dirty, save, discard}) / nuLeaveGuard.ask(urlOrFunction) (assets/js/nuapp.js);
  <form data-nu-guard> registers a form; the hidden field _nu_then makes nu_redirect() go to the clicked link after a successful save.

## 0.8.145 – edit forms: focus on the first field
- When an edit form opens (Add, Clone or a record from the list), the first editable field gets the focus, so you can type straight away.
  Form edit screens (also in popups, after nuOnLoad – a focus set by nuSetFocus() in nuOnLoad wins) and nuBuilder Next's own record
  pages: users, access levels, permission items, procedures, e-mail templates, files, items, prompts, translations. Skipped: read only,
  disabled and hidden fields (other tabs), sub-form rows, code editors. A searchable select gets the focus without opening its list.
  A field with autofocus (e.g. the code of a new procedure) keeps priority. Not in iframe objects embedded in another form.
- Touch screens (no mouse / trackpad): nothing gets the focus automatically, so the on-screen keyboard does not pop up – also the
  autofocus fields (login, 2FA code, …) and the search field of a lookup window. nuHasKeyboard() and nuFocusFirst(root) are global.

## 0.8.144 – list footer: first and last page
- The footer of every list (form lists and the nuBuilder Next pages: users, access levels, procedures, log, …) has two more buttons:
  « first page and » last page, around Previous / Page x of y / Next. Greyed out on the first / last page; tooltip "First page" /
  "Last page". On phones they show as icons like Previous / Next.

## 0.8.143 – read only fields look grey
- Fields that cannot be edited now have a grey background and grey text, like Bootstrap's disabled inputs: fields set to read only in
  the object's access (e.g. Created at / Created by / Updated at / Updated by), all fields of a view-only form, Display / Calc fields,
  lookup descriptions, disabled selects (also with search) and fields switched with nuDisable() / nuReadonly() at runtime.
  No focus ring on read only fields.

## 0.8.142 – documentation updated
- Help (user menu → Documentation) brought up to date with 0.8.134–0.8.141: signing in with "Try the demo"; "+" / "▾" on the start
  page tiles; configuration table with $nuConfigDemoUser and $nuConfigDemoSavingAllowedIds; procedures: "Demo access"; form builder:
  crossing out rows (tabs, list columns, events, columns of a new table, HTML attributes); settings: $nuConfigDemoSavingAllowedIds is
  used by nuBuilder Next; Admin tools: the other cards and the Demo mode status; users: "Demo user" badge; Inspect record and the
  Demo mode chapter: nuDemo(). Each part is shown only to those who may use it.
- README: compatibility note on nuBuilder's demo settings.

## 0.8.141 – start page: "+" and "▾" on tiles
- Globeadmin start page: instead of ">" some tiles have a button on the right. "+" opens a new record straight away: Users, Access levels,
  Permission items, Procedures, Prompt generator, Notes. "▾" opens a menu with the pages behind the tile: Forms (New form, Clone form,
  CSV import, Code snippets, Items, Files, Formats, Translations), API clients (New API client, API playground), E-mail (Settings,
  Test mail, Templates, New template, E-mail log), Log (Debug, Log, Logged-in users), Admin tools (Update, Sample data, CSV import,
  App cloner, Settings, API clients, Prompt generator, Compatibility check). The tile itself still opens its page.
- Logged-in users, Update and Settings keep ">", phpMyAdmin / File Manager keep "↗", own tiles are unchanged. Works in all tile sizes
  (large, compact, list) and on phones; card and button lift together on hover. NuHome::actions() defines the buttons.

## 0.8.140 – demo mode
- Like nuBuilder's demo mode, but with real users: create a user (login, password, any access level) and name it in nuconfig.php –
  $nuConfigDemoUser = "demo"; (login name or user id; several separated by commas, e.g. one per role). Optional: nuBuilder's
  $nuConfigDemoSavingAllowedIds = "form1,form2"; (ids or codes) – forms where demo users may still save.
- Login page: "Try the demo" logs in without a password (one button per demo user, also on the $nuWelcomeBodyInnerHTML page).
  Name + password and auto-login links work as well. Demo users get no second factor and no forced password change.
- A demo user sees everything the globeadmin sees – forms, records, form builder, procedures, settings, log, admin tools – with every
  button. Whatever would change something answers "Not available in the demo": saving (except in the allowed forms), deleting (never,
  also not sub-form rows), builder, formats, users, access levels, settings, update, import, cloners, sample data, phpMyAdmin / File
  Manager, acting as another user, ending sessions, start page Customize, test e-mails. Procedures run only with "Demo access"
  (sph_demo) and cannot write; no e-mails are sent; "Forgot password" sends nothing for demo users.
- Enforced in index.php (form posts refused unless they only read; backup downloads), in the JSON APIs (formapi, builderapi,
  formatapi) and in the database layer: NuDB refuses INSERT / UPDATE / DELETE / DDL of a demo session except nuBuilder Next's session,
  log, token and debug tables, temporary tables (#TABLE_ID# = ___nu…, #…) and system tables created on first use – so form code
  (Before Browse / Before Edit) that writes ends with the demo message, while temporary tables for lists keep working.
- Never shown to demo users: passwords / keys in Settings and Inspect record, auto-login links, the database name, IP addresses
  (sessions, log, log CSV).
- Top bar badge "Demo – read only"; user form badge "Demo user"; Admin tools → Demo mode: the demo users with warnings (unknown,
  expired, no access level, API service user, globeadmin), the forms that allow saving, the procedures with "Demo access".
- Shared login: a nuBuilder demo session (IS_DEMO) is no longer taken over as a globeadmin session.
- nuDemo($showError) (PHP) ported: true in a demo session. New file core/nudemo.php; help chapter "Demo mode".

## 0.8.139 – remove = cross out until saved
- Access level, form rights: the remove button no longer deletes the row at once – it crosses it out (faded, struck through,
  tooltip "Removed when you save") and turns into "Undo removal" (↺). The row is deleted when the access level is saved; until then
  one click brings it back with its rights. The counter shows the rows that stay; the column switches skip crossed-out rows; the form
  stays taken in "Add form" (undo instead of adding it twice). If saving fails for another reason, the row comes back crossed out
  (posted as rights[..][remove] = 1).
- Form builder, the same for: columns of a new table ("New form"), tabs, list columns, object events and the HTML attribute editor
  (left out of the result until Apply). Crossing out turns Save red.
- nuBuilder form sub-forms already worked this way (Delete switch per row) and are unchanged; the start page's Customize keeps its
  own Cancel and the "Hidden" tray.
- Shared helper window.nuToggleRemoved(row, button, labels[, on]) (nuapp.js) and CSS class nuRowRemoved (nuapp.css).

## 0.8.138 – Delete buttons with a light red border
- Light theme: red outline buttons (Delete on edit pages and in the form toolbar, Delete all, the row delete buttons in lists,
  Reset on the start page …) have the light red border #ebaab1. On hover they fill red as before (row buttons keep their soft hover);
  dark mode unchanged.

## 0.8.137 – grey buttons with a light border
- Light theme: grey outline buttons (Back, Act as user, Clone, New, Refresh, Tools, the ⋮ options button, Import …) have the light
  border #dee2e6 like Print and the search button – also on hover. The selected button of a group (Absolute | Auto | Grid) keeps
  its dark fill; the top bar buttons keep #e4e4e4; dark mode unchanged.

## 0.8.136 – tabs with an underline
- All tabs (Debug / Log / Logged-in users, E-mail, Translations, record tabs of items and procedures, nuBuilder form tabs, form builder,
  start page tabs, API playground) no longer look like boxes: grey text on a thin grey base line, the active tab in blue with a
  3 px blue line; on hover the text darkens and a light grey line appears. Dark mode: light grey text, active tab light blue.
- nuSetVerticalTabs(): the line sits on the right edge of the active tab; on phones the tabs run across with the underline.
- CSS variables --nu-tab-line, --nu-tab-color, --nu-tab-hover-color, --nu-tab-hover-line, --nu-tab-active (nuapp.css) replace the old
  --nu-tab-bg / --nu-tab-bg-hover / --nu-tab-active-bg.

## 0.8.135 – Add buttons in light blue
- "+ Add" buttons (lists, form toolbar, form builder) are light blue with a blue border and blue semibold text instead of solid blue,
  so they stand out less than Save. Dark mode: the same in a translucent blue. CSS class nuAddBtn (nuapp.css).
- E-mail templates, e-mail log and Debug: the Print button is small again like the buttons next to it (NuListExport::buttons(..., true)).

## 0.8.134 – buttons right after the title
- Lists, edit pages and form pages: the buttons (Add, Print, Save, Clone, Delete …) follow the title instead of sitting at the far
  right – a short mouse way on wide screens. Applies to all of nuBuilder Next's own lists and edit pages, the toolbar of nuBuilder
  forms (list and edit), the form builder and the print views. Phones unchanged (the buttons wrap below the title).
- The main button comes first: Add before Print in lists; Save before Clone and Delete on the edit pages of items, files, prompts,
  translations and e-mail templates. There, Enter in a field now saves – before, the first button was Delete, so Enter asked to delete.
- Subtitles and hints (Formats, Settings, API clients, Help …) sit on their own line below title and buttons.
- The ⋮ options menu of forms and the Tools menu of the forms list open to the right.
- New CSS class nuTitleBar (nuapp.css) for title bars: title, optional nuTitleSub, buttons.

## 0.8.133 – top bar buttons with a light border
- Top bar and login page: the theme, language and user menu buttons have a light border (#e4e4e4), also on hover and when open; dark mode unchanged.

## 0.8.132 – globeadmin start page without greeting
- Globeadmin start page: no "Welcome, Globeadmin" and no Globeadmin badge any more – the tiles start at the top, Customize sits on the right. Other users keep their greeting with the access level.

## 0.8.131 – documentation and README updated
- README: description of what nuBuilder Next changes in the database (own zzzzsys_nunext_* tables, zzzzsys_config only on
  request, columns only after confirming the structure check), sus_json through nuSetUserJSONData, use of zzzzsys_session,
  the settings table in the installation steps; feature list in the title.
- Help: navigation – the globeadmin's start page with Customize and the admin entries of the user menu.

## 0.8.130 – start page: tabs with several groups
- Globeadmin start page, display "Tabs": tabs can now hold several groups. Customize → Add tab (rename, order, delete – its groups
  move to the first tab); each group has a tab selection, "Add group" next to a tab adds a group there, groups are ordered within
  their tab. Without tabs every group is a tab of its own (as before); empty tabs are not shown.
- Customize: a tile can also be dropped on the title row of a group.

## 0.8.129 – customizable globeadmin start page
- Globeadmin start page in groups: Users & rights, Development, Communication, System (now also API clients, Update and Settings).
- Customize (top right): drag tiles between groups or move them with the arrows; rename, add, delete and order groups; hide tiles
  (tray "Hidden", show again with the eye); display as groups or tabs (last tab remembered); tiles large, compact or as a list;
  Reset to the default. One layout for the installation in the new table zzzzsys_nunext_setting; changes are logged.
- Own tiles: label, description, Bootstrap icon with suggestions, colour; opens a form (list, new record or a record), a nuBuilder
  Next page, a procedure (output in a new tab or hidden with a message) or a link (https://… / index.php?…), optionally in a new
  tab. Checked on the server (known form / page / procedure, no javascript: links).
- $nuConfigGlobeadminHome (nuBuilder's setting, id or code of a launch form): opened instead of the tile page; the user menu then
  has "Start page (tiles)". nuhome or empty = the tile page.

## 0.8.128 – update: "not newer" notice in red
- Update: the notice that the package is not newer than the installed version is shown in a soft red (with an icon) instead of yellow.

## 0.8.127 – sample start form redesigned
- Sample data: new look of the start form sample_home – logo (now green) next to the title, the logged-in user below it, section
  titles in small capitals and the run objects as tiles with an icon (Customers, Products, Orders, All objects; New order,
  Customers in a new tab, Procedure, Hidden), lifted on hover, light and dark mode; the hash cookie note as a light box.
  Done with the form's CSS (sfo_style) and the class sampleTile on the run objects – an example of styling a launch form.
  Looks the same in the layouts Absolute, Auto and Grid; on phones two tiles per row. Sample version 9: Admin tools →
  Sample data → Refresh applies it.

## 0.8.126 – help: CSV import and Prompt generator only for those who can use them
- Help: the chapters CSV import (globeadmin only) and Prompt generator (rights on nupromptgenerator) are shown only to users who
  can use them, like the other admin chapters.

## 0.8.125 – test e-mail remembers the recipient
- E-mail → Test e-mail: the "To" address is remembered in this browser (localStorage) and filled in when the tab is opened
  again; without a stored address the own e-mail address is used as before. Other fields can use the same with
  data-nu-remember="key".

## 0.8.124 – settings: create the table when it is missing
- Settings: the table is checked with a direct query (no longer through information_schema). When it cannot be read, the page names
  the database from nuconfig.php and shows the database's message.
- New button "Create settings table" when zzzzsys_config is missing – e.g. a database set up with nuBuilder Next's own install
  scripts, which did not contain it: creates the table as in nuBuilder (MySQL / MariaDB, MSSQL, SQLite) with nuBuilder's 53 default
  settings (install/settings_defaults.json); existing rows are never changed. The installer now creates the table as well.

## 0.8.123 – nuBuilder settings (zzzzsys_config)
- Admin tools → Settings (globeadmin): nuBuilder's Setup → Settings (table zzzzsys_config) – category, title, setting, value,
  description; search, category filter and "only settings nuBuilder Next uses" (marked Next). Value fields by type: true / false
  selection, number, Ace theme list, text with nuBuilder's suggestions. Changed values marked, Save red, only changes stored and
  logged; a note for settings nuBuilder applies after a new login or browser restart.
- Import as nuBuilder's button, but robust: PHP variables are taken by including nuconfig.php (real values, no splitting at = or ;,
  quotes inside values kept), nuUXOptions['…'] = …; lines (also in $nuJSOptions) by a regular expression, commented lines skipped;
  Monday / Sunday → 1 / 0 for nuCalendarStartOfWeek. Settings not set in nuconfig.php stay unchanged.
- $nuConfigSettingsFromDB = true (as in nuBuilder): the table's values replace those of nuconfig.php – nu_cfg() and window.nuUXOptions
  in forms – as typed values (true / false, numbers, text) without eval. Database access, $nuConfigSecretKey and the switch itself
  always come from nuconfig.php. Without the switch nuUXOptions come from nuconfig.php.

## 0.8.122 – top bar: no lone "Start" link
- Top bar: no "Start" link when it would be the only one (globeadmin, users without rights to Users, Access levels, Forms,
  Log, E-mail or Notes) – the logo opens the start page. Next to other areas "Start" stays (shows where you are, first entry
  of the phone menu).

## 0.8.121 – procedures: clearer edit form layout
- Procedure edit form (tab PHP): labels above the fields in semibold, normal-size fields; Code and Description full width,
  Group / Category and Status / Launch from side by side (one below the other on phones), the Launch from hint below that row;
  Run with its selection and the Hidden / New tab buttons (dark text, light border) on one line.

## 0.8.120 – documentation updated
- Help: configuration table with $nuConfigValidationMarkers, $nuConfigApi, $nuConfigApiTokenMinutes, $nuConfigApiAllowHttp and
  what $nuConfigSecretKey is used for; signing in after the session has ended; ◆ for unique values; users list filters on wide
  screens; lookups with description width 0.
- README: structure with api.php, core/nuapi.php, core/nuinfra.php, core/nuschemacheck.php and docs/.

## 0.8.119 – lighter hover on grey buttons
- Grey outline buttons (Act as user, Clone, Back, Print, row and tool buttons, the theme / language / user menu in the top bar …):
  on hover a light grey background (#e1e3e87d) with dark text (#0c0b0b) instead of the dark grey fill. The search and filter
  buttons keep their own hover; dark mode is unchanged.

## 0.8.118 – list footer: jump to a page
- List footer (all lists and form browse lists): a click on "Page x / y" turns it into a number field – type a page and
  press Enter to jump there (numbers beyond the last page go to the last one, Esc or leaving the field cancels).
  Only when the list has more than one page.

## 0.8.117 – clone buttons in lists: border like the search button
- Lists: the grey row buttons (Clone in the users and access levels lists, Tools and Cloner in the forms list) have the same
  border colour as the search button – also on hover, when pressed and in dark mode. Delete buttons stay red.

## 0.8.116 – print button border like the search button
- Print button of the lists (users, forms, procedures …) and of form browse lists: same border colour as the search button –
  also on hover, when pressed and in dark mode.

## 0.8.115 – filter button border like the search button
- Lists (users, forms, procedures, e-mail templates and all others with a filter button): the filter button has the same
  border colour as the search button next to it – also on hover, while the filters are open and in dark mode.

## 0.8.114 – users list: filters open on wide screens
- Users list: on screens at least 800 px wide the filters (access level, status) are shown at once; the filter button still
  hides them. Narrower screens keep them behind the button. Other lists can use the same with data-nu-open-min="800".

## 0.8.113 – validation markers after labels
- Edit forms show the validation of each object after its label: red * = No blanks, blue ◆ (Bootstrap icon) = No duplicates,
  both for No duplicates and blanks. Taken from the Validation setting of the object (sob_all_validate) – nothing per field, the
  validation itself is unchanged.
- Tooltips "Required field" / "Value must be unique"; screen readers hear "required" / "must be unique" instead of the symbols;
  required inputs get aria-required (not the HTML required attribute, so the browser does not validate on its own).
- Legend below the form with the markers in use. Colours stay red and blue, with lighter shades in dark mode for contrast.
- Also in subform column titles and form-layout subforms; not on forms that can only be viewed, nor on hidden objects.
- nuSetLabelText() keeps the markers; label texts read by nuSetPlaceholder(), the label menu and subform filters leave them out.
- $nuConfigValidationMarkers = false in nuconfig.php switches the markers off.

## 0.8.112 – back to the page after a new login
- After the session has ended, the login now always returns to the page that was clicked (e.g. Admin tools): the target is
  carried in the login URL (?next=…) and in the login form, so it also works when the session itself is gone (idle tab, cleared
  session files on shared hosting) and separately in every tab.
- A form posted after the session had ended returns to the page it was posted from; background requests (JSON) report the login
  URL with that page.
- An expired login form (left open until the session was gone) shows the login page again with a note instead of "Form expired".
- Only pages of this installation are accepted as target (no other host, not the login / logout pages, no auto-login links).

## 0.8.111 – API playground
- API playground (Admin tools → API clients → API playground): choose a client and scope, send requests to api.php/v1 from the
  browser and see status, time, body and headers; copy a request as curl. The token is fetched for the client without its secret
  (logged with via: playground), or – to test the real token request – with the client secret, which is not stored.
- Endpoints list with example bodies; variables {{name}} in path, headers and body – own ones and {{last_id}}, {{last_etag}},
  {{last_status}} from the last response; If-Match sent automatically with the ETag last seen for the resource.
- Saved requests (name, folder, method, path, headers, body, client, scope), shared by the administrators, and settings per login
  (client, scope, variables, If-Match option) in the new table zzzzsys_nunext_api_request. Help: REST API → Playground.

## 0.8.110 – REST API (phase 1)
- REST API, phase 1 (api.php/v1/…, switched on with $nuConfigApi = true, HTTPS only): users (list with search / filter / paging,
  read, create, change, archive or delete, set password or send the welcome e-mail, permission items of a user), access levels with
  their form rights, permission items; GET /v1/me. JSON in and out, unknown fields are refused, ETag + If-Match on every change,
  errors as RFC 9457 problem+json.
- Authentication: OAuth 2.0 client credentials – client_id + client_secret (shown once) give a 15-minute access token
  ($nuConfigApiTokenMinutes); secrets and tokens are stored only as HMAC with $nuConfigSecretKey. Ten wrong secrets lock the client
  for 15 minutes; optional IP allowlist, requests per minute (429), expiry date; new secret with 24 h overlap, revoke all tokens.
- Permissions: the client's scopes (users:read/write/password, access:read/write, permissions:read/write) and the rights of its
  service user's access level (as in the browser). Service users cannot log in, and neither they nor their access level can be
  changed through the API.
- Admin tools → API clients: create clients (with a new service user), scopes, IP list, rate limit, expiry, new secret, revoke, delete,
  link to the client's requests in the log. Every token and request is logged.
- New tables zzzzsys_nunext_api_client, _api_token, _api_rate (created automatically). Help: chapter REST API.

## 0.8.109 – auto login by link (hash), back to the page after login
- Login: nuBuilder's auto login by link (wiki "Login"): index.php?u=<login>&p=<hash>[&f=<form>&r=<record>][&h=<home form>].
  p is a hash, never the plain password: HMAC-SHA256 of the stored password hash with $nuConfigSecretKey (the link stops working when
  the password changes). Switched on with $nuConfigAutoLogin = true (default off); not for the globeadmin; 2FA, expiry and the lock
  after wrong attempts apply; logged as login via url; the hash is removed from the address bar at once (no referrer). The globeadmin
  finds the link in the user form (Auto-login link, copy button). Without p the login page opens with the name filled in and the form
  opens after the login. A link that opens another user's session logs the current user out first.
- Login: a page opened while logged out (e.g. a bookmarked form or record) opens again after the login (also after two-step login).

## 0.8.108 – update: database structure check
- Update: the package check also compares the database structure with what the new version expects (install/schema_expected.json:
  nuBuilder 4.9's zzzzsys_ tables and nuBuilder Next's own tables). Missing columns and columns with a smaller type are listed and
  selected (default on) and are changed when you install, before the files are copied; columns with a larger or different type are
  listed separately and not selected (changing them could shorten data). Types are compared on MySQL / MariaDB; MSSQL and SQLite
  get missing columns only. Primary keys and extra columns are never changed; missing tables are listed, not created. Every change is
  logged (Log: column added / changed). The same check, for the installed version, is at the bottom of the update page.
- Note: the check of a package is done by the installed version, so it appears from the update after 0.8.108 on; the check at the
  bottom of the update page works right away.

## 0.8.107 – more nuBuilder PHP and JavaScript functions
- Form code: 112 more nuBuilder 4.9 PHP functions (core/form/nuphp_compat.php) and 130 more JavaScript functions
  (assets/js/nu/nucompat.js) for events, lookups and procedures, e.g.
  - PHP: db_fetch_value, db_update_value, db_fetch / db_fetch_all_array / db_fetch_key_pair_array, db_field_names / exists / types,
    db_primary_key, db_quote, nuHasNewRecordID, nuHasNoRecordID, nuRunProcedure, nuRunPHPHidden, nuGetPHP, nuDisplayMessage,
    nuJavaScriptCallback, nuSetJSONData / nuGetJSONData, nuObjKey, nuJsonDecode, nuEncode / nuDecode, the nuString… helpers,
    nuFormatDate, nuAddFormatting, nuSelectOptions, nuGetSQLValue, nuListTables, nuUser, nuGetUserAccess, nuPasswordHash,
    nuGenerateToken, nuGetIPAddress, nuSQL, nuGetSysJSONValue / nuSetSysJSONValue, nuAutoNumber / nuUpdateCounter.
  - JavaScript: nuSubformGetValue / SetValue / GetObjectId, nuSubformHide / Show / HideColumns, nuSubformSetHeight, nuAddSubformRow,
    nuSubformToClipboard, nuSubformEnableMultiPaste, nuSortSubform, nuSelectTabById / ByTitle, nuRemoveTabById / ByTitle,
    nuSelectNextTab, nuSelectToArray, nuSelectSelectAll, nuSelectSelectedValueArray, nuSelectRemoveOption, nuSetBrowseTitle,
    nuSetBrowseNoDataMessage, nuHighlightSearch, nuSortBrowse, nuGetPage, nuPrompt, nuRunPHPWithParams, nuSetAccess,
    nuCopyToClipboard, nuGetStorageItem / nuSetStorageItem, nuAddCSSStyle, nuEscapeHTML, nuLogout, nuAddBackButton,
    nuAddActionButtonSaveClose; nuSaveAction(true) saves and goes back to the previous breadcrumb.
- Input objects of type nuAutoNumber get the next number (sob_input_count) when a new record or subform row is saved.
- Compatibility check (Admin tools) counts the new functions.
- docs/porting-review.md: functions to review (behaviour differs or purpose unclear) and the nuBuilder functions that are not
  ported, with the reason. Developer reference in the help updated.

## 0.8.106 – procedures: hint behind (i) button
- Procedures list: the hint "PHP code started by run buttons and nuRunPHP – form events are edited in the form builder." is hidden;
  the small (i) button next to the title shows and hides it.

## 0.8.105 – lookups: description width 0 hides the description
- Lookups: a description width of 0 hides the description, as in nuBuilder (an empty width still means 150 px). Layout widths and the
  builder's resize handle take it into account.
- Notes: the category shows only once (its lookup has description width 0), and the label "Updated on" no longer overlaps the category.

## 0.8.104 – password change time (sus_json), nuSetUserJSONData
- Password changes are stored like nuBuilder in zzzzsys_user.sus_json: PASSWORD_CHANGED_TIME (Unix time) and PASSWORD_CHANGED_SOURCE –
  "user" (own change, as nuBuilder), "admin" (set in the user form) or "reset" (forgot-password / welcome link). The user form shows
  "Password last changed" with date and source under the password. Nothing is stored when the column sus_json is missing.
- Form and procedure code: nuSetUserJSONData($key, $value, $userId = '') and nuGetUserJSONData($key, $userId = '') as in nuBuilder
  (current user when $userId is empty; for the globeadmin a data row with only its user name is created, as nuBuilder does).
- Users list, start page count and permission items no longer show nuBuilder's globeadmin data row (a zzzzsys_user row without login name).
- Cloning a user no longer copies LOGIN_TOKEN, 2FA_…, PASSWORD_… and OTP_… from sus_json.

## 0.8.103 – lists: filters behind the filter button everywhere
- Lists: the filters are now hidden until the filter button (funnel) right of the search field is clicked – also in the e-mail log
  (status, from, to), Files (group), Formats (type), Items (group, active), Translations (language, source); they stay open while a
  filter differs from its default, and the × button resets search and filters. These lists use the common search field (search
  button, cursor in the field on a computer). Dates in the e-mail log apply with the search button or Enter, as in the log.
- Documentation: lists chapter mentions the filter button.

## 0.8.102 – e-mail templates list: filters behind the filter button
- E-mail templates list: the Group and Category filters are hidden until the filter button (funnel) next to the search field is clicked,
  as in the users list; they stay open while a filter is set, and the × button resets search and filters.

## 0.8.101 – procedures: code suggestions
- Procedures: the Code field suggests the codes of nuBuilder's global procedures (suggestion list with arrow): nu_before_save, nu_after_save,
  nu_before_edit, nu_before_browse, nu_before_delete, nu_after_delete, nu_startup, nu_check_password_policy, nu_invalid_login,
  nu_after_update, nu_send_welcome_email, nu_debug_result_added. Any other code can still be typed.

## 0.8.100 – e-mail templates: nuBuilder templates read-only, welcome template
- E-mail templates: nuBuilder's shipped templates (emt_template = 1) are read-only for everyone, the globeadmin included – view, preview
  and test only, no Save or Delete (also refused on the server). The switch "nuBuilder template" is gone. Clone makes an editable copy
  without the suffix _template, e.g. nu_send_welcome_email_template → nu_send_welcome_email; the template shows the code its clone gets.
  A clone keeps nuBuilder's form (e.g. nuuser) even when that form is not in the form list.
- Welcome e-mail: uses the e-mail template nu_send_welcome_email (as nuBuilder); nu_welcome_email of earlier versions still works.
  The template shows a hint with the available placeholders.

## 0.8.99 – users list: status "Active" by default
- Users list: the status filter has the new option "Active" (users that are not expired) right after "All", and it is the default –
  archived and expired users show with "All" or "Expired". The user counts in the access levels list open the users list with "All".

## 0.8.98 – user form: Delete / Archive dialog
- User form: the Delete button opens the same Delete / Archive dialog as the users list (also for users with only the Save right,
  who can archive). After archiving, the user form stays open with the new "Valid until"; after deleting, the users list opens.
- Documentation: users chapter updated (dialog in the form, searchable access level filter).

## 0.8.97 – users list: searchable access level filter
- Users list: the filter "Access level" is a searchable select (Tom Select) – type to search by code or description; the description is
  shown below each code. Choosing a level filters the list at once, as before.

## 0.8.96 – forms list: Tools menu
- Forms list: Code snippets, Cloner, CSV import, Prompt generator, Procedures, Items, Files, Translations and Formats are no longer
  separate buttons in the toolbar but entries of one "Tools" dropdown (only the entries the user may open; hidden when there are none).
  The toolbar now shows Print, Tools and Add.

## 0.8.95 – lists: button "Add"
- Lists: the button for a new record is called "Add" everywhere (as in form lists) – Users, Access levels, Forms, Formats,
  Procedures, Prompt generator, Permission items, Files, Items, Translations, E-mail templates; the tooltip still says what is
  added (e.g. "New user"). The users list uses the same plus icon as the others.

## 0.8.94 – users list: no Permission items button
- Users list: the button "Permission items" is removed. Permission items open from the start page tile and from "Manage" in the user form.

## 0.8.93 – documentation updated
- Documentation (Help): new chapters Inspect record, Cloner, App cloner and, in the developer reference, Info bar (nuInfoBar),
  Tooltip icons (nu-tooltip-icon), Charts (nuChart) and Button spinner (nuButtonLoading), with examples. Updated: lists (search
  focus), editing (Ctrl+Shift+S, suggestion lists, searchable selects, help icons), users (Delete / Archive, open access level,
  suggestions), access levels (Several…), builder (attribute editor, chart object type), prompt generator (Instruction, character
  count), sample data (tab New features), configuration ($nuConfigImportMaxMB, $nuConfigWelcomeLinkHours, own login page with
  $nuWelcomeBodyInnerHTML), debugging (Google Charts in the security policy) and the administrator shortcuts (the outdated note
  that Cloner and Prompt generator do not exist is gone). Code examples in the help have a frame.

## 0.8.92 – app cloner
- Admin tools → App cloner (globeadmin): nuBuilder's Application Cloner, adapted. Copies the whole application into another
  MySQL / MariaDB database – nuBuilder system tables and views, own tables and views, system records (nu…), application records,
  data of own tables, functions, procedures, triggers, events; all or chosen tables – and optionally the nuBuilder folder (with
  nuBuilder Next) into another folder, with the database settings changed in the copied nuconfig.php files. Dry run shows what
  would happen; "Download SQL" streams the database as an SQL file for an import elsewhere (e.g. shared hosting where databases
  cannot be created from PHP). Options: create the database, what to do when it is not empty (stop / add to it / empty it),
  character set and collation, INSERT / INSERT IGNORE / REPLACE, what to do with a non-empty folder (stop / copy over / empty),
  folders to leave out, same server and login as this database. Improvements: safety checks (never this database, no target
  folder inside the source or the other way round, nothing deleted outside the target); DEFINER removed from views, routines,
  triggers and events; views in dependency order; data read in pages and written in batches, binary data kept; sessions and
  one-time tokens are not copied (option); record counts compared at the end; routines the database user may not read are
  reported instead of failing; the log is shown while it runs. MSSQL / SQLite are not supported (nuBuilder's export to MSSQL
  is not taken over: its automatic conversion of tables and routines is unreliable).

## 0.8.91 – form cloner
- Forms → Cloner (globeadmin; also a button per form in the forms list): nuBuilder's cloner, adapted. Copies a form with tabs,
  browse columns, form PHP (BB, BE, BS, AS, BD, AD …), objects, object events and object PHP (e.g. lookup After Browse), nuBuilder's
  SQL builder rows when the tables exist, optionally its subforms and iframe (run) forms and the access level rights – as a
  new form or as new tabs into an existing form; or only as an SQL dump (copy / download .sql, notes, keep primary keys,
  REPLACE INTO). Improvements: one transaction (an error changes nothing); code and description of the new form can be chosen
  (default <code>_clone_<n>, the first free number, checked for duplicates); tabs chosen by id; subform / iframe references are
  changed in the copied objects only (nuBuilder's cloner changed every form that used the subform) and a subform used twice is
  copied once; into an existing form the tabs come after its own and object ids that exist there get _2, _3 … (listed); object
  PHP keeps its code; the result lists the copied rows per table with links to the form and the form designer; the change is
  in the form history and the log.

## 0.8.90 – chart objects (nuChart, Google Charts)
- Forms: object type chart as in nuBuilder (builder: type Chart with chart type, JavaScript array, title, vertical and horizontal
  label – the same columns sob_html_chart_type / _javascript / _title / _vertical_label / _horizontal_label). Chart types pie, line,
  bars vertical / horizontal, each bar type also stacked (nuBuilder's codes p, l, b, bs, bh, bhs). The chart is drawn with
  Google Charts by nuChart(chartId, chartType, dataArray, chartTitle, xAxisTitle, yAxisTitle, seriesType, isStacked) – same
  function and parameters as nuBuilder, plus an options object as 9th parameter; window[id + '_wrapper'] and
  nuChartOnReady(id, wrapper) as nuBuilder. Improvements: the data may be the name of an array (also obj.data, let / const), an
  array literal, an array, a function or a DataTable; the Google loader is loaded once and only on forms that use charts
  (www.gstatic.com is allowed in the Content-Security-Policy only there); charts in a hidden tab are drawn when the tab opens
  and redrawn when their size changes; colours and font follow the page, also after a light / dark switch; title and labels
  may contain hash variables; the vertical label works (nuBuilder reads it from a misspelt column); a message instead of an
  empty box when the data is missing, no chart type is chosen or Google Charts cannot be loaded; returns a Promise.
- Sample data "all objects", tab New features: three charts – bars from an array in the form JavaScript, a pie with orders per
  status from the database (Before Edit PHP with nuAddJavascript) and horizontal stacked bars with the array in the object.
  Sample version 8.

## 0.8.89 – access level: add several forms
- Access level, form rights: the button "All" is replaced by "Several…". It opens a dialog to add many forms at once: search
  (code, description, table, id), filter by form group (sfo_group, when the column exists), type and own / nuBuilder system
  forms; tick single forms or "Select all shown"; forms already in the list are hidden (or shown greyed out); choose the rights
  the added forms get (all ticked by default, e.g. untick Delete). Added rows can still be changed before saving.

## 0.8.88 – sample: tab "New features"
- Sample data "all objects": new tab "New features" with tooltip icons (nu-tooltip-icon with themes and positions, a field
  changed in nuOnToolTipIcon with onClick, nu-tooltip on the field itself), info bar buttons (all types stacked, dark with shake,
  Yes / No, modal, bottom with fade, close all) and nuButtonLoading (auto-enable after 3 s, spinner until a task is done, a
  procedure button), plus a note where to find Inspect record, the attribute editor and Ctrl+Shift+S. Sample version 7:
  Admin tools → Sample data offers "Refresh".

## 0.8.87 – tooltip icons (nu-tooltip-icon)
- Forms: tooltip icons as in nuBuilder – a form object with the HTML attribute nu-tooltip-icon="text / HTML" gets a help icon on
  its right; hover, focus or tap shows the text in a tooltip with an arrow. Per object: nu-tooltip-position (top / bottom / left /
  right), nu-tooltip-theme (light, dark, blue, green, purple, red, orange), nu-tooltip-arrow-size (small / default / large);
  nu-tooltip="…" shows the tooltip on the field itself. Same API: nuAttachToolTip(options), nuAttachToolTip.refresh(),
  .destroy(); nuOnToolTipIcon(element, settings) changes theme, iconSize, gapRight, tooltipPosition, animationDuration,
  iconClasses, onClick per field; nuOnToolTipIconClick(element, text). Improvements: the icon sits inside the object, so it
  moves, hides (nuHide, tabs) and scrolls with the field; lookups get it after the description, select2 after the visible box;
  fields added or removed later are handled by themselves; the tooltip flips to the other side when there is no room and stays
  on screen; the mouse can move into it (links); a click / tap pins it, Escape or a click elsewhere closes it; Bootstrap Icons
  by default; theme light follows light / dark mode; the text is cleaned (simple formatting, http(s) links only). The attribute
  editor suggests the new attributes; the compatibility check lists nuAttachToolTip as supported.

## 0.8.86 – Inspect record
- Forms (globeadmin): options menu → "Inspect record" opens the record of the edit form in a new tab, as nuBuilder's Tools →
  Inspect Record: every column in table order with data type (NULL / NOT NULL, default and comment as tooltip) and the stored
  value. Sort by #, column, type or value (empty / NULL last); show all, only columns with a value or only empty / NULL ones
  (remembered); search in names, types and values; copy a value or the whole record as JSON. Keys: primary key, real foreign
  keys (→ table.column), unique and normal indexes; nuBuilder-style references (…_<table>_id) are marked as probable links.
  NULL and an empty string are shown differently; long values are cut to one line with a preview and open on click (JSON
  pretty printed, with character count); binary columns show their size; values of password / secret / token columns are
  blurred until the eye is clicked. MySQL / MariaDB, MSSQL and SQLite. Read only. Not offered for new records.

## 0.8.85 – nuButtonLoading
- Forms: nuButtonLoading(button, spinning = true, autoEnableAfterSeconds = 0) as in nuBuilder: shows a spinner on the button and
  disables it while a background process runs; nuButtonLoading(button, false) ends it, autoEnableAfterSeconds ends it by itself.
  Works on Run buttons and own <button> elements (Bootstrap spinner in place of the icon, before the label) and on input objects
  of type button (<input type="button">, animated spinner inside the button). Improvements: button as id, element or jQuery
  ($(…).nuButtonLoading() too); the width stays the same; aria-busy for screen readers; a disabled button does not start (as
  nuBuilder) and is not enabled by the end call; calling it again while spinning only restarts the auto-enable time; a removed
  button is ignored; no Font Awesome needed. Returns the button.

## 0.8.84 – builder: attribute editor
- Form builder: next to "HTML attributes" of the selected object a button opens an attribute editor (adapted from nuBuilder's).
  One row per attribute with name (suggestions) and value; known attributes get a selection (autocomplete, spellcheck, inputmode,
  autocapitalize, enterkeyhint, dir, nu-label-position) or no value (readonly, required, autofocus, disabled, multiple,
  nu-disable-calendar). Checks while typing: invalid names, duplicates, event handlers (on…, use the object's events), whole
  numbers (maxlength, minlength, tabindex, rows) and one character for accesskey. The result is shown below and written back as
  name="value", … (values with " are put in single quotes). Enter adds a row, Ctrl+Enter applies; Bootstrap dialog, light / dark,
  English / German. Save the object as usual.
- Forms: HTML attributes keep quotes inside a value (data-x='a "b"'); only one pair of surrounding quotes is removed.

## 0.8.83 – nuInfoBar
- Forms: $.nuInfoBar – nuBuilder's info bar (assets/js/nu/nuinfobar.js), same API and options: $.nuInfoBar(options), .info / .success /
  .warning / .error(message, options), .modal(…) and .modal.info / … , .shake(), .shakeLast(), .closeAll(), .updateContent(),
  .fixed(), $(…).nuInfoBar(); types, light / dark theme, icons, customClass, autoClose, close button, click to close,
  slide / fade up / down, top / bottom, fixed or in the form area, zIndex, opacity, shake, modal, onShow / onClose / onClick / onShake.
  Improvements: several bars are stacked instead of overlapping; closing (also closeAll) uses each bar's own settings; fixed bars
  push the page, the sticky navbar and the action bar by their real height and give the room back; modal bars have a backdrop
  (a click on it shakes the bar) and keep the focus; auto-close pauses while the mouse or the focus is on the bar; Escape closes
  a bar with a close button; screen reader roles (status / alert / alertdialog); no motion with "reduce motion"; theme light
  follows light / dark mode; Bootstrap Icons by default (Font Awesome classes work when nuBuilder's Font Awesome is loaded);
  every call returns the bar, plus $.nuInfoBar.close(bar) and .count(). Forms open as new pages, so bars disappear with their
  form; with hideOnFormClose: false a bar is shown again on the next page of the tab (without callbacks). The compatibility
  check lists nuInfoBar as supported.

## 0.8.82 – buttons attached to a field: field border
- Buttons attached to a field (input group: open access level, show / generate password, search) have the same border colour
  as the field again instead of the darker #6c757d; standalone outline buttons keep #6c757d.

## 0.8.81 – user form: open access level, button borders
- User form: next to "Access level" a button opens the chosen access level in a new tab. It follows the selection and is
  disabled while none is chosen; shown only to users who may open access levels.
- Buttons: every .btn-outline-secondary has the border colour #6c757d, also the row, tool, search / filter and input-group
  buttons that had lighter borders (light and dark mode).

## 0.8.80 – Ctrl+Shift+S saves on all edit pages
- Ctrl+Shift+S (Mac: ⌃⇧S) saves on nuBuilder Next's own edit pages too, as in nuBuilder forms: user, access level, permission
  items, procedures, prompt generator, e-mail settings and templates, files, items, translations and change password. It works
  while typing in a field or the code editor (the field is committed first) and goes through the normal Save (validation,
  confirmations). Inside an open dialog it only saves when the dialog has its own Save button. The tooltip of the Save button
  shows the shortcut. (Until now only nuBuilder form pages had it.)

## 0.8.79 – prompt generator: Instruction first, no token count
- Prompt generator: the field "Instruction" comes before "Prompt" (the order of the complete prompt); the cursor still starts
  in "Prompt" for a new prompt. Above the complete prompt only the number of characters is shown, no token estimate.

## 0.8.78 – sample: image no longer overlapped
- Sample data "all objects", tab Display: the HTML object (3 lines) is 100px instead of 60px high and the image, "Styled (CSS)" and
  "Hash variables" move down, so the HTML box no longer covers the image. Sample version 6: Admin tools → Sample data offers
  "Refresh" for existing installations.

## 0.8.77 – select2 multiple: items no longer cut off
- Forms: in searchable multiple selects (select2 / Tom Select) the chosen items (Red × Blue ×) were cut off at the bottom of the
  26px field. The field is now 30px high and grows when the items need more lines; the empty search field only takes room
  while the field has the focus (typing). Also in subforms.

## 0.8.76 – renamed to nuBuilder Next
- The product is now called "nuBuilder Next" (screens, page titles, e-mails, language files, documentation, README, comments,
  log messages, default 2FA issuer and sender name). Technical names stay the same, so existing installs, updates and backups
  keep working: folder and file names (nunext, nunext-manifest.json, nunext-docs.htm), tables zzzzsys_nunext_*, the session
  cookie, browser storage keys, the JavaScript objects window.nuNext… and "product": "nuNext" in the update manifest and backups.

## 0.8.75 – select2 dropdown on top
- Forms: the dropdown of searchable selects (select2 / Tom Select) opened behind the following objects, because every
  form object has its own z-index, and was cut off in subforms. It is now attached to the page (dropdownParent body,
  z-index 1070, above all objects and below tooltips), keeps the small font of the form, and follows its field when the
  form or a subform scrolls (it closes when the field scrolls out of view).

## 0.8.74 – suggestion lists: arrow shows all values
- Inputs with a suggestion list: clicking the arrow now opens the full list even when the field has a value (browsers only list
  matching options). As nuBuilder's nuDatalistShowAllOnArrowClick, the value is moved into the placeholder and the field
  emptied while the list is open. Picking or typing keeps the new value; clicking into the text, a second click on the
  arrow, Escape, leaving the field or submitting the form brings the old value back, without marking the form as changed.
  For all datalist inputs, no per-field call needed.

## 0.8.73 – suggestion lists: arrow always visible
- Inputs with a suggestion list (HTML5 datalist, e.g. Position, Department, Team in the user form) always show an arrow, like
  a select, in light and dark mode. Clicking the arrow opens the list (showPicker); typing still filters it. Chrome draws
  its own arrow only on hover and Bootstrap hides it, so CSS on ::-webkit-calendar-picker-indicator has no effect; the
  arrow is a background image like Bootstrap's .form-select. With a validation error, the error icon is shown instead.

## 0.8.72 – user form: suggestions from active users
- User form: the suggestion lists (HTML5 datalist) of Position, Department and Team only contain values of other, active users
  ("Valid until" empty or today or later; archived and expired users are left out). Values are trimmed, listed once
  (ignoring upper / lower case) and sorted alphabetically.

## 0.8.71 – users: Delete / Archive, password buttons
- Users list: the row button is now "Delete / Archive" and opens a dialog with both options. Archive sets "Valid until" to
  yesterday: the user can no longer log in (open sessions end on the next click) and all data stays; to reactivate, change
  or clear "Valid until". Archive needs the Save right, Delete the Delete right; for users who are already expired, Archive
  is disabled. Your own account has no button. After Delete or Archive, the list comes back with the same search,
  filters, sort and page. New log event "User archived" (with the previous date).
- User form: the "Generate password" button has the same border and colours as "Show password". Password fields on the user
  form, Change password and Reset password no longer show a rounded corner between the input and the buttons when there is
  no error (Bootstrap's has-validation is only set together with an error message).

## 0.8.70 – lighter input focus ring
- Inputs: lighter focus ring (`.form-control:focus { box-shadow: 0 0 0 .1rem rgb(140 180 239 / 8%) }`), also on the inputs of an own login page.

## 0.8.69 – own login page ($nuWelcomeBodyInnerHTML), search focus, thinner tab line
- Login: when `$nuWelcomeBodyInnerHTML` is set in nuconfig.php (same variable as nuBuilder), its HTML replaces the login card.
  nuBuilder's markup works as it is: the inputs `#nuusername` / `#nupassword` and the button are wired to nuBuilder Next's login by
  `assets/js/nulogincustom.js`, because inline `onclick` / `onsubmit` handlers stay blocked. The CSRF field is added to
  every form, `style="…"` is allowed on this page, and image paths `core/graphics/…` point to nuBuilder's folder
  (`$nuConfigNuBuilderURL` or `../`). nuBuilder's "SSO Log in" button is hidden. Error messages, "Forgot password?",
  the theme and language menus and the version stay. nuBuilder's classes `nuLogin`, `nuLoginInput` and `nuButton` get
  nuBuilder Next styling. nuconfig-sample.php contains a commented-out example.
- Tabs: the line above the active tab is 1px instead of 2px.
- Lists (Users, Access levels, Forms, Procedures, Permission items, Log, Debug, Prompts): the search field has the focus when the
  page opens, on desktop only (no keyboard popping up on phones and tablets).

## 0.8.68 – notes: no "Content" label
- Notes edit form: the label "Content" above the editor is no longer shown. Done in nuBuilder Next (`NuRender::HIDDEN_LABELS`), so nuBuilder's form in
  the database stays unchanged.

## 0.8.67 – e-mail templates: placeholders with label
- E-mail templates: a form field inserted into the **body** comes with its label in bold – `<b>Field Id: </b>#field_id#` (a colon at the end of the
  label is not doubled, the label is HTML-escaped). Subject, To, Cc, Bcc and the system placeholders (#USER_NAME# …) still get only `#field_id#`.

## 0.8.66 – note categories, Add in lookups
- Notes list: new **Categories** button (form `nunotescategroy`, with the Categories right) – before, categories could only be reached by URL.
- Lookup windows (all forms): **Add** is available when the lookup's form allows new records; after saving, the new record is chosen in the
  field that opened the lookup and the window closes – e.g. a new category straight from a note.

## 0.8.65 – CSV import
- New **CSV import** (Admin tools card and Forms toolbar, globeadmin only), based on the nuBuilder CSV import – rebuilt with prepared statements:
  - File: upload or drag & drop; encoding detected and converted (UTF-8 / BOM, UTF-16, Windows-1252), separator detected (`;` `,` tab `|` or own),
    text qualifier, header row; preview of the first 10 rows.
  - Mapping: target table (not `zzzzsys_…`), auto-mapping by name (common column prefix, German / English synonyms, similarity), CSV column,
    fixed value or generated nuID() per column; date format per column (detected; also Unix timestamp and Excel serial number); decimal
    separator (detected, thousands only in groups of three – no silently wrong numbers); yes/no → 1/0; length, required and type checks;
    live preview of the converted values.
  - Modes: insert, update existing (key column), update or insert; empty → NULL or empty text; trim; created/updated audit columns.
  - **Check**: complete trial run in a transaction that is rolled back – exact counts and problems (also database errors like duplicate keys),
    downloadable as CSV. Import: **all or nothing** (default, one transaction) or skip faulty rows (savepoint per row).
  - Save / load the configuration as JSON (matched by column names). Temp file private, outside the web root, deleted after the import.
    Log events CSV imported / CSV import failed. Setting `$nuConfigImportMaxMB` (default 20).

## 0.8.64 – prompt generator
- New **Prompt generator** (Forms toolbar, start page tile, Admin tools card; rights on nuBuilder's form `nupromptgenerator`), like nuBuilder's,
  stored in `zzzzsys_prompt_generator` (shared with nuBuilder, created if missing). nuBuilder Next does **not** call an AI – the prompt is copied.
  - Prompt, Languages, Scopes, Topics, Tables (column list with types, NOT NULL, defaults, keys – MySQL, MSSQL; no data), Instruction,
    Code (only for saving), Description, Template. Lists stored as JSON arrays of nuBuilder's codes.
  - **Complete prompt** next to the fields, updated while typing (instruction → context → request last), Copy button, character and token count.
  - Magic wand: suggests topics, languages and scopes from words in the prompt (English and German) – no AI.
  - Improved notes (parameterised nuRunQuery, nuDisplayError in Before Save …), new scopes After Delete and SQL, language SQL Server.
  - List with search, Template filter, Print / CSV; Clone (new code `…_2`), Delete; prefill via `?p=prompts&id=new&table=…&scope=…&lang=…`.

## 0.8.63 – forms list: coloured form types
- Forms list: the Type badge has its own colour per type – Browse and edit blue, Browse cyan, Edit green, Launch amber, Subform violet
  (light and dark theme; classes `nuFormType-…`). Subform descriptions are blue like the others; with the builder right a subform row opens
  the form builder (subforms cannot run on their own).

## 0.8.62 – user form: welcome e-mail
- User form: new switch **Send welcome e-mail** (when e-mail is set up). After saving, the user gets the login name and a personal
  link to set the password (valid `$nuConfigWelcomeLinkHours`, default 72 hours, once) – no password in the e-mail. A new user then needs no
  password in the form (random unknown one until the link is used); an e-mail address is required. Like nuBuilder's After Save:
  own procedure `nu_send_welcome_email` first, else e-mail template `nu_send_welcome_email` (clone of nuBuilder's `nu_send_welcome_email_template`; `nu_welcome_email` also works; user's language first), else a built-in text in the
  user's language. Placeholders: `#sus_…#` fields, `#SET_PASSWORD_URL#`, `#SET_PASSWORD_HOURS#`, `#LOGIN_URL#`, `#NUNEXT_URL#`, `#NUBUILDER_URL#`,
  `#PASSWORD_MIN_LENGTH#`, `#check_password#` (old templates). Values are HTML-escaped in templates. Log events Welcome e-mail sent / failed.

## 0.8.61 – user form: password generator
- User form: new button (magic wand) next to the password field opens a **password generator**: password with New and Copy buttons,
  strength bar (bits), length 8–64 (slider and number; at least `$nuConfigPasswordMinLength`), switches for upper case, lower case, numbers,
  symbols and "avoid look-alike characters" (0 O o 1 l I |). At least one character of every chosen kind, secure random numbers
  (crypto.getRandomValues, no bias), symbols that are safe to paste (no quotes, backslash, < >). **Use password** fills password and
  confirmation and copies it to the clipboard. Created in the browser; settings remembered per browser. Only with the Save right.

## 0.8.60 – user form: full name fills first and last name
- User form: changing **Full name** fills First name and Last name for a new user ("Anna Maria Muster" → Anna Maria / Muster,
  "Muster, Anna" → Anna / Muster); parts typed by hand are kept. Existing users: the parts change only on request – type `*` into
  First or Last name. With an empty full name, it is built from first and last name (and follows them until typed by hand).
  The full name is tidied to single spaces. Functions `nuUserSplitFullName()` and `nuUserSetNameParts()` in assets/js/nuuser.js.

## 0.8.59 – user form: Full name, required
- User form: "Display name" (`sus_name`) is now called **Full name** (German "Vollständiger Name") and is required (red *).
  Existing users without one need it the next time they are saved in nuBuilder Next; nuBuilder is not affected.

## 0.8.58 – lower list rows
- nuBuilder Next's list tables (Users, Access levels, Forms, Procedures, Log, Items, Translations, Files …): cell padding above and below
  .5rem instead of .8rem – rows about 10 px lower (users list: 49 px instead of 58 px). Header row and form browse lists unchanged.

## 0.8.57 – search fields without label
- All list pages (Users, Access levels, Forms, Procedures, Permission items, Log, Debug, Items, Translations, E-mail templates, Files, Formats,
  e-mail log) and form browse lists: the "Search" label above the search field is gone; the field shows "Search…" as placeholder.
  The former hints (e.g. "Login, Name, E-Mail …") show as tooltip on the field.

## 0.8.56 – users list: light blue access level badge
- Users list: the access level badge is light blue (background #eaf4ff, border #dcd7ff; darker blue in the dark theme). Class `nuAccessBadge`.

## 0.8.55 – Admin tools: clickable cards
- Admin tools: the Update, Sample data and Compatibility check cards no longer have buttons – the whole card opens the page
  (hover effect and arrow like the start page tiles). The InnoDB card keeps its Convert button (it runs an action).

## 0.8.54 – Update page: Check package only with a file
- Admin tools → Update: the **Check package** button appears only once a zip file is chosen (and hides again when the choice is cleared).

## 0.8.53 – code editor toolbar: # button same size
- Code editor toolbar: the # button (hash variables) has the same size and vertical position as the other tool buttons (Undo, Find, Format …).

## 0.8.52 – users list: Clone instead of Edit
- Users list: the Edit (pencil) button in each row is gone – clicking the row opens the user. In its place a **Clone** button
  (with the Clone right) opens a copy of that user, as Clone in the user form. Delete stays.

## 0.8.51 – procedures, permission items, log, debug: search and filter like the users list
- Procedures, Permission items, Log and Debug: search button at the right end of the search field (searches on click, Enter as before);
  the filters (Procedures: group, status · Permission items: group · Log: event, user, from, to · Debug: flag, user, from, to) are hidden at first
  and shown with the filter button (funnel) right of the search button – open and blue when one is set. The separate Filter buttons are gone;
  text and date filters apply with the search button or Enter. New: × (reset) when a search or filter is set.

## 0.8.50 – forms list: search and filter like the users list
- Forms list: search button at the right end of the search field, the separate Filter button is gone; the Type filter is hidden at first
  and shown with the filter button (funnel) right of the search button – open and blue when a type is set. × (reset) when a search or type is set.
- Users, Access levels and Forms share the new helpers `nu_list_search()` and `nu_list_reset()` (core/nulayout.php).

## 0.8.49 – start page tiles: normal weight
- Start page of the globeadmin: the tile names (Users, Access levels, Forms …) keep the larger size but are no longer bold (font weight 400).

## 0.8.48 – access levels list: search and filter like the users list
- Access levels list: search button at the right end of the search field (searches on click, Enter as before), the separate Filter button is gone.
  The Group filter is hidden at first and shown with the filter button (funnel) right of the search button; when a group is set it stays open
  and the button is blue. The × (reset) shows when a search or group is set.

## 0.8.47 – users list: filters behind a button
- Users list: the Access level and Status filters are hidden at first; the filter button (funnel) right of the search button shows and hides them.
  When one of them is set, they stay open and the button is blue (filled funnel).

## 0.8.46 – print view: Copy instead of Print
- Print view (the new tab from a list's Print button): the Print button is replaced by **Copy** – it puts the table on the clipboard
  as HTML (pastes as a table into Excel, Word, e-mail) and as tab-separated text; only the visible columns. The button shows "Copied" for a moment.
  Printing still works with the browser's print command (Ctrl+P). Export CSV stays.

## 0.8.45 – Print and CSV like nuBuilder
- Lists (nuBuilder Next's own lists and form browse lists): only a **Print** button – it opens the list as an HTML table in a new tab
  (no automatic print dialog any more; the form's header, buttons and pager are not shown there). The separate CSV button is gone.
- On that page: **Print** prints the table, **Export CSV** opens a dialog – delimiter (semicolon, comma, tab, pipe; default `$nuConfigCSVSeparator`),
  file name, column titles in the first row, UTF-8 BOM for Excel – and then starts the download. The choices are remembered per browser.
- `?p=…&export=csv` and `?p=formexport` take the options as `sep`, `head`, `bom`, `name` (without them: as before).

## 0.8.44 – users list: department and team once when equal
- Users list: when department and team are the same (ignoring upper/lower case), the column shows the value only once ("Sales" instead of "Sales / Sales").

## 0.8.43 – users list: new column order
- Users list: columns Name, Department / Team, Access level, Login name, E-mail. The name is the link to the user and the default sort.
  "Valid until" is no longer shown (the Status filter "Expired" stays; Print and CSV still contain the date).
  E-mail only on large screens (from 992 px), not on phones and tablets. Print and CSV use the same column order.

## 0.8.42 – phones: list pages no longer wider than the screen
- Phones: on list pages (Users, Access levels, Forms …) the page could be swiped sideways and down into empty space.
  Cause: the hidden "Actions" column header (`.visually-hidden`, absolutely positioned) escaped the table's scroll box and widened the page.
  `.table-responsive` is now `position: relative`, so only the table scrolls sideways inside its card.

## 0.8.41 – Admin tools: tool cards only when missing
- Admin tools: the phpMyAdmin and Tiny File Manager cards only appear while the tool is not installed (with the folder to copy it to);
  installed tools open from the start page tiles and the user menu as before. The note "Opens in a new tab …" is gone with them.

## 0.8.40 – start page tiles without counts
- Start page of the globeadmin: the tiles no longer show record counts (no COUNT queries on opening the page);
  the tile names (Users, Access levels, Forms …) are larger and bold, the short hint stays below.
  Other users with rights on Users / Access levels still see the counts.

## 0.8.39 – globeadmin session timeout, simpler login screen
- New setting `$nuConfigSessionTimeoutGlobeadmin` (minutes, default 720 = 12 hours): inactivity timeout for the globeadmin.
  Everybody else keeps `$nuConfigSessionTimeout` (default 120); a globeadmin acting as a user gets the user timeout.
  Applies to the sign-out, the list on *Log → Logged-in users* (its hint shows both values) and the phpMyAdmin / File Manager logins.
- PHP keeps idle sessions at least as long as the longer timeout (`session.gc_maxlifetime`; PHP's default of 24 minutes ended sessions early).
- Login screen: the line "Please sign in with your nuBuilder account." is gone.

## 0.8.38 – tabs like nuBuilder, modernised
- All tabs (form tabs at runtime and every nuBuilder Next page): each tab a separate rounded box with border and light grey background, small gap;
  the active tab is white with blue bold text and a blue line on top, joined to the content below. Hover: darker grey, blue text. Dark theme included.

## 0.8.37 – users list: search button in the field
- Users list: the search icon sits at the right end of the search field and searches when clicked (Enter as before).
  The Filter button is gone – Access level and Status filter at once; the × (reset) shows when a filter is set.

## 0.8.36 – list pages as one card
- List pages (Users, Access levels, Log, Debug, Items, Procedures, Permission items, Forms; filters + table on Translations and the e-mail log):
  one card with the title and buttons in its header, the filters below (small uppercase labels), then the table and the footer (unchanged).
- Tables: thin dividers between rows instead of stripes, roomier cells, header band #f8fafc with lines above and below, accent tint on hover
  for rows that open something. Phones: cells stay on one line, the table scrolls sideways.
- Users list: row buttons Edit (pencil) and Delete as small square buttons (Clone stays in the user form).

## 0.8.35 – user form: Person first
- User form: the Person card comes first (left), Login & access second (right; below on small screens).

## 0.8.34 – softer border on list toolbar buttons
- Print, CSV and the link buttons above lists (Permission items on Users; Snippets, Procedures, Items, Files, Translations, Formats on Forms):
  border #c9d1d8 (dark theme #3a4652). Class `nuToolBtn`.

## 0.8.33 – globeadmin: only Home in the top bar
- The globeadmin's top bar shows only Home; Users, Access levels, Forms, Log, E-mail and Notes are reached through the tiles on the start page.
  Other users keep their menu entries.

## 0.8.32 – list tables: zebra stripes and hover
- All list tables (nuBuilder Next lists, form browse lists, "My forms" on the start page) after the table style specification:
  no lines between rows, every second row with a light stripe (`rgba(127,140,155,.09)`), rows that open something get an accent tint on hover
  (light `rgba(47,111,206,.12)`, dark `rgba(77,159,255,.14)`); other rows no hover. Header: same layout, colours from the spec
  (background #f4f6f8 / #0f1419, text #5b6773 / #8b98a5, bottom line #d7dde2 / #2c3742). Subform grids and editing tables are unchanged.

## 0.8.31 – Database and File Manager tiles
- Start page of the globeadmin (MySQL): tiles **Database** (phpMyAdmin) and **File Manager** (Tiny File Manager) – they open the tool in a new tab
  as the user menu does. Not copied yet → the tile says so and opens Admin tools.

## 0.8.30 – start page tiles for the globeadmin
- Start page of the globeadmin: besides Users and Access levels also tiles for Forms, Procedures, Permission items, Log (opens Debug),
  E-mail, Notes, Logged-in users and Admin tools – each with its count and a short hint (the top menu stays as it is).

## 0.8.29 – label menu for the globeadmin
- Globeadmin, edit form (not nuBuilder system forms): a click on an object's label opens a menu, as in nuBuilder:
  - **Object: <id>** – click copies the object id to the clipboard (on http without clipboard access: shown selected in a prompt)
  - **Properties…** – opens the object in the form builder (new tab, `?p=builder&f=<form>&obj=<object id>`)
  - **Rename…** – new label
  - **Actions** → **Delete** (after a confirmation; the table column stays) · **Move to tab** → the other tabs of the form
  - **Access** (Editable, Read only, Hidden, Hidden for users, Hidden for users / read only for globeadmin) · **Align** (Left, Center, Right) ·
    **Validation** (None, No blanks, No duplicates, No blanks / no duplicates) – the current value is bold with a check mark
- Changes are saved through the builder API (rights on `nuform`) and the form reloads; unsaved record changes are asked about first.
- Submenus open on hover, by click (touch) and with the arrow keys; Esc closes a submenu, then the menu. Other users: labels work as before.

## 0.8.28 – Debug first in Log
- Menu Log: tabs in the order Debug · Log · Logged-in users; the menu entry opens Debug (users without Debug rights get the Log).

## 0.8.27 – no Browse tab for edit and launch forms
- Form settings: the Browse tab is hidden for the types Edit and Launch (also right after changing the type). Its values stay unchanged.

## 0.8.26 – taller code editors in the builder
- Form settings → Browse: the browse SQL editor is about 16 lines high (was 3 – 4). All builder code editors now start at the height
  their field asks for (e.g. Code panel 18 lines, HTML 8) and still grow with the content up to 62 % of the window.

## 0.8.25 – form settings in tabs
- Form builder → Form settings: two tabs, **General** (description, code, type, group, status, table, primary key, breadcrumb, layout) and
  **Browse** (browse SQL, rows per page, form that opens the records). The open tab stays open after saving; one Save stores both tabs.

## 0.8.24 – full screen code editor, no record count above lists
- Code editor toolbar (form builder, procedures, double-click editor): new button **Full screen** (also F11; Esc or F11 ends it).
  Builder and procedures: label, toolbar and editor fill the window. Double-click editor: the dialog becomes full screen (the separate
  button in its header is now in the toolbar; Esc still closes the dialog).
- The number of records above lists (under the title, in the form browse search bar, e-mail log, translations) is gone – the list footer shows it.
  The print view of a form list keeps it.

## 0.8.23 – list footer
- Every list (form browse, users, access levels, log, debug, e-mail log, items, files, translations, procedures, permission items) has the same
  footer: "Total: N records" · ‹ Previous | Page x / y | Next › · "N per page". Always shown – also with only one page – and sticky at the
  bottom of the window while the list is longer than the screen. On phones the words Previous / Next / records are left out.
- New helper `nu_list_footer()` in `core/nulayout.php` (replaces the page-number pagination on these pages).

## 0.8.22 – visibility and enabling as in nuBuilder
- Ported from nuBuilder (nucommon.js) and adapted to nuBuilder Next's form: `nuObjectComponents()`, `nuEnable(id, enable)`, `nuDisable()`,
  `nuReadonly(id, readonly = true)`, `nuShow(ids, visible, openTab)`, `nuHide()`, `nuRemove()`, `nuIsVisible()`, `nuIsHidden()`, `nuIsEnabled()`,
  `nuIsDisabled()`, plus `nuSetFocus()` and `nuSelectRemoveEmpty(id, setIndex)`.
- Hide / show: every object has a wrapper `#nuw_<id>` (label + control); `nuHide()` gives it the class `nuHidden`, `nuShow()` removes it.
  It stays hidden when tabs change (nuBuilder's `data-nu-tab="x…"` marker is not needed, `openTab` is accepted and ignored).
  Subform cells without a wrapper hide their parts. `nuShow()` also shows objects hidden by the access setting or by `$().hide()`.
- Lookups: code, button and description together. Tom Select fields are enabled / disabled with their select.
- `nuReadonly()`: text fields read only (copyable); selects, checkboxes, buttons and files cannot be read only and are disabled.
  `nuReadonly(id, false)` undoes it. `nuDisable()` calls nuBuilder's hook `nuOnDisableGlobal(id, $component)` (return false = keep usable).
- `nuIsDisabled()` is also true for fields read only by the object's access setting.
- `nuSetValue(id, value, method, change)`: `change === false` sets the value without a change event. `nuSetPlaceholder(id, text, translate)`:
  without text the label is used. `nuSetLabelText(id, text, translate)`.
- `nuSetFocus()`: opens the tab of the object first; lookups get the focus on the code field.

## 0.8.21 – nuBuilder jQuery plugins
- Form runtime: nuBuilder's jQuery plugins (`jQuery.fn.extend` from nucommon.js) – `$('#id').nuEnable()`, `.nuReadonly()`, `.nuDisable()`, `.nuShow()`,
  `.nuHide()`, `.nuRemove()`, `.nuSelectRemoveEmpty()`, `.nuGetValue()`, `.nuSetValue()`, `.nuGetText()`, `.nuSetText()`, `.nuGetHTML()`, `.nuTranslate()`,
  `.nuRemoveFormatting()`, `.nuIsVisible()`, `.nuIsHidden()`, `.nuIsDisabled()`, `.nuIsEnabled()`, `.nuSetFocus()`, `.nuSetPlaceholder()`,
  `.nuSetLabelText()`, `.nuTogglePassword()`. Each calls the global function with the element id, as in nuBuilder (replaces the simple `.nuHide()` / `.nuShow()`).
- `nuRemove()`, `nuSelectRemoveEmpty()` and `nuSetFocus()` are not ported yet: they write a console warning until they follow.

## 0.8.20 – less repetition in page headers
- Breadcrumbs no longer repeat the current page (it is the heading right below) – only the way back is shown, e.g. "Users" above "sample_ben".
  Applies to all nuBuilder Next pages with breadcrumbs (not the form runtime, which has its own).
- User form: "ID …" is only shown when the record id differs from the login name.

## 0.8.19 – no footer
- The footer "nuBuilder Next <version> · MySQL" below every page is gone. The version stays visible for the globeadmin under Admin tools (Update) and in the form options menu (Version).

## 0.8.18 – start page
- Start page of the globeadmin: the sentence "You have unrestricted access." is gone (the Globeadmin badge stays).

## 0.8.17 – act as user (globeadmin)
- The globeadmin can work as another user: **Act as user** in the user form (next to Clone) and in each row of Log → Sessions (nuBuilder Next and nuBuilder
  sessions). The globeadmin chooses each time: **View only** (no save / delete / clone / new, no procedures, no form posts – checked on the server)
  or **Full access** (after a confirmation; everything counts as the user).
- Bar on every page (blue = view only, red = full) with **Back to globeadmin** (also in the user menu); returns to the page where it started, no new login.
- Any user, also expired users and users with two-step login. Blocked while acting: change password, security page. The user deleted or
  given a new password meanwhile → back to the globeadmin.
- Log: `impersonate_start` / `impersonate_end` (target, mode, minutes); entries written while acting carry `acting_globeadmin`. Sessions page marks the session.
- New files: `core/nuimpersonate.php`, `pages/nuimpersonate.php`.

## 0.8.16 – options menu on touch devices
- Phones and tablets without mouse (no Ctrl key): the form's options menu lists the actions without the keyboard shortcuts.

## 0.8.15 – menu button focus
- The menu (hamburger) button on small screens no longer shows Bootstrap's thick focus ring after a tap; keyboard focus shows a thin ring.

## 0.8.14 – permission items
- **Users → Permission items** (`index.php?p=permissions`, rights of nuBuilder's form `nupermissionitem`): list (search, group, number of users, Print / CSV)
  and editor – ident (unique), group, description, the users who have it (add / remove directly), "Used in code", clone, delete (removes it from all users).
- **User form**: new card *Permission items* – tick the items of the user, grouped, with search and All / None. Saved with the user, copied by Clone, removed with the user.
- Same tables as nuBuilder: `zzzzsys_permission_item` and `zzzzsys_user_permission` (`upe_ident` = item id, `upe_active` = '1'). Created when missing;
  now also in `install/schema_mysql.sql` / `schema_mssql.sql`. Unticking deletes the row (nuBuilder's subform delete); rows nuBuilder Next does not know stay.
- nuBuilder functions: PHP `nuGetUserPermissions($userId = null)`, `nuUserHasPermission($item, $userId = null)`; JavaScript `nuUserPermissions()`,
  `nuUserHasPermission(item, userOnly = false)`. The globeadmin has every item (as nuBuilder). `#USER_PERMISSIONS#` as before.
- Log: `permission_created` / `_changed` / `_deleted` / `_users`, `user_permissions` (added / removed idents).
- Sample data (version 5): 3 items `sample_order_status`, `sample_export`, `sample_discount` for Ben, Carla, Dario and Eva; the order form disables
  the status without `sample_order_status` (Before Save checks it as well); the procedure `sample_hello` shows the user's items. Click *Refresh* under Admin tools → Sample data.
- New files: `core/nupermission.php`, `pages/nupermissions.php`, `assets/js/nupermissions.js`, `assets/js/nuuser.js`.

## 0.8.13 – password button
- The show / hide password button (user form, change password, reset password) has the same border colour as the input beside it, in light and dark theme.

## 0.8.12 – code formatter and editor toolbar
- Every code editor (double-click editor, form builder, procedures) has a toolbar: **Undo / Redo**, **Search** (<kbd>Ctrl</kbd>+<kbd>F</kbd>),
  **Replace** (<kbd>Ctrl</kbd>+<kbd>H</kbd>), **Toggle comment** (<kbd>Ctrl</kbd>+<kbd>/</kbd>), **Show spaces and tabs**, **Format** (<kbd>Shift</kbd>+<kbd>Alt</kbd>+<kbd>F</kbd>).
  Read-only editors show only Search and Show spaces. Formatting is one undo step.
- Formatters as nuBuilder's `nuace.php` (same options): js-beautify for JavaScript, HTML and CSS; sql-formatter for SQL (MySQL or T-SQL, after `$nuConfigDBType`);
  PHP with Prettier + @prettier/plugin-php (PHP 8.1, 4 spaces, single quotes, 1tbs). Code without `<?php` stays without it.
- Hash variables (`#RECORD_ID#` …) survive formatting (they would otherwise be treated as comments in PHP / MySQL). Code with a syntax error is not changed – a message says why.
- The formatter scripts are loaded only on the first click on Format (no extra load on normal pages; works on the strict CSP pages).
- New files: `assets/js/nu/nucodetools.js`.
- New vendor files (included in this zip): `assets/vendor/formatter/` (beautify.min.js, beautify-css.js, beautify-html.min.js, sql-formatter.min.js – from nuBuilder's
  `third_party/formatter`; prettier-standalone.js 3.x and prettier-plugin-php.js 0.25 – MIT, licences beside them) and
  `assets/vendor/codemirror-5.65.21/codemirror-addons.js` (CodeMirror's comment, dialog, searchcursor, search and jump-to-line add-ons, MIT).

## 0.8.11 – procedures
- **Forms → Procedures** (`index.php?p=procedures`, rights of nuBuilder's form `nuprocedure`): list (search, group, status, Print / CSV) and editor like
  nuBuilder's procedure form – tabs PHP · JS · Access levels · Usage · History.
- PHP tab: code, description, group, category, status (active / draft / archived), launch from, run (hidden / new tab); CodeMirror with the `#` menu and
  "Insert snippet" (PHP / JavaScript code snippets); test run of the saved version: *Hidden* shows result, returned JavaScript, debug; *New tab* shows the output.
- JS tab: `nuOnProcedureRun()` runs before a test run from this page when "Active" is set (as in nuBuilder): `nuSetProperty()` passes values (`#name#`),
  `return false` cancels, `nuRunThisProcedure()` runs it later.
- Access levels: everyone (`sph_global`), demo access, the access levels that may run it (`zzzzsys_access_php`).
- Usage: run / display / select / lookup objects that use it, and form code, events and procedures that name it in quotes. Delete is blocked while objects use it.
- Clone (copies access levels; a template's copy loses "_template", as in nuBuilder). Templates and system procedures are read-only outside developer mode.
- History: every change made here is logged with old / new values (PHP and JavaScript code side by side), access changes and test runs.
- Form events (`<form>_BE` …) and lookup After Browse code stay in the form builder and are not listed; codes ending in `_BB` … `_AB` are refused.
- New files: `core/nuprocedure.php`, `pages/nuprocedures.php`, `assets/js/nuprocedures.js`.

## 0.8.10 – builder preview can be hidden
- Form builder: button in the preview header hides / shows the preview. Hidden on wide screens, the settings panel takes the space;
  on small screens only the header bar stays. The choice is stored in the browser (localStorage `nunext-builder-preview`);
  without a stored choice the preview starts hidden below 992 px. "Arrange" (Ctrl+Shift+A from a form) always shows it.

## 0.8.9 – Print and CSV on all lists
- Users, Access levels, Forms, Log, Debug, Files, Items, Translations, E-mail templates, Formats and the E-mail log have **Print** and **CSV**
  buttons, like form browse lists: current search, filters and sort, all rows (print up to 5,000, CSV up to 50,000).
- Right: "Print" (`slf_print_button`) of the matching nuBuilder form (`nuuser`, `nuaccess`, `nuform`, `nunextlog`, `nudebug`, `nufile`, `nuitem`,
  `nutranslate`, `nuemailtemplate`, `nuformat`, `nuemaillog`); the globeadmin always. Without it the buttons are hidden and `&export=` is refused.
- Print opens a printable page in a new tab and starts printing; CSV is UTF-8 with BOM, separator `$nuConfigCSVSeparator` (default `;`).
- Exports are logged (`browse_exported`). New file: `core/nulistexport.php`.

## 0.8.8 – confirm dialog default
- The shared confirm dialog's default button is now a neutral "OK"; only dialogs that delete something show the red "Delete"
  (they set `data-confirm-ok` / `data-confirm-class`). A dialog that forgets to name its button can no longer say "Delete".
- Note: the update page's dialog comes from the version that is running – updating from 0.8.4 or older still shows "Delete" once.

## 0.8.7 – install scripts
- `install/schema_mysql.sql` and `install/schema_mssql.sql` create `zzzzsys_file` (Files) and `zzzzsys_translate` (Translations) like nuBuilder.
- Databases installed with older scripts: the Files page, Translations and the sample data create the missing table themselves;
  image objects show no picture instead of an error while the table is missing.

## 0.8.6 – buttons on top
- User and access level pages: Back and Save sit in the toolbar next to Clone / Delete (and Reset 2FA); the bar at the bottom is gone.
  Save turns red on unsaved changes, as on forms. Enter in a field still saves.

## 0.8.5 – confirm dialogs
- Confirm dialogs say what they do: "Convert all to InnoDB", "End session", "Install", "Roll back", "Reset 2FA", "Refresh" … –
  "Delete" (red) only where something is deleted. Forms set it with `data-confirm-ok` / `data-confirm-class`.

## 0.8.4 – hash variables
- New hash variables as in nuBuilder 4.9: `#nu_date#`, `#nu_date_time#`, `#nu_time#`, `#nu_year#`, `#nu_month#`, `#nu_day#`, `#nu_hour#`, `#nu_minute#`,
  `#HOME_ID#`, `#LANGUAGE#`, `#DEV_MODE#` (`$nuConfigDevMode`, globeadmin; also `nuDevMode()`), `#USER_PERMISSIONS#`,
  `#OBJECT_ID#` in display / select SQL, `#ROW#` in `nuSetFormValue('#ROW#field', …)` of lookup After Browse code (sets the field of the same subform row).
- Values stay escaped for SQL / PHP / HTML (difference to nuBuilder, on purpose).
- Code editors (form builder and double-click editor): `#` button for SQL, PHP and HTML – every variable with a description, the fields of the form
  (also when editing nuBuilder's form / object / PHP records) and your hash cookies; search, click or Enter inserts at the cursor.
- Fix: Setup → Header is output like nuBuilder does – as JavaScript in `<script id="nuheader">` (HTML via `</script> … <script>`), followed by
  Setup → Style. Before, a nuBuilder header was shown as text at the bottom of every form and `nuLoadEditGlobal` & co. were not defined.
  A header that starts with a tag (`<script>`, `<link>` …) is output unchanged.
- `nuEventName()` (JavaScript) added.
- Sample data version 4: lookup After Browse with `#ROW#` on the order items, a display object with the new variables; hash cookie demo on the start page.
- New file: `assets/js/nu/nuhashvars.js`.

## 0.8.2 – sample data
- **Admin tools → Sample data** (globeadmin): install, refresh, remove a sample application; new passwords for the sample users.
- 7 forms (codes `sample_…`, ids `samplehome`, `samplecustomer`, `sampleproduct`, `sampleorder`, `sampleorderitem`, `sampleall`, `sampleallline` –
  no underscore after "sample" because nuBuilder splits PHP hook ids like `sampleorder_BS` at "_"):
  launch form, nuBuilder positions / auto / grid layout, tabs, browse formats, lookup, grid and form subforms, calc, display, iframe,
  every input type, formats, access settings, JavaScript events, BB/BE/BS/AS/BD PHP hooks, audit columns.
- 5 access levels (`sample_viewer`, `sample_editor`, `sample_manager`, `sample_sales`, `sample_secure` with 2FA) and 5 users (random passwords, shown once).
- 9 formats, 2 procedures, an e-mail template, 3 code snippets, a picture in Files; tables `sample_customer`, `sample_product`, `sample_order`,
  `sample_order_item`, `sample_all`, `sample_all_line` with data.
- Stored in nuBuilder's format: tested by opening, logging in and saving in nuBuilder 4.9 on a database from `nubuilder4.sql`.
- Remove deletes everything sample (and rights of other access levels on sample forms) and drops the sample tables; blocked while other users
  have a sample access level. A newer sample version (`NuSample::VERSION`) shows "update available" → Refresh.
- Fix: calc objects are calculated when an edit form opens (as nuBuilder does), without marking the form as edited.
- New files: `core/nusample.php`, `pages/nusample.php`.

## 0.8.1 – installer
- **First run**: while `nuconfig.php` (or `NU_CONFIG`) is missing, every page shows a step-by-step installer:
  Start (server check, setup token) → Database → Content → Administrator → Options → Install.
- **Who may install**: the setup token from `_install/setup-token.txt` (proves file access to the server); requests from the server itself
  (localhost, no proxy headers) skip it; fallback when the token cannot be written: an empty `install.allow` in the nuBuilder Next folder.
  10 wrong tokens → locked for 15 minutes. Token, counters and `install.allow` are removed after the install.
- **Database**: MySQL/MariaDB or MSSQL, test connection, optionally create the database; nuBuilder's `nuconfig.php` next to nuBuilder Next pre-fills it.
- **Content**: existing nuBuilder database → connect only; empty database → nuBuilder's `nubuilder4.sql` (from the nuBuilder folder, MySQL)
  or nuBuilder Next's install scripts; MyISAM is turned into InnoDB while importing; a database with other tables needs a confirmation.
- **Administrator**: globeadmin name/password in plain text in `nuconfig.php` (as nuBuilder), e-mail, optional 2FA; password strength meter.
- **Install** writes `nuconfig.php` from `nuconfig-sample.php` (new secret key, time zone, timeout, base URL, shared login), creates nuBuilder Next's
  tables and runs the database steps. Folder not writable → download / copy the shown file, then "Check again".
- Once `nuconfig.php` exists the installer is off. `_install/` and `install.allow` are never touched by updates and not in the package.
- New files: `core/nuinstall.php`, `pages/nuinstall.php`, `assets/js/nuinstall.js`.

## 0.8.0 – updates from the browser
**Admin tools → Update** (globeadmin):
1. Choose the `nunext.zip` → *Check package*. Nothing changes yet. The page shows the version change, how many files are changed / new /
   unchanged, which old files will be deleted, and what's new (from this README).
2. *Install* (after a confirmation):
   - checks the package: nuBuilder Next manifest (`nunext-manifest.json`), safe paths, the SHA-256 checksum of every file, newer version
     (older or the same only with "Install anyway"), PHP version; and the server: zip extension, writable folders, disk space;
   - unpacks it into a temporary folder outside the web root;
   - backs up the current program files and nuBuilder Next's own tables (`zzzzsys_nunext_*` as JSON) into `_backup/nunext-<version>-<time>-<random>.zip`;
   - switches on maintenance mode – users get "nuBuilder Next is being updated", the globeadmin keeps working; it ends by itself after 15 minutes;
   - copies the files (each one written next to its target and renamed over it), deletes files of the old version that the new one no
     longer has (also the old file names from before 0.7.2), resets OPcache;
   - if copying fails, the backup is put back automatically.
3. The next page runs with the new code: database steps (numbered, each applied once and recorded in `zzzzsys_nunext_migration`),
   maintenance mode off, "Updated from … to …" with what's new.

Never touched: `nuconfig.php`, `third_party/` (phpMyAdmin, Tiny File Manager), `_backup/`. `assets/vendor/` and `lib/` are only replaced when a
package contains them and never deleted – TinyMCE and the other libraries you copied stay.
Backups: list with download, *Roll back* (puts the program files of that version back; the current ones are backed up first; database
changes stay) and delete; *Back up now* for a manual backup. Make a full database backup separately (phpMyAdmin / your hoster).
`$nuConfigBackupPath` puts backups elsewhere (best outside the web root; on nginx `.htaccess` does not protect `_backup/` – the file names
contain 16 random characters). The database steps also run after a manual update, with the globeadmin's next login.

## 0.7.4 – keyboard shortcuts and options menu
Form pages have nuBuilder's **Ctrl+Shift** shortcuts and an **Options** menu (⋮ in the toolbar) that lists them – only what exists in nuBuilder Next
and what the user may use (buttons on the page, rights, globeadmin):

| Key | Edit form | List (browse) | Who |
|---|---|---|---|
| S | Save | Search | save right / everyone |
| C, Y | Clone, Delete | – | clone / delete right |
| A | Arrange objects (builder) | Add record | rights on `nuform` / create right |
| H | Add object (builder) | – | rights on `nuform` |
| F, O | Form properties, object list (builder) | same | rights on `nuform` (not for nuBuilder's own forms) |
| P | – | Print | print right |
| R | Refresh | Refresh | everyone |
| ? | Help (documentation) | – | everyone |
| ← → | Previous / next tab | Previous / next page | everyone |
| Home, End | – | First / last page | everyone |
| 1–9 | – | Column in / out of the search | everyone |
| D | nuDebug results | same | rights on `nudebug` |
| E, Q | Database (phpMyAdmin), File Manager | same | globeadmin, when installed |
| J | Sessions (logged-in users) | same | globeadmin |
| M, V, X | Form info, version info, form properties as JSON | same | globeadmin |
| Q | Change password | – | users (not globeadmin) |
| L | Log out (asks first) | same | everyone |

Not in nuBuilder Next and therefore left out: Cloner (Z), Backup (B), Setup (U), Prompt generator (K), object grid (G), search list (C in lists).
The builder opens in a new tab (`?p=builder&f=…&open=arrange|add|form|objects`).
**Mac**: Control+Shift (shown as ⌃⇧) works for every shortcut. Cmd+Shift works as well, except where macOS or the browser keeps the
combination for itself (e.g. ⌘⇧Q logs out of macOS, ⌘⇧C opens Chrome's element inspector, ⌘⇧? opens the Help menu).

## 0.7.3 – code editor on textareas
Double-click on a textarea with code opens the code editor (CodeMirror, bundled – nothing to copy) in a large dialog:
syntax highlighting for PHP, SQL, JavaScript, HTML and CSS, line numbers, full-screen button, **OK** / Ctrl+Enter / Ctrl+S writes the code back
(the form becomes "edited"), Cancel / Esc asks before discarding changes. Read-only fields open read-only.
Which textareas: the nuBuilder rule (class `js`, `sql`, `php`, `html` or `css` – also what `nuACEInitDblClickHandlers()` binds) and nuBuilder's
code columns, in any form: `sph_php`; `sfo_browse_sql`, `sob_select_sql`, `sob_display_sql`, `sse_sql`; `sfo_javascript`, `sfo_browse_javascript`,
`sfo_edit_javascript`, `sev_javascript`, `sph_javascript`, `sob_input_javascript`, `sob_lookup_javascript`, `sob_html_javascript`; `set_header`,
`sob_html_code`; `sfo_style`, `set_style`, `itm_css`; code snippets (`cot_source_code`, language from `cot_language`). Code fields use a monospace font.
`nuOpenAce(lang, id)` opens the same editor; forms that bind their own double-click (as nuBuilder's do) still get one editor.

## 0.7.2

### Compatibility check – Admin tools → *Compatibility check* (`?p=compat`, globeadmin)
Scans the application's code – form JavaScript (all / browse / edit), object events, lookup JS/PHP, HTML objects, the setup header,
procedures – for `nu…(` calls and shows per function: **supported**, **stub** (exists, does nothing yet), **missing** (nuBuilder function not
in nuBuilder Next), **unknown** (not a nuBuilder function) or **your own** (defined in your code or `$nuConfigIncludePHP`), with the number of calls
and where they are (links to the builder). The supported list is read from the runtime itself, so it is always current. JavaScript that PHP
writes with `nuAddJavascript()` counts as JavaScript.

### More nuBuilder JavaScript functions
- **Hooks**: `nuOnLoad(formId, formCode)` (and `nuLoadBrowseGlobal` / `nuLoadEditGlobal` from the setup header), `nuOnTabSelected`,
  `nuOnBeforeGetBreadcrumb`, `nuBeforeAddActionButtons`, `nuSelectObjectRefreshed`, `nuDisplayObjectRefreshed`, `nuOnSearchAction(Global)`.
- **User**: `nuUserId`, `nuUserLogin`, `nuAccessLevelId`, `nuUserCode`, `nuUserTeam`, `nuUserDepartment`, `nuUserPosition`, `nuUserFirstName`,
  `nuUserLastName`, `nuUserAdditional1/2`.
- **Tabs**: `nuOpenTab`, `nuSelectedTabId/Number/Title`, `nuShowTabByTitle`, `nuHideTabByTitle`, `nuHideTabs`, `nuRemoveTabs`, `nuTabSetMarker`.
- **State and helpers**: `nuIsClone`, `nuIsEnabled`, `nuIsVisible`, `nuCurrentRow`, `nuSubformValue`, `nuCurrentDate`, `nuSetDateValue`, `nuID`,
  `nuPasteText`, String/Date helpers (`'x'.nuStartsWith()`, `nuEndsWith`, `nuReplaceAll`, `nuFormat` …).
- **Layout**: `nuHideHolders`, `nuRemoveHolders`, `nuSetVerticalTabs`, `nuPrintEditForm`, `nuLabelOnTop` (absolute layout), `nuBrowseStyleBadge`.
- **Files**: `nuAttachImage`, `nuAttachButtonImage` (by file code, served by `?p=file&code=…`), `nuEmbedObject`.
- **Refresh**: `nuRefreshSelectObject(selectId, formId, removeBlank)`, `nuRefreshDisplayObject(displayId, formId)` and the `nuSubform…` variants:
  the server re-runs the object's SQL with the current values (main form + subform row). Without a prefix and with a subform's form id,
  every row of that subform is refreshed. The "saved" state is kept.
- **Browse**: `nuSearchAction(search, filter)`, `nuSetNoSearchColumns`, `nuSetSearchColumns`, `nuSearchColumnsReset` (URL parameter `nsc`).
- **Run iFrames**: `nuGetIframeValue`, `nuSetIframeValue`, `nuGetIframeProperty`, `nuSetIframeProperty`, `nuFilterRun`, `nuRecordRun`.
- **Subforms**: `nuSubformAddFilter({sf: ['col'] | {col: {type: 'select' | 'search', all, blank, placeholder, width}}})` – in the column title
  (grid) or in a filter bar (form layout).
- Left out on purpose: `nuChart` (loads Google Charts from Google), `nuGetPHP` (nuBuilder's procedure editor), `nuStopBrowserResize`.

### File names with nu prefix
All own PHP, JavaScript and CSS files now start with `nu` (`core/auth.php` → `core/nuauth.php`, `pages/users.php` → `pages/nuusers.php`,
`assets/css/app.css` → `assets/css/nuapp.css` …). `index.php`, `nuconfig.php`, `lang/`, `install/` and the vendor libraries keep their names;
URLs stay the same (`?p=users`). **After unpacking 0.7.2 over an older version, delete these old files** (they are never loaded any more):

```
core/acl.php
core/app.php
core/auth.php
core/compat.php
core/db.php
core/debug.php
core/files.php
core/infra.php
core/items.php
core/layout.php
core/mail.php
core/sessions.php
core/translate.php
core/twofa.php
core/vendor.php
core/form/browse.php
core/form/builder_gen.php
core/form/ddl.php
core/form/format.php
core/form/hash.php
core/form/history.php
core/form/layout.php
core/form/lookup.php
core/form/meta.php
core/form/php.php
core/form/php_api.php
core/form/record.php
core/form/render.php
core/form/trail.php
pages/access.php
pages/accesslevel.php
pages/builder.php
pages/builderapi.php
pages/compat.php
pages/debug.php
pages/email.php
pages/emailtemplates.php
pages/fa.php
pages/file.php
pages/files.php
pages/forgot.php
pages/form.php
pages/formapi.php
pages/formatapi.php
pages/formats.php
pages/formexport.php
pages/forms.php
pages/help.php
pages/home.php
pages/items.php
pages/lang.php
pages/log.php
pages/login.php
pages/logout.php
pages/password.php
pages/reset.php
pages/security.php
pages/sessions.php
pages/translations.php
pages/twofa.php
pages/user.php
pages/users.php
pages/vendor.php
assets/js/app.js
assets/js/builder.js
assets/js/debug.js
assets/js/emailtemplates.js
assets/js/files.js
assets/js/formatdesigner.js
assets/js/formats.js
assets/js/help.js
assets/js/items.js
assets/js/qr.js
assets/js/theme.js
assets/js/nu/designer.js
assets/css/app.css
assets/css/builder.css
assets/css/form.css
```

## 0.7.1 – translations (`zzzzsys_translate`, rights on nuBuilder's form `nutranslate`)
- Form pages translate object labels, tab titles, list column titles and form descriptions like nuBuilder: exact match of `trl_english` in the
  reader's language (user: `sus_language`, globeadmin: `set_language`; empty → nuBuilder Next menu language, DE = German; English = none).
  Own rows win over nuBuilder's (`nu…`), `|text` is never translated. The builder keeps the original texts.
- `nuTranslate()` in PHP (with `%s` / `%s1` arguments) and JavaScript (string or array), `nuTranslateToEnglish()`, `nuUserLanguage()` return the same as nuBuilder.
- *Forms → Translations*: list (language, own/nuBuilder, search), edit, **Missing** (texts of your forms without a translation, with where they are used,
  one click to translate). nuBuilder's rows are read-only; *Override* creates an own row.

### Bundled libraries
The current list with versions, folders and licences: **Admin tools → Update → Libraries** (read from the files on the server) and
`assets/vendor/libraries.json` (the registry – since 0.8.229 also copied into `nunext-manifest.json`). In the package (0.8.229):
Bootstrap 5.3.8, Bootstrap Icons 1.13.2, jQuery 3.7.1, jquery-confirm 3.3.4, Tom Select 2.6.2, CodeMirror 5.65.21, js-beautify 1.15,
sql-formatter 15.6.4, Prettier 3.3.3, @prettier/plugin-php 0.25.0, qrcode-generator 2.0.4, ApexCharts 4.4.0. Copied by hand or used
from nuBuilder: TCPDF (6.x, or 7.x as a Composer installation in `third_party/tcpdf/` – see 0.8.232), PHPMailer, TinyMCE, phpMyAdmin,
Tiny File Manager; an own build / patched copy can carry `NUNEXT-VERSION.txt` (see 0.8.231).

## Earlier versions (0.2–0.7)

What the first versions added, kept as they were written then. The current state is described in the documentation (`?p=help`).

### Forms (0.2)

#### Runtime – `index.php?p=form&f=<form id or code>[&r=<record id>]`
- Browse lists from `sfo_browse_sql` + `zzzzsys_browse` with server-side search (dates searchable as displayed), sort and paging.
  Hash variables (`#USER_ID#`, `#RECORD_ID#`, `#field#`, properties) are replaced and **escaped** for SQL/PHP/HTML.
- Edit view with tabs (`syt_access` respected) and these object types: input (text, nuDate, nuNumber, number, checkbox,
  button, HTML5 types, nuScroll), select (static list or SQL), textarea, display, calc, lookup (code/description, popup,
  AB hook), subform grid (add/delete rows), contentbox, html, word, run (button or iframe).
- `sob_all_access` 0–4 and `sob_all_validate` 1–3 work like in nuBuilder (validation is enforced on the server).
- Save/delete in one transaction incl. subform rows; PHP hooks `<form>_BB/BE/BS/AS/BD/AD` and `<object>_AB`.
- Procedures (`nuRunPHPHidden`) need a row in `zzzzsys_access_php` (or `sph_global = 1`); globeadmin may run all.
- Rights per form from `zzzzsys_access_form` (view / save / add+new / delete / clone / print).

#### Layouts
| mode | where | behaviour |
|------|-------|-----------|
| absolute (default) | – | pixel positions like nuBuilder |
| auto | `sfo_json {"nunext":{"layout":"auto"}}` | rows derived from top/left, objects inside content boxes nested, responsive (stacks on phones) |
| grid | + `sob_all_json {"nunext":{"row":..,"col":..,"span":1–12,"box":"<contentbox id>"}}` | explicit placement; the builder's "Convert to grid" writes the auto result as a starting point |

Only a `nunext` key is added to the JSON columns; other content is preserved. Globeadmin can preview any layout from the form toolbar.

#### nuBuilder compatibility
- Element IDs match nuBuilder (`label_x`, `xcode`/`xbutton`/`xdescription`, `sub000field`, `sub000nuDelete`, `title_subfield`,
  `nuTab0`, `nucell_r_c`, `nuSaveButton` …), jQuery 3.7 and jquery-confirm are loaded.
- `sfo_javascript` / `sfo_edit_javascript` / `sfo_browse_javascript`, `sfo_style`, object events and the
  `zzzzsys_setup.set_header` HTML are executed. About 90 `nu*` JS functions are implemented (values, visibility,
  subforms, properties, lookups, formatting, calc, tabs, messages, save/delete/clone hooks, nuRunPHPHidden …);
  unsupported ones are harmless stubs that log a console warning. Script errors are listed for globeadmin (bug button).
- PHP API inside hooks: `nuRunQuery`, `db_fetch_row/array/object`, `db_num_rows`, `nuHash`, `nuDisplayError`,
  `nuAddJavascript`, `nuSetFormValue`, `nuLookupRecord`, `nuSetProperty`, `nuSubformObject`, `nuID`, `nuDebug`,
  `nuProcedure`, `nuCreateTableFromSelect`, `nuGetHttpOrigin` … Installation-specific helpers go into `$nuConfigIncludePHP`.
- Form pages allow inline scripts and eval (needed for admin-authored code); all other pages keep the strict CSP.

#### Form builder – `index.php?p=builder&f=<form id>` (rights on form `nuform`)
Object list (per tab, reorder), properties panel per object type, events, lookup AB code, form settings, browse
columns, tabs, JS/CSS and PHP hooks, live preview (click an object in the preview to select it).
"New form" works with an **existing table** (pick table and primary key; objects and browse columns are generated
from its columns) or a **new table** (column grid: name, label, type, length, required). New tables get a
`varchar(25)` primary key filled with nuBuilder IDs and, optionally, audit columns `created_at/by`, `updated_at/by`
that the server fills on every save. Column types map to MySQL and MSSQL (`text`→VARCHAR/NVARCHAR, `longtext`→LONGTEXT/NVARCHAR(MAX),
`bool`→TINYINT(1)/BIT, `datetime`→DATETIME/DATETIME2 …).
When an object is saved in the builder and the form's table has no column with its ID, the builder offers to add it
(`ALTER TABLE … ADD`) with a proposed type that can be changed. Changes are written to the live nuBuilder tables;
nuBuilder system forms (`nu…`) are read-only.

### 0.3 additions

#### Forms
- **Subforms in form layout** (`sob_subform_type = 'f'`): one card per record, fields arranged like the auto layout; add/delete like the grid.
- **Browse filters**: per column in the builder (Browse → "Filter") – a dropdown of the distinct values above the column;
  and nuBuilder's `nuAddBrowseFilter('<browse id>').nuSearchablePopup({items})`, which sets the property `<id>_filter` for `#<id>_filter#` in the browse SQL.
- **Searchable and multiple selects** (`sob_select_2`, `sob_select_multiple`) with Tom Select; multiple values are stored as JSON array like nuBuilder.
  `$('#x').val(...).change()` and option lists rebuilt by custom code stay in sync.
- **Files** (input type `file`): stored in the field in nuBuilder's format `{"file": base64(dataURL), "name", "size", "type"}`; preview, download, remove.
  Max size `$nuConfigFileMaxKB` (default 300); `nuOnFileLoad` / `nuOnFileLoaded` hooks work. **Image objects** show files from `zzzzsys_file`.
- **CSV export** (UTF-8 with BOM, `;` – `$nuConfigCSVSeparator`) and a **print view** of the current list (search, sort and filters apply; needs the Print right).

#### Form builder
- **Drag & drop in the preview**: absolute layout – move (5 px steps of movement, Alt = 1 px, arrow keys) and resize with the corner handle;
  auto/grid layout – drop an object before/after another, below it (new row) or into a content box, change the width (1–12 columns) with the side handle.
  Rearranging an auto-layout form converts it to grid first (after asking).
- **History & undo**: before every change the complete form definition is saved (`zzzzsys_nunext_form_history`, last 100 per form);
  "Undo" restores the last state, "History" lists all states for restoring. Table structures (DDL) are not part of it.
- **Code editors** with syntax highlighting (CodeMirror 5) for JavaScript, PHP, SQL, CSS and HTML.

#### Security & operation
- **Two-step login** for globeadmin (`$nuConfig2FAAdmin`) and for users whose access level has "Use 2FA" (`$nuConfig2FAUser`):
  authenticator app (TOTP, QR setup, replay protected) or a 6-digit code by e-mail; users without either set up an app at their next login.
  "Remember this device" (`$nuConfig2FATrustDays`, default 30). Users manage this under *Security* (user menu); admins can reset a user's 2FA.
- **Forgot password**: e-mailed single-use link (60 min), same answer for unknown users, rate-limited.
- **Log** (menu *Log*, globeadmin or rights on `nunextlog`): logins, failed logins, 2FA, password changes and resets,
  user/access-level changes, form changes and exports.
- **Audit trail** (menu *Log → Audit trail*, globeadmin or rights on `nunextaudit`): every change made in the forms, sub-form rows,
  clones and CSV imports, one row per column (who, when, table, record, form, old → new) in `zzzzsys_nunext_audit`; history per record
  (⋮ menu) with restore of a value or a deleted row; settings on/off, keep, not recorded tables/columns.
- **Shared login with nuBuilder** (`$nuConfigSharedLogin = true`, same domain and server): a user logged in to nuBuilder is logged in to nuBuilder Next
  automatically (nuBuilder's session is read, never changed). The nuBuilder Next session ends when the nuBuilder session ends.
  The other direction (nuBuilder Next → nuBuilder) is not possible without changing nuBuilder.
- **E-mail**: see 0.4 below.
- nuBuilder Next's own tables (`zzzzsys_nunext_log`, `zzzzsys_nunext_form_history`, `zzzzsys_nunext_user_2fa`, `zzzzsys_nunext_token`) are created automatically on first use.

### 0.4 additions – e-mail

#### Settings – menu *E-mail* (rights on nuBuilder's forms `nusetup` and `nuemaillog`)
- **Settings** edits the SMTP fields of `zzzzsys_setup` (`set_smtp_host/port/username/password/from_address/from_name/use_authentication/use_ssl`),
  the same row nuBuilder uses. The password is stored as in nuBuilder (plain text) and never shown again; leave the field empty to keep it.
- nuBuilder writes `1` into every SMTP field when the settings are reset; nuBuilder Next treats `1` as "not set" and says so.
- Encryption follows the port like nuBuilder: 465 = SSL/TLS, 587 = STARTTLS, otherwise STARTTLS if the server offers it.
  New: "Use SSL" selects SSL/TLS on non-standard ports (nuBuilder ignores the flag).
- `$nuConfigSMTPHost` (and the other `$nuConfigSMTP*`) in nuconfig.php still override `zzzzsys_setup` when set.
- **Test mail** sends with the active configuration and shows the SMTP dialogue (credentials hidden).
- **E-mail log** lists `zzzzsys_email_log` (filter, search, detail with the message in a sandboxed frame, delete old entries).

#### PHPMailer
Loaded from nuBuilder: `<nuBuilder>/third_party/phpmailer/` (`PHPMailer.php`, `SMTP.php`, `Exception.php`) – nuBuilder's
`core/nuemailer.php` requires `../third_party/phpmailer/…`. `<nuBuilder>` is the folder above nuBuilder Next, or `$nuConfigNuBuilderPath`;
`$nuConfigPHPMailerPath` points to another folder. If none is found, the bundled copy in `lib/PHPMailer` is used.

#### PHP functions (forms, procedures, `$nuConfigIncludePHP`) – same names, parameters and results as nuBuilder
| Function | Notes |
|---|---|
| `nuSendEmail($args)` / `nuSendEmail($to, $from, $fromName, $body, $subject, $attachments, $html, $cc, $bcc, $replyTo, $priority, $smtpOptions)` | `$args` keys: `to, from_email, from_name, cc, bcc, body, subject, reply_to, attachments, html` (default true), `priority, smtp_options`; new: `debug` (0–4), `method` (`SMTP`, `sendmail`, `mail`). |
| `nuSendEmail($args, $logOptions)` = `nuSendEmailEx($args, $logOptions)` | Sends and writes `zzzzsys_email_log`. `$logOptions`: `true` or `table_name, tag, form_id, user_id, record_id, json`; missing values come from the running form. |
| `nuEmail(...)` | nuBuilder's low-level function. Returns `[sent, message, ErrorInfo, debug output, skipped addresses]` (index 4 is new). |
| `nuMarshallEmailSettings()`, `nuEmailPHP()`, `nuUnescapeQuotes()`, `nuIsArrayAssociative()`, `db_setup()` | as in nuBuilder |
| `nuGetEmailTemplateData()`, `nuSendEmailFromTemplate()`, `nuReplaceHashVariables()`, `nuFormId()`, `nuRecordId()`, `nuUserId()`, `nuFormTable()`, `nuIsValidEmail()` | as in nuBuilder |

Improvements over nuBuilder, compatible with existing code:
- Recipients as a string (`,` or `;` separated, `Name <a@b.ch>` allowed) or as arrays (list or `address => name`); empty entries are ignored,
  invalid addresses are skipped and reported (result index 4 and `eml_error`). Without any valid recipient nothing is sent.
- Attachments: `['name' => '/path']` as in nuBuilder, plus a list of paths, `['name' => ['data' => …, 'type' => …]]`, data URLs and
  the JSON of a nuBuilder file field. A missing file stops the mail instead of sending it without the attachment.
- Priority 1/3/5 or `high`/`normal`/`low` (sets `X-Priority` and `Importance`).
- Timeouts of 20 s for connect and each server reply (PHPMailer default: 300 s), so a hanging server cannot block a save.
- An empty sender address gives a clear error; the log shows the sender actually used.
- `zzzzsys_email_log`: `eml_created_at` and `eml_sent_at` are set, values are shortened to the column sizes, errors are plain text,
  `eml_importance` is a number or NULL (MSSQL). The table is created when it is missing.
- `nuRunQuery()` accepts named parameters (`:name`) like nuBuilder.
- nuBuilder Next's own mails (2FA codes, password reset) use the same settings and are logged with tags `nunext_2fa` / `nunext_reset`,
  without the body (it contains codes and links).

#### Font Awesome (nuBuilder's icons)
Form pages and the builder load nuBuilder's Font Awesome (`<nuBuilder>/third_party/fontawesome/css/all.min.css` and its webfonts),
so `fa fa-…`, `fas`/`far`/`fab` and `fa-solid …` icons in existing forms keep working. The files are served through
`index.php?p=fa&f=…` (no session, long cache, only `css/` and `webfonts/` files), so this works under nuBuilder Next's strict CSP even when
nuBuilder has a different URL. `$nuConfigFontAwesomePath` points to another folder; without one, no Font Awesome is loaded.
nuBuilder Next's own screens use Bootstrap Icons.

### 0.5 additions – formats

Formats live in nuBuilder's table `zzzzsys_format`, so nuBuilder and nuBuilder Next share them. Objects keep referencing them like nuBuilder does,
as `<letter>|<format>` in `sob_input_format`, `sob_calc_format` and `sbr_format` (at most 30 characters in nuBuilder, so the format is at most 28).

| Type | Example | Notes |
|---|---|---|
| Date / time `D\|` | `dd.mm.yyyy hh:nn`, `dddd, dd.mm.yyyy`, `hh:nn` | tokens `dd mm yyyy yy mmm mmmm ddd dddd hh nn ss`; time-only values (`13:05:00`) are supported |
| Number `N\|` | `CHF 1'000.00`, `1'000.0 %`, `€ 1.000,00` | prefix, thousands and decimal separator, places, suffix. `srm_currency` is written as nuBuilder expects. New: negative numbers as `-1'234.50`, `(1'234.50)`, in red, or in red brackets (`srm_json`) |
| Text mask `T\|` (nuBuilder Next) | `CH99 9999 9999 9999 9999 9`, `+41 99 999 99 99`, `AAA-999` | `9` digit, `a` letter, `A` letter upper case, `*` letter or digit, `\` next character literally. Saved with or without the mask characters; optionally must be complete (checked in the browser and on the server). Existing values that don't match the mask are shown and saved unchanged. For inputs of type text, tel and search. |

- **Formats page** (*Forms → Formats*, rights on nuBuilder's form `nuformat`): all formats with an example and how often they are used.
  Click a format to edit it (objects and list columns using it are updated when the pattern changes) or delete it (only when unused).
  *New format* opens the designer: token buttons for dates, separators/places/negative style for numbers, mask legend and examples for text,
  with a live preview. "Default for this type" is nuBuilder's `srm_default`.
- **Builder**: the format fields of inputs, calculations and list columns are now lists (saved formats first, then built-ins) with
  **+** (new format, opens the designer and selects the result) and ✎ (edit). Inputs of type text/tel/search can get a text mask.
  A built-in format is added to `zzzzsys_format` when an object uses it, so nuBuilder knows it as well.
- nuBuilder ignores the nuBuilder Next extensions (suffix, negative style) and treats `T|` formats as plain text.

### 0.6 additions

#### Run objects (nuBuilder `run`)
Buttons (or iframes) that open a form or run a procedure, configured in the builder under *Type settings*:
- **Open form**: record ID empty = list (browse), `-1` = new record, otherwise that record; hash cookies (`#RECORD_ID#`, `#field#`) are replaced.
  *Filter* = search words always applied to the list (nuBuilder `sob_run_filter`, not shown to the user).
  *Open in*: new breadcrumb, current breadcrumb (replaces the current one), new browser tab, popup. Method button or iframe.
- **Run procedure**: procedures with `sph_run = 'hide'` run in the background (`nuRunPHPHidden`), others show their output in a new window (`nuRunPHP`).
  The list offers procedures like nuBuilder's run list (no system procedures, no form hooks).
- Reports (type R) are not supported yet (see `TODO.md`).
- **Breadcrumbs across forms**: forms opened from a run button, `nuForm()` or a list row keep the trail of the form they came from
  (stored in the session, `&bt=` in the URL). `nuForm(f, r, filter, search, target)` and `nuPopup(f, r, filter)` follow nuBuilder.

#### Notes (`nunotes`, categories `nunotescategroy`) and code snippets (`nucodesnippet`)
nuBuilder's own forms run unchanged in nuBuilder Next; menu *Notes*, and *Forms → Code snippets*. For a database without nuBuilder,
`install/notes_snippets_mysql.sql` creates the tables and forms (MSSQL: tables in `schema_mssql.sql`).
- New object type **WYSIWYG editor** (`editor`, nuBuilder's TinyMCE field), also available in the builder. `nuInitTinyMCE`,
  `nuSaveEditor`, `nuTinyMCESetContent/GetContent/SetBounds`, `nuOnEditorLoad`, `nuTinyMCEOnInit` work like in nuBuilder.
- **TinyMCE is not part of the package.** Copy the folder **`<nuBuilder>/third_party/tinymce/`** with its contents to
  **`<nuBuilder Next>/assets/vendor/tinymce/`**, so that `assets/vendor/tinymce/tinymce.min.js` exists (also `models/`, `plugins/`,
  `skins/`, `themes/`, `icons/`, `langs/`). Without it, the content is edited as HTML text and the globeadmin sees a hint.
- *Updated on* is set on every save of a note or snippet.
- Code snippets: language list JavaScript, PHP, SQL, CSS, HTML, Other; double-click the source code opens a code editor (CodeMirror,
  nuBuilder's `nuOpenAce`). "Selectable in" (`cot_scope`) follows later (`TODO.md`).
- New runtime functions: `nuDisableAllObjects`, `nuEnableAllObjects`, `nuInsertTextAtCaret`, `nuOpenAce`, `nuIsIframe`, `nuAttachFontAwesome`.
- New records no longer write NULL into empty date/number columns, so database defaults apply.

### 0.7 additions

#### Documentation – user menu → *Documentation* (`index.php?p=help`)
English documentation in `docs/nunext-docs.htm`, filtered on the server: every user sees only the chapters that apply to him
(`data-doc="admin"` = globeadmin, `data-acl="<form id>"` = view right on that form). Index on the left, full-text search with highlighting
(`/` focuses it, `&q=` in the URL), `#chapter` links. The globeadmin can switch to "As user" (`&doc=user`).
`docs/.htaccess` blocks direct access to the file.

#### E-mail templates, items, debug results
- **E-mail templates** (`zzzzsys_email_template`, rights on `nuemailtemplate`): *E-mail → Templates*. Choose a form and insert its objects as
  `#obj_id#` placeholders; preview and test with a record; clone; code + group + language unique.
- **Items** (`zzzzsys_item`, rights on `nuitem`): *Forms → Items*, Main and Additional tab like nuBuilder.
- **Debug results** (`zzzzsys_debug`, rights on `nudebug`): *Log → Debug*. `nuDebug()` in PHP writes there (nuBuilder format, `deb_added` = Unix time).

#### Files (`zzzzsys_file`, rights on nuBuilder's form `nufile`) – *Forms → Files*
Small images and documents in the database, stored in nuBuilder's format (`sfi_json` = `{"file": base64(data URL), "name", "size", "type"}`;
the MIME type is taken from the content). Grid with thumbnails, search and group filter; upload, drag & drop or paste an image; preview,
download (`?p=files&raw=<id>`, served with nosniff and a sandbox CSP), clone; code unique; files used by image objects cannot be deleted;
nuBuilder's system files only for the globeadmin. Maximum size `$nuConfigFileMaxKB` (default 300).

#### Logged-in users (globeadmin) – *Log → Logged-in users*
All nuBuilder Next sessions with IP, browser, login time and last activity (table `zzzzsys_nunext_session`, activity written at most every 30 s);
*End session* logs the user out with his next request. nuBuilder's sessions (`zzzzsys_session`) are listed for information.

#### phpMyAdmin and Tiny File Manager (globeadmin) – **not part of the package**
| Copy from | to |
|---|---|
| `<nuBuilder>/third_party/nudb/` | **`<nuBuilder Next>/third_party/nudb/`** |
| `<nuBuilder>/third_party/tinyfilemanager/` | **`<nuBuilder Next>/third_party/tinyfilemanager/`** |

Copy the folders with nuBuilder's config files: they include `../../nuconfig.php` (= nuBuilder Next's) and accept the cookie `nu_PMA` / `nu_TFM`.
User menu → *Database* (phpMyAdmin) / *File Manager* writes a `zzzzsys_session` row with `global_access = 1` for the current nuBuilder Next session,
sets the cookie and opens the tool in a new tab – like nuBuilder's `core/nuvendorlogin.php`. The row is removed at logout, when the session
is ended by the globeadmin or after the session timeout. `nuVendorLogin('PMA' | 'TFM', table)` works in form code.
`index.php?p=vendor` shows the status. Other locations: `$nuConfigPMAURL` / `$nuConfigTFMURL`. MySQL / MariaDB only.

#### Database
- nuBuilder Next's own tables are renamed from `nunext_*` to **`zzzzsys_nunext_*`** (primary key `<table>_id`, like nuBuilder's tables, so nuBuilder's
  app cloner and backup handle them). Existing tables are renamed automatically on the first request after the update – data is kept.
- Every table nuBuilder Next creates is **InnoDB** (`$nuConfigDBEngine` is ignored for that). *Admin tools → Storage engine* lists tables that are not
  InnoDB and converts them (`ALTER TABLE … ENGINE=InnoDB`, backup first). Keep `$nuConfigDBEngine = "InnoDB"` for nuBuilder's update.
- The user form's switch "Accessibility features" is hidden until its function is defined (the stored value is kept).

#### Forms
- The **Save** button turns red while there are unsaved changes (forms and builder).
- A **toast** confirms save, delete and clone (`nuToast(message, type)` for your own code).
- A record being **cloned** shows a yellow badge and a dashed frame until it is saved; the blank "new" sub-form row is no longer copied as edited.
