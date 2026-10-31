<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

NuAcl::require(NuAcl::FORM_USER);
$rights = NuAcl::rights(NuAcl::FORM_USER);
$me     = NuAuth::user();
$db     = nu_db();

$id      = (string)($_GET['id'] ?? '');
$cloneId = (string)($_GET['clone'] ?? '');

// welcome e-mail preview (0.8.684): the rendered mail for the iframe of the preview dialog – from the session, with a CSP of its own
// (mails carry inline styles; no scripts); assets/js/nuuser.js makes it editable (designMode) and reads it back
if (isset($_GET['welcome_frame'])) {
	$html = $_SESSION['nu_welcome_pv'][(string)$_GET['welcome_frame']] ?? null;
	if (!is_string($html)) { http_response_code(404); exit; }
	header('X-Frame-Options: SAMEORIGIN', true);
	header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: https: http:; font-src data: https:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'", true);
	header('Content-Type: text/html; charset=utf-8');
	exit($html);
}

$existing = $id !== '' ? $db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$id]) : null;
if ($id !== '' && !$existing) {
	nu_flash('warning', t('record_not_found'));
	nu_redirect(nu_url('users'));
}

/**
 * Admin-equivalent users (their access level may change code, users or rights – NuSecurity::ADMIN_FORMS): with the setting
 * "Only the globeadmin grants admin rights" (on by default) nobody else may change, archive, delete or reset them.
 */
$guardAdminUser = static function () use ($existing, $me, $id): void {
	if (!$existing || !NuSecurity::restricted() || $existing['zzzzsys_user_id'] === $me['id']) return;
	$lv = [(string)$existing['sus_zzzzsys_access_id'], ...array_column(NuUserAccess::rowsOf((string)$existing['zzzzsys_user_id']), 'access_id')];
	if (!array_filter($lv, static fn($a) => (bool)NuSecurity::adminRightsOf((string)$a))) return;   // also through additional levels (0.8.585)
	nu_flash('danger', t('secchk_err_admin_user'));
	nu_redirect(nu_url('user', ['id' => $id]));
};

/** After delete / archive from the users list: back to the list with its search, filters, sort and page. */
$backToList = static function (): string {
	parse_str((string)($_POST['return'] ?? ''), $q);
	$keep = array_intersect_key(array_filter($q, 'is_string'), array_flip(['q', 'view', 'access', 'state', 'pos', 'dept', 'team', 'lang', 'login', 'expires', 'twofa', 'online', 'role', 'sort', 'dir', 'pg']));
	return nu_url('users', $keep);
};

// ------------------------------------------------------------------ archive: Valid until = yesterday (login no longer possible, data stays)
// ------------------------------------------------------------------ effective rights (0.8.589): the levels entered in the form, not saved yet
if (nu_is_post() && nu_post('action') === 'effective') {
	$rowsIn = [];
	$xaA = is_array($_POST['xa_acc'] ?? null) ? $_POST['xa_acc'] : [];
	$xaU = is_array($_POST['xa_until'] ?? null) ? $_POST['xa_until'] : [];
	foreach ($xaA as $i => $a) if (is_string($a) && trim($a) !== '') $rowsIn[] = [trim($a), is_string($xaU[$i] ?? null) && nu_valid_date(trim($xaU[$i])) ? trim($xaU[$i]) : ''];
	header('Content-Type: application/json; charset=utf-8');
	exit((string)json_encode(['ok' => true] + NuUserAccess::effective(nu_post('sus_zzzzsys_access_id'), $rowsIn), JSON_UNESCAPED_UNICODE));
}

// ------------------------------------------------------------------ permission items of another user (0.8.649): "Copy from user…" ticks them
// in the form (Replace / Add, assets/js/nuuser.js) – nothing is saved here, the form's Save writes them as usual
if (nu_is_post() && nu_post('action') === 'perm_of') {
	$from  = nu_post('from');
	$known = $from !== '' && (int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$from]) > 0;
	$ids   = $known ? array_map('strval', array_keys(array_filter(NuPermission::forUser($from), static fn($a) => $a === '1'))) : [];
	header('Content-Type: application/json; charset=utf-8');
	exit((string)json_encode($known ? ['ok' => true, 'items' => $ids] : ['ok' => false], JSON_UNESCAPED_UNICODE));
}

if (nu_is_post() && nu_post('action') === 'archive' && $existing) {
	NuAcl::require(NuAcl::FORM_USER, 'save');
	$guardAdminUser();
	if ($existing['zzzzsys_user_id'] === $me['id']) {
		nu_flash('danger', t('user_err_archive_self'));
		nu_redirect($backToList());
	}
	$until = date('Y-m-d', strtotime('-1 day'));
	$db->update('zzzzsys_user', ['sus_expires_on' => $db->datetime($until)], 'zzzzsys_user_id', $id);
	NuUserJson::stamp($id, false, 'form');
	NuLog::write('user_archived', 'zzzzsys_user', $id, ['login' => $existing['sus_login_name'], 'expires_on' => $until,
		'previous' => nu_date_only($existing['sus_expires_on'])]);
	nu_flash('success', t('user_archived', $existing['sus_login_name'], date(nu_dfmt('d.m.Y'), strtotime($until))));
	nu_redirect(nu_post('stay') === '1' ? nu_url('user', ['id' => $id]) : $backToList());
}

// ------------------------------------------------------------------ delete
if (nu_is_post() && nu_post('action') === 'delete') {
	NuAcl::require(NuAcl::FORM_USER, 'delete');
	$guardAdminUser();
	if ($existing['zzzzsys_user_id'] === $me['id']) {
		nu_flash('danger', t('user_err_delete_self'));
		nu_redirect(nu_url('user', ['id' => $id]));
	}
	$db->delete('zzzzsys_user', 'zzzzsys_user_id', $id);
	NuPermission::deleteUser($id);                               // nuBuilder deletes the subform rows (sf_permission) as well
	NuTwoFA::removeTotp($id);
	NuTwoFA::forgetDevices($id);
	NuLog::write('user_deleted', 'zzzzsys_user', $id, ['login' => $existing['sus_login_name']]);
	NuUserFields::forget($id);                                  // custom field values (0.8.454)
	NuUserAccess::forgetUser($id);                              // additional access levels (0.8.585)
	nu_flash('success', t('user_deleted', $existing['sus_login_name']));
	nu_redirect($backToList());
}

// ------------------------------------------------------------------ reset second factor of the user
if (nu_is_post() && nu_post('action') === 'reset2fa' && $existing) {
	NuAcl::require(NuAcl::FORM_USER, 'save');
	$guardAdminUser();
	NuTwoFA::removeTotp($id);
	NuTwoFA::forgetDevices($id);
	NuLog::write('2fa_reset_by_admin', 'zzzzsys_user', $id, ['login' => $existing['sus_login_name']]);
	nu_flash('success', t('user_2fa_reset_done'));
	nu_redirect(nu_url('user', ['id' => $id]));
}

// ------------------------------------------------------------------ auto-login links: revoke all links of this user (globeadmin)
if (nu_is_post() && nu_post('action') === 'autologin_revoke' && $existing) {
	if (!$me['globeadmin'] || !empty($me['demo'])) NuAcl::require('__globeadmin_only__');
	$epoch = (int)NuUserJson::get($id, 'NUNEXT_AUTOLOGIN_EPOCH') + 1;
	if (NuUserJson::set($id, 'NUNEXT_AUTOLOGIN_EPOCH', $epoch)) {
		NuLog::write('autologin_revoked', 'zzzzsys_user', $id, ['login' => $existing['sus_login_name']]);
		nu_flash('success', t('user_autologin_revoked'));
	} else {
		nu_flash('danger', t('user_autologin_no_json'));
	}
	nu_redirect(nu_url('user', ['id' => $id]));
}

// ------------------------------------------------------------------ one-time login link: nuBuilder's LOGIN_TOKEN (globeadmin, 0.8.339)
if (nu_is_post() && in_array(nu_post('action'), ['logintoken_create', 'logintoken_mail', 'logintoken_clear'], true) && $existing) {
	if (!$me['globeadmin'] || !empty($me['demo'])) NuAcl::require('__globeadmin_only__');
	try {
		if (nu_post('action') === 'logintoken_clear') {
			NuLoginToken::clear($id);
			nu_flash('success', t('ltok_cleared'));
		} else {
			$min = (int)nu_post('minutes');
			if (!in_array($min, NuLoginToken::CHOICES, true)) $min = 1440;
			$link = NuLoginToken::create($id, $min);
			$_SESSION['nu_ltok_link'] = ['id' => $id, 'link' => $link];          // shown once in the box below
			if (nu_post('action') === 'logintoken_mail') {
				$err = NuLoginToken::mail($id, $link, $min);
				nu_flash($err === null ? 'success' : 'danger', $err === null ? t('ltok_mailed', (string)$existing['sus_email']) : $err);
			} else {
				nu_flash('success', t('ltok_created'));
			}
		}
	} catch (RuntimeException $e) {
		nu_flash('danger', $e->getMessage());
	}
	nu_redirect(nu_url('user', ['id' => $id]) . '#loginTokenBox');
}

// ------------------------------------------------------------------ field definitions (column => [max length, label key])
$text = [
	'sus_login_name'  => [50, 'user_login_name'],
	'sus_name'        => [50, 'user_display_name'],
	'sus_first_name'  => [30, 'user_first_name'],
	'sus_last_name'   => [30, 'user_last_name'],
	'sus_code'        => [50, 'user_code'],
	'sus_email'       => [255, 'user_email'],
	'sus_position'    => [50, 'user_position'],
	'sus_department'  => [50, 'user_department'],
	'sus_team'        => [50, 'user_team'],
	'sus_additional1' => [100, 'user_additional1'],
	'sus_additional2' => [100, 'user_additional2'],
	'sus_language'    => [20, 'user_language'],
];

$isNew = !$existing;
if ($isNew) NuAcl::require(NuAcl::FORM_USER, $cloneId !== '' ? 'clone' : 'create');
$canSave = $isNew ? true : $rights['save'];
$mailOk  = NuMail::configured();          // "Send welcome e-mail" only with working e-mail settings
$welcome = false;

