<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "emailtemplates" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListEmailTemplates
{
	/** E-mail templates (was a hand-written table – shown on the E-mail page's tab Templates). */
	private static function emailTemplates(): array
	{
		$T = NuEmailTemplate::TABLE;
		$welcome = static fn(array $r): bool => ((string)$r['emt_code'] === NuWelcome::TEMPLATE && (string)$r['emt_template'] !== '1')
			|| ((string)$r['emt_code'] === NuWelcome::STD && (string)$r['emt_group'] === NuWelcome::STD_GROUP);
		$cols = [
			'code' => ['label' => t('emt_code'), 'main' => true, 'locked' => true,
				'select' => ['emt_code' => 't.emt_code', 'emt_template' => 't.emt_template', 'emt_description' => 't.emt_description', 'emt_group' => 't.emt_group'], 'sort' => 't.emt_code, t.emt_language',
				'text' => static fn($r) => (string)$r['emt_code'],
				// read-only template · used by "Send welcome e-mail"
				'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['emt_code']) . '</span>'
					. ((string)$r['emt_template'] === '1' ? ' <span class="badge text-bg-light border">' . h(t('emt_template_badge')) . '</span>' : '')
					. ($welcome($r) ? ' <span class="badge bg-success-subtle text-success-emphasis" title="' . h(t('emt_welcome_badge_tip'))
						. '"><i class="bi bi-envelope-heart me-1" aria-hidden="true"></i>' . h(t('emt_welcome_badge')) . '</span>' : '')
					. ((string)$r['emt_description'] !== '' ? '<div class="small text-body-secondary fw-normal nuClamp2">' . h((string)$r['emt_description']) . '</div>' : '')],
			'group' => array_replace(self::badgeCol('emt_group2', 't.emt_group', t('emt_group')), ['sort' => 't.emt_group, t.emt_code']),
			'category' => self::col('emt_category', 't.emt_category', t('emt_category'), 'small'),
			'language' => self::col('emt_language', 't.emt_language', t('emt_language'), 'small'),
			// the form opens
			'form' => ['label' => t('emt_form'), 'select' => ['sfo_code' => 'f.sfo_code', 'emt_form_id' => 't.emt_form_id'], 'sort' => 'f.sfo_code', 'class' => 'small',
				'text' => static fn($r) => (string)($r['sfo_code'] ?? $r['emt_form_id'] ?? ''),
				'html' => static fn($r) => (string)($r['emt_form_id'] ?? '') === '' ? '' : (NuAcl::can((string)$r['emt_form_id'])
					? '<a class="text-decoration-none" href="' . h(nu_url('form', ['f' => (string)$r['emt_form_id']])) . '">' . h((string)($r['sfo_code'] ?? $r['emt_form_id'])) . '</a>' : h((string)($r['sfo_code'] ?? $r['emt_form_id'])))],
			'subject' => self::col('emt_subject', 't.emt_subject', t('mail_subject'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['emt_subject'], 0, 80, '…'))],
			'to' => self::col('emt_to', 't.emt_to', t('mail_to'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['emt_to'], 0, 50, '…'))],
			'id' => self::idCol('t.zzzzsys_email_template_id'),
		];
		$filters = [
			'langcur' => ['label' => t('lv_lang_cur'), 'type' => 'select', 'options' => static fn() => ['cur' => t('lv_lang_cur_only')],   // current language + no language
				'where' => static fn(string $v) => $v === 'cur' ? ["COALESCE(t.emt_language, '') IN ('', ?)", [NU_LANGS[nu_lang()][1] ?? 'English']] : null],
			'origin' => ['label' => t('lv_origin'), 'type' => 'select', 'options' => static fn() => ['own' => t('lv_origin_own'), 'system' => t('lv_origin_sys')],   // system rows hidden by default
				'where' => static fn(string $v) => match ($v) { 'own' => ["t.zzzzsys_email_template_id NOT LIKE 'nu%' AND t.zzzzsys_email_template_id NOT LIKE 'nx\\_%'", []], 'system' => ["(t.zzzzsys_email_template_id LIKE 'nu%' OR t.zzzzsys_email_template_id LIKE 'nx\\_%')", []], default => null }],
			'group' => ['label' => t('emt_group'), 'type' => 'distinct', 'expr' => 't.emt_group', 'none' => t('lv_empty')],
			'category' => ['label' => t('emt_category'), 'type' => 'distinct', 'expr' => 't.emt_category', 'none' => t('lv_empty')],
			'language' => ['label' => t('emt_language'), 'type' => 'distinct', 'expr' => 't.emt_language', 'none' => t('lv_empty')],
			'form' => ['label' => t('emt_form'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($T): array { $o = []; foreach (nu_db()->all("SELECT DISTINCT f.zzzzsys_form_id, f.sfo_code, f.sfo_description FROM $T t JOIN zzzzsys_form f ON f.zzzzsys_form_id = t.emt_form_id ORDER BY f.sfo_code") as $f) $o[(string)$f['zzzzsys_form_id']] = [(string)$f['sfo_code'], (string)$f['sfo_description']]; return $o; },
				'where' => static fn(string $v) => ['t.emt_form_id = ?', [$v]]],
			'template' => ['label' => t('emt_template_badge'), 'type' => 'bool', 'expr' => "t.emt_template = '1'"],
		];
		$base = ['columns' => ['code', 'group', 'category', 'language', 'form', 'subject', 'to'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['language', 'subject']],
			'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['langcur', 'origin', 'group', 'category', 'language', 'form'], 'values' => ['langcur' => 'cur']]];
		if ($pf = NuProject::listFilter('emailtemplate', 't.zzzzsys_email_template_id')) $filters['project'] = $pf;   // projects
		return [
			'key' => 'emailtemplates', 'title' => t('emt_title'), 'route' => 'emailtemplates', 'form' => NuAcl::FORM_EMAILTEMPLATE,
			'from' => " FROM $T t LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = t.emt_form_id", 'where' => [], 'params' => [], 'id' => 't.zzzzsys_email_template_id',
			'link' => static fn($r) => nu_url('emailtemplates', ['id' => $r['__id']]),
			'search' => ['t.emt_code', 't.emt_description', 't.emt_subject', 't.emt_to'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['code', 'language', 'subject']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
