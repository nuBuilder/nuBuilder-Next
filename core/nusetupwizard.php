<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Setup wizard (0.8.312): nuBuilder Next's own settings after the database exists – for the globeadmin, right after the login.
 * Shown while nuconfig.php does not say $nuConfigShowInstaller = false (true = always, e.g. to run it again); when PHP cannot write
 * nuconfig.php, "done" is kept in zzzzsys_nunext_setting (row setup_wizard). The first-run installer (nuconfig.php missing) leads here
 * after the first login, so a new installation gets the same choices.
 *   Basics: default language (nuBuilder's Setup → set_language), time zone, address, session timeouts, shared login, nuBuilder folder
 *   Look & tools: title, environment label, primary database tool, REST API
 *   Security: new globeadmin password, 2FA for the globeadmin, HTTPS check, cron line
 *   Sample data: install / refresh
 * Settings of nuconfig.php go into the settings table instead when nuBuilder's settings come from it ($nuConfigSettingsFromDB) and the
 * table has the row; everything else is written to nuconfig.php in one save (backup, check). Not writable: the lines to paste are shown.
 */
final class NuSetupWizard
{
	public const STEPS = ['basics', 'look', 'security', 'samples', 'summary'];
	public const SETTING = 'setup_wizard';
	/** Pages that open without the wizard in between (a globeadmin may need them first). */
	public const FREE_ROUTES = ['setupwizard', 'logout', 'lang', 'reauth', 'update', 'configfile', 'help', 'securitycheck', 'twofa'];

	/** Should the globeadmin see the wizard now? */
	public static function pending(): bool
	{
		try {
			$v = nu_config()['nuConfigShowInstaller'] ?? null;
		} catch (Throwable) {
			return false;
		}
		if ($v !== null) {
			$on = is_bool($v) ? $v : !in_array(strtolower(trim((string)$v)), ['false', '0', 'no', 'off', ''], true);
			return $on;                                           // set in nuconfig.php: it decides
		}
		return self::doneInDb() === null;
	}

	/** When / by whom the wizard was finished, from the database (fallback when nuconfig.php could not be written). */
	public static function doneInDb(): ?array
	{
		try {
			NuSchema::ensure();
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$d = is_string($v) ? json_decode($v, true) : null;
			return is_array($d) ? $d : null;
		} catch (Throwable) {
			return null;
		}
	}

	/** Marks the wizard as done: $nuConfigShowInstaller = false in nuconfig.php, and always the database row. @return string nuconfig error */
	public static function markDone(bool $skipped): string
	{
		$err = NuConfigFile::writable() ? NuConfigFile::setVariables(['nuConfigShowInstaller' => 'false']) : t('cfg_err_not_writable', NuConfigFile::path());
		self::storeDone($skipped);
		NuLog::write($skipped ? 'setup_wizard_skipped' : 'setup_wizard_done', 'nuconfig.php', '', ['nuconfig' => $err === '' ? 'written' : $err]);
		return $err;
	}

	private static function storeDone(bool $skipped): void
	{
		self::setSetting(self::SETTING, (string)json_encode(['done' => date('Y-m-d H:i:s'), 'skipped' => $skipped, 'version' => NU_VERSION]));
	}

	private static function setSetting(string $id, string $value): void
	{
		$db = nu_db();
		NuSchema::ensure();
		$row = ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', $id);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $id] + $row);
	}

	// ------------------------------------------------------------------ default language (nuBuilder's Setup → Language)

	/** nuBuilder's default language (zzzzsys_setup.set_language) as a nuBuilder Next code, or null. */
	public static function defaultLang(): ?string
	{
		static $v = false;
		if ($v !== false) return $v;
		try {
			$db = nu_db();
			$name = $db->tableExists('zzzzsys_setup') && isset($db->columns('zzzzsys_setup')['set_language']) ? (string)($db->value('SELECT set_language FROM zzzzsys_setup') ?? '') : '';
			return $v = trim($name) !== '' ? nu_lang_from_db($name) : null;
		} catch (Throwable) {
			return $v = null;
		}
	}

	/** The installation's default variant of a language (English: en-GB / en-AU / en-US), from zzzzsys_nunext_setting 'lang_variant'. */
	public static function defaultVariant(string $lang): ?string
	{
		static $v = false;
		if ($v === false) {
			try {
				NuSchema::ensure();
				$x = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', ['lang_variant']);
				$v = is_string($x) && isset(NU_LANG_VARIANTS[$x]) ? $x : null;
			} catch (Throwable) {
				$v = null;
			}
		}
		return $v !== null && NU_LANG_VARIANTS[$v][0] === $lang ? $v : null;
	}

	/** Default language as a menu choice (de, en-GB, en-US …), or null. */
	public static function defaultChoice(): ?string
	{
		$l = self::defaultLang();
		if ($l === null) return null;
		return isset(NU_LANG_VARIANT_DEFAULT[$l]) ? (self::defaultVariant($l) ?? NU_LANG_VARIANT_DEFAULT[$l]) : $l;
	}

	// ------------------------------------------------------------------ values

	/** The current settings as the wizard shows them. */
	public static function current(): array
	{
		$c = static fn(string $k, mixed $d = null) => nu_cfg($k) ?? $d;
		$b = NuBranding::get();
		$tools = NuVendor::dbTools();
		return [
			'lang' => self::defaultChoice() ?? nu_lang_choice(),
			'timezone' => (string)($c('nuConfigTimezone') ?? ''),
			'base_url' => (string)($c('nuConfigBaseURL') ?? ''),
			'timeout' => (int)$c('nuConfigSessionTimeout', 120),
			'timeout_ga' => (int)$c('nuConfigSessionTimeoutGlobeadmin', 720),
			'shared' => (bool)$c('nuConfigSharedLogin', false),
			'nub_path' => (string)($c('nuConfigNuBuilderPath') ?? ''),
			'title' => nu_app_title(true),
			'env_label' => $b['env_label'],
			'env_color' => $b['env_color'],
			'dbtool' => $tools[0] ?? '',
			'api' => (bool)$c('nuConfigApi', false),
			'twofa' => (bool)$c('nuConfig2FAAdmin', false),
			'admin_email' => (string)($c('nuConfigGlobeadminEmail') ?? ''),
			'pw1' => '', 'pw2' => '',
			'sample' => 'none',
		];
	}

	/** Validates the fields of one step. @return array errors (field => text) */
	public static function validate(string $step, array $v): array
	{
		$e = [];
		if ($step === 'basics') {
			if (!isset(nu_lang_choices()[$v['lang']])) $e['lang'] = t('err_required');
			if ($v['timezone'] !== '' && !in_array($v['timezone'], timezone_identifiers_list(), true)) $e['timezone'] = t('ins_err_timezone');
			if ($v['base_url'] !== '' && !preg_match('~^https?://[^\s/?#]+[^\s]*$~i', $v['base_url'])) $e['base_url'] = t('ins_err_url');
			if ($v['timeout'] < 5 || $v['timeout'] > 1440) $e['timeout'] = t('ins_err_timeout');
			if ($v['timeout_ga'] < 5 || $v['timeout_ga'] > 1440) $e['timeout_ga'] = t('ins_err_timeout');
			if ($v['nub_path'] !== '' && !NuInstall::nuBuilderPath(NuBuilderLib::absPath($v['nub_path']))) $e['nub_path'] = t('ins_err_nub_path');
			if ($v['shared'] && $v['nub_path'] === '') $e['shared'] = t('ins_err_shared');
		}
		if ($step === 'look') {
			if (mb_strlen($v['title']) > 100) $e['title'] = t('wiz_err_long');
			if (mb_strlen($v['env_label']) > NuBranding::ENV_LEN) $e['env_label'] = t('wiz_err_long');
		}
		if ($step === 'security') {
			if ($v['pw1'] !== '' || $v['pw2'] !== '') {
				$min = max(8, (int)(nu_cfg('nuConfigPasswordMinLength') ?? 8));
				if ($v['pw1'] !== $v['pw2']) $e['pw2'] = t('wiz_err_pw_match');
				elseif (mb_strlen($v['pw1']) < $min) $e['pw1'] = t('wiz_err_pw_short', $min);
				elseif (in_array(strtolower($v['pw1']), ['nu', 'globeadmin', 'admin', 'password', '12345678', 'passwort'], true)) $e['pw1'] = t('wiz_err_pw_weak');
			}
			if ($v['twofa'] && $v['admin_email'] !== '' && !filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) $e['admin_email'] = t('wiz_err_email');
		}
		return $e;
	}

	/** Reads the posted fields of a step into the state. */
	public static function take(string $step, array $state, array $post): array
	{
		$s = static fn(string $k) => trim((string)($post[$k] ?? ''));
		if ($step === 'basics') {
			$state['lang'] = $s('lang'); $state['timezone'] = $s('timezone'); $state['base_url'] = rtrim($s('base_url'), '/');
			$state['timeout'] = (int)$s('timeout'); $state['timeout_ga'] = (int)$s('timeout_ga');
			$state['shared'] = ($post['shared'] ?? '') === '1'; $state['nub_path'] = rtrim($s('nub_path'), '/\\');
		} elseif ($step === 'look') {
			$state['title'] = $s('title'); $state['env_label'] = $s('env_label'); $state['env_color'] = NuBranding::hex($s('env_color')) ?: '#dc3545';
			if (isset($post['dbtool']) && in_array($s('dbtool'), NuVendor::dbTools(), true)) $state['dbtool'] = $s('dbtool');
			$state['api'] = ($post['api'] ?? '') === '1';
		} elseif ($step === 'security') {
			$p1 = (string)($post['pw1'] ?? ''); $p2 = (string)($post['pw2'] ?? '');
			if ($p1 !== '' || $p2 !== '' || ($post['pw_clear'] ?? '') === '1') { $state['pw1'] = $p1; $state['pw2'] = $p2; }   // both empty: keep the one entered before
			$state['twofa'] = ($post['twofa'] ?? '') === '1'; $state['admin_email'] = $s('admin_email');
		} elseif ($step === 'samples') {
			$state['sample'] = $s('sample') === 'install' ? 'install' : 'none';
		}
		return $state;
	}

	/** nuconfig.php variables of the state: name => [PHP literal, text for the settings table]. */
	private static function configVars(array $v, array $cur): array
	{
		$x = static fn($val) => var_export($val, true);
		$b = static fn(bool $on) => $on ? 'true' : 'false';
		$out = [
			'nuConfigTimezone' => [$x($v['timezone']), $v['timezone']],
			'nuConfigBaseURL' => [$x($v['base_url']), $v['base_url']],
			'nuConfigSessionTimeout' => [(string)$v['timeout'], (string)$v['timeout']],
			'nuConfigSessionTimeoutGlobeadmin' => [(string)$v['timeout_ga'], (string)$v['timeout_ga']],
			'nuConfigSharedLogin' => [$b($v['shared']), $b($v['shared'])],
			'nuConfigNuBuilderPath' => [$x($v['nub_path']), $v['nub_path']],
			'nuConfigTitle' => [$x($v['title']), $v['title']],
			'nuConfigApi' => [$b($v['api']), $b($v['api'])],
			'nuConfig2FAAdmin' => [$b($v['twofa']), $b($v['twofa'])],
			'nuConfigGlobeadminEmail' => [$x($v['admin_email']), $v['admin_email']],
		];
		// only what changed – an unchanged empty label does not add a line to nuconfig.php
		foreach ($out as $k => $_) {
			$key = ['nuConfigTimezone' => 'timezone', 'nuConfigBaseURL' => 'base_url', 'nuConfigSessionTimeout' => 'timeout', 'nuConfigSessionTimeoutGlobeadmin' => 'timeout_ga',
				'nuConfigSharedLogin' => 'shared', 'nuConfigNuBuilderPath' => 'nub_path', 'nuConfigTitle' => 'title', 'nuConfigApi' => 'api', 'nuConfig2FAAdmin' => 'twofa', 'nuConfigGlobeadminEmail' => 'admin_email'][$k];
			if ($v[$key] === $cur[$key]) unset($out[$k]);
		}
		// environment label: nuconfig.php only when it is set there already, otherwise Branding (database) – see apply()
		if (NuBranding::envFromConfig() && ($v['env_label'] !== $cur['env_label'] || $v['env_color'] !== $cur['env_color'])) {
			$out['nuConfigEnvironmentLabel'] = [$x($v['env_label']), $v['env_label']];
			$out['nuConfigEnvironmentColor'] = [$x($v['env_color']), $v['env_color']];
		}
		if ($v['pw1'] !== '') $out['nuConfigDBGlobeadminPassword'] = [$x($v['pw1']), ''];
		return $out;
	}

	/** What would change: list of [label, old, new] for the summary. */
	public static function changes(array $v, array $cur): array
	{
		$labels = ['lang' => 'wiz_lang', 'timezone' => 'ins_opt_timezone', 'base_url' => 'ins_opt_url', 'timeout' => 'ins_opt_timeout', 'timeout_ga' => 'wiz_timeout_ga',
			'shared' => 'ins_opt_shared_short', 'nub_path' => 'ins_opt_nub', 'title' => 'wiz_app_title', 'env_label' => 'brand_env', 'env_color' => 'brand_env_color', 'dbtool' => 'wiz_dbtool',
			'api' => 'wiz_api', 'twofa' => 'wiz_twofa', 'admin_email' => 'wiz_admin_email'];
		$show = static function ($k, $val): string {
			if (is_bool($val)) return t($val ? 'yes' : 'no');
			if ($k === 'lang') return (string)(nu_lang_choices()[$val] ?? $val);
			if ($k === 'dbtool') return (string)(NuVendor::DB_TOOLS[$val] ?? $val);
			return (string)$val === '' ? '–' : (string)$val;
		};
		$out = [];
		foreach ($labels as $k => $l) if ($v[$k] !== $cur[$k]) $out[] = [t($l), $show($k, $cur[$k]), $show($k, $v[$k])];
		if ($v['pw1'] !== '') $out[] = [t('wiz_pw'), '••••', t('wiz_pw_new')];
		if ($v['sample'] === 'install') $out[] = [t('smp_title'), '', t(self::sampleInstalled() ? 'wiz_sample_refresh' : 'smp_install')];
		return $out;
	}

	/**
	 * Applies everything and marks the wizard done.
	 * @return array{errors: list<string>, notes: list<string>, manual: string, sample: ?array, relogin: bool}
	 */
	public static function apply(array $v): array
	{
		$cur = self::current();
		$res = ['errors' => [], 'notes' => [], 'manual' => '', 'sample' => null, 'relogin' => false];
		// default language → nuBuilder's Setup (also used by nuBuilder for the globeadmin)
		if ($v['lang'] !== $cur['lang'] || self::defaultLang() === null) {
			try {
				$db = nu_db();
				if ($db->tableExists('zzzzsys_setup') && isset($db->columns('zzzzsys_setup')['set_language'])) {
					$base = NU_LANG_VARIANTS[$v['lang']][0] ?? $v['lang'];
					$db->run('UPDATE zzzzsys_setup SET set_language = ?', [nu_lang_to_db($base)]);
					if (isset(NU_LANG_VARIANTS[$v['lang']])) self::setSetting('lang_variant', $v['lang']);   // English: UK / Australia / US
					nu_set_lang($v['lang']);
				}
			} catch (Throwable $e) {
				$res['errors'][] = t('wiz_lang') . ': ' . $e->getMessage();
			}
		}
		// the environment label, and the title when Branding has one of its own (0.8.736 – it wins over $nuConfigTitle)
		$bt = NuBranding::get()['title'];
		$envChg = !NuBranding::envFromConfig() && ($v['env_label'] !== $cur['env_label'] || $v['env_color'] !== $cur['env_color']);
		if ($envChg || ($bt !== '' && $v['title'] !== $cur['title'])) {
			$b = NuBranding::get();
			[$err] = NuBranding::save(['accent_on' => $b['accent'] !== '', 'accent' => $b['accent'], 'show_title' => $b['show_title'], 'favicon' => $b['favicon'],
				'logo_show' => $b['logo_show'], 'env_label' => $envChg ? $v['env_label'] : $b['env_label'], 'env_color' => $envChg ? $v['env_color'] : $b['env_color']]
				+ ($bt !== '' ? ['title' => $v['title']] : []), [], []);
			if ($err) $res['errors'][] = t('brand_env') . ': ' . implode(' ', $err);
		}
		if ($v['dbtool'] !== '' && $v['dbtool'] !== $cur['dbtool']) {
			$err = NuVendor::setPrimaryDb($v['dbtool']);
			if ($err !== '') $res['errors'][] = t('wiz_dbtool') . ': ' . $err;
		}

		// nuconfig.php (or the settings table) – one save, with "done"
		$vars = self::configVars($v, $cur);
		$file = [];
		$fromDb = NuSettings::fromDb() && NuSettings::available();
		foreach ($vars as $name => [$literal, $text]) {
			if ($fromDb && $name !== 'nuConfigDBGlobeadminPassword') {
				$id = nu_db()->value('SELECT zzzzsys_config_id FROM ' . NuSettings::TABLE . ' WHERE cfg_setting = ?', ['$' . $name]);
				if ($id !== null) { nu_db()->update(NuSettings::TABLE, ['cfg_value' => $text], 'zzzzsys_config_id', (string)$id); continue; }
			}
			$file[$name] = $literal;
		}
		$file['nuConfigShowInstaller'] = 'false';
		$err = NuConfigFile::writable() ? NuConfigFile::setVariables($file) : t('cfg_err_not_writable', NuConfigFile::path());
		if ($err !== '') {
			$res['manual'] = implode("\n", array_map(static fn($n, $l) => '$' . $n . ' = ' . $l . ';', array_keys($file), $file));
			$res['errors'][] = $err;
		} elseif (isset($file['nuConfigDBGlobeadminPassword'])) {
			$res['relogin'] = true;                               // the globeadmin's session ends with the old password
		}
		self::storeDone(false);
		NuLog::write('setup_wizard_done', 'nuconfig.php', '', ['changed' => array_values(array_diff(array_keys($file), ['nuConfigDBGlobeadminPassword'])),
			'password' => isset($file['nuConfigDBGlobeadminPassword']), 'nuconfig' => $err === '' ? 'written' : 'manual']);

		// sample data last (it takes longest)
		if ($v['sample'] === 'install') {
			try {
				$res['sample'] = NuSample::install();
			} catch (Throwable $e) {
				$res['errors'][] = t('smp_failed') . ' ' . $e->getMessage();
			}
		}
		return $res;
	}

	public static function sampleInstalled(): bool
	{
		try { return NuSample::status()['installed']; } catch (Throwable) { return false; }
	}

	/** Cron line for the housekeeping (and pending unattended updates). */
	public static function cronLine(): string
	{
		return '*/15 * * * * php ' . NU_ROOT . '/cron.php > /dev/null';
	}
}
