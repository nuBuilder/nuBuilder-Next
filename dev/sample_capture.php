<?php
declare(strict_types=1);

/*
 * Captures a sample application built on a test installation into install/samples/<key>.json (read by core/nusampleset.php).
 *   php dev/sample_capture.php <installation folder> <key> <prefix> [version]
 *     e.g. php dev/sample_capture.php ../site ticket tkt_ 1
 * What belongs to the sample (prefix tkt_):
 *   forms whose code starts with the prefix (+ tabs, objects, events, list columns, hooks <form id>_XX / <object id>_AB, field access),
 *   procedures, reports, SQL builder queries, e-mail templates, files with that code prefix, access levels with that code prefix (+ their
 *   rights, their users, the start pages of these levels), permission items with that ident prefix (+ assignments to the sample's users),
 *   dashboards with that code prefix (+ widgets), and the tables whose name starts with the prefix (structure + all rows).
 * Ids made by design mode (nu_id) are replaced by readable ids with the prefix – everywhere, also inside JSON and code – so the sample can
 * be installed next to anything else and removed again exactly. Form ids and object ids get no "_" after the prefix (nuBuilder splits
 * hook ids like <form id>_BE at the "_").
 */
if (PHP_SAPI !== 'cli' || $argc < 4) { fwrite(STDERR, "usage: php dev/sample_capture.php <installation> <key> <prefix> [version]\n"); exit(2); }
$project = dirname(__DIR__);
[$site, $key, $prefix] = [$argv[1], $argv[2], $argv[3]];
$version = (int)($argv[4] ?? 1);
chdir($site);
require 'core/nuapp.php';
$db = nu_db();
$tag = rtrim($prefix, '_');                                  // tkt
$like = static fn(string $col) => 'SUBSTRING(' . $col . ', 1, ' . strlen($prefix) . ") = '" . $prefix . "'";
$in = static fn(array $l) => $l ? "'" . implode("', '", array_map(static fn($v) => str_replace("'", "''", (string)$v), $l)) . "'" : "''";

$rows = [];                                                  // [table, row]
$take = static function (string $table, string $where) use ($db, &$rows): array {
	if (!$db->tableExists($table)) return [];
	$r = $db->all('SELECT * FROM ' . $table . ' WHERE ' . $where);
	foreach ($r as $x) $rows[] = [$table, $x];
	return $r;
};

// ---------------------------------------------------------------- forms and their parts
$forms = $take('zzzzsys_form', $like('sfo_code'));
$fids = array_column($forms, 'zzzzsys_form_id');
if (!$fids) { fwrite(STDERR, "no forms with code prefix $prefix\n"); exit(1); }
$take('zzzzsys_tab', 'syt_zzzzsys_form_id IN (' . $in($fids) . ')');
$objs = $take('zzzzsys_object', 'sob_all_zzzzsys_form_id IN (' . $in($fids) . ')');
$oids = array_column($objs, 'zzzzsys_object_id');
$take('zzzzsys_event', 'sev_zzzzsys_object_id IN (' . $in($oids) . ')');
$take('zzzzsys_browse', 'sbr_zzzzsys_form_id IN (' . $in($fids) . ')');
$hooks = [];
foreach ($fids as $f) foreach (['BB', 'BE', 'BS', 'AS', 'BD', 'AD'] as $h) $hooks[] = $f . '_' . $h;
foreach ($oids as $o) $hooks[] = $o . '_AB';
$take('zzzzsys_php', 'zzzzsys_php_id IN (' . $in($hooks) . ')');
$take('zzzzsys_nunext_object_access', 'noa_form_id IN (' . $in($fids) . ')');

// ---------------------------------------------------------------- procedures, reports, queries, templates, files, formats
$procs = $take('zzzzsys_php', $like('sph_code') . ' AND zzzzsys_php_id NOT IN (' . $in($hooks) . ')');
$reps = $take('zzzzsys_report', $like('sre_code'));
$sels = $take('zzzzsys_select', $like('sse_code'));
$take('zzzzsys_select_clause', 'ssc_zzzzsys_select_id IN (' . $in(array_column($sels, 'zzzzsys_select_id')) . ')');
$take('zzzzsys_email_template', $like('emt_code'));
$take('zzzzsys_file', $like('sfi_code'));

