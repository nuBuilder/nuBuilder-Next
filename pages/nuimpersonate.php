<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* Act as user: POST action=start (globeadmin; id, mode view|full, back) · action=stop (back to the globeadmin). */

if (!nu_is_post()) nu_redirect(nu_url('home'));

$action = nu_post('action');
if ($action === 'stop') {
	$back = NuImpersonate::stop();
	nu_flash('success', t('imp_ended'));
	nu_redirect($back);
}
if ($action === 'start') {
	if (nu_post('mode') === 'full') NuSecurity::requireRecentAuth(nu_url('user', ['id' => nu_post('id')]));   // full access: password confirmed recently
	$err = NuImpersonate::start(nu_post('id'), nu_post('mode'), nu_post('back'));
	if ($err !== null) {
		nu_flash('danger', t($err));
		nu_redirect(nu_url('home'));
	}
	$i = NuImpersonate::active();
	nu_flash('info', t($i['mode'] === 'view' ? 'imp_started_view' : 'imp_started_full', $i['login']));
	nu_redirect(nu_url('home'));
}
nu_redirect(nu_url('home'));
