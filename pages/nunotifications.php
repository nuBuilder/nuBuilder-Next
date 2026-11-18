<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Notification centre (0.8.357, core/nunotify.php):
 *   ?p=notifications[&f=unread|all|hidden]   the user's own notifications (everybody who is signed in)
 *   &tab=manage                                notifications for senders / viewers (globeadmin, admin role "notifications", permission items
 *                                              NUNEXT_NOTIFY / NUNEXT_NOTIFY_OWN) – status, recipients, read receipts, settings
 *   &id=new|<id>                               create / edit (senders) · &id=<id>&receipts=1 read receipts
 */
$u = NuAuth::user();
NuSchema::ensure();
$canManage = NuNotify::canManage();
$canSend = NuNotify::canSend();
$own = $canSend && !NuNotify::canSendAll();
$tab = (string)($_GET['tab'] ?? '') === 'manage' || isset($_GET['id']) ? 'manage' : 'mine';
$filter = in_array($_GET['f'] ?? '', ['unread', 'hidden'], true) ? (string)$_GET['f'] : 'all';
$id = (string)($_GET['id'] ?? '');
if ($tab === 'manage' && !$canManage) NuAcl::require('-');

// ------------------------------------------------------------------ actions
if (nu_is_post()) {
	$a = nu_post('action');
	$nid = nu_post('id');
	if ($a === 'state') {
		NuNotify::setState($nid, nu_post('what'));
		nu_redirect(nu_url('notifications', ['f' => $filter !== 'all' ? $filter : null]) . '#n' . $nid);
	}
	if ($a === 'readall') {
		NuNotify::readAll();
		nu_redirect(nu_url('notifications', ['f' => $filter !== 'all' ? $filter : null]));
	}
	if (!$canManage) NuAcl::require('-');
	if ($a === 'count') {                                       // live number of recipients of the edit form (JSON)
		header('Content-Type: application/json; charset=utf-8');
		$aud = NuNotify::cleanAudience(['all' => !$own && nu_check('aud_all') === '1'] + (is_array($_POST['audience'] ?? null) ? $_POST['audience'] : []), $own);
		$ids = NuNotify::audienceEmpty($aud) ? [] : NuNotify::recipients($aud);
		$people = NuNotify::people();
		// recipients for the "Show" dialogue (0.8.510): full name, login name, department, team, position – sorted by name
		$rows = array_map(static fn($i) => $i === 'globeadmin' ? ['Globeadmin', 'globeadmin', '', '', '']
			: [($people[$i]['name'] ?? '') !== '' ? $people[$i]['name'] : ($people[$i]['login'] ?? $i), $people[$i]['login'] ?? $i, $people[$i]['department'] ?? '', $people[$i]['team'] ?? '', $people[$i]['position'] ?? ''], $ids);
		usort($rows, static fn($a, $b) => strnatcasecmp($a[0], $b[0]));
		exit(json_encode(['ok' => true, 'n' => count($ids), 'text' => t('ntf_reaches', count($ids)), 'people' => array_slice($rows, 0, 2000)], JSON_UNESCAPED_UNICODE));
	}
	if ($a === 'settings') {
		if (!$u['globeadmin'] && !NuAdmin::can('notifications', 'change')) NuAcl::require('-');
		NuNotify::saveSettings((int)nu_post('keep_days'));
		nu_flash('success', t('ntf_settings_saved'));
		nu_redirect(nu_url('notifications', ['tab' => 'manage']));
	}
	if (!$canSend) NuAcl::require('-');
	$n = $nid !== '' ? NuNotify::load($nid) : null;
	if ($nid !== '' && (!$n || !NuNotify::canEdit($n))) NuAcl::require('-');
	if ($a === 'save') {
		$in = $_POST + ['audience' => []];
		foreach (['pinned', 'must_confirm', 'email'] as $k) $in[$k] = nu_check($k);
		$in['audience']['all'] = !$own && nu_check('aud_all') === '1';
		[$newId, $errors] = NuNotify::save($nid, $in, nu_post('publish') === '1');
		if ($errors) {
			$_SESSION['nu_ntf_form'] = ['in' => $in, 'errors' => $errors];
			nu_flash('danger', t('err_check_form'));
			nu_redirect(nu_url('notifications', ['id' => $nid !== '' ? $nid : 'new']));
		}
		nu_flash('success', t(nu_post('publish') === '1' ? 'ntf_published' : 'ntf_saved'));
		nu_redirect(nu_url('notifications', ['tab' => 'manage']));
	}
	if ($n && in_array($a, ['publish', 'withdraw'], true)) {
		NuNotify::setStatus($nid, $a === 'publish' ? 'published' : 'withdrawn');
		nu_flash('success', t($a === 'publish' ? 'ntf_published' : 'ntf_withdrawn'));
		nu_redirect(nu_url('notifications', ['tab' => 'manage']));
	}
	if ($n && $a === 'delete') {
		NuNotify::delete($nid);
		nu_flash('success', t('ntf_deleted'));
		nu_redirect(nu_url('notifications', ['tab' => 'manage']));
	}
	if ($n && $a === 'copy') {
		$_SESSION['nu_ntf_form'] = ['in' => ['title' => $n['title'], 'body' => $n['body'], 'level' => $n['level'], 'link' => $n['link'], 'audience' => $n['aud'],
			'mode' => $n['mode'], 'pinned' => $n['pinned'], 'must_confirm' => $n['must_confirm'], 'email' => $n['email'], 'format' => (string)($n['format'] ?? '')], 'errors' => []];
		nu_redirect(nu_url('notifications', ['id' => 'new']));
	}
	nu_redirect(nu_url('notifications', ['tab' => 'manage']));
}