// ---------------------------------------------------------------- access levels, rights, users, permission items, start pages
$levels = $take('zzzzsys_access', $like('sal_code'));
$lids = array_column($levels, 'zzzzsys_access_id');
$take('zzzzsys_access_form', 'slf_zzzzsys_access_id IN (' . $in($lids) . ')');
$take('zzzzsys_access_php', 'slp_zzzzsys_access_id IN (' . $in($lids) . ')');
$take('zzzzsys_access_report', 'sre_zzzzsys_access_id IN (' . $in($lids) . ')');
$users = $db->all('SELECT * FROM zzzzsys_user WHERE sus_zzzzsys_access_id IN (' . $in($lids) . ') ORDER BY sus_login_name');
$uids = array_column($users, 'zzzzsys_user_id');
$items = $take('zzzzsys_permission_item', $like('pme_ident'));
$take('zzzzsys_user_permission', 'upe_zzzzsys_user_id IN (' . $in($uids) . ') AND upe_ident IN (' . $in(array_column($items, 'zzzzsys_permission_item_id')) . ')');
$homes = [];
foreach ($lids as $l) foreach ($db->all("SELECT * FROM zzzzsys_nunext_list_view WHERE list_key = ? AND access_ids = ?", [NuHome::LIST_USER, json_encode([$l])]) as $x) { $rows[] = ['zzzzsys_nunext_list_view', $x]; $homes[] = $x; }
$dash = $take('zzzzsys_nunext_dashboard', $like('code'));
$take('zzzzsys_nunext_widget', 'dashboard_id IN (' . $in(array_column($dash, 'zzzzsys_nunext_dashboard_id')) . ')');

