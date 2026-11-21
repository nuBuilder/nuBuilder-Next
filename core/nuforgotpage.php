<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuforgot.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuForgotPage
{
	/** The POST actions of pages/nuforgot.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$value = $nuIn['value'] ?? null; $done = $nuIn['done'] ?? null;
		if (nu_is_post()) {
			$value = nu_post('identifier');
			$rate  = $_SESSION[NuSess::FORGOT] ?? [];
			$rate  = array_values(array_filter($rate, static fn($t) => $t > time() - 900));
			// links only to the known address of this installation – never to the Host header of this (anonymous) request
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

					$token = NuToken::create('pw_reset', $uid, 3600, '', 'forgot');   // 'forgot': the reset page confirms by e-mail
					$link  = $base . '/index.php?' . http_build_query(['p' => 'reset', 'u' => $uid, 't' => $token]);
					$name  = trim((string)$u['sus_first_name']) !== '' ? (string)$u['sus_first_name'] : (string)$u['sus_login_name'];
					NuMail::send((string)$u['sus_email'], t('reset_mail_subject'), NuMail::layout(t('reset_mail_subject'),
						'<p>' . h(t('reset_mail_hello', $name)) . '</p><p>' . h(t('reset_mail_text', (string)$u['sus_login_name'])) . '</p>'
						. '<p style="margin:22px 0"><a href="' . h($link) . '" style="background:#2f6fd6;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">'
						. h(t('reset_mail_button')) . '</a></p><p style="color:#7a8394;font-size:13px">' . h(t('reset_mail_hint')) . '</p>'), 'nunext_reset');
					NuLog::write('password_reset_requested', 'zzzzsys_user', $uid, [], ['id' => $uid, 'login' => (string)$u['sus_login_name']]);
				}
			}
			$_SESSION[NuSess::FORGOT] = $rate;
			$done = true;
		}
		return compact('value', 'done');
	}
}
