<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

// logout only via POST (CSRF checked in index.php)
if (nu_is_post()) {
	if (NuAuth::user()) NuLog::write('logout');
	NuAuth::logout();
	nu_flash('success', t('logout_done'));
}
nu_redirect(nu_url('login'));
