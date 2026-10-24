<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Admin roles (0.8.323): users of zzzzsys_user who get a defined part of the globeadmin's rights in nuBuilder Next.
 * A role (zzzzsys_nunext_admin_role) is a set of rights – one per tool, each "view" or "change"; a user gets at most one role
 * (zzzzsys_nunext_admin_user, key = zzzzsys_user_id). nuBuilder itself does not know these roles: there the user stays a normal
 * user with his access level.
 *   NuAdmin::can('update', 'change')   – the globeadmin always; a user with a role when the role has the right
 *   NuAdmin::require('update')          – 403 otherwise (POST: 'change')
 * Rights marked FULL lead to full access in the end (PHP on the server, the whole database, the web folder, passwords) – the role
 * editor says so. Rights on nuBuilder's system forms (FORMS) are added to the user's access level in NuAcl::rights().
 * Roles are managed by the globeadmin and by users with the right adminroles; nobody changes his own role or gives rights he does
 * not have himself.
 */
final class NuAdmin
{
	public const ROLE_TABLE = 'zzzzsys_nunext_admin_role';
	public const USER_TABLE = 'zzzzsys_nunext_admin_user';

	/**
	 * right => [group, label key, full access, levels ('vc' = view and change, 'c' = one level), nuBuilder system forms]
	 * Groups as on the start page.
	 */
	public const RIGHTS = [
		'update'        => ['system', 'upd_title', true, 'vc', []],
		'configfile'    => ['system', 'cfg_title', true, 'c', []],
		'settings'      => ['system', 'set_title', false, 'vc', []],
		'setupcode'     => ['system', 'scode_title', true, 'vc', []],   // 0.8.444: JavaScript for every form page = full access
		'securitycheck' => ['system', 'secchk_title', false, 'vc', []],
		'setupwizard'   => ['system', 'wiz_title', false, 'c', []],
		'status'        => ['system', 'status_title', false, 'vc', []],
		'dbtool'        => ['data', 'home_db', true, 'c', []],
		'filemanager'   => ['data', 'vendor_tfm', true, 'c', []],
		'import'        => ['data', 'imp_title', false, 'c', []],
		'appcloner'     => ['data', 'ac_title', true, 'c', []],
		'sample'        => ['data', 'smp_title', false, 'vc', []],
		'forms'         => ['dev', 'adm_r_forms', true, 'vc', [NuAcl::FORM_FORM, NuAcl::FORM_ITEM, NuAcl::FORM_FILE, NuAcl::FORM_FORMAT, NuAcl::FORM_TRANSLATE, 'nucodesnippet']],
		'formpublish'   => ['dev', 'adm_r_formpublish', true, 'c', []],   // form drafts (0.8.386): publishing puts PHP / JavaScript live – full access
		'procedures'    => ['dev', 'proc_title', true, 'vc', [NuAcl::FORM_PROCEDURE]],
		'reports'       => ['dev', 'adm_r_reports', false, 'vc', [NuAcl::FORM_REPORT, NuAcl::FORM_SELECT]],
		'dashboards'    => ['dev', 'dash_title', false, 'vc', []],
		'prompts'       => ['dev', 'pg_title', false, 'vc', [NuAcl::FORM_PROMPT]],
		'users'         => ['users', 'adm_r_users', false, 'vc', [NuAcl::FORM_USER, NuAcl::FORM_PERMISSION]],
		'access'        => ['users', 'nav_access', false, 'vc', [NuAcl::FORM_ACCESS]],
		'userfields'    => ['users', 'uf_title', false, 'vc', []],   // custom user fields (0.8.454): change = add / change / delete fields
		'sessions'      => ['users', 'sess_title', false, 'vc', []],
		'impersonate'   => ['users', 'adm_r_impersonate', true, 'c', []],
		'adminroles'    => ['users', 'adm_title', true, 'c', []],
		'branding'      => ['look', 'brand_title', false, 'vc', []],
		'homelayout'    => ['look', 'adm_r_homelayout', false, 'c', []],
		'listviews'     => ['look', 'adm_r_listviews', false, 'c', []],   // shared list views, default view of a list (0.8.329)
		'email'         => ['comm', 'nav_email', false, 'vc', [NuAcl::FORM_SETUP, NuAcl::FORM_EMAILLOG, NuAcl::FORM_EMAILTEMPLATE]],
		'notifications' => ['comm', 'ntf_title', false, 'vc', []],   // notification centre (0.8.357): view = all + read receipts, change = send
		'apiclients'    => ['integration', 'api_title', true, 'vc', []],
		'log'           => ['logs', 'adm_r_log', false, 'vc', [NuAcl::FORM_LOG]],
		'audit'         => ['logs', 'aud_title', true, 'vc', [NuAcl::FORM_AUDIT]],   // audit trail (0.8.350): change = its settings
		'debug'         => ['logs', 'debug_title', false, 'vc', [NuAcl::FORM_DEBUG]],
		'compat'        => ['logs', 'compat_title', false, 'vc', []],
	];
	public const GROUPS = ['system' => 'home_group_system', 'data' => 'home_group_data', 'dev' => 'home_group_dev', 'users' => 'home_group_users',
		'look' => 'home_group_look', 'comm' => 'home_group_comm', 'integration' => 'home_group_integration', 'logs' => 'adm_group_logs'];

