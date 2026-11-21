<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Self-test (core/nuselftest.php): integration checks of this installation – only in developer mode (globeadmin +
 * $nuConfigDevMode), like "To do". POST action=run [groups[]] runs them (format=json: JSON for the browser tests of a release).
 * The form checks write into the sample application and delete their records again.
 */
if (!NuSelfTest::allowed()) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}

NuSelftestPage::post(get_defined_vars());   // the POST actions (code review R7, nuselftestpage.php)

$last = $_SESSION[NuSess::SELFTEST] ?? null;
$chosen = $last['groups'] ?? NuSelfTest::GROUPS;
$badge = static fn(string $s) => '<span class="badge '
	. ['ok' => 'text-bg-success', 'warn' => 'text-bg-warning', 'fail' => 'text-bg-danger', 'skip' => 'text-bg-secondary'][$s] . ' nuStBadge">'
	. h(t('st_status_' . $s)) . '</span>';
// the results as a system list: a derived table of the session's results – NuListDefs 'selftest' (no temporary table)
NuSelfTest::source($last['results'] ?? []);
$st = NuListView::prepare('selftest');                               // Print / CSV / Excel end here
$sum = $last ? array_count_values(array_column($last['results'], 'status')) : [];
ob_start(); ?>
<form method="post" class="d-flex flex-wrap align-items-center gap-3 px-3 pt-3 pb-2" id="stForm" data-nu-once>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="run">
	<?php foreach (NuSelfTest::GROUPS as $g): ?>
		<div class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="groups[]" value="<?= $g ?>"
			id="st_g_<?= $g ?>"<?= in_array($g, $chosen, true) ? ' checked' : '' ?>>
			<label class="form-check-label" for="st_g_<?= $g ?>"><?= h(t('st_group_' . $g)) ?></label></div>
	<?php endforeach; ?>
	<button type="submit" class="btn btn-primary" id="stRun" data-nu-busy="<?= h(t('st_running')) ?>"><i class="bi bi-play-fill me-1"></i><?= h(t('st_run')) ?></button>
	<?php if ($last): ?>
		<span class="small text-body-secondary ms-auto" id="stSummary"><?= h(t('st_last', date(nu_dfmt('d.m.Y H:i:s'), (int)$last['at']))) ?>
			<?php foreach (['ok', 'warn', 'fail', 'skip'] as $s): if (!empty($sum[$s])): ?> <?= $badge($s) ?> <?= (int)$sum[$s] ?><?php endif; endforeach; ?></span>
	<?php else: ?>
		<span class="small text-body-secondary ms-auto" id="stNotRun"><?= h(t('st_not_run')) ?></span>
	<?php endif; ?>
</form>
<?php $controls = ob_get_clean();
nu_layout_start(t('st_title'), 'selftest', ['head' => NuListView::head() . nu_ts_head()]);
echo NuListView::card($st, ['title_extra' => NuListView::infoTip(t('st_hint')) . '<span class="badge text-bg-secondary ms-1 fw-normal" id="stDevBadge">Dev</span>', 'above_rows' => $controls]);
nu_layout_end(NuListView::foot() . nu_ts_foot());
