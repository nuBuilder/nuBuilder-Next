<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Getting ready (0.8.1310, core/nuready.php NuReady): the checklist after the installation, one group per step (?g=<group>).
 * POST action=mark (id, mark done|na|''), action=hide (mode later|forever|'' = show again; back = the page to return to).
 * The globeadmin only; nothing here changes a setting of the application – every point links to the page where it is done.
 */

if (!NuReady::allowed()) {
	http_response_code(403);
	nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true);
}

$groups = NuReady::GROUPS;
$g = (string)($_GET['g'] ?? '');

if (nu_is_post()) {
	$act = (string)nu_post('action');
	if ($act === 'mark') {
		NuReady::mark((string)nu_post('id'), (string)nu_post('mark'));
		$gb = (string)nu_post('g');
		nu_redirect(nu_url('gettingready', ['g' => isset($groups[$gb]) ? $gb : null]) . '#gr_' . preg_replace('~[^a-z0-9]~', '', (string)nu_post('id')));
	}
	if ($act === 'hide') {
		$mode = (string)nu_post('mode');
		NuReady::hide(in_array($mode, ['session', 'later', 'forever'], true) ? $mode : '');
		if ($mode !== '') nu_flash('info', t('gr_hidden_msg'));
		$back = NuAuth::validNext((string)nu_post('back'));
		nu_redirect($back !== null ? $back : nu_url('gettingready'));
	}
	nu_redirect(nu_url('gettingready'));
}

unset($_SESSION['nu_gr']);                                  // the page always shows the current state
$items = NuReady::items();
$sum = NuReady::summarise($items);
$_SESSION['nu_gr'] = $sum + ['t' => time()];
$byGroup = [];
foreach ($items as $id => $it) $byGroup[$it['group']][$id] = $it;
$groups = array_filter($groups, static fn($k) => isset($byGroup[$k]), ARRAY_FILTER_USE_KEY);
if (!isset($groups[$g])) {                                  // first group with an open point, else the first
	$g = (string)array_key_first($groups);
	foreach ($groups as $k => $_) {
		foreach ($byGroup[$k] as $it) if ($it['open']) { $g = $k; break 2; }
	}
}
$keys = array_keys($groups);
$pos = (int)array_search($g, $keys, true);
$hide = NuReady::state()['hide'];
$stIcon = ['ok' => 'bi-check-circle-fill', 'warn' => 'bi-exclamation-triangle-fill', 'bad' => 'bi-x-circle-fill', 'todo' => 'bi-circle'];
$stText = ['ok' => 'gr_st_ok', 'warn' => 'gr_st_warn', 'bad' => 'gr_st_bad', 'todo' => 'gr_st_todo'];
$markBtn = static function (string $id, string $mark, string $label, string $cls, string $group) {
	return '<form method="post" action="' . h(nu_url('gettingready')) . '" class="d-inline m-0">' . nu_csrf_field()
		. '<input type="hidden" name="action" value="mark"><input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="g" value="' . h($group) . '">'
		. '<input type="hidden" name="mark" value="' . h($mark) . '"><button type="submit" class="btn btn-sm ' . $cls . '" data-gr-mark="' . h($mark) . '">' . h($label) . '</button></form>';
};

nu_layout_start(t('gr_title'), 'gettingready', ['container' => 'nuContainer']);
$hideForm = static function (string $mode, string $label, string $cls, string $id) {
	return '<form method="post" action="' . h(nu_url('gettingready')) . '" class="d-inline m-0">' . nu_csrf_field()
		. '<input type="hidden" name="action" value="hide"><input type="hidden" name="mode" value="' . h($mode) . '"><input type="hidden" name="back" value="index.php?p=gettingready">'
		. '<button type="submit" class="btn ' . $cls . '" id="' . h($id) . '">' . h($label) . '</button></form>';
};
$buttons = $hide === null && empty($_SESSION['nu_gr_hide'])
	? '<div class="dropdown"><button type="button" class="btn nuBtnAct dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" id="grHideMenu"><i class="bi bi-eye-slash me-1"></i>' . h(t('gr_hide')) . '</button>'
		. '<ul class="dropdown-menu dropdown-menu-end"><li>' . $hideForm('session', t('gr_hide_session'), 'dropdown-item', 'grHideSession') . '</li><li>' . $hideForm('later', t('gr_hide_later', NuReady::LATER_DAYS), 'dropdown-item', 'grHideLater') . '</li>'
		. '<li>' . $hideForm('forever', t('gr_hide_forever'), 'dropdown-item', 'grHideForever') . '</li></ul></div>'
	: $hideForm('', t('gr_show_again'), 'nuBtnAct', 'grShowAgain');
echo nu_page_header(t('gr_title'), ['icon' => 'bi-rocket-takeoff', 'sub' => t('gr_sub'), 'buttons' => $buttons]);
?>
<?php if ($hide !== null): ?>
<div class="alert alert-secondary py-2 small" id="grHiddenNote"><i class="bi bi-eye-slash me-1"></i><?= h(t($hide['mode'] === 'forever' ? 'gr_hidden_forever' : 'gr_hidden_later', date(nu_dfmt('d.m.Y'), $hide['until']))) ?></div>
<?php endif; ?>
<div class="nuGrHead d-flex align-items-center gap-3 mb-3">
	<?= NuReady::ring($sum['done'], $sum['total'], 52) ?>
	<div class="flex-grow-1">
		<div class="fw-semibold" id="grCount"><?= h(t('gr_count', $sum['done'], $sum['total'])) ?><?php if ($sum['bad']): ?> <span class="badge text-bg-danger ms-1" id="grBad"><?= h(t('gr_to_fix', $sum['bad'])) ?></span><?php endif; ?></div>
		<progress class="nuGrProgress" max="<?= (int)$sum['total'] ?>" value="<?= (int)$sum['done'] ?>" aria-label="<?= h(t('gr_count', $sum['done'], $sum['total'])) ?>"></progress>
	</div>
