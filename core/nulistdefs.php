<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Column and filter catalogues of nuBuilder Next's own lists (0.8.329) – rendered by core/nulistview.php (NuListView).
 *
 * A list: key => [
 *   'title', 'route' (the page), 'form' (nuBuilder form whose rights apply), 'from' (FROM … JOIN …), 'where' / 'params' (always applied),
 *   'id' (SQL of the record id), 'link' => fn(row): url, 'search' => [SQL columns of the quick search], 'search_ph', 'open_min' (px: filters
 *   shown at once from this width),
 *   'columns' => [key => [
 *       'label', 'select' => [alias => SQL] (columns the cell needs), 'sort' => SQL ("a, b" – the direction applies to each) or null,
 *       'html' => fn(row): string (cell HTML, escaped), 'text' => fn(row): string (print / CSV / phone card), 'align' => 'start'|'end'|'center',
 *       'main' => true (the link column), 'locked' => true (always shown on desktop), 'class' => extra cell class, 'if' => fn(): bool (offered at all)]],
 *   'filters' => [key => [   key = URL parameter (kept from the lists before 0.8.329)
 *       'label', 'type' => 'select' (options + where) | 'distinct' (values of 'expr') | 'date' (presets on 'expr') | 'bool' ('expr' = condition),
 *       'options' => fn(): [value => label | [label, sub]], 'where' => fn(string value): [SQL, params], 'expr', 'none' (label of "empty"),
 *       'presets' => [...] (date), 'all' => value meaning "all" (default '*'), 'tom' => searchable select]],
 *   'builtin' => [view id => definition] – 'default' = the layout of the list before views existed ]
 * Definition of a view: see NuListView::normalize().
 */
final class NuListDefs
{
	/** @return array<string, array> */
	/** Keys of all lists with views (0.8.329: users, access; 0.8.334: the other system lists). */
	public const LISTS = ['users', 'access', 'forms', 'procedures', 'reports', 'files', 'items', 'translations', 'permissions', 'prompts', 'log', 'audit', 'emaillog', 'userfields', 'apiclients', 'adminroles', 'sqlbuilder', 'emailtemplates', 'notifications', 'dashboards', 'webhooks', 'webhookcalls'];

	public static function get(string $list): ?array
	{
		return match ($list) {
			'users' => self::users(), 'access' => self::access(), 'forms' => self::forms(), 'procedures' => self::procedures(), 'reports' => self::reports(),
			'files' => self::files(), 'items' => self::items(), 'translations' => self::translations(), 'permissions' => self::permissions(),
			'prompts' => self::prompts(), 'log' => self::log(), 'audit' => self::audit(), 'emaillog' => self::emailLog(), 'userfields' => self::userFields(), 'apiclients' => self::apiClients(),
			'adminroles' => self::adminRoles(), 'sqlbuilder' => self::sqlBuilder(), 'emailtemplates' => self::emailTemplates(),
			'notifications' => self::notifications(), 'dashboards' => self::dashboards(), 'webhooks' => self::webhooks(), 'webhookcalls' => self::webhookCalls(),
			default => null,
		};
	}

	/** A plain text column: [alias => SQL], sortable, escaped text. */
	private static function col(string $alias, string $sql, string $label, string $class = '', bool $sort = true): array
	{
		return ['label' => $label, 'select' => [$alias => $sql], 'sort' => $sort ? $sql : null, 'class' => $class, 'text' => static fn($r) => (string)($r[$alias] ?? '')];
	}

	/** Badge column (empty value: nothing). */
	private static function badgeCol(string $alias, string $sql, string $label, string $cls = 'bg-secondary-subtle text-secondary-emphasis'): array
	{
		return self::col($alias, $sql, $label) + ['html' => static fn($r) => (string)($r[$alias] ?? '') !== '' ? self::badge((string)$r[$alias], $cls) : ''];
	}

	private static function idCol(string $sql): array
	{
		return self::col('lv_id', $sql, 'ID', 'small text-body-secondary');
	}

	/** Forms list (0.8.388): a warning next to the type of a page form whose files use an unsupported API version or unknown / deprecated methods. */
	private static function pageApiBadge(string $code): string
	{
		$issues = NuPage::issues($code);
		if (!$issues) return '';
		$err = (bool)array_filter($issues, static fn($i) => $i['level'] === 'error');
		$tip = implode("\n", array_column($issues, 'text'));
		return ' <span class="nuInfoTip nuPageApiWarn ' . ($err ? 'text-danger' : 'text-warning-emphasis') . '" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide"'
			. ' aria-label="' . h($tip) . '" title="' . h($tip) . '"><i class="bi ' . ($err ? 'bi-x-octagon' : 'bi-exclamation-triangle') . '"></i></span>';
	}

	private static function badge(string $text, string $cls = 'bg-secondary-subtle text-secondary-emphasis border', string $title = ''): string
	{
		return '<span class="badge ' . $cls . '"' . ($title !== '' ? ' title="' . h($title) . '"' : '') . '>' . h($text) . '</span>';
	}

	private static function date(?string $v, bool $time = false): string
	{
		$d = $time ? (string)$v : nu_date_only($v);
		if ($d === '' || str_starts_with($d, '0000') || strtotime($d) === false || (int)substr($d, 0, 4) < 1901) return '';
		return date($time ? 'd.m.Y H:i' : 'd.m.Y', strtotime($d));
	}

	// ------------------------------------------------------------------ users

