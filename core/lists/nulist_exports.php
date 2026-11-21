<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "exports" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListExports
{
	/** Saved data exports (NuDataExport): name, code, format, datasets, runs, last run, changed. */
	private static function exports(): array
	{
		$T = NuDataExport::T;
		$dsOf = static function ($r): array {
			$j = json_decode((string)$r['dx_ds'], true);
			return array_map(static fn($d) => (string)(($d['name'] ?? '') !== '' ? $d['name'] : (($d['source'] ?? '') === 'table' ? ($d['table'] ?? '') : t('dxp_src_' . (string)($d['source'] ?? 'sql')))),
				is_array($j) ? $j : []);
		};
		$cols = [
			'name' => ['label' => t('dxp_name'), 'main' => true, 'locked' => true, 'select' => ['dx_name' => 'x.name'], 'sort' => 'x.name',
				'text' => static fn($r) => (string)$r['dx_name'], 'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['dx_name']) . '</span>'],
			'code' => self::col('dx_code', 'x.code', t('dxp_code'), 'small font-monospace'),
			'format' => ['label' => t('dxp_format'), 'select' => ['dx_fmt' => 'x.format'], 'sort' => 'x.format', 'text' => static fn($r) => strtoupper((string)$r['dx_fmt']),
				'html' => static fn($r) => self::badge(strtoupper((string)$r['dx_fmt']), (string)$r['dx_fmt'] === 'xlsx' ? 'text-bg-success' : 'text-bg-secondary')],
			'datasets' => ['label' => t('dxp_datasets'), 'select' => ['dx_ds' => 'x.datasets'], 'sort' => null, 'class' => 'small', 'text' => static fn($r) => implode(', ', $dsOf($r))],
			'description' => self::col('dx_desc', 'x.description', t('fi_description'), 'small text-body-secondary'),
			'runs' => ['label' => t('dxp_runs'), 'select' => ['dx_runs' => 'x.runs'], 'sort' => 'x.runs', 'align' => 'end', 'class' => 'small', 'text' => static fn($r) => (string)(int)$r['dx_runs']],
			'last_run' => ['label' => t('dxp_last_run'), 'select' => ['dx_last' => 'x.last_run_at'], 'sort' => 'x.last_run_at', 'class' => 'small text-nowrap', 'text' => static fn($r) => self::date($r['dx_last'] ?? null, true)],
			'updated' => ['label' => t('fi_changed'), 'select' => ['dx_upd' => 'x.updated_at', 'dx_by' => 'x.updated_by'], 'sort' => 'x.updated_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => trim(self::date($r['dx_upd'] ?? null, true) . ' ' . (string)$r['dx_by'])],
			'run' => ['label' => '', 'select' => [], 'sort' => null, 'class' => 'text-end text-nowrap', 'text' => static fn($r) => '',
				'html' => static fn($r) => '<a class="btn btn-sm btn-outline-secondary dxRun" href="' . h(nu_url('dataexport', ['run' => $r['__id']])) . '" title="' . h(t('dxp_run')) . '"><i class="bi bi-download" aria-hidden="true"></i><span class="d-none d-md-inline ms-1">' . h(t('dxp_run')) . '</span></a>'],
			'id' => self::idCol('x.' . $T . '_id'),
		];
		$filters = [
			'format' => ['label' => t('dxp_format'), 'type' => 'select', 'options' => static fn() => ['xlsx' => 'XLSX', 'csv' => 'CSV'],
				'where' => static fn(string $v) => in_array($v, ['xlsx', 'csv'], true) ? ['x.format = ?', [$v]] : null],
		];
		$base = ['columns' => ['name', 'code', 'format', 'datasets', 'runs', 'last_run', 'run'], 'card' => ['title' => 'name', 'badge' => 'format', 'lines' => ['datasets', 'last_run']],
			'sort' => ['name', 'asc'], 'per' => 50, 'filters' => ['shown' => ['format'], 'values' => []]];
		return [
			'key' => 'exports', 'title' => t('dxp_title'), 'route' => 'dataexport', 'new_url' => nu_url('dataexport', ['id' => 'new']), 'empty_icon' => 'bi-box-arrow-up', 'form' => NuAcl::FORM_EXPORT,
			'from' => " FROM $T x", 'where' => [], 'params' => [], 'id' => 'x.' . $T . '_id',
			'link' => static fn($r) => nu_url('dataexport', ['id' => $r['__id']]),
			'search' => ['x.name', 'x.code', 'x.description', 'x.datasets'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['name', 'format', 'run']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
