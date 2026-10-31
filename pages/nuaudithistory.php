<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * History of one record (audit trail, 0.8.350): ?p=audithistory&f=<form id>&r=<record id> – opened from the ⋮ menu of the edit form
 * or from a row of the audit trail. All changes of the record and of its sub-form rows, newest first, one block per save.
 * Rights: view on the form (the record must be visible to the user while it exists); restoring needs save on the form.
 */
$fid = (string)($_GET['f'] ?? '');
$rid = (string)($_GET['r'] ?? '');
$meta = $fid !== '' ? NuFormMeta::load($fid) : null;
if (!$meta || $rid === '' || $meta->table() === '') nu_fatal(t('record_not_found'), '', true);
NuAcl::require($meta->id);
$db = nu_db();
$exists = NuRecord::load($meta, $rid) !== null;
if ($exists && !NuRecord::visible($meta, $rid)) NuAcl::require('-');
$canRestore = NuAcl::can($meta->id, 'save') && !NuDemo::active();
$self = nu_url('audithistory', ['f' => $meta->id, 'r' => $rid]);

if (nu_is_post() && nu_post('action') === 'restore') {
	if (!$canRestore) NuAcl::require('-');
	try {
		nu_flash('success', NuAudit::restore(nu_post('id'), $meta->id));
	} catch (RuntimeException $e) {
		nu_flash('danger', $e->getMessage());
	}
	nu_redirect($self);
}

$changes = NuAudit::historyFor($meta, $rid);                     // only the columns this user sees on the form (0.8.367)
$restoreBtn = static function (string $id, string $label, string $confirm, string $icon = 'bi-arrow-counterclockwise') use ($canRestore): string {
	if (!$canRestore) return '';
	return '<form method="post" class="d-inline" data-confirm="' . h($confirm) . '" data-confirm-ok="' . h(t('aud_restore')) . '">' . nu_csrf_field()
		. '<input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="' . h($id) . '">'
		. '<button type="submit" class="btn btn-sm btn-outline-secondary nuAudRestore" title="' . h($label) . '" aria-label="' . h($label) . '"><i class="bi ' . $icon . '"></i></button></form>';
};

nu_layout_start(t('aud_history'), 'audit');
?>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('aud_history')) ?><?= NuListView::infoTip(t('aud_history_hint')) ?></h1>
		<div class="small text-body-secondary"><?= h($meta->description() ?: $meta->code()) ?> · <?= h($meta->table()) ?> #<?= h($rid) ?><?= $exists ? '' : ' · <span class="badge bg-danger-subtle text-danger-emphasis">' . h(t('aud_record_gone')) . '</span>' ?></div>
		<div class="d-flex flex-wrap gap-2 align-items-center">
			<?= nu_back_button(null, $exists ? nu_url('form', ['f' => $meta->id, 'r' => $rid]) : nu_url('form', ['f' => $meta->id])) ?>
			<?php if (NuAcl::can(NuAcl::FORM_AUDIT)): ?><a class="btn btn-outline-secondary" href="<?= h(nu_url('audit', ['table' => $meta->table(), 'record' => $rid])) ?>"><i class="bi bi-clock-history me-1"></i><?= h(t('aud_title')) ?></a><?php endif; ?>
		</div>
	</div>
	<div class="card-body">
<?php if (!NuAudit::on()): ?>
		<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('aud_off')) ?></div>
<?php endif; ?>
<?php if (!$changes): ?>
		<p class="text-body-secondary mb-0" id="audNone"><?= h(t('aud_none')) ?></p>
<?php else: ?>
		<ol class="list-unstyled mb-0 nuAudTimeline">
<?php foreach ($changes as $c):
	// one block per save: the rows grouped by table and record (the main record first)
	$groups = [];
	foreach ($c['rows'] as $row) $groups[$row['table_name'] . "\x1f" . $row['record_id']][] = $row;
	uksort($groups, static fn($a, $b) => (int)!str_starts_with($a, $meta->table() . "\x1f" . $rid) <=> (int)!str_starts_with($b, $meta->table() . "\x1f" . $rid));
	$acts = array_unique(array_column($c['rows'], 'action'));
?>
			<li class="mb-3 nuAudChange" data-change="<?= h($c['change_id']) ?>">
				<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
					<span class="fw-semibold"><?= h(date(nu_dfmt('d.m.Y H:i:s'), strtotime($c['at']))) ?></span>
					<span class="text-body-secondary"><i class="bi bi-person me-1"></i><?= h($c['login'] !== '' ? $c['login'] : '–') ?></span>
					<?php foreach ($acts as $a): ?><?= NuListDefs::auditBadge((string)$a) ?><?php endforeach; ?>
					<?php $src = (string)$c['rows'][0]['source']; if ($src !== 'form' && $src !== 'subform'): ?><span class="small text-body-secondary"><?= h(($l = t('aud_src_' . $src)) === 'aud_src_' . $src ? $src : $l) ?></span><?php endif; ?>
				</div>
				<div class="table-responsive border rounded">
				<table class="table table-sm mb-0 align-middle nu-table nuAudTable">
					<thead><tr><th class="w-25"><?= h(t('aud_column')) ?></th><th><?= h(t('aud_old')) ?></th><th><?= h(t('aud_new')) ?></th><th class="text-end"></th></tr></thead>
					<tbody>
<?php foreach ($groups as $rows):
	$g = $rows[0];
	$isMain = strcasecmp((string)$g['table_name'], $meta->table()) === 0 && (string)$g['record_id'] === $rid;
	$deleted = $g['action'] === 'delete';
?>
<?php if (!$isMain || $deleted): ?>
						<tr class="table-light nuAudGroup"><td colspan="3" class="small">
							<?= $isMain ? h(t('aud_record')) . ' ' . h((string)$g['table_name']) . ' #' . h((string)$g['record_id']) . ' ' : '<i class="bi bi-list-nested me-1"></i>' . h(t('aud_subrow', (string)$g['table_name'], (string)$g['record_id'])) . ' ' ?><?= NuListDefs::auditBadge((string)$g['action']) ?>
						</td><td class="text-end"><?= $deleted && NuAudit::restorable($g) ? $restoreBtn((string)$g['zzzzsys_nunext_audit_id'], t('aud_restore_row'), t('aud_restore_row_confirm'), 'bi-arrow-counterclockwise') : '' ?></td></tr>
<?php endif; ?>
<?php foreach ($rows as $row): if ((string)$row['column_name'] === '') continue; ?>
						<tr>
							<td class="small fw-semibold text-break"><?= h((string)$row['column_name']) ?></td>
							<td class="small text-break"><?= $row['action'] === 'insert' || $row['action'] === 'clone' ? '' : NuListDefs::auditValue($row['old_value'], true) ?></td>
							<td class="small text-break"><?= $row['action'] === 'delete' ? '' : NuListDefs::auditValue($row['new_value']) ?></td>
							<td class="text-end"><?= $row['action'] !== 'delete' && NuAudit::restorable($row) ? $restoreBtn((string)$row['zzzzsys_nunext_audit_id'], t('aud_restore_value'), t('aud_restore_value_confirm', (string)$row['column_name'])) : '' ?></td>
						</tr>
<?php endforeach; ?>
<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</li>
<?php endforeach; ?>
		</ol>
<?php endif; ?>
	</div>
</div>
<?php
nu_layout_end();
