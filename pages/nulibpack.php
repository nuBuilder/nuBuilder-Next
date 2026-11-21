<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Library packs – build and sign (core/nulibs.php): developer mode only (globeadmin + $nuConfigDevMode), password again,
 * HTTPS (or this computer). The signing key is uploaded with each request, used in memory and never written, logged or sent;
 * it must be the key of nuBuilder Next's packages (NuUpdate::SIGN_PUBLIC). Result: one zip per library + the signed index
 * libraries.json in _update/libpack/<id>/ – download them (one by one or as one zip) and attach them to the GitHub release with
 * the tag "libraries". Only the newest build is kept.
 *   ?p=libpack · POST action=build · ?p=libpack&dl=<id>&f=<file> · POST action=discard
 */
if (!NuHash::devMode() || empty(NuAuth::user()['globeadmin'])) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}
NuSecurity::requireRecentAuth(nu_url('libpack'));

$local = in_array(nu_client_ip(), ['127.0.0.1', '::1'], true);
$secure = nu_https() || $local;
$outBase = NuUpdate::folderDir() . '/libpack';
$buildDir = static fn(string $id): string => preg_match('/^[a-f0-9]{12}$/', $id) ? $outBase . '/' . $id : '';

// ------------------------------------------------------------------ download
if (isset($_GET['dl'])) {
	$d = $buildDir((string)$_GET['dl']);
	$f = (string)($_GET['f'] ?? '');
	$path = $d !== '' && (NuLibs::isPackName($f) || $f === NuLibs::INDEX || preg_match('/^nunext-libs-[0-9]{8}-[0-9]{4}\.zip$/', $f)) ? $d . '/' . $f : '';
	if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
	session_write_close();
	while (ob_get_level()) ob_end_clean();
	header('Content-Type: ' . (str_ends_with($f, '.json') ? 'application/json' : 'application/zip'));
	header('Content-Disposition: attachment; filename="' . $f . '"');
	header('Content-Length: ' . filesize($path));
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;
}

// ------------------------------------------------------------------ build
['secure' => $secure, 'src' => $src]
	= NuLibpackPage::post(get_defined_vars());   // the POST actions (code review R7, nulibpackpage.php)

