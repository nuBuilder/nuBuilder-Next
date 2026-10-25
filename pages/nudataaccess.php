<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Data access of a form (0.8.480, handover specs 04 + 07): ?p=dataaccess&f=<form id>
 *   Fields        – default mode and rules per field (core/nufieldaccess.php, table zzzzsys_nunext_object_access)
 *   List columns  – visibility of the browse columns per subject (core/nubrowseaccess.php, sbr_json → nunext.access)
 *   Access level  – all fields and columns of the form for one access level (writes the "access" rules of that level)
 *   Preview       – what a user gets, and which rule decided it
 * Rights: the form builder (nuform) – view to look, save to change.
 */

NuAcl::require(NuAcl::FORM_FORM);
$canSave = NuAcl::can(NuAcl::FORM_FORM, 'save') && !NuImpersonate::active();
$meta = NuFormMeta::load((string)($_GET['f'] ?? ''));
if (!$meta) { http_response_code(404); nu_fatal(t('form_err_not_found'), '', true); }
NuSchema::ensure();

// fields of the form and of its sub-forms: [form meta, object] per object id
$groups = [[$meta, '']];
foreach ($meta->objects as $o) {
	if ($o['sob_all_type'] !== 'subform') continue;
	$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
	if ($sub && $sub->id !== $meta->id) $groups[] = [$sub, (string)$o['sob_all_id']];
}
$fields = [];
foreach ($groups as [$m]) foreach ($m->objects as $o) {
	if (in_array($o['sob_all_type'], NuFieldAccess::FIELD_TYPES, true) && ($o['sob_input_type'] ?? '') !== 'button') $fields[(string)$o['zzzzsys_object_id']] = $o;
}
$browse = [];
foreach ($meta->browse as $b) $browse[(string)$b['zzzzsys_browse_id']] = $b;
$levels = [];
foreach (NuSubject::catalog()['access'] as [$id, $label]) $levels[$id] = $label;
$back = nu_url('dataaccess', ['f' => $meta->id]);

/** Rows of a posted rules table (rules[<key>][n][type|subject|mode|…]). */
$posted = static function (array $rows, bool $columns = false): array {
	$out = [];
	foreach ($rows as $r) {
		if (!is_array($r) || !empty($r['remove'])) continue;
		$out[] = $columns ? ['type' => (string)($r['type'] ?? ''), 'subject' => (string)($r['subject'] ?? ''), 'mode' => (string)($r['mode'] ?? '')]
			: ['type' => (string)($r['type'] ?? ''), 'subject' => (string)($r['subject'] ?? ''), 'mode' => (string)($r['mode'] ?? ''), 'mask' => (string)($r['mask'] ?? ''),
				'condition' => (string)($r['condition'] ?? ''), 'note' => (string)($r['note'] ?? '')];
	}
	return $out;
};

