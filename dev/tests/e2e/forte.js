// e2e tests: nuBuilder Forte compatibility – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

T.current_props = async (s) => {
	// 0.8.981: nuCurrentProperties() / nuFORM.getCurrent() carry nuBuilder Forte's breadcrumb keys and value types (list and edit form)
	const p = s.ga;
	const FORTE_KEYS = ['browse_autoresize_columns', 'browse_columns', 'browse_filtered_rows', 'browse_rows', 'browse_sql', 'browse_table_id', 'browse_target',
		'browse_title_multiline', 'call_type', 'column_widths', 'data_mode', 'form_code', 'form_description', 'form_group', 'form_id', 'form_type', 'forms', 'iframe',
		'lookup_id', 'mobile_view', 'nosearch_columns', 'object_id', 'page_number', 'pages', 'password', 'record_id', 'redirect_form_id', 'redirect_other_form_id',
		'refreshed', 'row_height', 'rows', 'run_code', 'run_description', 'search', 'session_id', 'sort', 'sort_direction', 'subforms', 'tab_start', 'title', 'user_id', 'username'];
	await open(p, 'index.php?p=form&f=nuuser'); await p.waitForSelector('#nuBrowseTable');
	let c = await p.evaluate(() => nuCurrentProperties());
	const miss = FORTE_KEYS.filter(k => !(k in c));
	expect(!miss.length, 'list: Forte keys present – missing ' + miss.join(', '));
	expect(c.rows === 20 && c.row_height === 18 && c.redirect_form_id === 'nuuser' && c.sort === '-1' && c.sort_direction === 'desc' && c.object_id === '1' && Array.isArray(c.tab_start) && !c.tab_start.length,
		'list: defaults as Forte ' + JSON.stringify({rows: c.rows, rh: c.row_height, r: c.redirect_form_id, s: c.sort, d: c.sort_direction}));
	const col = c.browse_columns[0] || {};
	expect(['title', 'display', 'align', 'width', 'order', 'format', 'id'].every(k => k in col) && col.display !== '' && /^[lrc]$/.test(col.align), 'browse_columns as stored: ' + JSON.stringify(col));
	expect(Array.isArray(c.column_widths) && c.column_widths.length === c.browse_columns.length && c.column_widths[0] === Number(col.width), 'column_widths: ' + JSON.stringify(c.column_widths).slice(0, 80));
	expect(JSON.stringify(c.browse_columns) === JSON.stringify(await p.evaluate(() => nuFORM.getCurrent().browse_columns)), 'getCurrent = nuCurrentProperties');
	const id = await p.evaluate(() => nuCurrentProperties().browse_rows[0][0]);
	await open(p, 'index.php?p=form&f=nuuser&r=' + id); await p.waitForSelector('#nuEditForm');
	c = await p.evaluate(() => nuCurrentProperties());
	expect(!/Warning:|Notice:/.test(await p.evaluate(() => document.documentElement.outerHTML.slice(0, 400) + document.body.innerText)), 'edit page without PHP messages');
	expect(c.record_id === id && c.browse_rows === 0 && c.browse_sql === 0 && c.column_widths === 0 && c.pages === 0 && Array.isArray(c.browse_columns) && !c.browse_columns.length,
		'edit: list keys 0 / [] as Forte');
	expect(Array.isArray(c.tab_start) && c.tab_start[0] && c.tab_start[0].tabNumber === '0' && c.tab_start[0].prefix === '', 'edit: tab_start ' + JSON.stringify(c.tab_start));
	expect(await p.evaluate(() => { nuFORM.setProperty('rows', 7); return nuCurrentProperties().rows === 7 && nuFORM.getProperty('rows') === 7; }), 'setProperty on a breadcrumb key');
};

T.run_query_error = async (s) => {
	// 0.8.982 (the owner's decision): a failing nuRunQuery returns -1, writes the Debug results, nuGetLastError() describes it, the code goes on
	const p = s.ga;
	const code = "$a = nuRunQuery('INSERT INTO e2e_no_such_table (x) VALUES (1)');\n$e = nuGetLastError();\n$n = nuRunQueryNoDebug('SELECT * FROM e2e_no_such_table2');\n"
		+ "$b = db_fetch_row(nuRunQuery('SELECT 7'))[0];\nnuAddJavascript('window.e2eRq = ' . json_encode([$a, $e ? $e->message : null, $e ? $e->sql : null, $b, $n]) . ';');";
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_rq'");
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('e2e_rq', 'e2e_rq', 'e2e', 'hide', '1', '0', " + JSON.stringify(code) + ")"});
	const before = Number(sql("SELECT COUNT(*) FROM zzzzsys_debug WHERE deb_message LIKE '%e2e_no_such_table%'") || 0);
	try {
		await open(p, 'index.php?p=form&f=nuuser'); await p.waitForSelector('#nuBrowseTable');
		await p.evaluate(() => { window.e2eRq = null; nuRunPHPHidden('e2e_rq'); });
		await p.waitForFunction(() => window.e2eRq !== null, null, {timeout: 8000});
		const r = await p.evaluate(() => window.e2eRq);
		expect(r[0] === -1 && /e2e_no_such_table/.test(r[1] || '') && /INSERT INTO e2e_no_such_table/.test(r[2] || '') && String(r[3]) === '7' && r[4] === null, 'nuRunQuery error as Forte: ' + JSON.stringify(r));
		const rows = sql("SELECT deb_message FROM zzzzsys_debug WHERE deb_message LIKE '%e2e_no_such_table%' ORDER BY deb_added DESC");
		expect(Number(sql("SELECT COUNT(*) FROM zzzzsys_debug WHERE deb_message LIKE '%e2e_no_such_table%'")) === before + 1 && /===PDO MESSAGE===/.test(rows) && /===SQL===/.test(rows) && !/e2e_no_such_table2/.test(rows),
			'one Debug result (none for nuRunQueryNoDebug): ' + String(rows).slice(0, 160));
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_rq'; DELETE FROM zzzzsys_debug WHERE deb_message LIKE '%e2e_no_such_table%'");
	}
};

T.load_wait_handlers = async (s) => {
	// 0.8.983 (the owner: B + check point): handlers of form code waiting for the page (DOMContentLoaded, readystatechange, window load,
	// window.onload =) never run in nuBuilder Forte – dropped here too; $(fn) and nuOnLoad still run; Compatibility check G11 lists the form
	const p = s.ga;
	const cus = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_name LIMIT 1");
	if (!cus) { s.skip = 'no sample data'; return; }
	const old = sql("SELECT IFNULL(REPLACE(TO_BASE64(sfo_javascript), '\\n', ''), 'NULL') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	const js = "window.e2eLw = [];\ndocument.addEventListener('DOMContentLoaded', function () { e2eLw.push('dcl'); });\nwindow.addEventListener('load', function () { e2eLw.push('load'); });\n"
		+ "window.onload = function () { e2eLw.push('onload'); };\n$(window).on('load', function () { e2eLw.push('jqload'); });\n$(function () { e2eLw.push('ready'); });\n"
		+ "document.querySelector('body').addEventListener('click', function () {});\nfunction nuOnLoad() { e2eLw.push('nuOnLoad'); }";
	execSync('mysql ' + DB, {input: "UPDATE zzzzsys_form SET sfo_javascript = " + JSON.stringify(js) + " WHERE zzzzsys_form_id = 'samplecustomer'"});
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cus); await p.waitForSelector('#nuSaveButton');
		await p.waitForLoadState('load'); await p.waitForTimeout(600);
		const r = await p.evaluate(() => ({lw: window.e2eLw.slice().sort(), own: Object.prototype.hasOwnProperty.call(document, 'addEventListener'), onload: window.onload}));
		expect(JSON.stringify(r.lw) === JSON.stringify(['nuOnLoad', 'ready']) && !r.own && r.onload === null, 'only $(fn) and nuOnLoad ran: ' + JSON.stringify(r));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
		await open(p, 'index.php?p=compat');
		const g = await p.evaluate(() => { const tr = document.querySelector('tr[data-finding="G11"]'); return tr ? tr.textContent.replace(/\s+/g, ' ') : ''; });
		expect(/sample_customer|samplecustomer|DOMContentLoaded/.test(g), 'Compatibility check G11: ' + g.slice(0, 200));
	} finally {
		execSync('mysql ' + DB, {input: "UPDATE zzzzsys_form SET sfo_javascript = " + (old === 'NULL' ? 'NULL' : "FROM_BASE64('" + old + "')") + " WHERE zzzzsys_form_id = 'samplecustomer'"});
	}
};

T.hash_forte = async (s) => {
	// 0.8.984: nuHash() as nuBuilder Forte – record values as text, page state keys, USER_A11Y, nuFORMdata in a procedure from an
	// edit form (main form first with nuDelete second, sub-form rows, checkboxes 0 / 1); no sub-form titles among the main fields;
	// a select whose chosen option is disabled has no value (nuSelectAddEnglishOption)
	const p = s.ga;
	const ord = sql("SELECT sample_order_id FROM sample_order ORDER BY ord_number LIMIT 1");
	if (!ord) { s.skip = 'no sample data'; return; }
	const code = "$h = nuHash(); $fd = $h['nuFORMdata'] ?? null;\nnuAddJavascript('window.e2eHf = ' . json_encode(['keys' => array_keys($h), 'rows' => $h['rows'] ?? null, 'title' => $h['title'] ?? null, 'a11y' => $h['USER_A11Y'] ?? 'x', "
		+ "'fd' => $fd ? array_map(fn($d) => [$d->id, array_slice($d->fields, 0, 2), count($d->rows), count($d->edited[0] ?? []) === count($d->fields)], $fd) : null]) . ';');";
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_hf'");
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('e2e_hf', 'e2e_hf', 'e2e', 'hide', '1', '0', " + JSON.stringify(code) + ")"});
	try {
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord); await p.waitForSelector('#nuSaveButton');
		await p.evaluate(() => { window.e2eHf = null; nuRunPHPHidden('e2e_hf'); });
		await p.waitForFunction(() => window.e2eHf !== null, null, {timeout: 8000});
		const r = await p.evaluate(() => window.e2eHf);
		const fd = r.fd || [];
		expect(fd.length >= 2 && fd[0][0] === 'nuBuilder4EditForm' && JSON.stringify(fd[0][1]) === '["ID","nuDelete"]' && fd[1][2] > 0 && fd.every(d => d[3]), 'nuFORMdata as Forte: ' + JSON.stringify(fd));
		expect(['title', 'search', 'sort', 'row_height', 'SUBFORM_ID', 'params'].every(k => r.keys.includes(k)) && r.a11y === false && typeof r.title === 'string' && r.title !== '', 'page state keys: ' + JSON.stringify({rows: r.rows, title: r.title, a11y: r.a11y}));
		const mv = await p.evaluate(() => { const v = nuNext.mainValues(); return Object.keys(v).filter(k => document.getElementById(k) === null || ['INPUT', 'SELECT', 'TEXTAREA'].indexOf(document.getElementById(k).tagName) < 0); });
		expect(!mv.length, 'main values only form controls: ' + mv.join(','));
		const sel = await p.evaluate(() => { const s = document.querySelector('#nuEditForm select[data-nu-field]:not([multiple])'); if (!s) return 'none'; const o = document.createElement('option'); o.value = 'zz'; o.disabled = true; s.appendChild(o); s.selectedIndex = s.options.length - 1; const v = nuNext.rawValue(s); o.remove(); return v; });
		expect(sel === '' || sel === 'none', 'disabled chosen option has no value: ' + sel);
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_hf'");
	}
};

