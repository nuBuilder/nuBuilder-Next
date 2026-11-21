<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

// page to open after the login: ?next= (set by index.php when the session had ended) or the hidden form field
$next = NuAuth::validNext(is_string($_POST['next'] ?? null) ? $_POST['next'] : (is_string($_GET['next'] ?? null) ? $_GET['next'] : ''));
if ($next !== null) NuAuth::setNext($next);
$loginAction = NuAuth::loginUrl($next);

// auto-login link opened while somebody else was logged in (pages/nuautologin.php asked first): switch now
NuLoginPage::post1(get_defined_vars());   // the POST actions (code review R7, nuloginpage.php)

if (NuAuth::user()) nu_redirect(NuAuth::takeNext());

$error = null;

$login = is_string($_GET['user'] ?? null) ? mb_substr(trim($_GET['user']), 0, 50) : '';   // auto login link without password: name filled in





['error' => $error, 'login' => $login]
	= NuLoginPage::post2(get_defined_vars());   // the POST actions (code review R7, nuloginpage.php)

$custom = NuLoginPage::customHtml();
if ($custom !== null) {
	// nuBuilder's $nuWelcomeBodyInnerHTML: the page of the config file replaces the login card. Inline style="…" is allowed here
	// (the HTML comes from nuconfig.php); inline scripts and onclick="…" stay blocked – assets/js/nulogincustom.js wires the form.
	header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
	NuCustomCode::$context = 'login';                 // Global JS & CSS: scope "sign-in pages"
	nu_head(t('login_title'));
	?>
<body class="nu-login nu-login-custom"<?= NuCustomCode::bodyAttrs() ?> data-login-url="<?= h($loginAction) ?>" data-login-user="<?= h($login) ?>" data-csrf="<?= h(nu_csrf_token()) ?>">
<div class="nu-login-tools">
	<?= NuBranding::envBadge() ?>
	<?php nu_theme_menu(); ?>
	<?php nu_lang_menu(); ?>
</div>
<?php if ($error || !empty($_SESSION[NuSess::FLASH])): ?>
<div class="nu-login-msg">
	<?php nu_render_flashes(); ?>
	<?php if ($error): ?>
		<div class="alert alert-danger d-flex gap-2 py-2" role="alert"><i class="bi bi-exclamation-octagon mt-1"></i><div><?= h(t($error)) ?></div></div>
	<?php endif; ?>
</div>
<?php endif; ?>
<?= $custom ?>
<?php if (($sso = NuLoginPage::ssoBlock($next)) !== ''): ?><div class="nuLoginSsoCustom"><?= $sso ?></div><?php endif; ?>
<?php if (($pk = NuLoginPage::passkeyBlock()) !== ''): ?><div class="nuLoginPasskeyCustom"><?= $pk ?></div><?php endif; ?>
<?php if (($demo = NuLoginPage::demoBlock($loginAction, $next)) !== ''): ?><div class="nuLoginDemoCustom"><?= $demo ?></div><?php endif; ?>
<?php if (NuMail::configured()): ?>
	<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('forgot')) ?>"><?= h(t('forgot_link')) ?></a></div>
<?php endif; ?>
<p class="text-center small text-body-secondary mt-3 mb-0">nuBuilder Next <?= NU_VERSION ?></p>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuutil.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nulogincustom.js') ?>"></script>
<?= NuPasskey::loginReady() ? NuPasskey::script() : '' ?>
<?= NuCustomCode::jsTag() ?>
</body>
</html>
<?php
	return;
}

nu_center_top(t('login_title'));
?>

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
			<?php if (NuInstall::backupNeeded()):   // a nuBuilder Forte database not set up by nuBuilder Next yet ?>
				<div class="alert alert-warning d-flex gap-2 py-2 small" role="note"
					id="nuLoginBackup"><i class="bi bi-shield-exclamation mt-1"></i><div><b><?= h(t('ins_backup_title')) ?></b><br><?= h(t('ins_backup_login')) ?></div></div>
			<?php endif; ?>
			<?php if (!NuDbState::ready() && NuDbState::check()['error'] === ''):   // database not set up ?>
				<div class="alert alert-info d-flex gap-2 py-2 small" role="status" id="nuLoginDbSetup"><i class="bi bi-database-gear mt-1"></i><div><?= h(t('dbs_login_hint')) ?>
					<a href="<?= h(nu_url('dbsetup')) ?>" class="alert-link"><?= h(t('dbs_login_link')) ?></a></div></div>
			<?php endif; ?>

			<?= NuLoginPage::ssoBlock($next) ?>
			<?php $ssoFirst = NuLoginPage::ssoFirst() && $error === null && $login === ''; ?>
			<?php if ($ssoFirst): ?><details class="nuLoginPwFold" id="nuLoginPwFold"><summary class="small text-center"><?= h(t('sso_password_link')) ?></summary><div class="pt-3"><?php endif; ?>
			<form method="post" action="<?= h($loginAction) ?>" novalidate>
				<?= nu_csrf_field() ?>
				<?php if ($next !== null): ?><input type="hidden" name="next" value="<?= h($next) ?>"><?php endif; ?>
				<div class="form-floating mb-3">
					<input type="text" class="form-control" id="username" name="username" value="<?= h($login) ?>"
						   placeholder="<?= h(t('login_username')) ?>" autocomplete="username<?= NuPasskey::loginReady() ? ' webauthn' : '' ?>" autocapitalize="off" spellcheck="false" maxlength="50" required autofocus>
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
			<?php if ($ssoFirst): ?></div></details><?php endif; ?>
			<?= NuLoginPage::passkeyBlock() ?>
			<?= NuLoginPage::demoBlock($loginAction, $next) ?>
			<?php if (NuMail::configured()): ?>
				<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('forgot')) ?>"><?= h(t('forgot_link')) ?></a></div>
			<?php endif; ?>
		</div>
	</div>
	<p class="text-center small text-body-secondary mt-3 mb-0">nuBuilder Next <?= NU_VERSION ?></p>
</main>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuutil.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<?= NuPasskey::loginReady() ? NuPasskey::script() : '' ?>
<?= NuCustomCode::jsTag() ?>
</body>
</html>
