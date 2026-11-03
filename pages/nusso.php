<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Single sign-on (0.8.863, core/nusso.php NuSso). One public route with three jobs:
 *   ?p=sso&go=1[&next=…]   sign-in button: off to the provider (state, nonce, PKCE in the session)
 *   oauth.php?state=sso.…  back from the provider (oauth.php routes it here): verify, find / create the user, sign in
 *   ?p=sso                 Settings → Security → Single sign-on (the globeadmin; ?p=sso&go=1&test=1 = Test sign-in without signing in)
 */

$u = NuAuth::user();
$isGa = $u && !empty($u['globeadmin']) && empty($u['demo']);

// ------------------------------------------------------------------ back from the provider
if (isset($_GET['state']) && str_starts_with((string)$_GET['state'], NuSso::STATE_PREFIX)) {
	$test = !empty($_SESSION['nunext_sso']['test']);
	$email = '';
	try {
		$r = NuSso::finish($_GET);
		$test = $r['test'];
		$res = NuSso::resolve($r['claims']);
		$email = $res['email'];
		if ($test) {
			if (!$isGa) throw new RuntimeException(t('err_forbidden'));
			$_SESSION['nunext_sso_test'] = ['at' => time(), 'res' => array_diff_key($res, ['user' => 1]) + ['login' => (string)($res['user']['sus_login_name'] ?? '')],
				'claims' => array_intersect_key($r['claims'], array_flip(['iss', 'sub', 'aud', 'email', 'email_verified', 'name', 'preferred_username', 'upn', 'tid', 'hd', NuSso::settings()['group_claim']]))];
			NuLog::write('sso_test', '', '', ['email' => $email, 'result' => $res['ok'] ? 'ok' : $res['error']]);
			nu_redirect(nu_url('sso') . '#ssoTestCard');
		}
		if (!NuSso::ready()) throw new RuntimeException(t('sso_err_off'));
		if (!$res['ok']) throw new RuntimeException(t($res['error']));
		$row = $res['create'] ? NuSso::createUser($res) : $res['user'];
		if (!$res['create'] && $res['change']) {
			NuSso::syncAccess($row, $res['access']);
			$row['sus_zzzzsys_access_id'] = $res['access'];
		}
		$err = NuAuth::loginSso($row);
		if ($err !== null) throw new RuntimeException(str_contains($err, ' ') ? $err : t($err));
		nu_redirect(NuAuth::takeNext());
	} catch (Throwable $e) {
		$msg = $e instanceof RuntimeException ? $e->getMessage() : t('err_unexpected');
		if (!$e instanceof RuntimeException) error_log('nuBuilder Next SSO: ' . $e->getMessage());
		NuLog::write('sso_refused', '', '', ['email' => $email, 'reason' => mb_substr($msg, 0, 300)] + ($test ? ['test' => 1] : []), ['id' => '', 'login' => $email]);
		if ($test && $isGa) {
			$_SESSION['nunext_sso_test'] = ['at' => time(), 'error' => $msg];
			nu_redirect(nu_url('sso') . '#ssoTestCard');
		}
		nu_flash('danger', $msg);
		nu_redirect(nu_url('login'));
	}
}

// ------------------------------------------------------------------ the sign-in button
if (isset($_GET['go'])) {
	$test = !empty($_GET['test']);
	if ($test && !$isGa) {
		http_response_code(403);
		nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true);
	}
	if (!$test && $u) nu_redirect(nu_url('home'));
	if (!($test ? NuSso::complete() : NuSso::ready())) {
		nu_flash('warning', t('sso_err_off'));
		nu_redirect(nu_url('login'));
	}
	if (!$test && isset($_GET['next'])) NuAuth::setNext((string)$_GET['next']);
	try {
		nu_redirect(NuSso::start($test));
	} catch (Throwable $e) {
		$msg = $e instanceof RuntimeException ? $e->getMessage() : t('err_unexpected');
		NuLog::write('sso_refused', '', '', ['reason' => mb_substr($msg, 0, 300)] + ($test ? ['test' => 1] : []));
		if ($test) {
			$_SESSION['nunext_sso_test'] = ['at' => time(), 'error' => $msg];
			nu_redirect(nu_url('sso') . '#ssoTestCard');
		}
		nu_flash('danger', $msg);
		nu_redirect(nu_url('login'));
	}
}

