<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * E-mail: settings (zzzzsys_setup), sending (PHPMailer) and the e-mail log (zzzzsys_email_log).
 *
 * The global functions nuEmail(), nuSendEmail(), nuSendEmailEx(), nuMarshallEmailSettings(), nuEmailPHP(),
 * nuUnescapeQuotes(), nuIsArrayAssociative() and db_setup() have the same names, parameters and return
 * values as in nuBuilder (core/nuemailer.php, core/nucommon.php), so existing PHP code in forms and
 * procedures keeps working. nuEmail() returns [ok, message, ErrorInfo, debug output, rejected addresses];
 * index 4 is new and lists addresses that were skipped because they are invalid.
 *
 * PHPMailer is taken from nuBuilder (<nuBuilder>/third_party/phpmailer/), falling back to lib/PHPMailer.
 */

final class NuMailer
{
	public const LOG_TABLE = 'zzzzsys_email_log';

	/** Column sizes of zzzzsys_email_log (values are shortened to fit). */
	private const LOG_LEN = [
		'eml_state' => 20, 'eml_from' => 70, 'eml_from_name' => 70, 'eml_to' => 4000, 'eml_cc' => 4000, 'eml_bcc' => 4000,
		'eml_subject' => 250, 'eml_file' => 1000, 'eml_reply_to' => 100, 'eml_form_id' => 25, 'eml_user_id' => 25,
		'eml_record_id' => 25, 'eml_error' => 200, 'eml_table_name' => 100, 'eml_tag' => 70,
	];

	private static ?array $setup = null;
	private static ?string $phpMailerDir = null;

	// ================================================================== PHPMailer

	/** Directories searched for PHPMailer.php, in this order. */
	public static function phpMailerCandidates(): array
	{
		$dirs = [];
		$cfg = trim((string)nu_cfg('nuConfigPHPMailerPath'));
		if ($cfg !== '') $dirs[] = self::absPath($cfg);
		$pack = NuLibs::activeDir('phpmailer');                  // library pack (0.8.405): files at the top or in src/
		if ($pack !== null) $dirs[] = is_file($pack . '/PHPMailer.php') ? $pack : $pack . '/src';
		$dirs[] = self::nuBuilderPath() . '/third_party/phpmailer';
		$dirs[] = NU_ROOT . '/lib/PHPMailer';
		return array_values(array_unique($dirs));
	}

	/** nuBuilder's root folder (see NuBuilderLib::path()). */
	public static function nuBuilderPath(): string
	{
		return NuBuilderLib::path();
	}

	private static function absPath(string $p): string
	{
		return NuBuilderLib::absPath($p);
	}

