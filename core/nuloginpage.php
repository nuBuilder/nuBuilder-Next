<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nulogin.php (code review R7: a page defines no global function). */
final class NuLoginPage
{
	/**
	 * $nuWelcomeBodyInnerHTML from nuconfig.php (nuBuilder's name, so an existing config works), or null for nuBuilder Next's own login card.
	 * Every <form> gets the CSRF field; nuBuilder's image paths "core/graphics/…" point to nuBuilder's folder.
	 */
	public static function customHtml(): ?string
	{
		$html = nu_cfg('nuWelcomeBodyInnerHTML');
		if (!is_string($html) || trim($html) === '') return null;
		global $next;
		$extra = nu_csrf_field() . ($next !== null ? '<input type="hidden" name="next" value="' . h($next) . '">' : '');
		$html = preg_replace('~<form\b[^>]*>~i', '$0' . $extra, $html) ?? $html;
		$base = rtrim((string)(nu_cfg('nuConfigNuBuilderURL') ?: '..'), '/') . '/';
		return preg_replace('~(\ssrc\s*=\s*["\'])(core/graphics/)~i', '$1' . $base . '$2', $html) ?? $html;
	}

	/** Single sign-on button (core/nusso.php): above the password form, empty while SSO is not ready. */
	public static function ssoBlock(?string $next): string
	{
		if (!NuSso::ready()) return '';
		$url = nu_url('sso', ['go' => '1', 'next' => $next]);
		return '<div class="nuLoginSso"><a class="btn btn-outline-primary w-100 py-2" id="nuSsoBtn" href="' . h($url) . '"><i class="bi bi-person-badge me-2" aria-hidden="true"></i>'
			. h(NuSso::buttonLabel()) . '</a><div class="nuLoginOr"><span>' . h(t('login_or')) . '</span></div></div>';
	}

	/** "Sign in with a passkey" (Security setting passkey_login): below the password form; '' while passkeys cannot sign in here. */
	public static function passkeyBlock(): string
	{
		if (!NuPasskey::loginReady()) return '';
		return '<div class="nuLoginPasskey">' . nu_csrf_field() . '<div class="nuLoginOr"><span>' . h(t('login_or')) . '</span></div>'
			. NuPasskey::button('login', t('pk_login_btn'), 'btn btn-outline-primary w-100 py-2', 'nuPkLogin') . '</div>';
	}

	/** nuBuilder Forte's $nuConfigLogonMode = 'sso': the password form is folded behind a link (only while SSO is ready). */
	public static function ssoFirst(): bool
	{
		return NuSso::ready() && strtolower((string)nu_cfg('nuConfigLogonMode')) === 'sso';
	}

	/** "Try the demo" (core/nudemo.php): one button per demo user that can log in; empty without $nuConfigDemoUser. */
	public static function demoBlock(string $action, ?string $next): string
	{
		$demos = NuDemo::configured() ? NuDemo::loginable() : [];
		if (!$demos) return '';
		$out = '<div class="nuLoginDemo"><div class="nuLoginOr"><span>' . h(t('login_or')) . '</span></div>';
		foreach ($demos as $d) {
			$out .= '<form method="post" action="' . h($action) . '" class="mt-2">' . nu_csrf_field()
				. ($next !== null ? '<input type="hidden" name="next" value="' . h($next) . '">' : '')
				. '<input type="hidden" name="action" value="demo"><input type="hidden" name="demo_user" value="' . h($d['id']) . '">'
				. '<button class="btn nuAddBtn w-100 py-2" type="submit"><i class="bi bi-play-circle me-2"></i>'
				. h(count($demos) > 1 ? t('demo_try_as', $d['display']) : t('demo_try')) . '</button></form>';
		}
		return $out . '<p class="small text-body-secondary text-center mt-2 mb-0">' . h(t('demo_try_hint')) . '</p></div>';
	}

	/** The POST actions of pages/nulogin.php (part 1) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post1(array $nuIn): array
	{
		if (nu_is_post() && nu_post('action') === 'autologin') {
			$al = $_SESSION[NuSess::AUTOLOGIN_PENDING] ?? null;
			unset($_SESSION[NuSess::AUTOLOGIN_PENDING]);
			if (is_array($al) && time() - (int)$al['time'] < 600) {
				if (NuAuth::user()) { NuLog::write('logout', '', '', ['reason' => 'auto login of another user']); NuAuth::logout(); }
				NuAuth::setNext((string)$al['target']);
				if ((string)($al['token'] ?? '') !== '') {                       // single-use login token
					$alErr = NuAuth::loginToken((string)$al['login'], (string)$al['token']);
					if ($alErr === null) nu_redirect(NuAuth::takeNext());
					nu_flash('danger', t($alErr));
					nu_redirect(nu_url('login'));
				}
				if (!nu_cfg('nuConfigAutoLogin') || $al['hash'] === '') nu_redirect(nu_url('login', ['user' => (string)$al['login']]));
				NuAuth::$urlExpires = (int)$al['e'];
				$alErr = NuAuth::login((string)$al['login'], (string)$al['hash'], true);
				if ($alErr === null) nu_redirect(NuAuth::takeNext());
				if ($alErr === '2fa') nu_redirect(nu_url('twofa'));
				nu_flash('danger', t($alErr));
			}
			nu_redirect(nu_url('login'));
		}
		return [];
	}

	/** The POST actions of pages/nulogin.php (part 2) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post2(array $nuIn): array
	{
		$error = $nuIn['error'] ?? null; $login = $nuIn['login'] ?? null;
		if (nu_is_post() && nu_post('action') === 'demo') {
			$error = NuAuth::loginDemo(nu_post('demo_user'));
			if ($error === null) nu_redirect(NuAuth::takeNext());
		} elseif (nu_is_post()) {
			$login    = nu_post('username');
			$password = (string)($_POST['password'] ?? '');
			$error    = NuAuth::login($login, $password);
			if ($error === null) nu_redirect(NuAuth::takeNext());
			if ($error === '2fa') nu_redirect(nu_url('twofa'));
		}
		return compact('error', 'login');
	}
}
