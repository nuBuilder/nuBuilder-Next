<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;
/*
 * Getting ready (0.8.1310): a checklist for the globeadmin after the installation – security, server, database, backups, e-mail, people,
 * look and next steps. Points marked 'auto' check their state themselves (mostly through NuSecurity::checks()); 'manual' points are
 * ticked by hand. Every point that is not green can be set to "Not needed". Shown as a slim bar below the top bar (bar()), a card on
 * the start page (homeCard()) and the page ?p=gettingready (pages/nugettingready.php, one group per step).
 * Setting row 'getting_ready' {marks: {id: done|na}, hide: {mode: later|forever, until, bad: [ids red when hidden]}}.
 * Hidden "for now" = 7 days; red points of the groups in COMEBACK that were not red when it was hidden bring the bar back in both modes.
 * The summary for the bar is kept in the session for CACHE seconds (the checks run a few queries); a POST of the page refreshes it.
 * A new point = an entry in ITEMS + texts gr_i_<id> / gr_i_<id>_d (+ a case in check() for 'auto').
 */
final class NuReady
{
	public const SETTING = 'getting_ready';
	public const CACHE = 600;
	/** Open points the start page card names (their states travel with the cached summary). */
	public const CARD_ITEMS = 3;
	public const LATER_DAYS = 7;
	/** Groups in their order: id => [icon, text key]. */
	public const GROUPS = [
		'security' => ['bi-shield-lock', 'gr_g_security'],
		'server'   => ['bi-hdd-rack', 'gr_g_server'],
		'database' => ['bi-database-check', 'gr_g_database'],
		'backups'  => ['bi-archive', 'gr_g_backups'],
		'mail'     => ['bi-envelope', 'gr_g_mail'],
		'people'   => ['bi-people', 'gr_g_people'],
		'look'     => ['bi-palette', 'gr_g_look'],
		'next'     => ['bi-signpost-2', 'gr_g_next'],
	];
	/** Groups whose new red points bring a hidden bar back. */
	public const COMEBACK = ['security', 'backups'];
	/** Points: id => [group, auto|manual, route, route params, anchor]. */
	public const ITEMS = [
		'adminpw'    => ['security', 'auto', 'configfile', [], ''],
		'admin2fa'   => ['security', 'auto', 'twofa', [], ''],
		'https'      => ['security', 'auto', 'help', [], '#security-checklist'],
		'secretkey'  => ['security', 'auto', 'configfile', [], ''],
		'seccheck'   => ['security', 'auto', 'securitycheck', [], ''],
		'pwpolicy'   => ['security', 'manual', 'pwpolicy', [], ''],
		'signin'     => ['security', 'manual', 'ipaccess', [], ''],
		'php'        => ['server', 'auto', 'update', ['tab' => 'system'], ''],
		'ext'        => ['server', 'auto', 'update', ['tab' => 'system'], ''],
		'folders'    => ['server', 'auto', 'update', ['tab' => 'system'], ''],
		'cron'       => ['server', 'auto', 'help', [], '#config-cron'],
		'integrity'  => ['database', 'manual', 'update', ['tab' => 'system', 'integrity' => '1'], ''],
		'compat'     => ['database', 'manual', 'compat', [], ''],
		'forte'      => ['database', 'manual', 'settings', ['s' => 'forte'], ''],
		'backup'     => ['backups', 'auto', 'update', ['tab' => 'backups'], ''],
		'cleanup'    => ['backups', 'auto', 'update', ['tab' => 'backups'], ''],
		'github'     => ['backups', 'auto', 'settings', ['s' => 'updates'], ''],
		'libs'       => ['backups', 'auto', 'update', ['tab' => 'libraries'], ''],
		'mail'       => ['mail', 'auto', 'email', ['tab' => 'settings'], ''],
		'welcome'    => ['mail', 'manual', 'emailtemplates', [], ''],
		'levels'     => ['people', 'auto', 'access', [], ''],
		'users'      => ['people', 'auto', 'users', [], ''],
		'roles'      => ['people', 'manual', 'adminroles', [], ''],
		'startpage'  => ['people', 'manual', 'access', [], ''],
		'branding'   => ['look', 'auto', 'branding', [], ''],
		'languages'  => ['look', 'auto', 'settings', ['s' => 'general'], '#setLangs'],
		'theme'      => ['look', 'manual', 'themes', [], ''],
		'sample'     => ['next', 'manual', 'sample', [], ''],
		'tutorial'   => ['next', 'auto', 'tutorial', [], ''],
		'pwa'        => ['next', 'manual', 'branding', [], '#pwa'],
	];
	public const MARKS = ['done', 'na'];

