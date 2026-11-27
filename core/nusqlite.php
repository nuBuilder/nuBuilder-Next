<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * SQLite as the database of an installation. nuBuilder Next and nuBuilder Forte write their SQL for MySQL; this class lets that SQL
 * run on SQLite:
 *  - setup(): the connection – WAL journal, a busy timeout, foreign keys off (as MyISAM), and MySQL's functions (NOW, DATE_FORMAT,
 *    CONCAT with NULL, IF, LEFT, DATE_ADD …) added to SQLite as PHP functions;
 *  - prepare(): every statement before it runs – MySQL's spellings that SQLite does not know (INSERT IGNORE, ON DUPLICATE KEY UPDATE,
 *    INTERVAL, GROUP_CONCAT … SEPARATOR, CAST … AS DATE, information_schema, SHOW …, DESCRIBE …) become SQLite's, and MySQL's table
 *    statements (CREATE TABLE with KEY lines and table options, ALTER TABLE … MODIFY / ADD PRIMARY KEY …) are carried out –
 *    SQLite cannot change a column, so the table is rebuilt (rebuild());
 *  - fromDump(): a statement of a MySQL dump (nubuilder4.sql, a backup) – strings with backslash escapes, hex literals, SET / LOCK
 *    statements – made fit for prepare().
 * Text columns of translated tables compare without case (COLLATE NOCASE), as MySQL's _ci collations do.
 */
final class NuSqlite
{
	// ------------------------------------------------------------------ connection

	public static function setup(PDO $pdo): void
	{
		foreach (['PRAGMA journal_mode = WAL', 'PRAGMA busy_timeout = 15000', 'PRAGMA synchronous = NORMAL', 'PRAGMA foreign_keys = OFF'] as $p) {
			try { $pdo->exec($p); } catch (Throwable $ig) { nu_ignored($ig, 'sqlite pragma'); }   // :memory: has no WAL – expected there
		}
		$add = class_exists('Pdo\\Sqlite') && $pdo instanceof \Pdo\Sqlite
			? static fn(string $n, callable $f, int $a) => $pdo->createFunction($n, $f, $a)
			: static fn(string $n, callable $f, int $a) => $pdo->sqliteCreateFunction($n, $f, $a);
		foreach (self::functions($pdo) as $name => [$fn, $args]) $add($name, $fn, $args);
		// MySQL's _ci collations: case AND accents do not count ('é' = 'E') – SQLite's own NOCASE knows only A–Z, its LIKE too
		$coll = class_exists('Pdo\\Sqlite') && $pdo instanceof \Pdo\Sqlite
			? static fn(string $n, callable $f) => $pdo->createCollation($n, $f)
			: static fn(string $n, callable $f) => $pdo->sqliteCreateCollation($n, $f);
		$coll('NOCASE', static fn($a, $b) => strcmp(self::fold((string)$a), self::fold((string)$b)));
		$like = static function ($pattern, $value, $escape = null) {
			if ($pattern === null || $value === null) return null;
			return preg_match(self::likeRegex((string)$pattern, $escape === null ? null : (string)$escape), self::fold((string)$value)) === 1 ? 1 : 0;
		};
		$add('like', $like, 2);
		$add('like', $like, 3);
	}

