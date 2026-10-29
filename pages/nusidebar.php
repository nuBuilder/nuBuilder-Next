<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Side bar (0.8.460, core/nusidebar.php) – form posts from the user menu and the side bar, back to the page they came from:
 *   toggle   the side bar on / off for me
 *   default  on / off for everyone without an own choice (the globeadmin)
 *   clear    empty my "Recently used"
 *   forget   one entry (key) out of my "Recently used"
 *   fav      ☆ of a start page tile (key) – the start page's favourites
 *   used     a start page tile was opened (key; sent with navigator.sendBeacon, answers 204 – 0.8.492)
 *   pin      keep the side bar open next to the page, or not (on = 1 / 0; sent with fetch from nusidebar.js, answers 204 – 0.8.556)
 *   hide     hide a start page tile (key, label for the message) from its right-click menu – in the layout or for me (0.8.652)
 *   unhide   Undo of the last hide (key)
 */

$u = NuAuth::user();
$back = NuAuth::validNext(nu_post('back')) ?? nu_url('home');
if (!nu_is_post()) nu_redirect($back);

if (nu_post('action') === 'pin') {
	NuSidebar::setPinned(nu_post('on') === '1');
	http_response_code(204);
	exit;
}
if (nu_post('action') === 'used') {
	NuSidebar::recordTile(nu_post('key'));
	http_response_code(204);
	exit;
}

switch (nu_post('action')) {
	case 'toggle':
		NuSidebar::set(!NuSidebar::enabled());
		break;
	case 'default':
		if (!$u['globeadmin'] || !empty($u['demo'])) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
		NuSidebar::setDefault(!NuSidebar::defaultOn());
		nu_flash('success', t(NuSidebar::defaultOn() ? 'sb_default_now_on' : 'sb_default_now_off'));
		break;
	case 'clear':
		NuSidebar::clearRecent();
		break;
	case 'forget':
		NuSidebar::forget(nu_post('key'));
		break;
	case 'fav':
		$demoUser = !empty($u['demo']);
		$r = $demoUser ? NuDemo::permitted(static fn() => NuSidebar::favToggle(nu_post('key'))) : NuSidebar::favToggle(nu_post('key'));
		if ($r === null) nu_flash('warning', t('sb_fav_not_possible'));
		break;
	case 'hide':     // tile menu of the start page (0.8.652): hide a tile – in the layout (admins) or for me (user start pages); message with Undo
	case 'unhide':   // its Undo
		$fn = nu_post('action') === 'hide' ? static fn() => NuSidebar::hideTile(nu_post('key')) : static fn() => NuSidebar::unhideTile(nu_post('key'));
		$ok = !empty($u['demo']) ? (bool)NuDemo::permitted($fn) : $fn();
		if (!$ok) nu_flash('warning', t(nu_post('action') === 'hide' ? 'home_ctx_hide_fail' : 'home_ctx_undo_fail'));
		elseif (nu_post('action') === 'hide' && isset($_SESSION[NuSidebar::UNDO])) $_SESSION[NuSidebar::UNDO]['label'] = mb_substr(trim(nu_post('label')), 0, 80);
		break;
}
nu_redirect($back);