// ------------------------------------------------------------------ initial values
$source = $existing;
if ($isNew && $cloneId !== '') {
	$source = $db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$cloneId]);
	if (!$source) nu_redirect(nu_url('users'));
	$source['sus_login_name'] = '';
	$source['sus_email']      = '';
	$source['sus_code']       = '';
}
$v = [];
foreach ($text as $col => $_) $v[$col] = (string)($source[$col] ?? '');
$v['sus_zzzzsys_access_id']      = (string)($source['sus_zzzzsys_access_id'] ?? '');
$v['sus_expires_on']             = nu_date_only($source['sus_expires_on'] ?? '');
$v['sus_change_password']        = $source ? ((string)$source['sus_change_password'] === '1' ? '1' : '0') : '1';
$v['sus_accessibility_features'] = (string)($source['sus_accessibility_features'] ?? '') === '1' ? '1' : '0';
if ($isNew && $cloneId !== '') $v['sus_change_password'] = '1';
// English variant (0.8.314): sus_language stays "English" (nuBuilder), the variant is kept in sus_json NUNEXT_LANG_VARIANT
$langVariant = $source ? (string)NuUserJson::get((string)$source['zzzzsys_user_id'], 'NUNEXT_LANG_VARIANT') : '';
if (!isset(NU_LANG_VARIANTS[$langVariant])) $langVariant = '';
$langVariantOld = $isNew ? '' : $langVariant;
// admin role (0.8.323): chosen here by the globeadmin or a user with "Admin roles" – never for the own record (core/nuadmin.php)
$admRoleMay = NuAdmin::canManage() && !($existing && $existing['zzzzsys_user_id'] === $me['id']);
$admRoleCur = $existing ? (string)(NuAdmin::roleOfUser((string)$existing['zzzzsys_user_id'])['id'] ?? '') : '';
$admRoleSel = $admRoleCur;

// additional access levels (0.8.585, core/nuuseraccess.php): [access id, valid until] – a clone takes them along
$xaOld = array_map(static fn($r) => [$r['access_id'], $r['valid_until']], NuUserAccess::rowsOf($existing ? $id : $cloneId));
$xaSel = $xaOld;

// permission items (nuBuilder subform sf_permission → zzzzsys_user_permission)
$permItems = NuPermission::items();
$permOld   = array_keys(array_filter(NuPermission::forUser($existing ? $id : $cloneId), static fn($a) => $a === '1'));
$permSel   = array_map('strval', $permOld);
// "Copy from user…" (0.8.649): the other users with at least one active permission item, with their number of items
$permFrom  = $permItems ? $db->all('SELECT u.zzzzsys_user_id AS id, u.sus_name AS name, u.sus_login_name AS login, COUNT(DISTINCT i.zzzzsys_permission_item_id) AS n'
	. ' FROM zzzzsys_user u JOIN ' . NuPermission::USER . ' p ON ' . $db->sameText('p.upe_zzzzsys_user_id', 'u.zzzzsys_user_id')
	. ' JOIN ' . NuPermission::ITEM . ' i ON ' . $db->sameText('i.zzzzsys_permission_item_id', 'p.upe_ident')
	. " WHERE p.upe_active = '1' AND u.zzzzsys_user_id <> ? GROUP BY u.zzzzsys_user_id, u.sus_name, u.sus_login_name ORDER BY u.sus_name, u.sus_login_name", [$id]) : [];

// custom user fields (0.8.454, core/nuuserfields.php): tab More; a clone takes the values along except unique ones, a new user the defaults
$ufAll = NuUserFields::all();
$ufEdit = NuUserFields::editable('admin');
$ufVals = $existing ? NuUserFields::values($id) : ($cloneId !== '' ? array_map(static fn($f, $x) => (string)$f['is_unique'] === '1' ? '' : $x, $ufAll, NuUserFields::values($cloneId)) : NuUserFields::defaults());
if ($ufAll) $ufVals = array_combine(array_keys($ufAll), array_values($ufVals));
$ufErr = [];

$errors = [];
$min    = NuPwPolicy::minLength();

