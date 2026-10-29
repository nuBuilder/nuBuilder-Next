<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Dashboards (0.8.298) – globeadmin, Admin tools → Dashboards. Logic in core/nudashboard.php, editor behaviour in assets/js/nudashboards.js,
 * renderer in assets/js/nudashboard.js.
 *   list · ?id=new|<id> dashboard with its widgets · ?id=<id>&w=new|<widget id> widget editor (Test = run before saving)
 * POST action: save | copy | delete | wsave | wdelete | wmove (dir up / down)
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('dashboards');
NuSecurity::requireRecentAuth(nu_url('dashboards'));   // the widgets run SQL and procedures – password again after 15 minutes
NuSchema::ensure();
NuDashboard::csp();                                   // preview and test run draw charts
$db = nu_db();
$id = (string)($_GET['id'] ?? '');
$wid = (string)($_GET['w'] ?? '');
$d = $id !== '' && $id !== 'new' ? NuDashboard::get($id) : null;
if ($id !== '' && $id !== 'new' && !$d) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
$w = $d && $wid !== '' && $wid !== 'new' ? NuDashboard::widget($wid) : null;
if ($w && (string)$w['dashboard_id'] !== $id) $w = null;
if ($d && $wid !== '' && $wid !== 'new' && !$w) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
$errors = [];
$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		[$newId, $errors] = NuDashboard::save(['code' => nu_post('code'), 'title' => nu_post('title'), 'description' => nu_post('dash_description'),
			'on_home' => nu_check('on_home') === '1', 'access' => (array)($_POST['access'] ?? []), 'date_range' => nu_post('date_range'), 'sort_order' => nu_post('sort_order')], $d ? $id : '');
		if (!$errors) { nu_flash('success', t('dash_saved')); nu_redirect(nu_url('dashboards', ['id' => $newId])); }
	} elseif ($action === 'copy' && $d) {
		$new = NuDashboard::copy($id);
		nu_flash('success', t('dash_copied'));
		nu_redirect(nu_url('dashboards', ['id' => $new]));
	} elseif ($action === 'delete' && $d) {
		NuDashboard::delete($id);
		nu_flash('success', t('dash_deleted'));
		nu_redirect(nu_url('dashboards'));
	} elseif ($action === 'wsave' && $d) {
		$in = ['type' => nu_post('type'), 'title' => nu_post('title'), 'source' => nu_post('source'), 'sql' => (string)($_POST['sql'] ?? ''),
			'select_id' => nu_post('select_id'), 'php_id' => nu_post('php_id'), 'opt' => is_array($_POST['opt'] ?? null) ? $_POST['opt'] : []];
		[$newW, $errors] = NuDashboard::saveWidget($id, $in, $w ? $wid : '');
		if (!$errors) { nu_flash('success', t('dash_w_saved')); nu_redirect(nu_url('dashboards', ['id' => $id])); }
	} elseif ($action === 'wdelete' && $d) {
		NuDashboard::deleteWidget($id, nu_post('widget'));
		nu_flash('success', t('dash_w_deleted'));
		nu_redirect(nu_url('dashboards', ['id' => $id]));
	} elseif ($action === 'wmove' && $d) {
		$ids = array_map(static fn($x) => (string)$x['zzzzsys_nunext_widget_id'], NuDashboard::widgets($id));
		$i = array_search(nu_post('widget'), $ids, true);
		$j = $i === false ? false : (nu_post('dir') === 'up' ? $i - 1 : $i + 1);
		if ($i !== false && $j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; NuDashboard::reorder($id, $ids); }
		nu_redirect(nu_url('dashboards', ['id' => $id]) . '#dashWidgets');
	}
}

// after the POST: arrow functions take $errors as it is when they are created
$inv = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$fb = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . h($errors[$k]) . '</div>' : '';
$accessLevels = $db->tableExists('zzzzsys_access') ? $db->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code') : [];
$accName = [];
foreach ($accessLevels as $a) $accName[(string)$a['zzzzsys_access_id']] = (string)$a['sal_code'];
$foot = '';

