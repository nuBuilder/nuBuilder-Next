// e2e tests: data import / export, samples, translations, REST API – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// 0.8.691: the samples as a showcase – badges and filters in the sales lists, a customer link in the orders list, "What to look at"
T.sample_showcase = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'") !== '1') { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=form&f=sampleorder');
	expect(await p.locator('#nuBrowseFilters select').count() === 2, 'orders filters');
	const st = await p.locator('div.nuCell[data-nu-column="3"] .badge').first().textContent();
	expect(['New', 'Confirmed', 'Shipped', 'Paid'].includes(st.trim()), 'status badge: ' + st);
	await Promise.all([p.waitForURL(/f=samplecustomer&r=sample_cus_/), p.click('.sampleCustomerLink >> nth=0')]);
	await open(p, 'index.php?p=form&f=sampleproduct');
	expect(await p.locator('div.nuCell[data-nu-column="4"] .badge', { hasText: 'Active' }).count() > 0 && await p.locator('#nuBrowseFilters select').count() === 1, 'products');
	await open(p, 'index.php?p=form&f=samplecustomer');
	const red = await p.evaluate(() => [...document.querySelectorAll('div.nuCell[data-nu-column="4"] .badge')].map(b => b.style.backgroundColor));
	expect(red.length >= 2 && red.every(c => c), 'customer credit badges: ' + red.join(','));
	await open(p, 'index.php?p=form&f=sampleall');
	expect(await p.locator('div.nuCell[data-nu-column="3"] .badge', { hasText: /Option [ABC]/ }).count() > 0, 'all objects select badge');
	await open(p, 'index.php?p=form&f=samplehome&r=-1');
	expect(await p.locator('#home_look a').count() >= 4, 'What to look at');
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'tktticket'") === '1') {
		await open(p, 'index.php?p=form&f=tktticket');
		expect(await p.locator('#nuBrowseFilters select').count() === 5, 'ticket filters');
		await open(p, 'index.php?p=form&f=tktkb');
		expect(await p.locator('div.nuCell .badge', { hasText: /Public|Internal/ }).count() > 0, 'kb badges');
		await open(p, 'index.php?p=form&f=tkthome&r=-1');
		expect(await p.locator('#home_look a').count() === 3, 'ticket What to look at');
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.527 / 0.8.530: API client → service user: New service user (its fields) or Existing user (Tom Select: login name, full name ·
// access level as second line, the chosen user's access level below); Existing without a user is refused, with one it is saved
T.api_client_user_select = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=apiclients&id=new');
	expect(await p.locator('#suModeNew:checked').count() === 1 && await p.locator('.nuApiNewUser:not([hidden])').count() === 1
		&& await p.locator('.nuApiExistUser[hidden]').count() === 1, 'New service user at first, its fields shown');
	await p.click('label[for=suModeExisting]');
	expect(await p.locator('.nuApiNewUser[hidden]').count() === 1 && await p.locator('.nuApiExistUser:not([hidden])').count() === 1, 'Existing user: list shown');
	expect(await p.locator('#user_id.tomselected').count() === 1, 'service user is a Tom Select');
	await p.fill('#name', 'E2E client');
	await Promise.all([p.waitForNavigation(), p.click('button.nuBtnSave')]);
	expect(await p.locator('#user_id.is-invalid, .nuApiExistUser .invalid-feedback').count() > 0 && await p.locator('#suModeExisting:checked').count() === 1, 'existing without a user: refused, choice kept');
	await p.locator('#user_id + .ts-wrapper .ts-control').click();
	await p.keyboard.type('sample_anna');
	const opt = p.locator('.ts-dropdown .option', { hasText: 'sample_anna' }).first();
	await opt.waitFor();
	expect((await opt.locator('.small').count()) === 1, 'second line with name / access level');
	await opt.click(); await p.waitForTimeout(200);
	expect(/sample_viewer/.test(await p.textContent('#apiUserAccess')), 'access level of the chosen user: ' + await p.textContent('#apiUserAccess'));
	await Promise.all([p.waitForNavigation(), p.click('button.nuBtnSave')]);
	const anna = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_api_client WHERE name = 'E2E client' AND user_id = '" + anna + "'") === '1', 'saved with the existing user');
	sql("DELETE FROM zzzzsys_nunext_api_client WHERE name = 'E2E client'");
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.546: custom user fields as a system list – view menu, export, filters, (i) with the SQL / hash variable hint, move buttons
T.userfields_list = async (s) => {
	const p = s.ga;
	const T = 'zzzzsys_nunext_user_field';
	sql("INSERT INTO " + T + " (zzzzsys_nunext_user_field_id, code, label, type, edit_by, required, is_unique, in_list, sort) VALUES ('e2euf1','e2e_a','E2E Alpha','text','admin','1','0','0',10),('e2euf2','e2e_b','E2E Beta','date','user','0','0','0',20)");
	try {
		await open(p, 'index.php?p=userfields');
		expect(await p.locator('.nuLv[data-lv-list="userfields"]').count() === 1, 'list view');
		expect(await p.locator('.nuListHead .dropdown-toggle').count() >= 2, 'view menu and export');
		expect(/LEFT JOIN zzzzsys_nunext_user_ext/.test(await p.locator('.nuListHead h1 .nuInfoTip').getAttribute('aria-label')), '(i) next to the title');
		expect(await p.locator('#lvf_type').count() === 1 && await p.locator('#lvf_edit_by').count() === 1, 'filters type / edit by');
		const rows = await p.locator('table.nu-table tbody tr').allTextContents();
		expect(rows.findIndex(r => /E2E Alpha/.test(r)) < rows.findIndex(r => /E2E Beta/.test(r)), 'in their order');
		await Promise.all([p.waitForNavigation(), p.locator('tr:has-text("E2E Beta") button[title]').first().click()]);   // move up
		expect(sql("SELECT sort FROM " + T + " WHERE zzzzsys_nunext_user_field_id = 'e2euf2'") < sql("SELECT sort FROM " + T + " WHERE zzzzsys_nunext_user_field_id = 'e2euf1'"), 'moved up');
		await open(p, 'index.php?p=userfields&type=date');
		const t2 = await p.textContent('table.nu-table tbody');
		expect(/E2E Beta/.test(t2) && !/E2E Alpha/.test(t2), 'filter by type');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM " + T + " WHERE zzzzsys_nunext_user_field_id IN ('e2euf1','e2euf2')");
	}
};

// data export (0.8.758): editor (table dataset with conditions / sort, SQL dataset with a #parameter#), preview + count, save, run → .xlsx
// (two sheets, typed cells), CSV → ZIP with two files, re-import option, JSON download / upload, REST API, delete
T.data_export = async (s) => {
	const p = s.ga;
	const X = 'zzzzsys_nunext_export';
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nudxp-'));
	const go = async (q) => {
		await open(p, 'index.php?p=dataexport' + (q || ''));
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=dataexport' + (q || '')); }
	};
	const download = async (click) => { const [d] = await Promise.all([p.waitForEvent('download', { timeout: 20000 }), click()]); const f = path.join(tmp, d.suggestedFilename()); await d.saveAs(f); return f; };
	const py = (code) => execSync('python3 -I -', { input: code }).toString().trim();
	sql("DELETE FROM " + X + " WHERE code LIKE 'e2e_dxp%'");
	try {
		await go('&id=new');
		await p.waitForSelector('#dxpTable option[value=sample_order]', { state: 'attached' });
		await p.fill('#dxpName', 'E2E dxp');
		await p.fill('#dxpCode', 'e2e_dxp');
		await p.fill('#dxpDsName', 'Orders');
		await p.evaluate(() => { const t = document.querySelector('#dxpTable'); t.tomselect.setValue('sample_order'); });
		await p.waitForSelector('.dxpCols tbody tr');
		const nCols = await p.locator('.dxpCols tbody tr').count();
		await p.locator('.dxpCols tbody tr[data-col=ord_note] .dxpColOn').uncheck();
		await p.fill('.dxpCols tbody tr[data-col=ord_total] .dxpColHead', 'Total');
		await p.selectOption('.dxpCols tbody tr[data-col=ord_total] .dxpColFmt', '#,##0.00');
		await p.click('.dxpCols tbody tr[data-col=ord_number] .dxpColUp');      // ord_number before sample_order_id
		await p.click('#dxpCondAdd'); await p.selectOption('.dxpCondCol', 'ord_total'); await p.selectOption('.dxpCondOp', 'gt'); await p.fill('.dxpCondV1', '100');
		await p.click('#dxpSortAdd'); await p.selectOption('.dxpSortCol', 'ord_total'); await p.selectOption('.dxpSortDir', 'desc');
		await p.click('#dxpCountBtn'); await p.waitForFunction(() => /\d/.test(document.querySelector('#dxpMsg').textContent));
		const count = parseInt(await p.textContent('#dxpMsg'), 10);
		const expected = parseInt(sql("SELECT COUNT(*) FROM sample_order WHERE ord_total > 100"), 10);
		expect(count === expected && count > 0, 'count ' + count + ' <> ' + expected);
		await p.click('#dxpPreviewBtn'); await p.waitForSelector('#dxpPreviewDlg.show');
		const heads = await p.$$eval('.dxpPreview thead th', th => th.map(x => x.textContent));
		expect(heads[0] === 'ord_number' && heads.indexOf('Total') > 0 && heads.indexOf('ord_note') < 0 && await p.locator('.dxpPreview tbody tr').count() === Math.min(20, count), 'preview columns / rows: ' + heads.join());
		await p.click('#dxpPreviewDlg .btn-close'); await p.waitForSelector('#dxpPreviewDlg', { state: 'hidden' }); await p.waitForTimeout(300);
		// second dataset: typed SQL with a parameter
		await p.click('#dxpDsAdd'); await p.selectOption('#dxpDsSource', 'sql'); await p.waitForSelector('.dxpCM');
		await p.evaluate(() => { document.querySelector('.dxpCM').CodeMirror.setValue("SELECT cus_name, cus_country, cus_credit, cus_since FROM sample_customer WHERE cus_country = '#country#' ORDER BY cus_name"); });
		await p.fill('#dxpDsName', 'Customers');
		await p.click('#dxpCheck'); await p.waitForFunction(() => /#country#/.test(document.querySelector('#dxpCheckMsg').textContent));
		expect(await p.locator('#dxpDsTabs .nav-link').count() === 2, 'two dataset tabs');
		await Promise.all([p.waitForNavigation(), p.click('#dxpSave')]);
		const id = sql("SELECT " + X + "_id FROM " + X + " WHERE code = 'e2e_dxp'");
		expect(id !== '' && p.url().includes('id=' + id) && await p.locator('#dxpParamsNote').count() === 1, 'saved, parameter note');
		const stored = JSON.parse(sql("SELECT datasets FROM " + X + " WHERE code = 'e2e_dxp'"));
		expect(stored.length === 2 && stored[0].columns.length === nCols - 1 && stored[0].columns[0].col === 'ord_number' && stored[0].where.c[0][1] === 'gt' && stored[0].sort[0][1] === 'desc' && stored[1].source === 'sql', 'stored model: ' + JSON.stringify(stored).slice(0, 200));
		// run: asks for #country#, then the xlsx
		await go('&run=' + id);
		await p.fill('#prm_country', sql("SELECT cus_country FROM sample_customer ORDER BY cus_country LIMIT 1"));
		let f = await download(() => p.click('#dxpRunGo'));
		let x = JSON.parse(py("import openpyxl, json\nwb = openpyxl.load_workbook(" + JSON.stringify(f) + ")\nws = wb.worksheets[0]\nr2 = [c for c in ws[2]]\n"
			+ "print(json.dumps({'sheets': wb.sheetnames, 'rows': [w.max_row for w in wb.worksheets], 'h': [c.value for c in ws[1]], 'freeze': str(ws.freeze_panes), 'filter': ws.auto_filter.ref,"
			+ " 'date': type(ws['D2'].value).__name__ if ws['D2'].value is not None else '', 'types': [type(c.value).__name__ for c in r2], 'fmt': [c.number_format for c in r2]}))"));
		expect(/\.xlsx$/.test(f) && JSON.stringify(x.sheets) === '["Orders","Customers"]' && x.rows[0] === count + 1 && x.h[0] === 'ord_number' && x.h.indexOf('Total') > 0 && x.freeze === 'A2' && x.filter.startsWith('A1:'), 'xlsx: ' + JSON.stringify(x));
		const iTotal = x.h.indexOf('Total'), iDate = x.h.indexOf('ord_date');
		expect(['int', 'float'].includes(x.types[iTotal]) && x.fmt[iTotal] === '#,##0.00' && x.types[iDate] === 'datetime' && x.fmt[iDate] === 'yyyy-mm-dd', 'typed cells: ' + JSON.stringify([x.types, x.fmt]));
		// CSV → ZIP with two files; re-import option: key column first, names as headings
		sql("UPDATE " + X + " SET format = 'csv', datasets = JSON_SET(datasets, '$[0].reimport', true) WHERE code = 'e2e_dxp'");
		await go('&run=' + id);
		await p.fill('#prm_country', 'XX');
		f = await download(() => p.click('#dxpRunGo'));
		const z = JSON.parse(py("import zipfile, json\nz = zipfile.ZipFile(" + JSON.stringify(f) + ")\nn = z.namelist()\nprint(json.dumps({'names': n, 'first': z.read('Orders.csv').decode('utf-8-sig').split('\\r\\n')[:2], 'cust': z.read('Customers.csv').decode('utf-8-sig').split('\\r\\n')[:2]}))"));
		expect(/\.zip$/.test(f) && z.names.length === 2 && z.first[0].startsWith('sample_order_id;ord_number;') && !/Total/.test(z.first[0]) && z.cust[0] === 'cus_name;cus_country;cus_credit;cus_since' && z.cust[1] === '', 'zip: ' + JSON.stringify(z));
		sql("UPDATE " + X + " SET format = 'xlsx' WHERE code = 'e2e_dxp'");
		// JSON download + upload = a copy with a new code
		await go('&id=' + id);
		await p.click('#dxpMore');
		f = await download(() => p.click('#dxpJson'));
		const j = JSON.parse(fs.readFileSync(f, 'utf8'));
		expect(j.format === 'nunext-export-1' && j.export.code === 'e2e_dxp' && j.export.datasets.length === 2, 'json file');
		await go('');
		expect(await p.locator('.nuLv[data-lv-list="exports"]').count() === 1 && await p.locator('tr:has-text("E2E dxp") .dxRun').count() === 1, 'list with Run');
		await Promise.all([p.waitForNavigation({ url: /p=dataexport&id=/ }), p.setInputFiles('#dxpImportFile', f)]);
		await p.waitForLoadState('networkidle');
		const copy = sql("SELECT code FROM " + X + " WHERE code = 'e2e_dxp_2'");
		expect(copy === 'e2e_dxp_2' && p.url().includes('p=dataexport&id='), 'uploaded as e2e_dxp_2: ' + p.url());
		// REST API: list + run (service user with the admin right through a role)
		const anna = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
		sql("DELETE FROM zzzzsys_nunext_admin_user WHERE zzzzsys_nunext_admin_user_id = '" + anna + "'");
		sql("INSERT INTO zzzzsys_nunext_admin_role (zzzzsys_nunext_admin_role_id, code, name, rights, sort_order) VALUES ('e2e_dxprole', 'e2edxp', 'E2E export role', '{\"dataexport\":\"view\"}', 99)");
		sql("INSERT INTO zzzzsys_nunext_admin_user (zzzzsys_nunext_admin_user_id, role_id) VALUES ('" + anna + "', 'e2e_dxprole')");
		sql("INSERT INTO zzzzsys_nunext_api_client (zzzzsys_nunext_api_client_id, name, client_id, scopes, user_id, active, rate_limit, created_at) VALUES ('e2edxpapi','E2E dxp','nk_e2edxp','[\"exports:run\"]','" + anna + "','1',120,NOW())");
		await go('');
		const tok = await p.evaluate(async (url) => {
			const csrf = document.querySelector('[data-csrf]').getAttribute('data-csrf');
			const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ action: 'token', client_ref: 'e2edxpapi', scope: 'exports:run' }) });
			const j = await r.json();
			return j.token || { access_token: '' };
		}, BASE + 'index.php?p=apiplayground');
		const api = BASE.replace(/index\.php$/, '') + 'api.php/v1/exports';
		const list = await (await fetch(api, { headers: { Authorization: 'Bearer ' + tok.access_token } })).json();
		if (list.status === 404 && /switched off/.test(list.title || '')) { console.log('      (REST part skipped: $nuConfigApi is off on the test site)'); } else {
		const mine = (list.data || []).find(e => e.code === 'e2e_dxp');
		expect(mine && JSON.stringify(mine.params) === '["country"]' && mine.datasets.length === 2, 'API list: ' + JSON.stringify(list).slice(0, 200));
		const run = await fetch(api + '/e2e_dxp/run', { method: 'POST', headers: { Authorization: 'Bearer ' + tok.access_token, 'Content-Type': 'application/json' }, body: JSON.stringify({ params: { country: 'XX' } }) });
		const buf = Buffer.from(await run.arrayBuffer());
		expect(run.status === 200 && /spreadsheetml/.test(run.headers.get('content-type')) && /e2e_dxp_.*\.xlsx/.test(run.headers.get('content-disposition')) && parseInt(run.headers.get('x-export-rows'), 10) === count && buf.length > 1000 && buf.slice(0, 2).toString() === 'PK', 'API run: ' + run.status + ' ' + run.headers.get('content-disposition'));
		const miss = await fetch(api + '/e2e_dxp/run', { method: 'POST', headers: { Authorization: 'Bearer ' + tok.access_token, 'Content-Type': 'application/json' }, body: '{}' });
		expect(miss.status === 422, 'missing parameter → 422, got ' + miss.status);
		expect(parseInt(sql("SELECT runs FROM " + X + " WHERE code = 'e2e_dxp'"), 10) === 3 && parseInt(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'export_run' AND record_id = '" + id + "'"), 10) === 3, 'runs counted and logged');
		}
		// delete
		await go('&id=' + id);
		await p.click('#dxpMore'); await p.click('#dxpDel');
		await p.waitForSelector('.modal.show .btn-danger'); await Promise.all([p.waitForNavigation(), p.click('.modal.show .btn-danger')]);
		expect(sql("SELECT COUNT(*) FROM " + X + " WHERE code = 'e2e_dxp'") === '0', 'deleted');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM " + X + " WHERE code LIKE 'e2e_dxp%'");
		sql("DELETE FROM zzzzsys_nunext_api_client WHERE zzzzsys_nunext_api_client_id = 'e2edxpapi'");
		sql("DELETE FROM zzzzsys_nunext_admin_user WHERE role_id = 'e2e_dxprole'");
		sql("DELETE FROM zzzzsys_nunext_admin_role WHERE zzzzsys_nunext_admin_role_id = 'e2e_dxprole'");
		fs.rmSync(tmp, { recursive: true, force: true });
	}
};