// ------------------------------------------------------------------ save
if (nu_is_post() && nu_post('action') === 'save') {
	if (!$canSave) NuAcl::require(NuAcl::FORM_USER, 'save');
	$guardAdminUser();

	foreach ($text as $col => [$max]) {
		$v[$col] = nu_post($col);
		if ($col === 'sus_language') {                       // "English|en-US" = language + variant
			[$v[$col], $lv] = array_pad(explode('|', $v[$col], 2), 2, '');
			$langVariant = isset(NU_LANG_VARIANTS[$lv]) && nu_lang_from_db($v[$col]) === NU_LANG_VARIANTS[$lv][0] ? $lv : '';
		}
		nu_maxlen($errors, $col, $v[$col], $max);
	}
	$v['sus_zzzzsys_access_id']      = nu_post('sus_zzzzsys_access_id');
	if ($admRoleMay && isset($_POST['admin_role'])) $admRoleSel = nu_post('admin_role');
	$v['sus_expires_on']             = nu_post('sus_expires_on');
	$v['sus_change_password']        = nu_check('sus_change_password');
	$welcome = $mailOk && nu_check('user_send_welcome_email') === '1';
	if (NU_FEATURE_ACCESSIBILITY) $v['sus_accessibility_features'] = nu_check('sus_accessibility_features');   // hidden: the stored value stays
	if (isset($_POST['perm_sent'])) $permSel = array_map('strval', is_array($_POST['perm'] ?? null) ? $_POST['perm'] : []);
	if (isset($_POST['xa_sent'])) {
		$xaSel = [];
		$xaA = is_array($_POST['xa_acc'] ?? null) ? $_POST['xa_acc'] : [];
		$xaU = is_array($_POST['xa_until'] ?? null) ? $_POST['xa_until'] : [];
		foreach ($xaA as $i => $a) if (is_string($a) && trim($a) !== '') $xaSel[] = [trim($a), is_string($xaU[$i] ?? null) ? trim($xaU[$i]) : ''];
	}
	$pw      = (string)($_POST['password'] ?? '');
	$confirm = (string)($_POST['password_confirm'] ?? '');

	// login name: required, unique, not the globeadmin name
	if ($v['sus_login_name'] === '') {
		$errors['sus_login_name'] = t('err_required');
	} elseif (strcasecmp($v['sus_login_name'], (string)nu_cfg('nuConfigDBGlobeadminUsername')) === 0) {
		$errors['sus_login_name'] = t('user_err_globeadmin_name');
	} elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name = ? AND zzzzsys_user_id <> ?', [$v['sus_login_name'], $id])) {
		$errors['sus_login_name'] = t('user_err_login_taken');
	}

	// code (sus_code): unique when set (0.8.626)
	if (($owner = NuAuth::userCodeOwner((string)($v['sus_code'] ?? ''), $id)) !== null) $errors['sus_code'] = t('user_err_code_taken', $owner['name']);

	// full name (sus_name): required
	if (trim($v['sus_name']) === '' && !isset($errors['sus_name'])) $errors['sus_name'] = t('err_required');

	if ($v['sus_zzzzsys_access_id'] === '') {
		$errors['sus_zzzzsys_access_id'] = t('err_required');
	} elseif (!(int)$db->value('SELECT COUNT(*) FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$v['sus_zzzzsys_access_id']])) {
		$errors['sus_zzzzsys_access_id'] = t('err_invalid');
	} elseif (NuSecurity::restricted()) {
		// nobody changes his own access level; admin-equivalent levels are given by the globeadmin only (Admin tools → Security check)
		$oldLevel = (string)($existing['sus_zzzzsys_access_id'] ?? '');
		if ($existing && $existing['zzzzsys_user_id'] === $me['id'] && $v['sus_zzzzsys_access_id'] !== $oldLevel) {
			$errors['sus_zzzzsys_access_id'] = t('secchk_err_own_level');
		} elseif ($v['sus_zzzzsys_access_id'] !== $oldLevel && NuSecurity::adminRightsOf($v['sus_zzzzsys_access_id'])) {
			$errors['sus_zzzzsys_access_id'] = t('secchk_err_admin_level');
		}
	}

	// additional access levels (0.8.585): existing levels, valid dates; restricted admins: not for the own record, no admin-equivalent new ones
	$xaOldIds = array_column($xaOld, 0);
	foreach ($xaSel as [$xa, $xu]) {
		if (!(int)$db->value('SELECT COUNT(*) FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$xa])) { $errors['xa'] = t('err_invalid'); break; }
		if ($xu !== '' && !nu_valid_date($xu)) { $errors['xa'] = t('xa_err_date'); break; }
		if (NuSecurity::restricted() && !in_array($xa, $xaOldIds, true) && NuSecurity::adminRightsOf($xa)) { $errors['xa'] = t('secchk_err_admin_level'); break; }
	}
	if (!isset($errors['xa']) && NuSecurity::restricted() && $existing && $existing['zzzzsys_user_id'] === $me['id'] && $xaSel != $xaOld) $errors['xa'] = t('secchk_err_own_level');

	if ($v['sus_email'] !== '' && !isset($errors['sus_email']) && !filter_var($v['sus_email'], FILTER_VALIDATE_EMAIL)) {
		$errors['sus_email'] = t('user_err_email');
	}
	if ($welcome && $v['sus_email'] === '' && !isset($errors['sus_email'])) $errors['sus_email'] = t('welcome_err_email');
	if ($v['sus_expires_on'] !== '' && !nu_valid_date($v['sus_expires_on'])) {
		$errors['sus_expires_on'] = t('err_invalid');
	}

	if ($isNew && $pw === '' && !$welcome) {                // with the welcome e-mail the user sets the password via the link
		$errors['password'] = t('err_required');
	} elseif ($pw !== '') {
		if (mb_strlen($pw) < $min)  $errors['password'] = t('pw_err_min', $min);
		elseif (strlen($pw) > 72)   $errors['password'] = t('err_too_long', 72);
		elseif ($pw !== $confirm)   $errors['password_confirm'] = t('pw_err_confirm');
		elseif (($policy = NuAuth::passwordPolicy($pw, $isNew ? '' : (string)$id, ['login' => (string)($v['sus_login_name'] ?? ''), 'name' => trim((string)($v['sus_first_name'] ?? '') . ' ' . (string)($v['sus_last_name'] ?? '') . ' ' . (string)($v['sus_name'] ?? '')), 'email' => (string)($v['sus_email'] ?? '')])) !== null) $errors['password'] = $policy;   // nu_check_password_policy (0.8.462)
	}

	// custom fields (0.8.454): only the ones this admin may change; a new user's unique check against all others
	$ufPost = is_array($_POST['uf'] ?? null) ? $_POST['uf'] : [];
	if ($ufEdit && $ufPost) {
		[$ufNew, $ufErr] = NuUserFields::validate($ufPost, $isNew ? '' : $id, 'admin');
		$ufVals = array_merge($ufVals, array_map(static fn($x) => is_scalar($x) ? (string)$x : '', array_intersect_key($ufPost, $ufEdit)));
		foreach ($ufErr as $code => $msg) $errors['uf_' . $code] = $msg;
	} else {
		$ufNew = [];
	}
	// welcome e-mail edited in the preview (0.8.684): it must keep the set-password link when it had one
	$wEdit = $welcome && nu_post('welcome_edit') === '1' && !NuWelcome::hasProcedure();
	$wHtml = $wEdit ? str_replace("\r\n", "\n", (string)($_POST['welcome_html'] ?? '')) : '';
	if ($wEdit && nu_post('welcome_link') === '1' && !str_contains($wHtml . nu_post('welcome_subject'), NuWelcome::MARKER)) $errors['welcome'] = t('welcome_pv_err_link', NuWelcome::MARKER);
	if ($isNew) foreach ($ufAll as $code => $f) if (!isset($ufNew[$code]) && !isset($ufErr[$code]) && !isset($ufEdit[$code]) && ($ufVals[$code] ?? '') !== '') $ufNew[$code] = $ufVals[$code];   // defaults of fields this admin may not edit

	// preview of the welcome e-mail (0.8.684, JSON for assets/js/nuuser.js): every check above ran, nothing is written
	if (nu_post('welcome_check') === '1') {
		header('Content-Type: application/json; charset=utf-8');
		if ($errors || !$welcome || NuWelcome::hasProcedure()) exit((string)json_encode(['ok' => false, 'errors' => count($errors)]));
		$pv = NuWelcome::preview($v + ['zzzzsys_user_id' => $isNew ? '' : $id, 'NUNEXT_LANG_VARIANT' => $langVariant], $pw, nu_post('welcome_choice') ?: 'auto');
		$key = bin2hex(random_bytes(8));
		$_SESSION['nu_welcome_pv'] = array_slice(($_SESSION['nu_welcome_pv'] ?? []) + [$key => $pv['html']], -5, null, true);
		exit((string)json_encode(['ok' => true, 'frame' => nu_url('user', ['welcome_frame' => $key]), 'choices' => NuWelcome::choices()]
			+ array_diff_key($pv, ['html' => 1]), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}

	if (!$errors) {
		$row = [];
		foreach ($text as $col => $_) $row[$col] = $v[$col] === '' ? null : $v[$col];
		$row['sus_login_name']             = $v['sus_login_name'];
		$row['sus_zzzzsys_access_id']      = $v['sus_zzzzsys_access_id'];
		$row['sus_expires_on']             = $v['sus_expires_on'] === '' ? null : $db->datetime($v['sus_expires_on']);
		$row['sus_change_password']        = $v['sus_change_password'];
		$row['sus_accessibility_features'] = $v['sus_accessibility_features'];
		$newHash = $pw !== '' ? NuAuth::hash($pw) : null;
		if (!$newHash && $isNew) $newHash = NuAuth::hash(bin2hex(random_bytes(24)));   // welcome e-mail without password: unknown random one
		if ($newHash) $row['sus_login_password'] = $newHash;

		if ($isNew) {
			$id  = nu_id();
			$row = ['zzzzsys_user_id' => $id] + $row;
			if ($source) {                                     // clone: carry over the fields this screen does not show
				$row['sus_permission'] = $source['sus_permission'];
				$row['sus_json']       = NuUserJson::forClone($source['sus_json'] ?? null);   // without login token, 2FA and password data
			}
			$db->insert('zzzzsys_user', $row);
			if ($langVariant !== '' || $cloneId !== '') NuUserJson::set($id, 'NUNEXT_LANG_VARIANT', $langVariant);
			if ($pw !== '') NuUserJson::passwordChanged($id, 'admin');   // not for the random one of a welcome e-mail
			$ufDiff = NuUserFields::save($id, $ufNew);
			foreach (NuUserAccess::save($id, $v['sus_zzzzsys_access_id'], $xaSel) as $xc => $xd) $ufDiff['access_extra:' . $xc] = $xd;   // 0.8.585
			NuLog::write('user_created', 'zzzzsys_user', $id, ['fields' => NuLog::diff([], array_diff_key($row, ['sus_login_password' => 1])) + $ufDiff]);
			NuUserJson::stamp($id, true, 'form');
			if ($permItems) NuPermission::setUser($id, $permSel);
			$pa = array_values(array_intersect_key(array_column($permItems, 'pme_ident', 'zzzzsys_permission_item_id'), array_flip($permSel)));
			if ($pa) NuLog::write('user_permissions', 'zzzzsys_user', $id, ['login' => $v['sus_login_name'], 'added' => $pa, 'removed' => []]);
			nu_flash('success', t('user_created', $v['sus_login_name']));
			if ($admRoleMay && $admRoleSel !== '' && ($ae = NuAdmin::assign($id, $admRoleSel)) !== '') nu_flash('warning', t('adm_role_field') . ': ' . $ae);
		} else {
			$db->update('zzzzsys_user', $row, 'zzzzsys_user_id', $id);   // sus_permission / sus_json stay untouched
			if ($newHash) NuUserJson::passwordChanged($id, $id === $me['id'] ? 'user' : 'admin');
			$changes = NuLog::diff($existing, array_diff_key($row, ['sus_login_password' => 1]));
			if ($langVariant !== $langVariantOld && NuUserJson::set($id, 'NUNEXT_LANG_VARIANT', $langVariant)) $changes['language_variant'] = [$langVariantOld, $langVariant];
			if ($newHash) $changes['sus_login_password'] = ['…', '(' . t('log_new_password') . ')'];
			$changes += NuUserFields::save($id, $ufNew);            // custom fields (0.8.454): "field:<code>" in the log
			if (isset($_POST['xa_sent']) || $v['sus_zzzzsys_access_id'] !== (string)$existing['sus_zzzzsys_access_id']) {   // additional levels (0.8.585)
				foreach (NuUserAccess::save($id, $v['sus_zzzzsys_access_id'], $xaSel) as $xc => $xd) $changes['access_extra:' . $xc] = $xd;
			}
			if ($changes) NuLog::write('user_updated', 'zzzzsys_user', $id, ['fields' => $changes]);
			$permChanged = false;
			if ($permItems && isset($_POST['perm_sent'])) {
				[$pa, $pr] = NuPermission::setUser($id, $permSel);
				if ($pa || $pr) NuLog::write('user_permissions', 'zzzzsys_user', $id, ['login' => $v['sus_login_name'], 'added' => $pa, 'removed' => $pr]);
				$permChanged = (bool)($pa || $pr);
			}
			if ($changes || $permChanged) NuUserJson::stamp($id, false, 'form');   // created / last changed stamps (0.8.759)
			if ($newHash && $id === $me['id']) NuAuth::refreshFingerprint($newHash);
			if ($admRoleMay && $admRoleSel !== $admRoleCur && ($ae = NuAdmin::assign($id, $admRoleSel)) !== '') nu_flash('warning', t('adm_role_field') . ': ' . $ae);
			nu_flash('success', t('saved'));
		}
		if ($welcome) {                                        // after the save, like nuBuilder's After Save
			$saved = $db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$id]);
			if ($saved && $wEdit) {                            // the e-mail edited in the preview; cc / bcc of the chosen template, as previewed
				$pvRec = NuWelcome::preview($saved, $pw, nu_post('welcome_choice') ?: 'auto');
				$err = NuWelcome::sendEdited($saved, nu_post('welcome_subject'), $wHtml, $pvRec['cc'], $pvRec['bcc'], $pvRec['source']);
			} else {
				$err = $saved ? NuWelcome::send($saved, $pw) : t('err_not_found');
			}
			nu_flash($err === null ? 'success' : 'danger', $err === null ? t('welcome_sent', (string)$saved['sus_email']) : t('welcome_failed') . ' ' . $err);
		}
		nu_redirect(nu_url('user', ['id' => $id, 'tab' => nu_post('tab') === 'perm' ? 'perm' : null]));   // stays on the tab it was saved from
	}
	nu_flash('danger', t('err_check_form'));
}

// ------------------------------------------------------------------ view data
$accessLevels = $db->all('SELECT zzzzsys_access_id, sal_code, sal_description, sal_group FROM zzzzsys_access ORDER BY sal_group, sal_code');
// Position / Department / Team: HTML5 datalist with the values of the OTHER, ACTIVE users (Valid until empty, a zero date or today
// or later – archived / expired users are left out), trimmed, without duplicates (ignoring case), sorted alphabetically
$suggest = [];
$active  = ' AND zzzzsys_user_id <> ? AND (sus_expires_on IS NULL OR sus_expires_on >= ? OR sus_expires_on < ?)';
foreach (['sus_position', 'sus_department', 'sus_team'] as $col) {
	$vals = [];
	foreach ($db->all("SELECT DISTINCT $col AS v FROM zzzzsys_user WHERE $col IS NOT NULL AND $col <> ''" . $active,
		[$id, $db->datetime(date('Y-m-d')), $db->datetime('1900-01-02')]) as $r) {
		$val = trim((string)$r['v']);
		if ($val !== '') $vals[mb_strtolower($val)] ??= $val;
	}
	usort($vals, static fn($a, $b) => strnatcasecmp($a, $b));
	$suggest[$col] = $vals;
}
$langOptions = ['' => t('user_language_default')];
foreach (NU_LANGS as $code => [$own, $dbName]) {
	if (!NuLanguages::has($code)) continue;                   // installed languages only (0.8.701); the user's own value stays below
	$langOptions[$dbName] = $own;
	foreach (NU_LANG_VARIANTS as $vc => [$base, $vName]) if ($base === $code) $langOptions[$dbName . '|' . $vc] = $vName;
}
if ($v['sus_language'] !== '' && !isset($langOptions[$v['sus_language']])) $langOptions[$v['sus_language']] = $v['sus_language'];
$langSelected = $v['sus_language'] . ($langVariant !== '' && isset($langOptions[$v['sus_language'] . '|' . $langVariant]) ? '|' . $langVariant : '');

$title = $isNew ? ($cloneId !== '' ? t('user_clone') : t('user_new')) : ($existing['sus_login_name'] ?: t('user'));
$ro    = $canSave ? '' : ' disabled';

$input = static function (string $col, string $type = 'text', array $attr = []) use ($v, $errors, $text, $suggest): string {
	$max  = $text[$col][0] ?? null;
	$uni = !empty($attr['unique']);                                   // key after the label: unique (0.8.627) – not an HTML attribute
	unset($attr['unique']);
	$html = '<label class="form-label" for="' . $col . '">' . h(t($text[$col][1])) . (!empty($attr['required']) ? ' <span class="text-danger">*</span>' : '') . ($uni ? nu_unique_mark(!empty($attr['required'])) : '') . '</label>'
		. '<input type="' . $type . '" class="form-control' . nu_invalid($errors, $col) . '" id="' . $col . '" name="' . $col . '" value="' . h($v[$col]) . '"'
		. ($max ? ' maxlength="' . $max . '"' : '')
		. (isset($suggest[$col]) ? ' list="dl_' . $col . '"' : '');
	foreach ($attr as $k => $val) $html .= ' ' . $k . ($val === true ? '' : '="' . h($val) . '"');
	$html .= '>' . nu_feedback($errors, $col);
	if (isset($suggest[$col])) {
		$html .= '<datalist id="dl_' . $col . '">';
		foreach ($suggest[$col] as $s) $html .= '<option value="' . h($s) . '">';
		$html .= '</datalist>';
	}
	return $html;
};

nu_layout_start($title, 'users', ['head' => '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">']);   // access level: searchable (0.8.327)
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('users')) ?>"><?= h(t('nav_users')) ?></a></li>
		<li class="breadcrumb-item active" aria-current="page"><?= h($title) ?></li>
	</ol>
</nav>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0 d-flex align-items-center gap-2">
		<?php $impIcon = $isNew ? '' : NuImpersonate::button($id, (string)$existing['sus_login_name'], nu_url('user', ['id' => $id]), false, true); // globeadmin: "Act as user" as the icon ?>
		<?= $impIcon !== '' ? $impIcon : '<i class="bi ' . ($isNew ? 'bi-person-plus' : 'bi-person') . '"></i>' ?><?= h($title) ?>
		<?php if (!$isNew && nu_is_expired($existing['sus_expires_on'])): ?><span class="badge text-bg-danger fs-6"><?= h(t('user_expired')) ?></span><?php endif; ?>
		<?php if (!$isNew && NuDemo::isDemoUser($id)): ?><span class="badge nuDemoBadge fs-6" title="<?= h(t('demo_user_hint')) ?>"><i class="bi bi-eye me-1"></i><?= h(t('demo_user_badge')) ?></span><?php endif; ?>
	</h1>
	<div class="d-flex flex-wrap gap-2 align-items-center">
		<a class="btn nuBtnAct" href="<?= h(nu_url('users')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if ($canSave): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuRecordForm"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<?php if (!$isNew): ?>
			<?php if ($rights['save'] && (NuTwoFA::hasTotp($id) || NuTwoFA::deviceCount($id) > 0)): ?>
				<form method="post" action="<?= h(nu_url('user', ['id' => $id])) ?>" data-confirm="<?= h(t('user_2fa_reset_confirm')) ?>" data-confirm-ok="<?= h(t('user_2fa_reset')) ?>" data-confirm-class="btn btn-warning">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="reset2fa">
					<button class="btn btn-outline-warning" type="submit"><i class="bi bi-shield-x me-1"></i><?= h(t('user_2fa_reset')) ?></button>
				</form>
			<?php endif; ?>
			<?php if ($rights['clone']): ?>
				<a class="btn nuBtnAct" href="<?= h(nu_url('user', ['clone' => $id])) ?>"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></a>
			<?php endif; ?>
			<?php if (($rights['delete'] || $rights['save']) && $id !== $me['id']): ?>
				<button class="btn nuBtnAct nuBtnDel" type="button" data-bs-toggle="modal" data-bs-target="#nuUserRemove"
					data-choice-action="<?= h(nu_url('user', ['id' => $id])) ?>"
					data-choice-name="<?= h(trim(($existing['sus_first_name'] ?? '') . ' ' . ($existing['sus_last_name'] ?? '')) ?: ($existing['sus_name'] ?: $existing['sus_login_name'])) ?>"
					data-choice-archived="<?= nu_is_expired($existing['sus_expires_on']) ? '1' : '0' ?>"
					title="<?= h(t('user_remove')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
			<?php $pwInfo = '';                                  // password last changed (nuBuilder: sus_json PASSWORD_CHANGED_TIME / _SOURCE) – in the (i) panel (0.8.587)
			if (($pwTime = (int)NuUserJson::get((string)$id, 'PASSWORD_CHANGED_TIME')) > 0) {
				$pwSrc = (string)NuUserJson::get((string)$id, 'PASSWORD_CHANGED_SOURCE');
				$pwInfo = date(nu_dfmt('d.m.Y H:i'), $pwTime) . (in_array($pwSrc, ['user', 'admin', 'reset'], true) ? ' (' . t('user_pw_src_' . $pwSrc) . ')' : '');
			} ?>
			<?php // (i) links to the activity of this user (0.8.687): its log entries, the log about this record, its sessions
			$riLinks = [];
			if (NuAcl::can(NuAcl::FORM_LOG)) {
				$riLinks[] = ['bi-journal-text', t('user_ri_log'), nu_url('log', ['login' => (string)$existing['sus_login_name']])];
				$riLinks[] = ['bi-clock-history', t('user_ri_changes'), nu_url('log', ['q' => (string)$id])];
			}
			if (NuAdmin::can('sessions')) $riLinks[] = ['bi-people', t('user_ri_sessions'), nu_url('sessions', ['user' => (string)$id])]; ?>
			<?= NuFormInfo::record('zzzzsys_user', (string)$id, ['login' => (string)$existing['sus_login_name']], 'nuRecInfo', $pwInfo !== '' ? ['pw_changed' => $pwInfo] : [], $riLinks) /* (i): record ID, table, created / changed (0.8.500) */ ?>
		<?php endif; ?>
	</div>
</div>

<?php if (!$canSave): ?>
	<div class="alert alert-info d-flex gap-2"><i class="bi bi-eye mt-1"></i><div><?= h(t('read_only')) ?></div></div>
<?php endif; ?>

<form method="post" data-nu-focus-first data-nu-guard id="nuRecordForm" action="<?= h(nu_url('user', $isNew ? ($cloneId !== '' ? ['clone' => $cloneId] : []) : ['id' => $id])) ?>"<?= $isNew && $cloneId !== '' ? ' data-nu-clone="' . h(t('form_clone_badge')) . '"' : '' ?> data-nu-new="<?= $isNew ? '1' : '0' ?>" novalidate>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<fieldset<?= $ro ?>>
	<?php // tabs General / Permission items (0.8.438): one form, one Save; ?tab=perm opens the second tab (also after saving there)
	$userTab = (string)($_GET['tab'] ?? '');
	if ($errors) $userTab = '';                                   // every field with an error is on General (custom fields too since 0.8.591)
	if ($userTab !== 'perm') $userTab = '';                      // ?tab=more (custom fields tab until 0.8.590) opens General
	$permTab = $userTab === 'perm';
	$permCount = count(array_intersect($permSel, array_column($permItems, 'zzzzsys_permission_item_id'))); ?>
	<input type="hidden" name="tab" id="userTab" value="<?= h($userTab) ?>">
	<ul class="nav nav-tabs mb-3" id="userTabs" role="tablist">
		<li class="nav-item" role="presentation"><button class="nav-link<?= $userTab === '' ? ' active' : '' ?>" id="userTabGeneralBtn" data-bs-toggle="tab" data-bs-target="#userTabGeneral" data-nu-tab="" type="button" role="tab" aria-controls="userTabGeneral" aria-selected="<?= $userTab === '' ? 'true' : 'false' ?>"><i class="bi bi-person me-1"></i><?= h(t('user_tab_general')) ?></button></li>
		<li class="nav-item" role="presentation"><button class="nav-link<?= $permTab ? ' active' : '' ?>" id="userTabPermBtn" data-bs-toggle="tab" data-bs-target="#userTabPerm" data-nu-tab="perm" type="button" role="tab" aria-controls="userTabPerm" aria-selected="<?= $permTab ? 'true' : 'false' ?>"><i class="bi bi-key me-1"></i><?= h(t('perm_title')) ?> <span class="badge rounded-pill text-bg-secondary ms-1" id="userPermTabCount"><?= $permCount ?></span></button></li>
	</ul>
	<div class="tab-content">
	<div class="tab-pane fade<?= $userTab === '' ? ' show active' : '' ?>" id="userTabGeneral" role="tabpanel" aria-labelledby="userTabGeneralBtn" tabindex="0">
	<div class="row g-3">
		<div class="col-lg-6 d-flex flex-column gap-3">   <?php // Person + custom fields (0.8.591: card instead of the tab Additional fields) ?>
			<div class="card shadow-sm">
				<div class="card-header fw-semibold"><i class="bi bi-person-vcard me-2"></i><?= h(t('user_sec_person')) ?></div>
				<div class="card-body">
					<div class="row g-3">
						<div class="col-12"><?= $input('sus_name', 'text', ['required' => true]) ?></div>
						<div class="col-sm-6"><?= $input('sus_first_name', 'text', ['autocomplete' => 'off']) ?></div>
						<div class="col-sm-6"><?= $input('sus_last_name', 'text', ['autocomplete' => 'off']) ?></div>
						<div class="col-sm-8"><?= $input('sus_email', 'email', ['autocomplete' => 'off', 'data-nu-email-msg' => t('user_err_email')]) ?></div>
						<div class="col-sm-4"><?= $input('sus_code', 'text', ['unique' => true]) ?></div>
						<div class="col-sm-4"><?= $input('sus_position') ?></div>
						<div class="col-sm-4"><?= $input('sus_department') ?></div>
						<div class="col-sm-4"><?= $input('sus_team') ?></div>
						<div class="col-sm-6"><?= $input('sus_additional1') ?></div>
						<div class="col-sm-6"><?= $input('sus_additional2') ?></div>
					</div>
				</div>
			</div>
			<?php if ($ufAll): // custom user fields (0.8.454) – own card below Person (0.8.591) ?>
			<div class="card shadow-sm" id="userTabMore">
				<div class="card-header d-flex align-items-center gap-2"><span class="fw-semibold me-auto"><i class="bi bi-ui-checks me-2"></i><?= h(t('user_tab_more')) ?></span>
					<?php if (NuAdmin::can('userfields')): ?><a class="small" href="<?= h(nu_url('userfields')) ?>" id="userFieldsManage"><i class="bi bi-gear me-1"></i><?= h(t('uf_manage')) ?></a><?php endif; ?></div>
				<div class="card-body"><div class="row g-3">
					<?php foreach ($ufAll as $code => $f) echo NuUserFields::inputHtml($f, (string)($ufVals[$code] ?? ''), $ufErr[$code] ?? null, !isset($ufEdit[$code]), true); ?>
				</div></div>
			</div>
			<?php endif; ?>
		</div>

		<div class="col-lg-6 d-flex flex-column gap-3">   <?php // three cards (0.8.586): login credentials · access & roles · account settings ?>
			<div class="card shadow-sm userCardLogin">
				<div class="card-header fw-semibold"><i class="bi bi-key me-2"></i><?= h(t('user_sec_credentials')) ?></div>
				<div class="card-body">
					<div class="mb-3"><?= $input('sus_login_name', 'text', ['required' => true, 'unique' => true, 'autocomplete' => 'off', 'spellcheck' => 'false']) ?></div>

					<div class="row g-3 mb-3">
						<div class="col-sm-6">
							<label class="form-label" for="password"><?= h(t('login_password')) ?><?= $isNew ? ' <span class="text-danger">*</span>' : '' ?></label>
							<div class="input-group<?= isset($errors['password']) ? ' has-validation' : '' ?>">
								<input type="password" class="form-control<?= nu_invalid($errors, 'password') ?>" id="password" name="password" autocomplete="new-password"<?= $isNew ? '' : ' placeholder="' . h(t('user_pw_unchanged')) . '" title="' . h(t('user_pw_keep')) . '"' ?>>
								<button class="btn btn-outline-secondary" type="button" data-pw-toggle="#password" aria-label="<?= h(t('show_password')) ?>"><i class="bi bi-eye"></i></button>
								<?php if ($canSave): ?><button class="btn btn-outline-secondary nuPwGenBtn" type="button" data-bs-toggle="modal" data-bs-target="#nuPwGen" title="<?= h(t('pwg_title')) ?>" aria-label="<?= h(t('pwg_title')) ?>"><i class="bi bi-magic"></i></button><?php endif; ?>
								<?= nu_feedback($errors, 'password') ?>
							</div>
							<?php // existing user (0.8.587): placeholder "(unchanged)" instead of the hint below; "Password last changed" is in the (i) panel ?>
							<div class="form-text<?= $isNew ? '' : ' d-none' ?>" id="passwordHint"><?= h($isNew ? t('pw_min_hint', $min) : '') ?></div>
							<?= NuPwPolicy::rulesHtml('password') ?>
							<?php if (!$isNew && $me['globeadmin'] && empty($me['demo']) && nu_cfg('nuConfigAutoLogin') && (string)($existing['sus_login_password'] ?? '') !== ''): // never for demo visitors: it logs in as that user
								$alLinks = [];
								foreach ([7, 1, 30, 90, 0] as $d) $alLinks[$d] = NuAuth::autoLoginLink($existing, $d); ?>
								<details class="mt-2" id="autoLoginBox"><summary class="small"><i class="bi bi-link-45deg me-1"></i><?= h(t('user_autologin')) ?></summary>
									<div class="input-group input-group-sm mt-1">
										<select class="form-select flex-grow-0 w-auto" id="autoLoginDays" aria-label="<?= h(t('user_autologin_valid')) ?>" title="<?= h(t('user_autologin_valid')) ?>">
											<?php foreach ($alLinks as $d => $link): ?><option value="<?= $d ?>" data-link="<?= h($link) ?>"><?= h($d === 0 ? t('user_autologin_forever') : ($d === 1 ? t('user_autologin_day') : t('user_autologin_days', $d))) ?></option><?php endforeach; ?>
										</select>
										<input class="form-control font-monospace" id="autoLoginUrl" readonly value="<?= h($alLinks[7]) ?>" aria-label="<?= h(t('user_autologin')) ?>">
										<button class="btn btn-outline-secondary" type="button" id="autoLoginCopy" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
										<button class="btn btn-outline-danger" type="submit" form="autoLoginRevokeForm" title="<?= h(t('user_autologin_revoke_hint')) ?>"><i class="bi bi-x-octagon me-1"></i><?= h(t('user_autologin_revoke')) ?></button>
									</div>
									<div class="form-text"><?= h(t('user_autologin_hint')) ?></div>
								</details>
							<?php endif; ?>
							<?php if (!$isNew && $me['globeadmin'] && empty($me['demo']) && NuLoginToken::enabled() && !(class_exists('NuApiClient') && NuApiClient::isServiceUser($id))):
								$ltState = NuLoginToken::state($id);
								$ltNew = ($_SESSION['nu_ltok_link']['id'] ?? '') === $id ? (string)$_SESSION['nu_ltok_link']['link'] : '';
								unset($_SESSION['nu_ltok_link']); ?>
								<details class="mt-2" id="loginTokenBox" data-nu-noguard<?= $ltNew !== '' ? ' open' : '' ?>><summary class="small"><i class="bi bi-key me-1"></i><?= h(t('ltok_title')) ?>
									<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('ltok_tip')) ?>" title="<?= h(t('ltok_tip')) ?>"><i class="bi bi-info-circle"></i></span></summary>
									<div class="small text-body-secondary mt-1" id="loginTokenState"><?= h($ltState === null ? t('ltok_state_none') : ($ltState === 0 ? t('ltok_state_open') : ($ltState < time() ? t('ltok_state_expired', date(nu_dfmt('d.m.Y H:i'), $ltState)) : t('ltok_state_until', date(nu_dfmt('d.m.Y H:i'), $ltState))))) ?></div>
									<?php if ($ltNew !== ''): ?>
										<div class="input-group input-group-sm mt-1">
											<input class="form-control font-monospace" id="loginTokenUrl" readonly value="<?= h($ltNew) ?>" aria-label="<?= h(t('ltok_title')) ?>">
											<button class="btn btn-outline-secondary" type="button" id="loginTokenCopy" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
										</div>
										<div class="form-text"><?= h(t('ltok_shown_once')) ?></div>
									<?php endif; ?>
									<div class="input-group input-group-sm mt-2">
										<select class="form-select flex-grow-0 w-auto" name="minutes" form="loginTokenForm" aria-label="<?= h(t('user_autologin_valid')) ?>" title="<?= h(t('user_autologin_valid')) ?>">
											<?php foreach (NuLoginToken::CHOICES as $m): ?><option value="<?= $m ?>"<?= $m === 1440 ? ' selected' : '' ?>><?= h($m === 0 ? t('user_autologin_forever') : NuLoginToken::duration($m)) ?></option><?php endforeach; ?>
										</select>
										<button class="btn btn-outline-secondary" type="submit" form="loginTokenForm" name="action" value="logintoken_create"><i class="bi bi-plus-lg me-1"></i><?= h(t('ltok_create')) ?></button>
										<?php if (trim((string)($existing['sus_email'] ?? '')) !== ''): ?>
											<button class="btn btn-outline-secondary" type="submit" form="loginTokenForm" name="action" value="logintoken_mail" title="<?= h(t('ltok_create_mail') . ': ' . (string)$existing['sus_email']) ?>" aria-label="<?= h(t('ltok_create_mail')) ?>"><i class="bi bi-envelope"></i></button>
										<?php endif; ?>
										<?php if ($ltState !== null): ?>
											<button class="btn btn-outline-danger" type="submit" form="loginTokenClearForm" title="<?= h(t('ltok_clear')) ?>" aria-label="<?= h(t('ltok_clear')) ?>"><i class="bi bi-x-octagon"></i></button>
										<?php endif; ?>
									</div>
								</details>
							<?php endif; ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="password_confirm"><?= h(t('pw_confirm')) ?></label>
							<div class="input-group<?= isset($errors['password_confirm']) ? ' has-validation' : '' ?>">
								<input type="password" class="form-control<?= nu_invalid($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm" autocomplete="new-password"<?= $isNew ? '' : ' placeholder="' . h(t('user_pw_unchanged')) . '"' ?>>
								<button class="btn btn-outline-secondary" type="button" data-pw-toggle="#password_confirm" aria-label="<?= h(t('show_password')) ?>"><i class="bi bi-eye"></i></button>
								<?= nu_feedback($errors, 'password_confirm') ?>
							</div>
						</div>
					</div>

					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="sus_change_password" name="sus_change_password" value="1"<?= $v['sus_change_password'] === '1' ? ' checked' : '' ?>>
						<label class="form-check-label" for="sus_change_password"><?= h(t('user_change_pw')) ?></label>
					</div>
				</div>
			</div>
			<div class="card shadow-sm userCardAccess">
				<div class="card-header fw-semibold"><i class="bi bi-shield-lock me-2"></i><?= h(t('user_sec_access')) ?></div>
				<div class="card-body">
					<div class="mb-3">
						<label class="form-label" for="sus_zzzzsys_access_id"><?= h(t('access_level')) ?> <span class="text-danger">*</span></label>
						<?php $canOpenAccess = NuAcl::can(NuAcl::FORM_ACCESS); $accessUrl = nu_url('accesslevel', ['id' => '__ID__']); ?>
						<div class="<?= $canOpenAccess ? 'input-group' . (isset($errors['sus_zzzzsys_access_id']) ? ' has-validation' : '') : '' ?>">
						<select class="form-select nuListTomSelect<?= nu_invalid($errors, 'sus_zzzzsys_access_id') ?>" id="sus_zzzzsys_access_id" name="sus_zzzzsys_access_id" required>
							<option value=""><?= h(t('please_select')) ?></option>
							<?php $grp = null; foreach ($accessLevels as $a):
								$g = (string)$a['sal_group'];
								if ($g !== $grp) { if ($grp !== null) echo '</optgroup>'; echo '<optgroup label="' . h($g !== '' ? $g : t('no_group')) . '">'; $grp = $g; } ?>
								<option value="<?= h($a['zzzzsys_access_id']) ?>" data-sub="<?= h((string)$a['sal_description']) ?>"<?= $v['sus_zzzzsys_access_id'] === $a['zzzzsys_access_id'] ? ' selected' : '' ?>><?= h((string)($a['sal_code'] ?: $a['zzzzsys_access_id'])) ?></option>
							<?php endforeach; if ($grp !== null) echo '</optgroup>'; ?>
						</select>
						<?php if ($canOpenAccess):   // opens the chosen access level in a new tab (assets/js/nuuser.js keeps the link in step with the select) ?>
							<a class="btn btn-outline-secondary nuOpenBtn<?= $v['sus_zzzzsys_access_id'] === '' ? ' disabled' : '' ?>" id="accessOpen" target="_blank" rel="noopener"
							   data-url="<?= h($accessUrl) ?>" href="<?= h($v['sus_zzzzsys_access_id'] === '' ? '#' : str_replace('__ID__', rawurlencode($v['sus_zzzzsys_access_id']), $accessUrl)) ?>"
							   <?= $v['sus_zzzzsys_access_id'] === '' ? 'aria-disabled="true" tabindex="-1"' : '' ?> title="<?= h(t('user_access_open')) ?>" aria-label="<?= h(t('user_access_open')) ?>"><i class="bi bi-box-arrow-up-right"></i></a>
						<?php endif; ?>
						<?= nu_feedback($errors, 'sus_zzzzsys_access_id') ?>
						</div>
					</div>
					<?php // additional access levels (0.8.585, core/nuuseraccess.php): rows [level, valid until], added / removed in assets/js/nuuser.js ?>
					<div class="mb-3" id="xaBox">
						<input type="hidden" name="xa_sent" value="1">
						<div class="d-flex align-items-center gap-2 mb-1">
							<span class="form-label mb-0"><?= h(t('xa_title')) ?></span><span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('xa_tip')) ?>" title="<?= h(t('xa_tip')) ?>"><i class="bi bi-info-circle"></i></span>
							<span class="ms-auto d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary<?= $xaSel ? '' : ' d-none' ?>" id="xaEff" data-bs-toggle="modal" data-bs-target="#xaEffDlg"><i class="bi bi-shield-check me-1"></i><?= h(t('eff_btn')) ?></button>
							<button type="button" class="btn btn-sm btn-outline-secondary" id="xaAdd"<?= $canSave ? '' : ' disabled' ?>><i class="bi bi-plus-lg me-1"></i><?= h(t('xa_add')) ?></button></span>
						</div>
						<?php $xaOpt = static function (string $sel) use ($accessLevels): string {
							$o = '<option value="">' . h(t('please_select')) . '</option>';
							foreach ($accessLevels as $a) $o .= '<option value="' . h($a['zzzzsys_access_id']) . '"' . ($sel === (string)$a['zzzzsys_access_id'] ? ' selected' : '') . '>' . h((string)($a['sal_code'] ?: $a['zzzzsys_access_id']) . ((string)$a['sal_description'] !== '' ? ' – ' . $a['sal_description'] : '')) . '</option>';
							return $o;
						}; $xaRow = static function (string $acc, string $until) use ($xaOpt, $canSave): string {
							$gone = $until !== '' && !NuUserAccess::active($until);
							return '<div class="input-group input-group-sm mb-1 xaRow' . ($gone ? ' xaExpired' : '') . '">'
								. '<select class="form-select" name="xa_acc[]" aria-label="' . h(t('access_level')) . '"' . ($canSave ? '' : ' disabled') . '>' . $xaOpt($acc) . '</select>'
								. '<span class="input-group-text">' . h(t('xa_until')) . '</span>'
								. '<input type="date" class="form-control xaUntil" name="xa_until[]" value="' . h($until) . '" aria-label="' . h(t('xa_until')) . '"' . ($canSave ? '' : ' disabled') . '>'
								. ($gone ? '<span class="input-group-text text-body-secondary">' . h(t('user_expired')) . '</span>' : '')
								. '<button type="button" class="btn btn-outline-danger xaDel" title="' . h(t('remove')) . '" aria-label="' . h(t('remove')) . '"' . ($canSave ? '' : ' disabled') . '><i class="bi bi-x-lg"></i></button></div>';
						}; ?>
						<div id="xaRows"><?php foreach ($xaSel as [$xa, $xu]) echo $xaRow((string)$xa, (string)$xu); ?></div>
						<template id="xaTpl"><?= $xaRow('', '') ?></template>
						<div class="small text-body-secondary<?= $xaSel ? ' d-none' : '' ?>" id="xaNone"><?= h(t('xa_none')) ?></div>
						<?php if (isset($errors['xa'])): ?><div class="invalid-feedback d-block"><?= h($errors['xa']) ?></div><?php endif; ?>
					</div>
					<?php if ($admRoleMay || $admRoleCur !== ''): $admRoles = NuAdmin::roles(); ?>
					<div class="mb-3" id="admRoleField">
						<label class="form-label" for="admin_role"><?= h(t('adm_role_field')) ?></label><span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('adm_role_field_tip')) ?>" title="<?= h(t('adm_role_field_tip')) ?>"><i class="bi bi-info-circle"></i></span>
						<?php $canOpenRole = NuAdmin::can('adminroles', 'view'); $roleUrl = nu_url('adminroles', ['id' => '__ID__']);   // open the role in a new tab (0.8.738) ?>
						<div class="<?= $canOpenRole ? 'input-group' : '' ?>">
						<select class="form-select" id="admin_role" name="admin_role"<?= $admRoleMay ? '' : ' disabled' ?>>
							<option value=""><?= h(t('adm_role_none')) ?></option>
							<?php foreach ($admRoles as $ar): ?><option value="<?= h($ar['id']) ?>"<?= $admRoleSel === $ar['id'] ? ' selected' : '' ?>><?= h($ar['name'] . (NuAdmin::hasFull($ar['rights']) ? ' ⚠' : '')) ?></option><?php endforeach; ?>
						</select>
						<?php if ($canOpenRole): ?>
							<a class="btn btn-outline-secondary nuOpenBtn<?= $admRoleSel === '' ? ' disabled' : '' ?>" id="admRoleOpen" target="_blank" rel="noopener"
							   data-url="<?= h($roleUrl) ?>" href="<?= h($admRoleSel === '' ? '#' : str_replace('__ID__', rawurlencode($admRoleSel), $roleUrl)) ?>"
							   <?= $admRoleSel === '' ? 'aria-disabled="true" tabindex="-1"' : '' ?> title="<?= h(t('adm_role_open')) ?>" aria-label="<?= h(t('adm_role_open')) ?>"><i class="bi bi-box-arrow-up-right"></i></a>
						<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<div class="card shadow-sm userCardAccount">
				<div class="card-header fw-semibold"><i class="bi bi-gear me-2"></i><?= h(t('user_sec_account')) ?></div>
				<div class="card-body">
					<div class="row g-3 mb-3">
						<div class="col-sm-6">
							<label class="form-label" for="sus_expires_on"><?= h(t('user_expires')) ?></label><span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('user_expires_hint')) ?>" title="<?= h(t('user_expires_hint')) ?>"><i class="bi bi-info-circle"></i></span>
							<input type="date" class="form-control<?= nu_invalid($errors, 'sus_expires_on') ?>" id="sus_expires_on" name="sus_expires_on" value="<?= h($v['sus_expires_on']) ?>">
							<?= nu_feedback($errors, 'sus_expires_on') ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="sus_language"><?= h(t('user_language')) ?></label>
							<select class="form-select" id="sus_language" name="sus_language">
								<?php foreach ($langOptions as $val => $label): ?>
									<option value="<?= h($val) ?>"<?= $langSelected === (string)$val ? ' selected' : '' ?>><?= h($label) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<?php if ($canSave):
						// 0.8.657: always shown – greyed out while e-mail is not set up, the (i) says so and a link leads to the E-mail settings (with that right)
						$wHint = $mailOk ? t('welcome_option_hint', NuWelcome::hours()) : t('welcome_need_mail'); ?>
					<div class="form-check form-switch" id="userWelcomeWrap">
						<input class="form-check-input" type="checkbox" role="switch" id="user_send_welcome_email" name="user_send_welcome_email" value="1"<?= $welcome ? ' checked' : '' ?><?= $mailOk ? '' : ' disabled' ?> aria-describedby="welcomeHint"
							<?php if ($mailOk): ?>data-nu-welcome="<?= h((string)json_encode(NuWelcome::sources(), JSON_UNESCAPED_UNICODE)) ?>"
							data-nu-welcome-t="<?= h((string)json_encode(['title' => t('welcome_option'), 'proc' => t('welcome_src_proc'), 'tpl' => t('welcome_src_tpl'), 'std' => t('welcome_src_std'), 'builtin' => t('welcome_src_builtin'), 'close' => t('close')], JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
						<label class="form-check-label" for="user_send_welcome_email"><?= h(t('welcome_option')) ?></label><?php // hint as tooltip of the (i), outside the label (a click does not toggle) ?>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('info')) ?>" title="<?= h($wHint) ?>"><i class="bi bi-info-circle"></i></span>
						<?php if (!$mailOk && NuAcl::can(NuAcl::FORM_SETUP)): ?><a class="small ms-2" id="userWelcomeMailLink" href="<?= h(nu_url('email', ['tab' => 'settings'])) ?>"><i class="bi bi-envelope-at me-1"></i><?= h(t('welcome_mail_settings')) ?></a><?php endif; ?>
						<span class="visually-hidden" id="welcomeHint"><?= h($wHint) ?></span>
					</div>
					<?php if ($mailOk): $wProc = NuWelcome::hasProcedure(); // preview before sending (0.8.684) – shown while the switch above is on ?>
					<div class="form-check form-switch ms-4" id="userWelcomePvWrap"<?= $welcome ? '' : ' hidden' ?>>
						<input class="form-check-input" type="checkbox" role="switch" id="user_welcome_preview" data-nu-noguard<?= $wProc ? ' disabled' : '' ?>>
						<label class="form-check-label" for="user_welcome_preview"><?= h(t('welcome_pv_option')) ?></label>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('info')) ?>" title="<?= h($wProc ? t('welcome_pv_proc', NuWelcome::PROCEDURE) : t('welcome_pv_option_tip')) ?>"><i class="bi bi-info-circle"></i></span>
					</div>
					<?php endif; ?>
					<?php if (isset($errors['welcome'])): ?><div class="invalid-feedback d-block" id="userWelcomeErr"><?= h($errors['welcome']) ?></div><?php endif; ?>
					<?php endif; ?>
					<?php if (NU_FEATURE_ACCESSIBILITY): ?>
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="sus_accessibility_features" name="sus_accessibility_features" value="1"<?= $v['sus_accessibility_features'] === '1' ? ' checked' : '' ?>>
						<label class="form-check-label" for="sus_accessibility_features"><?= h(t('user_accessibility')) ?></label>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

	</div>
	</div>
	<div class="tab-pane fade<?= $permTab ? ' show active' : '' ?>" id="userTabPerm" role="tabpanel" aria-labelledby="userTabPermBtn" tabindex="0">
			<div class="card shadow-sm" id="userPerms">
				<div class="card-header fw-semibold d-flex flex-wrap align-items-center gap-2">
					<span class="me-auto"><i class="bi bi-key me-2"></i><?= h(t('perm_title')) ?>
						<span class="badge text-bg-secondary ms-1" id="userPermCount"><?= $permCount ?></span></span>
					<?php if (NuAcl::can(NuAcl::FORM_PERMISSION)): ?><a class="btn btn-sm btn-outline-secondary fw-normal" href="<?= h(nu_url('permissions')) ?>"><i class="bi bi-gear me-1"></i><?= h(t('perm_manage')) ?></a><?php endif; ?>
				</div>
				<div class="card-body">
					<?php if (!$permItems): ?>
						<div class="text-body-secondary small"><?= h(t('perm_none_defined')) ?></div>
					<?php else: ?>
						<input type="hidden" name="perm_sent" value="1">
						<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
							<div class="input-group input-group-sm userPermSearch">
								<span class="input-group-text"><i class="bi bi-search"></i></span>
								<input type="search" class="form-control" id="userPermFilter" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('perm_search')) ?>">
							</div>
							<?php // assigned / all / not assigned (0.8.642): assigned by default – all when the user has none yet ?>
							<select class="form-select form-select-sm w-auto" id="userPermState" autocomplete="off" aria-label="<?= h(t('perm_state')) ?>">
								<?php foreach (['assigned', 'all', 'unassigned'] as $ps): // always "Assigned" when the record opens (0.8.644) ?><option value="<?= $ps ?>"<?= $ps === 'assigned' ? ' selected' : '' ?>><?= h(t('perm_state_' . $ps)) ?></option><?php endforeach; ?>
							</select>
							<?php $permGroups = array_values(array_unique(array_map(static fn($pi) => (string)$pi['pme_group'], $permItems))); ?>
							<?php if ($permGroups):   // group filter (0.8.260, always shown since 0.8.261): All / None then apply to that group only ?>
								<select class="form-select form-select-sm w-auto" id="userPermGroup" aria-label="<?= h(t('perm_group')) ?>">
									<option value="*"><?= h(t('perm_group_all')) ?></option>
									<?php foreach ($permGroups as $pg): ?><option value="<?= h($pg) ?>"><?= h($pg !== '' ? $pg : t('no_group')) ?></option><?php endforeach; ?>
								</select>
							<?php endif; ?>
							<button type="button" class="btn btn-sm btn-outline-secondary" data-perm-all="1" data-perm-confirm="<?= h(t('perm_all_confirm')) ?>" data-perm-confirm-ok="<?= h(t('perm_all')) ?>"><i class="bi bi-check2-all me-1"></i><?= h(t('perm_all')) ?></button>
							<button type="button" class="btn btn-sm btn-outline-secondary" data-perm-all="0"><i class="bi bi-x-lg me-1"></i><?= h(t('perm_none')) ?></button>
							<?php if ($permFrom): // the items of another user (0.8.649): dialog #userPermCopyDlg – Replace / Add, saved with Save, Undo as for All / None ?>
							<button type="button" class="btn btn-sm btn-outline-secondary" id="userPermCopy" data-bs-toggle="modal" data-bs-target="#userPermCopyDlg"><i class="bi bi-person-down me-1"></i><?= h(t('perm_copy')) ?></button>
							<?php endif; ?>
							<?php // Undo (0.8.584): back to the selection before the last All / None – shown until another change (nuuser.js) ?>
							<button type="button" class="btn btn-sm btn-outline-warning d-none" id="userPermUndo" title="<?= h(t('perm_undo_tip')) ?>"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('perm_undo')) ?></button>
							<?php // phones: descriptions on / off for a more compact table (remembered per browser, nuuser.js) ?>
							<div class="form-check form-switch mb-0 d-sm-none" id="userPermDescWrap" data-nu-noguard>
								<input class="form-check-input" type="checkbox" role="switch" id="userPermDescToggle" checked>
								<label class="form-check-label small" for="userPermDescToggle"><?= h(t('perm_show_desc')) ?></label>
							</div>
						</div>
						<?php // table (0.8.458): one row per item – a click anywhere on the row toggles it (nuuser.js) ?>
						<div class="table-responsive">
							<table class="table nu-table table-hover align-middle mb-0 userPermTable">
								<thead><tr>
									<?php // sortable columns (0.8.611, nuuser.js): a click sorts, a second one reverses; the first column = selected first ?>
									<th scope="col" class="userPermCheck userPermSort" data-sort="sel"><button type="button" class="btn btn-link p-0 userPermSortBtn" title="<?= h(t('perm_sort_sel')) ?>" aria-label="<?= h(t('perm_sort_sel')) ?>"><i class="bi bi-check2-square"></i></button></th>
									<th scope="col" class="userPermSort" data-sort="ident"><button type="button" class="btn btn-link p-0 userPermSortBtn"><?= h(t('perm_ident')) ?></button></th>
									<th scope="col" class="userPermSort d-none d-md-table-cell" data-sort="group" aria-sort="ascending"><button type="button" class="btn btn-link p-0 userPermSortBtn"><?= h(t('perm_group')) ?></button></th>
									<th scope="col" class="userPermSort d-none d-sm-table-cell" data-sort="desc"><button type="button" class="btn btn-link p-0 userPermSortBtn"><?= h(t('perm_description')) ?></button></th>
								</tr></thead>
								<tbody>
								<?php $permOpen = NuAcl::can(NuAcl::FORM_PERMISSION);   // link to the item in a new tab (0.8.612) ?>
								<?php foreach ($permItems as $pi):
									$g = (string)$pi['pme_group'];
									$pid = (string)$pi['zzzzsys_permission_item_id'];
									// description (0.8.561): one line, as much as fits (CSS: …, the text at most 750 px wide); the full text as tooltip
									$pDesc = (string)$pi['pme_description'];
									$pTitle = $pDesc !== '' ? ' title="' . h($pDesc) . '"' : ''; ?>
									<tr class="userPermItem" data-group="<?= h($g) ?>" data-search="<?= h(mb_strtolower($pi['pme_ident'] . ' ' . $g . ' ' . $pi['pme_description'])) ?>">
										<td class="userPermCheck"><input class="form-check-input" type="checkbox" name="perm[]" value="<?= h($pid) ?>" id="perm_<?= h($pid) ?>"<?= in_array($pid, $permSel, true) ? ' checked' : '' ?>></td>
										<td class="userPermIdent"><label class="font-monospace" for="perm_<?= h($pid) ?>"><?= h($pi['pme_ident']) ?></label><?php if ($permOpen): ?>
											<a class="userPermOpen ms-1" href="<?= h(nu_url('permissions', ['id' => $pid])) ?>" target="_blank" rel="noopener" title="<?= h(t('perm_open_item')) ?>" aria-label="<?= h(t('perm_open_item')) ?>"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
											<?php if ($pDesc !== ''): // phones: the description below the ident ?><div class="small text-body-secondary userPermDesc userPermDescLine d-sm-none"<?= $pTitle ?>><?= h($pDesc) ?></div><?php endif; ?></td>
										<td class="text-body-secondary text-nowrap d-none d-md-table-cell"><?= h($g !== '' ? $g : t('no_group')) ?></td>
										<td class="text-body-secondary userPermDesc userPermDescCell d-none d-sm-table-cell"><div class="userPermDescLine"<?= $pTitle ?>><?= h($pDesc) ?></div></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div class="text-body-secondary small d-none" id="userPermEmpty" data-none-assigned="<?= h(t('perm_none_assigned')) ?>" data-none="<?= h(t('no_records')) ?>"><?= h(t('no_records')) ?></div>
						<div class="form-text mt-2"><?= h(t('perm_user_hint')) ?></div>
					<?php endif; ?>
				</div>
			</div>
	</div>
	</div>
	</fieldset>

