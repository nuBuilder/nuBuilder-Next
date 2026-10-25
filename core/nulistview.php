<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * List views (0.8.329): switchable layouts of nuBuilder Next's own lists – columns and their order, width and alignment on desktop,
 * a card per row on phones (title, badge, up to 4 lines), sort, the filters offered with their default values, rows per page.
 *
 * - Catalogues (what a list can show / filter): core/nulistdefs.php. Pages: NuListView::page('users', [...]).
 * - Views: built-in (default = the list as before, compact, all – fixed), shared (for everyone or for chosen access levels; created
 *   by the globeadmin or an admin role with "List views") and personal (every user who may open the list).
 * - Default view of a list (for everyone: setting listview_defaults), own default (sus_json NUNEXT_LIST_VIEWS, globeadmin: setting
 *   listview_ga, demo users: the session). ?view=<id> chooses one; links keep it.
 * - Stored in zzzzsys_nunext_list_view (list_key, scope shared|user, owner, name, access_ids JSON, definition JSON). Keys of other
 *   lists later: 'form:<form id>' for form browse lists.
 * - Print / CSV follow the view's columns. Views can be exported / imported as JSON (shared views, access levels by code).
 */
final class NuListView
{
	public const TABLE = 'zzzzsys_nunext_list_view';
	public const BUILTIN = ['default', 'compact', 'all'];
	/** Keys of the columns the current view shows (set by prepare(); a column may adapt to its neighbours, e.g. the users' name, 0.8.512). */
	public static array $shown = [];
	public const PER = [10, 25, 48, 50, 100, 250, 500];
	public const WIDTHS = ['auto', 'narrow', 'wide'];
	public const ALIGNS = ['auto', 'start', 'center', 'end'];
	public const DATE_PRESETS = ['d7', 'd30', 'd90', 'older90', 'never', 'past', 'next30', 'future', 'empty'];
	private const DEFAULTS = 'listview_defaults';
	private const GA_DEFAULTS = 'listview_ga';
	private const JSON_KEY = 'NUNEXT_LIST_VIEWS';

	// ------------------------------------------------------------------ rights

	/** Shared views, the default view of a list, export / import: the globeadmin or an admin role with "List views". */
	public static function canManage(): bool
	{
		$u = NuAuth::user();
		return !empty($u['globeadmin']) || NuAdmin::can('listviews', 'change');
	}

	private static function me(): string
	{
		$u = NuAuth::user();
		return !empty($u['globeadmin']) ? 'globeadmin' : (string)($u['id'] ?? '');
	}

	public static function def(string $list): array
	{
		$L = NuListDefs::get($list);
		if (!$L) throw new RuntimeException(t('err_not_found'));
		foreach ($L['columns'] as $k => $c) if (isset($c['if']) && !($c['if'])()) unset($L['columns'][$k]);
		return $L;
	}

	/** May the user open the list at all (view right on its form)? */
	public static function mayOpen(array $L): bool
	{
		return NuAcl::can($L['form']);
	}

	// ------------------------------------------------------------------ definitions

	/** A view definition with only known keys and values (anything else is dropped). */
	public static function normalize(array $d, array $L): array
	{
		$cols = $L['columns'];
		$main = (string)(array_key_first(array_filter($cols, static fn($c) => !empty($c['main']))) ?? array_key_first($cols));
		$out = ['columns' => [], 'card' => ['title' => $main, 'badge' => '', 'lines' => []], 'sort' => [$main, 'asc'],
			'filters' => ['shown' => [], 'values' => []], 'per' => 25, 'open' => false];
		$seen = [];
		foreach ((array)($d['columns'] ?? []) as $c) {
			$c = is_array($c) ? $c : ['key' => (string)$c];
			$k = (string)($c['key'] ?? '');
			if (!isset($cols[$k]) || isset($seen[$k])) continue;
			$seen[$k] = true;
			$out['columns'][] = ['key' => $k, 'width' => in_array($c['width'] ?? '', self::WIDTHS, true) ? $c['width'] : 'auto',
				'align' => in_array($c['align'] ?? '', self::ALIGNS, true) ? $c['align'] : 'auto'];
		}
		foreach ($cols as $k => $c) if (!empty($c['locked']) && !isset($seen[$k])) array_unshift($out['columns'], ['key' => $k, 'width' => 'auto', 'align' => 'auto']);
		$card = (array)($d['card'] ?? []);
		if (isset($cols[$card['title'] ?? ''])) $out['card']['title'] = (string)$card['title'];
		if (isset($cols[$card['badge'] ?? ''])) $out['card']['badge'] = (string)$card['badge'];
		foreach ((array)($card['lines'] ?? []) as $k) {
			if (isset($cols[$k]) && !in_array($k, $out['card']['lines'], true) && count($out['card']['lines']) < 4) $out['card']['lines'][] = (string)$k;
		}
		$s = (array)($d['sort'] ?? []);
		if (isset($cols[$s[0] ?? '']) && !empty($cols[$s[0]]['sort'])) $out['sort'] = [(string)$s[0], ($s[1] ?? '') === 'desc' ? 'desc' : 'asc'];
		$f = (array)($d['filters'] ?? []);
		foreach ((array)($f['shown'] ?? []) as $k) if (isset($L['filters'][$k]) && !in_array($k, $out['filters']['shown'], true)) $out['filters']['shown'][] = (string)$k;
		foreach ((array)($f['values'] ?? []) as $k => $v) if (isset($L['filters'][$k]) && is_scalar($v) && (string)$v !== '') $out['filters']['values'][(string)$k] = mb_substr((string)$v, 0, 200);
		if (in_array((int)($d['per'] ?? 0), self::PER, true)) $out['per'] = (int)$d['per'];
		$out['open'] = !empty($d['open']);
		$out['display'] = isset($L['grid']) && ($d['display'] ?? '') === 'grid' ? 'grid' : 'table';
		$out['phone'] = ($d['phone'] ?? '') === 'table' ? 'table' : 'cards';   // layout below 768 px (0.8.471); the ▦ / ☰ button overrides it per device
		return $out;
	}

	// ------------------------------------------------------------------ storage