	/** Text as MySQL's _ci collations compare it: lower case, without accents (é → e, ß → ss). */
	public static function fold(string $s): string
	{
		static $memo = [];
		if (isset($memo[$s])) return $memo[$s];
		if (count($memo) > 5000) $memo = [];
		$f = mb_strtolower($s);
		if (!preg_match('/[\x80-\xff]/', $f)) return $memo[$s] = $f;
		$f = strtr($f, ['ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'đ' => 'd', 'ł' => 'l', 'ı' => 'i']);
		if (class_exists('Normalizer')) $f = (string)preg_replace('/\p{Mn}+/u', '', (string)Normalizer::normalize($f, Normalizer::FORM_D));
		return $memo[$s] = $f;
	}

	/** A LIKE pattern as a regular expression on fold()ed text. */
	private static function likeRegex(string $pattern, ?string $escape): string
	{
		static $memo = [];
		$k = $pattern . "\0" . $escape;
		if (isset($memo[$k])) return $memo[$k];
		$p = self::fold($pattern);
		$e = $escape !== null && $escape !== '' ? mb_substr($escape, 0, 1) : ($escape === null ? '\\' : '');
		$out = '';
		$chars = mb_str_split($p);
		for ($i = 0, $n = count($chars); $i < $n; $i++) {
			$c = $chars[$i];
			if ($e !== '' && $c === $e && $i + 1 < $n) { $out .= preg_quote($chars[++$i], '~'); continue; }
			$out .= $c === '%' ? '.*' : ($c === '_' ? '.' : preg_quote($c, '~'));
		}
		if (count($memo) > 2000) $memo = [];
		return $memo[$k] = '~^' . $out . '$~su';
	}

	/** Has the database file nuBuilder Forte's tables? @return list<string> tables */
	public static function tables(NuDB $db): array
	{
		return array_column($db->all("SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!'"), 't');
	}

	// ------------------------------------------------------------------ MySQL functions

	/** @return array<string, array{0: callable, 1: int}> */
	private static function functions(PDO $pdo): array
	{
		$d = self::class;
		$now = static fn() => date('Y-m-d H:i:s');
		$part = static fn(string $fmt) => static function ($v) use ($fmt) { $t = self::ts($v); return $t === null ? null : (int)date($fmt, $t); };
		return [
			'now' => [$now, -1], 'sysdate' => [$now, -1], 'localtime' => [$now, -1], 'localtimestamp' => [$now, -1],
			'curdate' => [static fn() => date('Y-m-d'), 0], 'curtime' => [static fn() => date('H:i:s'), -1],
			'utc_timestamp' => [static fn() => gmdate('Y-m-d H:i:s'), -1], 'utc_date' => [static fn() => gmdate('Y-m-d'), 0],
			'unix_timestamp' => [static fn($v = null) => $v === null ? time() : self::ts($v), -1],
			'from_unixtime' => [static fn($v, $f = null) => $v === null ? null : ($f === null ? date('Y-m-d H:i:s', (int)$v) : self::dateFormat(date('Y-m-d H:i:s', (int)$v), (string)$f)), -1],
			'date_format' => [static fn($v, $f) => self::dateFormat($v, (string)$f), 2],
			'time_format' => [static fn($v, $f) => self::dateFormat($v === null ? null : '2000-01-01 ' . $v, (string)$f), 2],
			'str_to_date' => [static fn($v, $f) => self::strToDate($v, (string)$f), 2],
			'year' => [$part('Y'), 1], 'month' => [$part('n'), 1], 'day' => [$part('j'), 1], 'dayofmonth' => [$part('j'), 1],
			'hour' => [$part('G'), 1], 'minute' => [static function ($v) { $t = self::ts($v); return $t === null ? null : (int)date('i', $t); }, 1],
			'second' => [static function ($v) { $t = self::ts($v); return $t === null ? null : (int)date('s', $t); }, 1],
			'weekday' => [static function ($v) { $t = self::ts($v); return $t === null ? null : ((int)date('N', $t)) - 1; }, 1],
			'dayofweek' => [static function ($v) { $t = self::ts($v); return $t === null ? null : ((int)date('w', $t)) + 1; }, 1],
			'dayofyear' => [static function ($v) { $t = self::ts($v); return $t === null ? null : ((int)date('z', $t)) + 1; }, 1],
			'week' => [static function ($v, $m = 0) { $t = self::ts($v); return $t === null ? null : (int)date('W', $t); }, -1],
			'weekofyear' => [$part('W'), 1], 'quarter' => [static function ($v) { $t = self::ts($v); return $t === null ? null : intdiv((int)date('n', $t) - 1, 3) + 1; }, 1],
			'last_day' => [static function ($v) { $t = self::ts($v); return $t === null ? null : date('Y-m-t', $t); }, 1],
			'dayname' => [static function ($v) { $t = self::ts($v); return $t === null ? null : date('l', $t); }, 1],
			'monthname' => [static function ($v) { $t = self::ts($v); return $t === null ? null : date('F', $t); }, 1],
			'to_days' => [static function ($v) { $t = self::ts($v); return $t === null ? null : intdiv($t + (int)date('Z', $t), 86400) + 719528; }, 1],
			'datediff' => [static function ($a, $b) { $x = self::ts($a); $y = self::ts($b); if ($x === null || $y === null) return null;
				return (int)round((strtotime(date('Y-m-d', $x) . ' 12:00') - strtotime(date('Y-m-d', $y) . ' 12:00')) / 86400); }, 2],
			'date_add' => [static fn($v, $n, $u = 'DAY') => self::dateAdd($v, $n, (string)$u, 1), -1],
			'adddate' => [static fn($v, $n, $u = 'DAY') => self::dateAdd($v, $n, (string)$u, 1), -1],
			'date_sub' => [static fn($v, $n, $u = 'DAY') => self::dateAdd($v, $n, (string)$u, -1), -1],
			'subdate' => [static fn($v, $n, $u = 'DAY') => self::dateAdd($v, $n, (string)$u, -1), -1],
			'timestampadd' => [static fn($u, $n, $v) => self::dateAdd($v, $n, (string)$u, 1, true), 3],
			'timestampdiff' => [static fn($u, $a, $b) => self::dateDiff((string)$u, $a, $b), 3],
			'nu_extract' => [static function ($u, $v) { $t = self::ts($v); if ($t === null) return null;
				return match (strtoupper((string)$u)) { 'YEAR' => (int)date('Y', $t), 'MONTH' => (int)date('n', $t), 'DAY' => (int)date('j', $t),
					'HOUR' => (int)date('G', $t), 'MINUTE' => (int)date('i', $t), 'SECOND' => (int)date('s', $t), 'WEEK' => (int)date('W', $t),
					'QUARTER' => intdiv((int)date('n', $t) - 1, 3) + 1, 'YEAR_MONTH' => (int)date('Ym', $t), default => null }; }, 2],
			'if' => [static fn($c, $a, $b) => self::truthy($c) ? $a : $b, 3],
			'isnull' => [static fn($v) => $v === null ? 1 : 0, 1],
			'concat' => [static function (...$a) { foreach ($a as $v) if ($v === null) return null; return implode('', array_map('strval', $a)); }, -1],
			'left' => [static fn($s, $n) => $s === null || $n === null ? null : mb_substr((string)$s, 0, max(0, (int)$n)), 2],
			'right' => [static fn($s, $n) => $s === null || $n === null ? null : ((int)$n <= 0 ? '' : mb_substr((string)$s, -(int)$n)), 2],
			'lcase' => [static fn($s) => $s === null ? null : mb_strtolower((string)$s), 1], 'ucase' => [static fn($s) => $s === null ? null : mb_strtoupper((string)$s), 1],
			'lower' => [static fn($s) => $s === null ? null : mb_strtolower((string)$s), 1], 'upper' => [static fn($s) => $s === null ? null : mb_strtoupper((string)$s), 1],
			'locate' => [static function ($sub, $s, $pos = 1) { if ($sub === null || $s === null) return null; $p = mb_stripos((string)$s, (string)$sub, max(0, (int)$pos - 1)); return $p === false ? 0 : $p + 1; }, -1],
			'position' => [static function ($sub, $s) { if ($sub === null || $s === null) return null; $p = mb_stripos((string)$s, (string)$sub); return $p === false ? 0 : $p + 1; }, 2],
			'substring_index' => [static function ($s, $delim, $n) { if ($s === null) return null; $s = (string)$s; $delim = (string)$delim; $n = (int)$n;
				if ($delim === '' || $n === 0) return ''; $parts = explode($delim, $s);
				return implode($delim, $n > 0 ? array_slice($parts, 0, $n) : array_slice($parts, $n)); }, 3],
			'lpad' => [static fn($s, $n, $p) => $s === null ? null : self::pad((string)$s, (int)$n, (string)$p, true), 3],
			'rpad' => [static fn($s, $n, $p) => $s === null ? null : self::pad((string)$s, (int)$n, (string)$p, false), 3],
			'repeat' => [static fn($s, $n) => $s === null ? null : str_repeat((string)$s, max(0, (int)$n)), 2],
			'reverse' => [static fn($s) => $s === null ? null : implode('', array_reverse(mb_str_split((string)$s))), 1],
			'space' => [static fn($n) => str_repeat(' ', max(0, (int)$n)), 1],
			'field' => [static function ($v, ...$list) { if ($v === null) return 0; foreach ($list as $i => $x) if ($x !== null && strcasecmp((string)$x, (string)$v) === 0) return $i + 1; return 0; }, -1],
			'find_in_set' => [static function ($v, $set) { if ($v === null || $set === null) return null; $i = array_search(strtolower((string)$v), array_map('strtolower', explode(',', (string)$set)), true); return $i === false ? 0 : $i + 1; }, 2],
			'elt' => [static fn($n, ...$list) => $list[(int)$n - 1] ?? null, -1],
			'char_length' => [static fn($s) => $s === null ? null : mb_strlen((string)$s), 1], 'character_length' => [static fn($s) => $s === null ? null : mb_strlen((string)$s), 1],
			'md5' => [static fn($s) => $s === null ? null : md5((string)$s), 1], 'sha1' => [static fn($s) => $s === null ? null : sha1((string)$s), 1],
			'sha' => [static fn($s) => $s === null ? null : sha1((string)$s), 1],
			'sha2' => [static fn($s, $n = 256) => $s === null ? null : hash(in_array((int)$n, [224, 384, 512], true) ? 'sha' . (int)$n : 'sha256', (string)$s), -1],
			'uuid' => [static fn() => nu_uuid(), 0],
			'rand' => [static fn($seed = null) => mt_rand() / mt_getrandmax(), -1],
			'floor' => [static fn($v) => $v === null ? null : (int)floor((float)$v), 1], 'ceil' => [static fn($v) => $v === null ? null : (int)ceil((float)$v), 1],
			'ceiling' => [static fn($v) => $v === null ? null : (int)ceil((float)$v), 1],
			'pow' => [static fn($a, $b) => $a === null || $b === null ? null : ((float)$a) ** ((float)$b), 2], 'power' => [static fn($a, $b) => $a === null || $b === null ? null : ((float)$a) ** ((float)$b), 2],
			'sqrt' => [static fn($v) => $v === null || (float)$v < 0 ? null : sqrt((float)$v), 1], 'exp' => [static fn($v) => $v === null ? null : exp((float)$v), 1],
			'ln' => [static fn($v) => $v === null || (float)$v <= 0 ? null : log((float)$v), 1],
			'log' => [static fn($a, $b = null) => $a === null ? null : ($b === null ? ((float)$a > 0 ? log((float)$a) : null) : ((float)$a > 0 && (float)$b > 0 ? log((float)$b, (float)$a) : null)), -1],
			'log10' => [static fn($v) => $v === null || (float)$v <= 0 ? null : log10((float)$v), 1], 'log2' => [static fn($v) => $v === null || (float)$v <= 0 ? null : log((float)$v, 2), 1],
			'mod' => [static fn($a, $b) => $a === null || $b === null || (float)$b == 0 ? null : fmod((float)$a, (float)$b), 2],
			'truncate' => [static function ($v, $d) { if ($v === null) return null; $f = 10 ** (int)$d; $r = ((float)$v >= 0 ? floor((float)$v * $f) : ceil((float)$v * $f)) / $f; return (int)$d <= 0 ? (int)$r : $r; }, 2],
			'format' => [static fn($v, $d = 0) => $v === null ? null : number_format((float)$v, max(0, (int)$d), '.', ','), -1],
			'greatest' => [static function (...$a) { foreach ($a as $v) if ($v === null) return null; return max($a); }, -1],
			'least' => [static function (...$a) { foreach ($a as $v) if ($v === null) return null; return min($a); }, -1],
			'database' => [static fn() => 'main', 0], 'schema' => [static fn() => 'main', 0],
			'version' => [static fn() => '8.0.0-sqlite-' . (string)$pdo->query('SELECT sqlite_version()')->fetchColumn(), 0],
			'user' => [static fn() => 'sqlite@localhost', 0], 'current_user' => [static fn() => 'sqlite@localhost', 0], 'connection_id' => [static fn() => getmypid(), 0],
			'last_insert_id' => [static fn() => (int)$pdo->lastInsertId(), -1],
			'regexp' => [static function ($re, $v) { if ($re === null || $v === null) return null; $p = '~' . str_replace('~', '\\~', (string)$re) . '~iu'; return @preg_match($p, (string)$v) === 1 ? 1 : 0; }, 2],
			// MySQL's JSON_EXTRACT: the value as JSON text ("x" quoted, true / false, MySQL's spacing) – SQLite's own gives SQL values
			'json_extract' => [static fn(...$a) => self::jsonExtract($a), -1],
			'json_unquote' => [static function ($v) { if ($v === null) return null; $j = json_decode((string)$v); return is_string($j) ? $j : (string)$v; }, 1],
			'nu_type_base' => [static fn($t) => strtolower(trim((string)preg_replace('/\s*\(.*$/s', '', (string)$t))) ?: 'text', 1],
			'nu_type_len' => [static fn($t) => preg_match('/\(\s*(\d+)/', (string)$t, $m) ? (int)$m[1] : null, 1],
			'bin_to_uuid' => [static fn($v) => $v, -1], 'uuid_to_bin' => [static fn($v) => $v, -1],
			'inet_aton' => [static fn($v) => $v === null ? null : (ip2long((string)$v) ?: null), 1], 'inet_ntoa' => [static fn($v) => $v === null ? null : long2ip((int)$v), 1],
			'hex' => [static fn($v) => $v === null ? null : strtoupper(is_int($v) ? dechex($v) : bin2hex((string)$v)), 1],
			'unhex' => [static fn($v) => $v === null ? null : (hex2bin((string)$v) ?: null), 1],
			'from_base64' => [static fn($v) => $v === null ? null : base64_decode((string)$v), 1], 'to_base64' => [static fn($v) => $v === null ? null : base64_encode((string)$v), 1],
			'nu_d' => [static fn($v) => $d::ts($v) === null ? null : date('Y-m-d', (int)$d::ts($v)), 1],
		];
	}

	private static function truthy(mixed $v): bool
	{
		if ($v === null) return false;
		if (is_string($v)) return (float)$v != 0;
		return (bool)$v;
	}

	private static function pad(string $s, int $n, string $p, bool $left): string
	{
		$len = mb_strlen($s);
		if ($n <= $len) return mb_substr($s, 0, max(0, $n));
		if ($p === '') return $s;
		$fill = mb_substr(str_repeat($p, (int)ceil(($n - $len) / mb_strlen($p))), 0, $n - $len);
		return $left ? $fill . $s : $s . $fill;
	}

	/** A date / time value as a Unix time (local time zone), null for NULL / '' / zero dates / text that is no date. */
	public static function ts(mixed $v): ?int
	{
		if ($v === null) return null;
		if (is_int($v) || is_float($v)) {
			$s = (string)(int)$v;
			if (strlen($s) === 8) $v = substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);   // 20261127
			elseif (strlen($s) === 14) $v = substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2) . ' ' . substr($s, 8, 2) . ':' . substr($s, 10, 2) . ':' . substr($s, 12, 2);
			else return null;
		}
		$v = trim((string)$v);
		if ($v === '' || str_starts_with($v, '0000-00-00')) return null;
		if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}([ T]\d{1,2}:\d{2}(:\d{2}(\.\d+)?)?)?$|^\d{1,2}:\d{2}(:\d{2})?$/', $v)) return null;
		$t = strtotime(str_replace('T', ' ', $v));
		return $t === false ? null : $t;
	}

	/** MySQL's DATE_FORMAT specifiers. */
	public static function dateFormat(mixed $v, string $f): ?string
	{
		$t = self::ts($v);
		if ($t === null) return null;
		$map = ['Y' => 'Y', 'y' => 'y', 'm' => 'm', 'c' => 'n', 'd' => 'd', 'e' => 'j', 'H' => 'H', 'k' => 'G', 'h' => 'h', 'I' => 'h', 'l' => 'g',
			'i' => 'i', 's' => 's', 'S' => 's', 'p' => 'A', 'M' => 'F', 'b' => 'M', 'W' => 'l', 'a' => 'D', 'T' => 'H:i:s', 'r' => 'h:i:s A',
			'u' => 'W', 'v' => 'W', 'x' => 'o', 'w' => 'w', 'D' => 'jS', 'f' => '000000'];
		$out = '';
		for ($i = 0, $n = strlen($f); $i < $n; $i++) {
			if ($f[$i] !== '%' || $i + 1 >= $n) { $out .= $f[$i]; continue; }
			$c = $f[++$i];
			if ($c === 'j') $out .= str_pad((string)((int)date('z', $t) + 1), 3, '0', STR_PAD_LEFT);
			elseif ($c === 'f') $out .= '000000';
			elseif (isset($map[$c])) $out .= date($map[$c], $t);
			else $out .= $c;
		}
		return $out;
	}

	private static function strToDate(mixed $v, string $f): ?string
	{
		if ($v === null) return null;
		$conv = strtr($f, ['%Y' => 'Y', '%y' => 'y', '%m' => 'm', '%c' => 'n', '%d' => 'd', '%e' => 'j', '%H' => 'H', '%k' => 'G', '%h' => 'h',
			'%i' => 'i', '%s' => 's', '%S' => 's', '%p' => 'A', '%M' => 'F', '%b' => 'M', '%T' => 'H:i:s', '%%' => '%']);
		$d = DateTime::createFromFormat('!' . $conv, trim((string)$v));
		if (!$d) return null;
		return preg_match('/[HGhisAT]/', $conv) ? $d->format('Y-m-d H:i:s') : $d->format('Y-m-d');
	}

	/** DATE_ADD / DATE_SUB: months keep the day or end at the last day of the month, as in MySQL. */
	private static function dateAdd(mixed $v, mixed $n, string $unit, int $sign, bool $full = false): ?string
	{
		$t = self::ts($v);
		if ($t === null || $n === null) return null;
		$dateOnly = !$full && !preg_match('/\d:\d/', (string)$v);
		$unit = strtoupper(trim($unit, " '\""));
		$n = (string)$n;
		$dt = (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone(date_default_timezone_get()));
		$num = static fn(string $s) => (float)$s * $sign;
		$months = static function (DateTimeImmutable $d, int $m): DateTimeImmutable {
			$y = (int)$d->format('Y'); $mo = (int)$d->format('n') + $m; $day = (int)$d->format('j');
			$y += (int)floor(($mo - 1) / 12); $mo = (($mo - 1) % 12 + 12) % 12 + 1;
			$last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $mo)))->format('t');
			return $d->setDate($y, $mo, min($day, $last));
		};
		switch ($unit) {
			case 'MICROSECOND': break;
			case 'SECOND': $dt = $dt->modify(sprintf('%+d seconds', (int)$num($n))); $dateOnly = false; break;
			case 'MINUTE': $dt = $dt->modify(sprintf('%+d minutes', (int)$num($n))); $dateOnly = false; break;
			case 'HOUR': $dt = $dt->modify(sprintf('%+d hours', (int)$num($n))); $dateOnly = false; break;
			case 'DAY': $dt = $dt->modify(sprintf('%+d days', (int)$num($n))); break;
			case 'WEEK': $dt = $dt->modify(sprintf('%+d days', 7 * (int)$num($n))); break;
			case 'MONTH': $dt = $months($dt, (int)$num($n)); break;
			case 'QUARTER': $dt = $months($dt, 3 * (int)$num($n)); break;
			case 'YEAR': $dt = $months($dt, 12 * (int)$num($n)); break;
			case 'YEAR_MONTH':
				[$y, $m] = array_pad(array_map('intval', preg_split('/\D+/', trim($n)) ?: []), 2, 0);
				$dt = $months($dt, $sign * (12 * $y + $m)); break;
			case 'DAY_HOUR': case 'DAY_MINUTE': case 'DAY_SECOND': case 'HOUR_MINUTE': case 'HOUR_SECOND': case 'MINUTE_SECOND':
				$parts = array_map('intval', preg_split('/\D+/', trim($n)) ?: []);
				$units = ['DAY_HOUR' => ['days', 'hours'], 'DAY_MINUTE' => ['days', 'hours', 'minutes'], 'DAY_SECOND' => ['days', 'hours', 'minutes', 'seconds'],
					'HOUR_MINUTE' => ['hours', 'minutes'], 'HOUR_SECOND' => ['hours', 'minutes', 'seconds'], 'MINUTE_SECOND' => ['minutes', 'seconds']][$unit];
				$parts = array_slice(array_pad($parts, -count($units), 0), -count($units));
				foreach ($units as $i => $u) $dt = $dt->modify(sprintf('%+d %s', $sign * $parts[$i], $u));
				$dateOnly = false; break;
			default: return null;
		}
		return $dt->format($dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s');
	}

	private static function dateDiff(string $unit, mixed $a, mixed $b): ?int
	{
		$x = self::ts($a); $y = self::ts($b);
		if ($x === null || $y === null) return null;
		$s = $y - $x;
		$months = static function (int $x, int $y): int {
			$m = ((int)date('Y', $y) - (int)date('Y', $x)) * 12 + (int)date('n', $y) - (int)date('n', $x);
			$rest = date('dHis', $y) <=> date('dHis', $x);
			if ($m > 0 && $rest < 0) $m--; elseif ($m < 0 && $rest > 0) $m++;
			return $m;
		};
		return match (strtoupper(trim($unit, " '\""))) {
			'SECOND' => $s, 'MINUTE' => intdiv($s, 60), 'HOUR' => intdiv($s, 3600), 'DAY' => intdiv($s, 86400), 'WEEK' => intdiv($s, 604800),
			'MONTH' => $months($x, $y), 'QUARTER' => intdiv($months($x, $y), 3), 'YEAR' => intdiv($months($x, $y), 12), default => null,
		};
	}

	/** JSON_EXTRACT(doc, path [, path …]) as MySQL answers it; NULL for a missing path, NULL document or invalid JSON. */
	private static function jsonExtract(array $a): ?string
	{
		$doc = array_shift($a);
		if ($doc === null || !$a) return null;
		try { $v = json_decode((string)$doc, false, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
		$out = [];
		foreach ($a as $path) {
			$p = (string)$path;
			if (!str_starts_with($p, '$')) return null;
			$cur = $v; $ok = true;
			if (!preg_match_all('/\.(?:"((?:[^"\\\\]|\\\\.)*)"|([^.\[]+))|\[(\d+)\]/', substr($p, 1), $m, PREG_SET_ORDER) && $p !== '$') return null;
			foreach ($m as $x) {
				if (isset($x[3])) {                              // [n] (PREG_SET_ORDER leaves the later groups out when unmatched)
					if (!is_array($cur) || !array_key_exists((int)$x[3], $cur)) { $ok = false; break; }
					$cur = $cur[(int)$x[3]];
				} else {
					$k = $x[1] !== '' ? stripcslashes($x[1]) : $x[2];
					if (!is_object($cur) || !property_exists($cur, $k)) { $ok = false; break; }
					$cur = $cur->$k;
				}
			}
			if ($ok) $out[] = $cur;
			elseif (count($a) === 1) return null;
		}
		return self::jsonText(count($a) === 1 ? $out[0] : $out);
	}

	/** A value as MySQL writes JSON: ", " between items, ": " after keys, no escaped slashes or unicode. */
	private static function jsonText(mixed $v): string
	{
		if (is_array($v)) return '[' . implode(', ', array_map([self::class, 'jsonText'], $v)) . ']';
		if (is_object($v)) {
			$p = [];
			foreach (get_object_vars($v) as $k => $x) $p[] = json_encode((string)$k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ': ' . self::jsonText($x);
			return '{' . implode(', ', $p) . '}';
		}
		return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
	}

	// ------------------------------------------------------------------ tokens

	/**
	 * Tokens of a statement: [kind, text] – kind s = string, q = quoted name, c = comment, w = word, n = number, p = punctuation, ' ' = space.
	 * $mysqlStrings: backslash escapes inside strings (a MySQL dump); else only doubled quotes count ('' – SQL as SQLite writes it).
	 * @return list<array{0: string, 1: string}>
	 */
	public static function tokens(string $sql, bool $mysqlStrings = false): array
	{
		$out = [];
		$n = strlen($sql);
		$i = 0;
		while ($i < $n) {
			$c = $sql[$i];
			if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
				$j = $i; while ($j < $n && strpos(" \t\n\r", $sql[$j]) !== false) $j++;
				$out[] = [' ', substr($sql, $i, $j - $i)]; $i = $j; continue;
			}
			if ($c === "'" || $c === '"') {
				$j = $i + 1;
				while ($j < $n) {
					if ($mysqlStrings && $sql[$j] === '\\') { $j += 2; continue; }
					if ($sql[$j] === $c) { if ($j + 1 < $n && $sql[$j + 1] === $c) { $j += 2; continue; } break; }
					$j++;
				}
				$out[] = ['s', substr($sql, $i, min($n, $j + 1) - $i)]; $i = $j + 1; continue;
			}
			if ($c === '`' || $c === '[') {
				$close = $c === '`' ? '`' : ']';
				$j = strpos($sql, $close, $i + 1);
				$j = $j === false ? $n - 1 : $j;
				$out[] = ['q', substr($sql, $i, $j + 1 - $i)]; $i = $j + 1; continue;
			}
			if ($c === '-' && ($sql[$i + 1] ?? '') === '-' && ($i + 2 >= $n || strpos(" \t\r\n", $sql[$i + 2]) !== false)) {
				$j = strpos($sql, "\n", $i); $j = $j === false ? $n : $j;
				$out[] = ['c', substr($sql, $i, $j - $i)]; $i = $j; continue;
			}
			if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
				$j = strpos($sql, '*/', $i + 2); $j = $j === false ? $n : $j + 2;
				$out[] = ['c', substr($sql, $i, $j - $i)]; $i = $j; continue;
			}
			if (ctype_digit($c) || ($c === '.' && ctype_digit($sql[$i + 1] ?? ''))) {
				if ($c === '0' && (($sql[$i + 1] ?? '') === 'x' || ($sql[$i + 1] ?? '') === 'X') && ctype_xdigit($sql[$i + 2] ?? '')) {
					$j = $i + 2; while ($j < $n && ctype_xdigit($sql[$j])) $j++;
					$out[] = ['x', substr($sql, $i, $j - $i)]; $i = $j; continue;
				}
				$j = $i; while ($j < $n && (ctype_digit($sql[$j]) || $sql[$j] === '.' || (($sql[$j] === 'e' || $sql[$j] === 'E') && ($j + 1 < $n) && (ctype_digit($sql[$j + 1]) || $sql[$j + 1] === '-' || $sql[$j + 1] === '+')) || (($sql[$j] === '-' || $sql[$j] === '+') && ($sql[$j - 1] === 'e' || $sql[$j - 1] === 'E')))) $j++;
				$out[] = ['n', substr($sql, $i, $j - $i)]; $i = $j; continue;
			}
			if (ctype_alpha($c) || $c === '_' || $c === '$' || $c === '@' || ord($c) >= 128) {
				$j = $i; while ($j < $n && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$' || $sql[$j] === '@' || ord($sql[$j]) >= 128)) $j++;
				$out[] = ['w', substr($sql, $i, $j - $i)]; $i = $j; continue;
			}
			$two = substr($sql, $i, 3);
			if ($two === '<=>') { $out[] = ['p', '<=>']; $i += 3; continue; }
			$two = substr($sql, $i, 2);
			if (in_array($two, ['<=', '>=', '<>', '!=', '||', '::', ':='], true)) { $out[] = ['p', $two]; $i += 2; continue; }
			$out[] = ['p', $c]; $i++;
		}
		return $out;
	}

	private static function text(array $t): string
	{
		return implode('', array_column($t, 1));
	}

	private static function up(array $tok): string
	{
		return $tok[0] === 'w' ? strtoupper($tok[1]) : '';
	}

	/** Index of the next token that is not a space / comment, from $i on (count($t) when none). */
	private static function next(array $t, int $i): int
	{
		$n = count($t);
		while ($i < $n && ($t[$i][0] === ' ' || $t[$i][0] === 'c')) $i++;
		return $i;
	}

	private static function prev(array $t, int $i): int
	{
		while ($i >= 0 && ($t[$i][0] === ' ' || $t[$i][0] === 'c')) $i--;
		return $i;
	}

	/** Index of the ")" that closes the "(" at $i. */
	private static function close(array $t, int $i): int
	{
		$d = 0;
		for ($n = count($t); $i < $n; $i++) {
			if ($t[$i][0] !== 'p') continue;
			if ($t[$i][1] === '(') $d++;
			elseif ($t[$i][1] === ')' && --$d === 0) return $i;
		}
		return $n - 1;
	}

	/** Words (upper case) of the significant tokens from $i on, at most $max. */
	private static function words(array $t, int $i, int $max): array
	{
		$out = [];
		for ($n = count($t); $i < $n && count($out) < $max; $i++) {
			if ($t[$i][0] === ' ' || $t[$i][0] === 'c') continue;
			$out[] = $t[$i][0] === 'w' ? strtoupper($t[$i][1]) : $t[$i][1];
		}
		return $out;
	}

	private static function name(string $s): string
	{
		$s = trim($s);
		if ($s !== '' && ($s[0] === '`' || $s[0] === '"' || $s[0] === '[')) $s = substr($s, 1, -1);
		return str_replace(['``', '""'], ['`', '"'], $s);
	}

	private static function qn(string $s): string
	{
		return '"' . str_replace('"', '""', $s) . '"';
	}

	// ------------------------------------------------------------------ dumps

	/**
	 * A statement of a MySQL dump for SQLite: hex literals, _charset introducers, b'…' – its strings are read by MySQL's rules in prepare().
	 * '' = a statement SQLite has no use for (SET, LOCK TABLES, transactions of the dump, CREATE DATABASE, USE).
	 */
	public static function fromDump(string $sql): string
	{
		$t = self::tokens($sql, true);
		$first = self::next($t, 0);
		$w = self::words($t, $first, 3);
		$v = $w[0] ?? '';
		if (in_array($v, ['SET', 'LOCK', 'UNLOCK', 'START', 'BEGIN', 'COMMIT', 'ROLLBACK', 'USE', 'DELIMITER', 'FLUSH'], true)) return '';
		if ($v === 'CREATE' && in_array($w[1] ?? '', ['DATABASE', 'SCHEMA'], true)) return '';
		if ($v === '' ) return '';
		if (str_starts_with($v, '/*!')) return '';
		foreach ($t as $k => $tok) {
			if ($tok[0] === 'x') {
				$t[$k][1] = "X'" . substr($tok[1], 2) . "'";
			} elseif ($tok[0] === 'w' && preg_match('/^_(utf8mb4|utf8mb3|utf8|latin1|binary|ascii)$/i', $tok[1])) {
				$j = self::next($t, $k + 1);
				if (($t[$j][0] ?? '') === 's' || ($t[$j][0] ?? '') === 'x') $t[$k][1] = '';
			} elseif ($tok[0] === 'w' && strtolower($tok[1]) === 'b' && ($t[$k + 1][0] ?? '') === 's') {   // b'0101'
				$bits = trim((string)$t[$k + 1][1], "'");
				$t[$k][1] = (string)bindec($bits); $t[$k + 1] = [' ', ''];
			}
		}
		return self::text($t);
	}

	/**
	 * CREATE TABLE / VIEW of a table as MySQL writes it (SHOW CREATE TABLE): the declared MySQL types, NOT NULL, defaults, the key,
	 * AUTO_INCREMENT, the indexes as KEY lines – for packages that may go to a MySQL installation.
	 */
	public static function mysqlCreate(NuDB $db, string $table): string
	{
		$m = $db->one("SELECT type, sql FROM sqlite_master WHERE name = ? AND type IN ('table', 'view')", [$table]);
		if (!$m) return '';
		$q = static fn(string $n) => '`' . str_replace('`', '``', $n) . '`';
		if ($m['type'] === 'view') return 'CREATE VIEW ' . $q($table) . ' ' . self::backticks((string)preg_replace('/^CREATE\s+VIEW\s+("(?:[^"]|"")*"|`[^`]*`|\S+)\s*/i', '', (string)$m['sql']));
		$auto = stripos((string)$m['sql'], 'AUTOINCREMENT') !== false;
		$lines = []; $pk = [];
		foreach ($db->all('SELECT * FROM pragma_table_info(?) ORDER BY cid', [$table]) as $c) {
			$type = (string)$c['type'] !== '' ? strtolower((string)$c['type']) : 'text';
			$isAuto = $auto && (int)$c['pk'] === 1 && strtoupper((string)$c['type']) === 'INTEGER';
			if ($isAuto) $type = 'int';
			$type = match ($type) { 'integer' => 'int', 'real' => 'double', 'blob' => 'longblob', default => $type };
			$d = $q((string)$c['name']) . ' ' . $type . ((int)$c['notnull'] === 1 || $isAuto ? ' NOT NULL' : '');
			if ($c['dflt_value'] !== null) $d .= ' DEFAULT ' . trim((string)$c['dflt_value'], '()');
			if ($isAuto) $d .= ' AUTO_INCREMENT';
			$lines[] = '  ' . $d;
			if ((int)$c['pk'] > 0) $pk[(int)$c['pk']] = $q((string)$c['name']);
		}
		ksort($pk);
		if ($pk) $lines[] = '  PRIMARY KEY (' . implode(', ', $pk) . ')';
		foreach ($db->all("SELECT il.name AS n, il.`unique` AS u FROM pragma_index_list(?) il WHERE il.origin = 'c'", [$table]) as $ix) {
			$cols = array_map(static fn($r) => $q((string)$r['name']), $db->all('SELECT name FROM pragma_index_info(?) ORDER BY seqno', [(string)$ix['n']]));
			if (!$cols) continue;
			$short = (string)preg_replace('/^' . preg_quote($table . '__', '/') . '/', '', (string)$ix['n']);
			$lines[] = '  ' . ((int)$ix['u'] ? 'UNIQUE ' : '') . 'KEY ' . $q($short) . ' (' . implode(', ', $cols) . ')';
		}
		return 'CREATE TABLE ' . $q($table) . " (\n" . implode(",\n", $lines) . "\n)";
	}

	/** SQL as SQLite stores it (sqlite_master) with "names" as `names` – how MySQL and the translation here read a name. */
	public static function backticks(string $sql): string
	{
		$t = self::tokens($sql);
		foreach ($t as $k => $tok) {
			if ($tok[0] === 's' && $tok[1][0] === '"') $t[$k][1] = '`' . str_replace(['""', '`'], ['"', '``'], substr($tok[1], 1, -1)) . '`';
		}
		return self::text($t);
	}

	/** The value of a MySQL string literal ('…' or "…" with backslash escapes). */
	private static function unescape(string $lit): string
	{
		$q = $lit[0];
		$s = substr($lit, 1, -1);
		$s = str_replace($q . $q, $q, $s);
		if (!str_contains($s, '\\')) return $s;
		return (string)preg_replace_callback('/\\\\(.)/s', static function ($m) {
			return match ($m[1]) { '0' => '', 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1A", '%' => '\\%', '_' => '\\_', default => $m[1] };
		}, $s);
	}

	private static function lit(string $v): string
	{
		return "'" . str_replace("'", "''", $v) . "'";
	}

	// ------------------------------------------------------------------ statements at run time

	/** A quick look: does the statement have anything prepare() would change? */
	private const HINT = '/information_schema|\bINSERT\s+IGNORE\b|\bSEPARATOR\b|\bINTERVAL\b|\bTIMESTAMP(DIFF|ADD)\b|\bEXTRACT\s*\(|\bCAST\s*\(|\bCONVERT\s*\('
		. '|\bCOLLATE\b|\bDUPLICATE\b|\bFOR\s+UPDATE\b|\bLOCK\s+IN\b|\bSQL_CALC_FOUND_ROWS\b|\bSTRAIGHT_JOIN\b|\b(USE|FORCE|IGNORE)\s+(INDEX|KEY)\b'
		. '|\b0x[0-9A-Fa-f]|\bGROUP_CONCAT\s*\((\s*DISTINCT\b|(?:[^()]|\([^()]*\))*,)|\bDUAL\b|\bJSON_TABLE\b|\bJSON_(ARRAY|OBJECT)AGG\b|\bDELETE\s+(?!FROM\b|IGNORE\b|LOW_PRIORITY\b|QUICK\b)\w|<=>|\bDIV\b|\bRLIKE\b|\bCURRENT_(TIMESTAMP|DATE|TIME)\b|\bHIGH_PRIORITY\b|\bDELAYED\b|\bLOW_PRIORITY\b/i';

	/** Statements carried out here (DDL, SHOW …) instead of being handed to SQLite as they are. */
	private const VERBS = ['CREATE', 'ALTER', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'TRUNCATE', 'RENAME', 'START', 'USE', 'OPTIMIZE', 'ANALYZE',
		'REPAIR', 'CHECK', 'DROP', 'LOCK', 'UNLOCK', 'SET', 'FLUSH', 'GRANT', 'REVOKE', 'KILL', 'CALL', 'DO'];

	/** @var callable(string): void|null runs one SQLite statement (set by NuDB) */
	private static $raw = null;

	/**
	 * The SQLite form of one statement. DDL that SQLite cannot do as one statement (a table rebuilt) is carried out here through $raw;
	 * then the answer is a statement that does nothing.
	 */
	public static function prepare(NuDB $db, string $sql, callable $raw): string
	{
		$first = (string)preg_replace('~^(?:\s+|--[^\n]*(?:\n|$)|/\*(?!!).*?\*/)*~s', '', $sql);   // comments in front (dumps)
		if (preg_match('/^(?:\(\s*)*([A-Za-z]+)/', $first, $m) !== 1) return $sql;
		$verb = strtoupper($m[1]);
		if (strpbrk($sql, "\"\\") !== false) $sql = self::strings($sql);     // "…" strings or backslash escapes: MySQL's string rules
		if ($verb === 'SELECT' || $verb === 'INSERT' || $verb === 'UPDATE' || $verb === 'DELETE' || $verb === 'REPLACE' || $verb === 'WITH' || $verb === 'VALUES') {
			return preg_match(self::HINT, $sql) === 1 ? self::dml(self::tokens($sql)) : $sql;
		}
		if (!in_array($verb, self::VERBS, true)) return $sql;
		self::$raw = $raw;
		try {
			return self::special($db, self::tokens($sql), $verb) ?? $sql;
		} finally {
			self::$raw = null;
		}
	}

	/** The string literals of a statement read by MySQL's rules ("…" = a string, backslash escapes) and written as SQLite's. */
	public static function strings(string $sql): string
	{
		$t = self::tokens($sql, true);
		foreach ($t as $k => $tok) {
			if ($tok[0] === 's' && ($tok[1][0] === '"' || str_contains($tok[1], '\\'))) $t[$k][1] = self::lit(self::unescape($tok[1]));
		}
		return self::text($t);
	}

	private const NOTHING = 'SELECT 0 WHERE 0';

	private static function run(string $sql): void
	{
		(self::$raw)($sql);
	}

	/**
	 * MySQL's multi-table DELETE (DELETE a FROM t a JOIN … WHERE …) as SQLite understands it: the rows of the first named table
	 * whose rowid the join finds. Only the first target table is deleted from (MySQL can name several).
	 */
	private static function multiDelete(array $t): array
	{
		$d = self::next($t, 0);
		if (self::up($t[$d] ?? ['', '']) !== 'DELETE') return $t;
		$a = self::next($t, $d + 1);
		if (!isset($t[$a]) || in_array(self::up($t[$a]), ['FROM', 'IGNORE', 'LOW_PRIORITY', 'QUICK'], true)) return $t;
		$alias = self::name($t[$a][1]);
		$f = $a;
		while (isset($t[$f]) && self::up($t[$f]) !== 'FROM') $f++;
		if (!isset($t[$f])) return $t;
		// the real table behind the alias: the word in front of it after FROM / JOIN, else the alias names a table itself
		$table = $alias;
		$rest = array_slice($t, $f + 1);
		$words = array_values(array_filter($rest, static fn($x) => $x[0] !== ' ' && $x[0] !== 'c'));
		for ($k = 1; $k < count($words); $k++) {
			$w = $words[$k];
			if (($w[0] === 'w' || $w[0] === 'q') && strcasecmp(self::name($w[1]), $alias) === 0 && in_array($words[$k - 1][0], ['w', 'q'], true)) {
				$prev = $words[$k - 1];
				if ($prev[0] === 'w' && strtoupper($prev[1]) === 'AS') $prev = $words[$k - 2] ?? $prev;
				$table = self::name($prev[1]);
				break;
			}
		}
		// ORDER BY / LIMIT of a multi-table DELETE are not allowed in MySQL either – the rest is FROM … WHERE …
		$sql = 'DELETE FROM ' . self::qn($table) . ' WHERE rowid IN (SELECT ' . self::qn($alias) . '.rowid FROM ' . self::text($rest) . ')';
		return self::tokens($sql);
	}

	/** SELECT / INSERT / UPDATE / DELETE with MySQL's spellings. */
	public static function dml(array $t): string
	{
		$t = self::multiDelete($t);
		$n = count($t);
		$dup = false;
		for ($i = 0; $i < $n; $i++) {
			$tok = $t[$i];
			if ($tok[0] === 'p' && $tok[1] === '<=>') { $t[$i][1] = ' IS '; continue; }
			if ($tok[0] === 'x') { $h = substr($tok[1], 2); $t[$i][1] = "X'" . (strlen($h) % 2 ? '0' : '') . $h . "'"; continue; }   // 0x2f → X'2f'
			if ($tok[0] !== 'w') continue;
			$u = strtoupper($tok[1]);
			switch ($u) {
				case 'INFORMATION_SCHEMA':
					$j = self::next($t, $i + 1);
					if (($t[$j][1] ?? '') !== '.') break;
					$k = self::next($t, $j + 1);
					if (!isset($t[$k])) break;
					$view = strtoupper(self::name($t[$k][1]));
					$sub = self::infoSchema($view);
					if ($sub === null) break;
					$after = self::next($t, $k + 1);
					$alias = isset($t[$after]) && ($t[$after][0] === 'q' || ($t[$after][0] === 'w' && !in_array(strtoupper($t[$after][1]), self::CLAUSE, true)));
					$t[$i][1] = '(' . $sub . ')' . ($alias ? '' : ' AS ' . self::qn($view));
					for ($x = $i + 1; $x <= $k; $x++) $t[$x][1] = '';
					$i = $k;
					break;
				case 'IGNORE':
					$p = self::prev($t, $i - 1);
					if ($p >= 0 && self::up($t[$p]) === 'INSERT') $t[$i][1] = 'OR IGNORE';
					elseif ($p >= 0 && in_array(self::up($t[$p]), ['UPDATE', 'DELETE'], true)) $t[$i][1] = '';
					elseif ($p >= 0 && in_array(self::up($t[$p]), ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY'], true)) $t[$i][1] = 'OR IGNORE';
					else self::dropIndexHint($t, $i);
					break;
				case 'LOW_PRIORITY': case 'DELAYED': case 'HIGH_PRIORITY': case 'SQL_CALC_FOUND_ROWS': case 'SQL_NO_CACHE': case 'SQL_CACHE': case 'SQL_BUFFER_RESULT':
					$t[$i][1] = ''; break;
				case 'STRAIGHT_JOIN': $t[$i][1] = 'JOIN'; break;
				case 'RLIKE': $t[$i][1] = 'REGEXP'; break;
				case 'DIV': $t[$i][1] = '/'; break;
				case 'JSON_ARRAYAGG': $t[$i][1] = 'json_group_array'; break;
				case 'JSON_OBJECTAGG': $t[$i][1] = 'json_group_object'; break;
				case 'DUAL':
					$p = self::prev($t, $i - 1);
					if ($p >= 0 && self::up($t[$p]) === 'FROM') { $t[$p][1] = ''; $t[$i][1] = ''; }
					break;
				case 'USE': case 'FORCE':
					self::dropIndexHint($t, $i); break;
				case 'FOR':
					$j = self::next($t, $i + 1);
					if (self::up($t[$j] ?? ['', '']) === 'UPDATE' || self::up($t[$j] ?? ['', '']) === 'SHARE') {
						$t[$i][1] = ''; $t[$j][1] = '';
						foreach (['NOWAIT', 'SKIP', 'LOCKED'] as $_) { $k = self::next($t, $j + 1); if (in_array(self::up($t[$k] ?? ['', '']), ['NOWAIT', 'SKIP', 'LOCKED'], true)) { $t[$k][1] = ''; $j = $k; } }
					}
					break;
				case 'LOCK':
					$w = self::words($t, $i, 4);
					if (($w[1] ?? '') === 'IN' && ($w[2] ?? '') === 'SHARE') { $c = 0; for ($x = $i; $x < $n && $c < 4; $x++) { if ($t[$x][0] === 'w') { $t[$x][1] = ''; $c++; } } }
					break;
				case 'CURRENT_TIMESTAMP': case 'CURRENT_DATE': case 'CURRENT_TIME': case 'LOCALTIME': case 'LOCALTIMESTAMP':
					$fn = ['CURRENT_TIMESTAMP' => 'NOW()', 'CURRENT_DATE' => 'CURDATE()', 'CURRENT_TIME' => 'CURTIME()', 'LOCALTIME' => 'NOW()', 'LOCALTIMESTAMP' => 'NOW()'][$u];
					$j = self::next($t, $i + 1);
					if (($t[$j][1] ?? '') === '(') { $c = self::close($t, $j); for ($x = $i + 1; $x <= $c; $x++) $t[$x][1] = ''; }
					$t[$i][1] = $fn;
					break;
				case 'DUPLICATE':
					$w = self::words($t, $i, 3);
					$p = self::prev($t, $i - 1);
					if ($p >= 0 && self::up($t[$p]) === 'ON' && ($w[1] ?? '') === 'KEY' && ($w[2] ?? '') === 'UPDATE') {
						$t[$p][1] = 'ON CONFLICT DO UPDATE SET';
						$t[$i][1] = '';
						$c = 0; for ($x = $i + 1; $x < $n && $c < 2; $x++) { if ($t[$x][0] === 'w') { $t[$x][1] = ''; $c++; } }
						$dup = true;
						// INSERT … SELECT … ON CONFLICT needs a WHERE so SQLite does not read ON as a join
						$sel = false;
						for ($x = 0; $x < $p; $x++) if (self::up($t[$x]) === 'SELECT') $sel = true;
						if ($sel) { $hasWhere = false; for ($x = 0; $x < $p; $x++) if (self::up($t[$x]) === 'WHERE') $hasWhere = true; if (!$hasWhere) $t[$p][1] = 'WHERE true ON CONFLICT DO UPDATE SET'; }
					}
					break;
				case 'VALUES': case 'VALUE':
					if ($dup) {
						$j = self::next($t, $i + 1);
						if (($t[$j][1] ?? '') === '(') { $k = self::next($t, $j + 1); $c = self::close($t, $j);
							$t[$i][1] = 'excluded.' . (string)($t[$k][1] ?? ''); for ($x = $i + 1; $x <= $c; $x++) $t[$x][1] = ''; }
					}
					break;
				case 'INTERVAL':
					$i = self::interval($t, $i);
					break;
				case 'TIMESTAMPDIFF': case 'TIMESTAMPADD':
					$j = self::next($t, $i + 1);
					if (($t[$j][1] ?? '') === '(') { $k = self::next($t, $j + 1); if (($t[$k][0] ?? '') === 'w') $t[$k][1] = "'" . strtoupper($t[$k][1]) . "'"; }
					break;
				case 'EXTRACT':
					$j = self::next($t, $i + 1);
					if (($t[$j][1] ?? '') === '(') { $k = self::next($t, $j + 1); $f = self::next($t, $k + 1);
						if (($t[$k][0] ?? '') === 'w' && self::up($t[$f] ?? ['', '']) === 'FROM') { $t[$i][1] = 'nu_extract'; $t[$k][1] = "'" . strtoupper($t[$k][1]) . "'"; $t[$f][1] = ','; } }
					break;
				case 'CAST':
					self::cast($t, $i);
					break;
				case 'JSON_TABLE':
					$i = self::jsonTable($t, $i);
					break;
				case 'CONVERT':
					self::convert($t, $i);
					break;
				case 'COLLATE':
					$j = self::next($t, $i + 1);
					if (isset($t[$j])) $t[$j][1] = preg_match('/_bin$|^binary$/i', self::name($t[$j][1])) ? 'BINARY' : 'NOCASE';
					break;
			}
		}
		// GROUP_CONCAT(… SEPARATOR …) and DISTINCT: rewritten as SQLite understands them
		$sql = self::text($t);
		if (stripos($sql, 'GROUP_CONCAT') !== false) $sql = self::groupConcat($sql);
		return $sql;
	}

	/** Words that end a FROM item (no alias follows information_schema.x). */
	private const CLAUSE = ['WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL', 'ON', 'USING', 'ORDER', 'GROUP', 'HAVING',
		'LIMIT', 'UNION', 'EXCEPT', 'INTERSECT', 'WINDOW', 'FOR', 'LOCK', 'INTO', 'SET', 'VALUES', 'STRAIGHT_JOIN', 'AS', 'OFFSET'];

	/** USE / FORCE / IGNORE INDEX (…) – SQLite has its own planner. */
	private static function dropIndexHint(array &$t, int $i): void
	{
		$j = self::next($t, $i + 1);
		if (!in_array(self::up($t[$j] ?? ['', '']), ['INDEX', 'KEY'], true)) return;
		$k = self::next($t, $j + 1);
		if (self::up($t[$k] ?? ['', '']) === 'FOR') { $k = self::next($t, $k + 1); $k = self::next($t, $k + 1); if (self::up($t[$k] ?? ['', '']) === 'BY') $k = self::next($t, $k + 1); }
		if (($t[$k][1] ?? '') !== '(') return;
		$c = self::close($t, $k);
		for ($x = $i; $x <= $c; $x++) $t[$x][1] = '';
	}

	private const UNITS = ['MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR', 'SECOND_MICROSECOND', 'MINUTE_MICROSECOND',
		'MINUTE_SECOND', 'HOUR_MICROSECOND', 'HOUR_SECOND', 'HOUR_MINUTE', 'DAY_MICROSECOND', 'DAY_SECOND', 'DAY_MINUTE', 'DAY_HOUR', 'YEAR_MONTH'];

	/**
	 * INTERVAL n UNIT: inside DATE_ADD( / DATE_SUB( / ADDDATE( / SUBDATE( the arguments become (…, n, 'UNIT'); after "x +" / "x -" the
	 * whole "x + INTERVAL n UNIT" becomes DATE_ADD(x, n, 'UNIT').
	 */
	private static function interval(array &$t, int $i): int
	{
		$n = count($t);
		// the unit: the first unit word after INTERVAL at depth 0
		$d = 0; $u = -1;
		for ($x = $i + 1; $x < $n; $x++) {
			if ($t[$x][0] === 'p' && $t[$x][1] === '(') $d++;
			elseif ($t[$x][0] === 'p' && $t[$x][1] === ')') { if ($d === 0) break; $d--; }
			elseif ($d === 0 && $t[$x][0] === 'w' && in_array(strtoupper($t[$x][1]), self::UNITS, true)) { $u = $x; break; }
		}
		if ($u < 0) return $i;
		$unit = strtoupper($t[$u][1]);
		$amount = self::text(array_slice($t, $i + 1, $u - $i - 1));
		$p = self::prev($t, $i - 1);
		if ($p >= 0 && $t[$p][0] === 'p' && ($t[$p][1] === '+' || $t[$p][1] === '-')) {
			// left operand: a call / bracket, or one name / literal (a.b)
			$l = self::prev($t, $p - 1);
			if ($l < 0) return $u;
			$start = $l;
			if ($t[$l][0] === 'p' && $t[$l][1] === ')') {
				$dd = 0;
				for ($x = $l; $x >= 0; $x--) {
					if ($t[$x][0] === 'p' && $t[$x][1] === ')') $dd++;
					elseif ($t[$x][0] === 'p' && $t[$x][1] === '(' && --$dd === 0) { $start = $x; break; }
				}
				$f = self::prev($t, $start - 1);
				if ($f >= 0 && $t[$f][0] === 'w' && !in_array(strtoupper($t[$f][1]), ['AND', 'OR', 'NOT', 'WHERE', 'SELECT', 'ON', 'WHEN', 'THEN', 'ELSE', 'IN'], true)) $start = $f;
			} else {
				while (($q = self::prev($t, $start - 1)) >= 1 && ($t[$q][1] ?? '') === '.') $start = self::prev($t, $q - 1);
			}
			$left = self::text(array_slice($t, $start, $l - $start + 1));
			$fn = $t[$p][1] === '+' ? 'DATE_ADD' : 'DATE_SUB';
			for ($x = $start; $x <= $u; $x++) $t[$x][1] = '';
			$t[$start][1] = "$fn($left, $amount, '$unit')";
			return $u;
		}
		$t[$i][1] = '';
		$t[$u][1] = ", '$unit'";
		return $u;
	}

	/** CAST(x AS DATE / DATETIME / TIME / CHAR(n) CHARACTER SET … / SIGNED / UNSIGNED …) for SQLite. */
	private static function cast(array &$t, int $i): void
	{
		$j = self::next($t, $i + 1);
		if (($t[$j][1] ?? '') !== '(') return;
		$c = self::close($t, $j);
		$d = 0; $as = -1;
		for ($x = $j + 1; $x < $c; $x++) {
			if ($t[$x][0] === 'p' && $t[$x][1] === '(') $d++;
			elseif ($t[$x][0] === 'p' && $t[$x][1] === ')') $d--;
			elseif ($d === 0 && self::up($t[$x]) === 'AS') $as = $x;
		}
		if ($as < 0) return;
		$type = strtoupper(trim((string)preg_replace('/\s+/', ' ', self::text(array_slice($t, $as + 1, $c - $as - 1)))));
		$new = match (true) {
			str_starts_with($type, 'CHAR'), str_starts_with($type, 'NCHAR'), str_starts_with($type, 'VARCHAR'), str_starts_with($type, 'DATE'),
			str_starts_with($type, 'TIME'), str_starts_with($type, 'JSON'), str_starts_with($type, 'TEXT') => 'TEXT',
			str_starts_with($type, 'SIGNED'), str_starts_with($type, 'UNSIGNED'), str_starts_with($type, 'INT') => 'INTEGER',
			str_starts_with($type, 'DECIMAL'), str_starts_with($type, 'NUMERIC') => 'NUMERIC',
			str_starts_with($type, 'DOUBLE'), str_starts_with($type, 'FLOAT'), str_starts_with($type, 'REAL') => 'REAL',
			str_starts_with($type, 'BINARY') => 'BLOB',
			default => null,
		};
		if ($new === null) return;
		for ($x = $as + 1; $x < $c; $x++) $t[$x][1] = '';
		$t[$as][1] = 'AS ' . $new;
	}

	/**
	 * MySQL 8's JSON_TABLE(doc, '$.list[*]' COLUMNS(c TYPE PATH '$.x', …)) alias → json_each(doc, '$.list') alias, and alias.c →
	 * json_extract(alias.value, '$.x') in the whole statement. One level, PATH columns (FOR ORDINALITY → alias.key + 1).
	 */
	private static function jsonTable(array &$t, int $i): int
	{
		$j = self::next($t, $i + 1);
		if (($t[$j][1] ?? '') !== '(') return $i;
		$c = self::close($t, $j);
		$parts = self::splitTop(array_slice($t, $j + 1, $c - $j - 1));
		if (count($parts) < 2) return $i;
		$doc = trim(self::text($parts[0]));
		$rest = array_values(array_filter($parts[1], static fn($x) => $x[0] !== ' ' && $x[0] !== 'c'));
		$path = $rest[0][1] ?? "'$'";
		$x = 0; while ($x < count($rest) && self::up($rest[$x]) !== 'COLUMNS') $x++;
		if ($x >= count($rest)) return $i;
		$cols = [];
		$open = $x + 1;
		$cc = self::close($rest, $open);
		foreach (self::splitTop(array_slice($rest, $open + 1, $cc - $open - 1)) as $cd) {
			$cd = array_values(array_filter($cd, static fn($y) => $y[0] !== ' ' && $y[0] !== 'c'));
			if (!$cd) continue;
			$name = strtolower(self::name($cd[0][1]));
			$pi = -1;
			foreach ($cd as $k => $y) if (self::up($y) === 'PATH') $pi = $k;
			$ord = false;
			foreach ($cd as $k => $y) if (self::up($y) === 'ORDINALITY') $ord = true;
			$cols[$name] = $ord ? null : ($pi >= 0 ? (string)($cd[$pi + 1][1] ?? "'$'") : "'$." . $name . "'");
		}
		$p = (string)preg_replace('/\[\*\]\s*\'$/', "'", $path);
		$a = self::next($t, $c + 1);
		if (self::up($t[$a] ?? ['', '']) === 'AS') $a = self::next($t, $a + 1);
		$alias = isset($t[$a]) && in_array($t[$a][0], ['w', 'q'], true) ? self::name($t[$a][1]) : 'jt';
		for ($y = $i + 1; $y <= $c; $y++) $t[$y][1] = '';
		$t[$i][1] = "json_each($doc, $p)";
		// alias.col → json_extract(alias.value, path)
		$n = count($t);
		for ($y = 0; $y < $n; $y++) {
			if (!in_array($t[$y][0], ['w', 'q'], true) || strcasecmp(self::name($t[$y][1]), $alias) !== 0) continue;
			$d = self::next($t, $y + 1);
			if (($t[$d][1] ?? '') !== '.') continue;
			$k = self::next($t, $d + 1);
			$col = strtolower(self::name($t[$k][1] ?? ''));
			if (!array_key_exists($col, $cols)) continue;
			$t[$y][1] = $cols[$col] === null ? '(' . $alias . '.key + 1)' : '(' . $alias . '.value ->> ' . $cols[$col] . ')';
			for ($z = $y + 1; $z <= $k; $z++) $t[$z][1] = '';
		}
		return $c;
	}

	/** CONVERT(x USING cs) → x; CONVERT(x, type) → CAST(x AS type). */
	private static function convert(array &$t, int $i): void
	{
		$j = self::next($t, $i + 1);
		if (($t[$j][1] ?? '') !== '(') return;
		$c = self::close($t, $j);
		$d = 0;
		for ($x = $j + 1; $x < $c; $x++) {
			if ($t[$x][0] === 'p' && $t[$x][1] === '(') $d++;
			elseif ($t[$x][0] === 'p' && $t[$x][1] === ')') $d--;
			elseif ($d === 0 && self::up($t[$x]) === 'USING') {
				for ($y = $x; $y < $c; $y++) $t[$y][1] = '';
				$t[$i][1] = '';
				return;
			} elseif ($d === 0 && $t[$x][0] === 'p' && $t[$x][1] === ',') {
				$t[$i][1] = 'CAST'; $t[$x][1] = ' AS ';
				self::cast($t, $i);
				return;
			}
		}
	}

	/**
	 * MySQL's GROUP_CONCAT([DISTINCT] a [, b …] [ORDER BY …] [SEPARATOR s]) for SQLite: several values joined with ||, the separator as
	 * second argument (with DISTINCT – one argument only in SQLite – put in afterwards by REPLACE), ORDER BY inside from SQLite 3.44 on.
	 */
	private static function groupConcat(string $sql): string
	{
		static $ordered = null;
		$ordered ??= version_compare((string)(new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn(), '3.44.0', '>=');
		$t = self::tokens($sql);
		$n = count($t);
		for ($i = 0; $i < $n; $i++) {
			if (self::up($t[$i]) !== 'GROUP_CONCAT') continue;
			$j = self::next($t, $i + 1);
			if (($t[$j][1] ?? '') !== '(') continue;
			$c = self::close($t, $j);
			$distinct = false; $exprs = [[]]; $order = null; $sep = null; $part = 'e';
			$d = 0;
			for ($x = $j + 1; $x < $c; $x++) {
				$tok = $t[$x];
				if ($tok[0] === 'p' && $tok[1] === '(') $d++;
				elseif ($tok[0] === 'p' && $tok[1] === ')') $d--;
				if ($d === 0 && $tok[0] === 'w') {
					$u = strtoupper($tok[1]);
					if ($u === 'DISTINCT' && $part === 'e' && $exprs === [[]]) { $distinct = true; continue; }
					if ($u === 'ORDER' && self::up($t[self::next($t, $x + 1)] ?? ['', '']) === 'BY') { $part = 'o'; $order = []; $x = self::next($t, $x + 1); continue; }
					if ($u === 'SEPARATOR') { $part = 's'; $sep = []; continue; }
				}
				if ($part === 'e') {
					if ($d === 0 && $tok[0] === 'p' && $tok[1] === ',') { $exprs[] = []; continue; }
					$exprs[count($exprs) - 1][] = $tok;
				} elseif ($part === 'o') $order[] = $tok;
				else $sep[] = $tok;
			}
			$ex = array_map(static fn($e) => trim(self::groupConcat(self::text($e))), $exprs);
			$expr = count($ex) > 1 ? '(' . implode(' || ', $ex) . ')' : $ex[0];
			$ord = $order !== null && $ordered ? ' ORDER BY ' . trim(self::text($order)) : '';
			$sp = $sep !== null ? trim(self::text($sep)) : null;
			$out = $distinct
				? ($sp !== null && $sp !== "','" ? "REPLACE(GROUP_CONCAT(DISTINCT $expr$ord), ',', $sp)" : "GROUP_CONCAT(DISTINCT $expr$ord)")
				: 'GROUP_CONCAT(' . $expr . ($sp !== null ? ', ' . $sp : '') . $ord . ')';
			$t[$i][1] = $out;
			for ($x = $i + 1; $x <= $c; $x++) $t[$x][1] = '';
			$i = $c;
		}
		return self::text($t);
	}

	/** information_schema's views as SQLite queries – the columns code of nuBuilder Forte and of applications reads. */
	private static function infoSchema(string $view): ?string
	{
		$tbl = "m.type IN ('table', 'view') AND m.name NOT LIKE 'sqlite!_%' ESCAPE '!'";
		return match ($view) {
			'TABLES' => "SELECT 'def' AS TABLE_CATALOG, 'main' AS TABLE_SCHEMA, m.name AS TABLE_NAME, CASE m.type WHEN 'view' THEN 'VIEW' ELSE 'BASE TABLE' END AS TABLE_TYPE,"
				. " CASE m.type WHEN 'table' THEN 'SQLite' END AS ENGINE, NULL AS TABLE_ROWS, NULL AS AVG_ROW_LENGTH, NULL AS DATA_LENGTH, NULL AS INDEX_LENGTH,"
				. " NULL AS AUTO_INCREMENT, NULL AS CREATE_TIME, NULL AS UPDATE_TIME, 'utf8mb4_general_ci' AS TABLE_COLLATION, '' AS TABLE_COMMENT FROM sqlite_master m WHERE $tbl",
			'COLUMNS' => "SELECT 'def' AS TABLE_CATALOG, 'main' AS TABLE_SCHEMA, m.name AS TABLE_NAME, p.name AS COLUMN_NAME, p.cid + 1 AS ORDINAL_POSITION,"
				. " p.dflt_value AS COLUMN_DEFAULT, CASE p.`notnull` WHEN 1 THEN 'NO' ELSE 'YES' END AS IS_NULLABLE, nu_type_base(p.type) AS DATA_TYPE,"
				. " nu_type_len(p.type) AS CHARACTER_MAXIMUM_LENGTH, nu_type_len(p.type) AS CHARACTER_OCTET_LENGTH, nu_type_len(p.type) AS NUMERIC_PRECISION,"
				. " NULL AS NUMERIC_SCALE, NULL AS DATETIME_PRECISION, 'utf8mb4' AS CHARACTER_SET_NAME, 'utf8mb4_general_ci' AS COLLATION_NAME,"
				. " lower(CASE WHEN p.type = '' THEN 'text' ELSE p.type END) AS COLUMN_TYPE, CASE WHEN p.pk > 0 THEN 'PRI' ELSE '' END AS COLUMN_KEY,"
				. " CASE WHEN p.pk > 0 AND lower(p.type) = 'integer' THEN 'auto_increment' ELSE '' END AS EXTRA, '' AS COLUMN_COMMENT, '' AS GENERATION_EXPRESSION"
				. " FROM sqlite_master m JOIN pragma_table_info(m.name) p WHERE $tbl",
			'STATISTICS' => "SELECT 'def' AS TABLE_CATALOG, 'main' AS TABLE_SCHEMA, m.name AS TABLE_NAME, CASE il.`unique` WHEN 1 THEN 0 ELSE 1 END AS NON_UNIQUE,"
				. " 'main' AS INDEX_SCHEMA, CASE il.origin WHEN 'pk' THEN 'PRIMARY' ELSE il.name END AS INDEX_NAME, ii.seqno + 1 AS SEQ_IN_INDEX, ii.name AS COLUMN_NAME,"
				. " 'A' AS COLLATION, NULL AS CARDINALITY, NULL AS SUB_PART, 'YES' AS NULLABLE, 'BTREE' AS INDEX_TYPE, '' AS COMMENT"
				. " FROM sqlite_master m JOIN pragma_index_list(m.name) il JOIN pragma_index_info(il.name) ii WHERE m.type = 'table'"
				. " UNION ALL SELECT 'def', 'main', m.name, 0, 'main', 'PRIMARY', p.pk, p.name, 'A', NULL, NULL, '', 'BTREE', '' FROM sqlite_master m JOIN pragma_table_info(m.name) p"
				. " WHERE m.type = 'table' AND p.pk > 0 AND NOT EXISTS (SELECT 1 FROM pragma_index_list(m.name) x WHERE x.origin = 'pk')",
			'KEY_COLUMN_USAGE' => "SELECT 'def' AS CONSTRAINT_CATALOG, 'main' AS CONSTRAINT_SCHEMA, 'PRIMARY' AS CONSTRAINT_NAME, 'def' AS TABLE_CATALOG, 'main' AS TABLE_SCHEMA,"
				. " m.name AS TABLE_NAME, p.name AS COLUMN_NAME, p.pk AS ORDINAL_POSITION, NULL AS POSITION_IN_UNIQUE_CONSTRAINT, NULL AS REFERENCED_TABLE_SCHEMA,"
				. " NULL AS REFERENCED_TABLE_NAME, NULL AS REFERENCED_COLUMN_NAME FROM sqlite_master m JOIN pragma_table_info(m.name) p WHERE m.type = 'table' AND p.pk > 0"
				. " UNION ALL SELECT 'def', 'main', 'fk_' || m.name || '_' || f.id, 'def', 'main', m.name, f.`from`, f.seq + 1, f.seq + 1, 'main', f.`table`, f.`to`"
				. " FROM sqlite_master m JOIN pragma_foreign_key_list(m.name) f WHERE m.type = 'table'",
			'TABLE_CONSTRAINTS' => "SELECT 'def' AS CONSTRAINT_CATALOG, 'main' AS CONSTRAINT_SCHEMA, 'PRIMARY' AS CONSTRAINT_NAME, 'main' AS TABLE_SCHEMA, m.name AS TABLE_NAME,"
				. " 'PRIMARY KEY' AS CONSTRAINT_TYPE FROM sqlite_master m WHERE m.type = 'table' AND EXISTS (SELECT 1 FROM pragma_table_info(m.name) p WHERE p.pk > 0)"
				. " UNION ALL SELECT 'def', 'main', il.name, 'main', m.name, 'UNIQUE' FROM sqlite_master m JOIN pragma_index_list(m.name) il WHERE m.type = 'table' AND il.`unique` = 1 AND il.origin <> 'pk'"
				. " UNION ALL SELECT 'def', 'main', 'fk_' || m.name || '_' || f.id, 'main', m.name, 'FOREIGN KEY' FROM sqlite_master m JOIN pragma_foreign_key_list(m.name) f WHERE m.type = 'table' AND f.seq = 0",
			'REFERENTIAL_CONSTRAINTS' => "SELECT 'def' AS CONSTRAINT_CATALOG, 'main' AS CONSTRAINT_SCHEMA, 'fk_' || m.name || '_' || f.id AS CONSTRAINT_NAME, f.on_update AS UPDATE_RULE,"
				. " f.on_delete AS DELETE_RULE, m.name AS TABLE_NAME, f.`table` AS REFERENCED_TABLE_NAME FROM sqlite_master m JOIN pragma_foreign_key_list(m.name) f WHERE m.type = 'table' AND f.seq = 0",
			'VIEWS' => "SELECT 'def' AS TABLE_CATALOG, 'main' AS TABLE_SCHEMA, m.name AS TABLE_NAME, m.sql AS VIEW_DEFINITION, 'NONE' AS CHECK_OPTION, 'NO' AS IS_UPDATABLE,"
				. " '' AS DEFINER, 'DEFINER' AS SECURITY_TYPE FROM sqlite_master m WHERE m.type = 'view'",
			'TRIGGERS' => "SELECT 'def' AS TRIGGER_CATALOG, 'main' AS TRIGGER_SCHEMA, m.name AS TRIGGER_NAME, m.tbl_name AS EVENT_OBJECT_TABLE, m.sql AS ACTION_STATEMENT"
				. " FROM sqlite_master m WHERE m.type = 'trigger'",
			'ROUTINES' => "SELECT NULL AS ROUTINE_SCHEMA, NULL AS ROUTINE_NAME, NULL AS ROUTINE_TYPE, NULL AS ROUTINE_DEFINITION, NULL AS DEFINER WHERE 0",
			'EVENTS' => "SELECT NULL AS EVENT_CATALOG, NULL AS EVENT_SCHEMA, NULL AS EVENT_NAME, NULL AS DEFINER, NULL AS EVENT_DEFINITION, NULL AS STATUS WHERE 0",
			'PARAMETERS', 'PARTITIONS', 'USER_PRIVILEGES', 'SCHEMA_PRIVILEGES', 'TABLE_PRIVILEGES', 'COLUMN_PRIVILEGES'
				=> "SELECT NULL AS SPECIFIC_NAME WHERE 0",
			'SCHEMATA' => "SELECT 'def' AS CATALOG_NAME, 'main' AS SCHEMA_NAME, 'utf8mb4' AS DEFAULT_CHARACTER_SET_NAME, 'utf8mb4_general_ci' AS DEFAULT_COLLATION_NAME",
			'CHARACTER_SETS' => "SELECT 'utf8mb4' AS CHARACTER_SET_NAME, 'utf8mb4_general_ci' AS DEFAULT_COLLATE_NAME, 'UTF-8 Unicode' AS DESCRIPTION, 4 AS MAXLEN",
			'COLLATIONS' => "SELECT 'utf8mb4_general_ci' AS COLLATION_NAME, 'utf8mb4' AS CHARACTER_SET_NAME",
			default => null,
		};
	}

	// ------------------------------------------------------------------ DDL and SHOW

	private static function special(NuDB $db, array $t, string $verb): ?string
	{
		$i = self::next($t, 0);
		$w = self::words($t, $i, 6);
		switch ($verb) {
			case 'START': return ($w[1] ?? '') === 'TRANSACTION' ? 'BEGIN' : null;
			case 'USE': case 'FLUSH': case 'GRANT': case 'REVOKE': case 'KILL': case 'LOCK': case 'UNLOCK': case 'REPAIR': case 'CHECK': case 'DO':
				return self::NOTHING;
			case 'SET':
				// SET NAMES / SET time_zone / SET FOREIGN_KEY_CHECKS / SET SESSION … – SQLite has none of them
				return self::NOTHING;
			case 'OPTIMIZE': return 'VACUUM';
			case 'ANALYZE': return 'ANALYZE';
			case 'CALL': return null;
			case 'TRUNCATE':
				$k = self::next($t, $i + 1);
				if (self::up($t[$k] ?? ['', '']) === 'TABLE') $k = self::next($t, $k + 1);
				$name = self::name($t[$k][1] ?? '');
				self::run('DELETE FROM ' . self::qn($name));
				try { self::run('DELETE FROM sqlite_sequence WHERE name = ' . self::lit($name)); } catch (Throwable) { /* no AUTOINCREMENT table yet – expected */ }
				return self::NOTHING;
			case 'RENAME':
				// RENAME TABLE a TO b [, c TO d]
				$parts = self::splitTop(array_slice($t, self::next($t, $i + 1) + 1));
				// as MySQL: views and triggers that name the table are neither checked nor changed (legacy_alter_table)
				self::run('PRAGMA legacy_alter_table = ON');
				try {
					foreach ($parts as $part) {
						$pw = array_values(array_filter($part, static fn($x) => $x[0] !== ' ' && $x[0] !== 'c'));
						if (count($pw) >= 3) self::run('ALTER TABLE ' . self::qn(self::name($pw[0][1])) . ' RENAME TO ' . self::qn(self::name($pw[2][1])));
					}
				} finally {
					self::run('PRAGMA legacy_alter_table = OFF');
				}
				return self::NOTHING;
			case 'SHOW': return self::show($t, $w);
			case 'DESCRIBE': case 'DESC': case 'EXPLAIN':
				$k = self::next($t, $i + 1);
				if ($verb !== 'DESCRIBE' && !in_array(($t[$k][0] ?? ''), ['w', 'q'], true)) return null;
				if ($verb === 'EXPLAIN' && in_array(self::up($t[$k] ?? ['', '']), ['SELECT', 'UPDATE', 'DELETE', 'INSERT', 'WITH', 'QUERY'], true)) return 'EXPLAIN QUERY PLAN ' . self::dml(array_slice($t, $k));
				return self::columnsOf(self::name($t[$k][1] ?? ''));
			case 'DROP':
				$sql = self::text($t);
				$sql = (string)preg_replace('/\s+(CASCADE|RESTRICT)\s*;?\s*$/i', '', $sql);
				$sql = (string)preg_replace('/^\s*DROP\s+TEMPORARY\s+TABLE/i', 'DROP TABLE', $sql);
				if (preg_match('/^\s*DROP\s+(TABLE|VIEW)\s+(IF\s+EXISTS\s+)?(.+?)\s*;?\s*$/is', $sql, $m)) {
					// one by one; a view named in DROP TABLE is dropped as a view (SQLite refuses it)
					foreach (self::splitTop(self::tokens($m[3])) as $p) {
						$nm = self::name(trim(self::text($p)));
						$kind = strtoupper($m[1]);
						if ($kind === 'TABLE' && (string)$db->value("SELECT type FROM sqlite_master WHERE name = ?", [$nm]) === 'view') $kind = 'VIEW';
						self::run('DROP ' . $kind . ' ' . $m[2] . self::qn($nm));
					}
					return self::NOTHING;
				}
				if (preg_match('/^\s*DROP\s+(PROCEDURE|FUNCTION|EVENT|DATABASE|SCHEMA|USER)\b/i', $sql)) return self::NOTHING;
				if (preg_match('/^\s*DROP\s+INDEX\s+(IF\s+EXISTS\s+)?(\S+)\s+ON\s+(\S+)/i', $sql, $m)) {
					$tb = self::name($m[3]); $ix = self::name($m[2]);
					self::run('DROP INDEX IF EXISTS ' . self::qn(self::indexName($db, $tb, $ix)));
					return self::NOTHING;
				}
				return $sql;
			case 'ALTER':
				if (($w[1] ?? '') === 'TABLE' || (($w[1] ?? '') === 'IGNORE' && ($w[2] ?? '') === 'TABLE') || (($w[1] ?? '') === 'ONLINE')) { self::alter($db, $t); return self::NOTHING; }
				if (in_array($w[1] ?? '', ['DATABASE', 'SCHEMA', 'USER', 'EVENT', 'VIEW'], true)) return $w[1] === 'VIEW' ? self::view($t, true) : self::NOTHING;
				return null;
			case 'CREATE':
				$k = 1;
				$or = false;
				if (($w[1] ?? '') === 'OR' && ($w[2] ?? '') === 'REPLACE') { $or = true; $k = 3; }
				// skip ALGORITHM=…, DEFINER=…, SQL SECURITY … of views
				$kind = $w[$k] ?? '';
				if (in_array($kind, ['ALGORITHM', 'DEFINER', 'SQL', 'VIEW'], true) || ($or && $kind !== 'TABLE')) return self::view($t, $or);
				if ($kind === 'TABLE' || $kind === 'TEMPORARY' || $kind === 'TEMP') return self::createTable($db, $t);
				if ($kind === 'UNIQUE' || $kind === 'INDEX' || $kind === 'FULLTEXT' || $kind === 'SPATIAL') return self::createIndex($db, $t);
				if (in_array($kind, ['DATABASE', 'SCHEMA', 'USER', 'EVENT', 'PROCEDURE', 'FUNCTION'], true)) return self::NOTHING;
				if ($kind === 'TRIGGER') return null;
				return null;
		}
		return null;
	}

	/** Splits tokens on commas at depth 0. @return list<list<array{0: string, 1: string}>> */
	private static function splitTop(array $t): array
	{
		$out = []; $cur = []; $d = 0;
		foreach ($t as $tok) {
			if ($tok[0] === 'p' && $tok[1] === '(') $d++;
			elseif ($tok[0] === 'p' && $tok[1] === ')') $d--;
			if ($d === 0 && $tok[0] === 'p' && $tok[1] === ',') { $out[] = $cur; $cur = []; continue; }
			$cur[] = $tok;
		}
		if ($cur) $out[] = $cur;
		return array_values(array_filter($out, static fn($p) => trim(self::text($p)) !== ''));
	}

	private static function columnsOf(string $table): string
	{
		return "SELECT name AS Field, CASE WHEN type = '' THEN 'text' ELSE lower(type) END AS Type, CASE `notnull` WHEN 1 THEN 'NO' ELSE 'YES' END AS `Null`,"
			. " CASE WHEN pk > 0 THEN 'PRI' ELSE '' END AS \"Key\", dflt_value AS \"Default\", '' AS Extra FROM pragma_table_info(" . self::lit($table) . ') ORDER BY cid';
	}

	private static function show(array $t, array $w): ?string
	{
		$sql = self::text($t);
		$like = preg_match("/\\bLIKE\\s+('(?:[^']|'')*')/i", $sql, $m) ? $m[1] : null;
		$full = ($w[1] ?? '') === 'FULL';
		$what = $w[$full ? 2 : 1] ?? '';
		$from = preg_match('/\b(?:FROM|IN)\s+(`[^`]+`|"[^"]+"|\[[^\]]+\]|[A-Za-z0-9_$]+)/i', $sql, $f) ? self::name($f[1]) : '';
		switch ($what) {
			case 'TABLES':
				return "SELECT name AS Tables_in_main" . ($full ? ", CASE type WHEN 'view' THEN 'VIEW' ELSE 'BASE TABLE' END AS Table_type" : '')
					. " FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite!_%' ESCAPE '!'" . ($like !== null ? " AND name LIKE $like" : '') . ' ORDER BY name';
			case 'COLUMNS': case 'FIELDS':
				return 'SELECT * FROM (' . self::columnsOf($from) . ')' . ($like !== null ? " WHERE Field LIKE $like" : '');
			case 'INDEX': case 'INDEXES': case 'KEYS':
				return "SELECT TABLE_NAME AS `Table`, NON_UNIQUE AS Non_unique, INDEX_NAME AS Key_name, SEQ_IN_INDEX AS Seq_in_index, COLUMN_NAME AS Column_name,"
					. " 'BTREE' AS Index_type FROM (" . self::infoSchema('STATISTICS') . ") WHERE TABLE_NAME = " . self::lit($from);
			case 'CREATE':
				$k = preg_match('/\bCREATE\s+(TABLE|VIEW)\s+(\S+)/i', $sql, $c) ? self::name(rtrim($c[2], ';')) : '';
				return 'SELECT name AS "Table", sql AS "Create Table" FROM sqlite_master WHERE name = ' . self::lit($k);
			case 'DATABASES': case 'SCHEMAS':
				return "SELECT 'main' AS `Database`";
			case 'TABLE':   // SHOW TABLE STATUS
				return "SELECT name AS Name, 'SQLite' AS Engine, NULL AS `Rows`, 'utf8mb4_general_ci' AS Collation, '' AS Comment FROM sqlite_master"
					. " WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!'" . ($like !== null ? " AND name LIKE $like" : '');
			case 'VARIABLES': case 'SESSION': case 'GLOBAL': case 'STATUS':
				return "SELECT 'version' AS Variable_name, sqlite_version() AS Value" . ($like !== null ? " WHERE 'version' LIKE $like" : '');
			case 'WARNINGS': case 'ERRORS': case 'TRIGGERS': case 'PROCEDURE': case 'FUNCTION': case 'EVENTS': case 'GRANTS': case 'PROCESSLIST': case 'ENGINES':
				return "SELECT NULL AS Name WHERE 0";
		}
		return null;
	}

	/** CREATE [OR REPLACE] [ALGORITHM=…] [DEFINER=…] [SQL SECURITY …] VIEW v [(cols)] AS select. */
	private static function view(array $t, bool $replace): string
	{
		$sql = self::text($t);
		if (!preg_match('/\bVIEW\s+(IF\s+NOT\s+EXISTS\s+)?(`[^`]+`|"[^"]+"|\[[^\]]+\]|[A-Za-z0-9_$.]+)\s*(\([^)]*\)\s*)?AS\s+(.*)$/is', $sql, $m)) return $sql;
		$name = self::name(preg_replace('/^.*\./', '', $m[2]) ?? $m[2]);
		$body = self::dml(self::tokens(rtrim(trim($m[4]), ';')));
		$body = (string)preg_replace('/\s+WITH\s+(CASCADED\s+|LOCAL\s+)?CHECK\s+OPTION\s*$/i', '', $body);
		if ($replace) self::run('DROP VIEW IF EXISTS ' . self::qn($name));
		return 'CREATE VIEW ' . $m[1] . self::qn($name) . ' ' . $m[3] . 'AS ' . $body;
	}

	/** Index names are per database in SQLite, per table in MySQL: KEY x of table t is called t__x. */
	private static function indexName(NuDB $db, string $table, string $index): string
	{
		$own = $table . '__' . $index;
		try {
			$have = $db->value("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name IN (?, ?)", [$table, $own, $index]);
			if (is_string($have) && $have !== '') return $have;
		} catch (Throwable $ig) { nu_ignored($ig); }
		return $own;
	}

	/** CREATE [UNIQUE|FULLTEXT] INDEX name [USING …] ON t (a(10), b) – lengths and index types dropped. */
	private static function createIndex(NuDB $db, array $t): string
	{
		$sql = self::text($t);
		if (!preg_match('/^\s*CREATE\s+(UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX\s+(IF\s+NOT\s+EXISTS\s+)?(\S+)\s+(?:USING\s+\w+\s+)?ON\s+(\S+?)\s*\((.*)\)[^)]*$/is', $sql, $m)) return $sql;
		$cols = self::indexCols(self::tokens($m[5]));
		return 'CREATE ' . (stripos($m[1], 'UNIQUE') !== false ? 'UNIQUE ' : '') . 'INDEX ' . $m[2] . self::qn(self::name($m[3])) . ' ON ' . self::qn(self::name($m[4])) . " ($cols)";
	}

	/** "a(10) ASC, `b`" → "a ASC", "b". */
	private static function indexCols(array $t): string
	{
		$out = [];
		foreach (self::splitTop($t) as $p) {
			$p = array_values(array_filter($p, static fn($x) => $x[0] !== ' ' && $x[0] !== 'c'));
			if (!$p) continue;
			$col = self::qn(self::name($p[0][1]));
			$dir = '';
			foreach ($p as $x) if (in_array(self::up($x), ['ASC', 'DESC'], true)) $dir = ' ' . self::up($x);
			$out[] = $col . $dir;
		}
		return implode(', ', $out);
	}

	/** Column definition (MySQL) → [name, sqlite definition, auto_increment, inline primary key, text]. */
	private static function column(array $p): array
	{
		$p = array_values(array_filter($p, static fn($x) => $x[0] !== 'c'));
		$i = self::next($p, 0);
		$name = self::name($p[$i][1] ?? '');
		$i = self::next($p, $i + 1);
		$n = count($p);
		// the type: a word (+ more words such as DOUBLE PRECISION / CHARACTER VARYING) + (args)
		$type = '';
		$base = '';
		if ($i < $n && $p[$i][0] === 'w' && !in_array(strtoupper($p[$i][1]), ['NOT', 'NULL', 'DEFAULT', 'PRIMARY', 'AUTO_INCREMENT', 'AUTOINCREMENT', 'GENERATED', 'AS', 'UNIQUE', 'CHECK', 'REFERENCES', 'COLLATE', 'COMMENT'], true)) {
			$base = strtoupper($p[$i][1]);
			$type = $p[$i][1];
			$i++;
			$j = self::next($p, $i);
			if ($j < $n && in_array(self::up($p[$j]), ['PRECISION', 'VARYING'], true)) { $type .= ' ' . $p[$j][1]; $i = $j + 1; }
			$j = self::next($p, $i);
			if ($j < $n && $p[$j][1] === '(') { $c = self::close($p, $j); $args = self::text(array_slice($p, $j, $c - $j + 1)); $i = $c + 1;
				if (!in_array($base, ['ENUM', 'SET'], true)) $type .= str_replace(' ', '', $args); }
		}
		$text = in_array($base, ['CHAR', 'VARCHAR', 'NCHAR', 'NVARCHAR', 'TEXT', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT', 'ENUM', 'SET', 'JSON', 'CHARACTER', 'NATIONAL', 'CLOB', 'STRING', ''], true);
		if (in_array($base, ['ENUM', 'SET', 'JSON'], true)) $type = 'TEXT';
		elseif (in_array($base, ['BINARY', 'VARBINARY', 'TINYBLOB', 'MEDIUMBLOB', 'LONGBLOB', 'BLOB', 'BIT'], true)) $type = $base === 'BIT' ? 'INTEGER' : 'BLOB';
		elseif (in_array($base, ['BOOL', 'BOOLEAN'], true)) $type = 'INTEGER';
		elseif ($base === 'YEAR') $type = 'INTEGER';
		$out = [];
		$auto = false; $pk = false; $binary = false;
		while ($i < $n) {
			$tok = $p[$i];
			if ($tok[0] === ' ') { $i++; continue; }
			$u = self::up($tok);
			if (in_array($u, ['UNSIGNED', 'SIGNED', 'ZEROFILL', 'INVISIBLE', 'VISIBLE', 'BINARY'], true)) { if ($u === 'BINARY') $binary = true; $i++; continue; }
			if ($u === 'AUTO_INCREMENT' || $u === 'AUTOINCREMENT' || $u === 'IDENTITY') { $auto = true; $i++; $j = self::next($p, $i); if (($p[$j][1] ?? '') === '(') $i = self::close($p, $j) + 1; continue; }
			if ($u === 'CHARACTER' || $u === 'CHARSET') { $j = self::next($p, $i + 1); if (self::up($p[$j] ?? ['', '']) === 'SET') $j = self::next($p, $j + 1); $i = $j + 1; continue; }
			if ($u === 'COLLATE') { $j = self::next($p, $i + 1); if (preg_match('/_bin$|^binary$/i', self::name($p[$j][1] ?? ''))) $binary = true; $i = $j + 1; continue; }
			if ($u === 'COMMENT') { $j = self::next($p, $i + 1); $i = $j + 1; continue; }
			if ($u === 'ON') {   // ON UPDATE CURRENT_TIMESTAMP[()]
				$j = self::next($p, $i + 1); $k = self::next($p, $j + 1);
				$i = $k + 1;
				$x = self::next($p, $i); if (($p[$x][1] ?? '') === '(') $i = self::close($p, $x) + 1;
				continue;
			}
			if ($u === 'PRIMARY') { $pk = true; $j = self::next($p, $i + 1); $i = self::up($p[$j] ?? ['', '']) === 'KEY' ? $j + 1 : $i + 1; continue; }
			if ($u === 'KEY') { $pk = true; $i++; continue; }   // "id INT KEY" = primary key
			if ($u === 'UNIQUE') { $out[] = 'UNIQUE'; $j = self::next($p, $i + 1); $i = self::up($p[$j] ?? ['', '']) === 'KEY' ? $j + 1 : $i + 1; continue; }
			if ($u === 'DEFAULT') {
				$j = self::next($p, $i + 1);
				$v = $p[$j] ?? ['w', 'NULL'];
				$vu = self::up($v);
				if (in_array($vu, ['CURRENT_TIMESTAMP', 'NOW', 'LOCALTIMESTAMP', 'LOCALTIME'], true)) {
					$out[] = "DEFAULT (datetime('now', 'localtime'))";
					$i = $j + 1; $x = self::next($p, $i); if (($p[$x][1] ?? '') === '(') $i = self::close($p, $x) + 1;
					continue;
				}
				if ($vu === 'CURRENT_DATE' || $vu === 'CURDATE') { $out[] = "DEFAULT (date('now', 'localtime'))"; $i = $j + 1; $x = self::next($p, $i); if (($p[$x][1] ?? '') === '(') $i = self::close($p, $x) + 1; continue; }
				if (($v[1] ?? '') === '(') { $c = self::close($p, $j); $out[] = 'DEFAULT ' . self::dml(array_slice($p, $j, $c - $j + 1)); $i = $c + 1; continue; }
				if (($v[1] ?? '') === '-' || ($v[1] ?? '') === '+') { $k = self::next($p, $j + 1); $out[] = 'DEFAULT ' . $v[1] . ($p[$k][1] ?? '0'); $i = $k + 1; continue; }
				if ($v[0] === 'x') { $out[] = "DEFAULT X'" . substr($v[1], 2) . "'"; $i = $j + 1; continue; }
				if ($v[0] === 'w' && !in_array($vu, ['NULL', 'TRUE', 'FALSE'], true)) { $out[] = 'DEFAULT ' . self::lit($v[1]); $i = $j + 1; continue; }
				$out[] = 'DEFAULT ' . $v[1]; $i = $j + 1; continue;
			}
			if ($u === 'GENERATED' || $u === 'AS') {
				$j = $u === 'GENERATED' ? self::next($p, self::next($p, $i + 1) + 1) : $i;   // GENERATED ALWAYS AS
				$j = self::next($p, $j + 1);
				if (($p[$j][1] ?? '') !== '(') { $i++; continue; }
				$c = self::close($p, $j);
				$expr = self::dml(array_slice($p, $j, $c - $j + 1));
				$i = $c + 1;
				$k = self::next($p, $i);
				$st = in_array(self::up($p[$k] ?? ['', '']), ['STORED', 'PERSISTENT'], true) ? 'STORED' : 'VIRTUAL';
				if (in_array(self::up($p[$k] ?? ['', '']), ['STORED', 'PERSISTENT', 'VIRTUAL'], true)) $i = $k + 1;
				$out[] = "GENERATED ALWAYS AS $expr $st";
				continue;
			}
			if ($u === 'CHECK') { $j = self::next($p, $i + 1); if (($p[$j][1] ?? '') === '(') { $c = self::close($p, $j); $out[] = 'CHECK ' . self::dml(array_slice($p, $j, $c - $j + 1)); $i = $c + 1; continue; } }
			if ($u === 'REFERENCES') { // a foreign key of the column: left out (as MyISAM; nuBuilder Forte has none)
				$i++; while ($i < $n && !in_array(self::up($p[$i]), ['NOT', 'NULL', 'DEFAULT', 'CHECK', 'COMMENT'], true)) $i++; continue; }
			if ($u === 'NOT' || $u === 'NULL') { $out[] = $u; $i++; continue; }
			if ($u === 'SRID' || $u === 'STORAGE' || $u === 'COLUMN_FORMAT') { $i += 2; continue; }
			$out[] = $tok[1]; $i++;
		}
		$def = trim(($type !== '' ? $type : 'TEXT') . ' ' . implode(' ', $out));
		if ($text && !$binary && !str_contains(strtoupper($def), 'COLLATE')) $def .= ' COLLATE NOCASE';
		return [$name, $def, $auto, $pk, $text && !$binary];
	}

	/** CREATE TABLE in MySQL's form → SQLite (KEY lines as indexes, table options dropped, AUTO_INCREMENT as INTEGER PRIMARY KEY). */
	private static function createTable(NuDB $db, array $t): string
	{
		$i = self::next($t, 0);
		$head = [];
		$n = count($t);
		// head: CREATE [TEMPORARY] TABLE [IF NOT EXISTS] name
		$j = $i;
		$temp = false;
		$ifNot = '';
		for (; $j < $n; $j++) {
			$u = self::up($t[$j]);
			if ($t[$j][0] === ' ' || $t[$j][0] === 'c') continue;
			if ($u === 'CREATE' || $u === 'TABLE') continue;
			if ($u === 'TEMPORARY' || $u === 'TEMP') { $temp = true; continue; }
			if ($u === 'IF') { $j = self::next($t, self::next($t, $j + 1) + 1); $ifNot = 'IF NOT EXISTS '; continue; }
			break;
		}
		$name = self::name(preg_replace('/^.*\./', '', $t[$j][1] ?? '') ?? '');
		// a schema prefix `db`.`t`
		$k = self::next($t, $j + 1);
		if (($t[$k][1] ?? '') === '.') { $j = self::next($t, $k + 1); $name = self::name($t[$j][1] ?? ''); $k = self::next($t, $j + 1); }
		$headSql = 'CREATE ' . ($temp ? 'TEMP ' : '') . 'TABLE ' . $ifNot . self::qn($name);
		// CREATE TABLE a LIKE b
		if (self::up($t[$k] ?? ['', '']) === 'LIKE' || (($t[$k][1] ?? '') === '(' && self::up($t[self::next($t, $k + 1)] ?? ['', '']) === 'LIKE')) {
			$x = self::up($t[$k]) === 'LIKE' ? self::next($t, $k + 1) : self::next($t, self::next($t, $k + 1) + 1);
			$src = self::name($t[$x][1] ?? '');
			$def = (string)$db->value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$src]);
			if ($def === '') throw new RuntimeException("Table '$src' doesn't exist");
			self::run((string)preg_replace('/^CREATE\s+TABLE\s+("(?:[^"]|"")*"|`[^`]*`|\[[^\]]*\]|\S+)/i', $headSql, $def));
			foreach ($db->all("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$src]) as $ix) {
				$new = $name . '__' . preg_replace('/^' . preg_quote($src . '__', '/') . '/', '', (string)$ix['name']);
				self::run((string)preg_replace('/^CREATE\s+(UNIQUE\s+)?INDEX\s+("(?:[^"]|"")*"|\S+)\s+ON\s+("(?:[^"]|"")*"|`[^`]*`|\S+)/i', 'CREATE $1INDEX ' . self::qn($new) . ' ON ' . self::qn($name), (string)$ix['sql']));
			}
			return self::NOTHING;
		}
		// CREATE TABLE t [AS] SELECT … (also with options in between)
		if (($t[$k][1] ?? '') !== '(' || in_array(self::up($t[self::next($t, $k + 1)] ?? ['', '']), ['SELECT', 'WITH'], true)) {
			$x = $k;
			while ($x < $n && !in_array(self::up($t[$x]), ['SELECT', 'WITH'], true) && ($t[$x][1] ?? '') !== '(') $x++;
			if ($x >= $n) return $headSql . ' (x)';
			if (($t[$x][1] ?? '') === '(') {                     // AS (SELECT …): SQLite wants the SELECT without the brackets
				$cx = self::close($t, $x);
				$sel = self::dml(array_merge(array_slice($t, $x + 1, $cx - $x - 1), array_slice($t, $cx + 1)));
			} else {
				$sel = self::dml(array_slice($t, $x));
			}
			return $headSql . ' AS ' . $sel;
		}
		$c = self::close($t, $k);
		$parts = self::splitTop(array_slice($t, $k + 1, $c - $k - 1));
		$cols = []; $pkCols = []; $indexes = []; $checks = []; $autoCol = '';
		foreach ($parts as $p) {
			$pp = array_values(array_filter($p, static fn($x) => $x[0] !== ' ' && $x[0] !== 'c'));
			if (!$pp) continue;
			$u0 = self::up($pp[0]);
			if ($u0 === 'CONSTRAINT') { array_shift($pp); if ($pp && !in_array(self::up($pp[0]), ['PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK'], true)) array_shift($pp); $u0 = $pp ? self::up($pp[0]) : ''; $p = $pp; }
			if ($u0 === 'PRIMARY') { $x = 0; while ($x < count($pp) && $pp[$x][1] !== '(') $x++; $cc = self::close($pp, $x);
				foreach (self::splitTop(array_slice($pp, $x + 1, $cc - $x - 1)) as $q) { $q = array_values(array_filter($q, static fn($y) => $y[0] !== ' ')); $pkCols[] = self::name($q[0][1]); } continue; }
			if (in_array($u0, ['KEY', 'INDEX', 'UNIQUE', 'FULLTEXT', 'SPATIAL'], true)) {
				$x = 0; while ($x < count($pp) && $pp[$x][1] !== '(') $x++;
				$ixName = '';
				foreach (array_slice($pp, 1, max(0, $x - 1)) as $y) if (($y[0] === 'w' && !in_array(self::up($y), ['KEY', 'INDEX', 'USING', 'BTREE', 'HASH'], true)) || $y[0] === 'q') $ixName = self::name($y[1]);
				$cc = self::close($pp, $x);
				$colsSql = self::indexCols(array_slice($pp, $x + 1, $cc - $x - 1));
				if ($ixName === '') $ixName = trim((string)preg_replace('/\W+/', '_', $colsSql), '_');
				$indexes[] = [$u0 === 'UNIQUE', $ixName, $colsSql];
				continue;
			}
			if ($u0 === 'FOREIGN') continue;
			if ($u0 === 'CHECK') { $checks[] = 'CHECK ' . self::dml(array_slice($pp, 1)); continue; }
			[$cn, $def, $auto, $pk] = self::column($p);
			if ($pk) $pkCols[] = $cn;
			if ($auto) $autoCol = $cn;
			$cols[$cn] = $def;
		}
		// AUTO_INCREMENT on the only key column: SQLite's rowid key
		if ($autoCol !== '' && (count($pkCols) === 0 || (count($pkCols) === 1 && strcasecmp($pkCols[0], $autoCol) === 0))) {
			$cols[$autoCol] = 'INTEGER PRIMARY KEY AUTOINCREMENT' . (preg_match('/\bNOT NULL\b/i', $cols[$autoCol]) ? ' NOT NULL' : '');
			$pkCols = [];
		}
		$lines = [];
		foreach ($cols as $cn => $def) {
			$def = (string)preg_replace('/\s+UNIQUE\b/i', '', $def, -1, $uq);
			if ($uq) $indexes[] = [true, $cn, self::qn($cn)];
			$lines[] = self::qn($cn) . ' ' . $def;
		}
		if ($pkCols) $lines[] = 'PRIMARY KEY (' . implode(', ', array_map([self::class, 'qn'], $pkCols)) . ')';
		foreach ($checks as $ck) $lines[] = $ck;
		$create = $headSql . " (\n  " . implode(",\n  ", $lines) . "\n)";
		if ($ifNot !== '' && $db->tableExists($name)) return self::NOTHING;
		self::run($create);
		foreach ($indexes as [$unique, $ixName, $colsSql]) {
			self::run('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . self::qn($name . '__' . $ixName) . ' ON ' . self::qn($name) . " ($colsSql)");
		}
		return self::NOTHING;
	}

	/** ALTER TABLE t spec, spec … */
	private static function alter(NuDB $db, array $t): void
	{
		$i = self::next($t, 0);
		$n = count($t);
		$j = $i;
		for (; $j < $n; $j++) { $u = self::up($t[$j]); if ($t[$j][0] === ' ' || in_array($u, ['ALTER', 'TABLE', 'IGNORE', 'ONLINE'], true)) continue; break; }
		$table = self::name($t[$j][1] ?? '');
		$k = self::next($t, $j + 1);
		if (($t[$k][1] ?? '') === '.') { $j = self::next($t, $k + 1); $table = self::name($t[$j][1] ?? ''); }
		$specs = self::splitTop(array_slice($t, $j + 1));
		foreach ($specs as $sp) {
			$sp = array_values(array_filter($sp, static fn($x) => $x[0] !== 'c'));
			$pw = array_values(array_filter($sp, static fn($x) => $x[0] !== ' '));
			if (!$pw) continue;
			$u0 = self::up($pw[0]); $u1 = self::up($pw[1] ?? ['', '']); $u2 = self::up($pw[2] ?? ['', '']);
			if ($u0 === 'ADD') {
				$rest = array_slice($pw, 1);
				if ($u1 === 'CONSTRAINT') { $rest = array_slice($pw, 2); if ($rest && !in_array(self::up($rest[0]), ['PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK'], true)) $rest = array_slice($rest, 1); $u1 = $rest ? self::up($rest[0]) : ''; $u2 = self::up($rest[1] ?? ['', '']); }
				if ($u1 === 'PRIMARY') {
					$cols = self::listIn($rest);
					self::rebuild($db, $table, static function (array &$c, array &$pk) use ($cols) { $pk = $cols; });
					continue;
				}
				if (in_array($u1, ['KEY', 'INDEX', 'UNIQUE', 'FULLTEXT', 'SPATIAL'], true)) {
					$x = 0; while ($x < count($rest) && $rest[$x][1] !== '(') $x++;
					$ixName = '';
					foreach (array_slice($rest, 1, max(0, $x - 1)) as $y) if (($y[0] === 'w' && !in_array(self::up($y), ['KEY', 'INDEX', 'USING', 'BTREE', 'HASH'], true)) || $y[0] === 'q') $ixName = self::name($y[1]);
					$cc = self::close($rest, $x);
					$colsSql = self::indexCols(array_slice($rest, $x + 1, $cc - $x - 1));
					if ($ixName === '') $ixName = trim((string)preg_replace('/\W+/', '_', $colsSql), '_');
					self::run('CREATE ' . ($u1 === 'UNIQUE' ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . self::qn($table . '__' . $ixName) . ' ON ' . self::qn($table) . " ($colsSql)");
					continue;
				}
				if ($u1 === 'FOREIGN' || $u1 === 'CHECK' || $u1 === 'PARTITION') continue;
				// ADD [COLUMN] [IF NOT EXISTS] col def [FIRST | AFTER x]   or   ADD [COLUMN] (col def, col def)
				$cs = $rest;
				if ($cs && self::up($cs[0]) === 'COLUMN') $cs = array_slice($cs, 1);
				$ifNot = false;
				if ($cs && self::up($cs[0]) === 'IF') { $cs = array_slice($cs, 3); $ifNot = true; }
				$list = ($cs && $cs[0][1] === '(') ? self::splitTop(array_slice($cs, 1, self::close($cs, 0) - 1)) : [self::withSpaces($sp, $cs)];
				foreach ($list as $one) {
					$one = self::cutPosition($one);
					[$cn, $def, $auto, $pk] = self::column($one);
					$have = array_change_key_case($db->columns($table));
					if (isset($have[strtolower($cn)])) { if ($ifNot) continue; }
					$plain = !$auto && !$pk && !preg_match('/\bNOT NULL\b/i', $def) || preg_match('/\bDEFAULT\b/i', $def);
					if ($plain && !$auto && !$pk && !preg_match('/\bUNIQUE\b/i', $def) && !preg_match('/\bSTORED\b/i', $def)) {
						self::run('ALTER TABLE ' . self::qn($table) . ' ADD COLUMN ' . self::qn($cn) . ' ' . $def);
					} else {
						self::rebuild($db, $table, static function (array &$c, array &$pkc) use ($cn, $def, $auto, $pk) {
							$c[] = ['name' => $cn, 'def' => $def, 'new' => true];
							if ($pk || $auto) $pkc = [$cn];
						}, $auto ? $cn : '');
					}
					NuDB::forgetColumns($table);
				}
				continue;
			}
			if ($u0 === 'MODIFY' || $u0 === 'CHANGE') {
				$cs = array_slice($pw, 1);
				if ($cs && self::up($cs[0]) === 'COLUMN') $cs = array_slice($cs, 1);
				$old = self::name($cs[0][1] ?? '');
				$defTokens = $u0 === 'CHANGE' ? self::withSpaces($sp, array_slice($cs, 1)) : self::withSpaces($sp, $cs);
				$defTokens = self::cutPosition($defTokens);
				[$cn, $def, $auto, $pk] = self::column($defTokens);
				self::rebuild($db, $table, static function (array &$c, array &$pkc) use ($old, $cn, $def, $pk) {
					foreach ($c as &$col) if (strcasecmp($col['name'], $old) === 0) { $col['def'] = $def; $col['to'] = $cn; }
					unset($col);
					foreach ($pkc as &$x) if (strcasecmp($x, $old) === 0) $x = $cn;
					unset($x);
					if ($pk) $pkc = [$cn];
				}, $auto ? $cn : '');
				continue;
			}
			if ($u0 === 'ALTER') {   // ALTER [COLUMN] c SET DEFAULT v / DROP DEFAULT
				$cs = array_slice($pw, 1);
				if ($cs && self::up($cs[0]) === 'COLUMN') $cs = array_slice($cs, 1);
				$cn = self::name($cs[0][1] ?? '');
				$set = self::up($cs[1] ?? ['', '']) === 'SET' ? self::text(array_slice($cs, 3)) : null;
				self::rebuild($db, $table, static function (array &$c) use ($cn, $set) {
					foreach ($c as &$col) {
						if (strcasecmp($col['name'], $cn) !== 0) continue;
						$col['def'] = trim((string)preg_replace("/\\s+DEFAULT\\s+(\\([^)]*\\)|'(?:[^']|'')*'|\\S+)/i", '', $col['def']));
						if ($set !== null) $col['def'] .= ' DEFAULT ' . ($set[0] === '(' || $set[0] === "'" || is_numeric($set) || strtoupper($set) === 'NULL' ? $set : self::lit($set));
					}
				});
				continue;
			}
			if ($u0 === 'DROP') {
				if ($u1 === 'PRIMARY') { self::rebuild($db, $table, static function (array &$c, array &$pk) { $pk = []; }); continue; }
				if ($u1 === 'INDEX' || $u1 === 'KEY') { $ix = self::name($pw[2][1] ?? ''); self::run('DROP INDEX IF EXISTS ' . self::qn(self::indexName($db, $table, $ix))); continue; }
				if ($u1 === 'FOREIGN' || $u1 === 'CONSTRAINT' || $u1 === 'CHECK' || $u1 === 'PARTITION') continue;
				$cn = self::name(($u1 === 'COLUMN' ? ($u2 === 'IF' ? ($pw[4][1] ?? '') : ($pw[2][1] ?? '')) : ($pw[1][1] ?? '')));
				$have = array_change_key_case($db->columns($table));
				if (!isset($have[strtolower($cn)])) { if ($u2 === 'IF') continue; throw new RuntimeException("Can't DROP COLUMN `$cn`; check that it exists"); }
				// indexes on the column go first (SQLite refuses to drop an indexed column)
				foreach ($db->all("SELECT il.name AS n FROM pragma_index_list(?) il JOIN pragma_index_info(il.name) ii WHERE ii.name = ? COLLATE NOCASE AND il.origin = 'c'", [$table, $cn]) as $r) self::run('DROP INDEX IF EXISTS ' . self::qn((string)$r['n']));
				try {
					self::run('ALTER TABLE ' . self::qn($table) . ' DROP COLUMN ' . self::qn($cn));
				} catch (Throwable) {
					self::rebuild($db, $table, static function (array &$c, array &$pk) use ($cn) {
						$c = array_values(array_filter($c, static fn($x) => strcasecmp($x['name'], $cn) !== 0));
						$pk = array_values(array_filter($pk, static fn($x) => strcasecmp($x, $cn) !== 0));
					});
				}
				NuDB::forgetColumns($table);
				continue;
			}
			if ($u0 === 'RENAME') {
				if ($u1 === 'COLUMN') { self::run('ALTER TABLE ' . self::qn($table) . ' RENAME COLUMN ' . self::qn(self::name($pw[2][1] ?? '')) . ' TO ' . self::qn(self::name($pw[4][1] ?? ''))); NuDB::forgetColumns($table); continue; }
				if ($u1 === 'INDEX' || $u1 === 'KEY') continue;
				$to = self::name(($u1 === 'TO' || $u1 === 'AS') ? ($pw[2][1] ?? '') : ($pw[1][1] ?? ''));
				self::run('PRAGMA legacy_alter_table = ON');
				try { self::run('ALTER TABLE ' . self::qn($table) . ' RENAME TO ' . self::qn($to)); } finally { self::run('PRAGMA legacy_alter_table = OFF'); }
				$table = $to;
				continue;
			}
			// ENGINE=…, AUTO_INCREMENT=…, DEFAULT CHARSET …, CONVERT TO CHARACTER SET …, COMMENT …, ORDER BY …, ALGORITHM=…, LOCK=…
		}
	}

	/** The tokens of $part (without spaces) back with their spaces, from the first one of $from on. */
	private static function withSpaces(array $all, array $from): array
	{
		if (!$from) return [];
		$first = $from[0];
		foreach ($all as $k => $tok) if ($tok === $first) return array_slice($all, $k);
		return $from;
	}

	/** FIRST / AFTER col at the end of a column definition – SQLite adds columns at the end. */
	private static function cutPosition(array $p): array
	{
		$n = count($p);
		for ($i = $n - 1; $i >= 0; $i--) {
			if ($p[$i][0] === ' ') continue;
			$u = self::up($p[$i]);
			if ($u === 'FIRST') return array_slice($p, 0, $i);
			$pv = self::prev($p, $i - 1);
			if ($pv >= 0 && self::up($p[$pv]) === 'AFTER') return array_slice($p, 0, $pv);
			break;
		}
		return $p;
	}

	/** Column names of "PRIMARY KEY (a, b)". */
	private static function listIn(array $pw): array
	{
		$x = 0; while ($x < count($pw) && $pw[$x][1] !== '(') $x++;
		$cc = self::close($pw, $x);
		$out = [];
		foreach (self::splitTop(array_slice($pw, $x + 1, $cc - $x - 1)) as $q) {
			$q = array_values(array_filter($q, static fn($y) => $y[0] !== ' '));
			if ($q) $out[] = self::name($q[0][1]);
		}
		return $out;
	}

	/**
	 * A table built again – how SQLite changes a column or a primary key: new table, rows copied, old one dropped, the new one renamed,
	 * indexes back. $change gets the columns [['name', 'def'] …] and the key columns and may change them ('to' = new name, 'new' = added).
	 */
	public static function rebuild(NuDB $db, string $table, callable $change, string $autoCol = ''): void
	{
		$info = $db->all('SELECT * FROM pragma_table_info(?) ORDER BY cid', [$table]);
		if (!$info) throw new RuntimeException("Table '$table' doesn't exist");
		/** @var list<array{name: string, def: string, to?: string, new?: bool}> $cols */
		$cols = []; $pk = [];
		$pkOrder = [];
		foreach ($info as $r) {
			$def = ($r['type'] !== '' ? $r['type'] : 'TEXT');
			if ((int)$r['notnull'] === 1) $def .= ' NOT NULL';
			if ($r['dflt_value'] !== null) {
				$dv = (string)$r['dflt_value'];
				$plain = preg_match("/^('(?:[^']|'')*'|[-+]?\\d+(\\.\\d+)?|NULL|TRUE|FALSE|CURRENT_(TIMESTAMP|DATE|TIME)|X'[0-9A-Fa-f]*')$/i", $dv);
				$def .= ' DEFAULT ' . ($plain ? $dv : '(' . $dv . ')');
			}
			if (preg_match('/CHAR|TEXT|CLOB/i', $def) && !preg_match('/COLLATE/i', $def)) $def .= self::collationOf($db, $table, (string)$r['name']);
			$cols[] = ['name' => (string)$r['name'], 'def' => $def];
			if ((int)$r['pk'] > 0) $pkOrder[(int)$r['pk']] = (string)$r['name'];
		}
		ksort($pkOrder);
		$pk = array_values($pkOrder);
		$wasAuto = (bool)$db->value("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ? AND sql LIKE '%AUTOINCREMENT%'", [$table]);
		if ($wasAuto && count($pk) === 1 && $autoCol === '') $autoCol = $pk[0];
		$indexes = $db->all("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);
		$change($cols, $pk);
		$lines = []; $from = []; $to = [];
		foreach ($cols as $c) {
			$newName = $c['to'] ?? $c['name'];
			$def = $c['def'];
			if ($autoCol !== '' && strcasecmp($newName, $autoCol) === 0 && count($pk) <= 1) {
				$def = 'INTEGER PRIMARY KEY AUTOINCREMENT' . (preg_match('/\bNOT NULL\b/i', $def) ? ' NOT NULL' : '');
				$pk = [];
			}
			$lines[] = self::qn($newName) . ' ' . $def;
			if (empty($c['new'])) { $from[] = self::qn($c['name']); $to[] = self::qn($newName); }
		}
		if ($pk) $lines[] = 'PRIMARY KEY (' . implode(', ', array_map([self::class, 'qn'], $pk)) . ')';
		$tmp = 'nu_rebuild_' . substr(md5($table . microtime()), 0, 8);
		self::run('PRAGMA legacy_alter_table = ON');
		try {
			self::run('SAVEPOINT nu_rebuild');
			self::run('CREATE TABLE ' . self::qn($tmp) . " (\n  " . implode(",\n  ", $lines) . "\n)");
			if ($from) self::run('INSERT INTO ' . self::qn($tmp) . ' (' . implode(', ', $to) . ') SELECT ' . implode(', ', $from) . ' FROM ' . self::qn($table));
			self::run('DROP TABLE ' . self::qn($table));
			self::run('ALTER TABLE ' . self::qn($tmp) . ' RENAME TO ' . self::qn($table));
			$rename = [];
			foreach ($cols as $c) if (isset($c['to']) && $c['to'] !== $c['name']) $rename[strtolower($c['name'])] = $c['to'];
			$names = array_map(static fn($c) => strtolower($c['to'] ?? $c['name']), $cols);
			foreach ($indexes as $ix) {
				$sql = (string)$ix['sql'];
				foreach ($rename as $o => $nw) $sql = (string)preg_replace('/(?<![\w"`])("?' . preg_quote($o, '/') . '"?)(?![\w"`])(?=[^(]*\)|\s*[,)])/i', self::qn($nw), $sql);
				if (!preg_match('/\(([^)]*)\)\s*(WHERE.*)?$/is', $sql, $m)) continue;
				$ok = true;
				foreach (explode(',', $m[1]) as $col) { $cn = strtolower(trim((string)preg_replace('/\s+(ASC|DESC|COLLATE\s+\w+)\b.*$/i', '', trim($col)), ' "`[]')); if ($cn !== '' && !in_array($cn, $names, true)) $ok = false; }
				if ($ok) self::run($sql);
			}
			self::run('RELEASE nu_rebuild');
		} catch (Throwable $e) {
			try { self::run('ROLLBACK TO nu_rebuild'); self::run('RELEASE nu_rebuild'); } catch (Throwable $ig) { nu_ignored($ig); }
			throw $e;
		} finally {
			self::run('PRAGMA legacy_alter_table = OFF');
		}
		NuDB::forgetColumns($table);
	}

	/** " COLLATE NOCASE" when the column was declared so (pragma_table_info does not say). */
	private static function collationOf(NuDB $db, string $table, string $col): string
	{
		$sql = (string)$db->value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
		return preg_match('/(?:^|[(,\s])"?' . preg_quote($col, '/') . '"?\s+[^,]*?\bCOLLATE\s+(\w+)/i', $sql, $m) ? ' COLLATE ' . $m[1] : '';
	}
}
