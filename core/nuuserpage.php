<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * The work of the user form (pages/nuuser.php, code review R7): its POST actions – Save, archive, delete, passkeys, second factor,
 * login links, password reset, effective rights, own field titles, "Copy from user…". Each ends with a redirect or a JSON answer; Save
 * returns the form state when it found errors. The page reads the record, renders and shows the errors.
 */
final class NuUserPage
{
	/** The POST actions of the form besides Save. Returns when the action is not one of them (or needs a record that is not there). */
	public static function post(string $action, ?array $existing, string $id, array $me, array $rights): void
	{
		match (true) {
			$action === 'effective'  => self::effective(),
			$action === 'user_label' => self::userLabel(),
			$action === 'perm_of'    => self::permOf(),
			$action === 'delete'     => self::delete($existing, $id, $me, $rights),
			!$existing               => null,
			$action === 'archive'    => self::archive($existing, $id, $me, $rights),
			$action === 'passkey_remove'   => self::passkeyRemove($existing, $id, $me, $rights),
			$action === 'reset2fa'         => self::reset2fa($existing, $id, $me, $rights),
			$action === 'autologin_revoke' => self::autologinRevoke($existing, $id, $me, $rights),
			in_array($action, ['logintoken_create', 'logintoken_mail', 'logintoken_clear'], true) => self::loginToken($existing, $id, $me, $rights),
			$action === 'pwreset'    => self::pwReset($existing, $id, $me, $rights),
			default                  => null,
		};
	}