</form>
<?php if (!$isNew && $me['globeadmin'] && empty($me['demo'])): ?>
<?php // one-time login links (0.8.339) – their own forms, not tied to $nuConfigAutoLogin (0.8.365: the buttons did nothing without it) ?>
<form method="post" id="loginTokenForm" action="<?= h(nu_url('user', ['id' => $id])) ?>"><?= nu_csrf_field() ?></form>
<form method="post" id="loginTokenClearForm" action="<?= h(nu_url('user', ['id' => $id])) ?>" data-confirm="<?= h(t('ltok_clear_confirm')) ?>"
	  data-confirm-ok="<?= h(t('ltok_clear')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="logintoken_clear"></form>
<?php endif; ?>
<?php if (!$isNew && $me['globeadmin'] && empty($me['demo']) && nu_cfg('nuConfigAutoLogin')): ?>
<form method="post" id="autoLoginRevokeForm" action="<?= h(nu_url('user', ['id' => $id])) ?>" data-confirm="<?= h(t('user_autologin_revoke_confirm')) ?>"
	  data-confirm-ok="<?= h(t('user_autologin_revoke')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="autologin_revoke"></form>
<?php endif; ?>

<?php if ($canSave):   // password generator (assets/js/nuuser.js) – runs in the browser, nothing is sent before Save
	$pwgMin = max(8, $min);
	$pwgLen = max(16, $pwgMin); ?>
