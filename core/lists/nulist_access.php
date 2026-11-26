<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "access" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListAccess
{
	/** The Home form the level's users really open after the sign-in ('' = the tile page): start page "Home form" and a form that exists. */
	private static function accStartForm(array $r): string
	{
		$f = (string)($r['sal_zzzzsys_form_id'] ?? '');
		if ($f === '' || NuHome::startMode((string)$r['__id']) !== 'form' || !nu_form_exists($f)) return '';
		return $f;
	}

	private static function access(): array
	{
		$db = nu_db();
		$nUsers = '(SELECT COUNT(*) FROM zzzzsys_user u WHERE u.sus_zzzzsys_access_id = a.zzzzsys_access_id)';
		$nForms = '(SELECT COUNT(*) FROM zzzzsys_access_form f WHERE f.slf_zzzzsys_access_id = a.zzzzsys_access_id)';
		$nProc = $db->tableExists('zzzzsys_access_php') ? '(SELECT COUNT(*) FROM zzzzsys_access_php p WHERE p.slp_zzzzsys_access_id = a.zzzzsys_access_id)' : '0';
		$nRep = $db->tableExists('zzzzsys_access_report') ? '(SELECT COUNT(*) FROM zzzzsys_access_report r WHERE r.sre_zzzzsys_access_id = a.zzzzsys_access_id)' : '0';
		$num = static fn(string $alias, string $label, string $sql) => ['label' => $label, 'select' => [$alias => $sql], 'sort' => $sql, 'align' => 'end',
			'text' => static fn($r) => (string)(int)$r[$alias]];
		$cols = [
			// no clone button per row (the owner: rarely needed – Clone stays on the access level form)
			'code' => ['label' => t('access_code'), 'main' => true, 'locked' => true, 'select' => ['sal_code' => 'a.sal_code', 'sal_use_2fa' => 'a.sal_use_2fa'], 'sort' => 'a.sal_code',
				'text' => static fn($r) => (string)$r['sal_code'],
				'html' => static fn($r) => h($r['sal_code'] !== '' ? $r['sal_code'] : '—') . ((string)$r['sal_use_2fa'] === '1' ? ' <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1 fw-normal">2FA</span>' : '')],
			'group' => ['label' => t('access_group'), 'select' => ['sal_group' => 'a.sal_group'], 'sort' => 'a.sal_group', 'text' => static fn($r) => (string)$r['sal_group']],
			'description' => ['label' => t('access_description'), 'select' => ['sal_description' => 'a.sal_description'], 'sort' => 'a.sal_description', 'text' => static fn($r) => (string)$r['sal_description']],
			// what the users of the level see after the sign-in: the tile page (own / default / built automatically) or the Home form
			'start' => ['label' => t('acc_start_page'), 'select' => ['sal_zzzzsys_form_id' => 'a.sal_zzzzsys_form_id'], 'sort' => null,
				'text' => static fn($r) => self::accStartForm($r) !== '' ? t('acc_start_form_chip') . ': ' . nu_form_label(self::accStartForm($r))
					: t('acc_start_tiles_chip') . ' – ' . t('acc_start_src_' . (NuHomeForms::tileSources()[(string)$r['__id']] ?? 'auto')),
				'html' => static function ($r): string {
					$form = self::accStartForm($r);
					if ($form !== '') {
						return '<a class="nuAccStart" href="' . h(nu_url('form', ['f' => $form])) . '" target="_blank" rel="noopener" title="' . h(t('acc_home_open')) . '">'
							. '<span class="nuAccStartChip nuAccStartForm"><i class="bi bi-window" aria-hidden="true"></i>' . h(t('acc_start_form_chip')) . '</span>'
							. '<span class="nuAccStartName">' . h(nu_form_label($form)) . '</span></a>';
					}
					$src = NuHomeForms::tileSources()[(string)$r['__id']] ?? 'auto';
					$unused = (string)$r['sal_zzzzsys_form_id'] !== '' ? ' title="' . h(t('acc_start_form_unused', nu_form_label((string)$r['sal_zzzzsys_form_id']))) . '"' : '';
					$tag = NuHome::canManage() ? 'a class="nuAccStart" href="' . h(nu_url('home', ['for' => $r['__id']])) . '"' : 'span class="nuAccStart"';
					return '<' . $tag . $unused . '><span class="nuAccStartChip nuAccStartTiles"><i class="bi bi-grid" aria-hidden="true"></i>' . h(t('acc_start_tiles_chip')) . '</span>'
						. '<span class="nuAccStartSrc">' . h(t('acc_start_src_' . $src)) . '</span></' . (NuHome::canManage() ? 'a' : 'span') . '>';
				}],
			'home_form' => ['label' => t('home_form'), 'select' => ['sal_zzzzsys_form_id' => 'a.sal_zzzzsys_form_id'], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => $r['sal_zzzzsys_form_id'] ? nu_form_label((string)$r['sal_zzzzsys_form_id']) : '',
				// the home form opens in a new tab (as the access level form's ↗ button)
				'html' => static fn($r) => (string)$r['sal_zzzzsys_form_id'] === '' ? ''
					: '<a class="nuAccHomeLink" href="' . h(nu_url('form', ['f' => (string)$r['sal_zzzzsys_form_id']])) . '" target="_blank" rel="noopener" title="' . h(t('acc_home_open')) . '">'
						. h(nu_form_label((string)$r['sal_zzzzsys_form_id'])) . ' <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i></a>'],
			'users' => $num('n_users', t('nav_users'), $nUsers) + ['html' => static fn($r) => (int)$r['n_users'] > 0 && NuAcl::can(NuAcl::FORM_USER)
				? '<a class="text-decoration-none" href="' . h(nu_url('users', ['access' => $r['__id'], 'state' => 'all'])) . '">' . (int)$r['n_users'] . '</a>' : (string)(int)$r['n_users']],
			// the counts open the access level on the matching tab
			'forms' => $num('n_forms', t('access_forms'), $nForms) + ['html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('accesslevel', ['id' => $r['__id'], 'tab' => 'rights'])) . '">' . (int)$r['n_forms'] . '</a>'],
			'procedures' => $num('n_proc', t('proc_title'), $nProc) + ['html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('accesslevel', ['id' => $r['__id']])) . '#accProcRights">' . (int)$r['n_proc'] . '</a>'],
			'reports' => $num('n_rep', t('rpt_title_list'), $nRep) + ['html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('accesslevel', ['id' => $r['__id']])) . '#accProcRights">' . (int)$r['n_rep'] . '</a>'],
			'twofa' => ['label' => '2FA', 'select' => ['sal_use_2fa' => 'a.sal_use_2fa'], 'sort' => 'a.sal_use_2fa', 'align' => 'center',
				'text' => static fn($r) => (string)$r['sal_use_2fa'] === '1' ? t('yes') : '',
				'html' => static fn($r) => (string)$r['sal_use_2fa'] === '1' ? '<i class="bi bi-shield-check text-success"></i>' : ''],
			'id' => ['label' => 'ID', 'select' => ['zzzzsys_access_id' => 'a.zzzzsys_access_id'], 'sort' => 'a.zzzzsys_access_id', 'class' => 'small text-body-secondary',
				'text' => static fn($r) => (string)$r['zzzzsys_access_id']],
		];
		$filters = [
			'group' => ['label' => t('access_group'), 'type' => 'distinct', 'expr' => 'a.sal_group', 'none' => t('lv_empty')],
			'twofa' => ['label' => '2FA', 'type' => 'bool', 'expr' => "a.sal_use_2fa = '1'"],
			'start' => ['label' => t('acc_start_page'), 'type' => 'select',
				'options' => static fn() => ['tiles' => t('acc_start_tiles'), 'form' => t('acc_start_form')],
				'where' => static function (string $v): ?array {
					$ids = array_keys(array_filter(NuHome::startLevels(), static fn($m) => $m === 'form'));
					$in = $ids ? 'a.zzzzsys_access_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ") AND COALESCE(a.sal_zzzzsys_form_id, '') <> ''" : '1 = 0';
					return match ($v) { 'form' => [$in, $ids], 'tiles' => ['NOT (' . $in . ')', $ids], default => null };
				}],
			'has_users' => ['label' => t('lv_has_users'), 'type' => 'bool', 'expr' => 'EXISTS (SELECT 1 FROM zzzzsys_user u WHERE u.sus_zzzzsys_access_id = a.zzzzsys_access_id)'],
			// the levels that can open a form
			'form' => ['label' => t('access_flt_form'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($db): array {
					$o = [];
					foreach ($db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE COALESCE(sfo_type, '') <> 'subform' ORDER BY sfo_code") as $f) $o[(string)$f['zzzzsys_form_id']] = [(string)$f['sfo_code'],
						(string)$f['sfo_description']];
					return $o;
				},
				'where' => static fn(string $v) => ['EXISTS (SELECT 1 FROM zzzzsys_access_form af WHERE af.slf_zzzzsys_access_id = a.zzzzsys_access_id AND af.slf_zzzzsys_form_id = ?)', [$v]]],
		];
		return [
			'key' => 'access', 'title' => t('nav_access'), 'route' => 'access', 'form' => NuAcl::FORM_ACCESS,
			'from' => ' FROM zzzzsys_access a', 'where' => [], 'params' => [],
			'id' => 'a.zzzzsys_access_id', 'link' => static fn($r) => nu_url('accesslevel', ['id' => $r['__id']]),
			'search' => ['a.sal_code', 'a.sal_description', 'a.sal_group'], 'search_ph' => t('access_search_ph'), 'open_min' => 0,
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['code', 'group', 'description', 'start', 'users', 'forms'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'start', 'users']],
					'sort' => ['code', 'asc'], 'filters' => ['shown' => ['group', 'start', 'form'], 'values' => []]],
				'compact' => ['columns' => ['code', 'description', 'users'], 'card' => ['title' => 'code', 'badge' => '', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'filters' => ['shown' => ['group'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'start', 'users', 'forms', 'home_form']],
					'sort' => ['code', 'asc'], 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}
}
