<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * nuBuilder's auto login (wiki: Login) – index.php?u=<login name>&p=<password hash>&f=<form id>&r=<record id>&h=<home id>
 *   with p:    logs in when $nuConfigAutoLogin = true and p is the user's auto-login hash (NuAuth::urlHash, see the user form);
 *              never a plain password, never the globeadmin.
 *   without p: the login page with the name filled in; after the login the form / record opens.
 *   f + r:     form and record to open (r empty = list, -1 = new record); h without f: that form as start page.
 *   e:         expiry of the link (Unix time, part of the hash – links made in the user form since 0.8.207).
 * The hash is removed from the address bar at once (redirect) and not sent on as referrer.
 * Somebody else logged in: the link does not replace that login silently – a confirmation page asks first (0.8.207).
 * p that is not a 64-hex hash (or $nuConfigAutoLogin is off): nuBuilder's single-use login token (sus_json LOGIN_TOKEN, wiki
 * "Single-User Password Token") – u may be the login name or the user id (NuAuth::loginToken, core/nulogintoken.php, 0.8.339).
 */
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$login  = mb_substr(trim((string)$_GET['u']), 0, 50);
$raw    = is_string($_GET['p'] ?? null) ? trim($_GET['p']) : '';
$hash   = strtolower($raw);
// 64 hex characters with $nuConfigAutoLogin = nuBuilder Next's signed auto-login hash, anything else long enough = a login token
$token  = $raw !== '' && (!nu_cfg('nuConfigAutoLogin') || !preg_match('/^[a-f0-9]{64}$/i', $raw)) && NuLoginToken::enabled() && NuLoginToken::looksLike($raw) ? $raw : '';
if ($token !== '') $hash = '';
$form   = is_string($_GET['f'] ?? null) ? trim($_GET['f']) : '';
$record = is_string($_GET['r'] ?? null) ? trim($_GET['r']) : '';
$home   = is_string($_GET['h'] ?? null) ? trim($_GET['h']) : '';

$target = nu_url('home');
if ($form !== '' && preg_match('/^[\w-]{1,25}$/', $form)) $target = nu_url('form', ['f' => $form, 'r' => $record !== '' ? $record : null]);
elseif ($home !== '' && preg_match('/^[\w-]{1,25}$/', $home) && !in_array($home, ['nuhome', 'nuuserhome'], true)) $target = nu_url('form', ['f' => $home, 'r' => '-1']);

$expires = is_string($_GET['e'] ?? null) && ctype_digit($_GET['e']) ? (int)$_GET['e'] : 0;

$current = NuAuth::user();
if ($current && (strcasecmp((string)$current['login'], $login) === 0 || ($token !== '' && (string)$current['id'] === $login))) nu_redirect($target);    // already logged in as this user (the token stays unused)
if ($current) {
	// another user: ask before logging out (a link must not switch the login without the visitor noticing)
	$_SESSION['nu_autologin_pending'] = ['login' => $login, 'hash' => $hash, 'token' => $token, 'e' => $expires, 'target' => $target, 'time' => time()];
	nu_center_start(t('autologin_switch_title'), '', 'bi-person-gear');
	echo '<p>' . h(t('autologin_switch_text', (string)$current['login'], $login)) . '</p>'
		. '<form method="post" action="' . h(nu_url('login')) . '" class="d-flex gap-2">' . nu_csrf_field()
		. '<input type="hidden" name="action" value="autologin">'
		. '<button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>' . h(t('autologin_switch_ok', $login)) . '</button>'
		. '<a class="btn btn-outline-secondary" href="' . h(nu_url('home')) . '">' . h(t('cancel')) . '</a></form>';
	nu_center_end();
	exit;
}

NuAuth::setNext($target);
NuAuth::$urlExpires = $expires;
if ($token !== '' && $login !== '') {
	$err = NuAuth::loginToken($login, $token);
	if ($err === null) nu_redirect(NuAuth::takeNext());
	nu_flash('danger', t($err));
	$found = NuLoginToken::findUser($login);                         // u may be a user id: fill in the login name
	nu_redirect(nu_url('login', ['user' => $found ? (string)$found['sus_login_name'] : $login]));
}
if ($hash !== '' && $login !== '') {
	if (!nu_cfg('nuConfigAutoLogin')) {
		nu_flash('warning', t('autologin_off'));
	} elseif (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
		NuLog::write('login_failed', '', '', ['reason' => 'login_err_invalid', 'via' => 'url'], ['id' => '', 'login' => $login]);
		nu_flash('danger', t('login_err_invalid'));
	} else {
		$err = NuAuth::login($login, $hash, true);
		if ($err === null) nu_redirect(NuAuth::takeNext());
		if ($err === '2fa') nu_redirect(nu_url('twofa'));
		nu_flash('danger', t($err));
	}
}
nu_redirect(nu_url('login', ['user' => $login]));
