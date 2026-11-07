# nuBuilder Next – features

What nuBuilder Next offers today. It is an alpha version: details change from release to release – see the
[changelog](CHANGELOG.md) for what changed when, and the documentation inside the app (user menu → *Documentation*, F1 on any
page) for how to use it.

## For everyone

- **Works on every screen** – phones, tablets and desktops; light, dark or automatic mode; checked against WCAG 2.1 A/AA.
- **Fast lists** – search, filters, sorting and paging; an advanced search with conditions per column and saved searches;
  coloured status badges; cells that open a form, a filtered list, a procedure or a report.
- **Export** – every list as print page, CSV or Excel; copy rows straight into Excel or Word.
- **Comfortable editing** – sub-forms, lookups and file uploads; required and unique fields marked; unsaved changes shown;
  Clone opens an unsaved copy.
- **Start page** – tiles, favourites, recently used and one search over everything (also Ctrl+K); dashboards with KPI tiles,
  charts and tables.
- **Notification centre** – a bell with unread messages, optionally also by e-mail.
- **Keyboard shortcuts** for save, clone, delete, search, print and more.
- **Secure sign-in** – two-step login (authenticator app or e-mail code), passkeys, single sign-on (OpenID Connect: Microsoft
  Entra ID, Google Workspace, Keycloak …) and *Forgot password* by e-mail.
- **Eleven languages** – including Arabic (right to left) and regional variants of English and German; themes per access level
  or chosen by the user.

## For building applications

- **Form designer** – design a form where it runs: drag and arrange objects, a properties panel for every object type, the
  list's columns and settings right on the list, responsive layouts for phones, tabs, events and code in one place.
- **Drafts and history** – changes go into a draft that you publish when ready; every published version can be compared
  and restored.
- **Validation rules without code** – as a builder or an expression, as errors or warnings, also for sub-form rows and per
  access level.
- **Object types** – inputs, selects, lookups, sub-forms, charts, HTML, a WYSIWYG editor and run buttons (forms, reports,
  procedures, URLs – shown only to users who may open them).
- **New form wizard** – from a table or a view, with the columns and their order chosen up front; text or UUID keys for new
  tables; change a column's type with a data check first.
- **Forms with AI** – describe a form (or a small app of several forms), paste the prompt into any AI chat and the answer
  back; it is checked before anything is created and lands as a draft. A context file (functions, examples, table structure –
  no data) can be attached to the chat.
- **Code where you need it** – PHP procedures, JavaScript hooks and hash variables as in nuBuilder Forte; a code editor with
  syntax check, formatting, search / replace and an Insert menu with every documented function.
- **Reports and queries** – a visual report designer (text, HTML or Markdown fields), an SQL builder, PDF output.
- **Dashboards** – KPI tiles, charts and tables built from SQL, on the start page or on any form.
- **Custom pages** – your own PHP apps inside nuBuilder Next, with its sign-in, rights, a stable page API, drafts and
  versions.
- **Data import and export** – CSV and Excel / OpenDocument into any table with column mapping; saved exports of tables,
  views and queries to Excel or CSV, streamed for any size.
- **Integration** – a REST API (OAuth 2.0 client credentials) with a playground, webhooks to n8n, Zapier, Teams or your own
  service (signed, with retries and a log), e-mail via SMTP or OAuth 2 (Google, Microsoft 365) with templates.
- **Cloners** – copy a single form or the whole application.
- **Sample applications** – a sales application and a ticket system for a support desk, both built to learn from.

## For administrators

- **Users and rights** – access levels (also extra ones per user, with an end date), admin roles, permission items, custom
  user fields; user import from CSV or Excel; welcome e-mails; password reset by the administrator without ever seeing a
  password; *Act as user* to see the application as a user does.
- **Security** – login rate limit, password policy with a leaked-password check, sign-in only from allowed networks, field
  access per user group, and a built-in Security check.
- **Audit trail and log** – every change per field, with history and restore; a log of logins, exports and admin changes.
- **Start pages per access level** – users get their own tiles and can personalise them.
- **Maintenance in the browser** – signed updates with backup, rollback and a preview of database changes; a `nuconfig.php`
  editor; global JavaScript / CSS; system status; logged-in users; phpMyAdmin, Adminer and a file manager.
- **Easy start** – installer, setup wizard, a tutorial (basic, in depth, coming from nuBuilder Forte) and built-in
  documentation.
- **Branding and demo mode** – logo, title, environment label; read-only demo users.

## For nuBuilder Forte users

- **Same tables, same formats** – forms, reports, procedures, users and access levels work in both; records, forms and reports
  saved in nuBuilder Next stay usable in nuBuilder Forte.
- **Compatibility check** – scans your application's code and shows which functions are supported (on SQL Server also the SQL
  it does not understand).
- **jQuery 4 ready** – form pages run on jQuery 3.7 or 4.0, with jQuery Migrate to find upgrade issues.
- The differences that remain are listed in the documentation: *Code reference → Migrating from nuBuilder Forte 4.9*.
