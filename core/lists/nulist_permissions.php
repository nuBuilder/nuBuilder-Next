<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "permissions" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListPermissions
{
	private static function permissions(): array
	{
		$U = NuPermission::USER;
		$nUsers = "(SELECT COUNT(DISTINCT p.upe_zzzzsys_user_id) FROM $U p WHERE p.upe_ident = i.zzzzsys_permission_item_id AND p.upe_active = '1')";
		$cols = [
			'ident' => self::col('pme_ident', 'i.pme_ident', t('perm_ident'), 'font-monospace text-break') + ['main' => true, 'locked' => true],
			'group' => array_replace(self::badgeCol('pme_group', 'i.pme_group', t('perm_group')), ['sort' => 'i.pme_group, i.pme_ident']),
			'description' => self::col('pme_description', 'i.pme_description', t('perm_description'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['pme_description'], 0, 120, '…'))],
			'users' => self::col('n_users', $nUsers, t('perm_users')) + ['align' => 'end',   // the count opens the users list filtered by the item
				'html' => static fn($r) => (int)$r['n_users'] > 0 && NuAcl::can(NuAcl::FORM_USER)
					? '<a class="text-decoration-none" href="' . h(nu_url('users', ['perm' => $r['__id'], 'state' => 'all'])) . '">' . (int)$r['n_users'] . '</a>' : (string)(int)$r['n_users']],
			'id' => self::idCol('i.zzzzsys_permission_item_id'),
		];
		// type of the item (own table zzzzsys_nunext_permission_type – plain when it has no row)
		$filters = ['group' => ['label' => t('perm_group'), 'type' => 'distinct', 'expr' => 'i.pme_group', 'none' => t('lv_empty')],
			'has_users' => ['label' => t('lv_has_users'), 'type' => 'bool', 'expr' => "EXISTS (SELECT 1 FROM $U p WHERE p.upe_ident = i.zzzzsys_permission_item_id AND p.upe_active = '1')"]];
		{   // always offered: before the table exists every item is plain
			$ty = nu_db()->tableExists(NuPermType::TABLE)
				? "COALESCE((SELECT pt.type FROM " . NuPermType::TABLE . ' pt WHERE '
					. nu_db()->sameText('pt.zzzzsys_nunext_permission_type_id', 'i.zzzzsys_permission_item_id')
					. "),'plain')"   // no ", ": NuListView splits a sort on it
				: "'plain'";
			$cols = array_slice($cols, 0, 2, true) + ['type' => ['label' => t('ptype_title'), 'select' => ['ptype' => $ty], 'sort' => $ty,
				'text' => static fn($r) => t(NuPermType::TYPES[(string)$r['ptype']][0] ?? 'ptype_plain'),
				'html' => static function ($r): string {
					$x = NuPermType::of((string)$r['__id']);
					[$lab, $ico] = NuPermType::TYPES[$x['type']] ?? NuPermType::TYPES['plain'];
					$sum = NuPermType::summary($x['type'], $x['settings']);
					return '<span class="badge ' . ($x['type'] === 'plain' ? 'text-bg-light border' : 'text-bg-primary') . ' fw-normal"'
						. ($sum !== '' ? ' title="' . h($sum) . '"' : '') . '><i class="bi ' . h($ico) . ' me-1" aria-hidden="true"></i>' . h(t($lab)) . '</span>';
				}]] + array_slice($cols, 2, null, true);
			$filters['ptype'] = ['label' => t('ptype_title'), 'type' => 'select', 'options' => static fn(): array => array_map(static fn($x) => t($x[0]), NuPermType::TYPES),
				'where' => static fn(string $v) => isset(NuPermType::TYPES[$v]) ? ["$ty = ?", [$v]] : null];
		}
		return [
			'key' => 'permissions', 'title' => t('perm_title'), 'route' => 'permissions', 'form' => NuAcl::FORM_PERMISSION,
			'from' => ' FROM ' . NuPermission::ITEM . ' i', 'where' => [], 'params' => [], 'id' => 'i.zzzzsys_permission_item_id',
			'link' => static fn($r) => nu_url('permissions', ['id' => $r['__id']]), 'search' => ['i.pme_ident', 'i.pme_group', 'i.pme_description'], 'columns' => $cols,
			'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => array_values(array_intersect(['ident', 'group', 'type', 'description', 'users'], array_keys($cols))),
					'card' => ['title' => 'ident', 'badge' => 'group', 'lines' => ['description', 'users']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => array_values(array_intersect(['group', 'ptype'], array_keys($filters))), 'values' => []]],   // type filter
				'compact' => ['columns' => ['ident', 'users'], 'card' => ['title' => 'ident', 'badge' => 'group', 'lines' => ['users']],
					'sort' => ['ident', 'asc'], 'per' => 50, 'filters' => ['shown' => array_values(array_intersect(['group', 'ptype'], array_keys($filters))), 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'ident', 'badge' => 'group', 'lines' => ['description', 'users']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}
}
