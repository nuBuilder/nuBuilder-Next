<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

NuAcl::require(NuAcl::FORM_ACCESS);
$rights = NuAcl::rights(NuAcl::FORM_ACCESS);
$db     = nu_db();
$forms  = nu_forms();

$id      = (string)($_GET['id'] ?? '');
$cloneId = (string)($_GET['clone'] ?? '');

$existing = $id !== '' ? $db->one('SELECT * FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$id]) : null;
if ($id !== '' && !$existing) {
	nu_flash('warning', t('record_not_found'));
	nu_redirect(nu_url('access'));
}

$userCount = $existing ? (int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE sus_zzzzsys_access_id = ?', [$id]) : 0;

// ------------------------------------------------------------------ delete (with child rows, like nuBuilder)
if (nu_is_post() && nu_post('action') === 'delete') {
	NuAcl::require(NuAcl::FORM_ACCESS, 'delete');
	if ($userCount > 0) {
		nu_flash('danger', t('access_err_in_use', $userCount));
		nu_redirect(nu_url('accesslevel', ['id' => $id]));
	}
	$db->transaction(static function (NuDB $db) use ($id) {
		$db->delete('zzzzsys_access_form', 'slf_zzzzsys_access_id', $id);
		$db->delete('zzzzsys_access_php', 'slp_zzzzsys_access_id', $id);
		if ($db->tableExists('zzzzsys_access_report')) $db->delete('zzzzsys_access_report', 'sre_zzzzsys_access_id', $id);
		$db->delete('zzzzsys_access', 'zzzzsys_access_id', $id);
	});
	NuLog::write('access_deleted', 'zzzzsys_access', $id, ['code' => $existing['sal_code']]);
	nu_flash('success', t('access_deleted', $existing['sal_code']));
	nu_redirect(nu_url('access'));
}

$isNew = !$existing;
if ($isNew) NuAcl::require(NuAcl::FORM_ACCESS, $cloneId !== '' ? 'clone' : 'create');
$canSave = $isNew ? true : $rights['save'];

$source = $existing;
if ($isNew && $cloneId !== '') {
	$source = $db->one('SELECT * FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$cloneId]);
	if (!$source) nu_redirect(nu_url('access'));
	$source['sal_code'] = '';
}

$text = [
	'sal_code'        => [50, 'access_code'],
	'sal_description' => [200, 'access_description'],
	'sal_group'       => [80, 'access_group'],
];
$v = [];
foreach ($text as $col => $_) $v[$col] = (string)($source[$col] ?? '');
$v['sal_zzzzsys_form_id'] = (string)($source['sal_zzzzsys_form_id'] ?? '');
$v['sal_use_2fa']         = (string)($source['sal_use_2fa'] ?? '') === '1' ? '1' : '0';

// form-right rows of this level (or of the clone source), keyed by id
$rightsSql = 'SELECT * FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ?';
$dbRows    = $existing ? $db->all($rightsSql, [$id]) : [];
$rows      = [];                  // key => ['id','form', right => '0'|'1', 'dm' => ''|'0', 'ft' => ''|'0'|'1']
// nuBuilder 4.9's data mode (0 = no changes to existing records) and form type (0 = browse only, 1 = edit only) per form (0.8.461)
$afCols  = $db->columns('zzzzsys_access_form');
$hasDm   = isset($afCols['slf_data_mode']);
$hasFt   = isset($afCols['slf_form_type']);
$dmOf    = static fn($v) => trim((string)$v) === '0' ? '0' : '';
$ftOf    = static fn($v) => in_array(trim((string)$v), ['0', '1'], true) ? trim((string)$v) : '';
foreach (($isNew && $source) ? $db->all($rightsSql, [$cloneId]) : $dbRows as $r) {
	$row = ['id' => $isNew ? '' : (string)$r['zzzzsys_access_form_id'], 'form' => (string)$r['slf_zzzzsys_form_id'],
		'dm' => $dmOf($r['slf_data_mode'] ?? ''), 'ft' => $ftOf($r['slf_form_type'] ?? '')];
	foreach (NuAcl::RIGHTS as $right => $col) $row[$right] = (string)$r[$col] === '1' ? '1' : '0';
	$rows[$isNew ? 'c' . count($rows) : $row['id']] = $row;
}
$existingById = [];
foreach ($dbRows as $r) $existingById[(string)$r['zzzzsys_access_form_id']] = $r;

$errors = [];

/** Data mode and form type of a form row (0.8.461) – two small selects, as nuBuilder's access level form. */
function nu_access_mode_cells(string $k, string $dm, string $ft, string $label, bool $hasDm, bool $hasFt): string
{
	$sel = static function (string $name, string $cur, array $opts, string $aria) use ($k): string {
		$o = '';
		foreach ($opts as $v => $txt) $o .= '<option value="' . h((string)$v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . h($txt) . '</option>';
		return '<td><select class="form-select form-select-sm nuAccMode nuAccMode-' . $name . '" name="rights[' . $k . '][' . $name . ']" aria-label="' . h($aria) . '">' . $o . '</select></td>';
	};
	$out = '';
	if ($hasDm) $out .= $sel('dm', $dm, ['' => t('access_dm_normal'), '0' => t('access_dm_noedit')], t('access_col_dm') . ($label !== '' ? ' – ' . $label : ''));
	if ($hasFt) $out .= $sel('ft', $ft, ['' => t('access_ft_both'), '0' => t('access_ft_browse'), '1' => t('access_ft_edit')], t('access_col_ft') . ($label !== '' ? ' – ' . $label : ''));
	return $out;
}

// ------------------------------------------------------------------ save
if (nu_is_post() && nu_post('action') === 'save') {
	if (!$canSave) NuAcl::require(NuAcl::FORM_ACCESS, 'save');

	foreach ($text as $col => [$max]) {
		$v[$col] = nu_post($col);
		nu_maxlen($errors, $col, $v[$col], $max);
	}
	$v['sal_zzzzsys_form_id'] = nu_post('sal_zzzzsys_form_id');
	$v['sal_use_2fa']         = nu_check('sal_use_2fa');

	if ($v['sal_code'] === '') {
		$errors['sal_code'] = t('err_required');
	} elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_access WHERE sal_code = ? AND zzzzsys_access_id <> ?', [$v['sal_code'], $id])) {
		$errors['sal_code'] = t('access_err_code_taken');
	}
	if ($v['sal_zzzzsys_form_id'] !== '' && !isset($forms[$v['sal_zzzzsys_form_id']]) && $v['sal_zzzzsys_form_id'] !== (string)($existing['sal_zzzzsys_form_id'] ?? '')) {
		$errors['sal_zzzzsys_form_id'] = t('err_invalid');
	}

	// rights grid
	$rows = [];
	$seen = [];
	$posted = $_POST['rights'] ?? [];
	foreach (is_array($posted) ? $posted : [] as $key => $p) {
		if (!is_array($p)) continue;
		$rid  = is_string($p['id'] ?? null) && isset($existingById[$p['id']]) ? $p['id'] : '';   // foreign ids are treated as new rows
		$form = is_string($p['form'] ?? null) ? trim($p['form']) : '';
		if ($form === '') continue;
		// crossed out (0.8.139): not saved, so an existing row is deleted below; kept crossed out if the page comes back with errors
		if (($p['remove'] ?? '') === '1') {
			$row = ['id' => $rid, 'form' => $form, 'remove' => true, 'dm' => $dmOf($p['dm'] ?? ''), 'ft' => $ftOf($p['ft'] ?? '')];
			foreach (NuAcl::RIGHTS as $right => $_) $row[$right] = (($p[$right] ?? '') === '1') ? '1' : '0';
			$rows[$rid !== '' ? $rid : 'n' . preg_replace('/\W/', '', (string)$key)] = $row;
			continue;
		}
		$known = isset($forms[$form]) || ($rid !== '' && (string)$existingById[$rid]['slf_zzzzsys_form_id'] === $form);
		if (!$known) { $errors['rights'] = t('access_err_unknown_form', $form); continue; }
		if (isset($seen[$form])) { $errors['rights'] = t('access_err_duplicate', nu_form_label($form)); continue; }
		$seen[$form] = true;

		$row = ['id' => $rid, 'form' => $form, 'dm' => $dmOf($p['dm'] ?? ''), 'ft' => $ftOf($p['ft'] ?? '')];
		foreach (NuAcl::RIGHTS as $right => $_) $row[$right] = (($p[$right] ?? '') === '1') ? '1' : '0';
		$rows[$rid !== '' ? $rid : 'n' . preg_replace('/\W/', '', (string)$key)] = $row;
	}

	// admin-equivalent rights (NuSecurity::ADMIN_FORMS): with "Only the globeadmin grants admin rights" nobody else adds them,
	// and nobody changes the rights of his own access level
	if (!$errors && NuSecurity::restricted()) {
		if (!$isNew && $id === (string)NuAuth::user()['access_id']) {
			$errors['rights'] = t('secchk_err_own_rights');
		} else {
			$before = $isNew ? [] : NuSecurity::adminRightsOf($id);
			foreach ($rows as $row) {
				if (!empty($row['remove']) || !in_array($row['form'], NuSecurity::ADMIN_FORMS, true)) continue;
				foreach (NuAcl::RIGHTS as $right => $col) {
					if ($row[$right] === '1' && in_array($col, NuSecurity::ADMIN_RIGHTS, true) && !in_array($col, $before[$row['form']] ?? [], true)) {
						$errors['rights'] = t('secchk_err_admin_rights', nu_form_label($row['form']));
						break 2;
					}
				}
			}
		}
	}

	if (!$errors) {
		$newId = $db->transaction(static function (NuDB $db) use ($isNew, $id, $v, $rows, $existingById, $cloneId, $source, $hasDm, $hasFt) {
			$head = [
				'sal_code'            => $v['sal_code'],
				'sal_description'     => $v['sal_description'] === '' ? null : $v['sal_description'],
				'sal_group'           => $v['sal_group'] === '' ? null : $v['sal_group'],
				'sal_zzzzsys_form_id' => $v['sal_zzzzsys_form_id'] === '' ? null : $v['sal_zzzzsys_form_id'],
				'sal_use_2fa'         => $v['sal_use_2fa'],
			];
			if ($isNew) {
				$id = nu_id();
				$db->insert('zzzzsys_access', ['zzzzsys_access_id' => $id] + $head + ['sal_json' => $source['sal_json'] ?? null]);
			} else {
				$db->update('zzzzsys_access', $head, 'zzzzsys_access_id', $id);   // sal_json untouched
			}

			$keep = [];
			foreach ($rows as $row) {
				if (!empty($row['remove'])) continue;                 // crossed out: an existing row is deleted below
				$data = ['slf_zzzzsys_form_id' => $row['form']];
				foreach (NuAcl::RIGHTS as $right => $col) $data[$col] = $row[$right];
				if ($hasDm) $data['slf_data_mode'] = $row['dm'] === '' ? null : $row['dm'];       // 0.8.461: NULL = no limit (as nuBuilder)
				if ($hasFt) $data['slf_form_type'] = $row['ft'] === '' ? null : $row['ft'];
				if ($row['id'] !== '') {
					$db->update('zzzzsys_access_form', $data, 'zzzzsys_access_form_id', $row['id']);   // json untouched; unchanged columns are not written
					$keep[$row['id']] = true;
				} else {
					$db->insert('zzzzsys_access_form', ['zzzzsys_access_form_id' => nu_id(), 'slf_zzzzsys_access_id' => $id] + $data);
				}
			}
			foreach (array_keys($existingById) as $oldId) {
				if (!isset($keep[$oldId])) $db->delete('zzzzsys_access_form', 'zzzzsys_access_form_id', (string)$oldId);
			}

			// a clone also copies the PHP procedure rights
			if ($isNew && $cloneId !== '') {
				foreach ($db->all('SELECT slp_zzzzsys_php_id, slp_json FROM zzzzsys_access_php WHERE slp_zzzzsys_access_id = ?', [$cloneId]) as $p) {
					$db->insert('zzzzsys_access_php', ['zzzzsys_access_php_id' => nu_id(), 'slp_zzzzsys_access_id' => $id] + $p);
				}
				if ($db->tableExists('zzzzsys_access_report')) {                 // and the report rights
					foreach ($db->all('SELECT sre_zzzzsys_report_id, srp_json FROM zzzzsys_access_report WHERE sre_zzzzsys_access_id = ?', [$cloneId]) as $p) {
						$db->insert('zzzzsys_access_report', ['zzzzsys_access_report_id' => nu_id(), 'sre_zzzzsys_access_id' => $id] + $p);
					}
				}
			}
			return $id;
		});

		NuLog::write($isNew ? 'access_created' : 'access_updated', 'zzzzsys_access', $newId, ['code' => $v['sal_code'], 'forms' => count(array_filter($rows, static fn($r) => empty($r['remove'])))]);
		nu_flash('success', $isNew ? t('access_created', $v['sal_code']) : t('saved'));
		nu_redirect(nu_url('accesslevel', ['id' => $newId, 'tab' => nu_post('tab') === 'rights' ? 'rights' : null]));   // stays on the tab it was saved from
	}
	nu_flash('danger', t('err_check_form'));
}

// ------------------------------------------------------------------ view data
uasort($rows, static fn($a, $b) => strcasecmp(nu_form_label($a['form']), nu_form_label($b['form'])));
$phpCount = $existing ? (int)$db->value('SELECT COUNT(*) FROM zzzzsys_access_php WHERE slp_zzzzsys_access_id = ?', [$id])
	: ($cloneId !== '' ? (int)$db->value('SELECT COUNT(*) FROM zzzzsys_access_php WHERE slp_zzzzsys_access_id = ?', [$cloneId]) : 0);
$rptCount = $db->tableExists('zzzzsys_access_report') && ($existing || $cloneId !== '') ? (int)$db->value('SELECT COUNT(*) FROM zzzzsys_access_report WHERE sre_zzzzsys_access_id = ?', [$existing ? $id : $cloneId]) : 0;
$users = $existing ? $db->page('SELECT zzzzsys_user_id, sus_login_name, sus_first_name, sus_last_name FROM zzzzsys_user WHERE sus_zzzzsys_access_id = ?', [$id], 'sus_login_name', 50, 0) : [];

$groups = array_column($db->all("SELECT DISTINCT sal_group AS g FROM zzzzsys_access WHERE sal_group IS NOT NULL AND sal_group <> '' ORDER BY sal_group"), 'g');

$title = $isNew ? ($cloneId !== '' ? t('access_clone') : t('access_new')) : ($existing['sal_code'] ?: t('access_level'));
$ro    = $canSave ? '' : ' disabled';

/** <option>s of all forms, grouped into own forms and nuBuilder system forms */
$formOptions = static function (string $selected = '', array $disabled = []) use ($forms): string {
	$out = '';
	foreach ([false, true] as $system) {
		$html = '';
		foreach ($forms as $fid => $f) {
			if ($f['system'] !== $system) continue;
			$html .= '<option value="' . h($fid) . '"' . ($fid === $selected ? ' selected' : '') . (isset($disabled[$fid]) ? ' disabled' : '') . '>'
				. h(nu_form_label((string)$fid) . ($f['type'] === 'page' ? ' · ' . t('form_type_page') : '')) . '</option>';   // page forms (0.8.387): what is granted
		}
		if ($html !== '') $out .= '<optgroup label="' . h(t($system ? 'forms_system' : 'forms_app')) . '">' . $html . '</optgroup>';
	}
	return $out;
};
$used = array_fill_keys(array_column($rows, 'form'), true);

// "Several…" dialog: all forms with group, type and table to filter by (sfo_group is missing in old nuBuilder versions)
$formCols = array_change_key_case($db->columns('zzzzsys_form'), CASE_LOWER);
$pick = [];
foreach ($db->all('SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_type, sfo_table' . (isset($formCols['sfo_group']) ? ', sfo_group' : '') . ' FROM zzzzsys_form ORDER BY sfo_code') as $f) {
	$pick[] = ['id' => (string)$f['zzzzsys_form_id'], 'code' => (string)$f['sfo_code'], 'desc' => (string)$f['sfo_description'], 'type' => (string)$f['sfo_type'],
		'table' => (string)$f['sfo_table'], 'group' => trim((string)($f['sfo_group'] ?? '')), 'system' => str_starts_with((string)$f['zzzzsys_form_id'], 'nu'),
		'label' => nu_form_label((string)$f['zzzzsys_form_id'])];
}
$pickGroups = array_values(array_unique(array_filter(array_column($pick, 'group'), static fn($g) => $g !== '')));
natcasesort($pickGroups);
$pickTypes = array_values(array_unique(array_filter(array_column($pick, 'type'))));

nu_layout_start($title, 'access', ['head' => '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">']);
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('access')) ?>"><?= h(t('nav_access')) ?></a></li>
		<li class="breadcrumb-item active" aria-current="page"><?= h($title) ?></li>
	</ol>
</nav>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0 d-flex align-items-center gap-2"><i class="bi bi-shield-lock"></i><?= h($title) ?></h1>
	<div class="d-flex flex-wrap gap-2 align-items-center">
		<a class="btn nuBtnAct" href="<?= h(nu_url('access')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if ($canSave): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuRecordForm"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<?php if (!$isNew): ?>
			<?php if (NuHome::canManage()): // its start page (user start pages, 0.8.425) ?>
				<a class="btn nuBtnAct" href="<?= h(nu_url('home', ['for' => $id])) ?>" id="nuAccessHome"><i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i><?= h(t('home_u_edit_level')) ?></a>
			<?php endif; ?>
			<?php if ($rights['clone']): ?>
				<a class="btn nuBtnAct" href="<?= h(nu_url('accesslevel', ['clone' => $id])) ?>"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></a>
			<?php endif; ?>
			<?php if ($rights['delete']): ?>
				<form method="post" action="<?= h(nu_url('accesslevel', ['id' => $id])) ?>" data-confirm="<?= h(t('access_delete_confirm', $existing['sal_code'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
					<?php if ($userCount): // still in use: the button says why instead of doing nothing ?>
						<button class="btn nuBtnAct nuBtnDel nuBtnBlocked" type="button" title="<?= h(t('access_err_in_use', $userCount)) ?>" data-nu-info="<?= h(t('access_err_in_use', $userCount)) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
					<?php else: ?>
						<button class="btn nuBtnAct nuBtnDel" type="submit"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
					<?php endif; ?>
				</form>
			<?php endif; ?>
			<?= NuFormInfo::record('zzzzsys_access', (string)$id, ['code' => (string)$existing['sal_code']]) /* (i) record info (0.8.501) */ ?>
		<?php endif; ?>
	</div>
</div>

<?php if (!$canSave): ?>
	<div class="alert alert-info d-flex gap-2"><i class="bi bi-eye mt-1"></i><div><?= h(t('read_only')) ?></div></div>
<?php endif; ?>

<form method="post" data-nu-focus-first data-nu-guard id="nuRecordForm" action="<?= h(nu_url('accesslevel', $isNew ? ($cloneId !== '' ? ['clone' => $cloneId] : []) : ['id' => $id])) ?>" novalidate>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<fieldset<?= $ro ?>>
	<?php
	// tab General / Form rights (0.8.484): ?tab=rights, the tab it was saved from; an error opens the tab that shows it (General first)
	$genErr = array_diff_key($errors, ['rights' => 1]) !== [];
	$accTab = !$genErr && (isset($errors['rights']) || (string)($_GET['tab'] ?? '') === 'rights') ? 'rights' : '';
	$rightsCount = count(array_filter($rows, static fn($r) => empty($r['remove'])));
	?>
	<input type="hidden" name="tab" id="accessTab" value="<?= h($accTab) ?>">
	<ul class="nav nav-tabs mb-3" id="accessTabs" role="tablist" data-nu-tabfield="accessTab">
		<li class="nav-item" role="presentation"><button class="nav-link<?= $accTab === '' ? ' active' : '' ?>" id="accessTabGeneralBtn" data-bs-toggle="tab" data-bs-target="#accessTabGeneral" data-nu-tab="" type="button" role="tab" aria-controls="accessTabGeneral" aria-selected="<?= $accTab === '' ? 'true' : 'false' ?>"><i class="bi bi-card-text me-1"></i><?= h(t('access_sec_general')) ?></button></li>
		<li class="nav-item" role="presentation"><button class="nav-link<?= $accTab === 'rights' ? ' active' : '' ?>" id="accessTabRightsBtn" data-bs-toggle="tab" data-bs-target="#accessTabRights" data-nu-tab="rights" type="button" role="tab" aria-controls="accessTabRights" aria-selected="<?= $accTab === 'rights' ? 'true' : 'false' ?>"><i class="bi bi-ui-checks-grid me-1"></i><?= h(t('access_forms')) ?> <span class="badge text-bg-secondary js-rights-count"><?= $rightsCount ?></span><?php if (isset($errors['rights'])): ?> <i class="bi bi-exclamation-circle text-danger" role="img" aria-label="<?= h(t('err_check_form')) ?>"></i><?php endif; ?></button></li>
	</ul>
	<div class="tab-content">
	<div class="tab-pane fade<?= $accTab === '' ? ' show active' : '' ?>" id="accessTabGeneral" role="tabpanel" aria-labelledby="accessTabGeneralBtn" tabindex="0">
	<div class="row g-3">
		<div class="col-lg-6">
			<div class="card shadow-sm mb-3">
				<div class="card-header fw-semibold"><i class="bi bi-card-text me-2"></i><?= h(t('access_sec_general')) ?></div>
				<div class="card-body">
					<div class="mb-3">
						<label class="form-label" for="sal_code"><?= h(t('access_code')) ?> <span class="text-danger">*</span></label>
						<input class="form-control<?= nu_invalid($errors, 'sal_code') ?>" id="sal_code" name="sal_code" value="<?= h($v['sal_code']) ?>" maxlength="50" required autocomplete="off">
						<?= nu_feedback($errors, 'sal_code') ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="sal_description"><?= h(t('access_description')) ?></label>
						<input class="form-control<?= nu_invalid($errors, 'sal_description') ?>" id="sal_description" name="sal_description" value="<?= h($v['sal_description']) ?>" maxlength="200">
						<?= nu_feedback($errors, 'sal_description') ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="sal_group"><?= h(t('access_group')) ?></label>
						<input class="form-control<?= nu_invalid($errors, 'sal_group') ?>" id="sal_group" name="sal_group" value="<?= h($v['sal_group']) ?>" maxlength="80" list="dl_groups" autocomplete="off">
						<datalist id="dl_groups"><?php foreach ($groups as $g): ?><option value="<?= h($g) ?>"><?php endforeach; ?></datalist>
						<?= nu_feedback($errors, 'sal_group') ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="sal_zzzzsys_form_id"><?= h(t('home_form')) ?></label>
						<select class="form-select nuListTomSelect<?= nu_invalid($errors, 'sal_zzzzsys_form_id') ?>" id="sal_zzzzsys_form_id" name="sal_zzzzsys_form_id">
							<option value=""><?= h(t('please_select')) ?></option>
							<?php if ($v['sal_zzzzsys_form_id'] !== '' && !isset($forms[$v['sal_zzzzsys_form_id']])): ?>
								<option value="<?= h($v['sal_zzzzsys_form_id']) ?>" selected><?= h($v['sal_zzzzsys_form_id']) ?></option>
							<?php endif; ?>
							<?= $formOptions($v['sal_zzzzsys_form_id']) ?>
						</select>
						<?= nu_feedback($errors, 'sal_zzzzsys_form_id') ?>
						<div class="form-text"><?= h(t('home_form_hint')) ?></div>
					</div>
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="sal_use_2fa" name="sal_use_2fa" value="1"<?= $v['sal_use_2fa'] === '1' ? ' checked' : '' ?>>
						<label class="form-check-label" for="sal_use_2fa"><?= h(t('access_2fa')) ?></label>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= h(t('access_2fa_hint')) ?>" aria-label="<?= h(t('access_2fa_hint')) ?>"><i class="bi bi-info-circle"></i></span>
						<?php // the switch only works while $nuConfig2FAUser is on (as in nuBuilder) – say so, and offer to switch it on
						$cfg2fa = (bool)nu_cfg('nuConfig2FAUser'); $fromDb = NuSettings::fromDb(); $me2 = NuAuth::user(); ?>
						<?php // shown only while the switch is on (0.8.511, assets/js/nuaccesslevel.js toggles it) ?>
						<div class="small mt-1 <?= $cfg2fa ? 'text-success' : 'text-warning-emphasis' ?>" id="nu2faCfgNote"<?= $v['sal_use_2fa'] === '1' ? '' : ' hidden' ?>>
							<i class="bi <?= $cfg2fa ? 'bi-check-circle' : 'bi-exclamation-triangle' ?> me-1"></i><?= h(t($cfg2fa ? 'access_2fa_cfg_on' : 'access_2fa_cfg_off')) ?>
							<?php if (!$cfg2fa && $me2['globeadmin'] && empty($me2['demo'])): ?>
								<?php if ($fromDb): ?>
									<a class="ms-1" href="<?= h(nu_url('settings')) ?>"><?= h(t('access_2fa_cfg_settings')) ?></a>
								<?php elseif (NuConfigFile::writable()): ?>
									<button type="submit" form="nuEnable2fa" class="btn btn-sm btn-warning py-0 ms-1"><i class="bi bi-toggle-on me-1"></i><?= h(t('twofa_on')) ?></button>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="col-lg-6">
			<?php if (!$isNew): ?>
			<div class="card shadow-sm mb-3">
				<div class="card-header fw-semibold d-flex justify-content-between"><span><i class="bi bi-people me-2"></i><?= h(t('nav_users')) ?></span><span class="badge text-bg-secondary"><?= $userCount ?></span></div>
				<?php if (!$users): ?>
					<div class="card-body small text-body-secondary"><?= h(t('access_no_users')) ?></div>
				<?php else: ?>
					<ul class="list-group list-group-flush small nu-scroll">
						<?php foreach ($users as $us): $nm = trim($us['sus_first_name'] . ' ' . $us['sus_last_name']); ?>
							<li class="list-group-item d-flex justify-content-between gap-2">
								<?php if (NuAcl::can(NuAcl::FORM_USER)): ?>
									<a class="text-decoration-none" href="<?= h(nu_url('user', ['id' => $us['zzzzsys_user_id']])) ?>"><?= h($us['sus_login_name']) ?></a>
								<?php else: ?><span><?= h($us['sus_login_name']) ?></span><?php endif; ?>
								<span class="text-body-secondary text-truncate"><?= h($nm) ?></span>
							</li>
						<?php endforeach; ?>
						<?php if ($userCount > count($users)): ?><li class="list-group-item text-body-secondary">+ <?= $userCount - count($users) ?></li><?php endif; ?>
					</ul>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="small text-body-secondary px-1">
				<i class="bi bi-filetype-php me-1"></i><?= h(t('access_php_info', $phpCount)) ?>
				<?php if (NuReport::available()): ?><br><i class="bi bi-file-earmark-richtext me-1"></i><?php if (NuAcl::can(NuAcl::FORM_REPORT)): ?><a href="<?= h(nu_url('reports')) ?>"><?= h(t('access_rpt_info', $rptCount)) ?></a><?php else: ?><?= h(t('access_rpt_info', $rptCount)) ?><?php endif; ?><?php endif; ?>
			</div>
		</div>
	</div>
	</div>

	<div class="tab-pane fade<?= $accTab === 'rights' ? ' show active' : '' ?>" id="accessTabRights" role="tabpanel" aria-labelledby="accessTabRightsBtn" tabindex="0">
			<div class="card shadow-sm">
				<div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
					<span class="fw-semibold"><i class="bi bi-ui-checks-grid me-2"></i><?= h(t('access_forms')) ?> <span class="badge text-bg-secondary js-rights-count"><?= $rightsCount ?></span></span>
					<input type="search" class="form-control form-control-sm nu-rights-filter js-rights-filter" placeholder="<?= h(t('access_filter_forms')) ?>" aria-label="<?= h(t('access_filter_forms')) ?>">
				</div>
				<?php if (isset($errors['rights'])): ?><div class="alert alert-danger rounded-0 mb-0 py-2"><?= h($errors['rights']) ?></div><?php endif; ?>

				<?php if ($canSave): ?>
				<div class="card-body border-bottom py-2">
					<div class="input-group input-group-sm">
						<label class="input-group-text" for="addForm"><i class="bi bi-plus-lg"></i></label>
						<select class="form-select js-add-form" id="addForm" aria-label="<?= h(t('access_add_form')) ?>">
							<option value=""><?= h(t('access_add_form')) ?>…</option>
							<?= $formOptions('', $used) ?>
						</select>
						<button class="btn btn-outline-primary js-add-form-btn" type="button"><?= h(t('add')) ?></button>
						<button class="btn btn-outline-secondary js-add-many" type="button" data-bs-toggle="modal" data-bs-target="#addFormsModal" title="<?= h(t('am_hint')) ?>"><i class="bi bi-collection me-1"></i><?= h(t('am_button')) ?></button>
					</div>
				</div>
				<?php endif; ?>

				<div class="table-responsive nu-rights-wrap">
					<table class="table table-sm table-hover align-middle mb-0 nu-rights">
						<thead class="sticky-top">
						<tr>
							<th scope="col"><?= h(t('form')) ?></th>
							<?php foreach (array_keys(NuAcl::RIGHTS) as $r): ?>
								<th scope="col" class="text-center">
									<label class="d-flex flex-column align-items-center gap-1 m-0">
										<span class="small"><?= h(t('right_' . $r)) ?></span>
										<input class="form-check-input js-col-toggle" type="checkbox" data-col="<?= $r ?>" aria-label="<?= h(t('toggle_all') . ': ' . t('right_' . $r)) ?>">
									</label>
								</th>
							<?php endforeach; ?>
							<?php if ($hasDm): ?><th scope="col" class="small"><?= h(t('access_col_dm')) ?></th><?php endif; ?>
							<?php if ($hasFt): ?><th scope="col" class="small"><?= h(t('access_col_ft')) ?></th><?php endif; ?>
							<th scope="col"><span class="visually-hidden"><?= h(t('actions')) ?></span></th>
						</tr>
						</thead>
						<tbody class="js-rights-body" data-admin-forms="<?= h(implode(' ', NuSecurity::ADMIN_FORMS)) ?>" data-label-remove="<?= h(t('remove')) ?>" data-label-undo="<?= h(t('remove_undo')) ?>" data-label-pending="<?= h(t('remove_pending')) ?>">
						<?php foreach ($rows as $key => $row): $k = h((string)$key); ?>
							<tr data-form="<?= h($row['form']) ?>">
								<td>
									<input type="hidden" name="rights[<?= $k ?>][id]" value="<?= h($row['id']) ?>">
									<input type="hidden" name="rights[<?= $k ?>][form]" value="<?= h($row['form']) ?>">
									<input type="hidden" name="rights[<?= $k ?>][remove]" value="<?= !empty($row['remove']) ? '1' : '' ?>" class="js-remove-flag">
									<span class="js-form-label"><?= h(nu_form_label($row['form'])) ?></span>
									<?php if (in_array($row['form'], NuSecurity::ADMIN_FORMS, true)): ?><span class="text-danger ms-1 nuAdminRight" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('secchk_admin_right_tip')) ?>" title="<?= h(t('secchk_admin_right_tip')) ?>"><i class="bi bi-shield-exclamation"></i></span><?php endif; ?>
									<?php if (!isset($forms[$row['form']])): ?><span class="badge bg-warning-subtle text-warning-emphasis ms-1"><?= h(t('access_form_missing')) ?></span><?php endif; ?>
								</td>
								<?php foreach (array_keys(NuAcl::RIGHTS) as $r): ?>
									<td class="text-center">
										<input class="form-check-input" type="checkbox" name="rights[<?= $k ?>][<?= $r ?>]" value="1" data-col="<?= $r ?>"<?= $row[$r] === '1' ? ' checked' : '' ?>
											   aria-label="<?= h(t('right_' . $r) . ' – ' . nu_form_label($row['form'])) ?>">
									</td>
								<?php endforeach; ?>
								<?= nu_access_mode_cells($k, $row['dm'], $row['ft'], nu_form_label($row['form']), $hasDm, $hasFt) ?>
								<td class="text-end">
									<?php if ($canSave): ?><button class="btn btn-sm btn-link text-danger js-remove-row" type="button" title="<?= h(t('remove')) ?>" aria-label="<?= h(t('remove')) ?>" aria-pressed="false"><i class="bi bi-x-circle"></i></button><?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						<tr class="js-rights-empty<?= $rows ? ' d-none' : '' ?>"><td colspan="<?= 8 + (int)$hasDm + (int)$hasFt ?>" class="text-center text-body-secondary py-4"><?= h(t('access_no_forms')) ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>
	</div>
	</div>
	</fieldset>

	<template id="rightRowTpl">
		<tr data-form="">
			<td>
				<input type="hidden" name="rights[__KEY__][id]" value="">
				<input type="hidden" name="rights[__KEY__][form]" value="">
				<input type="hidden" name="rights[__KEY__][remove]" value="" class="js-remove-flag">
				<span class="js-form-label"></span> <span class="text-danger ms-1 nuAdminRight js-admin-right" hidden tabindex="0" role="img" aria-label="<?= h(t('secchk_admin_right_tip')) ?>" title="<?= h(t('secchk_admin_right_tip')) ?>"><i class="bi bi-shield-exclamation"></i></span> <span class="badge bg-success-subtle text-success-emphasis ms-1"><?= h(t('new')) ?></span>
			</td>
			<?php foreach (array_keys(NuAcl::RIGHTS) as $r): ?>
				<td class="text-center"><input class="form-check-input" type="checkbox" name="rights[__KEY__][<?= $r ?>]" value="1" data-col="<?= $r ?>" checked aria-label="<?= h(t('right_' . $r)) ?>"></td>
			<?php endforeach; ?>
			<?= nu_access_mode_cells('__KEY__', '', '', '', $hasDm, $hasFt) ?>
			<td class="text-end"><button class="btn btn-sm btn-link text-danger js-remove-row" type="button" title="<?= h(t('remove')) ?>" aria-label="<?= h(t('remove')) ?>" aria-pressed="false"><i class="bi bi-x-circle"></i></button></td>
		</tr>
	</template>

</form>
<?php if ($canSave): ?>
<script type="application/json" id="addFormsData"><?= json_encode($pick, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="modal fade" id="addFormsModal" tabindex="-1" aria-labelledby="addFormsTitle" aria-hidden="true"
	 data-found="<?= h(t('am_found')) ?>" data-add="<?= h(t('am_add')) ?>" data-in-list="<?= h(t('am_in_list')) ?>" data-no-group="<?= h(t('am_no_group')) ?>" data-none="<?= h(t('am_none')) ?>" data-all-used="<?= h(t('am_all_used')) ?>">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header py-2">
				<h2 class="modal-title fs-5" id="addFormsTitle"><i class="bi bi-collection me-2"></i><?= h(t('am_title')) ?></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
			</div>
			<div class="modal-body">
				<div class="row g-2 mb-2">
					<div class="col-md-4"><input type="search" class="form-control form-control-sm" id="amSearch" placeholder="<?= h(t('am_search')) ?>" aria-label="<?= h(t('am_search')) ?>" autocomplete="off"></div>
					<div class="col-md-3">
						<select class="form-select form-select-sm" id="amGroup" aria-label="<?= h(t('am_group')) ?>">
							<option value="*"><?= h(t('am_all_groups')) ?></option>
							<?php foreach ($pickGroups as $g): ?><option value="<?= h($g) ?>"><?= h($g) ?></option><?php endforeach; ?>
							<option value=""><?= h(t('am_no_group')) ?></option>
						</select>
					</div>
					<div class="col-6 col-md-2">
						<select class="form-select form-select-sm" id="amType" aria-label="<?= h(t('am_type')) ?>">
							<option value="*"><?= h(t('am_all_types')) ?></option>
							<?php foreach ($pickTypes as $ty): ?><option value="<?= h($ty) ?>"><?= h(t('form_type_' . $ty) !== 'form_type_' . $ty ? t('form_type_' . $ty) : $ty) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="col-6 col-md-3">
						<select class="form-select form-select-sm" id="amKind" aria-label="<?= h(t('am_kind')) ?>">
							<option value="app"><?= h(t('am_kind_app')) ?></option>
							<option value="system"><?= h(t('forms_system')) ?></option>
							<option value="*"><?= h(t('am_kind_all')) ?></option>
						</select>
					</div>
				</div>
				<div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
					<button type="button" class="btn btn-sm btn-outline-secondary" id="amAll"><i class="bi bi-check2-all me-1"></i><?= h(t('am_select_shown')) ?></button>
					<button type="button" class="btn btn-sm btn-outline-secondary" id="amNone"><?= h(t('am_select_none')) ?></button>
					<label class="form-check form-switch mb-0 ms-1"><input class="form-check-input" type="checkbox" id="amHideUsed" checked> <span class="form-check-label"><?= h(t('am_hide_used')) ?></span></label>
					<span class="ms-auto text-body-secondary" id="amCount" aria-live="polite"></span>
				</div>
				<div class="list-group list-group-flush border rounded nuAmList" id="amList"></div>
				<div class="text-center text-body-secondary small py-3 d-none" id="amEmpty"><?= h(t('am_none')) ?></div>
				<fieldset class="mt-3">
					<legend class="fs-6 fw-semibold mb-1"><?= h(t('am_rights')) ?></legend>
					<div class="d-flex flex-wrap gap-3">
						<?php foreach (array_keys(NuAcl::RIGHTS) as $r): ?>
							<label class="form-check mb-0"><input class="form-check-input js-am-right" type="checkbox" data-col="<?= $r ?>" checked> <span class="form-check-label"><?= h(t('right_' . $r)) ?></span></label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			</div>
			<div class="modal-footer py-2">
				<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
				<button type="button" class="btn btn-sm btn-primary" id="amAdd" disabled><i class="bi bi-plus-lg me-1"></i><span></span></button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<?php
if (!(bool)nu_cfg('nuConfig2FAUser') && NuAuth::user()['globeadmin'] && !NuSettings::fromDb()) {
	echo NuConfigFile::enableForm('nuConfig2FAUser', 'nuEnable2fa', t('twofa_on'), t('twofa_on_confirm'), $isNew ? 'access' : 'accesslevel', $isNew ? '' : (string)$id);
}
nu_layout_end('<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nulistselect.js') . '"></script><script src="' . nu_asset('assets/js/nuaccesslevel.js') . '"></script>');
