<?php
defined('NU_PAGE') || exit;
$nu->api(1);

/*
 * nuFiles – file manager for nuBuilder Next (page form nufiles; built in: apps/nufiles). Replaces Tiny File Manager: no external code, no
 * own login – nuBuilder Next's session, permission items and CSRF protection. Settings: form builder → Settings (see README.md).
 */
const NUF_VERSION = '1.0.0';

require __DIR__ . '/lib/guard.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/fs.php';
require __DIR__ . '/lib/log.php';
require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/api.php';

// The page's own Content-Security-Policy (replaces the page route's frame-ancestors-only policy): own scripts only, no inline code.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; "
	. "media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
header('Referrer-Policy: same-origin');

$S = NufI18n::strings($nu->lang());
NufConfig::load($nu);
NufFs::housekeeping(NufConfig::$limits);

if ($nu->action() !== '') NufApi::handle($nu, $S);

// ---------------------------------------------------------------------------------------------------------------- the page
$boot = NufApi::boot($nu, $S);
$t = static fn(string $k): string => $nu->h($S[$k] ?? $k);
$theme = $nu->theme();
// vendor files that come with nuBuilder Next: the page method "vendor" of core change C2 when it exists, until then the constants
$v = static function (string $c, string $fallback, string $file) use ($nu): string {
	$lib = ['NU_BS' => 'bootstrap', 'NU_BI' => 'bootstrap-icons', 'NU_CODEMIRROR' => 'codemirror'][$c];
	$url = NufApi::future($nu, 'vendor', $lib, $file);
	return $nu->h($url ?? (defined($c) ? constant($c) : $fallback) . '/' . $file . '?v=' . (defined('NU_VERSION') ? NU_VERSION : '1'));
};
?><!doctype html>
<html lang="<?= $nu->h(substr($nu->lang(), 0, 2)) ?>" data-bs-theme="<?= $theme === 'dark' ? 'dark' : 'light' ?>" data-nuf-theme="<?= $nu->h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $t('title') ?></title>
<link rel="stylesheet" href="<?= $v('NU_BS', 'vendor/bundled/bootstrap-5.3.8', 'css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= $v('NU_BI', 'vendor/bundled/bootstrap-icons-1.13.2', 'bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= $v('NU_CODEMIRROR', 'vendor/bundled/codemirror-5.65.21', 'codemirror.css') ?>">
<link rel="stylesheet" href="<?= $nu->h($nu->asset('assets/app.css')) ?>">
</head>
<body class="nuf">
<script type="application/json" id="nufBoot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

