<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Sample data (globeadmin): install / refresh / remove the sample_ set and new passwords for the sample users – see core/nusample.php.
 * Passwords are shown once, right after they were created.
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
	nu_redirect(nu_url('sample'));
}

$st = NuSample::status();
$setPw = $_SESSION['nu_sample_pw_set'] ?? [];
unset($_SESSION['nu_sample_pw_set']);
$passwords = $_SESSION['nu_sample_pw'] ?? [];
unset($_SESSION['nu_sample_pw']);
$outdated = $st['installed'] && $st['version'] < $st['current'];

nu_layout_start(t('smp_title'), 'home');
?>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i><?= h(t('smp_title')) ?></h1>
	<?= nu_back_button() ?>
</div>
<p class="text-body-secondary"><?= h(t('smp_intro')) ?></p>
<p class="text-body-secondary" id="smpIntroHome"><i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i><?= h(t('smp_intro_home')) ?>
	<?php if (NuHome::canManage()): ?><a href="<?= h(nu_url('home', ['for' => 'sample_sales'])) ?>"><?= h(t('home_u_title')) ?></a><?php endif; ?></p>

<?php if ($passwords): ?>
	<div class="card border-warning shadow-sm mb-4" id="smpPasswords">
		<div class="card-body">
			<h2 class="h5"><i class="bi bi-key me-2"></i><?= h(t('smp_pw_title')) ?></h2>
			<p class="small text-body-secondary"><?= h(t('smp_pw_hint')) ?></p>
			<div class="table-responsive">
				<table class="table table-sm nu-table mb-0">
					<thead><tr><th><?= h(t('smp_login')) ?></th><th><?= h(t('smp_password')) ?></th></tr></thead>
					<tbody><?php foreach ($passwords as $login => $pw): ?><tr><td><code><?= h($login) ?></code></td><td><code class="user-select-all" data-sample-pw="<?= h($login) ?>"><?= h($pw) ?></code></td></tr><?php endforeach; ?></tbody>
				</table>
			</div>
		</div>
	</div>
<?php endif; ?>

<div class="card shadow-sm mb-4" id="smpStatus">
	<div class="card-body">
		<h2 class="h5"><i class="bi bi-cart3 me-2" aria-hidden="true"></i><?= h(t('smp_sales_title')) ?> · <?= h(t('smp_status')) ?>
			<?php if ($outdated): ?><span class="badge text-bg-warning ms-1" id="smpOutdated"><?= h(t('smp_outdated', $st['version'], $st['current'])) ?></span>
			<?php elseif ($st['installed']): ?><span class="badge text-bg-success ms-1"><?= h(t('smp_is_installed', $st['version'])) ?></span>
			<?php else: ?><span class="badge text-bg-secondary ms-1"><?= h(t('smp_not_installed')) ?></span><?php endif; ?></h2>
		<?php if ($st['installed']): ?>
			<p class="small mb-2"><?= h(t('smp_installed_on', $st['when'])) ?> ·
				<?= h(t('smp_counts', $st['counts']['users'], $st['counts']['access'], $st['counts']['forms'], $st['counts']['objects'], $st['counts']['php'], $st['counts']['formats'])) ?></p>
		<?php elseif ($st['leftovers']): ?>
			<div class="alert alert-warning small"><?= h(t('smp_leftovers')) ?></div>
		<?php endif; ?>
		<?php if ($st['blocking']): ?><div class="alert alert-warning small" id="smpBlocking"><?= h(t('smp_err_blocking', implode(', ', $st['blocking']))) ?></div><?php endif; ?>

		<div class="d-flex flex-wrap gap-2 mt-3">
			<?php if (!$st['installed']): ?>
				<form method="post" action="<?= h(nu_url('sample')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="install">
					<button class="btn btn-primary" type="submit" id="smpInstall"><i class="bi bi-download me-1"></i><?= h(t('smp_install')) ?></button></form>
			<?php else: ?>
				<a class="btn btn-primary" href="<?= h(nu_url('form', ['f' => NuSample::fid(NuSample::HOME)])) ?>" id="smpOpen"><i class="bi bi-box-arrow-in-right me-1"></i><?= h(t('smp_open')) ?></a>
				<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_refresh_confirm')) ?>" data-confirm-ok="<?= h(t('smp_refresh')) ?>" data-confirm-class="btn btn-primary"><?= nu_csrf_field() ?><input type="hidden" name="action" value="refresh">
					<button class="btn <?= $outdated ? 'btn-warning' : 'btn-outline-primary' ?>" type="submit" id="smpRefresh"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('smp_refresh')) ?></button></form>
				<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_passwords_confirm')) ?>" data-confirm-ok="<?= h(t('smp_passwords')) ?>" data-confirm-class="btn btn-primary"><?= nu_csrf_field() ?><input type="hidden" name="action" value="passwords">
					<button class="btn btn-outline-secondary" type="submit" id="smpPasswordsBtn"><i class="bi bi-key me-1"></i><?= h(t('smp_passwords')) ?></button></form>
			<?php endif; ?>
			<?php if ($st['installed'] || $st['leftovers']): ?>
				<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_remove_confirm')) ?>" data-confirm-ok="<?= h(t('smp_remove')) ?>" data-confirm-class="btn btn-danger" class="ms-auto"><?= nu_csrf_field() ?><input type="hidden" name="action" value="remove">
					<button class="btn btn-outline-danger" type="submit" id="smpRemove"<?= $st['blocking'] ? ' disabled' : '' ?>><i class="bi bi-trash me-1"></i><?= h(t('smp_remove')) ?></button></form>
			<?php endif; ?>
		</div>
	</div>
