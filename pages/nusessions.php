<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* Logged-in users (globeadmin only): nuBuilder Next sessions with IP and last activity, plus nuBuilder's sessions (zzzzsys_session). */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('sessions');
$db = nu_db();
NuSchema::ensure();
NuSessions::purge();

if (nu_is_post() && nu_post('action') === 'end') {
	$key = nu_post('key');
	$row = $db->one('SELECT login FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id = ?', [$key]);
	if ($row && $key !== NuSessions::key()) {
		NuSessions::end($key);
		NuLog::write('session_ended_by_admin', 'zzzzsys_nunext_session', '', ['login' => (string)$row['login']]);
		nu_flash('success', t('sess_ended', (string)$row['login']));
	}
	nu_redirect(nu_url('sessions'));
}

// all sessions of one user (0.8.687, the group row of a user with several sessions) – never the own one
if (nu_is_post() && nu_post('action') === 'end_user') {
	$login = nu_post('login');
	$mine = NuSessions::key();
	$n = 0;
	foreach ($db->all('SELECT zzzzsys_nunext_session_id FROM zzzzsys_nunext_session WHERE login = ?', [$login]) as $r) {
		$k = (string)$r['zzzzsys_nunext_session_id'];
		if ($k === $mine) continue;
		$n += NuSessions::end($k) > 0 ? 1 : 0;
	}
	if ($n) NuLog::write('session_ended_by_admin', 'zzzzsys_nunext_session', '', ['login' => $login, 'sessions' => $n]);
	nu_flash('success', t('sess_ended_user', $n, $login));
	nu_redirect(nu_url('sessions'));
}

// log out all users (0.8.254): end_others keeps the own session, end_all ends it too (sign in again afterwards)
if (nu_is_post() && in_array(nu_post('action'), ['end_others', 'end_all'], true)) {
	$all  = nu_post('action') === 'end_all';
	$mine = NuSessions::key();
	$n = 0;
	foreach ($db->all('SELECT zzzzsys_nunext_session_id FROM zzzzsys_nunext_session') as $r) {
		$k = (string)$r['zzzzsys_nunext_session_id'];
		if ($k === $mine) continue;
		$n += NuSessions::end($k) > 0 ? 1 : 0;
	}
	NuLog::write('sessions_ended_all', 'zzzzsys_nunext_session', '', ['sessions' => $n + ($all ? 1 : 0), 'including_own' => $all]);
	if ($all) {
		NuSessions::end($mine);
		NuAuth::logout();
		nu_flash('success', t('sess_ended_all', $n + 1));
		nu_redirect(nu_url('login'));
	}
	nu_flash('success', t('sess_ended_others', $n));
	nu_redirect(nu_url('sessions'));
}

// log out idle users (0.8.595): every session (not the own one) whose last activity is longer ago than the chosen hours
// (only hours below the longest session timeout – older sessions end by themselves: NuSessions::purge, $nuConfigSessionTimeout / …Globeadmin)
$idleHours = array_values(array_filter([1, 2, 4, 8, 12, 24], static fn($hh) => $hh * 3600 < max(nu_session_timeout(false), nu_session_timeout(true))));
if (nu_is_post() && nu_post('action') === 'end_idle') {
	$hours = (int)nu_post('hours');
	if (!in_array($hours, $idleHours, true)) nu_redirect(nu_url('sessions'));
	$mine = NuSessions::key();
	$before = date('Y-m-d H:i:s', time() - $hours * 3600);
	$n = 0;
	foreach ($db->all('SELECT zzzzsys_nunext_session_id FROM zzzzsys_nunext_session WHERE last_seen < ?', [$before]) as $r) {
		$k = (string)$r['zzzzsys_nunext_session_id'];
		if ($k === $mine) continue;
		$n += NuSessions::end($k) > 0 ? 1 : 0;
	}
	NuLog::write('sessions_ended_idle', 'zzzzsys_nunext_session', '', ['sessions' => $n, 'idle_hours' => $hours]);
	nu_flash('success', t('sess_ended_idle', $n, $hours));
	nu_redirect(nu_url('sessions'));
}

