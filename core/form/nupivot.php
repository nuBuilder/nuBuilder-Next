<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Pivot of a form list (0.8.1151): rows / columns / values / filters over the list's columns, worked out by the database – the list SQL
 * with its search, filters, advanced search and column access (NuBrowse::filtered) becomes a sub-query, GROUP BY the chosen fields.
 * Date fields group by year, quarter, month, month of the year, week, weekday or day. Masked / hidden columns are never offered.
 * Spec: {rows: [{c, g}], cols: [{c, g}], vals: [{c, fn}], flt: [{c, g, v: [keys]}]} – c = list column index, g = date part ('' = value).
 * Saved pivots: zzzzsys_nunext_list_view, list_key pivot:<form id>, scope user (as "My searches" of the advanced search);
 * scope shared (owner '') = pivots for everyone who opens the list, e.g. those of the samples (0.8.1155) – listed, never deleted here.
 */
final class NuPivot
{
	public const KEY = 'pivot:';
	public const PARTS = ['', 'year', 'quarter', 'month', 'monthnum', 'week', 'weekday', 'day'];
	public const FNS = ['count', 'sum', 'avg', 'min', 'max', 'countd'];
	public const MAX_ROWS = 2000;     // row groups
	public const MAX_COLS = 200;      // column groups
	public const MAX_DIMS = 3;        // fields per side
	public const DRILL = 200;         // records listed for one cell

	/** Fields of the pivot: the list's columns with a title and a value, not masked. @return list<array{c: int, title: string, type: string}> */
	public static function fields(array $cols): array
	{
		$out = [];
		foreach ($cols as $c) {
			if ($c['expr'] === 'NULL' || $c['mask'] !== null) continue;
			$title = trim(NuHtmlClean::cellText((string)$c['title']));
			if ($title === '') $title = '#' . ((int)$c['index'] + 1);
			$f = strtoupper(substr((string)$c['format'], 0, 2));
			$out[] = ['c' => (int)$c['index'], 'title' => $title, 'type' => $f === 'D|' ? 'date' : ($f === 'N|' ? 'number' : 'text')];
		}
		return $out;
	}

	/** Checks a spec against the fields. @return array cleaned spec */
	public static function clean(mixed $spec, array $fields): array
	{
		$spec = is_array($spec) ? $spec : (is_string($spec) ? (json_decode($spec, true) ?: []) : []);
		$by = [];
		foreach ($fields as $f) $by[$f['c']] = $f;
		$dim = static function ($list) use ($by): array {
			$out = [];
			foreach (array_slice(is_array($list) ? $list : [], 0, self::MAX_DIMS) as $d) {
				$c = (int)($d['c'] ?? -1); $g = (string)($d['g'] ?? '');
				if (!isset($by[$c]) || !in_array($g, self::PARTS, true)) continue;
				if ($g !== '' && $by[$c]['type'] !== 'date') $g = '';
				$out[] = ['c' => $c, 'g' => $g];
			}
			return $out;
		};
		$vals = [];
		foreach (array_slice(is_array($spec['vals'] ?? null) ? $spec['vals'] : [], 0, 6) as $v) {
			$fn = (string)($v['fn'] ?? 'count');
			if (!in_array($fn, self::FNS, true)) continue;
			$c = $fn === 'count' ? -1 : (int)($v['c'] ?? -1);
			if ($fn !== 'count' && !isset($by[$c])) continue;
			$vals[] = ['c' => $c, 'fn' => $fn];
		}
		if (!$vals) $vals = [['c' => -1, 'fn' => 'count']];
		$flt = [];
		foreach (array_slice(is_array($spec['flt'] ?? null) ? $spec['flt'] : [], 0, 6) as $d) {
			$x = $dim([$d]);
			if (!$x) continue;
			$v = array_values(array_filter(array_map('strval', array_slice((array)($d['v'] ?? []), 0, 500)), static fn($s) => strlen($s) <= 200));
			$flt[] = $x[0] + ['v' => $v];
		}
		return ['rows' => $dim($spec['rows'] ?? []), 'cols' => $dim($spec['cols'] ?? []), 'vals' => $vals, 'flt' => $flt];
	}

