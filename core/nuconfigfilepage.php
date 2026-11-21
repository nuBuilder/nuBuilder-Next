<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuconfigfile.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuConfigfilePage
{
	/** The POST actions of pages/nuconfigfile.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$hash = $nuIn['hash'] ?? null; $text = $nuIn['text'] ?? null; $result = $nuIn['result'] ?? null; $loaded = $nuIn['loaded'] ?? null;
		if (nu_is_post() && nu_post('action') === 'enable' && isset(NuConfigFile::SWITCHES[nu_post('var')])) {
			[$event, $done, $routes] = NuConfigFile::SWITCHES[nu_post('var')];
			$back = in_array(nu_post('return'), $routes, true) ? nu_post('return') : $routes[0];
			$err = NuConfigFile::setVariable(nu_post('var'), 'true');
			if ($err === '') {
				NuLog::write($event, 'nuconfig.php', '', ['$' . nu_post('var') => true]);
				nu_flash('success', t($done));
			} else {
				nu_flash('danger', t('set_use_db_failed') . ' ' . $err);
			}
			$rid = nu_post('return_id');
			$q = $rid !== '' && preg_match('/^[\w.-]{1,64}$/', $rid) ? ['id' => $rid] : [];
			foreach (['f', 'r'] as $k) {                         // back to a form page (?p=form&f=…&r=…)
				$v = (string)($_POST['return_q'][$k] ?? '');
				if ($v !== '' && preg_match('/^[\w.-]{1,64}$/', $v)) $q[$k] = $v;
			}
			nu_redirect(nu_url($back, $q));
		}

		// "Generate key" (API clients page): $nuConfigSecretKey while it is empty
		if (nu_is_post() && nu_post('action') === 'secretkey') {
			$err = NuConfigFile::setSecretKey(nu_post('key'));
			if ($err === '') {
				$sealed = NuSecret::sealPlain(nu_post('key'));     // secrets stored before the key existed (2FA, OAuth mail, SSO, webhooks, AI, SMTP copy): encrypt them now
				NuLog::write('secret_key_set', 'nuconfig.php', '', ['length' => strlen(nu_post('key')), 'encrypted' => $sealed]);   // never the key itself
				nu_flash('success', t('key_saved'));
			} else {
				nu_flash('danger', t('set_use_db_failed') . ' ' . $err);
			}
			nu_redirect(nu_url(nu_post('return') === 'securitycheck' ? 'securitycheck' : 'apiclients'));
		}

		// Save in the browser checks first (assets/js/nuconfigfile.js): JSON {ok, error, line} – nothing is written here
		if (nu_is_post() && nu_post('action') === 'precheck') {
			header('Content-Type: application/json; charset=utf-8');
			[$err, , $line] = NuConfigFile::check(str_replace("\r\n", "\n", (string)($_POST['content'] ?? ''))) + [1 => [], 2 => 0];
			exit(json_encode(['ok' => $err === '', 'error' => $err, 'line' => (int)$line], JSON_UNESCAPED_UNICODE));
		}

		if (nu_is_post()) {
			$action = nu_post('action');
			$text   = str_replace("\r\n", "\n", (string)($_POST['content'] ?? ''));
			$posted = (string)($_POST['hash'] ?? '');
			if ($action === 'save') {
				$err = NuConfigFile::save($text, $posted);
				if ($err === '') {
					nu_flash('success', t('cfg_saved'));
					nu_redirect(nu_url('configfile'));
				}
				$result = ['danger', $err];
			} else {
				[$err] = NuConfigFile::check($text);
				$result = $err === '' ? ['success', t('cfg_check_ok')] : ['danger', $err];
			}
			if (!hash_equals($hash, $posted)) $result = ['warning', t('cfg_err_changed')];   // the file changed meanwhile: keep the text, new base
		} elseif (is_string($_GET['backup'] ?? null) && ($b = NuConfigFile::backup((string)$_GET['backup'])) !== '') {
			$text = $b;
			$loaded = (string)$_GET['backup'];
		}
		return compact('text', 'result', 'hash', 'loaded');
	}
}
