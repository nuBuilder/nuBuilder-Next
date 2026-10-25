<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * "Act as user": the globeadmin works as another user – with that user's access level, rights, permission items and hash variables.
 *   mode 'view': only view / print (no save, delete, clone, new, no procedures, no form posts) · mode 'full': everything the user may do.
 * The globeadmin's own login stays in the session ($_SESSION['nu_imp']['orig']) and comes back with "Back to globeadmin".
 * Not allowed while acting: change password, security page (2FA, devices) – they belong to the user.
 */
final class NuImpersonate
{
	public const MODES = ['view', 'full'];

	public static function active(): ?array
	{
		$i = $_SESSION['nu_imp'] ?? null;
		return is_array($i) && isset($i['orig'], $i['id']) ? $i : null;
	}

	public static function readOnly(): bool
	{
		return (self::active()['mode'] ?? '') === 'view';
	}

	/** Who really acts (for the log): the globeadmin's login, or ''. */
	public static function by(): string
	{
		return (string)(self::active()['by'] ?? '');
	}

	/** @return string|null error key, null = started */
	public static function start(string $userId, string $mode, string $back = ''): ?string
	{
		$me = NuAuth::user();
		if (!$me || !NuAdmin::can('impersonate', 'change') || self::active()) return 'imp_err_not_allowed';
		if (!empty($me['demo'])) return 'demo_not_available';     // demo mode: never another user's rights
		if (!in_array($mode, self::MODES, true)) return 'err_invalid';
		$row = nu_db()->one('SELECT zzzzsys_user_id, sus_login_name, sus_login_password FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]);
		if (!$row) return 'record_not_found';
		if (!$me['globeadmin'] && (NuAdmin::roleOfUser($userId) !== null || (string)$me['id'] === $userId)) return 'imp_err_not_allowed';   // admin roles: not another admin
		$orig = $_SESSION['nu_auth'];
		$target = ['id' => (string)$row['zzzzsys_user_id'], 'globeadmin' => false, 'login' => (string)$row['sus_login_name'],
			'fp' => NuAuth::fingerprint((string)$row['sus_login_password']), 'via' => 'impersonated'];
		NuLog::write('impersonate_start', 'zzzzsys_user', $target['id'], ['login' => $target['login'], 'mode' => $mode]);
		NuAuth::startAs($target);
		$_SESSION['nu_imp'] = ['orig' => $orig, 'id' => $target['id'], 'login' => $target['login'], 'mode' => $mode, 'by' => (string)$me['login'],
			'since' => time(), 'back' => self::safeBack($back)];
		unset($_SESSION['nu_hashcookies'], $_SESSION['nu_vendor']);
		return null;
	}

	/** Back to the globeadmin. Returns the page to go to. */
	public static function stop(string $why = ''): string
	{
		$i = self::active();
		if (!$i) return nu_url('home');
		$details = ['login' => $i['login'], 'mode' => $i['mode'], 'minutes' => (int)round((time() - (int)$i['since']) / 60)] + ($why !== '' ? ['reason' => $why] : []);
		unset($_SESSION['nu_imp'], $_SESSION['nu_hashcookies']);
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
			. '<span class="me-auto"><i class="bi ' . ($view ? 'bi-eye' : 'bi-person-fill-gear') . ' me-2"></i>' . t('imp_banner', '<b>' . h($u['display'] ?? $i['login']) . '</b> (' . h($i['login']) . ')') . ' · <span class="badge ' . ($view ? 'text-bg-info' : 'text-bg-danger') . '">'
			. h(t($view ? 'imp_mode_view' : 'imp_mode_full')) . '</span></span>'
			. '<form method="post" action="' . h(nu_url('impersonate')) . '" class="m-0">' . nu_csrf_field() . '<input type="hidden" name="action" value="stop">'
			. '<button class="btn btn-sm btn-light" type="submit"><i class="bi bi-arrow-return-left me-1"></i>' . h(t('imp_back')) . '</button></form>'
			. '</div></div>';
	}

	/** "Act as user" dropdown (user form, sessions page). */
	/** $iconOnly: just the icon (user page, in front of the user name – 0.8.200), the label as tooltip. */
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
			? '<div class="dropdown nuImpIcon"><button class="btn nuImpIconBtn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="' . h(t('imp_act_as')) . '" aria-label="' . h(t('imp_act_as_user', $login)) . '">'
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
