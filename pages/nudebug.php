<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Debug results (zzzzsys_debug) – rights of nuBuilder's form "nudebug". Rows come from nuDebug(…) in PHP code
 * (hooks, procedures, $nuConfigIncludePHP). Newest first, filter by flag / user / text / date, delete.
 */

NuAcl::require(NuAcl::FORM_DEBUG);
$db = nu_db();
NuDebugLog::ensureTable();
$T = NuDebugLog::TABLE;
$cols = $db->columns($T);
$hasFlag = isset($cols['deb_flag']);
$hasUser = isset($cols['deb_user_id']);

if (nu_is_post()) {
	NuAcl::require(NuAcl::FORM_DEBUG, 'delete');
	$action = nu_post('action');
	$n = 0;
	if ($action === 'delete_selected') {
		$ids = array_values(array_filter((array)($_POST['ids'] ?? []), 'is_string'));
		foreach (array_chunk($ids, 200) as $chunk) {
			$n += $db->run("DELETE FROM $T WHERE zzzzsys_debug_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)->rowCount();
		}
	} elseif ($action === 'delete_all') {
		$n = $db->run("DELETE FROM $T")->rowCount();
	} elseif ($action === 'delete_one') {
		$n = $db->delete($T, 'zzzzsys_debug_id', nu_post('id'));
	}
	NuLog::write('debug_deleted', $T, '', ['rows' => $n]);
	nu_flash('success', t('debug_deleted', $n));
	nu_redirect(nu_url('debug', array_intersect_key($_GET, array_flip(['q', 'flag', 'user', 'from', 'to']))));
}

$q    = trim((string)($_GET['q'] ?? ''));
$flag = (string)($_GET['flag'] ?? '');
$user = (string)($_GET['user'] ?? '');
$from = (string)($_GET['from'] ?? '');
$to   = (string)($_GET['to'] ?? '');
$per  = nu_per_param() ?: 50;   // the footer's page size menu (0.8.437)
$pg   = max(1, (int)($_GET['pg'] ?? 1));

$where = [];
$params = [];
if ($q !== '') { $where[] = "d.deb_message LIKE ? ESCAPE '!'"; $params[] = NuDB::like($q); }
if ($hasFlag && $flag !== '') { $where[] = "COALESCE(d.deb_flag,'') = ?"; $params[] = $flag; }
if ($hasUser && $user !== '') { $where[] = "COALESCE(d.deb_user_id,'') = ?"; $params[] = $user; }
if ($from !== '' && nu_valid_date($from)) { $where[] = 'd.deb_added >= ?'; $params[] = strtotime($from . ' 00:00:00'); }
if ($to !== '' && nu_valid_date($to)) { $where[] = 'd.deb_added < ?'; $params[] = strtotime($to . ' 00:00:00 +1 day'); }
$userJoin = $hasUser && $db->tableExists('zzzzsys_user') ? ' LEFT JOIN zzzzsys_user u ON u.zzzzsys_user_id = d.deb_user_id' : '';
$sqlFrom = " FROM $T d$userJoin" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$total = (int)$db->value('SELECT COUNT(*)' . $sqlFrom, $params);
$pg    = min($pg, (int)max(1, ceil($total / $per)));
$rows  = $db->page('SELECT d.*' . ($userJoin ? ', u.sus_login_name, u.sus_name' : '') . $sqlFrom, $params, 'd.deb_added DESC, d.zzzzsys_debug_id DESC', $per, ($pg - 1) * $per);
$flags = $hasFlag ? array_column($db->all("SELECT DISTINCT deb_flag FROM $T WHERE deb_flag IS NOT NULL AND deb_flag <> '' ORDER BY deb_flag"), 'deb_flag') : [];
$users = $hasUser ? $db->all("SELECT DISTINCT d.deb_user_id" . ($userJoin ? ', u.sus_login_name' : '') . " FROM $T d$userJoin WHERE d.deb_user_id IS NOT NULL ORDER BY d.deb_user_id") : [];
$keep  = ['q' => $q, 'flag' => $flag, 'user' => $user, 'from' => $from, 'to' => $to, 'per' => nu_per_param() ?: null];
$canDelete = NuAcl::can(NuAcl::FORM_DEBUG, 'delete');

/** Message as text: nuBuilder's "<br>" after the header line is dropped, " <html>" messages are shown as source. */
function nu_debug_text(string $m): string
{
	$m = preg_replace("/\n\n<br>\n/", "\n", $m, 1) ?? $m;
	// nuBuilder Forte writes a little HTML (<b>nuRunQuery</b>, <br>) – shown as text it read as raw tags (review D8)
	$m = preg_replace('~<br\s*/?>~i', "\n", $m) ?? $m;
	return trim(preg_replace('~</?(b|i|strong|em|u|pre|code)>~i', '', $m) ?? $m);   // only these tags: SQL in the text may hold "<" 
}

NuListExport::handle(NuAcl::FORM_DEBUG, t('debug_title'), [
	t('log_time')      => static fn($r) => $r['deb_added'] ? date(nu_dfmt('d.m.Y H:i:s'), (int)$r['deb_added']) : '',
	t('debug_flag')    => static fn($r) => $hasFlag ? $r['deb_flag'] : '',
	t('user')          => static fn($r) => $hasUser ? ((string)($r['sus_login_name'] ?? '') ?: (string)($r['deb_user_id'] ?? '')) : '',
	t('log_details')   => static fn($r) => nu_debug_text((string)$r['deb_message']),
], static fn(int $limit) => $db->page('SELECT d.*' . ($userJoin ? ', u.sus_login_name, u.sus_name' : '') . $sqlFrom, $params, 'd.deb_added DESC, d.zzzzsys_debug_id DESC', $limit, 0),
	NuListExport::filterText([t('search') => $q, t('debug_flag') => $flag, t('user') => $user, t('log_from') => $from, t('log_to') => $to]));

nu_layout_start(t('debug_title'), 'log');
?>


<?= NuAudit::tabs('debug') ?>

<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
	<h1 class="h5 mb-0 fw-semibold"><?= h(t('debug_title')) ?><?php // the hint only as tooltip of the (i) ?>
		<span class="nuInfoTip fs-6 fw-normal ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" aria-label="<?= h(t('debug_hint')) ?>" title="<?= h(t('debug_hint')) ?>"><i class="bi bi-info-circle"></i></span></h1>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?>
		<?php // Back · Refresh · Print in one style (0.8.262); the auto refresh switch at the right end, on phones in its own row ?>
		<a class="btn nuBtnAct" href="<?= h(nu_url('debug', $keep)) ?>"><i class="bi bi-arrow-clockwise me-1"></i><?= h(t('debug_refresh')) ?></a>
		<?= $total > 0 ? NuListExport::buttons(NuAcl::FORM_DEBUG, 'debug', $keep, false, 'nuBtnAct') : '' /* no records: nothing to export (0.8.749) */ ?>
		<?php if ($canDelete && $total): ?>
			<form method="post" class="d-inline" data-confirm="<?= h(t('debug_delete_all_confirm')) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete_all">
				<button class="btn nuBtnAct nuBtnDel" type="submit"><i class="bi bi-trash me-1"></i><?= h(t('debug_delete_all')) ?></button>
			</form>
		<?php endif; ?>
		<div class="form-check form-switch mb-0 debugAutoWrap">
			<input class="form-check-input" type="checkbox" role="switch" id="debugAuto">
			<label class="form-check-label small" for="debugAuto"><?= h(t('debug_auto')) ?></label>
		</div>
	</div>
</div>
	<form class="nuListFilter" method="get" action="index.php" role="search">
	<input type="hidden" name="p" value="debug">
	<?php if (nu_per_param()): ?><input type="hidden" name="per" value="<?= nu_per_param() ?>"><?php endif; ?>
	<?php $filtered = $flag !== '' || $user !== '' || $from !== '' || $to !== ''; ?>
	<div class="row g-2 align-items-end">
		<div class="col-md-6"><?= nu_list_search($q, '', 'nuDebugFilters', $filtered, $q !== '' || $filtered ? nu_url('debug') : null) ?></div>
		<div class="col-12 collapse<?= $filtered ? ' show' : '' ?>" id="nuDebugFilters"><div class="row g-2">
		<?php if ($hasFlag): ?>
			<div class="col-sm-6 col-lg-3"><label class="form-label small mb-1" for="f_flag"><?= h(t('debug_flag')) ?></label>
				<select class="form-select js-autosubmit" id="f_flag" name="flag"><option value=""><?= h(t('all')) ?></option><?php foreach ($flags as $f): ?><option<?= $f === $flag ? ' selected' : '' ?>><?= h($f) ?></option><?php endforeach; ?></select></div>
		<?php endif; ?>
		<?php if ($hasUser): ?>
			<div class="col-sm-6 col-lg-3"><label class="form-label small mb-1" for="f_user"><?= h(t('user')) ?></label>
				<select class="form-select js-autosubmit" id="f_user" name="user"><option value=""><?= h(t('all')) ?></option>
					<?php foreach ($users as $us): $uid = (string)$us['deb_user_id']; ?><option value="<?= h($uid) ?>"<?= $uid === $user ? ' selected' : '' ?>><?= h((string)($us['sus_login_name'] ?? '') ?: $uid) ?></option><?php endforeach; ?></select></div>
		<?php endif; ?>
		<div class="col-6 col-lg-3"><label class="form-label small mb-1" for="f_from"><?= h(t('log_from')) ?></label><input type="date" class="form-control" id="f_from" name="from" value="<?= h($from) ?>"></div>
		<div class="col-6 col-lg-3"><label class="form-label small mb-1" for="f_to"><?= h(t('log_to')) ?></label><input type="date" class="form-control" id="f_to" name="to" value="<?= h($to) ?>"></div>
		</div></div>
	</div>
</form>
<form method="post" id="debugList">
	<?= nu_csrf_field() ?>
	<div class="nuListBody">
		<?php if ($canDelete && $rows): ?>
			<div class="card-header d-flex align-items-center gap-2">
				<input class="form-check-input mt-0" type="checkbox" id="debugAll" aria-label="<?= h(t('debug_select_all')) ?>">
				<label class="small me-auto" for="debugAll"><?= h(t('debug_select_all')) ?></label>
				<button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="delete_selected" id="debugDeleteSel" disabled><i class="bi bi-trash me-1"></i><?= h(t('debug_delete_selected')) ?></button>
			</div>
		<?php endif; ?>
		<div class="list-group list-group-flush">
			<?php if (!$rows): ?><div class="list-group-item text-center text-body-secondary py-5"><?= h(t('no_records')) ?></div><?php endif; ?>
			<?php foreach ($rows as $r):
				$text = nu_debug_text((string)$r['deb_message']);
				$lines = explode("\n", $text);
				$first = $lines[0] ?? '';
				$long = count($lines) > 20 || mb_strlen($text) > 2000;
				$who = $hasUser ? ((string)($r['sus_login_name'] ?? '') ?: (string)($r['deb_user_id'] ?? '')) : ''; ?>
				<div class="list-group-item debugRow">
					<div class="d-flex flex-wrap gap-2 align-items-center mb-1">
						<?php if ($canDelete): ?><input class="form-check-input mt-0 debugSel" type="checkbox" name="ids[]" value="<?= h($r['zzzzsys_debug_id']) ?>" aria-label="<?= h(t('debug_select')) ?>"><?php endif; ?>
						<span class="small fw-semibold text-nowrap"><?= $r['deb_added'] ? h(date(nu_dfmt('d.m.Y H:i:s'), (int)$r['deb_added'])) : '' ?></span>
						<?php if ($who !== ''): ?><span class="small text-body-secondary"><i class="bi bi-person me-1"></i><?= h($who) ?></span><?php endif; ?>
						<?php if ($hasFlag && (string)$r['deb_flag'] !== ''): ?><span class="badge bg-warning-subtle text-warning-emphasis"><?= h($r['deb_flag']) ?></span><?php endif; ?>
						<span class="small text-body-secondary text-truncate debugFirst me-auto"><?= h(preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} - /', '', $first)) ?></span>
						<button type="button" class="btn btn-sm btn-link p-0 debugCopy" title="<?= h(t('debug_copy')) ?>" aria-label="<?= h(t('debug_copy')) ?>"><i class="bi bi-clipboard"></i></button>
					</div>
					<pre class="debugMsg mb-0<?= $long ? ' debugCollapsed' : '' ?>"><?= h(implode("\n", array_slice($lines, 1)) ?: $first) ?></pre>
					<?php if ($long): ?><button type="button" class="btn btn-sm btn-link px-0 debugMore" data-less="<?= h(t('debug_less')) ?>"><?= h(t('debug_more')) ?></button><?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php nu_list_footer($total, $pg, $per, 'debug', $keep, 'card-footer', 'pg', '', true); ?>
	</div>
</form>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nudebug.js') . '"></script>');