// ------------------------------------------------------------------ settings (the globeadmin)
if (!$isGa) {
	if (!$u) nu_redirect(NuAuth::loginUrl('index.php?p=sso'));
	http_response_code(403);
	nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true);
}

$levels = NuIpAccess::accessLevels();
$levelIds = array_column($levels, 'id');
$s = NuSso::settings();
$errors = [];
$form = null;

if (nu_is_post() && nu_post('action') === 'save') {
	NuSecurity::requireRecentAuth(nu_url('sso'));
	$map = [];
	$gs = is_array($_POST['map_group'] ?? null) ? $_POST['map_group'] : [];
	$as = is_array($_POST['map_access'] ?? null) ? $_POST['map_access'] : [];
	foreach ($gs as $i => $g) {
		$a = (string)($as[$i] ?? '');
		if (trim((string)$g) !== '' && in_array($a, $levelIds, true)) $map[] = ['group' => (string)$g, 'access' => $a];
	}
	$in = ['on' => nu_check('on') === '1', 'kind' => (string)nu_post('kind'), 'label' => (string)nu_post('label'), 'issuer' => (string)nu_post('issuer'),
		'client_id' => (string)nu_post('client_id'), 'client_secret' => (string)nu_post('client_secret'), 'scopes' => (string)nu_post('scopes'),
		'domains' => (string)nu_post('domains'), 'create' => nu_check('create') === '1',
		'default_access' => in_array((string)nu_post('default_access'), $levelIds, true) ? (string)nu_post('default_access') : '',
		'group_claim' => (string)nu_post('group_claim'), 'group_map' => $map, 'sync' => nu_check('sync') === '1',
		'sso_only' => array_values(array_intersect(array_map('strval', is_array($_POST['sso_only'] ?? null) ? $_POST['sso_only'] : []), $levelIds))];
	$clean = NuSso::clean($in);
	$errors = NuSso::errors($clean, $in['client_secret'] !== '' || $s['client_secret'] !== '');
	if (trim($in['issuer']) !== '' && !NuSso::validIssuer($clean['issuer'])) $errors['issuer'] = t('sso_err_issuer');
	if ($errors) {
		$form = $_POST;
		nu_flash('danger', t('err_check_form'));
	} else {
		$before = $s;
		$saved = NuSso::save($in, (string)$u['login']);
		$changed = $saved !== $before;
		if ($changed) NuLog::write('sso_settings_changed', 'zzzzsys_nunext_setting', NuSso::KEY, ['on' => $saved['on'] ? 1 : 0, 'kind' => $saved['kind'], 'issuer' => $saved['issuer'],
			'create' => $saved['create'] ? 1 : 0, 'map' => count($saved['group_map']), 'sso_only' => count($saved['sso_only']), 'secret' => $in['client_secret'] !== '' ? 'changed' : 'kept']);
		nu_flash('success', $changed ? t('sso_saved') : t('set_saved_none'));
		nu_redirect(nu_url('sso'));
	}
}