<div class="modal fade" id="nuPwGen" tabindex="-1" aria-labelledby="nuPwGenTitle" aria-hidden="true" data-min="<?= $pwgMin ?>" data-max="64" data-default="<?= min(64, $pwgLen) ?>"
	 data-used="<?= h(t('pwg_used')) ?>" data-used-copied="<?= h(t('pwg_used_copied')) ?>" data-copied="<?= h(t('pwg_copied')) ?>"
	 data-strength="<?= h(json_encode([t('pwg_weak'), t('pwg_fair'), t('pwg_strong'), t('pwg_very_strong')], JSON_UNESCAPED_UNICODE)) ?>" data-bits="<?= h(t('pwg_bits')) ?>">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h2 class="modal-title fs-5" id="nuPwGenTitle"><i class="bi bi-magic me-2"></i><?= h(t('pwg_title')) ?></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
			</div>
			<div class="modal-body">
				<label class="form-label" for="pwgOut"><?= h(t('login_password')) ?></label>
				<div class="input-group">
					<input type="text" class="form-control font-monospace nuPwGenOut" id="pwgOut" readonly spellcheck="false" autocomplete="off" aria-describedby="pwgStrengthText">
					<button class="btn btn-outline-secondary" type="button" id="pwgNew" title="<?= h(t('pwg_regenerate')) ?>" aria-label="<?= h(t('pwg_regenerate')) ?>"><i class="bi bi-arrow-repeat"></i></button>
					<button class="btn btn-outline-secondary" type="button" id="pwgCopy" title="<?= h(t('pwg_copy')) ?>" aria-label="<?= h(t('pwg_copy')) ?>"><i class="bi bi-clipboard"></i></button>
				</div>
				<div class="progress mt-2 nuPwGenBar" role="progressbar" aria-label="<?= h(t('pwg_strength')) ?>"><div class="progress-bar" id="pwgStrengthBar"></div></div>
				<div class="small mt-1 d-flex justify-content-between"><span id="pwgStrengthText"></span><span class="text-body-secondary" id="pwgCopied" aria-live="polite"></span></div>

				<hr>
				<label class="form-label d-flex justify-content-between" for="pwgLenRange"><span><?= h(t('pwg_length')) ?></span></label>
				<div class="d-flex gap-3 align-items-center mb-3">
					<input type="range" class="form-range flex-grow-1" id="pwgLenRange" min="<?= $pwgMin ?>" max="64" step="1" aria-label="<?= h(t('pwg_length')) ?>">
					<input type="number" class="form-control text-center nuPwGenLen" id="pwgLen" min="<?= $pwgMin ?>" max="64" aria-label="<?= h(t('pwg_length')) ?>">
				</div>
				<div class="row g-2">
					<?php foreach (['upper' => 'pwg_upper', 'lower' => 'pwg_lower', 'digits' => 'pwg_digits', 'symbols' => 'pwg_symbols'] as $k => $label): ?>
						<div class="col-sm-6"><div class="form-check form-switch">
							<input class="form-check-input pwgSet" type="checkbox" role="switch" id="pwg_<?= $k ?>" data-set="<?= $k ?>" checked>
							<label class="form-check-label" for="pwg_<?= $k ?>"><?= h(t($label)) ?></label>
						</div></div>
					<?php endforeach; ?>
					<div class="col-12"><div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="pwg_ambiguous" checked>
						<label class="form-check-label" for="pwg_ambiguous"><?= h(t('pwg_ambiguous')) ?></label>
					</div></div>
				</div>
				<div class="form-text mt-3"><i class="bi bi-shield-lock me-1"></i><?= h(t('pwg_hint')) ?></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
				<button type="button" class="btn btn-primary" id="pwgUse"><i class="bi bi-check-lg me-1"></i><?= h(t('pwg_use')) ?></button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<?php