// nuBuilder compatibility (0.8.443): nuReturnNewRecord() in After Save, nuSetSaveButtonPosition(), jQuery top / left of fields in the
// absolute layout go to the wrapper (label and field move together)
T.compat_save_position = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	if (sql("SELECT COALESCE(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'").includes('"layout":"grid"')) { s.skip = 'form not absolute'; return; }
	const id = sql("SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1");
	await open(p, 'index.php?p=form&f=' + F + '&r=' + encodeURIComponent(id) + '&layout=absolute');
	const r = await p.evaluate(() => {
		const out = {};
		nuSetSaveButtonPosition(300, 200, 30, 140, 13);
		const b = document.getElementById('nuSaveButton');
		out.btn = !!b.closest('#nuRECORD') && b.style.top === '300px' && b.style.left === '200px' && b.style.fontSize === '13px';
		const w = document.getElementById('nuw_cus_name');
		$('#cus_name').css('top', 400); $('#label_cus_name').css({top: 400});
		const lab = document.getElementById('label_cus_name');
		out.top = w.style.top === '400px' && $('#cus_name').css('top') === '400px' && !document.getElementById('cus_name').style.top && lab.style.top === '0px' && $('#label_cus_name').css('top') === '400px';
		$('#cus_name').css('left', 250);
		out.left = w.style.left === '250px';
		// a label placed above its field in form coordinates (0.8.965, as nuBuilder Forte), kept when the field moves
		$('#label_cus_name').css({top: 380, left: 240}); $('#cus_name').css('top', 420);
		out.labelAbove = lab.style.top === '-40px' && lab.style.left === '-10px' && $('#label_cus_name').css('top') === '380px';
		out.other = $('#nuSaveButton').css('top') === '300px';   // elements outside a wrapper keep their own position
		return out;
	});
	expect(r.btn && r.top && r.left && r.other && r.labelAbove, 'save button / css mapping: ' + JSON.stringify(r));
	// nuReturnNewRecord() in After Save: the form opens a new record
	const AS = F + '_AS', had = +sql("SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id = '" + AS + "'");
	const old = had ? sql("SELECT HEX(sph_php) FROM zzzzsys_php WHERE zzzzsys_php_id = '" + AS + "'") : '';
	if (had) sql("UPDATE zzzzsys_php SET sph_php = 'nuReturnNewRecord();' WHERE zzzzsys_php_id = '" + AS + "'");
	else sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_group, sph_system) VALUES ('" + AS + "', '" + AS + "', 'e2e', 'nuReturnNewRecord();', 'nubuilder', '1')");
	try {
		await open(p, 'index.php?p=form&f=' + F + '&r=' + encodeURIComponent(id));
		await p.evaluate(() => { const e = document.getElementById('cus_phone'); e.value = (e.value || '') + ' '; e.dispatchEvent(new Event('change', {bubbles: true})); });
		await Promise.all([p.waitForNavigation(), p.click('#nuSaveButton')]); await p.waitForLoadState('networkidle');
		expect(new URL(p.url()).searchParams.get('r') === '-1', 'After Save → new record: ' + p.url());
	} finally {
		if (had) sql("UPDATE zzzzsys_php SET sph_php = " + (old === '' || old === 'NULL' ? 'NULL' : "UNHEX('" + old + "')") + " WHERE zzzzsys_php_id = '" + AS + "'");
		else sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = '" + AS + "'");
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.nuscroll = async (s) => {                               // 0.8.369: nuScroll – ↑ / ↓ through the list, suggestions for phones
	const p = s.ga, id = sql('SELECT sample_all_id FROM sample_all ORDER BY 1 LIMIT 1');
	if (sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleall' AND sob_input_type = 'nuScroll'") === '0') { s.skip = 'no nuScroll field'; return; }
	await open(p, 'index.php?p=form&f=sampleall&r=' + id);
	const f = p.locator('#all_scroll');
	await f.evaluate(e => { const t = e.closest('.tab-pane'); if (t && !t.classList.contains('show')) document.querySelector('[data-bs-target="#' + t.id + '"], [href="#' + t.id + '"]')?.click(); });
	await f.fill('');
	const seq = [];
	for (const k of ['ArrowDown', 'ArrowDown', 'ArrowUp', 'ArrowUp']) { await f.press(k); seq.push(await f.inputValue()); }
	expect(seq.join(',') === 'Low,Medium,Low,High', 'arrow keys: ' + seq.join(','));
	const dl = await f.evaluate(e => { const l = e.list; return l ? [...l.options].map(o => o.value).join(',') : ''; });
	expect(dl === 'Low,Medium,High', 'suggestions (datalist): ' + dl);
	await f.fill('Custom value');                               // text that is not in the list stays allowed
	await f.press('ArrowDown'); expect(await f.inputValue() === 'Low', 'arrow after own text');
	await save(p);
	expect(sql("SELECT all_scroll FROM sample_all WHERE sample_all_id = '" + id + "'") === 'Low', 'nuScroll value not saved');
	const m = await login(GA[0], GA[1], { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });   // phone: the list is attached
	await open(m, 'index.php?p=form&f=sampleall&r=' + id);
	expect(await m.locator('#all_scroll[list]').count() === 1, 'no suggestion list on a phone');
	await m.context().close();
};

T.select2_api = async (s) => {                           // 0.8.370: nuSetSelect2() and $(…).select2() of form code → Tom Select
	const p = s.ga, id = sql('SELECT sample_all_id FROM sample_all ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=sampleall&r=' + id);
	const r = await p.evaluate(() => {
		const out = {};
		out.ret = nuSetSelect2('all_select', {width: 220});
		out.ts1 = !!document.getElementById('all_select').tomselect && !!document.getElementById('all_select_select2');
		$('#all_select_sql').select2({placeholder: 'Choose'});
		const el = document.getElementById('all_select_sql');
		out.ts2 = !!el.tomselect;
		$('#all_select_sql').select2('open'); out.open = $('#all_select_sql').select2('isOpen'); $('#all_select_sql').select2('close');
		const v = [...el.options].map(o => o.value).filter(Boolean).pop();
		$('#all_select_sql').val(v).trigger('change');
		out.v = v; out.tsValue = el.tomselect.getValue(); out.data = ($('#all_select_sql').select2('data')[0] || {}).id;
		return out;
	});
	expect(r.ret === 'all_select_select2' && r.ts1, 'nuSetSelect2: ' + JSON.stringify(r));
	expect(r.ts2 && r.open === true, '$.fn.select2: ' + JSON.stringify(r));
	expect(r.tsValue === r.v && r.data === r.v, 'value set by code not taken over: ' + JSON.stringify(r));
	await p.evaluate(() => nuHasBeenEdited && document.getElementById('all_select_sql').dispatchEvent(new Event('change', { bubbles: true })));
	await save(p);
	expect(sql("SELECT all_select_sql FROM sample_all WHERE sample_all_id = '" + id + "'") === r.v, 'value not saved');
	expect(!p.errors.length, 'JavaScript errors: ' + p.errors.join(' | '));
};

// nuBuilder 4.9 compatibility (0.8.382, dev/tests/nub49 = the test application of the compatibility report): browse lists with table names,
// aliases, AS, no primary key, shared column names, nu_filter_match, nuAddBrowseTitleSelect, multi-value filter; access condition (access 9)
T.nub49_app = async (s) => {
	const db = (DB.match(/(?:^|\s)([A-Za-z0-9_]+)\s*$/) || [])[1] || 'nutest';
	const user = (DB.match(/-u\s*(\S+)/) || [])[1] || 'nu', pass = (DB.match(/-p(\S+)/) || [])[1] || 'nu';
	try {
		for (const f of ['build_app.php', 'build_app2.php']) execSync('php ' + JSON.stringify(path.join(__dirname, 'nub49', f)) + ' ' + db, { env: Object.assign({}, process.env, { DBUSER: user, DBPASS: pass }) });
		const p = s.ga;
		const rows = () => p.evaluate(() => document.querySelectorAll('.nuBrowseTable tbody tr, table tbody tr[data-nu-pk]').length);
		const want = { tcustomer: 5, tb_qualified: 5, tb_tablealias: 5, tb_alias: 5, tb_nopk: 5, tb_dupcol: 5, tb_filter: 5, tb_hash: 4, tb_titleselect: 5, tcountry: 3 };
		for (const [f, n] of Object.entries(want)) {
			await open(p, 'index.php?p=form&f=' + f);
			const err = await p.locator('.alert-danger').count();
			expect(!err && await rows() === n, f + ': ' + (err ? await p.locator('.alert-danger').first().textContent() : (await rows()) + ' rows, expected ' + n));
		}
		// alias column sorted by its expression; full name shown
		await open(p, 'index.php?p=form&f=tb_alias&sort=1&dir=desc');
		expect(/C005 - Epsilon/.test(await p.locator('tbody tr').first().textContent()), 'AS alias column: sort / value');
		// nuAddBrowseTitleSelect → #nuBrowseTitle1_select#
		await open(p, 'index.php?p=form&f=tb_titleselect');
		await Promise.all([p.waitForNavigation(), p.selectOption('#nuBrowseTitle1_select', 'B')]); await p.waitForLoadState('networkidle');
		expect(await rows() === 2 && await p.locator('#nuBrowseTitle1_select').inputValue() === 'B', 'nuAddBrowseTitleSelect filter');
		// nu_filter_match with a multi-value filter
		await open(p, 'index.php?p=form&f=tb_filter');
		await p.evaluate(() => nuAddBrowseFilter('tbfiltercoltype').nuSearchablePopup({ items: [['A', 'Type A'], ['B', 'Type B']], multiSelect: true }));
		await p.click('.nuBrowseFilterMulti summary'); await p.check('.nuBrowseFilterMenu input[value="A"]');
		await Promise.all([p.waitForNavigation(), p.click('.nuBrowseFilterMenu .btn-primary')]); await p.waitForLoadState('networkidle');
		expect(await rows() === 3, 'nu_filter_match / multiSelect: ' + await rows());
		// access condition: SELECT 2 = hidden, globeadmin → 1 = read only; neither is taken from the browser when saving
		await open(p, 'index.php?p=form&f=tadv&r=tu1');
		const st = await p.evaluate(() => ({ hidden: document.getElementById('nuw_cus_code')?.style.display === 'none', ro: !!(document.getElementById('cus_name')?.readOnly || document.getElementById('cus_name')?.disabled) }));
		expect(st.hidden && st.ro, 'access condition not applied: ' + JSON.stringify(st));
		await p.evaluate(() => { for (const [id, v] of [['cus_code', 'HACK1'], ['cus_name', 'HACK2'], ['cus_city', 'Bern']]) { const e = document.getElementById(id); e.readOnly = false; e.disabled = false; e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); } });
		await save(p);
		const row = sql("SELECT CONCAT(cus_code, '|', cus_name, '|', cus_city) FROM tcustomer WHERE customer_id = 'tu1'");
		expect(row === 'C001|Alpha AG|Bern', 'access condition: protected fields saved from the browser – ' + row);
		// the Compatibility check names the features and their state
		await open(p, 'index.php?p=compat');
		for (const id of ['C1', 'C2', 'C5', 'C6', 'M1']) expect(await p.locator('#compat_findings [data-finding="' + id + '"]').count() === 1, 'Compatibility check: ' + id + ' not listed');
		expect(/0\.8\.382/.test(await p.locator('#compat_findings [data-finding="C6"]').textContent()), 'C6 not marked as supported');
		// design mode (0.8.670, before: the builder) offers access 9 with its condition, and saving the object keeps the condition
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'tadv'");
		await open(p, 'index.php?p=form&f=tadv&r=-1&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(600);
		await p.click('#nuDsRailStructure'); await p.waitForTimeout(300);
		await p.click('#nuDsTree .nuDsTreeRow[data-id="cus_name"]'); await p.waitForTimeout(500); await p.click("#nuDsFly .btn-close");
		expect(await p.locator('#nuDsPanel select[data-key=sob_all_access]').inputValue() === '9', 'design: access 9 not shown');
		expect(await p.locator('#nuDsPanel [data-key=sob_all_access_condition]').count() === 1, 'design: no condition field');
		await p.fill('#nuDsPanel [data-key=sob_all_label]', 'E2E access 9'); await p.press('#nuDsPanel [data-key=sob_all_label]', 'Enter'); await p.waitForTimeout(1500);
		const cond = sql("SELECT CONCAT(COALESCE(sob_all_access_condition, ''), (SELECT COALESCE(MAX(snapshot), '') FROM zzzzsys_nunext_form_draft WHERE form_id = 'tadv' AND status = 'open')) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'tadv' AND sob_all_id = 'cus_name'");
		expect(/E2E access 9/.test(cond) && (cond.match(/globeadmin/g) || []).length >= 1, 'design: label not saved or condition lost');
		expect(!/"sob_all_access_condition":(null|"")/.test(cond.replace(/\\/g, '')) || /globeadmin/.test(cond), 'design: condition lost on save');
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'tadv'");
	} finally {
		sql("DELETE FROM zzzzsys_event WHERE zzzzsys_event_id LIKE 'tev%'; DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id IN ('tcountry','tcustomer','torder_sf','tb_qualified','tb_alias','tb_nopk','tb_dupcol','tb_tablealias','tb_filter','tb_hash','tb_titleselect','tadv');"
			+ " DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id IN ('tcountry','tcustomer','torder_sf','tadv'); DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id LIKE 't%' AND zzzzsys_browse_id LIKE 'tb\\_%';"
			+ " DELETE FROM zzzzsys_form WHERE zzzzsys_form_id IN ('tcountry','tcustomer','torder_sf','tb_qualified','tb_alias','tb_nopk','tb_dupcol','tb_tablealias','tb_filter','tb_hash','tb_titleselect','tadv');"
			+ " DELETE FROM zzzzsys_php WHERE zzzzsys_php_id LIKE 'tcustomer\\_%' OR zzzzsys_php_id IN ('tproc_hello','tsel_proc') OR zzzzsys_php_id LIKE 'to%\\_AB';"
			+ " DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'taccess'; DELETE FROM zzzzsys_access WHERE zzzzsys_access_id = 'taccess'; DELETE FROM zzzzsys_user WHERE zzzzsys_user_id IN ('tuser1','tuser2');"
			+ " DELETE FROM zzzzsys_translate WHERE zzzzsys_translate_id LIKE 'ttr%'; DROP TABLE IF EXISTS tcountry, tcustomer, torder, tlog, tnote");
		// the setup header function of build_app2.php is removed with the whole database at the end of the suite
	}
};

// nuBuilder 4.9 form-page JavaScript (0.8.383, compatibility report M1, M7, M8, N1–N4): dev/tests/nub49/build_app3.php = form tjs
T.nub49_js = async (s) => {
	const db = (DB.match(/(?:^|\s)([A-Za-z0-9_]+)\s*$/) || [])[1] || 'nutest';
	const user = (DB.match(/-u\s*(\S+)/) || [])[1] || 'nu', pass = (DB.match(/-p(\S+)/) || [])[1] || 'nu';
	try {
		for (const f of ['build_app.php', 'build_app3.php']) execSync('php ' + JSON.stringify(path.join(__dirname, 'nub49', f)) + ' ' + db, { env: Object.assign({}, process.env, { DBUSER: user, DBPASS: pass }) });
		const p = s.ga;
		const errs = []; const onErr = (e) => errs.push(e.message); p.on('pageerror', onErr);
		await open(p, 'index.php?p=form&f=tjs&r=tu1'); await p.waitForTimeout(500);
		const st = await p.evaluate(() => ({ T: window.T, box: document.getElementById('tHtmlBox').textContent, tpl: document.getElementById('tTpl').type,
			edited: !nuIsSaved(), red: document.getElementById('nuSaveButton').classList.contains('nuSaveEdited') }));
		expect(st.T.htmlJq === 'function' && st.box === 'set by script' && st.T.htmlFn === 'html ok', 'M1: script of an HTML object ' + JSON.stringify(st));
		expect(st.tpl === 'text/x-template', 'M1: other script types must stay');
		expect(st.T.prop === 'tjs' && st.T.sr === 'tjs' && st.T.main === true, 'M8 / N1: nuFORM / nuSERVERRESPONSE ' + JSON.stringify(st.T));
		expect(st.T.editedInLoad === true && !st.edited && !st.red, 'N4: values set while loading must not mark the form as edited');
		await p.fill('#cus_name', 'Alpha AG js'); await p.dispatchEvent('#cus_name', 'change');
		await save(p);
		expect(await p.evaluate(() => window.T.afterSave) === 'tu1|1', 'M7: nuAfterSave not run on the reloaded form');
		expect(sql("SELECT CONCAT(cus_name, '|', cus_city) FROM tcustomer WHERE customer_id = 'tu1'") === 'Alpha AG js|Loaded', 'value set while loading not saved');
		// subform filter hooks, nuFormatValueCleared (N2) and nuAddActionCheckbox (N3)
		await open(p, 'index.php?p=form&f=tcustomer&r=tu1');
		const r = await p.evaluate(() => {
			const out = {};
			window.nuSubformFilterOnAddValue = (sf, add, text, value) => ({ add: true, text: text + '!', value: value });
			window.nuSubformOnFilterRows = (sf, data, row) => row === 0;
			nuSubformAddFilter({ sub_orders: ['ord_product'] });
			const sel = document.getElementById('sub_ordersord_product_filter');
			sel.dispatchEvent(new Event('focus')); out.bang = Array.from(sel.options).some(o => /!$/.test(o.text));
			sel.dispatchEvent(new Event('change'));
			out.hidden = Array.from(document.querySelectorAll('#sub_orders .nuSubformRow.nuFilteredOut')).map(x => x.id).join();
			let cleared = null; window.nuFormatValueCleared = ($e, v) => { cleared = v; };
			const d = document.getElementById('cus_since'); d.value = '31-02-2026'; d.dispatchEvent(new Event('change', { bubbles: true }));
			out.cleared = cleared + '|' + d.value;
			nuAddActionCheckbox({ id: 'tChk', text: 'Only open' }); document.getElementById('tChk').click(); out.chk = nuGetProperty('tChk_filter');
			return out;
		});
		expect(r.bang && r.hidden === 'sub_orders000', 'N2: subform filter hooks ' + JSON.stringify(r));
		expect(r.cleared === '31-02-2026|', 'N2: nuFormatValueCleared ' + r.cleared);
		expect(r.chk === '1', 'N3: nuAddActionCheckbox');
		p.off('pageerror', onErr);
		expect(errs.length === 0, 'JavaScript errors: ' + errs.join(' · '));
		await open(p, 'index.php?p=compat');
		expect(/0\.8\.383/.test(await p.locator('#compat_findings [data-finding="M1"]').textContent()), 'M1 not marked as supported');
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id IN ('tcountry','tcustomer','torder_sf','tb_qualified','tb_alias','tb_nopk','tb_dupcol','tb_tablealias','tb_filter','tb_hash','tb_titleselect','tjs');"
			+ " DELETE FROM zzzzsys_event WHERE zzzzsys_event_id LIKE 'tev%';"
			+ " DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id IN ('tcountry','tcustomer','torder_sf','tjs'); DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id LIKE 't%' AND zzzzsys_browse_id LIKE 'tb\\_%';"
			+ " DELETE FROM zzzzsys_form WHERE zzzzsys_form_id IN ('tcountry','tcustomer','torder_sf','tb_qualified','tb_alias','tb_nopk','tb_dupcol','tb_tablealias','tb_filter','tb_hash','tb_titleselect','tjs');"
			+ " DELETE FROM zzzzsys_php WHERE zzzzsys_php_id LIKE 'tcustomer\\_%' OR zzzzsys_php_id = 'tproc_hello';"
			+ " DROP TABLE IF EXISTS tcountry, tcustomer, torder, tlog, tnote");
	}
};

// B3: access level data mode 0 (no changes to existing records) and form type 0 / 1 (browse only / edit only), as nuBuilder 4.9
T.gap_access = async (s) => {
	ttInstall();
	let lim, full;
	try {
		lim = await login('ttlim', TT_PW);
		// ttcus: data mode 0 – the record opens read only, no Save; a save from code is refused by the server
		await open(lim, 'index.php?p=form&f=ttcus&r=cus_1');
		expect(lim.url().includes('r=cus_1') && await lim.locator('#nuSaveButton').count() === 0, 'Save shown with data mode 0');
		expect(await lim.locator('[data-nu-field="cus_name"]').first().evaluate(e => e.readOnly || e.disabled), 'field editable with data mode 0');
		const r = await lim.evaluate(async () => {
			const res = await fetch('index.php?p=formapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf },
				body: JSON.stringify({ action: 'save', form_id: 'ttcus', record_id: 'cus_1', values: { cus_name: 'changed by ttlim' }, edited: ['cus_name'] }) });
			return res.status + ' ' + JSON.stringify(await res.json());
		});
		expect(/^403 .*Changes to existing records are not allowed/.test(r), 'save of an existing record not refused: ' + r);
		expect(sql("SELECT cus_name FROM tt_customer WHERE tt_customer_id = 'cus_1'") === 'Alice Muster', 'record changed');
		// a new record is still allowed (nuBuilder: data mode only protects existing records)
		await open(lim, 'index.php?p=form&f=ttcus&r=-1');
		expect(await lim.locator('#nuSaveButton').count() === 1, 'new record without Save under data mode 0');
		// ttcty: form type 0 = browse only – the list works, a record (also a new one) is refused, no Add, rows open nothing
		await open(lim, 'index.php?p=form&f=ttcty');
		expect(await lim.locator('tr.nuBrowseRow').count() > 0 && await lim.locator('#nuAddButton').count() === 0, 'browse only: list or Add wrong');
		const before = lim.url();
		await lim.locator('tr.nuBrowseRow').first().click(); await lim.waitForTimeout(500);
		expect(lim.url() === before, 'browse only: a row opened ' + lim.url());
		for (const rec of ['cty_ch', '-1']) {
			const resp = await open(lim, 'index.php?p=form&f=ttcty&r=' + rec);
			expect(resp.status() === 403 && /only the list or only the edit screen/.test(await lim.textContent('body')), 'browse only: record ' + rec + ' opened (' + resp.status() + ')');
		}
		// edit only (form type 1): the list is refused, a record opens
		sql("UPDATE zzzzsys_access_form SET slf_form_type = '1' WHERE zzzzsys_access_form_id = 'tt_af_lim_ttcty'");
		expect((await open(lim, 'index.php?p=form&f=ttcty')).status() === 403, 'edit only: list opened');
		expect((await open(lim, 'index.php?p=form&f=ttcty&r=cty_ch')).status() === 200, 'edit only: record refused');
		expect((await open(lim, 'index.php?p=formexport&f=ttcty&fmt=xlsx')).status() === 403, 'edit only: list export allowed');
		// the access level editor shows and keeps both settings; a save without a change leaves the rows as they were
		const ga = s.ga;
		sql("UPDATE zzzzsys_access_form SET slf_form_type = '0' WHERE zzzzsys_access_form_id = 'tt_af_lim_ttcty'");
		const rowsBefore = sql("SELECT GROUP_CONCAT(CONCAT_WS('|', zzzzsys_access_form_id, IFNULL(slf_data_mode, 'N'), IFNULL(slf_form_type, 'N')) ORDER BY 1) FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'tt_acc_limited'");
		await open(ga, 'index.php?p=accesslevel&id=tt_acc_limited&tab=rights');
		const sel = async (form, k) => ga.locator(`tr[data-form="${form}"] select[name$="[${k}]"]`).inputValue();
		expect(await sel('ttcus', 'dm') === '0' && await sel('ttcty', 'ft') === '0' && await sel('ttord', 'ft') === '', 'editor values: ' + await sel('ttcus', 'dm') + '/' + await sel('ttcty', 'ft'));
		await Promise.all([ga.waitForNavigation(), ga.click('[data-nu-save]')]);
		expect(sql("SELECT GROUP_CONCAT(CONCAT_WS('|', zzzzsys_access_form_id, IFNULL(slf_data_mode, 'N'), IFNULL(slf_form_type, 'N')) ORDER BY 1) FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'tt_acc_limited'") === rowsBefore, 'save without a change changed the rows');
		await ga.locator('tr[data-form="ttcty"] select[name$="[ft]"]').selectOption('1');
		await Promise.all([ga.waitForNavigation(), ga.click('[data-nu-save]')]);
		expect(sql("SELECT slf_form_type FROM zzzzsys_access_form WHERE zzzzsys_access_form_id = 'tt_af_lim_ttcty'") === '1', 'form type not saved');
		// the full access level is not limited
		full = await login('ttfull', TT_PW);
		await open(full, 'index.php?p=form&f=ttcty&r=cty_ch');
		expect(await full.locator('#nuSaveButton').count() === 1, 'full access level limited');
	} finally {
		if (lim) await lim.context().close();
		if (full) await full.context().close();
		ttRemove();
	}
};

// B4: a form with an access-9 object (access condition) saves completely – the condition runs in a SAVEPOINT, not a new transaction
T.gap_access9 = async (s) => {
	ttInstall();
	let p;
	try {
		p = await login('ttfull', TT_PW);
		await open(p, 'index.php?p=form&f=ttcus&r=cus_1');
		await setField(p, '[data-nu-field="cus_name"]', 'Alice saved with access 9');
		await save(p);
		expect(sql("SELECT cus_name FROM tt_customer WHERE tt_customer_id = 'cus_1'") === 'Alice saved with access 9', 'not saved');
		expect(+sql("SELECT COUNT(*) FROM tt_log WHERE log_event = 'AS' AND log_record = 'cus_1'") >= 1, 'After Save did not run (save stopped half way)');
		expect(p.errors.length === 0 && await p.locator('.alert-danger, .jconfirm-content:has-text("transaction")').count() === 0, 'error shown: ' + p.errors.join(' | '));
	} finally {
		if (p) await p.context().close();
		ttRemove();
	}
};

// B5: nuBuilder's global hook procedures run where 4.9 runs them (0.8.462) – the fixture's hooks write into tt_log
T.gap_hooks = async (s) => {
	ttInstall();
	const log = ev => +sql(`SELECT COUNT(*) FROM tt_log WHERE log_event = '${ev}'`);
	let p, q;
	try {
		// nu_invalid_login (wrong password) and nu_startup (#LOGIN_NAME#)
		const bad = await browser.newContext({ locale: 'en-GB' }); const b = await bad.newPage();
		await b.goto(BASE + 'index.php?p=login'); await b.fill('#username', 'ttfull'); await b.fill('#password', 'wrong');
		await Promise.all([b.waitForNavigation({ waitUntil: 'load' }), b.click('form button[type=submit]:not([name=demo_user])')]);
		await bad.close();
		expect(log('INVALID_LOGIN') === 1, 'nu_invalid_login: ' + log('INVALID_LOGIN'));
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		p = await login('ttfull', TT_PW);
		expect(sql("SELECT log_extra FROM tt_log WHERE log_event = 'STARTUP' ORDER BY tt_log_id DESC LIMIT 1") === 'ttfull', 'nu_startup: ' + sql("SELECT GROUP_CONCAT(log_extra) FROM tt_log WHERE log_event = 'STARTUP'"));
		// nu_before_save before Before Browse and before Before Save; nu_before_edit; nu_before_delete
		await open(p, 'index.php?p=form&f=ttcus');
		expect(log('GLOBAL_BS') === 1 && sql("SELECT tt_log_id FROM tt_log WHERE log_event = 'GLOBAL_BS'") < sql("SELECT MIN(tt_log_id) FROM tt_log WHERE log_event = 'BB'"), 'nu_before_save before BB');
		await open(p, 'index.php?p=form&f=ttcus&r=cus_2');
		expect(sql("SELECT CONCAT(log_record, '|', log_form) FROM tt_log WHERE log_event = 'GLOBAL_BE' ORDER BY tt_log_id DESC LIMIT 1") === 'cus_2|ttcus', 'nu_before_edit: hash');
		await setField(p, '[data-nu-field="cus_name"]', 'Bruno hook'); await save(p);
		expect(sql("SELECT COUNT(*) FROM tt_log WHERE log_event = 'GLOBAL_BS' AND log_record = 'cus_2' AND log_form = 'ttcus'") === '1' && sql("SELECT cus_name FROM tt_customer WHERE tt_customer_id = 'cus_2'") === 'Bruno hook', 'nu_before_save on save');
		await open(p, 'index.php?p=form&f=ttcus&r=cus_3');
		await p.click('#nuDeleteButton'); await confirmDelete(p);
		expect(log('GLOBAL_BD') === 1 && sql("SELECT COUNT(*) FROM tt_customer WHERE tt_customer_id = 'cus_3'") === '0', 'nu_before_delete');
		// an error in the hook stops what follows (nuBuilder: nuErrors)
		sql("UPDATE zzzzsys_php SET sph_php = CONCAT(sph_php, '\nnuDisplayError(''Global check failed'');') WHERE sph_code = 'nu_before_save' AND zzzzsys_php_id LIKE 'tt%'");
		await open(p, 'index.php?p=form&f=ttcus&r=cus_1');
		await setField(p, '[data-nu-field="cus_name"]', 'not saved'); await save(p);
		expect(sql("SELECT cus_name FROM tt_customer WHERE tt_customer_id = 'cus_1'") === 'Alice Muster' && /Global check failed/.test(await p.textContent('body')), 'error of nu_before_save did not stop the save');
		// nu_debug_result_added after nuDebug
		sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_run, sph_global, sph_system, sph_status) VALUES "
			+ "('tt_dbg', 'tt_dbg', 'debug', 'nuDebug(''hello from tt'');', 'hide', '1', '0', '1'), "
			+ "('tt_dbgadd', 'nu_debug_result_added', 'hook', 'nuRunQuery(\"INSERT INTO tt_log (log_event, log_extra) VALUES (?, ?)\", [''DEBUG_ADDED'', $nuDebugMessage]);', '', '0', '0', '1')");
		await p.evaluate(() => nuRunPHPHidden('tt_dbg')); await p.waitForTimeout(1200);
		expect(sql("SELECT log_extra FROM tt_log WHERE log_event = 'DEBUG_ADDED' LIMIT 1").includes('hello from tt'), 'nu_debug_result_added');
		// nu_check_password_policy: $check = false refuses a password (user form of the globeadmin)
		sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_run, sph_global, sph_system, sph_status) VALUES "
			+ "('tt_pwpol', 'nu_check_password_policy', 'policy', 'if (strlen($newPassword) < 12) { nuDisplayError(''At least 12 characters (policy)''); $check = false; }', '', '0', '0', '1')");
		await open(s.ga, 'index.php?p=user&id=tt_user_lim');
		await s.ga.fill('#password', 'Short#1x'); await s.ga.fill('#password_confirm', 'Short#1x');
		await Promise.all([s.ga.waitForNavigation({ waitUntil: 'load' }), s.ga.click('[data-nu-save]')]);
		expect(/At least 12 characters \(policy\)/.test(await s.ga.textContent('body')), 'password policy not applied');
		// nu_startup with $error: the login is refused with that text
		sql("UPDATE zzzzsys_php SET sph_php = CONCAT(sph_php, '\nif (''#LOGIN_NAME#'' === ''ttlim'') $error = ''Startup says no'';') WHERE sph_code = 'nu_startup' AND zzzzsys_php_id LIKE 'tt%'");
		q = await login('ttlim', TT_PW);
		expect(/p=login/.test(q.url()) && /Startup says no/.test(await q.textContent('body')), 'nu_startup $error did not refuse the login: ' + q.url());
	} finally {
		if (p) await p.context().close();
		if (q) await q.context().close();
		sql("DELETE FROM zzzzsys_debug WHERE deb_message LIKE '%hello from tt%'; DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		ttRemove();
	}
};

// B6 / B7: calc objects in grid sub-form rows, the blank new row, afterinsertrow once; object events onnuload / onenter (nuBuilder 4.9)
T.gap_calc_events = async (s) => {
	ttInstall();
	let p;
	try {
		p = await login('ttfull', TT_PW);
		await open(p, 'index.php?p=form&f=ttcus&r=cus_1');
		const v = await p.evaluate(() => ({ row: $('#tt_sf000ord_total').val(), total: $('#tt_calc').val(), nl: window.TT_NULOAD, air: window.TT_AIR || 0, edited: !!window.nuIsSaved && !nuIsSaved() }));
		expect(v.row === '7.50' && v.total === '37.40', 'calc in sub-form rows: ' + JSON.stringify(v));
		expect(v.nl === 1, 'onnuload did not run');
		await p.evaluate(() => document.getElementById('cus_search').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })));   // on a hidden tab
		expect(await p.evaluate(() => window.TT_ENTER) === 1, 'onenter did not run');
		// typing into the blank row adds exactly one new row
		const rows0 = await p.locator('#tt_sf .nuSubformRow:not(template .nuSubformRow)').count();
		await setField(p, '#tt_sf002ord_item', 'Lamp'); await setField(p, '#tt_sf002ord_qty', '2'); await setField(p, '#tt_sf002ord_price', '5');
		const after = await p.evaluate(() => ({ air: window.TT_AIR || 0, total: $('#tt_calc').val(), row: $('#tt_sf002ord_total').val() }));
		expect(after.air === 1 && await p.locator('#tt_sf .nuSubformRow:not(template .nuSubformRow)').count() === rows0 + 1, 'afterinsertrow / rows: ' + JSON.stringify(after));
		expect(after.row === '10.00' && after.total === '47.40', 'calc after typing: ' + JSON.stringify(after));
		// a new record with the grid sub-form (blank row with a calc column) saves
		await open(p, 'index.php?p=form&f=ttcus&r=-1');
		await setField(p, '[data-nu-field="cus_name"]', 'Calc new'); await setField(p, '[data-nu-field="cus_email"]', 'calc.new@example.com');
		await save(p);
		expect(sql("SELECT COUNT(*) FROM tt_customer WHERE cus_name = 'Calc new'") === '1', 'new record with a calc sub-form not saved: ' + (await p.textContent('body')).slice(0, 300).replace(/\s+/g, ' '));
		expect(sql("SELECT COUNT(*) FROM tt_order o JOIN tt_customer c ON c.tt_customer_id = o.ord_tt_customer_id WHERE c.cus_name = 'Calc new'") === '0', 'the blank row was saved');
	} finally {
		if (p) await p.context().close();
		ttRemove();
	}
};