	/** The SQL of one dimension on nuT. Keys are text: year "2020", quarter "2020-Q1", month "2020-03", monthnum "03", week "2020-W09", weekday "1" (Monday) … "7", day "2020-03-01". */
	public static function dimExpr(string $x, string $g, string $type): string
	{
		$my = $type !== 'sqlsrv';
		$d = $my ? "CAST($x AS DATE)" : "TRY_CAST($x AS date)";
		switch ($g) {
			case 'year':     return $my ? "CAST(YEAR($d) AS CHAR)" : "CAST(YEAR($d) AS varchar(4))";
			case 'quarter':  return $my ? "CONCAT(YEAR($d), '-Q', QUARTER($d))" : "CONCAT(YEAR($d), '-Q', DATEPART(quarter, $d))";
			case 'month':    return $my ? "DATE_FORMAT($d, '%Y-%m')" : "FORMAT($d, 'yyyy-MM')";
			case 'monthnum': return $my ? "DATE_FORMAT($d, '%m')" : "FORMAT($d, 'MM')";
			case 'week':     return $my ? "DATE_FORMAT($d, '%x-W%v')" : "CONCAT(YEAR(DATEADD(day, 26 - DATEPART(iso_week, $d), $d)), '-W', RIGHT(CONCAT('0', DATEPART(iso_week, $d)), 2))";
			case 'weekday':  return $my ? "CAST(WEEKDAY($d) + 1 AS CHAR)" : "CAST(((DATEPART(weekday, $d) + @@DATEFIRST + 5) % 7) + 1 AS varchar(1))";
			case 'day':      return $my ? "DATE_FORMAT($d, '%Y-%m-%d')" : "FORMAT($d, 'yyyy-MM-dd')";
		}
		return NuBrowse::castText($x);
	}

	private static function aggExpr(array $v, callable $col, array $cols, string $type): string
	{
		if ($v['fn'] === 'count') return 'COUNT(*)';
		$x = $col($cols[$v['c']]);
		if ($v['fn'] === 'countd') return "COUNT(DISTINCT $x)";
		$n = $type === 'sqlsrv' ? "TRY_CONVERT(DECIMAL(38, 6), $x)" : "($x + 0)";
		return strtoupper($v['fn']) . "($n)";
	}

	/** One aggregate query: GROUP BY $dims. @return list<array> rows with nu_d<i> and nu_v<j> */
	private static function grouped(array $src, string $inner, array $where, array $params, array $dims, array $aggs): array
	{
		$sel = []; $grp = [];
		foreach ($dims as $i => $e) { $sel[] = "$e AS nu_d$i"; $grp[] = $e; }
		foreach ($aggs as $j => $e) $sel[] = "$e AS nu_v$j";
		$sql = 'SELECT ' . implode(', ', $sel) . " FROM ($inner) nuT" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ($grp ? ' GROUP BY ' . implode(', ', $grp) : '');
		return nu_db()->all($sql, $params);
	}