	/** The globeadmin only, not while acting as a user, not in demo mode. */
	public static function allowed(): bool
	{
		$u = NuAuth::user();
		return $u !== null && !empty($u['globeadmin']) && empty($u['demo']) && !NuImpersonate::active();
	}

	/** Point ids that apply here ('compat' only on a database that came from nuBuilder Forte). */
	public static function ids(): array
	{
		$ids = array_keys(self::ITEMS);
		$origin = '';
		try { $origin = NuInstall::origin(); } catch (Throwable) { /* unknown */ }
		if ($origin === 'nunext') $ids = array_values(array_diff($ids, ['compat']));
		return $ids;
	}

	public static function state(): array
	{
		$s = ['marks' => [], 'hide' => null];
		try {
			$v = nu_setting_value(self::SETTING);
			$j = is_string($v) ? json_decode($v, true) : null;
			if (is_array($j)) {
				foreach ((array)($j['marks'] ?? []) as $k => $m) if (isset(self::ITEMS[$k]) && in_array($m, self::MARKS, true)) $s['marks'][$k] = $m;
				$h = $j['hide'] ?? null;
				if (is_array($h) && in_array($h['mode'] ?? '', ['later', 'forever'], true))
					$s['hide'] = ['mode' => $h['mode'], 'until' => (int)($h['until'] ?? 0), 'bad' => array_values(array_filter((array)($h['bad'] ?? []), 'is_string'))];
			}
		} catch (Throwable) { /* defaults */ }
		return $s;
	}

