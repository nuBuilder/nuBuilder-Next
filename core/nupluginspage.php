<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * The work of pages/nuplugins.php (Update → Plugins, globeadmin only): its POST actions and the HTML of the tab on the update page.
 * Every action asks for the password again after 15 minutes (requireRecentAuth) and goes back to the tab.
 */
final class NuPluginsPage
{
	/** The POST actions – every one ends with a redirect to the tab (or the plugin's settings page). */
	public static function post(array $nuIn): array
	{
		if (!nu_is_post()) return $nuIn;
		$back = nu_url('update', ['tab' => 'plugins']);
		$a = (string)nu_post('action');
		$id = (string)nu_post('id');
		if ($a === 'settings') NuSecurity::requireRecentAuth(nu_url('plugins', ['settings' => $id]));
		else NuSecurity::requireRecentAuth($back);
		switch ($a) {
			case 'enable':
				$err = NuPlugin::enable($id);
				nu_flash($err ? 'danger' : 'success', $err ?? t('plg_enabled_msg', $id));
				break;
			case 'disable':
				NuPlugin::disable($id);
				nu_flash('success', t('plg_disabled_msg', $id));
				break;
			case 'remove':
				$err = NuPlugin::remove($id, nu_check('data') === '1');
				nu_flash($err ? 'danger' : 'success', $err ?? t('plg_removed_msg', $id));
				break;
			case 'upload':
				self::upload();
				break;
			case 'install':
				$up = $_SESSION[NuSess::PLUGIN_UPLOAD] ?? null;
				unset($_SESSION[NuSess::PLUGIN_UPLOAD]);
				$f = is_array($up) ? self::uploadFile((string)$up['token']) : null;
				if ($f === null || !is_file($f) || !NuPlugin::state()['upload']) { nu_flash('danger', t('plg_err_zip')); break; }
				$err = NuPlugin::installZip($f);
				@unlink($f);
				nu_flash($err ? 'danger' : 'success', $err ?? t('plg_installed_msg', (string)$up['id']));
				break;
			case 'cancel':
				$up = $_SESSION[NuSess::PLUGIN_UPLOAD] ?? null;
				unset($_SESSION[NuSess::PLUGIN_UPLOAD]);
				if (is_array($up) && ($f = self::uploadFile((string)$up['token'])) !== null) @unlink($f);
				break;
			case 'upload_set':
				NuPlugin::setUpload(nu_check('upload') === '1');
				nu_flash('success', t('plg_saved'));
				break;
			case 'key_add':
				$err = NuPlugin::addKey((string)nu_post('key_name'), (string)nu_post('key_value'));
				nu_flash($err ? 'danger' : 'success', $err ?? t('plg_saved'));
				break;
			case 'key_remove':
				NuPlugin::removeKey((int)nu_post('key'));
				nu_flash('success', t('plg_saved'));
				break;
			case 'settings':
				$api = NuPlugin::get($id);
				$def = $api?->settingsDef();
				if (!$api || !$def) { nu_flash('danger', t('err_not_found')); break; }
				$err = NuPlugin::call($id, $def[1], [is_array($_POST['plg'] ?? null) ? $_POST['plg'] : []]);
				if ($err === false && !NuPlugin::get($id)) { nu_flash('danger', t('plg_failed_msg', $api->name(), '')); break; }
				if (is_string($err) && $err !== '') { nu_flash('danger', $err); nu_redirect(nu_url('plugins', ['settings' => $id])); }
				NuLog::write('plugin_settings', 'plugin', $id);
				nu_flash('success', t('plg_saved'));
				nu_redirect(nu_url('plugins', ['settings' => $id]));
		}
		nu_redirect($back);
	}

