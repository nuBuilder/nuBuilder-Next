# nuBuilder Next – features

What nuBuilder Next offers today. It is an alpha version: details change from release to release – see the
[changelog](CHANGELOG.md) for what changed when, and the documentation inside the app (user menu → *Documentation*, F1 on any
page) for how to use it.

## For everyone

- **Works on every screen** – phones, tablets and desktops; light, dark or automatic mode; checked against WCAG 2.1 A/AA.
- **Tidy lists on phones** – at most three buttons in a list's title bar (the rest in ⋮), folded filters, cards or table per list; system rows hidden until you ask for them.
- **Fast lists** – search, filters, sorting and paging; an advanced search with conditions per column and saved searches;
  coloured status badges; cells that open a form, a filtered list, a procedure or a report.
- **Totals and groups** – a row of totals below a list (sum, average, minimum, maximum, count) over everything the search matches, the totals of ticked rows, and lists grouped by a column with a heading and subtotals per group.
- **Row details** – a ▸ on each row of a list shows hidden columns, cut text and a related list (e.g. the items of an order) below the row.
- **Lists by keyboard** – search, ↓ into the rows, ↑ / ↓ / Page Down to move, Enter to open, Esc back to the search; the row is kept when you come back,
  shown with a soft rounded highlight and always scrolled into view.
- **Export** – every list as print page, CSV or Excel; copy rows straight into Excel or Word.
- **Comfortable editing** – sub-forms, lookups and file uploads; required and unique fields marked; unsaved changes shown;
  Clone opens an unsaved copy; fields you cannot change look quiet (soft fill, a small lock) but stay fully readable.
- **Start page** – tiles, favourites, recently used and one search over everything (also Ctrl+K); dashboards with KPI tiles,
  charts and tables.
- **Notification centre** – a bell with unread messages, optionally also by e-mail.
- **Keyboard shortcuts** for save, clone, delete, search, print and more; *Back* returns to the page you came from; success messages close by themselves, warnings stay.
- **Recently used** – the start page row and the side bar, optionally without the pages that are already favourites.
- **Secure sign-in** – two-step login (authenticator app or e-mail code), passkeys, single sign-on (OpenID Connect: Microsoft
  Entra ID, Google Workspace, Keycloak …) and *Forgot password* by e-mail.
- **Eleven languages** – including Arabic (right to left) and regional variants of English and German; themes per access level
  or chosen by the user.
