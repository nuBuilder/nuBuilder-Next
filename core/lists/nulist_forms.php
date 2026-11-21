<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "forms" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListForms
{
	private static function forms(): array
	{
		$u = NuAuth::user();
		$designer = NuAcl::can(NuAcl::FORM_FORM);
		$ga = !empty($u['globeadmin']);
		$canStatus = NuFormDraft::canEdit() && !NuImpersonate::readOnly();
		$status = ['1' => 'builder_status_1', '0' => 'builder_status_0', '2' => 'builder_status_2', '3' => 'builder_status_3'];
		$st = "COALESCE(NULLIF(f.sfo_status, ''), '1')";
		$where = ["f.zzzzsys_form_id NOT LIKE 'nu%'"];
		$params = [];
		if (!$ga && !$designer) {   // forms of the main and the additional access levels
			[$in, $lv] = NuUserAccess::inSql('slf_zzzzsys_access_id', $u);
			$where[] = "f.zzzzsys_form_id IN (SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form WHERE $in)";
			array_push($params, ...$lv);
		}
		$trail = '';
		// form drafts: the open draft of each form (author, id) – for designers
		$drafts = $designer && NuFormDraft::ready();
		$dWhere = "d.status IN ('open', 'review') AND " . nu_db()->sameText('d.form_id', 'f.zzzzsys_form_id');
		$dBy = $drafts ? "(SELECT MAX(d.created_by) FROM zzzzsys_nunext_form_draft d WHERE $dWhere)" : 'NULL';
		$dId = $drafts ? "(SELECT MAX(d.zzzzsys_nunext_form_draft_id) FROM zzzzsys_nunext_form_draft d WHERE $dWhere)" : 'NULL';
		$nAcc = '(SELECT COUNT(DISTINCT af.slf_zzzzsys_access_id) FROM zzzzsys_access_form af WHERE af.slf_zzzzsys_form_id = f.zzzzsys_form_id)';
		$facOk = NuAcl::can(NuAcl::FORM_ACCESS);
		$everyone = NuFormEveryone::all();                                 // forms for everyone
		$dbTool = NuAdmin::can('dbtool', 'change') && NuVendor::primaryDb() !== '';
		// department / team of a form (sfo_json nunext.*): only rows with valid JSON; no ", " inside (the sort splits on it)
		$tag = static fn(string $k): string => nu_db()->jsonText('f.sfo_json', "nunext.$k");
		$tagsUsed = NuFormMeta::tagValues();
		$cols = [
			'actions' => ['label' => '', 'select' => ['lv_draft_id' => $dId], 'sort' => null, 'class' => 'text-nowrap nuRowActions',
				'if' => static fn() => $designer, 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => '<a class="btn btn-sm btn-outline-secondary" href="'
					. h(nu_url('builder', ['f' => $r['__id'], 'draft' => ($r['lv_draft_id'] ?? null) ? '1' : null])) . '" title="' . h(t('ds_mode')) . '" aria-label="'
					. h(t('ds_mode')) . '"><i class="bi bi-tools"></i></a>'
					. (($r['lv_draft_id'] ?? null) ? ' <a class="btn btn-sm btn-outline-warning" href="'
						. h(nu_url('form', ['f' => $r['__id'], 'draft' => (string)$r['lv_draft_id']])) . '" target="_blank" rel="noopener" title="'
						. h(t('builder_draft_preview')) . '" aria-label="' . h(t('builder_draft_preview')) . '"><i class="bi bi-cone-striped"></i></a>' : '')
],   // the copy button of each row went in – Form cloner: Forms → Tools
			'description' => self::col('sfo_description', 'f.sfo_description', t('builder_description')) + ['main' => true, 'locked' => true],
			'code' => self::col('sfo_code', 'f.sfo_code', t('builder_code'), 'small text-body-secondary'),
			'type' => ['label' => t('builder_type'), 'select' => ['sfo_type' => 'f.sfo_type', 'lv_page_code' => 'f.sfo_code'], 'sort' => 'f.sfo_type',
				'text' => static fn($r) => t('form_type_' . ($r['sfo_type'] ?: 'browseedit')),
				'html' => static fn($r) => '<span class="badge nuFormType nuFormType-' . h($r['sfo_type'] ?: 'browseedit') . '">' . h(t('form_type_' . ($r['sfo_type'] ?: 'browseedit'))) . '</span>'
					. ($designer && $r['sfo_type'] === 'page' ? self::pageApiBadge((string)$r['lv_page_code']) : '')],
			// the table opens in the database tool (phpMyAdmin / Adminer, a POST with the CSRF token, new tab)
			'table' => self::col('sfo_table', 'f.sfo_table', t('builder_table'), 'small') + ['html' => static fn($r) => (string)$r['sfo_table'] !== '' && $dbTool
				? '<form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="d-inline m-0">' . nu_csrf_field()
					. '<input type="hidden" name="app" value="DB"><input type="hidden" name="table" value="' . h((string)$r['sfo_table']) . '">'
					. '<button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none nuTableLink" title="' . h(t('forms_table_open')) . '">'
						. h((string)$r['sfo_table']) . ' <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i></button></form>'
				: h((string)$r['sfo_table'])],
			'group' => array_replace(self::col('sfo_group', 'f.sfo_group', t('builder_group'), 'small'), ['sort' => 'f.sfo_group, f.sfo_description']),
			// department / team: sfo_json nunext – grouping only; hidden in the built-in views except All
			'department' => self::col('lv_department', $tag('department'), t('builder_department'), 'small'),
			'team' => self::col('lv_team', $tag('team'), t('builder_team'), 'small'),
			// how many access levels may open the form; the number opens "Access levels of this form" (assets/js/nu/nuformaccess.js)
			'access' => ['label' => t('forms_col_access'), 'select' => ['lv_nacc' => $nAcc], 'sort' => $nAcc, 'align' => 'end', 'class' => 'small',
				// forms for everyone (NuFormEveryone): a badge instead of the number
				'text' => static fn($r) => isset($everyone[(string)$r['__id']]) ? t('proc_everyone') : (string)(int)$r['lv_nacc'],
				'html' => static function ($r) use ($facOk, $everyone): string {
					$ev = isset($everyone[(string)$r['__id']]);
					$label = $ev ? self::badge(t('proc_everyone'), 'bg-info-subtle text-info-emphasis nuFacEvBadge') : (string)(int)$r['lv_nacc'];
					return $facOk && ($r['sfo_type'] ?? '') !== 'subform'
						? '<button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-nu-fac="' . h((string)$r['__id']) . '" title="' . h(t('fac_title')) . '">' . $label . '</button>'
						: $label;
				}],
			'layout' => ['label' => t('form_layout'), 'select' => ['sfo_json' => 'f.sfo_json'], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => t('form_layout_' . (NuFormMeta::nunextJson($r['sfo_json'])['layout'] ?? 'absolute'))],
			'status' => ['label' => t('builder_status'), 'select' => ['lv_status' => $st, 'lv_st_code' => 'f.sfo_code'], 'sort' => $st,
				'text' => static fn($r) => t($status[(string)$r['lv_status']] ?? 'builder_status_1'),
				// form designers: right-click / long press = menu to change the status (assets/js/nuformstatus.js → builderapi form.save)
				'html' => static fn($r) => ($canStatus ? '<span class="nuFormStatus" tabindex="0" data-nu-form="' . h((string)$r['__id']) . '" data-nu-label="'
					. h((string)$r['lv_st_code']) . '" data-nu-st="' . h((string)$r['lv_status']) . '" title="' . h(t('forms_status_change_tip')) . '">' : '')
					. self::badge(t($status[(string)$r['lv_status']] ?? 'builder_status_1'), match ((string)$r['lv_status']) {
					'0' => 'bg-warning-subtle text-warning-emphasis', '2' => 'bg-danger-subtle text-danger-emphasis', '3' => 'bg-secondary-subtle text-secondary-emphasis', default => 'bg-success-subtle text-success-emphasis' }) . ($canStatus ? '</span>' : '')],
			'draft' => ['label' => t('forms_col_draft'), 'select' => ['lv_draft_by' => $dBy], 'sort' => $drafts ? $dBy : null, 'class' => 'small', 'if' => static fn() => $drafts,
				'text' => static fn($r) => $r['lv_draft_by'] !== null ? t('forms_draft_by', (string)$r['lv_draft_by']) : '',
				'html' => static fn($r) => $r['lv_draft_by'] !== null ? self::badge(t('forms_draft_by', (string)$r['lv_draft_by']), 'bg-warning-subtle text-warning-emphasis') : ''],
			'id' => self::idCol('f.zzzzsys_form_id'),
		];
		$filters = [
			'type' => ['label' => t('builder_type'), 'type' => 'select',
				'options' => static fn() => array_combine($t = ['browseedit', 'browse', 'edit', 'launch', 'subform', 'page'], array_map(static fn($x) => t('form_type_' . $x), $t)),
				'where' => static fn(string $v) => ["COALESCE(NULLIF(f.sfo_type, ''), 'browseedit') = ?", [$v]]],
			'group' => ['label' => t('builder_group'), 'type' => 'distinct', 'expr' => 'f.sfo_group', 'none' => t('forms_group_none')],
			'department' => ['label' => t('builder_department'), 'type' => 'distinct', 'expr' => $tag('department'), 'none' => t('lv_none')],
			'team' => ['label' => t('builder_team'), 'type' => 'distinct', 'expr' => $tag('team'), 'none' => t('lv_none')],
			'status' => ['label' => t('builder_status'), 'type' => 'select', 'all' => 'all',
				'options' => static fn() => ['na' => t('forms_status_not_archived')] + array_map('t', $status),
				'where' => static fn(string $v) => $v === 'na' ? ["$st <> '3'", []] : (isset($status[$v]) ? ["$st = ?", [$v]] : null)],
			'table' => ['label' => t('builder_table'), 'type' => 'distinct', 'expr' => 'f.sfo_table', 'none' => t('lv_empty')],
			// access levels: forms for everyone / forms no level may open
			'facc' => ['label' => t('forms_col_access'), 'type' => 'select', 'options' => static fn() => ['ev' => t('forms_flt_everyone'), 'none' => t('forms_flt_no_level')],
				'where' => static function (string $v) use ($everyone, $nAcc): ?array {
					if ($v === 'none') return ["$nAcc = 0", []];
					if ($v !== 'ev') return null;
					if (!$everyone) return ['1 = 0', []];
					return ['f.zzzzsys_form_id IN (' . implode(', ', array_fill(0, count($everyone), '?')) . ')', array_keys($everyone)];
				}],
		];
		if ($pf = NuProject::listFilter('form', 'f.zzzzsys_form_id')) $filters['project'] = $pf;   // projects
		if ($drafts) $filters['draft'] = ['label' => t('forms_col_draft'), 'type' => 'select', 'options' => static fn() => ['1' => t('forms_with_draft')],
			'where' => static fn(string $v) => $v === '1' ? ["$dBy IS NOT NULL", []] : null];
		// ?type=page = the list Custom pages
		$cols['opened'] = NuOpened::column('form', 'f.zzzzsys_form_id');   // when I last opened it in design mode
		return [
			'key' => 'forms', 'title' => t(($_GET['type'] ?? '') === 'page' ? 'upd_pages_title' : 'nav_forms'), 'route' => 'forms', 'form' => NuAcl::FORM_FORM,
				'public' => true, 'from' => ' FROM zzzzsys_form f', 'where' => $where, 'params' => $params,
			'id' => 'f.zzzzsys_form_id', 'always' => ['type'],
			'rows' => static function (array $rows) use (&$trail): array {   // breadcrumb + Back of the opened form lead here
				$trail = $rows ? NuTrail::store([['title' => t('nav_forms'), 'url' => NuTrail::currentUrl()]]) : '';
				return $rows;
			},
			'link' => static function ($r) use (&$trail, $designer): string {   // subforms cannot run on their own: the builder (designers)
				if (($r['sfo_type'] ?? '') === 'subform') return $designer ? nu_url('builder', ['f' => $r['__id']]) : '';
				return nu_url('form', ['f' => $r['__id'], 'bt' => $trail]);
			},
			'search' => ['f.sfo_code', 'f.sfo_description', 'f.sfo_table', 'f.sfo_group'], 'search_ph' => t('forms_search_ph'), 'open_min' => 800,
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['actions', 'description', 'code', 'type', 'table', 'group', 'layout', 'status', 'draft', 'access'],
					'card' => ['title' => 'description', 'badge' => 'type', 'lines' => ['code', 'table', 'group']],
					'sort' => ['group', 'asc'], 'per' => 500, 'filters' => ['shown' => array_values(array_filter(['type', 'group', $tagsUsed['department'] ? 'department' : null, $tagsUsed['team'] ? 'team' : null, 'status', $drafts ? 'draft' : null])), 'values' => ['status' => 'na']]],
						// Has draft; department / team once a form has one
				'compact' => ['columns' => ['actions', 'description', 'code', 'type'], 'card' => ['title' => 'description', 'badge' => 'type', 'lines' => ['code']],
					'sort' => ['description', 'asc'], 'per' => 100, 'filters' => ['shown' => ['type', 'status'], 'values' => ['status' => 'na']]],
				'recent' => ['columns' => ['actions', 'description', 'code', 'type', 'group', 'status', 'opened'], 'card' => ['title' => 'description', 'badge' => 'type', 'lines' => ['code', 'opened']],
					'sort' => ['opened', 'desc'], 'per' => 100, 'filters' => ['shown' => ['type', 'status'], 'values' => ['status' => 'na']]],   // Recently opened
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'description', 'badge' => 'type', 'lines' => ['code', 'table', 'group', 'status']],
					'sort' => ['group', 'asc'], 'per' => 500, 'filters' => ['shown' => array_keys($filters), 'values' => ['status' => 'na']]],
			],
		];
	}
}
