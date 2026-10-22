<?php
// Adds the test form "tjs" (0.8.383): form-page JavaScript of nuBuilder 4.9 – scripts in HTML objects, nuAfterSave after the reload,
// nuFORM methods, nuSERVERRESPONSE, values set while loading, the nuCloseAfterSaveGlobal / subform filter hooks.
$db = new PDO('mysql:host=127.0.0.1;dbname=' . ($argv[1] ?? 'nutest') . ';charset=utf8mb4', getenv('DBUSER') ?: 'nu', getenv('DBPASS') ?: 'nu', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function ins3(PDO $db, string $t, array $r): void { $c = array_keys($r); $db->prepare("INSERT INTO $t (`" . implode('`,`', $c) . "`) VALUES (" . implode(',', array_fill(0, count($c), '?')) . ")")->execute(array_values($r)); }
$tpl = [];
foreach ($db->query("SELECT * FROM zzzzsys_object WHERE sob_all_id IN ('inputtextsample','htmlsample') AND sob_all_zzzzsys_form_id IN ('nusample','nusamplesubformform')", PDO::FETCH_ASSOC) as $r) $tpl[$r['sob_all_id']] = $r;
$formTpl = $db->query("SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuformat'")->fetch(PDO::FETCH_ASSOC);

$db->exec("DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'tjs'");
$db->exec("DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'tjs'");
$db->exec("DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = 'tjs'");

$js = <<<'JS'
window.T = window.T || {};
T.htmlFn = typeof tHtmlFn === 'function' ? tHtmlFn() : 'missing';
function nuOnLoad() {
	nuSetValue('cus_city', 'Loaded');
	T.editedInLoad = nuHasBeenEdited();
	T.prop = nuFORM.getProperty('form_id');
	T.sr = nuSERVERRESPONSE.form_id;
	T.main = nuFORM.subform('').fields.indexOf('cus_name') > 0;
	T.calc = nuFORM.calc('cus_code');
}
function nuAfterSave() { T.afterSave = nuRecordId() + '|' + document.querySelectorAll('#cus_name').length; }
JS;
ins3($db, 'zzzzsys_form', array_merge($formTpl, ['zzzzsys_form_id' => 'tjs', 'sfo_type' => 'edit', 'sfo_code' => 'tjs', 'sfo_description' => 'JavaScript', 'sfo_table' => 'tcustomer',
	'sfo_primary_key' => 'customer_id', 'sfo_browse_sql' => '', 'sfo_group' => 'test', 'sfo_browse_redirect_form_id' => '', 'sfo_json' => null, 'sfo_style' => null, 'sfo_browse_javascript' => null,
	'sfo_edit_javascript' => null, 'sfo_breadcrumb_title' => '', 'sfo_javascript' => $js]));
ins3($db, 'zzzzsys_tab', ['zzzzsys_tab_id' => 'tt_js_1', 'syt_zzzzsys_form_id' => 'tjs', 'syt_title' => 'Main', 'syt_order' => 10, 'syt_help' => '']);
$b = ['sob_all_zzzzsys_form_id' => 'tjs', 'sob_all_zzzzsys_tab_id' => 'tt_js_1', 'sob_all_left' => 150, 'sob_all_width' => 200, 'sob_all_json' => null, 'sob_json' => null, 'sob_all_access' => '0'];
$o = 0;
$mk = function (string $template, array $over) use ($db, $tpl, $b, &$o) {
	$row = array_merge($tpl[$template], $b, ['sob_all_order' => ($o += 10)], $over);
	$row['zzzzsys_object_id'] = 'tj' . substr(md5('tjs.' . $over['sob_all_id']), 0, 14);
	ins3($db, 'zzzzsys_object', $row);
};
$mk('inputtextsample', ['sob_all_id' => 'cus_code', 'sob_all_label' => 'Code', 'sob_all_top' => 30, 'sob_input_type' => 'text', 'sob_input_format' => '']);
$mk('inputtextsample', ['sob_all_id' => 'cus_name', 'sob_all_label' => 'Name', 'sob_all_top' => 60, 'sob_input_type' => 'text', 'sob_input_format' => '']);
$mk('inputtextsample', ['sob_all_id' => 'cus_city', 'sob_all_label' => 'City', 'sob_all_top' => 90, 'sob_input_type' => 'text', 'sob_input_format' => '']);
// nuBuilder inserts HTML objects after its libraries: the script may use $ and define functions for the form's code
$mk('htmlsample', ['sob_all_id' => 'js_html', 'sob_all_label' => '', 'sob_all_top' => 120, 'sob_all_height' => 40,
	'sob_html_code' => '<div id="tHtmlBox">box</div><script>window.T = window.T || {}; T.htmlJq = typeof $; $("#tHtmlBox").text("set by script"); window.tHtmlFn = function () { return "html ok"; };</script>'
		. '<script type="text/x-template" id="tTpl">keep</script>']);
echo "JavaScript test form built\n";
