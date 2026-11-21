<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * "Act as user": the globeadmin works as another user – with that user's access level, rights, permission items and hash variables.
 *   mode 'view': only view / print (no save, delete, clone, new, no procedures, no form posts) · mode 'full': everything the user may do.
 * The globeadmin's own login stays in the session ($_SESSION[NuSess::IMP]['orig']) and comes back with "Back to globeadmin".
 * Not allowed while acting: change password, security page (2FA, devices) – they belong to the user.
 * Test an admin role: the same, with a role of zzzzsys_nunext_admin_role applied to the chosen user for this session only
 * ($_SESSION[NuSess::IMP]['role']; NuAdmin::role() returns it and its rights count while acting) – nothing is saved. Who may: the
 * globeadmin, or a user with the rights impersonate + adminroles (change) for roles whose rights he has himself, never on another admin.
 */
final class NuImpersonate
{
	public const MODES = ['view', 'full'];

	public static function active(): ?array
	{
		$i = $_SESSION[NuSess::IMP] ?? null;
		return is_array($i) && isset($i['orig'], $i['id']) ? $i : null;
	}

	public static function readOnly(): bool
	{
		return (self::active()['mode'] ?? '') === 'view';
	}

	/** The admin role tested while acting: the session entry with 'role' / 'role_name', or null. */
	public static function testRole(): ?array
	{
		$i = self::active();
		return $i && (string)($i['role'] ?? '') !== '' ? $i : null;
	}

	/** May the signed-in user test admin roles – this one ($role) when given? */
	public static function canTest(?array $role = null): bool
	{
		$me = NuAuth::user();
		if (!$me || self::active() || !empty($me['demo'])) return false;
		if ($me['globeadmin']) return true;
		if (!NuAdmin::can('impersonate', 'change') || !NuAdmin::can('adminroles', 'change')) return false;
		return $role === null || NuAdmin::overGrant($role['rights']) === [];
	}

	/** Users a role can be tested on: [id, login, name, has (has this role)], the role's users first (the globeadmin only), at most 2000. */
	public static function testUsers(array $role): array
	{
		$me = NuAuth::user();
		$ga = !empty($me['globeadmin']);
		$own = [];
		if ($ga) foreach (NuAdmin::usersOf((string)$role['id']) as $u) $own[(string)$u['id']] = ['id' => (string)$u['id'], 'login' => (string)$u['login'], 'name' => (string)$u['name'], 'has' => true];
		$db = nu_db();
		$admins = [];
		if (!$ga && NuAdmin::ready()) foreach ($db->all('SELECT ' . NuAdmin::USER_TABLE . '_id AS id FROM ' . NuAdmin::USER_TABLE) as $r) $admins[(string)$r['id']] = true;
		$out = array_values($own);
		foreach ($db->page("SELECT zzzzsys_user_id AS id, sus_login_name AS login, sus_name AS name FROM zzzzsys_user WHERE zzzzsys_user_id <> 'globeadmin'", [], 'sus_login_name', 2000, 0) as $r) {
			$id = (string)$r['id'];
			if (isset($own[$id]) || isset($admins[$id]) || (!$ga && $id === (string)($me['id'] ?? ''))) continue;
			$out[] = ['id' => $id, 'login' => (string)$r['login'], 'name' => (string)$r['name'], 'has' => false];
		}
		return $out;
	}

	/** Who really acts (for the log): the globeadmin's login, or ''. */
	public static function by(): string
	{
		return (string)(self::active()['by'] ?? '');
	}

