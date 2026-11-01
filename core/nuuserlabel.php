<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Own titles of the user fields Code / Additional 1 / 2 (0.8.799) – nuBuilder Forte's $nuConfigUserCodeLabel,
 * $nuConfigUserAdditional1Label, $nuConfigUserAdditional2Label (read by nu_user_label() in core/nuapp.php). The user form renames them
 * in place (globeadmin): the value is written where nu_cfg() reads it – nuBuilder Forte's settings table while
 * $nuConfigSettingsFromDB is on and the row exists, else nuconfig.php – so nuBuilder Forte shows the same title.
 */
final class NuUserLabel
{
	public const MAX = 100;

	/** Text key (user_code …) → field (sus_code …). */
	public const FIELDS = ['user_code' => 'sus_code', 'user_additional1' => 'sus_additional1', 'user_additional2' => 'sus_additional2'];

	public static function setting(string $key): string
	{
		return NU_USER_LABELS[$key] ?? '';
	}

	/** Where a change is written: 'db' (nuBuilder Forte's settings table) or 'file' (nuconfig.php). */
	public static function target(string $key): string
	{
		return self::row($key) !== null ? 'db' : 'file';
	}

	/** The settings table row of the variable – only while nu_cfg() reads the table. */
	private static function row(string $key): ?array
	{
		$name = self::setting($key);
		if ($name === '' || !NuSettings::fromDb() || !NuSettings::available()) return null;
		foreach (NuSettings::rows() as $r) if (trim((string)$r['cfg_setting']) === '$' . $name) return $r;
		return null;
	}

	/** The value as stored (not translated); '' = built-in title. */
	public static function stored(string $key): string
	{
		$v = nu_cfg(self::setting($key));
		return is_scalar($v) ? trim((string)$v) : '';
	}

	/** Whether the signed-in user may rename: the real globeadmin (not acting as someone, not demo) – it writes nuconfig.php / settings. */
	public static function canRename(): bool
	{
		$u = NuAuth::user();
		return !empty($u['globeadmin']) && empty($u['demo']) && !NuImpersonate::active();
	}

	/** @return string error ('' = saved) */
	public static function save(string $key, string $value): string
	{
		$name = self::setting($key);
		if ($name === '') return t('err_forbidden');
		$value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
		if (mb_strlen($value) > self::MAX) return t('err_too_long', self::MAX);
		$old = self::stored($key);
		if ($value === $old) return '';
		$row = self::row($key);
		if ($row !== null) {
			[, $errors] = NuSettings::save([(string)$row['zzzzsys_config_id'] => $value]);
			if ($errors) return (string)reset($errors);
		} else {
			if (!NuConfigFile::writable()) return t('cfg_err_not_writable', NuConfigFile::path());
			$err = NuConfigFile::setVariable($name, var_export($value, true));
			if ($err !== '') return $err;
		}
		NuLog::write('user_label_changed', 'zzzzsys_user', '', ['setting' => '$' . $name, 'old' => $old, 'new' => $value, 'in' => $row !== null ? 'zzzzsys_config' : 'nuconfig.php']);
		return '';
	}
}
