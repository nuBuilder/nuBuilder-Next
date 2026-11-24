# nuBuilder Next – features

What nuBuilder Next offers today. It is an alpha version: details change from release to release – see the
[changelog](CHANGELOG.md) for what changed when, and the documentation inside the app (user menu → *Documentation*, F1 on any
page) for how to use it.

## For everyone

- **Works on every screen** – phones, tablets and desktops; light, dark or automatic mode; checked against WCAG 2.1 A/AA and the target size of WCAG 2.2 (24 px on touch screens, 16 px text in fields so phones do not zoom, nothing below 12 px).
- **One header on phones** – every page starts with ‹ previous page and its title; a record's title on its own row with the buttons below; tabs that scroll in one row; icon buttons on tablets; start page notices in one strip that folds.
- **Fast pages** – own statements sent in one step, compressed pages, minified scripts and styles, form definitions of sub-forms and lookups read together, the help kept ready built.
- **Tidy lists on phones** – at most three buttons in a list's title bar (the rest in ⋮), folded filters (also on tablets when a list has more than three), cards or table per list; system rows hidden until you ask for them.
- **View mode of a record** – the whole record as a read-only data sheet: every tab at once, sub-forms as tables, key facts on top, ← / → through the list, prints alone; every form and the user form; per form "Always open in view mode" for each user.
- **Fast lists** – search, filters, sorting and paging; an advanced search with conditions per column and saved searches;
  coloured status badges; cells that open a form, a filtered list, a procedure or a report.
- **Totals and groups** – a row of totals below a list (sum, average, minimum, maximum, count) over everything the search matches, the totals of ticked rows, and lists grouped by a column with a heading and subtotals per group.
- **Row details** – a ▸ on each row of a list shows hidden columns, cut text, a read-only detail form and related lists as tabs (sub-forms and child tables, e.g. the items and invoices of an order) below the row; one or several open, Expand all, kept while paging, hooks for own content.
- **Lists by keyboard** – search, ↓ into the rows, ↑ / ↓ / Page Down to move, Enter to open, Esc back to the search; the row is kept when you come back,
  shown with a soft rounded highlight and always scrolled into view.
- **Export** – every list as print page, CSV or Excel; copy rows straight into Excel or Word.
- **Comfortable editing** – sub-forms, lookups (a window sized to its list, the current value marked, Clear, keyboard) and file uploads; required and unique fields marked; unsaved changes shown;
  Clone opens an unsaved copy; fields you cannot change look quiet (soft fill, a small lock) but stay fully readable.
- **Start page** – tiles, favourites, recently used and one search over everything (also Ctrl+K); dashboards with KPI tiles,
  charts and tables.
- **Notification centre** – a bell with unread messages, optionally also by e-mail; on wide screens the editor shows everything at once
  with a live preview of the notification and a summary of who gets it.
- **Keyboard shortcuts** for save, clone, delete, search, print and more; *Back* returns to the page you came from; success messages close by themselves, warnings stay.
- **Favourites from the search** – a star on each tile in the start page search adds or removes the favourite at once (Undo,
  Ctrl+Shift+F).
- **Recently used** – the start page row and the side bar, optionally without the pages that are already favourites.
- **Secure sign-in** – two-step login (authenticator app or e-mail code), passkeys, single sign-on (OpenID Connect: Microsoft
  Entra ID, Google Workspace, Keycloak …) and *Forgot password* by e-mail.
- **Eleven languages** – including Arabic (right to left) and regional variants of English and German; themes per access level
  or chosen by the user.
