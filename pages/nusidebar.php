<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Side bar (0.8.460, core/nusidebar.php) – form posts from the user menu and the side bar, back to the page they came from:
 *   toggle   the side bar on / off for me
 *   default  on / off for everyone without an own choice (the globeadmin)
 *   clear    empty my "Recently used"
 *   fav      ☆ of a start page tile (key) – the start page's favourites
 */

$u = NuAuth::user();
$back = NuAuth::validNext(nu_post('back')) ?? nu_url('home');
if (!nu_is_post()) nu_redirect($back);

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
	case 'fav':
		$demoUser = !empty($u['demo']);
		$r = $demoUser ? NuDemo::permitted(static fn() => NuSidebar::favToggle(nu_post('key'))) : NuSidebar::favToggle(nu_post('key'));
		if ($r === null) nu_flash('warning', t('sb_fav_not_possible'));
		break;
}
nu_redirect($back);
