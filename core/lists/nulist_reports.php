<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "reports" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListReports
{
	private static function reports(): array
	{
		$nAccess = '(SELECT COUNT(*) FROM zzzzsys_access_report a WHERE a.sre_zzzzsys_report_id = r.zzzzsys_report_id)';
		$canSel = NuAcl::can(NuAcl::FORM_SELECT);
		$canProc = NuAcl::can(NuAcl::FORM_PROCEDURE);
		$cols = [
			'actions' => ['label' => '', 'select' => ['sre_code' => 'r.sre_code'], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => '<a class="btn btn-sm btn-outline-secondary" href="' . h(nu_url('reportdesigner', ['id' => $r['__id']])) . '" title="'
					. h(t('rpt_designer')) . '" aria-label="' . h(t('rpt_designer')) . '"><i class="bi bi-vector-pen"></i></a>'
					. ' <a class="btn btn-sm btn-outline-secondary nuRowRun" href="' . h(nu_url('report', ['c' => (string)$r['sre_code']]))
						. '" target="_blank" rel="noopener" title="' . h(t('rpt_run')) . '" aria-label="' . h(t('rpt_run'))
						. '"><i class="bi bi-file-earmark-pdf"></i></a>'
					. ' <a class="btn btn-sm btn-outline-secondary nuRowRun nuRowXlsx" href="' . h(nu_url('report', ['c' => (string)$r['sre_code'], 'fmt' => 'xlsx'])) . '" title="' . h(t('rpt_xlsx_tip')) . '" aria-label="' . h(t('rpt_xlsx')) . '"><i class="bi bi-file-earmark-excel"></i></a>'],
			'code' => self::col('sre_code', 'r.sre_code', t('rpt_code')) + ['main' => true, 'locked' => true],
			'description' => self::col('sre_description', 'r.sre_description', t('rpt_description'), 'small text-break'),
			'group' => array_replace(self::badgeCol('sre_group', 'r.sre_group', t('rpt_group')), ['sort' => 'r.sre_group, r.sre_code']),
			'source' => ['label' => t('rpt_source'), 'select' => ['sre_src' => 'r.sre_zzzzsys_php_id'], 'sort' => 'r.sre_zzzzsys_php_id', 'class' => 'small',
				'text' => static fn($r) => NuReport::sourceLabel((string)$r['sre_src']),
				// the query / procedure opens in its editor
				'html' => static function ($r) use ($canSel, $canProc): string { [$kind] = explode(':', (string)$r['sre_src'] . ':');
					$id = substr((string)$r['sre_src'], strlen($kind) + 1);
					$href = match ($kind) { 'SQL' => $canSel ? nu_url('sqlbuilder', ['id' => $id]) : '', 'PROCEDURE' => $canProc ? nu_url('procedures', ['id' => $id]) : '', default => '' };
					return self::badge($kind, 'bg-info-subtle text-info-emphasis me-1') . ($href !== '' ? '<a class="text-decoration-none" href="' . h($href) . '">' . h($id) . '</a>' : h($id)); }],
			// the launch form opens
			'launch' => array_replace(self::col('sfo_code', 'f.sfo_code', t('rpt_launch'), 'small'), ['select' => ['sfo_code' => 'f.sfo_code', 'lv_launch_id' => 'f.zzzzsys_form_id'],
				'html' => static fn($r) => (string)($r['sfo_code'] ?? '') !== '' ? '<a class="text-decoration-none" href="' . h(nu_url('form', ['f' => (string)$r['lv_launch_id']])) . '">' . h((string)$r['sfo_code']) . '</a>' : '']),
			// opens the report's tab Access levels
			'access' => self::col('n_access', $nAccess, t('rpt_access'), 'small') + ['align' => 'end',
				'html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('reports', ['id' => $r['__id']])) . '#access">' . (int)$r['n_access'] . '</a>'],
			'id' => self::idCol('r.zzzzsys_report_id'),
		];
		$filters = [
			'group' => ['label' => t('rpt_group'), 'type' => 'distinct', 'expr' => 'r.sre_group', 'none' => t('lv_empty')],
			'launch' => ['label' => t('rpt_launch'), 'type' => 'distinct', 'expr' => 'f.sfo_code', 'none' => t('lv_empty')],
			// kind of the data source
			'srctype' => ['label' => t('rpt_flt_srctype'), 'type' => 'select',
				'options' => static fn() => ['TABLE' => t('rpt_src_table'), 'SQL' => t('rpt_src_sql'), 'PROCEDURE' => t('rpt_src_procedure')],
				'where' => static fn(string $v) => in_array($v, ['TABLE', 'SQL', 'PROCEDURE'], true) ? ['r.sre_zzzzsys_php_id LIKE ?', [$v . ':%']] : null],
			// one data source (the SQL builder's Reports count links here)
			'src' => ['label' => t('rpt_source'), 'type' => 'select',
				'options' => static function (): array { $o = []; foreach (nu_db()->all('SELECT DISTINCT sre_zzzzsys_php_id AS s FROM ' . NuReport::TABLE . ' WHERE sre_zzzzsys_php_id IS NOT NULL ORDER BY 1') as $x) $o[(string)$x['s']] = NuReport::sourceLabel((string)$x['s']); return $o; },
				'where' => static fn(string $v) => ['r.sre_zzzzsys_php_id = ?', [$v]]],
		];
		if ($pf = NuProject::listFilter('report', 'r.zzzzsys_report_id')) $filters['project'] = $pf;   // projects
		$cols['opened'] = NuOpened::column('report', 'r.zzzzsys_report_id');
		return [
			'key' => 'reports', 'title' => t('rpt_title_list'), 'route' => 'reports', 'form' => NuAcl::FORM_REPORT,
			'from' => ' FROM ' . NuReport::TABLE . ' r LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = r.sre_zzzzsys_form_id', 'where' => [], 'params' => [],
			'id' => 'r.zzzzsys_report_id', 'link' => static fn($r) => nu_url('reports', ['id' => $r['__id']]),
			'search' => ['r.sre_code', 'r.sre_description', 'r.sre_group', 'r.sre_zzzzsys_php_id', 'f.sfo_code'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['actions', 'code', 'description', 'group', 'source', 'launch', 'access'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'source']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'srctype'], 'values' => []]],
				'compact' => ['columns' => ['actions', 'code', 'description'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
				'recent' => ['columns' => ['actions', 'code', 'description', 'group', 'opened'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'opened']],
					'sort' => ['opened', 'desc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],   // Recently opened
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'source', 'launch', 'access']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}
}