	/** Default roles (created once, then editable): code => rights. */
	public static function defaults(): array
	{
		$all = array_map(static fn() => 'change', self::RIGHTS);
		$system = ['update', 'configfile', 'settings', 'setupcode', 'dbtool', 'filemanager', 'appcloner', 'apiclients', 'adminroles'];
		return [
			'full_admin'      => $all,
			'admin_no_system' => array_diff_key($all, array_flip($system)),
			'developer'       => ['forms' => 'change', 'procedures' => 'change', 'reports' => 'change', 'dashboards' => 'change', 'prompts' => 'change',
				'import' => 'change', 'debug' => 'view', 'compat' => 'view'],
			'user_manager'    => ['users' => 'change', 'access' => 'change', 'userfields' => 'change', 'sessions' => 'change', 'listviews' => 'change'],
			'support'         => ['log' => 'view', 'audit' => 'view', 'debug' => 'view', 'sessions' => 'view', 'status' => 'view'],
		];
	}

	// ------------------------------------------------------------------ the current user

	private static ?array $mine = null;
	private static ?string $mineFor = null;

	/** The role of the signed-in user: ['id', 'code', 'name', 'rights'] or null (none / globeadmin / acting as another user). */
	public static function role(): ?array
	{
		$u = NuAuth::user();
		if (!$u || $u['globeadmin'] || !empty($u['demo'])) return null;
		$id = (string)$u['id'];
		if (self::$mineFor === $id) return self::$mine;
		self::$mineFor = $id;
		return self::$mine = self::roleOfUser($id);
	}

	/** Role of a user (zzzzsys_user_id) or null. */
	public static function roleOfUser(string $userId): ?array
	{
		if ($userId === '' || !self::ready()) return null;
		try {
			$r = nu_db()->one('SELECT r.* FROM ' . self::USER_TABLE . ' a JOIN ' . self::ROLE_TABLE . ' r ON r.' . self::ROLE_TABLE . '_id = a.role_id WHERE a.'
				. self::USER_TABLE . '_id = ?', [$userId]);
		} catch (Throwable) {
			return null;
		}
		return $r ? self::row($r) : null;
	}

	/** Has the signed-in user the right (globeadmin: always)? $level 'view' or 'change' ('change' includes 'view'). */
	public static function can(string $right, string $level = 'view'): bool
	{
		$u = NuAuth::user();
		if (!$u) return false;
		if ($u['globeadmin']) return true;
		$r = self::role();
		if (!$r || NuImpersonate::active()) return false;
		$have = $r['rights'][$right] ?? '';
		return $have === 'change' || ($have === 'view' && $level === 'view');
	}

	/** True on a page the user may only view (admin role with "view"): buttons that change something are switched off (nuapp.js). */
	public static bool $viewOnly = false;