	/** Upload of a package: kept in _update/ until Install / Cancel; the check is shown on the tab. */
	private static function upload(): void
	{
		if (!NuPlugin::state()['upload']) { nu_flash('warning', t('plg_upload_off')); return; }
		$f = $_FILES['plugin_zip'] ?? null;
		if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) { nu_flash('danger', t('plg_err_zip')); return; }
		if ((int)$f['size'] > NuPlugin::MAX_ZIP) { nu_flash('danger', t('plg_err_zip_size', NuFile::size(NuPlugin::MAX_ZIP))); return; }
		NuSecurity::protectDir(NU_ROOT . '/_update');
		$token = bin2hex(random_bytes(12));
		$to = self::uploadFile($token);
		if ($to === null || !move_uploaded_file((string)$f['tmp_name'], $to)) { nu_flash('danger', t('plg_err_write')); return; }
		$c = NuPlugin::checkZip($to);
		if (!$c['ok']) { @unlink($to); nu_flash('danger', $c['error']); return; }
		$_SESSION[NuSess::PLUGIN_UPLOAD] = ['token' => $token, 'id' => $c['manifest']['id'], 'name' => (string)$f['name']];
	}

	private static function uploadFile(string $token): ?string
	{
		return preg_match('/^[0-9a-f]{24}$/', $token) ? NU_ROOT . '/_update/plugin-' . $token . '.zip' : null;
	}

	// ================================================================== the tab

	/** Badge of a signature state. */
	private static function sigBadge(array $sig): string
	{
		[$cls, $icon] = match ($sig['state']) {
			'official', 'trusted' => ['text-bg-success', 'bi-patch-check'],
			'unsigned', 'unchecked', 'unknown' => ['text-bg-warning', 'bi-exclamation-triangle'],
			default => ['text-bg-danger', 'bi-x-octagon'],
		};
		$text = $sig['state'] === 'trusted' ? t('plg_sig_trusted', $sig['by']) : t('plg_sig_' . $sig['state']);
		return '<span class="badge ' . $cls . ' nuPlgSig" data-sig="' . h($sig['state']) . '"><i class="bi ' . $icon . ' me-1" aria-hidden="true"></i>' . h($text) . '</span>';
	}

	/** One action as a small POST form. */
	private static function action(string $action, string $id, string $label, string $cls, string $icon, string $confirm = '', string $extra = ''): string
	{
		return '<form method="post" action="' . h(nu_url('plugins')) . '" class="d-inline"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"'
			. ' data-confirm-ok="' . h($label) . '"' . (str_contains($cls, 'danger') ? ' data-confirm-class="btn btn-danger"' : '') : '') . '>' . nu_csrf_field()
			. '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="id" value="' . h($id) . '">'
			. '<button type="submit" class="btn btn-sm ' . $cls . '" data-plg-act="' . h($action) . '"><i class="bi ' . $icon . ' me-1" aria-hidden="true"></i>'
			. h($label) . '</button>' . $extra . '</form>';
	}

	/** HTML of the tab Plugins on the update page. */
	public static function tab(): string
	{
		$st = NuPlugin::state();
		$o = '<div class="d-flex align-items-center gap-2 mb-3"><h2 class="h5 mb-0"><i class="bi bi-puzzle me-1" aria-hidden="true"></i>' . h(t('plg_title')) . '</h2>'
			. nu_info_tip(t('plg_hint'), 'ms-1', true, '', 'plugins-install') . '</div>';
		if (NuPlugin::safe()) $o .= '<div class="alert alert-info small" id="plgSafe">' . h(t('plg_safe_note')) . '</div>';
		$o .= self::checkCard($st);
		$all = NuPlugin::installed();
		$o .= '<div class="card shadow-sm mb-4"><div class="card-body p-0">';
		if (!$all) {
			$o .= '<p class="text-body-secondary small p-3 mb-0" id="plgNone">' . h(t('plg_none')) . '</p>';
		} else {
			$o .= '<div class="table-responsive"><table class="table nu-table align-middle mb-0" id="plgTable" data-nu-phcards><thead><tr><th>' . h(t('plg_col_name')) . '</th><th>'
				. h(t('plg_col_version')) . '</th><th>' . h(t('plg_col_status')) . '</th><th>' . h(t('plg_col_sig')) . '</th><th><span class="visually-hidden">'
				. h(t('actions')) . '</span></th></tr></thead><tbody>';
			foreach ($all as $id => $p) $o .= self::row($id, $p, $st);
			$o .= '</tbody></table></div>';
		}
		$o .= '</div></div>';
		$o .= self::uploadCard($st) . self::keysCard($st);
		return $o;
	}

	private static function row(string $id, array $p, array $st): string
	{
		$m = $p['manifest'];
		if ($m === null) {
			return '<tr data-plugin="' . h($id) . '"><td><code>' . h($id) . '</code></td><td></td><td><span class="badge text-bg-danger">' . h(t('plg_broken')) . '</span>'
				. '<div class="small text-danger">' . h($p['error']) . '</div></td><td></td><td class="text-end">'
				. self::action('remove', $id, t('plg_remove'), 'btn-outline-danger', 'bi-trash', t('plg_remove_confirm', $id)) . '</td></tr>';
		}
		$on = isset($st['on'][$id]);
		$failed = $st['failed'][$id] ?? null;
		$needs = NuPlugin::requirements($m);
		$sig = NuPlugin::signature($m, $p['dir']);
		$status = $on ? '<span class="badge text-bg-success" data-status="on">' . h(t('plg_on')) . '</span>'
			: ($failed ? '<span class="badge text-bg-danger" data-status="failed">' . h(t('plg_failed')) . '</span>'
			. '<div class="small text-danger mt-1 nuPlgErr">' . h((string)$failed['error']) . ' · ' . h(date(nu_dfmt('d.m.Y H:i'), strtotime((string)$failed['at']) ?: time())) . '</div>'
			: '<span class="badge text-bg-secondary" data-status="off">' . h(t('plg_off')) . '</span>');
		if ($needs) $status .= '<div class="small text-warning-emphasis mt-1 nuPlgNeeds">' . h(t('plg_needs', implode(', ', $needs))) . '</div>';
		$info = [];
		if ($m['author'] !== '') $info[] = h($m['author']);
		if ($m['url'] !== '') $info[] = '<a href="' . h($m['url']) . '" target="_blank" rel="noopener">' . h(t('plg_website')) . '</a>';
		if ($m['tables']) $info[] = h(t('plg_tables', implode(', ', $m['tables'])));
		if ($m['network']) $info[] = '<i class="bi bi-globe2 me-1" aria-hidden="true"></i>' . h(t('plg_network'));
		$acts = [];
		if ($on) {
			$api = NuPlugin::get($id);
			if ($api && $api->settingsDef()) $acts[] = '<a class="btn btn-sm btn-outline-secondary" href="' . h(nu_url('plugins', ['settings' => $id])) . '" data-plg-act="settings">'
				. '<i class="bi bi-sliders me-1" aria-hidden="true"></i>' . h(t('plg_settings')) . '</a>';
			$acts[] = self::action('disable', $id, t('plg_disable'), 'btn-outline-secondary', 'bi-toggle-off');
		} elseif (!$needs && $sig['state'] !== 'modified') {
			$acts[] = self::action('enable', $id, t('plg_enable'), 'btn-primary', 'bi-toggle-on', in_array($sig['state'], ['unsigned', 'unchecked', 'unknown'], true) ? t('plg_enable_unsigned_confirm') : '');
		}
		$acts[] = self::action('remove', $id, t('plg_remove'), 'btn-outline-danger', 'bi-trash', t('plg_remove_confirm', $m['name']),
			'<div class="form-check text-start small mt-1 nuPlgData"><input class="form-check-input" type="checkbox" name="data" value="1" id="plgData-' . h($id) . '">'
			. '<label class="form-check-label" for="plgData-' . h($id) . '">' . h(t('plg_remove_data')) . '</label></div>');
		return '<tr data-plugin="' . h($id) . '"><td><div class="fw-semibold">' . h($m['name']) . ' <code class="small fw-normal">' . h($id) . '</code></div>'
			. ($m['description'] !== '' ? '<div class="small text-body-secondary">' . h($m['description']) . '</div>' : '')
			. ($info ? '<div class="small text-body-secondary mt-1">' . implode(' · ', $info) . '</div>' : '') . '</td>'
			. '<td>' . h($m['version']) . '</td><td>' . $status . '</td><td>' . self::sigBadge($sig) . '</td>'
			. '<td class="text-end"><div class="d-inline-flex flex-wrap gap-2 justify-content-end align-items-start">' . implode('', $acts) . '</div></td></tr>';
	}

	/** The checked upload, waiting for Install. */
	private static function checkCard(array $st): string
	{
		$up = $_SESSION[NuSess::PLUGIN_UPLOAD] ?? null;
		$f = is_array($up) ? self::uploadFile((string)$up['token']) : null;
		if ($f === null || !is_file($f)) return '';
		$c = NuPlugin::checkZip($f);
		if (!$c['ok']) return '<div class="alert alert-danger" id="plgCheck">' . h($c['error']) . '</div>';
		$m = $c['manifest'];
		$blocked = $c['sig']['state'] === 'modified';
		$o = '<div class="card shadow-sm mb-4 border-primary" id="plgCheck"><div class="card-body"><h3 class="h6">' . h(t('plg_check_title', $m['name'], $m['version'])) . '</h3>'
			. '<dl class="row small mb-2"><dt class="col-sm-3">' . h(t('plg_col_name')) . '</dt><dd class="col-sm-9"><code>' . h($m['id']) . '</code>'
			. ($m['description'] !== '' ? ' – ' . h($m['description']) : '') . '</dd>'
			. '<dt class="col-sm-3">' . h(t('plg_col_sig')) . '</dt><dd class="col-sm-9">' . self::sigBadge($c['sig']) . '</dd>'
			. ($m['author'] !== '' ? '<dt class="col-sm-3">' . h(t('plg_author')) . '</dt><dd class="col-sm-9">' . h($m['author']) . '</dd>' : '')
			. ($m['tables'] ? '<dt class="col-sm-3">' . h(t('plg_tables_label')) . '</dt><dd class="col-sm-9">' . h(implode(', ', $m['tables'])) . '</dd>' : '')
			. ($m['network'] ? '<dt class="col-sm-3">' . h(t('plg_network')) . '</dt><dd class="col-sm-9">✓</dd>' : '')
			. ($c['installed'] !== '' ? '<dt class="col-sm-3">' . h(t('plg_installed_label')) . '</dt><dd class="col-sm-9" id="plgReplaces">' . h(t('plg_replaces', $c['installed'])) . '</dd>' : '')
			. '</dl>';
		if ($c['needs']) $o .= '<div class="alert alert-warning small py-2">' . h(t('plg_needs', implode(', ', $c['needs']))) . '</div>';
		if (in_array($c['sig']['state'], ['unsigned', 'unchecked', 'unknown'], true)) $o .= '<div class="alert alert-warning small py-2" id="plgUnsigned">' . h(t('plg_unsigned_warn')) . '</div>';
		$o .= '<div class="d-flex gap-2">';
		if (!$blocked) $o .= self::action('install', $m['id'], t('plg_install'), 'btn-primary', 'bi-box-arrow-in-down');
		$o .= self::action('cancel', $m['id'], t('cancel'), 'btn-outline-secondary', 'bi-x-lg') . '</div></div></div>';
		return $o;
	}

	private static function uploadCard(array $st): string
	{
		$o = '<div class="card shadow-sm mb-4" id="plgUploadCard"><div class="card-body"><h3 class="h6">' . h(t('plg_upload_title')) . '</h3>';
		if ($st['upload']) {
			$o .= '<form method="post" action="' . h(nu_url('plugins')) . '" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end mb-3" id="plgUploadForm" data-nu-once>'
				. nu_csrf_field() . '<input type="hidden" name="action" value="upload"><div><label class="form-label small mb-1" for="plgZip">' . h(t('plg_upload_file')) . '</label>'
				. '<input class="form-control" type="file" name="plugin_zip" id="plgZip" accept=".zip" required></div>'
				. '<button type="submit" class="btn btn-primary" id="plgUploadBtn"><i class="bi bi-search me-1" aria-hidden="true"></i>' . h(t('plg_upload_btn')) . '</button></form>';
		} else {
			$o .= '<p class="small text-body-secondary" id="plgUploadOff">' . h(t('plg_upload_off')) . '</p>';
		}
		$o .= '<form method="post" action="' . h(nu_url('plugins')) . '" class="form-check form-switch mb-0">' . nu_csrf_field() . '<input type="hidden" name="action" value="upload_set">'
			. '<input type="hidden" name="upload" value="0"><input class="form-check-input js-autosubmit" type="checkbox" role="switch" name="upload" value="1" id="plgUploadSet"'
			. ($st['upload'] ? ' checked' : '') . '><label class="form-check-label" for="plgUploadSet">' . h(t('plg_upload_allow')) . '</label>' . nu_info_tip(t('plg_upload_allow_tip'), 'ms-1', true, '', 'plugins-install')
			. '</form></div></div>';
		return $o;
	}

	private static function keysCard(array $st): string
	{
		$o = '<div class="card shadow-sm mb-4" id="plgKeysCard"><div class="card-body"><h3 class="h6 d-flex align-items-center gap-1">' . h(t('plg_keys_title'))
			. nu_info_tip(t('plg_keys_tip'), 'ms-1', true, '', 'plugins-sign') . '</h3><ul class="list-unstyled small mb-3" id="plgKeys"><li class="mb-1"><i class="bi bi-patch-check me-1 text-success" aria-hidden="true"></i>'
			. 'nuBuilder Next <code class="small">' . h(substr(NuUpdate::SIGN_PUBLIC, 0, 12)) . '…</code></li>';
		foreach (array_values($st['keys']) as $i => $k) {
			$o .= '<li class="mb-1 d-flex align-items-center gap-2" data-key="' . $i . '"><i class="bi bi-key" aria-hidden="true"></i>' . h((string)$k['name'])
				. ' <code class="small">' . h(substr((string)$k['key'], 0, 12)) . '…</code>'
				. self::action('key_remove', '', t('plg_key_remove'), 'btn-link text-danger p-0', 'bi-x-lg', t('plg_key_remove_confirm', (string)$k['name']), '<input type="hidden" name="key" value="' . $i . '">')
				. '</li>';
		}
		$o .= '</ul><form method="post" action="' . h(nu_url('plugins')) . '" class="row g-2 align-items-end" id="plgKeyForm">' . nu_csrf_field()
			. '<input type="hidden" name="action" value="key_add"><div class="col-sm-4"><label class="form-label small mb-1" for="plgKeyName">' . h(t('plg_key_name')) . '</label>'
			. '<input class="form-control" name="key_name" id="plgKeyName" maxlength="60" required></div><div class="col-sm-6"><label class="form-label small mb-1" for="plgKeyValue">'
			. h(t('plg_key_value')) . '</label><input class="form-control font-monospace" name="key_value" id="plgKeyValue" maxlength="60" required></div>'
			. '<div class="col-sm-2"><button type="submit" class="btn btn-outline-secondary w-100" id="plgKeyAdd">' . h(t('plg_key_add')) . '</button></div></form></div></div>';
		return $o;
	}

	/** Update page → Check package: plugins the new version would stop or warn about. */
	public static function checkHtml(?array $report): string
	{
		if (!$report) return '';
		$bad = array_values(array_filter($report, static fn($r) => $r['issue'] !== ''));
		$o = '<div class="border rounded p-3 mb-3" id="updPlugins"><div class="fw-semibold small mb-2"><i class="bi bi-puzzle me-1" aria-hidden="true"></i>' . h(t('upd_plugins_title')) . '</div>';
		if (!$bad) return $o . '<p class="small text-success mb-0" id="updPluginsOk"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>' . h(t('upd_plugins_ok', count($report))) . '</p></div>';
		$o .= '<ul class="list-unstyled small mb-0" id="updPluginsList">';
		foreach ($bad as $r) {
			$err = $r['issue'] === 'breaks';
			$o .= '<li class="mb-1" data-plugin="' . h($r['id']) . '"><span class="badge ' . ($err ? 'bg-danger' : 'bg-warning text-dark') . ' me-1">'
				. h(t($err ? 'upd_plugins_breaks' : 'upd_plugins_deprecated')) . '</span>' . h($r['name']) . ' <code class="small">' . h($r['id']) . ' ' . h($r['version']) . '</code></li>';
		}
		return $o . '</ul></div>';
	}
}