	/**
	 * Save of the form (new user, clone or change): reads the input into the form state $s, checks it and writes it – then redirects. On
	 * errors it returns the state (values, errors …) for the page to show again. $c: what the page worked out before (read only).
	 */
	public static function save(array $s, array $c): array
	{
		$db = nu_db();
		['v' => $v, 'errors' => $errors, 'langVariant' => $langVariant, 'admRoleSel' => $admRoleSel, 'welcome' => $welcome, 'permSel' => $permSel,
			'xaSel' => $xaSel, 'ufVals' => $ufVals, 'ufErr' => $ufErr] = $s;
		['text' => $text, 'existing' => $existing, 'id' => $id, 'me' => $me, 'isNew' => $isNew, 'canSave' => $canSave, 'mailOk' => $mailOk,
			'source' => $source, 'cloneId' => $cloneId, 'admRoleMay' => $admRoleMay, 'admRoleCur' => $admRoleCur, 'xaOld' => $xaOld, 'permItems' => $permItems,
			'ufAll' => $ufAll, 'ufEdit' => $ufEdit, 'langVariantOld' => $langVariantOld, 'min' => $min] = $c;
		if (!$canSave) NuAcl::require(NuAcl::FORM_USER, 'save');
		self::guardAdmin($existing, $me, $id);

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

		$errors = self::checkIdentity($v, $errors, $db, $id, $existing, $me);

		$errors = self::checkAccessMail($xaOld, $xaSel, $errors, $existing, $me, $v, $welcome);

		$errors = self::checkPassword($isNew, $pw, $welcome, $errors, $min, $confirm, $id, $v);

		// custom fields: only the ones this admin may change; a new user's unique check against all others
		$ufPost = is_array($_POST['uf'] ?? null) ? $_POST['uf'] : [];
		if ($ufEdit && $ufPost) {
			[$ufNew, $ufErr] = NuUserFields::validate($ufPost, $isNew ? '' : $id, 'admin');
			$ufVals = array_merge($ufVals, array_map(static fn($x) => is_scalar($x) ? (string)$x : '', array_intersect_key($ufPost, $ufEdit)));
			foreach ($ufErr as $code => $msg) $errors['uf_' . $code] = $msg;
		} else {
			$ufNew = [];
		}
		// welcome e-mail edited in the preview: it must keep the set-password link when it had one
		$wEdit = $welcome && nu_post('welcome_edit') === '1' && !NuWelcome::hasProcedure();
		$wHtml = $wEdit ? str_replace("\r\n", "\n", (string)($_POST['welcome_html'] ?? '')) : '';
		if ($wEdit && nu_post('welcome_link') === '1' && !str_contains($wHtml . nu_post('welcome_subject'), NuWelcome::MARKER)) $errors['welcome'] = t('welcome_pv_err_link', NuWelcome::MARKER);
		if ($isNew) {   // defaults that use the new user's values ({first_name} …), when the field is still empty
			$tplVals = NuUserDefault::forNew(array_filter($ufVals + $ufNew, static fn($x) => (string)$x !== ''), $v);
			foreach ($tplVals as $code => $x) if (NuUserDefault::onSave((string)$ufAll[$code]['default_value']) && (string)($ufNew[$code] ?? '') === '') { $ufNew[$code] = $x; $ufVals[$code] = $x; }
		}
		if ($isNew) foreach ($ufAll as $code => $f) if (!isset($ufNew[$code]) && !isset($ufErr[$code]) && !isset($ufEdit[$code])
			&& ($ufVals[$code] ?? '') !== '') $ufNew[$code] = $ufVals[$code];   // defaults of fields this admin may not edit

		// preview of the welcome e-mail (JSON for assets/js/nuuser.js): every check above ran, nothing is written
		if (nu_post('welcome_check') === '1') {
			header('Content-Type: application/json; charset=utf-8');
			if ($errors || !$welcome || NuWelcome::hasProcedure()) exit((string)json_encode(['ok' => false, 'errors' => count($errors)]));
			$pv = NuWelcome::preview($v + ['zzzzsys_user_id' => $isNew ? '' : $id, 'NUNEXT_LANG_VARIANT' => $langVariant], $pw, nu_post('welcome_choice') ?: 'auto');
			$key = bin2hex(random_bytes(8));
			$_SESSION[NuSess::WELCOME_PREVIEW] = array_slice(($_SESSION[NuSess::WELCOME_PREVIEW] ?? []) + [$key => $pv['html']], -5, null, true);
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
				foreach (NuUserAccess::save($id, $v['sus_zzzzsys_access_id'], $xaSel) as $xc => $xd) $ufDiff['access_extra:' . $xc] = $xd;
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
				$changes += NuUserFields::save($id, $ufNew);            // custom fields: "field:<code>" in the log
				if (isset($_POST['xa_sent']) || $v['sus_zzzzsys_access_id'] !== (string)$existing['sus_zzzzsys_access_id']) {   // additional levels
					foreach (NuUserAccess::save($id, $v['sus_zzzzsys_access_id'], $xaSel) as $xc => $xd) $changes['access_extra:' . $xc] = $xd;
				}
				if ($changes) NuLog::write('user_updated', 'zzzzsys_user', $id, ['fields' => $changes]);
				$permChanged = false;
				if ($permItems && isset($_POST['perm_sent'])) {
					[$pa, $pr] = NuPermission::setUser($id, $permSel);
					if ($pa || $pr) NuLog::write('user_permissions', 'zzzzsys_user', $id, ['login' => $v['sus_login_name'], 'added' => $pa, 'removed' => $pr]);
					$permChanged = (bool)($pa || $pr);
				}
				if ($changes || $permChanged) NuUserJson::stamp($id, false, 'form');   // created / last changed stamps
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
		return compact('v', 'errors', 'langVariant', 'admRoleSel', 'welcome', 'permSel', 'xaSel', 'ufVals', 'ufErr');
	}

	/** Checks of the login name, the code, the full name and the access level (restricted admins: not their own level, no admin-equivalent one). */
	private static function checkIdentity(array $v, array $errors, NuDB $db, string $id, $existing, array $me): array
	{
		// login name: required, unique, not the globeadmin name
		if ($v['sus_login_name'] === '') {
			$errors['sus_login_name'] = t('err_required');
		} elseif (strcasecmp($v['sus_login_name'], (string)nu_cfg('nuConfigDBGlobeadminUsername')) === 0) {
			$errors['sus_login_name'] = t('user_err_globeadmin_name');
		} elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name = ? AND zzzzsys_user_id <> ?', [$v['sus_login_name'], $id])) {
			$errors['sus_login_name'] = t('user_err_login_taken');
		}

		// code (sus_code): unique when set
		if (($owner = NuAuth::userCodeOwner((string)($v['sus_code'] ?? ''), $id)) !== null) $errors['sus_code'] = t('user_err_code_taken', $owner['name']);

		// full name (sus_name): required
		if (trim($v['sus_name']) === '' && !isset($errors['sus_name'])) $errors['sus_name'] = t('err_required');

		if ($v['sus_zzzzsys_access_id'] === '') {
			$errors['sus_zzzzsys_access_id'] = t('err_required');
		} elseif (!NuAcl::levelExists((string)$v['sus_zzzzsys_access_id'])) {
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
		return $errors;
	}

	/** Checks of the additional access levels, the e-mail address and the expiry date. */
	private static function checkAccessMail(array $xaOld, array $xaSel, array $errors, $existing, array $me, array $v, bool $welcome): array
	{
		// additional access levels: existing levels, valid dates; restricted admins: not for the own record, no admin-equivalent new ones
		$xaOldIds = array_column($xaOld, 0);
		foreach ($xaSel as [$xa, $xu]) {
			if (!NuAcl::levelExists((string)$xa)) { $errors['xa'] = t('err_invalid'); break; }
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
		return $errors;
	}

	/** Checks of the password typed on the form (required for a new user without the welcome e-mail, length, confirmation, password policy). */
	private static function checkPassword(bool $isNew, string $pw, bool $welcome, array $errors, int $min, string $confirm, string $id, array $v): array
	{
		if ($isNew && $pw === '' && !$welcome) {                // with the welcome e-mail the user sets the password via the link
			$errors['password'] = t('err_required');
		} elseif ($pw !== '') {
			if (mb_strlen($pw) < $min)  $errors['password'] = t('pw_err_min', $min);
			elseif (strlen($pw) > 72)   $errors['password'] = t('err_too_long', 72);
			elseif ($pw !== $confirm)   $errors['password_confirm'] = t('pw_err_confirm');
			elseif (($policy = NuAuth::passwordPolicy($pw, $isNew ? '' : (string)$id,
				['login' => (string)($v['sus_login_name'] ?? ''),
					'name' => trim((string)($v['sus_first_name'] ?? '') . ' ' . (string)($v['sus_last_name'] ?? '') . ' ' . (string)($v['sus_name'] ?? '')),
					'email' => (string)($v['sus_email'] ?? '')])) !== null) $errors['password'] = $policy;   // nu_check_password_policy
		}
		return $errors;
	}

	// ------------------------------------------------------------------ effective rights: the levels entered in the form, not saved yet
	private static function effective(): never
	{
		$rowsIn = [];
		$xaA = is_array($_POST['xa_acc'] ?? null) ? $_POST['xa_acc'] : [];
		$xaU = is_array($_POST['xa_until'] ?? null) ? $_POST['xa_until'] : [];
		foreach ($xaA as $i => $a) if (is_string($a) && trim($a) !== '') $rowsIn[] = [trim($a), is_string($xaU[$i] ?? null) && nu_valid_date(trim($xaU[$i])) ? trim($xaU[$i]) : ''];
		header('Content-Type: application/json; charset=utf-8');
		exit((string)json_encode(['ok' => true] + NuUserAccess::effective(nu_post('sus_zzzzsys_access_id'), $rowsIn), JSON_UNESCAPED_UNICODE));
	}

	// ------------------------------------------------------------------ own titles of Code / Additional 1 / 2 (core/nuuserlabel.php): the
	// globeadmin renames them from the form (JSON for assets/js/nuuser.js) – written to nuBuilder Forte's settings table or nuconfig.php
	private static function userLabel(): never
	{
		header('Content-Type: application/json; charset=utf-8');
		$key = nu_post('key');
		if (!NuUserLabel::canRename() || NuUserLabel::setting($key) === '') { http_response_code(403); exit((string)json_encode(['ok' => false, 'error' => t('err_forbidden')])); }
		if (!NuSecurity::recentAuth()) {                               // writes nuconfig.php / settings: password again as on the editor
			$_SESSION[NuSess::REAUTH_NEXT] = NuAuth::validNext(nu_post('back')) ?? nu_url('users');
			exit((string)json_encode(['ok' => false, 'reauth' => nu_url('reauth')]));
		}
		$err = NuUserLabel::save($key, nu_post('value'));
		if ($err !== '') exit((string)json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE));
		$own = trim(nu_post('value'));
		exit((string)json_encode(['ok' => true, 'label' => $own !== '' ? NuTranslate::format($own, []) : nu_text($key), 'value' => $own, 'message' => t('user_lbl_saved')], JSON_UNESCAPED_UNICODE));
	}

	// ------------------------------------------------------------------ permission items of another user: "Copy from user…" ticks them
	// in the form (Replace / Add, assets/js/nuuser.js) – nothing is saved here, the form's Save writes them as usual
	private static function permOf(): never
	{
		$db = nu_db();
		$from  = nu_post('from');
		$known = $from !== '' && (int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$from]) > 0;
		$ids   = $known ? array_map('strval', array_keys(array_filter(NuPermission::forUser($from), static fn($a) => $a === '1'))) : [];
		header('Content-Type: application/json; charset=utf-8');
		exit((string)json_encode($known ? ['ok' => true, 'items' => $ids] : ['ok' => false], JSON_UNESCAPED_UNICODE));
	}

