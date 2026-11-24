<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

NuAcl::require(NuAcl::FORM_USER);
$rights = NuAcl::rights(NuAcl::FORM_USER);
$me     = NuAuth::user();
$db     = nu_db();

$id      = (string)($_GET['id'] ?? '');
$cloneId = (string)($_GET['clone'] ?? '');

// welcome e-mail preview: the rendered mail for the iframe of the preview dialog – from the session, with a CSP of its own
// (mails carry inline styles; no scripts); assets/js/nuuser.js makes it editable (designMode) and reads it back
if (isset($_GET['welcome_frame'])) {
	$html = $_SESSION[NuSess::WELCOME_PREVIEW][(string)$_GET['welcome_frame']] ?? null;
	if (!is_string($html)) { http_response_code(404); exit; }
	header('X-Frame-Options: SAMEORIGIN', true);
	header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: https: http:; font-src data: https:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'", true);
	header('Content-Type: text/html; charset=utf-8');
	exit($html);
}

$existing = $id !== '' ? $db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$id]) : null;
if ($id !== '' && !$existing) {
	nu_flash('warning', t('record_not_found'));
	nu_redirect(nu_url('users'));
}

// ------------------------------------------------------------------ actions of the form (core/nuuserpage.php, code review R7)
if (nu_is_post()) NuUserPage::post(nu_post('action'), $existing, $id, $me, $rights);

// ------------------------------------------------------------------ password reset by an administrator (core/nupwreset.php)
// a link for the user (e-mail or shown once), optionally the old password blocked at once; never a password the administrator sees
if ($existing && isset($_GET['pwreset_frame'])) {                  // the e-mail as it would be sent (link = placeholder), own CSP as welcome_frame
	if (!$rights['save'] || NuPwReset::refusal($existing, $me) !== null) { http_response_code(403); exit; }
	header('X-Frame-Options: SAMEORIGIN', true);
	header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: https: http:; font-src data: https:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'", true);
	header('Content-Type: text/html; charset=utf-8');
	exit(NuPwReset::preview($existing)['html']);
}
// ------------------------------------------------------------------ field definitions (column => [max length, label key])
$text = [
	'sus_login_name'  => [50, 'user_login_name'],
	'sus_name'        => [50, 'user_display_name'],
	'sus_first_name'  => [30, 'user_first_name'],
	'sus_last_name'   => [30, 'user_last_name'],
	'sus_code'        => [50, 'user_code'],
	'sus_email'       => [255, 'user_email'],
	'sus_position'    => [50, 'user_position'],
	'sus_department'  => [50, 'user_department'],
	'sus_team'        => [50, 'user_team'],
	'sus_additional1' => [100, 'user_additional1'],
	'sus_additional2' => [100, 'user_additional2'],
	'sus_language'    => [20, 'user_language'],
];

$isNew = !$existing;
if ($isNew) NuAcl::require(NuAcl::FORM_USER, $cloneId !== '' ? 'clone' : 'create');
$canSave = $isNew ? true : $rights['save'];
$mailOk  = NuMail::configured();          // "Send welcome e-mail" only with working e-mail settings
$welcome = false;

// ------------------------------------------------------------------ initial values
$source = $existing;
if ($isNew && $cloneId !== '') {
	$source = $db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$cloneId]);
	if (!$source) nu_redirect(nu_url('users'));
	$source['sus_login_name'] = '';
	$source['sus_email']      = '';
	$source['sus_code']       = '';
}
$v = [];
foreach ($text as $col => $_) $v[$col] = (string)($source[$col] ?? '');
$v['sus_zzzzsys_access_id']      = (string)($source['sus_zzzzsys_access_id'] ?? '');
$v['sus_expires_on']             = nu_date_only($source['sus_expires_on'] ?? '');
$v['sus_change_password']        = $source ? ((string)$source['sus_change_password'] === '1' ? '1' : '0') : '1';
$v['sus_accessibility_features'] = (string)($source['sus_accessibility_features'] ?? '') === '1' ? '1' : '0';
if ($isNew && $cloneId !== '') $v['sus_change_password'] = '1';
// English variant: sus_language stays "English" (nuBuilder), the variant is kept in sus_json NUNEXT_LANG_VARIANT
$langVariant = $source ? (string)NuUserJson::get((string)$source['zzzzsys_user_id'], 'NUNEXT_LANG_VARIANT') : '';
if (!isset(NU_LANG_VARIANTS[$langVariant])) $langVariant = '';
$langVariantOld = $isNew ? '' : $langVariant;
// admin role: chosen here by the globeadmin or a user with "Admin roles" – never for the own record (core/nuadmin.php)
$admRoleMay = NuAdmin::canManage() && !($existing && $existing['zzzzsys_user_id'] === $me['id']);
$admRoleCur = $existing ? (string)(NuAdmin::roleOfUser((string)$existing['zzzzsys_user_id'])['id'] ?? '') : '';
$admRoleSel = $admRoleCur;

// additional access levels (core/nuuseraccess.php): [access id, valid until] – a clone takes them along
$xaOld = array_map(static fn($r) => [$r['access_id'], $r['valid_until']], NuUserAccess::rowsOf($existing ? $id : $cloneId));
$xaSel = $xaOld;

// permission items (nuBuilder subform sf_permission → zzzzsys_user_permission)
$permItems = NuPermission::items();
$permOld   = array_keys(array_filter(NuPermission::forUser($existing ? $id : $cloneId), static fn($a) => $a === '1'));
$permSel   = array_map('strval', $permOld);
// "Copy from user…": the other users with at least one active permission item, with their number of items
$permFrom  = $permItems ? $db->all('SELECT u.zzzzsys_user_id AS id, u.sus_name AS name, u.sus_login_name AS login, COUNT(DISTINCT i.zzzzsys_permission_item_id) AS n'
	. ' FROM zzzzsys_user u JOIN ' . NuPermission::USER . ' p ON ' . $db->sameText('p.upe_zzzzsys_user_id', 'u.zzzzsys_user_id')
	. ' JOIN ' . NuPermission::ITEM . ' i ON ' . $db->sameText('i.zzzzsys_permission_item_id', 'p.upe_ident')
	. " WHERE p.upe_active = '1' AND u.zzzzsys_user_id <> ? GROUP BY u.zzzzsys_user_id, u.sus_name, u.sus_login_name ORDER BY u.sus_name, u.sus_login_name", [$id]) : [];

// custom user fields (core/nuuserfields.php): tab More; a clone takes the values along except unique ones, a new user the defaults
$ufAll = NuUserFields::all();
$ufEdit = NuUserFields::editable('admin');
$ufVals = $existing ? NuUserFields::values($id) : ($cloneId !== '' ? array_map(static fn($f, $x) => (string)$f['is_unique'] === '1' ? '' : $x, $ufAll,
	NuUserFields::values($cloneId)) : NuUserFields::defaults());
if ($ufAll) $ufVals = array_combine(array_keys($ufAll), array_values($ufVals));
$ufErr = [];

$errors = [];
$min    = NuPwPolicy::minLength();

// ------------------------------------------------------------------ save (core/nuuserpage.php)
if (nu_is_post() && nu_post('action') === 'save') {
	['v' => $v, 'errors' => $errors, 'langVariant' => $langVariant, 'admRoleSel' => $admRoleSel, 'welcome' => $welcome, 'permSel' => $permSel,
		'xaSel' => $xaSel, 'ufVals' => $ufVals,
		'ufErr' => $ufErr] = NuUserPage::save(compact('v', 'errors', 'langVariant', 'admRoleSel', 'welcome', 'permSel', 'xaSel', 'ufVals', 'ufErr'),
		compact('text', 'existing', 'id', 'me', 'isNew', 'canSave', 'mailOk', 'source', 'cloneId', 'admRoleMay', 'admRoleCur', 'xaOld', 'permItems', 'ufAll', 'ufEdit', 'langVariantOld', 'min'));
	nu_flash('danger', t('err_check_form'));
}

// ------------------------------------------------------------------ view data
$accessLevels = array_values(NuAcl::levelsByGroup());
// Position / Department / Team: HTML5 datalist with the values of the OTHER, ACTIVE users (Valid until empty, a zero date or today
// or later – archived / expired users are left out), trimmed, without duplicates (ignoring case), sorted alphabetically
$suggest = [];
$active  = ' AND zzzzsys_user_id <> ? AND (sus_expires_on IS NULL OR sus_expires_on >= ? OR sus_expires_on < ?)';
foreach (['sus_position', 'sus_department', 'sus_team'] as $col) {
	$vals = [];
	foreach ($db->all("SELECT DISTINCT $col AS v FROM zzzzsys_user WHERE $col IS NOT NULL AND $col <> ''" . $active,
		[$id, $db->datetime(date('Y-m-d')), $db->datetime('1900-01-02')]) as $r) {
		$val = trim((string)$r['v']);
		if ($val !== '') $vals[mb_strtolower($val)] ??= $val;
	}
	usort($vals, static fn($a, $b) => strnatcasecmp($a, $b));
	$suggest[$col] = $vals;
}
$langOptions = ['' => t('user_language_default')];
foreach (NU_LANGS as $code => [$own, $dbName]) {
	if (!NuLanguages::has($code)) continue;                   // installed languages only; the user's own value stays below
	$langOptions[$dbName] = $own;
	foreach (NuLanguages::variants($code) as $vc) $langOptions[$dbName . '|' . $vc] = NU_LANG_VARIANTS[$vc][1];   // offered variants
}
if ($v['sus_language'] !== '' && !isset($langOptions[$v['sus_language']])) $langOptions[$v['sus_language']] = $v['sus_language'];
if ($langVariant !== '' && isset(NU_LANG_VARIANTS[$langVariant]) && isset($langOptions[$v['sus_language']]) && !isset($langOptions[$v['sus_language'] . '|' . $langVariant])) {
	$langOptions[$v['sus_language'] . '|' . $langVariant] = NU_LANG_VARIANTS[$langVariant][1];   // the user's own variant stays, also when no longer offered
}
$langSelected = $v['sus_language'] . ($langVariant !== '' && isset($langOptions[$v['sus_language'] . '|' . $langVariant]) ? '|' . $langVariant : '');