	/** Loads PHPMailer once. Returns the folder it was loaded from (or 'preloaded'), null when not found. */
	public static function loadPHPMailer(): ?string
	{
		if (self::$phpMailerDir !== null) return self::$phpMailerDir;
		if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)) return self::$phpMailerDir = 'preloaded';
		foreach (self::phpMailerCandidates() as $dir) {
			if (!is_file("$dir/PHPMailer.php") || !is_file("$dir/SMTP.php") || !is_file("$dir/Exception.php")) continue;
			require_once "$dir/Exception.php";
			if (is_file("$dir/OAuthTokenProvider.php")) require_once "$dir/OAuthTokenProvider.php";   // XOAUTH2 (0.8.445, NuMailOAuth)
			require_once "$dir/PHPMailer.php";
			require_once "$dir/SMTP.php";
			return self::$phpMailerDir = $dir;
		}
		return null;
	}

	public static function phpMailerVersion(): string
	{
		return self::loadPHPMailer() ? \PHPMailer\PHPMailer\PHPMailer::VERSION : '';
	}

	// ================================================================== settings (zzzzsys_setup)

	/** First row of zzzzsys_setup (+ set_timezone like nuBuilder's db_setup()), [] when the table is missing. */
	public static function setupRow(bool $fresh = false): array
	{
		if (self::$setup !== null && !$fresh) return self::$setup;
		try {
			$db = nu_db();
			if (!$db->tableExists('zzzzsys_setup')) return self::$setup = [];
			$row = $db->page('SELECT * FROM zzzzsys_setup', [], 'zzzzsys_setup_id', 1, 0)[0] ?? [];
			if ($row && !array_key_exists('set_timezone', $row) && $db->tableExists('zzzzsys_timezone') && (string)($row['set_zzzzsys_timezone_id'] ?? '') !== '') {
				$row['set_timezone'] = $db->value('SELECT stz_timezone FROM zzzzsys_timezone WHERE zzzzsys_timezone_id = ?', [$row['set_zzzzsys_timezone_id']]);
			}
			return self::$setup = $row;
		} catch (Throwable $e) {
			error_log('nuBuilder Next setup: ' . $e->getMessage());
			return self::$setup = [];
		}
	}

	/**
	 * A text value from zzzzsys_setup. nuBuilder fills every SMTP field with '1' when the settings are reset,
	 * so '1' counts as "not set" (except for the two yes/no flags).
	 */
	public static function setupValue(string $key): string
	{
		$v = trim((string)(self::setupRow()[$key] ?? ''));
		return $v === '1' && !in_array($key, ['set_smtp_use_authentication', 'set_smtp_use_ssl'], true) ? '' : $v;
	}

	/**
	 * Effective SMTP settings.
	 * Source: zzzzsys_setup (set_smtp_*), unless $nuConfigSMTPHost is set in nuconfig.php – then the
	 * $nuConfigSMTP* variables are used (kept from nuBuilder Next 0.3).
	 *
	 * @return array{source:string,host:string,port:int,username:string,password:string,auth:bool,secure:string,from_address:string,from_name:string,use_ssl:bool}
	 */
	/** After the test e-mail: AUTH methods the server offered (last EHLO answer) and the one the client sent (AUTH …). */
	private static function probeRead(array $raw, object $s): void
	{
		$offered = null;
		$used = '';
		$saw = false;
		foreach ($raw as $entry) {
			if (preg_match('/CLIENT -> SERVER: AUTH ([A-Z0-9-]+)/', $entry, $m)) $used = $m[1];
			$p = strpos($entry, 'SERVER -> CLIENT:');
			if ($p === false) continue;
			$ehlo = null;                                                       // a multi-line answer comes as one entry
			foreach (preg_split('/\R/', substr($entry, $p + 17)) ?: [] as $l) {
				$l = trim($l);
				if (!preg_match('/^250[- ]/', $l)) continue;
				$saw = true;
				$ehlo ??= [];
				if (preg_match('/^250[- ]AUTH[ =](.+)$/i', $l, $m)) $ehlo = array_values(array_filter(preg_split('/\s+/', strtoupper(trim($m[1]))) ?: []));
			}
			if ($ehlo !== null && preg_match('/^\s*250-/', substr($entry, $p + 17))) $offered = $ehlo;   // EHLO answers are multi-line (250-…)
		}
		if ($offered === null && $saw) $offered = [];
		self::$probeResult = ['offered' => $offered, 'used' => $used, 'forced' => (bool)$s->smtp_use_authentication ? (string)($s->auth_type ?? '') : '', 'auth' => (bool)$s->smtp_use_authentication];
	}

	/** The base64 string after "AUTH XOAUTH2" holds the access token – hidden in every debug output (0.8.445). */
	public static function maskOAuth(string $line): string
	{
		return (string)preg_replace('/(AUTH XOAUTH2\s+)\S+/i', '$1********', $line);
	}

	/** SMTP authentication methods that can be forced ('' = automatic: PHPMailer takes CRAM-MD5, LOGIN, PLAIN, XOAUTH2 – the first the server offers). */
	public const AUTH_TYPES = ['', 'LOGIN', 'PLAIN', 'CRAM-MD5', 'XOAUTH2'];   // XOAUTH2 = OAuth 2 (0.8.445, NuMailOAuth)
	private const AUTH_SETTING = 'mail_auth_type';

	/**
	 * Forced SMTP authentication method (0.8.342) – nuBuilder Next's own setting (zzzzsys_nunext_setting 'mail_auth_type'), because
	 * nuBuilder's zzzzsys_setup has no column for it; applies to both sources (E-mail settings and the nuconfig.php SMTP settings).
	 */
	public static function authType(): string
	{
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::AUTH_SETTING]);
		} catch (Throwable) {
			return '';
		}
		$v = strtoupper(trim((string)$v));
		return in_array($v, self::AUTH_TYPES, true) ? $v : '';
	}

	public static function setAuthType(string $type): bool
	{
		$type = strtoupper(trim($type));
		if (!in_array($type, self::AUTH_TYPES, true)) $type = '';
		$old = self::authType();
		if ($old === $type) return false;
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => $type, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => (string)(NuAuth::user()['login'] ?? '')];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::AUTH_SETTING])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::AUTH_SETTING);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::AUTH_SETTING] + $row);
		return true;
	}

	/** Test e-mail (E-mail → Test): set to true before sending; afterwards $probe holds what the server offered and what was used. */
	public static bool $probe = false;
	/** @var array{offered: ?array, used: string, forced: string, auth: bool}|null */
	public static ?array $probeResult = null;

	public static function settings(): array
	{
		if (trim((string)nu_cfg('nuConfigSMTPHost')) !== '') {
			$user = (string)nu_cfg('nuConfigSMTPUsername');
			$secure = strtolower(trim((string)nu_cfg('nuConfigSMTPSecure')));
			$port = (int)(nu_cfg('nuConfigSMTPPort') ?: 587);
			return [
				'source' => 'nuconfig', 'host' => trim((string)nu_cfg('nuConfigSMTPHost')), 'port' => $port,
				'username' => $user, 'password' => (string)nu_cfg('nuConfigSMTPPassword'), 'auth' => $user !== '',
				'secure' => in_array($secure, ['ssl', 'tls'], true) ? $secure : ($secure === 'none' ? 'none' : self::secureForPort($port, false)),
				'from_address' => trim((string)nu_cfg('nuConfigSMTPFromAddress')), 'from_name' => (string)nu_cfg('nuConfigSMTPFromName'),
				'use_ssl' => $secure === 'ssl', 'auth_type' => self::authType(),
			];
		}

		$port   = (int)self::setupValue('set_smtp_port') ?: 25;
		$user   = self::setupValue('set_smtp_username');
		$authV  = self::setupValue('set_smtp_use_authentication');
		$useSsl = self::setupValue('set_smtp_use_ssl') === '1';
		return [
			'source'       => 'setup',
			'host'         => self::setupValue('set_smtp_host'),
			'port'         => $port,
			'username'     => $user,
			'password'     => self::setupValue('set_smtp_password'),
			// nuBuilder: '1' = authenticate. Not set at all → authenticate when a user name is given.
			'auth'         => $authV === '1' || ($authV === '' && $user !== ''),
			'secure'       => self::secureForPort($port, $useSsl),
			'from_address' => self::setupValue('set_smtp_from_address'),
			'from_name'    => self::setupValue('set_smtp_from_name'),
			'use_ssl'      => $useSsl,
			'auth_type'    => self::authType(),
		];
	}

	/**
	 * Encryption as nuBuilder does it: 465 → ssl (SMTPS), 587 → tls (STARTTLS), any other port → none
	 * (PHPMailer still upgrades with STARTTLS when the server offers it). New: on non-standard ports
	 * (not 25/465/587) "Use SSL" = 1 selects SMTPS; nuBuilder ignores that flag.
	 */
	public static function secureForPort(int $port, bool $useSsl): string
	{
		return match (true) {
			$port === 465 => 'ssl',
			$port === 587 => 'tls',
			$useSsl && $port !== 25 => 'ssl',
			default => '',
		};
	}

	/**
	 * Connection check without sending (0.8.432, E-mail → Test → Check connection): connect, EHLO, encryption (SMTPS or STARTTLS), login and
	 * MAIL FROM with the sender address, then RSET and QUIT – no message is transferred (no RCPT, no DATA). Uses the saved settings.
	 * @return array{ok: bool, steps: list<array{0: string, 1: ?bool, 2: string}>, debug: string} steps: [server|tls|login|sender, ok (null = note), detail]
	 */
	public static function check(int $timeout = 15): array
	{
		$s = self::settings();
		$steps = [];
		$debug = [];
		$add = static function (string $k, ?bool $ok, string $d = '') use (&$steps): void { $steps[] = [$k, $ok, $d]; };
		$done = static function () use (&$steps, &$debug): array {
			return ['ok' => !in_array(false, array_column($steps, 1), true), 'steps' => $steps, 'debug' => implode("\n", $debug)];
		};
		if ($s['host'] === '') { $add('server', false, t('mail_chk_no_host')); return $done(); }
		if (!self::loadPHPMailer()) { $add('server', false, 'PHPMailer not found in: ' . implode(', ', self::phpMailerCandidates())); return $done(); }
		$secret = array_filter([(string)$s['password'], base64_encode((string)$s['password']), base64_encode("\0" . $s['username'] . "\0" . $s['password'])], static fn($x) => strlen($x) > 2);
		$smtp = new \PHPMailer\PHPMailer\SMTP();
		$smtp->do_debug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
		$smtp->Debugoutput = static function (string $str) use (&$debug, $secret): void { $debug[] = self::maskOAuth(rtrim(str_replace($secret, '********', $str))); };
		$smtp->Timeout = $timeout;
		if (property_exists($smtp, 'Timelimit')) $smtp->Timelimit = $timeout;
		$err = static function () use ($smtp): string {
			$e = $smtp->getError();
			return trim(implode(' ', array_filter([(string)($e['error'] ?? ''), (string)($e['smtp_code'] ?? ''), (string)($e['detail'] ?? '')])));
		};
		$where = $s['host'] . ':' . $s['port'];
		if (!$smtp->connect(($s['secure'] === 'ssl' ? 'ssl://' : '') . $s['host'], (int)$s['port'], $timeout)) {
			$add('server', false, $where . ' – ' . $err());
			return $done();
		}
		try {
			$me = (string)(gethostname() ?: 'localhost');
			if (!$smtp->hello($me)) { $add('server', false, $where . ' – ' . $err()); return $done(); }
			$add('server', true, $where);
			if ($s['secure'] === 'ssl') {
				$add('tls', true, 'SSL/TLS (SMTPS)');
			} elseif ($s['secure'] === 'tls' || ($s['secure'] === '' && $smtp->getServerExt('STARTTLS'))) {
				if (!$smtp->startTLS()) { $add('tls', false, 'STARTTLS – ' . $err()); return $done(); }
				$smtp->hello($me);
				$add('tls', true, 'STARTTLS');
			} else {
				$add('tls', null, t('mail_chk_plain'));
			}
			if ($s['auth']) {
				$oauth = null;
				$user = (string)$s['username'];
				if ((string)$s['auth_type'] === 'XOAUTH2') {      // OAuth 2 (0.8.445): the access token first – its error is clearer than the server's
					$user = $user !== '' ? $user : NuMailOAuth::settings()['account'];
					try { NuMailOAuth::accessToken(); $oauth = NuMailOAuth::tokenProvider($user); }
					catch (Throwable $e) { $add('login', false, $e->getMessage()); return $done(); }
				}
				if (!$smtp->authenticate($user, (string)$s['password'], (string)$s['auth_type'] !== '' ? (string)$s['auth_type'] : null, $oauth)) {
					$add('login', false, $user . ' – ' . $err());
					return $done();
				}
				$add('login', true, $user . ($oauth !== null ? ' · OAuth 2' : ''));
			} else {
				$add('login', null, t('mail_chk_no_auth'));
			}
			$from = (string)$s['from_address'];
			if (!self::validAddress($from)) $add('sender', false, $from === '' ? t('mail_chk_no_from') : $from);
			elseif ($smtp->mail($from)) { $add('sender', true, $from); $smtp->reset(); }
			else $add('sender', false, $from . ' – ' . $err());
		} finally {
			if ($smtp->connected()) $smtp->quit();
			$smtp->close();
			self::probeRead($debug, (object)['smtp_use_authentication' => $s['auth'], 'auth_type' => $s['auth_type']]);   // AUTH methods, as the test e-mail
		}
		return $done();
	}

	/** True when mails can be sent (an SMTP host is set, or $nuConfigMailLog redirects mails to a file). */
	public static function configured(): bool
	{
		return self::mailLogFile() !== '' || self::settings()['host'] !== '';
	}

	public static function mailLogFile(): string
	{
		$log = trim((string)nu_cfg('nuConfigMailLog'));
		if ($log === '') return '';
		return self::absPath($log);
	}

	/** Saves the SMTP settings to zzzzsys_setup (first row; created with id '1' when the table is empty). */
	public static function saveSettings(array $v): void
	{
		$db = nu_db();
		if (!$db->tableExists('zzzzsys_setup')) throw new RuntimeException('zzzzsys_setup missing');
		$cols = $db->columns('zzzzsys_setup');
		$data = [];
		foreach (['set_smtp_host', 'set_smtp_port', 'set_smtp_username', 'set_smtp_password', 'set_smtp_from_address',
			'set_smtp_from_name', 'set_smtp_use_authentication', 'set_smtp_use_ssl'] as $k) {
			if (array_key_exists($k, $v) && isset($cols[$k])) $data[$k] = $v[$k];
		}
		$row = self::setupRow(true);
		if ($row) $db->update('zzzzsys_setup', $data, 'zzzzsys_setup_id', (string)$row['zzzzsys_setup_id']);
		else $db->insert('zzzzsys_setup', ['zzzzsys_setup_id' => '1'] + $data);
		self::$setup = null;
	}

	// ================================================================== addresses & attachments

	/**
	 * Normalises a recipient list to [[address, name], …].
	 * Accepts: 'a@x.ch, b@y.ch; Name <c@z.ch>', ['a@x.ch', …] (nuBuilder ordinal), ['a@x.ch' => 'Name', …]
	 * (nuBuilder associative) and mixtures. Empty entries are dropped.
	 */
	public static function addressList(mixed $list): array
	{
		if ($list === null || $list === '' || $list === false) return [];
		if (!is_array($list)) $list = preg_split('/[,;\n]+/', (string)$list);
		$out = [];
		foreach ($list as $k => $v) {
			if (is_string($k) && str_contains($k, '@')) {           // 'address' => 'name'
				$out[] = [trim($k), trim((string)$v)];
				continue;
			}
			if (is_array($v)) { $out[] = [trim((string)($v[0] ?? $v['address'] ?? '')), trim((string)($v[1] ?? $v['name'] ?? ''))]; continue; }
			foreach (preg_split('/[,;\n]+/', (string)$v) as $item) {
				$item = trim($item);
				if ($item === '') continue;
				if (preg_match('/^(.*)<\s*([^>]+?)\s*>$/', $item, $m)) $out[] = [trim($m[2]), trim(trim($m[1]), "\"' ")];
				else $out[] = [$item, ''];
			}
		}
		return array_values(array_filter($out, static fn($a) => $a[0] !== ''));
	}

	public static function validAddress(string $a): bool
	{
		return filter_var($a, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $a);
	}

	/**
	 * Attachments. nuBuilder: ['file name' => '/path/to/file', …]. Also accepted:
	 *  - ['/path/a.pdf', …]                                  (name = basename)
	 *  - ['x.pdf' => ['data' => $bytes, 'type' => 'application/pdf']]  (string attachment)
	 *  - ['x.pdf' => 'data:application/pdf;base64,…']        (data URL)
	 *  - ['x.pdf' => '{"file":"…","name":"x.pdf",…}']      (nuBuilder file field JSON)
	 *
	 * @return array{0: array<int, array{name:string,path?:string,data?:string,type:string}>, 1: string[]}  [attachments, errors]
	 */
	public static function attachmentList(mixed $list): array
	{
		if (!is_array($list) || !$list) return [[], []];
		$out = $errors = [];
		foreach ($list as $k => $v) {
			$name = is_string($k) ? $k : '';
			if (is_array($v)) {
				$data = $v['data'] ?? null;
				if (!is_string($data)) { $errors[] = "Attachment $name: no data"; continue; }
				$out[] = ['name' => $name !== '' ? $name : (string)($v['name'] ?? 'attachment'), 'data' => $data, 'type' => (string)($v['type'] ?? '')];
				continue;
			}
			$v = (string)$v;
			if (str_starts_with(ltrim($v), '{')) {                       // nuBuilder file field
				$j = json_decode($v, true);
				if (is_array($j) && isset($j['file'])) {
					$url = (string)$j['file'];
					if (!str_starts_with($url, 'data:')) $url = (string)base64_decode($url, true);
					$v = $url;
					if ($name === '') $name = (string)($j['name'] ?? '');
				}
			}
			if (str_starts_with($v, 'data:')) {
				if (!preg_match('/^data:([^;,]*)(;base64)?,(.*)$/s', $v, $m)) { $errors[] = "Attachment $name: invalid data URL"; continue; }
				$out[] = ['name' => $name !== '' ? $name : 'attachment', 'data' => $m[2] !== '' ? (string)base64_decode($m[3]) : rawurldecode($m[3]), 'type' => $m[1]];
				continue;
			}
			if (!is_file($v) || !is_readable($v)) { $errors[] = 'Attachment not found: ' . ($name !== '' ? "$name ($v)" : $v); continue; }
			$out[] = ['name' => $name !== '' ? $name : basename($v), 'path' => $v, 'type' => ''];
		}
		return [$out, $errors];
	}

	// ================================================================== sending

	/**
	 * Sends one message. All nuEmail() parameters, already normalised.
	 * @return array{0:bool,1:string,2:string,3:string,4:array}
	 */
	public static function send(array $o): array
	{
		if (NuDemo::active()) return [false, t('demo_not_available'), 'demo mode', '', []];   // demo mode: no e-mails (core/nudemo.php)
		$rejected = [];
		$lists = [];
		foreach (['to', 'cc', 'bcc', 'reply_to'] as $kind) {
			$lists[$kind] = [];
			foreach (self::addressList($o[$kind] ?? []) as [$addr, $nm]) {
				if (self::validAddress($addr)) $lists[$kind][] = [$addr, $nm];
				else $rejected[] = $addr;
			}
		}
		$fail = static fn(string $msg, string $info = '', string $dbg = '') => [false, $msg, $info, $dbg, $rejected];

		if (!$lists['to'] && !$lists['cc'] && !$lists['bcc']) {
			return $fail('Message sending failed', $rejected ? 'Invalid address: ' . implode(', ', $rejected) : 'No recipient');
		}
		[$files, $fileErrors] = self::attachmentList($o['files'] ?? []);
		if ($fileErrors) return $fail('Message sending failed', implode('; ', $fileErrors));

		$s = $o['settings'];
		$from = trim((string)$s->from_address);
		if ($from === '' || !self::validAddress($from)) {
			return $fail('Message sending failed', $from === '' ? 'No sender address (Setup → Email → From address)' : "Invalid sender address: $from");
		}

		// testing: write the message to a file instead of sending it
		if (($log = self::mailLogFile()) !== '') {
			$entry = ['time' => date('c'), 'from' => $from, 'from_name' => $s->from_name, 'to' => $lists['to'], 'cc' => $lists['cc'],
				'bcc' => $lists['bcc'], 'reply_to' => $lists['reply_to'], 'subject' => (string)$o['subject'], 'html' => (bool)$s->html,
				'body' => (string)$o['body'], 'files' => array_column($files, 'name'), 'priority' => $o['priority']];
			$ok = @file_put_contents($log, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
			return $ok === false ? $fail('Message sending failed', 'Mail log not writable: ' . $log) : [true, 'Message sent successfully', '', '', $rejected];
		}

		if (!self::loadPHPMailer()) {
			return $fail('Message sending failed', 'PHPMailer not found in: ' . implode(', ', self::phpMailerCandidates()));
		}

		$debug = [];
		$secret = [(string)$s->password, base64_encode((string)$s->password), base64_encode("\0" . $s->username . "\0" . $s->password)];
		$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
		try {
			$level = (int)$o['debug'];
			$raw = [];
			// test e-mail (0.8.342): the server conversation is read for its AUTH list even when the chosen debug level shows less
			$mail->SMTPDebug   = self::$probe ? max($level, 2) : $level;
			$mail->Debugoutput = static function (string $str, int $lvl = 0) use (&$debug, &$raw, $secret, $level): void {
				$line = self::maskOAuth(rtrim(str_replace(array_filter($secret, static fn($x) => strlen($x) > 2), '********', $str)));
				$raw[] = $line;
				if ($lvl <= $level) $debug[] = $line;
			};
			$method = strtolower((string)$o['method']);
			if ($method === 'sendmail') $mail->isSendmail();
			elseif ($method === 'mail') $mail->isMail();
			else $mail->isSMTP();

			$mail->CharSet  = (string)$s->charset;
			$mail->WordWrap = (int)$s->word_wrap;
			$mail->Host     = (string)$s->host;
			$mail->Port     = (int)$s->smtp_port;
			$mail->SMTPAuth = (bool)$s->smtp_use_authentication;
			if ($mail->SMTPAuth && (string)($s->auth_type ?? '') !== '') $mail->AuthType = (string)$s->auth_type;   // forced method (0.8.342)
			$mail->Username = (string)$s->username;
			if ($mail->SMTPAuth && (string)($s->auth_type ?? '') === 'XOAUTH2') {   // OAuth 2 (0.8.445): the connected mailbox, token from NuMailOAuth
				if ($mail->Username === '') $mail->Username = NuMailOAuth::settings()['account'];
				NuMailOAuth::accessToken();                       // a refresh error stops here with the provider's message
				$mail->setOAuth(NuMailOAuth::tokenProvider($mail->Username));
			}
			$mail->Password = (string)$s->password;
			$mail->Timeout  = (int)($s->timeout ?? 20);
			// PHPMailer waits up to SMTP::$Timelimit (300 s) for each server reply – limit it to the timeout as well
			$smtp = $mail->getSMTPInstance();
			if (property_exists($smtp, 'Timelimit')) $smtp->Timelimit = $mail->Timeout;
			if ($s->smtp_secure === 'none') { $mail->SMTPSecure = ''; $mail->SMTPAutoTLS = false; }
			else $mail->SMTPSecure = (string)$s->smtp_secure;
			if (is_array($o['smtp_options']) && $o['smtp_options']) $mail->SMTPOptions = $o['smtp_options'];

			$mail->setFrom($from, (string)$s->from_name, false);
			foreach ($lists['to'] as [$a, $n]) $mail->addAddress($a, $n);
			foreach ($lists['cc'] as [$a, $n]) $mail->addCC($a, $n);
			foreach ($lists['bcc'] as [$a, $n]) $mail->addBCC($a, $n);
			foreach ($lists['reply_to'] as [$a, $n]) $mail->addReplyTo($a, $n);
			foreach ($files as $f) {
				if (isset($f['path'])) $mail->addAttachment($f['path'], $f['name']);
				else $mail->addStringAttachment($f['data'], $f['name'], \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, $f['type']);
			}

			if (($p = self::priority($o['priority'])) !== null) {
				$mail->Priority = $p;
				$mail->addCustomHeader('Importance', $p === 1 ? 'High' : ($p === 5 ? 'Low' : 'Normal'));
			}

			$mail->Subject = (string)$o['subject'];
			$mail->Body    = (string)$o['body'];
			$mail->isHTML((bool)$s->html);
			if ($s->html) $mail->AltBody = self::plainText((string)$o['body']);

			try {
				$mail->send();
			} finally {
				if (self::$probe) self::probeRead($raw, $s);
			}
			return [true, 'Message sent successfully', '', implode("\n", $debug), $rejected];
		} catch (\PHPMailer\PHPMailer\Exception $e) {
			return $fail($e->errorMessage(), (string)$mail->ErrorInfo, implode("\n", $debug));
		} catch (Throwable $e) {
			return $fail('Message sending failed', $e->getMessage(), implode("\n", $debug));
		}
	}

	/** 1 = high, 3 = normal, 5 = low; also accepts 'high'/'normal'/'low' and 2/4. null = not set. */
	public static function priority(mixed $p): ?int
	{
		if ($p === null || $p === '' || $p === false) return null;
		if (is_string($p) && !is_numeric($p)) {
			return match (strtolower(trim($p))) { 'high', 'urgent', 'hoch' => 1, 'low', 'niedrig' => 5, 'normal' => 3, default => null };
		}
		$p = (int)$p;
		return $p >= 1 && $p <= 5 ? $p : null;
	}

	public static function plainText(string $html): string
	{
		$t = preg_replace(['~<(br|/p|/div|/tr|/h\d|/li)\b[^>]*>~i', '~<(style|script)\b.*?</\1>~is'], ["\n", ''], $html);
		$t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim((string)preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $t)));
	}

	// ================================================================== log (zzzzsys_email_log)

	/** Creates zzzzsys_email_log when it does not exist (same columns as nuBuilder). */
	public static function ensureLogTable(): void
	{
		static $done = false;
		if ($done) return;
		$done = true;
		$db = nu_db();
		if ($db->tableExists(self::LOG_TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$cols = [
			'zzzzsys_email_log_id ' . $T('id') . ' NOT NULL PRIMARY KEY',
			'eml_created_at ' . $T('datetime') . ' NULL', 'eml_sent_at ' . $T('datetime') . ' NULL',
			'eml_state ' . $T('text', '20') . ' NULL', 'eml_from ' . $T('text', '70') . ' NULL', 'eml_from_name ' . $T('text', '70') . ' NULL',
			'eml_to ' . $T('text', '4000') . ' NULL', 'eml_cc ' . $T('text', '4000') . ' NULL', 'eml_bcc ' . $T('text', '4000') . ' NULL',
			'eml_subject ' . $T('text', '250') . ' NULL', 'eml_body ' . $T('longtext') . ' NULL', 'eml_file ' . $T('text', '1000') . ' NULL',
			'eml_html ' . ($db->type === 'sqlsrv' ? 'TINYINT' : $T('bool')) . ' NULL', 'eml_reply_to ' . $T('text', '100') . ' NULL',
			'eml_form_id ' . $T('id') . ' NULL', 'eml_user_id ' . $T('id') . ' NULL', 'eml_record_id ' . $T('id') . ' NULL',
			'eml_importance ' . ($db->type === 'sqlite' ? 'INTEGER' : 'SMALLINT') . ' NULL', 'eml_error ' . $T('text', '200') . ' NULL',
			'eml_table_name ' . $T('text', '100') . ' NULL', 'eml_tag ' . $T('text', '70') . ' NULL', 'eml_json ' . $T('longtext') . ' NULL',
		];
		$sql = 'CREATE TABLE ' . self::LOG_TABLE . " (\n  " . implode(",\n  ", $cols) . "\n)" . ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : '');
		try {
			$db->run($sql);
			$db->run('CREATE INDEX ix_eml_created_at ON ' . self::LOG_TABLE . ' (eml_created_at)');
		} catch (Throwable $e) {
			if (!$db->tableExists(self::LOG_TABLE)) throw $e;
		}
	}

	/** Current time in a format every driver reads unambiguously. */
	public static function now(): string
	{
		return nu_db()->type === 'sqlsrv' ? date('Y-m-d\TH:i:s') : date('Y-m-d H:i:s');
	}

	/**
	 * Writes one row to zzzzsys_email_log. Never throws (logging must not turn a sent mail into an error).
	 * @param array $args   nuSendEmail() arguments (to, cc, bcc, from_email, from_name, subject, body, html, reply_to, attachments, priority)
	 * @param array $result nuEmail() result
	 * @param array $ctx    table_name, tag, form_id, user_id, record_id, json
	 */
	public static function log(array $args, array $result, array $ctx): ?string
	{
		try {
			self::ensureLogTable();
			$list = static fn($v) => is_array($v) ? implode(', ', array_map(static fn($a) => $a[1] !== '' ? "{$a[1]} <{$a[0]}>" : $a[0], self::addressList($v))) : trim((string)$v);
			$ok = (bool)($result[0] ?? false);
			$error = null;
			if (!$ok) {
				$msg = trim(strip_tags((string)($result[1] ?? '')));
				$info = trim(strip_tags((string)($result[2] ?? '')));
				$error = $info !== '' && $info !== $msg ? "$msg\n$info" : $msg;
			}
			elseif (!empty($result[4])) $error = 'Skipped invalid address: ' . implode(', ', $result[4]);

			$files = [];
			foreach ((array)($args['attachments'] ?? []) as $k => $v) {
				$files[is_string($k) ? $k : basename((string)(is_array($v) ? ($v['name'] ?? '') : $v))] = is_string($v) && !str_starts_with($v, 'data:') && !str_starts_with(ltrim($v), '{') ? $v : '(data)';
			}
			$json = $ctx['json'] ?? null;
			if ($json !== null && !is_string($json)) $json = json_encode($json, JSON_UNESCAPED_UNICODE);
			$replyTo = $args['reply_to'] ?? [];

			$row = [
				'zzzzsys_email_log_id' => nu_id(),
				'eml_created_at'       => self::now(),
				'eml_sent_at'          => $ok ? self::now() : null,
				'eml_state'            => $ok ? 'sent' : 'error',
				'eml_from'             => (string)($args['from_email'] ?? ''),
				'eml_from_name'        => (string)($args['from_name'] ?? ''),
				'eml_to'               => $list($args['to'] ?? ''),
				'eml_cc'               => $list($args['cc'] ?? ''),
				'eml_bcc'              => $list($args['bcc'] ?? ''),
				'eml_subject'          => (string)($args['subject'] ?? ''),
				'eml_body'             => (string)($args['body'] ?? ''),
				'eml_file'             => $files ? json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '[]',
				'eml_html'             => !empty($args['html']) ? 1 : 0,
				'eml_reply_to'         => is_array($replyTo) ? json_encode($replyTo, JSON_UNESCAPED_UNICODE) : (string)$replyTo,
				'eml_importance'       => self::priority($args['priority'] ?? null),
				'eml_form_id'          => $ctx['form_id'] ?? null,
				'eml_user_id'          => $ctx['user_id'] ?? null,
				'eml_error'            => $error,
				'eml_record_id'        => $ctx['record_id'] ?? null,
				'eml_table_name'       => $ctx['table_name'] ?? null,
				'eml_tag'              => $ctx['tag'] ?? null,
				'eml_json'             => $json,
			];
			foreach (self::LOG_LEN as $col => $len) {
				if ($row[$col] !== null) $row[$col] = mb_substr((string)$row[$col], 0, $len);
			}
			$cols = nu_db()->columns(self::LOG_TABLE);
			if ($cols) $row = array_intersect_key($row, $cols);       // older nuBuilder versions have fewer columns
			nu_db()->insert(self::LOG_TABLE, $row);
			return $row['zzzzsys_email_log_id'];
		} catch (Throwable $e) {
			error_log('nuBuilder Next email log: ' . $e->getMessage());
			return null;
		}
	}

	/** Context of the running form/procedure (nuBuilder: nuFormTable(), nuFormId(), nuUserId(), nuRecordId()). */
	public static function context(): array
	{
		$h = class_exists('NuPhp', false) && NuPhp::$hash ? NuPhp::$hash : [];
		$u = NuAuth::currentRaw();
		$table = '';
		if (!empty($h['FORM_ID']) && ($m = NuFormMeta::load((string)$h['FORM_ID']))) $table = (string)($m->form['sfo_table'] ?? '');
		$rid = (string)($h['RECORD_ID'] ?? '');
		return [
			'table_name' => $table !== '' ? $table : null,
			'tag'        => null,
			'form_id'    => ($h['FORM_ID'] ?? '') !== '' ? (string)$h['FORM_ID'] : null,
			'user_id'    => ($u['id'] ?? '') !== '' ? (string)$u['id'] : null,
			'record_id'  => $rid !== '' && $rid !== '-1' ? $rid : null,
			'json'       => null,
		];
	}
}