	// ------------------------------------------------------------------ archive: Valid until = yesterday (login no longer possible, data stays)
	private static function archive(?array $existing, string $id, array $me, array $rights): never
	{
		$db = nu_db();
		NuAcl::require(NuAcl::FORM_USER, 'save');
		self::guardAdmin($existing, $me, $id);
		if ($existing['zzzzsys_user_id'] === $me['id']) {
			nu_flash('danger', t('user_err_archive_self'));
			nu_redirect(self::backToList());
		}
		$until = date('Y-m-d', strtotime('-1 day'));
		$db->update('zzzzsys_user', ['sus_expires_on' => $db->datetime($until)], 'zzzzsys_user_id', $id);
		NuUserJson::stamp($id, false, 'form');
		NuLog::write('user_archived', 'zzzzsys_user', $id, ['login' => $existing['sus_login_name'], 'expires_on' => $until,
			'previous' => nu_date_only($existing['sus_expires_on'])]);
		nu_flash('success', t('user_archived', $existing['sus_login_name'], date(nu_dfmt('d.m.Y'), strtotime($until))));
		nu_redirect(nu_post('stay') === '1' ? nu_url('user', ['id' => $id]) : self::backToList());
	}

	// ------------------------------------------------------------------ delete
	private static function delete(?array $existing, string $id, array $me, array $rights): never
	{
		$db = nu_db();
		NuAcl::require(NuAcl::FORM_USER, 'delete');
		self::guardAdmin($existing, $me, $id);
		if ($existing['zzzzsys_user_id'] === $me['id']) {
			nu_flash('danger', t('user_err_delete_self'));
			nu_redirect(nu_url('user', ['id' => $id]));
		}
		$db->delete('zzzzsys_user', 'zzzzsys_user_id', $id);
		NuPermission::deleteUser($id);                               // nuBuilder deletes the subform rows (sf_permission) as well
		NuTwoFA::removeTotp($id);
		NuTwoFA::forgetDevices($id);
		NuPasskey::removeAll($id);                                  // passkeys
		NuLog::write('user_deleted', 'zzzzsys_user', $id, ['login' => $existing['sus_login_name']]);
		NuUserFields::forget($id);                                  // custom field values
		NuUserAccess::forgetUser($id);                              // additional access levels
		nu_flash('success', t('user_deleted', $existing['sus_login_name']));
		nu_redirect(self::backToList());
	}