	private static function users(): array
	{
		$db = nu_db();
		$today = $db->datetime(date('Y-m-d'));
		$zero = $db->datetime('1900-01-02');
		$hasRoles = $db->tableExists('zzzzsys_nunext_admin_user');
		$lastLogin = '(SELECT MAX(l.log_time) FROM zzzzsys_nunext_log l WHERE ' . $db->sameText('l.user_id', 'u.zzzzsys_user_id') . " AND l.event = 'login')";
		$twofa = 'EXISTS (SELECT 1 FROM zzzzsys_nunext_user_2fa f WHERE ' . $db->sameText('f.zzzzsys_nunext_user_2fa_id', 'u.zzzzsys_user_id') . ')';
		$session = 'EXISTS (SELECT 1 FROM zzzzsys_nunext_session s WHERE ' . $db->sameText('s.user_id', 'u.zzzzsys_user_id') . ' AND s.last_seen >= ?)';
		$sessionSince = $db->datetime(date('Y-m-d H:i:s', time() - 60 * max(1, (int)(nu_cfg('nuConfigSessionTimeout') ?? 120))));
		$role = $hasRoles ? '(SELECT r.name FROM zzzzsys_nunext_admin_user au JOIN zzzzsys_nunext_admin_role r ON r.zzzzsys_nunext_admin_role_id = au.role_id WHERE ' . $db->sameText('au.zzzzsys_nunext_admin_user_id', 'u.zzzzsys_user_id') . ')' : 'NULL';
		$expired = static fn(array $r): bool => ($d = nu_date_only($r['sus_expires_on'] ?? '')) !== '' && (int)substr($d, 0, 4) > 1900 && $d < date('Y-m-d');
		$name = static fn(array $r): string => (trim(($r['sus_first_name'] ?? '') . ' ' . ($r['sus_last_name'] ?? '')) ?: (string)($r['sus_name'] ?? '')) ?: '—';
		// first and last name shown as columns of their own (e.g. view "All columns"): the name column shows only the full name (0.8.512)
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
				// the badge opens the access level (0.8.687; the row still opens the user)
				'html' => static fn($r) => $r['sal_code'] !== null
					? (NuAcl::can(NuAcl::FORM_ACCESS) ? '<a class="text-decoration-none nuAccessLink" href="' . h(nu_url('accesslevel', ['id' => (string)$r['lv_acc_id']])) . '">' : '')
						. self::badge((string)$r['sal_code'], 'bg-secondary-subtle text-secondary-emphasis border nuAccessBadge', (string)$r['sal_description'])
						. (NuAcl::can(NuAcl::FORM_ACCESS) ? '</a>' : '')
					: self::badge(t('none_assigned'), 'bg-danger-subtle text-danger-emphasis')],
			'login' => ['label' => t('user_login_name'), 'select' => ['sus_login_name' => 'u.sus_login_name', 'sus_change_password' => 'u.sus_change_password'], 'sort' => 'u.sus_login_name',
				'text' => static fn($r) => (string)$r['sus_login_name'],
				'html' => static fn($r) => h($r['sus_login_name'] !== '' ? $r['sus_login_name'] : '—')
					. ((string)$r['sus_change_password'] === '1' ? ' <i class="bi bi-key text-warning ms-1" title="' . h(t('user_must_change')) . '"></i>' : '')],
			'code' => $plain('sus_code', t('user_code'), null, 'small text-body-secondary'),
			'email' => $plain('sus_email', t('user_email'), null, 'nuColEmail') + ['html' => static fn($r) => ($e = (string)($r['sus_email'] ?? '')) === '' ? ''
				: '<span class="nuClamp2" title="' . h($e) . '">' . h($e) . '</span>'],   // at most 2 lines, then … – full address as tooltip (0.8.555)
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
					return is_array($j) && !empty($j['PASSWORD_CHANGED_TIME']) ? date('d.m.Y', (int)$j['PASSWORD_CHANGED_TIME']) : '';
				}],
			'id' => ['label' => 'ID', 'select' => ['zzzzsys_user_id' => 'u.zzzzsys_user_id'], 'sort' => 'u.zzzzsys_user_id', 'class' => 'small text-body-secondary',
				'text' => static fn($r) => (string)$r['zzzzsys_user_id']],
		];

		$filters = [
			'access' => ['label' => t('access_level'), 'type' => 'select', 'tom' => true, 'none' => t('none_assigned'),
				'options' => static function () use ($db): array {
					$o = [];
					foreach ($db->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code') as $a) $o[(string)$a['zzzzsys_access_id']] = [(string)$a['sal_code'], (string)$a['sal_description']];
					return $o;
				},
				'where' => static fn(string $v) => $v === '-' ? ["(u.sus_zzzzsys_access_id IS NULL OR u.sus_zzzzsys_access_id = '')", []] : ['u.sus_zzzzsys_access_id = ?', [$v]]],
			// main or additional access level (0.8.687 – the link "Show in the users list" of an access level)
			'anyacc' => ['label' => t('users_flt_anyacc'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($db): array {
					$o = [];
					foreach ($db->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code') as $a) $o[(string)$a['zzzzsys_access_id']] = [(string)$a['sal_code'], (string)$a['sal_description']];
					return $o;
				},
				'where' => static fn(string $v) => NuUserAccess::ready()
					? ['(u.sus_zzzzsys_access_id = ? OR EXISTS (SELECT 1 FROM ' . NuUserAccess::TABLE . ' x WHERE ' . $db->sameText('x.user_id', 'u.zzzzsys_user_id')
						. ' AND x.access_id = ? AND (x.valid_until IS NULL OR x.valid_until = \'\' OR x.valid_until >= ?)))', [$v, $v, date('Y-m-d')]]
					: ['u.sus_zzzzsys_access_id = ?', [$v]]],
			// users with an active permission item (0.8.687)
			'perm' => ['label' => t('users_flt_perm'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($db): array {
					if (!$db->tableExists(NuPermission::ITEM)) return [];
					$o = [];
					foreach ($db->all('SELECT zzzzsys_permission_item_id, pme_ident, pme_description FROM ' . NuPermission::ITEM . ' ORDER BY pme_ident') as $i) $o[(string)$i['zzzzsys_permission_item_id']] = [(string)$i['pme_ident'], (string)$i['pme_description']];
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
					? ['NOT EXISTS (SELECT 1 FROM zzzzsys_nunext_admin_user au WHERE ' . $db->sameText('au.zzzzsys_nunext_admin_user_id', 'u.zzzzsys_user_id') . ')', []]
					: ['EXISTS (SELECT 1 FROM zzzzsys_nunext_admin_user au WHERE ' . $db->sameText('au.zzzzsys_nunext_admin_user_id', 'u.zzzzsys_user_id') . ' AND au.role_id = ?)', [$v]]];
		}

		// custom user fields (0.8.454, core/nuuserfields.php): the ones marked "in the users list" as columns (hidden by default, chosen in a
		// list view), filters for choice lists, yes / no, dates and short texts; text fields also found by the search
		$from = ' FROM zzzzsys_user u LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id';
		$search = ['u.sus_login_name', 'u.sus_name', 'u.sus_first_name', 'u.sus_last_name', 'u.sus_email', 'u.sus_code', 'u.sus_department', 'u.sus_team', 'u.sus_position'];
		if (NuUserFields::ready()) {
			$from .= ' LEFT JOIN ' . NuUserFields::EXT . ' ux ON ' . $db->sameText('ux.' . NuUserFields::KEY, 'u.zzzzsys_user_id');
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
			'search_ph' => t('user_search_ph'), 'open_min' => 800,
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['name', 'dept', 'position', 'access', 'login', 'code', 'email'], 'card' => ['title' => 'name', 'badge' => 'access', 'lines' => ['login', 'dept', 'email']],
					'sort' => ['name', 'asc'], 'filters' => ['shown' => ['access', 'pos', 'dept', 'team', 'state'], 'values' => ['state' => 'active']]],   // department + team (0.8.687)
				'compact' => ['columns' => ['name', 'access', 'login'], 'card' => ['title' => 'name', 'badge' => 'access', 'lines' => ['login']],
					'sort' => ['name', 'asc'], 'filters' => ['shown' => ['access', 'state'], 'values' => ['state' => 'active']]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'name', 'badge' => 'access', 'lines' => ['login', 'dept', 'email', 'status']],
					'sort' => ['name', 'asc'], 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}

	// ------------------------------------------------------------------ access levels

	private static function access(): array
	{
		$db = nu_db();
		$rights = NuAcl::rights(NuAcl::FORM_ACCESS);
		$nUsers = '(SELECT COUNT(*) FROM zzzzsys_user u WHERE u.sus_zzzzsys_access_id = a.zzzzsys_access_id)';
		$nForms = '(SELECT COUNT(*) FROM zzzzsys_access_form f WHERE f.slf_zzzzsys_access_id = a.zzzzsys_access_id)';
		$nProc = $db->tableExists('zzzzsys_access_php') ? '(SELECT COUNT(*) FROM zzzzsys_access_php p WHERE p.slp_zzzzsys_access_id = a.zzzzsys_access_id)' : '0';
		$nRep = $db->tableExists('zzzzsys_access_report') ? '(SELECT COUNT(*) FROM zzzzsys_access_report r WHERE r.sre_zzzzsys_access_id = a.zzzzsys_access_id)' : '0';
		$num = static fn(string $alias, string $label, string $sql) => ['label' => $label, 'select' => [$alias => $sql], 'sort' => $sql, 'align' => 'end',
			'text' => static fn($r) => (string)(int)$r[$alias]];
		$cols = [
			'clone' => ['label' => '', 'select' => [], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'if' => static fn() => $rights['clone'],
				'text' => static fn($r) => '',
				'html' => static fn($r) => '<a class="btn btn-sm btn-outline-secondary" href="' . h(nu_url('accesslevel', ['clone' => $r['__id']])) . '" title="' . h(t('clone')) . '" aria-label="' . h(t('clone')) . '"><i class="bi bi-copy"></i></a>'],
			'code' => ['label' => t('access_code'), 'main' => true, 'locked' => true, 'select' => ['sal_code' => 'a.sal_code', 'sal_use_2fa' => 'a.sal_use_2fa'], 'sort' => 'a.sal_code',
				'text' => static fn($r) => (string)$r['sal_code'],
				'html' => static fn($r) => h($r['sal_code'] !== '' ? $r['sal_code'] : '—') . ((string)$r['sal_use_2fa'] === '1' ? ' <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1 fw-normal">2FA</span>' : '')],
			'group' => ['label' => t('access_group'), 'select' => ['sal_group' => 'a.sal_group'], 'sort' => 'a.sal_group', 'text' => static fn($r) => (string)$r['sal_group']],
			'description' => ['label' => t('access_description'), 'select' => ['sal_description' => 'a.sal_description'], 'sort' => 'a.sal_description', 'text' => static fn($r) => (string)$r['sal_description']],
			'home_form' => ['label' => t('home_form'), 'select' => ['sal_zzzzsys_form_id' => 'a.sal_zzzzsys_form_id'], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => $r['sal_zzzzsys_form_id'] ? nu_form_label((string)$r['sal_zzzzsys_form_id']) : ''],
			'users' => $num('n_users', t('nav_users'), $nUsers) + ['html' => static fn($r) => (int)$r['n_users'] > 0 && NuAcl::can(NuAcl::FORM_USER)
				? '<a class="text-decoration-none" href="' . h(nu_url('users', ['access' => $r['__id'], 'state' => 'all'])) . '">' . (int)$r['n_users'] . '</a>' : (string)(int)$r['n_users']],
			// the counts open the access level on the matching tab (0.8.687)
			'forms' => $num('n_forms', t('access_forms'), $nForms) + ['html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('accesslevel', ['id' => $r['__id'], 'tab' => 'rights'])) . '">' . (int)$r['n_forms'] . '</a>'],
			'procedures' => $num('n_proc', t('proc_title'), $nProc) + ['html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('accesslevel', ['id' => $r['__id']])) . '#accProcRights">' . (int)$r['n_proc'] . '</a>'],
			'reports' => $num('n_rep', t('rpt_title_list'), $nRep) + ['html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('accesslevel', ['id' => $r['__id']])) . '#accProcRights">' . (int)$r['n_rep'] . '</a>'],
			'twofa' => ['label' => '2FA', 'select' => ['sal_use_2fa' => 'a.sal_use_2fa'], 'sort' => 'a.sal_use_2fa', 'align' => 'center',
				'text' => static fn($r) => (string)$r['sal_use_2fa'] === '1' ? t('yes') : '',
				'html' => static fn($r) => (string)$r['sal_use_2fa'] === '1' ? '<i class="bi bi-shield-check text-success"></i>' : ''],
			'id' => ['label' => 'ID', 'select' => ['zzzzsys_access_id' => 'a.zzzzsys_access_id'], 'sort' => 'a.zzzzsys_access_id', 'class' => 'small text-body-secondary',
				'text' => static fn($r) => (string)$r['zzzzsys_access_id']],
		];
		$filters = [
			'group' => ['label' => t('access_group'), 'type' => 'distinct', 'expr' => 'a.sal_group', 'none' => t('lv_empty')],
			'twofa' => ['label' => '2FA', 'type' => 'bool', 'expr' => "a.sal_use_2fa = '1'"],
			'has_users' => ['label' => t('lv_has_users'), 'type' => 'bool', 'expr' => 'EXISTS (SELECT 1 FROM zzzzsys_user u WHERE u.sus_zzzzsys_access_id = a.zzzzsys_access_id)'],
			// the levels that can open a form (0.8.687)
			'form' => ['label' => t('access_flt_form'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($db): array {
					$o = [];
					foreach ($db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE COALESCE(sfo_type, '') <> 'subform' ORDER BY sfo_code") as $f) $o[(string)$f['zzzzsys_form_id']] = [(string)$f['sfo_code'], (string)$f['sfo_description']];
					return $o;
				},
				'where' => static fn(string $v) => ['EXISTS (SELECT 1 FROM zzzzsys_access_form af WHERE af.slf_zzzzsys_access_id = a.zzzzsys_access_id AND af.slf_zzzzsys_form_id = ?)', [$v]]],
		];
		return [
			'key' => 'access', 'title' => t('nav_access'), 'route' => 'access', 'form' => NuAcl::FORM_ACCESS,
			'from' => ' FROM zzzzsys_access a', 'where' => [], 'params' => [],
			'id' => 'a.zzzzsys_access_id', 'link' => static fn($r) => nu_url('accesslevel', ['id' => $r['__id']]),
			'search' => ['a.sal_code', 'a.sal_description', 'a.sal_group'], 'search_ph' => t('access_search_ph'), 'open_min' => 0,
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['clone', 'code', 'group', 'description', 'home_form', 'users', 'forms'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'users']],
					'sort' => ['code', 'asc'], 'filters' => ['shown' => ['group', 'form'], 'values' => []]],
				'compact' => ['columns' => ['code', 'description', 'users'], 'card' => ['title' => 'code', 'badge' => '', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'filters' => ['shown' => ['group'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'users', 'forms', 'home_form']],
					'sort' => ['code', 'asc'], 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}

	// ------------------------------------------------------------------ forms (0.8.334)

	private static function forms(): array
	{
		$u = NuAuth::user();
		$designer = NuAcl::can(NuAcl::FORM_FORM);
		$ga = !empty($u['globeadmin']);
		$canStatus = NuFormDraft::canEdit() && !NuImpersonate::readOnly();
		$status = ['1' => 'builder_status_1', '0' => 'builder_status_0', '3' => 'builder_status_3'];
		$st = "COALESCE(NULLIF(f.sfo_status, ''), '1')";
		$where = ["f.zzzzsys_form_id NOT LIKE 'nu%'"];
		$params = [];
		if (!$ga && !$designer) {   // forms of the main and the additional access levels (0.8.585)
			[$in, $lv] = NuUserAccess::inSql('slf_zzzzsys_access_id', $u);
			$where[] = "f.zzzzsys_form_id IN (SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form WHERE $in)";
			array_push($params, ...$lv);
		}
		$trail = '';
		// form drafts (0.8.386): the open draft of each form (author, id) – for designers
		$drafts = $designer && NuFormDraft::ready();
		$dWhere = "d.status IN ('open', 'review') AND " . nu_db()->sameText('d.form_id', 'f.zzzzsys_form_id');
		$dBy = $drafts ? "(SELECT MAX(d.created_by) FROM zzzzsys_nunext_form_draft d WHERE $dWhere)" : 'NULL';
		$dId = $drafts ? "(SELECT MAX(d.zzzzsys_nunext_form_draft_id) FROM zzzzsys_nunext_form_draft d WHERE $dWhere)" : 'NULL';
		$nAcc = '(SELECT COUNT(DISTINCT af.slf_zzzzsys_access_id) FROM zzzzsys_access_form af WHERE af.slf_zzzzsys_form_id = f.zzzzsys_form_id)';
		$facOk = NuAcl::can(NuAcl::FORM_ACCESS);
		$dbTool = NuAdmin::can('dbtool', 'change') && NuVendor::primaryDb() !== '';
		$cols = [
			'actions' => ['label' => '', 'select' => ['lv_draft_id' => $dId], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'if' => static fn() => $designer, 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => '<a class="btn btn-sm btn-outline-secondary" href="' . h(nu_url('builder', ['f' => $r['__id'], 'draft' => ($r['lv_draft_id'] ?? null) ? '1' : null])) . '" title="' . h(t('builder_title')) . '" aria-label="' . h(t('builder_title')) . '"><i class="bi bi-tools"></i></a>'
					. (($r['lv_draft_id'] ?? null) ? ' <a class="btn btn-sm btn-outline-warning" href="' . h(nu_url('form', ['f' => $r['__id'], 'draft' => (string)$r['lv_draft_id']])) . '" target="_blank" rel="noopener" title="' . h(t('builder_draft_preview')) . '" aria-label="' . h(t('builder_draft_preview')) . '"><i class="bi bi-cone-striped"></i></a>' : '')
					. ($ga ? ' <a class="btn btn-sm btn-outline-secondary" href="' . h(nu_url('cloner', ['f' => $r['__id']])) . '" title="' . h(t('cl_title')) . '" aria-label="' . h(t('cl_title')) . '"><i class="bi bi-files"></i></a>' : '')],
			'description' => self::col('sfo_description', 'f.sfo_description', t('builder_description')) + ['main' => true, 'locked' => true],
			'code' => self::col('sfo_code', 'f.sfo_code', t('builder_code'), 'small text-body-secondary'),
			'type' => ['label' => t('builder_type'), 'select' => ['sfo_type' => 'f.sfo_type', 'lv_page_code' => 'f.sfo_code'], 'sort' => 'f.sfo_type',
				'text' => static fn($r) => t('form_type_' . ($r['sfo_type'] ?: 'browseedit')),
				'html' => static fn($r) => '<span class="badge nuFormType nuFormType-' . h($r['sfo_type'] ?: 'browseedit') . '">' . h(t('form_type_' . ($r['sfo_type'] ?: 'browseedit'))) . '</span>'
					. ($designer && $r['sfo_type'] === 'page' ? self::pageApiBadge((string)$r['lv_page_code']) : '')],
			// the table opens in the database tool (0.8.688; phpMyAdmin / Adminer, a POST with the CSRF token, new tab)
			'table' => self::col('sfo_table', 'f.sfo_table', t('builder_table'), 'small') + ['html' => static fn($r) => (string)$r['sfo_table'] !== '' && $dbTool
				? '<form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="d-inline m-0">' . nu_csrf_field() . '<input type="hidden" name="app" value="DB"><input type="hidden" name="table" value="' . h((string)$r['sfo_table']) . '">'
					. '<button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none nuTableLink" title="' . h(t('forms_table_open')) . '">' . h((string)$r['sfo_table']) . ' <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i></button></form>'
				: h((string)$r['sfo_table'])],
			'group' => array_replace(self::col('sfo_group', 'f.sfo_group', t('builder_group'), 'small'), ['sort' => 'f.sfo_group, f.sfo_description']),
			// how many access levels may open the form; the number opens "Access levels of this form" (0.8.688, assets/js/nu/nuformaccess.js)
			'access' => ['label' => t('forms_col_access'), 'select' => ['lv_nacc' => $nAcc], 'sort' => $nAcc, 'align' => 'end', 'class' => 'small',
				'text' => static fn($r) => (string)(int)$r['lv_nacc'],
				'html' => static fn($r) => $facOk && ($r['sfo_type'] ?? '') !== 'subform'
					? '<button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-nu-fac="' . h((string)$r['__id']) . '" title="' . h(t('fac_title')) . '">' . (int)$r['lv_nacc'] . '</button>'
					: (string)(int)$r['lv_nacc']],
			'layout' => ['label' => t('form_layout'), 'select' => ['sfo_json' => 'f.sfo_json'], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => t('form_layout_' . (NuFormMeta::nunextJson($r['sfo_json'])['layout'] ?? 'absolute'))],
			'status' => ['label' => t('builder_status'), 'select' => ['lv_status' => $st], 'sort' => $st,
				'text' => static fn($r) => t($status[(string)$r['lv_status']] ?? 'builder_status_1'),
				// form designers: right-click / long press = menu to change the status (0.8.575, assets/js/nuformstatus.js → builderapi form.save)
				'html' => static fn($r) => ($canStatus ? '<span class="nuFormStatus" tabindex="0" data-nu-form="' . h((string)$r['__id']) . '" data-nu-st="' . h((string)$r['lv_status']) . '" title="' . h(t('forms_status_change_tip')) . '">' : '')
					. self::badge(t($status[(string)$r['lv_status']] ?? 'builder_status_1'), match ((string)$r['lv_status']) {
					'0' => 'bg-warning-subtle text-warning-emphasis', '3' => 'bg-secondary-subtle text-secondary-emphasis', default => 'bg-success-subtle text-success-emphasis' }) . ($canStatus ? '</span>' : '')],
			'draft' => ['label' => t('forms_col_draft'), 'select' => ['lv_draft_by' => $dBy], 'sort' => $drafts ? $dBy : null, 'class' => 'small', 'if' => static fn() => $drafts,
				'text' => static fn($r) => $r['lv_draft_by'] !== null ? t('forms_draft_by', (string)$r['lv_draft_by']) : '',
				'html' => static fn($r) => $r['lv_draft_by'] !== null ? self::badge(t('forms_draft_by', (string)$r['lv_draft_by']), 'bg-warning-subtle text-warning-emphasis') : ''],
			'id' => self::idCol('f.zzzzsys_form_id'),
		];
		$filters = [
			'type' => ['label' => t('builder_type'), 'type' => 'select', 'options' => static fn() => array_combine($t = ['browseedit', 'browse', 'edit', 'launch', 'subform', 'page'], array_map(static fn($x) => t('form_type_' . $x), $t)),
				'where' => static fn(string $v) => ["COALESCE(NULLIF(f.sfo_type, ''), 'browseedit') = ?", [$v]]],
			'group' => ['label' => t('builder_group'), 'type' => 'distinct', 'expr' => 'f.sfo_group', 'none' => t('forms_group_none')],
			'status' => ['label' => t('builder_status'), 'type' => 'select', 'all' => 'all',
				'options' => static fn() => ['na' => t('forms_status_not_archived')] + array_map('t', $status),
				'where' => static fn(string $v) => $v === 'na' ? ["$st <> '3'", []] : (isset($status[$v]) ? ["$st = ?", [$v]] : null)],
			'table' => ['label' => t('builder_table'), 'type' => 'distinct', 'expr' => 'f.sfo_table', 'none' => t('lv_empty')],
		];
		if ($drafts) $filters['draft'] = ['label' => t('forms_col_draft'), 'type' => 'select', 'options' => static fn() => ['1' => t('forms_with_draft')],
			'where' => static fn(string $v) => $v === '1' ? ["$dBy IS NOT NULL", []] : null];
		return [
			'key' => 'forms', 'title' => t('nav_forms'), 'route' => 'forms', 'form' => NuAcl::FORM_FORM, 'public' => true, 'from' => ' FROM zzzzsys_form f', 'where' => $where, 'params' => $params,
			'id' => 'f.zzzzsys_form_id', 'always' => ['type'],
			'rows' => static function (array $rows) use (&$trail): array {   // breadcrumb + Back of the opened form lead here (0.8.255)
				$trail = $rows ? NuTrail::store([['title' => t('nav_forms'), 'url' => NuTrail::currentUrl()]]) : '';
				return $rows;
			},
			'link' => static function ($r) use (&$trail, $designer): string {   // subforms cannot run on their own: the builder (designers)
				if (($r['sfo_type'] ?? '') === 'subform') return $designer ? nu_url('builder', ['f' => $r['__id']]) : '';
				return nu_url('form', ['f' => $r['__id'], 'bt' => $trail]);
			},
			'search' => ['f.sfo_code', 'f.sfo_description', 'f.sfo_table', 'f.sfo_group'], 'search_ph' => t('forms_search_ph'), 'open_min' => 800,
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['actions', 'description', 'code', 'type', 'table', 'group', 'layout', 'status', 'draft', 'access'], 'card' => ['title' => 'description', 'badge' => 'type', 'lines' => ['code', 'table', 'group']],
					'sort' => ['group', 'asc'], 'per' => 500, 'filters' => ['shown' => array_values(array_filter(['type', 'group', 'status', $drafts ? 'draft' : null])), 'values' => ['status' => 'na']]],   // Has draft (0.8.688)
				'compact' => ['columns' => ['actions', 'description', 'code', 'type'], 'card' => ['title' => 'description', 'badge' => 'type', 'lines' => ['code']],
					'sort' => ['description', 'asc'], 'per' => 100, 'filters' => ['shown' => ['type', 'status'], 'values' => ['status' => 'na']]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'description', 'badge' => 'type', 'lines' => ['code', 'table', 'group', 'status']],
					'sort' => ['group', 'asc'], 'per' => 500, 'filters' => ['shown' => array_keys($filters), 'values' => ['status' => 'na']]],
			],
		];
	}

	// ------------------------------------------------------------------ procedures

	private static function procedures(): array
	{
		$nAccess = '(SELECT COUNT(*) FROM zzzzsys_access_php a WHERE a.slp_zzzzsys_php_id = p.zzzzsys_php_id)';
		$statusText = static fn($s) => t(NuProcedure::STATUS[(string)($s ?? '1') ?: '1'] ?? 'proc_status_active');
		$cols = [
			'code' => array_replace(self::col('sph_code', 'p.sph_code', t('proc_code')), ['main' => true, 'locked' => true, 'select' => ['sph_code' => 'p.sph_code', 'sph_template' => 'p.sph_template'],   // array_replace: + kept col()'s select (0.8.553)
				'html' => static fn($r) => h($r['sph_code']) . ((string)$r['sph_template'] === '1' ? ' <span class="badge text-bg-light border ms-1 fw-normal">' . h(t('proc_template')) . '</span>' : '')]),
			'group' => array_replace(self::badgeCol('sph_group', 'p.sph_group', t('proc_group')), ['sort' => 'p.sph_group, p.sph_code']),
			'category' => self::col('sph_category', 'p.sph_category', t('proc_category'), 'small'),
			'status' => ['label' => t('proc_status'), 'select' => ['sph_status' => 'p.sph_status'], 'sort' => 'p.sph_status', 'text' => static fn($r) => $statusText($r['sph_status']),
				'html' => static fn($r) => self::badge($statusText($r['sph_status']), match ((string)($r['sph_status'] ?? '1')) {
					'0' => 'bg-warning-subtle text-warning-emphasis', '3' => 'bg-secondary-subtle text-secondary-emphasis', default => 'bg-success-subtle text-success-emphasis' })],
			'description' => self::col('sph_description', 'p.sph_description', t('proc_description'), 'small text-break'),
			'run' => ['label' => t('proc_run'), 'select' => ['sph_run' => 'p.sph_run'], 'sort' => 'p.sph_run', 'class' => 'small',
				'text' => static fn($r) => t(NuProcedure::RUN[(string)$r['sph_run']] ?? 'proc_run_default')],
			'launch' => self::col('sfo_code', 'f.sfo_code', t('proc_launch'), 'small'),
			'access' => ['label' => t('proc_access'), 'select' => ['sph_global' => 'p.sph_global', 'n_access' => $nAccess], 'sort' => $nAccess, 'align' => 'end', 'class' => 'small',
				'text' => static fn($r) => (string)$r['sph_global'] === '1' ? t('proc_everyone') : (string)(int)$r['n_access'],
				// opens the procedure's tab Access levels (0.8.688)
				'html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('procedures', ['id' => $r['__id']])) . '#access">'
					. ((string)$r['sph_global'] === '1' ? self::badge(t('proc_everyone'), 'bg-info-subtle text-info-emphasis') : (string)(int)$r['n_access']) . '</a>'],
			'id' => self::idCol('p.zzzzsys_php_id'),
		];
		$filters = [
			'group' => ['label' => t('proc_group'), 'type' => 'distinct', 'expr' => 'p.sph_group', 'none' => t('lv_empty')],
			'status' => ['label' => t('proc_status'), 'type' => 'select', 'options' => static fn() => array_map('t', NuProcedure::STATUS),
				'where' => static fn(string $v) => ["COALESCE(NULLIF(p.sph_status, ''), '1') = ?", [$v]]],
			'category' => ['label' => t('proc_category'), 'type' => 'distinct', 'expr' => 'p.sph_category', 'none' => t('lv_empty')],
			'template' => ['label' => t('proc_template'), 'type' => 'bool', 'expr' => "p.sph_template = '1'"],
			'global' => ['label' => t('proc_everyone'), 'type' => 'bool', 'expr' => "p.sph_global = '1'"],
			'run' => ['label' => t('proc_run'), 'type' => 'select', 'options' => static fn() => ['default' => t('proc_run_default'), 'hide' => t('proc_run_hidden'), 'window' => t('proc_run_window')],   // how it runs (0.8.688)
				'where' => static fn(string $v) => match ($v) {
					'default' => ["COALESCE(p.sph_run, '') NOT IN ('hide', 'window')", []],
					'hide', 'window' => ['p.sph_run = ?', [$v]],
					default => null,
				}],
		];
		return [
			'key' => 'procedures', 'title' => t('proc_title'), 'route' => 'procedures', 'form' => NuAcl::FORM_PROCEDURE,
			'from' => ' FROM ' . NuProcedure::TABLE . ' p LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = p.sph_zzzzsys_form_id', 'where' => [NuProcedure::listCondition('p')], 'params' => [],
			'id' => 'p.zzzzsys_php_id', 'link' => static fn($r) => nu_url('procedures', ['id' => $r['__id']]),
			'search' => ['p.sph_code', 'p.sph_description', 'p.sph_group', 'p.sph_category', 'f.sfo_code'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['code', 'group', 'category', 'status', 'description', 'run', 'launch', 'access'], 'card' => ['title' => 'code', 'badge' => 'status', 'lines' => ['description', 'group']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'status', 'category', 'run'], 'values' => []]],   // category + run (0.8.688)
				'compact' => ['columns' => ['code', 'description', 'status'], 'card' => ['title' => 'code', 'badge' => 'status', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'status', 'lines' => ['description', 'group', 'launch', 'access']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}

	// ------------------------------------------------------------------ reports

	private static function reports(): array
	{
		$nAccess = '(SELECT COUNT(*) FROM zzzzsys_access_report a WHERE a.sre_zzzzsys_report_id = r.zzzzsys_report_id)';
		$canSel = NuAcl::can(NuAcl::FORM_SELECT);
		$canProc = NuAcl::can(NuAcl::FORM_PROCEDURE);
		$cols = [
			'actions' => ['label' => '', 'select' => ['sre_code' => 'r.sre_code'], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => '<a class="btn btn-sm btn-outline-secondary" href="' . h(nu_url('reportdesigner', ['id' => $r['__id']])) . '" title="' . h(t('rpt_designer')) . '" aria-label="' . h(t('rpt_designer')) . '"><i class="bi bi-vector-pen"></i></a>'
					. ' <a class="btn btn-sm btn-outline-primary" href="' . h(nu_url('report', ['c' => (string)$r['sre_code']])) . '" target="_blank" rel="noopener" title="' . h(t('rpt_run')) . '" aria-label="' . h(t('rpt_run')) . '"><i class="bi bi-file-earmark-pdf"></i></a>'],
			'code' => self::col('sre_code', 'r.sre_code', t('rpt_code')) + ['main' => true, 'locked' => true],
			'description' => self::col('sre_description', 'r.sre_description', t('rpt_description'), 'small text-break'),
			'group' => array_replace(self::badgeCol('sre_group', 'r.sre_group', t('rpt_group')), ['sort' => 'r.sre_group, r.sre_code']),
			'source' => ['label' => t('rpt_source'), 'select' => ['sre_src' => 'r.sre_zzzzsys_php_id'], 'sort' => 'r.sre_zzzzsys_php_id', 'class' => 'small',
				'text' => static fn($r) => NuReport::sourceLabel((string)$r['sre_src']),
				// the query / procedure opens in its editor (0.8.688)
				'html' => static function ($r) use ($canSel, $canProc): string { [$kind] = explode(':', (string)$r['sre_src'] . ':');
					$id = substr((string)$r['sre_src'], strlen($kind) + 1);
					$href = match ($kind) { 'SQL' => $canSel ? nu_url('sqlbuilder', ['id' => $id]) : '', 'PROCEDURE' => $canProc ? nu_url('procedures', ['id' => $id]) : '', default => '' };
					return self::badge($kind, 'bg-info-subtle text-info-emphasis me-1') . ($href !== '' ? '<a class="text-decoration-none" href="' . h($href) . '">' . h($id) . '</a>' : h($id)); }],
			// the launch form opens (0.8.688)
			'launch' => array_replace(self::col('sfo_code', 'f.sfo_code', t('rpt_launch'), 'small'), ['select' => ['sfo_code' => 'f.sfo_code', 'lv_launch_id' => 'f.zzzzsys_form_id'],
				'html' => static fn($r) => (string)($r['sfo_code'] ?? '') !== '' ? '<a class="text-decoration-none" href="' . h(nu_url('form', ['f' => (string)$r['lv_launch_id']])) . '">' . h((string)$r['sfo_code']) . '</a>' : '']),
			// opens the report's tab Access levels (0.8.688)
			'access' => self::col('n_access', $nAccess, t('rpt_access'), 'small') + ['align' => 'end',
				'html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('reports', ['id' => $r['__id']])) . '#access">' . (int)$r['n_access'] . '</a>'],
			'id' => self::idCol('r.zzzzsys_report_id'),
		];
		$filters = [
			'group' => ['label' => t('rpt_group'), 'type' => 'distinct', 'expr' => 'r.sre_group', 'none' => t('lv_empty')],
			'launch' => ['label' => t('rpt_launch'), 'type' => 'distinct', 'expr' => 'f.sfo_code', 'none' => t('lv_empty')],
			// kind of the data source (0.8.688)
			'srctype' => ['label' => t('rpt_flt_srctype'), 'type' => 'select',
				'options' => static fn() => ['TABLE' => t('rpt_src_table'), 'SQL' => t('rpt_src_sql'), 'PROCEDURE' => t('rpt_src_procedure')],
				'where' => static fn(string $v) => in_array($v, ['TABLE', 'SQL', 'PROCEDURE'], true) ? ['r.sre_zzzzsys_php_id LIKE ?', [$v . ':%']] : null],
			// one data source (the SQL builder's Reports count links here, 0.8.688)
			'src' => ['label' => t('rpt_source'), 'type' => 'select',
				'options' => static function (): array { $o = []; foreach (nu_db()->all('SELECT DISTINCT sre_zzzzsys_php_id AS s FROM ' . NuReport::TABLE . ' WHERE sre_zzzzsys_php_id IS NOT NULL ORDER BY 1') as $x) $o[(string)$x['s']] = NuReport::sourceLabel((string)$x['s']); return $o; },
				'where' => static fn(string $v) => ['r.sre_zzzzsys_php_id = ?', [$v]]],
		];
		return [
			'key' => 'reports', 'title' => t('rpt_title_list'), 'route' => 'reports', 'form' => NuAcl::FORM_REPORT,
			'from' => ' FROM ' . NuReport::TABLE . ' r LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = r.sre_zzzzsys_form_id', 'where' => [], 'params' => [],
			'id' => 'r.zzzzsys_report_id', 'link' => static fn($r) => nu_url('reports', ['id' => $r['__id']]),
			'search' => ['r.sre_code', 'r.sre_description', 'r.sre_group', 'r.sre_zzzzsys_php_id', 'f.sfo_code'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['actions', 'code', 'description', 'group', 'source', 'launch', 'access'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'source']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'srctype'], 'values' => []]],
				'compact' => ['columns' => ['actions', 'code', 'description'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'source', 'launch', 'access']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}

	// ------------------------------------------------------------------ files (grid by default)

	private static function files(): array
	{
		NuFile::ensureTable();
		$ga = !empty(NuAuth::user()['globeadmin']);
		$denied = (string)(NuMailer::setupRow()['set_denied'] ?? '') === '1';
		$isSystem = static fn(array $f) => (string)($f['sfi_system'] ?? '') === '1' || str_starts_with((string)$f['__id'], 'nu');
		$info = static fn(array $r) => NuFile::info((string)($r['sfi_json'] ?? ''));
		$hasImg = isset(nu_db()->columns('zzzzsys_object')['sob_image_zzzzsys_file_id']);
		$nUsed = $hasImg ? '(SELECT COUNT(*) FROM zzzzsys_object o WHERE o.sob_image_zzzzsys_file_id = ' . NuFile::TABLE . '.zzzzsys_file_id)' : '0';
		$cols = [
			'preview' => ['label' => t('lv_preview'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'nuLvThumbCell', 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => ($info($r)['image'] ?? false) ? '<img class="nuLvThumb" src="' . h(nu_url('files', ['raw' => $r['__id']])) . '" alt="" loading="lazy">' : '<i class="bi bi-file-earmark text-body-secondary fs-4"></i>'],
			'code' => self::col('sfi_code', 'sfi_code', t('file_code')) + ['main' => true, 'locked' => true],
			'description' => self::col('sfi_description', 'sfi_description', t('file_description'), 'small text-break'),
			'group' => array_replace(self::badgeCol('sfi_group', 'sfi_group', t('file_group')), ['sort' => 'sfi_group, sfi_code']),
			'name' => ['label' => t('file_name'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'small text-break', 'text' => static fn($r) => (string)($info($r)['name'] ?? '')],
			'type' => ['label' => t('file_type'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'small', 'text' => static fn($r) => (string)($info($r)['type'] ?? '')],
			'size' => ['label' => t('file_size'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'align' => 'end', 'class' => 'small text-nowrap',
				'text' => static fn($r) => ($i = $info($r)) ? NuFile::size((int)$i['size']) : ''],
			// how many objects show the file (0.8.688, NuFile::usage); the number opens the file's "Used by"
			'used' => ['label' => t('file_used_by'), 'select' => ['lv_used' => $nUsed], 'sort' => $hasImg ? $nUsed : null, 'align' => 'end', 'class' => 'small',
				'text' => static fn($r) => (string)(int)$r['lv_used'],
				'html' => static fn($r) => (int)$r['lv_used'] > 0 ? '<a class="text-decoration-none" href="' . h(nu_url('files', ['id' => $r['__id']])) . '#fileUsedBy">' . (int)$r['lv_used'] . '</a>' : '0'],
			'system' => ['label' => t('file_system'), 'select' => ['sfi_system' => 'sfi_system'], 'sort' => null, 'align' => 'center',
				'text' => static fn($r) => $isSystem($r) ? t('yes') : '', 'html' => static fn($r) => $isSystem($r) ? self::badge(t('file_system'), 'bg-info-subtle text-info-emphasis') : ''],
			'id' => self::idCol('zzzzsys_file_id'),
		];
		return [
			'key' => 'files', 'title' => t('file_title'), 'route' => 'files', 'form' => NuAcl::FORM_FILE, 'from' => ' FROM ' . NuFile::TABLE,
			'where' => $denied && !$ga ? ["zzzzsys_file_id NOT LIKE 'nu%'"] : [], 'params' => [],
			'id' => 'zzzzsys_file_id', 'link' => static fn($r) => nu_url('files', ['id' => $r['__id']]), 'always' => ['preview', 'code', 'description', 'group', 'size', 'system', 'used'],
			'search' => ['sfi_code', 'sfi_description', 'sfi_group'], 'columns' => $cols,
			'filters' => ['group' => ['label' => t('file_group'), 'type' => 'distinct', 'expr' => 'sfi_group', 'none' => t('lv_empty')],
				// kind of file by its stored MIME type (0.8.688; "type" in sfi_json, also with an escaped slash)
				'type' => ['label' => t('file_type'), 'type' => 'select', 'options' => static fn() => ['image' => t('file_type_image'), 'pdf' => 'PDF', 'other' => t('file_type_other')],
					'where' => static fn(string $v) => match ($v) {
						'image' => ["sfi_json LIKE '%\"type\":\"image%'", []],
						'pdf' => ["sfi_json LIKE '%\"type\":\"application%pdf\"%'", []],
						'other' => ["sfi_json NOT LIKE '%\"type\":\"image%' AND sfi_json NOT LIKE '%\"type\":\"application%pdf\"%'", []],
						default => null,
					}],
				'used' => ['label' => t('file_flt_used'), 'type' => 'bool', 'expr' => $hasImg ? "$nUsed > 0" : '1 = 0']],
			'grid' => static function (array $r, string $href) use ($info, $isSystem): string {
				$i = $info($r);
				return '<div class="col-6 col-md-4 col-lg-3 col-xxl-2"><a class="card h-100 shadow-sm text-decoration-none nuFileCard" href="' . h($href) . '"><div class="nuFileThumbBox">'
					. (($i['image'] ?? false) ? '<img src="' . h(nu_url('files', ['raw' => $r['__id']])) . '" alt="" loading="lazy">' : '<i class="bi bi-file-earmark' . ($i ? '' : '-x') . ' display-5 text-body-secondary"></i>')
					. '</div><div class="card-body p-2"><div class="fw-semibold text-truncate" title="' . h($r['sfi_code']) . '">' . h($r['sfi_code']) . '</div>'
					. '<div class="small text-body-secondary text-truncate" title="' . h($r['sfi_description']) . '">' . h($r['sfi_description'] ?: ($i['name'] ?? '')) . '</div><div class="d-flex flex-wrap gap-1 mt-1">'
					. ((string)$r['sfi_group'] !== '' ? self::badge((string)$r['sfi_group']) : '') . ($isSystem($r) ? self::badge(t('file_system'), 'bg-info-subtle text-info-emphasis') : '')
					. ((int)($r['lv_used'] ?? 0) > 0 ? '<span class="small text-body-secondary" title="' . h(t('file_used_by')) . '"><i class="bi bi-link-45deg"></i>' . (int)$r['lv_used'] . '</span>' : '')   // used by N objects (0.8.688)
					. ($i ? '<span class="small text-body-secondary ms-auto">' . h(NuFile::size((int)$i['size'])) . '</span>' : '') . '</div></div></a></div>';
			},
			'builtin' => [
				'default' => ['display' => 'grid', 'columns' => ['preview', 'code', 'description', 'group', 'size'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'size']],
					'sort' => ['group', 'asc'], 'per' => 48, 'filters' => ['shown' => ['group', 'type', 'used'], 'values' => []]],
				'compact' => ['columns' => ['code', 'description', 'group'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'name', 'type', 'size']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
			],
		];
	}

	// ------------------------------------------------------------------ items

	private static function items(): array
	{
		NuItem::ensureTable();
		$denied = (string)(NuMailer::setupRow()['set_denied'] ?? '') === '1';
		$upd = static fn($r) => ($u = $r['itm_updated_on'] ?: $r['itm_created_on']) ? date('d.m.Y H:i', strtotime((string)$u)) : '';
		$cols = [
			'form' => array_replace(self::col('sfo_code', 'f.sfo_code', t('item_form_code'), 'small'), ['sort' => 'f.sfo_code, o.sob_all_id, i.itm_group, i.itm_sort_order, i.itm_value',
				'select' => ['sfo_code' => 'f.sfo_code', 'lv_item_form' => 'f.zzzzsys_form_id'],   // the form opens (0.8.688)
				'html' => static fn($r) => (string)($r['sfo_code'] ?? '') !== '' && NuAcl::can((string)$r['lv_item_form'])
					? '<a class="text-decoration-none" href="' . h(nu_url('form', ['f' => (string)$r['lv_item_form']])) . '">' . h((string)$r['sfo_code']) . '</a>' : h((string)($r['sfo_code'] ?? ''))]),
			'object' => self::col('sob_all_id', 'o.sob_all_id', t('item_object'), 'small'),
			'value' => ['label' => t('item_value'), 'main' => true, 'locked' => true, 'select' => ['itm_value' => 'i.itm_value', 'itm_icon' => 'i.itm_icon'], 'sort' => 'i.itm_value', 'class' => 'text-break',
				'text' => static fn($r) => (string)$r['itm_value'],
				'html' => static fn($r) => ((string)$r['itm_icon'] !== '' ? '<i class="' . h($r['itm_icon']) . ' me-1"></i>' : '') . h($r['itm_value'])],
			'description' => self::col('itm_description', 'i.itm_description', t('item_description'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['itm_description'], 0, 90, '…'))],
			'group' => self::badgeCol('itm_group', 'i.itm_group', t('item_group')),
			'category' => self::col('itm_category', 'i.itm_category', t('item_category'), 'small'),
			'tags' => self::col('itm_tag', 'i.itm_tag', t('item_tags'), 'small'),
			'language' => self::col('itm_language', 'i.itm_language', t('item_language'), 'small'),
			'sort_order' => self::col('itm_sort_order', 'i.itm_sort_order', t('lv_sort_order'), 'small') + ['align' => 'end'],
			'active' => ['label' => t('item_active'), 'select' => ['itm_active' => 'i.itm_active'], 'sort' => 'i.itm_active', 'align' => 'center',
				'text' => static fn($r) => (string)$r['itm_active'] === '0' ? t('no') : t('yes'),
				'html' => static fn($r) => (string)$r['itm_active'] === '0' ? '<span class="text-danger">✖</span>' : '<span class="text-success">✔</span>'],
			'updated' => ['label' => t('item_updated_on'), 'select' => ['itm_updated_on' => 'i.itm_updated_on', 'itm_created_on' => 'i.itm_created_on'], 'sort' => 'i.itm_updated_on', 'class' => 'small text-nowrap', 'text' => $upd],
			'id' => self::idCol('i.zzzzsys_item_id'),
		];
		$filters = [
			'group' => ['label' => t('item_group'), 'type' => 'distinct', 'expr' => 'i.itm_group', 'none' => t('lv_empty')],
			'active' => ['label' => t('item_active'), 'type' => 'select', 'all' => 'all',
				'options' => static fn() => ['1' => '✔ ' . t('item_active_only'), '0' => '✖ ' . t('item_inactive_only')],
				'where' => static fn(string $v) => $v === '1' ? ["COALESCE(i.itm_active, '1') <> '0'", []] : ($v === '0' ? ["i.itm_active = '0'", []] : null)],
			'category' => ['label' => t('item_category'), 'type' => 'distinct', 'expr' => 'i.itm_category', 'none' => t('lv_empty')],
			'language' => ['label' => t('item_language'), 'type' => 'distinct', 'expr' => 'i.itm_language', 'none' => t('lv_empty')],
			'form' => ['label' => t('item_form_code'), 'type' => 'distinct', 'expr' => 'f.sfo_code', 'none' => t('lv_empty')],
		];
		return [
			'key' => 'items', 'title' => t('item_title'), 'route' => 'items', 'form' => NuAcl::FORM_ITEM,
			'from' => ' FROM zzzzsys_item i LEFT JOIN zzzzsys_object o ON o.zzzzsys_object_id = i.itm_object_id LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id',
			'where' => $denied ? ["i.zzzzsys_item_id NOT LIKE 'nu%'"] : [], 'params' => [],
			'id' => 'i.zzzzsys_item_id', 'link' => static fn($r) => nu_url('items', ['id' => $r['__id']]),
			'search' => ['i.itm_value', 'i.itm_description', 'i.itm_category', 'i.itm_tag', 'i.itm_language', 'o.sob_all_id', 'f.sfo_code'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['form', 'object', 'value', 'description', 'group', 'category', 'tags', 'language', 'active', 'updated'], 'card' => ['title' => 'value', 'badge' => 'group', 'lines' => ['description', 'form', 'object']],
					'sort' => ['form', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'active', 'form'], 'values' => ['active' => '1']]],   // form (0.8.688)
				'compact' => ['columns' => ['value', 'description', 'group'], 'card' => ['title' => 'value', 'badge' => 'group', 'lines' => ['description']],
					'sort' => ['value', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'active'], 'values' => ['active' => '1']]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'value', 'badge' => 'group', 'lines' => ['description', 'form', 'object', 'category']],
					'sort' => ['form', 'asc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => ['active' => '1']]],
			],
		];
	}

	// ------------------------------------------------------------------ translations (?view= is the tab "Missing" there: views use ?lv=)

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

	// ------------------------------------------------------------------ permission items

	private static function permissions(): array
	{
		$U = NuPermission::USER;
		$nUsers = "(SELECT COUNT(DISTINCT p.upe_zzzzsys_user_id) FROM $U p WHERE p.upe_ident = i.zzzzsys_permission_item_id AND p.upe_active = '1')";
		$cols = [
			'ident' => self::col('pme_ident', 'i.pme_ident', t('perm_ident'), 'font-monospace text-break') + ['main' => true, 'locked' => true],
			'group' => array_replace(self::badgeCol('pme_group', 'i.pme_group', t('perm_group')), ['sort' => 'i.pme_group, i.pme_ident']),
			'description' => self::col('pme_description', 'i.pme_description', t('perm_description'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['pme_description'], 0, 120, '…'))],
			'users' => self::col('n_users', $nUsers, t('perm_users')) + ['align' => 'end',   // the count opens the users list filtered by the item (0.8.687)
				'html' => static fn($r) => (int)$r['n_users'] > 0 && NuAcl::can(NuAcl::FORM_USER)
					? '<a class="text-decoration-none" href="' . h(nu_url('users', ['perm' => $r['__id'], 'state' => 'all'])) . '">' . (int)$r['n_users'] . '</a>' : (string)(int)$r['n_users']],
			'id' => self::idCol('i.zzzzsys_permission_item_id'),
		];
		return [
			'key' => 'permissions', 'title' => t('perm_title'), 'route' => 'permissions', 'form' => NuAcl::FORM_PERMISSION,
			'from' => ' FROM ' . NuPermission::ITEM . ' i', 'where' => [], 'params' => [], 'id' => 'i.zzzzsys_permission_item_id',
			'link' => static fn($r) => nu_url('permissions', ['id' => $r['__id']]), 'search' => ['i.pme_ident', 'i.pme_group', 'i.pme_description'], 'columns' => $cols,
			'filters' => ['group' => ['label' => t('perm_group'), 'type' => 'distinct', 'expr' => 'i.pme_group', 'none' => t('lv_empty')],
				'has_users' => ['label' => t('lv_has_users'), 'type' => 'bool', 'expr' => "EXISTS (SELECT 1 FROM $U p WHERE p.upe_ident = i.zzzzsys_permission_item_id AND p.upe_active = '1')"]],
			'builtin' => [
				'default' => ['columns' => ['ident', 'group', 'description', 'users'], 'card' => ['title' => 'ident', 'badge' => 'group', 'lines' => ['description', 'users']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
				'compact' => ['columns' => ['ident', 'users'], 'card' => ['title' => 'ident', 'badge' => 'group', 'lines' => ['users']],
					'sort' => ['ident', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'ident', 'badge' => 'group', 'lines' => ['description', 'users']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'has_users'], 'values' => []]],
			],
		];
	}

	// ------------------------------------------------------------------ prompt generator

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
			'key' => 'prompts', 'title' => t('pg_title'), 'route' => 'prompts', 'form' => NuAcl::FORM_PROMPT, 'from' => ' FROM ' . NuPrompt::TABLE, 'where' => [], 'params' => [],
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

	// ------------------------------------------------------------------ log (zzzzsys_nunext_log)

	public static function logBadge(string $e): string
	{
		$cls = match (true) {
			str_contains($e, 'fail') || str_contains($e, 'locked') => 'bg-danger-subtle text-danger-emphasis',
			str_starts_with($e, 'login') || str_starts_with($e, 'logout') => 'bg-primary-subtle text-primary-emphasis',
			str_contains($e, 'delete') => 'bg-warning-subtle text-warning-emphasis',
			str_starts_with($e, '2fa') || str_starts_with($e, 'password') => 'bg-info-subtle text-info-emphasis',
			default => 'bg-secondary-subtle text-secondary-emphasis',
		};
		return self::badge(self::eventLabel($e), $cls);
	}

	public static function eventLabel(string $e): string
	{
		$l = t('ev_' . $e);
		return $l === 'ev_' . $e ? $e : $l;
	}

	public static function logDetails(?string $json): string
	{
		if ($json === null || $json === '') return '';
		$d = json_decode($json, true);
		if (!is_array($d)) return h($json);
		if (isset($d['fields']) && is_array($d['fields'])) {
			$out = '<ul class="list-unstyled mb-0 nuLogFields">';
			foreach ($d['fields'] as $f => $pair) {
				[$old, $new] = is_array($pair) ? array_pad(array_values($pair), 2, null) : [null, $pair];
				$out .= '<li><span class="fw-semibold">' . h((string)$f) . '</span>: <span class="text-body-secondary text-decoration-line-through">' . h(is_scalar($old) ? (string)$old : ($old === null ? '∅' : json_encode($old))) . '</span> → '
					. h(is_scalar($new) ? (string)$new : ($new === null ? '∅' : json_encode($new))) . '</li>';
			}
			unset($d['fields']);
			return $out . '</ul>' . ($d ? '<div class="small text-body-secondary">' . h((string)json_encode($d, JSON_UNESCAPED_UNICODE)) . '</div>' : '');
		}
		$parts = [];
		foreach ($d as $k => $v) $parts[] = h((string)$k) . ': ' . h(is_scalar($v) || $v === null ? (string)$v : (string)json_encode($v, JSON_UNESCAPED_UNICODE));
		return implode('<br>', $parts);
	}

	private static function log(): array
	{
		$plain = static fn(string $html) => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</li>'], ' · ', $html)), ENT_QUOTES, 'UTF-8'), ' ·');
		$cols = [
			'time' => ['label' => t('log_time'), 'select' => ['log_time' => 'log_time'], 'sort' => 'log_time', 'class' => 'text-nowrap small', 'locked' => true,
				'text' => static fn($r) => date('d.m.Y H:i:s', strtotime((string)$r['log_time']))],
			'user' => self::col('login', 'login', t('user'), 'small'),
			'event' => ['label' => t('log_event'), 'select' => ['event' => 'event'], 'sort' => 'event', 'text' => static fn($r) => self::eventLabel((string)$r['event']),
				'html' => static fn($r) => self::logBadge((string)$r['event'])],
			'object' => ['label' => t('log_object'), 'select' => ['object' => 'object', 'record_id' => 'record_id'], 'sort' => 'object', 'class' => 'small text-break',
				'text' => static fn($r) => $r['object'] . ((string)$r['record_id'] !== '' ? ' #' . $r['record_id'] : ''),
				'html' => static function ($r): string {
					$url = (string)$r['record_id'] !== '' ? self::logRecordUrl((string)$r['object'], (string)$r['record_id']) : '';
					$rec = (string)$r['record_id'] !== '' ? '<div class="text-body-secondary">' . ($url !== '' ? '<a class="nuLogRec" href="' . h($url) . '" title="' . h(t('log_open_record')) . '">#' . h($r['record_id']) . '</a>' : '#' . h($r['record_id'])) . '</div>' : '';
					return h(self::logObjectLabel((string)$r['object'])) . $rec;
				}],
			'details' => ['label' => t('log_details'), 'select' => ['details' => 'details'], 'sort' => null, 'class' => 'small nuLogDetails',
				'text' => static fn($r) => $plain(self::logDetails($r['details'])),
				// long details fold to three lines with "more" (0.8.690, assets/js/nulistview.js)
				'html' => static fn($r) => '<div class="nuLvClamp" data-more="' . h(t('lv_more')) . '" data-less="' . h(t('lv_less')) . '">' . self::logDetails($r['details']) . '</div>'],
			'ip' => ['label' => 'IP', 'select' => ['ip' => 'ip'], 'sort' => 'ip', 'class' => 'small text-body-secondary', 'text' => static fn($r) => NuDemo::ip($r['ip'])],
			'id' => self::idCol('zzzzsys_nunext_log_id'),
		];
		$filters = [
			'event' => ['label' => t('log_event'), 'type' => 'select', 'tom' => true,
				'options' => static function (): array { $o = []; foreach (nu_db()->all('SELECT DISTINCT event FROM zzzzsys_nunext_log ORDER BY event') as $r) $o[(string)$r['event']] = self::eventLabel((string)$r['event']); asort($o); return $o; },
				'where' => static fn(string $v) => ['event = ?', [$v]]],
			// user as a searchable select of the logins in the log (0.8.686; was a text field)
			'login' => ['label' => t('user'), 'type' => 'select', 'tom' => true,
				'options' => static function (): array { $o = []; foreach (nu_db()->all("SELECT DISTINCT login FROM zzzzsys_nunext_log WHERE login IS NOT NULL AND login <> '' ORDER BY login") as $r) $o[(string)$r['login']] = (string)$r['login']; return $o; },
				'where' => static fn(string $v) => ['login = ?', [$v]]],
			// object = the table of the change (0.8.690): users, access levels, forms … with readable names
			'object' => ['label' => t('log_object'), 'type' => 'select', 'tom' => true,
				'options' => static function (): array { $o = []; foreach (nu_db()->all("SELECT DISTINCT object FROM zzzzsys_nunext_log WHERE object IS NOT NULL AND object <> ''") as $r) $o[(string)$r['object']] = self::logObjectLabel((string)$r['object']); asort($o); return $o; },
				'where' => static fn(string $v) => ['object = ?', [$v]]],
			'from' => ['label' => t('log_from'), 'type' => 'datefrom', 'expr' => 'log_time'],
			'to' => ['label' => t('log_to'), 'type' => 'dateto', 'expr' => 'log_time'],
			'when' => ['label' => t('lv_period'), 'type' => 'date', 'expr' => 'log_time', 'presets' => ['d7', 'd30', 'd90']],
		];
		return [
			'key' => 'log', 'title' => t('nav_log'), 'route' => 'log', 'form' => NuAcl::FORM_LOG, 'from' => ' FROM zzzzsys_nunext_log', 'where' => [], 'params' => [],
			'id' => 'zzzzsys_nunext_log_id', 'link' => static fn($r) => '', 'table_class' => 'table-sm',
			'search' => ['object', 'record_id', 'details', 'ip'], 'search_ph' => t('log_search_ph'), 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['time', 'user', 'event', 'object', 'details', 'ip'], 'card' => ['title' => 'event', 'badge' => '', 'lines' => ['time', 'user', 'object', 'details']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['event', 'login', 'object', 'from', 'to'], 'values' => []], 'open' => true],   // filters shown (0.8.686)
				'compact' => ['columns' => ['time', 'user', 'event'], 'card' => ['title' => 'event', 'badge' => '', 'lines' => ['time', 'user']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['event', 'when'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'event', 'badge' => '', 'lines' => ['time', 'user', 'object', 'details']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []], 'open' => true],
			],
		];
	}

	/** Tables the log knows a page for (0.8.690): table => [label key, route, id parameter, form whose view right is needed]. */
	private const LOG_RECORDS = [
		'zzzzsys_user' => ['nav_users', 'user', 'id', NuAcl::FORM_USER],
		'zzzzsys_access' => ['nav_access', 'accesslevel', 'id', NuAcl::FORM_ACCESS],
		'zzzzsys_form' => ['nav_forms', 'form', 'f', NuAcl::FORM_FORM],
		'zzzzsys_php' => ['proc_title', 'procedures', 'id', NuAcl::FORM_PROCEDURE],
		'zzzzsys_report' => ['rpt_title_list', 'reports', 'id', NuAcl::FORM_REPORT],
		'zzzzsys_permission_item' => ['perm_title', 'permissions', 'id', NuAcl::FORM_PERMISSION],
	];

	/** Readable name of a log object: "Users (zzzzsys_user)" for the tables above, else the stored text. */
	public static function logObjectLabel(string $object): string
	{
		$m = self::LOG_RECORDS[$object] ?? null;
		return $m ? t($m[0]) . ' (' . $object . ')' : $object;
	}

	/** Page of the record a log row names – only when the user may open it and the record still exists ('' otherwise). */
	public static function logRecordUrl(string $object, string $id): string
	{
		static $seen = [];
		$m = self::LOG_RECORDS[$object] ?? null;
		if (!$m || $id === '' || !NuAcl::can($m[3])) return '';
		$key = $object . "\0" . $id;
		if (!isset($seen[$key])) {
			try { $seen[$key] = (bool)nu_db()->value('SELECT COUNT(*) FROM ' . nu_db()->ident($object) . ' WHERE ' . nu_db()->ident($object . '_id') . ' = ?', [$id]); }
			catch (Throwable) { $seen[$key] = false; }
		}
		return $seen[$key] ? nu_url($m[1], [$m[2] => $id]) : '';
	}

	// ------------------------------------------------------------------ audit trail (0.8.350, core/nuaudit.php)

	public static function auditBadge(string $a): string
	{
		$cls = match ($a) {
			'insert', 'clone' => 'bg-success-subtle text-success-emphasis',
			'delete' => 'bg-danger-subtle text-danger-emphasis',
			'restore' => 'bg-info-subtle text-info-emphasis',
			default => 'bg-secondary-subtle text-secondary-emphasis',
		};
		return self::badge(self::auditAction($a), $cls);
	}

	public static function auditAction(string $a): string
	{
		$l = t('aud_act_' . $a);
		return $l === 'aud_act_' . $a ? $a : $l;
	}

	/** An audit value: null as ∅, long ones shortened (the whole value in the tooltip). */
	public static function auditValue(?string $v, bool $old = false): string
	{
		if ($v === null) return '<span class="text-body-tertiary">∅</span>';
		$short = mb_strimwidth($v, 0, 160, '…');
		return '<span class="nuAudVal' . ($old ? ' nuAudOld' : '') . '"' . ($short !== $v ? ' title="' . h(mb_strimwidth($v, 0, 1000, '…')) . '"' : '') . '>' . ($v === '' ? '<span class="text-body-tertiary">""</span>' : h($short)) . '</span>';
	}

	private static function audit(): array
	{
		$T = NuAudit::TABLE;
		$cols = [
			'time' => ['label' => t('log_time'), 'select' => ['changed_at' => 'changed_at'], 'sort' => 'changed_at', 'class' => 'text-nowrap small', 'locked' => true,
				'text' => static fn($r) => date('d.m.Y H:i:s', strtotime((string)$r['changed_at']))],
			'user' => self::col('login', 'login', t('user'), 'small'),
			'action' => ['label' => t('aud_action'), 'select' => ['action' => 'action'], 'sort' => 'action', 'text' => static fn($r) => self::auditAction((string)$r['action']),
				'html' => static fn($r) => self::auditBadge((string)$r['action'])],
			'table' => self::col('table_name', 'table_name', t('aud_table'), 'small text-nowrap'),
			'record' => ['label' => t('aud_record'), 'select' => ['record_id' => 'record_id', 'parent_table' => 'parent_table', 'parent_id' => 'parent_id', 'form_id' => 'form_id'],
				'sort' => 'record_id', 'class' => 'small text-break',
				'text' => static fn($r) => $r['record_id'] . ((string)$r['parent_id'] !== '' ? ' (' . $r['parent_table'] . ' #' . $r['parent_id'] . ')' : ''),
				'html' => static fn($r) => h((string)$r['record_id']) . ((string)$r['parent_id'] !== '' ? '<div class="text-body-secondary">' . h(t('aud_in', (string)$r['parent_table'], (string)$r['parent_id'])) . '</div>' : '')],
			'form' => self::col('form_code', 'form_code', t('aud_form'), 'small'),
			'column' => self::col('column_name', 'column_name', t('aud_column'), 'small fw-semibold text-break'),
			'old' => ['label' => t('aud_old'), 'select' => ['old_value' => 'old_value'], 'sort' => null, 'class' => 'small text-break',
				'text' => static fn($r) => (string)($r['old_value'] ?? ''), 'html' => static fn($r) => self::auditValue($r['old_value'], true)],
			'new' => ['label' => t('aud_new'), 'select' => ['new_value' => 'new_value'], 'sort' => null, 'class' => 'small text-break',
				'text' => static fn($r) => (string)($r['new_value'] ?? ''), 'html' => static fn($r) => self::auditValue($r['new_value'])],
			'source' => ['label' => t('aud_source'), 'select' => ['source' => 'source'], 'sort' => 'source', 'class' => 'small',
				'text' => static fn($r) => ($l = t('aud_src_' . $r['source'])) === 'aud_src_' . $r['source'] ? (string)$r['source'] : $l],
			'ip' => ['label' => 'IP', 'select' => ['ip' => 'ip'], 'sort' => 'ip', 'class' => 'small text-body-secondary', 'text' => static fn($r) => NuDemo::ip($r['ip'])],
			'change' => self::col('change_id', 'change_id', t('aud_change'), 'small text-body-secondary'),
			'id' => self::idCol('zzzzsys_nunext_audit_id'),
		];
		$distinct = static function (string $col) use ($T): array {
			$o = [];
			try { foreach (nu_db()->all("SELECT DISTINCT $col AS v FROM $T WHERE $col <> '' ORDER BY $col") as $r) $o[(string)$r['v']] = (string)$r['v']; } catch (Throwable) {}
			return $o;
		};
		$filters = [
			'action' => ['label' => t('aud_action'), 'type' => 'select',
				'options' => static fn(): array => array_combine(NuAudit::ACTIONS, array_map([self::class, 'auditAction'], NuAudit::ACTIONS)),
				'where' => static fn(string $v) => ['action = ?', [$v]]],
			'table' => ['label' => t('aud_table'), 'type' => 'select', 'tom' => true, 'options' => static fn(): array => $distinct('table_name'),
				'where' => static fn(string $v) => ['(table_name = ? OR parent_table = ?)', [$v, $v]]],
			'form' => ['label' => t('aud_form'), 'type' => 'select', 'tom' => true, 'options' => static fn(): array => $distinct('form_code'),
				'where' => static fn(string $v) => ['form_code = ?', [$v]]],
			// user as a searchable select of the logins in the audit trail (0.8.690, as the log; was a text field)
			'login' => ['label' => t('user'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($T): array { $o = []; foreach (nu_db()->all("SELECT DISTINCT login FROM $T WHERE login IS NOT NULL AND login <> '' ORDER BY login") as $r) $o[(string)$r['login']] = (string)$r['login']; return $o; },
				'where' => static fn(string $v) => ['login = ?', [$v]]],
			'record' => ['label' => t('aud_record'), 'type' => 'text', 'expr' => 'record_id'],
			'column' => ['label' => t('aud_column'), 'type' => 'text', 'expr' => 'column_name'],
			'from' => ['label' => t('log_from'), 'type' => 'datefrom', 'expr' => 'changed_at'],
			'to' => ['label' => t('log_to'), 'type' => 'dateto', 'expr' => 'changed_at'],
			'when' => ['label' => t('lv_period'), 'type' => 'date', 'expr' => 'changed_at', 'presets' => ['d7', 'd30', 'd90']],
		];
		return [
			'key' => 'audit', 'title' => t('aud_title'), 'route' => 'audit', 'form' => NuAcl::FORM_AUDIT, 'from' => " FROM $T", 'where' => [], 'params' => [],
			'id' => 'zzzzsys_nunext_audit_id', 'always' => ['record'], 'table_class' => 'table-sm',
			'link' => static fn($r) => (string)$r['form_id'] !== '' ? nu_url('audithistory', ['f' => $r['form_id'], 'r' => (string)$r['parent_id'] !== '' ? $r['parent_id'] : $r['record_id']]) : '',
			'search' => ['table_name', 'record_id', 'parent_id', 'column_name', 'old_value', 'new_value', 'form_code'], 'search_ph' => t('aud_search_ph'),
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['time', 'user', 'action', 'table', 'record', 'column', 'old', 'new'], 'card' => ['title' => 'column', 'badge' => 'action', 'lines' => ['time', 'user', 'table', 'record', 'old', 'new']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['action', 'table', 'login', 'from', 'to'], 'values' => []]],
				'compact' => ['columns' => ['time', 'user', 'action', 'table', 'record'], 'card' => ['title' => 'table', 'badge' => 'action', 'lines' => ['time', 'user', 'record']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['action', 'table', 'when'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'column', 'badge' => 'action', 'lines' => ['time', 'user', 'table', 'record', 'old', 'new']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}

	// ------------------------------------------------------------------ e-mail log (tab of the e-mail page)

	public static function mailState(?string $st): string
	{
		$st = (string)$st;
		$l = t('mail_state_' . $st);
		return self::badge($l === 'mail_state_' . $st ? $st : $l, $st === 'sent' ? 'bg-success-subtle text-success-emphasis' : ($st === 'error' ? 'bg-danger-subtle text-danger-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'));
	}

	public static function mailTime(mixed $v): string
	{
		if ($v === null || $v === '') return '';
		$ts = strtotime((string)$v);
		return $ts ? date('d.m.Y H:i:s', $ts) : (string)$v;
	}

	// ------------------------------------------------------------------ custom user fields (0.8.546; before: a table of its own in pages/nuuserfields.php)

	private static function userFields(): array
	{
		NuSchema::ensure();
		$T = NuUserFields::TABLE;
		$canChange = NuAdmin::can('userfields', 'change') && empty(NuAuth::user()['demo']);
		$yes = static fn(string $a) => static fn($r) => (string)$r[$a] === '1' ? t('yes') : '';
		$flag = static fn(string $a) => static fn($r) => (string)$r[$a] === '1' ? '<span class="text-success">✔</span>' : '';
		$move = static function ($r): string {
			$out = '';
			foreach (['up' => ['bi-arrow-up', 'home_move_up'], 'down' => ['bi-arrow-down', 'home_move_down']] as $dir => [$ic, $lbl]) {
				$out .= '<form method="post" action="' . h(nu_url('userfields')) . '" class="d-inline">' . nu_csrf_field() . '<input type="hidden" name="action" value="move">'
					. '<input type="hidden" name="id" value="' . h((string)$r['__id']) . '"><input type="hidden" name="dir" value="' . $dir . '">'
					. '<button class="btn btn-sm btn-outline-secondary nuRowBtn" type="submit" title="' . h(t($lbl)) . '" aria-label="' . h(t($lbl)) . '"><i class="bi ' . $ic . '"></i></button></form> ';
			}
			return $out;
		};
		$cols = [
			'order' => ['label' => '', 'select' => ['uf_sort' => 'sort'], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'noexport' => true, 'if' => static fn() => $canChange,
				'text' => static fn($r) => '', 'html' => $move],
			'label' => ['label' => t('uf_label'), 'main' => true, 'locked' => true, 'select' => ['uf_label' => 'label'], 'sort' => 'label',
				'text' => static fn($r) => NuTranslate::text((string)$r['uf_label'])],
			'code' => ['label' => t('uf_code'), 'select' => ['uf_code' => 'code'], 'sort' => 'code', 'class' => 'small font-monospace',
				'text' => static fn($r) => '#USER_' . strtoupper((string)$r['uf_code']) . '#'],
			'type' => ['label' => t('uf_type'), 'select' => ['uf_type' => 'type'], 'sort' => 'type', 'class' => 'small', 'text' => static fn($r) => t('uf_type_' . $r['uf_type'])],
			'edit_by' => ['label' => t('uf_edit_by'), 'select' => ['uf_edit_by' => 'edit_by'], 'sort' => 'edit_by', 'class' => 'small', 'text' => static fn($r) => t('uf_edit_' . $r['uf_edit_by'])],
			'required' => ['label' => t('uf_required'), 'select' => ['uf_required' => 'required'], 'sort' => 'required', 'align' => 'center', 'text' => $yes('uf_required'), 'html' => $flag('uf_required')],
			'unique' => ['label' => t('uf_unique'), 'select' => ['uf_unique' => 'is_unique'], 'sort' => 'is_unique', 'align' => 'center', 'text' => $yes('uf_unique'), 'html' => $flag('uf_unique')],
			'in_list' => ['label' => t('uf_in_list'), 'select' => ['uf_in_list' => 'in_list'], 'sort' => 'in_list', 'align' => 'center', 'text' => $yes('uf_in_list'), 'html' => $flag('uf_in_list')],
			'default' => self::col('uf_default', 'default_value', t('uf_default'), 'small text-break'),
			'hint' => self::col('uf_hint', 'hint', t('uf_hint'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['uf_hint'], 0, 90, '…'))],
			'sort' => self::col('uf_sort2', 'sort', t('lv_sort_order'), 'small') + ['align' => 'end'],
			'updated' => ['label' => t('fi_changed'), 'select' => ['uf_updated' => 'updated_at'], 'sort' => 'updated_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => $r['uf_updated'] ? date('d.m.Y H:i', strtotime((string)$r['uf_updated'])) : ''],
			'id' => self::idCol('zzzzsys_nunext_user_field_id'),
		];
		$filters = [
			'type' => ['label' => t('uf_type'), 'type' => 'select', 'options' => static fn() => array_combine(array_keys(NuUserFields::TYPES), array_map(static fn($k) => t('uf_type_' . $k), array_keys(NuUserFields::TYPES))),
				'where' => static fn(string $v) => isset(NuUserFields::TYPES[$v]) ? ['type = ?', [$v]] : null],
			'edit_by' => ['label' => t('uf_edit_by'), 'type' => 'select', 'options' => static fn() => array_combine(NuUserFields::EDIT_BY, array_map(static fn($k) => t('uf_edit_' . $k), NuUserFields::EDIT_BY)),
				'where' => static fn(string $v) => in_array($v, NuUserFields::EDIT_BY, true) ? ['edit_by = ?', [$v]] : null],
		];
		$base = ['columns' => ['order', 'label', 'code', 'type', 'edit_by', 'required', 'unique'], 'card' => ['title' => 'label', 'badge' => 'type', 'lines' => ['code', 'edit_by']],
			'sort' => ['sort', 'asc'], 'per' => 50, 'filters' => ['shown' => ['type', 'edit_by'], 'values' => []]];
		return [
			'key' => 'userfields', 'title' => t('uf_title'), 'route' => 'userfields', 'form' => NuAcl::FORM_USERFIELD,
			'from' => " FROM $T", 'where' => [], 'params' => [], 'id' => 'zzzzsys_nunext_user_field_id',
			'link' => static fn($r) => nu_url('userfields', ['id' => $r['__id']]),
			'search' => ['code', 'label', 'hint'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['label', 'code', 'type']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}

	// ------------------------------------------------------------------ REST API clients (0.8.547; before: a table of its own in pages/nuapiclients.php)

	private static function apiClients(): array
	{
		NuApiClient::ensure();
		$db = nu_db();
		$C = NuApiClient::CLIENT;
		$now = date('Y-m-d H:i:s');
		$state = static fn($r) => (string)$r['api_active'] !== '1' ? 'off' : ($r['api_expires'] && nu_is_expired($r['api_expires']) ? 'expired' : 'on');
		$scopes = static fn($r) => NuApiClient::scopesOf(['scopes' => $r['api_scopes']]);
		$dt = static fn(string $a, string $fmt = 'd.m.Y H:i') => static fn($r) => $r[$a] ? date($fmt, strtotime((string)$r[$a])) : '';
		$cols = [
			'name' => ['label' => t('api_name'), 'main' => true, 'locked' => true, 'select' => ['api_name' => 'c.name'], 'sort' => 'c.name', 'text' => static fn($r) => (string)$r['api_name']],
			'client_id' => self::col('api_client_id', 'c.client_id', 'client_id', 'small font-monospace text-break'),
			'user' => ['label' => t('api_user'), 'select' => ['api_login' => 'u.sus_login_name', 'api_access' => 'a.sal_code'], 'sort' => 'u.sus_login_name', 'class' => 'small',
				'text' => static fn($r) => trim((string)$r['api_login'] . ((string)$r['api_access'] !== '' ? ' · ' . $r['api_access'] : '')),
				'html' => static fn($r) => h((string)($r['api_login'] ?? '–')) . ((string)$r['api_access'] !== '' ? ' ' . self::badge((string)$r['api_access'], 'bg-secondary-subtle text-secondary-emphasis border') : '')],
			// scopes (0.8.547): a small button with the number; a click opens the list – no wide row of badges, also on phones
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
			'key' => 'apiclients', 'title' => t('api_title'), 'route' => 'apiclients', 'form' => NuAcl::FORM_APICLIENT,
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

	/** Webhooks (0.8.715, core/nuwebhook.php): name, forms, events, target host, active, calls of the last 24 hours. */
	private static function webhooks(): array
	{
		$W = NuWebhook::T; $C = NuWebhook::CALL;
		$since = date('Y-m-d H:i:s', time() - 86400);
		$nFail = "(SELECT COUNT(*) FROM $C x WHERE x.webhook_id = w.zzzzsys_nunext_webhook_id AND x.status = 'failed' AND x.created_at >= '$since')";
		$nPend = "(SELECT COUNT(*) FROM $C x WHERE x.webhook_id = w.zzzzsys_nunext_webhook_id AND x.status = 'pending')";
		$nSent = "(SELECT COUNT(*) FROM $C x WHERE x.webhook_id = w.zzzzsys_nunext_webhook_id AND x.status = 'sent' AND x.created_at >= '$since')";
		$forms = nu_forms();
		$formsOf = static function ($r) use ($forms): array { $j = json_decode((string)$r['wh_forms'], true); return array_map(static fn($f) => (string)($forms[$f]['code'] ?? $f), is_array($j) ? $j : []); };
		$eventsOf = static function ($r): array { $j = json_decode((string)$r['wh_events'], true); return array_map(static fn($e) => t('wh_ev_' . $e), array_intersect(NuWebhook::EVENTS, is_array($j) ? $j : [])); };
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
				'html' => static fn($r) => '<a class="text-decoration-none me-2" href="' . h($calls('sent', ['wh' => $r['__id']])) . '" title="' . h(t('wh_st_sent')) . '"><i class="bi bi-check2 text-success" aria-hidden="true"></i> ' . (int)$r['wh_nsent'] . '</a>'
					. '<a class="text-decoration-none me-2' . ((int)$r['wh_nfail'] ? ' text-danger fw-semibold' : '') . '" href="' . h($calls('failed', ['wh' => $r['__id']])) . '" title="' . h(t('wh_st_failed')) . '"><i class="bi bi-x-circle" aria-hidden="true"></i> ' . (int)$r['wh_nfail'] . '</a>'
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
			'key' => 'webhooks', 'title' => t('wh_title'), 'route' => 'webhooks', 'form' => NuAcl::FORM_WEBHOOK,
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

	/** Calls of the webhooks (0.8.715): time, webhook, event, form, record, status, attempts, last answer, next attempt. */
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
		$base = ['columns' => ['created', 'webhook', 'event', 'form', 'record', 'status', 'attempts', 'answer', 'next'], 'card' => ['title' => 'webhook', 'badge' => 'status', 'lines' => ['created', 'event', 'record', 'answer']],
			'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => ['status', 'wh', 'event', 'created'], 'values' => []]];
		return [
			'key' => 'webhookcalls', 'title' => t('wh_calls'), 'route' => 'webhooks', 'route_params' => ['tab' => 'calls'], 'form' => NuAcl::FORM_WEBHOOK,
			'from' => " FROM $C c LEFT JOIN $W w ON w.zzzzsys_nunext_webhook_id = c.webhook_id", 'where' => [], 'params' => [], 'id' => 'c.zzzzsys_nunext_webhook_call_id',
			'link' => static fn($r) => nu_url('webhooks', ['call' => $r['__id']]),
			'search' => ['w.name', 'c.record_id', 'c.last_error', 'c.form_id'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}

	/** SQL builder queries (0.8.688, was a hand-written table): code, description, tables, mode, reports using it (→ the reports list). */
	private static function sqlBuilder(): array
	{
		$db = nu_db();
		$srcOf = $db->type === 'sqlsrv' ? "'SQL:' + s.zzzzsys_select_id" : "CONCAT('SQL:', s.zzzzsys_select_id)";
		$hasRep = NuReport::available();
		$nRep = $hasRep ? '(SELECT COUNT(*) FROM zzzzsys_report rp WHERE ' . $db->sameText('rp.sre_zzzzsys_php_id', $srcOf) . ')' : '0';
		$cols = [
			'code' => ['label' => t('sqb_code'), 'main' => true, 'locked' => true, 'select' => ['sse_code' => 's.sse_code'], 'sort' => 's.sse_code',
				'text' => static fn($r) => (string)$r['sse_code'], 'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['sse_code'] !== '' ? $r['sse_code'] : '–') . '</span>'],
			'description' => self::col('sse_description', 's.sse_description', t('sqb_description'), 'small text-break'),
			'tables' => ['label' => t('sqb_tables'), 'select' => ['sse_json' => 's.sse_json'], 'sort' => null, 'class' => 'small',
				'text' => static function ($r): string { $j = json_decode((string)$r['sse_json'], true); return implode(', ', array_map(static fn($t) => (string)($t['tablename'] ?? ''), (array)($j['tables'] ?? []))); }],
			'mode' => ['label' => t('sqb_mode'), 'select' => ['sse_edit' => 's.sse_edit'], 'sort' => 's.sse_edit',
				'text' => static fn($r) => t((string)$r['sse_edit'] === '1' ? 'sqb_mode_manual' : 'sqb_mode_builder'),
				'html' => static fn($r) => self::badge(t((string)$r['sse_edit'] === '1' ? 'sqb_mode_manual' : 'sqb_mode_builder'), (string)$r['sse_edit'] === '1' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-info-subtle text-info-emphasis')],
			'reports' => ['label' => t('sqb_used'), 'select' => ['lv_nrep' => $nRep], 'sort' => $hasRep ? $nRep : null, 'align' => 'end', 'text' => static fn($r) => (string)(int)$r['lv_nrep'],
				'html' => static fn($r) => (int)$r['lv_nrep'] > 0 && NuAcl::can(NuAcl::FORM_REPORT)   // → the reports list of this query
					? '<a class="text-decoration-none" href="' . h(nu_url('reports', ['src' => 'SQL:' . $r['__id']])) . '">' . (int)$r['lv_nrep'] . '</a>' : (string)(int)$r['lv_nrep']],
			'sql' => ['label' => t('sqb_sql'), 'select' => ['sse_sql' => 's.sse_sql'], 'sort' => null, 'class' => 'small text-break nfdMono',
				'text' => static fn($r) => mb_strimwidth(preg_replace('/\s+/', ' ', (string)$r['sse_sql']) ?? '', 0, 200, '…')],
			'id' => self::idCol('s.zzzzsys_select_id'),
		];
		$filters = [
			'mode' => ['label' => t('sqb_mode'), 'type' => 'select', 'options' => static fn() => ['builder' => t('sqb_mode_builder'), 'manual' => t('sqb_mode_manual')],
				'where' => static fn(string $v) => match ($v) { 'manual' => ["s.sse_edit = '1'", []], 'builder' => ["COALESCE(s.sse_edit, '') <> '1'", []], default => null }],
			'used' => ['label' => t('sqb_flt_used'), 'type' => 'bool', 'expr' => $hasRep ? "$nRep > 0" : '1 = 0'],
		];
		$base = ['columns' => ['code', 'description', 'tables', 'mode', 'reports'], 'card' => ['title' => 'code', 'badge' => 'mode', 'lines' => ['description', 'tables', 'reports']],
			'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['mode', 'used'], 'values' => []]];
		return [
			'key' => 'sqlbuilder', 'title' => t('sqb_title'), 'route' => 'sqlbuilder', 'form' => NuAcl::FORM_SELECT,
			'from' => ' FROM ' . NuSqlSelect::TABLE . ' s', 'where' => ["COALESCE(s.sse_system, '') = ''"], 'params' => [], 'id' => 's.zzzzsys_select_id',
			'link' => static fn($r) => nu_url('sqlbuilder', ['id' => $r['__id']]),
			'search' => ['s.sse_code', 's.sse_description', 's.sse_sql'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['code', 'description']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}

	/** E-mail templates (0.8.688, was a hand-written table – shown on the E-mail page's tab Templates). */
	private static function emailTemplates(): array
	{
		$T = NuEmailTemplate::TABLE;
		$welcome = static fn(array $r): bool => ((string)$r['emt_code'] === NuWelcome::TEMPLATE && (string)$r['emt_template'] !== '1')
			|| ((string)$r['emt_code'] === NuWelcome::STD && (string)$r['emt_group'] === NuWelcome::STD_GROUP);
		$cols = [
			'code' => ['label' => t('emt_code'), 'main' => true, 'locked' => true,
				'select' => ['emt_code' => 't.emt_code', 'emt_template' => 't.emt_template', 'emt_description' => 't.emt_description', 'emt_group' => 't.emt_group'], 'sort' => 't.emt_code, t.emt_language',
				'text' => static fn($r) => (string)$r['emt_code'],
				// read-only template · used by "Send welcome e-mail" (0.8.688)
				'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['emt_code']) . '</span>'
					. ((string)$r['emt_template'] === '1' ? ' <span class="badge text-bg-light border">' . h(t('emt_template_badge')) . '</span>' : '')
					. ($welcome($r) ? ' <span class="badge bg-success-subtle text-success-emphasis" title="' . h(t('emt_welcome_badge_tip')) . '"><i class="bi bi-envelope-heart me-1" aria-hidden="true"></i>' . h(t('emt_welcome_badge')) . '</span>' : '')
					. ((string)$r['emt_description'] !== '' ? '<div class="small text-body-secondary fw-normal nuClamp2">' . h((string)$r['emt_description']) . '</div>' : '')],
			'group' => array_replace(self::badgeCol('emt_group2', 't.emt_group', t('emt_group')), ['sort' => 't.emt_group, t.emt_code']),
			'category' => self::col('emt_category', 't.emt_category', t('emt_category'), 'small'),
			'language' => self::col('emt_language', 't.emt_language', t('emt_language'), 'small'),
			// the form opens (0.8.688)
			'form' => ['label' => t('emt_form'), 'select' => ['sfo_code' => 'f.sfo_code', 'emt_form_id' => 't.emt_form_id'], 'sort' => 'f.sfo_code', 'class' => 'small',
				'text' => static fn($r) => (string)($r['sfo_code'] ?? $r['emt_form_id'] ?? ''),
				'html' => static fn($r) => (string)($r['emt_form_id'] ?? '') === '' ? '' : (NuAcl::can((string)$r['emt_form_id'])
					? '<a class="text-decoration-none" href="' . h(nu_url('form', ['f' => (string)$r['emt_form_id']])) . '">' . h((string)($r['sfo_code'] ?? $r['emt_form_id'])) . '</a>' : h((string)($r['sfo_code'] ?? $r['emt_form_id'])))],
			'subject' => self::col('emt_subject', 't.emt_subject', t('mail_subject'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['emt_subject'], 0, 80, '…'))],
			'to' => self::col('emt_to', 't.emt_to', t('mail_to'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['emt_to'], 0, 50, '…'))],
			'id' => self::idCol('t.zzzzsys_email_template_id'),
		];
		$filters = [
			'group' => ['label' => t('emt_group'), 'type' => 'distinct', 'expr' => 't.emt_group', 'none' => t('lv_empty')],
			'category' => ['label' => t('emt_category'), 'type' => 'distinct', 'expr' => 't.emt_category', 'none' => t('lv_empty')],
			'language' => ['label' => t('emt_language'), 'type' => 'distinct', 'expr' => 't.emt_language', 'none' => t('lv_empty')],
			'form' => ['label' => t('emt_form'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($T): array { $o = []; foreach (nu_db()->all("SELECT DISTINCT f.zzzzsys_form_id, f.sfo_code, f.sfo_description FROM $T t JOIN zzzzsys_form f ON f.zzzzsys_form_id = t.emt_form_id ORDER BY f.sfo_code") as $f) $o[(string)$f['zzzzsys_form_id']] = [(string)$f['sfo_code'], (string)$f['sfo_description']]; return $o; },
				'where' => static fn(string $v) => ['t.emt_form_id = ?', [$v]]],
			'template' => ['label' => t('emt_template_badge'), 'type' => 'bool', 'expr' => "t.emt_template = '1'"],
		];
		$base = ['columns' => ['code', 'group', 'category', 'language', 'form', 'subject', 'to'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['language', 'subject']],
			'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'category', 'language', 'form'], 'values' => []]];
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

	/** Admin roles (0.8.687, was a hand-written table): name, code, rights (n / all, "full access"), users (→ the users list filtered by the role). */
	private static function adminRoles(): array
	{
		$db = nu_db();
		$R = 'zzzzsys_nunext_admin_role';
		$nUsers = '(SELECT COUNT(*) FROM zzzzsys_nunext_admin_user au WHERE au.role_id = r.zzzzsys_nunext_admin_role_id)';
		$mine = (string)(NuAdmin::role()['id'] ?? '');
		$roleOf = static fn(array $r): array => NuAdmin::get((string)$r['__id']) ?? ['name' => (string)$r['adm_name'], 'rights' => []];
		$all = count(NuAdmin::RIGHTS);
		$cols = [
			'name' => ['label' => t('adm_role'), 'main' => true, 'locked' => true, 'select' => ['adm_name' => 'r.name', 'adm_code2' => 'r.code'], 'sort' => 'r.sort_order, r.name',
				'text' => static fn($r) => (string)$roleOf($r)['name'],
				'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$roleOf($r)['name']) . '</span>' . ($mine !== '' && $mine === (string)$r['__id'] ? ' <span class="badge text-bg-secondary ms-1">' . h(t('adm_yours')) . '</span>' : '')],
			'code' => ['label' => t('adm_code'), 'select' => ['adm_code' => 'r.code'], 'sort' => 'r.code', 'class' => 'small', 'text' => static fn($r) => (string)$r['adm_code'],
				'html' => static fn($r) => '<code>' . h((string)$r['adm_code']) . '</code>'],
			'description' => self::col('adm_desc', 'r.description', t('adm_description'), 'small text-body-secondary'),
			'rights' => ['label' => t('adm_rights'), 'select' => ['adm_rights' => 'r.rights'], 'sort' => null,
				'text' => static fn($r) => count($roleOf($r)['rights']) . ' / ' . $all,
				'html' => static fn($r) => h(count($roleOf($r)['rights']) . ' / ' . $all) . (NuAdmin::hasFull($roleOf($r)['rights']) ? ' <span class="badge text-bg-danger ms-1 nuAdmFull" title="' . h(t('adm_full_tip')) . '">' . h(t('adm_full')) . '</span>' : '')],
			'users' => ['label' => t('nav_users'), 'select' => ['adm_n' => $nUsers], 'sort' => $nUsers, 'align' => 'end', 'text' => static fn($r) => (string)(int)$r['adm_n'],
				'html' => static fn($r) => (int)$r['adm_n'] > 0 && NuAcl::can(NuAcl::FORM_USER)   // → the users list filtered by the role
					? '<a class="text-decoration-none" href="' . h(nu_url('users', ['role' => $r['__id'], 'state' => 'all'])) . '">' . (int)$r['adm_n'] . '</a>' : (string)(int)$r['adm_n']],
			'updated' => ['label' => t('fi_changed'), 'select' => ['adm_upd' => 'r.updated_at'], 'sort' => 'r.updated_at', 'class' => 'small text-nowrap', 'text' => static fn($r) => self::date($r['adm_upd'] ?? null, true)],
			'id' => self::idCol('r.zzzzsys_nunext_admin_role_id'),
		];
		$filters = [
			'has_users' => ['label' => t('lv_has_users'), 'type' => 'bool', 'expr' => 'EXISTS (SELECT 1 FROM zzzzsys_nunext_admin_user au WHERE au.role_id = r.zzzzsys_nunext_admin_role_id)'],
		];
		$base = ['columns' => ['name', 'code', 'rights', 'users'], 'card' => ['title' => 'name', 'badge' => '', 'lines' => ['code', 'rights', 'users']],
			'sort' => ['name', 'asc'], 'per' => 50, 'filters' => ['shown' => ['has_users'], 'values' => []]];
		return [
			'key' => 'adminroles', 'title' => t('adm_title'), 'route' => 'adminroles', 'form' => NuAcl::FORM_ADMINROLE,
			'from' => " FROM $R r", 'where' => [], 'params' => [], 'id' => 'r.zzzzsys_nunext_admin_role_id',
			'link' => static fn($r) => nu_url('adminroles', ['id' => $r['__id']]),
			'search' => ['r.name', 'r.code', 'r.description'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['name', 'users']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}

	/** The user whose e-mail address this is (one address, exactly one user, view right on users) – '' otherwise. */
	private static function userOfMail(string $to): string
	{
		static $by = null;
		$to = strtolower(trim($to));
		if ($to === '' || str_contains($to, ',') || str_contains($to, ';') || !NuAcl::can(NuAcl::FORM_USER)) return '';
		if ($by === null) {
			$by = [];
			try {
				foreach (nu_db()->all("SELECT zzzzsys_user_id, sus_email FROM zzzzsys_user WHERE sus_email IS NOT NULL AND sus_email <> ''") as $u) {
					$k = strtolower(trim((string)$u['sus_email']));
					$by[$k] = isset($by[$k]) ? '' : (string)$u['zzzzsys_user_id'];   // two users with one address: no link
				}
			} catch (Throwable) { $by = []; }
		}
		return $by[$to] ?? '';
	}

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
				$uid = self::userOfMail($to);   // the address of one user → that user (0.8.690)
				return $uid !== '' ? '<a class="nuMailUser" href="' . h(nu_url('user', ['id' => $uid])) . '" title="' . h(t('mail_open_user')) . '">' . h(mb_strimwidth($to, 0, 80, '…')) . '</a>' : h(mb_strimwidth($to, 0, 80, '…'));
			}],
			'subject' => ['label' => t('mail_subject'), 'select' => ['eml_subject' => 'eml_subject', 'eml_state' => 'eml_state', 'eml_error' => 'eml_error'], 'sort' => 'eml_subject', 'class' => 'small text-break',
				'text' => static fn($r) => (string)$r['eml_subject'],
				'html' => static fn($r) => h($r['eml_subject']) . ((string)$r['eml_state'] === 'error' && (string)$r['eml_error'] !== '' ? '<div class="text-danger">' . h(mb_strimwidth((string)$r['eml_error'], 0, 120, '…')) . '</div>' : '')],
			'tag' => self::col('eml_tag', 'eml_tag', t('mail_tag'), 'small text-body-secondary'),
			'error' => self::col('eml_error', 'eml_error', t('mail_error'), 'small text-danger text-break', false),
			'from' => self::col('eml_from', 'eml_from', t('lv_mail_from'), 'small text-break'),
			'delete' => ['label' => '', 'select' => [], 'sort' => null, 'align' => 'end', 'if' => static fn() => $canDel, 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static function ($r): string {
					$back = http_build_query(array_filter(array_intersect_key($_GET, array_flip(['state', 'q', 'from', 'to', 'pg', 'view', 'tag', 'when'])), static fn($v) => is_string($v) && $v !== ''));
					return '<form method="post" class="d-inline" data-confirm="' . h(t('mail_entry_delete_confirm')) . '" data-confirm-ok="' . h(t('delete')) . '" data-confirm-class="btn btn-danger">'
						. nu_csrf_field() . '<input type="hidden" name="action" value="delete_entry"><input type="hidden" name="id" value="' . h($r['__id']) . '"><input type="hidden" name="back" value="' . h($back) . '">'
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
			'key' => 'emaillog', 'title' => t('mail_tab_log'), 'route' => 'email', 'route_params' => ['tab' => 'log'], 'form' => NuAcl::FORM_EMAILLOG,
			'from' => ' FROM ' . NuMailer::LOG_TABLE, 'where' => [], 'params' => [], 'id' => 'zzzzsys_email_log_id', 'table_class' => 'table-sm',
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
	// ------------------------------------------------------------------ notification centre → Manage (0.8.690, was a hand-written table)

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
		$dt = static fn(?string $v) => (string)$v !== '' ? date('d.m.Y H:i', (int)strtotime((string)$v)) : '';
		$stCls = ['draft' => 'bg-secondary-subtle text-secondary-emphasis', 'scheduled' => 'bg-info-subtle text-info-emphasis', 'published' => 'bg-success-subtle text-success-emphasis',
			'expired' => 'bg-light text-body-secondary border', 'withdrawn' => 'bg-warning-subtle text-warning-emphasis'];
		$base = ['ntf_status' => 'n.status', 'ntf_mode' => 'n.mode', 'ntf_aud' => 'n.audience', 'ntf_pub' => 'n.publish_at', 'ntf_exp' => 'n.expires_at', 'ntf_by_id' => 'n.created_by_id'];
		$cols = [
			'level' => ['label' => t('ntf_f_level'), 'select' => ['ntf_level' => 'n.level'], 'sort' => 'n.level', 'align' => 'center', 'text' => static fn($r) => t('ntf_lv_' . $r['ntf_level']),
				'html' => static fn($r) => '<i class="bi ' . h(NuNotify::LEVELS[(string)$r['ntf_level']] ?? 'bi-info-circle') . ' nuBellIcon nuBellLv-' . h((string)$r['ntf_level']) . '" role="img" aria-label="' . h(t('ntf_lv_' . $r['ntf_level'])) . '" title="' . h(t('ntf_lv_' . $r['ntf_level'])) . '"></i>'],
			'title' => ['label' => t('ntf_f_title'), 'main' => true, 'locked' => true, 'select' => ['ntf_title' => 'n.title', 'ntf_pin' => 'n.pinned', 'ntf_conf' => 'n.must_confirm', 'ntf_mail' => 'n.email'] + $base,
				'sort' => 'n.title', 'text' => static fn($r) => (string)$r['ntf_title'],
				'html' => static fn($r) => '<span class="fw-semibold">' . h((string)$r['ntf_title']) . '</span>'
					. ((string)$r['ntf_pin'] === '1' ? ' <i class="bi bi-pin-angle-fill text-body-secondary ms-1" role="img" aria-label="' . h(t('ntf_pinned')) . '" title="' . h(t('ntf_pinned')) . '"></i>' : '')
					. ((string)$r['ntf_conf'] === '1' ? ' <span class="badge bg-warning-subtle text-warning-emphasis ms-1 nuNtfConfirm"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>' . h(t('ntf_f_must_confirm')) . '</span>' : '')
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
					$h = '<div class="dropdown"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" data-bs-popper-config="{&quot;strategy&quot;:&quot;fixed&quot;}" aria-expanded="false" aria-label="' . h(t('actions')) . '"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end">'
						. '<li><a class="dropdown-item" href="' . h(nu_url('notifications', ['id' => $nid])) . '"><i class="bi bi-pencil me-2"></i>' . h(t('edit')) . '</a></li>'
						. '<li><a class="dropdown-item" href="' . h(nu_url('notifications', ['id' => $nid, 'receipts' => '1'])) . '"><i class="bi bi-people me-2"></i>' . h(t('ntf_receipts')) . '</a></li>';
					foreach ([['copy', 'bi-copy', 'ntf_copy', ''], $n['status'] === 'published' ? ['withdraw', 'bi-eye-slash', 'ntf_withdraw', ''] : ['publish', 'bi-send', 'ntf_publish', ''], ['delete', 'bi-trash', 'delete', t('ntf_delete_confirm')]] as [$act, $ic, $lb, $conf]) {
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
			'key' => 'notifications', 'title' => t('ntf_manage'), 'route' => 'notifications', 'route_params' => ['tab' => 'manage'], 'form' => NuAcl::FORM_NOTIFY,
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

	// ------------------------------------------------------------------ dashboards (0.8.690, was a hand-written table)

	private static function dashboards(): array
	{
		$D = NuDashboard::TD;
		$W = NuDashboard::TW;
		$nW = "(SELECT COUNT(*) FROM $W w WHERE w.dashboard_id = d.zzzzsys_nunext_dashboard_id)";
		$accName = static function (): array {
			static $m = null;
			if ($m === null) { $m = []; try { foreach (nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access') as $a) $m[(string)$a['zzzzsys_access_id']] = (string)$a['sal_code']; } catch (Throwable) { /* none */ } }
			return $m;
		};
		$accText = static function (array $r) use ($accName): string {
			$a = NuDashboard::access(['access' => $r['dsh_access']]);
			$n = $accName();
			return $a ? implode(', ', array_map(static fn($x) => $n[$x] ?? $x, $a)) : t('dash_access_all');
		};
		$cols = [
			'code' => ['label' => t('dash_code'), 'main' => true, 'locked' => true, 'select' => ['dsh_code' => 'd.code'], 'sort' => 'd.code', 'text' => static fn($r) => (string)$r['dsh_code'],
				'html' => static fn($r) => '<span class="fw-semibold font-monospace">' . h((string)$r['dsh_code']) . '</span>'],
			'title' => self::col('dsh_title', 'd.title', t('dash_title_col')),
			'description' => self::col('dsh_desc', 'd.description', t('dash_description'), 'small text-body-secondary'),
			'where' => ['label' => t('dash_where'), 'select' => ['dsh_home' => 'd.on_home', 'dsh_access' => 'd.access'], 'sort' => 'd.on_home', 'class' => 'small',
				'text' => static fn($r) => ((string)$r['dsh_home'] === '1' ? t('dash_home_badge') . ' · ' : '') . $accText($r),
				'html' => static fn($r) => ((string)$r['dsh_home'] === '1' ? '<span class="badge text-bg-primary me-1"><i class="bi bi-house me-1" aria-hidden="true"></i>' . h(t('dash_home_badge')) . '</span>' : '')
					. '<span class="text-body-secondary">' . h($accText($r)) . '</span>'],
			'widgets' => ['label' => t('dash_widgets'), 'select' => ['dsh_n' => $nW], 'sort' => $nW, 'align' => 'end', 'text' => static fn($r) => (string)(int)$r['dsh_n'],
				'html' => static fn($r) => '<a class="text-decoration-none" href="' . h(nu_url('dashboards', ['id' => $r['__id']]) . '#dashWidgets') . '">' . (int)$r['dsh_n'] . '</a>'],
			'updated' => ['label' => t('fi_changed'), 'select' => ['dsh_upd' => 'd.updated_at', 'dsh_by' => 'd.updated_by'], 'sort' => 'd.updated_at', 'class' => 'small text-nowrap',
				'text' => static fn($r) => self::date($r['dsh_upd'] ?? null, true) . ((string)($r['dsh_by'] ?? '') !== '' ? ' · ' . $r['dsh_by'] : '')],
			'id' => self::idCol('d.zzzzsys_nunext_dashboard_id'),
		];
		$filters = [
			'home' => ['label' => t('dash_flt_home'), 'type' => 'bool', 'expr' => "d.on_home = '1'"],
			'acc' => ['label' => t('nav_access'), 'type' => 'select', 'tom' => true,
				'options' => static fn() => ['-' => t('dash_access_all')] + $accName(),
				'where' => static fn(string $v) => $v === '-' ? ["COALESCE(d.access, '') IN ('', '[]')", []] : ['d.access LIKE ?', ['%"' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '"%']]],
		];
		$base = ['columns' => ['code', 'title', 'where', 'widgets'], 'card' => ['title' => 'title', 'badge' => '', 'lines' => ['code', 'where', 'widgets']],
			'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['home', 'acc'], 'values' => []]];
		return [
			'key' => 'dashboards', 'title' => t('dash_title'), 'route' => 'dashboards', 'form' => NuAcl::FORM_DASHBOARD,
			'from' => " FROM $D d", 'where' => [], 'params' => [], 'id' => 'd.zzzzsys_nunext_dashboard_id',
			'link' => static fn($r) => nu_url('dashboards', ['id' => $r['__id']]),
			'search' => ['d.code', 'd.title', 'd.description'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => $base,
				'compact' => ['columns' => ['code', 'title', 'widgets']] + $base,
				'all' => ['columns' => array_keys($cols), 'filters' => ['shown' => array_keys($filters), 'values' => []]] + $base,
			],
		];
	}

}