if ($existing && $id !== $me['id']) echo nu_user_remove_modal($rights, ['stay' => '1']);
// effective rights dialog (0.8.589): filled by assets/js/nuuser.js from the POST action "effective" (the levels as entered, not saved yet)
$effT = ['view' => t('view'), 'add' => t('add'), 'save' => t('save'), 'delete' => t('delete'), 'clone' => t('clone'), 'print' => t('print'),
	'main' => t('eff_main'), 'expired' => t('user_expired'), 'additional' => t('xa_badge'), 'browse_only' => t('eff_browse_only'), 'edit_only' => t('eff_edit_only'),
	'none' => t('eff_none'), 'form' => t('eff_form'), 'levels' => t('eff_levels'), 'copied' => t('copy_done'), 'error' => t('err_unexpected')];
?>
<div class="modal fade" id="xaEffDlg" tabindex="-1" aria-labelledby="xaEffTitle" aria-hidden="true" data-url="<?= h(nu_url('user', ['id' => $id !== '' ? $id : null])) ?>" data-csrf="<?= h(nu_csrf_token()) ?>" data-t="<?= h((string)json_encode($effT, JSON_UNESCAPED_UNICODE)) ?>">
	<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title h5" id="xaEffTitle"><i class="bi bi-shield-check me-2"></i><?= h(t('eff_title')) ?></h2>
			<span class="nuInfoTip ms-2" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('eff_hint')) ?>" title="<?= h(t('eff_hint')) ?>"><i class="bi bi-info-circle"></i></span>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
		</div>
		<div class="modal-body">
			<div class="d-flex flex-wrap gap-2 align-items-center mb-2" id="xaEffLevels"></div>
			<div class="d-flex flex-wrap gap-3 align-items-center mb-3">
				<input type="search" class="form-control form-control-sm w-auto" id="xaEffFind" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>">
				<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="xaEffOnly"><label class="form-check-label small" for="xaEffOnly"><?= h(t('eff_only_extra')) ?></label></div>
			</div>
			<ul class="nav nav-tabs mb-2" role="tablist">
				<li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#xaEffForms" type="button" role="tab"><?= h(t('eff_forms')) ?> <span class="badge rounded-pill text-bg-light border" id="xaEffNForms">0</span></button></li>
				<li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#xaEffProcs" type="button" role="tab"><?= h(t('eff_procs')) ?> <span class="badge rounded-pill text-bg-light border" id="xaEffNProcs">0</span></button></li>
				<li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#xaEffReports" type="button" role="tab"><?= h(t('eff_reports')) ?> <span class="badge rounded-pill text-bg-light border" id="xaEffNReports">0</span></button></li>
			</ul>
			<div class="tab-content">
				<div class="tab-pane fade show active" id="xaEffForms" role="tabpanel"><div class="table-responsive"><table class="table table-sm nu-table align-middle mb-0 xaEffTable"><thead></thead><tbody></tbody></table></div></div>
				<div class="tab-pane fade" id="xaEffProcs" role="tabpanel"><div class="table-responsive"><table class="table table-sm nu-table align-middle mb-0 xaEffTable"><tbody></tbody></table></div></div>
				<div class="tab-pane fade" id="xaEffReports" role="tabpanel"><div class="table-responsive"><table class="table table-sm nu-table align-middle mb-0 xaEffTable"><tbody></tbody></table></div></div>
			</div>
			<div class="text-center py-4 d-none" id="xaEffBusy"><div class="spinner-border text-primary" role="status" aria-hidden="true"></div></div>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-outline-secondary me-auto" id="xaEffCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('copy')) ?></button>
			<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('close')) ?></button>
		</div>
	</div></div>
