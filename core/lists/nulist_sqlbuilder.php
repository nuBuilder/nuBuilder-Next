<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "sqlbuilder" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListSqlBuilder
{
	/** SQL builder queries (was a hand-written table): code, description, tables, mode, reports using it (→ the reports list). */
	private static function sqlBuilder(): array
	{
		$db = nu_db();
		$srcOf = $db->type === 'sqlsrv' ? "'SQL:' + s.zzzzsys_select_id" : "CONCAT('SQL:', s.zzzzsys_select_id)";
		$hasRep = NuReport::available();
		$nRep = $hasRep ? '(SELECT COUNT(*) FROM zzzzsys_report rp WHERE ' . $db->sameText('rp.sre_zzzzsys_php_id', $srcOf) . ')' : '0';
		$cols = [
			'code' => ['label' => t('sqb_code'), 'main' => true, 'locked' => true, 'select' => ['sse_code' => 's.sse_code'], 'sort' => 's.sse_code',
				'text' => static fn($r) => (string)$r['sse_code'], 'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['sse_code'] !== '' ? $r['sse_code'] : '–') . '</span>'],
			'description' => self::col('sse_description', 's.sse_description', t('sqb_description'), 'small text-break'),
			'tables' => ['label' => t('sqb_tables'), 'select' => ['sse_json' => 's.sse_json'], 'sort' => null, 'class' => 'small',
				'text' => static function ($r): string { $j = json_decode((string)$r['sse_json'], true); return implode(', ', array_map(static fn($t) => (string)($t['tablename'] ?? ''), (array)($j['tables'] ?? []))); }],
			'mode' => ['label' => t('sqb_mode'), 'select' => ['sse_edit' => 's.sse_edit'], 'sort' => 's.sse_edit',
				'text' => static fn($r) => t((string)$r['sse_edit'] === '1' ? 'sqb_mode_manual' : 'sqb_mode_builder'),
				'html' => static fn($r) => self::badge(t((string)$r['sse_edit'] === '1' ? 'sqb_mode_manual' : 'sqb_mode_builder'), (string)$r['sse_edit'] === '1' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-info-subtle text-info-emphasis')],
			'reports' => ['label' => t('sqb_used'), 'select' => ['lv_nrep' => $nRep], 'sort' => $hasRep ? $nRep : null, 'align' => 'end', 'text' => static fn($r) => (string)(int)$r['lv_nrep'],
				'html' => static fn($r) => (int)$r['lv_nrep'] > 0 && NuAcl::can(NuAcl::FORM_REPORT)   // → the reports list of this query
					? '<a class="text-decoration-none" href="' . h(nu_url('reports', ['src' => 'SQL:' . $r['__id']])) . '">' . (int)$r['lv_nrep'] . '</a>' : (string)(int)$r['lv_nrep']],
			'sql' => ['label' => t('sqb_sql'), 'select' => ['sse_sql' => 's.sse_sql'], 'sort' => null, 'class' => 'small text-break nfdMono',
				'text' => static fn($r) => mb_strimwidth(preg_replace('/\s+/', ' ', (string)$r['sse_sql']) ?? '', 0, 200, '…')],
			'id' => self::idCol('s.zzzzsys_select_id'),
		];
		$filters = [
			'mode' => ['label' => t('sqb_mode'), 'type' => 'select', 'options' => static fn() => ['builder' => t('sqb_mode_builder'), 'manual' => t('sqb_mode_manual')],
				'where' => static fn(string $v) => match ($v) { 'manual' => ["s.sse_edit = '1'", []], 'builder' => ["COALESCE(s.sse_edit, '') <> '1'", []], default => null }],
			'used' => ['label' => t('sqb_flt_used'), 'type' => 'bool', 'expr' => $hasRep ? "$nRep > 0" : '1 = 0'],
		];
		$base = ['columns' => ['code', 'description', 'tables', 'mode', 'reports'], 'card' => ['title' => 'code', 'badge' => 'mode', 'lines' => ['description', 'tables', 'reports']],
			'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['mode', 'used'], 'values' => []]];
		if ($pf = NuProject::listFilter('select', 's.zzzzsys_select_id')) $filters['project'] = $pf;   // projects
		$cols['opened'] = NuOpened::column('select', 's.zzzzsys_select_id');
		return [
			'key' => 'sqlbuilder', 'title' => t('sqb_title'), 'route' => 'sqlbuilder', 'form' => NuAcl::FORM_SELECT,
			'from' => ' FROM ' . NuSqlSelect::TABLE . ' s', 'where' => ["COALESCE(s.sse_system, '') = ''"], 'params' => [], 'id' => 's.zzzzsys_select_id',
			'link' => static fn($r) => nu_url('sqlbuilder', ['id' => $r['__id']]),
			'search' => ['s.sse_code', 's.sse_description', 's.sse_sql'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['code', 'description']] + $base,
				'recent' => ['columns' => ['code', 'description', 'opened'], 'sort' => ['opened', 'desc']] + $base,   // Recently opened
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
