<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "selftest" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListSelftest
{
	/** Self-test results: a derived table of the session's results (NuSelfTest::source) – developer mode, the page gates it. */
	private static function selftest(): array
	{
		[$from, $fromParams] = NuSelfTest::$source;
		$cls = ['ok' => 'text-bg-success', 'warn' => 'text-bg-warning', 'fail' => 'text-bg-danger', 'skip' => 'text-bg-secondary'];
		$rank = "CASE s.status WHEN 'fail' THEN 0 WHEN 'warn' THEN 1 WHEN 'ok' THEN 2 ELSE 3 END";
		$cols = [
			'group' => ['label' => t('st_group'), 'select' => ['st_grp' => 's.grp'], 'sort' => 's.grp, s.pos', 'class' => 'small text-body-secondary text-nowrap',
				'text' => static fn($r) => in_array((string)$r['st_grp'], NuSelfTest::GROUPS, true) ? t('st_group_' . $r['st_grp']) : (string)$r['st_grp']],
			'check' => ['label' => t('st_check'), 'main' => true, 'locked' => true, 'select' => ['st_title' => 's.title', 'st_id' => 's.id'], 'sort' => 's.title',
				'text' => static fn($r) => (string)$r['st_title'], 'html' => static fn($r) => '<span class="fw-semibold" data-st="' . h((string)$r['st_id']) . '">' . h((string)$r['st_title']) . '</span>'],
			'status' => ['label' => t('ntf_status'), 'select' => ['st_status' => 's.status'], 'sort' => $rank . ', s.pos',
				'text' => static fn($r) => t('st_status_' . $r['st_status']),
				'html' => static fn($r) => '<span class="badge ' . ($cls[(string)$r['st_status']] ?? 'text-bg-secondary') . ' nuStBadge">' . h(t('st_status_' . $r['st_status'])) . '</span>'],
			'details' => ['label' => t('log_details'), 'select' => ['st_detail' => 's.detail'], 'sort' => null, 'class' => 'small text-break',
				'text' => static fn($r) => (string)$r['st_detail'],
				'html' => static fn($r) => '<div class="nuLvClamp" data-more="' . h(t('lv_more')) . '" data-less="' . h(t('lv_less')) . '">' . h((string)$r['st_detail']) . '</div>'],
			'ms' => ['label' => 'ms', 'select' => ['st_ms' => 's.ms'], 'sort' => 's.ms', 'align' => 'end', 'class' => 'small text-body-secondary', 'text' => static fn($r) => (string)(int)$r['st_ms']],
			'pos' => ['label' => '#', 'select' => ['st_pos' => 's.pos'], 'sort' => 's.pos', 'align' => 'end', 'class' => 'small text-body-secondary', 'text' => static fn($r) => (string)(int)$r['st_pos']],
		];
		$filters = [
			'group' => ['label' => t('st_group'), 'type' => 'select', 'options' => static fn() => array_combine(NuSelfTest::GROUPS, array_map(static fn($g) => t('st_group_' . $g), NuSelfTest::GROUPS)),
				'where' => static fn(string $v) => in_array($v, NuSelfTest::GROUPS, true) ? ['s.grp = ?', [$v]] : null],
			'status' => ['label' => t('ntf_status'), 'type' => 'select',
				'options' => static fn() => ['fail' => t('st_status_fail'), 'warn' => t('st_status_warn'), 'ok' => t('st_status_ok'), 'skip' => t('st_status_skip')],
				'where' => static fn(string $v) => isset($cls[$v]) ? ['s.status = ?', [$v]] : null],
		];
		$base = ['columns' => ['group', 'check', 'status', 'details', 'ms'], 'card' => ['title' => 'check', 'badge' => 'status', 'lines' => ['group', 'details', 'ms']],
			'sort' => ['pos', 'asc'], 'per' => 100, 'filters' => ['shown' => ['group', 'status'], 'values' => []]];
		return [
			'key' => 'selftest', 'title' => t('st_title'), 'route' => 'selftest', 'empty_icon' => 'bi-clipboard-check', 'form' => NuAcl::FORM_DEBUG,
			'from' => $from, 'where' => [], 'params' => $fromParams, 'id' => 's.pos',
			'link' => static fn($r) => '',
			'search' => ['s.title', 's.detail', 's.id'], 'open_min' => 0, 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'problems' => ['filters' => ['shown' => ['group', 'status'], 'values' => ['status' => 'fail']]] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