$title = $isNew ? ($cloneId !== '' ? t('user_clone') : t('user_new')) : ($existing['sus_login_name'] ?: t('user'));
$ro    = $canSave ? '' : ' disabled';

$input = static function (string $col, string $type = 'text', array $attr = []) use ($v, $errors, $text, $suggest): string {
	$max  = $text[$col][0] ?? null;
	$uni = !empty($attr['unique']);                                   // key after the label: unique – not an HTML attribute
	$uniOff = ($attr['unique'] ?? null) === 'code' && !NuAuth::codeUnique();   // the code: key kept hidden, the rename dialog switches it
	unset($attr['unique']);
	$lk = $text[$col][1];
	// renamable titles: (i) with the setting, a pencil for the globeadmin (dialog #userLblDlg, assets/js/nuuser.js)
	$ren = '';
	if (isset(NU_USER_LABELS[$lk])) {
		$own = nu_user_label($lk) !== '';
		$tipTxt = $own ? t('user_lbl_tip_own', nu_text($lk), '$' . NU_USER_LABELS[$lk])
			: t($lk === 'user_code' ? 'user_lbl_tip_code' : 'user_lbl_tip', '$' . NU_USER_LABELS[$lk]);
		if ($lk === 'user_code') $tipTxt .= ' ' . t(NuAuth::codeUnique() ? 'user_code_unique_on' : 'user_code_unique_off');
		$ren = nu_info_tip($tipTxt, 'ms-1', true, '', $lk === 'user_code' ? 'user-code' : 'users');
		if (NuUserLabel::canRename()) $ren .= '<button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline nuUserLblEdit" data-key="' . h($lk)
			. '" data-for="' . h($col) . '" data-value="' . h(NuUserLabel::stored($lk)) . '" data-builtin="' . h(nu_text($lk)) . '"' . ($lk === 'user_code' ? ' data-unique="' . (NuAuth::codeUnique() ? '1' : '0') . '"' : '') . ' title="'
			. h(t('user_lbl_edit')) . '" aria-label="' . h(t('user_lbl_edit')) . '"><i class="bi bi-pencil" aria-hidden="true"></i></button>';
	}
	$html = '<label class="form-label" for="' . $col . '"><span class="nuUserLblText">' . h(t($lk)) . '</span>'
		. (!empty($attr['required']) ? ' <span class="text-danger">*</span>' : '') . ($uni ? nu_unique_mark(!empty($attr['required']), $uniOff) : '') . '</label>' . $ren
		. '<input type="' . $type . '" class="form-control' . nu_invalid($errors, $col) . '" id="' . $col . '" name="' . $col . '" value="' . h($v[$col]) . '"'
		. ($max ? ' maxlength="' . $max . '"' : '')
		. (isset($suggest[$col]) ? ' list="dl_' . $col . '"' : '');
	foreach ($attr as $k => $val) $html .= ' ' . $k . ($val === true ? '' : '="' . h($val) . '"');
	$html .= '>' . nu_feedback($errors, $col);
	if (isset($suggest[$col])) {
		$html .= '<datalist id="dl_' . $col . '">';
		foreach ($suggest[$col] as $s) $html .= '<option value="' . h($s) . '">';
		$html .= '</datalist>';
	}
	return $html;
};

nu_body_class('nuRecPage');
if (!$isNew && NuRecordView::pending('user')) nu_body_class('nuRvPending');   // view mode opens by itself: no glimpse of the form
nu_layout_start($title, 'users', ['head' => '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">']);   // access level: searchable
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('users')) ?>"><?= h(t('nav_users')) ?></a></li>
		<li class="breadcrumb-item active" aria-current="page"><?= h($title) ?></li>
	</ol>
</nav>
<div class="nuTitleBar nuRecHead mb-3">
	<h1 class="h3 mb-0 d-flex align-items-center gap-2">
		<?php $impIcon = $isNew ? '' : NuImpersonate::button($id, (string)$existing['sus_login_name'], nu_url('user', ['id' => $id]), false, true); // globeadmin: "Act as user" as the icon ?>
		<?= $impIcon !== '' ? $impIcon : '<i class="bi ' . ($isNew ? 'bi-person-plus' : 'bi-person') . '"></i>' ?><?= h($title) ?>
		<?php if (!$isNew && nu_is_expired($existing['sus_expires_on'])): ?><span class="badge text-bg-danger fs-6"><?= h(t('user_expired')) ?></span><?php endif; ?>
		<?php if (!$isNew && NuDemo::isDemoUser($id)): ?><span class="badge nuDemoBadge fs-6"
			title="<?= h(t('demo_user_hint')) ?>"><i class="bi bi-eye me-1"></i><?= h(t('demo_user_badge')) ?></span><?php endif; ?>
	</h1>
	<div class="d-flex flex-wrap gap-2 align-items-center">
		<a class="btn nuBtnAct" href="<?= h(nu_url('users')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if ($canSave): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuRecordForm"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<?php if (!$isNew): // view mode: the user as a read-only data sheet (assets/js/nuview.js) ?>
			<?= NuRecordView::button(['title' => trim((string)($existing['sus_name'] ?? '')) ?: $title, 'kind' => t('user'), 'list' => 'users', 'key' => 'user', 'edit' => $canSave,
				'head' => ['sus_login_name', 'sus_zzzzsys_access_id', 'sus_department', 'sus_position']]) ?>
		<?php endif; ?>
		<?php if (!$isNew): ?>
			<?php if ($rights['save'] && (NuTwoFA::hasTotp($id) || NuTwoFA::deviceCount($id) > 0)): ?>
				<form method="post" action="<?= h(nu_url('user', ['id' => $id])) ?>" data-confirm="<?= h(t('user_2fa_reset_confirm')) ?>"
					data-confirm-ok="<?= h(t('user_2fa_reset')) ?>" data-confirm-class="btn btn-warning">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="reset2fa">
					<button class="btn btn-outline-warning" type="submit"><i class="bi bi-shield-x me-1"></i><?= h(t('user_2fa_reset')) ?></button>
				</form>
			<?php endif; ?>
			<?php if ($rights['clone']): ?>
				<a class="btn nuBtnAct" href="<?= h(nu_url('user', ['clone' => $id])) ?>"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></a>
			<?php endif; ?>
			<?php if (($rights['delete'] || $rights['save']) && $id !== $me['id']): ?>
				<button class="btn nuBtnAct nuBtnDel" type="button" data-bs-toggle="modal" data-bs-target="#nuUserRemove"
					data-choice-action="<?= h(nu_url('user', ['id' => $id])) ?>"
					data-choice-name="<?= h(trim(($existing['sus_first_name'] ?? '') . ' ' . ($existing['sus_last_name'] ?? '')) ?: ($existing['sus_name'] ?: $existing['sus_login_name'])) ?>"
					data-choice-archived="<?= nu_is_expired($existing['sus_expires_on']) ? '1' : '0' ?>"
					title="<?= h(t('user_remove')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
			<?php $pwInfo = '';                                  // password last changed (nuBuilder: sus_json PASSWORD_CHANGED_TIME / _SOURCE) – in the (i) panel
			if (($pwTime = (int)NuUserJson::get((string)$id, 'PASSWORD_CHANGED_TIME')) > 0) {
				$pwSrc = (string)NuUserJson::get((string)$id, 'PASSWORD_CHANGED_SOURCE');
				$pwInfo = date(nu_dfmt('d.m.Y H:i'), $pwTime) . (in_array($pwSrc, ['user', 'admin', 'reset'], true) ? ' (' . t('user_pw_src_' . $pwSrc) . ')' : '');
			} ?>
			<?php // (i) links to the activity of this user: its log entries, the log about this record, its sessions
			$riLinks = [];
			if (NuAcl::can(NuAcl::FORM_LOG)) {
				$riLinks[] = ['bi-journal-text', t('user_ri_log'), nu_url('log', ['login' => (string)$existing['sus_login_name'], 'nf' => '1'])];
				$riLinks[] = ['bi-clock-history', t('user_ri_changes'), nu_url('log', ['q' => (string)$id, 'nf' => '1'])];
			}
			if (NuAdmin::can('sessions')) $riLinks[] = ['bi-people', t('user_ri_sessions'), nu_url('sessions', ['user' => (string)$id, 'nf' => '1'])]; ?>
			<?= NuFormInfo::record('zzzzsys_user', (string)$id, ['login' => (string)$existing['sus_login_name']], 'nuRecInfo', $pwInfo !== '' ? ['pw_changed' => $pwInfo] : [], $riLinks) /* (i): record ID, table, created / changed */ ?>
		<?php endif; ?>
	</div>
</div>

<?php if (!$canSave): ?>
	<div class="alert alert-info d-flex gap-2"><i class="bi bi-eye mt-1"></i><div><?= h(t('read_only')) ?></div></div>
<?php endif; ?>

