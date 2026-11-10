<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Browse lists, built the way nuBuilder builds them (0.8.382, nuBrowseRows() / nuSqlString in nuBuilder 4.9): FROM, WHERE, GROUP BY
 * and HAVING of sfo_browse_sql stay, the field list is replaced by the primary key and the zzzzsys_browse columns – so columns may
 * use table names and aliases (customer.name, c.name), an AS alias, joins whose tables share column names, and a SQL that does not
 * select the primary key. That query is then wrapped for search, column filters, count, sort and paging (MySQL and MSSQL):
 *   SELECT … FROM (SELECT pk AS nu_pk, <col0> AS nu_c0, …, <order0> AS nu_o0 … FROM … WHERE … GROUP BY … HAVING …) nuT WHERE … ORDER BY …
 * nuBuilder 4.9's nu_filter_match('column', '<browse id>'[, 'empty value']) is expanded first. SQL that cannot be split (UNION, WITH)
 * is wrapped as a whole, as before.
 */
final class NuBrowse
{
	public const NULL_FILTER = '__null__';

	/** Splits a trailing top-level ORDER BY (and LIMIT) off a SELECT: [sql without it, order clause]. */
	public static function splitOrder(string $sql): array
	{
		$sql    = rtrim(trim($sql), "; \n\r\t");
		$depth  = 0;
		$quote  = null;
		$orderAt = null;
		$len    = strlen($sql);
		for ($i = 0; $i < $len; $i++) {
			$c = $sql[$i];
			if ($quote !== null) {
				if ($c === '\\' && $quote !== '`') { $i++; continue; }
				if ($c === $quote) $quote = null;
				continue;
			}
			if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; continue; }
			if ($c === '(') $depth++;
			elseif ($c === ')') $depth--;
			elseif ($depth === 0 && ($c === 'O' || $c === 'o') && preg_match('/\GORDER\s+BY\b/i', $sql, $m, 0, $i)
				&& ($i === 0 || !preg_match('/\w/', $sql[$i - 1]))) {
				$orderAt = $i;
			}
		}
		if ($orderAt === null) return [$sql, ''];