	/**
	 * Works out the pivot. @return array{fields, spec, rows: list<list<string>>, cols: list<list<string>>, cells: array<string, list>,
	 * rowTotals, colTotals, grand, sub: array (row prefix → [col key → values]), error?}
	 */
	public static function run(NuFormMeta $meta, array $hash, array $opt, mixed $spec): array
	{
		$F = NuBrowse::filtered($meta, $hash, $opt);
		$fields = self::fields($F['cols']);
		$spec = self::clean($spec, $fields);
		$type = nu_db()->type;
		$col = $F['col'];
		$typeOf = [];
		foreach ($fields as $f) $typeOf[$f['c']] = $f['type'];
		$dim = fn(array $d): string => self::dimExpr($col($F['cols'][$d['c']]), $d['g'], $type);
		$where = $F['where']; $params = $F['params'];
		foreach ($spec['flt'] as $d) {
			if (!$d['v']) continue;
			$e = $dim($d);
			$hasNull = in_array('', $d['v'], true);
			$vals = array_values(array_filter($d['v'], static fn($s) => $s !== ''));
			$cond = [];
			if ($vals) { $cond[] = "$e IN (" . implode(', ', array_fill(0, count($vals), '?')) . ')'; array_push($params, ...$vals); }
			if ($hasNull) $cond[] = "($e IS NULL OR $e = '')";
			$where[] = '(' . implode(' OR ', $cond) . ')';
		}
		$aggs = array_map(fn($v) => self::aggExpr($v, $col, $F['cols'], $type), $spec['vals']);
		$rd = array_map($dim, $spec['rows']);
		$cd = array_map($dim, $spec['cols']);
		$key = static fn(array $r, string $p, int $from, int $n): array => array_map(static fn($i) => (string)($r[$p . $i] ?? ''), range($from, $from + $n - 1));
		$vals = static function (array $r, int $n): array { $o = []; for ($j = 0; $j < $n; $j++) $o[] = $r['nu_v' . $j] === null ? null : 0 + $r['nu_v' . $j]; return $o; };
		$nv = count($aggs); $nr = count($rd); $nc = count($cd);
		$res = ['fields' => $fields, 'spec' => $spec, 'rows' => [], 'cols' => [], 'cells' => [], 'rowTotals' => [], 'colTotals' => [], 'grand' => [], 'sub' => []];

		$main = self::grouped($F, $F['inner'], $where, $params, array_merge($rd, $cd), $aggs);
		$rk = []; $ck = [];
		foreach ($main as $r) {
			$a = $nr ? $key($r, 'nu_d', 0, $nr) : [];
			$b = $nc ? $key($r, 'nu_d', $nr, $nc) : [];
			$rk[json_encode($a)] = $a; $ck[json_encode($b)] = $b;
			$res['cells'][json_encode($a) . '|' . json_encode($b)] = $vals($r, $nv);
		}
		if (count($rk) > self::MAX_ROWS) return ['error' => t('pv_err_rows', self::MAX_ROWS)] + $res;
		if (count($ck) > self::MAX_COLS) return ['error' => t('pv_err_cols', self::MAX_COLS)] + $res;
		$cmp = static fn($x, $y) => strnatcasecmp(implode("\x1f", $x), implode("\x1f", $y));
		$res['rows'] = array_values($rk); usort($res['rows'], $cmp);
		$res['cols'] = array_values($ck); usort($res['cols'], $cmp);
		// totals as the database works them out (an average of averages would be wrong): per row, per column, overall, and per row prefix
		if ($nc) foreach (self::grouped($F, $F['inner'], $where, $params, $rd, $aggs) as $r) $res['rowTotals'][json_encode($nr ? $key($r, 'nu_d', 0, $nr) : [])] = $vals($r, $nv);
		if ($nr) foreach (self::grouped($F, $F['inner'], $where, $params, $cd, $aggs) as $r) $res['colTotals'][json_encode($nc ? $key($r, 'nu_d', 0, $nc) : [])] = $vals($r, $nv);
		$g = self::grouped($F, $F['inner'], $where, $params, [], $aggs);
		$res['grand'] = $g ? $vals($g[0], $nv) : array_fill(0, $nv, null);
		for ($k = 1; $k < $nr; $k++) {                                  // subtotals of the outer row fields
			$pre = array_slice($rd, 0, $k);
			foreach (self::grouped($F, $F['inner'], $where, $params, array_merge($pre, $cd), $aggs) as $r) {
				$res['sub'][json_encode($key($r, 'nu_d', 0, $k))][json_encode($nc ? $key($r, 'nu_d', $k, $nc) : [])] = $vals($r, $nv);
			}
			foreach (self::grouped($F, $F['inner'], $where, $params, $pre, $aggs) as $r) $res['sub'][json_encode($key($r, 'nu_d', 0, $k))]['*'] = $vals($r, $nv);
		}
		$res['types'] = $typeOf;
		return $res;
	}