<form method="post" data-nu-focus-first data-nu-guard id="nuRecordForm" data-nu-view-root
	action="<?= h(nu_url('user', $isNew ? ($cloneId !== '' ? ['clone' => $cloneId] : []) : ['id' => $id])) ?>"<?= $isNew && $cloneId !== '' ? ' data-nu-clone="' . h(t('form_clone_badge')) . '"' : '' ?>
	data-nu-new="<?= $isNew ? '1' : '0' ?>" novalidate>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<fieldset<?= $ro ?>>
	<?php // tabs General / Permission items: one form, one Save; ?tab=perm opens the second tab (also after saving there)
	$userTab = (string)($_GET['tab'] ?? '');
	if ($errors) $userTab = '';                                   // every field with an error is on General (custom fields too)
	if ($userTab !== 'perm') $userTab = '';                      // ?tab=more (custom fields tab until 0.8.590) opens General
	$permTab = $userTab === 'perm';
	$permCount = count(array_intersect($permSel, array_column($permItems, 'zzzzsys_permission_item_id'))); ?>
	<input type="hidden" name="tab" id="userTab" value="<?= h($userTab) ?>">
	<ul class="nav nav-tabs mb-3" id="userTabs" role="tablist">
		<li class="nav-item" role="presentation"><button class="nav-link<?= $userTab === '' ? ' active' : '' ?>" id="userTabGeneralBtn" data-bs-toggle="tab"
			data-bs-target="#userTabGeneral" data-nu-tab="" type="button" role="tab" aria-controls="userTabGeneral"
			aria-selected="<?= $userTab === '' ? 'true' : 'false' ?>"><i class="bi bi-person me-1"></i><?= h(t('user_tab_general')) ?></button></li>
		<li class="nav-item" role="presentation"><button class="nav-link<?= $permTab ? ' active' : '' ?>" id="userTabPermBtn" data-bs-toggle="tab"
			data-bs-target="#userTabPerm" data-nu-tab="perm" type="button" role="tab" aria-controls="userTabPerm"
			aria-selected="<?= $permTab ? 'true' : 'false' ?>"><i class="bi bi-key me-1"></i><?= h(t('perm_title')) ?> <span class="badge rounded-pill text-bg-secondary ms-1"
			id="userPermTabCount"><?= $permCount ?></span></button></li>
	</ul>
	<div class="tab-content">
	<div class="tab-pane fade<?= $userTab === '' ? ' show active' : '' ?>" id="userTabGeneral" role="tabpanel" aria-labelledby="userTabGeneralBtn" tabindex="0">
	<?php // layout (the owner picked mockup A of the canvas "User form layouts"): a summary card on the left (initials, name,
	// login, status, dates, the sign-in tools), on the right three sections Person · Organisation · Sign-in & access, each one even grid
	$ini = static function (string $first, string $last, string $full): string {
		$w = trim($first) !== '' || trim($last) !== '' ? array_filter([trim($first), trim($last)]) : preg_split('/\s+/u', trim($full), -1, PREG_SPLIT_NO_EMPTY);
		$w = array_values($w ?: []);
		$c = $w ? mb_substr($w[0], 0, 1) . (count($w) > 1 ? mb_substr($w[count($w) - 1], 0, 1) : '') : '';
		return mb_strtoupper($c);
	};
	$initials = $ini((string)$v['sus_first_name'], (string)$v['sus_last_name'], (string)$v['sus_name']);
	$lastLogin = '';
	if (!$isNew) {
		try { $ll = nu_db()->value("SELECT MAX(log_time) FROM zzzzsys_nunext_log WHERE user_id = ? AND event = 'login'",
			[$id]); $lastLogin = $ll ? date(nu_dfmt('d.m.Y H:i'), (int)strtotime((string)$ll)) : ''; } catch (Throwable $ig) { nu_ignored($ig,
			'no log table'); }
	}
	[$stCreated, $stChanged] = $isNew ? ['', ''] : NuUserJson::stamps((string)$id);
	$accCode = '';
	foreach ($accessLevels as $a) if ($v['sus_zzzzsys_access_id'] === $a['zzzzsys_access_id']) $accCode = (string)($a['sal_code'] ?: $a['zzzzsys_access_id']);
	$expired = !$isNew && nu_is_expired($existing['sus_expires_on']); ?>
	<div class="userLayout">
	<aside class="card shadow-sm userSummary" aria-label="<?= h(t('user_summary')) ?>">
		<div class="card-body">
			<div class="userSumHead">
				<div class="userAvatar userAvatarLg" id="userAvatar"
					aria-hidden="true"><span id="userAvatarTxt"><?= h($initials) ?></span><i class="bi bi-person<?= $initials !== '' ? ' d-none' : '' ?>"
					id="userAvatarIcon"></i></div>
				<div class="userSumName" id="userAvatarName" data-new="<?= h(t('user_new')) ?>"><?= h(trim((string)$v['sus_name']) !== '' ? (string)$v['sus_name'] : t('user_new')) ?></div>
				<div class="userSumSub"><?php if (trim((string)$v['sus_login_name']) !== ''): ?><button type="button" class="userSumJump"
					data-nu-jump="sus_login_name"
					title="<?= h(t('user_login_name')) ?>"><?= h(trim((string)$v['sus_login_name'])) ?></button><?php endif; ?><?php if (trim((string)$v['sus_code']) !== ''): ?> · <button type="button"
					class="userSumJump" data-nu-jump="sus_code"><?= h(t('user_code')) . ' ' . h((string)$v['sus_code']) ?></button><?php endif; ?></div>
				<div class="userSumBadges">
					<?php if (!$isNew): ?><button type="button" class="badge rounded-pill userSumJump <?= $expired ? 'text-bg-danger' : 'userSumActive' ?>"
						data-nu-jump="sus_expires_on" title="<?= h(t('user_expires')) ?>"><?= h(t($expired ? 'user_expired' : 'user_active')) ?></button><?php endif; ?>
					<?php if ($accCode !== ''): ?><button type="button" class="badge rounded-pill userSumJump userSumLevel" data-nu-jump="sus_zzzzsys_access_id"
						title="<?= h(t('access_level') . ': ' . $accCode) ?>"><?= h($accCode) ?></button><?php endif; ?>
				</div>
			</div>
			<?php if (!$isNew): ?>
			<dl class="userSumMeta">
				<dt><?= h(t('lv_last_login')) ?></dt><dd><?= h($lastLogin !== '' ? $lastLogin : t('user_sum_never')) ?></dd>
				<?php if ($stCreated !== ''): ?><dt><?= h(t('user_sum_created')) ?></dt><dd><?= h($stCreated) ?></dd><?php endif; ?>
				<?php if ($stChanged !== ''): ?><dt><?= h(t('user_sum_changed')) ?></dt><dd><?= h($stChanged) ?></dd><?php endif; ?>
				<?php if (!empty($pwInfo)): ?><dt><?= h(t('user_sum_pw')) ?></dt><dd><?= h($pwInfo) ?></dd><?php endif; ?>
			</dl>
			<div class="userSumTools">
					<?php $pwrShow = !$isNew && $rights['save'] && NuPwReset::refusal($existing, $me) === null;
					$pwrRow = is_array($_SESSION[NuSess::PW_RESET_LINK] ?? null) ? $_SESSION[NuSess::PW_RESET_LINK] : [];   // only after "create link" (a new user's '' id matched the missing row)
					$pwrLink = $id !== '' && ($pwrRow['id'] ?? null) === $id ? (string)($pwrRow['link'] ?? '') : '';
					unset($_SESSION[NuSess::PW_RESET_LINK]);
					if ($pwrShow): ?>
						<div class="mb-3" id="pwrBox">
							<button class="btn btn-outline-secondary btn-sm" type="button" id="pwrOpen" data-bs-toggle="modal" data-bs-target="#pwrDlg"><i class="bi bi-key me-1"></i><?= h(t('pwr_button')) ?></button>
							<?php if ($pwrLink !== ''): ?>
								<div class="input-group input-group-sm mt-2">
									<span class="input-group-text"><?= h(t('pwr_link_title')) ?></span>
									<input class="form-control font-monospace" id="pwrLinkUrl" readonly value="<?= h($pwrLink) ?>" aria-label="<?= h(t('pwr_link_title')) ?>">
									<button class="btn btn-outline-secondary" type="button" id="pwrLinkCopy" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
								</div>
								<div class="form-text"><?= h(t('pwr_shown_once', NuPwReset::HOURS)) ?></div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
							<?php if (!$isNew && $me['globeadmin'] && empty($me['demo']) && NuLoginToken::enabled() && !(class_exists('NuApiClient') && NuApiClient::isServiceUser($id))):
								$ltState = NuLoginToken::state($id);
								$ltNew = ($_SESSION[NuSess::LOGIN_LINK]['id'] ?? '') === $id ? (string)$_SESSION[NuSess::LOGIN_LINK]['link'] : '';
								unset($_SESSION[NuSess::LOGIN_LINK]); ?>
								<details class="mt-2" id="loginTokenBox" data-nu-noguard<?= $ltNew !== '' ? ' open' : '' ?>><summary class="small"><i class="bi bi-key me-1"></i><?= h(t('ltok_title')) ?>
									<?= nu_info_tip(t('ltok_tip'), 'ms-1', true, '', 'logintoken') ?></summary>
									<div class="small text-body-secondary mt-1"
										id="loginTokenState"><?= h($ltState === null ? t('ltok_state_none') : ($ltState === 0 ? t('ltok_state_open') : ($ltState < time() ? t('ltok_state_expired', date(nu_dfmt('d.m.Y H:i'), $ltState)) : t('ltok_state_until', date(nu_dfmt('d.m.Y H:i'), $ltState))))) ?></div>
									<?php if ($ltNew !== ''): ?>
										<div class="input-group input-group-sm mt-1">
											<input class="form-control font-monospace" id="loginTokenUrl" readonly value="<?= h($ltNew) ?>" aria-label="<?= h(t('ltok_title')) ?>">
											<button class="btn btn-outline-secondary" type="button" id="loginTokenCopy" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
										</div>
										<div class="form-text"><?= h(t('ltok_shown_once')) ?></div>
									<?php endif; ?>
									<div class="input-group input-group-sm mt-2">
										<select class="form-select flex-grow-0 w-auto" name="minutes" form="loginTokenForm" aria-label="<?= h(t('user_autologin_valid')) ?>" title="<?= h(t('user_autologin_valid')) ?>">
											<?php foreach (NuLoginToken::CHOICES as $m): ?><option value="<?= $m ?>"<?= $m === 1440 ? ' selected' : '' ?>><?= h($m === 0 ? t('user_autologin_forever') : NuLoginToken::duration($m)) ?></option><?php endforeach; ?>
										</select>
										<button class="btn btn-outline-secondary" type="submit" form="loginTokenForm" name="action" value="logintoken_create"><i class="bi bi-plus-lg me-1"></i><?= h(t('ltok_create')) ?></button>
										<?php if (trim((string)($existing['sus_email'] ?? '')) !== ''): ?>
											<button class="btn btn-outline-secondary" type="submit" form="loginTokenForm" name="action" value="logintoken_mail"
												title="<?= h(t('ltok_create_mail') . ': ' . (string)$existing['sus_email']) ?>"
												aria-label="<?= h(t('ltok_create_mail')) ?>"><i class="bi bi-envelope"></i></button>
										<?php endif; ?>
										<?php if ($ltState !== null): ?>
											<button class="btn btn-outline-danger" type="submit" form="loginTokenClearForm" title="<?= h(t('ltok_clear')) ?>"
												aria-label="<?= h(t('ltok_clear')) ?>"><i class="bi bi-x-octagon"></i></button>
										<?php endif; ?>
									</div>
								</details>
							<?php endif; ?>
							<?php if (!$isNew && $me['globeadmin'] && empty($me['demo']) && nu_cfg('nuConfigAutoLogin') && (string)($existing['sus_login_password'] ?? '') !== ''): // never for demo visitors: it logs in as that user
								$alLinks = [];
								foreach ([7, 1, 30, 90, 0] as $d) $alLinks[$d] = NuAuth::autoLoginLink($existing, $d); ?>
								<details class="mt-2" id="autoLoginBox"><summary class="small"><i class="bi bi-link-45deg me-1"></i><?= h(t('user_autologin')) ?></summary>
									<div class="input-group input-group-sm mt-1">
										<select class="form-select flex-grow-0 w-auto" id="autoLoginDays" aria-label="<?= h(t('user_autologin_valid')) ?>" title="<?= h(t('user_autologin_valid')) ?>">
											<?php foreach ($alLinks as $d => $link): ?><option value="<?= $d ?>"
												data-link="<?= h($link) ?>"><?= h($d === 0 ? t('user_autologin_forever') : ($d === 1 ? t('user_autologin_day') : t('user_autologin_days', $d))) ?></option><?php endforeach; ?>
										</select>
										<input class="form-control font-monospace" id="autoLoginUrl" readonly value="<?= h($alLinks[7]) ?>" aria-label="<?= h(t('user_autologin')) ?>">
										<button class="btn btn-outline-secondary" type="button" id="autoLoginCopy" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
										<button class="btn btn-outline-danger" type="submit" form="autoLoginRevokeForm"
											title="<?= h(t('user_autologin_revoke_hint')) ?>"><i class="bi bi-x-octagon me-1"></i><?= h(t('user_autologin_revoke')) ?></button>
									</div>
									<div class="form-text"><?= h(t('user_autologin_hint')) ?></div>
								</details>
							<?php endif; ?>
					<?php if (!$isNew && $rights['save'] && ($userPks = NuPasskey::list($id))):   // passkeys of the user: seen and removed here, added only by the user ?>
						<details class="mb-3" id="userPasskeys"
							data-nu-noguard><summary class="small"><i class="bi bi-fingerprint me-1"></i><?= h(t('pk_title')) ?> <span class="badge rounded-pill text-bg-secondary"><?= count($userPks) ?></span></summary>
							<ul class="list-group list-group-flush small mt-1">
								<?php foreach ($userPks as $pk): ?>
									<li class="list-group-item d-flex align-items-center gap-2 px-0">
										<span class="me-auto"><?= h((string)$pk['name']) ?> <span class="text-body-secondary">· <?= h((string)($pk['last_used_at'] ?? '') !== '' ? t('pk_last_used', date(nu_dfmt('d.m.Y'), strtotime((string)$pk['last_used_at']) ?: time())) : t('pk_never_used')) ?></span></span>
										<?php if (empty($me['demo'])): ?><button class="btn btn-outline-danger btn-sm" type="submit" form="userPkForm" name="pk"
											value="<?= h((string)$pk['zzzzsys_nunext_passkey_id']) ?>" title="<?= h(t('pk_remove')) ?>"
											aria-label="<?= h(t('pk_remove') . ': ' . (string)$pk['name']) ?>"><i class="bi bi-trash"></i></button><?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
	</aside>
	<div class="userMain">
		<section class="card shadow-sm userCardPerson">
			<div class="card-body">
				<h2 class="userSecTitle"><?= h(t('user_sec_person')) ?></h2>
				<div class="userGrid">
					<div class="uiF-name"><?= $input('sus_name', 'text', ['required' => true]) ?></div>
					<div><?= $input('sus_first_name', 'text', ['autocomplete' => 'off']) ?></div>
					<div><?= $input('sus_last_name', 'text', ['autocomplete' => 'off']) ?></div>
					<div class="userSpan2"><?= $input('sus_email', 'email', ['autocomplete' => 'off', 'data-nu-email-msg' => t('user_err_email')]) ?></div>
					<div><?= $input('sus_code', 'text', ['unique' => 'code']) ?></div>
				</div>
			</div>
		</section>
		<section class="card shadow-sm userCardOrg">
			<div class="card-body">
				<h2 class="userSecTitle"><?= h(t('user_sec_org')) ?></h2>
				<div class="userGrid">
					<div><?= $input('sus_position') ?></div>
					<div><?= $input('sus_department') ?></div>
					<div><?= $input('sus_team') ?></div>
					<div><?= $input('sus_additional1') ?></div>
					<div><?= $input('sus_additional2') ?></div>
				</div>
			</div>
		</section>
			<?php if ($ufAll): // custom user fields – own card below Organisation ?>
			<div class="card shadow-sm" id="userTabMore">
				<div class="card-header d-flex align-items-center gap-2"><span class="fw-semibold me-auto"><i class="bi bi-ui-checks me-2"></i><?= h(t('user_tab_more')) ?></span>
					<?php if (NuAdmin::can('userfields')): ?><a class="small" href="<?= h(nu_url('userfields')) ?>"
						id="userFieldsManage"><i class="bi bi-gear me-1"></i><?= h(t('uf_manage')) ?></a><?php endif; ?></div>
				<div class="card-body"><div class="row g-3">
					<?php foreach ($ufAll as $code => $f) echo NuUserFields::inputHtml($f, (string)($ufVals[$code] ?? ''), $ufErr[$code] ?? null, !isset($ufEdit[$code]), true); ?>
				</div></div>
			</div>
			<?php endif; ?>
		<section class="card shadow-sm userCardLogin">
			<div class="card-body">
				<h2 class="userSecTitle"><?= h(t('user_sec_signin')) ?></h2>
				<div class="userGrid">
					<div><?= $input('sus_login_name', 'text', ['required' => true, 'unique' => true, 'autocomplete' => 'off', 'spellcheck' => 'false']) ?></div>
					<div>
							<label class="form-label" for="password"><?= h(t('login_password')) ?><?= $isNew ? ' <span class="text-danger">*</span>' : '' ?></label>
							<div class="input-group<?= isset($errors['password']) ? ' has-validation' : '' ?>">
								<input type="password" class="form-control<?= nu_invalid($errors, 'password') ?>" id="password" name="password"
									autocomplete="new-password"<?= $isNew ? '' : ' placeholder="' . h(t('user_pw_unchanged')) . '" title="' . h(t('user_pw_keep')) . '"' ?>>
								<button class="btn btn-outline-secondary" type="button" data-pw-toggle="#password" aria-label="<?= h(t('show_password')) ?>"><i class="bi bi-eye"></i></button>
								<?php if ($canSave): ?><button class="btn btn-outline-secondary nuPwGenBtn" type="button" data-bs-toggle="modal" data-bs-target="#nuPwGen"
									title="<?= h(t('pwg_title')) ?>" aria-label="<?= h(t('pwg_title')) ?>"><i class="bi bi-magic"></i></button><?php endif; ?>
								<?= nu_feedback($errors, 'password') ?>
							</div>
							<?php // existing user: placeholder "(unchanged)" instead of the hint below; "Password last changed" is in the (i) panel ?>
							<div class="form-text<?= $isNew ? '' : ' d-none' ?>" id="passwordHint"><?= h($isNew ? t('pw_min_hint', $min) : '') ?></div>
							<?= NuPwPolicy::rulesHtml('password', true) ?>
					</div>
					<div>
							<label class="form-label" for="password_confirm"><?= h(t('pw_confirm')) ?></label>
							<div class="input-group<?= isset($errors['password_confirm']) ? ' has-validation' : '' ?>">
								<input type="password" class="form-control<?= nu_invalid($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm"
									autocomplete="new-password"<?= $isNew ? '' : ' placeholder="' . h(t('user_pw_unchanged')) . '"' ?>>
								<button class="btn btn-outline-secondary" type="button" data-pw-toggle="#password_confirm" aria-label="<?= h(t('show_password')) ?>"><i class="bi bi-eye"></i></button>
								<?= nu_feedback($errors, 'password_confirm') ?>
							</div>
					</div>
					<div class="userCardAccess">
						<label class="form-label" for="sus_zzzzsys_access_id"><?= h(t('access_level')) ?> <span class="text-danger">*</span></label>
						<?php $canOpenAccess = NuAcl::can(NuAcl::FORM_ACCESS); $accessUrl = nu_url('accesslevel', ['id' => '__ID__']); ?>
						<div class="<?= $canOpenAccess ? 'input-group' . (isset($errors['sus_zzzzsys_access_id']) ? ' has-validation' : '') : '' ?>">
						<select class="form-select nuListTomSelect<?= nu_invalid($errors, 'sus_zzzzsys_access_id') ?>" id="sus_zzzzsys_access_id" name="sus_zzzzsys_access_id" required>
							<option value=""><?= h(t('please_select')) ?></option>
							<?php $grp = null; foreach ($accessLevels as $a):
								$g = (string)$a['sal_group'];
								if ($g !== $grp) { if ($grp !== null) echo '</optgroup>'; echo '<optgroup label="' . h($g !== '' ? $g : t('no_group')) . '">'; $grp = $g; } ?>
								<option value="<?= h($a['zzzzsys_access_id']) ?>"
									data-sub="<?= h((string)$a['sal_description']) ?>"<?= $v['sus_zzzzsys_access_id'] === $a['zzzzsys_access_id'] ? ' selected' : '' ?>><?= h((string)($a['sal_code'] ?: $a['zzzzsys_access_id'])) ?></option>
							<?php endforeach; if ($grp !== null) echo '</optgroup>'; ?>
						</select>
						<?php if ($canOpenAccess):   // opens the chosen access level in a new tab (assets/js/nuuser.js keeps the link in step with the select) ?>
							<a class="btn btn-outline-secondary nuOpenBtn<?= $v['sus_zzzzsys_access_id'] === '' ? ' disabled' : '' ?>" id="accessOpen" target="_blank" rel="noopener"
							   data-url="<?= h($accessUrl) ?>" href="<?= h($v['sus_zzzzsys_access_id'] === '' ? '#' : str_replace('__ID__', rawurlencode($v['sus_zzzzsys_access_id']), $accessUrl)) ?>"
							   <?= $v['sus_zzzzsys_access_id'] === '' ? 'aria-disabled="true" tabindex="-1"' : '' ?> title="<?= h(t('user_access_open')) ?>"
							   	aria-label="<?= h(t('user_access_open')) ?>"><i class="bi bi-box-arrow-up-right"></i></a>
						<?php endif; ?>
						<?= nu_feedback($errors, 'sus_zzzzsys_access_id') ?>
						</div>
					</div>
					<div>
					<?php if ($admRoleMay || $admRoleCur !== ''): $admRoles = NuAdmin::roles(); ?>
					<div class="mb-3" id="admRoleField">
						<label class="form-label" for="admin_role"><?= h(t('adm_role_field')) ?></label><?= nu_info_tip(t('adm_role_field_tip'), 'ms-1', true, '', 'admin-roles') ?>
						<?php $canOpenRole = NuAdmin::can('adminroles', 'view'); $roleUrl = nu_url('adminroles', ['id' => '__ID__']);   // open the role in a new tab ?>
						<div class="<?= $canOpenRole ? 'input-group' : '' ?>">
						<select class="form-select" id="admin_role" name="admin_role"<?= $admRoleMay ? '' : ' disabled' ?>>
							<option value=""><?= h(t('adm_role_none')) ?></option>
							<?php foreach ($admRoles as $ar): ?><option value="<?= h($ar['id']) ?>"<?= $admRoleSel === $ar['id'] ? ' selected' : '' ?>><?= h($ar['name'] . (NuAdmin::hasFull($ar['rights']) ? ' ⚠' : '')) ?></option><?php endforeach; ?>
						</select>
						<?php if ($canOpenRole): ?>
							<a class="btn btn-outline-secondary nuOpenBtn<?= $admRoleSel === '' ? ' disabled' : '' ?>" id="admRoleOpen" target="_blank" rel="noopener"
							   data-url="<?= h($roleUrl) ?>" href="<?= h($admRoleSel === '' ? '#' : str_replace('__ID__', rawurlencode($admRoleSel), $roleUrl)) ?>"
							   <?= $admRoleSel === '' ? 'aria-disabled="true" tabindex="-1"' : '' ?> title="<?= h(t('adm_role_open')) ?>"
							   	aria-label="<?= h(t('adm_role_open')) ?>"><i class="bi bi-box-arrow-up-right"></i></a>
						<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>
					</div>
					<div class="userCardAccount">
							<label class="form-label" for="sus_expires_on"><?= h(t('user_expires')) ?></label><?= nu_info_tip(t('user_expires_hint')) ?>
							<input type="date" class="form-control<?= nu_invalid($errors, 'sus_expires_on') ?>" id="sus_expires_on" name="sus_expires_on" value="<?= h($v['sus_expires_on']) ?>">
							<?= nu_feedback($errors, 'sus_expires_on') ?>
					</div>
					<div>
							<label class="form-label" for="sus_language"><?= h(t('user_language')) ?></label>
							<select class="form-select" id="sus_language" name="sus_language">
								<?php foreach ($langOptions as $val => $label): ?>
									<option value="<?= h($val) ?>"<?= $langSelected === (string)$val ? ' selected' : '' ?>><?= h($label) ?></option>
								<?php endforeach; ?>
							</select>
					</div>
					<div class="userSpan2 userSwitches">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="sus_change_password" name="sus_change_password" value="1"<?= $v['sus_change_password'] === '1' ? ' checked' : '' ?>>
						<label class="form-check-label" for="sus_change_password"><?= h(t('user_change_pw')) ?></label>
					</div>
					<?php if ($canSave):
						// always shown – greyed out while e-mail is not set up, the (i) says so and a link leads to the E-mail settings (with that right)
						$wHint = $mailOk ? t('welcome_option_hint', NuWelcome::hours()) : t('welcome_need_mail'); ?>
					<div class="form-check form-switch" id="userWelcomeWrap" data-nu-view-skip>
						<input class="form-check-input" type="checkbox" role="switch" id="user_send_welcome_email" name="user_send_welcome_email"
							value="1"<?= $welcome ? ' checked' : '' ?><?= $mailOk ? '' : ' disabled' ?> aria-describedby="welcomeHint"
							<?php if ($mailOk): ?>data-nu-welcome="<?= h((string)json_encode(NuWelcome::sources(), JSON_UNESCAPED_UNICODE)) ?>"
							data-nu-welcome-t="<?= h((string)json_encode(['title' => t('welcome_option'), 'proc' => t('welcome_src_proc'), 'tpl' => t('welcome_src_tpl'), 'std' => t('welcome_src_std'), 'builtin' => t('welcome_src_builtin'), 'close' => t('close')], JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
						<label class="form-check-label" for="user_send_welcome_email"><?= h(t('welcome_option')) ?></label><?php // hint as tooltip of the (i), outside the label (a click does not toggle) ?>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('info')) ?>"
							title="<?= h($wHint) ?>"><i class="bi bi-info-circle"></i></span>
						<?php if (!$mailOk && NuAcl::can(NuAcl::FORM_SETUP)): ?><a class="small ms-2" id="userWelcomeMailLink"
							href="<?= h(nu_url('email', ['tab' => 'settings'])) ?>"><i class="bi bi-envelope-at me-1"></i><?= h(t('welcome_mail_settings')) ?></a><?php endif; ?>
						<span class="visually-hidden" id="welcomeHint"><?= h($wHint) ?></span>
					</div>
					<?php if ($mailOk): $wProc = NuWelcome::hasProcedure(); // preview before sending – shown while the switch above is on ?>
					<div class="form-check form-switch ms-4" id="userWelcomePvWrap"<?= $welcome ? '' : ' hidden' ?>>
						<input class="form-check-input" type="checkbox" role="switch" id="user_welcome_preview" data-nu-noguard<?= $wProc ? ' disabled' : '' ?>>
						<label class="form-check-label" for="user_welcome_preview"><?= h(t('welcome_pv_option')) ?></label>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('info')) ?>"
							title="<?= h($wProc ? t('welcome_pv_proc', NuWelcome::PROCEDURE) : t('welcome_pv_option_tip')) ?>"><i class="bi bi-info-circle"></i></span>
					</div>
					<?php endif; ?>
					<?php if (isset($errors['welcome'])): ?><div class="invalid-feedback d-block" id="userWelcomeErr"><?= h($errors['welcome']) ?></div><?php endif; ?>
					<?php endif; ?>
					<?php if (NU_FEATURE_ACCESSIBILITY): ?>
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="sus_accessibility_features" name="sus_accessibility_features"
							value="1"<?= $v['sus_accessibility_features'] === '1' ? ' checked' : '' ?>>
						<label class="form-check-label" for="sus_accessibility_features"><?= h(t('user_accessibility')) ?></label>
					</div>
					<?php endif; ?>
					</div>
					<div class="userSpan3">
					<?php // additional access levels (core/nuuseraccess.php): rows [level, valid until], added / removed in assets/js/nuuser.js ?>
					<div class="mb-3" id="xaBox">
						<input type="hidden" name="xa_sent" value="1">
						<div class="d-flex align-items-center gap-2 mb-1">
							<span class="form-label mb-0"><?= h(t('xa_title')) ?></span><?= nu_info_tip(t('xa_tip'), '', true, '', 'access-additional') ?>
							<span class="ms-auto d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary<?= $xaSel ? '' : ' d-none' ?>" id="xaEff"
								data-bs-toggle="modal" data-bs-target="#xaEffDlg"><i class="bi bi-shield-check me-1"></i><?= h(t('eff_btn')) ?></button>
							<button type="button" class="btn btn-sm btn-outline-secondary" id="xaAdd"<?= $canSave ? '' : ' disabled' ?>><i class="bi bi-plus-lg me-1"></i><?= h(t('xa_add')) ?></button></span>
						</div>
						<?php $xaOpt = static function (string $sel) use ($accessLevels): string {
							$o = '<option value="">' . h(t('please_select')) . '</option>';
							foreach ($accessLevels as $a) $o .= '<option value="' . h($a['zzzzsys_access_id']) . '"'
								. ($sel === (string)$a['zzzzsys_access_id'] ? ' selected' : '') . '>'
								. h((string)($a['sal_code'] ?: $a['zzzzsys_access_id']) . ((string)$a['sal_description'] !== '' ? ' – ' . $a['sal_description'] : ''))
								. '</option>';
							return $o;
						}; $xaRow = static function (string $acc, string $until) use ($xaOpt, $canSave): string {
							$gone = $until !== '' && !NuUserAccess::active($until);
							return '<div class="input-group input-group-sm mb-1 xaRow' . ($gone ? ' xaExpired' : '') . '">'
								. '<select class="form-select" name="xa_acc[]" aria-label="' . h(t('access_level')) . '"' . ($canSave ? '' : ' disabled') . '>' . $xaOpt($acc) . '</select>'
								. '<span class="input-group-text">' . h(t('xa_until')) . '</span>'
								. '<input type="date" class="form-control xaUntil" name="xa_until[]" value="' . h($until) . '" aria-label="' . h(t('xa_until')) . '"' . ($canSave ? '' : ' disabled') . '>'
								. ($gone ? '<span class="input-group-text text-body-secondary">' . h(t('user_expired')) . '</span>' : '')
								. '<button type="button" class="btn btn-outline-danger xaDel" title="' . h(t('remove')) . '" aria-label="' . h(t('remove')) . '"'
									. ($canSave ? '' : ' disabled') . '><i class="bi bi-x-lg"></i></button></div>';
						}; ?>
						<div id="xaRows"><?php foreach ($xaSel as [$xa, $xu]) echo $xaRow((string)$xa, (string)$xu); ?></div>
						<template id="xaTpl"><?= $xaRow('', '') ?></template>
						<div class="small text-body-secondary<?= $xaSel ? ' d-none' : '' ?>" id="xaNone"><?= h(t('xa_none')) ?></div>
						<?php if (isset($errors['xa'])): ?><div class="invalid-feedback d-block"><?= h($errors['xa']) ?></div><?php endif; ?>
					</div>
					</div>
				</div>
			</div>
		</section>
	</div>
	</div>
	</div>
	<div class="tab-pane fade<?= $permTab ? ' show active' : '' ?>" id="userTabPerm" role="tabpanel" aria-labelledby="userTabPermBtn" tabindex="0">
			<div class="card shadow-sm" id="userPerms">
				<div class="card-header fw-semibold d-flex flex-wrap align-items-center gap-2">
					<span class="me-auto"><i class="bi bi-key me-2"></i><?= h(t('perm_title')) ?>
						<span class="badge text-bg-secondary ms-1" id="userPermCount"><?= $permCount ?></span></span>
					<?php if (NuAcl::can(NuAcl::FORM_PERMISSION)): ?><a class="btn btn-sm btn-outline-secondary fw-normal"
						href="<?= h(nu_url('permissions')) ?>"><i class="bi bi-gear me-1"></i><?= h(t('perm_manage')) ?></a><?php endif; ?>
				</div>
				<div class="card-body">
					<?php if (!$permItems): ?>
						<div class="text-body-secondary small"><?= h(t('perm_none_defined')) ?></div>
					<?php else: ?>
						<input type="hidden" name="perm_sent" value="1">
						<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
							<div class="input-group input-group-sm userPermSearch">
								<span class="input-group-text"><i class="bi bi-search"></i></span>
								<input type="search" class="form-control" id="userPermFilter" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('perm_search')) ?>">
							</div>
							<?php // assigned / all / not assigned: assigned by default – all when the user has none yet ?>
							<select class="form-select form-select-sm w-auto" id="userPermState" autocomplete="off" aria-label="<?= h(t('perm_state')) ?>">
								<?php foreach (['assigned', 'all', 'unassigned'] as $ps): // always "Assigned" when the record opens ?><option value="<?= $ps ?>"<?= $ps === 'assigned' ? ' selected' : '' ?>><?= h(t('perm_state_' . $ps)) ?></option><?php endforeach; ?>
							</select>
							<?php $permGroups = array_values(array_unique(array_map(static fn($pi) => (string)$pi['pme_group'], $permItems))); ?>
							<?php if ($permGroups):   // group filter (always shown): All / None then apply to that group only ?>
								<select class="form-select form-select-sm w-auto" id="userPermGroup" aria-label="<?= h(t('perm_group')) ?>">
									<option value="*"><?= h(t('perm_group_all')) ?></option>
									<?php foreach ($permGroups as $pg): ?><option value="<?= h($pg) ?>"><?= h($pg !== '' ? $pg : t('no_group')) ?></option><?php endforeach; ?>
								</select>
							<?php endif; ?>
							<button type="button" class="btn btn-sm btn-outline-secondary" data-perm-all="1" data-perm-confirm="<?= h(t('perm_all_confirm')) ?>"
								data-perm-confirm-ok="<?= h(t('perm_all')) ?>"><i class="bi bi-check2-all me-1"></i><?= h(t('perm_all')) ?></button>
							<button type="button" class="btn btn-sm btn-outline-secondary" data-perm-all="0"><i class="bi bi-x-lg me-1"></i><?= h(t('perm_none')) ?></button>
							<?php if ($permFrom): // the items of another user: dialog #userPermCopyDlg – Replace / Add, saved with Save, Undo as for All / None ?>
							<button type="button" class="btn btn-sm btn-outline-secondary" id="userPermCopy" data-bs-toggle="modal"
								data-bs-target="#userPermCopyDlg"><i class="bi bi-person-down me-1"></i><?= h(t('perm_copy')) ?></button>
							<?php endif; ?>
							<?php // Undo: back to the selection before the last All / None – shown until another change (nuuser.js) ?>
							<button type="button" class="btn btn-sm btn-outline-warning d-none" id="userPermUndo"
								title="<?= h(t('perm_undo_tip')) ?>"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('perm_undo')) ?></button>
							<?php // phones: descriptions on / off for a more compact table (remembered per browser, nuuser.js) ?>
							<div class="form-check form-switch mb-0 d-sm-none" id="userPermDescWrap" data-nu-noguard>
								<input class="form-check-input" type="checkbox" role="switch" id="userPermDescToggle" checked>
								<label class="form-check-label small" for="userPermDescToggle"><?= h(t('perm_show_desc')) ?></label>
							</div>
						</div>
						<?php // table: one row per item – a click anywhere on the row toggles it (nuuser.js) ?>
						<div class="table-responsive">
							<table class="table nu-table table-hover align-middle mb-0 userPermTable">
								<thead><tr>
									<?php // sortable columns (nuuser.js): a click sorts, a second one reverses; the first column = selected first ?>
									<th scope="col" class="userPermCheck userPermSort" data-sort="sel"><button type="button" class="btn btn-link p-0 userPermSortBtn"
										title="<?= h(t('perm_sort_sel')) ?>" aria-label="<?= h(t('perm_sort_sel')) ?>"><i class="bi bi-check2-square"></i></button></th>
									<th scope="col" class="userPermSort" data-sort="ident"><button type="button" class="btn btn-link p-0 userPermSortBtn"><?= h(t('perm_ident')) ?></button></th>
									<th scope="col" class="userPermSort d-none d-md-table-cell" data-sort="group" aria-sort="ascending"><button type="button"
										class="btn btn-link p-0 userPermSortBtn"><?= h(t('perm_group')) ?></button></th>
									<th scope="col" class="userPermSort d-none d-sm-table-cell" data-sort="desc"><button type="button" class="btn btn-link p-0 userPermSortBtn"><?= h(t('perm_description')) ?></button></th>
								</tr></thead>
								<tbody>
								<?php $permOpen = NuAcl::can(NuAcl::FORM_PERMISSION);   // link to the item in a new tab ?>
								<?php foreach ($permItems as $pi):
									$g = (string)$pi['pme_group'];
									$pid = (string)$pi['zzzzsys_permission_item_id'];
									// description: one line, as much as fits (CSS: …, the text at most 750 px wide); the full text as tooltip
									$pDesc = (string)$pi['pme_description'];
									$pTitle = $pDesc !== '' ? ' title="' . h($pDesc) . '"' : ''; ?>
									<tr class="userPermItem" data-group="<?= h($g) ?>" data-search="<?= h(mb_strtolower($pi['pme_ident'] . ' ' . $g . ' ' . $pi['pme_description'])) ?>">
										<td class="userPermCheck"><input class="form-check-input" type="checkbox" name="perm[]" value="<?= h($pid) ?>"
											id="perm_<?= h($pid) ?>"<?= in_array($pid, $permSel, true) ? ' checked' : '' ?>></td>
										<td class="userPermIdent"><label class="font-monospace" for="perm_<?= h($pid) ?>"><?= h($pi['pme_ident']) ?></label><?php if ($permOpen): ?>
											<a class="userPermOpen ms-1" href="<?= h(nu_url('permissions', ['id' => $pid])) ?>" target="_blank" rel="noopener"
												title="<?= h(t('perm_open_item')) ?>" aria-label="<?= h(t('perm_open_item')) ?>"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
											<?php $ptx = NuPermType::of($pid); if ($ptx['type'] !== 'plain'): // typed item: what it allows, e.g. "Data import: orders · add + update" ?>
												<div class="small text-primary-emphasis userPermType"><i class="bi <?= h(NuPermType::TYPES[$ptx['type']][1]) ?> me-1"
													aria-hidden="true"></i><?= h(NuPermType::summary($ptx['type'], $ptx['settings'])) ?></div><?php endif; ?>
											<?php if ($pDesc !== ''): // phones: the description below the ident ?><div class="small text-body-secondary userPermDesc userPermDescLine d-sm-none"<?= $pTitle ?>><?= h($pDesc) ?></div><?php endif; ?></td>
										<td class="text-body-secondary text-nowrap d-none d-md-table-cell"><?= h($g !== '' ? $g : t('no_group')) ?></td>
										<td class="text-body-secondary userPermDesc userPermDescCell d-none d-sm-table-cell"><div class="userPermDescLine"<?= $pTitle ?>><?= h($pDesc) ?></div></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div class="text-body-secondary small d-none" id="userPermEmpty" data-none-assigned="<?= h(t('perm_none_assigned')) ?>" data-none="<?= h(t('no_records')) ?>"><?= h(t('no_records')) ?></div>
						<div class="form-text mt-2"><?= h(t('perm_user_hint')) ?></div>
					<?php endif; ?>
				</div>
			</div>
	</div>
	</div>
	</fieldset>

