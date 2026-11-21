<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nuemail.php (code review R7: a page defines no global function). */
final class NuEmailPage
{
	public static function state(?string $st): string
	{
		$st = (string)$st;
		$cls = $st === 'sent' ? 'bg-success-subtle text-success-emphasis' : ($st === 'error' ? 'bg-danger-subtle text-danger-emphasis' : 'bg-secondary-subtle text-secondary-emphasis');
		$l = t('mail_state_' . $st);
		return '<span class="badge ' . $cls . '">' . h($l === 'mail_state_' . $st ? $st : $l) . '</span>';
	}

	public static function dt(mixed $v): string
	{
		if ($v === null || $v === '') return '';
		$ts = strtotime((string)$v);
		return $ts ? date(nu_dfmt('d.m.Y H:i:s'), $ts) : (string)$v;
	}

	/** The POST actions of pages/nuemail.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$errors = $nuIn['errors'] ?? null;
		$logExists = $nuIn['logExists'] ?? null;
		$db = $nuIn['db'] ?? null;
		$tab = $nuIn['tab'] ?? null;
		$checkResult = $nuIn['checkResult'] ?? null;
		$testResult = $nuIn['testResult'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');

			if ($action === 'oauth_disconnect') {                                     // OAuth 2: forget the connected mailbox
				NuAcl::require(NuAcl::FORM_SETUP, 'save');
				$was = NuMailOAuth::settings()['account'];
				NuMailOAuth::disconnect();
				NuLog::write('mail_oauth_disconnected', NuMailOAuth::SETTING, '', ['account' => $was]);
				nu_flash('success', t('mail_oauth_disconnected'));
				nu_redirect(nu_url('email', ['tab' => 'settings']) . '#nuMailOAuth');
			}

			if ($action === 'save' || $action === 'save_connect') {                  // save_connect: then the provider's consent page
				NuAcl::require(NuAcl::FORM_SETUP, 'save');
				$host = nu_post('host');
				$port = nu_post('port');
				$user = nu_post('username');
				$from = nu_post('from_address');
				$name = nu_post('from_name');
				nu_maxlen($errors, 'host', $host, 255);
				nu_maxlen($errors, 'username', $user, 255);
				nu_maxlen($errors, 'from_name', $name, 255);
				if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) $errors['port'] = t('mail_err_port');
				if ($from !== '' && !NuMailer::validAddress($from)) $errors['from_address'] = t('user_err_email');
				$pw = $_POST['password'] ?? '';
				$pw = is_string($pw) ? $pw : '';
				nu_maxlen($errors, 'password', $pw, 255);

				if (!$errors) {
					$data = [
						'set_smtp_host' => $host, 'set_smtp_port' => $port === '' ? null : (int)$port, 'set_smtp_username' => $user,
						'set_smtp_from_address' => $from, 'set_smtp_from_name' => $name,
						'set_smtp_use_authentication' => nu_check('use_auth'), 'set_smtp_use_ssl' => nu_check('use_ssl'),
					];
					if (nu_check('clear_password') === '1') $data['set_smtp_password'] = '';
					elseif ($pw !== '') $data['set_smtp_password'] = $pw;
					$onlyBefore = NuSecret::smtp()['only'];
					$data['smtp_only'] = nu_check('smtp_only') === '1';   // password only in nuBuilder Next's encrypted copy
					$before = NuMailer::setupRow(true);
					try {
						$oldAuth = NuMailer::authType();
						NuMailer::saveSettings($data);
						$after = NuMailer::setupRow(true);
						$diff = NuLog::diff(array_diff_key($before, ['set_smtp_password' => 1]), array_diff_key(array_intersect_key($after, $data), ['set_smtp_password' => 1]));
						if (array_key_exists('set_smtp_password', $data)) $diff['set_smtp_password'] = ['***', $data['set_smtp_password'] === '' ? '' : '***'];
						unset($diff['smtp_only']);
						if ($onlyBefore !== NuSecret::smtp()['only']) $diff['smtp_only'] = [$onlyBefore ? '1' : '0', NuSecret::smtp()['only'] ? '1' : '0'];
						if (NuMailer::setAuthType(nu_post('auth_type'))) $diff['auth_type'] = [$oldAuth ?: 'auto', NuMailer::authType() ?: 'auto'];
						if (isset($_POST['oauth_provider'])) {           // OAuth 2 client – secrets never in the log
							$oc = NuMailOAuth::save(['provider' => nu_post('oauth_provider'), 'tenant' => nu_post('oauth_tenant'), 'client_id' => nu_post('oauth_client_id'),
								'client_secret' => is_string($_POST['oauth_client_secret'] ?? null) ? $_POST['oauth_client_secret'] : '',
								'auth_url' => nu_post('oauth_auth_url'), 'token_url' => nu_post('oauth_token_url'), 'scope' => nu_post('oauth_scope')]);
							if ($oc) $diff['oauth'] = $oc;
						}
						NuLog::write('email_settings', 'zzzzsys_setup', (string)($after['zzzzsys_setup_id'] ?? '1'), $diff ? ['fields' => $diff] : []);
						nu_flash('success', t('saved'));
						if ($action === 'save_connect') nu_redirect(nu_url('mailoauth', ['go' => 1]));
						nu_redirect(nu_url('email', ['tab' => 'settings']));
					} catch (Throwable $e) {
						error_log('nuBuilder Next email settings: ' . $e->getMessage());
						$errors['_'] = t('mail_err_save') . (nu_debug() ? ' ' . $e->getMessage() : '');
					}
				}
			}

			if ($action === 'check') {                                              // connect, encryption, login, sender – nothing is sent
				NuAcl::require(NuAcl::FORM_SETUP);
				$tab = 'test';
				$checkResult = NuMailer::check();
				NuLog::write($checkResult['ok'] ? 'email_check' : 'email_check_failed', 'zzzzsys_setup', '',
					['steps' => array_map(static fn($x) => $x[0] . ':' . ($x[1] === null ? '-' : ($x[1] ? 'ok' : 'fail')), $checkResult['steps'])]);
			}

			if ($action === 'test') {
				NuAcl::require(NuAcl::FORM_SETUP);
				$tab = 'test';
				$to = nu_post('to');
				if (!NuMailer::addressList($to)) $errors['to'] = t('err_required');
				if (!$errors) {
					$level = max(0, min(4, (int)nu_post('debug', '2')));
					$u = NuAuth::user();
					$body = NuMail::layout(t('mail_test_subject'), '<p>' . h(t('mail_test_text', $u['login'], date(nu_dfmt('d.m.Y H:i:s')))) . '</p>');
					NuMailer::$probe = true;                                         // read the server's AUTH methods
					$testResult = nuSendEmailEx(
						['to' => $to, 'subject' => nu_post('subject') ?: t('mail_test_subject'), 'body' => $body, 'html' => true, 'debug' => $level],
						['tag' => 'nunext_test', 'form_id' => NuAcl::FORM_SETUP, 'table_name' => 'zzzzsys_setup']
					);
					NuLog::write($testResult[0] ? 'email_test' : 'email_test_failed', 'zzzzsys_setup', '', ['to' => $to]);
				}
			}

			if ($action === 'delete_entry') {                                                   // one entry (button in the list row or the detail)
				NuAcl::require(NuAcl::FORM_EMAILLOG, 'delete');
				$eid = nu_post('id');
				if ($logExists && $eid !== '') {
					$subj = (string)$db->value('SELECT eml_subject FROM ' . NuMailer::LOG_TABLE . ' WHERE zzzzsys_email_log_id = ?', [$eid]);
					$st = $db->run('DELETE FROM ' . NuMailer::LOG_TABLE . ' WHERE zzzzsys_email_log_id = ?', [$eid]);
					if ($st->rowCount()) {
						NuLog::write('email_log_deleted', NuMailer::LOG_TABLE, $eid, ['subject' => mb_substr($subj, 0, 120)]);
						nu_flash('success', t('mail_log_deleted'));
					}
				}
				parse_str((string)nu_post('back'), $back);                                       // back to the same page, filters and search
				$back = array_intersect_key(is_array($back) ? $back : [], array_flip(['state', 'q', 'from', 'to', 'pg', 'view', 'tag', 'when']));
				nu_redirect(nu_url('email', ['tab' => 'log'] + array_map('strval', $back)));
			}

			if ($action === 'purge') {
				NuAcl::require(NuAcl::FORM_EMAILLOG, 'delete');
				$days = max(1, (int)nu_post('days', '90'));
				if ($logExists) {
					$cut = date('Y-m-d', strtotime("-$days days"));
					$st = $db->run('DELETE FROM ' . NuMailer::LOG_TABLE . ' WHERE eml_created_at < ?', [$db->datetime($cut)]);
					NuLog::write('email_log_purged', NuMailer::LOG_TABLE, '', ['days' => $days, 'rows' => $st->rowCount()]);
					nu_flash('success', t('mail_log_purged', $st->rowCount()));
				}
				nu_redirect(nu_url('email', ['tab' => 'log']));
			}
		}
		return compact('errors', 'tab', 'checkResult', 'testResult', 'logExists', 'db');
	}
}
