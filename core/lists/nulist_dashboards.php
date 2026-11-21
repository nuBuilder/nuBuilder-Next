<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "dashboards" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListDashboards
{
	private static function dashboards(): array
	{
		$D = NuDashboard::TD;
		$W = NuDashboard::TW;
		$nW = "(SELECT COUNT(*) FROM $W w WHERE w.dashboard_id = d.zzzzsys_nunext_dashboard_id)";
		$accName = static function (): array {
			return NuAcl::levelCodes();
		};
		$accText = static function (array $r) use ($accName): string {
			$a = NuDashboard::access(['access' => $r['dsh_access']]);
			$n = $accName();
			return $a ? implode(', ', array_map(static fn($x) => $n[$x] ?? $x, $a)) : t('dash_access_all');
		};
		$cols = [
			'code' => ['label' => t('dash_code'), 'main' => true, 'locked' => true, 'select' => ['dsh_code' => 'd.code'], 'sort' => 'd.code', 'text' => static fn($r) => (string)$r['dsh_code'],
				'html' => static fn($r) => '<span class="fw-semibold font-monospace">' . h((string)$r['dsh_code']) . '</span>'],
			'title' => self::col('dsh_title', 'd.title', t('dash_title_col')),
			'description' => self::col('dsh_desc', 'd.description', t('dash_description'), 'small text-body-secondary'),
			'where' => ['label' => t('dash_where'), 'select' => ['dsh_home' => 'd.on_home', 'dsh_access' => 'd.access'], 'sort' => 'd.on_home', 'class' => 'small',
				'text' => static fn($r) => ((string)$r['dsh_home'] === '1' ? t('dash_home_badge') . ' · ' : '') . $accText($r),
				'html' => static fn($r) => ((string)$r['dsh_home'] === '1' ? '<span class="badge text-bg-primary me-1"><i class="bi bi-house me-1" aria-hidden="true"></i>'
					. h(t('dash_home_badge')) . '</span>' : '')
					. '<span class="text-body-secondary">' . h($accText($r)) . '</span>'],
			'widgets' => ['label' => t('dash_widgets'), 'select' => ['dsh_n' => $nW], 'sort' => $nW, 'align' => 'end', 'text' => static fn($r) => (string)(int)$r['dsh_n'],
				'html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('dashboards', ['id' => $r['__id']]) . '#dashWidgets') . '">' . (int)$r['dsh_n'] . '</a>'],
			'updated' => ['label' => t('fi_changed'), 'select' => ['dsh_upd' => 'd.updated_at', 'dsh_by' => 'd.updated_by'], 'sort' => 'd.updated_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => self::date($r['dsh_upd'] ?? null, true) . ((string)($r['dsh_by'] ?? '') !== '' ? ' · ' . $r['dsh_by'] : '')],
			'id' => self::idCol('d.zzzzsys_nunext_dashboard_id'),
		];
		$filters = [
			'home' => ['label' => t('dash_flt_home'), 'type' => 'bool', 'expr' => "d.on_home = '1'"],
			'acc' => ['label' => t('nav_access'), 'type' => 'select', 'tom' => true,
				'options' => static fn() => ['-' => t('dash_access_all')] + $accName(),
				'where' => static fn(string $v) => $v === '-' ? ["COALESCE(d.access, '') IN ('', '[]')", []] : ['d.access LIKE ?', ['%"' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '"%']]],
		];
		$base = ['columns' => ['code', 'title', 'where', 'widgets'], 'card' => ['title' => 'title', 'badge' => '', 'lines' => ['code', 'where', 'widgets']],
			'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['home', 'acc'], 'values' => []]];
		return [
			'key' => 'dashboards', 'title' => t('dash_title'), 'route' => 'dashboards', 'new_url' => nu_url('dashboards', ['id' => 'new']), 'empty_icon' => 'bi-speedometer2', 'form' => NuAcl::FORM_DASHBOARD,
			'from' => " FROM $D d", 'where' => [], 'params' => [], 'id' => 'd.zzzzsys_nunext_dashboard_id',
			'link' => static fn($r) => nu_url('dashboards', ['id' => $r['__id']]),
			'search' => ['d.code', 'd.title', 'd.description'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['code', 'title', 'widgets']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