</form>
<?php if (!empty($pwrShow)):
	$pwrMailWhy = NuPwReset::mailProblem($existing);
	$pwrPk = NuPasskey::count($id); ?>
<div class="modal fade" id="pwrDlg" tabindex="-1" aria-labelledby="pwrDlgTitle" aria-hidden="true">
	<div class="modal-dialog"><form class="modal-content" method="post" id="pwrForm" action="<?= h(nu_url('user', ['id' => $id])) ?>" data-nu-once>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="pwreset">
		<div class="modal-header"><h2 class="modal-title fs-5" id="pwrDlgTitle"><i class="bi bi-key me-2"></i><?= h(t('pwr_title')) ?></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('cancel')) ?>"></button></div>
		<div class="modal-body">
			<p class="small"><?= h(t('pwr_dlg_intro', (string)$existing['sus_login_name'], NuPwReset::HOURS)) ?></p>
			<div class="form-check mb-1">
				<input class="form-check-input" type="radio" name="how" id="pwrHowMail" value="mail"<?= $pwrMailWhy === null ? ' checked' : ' disabled' ?>>
				<label class="form-check-label" for="pwrHowMail"><?= h(t('pwr_how_mail', trim((string)$existing['sus_email']) ?: '–')) ?></label>
				<?php if ($pwrMailWhy === null): ?>
					<a class="small ms-1" id="pwrPreview" href="<?= h(nu_url('user', ['id' => $id, 'pwreset_frame' => 1])) ?>" target="_blank" rel="noopener"><?= h(t('pwr_preview')) ?></a>
				<?php else: ?>
					<div class="form-text text-body-secondary" id="pwrMailWhy"><?= h($pwrMailWhy) ?></div>
				<?php endif; ?>
			</div>
			<div class="form-check mb-3">
				<input class="form-check-input" type="radio" name="how" id="pwrHowLink" value="link"<?= $pwrMailWhy === null ? '' : ' checked' ?>>
				<label class="form-check-label" for="pwrHowLink"><?= h(t('pwr_how_link')) ?></label>
			</div>
			<div class="form-check form-switch">
				<input class="form-check-input" type="checkbox" role="switch" id="pwrBlock" name="pwr_block" value="1">
				<label class="form-check-label" for="pwrBlock"><?= h(t('pwr_block')) ?></label>
				<?= nu_info_tip(t('pwr_block_tip'), 'ms-1', true, '', 'pw-reset-admin') ?>
			</div>
			<?php if ($pwrPk > 0): ?>
				<div class="form-check ms-4">
					<input class="form-check-input" type="checkbox" id="pwrPasskeys" name="pwr_passkeys" value="1" disabled>
					<label class="form-check-label" for="pwrPasskeys"><?= h(t('pwr_passkeys', $pwrPk)) ?></label>
				</div>
			<?php endif; ?>
			<p class="small text-body-secondary mt-3 mb-0"><i class="bi bi-shield-check me-1"></i><?= h(t('pwr_2fa')) ?></p>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
			<button type="submit" class="btn btn-primary" id="pwrSend" data-nu-busy="<?= h(t('pwr_send')) ?>"><i class="bi bi-send me-1"></i><?= h(t('pwr_send')) ?></button>
		</div>
	</form></div>
