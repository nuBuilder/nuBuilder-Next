<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "translations" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListTranslations
{
	private static function translations(): array
	{
		$T = NuTranslate::TABLE;
		$nu = static fn($r) => str_starts_with((string)$r['__id'], 'nu');
		$cols = [
			'language' => array_replace(self::col('trl_language', 'trl_language', t('trl_language'), 'small text-nowrap'), ['sort' => 'trl_language, trl_english']),
			'english' => self::col('trl_english', 'trl_english', t('trl_english'), 'text-break') + ['main' => true, 'locked' => true],
			'translation' => self::col('trl_translation', 'trl_translation', t('trl_translation'), 'text-break'),
			'source' => ['label' => t('trl_source'), 'select' => [], 'sort' => null, 'align' => 'end', 'text' => static fn($r) => $nu($r) ? 'nuBuilder Forte' : t('trl_src_own'),
				'html' => static fn($r) => $nu($r) ? self::badge('nuBuilder Forte', 'bg-info-subtle text-info-emphasis') : ''],
			'id' => self::idCol('zzzzsys_translate_id'),
		];
		$filters = [
			'lang' => ['label' => t('trl_language'), 'type' => 'distinct', 'expr' => 'trl_language'],
			'src' => ['label' => t('trl_source'), 'type' => 'select', 'all' => 'all', 'options' => static fn() => ['own' => t('trl_src_own'), 'nu' => t('trl_src_nu')],
				'where' => static fn(string $v) => $v === 'own' ? ["zzzzsys_translate_id NOT LIKE 'nu%'", []] : ($v === 'nu' ? ["zzzzsys_translate_id LIKE 'nu%'", []] : null)],
		];
		return [
			'key' => 'translations', 'title' => t('trl_title'), 'route' => 'translations', 'view_param' => 'lv', 'form' => NuAcl::FORM_TRANSLATE,
			'from' => " FROM $T", 'where' => [], 'params' => [], 'id' => 'zzzzsys_translate_id', 'link' => static fn($r) => nu_url('translations', ['id' => $r['__id']]),
			'search' => ['trl_english', 'trl_translation'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['language', 'english', 'translation', 'source'], 'card' => ['title' => 'english', 'badge' => 'language', 'lines' => ['translation']],
					'sort' => ['language', 'asc'], 'per' => 50, 'filters' => ['shown' => ['lang', 'src'], 'values' => []]],
				'compact' => ['columns' => ['english', 'translation'], 'card' => ['title' => 'english', 'badge' => 'language', 'lines' => ['translation']],
					'sort' => ['english', 'asc'], 'per' => 50, 'filters' => ['shown' => ['lang'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'english', 'badge' => 'language', 'lines' => ['translation', 'source']],
					'sort' => ['language', 'asc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}
}