</div>
<?php if ($canSave && $mailOk): // welcome e-mail preview (0.8.684) – filled by assets/js/nuuser.js from the JSON check of the save ?>
<div class="modal fade" id="userWelcomePvDlg" tabindex="-1" aria-labelledby="userWelcomePvTitle" aria-hidden="true" data-bs-backdrop="static"
	data-t="<?= h((string)json_encode(['replace' => t('welcome_pv_replace'), 'failed' => t('welcome_pv_failed'), 'link' => t('welcome_pv_err_link', NuWelcome::MARKER), 'marker' => NuWelcome::MARKER], JSON_UNESCAPED_UNICODE)) ?>">
	<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title h5" id="userWelcomePvTitle"><i class="bi bi-envelope-open me-2"></i><?= h(t('welcome_pv_title')) ?></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('cancel')) ?>"></button>
		</div>
		<div class="modal-body">
			<div class="row g-2 mb-2">
				<div class="col-md-6"><label class="form-label small mb-1" for="userWpvTo"><?= h(t('welcome_pv_to')) ?></label><input class="form-control" id="userWpvTo" readonly></div>
				<div class="col-md-6"><label class="form-label small mb-1" for="userWpvTpl"><?= h(t('welcome_pv_template')) ?></label><select class="form-select" id="userWpvTpl"></select></div>
				<div class="col-md-6" id="userWpvCcWrap" hidden><label class="form-label small mb-1" for="userWpvCc"><?= h(t('welcome_pv_cc')) ?></label><input class="form-control" id="userWpvCc" readonly></div>
				<div class="col-md-6" id="userWpvBccWrap" hidden><label class="form-label small mb-1" for="userWpvBcc"><?= h(t('welcome_pv_bcc')) ?></label><input class="form-control" id="userWpvBcc" readonly></div>
				<div class="col-12"><label class="form-label small mb-1" for="userWpvSubject"><?= h(t('welcome_pv_subject')) ?></label><input class="form-control" id="userWpvSubject" maxlength="300"></div>
			</div>
			<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
				<span class="small text-body-secondary me-auto" id="userWpvHint"><?= h(t('welcome_pv_edit_hint')) ?> <span id="userWpvLinkHint"><?= h(t('welcome_pv_link_hint', NuWelcome::MARKER)) ?></span></span>
				<div class="btn-group btn-group-sm" role="group" aria-label="<?= h(t('welcome_pv_title')) ?>">
					<input type="radio" class="btn-check" name="userWpvMode" id="userWpvModeView" value="view" checked><label class="btn btn-outline-secondary" for="userWpvModeView"><i class="bi bi-eye me-1"></i><?= h(t('welcome_pv_view')) ?></label>
					<input type="radio" class="btn-check" name="userWpvMode" id="userWpvModeHtml" value="html"><label class="btn btn-outline-secondary" for="userWpvModeHtml"><i class="bi bi-code-slash me-1"></i><?= h(t('welcome_pv_html')) ?></label>
				</div>
			</div>
			<iframe class="nuWpvFrame" id="userWpvFrame" title="<?= h(t('welcome_pv_title')) ?>"></iframe>
			<textarea class="form-control nfdMono nuWpvSource" id="userWpvSource" rows="18" hidden aria-label="<?= h(t('welcome_pv_html')) ?>" spellcheck="false"></textarea>
			<div class="invalid-feedback d-block" id="userWpvErr" aria-live="polite"></div>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal" id="userWpvCancel"><?= h(t('cancel')) ?></button>
			<button type="button" class="btn btn-primary" id="userWpvSend"><i class="bi bi-send me-1"></i><?= h(t('welcome_pv_send')) ?></button>
		</div>
	</div></div>