		$order = trim(preg_replace('/^ORDER\s+BY\s+/i', '', substr($sql, $orderAt)));
		$order = trim(preg_replace('/\s+LIMIT\s+\d+(\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', '', $order));
		$order = preg_replace('/\b[A-Za-z_][A-Za-z0-9_]*\.([A-Za-z_][A-Za-z0-9_]*)\b/', '$1', $order);   // table.col → col (derived table)
		return [rtrim(substr($sql, 0, $orderAt)), $order];
	}

	/** The browse SQL of the last list built in this request with its hash variables filled in, and which of them were set –
	 *  for the globeadmin's form info (i). @var array{sql: string, vars: array<string, ?string>}|null */
	public static ?array $shown = null;

	public static function innerSql(NuFormMeta $meta, array $hash): string
	{
		$sql = trim((string)$meta->form['sfo_browse_sql']);
		if ($sql === '') $sql = 'SELECT * FROM ' . NuDB::ident($meta->table());
		$sql = self::expandFilterMatch($sql);
		$vars = [];
		if (preg_match_all('/#([A-Za-z_][A-Za-z0-9_]*)#/', $sql, $m)) {
			foreach (array_unique($m[1]) as $n) {
				$v = $hash[$n] ?? null;
				$vars[$n] = $v === null ? null : (is_scalar($v) ? (string)$v : (string)json_encode($v, JSON_UNESCAPED_UNICODE));
			}
		}
		self::$shown = ['sql' => NuHash::replace($sql, $hash, NuHash::SQL), 'vars' => $vars];
		return NuHash::sql($sql, $hash);   // text, or bound parameters with $nuDevSelectQueryRunParameterised (0.8.873)
	}

	/**
	 * nuBuilder 4.9's nu_filter_match('column', '<browse id>'[, 'empty value']) (nuBrowseExpandNuEqualsFilter): true while the filter
	 * #<browse id>_filter# is not set, otherwise the column must be one of its comma-separated values ('empty value', default -1, stands
	 * for an empty column). The filter is set by nuAddBrowseFilter(…).nuSearchablePopup(…) – also with multiSelect.
	 */
	public static function expandFilterMatch(string $sql): string
	{
		if (stripos($sql, 'nu_filter_match') === false) return $sql;
		$mssql = in_array((string)(nu_config()['nuConfigDBType'] ?? ''), ['sqlsrv', 'mssql'], true);   // without connecting (unit tests)
		return (string)preg_replace_callback('/nu_filter_match\s*\(\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]+)\3(?:\s*,\s*([\'"])([^\'"]*)\5)?\s*\)/i',
			static function (array $m) use ($mssql): string {
				$col = $m[2];
				$ph = '#' . $m[4] . '_filter#';
				$empty = str_replace("'", "''", isset($m[6]) && $m[6] !== '' ? $m[6] : '-1');
				if ($mssql) {
					$in = static fn(string $v): string => "CHARINDEX(',' + $v + ',', ',' + REPLACE('$ph', ' ', '') + ',') > 0";
					return "((LEFT('$ph', 1) = '#' OR LTRIM(RTRIM('$ph')) = '') OR (" . $in("'$empty'") . " AND COALESCE(CAST($col AS NVARCHAR(4000)), '') = '') OR "
						. $in("CAST($col AS NVARCHAR(4000))") . ')';
				}
				return "((LEFT('$ph', 1) = '#' OR TRIM('$ph') = '') OR (FIND_IN_SET('$empty', '$ph') > 0 AND COALESCE($col, '') = '') OR FIND_IN_SET($col, '$ph') > 0)";
			}, $sql);
	}

	/**
	 * Splits a SELECT at its top-level clauses (quotes, comments and brackets respected): ['fields' => …, 'from' => 'FROM …',
	 * 'where' => …, 'group' => …, 'having' => …, 'order' => '<items without ORDER BY>'] – null when it is no plain SELECT
	 * (UNION / INTERSECT / EXCEPT, WITH, no FROM).
	 */
	public static function splitSelect(string $sql): ?array
	{
		$sql = rtrim(trim($sql), "; \n\r\t");
		$len = strlen($sql);
		$depth = 0;
		$hits = [];
		for ($i = 0; $i < $len; $i++) {
			$c = $sql[$i];
			if ($c === "'" || $c === '"' || $c === '`' || $c === '[') {
				$end = $c === '[' ? ']' : $c;
				for ($i++; $i < $len; $i++) {
					if ($sql[$i] === '\\' && $end !== '`' && $end !== ']') { $i++; continue; }
					if ($sql[$i] === $end) { if ($i + 1 < $len && $sql[$i + 1] === $end && $end !== ']') { $i++; continue; } break; }
				}
				continue;
			}
			if ($c === '-' && ($sql[$i + 1] ?? '') === '-') { $nl = strpos($sql, "\n", $i); $i = $nl === false ? $len : $nl; continue; }
			if ($c === '/' && ($sql[$i + 1] ?? '') === '*') { $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $len : $e + 1; continue; }
			if ($c === '(') { $depth++; continue; }
			if ($c === ')') { $depth--; continue; }
			if ($depth !== 0 || !ctype_alpha($c) || ($i > 0 && (ctype_alnum($sql[$i - 1]) || $sql[$i - 1] === '_' || $sql[$i - 1] === '.'))) continue;
			if (preg_match('/\G(SELECT|FROM|WHERE|GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|OFFSET|UNION|INTERSECT|EXCEPT|WITH|FOR\s+UPDATE)\b/i', $sql, $m, 0, $i)) {
				$hits[] = [strtoupper((string)preg_replace('/\s+/', ' ', $m[1])), $i, strlen($m[0])];
				$i += strlen($m[0]) - 1;
			} else {
				while ($i + 1 < $len && (ctype_alnum($sql[$i + 1]) || $sql[$i + 1] === '_')) $i++;
			}
		}
		if (!$hits || $hits[0][0] !== 'SELECT' || $hits[0][1] !== 0) return null;
		$parts = [];
		$from = null;
		foreach ($hits as $k => [$kw, $pos, $l]) {
			if (in_array($kw, ['UNION', 'INTERSECT', 'EXCEPT', 'WITH'], true)) return null;
			if ($kw === 'SELECT' && $k > 0) return null;
			if ($kw === 'FROM' && $from === null) $from = $k;
		}
		if ($from === null) return null;
		$order = ['SELECT' => 'fields', 'FROM' => 'from', 'WHERE' => 'where', 'GROUP BY' => 'group', 'HAVING' => 'having', 'ORDER BY' => 'order', 'LIMIT' => 'tail', 'OFFSET' => 'tail', 'FOR UPDATE' => 'tail'];
		$seen = [];
		for ($k = 0, $nHits = count($hits); $k < $nHits; $k++) {
			[$kw, $pos, $l] = $hits[$k];
			if ($k > 0 && $k < $from) continue;                 // FROM is the first one after SELECT
			$name = $order[$kw] ?? null;
			if ($name === null || isset($seen[$name])) continue;
			$seen[$name] = true;
			$next = $hits[$k + 1][1] ?? $len;
			if ($k === 0) $next = $hits[$from][1];
			$text = trim(substr($sql, $pos, $next - $pos));
			$parts[$name] = $name === 'tail' ? $text : trim(in_array($name, ['fields', 'order'], true) ? substr($text, $l) : $text);
		}
		$parts['fields'] = (string)preg_replace('/^(DISTINCT|ALL|DISTINCTROW)\s+/i', '', $parts['fields'] ?? '');
		if (isset($parts['order'])) $parts['order'] = trim((string)preg_replace('/\s+OFFSET\s+.*$/is', '', $parts['order']));
		return $parts + ['where' => '', 'group' => '', 'having' => '', 'order' => ''];
	}

	/** Items of a comma list at the top level (brackets and quotes respected). */
	public static function splitList(string $list): array
	{
		$out = [];
		$depth = 0;
		$cur = '';
		$q = null;
		$len = strlen($list);
		for ($i = 0; $i < $len; $i++) {
			$c = $list[$i];
			if ($q !== null) { $cur .= $c; if ($c === $q) $q = null; continue; }
			if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $cur .= $c; continue; }
			if ($c === '(') $depth++;
			if ($c === ')') $depth--;
			if ($c === ',' && $depth === 0) { $out[] = trim($cur); $cur = ''; continue; }
			$cur .= $c;
		}
		if (trim($cur) !== '') $out[] = trim($cur);
		return $out;
	}

	/** [expression, alias|null] – a trailing top-level "AS alias" (nuBuilder's nuBrowseRemoveFieldAlias, but never inside CAST(… AS …)). */
	public static function stripAlias(string $item): array
	{
		$item = trim($item);
		if (preg_match('/^(.*\S)\s+AS\s+(`[^`]+`|"[^"]+"|\[[^\]]+\]|\'[^\']+\'|[A-Za-z_][A-Za-z0-9_]*)$/is', $item, $m)) {
			$expr = $m[1];
			if (substr_count($expr, '(') === substr_count($expr, ')')) return [$expr, trim($m[2], '`"[]\'')];
		}
		return [$item, null];
	}

	/**
	 * The query of the list: [inner SQL with nu_pk, nu_c<i> (the columns) and nu_o<k> (the browse SQL's own ORDER BY), outer default
	 * order on nuT ('' = none)]. $cols: list of ['index' => i, 'expr' => display expression].
	 */
	/** Comment lines ("-- …", "/* … *\/") at the end of the SQL taken off – the SQL is wrapped and continued, a line comment would swallow the rest. */
	public static function trimTrailingComments(string $sql): string
	{
		do {
			$before = $sql;
			$sql = rtrim($sql);
			$sql = (string)preg_replace('~(?:^|\s)--(?:\s[^\n\'"`]*)?$~', '', $sql);   // MySQL: "-- " with a space; no quote after it (not inside a string)
			$sql = (string)preg_replace('~/\*(?:(?!\*/).)*\*/$~s', '', $sql);
		} while ($sql !== $before);
		return $sql;
	}

	public static function build(string $sql, string $pk, array $cols, ?callable $columnsOf = null): array
	{
		$sql = self::trimTrailingComments($sql);              // "… -- note" at the end would comment out what is added after it (0.8.930)
		$p = self::splitSelect($sql);
		$pkIdent = NuDB::ident($pk ?: 'id');
		if ($p === null) {                                      // UNION / WITH …: the whole SQL as a derived table, as before 0.8.382
			[$inner, $order] = self::splitOrder($sql);
			$f = ["nuX.$pkIdent AS nu_pk"];
			foreach ($cols as $c) $f[] = $c['expr'] . ' AS nu_c' . $c['index'];
			$outer = [];
			foreach ($order !== '' ? self::splitList($order) : [] as $k => $item) {
				[$e, $dir] = self::orderItem($item);
				$f[] = $e . ' AS nu_o' . $k;
				$outer[] = 'nuT.nu_o' . $k . $dir;
			}
			return ['SELECT ' . implode(', ', $f) . " FROM ($inner) nuX", implode(', ', $outer)];
		}
		$aliases = [];
		$items = self::splitList($p['fields']);
		foreach ($items as $it) { [$e, $a] = self::stripAlias($it); if ($a !== null) $aliases[strtolower($a)] = $e; }
		$f = ["$pkIdent AS nu_pk"];
		// a column that names an alias of the field list (SELECT CONCAT(a, b) AS full_name … with the column full_name, 0.8.731, reported
		// by a tester): the field list is replaced, so the alias would not exist – its expression is used instead. Since 0.8.1059 also an
		// alias INSIDE a column's expression (IF(…, amount, ''), CONCAT(…), CAST(amount AS …)) – reported by a tester with a patch
		// (expandAliases). A real column of the tables in FROM wins, as in nuBuilder Forte, which never resolves aliases; when a source
		// cannot be checked (other database, unknown table, SELECT * of a derived table) the alias is used (0.8.1075, a tester's report:
		// the field list is replaced, so a name that is no alias fails either way); derived tables give their output names.
		$real = null;
		$realCols = function () use ($p, $columnsOf, &$real): array {
			return $real ??= self::sourceColumns($p['from'], $columnsOf) ?? [];
		};
		$isAlias = function (string $e) use ($aliases, $realCols): ?string {
			if (!$aliases) return null;
			$name = trim($e);
			if (preg_match('/^(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[A-Za-z_][A-Za-z0-9_$]*)$/', $name)) {      // the whole column is one name
				$key = strtolower(trim($name, '`"[]'));
				if (!isset($aliases[$key]) || strtolower(trim($aliases[$key], '`"[] ')) === $key) return null;
				$real = $realCols();
				return isset($real[$key]) ? null : $aliases[$key];
			}
			if (self::expandAliases($e, $aliases, null) === null) return null;                // no alias in it: no need to look at the tables
			return self::expandAliases($e, $aliases, $realCols());
		};
		foreach ($cols as $c) {
			$e = self::stripAlias($c['expr'])[0];
			$f[] = ($isAlias($e) ?? $e) . ' AS nu_c' . $c['index'];
		}
		$outer = [];
		foreach ($p['order'] !== '' ? self::splitList($p['order']) : [] as $k => $item) {
			[$e, $dir] = self::orderItem($item);
			if (isset($aliases[strtolower(trim($e, '`"[]'))])) $e = $aliases[strtolower(trim($e, '`"[]'))];      // ORDER BY an alias of the field list
			elseif (ctype_digit($e)) {                                                                          // ORDER BY 2
				$e = isset($items[(int)$e - 1]) && !str_ends_with(trim($items[(int)$e - 1]), '*') ? self::stripAlias($items[(int)$e - 1])[0] : '';
				if ($e === '') continue;
			}
			else $e = self::expandAliases($e, $aliases, null) ?? $e;                                           // ORDER BY CAST(alias AS …): MySQL takes the alias first
			$f[] = $e . ' AS nu_o' . $k;
			$outer[] = 'nuT.nu_o' . $k . $dir;
		}
		// GROUP BY / HAVING may name an alias of the field list too (MySQL allows both) – a real column wins
		if ($aliases && (($p['group'] !== '' && self::expandAliases($p['group'], $aliases, null, true) !== null)
			|| ($p['having'] !== '' && self::expandAliases($p['having'], $aliases, null, true) !== null))) {
			$rc = $realCols();
			foreach (['group', 'having'] as $part) if ($p[$part] !== '') $p[$part] = self::expandAliases($p[$part], $aliases, $rc, true) ?? $p[$part];
		}
		$inner = 'SELECT ' . implode(', ', $f) . ' ' . $p['from'] . ($p['where'] !== '' ? ' ' . $p['where'] : '') . ($p['group'] !== '' ? ' ' . $p['group'] : '')
			. ($p['having'] !== '' ? ' ' . $p['having'] : '');
		return [$inner, implode(', ', $outer)];
	}

	/**
	 * Names of the field list's aliases inside an expression replaced by their expressions (in brackets unless one name / call / bracket):
	 * IF(t.flag = 1, total, 0) → IF(t.flag = 1, (t.a + t.b), 0). Left alone: text in quotes ('…', "…"), qualified names (x.total,
	 * total.x), function names (total( …), the word after AS / USING (CAST(x AS date)), after a number (INTERVAL 1 day) or after ")",
	 * an alias that is a real column ($real: lower-case names; null = aliases always win, as in MySQL's ORDER BY) and an alias that
	 * only names itself. $skipKeyword: the expression is a clause that begins with a key word (GROUP BY …, HAVING …). null = no change.
	 * (0.8.1059, from a tester's patch)
	 */
	public static function expandAliases(string $e, array $aliases, ?array $real, bool $skipKeyword = false): ?string
	{
		if (!$aliases) return null;
		$out = '';
		$changed = false;
		$len = strlen($e);
		$prev = '';          // last token outside quotes: a word (lower case), '.', ')', '0' (number) or another character
		for ($i = 0; $i < $len; $i++) {
			$c = $e[$i];
			if ($c === "'" || $c === '"') {                     // text: up to the closing quote ('' / \' inside)
				$j = $i + 1;
				while ($j < $len) {
					if ($e[$j] === '\\') { $j += 2; continue; }
					if ($e[$j] === $c) { if (($e[$j + 1] ?? '') === $c) { $j += 2; continue; } break; }
					$j++;
				}
				$out .= substr($e, $i, $j - $i + 1);
				$i = $j;
				$prev = 'q';
				continue;
			}
			$word = null;
			$raw = '';
			if ($c === '`' || $c === '[') {                      // quoted name
				$close = $c === '`' ? '`' : ']';
				$j = strpos($e, $close, $i + 1);
				if ($j === false) { $out .= substr($e, $i); break; }
				$raw = substr($e, $i, $j - $i + 1);
				$word = substr($raw, 1, -1);
			} elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $e, $m, 0, $i)) {
				$raw = $word = $m[0];
			} elseif (preg_match('/\G[0-9]+(?:\.[0-9]+)?/', $e, $m, 0, $i)) {
				$out .= $m[0];
				$i += strlen($m[0]) - 1;
				$prev = '0';
				continue;
			}
			if ($word === null) {
				$out .= $c;
				if (!ctype_space($c)) $prev = $c;
				continue;
			}
			$rest = ltrim(substr($e, $i + strlen($raw)));
			$key = strtolower($word);
			$use = isset($aliases[$key]) && strtolower(trim($aliases[$key], '`"[] ')) !== $key
				&& !in_array($prev, ['.', ')', '0', 'as', 'using'], true) && ($rest === '' || ($rest[0] !== '.' && $rest[0] !== '('))
				&& ($real === null || !isset($real[$key])) && !($skipKeyword && $out === '');
			if ($use) {
				$x = trim($aliases[$key]);
				$out .= self::isAtom($x) ? $x : '(' . $x . ')';
				$changed = true;
			} else {
				$out .= $raw;
			}
			$i += strlen($raw) - 1;
			$prev = $key;
		}
		return $changed ? $out : null;
	}

	/** One name, a qualified name, a number, a function call or one bracket around everything – needs no extra brackets. */
	private static function isAtom(string $x): bool
	{
		if (preg_match('/^(?:`[^`]+`|\[[^\]]+\]|[A-Za-z_][A-Za-z0-9_$]*)(?:\.(?:`[^`]+`|\[[^\]]+\]|[A-Za-z_][A-Za-z0-9_$]*))*$/', $x)) return true;
		if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $x)) return true;
		$k = preg_match('/^[A-Za-z_][A-Za-z0-9_$]*\s*\(/', $x, $m) ? strlen($m[0]) - 1 : ($x !== '' && $x[0] === '(' ? 0 : -1);
		if ($k < 0) return false;
		$depth = 0;
		$q = null;
		$n = strlen($x);
		for ($i = $k; $i < $n; $i++) {
			$c = $x[$i];
			if ($q !== null) { if ($c === '\\') $i++; elseif ($c === $q) $q = null; continue; }
			if ($c === "'" || $c === '"' || $c === '`') { $q = $c; continue; }
			if ($c === '(') $depth++;
			elseif ($c === ')' && --$depth === 0) return $i === $n - 1;
		}
		return false;
	}

	/**
	 * Columns of the sources in a FROM clause (lower-case names as keys), or null when one source cannot be checked: a qualified name
	 * (other database / schema), a table without columns (unknown) or a derived table with SELECT *. A derived table
	 * ("JOIN (SELECT … ) x") gives the output names of its SELECT (0.8.731; derived tables 0.8.1075).
	 */
	public static function sourceColumns(string $from, ?callable $columnsOf = null): ?array
	{
		$out = [];
		$rest = '';
		$len = strlen($from);
		for ($i = 0; $i < $len; $i++) {                      // take out "( … )" with its output names, quotes respected
			$c = $from[$i];
			if ($c === "'" || $c === '"' || $c === '`') {
				$j = $i + 1;
				while ($j < $len && $from[$j] !== $c) $j += $from[$j] === '\\' ? 2 : 1;
				$rest .= substr($from, $i, $j - $i + 1);
				$i = $j;
				continue;
			}
			if ($c !== '(') { $rest .= $c; continue; }
			$depth = 0;
			for ($j = $i; $j < $len; $j++) {
				if ($from[$j] === "'" || $from[$j] === '"' || $from[$j] === '`') { $q = $from[$j]; for ($j++; $j < $len && $from[$j] !== $q; $j++) if ($from[$j] === '\\') $j++; continue; }
				if ($from[$j] === '(') $depth++;
				elseif ($from[$j] === ')' && --$depth === 0) break;
			}
			$inner = substr($from, $i + 1, $j - $i - 1);
			if (preg_match('/(?:\bFROM|\bJOIN|,)\s*$/i', $rest)) {
				$sp = self::splitSelect($inner);
				if ($sp === null) return null;
				foreach (self::splitList($sp['fields']) as $it) {
					[$e, $a] = self::stripAlias($it);
					if ($a === null) {
						if (str_ends_with(trim($e), '*')) return null;
						if (!preg_match('/([A-Za-z_][A-Za-z0-9_$]*)[`"\]]?\s*$/', $e, $mm)) continue;
						$a = $mm[1];
					}
					$out[strtolower(trim($a, '`"[]'))] = true;
				}
				$rest .= ' nu_derived';
			} else $rest .= ' ';                              // ON (…) / USING (…) / functions
			$i = $j;
		}
		$id = '(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[A-Za-z_][A-Za-z0-9_$]*)';
		preg_match_all('/(?:\bFROM|\bJOIN|,)\s+(' . $id . '(?:\.' . $id . ')*)/i', $rest, $m);
		$tables = array_diff(array_unique($m[1]), ['nu_derived']);
		if (!$tables && !$out) return null;
		$columnsOf ??= static function (string $t): array {
			try { return array_keys(nu_db()->columns($t)); } catch (Throwable) { return []; }
		};
		foreach ($tables as $t) {
			if (str_contains($t, '.')) return null;
			$cols = $columnsOf(trim($t, '`"[]'));
			if (!$cols) return null;
			foreach ($cols as $c) $out[strtolower((string)$c)] = true;
		}
		return $out;
	}

	/** "expr DESC" → [expr, ' DESC'] */
	private static function orderItem(string $item): array
	{
		if (preg_match('/^(.*\S)\s+(ASC|DESC)$/is', trim($item), $m)) return [$m[1], ' ' . strtoupper($m[2])];
		return [trim($item), ''];
	}

	/**
	 * The list's columns (title, expression with hash values, format, filter, badge, click action), keyed by browse index; also used
	 * for the empty list shown when the browse SQL fails (emptyResult).
	 * @return array<int, array<string, mixed>>
	 */
	public static function columns(NuFormMeta $meta, array $hash): array
	{
		$cols = [];
		foreach ($meta->browse as $i => $b) {
			// column visibility / field data access (0.8.480, NuBrowseAccess): a hidden column is never selected, searched, filtered or
			// sorted; a masked one is shown masked and is not searched, filtered or sorted. Columns keep their index (nu_c<index>).
			$state = NuBrowseAccess::state($meta, $b);
			if ($state === 'hide') continue;
			$expr = trim(NuHash::sql((string)$b['sbr_display'], $hash));   // nuBuilder replaces hash variables in the columns too (bound: 0.8.873)
			if ($expr === '' || strtolower($expr) === 'null') $expr = 'NULL';
			$cols[$i] = [
				'mask' => str_starts_with($state, 'mask:') ? substr($state, 5) : null,
				'index' => $i, 'title' => (string)$b['sbr_title'], 'expr' => $expr, 'align' => (string)($b['sbr_align'] ?: 'l'),
				'format' => (string)$b['sbr_format'], 'width' => (int)$b['sbr_width'], 'id' => (string)$b['zzzzsys_browse_id'],
				'filter' => !empty(NuFormMeta::nunextJson($b['sbr_json'] ?? null)['filter']) && $expr !== 'NULL' && !str_starts_with($state, 'mask:'),
				'badge' => str_starts_with($state, 'mask:') ? null : NuFormMeta::badgeOf($b['sbr_json'] ?? null),   // design mode's Badge (0.8.688)
				'action' => str_starts_with($state, 'mask:') ? null : NuFormMeta::actionOf($b['sbr_json'] ?? null),   // On click (0.8.872)
			];
		}

		return $cols;
	}

	/** As nuBuilder Forte when the browse SQL fails: the column titles and no rows (the error goes to Debug results). */
	public static function emptyResult(NuFormMeta $meta, array $hash, int $per): array
	{
		try { $cols = self::columns($meta, $hash); } catch (Throwable) { $cols = []; }
		return ['columns' => $cols, 'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per' => $per];
	}

	/**
	 * @param array $opt search, runfilter, sort (column index), dir, page, per, filter => [sql on nuT, params]
	 */
	public static function query(NuFormMeta $meta, array $hash, array $opt = []): array
	{
		$db = nu_db();

		$cols = self::columns($meta, $hash);

		[$inner, $defaultOrder] = self::build(self::innerSql($meta, $hash), $meta->primaryKey(), $cols);
		$col = static fn(array $c): string => 'nuT.nu_c' . $c['index'];   // search, filters and sort work on the columns of the list query

		$where  = [];
		$params = [];
		$search = trim((string)($opt['search'] ?? ''));
		if ($search !== '') {
			// nuBuilder's syntax (0.8.427): words and "phrases" must each be found in one of the searched columns; -word and -"phrase"
			// must be in none of them (an empty column counts as not containing it – nuBuilder's NOT LIKE drops such rows)
			$terms = self::terms($search);
			$searched = array_filter($cols, static fn($c) => $c['width'] > 0 && $c['expr'] !== 'NULL' && $c['mask'] === null && !in_array($c['index'], (array)($opt['nosearch'] ?? []), true)
				&& (empty($opt['searchonly']) || in_array($c['index'], (array)$opt['searchonly'], true)));
			foreach (['in' => ' OR ', 'out' => ' AND '] as $kind => $glue) {
				foreach ($terms[$kind] as $term) {
					$part = [];
					foreach ($searched as $c) {
						$texts = [self::castText($col($c))];
						if (str_starts_with(strtoupper($c['format']), 'D|')) $texts[] = self::dateText($col($c), substr($c['format'], 2));
						foreach ($texts as $tx) {
							$part[] = $kind === 'in' ? "$tx LIKE ? ESCAPE '!'" : "COALESCE($tx, '') NOT LIKE ? ESCAPE '!'";
							$params[] = NuDB::like($term);
						}
					}
					if ($part) $where[] = '(' . implode($glue, $part) . ')';
				}
			}
		}
		$runFilter = trim((string)($opt['runfilter'] ?? ''));
		if ($runFilter !== '') {
			// run filter (sob_run_filter / nuForm filter, 0.8.974): as nuBuilder Forte's nuBrowseWhereClause("$filter $search") – each word
			// must be found in the primary key or in any column, hidden ones (width 0) included; nosearch / searchonly do not apply
			$terms = self::terms($runFilter);
			$fields = ['nuT.nu_pk'];
			foreach ($cols as $c) if ($c['expr'] !== 'NULL' && $c['mask'] === null) $fields[] = $col($c);
			foreach (['in' => ' OR ', 'out' => ' AND '] as $kind => $glue) {
				foreach ($terms[$kind] as $term) {
					$part = [];
					foreach ($fields as $f) {
						$tx = self::castText($f);
						$part[] = $kind === 'in' ? "$tx LIKE ? ESCAPE '!'" : "COALESCE($tx, '') NOT LIKE ? ESCAPE '!'";
						$params[] = NuDB::like($term);
					}
					$where[] = '(' . implode($glue, $part) . ')';
				}
			}
		}
		foreach ((array)($opt['filters'] ?? []) as $idx => $val) {           // column filters (builder option "Filter")
			$c = $cols[(int)$idx] ?? null;
			if (!$c || !$c['filter'] || !is_string($val) || $val === '') continue;
			if ($val === self::NULL_FILTER) { $where[] = '(' . $col($c) . ') IS NULL'; continue; }
			$where[] = self::castText($col($c)) . ' = ?';
			$params[] = $val;
		}
		if (!empty($opt['adv'])) {                                            // advanced search (0.8.719, NuAdvSearch)
			[$aw, $ap] = NuAdvSearch::where($cols, $opt['adv'], $col);
			if ($aw !== '') { $where[] = $aw; array_push($params, ...$ap); }
		}
		if (!empty($opt['filter'])) {
			$where[] = '(' . $opt['filter'][0] . ')';
			array_push($params, ...$opt['filter'][1]);
		}

		$from  = " FROM ($inner) nuT" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
		$total = (int)$db->value('SELECT COUNT(*)' . $from, $params);

		$per   = max(1, (int)($opt['per'] ?? 0) ?: ((int)$meta->form['sfo_browse_rows_per_page'] ?: 20));
		if (!empty($opt['all'])) $per = max(1, min((int)$opt['all'], max($total, 1)));
		$pages = max(1, (int)ceil($total / $per));
		$page  = min(max(1, (int)($opt['page'] ?? 1)), $pages);

		$select = 'SELECT nuT.nu_pk';
		foreach ($cols as $c) $select .= ', ' . $col($c);

		$sort = $opt['sort'] ?? null;
		$dir  = strtolower((string)($opt['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
		if ($sort !== null && $sort !== '' && isset($cols[(int)$sort]) && $cols[(int)$sort]['mask'] === null) {
			$order = 'nu_c' . (int)$sort . " $dir";
		} else {
			$order = $defaultOrder;
		}
		// no ORDER BY in the browse SQL and no column sorted: MySQL keeps the table's own order, as nuBuilder Forte (LIMIT without
		// ORDER BY) – e.g. a Before Browse table made with CREATE TABLE … SELECT … ORDER BY (0.8.956); SQL Server needs one for OFFSET
		if ($order !== '') $order .= ', nu_pk';
		elseif ($db->type !== 'mysql') $order = 'nu_pk';

		$rows = [];
		$fetched = $db->page($select . $from, $params, $order, $per, ($page - 1) * $per);
		// nuBuilder Forte's nuOnProcessBrowseRows() in Before Browse (0.8.958): the page's rows as [pk, column 0, column 1 …] before formatting
		if (isset($opt['process']) && is_callable($opt['process']) && $fetched) {
			$width = $cols ? max(array_map(static fn($c) => (int)$c['index'], $cols)) + 1 : 0;
			$raw = [];
			foreach ($fetched as $r) {
				$x = array_fill(0, $width + 1, null);
				$x[0] = $r['nu_pk'];
				foreach ($cols as $c) $x[(int)$c['index'] + 1] = $r['nu_c' . $c['index']];
				$raw[] = $x;
			}
			$fetched = [];
			foreach ((array)($opt['process'])($raw) as $x) {
				$x = array_values((array)$x);
				$r = ['nu_pk' => $x[0] ?? ''];
				foreach ($cols as $c) $r['nu_c' . $c['index']] = $x[(int)$c['index'] + 1] ?? null;
				$fetched[] = $r;
			}
		}
		foreach ($fetched as $r) {
			$cells = $classes = [];
			$rawRow = [$r['nu_pk']];                                 // nuBuilder Forte's browse_rows: [pk, value of column 0 …] unformatted (0.8.965)
			foreach ($cols as $c) {
				$v = $r['nu_c' . $c['index']];
				$rawRow[(int)$c['index'] + 1] = $c['mask'] !== null ? NuFieldAccess::maskValue($v, $c['mask']) : NuSecurity::demoMask($c['expr'], $v);
			}
			foreach ($cols as $k => $c) {
				$v = $r['nu_c' . $c['index']];
				$cells[$k]   = $c['mask'] !== null ? NuFieldAccess::maskValue($v, $c['mask']) : (!empty($opt['raw']) ? (string)(NuSecurity::demoMask($c['expr'], $v) ?? '') : NuFormat::format(NuSecurity::demoMask($c['expr'], $v), $c['format']));   // raw: print view of nuPrintUseBrowseFormats(false) (0.8.958)   // demo: no hashes / secrets
				$classes[$k] = $c['mask'] !== null ? '' : NuFormat::cssClass($v, $c['format']);
			}
			$rows[] = ['pk' => (string)$r['nu_pk'], 'cells' => $cells, 'classes' => $classes, 'raw' => $rawRow];
		}

		return ['columns' => $cols, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
	}

	/** The filter choices are kept in the session for this long (0.8.761): a DISTINCT over a large list costs a second per filter. */
	public const VALUES_TTL = 120;

	/**
	 * Distinct values of a filterable column: [[raw, label], …] (at most $limit). Cached in the session per form, column and list SQL
	 * for VALUES_TTL seconds; a save or delete through nuBuilder Next forgets the cache (forgetValues()).
	 */
	public static function distinctValues(NuFormMeta $meta, array $hash, array $col, int $limit = 300): array
	{
		[$inner] = self::build(self::innerSql($meta, $hash), $meta->primaryKey(), [$col]);
		$key = $meta->id . '|' . $col['index'] . '|' . hash('sha256', $inner . '|' . $limit . '|' . (string)$col['format'] . '|' . NuHash::boundKey($inner));
		$c = $_SESSION['nu_dv'][$key] ?? null;
		if (is_array($c) && ($c[0] ?? 0) > time() - self::VALUES_TTL) return $c[1];
		$expr = self::castText('nuT.nu_c' . $col['index']);
		$db   = nu_db();
		$rows = $db->page("SELECT DISTINCT $expr AS v FROM ($inner) nuT", [], 'v', $limit, 0);
		$out  = [];
		foreach ($rows as $r) {
			$v = $r['v'];
			$out[] = $v === null ? [self::NULL_FILTER, '∅'] : [(string)$v, NuFormat::format($v, $col['format'])];
		}
		if (isset($_SESSION)) {
			if (count($_SESSION['nu_dv'] ?? []) > 40) $_SESSION['nu_dv'] = [];
			$_SESSION['nu_dv'][$key] = [time(), $out];
		}
		return $out;
	}

	/** Forgets the cached filter choices (after a save / delete / import). */
	public static function forgetValues(): void
	{
		unset($_SESSION['nu_dv']);
	}

	/**
	 * The search terms of a browse search, as nuBuilder reads them (nuBrowseWhereClause): words separated by spaces, "phrases" in double
	 * quotes, a leading minus excludes (-word, -"phrase"; a lone "-" is a word). Duplicates are dropped. Also used for highlighting.
	 * @return array{in: list<string>, out: list<string>}
	 */
	public static function terms(string $search): array
	{
		$in = $out = [];
		preg_match_all('/(-?)"([^"]*)"|(\S+)/u', $search, $m, PREG_SET_ORDER);
		foreach ($m as $x) {
			if (($x[3] ?? '') !== '') {
				$t = $x[3];
				if ($t[0] === '-' && strlen($t) > 1) $out[] = substr($t, 1); else $in[] = $t;
			} elseif (trim($x[2]) !== '') {
				if ($x[1] === '-') $out[] = $x[2]; else $in[] = $x[2];
			}
		}
		return ['in' => array_values(array_unique($in)), 'out' => array_values(array_unique($out))];
	}

	public static function castText(string $expr): string
	{
		return nu_db()->type === 'sqlsrv' ? "CAST(($expr) AS NVARCHAR(4000))" : "CAST(($expr) AS CHAR)";
	}

	/** The column as the user sees it (dd.mm.yyyy), so searching "14.10" finds dates. */
	private static function dateText(string $expr, string $pattern): string
	{
		if (nu_db()->type === 'sqlsrv') {
			$f = strtr($pattern, ['yyyy' => 'yyyy', 'yy' => 'yy', 'mmmm' => 'MMMM', 'mmm' => 'MMM', 'mm' => 'MM', 'dd' => 'dd', 'hh' => 'HH', 'nn' => 'mm', 'ss' => 'ss']);
			return "FORMAT(($expr), '" . str_replace("'", "''", $f) . "')";
		}
		$f = preg_replace_callback('/yyyy|yy|mmmm|mmm|mm|dddd|ddd|dd|hh|nn|ss/', static fn($m) => [
			'yyyy' => '%Y', 'yy' => '%y', 'mmmm' => '%M', 'mmm' => '%b', 'mm' => '%m', 'dddd' => '%W', 'ddd' => '%a', 'dd' => '%d', 'hh' => '%H', 'nn' => '%i', 'ss' => '%s',
		][$m[0]], str_replace('%', '%%', $pattern));
		return "DATE_FORMAT(($expr), '" . str_replace("'", "''", $f) . "')";
	}

	/** Drops the #TABLE_ID# temp table a BB hook may have created. */
	public static function dropTempTable(): void
	{
		$t  = NuHash::tableId();
		$db = nu_db();
		try {
			if ($db->type === 'sqlsrv') $db->run("IF OBJECT_ID('$t') IS NOT NULL DROP TABLE $t");
			elseif ($db->type === 'mysql') $db->run("DROP TABLE IF EXISTS $t");
		} catch (Throwable) {
		}
	}
}

/** "1,3,4" → [1, 3, 4] (column indexes). */
function nu_browse_index_list(mixed $v): array
{
	if (!is_string($v) || $v === '') return [];
	return array_values(array_unique(array_map('intval', array_filter(explode(',', $v), static fn($x) => ctype_digit(trim($x))))));
}

/** Browse state from the request: search, sort, direction, page and column filters. */
/** nuBuilder Forte's breadcrumb keys of a list in nuHash() of Before Browse (0.8.984): search, page from 0, sort, direction, skipped columns. */
function nu_browse_hash_state(): array
{
	$st = nu_browse_request();
	return ['search' => $st['q'], 'page_number' => (string)($st['pg'] - 1), 'sort' => $st['sort'] === null ? '-1' : (string)$st['sort'],
		'sort_direction' => $st['sort'] === null ? 'desc' : $st['dir'], 'nosearch_columns' => implode(',', $st['nsc'])];
}

function nu_browse_request(): array
{
	$flt = [];
	foreach ((array)($_GET['flt'] ?? []) as $k => $v) {
		if (is_string($v) && $v !== '' && ctype_digit((string)$k)) $flt[(int)$k] = $v;
	}
	return [
		'q'    => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
		'rf'   => is_string($_GET['rf'] ?? null) ? trim($_GET['rf']) : '',     // run filter (nuBuilder sob_run_filter / nuForm filter): always applied, not shown
		'sort' => isset($_GET['sort']) && $_GET['sort'] !== '' ? (int)$_GET['sort'] : null,
		'dir'  => ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc',
		'pg'   => max(1, (int)($_GET['pg'] ?? 1)),
		'per'  => nu_per_param(true),   // the footer's page size menu (0.8.437; any size since 0.8.604 – nuBrowseRowsPerPageFilter); 0 = the form's rows per page
		'flt'  => $flt,
		'adv'  => NuAdvSearch::parse($_GET['adv'] ?? ''),   // advanced search (0.8.719)
		// nuBuilder nuSetNoSearchColumns / nuSetSearchColumns: column indexes the search skips / is limited to ("1,3")
		'nsc'  => nu_browse_index_list($_GET['nsc'] ?? ''),
		'sc'   => nu_browse_index_list($_GET['sc'] ?? ''),
	];
}
