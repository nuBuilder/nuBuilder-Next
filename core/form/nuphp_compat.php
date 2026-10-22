<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * More nuBuilder 4.9 PHP functions for form events, lookups and procedures (loaded with nuphp_api.php).
 * Ported from nuBuilder's core/nudatabase.php, nucommon.php, nudata.php, nuform.php and nusecurity.php.
 * Differences to nuBuilder are noted at the function; see docs/porting-review.md for open questions.
 */

if (!function_exists('db_fetch_value')) {

	// ============================================================= database

	/** nuBuilder: "MySQL" quoting of an identifier for the current database (`x` or [x]). */
	function nu_compat_ident(string $name): string
	{
		NuDB::ident($name);                                     // throws for anything but a plain (table.)column name
		return match (nu_db()->type) {
			'mysql'  => '`' . str_replace('.', '`.`', $name) . '`',
			'sqlsrv' => '[' . str_replace('.', '].[', $name) . ']',
			default  => '"' . str_replace('.', '"."', $name) . '"',
		};
	}

	function nuMSSQL(): bool
	{
		return nu_db()->type === 'sqlsrv';
	}

	/** Value of one column of one record, false when the record does not exist. */
	function db_fetch_value($table, $pk, $recordId, $column): mixed
	{
		$r = nu_db()->all('SELECT ' . nu_compat_ident((string)$column) . ' AS v FROM ' . nu_compat_ident((string)$table)
			. ' WHERE ' . nu_compat_ident((string)$pk) . ' = ?', [$recordId]);
		return count($r) === 1 ? $r[0]['v'] : false;
	}

	/** Sets one column of one record. */
	function db_update_value($table, $pk, $recordId, $column, $newValue): NuResult
	{
		return nuRunQuery('UPDATE ' . nu_compat_ident((string)$table) . ' SET ' . nu_compat_ident((string)$column) . ' = ? WHERE '
			. nu_compat_ident((string)$pk) . ' = ?', [$newValue, $recordId]);
	}

	/**
	 * nuBuilder: db_fetch($result, 'object' | 'array' | 'row' | 'keypairarray' | 'column', $fetchAll).
	 * One row: false when no row is left ('array' / 'keypairarray' / 'column': []); all rows: an array.
	 */
	function db_fetch($obj, $style = 'object', $fetchAll = false): mixed
	{
		$styles = ['array', 'keypairarray', 'object', 'row', 'column'];
		if (!in_array($style, $styles, true)) return false;
		$arrayType = in_array($style, ['array', 'keypairarray', 'column'], true);
		if (!$obj instanceof NuResult) return $arrayType ? [] : false;

		$one = static function (NuResult $r, array $row) use ($style): mixed {
			return match ($style) {
				'array'  => $r->assoc($row),
				'object' => (object)$r->assoc($row),
				'row'    => $row,
				'column' => $row[0] ?? null,
				default  => $row,
			};
		};
		if ($style === 'keypairarray') {                    // first column => second column
			$out = [];
			if ($fetchAll) { while (($row = $obj->next()) !== null) $out[$row[0]] = $row[1] ?? null; return $out; }
			$row = $obj->next();
			return $row === null ? false : [$row[0] => $row[1] ?? null];
		}
		if ($fetchAll) {
			$out = [];
			while (($row = $obj->next()) !== null) $out[] = $one($obj, $row);
			return $out;
		}
		$row = $obj->next();
		return $row === null ? false : $one($obj, $row);
	}

	function db_fetch_all_array($obj): array { return db_fetch($obj, 'array', true); }
	function db_fetch_all_object($obj): array { return db_fetch($obj, 'object', true); }
	function db_fetch_key_pair_array($obj, $fetchAll = false): array|false { return db_fetch($obj, 'keypairarray', $fetchAll); }
	function db_fetch_all_key_pair_array($obj): array { return db_fetch($obj, 'keypairarray', true); }

	function db_num_columns($obj): int
	{
		return $obj instanceof NuResult ? count($obj->columns) : 0;
	}

	/** Column names of a table, in table order. */
	function db_field_names($tableName): array
	{
		return array_keys(nu_db()->columns((string)$tableName));
	}

	/** Column types of a table (e.g. varchar(25)); MySQL as DESCRIBE shows them. */
	function db_field_types($tableName): array
	{
		return db_field_info($tableName)[1];
	}

	/** [names, types, primary key columns] of a table. */
	function db_field_info($tableName): array
	{
		$db = nu_db();
		$t  = (string)$tableName;
		if ($db->type === 'mysql') {
			$rows = $db->all('SELECT COLUMN_NAME AS f, COLUMN_TYPE AS t, COLUMN_KEY AS k FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]);
			return [array_column($rows, 'f'), array_column($rows, 't'),
				array_values(array_column(array_filter($rows, static fn($r) => $r['k'] === 'PRI'), 'f'))];
		}
		if ($db->type === 'sqlsrv') {
			$rows = $db->all("SELECT COLUMN_NAME AS f, DATA_TYPE + CASE WHEN CHARACTER_MAXIMUM_LENGTH IS NULL OR CHARACTER_MAXIMUM_LENGTH > 99999 THEN ''
				ELSE '(' + CAST(CHARACTER_MAXIMUM_LENGTH AS VARCHAR(5)) + ')' END AS t FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_NAME = ? AND TABLE_CATALOG = DB_NAME() ORDER BY ORDINAL_POSITION", [$t]);
			return [array_column($rows, 'f'), array_column($rows, 't'), db_primary_key($t)];
		}
		$rows = $db->all('PRAGMA table_info(' . NuDB::ident($t) . ')');
		return [array_column($rows, 'name'), array_column($rows, 'type'),
			array_values(array_column(array_filter($rows, static fn($r) => (int)$r['pk'] > 0), 'name'))];
	}

	/** True when the table has the column (nuBuilder returned false for the first column – fixed). */
	function db_field_exists($tableName, $fieldName): bool
	{
		return in_array((string)$fieldName, db_field_names($tableName), true);
	}

	/** Primary key column(s) of a table. */
	function db_primary_key($tableName): array
	{
		$db = nu_db();
		$t  = (string)$tableName;
		if ($db->type === 'sqlsrv') {
			return array_column($db->all("SELECT KCU.COLUMN_NAME AS c FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE KCU
				JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS TC ON KCU.CONSTRAINT_NAME = TC.CONSTRAINT_NAME AND KCU.TABLE_CATALOG = TC.TABLE_CATALOG
				WHERE KCU.TABLE_NAME = ? AND KCU.TABLE_CATALOG = DB_NAME() AND TC.CONSTRAINT_TYPE = 'PRIMARY KEY' ORDER BY KCU.ORDINAL_POSITION", [$t]), 'c');
		}
		return db_field_info($t)[2];
	}

	/** True when the primary key is filled by the database (auto_increment / identity, or an integer key with $nuConfigIntegerPKsAuto). */
	function db_is_auto_id($table, $primaryKey): bool
	{
		$cols = nu_db()->columns((string)$table);
		$c = $cols[(string)$primaryKey] ?? null;
		if ($c === null) { nuDisplayError(nuTranslate('The primary key is invalid') . ': ' . $primaryKey); return false; }
		return $c['auto'] || (nu_cfg('nuConfigIntegerPKsAuto') && str_contains($c['type'], 'int'));
	}

	/** Quoted string literal for SQL (prefer parameters: nuRunQuery($sql, [$value])). */
	function db_quote($s): string
	{
		return nu_db()->pdo()->quote((string)$s);
	}

	function nuDBQuote($s): string
	{
		return db_quote($s);
	}

	/** Like nuRunQuery, but errors are ignored (nuBuilder: no debug entry). */
	function nuRunQueryNoDebug($query, $params = [], $isInsert = false): mixed
	{
		try { return nuRunQuery((string)$query, (array)$params, (bool)$isInsert); }
		catch (Throwable) { return $isInsert ? '' : null; }
	}

	/** First column of the first row of a query (hash variables are replaced), '' for empty SQL. */
	function nuGetSQLValue($sql): mixed
	{
		$sql = nuReplaceHashVariables(trim((string)$sql));
		if (trim($sql) === '') return '';
		$row = db_fetch_row(nuRunQuery($sql));
		return $row === false ? null : ($row[0] ?? null);
	}

	/** Tables of the database (nuBuilder: SHOW TABLES). */
	function nuListTables(): array
	{
		$db = nu_db();
		return match ($db->type) {
			'mysql'  => array_column($db->all('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'), 't'),
			'sqlsrv' => array_column($db->all('SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_CATALOG = DB_NAME() ORDER BY TABLE_NAME'), 't'),
			default  => array_column($db->all("SELECT name AS t FROM sqlite_master WHERE type IN ('table','view') ORDER BY name"), 't'),
		};
	}

	/** Tables (and views) except nuBuilder's zzzzsys_ tables. */
	function nuGetUserTables(): array
	{
		return array_values(array_filter(nuListTables(), static fn($t) => stripos((string)$t, 'zzzzsys_') !== 0));
	}

	function nuViewExists($view): int
	{
		$db = nu_db();
		$n = match ($db->type) {
			'mysql'  => $db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_TYPE = 'VIEW' AND TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [(string)$view]),
			'sqlsrv' => $db->value("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'VIEW' AND TABLE_CATALOG = DB_NAME() AND TABLE_NAME = ?", [(string)$view]),
			default  => $db->value("SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = ?", [(string)$view]),
		};
		return (int)$n;
	}

	/** Unique name for a temporary table (___nu…___). */
	function nuTT(): string
	{
		return str_replace('.', '_', '___nu' . uniqid('1', true) . '___');
	}

	/** Table name `x` / [x] for the current database. */
	function nuIdentColumn($s): string
	{
		return nu_compat_ident((string)$s);
	}

	function nuSchemaWhereCurrentDBSQL(): string
	{
		return nuMSSQL() ? ' TABLE_CATALOG = db_name() ' : ' table_schema = DATABASE() ';
	}

	function nuGetTableNamesSQL($tableType = null): string
	{
		$sql = 'SELECT table_name as TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE ' . nuSchemaWhereCurrentDBSQL();
		if ($tableType && preg_match('/^[A-Z ]+$/i', (string)$tableType)) $sql .= " AND TABLE_TYPE = '$tableType'";
		return $sql;
	}

	/** Creates a table from a SELECT (optionally TEMPORARY, MySQL); on MSSQL the SELECT gets INTO [table]. */
	function nuCreateTableFromSelect2($tableName, $select, $params = [], $temporary = false): mixed
	{
		if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$tableName)) throw new RuntimeException('Invalid table name ' . $tableName);
		if (!nuMSSQL()) return nuRunQuery('CREATE' . ($temporary ? ' TEMPORARY' : '') . " TABLE `$tableName` AS ($select)", (array)$params);
		$q = str_contains((string)$select, '/* #MSSQL_INTO_NEW_TABLE */') ? str_replace('/* #MSSQL_INTO_NEW_TABLE */', "INTO [$tableName]", (string)$select)
			: (($p = strripos((string)$select, 'FROM')) !== false ? substr((string)$select, 0, $p) . "INTO [$tableName] " . substr((string)$select, $p) : (string)$select);
		return nuRunQuery($q, (array)$params);
	}

	/** 'autoid' when the database fills the key, otherwise a new nuID(). */
	function nuBuildPrimaryKey($tableName, $primaryKey): string
	{
		return db_is_auto_id($tableName, $primaryKey) ? 'autoid' : nuID();
	}

	// ============================================================= record and form state

	/** After Save: true when a new record was just inserted (RECORD_ID differs from the one before saving). */
	function nuHasNewRecordID(): bool
	{
		$h = NuPhp::$hash;
		return (string)($h['PREVIOUS_RECORD_ID'] ?? '') !== (string)($h['RECORD_ID'] ?? '');
	}

	/** True for a new record that has no id yet (RECORD_ID = -1, i.e. Before Save of a new record). */
	function nuHasNoRecordID(): bool
	{
		return (string)(NuPhp::$hash['RECORD_ID'] ?? '') === '-1';
	}

	/** Code (sfo_code) of a form. */
	function nuFormCode($f): string
	{
		return (string)(nu_db()->value('SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [(string)$f]) ?? '');
	}

	function nuIsForm($i): bool
	{
		return (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [(string)$i]);
	}

	function nuIsProcedure($i): bool
	{
		return (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [(string)$i]);
	}

	/** Reports are not supported by nuBuilder Next – true only when the record exists in zzzzsys_report. */
	function nuIsReport($i): bool
	{
		$db = nu_db();
		return $db->tableExists('zzzzsys_report') && (bool)$db->value('SELECT COUNT(*) FROM zzzzsys_report WHERE zzzzsys_report_id = ?', [(string)$i]);
	}

	/** Link to the current record in nuBuilder Next (index.php?p=form&f=…&r=…). */
	function nuGetRecordURL($origin = null, $subFolder = null, $homepageId = null, $formId = null): string
	{
		$origin = $origin ?: nuGetHttpOrigin();
		$formId = $formId ?: nuFormId();
		return rtrim((string)$origin, '/') . (string)$subFolder . '/index.php?' . http_build_query(['p' => 'form', 'f' => $formId, 'r' => nuRecordId()]);
	}

	/** The user record (object), default the current user; false when not found. */
	function nuUser($userId = null): object|false
	{
		$id = $userId === null ? nuUserId() : (string)$userId;
		$r = nu_db()->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$id]);
		return $r ? (object)$r : false;
	}

	/** nuBuilder: details of the logged-in user and access level (USER_ID, USER_GROUP_ID, LOGIN_NAME, ACCESS_LEVEL_CODE …). */
	function nuGetUserAccess(): array
	{
		$b = NuHash::base();
		return [
			'USER_ID' => $b['USER_ID'] ?? '', 'USER_GROUP_ID' => $b['ACCESS_LEVEL_ID'] ?? '', 'HOME_ID' => $b['HOME_ID'] ?? '',
			'GLOBAL_ACCESS' => $b['GLOBAL_ACCESS'] ?? '0', 'ACCESS_LEVEL_CODE' => $b['ACCESS_LEVEL_CODE'] ?? '', 'ACCESS_LEVEL_GROUP' => $b['ACCESS_LEVEL_GROUP'] ?? '',
			'LOGIN_NAME' => $b['LOGIN_NAME'] ?? '', 'USER_NAME' => $b['USER_NAME'] ?? '', 'USER_FIRST_NAME' => $b['USER_FIRST_NAME'] ?? '',
			'USER_LAST_NAME' => $b['USER_LAST_NAME'] ?? '', 'USER_DEPARTMENT' => $b['USER_DEPARTMENT'] ?? '', 'USER_TEAM' => $b['USER_TEAM'] ?? '',
			'USER_CODE' => $b['USER_CODE'] ?? '', 'USER_POSITION' => $b['USER_POSITION'] ?? '', 'USER_ADDITIONAL1' => $b['USER_ADDITIONAL1'] ?? '',
			'USER_ADDITIONAL2' => $b['USER_ADDITIONAL2'] ?? '', 'USER_PERMISSIONS' => $b['USER_PERMISSIONS'] ?? '',
			'USER_A11Y' => ($b['USER_A11Y'] ?? '') === '1', 'DEV_MODE' => ($b['DEV_MODE'] ?? '') === '1', 'LANGUAGE' => $b['LANGUAGE'] ?? '',
		];
	}

	/** Replaces a hash variable for the code that runs after this one in the same request (not system variables). */
	function nuChangeHashVariable($h, $v): void
	{
		if (NuHash::isSystem((string)$h)) return;
		NuPhp::$hash[(string)$h] = $v;
	}

	// ============================================================= procedures and output

	/** zzzzsys_php record (object) by code or id, false when missing. */
	function nuGetPHP($idOrCode): object|false
	{
		$r = nu_db()->all('SELECT * FROM zzzzsys_php WHERE sph_code = ? OR zzzzsys_php_id = ?', [(string)$idOrCode, (string)$idOrCode]);
		return count($r) === 1 ? (object)$r[0] : false;
	}

	/** PHP code of a procedure by id (without hash replacement), false when missing. */
	function nuGetProcedure($id): string|false
	{
		$v = nu_db()->value('SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [(string)$id]);
		return $v === null ? false : (string)$v;
	}

	/** True when the current user may run the procedure (record from nuGetPHP). */
	function nuHasProcedureAccess($phpObj): bool
	{
		if (!is_object($phpObj)) return false;
		$u = NuAuth::user();
		if ($u['globeadmin'] || NuAdmin::can('procedures', 'change') || (string)($phpObj->sph_global ?? '') === '1') return true;
		return (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_access_php WHERE slp_zzzzsys_access_id = ? AND slp_zzzzsys_php_id = ?',
			[(string)$u['access_id'], (string)$phpObj->zzzzsys_php_id]);
	}

	/**
	 * Runs another procedure here (same hash variables). true = ran without error, the error text when it set $error,
	 * false when it does not exist. nuBuilder ran it with eval() in the calling function; here it runs in its own scope.
	 */
	function nuRunProcedure($procedure): bool|string
	{
		$code = nuProcedure((string)$procedure);
		if (trim($code) === '') return false;
		$error = '';
		(static function (string $__c) use (&$error): void { eval($__c); })(preg_replace('/^\s*<\?php/', '', $code));
		return $error === '' ? true : $error;
	}

	/** Runs a procedure hidden from PHP (access is checked); its output, JavaScript and errors join the current request. */
	function nuRunPHPHidden($nuCode): object
	{
		$saved = NuPhp::$hash;
		$res = NuPhp::procedure((string)$nuCode, NuPhp::$hash);
		NuPhp::$hash = $saved;
		if (empty($res['ok']) && !str_starts_with((string)$nuCode, 'nu')) nuDisplayError((string)($res['error'] ?? ''));
		$f = new stdClass();
		$f->id = 1;
		return $f;
	}

	/** Like nuRunPHPHidden (opening a procedure's output in a new window from PHP is not possible here). */
	function nuRunPHP($nuCode, $hidden = false): object
	{
		return nuRunPHPHidden($nuCode);
	}

	/** nuBuilder: shown as a message after the request (here: nuMessage in the browser). */
	function nuDisplayMessage($m): void
	{
		NuPhp::$js[] = 'nuMessage(' . json_encode(is_array($m) ? array_values($m) : [(string)$m], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ');';
	}

	/** JavaScript that runs in the browser after the request (same as nuAddJavascript). */
	function nuJavaScriptCallback($js): void
	{
		NuPhp::$js[] = (string)$js;
	}

	/** Writes the arguments to the PHP error log (nuBuilder: temp/nuDebug.txt). */
	function nuLog(...$args): void
	{
		error_log('nuLog: ' . implode(' ; ', array_map(static fn($a) => is_object($a) || is_array($a) ? print_r($a, true) : (string)$a, $args)));
	}

	function nuSetTimeLimit($seconds): void
	{
		if (function_exists('set_time_limit')) nu_time_limit((int)$seconds);
	}

	// ============================================================= session data (nuBuilder: zzzzsys_session.sss_access)

	/** Value kept for this login session (nuBuilder: in zzzzsys_session; here: in the PHP session). */
	function nuSetJSONData($name, $newJson): void
	{
		$_SESSION['nu_json_data'][(string)$name] = $newJson;
	}

	function nuGetJSONData($name): mixed
	{
		return $_SESSION['nu_json_data'][(string)$name] ?? '';
	}

	/** nuBuilder: for every open session. nuBuilder Next can only set it for the current session. */
	function nuSetJSONDataAll($i, $nj): void
	{
		nuSetJSONData($i, $nj);
	}

	// ============================================================= arrays, JSON, strings

	function nuObjKey($object, $key, $default = null): mixed
	{
		if (is_object($object)) return $object->$key ?? $default;
		return is_array($object) ? ($object[$key] ?? $default) : $default;
	}

	function nuJsonDecode($json, $associative = null): mixed
	{
		return json_decode((string)($json ?? ''), $associative);
	}

	/** base64(JSON) – the counterpart of nuEncode() in JavaScript (nuRunPHPWithParams). */
	function nuEncode($value): string
	{
		return base64_encode((string)json_encode($value));
	}

	function nuDecode($str): mixed
	{
		if (empty($str)) return '';
		$b = base64_decode((string)$str);
		$d = json_decode((string)$b, true);
		return json_last_error() === JSON_ERROR_NONE ? $d : $b;
	}

	function nuArrayContains($needle, $haystack): bool
	{
		return is_array($haystack) && in_array($needle, $haystack);
	}

	function nuArrayToSeparated($array, $separator = ', '): string
	{
		return is_array($array) ? implode((string)$separator, $array) : '';
	}

	function nuStringContains($needle, $haystack, $ignoreCase = false): bool
	{
		if ($haystack === null) return false;
		return ($ignoreCase ? stripos((string)$haystack, (string)$needle) : strpos((string)$haystack, (string)$needle)) !== false;
	}

	function nuStringStartsWith($needle, $haystack, $ignoreCase = false): bool
	{
		if ($haystack === null) return false;
		return ($ignoreCase ? stripos((string)$haystack, (string)$needle) : strpos((string)$haystack, (string)$needle)) === 0;
	}

	/** (nuBuilder's version could fail for repeated needles – fixed.) */
	function nuStringEndsWith($needle, $haystack, $ignoreCase = false): bool
	{
		if ($haystack === null) return false;
		return $ignoreCase ? str_ends_with(mb_strtolower((string)$haystack), mb_strtolower((string)$needle)) : str_ends_with((string)$haystack, (string)$needle);
	}

	function nuStringLeft($str, $length): string { return substr((string)$str, 0, (int)$length); }
	function nuStringRight($str, $length): string { return (int)$length <= 0 ? '' : substr((string)$str, -(int)$length); }
	function nuStrPos($haystack, $needle, $offset = 0): int|false { return strpos((string)($haystack ?? ''), (string)($needle ?? ''), (int)$offset); }
	function nuTrim($s): string { return trim((string)($s ?? '')); }
	function nuPad($i, $length, $pad = 0): string { return str_pad((string)$i, (int)$length, (string)$pad, STR_PAD_LEFT); }

	function nuStringAddTrailingCharacter($str, $char = '/'): string
	{
		return str_ends_with((string)$str, (string)$char) ? (string)$str : $str . $char;
	}

	function nuWrapWithTag($value, $tag): string
	{
		return '<' . $tag . '>' . $value . '</' . $tag . '>';
	}

	/** Escapes quotes for SQL strings (prefer parameters). */
	function nuAddslashes($s): string
	{
		return nu_db()->escapeString((string)($s ?? ''));      // quotes doubled on SQL Server and with NO_BACKSLASH_ESCAPES
	}

	/** Adds $char at the start and end when missing. */
	function nuQuotise($string, $char): ?string
	{
		$string = (string)$string;
		if ($string === '') return null;
		if ($string[0] !== $char) $string = $char . $string;
		if (substr($string, -1) !== $char) $string .= $char;
		return $string;
	}

	function nuRemoveNonCharacters($s): string
	{
		return strtr((string)$s, ["\t" => '', "\n" => '', "\r" => '']);
	}

	function nuSanitizeFilename($file): string
	{
		$file = (string)preg_replace('/[^\w\s\-~,;\[\]\(\).]/u', '', (string)$file);
		return (string)preg_replace('/\.{2,}/', '', $file);
	}

	function nuEnsureFileExtension($filename, $desiredExtension, $forceExtension = false): string
	{
		$ext = pathinfo((string)$filename, PATHINFO_EXTENSION);
		if ($ext === '' || $forceExtension) {
			$filename = (string)preg_replace('/\.[^.]*$/', '', (string)$filename) . '.' . ltrim((string)$desiredExtension, '.');
		}
		return (string)$filename;
	}

	function nuGetCSVDelimiterFromValue($value): string
	{
		return match ((int)$value) { 9 => "\t", 59 => ';', default => ',' };
	}

	// ============================================================= dates, numbers, colours

	function nuFormatDateTime($dateString, $outputFormat, $inputFormat = 'Y-m-d H:i:s'): string
	{
		if (trim((string)$dateString) === '') return '';
		$d = DateTime::createFromFormat((string)$inputFormat, (string)$dateString);
		return $d === false ? '' : $d->format((string)$outputFormat);
	}

	function nuFormatDate($dateString, $outputFormat, $inputFormat = 'Y-m-d'): string
	{
		return nuFormatDateTime($dateString, $outputFormat, $inputFormat);
	}

	/** Value with a nuBuilder format ('N|€ 1,000.00', 'D|dd.mm.yyyy', 'T|…'), as in the browser. */
	function nuAddFormatting($v, $f): string
	{
		return NuFormat::format($v, (string)$f);
	}

	function nuAddThousandSpaces($s, $c): string
	{
		return (string)preg_replace('/\B(?=(\d{3})+(?!\d))/', (string)$c, (string)$s);
	}

	function nuColorToHex($pColor): string
	{
		$c = strtolower(trim((string)$pColor));
		$named = ['black' => '000000', 'white' => 'FFFFFF', 'red' => 'FF0000', 'green' => '008000', 'blue' => '0000FF', 'yellow' => 'FFFF00',
			'orange' => 'FFA500', 'purple' => '800080', 'gray' => '808080', 'grey' => '808080', 'silver' => 'C0C0C0', 'maroon' => '800000',
			'olive' => '808000', 'lime' => '00FF00', 'aqua' => '00FFFF', 'teal' => '008080', 'navy' => '000080', 'fuchsia' => 'FF00FF',
			'pink' => 'FFC0CB', 'brown' => 'A52A2A', 'lightgray' => 'D3D3D3', 'lightgrey' => 'D3D3D3', 'darkgray' => 'A9A9A9', 'darkgrey' => 'A9A9A9'];
		return $named[$c] ?? strtoupper(ltrim((string)$pColor, '#'));
	}

	function nuHex2rgb($hexOrColor): array
	{
		$hex = nuColorToHex($hexOrColor);
		if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
	}

	// ============================================================= selects

	/** [value, text] pair for select options. */
	function nuSelectAddOption($text, $value): array
	{
		return [$text, $value];
	}

	/**
	 * Options of a select object's SQL: a SELECT (rows [value, text]), a JSON array of values, or "v1|t1|v2|t2" (nuBuilder's formats).
	 */
	function nuSelectOptions($sql, $id = ''): array
	{
		$sql = (string)$sql;
		if ((string)$id !== '') $sql = str_replace('#OBJECT_ID#', (string)$id, $sql);
		$s = trim(nuReplaceHashVariables($sql));
		if (preg_match('/^\s*(SELECT|WITH|SHOW)\b/i', $s)) {
			$out = [];
			$r = nuRunQuery($s);
			while (($row = db_fetch_row($r)) !== false) $out[] = $row;
			return $out;
		}
		if (str_starts_with($s, '[')) {
			$a = json_decode($s, true);
			return is_array($a) ? array_map(static fn($x) => [$x, $x], $a) : [];
		}
		$parts = explode('|', nuRemoveNonCharacters($s));
		$out = [];
		for ($i = 0, $n = count($parts); $i < $n; $i += 2) $out[] = [$parts[$i], $parts[$i + 1] ?? 'Undefined'];
		return $out;
	}

	/** Options of a datalist: SELECT (first column), JSON array or comma separated list. */
	function nuDataListOptions($input): array
	{
		$t = trim((string)$input);
		if (preg_match('/^(SELECT|SHOW)\b/i', $t)) {
			$out = [];
			$r = nuRunQuery($t);
			while (($row = db_fetch_row($r)) !== false) $out[] = $row;
			return $out;
		}
		if (str_starts_with($t, '[')) { $a = json_decode($t, true); return is_array($a) ? $a : []; }
		return array_map('trim', explode(',', $t));
	}

	// ============================================================= security and environment

	function nuPasswordHash($pw): string
	{
		return NuAuth::hash((string)$pw);
	}

	/** Random token of letters and digits. */
	function nuGenerateToken($length = 32): string
	{
		$a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$s = '';
		for ($i = 0; $i < (int)$length; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
		return $s;
	}

	function nuCryptoRandSecure($min, $max): int
	{
		return (int)$max <= (int)$min ? (int)$min : random_int((int)$min, (int)$max - 1);
	}

	function nuRandomHexBytes(int $length = 64): string
	{
		return bin2hex(random_bytes(max(1, intdiv($length - ($length % 2), 2))));
	}

	/** Client IP address (proxy headers only with $nuConfigTrustProxy, unlike nuBuilder). */
	function nuGetIPAddress(): ?string
	{
		$ip = nu_client_ip();
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
	}

	/** nuBuilder: true in demo mode (core/nudemo.php, 0.8.140); $showError adds "Not available in the demo." to the errors. */
	function nuDemo($showError = true): bool
	{
		if (!NuDemo::active()) return false;
		if ($showError) nuDisplayError(t('demo_not_available'));
		return true;
	}

	function nuIsHTTPS(): bool
	{
		$v = $_SERVER['HTTPS'] ?? $_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
		return strcasecmp((string)$v, 'on') === 0 || strcasecmp((string)$v, 'https') === 0;
	}

	function nuIsMobile(): bool
	{
		return (bool)preg_match('/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i',
			(string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
	}

	// ============================================================= files (zzzzsys_file)

	/** Data URL of a file in zzzzsys_file by id (nuBuilder's sfi_json "file"), null when missing. */
	function nuGetSrc($i): ?string
	{
		$j = json_decode((string)nu_db()->value('SELECT sfi_json FROM zzzzsys_file WHERE zzzzsys_file_id = ?', [(string)$i]));
		return is_object($j) && isset($j->file) ? (string)$j->file : null;
	}

	// ============================================================= auto numbers (input type nuAutoNumber)

	/** Next number of an nuAutoNumber object (sob_input_count, a prefix like "INV" is kept); -1 when it cannot be taken. */
	function nuUpdateCounter($id): string|int
	{
		return NuAutoNumber::next((string)$id) ?? -1;
	}

	/** The next number for a new record when $fieldId is an nuAutoNumber object, otherwise $value. */
	function nuAutoNumber($formId, $recordId, $fieldId, $value): mixed
	{
		$o = nu_db()->one('SELECT sob_all_type, sob_input_type, zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_id = ?',
			[(string)$formId, (string)$fieldId]);
		$isNew = nuHasNewRecordID() || nuHasNoRecordID() || (string)$recordId === '-1';
		if ($o && $o['sob_all_type'] === 'input' && $o['sob_input_type'] === 'nuAutoNumber' && $isNew) return nuUpdateCounter($o['zzzzsys_object_id']);
		return $value;
	}

	// ============================================================= more helpers (events, SQL, system tables)

	function nuEventName($eventName): string
	{
		return ['BB' => 'Before Browse', 'AB' => 'After Browse', 'BE' => 'Before Edit', 'BS' => 'Before Save', 'AS' => 'After Save',
			'BD' => 'Before Delete', 'AD' => 'After Delete', 'OS' => 'On Submit'][(string)$eventName] ?? '';
	}

	/** True when nuDisplayError was called in this request. */
	function nuErrorFound(): bool
	{
		return nuHasErrors();
	}

	/** nuBuilder: the last database error (nuBuilder Next stops the code on a database error instead) – always null. */
	function nuGetLastError(): mixed
	{
		return null;
	}

	/** SQL saved with nuBuilder's SQL Builder (zzzzsys_select.sse_code), hash variables replaced; '' when missing. */
	function nuSQL($code): string
	{
		$db = nu_db();
		if (!$db->tableExists('zzzzsys_select')) return '';
		$sql = $db->value('SELECT sse_sql FROM zzzzsys_select WHERE sse_code = ?', [(string)$code]);
		return $sql === null ? '' : nuReplaceHashVariables((string)$sql);
	}

	/** true when the query runs, otherwise the exception (nothing is logged). */
	function nuRunQueryTest($query, $params = []): mixed
	{
		try { nu_db()->run((string)$query, (array)$params); return true; } catch (Throwable $e) { return $e; }
	}

	function nuSQLRemoveComments($sql): string
	{
		if (empty($sql)) return '';
		$sql = (string)preg_replace('/\/\*[\s\S]*?\*\//', '', (string)$sql);
		return (string)preg_replace('/^\s*--(?! #).*$/m', '', $sql);
	}

	function nuSQLTrim($s, $noCR = 0): string
	{
		return trim((string)$s) === '' ? '' : ($noCR == 0 ? "\n$s" : (string)$s);
	}

	function nuTableInfoSQL(): string
	{
		return nuMSSQL() ? 'sp_columns ' : 'DESCRIBE ';
	}

	/** Replaces #TABLE_ID# with the quoted temporary table name. */
	function nuIdentTables($sql): string
	{
		return (string)preg_replace_callback('/#TABLE_ID#/i', static fn() => nuIdentColumn((string)(NuPhp::$hash['TABLE_ID'] ?? '')), (string)$sql);
	}

	/** True when the database user may create views. */
	function nuCanCreateView(): bool
	{
		$v = 'nu_test_view_' . mt_rand();
		$q = nuMSSQL() ? "CREATE VIEW [$v] AS SELECT TOP 1 zzzzsys_user_id FROM zzzzsys_user" : "CREATE VIEW $v AS SELECT zzzzsys_user_id FROM zzzzsys_user";
		if (nuRunQueryTest($q) !== true) return false;
		nuRunQueryTest("DROP VIEW $v");
		return true;
	}

	/** nuBuilder's zzzzsys_ tables of the database. */
	function nuListSystemTables(): array
	{
		return array_values(array_filter(nuListTables(), static fn($t) => stripos((string)$t, 'zzzzsys_') === 0));
	}

	function nuGetSysTables(): array
	{
		return nuListSystemTables();
	}

	/** The …_json column of a system table (zzzzsys_form → sfo_json). */
	function nu_compat_sys_json(string $tbl): ?string
	{
		if (!preg_match('/^\w+$/', $tbl)) return null;
		foreach (array_keys(nu_db()->columns('zzzzsys_' . $tbl)) as $c) if (str_ends_with(strtolower((string)$c), '_json')) return (string)$c;
		return null;
	}

	/** Value $jk of the JSON column of a system record (e.g. nuGetSysJSONValue('form', 'key', $formId)). */
	function nuGetSysJSONValue($tbl, $jk, $pk): mixed
	{
		$c = nu_compat_sys_json((string)$tbl);
		if ($c === null) return '';
		$j = json_decode((string)nu_db()->value("SELECT $c FROM zzzzsys_$tbl WHERE zzzzsys_{$tbl}_id = ?", [(string)$pk]), true);
		return is_array($j) ? ($j[$jk] ?? '') : '';
	}

	function nuSetSysJSONValue($tbl, $jk, $jv, $pk): void
	{
		$c = nu_compat_sys_json((string)$tbl);
		if ($c === null) return;
		$db = nu_db();
		$j = json_decode((string)$db->value("SELECT $c FROM zzzzsys_$tbl WHERE zzzzsys_{$tbl}_id = ?", [(string)$pk]), true);
		$j = is_array($j) ? $j : [];
		$j[(string)$jk] = $jv;
		$db->run("UPDATE zzzzsys_$tbl SET $c = ? WHERE zzzzsys_{$tbl}_id = ?", [json_encode($j, JSON_UNESCAPED_UNICODE), (string)$pk]);
	}

	/** Currency settings of a number format (zzzzsys_format.srm_currency: [sign, thousands, decimal, places]). */
	function nuGetNumberFormat($f): mixed
	{
		$v = nu_db()->value('SELECT srm_currency FROM zzzzsys_format WHERE srm_format = ?', [(string)$f]);
		return $v === null ? null : json_decode((string)$v);
	}

	/** ['1' => thousands separator, '0' => decimal separator] found in a format like "1,000.00". */
	function nuPunctuation($text): array
	{
		$out = ['', ''];
		foreach (['1,' => ',', '1.' => '.', '0,' => ',', '0.' => '.'] as $k => $v) if (str_contains((string)$text, $k)) $out[$k[0]] = $v;
		return $out;
	}

	/** User id for nuDebug entries. */
	function nuDebugUserId(): ?string
	{
		return (string)(NuPhp::$hash['USER_ID'] ?? '') ?: null;
	}

	/** 'P' procedure, 'R' report or 'F' form for a run object target. */
	function nuRunType($runType, $formId): string
	{
		if ($runType === 'P' || nuIsProcedure($formId)) return 'P';
		if ($runType === 'R' || nuIsReport($formId)) return 'R';
		return 'F';
	}
}
