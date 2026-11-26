<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Compatibility check → Hash variables outside quotes → Fix (core/nuhashfix.php): every spot with its line before / after, a tick per spot
 * that can be fixed safely, the others with the reason; POST action fix (globeadmin, password confirmed) and undo of a run.
 */
NuAdmin::require('compat', 'view');
nu_time_limit(120);

NuHashfixPage::post(get_defined_vars());   // the POST actions (core/nuhashfixpage.php)

$canFix = NuCodeSearch::canReplace();
$parts = NuHashFix::parts();
$total = 0;
$fixable = 0;
foreach ($parts as $p) foreach ($p['spots'] as $sp) { $total++; if ($sp['why'] === '' && $p['block'] === '') $fixable++; }
$batches = $canFix ? NuHashFix::batches(10) : [];

nu_layout_start(t('hqf_title'), 'home');
$buttons = $canFix && $fixable ? '<button type="submit" form="hfForm" class="btn btn-primary" id="hfGo" data-label="' . h(t('hqf_go')) . '"><i class="bi bi-quote me-1"></i>'
	. '<span>' . h(t('hqf_go')) . '</span> <span class="badge text-bg-light ms-1" id="hfGoN">' . $fixable . '</span></button>' : '';
echo nu_page_header(t('hqf_title'), ['icon' => 'bi-shield-exclamation', 'sub' => t('hqf_sub', $total, $fixable), 'tip' => t('hqf_tip'), 'buttons' => $buttons,
	'back' => nu_url('compat') . '#compat_hash', 'crumbs' => [[t('compat_title'), nu_url('compat')]]]);
?>
<?php if (!$parts): ?>
<div class="alert alert-success" id="hfNone"><i class="bi bi-check-circle me-1"></i><?= h(t('compat_hash_none')) ?></div>
<?php else: ?>
<?php if ($canFix && $fixable): ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-2 small">
	<button type="button" class="btn btn-sm btn-outline-secondary" id="hfAll"><?= h(t('hqf_all')) ?></button>
	<button type="button" class="btn btn-sm btn-outline-secondary" id="hfNoneBtn"><?= h(t('hqf_none')) ?></button>
	<span class="text-body-secondary"><?= h(t('hqf_undo_note')) ?></span>
</div>
<?php elseif (!$canFix): ?>
<div class="alert alert-info small" id="hfGaOnly"><?= h(t('hqf_ga_only')) ?></div>
<?php endif; ?>
<form method="post" id="hfForm" data-nu-once data-confirm="<?= h(t('hqf_confirm')) ?>" data-confirm-ok="<?= h(t('hqf_go')) ?>">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="fix">
	<?php foreach ($parts as $p): $tickable = $canFix && $p['block'] === ''; ?>
	<div class="card shadow-sm mb-3 hfPart" data-key="<?= h($p['key']) ?>">
		<div class="card-header d-flex flex-wrap gap-2 align-items-center">
			<span class="fw-semibold"><?= h($p['gtitle']) ?></span><span class="text-body-secondary small"><?= h($p['label']) ?></span>
			<?php if (strtoupper((string)$p['label']) !== strtoupper($p['lang'])): ?><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h(strtoupper($p['lang'])) ?></span><?php endif; ?>
			<?php if (($p['url'] ?? '') !== ''): ?><a class="small ms-auto" href="<?= h($p['url']) ?>" target="_blank" rel="noopener"><i
				class="bi bi-box-arrow-up-right me-1"></i><?= h(t('hqf_open')) ?></a><?php endif; ?>
		</div>
		<?php if ($p['block'] !== ''): ?><div class="px-3 pt-2 small text-warning-emphasis hfBlock"><i class="bi bi-lock me-1"></i><?= h($p['block']) ?></div><?php endif; ?>
		<?php if ($tickable): ?><input type="hidden" name="h[<?= h($p['key']) ?>]" value="<?= h($p['hash']) ?>"><?php endif; ?>
		<div class="card-body py-2">
		<?php foreach ($p['spots'] as $sp): [$before, $after] = NuHashFix::lines((string)$p['code'], $sp); $ok = $sp['why'] === ''; ?>
			<div class="hfSpot<?= $ok ? '' : ' hfManual' ?>" data-var="<?= h($sp['var']) ?>" data-why="<?= h($sp['why']) ?>">
				<div class="hfLine"><span class="hfNo"><?= (int)$sp['line'] ?></span><?php if ($tickable && $ok): ?><input class="form-check-input hfTick" type="checkbox"
					name="t[<?= h($p['key']) ?>][]" value="<?= (int)$sp['pos'] ?>" checked aria-label="<?= h(t('cs_h_line') . ' ' . (int)$sp['line']) ?>"><?php else: ?><span
					class="hfGap"></span><?php endif; ?><code class="hfText"><?= $before ?></code></div>
				<?php if ($ok): ?><div class="hfLine hfAfter"><span class="hfNo">→</span><span class="hfGap"></span><code class="hfText"><?= $after ?></code></div>
				<?php if (($sp['note'] ?? '') !== ''): ?><div class="hfWhy hfNote small text-body-secondary"><i class="bi bi-info-circle me-1"></i><?= h(t('hqf_note_' . $sp['note'])) ?></div><?php endif; ?>
				<?php else: ?><div class="hfWhy small text-body-secondary"><i class="bi bi-hand-index me-1"></i><?= h(t('hqf_why_' . $sp['why'])) ?></div><?php endif; ?>
			</div>
		<?php endforeach; ?>
		</div>
	</div>
	<?php endforeach; ?>
</form>
<?php endif; ?>
<?php if ($batches): ?>
<div class="card shadow-sm mb-3" id="hfBatches">
	<div class="card-header fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('hqf_runs')) ?></div>
	<ul class="list-group list-group-flush">
	<?php foreach ($batches as $b): ?>
		<li class="list-group-item d-flex flex-wrap gap-2 align-items-center small">
			<span><?= h(date(nu_dfmt('d.m.Y H:i'), (int)strtotime((string)$b['created_at']))) ?> · <?= h((string)$b['login']) ?></span>
			<span class="text-body-secondary"><?= h(t('hqf_done', (int)$b['line_count'], (int)$b['part_count'])) ?></span>
			<?php if (!empty($b['undone_at'])): ?><span class="badge text-bg-secondary ms-auto"><?= h(t('hqf_undone')) ?></span>
			<?php else: ?><form method="post" class="ms-auto"><?= nu_csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="id"
				value="<?= h((string)$b['id']) ?>"><button type="submit" class="btn btn-sm btn-outline-secondary hfUndo"><i
				class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('cs_rp_undo')) ?></button></form><?php endif; ?>
		</li>
	<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>
<?php
nu_layout_end('<script src="' . h(nu_asset('assets/js/nuhashfix.js')) . '"></script>');
