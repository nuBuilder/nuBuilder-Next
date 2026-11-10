# nuBuilder Next – to do
Grouped by topic (0.8.909, the owner): every point starts with a short **title**, the version in brackets, then the details.
Older "please verify" sections (up to 0.8.677) are unchanged in `dev/TODO-archive.md`.

## Decisions for the owner
- [ ] **Fewer places for settings** (0.8.1002) – the owner wants to rethink where settings live (Shared settings, nuBuilder Next's own settings
      table, Branding, Settings → Look / General, Security settings, nuconfig.php …): too many places. Back Button on Phones went into the
      Shared settings for now (own row nuPhoneBackButton) – move it once the new structure is decided.
- [ ] **`browse_sql` in nuCurrentProperties() / nuHash()** (0.8.981) – nuBuilder Forte gives the list's full SQL; nuBuilder Next only the
      names of the unset hash variables (the SQL is not sent to the browser). Send the real SQL (forms that reuse it, e.g. to export the
      list in a procedure) or keep it as is. The owner decides later.
- [ ] **DeepSource** (0.8.944) – the owner looks into it later: turn it off (1), keep only the Secrets analyzer (2, recommended – change `.deepsource.toml`, goes up with the next gate push), or keep it and ignore the style rules (3). Findings so far assessed in 0.8.887 / 0.8.937–0.8.939.
- [ ] **dev / test / prod and git** (forum question, 26 Oct 2026): proposals M1–M3 (moving forms / procedures between installations) and
      G1–G3 (git sync of the application's metadata) – the owner decides later.
- [ ] **Custom pages: "Test copy"** – besides the draft (0.8.599) a clone of the custom page + its folder, published back (option A, not
      built); a page's draft shares _data, secrets.php and the database with the live version.
- [ ] **Test of the function reference examples** (proposed 2 Nov 2026): run.php only checks that every PHP / JS function has a complete
      entry in docs/nunext-functions.json; the examples `e` / sample results `x` are never executed. Proposal: an e2e test that runs every
      example – PHP against the test database, JavaScript on a test form – and compares with `x`; functions with side effects (mail,
      windows, downloads) marked "not runnable" in the entry.
- [ ] **Audit columns switch** (proposed 3 Nov 2026): created_at / created_by / updated_at / updated_by are stamped only for forms with
      sfo_json nunext.audit = true (set by the New form wizard for a new table). The mark is invisible – proposal: a switch in design
      mode → Form settings with an (i) tip, so it can be seen and turned off (and on for an existing table with the four columns).
- [ ] **Imitate Forte's wrapping of complex browse SQL?** (0.8.691): nuBuilder Forte (git HEAD) wraps the ticket list's browse SQL as
      complex SQL – columns may not use the SQL's table aliases and the ORDER BY is ignored (compat-known.json).
- [ ] **Masked values like Forte?** (compat.js): edit:samplecustomer – nuBuilder Forte shows the stored phone value, nuBuilder Next the
      masked one; browse:ttcus (Forte git HEAD 23559e7 "complex SQL in Browse Forms" shows no rows) – both in compat-known.json.
- [ ] **Drawing order of objects** (absolute layout): nuBuilder Forte sorts content boxes first, then sob_all_order, then the tab order,
      run objects last at the same order; nuBuilder Next sorts tab, sob_all_order, top, left. Matters only for objects with the same order
      value (stacking, keyboard Tab order) – align exactly if wanted (0.8.567 fixed the z-index).

## SQL Server
- [ ] **Restore database from a backup on SQL Server** (0.8.1031) – only offered on MySQL / MariaDB; the SQL Server dump (GO batches) is read by NuSqlImport (dialect sqlsrv), but dropping / restoring was not tried on a server.
- [ ] **SQL check on SQL Server** (0.8.999) – the code editors' SQL check uses SET NOEXEC ON there (compiled, not run); untested here: check that a wrong column / table is marked with the right line and that NOEXEC is switched off again.
- [ ] **Nothing ran on a real SQL Server yet** (no image / pdo_sqlsrv here; code checked with simulated catalogues and sqlglot; installation,
      lists and Adminer with a fixed TCP port work on the owner's SQL Server Express – 0.8.793): own tables and migration steps (0004,
      0020, 0021 …, missing columns added automatically), App cloner, backups with the whole database, list views' catalogue SQL, start
      page layouts, dashboards' read-only run (BEGIN TRAN … ROLLBACK), the access condition in the save transaction (SAVE TRANSACTION),
      concurrent edits (UPDLOCK, ROWLOCK), audit restore and demo data reset with IDENTITY keys, login token compare and swap on
      NVARCHAR(MAX), Change column (ALTER COLUMN, defaults as constraints), UUID keys (uniqueidentifier), custom user fields (ALTER TABLE),
      user import, Adminer through nudbadmin.php, the SQL builder list ('SQL:' + id).
- [ ] **ORDER BY fix** (0.8.792): browse lists, sub-forms and data exports on the owner's SQL Server Express.
- [ ] **Compatibility check card "SQL that SQL Server does not understand"** (0.8.791) on the owner's forms.
- [ ] **Form cloner dump for SQL Server** (0.8.794): the N'…' dump run in SSMS.
- [ ] **Fresh installation with the language choice** (0.8.790) on SQL Server.
- [ ] **CI job mssql** (0.8.889 / 0.8.903): check the next Actions run – sample application installed, every self-test check ok (report
      skipped: no TCPDF there), no PHP messages.
- [ ] **Passkeys table** (0.8.877): zzzzsys_nunext_passkey (cred_id 1400 characters, bigint counter) created by the update.
- [ ] **SHOW TABLES in selects** (0.8.879): read from the catalogue.
- [ ] **#ACCESS_LEVEL_IDS# / nuNextAccessLevels()** (0.8.867) in a browse SQL and an access condition.
- [ ] **Bound hash variables** (0.8.873) – `$nuDevSelectQueryRunParameterised = true`.
- [ ] **New form on a view** (0.8.721): "can the database save to it" is guessed from the view's text (sys.sql_modules).
- [ ] **Advanced search** (0.8.719): TRY_CAST(… AS DECIMAL(38,10)) / TRY_CAST(… AS DATE) and CAST(… AS NVARCHAR(4000)) on the list's columns.
- [ ] **Webhooks** (0.8.715): tables and the outbox query.
- [ ] **SQL builder list** (0.8.688): concatenates 'SQL:' + id with +.

## Live server, e-mail and deployment
- [ ] **Existing nuBuilder Forte database** (0.8.1008, no longer blocked): installer on a copy of the live Forte database – backup
      tick, integrity check, nuBuilder Forte keeps working afterwards; a hand-written nuconfig.php shows the backup note on the sign-in page.
- [ ] **E-mail → Test → Check connection** with the real SMTP server (also OAuth 2); PHPMailer 7.1.1 (0.8.894) with that server.
- [ ] **Cron and unattended updates**: `php nucron.php update` as the web server's user (--url over https, proxy), unattended update from
      GitHub; after the entry file rename (0.8.825 / 0.8.826) the crontab line (`nucron.php`) and Adminer bookmarks – `api.php` and
      `oauth.php` unchanged, a REST client and the OAuth 2 mail login still work.
- [ ] **nginx rules** from Help → Security.
- [ ] **mod_deflate rules** (0.8.765): pages still load with the rules in assets/.htaccess and vendor/bundled/.htaccess (needs
      "AllowOverride FileInfo"); a .js file's response headers show Content-Encoding: gzip.
- [ ] **Uploads with Uppy** into the real upload folder.
- [ ] **Webhooks with a real receiver** (0.8.715): n8n, Teams workflow, Zapier over https; the call is sent after the answer on PHP-FPM
      (fastcgi_finish_request – the test server is PHP's built-in one); retries with the real cron; a CSV import into a form's table = one
      call per row (not covered by the e2e test).
- [ ] **Library packs on a live server** (0.8.865): Update → Libraries status "Installed (pack / nuBuilder Forte's copy / copied by hand)".
- [ ] **Database tools in an installed app** (0.8.852–0.8.854): phpMyAdmin and Adminer open from the app window (Chrome / Edge "Install").

## Sign-in and security
- [ ] **Hardening from the security check of 0.8.1061** (decision: which to build) – no exploitable finding; four optional points:
      1. *Inline styles in list cells*: the cleaner keeps `style` (without `url()` / scripts), so `position: fixed` could draw over the
         page → allow only harmless properties (colour, font, padding, border).
      2. *Images with relative addresses in list cells*: a cell value can make a viewer's browser send a GET to any address of the app
         (no GET changes data except the language) → allow only `http(s)`, `data:image` and the file routes.
      3. *Unquoted hash variables in SQL* (`WHERE id = #x#`): escaping protects only quoted values; the bound-parameter switch
         `$nuDevSelectQueryRunParameterised` closes it but is off by default → a Compatibility check point listing such places.
      4. *`_nu_then` after a save* accepts a tab character (browsers strip it, `/<tab>/host` would leave the site; needs a valid CSRF
         token and a successful save) → refuse control characters.
- [ ] **2FA safe addresses** (0.8.958) – `$nuConfig2FASafeIPAddresses` (globeadmin list, users `*` or a login name; addresses, prefixes, `*` parts) skips the second factor – please verify behind the live proxy (the address is `nu_client_ip()`)
- [ ] **One password eye in Edge** (0.8.944): sign-in / user form show only nuBuilder Next's eye in Microsoft Edge; other password fields keep Edge's.
- [ ] **Passkeys on the live server** (0.8.877, https, the real domain): add one with Windows Hello, Touch ID / Face ID, an Android phone and
      a security key; a synced passkey (iCloud Keychain / Google Password Manager) on a second device; second factor and – with the switch
      on – sign-in without a password, also from the user name field; *Confirm with a passkey* before a dangerous action.
- [ ] **Passkeys behind the reverse proxy**: `$nuConfigBaseURL` set – the origin check uses its host; a wrong one shows "could not be
      checked" (the log says why). Lost device: remove a user's passkey on the user form; `php nucron.php passkeys off <login>`.
- [ ] **Single sign-on with a real provider** (0.8.863, tested only with dev/tests/fakeoidc.py): Microsoft Entra ID (tenant issuer, groups
      as object IDs / app roles), Google Workspace (hd), Keycloak (groups mapper); behind a reverse proxy the redirect URI shown must match
      the address the provider calls ($nuConfigBaseURL); nuBuilder Forte with $nuConfigLogonMode = 'sso' and $nuConfigSsoOnlyExcept:
      password form folded, exceptions sign in with the password.
- [ ] **Reset password…** (0.8.901, user form): a link by e-mail on the real mail server (template text, user's language), *Show the
      link*, *Block the current password now* with the user signed in elsewhere (also nuBuilder Forte with shared login), the confirmation e-mail.
- [ ] **Password policy** (0.8.745): the online leak check against the real api.pwnedpasswords.com (tested with a fake) and the reminder
      texts; an interval per access level now really forces the change (0.8.827 – it never did before).
- [ ] **IP access behind the proxy** (0.8.722): the address on Settings → Security → IP access is the client's ($nuConfigTrustProxy); a user
      with an extra access level (Valid until) and IP rules; nuBuilder Forte's shared login from an address that is not allowed.
- [ ] **Security assessment – not built**: HSTS header over HTTPS, CSP without 'unsafe-inline' / 'unsafe-eval' on form pages, constant-time
      sign-in, SameSite=Strict for the admin area. Re-run the assessment's test kit against the live server (HTTPS, real access levels).
- [ ] **Handover step 4** (25 Oct 2026): CSV import restrictions (spec 06), nuUpload (03), external DB connections (08) as needed.
- [ ] **Field data access – open from specs 04 / 07** (0.8.480): rules not copied by form clone / App cloner; report overview "forms ×
      fields × subjects"; logging reads of withheld fields; masked "replace the whole value"; Security check list of ro / hide fields while
      protect_fields is off.

## Forms and design mode
- [ ] **Grid rows** (0.8.1088) – please verify with the tester's converted forms: three wide fields in one row stay on one line, a dragged object / a lower column number keeps its place
- [ ] **Object menu on words, images, sub-form titles** (0.8.1082) – please verify right-click on a word, an image and a grid sub-form column title (Properties opens the child form)
- [ ] **Browse aliases with a sub-query in FROM** (0.8.1075) – please verify with the tester's list
- [ ] **UI review on devices** (0.8.1066–0.8.1070) – please verify on a real phone and a touch tablet: list title bars (only Back / Add / view menu, the rest in ⋮ with Card or table first), folded column filters of form lists, settings section select, full-size fields on touch tablets, Origin filter (procedures / items: own rows only by default; e-mail templates: own language) – is hiding the read-only nx_ templates under Own wanted?
- [ ] **Computed fields inside list columns** (0.8.1059, a tester's report) – please verify with the tester's real list: `IF(…, alias, '')`, `CONCAT(…, alias)`, `ORDER BY CAST(alias AS UNSIGNED)`, aliases in GROUP BY / HAVING
- [ ] **Help pages linked from the editor** (0.8.1054) – *Add to a form…* on a form with an open draft (published afterwards without a conflict); **content sheet** on screens ≥ 1680 px (0.8.1053) – look at forms with wide fixed layouts
- [ ] **Projects** (0.8.1035) – please verify on a real application: Suggest related on forms with many sub-forms / run buttons, the list filter Project while working on a project, export with table rows and import into a second installation (forms replaced → form history, new tables, project merged by code). Not built: a project filter in design mode's Structure (it lists one form only), Ctrl+K narrowed to the project, the working project kept across sign-ins
- [ ] **Responsive boxes** (0.8.959) – please verify on real forms: a box with a direction inside the auto / grid layout (side by side wrapping, grid 3 · 2 · 1, fixed / fit / fill widths, Hide on phone), nested boxes, "Put in a new box". Not built: moving an object into / out of a box by drag in design mode (use the Box field of the Layout section), a preview switch for phone / tablet width in design mode
- [ ] **Validation rules** (0.8.812–0.8.816): on a real form a rule in design mode (builder and expression), Publish, then save as a user –
      error stops, warning asks "Save anyway?", the field turns red / yellow while typing; sub-form rows and COUNT(sub-form) with rows added /
      deleted before saving; formatted numbers and dates (raw values compared); rules per access level / permission item; translated
      messages with #field#; hash cookies set by the form's own JavaScript (nuSetProperty) on both sides.
- [ ] **Form status Maintenance** (0.8.808) with nuBuilder Forte on the same database (Forte shows it as "Disabled").
- [ ] **Browse list settings from Forte** (0.8.820–0.8.823) on forms made in Forte: row height, multi-line titles, columns that fill the
      width, row click target (new tab / popup / current breadcrumb).
- [ ] **Column click actions** (0.8.872) on a real list: open a form / a filtered list (`'#PARENT_ID#'` in the target's browse SQL), a
      procedure and a report; Ctrl + click, phones (cell button in the card view), a user without the right on the target (plain value).
- [ ] **Bound hash variables** (0.8.873): `$nuDevSelectQueryRunParameterised = true` with your own browse SQL, selects and access
      conditions – especially hash variables inside longer strings (`LIKE '%#X#%'`).
- [ ] **Select options** (0.8.879): SQL that starts with `-- ` comment lines, hash variables in `value|text` and JSON lists, `SHOW TABLES` /
      `%LANGUAGES%` (names from nuBuilder Forte's `core/languages/`; without Forte next to it: the installed languages), the same form in
      Forte; `nuSelectOptions()` in a procedure.
- [ ] **Sub-form filters** (0.8.878): `nuSubformAddFilter` on a lookup column lists and matches the code – code that compared the
      description in `nuSubformOnFilterRows` (`data.val`) gets the code now, as in Forte.
- [ ] **Run buttons by rights** (0.8.829): a user without the right to a run object's form / report / procedure does not see it (also User
      Home's Fast Form buttons); form code that shows / hides such a button by its ID still works.
- [ ] **Run type Open URL** (0.8.830): a URL with #RECORD_ID#, new tab / popup / this tab, the iframe method with a site that allows framing.
- [ ] **Design mode on a phone** (0.8.831): one-line bar + ⋯ menu, bar hides on scroll down, sheet peek / half / full (handle tap, swipe),
      quick toolbar above the selected object (Up / Down / Duplicate / Delete) on a real phone.
- [ ] **Design mode extras** (0.8.765): Redo (Ctrl+Y), the "Messages of the form" button, "+" of the list design asks for the column;
      design state (events, PHP hooks, lookup After Browse code) complete on a form with many objects (0.8.827).
- [ ] **Design mode Add → Input** (0.8.846): the second page with the frequent kinds.
- [ ] **New form start page** (0.8.848–0.8.851): the four cards, tables and views as two groups in the table list.
- [ ] **Form with AI** (0.8.810, 0.8.841–0.8.843): a small app with a real AI tool (nunext-app-1), changes from design mode into the open
      draft, the three-step wizard, *None – new tables only*, an answer with "type": "input" + "input": "textarea".
- [ ] **AI context file** (0.8.961) – *Download context* in Form with AI (step 2) and design mode's Ask AI: please verify with a real AI chat (Claude, ChatGPT) that the compact .md (~110 KB) is accepted and that answers with it use the documented functions; the full file (~300 KB, zip ~90 KB) may be too big for some chats. Example forms = install/ai-examples.json (sample application) – regenerate when the form format changes
- [ ] **Custom pages** (0.8.859–0.8.861): Forms → Add → *Custom page* (description, suggested code, open mode) creates the page and opens
      its editor; Forms → Tools → *Custom pages* (also the ▾ of the Forms tile) lists only them; the wizard no longer offers the type.
- [ ] **Code snippets** (0.8.899): source code edited in place; language switch; templates (`nu…` rows) read only outside developer mode.
- [ ] **First field focus** (0.8.899) on record pages of forms with a searchable select first (no list opens any more).
- [ ] **Phone layout of own forms** (0.8.764): fixed-position forms stacked (label above, order top / left) – nuNextPhoneLayout(false) for
      a form that must keep it; form lists as cards, embedded lists (run objects) grow to their rows.
- [ ] **Lists refreshed in place** (0.8.776): forms with their own browse JavaScript still load normally; back / forward in the browser.
- [ ] **Right-click menus outside design mode** (0.8.919–0.8.929): tab menu (rename, visibility, move, delete), object menu with *Clone*
      (copy below the original with events and code) and Top / Left / Width / Height (saved on release of the spin arrows), the compact
      spacing; on a list the column title menu (rename, align, width, filter, move, hide, delete, Properties → design mode) – also with an
      open draft.
- [ ] **Browse SQL pane** (0.8.930–0.8.932): ▾ of *Design* on a list → *Edit browse SQL…* and Ctrl+Shift+B; Save / Ctrl+S, the pin keeps it
      open on the next page and on other lists; a browse SQL that ends in a comment line. Tab *Columns* (0.8.942 / 0.8.943 / 0.8.946 /
      0.8.947): *Edit columns…* opens it; settings + columns edited and saved together (also with an open draft), drag a column by its
      number, drag the pane's left edge (width kept, double-click = default); design panel's column list rows can be dragged too.
- [ ] **Testers' reports** (0.8.945): the Form / Procedure lists of run buttons and list click actions open above the design panel;
      icons (Font Awesome / Bootstrap) and simple HTML in list titles and cells of an imported Forte application; `nuRunPHPHidden(code, true)`
      with a `nuBeforeSave()` that returns false. Open: TinyMCE toolbar missing in some old forms (details asked from the tester).
- [ ] **Badge rules** (0.8.681 / 0.8.688): `nuBrowseStyleBadge(column, rules, options)` and design mode "Show as badge" + colour rules on a
      real form, also in dark mode (own bg colours do not change with the theme – classes like text-bg-* do).
- [ ] **Access levels of a form** (0.8.683, ⋮ menu / design mode Tools): check with a non-globeadmin admin (own levels locked, no new admin
      rights on nuform / nuuser …).
- [ ] **Clone as in Forte** (0.8.680): procedures, reports, SQL builder, permission items, e-mail templates, files, items, prompts, cloner
      settings – *Clone* opens `?id=new&clone=<id>`, nothing saved until *Save* (needs the Clone right); a report clone keeps the layout as
      stored, a file clone keeps the file, an App cloner clone keeps the source's saved password / options.
- [ ] **Sub-form columns in the designer**: edit them inside the sub-form; default values (Forte has no such field – would need sob_all_json).
- [ ] **New table column grid**: no reordering yet (the existing-table column list has it, 0.8.675).

- [ ] **Help pages for users – please verify** (0.8.1023 / 0.8.1024) – write a page with pictures (pasted screenshots become files in
  custom/userhelp/ – check that the folder is writable on the live server), link it to a form / tab / field in design mode, read it as a
  user of two access levels ([[level: …]] parts), translation, export / import into the second server. Decided: no (?) on list
  columns for now. Open: a Help tile on user start pages by default?
  Draft with AI (0.8.1025): related screens (sub-forms ticked, pick lists and forms opened by buttons offered) in the copied prompt.
- [ ] **Lists by keyboard and row details** (0.8.1026 / 0.8.1027): on a real list – search, ↓, ↑ / ↓ / Page Down, Enter, Back (same row
  focused again), Esc; row details with *Hidden columns and cut text* and a *Related list* on a form with a sub-form (lookups shown by
  their text, fields of a user with field access rules hidden / masked), Space / → / ← on a row, the phone card view.
- [ ] **Back = the page before** (0.8.1017 / 0.8.1018): Back on own pages and form pages after opening them from different places (start
  page, side bar, search, a link in a record); after the browser's own Back.
- [ ] **Form department / team** (0.8.1020): set in design mode, filter the forms list by them.
- [ ] **UI review suggestions** (0.8.1007): phone header of the system lists (← Back above, icons right of the title), menus near the
      bottom open upwards, report error page in dark mode with Home, shared setting titles in the user's language, server paths in
      tooltips relative to the installation.
- [ ] **Start page search** (0.8.855 / 0.8.864): right-click / long press on a tile result → favourite / hide, the search stays after the
      reload; the selected result in dark blue with white text – readable in light and dark mode, also with an own theme.
- [ ] **Start page rows: Auto collapse** (0.8.828).
- [ ] **List column widths** (0.8.910 / 0.8.931 / 0.8.933): titles stay at the top while scrolling; columns keep the stored widths on every
      page (long values end in …) on forms with and without *Auto resize*; dragged widths stay put while paging (no jump) and in a new tab.
- [ ] **One Form cloner tile** (0.8.934): a start page with "Clone form" pinned from the Forms ▾ shows only the Form cloner tile.
- [ ] **Compact lists and record pages** (0.8.832–0.8.835) with real data; procedures' two field rows and (i) texts (0.8.837 / 0.8.838).
- [ ] **Global code page** (0.8.856 / 0.8.857): the switch *Form pages | All pages* with the short tabs on a phone; × hides the warning,
      the (i) next to the title shows it again.
- [ ] **Settings centre** (0.8.763): sections Users & access / Logs & integration; Security check tabs; Data & logs with one Save.
- [ ] **Admin tool lists** (0.8.690): log Object filter, record links (only while the record exists), long details folded with "more";
      audit trail User as a select; e-mail log Tag filter, To → user; Notifications → Manage and Dashboards as system lists; cloner lists
      show the search only above 10 rows.
- [ ] **Forms and code lists** (0.8.688): forms list Access levels count → dialogue, table → database tool, Draft filter; procedures Run
      filter, access → tab; reports Source type / source filters + links; SQL builder and e-mail templates as system lists; files Type /
      Used filters; items Form filter; formats Used → menu of the forms.
- [ ] **[hidden] beats .d-flex / .d-block** (0.8.687, global CSS): watch for anything that relied on the old behaviour.
- [ ] **Browsers**: Safari (ApexCharts on the start page, CSP style-src-attr), phones with real touch (long tap menus, side bar, design mode).
- [ ] **Real data**: list views of every system list (default columns, sort, filters, print), `#USER_PERMISSIONS#` with real permission
      items, sub-form link columns without an index on forms made before 0.8.366.

## Users, access levels and permissions
- [ ] **User field defaults** (0.8.1074) – please verify: today+30d, next:EMP-{0000}, {first_name}.{last_name}, = SELECT …, proc:<code> on a new user, data import and REST
- [ ] **User form layout** (0.8.1018 / 0.8.1019): the Person card with initials, the three cards below on a laptop and a phone.
- [ ] **Admin roles / Logged-in users on phones** (0.8.1021 / 0.8.1022): the rights table in own columns (sort by area / full access)
  and the Logged-in users cards on a real phone.
- [ ] **Users / access links** (0.8.687): users list filters Permission item / Access level (also additional), Department + Team; access
      badge → level; user (i) links (log, changes, sessions); access levels: counts link, filter Can open form, "Show in the users list";
      permission item users search; admin roles as a system list; Logged-in users filters, one folded row per user, End sessions per user.
- [ ] **Forms for everyone** (0.8.784): a new / cloned access level gets the forms; the Compatibility check point FE on a database edited
      with nuBuilder Forte afterwards.
- [ ] **Renamed user field titles** (0.8.798 / 0.8.799) also in nuBuilder Forte's user form on the same database.
- [ ] **Data import templates** (0.8.870): the *Templates* menu in step 1 (users template ZIP) for a user with only the user import right.
- [ ] **Welcome e-mail preview** (0.8.684): check with a real mail client (Outlook / Gmail) that an edited mail looks right; welcome
      templates' Cc / Bcc are also used without the preview.
- [ ] **Installer languages** (0.8.693 / 0.8.701): *Languages to include* on Options – the interface, the user form and the welcome
      templates offer only these; Settings → General → *Languages* adds / removes them later.
- [ ] **Accessibility features switch**: define what `sus_accessibility_features` should do in nuBuilder Next (the switch on the user form
      is hidden – `NU_FEATURE_ACCESSIBILITY` in `core/nuapp.php`; saving a user keeps the stored value).

## Reports and dashboards
- [ ] **TCPDF 7 pack** (0.8.1062) – please verify a report with pictures (photos per row, a logo by web path) on the live server after installing the 7.0.22 pack; Roll back to 6 if anything is left out
- [ ] **Report pictures with TCPDF 7** (0.8.1062): a report with photos (`<img src="/folder/x.jpg">` in an HTML field, an image object with an
      address of the own server) on a server with the TCPDF 7 pack – they were left out before. Pictures from another host stay blocked
      with TCPDF 7 (decision: a setting for further hosts?).
- [ ] **Reports with HTML fields** (0.8.874): an existing report with "Fields contain HTML" on real data (page breaks follow the printed
      height now); an HTML / Markdown field that runs over a page break; the same report saved in Forte's designer (is `contentType` kept?).
- [ ] **Dashboard table widgets** (0.8.682): column nu_record_id + link to a form → rows open the record; option "Open in a new tab".

## Code editors and libraries
- [ ] **Visual editor (TinyMCE) look** (0.8.1040–0.8.1042): help pages, notifications, editor fields of forms – no HTML source and no
      white box while it loads (also dark mode), light ⇄ dark switches the editor at once without marking the record as changed.
- [ ] **Help pages → Draft with AI** (0.8.1037 / 0.8.1038): the field Language; an answer in Markdown becomes headings / lists in the
      visual editor.
- [ ] **CodeMirror 6 in every editor** (0.8.898, kept – the owner 0.8.1080): procedures, Global code, nuconfig.php, data export SQL, design mode code
      window / workspace, form page code window (double-click), code snippets, File Manager editor – typing, Ctrl+F, Ctrl+/, Ctrl+- / Ctrl++
      shortcuts (0.8.905), Tab, suggestions (nu…), Format, syntax check marks, dark mode, phones; custom pages that load
      `vendor('codemirror', …)` and call more of the CodeMirror 5 API than the adapter offers.
- [ ] **Updated libraries** (0.8.893–0.8.902): TinyMCE 8.10.1 pack (editor objects, notifications), Font Awesome 7.3.1 fallback,
      js-beautify 2 / Prettier 3.9 formatting of your own code, ApexCharts 4.7 dashboards.
- [ ] **jQuery 4** (0.8.741): Settings → Code → jQuery – as globeadmin with jQuery 4 + Migrate warnings on the live forms, look at the
      collected messages and the Compatibility check point J4; then decide when to switch everyone (default for new installations =
      NuJquery::DEFAULTS['jq']). jquery-confirm is our build 3.3.4-nu1 ($.trim replaced); its project is inactive – replace it with an own
      dialogue if more breaks.
- [ ] **Bootstrap 6** (alpha 8 Oct 2026): stay on 5.3.8 until 6.0 is stable; write the migration script when the beta is out. The alpha:
      Chrome / Edge 130+, Firefox 132+, Safari 18+; JavaScript as ES modules only (no `window.bootstrap`, no bundle with Popper); prefixed
      responsive / state classes (`d-md-none` → `md:d-none`, `col-lg-6` → `lg:col-6`); offcanvas → drawer, accordion on `<details>`,
      modals on `<dialog>`; prefixed CSS variables (our `var(--bs-…)` change). Prepared (0.8.740): `php dev/bs6_scan.php [--files]
      [--rule=…]` (~530 responsive classes in ~70 files) and the Compatibility check point BS6 (developer mode). At the switch: nubs.js
      imports the modules and sets `window.bootstrap` for Forte form code; the script rewrites the classes; side bar → drawer; themes
      accordion → details; check every page (smoke, a11y); decide on a compatibility CSS for application code.

## Update page, backups and admin tools
- [ ] **File Manager on phones** (0.8.1056–0.8.1058): folder list closes by a tap outside / swipe / Esc, the path as ‹ parent + folder name ▾, the toolbar in one row – please verify on a real phone (also a very long folder name, 320 px screens)
- [ ] **Installer** (0.8.1047–0.8.1049): start step with passed checks folded, no library packs in the dump list, all packs ticked – please verify with a fresh installation on the live host
- [ ] **Masking personal data in copies** (idea from mydumper's masquerade, 0.8.1032) – masking rules per column (e-mail, names, phone) for the App cloner download / copies of live data on test systems; not built.
- [ ] **Log: Delete older entries** (0.8.1087) – please verify on the live server (30 / 90 / 365 days, all; the password is asked again)
- [ ] **Restore database from a backup** (0.8.1031 / 0.8.1032 – way back on failure, row counts, free space): please verify on the live server with a copy – a whole-database backup and a nuBuilder-tables backup, with the backup first; large dumps (read line by line) and the time limit of the host (the restore sets none).
- [ ] **GitHub pack does not match its index**: a clearer message ("publish packs and libraries.json from the same build"); 0.8.556 reads
      the index again before installing.
- [ ] **Last GitHub check date** also next to an error, and drop a stored error saved under another PHP / nuBuilder Next version (proposed).
- [ ] **Update page re-check** (0.8.690): looks at GitHub again when the known release is not newer than the installed one and the last
      look is over an hour old.
- [ ] **Own tables at the first sign-in**: a failed creation only goes to PHP's error log – show it to the globeadmin.
- [ ] **Reset database from a package** (0.8.844 / 0.8.847, developer mode): a nunext.zip → fresh installation; the upload is gone from
      _update/ afterwards.
- [ ] **Settings cache** (0.8.827): a page right after a setting was saved shows the new value; the update page's Check package / history
      still show the installed version.
- [ ] **nuFiles – open**: C6 page cron hook (trash purge without visitors), C7 Security check entries for risky nuFiles settings, C10 more
      languages (French, Italian … – nuFiles has English and German).
- [ ] **CI on GitHub** (0.8.889 / 0.8.903): mysql + mssql jobs with the self-test step and the PHP message check (start-up warnings
      "in Unknown on line 0" are notices now).

## Samples
- [ ] **Sales sample showcase** (0.8.691, version 19 – *Refresh*): orders Status badges + filters Customer / Status, customer name →
      customer, products Active badge + filter Category, customers filter Country + credit > 30,000 red (JS test rule, nuBuilder Next only),
      Select / Checkbox badges, start form box "What to look at".
- [ ] **Ticket sample** (0.8.679 / 0.8.682 / 0.8.691): install shows six passwords; sign in as tkt_clara (customer), tkt_alex (agent),
      tkt_sam (specialist), tkt_lea (team lead) – start pages, ticket list per role, a customer cannot open another customer's ticket, internal
      notes hidden, Take ticket / Escalate, notifications, dashboards, the three mail templates; filters / badges, attachments tab, Due next
      rows; Remove leaves nothing behind.
- [ ] **Ticket sample project and help pages** (0.8.1036 / 0.8.1039, version 7 – *Refresh*): Sample data → Ticket system → Contents →
      *Open the project* (22 parts, notes, to-dos); help pages from the ticket form's ? button / F1, the tab Handling and the field Due –
      sign in as tkt_clara and tkt_alex and compare the page Tickets; Categories only for tkt_lea.
- [ ] **Samples in nuBuilder Forte**: the orders list shows the customer link, the other badges / filters are absent (design-mode settings).
- [ ] **Sample data page** (0.8.858): *Remove* in the title row of a sample; greyed out while another user has a sample access level.
- [ ] **Form designer usability review**: the shared doc "nuBuilder Next – form designer usability review" – pick the proposals to build.

## nuBuilder Forte compatibility
- [ ] **File manager for nuBuilder Forte** (0.8.1055–0.8.1058, one file `tinyfilemanager.php`, built by `dev/forte/build_nufiles_forte.py`) – please verify in a real Forte installation: sign-in from Forte's File Manager entry, `$nuConfigFileMangerUsers`, the Uploads folder, the code share with password, `_nufiles_data` blocked on the web server (nginx: deny it or set `data_dir`). Untested: SQL Server (log table), chmod on the host
- [ ] **List cells as div inside the td, sub-form cell ids, nuAddRow, label positions, canvas growth** (0.8.965) – please verify on a real application: row colouring by `div[id^='nucell_…']`, lists in phone view, column widths by dragging, design mode of lists; code that selects sub-form cells by id; Add row buttons; forms whose code moves labels.
- [ ] **Second Forte database compared form by form** (0.8.965–0.8.978) – please verify on the live application: framed lists with
      properties of the opening form and run filters on hidden columns, hash variables of the opening edit form, lookups whose id is not
      in their list shown empty, multiple selects as list boxes (Select2 off), selects with a value that is not an option shown empty,
      checkbox `val()` = "on", `data-nu-form` on fields, unclosed `<s>` in labels, launch forms left without asking, layout helpers
      (`nuGetWordWidth` …) and `$(…).clone()` of fixed-layout fields, `_nulog` "edited" only for main-record changes.
- [ ] **Forum announcements 1–7** (0.8.958) – please verify on a real Forte database: attribute `value=` only on new records, selects with a Select procedure, no blank item with validation No Blanks / No Duplicates (Forte 4.9.2 git; 4.9.1 still shows it), display objects with several columns (text area), `nuOnProcessObjects()` / `nuOnProcessBrowseRows()`, print view settings `nuPrint…()`. Not built yet (rows 8–13 of the review): settings marked "open", datalist value / label pairs, JS helpers (`nuSelectInParent…`, `nuBrowseCells`), debug auto refresh, `onSubformTitleClick`, mobile attributes, `nuapi.php` progress
- [ ] **Forte system forms as forms** (0.8.960) – the compat suite opens every Forte system form (nuuser, nuform, nuobject …) in nuBuilder Next (`sysform:<form>`): list, record, save without a change, then Forte opens the record. Fixes it found: input buttons never validated, `nuBeforeSave()` without a return value saves (only `false` stops), `containsAny` / `nuImages` / `nuSearchableMultiPopup` / `browse_*` properties, a sub-form's label left of it, narrow buttons keep their label (`nuBtnTight`), tab panes carry `data-nu-pane-tab` (the Procedures form writes into `div[data-nu-tab-id]`). Please verify on a real database: forms with 50–60 px wide buttons, sub-form labels in the absolute layout. Not changed: Forte's own CSS classes (`.nuQuickButton`, `.nuRelatedTab`) are not styled
- [ ] **JavaScript placeholders** (console warning; the list in assets/js/nu/nuruntime.js): `nuPrintExcludeColumns`, `nuResizeBrowseColumns`,
      `nuSetBrowseColumnWidth`, `nuOpenWiki`, `nuSetSnippetFormFilter`, `nuCreateDialog`, `nuAccessFormSetButtonIcons`,
      `nuBrowseStyleStatusColumn`, `nuDragElement`, `nuShowVersionInfo`, `nuHasHiddenModalDragDialog`; also `nuEnableMultiSelect`,
      `nuShowFile`, `nuSetDateFormat`, `nuSetTabHelpButtons`, `nuSetSideBarStyle`, which Forte 4.9's JavaScript does not have (kept so old
      code does not fail). Admin tools → Compatibility check shows which ones your forms call.
- [ ] **Forte git HEAD drops the ORDER BY of complex browse SQL** (0.8.956) – nuBuilder Forte's commit 23559e7 ("Support complex SQL queries in
      Browse Forms", 2026-10-25) wraps a browse SQL with a sub-query / alias as `SELECT * FROM (…) AS wrapped` and empties its ORDER BY, so such
      lists come unsorted; 4.9.1 and nuBuilder Next keep the order. dev/tests/compat.js against Forte HEAD reports it as "records differ" – worth
      telling the Forte developer; until then compare with a Forte before that commit.
- [ ] **Please verify on a Forte database** (0.8.956) – a lookup into a form whose list is built by its Before Browse (#TABLE_ID#), the
      database session time zone (NOW() / FROM_UNIXTIME() as in Forte), lists without ORDER BY in the table's own order, save without a
      change on access levels / procedures / SQL builder keeping NULL columns and spaces around codes.
- [ ] **Please verify: functions aligned with Forte** (0.8.951) – on your own forms: `nuSetValue(…, false)` no longer marks the field as
      edited (saved only when something else is edited); `nuSelectedTabNumber()` returns text; `nuCreateTableFromSelect` no longer drops an
      existing table (add a DROP first where that was relied on); `nuHasProperty` returns the value; `nuMessage(…, ms)` closes by itself;
      `nuAddActionButton('Save')` and the other built-in ids; `nuHasBeenEdited()` / `nuTinyMCEOnChangeHandler()` with your own TinyMCE set-up (0.8.950).
- [ ] **`nuSetValidationErrorTitle()`** does nothing.
- [ ] **Forte-style list filter buttons** (0.8.912–0.8.917): `nuSetProperty` + `nuSearchAction()` from buttons / selects of the browse
      JavaScript, `nuBrowseLoop()`, a permalink `?f=&r=&h=` written by form code, `<input type=button>` labels; the (i) panel shows the
      browse SQL as run with its hash variables.
- [ ] **Content box height** (0.8.920) next to the same form in Forte.
- [ ] **PHP extras** (0.8.922–0.8.926): `$nudata` with `nuGetNuDataValue` / `nuSetNuDataValue` in Before Save, `nuNextSaveValue` /
      `nuNextSetSaveValue`, `nuGetFormProperties`, `nuGetURLParams`, `nuCreateTableFromSelect` with parameters, `nuAddJavascript` options.
- [ ] **Shared login** only nuBuilder Forte → nuBuilder Next; the other way would need a change in Forte.
- [ ] **Compatibility check on the owner's forms** (real data).

## Documentation
- [ ] **"Where is it now?" page for Forte users** (0.8.985, proposal C of the older user guide, optional) – every button of Forte's Setup / Builders tabs and Options menu with its place in nuBuilder Next.
- [ ] **Help reading level** (0.8.884): Help → *Essentials* – read a few chapters you know (Object types, Users, Install): is anything
      hidden that a reader needs, or is a page still too heavy? `python3 -I dev/doc_details.py --list` lists the remaining candidates.
- [ ] **Help restructure** (0.8.493) – the pages written new from the code: 01-00 overview, 01-01 key-concepts, 01-03 databases, 02-00,
      02-08 phones, all of 03 architecture, 04-01 form-lifecycle, 05-00..05-02 saving / save flow / validation, 06-00, 07-01 sign-in methods,
      08-00, 09-00, 10-06 table reference, 11-00, 11-03 theming, 11-04 bundled libraries, 12-00, 12-02 porting, 14-00, 14-01 glossary, 14-03
      FAQ; and whether the 24 "advanced" flags fit.
- [ ] **Code in files – help page** (option D of the proposal below): the new-page model, the include variables, a folder layout for a
      project in git.
- [ ] **English only**: the help (`docs/pages/*.htm` + `docs/toc.json`) – extend the matching page with every new feature.

## Proposal: multi-tenancy (30 Oct 2026) – waiting for the owner's decision
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
- E2 – No page, only commands: php nucron.php tenants list | create | migrate | backup.

**F – Updates and cron**
- F1 – The update page installs the files once, then runs the database steps for every customer with a log per customer; a customer whose
  database fails is switched off, the others carry on. nucron.php runs housekeeping and webhooks for each customer (recommended).
- F2 – Database steps run for a customer the next time someone opens it – simpler, but customers end up on different database versions.

**Before deciding**
- Shared by all customers: the library packs and the installed code. Per customer: _backup/, uploads, custom/pages/ data, temp folder.
- A1 also works on SQL Server, but it cannot be tested here (as before).
- Installations without the customers file keep working exactly as now.

## Proposal: code in files instead of in the form (user suggestion, 26 Oct 2026) – waiting for the owner's decision
The user's points: nuBuilder 4.x runs form JavaScript with eval() (no top-level const / let, objects survive "refresh" – namespaces
needed); he prefers JS / PHP in files on disk (overview, no duplicates, git, AI coding agents) with only links in the forms.
Checked in 0.8.569: in nuBuilder Next every form / record is a new page and the form's JavaScript a normal <script> – top-level
const / let / class work, nothing survives a refresh (event code runs as a Function; only nuAddJavascript() from PHP uses eval).
Global includes exist already ($nuConfigIncludeJS / CSS / PHP), page forms live in files. Missing: per-form files. Options (proposed):
- [ ] **D – Help page "Code in files"** (recommended first; also listed under Documentation).
- [ ] **A1 – Form settings → JavaScript files** (e.g. custom/js/customer.js): <script src> after the runtime, before the form's code,
      version stamp = file time; stored in sfo_json nunext (no new nuBuilder column). **A2** later: ES modules with export onLoad(form) …
- [ ] **B1 – PHP hooks from a file** (BE, BS, AS, BB, BD, AD; e.g. custom/php/customer/before_save.php), same variables as the code in
      the form (B2: file + code).
- [ ] **C – Export / import of all form code** (procedures, Setup header / style) to a folder (code/forms/<code>/edit.js, before_save.php …)
      with a diff preview; the database stays the source (nuBuilder keeps working); optional automatic import in developer mode.
- Trade-off: A / B make forms depend on files – nuBuilder 4.x does not run that code; App cloner and backups must copy custom/ (as for
  page forms). C keeps nuBuilder compatibility but the code exists twice. Recommendation: D, then A1 + B1, then C.

## Ideas – what could come next (rated 0.8.292)
Rating 1–10: **10 = must have** in a modern business-app framework · 7–8 important · 5–6 useful · 1–4 nice to have.
Checked against what nuBuilder Next already has (October 2026) – only real gaps.

### Must have (9–10)
- [ ] **10 · Test on a real SQL Server** – App cloner, backups, installer and forms on MSSQL were written without one (see SQL Server above);
      a Docker SQL Server in CI would settle it (see Docker below).
- [ ] **9 · Scheduled automatic backups** – today only "Back up now" and the backup before an update. Daily / weekly by nucron.php with the
      database, a keep rule, optional copy to another folder / server (SFTP, S3) and a warning on the start page when the last backup is too old.
- [ ] **9 · Deploy changes from a test to the live system** – export forms, procedures, reports, access levels, settings as one versioned
      package (JSON), show a diff on the target and apply selected parts.
- [x] **8 · Single sign-on with OpenID Connect** – built in 0.8.863.
- [x] **8 · Passkeys (WebAuthn)** – built in 0.8.877.
- [ ] **8 · OpenAPI description of the REST API** – generated from forms and rights, Swagger-style view in the API playground.
- [ ] **8 · Accessibility audit (WCAG 2.2 AA)** – keyboard use of forms and lists, screen reader labels, contrast in both themes.
- [ ] **7 · Record-level rights** – rules per access level like "only own department" without writing them into every browse SQL.
- [ ] **7 · Excel (.xlsx) export and import** – partly done (list exports 0.8.398, import 0.8.755, data export 0.8.758); subforms and reports to xlsx open.
- [ ] **7 · Scheduled procedures** – run a procedure hourly / daily / monthly through nucron.php, with log and e-mail on error.
- [x] **7 · Password policy** – built in 0.8.745 (verify points under Sign-in and security).
- [x] **7 · Webhooks** – built in 0.8.715.
- [ ] **7 · Global search / command palette** – Ctrl+K: find forms, records (chosen fields), settings and pages from anywhere.

### Useful (5–6)
- [ ] **6 · Workflow / approvals** – status field + allowed transitions + who may do them + notification, defined without code.
- [x] **6 · In-app notifications** – the notification centre (0.8.357).
- [ ] **6 · Recycle bin for records** – deleted records restorable for n days (soft delete per form, opt-in).
- [x] **6 · Dashboard builder** – built in 0.8.298.
- [ ] **6 · Docker image / docker-compose** – quick install and demo; CI with MySQL 8, MariaDB and SQL Server.
- [ ] **6 · Health / status endpoint** – JSON for monitoring tools: database reachable, disk space, age of last backup and cron run.
- [ ] **5–6 · The settings marked "Not yet"** on the Settings page – sticky list headers, close after save, user form labels, keep session
      alive, browser tab title, calendar options … Do the ones users ask for.
- [x] **5 · Branding** – built in 0.8.297.
- [ ] **5 · Secrets encrypted at rest** – SMTP password, API secrets and similar values in the database encrypted with a key from nuconfig.php.
- [ ] **5 · E-mail queue** – send in the background with retry; failed mails visible and resendable.
- [ ] **5 · GDPR helpers** – export / erase all data of one person across tables; retention rules per table.
- [ ] **5 · Smarter code editor** – autocomplete of tables, columns and hash variables in SQL / PHP / JS (functions done in 0.8.593).

### Nice to have (1–4)
- [ ] **4 · Installable app (PWA)** – icon on the phone, faster start; offline only for reading.
- [x] **4 · "Who is editing" presence** – built with the concurrent-edit protection (0.8.401).
- [ ] **3 · File storage on S3 / WebDAV** instead of the server folder / database.
- [ ] **3 · Multi-tenancy** – see the proposal above.
- [ ] **2 · GraphQL API** besides REST.
- [ ] **2 · Plugin system** for third-party extensions.
