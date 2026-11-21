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
	$roleId = nu_post('role');                              // test an admin role
	$from = $roleId !== '' ? nu_url('adminroles', ['id' => $roleId]) : nu_url('user', ['id' => nu_post('id')]);
	if (nu_post('mode') === 'full') NuSecurity::requireRecentAuth($from);   // full access: password confirmed recently
	if ($roleId !== '' && nu_post('id') === '') { nu_flash('warning', t('imp_test_pick_user')); nu_redirect($from); }
	$err = NuImpersonate::start(nu_post('id'), nu_post('mode'), nu_post('back'), $roleId);
	if ($err !== null) {
		nu_flash('danger', t($err));
		nu_redirect($roleId !== '' ? $from : nu_url('home'));
	}
	$i = NuImpersonate::active();
	if ($roleId !== '') nu_flash('info', t($i['mode'] === 'view' ? 'imp_test_started_view' : 'imp_test_started_full', (string)$i['role_name'], $i['login']));
	else nu_flash('info', t($i['mode'] === 'view' ? 'imp_started_view' : 'imp_started_full', $i['login']));
	nu_redirect(nu_url('home'));
}
nu_redirect(nu_url('home'));