$timeout = intdiv(nu_session_timeout(false), 60);
$timeoutAdmin = intdiv(nu_session_timeout(true), 60);
$rows = $db->all('SELECT * FROM zzzzsys_nunext_session ORDER BY last_seen DESC');
$users = [];
if ($db->tableExists('zzzzsys_user')) {
	foreach ($db->all('SELECT zzzzsys_user_id, sus_login_name, sus_name, sus_first_name, sus_last_name FROM zzzzsys_user') as $u) $users[(string)$u['zzzzsys_user_id']] = $u;
}

// nuBuilder's own sessions (logged in to nuBuilder; activity = sss_time)
$nb = [];
if ($db->tableExists('zzzzsys_session')) {
	$nbTimeout = (int)(NuMailer::setupRow()['set_time_out_minutes'] ?? 0) ?: 480;
	$cols = $db->columns('zzzzsys_session');
	foreach ($db->all('SELECT zzzzsys_session_id, sss_access, sss_time' . (isset($cols['sss_login_time']) ? ', sss_login_time' : '') . ' FROM zzzzsys_session WHERE sss_time >= ? ORDER BY sss_time DESC',
		[time() - $nbTimeout * 60]) as $r) {
		if (NuVendor::isVendorRow((string)$r['sss_access'])) continue;   // nuBuilder Next's phpMyAdmin / File Manager login
		$a = json_decode((string)$r['sss_access'], true);
		$s = is_array($a['session'] ?? null) ? $a['session'] : [];
		$uid = (string)($s['zzzzsys_user_id'] ?? '');
		$isAdmin = (string)($s['global_access'] ?? '') === '1';
		$nb[] = ['uid' => $isAdmin ? '' : $uid, 'login' => $isAdmin ? (string)nu_cfg('nuConfigDBGlobeadminUsername') : (string)($users[$uid]['sus_login_name'] ?? $uid),
			'login_at' => $r['sss_login_time'] ?? null, 'last' => (int)$r['sss_time']];
	}
}

// sortable columns (0.8.508): ?sort=user|ip|browser|login|last &dir=asc|desc – default: last activity, newest first
$sort = in_array($_GET['sort'] ?? '', ['user', 'ip', 'browser', 'login', 'last'], true) ? (string)$_GET['sort'] : 'last';
$dir  = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : (($_GET['dir'] ?? '') === 'desc' || !isset($_GET['sort']) ? 'desc' : 'asc');
$ts = static fn($v): int => $v === null || $v === '' ? 0 : (is_numeric($v) ? (int)$v : (int)strtotime((string)$v));
$key = [
	'user' => static fn(array $r) => mb_strtolower((string)$r['login']),
	'ip' => static fn(array $r) => (string)($r['ip'] ?? ''),
	'browser' => static fn(array $r) => mb_strtolower(isset($r['user_agent']) ? NuSessions::agent((string)$r['user_agent']) : ''),
	'login' => static fn(array $r) => $ts($r['login_at'] ?? null),
	'last' => static fn(array $r) => isset($r['last_seen']) ? $ts($r['last_seen']) : (int)($r['last'] ?? 0),
][$sort];
$cmp = static function (array $a, array $b) use ($key, $dir): int {
	$x = $key($a); $y = $key($b);
	$c = is_int($x) && is_int($y) ? $x <=> $y : strnatcasecmp((string)$x, (string)$y);
	return $dir === 'asc' ? $c : -$c;
};
usort($rows, $cmp);
usort($nb, $cmp);
// search by user (0.8.540): login name, display name, full / first / last name – in both lists; End all / End others still mean all
$q = trim((string)($_GET['q'] ?? ''));
$allRows = $rows;
if ($q !== '') {
	$hit = static function (string $uid, string ...$texts) use ($q, $users): bool {
		$u = $users[$uid] ?? [];
		foreach (array_merge($texts, [(string)($u['sus_login_name'] ?? ''), (string)($u['sus_name'] ?? ''), trim((string)($u['sus_first_name'] ?? '') . ' ' . (string)($u['sus_last_name'] ?? ''))]) as $x) {
			if ($x !== '' && mb_stripos($x, $q) !== false) return true;
		}
		return false;
	};
	$rows = array_values(array_filter($rows, static fn($r) => $hit((string)$r['user_id'], (string)$r['login'], (string)$r['display'])));
	$nb = array_values(array_filter($nb, static fn($r) => $hit($r['uid'], $r['login'])));
}
// filters (0.8.687): user (id or login – the user form links with its id) and state (active = activity in the last 5 minutes, idle)
$fUser = trim((string)($_GET['user'] ?? ''));
$fState = in_array($_GET['state'] ?? '', ['active', 'idle'], true) ? (string)$_GET['state'] : '';
$userOpts = [];
foreach ($allRows as $r) $userOpts[(string)$r['login']] = trim((string)$r['login'] . ((string)$r['display'] !== '' && $r['display'] !== $r['login'] ? ' – ' . $r['display'] : ''));
foreach ($nb as $r) $userOpts[(string)$r['login']] ??= (string)$r['login'];
uksort($userOpts, 'strnatcasecmp');
if ($fUser !== '') {
	$fLogin = isset($users[$fUser]) ? (string)$users[$fUser]['sus_login_name'] : $fUser;
	if ($fLogin !== '' && !isset($userOpts[$fLogin])) $userOpts[$fLogin] = $fLogin;
	$rows = array_values(array_filter($rows, static fn($r) => (string)$r['user_id'] === $fUser || (string)$r['login'] === $fLogin));
	$nb = array_values(array_filter($nb, static fn($r) => $r['uid'] === $fUser || $r['login'] === $fLogin));
	$fUser = $fLogin;
}
if ($fState !== '') {
	$isIdle = static fn(int $last): bool => time() - $last > 300;
	$rows = array_values(array_filter($rows, static fn($r) => $isIdle((int)strtotime((string)$r['last_seen'])) === ($fState === 'idle')));
	$nb = array_values(array_filter($nb, static fn($r) => $isIdle((int)$r['last']) === ($fState === 'idle')));
}
$qp = array_filter(['q' => $q, 'user' => $fUser, 'state' => $fState], static fn($v) => $v !== '');
$th = static fn(string $label, string $col, string $class = ''): string => nu_sort_th($label, $col, $sort, $dir, 'sessions', $qp, $class);

