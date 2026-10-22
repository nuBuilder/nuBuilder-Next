<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Browse lists: sfo_browse_sql (hash-replaced) wrapped as a derived table, the zzzzsys_browse columns
 * selected from it, with server-side search, sort and paging on MySQL and MSSQL.
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

	public static function innerSql(NuFormMeta $meta, array $hash): string
	{
		$sql = trim((string)$meta->form['sfo_browse_sql']);
		if ($sql === '') $sql = 'SELECT * FROM ' . NuDB::ident($meta->table());
		return NuHash::replace($sql, $hash, NuHash::SQL);
	}

	/**
	 * @param array $opt search, sort (column index), dir, page, per, filter => [sql on nuT, params]
	 */
	public static function query(NuFormMeta $meta, array $hash, array $opt = []): array
	{
		$db = nu_db();
		[$inner, $defaultOrder] = self::splitOrder(self::innerSql($meta, $hash));
		$pk = NuDB::ident($meta->primaryKey() ?: 'id');

		$cols = [];
		foreach ($meta->browse as $i => $b) {
			$expr = trim((string)$b['sbr_display']);
			if ($expr === '' || strtolower($expr) === 'null') $expr = 'NULL';
			$cols[] = [
				'index' => $i, 'title' => (string)$b['sbr_title'], 'expr' => $expr, 'align' => (string)($b['sbr_align'] ?: 'l'),
				'format' => (string)$b['sbr_format'], 'width' => (int)$b['sbr_width'], 'id' => (string)$b['zzzzsys_browse_id'],
				'filter' => !empty(NuFormMeta::nunextJson($b['sbr_json'] ?? null)['filter']) && $expr !== 'NULL',
			];
		}

		$where  = [];
		$params = [];
		$search = trim((string)($opt['search'] ?? ''));
		if ($search !== '') {
			foreach (preg_split('/\s+/', $search) as $term) {
				$or = [];
				foreach ($cols as $c) {
					if ($c['width'] <= 0 || $c['expr'] === 'NULL') continue;
					if (in_array($c['index'], (array)($opt['nosearch'] ?? []), true)) continue;
					if (!empty($opt['searchonly']) && !in_array($c['index'], (array)$opt['searchonly'], true)) continue;
					$or[] = self::castText($c['expr']) . " LIKE ? ESCAPE '!'";
					$params[] = NuDB::like($term);
					if (str_starts_with(strtoupper($c['format']), 'D|')) {
						$or[] = self::dateText($c['expr'], substr($c['format'], 2)) . " LIKE ? ESCAPE '!'";
						$params[] = NuDB::like($term);
					}
				}
				if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
			}
		}
		foreach ((array)($opt['filters'] ?? []) as $idx => $val) {           // column filters (builder option "Filter")
			$c = $cols[(int)$idx] ?? null;
			if (!$c || !$c['filter'] || !is_string($val) || $val === '') continue;
			if ($val === self::NULL_FILTER) { $where[] = '(' . $c['expr'] . ') IS NULL'; continue; }
			$where[] = self::castText($c['expr']) . ' = ?';
			$params[] = $val;
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

		$select = "SELECT nuT.$pk AS nu_pk";
		foreach ($cols as $c) $select .= ', ' . $c['expr'] . ' AS nu_c' . $c['index'];

		$sort = $opt['sort'] ?? null;
		$dir  = strtolower((string)($opt['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
		if ($sort !== null && $sort !== '' && isset($cols[(int)$sort])) {
			$order = 'nu_c' . (int)$sort . " $dir";
		} else {
			$order = $defaultOrder !== '' ? $defaultOrder : "nu_pk";
		}
		$order .= ', nu_pk';

		$rows = [];
		foreach ($db->page($select . $from, $params, $order, $per, ($page - 1) * $per) as $r) {
			$cells = $classes = [];
			foreach ($cols as $c) {
				$cells[]   = NuFormat::format(NuSecurity::demoMask($c['expr'], $r['nu_c' . $c['index']]), $c['format']);   // demo: no hashes / secrets
				$classes[] = NuFormat::cssClass($r['nu_c' . $c['index']], $c['format']);
			}
			$rows[] = ['pk' => (string)$r['nu_pk'], 'cells' => $cells, 'classes' => $classes];
		}

		return ['columns' => $cols, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
	}

	/** Distinct values of a filterable column: [[raw, label], …] (at most $limit). */
	public static function distinctValues(NuFormMeta $meta, array $hash, array $col, int $limit = 300): array
	{
		[$inner] = self::splitOrder(self::innerSql($meta, $hash));
		$expr = self::castText($col['expr']);
		$db   = nu_db();
		$rows = $db->page("SELECT DISTINCT $expr AS v FROM ($inner) nuT", [], 'v', $limit, 0);
		$out  = [];
		foreach ($rows as $r) {
			$v = $r['v'];
			$out[] = $v === null ? [self::NULL_FILTER, '∅'] : [(string)$v, NuFormat::format($v, $col['format'])];
		}
		return $out;
	}

	private static function castText(string $expr): string
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
		'flt'  => $flt,
		// nuBuilder nuSetNoSearchColumns / nuSetSearchColumns: column indexes the search skips / is limited to ("1,3")
		'nsc'  => nu_browse_index_list($_GET['nsc'] ?? ''),
		'sc'   => nu_browse_index_list($_GET['sc'] ?? ''),
	];
}
