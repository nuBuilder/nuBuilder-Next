<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Tutorial (0.8.328) – globeadmin and users with an admin role. Text: docs/nunext-tutorial.htm (English), logic: core/nututorial.php.
 *   ?p=tutorial         overview (chapters, progress, start / continue)
 *   ?p=tutorial&l=<id>  one lesson
 * POST action: done (and go to the next lesson) | undo | hide | show | reset | track (basic / advanced, 0.8.622) – the reader's own
 * progress only. Track not chosen yet: the overview is the choice screen (a lesson link goes there too, l is kept).
 */

if (!NuTutorial::allowed()) {
	http_response_code(403);
	nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true);
}

$order = NuTutorial::order();
$ids = array_keys($order);
$all = NuTutorial::allLessons();                        // every lesson the reader may see: id => advanced | forte (advanced ⊇ basic)

if (nu_is_post()) {
	$p = NuTutorial::progress();
	$id = nu_post('l');
	$back = nu_post('back') === 'home' ? nu_url('home') : nu_url('tutorial', $id !== '' && isset($all[$id]) ? ['l' => $id] : []);
	switch (nu_post('action')) {
		case 'done':
			if (isset($order[$id])) {
				$p['done'][] = $id;
				$pos = array_search($id, $ids, true);
				$next = $ids[$pos + 1] ?? '';
				$back = nu_url('tutorial', $next !== '' ? ['l' => $next] : ['finished' => '1']);
			} elseif (isset($all[$id])) $p['done'][] = $id;           // a lesson of the other track: stays on it
			break;
		case 'track':
			$tr = nu_post('track');
			if (in_array($tr, NuTutorial::TRACKS, true)) {
				$first = $p['track'] === '';
				$p['track'] = $tr;
				NuTutorial::save($p);
				if ($id === '' && nu_post('go') === 'start') $id = NuTutorial::nextOpen($p);
				$tOrder = NuTutorial::order($tr);
				$back = nu_url('tutorial', $id !== '' && (isset($tOrder[$id]) || isset($all[$id])) ? ['l' => $id] : []);
				if (!$first) nu_flash('info', t('tut_track_switched', t('tut_track_' . $tr)));
			}
			break;
		case 'undo':
			$p['done'] = array_values(array_diff($p['done'], [$id]));
			break;
		case 'hide':
			$p['hidden'] = true;
			nu_flash('info', t('tut_hidden'));
			break;
		case 'show':
			$p['hidden'] = false;
			break;
		case 'reset':
			$p['done'] = [];
			$p['hidden'] = false;
			$back = nu_url('tutorial');
			break;
	}
	NuTutorial::save($p);
	nu_redirect($back);
}

$chapters = NuTutorial::chapters();
$prog = NuTutorial::progress();
$track = $prog['track'];
[$nDone, $nTotal] = NuTutorial::counts($prog);
$pct = $nTotal ? (int)round($nDone * 100 / $nTotal) : 0;
$cur = (string)($_GET['l'] ?? '');
if ($cur !== '' && !isset($all[$cur])) $cur = '';
$choose = $track === '';                               // first opening: choice screen (also for a lesson link – l is kept)
$wanted = $choose ? $cur : '';
if ($choose) $cur = '';
$next = NuTutorial::nextOpen($prog);
$isDone = static fn(string $id): bool => in_array($id, $prog['done'], true);

$title = t('tut_title');
$lesson = null; $chapter = null; $prevId = ''; $nextId = ''; $outside = false;
if ($cur !== '') {
	$outside = !isset($order[$cur]);                   // a lesson of another track (link from a lesson): advanced or nuBuilder Forte
	$outTrack = $outside ? $all[$cur] : '';
	[$ci, $li] = $outside ? NuTutorial::order($outTrack)[$cur] : $order[$cur];
	$chapter = ($outside ? NuTutorial::chapters($outTrack) : $chapters)[$ci];
	$lesson = $chapter['lessons'][$li];
	if (!$outside) {
		$pos = array_search($cur, $ids, true);
		$prevId = $ids[$pos - 1] ?? '';
		$nextId = $ids[$pos + 1] ?? '';
	}
	$title = $lesson['title'] . ' · ' . t('tut_title');
}
$parts = ['basics' => 'tut_part_basics', 'build' => 'tut_part_build', 'admin' => 'tut_part_admin', 'advanced' => 'tut_part_advanced',
	'changes' => 'tut_part_changes', 'new' => 'tut_part_new'];   // nuBuilder Forte track (0.8.635)