</div>

<div class="card shadow-sm mb-4" id="smpContents">
	<div class="card-body">
		<h2 class="h5"><?= h(t('smp_contents')) ?></h2>
		<div class="row g-4 small">
			<div class="col-md-6">
				<h3 class="h6"><?= h(t('smp_c_forms')) ?></h3>
				<ul class="mb-0">
					<li><code>sample_home</code> – <?= h(t('smp_f_home')) ?></li>
					<li><code>sample_customer</code> – <?= h(t('smp_f_customer')) ?></li>
					<li><code>sample_product</code> – <?= h(t('smp_f_product')) ?></li>
					<li><code>sample_order</code> + <code>sample_order_item</code> – <?= h(t('smp_f_order')) ?></li>
					<li><code>sample_all</code> + <code>sample_all_line</code> – <?= h(t('smp_f_all')) ?></li>
				</ul>
			</div>
			<div class="col-md-6">
				<h3 class="h6"><?= h(t('smp_c_users')) ?></h3>
				<ul class="mb-0">
					<li><code>sample_anna</code> → <code>sample_viewer</code> – <?= h(t('smp_u_viewer')) ?></li>
					<li><code>sample_ben</code> → <code>sample_editor</code> – <?= h(t('smp_u_editor')) ?></li>
					<li><code>sample_carla</code> → <code>sample_manager</code> – <?= h(t('smp_u_manager')) ?></li>
					<li><code>sample_dario</code> → <code>sample_sales</code> – <?= h(t('smp_u_sales')) ?></li>
					<li><code>sample_eva</code> → <code>sample_secure</code> – <?= h(t('smp_u_secure')) ?></li>
				</ul>
			</div>
			<div class="col-12">
				<h3 class="h6"><?= h(t('smp_c_more')) ?></h3>
				<p class="mb-0"><?= h(t('smp_more')) ?></p>
			</div>
		</div>
	</div>
</div>
<?php // further sample applications (0.8.679): one card each, defined in install/samples/<key>.json
foreach (NuSampleSet::keys() as $key):
	try { $ss = NuSampleSet::status($key); $def = NuSampleSet::definition($key); } catch (Throwable $e) { echo '<div class="alert alert-warning">' . h($e->getMessage()) . '</div>'; continue; }
	$sOut = $ss['installed'] && $ss['version'] < $ss['current'];
	$levels = array_values(array_filter(array_map(static fn($r) => $r[0] === 'zzzzsys_access' ? $r[1] : null, $def['rows']))); ?>
