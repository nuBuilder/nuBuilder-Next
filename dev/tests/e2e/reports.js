// e2e tests: reports and dashboards – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// 0.8.697: dashboard form – Access levels on their own tab with a group filter and a ticked count; Save stays on the tab; the
// description is saved (its field name did not match before)
T.dashboard_access_tab = async (s) => {
	const p = s.ga, keep = path.join(os.tmpdir(), 'nunext-e2e-dash.sql');
	if (!sql("SELECT COUNT(*) FROM zzzzsys_nunext_dashboard WHERE zzzzsys_nunext_dashboard_id = 'sample_dash_sales'")) { s.skip = 'no sample dashboard'; return; }
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_dashboard > ' + keep);
	try {
		await open(p, 'index.php?p=dashboards&id=sample_dash_sales');
		expect(await p.isVisible('#dCode') && !(await p.isVisible('.dashAccessList')), 'General tab first');
		await p.click('#dashTabAccessBtn'); await p.waitForTimeout(300);
		const vis = () => p.$$eval('.dashAccessList [data-group]', e => e.filter(x => !x.hidden).map(x => x.getAttribute('data-group')));
		const all = await vis();
		await p.selectOption('#dAccGroup', 'tkt');
		const tkt = await vis();
		expect(all.length > tkt.length && tkt.length > 0 && tkt.every(g => g === 'tkt'), 'group filter: ' + tkt.join(','));
		await p.selectOption('#dAccGroup', '*');
		const before = await p.textContent('#dAccCount'), box = p.locator('.dashAccessList input[name="access[]"]:not(:checked)').first();
		await box.check();
		const after = await p.textContent('#dAccCount');
		expect(parseInt(after, 10) === parseInt(before, 10) + 1, 'count: ' + before + ' → ' + after);
		await p.click('#dashTabGeneralBtn'); await p.fill('#dDesc', 'e2e description');
		await p.click('#dashTabAccessBtn');
		await Promise.all([p.waitForNavigation(), p.click('#dashSave')]);
		expect(/tab=access/.test(p.url()) && await p.isVisible('.dashAccessList'), 'stays on the tab: ' + p.url());
		expect(sql("SELECT description FROM zzzzsys_nunext_dashboard WHERE zzzzsys_nunext_dashboard_id = 'sample_dash_sales'") === 'e2e description', 'description saved');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(keep) }); fs.unlinkSync(keep);
	}
};

