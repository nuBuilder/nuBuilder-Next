<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Sample data (globeadmin): install / refresh / remove the sample_ set and new passwords for the sample users – see core/nusample.php.
 * Passwords are shown once, right after they were created.
 * Layout (0.8.742): list of the samples on the left (index – a new sample in NuSampleSet::SETS appears there), the selected one on the
 * right with tabs Overview / Contents / Logins / Maintenance; ?s=<key> (or #smpSet_<key>) selects, assets/js/nusample.js switches
 * without a reload. Passwords just created: tab Logins opens, masked until "Show passwords".
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('sample');

if (nu_is_post()) {
	$action = nu_post('action');
	$set = nu_post('set');
	try {
		if ($set !== '' && in_array($set, NuSampleSet::keys(), true)) {     // further sample applications (0.8.679, core/nusampleset.php)
			if ($action === 'install' || $action === 'refresh') {
				$_SESSION['nu_sample_pw_set'] = [$set => NuSampleSet::install($set)];
				nu_flash('success', t($action === 'refresh' ? 'smp_refreshed' : 'smp_installed'));
			} elseif ($action === 'remove') {
				NuSampleSet::remove($set);
				nu_flash('success', t('smp_removed'));
			} elseif ($action === 'passwords') {
				$_SESSION['nu_sample_pw_set'] = [$set => NuSampleSet::newPasswords($set)];
				NuLog::write('sample_passwords', 'sample', $set);
				nu_flash('success', t('smp_passwords_done'));
			}
			nu_redirect(nu_url('sample') . '#smpSet_' . $set);
		}
		if ($action === 'install' || $action === 'refresh') {
			$_SESSION['nu_sample_pw'] = NuSample::install();
			nu_flash('success', t($action === 'refresh' ? 'smp_refreshed' : 'smp_installed'));
		} elseif ($action === 'remove') {
			NuSample::remove();
			nu_flash('success', t('smp_removed'));
		} elseif ($action === 'passwords') {
			$_SESSION['nu_sample_pw'] = NuSample::newPasswords();
			NuLog::write('sample_passwords', 'sample');
			nu_flash('success', t('smp_passwords_done'));
		}
	} catch (Throwable $e) {
		error_log('nuBuilder Next sample data: ' . $e->getMessage());
		nu_flash('danger', t('smp_failed') . ' ' . $e->getMessage());
	}
	nu_redirect(nu_url('sample', ['s' => 'sales']));
}

// one entry per sample: the built-in sales sample (core/nusample.php) and the sets of core/nusampleset.php
$samples = [];
$st = NuSample::status();
$pw = $_SESSION['nu_sample_pw'] ?? [];
unset($_SESSION['nu_sample_pw']);
$setPw = $_SESSION['nu_sample_pw_set'] ?? [];
unset($_SESSION['nu_sample_pw_set']);
$salesUsers = [['sample_anna', 'sample_viewer', t('smp_u_viewer')], ['sample_ben', 'sample_editor', t('smp_u_editor')], ['sample_carla', 'sample_manager', t('smp_u_manager')],
	['sample_dario', 'sample_sales', t('smp_u_sales')], ['sample_eva', 'sample_secure', t('smp_u_secure')]];
$samples['sales'] = ['title' => t('smp_sales_title'), 'icon' => 'cart3', 'intro' => t('smp_intro'), 'st' => $st, 'pw' => $pw, 'users' => $salesUsers,
	'counts' => $st['installed'] ? t('smp_counts', $st['counts']['users'], $st['counts']['access'], $st['counts']['forms'], $st['counts']['objects'], $st['counts']['php'], $st['counts']['formats']) : '',
	'open' => nu_url('form', ['f' => NuSample::fid(NuSample::HOME)]), 'more' => t('smp_more'), 'set' => ''];
$errors = [];
foreach (NuSampleSet::keys() as $key) {
	try { $ss = NuSampleSet::status($key); $def = NuSampleSet::definition($key); } catch (Throwable $e) { $errors[] = $e->getMessage(); continue; }
	$levels = [];
	foreach ($def['rows'] as $r) if ($r[0] === 'zzzzsys_access') $levels[(string)$r[1]['zzzzsys_access_id']] = (string)$r[1]['sal_description'];
	$users = [];
	foreach ($def['users'] as $u) $users[] = [(string)$u['sus_login_name'], (string)$u['sus_zzzzsys_access_id'], trim((string)$u['sus_name'] . (isset($levels[$u['sus_zzzzsys_access_id']]) ? ' · ' . $levels[$u['sus_zzzzsys_access_id']] : ''))];
	$samples[$key] = ['title' => t('smp_' . $key . '_title'), 'icon' => ['ticket' => 'headset'][$key] ?? 'box-seam', 'intro' => t('smp_' . $key . '_intro'), 'st' => $ss,
		'pw' => $setPw[$key] ?? [], 'users' => $users, 'open' => nu_url('form', ['f' => (string)$def['home']]), 'more' => t('smp_' . $key . '_more'), 'set' => $key,
		'counts' => $ss['installed'] ? t('smp_set_counts', $ss['counts']['users'], $ss['counts']['access'], $ss['counts']['forms'], $ss['counts']['objects'], $ss['counts']['templates'], $ss['counts']['dashboards']) : ''];
}
$sel = is_string($_GET['s'] ?? null) && isset($samples[$_GET['s']]) ? (string)$_GET['s'] : (string)array_key_first($samples);
foreach ($samples as $k => $x) if ($x['pw']) $sel = $k;                       // passwords just created: that sample
$badge = static function (array $st, string $extra = ''): string {
	$out = $st['installed'] && $st['version'] < $st['current'];
	if ($out) return '<span class="badge text-bg-warning' . $extra . '">' . h(t('smp_outdated', $st['version'], $st['current'])) . '</span>';
	if ($st['installed']) return '<span class="badge text-bg-success' . $extra . '">' . h(t('smp_is_installed', $st['version'])) . '</span>';
	return '<span class="badge text-bg-secondary' . $extra . '">' . h(t('smp_not_installed')) . '</span>';
};
$hidden = static fn(string $key): string => $key !== '' ? '<input type="hidden" name="set" value="' . h($key) . '">' : '';

nu_layout_start(t('smp_title'), 'home');
?>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i><?= h(t('smp_title')) ?></h1>
	<?= nu_back_button() ?>
</div>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<div class="row g-3 align-items-start" id="smpPage">
	<div class="col-lg-3">
		<div class="list-group shadow-sm" id="smpList" role="tablist" aria-label="<?= h(t('smp_samples')) ?>">
		<?php foreach ($samples as $key => $x): $s = $x['st']; $out = $s['installed'] && $s['version'] < $s['current']; ?>
			<a class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-3<?= $key === $sel ? ' active' : '' ?>" href="<?= h(nu_url('sample', ['s' => $key])) ?>"
				data-smp="<?= h($key) ?>" role="tab" aria-selected="<?= $key === $sel ? 'true' : 'false' ?>" aria-controls="smpSet_<?= h($key) ?>">
				<span class="smpDot <?= $out ? 'smpDotOut' : ($s['installed'] ? 'smpDotOn' : 'smpDotOff') ?>" aria-hidden="true"></span>
				<span class="d-flex flex-column"><span class="fw-semibold"><i class="bi bi-<?= h($x['icon']) ?> me-1" aria-hidden="true"></i><?= h($x['title']) ?></span>
					<span class="small smpListSub"><?= h($out ? t('smp_list_out', $s['version'], $s['current']) : ($s['installed'] ? t('smp_list_on', $s['version']) : t('smp_not_installed'))) ?></span></span>
			</a>
		<?php endforeach; ?>
		</div>
	</div>
	<div class="col-lg-9">
	<?php foreach ($samples as $key => $x): $s = $x['st']; $set = $x['set']; $out = $s['installed'] && $s['version'] < $s['current'];
		// ids of the sales sample (smpInstall …), classes of the sets (.smpSetInstall …) – as before
		$tab = $x['pw'] ? 'logins' : 'overview'; $tid = 'smp_' . $key . '_'; ?>
		<div class="card shadow-sm smpPane" id="smpSet_<?= h($key) ?>" data-smp="<?= h($key) ?>" role="tabpanel"<?= $key === $sel ? '' : ' hidden' ?>>
			<div class="card-body">
				<div class="d-flex flex-wrap align-items-center gap-2 mb-3"<?= $set === '' ? ' id="smpStatus"' : '' ?>>
					<div class="me-auto">
						<h2 class="h5 mb-1"><i class="bi bi-<?= h($x['icon']) ?> me-2" aria-hidden="true"></i><?= h($x['title']) ?>
							<?= $set === '' && $out ? str_replace('<span class="badge', '<span id="smpOutdated" class="badge', $badge($s, ' ms-1')) : $badge($s, ' ms-1' . ($s['installed'] && !$out && $set !== '' ? ' smpSetInstalled' : '')) ?></h2>
						<?php if ($s['installed']): ?><div class="small text-body-secondary"><?= h(t('smp_installed_on', $s['when'])) ?></div><?php endif; ?>
					</div>
					<div class="d-flex flex-wrap gap-2">
					<?php if (!$s['installed']): ?>
						<form method="post" action="<?= h(nu_url('sample')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="install"><?= $hidden($set) ?>
							<button class="btn btn-primary<?= $set !== '' ? ' smpSetInstall' : '' ?>" type="submit"<?= $set === '' ? ' id="smpInstall"' : '' ?>><i class="bi bi-download me-1"></i><?= h(t('smp_install')) ?></button></form>
					<?php else: ?>
						<a class="btn btn-primary<?= $set !== '' ? ' smpSetOpen' : '' ?>" href="<?= h($x['open']) ?>"<?= $set === '' ? ' id="smpOpen"' : '' ?>><i class="bi bi-box-arrow-in-right me-1"></i><?= h(t('smp_open')) ?></a>
						<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_refresh_confirm')) ?>" data-confirm-ok="<?= h(t('smp_refresh')) ?>" data-confirm-class="btn btn-primary"><?= nu_csrf_field() ?><input type="hidden" name="action" value="refresh"><?= $hidden($set) ?>
							<button class="btn <?= $out ? 'btn-warning' : 'btn-outline-primary' ?><?= $set !== '' ? ' smpSetRefresh' : '' ?>" type="submit"<?= $set === '' ? ' id="smpRefresh"' : '' ?>><i class="bi bi-arrow-repeat me-1"></i><?= h(t('smp_refresh')) ?></button></form>
					<?php endif; ?>
					</div>
				</div>
				<?php if ($s['leftovers'] && !$s['installed']): ?><div class="alert alert-warning small"><?= h(t('smp_leftovers')) ?></div><?php endif; ?>
				<ul class="nav nav-tabs mb-3" role="tablist">
				<?php foreach (['overview', 'contents', 'logins', 'maint'] as $tb): ?>
					<li class="nav-item" role="presentation"><button class="nav-link<?= $tb === $tab ? ' active' : '' ?>" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#<?= $tid . $tb ?>"
						id="<?= $tid . $tb ?>_tab" aria-controls="<?= $tid . $tb ?>" aria-selected="<?= $tb === $tab ? 'true' : 'false' ?>"><?= h(t('smp_tab_' . $tb)) ?><?php if ($tb === 'logins'): ?> <span class="badge rounded-pill text-bg-light border"><?= count($x['users']) ?></span><?php endif; ?></button></li>
				<?php endforeach; ?>
				</ul>
				<div class="tab-content">
					<div class="tab-pane fade<?= $tab === 'overview' ? ' show active' : '' ?>" id="<?= $tid ?>overview" role="tabpanel" aria-labelledby="<?= $tid ?>overview_tab" tabindex="0">
						<p class="mb-2"><?= h($x['intro']) ?></p>
						<?php if ($x['counts'] !== ''): ?><p class="small text-body-secondary mb-0"><?= h($x['counts']) ?></p><?php endif; ?>
					</div>
					<div class="tab-pane fade" id="<?= $tid ?>contents" role="tabpanel" aria-labelledby="<?= $tid ?>contents_tab" tabindex="0">
						<div class="row g-4 small"<?= $set === '' ? ' id="smpContents"' : '' ?>>
						<?php if ($set === ''): ?>
							<div class="col-md-6"><h3 class="h6"><?= h(t('smp_c_forms')) ?></h3><ul class="mb-0">
								<li><code>sample_home</code> – <?= h(t('smp_f_home')) ?></li>
								<li><code>sample_customer</code> – <?= h(t('smp_f_customer')) ?></li>
								<li><code>sample_product</code> – <?= h(t('smp_f_product')) ?></li>
								<li><code>sample_order</code> + <code>sample_order_item</code> – <?= h(t('smp_f_order')) ?></li>
								<li><code>sample_all</code> + <code>sample_all_line</code> – <?= h(t('smp_f_all')) ?></li></ul></div>
							<div class="col-md-6"><h3 class="h6"><?= h(t('smp_c_start')) ?></h3>
								<p class="mb-0" id="smpIntroHome"><?= h(t('smp_intro_home')) ?>
								<?php if (NuHome::canManage()): ?><a href="<?= h(nu_url('home', ['for' => 'sample_sales'])) ?>"><?= h(t('home_u_title')) ?></a><?php endif; ?></p></div>
						<?php endif; ?>
							<div class="col-12"><h3 class="h6"><?= h(t('smp_c_more')) ?></h3><p class="mb-0"><?= h($x['more']) ?></p></div>
						</div>
					</div>
					<div class="tab-pane fade<?= $tab === 'logins' ? ' show active' : '' ?>" id="<?= $tid ?>logins" role="tabpanel" aria-labelledby="<?= $tid ?>logins_tab" tabindex="0">
						<?php if ($x['pw']): ?>
							<div class="d-flex flex-wrap align-items-center gap-2 mb-2<?= $set !== '' ? ' smpSetPasswords' : '' ?>"<?= $set === '' ? ' id="smpPasswords"' : '' ?>>
								<span class="small text-body-secondary me-auto"><i class="bi bi-key me-1" aria-hidden="true"></i><?= h(t('smp_pw_hint')) ?></span>
								<button class="btn btn-sm btn-outline-secondary smpPwToggle" type="button" data-show="<?= h(t('smp_pw_show')) ?>" data-hide="<?= h(t('smp_pw_hide')) ?>"><i class="bi bi-eye me-1" aria-hidden="true"></i><span><?= h(t('smp_pw_show')) ?></span></button>
							</div>
						<?php else: ?><p class="small text-body-secondary mb-2"><?= h(t('smp_pw_none')) ?></p><?php endif; ?>
						<div class="table-responsive"><table class="table table-sm nu-table mb-0 align-middle smpLogins smpPwMasked">
							<thead><tr><th><?= h(t('smp_login')) ?></th><th><?= h(t('smp_level')) ?></th><th><?= h(t('smp_user_desc')) ?></th><?php if ($x['pw']): ?><th><?= h(t('smp_password')) ?></th><?php endif; ?></tr></thead>
							<tbody><?php foreach ($x['users'] as [$login, $level, $desc]): ?><tr>
								<td><code><?= h($login) ?></code></td><td><code><?= h($level) ?></code></td><td class="small"><?= h($desc) ?></td>
								<?php if ($x['pw']): ?><td class="text-nowrap"><?php if (isset($x['pw'][$login])): ?><span class="smpPwDots" aria-hidden="true">••••••••</span><code class="user-select-all smpPwVal" data-sample-pw="<?= h($login) ?>"><?= h((string)$x['pw'][$login]) ?></code>
									<button class="btn btn-sm btn-link p-0 ms-2 smpPwCopy" type="button" data-copied="<?= h(t('smp_pw_copied')) ?>" aria-label="<?= h(t('smp_pw_copy') . ' ' . $login) ?>"><i class="bi bi-clipboard" aria-hidden="true"></i></button><?php endif; ?></td><?php endif; ?>
							</tr><?php endforeach; ?></tbody></table></div>
					</div>
					<div class="tab-pane fade" id="<?= $tid ?>maint" role="tabpanel" aria-labelledby="<?= $tid ?>maint_tab" tabindex="0">
						<?php if ($s['blocking']): ?><div class="alert alert-warning small"<?= $set === '' ? ' id="smpBlocking"' : '' ?>><?= h(t('smp_err_blocking', implode(', ', $s['blocking']))) ?></div><?php endif; ?>
						<?php if ($s['installed']): ?>
						<div class="d-flex flex-wrap align-items-center gap-3 py-2 border-bottom">
							<span class="small flex-grow-1"><?= h(t('smp_maint_pw')) ?></span>
							<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_passwords_confirm')) ?>" data-confirm-ok="<?= h(t('smp_passwords')) ?>" data-confirm-class="btn btn-primary"><?= nu_csrf_field() ?><input type="hidden" name="action" value="passwords"><?= $hidden($set) ?>
								<button class="btn btn-outline-secondary<?= $set !== '' ? ' smpSetPw' : '' ?>" type="submit"<?= $set === '' ? ' id="smpPasswordsBtn"' : '' ?>><i class="bi bi-key me-1"></i><?= h(t('smp_passwords')) ?></button></form>
						</div>
						<?php endif; ?>
						<?php if ($s['installed'] || $s['leftovers']): ?>
						<div class="d-flex flex-wrap align-items-center gap-3 py-2">
							<span class="small flex-grow-1"><?= h(t('smp_maint_remove')) ?></span>
							<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_remove_confirm')) ?>" data-confirm-ok="<?= h(t('smp_remove')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="remove"><?= $hidden($set) ?>
								<button class="btn btn-outline-danger<?= $set !== '' ? ' smpSetRemove' : '' ?>" type="submit"<?= $set === '' ? ' id="smpRemove"' : '' ?><?= $s['blocking'] ? ' disabled' : '' ?>><i class="bi bi-trash me-1"></i><?= h(t('smp_remove')) ?></button></form>
						</div>
						<?php else: ?><p class="small text-body-secondary mb-0"><?= h(t('smp_not_installed')) ?></p><?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
	</div>
</div>
<?php nu_layout_end('<script src="' . nu_asset('assets/js/nusample.js') . '"></script>');
