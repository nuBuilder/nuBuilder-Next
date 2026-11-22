<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Plugins (globeadmin only): the actions of Update → Plugins (POST, back to the tab) and the settings page of one plugin
 * (?settings=<id>). Without a plugin: the tab on the update page (core/nupluginspage.php NuPluginsPage).
 */

$u = NuAuth::user();
if (!$u || empty($u['globeadmin']) || !empty($u['demo'])) {
	http_response_code(403);
	nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true);
}

NuPluginsPage::post(get_defined_vars());   // POST: the action, then back to the tab (code review R7)

$id = (string)($_GET['settings'] ?? '');
$api = $id !== '' ? NuPlugin::get($id) : null;
$def = $api?->settingsDef();
if (!$api || !$def) nu_redirect(nu_url('update', ['tab' => 'plugins']));

$fields = NuPlugin::call($id, $def[0], [$api->all()]);
if ($fields === false && !NuPlugin::get($id)) {
	nu_flash('danger', t('plg_failed_msg', $api->name(), ''));
	nu_redirect(nu_url('update', ['tab' => 'plugins']));
}

nu_layout_start(t('plg_settings_title', $api->name()));
echo nu_page_header(t('plg_settings_title', $api->name()), ['icon' => 'bi-puzzle', 'back' => nu_url('update', ['tab' => 'plugins']),
	'crumbs' => [[t('upd_title'), nu_url('update')], [t('plg_title'), nu_url('update', ['tab' => 'plugins'])]],
	'buttons' => '<button type="submit" form="plgSettingsForm" class="btn nuBtnAct nuBtnSave" data-nu-save><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button>']);
?>
<div class="card shadow-sm"><div class="card-body">
	<form method="post" action="<?= h(nu_url('plugins')) ?>" id="plgSettingsForm" data-nu-guard>
		<?= nu_csrf_field() ?>
		<input type="hidden" name="action" value="settings">
		<input type="hidden" name="id" value="<?= h($id) ?>">
		<?= is_string($fields) ? $fields /* the plugin's fields – the plugin escapes its values */ : '' ?>
	</form>
</div></div>
<?php
nu_layout_end();
