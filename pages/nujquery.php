<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * ?p=jquery – jQuery of the form pages (0.8.741, core/nujquery.php): version for everyone (bundled 3.7.1 / 4.0.0 / a file of custom/jquery/),
 * jQuery Migrate (off / warnings / restore), a test for the globeadmin, the Migrate messages collected per form. Settings centre → Code.
 * POST actions: save, clear.
 */

NuAdmin::require('globalcode');
NuSecurity::requireRecentAuth(nu_url('jquery'));   // every form page of every user: password again after 15 minutes
$canChange = NuAdmin::can('globalcode', 'change');
if ($canChange && !is_dir(NuJquery::dir())) { @mkdir(NuJquery::dir(), 0775, true); }
if (is_dir(NuJquery::dir())) NuSecurity::protectDir(NuJquery::dir());

if (nu_is_post()) {
	NuAdmin::require('globalcode', 'change');
	$action = nu_post('action');
	if ($action === 'save') {
		$changed = NuJquery::save(['jq' => nu_post('jq'), 'migrate' => nu_post('migrate'), 'ga_jq' => nu_post('ga_jq'), 'ga_warn' => nu_check('ga_warn') === '1']);
		nu_flash('success', $changed ? t('saved') : t('aud_set_nochange'));
	}
	if ($action === 'clear') {
		NuJquery::clearWarnings();
		nu_flash('success', t('jq_cleared'));
	}
	nu_redirect(nu_url('jquery'));
}

$s = NuJquery::settings();
$files = NuJquery::customFiles();
$warn = NuJquery::warnings();
uasort($warn, static fn($a, $b) => strcmp($b['last'], $a['last']));
$forms = nu_forms();
$tip = static fn(string $text): string => NuListView::infoTip($text);
$opts = static function (string $cur, bool $empty) use ($files): string {
	$o = $empty ? '<option value=""' . ($cur === '' ? ' selected' : '') . '>' . h(t('jq_same')) . '</option>' : '';
	foreach (NuJquery::BUNDLED as $k => [$v]) $o .= '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . h(t('jq_bundled', $v)) . '</option>';
	foreach ($files as $f => $i) {
		if ($i['migrate'] || $i['version'] === '') continue;
		$k = 'c:' . $f;
		$o .= '<option value="' . h($k) . '"' . ($cur === $k ? ' selected' : '') . '>' . h(t('jq_own', $i['version'], $f)) . '</option>';
	}
	if (str_starts_with($cur, 'c:') && NuJquery::missing($cur)) $o .= '<option value="' . h($cur) . '" selected>' . h(t('jq_own_missing', substr($cur, 2))) . '</option>';
	return $o;
};
$cur = NuJquery::current();

nu_layout_start(t('jq_title'), 'home', ['container' => 'nuContainer', 'settings' => 'code', 'settings_page' => 'jquery']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item"><a href="<?= h(nu_url('settings')) ?>"><?= h(t('set_title')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h(t('jq_title')) ?></li>
</ol></nav>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-filetype-js me-2" aria-hidden="true"></i><?= h(t('jq_title')) ?></h1>
	<div class="nuTitleSub text-body-secondary small"><?= h(t('jq_sub')) ?></div>
	<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?></div>
</div>

<?php foreach (array_filter([$s['jq'], $s['ga_jq']], static fn($c) => NuJquery::missing($c)) as $c): ?>
	<div class="alert alert-warning" id="jqMissing"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('jq_missing_alert', substr($c, 2))) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= h(nu_url('jquery')) ?>" id="jqForm" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<div class="card shadow-sm mb-3" id="jqEveryone">
		<div class="card-header fw-semibold"><i class="bi bi-people me-2" aria-hidden="true"></i><?= h(t('jq_everyone')) ?></div>
		<div class="card-body row g-3">
			<div class="col-md-6">
				<label class="form-label" for="jqUse"><?= h(t('jq_version')) ?></label><?= $tip(t('jq_version_tip')) ?>
				<select class="form-select" name="jq" id="jqUse"<?= $canChange ? '' : ' disabled' ?>><?= $opts($s['jq'], false) ?></select>
			</div>
			<div class="col-md-6">
				<label class="form-label" for="jqMigrate"><?= h(t('jq_migrate')) ?></label><?= $tip(t('jq_migrate_tip')) ?>
				<select class="form-select" name="migrate" id="jqMigrate"<?= $canChange ? '' : ' disabled' ?>>
					<?php foreach (NuJquery::MODES as $m): ?><option value="<?= $m ?>"<?= $s['migrate'] === $m ? ' selected' : '' ?>><?= h(t('jq_mig_' . $m)) ?></option><?php endforeach; ?>
				</select>
			</div>
		</div>
	</div>
	<div class="card shadow-sm mb-3" id="jqTest">
		<div class="card-header fw-semibold"><i class="bi bi-person-gear me-2" aria-hidden="true"></i><?= h(t('jq_test')) ?><?= $tip(t('jq_test_tip')) ?></div>
		<div class="card-body row g-3 align-items-end">
			<div class="col-md-6">
				<label class="form-label" for="jqGaUse"><?= h(t('jq_version')) ?></label>
				<select class="form-select" name="ga_jq" id="jqGaUse"<?= $canChange ? '' : ' disabled' ?>><?= $opts($s['ga_jq'], true) ?></select>
			</div>
			<div class="col-md-6">
				<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="ga_warn" value="1" id="jqGaWarn"<?= $s['ga_warn'] ? ' checked' : '' ?><?= $canChange ? '' : ' disabled' ?>>
					<label class="form-check-label" for="jqGaWarn"><?= h(t('jq_ga_warn')) ?></label></div>
			</div>
		</div>
	</div>
	<?php if ($canChange): ?><div class="mb-4"><button class="btn btn-primary nuBtnSave" type="submit" data-nu-save id="jqSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button></div><?php endif; ?>