$testRes = $_SESSION['nunext_sso_test'] ?? null;
unset($_SESSION['nunext_sso_test']);
$tip = static fn(string $text): string => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = static fn(string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
$v = static function (string $k) use ($form, $s) {
	if ($form === null) return $s[$k];
	return $form[$k] ?? '';
};
$chk = static fn(string $k): bool => $form === null ? !empty($s[$k]) : !empty($form[$k]);
$map = $form === null ? $s['group_map'] : array_map(static fn($g, $a) => ['group' => (string)$g, 'access' => (string)$a], (array)($form['map_group'] ?? []), (array)($form['map_access'] ?? []));
$map = array_values(array_filter($map, static fn($m) => $m['group'] !== '' || $m['access'] !== ''));
$map[] = ['group' => '', 'access' => ''];
$only = $form === null ? $s['sso_only'] : (array)($form['sso_only'] ?? []);
$domains = $form === null ? implode(', ', $s['domains']) : (string)($form['domains'] ?? '');
$kind = (string)$v('kind');
$levelOpts = static function (string $sel, bool $empty) use ($levels): string {
	$o = $empty ? '<option value="">' . h(t('sso_level_none')) . '</option>' : '';
	foreach ($levels as $l) $o .= '<option value="' . h($l['id']) . '"' . ($sel === $l['id'] ? ' selected' : '') . '>' . h($l['code'] . ($l['name'] !== '' ? ' – ' . $l['name'] : '')) . '</option>';
	return $o;
};
$hasSecret = $s['client_secret'] !== '';
$noKey = (string)nu_cfg('nuConfigSecretKey') === '' || !function_exists('sodium_crypto_secretbox');

nu_layout_start(t('sso_title'), 'home', ['container' => 'nuContainer', 'settings' => 'security', 'settings_page' => 'sso']);
?>
<?= nu_page_header(t('sso_title'), ['icon' => 'bi-person-badge', 'sub' => t('sso_hint'), 'buttons' => '<button class="btn nuBtnAct nuBtnSave" type="submit" form="ssoForm" data-nu-save><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button>']) ?>

<form method="post" action="<?= h(nu_url('sso')) ?>" id="ssoForm" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">

	<div class="card shadow-sm mb-3" id="ssoMain">
		<div class="card-body">
			<div class="form-check form-switch mb-2">
				<input class="form-check-input" type="checkbox" role="switch" id="ssoOn" name="on" value="1"<?= $chk('on') ? ' checked' : '' ?>>
				<label class="form-check-label fw-semibold" for="ssoOn"><?= h(t('sso_on')) ?></label><?= $tip(t('sso_on_tip')) ?>
			</div>
			<div class="small mb-1"><?= h(t('sso_redirect')) ?><?= $tip(t('sso_redirect_tip')) ?></div>
			<div class="input-group input-group-sm nuSsoRedirect">
				<input class="form-control font-monospace" id="ssoRedirect" value="<?= h(NuSso::redirectUri()) ?>" readonly aria-label="<?= h(t('sso_redirect')) ?>">
				<button class="btn btn-outline-secondary" type="button" data-nu-copy="#ssoRedirect" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
			</div>
		</div>
	</div>

	<div class="card shadow-sm mb-3" id="ssoProvider">
		<div class="card-header fw-semibold"><?= h(t('sso_provider')) ?></div>
		<div class="card-body">
			<div class="row g-3">
				<div class="col-md-4">
					<label class="form-label" for="ssoKind"><?= h(t('sso_kind')) ?></label>
					<select class="form-select" id="ssoKind" name="kind">
						<?php foreach (NuSso::KINDS as $k): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?> data-issuer="<?= h(t('sso_issuer_ph_' . $k)) ?>" data-label="<?= h(t('sso_button_' . $k)) ?>"><?= h(t('sso_kind_' . $k)) ?></option><?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-8">
					<label class="form-label" for="ssoIssuer"><?= h(t('sso_issuer')) ?></label><?= $tip(t('sso_issuer_tip')) ?>
					<input class="form-control font-monospace<?= $inv('issuer') ?>" id="ssoIssuer" name="issuer" value="<?= h((string)$v('issuer')) ?>" placeholder="<?= h(t('sso_issuer_ph_' . $kind)) ?>" spellcheck="false" autocomplete="off">
					<?= $err('issuer') ?>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="ssoClientId"><?= h(t('sso_client_id')) ?></label>
					<input class="form-control font-monospace<?= $inv('client_id') ?>" id="ssoClientId" name="client_id" value="<?= h((string)$v('client_id')) ?>" spellcheck="false" autocomplete="off">
					<?= $err('client_id') ?>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="ssoSecret"><?= h(t('sso_client_secret')) ?></label><?= $tip(t('sso_secret_tip')) ?>
					<input class="form-control font-monospace<?= $inv('client_secret') ?>" type="password" id="ssoSecret" name="client_secret" value="" autocomplete="new-password"
						placeholder="<?= h($hasSecret ? t('sso_secret_kept') : '') ?>">
					<?= $err('client_secret') ?>
					<?php if ($noKey): ?><div class="form-text text-warning-emphasis" id="ssoNoKey"><?= h(t('sso_secret_plain')) ?></div><?php endif; ?>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="ssoLabel"><?= h(t('sso_label')) ?></label>
					<input class="form-control" id="ssoLabel" name="label" value="<?= h((string)$v('label')) ?>" maxlength="60" placeholder="<?= h(t('sso_button_' . $kind)) ?>">
				</div>
				<div class="col-md-6">
					<label class="form-label" for="ssoScopes"><?= h(t('sso_scopes')) ?></label><?= $tip(t('sso_scopes_tip')) ?>
					<input class="form-control font-monospace" id="ssoScopes" name="scopes" value="<?= h((string)$v('scopes')) ?>" spellcheck="false">
				</div>
			</div>
		</div>
	</div>

	<div class="card shadow-sm mb-3" id="ssoAccounts">
		<div class="card-header fw-semibold"><?= h(t('sso_accounts')) ?><?= $tip(t('sso_accounts_tip')) ?></div>
		<div class="card-body">
			<div class="row g-3">
				<div class="col-md-6">
					<label class="form-label" for="ssoDomains"><?= h(t('sso_domains')) ?></label><?= $tip(t('sso_domains_tip')) ?>
					<input class="form-control" id="ssoDomains" name="domains" value="<?= h($domains) ?>" placeholder="example.com" spellcheck="false">
				</div>
				<div class="col-md-6">
					<label class="form-label" for="ssoClaim"><?= h(t('sso_group_claim')) ?></label><?= $tip(t('sso_group_claim_tip')) ?>
					<input class="form-control font-monospace" id="ssoClaim" name="group_claim" value="<?= h((string)$v('group_claim')) ?>" spellcheck="false">
				</div>
				<div class="col-12">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="ssoCreate" name="create" value="1"<?= $chk('create') ? ' checked' : '' ?>>
						<label class="form-check-label" for="ssoCreate"><?= h(t('sso_create')) ?></label><?= $tip(t('sso_create_tip')) ?>
					</div>
				</div>
				<div class="col-md-6" id="ssoDefaultWrap">
					<label class="form-label" for="ssoDefault"><?= h(t('sso_default_access')) ?></label><?= $tip(t('sso_default_access_tip')) ?>
					<select class="form-select<?= $inv('default_access') ?>" id="ssoDefault" name="default_access"><?= $levelOpts((string)$v('default_access'), true) ?></select>
					<?= $err('default_access') ?>
				</div>
				<div class="col-12">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" id="ssoSync" name="sync" value="1"<?= $chk('sync') ? ' checked' : '' ?>>
						<label class="form-check-label" for="ssoSync"><?= h(t('sso_sync')) ?></label><?= $tip(t('sso_sync_tip')) ?>
					</div>
				</div>
			</div>
		</div>
		<div class="table-responsive">
			<table class="table nu-table align-middle mb-0" id="ssoMap">
				<thead><tr><th><?= h(t('sso_map_group')) ?></th><th><?= h(t('sso_map_access')) ?></th><th class="text-end"><span class="visually-hidden"><?= h(t('delete')) ?></span></th></tr></thead>
				<tbody>
				<?php foreach ($map as $i => $m): ?>
					<tr class="ssoMapRow">
						<td><input class="form-control form-control-sm font-monospace" name="map_group[]" value="<?= h($m['group']) ?>" aria-label="<?= h(t('sso_map_group')) ?>" spellcheck="false"></td>
						<td><select class="form-select form-select-sm" name="map_access[]" aria-label="<?= h(t('sso_map_access')) ?>"><?= $levelOpts($m['access'], true) ?></select></td>
						<td class="text-end"><button class="btn btn-sm btn-link text-danger ssoMapDel" type="button" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete')) ?>"><i class="bi bi-x-lg"></i></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="card-body pt-2"><button class="btn btn-sm nuBtnAct" type="button" id="ssoMapAdd"><i class="bi bi-plus-lg me-1"></i><?= h(t('sso_map_add')) ?></button>
			<span class="small text-body-secondary ms-2"><?= h(t('sso_map_hint')) ?></span></div>
	</div>

	<div class="card shadow-sm mb-3" id="ssoOnlyCard">
		<div class="card-header fw-semibold"><?= h(t('sso_only')) ?><?= $tip(t('sso_only_tip')) ?></div>
		<div class="card-body">
			<div class="row g-1">
			<?php foreach ($levels as $l): $fid = 'ssoOnly_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $l['id']); ?>
				<div class="col-sm-6 col-lg-4"><div class="form-check">
					<input class="form-check-input" type="checkbox" id="<?= h($fid) ?>" name="sso_only[]" value="<?= h($l['id']) ?>"<?= in_array($l['id'], $only, true) ? ' checked' : '' ?>>
					<label class="form-check-label" for="<?= h($fid) ?>"><?= h($l['code']) ?><?php if ($l['name'] !== ''): ?> <span class="small text-body-secondary"><?= h($l['name']) ?></span><?php endif; ?></label>
				</div></div>
			<?php endforeach; ?>
			</div>
			<div class="small text-body-secondary mt-2"><?= h(t('sso_only_ga')) ?></div>
			<?php if (strtolower((string)nu_cfg('nuConfigLogonMode')) === 'sso'): ?><div class="small mt-1" id="ssoForteMode"><?= h(t('sso_forte_mode')) ?></div><?php endif; ?>
		</div>
	</div>
</form>

<div class="card shadow-sm mb-3" id="ssoTestCard">
	<div class="card-header fw-semibold"><?= h(t('sso_test')) ?><?= $tip(t('sso_test_tip')) ?></div>
	<div class="card-body">
		<a class="btn nuBtnAct<?= NuSso::complete() ? '' : ' disabled' ?>" id="ssoTestBtn" href="<?= h(nu_url('sso', ['go' => '1', 'test' => '1'])) ?>"<?= NuSso::complete() ? '' : ' aria-disabled="true" tabindex="-1"' ?>><i class="bi bi-play me-1"></i><?= h(t('sso_test_run')) ?></a>
		<?php if (!NuSso::complete()): ?><span class="small text-body-secondary ms-2"><?= h(t('sso_test_need')) ?></span><?php endif; ?>
		<?php if (is_array($testRes)): ?>
			<div id="ssoTestResult" class="mt-3">
			<?php if (isset($testRes['error'])): ?>
				<div class="alert alert-danger py-2 mb-0" data-ok="0"><i class="bi bi-x-octagon me-1"></i><?= h((string)$testRes['error']) ?></div>
			<?php else: $r = $testRes['res']; ?>
				<div class="alert <?= $r['ok'] ? 'alert-success' : 'alert-warning' ?> py-2" data-ok="<?= $r['ok'] ? '1' : '0' ?>">
					<i class="bi <?= $r['ok'] ? 'bi-check-circle' : 'bi-exclamation-triangle' ?> me-1"></i>
					<?= h($r['ok'] ? ($r['create'] ? t('sso_test_create', $r['email']) : t('sso_test_user', $r['login'])) : t($r['error'])) ?>
					<?php if ($r['ok'] && $r['access'] !== ''): $lv = array_values(array_filter($levels, static fn($l) => $l['id'] === $r['access'])); ?>
						<div class="small mt-1" id="ssoTestAccess"><?= h(t('sso_test_access', $lv ? $lv[0]['code'] : $r['access'])) ?><?= $r['change'] ? ' ' . h(t('sso_test_changes')) : '' ?></div>
					<?php endif; ?>
				</div>
				<div class="small fw-semibold mb-1"><?= h(t('sso_test_claims')) ?></div>
				<pre class="small bg-body-tertiary p-2 rounded mb-0 nuSsoClaims" id="ssoTestClaims"><?= h((string)json_encode($testRes['claims'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
			<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nusso.js') . '"></script>');
