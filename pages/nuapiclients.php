<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * REST API clients (globeadmin): list · ?id=new | ?id=<client> edit · secret shown once after create / rotate.
 * Logic in core/nuapi.php (NuApiClient), JavaScript in assets/js/nuapiclients.js.
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('apiclients');
NuSecurity::requireRecentAuth(nu_url('apiclients'));   // password again when the last confirmation is older than 15 minutes (0.8.207)
NuApiClient::ensure();

$db = nu_db();
$id = (string)($_GET['id'] ?? '');
$client = $id !== '' && $id !== 'new' ? NuApiClient::get($id) : null;
if ($id !== '' && $id !== 'new' && !$client) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('apiclients')); }
$errors = [];

// ------------------------------------------------------------------ actions
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		$in = [
			'name' => nu_post('name'), 'scopes' => is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [], 'user_id' => nu_post('user_id'),
			'ip_allow' => nu_post('ip_allow'), 'rate_limit' => nu_post('rate_limit'), 'expires_on' => nu_post('expires_on'), 'active' => nu_check('active') === '1',
		];
		if ($in['user_id'] === '__new') {                                   // create the service user first
			[$uid, $ue] = NuApiClient::createServiceUser(nu_post('su_login'), nu_post('su_name') ?: $in['name'], nu_post('su_access'));
			$errors += $ue;
			$in['user_id'] = (string)$uid;
		}
		if (!$errors) {
			[$newId, $e, $cred] = NuApiClient::save($in, $client ? $id : '');
			$errors += $e;
			if (!$errors) {
				if ($cred) $_SESSION['nu_api_secret'] = ['id' => $newId] + $cred;
				nu_flash('success', t('saved'));
				nu_redirect(nu_url('apiclients', ['id' => $newId]));
			}
		}
	}
	if ($client && $action === 'rotate') {
		$secret = NuApiClient::rotate($id, nu_post('grace') === '0' ? 0 : 24);
		$_SESSION['nu_api_secret'] = ['id' => $id, 'client_id' => $client['client_id'], 'client_secret' => $secret];
		nu_redirect(nu_url('apiclients', ['id' => $id]));
	}
	if ($client && $action === 'revoke') {
		nu_flash('success', t('api_revoked', NuApiClient::revokeTokens($id)));
		nu_redirect(nu_url('apiclients', ['id' => $id]));
	}
	if ($client && $action === 'delete') {
		NuApiClient::delete($id);
		nu_flash('success', t('api_deleted', (string)$client['name']));
		nu_redirect(nu_url('apiclients'));
	}
}

$secret = $_SESSION['nu_api_secret'] ?? null;
if ($secret && (string)$secret['id'] !== $id) $secret = null;
unset($_SESSION['nu_api_secret']);                                   // shown once
$base = nu_base_url() . '/api.php/v1';

