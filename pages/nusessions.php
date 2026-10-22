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

function nu_sess_ago(int $ts): string
{
	$d = max(0, time() - $ts);
	if ($d < 60) return t('sess_ago_s', $d);
	if ($d < 3600) return t('sess_ago_m', intdiv($d, 60));
	return t('sess_ago_h', intdiv($d, 3600), intdiv($d % 3600, 60));
}

$me = NuSessions::key();
nu_layout_start(t('sess_title'), 'log');
?>
<?php // like Debug and Log (0.8.271): the tabs first, then the card with title, Back · Refresh and the list ?>
<?= NuAudit::tabs('sessions') ?>

<div class="card shadow-sm nuListCard mb-4">
	<div class="card-header nuListHead nuTitleBar">
	<h1 class="h5 mb-0 fw-semibold"><?= h(t('sess_title')) ?><?php $tip = t('sess_hint', $timeout, $timeoutAdmin); ?>
		<span class="nuInfoTip fs-6 fw-normal ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h($tip) ?>" title="<?= h($tip) ?>"><i class="bi bi-info-circle"></i></span></h1>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?>
		<a class="btn nuBtnAct" href="<?= h(nu_url('sessions')) ?>"><i class="bi bi-arrow-clockwise me-1"></i><?= h(t('debug_refresh')) ?></a>
		<?php $others = count(array_filter($rows, static fn($r) => (string)$r['zzzzsys_nunext_session_id'] !== $me)); ?>
		<span class="d-inline-flex flex-wrap gap-2 ms-auto">
		<form method="post" data-confirm="<?= h(t('sess_end_others_confirm', $others)) ?>" data-confirm-ok="<?= h(t('sess_end_others')) ?>" data-confirm-class="btn btn-danger">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="end_others">
			<button class="btn btn-outline-danger fw-normal" type="submit"<?= $others ? '' : ' disabled' ?>><i class="bi bi-people me-1"></i><?= h(t('sess_end_others')) ?></button>
		</form>
		<form method="post" data-confirm="<?= h(t('sess_end_all_confirm', count($rows))) ?>" data-confirm-ok="<?= h(t('sess_end_all')) ?>" data-confirm-class="btn btn-danger">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="end_all">
			<button class="btn btn-outline-danger fw-normal" type="submit"><i class="bi bi-box-arrow-right me-1"></i><?= h(t('sess_end_all')) ?></button>
		</form>
		</span>
	</div>
	</div>
	<div class="px-3 py-2 border-bottom fw-semibold small">nuBuilder Next <span class="badge text-bg-secondary ms-1"><?= count($rows) ?></span></div>
	<div class="table-responsive">
		<table class="table align-middle mb-0 nu-table" id="sessTable">
			<thead><tr><th><?= h(t('user')) ?></th><th>IP</th><th><?= h(t('sess_browser')) ?></th><th><?= h(t('sess_login_at')) ?></th><th><?= h(t('sess_last')) ?></th><th></th></tr></thead>
			<tbody>
			<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-body-secondary py-4"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
			<?php foreach ($rows as $r):
				$last = strtotime((string)$r['last_seen']) ?: 0;
				$idle = time() - $last > 300;
				$mine = (string)$r['zzzzsys_nunext_session_id'] === $me; ?>
				<tr>
					<td>
						<span class="nuSessDot <?= $idle ? 'nuSessIdle' : 'nuSessActive' ?>" title="<?= h(t($idle ? 'sess_idle' : 'sess_active')) ?>"></span>
						<span class="fw-semibold"><?= h($r['login']) ?></span>
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
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="card-footer small text-body-secondary"><?= h(t('sess_end_hint', NuSessions::TOUCH_EVERY)) ?></div>
</div>

<?php if ($db->tableExists('zzzzsys_session')): ?>
	<div class="card shadow-sm">
		<div class="card-header fw-semibold">nuBuilder <span class="badge text-bg-secondary ms-1"><?= count($nb) ?></span></div>
		<div class="table-responsive">
			<table class="table align-middle mb-0 nu-table">
				<thead><tr><th><?= h(t('user')) ?></th><th><?= h(t('sess_login_at')) ?></th><th><?= h(t('sess_last')) ?></th><th></th></tr></thead>
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
<?php endif; ?>
<?php
nu_layout_end();