if (nu_is_post()) {
	if (!$canSave) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
	$a = nu_post('action');
	try {
		if ($a === 'save_fields') {
			foreach ((array)($_POST['rules'] ?? []) as $oid => $rows) {
				if (!isset($fields[(string)$oid])) continue;
				NuFieldAccess::saveObject($fields[(string)$oid], $posted((array)$rows));
			}
			$tab = 'fields';
		} elseif ($a === 'save_columns') {
			foreach ((array)($_POST['cols'] ?? []) as $bid => $c) {
				if (!isset($browse[(string)$bid]) || !is_array($c)) continue;
				NuBrowseAccess::save((string)$bid, (string)($c['default'] ?? 'show'), $posted((array)($c['rules'] ?? []), true));
			}
			$tab = 'columns';
		} elseif ($a === 'save_level') {
			$lvl = nu_post('level');
			if (!isset($levels[$lvl])) throw new RuntimeException(t('err_invalid'));
			foreach ((array)($_POST['lf'] ?? []) as $oid => $mode) {
				$o = $fields[(string)$oid] ?? null;
				if ($o === null) continue;
				$rules = array_values(array_filter(NuFieldAccess::rulesOf($o), static fn($r) => !($r['type'] === 'access' && $r['subject'] === $lvl)));
				if (in_array((string)$mode, NuFieldAccess::MODES, true)) $rules[] = ['type' => 'access', 'subject' => $lvl, 'mode' => (string)$mode];
				NuFieldAccess::saveObject($o, $rules);
			}
			foreach ((array)($_POST['lc'] ?? []) as $bid => $mode) {
				$b = $browse[(string)$bid] ?? null;
				if ($b === null) continue;
				$cur = NuBrowseAccess::rules($b) ?? ['default' => 'show', 'rules' => []];
				$rules = array_values(array_filter($cur['rules'], static fn($r) => !($r['type'] === 'access' && $r['subject'] === $lvl)));
				if (in_array((string)$mode, ['show', 'hide'], true)) $rules[] = ['type' => 'access', 'subject' => $lvl, 'mode' => (string)$mode];
				NuBrowseAccess::save((string)$bid, $cur['default'], $rules);
			}
			$tab = 'level';
		} else {
			throw new RuntimeException(t('err_invalid'));
		}
		nu_flash('success', t('da_saved'));
	} catch (Throwable $e) {
		nu_flash('danger', t('form_error') . ' ' . $e->getMessage());
		$tab = 'fields';
	}
	nu_redirect(nu_url('dataaccess', ['f' => $meta->id, 'tab' => $tab, 'lvl' => $a === 'save_level' ? nu_post('level') : null]) . '#da_' . $tab);
}

