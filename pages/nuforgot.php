<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* "Forgot password": sends a reset link to the user's e-mail. The answer is always the same (no user enumeration). */

if (NuAuth::user()) nu_redirect(nu_url('home'));
if (!NuMail::configured()) nu_redirect(nu_url('login'));

$done  = false;
$value = '';

if (nu_is_post()) {
	$value = nu_post('identifier');
	$rate  = $_SESSION['nu_forgot'] ?? [];
	$rate  = array_values(array_filter($rate, static fn($t) => $t > time() - 900));
	// links only to the known address of this installation – never to the Host header of this (anonymous) request (0.8.207)
	$base  = NuSecurity::mailBaseUrl();
	if ($base === '') error_log('nuBuilder Next: "Forgot password" sends no link – set $nuConfigBaseURL (Admin tools → Security check)');
	// per address: at most 10 requests an hour (counted in the log, also without cookies); per user: 3 links an hour (below)
	$ipCount = NuSecurity::recentEvents('password_reset_form', 3600);
	if ($value !== '') NuLog::write('password_reset_form', '', '', [], ['id' => '', 'login' => mb_substr($value, 0, 50)]);

	if ($value !== '' && count($rate) < 3 && $ipCount < 10 && $base !== '') {
		$rate[] = time();
		$db = nu_db();
		$users = $db->all('SELECT zzzzsys_user_id, sus_login_name, sus_email, sus_expires_on, sus_first_name, sus_language FROM zzzzsys_user
			WHERE sus_login_name = ? OR (sus_email = ? AND sus_email <> \'\')', [$value, $value]);
		foreach (array_slice($users, 0, 5) as $u) {
			$uid = (string)$u['zzzzsys_user_id'];
			if (trim((string)$u['sus_email']) === '' || nu_is_expired($u['sus_expires_on'])) continue;
			if (NuDemo::isDemoUser($uid)) continue;                    // demo users: the globeadmin sets the password, visitors cannot
			if (NuToken::countSince('pw_reset', $uid, 3600) >= 3) continue;

			$token = NuToken::create('pw_reset', $uid, 3600);
			$link  = $base . '/index.php?' . http_build_query(['p' => 'reset', 'u' => $uid, 't' => $token]);
			$name  = trim((string)$u['sus_first_name']) !== '' ? (string)$u['sus_first_name'] : (string)$u['sus_login_name'];
			NuMail::send((string)$u['sus_email'], t('reset_mail_subject'), NuMail::layout(t('reset_mail_subject'),
				'<p>' . h(t('reset_mail_hello', $name)) . '</p><p>' . h(t('reset_mail_text', (string)$u['sus_login_name'])) . '</p>'
				. '<p style="margin:22px 0"><a href="' . h($link) . '" style="background:#2f6fd6;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">'
				. h(t('reset_mail_button')) . '</a></p><p style="color:#7a8394;font-size:13px">' . h(t('reset_mail_hint')) . '</p>'), 'nunext_reset');
			NuLog::write('password_reset_requested', 'zzzzsys_user', $uid, [], ['id' => $uid, 'login' => (string)$u['sus_login_name']]);
		}
	}
	$_SESSION['nu_forgot'] = $rate;
	$done = true;
}

nu_center_start(t('forgot_title'), t('forgot_subtitle'), 'bi-key');
?>
<?php if ($done): ?>
	<div class="alert alert-success d-flex gap-2" role="status"><i class="bi bi-envelope-check mt-1"></i><div><?= h(t('forgot_sent')) ?></div></div>
	<a class="btn btn-outline-secondary w-100" href="<?= h(nu_url('login')) ?>"><?= h(t('back_to_login')) ?></a>
<?php else: ?>
	<form method="post" action="<?= h(nu_url('forgot')) ?>" novalidate>
		<?= nu_csrf_field() ?>
		<div class="form-floating mb-3">
			<input type="text" class="form-control" id="identifier" name="identifier" value="<?= h($value) ?>" placeholder="x"
				   autocomplete="username" autocapitalize="off" spellcheck="false" maxlength="255" required autofocus>
			<label for="identifier"><?= h(t('forgot_identifier')) ?></label>
		</div>
		<button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-send me-2"></i><?= h(t('forgot_submit')) ?></button>
	</form>
	<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('login')) ?>"><?= h(t('back_to_login')) ?></a></div>
<?php endif; ?>
<?php
nu_center_end();