- Pivot of any form list (Export → Pivot…): rows, columns, values and filters by drag and drop, date grouping (year … day), totals and subtotals, % of row / column / total, heat map, top N, chart, drill down to the records, saved and shared pivots (the samples bring some), CSV / Excel; a run button can show a saved pivot (iframe, popup, new tab)
- Calendar view of form lists (month / week / day / list): colour and filter chips per group, a click opens the record in a window, a click on an empty day adds one with start and end filled in, drag and drop saves through the form (rights, validation, audit); calendar objects on forms (a list's calendar filtered by the record, or SQL) and calendar widgets on dashboards

## For building applications

- **Debug icon** – a red bug with a count appears in the top bar as soon as new Debug results are written (form code errors, failing queries, nuDebug()); a click lists the newest, *Mark as read* hides it, *Delete all* empties the Debug results
- **Form designer** – design a form where it runs: drag and arrange objects, a properties panel for every object type, the
  list's columns and settings right on the list, responsive layouts for phones (grid rows that always fit), tabs (with unique names), events and code in one place;
  *Properties…* in an object's right-click menu opens it selected in design mode; the properties panel has section chips that stay
  at the top (jump, fold / unfold all, one-line summaries of folded sections); *Set order by position* sorts the tab order by where
  the objects are (rows or columns, content boxes kept together); a selected content box keeps its objects visible and moves them
  along; *Discard* only while the draft holds changes; the switch *Audit columns* (Form settings → Data) shows and sets whether the server fills
  created_at / created_by / updated_at / updated_by; the properties panel can be dragged wider (two columns of sections from about 620 px).
- **A list's design in a side pane** – browse SQL, PHP (Before Browse), JavaScript, columns with all their settings, row details and list settings next to the running list;
  *As run* shows the SQL the list really ran, hash variables filled in and unset ones marked; *▶ Run* (Ctrl+Enter) runs the SQL in the editor, also unsaved, and shows the rows with paging.
- **A form's code beside the record** – every JavaScript, CSS and PHP event of a form in a side pane on the record (Ctrl+Shift+B), grouped in cards, saved in one go;
  a label in the pane's title says where Save goes (Live, Draft, Not published yet, Live – draft open, Draft needed).
- **Form info** – the form's details in section cards (form, data, list, record), description and code changed right in the panel.
- **Forms grouped** by group, department and team – shown and filtered in the forms list; forms, procedures, reports and SQL queries sorted by *Last opened*.
- **Drafts and history** – changes go into a draft that you publish when ready; every published version can be compared
  and restored.
- **Validation rules without code** – as a builder or an expression, as errors or warnings, also for sub-form rows and per
  access level.
- **Object types** – inputs, selects, lookups, sub-forms, charts, HTML, a WYSIWYG editor and run buttons (forms, reports,
  procedures, URLs, saved pivots – shown only to users who may open them), calendars on forms; grid sub-forms can fit their columns to the width and show their rows as cards on phones (or keep the table).
- **Button settings in one dialogue** – right-click a button (or Alt+click): what it runs, target, record, filter, where it opens,
  JavaScript events, label, icon and access in one place, live or into the open draft.
- **New form wizard** – from a table or a view, with the columns, their order and the object each one gets (select with its options, lookup, Yes/No select, rich text …) chosen up front; text or UUID keys for new
  tables; change a column's type with a data check first.
- **Forms with AI** – describe a form (or a small app of several forms), paste the prompt into any AI chat and the answer
  back; it is checked before anything is created and lands as a draft. A context file (functions, examples, table structure –
  no data) can be attached to the chat.
- **Code search** – one page searches the code of every form, event, procedure, SQL query, e-mail template, global code and
  custom page (text, words, regular expressions; filters by language, place, form group, project), jumps to the line in its
  editor, exports the hits and can replace in ticked lines – checked again before saving, with history and undo.
- **Code where you need it** – PHP procedures, JavaScript hooks and hash variables as in nuBuilder Forte; a code editor with
  syntax check, formatting, search / replace and an Insert menu with every documented function.
- **Names while typing** – the code editors suggest tables (after FROM / JOIN), the columns of an alias and `#` hash variables, in SQL and in the strings of PHP / JavaScript.
- **Reports and queries** – a visual report designer (text, HTML or Markdown fields), an SQL builder, PDF output.
- **Reports as Excel** – any report also downloads as an Excel file: groups and totals as in the PDF on one sheet, all rows on a second.
- **Reports with AI** – describe a report, paste the AI chat's answer: the layout is checked, previewed as PDF and applied; optionally
  with a query written by the AI; earlier layouts are kept and can be restored.
- **AI providers** – configurations for Claude, OpenAI-compatible services (also local models) and Gemini; the AI dialogues then offer
  "Ask AI": prompt sent from the server, answer checked with one automatic correction round; per configuration access levels or a
  password that also encrypts the API key, a monthly token limit and a usage list. The prompt generator sends its prompt directly too –
  optionally with the context file – and shows the answer formatted, with Copy on each code block.
- **Help pages for your users** – write help in the browser (visual editor or Markdown), open it from a form, a tab or a
  field in a side panel (link a page to forms right from its editor); whole pages or parts of a page only for some access levels, translations, search, export / import;
  drafted with AI in any installed language.
- **Dashboards** – KPI tiles, charts and tables built from SQL, on the start page or on any form.
- **Custom pages** – your own PHP apps inside nuBuilder Next, with its sign-in, rights, a stable page API, drafts and
  versions.
- **Sign-in for own PHP apps** – plain PHP apps in a sub-folder include `nuexternal.php` and get the signed-in user,
  permissions, teams and access levels of nuBuilder Next (no custom page needed).
- **Data import and export** – CSV and Excel / OpenDocument into any table with column mapping; saved exports of tables,
  views and queries to Excel or CSV, streamed for any size.
- **Integration** – a REST API (OAuth 2.0 client credentials) with a playground, webhooks to n8n, Zapier, Teams or your own
  service (signed, with retries and a log), e-mail via SMTP or OAuth 2 (Google, Microsoft 365) with templates.
- **Records over the API** – read, create, change and delete the records of your forms (with sub-form rows, filters, lookups)
  exactly as the form does it – rights, validation, Before / After Save; an OpenAPI description per client for Postman or Power Automate.
- **Docker** – an image with every PHP extension (also SQL Server) and compose files: one command starts nuBuilder Next with
  MariaDB, optionally with the sample application; a newer image updates the program and keeps the data.
- **System info** – user menu → System info…: versions of nuBuilder Next, PHP, database and browser at a glance, copied as text for support
- **Page width** – wider pages per device (start page → Customise → Appearance) (standard, wide, full width or a pixel value), with a default for everyone in Settings → Look
- **Compact settings** – a switch on the settings overview and its sections hides the descriptions (remembered per device)
- **Installable app** – install on phones and computers with its own icon and window, the branding's name and colour,
  favourites as shortcuts, a faster start and a clear page when offline.
- **Procedures by cron** – one command runs a procedure from the server's cron, as a chosen user, with a log entry and an
- **Health check** – `?p=health` answers monitoring tools in JSON: database, free disk space, age of the newest backup and of the last cron run; details only with a key, limits in Settings → Data & logs, also `php nucron.php health`.
  e-mail when it fails.
- **Procedures list searches the code** – tick *Code* in the search field to find procedures by their PHP or JavaScript.
- **Suggestions while typing** – a text input offers a list (fixed, or the rows of a query with the typed text as a bound value) and a choice can fill other fields too; `nuNextAutocomplete()` in code, and a stand-in for jQuery UI's `autocomplete`.
- **Where procedures are used** – a "Used in" column and filter on the procedures list (buttons, objects, reports, dashboard widgets,
  start page tiles, calls in code; hooks marked) with a menu of every place, the same list on the procedure page.
- **Projects** – group the forms, procedures, reports, tables and other parts of one piece of work, with "Suggest related",
  Markdown notes, to-dos with notifications, lists narrowed to the project, and export / import as one package (with access
  rights, translations, new table columns and a restore point before anything is replaced).
- **Development, test and production** – export the whole application as one package, or as a folder for git on the command line
  (one folder per form / procedure / report, code in files of its own, the same database gives the same files) and import it on
  another installation – parts that exist only on the target are listed and deleted only when chosen; the environment label tells
  the installations apart.
- **Cloners** – copy a single form or the whole application.
- **Sample applications** – a sales application and a ticket system for a support desk (with its project and help pages
  for its users per role), both built to learn from; the Sample data page shows what changed in each sample version.

## For administrators

- **Users and rights** – access levels (also extra ones per user, with an end date; a level whose users have all expired can be deleted), admin roles, permission items, custom
  user fields with worked-out defaults (dates, counters, templates, SQL, procedures); user import from CSV or Excel; welcome e-mails; password reset by the administrator without ever seeing a
  password; *Act as user* to see the application as a user does, and *Test role* to try an admin role on a chosen user before handing it out.
- **Security** – login rate limit, password policy with a leaked-password check, sign-in only from allowed networks, field
  access per user group, records reachable only through a form's list (switchable per form), and a built-in Security check.
- **Audit trail and log** – every change per field, with history and restore; a log of logins, exports and admin changes (older entries can be deleted).
- **Encrypted secrets** – stored secrets (2FA, OAuth mail, single sign-on, webhooks, AI keys, SMTP password copy) encrypted with the key of nuconfig.php; the SMTP password can be kept only encrypted; key rotation on the command line.
- **Recycle bin** – forms can keep deleted records (with their sub-form rows) for n days: Undo right after the delete, a page to look at and restore them.
- **Tile pages per access level** – users get their own tiles and can personalise them; or, per access level, the Home form opens
  after sign-in as in nuBuilder Forte – set on the access level's tab *Start page*, with a preview of the tiles.
- **Start pages at a glance** – the globeadmin lists every Home form and every tile page with their access levels from the button right of
  Customise on the start page (or a right-click / long press on Home): tabs Home forms / Tile pages, search, Open and Design on one line, User Home in the head
- **Maintenance in the browser** – signed updates with backup, rollback and a preview of database changes; restore the
  database from a backup (MySQL / MariaDB); in developer mode, reset the database from a SQL dump of any size (runs in short steps with a progress window); a `nuconfig.php`
  editor; global JavaScript / CSS; system status; logged-in users; phpMyAdmin, Adminer and a file manager (recycle bin,
  versions, code editor, tiles with picture thumbnails; made for phones too).
- **Plugins** – extensions of other developers in `plugins/<id>/`: pages, start page tiles, menu entries, admin rights, settings, events (save, delete, sign-in, e-mail), REST routes and cron tasks through a versioned plugin API; signed packages, upload only when switched on, switched off by themselves after an error, safe mode, checked before every update.
- **Library packs** – install, update, roll back and remove older versions of the bundled libraries (TCPDF 7, PHPMailer, TinyMCE …) from the browser.
- **Easy start** – installer, setup wizard, a tutorial (basic, in depth, coming from nuBuilder Forte) and built-in
  documentation.
- **Installer with a nuBuilder Forte dump** – a complete dump of a Forte database (any size from `_install/`, checked statement by
  statement, imported in steps with a progress figure; older 4.x brought to 4.9).
- **Existing older Forte databases** – the installer and *Set up the database* bring an existing database of nuBuilder Forte before
  4.9 (e.g. 4.5) to the structure of 4.9 after a backup tick, as Forte's own update does.
- **Getting ready checklist** – after the installation a bar and a start page card lead through 30 points in eight steps (security,
  server, database, backups, e-mail, people, look, next steps); most check themselves, the rest are ticked by hand; hide for 7 days
  or for good – new red security or backup points bring it back; dots on the steps and a link to the next point to fix.
- **Branding and demo mode** – logo, title, environment label; read-only demo users.

## For nuBuilder Forte users

- **Same tables, same formats** – forms, reports, procedures, users and access levels work in both; records, forms and reports
  saved in nuBuilder Next stay usable in nuBuilder Forte.
- **Compatibility check** – scans your application's code and shows which functions are supported (on SQL Server also the SQL
  it does not understand).
- **Context menus of form code** (`nuOpenContextMenu`, `ctxmenu`) work as in nuBuilder Forte; a quick search lists the
  places that call functions nuBuilder Next does not have.
- **Forte databases start as in Forte** – access levels with a Home form open it after sign-in; lists in run objects follow the
  fields of the form around them; `browse_sql`, checkbox values and `__FILE__` as Forte gives them to form code.
- **Code of older nuBuilder Forte versions keeps running** – lookups whose list lacks the key, the earlier names of the
  upload procedures, self-built Uppy uploads, `$phpid` and `nuSubformObject('')` in events, includes of Forte's core files,
  scripts that place lookup parts or select the tabs by id, `nuOnPropertySet`, records opened under an id the table does not have.
- **jQuery 4 ready** – form pages run on jQuery 3.7 or 4.0, with jQuery Migrate to find upgrade issues.
- **File manager for nuBuilder Forte** – the file manager is also available as a single file that replaces Tiny File
  Manager in a nuBuilder Forte installation, with its own light / dark switch.
- The differences that remain are listed in the documentation: *Code reference → Migrating from nuBuilder Forte 4.9*.