// ====================================================================== list
if ($id === '') {                                          // a system list (0.8.690, core/nulistdefs.php 'dashboards'): search, views, export, filters
	NuListView::page('dashboards', ['active' => 'home', 'title_extra' => $tip(t('dash_info')),
		'buttons' => '<a class="btn nuAddBtn" id="dashAdd" href="' . h(nu_url('dashboards', ['id' => 'new'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>']);
	return;
}

// ====================================================================== widget editor
if ($d && $wid !== '') {
	$selects = NuSqlSelect::available() ? $db->all('SELECT zzzzsys_select_id, sse_code, sse_description FROM zzzzsys_select ORDER BY sse_code, sse_description') : [];
	$procs = $db->all("SELECT zzzzsys_php_id, sph_code, sph_description FROM zzzzsys_php WHERE zzzzsys_php_id NOT LIKE 'nu%' ORDER BY sph_code");
	$forms = $db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' ORDER BY sfo_code");
	$reports = $db->tableExists('zzzzsys_report') ? $db->all('SELECT sre_code, sre_description FROM zzzzsys_report ORDER BY sre_code') : [];
	if (nu_is_post()) {        // the posted values after an error
		$cur = ['type' => nu_post('type'), 'title' => nu_post('title'), 'source' => nu_post('source'), 'query' => (string)($_POST[nu_post('source') === 'select' ? 'select_id' : (nu_post('source') === 'php' ? 'php_id' : 'sql')] ?? '')];
		$opt = NuDashboard::options(is_array($_POST['opt'] ?? null) ? $_POST['opt'] : [], $cur['type']);
	} else {
		$cur = $w ?? ['type' => 'kpi', 'title' => '', 'source' => 'sql', 'query' => ''];
		$opt = $w ? $w['opt'] : NuDashboard::options([], 'kpi');
	}
	$type = in_array($cur['type'], NuDashboard::TYPES, true) ? $cur['type'] : 'kpi';
	$src = in_array($cur['source'], NuDashboard::SOURCES, true) ? $cur['source'] : 'sql';
	$sel = static fn(bool $on) => $on ? ' selected' : '';
	nu_layout_start(t('dash_w_title'), 'home', ['head' => nu_ts_head()]);
	?>
	<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="<?= h(nu_url('dashboards')) ?>"><?= h(t('dash_title')) ?></a></li>
		<li class="breadcrumb-item"><a href="<?= h(nu_url('dashboards', ['id' => $id])) ?>"><?= h($d['code']) ?></a></li><li class="breadcrumb-item active" aria-current="page"><?= h($w ? ($w['title'] ?: '–') : t('dash_w_new')) ?></li></ol></nav>
	<div class="card shadow-sm nuListCard">
		<div class="card-header nuListHead nuTitleBar">
			<h1 class="h5 mb-0 fw-semibold"><?= h(t('dash_w_title')) ?></h1>
			<div class="d-flex flex-wrap gap-2"><?= nu_back_button('dashboards', nu_url('dashboards', ['id' => $id])) ?>
				<button class="btn nuBtnAct nuBtnSave" type="submit" form="dashWForm" data-nu-save id="dashWSave"><i class="bi bi-floppy"></i><?= h(t('save')) ?></button>
				<button class="btn nuBtnAct" type="button" id="dashTest"><i class="bi bi-play-fill"></i><?= h(t('dash_test')) ?></button>
			</div>
		</div>
		<div class="card-body">
			<form method="post" action="<?= h(nu_url('dashboards', ['id' => $id, 'w' => $wid])) ?>" id="dashWForm" novalidate data-nu-guard>
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="wsave">
				<div class="row g-3">
					<div class="col-md-3">
						<label class="form-label" for="dType"><?= h(t('dash_type')) ?></label>
						<select class="form-select" id="dType" name="type">
							<?php foreach (NuDashboard::TYPES as $ty): ?><option value="<?= $ty ?>"<?= $sel($ty === $type) ?>><?= h(t('dash_type_' . $ty)) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="dTitle"><?= h(t('dash_w_title_field')) ?></label>
						<input class="form-control" id="dTitle" name="title" maxlength="200" value="<?= h($cur['title']) ?>">
					</div>
					<div class="col-md-3">
						<label class="form-label" for="dWidth"><?= h(t('dash_width')) ?></label><?= $tip(t('dash_width_tip')) ?>
						<select class="form-select" id="dWidth" name="opt[width]">
							<?php foreach (NuDashboard::WIDTHS as $wd): ?><option value="<?= $wd ?>"<?= $sel($wd === (int)$opt['width']) ?>><?= h(t('dash_width_' . $wd)) ?></option><?php endforeach; ?>
						</select>
					</div>

					<div class="col-12">
						<span class="form-label d-block mb-1"><?= h(t('dash_source')) ?></span>
						<?php foreach (NuDashboard::SOURCES as $s): ?>
							<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="source" id="dSrc_<?= $s ?>" value="<?= $s ?>"<?= $s === $src ? ' checked' : '' ?>>
								<label class="form-check-label" for="dSrc_<?= $s ?>"><?= h(t('dash_src_' . $s)) ?></label></div>
						<?php endforeach; ?>
					</div>
					<div class="col-12" data-dash-src="sql">
						<label class="form-label" for="dSql">SQL</label><?= $tip(t('dash_sql_tip')) ?>
						<textarea class="form-control font-monospace<?= $inv('sql') ?>" id="dSql" name="sql" rows="9" spellcheck="false"><?= h($src === 'sql' ? $cur['query'] : '') ?></textarea>
						<?= $fb('sql') ?>
						<div class="form-text" id="dShape"><?= h(t('dash_shape_' . $type)) ?></div>
					</div>
					<div class="col-md-8" data-dash-src="select">
						<label class="form-label" for="dSelect"><?= h(t('dash_src_select')) ?></label>
						<select class="form-select nuListTomSelect<?= $inv('select_id') ?>" id="dSelect" name="select_id"><option value=""></option>
							<?php foreach ($selects as $q): ?><option value="<?= h($q['zzzzsys_select_id']) ?>"<?= $sel($src === 'select' && $cur['query'] === $q['zzzzsys_select_id']) ?>><?= h(trim($q['sse_code'] . ' – ' . $q['sse_description'], ' –')) ?></option><?php endforeach; ?>
						</select><?= $fb('select_id') ?>
					</div>
					<div class="col-md-8" data-dash-src="php">
						<label class="form-label" for="dPhp"><?= h(t('dash_src_php')) ?></label><?= $tip(t('dash_php_tip')) ?>
						<select class="form-select nuListTomSelect<?= $inv('php_id') ?>" id="dPhp" name="php_id"><option value=""></option>
							<?php foreach ($procs as $p): ?><option value="<?= h($p['zzzzsys_php_id']) ?>"<?= $sel($src === 'php' && $cur['query'] === $p['zzzzsys_php_id']) ?>><?= h(trim($p['sph_code'] . ' – ' . $p['sph_description'], ' –')) ?></option><?php endforeach; ?>
						</select><?= $fb('php_id') ?>
					</div>

					<div class="col-md-3" data-dash-type="chart">
						<label class="form-label" for="dChart"><?= h(t('dash_chart')) ?></label>
						<select class="form-select" id="dChart" name="opt[chart]">
							<?php foreach (NuDashboard::CHARTS as $c): ?><option value="<?= $c ?>"<?= $sel($c === $opt['chart']) ?>><?= h(t('dash_chart_' . $c)) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-2" data-dash-type="chart">
						<label class="form-label" for="dHeight"><?= h(t('dash_height')) ?></label>
						<input class="form-control" type="number" min="120" max="800" step="10" id="dHeight" name="opt[height]" value="<?= (int)$opt['height'] ?>">
					</div>
					<div class="col-md-3" data-dash-type="chart">
						<div class="form-check form-switch mt-md-4 pt-md-2"><input type="hidden" name="opt[stacked]" value="0"><input class="form-check-input" type="checkbox" role="switch" id="dStacked" name="opt[stacked]" value="1"<?= $opt['stacked'] ? ' checked' : '' ?>>
							<label class="form-check-label" for="dStacked"><?= h(t('dash_stacked')) ?></label></div>
					</div>
					<div class="col-md-2" data-dash-type="table">
						<label class="form-label" for="dRows"><?= h(t('dash_rows')) ?></label>
						<input class="form-control" type="number" min="1" max="100" id="dRows" name="opt[rows]" value="<?= (int)$opt['rows'] ?>">
					</div>

					<div class="col-12"><hr class="my-1"></div>
					<div class="col-md-3">
						<label class="form-label" for="dFormat"><?= h(t('dash_format')) ?></label>
						<select class="form-select" id="dFormat" name="opt[format]">
							<?php foreach (NuDashboard::FORMATS as $f): ?><option value="<?= $f ?>"<?= $sel($f === $opt['format']) ?>><?= h(t('dash_format_' . $f)) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-2">
						<label class="form-label" for="dDec"><?= h(t('dash_decimals')) ?></label>
						<input class="form-control" type="number" min="0" max="6" id="dDec" name="opt[decimals]" value="<?= (int)$opt['decimals'] ?>">
					</div>
					<div class="col-md-2">
						<label class="form-label" for="dPrefix"><?= h(t('dash_prefix')) ?></label>
						<input class="form-control" id="dPrefix" name="opt[prefix]" maxlength="30" placeholder="USD" value="<?= h($opt['prefix']) ?>">
					</div>
					<div class="col-md-2">
						<label class="form-label" for="dSuffix"><?= h(t('dash_suffix')) ?></label>
						<input class="form-control" id="dSuffix" name="opt[suffix]" maxlength="30" placeholder="h" value="<?= h($opt['suffix']) ?>">
					</div>
					<div class="col-12" data-dash-type="kpi">
						<span class="form-label d-block mb-1"><?= h(t('dash_thresholds')) ?><?= $tip(t('dash_thresholds_tip')) ?></span>
						<div class="d-flex flex-wrap align-items-center gap-2">
							<input class="form-control w-auto" type="number" step="any" name="opt[low]" id="dLow" placeholder="<?= h(t('dash_low')) ?>" aria-label="<?= h(t('dash_low')) ?>" value="<?= h((string)$opt['low']) ?>">
							<input class="form-control w-auto" type="number" step="any" name="opt[high]" id="dHigh" placeholder="<?= h(t('dash_high')) ?>" aria-label="<?= h(t('dash_high')) ?>" value="<?= h((string)$opt['high']) ?>">
							<div class="form-check form-switch ms-2"><input type="hidden" name="opt[higher_better]" value="0"><input class="form-check-input" type="checkbox" role="switch" id="dHigher" name="opt[higher_better]" value="1"<?= $opt['higher_better'] ? ' checked' : '' ?>>
								<label class="form-check-label" for="dHigher"><?= h(t('dash_higher_better')) ?></label></div>
						</div>
					</div>

					<div class="col-md-3">
						<label class="form-label" for="dLinkType"><?= h(t('dash_link')) ?></label><?= $tip(t('dash_link_tip')) ?>
						<select class="form-select" id="dLinkType" name="opt[link_type]">
							<?php foreach (NuDashboard::LINKS as $l): ?><option value="<?= $l ?>"<?= $sel($l === $opt['link_type']) ?>><?= h(t('dash_link_' . ($l ?: 'none'))) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="dLink"><?= h(t('dash_link_target')) ?></label>
						<input class="form-control<?= $inv('link') ?>" id="dLink" name="opt[link]" maxlength="500" list="dLinkList" value="<?= h($opt['link']) ?>">
						<datalist id="dLinkList" data-forms="<?= h(json_encode(array_map(static fn($f) => [$f['zzzzsys_form_id'], $f['sfo_code'] . ' – ' . $f['sfo_description']], $forms), JSON_UNESCAPED_UNICODE)) ?>"
							data-reports="<?= h(json_encode(array_map(static fn($r) => [$r['sre_code'], $r['sre_description']], $reports), JSON_UNESCAPED_UNICODE)) ?>"></datalist>
						<?= $fb('link') ?>
					</div>
					<div class="col-md-3">
						<div class="form-check form-switch mt-md-4 pt-md-2"><input type="hidden" name="opt[new_tab]" value="0"><input class="form-check-input" type="checkbox" role="switch" id="dNewTab" name="opt[new_tab]" value="1"<?= $opt['new_tab'] ? ' checked' : '' ?>>
							<label class="form-check-label" for="dNewTab"><?= h(t('dash_new_tab')) ?></label><?= $tip(t('dash_new_tab_tip')) ?></div>
					</div>
					<div class="col-md-3">
						<label class="form-label" for="dCache"><?= h(t('dash_cache')) ?></label><?= $tip(t('dash_cache_tip')) ?>
						<input class="form-control" type="number" min="0" max="86400" id="dCache" name="opt[cache]" value="<?= (int)$opt['cache'] ?>">
					</div>
					<div class="col-md-3">
						<label class="form-label" for="dRefresh"><?= h(t('dash_refresh_auto')) ?></label><?= $tip(t('dash_refresh_tip')) ?>
						<input class="form-control" type="number" min="0" max="86400" id="dRefresh" name="opt[refresh]" value="<?= (int)$opt['refresh'] ?>">
					</div>
				</div>
			</form>
		</div>
	</div>
	<div class="card shadow-sm mt-3" id="dashTestCard" hidden>
		<div class="card-header d-flex flex-wrap align-items-center gap-2">
			<span class="fw-semibold me-auto"><?= h(t('dash_test_result')) ?></span>
			<select class="form-select form-select-sm w-auto" id="dashTestRange" aria-label="<?= h(t('dash_range')) ?>">
				<?php foreach (NuDashboard::RANGES as $r): if ($r === 'custom') continue; ?><option value="<?= $r ?>"<?= $sel($r === ((string)$d['date_range'] ?: '30d')) ?>><?= h(t('dash_r_' . $r)) ?></option><?php endforeach; ?>
			</select>
			<input class="form-control form-control-sm w-auto" id="dashTestRecord" placeholder="#RECORD_ID#" aria-label="#RECORD_ID#" title="<?= h(t('dash_test_record')) ?>">
			<span class="small text-body-secondary" id="dashTestMs"></span>
		</div>
		<div class="card-body">
			<div class="row g-3">
				<div class="col-lg-6"><div class="dashPreview" id="dashTestPreview"></div></div>
				<div class="col-lg-6"><div class="table-responsive nuDashTable" id="dashTestTable"></div></div>
			</div>
			<div class="alert alert-danger small mb-0 mt-2" id="dashTestError" hidden></div>
		</div>
	</div>
	<span hidden id="dashShapes" data-kpi="<?= h(t('dash_shape_kpi')) ?>" data-chart="<?= h(t('dash_shape_chart')) ?>" data-table="<?= h(t('dash_shape_table')) ?>" data-forms-label="<?= h(t('dash_link_form')) ?>"></span>
	<?php
	nu_layout_end(NuDashboard::scriptTag() . nu_ts_foot() . '<script src="' . nu_asset('assets/js/nudashboards.js') . '"></script>');   // SQL builder select / procedure: searchable (0.8.536)
	return;
}

// ====================================================================== dashboard
$cur = $d ?? ['code' => '', 'title' => '', 'description' => '', 'on_home' => '0', 'access' => '[]', 'date_range' => '30d', 'sort_order' => 0];
if (nu_is_post() && nu_post('action') === 'save') $cur = ['code' => nu_post('code'), 'title' => nu_post('title'), 'description' => nu_post('dash_description'), 'on_home' => nu_check('on_home'),
	'access' => json_encode((array)($_POST['access'] ?? [])), 'date_range' => nu_post('date_range'), 'sort_order' => nu_post('sort_order')];
$acc = NuDashboard::access($cur);
$widgets = $d ? NuDashboard::widgets($id) : [];
$usage = $d ? NuDashboard::usage((string)$d['code']) : [];
nu_layout_start(t('dash_title'), 'home');
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="<?= h(nu_url('dashboards')) ?>"><?= h(t('dash_title')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h($d ? $d['code'] : t('dash_new')) ?></li></ol></nav>
<?php if ($d): ?>
<form method="post" action="<?= h(nu_url('dashboards', ['id' => $id])) ?>" id="dashCopy"><?= nu_csrf_field() ?><input type="hidden" name="action" value="copy"></form>
<form method="post" action="<?= h(nu_url('dashboards', ['id' => $id])) ?>" id="dashDelete" data-confirm="<?= h(t('dash_delete_confirm')) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="delete"></form>
<?php endif; ?>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold"><i class="bi bi-speedometer2 me-2"></i><?= h(t('dash_title_one')) ?></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button('dashboards', nu_url('dashboards')) ?>
			<button class="btn nuBtnAct nuBtnSave" type="submit" form="dashForm" data-nu-save id="dashSave"><i class="bi bi-floppy"></i><?= h(t('save')) ?></button>
			<?php if ($d): ?>
				<button class="btn nuBtnAct" type="submit" form="dashCopy"><i class="bi bi-copy"></i><?= h(t('clone')) ?></button>
				<button class="btn nuBtnAct nuBtnDel" type="submit" form="dashDelete"><i class="bi bi-trash"></i><?= h(t('delete')) ?></button>
				<?= NuFormInfo::record(NuDashboard::TD, (string)$id, ['code' => (string)($d['code'] ?? '')]) /* (i) record info (0.8.501) */ ?>
			<?php endif; ?>
		</div>
	</div>
	<div class="card-body">
		<form method="post" action="<?= h(nu_url('dashboards', ['id' => $d ? $id : 'new'])) ?>" id="dashForm" novalidate data-nu-guard>
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
			<div class="row g-3">
				<div class="col-md-3">
					<label class="form-label" for="dCode"><?= h(t('dash_code')) ?><?= nu_unique_mark() ?></label><?= $tip(t('dash_code_tip')) ?>
					<input class="form-control font-monospace<?= $inv('code') ?>" id="dCode" name="code" maxlength="50" required value="<?= h($cur['code']) ?>"><?= $fb('code') ?>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="dTitle"><?= h(t('dash_title_col')) ?></label><?= $tip(t('dash_title_tip')) ?>
					<input class="form-control" id="dTitle" name="title" maxlength="200" value="<?= h($cur['title']) ?>">
				</div>
				<div class="col-md-3">
					<label class="form-label" for="dSort"><?= h(t('dash_sort')) ?></label>
					<input class="form-control" type="number" id="dSort" name="sort_order" value="<?= (int)$cur['sort_order'] ?>">
				</div>
				<div class="col-12">
					<label class="form-label" for="dDesc"><?= h(t('dash_description')) ?></label>
					<input class="form-control" id="dDesc" name="description" maxlength="1000" value="<?= h($cur['description']) ?>">
				</div>
				<div class="col-md-6">
					<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="dHome" name="on_home" value="1"<?= (string)$cur['on_home'] === '1' ? ' checked' : '' ?>>
						<label class="form-check-label" for="dHome"><?= h(t('dash_on_home')) ?></label><?= $tip(t('dash_on_home_tip')) ?></div>
					<span class="form-label d-block mb-1"><?= h(t('dash_access')) ?><?= $tip(t('dash_access_tip')) ?></span>
					<div class="border rounded p-2 dashAccessList">
						<?php if (!$accessLevels): ?><span class="small text-body-secondary"><?= h(t('no_records')) ?></span><?php endif; ?>
						<?php foreach ($accessLevels as $a): $aid = (string)$a['zzzzsys_access_id']; ?>
							<div class="form-check"><input class="form-check-input" type="checkbox" name="access[]" id="dAcc_<?= h($aid) ?>" value="<?= h($aid) ?>"<?= in_array($aid, $acc, true) ? ' checked' : '' ?>>
								<label class="form-check-label" for="dAcc_<?= h($aid) ?>"><span class="fw-semibold"><?= h($a['sal_code']) ?></span> <span class="text-body-secondary small"><?= h($a['sal_description']) ?></span></label></div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="dRange"><?= h(t('dash_default_range')) ?></label><?= $tip(t('dash_default_range_tip')) ?>
					<select class="form-select" id="dRange" name="date_range">
						<?php foreach (NuDashboard::RANGES as $r): if ($r === 'custom') continue; ?><option value="<?= $r ?>"<?= $r === ((string)$cur['date_range'] ?: '30d') ? ' selected' : '' ?>><?= h(t('dash_r_' . $r)) ?></option><?php endforeach; ?>
					</select>
					<?php if ($d): ?>
						<label class="form-label mt-3" for="dSnippet"><?= h(t('dash_place')) ?></label><?= $tip(t('dash_place_tip')) ?>
						<input class="form-control font-monospace dashSnippet" id="dSnippet" readonly value="<?= h('<div data-nu-dashboard="' . $d['code'] . '"></div>') ?>">
						<div class="small mt-2"><span class="text-body-secondary"><?= h(t('dash_used_in')) ?></span>
							<?= $usage ? h(implode(', ', array_map(static fn($x) => $x[0] . ' (' . $x[1] . ')', $usage))) : '<span class="text-body-secondary">–</span>' ?></div>
					<?php endif; ?>
				</div>
			</div>
		</form>
	</div>
</div>

<?php if ($d): ?>
<div class="card shadow-sm mt-3" id="dashWidgets">
	<div class="card-header nuTitleBar">
		<span class="fw-semibold"><?= h(t('dash_widgets')) ?></span>
		<div class="d-flex flex-wrap gap-2"><a class="btn nuAddBtn" id="dashAddWidget" href="<?= h(nu_url('dashboards', ['id' => $id, 'w' => 'new'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= h(t('dash_w_add')) ?></a></div>
	</div>
	<div class="table-responsive">
		<table class="table align-middle mb-0 nu-table">
			<thead><tr><th><?= h(t('dash_w_title_field')) ?></th><th><?= h(t('dash_type')) ?></th><th><?= h(t('dash_source')) ?></th><th><?= h(t('dash_width')) ?></th><th class="text-end"></th></tr></thead>
			<tbody>
			<?php if (!$widgets): ?><tr><td colspan="5" class="text-center text-body-secondary py-4"><?= h(t('dash_no_widgets')) ?></td></tr><?php endif; ?>
			<?php foreach ($widgets as $i => $x): $wid2 = (string)$x['zzzzsys_nunext_widget_id']; $edit = nu_url('dashboards', ['id' => $id, 'w' => $wid2]); ?>
				<tr class="dashWidgetRow">
					<td class="fw-semibold"><a class="text-decoration-none" href="<?= h($edit) ?>"><?= h($x['title'] !== '' ? $x['title'] : '–') ?></a></td>
					<td><?= h(t('dash_type_' . $x['type'])) ?><?php if ($x['type'] === 'chart'): ?> <span class="text-body-secondary small">(<?= h(t('dash_chart_' . $x['opt']['chart'])) ?>)</span><?php endif; ?></td>
					<td class="small"><?= h(t('dash_src_' . $x['source'])) ?></td>
					<td class="small"><?= h(t('dash_width_' . $x['opt']['width'])) ?></td>
					<td class="text-end text-nowrap">
						<form method="post" action="<?= h(nu_url('dashboards', ['id' => $id])) ?>" class="d-inline"><?= nu_csrf_field() ?><input type="hidden" name="action" value="wmove"><input type="hidden" name="widget" value="<?= h($wid2) ?>">
							<button class="btn btn-sm btn-outline-secondary" name="dir" value="up" title="<?= h(t('home_move_up')) ?>" aria-label="<?= h(t('home_move_up')) ?>"<?= $i === 0 ? ' disabled' : '' ?>><i class="bi bi-arrow-up"></i></button>
							<button class="btn btn-sm btn-outline-secondary" name="dir" value="down" title="<?= h(t('home_move_down')) ?>" aria-label="<?= h(t('home_move_down')) ?>"<?= $i === count($widgets) - 1 ? ' disabled' : '' ?>><i class="bi bi-arrow-down"></i></button>
						</form>
						<a class="btn btn-sm btn-outline-secondary" href="<?= h($edit) ?>" title="<?= h(t('edit')) ?>" aria-label="<?= h(t('edit')) ?>"><i class="bi bi-pencil"></i></a>
						<form method="post" action="<?= h(nu_url('dashboards', ['id' => $id])) ?>" class="d-inline" data-confirm="<?= h(t('dash_w_delete_confirm')) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
							<?= nu_csrf_field() ?><input type="hidden" name="action" value="wdelete"><input type="hidden" name="widget" value="<?= h($wid2) ?>">
							<button class="btn btn-sm btn-outline-danger" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete')) ?>"><i class="bi bi-trash"></i></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php if ($widgets): ?>
<div class="card shadow-sm mt-3"><div class="card-header fw-semibold"><?= h(t('brand_preview')) ?></div>
	<div class="card-body"><div data-nu-dashboard="<?= h($d['code']) ?>"></div></div></div>
<?php $foot = NuDashboard::scriptTag(); endif; ?>
<?php endif; ?>
<?php
nu_layout_end($foot);