// 0.8.547: API clients as a system list – scopes behind a small button (menu with the list), filters status / scope, playground in ⋮ on phones
T.apiclients_list = async (s) => {
	const p = s.ga;
	const C = 'zzzzsys_nunext_api_client';
	sql(`INSERT INTO ${C} (zzzzsys_nunext_api_client_id, name, client_id, scopes, user_id, active, rate_limit, created_at) SELECT 'e2eapi1','E2E Client','nk_e2e1','["users:read","reports:run"]', zzzzsys_user_id, '1', 120, NOW() FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'`);
	sql(`INSERT INTO ${C} (zzzzsys_nunext_api_client_id, name, client_id, scopes, user_id, active, created_at) VALUES ('e2eapi2','E2E Off','nk_e2e2','["access:read"]','', '0', NOW())`);
	const go = async (q) => {
		await open(p, 'index.php?p=apiclients' + (q || ''));
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=apiclients' + (q || '')); }
	};
	try {
		await go('');
		expect(await p.locator('.nuLv[data-lv-list="apiclients"]').count() === 1 && await p.locator('#apiPlayground').count() === 1, 'list view with playground button');
		const row = p.locator('tr:has-text("E2E Client")');
		await row.locator('.nuApiScopes button').click(); await p.waitForTimeout(300);
		const items = await row.locator('.nuApiScopes .dropdown-menu.show li').allTextContents();
		expect(items.length === 2 && /users:read/.test(items.join()), 'scopes in the menu: ' + items.join(' | '));
		await go('&scope=access:read');
		const t1 = await p.textContent('table.nu-table tbody');
		expect(/E2E Off/.test(t1) && !/E2E Client/.test(t1), 'filter by scope');
		await go('&status=on');
		const t2 = await p.textContent('table.nu-table tbody');
		expect(/E2E Client/.test(t2) && !/E2E Off/.test(t2), 'filter by status');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM " + C + " WHERE zzzzsys_nunext_api_client_id IN ('e2eapi1','e2eapi2')");
	}
};

