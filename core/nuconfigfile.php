<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Editing nuconfig.php in the browser (Admin tools → nuconfig.php, pages/nuconfigfile.php) – globeadmin only, never in demo mode.
 *
 * Saving is refused unless the new text
 *   1. is valid PHP (token_get_all with TOKEN_PARSE – nothing runs),
 *   2. runs without an error or output and still sets $nuConfigDBName (run once, isolated, from a temporary file in the backup folder),
 *   3. connects to the database with its settings,
 *   4. and the file was not changed by someone else since the page was opened (sha256 of the text that was loaded).
 * Before writing, the current file is copied to <backup folder>/nuconfig-<YYYYmmdd-His>.php (the folder is blocked for the web;
 * the .php ending means a wrong server setup runs it instead of showing the passwords). The newest KEEP backups are kept.
 * Writing: temporary file → rename (NuInstall::writeConfig), then OPcache is told about the new file.
 */
final class NuConfigFile
{
	public const KEEP = 10;
	private const PREFIX = 'nuconfig-';

	public static function path(): string
	{
		return nu_config_path();
	}

	public static function read(): string
	{
		$p = self::path();
		return is_file($p) ? (string)file_get_contents($p) : '';
	}

	public static function hash(string $text): string
	{
		return hash('sha256', $text);
	}

	/** The file can be replaced: the file and its folder are writable (rename needs the folder). */
	public static function writable(): bool
	{
		$p = self::path();
		return is_writable(dirname($p)) && (!is_file($p) || is_writable($p));
	}

	/**
	 * Checks a new text. @return array{0: string, 1: array, 2?: int} [error ('' = fine), variables it sets, line of the error (0 = none)]
	 */
	public static function check(string $text): array
	{
		if (trim($text) === '') return [t('cfg_err_empty'), []];
		if (!str_starts_with(ltrim($text), '<?php')) return [t('cfg_err_open_tag'), []];
		try {
			token_get_all($text, TOKEN_PARSE);
		} catch (ParseError $e) {
			return [t('cfg_err_syntax', $e->getLine(), $e->getMessage()), [], $e->getLine()];
		}

		// run it once, isolated, to get the variables (a temporary file in the backup folder, which is blocked for the web)
		$tmp = NuUpdate::backupDir() . '/nuconfig-check-' . bin2hex(random_bytes(6)) . '.php';
		if (@file_put_contents($tmp, $text) === false) return [t('cfg_err_tmp'), []];
		ob_start();
		try {
			$vars = (static function (string $__file): array {
				require $__file;
				unset($__file);
				return get_defined_vars();
			})($tmp);
		} catch (Throwable $e) {
			return [t('cfg_err_run', $e->getMessage() . ' (' . t('cfg_line') . ' ' . $e->getLine() . ')'), [], $e->getFile() === $tmp ? $e->getLine() : 0];
		} finally {
			$out = (string)ob_get_clean();
			@unlink($tmp);
			if (function_exists('opcache_invalidate')) @opcache_invalidate($tmp, true);
		}
		if (trim($out) !== '') return [t('cfg_err_output'), []];
		if (trim((string)($vars['nuConfigDBName'] ?? '')) === '') return [t('cfg_err_dbname'), []];

		// the database connection with the new settings (same defaults as nu_config())
		try {
			new NuDB([
				'type'       => $vars['nuConfigDBType'] ?? 'mysql',
				'host'       => $vars['nuConfigDBHost'] ?? '127.0.0.1',
				'port'       => $vars['nuConfigDBPort'] ?? '',
				'name'       => $vars['nuConfigDBName'],
				'user'       => $vars['nuConfigDBUser'] ?? '',
				'password'   => $vars['nuConfigDBPassword'] ?? '',
				'charset'    => $vars['nuConfigDBCharacterSet'] ?? 'utf8mb4',
				'collate'    => $vars['nuConfigDBCollate'] ?? '',
				'options'    => (array)($vars['nuConfigDBOptions'] ?? []),
				'trust_cert' => $vars['nuConfigDBTrustServerCertificate'] ?? false,
			]);
		} catch (Throwable $e) {
			return [t('cfg_err_db', $e->getMessage()), $vars];
		}
		return ['', $vars];
	}