	private static function save(array $s): void
	{
		NuSchema::ensure();
		nu_setting_save(self::SETTING, ['setting_value' => json_encode(['marks' => (object)$s['marks'], 'hide' => $s['hide']]),
			'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => (string)(NuAuth::user()['login'] ?? '')]);
		unset($_SESSION['nu_gr']);
	}

	/** Ticks a point (done / na) or takes the tick away (''). */
	public static function mark(string $id, string $mark): void
	{
		if (!isset(self::ITEMS[$id])) return;
		$s = self::state();
		if ($mark === '') unset($s['marks'][$id]);
		elseif (in_array($mark, self::MARKS, true)) $s['marks'][$id] = $mark;
		self::save($s);
	}

	/** Hides the bar and the start page card: later (LATER_DAYS) or forever; '' shows them again. */
	public static function hide(string $mode): void
	{
		if ($mode === 'session') {                             // until the next sign-in: only this session (0.8.1312)
			$_SESSION['nu_gr_hide'] = 1;
			return;
		}
		unset($_SESSION['nu_gr_hide']);
		$s = self::state();
		if ($mode === '') $s['hide'] = null;
		else {
			$bad = [];
			foreach (self::items() as $id => $it) if ($it['st'] === 'bad') $bad[] = $id;
			$s['hide'] = ['mode' => $mode === 'forever' ? 'forever' : 'later', 'until' => time() + self::LATER_DAYS * 86400, 'bad' => $bad];
		}
		self::save($s);
	}

	/** Every point with its state: id => [group, kind, st (ok|warn|bad|todo), mark ('', done, na), detail, url, open (not done)]. */
	public static function items(): array
	{
		$s = self::state();
		$sec = [];
		try { foreach (NuSecurity::checks() as $c) $sec[$c['id']] = $c; } catch (Throwable $e) { error_log('nuBuilder Next getting ready: ' . $e->getMessage()); }
		$out = [];
		foreach (self::ids() as $id) {
			[$group, $kind, $route, $params, $anchor] = self::ITEMS[$id];
			[$st, $detail] = $kind === 'manual' ? ['todo', ''] : self::check($id, $sec);
			$mark = $s['marks'][$id] ?? '';
			if ($kind === 'auto' && $st === 'ok') $mark = '';
			$out[$id] = ['group' => $group, 'kind' => $kind, 'st' => $st, 'mark' => $mark, 'detail' => $detail,
				'url' => nu_url($route, $params) . $anchor, 'open' => $st !== 'ok' && $mark === ''];
		}
		return $out;
	}

	/** State of an automatic point: [ok|warn|bad, detail]. */
	private static function check(string $id, array $sec): array
	{
		$from = static function (string $k) use ($sec): array {
			$c = $sec[$k] ?? null;
			if (!$c) return ['warn', ''];
			$st = $c['status'] === 'ok' ? 'ok' : ($c['status'] === 'bad' ? 'bad' : 'warn');
			return [$st, (string)$c['detail']];
		};
		try {
			switch ($id) {
				case 'adminpw': case 'secretkey': case 'cron': return $from($id);
				case 'https': return $from('https');
				case 'admin2fa':
					[$st, $d] = $from('admin2fa');
					return [$st === 'ok' ? 'ok' : 'warn', $d];
				case 'seccheck':
					$own = ['https', 'adminpw', 'admin2fa', 'secretkey', 'php', 'php_update_ext', 'cron'];
					$n = 0; $w = 0;
					foreach ($sec as $k => $c) {
						if (in_array($k, $own, true)) continue;
						if ($c['status'] === 'bad') $n++;
						elseif ($c['status'] === 'warn') $w++;
					}
					return [$n ? 'bad' : ($w ? 'warn' : 'ok'), $n ? t('gr_n_red', $n) : ($w ? t('gr_n_yellow', $w) : '')];
				case 'php':
					[$a, $da] = $from('php');
					[$b, $db] = $from('php_update_ext');
					$st = in_array('bad', [$a, $b], true) ? 'bad' : (in_array('warn', [$a, $b], true) ? 'warn' : 'ok');
					return [$st, trim(PHP_VERSION . ' · ' . $db, ' ·')];
				case 'ext':
					$miss = array_values(array_filter(['intl', 'gd', 'curl', 'mbstring', 'openssl'], static fn($e) => !extension_loaded($e)));
					return [$miss ? 'warn' : 'ok', $miss ? t('gr_missing', implode(', ', $miss)) : ''];
				case 'folders':
					$bad = [];
					foreach ([NuUpdate::backupDir(), NU_ROOT . '/_update', NU_ROOT . '/temp', NU_ROOT . '/custom'] as $d) {
						$ok = is_dir($d) ? is_writable($d) : is_writable(dirname($d));
						if (!$ok) $bad[] = nu_short_path($d);
					}
					return [$bad ? 'bad' : 'ok', $bad ? t('gr_not_writable', implode(', ', $bad)) : ''];
				case 'backup':
					$b = NuUpdate::backups();
					if (!$b) return ['bad', t('gr_backup_none')];
					$t = 0;
					foreach ($b as $x) $t = max($t, (int)strtotime((string)$x['created']));
					$days = $t ? (int)floor((time() - $t) / 86400) : 999;
					return [$days > 30 ? 'warn' : 'ok', $t ? t('gr_backup_last', date(nu_dfmt('d.m.Y'), $t)) : ''];
				case 'cleanup':
					$r = NuUpdate::cleanupRule();
					return [$r['auto'] ? 'ok' : 'warn', ''];
				case 'github':
					$g = NuGitHub::settings();
					return [$g['auto'] ? 'ok' : 'warn', ''];
				case 'libs':
					$miss = [];
					if (NuReport::tcpdfDir() === null) $miss[] = 'TCPDF';
					$pm = false;
					foreach (NuMailer::phpMailerCandidates() as $d) if (is_file($d . '/PHPMailer.php') || is_file($d . '/src/PHPMailer.php')) { $pm = true; break; }
					if (!$pm) $miss[] = 'PHPMailer';
					$unmet = NuLibs::unmet(NuLibs::required());
					foreach ($unmet as $um) $miss[] = (string)($um['title'] ?? $um['slot'] ?? '');
					return [$miss ? 'warn' : 'ok', $miss ? t('gr_missing', implode(', ', array_unique($miss))) : ''];
				case 'mail':
					return [NuMail::configured() ? 'ok' : 'warn', ''];
				case 'levels':
					$n = (int)nu_db()->value("SELECT COUNT(*) FROM zzzzsys_access WHERE zzzzsys_access_id NOT LIKE 'sample%' AND zzzzsys_access_id NOT LIKE 'tkt%'");
					return [$n > 0 ? 'ok' : 'warn', $n > 0 ? (string)$n : ''];
				case 'users':
					$n = (int)nu_db()->value("SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name NOT LIKE 'sample\\_%' AND sus_login_name NOT LIKE 'tkt\\_%'");
					return [$n > 0 ? 'ok' : 'warn', $n > 0 ? (string)$n : ''];
				case 'branding':
					$b = NuBranding::get();
					return [$b['title'] !== '' || $b['logo'] ? 'ok' : 'warn', ''];
				case 'languages':
					return [nu_setting_value(NuLanguages::KEY) !== null ? 'ok' : 'warn', implode(', ', NuLanguages::installed())];
				case 'tutorial':
					[$d, $n] = NuTutorial::counts();
					return [$d > 0 ? 'ok' : 'warn', $n ? t('tut_progress', $d, $n) : ''];
			}
		} catch (Throwable $e) {
			error_log('nuBuilder Next getting ready (' . $id . '): ' . $e->getMessage());
		}
		return ['warn', ''];
	}

	/** Counts of the points: [done, total, red, open ids in order]. */
	public static function summarise(array $items): array
	{
		$done = 0; $bad = 0; $open = [];
		foreach ($items as $id => $it) {
			if (!$it['open']) $done++;
			else {
				$open[] = $id;
				if ($it['st'] === 'bad') $bad++;
			}
		}
		$st = [];
		foreach (array_slice($open, 0, self::CARD_ITEMS) as $id) $st[$id] = $items[$id]['st'];
		return ['done' => $done, 'total' => count($items), 'bad' => $bad, 'open' => $open,
			'badIds' => array_keys(array_filter($items, static fn($it) => $it['open'] && $it['st'] === 'bad')), 'st' => $st];
	}

	/** The summary for the bar and the card, from the session when it is fresh. */
	public static function summary(): array
	{
		$c = $_SESSION['nu_gr'] ?? null;
		if (is_array($c) && ($c['t'] ?? 0) > time() - self::CACHE && isset($c['st'])) return $c;
		$c = self::summarise(self::items()) + ['t' => time()];
		$_SESSION['nu_gr'] = $c;
		return $c;
	}

	/** Should the bar / card show now? */
	public static function visible(): bool
	{
		if (!self::allowed()) return false;
		try { if (NuSetupWizard::pending()) return false; } catch (Throwable) { /* none */ }
		$sum = self::summary();
		if (!$sum['open']) return false;
		if (!empty($_SESSION['nu_gr_hide'])) return false;
		$h = self::state()['hide'];
		if ($h === null) return true;
		$back = false;
		foreach ($sum['badIds'] as $id) if (in_array(self::ITEMS[$id][0] ?? '', self::COMEBACK, true) && !in_array($id, $h['bad'], true)) $back = true;
		if ($back) return true;
		return $h['mode'] === 'later' && $h['until'] < time();
	}

	/** Progress ring as SVG (attributes only – strict CSP). */
	public static function ring(int $done, int $total, int $size = 44): string
	{
		$r = 16; $c = 2 * M_PI * $r;
		$part = $total > 0 ? $done / $total : 0;
		return '<svg class="nuGrRing" width="' . $size . '" height="' . $size . '" viewBox="0 0 40 40" aria-hidden="true">'
			. '<circle class="nuGrRingBg" cx="20" cy="20" r="' . $r . '" fill="none" stroke-width="4"></circle>'
			. '<circle class="nuGrRingFg" cx="20" cy="20" r="' . $r . '" fill="none" stroke-width="4" stroke-linecap="round" transform="rotate(-90 20 20)"'
			. ' stroke-dasharray="' . round($c * $part, 2) . ' ' . round($c, 2) . '"></circle>'
			. '<text x="20" y="24" text-anchor="middle" class="nuGrRingTxt">' . (int)round($part * 100) . '%</text></svg>';
	}

	/** Hide menu (forms with the current page as the way back). */
	private static function hideMenu(string $id): string
	{
		$back = (string)($_SERVER['REQUEST_URI'] ?? '');
		$q = strpos($back, 'index.php?');
		$back = $q !== false ? substr($back, $q) : 'index.php?p=home';
		$f = static fn(string $mode, string $label, string $icon): string => '<li><form method="post" action="' . h(nu_url('gettingready')) . '" class="m-0">' . nu_csrf_field()
			. '<input type="hidden" name="action" value="hide"><input type="hidden" name="mode" value="' . h($mode) . '"><input type="hidden" name="back" value="' . h($back) . '">'
			. '<button type="submit" class="dropdown-item" data-gr-hide="' . h($mode) . '"><i class="bi ' . $icon . ' me-2"></i>' . h($label) . '</button></form></li>';
		return '<div class="dropdown"><button type="button" class="btn btn-sm nuGrHideBtn" id="' . h($id) . '" data-bs-toggle="dropdown" aria-expanded="false" title="' . h(t('gr_hide')) . '" aria-label="' . h(t('gr_hide')) . '"><i class="bi bi-x-lg"></i></button>'
			. '<ul class="dropdown-menu dropdown-menu-end">' . $f('session', t('gr_hide_session'), 'bi-box-arrow-in-right') . $f('later', t('gr_hide_later', self::LATER_DAYS), 'bi-clock') . $f('forever', t('gr_hide_forever'), 'bi-eye-slash')
			. '<li><hr class="dropdown-divider"></li><li><span class="dropdown-item-text small text-body-secondary">' . h(t('gr_hide_note')) . '</span></li></ul></div>';
	}

	/** The slim bar below the top bar (nu_layout_start). */
	public static function bar(string $active): string
	{
		if ($active === 'gettingready' || $active === 'home') return '';
		try { if (!self::visible()) return ''; } catch (Throwable) { return ''; }
		$s = self::summary();
		return '<div class="nuGrBar" id="nuGrBar" role="status"><div class="nuGrBarIn">'
			. '<i class="bi bi-rocket-takeoff" aria-hidden="true"></i>'
			. '<a class="nuGrBarText" href="' . h(nu_url('gettingready')) . '"><b>' . h(t('gr_title')) . '</b> <span class="nuGrBarCount">' . h(t('gr_count', $s['done'], $s['total'])) . '</span>'
			. ($s['bad'] ? ' <span class="badge text-bg-danger nuGrBarBad">' . h(t('gr_to_fix', $s['bad'])) . '</span>' : '') . '</a>'
			. '<a class="btn btn-sm btn-outline-primary nuGrBarOpen" href="' . h(nu_url('gettingready')) . '">' . h(t('gr_open')) . '</a>'
			. self::hideMenu('nuGrBarHide') . '</div></div>';
	}

	/** The start page card: ring, the next three open points, Open. */
	public static function homeCard(): string
	{
		try { if (!self::visible()) return ''; } catch (Throwable) { return ''; }
		// from the summary kept in the session (0.8.1341): the card used to run every check again on each view of the start page – the
		// whole security check and the scan of the application's code, a third of the page's time and about 45 statements
		$s = self::summary();
		$li = '';
		foreach (array_slice($s['open'], 0, self::CARD_ITEMS) as $id) {
			if (!isset(self::ITEMS[$id], $s['st'][$id])) continue;
			[, , $route, $params, $anchor] = self::ITEMS[$id];
			$li .= '<li><a href="' . h(nu_url($route, $params) . $anchor) . '" class="nuGrNext"><span class="nuGrDot nuGrSt-' . h($s['st'][$id]) . '" aria-hidden="true"></span>' . h(t('gr_i_' . $id)) . '</a></li>';
		}
		return '<div class="card mb-4 nuGrCard" id="nuGrCard"><div class="card-body d-flex flex-wrap align-items-center gap-3">'
			. self::ring($s['done'], $s['total'], 56)
			. '<div class="flex-grow-1 nuGrCardMain"><div class="fw-semibold">' . h(t('gr_title')) . ' <span class="text-body-secondary fw-normal small">' . h(t('gr_count', $s['done'], $s['total'])) . '</span></div>'
			. '<div class="small text-body-secondary">' . h(t('gr_card_next')) . '</div><ul class="nuGrNextList">' . $li . '</ul></div>'
			. '<div class="d-flex gap-2 align-items-center"><a class="btn btn-primary btn-sm" href="' . h(nu_url('gettingready')) . '" id="nuGrCardOpen">' . h(t('gr_open')) . '</a>'
			. self::hideMenu('nuGrCardHide') . '</div></div></div>';
	}
}