	/** @return array<string, array{id: string, name: string, scope: string, owner: string, levels: array, def: array, editable: bool, group: string}> */
	public static function views(array $L): array
	{
		$list = $L['key'];
		$names = ['default' => t('lv_builtin_default'), 'compact' => t('lv_builtin_compact'), 'all' => t('lv_builtin_all')];
		$out = [];
		foreach ($L['builtin'] as $id => $d) $out[$id] = ['id' => $id, 'name' => $names[$id] ?? $id, 'scope' => 'builtin', 'owner' => '', 'levels' => [],
			'def' => self::normalize($d, $L), 'editable' => false, 'group' => 'builtin'];
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return $out;
		$me = self::me();
		$manage = self::canManage();
		$level = (string)(NuAuth::user()['access_id'] ?? '');
		$rows = $db->all('SELECT * FROM ' . self::TABLE . ' WHERE list_key = ? ORDER BY sort_order, name', [$list]);
		foreach ($rows as $r) {
			$levels = json_decode((string)$r['access_ids'], true);
			$levels = is_array($levels) ? array_values(array_filter($levels, 'is_string')) : [];
			$shared = $r['scope'] === 'shared';
			if ($shared) {
				if (!$manage && $levels && !in_array($level, $levels, true)) continue;
				$group = 'shared';
			} elseif ((string)$r['owner'] === $me) {
				$group = 'mine';
			} elseif ($manage) {
				$group = 'others';                              // personal views of other users: list-view admins may share a copy
			} else {
				continue;
			}
			$def = json_decode((string)$r['definition'], true);
			$out[(string)$r['zzzzsys_nunext_list_view_id']] = ['id' => (string)$r['zzzzsys_nunext_list_view_id'], 'name' => (string)$r['name'], 'scope' => (string)$r['scope'],
				'owner' => (string)$r['owner'], 'levels' => $levels, 'def' => self::normalize(is_array($def) ? $def : [], $L),
				'editable' => $shared ? $manage : $group === 'mine', 'group' => $group];
		}
		return $out;
	}