/**
 * nuBuilder Next's own messages (2FA codes, password reset). Uses the same settings and sender as nuEmail().
 * The log row keeps subject and recipient but not the body, which contains codes and reset links.
 */
final class NuMail
{
	public static function settings(): array
	{
		$s = NuMailer::settings();
		return $s + ['from' => $s['from_address']];
	}

	public static function configured(): bool
	{
		return NuMailer::configured();
	}

	/** @return string|null  null on success, otherwise an error message */
	public static function send(string $to, string $subject, string $html, string $tag = 'nunext'): ?string
	{
		$args = ['to' => $to, 'subject' => $subject, 'body' => $html, 'html' => true];
		$r = nuEmail([$to], '', '', $html, $subject, [], true);
		$s = NuMailer::settings();
		NuMailer::log(['from_email' => $s['from_address'], 'from_name' => $s['from_name'], 'body' => '[' . $tag . ']'] + $args, $r,
			['tag' => $tag, 'user_id' => (NuAuth::currentRaw()['id'] ?? '') ?: null]);
		if ($r[0]) return null;
		error_log('nuBuilder Next mail: ' . $r[1] . ' ' . $r[2]);
		return t('mail_failed') . (nu_cfg('nuConfigDebug') ? ' ' . $r[2] : '');
	}

