<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Notification centre (0.8.357): messages for users – the bell in the top bar (red badge = unread), its panel, ?p=notifications
 * (own notifications; "Manage" for senders), PHP nuNextNotify(), REST API POST /v1/notifications.
 *
 * Tables: zzzzsys_nunext_notification (one row per notification: text, level, link, audience JSON, mode, times, flags, status) and
 * zzzzsys_nunext_notification_user (per notification and user: recipient = on the fixed list, read_at, hidden_at, confirmed_at).
 * Audience {"all": bool, "users": [ids], "departments": [], "teams": [], "positions": [], "access": [access level ids]} – combined with OR.
 * Mode "live": whoever matches when the bell is read (newcomers see it); "fixed": the users matching at publishing, stored as
 * recipient rows (exact read receipts). "Must confirm" is always fixed: shown as a dialog until the user clicks "Got it".
 * Senders: globeadmin, admin role right "notifications" = change, permission item NUNEXT_NOTIFY (anyone) or NUNEXT_NOTIFY_OWN (only
 * the sender's own department / team). Viewing all notifications and their receipts: globeadmin, "notifications" = view, NUNEXT_NOTIFY.
 * Demo visitors: read / hidden / confirmed only for the session.
 */
final class NuNotify
{
	public const T = 'zzzzsys_nunext_notification';
	public const U = 'zzzzsys_nunext_notification_user';
	public const LEVELS = ['info' => 'bi-info-circle', 'success' => 'bi-check-circle', 'warning' => 'bi-exclamation-triangle', 'danger' => 'bi-exclamation-octagon'];
	public const PERM_ALL = 'NUNEXT_NOTIFY';
	public const PERM_OWN = 'NUNEXT_NOTIFY_OWN';
	public const POLL = 60;                     // seconds between two checks of the bell
	private const SETTING = 'notify';
	private const AUD_KEYS = ['users', 'departments', 'teams', 'positions', 'access'];

	// ================================================================= rights

	private static function hasPerm(string $ident): bool
	{
		$u = NuAuth::user();
		if (!$u || $u['globeadmin']) return false;
		static $cache = [];
		$cache[$u['id']] ??= NuPermission::idents((string)$u['id']);
		return in_array($ident, $cache[$u['id']], true);
	}

	/** May send to anyone (globeadmin, admin role "notifications" = change, NUNEXT_NOTIFY). */
	public static function canSendAll(): bool
	{
		$u = NuAuth::user();
		if (!$u || NuImpersonate::readOnly()) return false;
		return $u['globeadmin'] || NuAdmin::can('notifications', 'change') || self::hasPerm(self::PERM_ALL);
	}

	/** May send – to anyone or (NUNEXT_NOTIFY_OWN) to the own department / team only. */
	public static function canSend(): bool
	{
		return self::canSendAll() || (!NuImpersonate::readOnly() && self::hasPerm(self::PERM_OWN) && self::ownGroups() !== []);
	}

	/** Sees all notifications of the installation and their read receipts. */
	public static function canViewAll(): bool
	{
		$u = NuAuth::user();
		return $u && ($u['globeadmin'] || NuAdmin::can('notifications') || self::hasPerm(self::PERM_ALL));
	}

	public static function canManage(): bool
	{
		return self::canViewAll() || self::canSend();
	}

	/** May change this notification: senders to anyone – all; NUNEXT_NOTIFY_OWN – their own ones. */
	public static function canEdit(array $n): bool
	{
		if (self::canSendAll()) return true;
		return self::canSend() && (string)$n['created_by_id'] === (string)(NuAuth::user()['id'] ?? '');
	}

	public static function canSee(array $n): bool
	{
		return self::canViewAll() || self::canEdit($n);
	}

	/** NUNEXT_NOTIFY_OWN: ['departments' => [own], 'teams' => [own]] (empty values left out). */
	public static function ownGroups(): array
	{
		$p = self::me();
		$out = [];
		if (($p['department'] ?? '') !== '') $out['departments'] = [$p['department']];
		if (($p['team'] ?? '') !== '') $out['teams'] = [$p['team']];
		return $out;
	}

	// ================================================================= people

	/** Active users (login, not expired): id => [id, login, name, department, team, position, access, email]. */
	public static function people(): array
	{
		static $all = null;
		if ($all !== null) return $all;
		$db = nu_db();
		$cols = array_change_key_case($db->columns('zzzzsys_user'));
		$c = static fn(string $k) => isset($cols[$k]) ? $k : "''";
		$all = [];
		foreach ($db->all('SELECT zzzzsys_user_id, sus_login_name, sus_name, ' . $c('sus_first_name') . ' AS fn, ' . $c('sus_last_name') . ' AS ln, '
			. $c('sus_department') . ' AS dep, ' . $c('sus_team') . ' AS team, ' . $c('sus_position') . ' AS pos, sus_zzzzsys_access_id, ' . $c('sus_email') . ' AS email, '
			. $c('sus_expires_on') . " AS exp FROM zzzzsys_user WHERE COALESCE(sus_login_name, '') <> ''") as $r) {
			$exp = trim((string)$r['exp']);
			if ($exp !== '' && !str_starts_with($exp, '0000') && strtotime($exp) !== false && strtotime($exp) < strtotime('today')) continue;
			$name = trim($r['fn'] . ' ' . $r['ln']) ?: (string)$r['sus_name'];
			$id = (string)$r['zzzzsys_user_id'];
			$all[$id] = ['id' => $id, 'login' => (string)$r['sus_login_name'], 'name' => $name, 'department' => trim((string)$r['dep']), 'team' => trim((string)$r['team']),
				'position' => trim((string)$r['pos']), 'access' => (string)$r['sus_zzzzsys_access_id'], 'email' => trim((string)$r['email'])];
		}
		return $all;
	}

	/** The signed-in user as a person (the globeadmin: id 'globeadmin', no groups). */
	public static function me(): array
	{
		$u = NuAuth::user();
		if (!$u) return [];
		if ($u['globeadmin']) return ['id' => 'globeadmin', 'login' => (string)$u['login'], 'name' => 'Globeadmin', 'department' => '', 'team' => '', 'position' => '', 'access' => '',
			'email' => (string)(nu_cfg('nuConfigGlobeadminEmail') ?? '')];
		return self::people()[(string)$u['id']] ?? ['id' => (string)$u['id'], 'login' => (string)$u['login'], 'name' => '', 'department' => '', 'team' => '', 'position' => '',
			'access' => (string)($u['access_id'] ?? ''), 'email' => (string)($u['email'] ?? '')];
	}

	public static function matches(array $aud, array $p): bool
	{
		if (!empty($aud['all'])) return true;
		$in = static fn(string $k, string $v) => $v !== '' && in_array(mb_strtolower($v), array_map('mb_strtolower', (array)($aud[$k] ?? [])), true);
		return in_array($p['id'], (array)($aud['users'] ?? []), true) || $in('departments', $p['department']) || $in('teams', $p['team'])
			|| $in('positions', $p['position']) || in_array($p['access'], (array)($aud['access'] ?? []), true) && $p['access'] !== '';
	}

	/** User ids the audience reaches now (the globeadmin when "all" or chosen). @return list<string> */
	public static function recipients(array $aud): array
	{
		$out = [];
		foreach (self::people() as $id => $p) if (self::matches($aud, $p)) $out[] = (string)$id;
		if (!empty($aud['all']) || in_array('globeadmin', (array)($aud['users'] ?? []), true)) $out[] = 'globeadmin';
		return $out;
	}

	/** Cleaned audience; NUNEXT_NOTIFY_OWN senders: only their own department / team. Users may be given by id or login name. */
	public static function cleanAudience(array $in, bool $own = false): array
	{
		$people = self::people();
		$logins = array_change_key_case(array_column($people, 'id', 'login'));
		$list = static fn($v) => array_values(array_unique(array_filter(array_map(static fn($x) => mb_substr(trim((string)$x), 0, 100), is_array($v) ? $v : (is_string($v) && $v !== '' ? explode(',', $v) : [])), static fn($x) => $x !== '')));
		$aud = ['all' => !empty($in['all']) && $in['all'] !== '0'];
		foreach (self::AUD_KEYS as $k) $aud[$k] = $list($in[$k] ?? ($k === 'access' ? ($in['access_levels'] ?? []) : []));
		$aud['users'] = array_values(array_unique(array_filter(array_map(static function ($x) use ($people, $logins) {
			if ($x === 'globeadmin' || isset($people[$x])) return $x;
			return $logins[mb_strtolower($x)] ?? null;
		}, $aud['users']))));
		if ($own) {
			$g = self::ownGroups();
			$aud = ['all' => false, 'users' => [], 'positions' => [], 'access' => [],
				'departments' => array_values(array_intersect($aud['departments'], $g['departments'] ?? [])), 'teams' => array_values(array_intersect($aud['teams'], $g['teams'] ?? []))];
		}
		return $aud;
	}

	public static function audienceEmpty(array $aud): bool
	{
		if (!empty($aud['all'])) return false;
		foreach (self::AUD_KEYS as $k) if (!empty($aud[$k])) return false;
		return true;
	}

	/** Short text of an audience for lists ("Everybody", "Departments: Sales · 2 users"). */
	public static function audienceText(array $aud): string
	{
		if (!empty($aud['all'])) return t('ntf_aud_all');
		$parts = [];
		$people = self::people();
		$names = static fn(array $ids) => implode(', ', array_map(static fn($i) => $i === 'globeadmin' ? 'globeadmin' : ($people[$i]['login'] ?? $i), $ids));
		if (!empty($aud['users'])) $parts[] = t('ntf_aud_users') . ': ' . $names($aud['users']);
		foreach (['departments', 'teams', 'positions'] as $k) if (!empty($aud[$k])) $parts[] = t('ntf_aud_' . $k) . ': ' . implode(', ', $aud[$k]);
		if (!empty($aud['access'])) {
			$codes = self::accessLevels();
			$parts[] = t('ntf_aud_access') . ': ' . implode(', ', array_map(static fn($i) => $codes[$i] ?? $i, $aud['access']));
		}
		return implode(' · ', $parts);
	}

	/** access level id => code */
	public static function accessLevels(): array
	{
		static $a = null;
		return $a ??= array_column(nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access ORDER BY sal_code'), 'sal_code', 'zzzzsys_access_id');
	}

	/** Choices of the audience fields (own senders: their department / team only). */
	public static function choices(bool $own): array
	{
		if ($own) {
			$g = self::ownGroups();
			return ['users' => [], 'departments' => $g['departments'] ?? [], 'teams' => $g['teams'] ?? [], 'positions' => [], 'access' => []];
		}
		$people = self::people();
		$distinct = static function (string $k) use ($people): array {
			$v = array_values(array_unique(array_filter(array_column($people, $k), static fn($x) => $x !== '')));
			natcasesort($v);
			return array_values($v);
		};
		$users = ['globeadmin' => ['globeadmin', 'Globeadmin']];
		foreach ($people as $id => $p) $users[$id] = [$p['login'], trim($p['name'] . ($p['department'] !== '' ? ' · ' . $p['department'] : '') . ($p['team'] !== '' ? ' · ' . $p['team'] : ''), ' ·')];
		uasort($users, static fn($a, $b) => strcasecmp($a[0], $b[0]));
		return ['users' => $users, 'departments' => $distinct('department'), 'teams' => $distinct('team'), 'positions' => $distinct('position'),
			'access' => array_map(static fn($c) => [(string)$c, ''], self::accessLevels())];
	}

	// ================================================================= reading (signed-in user)

	private static function now(): string
	{
		return date('Y-m-d H:i:s');
	}

	public static function ready(): bool
	{
		static $ok = null;
		return $ok ??= nu_db()->tableExists(self::T) && nu_db()->tableExists(self::U);
	}

	/** Notifications the signed-in user gets now, with their state; pinned first, then newest. */
	private static ?array $mine = null;

	public static function mine(int $max = 300): array
	{
		if (self::$mine !== null) return self::$mine;
		$p = self::me();
		if (!$p || !self::ready()) return self::$mine = [];
		$now = self::now();
		$rows = nu_db()->page('SELECT n.*, s.recipient AS s_recipient, s.read_at, s.hidden_at, s.confirmed_at FROM ' . self::T . ' n LEFT JOIN ' . self::U
			. " s ON s.notification_id = n.zzzzsys_nunext_notification_id AND s.user_id = ? WHERE n.status = 'published' AND (n.publish_at IS NULL OR n.publish_at <= ?)"
			. ' AND (n.expires_at IS NULL OR n.expires_at > ?)', [$p['id'], $now, $now], 'n.pinned DESC, n.publish_at DESC, n.created_at DESC', $max, 0);
		$demo = NuDemo::active() ? (array)($_SESSION['nu_ntf'] ?? []) : null;
		$out = [];
		foreach ($rows as $r) {
			$aud = json_decode((string)$r['audience'], true);
			$ok = $r['mode'] === 'fixed' ? (string)$r['s_recipient'] === '1' : self::matches(is_array($aud) ? $aud : [], $p);
			if (!$ok) continue;
			$id = (string)$r['zzzzsys_nunext_notification_id'];
			if ($demo !== null) foreach (['read_at', 'hidden_at', 'confirmed_at'] as $k) if (array_key_exists($k, (array)($demo[$id] ?? []))) $r[$k] = $demo[$id][$k];
			$out[$id] = $r;
		}
		return self::$mine = $out;
	}

	/** One notification for the bell / page (escaped HTML where needed). */
	public static function item(array $r): array
	{
		return ['id' => (string)$r['zzzzsys_nunext_notification_id'], 'title' => (string)$r['title'], 'body' => self::bodyHtml((string)$r['body']), 'text' => (string)$r['body'],
			'level' => (string)$r['level'], 'icon' => self::LEVELS[$r['level']] ?? 'bi-info-circle', 'link' => self::safeLink((string)$r['link']), 'pinned' => (string)$r['pinned'] === '1',
			'confirm' => (string)$r['must_confirm'] === '1', 'at' => (string)($r['publish_at'] ?: $r['created_at']), 'ago' => self::ago((string)($r['publish_at'] ?: $r['created_at'])),
			'read' => (string)$r['read_at'] !== '', 'hidden' => (string)$r['hidden_at'] !== '', 'confirmed' => (string)$r['confirmed_at'] !== '', 'by' => (string)$r['created_by']];
	}

	/** A link as save() allows it (http(s)://…, index.php…, ?…) or '' – also for rows written past save() (0.8.367: no javascript: links). */
	public static function safeLink(string $link): string
	{
		$link = trim($link);
		return $link !== '' && preg_match('~^(https?://[^\s]+|index\.php(\?[^\s]*)?|\?[^\s]+)$~i', $link) ? $link : '';
	}

	/** Text → HTML: escaped, line breaks, http(s) links clickable. */
	public static function bodyHtml(string $s): string
	{
		$h = h($s);
		$h = (string)preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)\]\'"])~i', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $h);
		return nl2br($h, false);
	}

	public static function ago(string $at): string
	{
		$s = time() - (int)strtotime($at);
		if ($s < 60) return t('ntf_ago_now');
		if ($s < 3600) return t('ntf_ago_min', intdiv($s, 60));
		if ($s < 86400) return t('ntf_ago_h', intdiv($s, 3600));
		if ($s < 7 * 86400) return t('ntf_ago_d', intdiv($s, 86400));
		return date('d.m.Y', (int)strtotime($at));
	}

	/** What the bell needs: unread count, the latest (not hidden) and the ones to confirm. */
	public static function summary(int $limit = 10): array
	{
		$all = self::mine();
		$shown = array_filter($all, static fn($r) => (string)$r['hidden_at'] === '');
		$unread = array_filter($shown, static fn($r) => (string)$r['read_at'] === '');
		$confirm = array_filter($all, static fn($r) => (string)$r['must_confirm'] === '1' && (string)$r['confirmed_at'] === '');
		return ['unread' => count($unread), 'items' => array_values(array_map([self::class, 'item'], array_slice($shown, 0, $limit))),
			'unread_ids' => array_keys($unread), 'confirm' => array_values(array_map([self::class, 'item'], array_reverse($confirm)))];
	}

	/** read / unread / hide / unhide / confirm for the signed-in user (only notifications they get). */
	public static function setState(string $id, string $action): bool
	{
		$map = ['read' => ['read_at', true], 'unread' => ['read_at', false], 'hide' => ['hidden_at', true], 'unhide' => ['hidden_at', false], 'confirm' => ['confirmed_at', true]];
		if (!isset($map[$action]) || !isset(self::mine()[$id])) return false;
		[$col, $on] = $map[$action];
		$vals = [$col => $on ? self::now() : null];
		if ($action === 'confirm' || $action === 'hide') $vals['read_at'] = self::mine()[$id]['read_at'] ?: self::now();   // confirmed / hidden = read
		self::write($id, $vals);
		return true;
	}

	public static function readAll(): int
	{
		$n = 0;
		foreach (self::mine() as $id => $r) if ((string)$r['read_at'] === '' && (string)$r['hidden_at'] === '') { self::write((string)$id, ['read_at' => self::now()]); $n++; }
		return $n;
	}

	private static function write(string $id, array $vals): void
	{
		self::$mine = null;
		if (NuDemo::active()) {                                    // demo visitors: only this session
			$_SESSION['nu_ntf'][$id] = $vals + (array)($_SESSION['nu_ntf'][$id] ?? []);
			return;
		}
		$uid = self::me()['id'];
		$db = nu_db();
		$row = $db->one('SELECT zzzzsys_nunext_notification_user_id AS k FROM ' . self::U . ' WHERE notification_id = ? AND user_id = ?', [$id, $uid]);
		if ($row) $db->update(self::U, $vals, 'zzzzsys_nunext_notification_user_id', (string)$row['k']);
		else $db->insert(self::U, ['zzzzsys_nunext_notification_user_id' => nu_id(), 'notification_id' => $id, 'user_id' => $uid, 'recipient' => '0'] + $vals);
	}

	// ================================================================= managing

	public static function load(string $id): ?array
	{
		if (!self::ready()) return null;
		$n = nu_db()->one('SELECT * FROM ' . self::T . ' WHERE zzzzsys_nunext_notification_id = ?', [$id]);
		if (!$n) return null;
		$aud = json_decode((string)$n['audience'], true);
		$n['aud'] = self::cleanAudience(is_array($aud) ? $aud : []);
		return $n;
	}

	/** draft / scheduled / published / expired / withdrawn */
	public static function state(array $n): string
	{
		if ($n['status'] !== 'published') return (string)$n['status'];
		$now = self::now();
		if ((string)$n['expires_at'] !== '' && $n['expires_at'] <= $now) return 'expired';
		if ((string)$n['publish_at'] !== '' && $n['publish_at'] > $now) return 'scheduled';
		return 'published';
	}

	/** Notifications for "Manage" (all or the own ones), newest first, with recipient / read / confirmed counts. */
	public static function managed(int $limit = 200): array
	{
		if (!self::ready()) return [];
		$db = nu_db();
		$where = self::canViewAll() ? '' : ' WHERE created_by_id = ?';
		$rows = $db->page('SELECT * FROM ' . self::T . $where, $where ? [(string)NuAuth::user()['id']] : [], 'created_at DESC', $limit, 0);
		foreach ($rows as &$r) {
			$aud = json_decode((string)$r['audience'], true);
			$r['aud'] = is_array($aud) ? $aud : [];
			$st = self::receipts($r, false);
			$r['n_total'] = $st['total']; $r['n_read'] = $st['read']; $r['n_confirmed'] = $st['confirmed'];
		}
		unset($r);
		return $rows;
	}

	/** Recipients with their state: total / read / confirmed and ($list) the rows [user, login, name, read_at, confirmed_at, hidden_at]. */
	public static function receipts(array $n, bool $list = true): array
	{
		$id = (string)$n['zzzzsys_nunext_notification_id'];
		$state = [];
		foreach (nu_db()->all('SELECT user_id, recipient, read_at, confirmed_at, hidden_at FROM ' . self::U . ' WHERE notification_id = ?', [$id]) as $s) $state[(string)$s['user_id']] = $s;
		if ($n['mode'] === 'fixed' && $n['status'] !== 'draft') $ids = array_keys(array_filter($state, static fn($s) => (string)$s['recipient'] === '1'));
		else { $aud = $n['aud'] ?? json_decode((string)$n['audience'], true); $ids = self::recipients(is_array($aud) ? $aud : []); }
		$people = self::people();
		$out = ['total' => count($ids), 'read' => 0, 'confirmed' => 0, 'rows' => []];
		foreach ($ids as $uid) {
			$s = $state[$uid] ?? [];
			if ((string)($s['read_at'] ?? '') !== '') $out['read']++;
			if ((string)($s['confirmed_at'] ?? '') !== '') $out['confirmed']++;
			if ($list) $out['rows'][] = ['user' => $uid, 'login' => $uid === 'globeadmin' ? 'globeadmin' : ($people[$uid]['login'] ?? $uid), 'name' => $people[$uid]['name'] ?? '',
				'department' => $people[$uid]['department'] ?? '', 'read_at' => (string)($s['read_at'] ?? ''), 'confirmed_at' => (string)($s['confirmed_at'] ?? ''), 'hidden_at' => (string)($s['hidden_at'] ?? '')];
		}
		if ($list) usort($out['rows'], static fn($a, $b) => [$a['read_at'] === '' ? 0 : 1, mb_strtolower($a['login'])] <=> [$b['read_at'] === '' ? 0 : 1, mb_strtolower($b['login'])]);
		return $out;
	}

	/** 'Y-m-d\TH:i' / 'Y-m-d H:i[:s]' → 'Y-m-d H:i:s' or null; false when not a date. */
	private static function dt(mixed $v): string|false|null
	{
		$v = trim((string)$v);
		if ($v === '') return null;
		$ts = preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $v) ? strtotime(str_replace('T', ' ', $v)) : false;
		return $ts === false ? false : date('Y-m-d H:i:s', $ts);
	}

	/**
	 * Validates the fields of a notification. @return array{0: array, 1: array} [row (without id / status / creator), errors field => text]
	 */
	public static function validate(array $in, bool $own): array
	{
		$e = [];
		$title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
		if ($title === '') $e['title'] = t('err_required');
		$level = isset(self::LEVELS[(string)($in['level'] ?? '')]) ? (string)$in['level'] : 'info';
		$link = trim((string)($in['link'] ?? ''));
		if ($link !== '' && !preg_match('~^(https?://[^\s]+|index\.php(\?[^\s]*)?|\?[^\s]+)$~i', $link)) $e['link'] = t('ntf_err_link');
		$aud = self::cleanAudience(is_array($in['audience'] ?? null) ? $in['audience'] : [], $own);
		if (self::audienceEmpty($aud)) $e['audience'] = t('ntf_err_audience');
		$pub = self::dt($in['publish_at'] ?? '');
		$exp = self::dt($in['expires_at'] ?? '');
		if ($pub === false) $e['publish_at'] = t('ntf_err_date');
		if ($exp === false) $e['expires_at'] = t('ntf_err_date');
		if (is_string($exp) && $exp <= max(self::now(), (string)$pub)) $e['expires_at'] = t('ntf_err_expires');
		$confirm = !empty($in['must_confirm']) && $in['must_confirm'] !== '0';
		$row = ['title' => $title, 'body' => mb_substr(trim((string)($in['body'] ?? '')), 0, 4000), 'level' => $level, 'link' => mb_substr($link, 0, 1000),
			'audience' => json_encode($aud, JSON_UNESCAPED_UNICODE), 'mode' => $confirm || ($in['mode'] ?? '') === 'fixed' ? 'fixed' : 'live',
			'publish_at' => is_string($pub) ? $pub : null, 'expires_at' => is_string($exp) ? $exp : null, 'pinned' => !empty($in['pinned']) && $in['pinned'] !== '0' ? '1' : '0',
			'must_confirm' => $confirm ? '1' : '0', 'email' => !empty($in['email']) && $in['email'] !== '0' ? '1' : '0'];
		return [$row, $e];
	}

	/**
	 * Saves a notification (new: $id ''); $publish: published at once (or at publish_at). @return array{0: string, 1: array} [id, errors]
	 * $creator: ['id', 'login'] (default the signed-in user); $own: NUNEXT_NOTIFY_OWN rules.
	 */
	public static function save(string $id, array $in, bool $publish, ?array $creator = null, ?bool $own = null): array
	{
		NuSchema::ensure();
		$own ??= !self::canSendAll();
		[$row, $e] = self::validate($in, $own);
		if ($e) return [$id, $e];
		$db = nu_db();
		$now = self::now();
		$old = $id !== '' ? self::load($id) : null;
		if ($id !== '' && !$old) return [$id, ['title' => t('err_not_found')]];
		$row['updated_at'] = $now;
		if ($old) {
			if ($publish && $old['status'] !== 'published') $row['status'] = 'published';
			$db->update(self::T, $row, 'zzzzsys_nunext_notification_id', $id);
		} else {
			$c = $creator ?? ['id' => (string)(NuAuth::user()['id'] ?? ''), 'login' => (string)(NuAuth::user()['login'] ?? '')];
			$id = nu_id();
			$db->insert(self::T, ['zzzzsys_nunext_notification_id' => $id, 'status' => $publish ? 'published' : 'draft', 'created_at' => $now,
				'created_by' => mb_substr((string)$c['login'], 0, 50), 'created_by_id' => mb_substr((string)$c['id'], 0, 25)] + $row);
		}
		$n = (array)self::load($id);
		if ($n['status'] === 'published') self::afterPublish($n, $old === null || $old['status'] !== 'published');
		NuLog::write($old ? 'notification_changed' : 'notification_created', self::T, $id, ['title' => $row['title'], 'status' => $n['status'], 'audience' => self::audienceText($n['aud'])]);
		return [$id, []];
	}

	/** Fixed list (stored once, when published) and the e-mails when due. */
	private static function afterPublish(array $n, bool $first): void
	{
		$id = (string)$n['zzzzsys_nunext_notification_id'];
		$db = nu_db();
		if ($n['mode'] === 'fixed' && $first) {
			$have = array_flip(array_column($db->all('SELECT user_id FROM ' . self::U . ' WHERE notification_id = ?', [$id]), 'user_id'));
			foreach (self::recipients($n['aud']) as $uid) {
				if (isset($have[$uid])) $db->run('UPDATE ' . self::U . " SET recipient = '1' WHERE notification_id = ? AND user_id = ?", [$id, $uid]);
				else $db->insert(self::U, ['zzzzsys_nunext_notification_user_id' => nu_id(), 'notification_id' => $id, 'user_id' => $uid, 'recipient' => '1']);
			}
		}
		if ((string)$n['email'] === '1' && (string)$n['sent_mail_at'] === '' && ((string)$n['publish_at'] === '' || $n['publish_at'] <= self::now())) self::mail($n);
	}

	public static function setStatus(string $id, string $status): bool
	{
		$n = self::load($id);
		if (!$n || !in_array($status, ['published', 'withdrawn', 'draft'], true)) return false;
		nu_db()->update(self::T, ['status' => $status, 'updated_at' => self::now()], 'zzzzsys_nunext_notification_id', $id);
		if ($status === 'published') self::afterPublish((array)self::load($id), $n['status'] !== 'published');
		NuLog::write('notification_' . $status, self::T, $id, ['title' => $n['title']]);
		return true;
	}

	public static function delete(string $id): void
	{
		$n = self::load($id);
		if (!$n) return;
		$db = nu_db();
		$db->run('DELETE FROM ' . self::U . ' WHERE notification_id = ?', [$id]);
		$db->delete(self::T, 'zzzzsys_nunext_notification_id', $id);
		NuLog::write('notification_deleted', self::T, $id, ['title' => $n['title']]);
	}

	/** E-mail to the recipients with an address (once; marked first, so two requests do not both send). @return int sent */
	public static function mail(array $n): int
	{
		$id = (string)$n['zzzzsys_nunext_notification_id'];
		$db = nu_db();
		if ($db->run('UPDATE ' . self::T . ' SET sent_mail_at = ? WHERE zzzzsys_nunext_notification_id = ? AND sent_mail_at IS NULL', [self::now(), $id])->rowCount() !== 1) return 0;
		if (!NuMail::configured() || NuDemo::active()) return 0;
		$people = self::people();
		$ids = $n['mode'] === 'fixed' ? array_column($db->all('SELECT user_id FROM ' . self::U . " WHERE notification_id = ? AND recipient = '1'", [$id]), 'user_id') : self::recipients($n['aud']);
		$sent = 0;
		$sl = self::safeLink((string)$n['link']);
		$link = $sl !== '' ? (preg_match('~^https?://~i', $sl) ? $sl : rtrim(NuSecurity::mailBaseUrl(), '/') . '/' . ltrim($sl, '/')) : '';
		$html = '<div style="font-size:14px;line-height:1.5">' . self::bodyHtml((string)$n['body']) . '</div>'
			. ($link !== '' ? '<p style="margin-top:16px"><a href="' . h($link) . '">' . h($link) . '</a></p>' : '');
		nu_time_limit(0);
		foreach (array_slice($ids, 0, 2000) as $uid) {
			$to = $uid === 'globeadmin' ? (string)(nu_cfg('nuConfigGlobeadminEmail') ?? '') : ($people[$uid]['email'] ?? '');
			if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
			if (NuMail::send($to, (string)$n['title'], NuMail::layout((string)$n['title'], $html), 'notification') === null) $sent++;
		}
		NuLog::write('notification_mailed', self::T, $id, ['sent' => $sent]);
		return $sent;
	}

	// ================================================================= housekeeping, settings

	public static function settings(): array
	{
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$d = is_string($v) ? json_decode($v, true) : null;
		} catch (Throwable) {
			$d = null;
		}
		return ['keep_days' => max(0, (int)((is_array($d) ? $d : [])['keep_days'] ?? 180))];
	}

	public static function saveSettings(int $keepDays): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => json_encode(['keep_days' => max(0, min(3650, $keepDays))]), 'updated_at' => self::now(), 'updated_by' => (string)(NuAuth::user()['login'] ?? '')];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
	}

	/** Housekeeping: e-mails of scheduled notifications that are due; notifications older than "keep" (by expiry or publishing). */
	public static function housekeeping(): int
	{
		if (!self::ready()) return 0;
		$db = nu_db();
		$now = self::now();
		foreach ($db->all('SELECT zzzzsys_nunext_notification_id AS id FROM ' . self::T . " WHERE status = 'published' AND email = '1' AND sent_mail_at IS NULL AND (publish_at IS NULL OR publish_at <= ?)", [$now]) as $r) {
			$n = self::load((string)$r['id']);
			if ($n) self::mail($n);
		}
		$days = self::settings()['keep_days'];
		if ($days <= 0) return 0;
		$cut = date('Y-m-d H:i:s', time() - $days * 86400);
		$ids = array_column($db->all('SELECT zzzzsys_nunext_notification_id AS id FROM ' . self::T . ' WHERE COALESCE(expires_at, publish_at, created_at) < ?', [$cut]), 'id');
		foreach (array_chunk($ids, 200) as $chunk) {
			$in = implode(',', array_fill(0, count($chunk), '?'));
			$db->run('DELETE FROM ' . self::U . " WHERE notification_id IN ($in)", $chunk);
			$db->run('DELETE FROM ' . self::T . " WHERE zzzzsys_nunext_notification_id IN ($in)", $chunk);
		}
		return count($ids);
	}

	/** Migration 0012: tables, and the permission items NUNEXT_NOTIFY / NUNEXT_NOTIFY_OWN when nuBuilder's permission tables exist. */
	public static function install(): void
	{
		NuSchema::create();
		if (!NuPermission::exists()) return;
		$db = nu_db();
		foreach ([self::PERM_ALL => 'nuBuilder Next: send notifications to anyone (bell)', self::PERM_OWN => 'nuBuilder Next: send notifications to the own department / team'] as $ident => $desc) {
			if ($db->value('SELECT COUNT(*) FROM ' . NuPermission::ITEM . ' WHERE pme_ident = ?', [$ident])) continue;
			$db->insert(NuPermission::ITEM, ['zzzzsys_permission_item_id' => nu_id(), 'pme_ident' => $ident, 'pme_group' => 'nuBuilder Next', 'pme_description' => $desc]);
		}
	}
}