<div class="nuf-app" id="nufApp">
	<aside class="nuf-side" id="nufSide" aria-label="<?= $t('shares') ?>">
		<div class="nuf-side-head">
			<i class="bi bi-hdd-network" aria-hidden="true"></i> <span><?= $t('shares') ?></span>
		</div>
		<nav class="nav flex-column nuf-shares" id="nufShares"></nav>
		<div class="nuf-side-foot">
			<button type="button" class="nav-link nuf-side-link" id="nufTrashBtn"><i class="bi bi-trash3" aria-hidden="true"></i> <?= $t('trash') ?></button>
			<button type="button" class="nav-link nuf-side-link d-none" id="nufLogBtn"><i class="bi bi-journal-text" aria-hidden="true"></i> <?= $t('log') ?></button>
		</div>
	</aside>

	<main class="nuf-main" id="nufMain">
		<div class="nuf-bar">
			<button type="button" class="btn btn-sm btn-outline-secondary d-md-none" id="nufSideToggle" aria-controls="nufSide" title="<?= $t('shares') ?>"><i class="bi bi-list"></i></button>
			<nav aria-label="breadcrumb" class="nuf-crumbs"><ol class="breadcrumb mb-0" id="nufCrumbs"></ol></nav>
			<form class="nuf-search" id="nufSearchForm" role="search">
				<div class="input-group input-group-sm">
					<span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
					<input type="search" class="form-control" id="nufSearch" placeholder="<?= $t('search_ph') ?>" aria-label="<?= $t('search_ph') ?>" maxlength="200">
				</div>
			</form>
		</div>

		<div class="nuf-tools" id="nufTools" role="toolbar">
			<div class="btn-group btn-group-sm" data-need="upload">
				<button type="button" class="btn btn-primary" id="nufUploadBtn"><i class="bi bi-upload"></i> <span class="nuf-lbl"><?= $t('upload') ?></span></button>
				<button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" data-need="delete"><span class="visually-hidden"><?= $t('details') ?></span></button>
				<div class="dropdown-menu p-2 nuf-upload-opts">
					<div class="form-check"><input class="form-check-input" type="checkbox" id="nufOverwrite"><label class="form-check-label" for="nufOverwrite"><?= $t('overwrite_existing') ?></label></div>
				</div>
			</div>
			<input type="file" id="nufFileInput" multiple hidden>
			<button type="button" class="btn btn-sm btn-outline-secondary" id="nufMkdirBtn" data-need="mkdir"><i class="bi bi-folder-plus"></i> <span class="nuf-lbl"><?= $t('new_folder') ?></span></button>
			<button type="button" class="btn btn-sm btn-outline-secondary" id="nufNewFileBtn" data-need="edit"><i class="bi bi-file-earmark-plus"></i> <span class="nuf-lbl"><?= $t('new_file') ?></span></button>
			<span class="nuf-sep"></span>
			<button type="button" class="btn btn-sm btn-outline-secondary" id="nufDownloadBtn" data-sel="1"><i class="bi bi-download"></i> <span class="nuf-lbl"><?= $t('download') ?></span></button>
			<button type="button" class="btn btn-sm btn-outline-secondary" id="nufCopyBtn" data-sel="1" data-need="view"><i class="bi bi-copy"></i> <span class="nuf-lbl"><?= $t('copy') ?></span></button>
			<button type="button" class="btn btn-sm btn-outline-secondary" id="nufMoveBtn" data-sel="1" data-need="rename|delete"><i class="bi bi-arrow-right-square"></i> <span class="nuf-lbl"><?= $t('move') ?></span></button>
			<button type="button" class="btn btn-sm btn-outline-secondary" id="nufZipBtn" data-sel="1" data-need="zip"><i class="bi bi-file-earmark-zip"></i> <span class="nuf-lbl"><?= $t('zip') ?></span></button>
			<button type="button" class="btn btn-sm btn-outline-danger" id="nufDeleteBtn" data-sel="1" data-need="delete"><i class="bi bi-trash3"></i> <span class="nuf-lbl"><?= $t('delete') ?></span></button>
			<span class="ms-auto"></span>
			<span class="badge text-bg-warning d-none" id="nufRoBadge"><?= $t('readonly_badge') ?></span>
			<button type="button" class="btn btn-sm btn-outline-secondary" id="nufRefreshBtn" title="<?= $t('refresh') ?>"><i class="bi bi-arrow-clockwise"></i></button>
		</div>

		<div class="alert alert-warning m-2 d-none" id="nufProblems" role="alert"></div>

		<div class="nuf-view" id="nufView" tabindex="-1">
			<div class="nuf-drop" id="nufDrop" aria-hidden="true"><div><i class="bi bi-cloud-arrow-up"></i><br><?= $t('drop_here') ?></div></div>
			<div class="nuf-msg text-body-secondary d-none" id="nufMsg"></div>
			<table class="table table-sm table-hover align-middle nuf-table mb-0" id="nufTable">
				<thead><tr>
					<th class="nuf-c-check"><input class="form-check-input" type="checkbox" id="nufAll" aria-label="<?= $t('select_all') ?>"></th>
					<th class="nuf-c-name"><button type="button" class="nuf-sort" data-sort="name"><?= $t('name') ?></button></th>
					<th class="nuf-c-size text-end"><button type="button" class="nuf-sort" data-sort="size"><?= $t('size') ?></button></th>
					<th class="nuf-c-time"><button type="button" class="nuf-sort" data-sort="mtime"><?= $t('modified') ?></button></th>
					<th class="nuf-c-act"><span class="visually-hidden"><?= $t('actions') ?></span></th>
				</tr></thead>
				<tbody id="nufRows"></tbody>
			</table>
			<table class="table table-sm table-hover align-middle nuf-table nuf-table2 mb-0 d-none" id="nufTable2">
				<thead><tr id="nufHead2"></tr></thead>
				<tbody id="nufRows2"></tbody>
			</table>
		</div>

		<div class="nuf-status" id="nufStatus"><span id="nufStatusLeft"></span><span id="nufStatusRight"></span></div>
	</main>
</div>

<!-- uploads -->
<div class="nuf-uploads card shadow d-none" id="nufUploads">
	<div class="card-header d-flex align-items-center gap-2 py-1">
		<i class="bi bi-upload"></i><strong class="me-auto" id="nufUploadsTitle"><?= $t('uploading') ?></strong>
		<button type="button" class="btn-close btn-sm" id="nufUploadsClose" aria-label="<?= $t('close') ?>"></button>
	</div>
	<ul class="list-group list-group-flush" id="nufUploadList"></ul>
</div>

<!-- generic dialog: prompt / confirm / message -->
<div class="modal fade" id="nufDlg" tabindex="-1" aria-hidden="true" aria-labelledby="nufDlgTitle">
	<div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="nufDlgForm" novalidate>
		<div class="modal-header"><h5 class="modal-title" id="nufDlgTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $t('close') ?>"></button></div>
		<div class="modal-body"><div id="nufDlgText" class="mb-2"></div><input type="text" class="form-control d-none" id="nufDlgInput" autocomplete="off" spellcheck="false"><div class="invalid-feedback" id="nufDlgErr"></div></div>
		<div class="modal-footer" id="nufDlgFoot"></div>
	</form></div>
