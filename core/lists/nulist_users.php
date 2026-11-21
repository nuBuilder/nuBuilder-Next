<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "users" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListUsers
{
	private static function users(): array
	{
		$db = nu_db();
		$today = $db->datetime(date('Y-m-d'));
		$zero = $db->datetime('1900-01-02');
		$hasRoles = $db->tableExists('zzzzsys_nunext_admin_user');
		// created / last changed by nuBuilder Next: sus_json NUNEXT_CREATED / NUNEXT_CHANGED {t, by} – only rows with valid JSON; no ", " inside (the sort splits on it)
		$stamp = static fn(string $key, string $part): string => $db->jsonText('u.sus_json', "$key.$part");
		$stampText = static fn(array $r, string $k): string => ($t = self::date($r[$k . '_t'] ?? null, true)) === '' ? '' : $t . ((string)($r[$k . '_by'] ?? '') !== '' ? ' · ' . $r[$k . '_by'] : '');
		$lastLogin = '(SELECT MAX(l.log_time) FROM zzzzsys_nunext_log l WHERE '
			. $db->sameTextOn('l.user_id', 'u.zzzzsys_user_id', 'zzzzsys_nunext_log', 'user_id')
			. " AND l.event = 'login')";   // index (user_id, event, log_time), review B1
		$twofa = 'EXISTS (SELECT 1 FROM zzzzsys_nunext_user_2fa f WHERE '
			. $db->sameTextOn('f.zzzzsys_nunext_user_2fa_id', 'u.zzzzsys_user_id', 'zzzzsys_nunext_user_2fa', 'zzzzsys_nunext_user_2fa_id') . ')';
		$session = 'EXISTS (SELECT 1 FROM zzzzsys_nunext_session s WHERE ' . $db->sameTextOn('s.user_id', 'u.zzzzsys_user_id', 'zzzzsys_nunext_session', 'user_id') . ' AND s.last_seen >= ?)';
		$sessionSince = $db->datetime(date('Y-m-d H:i:s', time() - 60 * max(1, (int)(nu_cfg('nuConfigSessionTimeout') ?? 120))));
		$role = $hasRoles ? '(SELECT r.name FROM zzzzsys_nunext_admin_user au JOIN zzzzsys_nunext_admin_role r ON r.zzzzsys_nunext_admin_role_id = au.role_id WHERE '
			. $db->sameTextOn('au.zzzzsys_nunext_admin_user_id', 'u.zzzzsys_user_id', 'zzzzsys_nunext_admin_user', 'zzzzsys_nunext_admin_user_id') . ')' : 'NULL';
		$expired = static fn(array $r): bool => ($d = nu_date_only($r['sus_expires_on'] ?? '')) !== '' && (int)substr($d, 0, 4) > 1900 && $d < date('Y-m-d');
		$name = static fn(array $r): string => (trim(($r['sus_first_name'] ?? '') . ' ' . ($r['sus_last_name'] ?? '')) ?: (string)($r['sus_name'] ?? '')) ?: '—';
		// first and last name shown as columns of their own (e.g. view "All columns"): the name column shows only the full name
		$ownCols = static fn(): bool => in_array('first_name', NuListView::$shown, true) && in_array('last_name', NuListView::$shown, true);
		$fullName = static fn(array $r): string => (trim((string)($r['sus_name'] ?? '')) ?: trim(($r['sus_first_name'] ?? '') . ' ' . ($r['sus_last_name'] ?? ''))) ?: '—';
		$deptTeam = static function (array $r): string {
			$dept = trim((string)($r['sus_department'] ?? '')); $team = trim((string)($r['sus_team'] ?? ''));   // equal (ignoring case): shown once
			return implode(' / ', array_filter($dept !== '' && mb_strtolower($dept) === mb_strtolower($team) ? [$dept] : [$dept, $team], static fn($v) => $v !== ''));
		};
		$plain = static fn(string $alias, string $label, ?string $sort = null, string $class = '') => ['label' => $label, 'select' => [$alias => 'u.' . $alias],
			'sort' => $sort ?? 'u.' . $alias, 'text' => static fn($r) => (string)($r[$alias] ?? ''), 'class' => $class];
		$langOf = static function (array $r): string {
			$code = nu_lang_from_db((string)($r['sus_language'] ?? ''));
			return ($r['sus_language'] ?? '') === '' || $code === null ? '' : (NU_LANGS[$code][0] ?? (string)$r['sus_language']);
		};

		$cols = [
			'name' => ['label' => t('name'), 'main' => true, 'locked' => true,
				'select' => ['sus_first_name' => 'u.sus_first_name', 'sus_last_name' => 'u.sus_last_name', 'sus_name' => 'u.sus_name'],
				'sort' => 'u.sus_last_name, u.sus_first_name, u.sus_name', 'text' => static fn(array $r): string => $ownCols() ? $fullName($r) : $name($r),
				'html' => static function (array $r) use ($name, $ownCols, $fullName): string {
					if ($ownCols()) return h($fullName($r));
					$n = trim(($r['sus_first_name'] ?? '') . ' ' . ($r['sus_last_name'] ?? ''));
					return h($name($r)) . ($n !== '' && ($r['sus_name'] ?? '') !== '' && $r['sus_name'] !== $n ? '<div class="small text-body-secondary fw-normal">' . h($r['sus_name']) . '</div>' : '');
				}],
			'dept' => ['label' => t('user_department') . ' / ' . t('user_team'), 'select' => ['sus_department' => 'u.sus_department', 'sus_team' => 'u.sus_team'],
				'sort' => 'u.sus_department, u.sus_team', 'text' => $deptTeam],
			'department' => $plain('sus_department', t('user_department')),
			'team' => $plain('sus_team', t('user_team')),
			'position' => $plain('sus_position', t('user_position')),
			'access' => ['label' => t('access_level'), 'select' => ['sal_code' => 'a.sal_code', 'sal_description' => 'a.sal_description', 'lv_acc_id' => 'a.zzzzsys_access_id'], 'sort' => 'a.sal_code',
				'text' => static fn($r) => (string)($r['sal_code'] ?? t('none_assigned')),
				// the badge opens the access level (the row still opens the user) – in a new tab
				'html' => static fn($r) => $r['sal_code'] !== null
					? (NuAcl::can(NuAcl::FORM_ACCESS) ? '<a class="text-decoration-none nuAccessLink" href="'
						. h(nu_url('accesslevel', ['id' => (string)$r['lv_acc_id']])) . '" target="_blank" rel="noopener" title="' . h(t('user_access_open')) . '">' : '')
						. self::badge((string)$r['sal_code'], 'bg-secondary-subtle text-secondary-emphasis border nuAccessBadge', (string)$r['sal_description'])
						. (NuAcl::can(NuAcl::FORM_ACCESS) ? ' <i class="bi bi-box-arrow-up-right small text-secondary" aria-hidden="true"></i></a>' : '')
					: self::badge(t('none_assigned'), 'bg-danger-subtle text-danger-emphasis')],
			'login' => ['label' => t('user_login_name'), 'select' => ['sus_login_name' => 'u.sus_login_name', 'sus_change_password' => 'u.sus_change_password'], 'sort' => 'u.sus_login_name',
				'text' => static fn($r) => (string)$r['sus_login_name'],
				'html' => static fn($r) => h($r['sus_login_name'] !== '' ? $r['sus_login_name'] : '—')
					. ((string)$r['sus_change_password'] === '1' ? ' <i class="bi bi-key text-warning ms-1" title="' . h(t('user_must_change')) . '"></i>' : '')],
			'code' => $plain('sus_code', t('user_code'), null, 'small text-body-secondary'),
			'email' => $plain('sus_email', t('user_email'), null, 'nuColEmail') + ['html' => static fn($r) => ($e = (string)($r['sus_email'] ?? '')) === '' ? ''
				: '<span class="nuClamp2" title="' . h($e) . '">' . h($e) . '</span>'],   // at most 2 lines, then … – full address as tooltip
			'first_name' => $plain('sus_first_name', t('user_first_name')),
			'last_name' => $plain('sus_last_name', t('user_last_name')),
			'full_name' => $plain('sus_name', t('user_display_name')),
			'status' => ['label' => t('status'), 'select' => ['sus_expires_on' => 'u.sus_expires_on', 'sus_change_password' => 'u.sus_change_password'], 'sort' => null,
				'text' => static fn($r) => $expired($r) ? t('user_expired') : ((string)$r['sus_change_password'] === '1' ? t('user_must_change') : t('user_active')),
				'html' => static fn($r) => $expired($r) ? self::badge(t('user_expired'), 'text-bg-danger')
					: ((string)$r['sus_change_password'] === '1' ? self::badge(t('user_must_change'), 'text-bg-warning') : self::badge(t('user_active'), 'text-bg-success'))],
			'expires' => ['label' => t('user_expires'), 'select' => ['sus_expires_on' => 'u.sus_expires_on'], 'sort' => 'u.sus_expires_on',
				'text' => static fn($r) => self::date($r['sus_expires_on'] ?? null),
				'html' => static fn($r) => ($d = self::date($r['sus_expires_on'] ?? null)) === '' ? '' : ($expired($r) ? '<span class="text-danger">' . h($d) . '</span>' : h($d))],
			'language' => ['label' => t('user_language'), 'select' => ['sus_language' => 'u.sus_language'], 'sort' => 'u.sus_language', 'text' => $langOf],
			'additional1' => $plain('sus_additional1', t('user_additional1')),
			'additional2' => $plain('sus_additional2', t('user_additional2')),
			'created' => ['label' => t('fi_created'), 'select' => ['lv_created_t' => $stamp('NUNEXT_CREATED', 't'), 'lv_created_by' => $stamp('NUNEXT_CREATED', 'by')],
				'sort' => $stamp('NUNEXT_CREATED', 't'), 'class' => 'small text-nowrap', 'text' => static fn($r) => $stampText($r, 'lv_created')],
			'changed' => ['label' => t('fi_changed'), 'select' => ['lv_changed_t' => $stamp('NUNEXT_CHANGED', 't'), 'lv_changed_by' => $stamp('NUNEXT_CHANGED', 'by')],
				'sort' => $stamp('NUNEXT_CHANGED', 't'), 'class' => 'small text-nowrap', 'text' => static fn($r) => $stampText($r, 'lv_changed')],
			'last_login' => ['label' => t('lv_last_login'), 'select' => ['lv_last_login' => $lastLogin], 'sort' => $lastLogin,
				'text' => static fn($r) => self::date($r['lv_last_login'] ?? null, true)],
			'twofa' => ['label' => t('lv_2fa_app'), 'select' => ['lv_2fa' => "CASE WHEN $twofa THEN 1 ELSE 0 END"], 'sort' => null, 'align' => 'center',
				'text' => static fn($r) => (int)$r['lv_2fa'] ? t('yes') : '',
				'html' => static fn($r) => (int)$r['lv_2fa'] ? '<i class="bi bi-shield-check text-success" title="' . h(t('lv_2fa_app')) . '"></i>' : ''],
			'admin_role' => ['label' => t('adm_role_field'), 'select' => ['lv_role' => $role], 'sort' => $hasRoles ? $role : null,
				'text' => static fn($r) => (string)($r['lv_role'] ?? ''),
				'html' => static fn($r) => ($r['lv_role'] ?? '') !== '' && $r['lv_role'] !== null ? self::badge((string)$r['lv_role'], 'bg-primary-subtle text-primary-emphasis border') : ''],
			'online' => ['label' => t('lv_online'), 'select' => ['lv_online' => ['CASE WHEN ' . $session . ' THEN 1 ELSE 0 END', [$sessionSince]]], 'sort' => null, 'align' => 'center',
				'text' => static fn($r) => (int)$r['lv_online'] ? t('yes') : '',
				'html' => static fn($r) => (int)$r['lv_online'] ? '<i class="bi bi-circle-fill text-success small" title="' . h(t('lv_online')) . '"></i>' : ''],
			'pw_changed' => ['label' => t('lv_pw_changed'), 'select' => ['sus_json_pw' => 'u.sus_json'], 'sort' => null,
				'text' => static function ($r): string {
					$j = json_decode((string)($r['sus_json_pw'] ?? ''), true);
					return is_array($j) && !empty($j['PASSWORD_CHANGED_TIME']) ? date(nu_dfmt('d.m.Y'), (int)$j['PASSWORD_CHANGED_TIME']) : '';
				}],
			'id' => ['label' => 'ID', 'select' => ['zzzzsys_user_id' => 'u.zzzzsys_user_id'], 'sort' => 'u.zzzzsys_user_id', 'class' => 'small text-body-secondary',
				'text' => static fn($r) => (string)$r['zzzzsys_user_id']],
		];

		$filters = [
			'access' => ['label' => t('access_level'), 'type' => 'select', 'tom' => true, 'none' => t('none_assigned'),
				'options' => static function (): array {
					$o = [];
					foreach (NuAcl::levels() as $a) $o[(string)$a['zzzzsys_access_id']] = [(string)$a['sal_code'], (string)$a['sal_description']];
					return $o;
				},
				'where' => static fn(string $v) => $v === '-' ? ["(u.sus_zzzzsys_access_id IS NULL OR u.sus_zzzzsys_access_id = '')", []] : ['u.sus_zzzzsys_access_id = ?', [$v]]],
			// main or additional access level (the link "Show in the users list" of an access level)
			'anyacc' => ['label' => t('users_flt_anyacc'), 'type' => 'select', 'tom' => true,
				'options' => static function (): array {
					$o = [];
					foreach (NuAcl::levels() as $a) $o[(string)$a['zzzzsys_access_id']] = [(string)$a['sal_code'], (string)$a['sal_description']];
					return $o;
				},
				'where' => static fn(string $v) => NuUserAccess::ready()
					? ['(u.sus_zzzzsys_access_id = ? OR EXISTS (SELECT 1 FROM ' . NuUserAccess::TABLE . ' x WHERE ' . $db->sameTextOn('x.user_id', 'u.zzzzsys_user_id', NuUserAccess::TABLE, 'user_id')
						. ' AND x.access_id = ? AND (x.valid_until IS NULL OR x.valid_until = \'\' OR x.valid_until >= ?)))', [$v, $v, date('Y-m-d')]]
					: ['u.sus_zzzzsys_access_id = ?', [$v]]],
			// users with an active permission item
			'perm' => ['label' => t('users_flt_perm'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($db): array {
					if (!$db->tableExists(NuPermission::ITEM)) return [];
					$o = [];
					foreach ($db->all('SELECT zzzzsys_permission_item_id, pme_ident, pme_description FROM ' . NuPermission::ITEM . ' ORDER BY pme_ident') as $i) $o[(string)$i['zzzzsys_permission_item_id']] = [(string)$i['pme_ident'],
						(string)$i['pme_description']];
					return $o;
				},
				'where' => static fn(string $v) => ['EXISTS (SELECT 1 FROM ' . NuPermission::USER . ' up WHERE ' . $db->sameText('up.upe_zzzzsys_user_id', 'u.zzzzsys_user_id')
					. " AND up.upe_ident = ? AND up.upe_active = '1')", [$v]]],
			'pos' => ['label' => t('user_position'), 'type' => 'distinct', 'expr' => 'u.sus_position', 'none' => t('users_pos_none')],
			'state' => ['label' => t('status'), 'type' => 'select', 'all' => 'all',
				'options' => static fn() => ['active' => t('user_active'), 'expired' => t('user_expired'), 'change' => t('user_must_change')],
				'where' => static fn(string $v) => match ($v) {
					'active' => ['(u.sus_expires_on IS NULL OR u.sus_expires_on >= ? OR u.sus_expires_on < ?)', [$today, $zero]],   // not expired (also MySQL zero dates)
					'expired' => ['u.sus_expires_on < ? AND u.sus_expires_on > ?', [$today, $zero]],
					'change' => ["u.sus_change_password = '1'", []],
					default => null,
				}],
			'dept' => ['label' => t('user_department'), 'type' => 'distinct', 'expr' => 'u.sus_department', 'none' => t('lv_empty')],
			'team' => ['label' => t('user_team'), 'type' => 'distinct', 'expr' => 'u.sus_team', 'none' => t('lv_empty')],
			'lang' => ['label' => t('user_language'), 'type' => 'distinct', 'expr' => 'u.sus_language', 'none' => t('lv_empty')],
			'login' => ['label' => t('lv_last_login'), 'type' => 'date', 'expr' => $lastLogin, 'presets' => ['d7', 'd30', 'd90', 'older90', 'never']],
			'expires' => ['label' => t('user_expires'), 'type' => 'date', 'expr' => 'u.sus_expires_on', 'presets' => ['past', 'next30', 'future', 'empty']],
			'twofa' => ['label' => t('lv_2fa_app'), 'type' => 'bool', 'expr' => $twofa],
			'online' => ['label' => t('lv_online'), 'type' => 'bool', 'expr' => $session, 'expr_params' => [$sessionSince]],
		];
		if ($hasRoles) {
			$filters['role'] = ['label' => t('adm_role_field'), 'type' => 'select', 'none' => t('lv_empty'),
				'options' => static fn() => array_column($db->all('SELECT zzzzsys_nunext_admin_role_id AS id, name FROM zzzzsys_nunext_admin_role ORDER BY sort_order, name'), 'name', 'id'),
				'where' => static fn(string $v) => $v === '-'
					? ['NOT EXISTS (SELECT 1 FROM zzzzsys_nunext_admin_user au WHERE '
						. $db->sameTextOn('au.zzzzsys_nunext_admin_user_id', 'u.zzzzsys_user_id', 'zzzzsys_nunext_admin_user', 'zzzzsys_nunext_admin_user_id') . ')', []]
					: ['EXISTS (SELECT 1 FROM zzzzsys_nunext_admin_user au WHERE '
						. $db->sameTextOn('au.zzzzsys_nunext_admin_user_id', 'u.zzzzsys_user_id', 'zzzzsys_nunext_admin_user', 'zzzzsys_nunext_admin_user_id')
						. ' AND au.role_id = ?)', [$v]]];
		}

		// custom user fields (core/nuuserfields.php): the ones marked "in the users list" as columns (hidden by default, chosen in a
		// list view), filters for choice lists, yes / no, dates and short texts; text fields also found by the search
		$from = ' FROM zzzzsys_user u LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id';
		$search = ['u.sus_login_name', 'u.sus_name', 'u.sus_first_name', 'u.sus_last_name', 'u.sus_email', 'u.sus_code', 'u.sus_department', 'u.sus_team', 'u.sus_position'];
		if (NuUserFields::ready()) {
			$from .= ' LEFT JOIN ' . NuUserFields::EXT . ' ux ON ' . $db->sameTextOn('ux.' . NuUserFields::KEY, 'u.zzzzsys_user_id', NuUserFields::EXT, NuUserFields::KEY);
			foreach (NuUserFields::all() as $code => $f) {
				if ((string)$f['in_list'] !== '1') continue;
				$expr = 'ux.' . NuDB::ident((string)$code);
				$type = (string)$f['type'];
				$cols['uf_' . $code] = ['label' => NuUserFields::label($f), 'select' => ['uf_' . $code => $expr], 'sort' => $type === 'longtext' ? null : $expr,
					'class' => in_array($type, ['integer', 'number'], true) ? 'text-end' : '', 'text' => static fn($r) => NuUserFields::text($f, $r['uf_' . $code] ?? null)];
				if ($type === 'select') {
					$filters['uf_' . $code] = ['label' => NuUserFields::label($f), 'type' => 'select', 'none' => t('lv_empty'),
						'options' => static function () use ($f): array { try { return NuUserFields::options($f); } catch (Throwable) { return []; } },
						'where' => static fn(string $v) => $v === '-' ? ["($expr IS NULL OR $expr = '')", []] : ["$expr = ?", [$v]]];
				} elseif ($type === 'yesno') {
					$filters['uf_' . $code] = ['label' => NuUserFields::label($f), 'type' => 'bool', 'expr' => "$expr = '1'"];
				} elseif ($type === 'date') {
					$filters['uf_' . $code] = ['label' => NuUserFields::label($f), 'type' => 'date', 'expr' => $expr, 'presets' => ['past', 'next30', 'future', 'empty']];
				} elseif (in_array($type, ['text', 'email', 'phone'], true)) {
					$filters['uf_' . $code] = ['label' => NuUserFields::label($f), 'type' => 'distinct', 'expr' => $expr, 'none' => t('lv_empty')];
				}
				if (in_array($type, ['text', 'longtext', 'email', 'phone', 'url', 'select'], true)) $search[] = $expr;
			}
		}

		return [
			'key' => 'users', 'title' => t('nav_users'), 'route' => 'users', 'form' => NuAcl::FORM_USER,
			'from' => $from,
			'where' => ["COALESCE(u.sus_login_name, '') <> ''"], 'params' => [],   // not nuBuilder's data row for the globeadmin (nuSetUserJSONData: only the id)
			'id' => 'u.zzzzsys_user_id', 'link' => static fn($r) => nu_url('user', ['id' => $r['__id']]),
			'search' => $search,
			'search_ph' => t('user_search_ph'), 'open_min' => 0,   // filters folded by default, open while one is set
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['name', 'dept', 'position', 'access', 'login', 'code', 'email'], 'card' => ['title' => 'name', 'badge' => 'access', 'lines' => ['login', 'dept', 'email']],
					'sort' => ['name', 'asc'], 'filters' => ['shown' => ['access', 'pos', 'dept', 'team', 'state'], 'values' => ['state' => 'active']]],   // department + team
				'compact' => ['columns' => ['name', 'access', 'login'], 'card' => ['title' => 'name', 'badge' => 'access', 'lines' => ['login']],
					'sort' => ['name', 'asc'], 'filters' => ['shown' => ['access', 'state'], 'values' => ['state' => 'active']]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'name', 'badge' => 'access', 'lines' => ['login', 'dept', 'email', 'status']],
					'sort' => ['name', 'asc'], 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}
}
