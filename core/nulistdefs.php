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
	public const LISTS = ['users', 'access', 'forms', 'procedures', 'reports', 'files', 'items', 'translations', 'permissions', 'prompts', 'log', 'audit', 'emaillog'];

	public static function get(string $list): ?array
	{
		return match ($list) {
			'users' => self::users(), 'access' => self::access(), 'forms' => self::forms(), 'procedures' => self::procedures(), 'reports' => self::reports(),
			'files' => self::files(), 'items' => self::items(), 'translations' => self::translations(), 'permissions' => self::permissions(),
			'prompts' => self::prompts(), 'log' => self::log(), 'audit' => self::audit(), 'emaillog' => self::emailLog(),
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
				'sort' => 'u.sus_last_name, u.sus_first_name, u.sus_name', 'text' => $name,
				'html' => static function (array $r) use ($name): string {
					$n = trim(($r['sus_first_name'] ?? '') . ' ' . ($r['sus_last_name'] ?? ''));
					return h($name($r)) . ($n !== '' && ($r['sus_name'] ?? '') !== '' && $r['sus_name'] !== $n ? '<div class="small text-body-secondary fw-normal">' . h($r['sus_name']) . '</div>' : '');
				}],
			'dept' => ['label' => t('user_department') . ' / ' . t('user_team'), 'select' => ['sus_department' => 'u.sus_department', 'sus_team' => 'u.sus_team'],
				'sort' => 'u.sus_department, u.sus_team', 'text' => $deptTeam],
			'department' => $plain('sus_department', t('user_department')),
			'team' => $plain('sus_team', t('user_team')),
			'position' => $plain('sus_position', t('user_position')),
			'access' => ['label' => t('access_level'), 'select' => ['sal_code' => 'a.sal_code', 'sal_description' => 'a.sal_description'], 'sort' => 'a.sal_code',
				'text' => static fn($r) => (string)($r['sal_code'] ?? t('none_assigned')),
				'html' => static fn($r) => $r['sal_code'] !== null ? self::badge((string)$r['sal_code'], 'bg-secondary-subtle text-secondary-emphasis border nuAccessBadge', (string)$r['sal_description'])
					: self::badge(t('none_assigned'), 'bg-danger-subtle text-danger-emphasis')],
			'login' => ['label' => t('user_login_name'), 'select' => ['sus_login_name' => 'u.sus_login_name', 'sus_change_password' => 'u.sus_change_password'], 'sort' => 'u.sus_login_name',
				'text' => static fn($r) => (string)$r['sus_login_name'],
				'html' => static fn($r) => h($r['sus_login_name'] !== '' ? $r['sus_login_name'] : '—')
					. ((string)$r['sus_change_password'] === '1' ? ' <i class="bi bi-key text-warning ms-1" title="' . h(t('user_must_change')) . '"></i>' : '')],
			'code' => $plain('sus_code', t('user_code'), null, 'small text-body-secondary'),
			'email' => $plain('sus_email', t('user_email'), null, 'text-break'),
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
					'sort' => ['name', 'asc'], 'filters' => ['shown' => ['access', 'pos', 'state'], 'values' => ['state' => 'active']]],
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
			'forms' => $num('n_forms', t('access_forms'), $nForms),
			'procedures' => $num('n_proc', t('proc_title'), $nProc),
			'reports' => $num('n_rep', t('rpt_title_list'), $nRep),
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
		];
		return [
			'key' => 'access', 'title' => t('nav_access'), 'route' => 'access', 'form' => NuAcl::FORM_ACCESS,
			'from' => ' FROM zzzzsys_access a', 'where' => [], 'params' => [],
			'id' => 'a.zzzzsys_access_id', 'link' => static fn($r) => nu_url('accesslevel', ['id' => $r['__id']]),
			'search' => ['a.sal_code', 'a.sal_description', 'a.sal_group'], 'search_ph' => t('access_search_ph'), 'open_min' => 0,
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['clone', 'code', 'group', 'description', 'home_form', 'users', 'forms'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'users']],
					'sort' => ['code', 'asc'], 'filters' => ['shown' => ['group'], 'values' => []]],
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
		$status = ['1' => 'builder_status_1', '0' => 'builder_status_0', '3' => 'builder_status_3'];
		$st = "COALESCE(NULLIF(f.sfo_status, ''), '1')";
		$where = ["f.zzzzsys_form_id NOT LIKE 'nu%'"];
		$params = [];
		if (!$ga && !$designer) { $where[] = 'f.zzzzsys_form_id IN (SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ?)'; $params[] = (string)$u['access_id']; }
		$trail = '';
		// form drafts (0.8.386): the open draft of each form (author, id) – for designers
		$drafts = $designer && NuFormDraft::ready();
		$dWhere = "d.status IN ('open', 'review') AND " . nu_db()->sameText('d.form_id', 'f.zzzzsys_form_id');
		$dBy = $drafts ? "(SELECT MAX(d.created_by) FROM zzzzsys_nunext_form_draft d WHERE $dWhere)" : 'NULL';
		$dId = $drafts ? "(SELECT MAX(d.zzzzsys_nunext_form_draft_id) FROM zzzzsys_nunext_form_draft d WHERE $dWhere)" : 'NULL';
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
			'table' => self::col('sfo_table', 'f.sfo_table', t('builder_table'), 'small'),
			'group' => self::col('sfo_group', 'f.sfo_group', t('builder_group'), 'small') + ['sort' => 'f.sfo_group, f.sfo_description'],
			'layout' => ['label' => t('form_layout'), 'select' => ['sfo_json' => 'f.sfo_json'], 'sort' => null, 'class' => 'small',
				'text' => static fn($r) => t('form_layout_' . (NuFormMeta::nunextJson($r['sfo_json'])['layout'] ?? 'absolute'))],
			'status' => ['label' => t('builder_status'), 'select' => ['lv_status' => $st], 'sort' => $st,
				'text' => static fn($r) => t($status[(string)$r['lv_status']] ?? 'builder_status_1'),
				'html' => static fn($r) => self::badge(t($status[(string)$r['lv_status']] ?? 'builder_status_1'), match ((string)$r['lv_status']) {
					'0' => 'bg-warning-subtle text-warning-emphasis', '3' => 'bg-secondary-subtle text-secondary-emphasis', default => 'bg-success-subtle text-success-emphasis' })],
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
				'default' => ['columns' => ['actions', 'description', 'code', 'type', 'table', 'group', 'layout', 'status', 'draft'], 'card' => ['title' => 'description', 'badge' => 'type', 'lines' => ['code', 'table', 'group']],
					'sort' => ['group', 'asc'], 'per' => 500, 'filters' => ['shown' => ['type', 'group', 'status'], 'values' => ['status' => 'na']]],
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
			'code' => self::col('sph_code', 'p.sph_code', t('proc_code')) + ['main' => true, 'locked' => true, 'select' => ['sph_code' => 'p.sph_code', 'sph_template' => 'p.sph_template'],
				'html' => static fn($r) => h($r['sph_code']) . ((string)$r['sph_template'] === '1' ? ' <span class="badge text-bg-light border ms-1 fw-normal">' . h(t('proc_template')) . '</span>' : '')],
			'group' => self::badgeCol('sph_group', 'p.sph_group', t('proc_group')) + ['sort' => 'p.sph_group, p.sph_code'],
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
				'html' => static fn($r) => (string)$r['sph_global'] === '1' ? self::badge(t('proc_everyone'), 'bg-info-subtle text-info-emphasis') : (string)(int)$r['n_access']],
			'id' => self::idCol('p.zzzzsys_php_id'),
		];
		$filters = [
			'group' => ['label' => t('proc_group'), 'type' => 'distinct', 'expr' => 'p.sph_group', 'none' => t('lv_empty')],
			'status' => ['label' => t('proc_status'), 'type' => 'select', 'options' => static fn() => array_map('t', NuProcedure::STATUS),
				'where' => static fn(string $v) => ["COALESCE(NULLIF(p.sph_status, ''), '1') = ?", [$v]]],
			'category' => ['label' => t('proc_category'), 'type' => 'distinct', 'expr' => 'p.sph_category', 'none' => t('lv_empty')],
			'template' => ['label' => t('proc_template'), 'type' => 'bool', 'expr' => "p.sph_template = '1'"],
			'global' => ['label' => t('proc_everyone'), 'type' => 'bool', 'expr' => "p.sph_global = '1'"],
		];
		return [
			'key' => 'procedures', 'title' => t('proc_title'), 'route' => 'procedures', 'form' => NuAcl::FORM_PROCEDURE,
			'from' => ' FROM ' . NuProcedure::TABLE . ' p LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = p.sph_zzzzsys_form_id', 'where' => [NuProcedure::listCondition('p')], 'params' => [],
			'id' => 'p.zzzzsys_php_id', 'link' => static fn($r) => nu_url('procedures', ['id' => $r['__id']]),
			'search' => ['p.sph_code', 'p.sph_description', 'p.sph_group', 'p.sph_category', 'f.sfo_code'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['code', 'group', 'category', 'status', 'description', 'run', 'launch', 'access'], 'card' => ['title' => 'code', 'badge' => 'status', 'lines' => ['description', 'group']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'status'], 'values' => []]],
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
		$cols = [
			'actions' => ['label' => '', 'select' => ['sre_code' => 'r.sre_code'], 'sort' => null, 'class' => 'text-nowrap nuRowActions', 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => '<a class="btn btn-sm btn-outline-secondary" href="' . h(nu_url('reportdesigner', ['id' => $r['__id']])) . '" title="' . h(t('rpt_designer')) . '" aria-label="' . h(t('rpt_designer')) . '"><i class="bi bi-vector-pen"></i></a>'
					. ' <a class="btn btn-sm btn-outline-primary" href="' . h(nu_url('report', ['c' => (string)$r['sre_code']])) . '" target="_blank" rel="noopener" title="' . h(t('rpt_run')) . '" aria-label="' . h(t('rpt_run')) . '"><i class="bi bi-file-earmark-pdf"></i></a>'],
			'code' => self::col('sre_code', 'r.sre_code', t('rpt_code')) + ['main' => true, 'locked' => true],
			'description' => self::col('sre_description', 'r.sre_description', t('rpt_description'), 'small text-break'),
			'group' => self::badgeCol('sre_group', 'r.sre_group', t('rpt_group')) + ['sort' => 'r.sre_group, r.sre_code'],
			'source' => ['label' => t('rpt_source'), 'select' => ['sre_src' => 'r.sre_zzzzsys_php_id'], 'sort' => 'r.sre_zzzzsys_php_id', 'class' => 'small',
				'text' => static fn($r) => NuReport::sourceLabel((string)$r['sre_src']),
				'html' => static function ($r): string { [$kind] = explode(':', (string)$r['sre_src'] . ':');
					return self::badge($kind, 'bg-info-subtle text-info-emphasis me-1') . h(substr((string)$r['sre_src'], strlen($kind) + 1)); }],
			'launch' => self::col('sfo_code', 'f.sfo_code', t('rpt_launch'), 'small'),
			'access' => self::col('n_access', $nAccess, t('rpt_access'), 'small') + ['align' => 'end'],
			'id' => self::idCol('r.zzzzsys_report_id'),
		];
		$filters = [
			'group' => ['label' => t('rpt_group'), 'type' => 'distinct', 'expr' => 'r.sre_group', 'none' => t('lv_empty')],
			'launch' => ['label' => t('rpt_launch'), 'type' => 'distinct', 'expr' => 'f.sfo_code', 'none' => t('lv_empty')],
		];
		return [
			'key' => 'reports', 'title' => t('rpt_title_list'), 'route' => 'reports', 'form' => NuAcl::FORM_REPORT,
			'from' => ' FROM ' . NuReport::TABLE . ' r LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = r.sre_zzzzsys_form_id', 'where' => [], 'params' => [],
			'id' => 'r.zzzzsys_report_id', 'link' => static fn($r) => nu_url('reports', ['id' => $r['__id']]),
			'search' => ['r.sre_code', 'r.sre_description', 'r.sre_group', 'r.sre_zzzzsys_php_id', 'f.sfo_code'], 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['actions', 'code', 'description', 'group', 'source', 'launch', 'access'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'source']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
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
		$cols = [
			'preview' => ['label' => t('lv_preview'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'nuLvThumbCell', 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => ($info($r)['image'] ?? false) ? '<img class="nuLvThumb" src="' . h(nu_url('files', ['raw' => $r['__id']])) . '" alt="" loading="lazy">' : '<i class="bi bi-file-earmark text-body-secondary fs-4"></i>'],
			'code' => self::col('sfi_code', 'sfi_code', t('file_code')) + ['main' => true, 'locked' => true],
			'description' => self::col('sfi_description', 'sfi_description', t('file_description'), 'small text-break'),
			'group' => self::badgeCol('sfi_group', 'sfi_group', t('file_group')) + ['sort' => 'sfi_group, sfi_code'],
			'name' => ['label' => t('file_name'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'small text-break', 'text' => static fn($r) => (string)($info($r)['name'] ?? '')],
			'type' => ['label' => t('file_type'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'small', 'text' => static fn($r) => (string)($info($r)['type'] ?? '')],
			'size' => ['label' => t('file_size'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'align' => 'end', 'class' => 'small text-nowrap',
				'text' => static fn($r) => ($i = $info($r)) ? NuFile::size((int)$i['size']) : ''],
			'system' => ['label' => t('file_system'), 'select' => ['sfi_system' => 'sfi_system'], 'sort' => null, 'align' => 'center',
				'text' => static fn($r) => $isSystem($r) ? t('yes') : '', 'html' => static fn($r) => $isSystem($r) ? self::badge(t('file_system'), 'bg-info-subtle text-info-emphasis') : ''],
			'id' => self::idCol('zzzzsys_file_id'),
		];
		return [
			'key' => 'files', 'title' => t('file_title'), 'route' => 'files', 'form' => NuAcl::FORM_FILE, 'from' => ' FROM ' . NuFile::TABLE,
			'where' => $denied && !$ga ? ["zzzzsys_file_id NOT LIKE 'nu%'"] : [], 'params' => [],
			'id' => 'zzzzsys_file_id', 'link' => static fn($r) => nu_url('files', ['id' => $r['__id']]), 'always' => ['preview', 'code', 'description', 'group', 'size', 'system'],
			'search' => ['sfi_code', 'sfi_description', 'sfi_group'], 'columns' => $cols,
			'filters' => ['group' => ['label' => t('file_group'), 'type' => 'distinct', 'expr' => 'sfi_group', 'none' => t('lv_empty')]],
			'grid' => static function (array $r, string $href) use ($info, $isSystem): string {
				$i = $info($r);
				return '<div class="col-6 col-md-4 col-lg-3 col-xxl-2"><a class="card h-100 shadow-sm text-decoration-none nuFileCard" href="' . h($href) . '"><div class="nuFileThumbBox">'
					. (($i['image'] ?? false) ? '<img src="' . h(nu_url('files', ['raw' => $r['__id']])) . '" alt="" loading="lazy">' : '<i class="bi bi-file-earmark' . ($i ? '' : '-x') . ' display-5 text-body-secondary"></i>')
					. '</div><div class="card-body p-2"><div class="fw-semibold text-truncate" title="' . h($r['sfi_code']) . '">' . h($r['sfi_code']) . '</div>'
					. '<div class="small text-body-secondary text-truncate" title="' . h($r['sfi_description']) . '">' . h($r['sfi_description'] ?: ($i['name'] ?? '')) . '</div><div class="d-flex flex-wrap gap-1 mt-1">'
					. ((string)$r['sfi_group'] !== '' ? self::badge((string)$r['sfi_group']) : '') . ($isSystem($r) ? self::badge(t('file_system'), 'bg-info-subtle text-info-emphasis') : '')
					. ($i ? '<span class="small text-body-secondary ms-auto">' . h(NuFile::size((int)$i['size'])) . '</span>' : '') . '</div></div></a></div>';
			},
			'builtin' => [
				'default' => ['display' => 'grid', 'columns' => ['preview', 'code', 'description', 'group', 'size'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'size']],
					'sort' => ['group', 'asc'], 'per' => 48, 'filters' => ['shown' => ['group'], 'values' => []]],
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
			'form' => self::col('sfo_code', 'f.sfo_code', t('item_form_code'), 'small') + ['sort' => 'f.sfo_code, o.sob_all_id, i.itm_group, i.itm_sort_order, i.itm_value'],
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
					'sort' => ['form', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group', 'active'], 'values' => ['active' => '1']]],
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
			'language' => self::col('trl_language', 'trl_language', t('trl_language'), 'small text-nowrap') + ['sort' => 'trl_language, trl_english'],
			'english' => self::col('trl_english', 'trl_english', t('trl_english'), 'text-break') + ['main' => true, 'locked' => true],
			'translation' => self::col('trl_translation', 'trl_translation', t('trl_translation'), 'text-break'),
			'source' => ['label' => t('trl_source'), 'select' => [], 'sort' => null, 'align' => 'end', 'text' => static fn($r) => $nu($r) ? 'nuBuilder' : t('trl_src_own'),
				'html' => static fn($r) => $nu($r) ? self::badge('nuBuilder', 'bg-info-subtle text-info-emphasis') : ''],
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
			'group' => self::badgeCol('pme_group', 'i.pme_group', t('perm_group')) + ['sort' => 'i.pme_group, i.pme_ident'],
			'description' => self::col('pme_description', 'i.pme_description', t('perm_description'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['pme_description'], 0, 120, '…'))],
			'users' => self::col('n_users', $nUsers, t('perm_users')) + ['align' => 'end'],
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
				'html' => static fn($r) => h($r['object']) . ((string)$r['record_id'] !== '' ? '<div class="text-body-secondary">#' . h($r['record_id']) . '</div>' : '')],
			'details' => ['label' => t('log_details'), 'select' => ['details' => 'details'], 'sort' => null, 'class' => 'small nuLogDetails',
				'text' => static fn($r) => $plain(self::logDetails($r['details'])), 'html' => static fn($r) => self::logDetails($r['details'])],
			'ip' => ['label' => 'IP', 'select' => ['ip' => 'ip'], 'sort' => 'ip', 'class' => 'small text-body-secondary', 'text' => static fn($r) => NuDemo::ip($r['ip'])],
			'id' => self::idCol('zzzzsys_nunext_log_id'),
		];
		$filters = [
			'event' => ['label' => t('log_event'), 'type' => 'select', 'tom' => true,
				'options' => static function (): array { $o = []; foreach (nu_db()->all('SELECT DISTINCT event FROM zzzzsys_nunext_log ORDER BY event') as $r) $o[(string)$r['event']] = self::eventLabel((string)$r['event']); asort($o); return $o; },
				'where' => static fn(string $v) => ['event = ?', [$v]]],
			'login' => ['label' => t('user'), 'type' => 'text', 'expr' => 'login'],
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
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['event', 'login', 'from', 'to'], 'values' => []]],
				'compact' => ['columns' => ['time', 'user', 'event'], 'card' => ['title' => 'event', 'badge' => '', 'lines' => ['time', 'user']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['event', 'when'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'event', 'badge' => '', 'lines' => ['time', 'user', 'object', 'details']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
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
			'login' => ['label' => t('user'), 'type' => 'text', 'expr' => 'login'],
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

	private static function emailLog(): array
	{
		$canDel = NuAcl::can(NuAcl::FORM_EMAILLOG, 'delete');
		$stateText = static fn($st) => ($l = t('mail_state_' . $st)) === 'mail_state_' . $st ? (string)$st : $l;
		$cols = [
			'created' => ['label' => t('mail_created'), 'select' => ['eml_created_at' => 'eml_created_at'], 'sort' => 'eml_created_at', 'class' => 'text-nowrap small', 'locked' => true,
				'text' => static fn($r) => self::mailTime($r['eml_created_at'])],
			'state' => ['label' => t('mail_state'), 'select' => ['eml_state' => 'eml_state'], 'sort' => 'eml_state', 'text' => static fn($r) => $stateText($r['eml_state']), 'html' => static fn($r) => self::mailState($r['eml_state'])],
			'to' => self::col('eml_to', 'eml_to', t('mail_to'), 'small text-break') + ['html' => static fn($r) => h(mb_strimwidth((string)$r['eml_to'], 0, 80, '…'))],
			'subject' => ['label' => t('mail_subject'), 'select' => ['eml_subject' => 'eml_subject', 'eml_state' => 'eml_state', 'eml_error' => 'eml_error'], 'sort' => 'eml_subject', 'class' => 'small text-break',
				'text' => static fn($r) => (string)$r['eml_subject'],
				'html' => static fn($r) => h($r['eml_subject']) . ((string)$r['eml_state'] === 'error' && (string)$r['eml_error'] !== '' ? '<div class="text-danger">' . h(mb_strimwidth((string)$r['eml_error'], 0, 120, '…')) . '</div>' : '')],
			'tag' => self::col('eml_tag', 'eml_tag', 'Tag', 'small text-body-secondary'),
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
			'tag' => ['label' => 'Tag', 'type' => 'distinct', 'expr' => 'eml_tag', 'none' => t('lv_empty')],
			'when' => ['label' => t('lv_period'), 'type' => 'date', 'expr' => 'eml_created_at', 'presets' => ['d7', 'd30', 'd90']],
		];
		return [
			'key' => 'emaillog', 'title' => t('mail_tab_log'), 'route' => 'email', 'route_params' => ['tab' => 'log'], 'form' => NuAcl::FORM_EMAILLOG,
			'from' => ' FROM ' . NuMailer::LOG_TABLE, 'where' => [], 'params' => [], 'id' => 'zzzzsys_email_log_id', 'table_class' => 'table-sm',
			'link' => static fn($r) => nu_url('email', ['tab' => 'log', 'id' => $r['__id']]),
			'search' => ['eml_to', 'eml_cc', 'eml_subject', 'eml_tag', 'eml_from'], 'search_ph' => t('mail_search_ph'), 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['created', 'state', 'to', 'subject', 'tag', 'delete'], 'card' => ['title' => 'subject', 'badge' => 'state', 'lines' => ['created', 'to']],
					'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => ['state', 'from', 'to'], 'values' => []]],
				'compact' => ['columns' => ['created', 'state', 'subject'], 'card' => ['title' => 'subject', 'badge' => 'state', 'lines' => ['created']],
					'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => ['state', 'when'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'subject', 'badge' => 'state', 'lines' => ['created', 'to', 'from', 'error']],
					'sort' => ['created', 'desc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}
}