<div class="card shadow-sm mb-4" id="smpSet_<?= h($key) ?>">
	<div class="card-body">
		<h2 class="h5"><i class="bi bi-<?= h(['ticket' => 'headset'][$key] ?? 'box-seam') ?> me-2" aria-hidden="true"></i><?= h(t('smp_' . $key . '_title')) ?>
			<?php if ($sOut): ?><span class="badge text-bg-warning ms-1"><?= h(t('smp_outdated', $ss['version'], $ss['current'])) ?></span>
			<?php elseif ($ss['installed']): ?><span class="badge text-bg-success ms-1 smpSetInstalled"><?= h(t('smp_is_installed', $ss['version'])) ?></span>
			<?php else: ?><span class="badge text-bg-secondary ms-1"><?= h(t('smp_not_installed')) ?></span><?php endif; ?></h2>
		<p class="text-body-secondary"><?= h(t('smp_' . $key . '_intro')) ?></p>
		<?php if (!empty($setPw[$key])): ?>
			<div class="border border-warning rounded p-3 mb-3 smpSetPasswords">
				<h3 class="h6"><i class="bi bi-key me-2"></i><?= h(t('smp_pw_title')) ?></h3>
				<p class="small text-body-secondary"><?= h(t('smp_pw_hint')) ?></p>
				<table class="table table-sm nu-table mb-0"><thead><tr><th><?= h(t('smp_login')) ?></th><th><?= h(t('smp_password')) ?></th></tr></thead>
					<tbody><?php foreach ($setPw[$key] as $login => $pw): ?><tr><td><code><?= h($login) ?></code></td><td><code class="user-select-all" data-sample-pw="<?= h($login) ?>"><?= h($pw) ?></code></td></tr><?php endforeach; ?></tbody></table>
			</div>
		<?php endif; ?>
		<?php if ($ss['installed']): ?><p class="small mb-2"><?= h(t('smp_installed_on', $ss['when'])) ?> · <?= h(t('smp_set_counts', $ss['counts']['users'], $ss['counts']['access'], $ss['counts']['forms'], $ss['counts']['objects'], $ss['counts']['templates'], $ss['counts']['dashboards'])) ?></p>
		<?php elseif ($ss['leftovers']): ?><div class="alert alert-warning small"><?= h(t('smp_leftovers')) ?></div><?php endif; ?>
		<?php if ($ss['blocking']): ?><div class="alert alert-warning small"><?= h(t('smp_err_blocking', implode(', ', $ss['blocking']))) ?></div><?php endif; ?>
		<div class="row g-4 small mb-1">
			<div class="col-md-6">
				<h3 class="h6"><?= h(t('smp_c_users')) ?></h3>
				<ul class="mb-0"><?php foreach ($def['users'] as $u): $lv = array_values(array_filter($levels, static fn($l) => $l['zzzzsys_access_id'] === $u['sus_zzzzsys_access_id']))[0] ?? null; ?>
					<li><code><?= h((string)$u['sus_login_name']) ?></code> → <code><?= h((string)$u['sus_zzzzsys_access_id']) ?></code> – <?= h((string)$u['sus_name']) ?><?= $lv ? ' · ' . h((string)$lv['sal_description']) : '' ?></li><?php endforeach; ?></ul>
			</div>
			<div class="col-md-6">
				<h3 class="h6"><?= h(t('smp_c_more')) ?></h3>
				<p class="mb-0"><?= h(t('smp_' . $key . '_more')) ?></p>
			</div>
		</div>
		<div class="d-flex flex-wrap gap-2 mt-3">
			<?php if (!$ss['installed']): ?>
				<form method="post" action="<?= h(nu_url('sample')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="install"><input type="hidden" name="set" value="<?= h($key) ?>">
					<button class="btn btn-primary smpSetInstall" type="submit"><i class="bi bi-download me-1"></i><?= h(t('smp_install')) ?></button></form>
			<?php else: ?>
				<a class="btn btn-primary smpSetOpen" href="<?= h(nu_url('form', ['f' => (string)$def['home']])) ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?= h(t('smp_open')) ?></a>
				<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_refresh_confirm')) ?>" data-confirm-ok="<?= h(t('smp_refresh')) ?>" data-confirm-class="btn btn-primary"><?= nu_csrf_field() ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="set" value="<?= h($key) ?>">
					<button class="btn <?= $sOut ? 'btn-warning' : 'btn-outline-primary' ?> smpSetRefresh" type="submit"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('smp_refresh')) ?></button></form>
				<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_passwords_confirm')) ?>" data-confirm-ok="<?= h(t('smp_passwords')) ?>" data-confirm-class="btn btn-primary"><?= nu_csrf_field() ?><input type="hidden" name="action" value="passwords"><input type="hidden" name="set" value="<?= h($key) ?>">
					<button class="btn btn-outline-secondary smpSetPw" type="submit"><i class="bi bi-key me-1"></i><?= h(t('smp_passwords')) ?></button></form>
			<?php endif; ?>
			<?php if ($ss['installed'] || $ss['leftovers']): ?>
				<form method="post" action="<?= h(nu_url('sample')) ?>" data-confirm="<?= h(t('smp_remove_confirm')) ?>" data-confirm-ok="<?= h(t('smp_remove')) ?>" data-confirm-class="btn btn-danger" class="ms-auto"><?= nu_csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="set" value="<?= h($key) ?>">
					<button class="btn btn-outline-danger smpSetRemove" type="submit"<?= $ss['blocking'] ? ' disabled' : '' ?>><i class="bi bi-trash me-1"></i><?= h(t('smp_remove')) ?></button></form>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php endforeach;
nu_layout_end();