function nu_sess_ago(int $ts): string
{
	$d = max(0, time() - $ts);
	if ($d < 60) return t('sess_ago_s', $d);
	if ($d < 3600) return t('sess_ago_m', intdiv($d, 60));
	return t('sess_ago_h', intdiv($d, 3600), intdiv($d % 3600, 60));
}

$me = NuSessions::key();
nu_layout_start(t('sess_title'), 'log', ['head' => nu_ts_head()]);
?>
<?php // like Debug and Log (0.8.271): the tabs first, then the card with title, Back · Refresh and the list ?>
<?= NuAudit::tabs('sessions') ?>

<div class="card shadow-sm nuListCard mb-4">
	<div class="card-header nuListHead nuTitleBar">
	<h1 class="h5 mb-0 fw-semibold"><?= h(t('sess_title')) ?><?php $tip = t('sess_hint', $timeout, $timeoutAdmin); ?>
		<span class="nuInfoTip fs-6 fw-normal ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h($tip) ?>" title="<?= h($tip) ?>"><i class="bi bi-info-circle"></i></span></h1>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?>
		<a class="btn nuBtnAct" href="<?= h(nu_url('sessions', (isset($_GET['sort']) ? ['sort' => $sort, 'dir' => $dir] : []) + $qp)) ?>"><i class="bi bi-arrow-clockwise me-1"></i><?= h(t('debug_refresh')) ?></a>
		<?php $others = count(array_filter($allRows, static fn($r) => (string)$r['zzzzsys_nunext_session_id'] !== $me)); ?>
		<?php // one "Log out" button (0.8.653 phones, 0.8.654 every width): its menu holds the actions – idle users with one entry per time, all others,
		// everyone incl. me; each asks first (data-confirm) ?>
		<div class="dropdown" id="sessLogoutMenu"><?php // right of Refresh (0.8.685; was at the far right) ?>
			<button class="btn btn-outline-danger fw-normal dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-box-arrow-right me-1"></i><?= h(t('nav_logout')) ?></button>
			<ul class="dropdown-menu">
				<?php if ($idleHours): ?>
				<li><h6 class="dropdown-header"><i class="bi bi-hourglass-split me-1"></i><?= h(t('sess_end_idle')) ?></h6></li>
				<?php foreach ($idleHours as $hh): ?>
				<li><form method="post" class="m-0" data-confirm="<?= h($hh === 1 ? t('sess_end_idle_confirm_1') : t('sess_end_idle_confirm', $hh)) ?>" data-confirm-ok="<?= h(t('sess_end_idle')) ?>" data-confirm-class="btn btn-danger">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="end_idle"><input type="hidden" name="hours" value="<?= (int)$hh ?>">
					<button class="dropdown-item ps-4" type="submit" data-hours="<?= (int)$hh ?>"><?= h(t('sess_idle_hours', $hh)) ?></button></form></li>
				<?php endforeach; ?>
				<li><hr class="dropdown-divider"></li>
				<?php endif; ?>
				<li><form method="post" class="m-0" data-confirm="<?= h(t('sess_end_others_confirm', $others)) ?>" data-confirm-ok="<?= h(t('sess_end_others')) ?>" data-confirm-class="btn btn-danger">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="end_others">
					<button class="dropdown-item text-danger" type="submit"<?= $others ? '' : ' disabled' ?>><i class="bi bi-people me-2"></i><?= h(t('sess_end_others')) ?></button></form></li>
				<li><form method="post" class="m-0" data-confirm="<?= h(t('sess_end_all_confirm', count($allRows))) ?>" data-confirm-ok="<?= h(t('sess_end_all')) ?>" data-confirm-class="btn btn-danger">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="end_all">
					<button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i><?= h(t('sess_end_all')) ?></button></form></li>
			</ul>
		</div>
	</div>
	</div>
	<form class="nuListFilter" method="get" action="index.php" role="search" id="sessSearch">
		<input type="hidden" name="p" value="sessions">
		<?php if (isset($_GET['sort'])): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= h($dir) ?>"><?php endif; ?>
		<div class="row g-2 align-items-end">
			<?php $sessFiltered = $fUser !== '' || $fState !== ''; // phones (0.8.709): the filters behind the funnel next to the search, as on the users list ?>
			<div class="col-md-6"><?= nu_list_search($q, t('sess_search_hint'), 'sessFilters', $sessFiltered, $qp ? nu_url('sessions', isset($_GET['sort']) ? ['sort' => $sort, 'dir' => $dir] : []) : null) ?></div>
			<div class="col-md-6 collapse<?= $sessFiltered ? ' show' : '' ?>" id="sessFilters" data-nu-open-min="768"><div class="row g-2">
			<div class="col-sm-6"><label class="form-label small mb-1" for="sessFltUser"><?= h(t('user')) ?></label>
				<select class="form-select js-autosubmit<?= count($userOpts) >= 15 ? ' nuListTomSelect' : '' ?>" id="sessFltUser" name="user"><option value=""><?= h(t('all')) ?></option>
					<?php foreach ($userOpts as $lg => $lbl): ?><option value="<?= h($lg) ?>"<?= $fUser === (string)$lg ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></div>
			<div class="col-sm-6"><label class="form-label small mb-1" for="sessFltState"><?= h(t('status')) ?></label>
				<select class="form-select js-autosubmit" id="sessFltState" name="state"><option value=""><?= h(t('all')) ?></option>
					<option value="active"<?= $fState === 'active' ? ' selected' : '' ?>><?= h(t('sess_active')) ?></option><option value="idle"<?= $fState === 'idle' ? ' selected' : '' ?>><?= h(t('sess_idle')) ?></option></select></div>
			</div></div>
		</div>
	</form>
	<div class="px-3 py-2 border-bottom fw-semibold small">nuBuilder Next <span class="badge text-bg-secondary ms-1"><?= count($rows) ?></span></div>
	<div class="table-responsive">
		<table class="table align-middle mb-0 nu-table" id="sessTable">
			<thead><tr><?= $th(t('user'), 'user') . $th('IP', 'ip') . $th(t('sess_browser'), 'browser') . $th(t('sess_login_at'), 'login') . $th(t('sess_last'), 'last') ?><th></th></tr></thead>
			<tbody>
			<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-body-secondary py-4"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
			<?php
			// one group per user with several sessions (0.8.687): a folded header row, the sessions below it (assets/js/nusessions.js)
			$groups = [];
			foreach ($rows as $r) $groups[(string)$r['login']][] = $r;
			$userLink = static fn(array $r): string => NuAcl::can(NuAcl::FORM_USER) && isset($users[(string)$r['user_id']])
				? '<a class="fw-semibold text-decoration-none" href="' . h(nu_url('user', ['id' => (string)$r['user_id']])) . '">' . h($r['login']) . '</a>'
				: '<span class="fw-semibold">' . h($r['login']) . '</span>';
			$gi = 0;
			foreach ($groups as $gLogin => $gRows):
				$grp = count($gRows) > 1 ? 'sg' . (++$gi) : '';
				if ($grp !== ''):
					$gLast = max(array_map(static fn($x) => (int)strtotime((string)$x['last_seen']), $gRows));
					$gFirst = min(array_map(static fn($x) => $x['login_at'] ? (int)strtotime((string)$x['login_at']) : PHP_INT_MAX, $gRows));
					$gIdle = time() - $gLast > 300;
					$gMine = (bool)array_filter($gRows, static fn($x) => (string)$x['zzzzsys_nunext_session_id'] === $me);
					$gIps = array_values(array_unique(array_map(static fn($x) => NuDemo::ip($x['ip']), $gRows))); ?>
				<tr class="nuSessGroup" data-grp="<?= $grp ?>">
					<td>
						<button type="button" class="btn btn-sm btn-link p-0 me-1 nuSessFold" aria-expanded="false" title="<?= h(t('sess_group_toggle')) ?>" aria-label="<?= h(t('sess_group_toggle') . ': ' . $gLogin) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
						<span class="nuSessDot <?= $gIdle ? 'nuSessIdle' : 'nuSessActive' ?>" title="<?= h(t($gIdle ? 'sess_idle' : 'sess_active')) ?>"></span>
						<?= $userLink($gRows[0]) ?>
						<?php if ((string)$gRows[0]['display'] !== '' && $gRows[0]['display'] !== $gLogin): ?><span class="text-body-secondary small ms-1"><?= h($gRows[0]['display']) ?></span><?php endif; ?>
						<span class="badge text-bg-secondary ms-1"><?= h(t('sess_group_n', count($gRows))) ?></span>
						<?php if ($gMine): ?><span class="badge text-bg-primary ms-1"><?= h(t('sess_you')) ?></span><?php endif; ?>
					</td>
					<td class="small text-nowrap"><?= h(count($gIps) === 1 ? $gIps[0] : t('sess_group_ips', count($gIps))) ?></td>
					<td class="small"></td>
					<td class="small text-nowrap"><?= $gFirst !== PHP_INT_MAX ? h(date('d.m.Y H:i', $gFirst)) : '' ?></td>
					<td class="small text-nowrap"><?= h(date('d.m.Y H:i:s', $gLast)) ?><div class="text-body-secondary"><?= h(nu_sess_ago($gLast)) ?></div></td>
					<td class="text-end">
						<?php if (count($gRows) - ($gMine ? 1 : 0) > 0): ?>
						<form method="post" class="d-inline" data-confirm="<?= h(t('sess_end_user_confirm', count($gRows) - ($gMine ? 1 : 0), $gLogin)) ?>" data-confirm-ok="<?= h(t('sess_end')) ?>" data-confirm-class="btn btn-danger">
							<?= nu_csrf_field() ?><input type="hidden" name="action" value="end_user"><input type="hidden" name="login" value="<?= h($gLogin) ?>">
							<button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-box-arrow-right me-1"></i><?= h(t('sess_end_user')) ?></button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif;
			foreach ($gRows as $r):
				$last = strtotime((string)$r['last_seen']) ?: 0;
				$idle = time() - $last > 300;
				$mine = (string)$r['zzzzsys_nunext_session_id'] === $me; ?>
				<tr<?= $grp !== '' ? ' class="nuSessChild" data-grp-of="' . $grp . '" hidden' : '' ?>>
					<td<?= $grp !== '' ? ' class="ps-5"' : '' ?>>
						<span class="nuSessDot <?= $idle ? 'nuSessIdle' : 'nuSessActive' ?>" title="<?= h(t($idle ? 'sess_idle' : 'sess_active')) ?>"></span>
						<?= $userLink($r) ?>
						<?php if ((string)$r['display'] !== '' && $r['display'] !== $r['login']): ?><span class="text-body-secondary small ms-1"><?= h($r['display']) ?></span><?php endif; ?>
						<?php if ($mine): ?><span class="badge text-bg-primary ms-1"><?= h(t('sess_you')) ?></span><?php endif; ?>
						<?php if ((string)$r['via'] === 'nubuilder'): ?><span class="badge bg-info-subtle text-info-emphasis ms-1"><?= h(t('sess_via_nb')) ?></span><?php endif; ?>
						<?php if ((string)$r['via'] === 'impersonated'): ?><span class="badge text-bg-warning ms-1"><i class="bi bi-person-fill-gear me-1"></i><?= h(t('sess_impersonated')) ?></span><?php endif; ?>
					</td>
					<td class="small text-nowrap"><?= h(NuDemo::ip($r['ip'])) ?></td>
					<td class="small" title="<?= h($r['user_agent']) ?>"><?= h(NuSessions::agent((string)$r['user_agent'])) ?></td>
					<td class="small text-nowrap"><?= $r['login_at'] ? h(date('d.m.Y H:i', strtotime((string)$r['login_at']))) : '' ?></td>
					<td class="small text-nowrap"><?= h(date('d.m.Y H:i:s', $last)) ?><div class="text-body-secondary"><?= h(nu_sess_ago($last)) ?></div></td>
					<td class="text-end">
						<div class="d-inline-flex flex-wrap gap-1 justify-content-end">
						<?php if (!$mine && (string)$r['via'] !== 'impersonated' && isset($users[(string)$r['user_id']])): ?><?= NuImpersonate::button((string)$r['user_id'], (string)$r['login'], nu_url('sessions'), true) ?><?php endif; ?>
						<?php if (!$mine): ?>
							<form method="post" data-confirm="<?= h(t('sess_end_confirm', (string)$r['login'])) ?>" data-confirm-ok="<?= h(t('sess_end')) ?>" data-confirm-class="btn btn-danger">
								<?= nu_csrf_field() ?><input type="hidden" name="action" value="end"><input type="hidden" name="key" value="<?= h($r['zzzzsys_nunext_session_id']) ?>">
								<button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-box-arrow-right me-1"></i><?= h(t('sess_end')) ?></button>
							</form>
						<?php endif; ?>
						</div>
					</td>
				</tr>
			<?php endforeach; endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="card-footer small text-body-secondary"><?= h(t('sess_end_hint', NuSessions::TOUCH_EVERY)) ?></div>