	/** @return string|null error key, null = started */
	public static function start(string $userId, string $mode, string $back = '', string $roleId = ''): ?string
	{
		$me = NuAuth::user();
		if (!$me || !NuAdmin::can('impersonate', 'change') || self::active()) return 'imp_err_not_allowed';
		if (!empty($me['demo'])) return 'demo_not_available';     // demo mode: never another user's rights
		$role = null;
		if ($roleId !== '') {                                       // test an admin role
			$role = NuAdmin::get($roleId);
			if (!$role) return 'record_not_found';
			if (!self::canTest($role)) return 'imp_err_not_allowed';
			if ($userId === 'globeadmin') return 'imp_err_not_allowed';
		}
		if (!in_array($mode, self::MODES, true)) return 'err_invalid';
		$row = nu_db()->one('SELECT zzzzsys_user_id, sus_login_name, sus_login_password FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]);
		if (!$row) return 'record_not_found';
		if (!$me['globeadmin'] && (NuAdmin::roleOfUser($userId) !== null || (string)$me['id'] === $userId)) return 'imp_err_not_allowed';   // admin roles: not another admin
		$orig = $_SESSION[NuSess::AUTH];
		$target = ['id' => (string)$row['zzzzsys_user_id'], 'globeadmin' => false, 'login' => (string)$row['sus_login_name'],
			'fp' => NuAuth::fingerprint((string)$row['sus_login_password']), 'via' => 'impersonated'];
		NuLog::write('impersonate_start', 'zzzzsys_user', $target['id'], ['login' => $target['login'], 'mode' => $mode] + ($role ? ['role' => (string)$role['code']] : []));
		NuAuth::startAs($target);
		$_SESSION[NuSess::IMP] = ['orig' => $orig, 'id' => $target['id'], 'login' => $target['login'], 'mode' => $mode, 'by' => (string)$me['login'],
			'since' => time(), 'back' => self::safeBack($back)] + ($role ? ['role' => (string)$role['id'], 'role_name' => (string)$role['name'], 'role_code' => (string)$role['code']] : []);
		unset($_SESSION[NuSess::HASH_COOKIES], $_SESSION[NuSess::VENDOR]);
		return null;
	}

	/** Back to the globeadmin. Returns the page to go to. */
	public static function stop(string $why = ''): string
	{
		$i = self::active();
		if (!$i) return nu_url('home');
		$details = ['login' => $i['login'], 'mode' => $i['mode'],
			'minutes' => (int)round((time() - (int)$i['since']) / 60)] + ((string)($i['role_code'] ?? '') !== '' ? ['role' => (string)$i['role_code']] : []) + ($why !== '' ? ['reason' => $why] : []);
		unset($_SESSION[NuSess::IMP], $_SESSION[NuSess::HASH_COOKIES]);
		NuAuth::startAs($i['orig']);
		NuLog::write('impersonate_end', 'zzzzsys_user', (string)$i['id'], $details, ['id' => (string)$i['orig']['id'], 'login' => (string)$i['by']]);
		return $i['back'] !== '' ? $i['back'] : nu_url('home');
	}

	/** Only local index.php links come back. */
	private static function safeBack(string $url): string
	{
		return preg_match('~^index\.php\?p=[a-z]+(&[A-Za-z0-9_=%.\-]*)*$~', $url) ? $url : '';
	}

	/** Routes the acting globeadmin cannot use (they change the user's own login). */
	public static function blockedRoute(string $p): bool
	{
		return in_array($p, ['password', 'security', 'twofa'], true);
	}

	/** Form posts allowed in view mode. */
	public static function allowedPost(string $p): bool
	{
		return in_array($p, ['impersonate', 'logout', 'lang'], true);
	}

	/** Coloured bar on every page while acting. */
	public static function banner(): string
	{
		$i = self::active();
		if (!$i) return '';
		$u = NuAuth::user();
		$view = $i['mode'] === 'view';
		return '<div class="nuImpBar ' . ($view ? 'nuImpView' : 'nuImpFull') . '" role="status"><div class="nuContainer d-flex flex-wrap align-items-center gap-2 py-1">'
			. '<span class="me-auto" id="nuImpText"><i class="bi ' . ($view ? 'bi-eye' : 'bi-person-fill-gear') . ' me-2"></i>'
			. ((string)($i['role'] ?? '') !== ''
				? t('imp_banner_role', '<b>' . h((string)$i['role_name']) . '</b>', '<b>' . h($u['display'] ?? $i['login']) . '</b> (' . h($i['login']) . ')')
				: t('imp_banner', '<b>' . h($u['display'] ?? $i['login']) . '</b> (' . h($i['login']) . ')'))
			. ' · <span class="badge ' . ($view ? 'text-bg-info' : 'text-bg-danger') . '">'
			. h(t($view ? 'imp_mode_view' : 'imp_mode_full')) . '</span></span>'
			. '<form method="post" action="' . h(nu_url('impersonate')) . '" class="m-0">' . nu_csrf_field() . '<input type="hidden" name="action" value="stop">'
			. '<button class="btn btn-sm btn-light" type="submit"><i class="bi bi-arrow-return-left me-1"></i>' . h(t('imp_back')) . '</button></form>'
			. '</div></div>';
	}

	/** "Act as user" dropdown (user form, sessions page). */
	/** $iconOnly: just the icon (user page, in front of the user name), the label as tooltip. */
	public static function button(string $userId, string $login, string $back, bool $small = false, bool $iconOnly = false): string
	{
		$u = NuAuth::user();
		if (!$u || !NuAdmin::can('impersonate', 'change') || self::active() || $userId === '' || $userId === 'globeadmin') return '';
		if (!$u['globeadmin'] && ((string)$u['id'] === $userId || NuAdmin::roleOfUser($userId) !== null)) return '';
		$form = static fn(string $mode, string $icon, string $label, string $confirm = '') => '<li><form method="post" action="' . h(nu_url('impersonate')) . '"'
			. ($confirm !== '' ? ' data-confirm="' . h($confirm) . '" data-confirm-ok="' . h(t('imp_start')) . '" data-confirm-class="btn btn-danger"' : '') . '>'
			. nu_csrf_field() . '<input type="hidden" name="action" value="start"><input type="hidden" name="id" value="' . h($userId) . '">'
			. '<input type="hidden" name="mode" value="' . $mode . '"><input type="hidden" name="back" value="' . h($back) . '">'
			. '<button class="dropdown-item" type="submit"><i class="bi ' . $icon . ' me-2"></i>' . $label . '</button></form></li>';
		$menu = '<ul class="dropdown-menu ' . ($iconOnly ? 'nuImpMenu' : 'dropdown-menu-end') . '">';
		$toggle = $iconOnly
			? '<div class="dropdown nuImpIcon"><button class="btn nuImpIconBtn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="'
				. h(t('imp_act_as')) . '" aria-label="' . h(t('imp_act_as_user', $login)) . '">'
				. '<i class="bi bi-person-fill-gear"></i></button>'
			: '<div class="dropdown"><button class="btn ' . ($small ? 'btn-sm ' : '') . 'btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">'
				. '<i class="bi bi-person-fill-gear me-1"></i>' . h(t('imp_act_as')) . '</button>';
		return $toggle . $menu
			. '<li><h6 class="dropdown-header">' . h(t('imp_act_as_user', $login)) . '</h6></li>'
			. $form('view', 'bi-eye', h(t('imp_mode_view')) . '<div class="small text-body-secondary">' . h(t('imp_mode_view_hint')) . '</div>')
			. $form('full', 'bi-pencil-square', h(t('imp_mode_full')) . '<div class="small text-body-secondary">' . h(t('imp_mode_full_hint')) . '</div>', t('imp_full_confirm', $login))
			. '</ul></div>';
	}
}
