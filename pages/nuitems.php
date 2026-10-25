<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Items (zzzzsys_item) – nuBuilder's form "nuitem": values for lists, menus etc., optionally bound to an object.
 * Rights of the form "nuitem". With nuBuilder's "set_denied" = 1, nuBuilder's own items (ids "nu…") are hidden.
 *   list, ?id=<id> | ?id=new edit, ?objects=<search> JSON (object lookup)
 */

NuAcl::require(NuAcl::FORM_ITEM);
$db     = nu_db();
$rights = NuAcl::rights(NuAcl::FORM_ITEM);
$T      = 'zzzzsys_item';
NuItem::ensureTable();
$denied = (string)(NuMailer::setupRow()['set_denied'] ?? '') === '1';
$hideNu = $denied ? " AND i.zzzzsys_item_id NOT LIKE 'nu%'" : '';

// ------------------------------------------------------------------ object lookup (JSON)
if (isset($_GET['objects'])) {
	header('Content-Type: application/json; charset=utf-8');
	$q = trim((string)$_GET['objects']);
	$sql = "SELECT o.zzzzsys_object_id, o.sob_all_id, o.sob_all_label, o.sob_all_type, f.sfo_code FROM zzzzsys_object o
		LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id";
	$params = [];
	if ($q !== '') {
		$sql .= " WHERE (o.sob_all_id LIKE ? ESCAPE '!' OR o.sob_all_label LIKE ? ESCAPE '!' OR f.sfo_code LIKE ? ESCAPE '!' OR o.zzzzsys_object_id = ?)";
		$params = [NuDB::like($q), NuDB::like($q), NuDB::like($q), $q];
	}
	$rows = $db->page($sql, $params, 'f.sfo_code, o.sob_all_id', 50, 0);
	echo json_encode(array_map(static fn($r) => ['value' => $r['zzzzsys_object_id'], 'text' => trim(($r['sfo_code'] ? $r['sfo_code'] . ' · ' : '') . $r['sob_all_id']),
		'label' => trim(strip_tags((string)$r['sob_all_label'])), 'type' => $r['sob_all_type']], $rows), JSON_UNESCAPED_UNICODE);
	exit;
}

$id    = (string)($_GET['id'] ?? '');
$isNew = $id === 'new';
$item  = null;
if ($id !== '' && !$isNew) {
	$item = $db->one("SELECT * FROM $T i WHERE i.zzzzsys_item_id = ?$hideNu", [$id]);
	if (!$item) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
$errors = [];

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		NuAcl::require(NuAcl::FORM_ITEM, $item ? 'save' : 'create');
		$in = [];
		foreach (array_keys(NuItem::FIELDS) as $k) $in[$k] = is_string($_POST[$k] ?? null) ? str_replace("\r\n", "\n", (string)$_POST[$k]) : '';
		$in['itm_active'] = nu_check('itm_active');
		[$newId, $errors] = NuItem::save($in, $item ? $id : '');
		if (!$errors) {
			$after = $db->one("SELECT * FROM $T WHERE zzzzsys_item_id = ?", [$newId]) ?? [];
			NuLog::write($item ? 'item_changed' : 'item_created', $T, (string)$newId, $item ? ['fields' => NuLog::diff($item, $after)] : ['value' => $in['itm_value']]);
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('items', ['id' => $newId, 'tab' => nu_post('tab') ?: null]));
		}
	}
	if ($action === 'clone' && $item) {
		NuAcl::require(NuAcl::FORM_ITEM, 'clone');
		$copy = $item;
		unset($copy['zzzzsys_item_id']);
		[$newId, $e] = NuItem::save($copy);
		if ($newId) {
			NuLog::write('item_created', $T, $newId, ['clone_of' => $id]);
			nu_flash('success', t('item_cloned'));
			nu_redirect(nu_url('items', ['id' => $newId]));
		}
		$errors = $e;
	}
	if ($action === 'delete' && $item) {
		NuAcl::require(NuAcl::FORM_ITEM, 'delete');
		$db->delete($T, 'zzzzsys_item_id', $id);
		NuLog::write('item_deleted', $T, $id, ['value' => (string)$item['itm_value']]);
		nu_flash('success', t('item_deleted'));
		nu_redirect(nu_url('items'));
	}
}

