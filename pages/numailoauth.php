<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * OAuth 2 for the SMTP login (0.8.445, core/numailoauth.php) – rights of nuBuilder's form "nusetup", like the E-mail settings.
 *   ?p=mailoauth&go=1  after "Save and connect": a state for this session, then the provider's consent page. A page of its own that
 *                      forwards with <meta refresh>: the form post itself may not redirect to another site (Content-Security-Policy form-action).
 *   oauth.php?code=…&state=…  the provider sends the browser back here (oauth.php sets p=mailoauth – a redirect address without a query
 *                      string): state checked (once, 10 minutes), code exchanged for the refresh token, back to E-mail → Settings.
 */

NuAcl::require(NuAcl::FORM_SETUP, 'save');
$back = nu_url('email', ['tab' => 'settings']) . '#nuMailOAuth';

// ------------------------------------------------------------------ back from the provider
if (isset($_GET['state']) || isset($_GET['code']) || isset($_GET['error'])) {
	$st = $_SESSION['nunext_mail_oauth'] ?? null;
	unset($_SESSION['nunext_mail_oauth']);
	$ok = is_array($st) && is_string($_GET['state'] ?? null) && hash_equals((string)$st['state'], (string)$_GET['state']) && (int)$st['at'] > time() - 600;
	if (!$ok) {
		NuLog::write('mail_oauth_failed', NuMailOAuth::SETTING, '', ['reason' => 'state']);
		nu_flash('danger', t('mail_oauth_state'));
		nu_redirect($back);
	}
	if (isset($_GET['error'])) {
		$msg = trim((string)$_GET['error'] . ' – ' . (string)($_GET['error_description'] ?? ''), ' –');
		NuLog::write('mail_oauth_failed', NuMailOAuth::SETTING, '', ['reason' => mb_substr($msg, 0, 300)]);
		nu_flash('danger', t('mail_oauth_refused', mb_substr($msg, 0, 300)));
		nu_redirect($back);
	}
	try {
		$account = NuMailOAuth::exchange((string)($_GET['code'] ?? ''));
		NuLog::write('mail_oauth_connected', NuMailOAuth::SETTING, '', ['provider' => NuMailOAuth::settings()['provider'], 'account' => $account]);
		nu_flash('success', $account !== '' ? t('mail_oauth_connected_as', $account) : t('mail_oauth_connected'));
	} catch (Throwable $e) {
		NuLog::write('mail_oauth_failed', NuMailOAuth::SETTING, '', ['reason' => mb_substr($e->getMessage(), 0, 300)]);
		nu_flash('danger', t('mail_oauth_failed', $e->getMessage()));
	}
	nu_redirect($back);
}

// ------------------------------------------------------------------ to the provider
if (!NuMailOAuth::ready()) {
	nu_flash('warning', t('mail_oauth_not_ready'));
	nu_redirect($back);
}
$state = bin2hex(random_bytes(24));
$_SESSION['nunext_mail_oauth'] = ['state' => $state, 'at' => time()];
$url = NuMailOAuth::authorizeUrl($state);
$host = (string)parse_url($url, PHP_URL_HOST);

nu_layout_start(t('mail_oauth_title'), 'home', ['head' => '<meta http-equiv="refresh" content="0;url=' . h($url) . '">']);
?>
<div class="card shadow-sm nu-card-narrow mt-4">
	<div class="card-body p-4">
		<h1 class="h5"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?= h(t('mail_oauth_going', $host)) ?></h1>
		<p class="mb-0"><a class="btn btn-primary" href="<?= h($url) ?>" id="nuMailOAuthGo"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('mail_oauth_go')) ?></a></p>
	</div>
</div>
<?php
nu_layout_end();
