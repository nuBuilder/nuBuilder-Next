<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Names for the suggestions of the code editors (assets/js/nu/nucodetools.js nameHints()):
 * GET ?p=codenames[&f=<form id>] → {hash: [[name, description]], tables, cols, fields, table}
 *   hash    the "#" variables of NuHash::catalog() (every signed-in user)
 *   tables  [[name, 'v' for a view else '']] and cols {table: [[column, type]]} – only for users who may write SQL (NuSqlCheck::allowed:
 *           globeadmin / save right on forms or SQL queries, not demo); capped at MAX_TABLES / MAX_COLS
 *   fields  [[object id, label]] of the form f (its record fields are hash variables too) and table = the form's table – same right
 * Cached by the browser for 5 minutes.
 */
session_write_close();
const NU_CN_MAX_TABLES = 3000;
const NU_CN_MAX_COLS = 60000;

$out = ['hash' => [], 'tables' => [], 'cols' => (object)[], 'fields' => [], 'table' => ''];
foreach (NuHash::catalog()['groups'] as $g) foreach ($g['items'] as $it) $out['hash'][] = [(string)$it[0], (string)$it[1]];

if (NuSqlCheck::allowed()) {
	$db = nu_db();
	try {
		$tables = match ($db->type) {
			'mysql'  => $db->all("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name"),
			'sqlsrv' => $db->all("SELECT TABLE_NAME AS t, TABLE_TYPE AS k FROM INFORMATION_SCHEMA.TABLES ORDER BY TABLE_NAME"),
			default  => $db->all("SELECT name AS t, type AS k FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY name"),
		};
		foreach (array_slice($tables, 0, NU_CN_MAX_TABLES) as $r) $out['tables'][] = [(string)$r['t'], stripos((string)$r['k'], 'view') !== false ? 'v' : ''];
		$cols = [];
		if ($db->type === 'sqlite') {
			foreach ($out['tables'] as [$t]) foreach ($db->columns($t) as $name => $c) $cols[$t][] = [(string)$name, (string)($c['type'] ?? '')];
		} else {
			$rows = $db->type === 'mysql'
				? $db->all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS y FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION LIMIT ' . NU_CN_MAX_COLS)
				: $db->all('SELECT TOP ' . NU_CN_MAX_COLS . ' TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS y FROM INFORMATION_SCHEMA.COLUMNS ORDER BY TABLE_NAME, ORDINAL_POSITION');
			foreach ($rows as $r) $cols[(string)$r['t']][] = [(string)$r['c'], (string)$r['y']];
		}
		$out['cols'] = $cols ?: (object)[];
	} catch (Throwable $e) {
		error_log('nuBuilder Next codenames: ' . $e->getMessage());
	}
	$f = (string)($_GET['f'] ?? '');
	if ($f !== '' && preg_match('/^[\w.-]{1,64}$/', $f)) {
		try {
			$out['table'] = (string)(nu_form($f)['table'] ?? '');
			foreach ($db->all("SELECT sob_all_id AS i, sob_all_label AS l FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?
				AND sob_all_type NOT IN ('word', 'html', 'image', 'run', 'contentbox') ORDER BY sob_all_order", [$f]) as $r) {
				if ((string)$r['i'] !== '') $out['fields'][] = [(string)$r['i'], trim(strip_tags((string)$r['l']))];
			}
		} catch (Throwable $ig) { nu_ignored($ig, 'no form'); }
	}
}
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
exit((string)json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