$tabs = '<ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link' . ($tab === 'mine' ? ' active" aria-current="page' : '') . '" href="' . h(nu_url('notifications')) . '"><i class="bi bi-bell me-1"></i>' . h(t('ntf_mine')) . '</a></li>'
	. ($canManage ? '<li class="nav-item"><a class="nav-link' . ($tab === 'manage' ? ' active" aria-current="page' : '') . '" href="' . h(nu_url('notifications', ['tab' => 'manage'])) . '"><i class="bi bi-gear me-1"></i>' . h(t('ntf_manage')) . '</a></li>' : '')
	. '</ul>';
$levelBadge = static fn(string $l) => '<i class="bi ' . (NuNotify::LEVELS[$l] ?? 'bi-info-circle') . ' nuBellIcon nuBellLv-' . h($l) . '" title="' . h(t('ntf_lv_' . $l)) . '"></i>';
$dt = static fn(?string $v) => (string)$v !== '' ? date(nu_dfmt('d.m.Y H:i'), (int)strtotime((string)$v)) : '';
$stateBadge = static function (string $s): string {
	$cls = ['draft' => 'bg-secondary-subtle text-secondary-emphasis', 'scheduled' => 'bg-info-subtle text-info-emphasis', 'published' => 'bg-success-subtle text-success-emphasis',
		'expired' => 'bg-light text-body-secondary border', 'withdrawn' => 'bg-warning-subtle text-warning-emphasis'][$s] ?? 'bg-secondary';
	return '<span class="badge ' . $cls . '">' . h(t('ntf_st_' . $s)) . '</span>';
};