</div>

<?php if ($db->tableExists('zzzzsys_session')): ?>
	<div class="card shadow-sm">
		<?php // folded by default (0.8.709) ?>
		<div class="card-header"><button class="btn btn-link p-0 fw-semibold text-decoration-none text-body thLevelsToggle collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sessForte" aria-expanded="false" aria-controls="sessForte" id="sessForteBtn">
			<i class="bi bi-chevron-right me-1 thChevron" aria-hidden="true"></i>nuBuilder Forte</button> <span class="badge text-bg-secondary ms-1"><?= count($nb) ?></span></div>
		<div class="collapse" id="sessForte">
		<div class="table-responsive">
			<table class="table align-middle mb-0 nu-table">
				<thead><tr><?= $th(t('user'), 'user') . $th(t('sess_login_at'), 'login') . $th(t('sess_last'), 'last') ?><th></th></tr></thead>
				<tbody>
				<?php if (!$nb): ?><tr><td colspan="4" class="text-center text-body-secondary py-4"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
				<?php foreach ($nb as $r): ?>
					<tr>
						<td class="fw-semibold"><?= h($r['login']) ?></td>
						<td class="small"><?= $r['login_at'] ? h(date('d.m.Y H:i', strtotime((string)$r['login_at']))) : '' ?></td>
						<td class="small"><?= h(date('d.m.Y H:i:s', $r['last'])) ?> <span class="text-body-secondary">· <?= h(nu_sess_ago($r['last'])) ?></span></td>
						<td class="text-end"><?php if ($r['uid'] !== '' && isset($users[$r['uid']])): ?><div class="d-inline-flex"><?= NuImpersonate::button($r['uid'], $r['login'], nu_url('sessions'), true) ?></div><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="card-footer small text-body-secondary"><?= h(t('sess_nb_hint')) ?></div>
		</div>
	</div>
<?php endif; ?>
<?php
nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nusessions.js') . '"></script>');
