<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Dashboards (0.8.298, Admin tools → Dashboards, globeadmin): KPI tiles, ApexCharts charts and small tables from SQL, a saved query of the
 * SQL builder (zzzzsys_select) or a PHP procedure (zzzzsys_php, returns its rows with nuDashboardData($rows)).
 *   - shown on the start page of users (on_home; access = access level ids, empty = every user) and anywhere on a form:
 *     an HTML object with <div data-nu-dashboard="CODE"></div> (nuBuilder shows an empty div) or nuNextDashboard('CODE', 'element_id');
 *   - hash variables: the user's (#USER_ID#, #ACCESS_LEVEL_CODE# …), on forms #RECORD_ID# and the field values of the saved record (read
 *     from the database, not from the browser; fields that are not saved – e.g. launch form criteria – come from the browser), the date
 *     filter #DATE_FROM# / #DATE_TO#; values are escaped for SQL;
 *   - every query runs read-only (exactly one SELECT, rolled-back READ ONLY transaction, time limit), results are cached per user.
 * Tables: zzzzsys_nunext_dashboard, zzzzsys_nunext_widget (NuSchema).
 */
final class NuDashboard
{
	public const TD = 'zzzzsys_nunext_dashboard';
	public const TW = 'zzzzsys_nunext_widget';
	public const TYPES = ['kpi', 'chart', 'table'];
	public const CHARTS = ['bar', 'barh', 'line', 'area', 'pie', 'donut', 'radial'];
	public const SOURCES = ['sql', 'select', 'php'];
	public const WIDTHS = [3, 4, 6, 8, 12];
	public const FORMATS = ['number', 'integer', 'percent', 'text'];
	public const RANGES = ['today', 'yesterday', '7d', '30d', '90d', 'month', 'last_month', 'quarter', 'year', 'last_year', 'all', 'custom'];
	public const LINKS = ['', 'form', 'report', 'url'];
	public const CODE_RE = '/^[A-Za-z0-9_.-]{1,50}$/';
	/** Rows read at most: KPI 1, table up to 100, chart 1000. */
	public const MAX_ROWS = ['kpi' => 1, 'table' => 100, 'chart' => 1000];
	public const TIMEOUT = 10;
	public const OPT_DEFAULTS = ['width' => 0, 'height' => 280, 'chart' => 'bar', 'stacked' => false, 'format' => 'number', 'decimals' => 0,
		'prefix' => '', 'suffix' => '', 'low' => '', 'high' => '', 'higher_better' => true, 'rows' => 10, 'link_type' => '', 'link' => '',
		'cache' => 60, 'refresh' => 0];

	/** Rows handed over by a procedure with nuDashboardData(). */
	public static ?array $procRows = null;

	// ------------------------------------------------------------------ reading

	public static function all(): array
	{
		NuSchema::ensure();
		return nu_db()->all('SELECT d.*, (SELECT COUNT(*) FROM ' . self::TW . ' w WHERE w.dashboard_id = d.zzzzsys_nunext_dashboard_id) AS widgets FROM ' . self::TD . ' d ORDER BY d.sort_order, d.code');
	}

	public static function get(string $id): ?array
	{
		return $id === '' ? null : nu_db()->one('SELECT * FROM ' . self::TD . ' WHERE zzzzsys_nunext_dashboard_id = ?', [$id]);
	}

	public static function byCode(string $code): ?array
	{
		if (!preg_match(self::CODE_RE, $code)) return null;
		try { return nu_db()->one('SELECT * FROM ' . self::TD . ' WHERE code = ?', [$code]); } catch (Throwable) { return null; }
	}

	/** Widgets of a dashboard in their order, options decoded and completed. */
	public static function widgets(string $dashId): array
	{
		$out = [];
		foreach (nu_db()->all('SELECT * FROM ' . self::TW . ' WHERE dashboard_id = ? ORDER BY sort_order, zzzzsys_nunext_widget_id', [$dashId]) as $w) $out[] = self::widgetRow($w);
		return $out;
	}