	// ------------------------------------------------------------------ remove a passkey of the user
	private static function passkeyRemove(?array $existing, string $id, array $me, array $rights): never
	{
		NuAcl::require(NuAcl::FORM_USER, 'save');
		self::guardAdmin($existing, $me, $id);
		$pkId = nu_post('pk');
		$pkName = (string)(array_column(NuPasskey::list($id), 'name', 'zzzzsys_nunext_passkey_id')[$pkId] ?? '');
		if (NuPasskey::remove($id, $pkId)) {
			NuLog::write('passkey_removed', NuPasskey::TABLE, $pkId, ['name' => $pkName, 'user' => $existing['sus_login_name'], 'by' => 'admin']);
			nu_flash('success', t('pk_removed', $pkName));
		}
		nu_redirect(nu_url('user', ['id' => $id]));
	}

	// ------------------------------------------------------------------ reset second factor of the user
	private static function reset2fa(?array $existing, string $id, array $me, array $rights): never
	{
		NuAcl::require(NuAcl::FORM_USER, 'save');
		self::guardAdmin($existing, $me, $id);
		NuTwoFA::removeTotp($id);
		NuTwoFA::forgetDevices($id);
		NuLog::write('2fa_reset_by_admin', 'zzzzsys_user', $id, ['login' => $existing['sus_login_name']]);
		nu_flash('success', t('user_2fa_reset_done'));
		nu_redirect(nu_url('user', ['id' => $id]));
	}

