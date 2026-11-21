<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "projects" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListProjects
{
	/** User help pages (core/nuuserhelp.php): the pages themselves (lang ''), with their translations, access levels and where they are used. */
	/** Projects (core/nuproject.php). */
	private static function projects(): array
	{
		$T = NuProject::T;
		$nItems = '(SELECT COUNT(*) FROM ' . NuProject::I . ' i WHERE i.project_id = p.zzzzsys_nunext_project_id)';
		$nTasks = nu_db()->tableExists(NuProject::K) ? '(SELECT COUNT(*) FROM ' . NuProject::K . " k WHERE k.project_id = p.zzzzsys_nunext_project_id AND k.status <> 'done')" : '0';
		$w = NuProject::working();
		$cols = [
			'name' => ['label' => t('prj_f_name'), 'main' => true, 'locked' => true, 'select' => ['pj_name' => 'p.name', 'pj_code' => 'p.code'], 'sort' => 'p.name',
				'text' => static fn($r) => (string)$r['pj_name'],
				'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['pj_name']) . '</span>'
					. ((string)$r['__id'] === $w ? ' ' . self::badge(t('prj_working_badge'), 'text-bg-primary nuPrjWorkBadge') : '')
					. '<div class="small text-body-secondary font-monospace">' . h((string)$r['pj_code']) . '</div>'],
			'code' => self::col('pj_code2', 'p.code', t('prj_f_code'), 'small font-monospace'),
			'description' => self::col('pj_desc', 'p.description', t('prj_f_description'), 'small')
				+ ['html' => static fn($r) => self::cut((string)$r['pj_desc'], 70)],
			'items' => array_replace(self::col('pj_items', $nItems, t('prj_items'), 'small'), ['align' => 'end']),
			'tasks' => array_replace(self::col('pj_tasks', $nTasks, t('prj_open_tasks'), 'small'), ['align' => 'end']),
			'status' => ['label' => t('status'), 'select' => ['pj_status' => 'p.status'], 'sort' => 'p.status',
				'text' => static fn($r) => t((string)$r['pj_status'] === 'archived' ? 'prj_archived' : 'prj_active'),
				'html' => static fn($r) => self::badge(t((string)$r['pj_status'] === 'archived' ? 'prj_archived' : 'prj_active'), (string)$r['pj_status'] === 'archived' ? 'text-bg-secondary' : 'text-bg-success')],
			'updated' => ['label' => t('fi_changed'), 'select' => ['pj_upd' => 'p.updated_at'], 'sort' => 'p.updated_at', 'class' => 'small text-nowrap', 'text' => static fn($r) => self::date($r['pj_upd'] ?? null, true)],
			'id' => self::idCol('p.zzzzsys_nunext_project_id'),
		];
		$filters = [
			'status' => ['label' => t('status'), 'type' => 'select', 'all' => 'all', 'options' => static fn() => ['active' => t('prj_active'), 'archived' => t('prj_archived')],
				'where' => static fn(string $v) => $v === 'archived' ? ["p.status = 'archived'", []] : ($v === 'active' ? ["COALESCE(p.status, 'active') <> 'archived'", []] : null)],
		];
		$base = ['columns' => ['name', 'description', 'items', 'tasks', 'status', 'updated'], 'card' => ['title' => 'name', 'badge' => 'status', 'lines' => ['description', 'items', 'tasks']],
			'sort' => ['name', 'asc'], 'per' => 50, 'filters' => ['shown' => ['status'], 'values' => ['status' => 'active']]];
		return [
			'key' => 'projects', 'title' => t('prj_title'), 'route' => 'projects', 'new_url' => NuProject::canChange() ? nu_url('projects', ['id' => 'new']) : '',
				'empty_icon' => 'bi-kanban', 'form' => NuAcl::FORM_PROJECT,
			'from' => " FROM $T p", 'where' => [], 'params' => [], 'id' => 'p.zzzzsys_nunext_project_id',
			'link' => static fn($r) => nu_url('projects', ['id' => $r['__id']]),
			'search' => ['p.name', 'p.code', 'p.description'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['name', 'items', 'status']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => ['status' => 'active']]] + $base,
			],
		];
	}
}