</form>

<div class="card shadow-sm mb-3" id="jqNow">
	<div class="card-body small">
		<i class="bi bi-info-circle me-1"></i><?= h(t('jq_now', $cur['jq']['version'], t('jq_mig_' . $cur['migrate']))) ?><?php if ($cur['test']): ?> <span class="badge text-bg-warning" id="jqTestBadge"><?= h(t('jq_test_on')) ?></span><?php endif; ?>
		· <?= h(t('jq_migrate_versions', NuJquery::MIGRATE[3][0], NuJquery::MIGRATE[4][0])) ?>
	</div>
</div>

<div class="card shadow-sm mb-3" id="jqFiles">
	<div class="card-header fw-semibold"><i class="bi bi-folder me-2" aria-hidden="true"></i><?= h(t('jq_files')) ?><?= $tip(t('jq_files_tip')) ?></div>
	<div class="card-body">
		<p class="small mb-2"><?= h(t('jq_folder')) ?> <code>custom/jquery/</code></p>
		<?php if (!$files): ?><p class="small text-body-secondary mb-0" id="jqNoFiles"><?= h(t('jq_no_files')) ?></p>
		<?php else: ?>
		<table class="table table-sm nu-table mb-0"><thead><tr><th><?= h(t('jq_file')) ?></th><th><?= h(t('jq_version')) ?></th><th class="text-end"><?= h(t('jq_size')) ?></th></tr></thead><tbody>
			<?php foreach ($files as $f => $i): ?><tr data-file="<?= h($f) ?>"><td><code><?= h($f) ?></code></td><td><?= $i['migrate'] ? h(t('jq_is_migrate')) : ($i['version'] !== '' ? h($i['version']) : '<span class="text-danger">' . h(t('jq_no_version')) . '</span>') ?></td><td class="text-end"><?= number_format($i['size'] / 1024, 0) ?> KB</td></tr><?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>
	</div>
</div>

<div class="card shadow-sm mb-3" id="jqWarnings">
	<div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-exclamation-diamond" aria-hidden="true"></i><span><?= h(t('jq_warnings')) ?></span><?= $tip(t('jq_warnings_tip')) ?>
		<span class="badge text-bg-secondary"><?= count($warn) ?></span>
		<a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= h(nu_url('compat')) ?>#compat_findings" id="jqScanLink"><i class="bi bi-search me-1"></i><?= h(t('jq_scan_link')) ?></a>
		<?php if ($warn && $canChange): ?>
		<form method="post" action="<?= h(nu_url('jquery')) ?>" class="m-0" data-confirm="<?= h(t('jq_clear_confirm')) ?>" data-confirm-ok="<?= h(t('jq_clear')) ?>" data-confirm-class="btn btn-danger">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-sm btn-outline-danger" type="submit" id="jqClear"><i class="bi bi-trash me-1"></i><?= h(t('jq_clear')) ?></button></form>
		<?php endif; ?>
	</div>
	<?php if (!$warn): ?><div class="card-body small text-body-secondary" id="jqNoWarnings"><?= h(t('jq_no_warnings')) ?></div>
	<?php else: ?>
	<div class="table-responsive"><table class="table table-sm nu-table mb-0 align-middle"><thead><tr><th><?= h(t('jq_form')) ?></th><th><?= h(t('jq_message')) ?></th><th class="text-end"><?= h(t('jq_count')) ?></th><th>jQuery</th><th><?= h(t('jq_last')) ?></th></tr></thead><tbody>
		<?php foreach ($warn as $w): $fid = (string)$w['f']; ?>
		<tr class="jqWarnRow"><td class="text-nowrap"><?= isset($forms[$fid]) ? '<a href="' . h(nu_url('form', ['f' => $fid])) . '" target="_blank" rel="noopener">' . h($forms[$fid]['code']) . '</a>' : h($fid) ?></td>
			<td class="small"><code class="text-break"><?= h($w['m']) ?></code></td><td class="text-end"><?= (int)$w['n'] ?></td><td><?= h($w['jq']) ?></td><td class="small text-nowrap"><?= h(substr((string)$w['last'], 0, 16)) ?></td></tr>
		<?php endforeach; ?>
	</tbody></table></div>
	<?php endif; ?>
</div>
<?php nu_layout_end(); ?>