	/** Simple branded HTML mail body. */
	public static function layout(string $title, string $bodyHtml): string
	{
		$dir = nu_lang_rtl() ? ' dir="rtl"' : '';            // mails are built in the recipient's language (nu_lang() switched meanwhile)
		return '<!doctype html><html lang="' . h(nu_lang()) . '"' . $dir . '><body' . $dir . ' style="margin:0;padding:24px;background:#f4f6f9;font-family:Segoe UI,Tahoma,Arial,sans-serif;color:#1d2433">'
			. '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;padding:28px;border:1px solid #e3e7ee">'
			. '<div style="font-weight:700;font-size:18px;margin-bottom:16px">' . h($title) . '</div>' . $bodyHtml
			. '<div style="margin-top:24px;font-size:12px;color:#7a8394">nuBuilder Next</div></div></body></html>';
	}
}

/**
 * Welcome e-mail for a user (user form → "Send welcome e-mail", like nuBuilder's user_send_welcome_email).
 * Order, as in nuBuilder: own procedure nu_send_welcome_email (zzzzsys_php) → e-mail template nu_send_welcome_email
 * (zzzzsys_email_template – a clone of nuBuilder's nu_send_welcome_email_template; user's language first) → built-in text in the user's language.
 * Instead of a password the mail carries a personal link to set one (reset page, valid $nuConfigWelcomeLinkHours, once).
 * Placeholders: every sus_… field, #RECORD_ID#, #LOGIN_URL#, #NUNEXT_URL#, #NUBUILDER_URL#, #SET_PASSWORD_URL#,
 * #SET_PASSWORD_HOURS#, #PASSWORD_MIN_LENGTH# and #check_password# (the password typed in the form – only for old templates).
 */