</div>

<!-- folder picker (copy / move) -->
<div class="modal fade" id="nufPick" tabindex="-1" aria-hidden="true" aria-labelledby="nufPickTitle">
	<div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title" id="nufPickTitle"><?= $t('choose_folder') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $t('close') ?>"></button></div>
		<div class="modal-body">
			<select class="form-select form-select-sm mb-2" id="nufPickShare" aria-label="<?= $t('shares') ?>"></select>
			<div class="small mb-2 text-break" id="nufPickPath"></div>
			<div class="list-group list-group-flush nuf-pick-list" id="nufPickList"></div>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $t('cancel') ?></button>
			<button type="button" class="btn btn-primary" id="nufPickOk"><?= $t('here') ?></button>
		</div>
	</div></div>
</div>

<!-- preview -->
<div class="modal fade" id="nufPreview" tabindex="-1" aria-hidden="true" aria-labelledby="nufPreviewTitle">
	<div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title text-truncate" id="nufPreviewTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $t('close') ?>"></button></div>
		<div class="modal-body nuf-preview-body" id="nufPreviewBody"></div>
		<div class="modal-footer"><a class="btn btn-outline-secondary" id="nufPreviewDl" href="#"><i class="bi bi-download"></i> <?= $t('download') ?></a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $t('close') ?></button></div>
	</div></div>
</div>

<!-- editor -->
<div class="modal" id="nufEditor" tabindex="-1" aria-hidden="true" aria-labelledby="nufEditorTitle" data-bs-backdrop="static" data-bs-keyboard="false">
	<div class="modal-dialog modal-fullscreen"><div class="modal-content">
		<div class="modal-header py-2 gap-2 flex-wrap nuf-editor-head">
			<div class="d-flex align-items-center gap-2 w-100 nuf-editor-titlerow">
				<h5 class="modal-title text-truncate me-auto" id="nufEditorTitle"></h5>
				<span class="badge text-bg-secondary d-none" id="nufEditorRo"><?= $t('readonly_badge') ?></span>
				<span class="small text-body-secondary text-nowrap" id="nufEditorPos"></span>
			</div>
			<div class="d-flex align-items-center gap-2 flex-wrap w-100 nuf-editor-tools">
				<div class="nuf-codetools" id="nufCodeTools"></div>
				<div class="form-check form-switch mb-0 small ms-auto"><input class="form-check-input" type="checkbox" id="nufWrap"><label class="form-check-label" for="nufWrap" title="<?= $t('wrap') ?>"><i class="bi bi-text-wrap nuf-icon-only" aria-hidden="true"></i><span class="nuf-btn-text"><?= $t('wrap') ?></span></label></div>
				<div class="dropdown">
					<button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" id="nufVersionsBtn" title="<?= $t('versions') ?>" aria-label="<?= $t('versions') ?>"><i class="bi bi-clock-history" aria-hidden="true"></i><span class="nuf-btn-text"> <?= $t('versions') ?></span></button>
					<ul class="dropdown-menu dropdown-menu-end nuf-versions" id="nufVersions"></ul>
				</div>
				<button type="button" class="btn btn-sm btn-primary" id="nufSaveBtn" title="<?= $t('save') ?> – <?= $t('shortcut_hint') ?>" aria-label="<?= $t('save') ?>"><i class="bi bi-floppy" aria-hidden="true"></i><span class="nuf-btn-text"> <?= $t('save') ?></span></button>
				<button type="button" class="btn btn-sm btn-outline-secondary" id="nufEditorClose" title="<?= $t('close') ?>" aria-label="<?= $t('close') ?>"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="nuf-btn-text"> <?= $t('close') ?></span></button>
			</div>
		</div>
		<div class="modal-body p-0 nuf-editor-body"><textarea id="nufCode" aria-labelledby="nufEditorTitle"></textarea></div>
	</div></div>
</div>

<!-- trash / log / info / search results share the main view; the toasts -->
<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x p-3" id="nufToasts" aria-live="polite"></div>

<script src="<?= $v('NU_BS', 'vendor/bundled/bootstrap-5.3.8', 'js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= $v('NU_CODEMIRROR', 'vendor/bundled/codemirror-5.65.21', 'codemirror-bundle.js') ?>"></script>
<script src="<?= $v('NU_CODEMIRROR', 'vendor/bundled/codemirror-5.65.21', 'codemirror-addons.js') ?>"></script>
<?php if (function_exists('nu_asset')): // nuBuilder Next's code editor toolbar (undo / redo, search, replace, comment, format, copy / paste) ?>
<script src="<?= $nu->h(nu_asset('assets/js/nu/nucodetools.js')) ?>"></script>
<?php endif; ?>
<?= $nu->sdk() ?>
<script src="<?= $nu->h($nu->asset('assets/app.js')) ?>"></script>
</body>
</html>