// B2: a copy next to a live nuBuilder does not change its database – no own tables from anonymous requests, the globeadmin's first
// sign-in sets them up, other users wait for it; the sign-in page asks for a backup while the database is not set up yet (0.8.1008)
T.gap_schema = async (s) => {
	const nubSql = SITE ? path.join(SITE, '..', 'nubuilder4.sql') : '';
	if (!SITE || !fs.existsSync(nubSql)) { s.skip = 'SITE / nubuilder4.sql not found'; return; }
	const DBN = 'nutest_gapb2', PORT = 8097, cfgFile = path.join(SITE, '..', '.nuconfig_gapb2.php');
	try { execSync('mysql', { input: `DROP DATABASE IF EXISTS ${DBN}; CREATE DATABASE ${DBN}; GRANT ALL ON ${DBN}.* TO 'nu'@'localhost';`, stdio: 'pipe' }); }
	catch (e) { s.skip = 'cannot create a database (needs the mysql root login)'; return; }
	const db2 = q => execSync('mysql -N ' + DB.replace(/\S+$/, DBN), { input: q }).toString().trim();
	const own = () => +db2(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DBN}' AND table_name LIKE 'zzzzsys_nunext%'`);
	const fresh = () => {
		execSync('mysql', { input: `DROP DATABASE IF EXISTS ${DBN}; CREATE DATABASE ${DBN};` });
		execSync('mysql ' + DB.replace(/\S+$/, DBN), { input: fs.readFileSync(nubSql) });
		const h = execSync(`php -r 'echo password_hash("${TT_PW}", PASSWORD_BCRYPT);'`).toString();
		db2(`INSERT INTO zzzzsys_access (zzzzsys_access_id, sal_code, sal_description, sal_zzzzsys_form_id) VALUES ('b2acc', 'B2', 'B2', 'nuuserhome');
			INSERT INTO zzzzsys_user (zzzzsys_user_id, sus_zzzzsys_access_id, sus_name, sus_login_name, sus_login_password) VALUES ('b2user', 'b2acc', 'B2', 'b2user', '${h}')`);
	};
	const config = () => {
		const c = fs.readFileSync(path.join(SITE, 'nuconfig.php'), 'utf8').replace(/(\$nuConfigDBName\s*=\s*)"[^"]*"/, `$1"${DBN}"`).replace(/\?>\s*$/, '');
		fs.writeFileSync(cfgFile, c + '\n');
	};
	const B2 = BASE.replace(/:\d+\//, ':' + PORT + '/');
	const signIn = async (user, pw) => {
		const ctx = await browser.newContext({ locale: 'en-GB' }); const p = await ctx.newPage();
		const r0 = await p.goto(B2 + 'index.php?p=login');
		const backupNote = await p.locator('#nuLoginBackup').count() === 1;
		await p.fill('#username', user); await p.fill('#password', pw);
		const [r] = await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('form button[type=submit]:not([name=demo_user])')]);
		const out = { url: p.url(), status: r ? r.status() : 0, first: r0.status(), text: (await p.textContent('body')).replace(/\s+/g, ' '), backup: backupNote };
		await ctx.close();
		return out;
	};
	let srv;
	const serve = async () => {
		if (srv) { srv.kill(); await new Promise(res => setTimeout(res, 500)); }
		srv = require('child_process').spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', path.join(SITE, '..')], { env: Object.assign({}, process.env, { NU_CONFIG: cfgFile }), stdio: 'ignore' });
		await new Promise(res => setTimeout(res, 1200));
	};
	try {
		fresh(); config(); await serve();
		await signIn('nobody', 'wrong');
		expect(own() === 0, 'anonymous requests created ' + own() + ' own tables');
		let r = await signIn('b2user', TT_PW);
		expect(r.backup && /p=login/.test(r.url) && /globeadmin/i.test(r.text) && own() === 0, 'a user signed in before the globeadmin (backup note ' + r.backup + '): ' + r.url + ' ' + own());
		r = await signIn(GA[0], GA[1]);
		expect(/p=home/.test(r.url) && own() > 10, 'the globeadmin\'s first sign-in did not set up the tables: ' + own());
		r = await signIn('b2user', TT_PW);
		expect(!/p=login/.test(r.url) && !r.backup, 'user cannot sign in after the set-up (or backup note still shown): ' + r.url);
	} finally {
		if (srv) srv.kill();
		try { fs.unlinkSync(cfgFile); } catch (e) { /* gone */ }
		execSync('mysql', { input: `DROP DATABASE IF EXISTS ${DBN};` });
	}
};

// smaller differences of the gap analysis (0.8.462): #USER_NAME# = sus_name, nuLookupRecord() with ID, nuFORMdata with
// nuGetNuDataValue / nuSetNuDataValue in Before Save (0.8.922: with nuBuilder Forte's variable $nudata, as in its documentation), a blank group footer for an empty (NULL) report group
T.gap_small = async (s) => {
	ttInstall();
	let p;
	try {
		sql("UPDATE zzzzsys_php SET sph_php = CONCAT(sph_php, '\n$nuMainForm = nuHash()[''nuFORMdata''][0]->id;\nnuSetNuDataValue($nudata, $nuMainForm, ''cus_name'', nuGetNuDataValue($nudata, $nuMainForm, ''cus_name'') . '' *'');\nnuSetNuDataValue($nudata, ''tt_sf'', ''ord_item'', ''first row'');') WHERE zzzzsys_php_id = 'ttcus_BS'");
		p = await login('ttfull', TT_PW);
		await open(p, 'index.php?p=form&f=ttcus&r=cus_1');
		expect(sql("SELECT log_extra FROM tt_log WHERE log_event = 'BE' ORDER BY tt_log_id DESC LIMIT 1").includes('|TT Full User|'), '#USER_NAME# is not sus_name: ' + sql("SELECT log_extra FROM tt_log WHERE log_event = 'BE' ORDER BY tt_log_id DESC LIMIT 1"));
		await setField(p, '[data-nu-field="cus_name"]', 'Data'); await save(p);
		expect(sql("SELECT cus_name FROM tt_customer WHERE tt_customer_id = 'cus_1'") === 'Data *', 'nuSetNuDataValue (edit form): ' + sql("SELECT cus_name FROM tt_customer WHERE tt_customer_id = 'cus_1'"));
		expect(sql("SELECT ord_item FROM tt_order WHERE tt_order_id = 'ord_1'") === 'first row', 'nuSetNuDataValue (sub-form): ' + sql("SELECT ord_item FROM tt_order WHERE tt_order_id = 'ord_1'"));
		// lookup: After Browse sees the chosen row with ID
		await open(p, 'index.php?p=form&f=ttcus&r=cus_2');
		const code = sql("SELECT cou_code FROM tt_country ORDER BY tt_country_id LIMIT 1"), cid = sql("SELECT tt_country_id FROM tt_country ORDER BY tt_country_id LIMIT 1");
		await p.evaluate(() => { const t = document.querySelector('[data-nu-target="cus_tt_country_id"].nuLookupCode') || document.getElementById('cus_tt_country_idcode'); t.value = ''; });
		await p.fill('#cus_tt_country_idcode', code); await p.locator('#cus_tt_country_idcode').dispatchEvent('change'); await p.waitForTimeout(1200);
		const ab = sql("SELECT log_extra FROM tt_log WHERE log_event = 'AB' ORDER BY tt_log_id DESC LIMIT 1");
		expect(ab.includes('"ID":"' + cid + '"'), 'nuLookupRecord() without ID: ' + ab);
		// report: a customer without type → the footer of that (NULL) group is blank, as in nuBuilder
		sql("INSERT INTO tt_customer (tt_customer_id, cus_name, cus_amount, cus_type) VALUES ('cus_null', 'No type', 5.00, NULL)");
		const pdf = await p.context().request.get(BASE + 'index.php?p=reportpdf&c=tt_report');
		const f = path.join(require('os').tmpdir(), 'gap_small.pdf'); fs.writeFileSync(f, await pdf.body());
		const txt = execSync(`pdftotext -layout ${f} -`).toString();
		expect(/No type/.test(txt), 'report without the NULL group row: ' + txt.slice(0, 400));
		const fives = (txt.match(/(^|\s)5\.00(?=\s|$)/gm) || []).length;
		expect(fives === 1, 'NULL group footer is not blank (5.00 printed ' + fives + ' times): ' + txt.slice(0, 800));
	} finally {
		if (p) await p.context().close();
		ttRemove();
	}
};

// Compatibility check (0.8.462): the features of the gap analysis are listed – not only function names
T.gap_compat = async (s) => {
	ttInstall();
	try {
		sql("UPDATE zzzzsys_php SET sph_php = CONCAT(sph_php, '\n$g = ''#browse_sql#'';') WHERE zzzzsys_php_id = 'ttcus_BS'");   // #USER_GROUP_ID# is set since 0.8.581, #USER_A11Y# since 0.8.984
		// G6 (0.8.580): an https image is allowed by the form page's CSP – not listed
		sql("INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_id, sob_all_type, sob_html_code) VALUES ('tto_e2e_img', 'ttcus', 'e2e_img_only', 'html', '<img src=\"https://images.example.org/a.png\">')");
		const r = await open(s.ga, 'index.php?p=compat');
		const g6 = await s.ga.$$eval('#compat_findings tr[data-finding="G6"] li', lis => lis.map(l => l.textContent).join(' | '));
		expect(/cdn\.jsdelivr\.net/.test(g6) && !/e2e_img_only/.test(g6), 'G6 lists only blocked addresses: ' + g6);
		expect(r.status() === 200, 'compatibility page: ' + r.status());
		const ids = await s.ga.$$eval('#compat_findings tbody tr td:first-child', tds => tds.map(t => t.textContent.trim()));
		expect(await s.ga.locator('#compat_findings tr.compat-ok[data-finding="G8"]').count() === 1, 'G8 shown as supported (0.8.577)');
		const want = ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'];
		expect(want.every(id => ids.includes(id)), 'findings missing: ' + want.filter(id => !ids.includes(id)).join(', ') + ' (have ' + ids.join(' ') + ')');
	} finally {
		ttRemove();
	}
};

// nuHideHolders (0.8.743, the owner's decision): 0 = button row, 1 = top bar (hidden, never removed), 2 = tabs
T.hide_holders = async (s) => {
	const p = s.ga;
	ttInstall();
	try {
		await open(p, 'index.php?p=form&f=ttcus&r=-1');
		const vis = () => p.evaluate(() => ['#nuActionHolder', '.nu-navbar', '#nuTabHolder', '#nuBreadcrumbHolder'].map(q => { const e = document.querySelector(q); return !!e && e.offsetParent !== null; }));
		expect((await vis()).slice(0, 3).every(Boolean), 'all shown first: ' + await vis());
		await p.evaluate(() => nuHideHolders(0));
		let v = await vis();
		expect(!v[0] && v[1] && v[2], 'nuHideHolders(0) = button row only: ' + v);
		await p.evaluate(() => nuHideHolders(1));
		v = await vis();
		expect(!v[1] && v[2] && await p.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--nu-nav-h').trim()) === '0px', 'nuHideHolders(1) = top bar: ' + v);
		await p.evaluate(() => nuHideHolders(2));
		v = await vis();
		expect(!v[2], 'nuHideHolders(2) = tabs: ' + v);
		await open(p, 'index.php?p=form&f=ttcus&r=-1');
		await p.evaluate(() => nuRemoveHolders(1));
		expect(await p.locator('.nu-navbar').count() === 1 && !(await vis())[1], 'nuRemoveHolders(1) hides the top bar, keeps it in the page');
		expect(p.errors.length === 0, p.errors.join(' | '));
	} finally { ttRemove(); }
};

// nuBuilder JS from the owner's notes (0.8.468): nuSelectBrowse gets the cell, nuBrowseRow, nuSubformValue as shown in the field
T.nub_js_notes = async (s) => {
	ttInstall();
	let p;
	try {
		p = await login('ttfull', TT_PW);
		await open(p, 'index.php?p=form&f=ttcus');
		await p.evaluate(() => { window.nuSelectBrowse = function (event, element) { window.TT_SEL = { el: element.className, pk: $(element).attr('data-nu-primary-key'), info: nuBrowseRow(element), second: nuBrowseRow(element, 1), byId: nuBrowseRow(element, $('#nucell_0_1').attr('data-nu-column-id')) }; }; });
		await p.click('#nucell_0_0'); await p.waitForTimeout(300);
		const r = await p.evaluate(() => { const x = window.TT_SEL; return x && { el: x.el, pk: x.pk, ipk: x.info.pk, row: x.info.row, col: x.info.column, val: x.info.value, second: x.second.value, cell1: $('#nucell_0_1').text(), byId: x.byId.value, colId: x.second.colId }; });
		expect(r && /nuCell/.test(r.el) && r.pk && r.pk === r.ipk && r.row === 0 && r.col === 0 && r.val !== '', 'nuSelectBrowse / nuBrowseRow: ' + JSON.stringify(r));
		expect(r.second === r.cell1 && r.byId === r.cell1 && !!r.colId, 'nuBrowseRow other column: ' + JSON.stringify(r));
		expect(/\/p=form&f=ttcus$|f=ttcus(&|$)/.test(p.url()) && !/r=/.test(p.url()), 'nuSelectBrowse did not take over the click');
		await open(p, 'index.php?p=form&f=ttcus&r=cus_1');
		const sv = await p.evaluate(() => nuSubformValue(document.getElementById('tt_sf000ord_item'), 'ord_total'));
		expect(sv === '7.50' || sv === '7,50', 'nuSubformValue not as shown: ' + sv);
	} finally {
		if (p) await p.context().close();
		ttRemove();
	}
};

T.display_saved_select_disabled = async (s) => {
	// 0.8.989 (found by comparing another nuBuilder Forte database): a select disabled by nuDisable keeps its value in the save (it came
	// as '' – a required one stopped the save); a display object refreshed by nuRefreshDisplayObject is edited and saved into its column
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	const rec = sql("SELECT sample_customer_id FROM sample_customer WHERE IFNULL(cus_country, '') <> '' ORDER BY sample_customer_id LIMIT 1");
	if (!tab || !rec) { s.skip = 'no sample data'; return; }
	const country = sql(`SELECT cus_country FROM sample_customer WHERE sample_customer_id = '${rec}'`);
	const val = sql("SELECT sob_all_validate FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_country'");
	sql('ALTER TABLE sample_customer ADD COLUMN e2e_disp VARCHAR(20) NULL');
	sql("UPDATE zzzzsys_object SET sob_all_validate = '1' WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_country'");
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_display_sql)
		VALUES ('e2edsp', 'samplecustomer', '${tab}', 'display', 'e2e_disp', 'E2E', 998, 500, 20, 120, 20, '0', "SELECT 'E2E-D'")`);
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + rec);
		const raw = await p.evaluate(() => { nuDisable('cus_country'); const e = document.getElementById('cus_country'); return [e.disabled, e.value, nuNext.rawValue(e)]; });
		await p.evaluate(() => { document.getElementById('e2e_disp').value = ''; return nuRefreshDisplayObject('e2e_disp'); });
		await p.waitForTimeout(800);
		const edited = await p.evaluate(() => nuFORM.edited === true || !nuIsSaved());
		await p.evaluate(() => nuSaveAction());
		await p.waitForTimeout(2500);
		const row = sql(`SELECT CONCAT(IFNULL(e2e_disp, 'NULL'), '|', IFNULL(cus_country, 'NULL')) FROM sample_customer WHERE sample_customer_id = '${rec}'`);
		expect(raw[0] === true && raw[1] !== '' && raw[2] === raw[1], 'disabled select value: ' + JSON.stringify(raw));
		expect(edited && row === 'E2E-D|' + country, 'display saved: ' + JSON.stringify({edited, row}));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2edsp'");
		sql(`UPDATE zzzzsys_object SET sob_all_validate = '${val}' WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_country'`);
		sql('ALTER TABLE sample_customer DROP COLUMN e2e_disp');
	}
};

T.php_forte_extras = async (s) => {
	// 0.8.926: nuGetFormProperties, nuGetURLParams, nuRunQuery() without SQL, nuCreateTableFromSelect with parameters,
	// nuAddJavascript(…, …, $first) – as in nuBuilder Forte's documentation
	const p = s.ga;
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const code = `$f = nuGetFormProperties('samplecustomer');
$u = nuGetURLParams();
$db = nuRunQuery();
nuCreateTableFromSelect('e2e_ctfs_tmp', 'SELECT sample_customer_id FROM sample_customer WHERE sample_customer_id = ?', ['${rec}']);
$n = db_num_rows(nuRunQuery('SELECT * FROM e2e_ctfs_tmp'));
nuRunQuery('DROP TABLE e2e_ctfs_tmp');
nuAddJavascript('window.e2eOrder = (window.e2eOrder || "") + "b";');
nuAddJavascript('window.e2eOrder = (window.e2eOrder || "") + "a";', false, true);
nuAddJavascript('window.e2ePhp = ' . json_encode(['table' => $f ? $f->sfo_table : null, 'none' => nuGetFormProperties('no_such_form'), 'url' => $u['e2eurl'] ?? null, 'pw' => isset($u['p']), 'db' => count($db), 'dbpw' => $db[3], 'n' => $n]) . ';');`;
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_group, sph_php, sph_system) VALUES ('samplecustomer_BE', 'samplecustomer_BE', 'System PHP', 'nubuilder', " + JSON.stringify(code) + ", '1')"});
	try {
		await open(p, 'index.php?e2eurl=42&p=x');
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + rec);
		const r = await p.evaluate(() => ({php: window.e2ePhp, order: window.e2eOrder}));
		expect(r.php && r.php.table === 'sample_customer' && r.php.none === false, 'nuGetFormProperties: ' + JSON.stringify(r.php));
		expect(r.php.url === '42' && r.php.pw === false, 'nuGetURLParams: ' + JSON.stringify(r.php));
		expect(r.php.db === 4 && r.php.dbpw === '', 'nuRunQuery() without SQL: ' + JSON.stringify(r.php));
		expect(r.php.n === 1, 'nuCreateTableFromSelect with parameters: ' + JSON.stringify(r.php));
		expect(r.order === 'ab', 'nuAddJavascript $first: ' + r.order);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_BE'; DROP TABLE IF EXISTS e2e_ctfs_tmp");
	}
};

T.php_nueval = async (s) => {
	// 0.8.1033: nuEval($phpid, $returnOutput) as nuBuilder Forte – runs a procedure row by its id with the hash variables of the
	// request, $result → $_POST['nuRunPHPHiddenResult'], printed output returned with $returnOutput, '' for a missing id
	const p = s.ga;
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const proc = "echo '<b>' . '#RECORD_ID#' . '</b>'; $result = 'r-' . nuHash()['form_id'];";
	const be = `$out = nuEval('e2e_nueval_proc', true);
$none = nuEval('no_such_php_id');
$res = $_POST['nuRunPHPHiddenResult'] ?? null;
nuAddJavascript('window.e2eEval = ' . json_encode(['out' => $out, 'none' => $none, 'res' => $res]) . ';');`;
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_group, sph_php, sph_system) VALUES ('e2e_nueval_proc', 'e2e_nueval', 'e2e nuEval', '', " + JSON.stringify(proc) + ", '0'), "
		+ "('samplecustomer_BE', 'samplecustomer_BE', 'System PHP', 'nubuilder', " + JSON.stringify(be) + ", '1')"});
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + rec);
		const r = await p.evaluate(() => window.e2eEval);
		expect(r && r.out === '<b>' + rec + '</b>', 'output returned, hash variables filled: ' + JSON.stringify(r));
		expect(r.none === '' && r.res === 'r-samplecustomer', '$result and a missing id: ' + JSON.stringify(r));
		expect(!(await p.evaluate(() => document.body.innerText)).includes('<b>' + rec), 'output not printed into the page');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id IN ('samplecustomer_BE', 'e2e_nueval_proc')");
	}
};

T.php_nugettranslation = async (s) => {
	// 0.8.1034: nuGetTranslation($l) as nuBuilder Forte – rows of the language sorted by English, own rows before nu… rows
	const p = s.ga;
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const be = "nuAddJavascript('window.e2eTr = ' . json_encode(['l' => nuGetTranslation('E2eLang'), 'none' => nuGetTranslation('NoSuchLang')]) . ';');";
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_translate (zzzzsys_translate_id, trl_language, trl_english, trl_translation) VALUES "
		+ "('nue2etr1', 'E2eLang', 'Save', 'Forte-Save'), ('e2etr1', 'E2eLang', 'Save', 'Own-Save'), ('e2etr2', 'E2eLang', 'Delete', 'Own-Delete'), ('e2etr3', 'OtherLang', 'Save', 'x');\n"
		+ "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_group, sph_php, sph_system) VALUES ('samplecustomer_BE', 'samplecustomer_BE', 'System PHP', 'nubuilder', " + JSON.stringify(be) + ", '1')"});
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + rec);
		const r = await p.evaluate(() => window.e2eTr);
		expect(r && JSON.stringify(r.l) === JSON.stringify([{english: 'Delete', translation: 'Own-Delete'}, {english: 'Save', translation: 'Own-Save'}, {english: 'Save', translation: 'Forte-Save'}]),
			'sorted by English, own rows first: ' + JSON.stringify(r));
		expect(Array.isArray(r.none) && r.none.length === 0, 'unknown language = []');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_BE'; DELETE FROM zzzzsys_translate WHERE zzzzsys_translate_id IN ('nue2etr1', 'e2etr1', 'e2etr2', 'e2etr3')");
	}
};