final class NuWelcome
{
	public const PROCEDURE = 'nu_send_welcome_email';
	public const TEMPLATE  = 'nu_send_welcome_email';       // nuBuilder: clone of nu_send_welcome_email_template

	public static function hours(): int
	{
		return max(1, min(720, (int)(nu_cfg('nuConfigWelcomeLinkHours') ?? 72) ?: 72));
	}

	/** @return string|null  null when sent, otherwise the error */
	public static function send(array $user, string $password = ''): ?string
	{
		$email = trim((string)($user['sus_email'] ?? ''));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return t('welcome_err_email');
		if (!NuMail::configured()) return t('welcome_err_mail');
		$uid = (string)$user['zzzzsys_user_id'];

		$token = NuToken::create('pw_reset', $uid, self::hours() * 3600);
		$base  = nu_base_url();
		$hash  = [];
		foreach ($user as $k => $v) if ($k !== 'sus_login_password' && (is_scalar($v) || $v === null)) $hash[$k] = (string)$v;
		$hash += [
			'RECORD_ID' => $uid, 'LOGIN_URL' => $base . '/index.php?p=login', 'NUNEXT_URL' => $base . '/',
			'NUBUILDER_URL' => trim((string)nu_cfg('nuConfigNuBuilderURL')) ?: $base . '/',
			'SET_PASSWORD_URL' => $base . '/index.php?' . http_build_query(['p' => 'reset', 'u' => $uid, 't' => $token]),
			'SET_PASSWORD_HOURS' => (string)self::hours(), 'PASSWORD_MIN_LENGTH' => (string)max(1, (int)nu_cfg('nuConfigPasswordMinLength')),
			'check_password' => $password,
		];

		// 1. own procedure (nuBuilder: nuRunProcedure('nu_send_welcome_email'))
		$db = nu_db();
		$proc = $db->tableExists('zzzzsys_php') ? $db->one('SELECT sph_php FROM zzzzsys_php WHERE sph_code = ?', [self::PROCEDURE]) : null;
		if ($proc) {
			NuPhp::reset();
			$ok = NuPhp::run((string)$proc['sph_php'], $hash, self::PROCEDURE);
			$err = $ok ? null : implode(' ', array_column(NuPhp::$errors, 'message'));
			self::log($uid, $email, 'procedure', $err);
			return $err;
		}

		// 2. e-mail template, 3. built-in text – both in the user's language
		$lang = nu_lang_from_db((string)($user['sus_language'] ?? '')) ?? nu_lang();
		$prev = $_SESSION['nu_lang'] ?? null;
		$prevVar = $_SESSION['nu_lang_variant'] ?? null;
		$_SESSION['nu_lang'] = $lang;
		$uv = (string)NuUserJson::get((string)($user['zzzzsys_user_id'] ?? ''), 'NUNEXT_LANG_VARIANT');   // English UK / Australia / US
		$var = (NU_LANG_VARIANTS[$uv][0] ?? '') === $lang ? $uv : NuSetupWizard::defaultVariant($lang);
		if ($var !== null) $_SESSION['nu_lang_variant'] = $var; else unset($_SESSION['nu_lang_variant']);
		try {
			[$subject, $html, $source] = self::content($user, $hash, $lang);
		} finally {
			if ($prev === null) unset($_SESSION['nu_lang']); else $_SESSION['nu_lang'] = $prev;
			if ($prevVar === null) unset($_SESSION['nu_lang_variant']); else $_SESSION['nu_lang_variant'] = $prevVar;
		}
		$err = NuMail::send($email, $subject, $html, 'nunext_welcome');
		self::log($uid, $email, $source, $err);
		return $err;
	}

