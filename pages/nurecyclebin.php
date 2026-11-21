<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Recycle bin for records (core/nurecycle.php) – deleted records of forms that keep them.
 *   ?p=recyclebin[&form=<form id>]   list (system list 'recyclebin': the forms whose delete right the user has; all with the admin right)
 *   ?p=recyclebin&id=<id>            one deleted record: its fields and sub-form rows, Restore / Delete for good
 * POST action restore | remove (id) | empty (form: every entry of that form the user may change).
 * JSON (Accept: application/json, the Undo of the delete toast): action restore → {ok, message, url}.
 */

NuAcl::require(NuAcl::FORM_RECYCLE);
NuSchema::ensure();
$json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

NuRecyclebinPage::post(get_defined_vars());   // the POST actions (code review R7, nurecyclebinpage.php)

$crumbs = static fn(array $items) => NuListView::crumbs(array_merge([[t('rb_title'), nu_url('recyclebin')]], $items));

// ------------------------------------------------------------------ one entry
if (($_GET['id'] ?? '') !== '') {
	$e = NuRecycle::get((string)$_GET['id']);
	if (!$e || !NuRecycle::canForm((string)$e['form_id']) && !NuRecycle::admin()) { http_response_code(404); nu_fatal(t('rb_err_gone'), '', true); }
	$can = NuRecycle::canForm((string)$e['form_id']);
	$d = $e['parsed'];
	$meta = NuFormMeta::load((string)$e['form_id']);
	$label = [];                                        // column → label of the form's object
	if ($meta) foreach ($meta->objects as $o) { $l = trim(strip_tags((string)($o['sob_all_label'] ?? ''))); if ($l !== '') $label[strtolower((string)$o['sob_all_id'])] = $l; }
	$val = static function (mixed $v): string {
		if (is_array($v)) return isset($v['__b64']) ? '[' . t('rb_binary') . ']' : (string)json_encode($v, JSON_UNESCAPED_UNICODE);
		$v = (string)($v ?? '');
		return mb_strlen($v) > 500 ? mb_substr($v, 0, 500) . ' …' : $v;
	};
	nu_body_class('nuRecPage');
	nu_layout_start((string)$e['title'], 'home');
	echo $crumbs([[(string)$e['title']]]);
	$buttons = nu_back_button(null, nu_url('recyclebin'));
	if ($can) {
		$buttons .= '<button type="submit" form="rbRestoreForm" class="btn nuBtnAct" id="rbRestore"><i class="bi bi-arrow-counterclockwise me-1"></i>' . h(t('rb_restore')) . '</button>'
			. '<button type="submit" form="rbRemoveForm" class="btn nuBtnAct nuBtnDel" id="rbRemove"><i class="bi bi-trash me-1"></i>' . h(t('rb_remove')) . '</button>';
	}
	?>
<div class="nuTitleBar nuRecHead"><h1><?= h((string)$e['title']) ?></h1><div class="d-flex flex-wrap gap-2"><?= $buttons ?></div></div>
<?php if ($can): ?>
<form method="post" id="rbRestoreForm" action="<?= h(nu_url('recyclebin')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action"
	value="restore"><input type="hidden" name="id" value="<?= h((string)$e['zzzzsys_nunext_recycle_id']) ?>"></form>
<form method="post" id="rbRemoveForm" action="<?= h(nu_url('recyclebin')) ?>" data-confirm="<?= h(t('rb_remove_confirm')) ?>"
	data-confirm-ok="<?= h(t('rb_remove')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action"
	value="remove"><input type="hidden" name="id" value="<?= h((string)$e['zzzzsys_nunext_recycle_id']) ?>"></form>
<?php endif; ?>
<div class="card shadow-sm mb-3"><div class="card-body">
	<dl class="row mb-0 small rbMeta">
		<dt class="col-sm-3"><?= h(t('rb_form')) ?></dt><dd class="col-sm-9"><?= h($meta ? ($meta->description() ?: $meta->code()) : (string)$e['form_code']) ?> <code><?= h((string)$e['form_code']) ?></code></dd>
		<dt class="col-sm-3"><?= h(t('rb_key')) ?></dt><dd class="col-sm-9"><code><?= h((string)$e['record_id']) ?></code> · <code><?= h((string)$e['table_name']) ?></code></dd>
		<dt class="col-sm-3"><?= h(t('rb_deleted')) ?></dt><dd class="col-sm-9"><?= h(date(nu_dfmt('d.m.Y H:i'), (int)strtotime((string)$e['deleted_at']))) ?> · <?= h((string)$e['deleted_by_name']) ?></dd>
		<dt class="col-sm-3"><?= h(t('rb_expires')) ?></dt><dd class="col-sm-9"><?= h(date(nu_dfmt('d.m.Y'), (int)strtotime((string)$e['expires_at']))) ?></dd>
	</dl>
</div></div>
<div class="card shadow-sm mb-3">
	<div class="card-header fw-semibold"><?= h(t('rb_fields')) ?></div>
	<div class="table-responsive"><table class="table nu-table mb-0 small" id="rbFields"><tbody>
	<?php foreach ((array)($d['row'] ?? []) as $k => $v): ?>
		<tr><th class="text-nowrap fw-normal text-body-secondary"><?= h($label[strtolower((string)$k)] ?? (string)$k) ?> <code class="small"><?= h((string)$k) ?></code></th><td class="text-break"><?= h($val($v)) ?></td></tr>
	<?php endforeach; ?>
	</tbody></table></div>
</div>
<?php foreach ((array)($d['subs'] ?? []) as $sub): $rows = (array)($sub['rows'] ?? []); if (!$rows) continue; $keys = array_keys((array)$rows[0]); ?>
<div class="card shadow-sm mb-3 rbSub">
	<div class="card-header fw-semibold"><?= h(t('rb_sub_rows', count($rows))) ?> <code class="small"><?= h((string)$sub['table']) ?></code></div>
	<div class="table-responsive"><table class="table nu-table mb-0 small"><thead><tr><?php foreach ($keys as $k): ?><th><?= h((string)$k) ?></th><?php endforeach; ?></tr></thead><tbody>
	<?php foreach ($rows as $r): ?><tr><?php foreach ($keys as $k): ?><td class="text-break"><?= h($val(((array)$r)[$k] ?? null)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
	</tbody></table></div>
</div>
<?php endforeach; ?>
	<?php
	nu_layout_end();
	return;
}

// ------------------------------------------------------------------ list
NuListView::page('recyclebin', [
	'before' => NuListView::crumbs([[t('rb_title')]]), 'active' => 'home',
	'title_extra' => NuListView::infoTip(t('rb_title_tip')),
	'foot' => '<script src="' . nu_asset('assets/js/nurecyclebin.js') . '"></script>',
	'above_rows' => '<form method="post" id="rbRowRestore" class="d-none" action="' . h(nu_url('recyclebin')) . '">' . nu_csrf_field()
		. '<input type="hidden" name="action" value="restore"><input type="hidden" name="id" value=""></form>',
]);
