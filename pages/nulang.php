<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

$lang = is_string($_GET['l'] ?? null) && (isset(NU_LANGS[$_GET['l']]) || isset(NU_LANG_VARIANTS[$_GET['l']])) ? $_GET['l'] : NuLanguages::fallback();   // nu_set_lang: installed languages only (0.8.701)
nu_set_lang($lang);
unset($_SESSION['nu_lang_carried']);                       // chosen now – counts as picked on the sign-in page (0.8.1205)

// remember per user in sus_language (nuBuilder reads the same column) – not while acting as another user, and only when the
// click came from a page of this installation (a link on another site changes the session language only)
$u = NuAuth::user();
$ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
$sameSite = is_array($ref) && strcasecmp((string)($ref['host'] ?? ''), (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) === 0;
// the globeadmin has no user row: his choice goes into the setting lang_ga (0.8.1205), read at his next sign-in
if ($u && $u['globeadmin'] && $sameSite && empty($u['demo']) && ($u['via'] ?? '') !== 'demo_ga' && !NuImpersonate::active()) {
	try { NuSchema::ensure(); nu_setting_save(NuAuth::LANG_GA, ['setting_value' => nu_lang_choice()]); } catch (Throwable) { /* best effort */ }
}
if ($u && !$u['globeadmin'] && !NuImpersonate::active() && $sameSite) {
	nu_db()->update('zzzzsys_user', ['sus_language' => nu_lang_to_db(nu_lang())], 'zzzzsys_user_id', $u['id']);
	if (isset(NU_LANG_VARIANTS[$lang]) || NuUserJson::get($u['id'], 'NUNEXT_LANG_VARIANT') !== '') NuUserJson::set($u['id'], 'NUNEXT_LANG_VARIANT', nu_lang_variant());   // English UK / Australia / US
}

// only go back to a query string of this app
$r = $_GET['r'] ?? '';
$r = (is_string($r) && preg_match('/^[A-Za-z0-9_\-=&%.]*$/', $r) && !str_contains($r, 'p=lang')) ? $r : '';
nu_redirect('index.php' . ($r !== '' ? '?' . $r : ''));