	/** Distinct keys of one field (filter list). @return list<string> */
	public static function values(NuFormMeta $meta, array $hash, array $opt, int $c, string $g): array
	{
		$F = NuBrowse::filtered($meta, $hash, $opt);
		$fields = self::fields($F['cols']);
		$spec = self::clean(['rows' => [['c' => $c, 'g' => $g]]], $fields);
		if (!$spec['rows']) return [];
		$e = self::dimExpr(($F['col'])($F['cols'][$spec['rows'][0]['c']]), $spec['rows'][0]['g'], nu_db()->type);
		$rows = self::grouped($F, $F['inner'], $F['where'], $F['params'], [$e], []);
		$out = array_map(static fn($r) => (string)($r['nu_d0'] ?? ''), array_slice($rows, 0, 500));
		usort($out, 'strnatcasecmp');
		return $out;
	}

	/** The records behind one cell: [{pk, cells}] (formatted like the list), first DRILL. */
	public static function drill(NuFormMeta $meta, array $hash, array $opt, mixed $spec, array $rowKey, array $colKey): array
	{
		$F = NuBrowse::filtered($meta, $hash, $opt);
		$spec = self::clean($spec, self::fields($F['cols']));
		$type = nu_db()->type;
		$where = $F['where']; $params = $F['params'];
		$add = function (array $d, string $v) use (&$where, &$params, $F, $type): void {
			$e = self::dimExpr(($F['col'])($F['cols'][$d['c']]), $d['g'], $type);
			if ($v === '') { $where[] = "($e IS NULL OR $e = '')"; return; }
			$where[] = "$e = ?"; $params[] = $v;
		};
		foreach ($spec['flt'] as $d) if ($d['v']) {
			$e = self::dimExpr(($F['col'])($F['cols'][$d['c']]), $d['g'], $type);
			$where[] = "$e IN (" . implode(', ', array_fill(0, count($d['v']), '?')) . ')'; array_push($params, ...$d['v']);
		}
		foreach ($spec['rows'] as $i => $d) if (array_key_exists($i, $rowKey)) $add($d, (string)$rowKey[$i]);
		foreach ($spec['cols'] as $i => $d) if (array_key_exists($i, $colKey)) $add($d, (string)$colKey[$i]);
		$sel = 'SELECT nuT.nu_pk';
		foreach ($F['cols'] as $c) $sel .= ', ' . ($F['col'])($c);
		$from = " FROM ({$F['inner']}) nuT" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
		$db = nu_db();
		$total = (int)$db->value('SELECT COUNT(*)' . $from, $params);
		$rows = [];
		foreach ($db->page($sel . $from, $params, $F['order'] !== '' ? $F['order'] . ', nu_pk' : 'nu_pk', self::DRILL, 0) as $r) {
			$cells = [];
			foreach ($F['cols'] as $c) {
				if ((int)$c['width'] <= 0) continue;
				$v = $r['nu_c' . $c['index']];
				$cells[] = $c['mask'] !== null ? NuFieldAccess::maskValue($v, $c['mask']) : NuHtmlClean::cellText((string)NuFormat::format(NuSecurity::demoMask($c['expr'], $v), $c['format']));
			}
			$rows[] = ['pk' => (string)$r['nu_pk'], 'cells' => $cells];
		}
		$head = [];
		foreach ($F['cols'] as $c) if ((int)$c['width'] > 0) $head[] = NuHtmlClean::cellText((string)$c['title']);
		return ['head' => $head, 'rows' => $rows, 'total' => $total];
	}

	// ================================================================= saved pivots (per user)

	private static function me(): string
	{
		$u = NuAuth::user();
		return !empty($u['globeadmin']) ? 'globeadmin' : (string)($u['id'] ?? '');
	}

	/** @return list<array{id: string, name: string, spec: mixed, shared: bool}> */
	public static function saved(string $formId): array
	{
		$db = nu_db();
		if (!$db->tableExists(NuListView::TABLE)) return [];
		$out = [];
		foreach ($db->all('SELECT zzzzsys_nunext_list_view_id AS id, name, definition, scope FROM ' . NuListView::TABLE . " WHERE list_key = ? AND ((scope = 'user' AND owner = ?) OR scope = 'shared') ORDER BY name",
			[self::KEY . $formId, self::me()]) as $r) $out[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'spec' => json_decode((string)$r['definition'], true),
				'shared' => $r['scope'] === 'shared'];
		return $out;
	}

