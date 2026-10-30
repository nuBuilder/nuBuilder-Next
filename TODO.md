# nuBuilder Next – to do

## Open questions for the owner
- [ ] dev / test / prod and git (forum question, 26 Oct 2026): proposals M1–M3 (moving forms / procedures between installations) and
      G1–G3 (git sync of the application's metadata) – the owner decides later.
- [ ] Page forms: besides the draft (0.8.599) a "Test copy" (clone of the page form + its folder, publish back) was proposed (option A,
      not built); a page's draft shares _data, secrets.php and the database with the live version.

## 0.8.634 Header layouts to try – TEMPORARY, remove when the owner has chosen
- [ ] User menu → *Header layout (test)* (developer mode, globeadmin; choice in localStorage nunext-hv): variants A–E of the mockup
      (`../mockups/layout-mockup.html`, outside the project) on record pages, form pages and lists. Once one is chosen: build it properly
      and delete assets/js/nuheadervariant.js, the CSS block "header layouts (test)" at the end of nuapp.css, the menu entries / script
      tag in core/nulayout.php, the texts hlt_*, the e2e test header_variants and the mockup folder.

## Form designer – open
- Editing sub-form columns inside the sub-form; default values (nuBuilder Forte has no such field – would need sob_all_json).
- New form, new table: no reordering of the column grid yet (the existing-table column list has it, 0.8.675).

## Please verify – short list (D13 of the review, 29 Oct 2026)
The "please verify" sections up to 0.8.677 (covered by the gates up to 0.8.677) are moved, unchanged, to `dev/TODO-archive.md`.
What still needs a real environment – or a look by the owner – is collected here; newer releases follow below.
- [ ] **SQL Server – nothing ran on a real one** (no image / pdo_sqlsrv here; code checked with simulated catalogues and sqlglot):
      install scripts (`schema_mssql.sql` has no form tables), own tables and migration steps (0004, 0020, 0021 …, missing columns added
      automatically), App cloner, backups with the whole database, list views' catalogue SQL, start page layouts, dashboards' read-only run
      (BEGIN TRAN … ROLLBACK), the access condition in the save transaction (SAVE TRANSACTION), concurrent edits (UPDLOCK, ROWLOCK), audit
      restore and demo data reset with IDENTITY keys (refused / not handled), login token compare and swap on NVARCHAR(MAX), Change column
      (ALTER COLUMN, defaults as constraints), UUID keys (uniqueidentifier), custom user fields (ALTER TABLE), user import, Adminer through
      dbadmin.php, the SQL builder list ('SQL:' + id).
- [ ] **On the live server**: e-mail → Test → *Check connection* with the real SMTP server (also OAuth 2), `php cron.php update` as the web
      server's user (--url over https, proxy), unattended update from GitHub, nginx rules from Help → Security, a re-run of the security
      assessment's test kit (HTTPS, real access levels), uploads with Uppy into the real upload folder.
- [ ] **Browsers**: Safari (ApexCharts on the start page, CSP style-src-attr), phones with real touch (long tap menus, side bar, design mode on phones).
- [ ] **Real data**: list views of every system list with real data (default columns, sort, filters, print), `#USER_PERMISSIONS#` with real
      permission items, sub-form link columns without an index on forms made before 0.8.366, the compatibility check on the owner's forms.

## 0.8.722 IP access – please verify
- On the live server behind its proxy: the address shown on Settings → Security → IP access is the client's ($nuConfigTrustProxy);
  a user with an extra access level (Valid until) and IP rules; nuBuilder Forte's shared login from an address that is not allowed.

## 0.8.721 New form on a view – please verify
- SQL Server: "can the database save to it" is guessed from the view's text (sys.sql_modules) – not run on a real SQL Server.

## 0.8.719 Advanced search – please verify
- SQL Server: the conditions use TRY_CAST(… AS DECIMAL(38,10)) / TRY_CAST(… AS DATE) and CAST(… AS NVARCHAR(4000)) on the list's
  columns – not run on a real SQL Server; a browse SQL with text dates (not DATE columns) on MySQL.

## 0.8.715 Webhooks – please verify
- A real receiver (n8n, Teams workflow, Zapier) over https; the call is sent after the answer on PHP-FPM (fastcgi_finish_request –
  the test server is PHP's built-in one, where the page waits); retries with the real cron; SQL Server (tables, the outbox query).
- CSV import into a form's table: one call per row (not covered by the e2e test).

## 0.8.693 Owner's list (users / access levels / permissions / security / compatibility / installer) – please verify
- Installer: *Languages to include* on Options (since 0.8.701 the installed languages: setting `languages`, fallback welcome_langs) – the
  interface, the user form and the welcome templates offer only these; Settings → General → *Languages* adds / removes them later.
- FIXED: the installer page stopped after its first lines since 0.8.632 (the theme menu asked NuAuth::user(), which read nuconfig.php) –
  dev/tests/install.js caught it once nuBuilder Forte's nuconfig.php was out of the way; fresh installations from 0.8.632 to 0.8.692 failed.

## 0.8.691 Samples as a showcase – please verify
- Sales sample (version 19, *Refresh* on the Sample data page): orders Status badges + filters Customer / Status, customer name → customer
  (browse JS, hidden column Customer id), products Active badge + filter Category, customers filter Country + credit > 30,000 red (JS test rule,
  only in nuBuilder Next – `if (window.nuNext)`), all objects Select / Checkbox badges, start form box "What to look at".
- Ticket sample (version 3): filters Assigned to / Requester, overdue Due badge, knowledge base Visibility badge (column renamed from
  "Visible to customers") + Category filter, categories Active badge + Team filter, "What to look at" on tkt_home for the team.
- nuBuilder Forte (git HEAD) wraps the ticket list's browse SQL as complex SQL: columns may not use the SQL's table aliases (now
  sub-queries / plain column names, so the list shows tickets there too), and it ignores the ORDER BY (compat-known.json) – decide whether
  nuBuilder Next should imitate the wrapping.
- In nuBuilder Forte: the orders list shows the customer link, the other badges / filters are absent (design-mode settings are nuBuilder Next only).

## 0.8.690 Admin tool lists – please verify
- Log: Object filter, record links (users, access levels, forms, procedures, reports, permission items – only while the record exists),
  long details folded with "more"; audit trail User as a select; e-mail log Tag filter shown, To → user; Notifications → Manage and
  Dashboards are system lists now (new pseudo forms nunextnotify / nunextdashboard); cloner lists show the search only above 10 rows.
- Update page: looks at GitHub again when the known release is not newer than the installed one and the last look is over an hour old
  (the card showed 0.8.600 "older than installed" because the daily look ran before v0.8.647 / v0.8.677 were published).

## 0.8.688 Forms and code lists: filters, links, badges – please verify
- Forms list: Access levels count → dialogue, table → database tool (only with a primary database tool), Draft filter; procedures Run filter,
  access → tab; reports Source type / source filters + links; SQL builder and e-mail templates are system lists now (views, export,
  filters, welcome badge); files Type / Used filters + count; items Form filter + link; formats Used → menu of the forms.
- Design mode list column "Show as badge" + colour rules (sbr_json nunext.badge, applied before nuOnLoad); New form wizard: Filter tick per list
  column. SQL Server: the SQL builder list concatenates 'SQL:' + id with + – not tried on a real SQL Server.

## 0.8.687 Users / access / permissions links, admin roles list, sessions grouped – please verify
- Users list filters Permission item / Access level (also additional), Department + Team in Default; access badge → level; user (i) links
  (log, changes, sessions); access levels: counts link, filter Can open form, "Show in the users list"; permission item users search /
  Active · Archived · All; admin roles = system list; Logged-in users filters User / Status, one folded row per user, End sessions per user.
- Global CSS: [hidden] now beats .d-flex / .d-block … (rows hidden by a filter stayed visible before) – watch for anything that relied on it.

## 0.8.684 Welcome e-mail preview – please verify
- User form: "Preview the e-mail before sending" (below Send welcome e-mail, off, remembered per browser) → Save checks, then the dialog
  #userWelcomePvDlg (iframe ?p=user&welcome_frame=… with its own CSP, designMode / HTML), template switch, Save and send / Cancel (no save).
  Check with a real mail client (Outlook / Gmail) that an edited mail looks right; welcome templates' Cc / Bcc are now also used without preview.

## 0.8.683 Access levels of a form (⋮ menu / design mode) – please verify
- Form ⋮ → Access levels… and design mode Form settings → Tools → Access levels: one dialog (assets/js/nu/nuformaccess.js, route ?p=formaccess)
  with Open + buttons + data mode / form type per level, search, group filter, Select all, column toggles. Check with a non-globeadmin admin
  (restricted: own levels locked, no new admin rights on nuform / nuuser … – the server refuses as the access level page does).

## 0.8.682 Ticket sample: list filters and badges, attachments, Due next rows – please verify
- Ticket sample version 2 (Sample data → Refresh on an installation with version 1): list filters Category / Priority / Status, badges for
  priority and status, attachments sub-form on the Attachments tab (was on Conversation), Due next / My latest tickets rows open the ticket in a new tab.
- Dashboard table widgets: column nu_record_id + link to a form → rows open the record; widget option "Open in a new tab" (also the title link).
- File fields: the remove (×) button lost its icon after a file was chosen – fixed.

## 0.8.681 nuBrowseStyleBadge with rules – please verify
- `nuBrowseStyleBadge(column, rules, options)`: map {value: colour | {…}} or rule list (equals / contains / startsWith / endsWith / regex /
  test(value, row) / default; bg / color / border incl. '$value', class, label, icon, title, badge: false, ignoreCase). Check on a real form,
  also in dark mode (own bg colours do not change with the theme – classes like text-bg-* do).

## 0.8.680 Clone as in nuBuilder Forte (own record pages) – please verify
- Procedures, reports, SQL builder, permission items, e-mail templates, files, items, prompts, Form cloner and App cloner settings: *Clone*
  opens `?id=new&clone=<id>` – nothing is saved until *Save*; badge "Clone – not saved yet", Save red, leave guard on. Users / access levels
  (already unsaved copies) got the badge and red Save too.
- Saving a clone needs the Clone right (not Add), as the old POST clone did. A report clone keeps the source layout as stored; a file clone keeps
  the stored file unless a new one is chosen; an App cloner clone keeps nuBuilder Forte's saved password / options of its source.

## 0.8.679 Ticket system sample – please verify
- Admin tools → Sample data → card "Ticket system": Install shows six passwords; sign in as tkt_clara (customer), tkt_alex (agent),
  tkt_sam (specialist), tkt_lea (team lead) – start pages, ticket list per role, a customer cannot open another customer's ticket,
  internal notes hidden for customers, Take ticket / Escalate, notifications, dashboards; with e-mail settings the three templates
  are sent after saving. Remove leaves nothing behind (rows, users, tables).
- Designer review with proposals: the shared doc "nuBuilder Next – form designer usability review" – pick the proposals to build.

## Multi-tenancy (30 Oct 2026) – proposal waiting for the owner's decision
Recommendation: **A1 · B1 · C1 · D2 · E1 · F1**. Pick the options, then it is built.

**A – How the customers are kept apart (the main decision)**
- A1 – One database per customer, one code installation (recommended): the code is installed once, each customer has a normal
  nuBuilder Forte database of their own. No data can leak between customers, each database stays usable in Forte, one customer can be
  backed up / restored / moved on their own, deleting a customer = dropping one database. To change: settings read once today become
  per customer, as do some folders (uploads, backups, page forms) and the session cookies; updates and cron run once per customer.
- A2 – One database with a customer column in the application tables: every list, lookup, report and procedure must filter by a new
  hash variable (e.g. #TENANT_ID#); Forte knows nothing about customers, so in Forte everyone sees everything; one forgotten filter shows
  one customer's data to another. Too risky for real separation.
- A3 – "Lite": customers as a user attribute (custom user field + #USER_TENANT# in the SQL, setting "Records only through the form's
  list", the compatibility check warns about lists of customer tables without the filter). Only for customers who trust each other
  (departments).

**B – How a request finds its customer (with A1)**
- B1 – By host name: kunde1.example.com → database nu_kunde1; sessions are separate per host automatically (recommended).
- B2 – By path: /nunext/t/kunde1/.
- B3 – A "Customer" field on the sign-in page.

**C – Where the customers are defined**
- C1 – A new file next to nuconfig.php (e.g. nutenants.php, never served): host, database access, display name, on / off per customer (recommended).
- C2 – A table of a main database: easier to manage from a page, but the database passwords would live in a database.

**D – The application (forms, procedures, reports)**
- D1 – Every customer has a completely separate application.
- D2 – Shared application (recommended): a template database holds it; "Publish to customers" copies forms, procedures, reports,
  formats … into every customer database (with a preview of the changes); users, access levels and data stay per customer. Builds on
  the App cloner and form snapshots.
- D3 – As D2, but a customer can keep chosen forms of their own that publishing does not overwrite.

**E – Administration**
- E1 – A super admin (the main installation's globeadmin) gets a "Customers" page: customers with their state (database version, last
  sign-in, size); create (new database: install scripts, sample or template application, its own globeadmin), switch off / on, back up,
  run updates. Each customer also has its own globeadmin who sees only their own installation (recommended; the same actions also as
  commands for scripts).
- E2 – No page, only commands: php cron.php tenants list | create | migrate | backup.

**F – Updates and cron**
- F1 – The update page installs the files once, then runs the database steps for every customer with a log per customer; a customer whose
  database fails is switched off, the others carry on. cron.php runs housekeeping and webhooks for each customer (recommended).
- F2 – Database steps run for a customer the next time someone opens it – simpler, but customers end up on different database versions.

**Before deciding**
- Shared by all customers: the library packs and the installed code. Per customer: _backup/, uploads, custom/pages/ data, temp folder.
- A1 also works on SQL Server, but it cannot be tested here (as before).
- Installations without the customers file keep working exactly as now.

## Code in files instead of in the form (suggestion from a user, 26 Oct 2026) – waiting for the owner's decision
The user's points: nuBuilder 4.x runs form JavaScript with eval() (no top-level const / let, objects survive "refresh" – namespaces
needed); he prefers JS / PHP in files on disk (overview, no duplicates, git, AI coding agents) with only links in the forms.
Checked in 0.8.569: in nuBuilder Next every form / record is a new page and the form's JavaScript a normal <script> – top-level
const / let / class work, nothing survives a refresh (event code runs as a Function; only nuAddJavascript() from PHP uses eval).
Global includes exist already ($nuConfigIncludeJS / CSS / PHP), page forms live in files. Missing: per-form files. Options (proposed):
- [ ] **D** Help page "Code in files": the new-page model (point 1), the include variables, a folder layout for a project in git. (recommended first)
- [ ] **A1** Form settings → *JavaScript files* (e.g. custom/js/customer.js): <script src> after the runtime, before the form's code,
      version stamp = file time; stored in sfo_json nunext (no new nuBuilder column). **A2** later: ES modules with export onLoad(form) …
- [ ] **B1** PHP hooks (BE, BS, AS, BB, BD, AD) from a file (e.g. custom/php/customer/before_save.php), same variables as the code in
      the form (B2: file + code).
- [ ] **C** Export / import of all form code, procedures and Setup header / style to a folder (code/forms/<code>/edit.js, before_save.php …)
      with a diff preview; the database stays the source (nuBuilder keeps working); optional automatic import in developer mode.
- Trade-off: A / B make forms depend on files – nuBuilder 4.x does not run that code; App cloner and backups must copy custom/ (as for
  page forms). C keeps nuBuilder compatibility but the code exists twice. Recommendation: D, then A1 + B1, then C.

## Open from 26 Oct 2026 (session after 0.8.551)
- [ ] Drawing order of objects (absolute layout): nuBuilder sorts content boxes first, then sob_all_order, then the tab order, run
      objects last at the same order, otherwise database order; nuBuilder Next sorts tab, sob_all_order, top, left. Matters only for
      objects with the same order value (stacking, keyboard Tab order) – align exactly if the owner wants it (0.8.567 fixed the z-index).
- [ ] Update → Libraries: a clearer message when a GitHub pack does not match its index ("the release does not match its index –
      publish packs and libraries.json from the same build"); 0.8.556 reads the index again before installing.
- [ ] Update → Libraries: show the date of the last GitHub check also next to an error, and drop a stored error saved under another
      PHP / nuBuilder Next version (proposed, not confirmed).
- [ ] A failed creation of the own tables at the globeadmin's first sign-in only goes to PHP's error log – show it to the globeadmin.
- [ ] Object menu (right-click): nuBuilder also offers it on words, images, tabs and subform titles – add if wanted.
- [ ] compat.js: edit:samplecustomer (cus_phone mask: nuBuilder shows the stored value, nuBuilder Next the masked one) and
      browse:ttcus (nuBuilder git HEAD 23559e7 "complex SQL in Browse Forms" shows no rows) – recorded in compat-known.json for the 0.8.600 gate;
      still to decide whether nuBuilder Next should show the stored phone value like nuBuilder Forte.

## Handover of 25 Oct 2026 (security assessment, nuFiles, specs 03–08 – base 0.8.460) – open
- [x] Step 2 done in 0.8.479 (nuFiles C1–C5, C9; reauth default `code`, data in `custom/pages/nufiles/_data/`). Open from nuFiles:
      C6 page cron hook (trash purge without visitors), C7 Security check entries for risky nuFiles settings, C10 more languages
      (French, Italian … – nuFiles has English and German).
- [x] Step 3 done in 0.8.480 (spec 04 field data access, spec 07 list column visibility). Open from the specs: rules are not copied
      by form clone / App cloner; builder object panel shows nuBuilder's access only (rules on Form builder → Data access); report
      overview "forms × fields × subjects"; logging reads of withheld fields; masked "replace the whole value"; report SQL stays the
      developer's (nuFieldAccessAllowed); Security check list of ro/hide fields while protect_fields is off.
- [ ] Step 4: CSV import restrictions (06), nuUpload (03), external DB connections (08) as needed.
- [ ] Security assessment, not built: HSTS header over HTTPS, CSP without 'unsafe-inline' / 'unsafe-eval' on form pages, constant-time
      sign-in, SameSite=Strict for the admin area. Re-run the assessment's test kit against the live server (HTTPS, real access levels).

## jQuery 4 (0.8.741)
- [ ] Please verify: Settings → Code → jQuery – test as globeadmin with jQuery 4 + Migrate warnings on the live forms, look at the collected
      messages and the Compatibility check point J4; then decide when to switch everyone. jquery-confirm is our build 3.3.4-nu1 ($.trim
      replaced); its project is inactive – replace it with an own dialogue if more breaks. A future default of jQuery 4 for new installations
      = change NuJquery::DEFAULTS['jq'].

## Bootstrap 6 (alpha 8 October 2026) – switch after the stable release
- [ ] Stay on Bootstrap 5.3.8 until 6.0 is stable; write the migration script when the beta is out (class names should not change after it).
- What the alpha changes (blog.getbootstrap.com, 2026-10-08): browsers Chrome / Edge 130+, Firefox 132+, Safari 18+ (the owner: older
  devices need not be considered); JavaScript as ES modules only – no `window.bootstrap`, no bundle with Popper; responsive and state
  classes with a prefix (`d-md-none` → `md:d-none`, `col-lg-6` → `lg:col-6`, `hover:` …); offcanvas → drawer, the accordion on native
  `<details>`, modals on `<dialog>`; CSS variables / tokens with a prefix set in the build (PostCSS) – our `var(--bs-…)` uses change.
- Prepared (0.8.740): `php dev/bs6_scan.php [--files] [--rule=…]` counts our own uses (rules in core/nubs6.php `NuBs6`; 0.8.740: ~530
  responsive classes in ~70 files, 1 offcanvas, 6 accordion, window.bootstrap only in apps/nufiles); the Compatibility check shows point
  BS6 = the same in the application's code (form / object / event JS, HTML objects, form CSS, Setup, Global code) – developer mode only.
- At the switch: `assets/js/nubs.js` imports the modules and sets `window.bootstrap` for nuBuilder Forte form code (it expects it);
  the migration script rewrites the classes of NuBs6 rule `responsive` in pages / core / assets; the side bar (offcanvas) → drawer;
  themes accordion → details; a check of every page (smoke, a11y). Form code of applications keeps Bootstrap 5 classes – decide then
  whether a small compatibility CSS for the old responsive classes ships with nuBuilder Next.

## Not supported yet
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
- [ ] The help (`docs/pages/*.htm` + `docs/toc.json`) is English only. Extend the matching page with every new feature (filtered per
      reader: `data-doc="admin"`, `data-acl="<form id>"`; advanced topics `data-level="advanced"`; no version numbers).
- [ ] Help restructure (0.8.493) – please verify the pages written new from the code: 01-00 overview, 01-01 key-concepts, 01-03 databases,
      02-00, 02-08 phones, all of 03 architecture, 04-01 form-lifecycle, 05-00..05-02 saving / save flow / validation, 06-00, 07-01
      sign-in methods, 08-00, 09-00, 10-06 table reference, 11-00, 11-03 theming, 11-04 bundled libraries, 12-00, 12-02 porting, 14-00,
      14-01 glossary, 14-03 FAQ; and whether the 24 "advanced" flags fit.

## Ideas – what could come next (rated 0.8.292)
Rating 1–10: **10 = must have** in a modern business-app framework · 7–8 important · 5–6 useful · 1–4 nice to have.
Checked against what nuBuilder Next already has (October 2026) – only real gaps. Effort: S = days, M = 1–2 weeks, L = more.

### Must have (9–10)
- [ ] **10 · Test on a real SQL Server** (L) – App cloner, backups, installer and forms on MSSQL were written without one (see 0.8.198).
      A feature that is claimed but never ran is a risk; a Docker SQL Server in CI would settle it (see Docker below).
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
- [x] **7 · Password policy** – done in 0.8.745 (Settings → Sign-in & security → Password policy). Please verify: the online leak check
      against the real api.pwnedpasswords.com (not reachable from the test container – tested with a fake), and the reminder texts.
- [x] **7 · Webhooks** – done in 0.8.715 (Admin tools → Webhooks; form saves / deletes and CSV imports, signed, retries by cron.php).
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
- [ ] **3 · Multi-tenancy** – several customers separated in one installation (proposal with options: section "Multi-tenancy" above).
- [ ] **2 · GraphQL API** besides REST.
- [ ] **2 · Plugin system** for third-party extensions.

**Suggested order:** concurrent-edit protection → scheduled backups → record change history → Docker + SQL Server / MySQL 8 tests →
deploy test → live → SSO / passkeys → xlsx, scheduled procedures, webhooks.
