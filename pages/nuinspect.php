<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Inspect record (globeadmin): ?p=inspect&f=<form id>&r=<record id> – opened from the options menu of an edit form in a new tab.
 * All columns of the record with type, keys and stored value; sort, filter, search, copy (assets/js/nuinspect.js).
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('forms', 'view');
require_once NU_ROOT . '/core/nuinspect.php';

$db       = nu_db();
$formId   = (string)($_GET['f'] ?? '');
$recordId = (string)($_GET['r'] ?? '');
$meta     = $formId !== '' ? NuFormMeta::load($formId) : null;
$table    = $meta ? $meta->table() : '';
$pk       = $meta ? $meta->primaryKey() : '';

$error = '';
$row = null; $cols = [];
if (!$meta)                                                                  $error = t('err_not_found');
elseif ($table === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $table)) $error = t('insp_err_table');
elseif ($pk === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $pk))       $error = t('insp_err_pk');
elseif ($recordId === '' || $recordId === '-1')                              $error = t('insp_err_new');
elseif (!$db->tableExists($table))                                           $error = t('insp_err_no_table', $table);
else {
	try {
		$cols = NuInspect::columns($db, $table);
		$row  = $db->one('SELECT * FROM ' . NuDB::ident($table) . ' WHERE ' . NuDB::ident($pk) . ' = ?', [$recordId]);
		if (!$row) $error = t('insp_err_no_record', $recordId);
	} catch (Throwable $e) {
		error_log('nuBuilder Next inspect: ' . $e->getMessage());
		$error = t('insp_err_read') . (nu_debug() ? ' ' . $e->getMessage() : '');
	}
}

$items = [];
if ($row) {
	$tables = NuInspect::tables($db);
	$byLower = array_change_key_case($row, CASE_LOWER);
	foreach ($cols as $i => $c) {
		$v = $byLower[strtolower($c['name'])] ?? null;
		$items[] = $c + [
			'pos'    => $i + 1,
			'value'  => $v,
			'guess'  => $c['fk'] === '' && !$c['pk'] ? NuInspect::guessReference($c['name'], $tables, $table) : '',
			'secret' => NuInspect::isSecret($c['name']) && $v !== null && $v !== '',
			'pkcol'  => strcasecmp($c['name'], $pk) === 0,
		];
	}
	// demo mode: secrets (password hashes, keys, tokens …) never leave the server – shown as dots, nothing to reveal
	if (NuDemo::active()) foreach ($items as &$it) if ($it['secret']) { $it['value'] = '••••••••'; $it['secret'] = false; }
	unset($it);
}
$filled = count(array_filter($items, static fn($i) => $i['value'] !== null && $i['value'] !== ''));

nu_layout_start(t('insp_title') . ($meta ? ' · ' . $meta->code() : ''), 'forms', ['container' => 'container-fluid']);
?>
<div class="nuTitleBar mb-3">
	<h1 class="h5 mb-0 fw-semibold"><i class="bi bi-table me-2"></i><?= h(t('insp_title')) ?></h1>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button(null, $formId !== '' ? nu_url('form', ['f' => $formId, 'r' => $recordId !== '' ? $recordId : null]) : null) ?>
	<?php if ($row): ?>
		<button type="button" class="btn nuBtnAct" id="inspCopyJson"><i class="bi bi-braces me-1"></i><?= h(t('insp_copy_json')) ?></button>
		<a class="btn nuBtnAct" href="<?= h(nu_url('inspect', ['f' => $formId, 'r' => $recordId])) ?>"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('sc_refresh')) ?></a>
	<?php endif; ?></div>
</div>

<?php if ($error !== ''): ?>
	<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div>