T.sample_pivots = async (s) => {
	// shared pivots of the sales sample (0.8.1155): listed in My pivots for everyone, without delete, load a layout
	const p = s.ga;
	if (!SITE) { s.skip = 'SITE not set'; return; }
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'") !== '1') { s.skip = 'no sample data'; return; }
	const php = (code) => require('child_process').execFileSync('php', ['-r', 'chdir(' + JSON.stringify(SITE) + "); require 'core/nuapp.php'; " + code]).toString();
	execSync('mysql ' + DB, {input: "DELETE FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id LIKE 'sample_pv_%'"});
	php("foreach ((new ReflectionMethod('NuSample', 'pivots'))->invoke(null) as [$t, $r]) nu_db()->insert($t, $r);");
	try {
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'pivot:sampleorder' AND scope = 'shared'") === '3', 'three shared pivots');
		await open(p, 'index.php?p=form&f=sampleorder');
		await p.click('#nuExportButton'); await p.click('#nuPivotOpen'); await p.waitForSelector('#nuPvDlg.show');
		await p.click('#nuPvSavedBtn'); await p.waitForSelector('.nuPvLoad');
		const rows = await p.evaluate(() => [...document.querySelectorAll('#nuPvSavedMenu .nuPvLoad')].map((b) => [b.textContent, !!b.parentElement.querySelector('.bi-trash'), !!b.parentElement.querySelector('.bi-people')]));
		const top = rows.find((r) => r[0] === 'Top 5 customers by revenue');
		expect(top && !top[1] && top[2], 'shared entry without delete: ' + JSON.stringify(rows));
		await p.click('#nuPvSavedMenu .nuPvLoad:text-is("Top 5 customers by revenue")'); await p.waitForSelector('#nuPvTable'); await p.waitForTimeout(500);
		expect(await p.locator('#nuPvTable tbody tr').count() <= 5, 'top 5 rows');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, {input: "DELETE FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id LIKE 'sample_pv_%'"});
	}
};

