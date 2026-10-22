<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* Compatibility check (globeadmin): nu… functions used by the application's JavaScript and PHP, and nuBuilder Next's support. */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('compat', 'view');            // the scan (POST) only reads

$withSystem = ($_GET['sys'] ?? '') === '1';
$all        = ($_GET['all'] ?? '') === '1';
$res        = NuCompat::scan($withSystem);
$nb         = ['js' => NuCompat::nuBuilderFunctions('js'), 'php' => NuCompat::nuBuilderFunctions('php')];
$forms      = nu_forms();

$badge = static function (string $status, bool $known = true): string {
	return match ($status) {
		'ok'      => '<span class="badge text-bg-success">' . h(t('compat_ok')) . '</span>',
		'stub'    => '<span class="badge text-bg-warning">' . h(t('compat_stub')) . '</span>',
		'custom'  => '<span class="badge bg-secondary-subtle text-secondary-emphasis">' . h(t('compat_custom')) . '</span>',
		default   => '<span class="badge text-bg-danger">' . h(t($known ? 'compat_missing' : 'compat_unknown')) . '</span>',
	};
};

nu_layout_start(t('compat_title'), 'home');
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h(t('compat_title')) ?></li>
</ol></nav>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><?= h(t('compat_title')) ?></h1>
	<div class="nuTitleSub text-body-secondary small"><?= h(t('compat_hint')) ?></div>
	<div class="d-flex flex-wrap gap-3 align-items-center"><?= nu_back_button() ?>
	<form method="get" action="index.php" class="d-flex flex-wrap gap-3 align-items-center">
		<input type="hidden" name="p" value="compat">
		<div class="form-check form-switch mb-0"><input class="form-check-input js-autosubmit" type="checkbox" role="switch" id="c_all" name="all" value="1"<?= $all ? ' checked' : '' ?>><label class="form-check-label small" for="c_all"><?= h(t('compat_show_all')) ?></label></div>
		<div class="form-check form-switch mb-0"><input class="form-check-input js-autosubmit" type="checkbox" role="switch" id="c_sys" name="sys" value="1"<?= $withSystem ? ' checked' : '' ?>><label class="form-check-label small" for="c_sys"><?= h(t('compat_system')) ?></label></div>
	</form></div>
</div>

