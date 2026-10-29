<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Access levels of one form (0.8.683) – the dialog of the form's ⋮ menu and of design mode (assets/js/nu/nuformaccess.js).
 *   GET  ?p=formaccess&f=<form id>  → {form, levels: [{id, code, description, group, own, row: null | {rights, dm, ft}}], rights, can_save, texts}
 *   POST {f, levels: {<access id>: null | {rights: {add: 1, …}, dm, ft}}} (JSON, X-CSRF-Token) – only the levels sent are changed:
 *        null = the level loses the form (its row in zzzzsys_access_form is deleted), else the row is written (inserted when missing).
 * The same rows and rules as the access level page (pages/nuaccesslevel.php): view needs the access level form, changes its save right;
 * with "Only the globeadmin grants admin rights" (NuSecurity::restricted) nobody changes his own levels or grants new admin-equivalent rights.
 */
$db = nu_db();
$out = static function (array $d, int $code = 200): never {
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	exit((string)json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
};
if (!NuAcl::can(NuAcl::FORM_ACCESS)) $out(['ok' => false, 'message' => t('err_forbidden')], 403);

$post = nu_is_post() ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
$fid = trim((string)($post['f'] ?? $_GET['f'] ?? ''));
$form = $fid === '' ? null : $db->one('SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$fid]);
if (!$form) $out(['ok' => false, 'message' => t('record_not_found')], 404);

$cols = $db->columns('zzzzsys_access_form');
$hasDm = isset($cols['slf_data_mode']);
$hasFt = isset($cols['slf_form_type']);
$canSave = NuAcl::can(NuAcl::FORM_ACCESS, 'save');
$restricted = NuSecurity::restricted();
$adminForm = in_array($fid, NuSecurity::ADMIN_FORMS, true);
$rowsOf = static function () use ($db, $fid): array {
	$by = [];
	foreach ($db->all('SELECT * FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = ? ORDER BY zzzzsys_access_form_id', [$fid]) as $r) {
		$by[(string)$r['slf_zzzzsys_access_id']] ??= $r;               // a level with two rows for the form: the first counts (as NuAcl)
	}
	return $by;
};

// ------------------------------------------------------------------ save
if (nu_is_post()) {
	if (!$canSave) $out(['ok' => false, 'message' => t('err_forbidden')], 403);
	$want = is_array($post['levels'] ?? null) ? $post['levels'] : [];
	$levels = [];
	foreach ($db->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access') as $l) $levels[(string)$l['zzzzsys_access_id']] = (string)$l['sal_code'];
	$before = $rowsOf();
	$plan = [];
	foreach ($want as $aid => $w) {
		$aid = (string)$aid;
		if (!isset($levels[$aid])) continue;
		$old = $before[$aid] ?? null;
		if ($w === null || !is_array($w)) { if ($old) $plan[$aid] = null; continue; }
		$row = [];
		foreach (NuAcl::RIGHTS as $right => $col) $row[$col] = !empty($w['rights'][$right]) ? '1' : '0';
		if ($hasDm) $row['slf_data_mode'] = trim((string)($w['dm'] ?? '')) === '0' ? '0' : null;
		if ($hasFt) $row['slf_form_type'] = in_array(trim((string)($w['ft'] ?? '')), ['0', '1'], true) ? trim((string)$w['ft']) : null;
		$same = $old !== null;
		if ($old) foreach ($row as $c => $v) if ((string)($old[$c] ?? '') !== (string)($v ?? '')) $same = false;
		if (!$same) $plan[$aid] = $row;
	}
	if ($restricted) {
		foreach ($plan as $aid => $row) {
			if (NuUserAccess::holds($aid)) $out(['ok' => false, 'message' => t('secchk_err_own_rights')], 403);
			if (!$adminForm || $row === null) continue;
			foreach (NuSecurity::ADMIN_RIGHTS as $col) {
				if (($row[$col] ?? '0') === '1' && (string)($before[$aid][$col] ?? '') !== '1') $out(['ok' => false, 'message' => t('secchk_err_admin_rights', nu_form_label($fid))], 403);
			}
		}
	}
	$db->transaction(static function (NuDB $db) use ($plan, $before, $fid) {
		foreach ($plan as $aid => $row) {
			$old = $before[$aid] ?? null;
			if ($row === null) $db->run('DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ? AND slf_zzzzsys_form_id = ?', [(string)$aid, $fid]);
			elseif ($old) $db->update('zzzzsys_access_form', $row, 'zzzzsys_access_form_id', (string)$old['zzzzsys_access_form_id']);
			else $db->insert('zzzzsys_access_form', ['zzzzsys_access_form_id' => nu_id(), 'slf_zzzzsys_access_id' => (string)$aid, 'slf_zzzzsys_form_id' => $fid] + $row);
		}
	});
	foreach ($plan as $aid => $row) {
		$on = $row === null ? [] : array_keys(array_filter(NuAcl::RIGHTS, static fn($c) => ($row[$c] ?? '0') === '1'));
		NuLog::write('access_form_rights', 'zzzzsys_access', (string)$aid, ['code' => $levels[$aid], 'form' => (string)$form['sfo_code']]
			+ ($row === null ? ['removed' => true] : ['rights' => $on ? implode(', ', $on) : t('view')]));
	}
	$out(['ok' => true, 'changed' => count($plan), 'message' => $plan ? t('fac_saved', count($plan)) : t('fac_nothing')]);
}

// ------------------------------------------------------------------ read
session_write_close();
$by = $rowsOf();
$levels = [];
foreach ($db->all('SELECT zzzzsys_access_id, sal_code, sal_description, sal_group FROM zzzzsys_access ORDER BY sal_code') as $l) {
	$aid = (string)$l['zzzzsys_access_id'];
	$r = $by[$aid] ?? null;
	$row = null;
	if ($r) {
		$row = ['rights' => [], 'dm' => trim((string)($r['slf_data_mode'] ?? '')) === '0' ? '0' : '', 'ft' => in_array(trim((string)($r['slf_form_type'] ?? '')), ['0', '1'], true) ? trim((string)$r['slf_form_type']) : ''];
		foreach (NuAcl::RIGHTS as $right => $col) $row['rights'][$right] = (string)$r[$col] === '1';
	}
	$levels[] = ['id' => $aid, 'code' => (string)$l['sal_code'], 'description' => (string)$l['sal_description'], 'group' => (string)$l['sal_group'],
		'own' => $restricted && NuUserAccess::holds($aid), 'row' => $row];
}
$texts = [];
foreach (['fac_title', 'fac_intro', 'fac_level', 'fac_open', 'fac_all', 'fac_group', 'fac_groups', 'fac_nogroup', 'fac_search', 'fac_count', 'fac_own',
	'fac_admin', 'fac_page', 'fac_none', 'access_col_dm', 'access_col_ft', 'access_dm_normal', 'access_dm_noedit', 'access_ft_both', 'access_ft_browse',
	'access_ft_edit', 'save', 'cancel', 'toggle_all'] as $k) $texts[$k] = t($k);
foreach (array_keys(NuAcl::RIGHTS) as $r) $texts['right_' . $r] = t('right_' . $r);
$out(['ok' => true, 'form' => ['id' => $fid, 'code' => (string)$form['sfo_code'], 'label' => nu_form_label($fid)],
	'rights' => array_keys(NuAcl::RIGHTS), 'dm' => $hasDm, 'ft' => $hasFt, 'can_save' => $canSave,
	'admin_form' => $restricted && $adminForm, 'page' => nu_url('accesslevel', ['id' => '__ID__', 'tab' => 'rights']), 'levels' => $levels, 'texts' => $texts]);