	/** 403 unless the right is there; without $level: 'change' for a POST, 'view' otherwise. */
	public static function require(string $right, ?string $level = null): void
	{
		if (self::can($right, $level ?? (nu_is_post() ? 'change' : 'view'))) {
			if ($level === null && !nu_is_post() && !self::can($right, 'change')) self::$viewOnly = true;
			return;
		}
		http_response_code(403);
		nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true);
	}

	/** Globeadmin or a user with any admin right – sees the admin start page, the search (Ctrl+K). */
	public static function any(): bool
	{
		$u = NuAuth::user();
		if (!$u) return false;
		if ($u['globeadmin']) return true;
		$r = self::role();
		return $r !== null && $r['rights'] !== [] && !NuImpersonate::active();
	}

	/** Rights on a nuBuilder system form granted by the role: '' / 'view' / 'change'. */
	public static function formLevel(string $formId): string
	{
		$r = self::role();
		if (!$r || NuImpersonate::active()) return '';
		$best = '';
		foreach (self::RIGHTS as $k => $def) {
			if (!in_array($formId, $def[4], true)) continue;
			$have = $r['rights'][$k] ?? '';
			if ($have === 'change') return 'change';
			if ($have === 'view') $best = 'view';
		}
		return $best;
	}

	// ------------------------------------------------------------------ roles

	public static function ready(): bool
	{
		static $ok = null;
		if ($ok !== null) return $ok;
		try {
			$db = nu_db();
			return $ok = $db->tableExists(self::ROLE_TABLE) && $db->tableExists(self::USER_TABLE);
		} catch (Throwable) {
			return $ok = false;
		}
	}

	private static function row(array $r): array
	{
		$rights = json_decode((string)($r['rights'] ?? ''), true);
		$clean = [];
		foreach (is_array($rights) ? $rights : [] as $k => $v) {
			if (!isset(self::RIGHTS[$k]) || !in_array($v, ['view', 'change'], true)) continue;
			$clean[$k] = self::RIGHTS[$k][3] === 'c' ? 'change' : $v;   // one-level rights are either there or not
		}
		$code = (string)($r['code'] ?? '');
		$name = trim((string)($r['name'] ?? ''));
		return ['id' => (string)$r[self::ROLE_TABLE . '_id'], 'code' => $code, 'name' => $name !== '' ? $name : t('adm_role_' . $code),
			'stored_name' => $name, 'description' => (string)($r['description'] ?? ''), 'rights' => $clean];
	}

	/** All roles with the number of users. */
	public static function roles(): array
	{
		if (!self::ready()) return [];
		$db = nu_db();
		$count = [];
		foreach ($db->all('SELECT role_id, COUNT(*) AS n FROM ' . self::USER_TABLE . ' GROUP BY role_id') as $c) $count[(string)$c['role_id']] = (int)$c['n'];
		$out = [];
		foreach ($db->all('SELECT * FROM ' . self::ROLE_TABLE . ' ORDER BY sort_order, code') as $r) {
			$x = self::row($r);
			$x['users'] = $count[$x['id']] ?? 0;
			$out[] = $x;
		}
		return $out;
	}

	public static function get(string $id): ?array
	{
		if (!self::ready() || $id === '') return null;
		$r = nu_db()->one('SELECT * FROM ' . self::ROLE_TABLE . ' WHERE ' . self::ROLE_TABLE . '_id = ?', [$id]);
		return $r ? self::row($r) : null;
	}

	/** Users with this role: [id, login, name]. */
	public static function usersOf(string $roleId): array
	{
		if (!self::ready()) return [];
		$db = nu_db();
		return $db->all('SELECT u.zzzzsys_user_id AS id, u.sus_login_name AS login, u.sus_name AS name FROM ' . self::USER_TABLE . ' a JOIN zzzzsys_user u ON '
			. $db->sameText('u.zzzzsys_user_id', 'a.' . self::USER_TABLE . '_id') . ' WHERE a.role_id = ? ORDER BY u.sus_login_name', [$roleId]);
	}

	/** Does the role give a right of full access? */
	public static function hasFull(array $rights): bool
	{
		foreach ($rights as $k => $_) if (self::RIGHTS[$k][2] ?? false) return true;
		return false;
	}

	/** May the signed-in user manage roles and assignments? */
	public static function canManage(): bool
	{
		return self::can('adminroles', 'change');
	}

	/** Rights the signed-in user may give (globeadmin: all; others: at most their own). */
	public static function grantable(): array
	{
		$u = NuAuth::user();
		if ($u && $u['globeadmin']) return array_map(static fn() => 'change', self::RIGHTS);
		return self::role()['rights'] ?? [];
	}

	/** Rights of $wanted that exceed what the signed-in user may give. */
	public static function overGrant(array $wanted): array
	{
		$g = self::grantable();
		$bad = [];
		foreach ($wanted as $k => $lvl) {
			$have = $g[$k] ?? '';
			if ($have === 'change' || ($have === 'view' && $lvl === 'view')) continue;
			$bad[] = $k;
		}
		return $bad;
	}

	/**
	 * Saves a role. $in: code, name, description, rights (right => view|change). @return array{0: ?string, 1: array} [id, errors]
	 */
	public static function save(?string $id, array $in): array
	{
		self::ensure();
		$db = nu_db();
		$errors = [];
		$code = trim((string)($in['code'] ?? ''));
		if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $code)) $errors['code'] = t('adm_err_code');
		elseif ((int)$db->value('SELECT COUNT(*) FROM ' . self::ROLE_TABLE . ' WHERE code = ? AND ' . self::ROLE_TABLE . '_id <> ?', [$code, (string)$id])) $errors['code'] = t('adm_err_code_taken');
		$rights = [];
		foreach ((array)($in['rights'] ?? []) as $k => $v) if (isset(self::RIGHTS[$k]) && in_array($v, ['view', 'change'], true)) $rights[$k] = self::RIGHTS[$k][3] === 'c' ? 'change' : $v;
		$old = $id !== null ? self::get($id) : null;
		if ($id !== null && !$old) $errors['code'] = t('err_not_found');
		// nobody raises rights beyond his own – also not by editing a role (only the changed rights count)
		$changed = $rights;
		if ($old) foreach ($changed as $k => $v) if (($old['rights'][$k] ?? '') === $v) unset($changed[$k]);
		if ($bad = self::overGrant($changed)) $errors['rights'] = t('adm_err_over', implode(', ', array_map(static fn($k) => t(self::RIGHTS[$k][1]), $bad)));
		$mine = self::role();
		if ($mine && $old && $mine['id'] === $old['id']) $errors['rights'] = t('adm_err_own_role');
		if ($errors) return [null, $errors];
		$row = ['code' => $code, 'name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 100), 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 1000),
			'rights' => json_encode($rights), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($old) {
			$db->update(self::ROLE_TABLE, $row, self::ROLE_TABLE . '_id', $id);
			NuLog::write('admin_role_saved', self::ROLE_TABLE, $id, ['code' => $code, 'rights' => NuLog::diff($old['rights'], $rights)]);
		} else {
			$id = nu_id();
			$db->insert(self::ROLE_TABLE, [self::ROLE_TABLE . '_id' => $id, 'sort_order' => 100] + $row);
			NuLog::write('admin_role_created', self::ROLE_TABLE, $id, ['code' => $code, 'rights' => $rights]);
		}
		return [$id, []];
	}

	/** Deletes a role without users. @return string error ('' = deleted) */
	public static function delete(string $id): string
	{
		$r = self::get($id);
		if (!$r) return t('err_not_found');
		if (self::usersOf($id)) return t('adm_err_in_use');
		$mine = self::role();
		if ($mine && $mine['id'] === $id) return t('adm_err_own_role');
		if (self::overGrant($r['rights'])) return t('adm_err_over', '');
		nu_db()->delete(self::ROLE_TABLE, self::ROLE_TABLE . '_id', $id);
		NuLog::write('admin_role_deleted', self::ROLE_TABLE, $id, ['code' => $r['code']]);
		return '';
	}

	/** Gives a user a role ('' = none). @return string error ('' = done) */
	public static function assign(string $userId, string $roleId): string
	{
		self::ensure();
		$u = NuAuth::user();
		if (!self::canManage()) return t('err_forbidden');
		if ($u && !$u['globeadmin'] && (string)$u['id'] === $userId) return t('adm_err_own_role');
		$old = self::roleOfUser($userId);
		if (($old['id'] ?? '') === $roleId) return '';
		$new = $roleId !== '' ? self::get($roleId) : null;
		if ($roleId !== '' && !$new) return t('err_not_found');
		// neither the new nor the old role may hold more than the assigning user has (taking a role away counts too)
		foreach ([$new, $old] as $r) if ($r && self::overGrant($r['rights'])) return t('adm_err_over', $r['name']);
		$db = nu_db();
		$db->run('DELETE FROM ' . self::USER_TABLE . ' WHERE ' . self::USER_TABLE . '_id = ?', [$userId]);
		if ($new) $db->insert(self::USER_TABLE, [self::USER_TABLE . '_id' => $userId, 'role_id' => $roleId, 'updated_at' => date('Y-m-d H:i:s'),
			'updated_by' => mb_substr((string)($u['login'] ?? ''), 0, 50)]);
		NuLog::write('admin_role_assigned', 'zzzzsys_user', $userId, ['from' => $old['code'] ?? '', 'to' => $new['code'] ?? '']);
		return '';
	}

	/** Creates the tables (NuSchema) and the default roles when there are none. */
	public static function ensure(): void
	{
		NuSchema::create();
		self::seed();
	}

	public static function seed(): void
	{
		$db = nu_db();
		if (!$db->tableExists(self::ROLE_TABLE) || (int)$db->value('SELECT COUNT(*) FROM ' . self::ROLE_TABLE) > 0) return;
		$i = 0;
		foreach (self::defaults() as $code => $rights) {
			$db->insert(self::ROLE_TABLE, [self::ROLE_TABLE . '_id' => nu_id(), 'code' => $code, 'name' => '', 'description' => '', 'rights' => json_encode($rights),
				'sort_order' => ++$i * 10, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => 'nunext']);
		}
	}
}
