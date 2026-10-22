<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * One form as data (0.8.386): all rows that define it – form, tabs, objects, events, browse columns, form / lookup PHP and the
 * SQL builder rows. Used by the history (snapshot / restore), form drafts (copy, preview, publish) and the compare view.
 *
 * Format: {v: 2, form: row, tabs: [rows], objects: [rows], events: [rows], browse: [rows], php: [rows], selects: [rows], clauses: [rows]}.
 * History rows of earlier versions have no "v" (and no selects / clauses): restoring them leaves the SQL builder rows and the form
 * hooks other than the six of the builder as they are (apply(), legacy).
 */
final class NuFormSnapshot
{
	public const VERSION = 2;
	public const KEYS = ['tabs' => 'zzzzsys_tab_id', 'objects' => 'zzzzsys_object_id', 'events' => 'zzzzsys_event_id', 'browse' => 'zzzzsys_browse_id',
		'php' => 'zzzzsys_php_id', 'selects' => 'zzzzsys_select_id', 'clauses' => 'zzzzsys_select_clause_id'];
	public const TABLES = ['tabs' => 'zzzzsys_tab', 'objects' => 'zzzzsys_object', 'events' => 'zzzzsys_event', 'browse' => 'zzzzsys_browse',
		'php' => 'zzzzsys_php', 'selects' => 'zzzzsys_select', 'clauses' => 'zzzzsys_select_clause'];
	/** Columns with code – the compare view shows them as a line diff. */
	public const CODE_COLUMNS = ['sfo_javascript', 'sfo_browse_javascript', 'sfo_edit_javascript', 'sfo_browse_sql', 'sfo_style', 'sob_select_sql', 'sob_display_sql',
		'sob_html_code', 'sob_input_javascript', 'sob_lookup_javascript', 'sob_html_javascript', 'sob_all_access_condition', 'sob_calc_formula', 'sph_php', 'sev_javascript'];
	/** Placement only – a changed object with nothing else changed counts as "moved". */
	private const PLACEMENT = ['sob_all_top', 'sob_all_left', 'sob_all_width', 'sob_all_height', 'sob_all_order', 'sob_all_json'];
	private const LEGACY_HOOKS = ['BB', 'BE', 'BS', 'AS', 'BD', 'AD'];
	private const HOOK_RE = '/^[A-Z]{2,3}$/';

