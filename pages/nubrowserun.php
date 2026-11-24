<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Run the browse SQL of a form from its code editor (design mode Code, the list's side pane): POST ?p=browserun
 * {f, sql, page, per, props (JSON), cols} → {ok, cols, rows, total, ms, page, per, sql_run} or {ok: false, error, line}.
 * The SQL as written in the editor (saved or not), with the hash variables filled as on the list: the form's hash, the
 * properties the page sends (nuSetProperty) and the form's Before Browse (it may build #TABLE_ID#). One SELECT / WITH only,
 * read only (NuDB::readOnly), 15 s, at most MAX_ROWS rows paged. Those who may design the form only (NuDesign::allowed).
 */
const NU_BRUN_MAX_ROWS = 1000;
session_write_close();
$f    = nu_post('f');
$meta = $f !== '' ? NuFormMeta::load($f) : null;
if (!$meta || !NuDesign::allowed($meta) || NuDemo::active()) nu_json(['ok' => false, 'error' => t('access_denied')], 403);
$sql = NuBrowse::trimTrailingComments(trim(nu_post('sql')));
if ($sql === '') $sql = 'SELECT * FROM ' . NuDB::ident($meta->table());
if (strlen($sql) > 200_000) nu_json(['ok' => false, 'error' => 'too_long'], 400);
$per  = max(1, min(100, (int)nu_post('per') ?: 10));
$page = max(1, (int)nu_post('page') ?: 1);
if (($page - 1) * $per >= NU_BRUN_MAX_ROWS) $page = intdiv(NU_BRUN_MAX_ROWS - 1, $per) + 1;
$props = [];
foreach ((array)(json_decode(nu_post('props'), true) ?: []) as $k => $v) {
	if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,99}$/', $k) && (is_scalar($v) || $v === null)) $props[$k] = (string)$v;
}
$db = nu_db();
// the hash as on the list: form, properties, Before Browse
NuHash::$state += nu_browse_hash_state();
$hash = NuHash::forForm($meta, '', [], $props);
NuPhp::reset();
if (NuProcedure::hook('nu_before_save', $hash) && NuPhp::run($meta->php('BB'), $hash, 'Before Browse')) $hash = NuPhp::$hash + $hash;
$sql    = NuBrowse::expandFilterMatch($sql);
$shown  = NuHash::replace($sql, $hash, NuHash::SQL);                  // the SQL as run, for "Show SQL as run"
if (!NuDemo::readOnlySql($shown)) nu_json(['ok' => false, 'error' => t('brun_err_select'), 'sql_run' => $shown]);
$run    = NuHash::sql($sql, $hash);
[$inner] = NuBrowse::splitOrder($run);
$t0 = microtime(true);
try {
	$out = $db->readOnly(static function () use ($db, $run, $inner, $per, $page): array {
		$off = ($page - 1) * $per;
		$lim = min($per, max(0, NU_BRUN_MAX_ROWS - $off));
		if ($db->type === 'sqlsrv') {
			$q = preg_match('/\bORDER\s+BY\b[^)]*$/i', $run) ? $run : $run . ' ORDER BY (SELECT NULL)';
			$rows = $db->all($q . " OFFSET $off ROWS FETCH NEXT $lim ROWS ONLY");
		} elseif (preg_match('/\bLIMIT\s+\d+(\s*(,|OFFSET)\s*\d+)?\s*$/i', $run)) {
			$rows = $db->all('SELECT * FROM (' . $run . ') nuBrun LIMIT ' . $lim . ' OFFSET ' . $off);   // the SQL has its own LIMIT
		} else {
			$rows = $db->all($run . ' LIMIT ' . $lim . ' OFFSET ' . $off);   // appended: keeps its ORDER BY (a derived table may drop it)
		}
		// the number of rows; a SELECT * of a join with equal column names cannot be counted as a derived table – unknown then
		try { $total = (int)$db->value('SELECT COUNT(*) FROM (' . $inner . ') nuBrunC'); } catch (Throwable $ig) { $total = null; nu_ignored($ig, 'browse run: count'); }
		return [$total, $rows];
	}, 15);
} catch (PDOException $e) {
	$msg = NuSqlCheck::message($e);
	nu_json(['ok' => false, 'error' => $msg, 'line' => NuSqlCheck::lineOf($sql, $msg), 'sql_run' => $shown]);
} catch (Throwable $e) {
	nu_json(['ok' => false, 'error' => $e->getMessage(), 'sql_run' => $shown]);
}
[$total, $rows] = $out;
$cols = $rows ? array_keys($rows[0]) : [];
if (!$cols) {                                                          // no rows: the column names from an empty run
	try { $cols = array_keys((array)($db->readOnly(static fn() => $db->one('SELECT * FROM (' . $run . ') nuBrunE WHERE 1 = 0'), 15) ?: [])); } catch (Throwable $ig) { nu_ignored($ig, 'browse run: column names of an empty result'); }
}
$list = [];
foreach ($meta->browse as $b) {                                       // the list's columns (switch "Only the list's columns")
	$d = trim((string)($b['sbr_display'] ?? ''));
	$d = preg_replace('/^.*\./', '', trim($d, '`[]"'));
	if ($d !== '') $list[] = strtolower((string)$d);
}
$out = [];
foreach ($rows as $r) {
	$line = [];
	foreach ($cols as $c) $line[] = $r[$c] === null ? null : (is_scalar($r[$c]) ? (string)$r[$c] : (string)json_encode($r[$c]));
	$out[] = $line;
}
nu_json(['ok' => true, 'cols' => $cols, 'list' => array_values(array_filter($cols, static fn($c) => in_array(strtolower((string)$c), $list, true))),
	'rows' => $out, 'total' => $total, 'capped' => $total !== null && $total > NU_BRUN_MAX_ROWS, 'max' => NU_BRUN_MAX_ROWS, 'page' => $page, 'per' => $per,
	'ms' => (int)round((microtime(true) - $t0) * 1000), 'sql_run' => $shown]);
