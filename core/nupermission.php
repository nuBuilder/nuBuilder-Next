<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Permission items – nuBuilder's forms "nupermissionitem" (zzzzsys_permission_item) and "nuuserpermission" (zzzzsys_user_permission,
 * subform sf_permission of the user form). A user has an item when a row upe_zzzzsys_user_id = user, upe_ident = zzzzsys_permission_item_id
 * and upe_active = '1' exists. Code asks with nuUserHasPermission('ident') (PHP and JavaScript) or #USER_PERMISSIONS#.
 */
final class NuPermission
{
	public const ITEM = 'zzzzsys_permission_item';
	public const USER = 'zzzzsys_user_permission';

	public static function ensureTables(): void
	{
		$db = nu_db();
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$opt = $db->type === 'mysql' ? NuDDL::mysqlTableOptions() : '';
		if (!$db->tableExists(self::ITEM)) {
			$db->run('CREATE TABLE ' . self::ITEM . ' (zzzzsys_permission_item_id ' . $T('id') . ' NOT NULL PRIMARY KEY, pme_description ' . $T('longtext') . ' NULL, '
				. 'pme_group ' . $T('text', '50') . ' NULL, pme_ident ' . $T('text', '50') . ' NULL)' . $opt);
		}
		if (!$db->tableExists(self::USER)) {
			$db->run('CREATE TABLE ' . self::USER . ' (zzzzsys_user_permission_id ' . $T('id') . ' NOT NULL PRIMARY KEY, upe_zzzzsys_user_id ' . $T('id') . ' NULL, '
				. 'upe_ident ' . $T('text', '50') . ' NULL, upe_active ' . $T('text', '1') . ' NULL)' . $opt);
			try { $db->run('CREATE INDEX upe_zzzzsys_user_id ON ' . self::USER . ' (upe_zzzzsys_user_id)'); } catch (Throwable) {}
		}
	}

	public static function exists(): bool
	{
		static $ok = null;
		return $ok ??= nu_db()->tableExists(self::ITEM) && nu_db()->tableExists(self::USER);
	}

	/** All items, ordered by group and ident. */
	public static function items(): array
	{
		if (!self::exists()) return [];
		return nu_db()->all('SELECT zzzzsys_permission_item_id, pme_ident, pme_group, pme_description FROM ' . self::ITEM . ' ORDER BY pme_group, pme_ident');
	}

	/** @return array<string, string> item id => upe_active of a user (all rows, active or not) */
	public static function forUser(string $userId): array
	{
		if ($userId === '' || !self::exists()) return [];
		$out = [];
		foreach (nu_db()->all('SELECT upe_ident, upe_active FROM ' . self::USER . ' WHERE upe_zzzzsys_user_id = ?', [$userId]) as $r) {
			$k = (string)$r['upe_ident'];
			$out[$k] = ($out[$k] ?? '0') === '1' || (string)$r['upe_active'] === '1' ? '1' : '0';
		}
		return $out;
	}

	/** Active idents of a user (nuBuilder nuGetUserPermissions). */
	public static function idents(string $userId): array
	{
		return NuHash::permissions($userId);
	}

	/**
	 * Sets the active items of a user: checked → upe_active '1' (row added if missing), unchecked → row removed.
	 * @return array{0: string[], 1: string[]} [added idents, removed idents]
	 */
	public static function setUser(string $userId, array $itemIds): array
	{
		self::ensureTables();
		$db = nu_db();
		$known = array_column(self::items(), 'pme_ident', 'zzzzsys_permission_item_id');
		$want = array_values(array_unique(array_filter(array_map('strval', $itemIds), static fn($i) => isset($known[$i]))));
		$have = self::forUser($userId);
		$added = $removed = [];
		$db->transaction(function () use ($db, $userId, $want, $have, $known, &$added, &$removed) {
			foreach ($want as $i) {
				if (!isset($have[$i])) {
					$db->insert(self::USER, ['zzzzsys_user_permission_id' => nu_id(), 'upe_zzzzsys_user_id' => $userId, 'upe_ident' => $i, 'upe_active' => '1']);
					$added[] = $known[$i];
				} elseif ($have[$i] !== '1') {
					$db->run('UPDATE ' . self::USER . " SET upe_active = '1' WHERE upe_zzzzsys_user_id = ? AND upe_ident = ?", [$userId, $i]);
					$added[] = $known[$i];
				}
			}
			foreach ($have as $i => $active) {
				if (in_array((string)$i, $want, true) || !isset($known[$i])) continue;   // rows of unknown items stay as they are
				$db->run('DELETE FROM ' . self::USER . ' WHERE upe_zzzzsys_user_id = ? AND upe_ident = ?', [$userId, (string)$i]);
				if ($active === '1') $removed[] = $known[$i];
			}
		});
		return [$added, $removed];
	}