$last = $_SESSION[NuSess::LIBPACK] ?? null;
if ($last && !is_dir($buildDir((string)$last['id']))) $last = null;
$gh = NuLibs::ghState();
nu_layout_start(t('lp_build_title'), 'update');
?>
<div class="card shadow-sm nuListCard" id="lpBuild">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('lp_build_title')) ?><?= NuListView::infoTip(t('lp_build_hint')) ?></h1>
		<div class="nuTitleSub"><span class="badge text-bg-secondary">Dev</span></div>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button(null, nu_url('update') . '#libraries') ?></div>
	</div>
	<div class="card-body">
		<?php if (!$secure): ?><div class="alert alert-danger" id="lpInsecure"><i class="bi bi-shield-exclamation me-1"></i><?= h(t('lp_err_https')) ?></div><?php endif; ?>
		<?php if (($lpOb = NuLibs::libsrcBlocked()) !== '' && !is_dir(NU_ROOT . '/' . NuLibs::LIBSRC_IN)): // open_basedir hides ../libsrc/ – then _libsrc/ ?>
		<div class="alert alert-warning small" id="lpLibsrcBlocked"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('lp_src_blocked', '../' . NuLibs::LIBSRC, $lpOb)) ?></div>
		<?php endif; ?>
		<?php if ($last): ?>
		<div class="alert alert-success" id="lpResult">
			<div class="fw-semibold mb-2"><i class="bi bi-check-circle me-1"></i><?= h(t('lp_result')) ?></div>
			<ul class="mb-2 small">
				<?php foreach ($last['packs'] as $p): ?>
					<li><a href="<?= h(nu_url('libpack', ['dl' => $last['id'], 'f' => $p['file']])) ?>"
						class="nuLpFile"><?= h($p['file']) ?></a> · <?= h(t('lp_result_line', number_format($p['files']), number_format($p['size'] / 1048576, 1))) ?></li>
				<?php endforeach; ?>
				<li><a href="<?= h(nu_url('libpack', ['dl' => $last['id'], 'f' => NuLibs::INDEX])) ?>"
					id="lpIndexLink"><?= h(NuLibs::INDEX) ?></a><?= $last['kept'] ? ' · ' . h(t('lp_result_kept', implode(', ', $last['kept']))) : '' ?></li>
			</ul>
			<div class="d-flex flex-wrap gap-2 align-items-center">
				<a class="btn btn-sm btn-success" href="<?= h(nu_url('libpack', ['dl' => $last['id'], 'f' => $last['bundle']])) ?>"
					id="lpBundle"><i class="bi bi-file-zip me-1"></i><?= h(t('lp_download_all')) ?></a>
				<form method="post" class="m-0"><?= nu_csrf_field() ?><input type="hidden" name="action"
					value="discard"><button class="btn btn-sm btn-outline-secondary"
					type="submit"><i class="bi bi-trash me-1"></i><?= h(t('lp_discard')) ?></button></form>
				<span class="small"><?= h(t('lp_result_next', NuLibs::TAG)) ?></span>
			</div>
		</div>
		<?php endif; ?>
		<form method="post" enctype="multipart/form-data" id="lpBuildForm" data-nu-once><?= nu_csrf_field() ?><input type="hidden" name="action" value="build">
			<div class="table-responsive">
				<table class="table table-sm nu-table small align-middle">
					<thead><tr><th><span class="visually-hidden"><?= h(t('actions')) ?></span></th><th><?= h(t('upd_libs_name')) ?></th><th><?= h(t('lp_src')) ?></th><th><?= h(t('upd_libs_version')) ?></th></tr></thead>
					<tbody>
					<?php foreach (NuLibs::SLOTS as $sl => $def): [$src, $kind] = NuLibs::source($sl); $ver = $src !== '' ? NuLibs::detectVersion($sl, $src) : ''; ?>
						<tr data-slot="<?= h($sl) ?>">
							<td><input class="form-check-input" type="checkbox" name="slots[]" value="<?= h($sl) ?>" id="lpB-<?= h($sl) ?>"<?= $src !== '' ? ' checked' : '' ?> aria-label="<?= h($def['title']) ?>"></td>
							<td><label class="fw-semibold" for="lpB-<?= h($sl) ?>"><?= h($def['title']) ?></label><div class="text-body-secondary small nuLpSrcKind"
								data-kind="<?= h($kind) ?>"><i class="bi <?= $kind === 'libsrc' ? 'bi-check-circle-fill text-success' : ($kind === 'inuse' ? 'bi-check-circle text-success' : 'bi-x-circle text-danger') ?> me-1"
								aria-hidden="true"></i><?= h($kind === 'libsrc' ? t('lp_src_from', NuLibs::shortPath(dirname($src)) . '/') : ($kind === 'inuse' ? t('lp_src_inuse') : t('lp_src_none'))) ?></div></td>
							<td><input class="form-control form-control-sm" type="text" name="src[<?= h($sl) ?>]"
								placeholder="<?= h($src !== '' ? NuLibs::shortPath($src) : (NuLibs::libsrcBlocked() !== '' ? NuLibs::LIBSRC_IN : '../' . NuLibs::LIBSRC) . '/' . $sl) ?>"
								aria-label="<?= h(t('lp_src') . ' ' . $def['title']) ?>"></td>
							<td><input class="form-control form-control-sm nuLpVer" type="text" name="ver[<?= h($sl) ?>]" placeholder="<?= h($ver !== '' ? $ver : '?') ?>"
								aria-label="<?= h(t('upd_libs_version') . ' ' . $def['title']) ?>"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="small text-body-secondary"><?= h(t('lp_src_hint')) ?></p>
			<div class="row g-3 align-items-end">
				<div class="col-md-6">
					<label class="form-label fw-semibold" for="lpKey"><?= h(t('lp_key')) ?></label>
					<input class="form-control" type="file" name="key" id="lpKey" required autocomplete="off">
					<div class="form-text"><?= h(t('lp_key_hint')) ?></div>
				</div>
				<div class="col-md-6">
					<div class="form-check"><input class="form-check-input" type="checkbox" name="keep" value="1" id="lpKeep"<?= $gh['libs'] ? ' checked' : ' disabled' ?>>
						<label class="form-check-label" for="lpKeep"><?= h(t('lp_keep_index', count($gh['libs']))) ?></label></div>
				</div>
			</div>
			<button class="btn btn-primary mt-3" type="submit" id="lpBuildBtn"<?= $secure ? '' : ' disabled' ?>
				data-nu-busy="<?= h(t('lp_building')) ?>"><i class="bi bi-pen me-1"></i><?= h(t('lp_build_btn')) ?></button>
		</form>
	</div>
</div>
<?php
nu_layout_end();
