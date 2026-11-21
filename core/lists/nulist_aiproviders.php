<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "aiproviders" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListAiProviders
{
	/** AI providers (core/nuaiprovider.php): name, service, model, who may use it, active, usage of the month. */
	private static function aiProviders(): array
	{
		$P = NuAiProvider::T; $C = NuAiProvider::CALL;
		$month = date('Y-m-01 00:00:00');
		$nCalls = "(SELECT COUNT(*) FROM $C x WHERE x.provider_id = p.zzzzsys_nunext_ai_provider_id AND x.created_at >= '$month')";
		$nTok = "(SELECT COALESCE(SUM(x.tokens_in + x.tokens_out), 0) FROM $C x WHERE x.provider_id = p.zzzzsys_nunext_ai_provider_id AND x.created_at >= '$month')";
		$num = static fn($v) => number_format((int)$v, 0, '.', "'");
		$cols = [
			'name' => ['label' => t('aip_name'), 'main' => true, 'locked' => true, 'select' => ['aip_name' => 'p.name'], 'sort' => 'p.name',
				'text' => static fn($r) => (string)$r['aip_name'], 'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['aip_name']) . '</span>'],
			'kind' => ['label' => t('aip_kind'), 'select' => ['aip_kind' => 'p.kind'], 'sort' => 'p.kind', 'class' => 'small',
				'text' => static fn($r) => isset(NuAiProvider::KINDS[(string)$r['aip_kind']]) ? t(NuAiProvider::KINDS[(string)$r['aip_kind']][0]) : (string)$r['aip_kind']],
			'model' => ['label' => t('aip_model'), 'select' => ['aip_model' => 'p.model'], 'sort' => 'p.model', 'class' => 'small font-monospace text-break', 'text' => static fn($r) => (string)$r['aip_model']],
			'access' => ['label' => t('aip_access'), 'select' => ['aip_access' => 'p.access'], 'sort' => 'p.access', 'class' => 'small',
				'text' => static fn($r) => t('aip_access_' . (in_array((string)$r['aip_access'], NuAiProvider::ACCESS, true) ? (string)$r['aip_access'] : 'all'))],
			'status' => ['label' => t('status'), 'select' => ['aip_active' => 'p.active'], 'sort' => 'p.active',
				'text' => static fn($r) => t((string)$r['aip_active'] === '1' ? 'wh_on' : 'wh_off'),
				'html' => static fn($r) => self::badge(t((string)$r['aip_active'] === '1' ? 'wh_on' : 'wh_off'), (string)$r['aip_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary')],
			'calls' => ['label' => t('aip_calls_month'), 'select' => ['aip_ncalls' => $nCalls], 'sort' => $nCalls, 'class' => 'small text-end', 'text' => static fn($r) => $num($r['aip_ncalls'])],
			'tokens' => ['label' => t('aip_tokens_month'), 'select' => ['aip_ntok' => $nTok, 'aip_limit' => 'p.month_limit'], 'sort' => $nTok, 'class' => 'small text-end text-nowrap',
				'text' => static fn($r) => $num($r['aip_ntok']) . ((int)$r['aip_limit'] > 0 ? ' / ' . $num($r['aip_limit']) : '')],
			'updated' => ['label' => t('fi_changed'), 'select' => ['aip_upd' => 'p.updated_at'], 'sort' => 'p.updated_at', 'class' => 'small text-nowrap', 'text' => static fn($r) => self::date($r['aip_upd'] ?? null, true)],
			'id' => self::idCol('p.zzzzsys_nunext_ai_provider_id'),
		];
		$filters = [
			'status' => ['label' => t('status'), 'type' => 'select', 'options' => static fn() => ['on' => t('wh_on'), 'off' => t('wh_off')],
				'where' => static fn(string $v) => $v === 'on' ? ["p.active = '1'", []] : ($v === 'off' ? ["COALESCE(p.active, '0') <> '1'", []] : null)],
		];
		$base = ['columns' => ['name', 'kind', 'model', 'access', 'status', 'calls', 'tokens'], 'card' => ['title' => 'name', 'badge' => 'status', 'lines' => ['kind', 'model', 'tokens']],
			'sort' => ['name', 'asc'], 'per' => 50, 'filters' => ['shown' => [], 'values' => []]];
		return [
			'key' => 'aiproviders', 'title' => t('aip_title'), 'route' => 'aiproviders', 'new_url' => nu_url('aiproviders', ['id' => 'new']), 'empty_icon' => 'bi-stars', 'form' => NuAcl::FORM_AIPROVIDER,
			'from' => " FROM $P p", 'where' => [], 'params' => [], 'id' => 'p.zzzzsys_nunext_ai_provider_id',
			'link' => static fn($r) => nu_url('aiproviders', ['id' => $r['__id']]),
			'search' => ['p.name', 'p.model', 'p.kind'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