	/** Every saved pivot the user sees, for run buttons in design mode (0.8.1156): [["<form id>|<id>", "<form code> – <name>"], …]; own ones marked. */
	public static function forRunButtons(): array
	{
		$db = nu_db();
		if (!$db->tableExists(NuListView::TABLE)) return [];
		$out = [];
		foreach ($db->all('SELECT v.zzzzsys_nunext_list_view_id AS id, v.list_key, v.name, v.scope FROM ' . NuListView::TABLE . " v WHERE v.list_key LIKE 'pivot:%'
			AND ((v.scope = 'user' AND v.owner = ?) OR v.scope = 'shared') ORDER BY v.list_key, v.name", [self::me()]) as $r) {
			$f = substr((string)$r['list_key'], strlen(self::KEY));
			$m = NuFormMeta::load($f);
			if (!$m) continue;
			$out[] = [$f . '|' . $r['id'], ($m->code() ?: $f) . ' – ' . $r['name'] . ($r['scope'] === 'shared' ? '' : ' (' . t('pv_only_me') . ')')];
		}
		return $out;
	}

	public static function save(string $formId, string $name, array $spec): string
	{
		$name = mb_substr(trim($name), 0, 100);
		if ($name === '') return t('as_err_name');
		NuSchema::ensure();
		$db = nu_db();
		$row = ['definition' => json_encode($spec, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => self::me()];
		$id = $db->value('SELECT zzzzsys_nunext_list_view_id FROM ' . NuListView::TABLE . " WHERE list_key = ? AND scope = 'user' AND owner = ? AND name = ?", [self::KEY . $formId, self::me(), $name]);
		if ($id) $db->update(NuListView::TABLE, $row, 'zzzzsys_nunext_list_view_id', (string)$id);
		else $db->insert(NuListView::TABLE, ['zzzzsys_nunext_list_view_id' => nu_id(), 'list_key' => self::KEY . $formId, 'scope' => 'user', 'owner' => self::me(), 'name' => $name,
			'access_ids' => '[]', 'sort_order' => 0] + $row);
		return '';
	}

	public static function delete(string $formId, string $id): void
	{
		nu_db()->run('DELETE FROM ' . NuListView::TABLE . " WHERE zzzzsys_nunext_list_view_id = ? AND list_key = ? AND scope = 'user' AND owner = ?", [$id, self::KEY . $formId, self::me()]);
	}

	/** Texts of the pivot dialogue (assets/js/nu/nupivot.js): every pv_ key plus a few shared ones. */
	public static function texts(): array
	{
		$keys = ['pv_title', 'pv_note', 'pv_fields', 'pv_rows', 'pv_cols', 'pv_vals', 'pv_filters', 'pv_drop', 'pv_add', 'pv_remove', 'pv_records', 'pv_fn_count', 'pv_fn_sum',
			'pv_fn_avg', 'pv_fn_min', 'pv_fn_max', 'pv_fn_countd', 'pv_g_value', 'pv_g_year', 'pv_g_quarter', 'pv_g_month', 'pv_g_monthnum', 'pv_g_week', 'pv_g_weekday', 'pv_g_day',
			'pv_total', 'pv_show', 'pv_show_val', 'pv_show_row', 'pv_show_col', 'pv_show_all', 'pv_heat', 'pv_table', 'pv_chart', 'pv_chart_bar', 'pv_chart_stack', 'pv_chart_line',
			'pv_sort', 'pv_sort_key', 'pv_sort_desc', 'pv_sort_asc', 'pv_top', 'pv_top_all', 'pv_saved', 'pv_shared', 'pv_save', 'pv_save_name', 'pv_none_saved', 'pv_clear', 'pv_empty_val',
			'pv_start', 'pv_drill', 'pv_drill_tip', 'pv_working', 'exp_csv', 'exp_xlsx', 'close', 'delete', 'cancel', 'save'];
		$out = [];
		foreach ($keys as $k) $out[$k] = t($k);
		return $out;
	}
}