// ------------------------------------------------------------------ edit form
if ($tab === 'manage' && $id !== '' && !isset($_GET['receipts'])) {
	if (!$canSend) NuAcl::require('-');
	$n = $id !== 'new' ? NuNotify::load($id) : null;
	if ($id !== 'new' && (!$n || !NuNotify::canEdit($n))) NuAcl::require('-');
	$flash = $_SESSION['nu_ntf_form'] ?? null;
	unset($_SESSION['nu_ntf_form']);
	$v = $flash['in'] ?? ($n ? ['title' => $n['title'], 'body' => $n['body'], 'level' => $n['level'], 'link' => $n['link'], 'audience' => $n['aud'], 'mode' => $n['mode'],
		'publish_at' => $n['publish_at'], 'expires_at' => $n['expires_at'], 'pinned' => $n['pinned'], 'must_confirm' => $n['must_confirm'], 'email' => $n['email'], 'format' => (string)($n['format'] ?? '')] : ['level' => 'info', 'mode' => 'live']);
	$err = $flash['errors'] ?? [];
	$aud = NuNotify::cleanAudience(is_array($v['audience'] ?? null) ? $v['audience'] : [], $own);
	$ch = NuNotify::choices($own);
	$on = static fn(string $k) => !empty($v[$k]) && (string)$v[$k] !== '0';
	$dtl = static fn($x) => (string)$x !== '' && ($ts = strtotime(str_replace('T', ' ', (string)$x))) ? date('Y-m-d\TH:i', $ts) : '';
	$fe = static fn(string $k) => isset($err[$k]) ? '<div class="invalid-feedback d-block">' . h($err[$k]) . '</div>' : '';
	$published = $n && $n['status'] === 'published';
	$mailOk = NuMail::configured();
	// recipient builder (0.8.1271, layout D of the canvas "Notification editor layouts"): one rule row per field, shown when it holds
	// values or after its "+ …" button; empty rows stay hidden (assets/js/nunotifications.js shows / hides them)
	$sel = static function (string $key, array $options, array $chosen, string $label, string $tip = '') use ($own): string {
		$o = '';
		foreach ($options as $val => $lab) {
			[$val, $text, $sub] = is_array($lab) ? [(string)$val, $lab[0], $lab[1]] : [(string)$lab, (string)$lab, ''];
			$o .= '<option value="' . h($val) . '"' . (in_array($val, $chosen, true) ? ' selected' : '') . ($sub !== '' ? ' data-sub="' . h($sub) . '"' : '') . '>' . h($text) . '</option>';
		}
		foreach ($chosen as $c) if (!array_key_exists($c, $options) && !in_array($c, $options, true)) $o .= '<option value="' . h($c) . '" selected>' . h($c) . '</option>';
		return '<div class="ntfRule' . ($key === 'exclude' ? ' ntfRuleEx' : '') . '" data-rule="' . $key . '"' . ($chosen ? '' : ' hidden') . '>'
			. '<div class="ntfRuleLbl"><label for="aud_' . $key . '">' . h($label) . '</label>' . ($tip !== '' ? NuListView::infoTip($tip) : '') . '</div>'
			. '<div class="ntfRuleSel"><select class="form-select" multiple name="audience[' . $key . '][]" id="aud_' . $key . '" data-ntf-ts' . ($key === 'exclude' ? ' data-ntf-exclude' : '')
			. ($own ? '' : ' data-create="' . (in_array($key, ['departments', 'teams', 'positions'], true) ? '1' : '') . '"') . '>' . $o . '</select></div>'
			. '<button type="button" class="btn btn-sm ntfRuleX" data-rule-x="' . $key . '" aria-label="' . h(t('remove') . ': ' . $label) . '" title="' . h(t('remove')) . '"><i class="bi bi-x-lg"></i></button></div>';
	};
	$ruleKeys = $own ? ['departments' => 'ntf_aud_departments', 'teams' => 'ntf_aud_teams']
		: ['users' => 'ntf_aud_users', 'departments' => 'ntf_aud_departments', 'teams' => 'ntf_aud_teams', 'positions' => 'ntf_aud_positions', 'access' => 'ntf_aud_access', 'exclude' => 'ntf_aud_exclude'];
	// formatted text (0.8.400): TinyMCE when it is installed (copied from nuBuilder); it needs inline styles on this page only
	$html = ($v['format'] ?? '') === 'html';
	$tinyDir = NuLibs::tinymce();                         // library pack or assets/vendor/tinymce (0.8.405)
	$tiny = $tinyDir !== '';
	if ($tiny) header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
	nu_layout_start(t('ntf_title'), 'notifications', ['head' => '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">']);
	echo $tabs;
	?>
<form method="post" class="card shadow-sm nuListCard ntfEdit" id="ntfForm" data-nu-guard action="<?= h(nu_url('notifications', ['id' => $id])) ?>">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($n ? $id : '') ?>">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h($n ? t('ntf_edit') : t('ntf_new')) ?><?= NuListView::infoTip(t('ntf_form_hint')) ?></h1>
		<?php if ($n): ?><div class="nuTitleSub"><?= $stateBadge(NuNotify::state($n)) ?></div><?php endif; ?>
		<div class="d-flex flex-wrap gap-2">
			<?= nu_back_button(null, nu_url('notifications', ['tab' => 'manage'])) ?>
			<?php if ($n): // 0.8.402: Delete and the list's ⋯ actions also here (the list's ⋯ column is off-screen on phones) ?>
			<button type="submit" class="btn nuBtnAct nuBtnDel" form="ntfActDelete" id="ntfDelete" title="<?= h(t('delete')) ?>"><i class="bi bi-trash me-1"></i><span><?= h(t('delete')) ?></span></button>
			<div class="dropdown"><button class="btn nuBtnAct" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('actions')) ?>" id="ntfMore"><i class="bi bi-three-dots"></i></button>
				<ul class="dropdown-menu dropdown-menu-end">
					<li><a class="dropdown-item" href="<?= h(nu_url('notifications', ['id' => $id, 'receipts' => '1'])) ?>"><i class="bi bi-people me-2"></i><?= h(t('ntf_receipts')) ?></a></li>
					<li><button type="submit" class="dropdown-item" form="ntfActCopy"><i class="bi bi-copy me-2"></i><?= h(t('ntf_copy')) ?></button></li>
					<?php if ($published): ?><li><button type="submit" class="dropdown-item" form="ntfActWithdraw"><i class="bi bi-eye-slash me-2"></i><?= h(t('ntf_withdraw')) ?></button></li><?php endif; ?>
				</ul></div>
			<?php endif; ?>
		</div>
	</div>
	<div class="card-body ntfBody">
		<div class="ntfCol">
		<section class="ntfSec" aria-labelledby="ntfSecMsg">
			<h2 class="ntfSecHead" id="ntfSecMsg"><span class="ntfNum">1</span><?= h(t('ntf_sec_message')) ?></h2>
			<div><label class="form-label" for="ntf_title"><?= h(t('ntf_f_title')) ?> *</label>
				<input type="text" class="form-control<?= isset($err['title']) ? ' is-invalid' : '' ?>" name="title" id="ntf_title" maxlength="200" required value="<?= h((string)($v['title'] ?? '')) ?>"><?= $fe('title') ?></div>
			<div><div class="d-flex flex-wrap align-items-center gap-2 mb-2"><label class="form-label mb-0 me-auto" for="ntf_body"><?= h(t('ntf_f_body')) ?></label>
					<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="ntf_format" name="format" value="html"<?= $html ? ' checked' : '' ?>
						data-tiny="<?= $tiny ? h(nu_asset($tinyDir . '/tinymce.min.js')) : '' ?>" data-base="<?= h($tinyDir) ?>" data-lang="<?= h(nu_lang()) ?>"
						data-confirm-text="<?= h(t('ntf_format_off_confirm')) ?>"><label class="form-check-label" for="ntf_format"><?= h(t('ntf_f_format')) ?></label></div><?= NuListView::infoTip(t('ntf_f_format_tip')) ?></div>
				<textarea class="form-control<?= isset($err['body']) ? ' is-invalid' : '' ?><?= $html && $tiny ? ' nuTmceWait' : '' ?>" name="body" id="ntf_body" rows="5"<?= $html ? '' : ' maxlength="4000"' ?>><?= h((string)($v['body'] ?? '')) ?></textarea><?= $fe('body') ?>
				<?php if (!$tiny): ?><div class="form-text<?= $html ? '' : ' d-none' ?>" id="ntfNoTiny"><?= h(t('ntf_tinymce_missing')) ?> <?= nu_lp_hint('lp_get_pack') ?></div><?php endif; ?></div>
			<fieldset><legend class="form-label fs-6 mb-2"><?= h(t('ntf_f_level')) ?></legend>
				<div class="ntfLevels nuNtfLevels">
					<?php foreach (NuNotify::LEVELS as $lv => $ic): ?>
						<input type="radio" class="btn-check" name="level" id="ntf_lv_<?= $lv ?>" value="<?= $lv ?>"<?= ($v['level'] ?? 'info') === $lv ? ' checked' : '' ?>>
						<label class="ntfLv ntfLv-<?= $lv ?> nuNtfLv-<?= $lv ?>" for="ntf_lv_<?= $lv ?>"><span class="ntfLvName"><i class="bi <?= $ic ?> nuBellIcon nuBellLv-<?= $lv ?>" aria-hidden="true"></i><?= h(t('ntf_lv_' . $lv)) ?></span><small><?= h(t('ntf_lv_' . $lv . '_d')) ?></small></label>
					<?php endforeach; ?>
				</div></fieldset>
			<div><label class="form-label" for="ntf_link"><?= h(t('ntf_f_link')) ?></label><?= NuListView::infoTip(t('ntf_f_link_tip')) ?>
				<input type="text" class="form-control<?= isset($err['link']) ? ' is-invalid' : '' ?>" name="link" id="ntf_link" maxlength="1000" placeholder="index.php?p=form&amp;f=…  ·  https://…" value="<?= h((string)($v['link'] ?? '')) ?>"><?= $fe('link') ?></div>
		</section>

		<section class="ntfSec" aria-labelledby="ntfSecAud">
			<div class="ntfSecHead"><h2 class="ntfSecHead m-0" id="ntfSecAud"><span class="ntfNum">2</span><?= h(t('ntf_f_audience')) ?><?= NuListView::infoTip($own ? t('ntf_f_audience_own') : t('ntf_f_audience_tip')) ?></h2>
				<?php if (!$own): ?>
				<div class="form-check form-switch mb-0 ms-auto"><input class="form-check-input" type="checkbox" role="switch" name="aud_all" value="1" id="ntf_all"<?= !empty($aud['all']) ? ' checked' : '' ?>>
					<label class="form-check-label" for="ntf_all"><?= h(t('ntf_aud_all')) ?></label></div>
				<?php endif; ?></div>
			<?php if (isset($err['audience'])): ?><div class="alert alert-danger py-2 small mb-0"><?= h($err['audience']) ?></div><?php endif; ?>
			<div class="ntfRules" id="ntfAudience">
				<?php foreach ($ruleKeys as $k => $lk): ?><?= $sel($k, $k === 'exclude' ? $ch['users'] : $ch[$k], $k === 'exclude' ? ($aud['exclude'] ?? []) : $aud[$k], t($lk), $k === 'exclude' ? t('ntf_aud_exclude_tip') : '') ?><?php endforeach; ?>
				<p class="ntfRuleNone small text-body-secondary mb-0" id="ntfRuleNone" hidden><?= h(t('ntf_rule_none')) ?></p>
			</div>
			<div class="ntfAdd" id="ntfAdd" role="group" aria-label="<?= h(t('ntf_add_rule')) ?>">
				<?php foreach ($ruleKeys as $k => $lk): ?><button type="button" class="ntfAddRule<?= $k === 'exclude' ? ' ntfAddEx' : '' ?>" data-rule="<?= $k ?>"><i class="bi <?= $k === 'exclude' ? 'bi-dash' : 'bi-plus' ?>" aria-hidden="true"></i><?= h(t($lk)) ?></button><?php endforeach; ?>
			</div>
			<div class="ntfMeter">
				<div class="alert alert-secondary py-2 mb-0" id="ntfCount" role="status" aria-live="polite" data-url="<?= h(nu_url('notifications')) ?>">…</div>
				<button type="button" class="btn btn-link btn-sm p-0" id="ntfCountShow" hidden><?= h(t('ntf_show_names')) ?></button>
			</div>
			<fieldset><legend class="form-label fs-6 mb-1"><?= h(t('ntf_f_mode')) ?></legend>
				<div class="ntfModes">
				<div class="form-check"><input class="form-check-input" type="radio" name="mode" value="live" id="ntf_mode_live"<?= ($v['mode'] ?? 'live') !== 'fixed' ? ' checked' : '' ?><?= $published ? ' disabled' : '' ?>>
					<label class="form-check-label" for="ntf_mode_live"><?= h(t('ntf_mode_live')) ?></label><?= NuListView::infoTip(t('ntf_mode_live_tip')) ?></div>
				<div class="form-check"><input class="form-check-input" type="radio" name="mode" value="fixed" id="ntf_mode_fixed"<?= ($v['mode'] ?? '') === 'fixed' ? ' checked' : '' ?><?= $published ? ' disabled' : '' ?>>
					<label class="form-check-label" for="ntf_mode_fixed"><?= h(t('ntf_mode_fixed')) ?></label><?= NuListView::infoTip(t('ntf_mode_fixed_tip')) ?></div>
				</div>
				<?php if ($published): ?><input type="hidden" name="mode" value="<?= h((string)$n['mode']) ?>"><?php endif; ?>
			</fieldset>
		</section>

		<section class="ntfSec" aria-labelledby="ntfSecDel">
			<h2 class="ntfSecHead" id="ntfSecDel"><span class="ntfNum">3</span><?= h(t('ntf_sec_delivery')) ?></h2>
			<div class="ntfWhen">
				<div><label class="form-label" for="ntf_pub"><?= h(t('ntf_f_publish_at')) ?></label><?= NuListView::infoTip(t('ntf_f_publish_at_tip')) ?>
					<input type="datetime-local" class="form-control<?= isset($err['publish_at']) ? ' is-invalid' : '' ?>" name="publish_at" id="ntf_pub" value="<?= h($dtl($v['publish_at'] ?? '')) ?>"><?= $fe('publish_at') ?></div>
				<div><label class="form-label" for="ntf_exp"><?= h(t('ntf_f_expires_at')) ?></label><?= NuListView::infoTip(t('ntf_f_expires_at_tip')) ?>
					<input type="datetime-local" class="form-control<?= isset($err['expires_at']) ? ' is-invalid' : '' ?>" name="expires_at" id="ntf_exp" value="<?= h($dtl($v['expires_at'] ?? '')) ?>"><?= $fe('expires_at') ?></div>
			</div>
			<?php $mailed = $n && (string)$n['sent_mail_at'] !== ''; ?>
			<div class="ntfOpts">
				<label class="ntfOpt" title="<?= h(t('ntf_f_pinned_tip')) ?>"><input class="form-check-input" type="checkbox" name="pinned" value="1" id="ntf_pinned"<?= $on('pinned') ? ' checked' : '' ?>>
					<span><b><?= h(t('ntf_f_pinned')) ?></b><small><?= h(t('ntf_opt_pinned_d')) ?></small></span></label>
				<label class="ntfOpt" title="<?= h(t('ntf_f_must_confirm_tip')) ?>"><input class="form-check-input" type="checkbox" name="must_confirm" value="1" id="ntf_confirm"<?= $on('must_confirm') ? ' checked' : '' ?><?= $published ? ' disabled' : '' ?>>
					<span><b><?= h(t('ntf_f_must_confirm')) ?></b><small><?= h(t('ntf_opt_confirm_d')) ?></small></span>
					<?php if ($published && $on('must_confirm')): ?><input type="hidden" name="must_confirm" value="1"><?php endif; ?></label>
				<label class="ntfOpt" title="<?= h($mailOk ? t('ntf_f_email_tip') : t('ntf_f_email_off')) ?>"><input class="form-check-input" type="checkbox" name="email" value="1" id="ntf_email"<?= $on('email') ? ' checked' : '' ?><?= $mailOk && !$mailed ? '' : ' disabled' ?>>
					<span><b><?= h(t('ntf_f_email')) ?></b><small><?= h($mailed ? t('ntf_mailed', $dt($n['sent_mail_at'])) : ($mailOk ? t('ntf_opt_email_d') : t('ntf_f_email_off'))) ?></small></span>
					<?php if ($mailed): ?><input type="hidden" name="email" value="1"><?php endif; ?></label>
			</div>
		</section>
		<?php // wide screens (0.8.1298, option C of the canvas "Notification editor on wide screens"): a side column with a live preview,
		// the summary and the buttons – shown from 1400 px on, the bottom bar is hidden there (assets/js/nunotifications.js side()) ?>
		<aside class="ntfSide" id="ntfSide" aria-label="<?= h(t('ntf_pv_head')) ?>" data-icons="<?= h((string)json_encode(NuNotify::LEVELS)) ?>"
			data-empty="<?= h(t('ntf_pv_empty')) ?>" data-now="<?= h(t('ntf_sum_now')) ?>" data-no-end="<?= h(t('ntf_ss_no_end')) ?>" data-confirm="<?= h(t('ntf_f_must_confirm')) ?>"
			data-users="<?= h(t('ntf_sum_users')) ?>" data-more="<?= h(t('ntf_ss_more')) ?>" data-yes="<?= h(t('yes')) ?>" data-no="<?= h(t('no')) ?>" data-all="<?= h(t('ntf_aud_all')) ?>">
			<h2 class="ntfSideHead"><?= h(t('ntf_pv_head')) ?></h2>
			<div class="ntfPv ntfPv-info" id="ntfPv"><i class="bi bi-info-circle ntfPvIcon" id="ntfPvIcon" aria-hidden="true"></i>
				<div class="ntfPvText"><b id="ntfPvTitle"></b><div id="ntfPvBody"></div><small id="ntfPvMeta"></small></div></div>
			<h2 class="ntfSideHead"><?= h(t('ntf_ss_head')) ?></h2>
			<dl class="ntfSs">
				<div><dt><?= h(t('ntf_ss_reaches')) ?></dt><dd id="ntfSsN">…</dd></div>
				<div><dt><?= h(t('ntf_ss_from')) ?></dt><dd id="ntfSsFrom"></dd></div>
				<div><dt><?= h(t('ntf_ss_until')) ?></dt><dd id="ntfSsUntil"></dd></div>
				<div><dt><?= h(t('ntf_f_must_confirm')) ?></dt><dd id="ntfSsConfirm"></dd></div>
				<div><dt><?= h(t('ntf_f_pinned')) ?></dt><dd id="ntfSsPinned"></dd></div>
			</dl>
			<div class="ntfSsNames"><span id="ntfSsNames"></span> <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="ntfSsShow" hidden><?= h(t('ntf_show_names')) ?></button></div>
			<div class="ntfSideBtns">
				<?php if (!$published): ?><button type="submit" class="btn nuBtnAct" name="publish" value="0" id="ntfDraftSide"><i class="bi bi-floppy me-1"></i><?= h(t('ntf_save_draft')) ?></button><?php endif; ?>
				<button type="submit" class="btn btn-primary" name="publish" value="1" id="ntfPublishSide" data-nu-save><i class="bi <?= $published ? 'bi-floppy' : 'bi-send' ?> me-1"></i><?= h($published ? t('save') : t('ntf_publish')) ?></button>
			</div>
		</aside>
		</div>
	</div>
	<div class="ntfBar" id="ntfBar">
		<div class="ntfBarIn">
			<span class="ntfSum" id="ntfSum" aria-live="polite" data-users="<?= h(t('ntf_sum_users')) ?>" data-now="<?= h(t('ntf_sum_now')) ?>" data-from="<?= h(t('ntf_sum_from')) ?>" data-until="<?= h(t('ntf_sum_until')) ?>" data-confirm="<?= h(t('ntf_f_must_confirm')) ?>" data-all="<?= h(t('ntf_aud_all')) ?>"></span>
			<?php if (!$published): ?><button type="submit" class="btn nuBtnAct" name="publish" value="0" id="ntfDraft"><i class="bi bi-floppy me-1"></i><?= h(t('ntf_save_draft')) ?></button><?php endif; ?>
			<button type="submit" class="btn btn-primary" name="publish" value="1" id="ntfPublish" data-nu-save><i class="bi <?= $published ? 'bi-floppy' : 'bi-send' ?> me-1"></i><?= h($published ? t('save') : t('ntf_publish')) ?></button>
		</div>
	</div>
