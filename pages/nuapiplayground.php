<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * REST API playground (globeadmin): send requests to api.php/v1 as an API client, see status, headers and body,
 * save requests for later (zzzzsys_nunext_api_request, shared by the administrators) and keep client, scope and
 * variables per login. Requests go from the browser straight to api.php – exactly as a real client sends them.
 * POST (JSON, X-CSRF-Token): action token | save | delete | settings. JavaScript: assets/js/nuapiplayground.js.
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('apiclients');
NuApiPlayground::ensure();
$login = (string)(NuAuth::user()['login'] ?? 'globeadmin');

// ------------------------------------------------------------------ JSON actions
if (nu_is_post()) {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	$in = json_decode((string)file_get_contents('php://input'), true);
	$in = is_array($in) ? $in : [];
	$out = ['ok' => false];
	switch ((string)($in['action'] ?? '')) {
		case 'token':
			[$res, $err] = NuApiClient::issueFor((string)($in['client_ref'] ?? ''), (string)($in['scope'] ?? ''), $login);
			$out = $res ? ['ok' => true, 'token' => $res] : ['ok' => false, 'error' => t('apg_err_token', (string)$err)];
			break;
		case 'save':
			[$req, $errors] = NuApiPlayground::save((array)($in['request'] ?? []), $login);
			$out = $req ? ['ok' => true, 'request' => $req, 'requests' => NuApiPlayground::requests()] : ['ok' => false, 'errors' => $errors, 'error' => t('err_check_form')];
			break;
		case 'delete':
			$out = NuApiPlayground::delete((string)($in['id'] ?? '')) ? ['ok' => true, 'requests' => NuApiPlayground::requests()] : ['ok' => false, 'error' => t('record_not_found')];
			break;
		case 'settings':
			$out = ['ok' => true, 'settings' => NuApiPlayground::saveSettings((array)($in['settings'] ?? []), $login)];
			break;
		default:
			http_response_code(400);
			$out = ['ok' => false, 'error' => 'Unknown action'];
	}
	echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

// ------------------------------------------------------------------ page
$clients = array_map(static fn(array $c) => [
	'ref' => (string)$c['zzzzsys_nunext_api_client_id'], 'name' => (string)$c['name'], 'client_id' => (string)$c['client_id'],
	'scopes' => NuApiClient::scopesOf($c), 'user' => (string)($c['sus_login_name'] ?? ''), 'level' => (string)($c['sal_code'] ?? ''),
	'active' => (string)$c['active'] === '1' && !($c['expires_on'] && nu_is_expired($c['expires_on'])), 'ip' => trim((string)($c['ip_allow'] ?? '')),
], NuApiClient::all());

$J = static fn(array $a) => json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$endpoints = [                                   // [method, path, scope, label key, sample body]
	['GET', '/v1/me', '', 'apg_ep_me', null],
	['GET', '/v1/users?limit=20&state=active', 'users:read', 'apg_ep_users', null],
	['GET', '/v1/users/{{last_id}}', 'users:read', 'apg_ep_user', null],
	['POST', '/v1/users', 'users:write', 'apg_ep_user_create', $J(['login' => 'jdoe', 'name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'access_level_id' => '{{access_level_id}}', 'change_password' => true])],
	['PATCH', '/v1/users/{{last_id}}', 'users:write', 'apg_ep_user_patch', $J(['position' => 'Sales', 'expires_on' => null])],
	['DELETE', '/v1/users/{{last_id}}', 'users:write', 'apg_ep_user_delete', null],
	['POST', '/v1/users/{{last_id}}/password', 'users:password', 'apg_ep_user_password', $J(['welcome' => true])],
	['POST', '/v1/users/{{last_id}}/login-link', 'users:login_link', 'apg_ep_user_login_link', $J(['minutes' => 1440, 'email' => false])],
	['GET', '/v1/reports', 'reports:run', 'apg_ep_reports', null],
	['POST', '/v1/reports/{{report}}/run', 'reports:run', 'apg_ep_report_run', $J(['params' => new stdClass(), 'filename' => 'report.pdf', 'expires_minutes' => 15, 'max_downloads' => 1])],
	['POST', '/v1/reports/{{report}}/run', 'reports:run', 'apg_ep_report_async', $J(['params' => new stdClass(), 'async' => true])],
	['GET', '/v1/reports/jobs/{{last_id}}', 'reports:run', 'apg_ep_report_job', null],
	['GET', '/v1/users/{{last_id}}/permissions', 'permissions:read', 'apg_ep_user_perms', null],
	['PUT', '/v1/users/{{last_id}}/permissions', 'permissions:write', 'apg_ep_user_perms_put', $J(['items' => []])],
	['GET', '/v1/access-levels', 'access:read', 'apg_ep_access', null],
	['GET', '/v1/access-levels/{{last_id}}', 'access:read', 'apg_ep_access_one', null],
	['POST', '/v1/access-levels', 'access:write', 'apg_ep_access_create', $J(['code' => 'SALES', 'description' => 'Sales team', 'forms' => []])],
	['PATCH', '/v1/access-levels/{{last_id}}', 'access:write', 'apg_ep_access_patch', $J(['description' => 'Sales and marketing'])],
	['DELETE', '/v1/access-levels/{{last_id}}', 'access:write', 'apg_ep_access_delete', null],
	['GET', '/v1/access-levels/{{last_id}}/forms', 'access:read', 'apg_ep_access_forms', null],
	['PUT', '/v1/access-levels/{{last_id}}/forms', 'access:write', 'apg_ep_access_forms_put', $J(['forms' => [['form_id' => 'nuuser', 'add' => false, 'save' => true, 'delete' => false, 'clone' => false, 'new' => false, 'print' => true]]])],
	['GET', '/v1/permission-items', 'permissions:read', 'apg_ep_perms', null],
	['POST', '/v1/permission-items', 'permissions:write', 'apg_ep_perm_create', $J(['ident' => 'sales_export', 'group' => 'Sales', 'description' => 'Export sales data'])],
	['PATCH', '/v1/permission-items/{{last_id}}', 'permissions:write', 'apg_ep_perm_patch', $J(['description' => 'Export sales and order data'])],
	['DELETE', '/v1/permission-items/{{last_id}}', 'permissions:write', 'apg_ep_perm_delete', null],
	['POST', '/v1/system/update', 'system:update', 'apg_ep_update', $J(['url' => 'https://example.com/nunext.zip', 'dry_run' => true, 'backup' => 'auto', 'schema' => 'safe', 'sample' => 'auto'])],
	['GET', '/v1/system/update', 'system:update', 'apg_ep_update_jobs', null],
	['GET', '/v1/system/update/{{last_id}}', 'system:update', 'apg_ep_update_job', null],
];
$data = [
	'csrf' => nu_csrf_token(), 'url' => nu_url('apiplayground'), 'api' => 'api.php', 'base' => nu_base_url() . '/api.php',
	'clients' => $clients, 'requests' => NuApiPlayground::requests(), 'settings' => NuApiPlayground::settings($login),
	'endpoints' => array_map(static fn($e) => ['method' => $e[0], 'path' => $e[1], 'scope' => $e[2], 'label' => t($e[3]), 'body' => $e[4]], $endpoints),
	't' => array_combine($k = ['apg_token_none', 'apg_token_until', 'apg_token_expired', 'apg_sending', 'apg_saved', 'apg_deleted', 'apg_delete_confirm',
		'apg_no_saved', 'apg_json_invalid', 'apg_copied', 'apg_var_missing', 'apg_net_error', 'apg_session', 'apg_no_folder', 'apg_need_token', 'apg_ip_hint',
		'apg_inactive', 'apg_var_auto', 'apg_header_name', 'apg_header_value', 'apg_new', 'remove', 'delete', 'apg_vars_saved'], array_map('t', $k)),
];
$m = static fn(string $meth) => ['GET' => 'text-bg-success', 'POST' => 'text-bg-primary', 'PUT' => 'text-bg-warning', 'PATCH' => 'text-bg-info', 'DELETE' => 'text-bg-danger'][$meth] ?? 'text-bg-secondary';

nu_layout_start(t('apg_title'), 'home', ['container' => 'container-fluid', 'body_class' => 'nuApgPage']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item"><a href="<?= h(nu_url('apiclients')) ?>"><?= h(t('api_title')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h(t('apg_title')) ?></li>
</ol></nav>

<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-terminal me-2"></i><?= h(t('apg_title')) ?></h1>
	<div class="nuTitleSub text-body-secondary small"><?= h(t('apg_hint')) ?></div>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?></div>
</div>
<?php if (!nu_cfg('nuConfigApi')): ?>
	<div class="alert alert-warning small d-flex flex-wrap align-items-center gap-2">
		<span class="flex-grow-1"><i class="bi bi-plug me-1"></i><?= h(t('api_off')) ?></span>
		<?= NuConfigFile::enableButton('nuConfigApi', t('api_on'), t('api_on_confirm'), 'apiplayground') ?>
	</div>
<?php endif; ?>

<div class="row g-3" id="apg">
	<!-- ============================================================ saved requests / endpoints -->
	<div class="col-xl-3 col-lg-4">
		<div class="card shadow-sm apgSide">
			<div class="card-header p-2">
				<ul class="nav nav-tabs card-header-tabs small" role="tablist">
					<li class="nav-item" role="presentation"><button class="nav-link active" id="apgTabSaved" data-bs-toggle="tab" data-bs-target="#apgSaved" type="button" role="tab" aria-controls="apgSaved" aria-selected="true"><i class="bi bi-bookmark me-1"></i><?= h(t('apg_saved_tab')) ?></button></li>
					<li class="nav-item" role="presentation"><button class="nav-link" id="apgTabEp" data-bs-toggle="tab" data-bs-target="#apgEndpoints" type="button" role="tab" aria-controls="apgEndpoints" aria-selected="false"><i class="bi bi-signpost-split me-1"></i><?= h(t('apg_endpoints_tab')) ?></button></li>
				</ul>
			</div>
			<div class="tab-content">
				<div class="tab-pane fade show active" id="apgSaved" role="tabpanel" aria-labelledby="apgTabSaved">
					<div class="p-2 border-bottom d-flex gap-2">
						<input class="form-control form-control-sm" type="search" id="apgFind" placeholder="<?= h(t('search_ph')) ?>" aria-label="<?= h(t('search')) ?>">
						<button class="btn btn-sm btn-outline-primary text-nowrap" type="button" id="apgNew" title="<?= h(t('apg_new')) ?>"><i class="bi bi-plus-lg"></i><span class="visually-hidden"><?= h(t('apg_new')) ?></span></button>
					</div>
					<div class="list-group list-group-flush small apgList" id="apgSavedList"></div>
				</div>
				<div class="tab-pane fade" id="apgEndpoints" role="tabpanel" aria-labelledby="apgTabEp">
					<div class="p-2 border-bottom small text-body-secondary"><?= h(t('apg_endpoints_hint')) ?></div>
					<div class="list-group list-group-flush small apgList">
						<?php foreach ($data['endpoints'] as $i => $e): ?>
							<button type="button" class="list-group-item list-group-item-action apgEp" data-ep="<?= $i ?>">
								<span class="badge <?= $m($e['method']) ?> apgMeth"><?= h($e['method']) ?></span>
								<span class="font-monospace apgPath"><?= h($e['path']) ?></span>
								<span class="d-block text-body-secondary"><?= h($e['label']) ?><?php if ($e['scope']): ?> · <code><?= h($e['scope']) ?></code><?php endif; ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================================================ connection, request, response -->
	<div class="col-xl-9 col-lg-8">
		<div class="card shadow-sm mb-3">
			<div class="card-body py-2">
				<div class="row g-2 align-items-end">
					<div class="col-md-4"><label class="form-label small mb-0" for="apgClient"><?= h(t('apg_client')) ?></label>
						<select class="form-select form-select-sm" id="apgClient">
							<?php if (!$clients): ?><option value=""><?= h(t('apg_no_clients')) ?></option><?php endif; ?>
							<?php foreach ($clients as $c): ?><option value="<?= h($c['ref']) ?>"><?= h($c['name'] . ($c['user'] !== '' ? ' · ' . $c['user'] : '') . ($c['active'] ? '' : ' (' . t('api_inactive') . ')')) ?></option><?php endforeach; ?>
						</select></div>
					<div class="col-md-4"><label class="form-label small mb-0" for="apgScope"><?= h(t('apg_scope')) ?></label>
						<input class="form-control form-control-sm font-monospace" id="apgScope" placeholder="<?= h(t('apg_scope_ph')) ?>" list="apgScopes" autocomplete="off">
						<datalist id="apgScopes"></datalist></div>
					<div class="col-md-4 d-flex gap-2 align-items-end">
						<button class="btn btn-sm btn-outline-primary flex-grow-1" type="button" id="apgToken"<?= $clients ? '' : ' disabled' ?>><i class="bi bi-key me-1"></i><?= h(t('apg_get_token')) ?></button>
						<button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#apgMore" aria-expanded="false" aria-controls="apgMore" title="<?= h(t('apg_more')) ?>"><i class="bi bi-sliders"></i><span class="visually-hidden"><?= h(t('apg_more')) ?></span></button>
					</div>
				</div>
				<div class="small mt-2 d-flex flex-wrap gap-2 align-items-center">
					<span id="apgTokenState" class="badge text-bg-light border"><?= h(t('apg_token_none')) ?></span>
					<span id="apgClientInfo" class="text-body-secondary"></span>
				</div>
				<div class="collapse" id="apgMore">
					<div class="row g-3 pt-3">
						<div class="col-md-6">
							<label class="form-label small mb-0" for="apgSecret"><?= h(t('apg_secret')) ?></label>
							<div class="input-group input-group-sm">
								<input class="form-control font-monospace" type="password" id="apgSecret" autocomplete="off" placeholder="ns_…">
								<button class="btn btn-outline-secondary" type="button" id="apgTokenSecret"><?= h(t('apg_token_secret')) ?></button>
							</div>
							<div class="form-text"><?= h(t('apg_secret_hint')) ?></div>
							<div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="apgAutoEtag">
								<label class="form-check-label small" for="apgAutoEtag"><?= h(t('apg_auto_etag')) ?></label></div>
						</div>
						<div class="col-md-6">
							<label class="form-label small mb-0" for="apgVars"><?= h(t('apg_vars')) ?></label>
							<textarea class="form-control form-control-sm font-monospace" id="apgVars" rows="3" placeholder="access_level_id=…&#10;user_id=…" spellcheck="false"></textarea>
							<div class="form-text"><?= h(t('apg_vars_hint')) ?> <span id="apgAutoVars" class="font-monospace"></span></div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<form class="card shadow-sm mb-3" id="apgForm" novalidate>
			<div class="card-body">
				<div class="row g-2 mb-2">
					<div class="col-sm-5"><label class="form-label small mb-0" for="apgName"><?= h(t('name')) ?></label>
						<input class="form-control form-control-sm" id="apgName" maxlength="100" placeholder="<?= h(t('apg_name_ph')) ?>"><div class="invalid-feedback" data-for="name"></div></div>
					<div class="col-sm-3"><label class="form-label small mb-0" for="apgFolder"><?= h(t('apg_folder')) ?></label>
						<input class="form-control form-control-sm" id="apgFolder" maxlength="100" list="apgFolders" autocomplete="off"><datalist id="apgFolders"></datalist></div>
					<div class="col-sm-4 d-flex gap-1 align-items-end justify-content-end">
						<button class="btn btn-sm btn-outline-primary" type="button" id="apgSave"><i class="bi bi-bookmark-check me-1"></i><?= h(t('save')) ?></button>
						<button class="btn btn-sm btn-outline-secondary" type="button" id="apgSaveNew" title="<?= h(t('apg_save_new')) ?>"><i class="bi bi-files"></i><span class="visually-hidden"><?= h(t('apg_save_new')) ?></span></button>
						<button class="btn btn-sm btn-outline-danger" type="button" id="apgDelete" title="<?= h(t('delete')) ?>" disabled><i class="bi bi-trash"></i><span class="visually-hidden"><?= h(t('delete')) ?></span></button>
					</div>
				</div>
				<div class="input-group">
					<select class="form-select flex-grow-0 w-auto fw-semibold font-monospace" id="apgMethod" aria-label="<?= h(t('apg_method')) ?>">
						<?php foreach (NuApiPlayground::METHODS as $meth): ?><option><?= $meth ?></option><?php endforeach; ?>
					</select>
					<span class="input-group-text font-monospace small d-none d-md-flex">api.php</span>
					<input class="form-control font-monospace" id="apgPath" value="/v1/me" spellcheck="false" autocomplete="off" aria-label="<?= h(t('apg_path')) ?>">
					<button class="btn btn-primary" type="submit" id="apgSend" title="Ctrl+Enter"><i class="bi bi-send me-1"></i><?= h(t('apg_send')) ?></button>
				</div>
				<div class="invalid-feedback d-block small" data-for="path"></div>

				<ul class="nav nav-underline small mt-2" role="tablist">
					<li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#apgBodyPane" type="button" role="tab" aria-controls="apgBodyPane" aria-selected="true" id="apgBodyTab"><?= h(t('apg_body')) ?></button></li>
					<li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#apgHeadPane" type="button" role="tab" aria-controls="apgHeadPane" aria-selected="false" id="apgHeadTab"><?= h(t('apg_headers')) ?> <span class="badge text-bg-secondary" id="apgHeadCount">0</span></button></li>
				</ul>
				<div class="tab-content pt-2">
					<div class="tab-pane fade show active" id="apgBodyPane" role="tabpanel" aria-labelledby="apgBodyTab">
						<textarea class="form-control form-control-sm font-monospace apgCode" id="apgBody" rows="8" spellcheck="false" placeholder="{ }" aria-label="<?= h(t('apg_body')) ?>"></textarea>
						<div class="d-flex flex-wrap gap-2 mt-1 small align-items-center">
							<button class="btn btn-sm btn-link p-0" type="button" id="apgFormat"><i class="bi bi-braces me-1"></i><?= h(t('apg_format')) ?></button>
							<span id="apgJsonState" class="text-danger"></span>
						</div>
					</div>
					<div class="tab-pane fade" id="apgHeadPane" role="tabpanel" aria-labelledby="apgHeadTab">
						<div id="apgHeaders"></div>
						<button class="btn btn-sm btn-link p-0" type="button" id="apgAddHeader"><i class="bi bi-plus-lg me-1"></i><?= h(t('apg_add_header')) ?></button>
						<div class="form-text"><?= h(t('apg_headers_hint')) ?></div>
					</div>
				</div>
			</div>
		</form>

		<div class="card shadow-sm" id="apgResult">
			<div class="card-header d-flex flex-wrap gap-2 align-items-center py-2">
				<span class="fw-semibold me-1"><?= h(t('apg_response')) ?></span>
				<span id="apgStatus" class="badge text-bg-light border">–</span>
				<span id="apgTime" class="small text-body-secondary"></span>
				<div class="ms-auto d-flex gap-1">
					<button class="btn btn-sm btn-outline-secondary" type="button" id="apgCurl"><i class="bi bi-terminal me-1"></i><?= h(t('apg_curl')) ?></button>
					<button class="btn btn-sm btn-outline-secondary" type="button" id="apgCopyBody" title="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i><span class="visually-hidden"><?= h(t('copy')) ?></span></button>
				</div>
			</div>
			<div class="card-body">
				<ul class="nav nav-underline small mb-2" role="tablist">
					<li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#apgResBodyPane" type="button" role="tab" aria-controls="apgResBodyPane" aria-selected="true" id="apgResBodyTab"><?= h(t('apg_body')) ?></button></li>
					<li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#apgResHeadPane" type="button" role="tab" aria-controls="apgResHeadPane" aria-selected="false" id="apgResHeadTab"><?= h(t('apg_headers')) ?></button></li>
				</ul>
				<div class="tab-content">
					<div class="tab-pane fade show active" id="apgResBodyPane" role="tabpanel" aria-labelledby="apgResBodyTab"><pre class="apgOut mb-0" id="apgResBody" tabindex="0"><span class="text-body-secondary"><?= h(t('apg_response_hint')) ?></span></pre></div>
					<div class="tab-pane fade" id="apgResHeadPane" role="tabpanel" aria-labelledby="apgResHeadTab"><pre class="apgOut mb-0" id="apgResHead" tabindex="0"></pre></div>
				</div>
				<pre class="apgOut mt-2 mb-0" id="apgCurlOut" hidden></pre>
			</div>
		</div>
	</div>
</div>
<?php
nu_layout_end('<script type="application/json" id="apgData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'
	. '<script src="' . nu_asset('assets/js/nuapiplayground.js') . '"></script>');