T.forte_list_html = async (s) => {
	// 0.8.945: searchable lists of the design panel open above it (they were behind it – Form / Procedure looked empty);
	// list titles and cells as HTML like nuBuilder Forte, cleaned (icons, formatting kept; scripts / events removed); nuRunPHPHidden(code, true)
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-listhtml.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_browse zzzzsys_nunext_form_draft > ' + keep);
	try {
		await open(p, 'index.php?p=form&f=' + F + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		await p.click('#nuBrowseTable th.nuBrowseTitle >> nth=1'); await p.waitForTimeout(400);
		await p.selectOption('#nuDsPanel select[data-key="action_t"]', 'form'); await p.waitForTimeout(1500);
		const sel = p.locator('#nuDsPanel select[data-key="action_id"]').locator('xpath=following-sibling::div[contains(@class,"ts-wrapper")]');
		await sel.locator('.ts-control').click(); await p.waitForTimeout(400);
		const onTop = await p.evaluate(() => { const x = [...document.querySelectorAll('.ts-dropdown')].find(e => e.offsetParent); if (!x) return 'no list';
			const o = x.querySelector('.option'); if (!o) return 'no options'; const r = o.getBoundingClientRect(), el = document.elementFromPoint(r.left + 10, r.top + r.height / 2);
			return x.contains(el) ? '' : 'covered by ' + (el && (el.id || String(el.className))); });
		expect(onTop === '', 'form list above the design panel: ' + onTop);
		await p.keyboard.press('Escape');
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status = 'open'`);
		// HTML in titles and cells
		const col = sql(`SELECT zzzzsys_browse_id FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}' ORDER BY sbr_order LIMIT 1`);
		execSync('mysql ' + DB, {input: `UPDATE zzzzsys_browse SET sbr_title = 'Code <i class="bi bi-hash"></i>', sbr_display = 'CONCAT(''<i class="fa fa-times"></i> <b>'', cus_code, ''</b><script>window.nuE2eXss=1</script><img src="x" onerror="window.nuE2eXss=2">'')' WHERE zzzzsys_browse_id = '${col}'`});
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuBrowseTable tbody tr');
		const r = await p.evaluate(() => { const th = document.querySelector('#nuBrowseTable th.nuBrowseTitle'), td = document.querySelector('#nuBrowseTable tbody tr td.nuCellTd');
			return {thIcon: !!th.querySelector('i.bi-hash'), tdIcon: !!td.querySelector('i.fa-times'), bold: !!td.querySelector('b'), script: !!td.querySelector('script'),
				onerror: !!td.querySelector('[onerror]'), xss: window.nuE2eXss || 0, label: td.getAttribute('data-nu-label')}; });
		expect(r.thIcon && r.tdIcon && r.bold, 'icons / bold drawn: ' + JSON.stringify(r));
		expect(!r.script && !r.onerror && !r.xss, 'script / event removed: ' + JSON.stringify(r));
		expect(r.label === 'Code', 'label as text: ' + r.label);
		// nuRunPHPHidden(code, true): nuBeforeSave() returning false stops the call
		const stopped = await p.evaluate(async () => { let calls = 0; const f = window.fetch; window.fetch = function () { calls++; return f.apply(this, arguments); };
			window.nuBeforeSave = () => false; const res = await nuRunPHPHidden('nu_e2e_none', true); window.fetch = f; delete window.nuBeforeSave; return {res, calls}; });
		expect(stopped.res === null && stopped.calls === 0, 'nuBeforeSave false stops nuRunPHPHidden: ' + JSON.stringify(stopped));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.forte_edited_tinymce = async (s) => {
	// 0.8.950: nuHasBeenEdited() marks the record as edited (nuBuilder Forte; it was a read-only check), nuTinyMCEOnChangeHandler(editor)
	// copies a self-made TinyMCE editor's HTML into its field (form code copied from Forte's nuInitTinyMCE)
	const p = s.ga;
	const id = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1");
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
	const r = await p.evaluate(() => {
		const before = nuIsSaved() && !document.getElementById('nuSaveButton').classList.contains('nuSaveEdited');
		nuHasBeenEdited();
		const after = !nuIsSaved() && document.getElementById('nuSaveButton').classList.contains('nuSaveEdited');
		nuHasNotBeenEdited();
		const ta = document.createElement('textarea'); ta.id = 'e2eWys'; document.getElementById('nuEditForm').appendChild(ta);
		nuTinyMCEOnChangeHandler({id: 'e2eWys_container', getContent: () => '<p>e2e</p>'});
		const synced = ta.value === '<p>e2e</p>' && !nuIsSaved();
		nuHasNotBeenEdited(); ta.remove();
		nuTinyMCEOnChangeHandler({id: 'other'});
		const plain = !nuIsSaved();
		nuHasNotBeenEdited();
		return {before, after, synced, plain};
	});
	expect(r.before && r.after, 'nuHasBeenEdited marks the record: ' + JSON.stringify(r));
	expect(r.synced && r.plain, 'nuTinyMCEOnChangeHandler: ' + JSON.stringify(r));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.forte_db3_compat = async (s) => {
	// comparing a third nuBuilder Forte database with nuBuilder Next: (1) a failing browse SQL shows the titles and no rows (message for the
	// globeadmin), (2) a sub-form whose query fails keeps the page – only the blank row, (3) multiple selects carry the blank item,
	// (4) nuPortraitScreen(), (5) nuUserName() is empty for the globeadmin, (6) window variables set while editing survive the save's
	// reload (nuAfterSave), (7) TinyMCE is loaded when form code calls tinymce.init itself
	const p = s.ga, id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1'), ord = sql('SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1');
	if (!id || !ord) { s.skip = 'no sample data'; return; }
	const bsql = sql("SELECT REPLACE(TO_BASE64(sfo_browse_sql), '\\n', '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	const ejs = sql("SELECT IFNULL(REPLACE(TO_BASE64(sfo_edit_javascript), '\\n', ''), 'NULL') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	try {
		// (1)
		sql("UPDATE zzzzsys_form SET sfo_browse_sql = 'SELECT * FROM e2e_no_such_table' WHERE zzzzsys_form_id = 'samplecustomer'");
		const r1 = await open(p, 'index.php?p=form&f=samplecustomer');
		const b = await p.evaluate(() => ({err: !!document.getElementById('nuBrowseSqlErr'), th: document.querySelectorAll('#nuBrowseTable thead th').length, rows: document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow').length}));
		expect(r1.status() === 200 && b.err && b.th > 0 && b.rows === 0, 'failing browse SQL: titles, no rows: ' + JSON.stringify(b));
		sql(`UPDATE zzzzsys_form SET sfo_browse_sql = FROM_BASE64('${bsql}') WHERE zzzzsys_form_id = 'samplecustomer'`);
		// (2)
		sql("UPDATE zzzzsys_object SET sob_subform_foreign_key = 'e2e_no_col' WHERE sob_all_id = 'sample_items' AND sob_all_zzzzsys_form_id = 'sampleorder'");
		const r2 = await open(p, 'index.php?p=form&f=sampleorder&r=' + ord);
		const sf = await p.evaluate(() => ({rows: $('#sample_items .nuSubformRow').length, save: !!document.getElementById('nuSaveButton')}));
		sql("UPDATE zzzzsys_object SET sob_subform_foreign_key = 'ori_sample_order_id' WHERE sob_all_id = 'sample_items' AND sob_all_zzzzsys_form_id = 'sampleorder'");
		expect(r2.status() === 200 && sf.save && sf.rows === 1, 'failing sub-form query: page with the blank row: ' + JSON.stringify(sf));
		// (3)
		await open(p, 'index.php?p=form&f=sampleall&r=-1');
		const ms = await p.evaluate(() => { const e = document.getElementById('all_select_multi'); if (!e) return null;
			const first = e.options[0] ? e.options[0].value : null; $(e).removeAttr('multiple'); $(e).attr('size', '5'); return {first, val: $(e).val()}; });
		if (ms) expect(ms.first === '' && ms.val === '', 'multiple select: blank item, nothing chosen as a list box: ' + JSON.stringify(ms));
		// (4) + (5)
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
		const pt = await p.evaluate(() => { const sc = nuPortraitScreen(); const h = document.documentElement.classList; return {sc, portrait: h.contains('nuPortrait'), stack: h.contains('nuPhoneStack'), name: nuUserName(), resp: nuSERVERRESPONSE.user_name}; });
		expect(pt.sc === 1 && pt.portrait && pt.stack, 'nuPortraitScreen: ' + JSON.stringify(pt));
		expect(pt.name === '' && pt.resp === '', 'nuUserName() of the globeadmin: ' + JSON.stringify(pt));
		// (6)
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
		await p.evaluate(() => { window.e2eCarry = 'kept'; window.e2eCarryObj = {a: [1, 2]}; window.nuAfterSave = function () { window.e2eSeen = window.e2eCarry; };
			const f = document.querySelector('#nuEditForm input[data-nu-field]:not([type=hidden]):not([readonly])'); $(f).val($(f).val() + ' ').change(); });
		await Promise.all([p.waitForNavigation(), p.evaluate(() => nuSaveAction())]);
		await p.waitForSelector('#nuSaveButton'); await p.waitForTimeout(500);
		const cv = await p.evaluate(() => ({v: window.e2eCarry, o: JSON.stringify(window.e2eCarryObj), left: sessionStorage.getItem('nuNextWinCarry')}));
		expect(cv.v === 'kept' && cv.o === '{"a":[1,2]}' && cv.left === null, 'window variables after the save: ' + JSON.stringify(cv));
		// (7)
		const pack = SITE && fs.existsSync(path.join(SITE, 'vendor/packs')) && fs.readdirSync(path.join(SITE, 'vendor/packs')).some(d => /^tinymce-/.test(d));
		if (pack) {
			sql("UPDATE zzzzsys_form SET sfo_edit_javascript = 'if (window.tinymce) window.e2eTiny = typeof tinymce.init;' WHERE zzzzsys_form_id = 'samplecustomer'");
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
			const tm = await p.evaluate(() => ({t: window.e2eTiny || '', script: !!document.querySelector('script[src*="tinymce.min.js"]')}));
			expect(tm.t === 'function' && tm.script, 'TinyMCE for form code: ' + JSON.stringify(tm));
		}
	} finally {
		sql(`UPDATE zzzzsys_form SET sfo_browse_sql = FROM_BASE64('${bsql}') WHERE zzzzsys_form_id = 'samplecustomer'`);
		sql(`UPDATE zzzzsys_form SET sfo_edit_javascript = ${ejs === 'NULL' ? 'NULL' : `FROM_BASE64('${ejs}')`} WHERE zzzzsys_form_id = 'samplecustomer'`);
		sql("UPDATE zzzzsys_object SET sob_subform_foreign_key = 'ori_sample_order_id' WHERE sob_all_id = 'sample_items' AND sob_all_zzzzsys_form_id = 'sampleorder'");
	}
};

T.forte_db2_compat = async (s) => {
	// 0.8.965, found by comparing another nuBuilder Forte database side by side: (1) nuHasNotBeenEdited() keeps the fields' edit marks
	// (values the form's code sets while loading are still saved), (2) <table>_nulog "viewed" on opening a record, (3) #user_id# /
	// #session_id# in the PHP hash, (4) list cells as div#nucell_<row>_<column> inside the td, (5) relative files of the Setup header
	// that only exist in nuBuilder Forte's folder are linked there
	const p = s.ga, id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	if (!id) { s.skip = 'no sample data'; return; }
	const hdr = sql("SELECT IFNULL(REPLACE(TO_BASE64(set_header), '\\n', ''), 'NULL') FROM zzzzsys_setup LIMIT 1");
	const nubDir = SITE ? path.dirname(SITE) : '', css = nubDir ? path.join(nubDir, 'e2e_hdr965.css') : '';
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_965'");
	sql(`INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('e2e_965', 'e2e_965', 'e2e', 'hide', '1', '0',
		'nuAddJavascript("window.e2e965 = ''#user_id#|#session_id#|#USER_ID#|#SESSION_ID#'';");')`);
	execSync('mysql ' + DB, {input: 'ALTER TABLE sample_customer ADD COLUMN sample_customer_nulog VARCHAR(1000) NULL'});
	try {
		// (1)
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1'); await p.waitForSelector('#nuSaveButton');
		const m = await p.evaluate(() => { const f = document.querySelector('#nuEditForm input[data-nu-field]:not([type=hidden]):not([readonly])');
			$(f).val('x965').change(); nuHasNotBeenEdited(); return {kept: f.classList.contains('nuEdited'), saved: nuIsSaved(), red: document.getElementById('nuSaveButton').classList.contains('nuSaveEdited')}; });
		expect(m.kept && m.saved && !m.red, 'nuHasNotBeenEdited keeps the field mark, resets the record state: ' + JSON.stringify(m));
		// (2)
		sql(`UPDATE sample_customer SET sample_customer_nulog = '{"added":{"user":"u1","time":5}}' WHERE sample_customer_id = '${id}'`);
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
		const nl = JSON.parse(sql(`SELECT sample_customer_nulog FROM sample_customer WHERE sample_customer_id = '${id}'`) || '{}');
		expect(nl.added && nl.added.user === 'u1' && nl.viewed && nl.viewed.user === 'globeadmin' && nl.viewed.time > 0, 'nulog viewed: ' + JSON.stringify(nl));
		// (3)
		await p.evaluate(() => { window.e2e965 = null; nuRunPHPHidden('e2e_965'); });
		await p.waitForFunction(() => window.e2e965 !== null, null, { timeout: 8000 });
		const hv = (await p.evaluate(() => window.e2e965)).split('|');
		expect(hv[0] === hv[2] && hv[0] !== '' && hv[1] === hv[3] && hv[1] !== '', '#user_id# / #session_id#: ' + hv.join('|'));
		// (4)
		await open(p, 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuBrowseTable div.nuCell');
		const c = await p.evaluate(() => { const all = [...document.querySelectorAll('[data-nu-column="0"]')];
			$("div[id^='nucell_0']").css('color', 'rgb(1, 2, 3)');
			return {ids: all.every(e => e.id), div: !!document.querySelector('td.nuCellTd > div#nucell_0_0.nuCell[data-nu-row="0"][data-nu-primary-key]'),
				color: getComputedStyle(document.querySelector('#nucell_0_0')).color, n: nuBrowseLoop([0], (x) => x.id).length}; });
		expect(c.ids && c.div && c.color === 'rgb(1, 2, 3)' && c.n > 0, 'list cells as Forte: ' + JSON.stringify(c));
		// (5)
		if (css) {
			fs.writeFileSync(css, '/* e2e */');
			execSync('mysql ' + DB, {input: "UPDATE zzzzsys_setup SET set_header = CONCAT(IFNULL(set_header, ''), '\\n</script><link rel=\"stylesheet\" href=\"e2e_hdr965.css\"><script>')"});
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
			const href = await p.evaluate(() => { const l = [...document.querySelectorAll('link')].find(x => /e2e_hdr965/.test(x.getAttribute('href'))); return l ? l.getAttribute('href') : ''; });
			expect(/^\.\.\/e2e_hdr965\.css\?v=/.test(href), 'Setup header file from nuBuilder Forte\'s folder: ' + href);
		}
		// (6) nuCurrentProperties().browse_rows = the unformatted values
		await open(p, 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuBrowseTable div.nuCell');
		const br = await p.evaluate(() => { const r = nuCurrentProperties().browse_rows; return {same: JSON.stringify(r[0]) === JSON.stringify(window.nuNext.browse.rows[0]), pk: r[0][0] === document.querySelector('tr.nuBrowseRow').getAttribute('data-nu-primary-key')}; });
		expect(br.same && br.pk, 'browse_rows: ' + JSON.stringify(br));
		// (7) nuAddRow as Forte (the blank row becomes a row, a new blank row follows), cell id <prefix>nuRECORD, clipboard with nuDelete
		const ord = sql('SELECT sample_order_id FROM sample_order ORDER BY ord_number LIMIT 1');
		if (ord) {
			await open(p, 'index.php?p=form&f=sampleorder&r=' + ord); await p.waitForSelector('#nuSaveButton');
			const ar = await p.evaluate(() => { const rows = () => $('#sample_items .nuSubformRow'), n0 = rows().length;
				nuAddRow('sample_items'); const r = rows(), n1 = r.length;
				let cap = ''; window.nuCopyToClipboard = (t) => { cap = t; }; nuSubformToClipboard('sample_items', '\t', true, false);
				return {n0, n1, lastNew: r.last().hasClass('nuNewRow'), prevEdited: r.eq(n1 - 2).hasClass('nuRowEdited') && !r.eq(n1 - 2).hasClass('nuNewRow'),
					focus: !!document.activeElement.closest && document.activeElement.closest('.nuSubformRow') === r.last()[0],
					cell: !!document.querySelector('td.nuSubformCell#sample_items000nuRECORD'), noField: $('[id^="sample_items"][id*="_nucell"]').length === 0,
					clip: cap.split('\n')[0].split('\t').pop()}; });
			expect(ar.n1 === ar.n0 + 1 && ar.lastNew && ar.prevEdited && ar.focus, 'nuAddRow: ' + JSON.stringify(ar));
			expect(ar.cell && ar.noField && ar.clip === 'nuDelete', 'sub-form cell id / clipboard: ' + JSON.stringify(ar));
		}
		// (9) the fixed layout's canvas grows with an HTML object the code fills later; a table caption stays above its table
		if (ord) {
			await open(p, 'index.php?p=form&f=sampleorder&r=' + ord + '&layout=absolute'); await p.waitForSelector('#nuSaveButton');
			const cv = await p.evaluate(async () => { const h = document.querySelector('.nuAbsoluteCanvas > .nuAbs .nuHtml'); if (!h) return null;
				const c = h.closest('.nuAbsoluteCanvas'), before = c.offsetHeight;
				const t = document.createElement('div'); t.innerHTML = '<table><caption id="e2eCap">c</caption><tr><td>x</td></tr></table><div style="height:1500px"></div>'; h.appendChild(t);
				await new Promise(r => setTimeout(r, 400));
				return {before, after: c.offsetHeight, top: h.closest('.nuAbs').offsetTop + h.scrollHeight, side: getComputedStyle(document.getElementById('e2eCap')).captionSide}; });
			if (cv) expect(cv.after >= cv.top && cv.after > cv.before && cv.side === 'top', 'canvas / caption: ' + JSON.stringify(cv));
		}
		// (10) images of form code relative to nuBuilder Forte's folder, nuCopyToClipboard(field), nuSERVERRESPONSE.home_id, box sizes in HTML objects
		const png = nubDir ? path.join(nubDir, 'e2e_img965.png') : '';
		if (png) fs.writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64'));
		try {
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
			const mx = await p.evaluate(async () => {
				const img = document.createElement('img'); img.setAttribute('src', 'e2e_img965.png'); document.getElementById('nuEditForm').appendChild(img);
				let cap = null; const w = navigator.clipboard && navigator.clipboard.writeText; try { navigator.clipboard.writeText = (t) => { cap = t; return Promise.resolve(); }; } catch (e) { /* none */ }
				const f = document.querySelector('#nuEditForm input[data-nu-field]:not([type=hidden])'); f.value = 'c965'; nuCopyToClipboard($(f));
				await new Promise(r => setTimeout(r, 600));
				const d = document.createElement('div'); d.className = 'nuHtml'; d.innerHTML = '<div style="height:50px;padding:10px">x</div>'; document.getElementById('nuEditForm').appendChild(d);
				return {img: img.getAttribute('src'), w: img.naturalWidth, cap, home: nuSERVERRESPONSE.home_id, box: d.firstChild.offsetHeight};
			});
			expect((!png || (mx.img === '../e2e_img965.png' && mx.w === 1)) && mx.cap === 'c965' && mx.home === 'nuhome' && mx.box === 70, 'images / clipboard / home_id / box sizes: ' + JSON.stringify(mx));
		} finally { if (png && fs.existsSync(png)) fs.unlinkSync(png); }
		// (11) Fit columns off: the list keeps the stored widths (+ padding) and scrolls sideways
		const fit = sql("SELECT IFNULL(sfo_browse_autoresize_columns, 'NULL') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		try {
			sql("UPDATE zzzzsys_form SET sfo_browse_autoresize_columns = '0' WHERE zzzzsys_form_id = 'samplecustomer'");
			await open(p, 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuBrowseTable div.nuCell');
			const tw = await p.evaluate(() => { const t = document.getElementById('nuBrowseTable'); return {style: t.style.width, sum: [...t.querySelectorAll('thead th')].filter(th => th.style.display !== 'none').reduce((a, th) => a + (parseInt(th.style.width, 10) || 0), 0)}; });
			expect(parseInt(tw.style, 10) === tw.sum && tw.sum > 0, 'stored widths: ' + JSON.stringify(tw));
		} finally {
			sql('UPDATE zzzzsys_form SET sfo_browse_autoresize_columns = ' + (fit === 'NULL' ? 'NULL' : "'" + fit + "'") + " WHERE zzzzsys_form_id = 'samplecustomer'");
		}
		// (12) nuBuilder Forte's control classes (input_<type>, nuReadonly), title ids nusort_<i>, nuAlign, alignment not on HTML objects
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
		const cl = await p.evaluate(() => {
			const ins = [...document.querySelectorAll('#nuEditForm input.nu_input[data-nu-field]:not([type=hidden])')];
			return {typed: ins.length > 0 && ins.every(e => [...e.classList].some(c => /^input_/.test(c))), ro: [...document.querySelectorAll('#nuEditForm input.nu_input[readonly]')].every(e => e.classList.contains('nuReadonly')),
				align: nuAlign('r') === 'right' && nuAlign('x') === '', html: [...document.querySelectorAll('.nuHtml')].every(e => !e.style.textAlign)};
		});
		expect(cl.typed && cl.ro && cl.align && cl.html, 'Forte classes: ' + JSON.stringify(cl));
		await open(p, 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuBrowseTable div.nuCell');
		expect(await p.evaluate(() => !!document.querySelector('th#nuBrowseTitle0 #nusort_0')), 'title id nusort_0');
		// (13) run buttons carry nuBuilder Forte's onclick (nuForm(…)); form code that rewrites it is followed
		await open(p, 'index.php?p=form&f=samplehome&r=-1'); await p.waitForTimeout(800);
		const rb = await p.evaluate(() => { const b = document.querySelector('button.nuRunButton[data-nu-run-onclick]'); if (!b) return null;
			const before = b.getAttribute('onclick'); b.setAttribute('onclick', "window.e2eRun = 1"); b.click(); return {before, run: window.e2eRun === 1, url: location.href}; });
		expect(rb && /nuRunButton\(this, event, function \(\) \{ nu(Form|Popup)\('/.test(rb.before) && rb.run && /samplehome/.test(rb.url), 'run button onclick: ' + JSON.stringify(rb));
		// (14) browser tab title as nuBuilder Forte when the shared settings say so (nuNext.tab_title)
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
		const tt = await p.evaluate(() => { const own = document.title; window.nuNext.tab_title = {prefix: 'APP', show: true}; nuSetTitle('Rec 1'); const a = document.title;
			window.nuNext.tab_title = {prefix: 'APP', show: false}; nuSetTitle('Rec 2'); const b = document.title; window.nuNext.tab_title = null; nuSetTitle('Rec 3'); return [own, a, b, document.title]; });
		expect(tt[1] === 'APP - Rec 1' && tt[2] === 'APP' && tt[3] === 'Rec 3', 'tab title: ' + JSON.stringify(tt));
		// (15) controls of form code inside an HTML object: a change does not mark the record as edited, no first focus; a ".row"
		// without Bootstrap columns is no grid (children keep their width), a real grid row stays one
		const hx = await p.evaluate(() => { nuHasNotBeenEdited(); const d = document.createElement('div'); d.className = 'nuHtml'; d.setAttribute('data-nu-field', 'e2ehtml');
			d.innerHTML = '<div class="row"><select id="e2eSel"><option>a</option><option>b</option></select><button type="button" id="e2eB1">x</button><button type="button" id="e2eB2">y</button></div>'
				+ '<div class="row"><div class="col-6" id="e2eCol">c</div></div>';
			document.getElementById('nuEditForm').appendChild(d);
			const sel = document.getElementById('e2eSel'); sel.value = 'b'; sel.dispatchEvent(new Event('change', {bubbles: true}));
			const b1 = document.getElementById('e2eB1').getBoundingClientRect(), b2 = document.getElementById('e2eB2').getBoundingClientRect(), col = document.getElementById('e2eCol').getBoundingClientRect();
			if (document.activeElement) document.activeElement.blur();
			const foc = window.nuFocusFirst(d);
			const r = {saved: nuIsSaved(), b1w: Math.round(b1.width), row: Math.abs(b1.top - b2.top) < 2, col: Math.round(col.width), all: Math.round(d.getBoundingClientRect().width), foc};
			d.remove(); return r; });
		expect(hx.saved && hx.b1w < 100 && hx.row && hx.col > hx.all * 0.4 && hx.col < hx.all * 0.6 && !hx.foc, 'HTML object controls / .row: ' + JSON.stringify(hx));
		// (16) the selected tab carries nuTabSelected; nuCurrentProperties().filter = the run filter (?rf=); Setup → Style in the head
		// (before HTML objects' own <style>); an unclosed tag of a label stays inside it; the blank sub-form row's delete box is ticked
		// until the row is edited (all as nuBuilder Forte)
		const lblId = sql("SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_type = 'input' AND sob_all_label <> '' ORDER BY sob_all_order LIMIT 1");
		const lblOld = sql("SELECT REPLACE(TO_BASE64(sob_all_label), '\\n', '') FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = '" + lblId + "'");
		const ssOld = sql("SELECT IFNULL(REPLACE(TO_BASE64(set_style), '\\n', ''), 'NULL') FROM zzzzsys_setup LIMIT 1");
		try {
			sql("UPDATE zzzzsys_object SET sob_all_label = CONCAT('<big>', sob_all_label) WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = '" + lblId + "'");
			sql("UPDATE zzzzsys_setup SET set_style = CONCAT(IFNULL(set_style, ''), ' .e2eSS971 { color: rgb(1, 2, 3); }')");
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + id + '&rf=e2e971'); await p.waitForSelector('#nuSaveButton');
			const tq = await p.evaluate(() => ({sel: (document.querySelector('.nuTabSelected') || {}).id || '', n: document.querySelectorAll('.nuTabSelected').length,
				filter: nuCurrentProperties().filter, ssHead: !!document.head.querySelector('style[data-nu-source="set_style"]'),
				bigs: document.querySelectorAll('#nuEditForm big').length, bigOk: [...document.querySelectorAll('#nuEditForm big')].every(b => b.closest('label'))}));
			expect(tq.sel === 'nuTab0' && tq.n === 1 && tq.filter === 'e2e971' && tq.ssHead && tq.bigs === 1 && tq.bigOk, 'tab / filter / style / label: ' + JSON.stringify(tq));
		} finally {
			sql("UPDATE zzzzsys_object SET sob_all_label = FROM_BASE64('" + lblOld + "') WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = '" + lblId + "'");
			execSync('mysql ' + DB, {input: 'UPDATE zzzzsys_setup SET set_style = ' + (ssOld === 'NULL' ? 'NULL' : "FROM_BASE64('" + ssOld + "')")});
		}
		const ordId = sql('SELECT ori_sample_order_id FROM sample_order_item GROUP BY ori_sample_order_id ORDER BY 1 LIMIT 1');
		if (ordId) {
			await open(p, 'index.php?p=form&f=sampleorder&r=' + ordId); await p.waitForSelector('#nuSaveButton');
			const br = await p.evaluate(() => { const rows = [...document.querySelectorAll('#sample_items .nuSubformRow')], last = rows[rows.length - 1];
				const pre = last.getAttribute('data-nu-prefix'), del = document.getElementById(pre + 'nuDelete'), before = del.checked;
				const f = document.getElementById(pre + 'ori_qty'); f.value = '1'; f.dispatchEvent(new Event('change', {bubbles: true}));
				const rows2 = [...document.querySelectorAll('#sample_items .nuSubformRow')], nl = rows2[rows2.length - 1];
				return {before, after: del.checked, newBlank: document.getElementById(nl.getAttribute('data-nu-prefix') + 'nuDelete').checked, more: rows2.length === rows.length + 1}; });
			expect(br.before && !br.after && br.newBlank && br.more, 'blank sub-form row delete box: ' + JSON.stringify(br));
		}
		// (17) a run button with an onclick event of its own (here: a loading spinner that disables the button) still opens its form –
		// the event follows the run code in one onclick, as in nuBuilder Forte; nuFORM.getCurrent() carries browse_columns / browse_sql
		sql("DELETE FROM zzzzsys_event WHERE zzzzsys_event_id = 'e2e_973'");
		try {
			sql("INSERT INTO zzzzsys_event (zzzzsys_event_id, sev_zzzzsys_object_id, sev_event, sev_javascript) VALUES ('e2e_973', 'sample_home_o4', 'onclick', 'this.disabled = true; window.e2e973 = 1;')");
			await open(p, 'index.php?p=form&f=samplehome&r=-1'); await p.waitForSelector('#home_customers');
			await Promise.all([p.waitForURL(/f=samplecustomer/, {timeout: 10000}).catch(() => {}), p.click('#home_customers')]);
			await p.waitForLoadState('networkidle');
			const gc = await p.evaluate(() => ({url: location.href, cols: (nuFORM.getCurrent().browse_columns || []).length, sql: typeof nuFORM.getCurrent().browse_sql}));
			expect(/samplecustomer/.test(gc.url) && gc.cols > 0 && gc.sql === 'string', 'run button with own onclick / getCurrent: ' + JSON.stringify(gc));
		} finally {
			sql("DELETE FROM zzzzsys_event WHERE zzzzsys_event_id = 'e2e_973'");
		}
		// (18) 0.8.974: a run filter is found in the primary key / hidden columns as in Forte; list titles carry no data-nu-column; #nubody and
		// #nuBrowseFooter exist, getCurrent().pages is set; form code hiding #nuActionHolder hides the search row; the row height cuts cells;
		// a prototype method defined further down the form's code is there when the code above it calls it
		const fj = sql("SELECT IFNULL(REPLACE(TO_BASE64(sfo_javascript), '\\n', ''), 'NULL') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		const brj = sql("SELECT IFNULL(REPLACE(TO_BASE64(sfo_browse_javascript), '\\n', ''), 'NULL') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		const rh = sql("SELECT IFNULL(sfo_browse_row_height, 'NULL') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		try {
			execSync('mysql ' + DB, {input: "UPDATE zzzzsys_form SET sfo_browse_row_height = 30, sfo_javascript = CONCAT(IFNULL(sfo_javascript, ''), '\nwindow.e2e974 = new Date().e2eV974();\nDate.prototype.e2eV974 = function () { return 7; };'),"
				+ " sfo_browse_javascript = CONCAT(IFNULL(sfo_browse_javascript, ''), '\n$(\\'#nuActionHolder\\').hide(); $(\\'#nuBreadcrumbHolder\\').hide();') WHERE zzzzsys_form_id = 'samplecustomer'"});
			await open(p, 'index.php?p=form&f=samplecustomer&rf=' + id); await p.waitForSelector('#nuBrowseTable');
			const lf = await p.evaluate(() => ({rows: document.querySelectorAll('#nuBrowseTable tbody tr.nuBrowseRow').length, th: document.querySelectorAll('#nuBrowseTable thead [data-nu-column]').length,
				body: !!document.getElementById('nubody'), foot: !!document.getElementById('nuBrowseFooter'), pages: nuFORM.getCurrent().pages,
				search: getComputedStyle(document.querySelector('.nuBrowseSearch')).display, head: getComputedStyle(document.querySelector('.nuFormHeader')).display,
				cut: (document.querySelector('#nuBrowseTable div.nuCell') || {style: {}}).style.maxHeight, proto: window.e2e974}));
			expect(lf.rows === 1 && lf.th === 0 && lf.body && lf.foot && lf.pages === 1 && lf.search === 'none' && lf.head === 'none' && lf.cut === '30px' && lf.proto === 7,
				'run filter / list as Forte / hoisted prototype: ' + JSON.stringify(lf));
		} finally {
			execSync('mysql ' + DB, {input: 'UPDATE zzzzsys_form SET sfo_browse_row_height = ' + (rh === 'NULL' ? 'NULL' : "'" + rh + "'")
				+ ', sfo_javascript = ' + (fj === 'NULL' ? 'NULL' : "FROM_BASE64('" + fj + "')")
				+ ', sfo_browse_javascript = ' + (brj === 'NULL' ? 'NULL' : "FROM_BASE64('" + brj + "')") + " WHERE zzzzsys_form_id = 'samplecustomer'"});
		}
		// (19) 0.8.975: Forte's layout helpers (nuGetWordWidth …), a $(…).clone() of a fixed-layout field keeps its px size, nuSetPlaceholder()
		// without text = the format, a checkbox's val() is "on", leaving a launch form after an edit does not ask
		await open(p, 'index.php?p=form&f=sampleall&r=-1'); await p.waitForSelector('#all_nudate', {state: 'attached'});
		const cl975 = await p.evaluate(() => {
			const fns = ['nuGetWordWidth', 'nuCreateElementWithId', 'nuSetObjectBounds', 'nuTotalWidth', 'nuTotalHeight', 'nuRoundNearest'].filter(n => typeof window[n] !== 'function');
			nuSetPlaceholder('all_nudate');
			const inAbs = !!document.getElementById('all_nudate').closest('.nuAbs');
			const c = $('#all_nudate').clone().prop('id', 'e2e975clone').insertAfter('#nuRECORD')[0];
			const r = {fns, ph: $('#all_nudate').attr('placeholder'), chk: $('#all_checkbox').val(), w: nuGetWordWidth('Name') > 5, inAbs,
				cw: c.style.width, cls: c.classList.contains('nuAbsClone')};
			c.remove(); return r; });
		expect(cl975.fns.length === 0 && cl975.ph === 'dd.mm.yyyy' && cl975.chk === 'on' && cl975.w && (!cl975.inAbs || (/px$/.test(cl975.cw) && cl975.cls)),
			'Forte helpers / clone / placeholder / checkbox: ' + JSON.stringify(cl975));
		await open(p, 'index.php?p=form&f=samplehome&r=-1'); await p.waitForSelector('#nuEditForm');
		let asked = 0; const onDlg = (d) => { asked++; d.accept().catch(() => {}); }; p.on('dialog', onDlg);
		await Promise.all([p.waitForNavigation({timeout: 8000}).catch(() => {}), p.evaluate(() => { nuHasBeenEdited(); nuGetBreadcrumb(); })]);
		await p.waitForLoadState('networkidle'); p.off('dialog', onDlg);
		const leave = await p.evaluate(() => !!document.getElementById('nuLeave') && document.getElementById('nuLeave').classList.contains('show'));
		expect(asked === 0 && !leave, 'launch form left without asking: dialogs ' + asked + ', leave ' + leave);
		// (20) 0.8.976: a form opened from an edit form gets that record's fields as hash variables (below its own), as Forte's
		// nuHashFromEditForm; a lookup id its list does not contain is shown empty (the id too), as Forte
		const dsql = sql("SELECT IFNULL(REPLACE(TO_BASE64(sob_display_sql), '\\n', ''), 'NULL') FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleorder' AND sob_all_id = 'ord_total_display'");
		const ord976 = sql("SELECT CONCAT(sample_order_id, '|', ord_sample_customer_id) FROM sample_order ORDER BY 1 LIMIT 1").split('|');
		try {
			sql("UPDATE zzzzsys_object SET sob_display_sql = 'SELECT ''#cus_code#''' WHERE sob_all_zzzzsys_form_id = 'sampleorder' AND sob_all_id = 'ord_total_display'");
			const code = sql(`SELECT cus_code FROM sample_customer WHERE sample_customer_id = '${id}'`);
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
			await Promise.all([p.waitForURL(/f=sampleorder/, {timeout: 10000}), p.evaluate(() => nuForm('sampleorder', '-1', '', '', 0))]);
			await p.waitForSelector('#ord_total_display', {state: 'attached'});
			const fromRec = await p.evaluate(() => $('#ord_total_display').val());
			await open(p, 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuBrowseTable');
			await Promise.all([p.waitForURL(/f=sampleorder/, {timeout: 10000}), p.evaluate(() => nuForm('sampleorder', '-1', '', '', 0))]);
			await p.waitForSelector('#ord_total_display', {state: 'attached'});
			const fromList = await p.evaluate(() => $('#ord_total_display').val());
			expect(fromRec === code && fromList !== code, 'hash of the opening edit form: ' + JSON.stringify({fromRec, fromList, code}));
			sql(`UPDATE sample_order SET ord_sample_customer_id = 'e2e976none' WHERE sample_order_id = '${ord976[0]}'`);
			await open(p, 'index.php?p=form&f=sampleorder&r=' + ord976[0]); await p.waitForSelector('#nuSaveButton');
			const lk = await p.evaluate(() => [$('#ord_sample_customer_id').val(), $('#ord_sample_customer_idcode').val()]);
			expect(lk[0] === '' && lk[1] === '', 'lookup id not in its list: ' + JSON.stringify(lk));
		} finally {
			sql(`UPDATE sample_order SET ord_sample_customer_id = '${ord976[1]}' WHERE sample_order_id = '${ord976[0]}'`);
			execSync('mysql ' + DB, {input: 'UPDATE zzzzsys_object SET sob_display_sql = ' + (dsql === 'NULL' ? 'NULL' : "FROM_BASE64('" + dsql + "')") + " WHERE sob_all_zzzzsys_form_id = 'sampleorder' AND sob_all_id = 'ord_total_display'"});
		}
		// (21) 0.8.977: a multiple select without "Select2" is the browser's list box (no Tom Select), as Forte; fields of a new record
		// left empty are hash variables ('') of After Save – an e-mail subject "#cus_phone#" stayed unreplaced
		const msel = sql("SELECT CONCAT(IFNULL(sob_select_multiple, 'NULL'), '|', IFNULL(sob_select_2, 'NULL')) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_country'").split('|');
		try {
			sql("UPDATE zzzzsys_object SET sob_select_multiple = '1', sob_select_2 = '0' WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_country'");
			sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_AS'");
			sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('samplecustomer_AS', 'samplecustomer_AS', 'e2e', 'hide', '0', '1', 'nuDebug(nuReplaceHashVariables(\"e2e977[#cus_phone#]\"));')");
			await open(p, 'index.php?p=form&f=samplecustomer&r=-1'); await p.waitForSelector('#cus_country', {state: 'attached'});
			const ms = await p.evaluate(() => { const e = document.getElementById('cus_country'); return {multi: e.multiple, ts: !!e.tomselect}; });
			sql(`UPDATE zzzzsys_object SET sob_select_multiple = ${msel[0] === 'NULL' ? 'NULL' : "'" + msel[0] + "'"} WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_country'`);
			await open(p, 'index.php?p=form&f=samplecustomer&r=-1'); await p.waitForSelector('#cus_code', {state: 'attached'});
			await p.evaluate(() => { $('#cus_code').val('E2E977').change(); $('#cus_name').val('e2e 977').change(); nuSaveAction(); });
			await p.waitForTimeout(3000);
			const dbg = sql("SELECT deb_message FROM zzzzsys_debug WHERE deb_message LIKE '%e2e977%' ORDER BY deb_added DESC LIMIT 1");
			expect(ms.multi && !ms.ts && /e2e977\[\]/.test(dbg), 'list box / blank fields in the After Save hash: ' + JSON.stringify(ms) + ' ' + dbg.slice(0, 120));
		} finally {
			sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_AS'");
			sql("DELETE FROM sample_customer WHERE cus_code = 'E2E977'");
			sql("DELETE FROM zzzzsys_debug WHERE deb_message LIKE '%e2e977%'");
			sql(`UPDATE zzzzsys_object SET sob_select_multiple = ${msel[0] === 'NULL' ? 'NULL' : "'" + msel[0] + "'"}, sob_select_2 = ${msel[1] === 'NULL' ? 'NULL' : "'" + msel[1] + "'"} WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_country'`);
		}
		// (22) 0.8.978: a stored select value that is not an option shows nothing chosen (no extra option), as Forte; data-nu-form = ''
		// on the main form and the sub-form's id in its rows (also new rows from the template)
		const ctry = sql(`SELECT IFNULL(cus_country, 'NULL') FROM sample_customer WHERE sample_customer_id = '${id}'`);
		try {
			sql(`UPDATE sample_customer SET cus_country = 'Q9' WHERE sample_customer_id = '${id}'`);
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#cus_country', {state: 'attached'});
			const sv = await p.evaluate(() => ({v: nuGetValue('cus_country'), extra: !!document.querySelector('#cus_country option[value="Q9"]'), nf: document.getElementById('cus_name').getAttribute('data-nu-form')}));
			expect(sv.v === '' && !sv.extra && sv.nf === '', 'select value not among the options / data-nu-form main: ' + JSON.stringify(sv));
		} finally {
			sql(`UPDATE sample_customer SET cus_country = ${ctry === 'NULL' ? 'NULL' : "'" + ctry + "'"} WHERE sample_customer_id = '${id}'`);
		}
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord976[0]); await p.waitForSelector('#sample_items', {state: 'attached'});
		const nf = await p.evaluate(() => [...document.querySelectorAll('#sample_items [data-nu-field][data-nu-prefix]')].map(e => e.getAttribute('data-nu-form')).filter((v, i, a) => a.indexOf(v) === i));
		expect(nf.length === 1 && nf[0] === 'sample_items', 'data-nu-form in sub-form rows: ' + JSON.stringify(nf));
		// (8) nuAddJavascript of Before Edit and the form's JavaScript in one script: a function declared in the form's code can be called
		const beOld = sql("SELECT IFNULL(REPLACE(TO_BASE64(sph_php), '\\n', ''), 'NULL') FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_BE'");
		const ej = sql("SELECT IFNULL(REPLACE(TO_BASE64(sfo_edit_javascript), '\\n', ''), 'NULL') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		try {
			execSync('mysql ' + DB, {input: (beOld === '' ? "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('samplecustomer_BE', 'samplecustomer_BE', 'e2e', 'hide', '0', '1', '');" : '')
				+ "UPDATE zzzzsys_php SET sph_php = CONCAT(IFNULL(sph_php, ''), '\nnuAddJavascript(\"window.e2eHoist = e2eHoistFn();\");') WHERE zzzzsys_php_id = 'samplecustomer_BE';"
				+ "UPDATE zzzzsys_form SET sfo_edit_javascript = CONCAT(IFNULL(sfo_edit_javascript, ''), '\nfunction e2eHoistFn() { return 42; }') WHERE zzzzsys_form_id = 'samplecustomer';"});
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
			const hz = await p.evaluate(() => window.e2eHoist);
			expect(hz === 42, 'Before Edit code calling a function of the form JavaScript: ' + hz);
		} finally {
			execSync('mysql ' + DB, {input: (beOld === '' ? "DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_BE';"
				: 'UPDATE zzzzsys_php SET sph_php = ' + (beOld === 'NULL' ? 'NULL' : "FROM_BASE64('" + beOld + "')") + " WHERE zzzzsys_php_id = 'samplecustomer_BE';")
				+ 'UPDATE zzzzsys_form SET sfo_edit_javascript = ' + (ej === 'NULL' ? 'NULL' : "FROM_BASE64('" + ej + "')") + " WHERE zzzzsys_form_id = 'samplecustomer'"});
		}
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_965'");
		execSync('mysql ' + DB, {input: 'ALTER TABLE sample_customer DROP COLUMN sample_customer_nulog'});
		execSync('mysql ' + DB, {input: 'UPDATE zzzzsys_setup SET set_header = ' + (hdr === 'NULL' ? 'NULL' : "FROM_BASE64('" + hdr + "')")});
		if (css && fs.existsSync(css)) fs.unlinkSync(css);
	}
};

T.current_datetime_format = async (s) => {
	// nuCurrentDateTime(format) as nuBuilder Forte: a format without 'D|' is a date format; nuSetValue with it fills a field and marks it edited
	const p = s.ga;
	await open(p, 'index.php?p=form&f=sampleorder&r=-1'); await p.waitForSelector('#nuSaveButton');
	const r = await p.evaluate(() => {
		const plain = nuCurrentDateTime(), f1 = nuCurrentDateTime('dd.mm.yyyy hh:nn'), f2 = nuCurrentDateTime('D|dd.mm.yyyy hh:nn');
		nuSetValue('ord_note', plain);
		return {plain, f1, f2, val: document.getElementById('ord_note').value, edited: !nuIsSaved()};
	});
	expect(/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(r.plain) && /^\d\d\.\d\d\.\d{4} \d\d:\d\d$/.test(r.f1) && r.f1 === r.f2, 'formats: ' + JSON.stringify(r));
	expect(r.val === r.plain && r.edited, 'nuSetValue fills the field and marks the record: ' + JSON.stringify(r));
};

T.forte_ctxmenu = async (s) => {
	// context menus of form code as in nuBuilder Forte: a button's onclick → nuOpenContextMenu(event, menu, this) with nuContextMenuItemText
	const p = s.ga;
	const ord = sql("SELECT sample_order_id FROM sample_order ORDER BY ord_number LIMIT 1");
	if (!ord) { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=form&f=sampleorder&r=' + ord); await p.waitForSelector('#nuSaveButton');
	await p.evaluate(() => {
		window.e2eCtx = [];
		window.e2eMenu = function (el, ev) {
			nuOpenContextMenu(ev, [
				{text: nuTranslate('Reports')},
				{text: nuContextMenuItemText('First', 'fa-solid fa fa-table'), action: (e) => window.e2eCtx.push('first:' + (e && e.type))},
				{isDivider: true},
				{text: 'More', subMenu: [{text: 'Inner', action: () => window.e2eCtx.push('inner')}]},
				{text: 'Off', disabled: true, action: () => window.e2eCtx.push('off')}
			], el);
		};
		const b = document.createElement('button');
		b.id = 'e2eCtxBtn'; b.type = 'button'; b.textContent = 'Menu';
		b.setAttribute('onclick', 'e2eMenu(this, event)');
		b.style.cssText = 'position:fixed;left:40px;top:200px;z-index:2000';
		document.body.appendChild(b);
	});
	await p.click('#e2eCtxBtn');
	await p.waitForTimeout(200);
	const m = await p.evaluate(() => {
		const ul = document.querySelector('ul.nuCtxMenu');
		if (!ul) return null;
		const b = document.getElementById('e2eCtxBtn').getBoundingClientRect(), r = ul.getBoundingClientRect();
		return {head: ul.querySelector('.nuCtxHead').textContent, items: ul.querySelectorAll('.dropdown-item').length, icon: !!ul.querySelector('.dropdown-item i.fa-table'),
			label: ul.querySelector('.nuCtxLabel') ? ul.querySelector('.nuCtxLabel').textContent : '', beside: Math.round(r.left) >= Math.round(b.right) - 1 && Math.abs(r.top - b.top) < 2,
			off: ul.querySelectorAll('.dropdown-item.disabled').length, div: ul.querySelectorAll('.dropdown-divider').length};
	});
	expect(m && m.head === 'Reports' && m.items === 3 && m.icon && m.label === 'First' && m.off === 1 && m.div === 1, 'menu drawn: ' + JSON.stringify(m));
	expect(m.beside, 'menu opens beside the button: ' + JSON.stringify(m));
	await p.click('ul.nuCtxMenu .dropdown-item:has-text("First")');
	await p.waitForTimeout(150);
	let st = await p.evaluate(() => ({log: window.e2eCtx.slice(), open: !!document.querySelector('ul.nuCtxMenu')}));
	expect(st.log.join() === 'first:click' && !st.open, 'action runs and the menu closes: ' + JSON.stringify(st));
	// sub menu, disabled item, Esc, click outside
	await p.click('#e2eCtxBtn'); await p.waitForTimeout(150);
	await p.hover('ul.nuCtxMenu .nuOmSubWrap > .dropdown-item'); await p.waitForTimeout(150);
	await p.click('ul.nuCtxMenu .nuOmSub .dropdown-item:has-text("Inner")');
	await p.click('#e2eCtxBtn'); await p.waitForTimeout(150);
	await p.click('ul.nuCtxMenu .dropdown-item.disabled', {force: true});
	await p.keyboard.press('Escape'); await p.waitForTimeout(100);
	const esc = await p.evaluate(() => !document.querySelector('ul.nuCtxMenu'));
	await p.click('#e2eCtxBtn'); await p.waitForTimeout(150);
	await p.mouse.click(600, 500); await p.waitForTimeout(100);
	st = await p.evaluate(() => ({log: window.e2eCtx.slice(), open: !!document.querySelector('ul.nuCtxMenu'), att: typeof ctxmenu.attach}));
	expect(st.log.join() === 'first:click,inner' && esc && !st.open && st.att === 'function', 'sub menu / disabled / Esc / outside: ' + JSON.stringify(st) + ' esc ' + esc);
	// ctxmenu.attach: right-click on an element; nuContextMenuClose
	await p.evaluate(() => ctxmenu.attach('#e2eCtxBtn', [{text: 'Attached', action: () => window.e2eCtx.push('att')}]));
	await p.click('#e2eCtxBtn', {button: 'right'}); await p.waitForTimeout(150);
	const att = await p.evaluate(() => { const ok = !!document.querySelector('ul.nuCtxMenu'); nuContextMenuClose(); return ok && !document.querySelector('ul.nuCtxMenu'); });
	expect(att, 'ctxmenu.attach opens on right-click, nuContextMenuClose closes');
	// dark mode: menu uses the theme's colours
	await p.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'dark'));
	await p.click('#e2eCtxBtn'); await p.waitForTimeout(150);
	const bg = await p.evaluate(() => getComputedStyle(document.querySelector('ul.nuCtxMenu')).backgroundColor);
	await p.screenshot({path: (process.env.SHOTS || '/tmp') + '/e2e_ctxmenu_dark.png'});
	await p.keyboard.press('Escape');
	await p.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'light'));
	expect(!/255, 255, 255/.test(bg), 'dark menu background: ' + bg);
};

T.forte_align = async (s) => {
	// 0.8.951: functions that behaved differently from nuBuilder Forte 4.9 (review list J1–J18, P1–P7)
	const p = s.ga;
	const ord = sql("SELECT sample_order_id FROM sample_order ORDER BY ord_number LIMIT 1");
	const cus = sql("SELECT CONCAT(sample_customer_id, '|', cus_code) FROM sample_customer WHERE sample_customer_id <> (SELECT ord_sample_customer_id FROM sample_order WHERE sample_order_id = '" + ord + "') ORDER BY cus_code LIMIT 1");
	if (!ord || !cus) { s.skip = 'no sample data'; return; }
	const [cusId, cusCode] = cus.split('|');
	const php = `nuRunQuery('DROP TABLE IF EXISTS e2e_align_t');
nuCreateTableFromSelect('e2e_align_t', 'SELECT 1 AS a');
$again = 'no error';
if (nuCreateTableFromSelect('e2e_align_t', 'SELECT 2 AS a') === -1) $again = 'refused';
$kept = db_fetch_row(nuRunQuery('SELECT a FROM e2e_align_t'))[0];
nuRunQuery('DROP TABLE e2e_align_t');
nuSetProperty('E2E_P', 'north'); nuSetProperty('E2E_Z', '0');
$rows = json_decode(base64_decode(nuEncodeQueryRowResults('SELECT 7 UNION SELECT 8', [], ['x'])));
$set = db_fetch_row(nuRunQuery("#SET @e2e_n := 41\\nSELECT @e2e_n + 1"))[0];
$proc = nuProcedure('e2e_align_inc') !== '';
nuAddJavascript('window.e2eAlign = ' . json_encode(['again' => $again, 'kept' => $kept, 'has' => nuHasProperty('E2E_P'), 'zero' => nuHasProperty('E2E_Z'), 'rows' => $rows, 'set' => $set, 'proc' => $proc, 'arr' => nuGetProperty('k', ['k' => 'v'])]) . ';');`;
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id IN ('e2e_align', 'e2e_align_inc')");
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('e2e_align', 'e2e_align', 'e2e', 'hide', '1', '0', " + JSON.stringify(php) + "), ('e2e_align_inc', 'e2e_align_inc_code', 'e2e', 'hide', '1', '0', '$x = 1;')"});
	try {
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord); await p.waitForSelector('#nuSaveButton');
		const r = await p.evaluate(async (cusId) => {
			const o = {};
			o.saved0 = nuHasBeenSaved();
			o.tab = typeof nuSelectedTabNumber();
			nuSetValue('ord_note', 'quiet', undefined, false);
			o.quiet = nuIsSaved() && !document.getElementById('ord_note').classList.contains('nuEdited');
			o.label0 = nuGetValue('label_ord_note');
			nuSetValue('label_ord_note', 'Note X');
			o.label = nuGetValue('label_ord_note');
			nuSetValue('ord_sample_customer_id', cusId);
			await new Promise(res => setTimeout(res, 1200));
			o.code = document.getElementById('ord_sample_customer_idcode').value;
			nuSelectRemoveEmpty();
			o.empty = document.querySelectorAll('#ord_status option[value=""]').length;
			nuSubformEnable('sample_items', '', false);
			o.sfOff = $('#sample_items [data-nu-field]').filter(function () { return !this.disabled && !this.readOnly; }).length;
			nuSubformEnable('sample_items');
			nuDisableAllObjects([], ['ord_note']);
			o.disAll = document.getElementById('ord_number').disabled || document.getElementById('ord_number').readOnly;
			o.noteOn = !(document.getElementById('ord_note').disabled || document.getElementById('ord_note').readOnly);
			nuEnableAllObjects();
			nuMessage('<h2>Head</h2>Body');
			o.msgTitle = $('#nuMessageDiv .nuMessageTitle').text();
			let cb = 0;
			nuMessage('Gone', 300, () => { cb++; });
			await new Promise(res => setTimeout(res, 1500));
			o.msgGone = !document.getElementById('nuMessageDiv') && cb === 1;
			nuAddActionButton('SaveClose');
			o.btn = nuGetValue('nuSaveCloseButton');
			nuSetValue('nuSaveCloseButton', 'Close it');
			o.btn2 = $('#nuSaveCloseButton .nuButtonLabel').text();
			nuSetToolTip('ord_note', 'Tip', true);
			$('#label_ord_note').trigger('mouseenter');
			o.tip = document.getElementById('label_ord_note').getAttribute('title');
			o.pad = nuPad2(5, 'x');
			window.e2eAlign = null; nuRunPHPHidden('e2e_align');
			return o;
		}, cusId);
		await p.waitForFunction(() => window.e2eAlign !== null, null, {timeout: 8000});
		const ph = await p.evaluate(() => window.e2eAlign);
		expect(r.saved0 === 0 && r.tab === 'string', 'nuHasBeenSaved / nuSelectedTabNumber: ' + JSON.stringify(r));
		expect(r.quiet, 'nuSetValue(…, false) does not mark edited');
		expect(r.label === 'Note X' && r.label0 !== '', 'label get / set: ' + r.label0 + ' / ' + r.label);
		expect(r.code === cusCode, 'nuSetValue on a lookup fills its code: ' + r.code + ' / ' + cusCode);
		expect(r.empty === 0 && r.sfOff === 0 && r.disAll && r.noteOn, 'remove empty / sub-form enable false / disable all: ' + JSON.stringify(r));
		expect(r.msgTitle === 'Head' && r.msgGone, 'nuMessage heading and timeout: ' + JSON.stringify(r));
		expect(r.btn === 'Save & Close' && r.btn2 === 'Close it', 'nuAddActionButton defaults / button value: ' + JSON.stringify(r));
		expect(r.tip === 'Tip' && r.pad === 'x5', 'tooltip on the label / nuPad2: ' + JSON.stringify(r));
		expect(ph.again === 'refused' && String(ph.kept) === '1', 'nuCreateTableFromSelect keeps an existing table: ' + JSON.stringify(ph));
		expect(ph.has === 'north' && ph.zero === false && ph.arr === 'v', 'nuHasProperty / nuGetProperty: ' + JSON.stringify(ph));
		expect(JSON.stringify(ph.rows) === '[["x"],[7],[8]]' || JSON.stringify(ph.rows) === '[["x"],["7"],["8"]]', 'nuEncodeQueryRowResults: ' + JSON.stringify(ph.rows));
		expect(String(ph.set) === '42' && ph.proc === true, '# commands / nuProcedure by id: ' + JSON.stringify(ph));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id IN ('e2e_align', 'e2e_align_inc'); DROP TABLE IF EXISTS e2e_align_t");
	}
};

T.forte_db_compat = async (s) => {
	// 0.8.956 (a nuBuilder Forte database tested side by side): a lookup into a form whose browse SQL reads its Before Browse table
	// (#TABLE_ID#, Forte's own File form), sub-form rows of nuSubformObject (new row ID -1, blank add row last), the database session's time zone
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	const cus = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1");
	const ord = sql("SELECT sample_order_id FROM sample_order ORDER BY ord_number LIMIT 1");
	if (!tab || !cus || !ord || !sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id = 'nufile'")) { s.skip = 'no sample data / File form'; return; }
	sql("ALTER TABLE sample_customer ADD e2e_file varchar(25) NULL");
	sql("INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_id, sob_all_label, sob_all_type, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_order, sob_lookup_code, sob_lookup_description, sob_lookup_zzzzsys_form_id, sob_lookup_description_width) VALUES ('e2e_lkfile', 'samplecustomer', '" + tab + "', 'e2e_file', 'File', 'lookup', 900, 20, 120, 18, 990, 'sfi_code', 'sfi_description', 'nufile', '150')");
	sql("UPDATE sample_customer SET e2e_file = 'nu5bad6cb37d02d01' WHERE sample_customer_id = '" + cus + "'");
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_tz'");
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('e2e_tz', 'e2e_tz', 'e2e', 'hide', '1', '0', " + JSON.stringify("nuAddJavascript('window.e2eTz = ' . json_encode([db_fetch_row(nuRunQuery('SELECT @@session.time_zone'))[0], date('P')]) . ';');") + ")"});
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cus);
		await p.waitForSelector('#e2e_filecode');
		expect(await p.inputValue('#e2e_filecode') === 'nubuilder', 'lookup code not read through the File form\'s Before Browse: ' + await p.inputValue('#e2e_filecode'));
		await p.evaluate(() => { window.e2eTz = null; nuRunPHPHidden('e2e_tz'); });
		await p.waitForFunction(() => window.e2eTz !== null, null, {timeout: 8000});
		const tz = await p.evaluate(() => window.e2eTz);
		expect(String(tz[0]).replace(/^\+?(\d):/, '+0$1:') === tz[1] || tz[0] === tz[1], 'session time zone is not the application\'s offset: ' + JSON.stringify(tz));
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord);
		const sf = await p.evaluate(() => { const o = nuSubformObject('sample_items'); return {first: o.rows[0][0], last: o.rows[o.rows.length - 1][0], del: o.deleted[o.deleted.length - 1]}; });
		expect(sf.first !== '-1' && sf.last === '-1' && sf.del === 1, 'nuSubformObject: blank row without ID -1: ' + JSON.stringify(sf));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2e_lkfile'; DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_tz'");
		sql("ALTER TABLE sample_customer DROP COLUMN e2e_file");
	}
};

T.forte_demo_compat = async (s) => {
	// 0.8.957 (nuBuilder Forte's demo database side by side): form code that reads nuSERVERRESPONSE.objects[i].js, the breadcrumb property
	// tab_start, the id / primary key of a grid sub-form cell (event.target.parentNode), an HTML object with an unclosed <iframe>
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'sampleorder' ORDER BY syt_order LIMIT 1");
	const ord = sql("SELECT sample_order_id FROM sample_order ORDER BY ord_number LIMIT 1");
	if (!tab || !ord) { s.skip = 'no sample data'; return; }
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_event (zzzzsys_event_id, sev_zzzzsys_object_id, sev_event, sev_javascript) VALUES ('e2e_dmev', 'sample_order_item_o2', 'onchange', 'window.e2eDm = 1;');"
		+ "INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_id, sob_all_label, sob_all_type, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_order, sob_html_code) VALUES "
		+ "('e2e_dmh1', 'sampleorder', '" + tab + "', 'e2e_dmh1', '', 'html', 900, 20, 200, 40, 991, '<iframe src=\"about:blank\">'), "
		+ "('e2e_dmh2', 'sampleorder', '" + tab + "', 'e2e_dmh2', '', 'html', 950, 20, 200, 40, 992, '<b id=\"e2eDmAfter\">after</b>')"});
	try {
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord);
		await p.waitForSelector('#e2eDmAfter', {state: 'attached', timeout: 8000}).catch(() => {});
		const r = await p.evaluate(() => {
			const objs = nuSERVERRESPONSE.objects || [];
			const bad = objs.filter(o => !Array.isArray(o.js)).length;
			const cell = document.querySelector('#sample_items000nuRECORD');
			const ts = nuFORM.getProperty('tab_start');
			return {n: objs.length, bad, ev: objs.some(o => (o.js || []).some(e => e.event === 'onchange' && /e2eDm/.test(e.js))) || JSON.stringify(nuSERVERRESPONSE.objects).includes('e2eDm'),
				cell: cell ? cell.getAttribute('data-nu-primary-key') : null, tab: ts && ts[0] ? ts[0].tabNumber : 'none', after: !!document.getElementById('e2eDmAfter')};
		});
		expect(r.n > 0 && r.bad === 0, 'objects without a js list: ' + JSON.stringify(r));
		expect(r.cell, 'grid sub-form cell without id / primary key: ' + JSON.stringify(r));
		expect(r.tab === '0', 'tab_start: ' + JSON.stringify(r));
		expect(r.after, 'HTML object after an unclosed <iframe> missing');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_event WHERE zzzzsys_event_id = 'e2e_dmev'; DELETE FROM zzzzsys_object WHERE zzzzsys_object_id IN ('e2e_dmh1', 'e2e_dmh2')");
	}
};

T.forte_announce = async (s) => {
	// 0.8.958 (nuBuilder Forte's announcements 2021–2025): attribute value= only for new records, select options from a procedure,
	// no blank item with validation No Blanks, display object with several columns, nuOnProcessObjects / nuOnProcessBrowseRows, print settings
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	const cus = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1");
	if (!tab || !cus) { s.skip = 'no sample data'; return; }
	if (sql("SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id IN ('samplecustomer_BE', 'samplecustomer_BB')") !== '0') { s.skip = 'sample form has BE / BB code'; return; }
	const obj = (id, field, type, extra) => "INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_id, sob_all_label, sob_all_type, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_order" + Object.keys(extra).map(k => ', ' + k).join('') + ") VALUES ('" + id + "', 'samplecustomer', '" + tab + "', '" + field + "', '" + field + "', '" + type + "', 900, 20, 150, 18, 990" + Object.values(extra).map(v => ', ' + JSON.stringify(v)).join('') + ");";
	sql("ALTER TABLE sample_customer ADD e2e_av varchar(30) NULL, ADD e2e_sel varchar(30) NULL");
	execSync('mysql ' + DB, {input: obj('e2e_o_av', 'e2e_av', 'input', {sob_input_type: 'text', sob_input_attribute: 'value=ABC, data-x="1"'})
		+ obj('e2e_o_sel', 'e2e_sel', 'select', {sob_select_procedure: 'e2e_selp', sob_select_sql: '', sob_all_validate: '1', sob_select_multiple: '0'})
		+ obj('e2e_o_dm', 'e2e_dm', 'display', {sob_display_sql: "SELECT 'l1', 'l2'"})
		+ "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES "
		+ "('e2e_selp', 'e2e_selp', 'e2e', 'hide', '1', '0', " + JSON.stringify("$result = [['a', 'Apple'], ['b', 'Banana']];") + "), "
		+ "('samplecustomer_BE', 'samplecustomer_BE', 'e2e', 'hide', '0', '0', " + JSON.stringify("function nuOnProcessObjects() {\n  foreach ($f->objects as $o) {\n    if ($o->id == 'cus_name') { $o->label = 'Renamed'; }\n  }\n}") + "), "
		+ "('samplecustomer_BB', 'samplecustomer_BB', 'e2e', 'hide', '0', '0', " + JSON.stringify("function nuOnProcessBrowseRows() {\n  for ($i = 0; $i < count($f->browse_rows); $i++) { $f->browse_rows[$i][1] = 'X-' . $f->browse_rows[$i][1]; }\n}") + ");"
		+ "UPDATE sample_customer SET e2e_av = 'stored', e2e_sel = 'b' WHERE sample_customer_id = '" + cus + "';"});
	try {
		// existing record: the stored value, not the attribute's
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cus);
		await p.waitForSelector('#e2e_av');
		let r = await p.evaluate(() => ({av: document.getElementById('e2e_av').value, attr: document.getElementById('e2e_av').getAttribute('data-x'),
			opts: Array.from(document.getElementById('e2e_sel').options).map(o => o.value + ':' + o.text).join('|'), sel: $('#e2e_sel').val(),
			dm: (document.getElementById('e2e_dm') || {}).value, dmTag: (document.getElementById('e2e_dm') || {}).tagName,
			label: ((document.getElementById('label_cus_name') || {}).textContent || '').trim()}));
		expect(r.av === 'stored' && r.attr === '1', 'existing record shows the attribute value: ' + JSON.stringify(r));
		expect(r.opts === 'a:Apple|b:Banana' && r.sel === 'b', 'select options from the procedure / no blank item: ' + JSON.stringify(r));
		expect(r.dmTag === 'TEXTAREA' && r.dm === 'l1\nl2', 'display object with two columns: ' + JSON.stringify(r));
		expect(r.label.indexOf('Renamed') === 0, 'nuOnProcessObjects label: ' + JSON.stringify(r));
		// new record: the attribute's value, saved; the select without choice
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
		await p.waitForSelector('#e2e_av');
		r = await p.evaluate(() => ({av: document.getElementById('e2e_av').value, idx: document.getElementById('e2e_sel').selectedIndex, val: $('#e2e_sel').val(), nv: nuGetValue('e2e_sel')}));
		expect(r.av === 'ABC' && r.idx === -1 && r.val === null && r.nv === '', 'new record: ' + JSON.stringify(r));
		// list: nuOnProcessBrowseRows; print view with nuPrintIncludeColumns([1])
		await open(p, 'index.php?p=form&f=samplecustomer');
		await p.waitForSelector('#nuBrowseTable div.nuCell');
		const first = await p.evaluate(() => document.querySelector('#nuBrowseTable div.nuCell[data-nu-column="0"]').textContent.trim());
		expect(first.indexOf('X-') === 0, 'nuOnProcessBrowseRows: ' + first);
		const url = await p.evaluate(() => new Promise(res => { nuPrintIncludeColumns([1]); nuPrintCSVExportOptions({delimiter: ',', fileName: 'e2e.csv'});
			nuNext.withScope(ps => { const u = new URL(document.querySelector('a.nuExpHtml').getAttribute('href'), location.href); u.searchParams.set('ps', ps); res(u.toString()); }); }));
		await p.goto(url);
		await p.waitForSelector('#nuBrowseTable');
		r = await p.evaluate(() => ({th: Array.from(document.querySelectorAll('#nuBrowseTable thead th')).filter(t => t.offsetParent !== null).map(t => t.textContent.trim()),
			sep: (document.querySelector('#nuCsvDialog [name=sep]') || {}).value, name: (document.getElementById('nuCsvName') || {}).value}));
		expect(r.th.length === 1 && r.th[0].indexOf('Name') === 0 && r.sep === 'comma' && r.name === 'e2e', 'print view settings: ' + JSON.stringify(r));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id IN ('e2e_o_av', 'e2e_o_sel', 'e2e_o_dm'); DELETE FROM zzzzsys_php WHERE zzzzsys_php_id IN ('e2e_selp', 'samplecustomer_BE', 'samplecustomer_BB')");
		sql("DELETE FROM sample_customer WHERE e2e_av = 'ABC'");
		sql("ALTER TABLE sample_customer DROP COLUMN e2e_av, DROP COLUMN e2e_sel");
	}
};

T.forte_sysforms = async (s) => {
	// 0.8.960 (nuBuilder Forte's own system forms opened as forms – the compat suite runs them all): input buttons are not validated, a
	// nuBeforeSave without a return value saves, String.prototype.containsAny / window.nuImages / $.fn.nuSearchableMultiPopup exist,
	// browse_filtered_rows / browse_rows / browse_columns in nuCurrentProperties(), a sub-form's label left of it, narrow buttons keep
	// their label, the tab pane carries data-nu-pane-tab (form code that writes into div[data-nu-tab-id] = the tab, not the pane)
	const p = s.ga;
	// save = the save request was sent (nuBeforeSave did not stop it) and nothing was refused
	const save = async () => {
		let posted = false; const on = q => { if (q.method() === 'POST' && /p=formapi/.test(q.url()) && !/presence/.test(q.url())) posted = true; };
		p.on('request', on);
		await p.evaluate(() => nuSaveAction()).catch(() => {}); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
		p.off('request', on);
		return posted && !await p.locator('.alert-danger, .is-invalid').count();
	};
	// Forms (nuform): a record with input buttons and sub-forms – the save was refused ("Objects must not be empty", a button validated)
	await open(p, 'index.php?p=form&f=nuform&r=nuuser');
	await p.waitForSelector('#nuEditForm');
	let r = await p.evaluate(() => { const l = document.querySelector('label.nuLabelSf'); const w = l && l.closest('.nuAbs'); const sf = w && w.querySelector('.nuSubform');
		return {lab: l ? l.getBoundingClientRect() : null, sf: sf ? sf.getBoundingClientRect() : null}; });
	expect(r.lab && r.sf && r.lab.right <= r.sf.left + 1 && Math.abs(r.lab.top - r.sf.top) < 12, 'sub-form label not left of the sub-form: ' + JSON.stringify(r));
	expect(await save(), 'Forms record not saved');
	// Objects (nuobject): nuBeforeSave() uses containsAny and returns nothing; quick buttons 50 px wide
	await open(p, 'index.php?p=form&f=nuobject&r=nu59e446589a1fc46');
	await p.waitForSelector('#nuEditForm');
	await p.waitForTimeout(600);
	r = await p.evaluate(() => ({ca: typeof ''.containsAny, bs: typeof nuBeforeSave === 'function' ? nuBeforeSave() : 'none',
		cut: Array.from(document.querySelectorAll('.nuQuickButton')).filter(b => b.scrollWidth > b.clientWidth + 1).length, n: document.querySelectorAll('.nuQuickButton').length,
		tight: document.querySelectorAll('.nuQuickButton.nuBtnTight').length}));
	expect(r.ca === 'function' && r.bs === undefined, 'containsAny / nuBeforeSave: ' + JSON.stringify(r));
	expect(r.n > 0 && r.cut === 0 && r.tight > 0, 'quick buttons cut: ' + JSON.stringify(r));
	expect(await save(), 'Objects record not saved (nuBeforeSave without return)');
	// Files (nufile): list properties + nuImages; record tab "Linked Objects (n)"
	await open(p, 'index.php?p=form&f=nufile');
	await p.waitForSelector('#nuBrowseTable');
	r = await p.evaluate(() => { const c = nuCurrentProperties(); return {f: c.browse_filtered_rows, rows: Array.isArray(c.browse_rows), cols: c.browse_columns && c.browse_columns.length, img: Array.isArray(window.nuImages), multi: typeof $.fn.nuSearchableMultiPopup}; });
	expect(typeof r.f === 'number' && r.rows && r.cols > 0 && r.img && r.multi === 'function', 'list properties: ' + JSON.stringify(r));
	const fid = sql('SELECT zzzzsys_file_id FROM zzzzsys_file ORDER BY 1 LIMIT 1');
	if (fid) {
		await open(p, 'index.php?p=form&f=nufile&r=' + fid);
		await p.waitForSelector('#nuEditForm');
		await p.waitForFunction(() => Array.from(document.querySelectorAll('.nuTab')).some(t => /\(\d+\)/.test(t.textContent)), null, {timeout: 10000}).catch(() => {});   // set by the run iframe's browse JS
		const tabs = await p.evaluate(() => Array.from(document.querySelectorAll('.nuTab')).map(t => t.textContent.trim()));
		expect(tabs.some(t => /^Linked Objects \(\d+\)$/.test(t)), 'Files tab title: ' + JSON.stringify(tabs));
	}
	// Procedures (nuprocedure): its JS writes the tab title into div[data-nu-tab-id=…] – the pane must keep its objects
	await open(p, 'index.php?p=form&f=nuprocedure&r=nuprocedure_BE');
	await p.waitForSelector('#nuEditForm'); await p.waitForTimeout(800);
	r = await p.evaluate(() => ({pane: !!document.querySelector('.nuTabPane[data-nu-pane-tab="nu5fdf7df2d873dd1"] #accphp'), noTabId: !document.querySelector('.nuTabPane[data-nu-tab-id]')}));
	expect(r.pane && r.noTabId, 'Procedures pane: ' + JSON.stringify(r));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.forte_property_hook = async (s) => {
	// nuBuilder Forte: nuOnPropertySet(name, value) runs after nuSetProperty(…, true) stored the hash cookie (not without persist);
	// nuGetFirstObject(objects, tab) = first field a user can type into; nuAddHome() exists
	const p = s.ga;
	await open(p, 'index.php?p=form&f=sampleorder&r=-1'); await p.waitForSelector('#nuSaveButton');
	const r = await p.evaluate(async () => {
		const got = [];
		window.nuOnPropertySet = (n, v) => got.push(n + '=' + v + '/' + nuGetGlobalProperties()[n]);
		nuSetProperty('E2E_PLAIN', 'a');
		await nuSetProperty('E2E_HOOK', 'b', true);
		const any = nuGetFirstObject(nuSERVERRESPONSE.objects, -1), tab = nuGetFirstObject(nuSERVERRESPONSE.objects, nuSelectedTabNumber());
		const first = any && any[0], ok = first && !first.disabled && !first.readOnly && !!(first.offsetWidth || first.offsetHeight);
		nuAddHome();
		await nuDeleteProperty('E2E_HOOK');
		return {got, any: first && first.id, tab: tab && tab[0] && tab[0].id, ok, none: nuGetFirstObject([], -1), far: nuGetFirstObject(nuSERVERRESPONSE.objects, 99)};
	});
	expect(JSON.stringify(r.got) === '["E2E_HOOK=b/b"]', 'hook once, after the cookie is stored: ' + JSON.stringify(r));
	expect(r.any && r.ok && r.any === r.tab && r.none === null && r.far === null, 'first object: ' + JSON.stringify(r));
};

T.forte_legacy_code = async (s) => {
	// code written for older nuBuilder Forte versions: a lookup form whose list does not select its key, $phpid, nuSubformObject(''),
	// #form_code#, includes of Forte's core files, the old names of the upload procedures; tab panes are not "nuTab…" elements
	const p = s.ga;
	const b64 = (t) => Buffer.from(t).toString('base64');
	const keepSql = sql("SELECT sfo_browse_sql FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	const ord = sql("SELECT sample_order_id FROM sample_order WHERE IFNULL(ord_sample_customer_id, '') <> '' ORDER BY sample_order_id LIMIT 1");
	const code = sql("SELECT cus_code FROM sample_customer c JOIN sample_order o ON o.ord_sample_customer_id = c.sample_customer_id WHERE o.sample_order_id = '" + ord + "'");
	const hadBs = sql("SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id = 'sampleorder_BS'") === '1';
	const keepBs = hadBs ? sql("SELECT TO_BASE64(sph_php) FROM zzzzsys_php WHERE zzzzsys_php_id = 'sampleorder_BS'").replace(/\s+/g, '') : '';
	const bs = "require_once ('nusecurity.php');\n$sf = nuSubformObject('');\nnuDisplayError('E2E|' . substr($phpid, -2) . '|' . $sf->table . '|' . $sf->primary_key . '|' . nuHash()['form_code']);";
	try {
		sql("UPDATE zzzzsys_form SET sfo_browse_sql = FROM_BASE64('" + b64("SELECT cus_code, cus_name FROM sample_customer WHERE cus_code <> 'e2e-none'") + "') WHERE zzzzsys_form_id = 'samplecustomer'");
		if (hadBs) sql("UPDATE zzzzsys_php SET sph_php = FROM_BASE64('" + b64(bs) + "') WHERE zzzzsys_php_id = 'sampleorder_BS'");
		else sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_group, sph_php, sph_system) VALUES ('sampleorder_BS', 'sampleorder_BS', 'System PHP', 'nubuilder', FROM_BASE64('" + b64(bs) + "'), '1')");
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_upl_proc'; INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_group, sph_php, sph_global) VALUES ('e2e_upl_proc', 'nu_upload_file_e2e', 'e2e', 'e2e', FROM_BASE64('" + b64("nuDisplayError('ALIAS ' . $phpid);") + "'), '1')");
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord); await p.waitForSelector('#nuSaveButton');
		const st = await p.evaluate(() => ({ code: document.getElementById('ord_sample_customer_idcode').value, id: document.getElementById('ord_sample_customer_id').value,
			divs: Array.from(document.querySelectorAll('div[id^=nuTab]')).map((d) => d.id).filter((x) => x !== 'nuTabHolder'), panes: document.querySelectorAll('.nuTabPane[id^=nuPane]').length }));
		expect(st.code === code && st.id !== '', 'lookup read from the tables of a list without the key: ' + JSON.stringify(st) + ' / ' + code);
		expect(st.divs.length === 0 && st.panes >= 1, 'no div called nuTab… but the tab holder: ' + JSON.stringify(st));
		await p.evaluate(() => nuRunPHPHidden('NUUPLOADFILE_e2e'));
		await p.waitForFunction(() => document.body.innerText.includes('ALIAS e2e_upl_proc'), null, { timeout: 8000 });
		await p.reload(); await p.waitForSelector('#nuSaveButton');
		await p.evaluate(() => { nuHasBeenEdited(); document.getElementById('nuSaveButton').click(); });
		const fc = sql("SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'");
		await p.waitForFunction((t) => document.body.innerText.includes(t), 'E2E|BS|sample_order|sample_order_id|' + fc, { timeout: 8000 });
		await p.evaluate(() => nuHasNotBeenEdited());
	} finally {
		sql("UPDATE zzzzsys_form SET sfo_browse_sql = FROM_BASE64('" + b64(keepSql) + "') WHERE zzzzsys_form_id = 'samplecustomer'; DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_upl_proc'");
		if (hadBs) sql("UPDATE zzzzsys_php SET sph_php = FROM_BASE64('" + keepBs + "') WHERE zzzzsys_php_id = 'sampleorder_BS'"); else sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'sampleorder_BS'");
	}
};

T.forte_legacy_code2 = async (s) => {
	// more code of older nuBuilder Forte versions: the <table>_nulog column changes on every view (no delete conflict), form code that
	// replaces nuIsVisible() with an id-only version, a lookup's description moved with css top / left, Uppy built by the form's own code
	const p = s.ga;
	const cust = sql('SELECT MIN(sample_customer_id) FROM sample_customer');
	const cols = sql("SELECT GROUP_CONCAT(column_name) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sample_customer' AND column_name <> 'sample_customer_id'");
	const hadNulog = sql("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sample_customer' AND column_name = 'sample_customer_nulog'") === '1';
	try {
		if (!hadNulog) sql('ALTER TABLE sample_customer ADD sample_customer_nulog VARCHAR(1000) NULL');
		sql("DELETE FROM sample_customer WHERE sample_customer_id = 'e2e_cc_del'; INSERT INTO sample_customer (sample_customer_id, " + cols + ", sample_customer_nulog) SELECT 'e2e_cc_del', " + cols.split(',').map((c) => c === 'cus_code' ? "'E2ECCDEL'" : c).join(', ') + ", NULL FROM sample_customer WHERE sample_customer_id = '" + cust + "'");
		await open(p, 'index.php?p=form&f=samplecustomer&r=e2e_cc_del'); await p.waitForSelector('#nuDeleteButton'); await p.waitForTimeout(600);
		expect(sql("SELECT IFNULL(sample_customer_nulog, '') FROM sample_customer WHERE sample_customer_id = 'e2e_cc_del'").includes('viewed'), 'nulog column not written on view');
		// form code that replaces nuIsVisible() with a version for ids only must not break nuBuilder Next's own calls
		const fo = await p.evaluate(() => { window.nuIsVisible = function (id) { const x = typeof id === 'string' ? $('#' + id) : id; return x.css('display') !== 'none'; }; try { const r = nuGetFirstObject(window.nuSERVERRESPONSE ? nuSERVERRESPONSE.objects : [], -1); return 'ok ' + (r ? r.attr('id') : 'none'); } catch (e) { return 'ERR ' + e.message; } });
		expect(/^ok /.test(fo), 'nuGetFirstObject with a replaced nuIsVisible: ' + fo);
		await p.click('#nuDeleteButton'); await p.waitForSelector('.modal.show .btn-danger'); await p.click('.modal.show .btn-danger'); await p.waitForTimeout(1500);
		expect(sql("SELECT COUNT(*) FROM sample_customer WHERE sample_customer_id = 'e2e_cc_del'") === '0', 'record not deleted (conflict because of the nulog column?)');
		// a lookup's description placed by form code below its code field (form coordinates, as in nuBuilder Forte)
		const ord = sql("SELECT sample_order_id FROM sample_order WHERE IFNULL(ord_sample_customer_id, '') <> '' ORDER BY sample_order_id LIMIT 1");
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord + '&layout=absolute'); await p.waitForSelector('#nuSaveButton');
		const pos = await p.evaluate(() => {
			const l = $('#ord_sample_customer_idcode').nuCSSNumber('left'), t = $('#ord_sample_customer_iddescription').nuCSSNumber('top');
			$('#ord_sample_customer_iddescription').css('left', l); $('#ord_sample_customer_iddescription').css('top', t + 27);
			const c = document.getElementById('ord_sample_customer_idcode').getBoundingClientRect(), d = document.getElementById('ord_sample_customer_iddescription').getBoundingClientRect();
			return { dx: Math.round(d.left - c.left), dy: Math.round(d.top - c.top), read: $('#ord_sample_customer_iddescription').nuCSSNumber('left') === l };
		});
		expect(Math.abs(pos.dx) <= 2 && Math.abs(pos.dy - 27) <= 2 && pos.read, 'description below the code: ' + JSON.stringify(pos));
		await p.evaluate(() => nuHasNotBeenEdited());
	} finally {
		sql("DELETE FROM sample_customer WHERE sample_customer_id = 'e2e_cc_del'");
		if (!hadNulog) sql('ALTER TABLE sample_customer DROP COLUMN sample_customer_nulog');
	}
	// Uppy built by the form's code (new Uppy.Core() + XHRUpload to core/nuapi.php) – needs the Uppy pack
	if (!SITE || !fs.existsSync(path.join(SITE, 'vendor/packs')) || !fs.readdirSync(path.join(SITE, 'vendor/packs')).some((d) => /^uppy-/.test(d))) return;
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nunext-e2e-uppy2-')); fs.chmodSync(dir, 0o777);
	const php = (k) => execSync(`php -r 'define("NU_ROOT", "."); require "core/nuupload.php"; echo NuUpload::${k};'`).toString();
	const hex = (t) => Buffer.from(t, 'utf8').toString('hex');
	const proc = php('PROCEDURE').replace("$targetDirectory = dirname($_SERVER['DOCUMENT_ROOT']) . '/nu_uploads/';", `$targetDirectory = '${dir}/';`);
	const html = php('HTML').replace("procedure: 'nu_upload_file_template'", "procedure: 'e2e_upload2'").replace(/nuUppyCreate\(\)/, 'new Uppy.Core()')
		.replace("$(document).ready(function () {", "window.e2eUppy = [];\nwindow.nuOnFileUploadComplete = function (t, id, r, m) { window.e2eUppy.push(t + '|' + id + '|' + m); };\n$(document).ready(function () {");
	expect(html.includes('new Uppy.Core()'), 'template not changed');
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	try {
		sql(`INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_run, sph_global, sph_system, sph_status) VALUES ('e2e_upload2', 'e2e_upload2', 'e2e', UNHEX('${hex(proc)}'), 'hide', '1', '0', '1')`);
		sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_all_validate, sob_input_type, sob_input_file_target, sob_html_code)
			VALUES ('e2e_uppy2_obj', 'samplecustomer', '${tab}', 'input', 'e2e_uppy2', 'Upload', 997, 900, 20, 500, 320, '0', '0', 'file', '1', UNHEX('${hex(html)}'))`);
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust); await p.waitForSelector('#e2e_uppy2_uppy_div .uppy-Dashboard', { timeout: 10000 });
		const f = path.join(dir, 'src.png'); fs.writeFileSync(f, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64'));
		const up = p.waitForResponse((r) => /p=upload/.test(r.url()), { timeout: 15000 });
		await p.setInputFiles('#e2e_uppy2_uppy_div input.uppy-Dashboard-input', f); await p.click('#e2e_uppy2_uppy_div .uppy-StatusBar-actionBtn--upload');
		const r = await up;
		expect(r.status() === 201, 'self-built Uppy upload: ' + r.status() + ' ' + (await r.text()).slice(0, 200));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2e_uppy2_obj'; DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_upload2'");
		fs.rmSync(dir, { recursive: true, force: true });
	}
};

T.record_unknown_id = async (s) => {
	// as nuBuilder Forte: a record id that is not in the table opens the form empty under that id – the sub-form lists the rows of this
	// id and a save writes them, but no main record
	const p = s.ga, id = 'e2e_ghost_1';
	const src = sql("SELECT sample_order_item_id FROM sample_order_item ORDER BY sample_order_item_id LIMIT 1");
	sql("DELETE FROM sample_order_item WHERE ori_sample_order_id = '" + id + "'; DELETE FROM sample_order WHERE sample_order_id = '" + id + "'");
	const cols = sql("SELECT GROUP_CONCAT(column_name) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sample_order_item' AND column_name NOT IN ('sample_order_item_id', 'ori_sample_order_id')");
	sql("INSERT INTO sample_order_item (sample_order_item_id, ori_sample_order_id, " + cols + ") SELECT 'e2e_ghost_item', '" + id + "', " + cols + " FROM sample_order_item WHERE sample_order_item_id = '" + src + "'");
	try {
		const res = await p.goto(BASE + 'index.php?p=form&f=sampleorder&r=' + id); await p.waitForSelector('#nuSaveButton');
		expect(res.status() === 200, 'status ' + res.status());
		const st = await p.evaluate(() => ({ rec: nuCurrentProperties().record_id, isNew: nuIsNewRecord(), rows: Array.from(document.querySelectorAll('#sample_items .nuSubformRow:not(.nuNewRow)')).filter(x => !x.closest('template')).length }));
		expect(st.rec === id && st.rows === 1, 'empty form under the id, one sub-form row: ' + JSON.stringify(st));
		await p.evaluate(() => { const h = document.getElementById('ord_sample_customer_id'); h.value = 'sample_cus_01'; });   // the required lookup of the main form
		const q = p.locator('input[data-nu-field="ori_qty"]').first();
		await q.fill('7'); await q.dispatchEvent('change');
		await save(p);
		expect(Number(sql("SELECT ori_qty FROM sample_order_item WHERE sample_order_item_id = 'e2e_ghost_item'")) === 7, 'sub-form row saved');
		expect(sql("SELECT COUNT(*) FROM sample_order WHERE sample_order_id = '" + id + "'") === '0', 'no main record written');
	} finally {
		sql("DELETE FROM sample_order_item WHERE ori_sample_order_id = '" + id + "'; DELETE FROM sample_order WHERE sample_order_id = '" + id + "'");
	}
};

T.checkbox_value_attr = async (s) => {
	// checkboxes of a saved record carry the stored value as value attribute ('' for NULL), as nuBuilder Forte's nuINPUTSetValue –
	// form code reading $(…).val() gets '1' / ''; a new record without a value keeps none ($(…).val() = 'on'); the save uses .checked
	const p = s.ga;
	const on = sql("SELECT MIN(sample_all_id) FROM sample_all WHERE all_checkbox = '1'"), off = sql('SELECT MIN(sample_all_id) FROM sample_all WHERE all_checkbox IS NULL');
	expect(on && off, 'sample_all needs a ticked and a NULL row');
	const read = async (r) => { await open(p, 'index.php?p=form&f=sampleall&r=' + r); await p.waitForSelector('#all_checkbox', {state: 'attached'});
		return p.evaluate(() => { const e = document.getElementById('all_checkbox'); return {attr: e.getAttribute('value'), jq: $(e).val(), checked: e.checked, get: nuGetValue('all_checkbox')}; }); };
	const a = await read(on), b = await read(off), n = await read('-1');
	expect(a.attr === '1' && a.jq === '1' && a.checked && a.get === true, 'ticked: ' + JSON.stringify(a));
	expect(b.attr === '' && b.jq === '' && !b.checked && b.get === false, 'NULL: ' + JSON.stringify(b));
	expect(n.attr === null && n.jq === 'on', 'new record: ' + JSON.stringify(n));
	// ticking a NULL box still saves '1' (from .checked, not the value attribute)
	await open(p, 'index.php?p=form&f=sampleall&r=' + off); await p.waitForSelector('#all_checkbox', {state: 'attached'});
	try {
		await p.evaluate(() => { const e = document.getElementById('all_checkbox'); e.click(); });
		await p.click('#nuSaveButton'); await p.waitForTimeout(1500);
		expect(sql("SELECT IFNULL(all_checkbox, 'NULL') FROM sample_all WHERE sample_all_id = '" + off + "'") === '1', 'tick not saved');
	} finally { sql("UPDATE sample_all SET all_checkbox = NULL WHERE sample_all_id = '" + off + "'"); }
};

T.framed_list_outer_values = async (s) => {
	// a list shown in a run object (iframe) that reloads itself (nuGetBreadcrumb(0)) after a filter select of the outer form changed:
	// its hash variables come from the outer form's current fields, as nuBuilder Forte's parent.nuHashFromEditForm(); nuHideHolders(0, 1)
	// in the frame hides the search row and the title; nuFORM.getCurrent().browse_sql = the list's SQL filled in; a narrow select keeps its value readable
	const p = s.ga, L = 'e2e_fr_launch', B = 'e2e_fr_list', T = 'e2e_fr_tab';
	const b64 = (t) => Buffer.from(t).toString('base64');
	const drop = () => sql(`DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id IN ('${L}', '${B}'); DELETE FROM zzzzsys_event WHERE zzzzsys_event_id = 'e2e_fr_ev';
		DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id IN ('${L}', '${B}'); DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${B}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id IN ('${L}', '${B}')`);
	drop();
	try {
		const bsql = "SELECT * FROM sample_customer WHERE IFNULL(cus_country, '') = '#e2e_fr_pick#' ORDER BY cus_code";
		sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_table, sfo_primary_key, sfo_browse_sql, sfo_browse_javascript, sfo_browse_rows_per_page, sfo_json) VALUES
			('${B}', 'browse', '${B}', 'E2E framed list', 'sample_customer', 'sample_customer_id', FROM_BASE64('${b64(bsql)}'), 'if (nuIsIframe()) { nuHideHolders(0, 1); }', 50,
				'{"nunext":{"browse_sql_client":true}}'),
			('${L}', 'launch', '${L}', 'E2E outer form', '', '', '', '', 0, NULL);
			INSERT INTO zzzzsys_tab (zzzzsys_tab_id, syt_zzzzsys_form_id, syt_title, syt_order) VALUES ('${T}', '${L}', 'Main', 10), ('${T}b', '${B}', 'Main', 10);
			INSERT INTO zzzzsys_browse (zzzzsys_browse_id, sbr_zzzzsys_form_id, sbr_title, sbr_display, sbr_align, sbr_width, sbr_order) VALUES ('e2e_fr_b1', '${B}', 'Code', 'cus_code', 'l', 100, 10), ('e2e_fr_b2', '${B}', 'Country', 'cus_country', 'l', 80, 20);
			INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_id, sob_all_label, sob_all_type, sob_select_sql, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_order, sob_input_file_target) VALUES
			('e2e_fr_o1', '${L}', '${T}', 'e2e_fr_pick', 'Country', 'select', 'GB|GB|US|US|CA|CA', 20, 120, 54, 20, 10, 0);
			INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_id, sob_all_label, sob_all_type, sob_run_zzzzsys_form_id, sob_run_method, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_order, sob_input_file_target) VALUES
			('e2e_fr_o2', '${L}', '${T}', 'e2e_fr_run', '', 'run', '${B}', 'i', 60, 20, 600, 400, 20, 0);
			INSERT INTO zzzzsys_event (zzzzsys_event_id, sev_zzzzsys_object_id, sev_event, sev_javascript) VALUES ('e2e_fr_ev', 'e2e_fr_o1', 'onchange', '$("#e2e_fr_run")[0].contentWindow.nuGetBreadcrumb(0);');`);
		await open(p, 'index.php?p=form&f=' + L + '&r=-1&layout=absolute'); await p.waitForSelector('#e2e_fr_run', {state: 'attached'}); await p.waitForTimeout(1500);
		const frame = async () => { const h = await p.$('#e2e_fr_run'); const f = h ? await h.contentFrame() : null; await f.waitForSelector('#nuBrowseTable', {state: 'attached'}); await p.waitForTimeout(400); return f; };
		const read = async () => (await frame()).evaluate(() => ({codes: Array.from(document.querySelectorAll('#nuRECORD tr.nuBrowseRow')).map(r => (r.querySelector('div.nuCell') || {}).textContent.trim()),
			search: (() => { const e = document.querySelector('.nuBrowseSearch, .nuListSearch'); return !!(e && e.offsetWidth); })(),
			title: (() => { const e = document.getElementById('nuBreadcrumbHolder'); return !!(e && e.offsetWidth); })(),
			bs: String(nuFORM.getCurrent().browse_sql)}));
		const pick = async (v) => { await p.evaluate((v) => { const e = document.getElementById('e2e_fr_pick'); e.value = v; jQuery(e).trigger('change'); }, v);
			await p.waitForFunction((v) => { try { return document.getElementById('e2e_fr_run').contentWindow.nuFORM.getCurrent().browse_sql.includes("'" + v + "'"); } catch (x) { return false; } }, v, {timeout: 15000}).catch(() => {}); };
		for (const c of ['GB', 'US']) {
			await pick(c);
			const r = await read(), want = sql(`SELECT GROUP_CONCAT(cus_code ORDER BY cus_code SEPARATOR ',') FROM sample_customer WHERE IFNULL(cus_country, '') = '${c}'`);
			expect(r.codes.join(',') === (want === 'NULL' ? '' : want), c + ': rows ' + r.codes.join(',') + ' want ' + want);
			expect(!r.search && !r.title, c + ': search row / title still shown ' + JSON.stringify([r.search, r.title]));
			expect(/^SELECT sample_customer_id,cus_code,cus_country FROM \(SELECT \* FROM \(SELECT \* FROM sample_customer WHERE/.test(r.bs) && r.bs.includes("= '" + c + "'"), c + ': browse_sql ' + r.bs.slice(0, 200));
		}
		// the 54 px select shows its value: the text fits next to the arrow
		const sel = await p.evaluate(() => { const e = document.getElementById('e2e_fr_pick'); const cs = getComputedStyle(e); return {w: e.clientWidth, pr: parseFloat(cs.paddingRight), pl: parseFloat(cs.paddingLeft)}; });
		expect(sel.w - sel.pr - sel.pl >= 30, 'narrow select text room ' + JSON.stringify(sel));
	} finally { drop(); }
};

T.start_page_forte = async (s) => {
	// 0.8.1219: on a database from nuBuilder Forte (access levels that are not nuBuilder Next's samples) step 0044 sets every level with a
	// Home form to start on it; levels already set stay, sample levels and levels without a Home form keep tiles; the database is marked,
	// and a new access level created there picks "Home form" when a Home form is chosen
	if (!SITE) return 'skip: needs SITE';
	const p = s.ga, L = 'e2e_spf_lvl', N = 'e2e_spf_none';
	const php = (code) => require('child_process').execFileSync('php', ['-r', 'chdir(' + JSON.stringify(SITE) + "); require 'core/nuapp.php'; " + code]).toString();
	const keep = sql("SELECT IFNULL(MAX(setting_value), '-') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'");
	const mode = (id) => php(`echo NuHome::startMode('${id}');`).trim();
	try {
		sql(`DELETE FROM zzzzsys_access WHERE zzzzsys_access_id IN ('${L}', '${N}') OR sal_code = 'e2e_spf_new';
			INSERT INTO zzzzsys_access (zzzzsys_access_id, sal_code, sal_description, sal_zzzzsys_form_id, sal_use_2fa) VALUES ('${L}', '${L}', 'E2E Forte level', 'samplehome', '0'), ('${N}', '${N}', 'E2E level without home form', '', '0');
			DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'; DELETE FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0044-start-page-forte'`);
		php('NuMigrate::run();');
		expect(mode(L) === 'form' && mode(N) === 'tiles' && mode('sample_sales') === 'tiles', 'after the step: ' + [mode(L), mode(N), mode('sample_sales')].join());
		expect(php("echo NuHome::startForte() ? 'y' : 'n';").trim() === 'y', 'database not marked as from Forte');
		// only sample levels: nothing changes, no mark
		sql(`DELETE FROM zzzzsys_access WHERE zzzzsys_access_id IN ('${L}', '${N}'); DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'; DELETE FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0044-start-page-forte'`);
		php('NuMigrate::run();');
		expect(php("echo NuHome::startForte() ? 'y' : 'n';").trim() === 'n' && mode('sample_sales') === 'tiles', 'sample levels alone counted as Forte');
		// a new level on a database from Forte: choosing a Home form chooses "Home form"
		sql(`INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('start_page', '{"levels":{},"forte":true}')`);
		await open(p, 'index.php?p=accesslevel'); await p.waitForSelector('#accStartPage[data-forte-new="1"]', {state: 'attached'});
		expect(await p.isChecked('#accStartTiles'), 'a new level without Home form starts on tiles');
		await p.fill('#sal_code', 'e2e_spf_new');
		await p.evaluate(() => { const e = document.getElementById('sal_zzzzsys_form_id'); e.value = 'samplehome'; if (e.tomselect) e.tomselect.setValue('samplehome'); e.dispatchEvent(new Event('change', {bubbles: true})); });
		expect(await p.isChecked('#accStartForm'), 'choosing a Home form did not choose "Home form"');
		await p.click('button[data-nu-save]');
		await p.waitForTimeout(1500);
		const nid = sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE sal_code = 'e2e_spf_new'");
		expect(nid && nid !== 'NULL' && mode(nid) === 'form', 'the new level does not start on its Home form: ' + nid);
	} finally {
		sql(`DELETE FROM zzzzsys_access WHERE zzzzsys_access_id IN ('${L}', '${N}') OR sal_code = 'e2e_spf_new'; DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'`);
		if (keep !== '-') sql(`INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('start_page', FROM_BASE64('${Buffer.from(keep).toString('base64')}'))`);
	}
};

T.nuprompt_focus = async (s) => {
	// 0.8.1319: nuPrompt() puts the focus into its input – also when other code takes the focus right after, and for a
	// prompt opened from the callback of the one before
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
	await p.waitForTimeout(800);
	const ae = () => p.evaluate(() => document.activeElement && document.activeElement.id);
	await p.evaluate(() => { window.e2eP = []; nuPrompt('Enter comment:', 'Add Note', '', '', function (v, ok) { window.e2eP.push([v, ok]); }); });
	await p.waitForSelector('#nuPromptModal.show'); await p.waitForTimeout(400);
	expect(await ae() === 'prompt_value1', 'input not focused: ' + await ae());
	await p.evaluate(() => { const f = document.querySelector('#nuEditForm input:not([type=hidden])'); if (f) f.focus(); });
	await p.waitForTimeout(150);
	expect(await ae() === 'prompt_value1', 'focus not given back to the input: ' + await ae());
	await p.keyboard.type('first'); await p.keyboard.press('Enter');
	await p.waitForTimeout(100);
	await p.evaluate(() => nuPrompt('Second', 'B', 'x', '', function (v, ok) { window.e2eP.push([v, ok]); }));
	await p.waitForTimeout(1300);
	expect(await ae() === 'prompt_value1', 'second prompt not focused: ' + await ae());
	await p.keyboard.type('two'); await p.keyboard.press('Enter'); await p.waitForTimeout(700);
	const r = await p.evaluate(() => window.e2eP);
	expect(JSON.stringify(r) === '[["first",true],["two",true]]', 'answers: ' + JSON.stringify(r));
	s.result = r;
};

T.numessage_card = async (s) => {
	// 0.8.1320: nuMessage() = card with a tinted header (icon, title, close) and the lines below; no focus ring on ✕ when it opens
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
	await p.waitForTimeout(600);
	const look = () => p.evaluate(() => { const m = document.getElementById('nuMessageDiv'), h = m.querySelector('.nuMessageHead');
		return { head: !!h, title: h ? h.querySelector('.nuMessageTitle').textContent : '', closeInHead: !!(h && h.querySelector('.nuMessageClose')),
			icon: !!m.querySelector('.nuMessageIcon'), lines: [...m.querySelectorAll('.nuMessageBody .nuMessageLine')].map((x) => x.textContent),
			focus: document.activeElement.id, labelled: m.getAttribute('aria-labelledby') || '', bg: h ? getComputedStyle(h).backgroundColor : '' }; });
	await p.evaluate(() => nuMessage('Validation', ['Name is required.', 'Code must be unique.']));
	let r = await look();
	expect(r.head && r.title === 'Validation' && r.closeInHead && r.icon && r.lines.join('|') === 'Name is required.|Code must be unique.', 'card: ' + JSON.stringify(r));
	expect(r.focus === 'nuMessageDiv' && r.labelled !== '' && r.bg !== 'rgba(0, 0, 0, 0)', 'focus / label / tint: ' + JSON.stringify(r));
	await p.evaluate(() => nuMessage(['Record saved.', 'Second line']));
	r = await look();
	expect(!r.head && r.lines.length === 2 && await p.locator('#nuMessageDiv > .nuMessageClose').count() === 1, 'no title: ' + JSON.stringify(r));
	await p.evaluate(() => nuMessage(['<h2>Warning</h2>', 'Stock is low.']));
	r = await look();
	expect(r.head && r.title === 'Warning' && r.lines.join('|') === 'Stock is low.', 'h2 title: ' + JSON.stringify(r));
	await p.keyboard.press('Escape');
	expect(await p.locator('#nuMessageDiv').count() === 0, 'Esc did not close it');
	s.result = r;
};

T.runphp_page = async (s) => {
	// nuRunPHP opens the output as a page of its own (?p=runphp&i=<token>, as nuBuilder Forte's nurunphp.php?i=): a procedure can link
	// to itself with more parameters ($_SERVER['REQUEST_URI'] . '&x=1' → $_GET['x']) and a reload runs it again
	const p = s.ga;
	const b64 = (t) => Buffer.from(t).toString('base64');
	const code = "$x = $_GET['e2e_x'] ?? '';\nif ($x === '') { $url = $_SERVER['REQUEST_URI'] . '&e2e_x=2'; echo \"<a id='e2eNext' href='$url'>next</a>\"; return; }\n"
		+ "echo '<p id=\"e2eGot\">X=' . htmlspecialchars($x) . '|R=' . nuHash()['RECORD_ID'] . '</p>';";
	const ord = sql("SELECT sample_order_id FROM sample_order ORDER BY sample_order_id LIMIT 1");
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_runphp'; INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_group, sph_php, sph_global) VALUES ('e2e_runphp', 'e2e_runphp', 'e2e', 'e2e', FROM_BASE64('" + b64(code) + "'), '1')");
	try {
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord); await p.waitForSelector('#nuSaveButton');
		const [w] = await Promise.all([p.context().waitForEvent('page'), p.evaluate(() => { nuRunPHP('e2e_runphp'); })]);
		await w.waitForSelector('#e2eNext', { timeout: 15000 });
		expect(/[?&]p=runphp&i=[0-9a-f]{24}/.test(w.url()), 'own address: ' + w.url());
		const icon = () => w.evaluate(() => { const l = document.querySelectorAll('link[rel~="icon"]'); return l.length + '|' + (l[0] ? l[0].getAttribute('href') : ''); });
		const i1 = await icon();
		expect(/^1\|.*(favicon|brand)/.test(i1), 'the output page has nuBuilder Next\'s icon, not the server\'s /favicon.ico: ' + i1);
		await Promise.all([w.waitForNavigation(), w.click('#e2eNext')]);
		const got = await w.textContent('#e2eGot');
		expect(got === 'X=2|R=' + ord, 'link with a parameter runs the procedure again with the form\'s hash: ' + got + ' / ' + w.url());
		const i2 = await icon(); expect(i2.startsWith('1|'), 'a rerun has one icon too: ' + i2);
		await w.reload(); expect((await w.textContent('#e2eGot')) === got, 'reload runs it again');
		const bad = await w.goto(new URL('index.php?p=runphp&i=' + '0'.repeat(24), w.url()).href);
		expect(bad.status() === 410, 'unknown token: ' + bad.status());
		await w.close();
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_runphp'");
	}
};

T.forum_compat = async (s) => {
	// code from the nuBuilder Forte forum: nuSubformObject('') = the main form, Forte's popup ids, label / lookup / body classes,
	// nuDocumentID, the list pager's ids, database errors of PHP helpers and PHP warnings for the globeadmin
	const p = s.ga;
	const b64 = (t) => Buffer.from(t).toString('base64');
	const ord = sql("SELECT sample_order_id FROM sample_order ORDER BY sample_order_id LIMIT 1");
	await open(p, 'index.php?p=form&f=sampleorder&r=' + ord); await p.waitForSelector('#nuSaveButton');
	const e = await p.evaluate(() => {
		const o = nuSubformObject('');
		const lab = (id) => (document.getElementById('label_' + id) || {}).className || '';
		return { id: o.id, rows: o.rows.length, fields: o.fields.slice(0, 2), obj: o.object_id, body: document.body.className,
			doc: typeof window.nuDocumentID, hidden: document.querySelectorAll('input.nuHiddenLookup').length,
			lk: lab('ord_sample_customer_id'), date: lab('ord_date'), num: lab('ord_number') };
	});
	expect(e.id === 'nuBuilder4EditForm' && e.rows === 1 && e.fields[0] === 'ID' && e.obj === 'sampleorder', "nuSubformObject(''): " + JSON.stringify(e));
	expect(/\bnuEditBody\b/.test(e.body) && e.doc === 'number' && e.hidden >= 1, 'body class, nuDocumentID, nuHiddenLookup: ' + JSON.stringify(e));
	expect(/\bnuBlank\b/.test(e.lk) && /\bnuNone\b/.test(e.date) && /\bnuDuplicate\b/.test(e.num), 'label classes by validation: ' + JSON.stringify(e));
	// popup: Forte's ids while it is open, a width set from inside wins, gone after closing
	await p.evaluate(() => nuPopup('samplecustomer', '-1'));
	await p.waitForSelector('#nuPopupModal.show #nuWindow');
	await p.waitForSelector('#nuModal', { state: 'attached' });
	const pw = await p.evaluate(() => { $('#nuDragDialog').css('width', '520px'); return new Promise((r) => setTimeout(() => r({
		dlg: document.getElementById('nuDragDialog').classList.contains('modal-dialog'), w: Math.round(document.getElementById('nuDragDialog').getBoundingClientRect().width),
		fr: document.getElementById('nuWindow').tagName }), 150)); });
	expect(pw.dlg && pw.fr === 'IFRAME' && Math.abs(pw.w - 520) <= 2, 'popup ids + width from code: ' + JSON.stringify(pw));
	await p.evaluate(() => nuBs.modal(document.getElementById('nuPopupModal')).hide());
	await p.waitForFunction(() => !document.getElementById('nuDragDialog') && !document.getElementById('nuWindow') && !document.getElementById('nuModal'));
	// list pager ids (two pages of customers)
	await open(p, 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuBrowseTable');
	const pg = await p.evaluate(() => ({ body: document.body.className, ids: ['nuFirst', 'nuLast', 'nuEnd'].map((i) => !!document.getElementById(i)),
		next: typeof window.nuNext === 'object' && !(window.nuNext instanceof Element) }));
	expect(/\bnuBrowseBody\b/.test(pg.body) && pg.ids.every(Boolean) && pg.next, 'list: body class, #nuFirst / #nuLast / #nuEnd: ' + JSON.stringify(pg));
	// PHP: a database error in nuCreateTableFromSelect / db_fetch_value goes on (Debug result), a warning reaches the globeadmin as a notice
	const code = "$a = nuCreateTableFromSelect('e2e_fc_t', 'SELECT x FROM e2e_no_such_table');\n$b = db_fetch_value('e2e_no_such_table', 'id', '1', 'x');\n"
		+ "$c = $e2eUndefinedVar;\nnuSetProperty('E2E_FC', ($a === -1 ? 'm1' : 'x') . '|' . ($b === false ? 'false' : 'x'));";
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_fc'; INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_group, sph_php, sph_global) VALUES ('e2e_fc', 'e2e_fc', 'e2e', 'e2e', FROM_BASE64('" + b64(code) + "'), '1')");
	try {
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord); await p.waitForSelector('#nuSaveButton');
		const [resp] = await Promise.all([p.waitForResponse((x) => /p=formapi/.test(x.url()) && (x.request().postData() || '').includes('e2e_fc')),
			p.evaluate(() => nuRunPHPHidden('e2e_fc'))]);
		const j = await resp.json();
		expect(!(j.errors || []).length && (j.props || {}).E2E_FC === 'm1|false', 'database errors go on: ' + JSON.stringify({ e: j.errors, p: j.props }));
		expect((j.warnings || []).some((w) => /e2eUndefinedVar/.test(w)), 'warning sent to the globeadmin: ' + JSON.stringify(j.warnings));
		await p.waitForSelector('.toast.nuPhpWarn.show', { timeout: 5000 });
		const n = sql("SELECT COUNT(*) FROM zzzzsys_debug WHERE deb_message LIKE '%e2e_no_such_table%'");
		expect(+n >= 2, 'Debug results of both database errors: ' + n);
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_fc'; DELETE FROM zzzzsys_debug WHERE deb_message LIKE '%e2e_no_such_table%'");
	}
};

// Forte's tabs are siblings: nuSelectTab($('.nuTabSelected').next()[0]) selects the next tab (here each tab sits in its own <li>)
T.forte_tab_next = async (s) => {
	const p = s.ga;
	const id = sql("SELECT sample_customer_id FROM sample_customer LIMIT 1").trim();
	if (!id) { s.skip = 'no sample customer'; return; }
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + id);
	await p.waitForSelector('#nuTab0.active');
	const r = await p.evaluate(async () => {
		const wait = () => new Promise((res) => setTimeout(res, 300));
		const out = {};
		out.n0 = $('.nuTabSelected').next()[0] && $('.nuTabSelected').next()[0].id;
		nuSelectTab($('.nuTabSelected').next()[0]);
		await wait();
		out.a1 = $('.nuTabSelected')[0].id;
		nuSelectTab($('.nuTabSelected').prev()[0]);
		await wait();
		out.a0 = $('.nuTabSelected')[0].id;
		out.other = $('#nuTab0').parent().next().length === 1 && $('#nuTab0').parent().next()[0].tagName === 'LI';   // non-tab elements unchanged
		out.filtered = $('#nuTab0').next('.nope').length;
		return out;
	});
	expect(r.n0 === 'nuTab1' && r.a1 === 'nuTab1' && r.a0 === 'nuTab0' && r.other && r.filtered === 0, JSON.stringify(r));
};
