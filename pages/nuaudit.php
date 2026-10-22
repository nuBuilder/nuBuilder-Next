<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Audit trail (zzzzsys_nunext_audit, 0.8.350 – core/nuaudit.php): every change made in the forms, one row per column. Globeadmin,
 * admin role right "audit" or access levels with rights on "nunextaudit". Settings (on / keep / exclude): globeadmin or "audit" = change.
 * Columns, filters and sort come from the chosen list view (core/nulistdefs.php → audit). A row opens the history of its record.
 */
NuAcl::require(NuAcl::FORM_AUDIT);
NuSchema::ensure();
$canSet = NuAdmin::can('audit', 'change');

if (nu_is_post() && nu_post('action') === 'audit_settings') {
	if (!$canSet) NuAcl::require('-');
	NuSecurity::requireRecentAuth(nu_url('audit', ['settings' => '1']));
	$changed = NuAudit::saveSettings(['on' => nu_check('on') === '1', 'keep_days' => (int)nu_post('keep_days'), 'exclude' => nu_post('exclude')]);
	nu_flash('success', $changed ? t('aud_set_saved') : t('aud_set_nochange'));
	nu_redirect(nu_url('audit'));
}

// delete (0.8.351): the rows of the current search / filters, older than …, or all – the form posts to this page with the list's
// GET parameters, so NuListView::scope() sees the same filters as the list
$canDelete = $canSet && !NuDemo::active();
if (nu_is_post() && in_array(nu_post('action'), ['audit_count', 'audit_csv', 'audit_delete'], true)) {
	if (!$canDelete) NuAcl::require('-');
	$sel = NuAudit::selection($_POST);
	$back = nu_url('audit', array_diff_key($_GET, ['p' => 1]));
	switch (nu_post('action')) {
		case 'audit_count':
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			$c = NuAudit::countSelection($sel);
			exit(json_encode(['ok' => true, 'rows' => $c['rows'], 'all' => $sel['all'],
				'text' => $c['rows'] ? t('aud_del_count', number_format($c['rows'], 0, '', ' '), number_format($c['changes'], 0, '', ' ')) : t('aud_del_none')], JSON_UNESCAPED_UNICODE));
		case 'audit_csv':
			NuAudit::csv($sel);
		case 'audit_delete':
			NuSecurity::requireRecentAuth($back);
			if ($sel['all'] && nu_post('confirm_all') !== 'DELETE') { nu_flash('danger', t('aud_del_err_confirm')); nu_redirect($back); }
			$n = NuAudit::deleteSelection($sel, (string)(NuAuth::user()['login'] ?? ''));
			nu_flash('success', t('aud_del_done', number_format($n, 0, '', ' ')));
			nu_redirect($sel['filtered'] ? nu_url('audit') : $back);
	}
}

$s = NuAudit::settings();
$open = isset($_GET['settings']) || !$s['on'];
$before = NuAudit::tabs('audit');
if (!$s['on']) $before .= '<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i>' . h(t('aud_off')) . '</div>';

$settings = '';
if ($canSet) {
	$keep = [0, 30, 90, 180, 365, 730, 1825, 3650];
	if (!in_array($s['keep_days'], $keep, true)) $keep[] = $s['keep_days'];
	sort($keep);
	$opt = '';
	foreach ($keep as $d) $opt .= '<option value="' . $d . '"' . ($d === $s['keep_days'] ? ' selected' : '') . '>' . h($d === 0 ? t('aud_keep_ever') : t('aud_keep_days', $d)) . '</option>';
	$settings = '<div class="collapse' . ($open ? ' show' : '') . '" id="audSettings"><form method="post" class="border rounded p-3 mb-3 bg-body-tertiary" id="audSettingsForm">' . nu_csrf_field()
		. '<input type="hidden" name="action" value="audit_settings"><div class="row g-3 align-items-end">'
		. '<div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="on" value="1" id="aud_on"' . ($s['on'] ? ' checked' : '') . '>'
		. '<label class="form-check-label" for="aud_on">' . h(t('aud_set_on')) . '</label>' . NuListView::infoTip(t('aud_set_on_tip')) . '</div></div>'
		. '<div class="col-md-3"><label class="form-label small mb-1" for="aud_keep">' . h(t('aud_set_keep')) . NuListView::infoTip(t('aud_set_keep_tip')) . '</label>'
		. '<select class="form-select form-select-sm" name="keep_days" id="aud_keep">' . $opt . '</select></div>'
		. '<div class="col-md-4"><label class="form-label small mb-1" for="aud_exclude">' . h(t('aud_set_exclude')) . NuListView::infoTip(t('aud_set_exclude_tip')) . '</label>'
		. '<input type="text" class="form-control form-control-sm" name="exclude" id="aud_exclude" maxlength="2000" value="' . h($s['exclude']) . '" placeholder="customer, invoice.notes" spellcheck="false"></div>'
		. '<div class="col-md-2 text-md-end"><button type="submit" class="btn btn-sm nuBtnAct nuBtnSave" id="audSettingsSave"><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button></div>'
		. '</div></form></div>';
}

