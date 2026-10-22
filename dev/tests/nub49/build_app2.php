<?php
// Adds a second test form "tadv" (nuBuilder 4.9 features beyond the basics), an access level, a user and a setup header function.
$db = new PDO('mysql:host=127.0.0.1;dbname=' . ($argv[1] ?? 'nutest') . ';charset=utf8mb4', getenv('DBUSER') ?: 'nu', getenv('DBPASS') ?: 'nu', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function ins(PDO $db, string $t, array $r): void { $c = array_keys($r); $db->prepare("INSERT INTO $t (`" . implode('`,`', $c) . "`) VALUES (" . implode(',', array_fill(0, count($c), '?')) . ")")->execute(array_values($r)); }
function oid(string $f, string $i): string { return 'to' . substr(md5("$f.$i"), 0, 14); }
$tpl = [];
foreach ($db->query("SELECT * FROM zzzzsys_object WHERE sob_all_id IN ('inputtextsample','selectsample','lookupsample','inputfilesample','htmlsample') AND sob_all_zzzzsys_form_id IN ('nusample','nusamplesubformform')", PDO::FETCH_ASSOC) as $r) $tpl[$r['sob_all_id']] = $r;
$formTpl = $db->query("SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuformat'")->fetch(PDO::FETCH_ASSOC);

$db->exec("DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'tadv'");
$db->exec("DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'tadv'");
$db->exec("DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = 'tadv'");
$db->exec("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id IN ('tsel_proc', '" . oid('tadv', 'adv_lookup') . "_AB')");
$db->exec("DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'taccess'");
$db->exec("DELETE FROM zzzzsys_access WHERE zzzzsys_access_id = 'taccess'");
$db->exec("DELETE FROM zzzzsys_user WHERE zzzzsys_user_id IN ('tuser1', 'tuser2')");
$db->exec("DELETE FROM zzzzsys_translate WHERE zzzzsys_translate_id LIKE 'ttr%'");

ins($db, 'zzzzsys_form', array_merge($formTpl, ['zzzzsys_form_id' => 'tadv', 'sfo_type' => 'edit', 'sfo_code' => 'tadv', 'sfo_description' => 'Advanced', 'sfo_table' => 'tcustomer',
	'sfo_primary_key' => 'customer_id', 'sfo_browse_sql' => '', 'sfo_group' => 'test', 'sfo_browse_redirect_form_id' => '', 'sfo_json' => null, 'sfo_style' => null, 'sfo_browse_javascript' => null,
	'sfo_edit_javascript' => null, 'sfo_breadcrumb_title' => '',
	'sfo_javascript' => "window.T = window.T || {}; T.setupHeaderFn = (typeof tSetupHeaderFn === 'function') ? tSetupHeaderFn() : 'missing';"]));
ins($db, 'zzzzsys_tab', ['zzzzsys_tab_id' => 'tt_adv_1', 'syt_zzzzsys_form_id' => 'tadv', 'syt_title' => 'Main', 'syt_order' => 10, 'syt_help' => 'Help text of the tab']);
$b = ['sob_all_zzzzsys_form_id' => 'tadv', 'sob_all_zzzzsys_tab_id' => 'tt_adv_1', 'sob_all_left' => 150, 'sob_all_width' => 200, 'sob_all_json' => null, 'sob_json' => null];
$o = 0;
$mk = function (string $template, array $over) use ($db, $tpl, $b, &$o) {
	$row = array_merge($tpl[$template], $b, ['sob_all_order' => ($o += 10)], $over);
	$row['zzzzsys_object_id'] = oid('tadv', $over['sob_all_id']);
	ins($db, 'zzzzsys_object', $row);
};
// access condition (access 9 + SQL): nuBuilder hides / makes read-only by the SQL result
$mk('inputtextsample', ['sob_all_id' => 'cus_code', 'sob_all_label' => 'Hidden by condition', 'sob_all_top' => 30, 'sob_all_access' => '9', 'sob_all_access_condition' => 'SELECT 2']);
$mk('inputtextsample', ['sob_all_id' => 'cus_name', 'sob_all_label' => 'Read-only by condition', 'sob_all_top' => 60, 'sob_all_access' => '9', 'sob_all_access_condition' => "SELECT IF('#USER_ID#' = 'globeadmin', 1, 0)"]);
// input icon
$mk('inputtextsample', ['sob_all_id' => 'cus_city', 'sob_all_label' => 'With icon', 'sob_all_top' => 90, 'sob_input_icon' => 'fa-solid fa-city']);
// select filled by a procedure
$mk('selectsample', ['sob_all_id' => 'cus_type', 'sob_all_label' => 'Select by procedure', 'sob_all_top' => 120, 'sob_select_sql' => '', 'sob_select_procedure' => 'tsel_proc']);
// lookup with After Browse PHP (nuLookupRecord + nuSetFormValue)
$mk('lookupsample', ['sob_all_id' => 'adv_lookup', 'sob_all_label' => 'Lookup AB', 'sob_all_top' => 150, 'sob_all_width' => 60, 'sob_lookup_zzzzsys_form_id' => 'tcountry',
	'sob_lookup_code' => 'cty_code', 'sob_lookup_description' => 'cty_name', 'sob_lookup_description_width' => '150']);
$mk('inputtextsample', ['sob_all_id' => 'cus_notes', 'sob_all_label' => 'Set by lookup AB', 'sob_all_top' => 180]);
// file into the file system (nuBuilder 4.9: target 1 = server folder via Uppy)
$mk('inputfilesample', ['sob_all_id' => 'cus_file', 'sob_all_label' => 'File to server folder', 'sob_all_top' => 210, 'sob_input_file_target' => '1', 'sob_input_format' => '',
	'sob_html_code' => "<div id=\"#uppy_div#\"></div><script>window.T = window.T || {}; T.uppyScript = true;</script>"]);
ins($db, 'zzzzsys_php', ['zzzzsys_php_id' => 'tsel_proc', 'sph_code' => 'tsel_proc', 'sph_description' => 'select options', 'sph_group' => '', 'sph_category' => '', 'sph_status' => '1',
	'sph_php' => "\$_POST['nuRunPHPHiddenResult'] = [['P1', 'From procedure 1'], ['P2', 'From procedure 2']];\nnuSetJSONData('x', 'y');",
	'sph_run' => 'hide', 'sph_zzzzsys_form_id' => '', 'sph_system' => '0', 'sph_global' => '1', 'sph_demo' => '0', 'sph_template' => '0', 'sph_hide' => '0']);
ins($db, 'zzzzsys_php', ['zzzzsys_php_id' => oid('tadv', 'adv_lookup') . '_AB', 'sph_code' => oid('tadv', 'adv_lookup') . '_AB', 'sph_description' => 'lookup AB', 'sph_group' => '', 'sph_category' => '',
	'sph_status' => '1', 'sph_php' => "\$lu = nuLookupRecord();\nnuSetFormValue('cus_notes', 'AB:' . \$lu->cty_name);", 'sph_run' => '', 'sph_zzzzsys_form_id' => '', 'sph_system' => '0',
	'sph_global' => '0', 'sph_demo' => '0', 'sph_template' => '0', 'sph_hide' => '0']);
// setup header with a global function (nuBuilder: Setup → Header)
$db->exec("UPDATE zzzzsys_setup SET set_header = CONCAT(IFNULL(set_header, ''), '\n\nfunction tSetupHeaderFn() { return \"header ok\"; }\n')");

// access level + users (md5 password as nuBuilder up to 4.5, bcrypt as later versions)
ins($db, 'zzzzsys_access', ['zzzzsys_access_id' => 'taccess', 'sal_code' => 'TSALES', 'sal_description' => 'Test sales', 'sal_zzzzsys_form_id' => 'tcustomer']);
foreach ([['tcustomer', '1', '1', '1', '0', '1', '1'], ['tcountry', '1', '0', '0', '0', '0', '0'], ['torder_sf', '1', '1', '1', '1', '0', '0']] as $i => $r) {
	ins($db, 'zzzzsys_access_form', ['zzzzsys_access_form_id' => "taf$i", 'slf_zzzzsys_access_id' => 'taccess', 'slf_zzzzsys_form_id' => $r[0], 'slf_add_button' => $r[1], 'slf_save_button' => $r[2],
		'slf_clone_button' => $r[3], 'slf_new_button' => $r[1], 'slf_delete_button' => $r[4], 'slf_print_button' => $r[5], 'slf_data_mode' => null]);
}
ins($db, 'zzzzsys_user', ['zzzzsys_user_id' => 'tuser1', 'sus_zzzzsys_access_id' => 'taccess', 'sus_name' => 'Test Md5', 'sus_email' => 't1@example.com', 'sus_login_name' => 'tmd5',
	'sus_login_password' => md5('Secret123!'), 'sus_language' => 'German']);
ins($db, 'zzzzsys_user', ['zzzzsys_user_id' => 'tuser2', 'sus_zzzzsys_access_id' => 'taccess', 'sus_name' => 'Test Bcrypt', 'sus_email' => 't2@example.com', 'sus_login_name' => 'tbcrypt',
	'sus_login_password' => password_hash('Secret123!', PASSWORD_DEFAULT), 'sus_language' => '']);
// translation of an application label (nuBuilder: zzzzsys_translate, used by nuTranslate and labels)
ins($db, 'zzzzsys_translate', ['zzzzsys_translate_id' => 'ttr1', 'trl_language' => 'German', 'trl_english' => 'Name', 'trl_translation' => 'Name-DE']);
echo "advanced test form, access level, users built\n";
