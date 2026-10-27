<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Additional access levels per user (0.8.585). nuBuilder gives a user ONE access level (sus_zzzzsys_access_id); nuBuilder Next can
 * give him more in its own table zzzzsys_nunext_user_access (user_id, access_id, valid_until 'Y-m-d' or NULL = no end).
 * The rights of all active levels are combined – an extra level only adds (NuAcl::rightsOf: a right granted by any level counts;
 * browse / edit when any level allows it; "no changes to existing records" only when every level with the form says so).
 * Used for form rights (NuAcl), procedures (zzzzsys_access_php), reports (zzzzsys_access_report), dashboards and the forms list.
 * The main level alone decides the start page layout, list views, two-factor login is required when ANY active level asks for it,
 * field data access rules by access level and the hash variables #ACCESS_LEVEL_ID# / #USER_GROUP_ID#.
 * nuBuilder 4.x does not know the table: there the user has only the rights of his main level.
 */
final class NuUserAccess
{
	public const TABLE = 'zzzzsys_nunext_user_access';

	/** @var array<string, list<string>> user id => active extra level ids */
	private static array $cache = [];

	public static function ready(): bool
	{
		static $ok = null;
		try { return $ok ??= nu_db()->tableExists(self::TABLE); } catch (Throwable) { return false; }
	}

	public static function active(?string $validUntil): bool
	{
		$d = trim((string)$validUntil);
		return $d === '' || substr($d, 0, 10) >= date('Y-m-d');
	}

	/** Active extra levels of a user (without the main level). @return list<string> */
	public static function extraIds(string $userId): array
	{
		if ($userId === '' || !self::ready()) return [];
		if (isset(self::$cache[$userId])) return self::$cache[$userId];
		$out = [];
		foreach (nu_db()->all('SELECT access_id, valid_until FROM ' . self::TABLE . ' WHERE user_id = ?', [$userId]) as $r) {
			if (self::active($r['valid_until'] ?? null) && (string)$r['access_id'] !== '') $out[] = (string)$r['access_id'];
		}
		return self::$cache[$userId] = array_values(array_unique($out));
	}

	/** Main level + active extra levels of the signed-in user (empty for the globeadmin). @return list<string> */
	public static function levels(?array $u = null): array
	{
		$u ??= NuAuth::user();
		if (!$u || !empty($u['globeadmin'])) return [];
		$main = (string)($u['access_id'] ?? '');
		$all = $main !== '' ? [$main] : [];
		foreach (self::extraIds((string)($u['id'] ?? '')) as $a) if (!in_array($a, $all, true)) $all[] = $a;
		return $all;
	}

	/** SQL "col IN (?, ?)" + params for the levels of the signed-in user (never matches when there is none). @return array{0: string, 1: list<string>} */
	public static function inSql(string $col, ?array $u = null): array
	{
		$l = self::levels($u);
		if (!$l) return ['1 = 0', []];
		return [$col . ' IN (' . implode(', ', array_fill(0, count($l), '?')) . ')', $l];
	}

