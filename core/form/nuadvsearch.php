<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Advanced search of form browse lists (0.8.719): conditions on the list's columns – ?adv={"m":"all"|"any","c":[[column index, operator,
 * value, value 2], …]} – built in the ▾ panel next to the search (assets/js/nu/nuadvsearch.js). NuBrowse::query turns them into a WHERE
 * on the outer query (nuT.nu_c<i>), so they work with any browse SQL; values are parameters. Hidden / masked columns are not offered.
 * Named searches per user and form ("My searches") are rows of zzzzsys_nunext_list_view with list_key advsearch:<form id>.
 */
final class NuAdvSearch
{
	public const MAX = 20;
	/** Operators per column type ('' = no value, '2' = two values, 'list' = several values). */
	public const OPS = [
		'text' => ['contains' => 1, 'ncontains' => 1, 'eq' => 1, 'neq' => 1, 'starts' => 1, 'ends' => 1, 'in' => 'list', 'nin' => 'list', 'empty' => '', 'nempty' => ''],
		'number' => ['eq' => 1, 'neq' => 1, 'lt' => 1, 'le' => 1, 'gt' => 1, 'ge' => 1, 'between' => '2', 'empty' => '', 'nempty' => ''],
		'date' => ['on' => 1, 'before' => 1, 'after' => 1, 'between' => '2', 'today' => '', 'd7' => '', 'd30' => '', 'month' => '', 'year' => '', 'empty' => '', 'nempty' => ''],
	];
	public const KEY = 'advsearch:';

	/** The column type from its browse format: D|… date, N|… number, else text. */
	public static function typeOf(array $col): string
	{
		$f = strtoupper((string)($col['format'] ?? ''));
		return str_starts_with($f, 'D|') ? 'date' : (str_starts_with($f, 'N|') ? 'number' : 'text');
	}

	/** Columns that can be searched: shown in the list (width > 0), not masked, not NULL. */
	public static function searchable(array $cols): array
	{
		return array_filter($cols, static fn($c) => (int)$c['width'] > 0 && $c['expr'] !== 'NULL' && $c['mask'] === null);
	}

	/** ?adv=… → ['m' => 'all'|'any', 'c' => [[index, op, v, v2], …]] (shape only; columns are checked by where()). */
	public static function parse(mixed $raw): array
	{
		$j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
		if (!is_array($j)) return [];
		$c = [];
		foreach (array_slice(is_array($j['c'] ?? null) ? $j['c'] : [], 0, self::MAX) as $x) {
			if (!is_array($x) || !isset($x[0], $x[1]) || !is_numeric($x[0]) || !is_string($x[1])) continue;
			$v = $x[2] ?? '';
			$v = is_array($v) ? array_values(array_map('strval', array_filter($v, 'is_scalar'))) : (is_scalar($v) ? (string)$v : '');
			$c[] = [(int)$x[0], $x[1], $v, is_scalar($x[3] ?? null) ? (string)$x[3] : ''];
		}
		return $c ? ['m' => ($j['m'] ?? '') === 'any' ? 'any' : 'all', 'c' => $c] : [];
	}