nu_layout_start(t('api_title'), 'home', ['container' => 'container']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<?php if ($id !== ''): ?><li class="breadcrumb-item"><a href="<?= h(nu_url('apiclients')) ?>"><?= h(t('api_title')) ?></a></li><?php endif; ?>
	<li class="breadcrumb-item active" aria-current="page"><?= h($id === '' ? t('api_title') : ($client ? (string)$client['name'] : t('api_new'))) ?></li>
</ol></nav>

<?php if (!nu_cfg('nuConfigApi')): ?>
	<div class="alert alert-warning small d-flex flex-wrap align-items-center gap-2">
		<span class="flex-grow-1"><i class="bi bi-plug me-1"></i><?= h(t('api_off')) ?></span>
		<?= NuConfigFile::enableButton('nuConfigApi', t('api_on'), t('api_on_confirm'), 'apiclients') ?>
	</div>
<?php endif; ?>
<?php if ((string)nu_cfg('nuConfigSecretKey') === ''): ?>
	<div class="alert alert-warning small d-flex flex-wrap align-items-center gap-2">
		<span class="flex-grow-1"><i class="bi bi-key me-1"></i><?= h(t('api_no_key')) ?></span>
		<?php if (NuConfigFile::writable() && !NuDemo::active()): ?>
			<button class="btn btn-sm btn-warning text-nowrap" type="button" data-bs-toggle="modal" data-bs-target="#nuKeyGen"><i class="bi bi-key me-1"></i><?= h(t('key_generate')) ?></button>
		<?php elseif (!NuDemo::active()): ?>
			<span class="text-body-secondary"><?= h(t('cfg_err_not_writable', NuConfigFile::path())) ?></span>
		<?php endif; ?>
	</div>
	<?php if (NuConfigFile::writable() && !NuDemo::active()):
		$nClients = (int)$db->value('SELECT COUNT(*) FROM ' . NuApiClient::CLIENT); ?>
	<div class="modal fade" id="nuKeyGen" tabindex="-1" aria-labelledby="nuKeyGenTitle" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg">
			<form class="modal-content" method="post" action="<?= h(nu_url('configfile')) ?>" id="nuKeyGenForm">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="secretkey">
				<div class="modal-header">
					<h2 class="modal-title fs-5" id="nuKeyGenTitle"><i class="bi bi-key me-2"></i><?= h(t('key_title')) ?></h2>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
				</div>
				<div class="modal-body">
					<label class="form-label" for="nuKeyValue">$nuConfigSecretKey</label>
					<div class="input-group">
						<input class="form-control font-monospace" id="nuKeyValue" name="key" required minlength="32" maxlength="128" pattern="[A-Za-z0-9_\-]{32,128}"
							autocomplete="off" spellcheck="false">
						<button class="btn btn-outline-secondary" type="button" id="nuKeyNew" title="<?= h(t('key_regenerate')) ?>" aria-label="<?= h(t('key_regenerate')) ?>"><i class="bi bi-arrow-repeat"></i></button>
						<button class="btn btn-outline-secondary" type="button" id="nuKeyCopy" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
					</div>
					<div class="form-text"><?= h(t('key_hint')) ?></div>
					<div class="alert alert-warning small mt-3 mb-0">
						<div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('key_effects')) ?></div>
						<ul class="mb-0 ps-3">
							<?php if ($nClients > 0): ?><li><?= h(t('key_effect_clients', $nClients)) ?></li><?php endif; ?>
							<li><?= h(t('key_effect_autologin')) ?></li>
							<li><?= h(t('key_effect_2fa')) ?></li>
						</ul>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
					<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= h(t('key_save')) ?></button>
				</div>
			</form>
		</div>
	</div>
	<?php endif; ?>
<?php endif; ?>

<?php if ($secret): ?>
	<div class="card border-success shadow-sm mb-4" id="apiSecret">
		<div class="card-body">
			<h2 class="h6 text-success"><i class="bi bi-shield-lock me-1"></i><?= h(t('api_secret_title')) ?></h2>
			<p class="small mb-2"><?= h(t('api_secret_once')) ?></p>
			<?php foreach (['client_id' => 'client_id', 'client_secret' => 'client_secret'] as $k => $label): ?>
				<label class="form-label small mb-0" for="api_<?= $k ?>"><code><?= $label ?></code></label>
				<div class="input-group input-group-sm mb-2">
					<input class="form-control font-monospace" id="api_<?= $k ?>" readonly value="<?= h((string)$secret[$k]) ?>">
					<button class="btn btn-outline-secondary nuApiCopy" type="button" data-copy="#api_<?= $k ?>" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button>
				</div>
			<?php endforeach; ?>
			<details class="small"><summary><?= h(t('api_example')) ?></summary>
<pre class="nuApiExample mt-2 mb-0"><code>curl -s -u '<?= h((string)$secret['client_id']) ?>:<?= h((string)$secret['client_secret']) ?>' \
  -d grant_type=client_credentials <?= h($base) ?>/token

curl -s -H 'Authorization: Bearer &lt;access_token&gt;' '<?= h($base) ?>/users?limit=10'</code></pre></details>
		</div>
	</div>
<?php endif; ?>