</div>
<?php endif; ?>
<?php if (!$isNew && $me['globeadmin'] && empty($me['demo'])): ?>
<?php // one-time login links – their own forms, not tied to $nuConfigAutoLogin (the buttons did nothing without it) ?>
<form method="post" id="loginTokenForm" action="<?= h(nu_url('user', ['id' => $id])) ?>"><?= nu_csrf_field() ?></form>
<form method="post" id="loginTokenClearForm" action="<?= h(nu_url('user', ['id' => $id])) ?>" data-confirm="<?= h(t('ltok_clear_confirm')) ?>"
	  data-confirm-ok="<?= h(t('ltok_clear')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="logintoken_clear"></form>
<?php endif; ?>
<?php if (!$isNew && $rights['save'] && empty($me['demo']) && !empty($userPks)): ?>
<form method="post" id="userPkForm" action="<?= h(nu_url('user', ['id' => $id])) ?>" data-confirm="<?= h(t('pk_remove_confirm_admin')) ?>"
	  data-confirm-ok="<?= h(t('pk_remove')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="passkey_remove"></form>
<?php endif; ?>
<?php if (!$isNew && $me['globeadmin'] && empty($me['demo']) && nu_cfg('nuConfigAutoLogin')): ?>
<form method="post" id="autoLoginRevokeForm" action="<?= h(nu_url('user', ['id' => $id])) ?>" data-confirm="<?= h(t('user_autologin_revoke_confirm')) ?>"
	  data-confirm-ok="<?= h(t('user_autologin_revoke')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="autologin_revoke"></form>
