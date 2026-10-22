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
		$names = array_map(static fn($i) => $i === 'globeadmin' ? 'globeadmin' : ($people[$i]['login'] ?? $i), $ids);
		natcasesort($names);
		exit(json_encode(['ok' => true, 'n' => count($ids), 'text' => t('ntf_reaches', count($ids)), 'names' => array_values(array_slice($names, 0, 500))], JSON_UNESCAPED_UNICODE));
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
			'mode' => $n['mode'], 'pinned' => $n['pinned'], 'must_confirm' => $n['must_confirm'], 'email' => $n['email']], 'errors' => []];
		nu_redirect(nu_url('notifications', ['id' => 'new']));
	}
	nu_redirect(nu_url('notifications', ['tab' => 'manage']));
}

$tabs = '<ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link' . ($tab === 'mine' ? ' active" aria-current="page' : '') . '" href="' . h(nu_url('notifications')) . '"><i class="bi bi-bell me-1"></i>' . h(t('ntf_mine')) . '</a></li>'
	. ($canManage ? '<li class="nav-item"><a class="nav-link' . ($tab === 'manage' ? ' active" aria-current="page' : '') . '" href="' . h(nu_url('notifications', ['tab' => 'manage'])) . '"><i class="bi bi-gear me-1"></i>' . h(t('ntf_manage')) . '</a></li>' : '')
	. '</ul>';
$levelBadge = static fn(string $l) => '<i class="bi ' . (NuNotify::LEVELS[$l] ?? 'bi-info-circle') . ' nuBellIcon nuBellLv-' . h($l) . '" title="' . h(t('ntf_lv_' . $l)) . '"></i>';
$dt = static fn(?string $v) => (string)$v !== '' ? date('d.m.Y H:i', (int)strtotime((string)$v)) : '';
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
		'publish_at' => $n['publish_at'], 'expires_at' => $n['expires_at'], 'pinned' => $n['pinned'], 'must_confirm' => $n['must_confirm'], 'email' => $n['email']] : ['level' => 'info', 'mode' => 'live']);
	$err = $flash['errors'] ?? [];
	$aud = NuNotify::cleanAudience(is_array($v['audience'] ?? null) ? $v['audience'] : [], $own);
	$ch = NuNotify::choices($own);
	$on = static fn(string $k) => !empty($v[$k]) && (string)$v[$k] !== '0';
	$dtl = static fn($x) => (string)$x !== '' && ($ts = strtotime(str_replace('T', ' ', (string)$x))) ? date('Y-m-d\TH:i', $ts) : '';
	$fe = static fn(string $k) => isset($err[$k]) ? '<div class="invalid-feedback d-block">' . h($err[$k]) . '</div>' : '';
	$published = $n && $n['status'] === 'published';
	$mailOk = NuMail::configured();
	$sel = static function (string $key, array $options, array $chosen, string $label) use ($own): string {
		$o = '';
		foreach ($options as $val => $lab) {
			[$val, $text, $sub] = is_array($lab) ? [(string)$val, $lab[0], $lab[1]] : [(string)$lab, (string)$lab, ''];
			$o .= '<option value="' . h($val) . '"' . (in_array($val, $chosen, true) ? ' selected' : '') . ($sub !== '' ? ' data-sub="' . h($sub) . '"' : '') . '>' . h($text) . '</option>';
		}
		foreach ($chosen as $c) if (!array_key_exists($c, $options) && !in_array($c, $options, true)) $o .= '<option value="' . h($c) . '" selected>' . h($c) . '</option>';
		return '<div class="col-md-6"><label class="form-label small mb-1" for="aud_' . $key . '">' . h($label) . '</label><select class="form-select" multiple name="audience[' . $key . '][]" id="aud_' . $key . '" data-ntf-ts'
			. ($own ? '' : ' data-create="' . (in_array($key, ['departments', 'teams', 'positions'], true) ? '1' : '') . '"') . '>' . $o . '</select></div>';
	};
	nu_layout_start(t('ntf_title'), 'notifications', ['head' => '<link rel="stylesheet" href="' . nu_asset('assets/vendor/tom-select-2.6.2/tom-select.bootstrap5.min.css') . '">']);
	echo $tabs;
	?>
