<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "items" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListItems
{
	private static function items(): array
	{
		NuItem::ensureTable();
		$denied = (string)(NuMailer::setupRow()['set_denied'] ?? '') === '1';
		$upd = static fn($r) => ($u = $r['itm_updated_on'] ?: $r['itm_created_on']) ? date(nu_dfmt('d.m.Y H:i'), strtotime((string)$u)) : '';
		$cols = [
			'form' => array_replace(self::col('sfo_code', 'f.sfo_code', t('item_form_code'), 'small'), ['sort' => 'f.sfo_code, o.sob_all_id, i.itm_group, i.itm_sort_order, i.itm_value',
				'select' => ['sfo_code' => 'f.sfo_code', 'lv_item_form' => 'f.zzzzsys_form_id'],   // the form opens
				'html' => static fn($r) => (string)($r['sfo_code'] ?? '') !== '' && NuAcl::can((string)$r['lv_item_form'])
					? '<a class="text-decoration-none" href="' . h(nu_url('form', ['f' => (string)$r['lv_item_form']])) . '">' . h((string)$r['sfo_code']) . '</a>' : h((string)($r['sfo_code'] ?? ''))]),
			'object' => self::col('sob_all_id', 'o.sob_all_id', t('item_object'), 'small'),
			'value' => ['label' => t('item_value'), 'main' => true, 'locked' => true,
				'select' => ['itm_value' => 'i.itm_value', 'itm_icon' => 'i.itm_icon', 'lv_item_desc' => 'i.itm_description'], 'sort' => 'i.itm_value',
				'class' => 'text-break',
				'text' => static fn($r) => (string)$r['itm_value'],
				// review N1: a value that is only an id (nuBuilder Forte's own items) – the description is the title, the id a small line
				'html' => static fn($r) => ((string)$r['itm_icon'] !== '' ? '<i class="' . h($r['itm_icon']) . ' me-1"></i>' : '')
					. (preg_match('/^[a-z]{0,3}[0-9a-f]{12,}$/i', (string)$r['itm_value']) && trim((string)($r['lv_item_desc'] ?? '')) !== ''
						? h(mb_strimwidth(trim((string)$r['lv_item_desc']), 0, 80, '…')) . '<div class="small text-body-secondary fw-normal font-monospace nuItemId">' . h($r['itm_value']) . '</div>'
						: h($r['itm_value']))],
			'description' => self::col('itm_description', 'i.itm_description', t('item_description'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['itm_description'], 0, 90, '…'))],
			'group' => self::badgeCol('itm_group', 'i.itm_group', t('item_group')),
			'category' => self::col('itm_category', 'i.itm_category', t('item_category'), 'small'),
			'tags' => self::col('itm_tag', 'i.itm_tag', t('item_tags'), 'small'),
			'language' => self::col('itm_language', 'i.itm_language', t('item_language'), 'small'),
			'sort_order' => self::col('itm_sort_order', 'i.itm_sort_order', t('lv_sort_order'), 'small') + ['align' => 'end'],
			'active' => ['label' => t('item_active'), 'select' => ['itm_active' => 'i.itm_active'], 'sort' => 'i.itm_active', 'align' => 'center',
				'text' => static fn($r) => (string)$r['itm_active'] === '0' ? t('no') : t('yes'),
				'html' => static fn($r) => (string)$r['itm_active'] === '0' ? '<span class="text-danger">✖</span>' : '<span class="text-success">✔</span>'],
			'updated' => ['label' => t('item_updated_on'), 'select' => ['itm_updated_on' => 'i.itm_updated_on', 'itm_created_on' => 'i.itm_created_on'], 'sort' => 'i.itm_updated_on', 'class' => 'small text-nowrap', 'text' => $upd],
			'id' => self::idCol('i.zzzzsys_item_id'),
		];
		$filters = [
			'origin' => ['label' => t('lv_origin'), 'type' => 'select', 'options' => static fn() => ['own' => t('lv_origin_own'), 'system' => t('lv_origin_sys')],   // system rows hidden by default
				'where' => static fn(string $v) => match ($v) { 'own' => ["i.zzzzsys_item_id NOT LIKE 'nu%' AND i.zzzzsys_item_id NOT LIKE 'nx\\_%'", []], 'system' => ["(i.zzzzsys_item_id LIKE 'nu%' OR i.zzzzsys_item_id LIKE 'nx\\_%')", []], default => null }],
			'group' => ['label' => t('item_group'), 'type' => 'distinct', 'expr' => 'i.itm_group', 'none' => t('lv_empty')],
			'active' => ['label' => t('item_active'), 'type' => 'select', 'all' => 'all',
				'options' => static fn() => ['1' => '✔ ' . t('item_active_only'), '0' => '✖ ' . t('item_inactive_only')],
				'where' => static fn(string $v) => $v === '1' ? ["COALESCE(i.itm_active, '1') <> '0'", []] : ($v === '0' ? ["i.itm_active = '0'", []] : null)],
			'category' => ['label' => t('item_category'), 'type' => 'distinct', 'expr' => 'i.itm_category', 'none' => t('lv_empty')],
			'language' => ['label' => t('item_language'), 'type' => 'distinct', 'expr' => 'i.itm_language', 'none' => t('lv_empty')],
			'form' => ['label' => t('item_form_code'), 'type' => 'distinct', 'expr' => 'f.sfo_code', 'none' => t('lv_empty')],
		];
		return [
			'key' => 'items', 'title' => t('item_title'), 'route' => 'items', 'form' => NuAcl::FORM_ITEM,
			'from' => ' FROM zzzzsys_item i LEFT JOIN zzzzsys_object o ON o.zzzzsys_object_id = i.itm_object_id LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id',
			'where' => $denied ? ["i.zzzzsys_item_id NOT LIKE 'nu%'"] : [], 'params' => [],
			'id' => 'i.zzzzsys_item_id', 'link' => static fn($r) => nu_url('items', ['id' => $r['__id']]),
			'search' => ['i.itm_value', 'i.itm_description', 'i.itm_category', 'i.itm_tag', 'i.itm_language', 'o.sob_all_id', 'f.sfo_code'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['form', 'object', 'value', 'description', 'group', 'category', 'tags', 'language', 'active', 'updated'],
					'card' => ['title' => 'value', 'badge' => 'group', 'lines' => ['description', 'form', 'object']],
					'sort' => ['form', 'asc'], 'per' => 50, 'filters' => ['shown' => ['origin', 'group', 'active', 'form'], 'values' => ['active' => '1', 'origin' => 'own']]],   // form
				'compact' => ['columns' => ['value', 'description', 'group'], 'card' => ['title' => 'value', 'badge' => 'group', 'lines' => ['description']],
					'sort' => ['value', 'asc'], 'per' => 50, 'filters' => ['shown' => ['origin', 'group', 'active'], 'values' => ['active' => '1', 'origin' => 'own']]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'value', 'badge' => 'group', 'lines' => ['description', 'form', 'object', 'category']],
					'sort' => ['form', 'asc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => ['active' => '1', 'origin' => 'own']]],
			],
		];
	}
}
