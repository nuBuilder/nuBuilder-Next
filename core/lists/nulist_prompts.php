<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "prompts" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListPrompts
{
	private static function prompts(): array
	{
		$labels = static fn(mixed $v, array $catalog): array => array_map(static fn($c) => $catalog[$c]['label'] ?? $c, NuPrompt::list($v));
		$badges = static fn(array $l, string $cls) => implode('', array_map(static fn($x) => '<span class="badge nuFormType ' . $cls . ' me-1">' . h($x) . '</span>', $l));
		$cols = [
			'code' => ['label' => t('pg_code'), 'main' => true, 'locked' => true, 'select' => ['pge_code' => 'pge_code', 'pge_template' => 'pge_template'], 'sort' => 'pge_code',
				'text' => static fn($r) => (string)$r['pge_code'],
				'html' => static fn($r) => h($r['pge_code']) . ((string)$r['pge_template'] === '1' ? ' <span class="badge nuFormType nuFormType-launch ms-1 fw-normal">' . h(t('pg_template')) . '</span>' : '')],
			'description' => ['label' => t('pg_description'), 'select' => ['pge_description' => 'pge_description', 'pge_prompt' => 'pge_prompt'], 'sort' => 'pge_description', 'class' => 'small text-break',
				'text' => static fn($r) => (string)($r['pge_description'] ?: $r['pge_prompt']), 'html' => static fn($r) => h(mb_strimwidth((string)($r['pge_description'] ?: $r['pge_prompt']), 0, 90, '…'))],
			'languages' => ['label' => t('pg_languages'), 'select' => ['pge_language' => 'pge_language'], 'sort' => null,
				'text' => static fn($r) => implode(', ', $labels($r['pge_language'], NuPrompt::languages())), 'html' => static fn($r) => $badges($labels($r['pge_language'], NuPrompt::languages()), 'nuFormType-browseedit')],
			'scopes' => ['label' => t('pg_scopes'), 'select' => ['pge_scope' => 'pge_scope'], 'sort' => null,
				'text' => static fn($r) => implode(', ', $labels($r['pge_scope'], NuPrompt::scopes())), 'html' => static fn($r) => $badges($labels($r['pge_scope'], NuPrompt::scopes()), 'nuFormType-subform')],
			'tables' => ['label' => t('pg_tables'), 'select' => ['pge_table' => 'pge_table'], 'sort' => null, 'class' => 'small text-break', 'text' => static fn($r) => implode(', ', NuPrompt::list($r['pge_table']))],
			'template' => ['label' => t('pg_template'), 'select' => ['pge_template' => 'pge_template'], 'sort' => 'pge_template', 'align' => 'center', 'text' => static fn($r) => (string)$r['pge_template'] === '1' ? t('yes') : ''],
			'prompt' => self::col('pge_prompt', 'pge_prompt', t('pg_prompt'), 'small text-break', false) + ['html' => static fn($r) => h(mb_strimwidth((string)$r['pge_prompt'], 0, 160, '…'))],
			'id' => self::idCol('zzzzsys_prompt_generator_id'),
		];
		return [
			'key' => 'prompts', 'title' => t('pg_title'), 'route' => 'prompts', 'new_url' => nu_url('prompts', ['id' => 'new']),
				'empty_icon' => 'bi-chat-square-text', 'form' => NuAcl::FORM_PROMPT, 'from' => ' FROM ' . NuPrompt::TABLE, 'where' => [], 'params' => [],
			'id' => 'zzzzsys_prompt_generator_id', 'link' => static fn($r) => nu_url('prompts', ['id' => $r['__id']]),
			'search' => ['pge_code', 'pge_description', 'pge_prompt', 'pge_table', 'pge_tag'], 'columns' => $cols,
			'filters' => ['tpl' => ['label' => t('pg_template'), 'type' => 'select', 'options' => static fn() => ['1' => t('pg_tpl_only'), '0' => t('pg_tpl_none')],
				'where' => static fn(string $v) => $v === '1' ? ["pge_template = '1'", []] : ["COALESCE(pge_template, '0') <> '1'", []]]],
			'builtin' => [
				'default' => ['columns' => ['code', 'description', 'languages', 'scopes', 'tables'], 'card' => ['title' => 'code', 'badge' => '', 'lines' => ['description', 'languages', 'scopes']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['tpl'], 'values' => []]],
				'compact' => ['columns' => ['code', 'description'], 'card' => ['title' => 'code', 'badge' => '', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['tpl'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'template', 'lines' => ['description', 'languages', 'scopes', 'tables']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['tpl'], 'values' => []]],
			],
		];
	}
}
