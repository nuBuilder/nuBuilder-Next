<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "adminroles" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListAdminRoles
{
	/** Admin roles (was a hand-written table): name, code, rights (n / all, "full access"), users (→ the users list filtered by the role). */
	private static function adminRoles(): array
	{
		$db = nu_db();
		$R = 'zzzzsys_nunext_admin_role';
		$nUsers = '(SELECT COUNT(*) FROM zzzzsys_nunext_admin_user au WHERE au.role_id = r.zzzzsys_nunext_admin_role_id)';
		$mine = (string)(NuAdmin::role()['id'] ?? '');
		$roleOf = static fn(array $r): array => NuAdmin::get((string)$r['__id']) ?? ['name' => (string)$r['adm_name'], 'rights' => []];
		$all = count(NuAdmin::RIGHTS);
		$canTest = NuImpersonate::canTest();                 // test the role as a user: row button → the role form with its dialogue open
		$cols = [
			'test' => ['label' => '', 'select' => ['adm_rights_t' => 'r.rights'], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => $canTest && ($mine === '' || $mine !== (string)$r['__id']) && NuImpersonate::canTest($roleOf($r) + ['id' => (string)$r['__id']])
					? '<a class="btn btn-sm btn-outline-secondary nuAdmTestRow" href="' . h(nu_url('adminroles', ['id' => $r['__id'], 'test' => '1'])) . '" title="' . h(t('adm_test')) . '" aria-label="' . h(t('adm_test') . ': ' . (string)$roleOf($r)['name']) . '"><i class="bi bi-person-fill-gear"></i></a>' : ''],
			'name' => ['label' => t('adm_role'), 'main' => true, 'locked' => true, 'select' => ['adm_name' => 'r.name', 'adm_code2' => 'r.code'], 'sort' => 'r.sort_order, r.name',
				'text' => static fn($r) => (string)$roleOf($r)['name'],
				'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$roleOf($r)['name']) . '</span>' . ($mine !== '' && $mine === (string)$r['__id'] ? ' <span class="badge text-bg-secondary ms-1">' . h(t('adm_yours')) . '</span>' : '')],
			'code' => ['label' => t('adm_code'), 'select' => ['adm_code' => 'r.code'], 'sort' => 'r.code', 'class' => 'small', 'text' => static fn($r) => (string)$r['adm_code'],
				'html' => static fn($r) => '<code>' . h((string)$r['adm_code']) . '</code>'],
			'description' => self::col('adm_desc', 'r.description', t('adm_description'), 'small text-body-secondary'),
			'rights' => ['label' => t('adm_rights'), 'select' => ['adm_rights' => 'r.rights'], 'sort' => null,
				'text' => static fn($r) => count($roleOf($r)['rights']) . ' / ' . $all,
				'html' => static fn($r) => h(count($roleOf($r)['rights']) . ' / ' . $all) . (NuAdmin::hasFull($roleOf($r)['rights']) ? ' <span class="badge text-bg-danger ms-1 nuAdmFull" title="' . h(t('adm_full_tip')) . '">' . h(t('adm_full')) . '</span>' : '')],
			'users' => ['label' => t('nav_users'), 'select' => ['adm_n' => $nUsers], 'sort' => $nUsers, 'align' => 'end', 'text' => static fn($r) => (string)(int)$r['adm_n'],
				'html' => static fn($r) => (int)$r['adm_n'] > 0 && NuAcl::can(NuAcl::FORM_USER)   // → the users list filtered by the role
					? '<a class="text-decoration-none" href="' . h(nu_url('users', ['role' => $r['__id'], 'state' => 'all'])) . '">' . (int)$r['adm_n'] . '</a>' : (string)(int)$r['adm_n']],
			'updated' => ['label' => t('fi_changed'), 'select' => ['adm_upd' => 'r.updated_at'], 'sort' => 'r.updated_at', 'class' => 'small text-nowrap', 'text' => static fn($r) => self::date($r['adm_upd'] ?? null, true)],
			'id' => self::idCol('r.zzzzsys_nunext_admin_role_id'),
		];
		$filters = [
			'has_users' => ['label' => t('lv_has_users'), 'type' => 'bool', 'expr' => 'EXISTS (SELECT 1 FROM zzzzsys_nunext_admin_user au WHERE au.role_id = r.zzzzsys_nunext_admin_role_id)'],
		];
		$base = ['columns' => ['test', 'name', 'code', 'rights', 'users'], 'card' => ['title' => 'name', 'badge' => '', 'lines' => ['code', 'rights', 'users']],
			'sort' => ['name', 'asc'], 'per' => 50, 'filters' => ['shown' => ['has_users'], 'values' => []]];
		return [
			'key' => 'adminroles', 'title' => t('adm_title'), 'route' => 'adminroles', 'new_url' => nu_url('adminroles', ['id' => 'new']),
				'empty_icon' => 'bi-person-gear', 'form' => NuAcl::FORM_ADMINROLE, 'card_count' => 'users', 'card_join' => true,
			'from' => " FROM $R r", 'where' => [], 'params' => [], 'id' => 'r.zzzzsys_nunext_admin_role_id',
			'link' => static fn($r) => nu_url('adminroles', ['id' => $r['__id']]),
			'search' => ['r.name', 'r.code', 'r.description'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['test', 'name', 'users']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
