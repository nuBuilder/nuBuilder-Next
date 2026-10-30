<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Themes (0.8.632, core/nutheme.php): Admin tools → Themes – admin right "branding".
 *   list: the themes (5 built-in + own), who uses which (installation, access levels, users may choose), import
 *   ?edit=<own id>: editor with live preview (iframe ?p=themes&preview=1, draft values in the session – POST action=draft as JSON)
 *   ?preview=1: the preview page (sample of top bar, buttons, fields, tabs, list, form) with the draft stylesheet
 *   POST action pick (any signed-in user, user menu): own theme when the installation lets users choose
 * Other POST actions: duplicate, save, delete, export, import, use, who.
 */
if (nu_is_post() && nu_post('action') === 'pick') {
	try { NuTheme::pick(nu_post('theme')); } catch (Throwable $e) { nu_flash('danger', $e->getMessage()); }
	$back = NuAuth::validNext(nu_post('back')) ?? nu_url('home');
	nu_redirect($back);
}

NuAdmin::require('branding');

// ------------------------------------------------------------------ preview page (inside the editor's iframe)
if (isset($_GET['preview'])) {
	NuTheme::$preview = true;
	header_remove('X-Frame-Options');                                     // shown in the editor's iframe (same site only)
	header('X-Frame-Options: SAMEORIGIN');
	header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
	nu_head(t('theme_preview'), '<link rel="stylesheet" href="' . nu_asset('assets/css/nuform.css') . '">');
	?>
<body class="nu-app thPreviewBody">
	<nav class="navbar nu-navbar border-bottom px-3 py-2">
		<span class="navbar-brand d-flex align-items-center gap-2 m-0"><?= nu_brand_logo() ?></span>
		<span class="d-flex align-items-center gap-1 ms-3 me-auto"><a class="nav-link active px-2" href="#"><i class="bi bi-house me-1"></i><?= h(t('nav_home')) ?></a><a class="nav-link px-2" href="#"><?= h(t('nav_users')) ?></a></span>
		<button type="button" class="btn btn-sm btn-outline-secondary" tabindex="-1"><i class="bi bi-circle-half"></i></button>
	</nav>
	<main class="p-3">
		<div class="card shadow-sm nuListCard mb-3">
			<div class="card-header nuListHead nuTitleBar">
				<h1 class="h5 mb-0 fw-semibold"><?= h(t('theme_pv_title')) ?></h1>
				<div class="d-flex flex-wrap gap-2">
					<button type="button" class="btn nuBtnAct" tabindex="-1"><i class="bi bi-arrow-left"></i><?= h(t('back')) ?></button>
					<button type="button" class="btn nuBtnAct nuBtnSave" tabindex="-1"><i class="bi bi-floppy"></i><?= h(t('save')) ?></button>
					<button type="button" class="btn nuBtnAct" tabindex="-1"><i class="bi bi-copy"></i><?= h(t('clone')) ?></button>
					<button type="button" class="btn btn-primary" tabindex="-1"><?= h(t('brand_preview_button')) ?></button>
				</div>
			</div>
			<div class="card-body">
				<ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link active" href="#"><?= h(t('theme_pv_tab1')) ?></a></li><li class="nav-item"><a class="nav-link" href="#"><?= h(t('theme_pv_tab2')) ?></a></li><li class="nav-item"><a class="nav-link" href="#"><?= h(t('theme_pv_tab3')) ?></a></li></ul>
				<div class="row g-3">
					<div class="col-sm-6"><label class="form-label" for="pv1"><?= h(t('theme_pv_name')) ?> <span class="nuMark nuMarkRequired">*</span></label><input class="form-control" id="pv1" value="Anna Example" tabindex="-1"></div>
					<div class="col-sm-6"><label class="form-label" for="pv2"><?= h(t('theme_pv_level')) ?></label><select class="form-select" id="pv2" tabindex="-1"><option>Manager</option></select></div>
					<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="pv3" checked tabindex="-1"><label class="form-check-label" for="pv3"><?= h(t('brand_preview_check')) ?></label></div></div>
				</div>
				<p class="mt-3 mb-0 small"><span class="text-body-secondary"><?= h(t('theme_pv_muted')) ?></span> · <a href="#"><?= h(t('brand_preview_link')) ?></a></p>
			</div>
		</div>
		<div class="card shadow-sm nuListCard mb-3">
			<div class="table-responsive"><table class="table nu-table table-hover mb-0">
				<thead><tr><th><?= h(t('theme_pv_name')) ?></th><th><?= h(t('theme_pv_level')) ?></th><th class="text-end"><?= h(t('theme_pv_orders')) ?></th></tr></thead>
				<tbody><tr class="clickable-row"><td>Anna Example</td><td>Manager</td><td class="text-end">12</td></tr><tr class="clickable-row"><td>Ben Sample</td><td>Viewer</td><td class="text-end">7</td></tr><tr class="clickable-row"><td>Carla Test</td><td>Manager</td><td class="text-end">31</td></tr></tbody>
			</table></div>
		</div>
		<div class="nuEditForm p-3">
			<div class="small fw-semibold mb-2"><?= h(t('theme_pv_form')) ?></div>
			<div class="d-flex flex-wrap align-items-end gap-3">
				<div><label class="form-label nuLabel" for="pv4"><?= h(t('theme_pv_customer')) ?></label><input class="form-control" id="pv4" value="ACME Ltd" tabindex="-1"></div>
				<button type="button" class="btn nuFormBtn nuButton" tabindex="-1"><span class="nuButtonLabel"><?= h(t('theme_pv_run')) ?></span></button>
				<button type="button" class="nuActionButton" tabindex="-1"><i class="bi bi-printer"></i> nuActionButton</button>
			</div>
		</div>
	</main>
</body>
</html>
	<?php
	exit;
}