<?php endif; ?>

<?php if ($canSave):   // password generator (assets/js/nuuser.js) – runs in the browser, nothing is sent before Save
	$pwgMin = max(8, $min);
	$pwgLen = max(16, $pwgMin); ?>
<div class="modal fade" id="nuPwGen" tabindex="-1" aria-labelledby="nuPwGenTitle" aria-hidden="true" data-min="<?= $pwgMin ?>" data-max="64" data-default="<?= min(64, $pwgLen) ?>"
	 data-used="<?= h(t('pwg_used')) ?>" data-used-copied="<?= h(t('pwg_used_copied')) ?>" data-copied="<?= h(t('pwg_copied')) ?>"
	 data-strength="<?= h(json_encode([t('pwg_weak'), t('pwg_fair'), t('pwg_strong'), t('pwg_very_strong')], JSON_UNESCAPED_UNICODE)) ?>" data-bits="<?= h(t('pwg_bits')) ?>">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h2 class="modal-title fs-5" id="nuPwGenTitle"><i class="bi bi-magic me-2"></i><?= h(t('pwg_title')) ?></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
			</div>
			<div class="modal-body">
				<label class="form-label" for="pwgOut"><?= h(t('login_password')) ?></label>
				<div class="input-group">
					<input type="text" class="form-control font-monospace nuPwGenOut" id="pwgOut" readonly spellcheck="false" autocomplete="off" aria-describedby="pwgStrengthText">
					<button class="btn btn-outline-secondary" type="button" id="pwgNew" title="<?= h(t('pwg_regenerate')) ?>" aria-label="<?= h(t('pwg_regenerate')) ?>"><i class="bi bi-arrow-repeat"></i></button>
					<button class="btn btn-outline-secondary" type="button" id="pwgCopy" title="<?= h(t('pwg_copy')) ?>" aria-label="<?= h(t('pwg_copy')) ?>"><i class="bi bi-clipboard"></i></button>
				</div>
				<div class="progress mt-2 nuPwGenBar" role="progressbar" aria-label="<?= h(t('pwg_strength')) ?>"><div class="progress-bar" id="pwgStrengthBar"></div></div>
				<div class="small mt-1 d-flex justify-content-between"><span id="pwgStrengthText"></span><span class="text-body-secondary" id="pwgCopied" aria-live="polite"></span></div>

				<hr>
				<label class="form-label d-flex justify-content-between" for="pwgLenRange"><span><?= h(t('pwg_length')) ?></span></label>
				<div class="d-flex gap-3 align-items-center mb-3">
					<input type="range" class="form-range flex-grow-1" id="pwgLenRange" min="<?= $pwgMin ?>" max="64" step="1" aria-label="<?= h(t('pwg_length')) ?>">
					<input type="number" class="form-control text-center nuPwGenLen" id="pwgLen" min="<?= $pwgMin ?>" max="64" aria-label="<?= h(t('pwg_length')) ?>">
				</div>
				<div class="row g-2">
					<?php foreach (['upper' => 'pwg_upper', 'lower' => 'pwg_lower', 'digits' => 'pwg_digits', 'symbols' => 'pwg_symbols'] as $k => $label): ?>
						<div class="col-sm-6"><div class="form-check form-switch">
							<input class="form-check-input pwgSet" type="checkbox" role="switch" id="pwg_<?= $k ?>" data-set="<?= $k ?>" checked>
							<label class="form-check-label" for="pwg_<?= $k ?>"><?= h(t($label)) ?></label>
						</div></div>
					<?php endforeach; ?>
					<div class="col-12"><div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="pwg_ambiguous" checked>
						<label class="form-check-label" for="pwg_ambiguous"><?= h(t('pwg_ambiguous')) ?></label>
					</div></div>
				</div>
				<div class="form-text mt-3"><i class="bi bi-shield-lock me-1"></i><?= h(t('pwg_hint')) ?></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
				<button type="button" class="btn btn-primary" id="pwgUse"><i class="bi bi-check-lg me-1"></i><?= h(t('pwg_use')) ?></button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<?php
