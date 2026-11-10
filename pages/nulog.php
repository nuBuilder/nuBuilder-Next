<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Login and change log (zzzzsys_nunext_log). Globeadmin, or access levels with rights on "nunextlog". Columns, filters and sort come
 * from the chosen list view (0.8.334, core/nulistdefs.php → log). URL parameters as before: event, login, q, from, to.
 */
NuAcl::require(NuAcl::FORM_LOG);
NuSchema::ensure();
$ga = !empty(NuAuth::user()['globeadmin']);

// Delete older entries (0.8.1087, globeadmin, password again): 30 / 90 / 365 days or all – the last 15 minutes always stay (the login
// rate limit counts failed sign-ins in the log); one entry records who cleared how many
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nu_post('action') === 'purge') {
	if (!$ga) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
	NuSecurity::requireRecentAuth(nu_url('log'));
	$days = (int)nu_post('days');
	if (!in_array($days, [0, 30, 90, 365], true)) { nu_flash('danger', t('form_error')); nu_redirect(nu_url('log')); }
	$keep = time() - 15 * 60;
	$cut = date('Y-m-d H:i:s', $days === 0 ? $keep : min($keep, time() - $days * 86400));
	$n = nu_db()->run('DELETE FROM zzzzsys_nunext_log WHERE log_time < ?', [$cut])->rowCount();
	NuLog::write('log_cleared', 'zzzzsys_nunext_log', '', ['older_than' => $days === 0 ? 'all' : $days . ' d', 'deleted' => $n]);
	nu_flash('success', t('log_purge_done', $n));
	nu_redirect(nu_url('log'));
}

$tabs = NuAudit::tabs('log');                // debug results · log · audit trail · sessions
$dlg = '';
if ($ga) {
	$opts = '';
	foreach ([30 => t('log_purge_30'), 90 => t('log_purge_90'), 365 => t('log_purge_365'), 0 => t('log_purge_all')] as $d => $lbl)
		$opts .= '<div class="form-check"><input class="form-check-input" type="radio" name="days" value="' . $d . '" id="logPurge' . $d . '"' . ($d === 90 ? ' checked' : '') . '><label class="form-check-label" for="logPurge' . $d . '">' . h($lbl) . '</label></div>';
	$dlg = '<div class="modal fade" id="logPurgeDlg" tabindex="-1" aria-labelledby="logPurgeTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post" action="' . h(nu_url('log')) . '" data-nu-once>'
		. nu_csrf_field() . '<input type="hidden" name="action" value="purge">'
		. '<div class="modal-header"><h2 class="modal-title h5" id="logPurgeTitle">' . h(t('log_purge')) . '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . h(t('cancel')) . '"></button></div>'
		. '<div class="modal-body"><p class="small text-body-secondary">' . h(t('log_purge_hint')) . '</p>' . $opts . '</div>'
		. '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' . h(t('cancel')) . '</button>'
		. '<button type="submit" class="btn btn-danger" id="logPurgeGo" data-nu-busy="' . h(t('log_purge')) . '"><i class="bi bi-trash me-1"></i>' . h(t('delete')) . '</button></div></form></div></div>';
}
NuListView::page('log', ['before' => $tabs . $dlg, 'active' => 'log',
	'more' => $ga ? [['icon' => 'bi-trash', 'label' => t('log_purge') . '…', 'href' => '#logPurgeDlg', 'modal' => 'logPurgeDlg', 'id' => 'logPurgeOpen']] : []]);