$trackSwitch = static function (string $l) use ($track): string {   // Basic | Advanced in the title bar
	$o = '<form method="post" class="btn-group" role="group" aria-label="' . h(t('tut_track_label')) . '" id="tutTrack">' . nu_csrf_field()
		. '<input type="hidden" name="action" value="track"><input type="hidden" name="l" value="' . h($l) . '">';
	foreach (NuTutorial::TRACKS as $tr) {
		$on = $tr === $track;
		$o .= '<button class="btn ' . ($on ? 'btn-primary' : 'btn-outline-primary') . '" type="submit" name="track" value="' . $tr . '"' . ($on ? ' aria-pressed="true"' : ' aria-pressed="false"')
			. ' data-track="' . $tr . '"><i class="bi ' . ['basic' => 'bi-signpost-2', 'advanced' => 'bi-rocket-takeoff', 'forte' => 'bi-arrow-left-right'][$tr] . ' me-1"></i>' . h(t('tut_track_' . $tr)) . '</button>';
	}
	return $o . '</form>';
};

nu_layout_start($title, 'home');
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
		<?php if ($lesson): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('tutorial')) ?>"><?= h(t('tut_title')) ?></a></li>
			<li class="breadcrumb-item active" aria-current="page"><?= h($lesson['title']) ?></li>
		<?php else: ?>
			<li class="breadcrumb-item active" aria-current="page"><?= h(t('tut_title')) ?></li>
		<?php endif; ?>
	</ol>
</nav>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-mortarboard me-2"></i><?= h(t('tut_title')) ?></h1>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button(null, $lesson ? nu_url('tutorial') : nu_url('home')) ?>
		<?php if (!$choose): ?><?= $trackSwitch($cur) ?><?php endif; ?>
		<a class="btn nuBtnAct" href="<?= h(nu_url('help')) ?>" target="_blank" rel="noopener"><i class="bi bi-book me-1"></i><?= h(t('help_title')) ?></a>
	</div>
</div>
<?= nu_docs_update_notice('docs_newer_tutorial') ?>
<?php if (nu_lang() !== 'en'): ?><div class="small text-body-secondary mb-2"><i class="bi bi-translate me-1"></i><?= h(t('tut_english')) ?></div><?php endif; ?>

<?php if ($choose):
	$nB = count(NuTutorial::order('basic')); $nA = count(NuTutorial::order('advanced')); $nF = count(NuTutorial::order('forte')); ?>
<div class="tutChoose" id="tutChoose">
	<h2 class="h5 mb-1"><?= h(t('tut_choose_title')) ?></h2>
	<p class="text-body-secondary mb-3"><?= h(t('tut_choose_intro')) ?></p>
	<div class="row g-3">
		<?php foreach (['basic' => ['bi-signpost-2', $nB], 'advanced' => ['bi-rocket-takeoff', $nA], 'forte' => ['bi-arrow-left-right', $nF]] as $tr => [$ic, $n]): if (!$n) continue; ?>
		<div class="col-md-6 col-xl-4">
			<form method="post" class="card shadow-sm h-100 tutChoice" data-track="<?= $tr ?>"><?= nu_csrf_field() ?>
				<input type="hidden" name="action" value="track"><input type="hidden" name="track" value="<?= $tr ?>">
				<input type="hidden" name="l" value="<?= h($wanted) ?>"><input type="hidden" name="go" value="start">
				<div class="card-body d-flex flex-column">
					<div class="d-flex align-items-center gap-2 mb-2"><i class="bi <?= $ic ?> fs-3 tutChoiceIcon" aria-hidden="true"></i>
						<h3 class="h5 mb-0 me-auto"><?= h(t('tut_track_' . $tr)) ?></h3>
						<span class="badge text-bg-light border"><?= h(t('tut_lessons_n', $n)) ?></span></div>
					<p class="mb-3 flex-grow-1"><?= h(t('tut_' . $tr . '_desc')) ?></p>
					<div><button class="btn <?= $tr === 'basic' ? 'btn-primary' : 'btn-outline-primary' ?>" type="submit" id="tutChoose_<?= $tr ?>"><i class="bi bi-play-fill me-1"></i><?= h(t('tut_choose_btn_' . $tr)) ?></button></div>
				</div>
			</form>
		</div>
		<?php endforeach; ?>
	</div>
	<?php if ($nDone): ?><p class="small text-body-secondary mt-3 mb-0"><?= h(t('tut_choose_shared')) ?></p><?php endif; ?>
