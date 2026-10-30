<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Login against zzzzsys_user (sus_login_name / sus_login_password) and the globeadmin from nuconfig.php,
 * with optional second factor (NuTwoFA) and optional shared login with a nuBuilder session on the same domain.
 * Sessions are nuBuilder Next's own (cookie "nunext"); nuBuilder's zzzzsys_session is only read.
 */
final class NuAuth
{
	private const PENDING_TTL  = 900;      // seconds to finish the second factor

	private static ?array $user = null;
	private static bool $loaded = false;

	// ----------------------------------------------------------------- password hashing

	/** Returns [bool ok, bool legacy]. Accepts password_hash() hashes and legacy md5. */
	public static function verifyPassword(string $plain, string $stored): array
	{
		$stored = trim($stored);
		if ($stored === '') return [false, false];

		if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $stored)) {
			return [password_verify($plain, $stored), false];
		}
		if (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
			return [hash_equals(strtolower($stored), md5($plain)), true];
		}
		return [false, false];
	}

	/**
	 * nuBuilder's $nuUseMd5PasswordHash (0.8.336): true = passwords are stored as md5 and never converted – nuBuilder then accepts md5
	 * only, so a bcrypt hash written here would lock the user out of nuBuilder. Insecure: the Security check shows it in red.
	 */
	public static function md5Storage(): bool
	{
		$v = nu_cfg('nuUseMd5PasswordHash');
		return $v === true || in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
	}

	public static function hash(string $plain): string
	{
		if (self::md5Storage()) return md5($plain);
		return password_hash($plain, PASSWORD_DEFAULT);
	}

	/** Short fingerprint of the stored hash: a password change elsewhere ends our sessions. */
	public static function fingerprint(string $hash): string
	{
		return substr(hash('sha256', $hash), 0, 16);
	}

	// ----------------------------------------------------------------- login / logout

	/**
	 * @return string|null  null = logged in, '2fa' = second factor needed (see pending()), otherwise a translation key
	 */
	public static function login(string $login, string $password, bool $byHash = false): ?string
	{
		$login = trim($login);
		if ($login === '' || $password === '') return 'login_err_empty';

		// rate limit in the database (failed logins of the last 15 minutes per name, name + address and address – see NuSecurity),
		// so a client without cookies cannot reset it (before 0.8.207 the counter lived in the PHP session)
		if (NuSecurity::loginBlocked($login)) {
			password_hash($password, PASSWORD_DEFAULT);           // same time as a real check
			NuLog::write('login_locked', '', '', ['login' => $login] + ($byHash ? ['via' => 'url'] : []), ['id' => '', 'login' => $login]);
			return 'login_err_locked';
		}

		// database not set up yet (0.8.645, NuDbState): only the globeadmin of nuconfig.php can sign in – he is sent to the set-up
		if (!NuDbState::ready() && !hash_equals((string)nu_cfg('nuConfigDBGlobeadminUsername'), $login)) {
			password_hash($password, PASSWORD_DEFAULT);
			return 'login_not_ready';
		}
		$result = self::attempt($login, $password, $byHash);

		if ($result !== null && $result !== '2fa') {
			if ($result === 'login_err_invalid') usleep(random_int(250_000, 600_000));
			NuLog::write('login_failed', '', '', ['reason' => $result] + ($byHash ? ['via' => 'url'] : []), ['id' => '', 'login' => $login]);
			return self::invalidLoginHook($login) ?? $result;
		}
		return $result === null ? self::startupHook() : $result;
	}

	/**
	 * nuBuilder's global hooks of the login (0.8.462, gap analysis B5): nu_startup after every login (also by link, demo button,
	 * second factor) – a text in its $error ends the new session and is shown instead (nuBuilder: nuDie($error)); the shared login
	 * from nuBuilder does not run it again (nuBuilder did). @return string|null null = logged in, otherwise the message
	 */
	private static function startupHook(): ?string
	{
		if (empty($_SESSION['nu_auth']['globeadmin']) && !NuSchema::present()) {   // not set up yet: only the globeadmin (0.8.462, B2)
			unset($_SESSION['nu_auth']);
			self::$user = null;
			self::$loaded = false;
			return t('login_not_ready');
		}
		try {
			$hash = NuHash::base();
		} catch (Throwable) {
			$hash = [];
		}
		NuPhp::reset();
		if (NuProcedure::hook('nu_startup', $hash)) return null;
		$msg = NuPhp::$error !== '' ? NuPhp::$error : (string)(NuPhp::$errors[0]['message'] ?? t('login_err_invalid'));
		$u = self::user();
		NuLog::write('login_refused', '', '', ['hook' => 'nu_startup', 'message' => mb_substr($msg, 0, 300)], $u ? ['id' => (string)$u['id'], 'login' => (string)$u['login']] : null);
		unset($_SESSION['nu_auth']);
		self::$user = null;
		self::$loaded = false;
		return $msg;
	}

	/** nu_invalid_login after a failed login (0.8.462): #LOGIN_NAME# = the name typed; a text in $error is shown instead of the usual message. */
	private static function invalidLoginHook(string $login): ?string
	{
		NuPhp::reset();
		$ok = NuProcedure::hook('nu_invalid_login', ['LOGIN_NAME' => mb_substr($login, 0, 100), 'USER_ID' => '', 'RECORD_ID' => '', 'FORM_ID' => '']);
		return !$ok && NuPhp::$error !== '' ? NuPhp::$error : null;
	}

	/**
	 * nuBuilder's single-use login token (index.php?u=<login name or user id>&p=<token>, core/nulogintoken.php, 0.8.339).
	 * Like nuBuilder no second factor; the token is replaced at once. @return string|null  null = logged in, otherwise a translation key
	 */
	public static function loginToken(string $user, string $token): ?string
	{
		$user = trim($user);
		if ($user === '' || $token === '') return 'login_err_empty';
		$fail = static function (string $reason) use ($user): string {
			if ($reason === 'login_err_invalid') usleep(random_int(250_000, 600_000));
			NuLog::write('login_failed', '', '', ['reason' => $reason, 'via' => 'token'], ['id' => '', 'login' => $user]);
			return self::invalidLoginHook($user) ?? $reason;
		};
		if (!NuLoginToken::enabled()) return $fail('login_err_invalid');
		if (NuSecurity::loginBlocked($user)) {
			NuLog::write('login_locked', '', '', ['login' => $user, 'via' => 'token'], ['id' => '', 'login' => $user]);
			return 'login_err_locked';
		}
		$adminName = (string)nu_cfg('nuConfigDBGlobeadminUsername');
		if ($adminName !== '' && hash_equals($adminName, $user)) return $fail('login_err_invalid');          // never the globeadmin
		$row = NuLoginToken::findUser($user);
		if (!$row || (class_exists('NuApiClient') && NuApiClient::isServiceUser((string)$row['zzzzsys_user_id']))) return $fail('login_err_invalid');
		$state = NuLoginToken::check($row, $token);
		if ($state === 'expired') { NuLoginToken::rotate($row); return $fail('login_err_token_expired'); }
		if ($state !== 'ok') return $fail('login_err_invalid');
		if (nu_is_expired($row['sus_expires_on'])) return $fail('login_err_expired');
		$accessId = (string)$row['sus_zzzzsys_access_id'];
		if ($accessId === '' || !nu_db()->one('SELECT zzzzsys_access_id FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$accessId])) return $fail('login_err_no_access');
		if (!NuLoginToken::rotate($row)) return $fail('login_err_invalid');                                 // used by a second click at the same moment
		self::applyLanguage((string)$row['sus_language'], (string)$row['zzzzsys_user_id'], true);
		$auth = ['id' => (string)$row['zzzzsys_user_id'], 'globeadmin' => false, 'fp' => self::fingerprint((string)$row['sus_login_password']), 'login' => (string)$row['sus_login_name']];
		self::start($auth + ['via' => 'token']);
		NuLog::write('login', '', '', ['via' => 'token'], $auth);
		return self::startupHook();
	}

	/**
	 * Password hash for nuBuilder's auto login (index.php?u=<login>&p=<hash>[&e=<expiry>]): HMAC-SHA256 of the stored password hash
	 * with $nuConfigSecretKey – the stored hash itself never leaves the server, and the link stops working when the password changes.
	 * Since 0.8.207 a link can expire (e = Unix time, signed) and all links of a user can be revoked (epoch in sus_json
	 * NUNEXT_AUTOLOGIN_EPOCH, raised by "Revoke links"). Links without e and epoch 0 have the format of older versions.
	 */
	public static function urlHash(string $storedHash, int $expires = 0, int $epoch = 0): string
	{
		$msg = 'nunext-autologin|' . $storedHash . ($expires > 0 || $epoch > 0 ? '|' . $epoch . '|' . $expires : '');
		return hash_hmac('sha256', $msg, (string)nu_cfg('nuConfigSecretKey'));
	}

	/** Auto-login link of a user ($days 0 = no expiry; the epoch of NUNEXT_AUTOLOGIN_EPOCH is part of the hash). */
	public static function autoLoginLink(array $userRow, int $days): string
	{
		$epoch = (int)NuUserJson::get((string)$userRow['zzzzsys_user_id'], 'NUNEXT_AUTOLOGIN_EPOCH');
		$exp = $days > 0 ? time() + $days * 86400 : 0;
		$q = ['u' => (string)$userRow['sus_login_name'], 'p' => self::urlHash((string)$userRow['sus_login_password'], $exp, $epoch)];
		if ($exp > 0) $q['e'] = (string)$exp;
		return nu_base_url() . '/index.php?' . http_build_query($q);
	}

	/** Expiry of the auto-login request being checked (index.php?…&e=), set by pages/nuautologin.php. */
	public static int $urlExpires = 0;

	private const NO_NEXT = ['login', 'logout', 'lang', 'twofa', 'forgot', 'reset', 'fa', 'install', 'reauth'];

	/** Relative URL to open after the next login (only index.php?p=… of this installation, not the login pages themselves). */
	public static function setNext(string $url): void
	{
		$url = self::validNext($url);
		if ($url !== null) $_SESSION['nu_login_next'] = $url;
	}

	/** index.php?p=<page>… of this installation, or null (no other host, no login / logout page, no auto-login link). */
	public static function validNext(string $url): ?string
	{
		if (strlen($url) > 2000 || !preg_match('~^index\.php\?[^\s\\\\]*$~', $url) || str_contains($url, '//')) return null;
		parse_str((string)substr($url, 10), $q);
		$p = $q['p'] ?? null;
		if (!is_string($p) || $p === '' || in_array($p, self::NO_NEXT, true) || isset($q['u'])) return null;
		return $url;
	}

	/** The page a POST / background request came from (same host, index.php?…), to return there after the login. */
	public static function nextFromReferer(): ?string
	{
		$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
		$u = $ref !== '' ? parse_url($ref) : false;
		if (!$u || strcasecmp((string)($u['host'] ?? ''), (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) !== 0) return null;
		if (!str_ends_with((string)($u['path'] ?? ''), '/index.php') || ($u['query'] ?? '') === '') return null;
		return self::validNext('index.php?' . $u['query']);
	}

	/** Login page that returns to $next afterwards (the target is in the URL too, so it survives a lost session and works per tab). */
	public static function loginUrl(?string $next): string
	{
		$next = $next !== null ? self::validNext($next) : null;
		return nu_url('login', $next !== null ? ['next' => $next] : []);
	}

	public static function takeNext(): string
	{
		$n = (string)($_SESSION['nu_login_next'] ?? '');
		unset($_SESSION['nu_login_next']);
		return $n !== '' ? $n : nu_url('home');
	}

	private static bool $byUrl = false;

	private static function attempt(string $login, string $password, bool $byHash = false): ?string
	{
		self::$byUrl = $byHash;
		$adminName = (string)nu_cfg('nuConfigDBGlobeadminUsername');
		$adminPass = (string)nu_cfg('nuConfigDBGlobeadminPassword');

		// nuBuilder's demo globeadmin ($nuConfigIsDemo, 0.8.341): read-only demo session as the globeadmin – the real globeadmin wins
		$ga = $byHash ? null : NuDemo::gaDemo();
		if ($ga && hash_equals($ga[0], $login) && hash_equals($ga[1], $password) && !($adminName !== '' && hash_equals($adminName, $login) && hash_equals($adminPass, $password))) {
			self::applyLanguage(null, '', true);
			self::start(['id' => 'globeadmin', 'globeadmin' => true, 'fp' => NuDemo::gaFingerprint(), 'login' => $ga[0], 'via' => 'demo_ga']);
			NuLog::write('login', '', '', ['via' => 'demo globeadmin'], ['id' => 'globeadmin', 'login' => $ga[0]]);
			return null;
		}

		// globeadmin first, exactly like nuBuilder
		if ($adminName !== '' && hash_equals($adminName, $login)) {
			if ($byHash || !hash_equals($adminPass, $password)) return 'login_err_invalid';   // no auto login for the globeadmin
			if (in_array($adminPass, ['nu', 'globeadmin', 'admin', 'password', '1234', '12345678'], true) || strlen($adminPass) < 8) $_SESSION['nu_weak_admin'] = 1;
			// language: one picked on the sign-in page, else nuBuilder's default language (Setup → set_language, as nuBuilder does) – 0.8.312
			self::applyLanguage(null, '', true);
			return self::afterPassword(['id' => 'globeadmin', 'globeadmin' => true, 'fp' => self::adminFingerprint(), 'login' => $adminName],
				(bool)nu_cfg('nuConfig2FAAdmin'), (string)(nu_cfg('nuConfigGlobeadminEmail') ?: NuMail::settings()['from']));
		}

		$db  = nu_db();
		$row = $db->one('SELECT zzzzsys_user_id, sus_login_name, sus_login_password, sus_expires_on, sus_zzzzsys_access_id, sus_change_password, sus_language, sus_email
			FROM zzzzsys_user WHERE sus_login_name = ?', [$login]);

		if (!$row) {
			password_hash($password, PASSWORD_DEFAULT);   // spend the same time as a real check (no user enumeration)
			return 'login_err_invalid';
		}

		if (class_exists('NuApiClient') && NuApiClient::isServiceUser((string)$row['zzzzsys_user_id'])) {   // API service users never log in
			password_hash($password, PASSWORD_DEFAULT);
			return 'login_err_invalid';
		}
		if ($byHash) {
			$exp = self::$urlExpires;
			$epoch = (int)NuUserJson::get((string)$row['zzzzsys_user_id'], 'NUNEXT_AUTOLOGIN_EPOCH');
			$ok = (string)$row['sus_login_password'] !== '' && ($exp === 0 || $exp >= time())
				&& hash_equals(self::urlHash((string)$row['sus_login_password'], $exp, $epoch), strtolower($password));
			$legacy = false;
		} else {
			[$ok, $legacy] = self::verifyPassword($password, (string)$row['sus_login_password']);
		}
		if (!$ok) return 'login_err_invalid';
		if ($legacy && !NuSecurity::get('md5_login') && !self::md5Storage()) return 'login_err_md5';   // md5 storage: md5 is the format     // Admin tools → Security check: md5 refused

		if (nu_is_expired($row['sus_expires_on'])) return 'login_err_expired';

		$accessId = (string)$row['sus_zzzzsys_access_id'];
		$access   = $accessId !== '' ? $db->one('SELECT zzzzsys_access_id, sal_use_2fa FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$accessId]) : null;
		if (!$access) return 'login_err_no_access';

		$hash = (string)$row['sus_login_password'];
		if (!$byHash && !self::md5Storage() && nu_cfg('nuConfigPasswordUpgrade') && ($legacy || password_needs_rehash($hash, PASSWORD_DEFAULT))) {
			$hash = self::hash($password);
			$db->update('zzzzsys_user', ['sus_login_password' => $hash], 'zzzzsys_user_id', $row['zzzzsys_user_id']);
		}

		self::applyLanguage((string)$row['sus_language'], (string)$row['zzzzsys_user_id'], true);

		$need2fa = (bool)nu_cfg('nuConfig2FAUser') && self::levels2fa((string)$access['sal_use_2fa'] === '1', (string)$row['zzzzsys_user_id'])
			&& !NuDemo::isDemoUser((string)$row['zzzzsys_user_id']);   // demo users: no second factor
		return self::afterPassword(['id' => (string)$row['zzzzsys_user_id'], 'globeadmin' => false, 'fp' => self::fingerprint($hash), 'login' => (string)$row['sus_login_name']],
			$need2fa, (string)$row['sus_email']);
	}

	/** Two-factor login required: by the main access level or by any active additional level (0.8.585, NuUserAccess). */
	public static function levels2fa(bool $main, string $userId): bool
	{
		if ($main) return true;
		$extra = NuUserAccess::extraIds($userId);
		if (!$extra) return false;
		return (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_access WHERE sal_use_2fa = ? AND zzzzsys_access_id IN (' . implode(', ', array_fill(0, count($extra), '?')) . ')', ['1', ...$extra]);
	}

	/** Password was correct: log in now, or ask for the second factor. */
	private static function afterPassword(array $auth, bool $need2fa, string $email): ?string
	{
		if ($need2fa && !NuTwoFA::trustedDevice($auth['id'])) {
			session_regenerate_id(true);
			unset($_SESSION['nu_auth']);
			$_SESSION['nu_2fa'] = ['auth' => $auth, 'email' => $email, 'since' => time(), 'sent' => 0, 'fails' => 0];
			self::$loaded = false;
			return '2fa';
		}
		self::start($auth);
		NuLog::write('login', '', '', ($need2fa ? ['2fa' => 'trusted device'] : []) + (self::$byUrl ? ['via' => 'url'] : []), $auth);
		return null;
	}

	/** Pending second-factor login, or null (expired pendings are dropped). */
	public static function pending(): ?array
	{
		$p = $_SESSION['nu_2fa'] ?? null;
		if (!$p) return null;
		if (time() - (int)$p['since'] > self::PENDING_TTL) {
			unset($_SESSION['nu_2fa']);
			return null;
		}
		return $p;
	}

	public static function updatePending(array $p): void
	{
		$_SESSION['nu_2fa'] = $p;
	}

	/** Second factor confirmed: turn the pending login into a session. @return string|null message of nu_startup when it refused (0.8.462) */
	public static function completePending(string $method, bool $trustDevice): ?string
	{
		$p = self::pending();
		if (!$p) return null;
		unset($_SESSION['nu_2fa']);
		if ($trustDevice) NuTwoFA::trustDevice($p['auth']['id']);
		self::start($p['auth']);
		NuLog::write('login', '', '', ['2fa' => $method], $p['auth']);
		return self::startupHook();
	}

	/** REST API request: acts as the client's service user for this request only (no session, rights of its access level). */
	public static function actAs(array $row): void
	{
		$full = trim(($row['sus_first_name'] ?? '') . ' ' . ($row['sus_last_name'] ?? ''));
		self::$user = [
			'id' => (string)$row['zzzzsys_user_id'], 'globeadmin' => false, 'login' => (string)$row['sus_login_name'],
			'display' => $full !== '' ? $full : ((string)($row['sus_name'] ?? '') ?: (string)$row['sus_login_name']),
			'access_id' => (string)$row['sus_zzzzsys_access_id'], 'email' => (string)($row['sus_email'] ?? ''), 'change_password' => false, 'via' => 'api',
		];
		self::$loaded = true;
	}

	/** "Try the demo" on the login page: logs in as a demo user without a password (core/nudemo.php). Error key or null. */
	public static function loginDemo(string $userId = ''): ?string
	{
		$ok = NuDemo::loginable();
		if (!$ok) return 'demo_unavailable';
		$u = $userId !== '' && isset($ok[$userId]) ? $ok[$userId] : reset($ok);
		if ($u['id'] === 'globeadmin') {                                     // nuBuilder's demo globeadmin ($nuConfigIsDemo)
			self::applyLanguage(null, '', true);
			self::start(['id' => 'globeadmin', 'globeadmin' => true, 'fp' => NuDemo::gaFingerprint(), 'login' => $u['login'], 'via' => 'demo_ga']);
			NuLog::write('login', '', '', ['via' => 'demo button'], ['id' => 'globeadmin', 'login' => $u['login']]);
			return self::startupHook();
		}
		self::start(['id' => $u['id'], 'globeadmin' => false, 'fp' => self::fingerprint($u['hash']), 'login' => $u['login'], 'via' => 'demo']);
		NuLog::write('login', '', '', ['via' => 'demo button'], ['id' => $u['id'], 'login' => $u['login']]);
		return self::startupHook();
	}

	/** Session of another login without password (globeadmin acting as a user, and back) – NuImpersonate only. */
	public static function startAs(array $data): void
	{
		unset($data['since'], $data['last']);
		self::start($data);
		self::$user = null;
	}

	private static function start(array $data): void
	{
		if (!empty($data['globeadmin']) && !NuInstall::existingBlocked() && NuDbState::ready()) {   // the globeadmin's first sign-in sets up the own tables (0.8.462, B2) – not before the database is set up (0.8.645)
			NuSchema::allow();
			NuSchema::ensure();
		}
		session_regenerate_id(true);
		$_SESSION['nu_auth'] = $data + ['since' => time(), 'last' => time(), 'inst' => NuSecurity::installId()];
		self::$loaded = false;
	}

	/** Fingerprint of the globeadmin's name and password in nuconfig.php: changing them ends running globeadmin sessions. */
	public static function adminFingerprint(): string
	{
		return self::fingerprint('globeadmin|' . (string)nu_cfg('nuConfigDBGlobeadminUsername') . '|' . (string)nu_cfg('nuConfigDBGlobeadminPassword'));
	}

	/** Checks the current password (password confirmation for dangerous actions, pages/nureauth.php). */
	public static function checkOwnPassword(string $password): bool
	{
		$u = self::user();
		if (!$u || $password === '') return false;
		if ($u['globeadmin'] && empty($u['demo'])) return hash_equals((string)nu_cfg('nuConfigDBGlobeadminPassword'), $password);
		$hash = (string)nu_db()->value('SELECT sus_login_password FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$u['id']]);
		return self::verifyPassword($password, $hash)[0];
	}

	/**
	 * Language after the sign-in (0.8.312 / 0.8.314). Language: the user's (sus_language), else one picked on the sign-in page, else
	 * nuBuilder's default (Setup → Language). English variant: the user's (sus_json NUNEXT_LANG_VARIANT), else one picked on the
	 * sign-in page, else the installation's default variant, else the browser's (en-US / en-AU / en-GB …).
	 * $userWins = false (nuBuilder session adopted): a language already in the session stays.
	 */
	public static function applyLanguage(?string $susLanguage, string $userId, bool $userWins): void
	{
		$own = nu_lang_from_db($susLanguage);
		$browser = nu_lang_browser();
		$browserVar = $browser !== null ? nu_lang_variant_of($browser[1]) : null;
		$picked = isset($_SESSION['nu_lang']);
		if ($own !== null && ($userWins || !$picked)) nu_set_lang($own);
		elseif (!$picked && ($dl = NuSetupWizard::defaultLang())) nu_set_lang($dl);
		elseif (!$picked) nu_set_lang(nu_lang());                // the browser's language, now fixed in the session
		$lang = nu_lang();
		if (!isset(NU_LANG_VARIANT_DEFAULT[$lang])) return;
		$uv = $userId !== '' ? (string)NuUserJson::get($userId, 'NUNEXT_LANG_VARIANT') : '';
		if (isset(NU_LANG_VARIANTS[$uv]) && NU_LANG_VARIANTS[$uv][0] === $lang && ($userWins || !isset($_SESSION['nu_lang_variant']))) { nu_set_lang($uv); return; }
		if (isset($_SESSION['nu_lang_variant'])) return;      // picked on the sign-in page
		$dv = NuSetupWizard::defaultVariant($lang);
		if ($dv !== null) { nu_set_lang($dv); return; }
		if ($browserVar !== null && NU_LANG_VARIANTS[$browserVar][0] === $lang) nu_set_lang($browserVar);
	}

	public static function logout(): void
	{
		NuSessions::forget();
		$lang = $_SESSION['nu_lang'] ?? null;
		$_SESSION = [];
		session_regenerate_id(true);
		if ($lang) $_SESSION['nu_lang'] = $lang;
		self::$user   = null;
		self::$loaded = true;
	}

	/** Who is acting (for the log), without database validation. */
	public static function currentRaw(): array
	{
		if (self::$user) return ['id' => self::$user['id'], 'login' => self::$user['login']];
		$a = $_SESSION['nu_auth'] ?? ($_SESSION['nu_2fa']['auth'] ?? null);
		return $a ? ['id' => (string)$a['id'], 'login' => (string)($a['login'] ?? '')] : ['id' => '', 'login' => ''];
	}

	// ----------------------------------------------------------------- current user

	/**
	 * The logged-in user, re-validated against the DB on every request, so a deleted, expired
	 * or re-passworded account (also when changed in nuBuilder) is logged out immediately.
	 */
	/**
	 * Unique user code (0.8.626): the user (id, name) that already has $code – trimmed, case-insensitive, empty codes never clash –
	 * or null. nuBuilder Forte does not check it and the column has no unique index (existing duplicates stay until a user is saved).
	 * @return array{id: string, name: string}|null
	 */
	public static function userCodeOwner(string $code, string $exceptId = ''): ?array
	{
		$code = trim($code);
		if ($code === '') return null;
		$r = nu_db()->one('SELECT zzzzsys_user_id AS id, sus_name AS name, sus_login_name AS login FROM zzzzsys_user WHERE LOWER(TRIM(sus_code)) = LOWER(?) AND zzzzsys_user_id <> ?', [$code, $exceptId]);
		return $r ? ['id' => (string)$r['id'], 'name' => trim((string)$r['name']) !== '' ? (string)$r['name'] : (string)$r['login']] : null;
	}

	public static function user(): ?array
	{
		if (self::$loaded) return self::$user;
		self::$loaded = true;
		if (NuInstall::needed()) return self::$user = null;   // no nuconfig.php yet: the installer (its theme menu asked here and stopped the page, 0.8.693)

		$s = $_SESSION['nu_auth'] ?? null;
		if (!$s && nu_cfg('nuConfigSharedLogin') && !isset($_SESSION['nu_2fa'])) {
			$s = NuSharedLogin::adopt();
			if ($s) {
				self::start($s);
				self::$loaded = true;
				$s = $_SESSION['nu_auth'];
			}
		}
		if (!$s) return null;

		// a session of another copy of nuBuilder Next (other folder or database) is not a login here
		if (isset($s['inst']) && $s['inst'] !== NuSecurity::installId()) {
			self::logout();
			return null;
		}

		if (time() - (int)$s['last'] > nu_session_timeout(!empty($s['globeadmin']))) {
			if (NuImpersonate::active()) NuLog::write('impersonate_end', 'zzzzsys_user', (string)$s['id'], ['reason' => 'timeout']);
			self::logout();
			nu_flash('warning', t('login_session_timeout'));
			return null;
		}

		// logged in through nuBuilder: ends when the nuBuilder session ends
		if (($s['via'] ?? '') === 'nubuilder' && !NuSharedLogin::stillValid((string)$s['nb_session'])) {
			self::logout();
			nu_flash('warning', t('login_session_invalid'));
			return null;
		}

		if ($s['globeadmin'] && ($s['via'] ?? '') === 'demo_ga') {
			// nuBuilder's demo globeadmin: ends when $nuConfigIsDemo or its name / password change
			if (!hash_equals(NuDemo::gaFingerprint() ?: '-', (string)($s['fp'] ?? ''))) {
				self::logout();
				nu_flash('warning', t('login_session_invalid'));
				return null;
			}
			$user = ['id' => 'globeadmin', 'globeadmin' => true, 'demo' => true, 'login' => (string)$s['login'], 'display' => 'Globeadmin',
				'access_id' => '', 'email' => '', 'change_password' => false];
			NuDemo::activate();
		} elseif ($s['globeadmin']) {
			// name or password in nuconfig.php changed since the login (not for logins taken over from nuBuilder)
			if (($s['via'] ?? '') !== 'nubuilder' && !hash_equals(self::adminFingerprint(), (string)($s['fp'] ?? ''))) {
				self::logout();
				nu_flash('warning', t('login_session_invalid'));
				return null;
			}
			$user = [
				'id' => 'globeadmin', 'globeadmin' => true, 'login' => (string)nu_cfg('nuConfigDBGlobeadminUsername'),
				'display' => 'Globeadmin', 'access_id' => '', 'email' => (string)(nu_cfg('nuConfigGlobeadminEmail') ?? ''), 'change_password' => false,
			];
		} else {
			$row = nu_db()->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$s['id']]);
			$acting = ($s['via'] ?? '') === 'impersonated' && NuImpersonate::active();
			if ($acting && (!$row || !hash_equals((string)$s['fp'], self::fingerprint((string)$row['sus_login_password'])))) {
				// the user was deleted or got a new password meanwhile: back to the globeadmin instead of a logout
				NuImpersonate::stop('user changed');
				nu_flash('warning', t('imp_ended_changed'));
				self::$loaded = false;
				return self::user();
			}
			if (!$row
				|| (!$acting && nu_is_expired($row['sus_expires_on']))
				|| (($s['via'] ?? '') !== 'nubuilder' && !hash_equals((string)$s['fp'], self::fingerprint((string)$row['sus_login_password'])))) {
				self::logout();
				nu_flash('warning', t('login_session_invalid'));
				return null;
			}
			$full = trim($row['sus_first_name'] . ' ' . $row['sus_last_name']);
			$user = [
				'id'              => $row['zzzzsys_user_id'],
				'globeadmin'      => false,
				'login'           => (string)$row['sus_login_name'],
				'display'         => $full !== '' ? $full : ((string)$row['sus_name'] !== '' ? (string)$row['sus_name'] : (string)$row['sus_login_name']),
				'access_id'       => (string)$row['sus_zzzzsys_access_id'],
				'email'           => (string)$row['sus_email'],
				'change_password' => (string)$row['sus_change_password'] === '1' && !in_array($s['via'] ?? '', ['nubuilder', 'impersonated'], true),
			];
			// demo mode (core/nudemo.php): a demo user sees everything the globeadmin sees – read only from here on
			if (NuDemo::isDemoUser((string)$row['zzzzsys_user_id'])) {
				$user['globeadmin'] = true;
				$user['demo'] = true;
				$user['change_password'] = false;
				NuDemo::activate();
			}
		}

		$user['via'] = (string)($s['via'] ?? '');
		if (!NuSessions::touch($user)) {                 // ended by the globeadmin (Log → Sessions)
			NuLog::write('session_ended', '', '', [], ['id' => $user['id'], 'login' => $user['login']]);
			self::logout();
			nu_flash('warning', t('login_session_ended'));
			return null;
		}
		if (!defined('NU_PASSIVE')) $_SESSION['nu_auth']['last'] = time();   // the bell's check does not count as activity (0.8.357)
		return self::$user = $user;
	}

	/** Call after the current user's hash was changed elsewhere in this request (keeps the session valid). */
	public static function refreshFingerprint(string $newHash): void
	{
		if (isset($_SESSION['nu_auth'])) $_SESSION['nu_auth']['fp'] = self::fingerprint($newHash);
		self::$loaded = false;
	}

	/**
	 * nuBuilder's hook nu_check_password_policy (0.8.462, gap analysis B5) for a new password: the procedure gets $newPassword,
	 * $newPasswordCheck, $will (all the new password), $userId and $check = true, as in nuBuilder's password procedures; it refuses
	 * with $check = false (its nuDisplayError text is shown) or an error. @return string|null null = accepted, otherwise the message
	 */
	public static function passwordPolicy(string $new, string $userId): ?string
	{
		NuPhp::reset();
		$hash = ['USER_ID' => $userId, 'RECORD_ID' => $userId, 'FORM_ID' => 'nuuser'];
		$ok = NuProcedure::hook('nu_check_password_policy', $hash, ['newPassword' => $new, 'newPasswordCheck' => $new, 'will' => $new, 'userId' => $userId, 'check' => true]);
		if ($ok && (NuPhp::$vars['check'] ?? true) !== false) return null;
		return (string)(NuPhp::$errors[0]['message'] ?? (NuPhp::$error !== '' ? NuPhp::$error : t('pw_err_policy')));
	}

	/** Own password change (also clears sus_change_password). */
	public static function changeOwnPassword(string $current, string $new): ?string
	{
		$u = self::user();
		if (!$u || $u['globeadmin']) return 'pw_err_globeadmin';

		$db   = nu_db();
		$hash = (string)$db->value('SELECT sus_login_password FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$u['id']]);
		[$ok] = self::verifyPassword($current, $hash);
		if (!$ok) return 'pw_err_current';
		if (hash_equals($current, $new)) return 'pw_err_same';
		if (($policy = self::passwordPolicy($new, (string)$u['id'])) !== null) return $policy;   // nu_check_password_policy (0.8.462)

		$newHash = self::hash($new);
		$db->update('zzzzsys_user', ['sus_login_password' => $newHash, 'sus_change_password' => '0'], 'zzzzsys_user_id', $u['id']);
		$_SESSION['nu_auth']['fp'] = self::fingerprint($newHash);
		session_regenerate_id(true);
		self::$loaded = false;
		NuLog::write('password_changed', 'zzzzsys_user', $u['id']);
		NuUserJson::passwordChanged((string)$u['id'], 'user');
		return null;
	}
}

/**
 * Shared login with nuBuilder on the same domain ($nuConfigSharedLogin = true):
 * when nobody is logged in to nuBuilder Next, the browser's nuBuilder session (cookie named after the database,
 * like nuBuilder's nuSetSafeSessionName) is read – read-only – and the user it belongs to is logged in.
 * Requires the same server/PHP session storage. Logging out of nuBuilder ends the nuBuilder Next session.
 */
final class NuSharedLogin
{
	public static function sessionName(): string
	{
		$cfg = nu_cfg('nuConfigNuBuilderSessionName');
		if (is_string($cfg) && $cfg !== '') return $cfg;
		$safe = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)nu_cfg('nuConfigDBName'));
		if ($safe === '' || !preg_match('/^[a-zA-Z]/', $safe)) $safe = 'S_' . $safe;
		return substr($safe, 0, 128);
	}

	/** Reads nuBuilder's $_SESSION['nubuilder_session_data'] without locking or changing it. */
	private static function readNuBuilderSession(): ?array
	{
		$name = self::sessionName();
		$id   = $_COOKIE[$name] ?? '';
		if (!is_string($id) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/', $id) || $name === session_name()) return null;

		$ownName = session_name();
		$ownId   = session_id();
		$ownData = $_SESSION;
		session_write_close();

		$data = null;
		try {
			session_name($name);
			session_id($id);
			@session_start(['read_and_close' => true, 'use_cookies' => false, 'use_only_cookies' => false]);
			$data = $_SESSION['nubuilder_session_data'] ?? null;
		} finally {
			$_SESSION = [];
			nu_ini_set('session.use_cookies', '1');            // session_start options persist as ini values
			nu_ini_set('session.use_only_cookies', '1');
			session_name($ownName);
			session_id($ownId);
			@session_start();
			$_SESSION = $ownData + $_SESSION;
		}
		return is_array($data) ? $data : null;
	}

	/** Auth data for a valid nuBuilder login, or null. */
	public static function adopt(): ?array
	{
		$nb = self::readNuBuilderSession();
		if (!$nb) return null;
		$sid = (string)($nb['SESSION_ID'] ?? '');
		if ($sid === '' || $sid === 'tempanonreport') return null;
		if (!empty($nb['IS_DEMO'])) return null;          // nuBuilder's demo login is a globeadmin session – never taken over
		if (($nb['SESSION_2FA_STATUS'] ?? '') === 'PENDING' || ($nb['SESSION_CHANGE_PW_STATUS'] ?? '') === 'PENDING') return null;

		$row = nu_db()->one('SELECT sss_access FROM zzzzsys_session WHERE zzzzsys_session_id = ?', [$sid]);
		if (!$row) return null;
		$access = json_decode((string)$row['sss_access'], true);
		$sess   = $access['session'] ?? null;
		if (!is_array($sess)) return null;

		if ((string)($sess['global_access'] ?? '') === '1') {
			NuAuth::applyLanguage(null, '', false);
			$auth = ['id' => 'globeadmin', 'globeadmin' => true, 'fp' => '', 'login' => (string)nu_cfg('nuConfigDBGlobeadminUsername')];
		} else {
			$uid = (string)($sess['zzzzsys_user_id'] ?? '');
			$u   = $uid !== '' ? nu_db()->one('SELECT zzzzsys_user_id, sus_login_name, sus_expires_on, sus_zzzzsys_access_id, sus_language FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$uid]) : null;
			if (!$u || nu_is_expired($u['sus_expires_on']) || (string)$u['sus_zzzzsys_access_id'] === '') return null;
			NuAuth::applyLanguage((string)$u['sus_language'], $uid, false);
			$auth = ['id' => $uid, 'globeadmin' => false, 'fp' => '', 'login' => (string)$u['sus_login_name']];
		}
		$auth += ['via' => 'nubuilder', 'nb_session' => $sid];
		NuLog::write('login', '', '', ['via' => 'nuBuilder Forte session'], $auth);
		return $auth;
	}

	public static function stillValid(string $nbSessionId): bool
	{
		static $ok = null;
		return $ok ??= $nbSessionId !== '' && (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_session WHERE zzzzsys_session_id = ?', [$nbSessionId]);
	}

	/** URL of nuBuilder (for the "back to nuBuilder" link), from $nuConfigNuBuilderURL. */
	public static function url(): string
	{
		return trim((string)nu_cfg('nuConfigNuBuilderURL'));
	}
}

/**
 * Per-user JSON data in zzzzsys_user.sus_json – nuBuilder's nuSetUserJSONData / nuGetUserJSONData (core/nucommon.php).
 * nuBuilder keeps LOGIN_TOKEN, 2FA_… and PASSWORD_CHANGED_TIME / PASSWORD_CHANGED_SOURCE there. Without the column
 * (nuBuilder adds it at login since 4.5) nothing is stored and get() returns ''.
 */
final class NuUserJson
{
	private static ?bool $has = null;

	public static function available(): bool
	{
		if (self::$has === null) {
			try { self::$has = isset(array_change_key_case(nu_db()->columns('zzzzsys_user'))['sus_json']); }
			catch (Throwable) { self::$has = false; }
		}
		return self::$has;
	}

	/** @return array<string, mixed> */
	public static function all(string $userId): array
	{
		if ($userId === '' || !self::available()) return [];
		$j = json_decode((string)nu_db()->value('SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]), true);
		return is_array($j) ? $j : [];
	}

	public static function get(string $userId, string $key): mixed
	{
		return self::all($userId)[$key] ?? '';
	}

	public static function set(string $userId, string $key, mixed $value): bool
	{
		return self::setMany($userId, [$key => $value]);
	}

	/** Several keys in one read / write. */
	public static function setMany(string $userId, array $values): bool
	{
		if ($userId === '' || !self::available()) return false;
		$db = nu_db();
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]) == 0) return false;
		$j = self::all($userId);
		foreach ($values as $k => $v) $j[(string)$k] = $v;
		$db->update('zzzzsys_user', ['sus_json' => json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'zzzzsys_user_id', $userId);
		return true;
	}

	/** sus_json for a cloned user: the source's data without what belongs to that person (login token, 2FA, password, OTP). */
	public static function forClone(?string $json): ?string
	{
		$j = json_decode((string)$json, true);
		if (!is_array($j)) return $json;
		$j = array_filter($j, static fn($k) => !preg_match('/^(LOGIN_TOKEN|2FA_|PASSWORD_|OTP_)/', (string)$k), ARRAY_FILTER_USE_KEY);
		return $j ? json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
	}

	/**
	 * After a password change, like nuBuilder: PASSWORD_CHANGED_TIME = Unix time, PASSWORD_CHANGED_SOURCE =
	 * 'user' (own change, as nuBuilder), 'admin' (set in the user form), 'reset' (forgot-password / welcome link).
	 */
	public static function passwordChanged(string $userId, string $source): void
	{
		try { self::setMany($userId, ['PASSWORD_CHANGED_TIME' => time(), 'PASSWORD_CHANGED_SOURCE' => $source]); }
		catch (Throwable) { /* best effort – the password itself is saved */ }
	}
}