	/**
	 * Saves a checked text. $loadedHash: hash of the file when the page was opened.
	 * @return string error ('' = saved)
	 */
	public static function save(string $text, string $loadedHash): string
	{
		$text = str_replace("\r\n", "\n", $text);
		$current = self::read();
		if (!hash_equals(self::hash($current), $loadedHash)) return t('cfg_err_changed');
		if ($current === $text) return t('cfg_err_unchanged');
		if (!self::writable()) return t('cfg_err_not_writable', self::path());
		[$err] = self::check($text);
		if ($err !== '') return $err;

		$backup = '';
		if ($current !== '') {
			$backup = self::PREFIX . date('Ymd-His') . '.php';
			if (@file_put_contents(NuUpdate::backupDir() . '/' . $backup, $current) === false) return t('cfg_err_backup');
			@chmod(NuUpdate::backupDir() . '/' . $backup, 0640);
		}
		if (!NuInstall::writeConfig($text)) return t('cfg_err_write');
		if (function_exists('opcache_invalidate')) @opcache_invalidate(self::path(), true);
		self::prune();
		NuLog::write('config_saved', 'nuconfig.php', '', ['backup' => $backup, 'bytes' => strlen($text)]);
		return '';
	}

	/**
	 * Sets one variable in nuconfig.php ($value: PHP code, e.g. 'true') and saves it like the editor (checks, backup, log):
	 * every assignment at the start of a line is changed; without one the line is added at the end (before a closing ?>).
	 * @return string error ('' = saved)
	 */
	public static function setVariable(string $name, string $value): string
	{
		return self::setVariables([$name => $value]);
	}

	/** Several variables in one save (one backup, one log entry) – name => PHP code. @return string error ('' = saved) */
	public static function setVariables(array $vars): string
	{
		$current = self::read();
		$text = str_replace("\r\n", "\n", $current);
		$val = '(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^;\'"\n])*';   // up to the ; – strings may contain ;
		$add = [];
		foreach ($vars as $name => $value) {
			if (!preg_match('/^nuConfig\w+$/', (string)$name)) return t('cfg_err_write');
			$n = 0;   // also a second assignment on one line: $a = 1; $nuConfigX = 2;
			$text = (string)preg_replace_callback('/(^[ \t]*|;[ \t]*)(\$' . $name . '\s*=\s*)' . $val . ';/m', static fn($m) => $m[1] . $m[2] . $value . ';', $text, -1, $n);
			if ($n === 0) $add[] = '$' . $name . ' = ' . $value . ';';
		}
		if ($add) {
			$lines = implode("\n", $add);
			$text = preg_match('/\?>\s*$/', $text) ? (string)preg_replace('/\?>\s*$/', $lines . "\n?>\n", $text) : rtrim($text) . "\n\n" . $lines . "\n";
		}
		if ($text === str_replace("\r\n", "\n", $current)) return '';
		return self::save($text, self::hash($current));
	}

	// ================================================================= Insert variable (0.8.442)

	/** Never offered by Insert variable: developer switches and nuBuilder settings nuBuilder Next does not support. */
	public const HIDDEN = ['nuConfigAllowExistingNuDB', 'nuConfigLogonMode'];

	/** Groups of Insert variable: first matching pattern of the name (without $nuConfig) wins; the rest is "General". */
	public const GROUPS = [
		'Database'           => '/^DB(?!Globeadmin)/',
		'Login and security' => '/^(2FA|DBGlobeadmin|Globeadmin(Email)?$|Password|Session|SecretKey|AutoLogin|TrustProxy)/',
		'E-mail'             => '/^(SMTP|MailLog|PHPMailer)/',
		'REST API'           => '/^Api/',
		'Demo mode'          => '/^(Demo|IsDemo)/',
		'nuBuilder Forte'    => '/^(SharedLogin|NuBuilder|FontAwesome)/',
		'Includes'           => '/^Include(JS|CSS|PHP)$/',
		'Admin tools'        => '/^(PMAURL|TFMURL|PrimaryDatabase|BackupPath|EnableDatabaseUpdate|TCPDFPath|ShowInstaller|DevMode|Debug$|SettingsFromDB)/',
	];