	/**
	 * What send() will use (0.8.508, toast of the user form): {proc: code|null, tpl: {emt_language: "code (language) – description"},
	 * fallback: database language of the current session (used when the user has none), code: TEMPLATE}.
	 */
	public static function sources(): array
	{
		$db = nu_db();
		$proc = $db->tableExists('zzzzsys_php') && $db->value('SELECT COUNT(*) FROM zzzzsys_php WHERE sph_code = ?', [self::PROCEDURE]) ? self::PROCEDURE : null;
		$tpl = [];
		if ($db->tableExists(NuEmailTemplate::TABLE)) {
			foreach ($db->all('SELECT emt_language, emt_description FROM ' . NuEmailTemplate::TABLE . " WHERE emt_code = ? AND COALESCE(emt_template,'') <> '1'", [self::TEMPLATE]) as $r) {
				$l = trim((string)$r['emt_language']);
				$tpl[$l] = self::TEMPLATE . ($l !== '' ? ' (' . $l . ')' : '') . (trim((string)$r['emt_description']) !== '' ? ' – ' . trim((string)$r['emt_description']) : '');
			}
		}
		return ['proc' => $proc, 'tpl' => (object)$tpl, 'fallback' => nu_lang_to_db(nu_lang()), 'code' => self::TEMPLATE];
	}

	/** @return array{0: string, 1: string, 2: string} subject, HTML, source ('template' / 'builtin') */
	private static function content(array $user, array $hash, string $lang): array
	{
		$db = nu_db();
		if ($db->tableExists(NuEmailTemplate::TABLE)) {
			$langs = array_values(array_unique([trim((string)($user['sus_language'] ?? '')), nu_lang_to_db($lang), '']));
			$tpl = null;
			foreach ($langs as $l) {
				$tpl ??= $db->one('SELECT * FROM ' . NuEmailTemplate::TABLE . " WHERE emt_code = ? AND COALESCE(emt_language,'') = ? AND COALESCE(emt_template,'') <> '1'", [self::TEMPLATE, $l]);
			}
			if ($tpl) {
				$esc = []; foreach ($hash as $k => $v) $esc[$k] = h($v);              // values are text in an HTML mail
				$body = NuHash::replace((string)$tpl['emt_body'], $esc, NuHash::RAW);
				if ($body === strip_tags($body)) $body = nl2br($body);                // plain text template (like nuBuilder)
				$subject = NuHash::replace((string)$tpl['emt_subject'], $hash, NuHash::RAW) ?: t('welcome_subject');
				return [$subject, NuMail::layout($subject, $body), 'template'];
			}
		}
		$name = trim((string)($user['sus_name'] ?? '')) ?: (string)$user['sus_login_name'];
		$p = static fn(string $s) => '<p style="margin:0 0 14px">' . $s . '</p>';
		$body = $p(h(t('welcome_hello', $name)))
			. $p(h(t('welcome_intro')))
			. '<table style="border-collapse:collapse;margin:0 0 18px;font-size:14px"><tr><td style="padding:4px 14px 4px 0;color:#7a8394">' . h(t('user_login_name'))
			. '</td><td style="padding:4px 0;font-weight:600">' . h((string)$user['sus_login_name']) . '</td></tr></table>'
			. $p(h(t('welcome_step')))
			. '<p style="margin:22px 0"><a href="' . h($hash['SET_PASSWORD_URL']) . '" style="background:#2f6fd6;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">'
			. h(t('welcome_button')) . '</a></p>'
			. $p('<span style="font-size:13px;color:#7a8394">' . h(t('welcome_valid', self::hours(), $hash['PASSWORD_MIN_LENGTH'])) . '</span>')
			. $p('<span style="font-size:13px;color:#7a8394">' . h(t('welcome_later')) . ' <a href="' . h($hash['LOGIN_URL']) . '">' . h($hash['LOGIN_URL']) . '</a></span>')
			. $p('<span style="font-size:13px;color:#7a8394">' . h(t('welcome_ignore')) . '</span>');
		return [t('welcome_subject'), NuMail::layout(t('welcome_title'), $body), 'builtin'];
	}

	private static function log(string $uid, string $email, string $source, ?string $err): void
	{
		NuLog::write($err === null ? 'welcome_sent' : 'welcome_failed', 'zzzzsys_user', $uid, ['to' => $email, 'source' => $source] + ($err !== null ? ['error' => $err] : []));
	}
}

