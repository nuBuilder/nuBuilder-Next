<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Access levels of one form – the dialog of the form's ⋮ menu and of design mode (assets/js/nu/nuformaccess.js).
 *   GET  ?p=formaccess&f=<form id>  → {form, levels: [{id, code, description, group, own, row: null | {rights, dm, ft}}], rights, can_save, texts}
 *   POST {f, levels: {<access id>: null | {rights: {add: 1, …}, dm, ft}}} (JSON, X-CSRF-Token) – only the levels sent are changed:
 *        null = the level loses the form (its row in zzzzsys_access_form is deleted), else the row is written (inserted when missing).
 * The same rows and rules as the access level page (pages/nuaccesslevel.php): view needs the access level form, changes its save right;
 * with "Only the globeadmin grants admin rights" (NuSecurity::restricted) nobody changes his own levels or grants new admin-equivalent rights.
 * Everyone (NuFormEveryone): GET adds {everyone: null | {rights, dm, ft, new}, everyone_ok}; POST {everyone: {…}} gives every level
 * the form with these buttons (the normal rows) and stores the switch, {everyone: null} switches it off (the rows stay).
 */
$db = nu_db();
$out = static function (array $d, int $code = 200): never { nu_json($d, $code); };
if (!NuAcl::can(NuAcl::FORM_ACCESS)) $out(['ok' => false, 'message' => t('err_forbidden')], 403);

$post = nu_is_post() ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
$fid = trim((string)($post['f'] ?? $_GET['f'] ?? ''));
$form = ($nf = nu_form($fid)) ? ['zzzzsys_form_id' => $fid, 'sfo_code' => $nf['code'], 'sfo_description' => $nf['description']] : null;
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
['canSave' => $canSave, 'out' => $out, 'fid' => $fid, 'rowsOf' => $rowsOf, 'col' => $col, 'hasDm' => $hasDm, 'hasFt' => $hasFt, 'restricted' => $restricted, 'adminForm' => $adminForm, 'form' => $form]
	= NuFormaccessPage::post(get_defined_vars());   // the POST actions (code review R7, nuformaccesspage.php)

// ------------------------------------------------------------------ read
session_write_close();
$by = $rowsOf();
$levels = [];
foreach (NuAcl::levels() as $l) {
	$aid = (string)$l['zzzzsys_access_id'];
	$r = $by[$aid] ?? null;
	$row = null;
	if ($r) {
		$row = ['rights' => [], 'dm' => trim((string)($r['slf_data_mode'] ?? '')) === '0' ? '0' : '',
			'ft' => in_array(trim((string)($r['slf_form_type'] ?? '')), ['0', '1'], true) ? trim((string)$r['slf_form_type']) : ''];
		foreach (NuAcl::RIGHTS as $right => $col) $row['rights'][$right] = (string)$r[$col] === '1';
	}
	$levels[] = ['id' => $aid, 'code' => (string)$l['sal_code'], 'description' => (string)$l['sal_description'], 'group' => (string)$l['sal_group'],
		'own' => $restricted && NuUserAccess::holds($aid), 'row' => $row];
}
$texts = [];
foreach (['fac_title', 'fac_intro', 'fac_level', 'fac_open', 'fac_all', 'fac_group', 'fac_groups', 'fac_nogroup', 'fac_search', 'fac_count', 'fac_own',
	'fac_admin', 'fac_page', 'fac_none', 'access_col_dm', 'access_col_ft', 'access_dm_normal', 'access_dm_noedit', 'access_ft_both', 'access_ft_browse',
	'access_ft_edit', 'save', 'cancel', 'toggle_all', 'fac_ev', 'fac_ev_hint', 'fac_ev_buttons', 'fac_ev_new', 'fac_ev_new_hint', 'fac_ev_follow', 'fac_ev_admin',
	'fac_ev_count'] as $k) $texts[$k] = t($k);
foreach (array_keys(NuAcl::RIGHTS) as $r) $texts['right_' . $r] = t('right_' . $r);
$out(['ok' => true, 'form' => ['id' => $fid, 'code' => (string)$form['sfo_code'], 'label' => nu_form_label($fid)],
	'rights' => array_keys(NuAcl::RIGHTS), 'dm' => $hasDm, 'ft' => $hasFt, 'can_save' => $canSave,
	'admin_form' => $restricted && $adminForm, 'everyone' => NuFormEveryone::of($fid), 'everyone_ok' => NuFormEveryone::allowed($fid),
		'page' => nu_url('accesslevel', ['id' => '__ID__', 'tab' => 'rights']), 'levels' => $levels, 'texts' => $texts]);
