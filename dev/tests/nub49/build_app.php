<?php
// From the nuBuilder 4.9 compatibility test kit (0.8.382) – used by dev/tests/e2e.js (test nub49_app). Usage: php build_app.php [database]
// Builds a small test application in nuBuilder 4.9's storage format (zzzzsys_form / tab / object / browse / php / event),
// cloning nuBuilder's own sample objects as templates so every column is filled the way nuBuilder fills it.
// Usage: php build_app.php <database>
$dbName = $argv[1] ?? 'nutest';
$db = new PDO("mysql:host=127.0.0.1;dbname=$dbName;charset=utf8mb4", getenv('DBUSER') ?: 'nu', getenv('DBPASS') ?: 'nu', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ---------------------------------------------------------------- application tables and data
$db->exec("DROP TABLE IF EXISTS tcountry, tcustomer, torder, tlog, tnote");
$db->exec("CREATE TABLE tnote (tnote_id VARCHAR(25) PRIMARY KEY, cus_code VARCHAR(20), nte_text VARCHAR(200)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("INSERT INTO tnote VALUES ('tn1','C001','a note')");
$db->exec("CREATE TABLE tcountry (country_id VARCHAR(25) PRIMARY KEY, cty_code VARCHAR(10), cty_name VARCHAR(100)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE tcustomer (customer_id VARCHAR(25) PRIMARY KEY, cus_code VARCHAR(20), cus_name VARCHAR(100), cus_city VARCHAR(100), cus_country_id VARCHAR(25),
	cus_active VARCHAR(1), cus_since DATE, cus_credit DECIMAL(12,2), cus_type VARCHAR(20), cus_tags VARCHAR(500), cus_notes TEXT, cus_number VARCHAR(20), cus_file LONGTEXT,
	sort_order INT, tcustomer_nulog VARCHAR(1000)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE torder (order_id VARCHAR(25) PRIMARY KEY, ord_customer_id VARCHAR(25), ord_date DATE, ord_product VARCHAR(100), ord_qty INT, ord_price DECIMAL(12,2)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE tlog (tlog_id VARCHAR(25) PRIMARY KEY, tlg_text VARCHAR(500), tlg_when DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("INSERT INTO tcountry VALUES ('tc1','CH','Switzerland'),('tc2','DE','Germany'),('tc3','FR','France')");
$db->exec("INSERT INTO tcustomer (customer_id,cus_code,cus_name,cus_city,cus_country_id,cus_active,cus_since,cus_credit,cus_type,cus_tags,cus_notes,cus_number,sort_order) VALUES
	('tu1','C001','Alpha AG','Zurich','tc1','1','2020-01-15',1500.50,'A','[\"x\",\"y\"]','first','1',1),
	('tu2','C002','Beta GmbH','Berlin','tc2','1','2021-06-01',200.00,'B','[\"y\"]','','2',2),
	('tu3','C003','Gamma SA','Paris','tc3','0','2019-11-30',0,'A','[]','','3',3),
	('tu4','C004','Delta AG','Basel','tc1','1','2022-03-10',99.90,'B','','','4',4),
	('tu5','C005','Epsilon','Lyon','tc3','1','2023-08-08',5000,'A','','','5',5)");
$db->exec("INSERT INTO torder VALUES ('to1','tu1','2024-01-01','Widget',2,10.00),('to2','tu1','2024-02-01','Gadget',1,99.50),('to3','tu2','2024-03-01','Widget',5,10.00)");

// ---------------------------------------------------------------- remove an earlier copy of the test app
$forms = ['tcountry', 'tcustomer', 'torder_sf', 'tb_qualified', 'tb_alias', 'tb_nopk', 'tb_dupcol', 'tb_tablealias', 'tb_filter', 'tb_hash', 'tb_titleselect'];
$in = "'" . implode("','", $forms) . "'";
$db->exec("DELETE FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN (SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id IN ($in))");
foreach (['zzzzsys_object' => 'sob_all_zzzzsys_form_id', 'zzzzsys_tab' => 'syt_zzzzsys_form_id', 'zzzzsys_browse' => 'sbr_zzzzsys_form_id', 'zzzzsys_form' => 'zzzzsys_form_id'] as $t => $c) $db->exec("DELETE FROM $t WHERE $c IN ($in)");
$db->exec("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id LIKE 't%\\_B_' OR zzzzsys_php_id LIKE 't%\\_A_' OR zzzzsys_php_id IN ('tproc_hello','tproc_report') ");

// ---------------------------------------------------------------- helpers
$tpl = [];
foreach ($db->query("SELECT * FROM zzzzsys_object WHERE sob_all_id IN ('inputtextsample','inputnudatesample','inputnunumbersample','inputnumbersample','inputcheckboxsample',
	'inputnuautonumbersample','inputbuttonsample','selectsample','selectmultiselectsample','lookupsample','textareasample','displaysample','htmlsample','calcsample','wordsample',
	'subformsample','inputfilesample') OR sob_all_id = 'run_add_note'", PDO::FETCH_ASSOC) as $r) $tpl[$r['sob_all_id']] = $r;
$formTpl = $db->query("SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuformat'")->fetch(PDO::FETCH_ASSOC);

function ins(PDO $db, string $table, array $row): void {
	$cols = array_keys($row);
	$db->prepare("INSERT INTO $table (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($row));
}
function form(PDO $db, array $tplRow, array $o): void {
	$row = array_merge($tplRow, ['sfo_browse_sql' => '', 'sfo_javascript' => null, 'sfo_browse_javascript' => null, 'sfo_edit_javascript' => null, 'sfo_style' => null,
		'sfo_json' => null, 'sfo_group' => 'test', 'sfo_browse_redirect_form_id' => '', 'sfo_browse_rows_per_page' => 20, 'sfo_breadcrumb_title' => ''], $o);
	ins($db, 'zzzzsys_form', $row);
}
function tab(PDO $db, string $id, string $form, string $title, int $order): void {
	ins($db, 'zzzzsys_tab', ['zzzzsys_tab_id' => $id, 'syt_zzzzsys_form_id' => $form, 'syt_title' => $title, 'syt_order' => $order, 'syt_help' => '']);
}
$order = 0;
function oid(string $f, string $i): string { return 'to' . substr(md5("$f.$i"), 0, 14); }
function obj(PDO $db, array $tpl, string $template, array $o): void {
	global $order;
	$row = array_merge($tpl[$template], ['sob_all_order' => ($order += 10), 'sob_all_json' => null, 'sob_json' => null], $o);
	$row['zzzzsys_object_id'] = $o['zzzzsys_object_id'] ?? oid($o['sob_all_zzzzsys_form_id'], $o['sob_all_id']);
	ins($db, 'zzzzsys_object', $row);
}
function browse(PDO $db, string $form, array $cols): void {
	foreach ($cols as $i => $c) ins($db, 'zzzzsys_browse', ['zzzzsys_browse_id' => $c[3] ?? ("tb_{$form}_$i"), 'sbr_zzzzsys_form_id' => $form, 'sbr_title' => $c[0], 'sbr_display' => $c[1],
		'sbr_align' => 'l', 'sbr_format' => $c[2] ?? '', 'sbr_order' => ($i + 1) * 10, 'sbr_width' => 150]);
}
function php(PDO $db, string $id, string $code, array $o = []): void {
	ins($db, 'zzzzsys_php', array_merge(['zzzzsys_php_id' => $id, 'sph_code' => $id, 'sph_description' => $id, 'sph_group' => '', 'sph_category' => '', 'sph_status' => '1',
		'sph_php' => $code, 'sph_javascript' => null, 'sph_javascript_active' => null, 'sph_run' => '', 'sph_zzzzsys_form_id' => '', 'sph_system' => '0', 'sph_global' => '0',
		'sph_demo' => '0', 'sph_template' => '0', 'sph_hide' => '0', 'sph_json' => null], $o));
}

// ---------------------------------------------------------------- tcountry (lookup target)
form($db, $formTpl, ['zzzzsys_form_id' => 'tcountry', 'sfo_type' => 'browseedit', 'sfo_code' => 'tcountry', 'sfo_description' => 'Countries', 'sfo_table' => 'tcountry',
	'sfo_primary_key' => 'country_id', 'sfo_browse_sql' => 'SELECT * FROM tcountry ORDER BY cty_name']);
tab($db, 'tt_country_1', 'tcountry', 'Main', 10);
browse($db, 'tcountry', [['Code', 'cty_code'], ['Name', 'cty_name']]);
$base = ['sob_all_zzzzsys_form_id' => 'tcountry', 'sob_all_zzzzsys_tab_id' => 'tt_country_1', 'sob_all_left' => 150, 'sob_all_width' => 200];
obj($db, $tpl, 'inputtextsample', $base + ['sob_all_id' => 'cty_code', 'sob_all_label' => 'Code', 'sob_all_top' => 30]);
obj($db, $tpl, 'inputtextsample', $base + ['sob_all_id' => 'cty_name', 'sob_all_label' => 'Name', 'sob_all_top' => 60]);

// ---------------------------------------------------------------- torder_sf (subform)
form($db, $formTpl, ['zzzzsys_form_id' => 'torder_sf', 'sfo_type' => 'subform', 'sfo_code' => 'torder_sf', 'sfo_description' => 'Orders', 'sfo_table' => 'torder', 'sfo_primary_key' => 'order_id']);
tab($db, 'tt_order_1', 'torder_sf', 'Main', 10);
$base = ['sob_all_zzzzsys_form_id' => 'torder_sf', 'sob_all_zzzzsys_tab_id' => 'tt_order_1', 'sob_all_top' => 20];
obj($db, $tpl, 'inputnudatesample', $base + ['sob_all_id' => 'ord_date', 'sob_all_label' => 'Date', 'sob_all_left' => 10, 'sob_all_width' => 90]);
obj($db, $tpl, 'inputtextsample', $base + ['sob_all_id' => 'ord_product', 'sob_all_label' => 'Product', 'sob_all_left' => 110, 'sob_all_width' => 150]);
obj($db, $tpl, 'inputnumbersample', $base + ['sob_all_id' => 'ord_qty', 'sob_all_label' => 'Qty', 'sob_all_left' => 270, 'sob_all_width' => 50, 'sob_input_format' => '']);
obj($db, $tpl, 'inputnunumbersample', $base + ['sob_all_id' => 'ord_price', 'sob_all_label' => 'Price', 'sob_all_left' => 330, 'sob_all_width' => 80, 'sob_input_format' => 'N|1000.00']);

// ---------------------------------------------------------------- tcustomer (the main test form)
$formJs = <<<'JS'
window.T = window.T || {};
T.formJs = true;
T.formType = nuFormType();
if (nuFormType() == 'edit') {
	T.recordId = nuRecordId();
	T.isNew = nuIsNewRecord();
	T.code = nuGetValue('cus_code');
	nuSetTitle('Customer ' + nuGetValue('cus_name'));
	nuAddActionButton('tMyButton', 'My Button', 'T.myButton = true;');
	nuHide('cus_city'); T.hidden = nuIsHidden('cus_city'); nuShow('cus_city'); T.shown = nuIsVisible('cus_city');
	nuDisable('cus_number'); T.disabled = nuIsDisabled('cus_number');
	$('#cus_code').addClass('tTestClass');
	nuSetProperty('tprop', 'abc'); T.prop = nuGetProperty('tprop');
	T.userId = nuUserId(); T.globeadmin = nuGlobalAccess();
	T.subRows = nuSubformObject('sub_orders') ? nuSubformObject('sub_orders').rows.length : -1;
	T.translate = nuTranslate('Save');
	if (nuIsNewRecord()) nuSetValue('cus_active', true);
}
function nuBeforeSave() { T.beforeSave = (T.beforeSave || 0) + 1; return true; }
function nuAfterSave() { T.afterSave = (T.afterSave || 0) + 1; }
function nuOnTabSelected(tab) { T.tabSelected = tab ? (tab.id || true) : true; }
function nuSelectBrowse(e) { T.selectBrowse = true; return true; }
JS;
form($db, $formTpl, ['zzzzsys_form_id' => 'tcustomer', 'sfo_type' => 'browseedit', 'sfo_code' => 'tcustomer', 'sfo_description' => 'Customers', 'sfo_table' => 'tcustomer',
	'sfo_primary_key' => 'customer_id', 'sfo_javascript' => $formJs,
	'sfo_browse_sql' => "SELECT * FROM tcustomer LEFT JOIN tcountry ON country_id = cus_country_id ORDER BY cus_name"]);
tab($db, 'tt_cust_1', 'tcustomer', 'Main', 10);
tab($db, 'tt_cust_2', 'tcustomer', 'Orders', 20);
browse($db, 'tcustomer', [['Code', 'cus_code'], ['Name', 'cus_name'], ['Country', 'cty_name'], ['Since', 'cus_since', 'D|dd-mm-yyyy'], ['Credit', 'cus_credit', 'N|1,000.00']]);
$b = ['sob_all_zzzzsys_form_id' => 'tcustomer', 'sob_all_zzzzsys_tab_id' => 'tt_cust_1', 'sob_all_left' => 150, 'sob_all_width' => 200];
$y = 0; $nextY = function () use (&$y) { return $y += 32; };
obj($db, $tpl, 'inputtextsample', $b + ['sob_all_id' => 'cus_code', 'sob_all_label' => 'Code', 'sob_all_top' => $nextY(), 'sob_all_validate' => '2']);   // no duplicates
obj($db, $tpl, 'inputtextsample', $b + ['sob_all_id' => 'cus_name', 'sob_all_label' => 'Name', 'sob_all_top' => $nextY(), 'sob_all_validate' => '1']);   // no blanks
obj($db, $tpl, 'inputtextsample', $b + ['sob_all_id' => 'cus_city', 'sob_all_label' => 'City', 'sob_all_top' => $nextY()]);
obj($db, $tpl, 'lookupsample', $b + ['sob_all_id' => 'cus_country_id', 'sob_all_label' => 'Country', 'sob_all_top' => $nextY(), 'sob_all_width' => 60,
	'sob_lookup_zzzzsys_form_id' => 'tcountry', 'sob_lookup_code' => 'cty_code', 'sob_lookup_description' => 'cty_name', 'sob_lookup_description_width' => '150']);
obj($db, $tpl, 'inputcheckboxsample', $b + ['sob_all_id' => 'cus_active', 'sob_all_label' => 'Active', 'sob_all_top' => $nextY(), 'sob_all_width' => 20, 'sob_input_format' => '']);
obj($db, $tpl, 'inputnudatesample', $b + ['sob_all_id' => 'cus_since', 'sob_all_label' => 'Since', 'sob_all_top' => $nextY(), 'sob_all_width' => 100, 'sob_input_format' => 'D|dd-mm-yyyy']);
obj($db, $tpl, 'inputnunumbersample', $b + ['sob_all_id' => 'cus_credit', 'sob_all_label' => 'Credit', 'sob_all_top' => $nextY(), 'sob_all_width' => 100, 'sob_input_format' => 'N|1,000.00']);
obj($db, $tpl, 'selectsample', $b + ['sob_all_id' => 'cus_type', 'sob_all_label' => 'Type', 'sob_all_top' => $nextY(), 'sob_select_sql' => "SELECT 'A', 'Type A' UNION SELECT 'B', 'Type B'"]);
obj($db, $tpl, 'selectmultiselectsample', $b + ['sob_all_id' => 'cus_tags', 'sob_all_label' => 'Tags', 'sob_all_top' => $nextY(), 'sob_select_sql' => 'x|Tag X|y|Tag Y|z|Tag Z']);
$y += 30;
obj($db, $tpl, 'inputnuautonumbersample', $b + ['sob_all_id' => 'cus_number', 'sob_all_label' => 'Number', 'sob_all_top' => $nextY(), 'sob_all_width' => 80]);
obj($db, $tpl, 'textareasample', $b + ['sob_all_id' => 'cus_notes', 'sob_all_label' => 'Notes', 'sob_all_top' => $nextY(), 'sob_all_height' => 60]);
$y += 40;
obj($db, $tpl, 'displaysample', $b + ['sob_all_id' => 'disp_orders', 'sob_all_label' => 'Orders', 'sob_all_top' => $nextY(), 'sob_all_width' => 60, 'sob_input_format' => '',
	'sob_display_sql' => "SELECT COUNT(*) FROM torder WHERE ord_customer_id = '#RECORD_ID#'"]);
obj($db, $tpl, 'htmlsample', $b + ['sob_all_id' => 'html_test', 'sob_all_label' => 'HTML', 'sob_all_top' => $nextY(), 'sob_all_height' => 40, 'sob_input_format' => '',
	'sob_html_code' => "<div id=\"tHtmlOut\">no script</div>\n<script>\nwindow.T = window.T || {};\nT.htmlScriptStarted = true;\n\$('#tHtmlOut').text('html script ran');\nT.htmlScriptJq = true;\nT.htmlScriptNu = nuGetValue('cus_code');\n</script>"]);
$y += 20;
obj($db, $tpl, 'wordsample', $b + ['sob_all_id' => 'word_test', 'sob_all_label' => 'A word object', 'sob_all_top' => $nextY()]);
obj($db, $tpl, 'inputbuttonsample', $b + ['sob_all_id' => 'btn_hidden', 'sob_all_label' => 'Run hidden PHP', 'sob_all_top' => $nextY(), 'sob_input_format' => '']);
obj($db, $tpl, 'run_add_note', $b + ['sob_all_id' => 'run_proc', 'sob_all_label' => 'Run procedure', 'sob_all_top' => $nextY(), 'sob_run_type' => 'P', 'sob_run_zzzzsys_form_id' => 'tproc_hello', 'sob_run_id' => '', 'sob_run_method' => 'b']);
obj($db, $tpl, 'inputfilesample', $b + ['sob_all_id' => 'cus_file', 'sob_all_label' => 'File', 'sob_all_top' => $nextY(), 'sob_input_format' => '']);
// orders tab
$b2 = ['sob_all_zzzzsys_form_id' => 'tcustomer', 'sob_all_zzzzsys_tab_id' => 'tt_cust_2', 'sob_all_left' => 20];
obj($db, $tpl, 'subformsample', $b2 + ['sob_all_id' => 'sub_orders', 'sob_all_label' => 'Orders', 'sob_all_top' => 30, 'sob_all_width' => 600, 'sob_all_height' => 200,
	'sob_subform_zzzzsys_form_id' => 'torder_sf', 'sob_subform_foreign_key' => 'ord_customer_id', 'sob_subform_add' => '1', 'sob_subform_delete' => '1', 'sob_subform_type' => 'g']);
obj($db, $tpl, 'calcsample', $b2 + ['sob_all_id' => 'calc_qty', 'sob_all_label' => 'Total qty', 'sob_all_top' => 260, 'sob_all_left' => 150, 'sob_all_width' => 80,
	'sob_calc_formula' => "nuTotal('sub_orders.ord_qty')", 'sob_calc_format' => '']);
// object event: onchange of cus_type
ins($db, 'zzzzsys_event', ['zzzzsys_event_id' => 'tev_type', 'sev_zzzzsys_object_id' => oid('tcustomer', 'cus_type'), 'sev_event' => 'onchange',
	'sev_javascript' => "T.typeChanged = nuGetValue('cus_type'); nuSetValue('cus_city', 'changed-' + nuGetValue('cus_type'));"]);
ins($db, 'zzzzsys_event', ['zzzzsys_event_id' => 'tev_btn', 'sev_zzzzsys_object_id' => oid('tcustomer', 'btn_hidden'), 'sev_event' => 'onclick',
	'sev_javascript' => "nuRunPHPHidden('tproc_hello', 0);"]);
// form PHP events
php($db, 'tcustomer_BE', "nuAddJavaScript(\"window.T = window.T || {}; T.beRecord = '#RECORD_ID#'; T.beUser = '#USER_ID#';\");");
php($db, 'tcustomer_BS', "if (trim('#cus_name#') == 'FAIL') { nuDisplayError('Name must not be FAIL'); }");
php($db, 'tcustomer_AS', "nuRunQuery('INSERT INTO tlog VALUES (?, ?, NOW())', [nuID(), 'AS #RECORD_ID# name=#cus_name# new=#NEW_RECORD#']);");
php($db, 'tcustomer_BD', "nuRunQuery('INSERT INTO tlog VALUES (?, ?, NOW())', [nuID(), 'BD #RECORD_ID#']);");
php($db, 'tcustomer_BB', "nuRunQuery('INSERT INTO tlog VALUES (?, ?, NOW())', [nuID(), 'BB #FORM_ID#']);");
php($db, 'tproc_hello', "nuJavaScriptCallback(\"window.T = window.T || {}; T.procRan = '#cus_code#'; \");\necho 'procedure output for #cus_code#';", ['sph_run' => 'hide', 'sph_zzzzsys_form_id' => 'tcustomer']);

// ---------------------------------------------------------------- browse variants (browse forms that open tcustomer)
$variants = [
	'tb_qualified'   => ["SELECT * FROM tcustomer LEFT JOIN tcountry ON country_id = cus_country_id", [['Name', 'tcustomer.cus_name'], ['Country', 'tcountry.cty_name']]],
	'tb_tablealias'  => ["SELECT * FROM tcustomer c LEFT JOIN tcountry t ON t.country_id = c.cus_country_id", [['Name', 'c.cus_name'], ['Country', 't.cty_name']]],
	'tb_alias'       => ["SELECT * FROM tcustomer", [['Code', 'cus_code'], ['Full name', "CONCAT(cus_code, ' - ', cus_name) AS full_name"]]],
	'tb_nopk'        => ["SELECT cus_code, cus_name FROM tcustomer", [['Code', 'cus_code'], ['Name', 'cus_name']]],
	'tb_dupcol'      => ["SELECT * FROM tcustomer LEFT JOIN tnote ON tnote.cus_code = tcustomer.cus_code", [['Name', 'cus_name'], ['Note', 'nte_text']]],
	'tb_filter'      => ["SELECT * FROM tcustomer WHERE nu_filter_match('cus_type', 'tbfiltercoltype')", [['Name', 'cus_name'], ['Type', 'cus_type', '', 'tbfiltercoltype']]],
	'tb_hash'        => ["SELECT * FROM tcustomer WHERE cus_active = '1' AND '#USER_ID#' <> '' AND '#FORM_ID#' = 'tb_hash'", [['Name', 'cus_name']]],
	'tb_titleselect' => ["SELECT * FROM tcustomer WHERE (cus_type = '#nuBrowseTitle1_select#' OR LEFT('#nuBrowseTitle1_select#',1) = '#' OR '#nuBrowseTitle1_select#' = '')", [['Name', 'cus_name'], ['Type', 'cus_type']]],
];
foreach ($variants as $id => [$sql, $cols]) {
	form($db, $formTpl, ['zzzzsys_form_id' => $id, 'sfo_type' => 'browse', 'sfo_code' => $id, 'sfo_description' => $id, 'sfo_table' => 'tcustomer', 'sfo_primary_key' => 'customer_id',
		'sfo_browse_sql' => $sql, 'sfo_browse_redirect_form_id' => 'tcustomer',
		'sfo_browse_javascript' => $id === 'tb_titleselect' ? "nuAddBrowseTitleSelect(1, [['', 'All'], ['A', 'Type A'], ['B', 'Type B']]);" : null]);
	browse($db, $id, $cols);
}
echo "test application built in $dbName\n";
