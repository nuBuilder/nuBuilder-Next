<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

// page to open after the login: ?next= (set by index.php when the session had ended) or the hidden form field
$next = NuAuth::validNext(is_string($_POST['next'] ?? null) ? $_POST['next'] : (is_string($_GET['next'] ?? null) ? $_GET['next'] : ''));
if ($next !== null) NuAuth::setNext($next);
$loginAction = NuAuth::loginUrl($next);

// auto-login link opened while somebody else was logged in (pages/nuautologin.php asked first): switch now
if (nu_is_post() && nu_post('action') === 'autologin') {
	$al = $_SESSION['nu_autologin_pending'] ?? null;
	unset($_SESSION['nu_autologin_pending']);
	if (is_array($al) && time() - (int)$al['time'] < 600) {
		if (NuAuth::user()) { NuLog::write('logout', '', '', ['reason' => 'auto login of another user']); NuAuth::logout(); }
		NuAuth::setNext((string)$al['target']);
		if ((string)($al['token'] ?? '') !== '') {                       // single-use login token (0.8.339)
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

if (NuAuth::user()) nu_redirect(NuAuth::takeNext());

$error = null;

/**
 * $nuWelcomeBodyInnerHTML from nuconfig.php (nuBuilder's name, so an existing config works), or null for nuBuilder Next's own login card.
 * Every <form> gets the CSRF field; nuBuilder's image paths "core/graphics/…" point to nuBuilder's folder.
 */
function nu_login_custom_html(): ?string
{
	$html = nu_cfg('nuWelcomeBodyInnerHTML');
	if (!is_string($html) || trim($html) === '') return null;
	global $next;
	$extra = nu_csrf_field() . ($next !== null ? '<input type="hidden" name="next" value="' . h($next) . '">' : '');
	$html = preg_replace('~<form\b[^>]*>~i', '$0' . $extra, $html) ?? $html;
	$base = rtrim((string)(nu_cfg('nuConfigNuBuilderURL') ?: '..'), '/') . '/';
	return preg_replace('~(\ssrc\s*=\s*["\'])(core/graphics/)~i', '$1' . $base . '$2', $html) ?? $html;
}
$login = is_string($_GET['user'] ?? null) ? mb_substr(trim($_GET['user']), 0, 50) : '';   // auto login link without password: name filled in

/** "Try the demo" (core/nudemo.php): one button per demo user that can log in; empty without $nuConfigDemoUser. */
function nu_login_demo_block(string $action, ?string $next): string
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

$custom = nu_login_custom_html();
if ($custom !== null) {
	// nuBuilder's $nuWelcomeBodyInnerHTML: the page of the config file replaces the login card. Inline style="…" is allowed here
	// (the HTML comes from nuconfig.php); inline scripts and onclick="…" stay blocked – assets/js/nulogincustom.js wires the form.
	header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
	nu_head(t('login_title'));
	?>
<body class="nu-login nu-login-custom" data-login-url="<?= h($loginAction) ?>" data-login-user="<?= h($login) ?>" data-csrf="<?= h(nu_csrf_token()) ?>">
<div class="nu-login-tools">
	<?= NuBranding::envBadge() ?>
	<?php nu_theme_menu(); ?>
	<?php nu_lang_menu(); ?>
</div>
<?php if ($error || !empty($_SESSION['nu_flash'])): ?>
<div class="nu-login-msg">
	<?php nu_render_flashes(); ?>
	<?php if ($error): ?>
		<div class="alert alert-danger d-flex gap-2 py-2" role="alert"><i class="bi bi-exclamation-octagon mt-1"></i><div><?= h(t($error)) ?></div></div>
	<?php endif; ?>
</div>
<?php endif; ?>
<?= $custom ?>
<?php if (($demo = nu_login_demo_block($loginAction, $next)) !== ''): ?><div class="nuLoginDemoCustom"><?= $demo ?></div><?php endif; ?>
<?php if (NuMail::configured()): ?>
	<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('forgot')) ?>"><?= h(t('forgot_link')) ?></a></div>
<?php endif; ?>
<p class="text-center small text-body-secondary mt-3 mb-0">nuBuilder Next <?= NU_VERSION ?></p>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nulogincustom.js') ?>"></script>
</body>
</html>
<?php
	return;
}

nu_head(t('login_title'));
?>
<body class="nu-center nu-login">
<main class="nu-card-narrow">
	<div class="d-flex justify-content-end align-items-center gap-2 mb-3">
		<?= NuBranding::envBadge('me-auto') ?>
		<?php nu_theme_menu(); ?>
		<?php nu_lang_menu(); ?>
	</div>

	<div class="card shadow-sm border-0">
		<div class="card-body p-4 p-sm-5">
			<div class="text-center mb-4">
				<div class="mb-3"><?= nu_brand_logo('lg') ?></div>
				<?php if (($appTitle = nu_app_title(true)) !== ''):   // $nuConfigTitle (as in nuBuilder) above "Sign in" ?>
					<h1 class="h4 mb-1 nuLoginAppTitle"><?= h($appTitle) ?></h1>
					<div class="text-body-secondary"><?= h(t('login_title')) ?></div>
				<?php else: ?>
					<h1 class="h4 mb-0"><?= h(t('login_title')) ?></h1>
				<?php endif; ?>
			</div>

			<?php nu_render_flashes(); ?>
			<?php if ($error): ?>
				<div class="alert alert-danger d-flex gap-2 py-2" role="alert"><i class="bi bi-exclamation-octagon mt-1"></i><div><?= h(t($error)) ?></div></div>
			<?php endif; ?>

			<form method="post" action="<?= h($loginAction) ?>" novalidate>
				<?= nu_csrf_field() ?>
				<?php if ($next !== null): ?><input type="hidden" name="next" value="<?= h($next) ?>"><?php endif; ?>
				<div class="form-floating mb-3">
					<input type="text" class="form-control" id="username" name="username" value="<?= h($login) ?>"
						   placeholder="<?= h(t('login_username')) ?>" autocomplete="username" autocapitalize="off" spellcheck="false" maxlength="50" required autofocus>
					<label for="username"><?= h(t('login_username')) ?></label>
				</div>
				<div class="form-floating mb-4 nu-pw">
					<input type="password" class="form-control" id="password" name="password"
						   placeholder="<?= h(t('login_password')) ?>" autocomplete="current-password" required>
					<label for="password"><?= h(t('login_password')) ?></label>
					<button class="btn btn-link nu-pw-toggle" type="button" data-pw-toggle="#password" aria-label="<?= h(t('show_password')) ?>"><i class="bi bi-eye"></i></button>
				</div>
				<button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-box-arrow-in-right me-2"></i><?= h(t('login_submit')) ?></button>
			</form>
			<?= nu_login_demo_block($loginAction, $next) ?>
			<?php if (NuMail::configured()): ?>
				<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('forgot')) ?>"><?= h(t('forgot_link')) ?></a></div>
			<?php endif; ?>
		</div>
	</div>
	<p class="text-center small text-body-secondary mt-3 mb-0">nuBuilder Next <?= NU_VERSION ?></p>
</main>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
</body>
</html>