</div>
<?php else: ?>
<div class="tutProgress mb-3">
	<div class="d-flex justify-content-between small mb-1"><span><?= h(t('tut_progress', $nDone, $nTotal)) ?></span><span><?= $pct ?>%</span></div>
	<div class="progress" role="progressbar" aria-label="<?= h(t('tut_progress', $nDone, $nTotal)) ?>" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
		<div class="progress-bar tutBar" data-pct="<?= $pct ?>"></div>
	</div>
</div>

<div class="row g-3">
	<div class="col-lg-4 col-xl-3 order-2 order-lg-1">
		<nav class="tutIndex card shadow-sm" aria-label="<?= h(t('tut_contents')) ?>">
			<div class="card-header fw-semibold d-flex align-items-center"><span class="me-auto"><?= h(t('tut_contents')) ?></span>
				<a class="small" href="<?= h(nu_url('tutorial')) ?>"><?= h(t('tut_overview')) ?></a></div>
			<div class="list-group list-group-flush">
				<?php $lastPart = ''; foreach ($chapters as $c): ?>
					<?php if ($c['part'] !== $lastPart && isset($parts[$c['part']])): $lastPart = $c['part']; ?>
						<div class="list-group-item tutPart"><?= h(t($parts[$c['part']])) ?></div>
					<?php endif; ?>
					<?php $cAdv = !array_filter($c['lessons'], static fn($x) => !$x['advanced']); ?>
					<div class="list-group-item tutChapter"><?= h($c['title']) ?><?php if ($cAdv): ?> <span class="badge tutAdvBadge ms-1"><?= h(t('tut_adv_badge')) ?></span><?php endif; ?></div>
					<?php foreach ($c['lessons'] as $l): $d = $isDone($l['id']); ?>
						<a class="list-group-item list-group-item-action tutLessonLink<?= $l['id'] === $cur ? ' active' : '' ?>" href="<?= h(nu_url('tutorial', ['l' => $l['id']])) ?>"<?= $l['id'] === $cur ? ' aria-current="page"' : '' ?>>
							<i class="bi <?= $d ? 'bi-check-circle-fill text-success' : 'bi-circle text-body-tertiary' ?> me-2" aria-hidden="true"></i><?= h($l['title']) ?>
							<?php if ($l['advanced'] && !$cAdv): ?><span class="badge tutAdvBadge ms-1"><?= h(t('tut_adv_badge')) ?></span><?php endif; ?>
							<?php if ($d): ?><span class="visually-hidden"> (<?= h(t('tut_done_badge')) ?>)</span><?php endif; ?>
						</a>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
		</nav>
	</div>

	<div class="col-lg-8 col-xl-9 order-1 order-lg-2">
	<?php if ($lesson): ?>
		<article class="card shadow-sm tutLesson">
			<div class="card-body">
				<div class="small text-body-secondary mb-1"><?= h($chapter['title']) ?><?php if (!$outside): ?> · <?= h(t('tut_lesson_n', array_search($cur, $ids, true) + 1, $nTotal)) ?><?php endif; ?>
					<?php if ($lesson['advanced']): ?> <span class="badge tutAdvBadge ms-1"><?= h(t('tut_adv_badge')) ?></span><?php endif; ?></div>
				<?php if ($outside): ?>
					<div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2" id="tutOutside"><i class="bi <?= $outTrack === 'forte' ? 'bi-arrow-left-right' : 'bi-rocket-takeoff' ?>"></i><span class="me-auto"><?= h(t($outTrack === 'forte' ? 'tut_out_of_track_forte' : 'tut_out_of_track')) ?></span>
						<form method="post"><?= nu_csrf_field() ?><input type="hidden" name="action" value="track"><input type="hidden" name="track" value="<?= h($outTrack) ?>"><input type="hidden" name="l" value="<?= h($cur) ?>">
							<button class="btn btn-sm btn-primary" type="submit"><?= h(t($outTrack === 'forte' ? 'tut_switch_forte' : 'tut_switch_advanced')) ?></button></form></div>
				<?php endif; ?>
				<h2 class="h4 mb-3 d-flex align-items-center gap-2"><?= h($lesson['title']) ?>
					<?php if ($isDone($cur)): ?><span class="badge text-bg-success fs-6 fw-normal"><i class="bi bi-check2 me-1"></i><?= h(t('tut_done_badge')) ?></span><?php endif; ?></h2>
				<div class="tutBody"><?= $lesson['html'] /* from docs/nunext-tutorial.htm, part of the package */ ?></div>
			</div>
			<div class="card-footer d-flex flex-wrap gap-2 align-items-center">
				<?php if ($prevId !== ''): ?>
					<a class="btn btn-outline-secondary" href="<?= h(nu_url('tutorial', ['l' => $prevId])) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('tut_prev')) ?></a>
				<?php endif; ?>
				<span class="me-auto"></span>
				<form method="post" class="d-inline"><?= nu_csrf_field() ?><input type="hidden" name="l" value="<?= h($cur) ?>">
					<?php if ($isDone($cur)): ?>
						<button class="btn btn-outline-secondary" type="submit" name="action" value="undo"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('tut_undo')) ?></button>
					<?php else: ?>
						<button class="btn btn-success" type="submit" name="action" value="done"><i class="bi bi-check2 me-1"></i><?= h(t($nextId !== '' ? 'tut_mark_done' : 'tut_mark_done_last')) ?></button>
					<?php endif; ?>
				</form>
				<?php if ($nextId !== ''): ?>
					<a class="btn btn-outline-primary" href="<?= h(nu_url('tutorial', ['l' => $nextId])) ?>"><?= h(t('tut_next')) ?><i class="bi bi-arrow-right ms-1"></i></a>
				<?php endif; ?>
			</div>
		</article>
	<?php else: ?>
		<?php if (isset($_GET['finished']) || ($nTotal && $nDone >= $nTotal)): ?>
			<div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-trophy fs-4"></i><div><?= h(t('tut_all_done')) ?></div></div>
		<?php endif; ?>
		<div class="card shadow-sm mb-3">
			<div class="card-body">
				<p class="mb-3"><?= h(t('tut_intro')) ?></p>
				<div class="d-flex flex-wrap gap-2">
					<?php if ($next !== ''): ?>
						<a class="btn btn-primary" href="<?= h(nu_url('tutorial', ['l' => $next])) ?>"><i class="bi bi-play-fill me-1"></i><?= h($nDone ? t('tut_continue', $chapters[$order[$next][0]]['lessons'][$order[$next][1]]['title']) : t('tut_start')) ?></a>
					<?php endif; ?>
					<?php if ($nDone): ?>
						<form method="post" data-confirm="<?= h(t('tut_reset_confirm')) ?>" data-confirm-ok="<?= h(t('tut_reset')) ?>"><?= nu_csrf_field() ?>
							<button class="btn btn-outline-secondary" type="submit" name="action" value="reset"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('tut_reset')) ?></button></form>
					<?php endif; ?>
					<form method="post"><?= nu_csrf_field() ?>
						<button class="btn btn-outline-secondary" type="submit" name="action" value="<?= $prog['hidden'] ? 'show' : 'hide' ?>"><i class="bi <?= $prog['hidden'] ? 'bi-eye' : 'bi-eye-slash' ?> me-1"></i><?= h(t($prog['hidden'] ? 'tut_banner_show' : 'tut_banner_hide')) ?></button></form>
				</div>
			</div>
		</div>
		<?php $lastPart = ''; foreach ($chapters as $c):
			$cDone = count(array_filter($c['lessons'], static fn($l) => $isDone($l['id']))); ?>
			<?php if ($c['part'] !== $lastPart && isset($parts[$c['part']])): $lastPart = $c['part']; ?>
				<h2 class="h6 text-uppercase text-body-secondary mt-4 mb-2"><?= h(t($parts[$c['part']])) ?></h2>
			<?php endif; ?>
			<div class="card shadow-sm mb-2 tutChapterCard">
				<div class="card-body py-2">
					<div class="d-flex flex-wrap align-items-center gap-2">
						<a class="fw-semibold me-auto" href="<?= h(nu_url('tutorial', ['l' => $c['lessons'][0]['id']])) ?>"><?= h($c['title']) ?></a>
						<?php if (!array_filter($c['lessons'], static fn($x) => !$x['advanced'])): ?><span class="badge tutAdvBadge"><?= h(t('tut_adv_badge')) ?></span><?php endif; ?>
						<span class="badge <?= $cDone === count($c['lessons']) ? 'text-bg-success' : 'text-bg-light border' ?>"><?= h(t('tut_chapter_done', $cDone, count($c['lessons']))) ?></span>
					</div>
					<?php if ($c['intro'] !== ''): ?><div class="small text-body-secondary mt-1"><?= h($c['intro']) ?></div><?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
	</div>
</div>
<?php endif; /* choice screen */ ?>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nututorial.js') . '"></script>');
