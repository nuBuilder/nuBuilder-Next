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

	private const WIKI   = 'https://wiki.nubuilder.cloud/index.php?title=';
	private const GITHUB = 'https://raw.githubusercontent.com/nuBuilder/nuBuilder/refs/heads/master/core/';

	public static function defaultInstruction(): string
	{
		return 'Generate code snippets and explanations following nuBuilder conventions. Use the information below when relevant to the request. '
			. 'This context is provided to help understand the requirements. If something is unclear or missing, say so instead of guessing.';
	}

	// ------------------------------------------------------------------ catalog (codes as in nuBuilder)

	/** @return array<string, array{label: string, text: string}> */
	public static function languages(): array
	{
		$w = self::WIKI; $g = self::GITHUB;
		return [
			'nuphp'        => ['label' => 'PHP', 'text' => "PHP 8 – use nuBuilder's PDO functions (nuRunQuery() with ? parameters, db_fetch_array(), db_fetch_object()), never string-concatenated SQL: [Documentation]({$w}PHP) | [nucommon.php]({$g}nucommon.php) | [nudatabase.php]({$g}nudatabase.php)"],
			'nujavascript' => ['label' => 'JavaScript', 'text' => "JavaScript – use nuBuilder's JavaScript functions (nuGetValue(), nuSetValue(), nuHide(), nuShow(), nuMessage() …): [Wiki]({$w}Javascript) | [nucommon.js]({$g}nucommon.js) | [nuform.js]({$g}nuform.js)"],
			'nujquery'     => ['label' => 'jQuery', 'text' => 'jQuery (bundled with nuBuilder) – object ids are the field names: $(\'#field_name\').'],
			'nuhtml'       => ['label' => 'HTML', 'text' => "HTML – nuBuilder HTML objects and templates: [Wiki]({$w}HTML)"],
			'nucss'        => ['label' => 'CSS', 'text' => 'CSS – style nuBuilder objects by their id (#field_name) or by classes added with nuAddClass() / the object\'s Style settings.'],
			'mysql'        => ['label' => 'MySQL / MariaDB', 'text' => "Database: MySQL / MariaDB – nuBuilder primary keys are VARCHAR(25) (generated with nuID()), tables use InnoDB: [Naming Conventions]({$w}Naming_Conventions)"],
			'mssql'        => ['label' => 'SQL Server', 'text' => 'Database: Microsoft SQL Server (T-SQL) – use TOP / OFFSET … FETCH instead of LIMIT, square brackets for identifiers.'],
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
			'nubrowse_form'      => ['label' => 'Browse Form (JS)', 'text' => 'Browse Form (client-side JavaScript): runs when the record list is displayed – e.g. nuOnLoad(), row styling, nuSelectBrowse().'],
			'nuedit_form'        => ['label' => 'Edit Form (JS)', 'text' => 'Edit Form (client-side JavaScript): events and validation while editing a record – nuOnLoad(), nuBeforeSave(), object onchange events.'],
			'nuform_custom_code' => ['label' => 'Form Custom Code (JS)', 'text' => 'Form Custom Code: JavaScript only – omit <script> tags; runs on every load of the form.'],
			'nusql'              => ['label' => 'SQL (Browse / Select / Lookup)', 'text' => 'SQL of a Browse, Select or Lookup object: a SELECT statement; hash cookies like #USER_ID# or #field# are replaced before it runs.'],
			'nusetup_header'     => ['label' => 'Setup Header', 'text' => 'Setup → Header: JavaScript functions that are available on every form (global helpers, nuLoadEditGlobal() …).'],
		];
	}

	/**
	 * Topics with wiki link and keywords for the local suggestion (English and German, lower case, matched as word starts).
	 * @return array<string, array{label: string, url: string, keys: string[]}>
	 */
	public static function topics(): array
	{
		$t = static fn(string $label, string $slug, array $keys, string $url = '') => ['label' => $label, 'url' => $url !== '' ? $url : self::WIKI . $slug, 'keys' => $keys];
		return [
			'nuforms'             => $t('Forms', 'Forms', ['form', 'formular', 'browse', 'edit form', 'launch']),
			'nuobjects'           => $t('Objects', 'Objects', ['object', 'objekt', 'field', 'feld', 'input', 'button', 'subform', 'unterformular', 'lookup', 'select', 'dropdown', 'textarea', 'calc', 'display', 'html object', 'checkbox']),
			'nuprocedures'        => $t('Procedures', 'Procedures', ['procedure', 'prozedur', 'nurunphp', 'run php']),
			'nufunctions'         => $t('Functions', 'Functions', ['function', 'funktion', 'nuget', 'nuset']),
			'nuhash_cookies'      => $t('Hash Cookies', 'Hash_Cookies', ['hash', 'cookie', 'nusetproperty', 'property', 'record_id', 'user_id']),
			'nudatabase'          => $t('Database', 'Database', ['database', 'datenbank', 'table', 'tabelle', 'column', 'spalte', 'query', 'abfrage', 'sql', 'join', 'index']),
			'nusql_builder'       => $t('SQL Builder', 'SQL_Builder', ['sql builder', 'query builder']),
			'nureports'           => $t('Reports', 'Reports', ['report', 'bericht', 'pdf', 'print', 'drucken']),
			'nureport_builder'    => $t('Report Builder', 'Report_Builder', ['report builder']),
			'nureport_designer'   => $t('Report Designer', 'Report_Designer', ['report designer']),
			'nuuser_access'       => $t('User Access', 'User_Access', ['access level', 'zugriff', 'permission', 'berechtigung', 'right', 'recht', 'role', 'rolle']),
			'nuusers'             => $t('Users', 'User_Access', ['user', 'benutzer', 'account', 'konto']),
			'nulogging_in'        => $t('Logging in', 'Login', ['login', 'log in', 'anmeld', 'password', 'passwort', 'session', 'sitzung']),
			'nu2fa'               => $t('Two Factor Authentication (2FA)', 'Two_Factor_Authentication_-_2FA', ['2fa', 'two factor', 'zwei-faktor', 'authenticator', 'otp', 'totp']),
			'nusso'               => $t('Single sign-on (SSO)', 'Single_sign-on_(SSO)', ['sso', 'single sign', 'oauth', 'saml', 'ldap', 'azure', 'entra']),
			'nusearch'            => $t('Search', 'Search', ['search', 'such', 'filter']),
			'nuitems'             => $t('Items', 'Items', ['item', 'eintrag', 'list values']),
			'nutranslations'      => $t('Translations', 'Translations', ['translat', 'übersetz', 'language', 'sprache', 'multilingual', 'mehrsprach']),
			'nufiles'             => $t('Files', 'Files', ['file', 'datei', 'upload', 'image', 'bild', 'attachment', 'anhang']),
			'nufile_manager'      => $t('File Manager', 'File_Manager', ['file manager', 'dateimanager']),
			'nufile_includes'     => $t('Custom File Includes', 'Custom_File_Includes', ['include', 'custom file', 'library', 'bibliothek']),
			'nunavigation'        => $t('Navigation', 'Navigation', ['navigat', 'breadcrumb', 'menu', 'menü', 'tab', 'open form', 'nuform(']),
			'nuformat_builder'    => $t('Format Builder', 'Format_Builder', ['format', 'date format', 'datumsformat', 'number format', 'zahlenformat', 'currency', 'währung']),
			'nucsv_transfer'      => $t('CSV Transfer', 'CSV_Transfer', ['csv', 'import', 'export']),
			'nulogging_activity'  => $t('Logging Activity', 'Logging_Activity', ['logging', 'audit', 'activity', 'protokoll', 'history', 'verlauf', 'change log', 'änderungsprotokoll']),
			'nucloner'            => $t('Cloner', 'Cloner', ['cloner', 'clone', 'klonen', 'copy form']),
			'nufast_form_builder' => $t('Fast Form Builder', 'Form_Builder', ['fast form', 'form builder']),
			'nusetup'             => $t('Setup', 'Setup', ['setup', 'setting', 'einstellung', 'config', 'konfig']),
			'nuinstallation'      => $t('Installation', 'nuBuilder-Installation-Guide', ['install'], 'https://github.com/nuBuilder/nuBuilder/wiki/nuBuilder-Installation-Guide'),
			'nuupdating'          => $t('Updating', 'Updating', ['updat', 'upgrade', 'aktualis']),
			'nuaiprompt_generator' => $t('Prompt Generator', 'Prompt_Generator', ['prompt generator']),
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

	// ------------------------------------------------------------------ building the prompt

	/** The context ("Extra information"): table schemas, languages, scopes, topics – Markdown. */
	public static function context(array $row): string
	{
		$lines = [];
		$tables = array_slice(self::list($row['pge_table'] ?? ''), 0, self::MAX_TABLES);
		$schemas = [];
		foreach ($tables as $tb) if (($s = self::schema($tb)) !== null) $schemas[] = "- **{$tb}**: {$s}";
		if ($schemas) array_push($lines, '## Table Schemas', ...$schemas);

		$section = static function (string $title, array $codes, array $catalog, callable $fmt) use (&$lines): void {
			$out = [];
			foreach ($codes as $c) if (isset($catalog[$c])) $out[] = '- ' . $fmt($catalog[$c]);
			if ($out) { if ($lines) $lines[] = ''; array_push($lines, $title, ...$out); }
		};
		$section('## Languages & Technologies', self::list($row['pge_language'] ?? ''), self::languages(), static fn($e) => $e['text']);
		$section('## Context & Scopes', self::list($row['pge_scope'] ?? ''), self::scopes(), static fn($e) => $e['text']);
		$section('## Topics & References', self::list($row['pge_tag'] ?? ''), self::topics(), static fn($e) => $e['label'] . ': ' . $e['url']);
		return implode("\n", $lines);
	}

	/** The complete prompt to copy: instruction, context, then the request (long context first, question last). */
	public static function build(array $row): string
	{
		$parts = [];
		if (trim((string)($row['pge_instruction'] ?? '')) !== '') $parts[] = trim((string)$row['pge_instruction']);
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