</form>
<?php // recipients as a sortable table (0.8.510, assets/js/nunotifications.js fills it from the count answer) ?>
<div class="modal fade" id="ntfPeopleDlg" tabindex="-1" aria-labelledby="ntfPeopleTitle" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header"><h2 class="modal-title h5" id="ntfPeopleTitle"><?= h(t('ntf_recipients_now')) ?> <span class="badge text-bg-secondary ms-1" id="ntfPeopleN"></span></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
		<div class="modal-body p-0">
			<table class="table nu-table align-middle mb-0" id="ntfPeopleTable">
				<thead><tr><?php foreach (['user_display_name', 'user_login_name', 'user_department', 'user_team', 'user_position'] as $i => $k): ?><th scope="col"><button type="button" class="btn btn-link p-0 nuNtfSort text-reset text-decoration-none" data-col="<?= $i ?>"><?= h(t($k)) ?><i class="bi ms-1" aria-hidden="true"></i></button></th><?php endforeach; ?></tr></thead>
				<tbody></tbody>
			</table>
		</div>
		<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('close')) ?></button></div>
	</div></div>
</div>
<?php if ($n): foreach (['delete' => 'ntfActDelete', 'copy' => 'ntfActCopy', 'withdraw' => 'ntfActWithdraw'] as $act => $fid): ?>
<form method="post" id="<?= $fid ?>" class="d-none"<?= $act === 'delete' ? ' data-confirm="' . h(t('ntf_delete_confirm')) . '" data-confirm-ok="' . h(t('delete')) . '" data-confirm-class="btn btn-danger"' : '' ?>><?= nu_csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= h($id) ?>"></form>
<?php endforeach; endif; ?>
	<?php
	nu_layout_end('<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nunotifications.js') . '"></script>');
	return;
}