	public static function widget(string $id): ?array
	{
		$w = $id === '' ? null : nu_db()->one('SELECT * FROM ' . self::TW . ' WHERE zzzzsys_nunext_widget_id = ?', [$id]);
		return $w ? self::widgetRow($w) : null;
	}

	private static function widgetRow(array $w): array
	{
		$o = json_decode((string)($w['options'] ?? ''), true);
		$w['opt'] = self::options(is_array($o) ? $o : [], (string)$w['type']);
		return $w;
	}

	/** Options with defaults and limits. */
	public static function options(array $o, string $type): array
	{
		$d = self::OPT_DEFAULTS;
		$out = [];
		foreach ($d as $k => $def) {
			$v = $o[$k] ?? $def;
			$out[$k] = match (true) {
				is_bool($def) => (bool)$v,
				is_int($def) => (int)$v,
				default => mb_substr(trim((string)$v), 0, $k === 'link' ? 500 : 30),
			};
		}
		if (!in_array($out['width'], self::WIDTHS, true)) $out['width'] = $type === 'kpi' ? 3 : 6;
		$out['height'] = max(120, min(800, $out['height']));
		if (!in_array($out['chart'], self::CHARTS, true)) $out['chart'] = 'bar';
		if (!in_array($out['format'], self::FORMATS, true)) $out['format'] = 'number';
		$out['decimals'] = max(0, min(6, $out['decimals']));
		foreach (['low', 'high'] as $k) $out[$k] = is_numeric($out[$k]) ? $out[$k] : '';
		$out['rows'] = max(1, min(self::MAX_ROWS['table'], $out['rows']));
		if (!in_array($out['link_type'], self::LINKS, true)) $out['link_type'] = '';
		$out['cache'] = max(0, min(86400, $out['cache']));
		$out['refresh'] = $out['refresh'] > 0 ? max(10, min(86400, $out['refresh'])) : 0;
		return $out;
	}

	/** Access level ids of a dashboard (empty = every user). */
	public static function access(array $d): array
	{
		$a = json_decode((string)($d['access'] ?? ''), true);
		return is_array($a) ? array_values(array_filter(array_map('strval', $a))) : [];
	}

	/** May the current user see this dashboard (the globeadmin sees every one)? */
	public static function visible(array $d): bool
	{
		$u = NuAuth::user();
		if (!$u) return false;
		if ($u['globeadmin'] || NuAdmin::can('dashboards', 'view')) return true;
		$a = self::access($d);
		return !$a || (bool)array_intersect(NuUserAccess::levels($u), $a);   // main or additional access level (0.8.585)
	}

	/** Dashboards on the start page of the current user (not for the globeadmin – his start page is the tiles). */
	public static function forHome(): array
	{
		$u = NuAuth::user();
		if (!$u || $u['globeadmin']) return [];
		try {
			if (!nu_db()->tableExists(self::TD)) return [];
			$rows = nu_db()->all('SELECT * FROM ' . self::TD . " WHERE on_home = '1' ORDER BY sort_order, code");
		} catch (Throwable) {
			return [];
		}
		return array_values(array_filter($rows, [self::class, 'visible']));
	}

	/** Is the dashboard placed on this form (an HTML object or the form's JavaScript names its code)? */
	public static function placed(NuFormMeta $meta, string $code): bool
	{
		$text = (string)$meta->form['sfo_javascript'] . (string)$meta->form['sfo_edit_javascript'] . (string)$meta->form['sfo_browse_javascript'];
		foreach ($meta->objects as $o) $text .= "\n" . (string)($o['sob_html_code'] ?? '');
		return (bool)preg_match('/[\'"]' . preg_quote($code, '/') . '[\'"]/', $text);
	}