	/** All extra rows of a user, with the level's code: list of {access_id, valid_until, code, description, active}. */
	public static function rowsOf(string $userId): array
	{
		if ($userId === '' || !self::ready()) return [];
		$rows = nu_db()->all('SELECT x.access_id, x.valid_until, a.sal_code, a.sal_description FROM ' . self::TABLE . ' x
			LEFT JOIN zzzzsys_access a ON ' . nu_db()->sameText('a.zzzzsys_access_id', 'x.access_id') . ' WHERE x.user_id = ? ORDER BY a.sal_code', [$userId]);
		return array_map(static fn($r) => ['access_id' => (string)$r['access_id'], 'valid_until' => substr(trim((string)($r['valid_until'] ?? '')), 0, 10),
			'code' => (string)($r['sal_code'] ?? $r['access_id']), 'description' => (string)($r['sal_description'] ?? ''),
			'active' => self::active($r['valid_until'] ?? null)], $rows);
	}

	/** Users that have this level as an extra one: list of {user_id, valid_until, active}. */
	public static function usersOf(string $accessId): array
	{
		if ($accessId === '' || !self::ready()) return [];
		return array_map(static fn($r) => ['user_id' => (string)$r['user_id'], 'valid_until' => substr(trim((string)($r['valid_until'] ?? '')), 0, 10),
			'active' => self::active($r['valid_until'] ?? null)], nu_db()->all('SELECT user_id, valid_until FROM ' . self::TABLE . ' WHERE access_id = ?', [$accessId]));
	}

	/**
	 * Replace the extra levels of a user. $rows: list of [access_id, valid_until ('' or Y-m-d)]; the main level and duplicates are
	 * dropped. @return array<string, array{0: string, 1: string}> changes for the log: code => [before, after]
	 */
	public static function save(string $userId, string $mainLevel, array $rows): array
	{
		NuSchema::ensure();
		$db = nu_db();
		$want = [];
		foreach ($rows as [$acc, $until]) {
			$acc = (string)$acc;
			if ($acc === '' || $acc === $mainLevel || isset($want[$acc])) continue;
			$want[$acc] = (string)$until;
		}
		$have = [];
		foreach ($db->all('SELECT zzzzsys_nunext_user_access_id AS id, access_id, valid_until FROM ' . self::TABLE . ' WHERE user_id = ?', [$userId]) as $r) {
			$have[(string)$r['access_id']] = ['id' => (string)$r['id'], 'until' => substr(trim((string)($r['valid_until'] ?? '')), 0, 10)];
		}
		$codes = array_column($db->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access'), 'sal_code', 'zzzzsys_access_id');
		$label = static fn(string $a, string $u) => '✓' . ($u !== '' ? ' ' . $u : '');
		$who = mb_substr((string)(NuAuth::currentRaw()['login'] ?? ''), 0, 50);
		$changes = [];
		foreach ($have as $acc => $h) {
			if (array_key_exists($acc, $want)) continue;
			$db->delete(self::TABLE, 'zzzzsys_nunext_user_access_id', $h['id']);
			$changes[(string)($codes[$acc] ?? $acc)] = [$label($acc, $h['until']), ''];
		}
		foreach ($want as $acc => $until) {
			$row = ['valid_until' => $until !== '' ? $until : null, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $who];
			if (isset($have[$acc])) {
				if ($have[$acc]['until'] === $until) continue;
				$db->update(self::TABLE, $row, 'zzzzsys_nunext_user_access_id', $have[$acc]['id']);
				$changes[(string)($codes[$acc] ?? $acc)] = [$label($acc, $have[$acc]['until']), $label($acc, $until)];
			} else {
				$db->insert(self::TABLE, ['zzzzsys_nunext_user_access_id' => nu_id(), 'user_id' => $userId, 'access_id' => $acc] + $row);
				$changes[(string)($codes[$acc] ?? $acc)] = ['', $label($acc, $until)];
			}
		}
		unset(self::$cache[$userId]);
		return $changes;
	}

	/** A deleted user: his extra rows go too. */
	public static function forgetUser(string $userId): void
	{
		if (self::ready()) nu_db()->delete(self::TABLE, 'user_id', $userId);
		unset(self::$cache[$userId]);
	}

	/**
	 * Effective rights of a set of levels (0.8.589, user form → Effective rights): $main + $rows [access_id, valid_until] as entered
	 * in the form (not saved yet). Forms with every right and the levels that grant it (NuAcl::levelRights, combined like NuAcl::rightsOf),
	 * procedures and reports with their levels. Expired additional levels are listed and not counted.
	 * @return array{levels: list<array>, forms: list<array>, procs: list<array>, reports: list<array>}
	 */
	public static function effective(string $main, array $rows): array
	{
		$db = nu_db();
		$all = array_column($db->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access'), null, 'zzzzsys_access_id');
		$levels = [];
		$add = static function (string $a, bool $isMain, string $until) use (&$levels, $all): void {
			if ($a === '' || !isset($all[$a]) || isset($levels[$a])) return;
			$levels[$a] = ['id' => $a, 'code' => (string)($all[$a]['sal_code'] ?: $a), 'description' => (string)($all[$a]['sal_description'] ?? ''),
				'main' => $isMain, 'until' => $until, 'active' => $isMain || self::active($until)];
		};
		$add($main, true, '');
		foreach ($rows as [$a, $u]) $add((string)$a, false, (string)$u);
		$active = array_keys(array_filter($levels, static fn($l) => $l['active']));
		$out = ['levels' => array_values($levels), 'forms' => [], 'procs' => [], 'reports' => []];
		if (!$active) return $out;
		$qm = implode(', ', array_fill(0, count($active), '?'));
		$code = static fn(string $a) => $levels[$a]['code'];
		$forms = nu_forms();
		$byForm = [];
		foreach ($db->all("SELECT slf_zzzzsys_access_id AS a, slf_zzzzsys_form_id AS f FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id IN ($qm)", $active) as $r) {
			$byForm[(string)$r['f']][] = (string)$r['a'];
		}
		$keys = ['view', 'add', 'save', 'delete', 'clone', 'print'];
		foreach ($byForm as $f => $lv) {
			$e = ['id' => $f, 'label' => nu_form_label($f), 'code' => (string)($forms[$f]['code'] ?? $f), 'rights' => array_fill_keys($keys, []), 'browse' => false, 'edit' => false];
			foreach (array_unique($lv) as $a) {
				$r = NuAcl::levelRights($a, $f);
				if (!$r['view']) continue;
				foreach ($keys as $k) if (!empty($r[$k === 'add' ? 'create' : $k])) $e['rights'][$k][] = $code($a);
				$e['browse'] = $e['browse'] || $r['browse'];
				$e['edit'] = $e['edit'] || $r['edit'];
			}
			$out['forms'][] = $e;
		}
		usort($out['forms'], static fn($x, $y) => strcasecmp($x['label'], $y['label']));
		$list = static function (string $sql) use ($db, $active, $code): array {
			$o = [];
			try {
				foreach ($db->all($sql, $active) as $r) {
					$k = (string)$r['id'];
					$o[$k] ??= ['id' => $k, 'code' => (string)$r['code'], 'description' => (string)($r['description'] ?? ''), 'levels' => []];
					$o[$k]['levels'][] = $code((string)$r['a']);
				}
			} catch (Throwable) { /* table missing */ }
			uasort($o, static fn($x, $y) => strcasecmp($x['code'], $y['code']));
			return array_values($o);
		};
		if ($db->tableExists('zzzzsys_access_php')) $out['procs'] = $list("SELECT p.zzzzsys_php_id AS id, p.sph_code AS code, p.sph_description AS description, x.slp_zzzzsys_access_id AS a
			FROM zzzzsys_access_php x JOIN zzzzsys_php p ON p.zzzzsys_php_id = x.slp_zzzzsys_php_id WHERE x.slp_zzzzsys_access_id IN ($qm)");
		if ($db->tableExists('zzzzsys_access_report')) $out['reports'] = $list("SELECT r.zzzzsys_report_id AS id, r.sre_code AS code, r.sre_description AS description, x.sre_zzzzsys_access_id AS a
			FROM zzzzsys_access_report x JOIN zzzzsys_report r ON r.zzzzsys_report_id = x.sre_zzzzsys_report_id WHERE x.sre_zzzzsys_access_id IN ($qm)");
		return $out;
	}

	/** Does the signed-in user (restricted admin) hold this level – as main or active extra level? */
	public static function holds(string $accessId): bool
	{
		return $accessId !== '' && in_array($accessId, self::levels(), true);
	}
}