	/** Copies the rows of a user (clone) – as nuBuilder copies the subform rows. */
	public static function copyUser(string $fromId, string $toId): void
	{
		if (!self::exists()) return;
		$db = nu_db();
		foreach ($db->all('SELECT upe_ident, upe_active FROM ' . self::USER . ' WHERE upe_zzzzsys_user_id = ?', [$fromId]) as $r) {
			$db->insert(self::USER, ['zzzzsys_user_permission_id' => nu_id(), 'upe_zzzzsys_user_id' => $toId, 'upe_ident' => $r['upe_ident'], 'upe_active' => $r['upe_active']]);
		}
	}

	public static function deleteUser(string $userId): void
	{
		if (self::exists()) nu_db()->run('DELETE FROM ' . self::USER . ' WHERE upe_zzzzsys_user_id = ?', [$userId]);
	}

	/** Users with the item (active rows). */
	public static function users(string $itemId): array
	{
		if (!self::exists()) return [];
		return nu_db()->all('SELECT u.zzzzsys_user_id, u.sus_login_name, u.sus_name, u.sus_first_name, u.sus_last_name, a.sal_code FROM ' . self::USER . ' p
			JOIN zzzzsys_user u ON u.zzzzsys_user_id = p.upe_zzzzsys_user_id LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id
			WHERE p.upe_ident = ? AND p.upe_active = \'1\' ORDER BY u.sus_login_name', [$itemId]);
	}

	/**
	 * Sets the users of an item: listed users get it active, other users lose it.
	 * @return array{0: string[], 1: string[]} [added logins, removed logins]
	 */
	public static function setItemUsers(string $itemId, array $userIds): array
	{
		self::ensureTables();
		$db = nu_db();
		$logins = array_column($db->all('SELECT zzzzsys_user_id, sus_login_name FROM zzzzsys_user'), 'sus_login_name', 'zzzzsys_user_id');
		$want = array_values(array_unique(array_filter(array_map('strval', $userIds), static fn($u) => isset($logins[$u]))));
		$have = [];
		foreach ($db->all('SELECT upe_zzzzsys_user_id, upe_active FROM ' . self::USER . ' WHERE upe_ident = ?', [$itemId]) as $r) {
			$k = (string)$r['upe_zzzzsys_user_id'];
			$have[$k] = ($have[$k] ?? '0') === '1' || (string)$r['upe_active'] === '1' ? '1' : '0';
		}
		$added = $removed = [];
		$db->transaction(function () use ($db, $itemId, $want, $have, $logins, &$added, &$removed) {
			foreach ($want as $u) {
				if (!isset($have[$u])) {
					$db->insert(self::USER, ['zzzzsys_user_permission_id' => nu_id(), 'upe_zzzzsys_user_id' => $u, 'upe_ident' => $itemId, 'upe_active' => '1']);
					$added[] = (string)$logins[$u];
				} elseif ($have[$u] !== '1') {
					$db->run('UPDATE ' . self::USER . " SET upe_active = '1' WHERE upe_zzzzsys_user_id = ? AND upe_ident = ?", [$u, $itemId]);
					$added[] = (string)$logins[$u];
				}
			}
			foreach ($have as $u => $active) {
				if (in_array((string)$u, $want, true) || !isset($logins[$u])) continue;
				$db->run('DELETE FROM ' . self::USER . ' WHERE upe_zzzzsys_user_id = ? AND upe_ident = ?', [(string)$u, $itemId]);
				if ($active === '1') $removed[] = (string)$logins[$u];
			}
		});
		return [$added, $removed];
	}

