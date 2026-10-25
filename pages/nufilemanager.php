<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * File Manager (0.8.479, core/nufilemanager.php): every entry point opens this route. With the page form nufiles it redirects there
 * (the page checks the form's access levels and asks for the password before code folders). Without it the globeadmin can create it
 * (POST action setup, password confirmed recently); others read that it is not set up yet.
 */

NuAdmin::require('filemanager', 'change');
$u = NuAuth::user();
$ga = !empty($u['globeadmin']);

if (nu_is_post() && nu_post('action') === 'setup') {
	if (!$ga) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
	NuSecurity::requireRecentAuth(nu_url('filemanager'));
	try {
		NuFileManager::setup();
		nu_flash('success', t('fm_setup_done'));
	} catch (Throwable $e) {
		nu_flash('danger', t('fm_setup_failed') . ' ' . $e->getMessage());
	}
	nu_redirect(nu_url('filemanager'));
}

if (NuFileManager::formId() !== null && NuFileManager::shipped()) nu_redirect(nu_url('page', ['f' => NuFileManager::CODE]));

nu_center_start(t('vendor_tfm'), t('fm_not_set_up'), 'bi-folder2-open');
?>
<?php if (!NuFileManager::shipped()): ?>
	<div class="alert alert-warning small mb-3" id="fmMissing"><?= h(t('fm_missing_files', NuPage::shownFolder(NuFileManager::CODE))) ?></div>
<?php elseif ($ga): ?>
	<p class="small text-body-secondary"><?= h(t('fm_setup_hint')) ?></p>
	<form method="post" action="<?= h(nu_url('filemanager')) ?>" data-nu-once>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="setup">
		<button class="btn btn-primary w-100 py-2" type="submit" id="fmSetup" data-nu-busy="<?= h(t('fm_setup')) ?>"><i class="bi bi-magic me-2"></i><?= h(t('fm_setup')) ?></button>
	</form>
<?php else: ?>
	<p class="small text-body-secondary mb-0" id="fmAskAdmin"><?= h(t('fm_ask_admin')) ?></p>
<?php endif; ?>
<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('home')) ?>"><?= h(t('back')) ?></a></div>
<?php
nu_center_end();
