<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Self-test (0.8.361, core/nuselftest.php): integration checks of this installation – only in developer mode (globeadmin +
 * $nuConfigDevMode), like "To do". POST action=run [groups[]] runs them (format=json: JSON for the browser tests of a release).
 * The form checks write into the sample application and delete their records again.
 */
if (!NuSelfTest::allowed()) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}

if (nu_is_post() && nu_post('action') === 'run') {
	$groups = array_values(array_intersect(array_map('strval', (array)($_POST['groups'] ?? [])), NuSelfTest::GROUPS));
	$res = NuSelfTest::run($groups);
	if (nu_post('format') === 'json') {
		header('Content-Type: application/json; charset=utf-8');
		exit(json_encode(['ok' => !in_array('fail', array_column($res, 'status'), true), 'results' => $res], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}
	$_SESSION['nu_selftest'] = ['at' => time(), 'groups' => $groups ?: NuSelfTest::GROUPS, 'results' => $res];
	nu_redirect(nu_url('selftest'));
}

$last = $_SESSION['nu_selftest'] ?? null;
$chosen = $last['groups'] ?? NuSelfTest::GROUPS;
$badge = static fn(string $s) => '<span class="badge ' . ['ok' => 'text-bg-success', 'warn' => 'text-bg-warning', 'fail' => 'text-bg-danger', 'skip' => 'text-bg-secondary'][$s] . ' nuStBadge">' . h(t('st_status_' . $s)) . '</span>';
nu_layout_start(t('st_title'), 'selftest');
?>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('st_title')) ?><?= NuListView::infoTip(t('st_hint')) ?><span class="badge text-bg-secondary ms-1 fw-normal" id="stDevBadge">Dev</span></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?></div>
	</div>
	<div class="card-body">
		<form method="post" class="d-flex flex-wrap align-items-center gap-3 mb-3" id="stForm" data-nu-once>
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="run">
			<?php foreach (NuSelfTest::GROUPS as $g): ?>
				<div class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="groups[]" value="<?= $g ?>" id="st_g_<?= $g ?>"<?= in_array($g, $chosen, true) ? ' checked' : '' ?>>
					<label class="form-check-label" for="st_g_<?= $g ?>"><?= h(t('st_group_' . $g)) ?></label></div>
			<?php endforeach; ?>
			<button type="submit" class="btn btn-primary" id="stRun" data-nu-busy="<?= h(t('st_running')) ?>"><i class="bi bi-play-fill me-1"></i><?= h(t('st_run')) ?></button>
		</form>
		<?php if ($last):
			$sum = array_count_values(array_column($last['results'], 'status')); ?>
			<p class="mb-2" id="stSummary"><?= h(t('st_last', date('d.m.Y H:i:s', (int)$last['at']))) ?>
				<?php foreach (['ok', 'warn', 'fail', 'skip'] as $s): if (!empty($sum[$s])): ?> <?= $badge($s) ?> <?= (int)$sum[$s] ?><?php endif; endforeach; ?></p>
			<div class="table-responsive"><table class="table table-sm align-middle nu-table mb-0" id="stResults">
				<?php // sortable titles (0.8.704, assets/js/nuselftest.js): data-sort = column kind; a second click reverses
				$sth = static fn(string $label, string $kind, string $cls = '') => '<th class="stSort' . ($cls !== '' ? ' ' . $cls : '') . '" data-sort="' . $kind . '"><button type="button" class="btn btn-link p-0 userPermSortBtn" title="' . h(t('acc_sort_by', $label)) . '">' . h($label) . '</button></th>'; ?>
				<thead><tr><?= $sth(t('st_group'), 'text') . $sth(t('st_check'), 'text') . $sth(t('ntf_status'), 'status') . $sth(t('log_details'), 'text') . $sth('ms', 'num', 'text-end') ?></tr></thead>
				<tbody>
				<?php foreach ($last['results'] as $r): ?>
					<tr data-st="<?= h($r['id']) ?>" data-status="<?= h($r['status']) ?>"><td class="small text-body-secondary"><?= h(t('st_group_' . $r['group'])) ?></td><td><?= h($r['title']) ?></td><td><?= $badge($r['status']) ?></td>
						<td class="small text-break"><?= h($r['detail']) ?></td><td class="text-end small text-body-secondary"><?= (int)$r['ms'] ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
		<?php else: ?>
			<p class="text-body-secondary mb-0"><?= h(t('st_not_run')) ?></p>
		<?php endif; ?>
	</div>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuselftest.js') . '"></script>');
