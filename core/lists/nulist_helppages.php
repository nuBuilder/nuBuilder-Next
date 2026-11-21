<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "helppages" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListHelpPages
{
	private static function helpPages(): array
	{
		$T = NuUserHelp::T;
		$levels = [];
		foreach (NuAcl::levels() as $a) $levels[(string)$a['zzzzsys_access_id']] = (string)$a['sal_code'];
		$usage = NuUserHelp::usage();
		$titles = array_map(static fn($b) => (string)$b['title'], NuUserHelp::bases());
		$accOf = static function ($r) use ($levels): array { $j = json_decode((string)$r['hp_acc'], true); return array_map(static fn($x) => $levels[$x] ?? (string)$x, is_array($j) ? $j : []); };
		$cols = [
			'title' => ['label' => t('uh_f_title'), 'main' => true, 'locked' => true, 'select' => ['hp_title' => 'h.title', 'hp_code' => 'h.code'], 'sort' => 'h.title',
				'text' => static fn($r) => (string)$r['hp_title'],
				'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['hp_title']) . '</span><div class="small text-body-secondary font-monospace">' . h((string)$r['hp_code']) . '</div>'],
			'code' => self::col('hp_code2', 'h.code', t('uh_f_code'), 'small font-monospace'),
			'parent' => ['label' => t('uh_f_parent'), 'select' => ['hp_parent' => 'h.parent'], 'sort' => 'h.parent', 'class' => 'small',
				'text' => static fn($r) => (string)$r['hp_parent'] !== '' ? ($titles[(string)$r['hp_parent']] ?? (string)$r['hp_parent']) : ''],
			'order' => array_replace(self::col('hp_sort', 'h.sort_order', t('uh_f_order'), 'small'), ['align' => 'end']),
			'langs' => ['label' => t('uh_translations'), 'select' => [], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => implode(', ', NuUserHelp::langsOf((string)$r['hp_code']))],
			'access' => ['label' => t('uh_f_access'), 'select' => ['hp_acc' => 'h.access'], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => ($a = $accOf($r)) ? implode(', ', $a) : t('uh_access_all'),
				'html' => static fn($r) => ($a = $accOf($r)) ? implode(' ', array_map(static fn($x) => self::badge($x), $a)) : '<span class="text-body-secondary">' . h(t('uh_access_all')) . '</span>'],
			'used' => ['label' => t('uh_used'), 'select' => [], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => implode('; ', array_map(static fn($u) => $u[1] . ' – ' . $u[2], $usage[(string)$r['hp_code']] ?? []))],
			'status' => ['label' => t('status'), 'select' => ['hp_active' => 'h.active'], 'sort' => 'h.active',
				'text' => static fn($r) => t((string)$r['hp_active'] === '1' ? 'uh_active' : 'uh_inactive'),
				'html' => static fn($r) => self::badge(t((string)$r['hp_active'] === '1' ? 'uh_active' : 'uh_inactive'), (string)$r['hp_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary')],
			'updated' => ['label' => t('fi_changed'), 'select' => ['hp_upd' => 'h.updated_at'], 'sort' => 'h.updated_at', 'class' => 'small text-nowrap', 'text' => static fn($r) => self::date($r['hp_upd'] ?? null, true)],
			'id' => self::idCol('h.zzzzsys_nunext_help_id'),
		];
		$filters = [
			'status' => ['label' => t('status'), 'type' => 'select', 'options' => static fn() => ['on' => t('uh_active'), 'off' => t('uh_inactive')],
				'where' => static fn(string $v) => $v === 'on' ? ["h.active = '1'", []] : ($v === 'off' ? ["COALESCE(h.active, '0') <> '1'", []] : null)],
			'level' => ['label' => t('uh_f_access'), 'type' => 'select', 'options' => static fn() => ['*' => t('uh_access_all')] + $levels,
				'where' => static fn(string $v) => $v === '*' ? ["COALESCE(h.access, '') = ''", []] : ($v !== '' ? ['h.access LIKE ?', ['%"' . $v . '"%']] : null)],
			'parent' => ['label' => t('uh_f_parent'), 'type' => 'select', 'options' => static fn() => $titles,
				'where' => static fn(string $v) => $v !== '' ? ['h.parent = ?', [$v]] : null],
		];
		$base = ['columns' => ['title', 'parent', 'langs', 'access', 'used', 'status'], 'card' => ['title' => 'title', 'badge' => 'status', 'lines' => ['parent', 'access', 'used']],
			'sort' => ['title', 'asc'], 'per' => 50, 'filters' => ['shown' => ['status', 'level'], 'values' => []]];
		return [
			'key' => 'helppages', 'title' => t('uh_admin_title'), 'route' => 'helppages', 'new_url' => nu_url('helppages', ['id' => 'new']),
				'empty_icon' => 'bi-journal-bookmark', 'form' => NuAcl::FORM_USERHELP,
			'from' => " FROM $T h", 'where' => ["h.lang = ''"], 'params' => [], 'id' => 'h.zzzzsys_nunext_help_id',
			'link' => static fn($r) => nu_url('helppages', ['id' => $r['__id']]),
			'search' => ['h.title', 'h.code', 'h.keywords'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['title', 'status']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