$back = nu_url('themes');
if (nu_is_post()) {
	$action = nu_post('action');
	$in = [];
	if ($action === '' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'json')) {
		$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
		$action = (string)($in['action'] ?? '');
	}
	if ($action === 'draft') {                                            // live preview: values of the editor into the session (JSON)
		[$v, $d] = NuTheme::clean((array)($in['v'] ?? []), (array)($in['d'] ?? []));
		$_SESSION['nu_theme_draft'] = ['v' => $v, 'd' => $d];
		header('Content-Type: application/json; charset=utf-8');
		$names = static fn(array $w) => ['text' => t('theme_warn', t('theme_k_' . $w[0]), t('theme_k_' . $w[1]), str_replace('.', ',', (string)$w[2]) . ' : 1')
			. ($w[3] === 'dark' ? ' (' . t('theme_dark') . ')' : '')];
		exit(json_encode(['ok' => true, 'warnings' => array_map($names, NuTheme::warnings($v, $d))], JSON_UNESCAPED_UNICODE));
	}
	if ($action === 'export') {
		try {
			$json = NuTheme::export(nu_post('id'));
			$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)(NuTheme::get(nu_post('id'))['name'] ?? 'theme')) ?: 'theme';
			header('Content-Type: application/json; charset=utf-8');
			header('Content-Disposition: attachment; filename="theme-' . trim($name, '-') . '.json"');
			exit($json);
		} catch (Throwable $e) { nu_flash('danger', $e->getMessage()); }
		nu_redirect($back);
	}
	try {
		if ($action === 'duplicate') {
			$src = NuTheme::get(nu_post('id'));
			if (!$src) throw new RuntimeException(t('err_not_found'));
			[$id] = NuTheme::save('', NuTheme::uniqueName(t('theme_copy_of', $src['name'])), $src['v'], $src['d'], $src['builtin'] ? $src['id'] : $src['base']);
			nu_redirect(nu_url('themes', ['edit' => $id]));
		}
		if ($action === 'save') {
			$id = nu_post('id');
			$v = []; $d = [];
			foreach (NuTheme::KEYS as $k => $def) { $v[$k] = nu_post('v_' . $k); if ($def[1] === 'color') $d[$k] = nu_post('d_' . $k); }
			[$id, $errors] = NuTheme::save($id, nu_post('name'), $v, $d);
			if ($errors) { foreach ($errors as $e) nu_flash('danger', $e); nu_redirect(nu_url('themes', ['edit' => nu_post('id')])); }
			unset($_SESSION['nu_theme_draft']);
			nu_flash('success', t('theme_saved'));
			nu_redirect(nu_post('stay') === '1' ? nu_url('themes', ['edit' => $id]) : $back);
		}
		if ($action === 'delete') {
			NuTheme::delete(nu_post('id'));
			nu_flash('success', t('theme_deleted'));
		}
		if ($action === 'import') {
			$f = $_FILES['file'] ?? null;
			if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new RuntimeException(t('file_err_upload'));
			$id = NuTheme::import((string)file_get_contents((string)$f['tmp_name'], false, null, 0, 200000));
			nu_flash('success', t('theme_imported_ok'));
			nu_redirect(nu_url('themes', ['edit' => $id]));
		}
		if ($action === 'use') {                                           // "Use for everyone": the installation's theme
			NuTheme::saveSettings(['default' => nu_post('id')] + NuTheme::settings());
			NuLog::write('theme_settings', 'zzzzsys_nunext_setting', NuTheme::SETTING, ['default' => nu_post('id')]);
			nu_flash('success', t('theme_used'));
		}
		if ($action === 'who') {
			$levels = [];
			foreach ((array)($_POST['level'] ?? []) as $a => $t) if (is_string($t) && $t !== '') $levels[(string)$a] = $t;
			NuTheme::saveSettings(['default' => nu_post('default'), 'levels' => $levels, 'user_choice' => nu_check('user_choice') === '1',
				'allowed' => array_map('strval', (array)($_POST['allowed'] ?? []))]);
			NuLog::write('theme_settings', 'zzzzsys_nunext_setting', NuTheme::SETTING, ['default' => nu_post('default'), 'levels' => count($levels), 'user_choice' => nu_check('user_choice')]);
			nu_flash('success', t('theme_who_saved'));
		}
	} catch (Throwable $e) {
		nu_flash('danger', $e->getMessage());
	}
	nu_redirect($back);
}