$delModal = '';
if ($canDelete) {
	[, , $active] = NuListView::scope('audit');
	$opt = '<option value="">' . h(t('aud_del_any')) . '</option>';
	foreach (NuAudit::OLDER as $m) $opt .= '<option value="' . $m . '"' . ($m === 2 ? ' selected' : '') . '>' . h($m === 1 ? t('aud_del_older1') : t('aud_del_older', $m)) . '</option>';
	$opt .= '<option value="date">' . h(t('aud_del_date')) . '</option>';
	$delModal = '<div class="modal fade" id="audDelModal" tabindex="-1" aria-labelledby="audDelTitle" aria-hidden="true"><div class="modal-dialog modal-lg">'
		. '<form class="modal-content" method="post" id="audDelForm" action="' . h(nu_url('audit', array_diff_key($_GET, ['p' => 1]))) . '">' . nu_csrf_field()
		. '<div class="modal-header py-2"><h2 class="modal-title fs-5 d-inline-flex align-items-center gap-1" id="audDelTitle"><i class="bi bi-trash me-1"></i>' . h(t('aud_del_title')) . NuListView::infoTip(t('aud_del_tip')) . '</h2>'
		. '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . h(t('close')) . '"></button></div><div class="modal-body">'
		. '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="filtered" value="1" id="audDelFiltered"' . ($active ? ' checked' : ' disabled') . '>'
		. '<label class="form-check-label" for="audDelFiltered">' . h(t('aud_del_filtered')) . ($active ? '' : ' <span class="text-body-secondary small">' . h(t('aud_del_nofilter')) . '</span>') . '</label></div>'
		. '<div class="row g-2 align-items-end mb-3"><div class="col-sm-7"><label class="form-label small mb-1" for="audDelOlder">' . h(t('aud_del_age')) . '</label>'
		. '<select class="form-select" name="older" id="audDelOlder">' . $opt . '</select></div>'
		. '<div class="col-sm-5 d-none" id="audDelDateBox"><input type="date" class="form-control" name="before" id="audDelBefore" max="' . date('Y-m-d') . '" aria-label="' . h(t('aud_del_date')) . '"></div></div>'
		. '<div class="alert alert-secondary py-2 mb-2" id="audDelCount" role="status" aria-live="polite">…</div>'
		. '<div class="d-none" id="audDelAllBox"><div class="alert alert-danger py-2 mb-2 small">' . h(t('aud_del_all')) . '</div>'
		. '<input type="text" class="form-control" name="confirm_all" id="audDelConfirm" autocomplete="off" spellcheck="false" placeholder="DELETE" aria-label="' . h(t('aud_del_all')) . '"></div>'
		. '</div><div class="modal-footer py-2"><button type="submit" name="action" value="audit_csv" class="btn btn-outline-secondary me-auto" id="audDelCsv"><i class="bi bi-download me-1"></i>' . h(t('aud_del_csv')) . '</button>'
		. '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' . h(t('cancel')) . '</button>'
		. '<button type="submit" name="action" value="audit_delete" class="btn btn-danger" id="audDelGo" disabled><i class="bi bi-trash me-1"></i>' . h(t('delete')) . '</button></div>'
		. '</form></div></div>';
}

NuListView::page('audit', [
	'before' => $before . $delModal, 'foot' => $canDelete ? '<script src="' . h(nu_asset('assets/js/nuaudit.js')) . '"></script>' : '', 'active' => 'audit', 'title_extra' => NuListView::infoTip(t('aud_hint')), 'above_rows' => $settings,
	'buttons' => $canSet ? '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#audSettings" aria-expanded="' . ($open ? 'true' : 'false')
		. '" aria-controls="audSettings" id="audSettingsBtn"><i class="bi bi-gear me-1"></i>' . h(t('aud_settings')) . '</button>'
		. ($canDelete ? '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#audDelModal" id="audDelBtn"><i class="bi bi-trash me-1"></i>' . h(t('aud_del_btn')) . '</button>' : '') : '',
]);
