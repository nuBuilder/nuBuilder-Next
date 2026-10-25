<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Rules per subject (0.8.480, handover specs 04 field data access and 07 browse column visibility): who a rule applies to and which
 * rule wins. A rule is ['type' => user|perm|team|dept|access|default, 'subject' => …]; the most specific matching rule wins –
 * user (5) > permission item (4) > team / department (3) > access level (2) > default (1). Rules of the same rank are decided by the
 * caller's order of modes (field access: the more open mode; column visibility: hide).
 */
final class NuSubject
{
	public const RANK = ['user' => 5, 'perm' => 4, 'team' => 3, 'dept' => 3, 'access' => 2, 'default' => 1];
	public const TYPES = ['user', 'perm', 'team', 'dept', 'access'];

	private static ?array $me = null;

	/** The signed-in user (while acting as another user: that user): id, globeadmin, access level id, permission idents, team, department. */
	public static function me(): array
	{
		if (self::$me !== null) return self::$me;
		$u = NuAuth::user() ?? [];
		$me = ['id' => (string)($u['id'] ?? ''), 'ga' => !empty($u['globeadmin']), 'access' => (string)($u['access_id'] ?? ''), 'perms' => [], 'team' => '', 'dept' => ''];
		if (!$me['ga'] && $me['id'] !== '') {
			try {
				$row = nu_db()->one('SELECT sus_team, sus_department FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$me['id']]) ?? [];
				$me['team'] = mb_strtolower(trim((string)($row['sus_team'] ?? '')));
				$me['dept'] = mb_strtolower(trim((string)($row['sus_department'] ?? '')));
				$me['perms'] = array_map(static fn($p) => mb_strtolower(trim((string)$p)), NuPermission::idents($me['id']));
			} catch (Throwable) {
			}
		}
		return self::$me = $me;
	}

	/** Forget the cached user (tests, "preview as user"). */
	public static function reset(?array $as = null): void
	{
		self::$me = $as;
	}

	/** The user of a preview (Data access → "As user"): same shape as me(). */
	public static function forUser(string $userId): array
	{
		$db = nu_db();
		$row = $db->one('SELECT zzzzsys_user_id, sus_zzzzsys_access_id, sus_team, sus_department FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]) ?? [];
		return ['id' => $userId, 'ga' => false, 'access' => (string)($row['sus_zzzzsys_access_id'] ?? ''),
			'perms' => array_map(static fn($p) => mb_strtolower(trim((string)$p)), NuPermission::idents($userId)),
			'team' => mb_strtolower(trim((string)($row['sus_team'] ?? ''))), 'dept' => mb_strtolower(trim((string)($row['sus_department'] ?? '')))];
	}

	public static function matches(array $rule, ?array $me = null): bool
	{
		$me ??= self::me();
		$s = trim((string)($rule['subject'] ?? ''));
		return match ((string)($rule['type'] ?? '')) {
			'default' => true,
			'user'    => $s !== '' && $s === $me['id'],
			'access'  => $s !== '' && $s === $me['access'],
			'perm'    => $s !== '' && in_array(mb_strtolower($s), $me['perms'], true),
			'team'    => $s !== '' && $me['team'] !== '' && mb_strtolower($s) === $me['team'],
			'dept'    => $s !== '' && $me['dept'] !== '' && mb_strtolower($s) === $me['dept'],
			default   => false,
		};
	}

	/**
	 * The winning rule: highest rank among the matching rules that pass $ok (a condition), then the mode that comes first in $order.
	 * @param list<array> $rules each with type, subject, mode
	 * @param list<string> $order modes, the one that wins a tie first
	 */
	public static function best(array $rules, array $order, ?callable $ok = null, ?array $me = null): ?array
	{
		$win = null;
		foreach ($rules as $r) {
			if (!self::matches($r, $me) || ($ok !== null && !$ok($r))) continue;
			if ($win === null) { $win = $r; continue; }
			$a = self::RANK[$r['type']] ?? 0;
			$b = self::RANK[$win['type']] ?? 0;
			if ($a > $b) { $win = $r; continue; }
			if ($a === $b) {
				$ia = array_search($r['mode'], $order, true);
				$ib = array_search($win['mode'], $order, true);
				if ($ia !== false && ($ib === false || $ia < $ib)) $win = $r;
			}
		}
		return $win;
	}

	/** Lists for the subject selects: access levels, users, permission items, teams, departments – [value, label]. */
	public static function catalog(): array
	{
		$db = nu_db();
		$out = ['access' => [], 'user' => [], 'perm' => [], 'team' => [], 'dept' => []];
		try {
			foreach ($db->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code') as $r) $out['access'][] = [(string)$r['zzzzsys_access_id'], trim($r['sal_code'] . ' – ' . $r['sal_description'], ' –')];
			foreach ($db->all('SELECT zzzzsys_user_id, sus_login_name, sus_name FROM zzzzsys_user ORDER BY sus_login_name') as $r) $out['user'][] = [(string)$r['zzzzsys_user_id'], trim($r['sus_login_name'] . ' – ' . $r['sus_name'], ' –')];
			foreach (NuPermission::items() as $r) $out['perm'][] = [(string)$r['pme_ident'], trim($r['pme_ident'] . ' – ' . $r['pme_description'], ' –')];
			foreach (['team' => 'sus_team', 'dept' => 'sus_department'] as $k => $c) {
				foreach ($db->all("SELECT DISTINCT $c AS v FROM zzzzsys_user WHERE $c IS NOT NULL AND $c <> '' ORDER BY $c") as $r) $out[$k][] = [(string)$r['v'], (string)$r['v']];
			}
		} catch (Throwable) {
		}
		return $out;
	}
}