// ------------------------------------------------------------------ read receipts
if ($tab === 'manage' && $id !== '' && isset($_GET['receipts'])) {
	$n = NuNotify::load($id);
	if (!$n || !NuNotify::canSee($n)) NuAcl::require('-');
	$r = NuNotify::receipts($n);
	nu_layout_start(t('ntf_receipts'), 'notifications');
	echo $tabs;
	?>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-2"><?= $levelBadge((string)$n['level']) ?><?= h((string)$n['title']) ?></h1>
		<div class="nuTitleSub small text-body-secondary"><?= $stateBadge(NuNotify::state($n)) ?> · <?= h(NuNotify::audienceText($n['aud'])) ?> · <?= h(t($n['mode'] === 'fixed' ? 'ntf_mode_fixed' : 'ntf_mode_live')) ?></div>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button(null, nu_url('notifications', ['tab' => 'manage'])) ?></div>
	</div>
	<div class="card-body">
		<p class="mb-3" id="ntfReceiptSum"><?= h(t('ntf_read_of', $r['read'], $r['total'])) ?><?= (string)$n['must_confirm'] === '1' ? ' · ' . h(t('ntf_confirmed_of', $r['confirmed'], $r['total'])) : '' ?></p>
		<div class="table-responsive"><table class="table table-sm align-middle nu-table mb-0">
			<thead><tr><th><?= h(t('user')) ?></th><th><?= h(t('ntf_aud_departments')) ?></th><th><?= h(t('ntf_read_at')) ?></th><?php if ((string)$n['must_confirm'] === '1'): ?><th><?= h(t('ntf_confirmed_at')) ?></th><?php endif; ?><th><?= h(t('ntf_hidden_at')) ?></th></tr></thead>
			<tbody>
			<?php foreach ($r['rows'] as $row): ?>
				<tr><td><span class="fw-semibold"><?= h($row['login']) ?></span><?= $row['name'] !== '' ? ' <span class="text-body-secondary small">' . h($row['name']) . '</span>' : '' ?></td>
					<td class="small"><?= h($row['department']) ?></td>
					<td class="small"><?= $row['read_at'] !== '' ? h($dt($row['read_at'])) : '<span class="badge bg-secondary-subtle text-secondary-emphasis">' . h(t('ntf_not_read')) . '</span>' ?></td>
					<?php if ((string)$n['must_confirm'] === '1'): ?><td class="small"><?= h($dt($row['confirmed_at'])) ?></td><?php endif; ?>
					<td class="small"><?= h($dt($row['hidden_at'])) ?></td></tr>
			<?php endforeach; ?>
			<?php if (!$r['rows']): ?><tr><td colspan="5" class="text-body-secondary small"><?= h(t('ntf_no_recipients')) ?></td></tr><?php endif; ?>
			</tbody></table></div>
	</div>
</div>
	<?php
	nu_layout_end();
	return;
}