<form method="post" class="card shadow-sm nuListCard" id="ntfForm" data-nu-guard action="<?= h(nu_url('notifications', ['id' => $id])) ?>">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($n ? $id : '') ?>">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h($n ? t('ntf_edit') : t('ntf_new')) ?><?= NuListView::infoTip(t('ntf_form_hint')) ?></h1>
		<?php if ($n): ?><div class="nuTitleSub"><?= $stateBadge(NuNotify::state($n)) ?></div><?php endif; ?>
		<div class="d-flex flex-wrap gap-2">
			<?= nu_back_button(null, nu_url('notifications', ['tab' => 'manage'])) ?>
			<?php if (!$published): ?><button type="submit" class="btn nuBtnAct" name="publish" value="0" id="ntfDraft"><i class="bi bi-floppy me-1"></i><?= h(t('ntf_save_draft')) ?></button><?php endif; ?>
			<button type="submit" class="btn nuBtnAct nuBtnSave" name="publish" value="1" id="ntfPublish" data-nu-save><i class="bi <?= $published ? 'bi-floppy' : 'bi-send' ?> me-1"></i><?= h($published ? t('save') : t('ntf_publish')) ?></button>
		</div>
	</div>
	<div class="card-body">
		<div class="row g-3">
			<div class="col-lg-7">
				<div class="mb-3"><label class="form-label" for="ntf_title"><?= h(t('ntf_f_title')) ?> *</label>
					<input type="text" class="form-control<?= isset($err['title']) ? ' is-invalid' : '' ?>" name="title" id="ntf_title" maxlength="200" required value="<?= h((string)($v['title'] ?? '')) ?>"><?= $fe('title') ?></div>
				<div class="mb-3"><label class="form-label" for="ntf_body"><?= h(t('ntf_f_body')) ?></label><?= NuListView::infoTip(t('ntf_f_body_tip')) ?>
					<textarea class="form-control" name="body" id="ntf_body" rows="6" maxlength="4000"><?= h((string)($v['body'] ?? '')) ?></textarea></div>
				<div class="mb-3"><span class="form-label d-block"><?= h(t('ntf_f_level')) ?></span>
					<div class="btn-group flex-wrap nuNtfLevels" role="group" aria-label="<?= h(t('ntf_f_level')) ?>">
						<?php foreach (NuNotify::LEVELS as $lv => $ic): ?>
							<input type="radio" class="btn-check" name="level" id="ntf_lv_<?= $lv ?>" value="<?= $lv ?>"<?= ($v['level'] ?? 'info') === $lv ? ' checked' : '' ?>>
							<label class="btn btn-outline-secondary nuNtfLv-<?= $lv ?>" for="ntf_lv_<?= $lv ?>"><i class="bi <?= $ic ?> me-1 nuBellIcon nuBellLv-<?= $lv ?>"></i><?= h(t('ntf_lv_' . $lv)) ?></label>
						<?php endforeach; ?>
					</div></div>
				<div class="mb-3"><label class="form-label" for="ntf_link"><?= h(t('ntf_f_link')) ?></label><?= NuListView::infoTip(t('ntf_f_link_tip')) ?>
					<input type="text" class="form-control<?= isset($err['link']) ? ' is-invalid' : '' ?>" name="link" id="ntf_link" maxlength="1000" placeholder="index.php?p=form&amp;f=…  ·  https://…" value="<?= h((string)($v['link'] ?? '')) ?>"><?= $fe('link') ?></div>
			</div>
			<div class="col-lg-5">
				<div class="row g-3">
					<div class="col-sm-6"><label class="form-label" for="ntf_pub"><?= h(t('ntf_f_publish_at')) ?></label><?= NuListView::infoTip(t('ntf_f_publish_at_tip')) ?>
						<input type="datetime-local" class="form-control<?= isset($err['publish_at']) ? ' is-invalid' : '' ?>" name="publish_at" id="ntf_pub" value="<?= h($dtl($v['publish_at'] ?? '')) ?>"><?= $fe('publish_at') ?></div>
					<div class="col-sm-6"><label class="form-label" for="ntf_exp"><?= h(t('ntf_f_expires_at')) ?></label><?= NuListView::infoTip(t('ntf_f_expires_at_tip')) ?>
						<input type="datetime-local" class="form-control<?= isset($err['expires_at']) ? ' is-invalid' : '' ?>" name="expires_at" id="ntf_exp" value="<?= h($dtl($v['expires_at'] ?? '')) ?>"><?= $fe('expires_at') ?></div>
				</div>
				<div class="mt-3">
					<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="pinned" value="1" id="ntf_pinned"<?= $on('pinned') ? ' checked' : '' ?>>
						<label class="form-check-label" for="ntf_pinned"><?= h(t('ntf_f_pinned')) ?></label><?= NuListView::infoTip(t('ntf_f_pinned_tip')) ?></div>
					<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="must_confirm" value="1" id="ntf_confirm"<?= $on('must_confirm') ? ' checked' : '' ?><?= $published ? ' disabled' : '' ?>>
						<label class="form-check-label" for="ntf_confirm"><?= h(t('ntf_f_must_confirm')) ?></label><?= NuListView::infoTip(t('ntf_f_must_confirm_tip')) ?>
						<?php if ($published && $on('must_confirm')): ?><input type="hidden" name="must_confirm" value="1"><?php endif; ?></div>
					<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="email" value="1" id="ntf_email"<?= $on('email') ? ' checked' : '' ?><?= $mailOk && !($n && (string)$n['sent_mail_at'] !== '') ? '' : ' disabled' ?>>
						<label class="form-check-label" for="ntf_email"><?= h(t('ntf_f_email')) ?></label><?= NuListView::infoTip($mailOk ? t('ntf_f_email_tip') : t('ntf_f_email_off')) ?>
						<?php if ($n && (string)$n['sent_mail_at'] !== ''): ?><span class="small text-body-secondary ms-1"><?= h(t('ntf_mailed', $dt($n['sent_mail_at']))) ?></span><input type="hidden" name="email" value="1"><?php endif; ?></div>
				</div>
			</div>
		</div>

		<h2 class="h6 mt-4 mb-2 d-flex align-items-center gap-1"><?= h(t('ntf_f_audience')) ?><?= NuListView::infoTip($own ? t('ntf_f_audience_own') : t('ntf_f_audience_tip')) ?></h2>
		<?php if (isset($err['audience'])): ?><div class="alert alert-danger py-2 small"><?= h($err['audience']) ?></div><?php endif; ?>
		<?php if (!$own): ?>
			<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="aud_all" value="1" id="ntf_all"<?= !empty($aud['all']) ? ' checked' : '' ?>>
				<label class="form-check-label" for="ntf_all"><?= h(t('ntf_aud_all')) ?></label></div>
		<?php endif; ?>
		<div class="row g-3" id="ntfAudience">
			<?php if (!$own): ?><?= $sel('users', $ch['users'], $aud['users'], t('ntf_aud_users')) ?><?php endif; ?>
			<?= $sel('departments', $ch['departments'], $aud['departments'], t('ntf_aud_departments')) ?>
			<?= $sel('teams', $ch['teams'], $aud['teams'], t('ntf_aud_teams')) ?>
			<?php if (!$own): ?>
				<?= $sel('positions', $ch['positions'], $aud['positions'], t('ntf_aud_positions')) ?>
				<?= $sel('access', $ch['access'], $aud['access'], t('ntf_aud_access')) ?>
			<?php endif; ?>
		</div>
		<div class="d-flex flex-wrap align-items-center gap-3 mt-3">
			<div class="alert alert-secondary py-2 mb-0 small" id="ntfCount" role="status" aria-live="polite" data-url="<?= h(nu_url('notifications')) ?>">…</div>
			<button type="button" class="btn btn-link btn-sm p-0" id="ntfCountShow" hidden><?= h(t('ntf_show_names')) ?></button>
		</div>
		<div class="small text-body-secondary mt-2 nuNtfNames" id="ntfNames" hidden></div>

		<div class="mt-4"><span class="form-label d-block"><?= h(t('ntf_f_mode')) ?></span>
			<div class="form-check"><input class="form-check-input" type="radio" name="mode" value="live" id="ntf_mode_live"<?= ($v['mode'] ?? 'live') !== 'fixed' ? ' checked' : '' ?><?= $published ? ' disabled' : '' ?>>
				<label class="form-check-label" for="ntf_mode_live"><?= h(t('ntf_mode_live')) ?></label><?= NuListView::infoTip(t('ntf_mode_live_tip')) ?></div>
			<div class="form-check"><input class="form-check-input" type="radio" name="mode" value="fixed" id="ntf_mode_fixed"<?= ($v['mode'] ?? '') === 'fixed' ? ' checked' : '' ?><?= $published ? ' disabled' : '' ?>>
				<label class="form-check-label" for="ntf_mode_fixed"><?= h(t('ntf_mode_fixed')) ?></label><?= NuListView::infoTip(t('ntf_mode_fixed_tip')) ?></div>
			<?php if ($published): ?><input type="hidden" name="mode" value="<?= h((string)$n['mode']) ?>"><?php endif; ?>
		</div>
	</div>