<?php foreach (['js' => 'JavaScript', 'php' => 'PHP'] as $lang => $title):
	$list = $res[$lang];
	$count = static fn(string $st) => count(array_filter($list, static fn($r) => $r['status'] === $st));
	$calls = array_sum(array_column($list, 'count'));
	?>
	<div class="card shadow-sm mb-4" id="compat_<?= $lang ?>">
		<div class="card-header d-flex flex-wrap gap-2 align-items-center">
			<span class="fw-semibold me-auto"><?= h($title) ?></span>
			<span class="small text-body-secondary"><?= h(t('compat_summary', count($list), $calls)) ?></span>
			<span class="badge text-bg-danger"><?= $count('missing') ?> <?= h(t('compat_missing')) ?></span>
			<span class="badge text-bg-warning"><?= $count('stub') ?> <?= h(t('compat_stub')) ?></span>
			<span class="badge bg-secondary-subtle text-secondary-emphasis"><?= $count('custom') ?> <?= h(t('compat_custom')) ?></span>
			<span class="badge text-bg-success"><?= $count('ok') ?> <?= h(t('compat_ok')) ?></span>
		</div>
		<div class="table-responsive">
			<table class="table table-sm align-middle mb-0 nu-table">
				<thead><tr><th><?= h(t('compat_function')) ?></th><th><?= h(t('compat_status')) ?></th><th class="text-end"><?= h(t('compat_calls')) ?></th><th><?= h(t('compat_where')) ?></th></tr></thead>
				<tbody>
				<?php $shown = 0; foreach ($list as $name => $r):
					if (!$all && in_array($r['status'], ['ok', 'custom'], true)) continue;
					$shown++; ?>
					<tr class="compat-<?= h($r['status']) ?>">
						<td><code><?= h($name) ?></code></td>
						<td><?= $badge($r['status'], !$nb[$lang] || isset($nb[$lang][$lang === 'php' ? strtolower($name) : $name])) ?></td>
						<td class="text-end"><?= (int)$r['count'] ?></td>
						<td class="small">
							<?php
							$links = [];
							foreach (array_slice($r['forms'], 0, 6) as $fid) {
								$label = $forms[$fid]['code'] ?? $fid;
								$links[] = isset($forms[$fid]) && !str_starts_with($fid, 'nu') ? '<a href="' . h(nu_url('builder', ['f' => $fid])) . '">' . h($label) . '</a>' : h($label);
							}
							echo implode(', ', $links);
							?>
							<details class="d-inline ms-1"><summary class="text-body-secondary"><?= count($r['where']) ?> <?= h(t('compat_places')) ?></summary>
								<ul class="mb-0 ps-3"><?php foreach (array_slice($r['where'], 0, 40) as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></details>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if (!$shown): ?><tr><td colspan="4" class="text-center text-success py-4"><i class="bi bi-check-circle me-1"></i><?= h(t('compat_all_good')) ?></td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endforeach; ?>
<?php $fnd = NuCompat::findings($withSystem); // nuBuilder 4.9 features of the compatibility report (0.8.382) ?>
<div class="card shadow-sm mb-4" id="compat_findings">
	<div class="card-header d-flex flex-wrap gap-2 align-items-center">
		<span class="fw-semibold me-auto"><i class="bi bi-list-check me-1"></i><?= h(t('compat_f_title')) ?><?= NuListView::infoTip(t('compat_f_tip')) ?></span>
		<?php $open = count(array_filter(array_keys($fnd), static fn($id) => (NuCompat::SINCE[$id] ?? null) === null)); ?>
		<span class="badge <?= $open ? 'text-bg-danger' : 'text-bg-success' ?>"><?= h(t('compat_f_open', $open)) ?></span>
	</div>
	<div class="table-responsive">
		<table class="table table-sm align-middle mb-0 nu-table">
			<thead><tr><th>ID</th><th><?= h(t('compat_f_feature')) ?></th><th><?= h(t('compat_status')) ?></th><th><?= h(t('compat_where')) ?></th></tr></thead>
			<tbody>
			<?php foreach ($fnd as $id => $places): $since = NuCompat::SINCE[$id] ?? null; ?>
				<tr class="compat-<?= $since ? 'ok' : 'missing' ?>" data-finding="<?= h($id) ?>">
					<td><code><?= h($id) ?></code></td>
					<td class="small"><?= h(t('compat_f_' . strtolower($id))) ?></td>
					<td><?= $since ? '<span class="badge text-bg-success">' . h(t('compat_f_since', $since)) . '</span>' : '<span class="badge text-bg-danger">' . h(t('compat_f_not_yet')) . '</span>' ?></td>
					<td class="small">
						<?php $links = [];
						foreach ($places as $pl) { $fid = $pl['form']; if ($fid !== '' && isset($forms[$fid]) && !in_array($fid, $links, true)) $links[$fid] = !str_starts_with($fid, 'nu') ? '<a href="' . h(nu_url('builder', ['f' => $fid])) . '">' . h($forms[$fid]['code']) . '</a>' : h($forms[$fid]['code']); }
						echo implode(', ', array_slice($links, 0, 6)); ?>
						<details class="d-inline ms-1"><summary class="text-body-secondary"><?= count($places) ?> <?= h(t('compat_places')) ?></summary>
							<ul class="mb-0 ps-3"><?php foreach (array_slice($places, 0, 60) as $pl): ?><li><?= h($pl['where']) ?><?php if ($pl['detail'] !== ''): ?> – <code class="text-break"><?= h($pl['detail']) ?></code><?php endif; ?></li><?php endforeach; ?></ul></details>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if (!$fnd): ?><tr><td colspan="4" class="text-center text-success py-4"><i class="bi bi-check-circle me-1"></i><?= h(t('compat_f_none')) ?></td></tr><?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
<?php $unq = NuCompat::unquotedHash($withSystem); // hash variables outside quotes (0.8.207) ?>
<div class="card shadow-sm mb-4" id="compat_hash">
	<div class="card-header d-flex flex-wrap gap-2 align-items-center">
		<span class="fw-semibold me-auto"><i class="bi bi-shield-exclamation me-1"></i><?= h(t('compat_hash_title')) ?>
			<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('compat_hash_tip')) ?>" title="<?= h(t('compat_hash_tip')) ?>"><i class="bi bi-info-circle"></i></span></span>
		<span class="badge <?= $unq ? 'text-bg-warning' : 'text-bg-success' ?>"><?= count($unq) ?></span>
	</div>
	<div class="table-responsive">
		<table class="table table-sm align-middle mb-0 nu-table">
			<thead><tr><th><?= h(t('compat_where')) ?></th><th><?= h(t('compat_hash_lang')) ?></th><th><?= h(t('compat_hash_vars')) ?></th></tr></thead>
			<tbody>
			<?php foreach ($unq as $u): $fid = $u['form']; ?>
				<tr>
					<td class="small"><?php if ($fid !== '' && isset($forms[$fid]) && !str_starts_with($fid, 'nu')): ?><a href="<?= h(nu_url('builder', ['f' => $fid])) ?>"><?= h($u['where']) ?></a><?php else: ?><?= h($u['where']) ?><?php endif; ?></td>
					<td><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h(strtoupper($u['lang'])) ?></span></td>
					<td><?php foreach ($u['vars'] as $vn): ?><code class="me-2">#<?= h($vn) ?>#</code><?php endforeach; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if (!$unq): ?><tr><td colspan="3" class="text-center text-success py-4"><i class="bi bi-check-circle me-1"></i><?= h(t('compat_hash_none')) ?></td></tr><?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
<p class="small text-body-secondary"><?= h(t('compat_legend')) ?></p>
<?php
nu_layout_end();
