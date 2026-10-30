<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * CSV import into a database table (globeadmin, Admin tools → CSV import, pages/nuimport.php + assets/js/nuimport.js).
 *
 * - The upload is converted to UTF-8 (BOM, UTF-16, Windows-1252) and kept as a private temp file for this session only.
 * - Columns are mapped to CSV columns, a fixed value or a generated nuID(); dates, numbers and yes/no are converted per column.
 * - Every value goes through prepared statements; only existing base tables outside zzzzsys_ can be written.
 * - check() runs the complete import inside a transaction and rolls it back: it reports exactly what import() will do,
 *   including database errors (duplicate keys, foreign keys). import() either writes all rows or – "stop on error" – nothing.
 */
final class NuImport
{
	public const SESSION = 'nu_import';
	public const MAX_ROWS = 200000;
	public const MAX_ERRORS = 300;
	public const DELIMITERS = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab', '|' => 'pipe'];
	/** Users (0.8.327): a target of its own – the same checks as the user form, never a plain write into zzzzsys_user. */
	public const USERS = 'zzzzsys_user';

	// ------------------------------------------------------------------ file

	public static function maxBytes(): int
	{
		return max(1, (int)(nu_cfg('nuConfigImportMaxMB') ?? 20) ?: 20) * 1024 * 1024;
	}

	private static function dir(): string
	{
		$dir = rtrim(sys_get_temp_dir(), '/\\') . '/nunext_import';
		if (!is_dir($dir)) @mkdir($dir, 0700, true);
		foreach (glob($dir . '/*.csv') ?: [] as $f) if (@filemtime($f) < time() - 86400) @unlink($f);   // leftovers older than a day
		return $dir;
	}

	/** The uploaded file of this session (or null). */
	public static function current(): ?array
	{
		$s = $_SESSION[self::SESSION] ?? null;
		return is_array($s) && is_file((string)($s['file'] ?? '')) ? $s : null;
	}

	public static function discard(): void
	{
		$s = $_SESSION[self::SESSION] ?? null;
		if (is_array($s) && is_file((string)($s['file'] ?? ''))) @unlink((string)$s['file']);
		unset($_SESSION[self::SESSION]);
	}