// ------------------------------------------------------------------ list (also Print / CSV)
if ($id === '') {
	// list: columns, filters and sort of the chosen list view (0.8.334, core/nulistdefs.php)
	NuListView::page('items', [
		'before' => NuListView::crumbs([[t('nav_forms'), nu_url('forms')], [t('item_title')]]), 'active' => 'forms', 'head' => NuBuilderLib::fontAwesomeTag(),
		'buttons' => $rights['add'] ? '<a class="btn nuAddBtn" title="' . h(t('item_new')) . '" href="' . h(nu_url('items', ['id' => 'new'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '',
	]);
	return;
}

$head = NuBuilderLib::fontAwesomeTag() . '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">';
nu_layout_start(t('item_title'), 'forms', ['head' => $head]);
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
		<?php if ($id !== ''): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('items')) ?>"><?= h(t('item_title')) ?></a></li>
			<li class="breadcrumb-item active" aria-current="page"><?= h($item ? (string)$item['itm_value'] : t('item_new')) ?></li>
		<?php else: ?>
			<li class="breadcrumb-item active" aria-current="page"><?= h(t('item_title')) ?></li>
		<?php endif; ?>
	</ol>
</nav>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php endif; ?>

<?php if ($id === ''): ?>
<?php else: ?>
	<?php
	// ------------------------------------------------------------------ edit (Main / Additional, like nuBuilder)
	$v = static fn(string $k) => nu_is_post() && nu_post('action') === 'save' ? (string)($_POST[$k] ?? '') : (string)($item[$k] ?? '');
	$locked = !($item ? $rights['save'] : $rights['create']);
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$fb = static fn(string $k) => '<div class="invalid-feedback">' . h($errors[$k] ?? '') . '</div>';
	$tab = (string)($_GET['tab'] ?? (nu_is_post() ? nu_post('tab') : 'main')) === 'additional' ? 'additional' : 'main';
	$obj = null;
	$objId = $v('itm_object_id');
	if ($objId !== '') $obj = $db->one('SELECT o.zzzzsys_object_id, o.sob_all_id, o.sob_all_label, f.sfo_code FROM zzzzsys_object o LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id WHERE o.zzzzsys_object_id = ?', [$objId]);
	$active = nu_is_post() ? nu_check('itm_active') === '1' : ($item ? (string)$item['itm_active'] !== '0' : true);
	$dt = static fn($x) => $x ? date('d.m.Y H:i:s', strtotime((string)$x)) : '';
	$row = static function (string $k, string $label, string $control, bool $req = false) {
		return '<div class="row g-2 mb-3 align-items-start"><label class="col-sm-3 col-form-label text-sm-end" for="' . $k . '">' . h($label)
			. ($req ? ' <span class="text-danger">*</span>' : '') . '</label><div class="col-sm-9">' . $control . '</div></div>';
	};
	?>
	<form method="post" data-nu-focus-first data-nu-guard id="itemForm" novalidate>
		<?= nu_csrf_field() ?>
		<input type="hidden" name="action" value="save">
		<input type="hidden" name="tab" id="itemTab" value="<?= h($tab) ?>">
		<div class="nuTitleBar mb-3">
			<h1 class="h4 mb-0"><?= h($item ? (string)$item['itm_value'] : t('item_new')) ?></h1>
			<div class="d-flex flex-wrap gap-2 align-items-center">   <!-- buttons in their own row (0.8.474) -->
			<a class="btn nuBtnAct" href="<?= h(nu_url('items')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
			<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" id="itemSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
			<?php if ($item && $rights['clone']): ?>
				<button class="btn nuBtnAct" type="submit" name="action" value="clone" formnovalidate><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></button>
			<?php endif; ?>
			<?php if ($item && $rights['delete']): ?>
				<button class="btn nuBtnAct nuBtnDel" type="submit" name="action" value="delete" formnovalidate data-confirm-submit="<?= h(t('item_delete_confirm')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
			<?php if ($item): ?><?= NuFormInfo::record($T, (string)$id) /* (i) record info (0.8.501) */ ?><?php endif; ?>
			</div>
		</div>
		<div class="card shadow-sm itemCard">
			<div class="card-header pb-0 border-bottom-0">
				<ul class="nav nav-tabs card-header-tabs" role="tablist">
					<li class="nav-item" role="presentation"><button class="nav-link<?= $tab === 'main' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#itemMain" data-tab="main" type="button" role="tab"><?= h(t('item_tab_main')) ?></button></li>
					<li class="nav-item" role="presentation"><button class="nav-link<?= $tab === 'additional' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#itemAdditional" data-tab="additional" type="button" role="tab" id="itemAdditionalTab"><?= h(t('item_tab_additional')) ?></button></li>
				</ul>
			</div>
			<fieldset class="card-body tab-content"<?= $locked ? ' disabled' : '' ?>>
				<div class="tab-pane fade<?= $tab === 'main' ? ' show active' : '' ?>" id="itemMain" role="tabpanel">
					<?= $row('itm_value', t('item_value'), '<input class="form-control' . $err('itm_value') . '" id="itm_value" name="itm_value" maxlength="1000" required value="' . h($v('itm_value')) . '"' . ($isNew ? ' autofocus' : '') . '>' . $fb('itm_value'), true) ?>
					<?= $row('itm_description', t('item_description'), '<input class="form-control" id="itm_description" name="itm_description" value="' . h($v('itm_description')) . '">') ?>
					<?= $row('itm_group', t('item_group'), '<input class="form-control' . $err('itm_group') . '" id="itm_group" name="itm_group" maxlength="100" list="itemGroups" value="' . h($v('itm_group')) . '">' . $fb('itm_group')
						. '<datalist id="itemGroups">' . implode('', array_map(static fn($g) => '<option value="' . h($g) . '">', array_column($db->all("SELECT DISTINCT itm_group FROM $T WHERE itm_group IS NOT NULL ORDER BY itm_group"), 'itm_group'))) . '</datalist>') ?>
					<?= $row('itm_category', t('item_category'), '<input class="form-control' . $err('itm_category') . '" id="itm_category" name="itm_category" maxlength="255" value="' . h($v('itm_category')) . '">' . $fb('itm_category')) ?>
					<?= $row('itm_object_id', t('item_object'), '<select id="itm_object_id" name="itm_object_id" class="itemObject' . $err('itm_object_id') . '" data-url="' . h(nu_url('items') . '&objects=') . '" placeholder="' . h(t('item_object_ph')) . '">'
						. '<option value=""></option>' . ($obj ? '<option value="' . h($obj['zzzzsys_object_id']) . '" selected>' . h(trim(($obj['sfo_code'] ? $obj['sfo_code'] . ' · ' : '') . $obj['sob_all_id'])) . '</option>' : ($objId !== '' ? '<option value="' . h($objId) . '" selected>' . h($objId) . '</option>' : ''))
						. '</select>' . $fb('itm_object_id') . ($obj && trim(strip_tags((string)$obj['sob_all_label'])) !== '' ? '<div class="form-text">' . h(strip_tags((string)$obj['sob_all_label'])) . '</div>' : '')) ?>
					<?= $row('itm_sort_order', t('item_sort_order'), '<input class="form-control itemSort' . $err('itm_sort_order') . '" id="itm_sort_order" name="itm_sort_order" inputmode="numeric" value="' . h($v('itm_sort_order')) . '">' . $fb('itm_sort_order')) ?>
					<?= $row('itm_tag', t('item_tags'), '<input class="form-control' . $err('itm_tag') . '" id="itm_tag" name="itm_tag" maxlength="1000" value="' . h($v('itm_tag')) . '">' . $fb('itm_tag')) ?>
					<?= $row('itm_additional1', t('item_additional1'), '<input class="form-control" id="itm_additional1" name="itm_additional1" value="' . h($v('itm_additional1')) . '">') ?>
					<?= $row('itm_additional2', t('item_additional2'), '<textarea class="form-control' . $err('itm_additional2') . '" id="itm_additional2" name="itm_additional2" rows="6" maxlength="2000">' . h($v('itm_additional2')) . '</textarea>' . $fb('itm_additional2')) ?>
					<?= $row('itm_notes', t('item_notes'), '<textarea class="form-control' . $err('itm_notes') . '" id="itm_notes" name="itm_notes" rows="3" maxlength="2000">' . h($v('itm_notes')) . '</textarea>' . $fb('itm_notes')) ?>
					<?= $row('itm_active', t('item_active'), '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="itm_active" name="itm_active" value="1"' . ($active ? ' checked' : '') . '></div>') ?>
				</div>
				<div class="tab-pane fade<?= $tab === 'additional' ? ' show active' : '' ?>" id="itemAdditional" role="tabpanel">
					<?= $row('itm_css', t('item_css'), '<input class="form-control' . $err('itm_css') . '" id="itm_css" name="itm_css" maxlength="100" value="' . h($v('itm_css')) . '">' . $fb('itm_css')) ?>
					<?= $row('itm_icon', t('item_icon'), '<div class="input-group"><span class="input-group-text itemIconPreview" id="itemIconPreview"><i class="' . h($v('itm_icon')) . '"></i></span><input class="form-control' . $err('itm_icon') . '" id="itm_icon" name="itm_icon" maxlength="100" value="' . h($v('itm_icon')) . '" placeholder="bi bi-star / fa-solid fa-star"></div>' . $fb('itm_icon')) ?>
					<?= $row('itm_language', t('item_language'), '<input class="form-control' . $err('itm_language') . '" id="itm_language" name="itm_language" maxlength="25" value="' . h($v('itm_language')) . '">' . $fb('itm_language')) ?>
					<?= $row('itm_parent_id', t('item_parent'), '<input class="form-control' . $err('itm_parent_id') . '" id="itm_parent_id" name="itm_parent_id" maxlength="25" value="' . h($v('itm_parent_id')) . '">' . $fb('itm_parent_id')) ?>
					<?= $row('itm_access_level', t('item_access_level'), '<input class="form-control' . $err('itm_access_level') . '" id="itm_access_level" name="itm_access_level" maxlength="2000" value="' . h($v('itm_access_level')) . '">' . $fb('itm_access_level')) ?>
					<?= $row('itm_created_on', t('item_created_on'), '<input class="form-control" id="itm_created_on" readonly tabindex="-1" value="' . h($dt($item['itm_created_on'] ?? null)) . '">') ?>
					<?= $row('itm_updated_on', t('item_updated_on'), '<input class="form-control" id="itm_updated_on" readonly tabindex="-1" value="' . h($dt($item['itm_updated_on'] ?? null)) . '">') ?>
				</div>
			</fieldset>
		</div>
	</form>
<?php endif; ?>
<?php
nu_layout_end($id !== '' ? '<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nuitems.js') . '"></script>' : '');
