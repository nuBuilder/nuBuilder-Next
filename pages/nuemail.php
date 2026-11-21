<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * E-mail: SMTP settings (zzzzsys_setup, rights of nuBuilder's form "nusetup"), test mail and
 * the e-mail log (zzzzsys_email_log, rights of "nuemaillog").
 */

$canSetup = NuAcl::can(NuAcl::FORM_SETUP);
$canLog   = NuAcl::can(NuAcl::FORM_EMAILLOG);
if (!$canSetup && !$canLog) {
	if (NuAcl::can(NuAcl::FORM_EMAILTEMPLATE)) nu_redirect(nu_url('emailtemplates'));
	NuAcl::require(NuAcl::FORM_SETUP);
}

$db  = nu_db();
$tab = (string)($_GET['tab'] ?? ($canSetup ? 'settings' : 'log'));
if (!in_array($tab, ['settings', 'test', 'log'], true)) $tab = 'settings';
if ($tab !== 'log' && !$canSetup) $tab = 'log';
if ($tab === 'log' && !$canLog) $tab = 'settings';

$logExists = $db->tableExists(NuMailer::LOG_TABLE);

// ------------------------------------------------------------------ raw body of a logged mail (shown in a sandboxed iframe)
if (isset($_GET['body'])) {
	NuAcl::require(NuAcl::FORM_EMAILLOG);
	$row = $logExists ? $db->one('SELECT eml_body, eml_html FROM ' . NuMailer::LOG_TABLE . ' WHERE zzzzsys_email_log_id = ?', [(string)$_GET['body']]) : null;
	header_remove('X-Frame-Options');
	header('X-Frame-Options: SAMEORIGIN');
	header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: https: cid:; font-src data: https:; sandbox");
	header('Content-Type: text/html; charset=UTF-8');
	if (!$row) exit;
	$body = (string)$row['eml_body'];
	echo (string)$row['eml_html'] === '1' || (int)$row['eml_html'] === 1 ? $body
		: '<pre style="white-space:pre-wrap;font:14px/1.45 Segoe UI,Arial,sans-serif;margin:12px">' . h($body) . '</pre>';
	exit;
}

$errors = [];
$testResult = null;
$checkResult = null;                                                        // connection check without sending

// ------------------------------------------------------------------ POST actions
['errors' => $errors, 'tab' => $tab, 'checkResult' => $checkResult, 'testResult' => $testResult, 'logExists' => $logExists, 'db' => $db]
	= NuEmailPage::post(get_defined_vars());   // the POST actions (code review R7, nuemailpage.php)

$s       = NuMailer::settings();
$row     = NuMailer::setupRow(true);
$setupOk = $db->tableExists('zzzzsys_setup');
$canSave = NuAcl::can(NuAcl::FORM_SETUP, 'save');
$pmDir   = NuMailer::loadPHPMailer();
$val = static fn(string $k, string $post) => nu_is_post() && nu_post('action') === 'save' ? nu_post($post) : NuMailer::setupValue($k);
$smtpOnly = NuSecret::smtp()['only'];
$smtpHasPw = NuSecret::smtpPassword(NuMailer::setupValue('set_smtp_password')) !== '';
$placeholders = array_filter(['set_smtp_host', 'set_smtp_username', 'set_smtp_password', 'set_smtp_from_address', 'set_smtp_from_name'],
	static fn($k) => trim((string)($row[$k] ?? '')) === '1');



$encLabel = match ($s['secure']) { 'ssl' => 'SSL/TLS (SMTPS)', 'tls' => 'STARTTLS', 'none' => t('mail_enc_none'), default => t('mail_enc_auto') };

// ------------------------------------------------------------------ log detail
$detail = null;
if ($tab === 'log' && isset($_GET['id']) && $logExists) {
	$detail = $db->one('SELECT * FROM ' . NuMailer::LOG_TABLE . ' WHERE zzzzsys_email_log_id = ?', [(string)$_GET['id']]);
}

// ------------------------------------------------------------------ log list (also Print / CSV)
$lvState = null;
$purgeForm = '';
if ($tab === 'log' && !$detail && $logExists) {
	// columns, filters and sort of the chosen list view (core/nulistdefs.php → emaillog); Print / CSV end here
	$lvState = NuListView::prepare('emaillog');
	if (NuAcl::can(NuAcl::FORM_EMAILLOG, 'delete')) {
		$purgeForm = '<form method="post" class="d-flex align-items-center gap-2 small" data-confirm="' . h(t('mail_purge_confirm')) . '" data-confirm-ok="'
			. h(t('delete')) . '" data-confirm-class="btn btn-danger">'
			. nu_csrf_field() . '<input type="hidden" name="action" value="purge"><label for="p_days" class="text-nowrap">' . h(t('mail_purge_older')) . '</label>'
			. '<input class="form-control form-control-sm nuPurgeDays" type="number" min="1" id="p_days" name="days" value="90"><span class="text-nowrap">' . h(t('mail_days')) . '</span>'
			. '<button class="btn btn-sm btn-outline-danger" type="submit" title="' . h(t('delete')) . '" aria-label="' . h(t('delete')) . '"><i class="bi bi-trash"></i></button></form>';
	}
}

nu_layout_start(t('nav_email'), 'email', ['head' => NuListView::head(), 'settings' => 'mail']);
?>
<?php // Save of the settings form in the header, as on the other settings pages (usability review) ?>
<?= nu_page_header(t('nav_email'), ['icon' => 'bi-envelope', 'buttons' => $tab === 'settings' && $setupOk && $canSave
	? '<button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuMailForm" id="nuMailSave"><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button>' : '']) ?>

<ul class="nav nav-tabs mb-3">
	<?php if ($canSetup): ?>
		<li class="nav-item"><a class="nav-link<?= $tab === 'settings' ? ' active' : '' ?>"
			href="<?= h(nu_url('email', ['tab' => 'settings'])) ?>"><i class="bi bi-gear me-1"></i><?= h(t('mail_tab_settings')) ?></a></li>
		<li class="nav-item"><a class="nav-link<?= $tab === 'test' ? ' active' : '' ?>"
			href="<?= h(nu_url('email', ['tab' => 'test'])) ?>"><i class="bi bi-send me-1"></i><?= h(t('mail_tab_test')) ?></a></li>
	<?php endif; ?>
	<?php if (NuAcl::can(NuAcl::FORM_EMAILTEMPLATE)): ?>
		<li class="nav-item"><a class="nav-link" href="<?= h(nu_url('emailtemplates')) ?>"><i class="bi bi-file-earmark-richtext me-1"></i><?= h(t('emt_title')) ?></a></li>
	<?php endif; ?>
	<?php if ($canLog): ?>
		<li class="nav-item"><a class="nav-link<?= $tab === 'log' ? ' active' : '' ?>"
			href="<?= h(nu_url('email', ['tab' => 'log'])) ?>"><i class="bi bi-journal-text me-1"></i><?= h(t('mail_tab_log')) ?></a></li>
	<?php endif; ?>
</ul>

<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php endif; ?>
<?php if (NuAcl::can(NuAcl::FORM_SETUP)): // connection check without sending – its buttons on the tabs Settings and Test point here ?>
<form method="post" action="<?= h(nu_url('email', ['tab' => 'test'])) ?>" id="nuMailCheckForm" data-nu-once><?= nu_csrf_field() ?><input type="hidden" name="action" value="check"></form>
<form method="post" action="<?= h(nu_url('email', ['tab' => 'settings'])) ?>" id="nuMailOAuthOff" data-confirm="<?= h(t('mail_oauth_disconnect_confirm')) ?>"
	data-confirm-ok="<?= h(t('mail_oauth_disconnect')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="oauth_disconnect"></form>
<?php endif; ?>

<?php if ($tab === 'settings'): ?>
	<div class="row g-3">
		<div class="col-lg-7">
			<?php if (!$setupOk): ?>
				<div class="alert alert-warning"><?= h(t('mail_no_setup_table')) ?></div>
			<?php else: ?>
			<?php if ($s['source'] === 'nuconfig'): ?>
				<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i><?= h(t('mail_source_nuconfig')) ?></div>
			<?php endif; ?>
			<?php if ($placeholders): ?>
				<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('mail_placeholders')) ?></div>
			<?php endif; ?>
			<form method="post" class="card shadow-sm" id="nuMailForm" autocomplete="off" novalidate data-nu-guard>
				<?= nu_csrf_field() ?>
				<input type="hidden" name="action" value="save">
				<div class="card-body">
					<div class="row g-3">
						<div class="col-sm-8">
							<label class="form-label" for="m_host"><?= h(t('mail_host')) ?></label>
							<input class="form-control<?= isset($errors['host']) ? ' is-invalid' : '' ?>" id="m_host" name="host" maxlength="255"
								value="<?= h($val('set_smtp_host', 'host')) ?>" placeholder="smtp.example.ch">
							<div class="invalid-feedback"><?= h($errors['host'] ?? '') ?></div>
						</div>
						<div class="col-sm-4">
							<label class="form-label" for="m_port"><?= h(t('mail_port')) ?></label>
							<input class="form-control<?= isset($errors['port']) ? ' is-invalid' : '' ?>" id="m_port" name="port" inputmode="numeric"
								value="<?= h($val('set_smtp_port', 'port')) ?>" placeholder="587" list="m_ports">
							<datalist id="m_ports"><option value="587">STARTTLS</option><option value="465">SSL/TLS</option><option value="25">SMTP</option></datalist>
							<div class="invalid-feedback"><?= h($errors['port'] ?? '') ?></div>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="m_user"><?= h(t('mail_username')) ?></label>
							<input class="form-control<?= isset($errors['username']) ? ' is-invalid' : '' ?>" id="m_user" name="username" maxlength="255"
								value="<?= h($val('set_smtp_username', 'username')) ?>" autocomplete="off">
							<div class="invalid-feedback"><?= h($errors['username'] ?? '') ?></div>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="m_pw"><?= h(t('mail_password')) ?></label>
							<input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="m_pw" name="password" maxlength="255" autocomplete="new-password"
								placeholder="<?= h($smtpHasPw ? t('mail_password_unchanged') : '') ?>">
							<div class="invalid-feedback"><?= h($errors['password'] ?? '') ?></div>
							<?php if ($smtpHasPw): ?>
								<div class="form-check mt-1 small">
									<input class="form-check-input" type="checkbox" id="m_pwclear" name="clear_password" value="1">
									<label class="form-check-label" for="m_pwclear"><?= h(t('mail_password_clear')) ?></label>
								</div>
							<?php endif; ?>
							<div class="form-check form-switch mt-1 small" id="m_onlyWrap">
								<input class="form-check-input" type="checkbox" role="switch" id="m_only" name="smtp_only" value="1"<?= $smtpOnly ? ' checked' : '' ?><?= NuSecret::available() ? '' : ' disabled' ?>>
								<label class="form-check-label" for="m_only"><?= h(t('mail_pw_only_next')) ?></label>
								<?= nu_info_tip(t('mail_pw_only_next_tip')) ?>
								<?php if (!NuSecret::available()): ?><div class="text-body-secondary"
									id="m_onlyNoKey"><?= h(t('mail_pw_only_nokey')) ?></div><?php elseif ($smtpOnly): ?><div class="text-body-secondary"
									id="m_onlyOn"><i class="bi bi-lock me-1"></i><?= h(t('mail_pw_only_on')) ?></div><?php endif; ?>
							</div>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="m_from"><?= h(t('mail_from_address')) ?></label>
							<input type="email" class="form-control<?= isset($errors['from_address']) ? ' is-invalid' : '' ?>" id="m_from" name="from_address"
								maxlength="255" value="<?= h($val('set_smtp_from_address', 'from_address')) ?>" placeholder="noreply@example.ch">
							<div class="invalid-feedback"><?= h($errors['from_address'] ?? '') ?></div>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="m_fromname"><?= h(t('mail_from_name')) ?></label>
							<input class="form-control<?= isset($errors['from_name']) ? ' is-invalid' : '' ?>" id="m_fromname" name="from_name" maxlength="255" value="<?= h($val('set_smtp_from_name', 'from_name')) ?>">
							<div class="invalid-feedback"><?= h($errors['from_name'] ?? '') ?></div>
						</div>
						<div class="col-sm-6">
							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" role="switch" id="m_auth" name="use_auth"
									value="1"<?= (nu_is_post() ? nu_check('use_auth') === '1' : NuMailer::setupValue('set_smtp_use_authentication') === '1') ? ' checked' : '' ?>>
								<label class="form-check-label" for="m_auth"><?= h(t('mail_use_auth')) ?></label>
							</div>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="m_authtype"><?= h(t('mail_auth_type')) ?></label>
							<?= nu_info_tip(t('mail_auth_type_tip')) ?>
							<?php $curAuth = nu_is_post() ? strtoupper(nu_post('auth_type')) : NuMailer::authType(); ?>
							<select class="form-select" id="m_authtype" name="auth_type">
								<?php foreach (NuMailer::AUTH_TYPES as $at): ?><option value="<?= h($at) ?>"<?= $curAuth === $at ? ' selected' : '' ?>><?= h($at === '' ? t('mail_auth_auto') : ($at === 'XOAUTH2' ? t('mail_auth_oauth') : $at)) ?></option><?php endforeach; ?>
							</select>
						</div>
						<div class="col-sm-6">
							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" role="switch" id="m_ssl" name="use_ssl"
									value="1"<?= (nu_is_post() ? nu_check('use_ssl') === '1' : NuMailer::setupValue('set_smtp_use_ssl') === '1') ? ' checked' : '' ?>>
								<label class="form-check-label" for="m_ssl"><?= h(t('mail_use_ssl')) ?></label><?php // hint only as tooltip of the (i) – outside the label, so a click does not toggle ?>
								<?= nu_info_tip(t('mail_use_ssl_hint')) ?>
							</div>
						</div>
						<?php // OAuth 2 (core/numailoauth.php): shown with the method OAuth 2 (assets/js/nuemail.js) ?>
						<?php $oa = NuMailOAuth::settings(); $oaPost = nu_is_post() && isset($_POST['oauth_provider']);
						$ov = static fn(string $k) => $oaPost ? nu_post('oauth_' . $k) : (string)($oa[$k] ?? ''); $prov = $ov('provider') ?: 'google'; ?>
						<div class="col-12<?= $curAuth === 'XOAUTH2' ? '' : ' d-none' ?>" id="nuMailOAuth">
							<div class="border rounded p-3 bg-body-tertiary">
								<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
									<h3 class="h6 mb-0 me-auto"><i class="bi bi-shield-lock me-1"></i><?= h(t('mail_auth_oauth')) ?></h3>
									<?php if (NuMailOAuth::connected()): ?>
										<span class="badge text-bg-success"
											id="nuMailOAuthState"><i class="bi bi-check-circle me-1"></i><?= h($oa['account'] !== '' ? t('mail_oauth_connected_as', $oa['account']) : t('mail_oauth_connected')) ?></span>
									<?php else: ?>
										<span class="badge text-bg-warning" id="nuMailOAuthState"><i class="bi bi-exclamation-circle me-1"></i><?= h(t('mail_oauth_not_connected')) ?></span>
									<?php endif; ?>
								</div>
								<div class="row g-3">
									<div class="col-sm-6">
										<label class="form-label" for="m_oprov"><?= h(t('mail_oauth_provider')) ?></label>
										<select class="form-select" id="m_oprov" name="oauth_provider">
											<?php foreach (NuMailOAuth::PROVIDERS as $pv): ?><option value="<?= $pv ?>"<?= $prov === $pv ? ' selected' : '' ?>
												<?php if (isset(NuMailOAuth::SERVERS[$pv])): ?> data-host="<?= h(NuMailOAuth::SERVERS[$pv][0]) ?>"
													data-port="<?= (int)NuMailOAuth::SERVERS[$pv][1] ?>"<?php endif; ?>><?= h(t('mail_oauth_p_' . $pv)) ?></option><?php endforeach; ?>
										</select>
										<button type="button" class="btn btn-link btn-sm px-0" id="nuMailOAuthServer" data-label="<?= h(t('mail_oauth_use_server')) ?>"></button>
									</div>
									<div class="col-sm-6 nuOAuthMs<?= $prov === 'microsoft' ? '' : ' d-none' ?>">
										<label class="form-label" for="m_otenant"><?= h(t('mail_oauth_tenant')) ?></label>
										<?= nu_info_tip(t('mail_oauth_tenant_tip')) ?>
										<input class="form-control" id="m_otenant" name="oauth_tenant" maxlength="100" value="<?= h($ov('tenant') ?: 'common') ?>" autocomplete="off">
									</div>
									<div class="col-sm-6">
										<label class="form-label" for="m_ocid"><?= h(t('mail_oauth_client_id')) ?></label>
										<input class="form-control font-monospace" id="m_ocid" name="oauth_client_id" maxlength="300" value="<?= h($ov('client_id')) ?>" autocomplete="off" spellcheck="false">
									</div>
									<div class="col-sm-6">
										<label class="form-label" for="m_osec"><?= h(t('mail_oauth_client_secret')) ?></label>
										<input type="password" class="form-control font-monospace" id="m_osec" name="oauth_client_secret" maxlength="500" autocomplete="new-password"
											placeholder="<?= h($oa['client_secret'] !== '' ? t('mail_password_unchanged') : '') ?>">
									</div>
									<div class="col-12 nuOAuthOther<?= $prov === 'other' ? '' : ' d-none' ?>">
										<div class="row g-3">
											<div class="col-md-6"><label class="form-label" for="m_oauth"><?= h(t('mail_oauth_auth_url')) ?></label>
												<input type="url" class="form-control font-monospace" id="m_oauth" name="oauth_auth_url" maxlength="500" value="<?= h($ov('auth_url')) ?>" placeholder="https://…/authorize"></div>
											<div class="col-md-6"><label class="form-label" for="m_otok"><?= h(t('mail_oauth_token_url')) ?></label>
												<input type="url" class="form-control font-monospace" id="m_otok" name="oauth_token_url" maxlength="500" value="<?= h($ov('token_url')) ?>" placeholder="https://…/token"></div>
											<div class="col-12"><label class="form-label" for="m_oscope"><?= h(t('mail_oauth_scope')) ?></label>
												<input class="form-control font-monospace" id="m_oscope" name="oauth_scope" maxlength="500" value="<?= h($ov('scope')) ?>"></div>
										</div>
									</div>
									<div class="col-12">
										<label class="form-label" for="m_oredir"><?= h(t('mail_oauth_redirect')) ?></label>
										<?= nu_info_tip(t('mail_oauth_redirect_tip')) ?>
										<div class="input-group">
											<input class="form-control font-monospace" id="m_oredir" value="<?= h(NuMailOAuth::redirectUri()) ?>" readonly>
											<button type="button" class="btn btn-outline-secondary" data-nu-copy-text="<?= h(NuMailOAuth::redirectUri()) ?>"
												title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
										</div>
									</div>
									<div class="col-12 d-flex flex-wrap gap-2">
										<?php if (NuAcl::can(NuAcl::FORM_SETUP, 'save')): ?>
											<button type="submit" class="btn btn-primary" name="action" value="save_connect"
												id="nuMailOAuthConnect"><i class="bi bi-box-arrow-in-right me-1"></i><?= h(t(NuMailOAuth::connected() ? 'mail_oauth_reconnect' : 'mail_oauth_connect')) ?></button>
											<?php if (NuMailOAuth::connected()): ?>
												<button type="submit" class="btn btn-outline-danger" form="nuMailOAuthOff" id="nuMailOAuthDisconnect"><i class="bi bi-x-circle me-1"></i><?= h(t('mail_oauth_disconnect')) ?></button>
											<?php endif; ?>
										<?php endif; ?>
									</div>
									<?php if (NuMailOAuth::plainStored() || (string)nu_cfg('nuConfigSecretKey') === ''): ?>
										<div class="col-12"><div class="alert alert-warning small py-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('mail_oauth_nokey')) ?></div></div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php $canSave = NuAcl::can(NuAcl::FORM_SETUP, 'save'); // copy / paste as JSON without the password (assets/js/nuemail.js) ?>
				<div class="card-footer d-flex flex-wrap justify-content-end gap-2">
					<span id="nuMailClipStatus" class="small me-auto align-self-center" role="status" aria-live="polite"></span>
					<div class="dropdown" id="nuMailXfer"><!-- copy / paste / download as JSON (before: two buttons) -->
						<button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"
							id="nuMailXferBtn"><i class="bi bi-arrow-left-right me-1"></i><?= h(t('mail_xfer')) ?></button>
						<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="nuMailXferBtn">
							<li><button type="button" class="dropdown-item" id="nuMailCopy" title="<?= h(t('mail_copy_settings_help')) ?>"
								data-copied="<?= h(t('mail_copied')) ?>" data-failed="<?= h(t('mail_copy_failed')) ?>"><i class="bi bi-clipboard me-2"></i><?= h(t('mail_copy_settings')) ?></button></li>
							<?php if ($canSave): ?>
							<li><button type="button" class="dropdown-item" id="nuMailPaste" title="<?= h(t('mail_paste_settings_help')) ?>"
								data-pasted="<?= h(t('mail_pasted')) ?>" data-invalid="<?= h(t('mail_paste_invalid')) ?>"><i class="bi bi-clipboard-check me-2"></i><?= h(t('mail_paste_settings')) ?></button></li>
							<?php endif; ?>
							<li><hr class="dropdown-divider"></li>
							<li><button type="button" class="dropdown-item" id="nuMailDownload" title="<?= h(t('mail_download_settings_help')) ?>"
								data-done="<?= h(t('mail_downloaded')) ?>"><i class="bi bi-download me-2"></i><?= h(t('mail_download_settings')) ?></button></li>
						</ul>
					</div>
					<?php if (NuAcl::can(NuAcl::FORM_SETUP)): ?>
						<button class="btn btn-outline-primary" type="submit" form="nuMailCheckForm" id="nuMailCheckBtn2" title="<?= h(t('mail_chk_tip')) ?>"
							data-nu-busy="<?= h(t('mail_chk_busy')) ?>"><i class="bi bi-plug me-1"></i><?= h(t('mail_chk_button')) ?></button>
					<?php endif; ?>
				</div>
			</form>
			<?php if ($canSave): ?>
			<div class="modal fade" id="nuMailPasteModal" tabindex="-1" aria-labelledby="nuMailPasteTitle" aria-hidden="true">
				<div class="modal-dialog modal-dialog-centered">
					<div class="modal-content">
						<div class="modal-header"><h2 class="modal-title h6" id="nuMailPasteTitle"><i class="bi bi-clipboard-check me-2"></i><?= h(t('mail_paste_title')) ?></h2>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
						<div class="modal-body">
							<p class="small text-body-secondary"><?= h(t('mail_paste_hint')) ?></p>
							<textarea class="form-control font-monospace small" id="nuMailPasteText" rows="9" spellcheck="false" aria-label="<?= h(t('mail_paste_title')) ?>"></textarea>
							<div class="invalid-feedback"><?= h(t('mail_paste_invalid')) ?></div>
						</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
							<button type="button" class="btn btn-primary" id="nuMailPasteApply"><i class="bi bi-clipboard-check me-1"></i><?= h(t('mail_paste_apply')) ?></button>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<div class="col-lg-5">
			<div class="card shadow-sm">
				<div class="card-header fw-semibold"><?= h(t('mail_effective')) ?></div>
				<ul class="list-group list-group-flush small">
					<li class="list-group-item d-flex justify-content-between gap-2"><span class="text-body-secondary"><?= h(t('mail_source')) ?></span><span><?= h($s['source'] === 'nuconfig' ? 'nuconfig.php' : 'zzzzsys_setup') ?></span></li>
					<li class="list-group-item d-flex justify-content-between gap-2"><span class="text-body-secondary"><?= h(t('mail_server')) ?></span><span class="text-break text-end"><?= $s['host'] !== '' ? h($s['host'] . ':' . $s['port']) : '<span class="text-danger">' . h(t('mail_not_set')) . '</span>' ?></span></li>
					<li class="list-group-item d-flex justify-content-between gap-2"><span class="text-body-secondary"><?= h(t('mail_encryption')) ?></span><span><?= h($encLabel) ?></span></li>
					<li class="list-group-item d-flex justify-content-between gap-2"><span class="text-body-secondary"><?= h(t('mail_use_auth')) ?></span><span><?= h($s['auth'] ? t('yes') : t('no')) ?><?= $s['auth'] && $s['username'] !== '' ? ' · ' . h($s['username']) : '' ?><?= $s['auth'] ? ' · ' . h($s['auth_type'] !== '' ? $s['auth_type'] : t('mail_auth_auto')) : '' ?></span></li>
					<li class="list-group-item d-flex justify-content-between gap-2"><span class="text-body-secondary"><?= h(t('mail_from_address')) ?></span><span class="text-break text-end"><?= $s['from_address'] !== '' ? h(trim($s['from_name'] . ' <' . $s['from_address'] . '>')) : '<span class="text-danger">' . h(t('mail_not_set')) . '</span>' ?></span></li>
					<li class="list-group-item d-flex justify-content-between gap-2"><span class="text-body-secondary">PHPMailer<?php // the folder (or the places searched) only as tooltip of the (i) ?>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-custom-class="nuTipWide"
							aria-label="<?= h($pmDir ?: implode(', ', NuMailer::phpMailerCandidates())) ?>"
							title="<?= h($pmDir ? nu_short_path($pmDir) : implode(', ', array_map('nu_short_path', NuMailer::phpMailerCandidates()))) ?>"><i class="bi bi-info-circle"></i></span></span>
						<span><?= $pmDir ? h(NuMailer::phpMailerVersion()) : '<span class="text-danger">' . h(t('mail_phpmailer_missing')) . '</span>' ?></span></li>
					<?php if (NuMailer::mailLogFile() !== ''): ?>
						<li class="list-group-item text-warning-emphasis bg-warning-subtle"><i class="bi bi-file-earmark-text me-1"></i><?= h(t('mail_to_file', NuMailer::mailLogFile())) ?></li>
					<?php endif; ?>
				</ul>
			</div>
		</div>
	</div>