	/** Stores an uploaded file ($_FILES entry) as UTF-8 temp file. @return array session info */
	public static function upload(array $f): array
	{
		if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
			throw new RuntimeException(t(in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'imp_err_size' : 'imp_err_upload', self::maxBytes() >> 20));
		}
		if ((int)$f['size'] > self::maxBytes()) throw new RuntimeException(t('imp_err_size', self::maxBytes() >> 20));
		$ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, ['csv', 'txt', 'tsv', 'tab'], true)) throw new RuntimeException(t('imp_err_type'));
		$raw = (string)file_get_contents((string)$f['tmp_name']);
		if (str_contains(substr($raw, 0, 4096), "\0") && !preg_match('/^(\xFF\xFE|\xFE\xFF)/', $raw)) throw new RuntimeException(t('imp_err_type'));

		[$text, $enc] = self::toUtf8($raw);
		$text = preg_replace("/\r\n?/", "\n", $text);           // Windows and old Mac line ends
		if (trim($text) === '') throw new RuntimeException(t('imp_err_empty'));

		self::discard();
		$path = self::dir() . '/' . bin2hex(random_bytes(16)) . '.csv';
		if (file_put_contents($path, $text) === false) throw new RuntimeException(t('imp_err_upload', 0));
		@chmod($path, 0600);
		$info = ['file' => $path, 'name' => mb_substr(basename((string)$f['name']), 0, 200), 'size' => (int)$f['size'], 'encoding' => $enc,
			'delimiter' => self::detectDelimiter($path)];
		$_SESSION[self::SESSION] = $info;
		return $info;
	}

	/** @return array{0: string, 1: string} UTF-8 text without BOM, source encoding */
	private static function toUtf8(string $raw): array
	{
		if (str_starts_with($raw, "\xEF\xBB\xBF")) return [substr($raw, 3), 'UTF-8 (BOM)'];
		if (str_starts_with($raw, "\xFF\xFE")) return [mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE'), 'UTF-16 LE'];
		if (str_starts_with($raw, "\xFE\xFF")) return [mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE'), 'UTF-16 BE'];
		if (mb_check_encoding($raw, 'UTF-8')) return [$raw, 'UTF-8'];
		return [mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'), 'Windows-1252'];   // Excel "CSV" on Windows
	}

	/** Separator with the most consistent column count over the first lines (quotes respected). */
	public static function detectDelimiter(string $path): string
	{
		$lines = [];
		$h = fopen($path, 'r');
		while ($h && count($lines) < 25 && ($l = fgets($h)) !== false) if (trim($l) !== '') $lines[] = $l;
		if ($h) fclose($h);
		$best = ','; $bestScore = -1;
		foreach (array_keys(self::DELIMITERS) as $d) {
			$counts = array_map(static fn($l) => count(str_getcsv($l, $d, '"', '')), $lines);
			if (!$counts) continue;
			$freq = array_count_values($counts);
			arsort($freq);
			$mode = (int)array_key_first($freq);
			if ($mode < 2) continue;
			$score = $freq[$mode] / count($counts) * 100 + min($mode, 30);   // consistency first, then more columns
			if ($score > $bestScore) { $bestScore = $score; $best = $d; }
		}
		return $best;
	}

	/** Normalised reading options from a request. */
	public static function readOptions(array $in): array
	{
		$d = (string)($in['delimiter'] ?? '');
		$d = $d === 'tab' || $d === '\t' ? "\t" : $d;
		if (mb_strlen($d) !== 1) $d = (string)(self::current()['delimiter'] ?? ',');
		$q = (string)($in['quote'] ?? '"');
		$q = in_array($q, ['"', "'"], true) ? $q : '"';
		return ['delimiter' => $d, 'quote' => $q, 'header' => (string)($in['header'] ?? '1') !== '0'];
	}

	/** Rows of the file: generator of [line number, cells]; empty lines are skipped. */
	public static function rows(array $o): Generator
	{
		$s = self::current();
		if (!$s) throw new RuntimeException(t('imp_err_nofile'));
		$h = fopen($s['file'], 'r');
		$n = 0;
		try {
			while (($r = fgetcsv($h, null, $o['delimiter'], $o['quote'], '')) !== false) {
				$n++;
				if ($r === [null] || implode('', array_map('trim', array_map('strval', $r))) === '') continue;
				yield [$n, array_map(static fn($v) => (string)$v, $r)];
				if ($n > self::MAX_ROWS + 1) throw new RuntimeException(t('imp_err_rows', self::MAX_ROWS));
			}
		} finally {
			fclose($h);
		}
	}

	/** Headers, first rows, row count and per CSV column a detected date format. */
	public static function preview(array $o): array
	{
		$headers = null; $rows = []; $count = 0; $width = 0; $samples = [];
		foreach (self::rows($o) as [$line, $r]) {
			if ($headers === null && $o['header']) { $headers = $r; continue; }
			$count++;
			$width = max($width, count($r));
			if (count($rows) < 10) $rows[] = $r;
			foreach ($r as $i => $v) if (trim($v) !== '' && count($samples[$i] ?? []) < 40) $samples[$i][] = trim($v);
		}
		$width = max($width, count($headers ?? []));
		$names = [];
		for ($i = 0; $i < $width; $i++) {
			$h = trim((string)($headers[$i] ?? ''));
			$names[] = $h !== '' ? $h : t('imp_col_n', $i + 1);
		}
		$dates = [];
		for ($i = 0; $i < $width; $i++) $dates[] = self::detectDateFormat($samples[$i] ?? []);
		return ['headers' => $names, 'rows' => $rows, 'count' => $count, 'dates' => $dates, 'decimal' => self::detectDecimal(array_merge([], ...array_values($samples)))];
	}

	// ------------------------------------------------------------------ tables and columns

	/** Base tables that may be imported into (no nuBuilder / nuBuilder Next system tables). */
	public static function tables(): array
	{
		$db = nu_db();
		$rows = match ($db->type) {
			'mysql'  => $db->all("SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"),
			'sqlsrv' => $db->all("SELECT TABLE_NAME AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"),
			default  => $db->all("SELECT name AS n FROM sqlite_master WHERE type = 'table' ORDER BY name"),
		};
		return array_values(array_filter(array_map(static fn($r) => (string)$r['n'], $rows),
			static fn($t) => !str_starts_with(strtolower($t), 'zzzzsys_') && !str_starts_with($t, 'sqlite_') && preg_match('/^[A-Za-z0-9_]+$/', $t)));
	}

	/** May tables be imported into (Admin tools → CSV import)? */
	public static function canTables(): bool
	{
		return NuAdmin::can('import', 'change');
	}

	/** May users be imported (users browse list → Import…)? Whoever may add users: the globeadmin, the access level or an admin role. */
	public static function canUsers(): bool
	{
		return !empty(NuAcl::rights(NuAcl::FORM_USER)['add']);
	}

	/** Targets of this user: the users first, then the tables. */
	public static function targets(): array
	{
		return array_merge(self::canUsers() ? [self::USERS] : [], self::canTables() ? self::tables() : []);
	}

	/**
	 * Columns of an importable table: name, type, kind (date/datetime/time/int/decimal/bool/text), length, flags.
	 * @return array<string, array>
	 */
	public static function columns(string $table): array
	{
		if ($table === self::USERS) {
			if (!self::canUsers()) throw new RuntimeException(t('imp_err_table'));
			return self::userColumns();
		}
		if (!self::canTables() || !in_array($table, self::tables(), true)) throw new RuntimeException(t('imp_err_table'));
		$db = nu_db();
		if ($db->type === 'mysql') {
			$rows = $db->all('SELECT COLUMN_NAME AS n, COLUMN_TYPE AS t, DATA_TYPE AS dt, IS_NULLABLE AS nul, COLUMN_DEFAULT AS d, COLUMN_KEY AS k, EXTRA AS x,
				CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
		} elseif ($db->type === 'sqlsrv') {
			$rows = $db->all("SELECT c.COLUMN_NAME AS n, c.DATA_TYPE AS t, c.DATA_TYPE AS dt, c.IS_NULLABLE AS nul, c.COLUMN_DEFAULT AS d,
				CASE WHEN EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku ON ku.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
					WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_NAME = c.TABLE_NAME AND ku.COLUMN_NAME = c.COLUMN_NAME) THEN 'PRI' ELSE '' END AS k,
				CASE WHEN COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') = 1 THEN 'auto_increment' ELSE '' END AS x,
				c.CHARACTER_MAXIMUM_LENGTH AS len FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_NAME = ? ORDER BY c.ORDINAL_POSITION", [$table]);
		} else {
			$rows = array_map(static fn($r) => ['n' => $r['name'], 't' => strtolower((string)$r['type']), 'dt' => strtolower(preg_replace('/\(.*/', '', (string)$r['type'])),
				'nul' => $r['notnull'] ? 'NO' : 'YES', 'd' => $r['dflt_value'], 'k' => $r['pk'] ? 'PRI' : '', 'x' => '', 'len' => null],
				$db->all('PRAGMA table_info("' . str_replace('"', '""', $table) . '")'));
		}
		$out = [];
		foreach ($rows as $r) {
			$name = (string)$r['n'];
			if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) continue;          // only plain names can be written safely
			$t = strtolower((string)$r['t']); $dt = strtolower((string)$r['dt']);
			$kind = match (true) {
				in_array($dt, ['datetime', 'datetime2', 'timestamp', 'smalldatetime', 'datetimeoffset'], true) => 'datetime',
				$dt === 'date' => 'date', $dt === 'time' => 'time',
				$dt === 'bit' || $t === 'tinyint(1)' || $dt === 'boolean' => 'bool',
				in_array($dt, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'year'], true) => 'int',
				in_array($dt, ['decimal', 'numeric', 'float', 'double', 'real', 'money', 'smallmoney'], true) => 'decimal',
				default => 'text',
			};
			$pk = (string)$r['k'] === 'PRI';
			$auto = str_contains(strtolower((string)$r['x']), 'auto_increment');
			$hasDefault = $r['d'] !== null || str_contains(strtolower((string)$r['x']), 'default_generated');
			$len = $r['len'] !== null && (int)$r['len'] > 0 ? (int)$r['len'] : null;
			$out[$name] = ['name' => $name, 'type' => (string)$r['t'], 'kind' => $kind, 'len' => $len, 'pk' => $pk, 'auto' => $auto,
				'nullable' => (string)$r['nul'] === 'YES', 'default' => $hasDefault, 'required' => (string)$r['nul'] !== 'YES' && !$hasDefault && !$auto,
				'nuid' => $pk && !$auto && $kind === 'text' && ($len === null || $len >= 15)];
		}
		return $out;
	}

	// ------------------------------------------------------------------ conversion

	/** PHP date formats offered for date columns (code => example). */
	public static function dateFormats(): array
	{
		return [
			'Y-m-d' => '2026-12-25', 'd.m.Y' => '25.12.2026', 'd.m.y' => '25.12.26', 'd/m/Y' => '25/12/2026', 'm/d/Y' => '12/25/2026', 'd-m-Y' => '25-12-2026',
			'Y/m/d' => '2026/12/25', 'Y-m-d H:i:s' => '2026-12-25 14:30:00', 'Y-m-d H:i' => '2026-12-25 14:30', 'Y-m-d\TH:i:s' => '2026-12-25T14:30:00',
			'd.m.Y H:i:s' => '25.12.2026 14:30:00', 'd.m.Y H:i' => '25.12.2026 14:30', 'd/m/Y H:i' => '25/12/2026 14:30', 'm/d/Y H:i:s' => '12/25/2026 14:30:00',
			'm/d/Y g:i A' => '12/25/2026 2:30 PM', 'H:i:s' => '14:30:00', 'H:i' => '14:30',
			'timestamp' => '1798201800', 'excel' => '46381',
		];
	}

	public static function parseDate(string $v, string $fmt): ?DateTimeImmutable
	{
		$v = trim($v);
		if ($v === '') return null;
		if ($fmt === 'timestamp') return preg_match('/^-?\d{1,11}$/', $v) ? (new DateTimeImmutable('@' . $v))->setTimezone(new DateTimeZone(date_default_timezone_get())) : null;
		if ($fmt === 'excel') {                                   // Excel serial number (1900 system), with fraction = time
			if (!preg_match('/^\d{1,6}([.,]\d+)?$/', $v)) return null;
			$f = (float)str_replace(',', '.', $v);
			return (new DateTimeImmutable('1899-12-30 00:00:00'))->modify('+' . (int)floor($f) . ' days')->modify('+' . (int)round(($f - floor($f)) * 86400) . ' seconds');
		}
		$d = DateTimeImmutable::createFromFormat('!' . $fmt, $v);
		$e = DateTimeImmutable::getLastErrors();
		if (!$d || ($e && ($e['warning_count'] || $e['error_count']))) return null;   // strict: 31.02. or 13/25 fail
		return $d;
	}

	/**
	 * Format that reads the most samples (at least 80 %, so one wrong value does not hide the format; '' if none).
	 * Ambiguous d/m vs m/d: the order of dateFormats() decides – European first.
	 */
	public static function detectDateFormat(array $samples): string
	{
		if (!$samples) return '';
		$best = ''; $bestHits = 0;
		foreach (array_keys(self::dateFormats()) as $fmt) {
			if ($fmt === 'timestamp' || $fmt === 'excel') continue;          // plain numbers are not guessed as dates
			$hits = 0;
			foreach ($samples as $s) if (self::parseDate($s, $fmt) !== null) $hits++;
			if ($hits > $bestHits) { $best = $fmt; $bestHits = $hits; }
			if ($hits === count($samples)) break;
		}
		return $bestHits >= max(1, (int)ceil(count($samples) * 0.8)) ? $best : '';
	}

	/** Decimal separator used by the numbers of the file ('.' or ','), from values like 1'234.50 / 1.234,50 / 12,5. */
	public static function detectDecimal(array $values): string
	{
		$comma = $dot = 0;
		foreach ($values as $v) {
			$v = str_replace(["\u{00A0}", "\u{202F}", ' ', "'", '’'], '', trim((string)$v));
			if (!preg_match('/^[+-]?[\d.,]+$/', $v) || !preg_match('/\d/', $v)) continue;
			if (preg_match('/^[+-]?\d{1,3}(\.\d{3})+,\d+$|^[+-]?\d+,\d{1,2}$/', $v)) $comma++;        // 1.234,50 or 12,5
			elseif (preg_match('/^[+-]?\d{1,3}(,\d{3})+\.\d+$|^[+-]?\d+\.\d{1,2}$/', $v)) $dot++;    // 1,234.50 or 12.5
		}
		return $comma > $dot ? ',' : '.';
	}

	/**
	 * Decimal number in a canonical form ("1234.5") or null. Thousands separators are only accepted in groups of three,
	 * so "12'500,50" read with "." is an error instead of 1250050.
	 */
	private static function number(string $v, string $dec): ?string
	{
		$v = str_replace(["\u{00A0}", "\u{202F}", ' ', "'", '’'], '', $v);
		$thou = $dec === ',' ? '.' : ',';
		if (str_contains($v, $thou)) {
			if (!preg_match('/^[+-]?\(?\d{1,3}(' . preg_quote($thou, '/') . '\d{3})+(' . preg_quote($dec, '/') . '\d+)?\)?$/', $v)) return null;
			$v = str_replace($thou, '', $v);
		}
		if ($dec === ',') $v = str_replace(',', '.', $v);
		if (preg_match('/^\((.+)\)$/', $v, $m)) $v = '-' . $m[1];        // (123.45) accounting style
		return preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $v) ? $v : null;
	}

	/** convert(): leave the column out of the INSERT so that the database default applies. */
	public const SKIP = "\0skip";

	/**
	 * One CSV value → database value for a column. @return array{0: mixed, 1: ?string} value (or SKIP), error
	 */
	public static function convert(string $v, array $col, array $opt, string $fmt = ''): array
	{
		if ($opt['trim']) $v = trim($v);
		if ($v === '') {
			if ($opt['empty'] === 'null' && $col['nullable']) return [null, null];
			if ($col['kind'] === 'text' && $opt['empty'] !== 'null') return ['', null];
			if ($col['nullable']) return [null, null];
			if ($col['default'] || $col['auto']) return [self::SKIP, null];  // the database fills it
			return [null, t('imp_err_required')];
		}
		switch ($col['kind']) {
			case 'date': case 'datetime': case 'time':
				$d = self::parseDate($v, $fmt !== '' ? $fmt : (self::detectDateFormat([$v]) ?: 'Y-m-d'));
				if (!$d) return [null, t('imp_err_date', $fmt !== '' ? $fmt : '?')];
				return [$d->format(['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'][$col['kind']]), null];
			case 'bool':
				$l = mb_strtolower($v);
				if (in_array($l, ['1', 'true', 'yes', 'y', 'ja', 'j', 'x', 'on', 'wahr', 'oui', 'si', 'sì'], true)) return [1, null];
				if (in_array($l, ['0', 'false', 'no', 'n', 'nein', 'off', 'falsch', 'non', '-'], true)) return [0, null];
				return [null, t('imp_err_bool')];
			case 'int':
				$n = self::number($v, $opt['decimal']);
				if ($n === null || !preg_match('/^[+-]?\d+(\.0*)?$/', $n)) return [null, t('imp_err_int')];
				return [(int)$n, null];
			case 'decimal':
				$n = self::number($v, $opt['decimal']);
				return $n === null ? [null, t('imp_err_number')] : [$n, null];
			default:
				if ($col['len'] !== null && mb_strlen($v) > $col['len']) return [null, t('imp_err_long', mb_strlen($v), $col['len'])];
				return [$v, null];
		}
	}

	/** Import options from a request. */
	public static function options(array $in): array
	{
		$mode = (string)($in['mode'] ?? 'insert');
		return [
			'mode' => in_array($mode, ['insert', 'update', 'upsert'], true) ? $mode : 'insert',
			'key' => (string)($in['key'] ?? ''), 'decimal' => (string)($in['decimal'] ?? '.') === ',' ? ',' : '.',
			'empty' => (string)($in['empty'] ?? 'null') === 'keep' ? 'keep' : 'null', 'trim' => (string)($in['trim'] ?? '1') !== '0',
			'audit' => (string)($in['audit'] ?? '1') !== '0', 'welcome' => (string)($in['welcome'] ?? '0') === '1', 'on_error' => (string)($in['on_error'] ?? 'stop') === 'skip' ? 'skip' : 'stop',
		];
	}

	/** Validated mapping: column => ['src' => csv|const|nuid, 'idx' => int, 'value' => string, 'fmt' => string]. */
	public static function mapping(array $in, array $cols, int $width): array
	{
		$map = [];
		foreach ($in as $col => $m) {
			if (!isset($cols[$col]) || !is_array($m)) continue;
			$src = (string)($m['src'] ?? '');
			$fmt = (string)($m['fmt'] ?? '');
			if ($fmt !== '' && !isset(self::dateFormats()[$fmt])) $fmt = '';
			if ($src === 'csv' && ctype_digit((string)($m['idx'] ?? '')) && (int)$m['idx'] < $width) $map[$col] = ['src' => 'csv', 'idx' => (int)$m['idx'], 'fmt' => $fmt];
			elseif ($src === 'const') $map[$col] = ['src' => 'const', 'value' => (string)($m['value'] ?? ''), 'fmt' => $fmt];
			elseif ($src === 'nuid' && $cols[$col]['nuid']) $map[$col] = ['src' => 'nuid'];
		}
		return $map;
	}

	/** Converted values of the first rows for the mapping preview: [column => [[value, error], …]]. */
	public static function sample(array $read, array $cols, array $map, array $opt, int $limit = 4): array
	{
		$out = []; $n = 0; $first = true;
		foreach (self::rows($read) as [$line, $r]) {
			if ($first && $read['header']) { $first = false; continue; }
			$first = false;
			foreach ($map as $col => $m) {
				if ($m['src'] === 'nuid') { $out[$col][] = ['nuID()', null]; continue; }
				[$v, $e] = self::convert($m['src'] === 'csv' ? ($r[$m['idx']] ?? '') : $m['value'], $cols[$col], $opt, $m['fmt'] ?? '');
				if (!empty($cols[$col]['secret']) && $v !== null && $v !== self::SKIP) $v = '••••••';   // passwords never shown
				$out[$col][] = [$v === self::SKIP ? '(default)' : ($v === null ? 'NULL' : (string)$v), $e];
			}
			if (++$n >= $limit) break;
		}
		return $out;
	}

	// ------------------------------------------------------------------ import

	/**
	 * Runs the import. $dryRun: everything happens inside a transaction that is rolled back at the end.
	 * @return array{inserted: int, updated: int, unchanged: int, failed: int, rows: int, errors: array, rolled_back: bool, skipped?: int, skipped_logins?: array, welcome?: int, welcome_failed?: int, random?: int}
	 */
	public static function run(string $table, array $read, array $mapIn, array $opt, bool $dryRun): array
	{
		if ($table === self::USERS) {
			if (!self::canUsers()) throw new RuntimeException(t('imp_err_table'));
			return self::runUsers($read, $mapIn, $opt, $dryRun);
		}
		$cols = self::columns($table);
		$prev = self::preview($read);
		$map = self::mapping($mapIn, $cols, count($prev['headers']));
		if (!$map) throw new RuntimeException(t('imp_err_nomap'));
		if ($opt['mode'] !== 'insert') {
			if (!isset($cols[$opt['key']]) || !isset($map[$opt['key']]) || $map[$opt['key']]['src'] === 'nuid') throw new RuntimeException(t('imp_err_key'));
		}
		foreach ($cols as $c) {
			if ($opt['mode'] !== 'update' && $c['required'] && !isset($map[$c['name']])) throw new RuntimeException(t('imp_err_unmapped', $c['name']));
		}

		$db = nu_db();
		$u = NuAuth::user();
		$who = $u['globeadmin'] ? 'globeadmin' : (string)$u['login'];
		$now = date('Y-m-d H:i:s');
		$audit = static function (array $row, bool $insert) use ($cols, $opt, $who, $now): array {
			if (!$opt['audit']) return $row;
			$set = $insert ? ['created_at' => $now, 'created_by' => $who, 'nu_created_date' => $now, 'nu_created_by' => $who]
				: ['updated_at' => $now, 'updated_by' => $who, 'nu_updated_date' => $now, 'nu_updated_by' => $who];
			foreach ($set as $c => $v) if (isset($cols[$c]) && !array_key_exists($c, $row)) $row[$c] = $v;
			return $row;
		};

		// nuBuilder's <table>_nulog (0.8.349): added on insert, edited after a changing update; a mapped value for it is ignored
		$nlCol = isset($cols[$table . '_nulog']) ? $table . '_nulog' : null;
		$nulog = static function (array $row, ?string $key) use ($nlCol, $db, $table, $opt): array {
			if ($nlCol === null) return $row;
			$row[$nlCol] = $key === null ? NuRecord::nulogAdded()
				: NuRecord::nulogEdited($db->value('SELECT ' . NuDB::ident($nlCol) . ' FROM ' . NuDB::ident($table) . ' WHERE ' . NuDB::ident($opt['key']) . ' = ?', [$key]));
			return $row;
		};
		$res = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'rows' => 0, 'errors' => [], 'rolled_back' => false];
		$addError = static function (int $line, string $col, string $value, string $msg) use (&$res): void {
			if (count($res['errors']) < self::MAX_ERRORS) $res['errors'][] = ['line' => $line, 'column' => $col, 'value' => mb_strimwidth($value, 0, 80, '…'), 'message' => $msg];
		};
		$keys = [];                                                  // keys already seen in this file (update / upsert)

		// audit trail (0.8.350): one change for the whole import, rows of a dry run or a rolled back import go with the transaction
		$pkCol = null;
		foreach ($cols as $c) if ($c['pk']) { $pkCol = $c['name']; break; }
		$auditOn = !$dryRun && NuAudit::on() && NuAudit::audited($table);
		$actx = ['source' => 'import'];
		$auditInsert = static function (array $row) use ($auditOn, $pkCol, $cols, $db, $table, $actx): void {
			if (!$auditOn || $pkCol === null) return;
			NuAudit::record('insert', $table, (string)($row[$pkCol] ?? ($cols[$pkCol]['auto'] ? $db->lastInsertId() : '')), [], $row, $actx);
		};
		if ($auditOn) NuAudit::begin();
		// webhooks (0.8.715): forms on this table with a webhook – one call per imported / changed row, queued with the row's savepoint
		$whForms = !$dryRun ? NuWebhook::formsOfTable($table) : [];
		$whKey = $pkCol ?? $opt['key'];
		$webhook = static function (string $event, string $k, array $before = []) use ($whForms, $db, $table, $whKey): void {
			if (!$whForms || $whKey === '' || $k === '') return;
			$now = (array)$db->one('SELECT * FROM ' . NuDB::ident($table) . ' WHERE ' . NuDB::ident($whKey) . ' = ?', [$k]);
			foreach ($whForms as $f) NuWebhook::queue($f['id'], $f['code'], $table, $event, $k, $now, $before, 'import');
		};
		$insKey = static fn(array $ins): string => $whKey !== '' ? (string)($ins[$whKey] ?? ($pkCol !== null && $cols[$pkCol]['auto'] ? $db->lastInsertId() : '')) : '';

		$pdo = $db->pdo();
		$pdo->beginTransaction();
		try {
			$first = true;
			foreach (self::rows($read) as [$line, $r]) {
				if ($first && $read['header']) { $first = false; continue; }
				$first = false;
				$res['rows']++;
				$row = []; $bad = false;
				foreach ($map as $col => $m) {
					if ($m['src'] === 'nuid') { $row[$col] = nu_new_key($cols[$col] ?? null); continue; }   // UUID for a 36-character key (0.8.622)
					$raw = $m['src'] === 'csv' ? ($r[$m['idx']] ?? '') : $m['value'];
					[$v, $e] = self::convert($raw, $cols[$col], $opt, $m['fmt'] ?? '');
					if ($e !== null) { $addError($line, $col, $raw, $e); $bad = true; continue; }
					if ($v !== self::SKIP) $row[$col] = $v;
				}
				if (!$bad && $opt['mode'] !== 'insert') {
					$k = (string)($row[$opt['key']] ?? '');
					if ($k === '') { $addError($line, $opt['key'], '', t('imp_err_keyempty')); $bad = true; }
					elseif (isset($keys[$k])) { $addError($line, $opt['key'], $k, t('imp_err_dupkey', $keys[$k])); $bad = true; }
					else $keys[$k] = $line;
				}
				if ($bad) {
					$res['failed']++;
					if ($opt['on_error'] === 'stop' && !$dryRun && count($res['errors'])) break;
					continue;
				}
				try {
					$pdo->exec($db->type === 'sqlsrv' ? 'SAVE TRANSACTION nu_row' : 'SAVEPOINT nu_row');   // one failed row does not spoil the others
					if ($opt['mode'] === 'insert') {
						$db->insert($table, $ins = $nulog($audit($row, true), null));
						$insId = $insKey($ins);
						$auditInsert($ins);
						$webhook('insert', $insId);
						$res['inserted']++;
					} else {
						$k = (string)$row[$opt['key']];
						$exists = (bool)$db->value('SELECT COUNT(*) FROM ' . NuDB::ident($table) . ' WHERE ' . NuDB::ident($opt['key']) . ' = ?', [$k]);
						if ($exists) {
							$set = $row;
							unset($set[$opt['key']]);
							if ($nlCol !== null) unset($set[$nlCol]);
							$before = $auditOn || $whForms ? $db->one('SELECT * FROM ' . NuDB::ident($table) . ' WHERE ' . NuDB::ident($opt['key']) . ' = ?', [$k]) : null;
							$upd = $set ? $audit($set, false) : [];
							$n = $upd ? $db->update($table, $upd, $opt['key'], $k) : 0;
							if ($n && $before && $auditOn) NuAudit::record('update', $table, $pkCol !== null ? (string)$before[$pkCol] : $k, $before, $upd, $actx);
							if ($n && $before) $webhook('update', $pkCol !== null ? (string)$before[$pkCol] : $k, (array)$before);
							if ($n && $nlCol !== null) $db->update($table, $nulog([], $k), $opt['key'], $k);     // nuBuilder's _nulog: only when something changed
							$n ? $res['updated']++ : $res['unchanged']++;
						} elseif ($opt['mode'] === 'upsert') {
							$db->insert($table, $ins = $nulog($audit($row, true), null));
							$insId = $insKey($ins);
							$auditInsert($ins);
							$webhook('insert', $insId);
							$res['inserted']++;
						} else {
							$addError($line, $opt['key'], $k, t('imp_err_notfound'));
							$res['failed']++;
						}
					}
					if ($db->type !== 'sqlsrv') $pdo->exec('RELEASE SAVEPOINT nu_row');
				} catch (PDOException $e) {
					$pdo->exec($db->type === 'sqlsrv' ? 'ROLLBACK TRANSACTION nu_row' : 'ROLLBACK TO SAVEPOINT nu_row');
					$addError($line, '', '', self::dbMessage($e));
					$res['failed']++;
					if ($opt['on_error'] === 'stop' && !$dryRun) break;
				}
			}
			if ($dryRun || ($opt['on_error'] === 'stop' && $res['failed'])) {
				$pdo->rollBack();
				$res['rolled_back'] = !$dryRun;
				if (!$dryRun) $res['inserted'] = $res['updated'] = $res['unchanged'] = 0;   // nothing stayed in the table
			} else {
				$pdo->commit();
			}
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) $pdo->rollBack();
			throw $e;
		} finally {
			if ($auditOn) NuAudit::end();
		}
		return $res;
	}

	// ------------------------------------------------------------------ users (0.8.327)

	/** Access levels a user may be given here: code => id (restricted: no admin-equivalent levels, as in the user form). */
	public static function userLevels(): array
	{
		$out = [];
		foreach (nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access ORDER BY sal_code') as $a) {
			$id = (string)$a['zzzzsys_access_id'];
			if (NuSecurity::restricted() && NuSecurity::adminRightsOf($id)) continue;
			$out[(string)($a['sal_code'] ?: $id)] = $id;
		}
		return $out;
	}

	/** The columns of the user import: fields of the user form, the access level by code and the password. */
	private static function userColumns(): array
	{
		$labels = self::userLabels();
		$col = static fn(string $name, string $type, int $len, bool $req = false, string $kind = 'text', array $extra = []) => $extra + ['label' => $labels[$name] ?? '', 'name' => $name, 'type' => $type,
			'kind' => $kind, 'len' => $len ?: null, 'pk' => false, 'auto' => false, 'nullable' => !$req, 'default' => false, 'required' => $req, 'nuid' => false];
		$out = [];
		$out['sus_login_name'] = $col('sus_login_name', t('user_login_name'), 50, true);
		$out['sus_name']       = $col('sus_name', t('user_display_name') . ' · ' . t('imp_u_name_hint'), 50);
		foreach (['sus_first_name' => [30, 'user_first_name'], 'sus_last_name' => [30, 'user_last_name'], 'sus_email' => [255, 'user_email'],
			'sus_code' => [50, 'user_code'], 'sus_position' => [50, 'user_position'], 'sus_department' => [50, 'user_department'], 'sus_team' => [50, 'user_team'],
			'sus_additional1' => [100, 'user_additional1'], 'sus_additional2' => [100, 'user_additional2']] as $n => [$len, $label]) $out[$n] = $col($n, t($label), $len);
		$out['access_level']   = $col('access_level', t('imp_u_access'), 0, true, 'text', ['choices' => array_keys(self::userLevels())]);
		$out['sus_language']   = $col('sus_language', t('user_language') . ' · ' . t('imp_u_lang_hint'), 0);
		$out['sus_expires_on'] = $col('sus_expires_on', t('user_expires'), 0, false, 'date');
		$out['password']       = $col('password', t('imp_u_password'), 72, false, 'text', ['secret' => true]);
		foreach (NuUserFields::editable('import') as $code => $f) {    // custom user fields (0.8.454): uf_<code>
			$max = NuUserFields::TYPES[(string)$f['type']][2];
			$out['uf_' . $code] = $col('uf_' . $code, NuUserFields::label($f), $max, (string)$f['required'] === '1', (string)$f['type'] === 'date' ? 'date' : 'text');
		}
		return $out;
	}

	/** Short column titles of the user import (template header row, and matched by "Map automatically"). */
	public static function userLabels(): array
	{
		return ['sus_login_name' => t('user_login_name'), 'sus_name' => t('user_display_name'), 'sus_first_name' => t('user_first_name'),
			'sus_last_name' => t('user_last_name'), 'sus_email' => t('user_email'), 'access_level' => t('access_level'), 'sus_code' => t('user_code'),
			'sus_position' => t('user_position'), 'sus_department' => t('user_department'), 'sus_team' => t('user_team'), 'sus_additional1' => t('user_additional1'),
			'sus_additional2' => t('user_additional2'), 'sus_language' => t('user_language'), 'sus_expires_on' => t('user_expires'), 'password' => t('login_password')]
			+ array_combine(array_map(static fn($c) => 'uf_' . $c, array_keys(NuUserFields::editable('import'))), array_map([NuUserFields::class, 'label'], NuUserFields::editable('import')));   // 0.8.454
	}

	/** CSV template of the user import: header row only, separator $nuConfigCSVSeparator (default ;), UTF-8 with BOM for Excel. */
	public static function userTemplate(): string
	{
		$sep = (string)(nu_cfg('nuConfigCSVSeparator') ?: ';');
		if (!in_array($sep, [';', ',', "\t", '|'], true)) $sep = ';';
		$q = static fn(string $v) => preg_match('/["\r\n' . preg_quote($sep, '/') . ']/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
		return "\xEF\xBB\xBF" . implode($sep, array_map($q, array_values(self::userLabels()))) . "\r\n";
	}

	/** Language of a CSV value: [nuBuilder name, variant] – "de", "de-CH", "German", "Deutsch", "English (US)" …; null = unknown. */
	public static function userLanguage(string $v): ?array
	{
		$l = mb_strtolower(str_replace('_', '-', trim($v)));
		foreach (NU_LANG_VARIANTS as $vc => [$base, $vName]) {
			if ($l === strtolower($vc) || $l === mb_strtolower($vName)) return [NU_LANGS[$base][1], $vc];
		}
		foreach (NU_LANGS as $code => [$own, $db]) {
			if ($l === $code || $l === strtolower($db) || $l === mb_strtolower($own)) return [$db, ''];
		}
		return null;
	}

	/**
	 * User import: only new logins (existing ones are skipped and listed), the checks of the user form, passwords hashed with "change at
	 * next login"; rows without password get a random one and – option "welcome" – the welcome e-mail with the link to set one.
	 */
	private static function runUsers(array $read, array $mapIn, array $opt, bool $dryRun): array
	{
		$cols = self::userColumns();
		$map = self::mapping($mapIn, $cols, count(self::preview($read)['headers']));
		if (!$map) throw new RuntimeException(t('imp_err_nomap'));
		foreach (['sus_login_name', 'access_level'] as $c) if (!isset($map[$c])) throw new RuntimeException(t('imp_err_unmapped', $c));
		if (!isset($map['sus_name']) && !isset($map['sus_first_name']) && !isset($map['sus_last_name'])) throw new RuntimeException(t('imp_err_unmapped', 'sus_name'));
		$welcome = $opt['welcome'] && NuMail::configured();

		$db = nu_db();
		$levels = array_change_key_case(self::userLevels(), CASE_LOWER);
		$allLevels = [];
		foreach ($db->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access') as $a) {
			$allLevels[strtolower((string)$a['sal_code'])] = $allLevels[strtolower((string)$a['zzzzsys_access_id'])] = (string)$a['zzzzsys_access_id'];
		}
		$existing = [];
		foreach ($db->all('SELECT sus_login_name FROM zzzzsys_user') as $u) $existing[mb_strtolower((string)$u['sus_login_name'])] = true;
		$ga = mb_strtolower((string)nu_cfg('nuConfigDBGlobeadminUsername'));
		$min = max(1, (int)nu_cfg('nuConfigPasswordMinLength'));
		$opt['empty'] = 'null';

		$res = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'rows' => 0, 'errors' => [], 'rolled_back' => false,
			'skipped' => 0, 'skipped_logins' => [], 'welcome' => 0, 'welcome_failed' => 0, 'random' => 0];
		$addError = static function (int $line, string $col, string $value, string $msg) use (&$res): void {
			if (count($res['errors']) < self::MAX_ERRORS) $res['errors'][] = ['line' => $line, 'column' => $col, 'value' => mb_strimwidth($value, 0, 80, '…'), 'message' => $msg];
		};
		$seen = []; $ufSeen = []; $codeSeen = [];                   // logins / unique custom field values / user codes of this file
		$created = [];                                               // [id, login, row, password given, welcome]

		// 1. check every row (nothing is written: zzzzsys_user is often MyISAM, where a transaction cannot be rolled back)
		$rows = [];
		$first = true;
		foreach (self::rows($read) as [$line, $r]) {
			if ($first && $read['header']) { $first = false; continue; }
			$first = false;
			$res['rows']++;
			$v = []; $bad = false; $code = ''; $pw = ''; $lang = ['', '']; $ufv = [];
			foreach ($map as $col => $m) {
				$raw = $m['src'] === 'csv' ? ($r[$m['idx']] ?? '') : $m['value'];
				if ($col === 'password') { $v[$col] = $raw; continue; }            // as typed (no trimming)
				[$val, $e] = self::convert($raw, $cols[$col], $opt, $m['fmt'] ?? '');
				if ($e !== null) { $addError($line, $col, $raw, $e); $bad = true; continue; }
				$v[$col] = $val === self::SKIP || $val === null ? '' : (string)$val;
			}
			$login = (string)($v['sus_login_name'] ?? '');
			$lk = mb_strtolower($login);
			if (!$bad) {
				if ($login === '') { $addError($line, 'sus_login_name', '', t('err_required')); $bad = true; }
				elseif ($lk === $ga) { $addError($line, 'sus_login_name', $login, t('user_err_globeadmin_name')); $bad = true; }
				elseif (isset($existing[$lk])) {                              // already there: skipped, listed
					$res['skipped']++;
					if (count($res['skipped_logins']) < self::MAX_ERRORS) $res['skipped_logins'][] = ['line' => $line, 'login' => $login];
					continue;
				}
				elseif (isset($seen[$lk])) { $addError($line, 'sus_login_name', $login, t('imp_err_dupkey', $seen[$lk])); $bad = true; }
				else $seen[$lk] = $line;
			}
			$accCode = '';
			if (!$bad) {
				if (($v['sus_name'] ?? '') === '') $v['sus_name'] = mb_substr(trim(($v['sus_first_name'] ?? '') . ' ' . ($v['sus_last_name'] ?? '')), 0, 50);
				if ($v['sus_name'] === '') { $addError($line, 'sus_name', '', t('err_required')); $bad = true; }
				$uc = trim((string)($v['sus_code'] ?? ''));                       // user code: unique (0.8.626), also within the file
				if ($uc !== '') {
					$ucK = mb_strtolower($uc);
					if (($owner = NuAuth::userCodeOwner($uc)) !== null) { $addError($line, 'sus_code', $uc, t('user_err_code_taken', $owner['name'])); $bad = true; }
					elseif (isset($codeSeen[$ucK])) { $addError($line, 'sus_code', $uc, t('imp_err_dupkey', $codeSeen[$ucK])); $bad = true; }
					else $codeSeen[$ucK] = $line;
				}
				$email = (string)($v['sus_email'] ?? '');
				if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $addError($line, 'sus_email', $email, t('user_err_email')); $bad = true; }
				$accCode = mb_strtolower((string)($v['access_level'] ?? ''));
				if ($accCode === '') { $addError($line, 'access_level', '', t('err_required')); $bad = true; }
				elseif (!isset($allLevels[$accCode])) { $addError($line, 'access_level', (string)$v['access_level'], t('imp_u_err_access')); $bad = true; }
				elseif (!isset($levels[$accCode]) && !in_array($allLevels[$accCode], $levels, true)) { $addError($line, 'access_level', (string)$v['access_level'], t('secchk_err_admin_level')); $bad = true; }
				$lang = ['', ''];
				if (($v['sus_language'] ?? '') !== '') {
					$lang = self::userLanguage($v['sus_language']);
					if (!$lang) { $addError($line, 'sus_language', $v['sus_language'], t('imp_u_err_lang')); $bad = true; $lang = ['', '']; }
				}
				$pw = (string)($v['password'] ?? '');
				if ($pw !== '') {
					if (mb_strlen($pw) < $min) { $addError($line, 'password', '***', t('pw_err_min', $min)); $bad = true; }
					elseif (strlen($pw) > 72) { $addError($line, 'password', '***', t('err_too_long', 72)); $bad = true; }
				} elseif ($welcome && $email === '') { $addError($line, 'sus_email', '', t('welcome_err_email')); $bad = true; }
				// custom user fields (0.8.454): checked like in the user form; unique also within the file
				$ufv = [];
				foreach (NuUserFields::editable('import') as $code => $f) {
					if (!isset($cols['uf_' . $code]) || !array_key_exists('uf_' . $code, $v)) continue;
					$x = (string)$v['uf_' . $code];
					$err = NuUserFields::checkValue($f, $x, '');
					if ($err === null && $x !== '' && (string)$f['is_unique'] === '1') {
						$k = $code . "\x1f" . mb_strtolower($x);
						if (isset($ufSeen[$k])) $err = t('imp_err_dupkey', $ufSeen[$k]); else $ufSeen[$k] = $line;
					}
					if ($err !== null) { $addError($line, 'uf_' . $code, $x, $err); $bad = true; continue; }
					if ($x !== '') $ufv[$code] = $x;
				}
			}
			if ($bad) {
				$res['failed']++;
				continue;
			}
			$id = nu_id();
			$row = ['zzzzsys_user_id' => $id, 'sus_zzzzsys_access_id' => $allLevels[$accCode]];
			foreach (['sus_name', 'sus_first_name', 'sus_last_name', 'sus_email', 'sus_code', 'sus_position', 'sus_department', 'sus_team', 'sus_additional1', 'sus_additional2'] as $c) {
				$row[$c] = ($v[$c] ?? '') === '' ? null : $v[$c];
			}
			$row['sus_language'] = $lang[0] === '' ? null : $lang[0];
			$row['sus_login_name'] = $login;
			$row['sus_expires_on'] = ($v['sus_expires_on'] ?? '') === '' ? null : $db->datetime($v['sus_expires_on']);
			$row['sus_change_password'] = '1';                          // own password at the first login
			$row['sus_accessibility_features'] = '0';
			$sendWelcome = $pw === '' && $welcome;
			$rows[] = [$row, $pw, $sendWelcome, $lang[1], $line, $ufv];
		}

		// 2. write – with "stop on error" only when every row is fine
		if ($dryRun || ($opt['on_error'] === 'stop' && $res['failed'])) {
			$res['rolled_back'] = !$dryRun;
			foreach ($rows as [, $pw, $sendWelcome]) {
				if ($dryRun) { $res['inserted']++; if ($sendWelcome) $res['welcome']++; elseif ($pw === '') $res['random']++; }
			}
			return $res;
		}
		$created = [];
		foreach ($rows as [$row, $pw, $sendWelcome, $variant, $line, $ufv]) {
			$row['sus_login_password'] = NuAuth::hash($pw !== '' ? $pw : bin2hex(random_bytes(24)));   // random: unknown to anybody
			try {
				$db->insert('zzzzsys_user', $row);
			} catch (PDOException $e) {
				$addError($line, '', '', self::dbMessage($e));
				$res['failed']++;
				continue;
			}
			$res['inserted']++;
			if ($sendWelcome) $res['welcome']++; elseif ($pw === '') $res['random']++;
			$created[] = [(string)$row['zzzzsys_user_id'], $row, $pw !== '', $sendWelcome, $variant, $line, $ufv];
		}

		// then: language variant, password history, log, welcome e-mails
		foreach ($created as [$id, $row, $hasPw, $sendWelcome, $variant, $line, $ufv]) {
			if ($variant !== '') NuUserJson::set($id, 'NUNEXT_LANG_VARIANT', $variant);
			if ($hasPw) NuUserJson::passwordChanged($id, 'admin');
			$ufv += array_filter(array_diff_key(NuUserFields::defaults(), $ufv), static fn($d) => $d !== '');   // defaults for the fields left out
			[$ufOk] = NuUserFields::validate($ufv, $id, 'import');
			$ufDiff = NuUserFields::save($id, $ufOk);
			NuLog::write('user_created', 'zzzzsys_user', $id, ['source' => 'csv_import', 'fields' => NuLog::diff([], array_diff_key($row, ['sus_login_password' => 1])) + $ufDiff]);
			if ($sendWelcome) {
				$err = NuWelcome::send($row);
				if ($err !== null) {
					$res['welcome']--; $res['welcome_failed']++;
					$addError($line, 'sus_email', (string)$row['sus_email'], t('welcome_failed') . ' ' . $err);
				}
			}
		}
		return $res;
	}

	/** Short database error without the SQL statement. */
	private static function dbMessage(PDOException $e): string
	{
		$m = (string)($e->errorInfo[2] ?? $e->getMessage());
		return mb_strimwidth(preg_replace('/\s+/', ' ', $m), 0, 200, '…');
	}
}