<?php else: ?>
<div class="card shadow-sm nuListCard" id="inspCard"
	 data-copied="<?= h(t('insp_copied')) ?>" data-show="<?= h(t('insp_show')) ?>" data-hide="<?= h(t('insp_hide')) ?>"
	 data-json="<?= h(json_encode(array_combine(array_column($items, 'name'), array_column($items, 'value')), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT)) ?>">
	<div class="card-header nuListHead">
		<dl class="row small mb-2 inspFacts">
			<dt class="col-sm-2 col-lg-1"><?= h(t('insp_form')) ?></dt><dd class="col-sm-10 col-lg-5"><?= h($meta->code()) ?> – <?= h($meta->description()) ?></dd>
			<dt class="col-sm-2 col-lg-1"><?= h(t('insp_table')) ?></dt><dd class="col-sm-10 col-lg-5 font-monospace"><?= h($table) ?></dd>
			<dt class="col-sm-2 col-lg-1"><?= h(t('insp_record')) ?></dt><dd class="col-sm-10 col-lg-5 font-monospace"><?= h($pk) ?> = <?= h($recordId) ?></dd>
			<dt class="col-sm-2 col-lg-1"><?= h(t('insp_columns')) ?></dt><dd class="col-sm-10 col-lg-5"><?= h(t('insp_counts', count($items), $filled, count($items) - $filled)) ?></dd>
		</dl>
		<div class="row g-2 align-items-center">
			<div class="col-md-5"><input type="search" class="form-control" id="inspSearch" placeholder="<?= h(t('search_ph')) ?>" aria-label="<?= h(t('search')) ?>" data-nu-autofocus></div>
			<div class="col-md-4 col-lg-3">
				<select class="form-select" id="inspFilter" aria-label="<?= h(t('insp_show_label')) ?>">
					<option value="all"><?= h(t('insp_all')) ?></option>
					<option value="filled"><?= h(t('insp_filled')) ?></option>
					<option value="empty"><?= h(t('insp_empty')) ?></option>
				</select>
			</div>
			<div class="col-auto small text-body-secondary" id="inspShown" aria-live="polite"></div>
		</div>
	</div>
	<div class="table-responsive">
		<table class="table table-hover table-sm align-middle mb-0 nuListTable inspTable" id="inspTable">
			<thead>
				<tr>
					<th scope="col" class="inspSort text-end" data-sort="pos" aria-sort="ascending"><button type="button" class="btn btn-link p-0 inspSortBtn">#</button></th>
					<th scope="col" class="inspSort" data-sort="name"><button type="button" class="btn btn-link p-0 inspSortBtn"><?= h(t('insp_column')) ?></button></th>
					<th scope="col" class="inspSort" data-sort="type"><button type="button" class="btn btn-link p-0 inspSortBtn"><?= h(t('insp_type')) ?></button></th>
					<th scope="col" class="inspSort w-50" data-sort="value"><button type="button" class="btn btn-link p-0 inspSortBtn"><?= h(t('insp_value')) ?></button></th>
					<th scope="col"><span class="visually-hidden"><?= h(t('actions')) ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($items as $i):
				$v = $i['value'];
				$isNull = $v === null; $isEmpty = !$isNull && (string)$v === '';
				$str = $isNull ? '' : (string)$v;
				$binary = $i['binary'] && !$isNull;
				$isJson = !$binary && $str !== '' && ($str[0] === '{' || $str[0] === '[') && json_decode($str) !== null;
				$typeInfo = ($i['nullable'] ? 'NULL' : 'NOT NULL') . ($i['default'] !== null ? ' · DEFAULT ' . $i['default'] : '') . ($i['comment'] !== '' ? ' · ' . $i['comment'] : '');
			?>
				<tr class="<?= $i['pkcol'] ? 'table-warning' : '' ?>" data-pos="<?= $i['pos'] ?>" data-name="<?= h(mb_strtolower($i['name'])) ?>" data-type="<?= h(mb_strtolower($i['type'])) ?>"
					data-value="<?= h($binary ? '' : mb_strtolower(mb_substr($str, 0, 200))) ?>" data-state="<?= $isNull ? 'null' : ($isEmpty ? 'empty' : 'filled') ?>">
					<td class="text-end text-body-secondary small"><?= $i['pos'] ?></td>
					<td class="text-nowrap">
						<span class="fw-semibold font-monospace"><?= h($i['name']) ?></span>
						<?php if ($i['pk']): ?><i class="bi bi-key-fill text-warning ms-1" title="<?= h(t('insp_pk')) ?>"></i><?php endif; ?>
						<?php if ($i['fk'] !== ''): ?><i class="bi bi-link-45deg text-primary ms-1" title="<?= h(t('insp_fk', $i['fk'])) ?>"></i><?php endif; ?>
						<?php if ($i['guess'] !== ''): ?><i class="bi bi-link-45deg text-body-secondary ms-1" title="<?= h(t('insp_fk_guess', $i['guess'])) ?>"></i><?php endif; ?>
						<?php if (!$i['pk'] && ($i['unique'] || $i['index'])): ?><i class="bi bi-key text-body-secondary ms-1" title="<?= h(t($i['unique'] ? 'insp_unique' : 'insp_index')) ?>"></i><?php endif; ?>
					</td>
					<td class="text-nowrap"><code class="inspType" title="<?= h($typeInfo) ?>"><?= h($i['type']) ?></code></td>
					<td class="inspValueCell">
						<?php if ($isNull): ?><span class="badge text-bg-light border fst-italic fw-normal">NULL</span>
						<?php elseif ($isEmpty): ?><span class="text-body-secondary fst-italic small"><?= h(t('insp_empty_string')) ?></span>
						<?php elseif ($binary): ?><span class="text-body-secondary fst-italic small"><?= h(t('insp_binary', strlen($str))) ?></span>
						<?php else: ?>
							<span class="inspValue font-monospace<?= $i['secret'] ? ' inspMasked' : '' ?>" tabindex="0" title="<?= h(mb_strlen($str) > 80 && !$i['secret'] ? mb_substr($str, 0, 1000) : '') ?>"
								  data-len="<?= mb_strlen($str) ?>"<?= $isJson ? ' data-json="1"' : '' ?>><?= h($str) ?></span>
							<?php if (mb_strlen($str) > 80): ?><span class="badge text-bg-light border fw-normal ms-1"><?= h(t('insp_chars', mb_strlen($str))) ?></span><?php endif; ?>
							<?php if ($isJson): ?><span class="badge text-bg-info fw-normal ms-1">JSON</span><?php endif; ?>
						<?php endif; ?>
					</td>
					<td class="text-end text-nowrap">
						<?php if (!$isNull && !$isEmpty && !$binary): ?>
							<?php if ($i['secret']): ?><button type="button" class="btn btn-sm btn-outline-secondary nuRowBtn inspReveal" title="<?= h(t('insp_show')) ?>" aria-label="<?= h(t('insp_show')) ?>"><i class="bi bi-eye"></i></button><?php endif; ?>
							<button type="button" class="btn btn-sm btn-outline-secondary nuRowBtn inspCopy" title="<?= h(t('insp_copy')) ?>" aria-label="<?= h(t('insp_copy')) ?>"><i class="bi bi-clipboard"></i></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
				<tr class="d-none" id="inspNone"><td colspan="5" class="text-center text-body-secondary py-4"><?= h(t('no_records')) ?></td></tr>
			</tbody>
		</table>
	</div>
	<div class="card-footer small text-body-secondary"><?= h(t('insp_hint')) ?></div>
</div>
<?php endif;
nu_layout_end('<script src="' . nu_asset('assets/js/nuinspect.js') . '"></script>');
