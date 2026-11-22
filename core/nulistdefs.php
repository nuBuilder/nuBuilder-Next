<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Column and filter catalogues of nuBuilder Next's own lists – rendered by core/nulistview.php (NuListView).
 *
 * A list: key => [
 *   'title', 'route' (the page), 'form' (nuBuilder form whose rights apply), 'from' (FROM … JOIN …), 'where' / 'params' (always applied),
 *   'id' (SQL of the record id), 'link' => fn(row): url, 'search' => [SQL columns of the quick search], 'search_extra' => [param, cols, label, short, icon] (optional columns behind a box in the search field), 'search_ph', 'open_min' (px: filters
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
	// the lists themselves: core/lists/nulist_<key>.php, one trait each (code review L3)
	use NuListUsers, NuListAccess, NuListForms, NuListProcedures, NuListReports, NuListFiles, NuListItems, NuListTranslations, NuListPermissions,
		NuListPrompts, NuListLog, NuListAudit, NuListEmailLog, NuListUserFields, NuListApiClients, NuListAdminRoles, NuListSqlBuilder, NuListEmailTemplates,
		NuListNotifications, NuListDashboards, NuListWebhooks, NuListAiProviders, NuListHelpPages, NuListWebhookCalls, NuListExports, NuListSelftest,
		NuListProjects, NuListRecycleBin;

	/** @return array<string, array> */
	/** Keys of all lists with views (users, access; the other system lists). */
	public const LISTS = ['users', 'access', 'forms', 'procedures', 'reports', 'files', 'items', 'translations', 'permissions', 'prompts', 'log', 'audit',
		'emaillog', 'userfields', 'apiclients', 'aiproviders', 'adminroles', 'sqlbuilder', 'emailtemplates', 'notifications', 'dashboards', 'helppages',
		'webhooks', 'webhookcalls', 'exports', 'selftest', 'projects', 'recyclebin'];

	public static function get(string $list): ?array
	{
		$def = self::builtIn($list);
		return $def !== null ? NuPlugin::filter('list.def', $def, $list) : null;   // plugins may change a list (event list.def)
	}

	private static function builtIn(string $list): ?array
	{
		return match ($list) {
			'users' => self::users(), 'access' => self::access(), 'forms' => self::forms(), 'procedures' => self::procedures(), 'reports' => self::reports(),
			'files' => self::files(), 'items' => self::items(), 'translations' => self::translations(), 'permissions' => self::permissions(),
			'prompts' => self::prompts(), 'log' => self::log(), 'audit' => self::audit(), 'emaillog' => self::emailLog(), 'userfields' => self::userFields(), 'apiclients' => self::apiClients(),
			'adminroles' => self::adminRoles(), 'sqlbuilder' => self::sqlBuilder(), 'emailtemplates' => self::emailTemplates(),
			'notifications' => self::notifications(), 'dashboards' => self::dashboards(), 'webhooks' => self::webhooks(), 'aiproviders' => self::aiProviders(),
				'helppages' => self::helpPages(), 'webhookcalls' => self::webhookCalls(), 'exports' => self::exports(), 'selftest' => self::selftest(),
				'projects' => self::projects(), 'recyclebin' => self::recycleBin(),
			default => null,
		};
	}

	/**
	 * Advanced search of the system lists: per list, columns whose sort expression is not plain text – type ('date' | 'number' |
	 * 'bool'), false (not searchable: the sort expression is a code / CASE the user does not see) or [type, expr] (another expression).
	 * Every other column with a label and a sort expression is searchable as text (the first part of an "a, b" sort). Columns without a
	 * sort expression (log details, audit values, secrets) are never searchable – a condition cannot probe what a list masks.
	 * CONCAT_WS: MySQL / MariaDB and SQL Server 2017+.
	 */
	private const ADV = [
		'users' => ['name' => ['text', "CONCAT_WS(' ', u.sus_first_name, u.sus_last_name, u.sus_name)"], 'dept' => ['text', "CONCAT_WS(' ', u.sus_department, u.sus_team)"],
			'expires' => 'date', 'created' => 'date', 'changed' => 'date', 'last_login' => 'date'],
		'access' => ['users' => 'number', 'forms' => 'number', 'procedures' => 'number', 'reports' => 'number', 'twofa' => 'bool'],
		'forms' => ['access' => 'number', 'status' => false, 'opened' => 'date'],
		'procedures' => ['access' => 'number', 'status' => false, 'run' => false, 'opened' => 'date'],
		'reports' => ['access' => 'number', 'source' => false, 'opened' => 'date'],
		'files' => ['used' => 'number'],
		'items' => ['form' => ['text', 'f.sfo_code'], 'sort_order' => 'number', 'active' => 'bool', 'updated' => 'date'],
		'permissions' => ['users' => 'number', 'type' => false],
		'prompts' => ['template' => false],
		'log' => ['time' => 'date'],
		'audit' => ['time' => 'date'],
		'emaillog' => ['created' => 'date', 'state' => false],
		'userfields' => ['type' => false, 'edit_by' => false, 'required' => 'bool', 'unique' => 'bool', 'in_list' => 'bool', 'sort' => 'number', 'updated' => 'date'],
		'apiclients' => ['status' => 'bool', 'last_used' => 'date', 'expires' => 'date', 'rate' => 'number', 'created' => 'date'],
		'adminroles' => ['name' => ['text', 'r.name'], 'users' => 'number', 'updated' => 'date'],
		'sqlbuilder' => ['mode' => false, 'reports' => 'number', 'opened' => 'date'],
		'notifications' => ['level' => false, 'state' => false, 'created' => 'date'],
		'dashboards' => ['where' => false, 'widgets' => 'number', 'updated' => 'date'],
		'webhooks' => ['status' => 'bool', 'calls' => 'number', 'updated' => 'date'],
		'helppages' => ['status' => 'bool', 'langs' => false, 'used' => false, 'access' => false, 'order' => 'number', 'updated' => 'date'],
		'webhookcalls' => ['created' => 'date', 'status' => false, 'attempts' => 'number', 'answer' => 'number', 'next' => 'date', 'source' => false],
		'exports' => ['format' => false, 'runs' => 'number', 'last_run' => 'date', 'updated' => 'date'],
		'selftest' => ['status' => false, 'ms' => 'number', 'pos' => 'number'],
		'recyclebin' => ['deleted' => 'date', 'expires' => 'date', 'rows' => 'number', 'actions' => false],
		'projects' => ['status' => false, 'items' => 'number', 'tasks' => 'number', 'updated' => 'date'],
	];

	/** Text cut to $max characters with … – the full text as tooltip when cut. */
	public static function cut(string $v, int $max): string
	{
		$v = trim($v);
		if (mb_strlen($v) <= $max) return h($v);
		return '<span title="' . h($v) . '">' . h(rtrim(mb_substr($v, 0, $max)) . '…') . '</span>';
	}

	/** "a, f(b, c), d" → ['a', 'f(b, c)', 'd'] (commas outside brackets and quotes). */
	public static function splitSort(string $sql): array
	{
		$out = [];
		$depth = 0;
		$q = '';
		$cur = '';
		for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
			$ch = $sql[$i];
			if ($q !== '') { if ($ch === $q) $q = ''; }
			elseif ($ch === "'" || $ch === '"') $q = $ch;
			elseif ($ch === '(') $depth++;
			elseif ($ch === ')') $depth--;
			elseif ($ch === ',' && $depth === 0) { $out[] = trim($cur); $cur = ''; continue; }
			$cur .= $ch;
		}
		if (trim($cur) !== '') $out[] = trim($cur);
		return $out;
	}

	/**
	 * The searchable columns of a list for NuAdvSearch: [key => ['index' => key, 'title', 'expr', 'type', 'width' => 1, 'mask' => null,
	 * 'format' => '', 'values' => null]], in the list's order.
	 */
	public static function advColumns(array $L): array
	{
		$map = self::ADV[$L['key']] ?? [];
		$out = [];
		foreach ($L['columns'] as $k => $c) {
			$k = (string)$k;
			$rule = $map[$k] ?? 'text';
			if ($rule === false || !empty($c['noadv']) || (string)($c['label'] ?? '') === '') continue;
			[$type, $expr] = is_array($rule) ? $rule : [$rule, null];
			if ($expr === null) {
				if (empty($c['sort'])) continue;
				$expr = self::splitSort((string)$c['sort'])[0] ?? '';
			}
			if ($expr === '') continue;
			$out[$k] = ['index' => $k, 'title' => (string)$c['label'], 'expr' => $expr, 'type' => $type, 'width' => 1, 'mask' => null, 'format' => '', 'values' => null];
		}
		return $out;
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

	/** Forms list: a warning next to the type of a page form whose files use an unsupported API version or unknown / deprecated methods. */
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
		return date(nu_dfmt($time ? 'd.m.Y H:i' : 'd.m.Y'), strtotime($d));
	}

	// ------------------------------------------------------------------ users

	// ------------------------------------------------------------------ access levels

	// ------------------------------------------------------------------ forms

	// ------------------------------------------------------------------ procedures

	// ------------------------------------------------------------------ reports

	// ------------------------------------------------------------------ files (grid by default)

	// ------------------------------------------------------------------ items

	// ------------------------------------------------------------------ translations (?view= is the tab "Missing" there: views use ?lv=)

	// ------------------------------------------------------------------ permission items

	// ------------------------------------------------------------------ prompt generator

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
				$out .= '<li><span class="fw-semibold">' . h((string)$f) . '</span>: <span class="text-body-secondary text-decoration-line-through">'
					. h(is_scalar($old) ? (string)$old : ($old === null ? '∅' : json_encode($old))) . '</span> → '
					. h(is_scalar($new) ? (string)$new : ($new === null ? '∅' : json_encode($new))) . '</li>';
			}
			unset($d['fields']);
			return $out . '</ul>' . ($d ? '<div class="small text-body-secondary">' . h((string)json_encode($d, JSON_UNESCAPED_UNICODE)) . '</div>' : '');
		}
		$parts = [];
		foreach ($d as $k => $v) $parts[] = h((string)$k) . ': ' . h(is_scalar($v) || $v === null ? (string)$v : (string)json_encode($v, JSON_UNESCAPED_UNICODE));
		return implode('<br>', $parts);
	}

	/** Tables the log knows a page for: table => [label key, route, id parameter, form whose view right is needed]. */
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

	// ------------------------------------------------------------------ audit trail (core/nuaudit.php)

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
		return '<span class="nuAudVal' . ($old ? ' nuAudOld' : '') . '"' . ($short !== $v ? ' title="' . h(mb_strimwidth($v, 0, 1000, '…')) . '"' : '') . '>'
			. ($v === '' ? '<span class="text-body-tertiary">""</span>' : h($short)) . '</span>';
	}

	// ------------------------------------------------------------------ e-mail log (tab of the e-mail page)

	public static function mailState(?string $st): string
	{
		$st = (string)$st;
		$l = t('mail_state_' . $st);
		return self::badge($l === 'mail_state_' . $st ? $st : $l,
			$st === 'sent' ? 'bg-success-subtle text-success-emphasis' : ($st === 'error' ? 'bg-danger-subtle text-danger-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'));
	}

	public static function mailTime(mixed $v): string
	{
		if ($v === null || $v === '') return '';
		$ts = strtotime((string)$v);
		return $ts ? date(nu_dfmt('d.m.Y H:i:s'), $ts) : (string)$v;
	}

	// ------------------------------------------------------------------ custom user fields (before: a table of its own in pages/nuuserfields.php)

	// ------------------------------------------------------------------ REST API clients (before: a table of its own in pages/nuapiclients.php)

	/** The user whose e-mail address this is (one address, exactly one user, view right on users) – '' otherwise. */
	private static function userOfMail(string $to): string
	{
		$to = strtolower(trim($to));
		if ($to === '' || str_contains($to, ',') || str_contains($to, ';') || !NuAcl::can(NuAcl::FORM_USER)) return '';
		$by = NuMemo::get('users:bymail', ['zzzzsys_user'], static function (): array {
			$by = [];
			try {
				foreach (nu_db()->all("SELECT zzzzsys_user_id, sus_email FROM zzzzsys_user WHERE sus_email IS NOT NULL AND sus_email <> ''") as $u) {
					$k = strtolower(trim((string)$u['sus_email']));
					$by[$k] = isset($by[$k]) ? '' : (string)$u['zzzzsys_user_id'];   // two users with one address: no link
				}
			} catch (Throwable) { $by = []; }
			return $by;
		});
		return $by[$to] ?? '';
	}

	// ------------------------------------------------------------------ notification centre → Manage (was a hand-written table)

	// ------------------------------------------------------------------ dashboards (was a hand-written table)


	// ------------------------------------------------------------------ recycle bin (core/nurecycle.php)

}