// 0.8.1166: the ticket sample brings calendars – the tickets list (Due), the category form (calendar object) and the support dashboard
T.sample_ticket_calendar = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'tktticket'") !== '1') { s.skip = 'no ticket sample'; return; }
	await open(p, 'index.php?p=form&f=tktticket');
	await p.click('#nuCalendarOpen'); await p.waitForSelector('#nuCalDlg.show .fc');
	const due = sql("SELECT DATE_FORMAT(MAX(tic_due), '%Y-%m-%d') FROM tkt_ticket");
	await p.evaluate((d) => document.getElementById('nuCalListHost')._nuCal.gotoDate(d), due);
	await p.waitForSelector('#nuCalDlg .fc-event', {timeout: 8000});
	expect(await p.locator('#nuCalDlg .nuCalChip').count() > 2, 'status chips');
	const cat = sql('SELECT tic_tkt_category_id FROM tkt_ticket GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1');
	await open(p, 'index.php?p=form&f=tktcategory&r=' + cat);
	await p.waitForSelector('.nuCalObj .fc', {timeout: 8000});
	const n = await p.evaluate((d) => new Promise((ok) => { const c = document.querySelector('.nuCalObj .nuCalHost')._nuCal; c.gotoDate(d); setTimeout(() => ok(c.getEvents().length), 1200); }), due);
	expect(n >= 0, 'calendar object');
	await open(p, 'index.php?p=form&f=tkthome&r=-1');
	await p.waitForSelector('.nuDashCal .fc', {timeout: 10000});
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.translations_form = async (s) => {                     // 0.8.754: missing translations of one form in every language, AI round trip, design mode, select options
	const p = s.ga;
	const clean = () => sql("DELETE FROM zzzzsys_translate WHERE trl_translation LIKE 'E2E %'; DELETE FROM zzzzsys_nunext_log WHERE event = 'translations_ai'");
	clean();
	try {
		const U = 'index.php?p=translations&view=missing&f=samplecustomer';
		await open(p, U);
		const rows = await p.$$eval('#trlMatrix tbody tr', (r) => r.map((x) => [x.getAttribute('data-text'), x.innerText]));
		const au = rows.find((r) => r[0] === 'Australia');
		expect(au && await p.locator('#trlMatrix th[data-lang="German"]').count() === 1, 'matrix without the select option text / German: ' + JSON.stringify(rows.slice(0, 3)));
		// the prompt (JSON route, nothing stored)
		const pr = await p.evaluate(async () => {
			const f = document.getElementById('trlAiPromptForm');
			const fd = new FormData(f);
			fd.delete('ai_lang[]'); fd.append('ai_lang[]', 'German');
			return (await fetch(f.getAttribute('data-url'), {method: 'POST', body: fd, credentials: 'same-origin'})).json();
		});
		expect(pr.ok && /nunext-translate-1/.test(pr.prompt) && /"en": "Australia"/.test(pr.prompt) && /into German\./.test(pr.prompt), 'prompt: ' + JSON.stringify(pr).slice(0, 300));
		// paste an answer: two good rows, one unknown text, one unknown language
		const ans = 'Sure:\n```json\n' + JSON.stringify({format: 'nunext-translate-1', translations: {German: {Australia: 'E2E Australien', Customer: 'E2E Kunde', Nonsense: 'E2E x'}, Klingon: {Customer: 'E2E Q'}}}) + '\n```';
		await p.fill('#trlAiAnswer', ans);
		await Promise.all([p.waitForNavigation(), p.click('#trlAiCheck')]);
		const st = await p.$$eval('#trlAiPreview tbody tr', (r) => r.map((x) => x.getAttribute('data-status')).join(','));
		expect(st === 'new,new,skip,skip', 'preview statuses: ' + st);
		expect(await p.locator('.trlAiLang:checked').count() > 0, 'languages not ticked after the check');
		await Promise.all([p.waitForNavigation(), p.click('#trlAiSave')]);
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_translate WHERE trl_language = 'German' AND trl_translation IN ('E2E Australien', 'E2E Kunde')") === 2, 'translations not saved');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'translations_ai'") === 1, 'not logged');
		const cell = await p.locator('#trlMatrix tr[data-text="Australia"] td[data-lang="German"]').innerText();
		expect(/E2E Australien/.test(cell), 'matrix cell after saving: ' + cell);
		// a broken answer
		await p.fill('#trlAiAnswer', 'no json here');
		await Promise.all([p.waitForNavigation(), p.click('#trlAiCheck')]);
		expect(await p.locator('#trlAiError').count() === 1, 'no error for an answer without JSON');
		// design mode: Tools → Translations with the missing count
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1&design=1');
		const dt = await p.evaluate(() => window.nuNext && window.nuNext.design && window.nuNext.design.translations);
		expect(dt && /f=samplecustomer/.test(dt.url) && dt.missing && dt.missing.German > 0, 'design client translations: ' + JSON.stringify(dt));
		// the reader's language: a fixed select option is translated, the value stays
		const u = await login('sample_ben', UPW);
		await open(u, 'index.php?p=form&f=samplecustomer&r=-1');
		const opt = await u.evaluate(() => { const o = [...document.querySelectorAll('#cus_country option')].find((x) => /Austral/.test(x.textContent)); return o ? [o.value, o.textContent] : null; });
		expect(opt && opt[1] === 'E2E Australien' && opt[0] !== 'E2E Australien', 'select option not translated: ' + JSON.stringify(opt));
		await u.context().close();
	} finally { clean(); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// sample data 16 (0.8.506): tab Upload of sample_all = Uppy object (file system), full width also when opened on a hidden tab
T.sample_upload = async (s) => {
	// the test database may hold an older sample version: build the forms of this version (the database is put back after the run)
	if (sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_id = 'all_upload' AND sob_all_zzzzsys_form_id = 'sampleall'") === '0') {
		if (!SITE) { s.skip = 'sample data older than 16 (SITE needed to refresh it)'; return; }
		execSync(`php -r 'chdir(${JSON.stringify(SITE)}); require "core/nuapp.php"; NuSample::install();'`, { stdio: 'pipe' });
		const hash = execSync('php -r ' + JSON.stringify('echo password_hash(' + JSON.stringify(UPW) + ', PASSWORD_BCRYPT);')).toString().trim();
		sql("UPDATE zzzzsys_user SET sus_login_password = '" + hash + "', sus_change_password = '0' WHERE sus_login_name LIKE 'sample\\_%'");
		// the globeadmin's own language (0.8.1205, setting lang_ga): the tests expect the browser's English
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'lang_ga'");
	}
	expect(sql("SELECT sob_input_file_target FROM zzzzsys_object WHERE sob_all_id = 'all_upload' AND sob_all_zzzzsys_form_id = 'sampleall'") === '1', 'sample Uppy object missing');
	expect(+sql("SELECT COUNT(*) FROM zzzzsys_php WHERE sph_code = 'nu_upload_file_template'") === 1, 'upload procedure missing');
	expect(+sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sample_all' AND COLUMN_NAME = 'all_upload'") === 0, 'column for the Uppy object');
	if (!SITE || !fs.existsSync(path.join(SITE, 'vendor/packs')) || !fs.readdirSync(path.join(SITE, 'vendor/packs')).some(d => /^uppy-/.test(d))) { s.skip = 'Uppy library pack not installed on the test site'; return; }
	const p = s.ga;
	await open(p, 'index.php?p=form&f=sampleall&r=sample_all_1');
	await p.locator('.nav-tabs .nav-link', { hasText: 'Upload' }).first().click();
	await p.waitForSelector('#all_upload_uppy_div .uppy-Dashboard', { timeout: 10000 });
	const w = await p.evaluate(() => document.querySelector('#all_upload_uppy_div .uppy-Dashboard-inner').getBoundingClientRect().width);
	expect(w > 600, 'Uppy dashboard width ' + w);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.csv_import = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=import');
	const res = await p.evaluate(async () => {
		const csrf = document.querySelector('input[name=_csrf]').value;
		const post = async (o, file) => { const fd = new FormData(); fd.append('_csrf', csrf); for (const k in o) fd.append(k, o[k]); if (file) fd.append('file', file); return (await fetch('index.php?p=import', { method: 'POST', body: fd })).json(); };
		const up = await post({ action: 'upload', header: '1' }, new File(['code;name\nE2E7;Imported one\nE2E8;Imported two\n'], 'e2e.csv', { type: 'text/csv' }));
		if (!up.ok) return up;
		return post({ action: 'import', table: 'sample_customer', mode: 'insert', delimiter: ';', header: '1',
			mapping: JSON.stringify({ sample_customer_id: { src: 'nuid' }, cus_code: { src: 'csv', idx: '0' }, cus_name: { src: 'csv', idx: '1' } }) });
	});
	expect(res.ok && res.inserted === 2, 'import: ' + JSON.stringify(res).slice(0, 200));
	expect(sql("SELECT COUNT(*) FROM sample_customer WHERE cus_code IN ('E2E7','E2E8')") === '2', 'imported rows missing');
};

// PhpSpreadsheet pack for a test (0.8.756): built from ../libsrc/phpspreadsheet with the owner's key and uploaded on the update page;
// returns a function that removes it again (packs of the slot that were there before come back). null = cannot be built here.
// 0.8.1094: spreadsheets are checked against PHP's memory_limit before they are loaded (no fixed 10 MB any more) – a second PHP server
// with memory_limit 96M gets a small .xlsx whose sheet unpacks to ~8 MB: refused with the CSV hint instead of a fatal memory error
T.spreadsheet_memory = async (s) => {
	if (!SITE || !fs.existsSync(path.resolve(__dirname, '../../../libsrc/phpspreadsheet/autoload.php'))) return 'skip';
	const undo = await pssPack(s.ga);
	if (!undo) return 'skip';
	const port = 8093, docroot = path.resolve(SITE, '..'), sub = path.basename(SITE);
	const srv = require('child_process').spawn('php', ['-d', 'memory_limit=96M', '-S', '127.0.0.1:' + port, '-t', docroot], { stdio: 'ignore' });
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nupssm-'));
	let ctx;
	try {
		const big = path.join(tmp, 'big.xlsx');
		execSync('php', { input: `<?php $z = new ZipArchive(); $z->open(${JSON.stringify(big)}, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
$z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
$z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
$z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
$rows = ''; for ($r = 1; $r <= 60000; $r++) $rows .= '<row r="' . $r . '"><c r="A' . $r . '"><v>' . $r . '</v></c><c r="B' . $r . '"><v>' . ($r * 2) . '</v></c><c r="C' . $r . '"><v>3</v></c></row>';
$z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
$z->close();` });
		expect(fs.statSync(big).size < 2 * 1048576, 'the test file is small: ' + fs.statSync(big).size);
		const base = 'http://127.0.0.1:' + port + '/' + sub + '/';
		for (let i = 0; i < 30; i++) { try { execSync('curl -s -o /dev/null ' + base); break; } catch (e) { await new Promise((r) => setTimeout(r, 200)); } }
		ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 1300, height: 900 } });
		const p = await ctx.newPage();
		await p.goto(base + 'index.php?p=login');
		await p.fill('#username', GA[0]); await p.fill('#password', GA[1]);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
		await p.goto(base + 'index.php?p=import');
		expect(/memory/.test(await p.locator('#impFile').evaluate((e) => e.closest('label, .card, form').textContent)), 'drop hint mentions the memory');
		await p.setInputFiles('#impFile', big);
		await p.waitForFunction(() => /memory_limit/.test(document.body.textContent), null, { timeout: 15000 });
		const txt = await p.evaluate(() => document.body.textContent);
		expect(/96 MB \(memory_limit\)/.test(txt) && /CSV/.test(txt), 'refused with the memory and the CSV hint');
	} finally {
		if (ctx) await ctx.close();
		srv.kill();
		undo();
		fs.rmSync(tmp, { recursive: true, force: true });
	}
};

