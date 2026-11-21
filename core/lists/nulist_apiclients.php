<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "apiclients" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListApiClients
{
	private static function apiClients(): array
	{
		NuApiClient::ensure();
		$db = nu_db();
		$C = NuApiClient::CLIENT;
		$now = date('Y-m-d H:i:s');
		$state = static fn($r) => (string)$r['api_active'] !== '1' ? 'off' : ($r['api_expires'] && nu_is_expired($r['api_expires']) ? 'expired' : 'on');
		$scopes = static fn($r) => NuApiClient::scopesOf(['scopes' => $r['api_scopes']]);
		$dt = static fn(string $a, string $fmt = 'd.m.Y H:i') => static fn($r) => $r[$a] ? date(nu_dfmt($fmt), strtotime((string)$r[$a])) : '';
		$cols = [
			'name' => ['label' => t('api_name'), 'main' => true, 'locked' => true, 'select' => ['api_name' => 'c.name'], 'sort' => 'c.name', 'text' => static fn($r) => (string)$r['api_name']],
			'client_id' => self::col('api_client_id', 'c.client_id', 'client_id', 'small font-monospace text-break'),
			'user' => ['label' => t('api_user'), 'select' => ['api_login' => 'u.sus_login_name', 'api_access' => 'a.sal_code'], 'sort' => 'u.sus_login_name', 'class' => 'small',
				'text' => static fn($r) => trim((string)$r['api_login'] . ((string)$r['api_access'] !== '' ? ' · ' . $r['api_access'] : '')),
				'html' => static fn($r) => h((string)($r['api_login'] ?? '–')) . ((string)$r['api_access'] !== '' ? ' ' . self::badge((string)$r['api_access'], 'bg-secondary-subtle text-secondary-emphasis border') : '')],
			// scopes: a small button with the number; a click opens the list – no wide row of badges, also on phones
			'scopes' => ['label' => t('api_scopes'), 'select' => ['api_scopes' => 'c.scopes'], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => implode(', ', $scopes($r)),
				'html' => static function ($r) use ($scopes): string {
					$s = $scopes($r);
					if (!$s) return '<span class="text-body-secondary">' . h(t('api_scopes_none')) . '</span>';
					$items = '';
					foreach ($s as $x) $items .= '<li class="dropdown-item-text small"><code>' . h($x) . '</code> – ' . h(t('api_scope_' . str_replace(':', '_', $x))) . '</li>';
					return '<div class="dropdown d-inline-block nuApiScopes"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" title="'
						. h(implode(', ', $s)) . '"><i class="bi bi-key me-1"></i>' . h(t('api_scopes_n', count($s))) . '</button><ul class="dropdown-menu shadow-sm">' . $items . '</ul></div>';
				}],
			'status' => ['label' => t('status'), 'select' => ['api_active' => 'c.active', 'api_expires' => 'c.expires_on'], 'sort' => 'c.active', 'align' => 'center',
				'text' => static fn($r) => t(['on' => 'api_active', 'off' => 'api_inactive', 'expired' => 'api_expired'][$state($r)]),
				'html' => static fn($r) => self::badge(t(['on' => 'api_active', 'off' => 'api_inactive', 'expired' => 'api_expired'][$state($r)]), ['on' => 'text-bg-success', 'off' => 'text-bg-secondary', 'expired' => 'text-bg-warning'][$state($r)])],
			'last_used' => ['label' => t('api_last_used'), 'select' => ['api_last' => 'c.last_used_at'], 'sort' => 'c.last_used_at', 'class' => 'small text-nowrap', 'text' => $dt('api_last')],
			'expires' => ['label' => t('user_expires'), 'select' => ['api_exp2' => 'c.expires_on'], 'sort' => 'c.expires_on', 'class' => 'small text-nowrap', 'text' => $dt('api_exp2', 'd.m.Y')],
			'rate' => ['label' => t('api_rate'), 'select' => ['api_rate' => 'c.rate_limit'], 'sort' => 'c.rate_limit', 'class' => 'small', 'align' => 'end', 'text' => static fn($r) => (string)($r['api_rate'] ?? '')],
			'ip' => array_replace(self::col('api_ip', 'c.ip_allow', t('api_ip'), 'small text-break'), ['sort' => null]),
			'created' => ['label' => t('api_created'), 'select' => ['api_created' => 'c.created_at'], 'sort' => 'c.created_at', 'class' => 'small text-nowrap', 'text' => $dt('api_created')],
			'id' => self::idCol('c.zzzzsys_nunext_api_client_id'),
		];
		$filters = [
			'status' => ['label' => t('status'), 'type' => 'select',
				'options' => static fn() => ['on' => t('api_active'), 'off' => t('api_inactive'), 'expired' => t('api_expired')],
				'where' => static fn(string $v) => match ($v) {
					'on' => ["c.active = '1' AND (c.expires_on IS NULL OR c.expires_on > ?)", [$now]],
					'off' => ["COALESCE(c.active, '0') <> '1'", []],
					'expired' => ["c.active = '1' AND c.expires_on IS NOT NULL AND c.expires_on <= ?", [$now]],
					default => null,
				}],
			'scope' => ['label' => t('api_scopes'), 'type' => 'select',
				'options' => static fn() => array_combine(array_keys(NuApiClient::SCOPES), array_keys(NuApiClient::SCOPES)),
				'where' => static fn(string $v) => isset(NuApiClient::SCOPES[$v]) ? ['c.scopes LIKE ?', ['%"' . $v . '"%']] : null],
		];
		$base = ['columns' => ['name', 'user', 'scopes', 'status', 'last_used'], 'card' => ['title' => 'name', 'badge' => 'status', 'lines' => ['user', 'scopes', 'last_used']],
			'sort' => ['name', 'asc'], 'per' => 50, 'filters' => ['shown' => ['status', 'scope'], 'values' => []]];
		return [
			'key' => 'apiclients', 'title' => t('api_title'), 'route' => 'apiclients', 'new_url' => nu_url('apiclients', ['id' => 'new']), 'empty_icon' => 'bi-plug', 'form' => NuAcl::FORM_APICLIENT,
			'from' => " FROM $C c LEFT JOIN zzzzsys_user u ON " . $db->sameText('u.zzzzsys_user_id', 'c.user_id') . ' LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id',
			'where' => [], 'params' => [], 'id' => 'c.zzzzsys_nunext_api_client_id',
			'link' => static fn($r) => nu_url('apiclients', ['id' => $r['__id']]),
			'search' => ['c.name', 'c.client_id', 'u.sus_login_name', 'a.sal_code', 'c.scopes'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['name', 'status', 'scopes'], 'card' => ['title' => 'name', 'badge' => 'status', 'lines' => ['scopes']]] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}
}
