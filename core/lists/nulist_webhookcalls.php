<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "webhookcalls" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListWebhookCalls
{
	/** Calls of the webhooks: time, webhook, event, form, record, status, attempts, last answer, next attempt. */
	private static function webhookCalls(): array
	{
		$W = NuWebhook::T; $C = NuWebhook::CALL;
		$forms = nu_forms();
		$stCls = ['sent' => 'text-bg-success', 'failed' => 'text-bg-danger', 'pending' => 'text-bg-warning'];
		$cols = [
			'created' => ['label' => t('wh_time'), 'main' => true, 'select' => ['whc_at' => 'c.created_at'], 'sort' => 'c.created_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => self::date($r['whc_at'] ?? null, true)],
			'webhook' => self::col('whc_name', 'w.name', t('wh_name'), 'fw-semibold'),
			'event' => ['label' => t('wh_event'), 'select' => ['whc_ev' => 'c.event'], 'sort' => 'c.event', 'class' => 'small', 'text' => static fn($r) => t('wh_ev_' . $r['whc_ev'])],
			'form' => ['label' => t('wh_form'), 'select' => ['whc_form' => 'c.form_id'], 'sort' => 'c.form_id', 'class' => 'small', 'text' => static fn($r) => (string)($forms[$r['whc_form']]['code'] ?? $r['whc_form'])],
			'record' => array_replace(self::col('whc_rec', 'c.record_id', t('wh_record'), 'small font-monospace text-break'), ['sort' => null]),
			'status' => ['label' => t('status'), 'select' => ['whc_st' => 'c.status'], 'sort' => 'c.status',
				'text' => static fn($r) => t('wh_st_' . $r['whc_st']), 'html' => static fn($r) => self::badge(t('wh_st_' . $r['whc_st']), $stCls[(string)$r['whc_st']] ?? 'text-bg-secondary')],
			'attempts' => ['label' => t('wh_attempts'), 'select' => ['whc_n' => 'c.attempts'], 'sort' => 'c.attempts', 'align' => 'end', 'class' => 'small', 'text' => static fn($r) => (string)(int)$r['whc_n']],
			'answer' => ['label' => t('wh_answer'), 'select' => ['whc_code' => 'c.last_code', 'whc_err' => 'c.last_error'], 'sort' => 'c.last_code', 'class' => 'small text-break',
				'text' => static fn($r) => trim(((int)$r['whc_code'] ? (int)$r['whc_code'] . ' ' : '') . ((string)$r['whc_err'] !== 'HTTP ' . (int)$r['whc_code'] ? (string)$r['whc_err'] : ''))],
			'next' => ['label' => t('wh_next'), 'select' => ['whc_next' => 'c.next_at'], 'sort' => 'c.next_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => (string)$r['whc_st'] === 'pending' ? self::date($r['whc_next'] ?? null, true) : ''],
			'source' => ['label' => t('wh_source'), 'select' => ['whc_src' => 'c.source'], 'sort' => 'c.source', 'class' => 'small', 'text' => static fn($r) => t('wh_src_' . ((string)$r['whc_src'] ?: 'form'))],
			'user' => self::col('whc_by', 'c.created_by', t('user'), 'small'),
			'id' => self::idCol('c.zzzzsys_nunext_webhook_call_id'),
		];
		$filters = [
			'status' => ['label' => t('status'), 'type' => 'select', 'options' => static fn() => ['pending' => t('wh_st_pending'), 'sent' => t('wh_st_sent'), 'failed' => t('wh_st_failed')],
				'where' => static fn(string $v) => in_array($v, ['pending', 'sent', 'failed'], true) ? ['c.status = ?', [$v]] : null],
			'wh' => ['label' => t('wh_name'), 'type' => 'select', 'options' => static fn() => array_map(static fn($d) => $d['name'], NuWebhook::all()),
				'where' => static fn(string $v) => $v !== '' ? ['c.webhook_id = ?', [$v]] : null],
			'event' => ['label' => t('wh_event'), 'type' => 'select', 'options' => static fn() => array_combine(NuWebhook::EVENTS, array_map(static fn($e) => t('wh_ev_' . $e), NuWebhook::EVENTS)),
				'where' => static fn(string $v) => in_array($v, NuWebhook::EVENTS, true) ? ['c.event = ?', [$v]] : null],
			'created' => ['label' => t('wh_time'), 'type' => 'date', 'expr' => 'c.created_at', 'presets' => ['d7', 'd30']],
		];
		$base = ['columns' => ['created', 'webhook', 'event', 'form', 'record', 'status', 'attempts', 'answer', 'next'],
			'card' => ['title' => 'webhook', 'badge' => 'status', 'lines' => ['created', 'event', 'record', 'answer']],
			'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => ['status', 'wh', 'event', 'created'], 'values' => []]];
		return [
			'key' => 'webhookcalls', 'title' => t('wh_calls'), 'route' => 'webhooks', 'route_params' => ['tab' => 'calls'], 'empty_icon' => 'bi-arrow-left-right', 'form' => NuAcl::FORM_WEBHOOK,
			'from' => " FROM $C c LEFT JOIN $W w ON w.zzzzsys_nunext_webhook_id = c.webhook_id", 'where' => [], 'params' => [], 'id' => 'c.zzzzsys_nunext_webhook_call_id',
			'link' => static fn($r) => nu_url('webhooks', ['call' => $r['__id']]),
			'search' => ['w.name', 'c.record_id', 'c.last_error', 'c.form_id'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