$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
$all = NuTheme::all();
$ro = NuAdmin::$viewOnly;

// ------------------------------------------------------------------ editor
if (isset($_GET['edit'])) {
	$t = NuTheme::get((string)$_GET['edit']);
	if (!$t || $t['builtin']) { nu_flash('warning', t('err_not_found')); nu_redirect($back); }
	$_SESSION['nu_theme_draft'] = ['v' => $t['v'], 'd' => $t['d']];
	nu_layout_start(t('theme_edit_title', $t['name']), 'home', ['settings' => 'look', 'settings_page' => 'themes']);
	?>
<form method="post" action="<?= h(nu_url('themes')) ?>" id="thForm" data-nu-guard data-draft-url="<?= h(nu_url('themes')) ?>" data-csrf="<?= h(nu_csrf_token()) ?>">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($t['id']) ?>"><input type="hidden" name="stay" value="0" id="thStay">
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('theme_edit_title', $t['name'])) ?><?= NuListView::infoTip(t('theme_edit_hint')) ?></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button(null, nu_url('themes')) ?>
			<?php if (!$ro): ?><button class="btn nuBtnAct nuBtnSave" type="submit" id="thSave" data-nu-save><i class="bi bi-floppy"></i><?= h(t('save')) ?></button><?php endif; ?>
		</div>
	</div>
	<div class="card-body">
		<div class="row g-4">
			<div class="col-xl-6">
				<div class="mb-3"><label class="form-label fw-semibold" for="thName"><?= h(t('theme_name')) ?> <span class="nuMark nuMarkRequired" title="<?= h(t('val_required')) ?>" aria-hidden="true">*</span></label>
					<?php // names in use (0.8.694): checked while typing (assets/js/nuthemes.js) and again on the server ?>
					<input class="form-control thNarrow" id="thName" name="name" maxlength="100" required value="<?= h($t['name']) ?>"
						data-taken="<?= h((string)json_encode(array_values(array_map(static fn($x) => mb_strtolower(trim((string)$x['name'])), array_filter(NuTheme::all(), static fn($x) => $x['id'] !== $t['id']))), JSON_UNESCAPED_UNICODE)) ?>"
						data-taken-msg="<?= h(t('theme_err_name_taken', '%s')) ?>"><div class="invalid-feedback" id="thNameMsg"></div></div>
				<p class="small text-body-secondary"><?= h(t('theme_empty_hint')) ?></p>
				<div class="accordion" id="thGroups">
				<?php foreach (NuTheme::GROUPS as $gi => $g): ?>
					<div class="accordion-item">
						<h2 class="accordion-header"><button class="accordion-button<?= $gi ? ' collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#thG_<?= $g ?>" aria-expanded="<?= $gi ? 'false' : 'true' ?>" aria-controls="thG_<?= $g ?>"><?= h(t('theme_g_' . $g)) ?></button></h2>
						<div id="thG_<?= $g ?>" class="accordion-collapse collapse<?= $gi ? '' : ' show' ?>"><div class="accordion-body">
							<?php $hasColor = false; foreach (NuTheme::KEYS as $k => $def) if ($def[0] === $g && $def[1] === 'color') $hasColor = true; ?>
							<?php if ($hasColor): ?><div class="row small text-body-secondary mb-1 d-none d-sm-flex"><div class="col-sm-4"></div><div class="col-sm-4"><i class="bi bi-sun me-1"></i><?= h(t('theme_light')) ?></div><div class="col-sm-4"><i class="bi bi-moon-stars me-1"></i><?= h(t('theme_dark')) ?></div></div><?php endif; ?>
							<?php foreach (NuTheme::KEYS as $k => $def): if ($def[0] !== $g) continue; $val = (string)($t['v'][$k] ?? ''); ?>
								<div class="row g-2 align-items-center mb-2 thRow" data-key="<?= $k ?>">
									<div class="col-sm-4"><label class="form-label mb-0 small" for="v_<?= $k ?>"><?= h(t('theme_k_' . $k)) ?></label></div>
									<?php if ($def[1] === 'color'): ?>
										<?php foreach (['v' => $val, 'd' => (string)($t['d'][$k] ?? '')] as $m => $cv): ?>
										<div class="col-6 col-sm-4"><div class="input-group input-group-sm thColor">
											<input type="color" class="form-control form-control-color thPick" value="<?= h($cv !== '' ? $cv : '#ffffff') ?>" aria-label="<?= h(t('theme_k_' . $k) . ' – ' . t($m === 'v' ? 'theme_light' : 'theme_dark')) ?>" tabindex="-1">
											<input type="text" class="form-control thHex" id="<?= $m ?>_<?= $k ?>" name="<?= $m ?>_<?= $k ?>" value="<?= h($cv) ?>" placeholder="<?= h(t('theme_default')) ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?" spellcheck="false" autocomplete="off" aria-label="<?= h(t('theme_k_' . $k) . ' – ' . t($m === 'v' ? 'theme_light' : 'theme_dark')) ?>">
										</div></div>
										<?php endforeach; ?>
									<?php elseif ($def[1] === 'select'): ?>
										<div class="col-sm-8"><select class="form-select form-select-sm thVal" id="v_<?= $k ?>" name="v_<?= $k ?>">
											<?php foreach ($def[2] as $opt): ?><option value="<?= h($opt) ?>"<?= $val === $opt ? ' selected' : '' ?>><?= h($opt === '' ? t('theme_default') : ($k === 'btn_weight' || $k === 'label_weight' ? t('theme_w_' . $opt) : t('theme_o_' . $opt))) ?></option><?php endforeach; ?>
										</select></div>
									<?php else: ?>
										<div class="col-sm-8"><select class="form-select form-select-sm thVal" id="v_<?= $k ?>" name="v_<?= $k ?>">
											<option value=""><?= h(t('theme_default')) ?></option>
											<?php for ($n = $def[2][0]; $n <= $def[2][1]; $n++): ?><option value="<?= $n ?>"<?= $val === (string)$n ? ' selected' : '' ?>><?= $n ?> px</option><?php endfor; ?>
										</select></div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div></div>
					</div>
				<?php endforeach; ?>
				</div>
			</div>
			<div class="col-xl-6">
				<div class="thPreviewWrap">
					<div class="d-flex align-items-center gap-2 mb-2">
						<span class="fw-semibold me-auto"><?= h(t('brand_preview')) ?></span>
						<div class="btn-group btn-group-sm" role="group" aria-label="<?= h(t('nav_theme')) ?>">
							<button type="button" class="btn btn-outline-secondary active" data-th-mode="light" aria-pressed="true"><i class="bi bi-sun me-1"></i><?= h(t('theme_light')) ?></button>
							<button type="button" class="btn btn-outline-secondary" data-th-mode="dark" aria-pressed="false"><i class="bi bi-moon-stars me-1"></i><?= h(t('theme_dark')) ?></button>
						</div>
					</div>
					<iframe class="thPreview" id="thPreview" src="<?= h(nu_url('themes', ['preview' => 1])) ?>" title="<?= h(t('brand_preview')) ?>"></iframe>
					<div id="thWarn" class="small mt-2" role="status" aria-live="polite"></div>
				</div>
			</div>
		</div>
	</div>