// ====================================================================== nuBuilder-compatible functions

if (!function_exists('nuEmail')) {

	/**
	 * Sends an e-mail with PHPMailer (nuBuilder core/nuemailer.php).
	 * @return array [0 => bool sent, 1 => message, 2 => PHPMailer ErrorInfo, 3 => debug output, 4 => skipped invalid addresses]
	 */
	function nuEmail($to_list = [], $from_address = '', $from_name = '', $content = '', $subject = '', $file_list = [], $html = false,
		$cc_list = [], $bcc_list = [], $reply_to_list = [], $debug = 0, $method = 'SMTP', $priority = '', $smtp_options = []): array
	{
		$settings = nuMarshallEmailSettings((string)$from_address, (string)$from_name, (bool)$html, $reply_to_list ?? []);
		return NuMailer::send([
			'to' => $to_list, 'cc' => $cc_list, 'bcc' => $bcc_list, 'reply_to' => $settings->reply_to_list,
			'files' => $file_list, 'subject' => (string)$subject, 'body' => (string)$content, 'debug' => (int)$debug,
			'method' => $method ?: 'SMTP', 'priority' => $priority, 'smtp_options' => is_array($smtp_options) ? $smtp_options : [],
			'settings' => $settings,
		]);
	}

	/** Settings object as in nuBuilder (same property names), from zzzzsys_setup. */
	function nuMarshallEmailSettings($from_address = '', $from_name = '', $html = false, $reply_to_list = []): stdClass
	{
		$c = NuMailer::settings();
		$s = new stdClass();
		$s->error_text              = '';
		$s->username                = $c['username'];
		$s->password                = $c['password'];
		$s->host                    = $c['host'] !== '' ? $c['host'] : '127.0.0.1';
		$s->smtp_port               = (string)$c['port'];
		$s->smtp_use_secure         = $c['use_ssl'];
		$s->smtp_use_authentication = $c['auth'];
		$s->auth_type               = (string)($c['auth_type'] ?? '');
		$s->from_address            = trim((string)$from_address) !== '' ? trim((string)$from_address) : $c['from_address'];
		$s->from_name               = trim((string)$from_name) !== '' ? (string)$from_name : $c['from_name'];
		$s->word_wrap               = 120;
		$s->charset                 = 'UTF-8';
		$s->html                    = (bool)$html;
		$s->timeout                 = 20;
		$s->smtp_secure             = $c['secure'];
		$s->source                  = $c['source'];
		if ($s->smtp_secure === 'tls' || $s->smtp_secure === 'ssl') $s->verbose_host = $s->smtp_secure . '://' . $s->host . ':' . $s->smtp_port;

		// nuBuilder: without an explicit reply-to list, replies go to the given sender
		if (empty($reply_to_list)) $s->reply_to_list = trim((string)$from_address) !== '' ? [trim((string)$from_address) => (string)$from_name] : [];
		else $s->reply_to_list = $reply_to_list;
		return $s;
	}

	/** Sends with PHP's mail() (nuBuilder). Header injection is blocked. */
	function nuEmailPHP($sendTo, $fromAddress, $fromName, $content, $subject, $options = []): bool
	{
		$clean = static fn($v) => trim(str_replace(["\r", "\n"], '', (string)$v));
		if (!NuMailer::validAddress($clean($sendTo)) || !NuMailer::validAddress($clean($fromAddress))) return false;
		$headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
		$headers .= 'From: ' . mb_encode_mimeheader($clean($fromName), 'UTF-8') . ' <' . $clean($fromAddress) . ">\r\n";
		foreach (['cc' => 'Cc', 'bcc' => 'Bcc', 'replyTo' => 'Reply-To'] as $k => $hdr) {
			if (empty($options[$k])) continue;
			$list = array_filter(array_column(NuMailer::addressList($options[$k]), 0), [NuMailer::class, 'validAddress']);
			if ($list) $headers .= "$hdr: " . implode(', ', $list) . "\r\n";
		}
		return mail($clean($sendTo), mb_encode_mimeheader($clean($subject), 'UTF-8'), (string)$content, $headers);
	}

	/**
	 * nuBuilder: nuSendEmail($args) with an array, or positional parameters.
	 * nuSendEmail($args, $logOptions) (second parameter array or true) sends and logs (= nuSendEmailEx).
	 *
	 * Array keys: to, from_email, from_name, cc, bcc, body, subject, reply_to, attachments, html (default true),
	 * priority, smtp_options; new: debug (0–4), method (SMTP|sendmail|mail).
	 * to/cc/bcc: comma or semicolon separated string ("Name <a@b.ch>" allowed) or an array.
	 */
	function nuSendEmail($args1, $args2 = '', $from_name = '', $body = '', $subject = '', $attachments = [], $html = false, $cc = '', $bcc = '', $reply_to = [], $priority = '', $smtp_options = []): array
	{
		if (is_array($args1) && (is_array($args2) || is_bool($args2))) {
			return nuSendEmailEx($args1, $args2);
		}

		$debug = 0;
		$method = 'SMTP';
		if (is_array($args1)) {
			$a = nu_email_args($args1);
			[$to, $from_email, $from_name, $cc, $bcc, $body, $subject, $reply_to, $attachments, $html, $priority, $smtp_options] =
				[$a['to'], $a['from_email'], $a['from_name'], $a['cc'], $a['bcc'], $a['body'], $a['subject'], $a['reply_to'], $a['attachments'], $a['html'], $a['priority'], $a['smtp_options']];
			$debug = (int)$a['debug'];
			$method = (string)$a['method'];
		} else {
			$to = $args1;
			$from_email = $args2;
		}

		return nuEmail($to, $from_email, $from_name, $body, $subject, $attachments, $html, $cc, $bcc, $reply_to, $debug, $method, $priority, $smtp_options);
	}

	/** nuSendEmail() arguments with nuBuilder's defaults. */
	function nu_email_args(array $args): array
	{
		$defaults = [
			'to' => '', 'from_email' => '', 'from_name' => '', 'cc' => '', 'bcc' => '', 'body' => '', 'subject' => 'test',
			'reply_to' => [], 'attachments' => [], 'html' => true, 'priority' => '', 'smtp_options' => [], 'debug' => 0, 'method' => 'SMTP',
		];
		return array_merge($defaults, array_intersect_key($args, $defaults));
	}

	function nuUnescapeQuotes($str): string
	{
		return str_replace(['\"', "\\'"], ['"', "'"], (string)$str);
	}

	/**
	 * Sends like nuSendEmail($args) and writes a row to zzzzsys_email_log (nuBuilder).
	 * $emailLogOptions: true, or ['table_name', 'tag', 'form_id', 'user_id', 'record_id', 'json'] – missing keys
	 * are taken from the running form (FORM_ID, RECORD_ID, table) and the logged-in user.
	 */
	function nuSendEmailEx($args, $emailLogOptions = true): array
	{
		$ctx = NuMailer::context();
		if (is_array($emailLogOptions)) $ctx = array_merge($ctx, array_intersect_key($emailLogOptions, $ctx));

		$args = nu_email_args((array)$args);
		$args['subject'] = nuUnescapeQuotes($args['subject']);
		$args['body'] = nuUnescapeQuotes($args['body']);

		$result = nuSendEmail($args);

		// the log shows the sender that was actually used
		$used = nuMarshallEmailSettings((string)$args['from_email'], (string)$args['from_name']);
		$logArgs = ['from_email' => $used->from_address, 'from_name' => $used->from_name] + $args;
		NuMailer::log($logArgs, $result, $ctx);
		return $result;
	}
}

if (!function_exists('nuIsArrayAssociative')) {
	function nuIsArrayAssociative($arr): bool
	{
		return is_array($arr) && !array_key_exists('0', $arr) && !array_key_exists(0, $arr);
	}
}