// ------------------------------------------------------------------ manage list
// a system list (0.8.690, core/nulistdefs.php 'notifications'): search, views, export, filters Status / Must confirm / Level
if ($tab === 'manage') {
	$canSet = $u['globeadmin'] || NuAdmin::can('notifications', 'change');
	$keep = NuNotify::settings()['keep_days'];
	$settings = '';
	if ($canSet) {
		$opts = '';
		foreach ([0, 30, 90, 180, 365, 730] as $d) $opts .= '<option value="' . $d . '"' . ($d === $keep ? ' selected' : '') . '>' . h($d ? t('aud_keep_days', $d) : t('aud_keep_ever')) . '</option>';
		$settings = '<div class="collapse" id="ntfSettings"><div class="card shadow-sm mb-3"><form method="post" class="p-3 bg-body-tertiary d-flex flex-wrap align-items-end gap-3">' . nu_csrf_field() . '<input type="hidden" name="action" value="settings">'
			. '<div><label class="form-label small mb-1" for="ntf_keep">' . h(t('ntf_keep')) . '</label>' . NuListView::infoTip(t('ntf_keep_tip'))
			. '<select class="form-select form-select-sm" name="keep_days" id="ntf_keep">' . $opts . '</select></div>'
			. '<button type="submit" class="btn btn-sm nuBtnAct nuBtnSave"><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button></form></div></div>';
	}
	$buttons = ($canSend ? '<a class="btn nuAddBtn" href="' . h(nu_url('notifications', ['id' => 'new'])) . '" id="ntfNew"><i class="bi bi-plus-lg me-1"></i>' . h(t('ntf_new')) . '</a>' : '')
		. ($canSet ? '<button class="btn btn-outline-secondary nuToolBtn" type="button" data-bs-toggle="collapse" data-bs-target="#ntfSettings" aria-expanded="false" aria-controls="ntfSettings" id="ntfSettingsBtn" title="' . h(t('aud_settings')) . '" aria-label="' . h(t('aud_settings')) . '"><i class="bi bi-gear me-sm-1" aria-hidden="true"></i><span class="d-none d-sm-inline">' . h(t('aud_settings')) . '</span></button>' : '');
	NuListView::page('notifications', ['before' => $tabs . $settings, 'active' => 'notifications', 'title_extra' => NuListView::infoTip(t('ntf_manage_hint')), 'buttons' => $buttons]);
	return;
}