// ---------------------------------------------------------------- own tables
$tables = [];
$indexes = [];
foreach ($db->all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME", [str_replace('_', '\\_', $prefix) . '%']) as $t) {
	$name = (string)$t['t'];
	$cols = [];
	$audit = true;
	$names = [];
	foreach ($db->all('SELECT COLUMN_NAME AS c, DATA_TYPE AS d, CHARACTER_MAXIMUM_LENGTH AS l, NUMERIC_PRECISION AS p, NUMERIC_SCALE AS s, COLUMN_TYPE AS ct FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$name]) as $c) {
		$names[] = $c['c'];
		if ($c['c'] === $name . '_id' || isset(NuDDL::AUDIT[$c['c']])) continue;
		$d = strtolower((string)$c['d']);
		[$type, $len] = match (true) {
			$d === 'varchar' && (int)$c['l'] === 25 && str_contains((string)$c['c'], '_' . $prefix) => ['id', ''],
			$d === 'varchar' => ['text', (string)$c['l']],
			in_array($d, ['longtext', 'mediumtext', 'text'], true) => ['longtext', ''],
			$d === 'tinyint' => ['bool', ''],
			$d === 'int' => ['int', ''], $d === 'bigint' => ['bigint', ''],
			$d === 'decimal' => ['decimal', $c['p'] . ',' . $c['s']],
			$d === 'date' => ['date', ''], $d === 'datetime' => ['datetime', ''], $d === 'time' => ['time', ''],
			default => ['text', '255'],
		};
		$cols[] = ['name' => (string)$c['c'], 'type' => $type, 'length' => $len];
		if ($type === 'id') $indexes[$name][] = (string)$c['c'];
	}
	foreach (array_keys(NuDDL::AUDIT) as $a) if (!in_array($a, $names, true)) $audit = false;
	$tables[$name] = ['columns' => $cols, 'audit' => $audit];
	foreach ($db->all('SELECT * FROM ' . $name . ' ORDER BY 1') as $r) $rows[] = [$name, $r];
}
// parents before children: categories / tickets before comments (creation order = name order is fine for tables, rows have no FKs)

// ---------------------------------------------------------------- new ids
$map = [];
$n = [];
$next = static function (string $kind) use (&$n, $prefix): string { $n[$kind] = ($n[$kind] ?? 0) + 1; return $prefix . $kind . '_' . sprintf('%03d', $n[$kind]); };
foreach ($forms as $f) $map[$f['zzzzsys_form_id']] = $tag . str_replace('_', '', substr((string)$f['sfo_code'], strlen($prefix)));
$on = 0;
foreach ($objs as $o) $map[$o['zzzzsys_object_id']] = $tag . 'o' . sprintf('%03d', ++$on);
foreach ($levels as $l) $map[$l['zzzzsys_access_id']] = (string)$l['sal_code'];
foreach ($users as $u) $map[$u['zzzzsys_user_id']] = (string)$u['sus_login_name'];
$kinds = ['zzzzsys_tab' => 'tab', 'zzzzsys_event' => 'ev', 'zzzzsys_browse' => 'br', 'zzzzsys_nunext_object_access' => 'fa', 'zzzzsys_report' => 'rpt',
	'zzzzsys_select' => 'sql', 'zzzzsys_select_clause' => 'sqc', 'zzzzsys_email_template' => 'emt', 'zzzzsys_file' => 'file', 'zzzzsys_access_form' => 'af',
	'zzzzsys_access_php' => 'ap', 'zzzzsys_access_report' => 'ar', 'zzzzsys_permission_item' => 'pi', 'zzzzsys_user_permission' => 'up',
	'zzzzsys_nunext_list_view' => 'uh', 'zzzzsys_nunext_dashboard' => 'dash', 'zzzzsys_nunext_widget' => 'w', 'zzzzsys_php' => 'proc'];
$pkOf = static fn(string $t) => $t . '_id';
foreach ($rows as [$t, $r]) {
	$id = (string)($r[$pkOf($t)] ?? '');
	if ($id === '' || isset($map[$id]) || str_starts_with($t, $prefix)) continue;
	if ($t === 'zzzzsys_php' && preg_match('/^(.+)_(BB|BE|BS|AS|BD|AD|AB)$/', $id, $m) && in_array($m[1], array_merge($fids, $oids), true)) continue;   // hooks follow their form / object
	if (str_starts_with($id, $prefix) || (str_starts_with($id, $tag) && $t !== 'zzzzsys_php')) { $map[$id] = $id; continue; }   // already readable
	$map[$id] = isset($kinds[$t]) ? $next($kinds[$t]) : $next('x');
}
foreach ($rows as [$t, $r]) {                                // hook ids: <new form / object id>_XX
	if ($t !== 'zzzzsys_php') continue;
	$id = (string)$r['zzzzsys_php_id'];
	if (preg_match('/^(.+)_(BB|BE|BS|AS|BD|AD|AB)$/', $id, $m) && isset($map[$m[1]])) $map[$id] = $map[$m[1]] . '_' . $m[2];
}
// replace: exact values everywhere, inside text only ids long enough not to be ordinary words (nu_id: 15+ characters)
uksort($map, static fn($a, $b) => strlen((string)$b) - strlen((string)$a));
$long = array_filter($map, static fn($v, $k) => strlen((string)$k) >= 12 && $k !== $v, ARRAY_FILTER_USE_BOTH);
$clean = static function ($v) use ($map, $long) {
	if (!is_string($v)) return $v;
	if (isset($map[$v])) return $map[$v];
	return $long ? strtr($v, array_map('strval', $long)) : $v;
};
$out = [];
foreach ($rows as [$t, $r]) {
	if (in_array($t, ['zzzzsys_nunext_list_view', 'zzzzsys_nunext_dashboard', 'zzzzsys_nunext_widget', 'zzzzsys_nunext_object_access'], true)) {
		foreach (['updated_at', 'updated_by'] as $k) if (array_key_exists($k, $r)) $r[$k] = $k === 'updated_by' ? 'sample' : null;
	}
	$out[] = [$t, array_map($clean, $r)];
}
$userRows = [];
foreach ($users as $u) {
	unset($u['sus_login_password']);
	$u['sus_json'] = null;                                   // no login tokens, 2FA secrets or personal settings
	$userRows[] = array_map($clean, $u);
}
$home = '';
foreach ($forms as $f) if ((string)$f['sfo_type'] === 'launch' && str_ends_with((string)$f['sfo_code'], 'home')) $home = $map[$f['zzzzsys_form_id']];
if ($home === '') { fwrite(STDERR, "no launch form <prefix>home\n"); exit(1); }
$def = ['key' => $key, 'version' => $version, 'prefix' => $prefix, 'home' => $home, 'date_ref' => date('Y-m-d'),
	'tables' => $tables, 'indexes' => $indexes, 'rows' => $out, 'users' => $userRows];
@mkdir($project . '/install/samples', 0755, true);
$file = $project . '/install/samples/' . $key . '.json';
file_put_contents($file, json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
$left = [];
$all = json_encode($def);
foreach ($rows as [$t, $r]) { $id = (string)($r[$pkOf($t)] ?? ''); if (strlen($id) >= 12 && !str_starts_with($id, $tag) && str_contains($all, $id)) $left[] = $t . ':' . $id; }
echo $file, ': ', count($out), ' rows, ', count($userRows), ' users, ', count($tables), ' tables', $left ? ' – ids left: ' . implode(', ', array_slice($left, 0, 5)) : '', "\n";