- Pivot of any form list (Export → Pivot…): rows, columns, values and filters by drag and drop, date grouping (year … day), totals and subtotals, % of row / column / total, heat map, top N, chart, drill down to the records, saved and shared pivots (the samples bring some), CSV / Excel; a run button can show a saved pivot (iframe, popup, new tab)
- Calendar view of form lists (month / week / day / list): colour and filter chips per group, a click opens the record in a window, a click on an empty day adds one with start and end filled in, drag and drop saves through the form (rights, validation, audit); calendar objects on forms (a list's calendar filtered by the record, or SQL) and calendar widgets on dashboards

## For building applications

- **Form designer** – design a form where it runs: drag and arrange objects, a properties panel for every object type, the
  list's columns and settings right on the list, responsive layouts for phones (grid rows that always fit), tabs (with unique names), events and code in one place;
  *Properties…* in an object's right-click menu opens it selected in design mode.
- **A list's design in a side pane** – browse SQL, PHP (Before Browse), JavaScript, columns with all their settings, row details and list settings next to the running list;
  *As run* shows the SQL the list really ran, hash variables filled in and unset ones marked.
- **A form's code beside the record** – every JavaScript, CSS and PHP event of a form in a side pane on the record (Ctrl+Shift+B), grouped in cards, saved in one go.
- **Form info** – the form's details in section cards (form, data, list, record), description and code changed right in the panel.
- **Forms grouped** by group, department and team – shown and filtered in the forms list; forms, procedures, reports and SQL queries sorted by *Last opened*.
- **Drafts and history** – changes go into a draft that you publish when ready; every published version can be compared
  and restored.
- **Validation rules without code** – as a builder or an expression, as errors or warnings, also for sub-form rows and per
  access level.
- **Object types** – inputs, selects, lookups, sub-forms, charts, HTML, a WYSIWYG editor and run buttons (forms, reports,
  procedures, URLs, saved pivots – shown only to users who may open them), calendars on forms; grid sub-forms can fit their columns to the width.
- **Button settings in one dialogue** – right-click a button (or Alt+click): what it runs, target, record, filter, where it opens,
  JavaScript events, label, icon and access in one place, live or into the open draft.
- **New form wizard** – from a table or a view, with the columns and their order chosen up front; text or UUID keys for new
  tables; change a column's type with a data check first.
- **Forms with AI** – describe a form (or a small app of several forms), paste the prompt into any AI chat and the answer
  back; it is checked before anything is created and lands as a draft. A context file (functions, examples, table structure –
  no data) can be attached to the chat.
- **Code search** – one page searches the code of every form, event, procedure, SQL query, e-mail template, global code and
  custom page (text, words, regular expressions; filters by language, place, form group, project), jumps to the line in its
  editor, exports the hits and can replace in ticked lines – checked again before saving, with history and undo.
- **Code where you need it** – PHP procedures, JavaScript hooks and hash variables as in nuBuilder Forte; a code editor with
  syntax check, formatting, search / replace and an Insert menu with every documented function.
- **Reports and queries** – a visual report designer (text, HTML or Markdown fields), an SQL builder, PDF output.
- **Reports with AI** – describe a report, paste the AI chat's answer: the layout is checked, previewed as PDF and applied; optionally
  with a query written by the AI; earlier layouts are kept and can be restored.
- **AI providers** – configurations for Claude, OpenAI-compatible services (also local models) and Gemini; the AI dialogues then offer
  "Ask AI": prompt sent from the server, answer checked with one automatic correction round; per configuration access levels or a
  password that also encrypts the API key, a monthly token limit and a usage list.
- **Help pages for your users** – write help in the browser (visual editor or Markdown), open it from a form, a tab or a
  field in a side panel (link a page to forms right from its editor); whole pages or parts of a page only for some access levels, translations, search, export / import;
  drafted with AI in any installed language.
- **Dashboards** – KPI tiles, charts and tables built from SQL, on the start page or on any form.
- **Custom pages** – your own PHP apps inside nuBuilder Next, with its sign-in, rights, a stable page API, drafts and
  versions.
- **Data import and export** – CSV and Excel / OpenDocument into any table with column mapping; saved exports of tables,
  views and queries to Excel or CSV, streamed for any size.
- **Integration** – a REST API (OAuth 2.0 client credentials) with a playground, webhooks to n8n, Zapier, Teams or your own
  service (signed, with retries and a log), e-mail via SMTP or OAuth 2 (Google, Microsoft 365) with templates.
- **Docker** – an image with every PHP extension (also SQL Server) and compose files: one command starts nuBuilder Next with
  MariaDB, optionally with the sample application; a newer image updates the program and keeps the data.
- **Installable app** – install on phones and computers with its own icon and window, the branding's name and colour,
  favourites as shortcuts, a faster start and a clear page when offline.
- **Procedures by cron** – one command runs a procedure from the server's cron, as a chosen user, with a log entry and an
  e-mail when it fails.
- **Projects** – group the forms, procedures, reports, tables and other parts of one piece of work, with "Suggest related",
  Markdown notes, to-dos with notifications, lists narrowed to the project, and export / import as one package.
- **Cloners** – copy a single form or the whole application.
- **Sample applications** – a sales application and a ticket system for a support desk (with its project and help pages
  for its users per role), both built to learn from.

## For administrators

- **Users and rights** – access levels (also extra ones per user, with an end date), admin roles, permission items, custom
  user fields with worked-out defaults (dates, counters, templates, SQL, procedures); user import from CSV or Excel; welcome e-mails; password reset by the administrator without ever seeing a
  password; *Act as user* to see the application as a user does.
- **Security** – login rate limit, password policy with a leaked-password check, sign-in only from allowed networks, field
  access per user group, and a built-in Security check.
- **Audit trail and log** – every change per field, with history and restore; a log of logins, exports and admin changes (older entries can be deleted).
- **Start pages per access level** – users get their own tiles and can personalise them.
- **Maintenance in the browser** – signed updates with backup, rollback and a preview of database changes; restore the
  database from a backup (MySQL / MariaDB); a `nuconfig.php`
  editor; global JavaScript / CSS; system status; logged-in users; phpMyAdmin, Adminer and a file manager (recycle bin,
  versions, code editor, tiles with picture thumbnails; made for phones too).
- **Library packs** – install, update, roll back and remove older versions of the bundled libraries (TCPDF 7, PHPMailer, TinyMCE …) from the browser.
- **Easy start** – installer, setup wizard, a tutorial (basic, in depth, coming from nuBuilder Forte) and built-in
  documentation.
- **Branding and demo mode** – logo, title, environment label; read-only demo users.

## For nuBuilder Forte users

- **Same tables, same formats** – forms, reports, procedures, users and access levels work in both; records, forms and reports
  saved in nuBuilder Next stay usable in nuBuilder Forte.
- **Compatibility check** – scans your application's code and shows which functions are supported (on SQL Server also the SQL
  it does not understand).
- **Context menus of form code** (`nuOpenContextMenu`, `ctxmenu`) work as in nuBuilder Forte; a quick search lists the
  places that call functions nuBuilder Next does not have.
- **jQuery 4 ready** – form pages run on jQuery 3.7 or 4.0, with jQuery Migrate to find upgrade issues.
- **File manager for nuBuilder Forte** – the file manager is also available as a single file that replaces Tiny File
  Manager in a nuBuilder Forte installation, with its own light / dark switch.
- The differences that remain are listed in the documentation: *Code reference → Migrating from nuBuilder Forte 4.9*.