</div>
<?php endif; ?>
<?php if ($permFrom): // copy the permission items of another user (0.8.649) – filled by assets/js/nuuser.js from the POST action "perm_of"
	$pcT = ['info' => t('perm_copy_info'), 'error' => t('err_unexpected')]; ?>
<div class="modal fade" id="userPermCopyDlg" tabindex="-1" aria-labelledby="userPermCopyTitle" aria-hidden="true" data-url="<?= h(nu_url('user', ['id' => $id !== '' ? $id : null])) ?>" data-csrf="<?= h(nu_csrf_token()) ?>" data-t="<?= h((string)json_encode($pcT, JSON_UNESCAPED_UNICODE)) ?>">
	<div class="modal-dialog"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title h5" id="userPermCopyTitle"><i class="bi bi-person-down me-2"></i><?= h(t('perm_copy_title')) ?></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
		</div>
		<div class="modal-body">
			<label class="form-label" for="userPermCopyFrom"><?= h(t('perm_copy_user')) ?></label>
			<select class="form-select nuListTomSelect" id="userPermCopyFrom" autocomplete="off">
				<option value=""><?= h(t('perm_copy_choose')) ?></option>
				<?php foreach ($permFrom as $pf): ?><option value="<?= h((string)$pf['id']) ?>" data-sub="<?= h((string)$pf['login'] . ' · ' . t('perm_copy_n', (int)$pf['n'])) ?>"><?= h((string)($pf['name'] !== '' && $pf['name'] !== null ? $pf['name'] : $pf['login'])) ?></option><?php endforeach; ?>
			</select>
			<div class="form-text" id="userPermCopyInfo" aria-live="polite"></div>
			<p class="small text-body-secondary mt-3 mb-0"><?= h(t('perm_copy_hint')) ?></p>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
			<button type="button" class="btn btn-outline-primary" id="userPermCopyAdd" disabled><i class="bi bi-plus-lg me-1"></i><?= h(t('perm_copy_add')) ?></button>
			<button type="button" class="btn btn-primary" id="userPermCopyReplace" disabled><i class="bi bi-arrow-repeat me-1"></i><?= h(t('perm_copy_replace')) ?></button>
		</div>
	</div></div>
</div>
<?php endif;
nu_layout_end('<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nulistselect.js') . '"></script><script src="' . nu_asset('assets/js/nuuser.js') . '"></script>');   // name parts; permission items when present
