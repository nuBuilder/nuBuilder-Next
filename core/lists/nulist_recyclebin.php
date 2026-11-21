<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "recyclebin" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListRecycleBin
{
	private static function recycleBin(): array
	{
		$T = NuRecycle::TABLE;
		$forms = nu_forms();
		$vis = NuRecycle::visibleForms();
		$where = []; $params = [];
		if ($vis !== null) {
			if (!$vis) $where[] = '1 = 0';
			else { $where[] = 'r.form_id IN (' . implode(', ', array_fill(0, count($vis), '?')) . ')'; $params = $vis; }
		}
		$cols = [
			'deleted' => ['label' => t('rb_deleted'), 'select' => ['rb_at' => 'r.deleted_at'], 'sort' => 'r.deleted_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => self::date($r['rb_at'] ?? null, true)],
			'title' => self::col('rb_title', 'r.title', t('rb_record'), 'fw-semibold text-break') + ['main' => true, 'locked' => true],
			'form' => ['label' => t('rb_form'), 'select' => ['rb_form' => 'r.form_id', 'rb_code' => 'r.form_code'], 'sort' => 'r.form_code', 'class' => 'small',
				'text' => static fn($r) => trim((string)($forms[$r['rb_form']]['description'] ?? '') ?: (string)$r['rb_code'])],
			'record' => array_replace(self::col('rb_rec', 'r.record_id', t('rb_key'), 'small font-monospace text-break'), ['sort' => null]),
			'by' => self::col('rb_by', 'r.deleted_by_name', t('rb_by'), 'small'),
			'rows' => ['label' => t('rb_rows'), 'select' => ['rb_rows' => 'r.sub_rows'], 'sort' => 'r.sub_rows', 'align' => 'end', 'class' => 'small', 'text' => static fn($r) => (string)(int)$r['rb_rows']],
			'expires' => ['label' => t('rb_expires'), 'select' => ['rb_exp' => 'r.expires_at'], 'sort' => 'r.expires_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => self::date($r['rb_exp'] ?? null)],
			'actions' => ['label' => '', 'select' => [], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'noexport' => true, 'text' => static fn($r) => '',
				'html' => static fn($r) => '<button type="button" class="btn btn-sm btn-outline-secondary rbRestore" data-id="' . h((string)$r['__id']) . '" title="' . h(t('rb_restore')) . '" aria-label="' . h(t('rb_restore')) . '"><i class="bi bi-arrow-counterclockwise"></i></button>'],
			'table' => self::col('rb_tab', 'r.table_name', t('rb_table'), 'small font-monospace'),
			'id' => self::idCol('r.zzzzsys_nunext_recycle_id'),
		];
		$filters = [
			'form' => ['label' => t('rb_form'), 'type' => 'select', 'tom' => true, 'options' => static function () use ($forms, $vis) {
				$db = nu_db(); $o = [];
				if (!$db->tableExists(NuRecycle::TABLE)) return $o;
				foreach ($db->all('SELECT form_id, MAX(form_code) AS c, COUNT(*) AS n FROM ' . NuRecycle::TABLE . ' GROUP BY form_id') as $f) {
					if ($vis !== null && !in_array((string)$f['form_id'], $vis, true)) continue;
					$o[(string)$f['form_id']] = [trim((string)($forms[$f['form_id']]['description'] ?? '') ?: (string)$f['c']), (string)$f['c'] . ' · ' . (int)$f['n']];
				}
				return $o;
			}, 'where' => static fn(string $v) => $v !== '' ? ['r.form_id = ?', [$v]] : null],
			'deleted' => ['label' => t('rb_deleted'), 'type' => 'date', 'expr' => 'r.deleted_at', 'presets' => ['d7', 'd30']],
		];
		$base = ['columns' => ['deleted', 'title', 'form', 'by', 'rows', 'expires', 'actions'], 'card' => ['title' => 'title', 'badge' => 'form', 'lines' => ['deleted', 'by', 'expires']],
			'sort' => ['deleted', 'desc'], 'per' => 50, 'filters' => ['shown' => ['form', 'deleted'], 'values' => []]];
		return [
			'key' => 'recyclebin', 'title' => t('rb_title'), 'route' => 'recyclebin', 'empty_icon' => 'bi-trash3', 'form' => NuAcl::FORM_RECYCLE,
			'from' => " FROM $T r", 'where' => $where, 'params' => $params, 'id' => 'r.zzzzsys_nunext_recycle_id',
			'link' => static fn($r) => nu_url('recyclebin', ['id' => $r['__id']]),
			'search' => ['r.title', 'r.record_id', 'r.form_code', 'r.deleted_by_name'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