if (!function_exists('db_setup')) {
	/** zzzzsys_setup row as an object (nuBuilder), including set_timezone. */
	function db_setup(): object
	{
		return (object)NuMailer::setupRow();
	}
}

/**
 * E-mail templates (zzzzsys_email_template, nuBuilder's form "nuemailtemplate").
 * Placeholders are hash cookies: #object_id# of the template's form, plus #RECORD_ID#, #USER_NAME# … (nuSendEmailFromTemplate()).
 */
final class NuEmailTemplate
{
	public const TABLE = 'zzzzsys_email_template';
	public const LEN = ['emt_code' => 80, 'emt_group' => 100, 'emt_category' => 100, 'emt_language' => 20, 'emt_description' => 200,
		'emt_subject' => 1000, 'emt_to' => 1000, 'emt_cc' => 1000, 'emt_bcc' => 1000, 'emt_form_id' => 25];
	/** Hash cookies every template can use. */
	public const SYSTEM_FIELDS = ['RECORD_ID', 'FORM_ID', 'FORM_CODE', 'USER_ID', 'LOGIN_NAME', 'USER_NAME', 'USER_EMAIL', 'USER_FIRST_NAME', 'USER_LAST_NAME'];

	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists(self::TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$db->run('CREATE TABLE ' . self::TABLE . ' (zzzzsys_email_template_id ' . $T('id') . ' NOT NULL PRIMARY KEY, emt_form_id ' . $T('id') . ' NULL, '
			. 'emt_group ' . $T('text', '100') . ' NULL, emt_category ' . $T('text', '100') . ' NULL, emt_template ' . $T('text', '1') . ' NULL, '
			. 'emt_language ' . $T('text', '20') . ' NULL, emt_description ' . $T('text', '200') . ' NULL, emt_code ' . $T('text', '80') . ' NULL, '
			. 'emt_body ' . $T('longtext') . ' NULL, emt_subject ' . $T('text', '1000') . ' NULL, emt_to ' . $T('text', '1000') . ' NULL, '
			. 'emt_cc ' . $T('text', '1000') . ' NULL, emt_bcc ' . $T('text', '1000') . ' NULL)' . ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
	}

	/** Languages for the select: nuBuilder's set_languages_included (+ values in use), default English/German. */
	public static function languages(): array
	{
		$list = json_decode((string)(NuMailer::setupRow()['set_languages_included'] ?? ''), true);
		$list = is_array($list) && $list ? array_map('strval', $list) : array_column(NU_LANGS, 1);
		try {
			foreach (nu_db()->all("SELECT DISTINCT emt_language FROM " . self::TABLE . " WHERE emt_language IS NOT NULL AND emt_language <> ''") as $r) $list[] = (string)$r['emt_language'];
		} catch (Throwable) {
		}
		$list = array_values(array_unique($list));
		sort($list);
		return $list;
	}

	/** Objects of a form that can be placeholders: [['id' => …, 'label' => …, 'type' => …, 'tab' => …], …]. */
	public static function fields(string $formId): array
	{
		$meta = $formId !== '' ? NuFormMeta::load($formId) : null;
		if (!$meta) return [];
		$tabs = [];
		foreach ($meta->tabs ?? [] as $tb) $tabs[(string)($tb['zzzzsys_tab_id'] ?? '')] = (string)($tb['syt_title'] ?? '');
		$out = [];
		foreach ($meta->objects as $o) {
			if (!in_array($o['sob_all_type'], ['input', 'select', 'textarea', 'editor', 'calc', 'lookup', 'display'], true)) continue;
			if ($o['sob_all_type'] === 'input' && in_array((string)$o['sob_input_type'], ['button', 'file'], true)) continue;
			$out[] = ['id' => (string)$o['sob_all_id'], 'label' => trim(strip_tags((string)$o['sob_all_label'])) ?: (string)$o['sob_all_id'],
				'type' => (string)$o['sob_all_type'], 'tab' => $tabs[(string)$o['sob_all_zzzzsys_tab_id']] ?? ''];
		}
		usort($out, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
		return $out;
	}

	/**
	 * Validates and saves (id '' = new). nuBuilder rule: code + group + language form a unique template.
	 * @return array{0: ?string, 1: array}
	 */
	public static function save(array $in, string $id = ''): array
	{
		$db = nu_db();
		$row = [];
		foreach (self::LEN as $k => $len) $row[$k] = trim((string)($in[$k] ?? ''));
		$row['emt_body'] = (string)($in['emt_body'] ?? '');
		$row['emt_template'] = !empty($in['emt_template']) ? '1' : '0';
		$errors = [];
		if ($row['emt_code'] === '') $errors['emt_code'] = t('err_required');
		foreach (self::LEN as $k => $len) nu_maxlen($errors, $k, $row[$k], $len);
		if ($row['emt_form_id'] !== '' && !str_starts_with($row['emt_form_id'], 'nu') && !NuFormMeta::load($row['emt_form_id'])) $errors['emt_form_id'] = t('form_err_not_found');   // nu…: nuBuilder's own forms (e.g. nuuser)
		if (!$errors) {
			$dup = $db->value('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE COALESCE(emt_code,'') = ? AND COALESCE(emt_group,'') = ? AND COALESCE(emt_language,'') = ?"
				. ($id !== '' ? ' AND zzzzsys_email_template_id <> ?' : ''),
				$id !== '' ? [$row['emt_code'], $row['emt_group'], $row['emt_language'], $id] : [$row['emt_code'], $row['emt_group'], $row['emt_language']]);
			if ((int)$dup) $errors['emt_code'] = t('emt_err_duplicate');
		}
		if ($errors) return [null, $errors];
		foreach ($row as $k => $v) if ($v === '' && $k !== 'emt_body') $row[$k] = null;
		if ($id !== '') {
			// the flag is nuBuilder's (its shipped templates) and never changed here: an unticked '' stays '' (0.8.364)
			if ($row['emt_template'] === '0' && (string)$db->value('SELECT emt_template FROM ' . self::TABLE . ' WHERE zzzzsys_email_template_id = ?', [$id]) !== '1') unset($row['emt_template']);
			$db->update(self::TABLE, $row, 'zzzzsys_email_template_id', $id);
		}
		else { $id = nu_id(); $db->insert(self::TABLE, ['zzzzsys_email_template_id' => $id] + $row); }
		return [$id, []];
	}

	/**
	 * Template with placeholders replaced for a record of its form (preview / test mail), like nuSendEmailFromTemplate():
	 * the body gets nl2br(). Unknown placeholders stay visible.
	 */
	public static function render(array $tpl, string $recordId = ''): array
	{
		$meta = (string)($tpl['emt_form_id'] ?? '') !== '' ? NuFormMeta::load((string)$tpl['emt_form_id']) : null;
		$row = [];
		if ($meta && $recordId !== '' && $recordId !== '-1') $row = NuRecord::load($meta, $recordId) ?? [];
		$hash = $meta ? NuHash::forForm($meta, $recordId !== '' ? $recordId : '-1', $row, []) : NuHash::base();
		if ($meta && $row) {
			// lookups and selects: the value as stored (like nuBuilder); passwords, keys and hashes never (0.8.207)
			foreach ($row as $k => $v) {
				$v = is_scalar($v) || $v === null ? (string)$v : json_encode($v);
				$hash[$k] = NuSecurity::secretName((string)$k) || NuSecurity::hashValue($v) ? '•••' : $v;
			}
		}
		$r = static fn(string $s) => NuHash::replace($s, $hash, NuHash::RAW);
		return [
			'to' => $r((string)($tpl['emt_to'] ?? '')), 'cc' => $r((string)($tpl['emt_cc'] ?? '')), 'bcc' => $r((string)($tpl['emt_bcc'] ?? '')),
			'subject' => $r((string)($tpl['emt_subject'] ?? '')), 'body' => nl2br($r((string)($tpl['emt_body'] ?? ''))), 'found' => (bool)$row,
		];
	}
}