</div>
<div class="nuGrWrap">
	<nav class="nuGrSteps" aria-label="<?= h(t('gr_steps')) ?>"><ol>
	<?php foreach ($groups as $k => [$icon, $label]):
		$list = $byGroup[$k]; $open = count(array_filter($list, static fn($it) => $it['open'])); $red = count(array_filter($list, static fn($it) => $it['open'] && $it['st'] === 'bad')); ?>
		<li><a class="nuGrStep<?= $k === $g ? ' active' : '' ?><?= $open ? '' : ' nuGrStepDone' ?>" href="<?= h(nu_url('gettingready', ['g' => $k])) ?>" data-gr-group="<?= h($k) ?>"<?= $k === $g ? ' aria-current="step"' : '' ?>>
			<i class="bi <?= $open ? $icon : 'bi-check-circle-fill' ?>" aria-hidden="true"></i><span class="nuGrStepName"><?= h(t($label)) ?></span>
			<span class="nuGrStepCount<?= $red ? ' nuGrStepRed' : '' ?>"><?= count($list) - $open ?>/<?= count($list) ?></span></a></li>
	<?php endforeach; ?>
	</ol></nav>
	<section class="nuGrPane" id="grPane" data-gr-group="<?= h($g) ?>">
		<h2 class="h5 mb-1"><i class="bi <?= h($groups[$g][0]) ?> me-2" aria-hidden="true"></i><?= h(t($groups[$g][1])) ?></h2>
		<p class="text-body-secondary small mb-3"><?= h(t('gr_gd_' . $g)) ?></p>
		<ul class="nuGrItems list-unstyled mb-3">
		<?php foreach ($byGroup[$g] as $id => $it):
			$st = $it['mark'] === 'done' ? 'ok' : $it['st']; ?>
			<li class="nuGrItem nuGrSt-<?= h($st) ?><?= $it['mark'] === 'na' ? ' nuGrNa' : '' ?>" id="gr_<?= h($id) ?>" data-gr-item="<?= h($id) ?>" data-gr-st="<?= h($it['st']) ?>" data-gr-mark="<?= h($it['mark']) ?>">
				<i class="bi <?= $it['mark'] === 'na' ? 'bi-dash-circle' : $stIcon[$st] ?> nuGrIcon" role="img" aria-label="<?= h(t($it['mark'] === 'na' ? 'gr_st_na' : $stText[$st])) ?>"></i>
				<div class="nuGrBody">
					<div class="fw-semibold"><?= h(t('gr_i_' . $id)) ?><?php if ($it['kind'] === 'manual'): ?> <span class="badge text-bg-secondary nuGrKind"><?= h(t('gr_manual')) ?></span><?php endif; ?></div>
					<div class="small text-body-secondary"><?= h(t('gr_i_' . $id . '_d')) ?></div>
					<?php if ($it['detail'] !== '' && $it['st'] !== 'ok'): ?><div class="small nuGrDetail"><?= h($it['detail']) ?></div><?php endif; ?>
				</div>
				<div class="nuGrActs">
					<a class="btn btn-sm <?= $it['open'] ? 'btn-outline-primary' : 'btn-outline-secondary' ?>" href="<?= h($it['url']) ?>"><?= h(t('gr_go')) ?></a>
					<?php if ($it['mark'] !== ''): ?>
						<?= $markBtn($id, '', t('gr_undo'), 'btn-link', $g) ?>
					<?php elseif ($it['open']): ?>
						<?php if ($it['kind'] === 'manual'): ?><?= $markBtn($id, 'done', t('gr_mark_done'), 'btn-outline-success', $g) ?><?php endif; ?>
						<?= $markBtn($id, 'na', t('gr_mark_na'), 'btn-link', $g) ?>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
		</ul>
		<div class="d-flex gap-2 nuGrNav">
			<?php if ($pos > 0): ?><a class="btn btn-outline-secondary" id="grBack" href="<?= h(nu_url('gettingready', ['g' => $keys[$pos - 1]])) ?>"><i class="bi bi-chevron-left me-1"></i><?= h(t('back')) ?></a><?php endif; ?>
			<?php if ($pos < count($keys) - 1): ?><a class="btn btn-primary ms-auto" id="grNext" href="<?= h(nu_url('gettingready', ['g' => $keys[$pos + 1]])) ?>"><?= h(t('gr_next')) ?><i class="bi bi-chevron-right ms-1"></i></a>
			<?php else: ?><a class="btn btn-primary ms-auto" id="grDone" href="<?= h(nu_url('home')) ?>"><?= h(t('gr_finish')) ?></a><?php endif; ?>
		</div>
	</section>
</div>
<?php nu_layout_end('<script src="' . nu_asset('assets/js/nugettingready.js') . '"></script>'); ?>
