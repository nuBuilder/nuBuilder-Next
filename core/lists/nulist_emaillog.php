<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "emaillog" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListEmailLog
{
	private static function emailLog(): array
	{
		$canDel = NuAcl::can(NuAcl::FORM_EMAILLOG, 'delete');
		$stateText = static fn($st) => ($l = t('mail_state_' . $st)) === 'mail_state_' . $st ? (string)$st : $l;
		$cols = [
			'created' => ['label' => t('mail_created'), 'select' => ['eml_created_at' => 'eml_created_at'], 'sort' => 'eml_created_at', 'class' => 'text-nowrap small', 'locked' => true,
				'text' => static fn($r) => self::mailTime($r['eml_created_at'])],
			'state' => ['label' => t('mail_state'), 'select' => ['eml_state' => 'eml_state'], 'sort' => 'eml_state', 'text' => static fn($r) => $stateText($r['eml_state']), 'html' => static fn($r) => self::mailState($r['eml_state'])],
			'to' => self::col('eml_to', 'eml_to', t('mail_to'), 'small text-break') + ['html' => static function ($r): string {
				$to = (string)$r['eml_to'];
				$uid = self::userOfMail($to);   // the address of one user → that user
				return $uid !== '' ? '<a class="nuMailUser" href="' . h(nu_url('user', ['id' => $uid])) . '" title="' . h(t('mail_open_user')) . '">'
					. h(mb_strimwidth($to, 0, 80, '…')) . '</a>' : h(mb_strimwidth($to, 0, 80, '…'));
			}],
			'subject' => ['label' => t('mail_subject'), 'select' => ['eml_subject' => 'eml_subject', 'eml_state' => 'eml_state', 'eml_error' => 'eml_error'],
				'sort' => 'eml_subject', 'class' => 'small text-break',
				'text' => static fn($r) => (string)$r['eml_subject'],
				'html' => static fn($r) => h($r['eml_subject']) . ((string)$r['eml_state'] === 'error' && (string)$r['eml_error'] !== '' ? '<div class="text-danger">' . h(mb_strimwidth((string)$r['eml_error'], 0, 120, '…')) . '</div>' : '')],
			'tag' => self::col('eml_tag', 'eml_tag', t('mail_tag'), 'small text-body-secondary'),
			'error' => self::col('eml_error', 'eml_error', t('mail_error'), 'small text-danger text-break', false),
			'from' => self::col('eml_from', 'eml_from', t('lv_mail_from'), 'small text-break'),
			'delete' => ['label' => '', 'select' => [], 'sort' => null, 'align' => 'end', 'if' => static fn() => $canDel, 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static function ($r): string {
					$back = http_build_query(array_filter(array_intersect_key($_GET, array_flip(['state', 'q', 'from', 'to', 'pg', 'view', 'tag', 'when'])), static fn($v) => is_string($v) && $v !== ''));
					return '<form method="post" class="d-inline" data-confirm="' . h(t('mail_entry_delete_confirm')) . '" data-confirm-ok="' . h(t('delete')) . '" data-confirm-class="btn btn-danger">'
						. nu_csrf_field() . '<input type="hidden" name="action" value="delete_entry"><input type="hidden" name="id" value="' . h($r['__id'])
							. '"><input type="hidden" name="back" value="' . h($back) . '">'
						. '<button class="btn btn-sm btn-link text-danger px-1 py-0" type="submit" title="' . h(t('delete')) . '" aria-label="' . h(t('delete')) . '"><i class="bi bi-trash"></i></button></form>';
				}],
			'id' => self::idCol('zzzzsys_email_log_id'),
		];
		$filters = [
			'state' => ['label' => t('mail_state'), 'type' => 'select', 'options' => static fn() => ['sent' => t('mail_state_sent'), 'error' => t('mail_state_error')],
				'where' => static fn(string $v) => ['eml_state = ?', [$v]]],
			'from' => ['label' => t('log_from'), 'type' => 'datefrom', 'expr' => 'eml_created_at'],
			'to' => ['label' => t('log_to'), 'type' => 'dateto', 'expr' => 'eml_created_at'],
			'tag' => ['label' => t('mail_tag'), 'type' => 'distinct', 'expr' => 'eml_tag', 'none' => t('lv_empty')],
			'when' => ['label' => t('lv_period'), 'type' => 'date', 'expr' => 'eml_created_at', 'presets' => ['d7', 'd30', 'd90']],
		];
		return [
			'key' => 'emaillog', 'title' => t('mail_tab_log'), 'route' => 'email', 'route_params' => ['tab' => 'log'], 'empty_icon' => 'bi-envelope-paper', 'form' => NuAcl::FORM_EMAILLOG,
			'from' => ' FROM ' . NuMailer::LOG_TABLE, 'where' => [], 'params' => [], 'id' => 'zzzzsys_email_log_id',
			'link' => static fn($r) => nu_url('email', ['tab' => 'log', 'id' => $r['__id']]),
			'search' => ['eml_to', 'eml_cc', 'eml_subject', 'eml_tag', 'eml_from'], 'search_ph' => t('mail_search_ph'), 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['created', 'state', 'to', 'subject', 'tag', 'delete'], 'card' => ['title' => 'subject', 'badge' => 'state', 'lines' => ['created', 'to']],
					'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => ['state', 'tag', 'from', 'to'], 'values' => []]],
				'compact' => ['columns' => ['created', 'state', 'subject'], 'card' => ['title' => 'subject', 'badge' => 'state', 'lines' => ['created']],
					'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => ['state', 'when'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'subject', 'badge' => 'state', 'lines' => ['created', 'to', 'from', 'error']],
					'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}
}