	/** Forms that show a dashboard: [form code, object id] (HTML objects and form JavaScript). */
	public static function usage(string $code): array
	{
		$db = nu_db();
		$out = [];
		$like = '%' . NuDB::like($code) . '%';
		foreach ($db->all("SELECT f.sfo_code, o.sob_all_id, o.sob_html_code FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
			WHERE o.sob_all_type = 'html' AND o.sob_html_code LIKE ? ORDER BY f.sfo_code", [$like]) as $r) {
			if (preg_match('/[\'"]' . preg_quote($code, '/') . '[\'"]/', (string)$r['sob_html_code'])) $out[] = [(string)$r['sfo_code'], (string)$r['sob_all_id']];
		}
		foreach ($db->all('SELECT sfo_code, sfo_javascript, sfo_edit_javascript, sfo_browse_javascript FROM zzzzsys_form WHERE sfo_javascript LIKE ? OR sfo_edit_javascript LIKE ? OR sfo_browse_javascript LIKE ?', [$like, $like, $like]) as $r) {
			if (preg_match('/nuNextDashboard\s*\(\s*[\'"]' . preg_quote($code, '/') . '[\'"]/', $r['sfo_javascript'] . $r['sfo_edit_javascript'] . $r['sfo_browse_javascript'])) $out[] = [(string)$r['sfo_code'], 'JavaScript'];
		}
		return $out;
	}

	// ------------------------------------------------------------------ dates

	/** [from, to] (Y-m-d, both included) of a range; custom: the given dates. */
	public static function range(string $key, string $from = '', string $to = ''): array
	{
		$d = new DateTimeImmutable('today');
		$ymd = static fn(DateTimeImmutable $x) => $x->format('Y-m-d');
		$q = intdiv((int)$d->format('n') - 1, 3) * 3 + 1;
		return match ($key) {
			'today' => [$ymd($d), $ymd($d)],
			'yesterday' => [$ymd($d->modify('-1 day')), $ymd($d->modify('-1 day'))],
			'7d' => [$ymd($d->modify('-6 days')), $ymd($d)],
			'90d' => [$ymd($d->modify('-89 days')), $ymd($d)],
			'month' => [$d->format('Y-m-01'), $d->format('Y-m-t')],
			'last_month' => [$d->modify('first day of last month')->format('Y-m-d'), $d->modify('last day of last month')->format('Y-m-d')],
			'quarter' => [$d->format('Y') . sprintf('-%02d-01', $q), $ymd((new DateTimeImmutable($d->format('Y') . sprintf('-%02d-01', $q)))->modify('+2 months')->modify('last day of this month'))],
			'year' => [$d->format('Y-01-01'), $d->format('Y-12-31')],
			'last_year' => [($d->format('Y') - 1) . '-01-01', ($d->format('Y') - 1) . '-12-31'],
			'all' => ['1900-01-01', '2999-12-31'],
			'custom' => [self::date($from) ?? $ymd($d->modify('-29 days')), self::date($to) ?? $ymd($d)],
			default => [$ymd($d->modify('-29 days')), $ymd($d)],      // 30d
		};
	}

	private static function date(string $s): ?string
	{
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4)) ? $s : null;
	}

	/** Does any widget use #DATE_FROM# / #DATE_TO# (the filter is shown only then)? */
	public static function usesDates(array $widgets): bool
	{
		foreach ($widgets as $w) if (preg_match('/#DATE_(FROM|TO)#/', self::sourceText($w))) return true;
		return false;
	}

	/** Uses the widget the record (#RECORD_ID# or a field of the form)? Then it waits for the record to be saved. */
	public static function usesRecord(array $w, ?NuFormMeta $meta): bool
	{
		$txt = self::sourceText($w);
		if (str_contains($txt, '#RECORD_ID#')) return true;
		if ($meta) foreach ($meta->objects as $o) if ($o['sob_all_type'] !== 'html' && str_contains($txt, '#' . $o['sob_all_id'] . '#') && $meta->table() !== '') return true;
		return false;
	}

	/** The text of the source (SQL, the SQL builder query or the procedure's code). */
	public static function sourceText(array $w): string
	{
		try {
			return match ((string)$w['source']) {
				'select' => (string)(nu_db()->value('SELECT sse_sql FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [(string)$w['query']]) ?? ''),
				'php' => (string)(nu_db()->value('SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [(string)$w['query']]) ?? ''),
				default => (string)$w['query'],
			};
		} catch (Throwable) {
			return '';
		}
	}

	// ------------------------------------------------------------------ data

	/**
	 * Runs the widget's source with the hash and returns ['columns' => [...], 'rows' => [[...]], 'more' => bool] (values as text or number).
	 * Results are cached per user for the widget's cache seconds ($force = read again).
	 */
	public static function data(array $w, array $hash, bool $force = false): array
	{
		$ttl = (int)$w['opt']['cache'];
		$h = $hash;
		foreach (array_keys(NuHash::now()) as $k) unset($h[$k]);
		unset($h['TABLE_ID'], $h['SESSION_ID']);
		$key = md5((string)$w['zzzzsys_nunext_widget_id'] . '|' . md5((string)$w['source'] . (string)$w['query'] . (string)$w['options']) . '|' . json_encode($h));
		$cache = &$_SESSION['nu_dash_cache'];
		if (!is_array($cache)) $cache = [];
		if (!$force && $ttl > 0 && isset($cache[$key]) && $cache[$key][0] > time()) return $cache[$key][1] + ['cached' => true];
		$res = self::run($w, $hash);
		if ($ttl > 0) {
			$cache[$key] = [time() + $ttl, $res];
			if (count($cache) > 60) { uasort($cache, static fn($a, $b) => $a[0] <=> $b[0]); $cache = array_slice($cache, -60, null, true); }
		}
		return $res;
	}

	/** Runs the source without cache. Throws RuntimeException with a message for the globeadmin. */
	public static function run(array $w, array $hash): array
	{
		$limit = self::MAX_ROWS[(string)$w['type']] ?? 1000;
		if ($w['type'] === 'table') $limit = (int)$w['opt']['rows'];
		if ($w['source'] === 'php') return self::runPhp($w, $hash, $limit);
		$sql = $w['source'] === 'select' ? (string)(nu_db()->value('SELECT sse_sql FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [(string)$w['query']]) ?? '') : (string)$w['query'];
		if (trim($sql) === '') throw new RuntimeException(t('dash_err_no_source'));
		return self::runSql($sql, $hash, $limit);
	}

	public static function runSql(string $sql, array $hash, int $limit): array
	{
		$sql = rtrim(trim($sql), "; \n\r\t");
		if (!NuDemo::readOnlySql(NuHash::replace($sql, [], NuHash::RAW))) throw new RuntimeException(t('sqb_err_select_only'));
		$sql = NuHash::replace($sql, $hash, NuHash::SQL);
		if (!NuDemo::readOnlySql($sql)) throw new RuntimeException(t('sqb_err_select_only'));
		if (NuDemo::active() && NuDemo::touchesHidden($sql)) throw new RuntimeException(t('demo_not_available'));
		return nu_db()->readOnly(static function (NuDB $db) use ($sql, $limit): array {
			$st = $db->run($sql);
			$cols = [];
			for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = (string)($st->getColumnMeta($i)['name'] ?? $i);
			$rows = [];
			$more = false;
			while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
				if (count($rows) >= $limit) { $more = true; break; }
				$rows[] = array_map([self::class, 'value'], $r);
			}
			$st->closeCursor();
			return ['columns' => $cols, 'rows' => $rows, 'more' => $more];
		}, self::TIMEOUT);
	}

	/** A procedure: its code runs read-only with the hash; nuDashboardData($rows) hands over the rows (list of arrays). */
	private static function runPhp(array $w, array $hash, int $limit): array
	{
		$php = nu_db()->one('SELECT zzzzsys_php_id, sph_code, sph_php, sph_demo FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [(string)$w['query']]);
		if (!$php) throw new RuntimeException(t('dash_err_no_source'));
		if (NuDemo::active() && (string)($php['sph_demo'] ?? '1') !== '1') throw new RuntimeException(t('demo_not_available'));
		self::$procRows = null;
		$errors = nu_db()->readOnly(static function () use ($php, $hash): array {
			NuPhp::reset();
			ob_start();
			try {
				NuPhp::run((string)$php['sph_php'], $hash + ['PROCEDURE_CODE' => (string)$php['sph_code']], (string)$php['sph_code']);
			} finally {
				ob_end_clean();
			}
			return NuPhp::$errors;
		}, self::TIMEOUT);
		if ($errors) throw new RuntimeException(strip_tags((string)($errors[0]['message'] ?? 'error')));
		$data = self::$procRows ?? [];
		self::$procRows = null;
		$cols = [];
		$rows = [];
		foreach (array_values($data) as $i => $r) {
			if (!is_array($r)) continue;
			if ($i === 0) $cols = array_map('strval', array_keys($r));
			if (count($rows) >= $limit) return ['columns' => $cols, 'rows' => $rows, 'more' => true];
			$rows[] = array_map([self::class, 'value'], array_values($r));
		}
		return ['columns' => $cols, 'rows' => $rows, 'more' => false];
	}

	/** Numbers as numbers (charts), everything else as text. */
	public static function value(mixed $v): mixed
	{
		if ($v === null || is_int($v) || is_float($v)) return $v;
		if (is_bool($v)) return (int)$v;
		$s = (string)$v;
		if (is_numeric($s) && preg_match('/^-?\d+(\.\d+)?$/', $s) && strlen($s) < 16) return str_contains($s, '.') ? (float)$s : (int)$s;
		return mb_substr($s, 0, 500);
	}

	// ------------------------------------------------------------------ hash

	/**
	 * Hash for a widget: start page (no form) or a form with its record. $clientValues: field values of the page – used only for fields that
	 * are not columns of the saved record (launch form criteria, display fields); the saved record's values come from the database.
	 */
	public static function hash(?NuFormMeta $meta, string $recordId, array $clientValues, array $dates): array
	{
		$extra = ['DATE_FROM' => $dates[0], 'DATE_TO' => $dates[1]];
		if (!$meta) return NuHash::plain([], $extra);
		$values = [];
		$row = $recordId !== '' && $recordId !== '-1' ? NuRecord::load($meta, $recordId) : null;
		foreach ($clientValues as $k => $v) if (is_string($k) && preg_match('/^\w{1,100}$/', $k) && (is_scalar($v) || $v === null)) $values[$k] = (string)$v;
		if ($row) foreach ($row as $k => $v) $values[(string)$k] = $v === null ? '' : (string)$v;      // saved values win
		return NuHash::forForm($meta, $recordId, $values, [], $extra);
	}

	// ------------------------------------------------------------------ saving

	/** @return array{0: ?string, 1: array} [id, errors] */
	public static function save(array $in, string $id = ''): array
	{
		$db = nu_db();
		NuSchema::ensure();
		$errors = [];
		$code = trim((string)($in['code'] ?? ''));
		$title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
		if (!preg_match(self::CODE_RE, $code)) $errors['code'] = t('dash_err_code');
		elseif ((int)$db->value('SELECT COUNT(*) FROM ' . self::TD . ' WHERE code = ? AND zzzzsys_nunext_dashboard_id <> ?', [$code, $id])) $errors['code'] = t('dash_err_code_taken');
		$access = array_values(array_unique(array_filter(array_map('strval', (array)($in['access'] ?? [])), static fn($a) => preg_match('/^[\w.-]{1,40}$/', $a))));
		$range = in_array((string)($in['date_range'] ?? ''), self::RANGES, true) && $in['date_range'] !== 'custom' ? (string)$in['date_range'] : '30d';
		if ($errors) return [null, $errors];
		$row = ['code' => $code, 'title' => $title, 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 1000),
			'on_home' => !empty($in['on_home']) ? '1' : '0', 'access' => json_encode($access), 'date_range' => $range,
			'sort_order' => (int)($in['sort_order'] ?? 0), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($id !== '' && self::get($id)) {
			$db->update(self::TD, $row, 'zzzzsys_nunext_dashboard_id', $id);
			NuLog::write('dashboard_saved', self::TD, $id, ['code' => $code]);
		} else {
			$id = nu_id();
			$db->insert(self::TD, ['zzzzsys_nunext_dashboard_id' => $id] + $row);
			NuLog::write('dashboard_added', self::TD, $id, ['code' => $code]);
		}
		return [$id, []];
	}

	public static function delete(string $id): void
	{
		$d = self::get($id);
		if (!$d) return;
		nu_db()->run('DELETE FROM ' . self::TW . ' WHERE dashboard_id = ?', [$id]);
		nu_db()->run('DELETE FROM ' . self::TD . ' WHERE zzzzsys_nunext_dashboard_id = ?', [$id]);
		NuLog::write('dashboard_deleted', self::TD, $id, ['code' => (string)$d['code']]);
	}

	/** Copy with all widgets; the code gets a suffix. */
	public static function copy(string $id): ?string
	{
		$d = self::get($id);
		if (!$d) return null;
		$db = nu_db();
		$base = mb_substr((string)$d['code'], 0, 44);
		$code = $base . '_copy';
		for ($n = 2; (int)$db->value('SELECT COUNT(*) FROM ' . self::TD . ' WHERE code = ?', [$code]); $n++) $code = $base . '_copy' . $n;
		$new = nu_id();
		$db->insert(self::TD, ['zzzzsys_nunext_dashboard_id' => $new, 'code' => $code] + array_intersect_key($d, array_flip(['title', 'description', 'on_home', 'access', 'date_range', 'sort_order']))
			+ ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)]);
		foreach (self::widgets($id) as $w) {
			$db->insert(self::TW, ['zzzzsys_nunext_widget_id' => nu_id(), 'dashboard_id' => $new] + array_intersect_key($w, array_flip(['sort_order', 'type', 'title', 'source', 'query', 'options'])));
		}
		NuLog::write('dashboard_added', self::TD, $new, ['code' => $code, 'copy_of' => (string)$d['code']]);
		return $new;
	}

	/** Widget fields from the editor (also for the test run): [row, errors]. */
	public static function widgetInput(array $in): array
	{
		$errors = [];
		$type = in_array((string)($in['type'] ?? ''), self::TYPES, true) ? (string)$in['type'] : 'kpi';
		$source = in_array((string)($in['source'] ?? ''), self::SOURCES, true) ? (string)$in['source'] : 'sql';
		$query = match ($source) {
			'select' => (string)($in['select_id'] ?? ''),
			'php' => (string)($in['php_id'] ?? ''),
			default => (string)($in['sql'] ?? ''),
		};
		if (trim($query) === '') $errors[$source === 'sql' ? 'sql' : ($source === 'select' ? 'select_id' : 'php_id')] = t('err_required');
		elseif ($source === 'sql' && !NuDemo::readOnlySql(NuHash::replace(rtrim(trim($query), "; \n\r\t"), [], NuHash::RAW))) $errors['sql'] = t('sqb_err_select_only');
		$opt = self::options(is_array($in['opt'] ?? null) ? $in['opt'] : [], $type);
		if ($opt['link_type'] === 'url' && $opt['link'] !== '' && !preg_match('~^(https?://|index\.php\?|\?)~i', $opt['link'])) $errors['link'] = t('dash_err_link');
		$row = ['type' => $type, 'title' => mb_substr(trim((string)($in['title'] ?? '')), 0, 200), 'source' => $source, 'query' => $query,
			'options' => json_encode($opt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
		return [$row, $errors];
	}

	/** @return array{0: ?string, 1: array} */
	public static function saveWidget(string $dashId, array $in, string $id = ''): array
	{
		[$row, $errors] = self::widgetInput($in);
		if ($errors) return [null, $errors];
		$db = nu_db();
		if ($id !== '' && self::widget($id)) {
			$db->update(self::TW, $row, 'zzzzsys_nunext_widget_id', $id);
		} else {
			$id = nu_id();
			$row['sort_order'] = (int)$db->value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM ' . self::TW . ' WHERE dashboard_id = ?', [$dashId]);
			$db->insert(self::TW, ['zzzzsys_nunext_widget_id' => $id, 'dashboard_id' => $dashId] + $row);
		}
		self::touch($dashId);
		NuLog::write('dashboard_widget_saved', self::TW, $id, ['dashboard' => $dashId, 'title' => $row['title'], 'type' => $row['type']]);
		return [$id, []];
	}

	public static function deleteWidget(string $dashId, string $id): void
	{
		nu_db()->run('DELETE FROM ' . self::TW . ' WHERE zzzzsys_nunext_widget_id = ? AND dashboard_id = ?', [$id, $dashId]);
		self::touch($dashId);
		NuLog::write('dashboard_widget_deleted', self::TW, $id, ['dashboard' => $dashId]);
	}

	/** New order of the widgets (ids in order). */
	public static function reorder(string $dashId, array $ids): void
	{
		$n = 10;
		foreach ($ids as $wid) {
			nu_db()->run('UPDATE ' . self::TW . ' SET sort_order = ? WHERE zzzzsys_nunext_widget_id = ? AND dashboard_id = ?', [$n, (string)$wid, $dashId]);
			$n += 10;
		}
		self::touch($dashId);
	}

	private static function touch(string $dashId): void
	{
		nu_db()->run('UPDATE ' . self::TD . ' SET updated_at = ?, updated_by = ? WHERE zzzzsys_nunext_dashboard_id = ?',
			[date('Y-m-d H:i:s'), mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50), $dashId]);
	}

	// ------------------------------------------------------------------ output for the browser

	/** Widget definition for the renderer (no source). */
	public static function clientWidget(array $w): array
	{
		$o = $w['opt'];
		$link = '';
		if ($o['link'] !== '') {
			$link = match ($o['link_type']) {
				'form' => nu_url('form', ['f' => $o['link']]),
				'report' => nu_url('report', ['c' => $o['link']]),
				'url' => $o['link'],
				default => '',
			};
		}
		return ['id' => (string)$w['zzzzsys_nunext_widget_id'], 'type' => (string)$w['type'], 'title' => (string)$w['title'], 'link' => $link,
			'opt' => array_diff_key($o, array_flip(['link', 'link_type', 'cache']))];
	}

	/**
	 * Pages with dashboards keep the strict CSP but allow style ATTRIBUTES (ApexCharts sets them on its SVG elements) and <style>
	 * elements with this request's nonce (ApexCharts' legend styles, chart.nonce); scripts stay 'self' only. Form pages have their own policy.
	 */
	public static function csp(): void
	{
		self::$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
		header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'nonce-" . self::$nonce . "'; style-src-attr 'unsafe-inline'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
	}

	/** Style nonce of this page (ApexCharts' legend <style>, chart.nonce) – only set where csp() ran. */
	private static string $nonce = '';

	/** The renderer's <script> tag (start page, form pages, editor). */
	public static function scriptTag(): string
	{
		return '<script src="' . nu_asset('assets/js/nudashboard.js') . '" data-url="' . h(nu_url('dashboardapi')) . '" data-csrf="' . h(nu_csrf_token())
			. '" data-apex="' . h(nu_asset(NU_APEXCHARTS . '/apexcharts.min.js')) . '" data-apex-css="' . h(nu_asset(NU_APEXCHARTS . '/apexcharts.css')) . '"'
			. (self::$nonce !== '' ? ' data-style-nonce="' . h(self::$nonce) . '"' : '') . '></script>';
	}

	/** Texts of the renderer in the reader's language. */
	public static function messages(): array
	{
		$m = ['refresh' => t('dash_refresh'), 'no_data' => t('dash_no_data'), 'error' => t('dash_error'), 'after_save' => t('dash_after_save'),
			'from' => t('dash_from'), 'to' => t('dash_to'), 'range' => t('dash_range'), 'more' => t('dash_more_rows'), 'previous' => t('dash_previous')];
		foreach (self::RANGES as $r) $m['r_' . $r] = t('dash_r_' . $r);
		return $m;
	}
}
