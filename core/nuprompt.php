<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * AI prompt generator (nuBuilder's "Prompt Generator", table zzzzsys_prompt_generator).
 * Builds a complete prompt from instruction + context (table schemas, languages, scopes, topics) + the request,
 * for copying into any AI tool – nuBuilder Next never calls an AI itself.
 * Lists are stored as JSON arrays of codes (nuBuilder's multiple select format).
 */
final class NuPrompt
{
	public const TABLE = 'zzzzsys_prompt_generator';
	/** column => max length (0 = longtext) */
	public const LEN = [
		'pge_code' => 300, 'pge_description' => 300, 'pge_table' => 1000, 'pge_language' => 1000, 'pge_scope' => 300,
		'pge_prompt' => 0, 'pge_tag' => 3000, 'pge_template' => 1, 'pge_instruction' => 3000,
	];
	public const MAX_TABLES = 15;

	/** nuBuilder Next's repository on GitHub (raw files an AI chat can read): source, function reference, documentation pages. */
	public const REPO = 'https://raw.githubusercontent.com/nuBuilder/nuBuilder-Next/refs/heads/main/';
	private const DOCS = self::REPO . 'docs/pages/';
	private const FNREF = self::REPO . 'docs/nunext-functions.json';
	/** nuBuilder Forte's wiki – most of it applies to nuBuilder Next too (same functions); nuBuilder Next's reference wins where they differ. */
	public const WIKI = 'https://wiki.nubuilder.cloud/index.php?title=';

	/** Default instructions of earlier versions – replaced by the current one when a record still holds them. */
	private const OLD_INSTRUCTIONS = [
		'Generate code snippets and explanations following nuBuilder conventions. Use the information below when relevant to the request. '
			. 'This context is provided to help understand the requirements. If something is unclear or missing, say so instead of guessing.',
	];

	public static function defaultInstruction(): string
	{
		return 'Generate code snippets and explanations for nuBuilder Next, a front end for nuBuilder Forte 4.9 that uses the same tables and the same '
			. 'PHP / JavaScript functions. Use only the functions listed in nuBuilder Next\'s function reference (' . self::FNREF . '); a function that is not '
			. 'listed does not exist. The nuBuilder Forte wiki mostly applies too; where it differs, nuBuilder Next\'s reference is right. Use the information below when relevant to the request. If something is unclear or missing, say so instead of guessing.';
	}

	/** The instruction of a record – the current default while it still holds an earlier default. */
	public static function instruction(string $stored): string
	{
		return in_array(trim(str_replace("\r\n", "\n", $stored)), self::OLD_INSTRUCTIONS, true) ? self::defaultInstruction() : $stored;
	}

	// ------------------------------------------------------------------ catalog (codes as in nuBuilder Forte)

	/** @return array<string, array{label: string, text: string}> */
	public static function languages(): array
	{
		$d = self::DOCS; $r = self::REPO; $f = self::FNREF; $w = self::WIKI;
		return [
			'nuphp'        => ['label' => 'PHP', 'text' => "PHP 8 – use nuBuilder Next's database functions (nuRunQuery() with ? parameters, db_fetch_array(), db_fetch_object()), never string-concatenated SQL: [PHP functions]({$d}10-01-php-api.htm) | [Function reference (JSON)]({$f}) | [nuphp_api.php]({$r}core/form/nuphp_api.php) | [nuphp_compat.php]({$r}core/form/nuphp_compat.php) | [nuBuilder Forte wiki]({$w}PHP)"],
			'nujavascript' => ['label' => 'JavaScript', 'text' => "JavaScript – use nuBuilder Next's JavaScript functions (nuGetValue(), nuSetValue(), nuHide(), nuShow(), nuMessage() …): [JavaScript functions]({$d}10-02-js-api.htm) | [Function reference (JSON)]({$f}) | [nuruntime.js]({$r}assets/js/nu/nuruntime.js) | [nucompat.js]({$r}assets/js/nu/nucompat.js) | [nuBuilder Forte wiki]({$w}Javascript)"],
			'nujquery'     => ['label' => 'jQuery', 'text' => "jQuery (bundled with nuBuilder Next: 3.7 or 4.0, chosen by the administrator) – object ids are the field names: \$('#field_name'): [jQuery version]({$d}08-17-jquery.htm)"],
			'nuhtml'       => ['label' => 'HTML', 'text' => "HTML – HTML objects of a form (scripts inside them run after the form is loaded): [Object types]({$d}04-05-object-types.htm) | [nuBuilder Forte wiki]({$w}HTML)"],
			'nucss'        => ['label' => 'CSS', 'text' => "CSS – style objects by their id (#field_name) or by classes added with nuAddClass() / the object's Style settings; form pages support light and dark mode: [Theming and CSS]({$d}11-03-theming.htm)"],
			'mysql'        => ['label' => 'MySQL / MariaDB', 'text' => "Database: MySQL / MariaDB – primary keys are VARCHAR(25) (generated with nuID()) or a 36-character UUID, tables use InnoDB: [Database basics]({$d}01-07-db-basics.htm) | [Naming conventions (nuBuilder Forte wiki)]({$w}Naming_Conventions)"],
			'mssql'        => ['label' => 'SQL Server', 'text' => "Database: Microsoft SQL Server (T-SQL) – use TOP / OFFSET … FETCH instead of LIMIT, square brackets for identifiers: [Databases]({$d}01-03-databases.htm)"],
		];
	}

	/** @return array<string, array{label: string, text: string}> */
	public static function scopes(): array
	{
		return [
			'nuphp_procedure'    => ['label' => 'PHP Procedure', 'text' => 'PHP Procedure: custom server-side logic. Called from JavaScript with nuRunPHP() / nuRunPHPHidden(); return data with nuJavaScriptCallback() or nuSetProperty().'],
			'nuphp_bb'           => ['label' => 'PHP Before Browse (BB)', 'text' => 'PHP Before Browse (BB): runs before the browse list is built – e.g. nuCreateTableFromSelect() for a temporary table used in the Browse SQL.'],
			'nuphp_be'           => ['label' => 'PHP Before Edit (BE)', 'text' => 'PHP Before Edit (BE): runs before the edit form loads – set values or properties, add JavaScript with nuAddJavaScript().'],
			'nuphp_bs'           => ['label' => 'PHP Before Save (BS)', 'text' => 'PHP Before Save (BS): validate or change data on the server before insert/update; nuDisplayError() stops the save. Values via nuGetNuDataValue() / #field#.'],
			'nuphp_as'           => ['label' => 'PHP After Save (AS)', 'text' => 'PHP After Save (AS): logic after the record is saved – notifications, logging, related records. #RECORD_ID# is the saved id.'],
			'nuphp_bd'           => ['label' => 'PHP Before Delete (BD)', 'text' => 'PHP Before Delete (BD): validate or prevent the deletion with nuDisplayError().'],
			'nuphp_ad'           => ['label' => 'PHP After Delete (AD)', 'text' => 'PHP After Delete (AD): clean up related data after a record was deleted.'],
			'nubrowse_form'      => ['label' => 'Browse Form (JS)', 'text' => 'Browse Form (client-side JavaScript): runs when the record list is displayed – e.g. nuOnLoad(), row styling, nuBrowseStyleBadge(), nuSelectBrowse().'],
			'nuedit_form'        => ['label' => 'Edit Form (JS)', 'text' => 'Edit Form (client-side JavaScript): events and validation while editing a record – nuOnLoad(), nuBeforeSave(), object onchange events.'],
			'nuform_custom_code' => ['label' => 'Form Custom Code (JS)', 'text' => 'Form Custom Code: JavaScript only – omit <script> tags; runs on every load of the form.'],
			'nusql'              => ['label' => 'SQL (Browse / Select / Lookup)', 'text' => 'SQL of a Browse, Select or Lookup object: a SELECT statement; hash variables like #USER_ID# or #field# are replaced before it runs.'],
			'nusetup_header'     => ['label' => 'Setup Header', 'text' => 'Header of the form pages (Global code → Form pages): JavaScript functions available on every form (global helpers, nuLoadEditGlobal() …).'],
		];
	}

	/**
	 * Topics with a link to nuBuilder Next's documentation page (+ the nuBuilder Forte wiki page) and keywords for the local suggestion (English and German, lower case, matched as word starts).
	 * @return array<string, array{label: string, url: string, wiki: string, keys: string[]}>
	 */
	public static function topics(): array
	{
		$t = static fn(string $label, string $page, array $keys, string $wiki = '') => ['label' => $label, 'url' => $page === 'fnref' ? self::FNREF : self::DOCS . $page . '.htm',
			'wiki' => $wiki !== '' ? self::WIKI . $wiki : '', 'keys' => $keys];
		return [
			'nuforms'             => $t('Forms', '04-01-form-lifecycle', ['form', 'formular', 'browse', 'edit form', 'launch'], 'Forms'),
			'nuobjects'           => $t('Objects', '04-05-object-types', ['object', 'objekt', 'field', 'feld', 'input', 'button', 'subform', 'unterformular', 'lookup', 'select', 'dropdown', 'textarea', 'calc', 'display', 'html object', 'checkbox'], 'Objects'),
			'nuprocedures'        => $t('Procedures', '05-03-procedures', ['procedure', 'prozedur', 'nurunphp', 'run php'], 'Procedures'),
			'nufunctions'         => $t('Functions', 'fnref', ['function', 'funktion', 'nuget', 'nuset'], 'Functions'),
			'nuhash_cookies'      => $t('Hash variables', '10-03-label-menu', ['hash', 'cookie', 'nusetproperty', 'property', 'record_id', 'user_id'], 'Hash_Cookies'),
			'nudatabase'          => $t('Database', '01-07-db-basics', ['database', 'datenbank', 'table', 'tabelle', 'column', 'spalte', 'query', 'abfrage', 'sql', 'join', 'index'], 'Database'),
			'nusql_builder'       => $t('SQL Builder', '06-03-sql-builder', ['sql builder', 'query builder'], 'SQL_Builder'),
			'nureports'           => $t('Reports', '06-02-reports', ['report', 'bericht', 'pdf', 'print', 'drucken'], 'Reports'),
			'nureport_builder'    => $t('Report Builder', '06-02-reports', ['report builder'], 'Report_Builder'),
			'nureport_designer'   => $t('Report Designer', '06-02-reports', ['report designer'], 'Report_Designer'),
			'nuuser_access'       => $t('User Access', '07-02-access', ['access level', 'zugriff', 'permission', 'berechtigung', 'right', 'recht', 'role', 'rolle'], 'User_Access'),
			'nuusers'             => $t('Users', '08-01-users', ['user', 'benutzer', 'account', 'konto'], 'User_Access'),
			'nulogging_in'        => $t('Logging in', '02-01-sign-in', ['login', 'log in', 'anmeld', 'password', 'passwort', 'session', 'sitzung'], 'Login'),
			'nu2fa'               => $t('Two Factor Authentication (2FA)', '07-01-signin-methods', ['2fa', 'two factor', 'zwei-faktor', 'authenticator', 'otp', 'totp'], 'Two_Factor_Authentication_-_2FA'),
			'nusso'               => $t('Single sign-on (SSO)', '07-10-sso', ['sso', 'single sign', 'oauth', 'saml', 'ldap', 'azure', 'entra'], 'Single_sign-on_(SSO)'),
			'nusearch'            => $t('Search', '02-03-lists', ['search', 'such', 'filter'], 'Search'),
			'nuitems'             => $t('Items', '04-08-items', ['item', 'eintrag', 'list values'], 'Items'),
			'nutranslations'      => $t('Translations', '09-04-translations', ['translat', 'übersetz', 'language', 'sprache', 'multilingual', 'mehrsprach'], 'Translations'),
			'nufiles'             => $t('Files', '09-05-files', ['file', 'datei', 'upload', 'image', 'bild', 'attachment', 'anhang'], 'Files'),
			'nufile_manager'      => $t('File Manager', '08-08-admin-tools', ['file manager', 'dateimanager'], 'File_Manager'),
			'nufile_includes'     => $t('Custom File Includes', '08-05-globalcode', ['include', 'custom file', 'library', 'bibliothek'], 'Custom_File_Includes'),
			'nunavigation'        => $t('Navigation', '02-02-navigation', ['navigat', 'breadcrumb', 'menu', 'menü', 'tab', 'open form', 'nuform('], 'Navigation'),
			'nuformat_builder'    => $t('Format Builder', '04-07-formats', ['format', 'date format', 'datumsformat', 'number format', 'zahlenformat', 'currency', 'währung'], 'Format_Builder'),
			'nucsv_transfer'      => $t('CSV Transfer', '08-09-csv-import', ['csv', 'import', 'export'], 'CSV_Transfer'),
			'nulogging_activity'  => $t('Logging Activity', '05-04-log', ['logging', 'audit', 'activity', 'protokoll', 'history', 'verlauf', 'change log', 'änderungsprotokoll'], 'Logging_Activity'),
			'nucloner'            => $t('Cloner', '09-06-cloner', ['cloner', 'clone', 'klonen', 'copy form'], 'Cloner'),
			'nufast_form_builder' => $t('Fast Form Builder', '04-02-new-form', ['fast form', 'form builder'], 'Form_Builder'),
			'nusetup'             => $t('Setup', '08-04-settings', ['setup', 'setting', 'einstellung', 'config', 'konfig'], 'Setup'),
			'nuinstallation'      => $t('Installation', '01-02-install', ['install']),
			'nuupdating'          => $t('Updating', '01-04-updating', ['updat', 'upgrade', 'aktualis'], 'Updating'),
			'nuaiprompt_generator' => $t('Prompt Generator', '09-03-prompts', ['prompt generator'], 'Prompt_Generator'),
		];
	}

	// ------------------------------------------------------------------ storage

	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists(self::TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$cols = ['zzzzsys_prompt_generator_id ' . $T('id') . ' NOT NULL PRIMARY KEY'];
		foreach (self::LEN as $k => $len) $cols[] = $k . ' ' . ($len === 0 ? $T('longtext') : $T('text', (string)$len)) . ' NULL';
		$db->run('CREATE TABLE ' . self::TABLE . ' (' . implode(', ', $cols) . ')' . ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
	}

	/** JSON array (nuBuilder) or comma list → unique non-empty strings. */
	public static function list(mixed $v): array
	{
		if (is_array($v)) $a = $v;
		else {
			$s = trim((string)$v);
			$a = $s === '' ? [] : (str_starts_with($s, '[') ? (json_decode($s, true) ?: []) : explode(',', $s));
		}
		return array_values(array_unique(array_filter(array_map(static fn($x) => trim((string)$x), $a), static fn($x) => $x !== '')));
	}

	/** Fields of a request (POST) → record values (lists as JSON). */
	public static function fromInput(array $in): array
	{
		$row = [];
		foreach (['pge_code', 'pge_description'] as $k) $row[$k] = trim((string)($in[$k] ?? ''));
		foreach (['pge_prompt', 'pge_instruction'] as $k) $row[$k] = str_replace("\r\n", "\n", (string)($in[$k] ?? ''));
		foreach (['pge_table', 'pge_language', 'pge_scope', 'pge_tag'] as $k) {
			$l = self::list($in[$k] ?? []);
			$row[$k] = $l ? json_encode($l, JSON_UNESCAPED_UNICODE) : '';
		}
		$row['pge_template'] = (string)($in['pge_template'] ?? '') === '1' ? '1' : '0';
		return $row;
	}

	/** @return array{0: ?string, 1: array} [id, errors] */
	public static function save(array $row, string $id = ''): array
	{
		$db = nu_db();
		$errors = [];
		foreach (self::LEN as $k => $len) if ($len > 0) nu_maxlen($errors, $k, (string)($row[$k] ?? ''), $len);
		if ($row['pge_code'] === '') $errors['pge_code'] = t('err_required');
		if (trim($row['pge_prompt']) === '') $errors['pge_prompt'] = t('err_required');
		if (!isset($errors['pge_code']) && (int)$db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE pge_code = ?' . ($id !== '' ? ' AND zzzzsys_prompt_generator_id <> ?' : ''),
			$id !== '' ? [$row['pge_code'], $id] : [$row['pge_code']])) $errors['pge_code'] = t('pg_err_code_taken');
		if ($errors) return [null, $errors];
		$data = [];
		foreach (self::LEN as $k => $_) $data[$k] = ($row[$k] ?? '') === '' ? null : $row[$k];
		if ($id !== '') $db->update(self::TABLE, $data, 'zzzzsys_prompt_generator_id', $id);
		else { $id = nu_id(); $db->insert(self::TABLE, ['zzzzsys_prompt_generator_id' => $id] + $data); }
		return [$id, []];
	}

	// ------------------------------------------------------------------ database tables

	/** Base tables of the database (nuBuilder's zzzzsys_ tables last). */
	public static function tables(): array
	{
		$db = nu_db();
		$rows = match ($db->type) {
			'mysql'  => $db->all("SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'VIEW') ORDER BY TABLE_NAME"),
			'sqlsrv' => $db->all("SELECT TABLE_NAME AS n FROM INFORMATION_SCHEMA.TABLES ORDER BY TABLE_NAME"),
			default  => $db->all("SELECT name AS n FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name"),
		};
		$names = array_map(static fn($r) => (string)$r['n'], $rows);
		usort($names, static fn($a, $b) => [str_starts_with($a, 'zzzzsys_'), strtolower($a)] <=> [str_starts_with($b, 'zzzzsys_'), strtolower($b)]);
		return $names;
	}

	/**
	 * Compact schema line of a table: `col`: type NOT NULL DEFAULT … (PK, auto) – only for existing tables (no SQL from user input).
	 */
	public static function schema(string $table): ?string
	{
		$db = nu_db();
		if (!in_array($table, self::tables(), true)) return null;
		if ($db->type === 'mysql') {
			$cols = $db->all('SELECT COLUMN_NAME AS n, COLUMN_TYPE AS t, IS_NULLABLE AS nul, COLUMN_DEFAULT AS d, COLUMN_KEY AS k, EXTRA AS x, COLUMN_COMMENT AS c
				FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
		} elseif ($db->type === 'sqlsrv') {
			$cols = $db->all("SELECT c.COLUMN_NAME AS n, c.DATA_TYPE + CASE WHEN c.CHARACTER_MAXIMUM_LENGTH IS NULL THEN '' WHEN c.CHARACTER_MAXIMUM_LENGTH = -1 THEN '(max)'
					ELSE '(' + CAST(c.CHARACTER_MAXIMUM_LENGTH AS VARCHAR(10)) + ')' END AS t, c.IS_NULLABLE AS nul, c.COLUMN_DEFAULT AS d,
				CASE WHEN EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku ON ku.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
					WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_NAME = c.TABLE_NAME AND ku.COLUMN_NAME = c.COLUMN_NAME) THEN 'PRI' ELSE '' END AS k,
				CASE WHEN COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') = 1 THEN 'auto_increment' ELSE '' END AS x, '' AS c
				FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_NAME = ? ORDER BY c.ORDINAL_POSITION", [$table]);
		} else {
			$cols = array_map(static fn($r) => ['n' => $r['name'], 't' => strtolower((string)$r['type']), 'nul' => $r['notnull'] ? 'NO' : 'YES', 'd' => $r['dflt_value'],
				'k' => $r['pk'] ? 'PRI' : '', 'x' => '', 'c' => ''], $db->all('PRAGMA table_info("' . str_replace('"', '""', $table) . '")'));
		}
		$parts = [];
		foreach ($cols as $c) {
			$s = '`' . $c['n'] . '` ' . $c['t'];
			if ((string)$c['nul'] === 'NO' && (string)$c['k'] !== 'PRI') $s .= ' NOT NULL';
			if ($c['d'] !== null && (string)$c['d'] !== '' && strtoupper((string)$c['d']) !== 'NULL') $s .= ' DEFAULT ' . mb_strimwidth((string)$c['d'], 0, 40, '…');
			$flags = array_filter([(string)$c['k'] === 'PRI' ? 'PK' : '', str_contains((string)$c['x'], 'auto_increment') ? 'auto increment' : '',
				(string)$c['k'] === 'MUL' ? 'indexed' : '', (string)$c['k'] === 'UNI' ? 'unique' : '']);
			if ($flags) $s .= ' (' . implode(', ', $flags) . ')';
			if ((string)$c['c'] !== '') $s .= ' – ' . mb_strimwidth((string)$c['c'], 0, 80, '…');
			$parts[] = $s;
		}
		return $parts ? implode(', ', $parts) : null;
	}

	/**
	 * What the column line does not say (0.8.1102): keys over several columns, unique keys, indexes, foreign keys (both directions),
	 * CHECK constraints, generated columns, triggers, the view's SELECT, table comment, approximate rows – and the relations
	 * nuBuilder itself knows (forms on the table, lookups, sub-forms), since nuBuilder applications rarely declare foreign keys.
	 * @return string[] Markdown lines "label: text" – only what exists.
	 */
	public static function details(string $table): array
	{
		$db = nu_db();
		if (!in_array($table, self::tables(), true)) return [];
		$out = [];
		$cut = static fn($v, int $n = 200) => mb_strimwidth(trim((string)preg_replace('/\s+/', ' ', (string)$v)), 0, $n, '…');
		$try = static function (callable $fn): array { try { return $fn(); } catch (Throwable) { return []; } };    // a catalog view this server lacks: that part is left out
		$idx = [];             // name => [unique, primary, [cols]]
		$fk = []; $in = []; $checks = []; $trig = []; $gen = []; $view = ''; $comment = ''; $rows = null;
		if ($db->type === 'mysql') {
			foreach ($try(fn() => $db->all('SELECT INDEX_NAME AS i, NON_UNIQUE AS nu, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table])) as $r) {
				$idx[(string)$r['i']] ??= [(int)$r['nu'] === 0, (string)$r['i'] === 'PRIMARY', []];
				$idx[(string)$r['i']][2][] = (string)$r['c'];
			}
			$fkSql = 'SELECT k.CONSTRAINT_NAME AS n, k.TABLE_NAME AS t, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc, r.DELETE_RULE AS d, r.UPDATE_RULE AS u
				FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
				WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL AND ';
			$fk = $try(fn() => $db->all($fkSql . 'k.TABLE_NAME = ? ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION', [$table]));
			$in = $try(fn() => $db->all($fkSql . 'k.REFERENCED_TABLE_NAME = ? AND k.REFERENCED_TABLE_SCHEMA = DATABASE() ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION', [$table]));
			$checks = $try(fn() => $db->all('SELECT c.CONSTRAINT_NAME AS n, c.CHECK_CLAUSE AS x FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t
				ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME AND t.CONSTRAINT_TYPE = \'CHECK\'
				WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME = ? ORDER BY c.CONSTRAINT_NAME', [$table]));
			$trig = $try(fn() => $db->all('SELECT TRIGGER_NAME AS n, ACTION_TIMING AS w, EVENT_MANIPULATION AS e FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? ORDER BY TRIGGER_NAME', [$table]));
			$gen = $try(fn() => $db->all("SELECT COLUMN_NAME AS n, GENERATION_EXPRESSION AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION <> '' ORDER BY ORDINAL_POSITION", [$table]));
			$t = $try(fn() => $db->all('SELECT TABLE_TYPE AS ty, TABLE_COMMENT AS c, TABLE_ROWS AS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]))[0] ?? [];
			if (($t['ty'] ?? '') === 'VIEW') $view = (string)($try(fn() => $db->all('SELECT VIEW_DEFINITION AS v FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]))[0]['v'] ?? '');
			else { $comment = (string)($t['c'] ?? ''); $rows = isset($t['r']) ? (int)$t['r'] : null; }
		} elseif ($db->type === 'sqlsrv') {
			foreach ($try(fn() => $db->all('SELECT i.name AS i, i.is_unique AS u, i.is_primary_key AS p, c.name AS c FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
				JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id WHERE i.object_id = OBJECT_ID(?) AND i.name IS NOT NULL AND ic.is_included_column = 0 ORDER BY i.name, ic.key_ordinal', [$table])) as $r) {
				$idx[(string)$r['i']] ??= [(int)$r['u'] === 1, (int)$r['p'] === 1, []];
				$idx[(string)$r['i']][2][] = (string)$r['c'];
			}
			$fkSql = "SELECT f.name AS n, OBJECT_NAME(f.parent_object_id) AS t, pc.name AS c, OBJECT_NAME(f.referenced_object_id) AS rt, rc.name AS rc,
				REPLACE(f.delete_referential_action_desc, '_', ' ') AS d, REPLACE(f.update_referential_action_desc, '_', ' ') AS u
				FROM sys.foreign_keys f JOIN sys.foreign_key_columns k ON k.constraint_object_id = f.object_id
				JOIN sys.columns pc ON pc.object_id = k.parent_object_id AND pc.column_id = k.parent_column_id
				JOIN sys.columns rc ON rc.object_id = k.referenced_object_id AND rc.column_id = k.referenced_column_id WHERE ";
			$fk = $try(fn() => $db->all($fkSql . 'f.parent_object_id = OBJECT_ID(?) ORDER BY f.name, k.constraint_column_id', [$table]));
			$in = $try(fn() => $db->all($fkSql . 'f.referenced_object_id = OBJECT_ID(?) ORDER BY 2, f.name, k.constraint_column_id', [$table]));
			$checks = $try(fn() => $db->all('SELECT name AS n, definition AS x FROM sys.check_constraints WHERE parent_object_id = OBJECT_ID(?) ORDER BY name', [$table]));
			$trig = $try(fn() => $db->all("SELECT t.name AS n, CASE WHEN t.is_instead_of_trigger = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END AS w,
				STUFF((SELECT ' / ' + e.type_desc FROM sys.trigger_events e WHERE e.object_id = t.object_id FOR XML PATH('')), 1, 3, '') AS e FROM sys.triggers t WHERE t.parent_id = OBJECT_ID(?) ORDER BY t.name", [$table]));
			$gen = $try(fn() => $db->all('SELECT name AS n, definition AS x FROM sys.computed_columns WHERE object_id = OBJECT_ID(?) ORDER BY column_id', [$table]));
			$view = (string)($try(fn() => $db->all('SELECT m.definition AS v FROM sys.views v JOIN sys.sql_modules m ON m.object_id = v.object_id WHERE v.object_id = OBJECT_ID(?)', [$table]))[0]['v'] ?? '');
			if ($view === '') $rows = (int)($try(fn() => $db->all('SELECT SUM(p.rows) AS r FROM sys.partitions p WHERE p.object_id = OBJECT_ID(?) AND p.index_id IN (0, 1)', [$table]))[0]['r'] ?? 0) ?: null;
		} else {
			$q = '"' . str_replace('"', '""', $table) . '"';
			foreach ($try(fn() => $db->all("PRAGMA index_list($q)")) as $i) {
				$cols = array_map(static fn($r) => (string)$r['name'], $try(fn() => $db->all('PRAGMA index_info("' . str_replace('"', '""', (string)$i['name']) . '")')));
				if ($cols) $idx[(string)$i['name']] = [(int)$i['unique'] === 1, ($i['origin'] ?? '') === 'pk', $cols];
			}
			foreach ($try(fn() => $db->all("PRAGMA foreign_key_list($q)")) as $r) $fk[] = ['n' => 'fk' . $r['id'], 't' => $table, 'c' => $r['from'], 'rt' => $r['table'], 'rc' => $r['to'], 'd' => $r['on_delete'], 'u' => $r['on_update']];
		}

		$name = static fn(string $n) => '`' . $n . '`';
		$list = static fn(array $c) => '(' . implode(', ', array_map($name, $c)) . ')';
		$uniq = []; $plain = [];
		foreach ($idx as $n => [$u, $p, $cols]) {
			if ($p) { if (count($cols) > 1) $out[] = 'Primary key: ' . $list($cols); continue; }
			if ($u) $uniq[] = $list($cols) . ' ' . $name((string)$n);
			elseif (count($cols) > 1) $plain[] = $list($cols) . ' ' . $name((string)$n);       // single-column indexes are marked on the column
		}
		if ($uniq) $out[] = 'Unique: ' . implode('; ', array_slice($uniq, 0, 20));
		if ($plain) $out[] = 'Indexes over several columns: ' . implode('; ', array_slice($plain, 0, 20));
		$group = static function (array $rows, bool $incoming) use ($name): array {
			$g = [];
			foreach ($rows as $r) {
				$k = $r['t'] . '|' . $r['n'];
				$g[$k] ??= ['t' => (string)$r['t'], 'rt' => (string)$r['rt'], 'c' => [], 'rc' => [], 'd' => strtoupper((string)$r['d']), 'u' => strtoupper((string)$r['u'])];
				$g[$k]['c'][] = (string)$r['c']; $g[$k]['rc'][] = (string)$r['rc'];
			}
			$lines = [];
			foreach ($g as $x) {
				$rule = implode(', ', array_filter([in_array($x['d'], ['', 'RESTRICT', 'NO ACTION'], true) ? '' : 'on delete ' . strtolower($x['d']), in_array($x['u'], ['', 'RESTRICT', 'NO ACTION'], true) ? '' : 'on update ' . strtolower($x['u'])]));
				$from = ($incoming ? $name($x['t']) . '.' : '') . implode(' + ', array_map($name, $x['c']));
				$to = ($incoming ? '' : $name($x['rt']) . '.') . implode(' + ', array_map($name, $x['rc']));
				$lines[] = $from . ' → ' . $to . ($rule !== '' ? ' (' . $rule . ')' : '');
			}
			return array_slice($lines, 0, 30);
		};
		if ($l = $group($fk, false)) $out[] = 'Foreign keys: ' . implode('; ', $l);
		if ($l = $group($in, true)) $out[] = 'Referenced by: ' . implode('; ', $l);
		if ($checks) $out[] = 'Checks: ' . implode('; ', array_map(static fn($c) => $name((string)$c['n']) . ' ' . $cut($c['x'], 160), array_slice($checks, 0, 15)));
		if ($gen) $out[] = 'Generated columns: ' . implode('; ', array_map(static fn($c) => $name((string)$c['n']) . ' = ' . $cut($c['x'], 160), array_slice($gen, 0, 15)));
		if ($trig) $out[] = 'Triggers: ' . implode('; ', array_map(static fn($c) => $name((string)$c['n']) . ' (' . strtolower(trim($c['w'] . ' ' . $c['e'])) . ')', array_slice($trig, 0, 15)));
		if ($view !== '' && $db->type === 'mysql') $view = (string)preg_replace('/`' . preg_quote((string)$db->value('SELECT DATABASE()'), '/') . '`\./', '', $view);   // without the schema prefix
		if ($view !== '') $out[] = 'View: ' . $cut($view, 1200);
		if ($comment !== '') $out[] = 'Comment: ' . $cut($comment);
		if ($rows !== null && $rows >= 1000) $out[] = 'Rows: about ' . number_format((int)round($rows, -(strlen((string)$rows) - 2)), 0, '.', ',');

		// what nuBuilder knows: forms on the table, their lookups (→ the looked-up form's table) and sub-forms (child table + its link column)
		$nb = $try(function () use ($db, $table, $name): array {
			$o = [];
			$forms = $db->all("SELECT zzzzsys_form_id AS id, sfo_code AS c, sfo_type AS t, sfo_primary_key AS pk FROM zzzzsys_form WHERE sfo_table = ? AND zzzzsys_form_id NOT LIKE 'nu%' ORDER BY sfo_code", [$table]);
			if (!$forms) return [];
			$o[] = 'nuBuilder forms: ' . implode('; ', array_map(static fn($f) => $f['c'] . ' (' . $f['t'] . ((string)$f['pk'] !== '' ? ', primary key ' . $name((string)$f['pk']) : '') . ')', array_slice($forms, 0, 10)));
			$ids = array_column($forms, 'id');
			$ph = implode(',', array_fill(0, count($ids), '?'));
			$look = []; $subs = [];
			foreach ($db->all("SELECT o.sob_all_id AS id, o.sob_all_type AS ty, o.sob_subform_foreign_key AS fk, f.sfo_table AS tb, f.sfo_primary_key AS pk
				FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = CASE WHEN o.sob_all_type = 'lookup' THEN o.sob_lookup_zzzzsys_form_id ELSE o.sob_subform_zzzzsys_form_id END
				WHERE o.sob_all_zzzzsys_form_id IN ($ph) AND o.sob_all_type IN ('lookup', 'subform') ORDER BY o.sob_all_id", $ids) as $r) {
				if ((string)$r['tb'] === '') continue;
				if ($r['ty'] === 'lookup') $look[$r['id']] = $name((string)$r['id']) . ' → ' . $name((string)$r['tb']) . ((string)$r['pk'] !== '' ? '.' . $name((string)$r['pk']) : '');
				else $subs[$r['tb']] = $name((string)$r['tb']) . ((string)$r['fk'] !== '' ? '.' . $name((string)$r['fk']) : '') . ' → this table\'s primary key';
			}
			if ($look) $o[] = 'nuBuilder lookups (no database constraint): ' . implode('; ', array_slice(array_values($look), 0, 30));
			if ($subs) $o[] = 'nuBuilder sub-forms (child rows): ' . implode('; ', array_slice(array_values($subs), 0, 20));
			// the other direction: lookups of other forms that point at this table, forms this table is a sub-form of
			$from = []; $parents = [];
			foreach ($db->all("SELECT o.sob_all_id AS id, o.sob_all_type AS ty, o.sob_subform_foreign_key AS fk, f.sfo_table AS tb
				FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
				WHERE o.sob_all_type IN ('lookup', 'subform') AND (o.sob_lookup_zzzzsys_form_id IN ($ph) OR o.sob_subform_zzzzsys_form_id IN ($ph)) ORDER BY f.sfo_table, o.sob_all_id", array_merge($ids, $ids)) as $r) {
				if ((string)$r['tb'] === '' || (string)$r['tb'] === $table) continue;
				if ($r['ty'] === 'lookup') $from[$r['tb'] . '.' . $r['id']] = $name((string)$r['tb']) . '.' . $name((string)$r['id']);
				else $parents[$r['tb']] = ((string)$r['fk'] !== '' ? $name((string)$r['fk']) . ' → ' : '') . $name((string)$r['tb']);
			}
			if ($parents) $o[] = 'nuBuilder sub-form of (parent rows): ' . implode('; ', array_slice(array_values($parents), 0, 20));
			if ($from) $o[] = 'nuBuilder looked up from: ' . implode('; ', array_slice(array_values($from), 0, 30));
			return $o;
		});
		return array_merge($out, $nb);
	}

	// ------------------------------------------------------------------ building the prompt

	/** The context ("Extra information"): table schemas, languages, scopes, topics – Markdown. */
	public static function context(array $row): string
	{
		$lines = [];
		$tables = array_slice(self::list($row['pge_table'] ?? ''), 0, self::MAX_TABLES);
		$schemas = [];
		foreach ($tables as $tb) if (($s = self::schema($tb)) !== null) {
			$schemas[] = "- **{$tb}**: {$s}";
			foreach (self::details($tb) as $d) $schemas[] = '  - ' . $d;      // keys, constraints, relations (0.8.1102)
		}
		if ($schemas) array_push($lines, '## Table Schemas', ...$schemas);

		$section = static function (string $title, array $codes, array $catalog, callable $fmt) use (&$lines): void {
			$out = [];
			foreach ($codes as $c) if (isset($catalog[$c])) $out[] = '- ' . $fmt($catalog[$c]);
			if ($out) { if ($lines) $lines[] = ''; array_push($lines, $title, ...$out); }
		};
		$section('## Languages & Technologies', self::list($row['pge_language'] ?? ''), self::languages(), static fn($e) => $e['text']);
		$section('## Context & Scopes', self::list($row['pge_scope'] ?? ''), self::scopes(), static fn($e) => $e['text']);
		$section('## Topics & References', self::list($row['pge_tag'] ?? ''), self::topics(), static fn($e) => $e['label'] . ': ' . $e['url'] . ($e['wiki'] !== '' ? ' | nuBuilder Forte wiki: ' . $e['wiki'] : ''));
		return implode("\n", $lines);
	}

	/** The complete prompt to copy: instruction, context, then the request (long context first, question last). */
	public static function build(array $row): string
	{
		$parts = [];
		$ins = self::instruction((string)($row['pge_instruction'] ?? ''));
		if (trim($ins) !== '') $parts[] = trim($ins);
		$ctx = self::context($row);
		if ($ctx !== '') $parts[] = $ctx;
		if (trim((string)($row['pge_prompt'] ?? '')) !== '') $parts[] = "## Request\n" . trim((string)$row['pge_prompt']);
		return implode("\n\n", $parts);
	}

	/** Keywords of languages and scopes for the local suggestion (word starts, lower case). */
	public const KEYS = [
		'pge_language' => [
			'nuphp' => ['php', 'procedure', 'prozedur', 'before save', 'after save', 'before edit', 'before browse', 'before delete', 'after delete', 'nurunquery'],
			'nujavascript' => ['javascript', 'js', 'onchange', 'onclick', 'nuonload', 'nubeforesave', 'client'], 'nujquery' => ['jquery'],
			'nuhtml' => ['html'], 'nucss' => ['css', 'style', 'colour', 'color', 'farbe'],
			'mysql' => ['mysql', 'mariadb', 'sql', 'query', 'abfrage', 'select ', 'join'], 'mssql' => ['sql server', 'mssql', 't-sql', 'tsql'],
		],
		'pge_scope' => [
			'nuphp_procedure' => ['procedure', 'prozedur', 'nurunphp'], 'nuphp_bb' => ['before browse', 'beforebrowse'], 'nuphp_be' => ['before edit', 'beforeedit'],
			'nuphp_bs' => ['before save', 'beforesave', 'vor dem speichern', 'validat', 'prüf'], 'nuphp_as' => ['after save', 'aftersave', 'nach dem speichern'],
			'nuphp_bd' => ['before delete', 'vor dem löschen'], 'nuphp_ad' => ['after delete', 'nach dem löschen'],
			'nubrowse_form' => ['browse form', 'browse list', 'browseliste', 'browse-formular', 'browseformular', 'browse-liste'],
			'nuedit_form' => ['edit form', 'edit-formular', 'bearbeitungsformular', 'eingabeformular', 'onchange', 'nuonload', 'nubeforesave'],
			'nuform_custom_code' => ['custom code'], 'nusql' => ['browse sql', 'select sql', 'lookup sql', 'sql of'], 'nusetup_header' => ['setup header', 'global function'],
		],
	];

	/** Catalog for the editor: keywords per select for the local suggestion (topics, languages, scopes). */
	public static function catalogJson(): string
	{
		return json_encode(['suggest' => [
			'pge_tag' => array_map(static fn($e) => $e['keys'], self::topics()),
		] + self::KEYS], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
	}
}
