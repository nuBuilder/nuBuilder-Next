<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Settings → Sign-in & security → Password policy (0.8.745, core/nupwpolicy.php NuPwPolicy): rules for new passwords, the leak check,
 * the regular change (users, per access level, the globeadmin's reminder). Admin right securitycheck (view / change).
 * POST action=save (password confirmed recently); action=test (JSON) checks a typed password against the rules – nothing is stored.
 */

NuAdmin::require('securitycheck');
$canChange = NuAdmin::can('securitycheck', 'change');

if (nu_is_post() && nu_post('action') === 'test') {          // JSON: the policy's verdict on a typed password (never stored or logged)
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	$v = NuPwPolicy::violations((string)($_POST['pw'] ?? ''), ['login' => (string)($_POST['login'] ?? '')]);
	echo json_encode(['ok' => !$v, 'message' => $v ? NuPwPolicy::message($v) : t('pwp_test_ok')], JSON_UNESCAPED_UNICODE);
	exit;
}
NuSecurity::requireRecentAuth(nu_url('pwpolicy'));
if (nu_is_post() && nu_post('action') === 'save') {
	NuAdmin::require('securitycheck', 'change');
	$lv = is_array($_POST['lv'] ?? null) ? $_POST['lv'] : [];
	$levels = [];
	foreach ($lv as $id => $d) if (is_string($id) && $d !== '' && $d !== null) $levels[$id] = (int)$d;
	$changed = NuPwPolicy::save(['min_len' => (int)nu_post('min_len'), 'upper' => nu_check('upper') === '1', 'lower' => nu_check('lower') === '1',
		'digit' => nu_check('digit') === '1', 'symbol' => nu_check('symbol') === '1', 'not_name' => nu_check('not_name') === '1', 'leak' => nu_post('leak'),
		'leak_login' => nu_check('leak_login') === '1', 'expire' => (int)nu_post('expire'), 'warn' => (int)nu_post('warn'), 'ga_expire' => (int)nu_post('ga_expire'),
		'levels' => $levels]);
	nu_flash('success', $changed ? t('saved') : t('aud_set_nochange'));
	nu_redirect(nu_url('pwpolicy'));
}

$s = NuPwPolicy::settings();
$cfgMin = max(1, (int)nu_cfg('nuConfigPasswordMinLength'));
$levels = [];
try { foreach (nu_db()->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code') as $r) $levels[] = $r; } catch (Throwable) { /* none */ }
[$nExp, $nSoon] = NuPwPolicy::counts();
$dis = $canChange ? '' : ' disabled';
$tip = static fn(string $text): string => NuListView::infoTip($text);
$every = static function (string $name, int $cur, string $id, bool $withDefault = false, ?int $sel = null) use ($dis): string {
	$o = '<select class="form-select" name="' . h($name) . '" id="' . h($id) . '"' . $dis . '>';
	if ($withDefault) $o .= '<option value=""' . ($sel === null ? ' selected' : '') . '>' . h(t('pwp_level_default')) . '</option>';
	foreach (NuPwPolicy::EVERY as $d) {
		$on = $withDefault ? $sel === $d : $cur === $d;
		$o .= '<option value="' . $d . '"' . ($on ? ' selected' : '') . '>' . h($d === 0 ? t('pwp_every_0') : t('pwp_every_n', $d)) . '</option>';
	}
	return $o . '</select>';
};
$sw = static function (string $name, bool $on, string $label, string $tipText = '') use ($dis, $tip): string {
	return '<div class="d-flex align-items-center mb-2"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" name="' . h($name) . '" value="1" id="pwp_' . h($name) . '"'
		. ($on ? ' checked' : '') . $dis . '><label class="form-check-label" for="pwp_' . h($name) . '">' . h($label) . '</label></div>' . ($tipText !== '' ? $tip($tipText) : '') . '</div>';
};

nu_layout_start(t('pwp_title'), 'home', ['container' => 'nuContainer', 'settings' => 'security', 'settings_page' => 'pwpolicy']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item"><a href="<?= h(nu_url('settings')) ?>"><?= h(t('set_title')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h(t('pwp_title')) ?></li>
</ol></nav>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-key me-2" aria-hidden="true"></i><?= h(t('pwp_title')) ?></h1>
	<div class="nuTitleSub text-body-secondary small"><?= h(t('pwp_sub')) ?></div>
	<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?></div>
</div>

<form method="post" action="<?= h(nu_url('pwpolicy')) ?>" id="pwpForm" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<div class="card shadow-sm mb-3" id="pwpRules">
		<div class="card-header fw-semibold"><i class="bi bi-list-check me-2" aria-hidden="true"></i><?= h(t('pwp_rules')) ?></div>
		<div class="card-body row g-4">
			<div class="col-md-6">
				<label class="form-label" for="pwp_min_len"><?= h(t('pwp_min')) ?></label><?= $tip(t('pwp_min_tip', $cfgMin)) ?>
				<input type="number" class="form-control" name="min_len" id="pwp_min_len" min="0" max="<?= NuPwPolicy::MAX_LEN ?>" value="<?= (int)$s['min_len'] ?: '' ?>" placeholder="<?= $cfgMin ?>"<?= $dis ?>>
				<div class="form-text" id="pwpMinNow"><?= h(t('pwp_min_now', NuPwPolicy::minLength())) ?></div>
				<div class="mt-3 mb-1 d-flex align-items-center"><span class="fw-semibold small"><?= h(t('pwp_classes')) ?></span><?= $tip(t('pwp_classes_tip')) ?></div>
				<?php foreach (['upper', 'lower', 'digit', 'symbol'] as $k) echo $sw($k, (bool)$s[$k], t('pwp_' . $k)); ?>
				<?= $sw('not_name', (bool)$s['not_name'], t('pwp_noname')) ?>
			</div>
			<div class="col-md-6">
				<label class="form-label" for="pwp_leak"><?= h(t('pwp_leak')) ?></label><?= $tip(t('pwp_leak_tip')) ?>
				<select class="form-select mb-3" name="leak" id="pwp_leak"<?= $dis ?>>
					<?php foreach (NuPwPolicy::LEAK_MODES as $m): ?><option value="<?= $m ?>"<?= $s['leak'] === $m ? ' selected' : '' ?>><?= h(t('pwp_leak_' . $m)) ?></option><?php endforeach; ?>
				</select>
				<?= $sw('leak_login', (bool)$s['leak_login'], t('pwp_leak_login'), t('pwp_leak_login_tip')) ?>
			</div>
		</div>
	</div>

	<div class="card shadow-sm mb-3" id="pwpChange">
		<div class="card-header fw-semibold d-flex align-items-center"><i class="bi bi-calendar-check me-2" aria-hidden="true"></i><?= h(t('pwp_change')) ?><?= $tip(t('pwp_change_tip')) ?></div>
		<div class="card-body">
			<div class="row g-3 mb-3">
				<div class="col-md-4"><label class="form-label" for="pwp_expire"><?= h(t('pwp_users_every')) ?></label><?= $every('expire', (int)$s['expire'], 'pwp_expire') ?></div>
				<div class="col-md-4"><label class="form-label" for="pwp_warn"><?= h(t('pwp_warn')) ?></label>
					<select class="form-select" name="warn" id="pwp_warn"<?= $dis ?>><?php foreach (NuPwPolicy::WARN as $d): ?><option value="<?= $d ?>"<?= (int)$s['warn'] === $d ? ' selected' : '' ?>><?= h(t('pwp_every_n', $d)) ?></option><?php endforeach; ?></select></div>
				<div class="col-md-4"><label class="form-label" for="pwp_ga_expire"><?= h(t('pwp_ga_every')) ?></label><?= $tip(t('pwp_ga_tip')) ?><?= $every('ga_expire', (int)$s['ga_expire'], 'pwp_ga_expire') ?></div>
			</div>
			<?php if ($nExp || $nSoon): ?><div class="alert alert-info small" id="pwpCounts"><?= h(t('pwp_status', $nExp, $nSoon)) ?></div><?php endif; ?>
			<details id="pwpLevels"<?= $s['levels'] ? ' open' : '' ?>><summary class="fw-semibold small"><?= h(t('pwp_levels')) ?> <span class="badge text-bg-light border"><?= count($s['levels']) ?></span></summary>
				<p class="small text-body-secondary mt-2"><?= h(t('pwp_levels_tip')) ?></p>
				<div class="table-responsive"><table class="table table-sm nu-table align-middle mb-0"><tbody>
				<?php foreach ($levels as $l): $id = (string)$l['zzzzsys_access_id']; ?>
					<tr><td><code><?= h((string)$l['sal_code']) ?></code> <span class="small text-body-secondary"><?= h((string)$l['sal_description']) ?></span></td>
						<td class="nuPwpLevelSel"><?= $every('lv[' . $id . ']', 0, 'pwp_lv_' . $id, true, $s['levels'][$id] ?? null) ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			</details>
		</div>
	</div>
	<?php if ($canChange): ?><div class="mb-4"><button class="btn btn-primary nuBtnSave" type="submit" data-nu-save id="pwpSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button></div><?php endif; ?>
</form>

<div class="card shadow-sm mb-3" id="pwpTest">
	<div class="card-header fw-semibold"><i class="bi bi-search me-2" aria-hidden="true"></i><?= h(t('pwp_test')) ?></div>
	<div class="card-body">
		<form class="row g-2 align-items-end" id="pwpTestForm" data-url="<?= h(nu_url('pwpolicy')) ?>" data-csrf="<?= h(nu_csrf_token()) ?>">
			<div class="col-md-5"><label class="form-label" for="pwpTestPw"><?= h(t('pwp_test_ph')) ?></label><input type="password" class="form-control" id="pwpTestPw" autocomplete="off"></div>
			<div class="col-md-4"><label class="form-label" for="pwpTestLogin"><?= h(t('pwp_test_login')) ?></label><input type="text" class="form-control" id="pwpTestLogin" autocomplete="off"></div>
			<div class="col-md-3"><button class="btn btn-outline-primary w-100" type="submit" id="pwpTestBtn"><?= h(t('pwp_test_btn')) ?></button></div>
		</form>
		<div class="mt-3 small" id="pwpTestOut" role="status" aria-live="polite"></div>
	</div>
</div>
<?php nu_layout_end('<script src="' . nu_asset('assets/js/nupwpolicy.js') . '"></script>');
