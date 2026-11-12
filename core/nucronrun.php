<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * A procedure started by the server's cron (0.8.1135):  php nucron.php run <code> [--user=<login>] [--mail=<address>] [name=value …]
 * The crontab decides when; this runs the procedure as a user (default: the globeadmin), writes one log entry and – when it fails –
 * sends an e-mail through the mail settings to --mail or the address of Settings → Data & logs (setting 'cron_run' {mail}).
 */
final class NuCronRun
{
	public const SETTING = 'cron_run';

	/** @return array{mail: string} */
	public static function settings(): array
	{
		try {
			$v = json_decode((string)nu_setting_value(self::SETTING), true);
		} catch (Throwable) {
			$v = null;                                       // the own tables are not there yet
		}
		return ['mail' => is_array($v) && is_string($v['mail'] ?? null) ? $v['mail'] : ''];
	}

	/** Addresses of a comma / semicolon separated text; null when one of them is not an e-mail address. @return list<string>|null */
	public static function addresses(string $text): ?array
	{
		$out = [];
		foreach (preg_split('/[,;\s]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
			if (filter_var($a, FILTER_VALIDATE_EMAIL) === false) return null;
			$out[] = $a;
		}
		return array_values(array_unique($out));
	}

	/** Saves the address(es) for failed runs; false = not valid (nothing saved). */
	public static function saveSettings(string $mail): bool
	{
		$list = self::addresses($mail);
		if ($list === null || strlen($mail) > 500) return false;
		$new = ['mail' => implode(', ', $list)];
		if ($new !== self::settings()) {
			NuSchema::ensure();
			nu_setting_save(self::SETTING, ['setting_value' => json_encode($new), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => (string)(NuAuth::user()['login'] ?? '')]);
			NuLog::write('cron_run_settings', 'zzzzsys_nunext_setting', self::SETTING, $new);
		}
		return true;
	}

	/**
	 * Arguments after "run": the code, --user= / --mail= / --quiet, and name=value pairs (hash variables of the run, as nuSetProperty).
	 * @param list<string> $args
	 * @return array{code: string, user: string, mail: ?string, quiet: bool, props: array<string, string>}|string  a text = wrong call
	 */
	public static function parse(array $args): array|string
	{
		$o = ['code' => '', 'user' => '', 'mail' => null, 'quiet' => false, 'props' => []];
		foreach ($args as $a) {
			if (preg_match('/^--user=(.+)$/s', $a, $m)) $o['user'] = $m[1];
			elseif (preg_match('/^--mail=(.*)$/s', $a, $m)) $o['mail'] = $m[1];
			elseif ($a === '--quiet') $o['quiet'] = true;
			elseif (str_starts_with($a, '--')) return "Unknown option: $a";
			elseif (preg_match('/^(\w{1,100})=(.*)$/s', $a, $m)) $o['props'][$m[1]] = $m[2];
			elseif ($o['code'] === '') $o['code'] = $a;
			else return "Unexpected argument: $a";
		}
		if ($o['code'] === '') return 'The code of the procedure is missing.';
		if ($o['mail'] !== null && self::addresses($o['mail']) === null) return 'Not an e-mail address: ' . $o['mail'];
		return $o;
	}

	/**
	 * Runs the procedure. Command line only.
	 * @param array{code: string, user: string, mail: ?string, quiet: bool, props: array<string, string>} $o
	 * @return array{ok: bool, error: string, output: string, ms: int, mailed: list<string>, mail_error: string}
	 */
	public static function run(array $o): array
	{
		if (PHP_SAPI !== 'cli') throw new LogicException('NuCronRun is for the command line only');
		$db = nu_db();
		$res = ['ok' => false, 'error' => '', 'output' => '', 'ms' => 0, 'mailed' => [], 'mail_error' => ''];
		$code = $o['code'];
		$php = $db->one('SELECT zzzzsys_php_id, sph_code, sph_description FROM zzzzsys_php WHERE sph_code = ?', [$code]);
		$ga = (string)nu_cfg('nuConfigDBGlobeadminUsername');
		$login = $o['user'] === '' || $o['user'] === 'globeadmin' || hash_equals($ga, $o['user']) ? '' : $o['user'];
		$actor = ['id' => 'cli', 'login' => 'cron'];
		if (!$php || NuProcedure::isHook((string)$php['zzzzsys_php_id'])) {   // form events are rows of the same table
			$res['error'] = "No procedure with the code $code.";
		} elseif ($login === '') {
			NuAuth::cliGlobeadmin();
			$actor = ['id' => 'globeadmin', 'login' => $ga];
		} else {
			$row = $db->one('SELECT * FROM zzzzsys_user WHERE sus_login_name = ?', [$login]);
			if (!$row) $res['error'] = "No user with the login name $login.";
			elseif (nu_is_expired($row['sus_expires_on'])) $res['error'] = "The user $login has expired.";
			else { NuAuth::actAs($row); $actor = ['id' => (string)$row['zzzzsys_user_id'], 'login' => $login]; }
		}
		$t0 = microtime(true);
		if ($res['error'] === '') {
			require_once NU_ROOT . '/core/form/nuphp_api.php';
			NuPhp::reset();
			try {
				$r = NuPhp::procedure((string)$php['sph_code'], NuHash::plain($o['props'], ['NU_CRON' => '1']));
				$errs = array_map(static fn(array $e): string => (string)$e['message'], NuPhp::result()['errors']);
				if (empty($r['ok'])) $res['error'] = (string)($r['error'] ?? 'failed');
				elseif ($errs) $res['error'] = implode("\n", $errs);
				else $res['ok'] = true;
			} catch (Throwable $e) {
				$res['error'] = $e->getMessage();
			}
			$res['output'] = NuPhp::$output;
		}
		$res['ms'] = (int)round((microtime(true) - $t0) * 1000);
		$det = ['code' => $code, 'user' => $actor['login'], 'ms' => $res['ms']] + ($o['props'] ? ['values' => array_keys($o['props'])] : []);
		try {
			NuLog::write($res['ok'] ? 'procedure_cron' : 'procedure_cron_failed', 'zzzzsys_php', (string)($php['zzzzsys_php_id'] ?? ''),
				$det + ($res['ok'] ? [] : ['error' => mb_substr($res['error'], 0, 1000)]), $actor);
		} catch (Throwable) {
			// the log must not turn a finished run into a failed one
		}
		if (!$res['ok']) {
			$to = self::addresses($o['mail'] ?? self::settings()['mail']) ?? [];
			if ($to && !NuMail::configured()) $res['mail_error'] = 'the e-mail settings are not complete';
			elseif ($to) {
				$name = trim((string)($php['sph_description'] ?? '')) !== '' ? (string)$php['sph_description'] . " ($code)" : $code;
				$line = static fn(string $k, string $v): string => '<tr><td style="padding:2px 12px 2px 0;color:#666;vertical-align:top">' . h($k) . '</td><td style="padding:2px 0">' . nl2br(h($v)) . '</td></tr>';
				$html = '<p>' . h(t('cron_mail_intro')) . '</p><table style="border-collapse:collapse">'
					. $line(t('builder_run_procedure'), $name) . $line(t('cron_mail_when'), date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')')
					. $line(t('cron_mail_user'), $actor['login']) . $line(t('cron_mail_app'), nu_app_title() . (NuSecurity::mailBaseUrl() !== '' ? ' – ' . NuSecurity::mailBaseUrl() : ''))
					. $line(t('cron_mail_error'), mb_substr($res['error'], 0, 4000))
					. (trim($res['output']) !== '' ? $line(t('cron_mail_output'), mb_substr(trim(strip_tags($res['output'])), 0, 4000)) : '') . '</table>';
				foreach ($to as $a) {
					try { $err = NuMail::send($a, t('cron_mail_subject', $code), $html, 'cron'); } catch (Throwable $e) { $err = $e->getMessage(); }
					if ($err === null) $res['mailed'][] = $a; else $res['mail_error'] = (string)$err;
				}
			}
		}
		return $res;
	}
}