if ($existing && $id !== $me['id']) echo nu_user_remove_modal($rights, ['stay' => '1']);
// rename a field title – assets/js/nuuser.js fills it from the pencil's data-* and posts action=user_label
if (NuUserLabel::canRename()): $lblWhere = NuUserLabel::target('user_additional1') === 'db' ? t('user_lbl_where_db') : t('user_lbl_where_file'); ?>
<div class="modal fade" id="userLblDlg" tabindex="-1" aria-labelledby="userLblDlgT" aria-hidden="true" data-title="<?= h(t('user_lbl_dlg')) ?>" data-hint="<?= h(t('user_lbl_hint')) ?>"
	data-back="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>" data-csrf="<?= h(nu_csrf_token()) ?>">
	<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
		<div class="modal-header py-2"><h2 class="modal-title fs-6" id="userLblDlgT"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('cancel')) ?>"></button></div>
		<div class="modal-body">
			<label class="form-label" for="userLblVal"><?= h(t('user_lbl_new')) ?></label>
			<input class="form-control" id="userLblVal" maxlength="<?= NuUserLabel::MAX ?>" autocomplete="off">
			<div class="form-text" id="userLblHint"></div>
			<div class="form-text"><?= h($lblWhere) ?></div>
			<div class="form-check form-switch mt-3" id="userLblUniqueRow" hidden>
				<input class="form-check-input" type="checkbox" role="switch" id="userLblUnique">
				<label class="form-check-label" for="userLblUnique"><?= h(t('user_code_unique')) ?></label>
				<div class="form-text"><?= h(t('user_code_unique_hint')) ?></div>
			</div>
			<div class="alert alert-danger small mt-2 mb-0" id="userLblErr" hidden></div>
		</div>
		<div class="modal-footer py-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
			<button type="button" class="btn btn-primary" id="userLblSave"><?= h(t('save')) ?></button></div>
	</div></div>
