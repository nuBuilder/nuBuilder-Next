<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "webhooks" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListWebhooks
{
	/** Webhooks (core/nuwebhook.php): name, forms, events, target host, active, calls of the last 24 hours. */
	private static function webhooks(): array
	{
		$W = NuWebhook::T; $C = NuWebhook::CALL;
		$since = date('Y-m-d H:i:s', time() - 86400);
		$nFail = "(SELECT COUNT(*) FROM $C x WHERE x.webhook_id = w.zzzzsys_nunext_webhook_id AND x.status = 'failed' AND x.created_at >= '$since')";
		$nPend = "(SELECT COUNT(*) FROM $C x WHERE x.webhook_id = w.zzzzsys_nunext_webhook_id AND x.status = 'pending')";
		$nSent = "(SELECT COUNT(*) FROM $C x WHERE x.webhook_id = w.zzzzsys_nunext_webhook_id AND x.status = 'sent' AND x.created_at >= '$since')";
		$forms = nu_forms();
		$formsOf = static function ($r) use ($forms): array { $j = json_decode((string)$r['wh_forms'],
			true); return array_map(static fn($f) => (string)($forms[$f]['code'] ?? $f), is_array($j) ? $j : []); };
		$eventsOf = static function ($r): array { $j = json_decode((string)$r['wh_events'], true); return array_map(static fn($e) => t('wh_ev_' . $e),
			array_intersect(NuWebhook::EVENTS, is_array($j) ? $j : [])); };
		$calls = static fn(string $st, array $p) => nu_url('webhooks', ['tab' => 'calls'] + $p + ['status' => $st]);
		$cols = [
			'name' => ['label' => t('wh_name'), 'main' => true, 'locked' => true, 'select' => ['wh_name' => 'w.name'], 'sort' => 'w.name',
				'text' => static fn($r) => (string)$r['wh_name'], 'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['wh_name']) . '</span>'],
			'forms' => ['label' => t('wh_forms'), 'select' => ['wh_forms' => 'w.forms'], 'sort' => null, 'class' => 'small', 'text' => static fn($r) => implode(', ', $formsOf($r))],
			'events' => ['label' => t('wh_events'), 'select' => ['wh_events' => 'w.events'], 'sort' => null, 'class' => 'small', 'text' => static fn($r) => implode(', ', $eventsOf($r))],
			'target' => ['label' => t('wh_target'), 'select' => ['wh_url' => 'w.url'], 'sort' => 'w.url', 'class' => 'small text-break',
				'text' => static fn($r) => NuWebhook::urlForLog((string)$r['wh_url'])],
			'status' => ['label' => t('status'), 'select' => ['wh_active' => 'w.active'], 'sort' => 'w.active',
				'text' => static fn($r) => t((string)$r['wh_active'] === '1' ? 'wh_on' : 'wh_off'),
				'html' => static fn($r) => self::badge(t((string)$r['wh_active'] === '1' ? 'wh_on' : 'wh_off'), (string)$r['wh_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary')],
			'calls' => ['label' => t('wh_calls_24h'), 'select' => ['wh_nsent' => $nSent, 'wh_nfail' => $nFail, 'wh_npend' => $nPend], 'sort' => $nFail, 'class' => 'small text-nowrap',
				'text' => static fn($r) => (int)$r['wh_nsent'] . ' / ' . (int)$r['wh_nfail'] . ' / ' . (int)$r['wh_npend'],
				'html' => static fn($r) => '<a class="text-decoration-none me-2" href="' . h($calls('sent', ['wh' => $r['__id']])) . '" title="' . h(t('wh_st_sent'))
					. '"><i class="bi bi-check2 text-success" aria-hidden="true"></i> ' . (int)$r['wh_nsent'] . '</a>'
					. '<a class="text-decoration-none me-2' . ((int)$r['wh_nfail'] ? ' text-danger fw-semibold' : '') . '" href="'
						. h($calls('failed', ['wh' => $r['__id']])) . '" title="' . h(t('wh_st_failed')) . '"><i class="bi bi-x-circle" aria-hidden="true"></i> '
						. (int)$r['wh_nfail'] . '</a>'
					. '<a class="text-decoration-none" href="' . h($calls('pending', ['wh' => $r['__id']])) . '" title="' . h(t('wh_st_pending')) . '"><i class="bi bi-hourglass-split" aria-hidden="true"></i> ' . (int)$r['wh_npend'] . '</a>'],
			'updated' => ['label' => t('fi_changed'), 'select' => ['wh_upd' => 'w.updated_at'], 'sort' => 'w.updated_at', 'class' => 'small text-nowrap', 'text' => static fn($r) => self::date($r['wh_upd'] ?? null, true)],
			'id' => self::idCol('w.zzzzsys_nunext_webhook_id'),
		];
		$filters = [
			'status' => ['label' => t('status'), 'type' => 'select', 'options' => static fn() => ['on' => t('wh_on'), 'off' => t('wh_off')],
				'where' => static fn(string $v) => $v === 'on' ? ["w.active = '1'", []] : ($v === 'off' ? ["COALESCE(w.active, '0') <> '1'", []] : null)],
			'form' => ['label' => t('wh_form'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($forms): array { $o = []; foreach (NuWebhook::all() as $d) foreach ($d['forms'] as $f) $o[$f] = (string)($forms[$f]['code'] ?? $f); asort($o); return $o; },
				'where' => static fn(string $v) => $v !== '' ? ['w.forms LIKE ?', ['%"' . $v . '"%']] : null],
		];
		$base = ['columns' => ['name', 'forms', 'events', 'target', 'status', 'calls'], 'card' => ['title' => 'name', 'badge' => 'status', 'lines' => ['forms', 'events', 'calls']],
			'sort' => ['name', 'asc'], 'per' => 50, 'filters' => ['shown' => ['status', 'form'], 'values' => []]];
		return [
			'key' => 'webhooks', 'title' => t('wh_title'), 'route' => 'webhooks', 'new_url' => nu_url('webhooks', ['id' => 'new']), 'empty_icon' => 'bi-broadcast', 'form' => NuAcl::FORM_WEBHOOK,
			'from' => " FROM $W w", 'where' => [], 'params' => [], 'id' => 'w.zzzzsys_nunext_webhook_id',
			'link' => static fn($r) => nu_url('webhooks', ['id' => $r['__id']]),
			'search' => ['w.name', 'w.url', 'w.forms'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['name', 'status', 'calls']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