	private static function settingGet(string $id): array
	{
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id]);
			$j = is_string($v) ? json_decode($v, true) : null;
			return is_array($j) ? $j : [];
		} catch (Throwable) {
			return [];
		}
	}

	private static function settingSet(string $id, array $value): void
	{
		$db = nu_db();
		NuSchema::ensure();
		$row = ['setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', $id);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $id] + $row);
	}

	/** Default view of the list for everyone ('default' = the built-in layout). */
	public static function listDefault(string $list): string
	{
		return (string)(self::settingGet(self::DEFAULTS)[$list] ?? 'default');
	}

	/** The user's own default view of the list ('' = the list's default). */
	public static function myDefault(string $list): string
	{
		$u = NuAuth::user();
		if (!empty($u['demo'])) return (string)($_SESSION['nu_listviews'][$list] ?? '');
		if (!empty($u['globeadmin'])) return (string)(self::settingGet(self::GA_DEFAULTS)[$list] ?? '');
		$j = NuUserJson::get((string)$u['id'], self::JSON_KEY);
		return is_array($j) ? (string)($j[$list] ?? '') : '';
	}

	private static function setMyDefault(string $list, string $id): void
	{
		$u = NuAuth::user();
		if (!empty($u['demo'])) { $_SESSION['nu_listviews'][$list] = $id; return; }
		if (!empty($u['globeadmin'])) {
			$all = self::settingGet(self::GA_DEFAULTS);
			if ($id === '') unset($all[$list]); else $all[$list] = $id;
			self::settingSet(self::GA_DEFAULTS, $all);
			return;
		}
		$j = NuUserJson::get((string)$u['id'], self::JSON_KEY);
		$j = is_array($j) ? $j : [];
		if ($id === '') unset($j[$list]); else $j[$list] = $id;
		NuUserJson::set((string)$u['id'], self::JSON_KEY, $j);
	}

	/** The view to show: ?view=, else the own default, else the list's default, else the built-in default. */
	public static function current(array $L, array $views): array
	{
		foreach ([(string)($_GET[self::viewParam($L)] ?? ''), self::myDefault($L['key']), self::listDefault($L['key']), 'default'] as $id) {
			if ($id !== '' && isset($views[$id])) return $views[$id];
		}
		return reset($views);
	}

	/** Save a view (new: $id null). Shared views need canManage(). @return string id */
	public static function save(array $L, ?string $id, array $in): string
	{
		$db = nu_db();
		NuSchema::ensure();
		$name = trim(mb_substr((string)($in['name'] ?? ''), 0, 100));
		if ($name === '') throw new RuntimeException(t('lv_err_name'));
		$shared = ($in['scope'] ?? '') === 'shared';
		if ($shared && !self::canManage()) throw new RuntimeException(t('err_forbidden'));
		$levels = [];
		if ($shared) {
			$known = array_column($db->all('SELECT zzzzsys_access_id FROM zzzzsys_access'), 'zzzzsys_access_id');
			foreach ((array)($in['levels'] ?? []) as $l) if (in_array((string)$l, $known, true)) $levels[] = (string)$l;
		}
		$def = self::normalize((array)($in['def'] ?? []), $L);
		$row = ['list_key' => $L['key'], 'scope' => $shared ? 'shared' : 'user', 'owner' => $shared ? '' : self::me(), 'name' => $name,
			'access_ids' => json_encode(array_values(array_unique($levels))), 'definition' => json_encode($def, JSON_UNESCAPED_UNICODE),
			'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($id !== null && $id !== '') {
			$old = self::stored($L, $id);
			if (!$old) throw new RuntimeException(t('err_not_found'));
			if ($old['scope'] === 'shared' ? !self::canManage() : (string)$old['owner'] !== self::me()) throw new RuntimeException(t('err_forbidden'));
			if ($old['scope'] !== 'shared' && $shared) $row['owner'] = '';
			$db->update(self::TABLE, $row, 'zzzzsys_nunext_list_view_id', $id);
		} else {
			$id = nu_id();
			$row['sort_order'] = (int)$db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE list_key = ?', [$L['key']]) + 1;
			$db->insert(self::TABLE, ['zzzzsys_nunext_list_view_id' => $id] + $row);
		}
		if ($shared) NuLog::write('list_view_saved', self::TABLE, $id, ['list' => $L['key'], 'name' => $name, 'levels' => count($levels)]);
		return $id;
	}

	private static function stored(array $L, string $id): ?array
	{
		$r = nu_db()->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_list_view_id = ? AND list_key = ?', [$id, $L['key']]);
		return $r ?: null;
	}

	public static function delete(array $L, string $id): void
	{
		$old = self::stored($L, $id);
		if (!$old) throw new RuntimeException(t('err_not_found'));
		if ($old['scope'] === 'shared' ? !self::canManage() : (string)$old['owner'] !== self::me()) throw new RuntimeException(t('err_forbidden'));
		nu_db()->run('DELETE FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_list_view_id = ?', [$id]);
		if (self::listDefault($L['key']) === $id) self::setListDefault($L, 'default');
		if ($old['scope'] === 'shared') NuLog::write('list_view_deleted', self::TABLE, $id, ['list' => $L['key'], 'name' => $old['name']]);
	}

	/** Default of a list for everyone, without a rights check (callers check: list views, start page layouts). */
	public static function storeListDefault(string $list, string $id): void
	{
		$all = self::settingGet(self::DEFAULTS);
		$all[$list] = $id;
		self::settingSet(self::DEFAULTS, $all);
	}

	public static function setListDefault(array $L, string $id): void
	{
		if (!self::canManage()) throw new RuntimeException(t('err_forbidden'));
		$all = self::settingGet(self::DEFAULTS);
		$all[$L['key']] = $id;
		self::settingSet(self::DEFAULTS, $all);
		NuLog::write('list_view_default', self::TABLE, $id, ['list' => $L['key']]);
	}

	public static function setOwnDefault(array $L, string $id): void
	{
		self::setMyDefault($L['key'], $id);
	}

	/** A shared copy of a personal view (list-view admins). */
	public static function share(array $L, string $id): string
	{
		if (!self::canManage()) throw new RuntimeException(t('err_forbidden'));
		$old = self::stored($L, $id);
		if (!$old) throw new RuntimeException(t('err_not_found'));
		return self::save($L, null, ['name' => $old['name'], 'scope' => 'shared', 'levels' => [], 'def' => json_decode((string)$old['definition'], true) ?: []]);
	}

	/** Shared views and the list default as JSON (access levels by code, so another installation can import them). */
	public static function export(array $L): array
	{
		if (!self::canManage()) throw new RuntimeException(t('err_forbidden'));
		$codes = array_column(nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access'), 'sal_code', 'zzzzsys_access_id');
		$views = [];
		$default = self::listDefault($L['key']);
		$defName = in_array($default, self::BUILTIN, true) ? $default : '';
		foreach (self::views($L) as $v) {
			if ($v['scope'] !== 'shared') continue;
			if ($v['id'] === $default) $defName = 'shared:' . $v['name'];
			$views[] = ['name' => $v['name'], 'access_levels' => array_values(array_filter(array_map(static fn($l) => $codes[$l] ?? null, $v['levels']))), 'definition' => $v['def']];
		}
		return ['nunextListViews' => 1, 'list' => $L['key'], 'exported' => date('c'), 'version' => NU_VERSION, 'default' => $defName, 'views' => $views];
	}

	/** Import: views with the same name are replaced, others added. @return int number of views */
	public static function import(array $L, array $data): int
	{
		if (!self::canManage()) throw new RuntimeException(t('err_forbidden'));
		if ((int)($data['nunextListViews'] ?? 0) !== 1 || ($data['list'] ?? '') !== $L['key'] || !is_array($data['views'] ?? null)) throw new RuntimeException(t('lv_err_import'));
		$ids = array_column(nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access'), 'zzzzsys_access_id', 'sal_code');
		$existing = [];
		foreach (self::views($L) as $v) if ($v['scope'] === 'shared') $existing[mb_strtolower($v['name'])] = $v['id'];
		$n = 0;
		$byName = [];
		foreach ($data['views'] as $v) {
			if (!is_array($v) || trim((string)($v['name'] ?? '')) === '') continue;
			$levels = array_values(array_filter(array_map(static fn($c) => $ids[(string)$c] ?? null, (array)($v['access_levels'] ?? []))));
			$name = trim((string)$v['name']);
			$byName[$name] = self::save($L, $existing[mb_strtolower($name)] ?? null, ['name' => $name, 'scope' => 'shared', 'levels' => $levels, 'def' => (array)($v['definition'] ?? [])]);
			$n++;
		}
		$d = (string)($data['default'] ?? '');
		if (in_array($d, self::BUILTIN, true)) self::setListDefault($L, $d);
		elseif (str_starts_with($d, 'shared:') && isset($byName[substr($d, 7)])) self::setListDefault($L, $byName[substr($d, 7)]);
		NuLog::write('list_views_imported', self::TABLE, '', ['list' => $L['key'], 'views' => $n]);
		return $n;
	}

	// ------------------------------------------------------------------ query

	/** Value of a filter from the URL, else the view's default value. '' = no filter. */
	private static function filterValue(array $L, array $view, string $k): string
	{
		$f = $L['filters'][$k];
		$all = (string)($f['all'] ?? (in_array($f['type'], ['text', 'datefrom', 'dateto'], true) ? '' : '*'));
		$v = array_key_exists($k, $_GET) ? (string)$_GET[$k] : (string)($view['def']['filters']['values'][$k] ?? '');
		return $v === $all ? '' : $v;
	}

	/** [SQL, params] of one filter value, null = no condition. */
	private static function filterWhere(array $f, string $v): ?array
	{
		if ($v === '') return null;
		$db = nu_db();
		switch ($f['type']) {
			case 'select':
				$w = ($f['where'])($v);
				return $w ?: null;
			case 'distinct':
				return $v === '-' ? ['COALESCE(' . $f['expr'] . ", '') = ''", []] : [$f['expr'] . ' = ?', [$v]];
			case 'text':
				return [$f['expr'] . " LIKE ? ESCAPE '!'", [NuDB::like($v)]];
			case 'datefrom':
			case 'dateto':
				if (!nu_valid_date($v)) return null;
				$d = $f['type'] === 'datefrom' ? $v : date('Y-m-d', strtotime($v . ' +1 day'));
				$val = isset($f['conv']) ? ($f['conv'])($d) : $db->datetime($d);
				return [$f['expr'] . ($f['type'] === 'datefrom' ? ' >= ?' : ' < ?'), [$val]];
			case 'bool':
				$p = (array)($f['expr_params'] ?? []);
				return $v === 'yes' ? [$f['expr'], $p] : ($v === 'no' ? ['NOT (' . $f['expr'] . ')', $p] : null);
			case 'date':
				$e = $f['expr'];
				$day = static fn(int $d) => $db->datetime(date('Y-m-d', strtotime(($d >= 0 ? '+' : '') . $d . ' days')));
				$zero = $db->datetime('1900-01-02');
				return match ($v) {
					'd7' => ["$e >= ?", [$day(-7)]], 'd30' => ["$e >= ?", [$day(-30)]], 'd90' => ["$e >= ?", [$day(-90)]],
					'older90' => ["($e IS NULL OR $e < ?)", [$day(-90)]], 'never', 'empty' => ["($e IS NULL OR $e < ?)", [$zero]],
					'past' => ["$e < ? AND $e > ?", [$day(0), $zero]], 'future' => ["$e >= ?", [$day(0)]],
					'next30' => ["$e >= ? AND $e < ?", [$day(0), $day(31)]],
					default => null,
				};
		}
		return null;
	}

	/** Options of a filter for the select: [value => [label, sub]] (without "all"). */
	public static function filterOptions(array $L, string $k): array
	{
		$f = $L['filters'][$k];
		$o = [];
		if (($f['none'] ?? null) !== null) $o['-'] = [(string)$f['none'], ''];
		switch ($f['type']) {
			case 'select':
				foreach (($f['options'])() as $v => $lab) $o[(string)$v] = is_array($lab) ? [(string)$lab[0], (string)($lab[1] ?? '')] : [(string)$lab, ''];
				break;
			case 'distinct':
				$w = array_merge($L['where'], ["COALESCE({$f['expr']}, '') <> ''"]);
				foreach (nu_db()->all('SELECT DISTINCT ' . $f['expr'] . ' AS v' . $L['from'] . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY v', $L['params']) as $r) $o[(string)$r['v']] = [(string)$r['v'], ''];
				break;
			case 'bool':
				$o += ['yes' => [t('yes'), ''], 'no' => [t('no'), '']];
				break;
			case 'date':
				foreach ((array)$f['presets'] as $p) $o[$p] = [t('lv_date_' . $p), ''];
				break;
		}
		return $o;
	}

	/** Search, filters and sort of the request for this view: [from+where, params, order]. */
	private static function query(array $L, array $view, array $sortCol): array
	{
		$where = $L['where'];
		$params = $L['params'];
		$q = trim((string)($_GET['q'] ?? ''));
		foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) as $term) {
			$where[] = '(' . implode(' OR ', array_map(static fn($c) => "$c LIKE ? ESCAPE '!'", $L['search'])) . ')';
			array_push($params, ...array_fill(0, count($L['search']), NuDB::like($term)));
		}
		foreach ($L['filters'] as $k => $f) {
			$w = self::filterWhere($f, self::filterValue($L, $view, $k));
			if ($w) { $where[] = $w[0]; array_push($params, ...$w[1]); }
		}
		[$sk, $dir] = $sortCol;
		$order = implode(', ', array_map(static fn($c) => trim($c) . ' ' . $dir, explode(', ', (string)$L['columns'][$sk]['sort']))) . ', ' . $L['id'];
		return [$L['from'] . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params, $order];
	}

	/**
	 * FROM … WHERE of a list as it is shown now (search, filters, the chosen view's filter values from $_GET) – or, $filters false,
	 * only the list's own conditions. For actions on "the rows of the current filter" (audit trail: delete, 0.8.351).
	 * @return array{0: string, 1: array, 2: bool} [from + where, params, a search or filter is active]
	 */
	public static function scope(string $list, bool $filters = true): array
	{
		$L = self::def($list);
		$base = [$L['from'] . ($L['where'] ? ' WHERE ' . implode(' AND ', $L['where']) : ''), $L['params']];
		$view = self::current($L, self::views($L));
		$sk = (string)$view['def']['sort'][0];
		[$from, $params] = self::query($L, $view, [isset($L['columns'][$sk]['sort']) && $L['columns'][$sk]['sort'] ? $sk : array_key_first(array_filter($L['columns'], static fn($c) => !empty($c['sort']))), 'asc']);
		$active = $from !== $base[0] || $params !== $base[1];
		return $filters ? [$from, $params, $active] : [$base[0], $base[1], $active];
	}

	/** SELECT list for the needed columns: [sql, params]. */
	private static function select(array $L, array $keys): array
	{
		$sel = [$L['id'] . ' AS __id'];
		$params = [];
		$done = [];
		foreach ($keys as $k) {
			foreach ((array)($L['columns'][$k]['select'] ?? []) as $alias => $sql) {
				if (isset($done[$alias])) continue;
				$done[$alias] = true;
				if (is_array($sql)) { $sel[] = $sql[0] . ' AS ' . $alias; array_push($params, ...$sql[1]); }
				else $sel[] = $sql . ' AS ' . $alias;
			}
		}
		return ['SELECT ' . implode(', ', $sel), $params];
	}

	private static function cellHtml(array $c, array $r): string
	{
		return isset($c['html']) ? ($c['html'])($r) : h(($c['text'])($r));
	}

	// ------------------------------------------------------------------ page

	/** URL parameter of the view: 'view' (pages that use 'view' themselves set 'view_param', e.g. translations: 'lv'). */
	public static function viewParam(array $L): string
	{
		return (string)($L['view_param'] ?? 'view');
	}

	/** Link to the list (its route and fixed parameters, e.g. tab=log). */
	public static function url(array $L, array $params = []): string
	{
		return nu_url($L['route'], (array)($L['route_params'] ?? []) + $params);
	}

	/** Breadcrumb above a list: [[label, url], …, [label]] (the last one is the current page). */
	public static function crumbs(array $items): string
	{
		$out = '<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">';
		foreach ($items as $i => $it) {
			$last = $i === count($items) - 1;
			$out .= $last ? '<li class="breadcrumb-item active" aria-current="page">' . h((string)$it[0]) . '</li>'
				: '<li class="breadcrumb-item"><a href="' . h((string)$it[1]) . '">' . h((string)$it[0]) . '</a></li>';
		}
		return $out . '</ol></nav>';
	}

	/** (i) button that opens a hint below the title (collapse with the id). */
	public static function infoToggle(string $id): string
	{
		return '<button type="button" class="btn btn-link btn-sm p-0 lh-1 text-body-secondary nuInfoBtn" data-bs-toggle="collapse" data-bs-target="#' . h($id) . '" aria-expanded="false" aria-controls="'
			. h($id) . '" title="' . h(t('info')) . '" aria-label="' . h(t('info')) . '"><i class="bi bi-info-circle"></i></button>';
	}

	/** (i) tooltip after the title. */
	public static function infoTip(string $text): string
	{
		return '<span class="nuInfoTip fs-6 fw-normal ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
	}

	/** Head / foot HTML of a page with a list (Tom Select, the editor). */
	public static function head(): string
	{
		return '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">';
	}

	public static function foot(): string
	{
		return '<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nulistview.js') . '"></script>'
			. '<script src="' . nu_asset('assets/js/nulistselect.js') . '"></script>';
	}

	/**
	 * The whole list page. $opt: 'buttons' => HTML before View / Print (Add, Import …), 'active' => menu entry, 'before' => HTML above the
	 * card (breadcrumb, tabs), 'title_extra' => HTML after the title, 'title' => '' hides the title.
	 */
	public static function page(string $list, array $opt = []): void
	{
		$st = self::prepare($list);
		nu_layout_start($st['L']['title'], $opt['active'] ?? $st['L']['route'], ['head' => self::head() . ($opt['head'] ?? '')]);
		echo $opt['before'] ?? '';
		echo self::card($st, $opt);
		nu_layout_end(self::foot() . ($opt['foot'] ?? ''));
	}

	/** Rights, view, query, rows – and Print / CSV (ends the request). Call before nu_layout_start(). */
	public static function prepare(string $list): array
	{
		$L = self::def($list);
		if (empty($L['public'])) NuAcl::require($L['form']);   // the forms list: everybody (it shows what the user may open)
		$db = nu_db();
		$views = self::views($L);
		$view = self::current($L, $views);
		$def = $view['def'];
		$cols = $L['columns'];
		$vp = self::viewParam($L);

		$sort = isset($cols[$_GET['sort'] ?? '']) && !empty($cols[$_GET['sort']]['sort']) ? (string)$_GET['sort'] : $def['sort'][0];
		$dir = isset($_GET['dir']) ? (($_GET['dir'] === 'desc') ? 'desc' : 'asc') : ($sort === $def['sort'][0] ? $def['sort'][1] : 'asc');
		$per = nu_per_param() ?: $def['per'];   // the footer's page size menu (0.8.437)
		$pg = max(1, (int)($_GET['pg'] ?? 1));
		[$from, $params, $order] = self::query($L, $view, [$sort, $dir]);

		$colKeys = array_column($def['columns'], 'key');
		self::$shown = $colKeys;
		$cardKeys = array_filter(array_merge([$def['card']['title'], $def['card']['badge']], $def['card']['lines']));
		$need = array_unique(array_merge($colKeys, $cardKeys, (array)($L['always'] ?? [])));
		[$selSql, $selParams] = self::select($L, $need);

		// keep: what links (sort, pages, print, view menu) carry over
		$keep = ['q' => trim((string)($_GET['q'] ?? '')), $vp => isset($_GET[$vp]) ? $view['id'] : null, 'per' => nu_per_param() ?: null];
		foreach (array_keys($L['filters']) as $k) if (array_key_exists($k, $_GET)) $keep[$k] = (string)$_GET[$k];
		$keepSort = $keep + (isset($_GET['sort']) ? ['sort' => $sort, 'dir' => $dir] : []);

		// Print / CSV: the view's columns in its order
		$exportCols = [];
		foreach ($colKeys as $k) if (($cols[$k]['label'] ?? '') !== '' && empty($cols[$k]['noexport'])) $exportCols[$cols[$k]['label']] = $cols[$k]['text'];
		$filterText = [t('search') => $keep['q'], t('lv_view') => $view['name']];
		foreach ($L['filters'] as $k => $f) {
			$v = self::filterValue($L, $view, $k);
			if ($v !== '') $filterText[$f['label']] = self::filterOptions($L, $k)[$v][0] ?? $v;
		}
		$exportRoute = $_GET['p'] ?? $L['route'];
		NuListExport::handle($L['form'], $L['title'], $exportCols,
			static fn(int $limit) => $db->page($selSql . $from, array_merge($selParams, $params), $order, $limit, 0), NuListExport::filterText($filterText));

		$total = (int)$db->value('SELECT COUNT(*)' . $from, $params);
		$pages = (int)max(1, ceil($total / $per));
		$pg = min($pg, $pages);
		$rows = $db->page($selSql . $from, array_merge($selParams, $params), $order, $per, ($pg - 1) * $per);
		if (isset($L['rows'])) $rows = ($L['rows'])($rows);   // e.g. a breadcrumb trail for the row links
		unset($exportRoute);
		return compact('L', 'views', 'view', 'def', 'cols', 'sort', 'dir', 'per', 'pg', 'total', 'rows', 'keep', 'keepSort', 'vp');
	}

	/** The list card: title bar, filters, table (desktop), cards (phone) or grid, pager, editor data. */
	/** Links of a cell as plain text (inside a card that is a link itself). */
	private static function unlink(string $html): string
	{
		if (stripos($html, '<a') === false) return $html;
		return (string)preg_replace(['~<a\b[^>]*>~i', '~</a\s*>~i'], ['<span>', '</span>'], $html);
	}

	public static function card(array $st, array $opt = []): string
	{
		['L' => $L, 'views' => $views, 'view' => $view, 'def' => $def, 'cols' => $cols, 'sort' => $sort, 'dir' => $dir, 'per' => $per, 'pg' => $pg,
			'total' => $total, 'rows' => $rows, 'keep' => $keep, 'keepSort' => $keepSort, 'vp' => $vp] = $st;
		$route = $L['route'];
		$rp = (array)($L['route_params'] ?? []);
		$filtered = false;
		foreach ($def['filters']['shown'] as $k) {
			$all = (string)($L['filters'][$k]['all'] ?? (in_array($L['filters'][$k]['type'], ['text', 'datefrom', 'dateto'], true) ? '' : '*'));
			if (array_key_exists($k, $_GET) && (string)$_GET[$k] !== (string)($def['filters']['values'][$k] ?? $all)) $filtered = true;
		}
		$hasQuery = $keep['q'] !== '' || $filtered;
		$wCls = ['narrow' => ' nuLvNarrow', 'wide' => ' nuLvWide', 'auto' => ''];
		$aCls = static fn(string $a, array $c) => match ($a !== 'auto' ? $a : (string)($c['align'] ?? '')) { 'end' => ' text-end', 'center' => ' text-center', default => '' };
		$title = array_key_exists('title', $opt) ? (string)$opt['title'] : $L['title'];
		$grid = ($def['display'] ?? 'table') === 'grid' && isset($L['grid']);
		$more = !empty($opt['more']);                             // ⋮ menu on phones (more()): Print moves into it
		ob_start();
		?>
<div class="card shadow-sm nuListCard nuLv<?= ($def['phone'] ?? 'cards') === 'table' ? ' nuLvPhoneTable' : '' ?>" data-lv-list="<?= h($L['key']) ?>" data-lv-phone="<?= h($def['phone'] ?? 'cards') ?>">
	<div class="card-header nuListHead nuTitleBar">
		<?php if ($title !== ''): ?><h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h($title) ?><?= $opt['title_extra'] ?? '' ?></h1><?php endif; ?>
		<?= $opt['title_sub'] ?? '' ?>
		<div class="d-flex flex-wrap gap-2 align-items-center"><?= ($opt['back'] ?? true) ? nu_back_button() : '' ?>
			<?= $opt['buttons'] ?? '' ?>
			<?= self::more($opt['more'] ?? [], $more ? NuListExport::printUrl($L['form'], $route, $rp + $keepSort) : '', $more && NuListExport::allowed($L['form']) ? nu_url($route, array_filter($rp + $keepSort, static fn($v) => $v !== null && $v !== '') + ['export' => 'xlsx']) : '') ?>
			<?php if (!$grid): // phones: cards ⇄ table, remembered on the device (0.8.471, assets/js/nulistview.js) ?>
			<button type="button" class="btn nuBtnAct nuToolBtn d-md-none nuLvPhoneBtn" data-lv-phone-toggle aria-pressed="false" title="<?= h(t('lv_phone_toggle')) ?>" aria-label="<?= h(t('lv_phone_toggle')) ?>"><i class="bi bi-table nuLvPhoneToTable" aria-hidden="true"></i><i class="bi bi-card-list nuLvPhoneToCards" aria-hidden="true"></i></button>
			<?php endif; ?>
			<?= self::menu($L, $views, $view, $keepSort) ?>
			<?= NuListExport::buttons($L['form'], $route, $rp + $keepSort, false, 'btn-outline-secondary nuToolBtn', $more ? 'd-none d-sm-inline-block' : '') ?>
			<?= $opt['buttons_end'] ?? '' ?>
		</div>
	</div>
	<form class="nuListFilter" method="get" action="index.php" role="search">
		<input type="hidden" name="p" value="<?= h($route) ?>">
		<?php foreach ($rp as $k => $v): ?><input type="hidden" name="<?= h((string)$k) ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
		<?php if ($keep[$vp] !== null): ?><input type="hidden" name="<?= h($vp) ?>" value="<?= h($view['id']) ?>"><?php endif; ?>
		<?php if ($keep['per'] !== null): ?><input type="hidden" name="per" value="<?= (int)$keep['per'] ?>"><?php endif; ?>
		<div class="row g-2 align-items-end">
			<div class="col-md-6"><?= nu_list_search($keep['q'], (string)($L['search_ph'] ?? ''), $def['filters']['shown'] ? 'nuLvFilters' : '', $filtered, $hasQuery ? nu_url($route, $rp + ($keep[$vp] !== null ? [$vp => $view['id']] : [])) : null) ?></div>
			<?php if ($def['filters']['shown']): $n = count($def['filters']['shown']); $colCls = $n === 1 ? 'col-12' : ($n === 2 ? 'col-sm-6' : 'col-sm-6 col-lg-4'); ?>
			<div class="<?= $n > 3 ? 'col-12' : 'col-md' ?> collapse<?= $filtered || $def['open'] ? ' show' : '' ?>" id="nuLvFilters"<?= !empty($L['open_min']) ? ' data-nu-open-min="' . (int)$L['open_min'] . '"' : '' ?>><div class="row g-2">
				<?php foreach ($def['filters']['shown'] as $k): $f = $L['filters'][$k];
					$input = in_array($f['type'], ['text', 'datefrom', 'dateto'], true);
					$all = (string)($f['all'] ?? ($input ? '' : '*'));
					$cur = array_key_exists($k, $_GET) ? (string)$_GET[$k] : (string)($def['filters']['values'][$k] ?? $all); ?>
					<div class="<?= $n > 3 ? 'col-sm-6 col-lg-3' : $colCls ?>">
						<label class="form-label small mb-1" for="lvf_<?= h($k) ?>"><?= h($f['label']) ?></label>
						<?php if ($input): ?>
							<input class="form-control" type="<?= $f['type'] === 'text' ? 'text' : 'date' ?>" id="lvf_<?= h($k) ?>" name="<?= h($k) ?>" value="<?= h($cur) ?>">
						<?php else: ?>
						<select class="form-select js-autosubmit<?= !empty($f['tom']) ? ' nuListTomSelect' : '' ?>" id="lvf_<?= h($k) ?>" name="<?= h($k) ?>">
							<option value="<?= h($all) ?>"><?= h((string)($f['all_label'] ?? t('all'))) ?></option>
							<?php foreach (self::filterOptions($L, $k) as $v => [$lab, $sub]): ?>
								<option value="<?= h((string)$v) ?>"<?= $cur === (string)$v ? ' selected' : '' ?><?= $sub !== '' ? ' data-sub="' . h($sub) . '"' : '' ?>><?= h($lab) ?></option>
							<?php endforeach; ?>
						</select>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div></div>
			<?php endif; ?>
		</div>
	</form>
	<?= $opt['above_rows'] ?? '' ?>
	<?php if ($grid): ?>
		<div class="p-3"><?php if (!$rows): ?><div class="text-center text-body-secondary py-5"><?= h(t('no_records')) ?></div><?php else: ?>
			<div class="row g-3 nuLvGrid"<?= $keep['q'] !== '' ? ' data-nu-highlight="' . h($keep['q']) . '"' : '' ?>><?php foreach ($rows as $r): ?><?= ($L['grid'])($r, ($L['link'])($r)) ?><?php endforeach; ?></div><?php endif; ?></div>
	<?php else: ?>
	<div class="table-responsive nuLvTableWrap">
		<table class="table table-hover align-middle mb-0 nu-table<?= !empty($L['table_class']) ? ' ' . h($L['table_class']) : '' ?>">
			<thead><tr>
				<?php foreach ($def['columns'] as $c): $cd = $cols[$c['key']]; $cls = trim($wCls[$c['width']] . $aCls($c['align'], $cd));
					if (!empty($cd['sort'])): ?>
					<?= nu_sort_th($cd['label'], $c['key'], $sort, $dir, $route, $rp + $keep, $cls) ?>
				<?php else: ?>
					<th scope="col"<?= $cls !== '' ? ' class="' . h($cls) . '"' : '' ?>><?= $cd['label'] !== '' ? h($cd['label']) : '<span class="visually-hidden">' . h(t('actions')) . '</span>' ?></th>
				<?php endif; endforeach; ?>
			</tr></thead>
			<tbody<?= $keep['q'] !== '' ? ' data-nu-highlight="' . h($keep['q']) . '"' : '' ?>>
			<?php if (!$rows): ?>
				<tr><td colspan="<?= count($def['columns']) ?>" class="text-center text-body-secondary py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= h(t('no_records')) ?></td></tr>
			<?php endif; ?>
			<?php foreach ($rows as $r): $href = ($L['link'])($r); ?>
				<tr<?= $href !== '' ? ' class="nu-row-link" data-href="' . h($href) . '"' : '' ?>>
					<?php foreach ($def['columns'] as $c): $cd = $cols[$c['key']]; $cls = trim(($cd['class'] ?? '') . $wCls[$c['width']] . $aCls($c['align'], $cd) . (!empty($cd['main']) ? ' nuMainCol' : '')); ?>
						<td<?= $cls !== '' ? ' class="' . h($cls) . '"' : '' ?>><?= !empty($cd['main']) && $href !== '' ? '<a class="fw-semibold text-decoration-none" href="' . h($href) . '">' . self::cellHtml($cd, $r) . '</a>' : self::cellHtml($cd, $r) ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="nuLvCards"<?= $keep['q'] !== '' ? ' data-nu-highlight="' . h($keep['q']) . '"' : '' ?>>
		<?php if (!$rows): ?><div class="text-center text-body-secondary py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= h(t('no_records')) ?></div><?php endif; ?>
		<?php foreach ($rows as $r): $href = ($L['link'])($r); $card = $def['card']; $tag = $href !== '' ? 'a' : 'div';
			// a card that is a link must not hold links: a nested <a> ends the card early and its value lands below it (0.8.466)
			$cell = static fn(string $k) => $href !== '' ? self::unlink(self::cellHtml($cols[$k], $r)) : self::cellHtml($cols[$k], $r); ?>
			<<?= $tag ?> class="nuLvCard"<?= $href !== '' ? ' href="' . h($href) . '"' : '' ?>>
				<span class="nuLvCardHead"><span class="nuLvCardTitle"><?= $cell($card['title']) ?></span>
					<?php if ($card['badge'] !== ''): ?><span class="nuLvCardBadge"><?= $cell($card['badge']) ?></span><?php endif; ?></span>
				<?php foreach ($card['lines'] as $k): $html = $cell($k); if (trim(strip_tags($html)) === '' && !str_contains($html, '<i')) continue; ?>
					<span class="nuLvCardLine"><?php if ($cols[$k]['label'] !== ''): ?><span class="nuLvCardLabel"><?= h($cols[$k]['label']) ?></span><?php endif; ?><span class="nuLvCardVal"><?= $html ?></span></span>
				<?php endforeach; ?>
			</<?= $tag ?>>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
	<?php nu_list_footer($total, $pg, $per, $route, $rp + $keepSort, 'card-footer', 'pg', '', true); ?>
</div>
<?= self::editorData($L, $views, $view) ?>
		<?php
		return (string)ob_get_clean();
	}

	/** The "View" menu in the title bar. */
	/**
	 * $opt['more'] (0.8.371): buttons that sit in the title bar from 576 px on and – with Print – in a ⋮ menu right of the Add button on
	 * phones. Each entry: ['icon' => 'bi-…', 'label' => …, 'href' => …, 'title' => …, 'blank' => bool, 'id' => …].
	 */
	private static function more(array $items, string $printUrl, string $xlsxUrl = ''): string
	{
		if (!$items) return '';
		$btns = $menu = '';
		foreach ($items as $i) {
			$blank = !empty($i['blank']) ? ' target="_blank" rel="noopener"' : '';
			$title = (string)($i['title'] ?? '') !== '' ? ' title="' . h((string)$i['title']) . '"' : '';
			$id = (string)($i['id'] ?? '') !== '' ? ' id="' . h((string)$i['id']) . '"' : '';
			$btns .= '<a class="btn btn-outline-secondary d-none d-sm-inline-block"' . $id . ' href="' . h((string)$i['href']) . '"' . $blank . $title . '><i class="bi ' . h((string)$i['icon']) . ' me-1"></i>' . h((string)$i['label']) . '</a>';
			$menu .= '<li><a class="dropdown-item" href="' . h((string)$i['href']) . '"' . $blank . '><i class="bi ' . h((string)$i['icon']) . ' me-2"></i>' . h((string)$i['label']) . '</a></li>';
		}
		if ($printUrl !== '') $menu .= '<li><hr class="dropdown-divider"></li><li><h6 class="dropdown-header">' . h(t('exp_menu')) . '</h6></li>' . NuListExport::menuItems($printUrl, $xlsxUrl);
		return '<div class="dropdown d-sm-none nuMoreMenu"><button class="btn btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="nuMoreBtn"'
			. ' title="' . h(t('more_actions')) . '" aria-label="' . h(t('more_actions')) . '"><i class="bi bi-three-dots-vertical"></i></button>'
			. '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' . $menu . '</ul></div>' . $btns;
	}

	private static function menu(array $L, array $views, array $view, array $keep): string
	{
		$vp = self::viewParam($L);
		$myDef = self::myDefault($L['key']);
		$listDef = self::listDefault($L['key']);
		$manage = self::canManage();
		$groups = ['builtin' => t('lv_group_builtin'), 'shared' => t('lv_group_shared'), 'mine' => t('lv_group_mine'), 'others' => t('lv_group_others')];
		$keepNoView = array_diff_key($keep, [$vp => 1, 'sort' => 1, 'dir' => 1]);
		$items = '';
		foreach ($groups as $g => $title) {
			$in = array_filter($views, static fn($v) => $v['group'] === $g);
			if (!$in) continue;
			$items .= '<li><h6 class="dropdown-header">' . h($title) . '</h6></li>';
			foreach ($in as $v) {
				$icons = ($v['id'] === $myDef ? '<i class="bi bi-star-fill text-warning ms-2" title="' . h(t('lv_my_default')) . '"></i>' : '')
					. ($v['id'] === $listDef ? '<i class="bi bi-pin-angle-fill text-primary ms-2" title="' . h(t('lv_list_default')) . '"></i>' : '')
					. ($v['scope'] === 'shared' && $v['levels'] ? '<i class="bi bi-people ms-2 text-body-secondary" title="' . h(t('lv_limited')) . '"></i>' : '');
				$items .= '<li><a class="dropdown-item d-flex align-items-center' . ($v['id'] === $view['id'] ? ' active' : '') . '" href="' . h(self::url($L, [$vp => $v['id']] + $keepNoView)) . '">'
					. '<span class="me-auto">' . h($v['name']) . '</span>' . $icons . '</a></li>';
			}
		}
		$post = static function (string $action, string $label, string $icon, array $extra = []) use ($L, $view, $keep, $vp): string {
			$f = '<li><form method="post" action="' . h(nu_url('listview')) . '">' . nu_csrf_field()
				. '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="list" value="' . h($L['key']) . '">'
				. '<input type="hidden" name="id" value="' . h($view['id']) . '"><input type="hidden" name="back" value="' . h(self::url($L, array_diff_key($keep, [$vp => 1]) + [$vp => $view['id']])) . '">';
			foreach ($extra as $k => $v) $f .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
			return $f . '<button class="dropdown-item" type="submit"><i class="bi ' . $icon . ' me-2"></i>' . h($label) . '</button></form></li>';
		};
		$items .= '<li><hr class="dropdown-divider"></li>';
		if ($view['editable']) $items .= '<li><button class="dropdown-item" type="button" data-lv-edit="edit"><i class="bi bi-pencil me-2"></i>' . h(t('lv_edit')) . '</button></li>';
		$items .= '<li><button class="dropdown-item" type="button" data-lv-edit="new"><i class="bi bi-plus-lg me-2"></i>' . h(t('lv_new')) . '</button></li>';
		if ($view['id'] !== $myDef) $items .= $post('my_default', t('lv_set_my_default'), 'bi-star');
		elseif ($myDef !== '') $items .= $post('my_default', t('lv_clear_my_default'), 'bi-star-half', ['clear' => '1']);
		if ($manage) {
			if ($view['id'] !== $listDef && $view['group'] !== 'mine' && $view['group'] !== 'others') $items .= $post('list_default', t('lv_set_list_default'), 'bi-pin-angle');
			if ($view['group'] === 'mine' || $view['group'] === 'others') $items .= $post('share', t('lv_share'), 'bi-share');
			$items .= '<li><hr class="dropdown-divider"></li>'
				. '<li><a class="dropdown-item" href="' . h(nu_url('listview', ['action' => 'export', 'list' => $L['key']])) . '"><i class="bi bi-download me-2"></i>' . h(t('lv_export')) . '</a></li>'
				. '<li><button class="dropdown-item" type="button" data-lv-import="1"><i class="bi bi-upload me-2"></i>' . h(t('lv_import')) . '</button></li>';
		}
		return '<div class="dropdown"><button class="btn btn-outline-secondary nuToolBtn dropdown-toggle nuLvMenuBtn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="' . h(t('lv_view')) . '">'
			. '<i class="bi bi-layout-three-columns me-1"></i><span class="nuLvMenuName">' . h($view['name']) . '</span></button>'
			. '<ul class="dropdown-menu dropdown-menu-end shadow-sm nuLvMenu">' . $items . '</ul></div>';
	}

	/** Catalogue, current view and texts for the editor (assets/js/nulistview.js). */
	private static function editorData(array $L, array $views, array $view): string
	{
		$cols = [];
		foreach ($L['columns'] as $k => $c) $cols[] = ['key' => $k, 'label' => $c['label'] !== '' ? $c['label'] : t('actions'), 'locked' => !empty($c['locked']), 'sort' => !empty($c['sort'])];
		$filters = [];
		foreach ($L['filters'] as $k => $f) {
			$input = in_array($f['type'], ['text', 'datefrom', 'dateto'], true);
			$o = $input ? [] : [['v' => (string)($f['all'] ?? '*'), 'l' => (string)($f['all_label'] ?? t('all'))]];
			if (!$input) foreach (array_slice(self::filterOptions($L, $k), 0, 200, true) as $v => [$lab]) $o[] = ['v' => (string)$v, 'l' => $lab];
			$filters[] = ['key' => $k, 'label' => $f['label'], 'options' => $o, 'input' => $input ? ($f['type'] === 'text' ? 'text' : 'date') : ''];
		}
		$levels = self::canManage() ? array_map(static fn($a) => ['id' => (string)$a['zzzzsys_access_id'], 'code' => (string)$a['sal_code']],
			nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access ORDER BY sal_code')) : [];
		$texts = [];
		foreach (['lv_edit_title', 'lv_new_title', 'lv_tab_columns', 'lv_tab_phone', 'lv_tab_filters', 'lv_tab_general', 'lv_name', 'lv_visible', 'lv_width', 'lv_align',
			'lv_width_auto', 'lv_width_narrow', 'lv_width_wide', 'lv_align_auto', 'lv_align_start', 'lv_align_center', 'lv_align_end', 'lv_card_title', 'lv_card_badge',
			'lv_card_lines', 'lv_none', 'lv_filter_shown', 'lv_filter_default', 'lv_filters_open', 'lv_sort', 'lv_sort_asc', 'lv_sort_desc', 'lv_per', 'lv_scope',
			'lv_scope_user', 'lv_scope_shared', 'lv_levels', 'lv_levels_all', 'lv_save', 'lv_save_new', 'lv_delete', 'lv_delete_confirm', 'cancel', 'lv_up', 'lv_down',
			'lv_import_title', 'lv_import_hint', 'lv_import_go', 'lv_card_preview', 'lv_locked', 'lv_copy_of', 'lv_err_name', 'lv_display', 'lv_display_table', 'lv_display_grid', 'lv_phone_layout', 'lv_phone_cards', 'lv_phone_table'] as $k) $texts[$k] = t($k);
		$data = ['list' => $L['key'], 'route' => $L['route'], 'columns' => $cols, 'filters' => $filters, 'per' => self::PER, 'levels' => $levels, 'grid' => isset($L['grid']),
			'manage' => self::canManage(), 'view' => ['id' => $view['id'], 'name' => $view['name'], 'scope' => $view['scope'], 'levels' => $view['levels'], 'editable' => $view['editable'], 'def' => $view['def']],
			'texts' => $texts, 'post' => nu_url('listview')];
		return '<div id="nuLvData" hidden data-lv="' . h(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">' . nu_csrf_field() . '</div>';
	}
}