</div>
<?php endif;
// effective rights dialog: filled by assets/js/nuuser.js from the POST action "effective" (the levels as entered, not saved yet)
$effT = ['view' => t('view'), 'add' => t('add'), 'save' => t('save'), 'delete' => t('delete'), 'clone' => t('clone'), 'print' => t('print'),
	'main' => t('eff_main'), 'expired' => t('user_expired'), 'additional' => t('xa_badge'), 'browse_only' => t('eff_browse_only'), 'edit_only' => t('eff_edit_only'),
	'none' => t('eff_none'), 'form' => t('eff_form'), 'levels' => t('eff_levels'), 'copied' => t('copy_done'), 'error' => t('err_unexpected')];
?>
<div class="modal fade" id="xaEffDlg" tabindex="-1" aria-labelledby="xaEffTitle" aria-hidden="true"
	data-url="<?= h(nu_url('user', ['id' => $id !== '' ? $id : null])) ?>" data-csrf="<?= h(nu_csrf_token()) ?>"
	data-t="<?= h((string)json_encode($effT, JSON_UNESCAPED_UNICODE)) ?>">
	<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title h5" id="xaEffTitle"><i class="bi bi-shield-check me-2"></i><?= h(t('eff_title')) ?></h2>
			<?= nu_info_tip(t('eff_hint'), 'ms-2', true, '', 'access-additional') ?>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
		</div>
		<div class="modal-body">
			<div class="d-flex flex-wrap gap-2 align-items-center mb-2" id="xaEffLevels"></div>
			<div class="d-flex flex-wrap gap-3 align-items-center mb-3">
				<input type="search" class="form-control form-control-sm w-auto" id="xaEffFind" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>">
				<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch"
					id="xaEffOnly"><label class="form-check-label small" for="xaEffOnly"><?= h(t('eff_only_extra')) ?></label></div>
			</div>
			<ul class="nav nav-tabs mb-2" role="tablist">
				<li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#xaEffForms" type="button"
					role="tab"><?= h(t('eff_forms')) ?> <span class="badge rounded-pill text-bg-light border" id="xaEffNForms">0</span></button></li>
				<li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#xaEffProcs" type="button"
					role="tab"><?= h(t('eff_procs')) ?> <span class="badge rounded-pill text-bg-light border" id="xaEffNProcs">0</span></button></li>
				<li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#xaEffReports" type="button"
					role="tab"><?= h(t('eff_reports')) ?> <span class="badge rounded-pill text-bg-light border" id="xaEffNReports">0</span></button></li>
			</ul>
			<div class="tab-content">
				<div class="tab-pane fade show active" id="xaEffForms"
					role="tabpanel"><div class="table-responsive"><table class="table table-sm nu-table align-middle mb-0 xaEffTable"><thead></thead><tbody></tbody></table></div></div>
				<div class="tab-pane fade" id="xaEffProcs" role="tabpanel"><div class="table-responsive"><table class="table table-sm nu-table align-middle mb-0 xaEffTable"><tbody></tbody></table></div></div>
				<div class="tab-pane fade" id="xaEffReports" role="tabpanel"><div class="table-responsive"><table class="table table-sm nu-table align-middle mb-0 xaEffTable"><tbody></tbody></table></div></div>
			</div>
			<div class="text-center py-4 d-none" id="xaEffBusy"><div class="spinner-border text-primary" role="status" aria-hidden="true"></div></div>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-outline-secondary me-auto" id="xaEffCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('copy')) ?></button>
			<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('close')) ?></button>
		</div>
	</div></div>
</div>
<?php if ($canSave && $mailOk): // welcome e-mail preview – filled by assets/js/nuuser.js from the JSON check of the save ?>
<div class="modal fade" id="userWelcomePvDlg" tabindex="-1" aria-labelledby="userWelcomePvTitle" aria-hidden="true" data-bs-backdrop="static"
	data-t="<?= h((string)json_encode(['replace' => t('welcome_pv_replace'), 'failed' => t('welcome_pv_failed'), 'link' => t('welcome_pv_err_link', NuWelcome::MARKER), 'marker' => NuWelcome::MARKER], JSON_UNESCAPED_UNICODE)) ?>">
	<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title h5" id="userWelcomePvTitle"><i class="bi bi-envelope-open me-2"></i><?= h(t('welcome_pv_title')) ?></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('cancel')) ?>"></button>
		</div>
		<div class="modal-body">
			<div class="row g-2 mb-2">
				<div class="col-md-6"><label class="form-label small mb-1" for="userWpvTo"><?= h(t('welcome_pv_to')) ?></label><input class="form-control" id="userWpvTo" readonly></div>
				<div class="col-md-6"><label class="form-label small mb-1" for="userWpvTpl"><?= h(t('welcome_pv_template')) ?></label><select class="form-select" id="userWpvTpl"></select></div>
				<div class="col-md-6" id="userWpvCcWrap" hidden><label class="form-label small mb-1" for="userWpvCc"><?= h(t('welcome_pv_cc')) ?></label><input class="form-control" id="userWpvCc" readonly></div>
				<div class="col-md-6" id="userWpvBccWrap" hidden><label class="form-label small mb-1"
					for="userWpvBcc"><?= h(t('welcome_pv_bcc')) ?></label><input class="form-control" id="userWpvBcc" readonly></div>
				<div class="col-12"><label class="form-label small mb-1" for="userWpvSubject"><?= h(t('welcome_pv_subject')) ?></label><input class="form-control" id="userWpvSubject" maxlength="300"></div>
			</div>
			<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
				<span class="small text-body-secondary me-auto" id="userWpvHint"><?= h(t('welcome_pv_edit_hint')) ?> <span id="userWpvLinkHint"><?= h(t('welcome_pv_link_hint', NuWelcome::MARKER)) ?></span></span>
				<div class="btn-group btn-group-sm" role="group" aria-label="<?= h(t('welcome_pv_title')) ?>">
					<input type="radio" class="btn-check" name="userWpvMode" id="userWpvModeView" value="view" checked><label class="btn btn-outline-secondary"
						for="userWpvModeView"><i class="bi bi-eye me-1"></i><?= h(t('welcome_pv_view')) ?></label>
					<input type="radio" class="btn-check" name="userWpvMode" id="userWpvModeHtml" value="html"><label class="btn btn-outline-secondary"
						for="userWpvModeHtml"><i class="bi bi-code-slash me-1"></i><?= h(t('welcome_pv_html')) ?></label>
				</div>
			</div>
			<iframe class="nuWpvFrame" id="userWpvFrame" title="<?= h(t('welcome_pv_title')) ?>"></iframe>
			<textarea class="form-control nfdMono nuWpvSource" id="userWpvSource" rows="18" hidden aria-label="<?= h(t('welcome_pv_html')) ?>" spellcheck="false"></textarea>
			<div class="invalid-feedback d-block" id="userWpvErr" aria-live="polite"></div>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal" id="userWpvCancel"><?= h(t('cancel')) ?></button>
			<button type="button" class="btn btn-primary" id="userWpvSend"><i class="bi bi-send me-1"></i><?= h(t('welcome_pv_send')) ?></button>
		</div>
	</div></div>
</div>
<?php endif; ?>
<?php if ($permFrom): // copy the permission items of another user – filled by assets/js/nuuser.js from the POST action "perm_of"
	$pcT = ['info' => t('perm_copy_info'), 'error' => t('err_unexpected')]; ?>
<div class="modal fade" id="userPermCopyDlg" tabindex="-1" aria-labelledby="userPermCopyTitle" aria-hidden="true"
	data-url="<?= h(nu_url('user', ['id' => $id !== '' ? $id : null])) ?>" data-csrf="<?= h(nu_csrf_token()) ?>"
	data-t="<?= h((string)json_encode($pcT, JSON_UNESCAPED_UNICODE)) ?>">
	<div class="modal-dialog"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title h5" id="userPermCopyTitle"><i class="bi bi-person-down me-2"></i><?= h(t('perm_copy_title')) ?></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
		</div>
		<div class="modal-body">
			<label class="form-label" for="userPermCopyFrom"><?= h(t('perm_copy_user')) ?></label>
			<select class="form-select nuListTomSelect" id="userPermCopyFrom" autocomplete="off">
				<option value=""><?= h(t('perm_copy_choose')) ?></option>
				<?php foreach ($permFrom as $pf): ?><option value="<?= h((string)$pf['id']) ?>"
					data-sub="<?= h((string)$pf['login'] . ' · ' . t('perm_copy_n', (int)$pf['n'])) ?>"><?= h((string)($pf['name'] !== '' && $pf['name'] !== null ? $pf['name'] : $pf['login'])) ?></option><?php endforeach; ?>
			</select>
			<div class="form-text" id="userPermCopyInfo" aria-live="polite"></div>
			<p class="small text-body-secondary mt-3 mb-0"><?= h(t('perm_copy_hint')) ?></p>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
			<button type="button" class="btn btn-outline-primary" id="userPermCopyAdd" disabled><i class="bi bi-plus-lg me-1"></i><?= h(t('perm_copy_add')) ?></button>
			<button type="button" class="btn btn-primary" id="userPermCopyReplace" disabled><i class="bi bi-arrow-repeat me-1"></i><?= h(t('perm_copy_replace')) ?></button>
		</div>
	</div></div>
</div>
<?php endif;
nu_layout_end('<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="'
	. nu_asset('assets/js/nulistselect.js') . '"></script><script src="' . nu_asset('assets/js/nuuser.js')
	. '"></script>' . (!$isNew ? NuRecordView::script() : ''));   // name parts; permission items when present; view mode