	public static function encode(array $adv): string
	{
		return $adv && !empty($adv['c']) ? (string)json_encode(['m' => $adv['m'] ?? 'all', 'c' => array_values($adv['c'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	}

	/** The usable conditions (known column, operator of its type, a value where one is needed). */
	public static function valid(array $cols, array $adv): array
	{
		$ok = [];
		$cols = self::searchable($cols);
		foreach ($adv['c'] ?? [] as [$i, $op, $v, $v2]) {
			$c = $cols[$i] ?? null;
			if (!$c) continue;
			$type = self::typeOf($c);
			$need = self::OPS[$type][$op] ?? null;
			if ($need === null) continue;
			if ($need === 'list') { $list = is_array($v) ? $v : (preg_split('/\s*[;\n]\s*/', (string)$v) ?: []); $v = array_values(array_filter(array_map('trim', $list), 'strlen')); if (!$v) continue; }
			elseif ($need === 1 || $need === '2') {
				$v = is_array($v) ? '' : trim((string)$v);
				if ($type === 'number') { $v = self::number($v); $v2 = $need === '2' ? self::number($v2) : ''; }
				if ($type === 'date') { $v = self::date($v); $v2 = $need === '2' ? self::date($v2) : ''; }
				if ($v === '' || ($need === '2' && $v2 === '')) continue;
			} else { $v = ''; $v2 = ''; }
			$ok[] = [$i, $op, $v, $need === '2' ? $v2 : ''];
		}
		return $ok ? ['m' => $adv['m'] ?? 'all', 'c' => $ok] : [];
	}

	/** "1'234,50" / "1,234.50" / "-3" → "1234.50", '' when not a number. */
	public static function number(string $v): string
	{
		$v = str_replace(["'", ' ', "\u{2019}", "\u{00A0}"], '', trim($v));
		if (str_contains($v, ',') && str_contains($v, '.')) $v = strrpos($v, ',') > strrpos($v, '.') ? str_replace(['.', ','], ['', '.'], $v) : str_replace(',', '', $v);
		elseif (str_contains($v, ',')) $v = str_replace(',', '.', $v);
		return is_numeric($v) ? $v : '';
	}

	/** yyyy-mm-dd (from the date field) or dd.mm.yyyy → yyyy-mm-dd, '' when not a date. */
	public static function date(string $v): string
	{
		$v = trim($v);
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
		if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
		return '';
	}

	/**
	 * WHERE part of the conditions. @param callable $colExpr fn(array $col): string (nuT.nu_c<i>) @return array{0: string, 1: list<mixed>}
	 * ('' when there is nothing to add)
	 */
	public static function where(array $cols, array $adv, callable $colExpr): array
	{
		$adv = self::valid($cols, $adv);
		if (!$adv) return ['', []];
		$mssql = nu_db()->type === 'sqlsrv';
		$parts = [];
		$params = [];
		foreach ($adv['c'] as [$i, $op, $v, $v2]) {
			$c = $cols[$i];
			$e = $colExpr($c);
			$type = self::typeOf($c);
			$tx = NuBrowse::castText($e);
			$num = $mssql ? "TRY_CAST(($e) AS DECIMAL(38,10))" : "CAST(($e) AS DECIMAL(38,10))";
			$dt = $mssql ? "TRY_CAST(($e) AS DATE)" : "DATE($e)";
			$empty = "(($e) IS NULL OR $tx = '')";
			$p = [];
			if ($op === 'empty') $sql = $empty;
			elseif ($op === 'nempty') $sql = "NOT $empty";
			elseif ($type === 'text') {
				$like = static fn(string $s) => NuDB::likeRaw($s);
				[$sql, $p] = match ($op) {
					'contains' => ["$tx LIKE ? ESCAPE '!'", ['%' . $like($v) . '%']],
					'ncontains' => ["COALESCE($tx, '') NOT LIKE ? ESCAPE '!'", ['%' . $like($v) . '%']],
					'starts' => ["$tx LIKE ? ESCAPE '!'", [$like($v) . '%']],
					'ends' => ["$tx LIKE ? ESCAPE '!'", ['%' . $like($v)]],
					'eq' => ["$tx = ?", [$v]],
					'neq' => ["COALESCE($tx, '') <> ?", [$v]],
					'in' => ["$tx IN (" . implode(', ', array_fill(0, count($v), '?')) . ')', $v],
					'nin' => ["COALESCE($tx, '') NOT IN (" . implode(', ', array_fill(0, count($v), '?')) . ')', $v],
				};
			} elseif ($type === 'number') {
				$ops = ['eq' => '=', 'neq' => '<>', 'lt' => '<', 'le' => '<=', 'gt' => '>', 'ge' => '>='];
				if ($op === 'between') { $sql = "$num BETWEEN ? AND ?"; $p = [min((float)$v, (float)$v2), max((float)$v, (float)$v2)]; }
				else { $sql = "$num {$ops[$op]} ?"; $p = [(float)$v]; }
				if ($op === 'neq') $sql = "($sql OR ($e) IS NULL)";
			} else {
				$today = date('Y-m-d');
				[$sql, $p] = match ($op) {
					'on' => ["$dt = ?", [$v]],
					'before' => ["$dt < ?", [$v]],
					'after' => ["$dt > ?", [$v]],
					'between' => ["$dt BETWEEN ? AND ?", [min($v, $v2), max($v, $v2)]],
					'today' => ["$dt = ?", [$today]],
					'd7' => ["$dt BETWEEN ? AND ?", [date('Y-m-d', strtotime('-6 days')), $today]],
					'd30' => ["$dt BETWEEN ? AND ?", [date('Y-m-d', strtotime('-29 days')), $today]],
					'month' => ["$dt BETWEEN ? AND ?", [date('Y-m-01'), date('Y-m-t')]],
					'year' => ["$dt BETWEEN ? AND ?", [date('Y-01-01'), date('Y-12-31')]],
				};
			}
			$parts[] = '(' . $sql . ')';
			array_push($params, ...$p);
		}
		return ['(' . implode($adv['m'] === 'any' ? ' OR ' : ' AND ', $parts) . ')', $params];
	}

	/** Chips: [[text, adv without this condition (encoded)], …] for the active conditions. */
	public static function chips(array $cols, array $adv): array
	{
		$adv = self::valid($cols, $adv);
		$out = [];
		foreach ($adv['c'] ?? [] as $k => [$i, $op, $v, $v2]) {
			$c = $cols[$i];
			$val = is_array($v) ? implode(', ', $v) : (self::typeOf($c) === 'date' && $v !== '' ? date(nu_dfmt('d.m.Y'), (int)strtotime($v)) : $v);
			$val2 = self::typeOf($c) === 'date' && $v2 !== '' ? date(nu_dfmt('d.m.Y'), (int)strtotime($v2)) : $v2;
			$text = trim(strip_tags((string)$c['title'])) . ' ' . t('as_op_' . $op) . ($val !== '' ? ' "' . $val . '"' : '') . ($val2 !== '' ? ' ' . t('as_and') . ' "' . $val2 . '"' : '');
			$rest = $adv['c'];
			unset($rest[$k]);
			$out[] = [$text, self::encode(['m' => $adv['m'], 'c' => array_values($rest)])];
		}
		return $out;
	}

	/** What the panel needs: columns, the current search, the user's saved searches, texts. */
	public static function client(NuFormMeta $meta, array $cols, array $adv): array
	{
		$list = [];
		foreach (self::searchable($cols) as $c) {
			$list[] = ['i' => $c['index'], 't' => trim(strip_tags((string)$c['title'])) ?: '#' . $c['index'], 'y' => self::typeOf($c),
				'v' => !empty($c['values']) ? array_values(array_filter(array_map(static fn($x) => $x[0] === NuBrowse::NULL_FILTER ? null : [$x[0], $x[1]], $c['values']))) : null];
		}
		$texts = [];
		foreach (['as_title', 'as_match', 'as_all', 'as_any', 'as_add', 'as_apply', 'as_clear', 'as_field', 'as_op', 'as_value', 'as_and', 'as_remove', 'as_saved',
			'as_save', 'as_save_name', 'as_save_btn', 'as_none_saved', 'as_delete', 'as_list_hint', 'as_err_name'] as $k) $texts[$k] = t($k);
		foreach (self::OPS as $ops) foreach (array_keys($ops) as $o) $texts['as_op_' . $o] = t('as_op_' . $o);
		return ['cols' => $list, 'cur' => self::valid($cols, $adv) ?: (object)[], 'ops' => self::OPS, 'saved' => self::saved($meta->id),
			'url' => nu_url('advsearch'), 'form' => $meta->id, 'canSave' => !NuDemo::active(), 'texts' => $texts];
	}

	// ================================================================= My searches

	private static function me(): string
	{
		$u = NuAuth::user();
		return !empty($u['globeadmin']) ? 'globeadmin' : (string)($u['id'] ?? '');
	}

	/** @return list<array{id: string, name: string, adv: string}> */
	public static function saved(string $formId): array
	{
		$db = nu_db();
		if (!$db->tableExists(NuListView::TABLE)) return [];
		$out = [];
		foreach ($db->all('SELECT zzzzsys_nunext_list_view_id AS id, name, definition FROM ' . NuListView::TABLE . " WHERE list_key = ? AND scope = 'user' AND owner = ? ORDER BY name",
			[self::KEY . $formId, self::me()]) as $r) $out[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'adv' => (string)$r['definition']];
		return $out;
	}

	/** Saves (or replaces by name) a search of the current user. @return string '' = ok, else the error */
	public static function save(string $formId, string $name, array $adv): string
	{
		$name = mb_substr(trim($name), 0, 100);
		if ($name === '') return t('as_err_name');
		if (!$adv) return t('as_err_empty');
		NuSchema::ensure();
		$db = nu_db();
		$row = ['definition' => self::encode($adv), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => self::me()];
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
}