// ------------------------------------------------------------------ my notifications
$all = NuNotify::mine();
$items = array_values(array_filter(array_map([NuNotify::class, 'item'], $all), static fn($it) => match ($filter) {
	'unread' => !$it['read'] && !$it['hidden'], 'hidden' => $it['hidden'], default => !$it['hidden'] }));
$unread = count(array_filter($all, static fn($r) => (string)$r['read_at'] === '' && (string)$r['hidden_at'] === ''));
nu_layout_start(t('ntf_title'), 'notifications');
echo $tabs;
?>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('ntf_mine')) ?><?= NuListView::infoTip(t('ntf_mine_hint')) ?></h1>
		<div class="d-flex flex-wrap gap-2">
			<?= nu_back_button() ?>
			<?php if ($unread): ?><form method="post"><?= nu_csrf_field() ?><input type="hidden" name="action" value="readall"><button class="btn btn-outline-secondary" type="submit" id="ntfReadAll"><i class="bi bi-envelope-open me-1"></i><?= h(t('ntf_mark_all')) ?></button></form><?php endif; ?>
		</div>
	</div>
	<div class="card-body">
		<ul class="nav nav-pills nav-sm mb-3 gap-1">
			<?php foreach (['all' => 'ntf_f_all', 'unread' => 'ntf_f_unread', 'hidden' => 'ntf_f_hidden'] as $fk => $fl): ?>
				<li class="nav-item"><a class="nav-link py-1 px-3<?= $filter === $fk ? ' active' : '' ?>" href="<?= h(nu_url('notifications', ['f' => $fk !== 'all' ? $fk : null])) ?>"><?= h(t($fl)) ?><?= $fk === 'unread' && $unread ? ' <span class="badge text-bg-danger ms-1">' . $unread . '</span>' : '' ?></a></li>
			<?php endforeach; ?>
		</ul>
		<?php if (!$items): ?><p class="text-body-secondary mb-0" id="ntfEmpty"><?= h(t('ntf_none')) ?></p><?php endif; ?>
		<div class="d-flex flex-column gap-2">
		<?php foreach ($items as $it):
			$state = static fn(string $what, string $icon, string $label) => '<form method="post" class="d-inline">' . nu_csrf_field() . '<input type="hidden" name="action" value="state"><input type="hidden" name="id" value="' . h($it['id']) . '"><input type="hidden" name="what" value="' . $what . '">'
				. '<button type="submit" class="btn btn-sm btn-outline-secondary" title="' . h($label) . '" aria-label="' . h($label) . '"><i class="bi ' . $icon . '"></i></button></form>'; ?>
			<div class="border rounded p-3 nuNtfItem nuBellLv-<?= h($it['level']) ?><?= $it['read'] ? '' : ' nuBellUnread' ?>" id="n<?= h($it['id']) ?>">
				<div class="d-flex align-items-start gap-3">
					<i class="bi <?= h($it['icon']) ?> fs-4 nuBellIcon"></i>
					<div class="flex-grow-1 min-w-0">
						<div class="nuBellTitle fs-6"><?php if ($it['pinned']): ?><i class="bi bi-pin-angle-fill text-body-secondary me-1" title="<?= h(t('ntf_pinned')) ?>"></i><?php endif; ?><?= h($it['title']) ?></div>
						<?php if ($it['text'] !== ''): ?><div class="mt-1"><?= $it['body'] ?></div><?php endif; ?>
						<div class="small text-body-secondary mt-2 d-flex flex-wrap gap-3">
							<span title="<?= h($dt($it['at'])) ?>"><?= h($it['ago']) ?></span><?php if ($it['by'] !== ''): ?><span><i class="bi bi-person me-1"></i><?= h($it['by']) ?></span><?php endif; ?>
							<?php if ($it['link'] !== ''): ?><a class="text-decoration-none" href="<?= h($it['link']) ?>"<?= preg_match('~^https?://~i', $it['link']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('ntf_open')) ?></a><?php endif; ?>
							<?php if ($it['confirm']): ?><span><?= $it['confirmed'] ? '<i class="bi bi-check2-square me-1"></i>' . h(t('ntf_confirmed')) : '<i class="bi bi-square me-1"></i>' . h(t('ntf_must_confirm')) ?></span><?php endif; ?>
						</div>
					</div>
					<div class="d-flex gap-1 flex-shrink-0">
						<?php if ($it['confirm'] && !$it['confirmed']): ?><?= $state('confirm', 'bi-check2', t('ntf_got_it')) ?><?php endif; ?>
						<?= $it['read'] ? $state('unread', 'bi-envelope', t('ntf_mark_unread')) : $state('read', 'bi-envelope-open', t('ntf_mark_read')) ?>
						<?= $it['hidden'] ? $state('unhide', 'bi-eye', t('ntf_unhide')) : $state('hide', 'bi-x-lg', t('ntf_hide')) ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
	</div>
</div>
<?php
nu_layout_end();