NuFieldAccess::reset();
$cat = NuSubject::catalog();
$typeOpts = ['default' => t('da_type_default'), 'access' => t('da_type_access'), 'user' => t('da_type_user'), 'perm' => t('da_type_perm'), 'team' => t('da_type_team'), 'dept' => t('da_type_dept')];
$modeLabel = static fn(string $m): string => t('da_mode_' . $m);
$nubAccess = static fn(array $o): string => (string)$o['sob_all_access'] === '9' ? t('da_nub_condition') : t('bf_access_' . (in_array((string)$o['sob_all_access'], ['0', '1', '2', '3', '4'], true) ? (string)$o['sob_all_access'] : '0'));
$label = static fn(array $o): string => trim(strip_tags((string)$o['sob_all_label'])) ?: (string)$o['sob_all_id'];
$subjectName = static function (string $type, string $subject) use ($cat): string {
	foreach ($cat[$type] ?? [] as [$v, $l]) if ((string)$v === $subject) return $l;
	return $subject;
};
$sel = static function (string $name, array $opts, string $cur, string $cls = 'form-select form-select-sm', string $extra = ''): string {
	$h = '<select class="' . $cls . '" name="' . h($name) . '"' . $extra . '>';
	foreach ($opts as $v => $l) $h .= '<option value="' . h((string)$v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . h($l) . '</option>';
	return $h . '</select>';
};
$modes = [];
foreach (NuFieldAccess::MODES as $m) $modes[$m] = $modeLabel($m);
$tab = in_array($_GET['tab'] ?? '', ['fields', 'columns', 'level', 'preview'], true) ? (string)$_GET['tab'] : 'fields';
$ro = $canSave ? '' : ' disabled';

/** One rule row of a field (or the template with __N__). */
$fieldRow = static function (string $oid, string $n, array $r) use ($sel, $typeOpts, $modes, $ro): string {
	$p = 'rules[' . $oid . '][' . $n . ']';
	$type = (string)($r['type'] ?? 'access');
	return '<tr class="daRule">'
		. '<td>' . $sel($p . '[type]', $typeOpts, $type, 'form-select form-select-sm daType', $ro . ' aria-label="' . h(t('da_type')) . '"') . '</td>'
		. '<td><input class="form-control form-control-sm daSubject" name="' . h($p . '[subject]') . '" value="' . h((string)($r['subject'] ?? '')) . '" list="da_dl_' . h($type) . '" aria-label="' . h(t('da_subject')) . '"' . ($type === 'default' ? ' disabled' : '') . $ro . '></td>'
		. '<td>' . $sel($p . '[mode]', $modes, (string)($r['mode'] ?? 'prot'), 'form-select form-select-sm daMode', $ro . ' aria-label="' . h(t('da_mode')) . '"') . '</td>'
		. '<td><input class="form-control form-control-sm" name="' . h($p . '[mask]') . '" value="' . h((string)($r['mask'] ?? '')) . '" placeholder="last:4" aria-label="' . h(t('da_mask')) . '"' . $ro . '></td>'
		. '<td><input class="form-control form-control-sm font-monospace" name="' . h($p . '[condition]') . '" value="' . h((string)($r['condition'] ?? '')) . '" aria-label="' . h(t('da_condition')) . '"' . $ro . '></td>'
		. '<td><input class="form-control form-control-sm" name="' . h($p . '[note]') . '" value="' . h((string)($r['note'] ?? '')) . '" aria-label="' . h(t('da_note')) . '"' . $ro . '></td>'
		. '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . h($p . '[remove]') . '" value="1" aria-label="' . h(t('da_remove')) . '"' . $ro . '></td></tr>';
};
$colRow = static function (string $bid, string $n, array $r) use ($sel, $typeOpts, $ro): string {
	$p = 'cols[' . $bid . '][rules][' . $n . ']';
	$type = (string)($r['type'] ?? 'access');
	return '<tr class="daRule">'
		. '<td>' . $sel($p . '[type]', array_diff_key($typeOpts, ['default' => 1]), $type, 'form-select form-select-sm daType', $ro . ' aria-label="' . h(t('da_type')) . '"') . '</td>'
		. '<td><input class="form-control form-control-sm daSubject" name="' . h($p . '[subject]') . '" value="' . h((string)($r['subject'] ?? '')) . '" list="da_dl_' . h($type) . '" aria-label="' . h(t('da_subject')) . '"' . $ro . '></td>'
		. '<td>' . $sel($p . '[mode]', ['hide' => t('da_hide'), 'show' => t('da_show')], (string)($r['mode'] ?? 'hide'), 'form-select form-select-sm', $ro . ' aria-label="' . h(t('da_mode')) . '"') . '</td>'
		. '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . h($p . '[remove]') . '" value="1" aria-label="' . h(t('da_remove')) . '"' . $ro . '></td></tr>';
};
$tip = static fn(string $text): string => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';

nu_layout_start(t('da_title_form', $meta->description()), 'forms');
?>
<div class="nuTitleBar">
	<h1 class="h4 mb-0"><i class="bi bi-shield-lock me-2"></i><?= h(t('da_title_form', $meta->description())) ?></h1>
	<div class="nuTitleSub small text-body-secondary"><?= h($meta->code()) ?><?= $meta->table() !== '' ? ' · ' . h($meta->table()) : '' ?></div>
	<div class="d-flex flex-wrap gap-2">
		<?= nu_back_button(null, nu_url('builder', ['f' => $meta->id])) ?>
	</div>
</div>
<?php foreach ($cat as $type => $list): ?>
	<datalist id="da_dl_<?= h($type) ?>"><?php foreach ($list as [$v, $l]): ?><option value="<?= h((string)$v) ?>"><?= h($l) ?></option><?php endforeach; ?></datalist>
<?php endforeach; ?>

<ul class="nav nav-tabs mt-3" role="tablist" id="daTabs">
	<?php foreach (['fields' => 'da_tab_fields', 'columns' => 'da_tab_columns', 'level' => 'da_tab_level', 'preview' => 'da_tab_preview'] as $k => $key): ?>
		<li class="nav-item" role="presentation"><button class="nav-link<?= $tab === $k ? ' active' : '' ?>" id="da_<?= $k ?>_tab" data-bs-toggle="tab" data-bs-target="#da_<?= $k ?>" type="button" role="tab" aria-controls="da_<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= h(t($key)) ?></button></li>
	<?php endforeach; ?>
</ul>
<div class="tab-content card card-body border-top-0 rounded-top-0 shadow-sm mb-4">
	<!-- Fields -->
	<div class="tab-pane fade<?= $tab === 'fields' ? ' show active' : '' ?>" id="da_fields" role="tabpanel" aria-labelledby="da_fields_tab">
		<p class="small text-body-secondary"><?= h(t('da_intro_fields')) ?><?= $tip(t('da_modes_tip')) ?></p>
		<?php if (!$fields): ?><p class="text-body-secondary"><?= h(t('da_nothing')) ?></p><?php else: ?>
		<form method="post" action="<?= h($back) ?>" data-nu-guard id="daFieldsForm">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="save_fields">
			<?php foreach ($groups as [$m, $sfId]):
				$objs = array_filter($fields, static fn($o) => (string)$o['sob_all_zzzzsys_form_id'] === $m->id);
				if (!$objs) continue; ?>
				<?php if ($sfId !== ''): ?><h2 class="h6 mt-4"><?= h(t('da_subform', $sfId . ' – ' . $m->description())) ?></h2><?php endif; ?>
				<?php foreach ($objs as $oid => $o): $rules = NuFieldAccess::rulesOf($o); ?>
					<details class="daField border rounded mb-2"<?= $rules ? ' open' : '' ?> id="da_o_<?= h($oid) ?>" data-oid="<?= h($oid) ?>">
						<summary class="px-3 py-2 d-flex flex-wrap gap-2 align-items-center">
							<code><?= h((string)$o['sob_all_id']) ?></code> <span><?= h($label($o)) ?></span>
							<span class="badge text-bg-light border">nuBuilder: <?= h($nubAccess($o)) ?></span>
							<?php foreach ($rules as $r): ?>
								<span class="badge <?= in_array($r['mode'], ['wh', 'mask', 'prot'], true) ? 'text-bg-warning' : 'text-bg-secondary' ?>"><?= h(($r['type'] === 'default' ? t('da_type_default') : t('da_type_' . $r['type']) . ' ' . $subjectName($r['type'], $r['subject'])) . ': ' . $modeLabel($r['mode'])) ?></span>
							<?php endforeach; ?>
						</summary>
						<div class="px-3 pb-3">
							<div class="table-responsive"><table class="table table-sm align-middle mb-2 daRules">
								<thead><tr><th><?= h(t('da_type')) ?></th><th><?= h(t('da_subject')) ?></th><th><?= h(t('da_mode')) ?></th><th><?= h(t('da_mask')) ?><?= $tip(t('da_mask_tip')) ?></th>
									<th><?= h(t('da_condition')) ?><?= $tip(t('da_condition_tip')) ?></th><th><?= h(t('da_note')) ?></th><th class="text-center"><?= h(t('da_remove')) ?></th></tr></thead>
								<tbody><?php foreach ($rules as $i => $r) echo $fieldRow($oid, (string)$i, $r); ?></tbody>
							</table></div>
							<?php if ($canSave): ?>
								<template class="daTpl"><?= $fieldRow($oid, '__N__', ['type' => $rules ? 'access' : 'default', 'mode' => 'prot']) ?></template>
								<button type="button" class="btn btn-sm btn-outline-secondary daAdd"><i class="bi bi-plus-lg me-1"></i><?= h(t('da_add_rule')) ?></button>
							<?php endif; ?>
							<?php if (!$rules): ?><span class="small text-body-secondary ms-2"><?= h(t('da_no_rules')) ?></span><?php endif; ?>
						</div>
					</details>
				<?php endforeach; ?>
			<?php endforeach; ?>
			<?php if ($canSave): ?><button class="btn nuBtnAct nuBtnSave mt-2" type="submit" data-nu-save id="daSaveFields"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		</form>
		<?php endif; ?>
	</div>

	<!-- List columns -->
	<div class="tab-pane fade<?= $tab === 'columns' ? ' show active' : '' ?>" id="da_columns" role="tabpanel" aria-labelledby="da_columns_tab">
		<p class="small text-body-secondary"><?= h(t('da_intro_columns')) ?></p>
		<?php if (!$browse): ?><p class="text-body-secondary"><?= h(t('da_no_columns')) ?></p><?php else: ?>
		<form method="post" action="<?= h($back) ?>" data-nu-guard id="daColsForm">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="save_columns">
			<?php foreach ($browse as $bid => $b): $acc = NuBrowseAccess::rules($b); $fo = NuFieldAccess::columnObject($meta, (string)$b['sbr_display']); ?>
				<details class="daField border rounded mb-2"<?= $acc ? ' open' : '' ?> id="da_c_<?= h($bid) ?>">
					<summary class="px-3 py-2 d-flex flex-wrap gap-2 align-items-center">
						<strong><?= h(trim(strip_tags((string)$b['sbr_title'])) ?: '–') ?></strong> <code class="small"><?= h(mb_strimwidth((string)$b['sbr_display'], 0, 60, '…')) ?></code>
						<?php if ($acc): ?><span class="badge <?= $acc['default'] === 'hide' ? 'text-bg-warning' : 'text-bg-secondary' ?>"><?= h(t('da_col_default') . ': ' . t($acc['default'] === 'hide' ? 'da_hide' : 'da_show')) ?></span><?php endif; ?>
						<?php foreach ($acc['rules'] ?? [] as $r): ?><span class="badge text-bg-secondary"><?= h(t('da_type_' . $r['type']) . ' ' . $subjectName($r['type'], $r['subject']) . ': ' . t($r['mode'] === 'hide' ? 'da_hide' : 'da_show')) ?></span><?php endforeach; ?>
						<?php if ($fo !== null && NuFieldAccess::rulesOf($fo)): ?><span class="badge text-bg-info"><?= h(t('da_col_field', (string)$fo['sob_all_id'])) ?></span><?php endif; ?>
					</summary>
					<div class="px-3 pb-3">
						<div class="d-flex align-items-center gap-2 mb-2"><label class="small" for="da_cd_<?= h($bid) ?>"><?= h(t('da_col_default')) ?></label>
							<?= $sel('cols[' . $bid . '][default]', ['show' => t('da_show'), 'hide' => t('da_hide')], $acc['default'] ?? 'show', 'form-select form-select-sm w-auto', ' id="da_cd_' . h($bid) . '"' . $ro) ?></div>
						<div class="table-responsive"><table class="table table-sm align-middle mb-2 daRules">
							<thead><tr><th><?= h(t('da_type')) ?></th><th><?= h(t('da_subject')) ?></th><th><?= h(t('da_mode')) ?></th><th class="text-center"><?= h(t('da_remove')) ?></th></tr></thead>
							<tbody><?php foreach ($acc['rules'] ?? [] as $i => $r) echo $colRow($bid, (string)$i, $r); ?></tbody>
						</table></div>
						<?php if ($canSave): ?>
							<template class="daTpl"><?= $colRow($bid, '__N__', ['type' => 'access', 'mode' => 'hide']) ?></template>
							<button type="button" class="btn btn-sm btn-outline-secondary daAdd"><i class="bi bi-plus-lg me-1"></i><?= h(t('da_add_rule')) ?></button>
						<?php endif; ?>
					</div>
				</details>
			<?php endforeach; ?>
			<?php if ($canSave): ?><button class="btn nuBtnAct nuBtnSave mt-2" type="submit" data-nu-save id="daSaveCols"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		</form>
		<?php endif; ?>
	</div>

	<!-- By access level -->
	<div class="tab-pane fade<?= $tab === 'level' ? ' show active' : '' ?>" id="da_level" role="tabpanel" aria-labelledby="da_level_tab">
		<p class="small text-body-secondary"><?= h(t('da_intro_level')) ?></p>
		<?php $lvl = isset($levels[(string)($_GET['lvl'] ?? '')]) ? (string)$_GET['lvl'] : (string)array_key_first($levels); ?>
		<form method="get" action="index.php" class="d-flex flex-wrap gap-2 align-items-center mb-3">
			<input type="hidden" name="p" value="dataaccess"><input type="hidden" name="f" value="<?= h($meta->id) ?>"><input type="hidden" name="tab" value="level">
			<label class="small" for="daLevel"><?= h(t('da_type_access')) ?></label>
			<?= $sel('lvl', $levels, $lvl, 'form-select form-select-sm w-auto', ' id="daLevel" data-nu-autosubmit="1"') ?>
			<button class="btn btn-sm btn-outline-secondary" type="submit"><?= h(t('da_show_level')) ?></button>
		</form>
		<?php if ($lvl !== ''): ?>
		<form method="post" action="<?= h($back) ?>" data-nu-guard id="daLevelForm">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="save_level"><input type="hidden" name="level" value="<?= h($lvl) ?>">
			<div class="table-responsive"><table class="table table-sm align-middle nu-table">
				<thead><tr><th><?= h(t('da_field')) ?></th><th><?= h(t('da_nub')) ?></th><th><?= h(t('da_level_rule')) ?></th><th><?= h(t('da_other_rules')) ?></th></tr></thead>
				<tbody>
				<?php foreach ($fields as $oid => $o):
					$rules = NuFieldAccess::rulesOf($o);
					$mine = ''; $other = [];
					foreach ($rules as $r) { if ($r['type'] === 'access' && $r['subject'] === $lvl) $mine = $r['mode']; else $other[] = ($r['type'] === 'default' ? t('da_type_default') : t('da_type_' . $r['type']) . ' ' . $subjectName($r['type'], $r['subject'])) . ': ' . $modeLabel($r['mode']); } ?>
					<tr><td><code><?= h((string)$o['sob_all_id']) ?></code> <span class="small text-body-secondary"><?= h($label($o)) ?></span></td>
						<td class="small"><?= h($nubAccess($o)) ?></td>
						<td><?= $sel('lf[' . $oid . ']', ['' => t('da_no_rule')] + $modes, $mine, 'form-select form-select-sm', ' aria-label="' . h((string)$o['sob_all_id']) . '"' . $ro) ?></td>
						<td class="small text-body-secondary"><?= h(implode(' · ', $other)) ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ($browse as $bid => $b):
					$acc = NuBrowseAccess::rules($b); $mine = '';
					foreach ($acc['rules'] ?? [] as $r) if ($r['type'] === 'access' && $r['subject'] === $lvl) $mine = $r['mode']; ?>
					<tr><td><i class="bi bi-layout-three-columns me-1" aria-hidden="true"></i><?= h(trim(strip_tags((string)$b['sbr_title'])) ?: (string)$b['sbr_display']) ?> <span class="small text-body-secondary"><?= h(t('da_column')) ?></span></td>
						<td class="small"><?= h($acc ? t('da_col_default') . ': ' . t($acc['default'] === 'hide' ? 'da_hide' : 'da_show') : t('da_show')) ?></td>
						<td><?= $sel('lc[' . $bid . ']', ['' => t('da_no_rule'), 'show' => t('da_show'), 'hide' => t('da_hide')], $mine, 'form-select form-select-sm', ' aria-label="' . h((string)$b['sbr_title']) . '"' . $ro) ?></td>
						<td></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php if ($canSave): ?><button class="btn nuBtnAct nuBtnSave" type="submit" data-nu-save id="daSaveLevel"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		</form>
		<?php endif; ?>
	</div>

	<!-- Preview -->
	<div class="tab-pane fade<?= $tab === 'preview' ? ' show active' : '' ?>" id="da_preview" role="tabpanel" aria-labelledby="da_preview_tab">
		<p class="small text-body-secondary"><?= h(t('da_intro_preview')) ?></p>
		<?php $as = (string)($_GET['as'] ?? ''); $users = []; foreach ($cat['user'] as [$v, $l]) $users[$v] = $l; ?>
		<form method="get" action="index.php" class="d-flex flex-wrap gap-2 align-items-center mb-3">
			<input type="hidden" name="p" value="dataaccess"><input type="hidden" name="f" value="<?= h($meta->id) ?>"><input type="hidden" name="tab" value="preview">
			<label class="small" for="daAs"><?= h(t('da_type_user')) ?></label>
			<?= $sel('as', ['' => '–'] + $users, $as, 'form-select form-select-sm w-auto', ' id="daAs" data-nu-autosubmit="1"') ?>
			<button class="btn btn-sm btn-outline-secondary" type="submit"><?= h(t('da_show_level')) ?></button>
		</form>
		<?php if ($as !== '' && isset($users[$as])):
			$me = NuSubject::forUser($as);
			$rows = NuFieldAccess::asUser($as, static function () use ($fields): array {
				$out = [];
				foreach ($fields as $oid => $o) { [$m, $r] = NuFieldAccess::resolve($o); $out[$oid] = [$m, $r]; }
				return $out;
			}); ?>
			<div class="table-responsive"><table class="table table-sm align-middle nu-table" id="daPreviewTable">
				<thead><tr><th><?= h(t('da_field')) ?></th><th><?= h(t('da_effective')) ?></th><th><?= h(t('da_decided_by')) ?></th></tr></thead>
				<tbody>
				<?php foreach ($fields as $oid => $o): [$m, $r] = $rows[$oid];
					$warn = in_array((string)$o['sob_all_validate'], ['1', '3'], true) && in_array($m, ['wh', 'prot', 'mask', 'upd'], true); ?>
					<tr data-field="<?= h((string)$o['sob_all_id']) ?>" data-mode="<?= h($m) ?>"><td><code><?= h((string)$o['sob_all_id']) ?></code> <span class="small text-body-secondary"><?= h($label($o)) ?></span></td>
						<td><span class="badge <?= in_array($m, ['wh', 'mask', 'prot'], true) ? 'text-bg-warning' : 'text-bg-secondary' ?>"><?= h($modeLabel($m)) ?></span>
							<?php if ($warn): ?><i class="bi bi-exclamation-triangle text-warning ms-1" role="img" aria-label="<?= h(t('da_warn_required')) ?>" title="<?= h(t('da_warn_required')) ?>"></i><?php endif; ?></td>
						<td class="small"><?= h($r === null ? t('da_rule_nub') : ($r['type'] === 'default' ? t('da_type_default') : t('da_type_' . $r['type']) . ' ' . $subjectName($r['type'], $r['subject']))) ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ($browse as $bid => $b): $st = NuBrowseAccess::state($meta, $b, $me); ?>
					<tr data-column="<?= h($bid) ?>" data-state="<?= h(explode(':', $st)[0]) ?>"><td><i class="bi bi-layout-three-columns me-1" aria-hidden="true"></i><?= h(trim(strip_tags((string)$b['sbr_title'])) ?: (string)$b['sbr_display']) ?> <span class="small text-body-secondary"><?= h(t('da_column')) ?></span></td>
						<td><span class="badge <?= $st === 'show' ? 'text-bg-secondary' : 'text-bg-warning' ?>"><?= h(t($st === 'show' ? 'da_show' : ($st === 'hide' ? 'da_hide' : 'da_mode_mask'))) ?></span></td><td></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
	</div>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nudataaccess.js') . '"></script>');