	/** Variables nuBuilder Next reads that nuconfig-sample.php does not show: name => [default (PHP code), comment]. */
	public const EXTRA = [
		'nuConfigTitle'                    => ['""', 'Application title in the browser tab and the header (empty: nuBuilder Next).'],
		'nuConfigGlobeadminHome'           => ['""', 'nuBuilder Forte: id or code of a launch form opened as the globeadmin\'s start page instead of the tile page.'],
		'nuConfigIncludeJS'                => ['\'\'', 'nuBuilder Forte: own JavaScript file(s) for form pages, e.g. \'myjslib.js\' or [\'a.js\', \'b.js\'] – relative to nuBuilder Next, else nuBuilder Forte\'s folder; https:// addresses too.'],
		'nuConfigIncludeCSS'               => ['\'\'', 'nuBuilder Forte: own CSS file(s) for form pages, e.g. \'mystyles.css\' or [\'a.css\', \'b.css\'] – relative to nuBuilder Next, else nuBuilder Forte\'s folder.'],
		'nuConfigCSVSeparator'             => ['";"', 'Default delimiter of the Export CSV dialog: ";" "," "|" or "\t".'],
		'nuConfigIncludeApexCharts'        => ['false', 'nuBuilder Forte: true loads ApexCharts on form pages (new ApexCharts(element, options).render() in form code).'],
		'nuConfigIntegerPKsAuto'           => ['false', 'nuBuilder Forte: integer primary keys count as auto-increment when the column does not say so.'],
		'nuConfigCustomPagesDir'           => ['""', 'Absolute path of the folder with the page forms (default: custom/pages/ of nuBuilder Next).'],
		'nuConfigHomeHooks'                => ['true', 'false: the start page hooks nu_home / nu_home_user (PHP and JavaScript) do not run.'],
		'nuConfigEnableDatabaseUpdate'     => ['true', 'nuBuilder Forte: false switches updates off (Admin tools → Update refuses to install).'],
		'nuConfigTCPDFPath'                => ['""', 'Folder of TCPDF (tcpdf.php) for reports – overrides the library pack and the search.'],
		'nuConfigIsDemo'                   => ['false', 'nuBuilder Forte demo: true lets the demo globeadmin below sign in read only.'],
		'nuConfigDemoDBGlobeadminUsername' => ['""', 'nuBuilder Forte demo: login name of the demo globeadmin (with $nuConfigIsDemo = true).'],
		'nuConfigDemoDBGlobeadminPassword' => ['""', 'nuBuilder Forte demo: password of the demo globeadmin.'],
	];

	/**
	 * Variables for Insert variable: those of nuconfig-sample.php (default and comment from there – commented-out lines too, the first
	 * occurrence wins: the MySQL block before SQL Server) and EXTRA, without HIDDEN. @return list<array{name: string, value: string, comment: string, group: string}>
	 */
	public static function catalog(): array
	{
		$out = [];
		$sample = NU_ROOT . '/nuconfig-sample.php';
		$lines = is_file($sample) ? (array)file($sample, FILE_IGNORE_NEW_LINES) : [];
		$last = null;                                         // index in $out of the previous variable (for "//--" continuation lines)
		$inBlock = false;
		foreach ($lines as $line) {
			$line = (string)$line;
			$t = trim($line);
			if (str_starts_with($t, '/*')) $inBlock = true;
			if ($inBlock) { if (str_contains($t, '*/')) $inBlock = false; $last = null; continue; }
			if (preg_match('/^(?:\/\/)?\s*\$(nuConfig\w+)\s*=\s*(.+?);\s*(?:\/\/--\s*(.*))?$/', $t, $m)) {
				$name = $m[1];
				if (isset($out[$name]) || in_array($name, self::HIDDEN, true)) { $last = null; continue; }
				$out[$name] = ['name' => $name, 'value' => trim($m[2]), 'comment' => trim($m[3] ?? ''), 'group' => self::groupOf($name)];
				$last = $name;
				continue;
			}
			if ($last !== null && preg_match('/^\/\/--\s*(.*)$/', $t, $m)) { $out[$last]['comment'] = trim($out[$last]['comment'] . ' ' . $m[1]); continue; }
			$last = null;
		}
		foreach (self::EXTRA as $name => [$value, $comment]) {
			if (!isset($out[$name]) && !in_array($name, self::HIDDEN, true)) $out[$name] = ['name' => $name, 'value' => $value, 'comment' => $comment, 'group' => self::groupOf($name)];
		}
		$order = array_flip(array_merge(array_keys(self::GROUPS), ['General']));
		$list = array_values($out);
		usort($list, static fn($a, $b) => [$order[$a['group']], $a['name']] <=> [$order[$b['group']], $b['name']]);
		return $list;
	}

	public static function groupOf(string $name): string
	{
		$short = (string)preg_replace('/^nuConfig/', '', $name);
		foreach (self::GROUPS as $g => $re) if (preg_match($re, $short)) return $g;
		return 'General';
	}