// 0.8.510: report form – Launch form and Data source are searchable (Tom Select); choosing one marks the form as edited
T.report_tomselect = async (s) => {
	const p = s.ga;
	const id = sql("SELECT zzzzsys_report_id FROM zzzzsys_report WHERE sre_code = 'sample_customers'");
	if (!id) { s.skip = 'no sample report'; return; }
	await open(p, 'index.php?p=reports&id=' + id);
	for (const f of ['sre_zzzzsys_form_id', 'sre_zzzzsys_php_id']) expect(await p.locator('#' + f + '.tomselected').count() === 1, f + ' is no Tom Select');
	await p.locator('#sre_zzzzsys_form_id').locator('xpath=following-sibling::div[contains(@class,"ts-wrapper")]').locator('.ts-control').click();
	await p.keyboard.type('sample_order'); await p.waitForTimeout(300);
	expect(await p.locator('.ts-dropdown .option[data-value="sampleorder"]').count() === 1, 'launch form not found by search');
	await p.keyboard.press('Escape');
	expect(await p.inputValue('#sre_zzzzsys_php_id') === sql("SELECT sre_zzzzsys_php_id FROM zzzzsys_report WHERE zzzzsys_report_id = '" + id + "'"), 'data source not preselected');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.dashboard = async () => {                              // sample dashboard: data for its access levels, refused for others
	const dash = async (user) => {
		const p = await login(user, UPW);
		await open(p, 'index.php?p=home');
		const r = await p.evaluate(async () => {
			const csrf = (document.querySelector('input[name=_csrf]') || document.querySelector('meta[name=csrf-token]') || {}).value || (document.querySelector('meta[name=csrf-token]') || {}).content || '';
			const call = b => fetch('index.php?p=dashboardapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b) }).then(x => x.json());
			const m = await call({ action: 'meta', code: 'sample_products' });
			if (!m.ok) return { ok: false };
			const rows = [];
			for (const w of m.widgets) { const d = await call({ action: 'data', code: 'sample_products', widget: w.id }); rows.push(d.ok ? (d.data && d.data.rows ? d.data.rows.length : (d.rows || []).length) : -1); }
			return { ok: true, rows };
		});
		await p.context().close();
		return r;
	};
	const m = await dash('sample_carla');
	expect(m.ok && m.rows.length > 0 && m.rows.every(n => n > 0), 'manager: widgets without data ' + JSON.stringify(m));
	sql("UPDATE zzzzsys_nunext_dashboard SET access = '[\"sample_manager\"]' WHERE code = 'sample_products'");   // the viewer taken out (database is put back)
	const v = await dash('sample_anna');
	expect(!v.ok, 'viewer (not in the dashboard\'s access levels) gets its data');
};

// 0.8.770: TCPDF gets its own cache folder – a photo printed through <img src="/…"> (report "Fields contain HTML") is no longer deleted
// when it lies below the system's temp folder (TCPDF removes every used image below K_PATH_CACHE); the photo is in the PDF
T.report_photo_kept = async (s) => {
	const p = s.ga;
	if (!SITE) return 'skip';
	const root = path.dirname(SITE);                        // the web server's document root on the test site (nuBuilder's folder)
	const name = 'e2e_photo_' + Date.now() + '.jpg', file = path.join(root, name);
	// a small JPEG (8×8, grey)
	fs.writeFileSync(file, Buffer.from('/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5miiigD/2Q==', 'base64'));
	const lay = JSON.parse(sql("SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = 'sample_rpt_customers'"));
	const det = lay.groups[0].sections[0];
	det.height = 60;
	det.objects = [{ id: 'e2e_ph_f', name: 'e2e_ph_f', objectType: 'field', fieldName: 'photo_html', left: 0, top: 4, width: 400, height: 50, fontWeight: '', textAlign: 'left',
		backgroundColor: '#FFFFFF', borderColor: '#000000', borderWidth: '0', fontColor: '#000000', fontFamily: 'Helvetica', fontSize: '8', format: '', minRows: 0, maxRows: 0, zIndex: 1 }];
	lay.ishtml = 'Y';
	const esc = v => v.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
	try {
		sql(`INSERT INTO zzzzsys_select (zzzzsys_select_id, sse_code, sse_description, sse_json, sse_sql, sse_edit) VALUES ('e2e_ph_sql', 'e2e_ph', 'photo', '',
			'SELECT cus_name, ''<img src="/${name}">'' AS photo_html FROM sample_customer', '1')`);
		sql(`INSERT INTO zzzzsys_report (zzzzsys_report_id, sre_code, sre_description, sre_group, sre_layout, sre_zzzzsys_php_id, sre_zzzzsys_form_id)
			SELECT 'e2e_ph_rpt', 'e2e_ph_rpt', 'photo', '', '${esc(JSON.stringify(lay))}', 'SQL:e2e_ph_sql', sre_zzzzsys_form_id FROM zzzzsys_report WHERE zzzzsys_report_id = 'sample_rpt_customers'`);
		const r = await p.context().request.get(BASE + 'index.php?p=reportpdf&c=e2e_ph_rpt&dl=1');
		const body = (await r.body()).toString('latin1');
		if (process.env.E2E_KEEP) fs.writeFileSync(process.env.E2E_KEEP, await r.body());
		expect(r.status() === 200 && body.startsWith('%PDF'), 'PDF: ' + r.status());
		expect(/\/Subtype\s*\/Image/.test(body), 'the photo is in the PDF');
		expect(fs.existsSync(file), 'the photo file is still there after the report');
		expect(fs.existsSync(path.join(SITE, 'temp', 'tcpdf')), 'TCPDF cache = temp/tcpdf of nuBuilder Next');
	} finally {
		if (!process.env.E2E_KEEP) sql("DELETE FROM zzzzsys_report WHERE zzzzsys_report_id = 'e2e_ph_rpt'; DELETE FROM zzzzsys_select WHERE zzzzsys_select_id = 'e2e_ph_sql'");
		try { fs.unlinkSync(file); } catch (e) { /* already gone */ }
	}
};

// 0.8.874: content type of report fields / labels – Markdown and HTML per object, heights of the printed HTML, unsafe HTML removed,
// hash values in HTML labels as text; designer: Content select (Automatic removes the key), MD / HTML tag on the canvas
T.report_rich_text = async (s) => {
	const p = s.ga;
	const lay = JSON.parse(sql("SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = 'sample_rpt_customers'"));
	const base = { fontWeight: '', textAlign: 'left', backgroundColor: '#FFFFFF', borderColor: '#000000', borderWidth: '0', fontColor: '#000000', fontFamily: 'Helvetica',
		fontSize: '10', format: '', minRows: 0, maxRows: 0, zIndex: 1 };
	const det = lay.groups[0].sections[0];
	det.height = 24;
	det.objects = [Object.assign({}, base, { id: 'e2e_rt_md', name: 'e2e_rt_md', objectType: 'field', fieldName: 'md', left: 0, top: 2, width: 600, height: 20, contentType: 'md' })];
	const ph = lay.groups[2].sections[0];
	ph.height = Math.max(40, +ph.height || 0);
	ph.objects = [Object.assign({}, base, { id: 'e2e_rt_lbl', name: 'e2e_rt_lbl', objectType: 'label', fieldName: '<b>E2EHEAD</b> #page# <i>#description#</i>', left: 0, top: 4, width: 600, height: 20, contentType: 'html' })];
	lay.ishtml = 'N';
	const esc = v => v.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
	const put = l => sql(`UPDATE zzzzsys_report SET sre_layout = '${esc(JSON.stringify(l))}' WHERE zzzzsys_report_id = 'e2e_rt_rpt'`);
	const pdf = async (q) => {
		const r = await p.context().request.get(BASE + 'index.php?p=reportpdf&c=e2e_rt_rpt&dl=1' + (q || ''));
		const b = await r.body();
		expect(r.status() === 200 && b.toString('latin1').startsWith('%PDF'), 'PDF: ' + r.status());
		return b;
	};
	const pages = b => (b.toString('latin1').match(/\/Type\s*\/Page\b(?!s)/g) || []).length;
	const text = b => {
		try {
			const f = path.join(os.tmpdir(), 'e2e_rt_' + Date.now() + '.pdf');
			fs.writeFileSync(f, b);
			const t = execSync(`pdftotext "${f}" -`, { stdio: ['ignore', 'pipe', 'ignore'] }).toString();
			fs.unlinkSync(f);
			return t;
		} catch (e) { return null; }                          // no pdftotext on this computer
	};
	try {
		sql(`INSERT INTO zzzzsys_select (zzzzsys_select_id, sse_code, sse_description, sse_json, sse_sql, sse_edit) VALUES ('e2e_rt_sql', 'e2e_rt', 'rich text', '',
			'SELECT cus_name, CONCAT(''## Heading '', cus_name, ''\n\n- one **bold**\n- two\n- three\n\nEnd <img src="phar://x.jpg"> <b>tag</b>'') AS md FROM sample_customer', '1')`);
		sql(`INSERT INTO zzzzsys_report (zzzzsys_report_id, sre_code, sre_description, sre_group, sre_layout, sre_zzzzsys_php_id, sre_zzzzsys_form_id)
			SELECT 'e2e_rt_rpt', 'e2e_rt_rpt', 'rich <u>text</u>', '', '${esc(JSON.stringify(lay))}', 'SQL:e2e_rt_sql', sre_zzzzsys_form_id FROM zzzzsys_report WHERE zzzzsys_report_id = 'sample_rpt_customers'`);
		const full = await pdf();
		const t = text(full);
		if (t !== null) {
			expect(/Heading/.test(t) && /bold/.test(t) && !/\*\*|##/.test(t), 'Markdown printed as formatted text: ' + t.slice(0, 200));
			expect(/<b>tag<\/b>/.test(t), 'HTML inside Markdown is text');
			expect(/E2EHEAD 1/.test(t) && !/<b>E2EHEAD/.test(t), 'HTML label printed as HTML with #page#');
			expect(/rich <u>text<\/u>/.test(t), 'values in an HTML label are text');
		}
		// heights: the Markdown field grows to its printed height – one row per field needs fewer pages
		lay.groups[0].sections[0].objects[0].maxRows = 1;
		put(lay);
		const one = await pdf();
		expect(pages(full) > pages(one), 'grown fields need more pages: ' + pages(full) + ' / ' + pages(one));
		// the same layout as plain text: the raw Markdown is printed
		lay.groups[0].sections[0].objects[0].maxRows = 0;
		lay.groups[0].sections[0].objects[0].contentType = 'text';
		put(lay);
		const raw = text(await pdf());
		if (raw !== null) expect(/\*\*bold\*\*/.test(raw), 'Text prints the raw Markdown');
		// designer: Content select, tag on the canvas, Automatic removes the key
		lay.groups[0].sections[0].objects[0].contentType = 'md';
		put(lay);
		await open(p, 'index.php?p=reportdesigner&id=e2e_rt_rpt');
		await p.waitForSelector('.rdObj[data-id="e2e_rt_md"]');
		expect((await p.textContent('.rdObj[data-id="e2e_rt_md"] .rdCt')) === 'MD', 'MD tag on the canvas');
		await p.click('.rdObj[data-id="e2e_rt_md"]');
		const ct = p.locator('select[data-prop="contentType"]');
		expect(await ct.inputValue() === 'md', 'Content select shows Markdown');
		expect(await p.locator('.rdProp:has(select[data-prop="contentType"]) .nuInfoTip').count() === 1, '(i) next to Content');
		await ct.selectOption('');
		expect(await p.locator('.rdObj[data-id="e2e_rt_md"] .rdCt').count() === 0, 'no tag while Automatic (report without HTML)');
		await p.click('.nuBtnSave');
		await p.waitForFunction(() => !document.querySelector('.nuBtnSave.nuSaveEdited, .nuBtnSave:disabled'), null, { timeout: 5000 }).catch(() => {});
		await p.waitForTimeout(500);
		let o = JSON.parse(sql("SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = 'e2e_rt_rpt'")).groups[0].sections[0].objects[0];
		expect(!('contentType' in o), 'Automatic: no contentType stored – ' + JSON.stringify(o).slice(0, 120));
		await ct.selectOption('html');
		await p.click('.nuBtnSave');
		await p.waitForTimeout(800);
		o = JSON.parse(sql("SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = 'e2e_rt_rpt'")).groups[0].sections[0].objects[0];
		expect(o.contentType === 'html', 'HTML stored');
	} finally {
		sql("DELETE FROM zzzzsys_report WHERE zzzzsys_report_id = 'e2e_rt_rpt'; DELETE FROM zzzzsys_select WHERE zzzzsys_select_id = 'e2e_rt_sql'");
	}
};

T.dashboard_chart = async (s) => {                       // 0.8.896: a chart of the sample dashboard draws with the bundled ApexCharts (no script errors)
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplehome&r=-1');
	await p.locator('[data-nu-dashboard] .apexcharts-svg').first().waitFor({ timeout: 10000 }).catch(() => {});
	const n = await p.evaluate(() => document.querySelectorAll('[data-nu-dashboard] .apexcharts-svg').length);
	expect(n >= 1, 'no chart drawn: ' + n);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.report_run_btn_neutral = async (s) => {
	const ga = s.ga;
	await open(ga, 'index.php?p=reports');
	await ga.waitForSelector('.nuRowActions a.nuRowRun');
	await ga.mouse.move(0, 0);
	const v = await ga.evaluate(() => { const c = document.querySelector('.nuRowActions'), a = [...c.querySelectorAll('a.btn')].filter(x => !x.classList.contains('nuRowXlsx')), cs = e => getComputedStyle(e);   // Excel button (0.8.1324) aside
		return { n: a.length, b0: cs(a[0]).borderTopColor, b1: cs(a[1]).borderTopColor, i0: cs(a[0].querySelector('.bi')).color, i1: cs(a[1].querySelector('.bi')).color,
			primary: (() => { const d = document.createElement('span'); d.style.color = 'var(--bs-primary)'; document.body.appendChild(d); const x = cs(d).color; d.remove(); return x; })(), blank: a[1].target }; });
	expect(v.n === 2 && v.b0 === v.b1, 'designer and run button have the same frame: ' + JSON.stringify(v));
	expect(v.i1 === v.primary && v.i0 !== v.primary && v.blank === '_blank', 'only the run icon is in the accent colour: ' + JSON.stringify(v));
};

// 0.8.1313: report designer – "Send to back" / "Bring to front" also move the object to the start / end of its band, because the PDF
// prints a band's objects in their order (as nuBuilder Forte); objects sent to back stay visible in the designer (band isolates)
T.report_designer_back = async (s) => {
	const p = s.ga;
	const dump = path.join(os.tmpdir(), 'nunext-e2e-1313.sql');
	execSync(`mysqldump ${DB} zzzzsys_report zzzzsys_nunext_log > ${dump}`);
	const order = () => {
		const L = JSON.parse(execSync(`mysql ${DB} -N -r -e "SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = 'sample_rpt_customers'"`).toString());
		for (const g of L.groups) for (const sc of g.sections) if ((sc.objects || []).length >= 2) return sc.objects.map((o) => o.id);
		return [];
	};
	try {
		const before = order();
		expect(before.length >= 2, 'a band with two objects');
		await open(p, 'index.php?p=reportdesigner&id=sample_rpt_customers');
		await p.waitForSelector('.rdObj');
		const last = before[before.length - 1];
		await p.click(`.rdObj[data-id="${last}"]`);
		await p.click('button[title="Send to back"]');
		await p.keyboard.press('Control+s'); await p.waitForTimeout(1200);
		let now = order();
		expect(now[0] === last, 'sent to back = first object of its band: ' + now.join(','));
		const vis = await p.evaluate((id) => { const e = document.querySelector(`.rdObj[data-id="${id}"]`), b = e.closest('.rdBandBody'); return [getComputedStyle(b).isolation, +getComputedStyle(e).zIndex]; }, last);
		expect(vis[0] === 'isolate', 'band body isolates its objects (negative layers stay visible): ' + vis);
		await p.click(`.rdObj[data-id="${last}"]`);
		await p.click('button[title="Bring to front"]');
		await p.keyboard.press('Control+s'); await p.waitForTimeout(1200);
		now = order();
		expect(now[now.length - 1] === last, 'brought to front = last object: ' + now.join(','));
	} finally {
		execSync(`mysql ${DB} < ${dump}`);
	}
};

T.report_xlsx = async (s) => {
	// 0.8.1324: reports as Excel – list button, ?fmt=xlsx (sheets Report with group rows and totals + Data), designer preview, nuRunReportXlsx
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_report WHERE zzzzsys_report_id = 'sample_rpt_orders'") !== '1') { s.skip = 'sample report missing'; return; }
	const sheetText = (buf) => {
		const f = path.join(os.tmpdir(), 'nunext-e2e-rpt-' + process.pid + '.xlsx');
		fs.writeFileSync(f, buf);
		const py = "import zipfile,sys,re,json\nz=zipfile.ZipFile(sys.argv[1])\nn=[x for x in z.namelist()]\nwb=z.read('xl/workbook.xml').decode()\nss=z.read('xl/sharedStrings.xml').decode() if 'xl/sharedStrings.xml' in n else ''\nprint(json.dumps({'sheets':re.findall(r'<sheet name=\"([^\"]+)\"',wb),'s1':z.read('xl/worksheets/sheet1.xml').decode(),'s2':z.read('xl/worksheets/sheet2.xml').decode(),'ss':ss}))";
		const out = JSON.parse(execSync('python3 -I -c "$PY" ' + f, { env: Object.assign({}, process.env, { PY: py }) }).toString());
		fs.unlinkSync(f);
		return out;
	};
	await open(p, 'index.php?p=reports');
	const href = await p.getAttribute('#nuLvTable a.nuRowXlsx[href*="sample_orders"], table a.nuRowXlsx[href*="sample_orders"]', 'href');
	expect(href && /fmt=xlsx/.test(href), 'Excel button in the list: ' + href);
	const res = await p.request.get(new URL(href, p.url()).href);
	expect(res.status() === 200 && /spreadsheetml/.test(res.headers()['content-type'] || ''), 'download: ' + res.status() + ' ' + res.headers()['content-type']);
	const x = sheetText(await res.body());
	const all = x.s1 + x.ss;
	expect(x.sheets.length === 2, 'two sheets: ' + x.sheets.join(', '));
	expect(/Orders by customer/.test(all) && /Orders of the customer/.test(all) && /All orders/.test(all), 'report header, group footer and grand total texts');
	const total = sql('SELECT ROUND(SUM(ord_total), 2) FROM sample_order');
	expect(x.s1.includes('<v>' + Number(total) + '</v>'), 'grand total ' + total + ' as a number');
	const nOrd = Number(sql('SELECT COUNT(*) FROM sample_order'));
	expect((x.s2.match(/<row /g) || []).length === nOrd + 1, 'data sheet: header + ' + nOrd + ' rows');
	// designer preview → Excel
	await open(p, 'index.php?p=reportdesigner&id=sample_rpt_orders');
	await p.click('button:has(.bi-file-earmark-pdf)');
	await p.waitForSelector('#rdPreviewModal.show #rdXlsx');
	// headless Chromium hands the PDF preview over as a download too – it can arrive after the click: wait for the .xlsx one
	const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 15000, predicate: d => !/\.pdf$/i.test(d.suggestedFilename()) }), p.click('#rdXlsx')]);
	expect(/\.xlsx$/.test(dl.suggestedFilename()), 'designer download: ' + dl.suggestedFilename());
	// nuRunReportXlsx from a form page
	await open(p, 'index.php?p=form&f=samplecustomer');
	const [dl2] = await Promise.all([p.waitForEvent('download', { timeout: 15000 }), p.evaluate(() => nuRunReportXlsx('sample_orders'))]);
	expect(/\.xlsx$/.test(dl2.suggestedFilename()), 'nuRunReportXlsx download: ' + dl2.suggestedFilename());
	s.result = { sheets: x.sheets, total };
};