</div>
</form>
	<?php
	nu_layout_end('<script src="' . nu_asset('assets/js/nuthemes.js') . '"></script>');
	exit;
}

// ------------------------------------------------------------------ list
$s = NuTheme::settings();
$mine = NuTheme::current(); $myChoices = NuTheme::choices(); $myPick = NuTheme::userPick();   // own theme (0.8.643): globeadmin always, others when users may choose
$levels = [];
try { $levels = nu_db()->all('SELECT zzzzsys_access_id AS id, sal_code AS code, sal_description AS descr FROM zzzzsys_access ORDER BY sal_code'); } catch (Throwable) {}
$swatch = static function (array $t): string {
	$v = $t['v'];
	$c = [$v['accent'] ?? '#2f6fd6', $v['page_bg'] ?? '#f4f6f9', $v['btn_bg'] ?? '#f8f9fa', $v['nav_bg'] ?? ($v['surface'] ?? '#ffffff'), $v['fbtn_bg'] ?? ($v['accent'] ?? '#2f6fd6')];
	return '<span class="thSwatch" aria-hidden="true">' . implode('', array_map(static fn($x) => '<span class="thSw" data-c="' . h($x) . '"></span>', $c)) . '</span>';   // colour set by nuthemes.js (CSP)
};
nu_layout_start(t('theme_title'), 'home', ['settings' => 'look', 'settings_page' => 'themes']);
?>
<div class="card shadow-sm nuListCard mb-3">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('theme_title')) ?><?= NuListView::infoTip(t('theme_hint')) ?></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?>
			<?php if (!$ro): ?>
			<form method="post" enctype="multipart/form-data" class="d-inline" id="thImportForm"><?= nu_csrf_field() ?><input type="hidden" name="action" value="import">
				<label class="btn nuBtnAct mb-0" for="thImport"><i class="bi bi-upload"></i><?= h(t('theme_import')) ?></label>
				<input type="file" class="d-none" id="thImport" name="file" accept=".json,application/json"></form>
			<?php endif; ?>
		</div>
	</div>
	<div class="card-body">
		<div class="row g-3" id="thList">
		<?php foreach ($all as $t): $u = NuTheme::usage($t['id']); ?>
			<div class="col-sm-6 col-lg-4 col-xxl-3">
				<div class="card h-100 thCard<?= $u['default'] ? ' thCardDefault' : '' ?>" data-theme="<?= h($t['id']) ?>">
					<div class="card-body d-flex flex-column gap-2">
						<div class="d-flex align-items-start gap-2">
							<?= $swatch($t) ?>
							<div class="me-auto"><div class="fw-semibold thCardName"><?= h($t['name']) ?></div>
								<div class="small text-body-secondary"><?= h($t['builtin'] ? t('theme_builtin') : t('theme_own')) ?></div></div>
						</div>
						<div class="d-flex flex-wrap gap-1 small">
							<?php if ($u['default']): ?><span class="badge text-bg-primary"><?= h(t('theme_badge_default')) ?></span><?php endif; ?>
							<?php if ($u['levels']): ?><span class="badge text-bg-secondary"><?= h(t('theme_badge_levels', count($u['levels']))) ?></span><?php endif; ?>
							<?php if ($u['allowed']): ?><span class="badge text-bg-light border"><?= h(t('theme_badge_choice')) ?></span><?php endif; ?>
							<?php if ($mine === $t['id']): ?><span class="badge text-bg-success thMine"><i class="bi bi-person-check me-1"></i><?= h(t('theme_badge_mine')) ?></span><?php endif; ?>
						</div>
						<div class="d-flex flex-wrap gap-1 mt-auto pt-1">
							<?php if (!$t['builtin'] && !$ro): ?><a class="btn btn-sm btn-outline-primary thEdit" href="<?= h(nu_url('themes', ['edit' => $t['id']])) ?>"><i class="bi bi-pencil me-1"></i><?= h(t('edit')) ?></a><?php endif; ?>
							<?php if (!$ro): ?>
							<form method="post" class="d-inline"><?= nu_csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= h($t['id']) ?>">
								<button class="btn btn-sm btn-outline-secondary thDup" type="submit" title="<?= h(t('theme_duplicate_tip')) ?>"><i class="bi bi-copy me-1"></i><?= h(t('theme_duplicate')) ?></button></form>
							<?php if (!$u['default']): ?>
							<form method="post" class="d-inline"><?= nu_csrf_field() ?><input type="hidden" name="action" value="use"><input type="hidden" name="id" value="<?= h($t['id']) ?>">
								<button class="btn btn-sm btn-outline-secondary thUse" type="submit"><i class="bi bi-check2-circle me-1"></i><?= h(t('theme_use')) ?></button></form>
							<?php endif; ?>
							<?php endif; ?>
							<?php if (isset($myChoices[$t['id']]) && $myPick !== $t['id']): ?>
							<form method="post" class="d-inline"><?= nu_csrf_field() ?><input type="hidden" name="action" value="pick"><input type="hidden" name="theme" value="<?= h($t['id']) ?>"><input type="hidden" name="back" value="index.php?p=themes">
								<button class="btn btn-sm btn-outline-secondary thUseMe" type="submit" title="<?= h(t('theme_use_me_tip')) ?>"><i class="bi bi-person-check me-1"></i><?= h(t('theme_use_me')) ?></button></form>
							<?php endif; ?>
							<div class="dropdown ms-auto">
								<button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('more_actions')) ?>"><i class="bi bi-three-dots"></i></button>
								<ul class="dropdown-menu dropdown-menu-end">
									<?php if ($myPick === $t['id']): ?>
									<li><form method="post"><?= nu_csrf_field() ?><input type="hidden" name="action" value="pick"><input type="hidden" name="theme" value=""><input type="hidden" name="back" value="index.php?p=themes">
										<button class="dropdown-item thUseMeOff" type="submit"><i class="bi bi-person-x me-2"></i><?= h(t('theme_use_me_off')) ?></button></form></li>
									<?php endif; ?>
									<li><form method="post"><?= nu_csrf_field() ?><input type="hidden" name="action" value="export"><input type="hidden" name="id" value="<?= h($t['id']) ?>">
										<button class="dropdown-item thExport" type="submit"><i class="bi bi-download me-2"></i><?= h(t('theme_export')) ?></button></form></li>
									<?php if (!$t['builtin'] && !$ro): ?>
									<li><form method="post" data-confirm="<?= h(t('theme_delete_confirm', $t['name'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($t['id']) ?>">
										<button class="dropdown-item text-danger thDelete" type="submit"><i class="bi bi-trash me-2"></i><?= h(t('delete')) ?></button></form></li>
									<?php endif; ?>
								</ul>
							</div>
						</div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
	</div>