<?php elseif ($tab === 'test'): ?>
	<?php $postTest = nu_is_post() && nu_post('action') === 'test';   // the test form keeps what was typed – not after a connection check ?>
	<div class="row g-3">
		<div class="col-lg-6">
			<div class="card shadow-sm mb-3" id="nuMailCheckCard">
				<div class="card-body d-flex flex-wrap align-items-center gap-2">
					<div class="me-auto"><div class="fw-semibold"><i class="bi bi-plug me-1"></i><?= h(t('mail_chk_title')) ?></div><div class="small text-body-secondary"><?= h(t('mail_chk_hint')) ?></div></div>
					<button class="btn btn-outline-primary" type="submit" form="nuMailCheckForm" id="nuMailCheckBtn"
						data-nu-busy="<?= h(t('mail_chk_busy')) ?>"><i class="bi bi-plug me-1"></i><?= h(t('mail_chk_button')) ?></button>
				</div>
			</div>
			<form method="post" class="card shadow-sm" novalidate>
				<?= nu_csrf_field() ?>
				<input type="hidden" name="action" value="test">
				<div class="card-body">
					<div class="mb-3">
						<label class="form-label" for="t_to"><?= h(t('mail_to')) ?> <span class="text-danger">*</span></label>
						<input class="form-control<?= isset($errors['to']) ? ' is-invalid' : '' ?>" id="t_to" name="to" value="<?= h($postTest ? nu_post('to') : (string)(NuAuth::user()['email'] ?? '')) ?>" required
							data-nu-remember="nunext.mailTestTo"<?= $postTest ? ' data-nu-remember-keep' : '' ?>>
						<div class="invalid-feedback"><?= h($errors['to'] ?? '') ?></div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="t_subject"><?= h(t('mail_subject')) ?></label>
						<input class="form-control" id="t_subject" name="subject" value="<?= h($postTest ? nu_post('subject') : t('mail_test_subject')) ?>">
					</div>
					<div>
						<label class="form-label" for="t_debug"><?= h(t('mail_debug')) ?></label>
						<select class="form-select" id="t_debug" name="debug">
							<?php foreach ([0 => t('mail_debug_0'), 2 => t('mail_debug_2'), 3 => t('mail_debug_3')] as $lv => $lb): ?>
								<option value="<?= $lv ?>"<?= (int)($postTest ? nu_post('debug', '2') : 2) === $lv ? ' selected' : '' ?>><?= h($lb) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="card-footer d-flex justify-content-end align-items-center gap-2">
					<?= nu_info_tip(t('mail_test_hint'), '') ?><?php // what the test does – only as tooltip ?>
					<button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i><?= h(t('mail_send_test')) ?></button>
				</div>
			</form>
		</div>
		<div class="col-lg-6">
			<?php if ($checkResult !== null): $chkIcons = ['ok' => 'bi-check-circle-fill text-success', 'fail' => 'bi-x-circle-fill text-danger', 'note' => 'bi-info-circle text-body-secondary']; ?>
				<div class="card shadow-sm mb-3" id="mailCheckResult">
					<div class="card-header fw-semibold <?= $checkResult['ok'] ? 'text-success' : 'text-danger' ?>"><i class="bi <?= $checkResult['ok'] ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i><?= h(t($checkResult['ok'] ? 'mail_chk_ok' : 'mail_chk_failed')) ?></div>
					<ul class="list-group list-group-flush small">
						<?php foreach ($checkResult['steps'] as [$k, $ok, $d]): $ic = $chkIcons[$ok === null ? 'note' : ($ok ? 'ok' : 'fail')]; ?>
							<li class="list-group-item d-flex gap-2 align-items-start" data-step="<?= h($k) ?>" data-ok="<?= $ok === null ? 'note' : ($ok ? '1' : '0') ?>">
								<i class="bi <?= $ic ?> mt-1" role="img" aria-label="<?= h($ok === null ? t('mail_chk_note') : ($ok ? t('yes') : t('no'))) ?>"></i>
								<div><span class="fw-semibold"><?= h(t('mail_chk_' . $k)) ?></span><?php if ($d !== ''): ?><div class="text-body-secondary text-break"><?= h($d) ?></div><?php endif; ?></div></li>
						<?php endforeach; ?>
					</ul>
					<?php if (NuMailer::mailLogFile() !== ''): ?><div class="card-footer small text-body-secondary"><?= h(t('mail_chk_maillog')) ?></div><?php endif; ?>
				</div>
				<?php $pr = NuMailer::$probeResult; if ($pr && $pr['auth'] && $pr['offered'] !== null): ?>
					<div class="alert alert-light border small"
						id="mailCheckProbe"><span class="fw-semibold"><?= h(t('mail_auth_offered')) ?>:</span> <?= $pr['offered'] ? '<code>' . h(implode(' ', $pr['offered'])) . '</code>' : h(t('mail_auth_none')) ?>
						<?php if ($pr['used'] !== ''): ?> · <span class="fw-semibold"><?= h(t('mail_auth_used')) ?>:</span> <code><?= h($pr['used']) ?></code><?php endif; ?></div>
				<?php endif; ?>
				<?php if ($checkResult['debug'] !== ''): ?>
					<details class="card shadow-sm mb-3"><summary class="card-header small fw-semibold"><?= h(t('mail_debug_output')) ?></summary><pre class="small mb-0 p-3 nuMailDebug"><?= h($checkResult['debug']) ?></pre></details>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ($testResult !== null): ?>
				<div class="alert <?= $testResult[0] ? 'alert-success' : 'alert-danger' ?>" id="mailTestResult">
					<div class="fw-semibold"><?= h($testResult[0] ? t('mail_test_ok') : t('mail_test_failed')) ?></div>
					<div class="small"><?= h(strip_tags((string)$testResult[1])) ?></div>
					<?php if (($testResult[2] ?? '') !== '' && strip_tags((string)$testResult[2]) !== strip_tags((string)$testResult[1])): ?><div class="small mt-1"><code><?= h(strip_tags((string)$testResult[2])) ?></code></div><?php endif; ?>
					<?php if (!empty($testResult[4])): ?><div class="small mt-1"><?= h(t('mail_rejected')) ?>: <?= h(implode(', ', $testResult[4])) ?></div><?php endif; ?>
				</div>
				<?php $pr = NuMailer::$probeResult; if ($pr && $pr['auth']):
					$offered = $pr['offered']; ?>
					<div class="alert alert-light border small" id="mailAuthProbe">
						<div><span class="fw-semibold"><?= h(t('mail_auth_offered')) ?>:</span> <?= $offered === null ? h(t('mail_auth_unknown')) : ($offered ? '<code>' . h(implode(' ', $offered)) . '</code>' : h(t('mail_auth_none'))) ?></div>
						<div><span class="fw-semibold"><?= h(t('mail_auth_used')) ?>:</span> <?= $pr['used'] !== '' ? '<code>' . h($pr['used']) . '</code>' : '–' ?> (<?= h($pr['forced'] !== '' ? t('mail_auth_forced') : t('mail_auth_auto')) ?>)</div>
						<?php if ($pr['forced'] !== '' && is_array($offered) && $offered && !in_array($pr['forced'], $offered, true)): ?>
							<div class="text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('mail_auth_not_offered', $pr['forced'])) ?></div>
						<?php elseif (!$testResult[0] && $pr['forced'] === '' && is_array($offered) && count($offered) > 1): ?>
							<div class="text-warning-emphasis mt-1"><i class="bi bi-lightbulb me-1"></i><?= h(t('mail_auth_try', in_array('LOGIN', $offered, true) && $pr['used'] !== 'LOGIN' ? 'LOGIN' : 'PLAIN')) ?></div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if (($testResult[3] ?? '') !== ''): ?>
					<div class="card shadow-sm">
						<div class="card-header small fw-semibold"><?= h(t('mail_debug_output')) ?></div>
						<pre class="small mb-0 p-3 nuMailDebug"><?= h($testResult[3]) ?></pre>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

