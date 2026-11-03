<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Forms for everyone (0.8.784): a form every access level may open, with the same buttons – the form's Access levels dialog
 * (pages/nuformaccess.php, assets/js/nu/nuformaccess.js), switch "Everyone".
 *
 * The rights themselves are nuBuilder Forte's normal rows of zzzzsys_access_form, one per level – so nuBuilder Forte opens the form for
 * everyone too. nuBuilder Next only remembers WHICH forms are for everyone and with which buttons (setting row 'form_everyone':
 * {form id: {rights: {add: bool, …}, dm, ft, new: bool}}): "new" = an access level created or cloned in nuBuilder Next gets the form at
 * once (addToLevel(), pages/nuaccesslevel.php and the REST API). A level created in nuBuilder Forte does not – missing() lists such gaps
 * (Compatibility check, point FE), fill() closes them. Admin-equivalent forms (NuSecurity::ADMIN_FORMS) are never for everyone.
 */
final class NuFormEveryone
{
	public const KEY = 'form_everyone';
	private static ?array $cache = null;

	/** @return array<string, array{rights: array<string, bool>, dm: string, ft: string, new: bool}> */
	public static function all(): array
	{
		if (self::$cache !== null) return self::$cache;
		$out = [];
		try {
			$v = nu_setting_value(self::KEY);
			$d = is_string($v) ? json_decode($v, true) : null;
			if (is_array($d)) foreach ($d as $fid => $c) if (is_array($c) && self::allowed((string)$fid)) $out[(string)$fid] = self::clean($c);
		} catch (Throwable) {
		}
		return self::$cache = $out;
	}

	public static function of(string $formId): ?array
	{
		return self::all()[$formId] ?? null;
	}

	/** Admin-equivalent forms (rights on them are as strong as a globeadmin's) can never be for everyone. */
	public static function allowed(string $formId): bool
	{
		return $formId !== '' && !in_array($formId, NuSecurity::ADMIN_FORMS, true);
	}

	public static function clean(array $c): array
	{
		$r = [];
		foreach (array_keys(NuAcl::RIGHTS) as $k) $r[$k] = !empty($c['rights'][$k]);
		$dm = trim((string)($c['dm'] ?? '')) === '0' ? '0' : '';
		$ft = in_array(trim((string)($c['ft'] ?? '')), ['0', '1'], true) ? trim((string)$c['ft']) : '';
		return ['rights' => $r, 'dm' => $dm, 'ft' => $ft, 'new' => !array_key_exists('new', $c) || !empty($c['new'])];
	}

	/** Stores (or with null removes) the switch of a form; the rows of the levels are written by the caller. */
	public static function set(string $formId, ?array $cfg, string $by): void
	{
		$all = self::all();
		if ($cfg === null) unset($all[$formId]); elseif (self::allowed($formId)) $all[$formId] = self::clean($cfg);
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => (string)json_encode((object)$all), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr($by, 0, 50)];
		nu_setting_save(self::KEY, $row, $db);
		self::$cache = null;
	}

	/** The columns of a zzzzsys_access_form row for a switch's buttons. */
	public static function row(array $cfg): array
	{
		$cols = nu_db()->columns('zzzzsys_access_form');
		$row = [];
		foreach (NuAcl::RIGHTS as $right => $col) $row[$col] = !empty($cfg['rights'][$right]) ? '1' : '0';
		if (isset($cols['slf_data_mode'])) $row['slf_data_mode'] = ($cfg['dm'] ?? '') === '0' ? '0' : null;
		if (isset($cols['slf_form_type'])) $row['slf_form_type'] = in_array($cfg['ft'] ?? '', ['0', '1'], true) ? $cfg['ft'] : null;
		return $row;
	}

	/**
	 * A new access level (created or cloned in nuBuilder Next): the forms for everyone it does not have yet, with their buttons.
	 * @return list<string> labels of the forms added
	 */
	public static function addToLevel(string $accessId): array
	{
		$db = nu_db();
		$have = array_flip(array_map('strval', array_column($db->all('SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ?', [$accessId]), 'slf_zzzzsys_form_id')));
		$added = [];
		foreach (self::all() as $fid => $cfg) {
			if (!$cfg['new'] || isset($have[$fid]) || !$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$fid])) continue;
			$db->insert('zzzzsys_access_form', ['zzzzsys_access_form_id' => nu_id(), 'slf_zzzzsys_access_id' => $accessId, 'slf_zzzzsys_form_id' => $fid] + self::row($cfg));
			$added[] = nu_form_label($fid);
		}
		if ($added) NuLog::write('form_everyone_added', 'zzzzsys_access', $accessId, ['forms' => implode(', ', $added)]);
		return $added;
	}

	/** Forms for everyone that some access levels do not have (e.g. levels created in nuBuilder Forte): [form id => [access ids]]. */
	public static function missing(): array
	{
		$all = self::all();
		if (!$all) return [];
		$db = nu_db();
		$levels = array_map('strval', array_column($db->all('SELECT zzzzsys_access_id FROM zzzzsys_access'), 'zzzzsys_access_id'));
		$out = [];
		foreach (array_keys($all) as $fid) {
			if (!$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$fid])) continue;
			$have = array_flip(array_map('strval', array_column($db->all('SELECT slf_zzzzsys_access_id FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = ?', [$fid]), 'slf_zzzzsys_access_id')));
			$miss = array_values(array_filter($levels, static fn($l) => !isset($have[$l])));
			if ($miss) $out[$fid] = $miss;
		}
		return $out;
	}

	/** Gives the forms for everyone to the levels that miss them (missing()). @return int rows written */
	public static function fill(): int
	{
		$n = 0;
		$db = nu_db();
		foreach (self::missing() as $fid => $levels) {
			$cfg = self::of($fid);
			if (!$cfg) continue;
			foreach ($levels as $aid) {
				$db->insert('zzzzsys_access_form', ['zzzzsys_access_form_id' => nu_id(), 'slf_zzzzsys_access_id' => $aid, 'slf_zzzzsys_form_id' => $fid] + self::row($cfg));
				$n++;
			}
		}
		if ($n) NuLog::write('form_everyone_filled', 'zzzzsys_access_form', '', ['rows' => $n]);
		return $n;
	}

	/** Housekeeping: switches of deleted forms. @return int removed */
	public static function purgeOrphans(): int
	{
		$all = self::all();
		if (!$all) return 0;
		$db = nu_db();
		$gone = array_values(array_filter(array_keys($all), static fn($fid) => !$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$fid])));
		foreach ($gone as $fid) self::set($fid, null, 'housekeeping');
		return count($gone);
	}
}
