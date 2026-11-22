<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "procedures" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListProcedures
{
	private static function procedures(): array
	{
		$nAccess = '(SELECT COUNT(*) FROM zzzzsys_access_php a WHERE a.slp_zzzzsys_php_id = p.zzzzsys_php_id)';
		$statusText = static fn($s) => t(NuProcedure::STATUS[(string)($s ?? '1') ?: '1'] ?? 'proc_status_active');
		$cols = [
			'code' => array_replace(self::col('sph_code', 'p.sph_code', t('proc_code')),
				['main' => true, 'locked' => true, 'select' => ['sph_code' => 'p.sph_code', 'sph_template' => 'p.sph_template'],   // array_replace: + kept col()'s select
				'html' => static fn($r) => h($r['sph_code']) . ((string)$r['sph_template'] === '1' ? ' <span class="badge text-bg-light border ms-1 fw-normal">' . h(t('proc_template')) . '</span>' : '')]),
			'group' => array_replace(self::badgeCol('sph_group', 'p.sph_group', t('proc_group')), ['sort' => 'p.sph_group, p.sph_code']),
			'category' => self::col('sph_category', 'p.sph_category', t('proc_category'), 'small'),
			'status' => ['label' => t('proc_status'), 'select' => ['sph_status' => 'p.sph_status'], 'sort' => 'p.sph_status', 'text' => static fn($r) => $statusText($r['sph_status']),
				'html' => static fn($r) => self::badge($statusText($r['sph_status']), match ((string)($r['sph_status'] ?? '1')) {
					'0' => 'bg-warning-subtle text-warning-emphasis', '3' => 'bg-secondary-subtle text-secondary-emphasis', default => 'bg-success-subtle text-success-emphasis' })],
			'description' => self::col('sph_description', 'p.sph_description', t('proc_description'), 'small text-break'),
			'run' => ['label' => t('proc_run'), 'select' => ['sph_run' => 'p.sph_run'], 'sort' => 'p.sph_run', 'class' => 'small',
				'text' => static fn($r) => t(NuProcedure::RUN[(string)$r['sph_run']] ?? 'proc_run_default')],
			'launch' => self::col('sfo_code', 'f.sfo_code', t('proc_launch'), 'small'),
			'access' => ['label' => t('proc_access'), 'select' => ['sph_global' => 'p.sph_global', 'n_access' => $nAccess], 'sort' => $nAccess, 'align' => 'end', 'class' => 'small',
				'text' => static fn($r) => (string)$r['sph_global'] === '1' ? t('proc_everyone') : (string)(int)$r['n_access'],
				// opens the procedure's tab Access levels
				'html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('procedures', ['id' => $r['__id']])) . '#access">'
					. ((string)$r['sph_global'] === '1' ? self::badge(t('proc_everyone'), 'bg-info-subtle text-info-emphasis') : (string)(int)$r['n_access']) . '</a>'],
			// where it is used (NuProcUse: buttons, objects, reports, tiles, code) – worked out only when the column is shown
			'used' => ['label' => t('prc_use_col'), 'select' => [], 'class' => 'text-nowrap', 'sort' => static fn(): string => self::procUseSort(),
				'text' => static fn($r) => self::procUseText(NuProcUse::of((string)$r['__id'])),
				'html' => static fn($r) => self::procUseHtml(NuProcUse::of((string)$r['__id']))],
			'id' => self::idCol('p.zzzzsys_php_id'),
		];
		$filters = [
			'origin' => ['label' => t('lv_origin'), 'type' => 'select', 'options' => static fn() => ['own' => t('lv_origin_own'), 'system' => t('lv_origin_sys')],   // system rows hidden by default
				'where' => static fn(string $v) => match ($v) { 'own' => ["p.zzzzsys_php_id NOT LIKE 'nu%' AND p.zzzzsys_php_id NOT LIKE 'nx\\_%'", []], 'system' => ["(p.zzzzsys_php_id LIKE 'nu%' OR p.zzzzsys_php_id LIKE 'nx\\_%')", []], default => null }],
			'group' => ['label' => t('proc_group'), 'type' => 'distinct', 'expr' => 'p.sph_group', 'none' => t('lv_empty')],
			'status' => ['label' => t('proc_status'), 'type' => 'select', 'options' => static fn() => array_map('t', NuProcedure::STATUS),
				'where' => static fn(string $v) => ["COALESCE(NULLIF(p.sph_status, ''), '1') = ?", [$v]]],
			'category' => ['label' => t('proc_category'), 'type' => 'distinct', 'expr' => 'p.sph_category', 'none' => t('lv_empty')],
			'used' => ['label' => t('prc_use_col'), 'type' => 'select',
				'options' => static fn() => ['used' => t('prc_use_used'), 'unused' => t('prc_use_unused'), 'hook' => t('prc_use_hook')],
				'where' => static function (string $v) {
					if (!in_array($v, ['used', 'unused', 'hook'], true)) return null;
					$ids = NuProcUse::ids($v);
					return $ids ? ['p.zzzzsys_php_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids] : ['1 = 0', []];
				}],
			'template' => ['label' => t('proc_template'), 'type' => 'bool', 'expr' => "p.sph_template = '1'"],
			'global' => ['label' => t('proc_everyone'), 'type' => 'bool', 'expr' => "p.sph_global = '1'"],
			'run' => ['label' => t('proc_run'), 'type' => 'select',
				'options' => static fn() => ['default' => t('proc_run_default'), 'hide' => t('proc_run_hidden'), 'window' => t('proc_run_window')],
				// how it runs
				'where' => static fn(string $v) => match ($v) {
					'default' => ["COALESCE(p.sph_run, '') NOT IN ('hide', 'window')", []],
					'hide', 'window' => ['p.sph_run = ?', [$v]],
					default => null,
				}],
		];
		if ($pf = NuProject::listFilter('procedure', 'p.zzzzsys_php_id')) $filters['project'] = $pf;   // projects
		$cols['opened'] = NuOpened::column('procedure', 'p.zzzzsys_php_id');
		return [
			'key' => 'procedures', 'title' => t('proc_title'), 'route' => 'procedures', 'form' => NuAcl::FORM_PROCEDURE,
			'from' => ' FROM ' . NuProcedure::TABLE . ' p LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = p.sph_zzzzsys_form_id', 'where' => [NuProcedure::listCondition('p')], 'params' => [],
			'id' => 'p.zzzzsys_php_id', 'link' => static fn($r) => nu_url('procedures', ['id' => $r['__id']]),
			'search' => ['p.sph_code', 'p.sph_description', 'p.sph_group', 'p.sph_category', 'f.sfo_code'], 'columns' => $cols, 'filters' => $filters,
			'search_extra' => ['param' => 'qc', 'cols' => ['p.sph_php', 'p.sph_javascript'], 'label' => t('lv_search_code'), 'short' => t('lv_search_code_short'), 'icon' => 'bi-code-slash'],
			'builtin' => [
				'default' => ['columns' => ['code', 'group', 'category', 'status', 'description', 'run', 'launch', 'access', 'used'],
					'card' => ['title' => 'code', 'badge' => 'status', 'lines' => ['description', 'group']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['origin', 'group', 'status', 'category', 'run', 'used'], 'values' => ['origin' => 'own']]],   // category + run
				'compact' => ['columns' => ['code', 'description', 'status'], 'card' => ['title' => 'code', 'badge' => 'status', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
				'recent' => ['columns' => ['code', 'description', 'group', 'status', 'opened'], 'card' => ['title' => 'code', 'badge' => 'status', 'lines' => ['description', 'opened']],
					'sort' => ['opened', 'desc'], 'per' => 50, 'filters' => ['shown' => ['origin', 'group'], 'values' => []]],   // Recently opened: system procedures too
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'status', 'lines' => ['description', 'group', 'launch', 'access']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}

	/** The "Used in" cell as text (exports). */
	private static function procUseText(array $u): string
	{
		if ($u['hook'] !== '') return t('prc_use_hook_' . $u['hook']);   // prc_use_hook_form, prc_use_hook_global
		if (!$u['places']) return '';
		return $u['forms'] ? t('prc_use_forms_n', count($u['forms'])) : t('prc_use_places_n', count($u['places']));
	}

	/** The "Used in" cell: number of forms (≈ when code calls count), a menu with every place. */
	private static function procUseHtml(array $u): string
	{
		if ($u['hook'] !== '') return self::badge(t('prc_use_hook_' . $u['hook']), 'bg-info-subtle text-info-emphasis');
		if (!$u['places']) return '<span class="text-body-secondary nuProcUnused" title="' . h(t('prc_use_none_tip')) . '">–</span>';
		$icons = ['run' => 'bi-play-btn', 'object' => 'bi-ui-checks', 'report' => 'bi-file-earmark-richtext', 'tile' => 'bi-grid', 'widget' => 'bi-bar-chart', 'code' => 'bi-code-slash'];
		$items = '';
		foreach ($u['places'] as $p) {
			$form = $p['form'] !== '' ? '<span class="text-body-secondary ms-1">' . h((string)(nu_form($p['form'])['code'] ?? $p['form'])) . '</span>' : '';
			$inner = '<i class="bi ' . ($icons[$p['kind']] ?? 'bi-dot') . ' me-2"></i>' . h($p['label']) . $form;
			$items .= '<li>' . ($p['url'] !== '' ? '<a class="dropdown-item small" href="' . h($p['url']) . '" target="_blank" rel="noopener">' . $inner . '</a>'
				: '<span class="dropdown-item-text small">' . $inner . '</span>') . '</li>';
		}
		$n = $u['forms'] ? t('prc_use_forms_n', count($u['forms'])) : t('prc_use_places_n', count($u['places']));
		$approx = $u['code'] ? '<span class="ms-1 text-body-secondary" title="' . h(t('prc_use_code_tip')) . '">≈</span>' : '';
		return '<div class="dropdown nuProcUse"><button type="button" class="btn btn-sm btn-link p-0 text-decoration-none align-baseline lh-1" data-bs-toggle="dropdown"'
			. ' data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false">' . h($n) . $approx . ' <i class="bi bi-chevron-down small"></i></button>'
			. '<ul class="dropdown-menu shadow-sm nuProcUseMenu">' . $items . '</ul></div>';
	}

	/** Sort of "Used in": number of forms, then number of places; not used = 0, hooks = -1 (one CASE over the procedures that differ from 0). */
	private static function procUseSort(): string
	{
		$db = nu_db();
		$when = '';
		foreach (NuProcUse::all() as $id => $u) {
			$v = $u['hook'] !== '' ? -1 : count($u['forms']) * 10000 + count($u['places']);
			if ($v !== 0) $when .= ' WHEN ' . $db->quote((string)$id) . ' THEN ' . $v;
		}
		return $when === '' ? '0' : '(CASE p.zzzzsys_php_id' . $when . ' ELSE 0 END)';
	}
}
