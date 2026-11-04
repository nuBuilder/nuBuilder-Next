<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Password reset started by an administrator (user form → "Reset password…", 0.8.901).
 * Best practice (NIST SP 800-63B, OWASP): the administrator never sees or chooses the password – the user gets a single-use,
 * time-limited link (the reset page ?p=reset, token kind pw_reset with data 'admin') and sets the password there. A new link
 * replaces older ones (also a welcome link). Optionally the current password is blocked at once (an unknown random password:
 * every session ends, because sessions carry a fingerprint of the password hash) – for a compromised account, then the one-time
 * login link and, when chosen, the passkeys go too. Two-factor authentication stays.
 * Mail: own e-mail template nu_password_reset (user's language first) → nuBuilder Next's read-only template nu_password_reset_template
 * (group nunext, ids nx_pwreset_<lang>, ensureTemplates()) → the built-in text. Placeholders as the welcome e-mail: sus_… fields,
 * #SET_PASSWORD_URL#, #SET_PASSWORD_HOURS#, #PASSWORD_MIN_LENGTH#, #LOGIN_URL#, #NUNEXT_URL#, #RECORD_ID#.
 * After the user set the password, the reset page sends a confirmation ("your password was changed", confirm()).
 */
final class NuPwReset
{
	public const HOURS     = 24;                         // validity of an administrator's link (Forgot password: 1 hour)
	public const PER_HOUR  = 5;                          // links per user and hour
	public const TEMPLATE  = 'nu_password_reset';        // own template (a clone of the read-only one)
	public const STD       = 'nu_password_reset_template';
	public const STD_GROUP = 'nunext';
	public const STD_ID    = 'nx_pwreset_';              // + language code; not "nu…": a nuBuilder Forte update replaces only its own rows
	public const DATA      = 'admin';                    // token data of these links (the reset page sends the confirmation for them)

	/**
	 * Why the reset is not offered for this user (null = it is): own record, demo, API service user, expired account.
	 * The admin-equivalent rule (NuSecurity::restricted) is checked by the user form's guard.
	 */
	public static function refusal(array $user, array $me): ?string
	{
		$uid = (string)$user['zzzzsys_user_id'];
		if ($uid === (string)($me['id'] ?? '')) return t('pwr_err_own');
		if (!empty($me['demo']) || NuDemo::isDemoUser($uid)) return t('demo_blocked');
		if (class_exists('NuApiClient') && NuApiClient::isServiceUser($uid)) return t('pwr_err_service');
		if (nu_is_expired($user['sus_expires_on'] ?? null)) return t('pwr_err_expired');
		return null;
	}

	/** Links sent for this user in the last hour (counted in the log). */
	public static function recent(string $uid): int
	{
		try {
			return (int)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = ? AND object = ? AND record_id = ? AND log_time > ?',
				['password_reset_link', 'zzzzsys_user', $uid, date('Y-m-d H:i:s', time() - 3600)]);
		} catch (Throwable) {
			return 0;
		}
	}

	/** Can the link be e-mailed (mail set up, a valid address)? null = yes, else the reason. */
	public static function mailProblem(array $user): ?string
	{
		$email = trim((string)($user['sus_email'] ?? ''));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return t('welcome_err_email');
		if (!NuMail::configured()) return t('welcome_err_mail');
		return null;
	}

	/**
	 * Creates the link (older reset links of the user are removed), optionally blocks the password, e-mails it or returns it.
	 * @param string $how 'mail' | 'link'
	 * @return array{link: string, error: ?string, blocked: bool, passkeys: int}
	 */
	public static function start(array $user, string $how, bool $block, bool $passkeys): array
	{
		$uid = (string)$user['zzzzsys_user_id'];
		NuToken::deleteFor('pw_reset', $uid);
		$token = NuToken::create('pw_reset', $uid, self::HOURS * 3600, '', self::DATA);
		$base = NuSecurity::mailBaseUrl() ?: nu_base_url();
		$link = $base . '/index.php?' . http_build_query(['p' => 'reset', 'u' => $uid, 't' => $token]);
		$err = null;
		if ($how === 'mail') {
			$err = self::mailProblem($user) ?? self::send($user, $link);
			if ($err !== null) NuToken::deleteFor('pw_reset', $uid);        // nothing reached the user: no link stays open
		}
		$removed = 0;
		if ($err === null && $block) {
			nu_db()->update('zzzzsys_user', ['sus_login_password' => NuAuth::hash(bin2hex(random_bytes(24)))], 'zzzzsys_user_id', $uid);
			self::endSessions($uid);
			try { NuLoginToken::clear($uid, 'password reset'); } catch (Throwable) { /* none */ }
			if ($passkeys) $removed = NuPasskey::removeAll($uid);
			NuLog::write('password_reset_blocked', 'zzzzsys_user', $uid, ['passkeys' => $removed], ['id' => $uid, 'login' => (string)$user['sus_login_name']]);
		}
		if ($err === null) {
			NuLog::write('password_reset_link', 'zzzzsys_user', $uid, ['how' => $how, 'blocked' => $block ? 1 : 0, 'hours' => self::HOURS] + ($how === 'mail' ? ['to' => trim((string)$user['sus_email'])] : []),
				['id' => $uid, 'login' => (string)$user['sus_login_name']]);
		}
		return ['link' => $how === 'link' && $err === null ? $link : '', 'error' => $err, 'blocked' => $err === null && $block, 'passkeys' => $removed];
	}

	/** Every nuBuilder Next session of the user ends (their rows go; the changed password hash ends them anyway). */
	public static function endSessions(string $uid): void
	{
		try {
			foreach (nu_db()->all('SELECT zzzzsys_nunext_session_id FROM zzzzsys_nunext_session WHERE user_id = ?', [$uid]) as $r) NuSessions::end((string)$r['zzzzsys_nunext_session_id']);
		} catch (Throwable) {
			/* no session table yet */
		}
	}

	/** The e-mail with the link, in the user's language. @return string|null error */
	public static function send(array $user, string $link): ?string
	{
		[$subject, $html, $source, $cc, $bcc] = NuWelcome::inUserLang($user, static fn(string $lang) => self::content($user, self::hash($user, $link), $lang));
		$err = NuMail::send(trim((string)$user['sus_email']), $subject, $html, 'nunext_pwreset', $cc, $bcc);
		if ($err !== null) NuLog::write('password_reset_mail_failed', 'zzzzsys_user', (string)$user['zzzzsys_user_id'], ['source' => $source, 'error' => $err]);
		return $err;
	}

	/** The e-mail as send() would build it, with #SET_PASSWORD_URL# for the link (preview of the dialog). @return array{subject: string, html: string, source: string} */
	public static function preview(array $user): array
	{
		[$subject, $html, $source] = NuWelcome::inUserLang($user, static fn(string $lang) => self::content($user, self::hash($user, NuWelcome::MARKER), $lang));
		return ['subject' => $subject, 'html' => $html, 'source' => $source];
	}

	/** Confirmation after the password was set through an administrator's or a "Forgot password" link (pages/nureset.php). */
	public static function confirm(array $user): void
	{
		if (self::mailProblem($user) !== null) return;
		try {
			[$subject, $html] = NuWelcome::inUserLang($user, static function () use ($user): array {
				$name = trim((string)($user['sus_name'] ?? '')) ?: (string)$user['sus_login_name'];
				$p = static fn(string $s) => '<p style="margin:0 0 14px">' . $s . '</p>';
				$body = $p(str_replace('%s', h($name), h(t('welcome_hello')))) . $p(h(t('pwr_done_text', (string)$user['sus_login_name'], date(nu_dfmt('d.m.Y H:i')))))
					. $p('<span style="font-size:13px;color:#7a8394">' . h(t('pwr_done_hint')) . '</span>');
				return [t('pwr_done_subject'), NuMail::layout(t('pwr_done_subject'), $body)];
			});
			NuMail::send(trim((string)$user['sus_email']), $subject, $html, 'nunext_pwreset_done');
		} catch (Throwable $e) {
			error_log('nuBuilder Next password changed mail: ' . $e->getMessage());
		}
	}

	private static function hash(array $user, string $link): array
	{
		$base = nu_base_url();
		$hash = [];
		foreach ($user as $k => $v) if ($k !== 'sus_login_password' && $k !== 'sus_json' && (is_scalar($v) || $v === null)) $hash[$k] = (string)$v;
		return $hash + ['RECORD_ID' => (string)$user['zzzzsys_user_id'], 'LOGIN_URL' => $base . '/index.php?p=login', 'NUNEXT_URL' => $base . '/',
			'SET_PASSWORD_URL' => $link, 'SET_PASSWORD_HOURS' => (string)self::HOURS, 'PASSWORD_MIN_LENGTH' => (string)NuPwPolicy::minLength()];
	}

	/** @return array{0: string, 1: string, 2: string, 3: list<string>, 4: list<string>} subject, HTML, source, cc, bcc */
	private static function content(array $user, array $hash, string $lang): array
	{
		$db = nu_db();
		$tpl = null;
		$src = 'builtin';
		if ($db->tableExists(NuEmailTemplate::TABLE)) {
			foreach (array_values(array_unique([trim((string)($user['sus_language'] ?? '')), nu_lang_to_db($lang), ''])) as $l) {
				$tpl ??= $db->one('SELECT * FROM ' . NuEmailTemplate::TABLE . " WHERE emt_code = ? AND COALESCE(emt_language,'') = ? AND COALESCE(emt_template,'') <> '1'", [self::TEMPLATE, $l]);
			}
			$src = $tpl ? 'template' : 'standard';
			if (!$tpl) {
				foreach (array_values(array_unique([trim((string)($user['sus_language'] ?? '')), nu_lang_to_db($lang), 'English'])) as $l) {
					$tpl ??= $db->one('SELECT * FROM ' . NuEmailTemplate::TABLE . " WHERE emt_code = ? AND emt_group = ? AND emt_template = '1' AND COALESCE(emt_language,'') = ?", [self::STD, self::STD_GROUP, $l]);
				}
			}
		}
		if ($tpl) {
			$list = static fn(string $v) => array_values(array_filter(array_map('trim', preg_split('/[,;]/', NuHash::replace($v, $hash, NuHash::RAW)) ?: []),
				static fn($a) => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)));
			$esc = []; foreach ($hash as $k => $v) $esc[$k] = h($v);
			$body = NuHash::replace((string)$tpl['emt_body'], $esc, NuHash::RAW);
			if ($body === strip_tags($body)) $body = nl2br($body);
			$subject = NuHash::replace((string)$tpl['emt_subject'], $hash, NuHash::RAW) ?: t('pwr_subject');
			return [$subject, NuMail::layout($subject, $body), $src, $list((string)($tpl['emt_cc'] ?? '')), $list((string)($tpl['emt_bcc'] ?? ''))];
		}
		$name = trim((string)($user['sus_name'] ?? '')) ?: (string)$user['sus_login_name'];
		return [t('pwr_subject'), NuMail::layout(t('pwr_subject'), self::body(h($name), h((string)$user['sus_login_name']), h($hash['SET_PASSWORD_URL']),
			h(t('pwr_valid', self::HOURS, $hash['PASSWORD_MIN_LENGTH'])))), 'builtin', [], []];
	}

	/** The text of the mail – built-in and the read-only templates; every argument is HTML already. */
	private static function body(string $name, string $login, string $setUrl, string $valid): string
	{
		$p = static fn(string $s) => '<p style="margin:0 0 14px">' . $s . '</p>';
		$small = static fn(string $s) => $p('<span style="font-size:13px;color:#7a8394">' . $s . '</span>');
		return $p(str_replace('%s', $name, h(t('welcome_hello'))))
			. $p(str_replace('%s', $login, h(t('pwr_intro'))))
			. '<p style="margin:22px 0"><a href="' . $setUrl . '" style="background:#2f6fd6;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">'
			. h(t('pwr_mail_button')) . '</a></p>'
			. $small($valid)
			. $small(h(t('pwr_not_you')));
	}

	/** Read-only templates nu_password_reset_template, one per installed language, added when missing, never changed. @return int rows added */
	public static function ensureTemplates(): int
	{
		$db = nu_db();
		if (!$db->tableExists(NuEmailTemplate::TABLE)) return 0;
		$cols = $db->columns(NuEmailTemplate::TABLE);
		$n = 0;
		foreach (NuLanguages::installed() as $l) {
			$id = self::STD_ID . $l;
			if ((int)$db->value('SELECT COUNT(*) FROM ' . NuEmailTemplate::TABLE . ' WHERE zzzzsys_email_template_id = ?', [$id]) > 0) continue;
			$row = NuWelcome::inLang($l, static fn() => [
				'zzzzsys_email_template_id' => $id, 'emt_code' => self::STD, 'emt_group' => self::STD_GROUP, 'emt_category' => 'Email', 'emt_template' => '1',
				'emt_language' => nu_lang_to_db($l), 'emt_description' => mb_substr(t('pwr_tpl_desc'), 0, 200), 'emt_subject' => t('pwr_subject'),
				'emt_body' => self::body('#sus_name#', '#sus_login_name#', '#SET_PASSWORD_URL#',
					str_replace(['99999', '88888'], ['#SET_PASSWORD_HOURS#', '#PASSWORD_MIN_LENGTH#'], h(t('pwr_valid', 99999, '88888')))),
				'emt_to' => '#sus_email#', 'emt_cc' => '', 'emt_bcc' => '', 'emt_form_id' => NuAcl::FORM_USER]);
			$db->insert(NuEmailTemplate::TABLE, array_intersect_key($row, $cols));
			$n++;
		}
		return $n;
	}

	public static function pruneTemplates(array $codes): void
	{
		$db = nu_db();
		if (!$codes || !$db->tableExists(NuEmailTemplate::TABLE)) return;
		foreach ($codes as $x) $db->run('DELETE FROM ' . NuEmailTemplate::TABLE . ' WHERE zzzzsys_email_template_id = ?', [self::STD_ID . $x]);
	}
}
