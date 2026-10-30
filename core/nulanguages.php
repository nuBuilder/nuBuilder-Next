<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Languages of the installation (0.8.701): the ones chosen when installing (installer step Options) or later in Admin tools →
 * Settings → card Languages. Only these are offered – language menus, the sign-in page, the user form, the setup wizard – and only
 * they get a built-in welcome e-mail template (NuWelcome::ensureTemplates). Setting row 'languages' (JSON list of codes, as
 * NU_LANGS); 0.8.693–0.8.700 stored it as 'welcome_langs' (read as the fallback). No row = every language (installations from
 * before, and while there is no database yet – the installer).
 */
final class NuLanguages
{
	public const KEY = 'languages';
	private const OLD_KEY = 'welcome_langs';
	private static ?array $cache = null;
	private static bool $connecting = false;

	/** Codes of the installed languages, in the order of NU_LANGS. */
	public static function installed(): array
	{
		if (self::$cache !== null) return self::$cache;
		$all = array_keys(NU_LANGS);
		// no nuconfig.php yet (installer – which connects with its own NuDB, so $connected may be true): every language, nothing read
		if (NuInstall::needed()) return $all;
		if (!NuDB::$connected) {
			// connect now – but never while connecting fails (the error page asks for texts again) and never before nuconfig.php exists
			if (self::$connecting) return $all;
			// command line (cron.php, dev/tests/run.php): no connection of its own – a failing one ends the script (0.8.714)
			if (PHP_SAPI === 'cli') return $all;
			self::$connecting = true;
			try { nu_db(); } finally { self::$connecting = false; }
		}
		$l = [];
		try {
			$db = nu_db();
			foreach ([self::KEY, self::OLD_KEY] as $k) {
				$v = $db->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$k]);
				$d = is_string($v) ? json_decode($v, true) : null;
				if (is_array($d)) { $l = array_values(array_intersect($all, array_map('strval', $d))); break; }
			}
		} catch (Throwable) {
			$l = [];                                             // no settings table yet: every language
		}
		return self::$cache = ($l ?: $all);
	}

	public static function has(string $code): bool
	{
		return in_array($code, self::installed(), true);
	}

	/** Language when nothing else decides: German, else English, else the first installed one. */
	public static function fallback(): string
	{
		$l = self::installed();
		foreach (['de', 'en'] as $c) if (in_array($c, $l, true)) return $c;
		return $l[0];
	}

	/**
	 * Stores the languages (unknown codes dropped; none = refused – at least one stays). New ones get their welcome template; the
	 * built-in templates of removed ones are deleted (read-only rows nobody changed). @return array{added: list<string>, removed: list<string>}
	 */
	public static function set(array $langs, string $by): array
	{
		$all = array_keys(NU_LANGS);
		$l = array_values(array_intersect($all, array_map('strval', $langs)));
		if (!$l) throw new InvalidArgumentException('At least one language is needed.');
		$before = self::installed();
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => json_encode($l), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $by];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::KEY])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::KEY);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::KEY] + $row);
		$db->run('DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::OLD_KEY]);
		self::$cache = null;
		$removed = array_values(array_diff($before, $l));
		NuWelcome::pruneTemplates($removed);
		try { NuWelcome::ensureTemplates(); } catch (Throwable $e) { error_log('nuBuilder Next languages, welcome templates: ' . $e->getMessage()); }
		return ['added' => array_values(array_diff($l, $before)), 'removed' => $removed];
	}

	/** Users per language: code => number of users whose sus_language is that language. */
	public static function userCounts(): array
	{
		$out = array_fill_keys(array_keys(NU_LANGS), 0);
		try {
			foreach (nu_db()->all("SELECT sus_language AS l, COUNT(*) AS n FROM zzzzsys_user WHERE sus_language IS NOT NULL AND sus_language <> '' GROUP BY sus_language") as $r) {
				$c = nu_lang_from_db((string)$r['l']);
				if ($c !== null) $out[$c] += (int)$r['n'];
			}
		} catch (Throwable) {
		}
		return $out;
	}
}