	/** Code that names the ident in quotes: nuUserHasPermission('ident') … (forms, events, procedures). */
	public static function usage(string $ident): array
	{
		if ($ident === '') return [];
		$db = nu_db();
		$out = [];
		$like = substr(NuDB::like($ident), 1, -1);
		[$p1, $p2] = ["%'" . $like . "'%", '%"' . $like . '"%'];
		$fcols = $db->columns('zzzzsys_form');
		foreach (['sfo_javascript', 'sfo_edit_javascript', 'sfo_browse_javascript'] as $c) {
			if (!isset($fcols[$c])) continue;
			foreach ($db->all("SELECT zzzzsys_form_id, sfo_code FROM zzzzsys_form WHERE $c LIKE ? ESCAPE '!' OR $c LIKE ? ESCAPE '!'", [$p1, $p2]) as $r) {
				$out[] = ['form_id' => (string)$r['zzzzsys_form_id'], 'proc_id' => '', 'label' => $r['sfo_code'] . ' · ' . $c];
			}
		}
		if ($db->tableExists('zzzzsys_event')) {
			foreach ($db->all("SELECT e.sev_event, o.sob_all_id, f.zzzzsys_form_id, f.sfo_code FROM zzzzsys_event e JOIN zzzzsys_object o ON o.zzzzsys_object_id = e.sev_zzzzsys_object_id
					LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id WHERE e.sev_javascript LIKE ? ESCAPE '!' OR e.sev_javascript LIKE ? ESCAPE '!'", [$p1, $p2]) as $r) {
				$out[] = ['form_id' => (string)$r['zzzzsys_form_id'], 'proc_id' => '', 'label' => $r['sfo_code'] . ' · ' . $r['sob_all_id'] . ' · ' . $r['sev_event']];
			}
		}
		if ($db->tableExists('zzzzsys_php')) {
			$js = isset($db->columns('zzzzsys_php')['sph_javascript']);
			$sql = "SELECT zzzzsys_php_id, sph_code FROM zzzzsys_php WHERE sph_php LIKE ? ESCAPE '!' OR sph_php LIKE ? ESCAPE '!'" . ($js ? " OR sph_javascript LIKE ? ESCAPE '!' OR sph_javascript LIKE ? ESCAPE '!'" : '');
			foreach ($db->all($sql, $js ? [$p1, $p2, $p1, $p2] : [$p1, $p2]) as $r) {
				$hook = NuProcedure::isHook((string)$r['zzzzsys_php_id']);
				$out[] = ['form_id' => $hook ? substr((string)$r['zzzzsys_php_id'], 0, -3) : '', 'proc_id' => $hook ? '' : (string)$r['zzzzsys_php_id'], 'label' => 'PHP ' . $r['sph_code']];
			}
		}
		return $out;
	}

	/** @return array{0: ?string, 1: array} [id, errors] */
	public static function save(array $in, string $id = ''): array
	{
		self::ensureTables();
		$db = nu_db();
		$row = [
			'pme_ident' => trim((string)($in['pme_ident'] ?? '')),
			'pme_group' => trim((string)($in['pme_group'] ?? '')),
			'pme_description' => str_replace("\r\n", "\n", (string)($in['pme_description'] ?? '')),
		];
		$errors = [];
		if ($row['pme_ident'] === '') $errors['pme_ident'] = t('err_required');
		elseif (!preg_match('/^[A-Za-z0-9_.:\-]+$/', $row['pme_ident'])) $errors['pme_ident'] = t('perm_err_ident');
		nu_maxlen($errors, 'pme_ident', $row['pme_ident'], 50);
		nu_maxlen($errors, 'pme_group', $row['pme_group'], 50);
		if (!isset($errors['pme_ident']) && (int)$db->value('SELECT COUNT(*) FROM ' . self::ITEM . ' WHERE pme_ident = ? AND zzzzsys_permission_item_id <> ?', [$row['pme_ident'], $id])) {
			$errors['pme_ident'] = t('perm_err_taken');
		}
		if ($errors) return [null, $errors];
		foreach ($row as $k => $v) if ($v === '') $row[$k] = null;
		if ($id !== '') {
			$db->update(self::ITEM, $row, 'zzzzsys_permission_item_id', $id);
		} else {
			$id = nu_id();
			$db->insert(self::ITEM, ['zzzzsys_permission_item_id' => $id] + $row);
		}
		return [$id, []];
	}

	public static function delete(string $id): void
	{
		$db = nu_db();
		$db->transaction(function () use ($db, $id) {
			$db->run('DELETE FROM ' . self::USER . ' WHERE upe_ident = ?', [$id]);
			$db->delete(self::ITEM, 'zzzzsys_permission_item_id', $id);
		});
	}
}