</form>
	<?php
	nu_layout_end('<script src="' . nu_asset('assets/vendor/tom-select-2.6.2/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nunotifications.js') . '"></script>');
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
if ($tab === 'manage') {
	$rows = NuNotify::managed();
	$canSet = $u['globeadmin'] || NuAdmin::can('notifications', 'change');
	$keep = NuNotify::settings()['keep_days'];
	nu_layout_start(t('ntf_manage'), 'notifications');
	echo $tabs;
	?>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('ntf_manage')) ?><?= NuListView::infoTip(t('ntf_manage_hint')) ?></h1>
		<div class="d-flex flex-wrap gap-2">
			<?= nu_back_button() ?>
			<?php if ($canSend): ?><a class="btn btn-outline-secondary" href="<?= h(nu_url('notifications', ['id' => 'new'])) ?>" id="ntfNew"><i class="bi bi-plus-lg me-1"></i><?= h(t('ntf_new')) ?></a><?php endif; ?>
			<?php if ($canSet): ?><button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#ntfSettings" aria-expanded="false" aria-controls="ntfSettings"><i class="bi bi-gear me-1"></i><?= h(t('aud_settings')) ?></button><?php endif; ?>
		</div>
	</div>
	<?php if ($canSet): ?>
	<div class="collapse" id="ntfSettings"><form method="post" class="border-bottom p-3 bg-body-tertiary d-flex flex-wrap align-items-end gap-3"><?= nu_csrf_field() ?><input type="hidden" name="action" value="settings">
		<div><label class="form-label small mb-1" for="ntf_keep"><?= h(t('ntf_keep')) ?></label><?= NuListView::infoTip(t('ntf_keep_tip')) ?>
			<select class="form-select form-select-sm" name="keep_days" id="ntf_keep"><?php foreach ([0, 30, 90, 180, 365, 730] as $d): ?><option value="<?= $d ?>"<?= $d === $keep ? ' selected' : '' ?>><?= h($d ? t('aud_keep_days', $d) : t('aud_keep_ever')) ?></option><?php endforeach; ?></select></div>
		<button type="submit" class="btn btn-sm nuBtnAct nuBtnSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
	</form></div>
	<?php endif; ?>
	<div class="table-responsive"><table class="table table-hover align-middle mb-0 nu-table">
		<thead><tr><th></th><th><?= h(t('ntf_f_title')) ?></th><th><?= h(t('ntf_status')) ?></th><th><?= h(t('ntf_f_audience')) ?></th><th class="text-end"><?= h(t('ntf_read')) ?></th><th><?= h(t('ntf_created')) ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ($rows as $r): $st = NuNotify::state($r); $nid = (string)$r['zzzzsys_nunext_notification_id']; $edit = NuNotify::canEdit($r); ?>
			<tr data-ntf="<?= h($nid) ?>">
				<td class="text-center"><?= $levelBadge((string)$r['level']) ?></td>
				<td><a class="fw-semibold text-decoration-none" href="<?= h(nu_url('notifications', $edit ? ['id' => $nid] : ['id' => $nid, 'receipts' => '1'])) ?>"><?= h((string)$r['title']) ?></a>
					<?php if ((string)$r['pinned'] === '1'): ?><i class="bi bi-pin-angle-fill text-body-secondary ms-1" title="<?= h(t('ntf_pinned')) ?>"></i><?php endif; ?>
					<?php if ((string)$r['must_confirm'] === '1'): ?><i class="bi bi-check2-square text-body-secondary ms-1" title="<?= h(t('ntf_f_must_confirm')) ?>"></i><?php endif; ?>
					<?php if ((string)$r['email'] === '1'): ?><i class="bi bi-envelope text-body-secondary ms-1" title="<?= h(t('ntf_f_email')) ?>"></i><?php endif; ?></td>
				<td><?= $stateBadge($st) ?><?php if ($st === 'scheduled'): ?><div class="small text-body-secondary"><?= h($dt($r['publish_at'])) ?></div><?php elseif ((string)$r['expires_at'] !== '' && $st === 'published'): ?><div class="small text-body-secondary"><?= h(t('ntf_until', $dt($r['expires_at']))) ?></div><?php endif; ?></td>
				<td class="small"><?= h(NuNotify::audienceText($r['aud'])) ?></td>
				<td class="text-end small text-nowrap"><a class="text-decoration-none" href="<?= h(nu_url('notifications', ['id' => $nid, 'receipts' => '1'])) ?>" title="<?= h(t('ntf_receipts')) ?>"><?= (int)$r['n_read'] ?> / <?= (int)$r['n_total'] ?></a></td>
				<td class="small text-nowrap"><?= h($dt($r['created_at'])) ?><div class="text-body-secondary"><?= h((string)$r['created_by']) ?></div></td>
				<td class="text-end text-nowrap">
					<?php if ($edit): ?>
					<div class="dropdown"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('actions')) ?>"><i class="bi bi-three-dots"></i></button>
						<ul class="dropdown-menu dropdown-menu-end">
							<li><a class="dropdown-item" href="<?= h(nu_url('notifications', ['id' => $nid])) ?>"><i class="bi bi-pencil me-2"></i><?= h(t('edit')) ?></a></li>
							<li><a class="dropdown-item" href="<?= h(nu_url('notifications', ['id' => $nid, 'receipts' => '1'])) ?>"><i class="bi bi-people me-2"></i><?= h(t('ntf_receipts')) ?></a></li>
							<?php foreach ([['copy', 'bi-copy', 'ntf_copy', ''], $r['status'] === 'published' ? ['withdraw', 'bi-eye-slash', 'ntf_withdraw', ''] : ['publish', 'bi-send', 'ntf_publish', ''], ['delete', 'bi-trash', 'delete', t('ntf_delete_confirm')]] as [$act, $ic, $lb, $conf]): ?>
								<li><form method="post"<?= $conf !== '' ? ' data-confirm="' . h($conf) . '" data-confirm-ok="' . h(t('delete')) . '" data-confirm-class="btn btn-danger"' : '' ?>><?= nu_csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= h($nid) ?>">
									<button type="submit" class="dropdown-item<?= $act === 'delete' ? ' text-danger' : '' ?>"><i class="bi <?= $ic ?> me-2"></i><?= h(t($lb)) ?></button></form></li>
							<?php endforeach; ?>
						</ul></div>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if (!$rows): ?><tr><td colspan="7" class="text-body-secondary small py-4 text-center"><?= h(t('ntf_none_sent')) ?></td></tr><?php endif; ?>
		</tbody></table></div>
</div>
	<?php
	nu_layout_end();
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