	/** Everything that defines the form, as plain rows (null when the form does not exist). */
	public static function capture(string $formId): ?array
	{
		$db = nu_db();
		$form = $db->one('SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]);
		if (!$form) return null;
		$objects = $db->all('SELECT * FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$formId]);
		$ids = array_map('strval', array_column($objects, 'zzzzsys_object_id'));
		$events = $php = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$in = implode(',', array_fill(0, count($chunk), '?'));
			$events = array_merge($events, $db->all("SELECT * FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN ($in)", $chunk));
			$php = array_merge($php, $db->all("SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id IN ($in)", array_map(static fn($i) => $i . '_AB', $chunk)));
		}
		// form hooks: <form id>_<HOOK> – like the cloner (not only the six of the builder)
		foreach ($db->all("SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id LIKE ? ESCAPE '!'", [self::prefix($formId)]) as $p) {
			if (preg_match(self::HOOK_RE, substr((string)$p['zzzzsys_php_id'], strlen($formId) + 1))) $php[] = $p;
		}
		$selects = $clauses = [];
		if ($db->tableExists('zzzzsys_select') && $db->tableExists('zzzzsys_select_clause')) {
			$owners = array_flip(array_merge([$formId], $ids));
			foreach ($db->all('SELECT zzzzsys_select_id FROM zzzzsys_select') as $s) {          // few rows: filter in PHP
				$sid = (string)$s['zzzzsys_select_id'];
				$p = strrpos($sid, '_');
				if ($p !== false && isset($owners[substr($sid, 0, $p)]) && preg_match(self::HOOK_RE, substr($sid, $p + 1))) {
					$selects[] = $db->one('SELECT * FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$sid]);
					$clauses = array_merge($clauses, $db->all('SELECT * FROM zzzzsys_select_clause WHERE ssc_zzzzsys_select_id = ?', [$sid]));
				}
			}
		}
		return ['v' => self::VERSION, 'form' => $form, 'tabs' => $db->all('SELECT * FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ?', [$formId]),
			'objects' => $objects, 'events' => $events, 'browse' => $db->all('SELECT * FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = ?', [$formId]),
			'php' => $php, 'selects' => array_values(array_filter($selects)), 'clauses' => $clauses];
	}

	/**
	 * Replaces the form's rows by the snapshot's – call inside a transaction. The form row is updated, the other rows of the current
	 * state are deleted and the snapshot's inserted (only the columns the tables have today). A snapshot of an earlier version (no
	 * "v") replaces only what it holds: tabs, objects, events, browse, the six builder hooks and the lookups' After Browse code.
	 */
	public static function apply(NuDB $db, string $formId, array $snap): void
	{
		$cur = self::capture($formId) ?? throw new RuntimeException(t('form_err_not_found'));
		$legacy = !isset($snap['v']);
		if (!$legacy) {
			foreach ($cur['clauses'] as $c) $db->delete('zzzzsys_select_clause', 'zzzzsys_select_clause_id', (string)$c['zzzzsys_select_clause_id']);
			foreach ($cur['selects'] as $s) $db->delete('zzzzsys_select', 'zzzzsys_select_id', (string)$s['zzzzsys_select_id']);
		}
		foreach ($cur['events'] as $e) $db->delete('zzzzsys_event', 'zzzzsys_event_id', (string)$e['zzzzsys_event_id']);
		foreach ($cur['php'] as $p) {
			$pid = (string)$p['zzzzsys_php_id'];
			if ($legacy && !str_ends_with($pid, '_AB') && !in_array(substr($pid, strlen($formId) + 1), self::LEGACY_HOOKS, true)) continue;
			$db->delete('zzzzsys_php', 'zzzzsys_php_id', $pid);
		}
		$db->delete('zzzzsys_object', 'sob_all_zzzzsys_form_id', $formId);
		$db->delete('zzzzsys_tab', 'syt_zzzzsys_form_id', $formId);
		$db->delete('zzzzsys_browse', 'sbr_zzzzsys_form_id', $formId);
		$form = self::only('zzzzsys_form', $snap['form']);
		unset($form['zzzzsys_form_id']);
		$db->update('zzzzsys_form', $form, 'zzzzsys_form_id', $formId);
		foreach (self::TABLES as $key => $table) {
			if (!isset($snap[$key]) || !$db->tableExists($table)) continue;
			foreach ($snap[$key] as $r) $db->insert($table, self::only($table, $r));
		}
	}

	/** Lists sorted by primary key, keys sorted, values as strings (null stays null) – equal content gives equal JSON. */
	public static function canonical(array $snap): array
	{
		$row = static function ($r): array {
			$r = (array)$r;
			ksort($r);
			return array_map(static fn($v) => $v === null ? null : (is_scalar($v) ? (string)$v : json_encode($v)), $r);
		};
		$out = ['form' => $row($snap['form'] ?? [])];
		foreach (self::KEYS as $key => $pk) {
			$list = array_map($row, $snap[$key] ?? []);
			usort($list, static fn($a, $b) => strcmp((string)($a[$pk] ?? ''), (string)($b[$pk] ?? '')));
			$out[$key] = $list;
		}
		return $out;
	}

	/** sha256 of the canonical snapshot; $like: only the columns the rows of $like have (a column added by an update is no change). */
	public static function hash(array $snap, ?array $like = null): string
	{
		$c = self::canonical($snap);
		if ($like !== null) {
			$l = self::canonical($like);
			$cols = static function (array $rows): ?array {
				if (!$rows) return null;
				$k = [];
				foreach ($rows as $r) foreach (array_keys($r) as $n) $k[$n] = true;
				return $k;
			};
			$fc = $l['form'] ? array_fill_keys(array_keys($l['form']), true) : null;
			if ($fc !== null) $c['form'] = array_intersect_key($c['form'], $fc);
			foreach (array_keys(self::KEYS) as $key) {
				$k = $cols($l[$key]);
				if ($k !== null) $c[$key] = array_map(static fn($r) => array_intersect_key($r, $k), $c[$key]);
			}
		}
		return hash('sha256', (string)json_encode($c, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}

	/**
	 * Differences $a (old: live or base) → $b (new: draft or live) – for the compare view, the change count and conflicts.
	 * @return array{form: list<array{0: string, 1: ?string, 2: ?string}>, tabs: array, objects: array, events: list<array>, php: list<array>,
	 *   browse: array, sql_builder: bool, counts: array<string, int>}
	 */
	public static function diff(array $a, array $b): array
	{
		$A = self::canonical($a);
		$B = self::canonical($b);
		$fields = static function (array $x, array $y, array $skip): array {
			$out = [];
			foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $k) {
				if (in_array($k, $skip, true)) continue;
				$ov = $x[$k] ?? null; $nv = $y[$k] ?? null;
				if (!array_key_exists($k, $x) || !array_key_exists($k, $y)) { if ((string)$ov === '' && (string)$nv === '') continue; }   // a column only one side has, empty
				if ($ov !== $nv && !((string)$ov === '' && (string)$nv === '' && ($ov === null || $nv === null))) $out[] = [$k, $ov, $nv];
			}
			return $out;
		};
		$byId = static function (array $rows, string $pk): array { $o = []; foreach ($rows as $r) $o[(string)($r[$pk] ?? '')] = $r; return $o; };
		$d = ['form' => $fields($A['form'], $B['form'], ['zzzzsys_form_id'])];
		// tabs
		$ta = $byId($A['tabs'], 'zzzzsys_tab_id'); $tb = $byId($B['tabs'], 'zzzzsys_tab_id');
		$d['tabs'] = ['added' => [], 'removed' => [], 'changed' => [], 'order' => false];
		foreach ($tb as $id => $r) if (!isset($ta[$id])) $d['tabs']['added'][] = ['id' => $id, 'title' => $r['syt_title'] ?? ''];
		foreach ($ta as $id => $r) if (!isset($tb[$id])) $d['tabs']['removed'][] = ['id' => $id, 'title' => $r['syt_title'] ?? ''];
		foreach ($tb as $id => $r) {
			if (!isset($ta[$id])) continue;
			$f = $fields($ta[$id], $r, ['syt_zzzzsys_form_id', 'syt_order']);
			if ($f) $d['tabs']['changed'][] = ['id' => $id, 'title' => $r['syt_title'] ?? '', 'fields' => $f];
		}
		$ord = static function (array $rows, string $o, string $pk): array { usort($rows, static fn($x, $y) => [(int)($x[$o] ?? 0), $x[$pk]] <=> [(int)($y[$o] ?? 0), $y[$pk]]); return array_column($rows, $pk); };
		$common = array_intersect(array_keys($ta), array_keys($tb));
		$d['tabs']['order'] = array_values(array_intersect($ord(array_values($ta), 'syt_order', 'zzzzsys_tab_id'), $common)) !== array_values(array_intersect($ord(array_values($tb), 'syt_order', 'zzzzsys_tab_id'), $common));
		// objects
		$oa = $byId($A['objects'], 'zzzzsys_object_id'); $ob = $byId($B['objects'], 'zzzzsys_object_id');
		$sum = static fn(array $r) => ['id' => $r['zzzzsys_object_id'] ?? '', 'sob_all_id' => $r['sob_all_id'] ?? '', 'type' => $r['sob_all_type'] ?? '', 'label' => $r['sob_all_label'] ?? '', 'tab' => $r['sob_all_zzzzsys_tab_id'] ?? ''];
		$d['objects'] = ['added' => [], 'removed' => [], 'changed' => [], 'moved' => 0, 'order' => false];
		foreach ($ob as $id => $r) if (!isset($oa[$id])) $d['objects']['added'][] = $sum($r);
		foreach ($oa as $id => $r) if (!isset($ob[$id])) $d['objects']['removed'][] = $sum($r);
		foreach ($ob as $id => $r) {
			if (!isset($oa[$id])) continue;
			$f = $fields($oa[$id], $r, ['sob_all_zzzzsys_form_id', 'sob_input_count']);
			if (!$f) continue;
			$real = array_values(array_filter($f, static fn($x) => !in_array($x[0], self::PLACEMENT, true)));
			if ($real) $d['objects']['changed'][] = ['id' => $id, 'sob_all_id' => $r['sob_all_id'] ?? '', 'fields' => $real];
			else $d['objects']['moved']++;
		}
		// events: per object and event name
		$ev = static function (array $rows): array { $o = []; foreach ($rows as $r) { $k = ($r['sev_zzzzsys_object_id'] ?? '') . "\n" . strtolower(trim((string)($r['sev_event'] ?? ''))); $o[$k] = ltrim(($o[$k] ?? '') . "\n" . ($r['sev_javascript'] ?? '')); } return $o; };
		$ea = $ev($A['events']); $eb = $ev($B['events']);
		$d['events'] = [];
		foreach (array_unique(array_merge(array_keys($ea), array_keys($eb))) as $k) {
			if (($ea[$k] ?? '') === ($eb[$k] ?? '')) continue;
			[$oid, $name] = explode("\n", $k, 2);
			$d['events'][] = ['object_id' => $oid, 'sob_all_id' => $ob[$oid]['sob_all_id'] ?? $oa[$oid]['sob_all_id'] ?? '', 'event' => $name, 'old' => $ea[$k] ?? '', 'new' => $eb[$k] ?? ''];
		}
		// php: form hooks and _AB
		$pa = $byId($A['php'], 'zzzzsys_php_id'); $pb = $byId($B['php'], 'zzzzsys_php_id');
		$d['php'] = [];
		foreach (array_unique(array_merge(array_keys($pa), array_keys($pb))) as $id) {
			$o = (string)($pa[$id]['sph_php'] ?? ''); $n = (string)($pb[$id]['sph_php'] ?? '');
			if ($o === $n) continue;
			$p = strrpos($id, '_');
			$d['php'][] = ['id' => $id, 'hook' => $p === false ? '' : substr($id, $p + 1), 'old' => $o, 'new' => $n];
		}
		// browse
		$ba = $byId($A['browse'], 'zzzzsys_browse_id'); $bb = $byId($B['browse'], 'zzzzsys_browse_id');
		$d['browse'] = ['added' => [], 'removed' => [], 'changed' => [], 'order' => false];
		foreach ($bb as $id => $r) if (!isset($ba[$id])) $d['browse']['added'][] = ['id' => $id, 'title' => $r['sbr_title'] ?? '', 'display' => $r['sbr_display'] ?? ''];
		foreach ($ba as $id => $r) if (!isset($bb[$id])) $d['browse']['removed'][] = ['id' => $id, 'title' => $r['sbr_title'] ?? '', 'display' => $r['sbr_display'] ?? ''];
		foreach ($bb as $id => $r) {
			if (!isset($ba[$id])) continue;
			$f = $fields($ba[$id], $r, ['sbr_zzzzsys_form_id', 'sbr_order']);
			if ($f) $d['browse']['changed'][] = ['id' => $id, 'title' => $r['sbr_title'] ?? '', 'fields' => $f];
		}
		$cb = array_intersect(array_keys($ba), array_keys($bb));
		$d['browse']['order'] = array_values(array_intersect($ord(array_values($ba), 'sbr_order', 'zzzzsys_browse_id'), $cb)) !== array_values(array_intersect($ord(array_values($bb), 'sbr_order', 'zzzzsys_browse_id'), $cb));
		$d['sql_builder'] = json_encode([$A['selects'], $A['clauses']]) !== json_encode([$B['selects'], $B['clauses']]);
		$d['counts'] = [
			'form' => count($d['form']),
			'tabs' => count($d['tabs']['added']) + count($d['tabs']['removed']) + count($d['tabs']['changed']) + ($d['tabs']['order'] ? 1 : 0),
			'objects' => count($d['objects']['added']) + count($d['objects']['removed']) + count($d['objects']['changed']) + ($d['objects']['moved'] ? 1 : 0),
			'events' => count($d['events']), 'php' => count($d['php']),
			'browse' => count($d['browse']['added']) + count($d['browse']['removed']) + count($d['browse']['changed']) + ($d['browse']['order'] ? 1 : 0),
			'sql_builder' => $d['sql_builder'] ? 1 : 0,
		];
		$d['counts']['total'] = array_sum($d['counts']);
		return $d;
	}

	/** Only the columns the table has today (a snapshot may be older or newer than the schema). */
	public static function only(string $table, array $row): array
	{
		$cols = nu_db()->columns($table);
		$out = [];
		foreach ($row as $k => $v) if (($c = NuRecord::column($cols, (string)$k)) !== null) $out[$c] = $v;
		return $out;
	}

	private static function prefix(string $id): string { return str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $id) . '!_%'; }
}