	// ------------------------------------------------------------------ auto-login links: revoke all links of this user (globeadmin)
	private static function autologinRevoke(?array $existing, string $id, array $me, array $rights): never
	{
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

	// ------------------------------------------------------------------ one-time login link: nuBuilder's LOGIN_TOKEN (globeadmin)
	private static function loginToken(?array $existing, string $id, array $me, array $rights): never
	{
		if (!$me['globeadmin'] || !empty($me['demo'])) NuAcl::require('__globeadmin_only__');
		try {
			if (nu_post('action') === 'logintoken_clear') {
				NuLoginToken::clear($id);
				nu_flash('success', t('ltok_cleared'));
			} else {
				$min = (int)nu_post('minutes');
				if (!in_array($min, NuLoginToken::CHOICES, true)) $min = 1440;
				$link = NuLoginToken::create($id, $min);
				$_SESSION[NuSess::LOGIN_LINK] = ['id' => $id, 'link' => $link];          // shown once in the box below
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

	// ------------------------------------------------------------------ password reset by an administrator (core/nupwreset.php)
	// a link for the user (e-mail or shown once), optionally the old password blocked at once; never a password the administrator sees
	private static function pwReset(?array $existing, string $id, array $me, array $rights): never
	{
		if (!$rights['save']) NuAcl::require(NuAcl::FORM_USER, 'save');
		self::guardAdmin($existing, $me, $id);
		$back = nu_url('user', ['id' => $id]);
		if (($why = NuPwReset::refusal($existing, $me)) !== null) { nu_flash('danger', $why); nu_redirect($back); }
		NuSecurity::requireRecentAuth($back);
		if (NuPwReset::recent($id) >= NuPwReset::PER_HOUR) { nu_flash('danger', t('pwr_err_rate')); nu_redirect($back); }
		$how = nu_post('how') === 'mail' ? 'mail' : 'link';
		$block = nu_check('pwr_block') === '1';
		$r = NuPwReset::start($existing, $how, $block, $block && nu_check('pwr_passkeys') === '1');
		if ($r['error'] !== null) nu_flash('danger', $r['error']);
		else {
			if ($how === 'mail') nu_flash('success', t('pwr_sent', trim((string)$existing['sus_email'])));
			else { $_SESSION[NuSess::PW_RESET_LINK] = ['id' => $id, 'link' => $r['link']]; nu_flash('success', t('pwr_created')); }
			if ($r['blocked']) nu_flash('warning', t('pwr_blocked_msg'));
		}
		nu_redirect($back . '#pwrBox');
	}

	/**
	 * Admin-equivalent users (their access level may change code, users or rights – NuSecurity::ADMIN_FORMS): with the setting
	 * "Only the globeadmin grants admin rights" (on by default) nobody else may change, archive, delete or reset them.
	 */
	private static function guardAdmin(?array $existing, array $me, string $id): void
	{
		if (!$existing || !NuSecurity::restricted() || $existing['zzzzsys_user_id'] === $me['id']) return;
		$lv = [(string)$existing['sus_zzzzsys_access_id'], ...array_column(NuUserAccess::rowsOf((string)$existing['zzzzsys_user_id']), 'access_id')];
		if (!array_filter($lv, static fn($a) => (bool)NuSecurity::adminRightsOf((string)$a))) return;   // also through additional levels
		nu_flash('danger', t('secchk_err_admin_user'));
		nu_redirect(nu_url('user', ['id' => $id]));
	}

	/** After delete / archive from the users list: back to the list with its search, filters, sort and page. */
	private static function backToList(): string
	{
		parse_str((string)($_POST['return'] ?? ''), $q);
		$keep = array_intersect_key(array_filter($q, 'is_string'),
			array_flip(['q', 'view', 'access', 'state', 'pos', 'dept', 'team', 'lang', 'login', 'expires', 'twofa', 'online', 'role', 'sort', 'dir', 'pg']));
		return nu_url('users', $keep);
	}
}
