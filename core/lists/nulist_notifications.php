<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "notifications" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListNotifications
{
	private static function notifications(): array
	{
		$T = NuNotify::T;
		$now = date('Y-m-d H:i:s');
		$state = "CASE WHEN n.status <> 'published' THEN n.status WHEN n.expires_at IS NOT NULL AND n.expires_at <= '$now' THEN 'expired'"
			. " WHEN n.publish_at IS NOT NULL AND n.publish_at > '$now' THEN 'scheduled' ELSE 'published' END";
		$row = static fn(array $r): array => ['zzzzsys_nunext_notification_id' => (string)$r['__id'], 'status' => (string)$r['ntf_status'], 'mode' => (string)$r['ntf_mode'],
			'audience' => (string)$r['ntf_aud'], 'publish_at' => (string)$r['ntf_pub'], 'expires_at' => (string)$r['ntf_exp'], 'created_by_id' => (string)$r['ntf_by_id']];
		$recCache = [];
		$rec = static function (array $r) use ($row, &$recCache): array {
			$id = (string)$r['__id'];
			return $recCache[$id] ??= NuNotify::receipts($row($r), false);
		};
		$dt = static fn(?string $v) => (string)$v !== '' ? date(nu_dfmt('d.m.Y H:i'), (int)strtotime((string)$v)) : '';
		$stCls = ['draft' => 'bg-secondary-subtle text-secondary-emphasis', 'scheduled' => 'bg-info-subtle text-info-emphasis', 'published' => 'bg-success-subtle text-success-emphasis',
			'expired' => 'bg-light text-body-secondary border', 'withdrawn' => 'bg-warning-subtle text-warning-emphasis'];
		$base = ['ntf_status' => 'n.status', 'ntf_mode' => 'n.mode', 'ntf_aud' => 'n.audience', 'ntf_pub' => 'n.publish_at', 'ntf_exp' => 'n.expires_at', 'ntf_by_id' => 'n.created_by_id'];
		$cols = [
			'level' => ['label' => t('ntf_f_level'), 'select' => ['ntf_level' => 'n.level'], 'sort' => 'n.level', 'align' => 'center', 'text' => static fn($r) => t('ntf_lv_' . $r['ntf_level']),
				'html' => static fn($r) => '<i class="bi ' . h(NuNotify::LEVELS[(string)$r['ntf_level']] ?? 'bi-info-circle') . ' nuBellIcon nuBellLv-' . h((string)$r['ntf_level']) . '" role="img" aria-label="' . h(t('ntf_lv_' . $r['ntf_level'])) . '" title="' . h(t('ntf_lv_' . $r['ntf_level'])) . '"></i>'],
			'title' => ['label' => t('ntf_f_title'), 'main' => true, 'locked' => true,
				'select' => ['ntf_title' => 'n.title', 'ntf_pin' => 'n.pinned', 'ntf_conf' => 'n.must_confirm', 'ntf_mail' => 'n.email'] + $base,
				'sort' => 'n.title', 'text' => static fn($r) => (string)$r['ntf_title'],
				'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['ntf_title']) . '</span>'
					. ((string)$r['ntf_pin'] === '1' ? ' <i class="bi bi-pin-angle-fill text-body-secondary ms-1" role="img" aria-label="' . h(t('ntf_pinned')) . '" title="' . h(t('ntf_pinned')) . '"></i>' : '')
					. ((string)$r['ntf_conf'] === '1' ? ' <span class="badge bg-warning-subtle text-warning-emphasis ms-1 nuNtfConfirm"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>'
						. h(t('ntf_f_must_confirm')) . '</span>' : '')
					. ((string)$r['ntf_mail'] === '1' ? ' <i class="bi bi-envelope text-body-secondary ms-1" role="img" aria-label="' . h(t('ntf_f_email')) . '" title="' . h(t('ntf_f_email')) . '"></i>' : '')],
			'state' => ['label' => t('ntf_status'), 'select' => ['ntf_state' => $state, 'ntf_pub2' => 'n.publish_at', 'ntf_exp2' => 'n.expires_at'], 'sort' => $state,
				'text' => static fn($r) => t('ntf_st_' . $r['ntf_state']),
				'html' => static fn($r) => self::badge(t('ntf_st_' . $r['ntf_state']), $stCls[(string)$r['ntf_state']] ?? 'bg-secondary')
					. ((string)$r['ntf_state'] === 'scheduled' ? '<div class="small text-body-secondary">' . h($dt($r['ntf_pub2'])) . '</div>'
						: ((string)$r['ntf_state'] === 'published' && (string)$r['ntf_exp2'] !== '' ? '<div class="small text-body-secondary">' . h(t('ntf_until', $dt($r['ntf_exp2']))) . '</div>' : ''))],
			'audience' => ['label' => t('ntf_f_audience'), 'select' => ['ntf_aud2' => 'n.audience'], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => NuNotify::audienceText(is_array($a = json_decode((string)$r['ntf_aud2'], true)) ? $a : [])],
			'read' => ['label' => t('ntf_read'), 'select' => $base, 'sort' => null, 'align' => 'end', 'class' => 'small text-nowrap',
				'text' => static fn($r) => ($x = $rec($r))['read'] . ' / ' . $x['total'],
				'html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('notifications', ['id' => $r['__id'], 'receipts' => '1'])) . '" title="' . h(t('ntf_receipts')) . '">' . ($x = $rec($r))['read'] . ' / ' . $x['total'] . '</a>'],
			'confirmed' => ['label' => t('ntf_confirmed'), 'select' => $base + ['ntf_conf2' => 'n.must_confirm'], 'sort' => null, 'align' => 'end', 'class' => 'small',
				'text' => static fn($r) => (string)$r['ntf_conf2'] === '1' ? ($x = $rec($r))['confirmed'] . ' / ' . $x['total'] : ''],
			'created' => ['label' => t('ntf_created'), 'select' => ['ntf_created' => 'n.created_at', 'ntf_by' => 'n.created_by'], 'sort' => 'n.created_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => $dt($r['ntf_created']) . ' · ' . $r['ntf_by'],
				'html' => static fn($r) => h($dt($r['ntf_created'])) . '<div class="text-body-secondary">' . h((string)$r['ntf_by']) . '</div>'],
			'actions' => ['label' => '', 'select' => $base, 'sort' => null, 'align' => 'end', 'noexport' => true, 'text' => static fn($r) => '',
				'html' => static function ($r) use ($row): string {
					$n = $row($r);
					if (!NuNotify::canEdit($n)) return '';
					$nid = (string)$r['__id'];
					$h = '<div class="dropdown"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" data-bs-popper-config="{&quot;strategy&quot;:&quot;fixed&quot;}" aria-expanded="false" aria-label="'
						. h(t('actions')) . '"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end">'
						. '<li><a class="dropdown-item" href="' . h(nu_url('notifications', ['id' => $nid])) . '"><i class="bi bi-pencil me-2"></i>' . h(t('edit')) . '</a></li>'
						. '<li><a class="dropdown-item" href="' . h(nu_url('notifications', ['id' => $nid, 'receipts' => '1'])) . '"><i class="bi bi-people me-2"></i>' . h(t('ntf_receipts')) . '</a></li>';
					foreach ([['copy', 'bi-copy', 'ntf_copy', ''],
						$n['status'] === 'published' ? ['withdraw', 'bi-eye-slash', 'ntf_withdraw', ''] : ['publish', 'bi-send', 'ntf_publish', ''],
						['delete', 'bi-trash', 'delete', t('ntf_delete_confirm')]] as [$act, $ic, $lb, $conf]) {
						$h .= '<li><form method="post"' . ($conf !== '' ? ' data-confirm="' . h($conf) . '" data-confirm-ok="' . h(t('delete')) . '" data-confirm-class="btn btn-danger"' : '') . '>' . nu_csrf_field()
							. '<input type="hidden" name="action" value="' . $act . '"><input type="hidden" name="id" value="' . h($nid) . '">'
							. '<button type="submit" class="dropdown-item' . ($act === 'delete' ? ' text-danger' : '') . '"><i class="bi ' . $ic . ' me-2"></i>' . h(t($lb)) . '</button></form></li>';
					}
					return $h . '</ul></div>';
				}],
			'id' => self::idCol('n.zzzzsys_nunext_notification_id'),
		];
		$filters = [
			'state' => ['label' => t('ntf_status'), 'type' => 'select',
				'options' => static fn() => array_combine(['draft', 'scheduled', 'published', 'expired', 'withdrawn'], array_map(static fn($k) => t('ntf_st_' . $k), ['draft', 'scheduled', 'published', 'expired', 'withdrawn'])),
				'where' => static fn(string $v) => ["($state) = ?", [$v]]],
			'confirm' => ['label' => t('ntf_f_must_confirm'), 'type' => 'bool', 'expr' => "n.must_confirm = '1'"],
			'level' => ['label' => t('ntf_f_level'), 'type' => 'select',
				'options' => static fn() => array_combine(array_keys(NuNotify::LEVELS), array_map(static fn($k) => t('ntf_lv_' . $k), array_keys(NuNotify::LEVELS))),
				'where' => static fn(string $v) => ['n.level = ?', [$v]]],
			'when' => ['label' => t('lv_period'), 'type' => 'date', 'expr' => 'n.created_at', 'presets' => ['d7', 'd30', 'd90']],
		];
		$own = NuNotify::canViewAll() ? [] : ['n.created_by_id = ?'];
		$base2 = ['columns' => ['level', 'title', 'state', 'audience', 'read', 'created', 'actions'], 'card' => ['title' => 'title', 'badge' => 'state', 'lines' => ['audience', 'read', 'created']],
			'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => ['state', 'confirm'], 'values' => []]];
		return [
			'key' => 'notifications', 'title' => t('ntf_manage'), 'route' => 'notifications', 'route_params' => ['tab' => 'manage'],
				'new_url' => nu_url('notifications', ['id' => 'new']), 'empty_icon' => 'bi-bell', 'form' => NuAcl::FORM_NOTIFY,
			'from' => " FROM $T n", 'where' => $own, 'params' => $own ? [(string)(NuAuth::user()['id'] ?? '')] : [], 'id' => 'n.zzzzsys_nunext_notification_id',
			'link' => static fn($r) => NuNotify::canEdit($row($r)) ? nu_url('notifications', ['id' => $r['__id']]) : nu_url('notifications', ['id' => $r['__id'], 'receipts' => '1']),
			'always' => ['title'], 'search' => ['n.title', 'n.body', 'n.created_by'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base2,
				'compact' => ['columns' => ['level', 'title', 'state', 'read']] + $base2,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base2,
			],
		];
	}
}