</div>

<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead"><h2 class="h6 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('theme_who')) ?><?= $tip(t('theme_who_tip')) ?></h2></div>
	<div class="card-body">
		<form method="post" id="thWho" data-nu-guard>
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="who">
			<div class="mb-3"><label class="form-label fw-semibold" for="thDefault"><?= h(t('theme_default_all')) ?></label>
				<select class="form-select thNarrow" id="thDefault" name="default"<?= $ro ? ' disabled' : '' ?>><?php foreach ($all as $t): ?><option value="<?= h($t['id']) ?>"<?= $s['default'] === $t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
			<?php if ($levels): ?>
			<?php $nOwn = count(array_filter($levels, static fn($l) => ($s['levels'][$l['id']] ?? '') !== '')); // folded by default (0.8.695) ?>
			<div class="mb-3"><button class="btn btn-link p-0 fw-semibold text-decoration-none text-body thLevelsToggle collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#thLevelsBox" aria-expanded="false" aria-controls="thLevelsBox" id="thLevelsBtn">
					<i class="bi bi-chevron-right me-1 thChevron" aria-hidden="true"></i><?= h(t('theme_per_level')) ?></button>
				<span class="badge rounded-pill text-bg-light border ms-1" title="<?= h(t('theme_levels_own', $nOwn)) ?>"><?= $nOwn ?> / <?= count($levels) ?></span>
				<div class="collapse mt-2" id="thLevelsBox"><div class="table-responsive"><table class="table table-sm nu-table align-middle mb-0 thLevels"><thead><tr><th><?= h(t('nav_access')) ?></th><th><?= h(t('theme_title')) ?></th></tr></thead><tbody>
				<?php foreach ($levels as $l): ?>
					<tr><td><label for="thL_<?= h($l['id']) ?>"><span class="fw-medium"><?= h((string)$l['code']) ?></span> <span class="small text-body-secondary"><?= h((string)$l['descr']) ?></span></label></td>
						<td><select class="form-select form-select-sm" id="thL_<?= h($l['id']) ?>" name="level[<?= h($l['id']) ?>]"<?= $ro ? ' disabled' : '' ?>><option value=""><?= h(t('theme_level_inherit')) ?></option>
							<?php foreach ($all as $t): ?><option value="<?= h($t['id']) ?>"<?= ($s['levels'][$l['id']] ?? '') === $t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></td></tr>
				<?php endforeach; ?>
				</tbody></table></div></div></div>
			<?php endif; ?>
			<div class="form-check form-switch mb-2 d-flex align-items-center gap-1">
				<input class="form-check-input me-1" type="checkbox" role="switch" id="thChoice" name="user_choice" value="1"<?= $s['user_choice'] ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>>
				<label class="form-check-label" for="thChoice"><?= h(t('theme_user_choice')) ?></label><?= $tip(t('theme_user_choice_tip')) ?>
			</div>
			<div class="mb-3 ms-4" id="thAllowed"><div class="small text-body-secondary mb-1"><?= h(t('theme_allowed')) ?></div>
				<?php foreach ($all as $t): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="allowed[]" value="<?= h($t['id']) ?>" id="thA_<?= h(str_replace(':', '_', $t['id'])) ?>"<?= in_array($t['id'], $s['allowed'], true) ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>>
					<label class="form-check-label" for="thA_<?= h(str_replace(':', '_', $t['id'])) ?>"><?= h($t['name']) ?></label></div><?php endforeach; ?>
			</div>
			<?php if (!$ro): ?><button class="btn nuBtnAct nuBtnSave" type="submit" id="thWhoSave"><i class="bi bi-floppy"></i><?= h(t('save')) ?></button><?php endif; ?>
		</form>
	</div>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuthemes.js') . '"></script>');