<?php elseif ($detail): ?>
	<?php
	$files = json_decode((string)($detail['eml_file'] ?? ''), true);
	$userLogin = '';
	if (!empty($detail['eml_user_id'])) {
		$userLogin = $detail['eml_user_id'] === 'globeadmin' ? 'globeadmin'
			: ($db->tableExists('zzzzsys_user') ? NuAuth::userLogin((string)$detail['eml_user_id']) : '');
	}
	?>
	<div class="mb-3 d-flex gap-2"><a class="btn nuBtnAct" href="<?= h(nu_url('email', ['tab' => 'log'])) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if (NuAcl::can(NuAcl::FORM_EMAILLOG, 'delete')): ?>
			<form method="post" data-confirm="<?= h(t('mail_entry_delete_confirm')) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="id" value="<?= h((string)$detail['zzzzsys_email_log_id']) ?>">
				<button class="btn nuBtnAct nuBtnDel btn-sm" type="submit"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			</form>
		<?php endif; ?>
	</div>
	<div class="card shadow-sm mb-3">
		<div class="card-header d-flex justify-content-between align-items-center gap-2">
			<span class="fw-semibold text-break"><?= h($detail['eml_subject']) ?></span><?= NuEmailPage::state($detail['eml_state']) ?>
		</div>
		<div class="card-body small">
			<dl class="row mb-0 nuMailMeta">
				<dt class="col-sm-3"><?= h(t('mail_from_address')) ?></dt><dd class="col-sm-9 text-break"><?= h(trim($detail['eml_from_name'] . ' <' . $detail['eml_from'] . '>')) ?></dd>
				<dt class="col-sm-3"><?= h(t('mail_to')) ?></dt><dd class="col-sm-9 text-break"><?= h($detail['eml_to']) ?></dd>
				<?php if ((string)$detail['eml_cc'] !== ''): ?><dt class="col-sm-3">Cc</dt><dd class="col-sm-9 text-break"><?= h($detail['eml_cc']) ?></dd><?php endif; ?>
				<?php if ((string)$detail['eml_bcc'] !== ''): ?><dt class="col-sm-3">Bcc</dt><dd class="col-sm-9 text-break"><?= h($detail['eml_bcc']) ?></dd><?php endif; ?>
				<?php if (!in_array((string)($detail['eml_reply_to'] ?? ''), ['', '[]', '{}'], true)): ?><dt class="col-sm-3">Reply-To</dt><dd class="col-sm-9 text-break"><?= h($detail['eml_reply_to']) ?></dd><?php endif; ?>
				<dt class="col-sm-3"><?= h(t('mail_created')) ?></dt><dd class="col-sm-9"><?= h(NuEmailPage::dt($detail['eml_created_at'])) ?></dd>
				<dt class="col-sm-3"><?= h(t('mail_sent')) ?></dt><dd class="col-sm-9"><?= h(NuEmailPage::dt($detail['eml_sent_at'])) ?></dd>
				<?php if ($userLogin !== ''): ?><dt class="col-sm-3"><?= h(t('user')) ?></dt><dd class="col-sm-9"><?= h($userLogin) ?></dd><?php endif; ?>
				<?php if ((string)($detail['eml_form_id'] ?? '') !== ''): ?><dt class="col-sm-3"><?= h(t('mail_form')) ?></dt><dd class="col-sm-9"><?= h(nu_form_label((string)$detail['eml_form_id'])) ?><?= (string)($detail['eml_record_id'] ?? '') !== '' ? ' · #' . h($detail['eml_record_id']) : '' ?><?= (string)($detail['eml_table_name'] ?? '') !== '' ? ' · ' . h($detail['eml_table_name']) : '' ?></dd><?php endif; ?>
				<?php if ((string)($detail['eml_tag'] ?? '') !== ''): ?><dt class="col-sm-3">Tag</dt><dd class="col-sm-9"><?= h($detail['eml_tag']) ?></dd><?php endif; ?>
				<?php if ((string)($detail['eml_importance'] ?? '') !== ''): ?><dt class="col-sm-3"><?= h(t('mail_priority')) ?></dt><dd class="col-sm-9"><?= h($detail['eml_importance']) ?></dd><?php endif; ?>
				<?php if (is_array($files) && $files): ?><dt class="col-sm-3"><?= h(t('mail_attachments')) ?></dt><dd class="col-sm-9 text-break"><?= h(implode(', ', array_map(static fn($k, $v) => is_string($k) ? $k : (string)$v, array_keys($files), $files))) ?></dd><?php endif; ?>
				<?php if ((string)($detail['eml_error'] ?? '') !== ''): ?><dt class="col-sm-3 text-danger"><?= h(t('mail_error')) ?></dt><dd class="col-sm-9 text-danger text-break nuPreWrap"><?= h($detail['eml_error']) ?></dd><?php endif; ?>
				<?php if ((string)($detail['eml_json'] ?? '') !== ''): ?><dt class="col-sm-3">JSON</dt><dd class="col-sm-9"><code class="text-break"><?= h($detail['eml_json']) ?></code></dd><?php endif; ?>
			</dl>
		</div>
	</div>
	<div class="card shadow-sm">
		<iframe class="nuMailBody" sandbox="" title="<?= h(t('mail_body')) ?>" src="<?= h(nu_url('email', ['body' => (string)$detail['zzzzsys_email_log_id']])) ?>"></iframe>
	</div>

<?php else: ?>
	<?php if (!$logExists): ?>
		<div class="card card-body text-center text-body-secondary py-5"><?= h(t('no_records')) ?></div>
	<?php else: ?>
		<?= NuListView::card($lvState, ['title' => '', 'back' => false, 'buttons' => $purgeForm]) ?>
	<?php endif; ?>
<?php endif; ?>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuemail.js') . '"></script>' . ($lvState ? NuListView::foot() : ''));
