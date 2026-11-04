<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Options of a select object (sob_select_sql), read as nuBuilder Forte 4.9 reads them (nuGetSelectType / nuSelectOptions) – the one
 * place for the form (NuRender::options, nuRefreshSelectObject), the PHP function nuSelectOptions(), translations and Form with AI
 * (0.8.879). Kinds:
 *   query      SELECT / WITH / (SELECT / EXEC / other SHOW … – also after leading "-- " comment lines or a slash-star block
 *   tables     SHOW TABLES / SHOW FULL TABLES [WHERE …]: the first column; names starting with "__" left out
 *              (SQL Server and SQLite: their catalog – BASE TABLE / VIEW in the text choose tables or views)
 *   languages  %LANGUAGES%: nuBuilder Forte's language names (core/languages/*.sql of nuBuilder Forte), else the English names of
 *              nuBuilder Next's installed languages without English (nuBuilder Forte has no file for its default language)
 *   array      a JSON list ["a", "b"] (value = text); [["v", "Text"], …] = value and text (nuBuilder Next only)
 *   list       value|text|value|text – line ends and tabs removed
 * Hash variables: bound in SQL (NuHash::sql), filled in as text in lists and JSON, as nuBuilder Forte does. A source that starts with
 * "[" but is no valid JSON after that is read as a pipe list (nuBuilder Forte). An SQL is only recognised as written: a hash
 * variable never turns a list into SQL.
 */
final class NuSelectSource
{
	/** Kind of a source (see above); '' when empty. */
	public static function kind(string $src): string
	{
		$h = self::head($src);
		if ($h === '') return '';
		if (preg_match('/^SHOW\s+(FULL\s+)?TABLES\b/i', $h)) return 'tables';
		// a key word followed by a space, "(" or "*" – so a pipe list such as "WITH|With …" stays a list
		if (preg_match('/^\(*\s*(SELECT|WITH|EXEC|EXECUTE|SHOW)(?=[\s(*])/i', $h)) return 'query';
		if (stripos($h, '%LANGUAGES%') === 0) return 'languages';
		if (str_starts_with($h, '[') && is_array(json_decode(trim($src), true))) return 'array';
		return 'list';
	}

	/** True for a list written into the object (pipe list or JSON) – no database, its texts can be translated. */
	public static function isFixed(string $src): bool
	{
		return in_array(self::kind($src), ['list', 'array'], true);
	}

	/**
	 * Options [[value, text], …]. $hash = the form's hash variables ([] when they are filled in already, as nuSelectOptions() does).
	 * $exact = nuBuilder Forte's own result (PHP function nuSelectOptions): rows of an SQL with all their columns, an odd last value
	 * gets the text 'Undefined', an empty piece after a closing "|" stays. $run(sql) = rows (PDO::FETCH_NUM) – default nu_db() with
	 * the hash variables bound. Throws on SQL errors.
	 */
	public static function options(string $src, array $hash = [], bool $exact = false, ?callable $run = null): array
	{
		$src = trim($src);
		$kind = self::kind($src);
		if ($kind === '') return [];
		$run ??= static fn(string $sql): array => nu_db()->run(NuHash::sql($sql, $hash))->fetchAll(PDO::FETCH_NUM);
		$out = [];
		switch ($kind) {
			case 'query':
				foreach ($run($src) as $r) {
					$r = array_values((array)$r);
					$out[] = $exact ? $r : [(string)($r[0] ?? ''), (string)($r[1] ?? $r[0] ?? '')];
				}
				return $out;
			case 'tables':
				foreach (self::tables($src, $run) as $t) $out[] = [$t, $t];
				return $out;
			case 'languages':
				foreach (self::languages() as $l) $out[] = [$l, $l];
				return $out;
		}
		$s = $hash ? NuHash::replace($src, $hash) : $src;
		$d = str_starts_with($s, '[') ? json_decode($s, true) : null;
		if (is_array($d)) {
			foreach ($d as $v) {
				if (is_array($v)) $out[] = [(string)($v[0] ?? ''), (string)($v[1] ?? $v[0] ?? '')];
				else $out[] = $exact ? [$v, $v] : [(string)$v, (string)$v];
			}
			return $out;
		}
		$parts = explode('|', strtr($s, ["\t" => '', "\r" => '', "\n" => '']));
		if (!$exact && count($parts) > 1 && end($parts) === '') array_pop($parts);
		for ($i = 0, $n = count($parts); $i < $n; $i += 2) $out[] = [$parts[$i], $parts[$i + 1] ?? ($exact ? 'Undefined' : $parts[$i])];
		return $out;
	}

	/** The source without leading "-- " comment lines (nuBuilder Forte) and slash-star blocks, trimmed. */
	private static function head(string $src): string
	{
		$s = preg_replace('/^[ \t]*--(?:[ \t].*)?$/m', '', $src) ?? $src;
		do {
			$before = $s;
			$s = preg_replace('~^\s*/\*.*?\*/~s', '', $s) ?? $s;
		} while ($s !== $before);
		return trim($s);
	}

	/** Names of SHOW TABLES / SHOW FULL TABLES …, without the "__" tables (nuBuilder Forte). */
	private static function tables(string $src, callable $run): array
	{
		$db = nu_db();
		if ($db->type === 'mysql') {
			$names = array_map(static fn($r) => (string)(array_values((array)$r)[0] ?? ''), $run($src));
		} else {
			$want = preg_match('/BASE\s+TABLE/i', $src) ? 'table' : (preg_match('/\bVIEW\b/i', $src) ? 'view' : '');
			$rows = $db->type === 'sqlsrv'
				? $db->all('SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES' . ($want === '' ? '' : ' WHERE TABLE_TYPE = ?') . ' ORDER BY TABLE_NAME',
					$want === '' ? [] : [$want === 'table' ? 'BASE TABLE' : 'VIEW'])
				: $db->all("SELECT name AS t FROM sqlite_master WHERE type " . ($want === '' ? "IN ('table', 'view')" : '= ?') . ' ORDER BY name', $want === '' ? [] : [$want]);
			$names = array_map(static fn($r) => (string)($r['t'] ?? $r['T'] ?? reset($r)), $rows);
		}
		return array_values(array_filter($names, static fn($t) => $t !== '' && !str_starts_with($t, '__')));
	}

	/** %LANGUAGES% – see the class comment. $forte = nuBuilder Forte's folder (tests; default NuBuilderLib::path()). */
	public static function languages(?string $forte = null): array
	{
		$names = [];
		$dir = ($forte ?? NuBuilderLib::path()) . '/core/languages';
		foreach ((is_dir($dir) ? glob($dir . '/*.sql') : []) ?: [] as $f) $names[] = basename($f, '.sql');
		if (!$names) {
			foreach (NuLanguages::installed() as $code) if ($code !== 'en' && isset(NU_LANGS[$code])) $names[] = NU_LANGS[$code][1];
		}
		sort($names, SORT_STRING | SORT_FLAG_CASE);
		return array_values(array_unique($names));
	}
}
