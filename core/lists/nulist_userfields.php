<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "userfields" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListUserFields
{
	private static function userFields(): array
	{
		NuSchema::ensure();
		$T = NuUserFields::TABLE;
		$canChange = NuAdmin::can('userfields', 'change') && empty(NuAuth::user()['demo']);
		$yes = static fn(string $a) => static fn($r) => (string)$r[$a] === '1' ? t('yes') : '';
		$flag = static fn(string $a) => static fn($r) => (string)$r[$a] === '1' ? '<span class="text-success">✔</span>' : '';
		$move = static function ($r): string {
			$out = '';
			foreach (['up' => ['bi-arrow-up', 'home_move_up'], 'down' => ['bi-arrow-down', 'home_move_down']] as $dir => [$ic, $lbl]) {
				$out .= '<form method="post" action="' . h(nu_url('userfields')) . '" class="d-inline">' . nu_csrf_field() . '<input type="hidden" name="action" value="move">'
					. '<input type="hidden" name="id" value="' . h((string)$r['__id']) . '"><input type="hidden" name="dir" value="' . $dir . '">'
					. '<button class="btn btn-sm btn-outline-secondary nuRowBtn" type="submit" title="' . h(t($lbl)) . '" aria-label="' . h(t($lbl)) . '"><i class="bi ' . $ic . '"></i></button></form> ';
			}
			return $out;
		};
		$cols = [
			'order' => ['label' => '', 'select' => ['uf_sort' => 'sort'], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'noexport' => true, 'if' => static fn() => $canChange,
				'text' => static fn($r) => '', 'html' => $move],
			'label' => ['label' => t('uf_label'), 'main' => true, 'locked' => true, 'select' => ['uf_label' => 'label'], 'sort' => 'label',
				'text' => static fn($r) => NuTranslate::text((string)$r['uf_label'])],
			'code' => ['label' => t('uf_code'), 'select' => ['uf_code' => 'code'], 'sort' => 'code', 'class' => 'small font-monospace',
				'text' => static fn($r) => '#USER_' . strtoupper((string)$r['uf_code']) . '#'],
			'type' => ['label' => t('uf_type'), 'select' => ['uf_type' => 'type'], 'sort' => 'type', 'class' => 'small', 'text' => static fn($r) => t('uf_type_' . $r['uf_type'])],
			'edit_by' => ['label' => t('uf_edit_by'), 'select' => ['uf_edit_by' => 'edit_by'], 'sort' => 'edit_by', 'class' => 'small', 'text' => static fn($r) => t('uf_edit_' . $r['uf_edit_by'])],
			'required' => ['label' => t('uf_required'), 'select' => ['uf_required' => 'required'], 'sort' => 'required', 'align' => 'center', 'text' => $yes('uf_required'), 'html' => $flag('uf_required')],
			'unique' => ['label' => t('uf_unique'), 'select' => ['uf_unique' => 'is_unique'], 'sort' => 'is_unique', 'align' => 'center', 'text' => $yes('uf_unique'), 'html' => $flag('uf_unique')],
			'in_list' => ['label' => t('uf_in_list'), 'select' => ['uf_in_list' => 'in_list'], 'sort' => 'in_list', 'align' => 'center', 'text' => $yes('uf_in_list'), 'html' => $flag('uf_in_list')],
			'default' => self::col('uf_default', 'default_value', t('uf_default'), 'small text-break'),
			'hint' => self::col('uf_hint', 'hint', t('uf_hint'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['uf_hint'], 0, 90, '…'))],
			'sort' => self::col('uf_sort2', 'sort', t('lv_sort_order'), 'small') + ['align' => 'end'],
			'updated' => ['label' => t('fi_changed'), 'select' => ['uf_updated' => 'updated_at'], 'sort' => 'updated_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => $r['uf_updated'] ? date(nu_dfmt('d.m.Y H:i'), strtotime((string)$r['uf_updated'])) : ''],
			'id' => self::idCol('zzzzsys_nunext_user_field_id'),
		];
		$filters = [
			'type' => ['label' => t('uf_type'), 'type' => 'select',
				'options' => static fn() => array_combine(array_keys(NuUserFields::TYPES), array_map(static fn($k) => t('uf_type_' . $k), array_keys(NuUserFields::TYPES))),
				'where' => static fn(string $v) => isset(NuUserFields::TYPES[$v]) ? ['type = ?', [$v]] : null],
			'edit_by' => ['label' => t('uf_edit_by'), 'type' => 'select',
				'options' => static fn() => array_combine(NuUserFields::EDIT_BY, array_map(static fn($k) => t('uf_edit_' . $k), NuUserFields::EDIT_BY)),
				'where' => static fn(string $v) => in_array($v, NuUserFields::EDIT_BY, true) ? ['edit_by = ?', [$v]] : null],
		];
		$base = ['columns' => ['order', 'label', 'code', 'type', 'edit_by', 'required', 'unique'], 'card' => ['title' => 'label', 'badge' => 'type', 'lines' => ['code', 'edit_by']],
			'sort' => ['sort', 'asc'], 'per' => 50, 'filters' => ['shown' => ['type', 'edit_by'], 'values' => []]];
		return [
			'key' => 'userfields', 'title' => t('uf_title'), 'route' => 'userfields', 'new_url' => nu_url('userfields', ['id' => 'new']), 'empty_icon' => 'bi-ui-checks', 'form' => NuAcl::FORM_USERFIELD,
			'from' => " FROM $T", 'where' => [], 'params' => [], 'id' => 'zzzzsys_nunext_user_field_id',
			'link' => static fn($r) => nu_url('userfields', ['id' => $r['__id']]),
			'search' => ['code', 'label', 'hint'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['label', 'code', 'type']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