// sample application (0.8.756): Excel export – the built-in writer and PhpSpreadsheet (formulas, conditional format, chart), started from
// the start form's tiles (nuRunPHP: the answer is the file → saved, no window) and from the procedure page's test run
T.sample_excel = async (s) => {
	const p = s.ga;
	if (!sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE zzzzsys_php_id = 'sample_proc_xlsx'")) { s.skip = 'sample data without the Excel procedures'; return; }
	const home = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'sample_home'");
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nuxl-'));
	const look = (f) => JSON.parse(execSync('python3 -I -', { input: "import openpyxl, json, zipfile\nwb = openpyxl.load_workbook(" + JSON.stringify(f) + ")\nz = zipfile.ZipFile(" + JSON.stringify(f) + ")\n"
		+ "print(json.dumps({'sheets': wb.sheetnames, 'a1': [c.value for c in wb.worksheets[0][1]], 'last': [str(c.value) for c in wb.worksheets[0][wb.worksheets[0].max_row]],"
		+ " 'b2': str(wb.worksheets[1]['B2'].value) if len(wb.worksheets) > 1 else '', 'charts': len([n for n in z.namelist() if n.startswith('xl/charts/chart')])}))" }).toString());
	const getFile = async (click) => {
		const [d] = await Promise.all([p.waitForEvent('download', { timeout: 20000 }), click()]);
		const f = path.join(tmp, d.suggestedFilename());
		await d.saveAs(f);
		return f;
	};
	let undo = null;
	try {
		await open(p, 'index.php?p=form&f=' + home + '&r=-1');
		let f = await getFile(() => p.click('#home_xlsx'));
		let x = look(f);
		expect(/^sample-orders-\d{4}-\d\d-\d\d\.xlsx$/.test(path.basename(f)) && JSON.stringify(x.sheets) === '["Orders"]' && x.a1[0] === 'Number' && /^=SUM\(F2:F\d+\)$/.test(x.last[5]), 'built-in export: ' + JSON.stringify(x));
		// PhpSpreadsheet missing: the procedure says so in its window
		const [pop] = await Promise.all([p.waitForEvent('popup'), p.click('#home_xlsx_pss')]);
		await pop.waitForFunction(() => /PhpSpreadsheet is not installed/.test(document.body ? document.body.innerText : ''), null, { timeout: 15000 });
		await pop.close();
		undo = await pssPack(p);
		if (!undo) { s.skip = 'PhpSpreadsheet pack cannot be built here (key or ../libsrc/phpspreadsheet missing) – built-in export checked'; return; }
		await open(p, 'index.php?p=form&f=' + home + '&r=-1');
		const pages = p.context().pages().length;
		f = await getFile(() => p.click('#home_xlsx_pss'));
		x = look(f);
		expect(JSON.stringify(x.sheets) === '["Orders","By country"]' && /^=SUM\(F2:F\d+\)$/.test(x.last[5]) && /^=COUNTIF\(/.test(x.b2) && x.charts === 1, 'PhpSpreadsheet export: ' + JSON.stringify(x));
		await p.waitForTimeout(500);
		expect(p.context().pages().length === pages, 'a window stayed open after the download');
		// the procedure page's test run saves the file too
		await open(p, 'index.php?p=procedures&id=sample_proc_xlsx');
		f = await getFile(() => p.click('#procRunWindow'));
		expect(fs.statSync(f).size > 1000 && await p.locator('#procResultFile').count() === 1, 'procedure page: no file / result line');
	} finally {
		if (undo) undo();
		fs.rmSync(tmp, { recursive: true, force: true });
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// PhpSpreadsheet pack (0.8.755): without it the import says how to get it; built from ../libsrc/phpspreadsheet (dev/pss_assemble.py), uploaded,
// self-tested; an .xlsx with two sheets (a date, a formula) is read by the CSV import – the sheet select switches the sheet. Needs SITE, the key, the source.
T.spreadsheet_import = async (s) => {
	const key = process.env.NUNEXT_SIGNING_KEY && fs.existsSync(process.env.NUNEXT_SIGNING_KEY) ? process.env.NUNEXT_SIGNING_KEY : path.resolve(__dirname, '../../../nunext-signing.key');
	const src = path.resolve(__dirname, '../../../libsrc/phpspreadsheet');
	if (!SITE || !fs.existsSync(key) || !fs.existsSync(path.join(src, 'autoload.php'))) { s.skip = 'SITE, signing key or ../libsrc/phpspreadsheet missing'; return; }
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nupss-'));
	const tp = path.join(SITE, 'vendor/packs');
	const stateFile = path.join(tp, 'nunext-libs.php');
	const stateBefore = fs.existsSync(stateFile) ? fs.readFileSync(stateFile) : null;
	const had = fs.existsSync(tp) ? fs.readdirSync(tp).filter((d) => /^phpspreadsheet-/.test(d)) : [];
	const keep = path.join(tmp, 'keep');
	fs.mkdirSync(keep);
	for (const d of had) fs.renameSync(path.join(tp, d), path.join(keep, d));
	if (stateBefore) fs.writeFileSync(stateFile, stateBefore.toString().replace(/"phpspreadsheet"\s*:\s*\{[^}]*\},?/, '').replace(/,(\s*})\s*$/, '$1'));
	const p = s.ga;
	try {
		await open(p, 'index.php?p=import');
		expect(await p.locator('#impXlsxHint').count() === 1 && !/\.xlsx/.test(await p.getAttribute('#impFile', 'accept')), 'no hint without the pack');
		execSync(`php dev/libpack.php ${JSON.stringify(tmp)} ${JSON.stringify(key)} phpspreadsheet=${JSON.stringify(src)}`, { cwd: path.resolve(__dirname, '../..') });
		const pack = fs.readdirSync(tmp).find((f) => /^nunext-lib-phpspreadsheet-.*\.zip$/.test(f));
		await open(p, 'index.php?p=update&lp=' + Date.now() + '#libraries');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update&lp=' + Date.now() + '#libraries'); }
		await p.setInputFiles('#lpUpload', path.join(tmp, pack));
		await Promise.all([p.waitForNavigation(), p.click('#lpUploadBtn')]);
		expect(/5\.\d+\.\d+/.test(await p.locator('tr[data-slot=phpspreadsheet] .nuLpInUse').innerText()), 'pack not in use: ' + await p.locator('tr[data-slot=phpspreadsheet]').innerText());
		expect(fs.existsSync(path.join(tp, fs.readdirSync(tp).find((d) => /^phpspreadsheet-/.test(d)) || 'x', '.htaccess')), 'server-only pack without deny-all');
		await p.click('tr[data-slot=phpspreadsheet] [data-bs-toggle=dropdown]');
		await Promise.all([p.waitForNavigation(), p.click('tr[data-slot=phpspreadsheet] button[form="lpF-lp_test-phpspreadsheet"]')]);
		expect(await p.locator('tr[data-slot=phpspreadsheet] .nuLpTest-ok').count() === 1, 'self-test: ' + await p.locator('tr[data-slot=phpspreadsheet]').innerText());
		// an .xlsx with two sheets, written with the pack's own classes
		const x = path.join(tmp, 'e2e.xlsx');
		execSync('php', { input: `<?php require ${JSON.stringify(src + '/autoload.php')};
$s = new \\PhpOffice\\PhpSpreadsheet\\Spreadsheet(); $w = $s->getActiveSheet(); $w->setTitle('Customers');
$w->fromArray([['Name', 'Since', 'Credit', 'Double'], ['Anna', null, 1200.5, '=C2*2'], ['Ben', null, 3, '=C3*2']]);
$w->setCellValue('B2', \\PhpOffice\\PhpSpreadsheet\\Shared\\Date::PHPToExcel(new DateTime('2024-03-05'))); $w->getStyle('B2')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
$s->createSheet()->setTitle('Other')->fromArray([['Code'], ['X1'], ['X2'], ['X3']]);
(new \\PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx($s))->save(${JSON.stringify(x)});` });
		await open(p, 'index.php?p=import');
		expect(await p.locator('#impXlsxHint').count() === 0 && /\.xlsx/.test(await p.getAttribute('#impFile', 'accept')), 'Excel not offered with the pack');
		await p.setInputFiles('#impFile', x);
		await p.waitForSelector('#impFileCard:not(.d-none)', { timeout: 15000 });
		const prev = async () => p.$$eval('#impPreview tr', (r) => r.map((x) => [...x.children].map((c) => c.textContent.trim()).slice(1)));
		let t = await prev();
		expect(JSON.stringify(t[0]) === '["Name","Since","Credit","Double"]' && JSON.stringify(t[1]) === '["Anna","2024-03-05","1200.5","2401"]', 'preview of sheet 1: ' + JSON.stringify(t.slice(0, 3)));
		expect(await p.locator('#impSheet option').count() === 2 && await p.locator('#impSheetWrap:not(.d-none)').count() === 1 && await p.locator('.impCsvOnly.d-none').count() === 2, 'sheet select / CSV options');
		await p.selectOption('#impSheet', '1');
		await p.waitForFunction(() => /Code/.test(document.querySelector('#impPreview thead') ? document.querySelector('#impPreview thead').textContent : ''), null, { timeout: 10000 });
		t = await prev();
		expect(t.length === 4 && t[1][0] === 'X1', 'preview of sheet 2: ' + JSON.stringify(t));
		expect(/3/.test(await p.locator('#impRowCount').innerText()), 'row count of sheet 2: ' + await p.locator('#impRowCount').innerText());
		// a file that is not what its name says
		fs.writeFileSync(path.join(tmp, 'fake.xlsx'), 'not a spreadsheet');
		await p.click('[data-imp="restart"]').catch(() => {});
		await open(p, 'index.php?p=import');
		await p.setInputFiles('#impFile', path.join(tmp, 'fake.xlsx'));
		await p.waitForSelector('#impMsg .alert-danger', { timeout: 10000 });
	} finally {
		for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpspreadsheet-/.test(d)) fs.rmSync(path.join(tp, d), { recursive: true, force: true });
		for (const d of fs.readdirSync(keep)) fs.renameSync(path.join(keep, d), path.join(tp, d));
		if (stateBefore) fs.writeFileSync(stateFile, stateBefore); else if (fs.existsSync(stateFile)) fs.unlinkSync(stateFile);
		fs.rmSync(tmp, { recursive: true, force: true });
		sql("DELETE FROM zzzzsys_nunext_log WHERE event IN ('nunext_lib_install', 'nunext_lib_test') AND record_id = 'phpspreadsheet'");
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.679: the ticket system sample (install/samples/ticket.json, core/nusampleset.php) – install on the Sample data page, roles,
// row-level security, internal notes, dashboard, remove again; leaves the database as it was
T.sample_ticket = async (s) => {
	if (!fs.existsSync(path.join(__dirname, '../../install/samples/ticket.json'))) { s.skip = 'no ticket.json'; return; }
	const p = s.ga;
	const php = (code) => execSync('php -r ' + JSON.stringify("chdir(" + JSON.stringify(SITE || '.') + "); require 'core/nuapp.php'; " + code)).toString();
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const was = sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'tkthome'") === '1';
	try {
		if (was) php("NuSampleSet::remove('ticket');");
		await open(p, 'index.php?p=sample');
		// 0.8.742: list of samples left, one pane right – a click selects without a reload (?s= in the address)
		expect(await p.locator('#smpList [data-smp]').count() >= 2 && await p.locator('#smpSet_ticket').isHidden(), 'sample list / panes');
		await p.click('#smpList [data-smp="ticket"]');
		expect(await p.locator('#smpSet_ticket').isVisible() && await p.locator('#smpSet_sales').isHidden() && /[?&]s=ticket/.test(p.url()), 'list click selects the ticket pane: ' + p.url());
		expect(await p.locator('#smpSet_ticket .smpSetInstall').count() === 1, 'ticket sample card without Install');
		await Promise.all([p.waitForNavigation(), p.click('#smpSet_ticket .smpSetInstall')]); await p.waitForLoadState('networkidle');
		expect(await p.locator('#smpSet_ticket .smpSetInstalled').count() === 1, 'not installed');
		// passwords: tab Logins open, masked until "Show passwords"
		expect(await p.locator('#smp_ticket_logins').isVisible() && await p.locator('#smpSet_ticket .smpPwVal').first().isHidden(), 'passwords masked on the Logins tab');
		await p.click('#smpSet_ticket .smpPwToggle');
		expect(await p.locator('#smpSet_ticket .smpPwVal').first().isVisible(), 'Show passwords');
		const pw = {};
		for (const c of await p.locator('#smpSet_ticket [data-sample-pw]').all()) pw[await c.getAttribute('data-sample-pw')] = (await c.textContent()).trim();
		expect(Object.keys(pw).length >= 5 && pw.tkt_clara, 'passwords: ' + Object.keys(pw).join(','));
		expect(+sql('SELECT COUNT(*) FROM tkt_ticket') >= 50 && +sql('SELECT COUNT(*) FROM tkt_comment') > 100, 'sample data missing');
		// dates moved to today: the newest ticket is at most two days old
		expect(+sql('SELECT DATEDIFF(CURRENT_DATE, MAX(created_at)) FROM tkt_ticket') <= 2, 'dates not moved');
		// 0.8.1036: the sample's project – parts, notes, to-dos with moved due dates, link on the sample page
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_project_item WHERE project_id = 'tkt_prj_001'") === '22' && sql("SELECT COUNT(*) FROM zzzzsys_nunext_project_note WHERE project_id = 'tkt_prj_001'") === '2', 'sample project parts / notes');
		// 0.8.1039: help pages of the sample – linked from the forms / the Handling tab / the Due field, parts per role, a German translation
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_help WHERE code LIKE 'tkt_help%'") === '6', 'sample help pages');
		{
			const cu = await login('tkt_clara', pw.tkt_clara);
			const fr = await cu.evaluate(async () => (await fetch('index.php?p=userhelp&c=tkt_help_ticket&frag=1')).json());
			expect(fr.title === 'Tickets' && /Rate the solution/.test(fr.html) && !/Internal note/.test(fr.html) && !/Handling a ticket/.test(fr.html), 'customer view of the ticket help: ' + (fr.html || '').slice(0, 200));
			const no = await cu.evaluate(async () => (await fetch('index.php?p=userhelp&c=tkt_help_categories&frag=1')).status);
			expect(no === 404, 'categories page only for the team lead: ' + no);
			await open(cu, 'index.php?p=form&f=tktticket&r=-1');
			expect(await cu.locator('#nuHelpButton').count() === 1, 'help button on the ticket form');
			await cu.context().close();
			const ag = await login('tkt_alex', pw.tkt_alex);
			const fa = await ag.evaluate(async () => (await fetch('index.php?p=userhelp&c=tkt_help_ticket&frag=1')).json());
			expect(/Internal note/.test(fa.html) && /Handling a ticket/.test(fa.html) && !/Rate the solution/.test(fa.html), 'agent view of the ticket help');
			await ag.context().close();
		}
		expect(+sql("SELECT DATEDIFF(MIN(due), CURRENT_DATE) FROM zzzzsys_nunext_project_task WHERE project_id = 'tkt_prj_001' AND status = 'open'") >= 3, 'to-do due dates moved');
		await p.click('#smp_ticket_contents_tab');
		await Promise.all([p.waitForNavigation(), p.click('#smpSet_ticket .smpProjectLink')]); await p.waitForLoadState('networkidle');
		expect(await p.locator('#prjKind-form tr[data-ref]').count() === 6 && (await p.textContent('#prjPinned')).includes('Tickets'), 'sample project page');
		await p.click('#prjTab-items'); await p.click('#prjSuggestBtn');
		await p.waitForSelector('#prjSuggestList .list-group-item');
		expect(await p.locator('#prjSuggestList input[value^="form|"]').count() === 0, 'every form of the sample is in its project');
		await p.keyboard.press('Escape');
		await open(p, 'index.php?p=sample&s=ticket');
		// customer: only own tickets, no internal notes, another customer's ticket refused
		const own = sql("SELECT COUNT(*) FROM tkt_ticket WHERE tic_requester_id = 'tkt_clara'");
		const c = await login('tkt_clara', pw.tkt_clara);
		await open(c, 'index.php?p=form&f=tktticket');
		const total = ((await c.textContent('body')).match(/Total:\s*(\d+)/) || [])[1];
		expect(total === own, 'customer list: ' + total + ' / own ' + own);
		const mine = sql("SELECT tkt_ticket_id FROM tkt_ticket WHERE tic_requester_id = 'tkt_clara' AND tic_status <> 'new' ORDER BY 1 LIMIT 1");
		await open(c, 'index.php?p=form&f=tktticket&r=' + mine);
		const internal = sql(`SELECT com_body FROM tkt_comment WHERE com_tkt_ticket_id = '${mine}' AND com_internal = 1 LIMIT 1`);
		expect(internal && !(await c.content()).includes(internal.slice(0, 30)), 'customer sees an internal note');
		expect(await c.locator('#nuTabHolder .nuTab:visible').filter({ hasText: 'Handling' }).count() === 0, 'customer sees the Handling tab');
		const other = sql("SELECT tkt_ticket_id FROM tkt_ticket WHERE tic_requester_id = 'tkt_oscar' ORDER BY 1 LIMIT 1");
		await open(c, 'index.php?p=form&f=tktticket&r=' + other);
		expect(/another customer/.test(await c.content()), 'customer opened another customer\'s ticket');
		await c.context().close();
		// agent: dashboard on the start page
		const a = await login('tkt_alex', pw.tkt_alex);
		await open(a, 'index.php?p=home');
		expect(/Open tickets/.test(await a.content()), 'agent start page without the support dashboard');
		// 0.8.682: "Due next" rows open their ticket in a new tab (column nu_record_id, option new_tab)
		await a.waitForSelector('.nuDashRowLink', { timeout: 8000 });
		const [tab] = await Promise.all([a.context().waitForEvent('page'), a.locator('.nuDashRowLink').first().click()]);
		await tab.waitForLoadState();
		expect(/p=form&f=tktticket&r=\w+/.test(tab.url()) && !/nu_record_id/i.test(await a.locator('.nuDashTable thead').first().textContent()), 'Due next row: ' + tab.url());
		await tab.close();
		// ticket list: filters for category / priority / status, badges for priority and status
		await open(a, 'index.php?p=form&f=tktticket');
		expect(await a.locator('#nuFlt3, #nuFlt4, #nuFlt5').count() === 3, 'filters missing');
		expect(await a.locator('div.nuCell[data-nu-column="4"] .badge').count() > 0 && await a.locator('div.nuCell[data-nu-column="5"] .badge').count() > 0
			&& await a.locator('div.nuCell[data-nu-column="3"] .badge').count() === 0, 'badges');
		await Promise.all([a.waitForURL(/Urgent/), a.selectOption('#nuFlt4', 'Urgent')]); await a.waitForLoadState('networkidle');
		const urg = sql("SELECT COUNT(*) FROM tkt_ticket WHERE tic_priority = 'urgent' AND (tic_level = 1 OR tic_agent_id = 'tkt_alex')");
		const tot = ((await a.textContent('body')).match(/Total:\s*(\d+)/) || [])[1];
		expect(tot === urg, 'priority filter: ' + tot + ' / ' + urg);
		// a new ticket: the attachments sub-form is on the Attachments tab
		await open(a, 'index.php?p=form&f=tktticket&r=-1');
		expect(await a.evaluate(() => { const sf = document.getElementById('tic_attachments'), pane = sf && sf.closest('[data-nu-tab], .nuTabPage, .tab-pane');
			return !!sf && !!sf.querySelector('input[type=file]') && (!pane || /Attachments/.test(document.querySelector('[data-bs-target="#' + pane.id + '"], [href="#' + pane.id + '"]')?.textContent || 'Attachments')); }), 'attachments sub-form');
		expect(a.errors.length === 0, 'agent: ' + a.errors.slice(0, 2).join(' | '));
		await a.context().close();
		// remove on the page
		await open(p, 'index.php?p=sample&s=ticket');
		expect(await p.isVisible('#smpSet_ticket .smpSetRemove'), 'Remove not in the title row (0.8.858)');
		await p.click('#smpSet_ticket .smpSetRemove'); await p.waitForTimeout(400);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#nuConfirm.show .js-confirm-ok')]);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'tkthome'") === '0' && sql("SHOW TABLES LIKE 'tkt_ticket'") === '', 'not removed');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_user WHERE SUBSTRING(zzzzsys_user_id, 1, 4) = 'tkt_'") === '0' && sql("SELECT COUNT(*) FROM zzzzsys_object WHERE SUBSTRING(zzzzsys_object_id, 1, 4) = 'tkto'") === '0', 'rows left');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_project WHERE zzzzsys_nunext_project_id = 'tkt_prj_001'") === '0' && sql("SELECT COUNT(*) FROM zzzzsys_nunext_project_item WHERE project_id = 'tkt_prj_001'") === '0', 'sample project left');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'tkthome'") === '1') php("NuSampleSet::remove('ticket');");
		if (was) php("NuSampleSet::install('ticket');");
	}
};

T.sample_ticket_suggest = async (s) => {
	// the ticket sample shows suggestions while typing: the subject of a ticket offers the subjects of earlier tickets
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_object WHERE zzzzsys_object_id = 'tkto034'") !== '1') { s.skip = 'ticket sample not installed'; return; }
	const def = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'install', 'samples', 'ticket.json'), 'utf8'));
	const row = def.rows.find((r) => r[0] === 'zzzzsys_object' && r[1].zzzzsys_object_id === 'tkto034')[1];
	const cfg = JSON.parse(row.sob_all_json).nunext.suggest;
	expect(def.version >= 10 && cfg && cfg.src === 'sql' && /#term#/.test(cfg.sql), 'sample file has the suggestion: ' + JSON.stringify(cfg));
	const keep = sql("SELECT IFNULL(sob_all_json, '') FROM zzzzsys_object WHERE zzzzsys_object_id = 'tkto034'");
	const put = (v) => sql("UPDATE zzzzsys_object SET sob_all_json = " + (v === '' ? 'NULL' : "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "''") + "'") + " WHERE zzzzsys_object_id = 'tkto034'");
	const subj = sql("SELECT tic_subject FROM tkt_ticket WHERE CHAR_LENGTH(tic_subject) > 8 ORDER BY tic_subject LIMIT 1");
	const word = subj.slice(2, 6);
	const n = Number(sql("SELECT COUNT(DISTINCT tic_subject) FROM tkt_ticket WHERE tic_subject LIKE '%" + word.replace(/'/g, "''") + "%'"));
	try {
		put(row.sob_all_json);
		await open(p, 'index.php?p=form&f=tktticket&r=-1'); await p.waitForSelector('#nuSaveButton');
		await p.click('#tic_subject'); await p.keyboard.type(word, { delay: 40 }); await p.waitForSelector('.nuSuggestList:not([hidden]) .nuSuggestItem');
		const shown = await p.locator('.nuSuggestList:not([hidden]) .nuSuggestItem').count();
		expect(shown === Math.min(n, cfg.max) && shown >= 1, 'subjects offered: ' + shown + ' of ' + n);
		await p.keyboard.press('ArrowDown'); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
		expect((await p.inputValue('#tic_subject')).includes(word), 'subject taken: ' + await p.inputValue('#tic_subject'));
		await p.evaluate(() => nuHasNotBeenEdited());
	} finally { put(keep); }
};

T.sample_ticket_details = async (s) => {
	// ticket sample version 11: the tickets list has row details – ▸ opens the hidden columns Description, Channel, Created
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'tktticket'") !== '1') { s.skip = 'ticket sample not installed'; return; }
	const def = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'install', 'samples', 'ticket.json'), 'utf8'));
	const form = def.rows.find((r) => r[0] === 'zzzzsys_form' && r[1].zzzzsys_form_id === 'tktticket')[1];
	const cols = def.rows.filter((r) => r[0] === 'zzzzsys_browse' && ['tkt_br_017', 'tkt_br_018', 'tkt_br_019'].includes(r[1].zzzzsys_browse_id)).map((r) => r[1]);
	expect(def.version >= 11 && JSON.parse(form.sfo_json).nunext.details.cols === true && cols.length === 3 && cols.every((c) => c.sbr_width === 0), 'sample file has the row details');
	const q = (v) => v === null ? 'NULL' : typeof v === 'number' ? String(v) : "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";
	const keep = sql("SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'tktticket'");
	const had = sql("SELECT COUNT(*) FROM zzzzsys_browse WHERE zzzzsys_browse_id IN ('tkt_br_017', 'tkt_br_018', 'tkt_br_019')");
	try {
		sql('UPDATE zzzzsys_form SET sfo_json = ' + q(form.sfo_json) + " WHERE zzzzsys_form_id = 'tktticket'");
		if (had === '0') cols.forEach((c) => sql('INSERT INTO zzzzsys_browse (' + Object.keys(c).join(', ') + ') VALUES (' + Object.values(c).map(q).join(', ') + ')'));
		await open(p, 'index.php?p=form&f=tktticket');
		await p.locator("#nuBrowseTable tbody tr.nuBrowseRow .nuDetBtn").first().click();
		await p.waitForSelector('tr.nuDetRow .nuDetCols');
		const txt = await p.textContent('tr.nuDetRow .nuDetCols');
		expect(/Description/.test(txt) && /Channel/.test(txt) && /Created/.test(txt), 'details: ' + txt.slice(0, 300));
		s.result = txt.slice(0, 200);
	} finally {
		sql('UPDATE zzzzsys_form SET sfo_json = ' + (keep === '' ? 'NULL' : q(keep)) + " WHERE zzzzsys_form_id = 'tktticket'");
		if (had === '0') sql("DELETE FROM zzzzsys_browse WHERE zzzzsys_browse_id IN ('tkt_br_017', 'tkt_br_018', 'tkt_br_019')");
	}
};

T.sample_changes = async (s) => {
	// 0.8.1256: Sample data shows the change history of each sample (tab Changes) and, when outdated, what Refresh brings
	const p = s.ga;
	const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplehome'");
	if (!fid) { s.skip = 'no sales sample'; return; }
	const json0 = sql("SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplehome'");
	const q = (v) => "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";
	const j = JSON.parse(json0); const cur = j.nunext.sample.version;
	j.nunext.sample.version = cur - 3;
	sql('UPDATE zzzzsys_form SET sfo_json = ' + q(JSON.stringify(j)) + " WHERE zzzzsys_form_id = 'samplehome'");
	try {
		await open(p, 'index.php?p=sample&s=sales');
		const r = await p.evaluate(() => ({
			whats: [...document.querySelectorAll('#smpSet_sales .smpWhatsNew .smpChg')].map(li => +li.dataset.v),
			all: document.querySelectorAll('#smp_sales_changes .smpChg').length,
			newBadges: document.querySelectorAll('#smp_sales_changes .smpChgNew').length,
			tab: !!document.getElementById('smp_sales_changes_tab'),
			ticketTab: document.querySelectorAll('#smp_ticket_changes .smpChg').length,
		}));
		expect(r.tab, 'no Changes tab');
		expect(JSON.stringify(r.whats) === JSON.stringify([cur, cur - 1, cur - 2]), 'what Refresh brings: ' + JSON.stringify(r.whats));
		expect(r.all >= 20 && r.newBadges === 3, 'history: ' + JSON.stringify(r));
		expect(r.ticketTab >= 10, 'ticket sample history missing: ' + r.ticketTab);
		s.result = r;
	} finally {
		sql('UPDATE zzzzsys_form SET sfo_json = ' + q(json0) + " WHERE zzzzsys_form_id = 'samplehome'");
	}
};

// records over the REST API (0.8.1337): /v1/forms, /v1/forms/{form}/records[/{id}] through the form's own path (rights, validation,
// Before / After Save, sub-form rows, ETag), filters / sort / include / expand, and /v1/openapi.json for the client's scopes
T.api_records = async (s) => {
	const p = s.ga;
	const carla = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_carla'");
	const ben = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	const api = BASE.replace(/index\.php$/, '') + 'api.php/v1';
	const token = async (ref, scope) => {
		await open(p, 'index.php?p=apiplayground');
		return p.evaluate(async ([url, ref, scope]) => {
			const csrf = document.querySelector('[data-csrf]').getAttribute('data-csrf');
			const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ action: 'token', client_ref: ref, scope }) });
			return ((await r.json()).token || {}).access_token || '';
		}, [BASE + 'index.php?p=apiplayground', ref, scope]);
	};
	const call = async (tok, method, path, body, headers) => {
		const r = await fetch(api + path, { method, headers: Object.assign({ Authorization: 'Bearer ' + tok }, body !== undefined ? { 'Content-Type': 'application/json' } : {}, headers || {}), body: body !== undefined ? JSON.stringify(body) : undefined });
		const txt = await r.text();
		let j = null; try { j = JSON.parse(txt); } catch (e) { j = txt; }
		return { s: r.status, j, h: r.headers };
	};
	try {
		sql("INSERT INTO zzzzsys_nunext_api_client (zzzzsys_nunext_api_client_id, name, client_id, scopes, user_id, active, rate_limit, created_at) VALUES ('e2erecapi','E2E records','nk_e2erec','[\"records:read\",\"records:write\"]','" + carla + "','1',1000,NOW())");
		sql("INSERT INTO zzzzsys_nunext_api_client (zzzzsys_nunext_api_client_id, name, client_id, scopes, user_id, active, rate_limit, created_at) VALUES ('e2erecapi2','E2E records read','nk_e2erec2','[\"records:read\"]','" + ben + "','1',1000,NOW())");
		const tok = await token('e2erecapi', 'records:read records:write');
		expect(tok !== '', 'token');
		let r = await call(tok, 'GET', '/forms');
		expect(r.s === 200 && r.j.data.some(f => f.code === 'sample_order' && f.rights.delete) && !r.j.data.some(f => /^nu/.test(f.id)), 'forms: ' + JSON.stringify(r.j).slice(0, 300));
		r = await call(tok, 'GET', '/forms/nuuser/records');
		expect(r.s === 404, 'Forte system forms are not served: ' + r.s);
		r = await call(tok, 'GET', '/forms/sample_order');
		expect(r.s === 200 && r.j.fields.some(f => f.name === 'ord_number') && r.j.subforms.some(x => x.name === 'sample_items' && x.fields.length > 0), 'form fields + sub-forms');
		// list: filters, sort, fields, expand
		const cust = sql("SELECT ord_sample_customer_id FROM sample_order WHERE ord_sample_customer_id IS NOT NULL GROUP BY ord_sample_customer_id ORDER BY COUNT(*) DESC LIMIT 1");
		const n = parseInt(sql("SELECT COUNT(*) FROM sample_order WHERE ord_sample_customer_id = '" + cust + "'"), 10);
		r = await call(tok, 'GET', '/forms/sample_order/records?ord_sample_customer_id=' + cust + '&sort=-ord_number&limit=2&fields=ord_number,ord_sample_customer_id&expand=ord_sample_customer_id');
		expect(r.s === 200 && r.j.meta.total === n && r.j.data.length === Math.min(2, n), 'filtered list: ' + JSON.stringify(r.j).slice(0, 300));
		expect(r.j.data[0].ord_sample_customer_id && r.j.data[0].ord_sample_customer_id.id === cust && r.j.data[0].ord_sample_customer_id.description !== undefined && r.j.data[0].ord_date === undefined, 'fields + expanded lookup');
		if (r.j.data.length === 2) expect(String(r.j.data[0].ord_number) >= String(r.j.data[1].ord_number), 'sorted descending');
		r = await call(tok, 'GET', '/forms/sample_order/records?nope=1');
		expect(r.s === 422, 'unknown filter → 422');
		r = await call(tok, 'GET', '/forms/sample_order/records?ord_number[like]=zzzzz_none');
		expect(r.s === 200 && r.j.meta.total === 0, 'like filter');
		// create with sub-form rows
		const prod = sql("SELECT sample_product_id FROM sample_product LIMIT 1");
		const itemFields = (await call(tok, 'GET', '/forms/sample_order')).j.subforms.find(x => x.name === 'sample_items').fields.map(f => f.name);
		const row = {}; row.ori_sample_product_id = prod; row.ori_qty = '2'; expect(itemFields.includes('ori_qty'), 'item fields: ' + itemFields);
		r = await call(tok, 'POST', '/forms/sample_order/records', { ord_number: 'E2E-API-1', ord_sample_customer_id: cust, ord_date: '2026-11-19', sample_items: [row] });
		expect(r.s === 201 && r.j.id && r.j.ord_number === 'E2E-API-1' && /\/records\//.test(r.h.get('location') || '') && r.h.get('etag'), 'created: ' + r.s + ' ' + JSON.stringify(r.j).slice(0, 400));
		const id = String(r.j.id);
		expect(Array.isArray(r.j.sample_items) && r.j.sample_items.length === 1, 'sub-form row saved');
		const itemId = r.j.sample_items[0].id;
		// unknown / read-only fields
		r = await call(tok, 'POST', '/forms/sample_order/records', { ord_number: 'X', nope: 1, ord_qty_total: 3 });
		expect(r.s === 422 && r.j.errors.some(e => e.field === 'nope') && r.j.errors.some(e => e.field === 'ord_qty_total'), 'unknown + read only → 422');
		// PATCH needs If-Match; changes only the fields sent; sub-form row changed / added / deleted
		r = await call(tok, 'PATCH', '/forms/sample_order/records/' + id, { ord_note: 'via API' });
		expect(r.s === 428, 'If-Match required');
		const g = await call(tok, 'GET', '/forms/sample_order/records/' + id);
		expect(g.s === 200 && g.j.sample_items.length === 1, 'read one with sub-form rows');
		r = await call(tok, 'PATCH', '/forms/sample_order/records/' + id, { sample_items: [{ id: itemId, _delete: true }] }, { 'If-Match': g.h.get('etag') });
		expect(r.s === 422 && r.j.errors.some(e => /item/i.test(e.message)), 'Before Save of the form refuses (no item left): ' + r.s + ' ' + JSON.stringify(r.j).slice(0, 200));
		r = await call(tok, 'PATCH', '/forms/sample_order/records/' + id, { ord_note: 'via API', sample_items: [{ id: itemId, _delete: true }, { ori_sample_product_id: prod, ori_qty: '3' }] }, { 'If-Match': g.h.get('etag') });
		expect(r.s === 200 && r.j.ord_note === 'via API' && r.j.ord_number === 'E2E-API-1' && r.j.sample_items.length === 1 && parseFloat(r.j.sample_items[0].ori_qty) === 3 && r.j.sample_items[0].id !== itemId, 'patched: ' + r.s + ' ' + JSON.stringify(r.j).slice(0, 300));
		r = await call(tok, 'PATCH', '/forms/sample_order/records/' + id, { ord_note: 'old etag' }, { 'If-Match': g.h.get('etag') });
		expect(r.s === 412, 'stale ETag → 412');
		// a read-only client: no writes; another service user without the delete right
		const tok2 = await token('e2erecapi2', 'records:read');
		r = await call(tok2, 'DELETE', '/forms/sample_order/records/' + id, undefined, { 'If-Match': '*' });
		expect(r.s === 403 && /records:write/.test(r.j.title), 'scope records:write needed: ' + r.s);
		// OpenAPI
		r = await call(tok, 'GET', '/openapi.json');
		expect(r.s === 200 && r.j.openapi === '3.1.0' && r.j.paths['/v1/forms/sample_order/records'] && r.j.paths['/v1/forms/sample_order/records/{id}'].patch && r.j.components.schemas.sample_order && !r.j.paths['/v1/users'], 'openapi: ' + Object.keys(r.j.paths || {}).slice(0, 6));
		r = await call(tok2, 'GET', '/openapi.json');
		expect(r.s === 200 && r.j.paths['/v1/forms/sample_order/records'].get && !r.j.paths['/v1/forms/sample_order/records'].post, 'openapi of a read-only client has no POST');
		// delete
		const cur = await call(tok, 'GET', '/forms/sample_order/records/' + id);
		r = await call(tok, 'DELETE', '/forms/sample_order/records/' + id, undefined, { 'If-Match': cur.h.get('etag') });
		expect(r.s === 204 && sql("SELECT COUNT(*) FROM sample_order WHERE sample_order_id = '" + id + "'") === '0', 'deleted: ' + r.s);
		r = await call(tok, 'GET', '/forms/sample_order/records/' + id);
		expect(r.s === 404, 'gone');
	} finally {
		sql("DELETE FROM sample_order_item WHERE ori_sample_order_id IN (SELECT sample_order_id FROM sample_order WHERE ord_number LIKE 'E2E-API%')");
		sql("DELETE FROM sample_order WHERE ord_number LIKE 'E2E-API%'");
		sql("DELETE FROM zzzzsys_nunext_api_token WHERE client_ref IN ('e2erecapi', 'e2erecapi2')");
		sql("DELETE FROM zzzzsys_nunext_api_client WHERE zzzzsys_nunext_api_client_id IN ('e2erecapi', 'e2erecapi2')");
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.sample_tile_icons = async (s) => {                   // 0.8.1416: every tile of the sample start page has an icon (Excel tiles too)
	const p = s.ga;
	const home = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'sample_home'");
	if (!home) { s.skip = 'no sample data'; return; }
	if (!sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = '" + home + "' AND sfo_style LIKE '%home_xlsx_pss::before%'") * 1) { s.skip = 'sample data older than version 26'; return; }
	await open(p, 'index.php?p=form&f=' + home + '&r=-1');
	const no = await p.evaluate(() => [...document.querySelectorAll('.btn.nuRunButton.sampleTile')]
		.filter((b) => !b.querySelector('i') && !/["'].+["']/.test(getComputedStyle(b, '::before').content)).map((b) => b.id));
	expect(no.length === 0, 'tiles without an icon: ' + no.join(', '));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};