<?php if ($id === ''): ?>
	<?php $rows = NuApiClient::all(); ?>
	<div class="card shadow-sm nuListCard">
		<div class="card-header nuListHead nuTitleBar">
			<h1 class="h5 mb-0 fw-semibold"><?= h(t('api_title')) ?></h1>
			<div class="nuTitleSub text-body-secondary small"><?= h(t('api_hint')) ?> <code><?= h($base) ?>/</code></div>
			<div class="d-flex gap-2"><?= nu_back_button() ?>
				<a class="btn nuAddBtn" href="<?= h(nu_url('apiclients', ['id' => 'new'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></a>
				<a class="btn btn-outline-secondary" href="<?= h(nu_url('apiplayground')) ?>" id="apiPlayground"><i class="bi bi-terminal me-1"></i><?= h(t('apg_title')) ?></a>
			</div>
		</div>
		<div class="table-responsive">
			<table class="table table-hover align-middle mb-0 nu-table" id="apiClients">
				<thead><tr><th><?= h(t('api_name')) ?></th><th>client_id</th><th><?= h(t('api_user')) ?></th><th><?= h(t('api_scopes')) ?></th><th><?= h(t('api_last_used')) ?></th><th><?= h(t('status')) ?></th></tr></thead>
				<tbody>
				<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-body-secondary py-4"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
				<?php foreach ($rows as $r):
					$on = (string)$r['active'] === '1' && !($r['expires_on'] && nu_is_expired($r['expires_on'])); ?>
					<tr data-href="<?= h(nu_url('apiclients', ['id' => (string)$r['zzzzsys_nunext_api_client_id']])) ?>">
						<td class="fw-semibold"><a class="text-decoration-none" href="<?= h(nu_url('apiclients', ['id' => (string)$r['zzzzsys_nunext_api_client_id']])) ?>"><?= h((string)$r['name']) ?></a></td>
						<td><code class="small"><?= h((string)$r['client_id']) ?></code></td>
						<td class="small"><?= h((string)($r['sus_login_name'] ?? '–')) ?><?php if (!empty($r['sal_code'])): ?> <span class="badge bg-secondary-subtle text-secondary-emphasis border"><?= h((string)$r['sal_code']) ?></span><?php endif; ?></td>
						<td class="small"><?php foreach (NuApiClient::scopesOf($r) as $s): ?><span class="badge text-bg-light border me-1"><?= h($s) ?></span><?php endforeach; ?></td>
						<td class="small text-nowrap"><?= $r['last_used_at'] ? h(date('d.m.Y H:i', strtotime((string)$r['last_used_at']))) : '–' ?></td>
						<td><span class="badge <?= $on ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h(t($on ? 'api_active' : 'api_inactive')) ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php else:
	$v = static fn(string $k, string $d = '') => nu_is_post() ? (string)($_POST[$k] ?? $d) : (string)($client[$k] ?? $d);
	$scopes = nu_is_post() ? array_map('strval', (array)($_POST['scopes'] ?? [])) : ($client ? NuApiClient::scopesOf($client) : ['users:read']);
	$users = $db->all("SELECT u.zzzzsys_user_id, u.sus_login_name, u.sus_name, a.sal_code FROM zzzzsys_user u LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id
		WHERE COALESCE(u.sus_login_name, '') <> '' ORDER BY u.sus_login_name");
	$taken = array_column($db->all('SELECT user_id, name FROM ' . NuApiClient::CLIENT . ' WHERE zzzzsys_nunext_api_client_id <> ?', [$client ? $id : '']), 'name', 'user_id');
	$levels = $db->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code');
	$uSel = $v('user_id', $client ? '' : '__new');
	$active = nu_is_post() ? nu_check('active') === '1' : ($client ? (string)$client['active'] === '1' : true);
	?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-plug me-2"></i><?= h($client ? (string)$client['name'] : t('api_new')) ?></h1>
		<a class="btn nuBtnAct" href="<?= h(nu_url('apiclients')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<button class="btn nuBtnAct nuBtnSave" type="submit" form="apiForm" data-nu-save><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
	</div>
	<?php if ($errors): ?><div class="alert alert-danger small py-2"><?= h(t('err_check_form')) ?></div><?php endif; ?>
	<div class="row g-4">
		<div class="col-lg-7">
			<form method="post" action="<?= h(nu_url('apiclients', ['id' => $client ? $id : 'new'])) ?>" id="apiForm" class="card shadow-sm" novalidate data-nu-guard data-nu-focus-first>
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
				<div class="card-body">
					<div class="row g-3">
						<div class="col-sm-8"><label class="form-label" for="name"><?= h(t('api_name')) ?> <span class="text-danger">*</span></label>
							<input class="form-control<?= nu_invalid($errors, 'name') ?>" id="name" name="name" maxlength="100" required value="<?= h($v('name')) ?>"><?= nu_feedback($errors, 'name') ?></div>
						<div class="col-sm-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1"<?= $active ? ' checked' : '' ?>>
							<label class="form-check-label" for="active"><?= h(t('api_active')) ?></label></div></div>
						<?php if ($client): ?><div class="col-12"><label class="form-label small mb-0">client_id</label><div><code><?= h((string)$client['client_id']) ?></code></div></div><?php endif; ?>

						<div class="col-12"><label class="form-label" for="user_id"><?= h(t('api_user')) ?> <span class="text-danger">*</span></label>
							<select class="form-select<?= nu_invalid($errors, 'user_id') ?>" id="user_id" name="user_id">
								<option value="__new"<?= $uSel === '__new' ? ' selected' : '' ?>><?= h(t('api_user_new')) ?></option>
								<?php foreach ($users as $u): if (isset($taken[(string)$u['zzzzsys_user_id']])) continue; ?>
									<option value="<?= h((string)$u['zzzzsys_user_id']) ?>"<?= $uSel === (string)$u['zzzzsys_user_id'] ? ' selected' : '' ?>><?= h($u['sus_login_name'] . ($u['sal_code'] ? ' · ' . $u['sal_code'] : '')) ?></option>
								<?php endforeach; ?>
							</select><?= nu_feedback($errors, 'user_id') ?>
							<div class="form-text"><?= h(t('api_user_hint')) ?></div></div>
						<div class="col-12 nuApiNewUser"<?= $uSel === '__new' ? '' : ' hidden' ?>><div class="row g-2">
							<div class="col-sm-4"><label class="form-label small" for="su_login"><?= h(t('user_login_name')) ?></label>
								<input class="form-control form-control-sm<?= nu_invalid($errors, 'su_login') ?>" id="su_login" name="su_login" maxlength="50" value="<?= h((string)($_POST['su_login'] ?? 'api_')) ?>"><?= nu_feedback($errors, 'su_login') ?></div>
							<div class="col-sm-4"><label class="form-label small" for="su_name"><?= h(t('user_display_name')) ?></label>
								<input class="form-control form-control-sm" id="su_name" name="su_name" maxlength="50" value="<?= h((string)($_POST['su_name'] ?? '')) ?>"></div>
							<div class="col-sm-4"><label class="form-label small" for="su_access"><?= h(t('access_level')) ?></label>
								<select class="form-select form-select-sm<?= nu_invalid($errors, 'su_access') ?>" id="su_access" name="su_access"><option value=""></option>
									<?php foreach ($levels as $l): ?><option value="<?= h((string)$l['zzzzsys_access_id']) ?>"<?= ($_POST['su_access'] ?? '') === (string)$l['zzzzsys_access_id'] ? ' selected' : '' ?>><?= h($l['sal_code']) ?></option><?php endforeach; ?>
								</select><?= nu_feedback($errors, 'su_access') ?></div>
						</div></div>

						<div class="col-12"><span class="form-label d-block"><?= h(t('api_scopes')) ?></span>
							<?php foreach (NuApiClient::SCOPES as $s => $desc): ?>
								<div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="<?= h($s) ?>" id="sc_<?= h(str_replace(':', '_', $s)) ?>"<?= in_array($s, $scopes, true) ? ' checked' : '' ?>>
									<label class="form-check-label small" for="sc_<?= h(str_replace(':', '_', $s)) ?>"><code><?= h($s) ?></code> – <?= h(t('api_scope_' . str_replace(':', '_', $s))) ?></label></div>
							<?php endforeach; ?>
							<div class="form-text"><?= h(t('api_scopes_hint')) ?></div></div>

						<div class="col-sm-6"><label class="form-label" for="ip_allow"><?= h(t('api_ip')) ?></label>
							<textarea class="form-control form-control-sm font-monospace<?= nu_invalid($errors, 'ip_allow') ?>" id="ip_allow" name="ip_allow" rows="2" placeholder="203.0.113.10 198.51.100.0/24"><?= h($v('ip_allow')) ?></textarea><?= nu_feedback($errors, 'ip_allow') ?>
							<div class="form-text"><?= h(t('api_ip_hint')) ?></div></div>
						<div class="col-sm-3"><label class="form-label" for="rate_limit"><?= h(t('api_rate')) ?></label>
							<input class="form-control form-control-sm" type="number" min="0" max="100000" id="rate_limit" name="rate_limit" value="<?= h($v('rate_limit', '120')) ?>">
							<div class="form-text"><?= h(t('api_rate_hint')) ?></div></div>
						<div class="col-sm-3"><label class="form-label" for="expires_on"><?= h(t('user_expires')) ?></label>
							<input class="form-control form-control-sm<?= nu_invalid($errors, 'expires_on') ?>" type="date" id="expires_on" name="expires_on" value="<?= h(nu_is_post() ? $v('expires_on') : nu_date_only($client['expires_on'] ?? '')) ?>"><?= nu_feedback($errors, 'expires_on') ?></div>
					</div>
				</div>
			</form>
		</div>
		<?php if ($client): ?>
			<div class="col-lg-5">
				<div class="card shadow-sm mb-3">
					<div class="card-body small">
						<dl class="row mb-0">
							<dt class="col-5"><?= h(t('api_created')) ?></dt><dd class="col-7"><?= h(date('d.m.Y H:i', strtotime((string)$client['created_at']))) ?> · <?= h((string)$client['created_by']) ?></dd>
							<dt class="col-5"><?= h(t('api_last_used')) ?></dt><dd class="col-7"><?= $client['last_used_at'] ? h(date('d.m.Y H:i', strtotime((string)$client['last_used_at']))) : '–' ?></dd>
							<dt class="col-5"><?= h(t('api_tokens')) ?></dt><dd class="col-7"><?= (int)$db->value('SELECT COUNT(*) FROM ' . NuApiClient::TOKEN . ' WHERE client_ref = ? AND expires_at > ?', [$id, date('Y-m-d H:i:s')]) ?></dd>
							<?php if ($client['secret_old_until'] && strtotime((string)$client['secret_old_until']) > time()): ?>
								<dt class="col-5"><?= h(t('api_old_secret')) ?></dt><dd class="col-7"><?= h(t('api_old_until', date('d.m.Y H:i', strtotime((string)$client['secret_old_until'])))) ?></dd>
							<?php endif; ?>
						</dl>
					</div>
				</div>
				<div class="card shadow-sm">
					<div class="card-body d-grid gap-2">
						<form method="post" action="<?= h(nu_url('apiclients', ['id' => $id])) ?>" class="d-flex gap-2 align-items-center" data-confirm="<?= h(t('api_rotate_confirm')) ?>" data-confirm-ok="<?= h(t('api_rotate')) ?>" data-confirm-class="btn btn-warning">
							<?= nu_csrf_field() ?><input type="hidden" name="action" value="rotate">
							<select class="form-select form-select-sm w-auto" name="grace" aria-label="<?= h(t('api_grace')) ?>"><option value="24"><?= h(t('api_grace_24')) ?></option><option value="0"><?= h(t('api_grace_0')) ?></option></select>
							<button class="btn btn-sm btn-outline-warning flex-grow-1" type="submit"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('api_rotate')) ?></button>
						</form>
						<form method="post" action="<?= h(nu_url('apiclients', ['id' => $id])) ?>" data-confirm="<?= h(t('api_revoke_confirm')) ?>" data-confirm-ok="<?= h(t('api_revoke')) ?>" data-confirm-class="btn btn-warning">
							<?= nu_csrf_field() ?><input type="hidden" name="action" value="revoke">
							<button class="btn btn-sm btn-outline-secondary w-100" type="submit"><i class="bi bi-x-octagon me-1"></i><?= h(t('api_revoke')) ?></button>
						</form>
						<a class="btn btn-sm btn-outline-secondary" href="<?= h(nu_url('apiplayground')) ?>"><i class="bi bi-terminal me-1"></i><?= h(t('apg_title')) ?></a>
						<a class="btn btn-sm btn-outline-secondary" href="<?= h(nu_url('log', ['q' => (string)($db->value('SELECT sus_login_name FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [(string)$client['user_id']]) ?? $client['name'])])) ?>"><i class="bi bi-journal-text me-1"></i><?= h(t('api_log')) ?></a>
						<form method="post" action="<?= h(nu_url('apiclients', ['id' => $id])) ?>" data-confirm="<?= h(t('api_delete_confirm', (string)$client['name'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
							<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
							<button class="btn btn-sm btn-outline-danger w-100" type="submit"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
						</form>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuapiclients.js') . '"></script>');