	/** Settings a button elsewhere may switch on (pages/nuconfigfile.php, action enable): variable => [log event, success text, page to return to]. */
	public const SWITCHES = [
		'nuConfigApi'     => ['api_switched_on', 'api_on_done', ['apiclients', 'apiplayground']],
		'nuConfig2FAUser' => ['2fa_user_switched_on', 'twofa_on_done', ['accesslevel', 'access']],
		'nuConfigIncludeApexCharts' => ['apex_switched_on', 'apex_on_done', ['form']],
	];

	/**
	 * The hidden form of a "Switch on" button whose button sits elsewhere (e.g. inside another form): <button form="$formId">.
	 * $returnId: record id for the page to return to.
	 */
	public static function enableForm(string $var, string $formId, string $label, string $confirm, string $returnRoute, string $returnId = ''): string
	{
		if (!isset(self::SWITCHES[$var]) || NuDemo::active() || !self::writable()) return '';
		return '<form method="post" action="' . h(nu_url('configfile')) . '" id="' . h($formId) . '" class="d-none" data-confirm="' . h($confirm) . '" data-confirm-ok="' . h($label) . '">'
			. nu_csrf_field() . '<input type="hidden" name="action" value="enable"><input type="hidden" name="var" value="' . h($var) . '">'
			. '<input type="hidden" name="return" value="' . h($returnRoute) . '"><input type="hidden" name="return_id" value="' . h($returnId) . '"></form>';
	}

	/**
	 * "Switch on" button for a notice: sets $<var> = true; in nuconfig.php after a confirmation, then back to $returnRoute
	 * (with $returnQuery, e.g. a form page: ['f' => form id, 'r' => record id]).
	 * Not writable: a short note instead. Demo mode: nothing (the page is refused anyway).
	 */
	public static function enableButton(string $var, string $label, string $confirm, string $returnRoute, array $returnQuery = []): string
	{
		if (!isset(self::SWITCHES[$var]) || NuDemo::active()) return '';
		if (!self::writable()) return '<span class="text-body-secondary">' . h(t('cfg_err_not_writable', self::path())) . '</span>';
		return '<form method="post" action="' . h(nu_url('configfile')) . '" class="m-0" data-confirm="' . h($confirm) . '" data-confirm-ok="' . h($label) . '">'
			. nu_csrf_field() . '<input type="hidden" name="action" value="enable"><input type="hidden" name="var" value="' . h($var) . '">'
			. '<input type="hidden" name="return" value="' . h($returnRoute) . '">'
			. implode('', array_map(static fn($k, $v) => '<input type="hidden" name="return_q[' . h((string)$k) . ']" value="' . h((string)$v) . '">', array_keys($returnQuery), $returnQuery))
			. '<button class="btn btn-sm btn-warning text-nowrap" type="submit"><i class="bi bi-toggle-on me-1"></i>' . h($label) . '</button></form>';
	}

	/**
	 * Sets $nuConfigSecretKey (only while it is empty: a different key would make encrypted 2FA secrets unreadable).
	 * @return string error ('' = saved)
	 */
	public static function setSecretKey(string $key): string
	{
		if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $key)) return t('key_err_format');
		if ((string)(nu_config_file()['nuConfigSecretKey'] ?? '') !== '' || (string)nu_cfg('nuConfigSecretKey') !== '') return t('key_err_set');
		return self::setVariable('nuConfigSecretKey', var_export($key, true));
	}

	/** Backups, newest first: [file name => time]. */
	public static function backups(): array
	{
		$out = [];
		foreach (glob(NuUpdate::backupDir() . '/' . self::PREFIX . '*.php') ?: [] as $f) {
			$n = basename($f);
			if (preg_match('/^nuconfig-(\d{8}-\d{6})\.php$/', $n, $m)) $out[$n] = (int)filemtime($f);
		}
		krsort($out);
		return $out;
	}

	/** Text of a backup ('' = not found). Only names from backups() – no paths. */
	public static function backup(string $name): string
	{
		if (!array_key_exists($name, self::backups())) return '';
		return (string)file_get_contents(NuUpdate::backupDir() . '/' . $name);
	}

	private static function prune(): void
	{
		foreach (array_slice(array_keys(self::backups()), self::KEEP) as $n) @unlink(NuUpdate::backupDir() . '/' . $n);
	}
}
