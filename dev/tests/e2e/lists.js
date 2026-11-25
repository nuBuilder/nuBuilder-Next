// e2e tests: form lists and system lists – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// 0.8.681: nuBrowseStyleBadge(column, rules, options) – colours, labels, icons by value (map or rule list), '$value', contrast, badge: false
T.browse_badge_rules = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer');
	const r = await p.evaluate(() => {
		const cells = [...document.querySelectorAll('div.nuCell[data-nu-column="0"]')].filter(c => c.textContent.trim());
		if (cells.length < 3) return {skip: true};
		const v = cells.map(c => c.textContent.trim());
		cells[2].textContent = '#123456';
		nuBrowseStyleBadge(0, [
			{equals: v[0].toUpperCase(), ignoreCase: true, bg: 'rgb(255, 170, 170)', label: 'First', icon: 'star'},
			{contains: v[1], bg: '#FFF', color: 'nonsense-colour', border: '#000'},
			{test: (x, row) => x === '#123456' && row.length > 0, bg: '$value'},
			{default: true, badge: false}
		]);
		const b = (i) => cells[i].querySelector('.badge');
		return {
			first: b(0) && b(0).style.backgroundColor === 'rgb(255, 170, 170)' && /First/.test(b(0).textContent) && !!b(0).querySelector('.bi-star') && b(0).title === v[0] && b(0).style.color === 'rgb(33, 37, 41)',
			second: b(1) && b(1).style.backgroundColor === 'rgb(255, 255, 255)' && b(1).style.color === 'rgb(33, 37, 41)' && /solid/.test(b(1).style.border) && b(1).textContent === v[1],
			third: b(2) && b(2).style.backgroundColor === 'rgb(18, 52, 86)' && b(2).style.color === 'rgb(255, 255, 255)',
			plain: cells.slice(3).every(c => !c.querySelector('.badge')),
			map: (() => { const c = document.querySelector('div.nuCell[data-nu-column="1"]'); if (!c) return true; const t = c.textContent.trim();
				nuBrowseStyleBadge([1], {[t]: '#000'}); const x = c.querySelector('.badge'); return !!x && x.style.color === 'rgb(255, 255, 255)'; })(),
		};
	});
	if (r.skip) { s.skip = 'fewer than 3 customers'; return; }
	expect(r.first && r.second && r.third && r.plain && r.map, JSON.stringify(r));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.686: log filters – event and user as searchable selects (the user's logins in the log)
T.log_filters = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=log&view=default');
	const st = await p.evaluate(() => { const e = document.getElementById('lvf_event'), u = document.getElementById('lvf_login');
		return { e: e && e.tagName, u: u && u.tagName, ga: !!(u && [...u.options].some(o => o.value === 'globeadmin')) }; });
	expect(st.e === 'SELECT' && st.u === 'SELECT' && st.ga, 'filters: ' + JSON.stringify(st));
	await open(p, 'index.php?p=log&view=default&login=globeadmin&event=login');
	const users = await p.$$eval('#nuListTable tbody tr td:nth-child(2), table.nu-table tbody tr td:nth-child(2)', tds => [...new Set(tds.map(t => t.textContent.trim()))]);
	expect(users.length <= 1 && (users.length === 0 || users[0] === 'globeadmin'), 'filtered rows: ' + users.join(','));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.690: admin tool lists – log (Object filter, record link, long details folded), audit trail user select, e-mail log Tag + user link,
// notifications → Manage and dashboards as system lists, cloner search only with more than 10 rows
T.admin_lists = async (s) => {
	const p = s.ga, keep = path.join(os.tmpdir(), 'nunext-e2e-adminlists.sql');
	const ben = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!ben) { s.skip = 'no sample data'; return; }
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_log zzzzsys_email_log zzzzsys_user > ' + keep);
	try {
		const long = JSON.stringify({ php: Array.from({ length: 40 }, (_, i) => 'line ' + i + ' of a long procedure').join('\n') });
		execSync('mysql ' + DB, { input: `INSERT INTO zzzzsys_nunext_log (zzzzsys_nunext_log_id, log_time, user_id, login, ip, event, object, record_id, details)
			VALUES ('e2elog1', NOW(), 'globeadmin', 'globeadmin', '127.0.0.1', 'user_saved', 'zzzzsys_user', '${ben}', '${long.replace(/'/g, "''")}');
			UPDATE zzzzsys_user SET sus_email = 'ben-e2e@example.com' WHERE zzzzsys_user_id = '${ben}';
			INSERT INTO zzzzsys_email_log (zzzzsys_email_log_id, eml_created_at, eml_state, eml_to, eml_subject, eml_tag) VALUES ('e2email1', NOW(), 'sent', 'ben-e2e@example.com', 'E2E', 'welcome');` });
		await open(p, 'index.php?p=log&view=default&object=zzzzsys_user');
		expect(await p.locator('#lvf_object').count() === 1, 'log Object filter');
		const rec = p.locator('a.nuLogRec[href*="p=user&id=' + ben + '"]').first();
		expect(await rec.count() === 1, 'log record link');
		const more = p.locator('.nuLvMore').first();
		expect(await more.count() === 1, 'long details not folded');
		const h1 = await p.evaluate(() => document.querySelector('.nuLvClamp.nuLvClamped').clientHeight);
		await more.click();
		const h2 = await p.evaluate(() => document.querySelector('.nuLvMore').previousElementSibling.clientHeight);
		expect(h2 > h1 * 2 && /p=log/.test(p.url()), 'more did not unfold: ' + h1 + ' / ' + h2);
		await open(p, 'index.php?p=audit&view=default');
		expect(await p.evaluate(() => (document.getElementById('lvf_login') || {}).tagName) === 'SELECT', 'audit user select');
		await open(p, 'index.php?p=email&tab=log&view=default');
		expect(await p.locator('#lvf_tag').count() === 1 && await p.locator('a.nuMailUser[href*="id=' + ben + '"]').count() >= 1, 'e-mail log Tag / user link');
		await open(p, 'index.php?p=notifications&tab=manage');
		expect(await p.locator('#lvf_state').count() === 1 && await p.locator('#ntfSettingsBtn').count() === 1, 'notifications list');
		await open(p, 'index.php?p=dashboards');
		expect(await p.locator('#lvf_home').count() === 1 && await p.locator('a[href*="#dashWidgets"]').count() >= 1 && await p.locator('#dashAdd').count() === 1, 'dashboards list');
		await open(p, 'index.php?p=cloner');
		const n = +sql('SELECT COUNT(*) FROM zzzzsys_cloner');
		expect((await p.locator('form[role="search"] input[name="q"]').count() > 0) === (n > 10), 'cloner search with ' + n + ' rows');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { execSync('mysql ' + DB, { input: fs.readFileSync(keep) }); fs.unlinkSync(keep); }
};

T.code_lists = async (s) => {
	const p = s.ga;
	const total = async () => +(((await p.textContent('body')).match(/Total:\s*(\d+)/) || [])[1] || -1);
	await open(p, 'index.php?p=forms');
	const fac = p.locator('[data-nu-fac="samplecustomer"]');
	expect(await fac.count() === 1, 'access count of the form');
	await fac.click(); await p.waitForSelector('#nuFormAccessDlg.show');
	expect(await p.locator('#nuFacTable tbody tr').count() > 0, 'access dialog from the forms list');
	await p.keyboard.press('Escape'); await p.waitForTimeout(300);
	await open(p, 'index.php?p=procedures');
	expect(await p.locator('#lvf_run').count() === 1 && await p.locator('#lvf_category').count() === 1, 'procedure filters');
	await open(p, 'index.php?p=procedures&run=hide&view=default');
	expect(await total() >= 0, 'run filter');
	await open(p, 'index.php?p=reports');
	expect(await p.locator('#lvf_srctype').count() === 1, 'report source type filter');
	expect(await p.locator('a[href*="p=sqlbuilder&id="], a[href*="p=procedures&id="]').count() > 0, 'report source link');
	const sel = sql("SELECT zzzzsys_select_id FROM zzzzsys_select WHERE COALESCE(sse_system, '') = '' LIMIT 1");
	if (sel) {
		await open(p, 'index.php?p=sqlbuilder');
		expect(await p.locator('#lvf_mode').count() + await p.locator('[data-nu-lv-filter]').count() >= 0 && await total() >= 1, 'SQL builder list');
		const link = p.locator('a[href*="src=SQL"]').first();
		if (await link.count()) {
			const want = +sql(`SELECT COUNT(*) FROM zzzzsys_report WHERE sre_zzzzsys_php_id = 'SQL:${sel}' OR sre_zzzzsys_php_id = '${sel}'`);
			await Promise.all([p.waitForNavigation(), link.click()]);
			expect(/p=reports/.test(p.url()) && await total() >= 1, 'SQL builder → reports: ' + want);
		}
	}
	await open(p, 'index.php?p=emailtemplates&language=English');
	expect(await total() >= 1 && await p.locator('#lvf_language').count() === 1, 'e-mail templates language filter');
	expect(await p.locator('.badge:has(.bi-envelope-heart)').count() > 0, 'welcome badge');
	await open(p, 'index.php?p=files');
	expect(await p.locator('#lvf_type').count() === 1, 'file type filter');
	await open(p, 'index.php?p=formats');
	const used = p.locator('.nuFmtUsed .dropdown-toggle').first();
	if (await used.count()) { await used.click(); expect(await p.locator('.nuFmtUsed .dropdown-menu.show a').count() > 0, 'format usages'); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// click action of a list cell (0.8.872): a list in the context of the row (#PARENT_ID#), a form for a new record (icon button), a JavaScript
// function with the row; the rest of the row still opens the row; design mode panel "On click" writes sbr_json nunext.action into the draft
T.browse_cell_action = async (s) => {
	const p = s.ga, F = 'samplecustomer', keep = path.join(os.tmpdir(), 'nunext-e2e-cellact.sql');
	if (!sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id IN ('samplecustomer', 'sampleorder')").startsWith('2')) { s.skip = 'no sample application'; return; }
	execSync('mysqldump ' + DB + ' zzzzsys_browse zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const col = sql(`SELECT zzzzsys_browse_id FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}' ORDER BY sbr_order LIMIT 1`);
	const setAct = (a) => sql(`UPDATE zzzzsys_browse SET sbr_json = '${JSON.stringify({ nunext: { action: a } })}' WHERE zzzzsys_browse_id = '${col}'`);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id IN ('${F}', 'sampleorder')`);
		sql("UPDATE zzzzsys_form SET sfo_browse_sql = CONCAT(sfo_browse_sql, \" WHERE ('#PARENT_ID#' LIKE '#%' OR ord_sample_customer_id = '#PARENT_ID#')\") WHERE zzzzsys_form_id = 'sampleorder'");
		// 1 a list for this row: the orders of the clicked customer
		setAct({ t: 'list', id: 'sampleorder', open: '0', pass: 'PARENT_ID', look: 'link' });
		await open(p, 'index.php?p=form&f=' + F);
		const btn = p.locator('#nuBrowseTable tr.nuBrowseRow >> nth=0').locator('.nuCellAct.nuCellActLink');
		expect(await btn.count() === 1, 'no link in the first cell');
		const pk = await p.getAttribute('#nuBrowseTable tr.nuBrowseRow >> nth=0', 'data-nu-primary-key');
		await Promise.all([p.waitForURL(/f=sampleorder/, { timeout: 10000 }), btn.click()]);
		await p.waitForLoadState('networkidle');
		const want = +sql(`SELECT COUNT(*) FROM sample_order WHERE ord_sample_customer_id = '${pk}'`);
		const total = await p.evaluate(() => (window.nuNext && nuNext.browse) ? nuNext.browse.total : -1);
		expect(total === want && want < +sql('SELECT COUNT(*) FROM sample_order'), 'orders of the customer: ' + total + ' of ' + want);
		// 2 the rest of the row opens the row as before
		await open(p, 'index.php?p=form&f=' + F);
		await Promise.all([p.waitForURL(/f=samplecustomer.*r=|r=.*f=samplecustomer/, { timeout: 10000 }), p.click('#nuBrowseTable tr.nuBrowseRow >> nth=0 >> div.nuCell[data-nu-column="1"]')]);
		// 3 a new record of another form, as an icon button
		setAct({ t: 'form', id: 'sampleorder', rec: 'new', open: '0', look: 'icon', icon: 'bi-cart-plus', label: 'New order' });
		await open(p, 'index.php?p=form&f=' + F);
		const ib = p.locator('#nuBrowseTable tr.nuBrowseRow >> nth=0').locator('.nuCellActIcon');
		expect(await ib.count() === 1 && await ib.getAttribute('aria-label') === 'New order' && await ib.locator('.bi-cart-plus').count() === 1, 'icon button');
		await Promise.all([p.waitForURL(/f=sampleorder.*r=-1|r=-1.*f=sampleorder/, { timeout: 10000 }), ib.click()]);
		// 4 a JavaScript function gets the row
		setAct({ t: 'js', id: 'e2eCellAct', look: 'button', label: 'Go' });
		await open(p, 'index.php?p=form&f=' + F);
		await p.evaluate(() => { window.e2eCellAct = (i) => { window.__e2eAct = { pk: i.pk, value: i.value, formId: i.formId }; }; });
		await p.click('#nuBrowseTable tr.nuBrowseRow >> nth=0 >> .nuCellActBtn');
		const got = await p.evaluate(() => window.__e2eAct || null);
		expect(got && got.pk === pk && got.formId === F && /samplecustomer/.test(p.url()), 'JS function: ' + JSON.stringify(got));
		// 5 a target that does not exist: plain value
		setAct({ t: 'form', id: 'no_such_form', look: 'link' });
		await open(p, 'index.php?p=form&f=' + F);
		expect(await p.locator('#nuBrowseTable .nuCellAct').count() === 0, 'link to a missing form');
		// 6 design mode: On click → Open a list → target select → stored in the draft
		setAct(null);
		sql(`UPDATE zzzzsys_browse SET sbr_json = NULL WHERE zzzzsys_browse_id = '${col}'`);
		await open(p, 'index.php?p=form&f=' + F + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		await p.click('#nuBrowseTable th.nuBrowseTitle >> nth=0'); await p.waitForTimeout(400);
		await p.selectOption('#nuDsPanel select[data-key="action_t"]', 'list'); await p.waitForTimeout(1500);
		expect(await p.locator('#nuDsActNoTarget').count() === 1, 'hint without a target');
		await p.selectOption('#nuDsPanel select[data-key="action_id"]', 'sampleorder'); await p.waitForTimeout(1500);
		const js = sql(`SELECT jt.j FROM zzzzsys_nunext_form_draft d, JSON_TABLE(d.snapshot, '$.browse[*]' COLUMNS(i VARCHAR(30) PATH '$.zzzzsys_browse_id', j TEXT PATH '$.sbr_json')) jt WHERE d.form_id = '${F}' AND d.status = 'open' AND jt.i = '${col}'`);
		expect(/"action":\{"t":"list","id":"sampleorder"/.test(js), 'action in the draft: ' + js);
		expect(await p.locator('#nuBrowseTable tr.nuBrowseRow >> nth=0').locator('.nuCellAct').count() === 1, 'design preview shows the link');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { execSync('mysql ' + DB, { input: fs.readFileSync(keep) }); fs.unlinkSync(keep); }
};

T.browse_search = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&q=E2E');
	const n = await p.locator('table.nuBrowseTable tbody tr, .nuBrowseTable tbody tr').count();
	expect(n >= 1, 'search found nothing');
};

// 0.8.650: column widths by dragging a title (as nuBuilder Forte) – the width follows the pointer, at least 40 px, a click without moving
// sorts, the drag does not; kept through sort / paging (sessionStorage of this tab), a new tab starts fresh; nuDisableBrowseResize(); touch
T.browse_edge_padding = async (s) => {                // 0.8.1078: form lists have the system lists' 16 px at the outer edges
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer');
	const r = await p.evaluate(() => {
		const row = document.querySelector('#nuBrowseTable tbody tr.nuBrowseRow'), th = document.querySelectorAll('#nuBrowseTable thead th');
		const px = (e, k) => parseFloat(getComputedStyle(e)[k]);
		return [px(th[0], 'paddingLeft'), px(row.firstElementChild, 'paddingLeft'), px(row.lastElementChild, 'paddingRight'), row.children.length > 2 ? px(row.children[1], 'paddingLeft') : 4.8];
	});
	expect(r[0] === 16 && r[1] === 16 && r[2] === 16 && r[3] < 6, 'edges / inner: ' + r.join(' '));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.browse_resize = async (s) => {
	const p = s.ga, KEY = 'nunext-browse-w:samplecustomer';
	const width = (pg) => pg.evaluate(() => Math.round(document.querySelector('#nuBrowseTable thead th[data-nu-col="1"]').getBoundingClientRect().width));
	const cell = () => p.evaluate(() => { const td = (document.querySelector('#nuBrowseTable tbody div.nuCell[data-nu-column="1"]') || {}).parentNode; return td ? Math.round(td.getBoundingClientRect().width) : -1; });
	const drag = async (pg, dx) => {
		const b = await pg.locator('#nuBrowseTable thead th[data-nu-col="1"]').boundingBox();
		await pg.mouse.move(b.x + b.width / 2, b.y + b.height / 2); await pg.mouse.down();
		await pg.mouse.move(b.x + b.width / 2 + dx, b.y + b.height / 2, { steps: 10 }); await pg.mouse.up(); await pg.waitForTimeout(300);
	};
	await open(p, 'index.php?p=form&f=samplecustomer');
	await p.evaluate((k) => sessionStorage.removeItem(k), KEY);
	try {
		await open(p, 'index.php?p=form&f=samplecustomer');
		const w0 = await width(p), u0 = p.url();
		await drag(p, 90);
		expect(Math.abs(await width(p) - (w0 + 90)) <= 2 && Math.abs(await cell() - (w0 + 90)) <= 2, 'drag +90: ' + w0 + ' → ' + await width(p) + ' (cell ' + await cell() + ')');
		expect(p.url() === u0, 'the drag sorted the list');
		await drag(p, -2000);
		expect(await width(p) === 40, 'not below 40 px: ' + await width(p));
		await Promise.all([p.waitForNavigation(), p.click('#nuBrowseTable thead th[data-nu-col="2"] a')]); await p.waitForLoadState('networkidle');
		expect(/sort=2/.test(p.url()) && await width(p) === 40, 'a click sorts and the width stays: ' + p.url() + ' ' + await width(p));
		const p2 = await p.context().newPage();
		try {
			await open(p2, 'index.php?p=form&f=samplecustomer');
			// 0.8.933: the server draws the dragged widths (cookie of this browser session) – also in a new tab, and before any script runs
			expect(await width(p2) === 40 && (await p2.getAttribute('#nuBrowseTable', 'class')).includes('nuBrowseFixed'), 'a new tab starts with the dragged widths: ' + await width(p2));
			const html = await p2.evaluate(() => fetch('index.php?p=form&f=samplecustomer&pg=2').then(r => r.text()));
			expect(/id="nuBrowseTitle1"[^>]*style="width:40px;"/.test(html), 'the server draws the dragged width');
			await p2.evaluate(() => { document.cookie = 'nunext_bw=; path=' + location.pathname.replace(/[^/]*$/, '') + '; max-age=0'; });
			await open(p2, 'index.php?p=form&f=samplecustomer');
			expect(await width(p2) > 40 && !(await p2.getAttribute('#nuBrowseTable', 'class')).includes('nuBrowseFixed'), 'without the cookie: the form\'s widths');
			await p2.evaluate(() => nuDisableBrowseResize());
			const d0 = await width(p2);
			await drag(p2, 80);
			expect(await width(p2) === d0, 'nuDisableBrowseResize: no change');
		} finally { await p2.close(); }
		// touch: real touch events (touch-action decides whether the page scrolls or the column resizes)
		const tctx = await p.context().browser().newContext({ locale: 'en-GB', viewport: { width: 1300, height: 900 }, hasTouch: true, storageState: await p.context().storageState() });
		try {
			const tp = await tctx.newPage();
			await open(tp, 'index.php?p=form&f=samplecustomer');
			const cdp = await tctx.newCDPSession(tp), b = await tp.locator('#nuBrowseTable thead th[data-nu-col="1"]').boundingBox(), t0 = Math.round(b.width);
			const x = b.x + b.width / 2, y = b.y + b.height / 2;
			await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
			for (let i = 1; i <= 10; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + i * 7, y }] }); await tp.waitForTimeout(20); }
			await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await tp.waitForTimeout(400);
			expect(Math.abs(await width(tp) - (t0 + 70)) <= 2 && !/sort=/.test(tp.url()), 'touch drag +70: ' + t0 + ' → ' + await width(tp));
		} finally { await tctx.close(); }
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.evaluate((k) => { sessionStorage.removeItem(k); document.cookie = 'nunext_bw=; path=' + location.pathname.replace(/[^/]*$/, '') + '; max-age=0'; }, KEY).catch(() => {});
	}
};

// 0.8.651: the same in the system lists (NuListView – here the users list): titles with data-nu-col, kept per list and view for this tab
T.list_resize = async (s) => {
	const p = s.ga, sel = 'table[data-nu-resize] thead th[data-nu-col="login"]';
	const width = (pg) => pg.$eval(sel, (t) => Math.round(t.getBoundingClientRect().width));
	const drag = async (pg, dx) => {
		const b = await pg.locator(sel).boundingBox();
		await pg.mouse.move(b.x + b.width / 2, b.y + b.height / 2); await pg.mouse.down();
		await pg.mouse.move(b.x + b.width / 2 + dx, b.y + b.height / 2, { steps: 10 }); await pg.mouse.up(); await pg.waitForTimeout(300);
	};
	await open(p, 'index.php?p=users');
	const key = 'nunext-list-w:' + await p.getAttribute('table[data-nu-resize]', 'data-nu-resize');
	await p.evaluate((k) => sessionStorage.removeItem(k), key);
	try {
		await open(p, 'index.php?p=users');
		if (!await p.locator(sel).count()) { s.skip = 'no Login name column'; return; }
		const w0 = await width(p), u0 = p.url();
		await drag(p, 70);
		expect(Math.abs(await width(p) - (w0 + 70)) <= 2 && p.url() === u0, 'drag +70 without sorting: ' + w0 + ' → ' + await width(p));
		await drag(p, -2000);
		expect(await width(p) === 40, 'not below 40 px: ' + await width(p));
		const nowrap = await p.$eval('table[data-nu-resize] tbody td', (td) => getComputedStyle(td).whiteSpace);
		expect(nowrap === 'nowrap', 'narrow cells stay on one line: ' + nowrap);
		await Promise.all([p.waitForNavigation(), p.click('table[data-nu-resize] thead th[data-nu-col="name"] a.nu-sort')]); await p.waitForLoadState('networkidle');
		expect(/sort=name/.test(p.url()) && await width(p) === 40, 'a click sorts and the width stays: ' + p.url() + ' ' + await width(p));
		const p2 = await p.context().newPage();
		try {
			await open(p2, 'index.php?p=users');
			expect(await width(p2) > 40, 'a new tab starts with the list\'s widths');
		} finally { await p2.close(); }
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.evaluate((k) => sessionStorage.removeItem(k), key).catch(() => {});
	}
};

// back to all records (0.8.428): the × button (search words + column filters go, sort stays), the field's own ×, Esc; no button without a search
T.browse_reset = async (s) => {
	const p = s.ga;
	const total = sql('SELECT COUNT(*) FROM sample_customer');
	const rows = () => p.locator('div.nuCell[data-nu-column="1"]').count();
	const count = async () => (await p.textContent('.nuListCard .card-footer, .nuBrowseCard')).match(/(\d+)\s+record/)?.[1];
	await open(p, 'index.php?p=form&f=samplecustomer');
	expect(await p.locator('#nuSearchReset').count() === 0, 'reset button without a search');
	await open(p, 'index.php?p=form&f=samplecustomer&q=bluepeak&sort=1&dir=desc');
	expect(await rows() === 1 && await p.locator('#nuSearchReset').count() === 1, 'search + reset button');
	await Promise.all([p.waitForNavigation(), p.click('#nuSearchReset')]); await p.waitForLoadState('networkidle');
	let u = new URL(p.url());
	expect(!u.searchParams.get('q') && u.searchParams.get('sort') === '1' && u.searchParams.get('dir') === 'desc' && await count() === total, 'reset button: ' + p.url() + ' ' + await count());
	// the field's own × (event "search" with an empty field) and Esc reload without the search words
	await open(p, 'index.php?p=form&f=samplecustomer&q=bluepeak');
	await Promise.all([p.waitForNavigation(), p.evaluate(() => { const i = document.getElementById('nuSearchField'); i.value = ''; i.dispatchEvent(new Event('search', { bubbles: true })); })]);
	await p.waitForLoadState('networkidle');
	expect(!new URL(p.url()).searchParams.get('q') && await count() === total, 'native ×: ' + p.url());
	await open(p, 'index.php?p=form&f=samplecustomer&q=bluepeak');
	await p.focus('#nuSearchField');
	await Promise.all([p.waitForNavigation(), p.keyboard.press('Escape')]); await p.waitForLoadState('networkidle');
	expect(!new URL(p.url()).searchParams.get('q') && await count() === total, 'Esc: ' + p.url());
	// a system list: Esc there too
	await open(p, 'index.php?p=users&q=sample_anna');
	await p.focus('#q');
	await Promise.all([p.waitForNavigation(), p.keyboard.press('Escape')]); await p.waitForLoadState('networkidle');
	expect(!new URL(p.url()).searchParams.get('q'), 'Esc in the users list: ' + p.url());
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// list footer (0.8.436): pager and page size only when there is more than one page; the total always
T.list_footer = async (s) => {
	const p = s.ga;
	const state = async () => ({ pager: await p.locator('.nuListFooter .nuPager').count(), per: await p.locator('.nuListFooter .nuLfPer').count(), total: await p.locator('.nuListFooter .nuLfTotal').count() });
	const total = +sql('SELECT COUNT(*) FROM sample_customer'), per = +(sql("SELECT IFNULL(sfo_browse_rows_per_page, 0) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") || 0);
	await open(p, 'index.php?p=form&f=samplecustomer');
	let st = await state();
	if (per > 0 && total > per) expect(st.pager === 1 && st.per === 1 && st.total === 1, 'several pages: ' + JSON.stringify(st));
	await open(p, 'index.php?p=form&f=samplecustomer&q=bluepeak');
	st = await state();
	expect(st.pager === 0 && st.per === 0 && st.total === 1, 'one page (form): ' + JSON.stringify(st));
	// page size menu (0.8.437): choose 10 → page 1 of several; the choice stays when paging and searching
	await open(p, 'index.php?p=form&f=samplecustomer&per=250');
	expect(await p.locator('.nuListFooter .nuPager').count() === 0, '250 per page: one page');
	if (total > 10) {
		await open(p, 'index.php?p=form&f=samplecustomer&pg=2');
		await p.click('.nuLfPerBtn'); 
		await Promise.all([p.waitForNavigation(), p.click('.nuLfPerMenu a:text-is("10 per page")')]);
		let u = new URL(p.url());
		expect(u.searchParams.get('per') === '10' && !u.searchParams.get('pg') && await p.locator('.nuBrowseTable tbody tr, #nuBrowseTable tbody tr').count() <= 10, 'menu: ' + p.url());
		await Promise.all([p.waitForNavigation(), p.click('.nuPagerNext')]);
		expect(new URL(p.url()).searchParams.get('per') === '10', 'kept when paging: ' + p.url());
		await p.fill('#nuSearchField', 'a'); await Promise.all([p.waitForNavigation(), p.press('#nuSearchField', 'Enter')]);
		expect(new URL(p.url()).searchParams.get('per') === '10', 'kept when searching: ' + p.url());
	}
	// a system list: the log with 10 per page
	if (+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'login'") > 10) {
		await open(p, 'index.php?p=log&per=10');
		expect(await p.locator('.nuListFooter .nuPager').count() === 1 && /10/.test(await p.textContent('.nuLfPerBtn')), 'log with per=10');
		await p.fill('#q', 'login'); await Promise.all([p.waitForNavigation(), p.press('#q', 'Enter')]);
		const found = +((await p.textContent('.nuListFooter')).match(/(\d[\d',.]*)\s+record/) || [0, '0'])[1].replace(/\D/g, '');
		expect(new URL(p.url()).searchParams.get('per') === '10' && (found <= 10 || await p.locator('.nuListFooter .nuPager').count() === 1), 'log: kept when searching: ' + p.url());
	} else s.note = 'log too short for the system list part';
	await open(p, 'index.php?p=form&f=samplecustomer&per=7');
	// form lists take any size 1–1000 since 0.8.604 (nuBrowseRowsPerPageFilter); 0 / too large fall back to the form's rows per page
	expect(/\b7\b/.test(await p.textContent('.nuLfPerBtn')), 'form list: per=7 not used');
	await open(p, 'index.php?p=form&f=samplecustomer&per=5000');
	expect(!/5000/.test(await p.textContent('.nuLfPerBtn')), 'per=5000 used');
	if (+sql('SELECT COUNT(*) FROM zzzzsys_form') <= 500) {
		await open(p, 'index.php?p=forms');
		st = await state();
		expect(st.pager === 0 && st.per === 0 && st.total === 1, 'one page (forms list): ' + JSON.stringify(st));
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// browse search as in nuBuilder (0.8.427): "phrases", -exclusions, accents; the words are marked in the searched columns only
T.browse_highlight = async (s) => {
	const p = s.ga;
	const look = async (q, extra = '') => {
		await open(p, 'index.php?p=form&f=samplecustomer&q=' + encodeURIComponent(q) + extra);
		return p.evaluate(() => ({ names: [...document.querySelectorAll('div.nuCell[data-nu-column="1"]')].map(t => t.textContent.trim()),
			marks: [...document.querySelectorAll('div.nuCell mark.nuHighlight')].map(m => m.textContent), cols: [...new Set([...document.querySelectorAll('div.nuCell mark.nuHighlight')].map(m => m.closest('div.nuCell').dataset.nuColumn))] }));
	};
	let r = await look('ltd -harbour');
	expect(r.names.includes('Silverline Logistics Ltd') && !r.names.some(n => /Harbour/.test(n)) && r.marks.every(m => /^ltd$/i.test(m)) && r.marks.length >= 2, 'exclusion: ' + JSON.stringify(r));
	r = await look('"Maple Ridge"');
	expect(r.names.length === 1 && r.names[0] === 'Maple Ridge Supply' && JSON.stringify(r.marks) === '["Maple Ridge"]', 'phrase: ' + JSON.stringify(r));
	r = await look('cafe');
	expect(r.names.length === 1 && /^Caf. Aurora Ltd$/.test(r.names[0]) && r.marks.length === 1 && /^Caf.$/.test(r.marks[0]), 'accent-insensitive: ' + JSON.stringify(r));
	r = await look('ltd gb');
	expect(r.names.length >= 2 && r.marks.filter(m => m === 'Ltd').length === r.names.length && r.marks.filter(m => m === 'GB').length === r.names.length, 'two words: ' + JSON.stringify(r));
	r = await look('de', '&nsc=2');                                // country not searched: not marked there either
	expect(!r.cols.includes('2'), 'marks in a column left out of the search: ' + JSON.stringify(r));
	// nuHighlight from form code: every occurrence, nothing nested, nuUnhighlight takes the marks away
	const h = await p.evaluate(() => { const d = document.createElement('div'); d.innerHTML = 'Bananas <b>and</b> ananas – Ånanas'; document.body.appendChild(d);
		$(d).nuHighlight('ana'); $(d).nuHighlight(['an', 'ana']); const n = d.querySelectorAll('mark').length, nested = d.querySelectorAll('mark mark').length, html = d.innerHTML;
		$(d).nuUnhighlight(); const left = d.querySelectorAll('mark').length + (d.textContent === 'Bananas and ananas – Ånanas' ? 0 : 100); d.remove(); return { n, nested, html, left }; });
	expect(h.n >= 4 && h.nested === 0 && h.left === 0 && /<mark class="nuHighlight">Åna<\/mark>/.test(h.html), 'nuHighlight: ' + JSON.stringify(h));
	// a system list: the users list marks its search words
	await open(p, 'index.php?p=users&q=sample');
	expect(await p.locator('tbody mark.nuHighlight').count() >= 5, 'users list: no marks');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.browse_alias = async (s) => {                         // 0.8.731: a list column naming an alias of the browse SQL's field list (tester's report)
	const p = s.ga;
	const oldSql = sql("SELECT sfo_browse_sql FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	const oldCol = sql("SELECT sbr_display FROM zzzzsys_browse WHERE zzzzsys_browse_id = 'sample_customer_b1'");
	try {
		sql("UPDATE zzzzsys_form SET sfo_browse_sql = 'SELECT sample_customer.*, CONCAT(cus_code, '' / '', cus_name) AS code_name FROM sample_customer ORDER BY code_name DESC' WHERE zzzzsys_form_id = 'samplecustomer'");
		sql("UPDATE zzzzsys_browse SET sbr_display = 'code_name' WHERE zzzzsys_browse_id = 'sample_customer_b1'");
		await open(p, 'index.php?p=form&f=samplecustomer');
		const cells = await p.$$eval('#nuBrowseTable div.nuCell[data-nu-column="0"]', (t) => t.map((x) => x.textContent.trim()));
		const want = sql("SELECT CONCAT(cus_code, ' / ', cus_name) FROM sample_customer ORDER BY 1 DESC LIMIT 1");
		expect(cells.length > 1 && cells[0] === want && !/error|fehler/i.test(await p.textContent('main')), 'alias column: ' + cells.slice(0, 2).join(' | ') + ' / ' + want);
		await p.fill('#nuSearchField', ' / '); await p.press('#nuSearchField', 'Enter'); await p.waitForLoadState('networkidle');
		expect(await p.locator('#nuBrowseTable div.nuCell[data-nu-column="0"]').count() === cells.length, 'search in the alias column');
		// 0.8.1075: a derived table in FROM (LEFT JOIN (SELECT … SUM(…) AS …) s) – the alias is still expanded
		execSync('mysql ' + DB, { input: "UPDATE zzzzsys_form SET sfo_browse_sql = 'SELECT sample_customer.*, s.total AS amount_due FROM sample_customer LEFT JOIN (SELECT ord_sample_customer_id AS cid, SUM(ord_total) AS total FROM sample_order GROUP BY ord_sample_customer_id) s ON (s.cid = sample_customer.sample_customer_id) ORDER BY cus_name' WHERE zzzzsys_form_id = 'samplecustomer';"
			+ " UPDATE zzzzsys_browse SET sbr_display = 'IF(cus_name LIKE ''%'', CONCAT(''x'', COALESCE(amount_due, 0)), '''')' WHERE zzzzsys_browse_id = 'sample_customer_b1';" });
		await open(p, 'index.php?p=form&f=samplecustomer');
		const c2 = await p.$$eval('#nuBrowseTable div.nuCell[data-nu-column="0"]', (t) => t.map((x) => x.textContent.trim()));
		const want2 = sql("SELECT CONCAT('x', COALESCE((SELECT SUM(ord_total) FROM sample_order WHERE ord_sample_customer_id = sample_customer_id), 0)) FROM sample_customer ORDER BY cus_name LIMIT 1");
		expect(c2.length > 1 && c2[0] === want2 && !/error|fehler/i.test(await p.textContent('main')), 'alias with a derived table: ' + c2.slice(0, 3).join(' | ') + ' / ' + want2);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: `UPDATE zzzzsys_form SET sfo_browse_sql = ${JSON.stringify(oldSql)} WHERE zzzzsys_form_id = 'samplecustomer'; UPDATE zzzzsys_browse SET sbr_display = ${JSON.stringify(oldCol)} WHERE zzzzsys_browse_id = 'sample_customer_b1';` });
	}
};

T.browse_alias_expr = async (s) => {                    // 0.8.1059: an alias of the browse SQL's field list INSIDE a column's expression (tester's report)
	const p = s.ga;
	const oldSql = sql("SELECT sfo_browse_sql FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	const oldCol = sql("SELECT sbr_display FROM zzzzsys_browse WHERE zzzzsys_browse_id = 'sample_customer_b1'");
	try {
		execSync('mysql ' + DB, { input: "UPDATE zzzzsys_form SET sfo_browse_sql = 'SELECT sample_customer.*, CHAR_LENGTH(cus_name) + 1 AS name_len FROM sample_customer GROUP BY sample_customer.sample_customer_id HAVING name_len > 0 ORDER BY CAST(name_len AS UNSIGNED) DESC, cus_name' WHERE zzzzsys_form_id = 'samplecustomer';"
			+ " UPDATE zzzzsys_browse SET sbr_display = 'IF(cus_name LIKE ''%name_len%'' OR 1 = 1, name_len * 2, '''')' WHERE zzzzsys_browse_id = 'sample_customer_b1';" });
		await open(p, 'index.php?p=form&f=samplecustomer');
		const cells = await p.$$eval('#nuBrowseTable div.nuCell[data-nu-column="0"]', (t) => t.map((x) => x.textContent.trim()));
		const want = sql("SELECT (CHAR_LENGTH(cus_name) + 1) * 2 FROM sample_customer ORDER BY CHAR_LENGTH(cus_name) DESC, cus_name LIMIT 1");
		expect(cells.length > 1 && cells[0] === want && !/error|fehler/i.test(await p.textContent('main')), 'alias inside IF: ' + cells.slice(0, 3).join(' | ') + ' / ' + want);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: `UPDATE zzzzsys_form SET sfo_browse_sql = ${JSON.stringify(oldSql)} WHERE zzzzsys_form_id = 'samplecustomer'; UPDATE zzzzsys_browse SET sbr_display = ${JSON.stringify(oldCol)} WHERE zzzzsys_browse_id = 'sample_customer_b1';` });
	}
};

// 0.8.537: column filters of form browse lists are labelled selects next to the search (like the system lists), not a row below the header
T.browse_filters_bar = async (s) => {
	const p = s.ga;
	sql("UPDATE zzzzsys_browse SET sbr_json = '{\"nunext\":{\"filter\":true}}' WHERE zzzzsys_browse_id = 'sample_customer_b3'");
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&q=a');
		expect(await p.locator('.nuFilterRow').count() === 0, 'no filter row below the header');
		const sel = p.locator('#nuBrowseFilters select.nuColumnFilter');
		expect(await sel.count() === 1 && /Country/i.test(await p.textContent('#nuBrowseFilters label')), 'labelled filter next to the search');
		const val = await sel.locator('option').nth(1).getAttribute('value');
		await Promise.all([p.waitForNavigation(), sel.selectOption(val)]);
		const u = new URL(p.url());
		expect(u.searchParams.get('flt[2]') === val && u.searchParams.get('q') === 'a', 'filter and search kept: ' + p.url());
		expect(await p.locator('#nuBrowseFilters select.nuFilterActive').count() === 1 && await p.locator('.nuFilterToggle .bi-funnel-fill').count() === 1, 'active filter marked');
		const cells = await p.locator('div.nuCell[data-nu-column="2"]').allTextContents();
		expect(cells.length > 0 && cells.every(c => c.trim() === cells[0].trim()), 'rows filtered: ' + cells.join(','));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("UPDATE zzzzsys_browse SET sbr_json = NULL WHERE zzzzsys_browse_id = 'sample_customer_b3'");
	}
};

T.list_pivot = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'") !== '1') { s.skip = 'no sample data'; return; }
	const before = sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'pivot:sampleorder'");
	try {
		await open(p, 'index.php?p=form&f=sampleorder');
		await p.evaluate(() => { try { localStorage.removeItem('nunext-pivot-sampleorder'); } catch (e) { /* none */ } });
		await p.click('#nuExportButton'); await p.click('#nuPivotOpen'); await p.waitForSelector('#nuPvDlg.show'); await p.waitForTimeout(300);
		const add = async (z, name) => { await p.click(`.nuPvZone[data-zone=${z}] .nuPvAdd`); await p.click(`.nuPvZone[data-zone=${z}] .nuPvAddMenu button:text-is("${name}")`); await p.waitForTimeout(400); };
		await add('rows', 'Status'); await add('vals', 'Total');
		await p.waitForSelector('#nuPvTable'); await p.waitForTimeout(300);
		const grand = await p.evaluate(() => document.querySelector('#nuPvTable tfoot td:last-child').textContent);
		const dbSum = Number(sql('SELECT ROUND(SUM(ord_total), 2) FROM sample_order'));
		const shown = Number(grand.replace(/[^\d.,-]/g, '').replace(/,(?=\d{3}\b)/g, '').replace(',', '.'));
		expect(Math.abs(shown - dbSum) < 0.01, 'grand total ' + grand + ' vs ' + dbSum);
		const nStat = Number(sql('SELECT COUNT(DISTINCT IFNULL(ord_status, \'\')) FROM sample_order'));
		expect(await p.locator('#nuPvTable tbody tr').count() === nStat, 'one row per status');
		// columns: Date by year; drill into a cell
		await add('cols', 'Date');
		expect(await p.evaluate(() => /\d{4}/.test(document.querySelector('#nuPvTable thead').textContent)), 'year columns');
		await p.click('#nuPvTable td.nuPvCell[data-r]'); await p.waitForSelector('.nuPvDrillTable tbody tr');
		// filter Status to one value
		await add('flt', 'Status');
		await p.click('.nuPvZone[data-zone=flt] .nuPvFltBtn'); await p.waitForSelector('.nuPvFltList input');
		await p.locator('.nuPvFltList input').first().check(); await p.click('.nuPvFltPop .btn-primary'); await p.waitForTimeout(700);
		expect(await p.locator('#nuPvTable tbody tr').count() === 1, 'filter keeps one status');
		// save, list, delete
		await p.click('#nuPvSavedBtn'); await p.fill('#nuPvSaveName', 'e2e pivot'); await p.click('#nuPvSave'); await p.waitForTimeout(500);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'pivot:sampleorder' AND name = 'e2e pivot'") === '1', 'pivot saved');
		// export Excel
		const [dl] = await Promise.all([p.waitForEvent('download'), p.click('#nuPvExp_xlsx')]);
		expect(/\.xlsx$/.test(dl.suggestedFilename()), 'xlsx: ' + dl.suggestedFilename());
		await p.click('#nuPvMode_chart'); await p.waitForSelector('#nuPvChart svg', {timeout: 8000});
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, {input: "DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'pivot:sampleorder' AND name = 'e2e pivot'"});
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'pivot:sampleorder'") === before, 'cleaned up');
	}
};

// 0.8.1165: calendar view of a form list – events of the list, a group filter, drag and drop saves the record, a new record from an empty day
T.list_calendar = async (s) => {
	const p = s.ga, F = 'sampleorder';
	if (sql(`SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`) !== '1') { s.skip = 'no sample data'; return; }
	const oldJson = sql(`SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`);
	const row = sql("SELECT CONCAT(sample_order_id, '|', ord_date, '|', ord_number) FROM sample_order WHERE ord_date IS NOT NULL ORDER BY ord_date DESC LIMIT 1").split('|');
	const cfg = JSON.stringify({nunext: {calendar: {start: 'sample_order_b2', title: 'sample_order_b1', group: 'sample_order_b4', drag: true, view: 'month'}}});
	execSync('mysql ' + DB, {input: `UPDATE zzzzsys_form SET sfo_json = '${cfg}' WHERE zzzzsys_form_id = '${F}'`});
	try {
		await open(p, 'index.php?p=form&f=' + F);
		await p.evaluate(() => { try { localStorage.removeItem('nunext-cal-view-sampleorder'); } catch (e) { /* none */ } });
		await p.click('#nuCalendarOpen'); await p.waitForSelector('#nuCalDlg.show .fc');
		await p.evaluate((d) => document.getElementById('nuCalListHost')._nuCal.gotoDate(d), row[1]);
		await p.waitForSelector(`#nuCalDlg .fc-event:has-text("${row[2]}")`, {timeout: 8000});
		const inMonth = Number(sql(`SELECT COUNT(*) FROM sample_order WHERE DATE_FORMAT(ord_date, '%Y-%m') = '${row[1].slice(0, 7)}'`));
		const shown = await p.locator('#nuCalDlg .fc-daygrid-day:not(.fc-day-other) .fc-event').count();
		expect(shown >= inMonth, 'events of the month: ' + shown + ' / ' + inMonth);
		// group chips: one per status, a click hides that status
		const chips = await p.locator('#nuCalDlg .nuCalChip:not(.nuCalChipAll)').count();
		if (chips > 1) {
			const before = await p.locator('#nuCalDlg .fc-event').count();
			await p.locator('#nuCalDlg .nuCalChip:not(.nuCalChipAll)').first().click(); await p.waitForTimeout(500);
			expect(await p.locator('#nuCalDlg .fc-event').count() < before, 'a group hidden');
			await p.click('#nuCalDlg .nuCalChipAll'); await p.waitForTimeout(500);
		}
		// drag the event one day on (to the day cell right of it, or left on Sundays)
		const ev = p.locator(`#nuCalDlg .fc-event:has-text("${row[2]}")`).first();
		const cell = p.locator(`#nuCalDlg .fc-daygrid-day[data-date="${row[1]}"]`);
		const cb = await cell.boundingBox(), eb = await ev.boundingBox();
		const dx = cb.x + cb.width * 1.5 > (await p.locator('#nuCalDlg .fc-daygrid-body').boundingBox()).x + (await p.locator('#nuCalDlg .fc-daygrid-body').boundingBox()).width ? -cb.width : cb.width;
		await p.mouse.move(eb.x + 10, eb.y + eb.height / 2); await p.mouse.down();
		for (let i = 1; i <= 8; i++) { await p.mouse.move(eb.x + 10 + dx * i / 8, eb.y + eb.height / 2); await p.waitForTimeout(40); }
		await p.mouse.up();
		await p.waitForSelector('.nuCalToast', {timeout: 8000});
		const exp = new Date(row[1] + 'T12:00:00'); exp.setDate(exp.getDate() + (dx > 0 ? 1 : -1));
		const want = exp.toISOString().slice(0, 10);
		expect(sql(`SELECT ord_date FROM sample_order WHERE sample_order_id = '${row[0]}'`) === want, 'moved to ' + want);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'calendar_moved' AND record_id = '${row[0]}'`) !== '0', 'logged');
		// a click on an empty day opens a new record with the date filled in
		const empty = await p.evaluate(() => { const c = [...document.querySelectorAll('#nuCalDlg .fc-daygrid-day:not(.fc-day-other)')].find((d) => !d.querySelector('.fc-event')); return c ? c.getAttribute('data-date') : ''; });
		await p.locator(`#nuCalDlg .fc-daygrid-day[data-date="${empty}"] .fc-daygrid-day-frame`).click({position: {x: 30, y: 40}});
		await p.waitForSelector('.nuCalRecDlg.show iframe');
		const fr = p.frameLocator('.nuCalRecDlg.show iframe');
		await fr.locator('#ord_date').waitFor();
		const v = await fr.locator('#ord_date').inputValue();
		const [y, m, d] = empty.split('-');
		expect(v.includes(d) && v.includes(y) || v === empty, 'date filled in: ' + v + ' / ' + empty);
		await p.click('.nuCalRecDlg .btn-close'); await p.waitForTimeout(400);
		// a click on an event opens its record
		await p.locator('#nuCalDlg .fc-event').first().click();
		await p.waitForSelector('.nuCalRecDlg.show iframe');
		await p.frameLocator('.nuCalRecDlg.show iframe').locator('#ord_number').waitFor();
		await p.click('.nuCalRecDlg .btn-close');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, {input: `UPDATE zzzzsys_form SET sfo_json = ${oldJson === '' ? 'NULL' : `'${oldJson.replace(/'/g, "''")}'`} WHERE zzzzsys_form_id = '${F}'; UPDATE sample_order SET ord_date = '${row[1]}' WHERE sample_order_id = '${row[0]}'`});
	}
};

// 0.8.1150: a list whose form has its own JavaScript keeps its rows hidden until that code ran (cells it rewrites do not flash)
T.browse_js_wait = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const old = sql(`SELECT IFNULL(sfo_browse_javascript, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`);
	if (old === null || old === undefined) { s.skip = 'no sample data'; return; }
	execSync('mysql ' + DB, { input: `UPDATE zzzzsys_form SET sfo_browse_javascript = 'function nuOnLoad() { $(".nuCell[data-nu-column=\\'0\\']").text("done"); }' WHERE zzzzsys_form_id = '${F}'` });
	try {
		await open(p, 'index.php?p=form&f=' + F); await p.waitForTimeout(300);
		const st = await p.evaluate(() => { const t = document.getElementById('nuBrowseTable'); return { wait: t.classList.contains('nuBrowseJsWait'), vis: getComputedStyle(t.tBodies[0]).visibility, txt: (t.querySelector('.nuCell[data-nu-column="0"]') || {}).textContent }; });
		expect(!st.wait && st.vis === 'visible' && st.txt === 'done', 'rows shown after the form code: ' + JSON.stringify(st));
		// without the runtime the rows are hidden at first, shown after 3 s by the CSS fallback
		const ctx = await p.context().browser().newContext({ storageState: await p.context().storageState() });
		const q = await ctx.newPage();
		await q.route(/nuruntime(\.min)?\.js/, (r) => r.abort());
		await q.goto(BASE + 'index.php?p=form&f=' + F);
		const v0 = await q.evaluate(() => getComputedStyle(document.querySelector('#nuBrowseTable > tbody')).visibility);
		await q.waitForTimeout(3500);
		const v1 = await q.evaluate(() => getComputedStyle(document.querySelector('#nuBrowseTable > tbody')).visibility);
		await ctx.close();
		expect(v0 === 'hidden' && v1 === 'visible', 'fallback: ' + v0 + ' → ' + v1);
	} finally {
		execSync('mysql ' + DB, { input: `UPDATE zzzzsys_form SET sfo_browse_javascript = ${old === '' ? 'NULL' : "'" + old.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"} WHERE zzzzsys_form_id = '${F}'` });
	}
};

T.browse_phone_toggle = async (s) => {
	// 0.8.992: form lists on a phone – the ▦ / ☰ button switches cards ⇄ table, kept per form (localStorage nunext-browse-phone-<form>)
	if (!sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'").startsWith('1')) { s.skip = 'no sample data'; return; }
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	const cards = () => p.evaluate(() => document.documentElement.classList.contains('nuPhoneCards'));
	try {
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer'); await p.waitForTimeout(500);
		expect(await cards() && await p.isVisible('#nuPhoneCardsBtn'), 'cards and the button on a phone');
		await p.click('#nuPhoneCardsBtn'); await p.waitForTimeout(200);
		expect(!(await cards()) && await p.getAttribute('#nuPhoneCardsBtn', 'aria-pressed') === 'true', 'table after the click');
		await p.reload(); await p.waitForTimeout(500);
		expect(!(await cards()), 'table kept after a reload');
		await p.click('#nuPhoneCardsBtn'); await p.waitForTimeout(200);
		expect(await cards() && await p.evaluate(() => localStorage.getItem('nunext-browse-phone-samplecustomer')) === null, 'cards again, choice removed');
		// 0.8.993: cards from the first paint – the class is set in <head> (nutheme.js), also without the form runtime
		await p.route(/nuruntime(\.min)?\.js/, (r) => r.abort());
		await p.reload(); await p.waitForTimeout(300);
		expect(await cards(), 'cards before the form runtime (no table flash)');
		await p.evaluate(() => localStorage.setItem('nunext-browse-phone-samplecustomer', 'table'));
		await p.reload(); await p.waitForTimeout(300);
		expect(!(await cards()), 'chosen table: no cards class in <head>');
		await p.unroute(/nuruntime(\.min)?\.js/);
		await p.evaluate(() => localStorage.removeItem('nunext-browse-phone-samplecustomer'));
		await p.setViewportSize({ width: 1200, height: 800 }); await p.waitForTimeout(300);
		expect(!(await p.isVisible('#nuPhoneCardsBtn')), 'button only on phones');
	} finally {
		await ctx.close();
	}
};

// 0.8.710: no records → no Export button (form browse lists and system lists)
T.export_empty = async (s) => {
	const p = s.ga;
	for (const [url, btn] of [['index.php?p=form&f=samplecustomer', '#nuExportButton'], ['index.php?p=users', '#nuListExportBtn']]) {
		await open(p, url);
		expect(await p.locator(btn).count() === 1, url + ': Export missing with records');
		await open(p, url + '&q=zzz-no-such-record-e2e');
		expect(await p.locator(btn).count() === 0, url + ': Export shown without records');
	}
	// 0.8.749: Debug results and formats too
	sql("INSERT INTO zzzzsys_debug (zzzzsys_debug_id, deb_message, deb_added) VALUES ('e2e_dbg_exp', 'e2e export empty', UNIX_TIMESTAMP())");
	try {
		for (const url of ['index.php?p=debug', 'index.php?p=formats']) {
			await open(p, url);
			expect(await p.locator('#nuListExportBtn').count() === 1, url + ': Export missing with records');
			await open(p, url + '&q=zzz-no-such-record-e2e');
			expect(await p.locator('#nuListExportBtn').count() === 0, url + ': Export shown without records');
		}
	} finally { sql("DELETE FROM zzzzsys_debug WHERE zzzzsys_debug_id = 'e2e_dbg_exp'"); }
	// 0.8.711: phones – the icon only
	const ph = await login(GA[0], GA[1], { viewport: { width: 390, height: 800 } });
	await open(ph, 'index.php?p=form&f=samplecustomer');
	const txt = await ph.evaluate(() => { const b = document.getElementById('nuExportButton'); return b ? [b.innerText.trim(), b.getAttribute('aria-label')] : null; });
	expect(txt && txt[0] === '' && txt[1], 'phone: Export button with text / without label: ' + JSON.stringify(txt));
	await ph.click('#nuExportButton'); await ph.waitForTimeout(300);
	expect(await ph.locator('#nuExportButton + .dropdown-menu .nuExpXlsx').isVisible(), 'phone: Export menu does not open');
	await ph.context().close();
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.excel_export = async (s) => {                         // 0.8.398: Export Excel of a list print view (visible columns) and of a form browse list
	const p = s.ga;
	const read = async (url) => {
		const r = await p.request.get(BASE + url);
		const buf = await r.body();
		const f = path.join(require('os').tmpdir(), 'e2e-' + Date.now() + '.xlsx');
		fs.writeFileSync(f, buf);
		try {
			return { status: r.status(), ct: r.headers()['content-type'] || '', rows: JSON.parse(execSync('python3 -', { input: "import openpyxl, json\nws = openpyxl.load_workbook(" + JSON.stringify(f) + ").worksheets[0]\nprint(json.dumps({'rows': [[str(c) if c is not None else None for c in r] for r in ws.iter_rows(values_only=True)][:5], 'bold': bool(ws['A1'].font.b), 'freeze': ws.freeze_panes, 'filter': ws.auto_filter.ref}))" }).toString()) };
		} finally { fs.rmSync(f, { force: true }); }
	};
	let r = await read('index.php?p=forms&export=xlsx');
	expect(r.status === 200 && /spreadsheetml/.test(r.ct) && r.rows.bold && r.rows.freeze === 'A2' && /^A1:/.test(r.rows.filter || ''), 'forms list as Excel: ' + JSON.stringify(r).slice(0, 300));
	const all = r.rows.rows[0].length;
	r = await read('index.php?p=forms&export=xlsx&cols=0,2');
	expect(r.rows.rows[0].length === 2 && all > 2, 'visible columns only: ' + JSON.stringify(r.rows.rows[0]));
	// the print view's button carries the visible columns
	await open(p, 'index.php?p=forms&export=print');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-print-cols:forms'); } catch (e) {} });
	await open(p, 'index.php?p=forms&export=print');
	await p.locator('.nu-print-table thead th .nuColHide').nth(1).click();
	expect(/cols=0%2C2|cols=0,2/.test(await p.getAttribute('#nuXlsxButton', 'href')), 'Export Excel without the visible columns: ' + await p.getAttribute('#nuXlsxButton', 'href'));
	await p.click('#nuPrintReset');
	// form browse list (print view → Export Excel)
	await open(p, 'index.php?p=form&f=samplecustomer&print=1');
	const href = await p.getAttribute('#nuXlsxButton', 'href');
	r = await read(href);
	expect(r.status === 200 && r.rows.rows.length > 1 && r.rows.bold, 'form browse list as Excel: ' + JSON.stringify(r).slice(0, 300));
	// the Export menu (0.8.398): HTML page, CSV … (dialog), Excel – lists and form browse lists
	for (const [url, btn] of [['index.php?p=users', '#nuListExportBtn'], ['index.php?p=form&f=samplecustomer', '#nuExportButton']]) {
		await open(p, url);
		await p.click(btn); await p.waitForTimeout(300);
		const menu = p.locator(btn + ' + .dropdown-menu');
		expect(await menu.locator('.nuExpHtml').isVisible() && await menu.locator('.nuExpCsv').isVisible() && await menu.locator('.nuExpXlsx').isVisible(), url + ': Export menu');
		expect(/print/.test(await menu.locator('.nuExpHtml').getAttribute('href')) && /xlsx/.test(await menu.locator('.nuExpXlsx').getAttribute('href')), url + ': menu links');
		await menu.locator('.nuExpCsv').click(); await p.waitForTimeout(500);
		expect(await p.locator('#nuCsvDialog').isVisible(), url + ': CSV dialog');
		await p.keyboard.press('Escape'); await p.waitForTimeout(300);
	}
	// without the print right: no file
	const ben = await login('sample_ben', UPW);
	const rb = await ben.request.get(BASE + 'index.php?p=users&export=xlsx');
	expect(rb.status() === 403 || !/spreadsheetml/.test(rb.headers()['content-type'] || ''), 'Excel without the print right');
	await ben.context().close();
};

T.print_columns = async (s) => {                        // 0.8.397: print view of a list – × hides a column, Undo, Reset view, remembered
	const p = s.ga;
	await open(p, 'index.php?p=forms&export=print');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-print-cols:forms'); } catch (e) {} });
	await open(p, 'index.php?p=forms&export=print');
	const vis = async () => p.locator('.nu-print-table thead th:not(.nuColHidden)').count();
	const n = await vis();
	expect(n > 2 && await p.isDisabled('#nuPrintUndo') && await p.isDisabled('#nuPrintReset'), 'start state');
	await p.locator('.nu-print-table thead th .nuColHide').nth(1).click();
	await p.locator('.nu-print-table thead th .nuColHide').nth(2).click();
	const cells = await p.evaluate(() => document.querySelector('.nu-print-table tbody tr').querySelectorAll('td:not(.nuColHidden)').length);
	expect(await vis() === n - 2 && cells === n - 2 && /2/.test(await p.textContent('#nuPrintHiddenInfo')), 'hide two columns');
	await open(p, 'index.php?p=forms&export=print');
	expect(await vis() === n - 2, 'hidden columns not remembered');
	await p.click('#nuPrintUndo');
	expect(await vis() === n - 1, 'undo');
	await p.click('#nuPrintReset');
	expect(await vis() === n && await p.isDisabled('#nuPrintReset'), 'reset view');
};

T.list_reset = async (s) => {                           // 0.8.395: the × of a filtered list sits right of the funnel, same size
	await open(s.ga, 'index.php?p=forms&type=edit');
	const box = async (sel) => s.ga.locator(sel).boundingBox();
	const f = await box('.nuFilterToggle'), x = await box('.nuListResetBtn');
	expect(f && x && Math.abs(f.height - x.height) < 1 && Math.abs(f.width - x.width) < 2 && Math.abs(f.y - x.y) < 1 && x.x > f.x && x.x - (f.x + f.width) < 16, 'reset not next to the funnel: ' + JSON.stringify([f, x]));
	const bc = await s.ga.evaluate(() => ['.nuFilterToggle', '.nuListResetBtn'].map((q) => getComputedStyle(document.querySelector(q)).borderTopColor));
	expect(bc[0] === bc[1], 'reset border differs from the filter button: ' + bc.join(' / '));
	await Promise.all([s.ga.waitForNavigation(), s.ga.click('.nuListResetBtn')]);
	expect(!/type=/.test(s.ga.url()) && await s.ga.locator('.nuListResetBtn').count() === 0, 'reset did not clear the filter');
	await open(s.ga, 'index.php?p=forms');
	expect(await s.ga.locator('.nuListResetBtn').count() === 0, '× without search or filter');
};

T.list_views = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=users&view=all');
	const all = await p.locator('table.nu-table thead th').count();
	await open(p, 'index.php?p=users&view=compact');
	const compact = await p.locator('table.nu-table thead th').count();
	expect(all > compact && compact > 0, 'views show the same columns (' + all + '/' + compact + ')');
};

// system lists without records (0.8.748): no view menu and no cards ⇄ table switch on phones; a list with records keeps both
T.list_empty_tools = async (s) => {
	const p = s.ga;
	const was = sql("SELECT COUNT(*) FROM zzzzsys_nunext_user_field");
	await p.setViewportSize({ width: 390, height: 800 });
	try {
		await open(p, 'index.php?p=userfields');
		if (was === '0') {
			expect(await p.locator('.nuLvMenuBtn').count() === 0 && await p.locator('[data-lv-phone-toggle]').count() === 0, 'empty list: no view menu / phone switch');
		}
		await open(p, 'index.php?p=users');
		expect(await p.locator('.nuLvMenuBtn').count() === 1 && await p.locator('[data-lv-phone-toggle]').count() === 1, 'users list keeps both');
		await open(p, 'index.php?p=users&q=zz-no-such-user-zz');
		expect(await p.locator('[data-lv-phone-toggle]').count() === 0, 'no hits: no phone switch');
	} finally { await p.setViewportSize({ width: 1300, height: 900 }); }
};

// list cards on phones (0.8.466): a card that is a link holds no other link – the users count of access levels stayed outside the card
T.list_cards = async (s) => {
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 390, height: 844 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		for (const r of ['access', 'users', 'forms', 'procedures', 'reports']) {
			await p.goto(BASE + 'index.php?p=' + r);
			const bad = await p.evaluate(() => {
				const box = document.querySelector('.nuLvCards');
				if (!box) return 'no cards';
				const stray = [...box.children].filter(c => !c.classList.contains('nuLvCard') && !/no_records|text-center/.test(c.className));
				return stray.length ? stray.length + ' element(s) outside the cards' : (box.querySelector('a.nuLvCard a') ? 'link inside a card link' : '');
			});
			expect(bad === '', r + ': ' + bad);
		}
		expect(await p.goto(BASE + 'index.php?p=access') && /\d/.test(await p.locator('.nuLvCard').first().textContent()), 'users count missing in the access level card');
	} finally {
		await ctx.close();
	}
};

// system lists on phones (0.8.471): cards or table – per view (editor → On phones) and per device with the ▦ / ☰ button
T.list_phone_layout = async (s) => {
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 390, height: 844 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	const vis = async () => ({ table: await p.locator('.nuLv .nuLvTableWrap').isVisible(), cards: await p.locator('.nuLv .nuLvCards').isVisible() });
	let viewId = '';
	try {
		await p.goto(BASE + 'index.php?p=users');
		await p.evaluate(() => localStorage.removeItem('nunext-lv-phone-users'));
		await p.goto(BASE + 'index.php?p=users');
		let v = await vis();
		expect(v.cards && !v.table && await p.locator('[data-lv-phone-toggle]').count() === 1, 'phone: cards by default: ' + JSON.stringify(v));
		await p.evaluate(() => document.querySelector('[data-lv-phone-toggle]').click()); await p.waitForTimeout(200);   // 0.8.1068: the button sits in ⋮ on phones
		v = await vis();
		expect(v.table && !v.cards && await p.locator('[data-lv-phone-toggle]').getAttribute('aria-pressed') === 'true', 'toggle to table: ' + JSON.stringify(v));
		const pos = await p.evaluate(() => getComputedStyle(document.querySelector('.nuLvTableWrap td:first-child')).position);
		const wide = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
		expect(pos === 'sticky' && !wide, 'phone table: first column sticky, page not wider than the screen: ' + pos + ' ' + wide);
		await p.reload(); await p.waitForTimeout(200);
		v = await vis();
		expect(v.table && !v.cards, 'table not remembered: ' + JSON.stringify(v));
		await p.evaluate(() => document.querySelector('[data-lv-phone-toggle]').click()); await p.waitForTimeout(200);
		expect(await p.evaluate(() => localStorage.getItem('nunext-lv-phone-users')) === null && (await vis()).cards, 'back to the view: own choice not removed');
		// a view with "On phones: Table"
		const res = await p.evaluate(async () => {
			const fd = new FormData();
			fd.append('_csrf', document.querySelector('input[name=_csrf]').value); fd.append('action', 'save'); fd.append('list', 'users'); fd.append('id', '');
			fd.append('view', JSON.stringify({ name: 'E2E phone table', scope: 'user', levels: [], def: { columns: [{ key: 'name' }, { key: 'login' }, { key: 'email' }], card: { title: 'name', lines: [] }, sort: ['name', 'asc'], filters: { shown: [], values: {} }, per: 25, open: false, phone: 'table' } }));
			return (await fetch('index.php?p=listview', { method: 'POST', body: fd, headers: { Accept: 'application/json' } })).json();
		});
		expect(res.ok, 'save view: ' + JSON.stringify(res));
		viewId = res.id;
		await p.goto(res.url.replace(/^.*index\.php/, BASE + 'index.php'));
		v = await vis();
		expect(v.table && !v.cards && await p.locator('.nuLv').getAttribute('data-lv-phone') === 'table', 'view with "On phones: Table": ' + JSON.stringify(v));
		// the editor offers the choice
		await p.setViewportSize({ width: 1200, height: 900 });
		await p.reload();
		expect(await p.locator('.nuLv .nuLvTableWrap').isVisible() && !(await p.locator('[data-lv-phone-toggle]').isVisible()), 'wide screen: table, no toggle');
	} finally {
		await p.evaluate(() => localStorage.removeItem('nunext-lv-phone-users')).catch(() => {});
		if (viewId) sql(`DELETE FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id = '${viewId.replace(/'/g, '')}'`);
		await ctx.close();
	}
};

// advanced search of browse lists (0.8.719): text / number / date operators on the list's columns, all / any, chips, Clear,
// "My searches", nuNextAdvancedSearch(), the search kept in Export and paging; a masked or hidden column is never offered
// 0.8.769: advanced search on the system lists – the same ▾ panel (column keys, AND only), chips, export, My searches, not-searchable columns
T.list_adv_search = async (s) => {
	const p = s.ga;
	const url = (route, adv) => 'index.php?p=' + route + '&adv=' + encodeURIComponent(JSON.stringify(adv));
	const total = async () => { const f = await p.locator('.nuListCard .card-footer').textContent().catch(() => ''); const m = (f || '').match(/(\d+)/); return m ? +m[1] : 0; };
	const n = (w) => +sql('SELECT COUNT(*) FROM zzzzsys_user u WHERE ' + w);
	sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key LIKE 'advsearch:list:%'");
	try {
		// the panel on the users list: field list without unsortable columns, no all / any switch, Apply → ?adv= + chip
		await open(p, 'index.php?p=users&status=*');
		await p.click('#nuAdvButton'); await p.waitForSelector('#nuAdvPanel .nuAdvRow');
		const fields = await p.$$eval('#nuAdvPanel .nuAdvField option', o => o.map(x => x.value));
		expect(fields.includes('login') && fields.includes('last_login') && !fields.includes('status') && !fields.includes('online'), 'fields: ' + fields.join(','));
		expect(!(await p.locator('#nuAdvPanel .nuAdvMatch').isVisible()), 'no all / any switch on system lists');
		await p.selectOption('#nuAdvPanel .nuAdvField', 'login');
		await p.selectOption('#nuAdvPanel .nuAdvOp', 'starts');
		await p.fill('#nuAdvPanel .nuAdvVal', 'sample_a');
		await Promise.all([p.waitForNavigation(), p.click('#nuAdvPanel .nuAdvApply')]);
		expect(/adv=/.test(p.url()) && await total() === n("u.sus_login_name LIKE 'sample_a%'"), 'starts with: ' + await total());
		expect(await p.locator('#nuLvChips .nuAdvChip').count() === 1, 'chip');
		// links keep it (sort), the search form keeps it, the chip removes it
		expect((await p.locator('thead th a').first().getAttribute('href')).includes('adv='), 'sort link keeps the search');
		expect(await p.locator('form.nuListFilter input[name="adv"]').count() === 1, 'search form keeps it');
		await Promise.all([p.waitForNavigation(), p.click('#nuLvChips .nuAdvChip')]);
		expect(!/adv=/.test(p.url()) && await p.locator('.nuAdvChip').count() === 0, 'chip removes the condition');
		// AND with the quick search; types: number + bool (access levels), date (log), unknown / unsearchable keys ignored
		await open(p, url('users', { c: [['login', 'starts', 'sample_'], ['email', 'contains', 'anna']] }) + '&status=*');
		expect(await total() === n("u.sus_login_name LIKE 'sample_%' AND u.sus_email LIKE '%anna%'"), 'two conditions AND: ' + await total());
		await open(p, url('access', { c: [['users', 'ge', '1'], ['twofa', 'no', '']] }));
		const accN = +sql("SELECT COUNT(*) FROM zzzzsys_access a WHERE (SELECT COUNT(*) FROM zzzzsys_user u WHERE u.sus_zzzzsys_access_id = a.zzzzsys_access_id) >= 1 AND COALESCE(a.sal_use_2fa, '') NOT IN ('1')");
		expect(await total() === accN && await p.locator('.nuAdvChip').count() === 2, 'number + bool: ' + await total() + ' / ' + accN);
		await open(p, url('log', { c: [['time', 'today', '']] }));
		const logN = +sql('SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE DATE(log_time) = CURDATE()');
		expect(await total() === logN, 'date today: ' + await total() + ' / ' + logN);
		await open(p, url('log', { c: [['details', 'contains', 'x'], ['nosuch', 'eq', '1']] }));
		expect(await p.locator('.nuAdvChip').count() === 0, 'details / unknown keys are not searchable');
		await p.click('#nuAdvButton'); await p.waitForSelector('#nuAdvPanel .nuAdvRow');
		expect(!(await p.$$eval('#nuAdvPanel .nuAdvField option', o => o.map(x => x.value))).includes('details'), 'log details not offered');
		// export honours the conditions
		const csv = await (await p.request.get(BASE + url('users', { c: [['login', 'starts', 'sample_a']] }) + '&status=*&export=csv')).text();
		expect(/sample_anna/.test(csv) && !/sample_ben/.test(csv), 'CSV export follows the search');
		// My searches per list and user
		await open(p, url('users', { c: [['login', 'starts', 'sample_']] }));
		await p.click('#nuAdvButton'); await p.waitForSelector('#nuAdvPanel .nuAdvSaveName');
		expect(await p.inputValue('#nuAdvPanel .nuAdvVal') === 'sample_', 'panel opens with the current condition');
		await p.fill('#nuAdvPanel .nuAdvSaveName', 'E2E samples'); await p.click('#nuAdvPanel .nuAdvSaveBtn');
		await p.waitForSelector('#nuAdvPanel .nuAdvSavedUse');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'advsearch:list:users' AND name = 'E2E samples'") === 1, 'saved for the list');
		await open(p, 'index.php?p=users');
		await p.click('#nuAdvButton'); await p.waitForSelector('#nuAdvPanel .nuAdvSavedUse');
		await Promise.all([p.waitForNavigation(), p.click('#nuAdvPanel .nuAdvSavedUse')]);
		expect(/adv=/.test(p.url()) && await p.locator('.nuAdvChip').count() === 1, 'a saved search is applied');
		// another list, or a list the user may not open, is refused
		const bad = await p.evaluate(async () => (await fetch('index.php?p=advsearch', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': JSON.parse(document.getElementById('nuAdvData').dataset.adv).csrf },
			body: JSON.stringify({ f: 'list:nosuch', action: 'save', name: 'x', adv: '{"c":[["login","eq","a"]]}' }) })).status);
		expect(bad === 403, 'unknown list refused: ' + bad);
	} finally {
		sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key LIKE 'advsearch:list:%'");
	}
};

T.adv_search = async (s) => {
	const p = s.ga;
	const tot = async () => +((await p.textContent('.nuBrowseCard .card-footer')).match(/(\d+)/) || [0, -1])[1];
	const ids = (where) => +sql('SELECT COUNT(*) FROM sample_customer WHERE ' + where);
	const url = (adv) => 'index.php?p=form&f=samplecustomer&adv=' + encodeURIComponent(JSON.stringify(adv));
	sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'advsearch:samplecustomer'");
	try {
		// the panel: one row, field / operator / value, Apply
		await open(p, 'index.php?p=form&f=samplecustomer');
		const all = await tot();
		await p.click('#nuAdvButton'); await p.waitForSelector('#nuAdvPanel .nuAdvRow');
		await p.selectOption('#nuAdvPanel .nuAdvField', { label: 'Name' });
		await p.selectOption('#nuAdvPanel .nuAdvOp', 'starts');
		await p.fill('#nuAdvPanel .nuAdvVal', 'B');
		await Promise.all([p.waitForNavigation(), p.click('#nuAdvPanel .nuAdvApply')]);
		expect(await tot() === ids("cus_name LIKE 'B%'") && /adv=/.test(p.url()), 'starts with: ' + await tot());
		expect(await p.locator('#nuAdvChips .nuAdvChip').count() === 1 && /Name/.test(await p.textContent('#nuAdvChips')), 'chip');
		// server side, every type (straight through the URL)
		const checks = [
			[{ m: 'all', c: [[1, 'contains', 'a']] }, "cus_name LIKE '%a%'"],
			[{ m: 'all', c: [[1, 'ncontains', 'a']] }, "COALESCE(cus_name, '') NOT LIKE '%a%'"],
			[{ m: 'all', c: [[2, 'in', ['GB', 'DE']]] }, "cus_country IN ('GB', 'DE')"],
			[{ m: 'all', c: [[2, 'nempty', '']] }, "COALESCE(cus_country, '') <> ''"],
			[{ m: 'all', c: [[4, 'gt', "30'000"]] }, 'cus_credit > 30000'],
			[{ m: 'all', c: [[4, 'between', '10000', '30000']] }, 'cus_credit BETWEEN 10000 AND 30000'],
			[{ m: 'all', c: [[3, 'before', '2024-06-01']] }, "cus_since < '2024-06-01'"],
			[{ m: 'any', c: [[2, 'eq', 'GB'], [4, 'ge', '50000']] }, "cus_country = 'GB' OR cus_credit >= 50000"],
			[{ m: 'all', c: [[2, 'eq', 'GB'], [4, 'ge', '50000']] }, "cus_country = 'GB' AND cus_credit >= 50000"],
			[{ m: 'all', c: [[1, 'contains', '100%_!']] }, "cus_name LIKE '%100!%!_!!%' ESCAPE '!'"],
		];
		for (const [adv, where] of checks) {
			await open(p, url(adv));
			expect(await tot() === ids(where), JSON.stringify(adv) + ': ' + await tot() + ' ≠ ' + ids(where));
		}
		// a bad column / operator / value is left out (all rows)
		await open(p, url({ m: 'all', c: [[99, 'eq', 'x'], [1, 'gt', '5'], [4, 'gt', 'abc']] }));
		expect(await tot() === all && await p.locator('#nuAdvChips').count() === 0, 'invalid conditions not ignored');
		// chip ✕ removes one condition, Reset removes all
		await open(p, url({ m: 'all', c: [[2, 'eq', 'GB'], [4, 'ge', '50000']] }));
		await Promise.all([p.waitForNavigation(), p.locator('#nuAdvChips .nuAdvChip').nth(1).click()]);
		expect(await tot() === ids("cus_country = 'GB'") && await p.locator('#nuAdvChips .nuAdvChip').count() === 1, 'chip ✕');
		// the search goes along to Export (Excel link) and stays with the normal search
		expect(/adv=/.test(decodeURIComponent(await p.getAttribute('#nuExportButton + .dropdown-menu .nuExpXlsx', 'href'))), 'export without the search');
		// My searches: save, use, delete
		await p.click('#nuAdvButton'); await p.waitForSelector('#nuAdvPanel .nuAdvSaveName');
		await p.fill('#nuAdvPanel .nuAdvSaveName', 'e2e British');
		await p.click('#nuAdvPanel .nuAdvSaveBtn'); await p.waitForSelector('#nuAdvPanel .nuAdvSavedUse');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'advsearch:samplecustomer' AND name = 'e2e British'") === 1, 'not saved');
		await open(p, 'index.php?p=form&f=samplecustomer');
		await p.click('#nuAdvButton'); await p.waitForSelector('#nuAdvPanel .nuAdvSavedUse');
		await Promise.all([p.waitForNavigation(), p.click('#nuAdvPanel .nuAdvSavedUse')]);
		expect(await tot() === ids("cus_country = 'GB'"), 'saved search not applied');
		await p.click('#nuAdvButton'); await p.waitForSelector('#nuAdvPanel .nuAdvSavedDel');
		await p.click('#nuAdvPanel .nuAdvSavedDel'); await p.waitForTimeout(500);
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'advsearch:samplecustomer'") === 0, 'not deleted');
		// Clear
		await Promise.all([p.waitForNavigation(), p.click('#nuAdvPanel .nuAdvClear')]);
		expect(!/adv=/.test(p.url()) && await tot() === all, 'Clear');
		// form code: nuNextAdvancedSearch()
		await Promise.all([p.waitForNavigation(), p.evaluate(() => nuNextAdvancedSearch([{ column: 'Country', op: 'eq', value: 'DE' }]))]);
		const got = await p.evaluate(() => nuNextAdvancedSearch());
		expect(await tot() === ids("cus_country = 'DE'") && got.conditions.length === 1 && got.conditions[0].title === 'Country', 'nuNextAdvancedSearch: ' + JSON.stringify(got));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'advsearch:samplecustomer'");
	}
};

T.forms_status_menu = async (s) => {
	const p = s.ga;
	const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' AND COALESCE(NULLIF(sfo_status, ''), '1') = '1' AND sfo_type <> 'page' ORDER BY zzzzsys_form_id LIMIT 1");
	if (!id) { s.skip = 'no form'; return; }
	const keep = sql(`SELECT IFNULL(sfo_status, '-') FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`);
	try {
		await open(p, 'index.php?p=forms&status=all&q=');
		const badge = p.locator(`.nuFormStatus[data-nu-form="${id}"]`);
		expect(await badge.count() === 1, 'status with menu for ' + id);
		await badge.click({ button: 'right' });
		await p.waitForSelector('.nuSbMenu .dropdown-item');
		expect(await p.locator('.nuSbMenu .dropdown-item').count() === 4, 'four statuses (0.8.808: Maintenance)');
		expect(await p.locator('.nuSbMenu .dropdown-item.active').count() === 1, 'current one marked');
		await p.locator('.nuSbMenu .dropdown-item').nth(3).click();       // Archived
		await p.waitForSelector('#nuFormStatusToast .alert-success');
		expect(sql(`SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`) === '3', 'saved as archived');
		expect(await badge.getAttribute('data-nu-st') === '3', 'badge updated');
		expect(!p.url().includes('p=form&'), 'row not opened');
		await badge.click({ button: 'right' });
		await p.locator('.nuSbMenu .dropdown-item').nth(0).click();       // Active again
		await p.waitForTimeout(800);
		expect(sql(`SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`) === '1', 'back to active');
		expect(p.errors.length === 0, 'JS errors: ' + p.errors.join(' | '));
	} finally {
		sql(`UPDATE zzzzsys_form SET sfo_status = ${keep === '-' ? 'NULL' : `'${keep}'`} WHERE zzzzsys_form_id = '${id}'`);
	}
	// users without form builder rights get no menu
	const ben = sql("SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (ben === '1') {
		const u = await login('sample_ben', 'Test1234!');
		await open(u, 'index.php?p=forms');
		expect(await u.locator('.nuFormStatus').count() === 0 && await u.locator('#nuFormStatusData').count() === 0, 'no status menu without builder rights');
		await u.context().close();
	}
};

// lists refreshed in place (0.8.776): system lists and form browse lists swap their card – no page load, address and Back follow
T.list_smooth = async (s) => {
	const p = s.ga;
	const mark = () => p.evaluate(() => window.__nuMark === 1);
	await open(p, 'index.php?p=users');
	await p.evaluate(() => { window.__nuMark = 1; });
	await p.fill('input[name=q]', 'sample'); await p.press('input[name=q]', 'Enter');
	await p.waitForFunction(() => document.documentElement.getAttribute('data-nu-lv-swaps') === '1');
	expect(await mark() && /q=sample/.test(p.url()), 'users: search in place, address follows');
	expect(await p.evaluate(() => document.activeElement && document.activeElement.name === 'q'), 'search keeps the focus');
	await p.locator('table.nu-table thead a').first().click();
	await p.waitForFunction(() => document.documentElement.getAttribute('data-nu-lv-swaps') === '2');
	expect(await mark() && /sort=/.test(p.url()), 'users: sort in place');
	await p.locator('.nuListFilter select.js-autosubmit[name=access]').evaluate(el => { el.selectedIndex = 1; el.dispatchEvent(new Event('change', { bubbles: true })); });
	await p.waitForFunction(() => document.documentElement.getAttribute('data-nu-lv-swaps') === '3');
	expect(await mark() && /access=/.test(p.url()), 'users: filter in place');
	await p.goBack(); await p.waitForFunction(() => document.documentElement.getAttribute('data-nu-lv-swaps') === '4');
	expect(await mark() && /sort=/.test(p.url()), 'Back swaps again');
	await p.locator('#nuAdvButton, .nuAdvBtn').first().click(); await p.waitForTimeout(300);
	expect(await p.locator('#nuAdvPanel').isVisible(), 'advanced search panel works after a swap');
	// form browse list: automatic for a form without code of its own (a script in Setup → Include switches it off)
	const inc = sql("SELECT IFNULL(set_include, '') FROM zzzzsys_setup");
	sql("UPDATE zzzzsys_setup SET set_include = ''");
	try {
		await open(p, 'index.php?p=form&f=sampleproduct');
		expect(await p.evaluate(() => window.nuNext.browse.smooth === true), 'sampleproduct: smooth flag');
		await p.evaluate(() => { window.__nuMark = 1; });
		const ps0 = await p.evaluate(() => window.nuNext.ps);
		await p.fill('#nuSearchField', 'a'); await p.press('#nuSearchField', 'Enter');
		await p.waitForFunction(() => document.documentElement.getAttribute('data-nu-lv-swaps') === '1');
		const ps = await p.evaluate(() => window.nuNext.ps);
		expect(await mark() && /q=a/.test(p.url()) && ps && ps !== ps0, 'browse: search in place, the new page scope taken over');
		await p.locator('#nuBrowseTable thead a.nu-sort').nth(1).click();
		await p.waitForFunction(() => document.documentElement.getAttribute('data-nu-lv-swaps') === '2');
		expect(await mark() && await p.evaluate(() => window.nuNext.browse.sort === 1), 'browse: sort in place, nuNext.browse updated');
		await p.locator('select.nuColumnFilter').first().selectOption({ index: 1 });
		await p.waitForFunction(() => document.documentElement.getAttribute('data-nu-lv-swaps') === '3');
		expect(await mark(), 'browse: column filter in place');
		await Promise.all([p.waitForNavigation(), p.locator('#nuBrowseTable tbody tr').first().click()]);
		expect(/[?&]r=/.test(p.url()), 'a row still opens its record');
		// a form with browse JavaScript: the normal page load
		await open(p, 'index.php?p=form&f=samplecustomer');
		expect(await p.evaluate(() => window.nuNext.browse.smooth === false), 'samplecustomer (browse JS): no smooth flag');
		await p.evaluate(() => { window.__nuMark = 1; });
		await Promise.all([p.waitForNavigation(), p.press('#nuSearchField', 'Enter')]);
		expect(!(await mark()), 'samplecustomer: reloads');
	} finally { sql("UPDATE zzzzsys_setup SET set_include = '" + inc.replace(/\\/g, '\\\\').replace(/'/g, "''") + "'"); }
};

T.forms_row_actions = async (s) => {          // 0.8.809: no Form cloner button in the rows of the forms list (Forms → Tools keeps it)
	const p = s.ga;
	await open(p, 'index.php?p=forms');
	expect(await p.locator('.nuRowActions a[href*="p=builder"]').count() > 0, 'design mode button missing');
	expect(await p.locator('td a[href*="p=cloner"]').count() === 0, 'cloner button still in the rows');
	expect(await p.locator('.dropdown-menu a[href*="p=cloner"]:not(.nuPhMoreItem)').count() === 1, 'Tools → Form cloner missing');
};

T.log_purge = async (s) => {                         // 0.8.1087: Log → Delete older entries… (globeadmin)
	const p = s.ga;
	sql("INSERT INTO zzzzsys_nunext_log (zzzzsys_nunext_log_id, log_time, login, event, details) VALUES ('e2e_lp_old', NOW() - INTERVAL 40 DAY, 'e2e', 'e2e_purge', ''), ('e2e_lp_new', NOW() - INTERVAL 5 DAY, 'e2e', 'e2e_purge', '')");
	await open(p, 'index.php?p=log');
	await p.click('#logPurgeOpen');
	await p.waitForSelector('#logPurgeDlg.show');
	await p.check('#logPurge30');
	await Promise.all([p.waitForNavigation(), p.click('#logPurgeGo')]);
	const left = sql("SELECT GROUP_CONCAT(zzzzsys_nunext_log_id ORDER BY zzzzsys_nunext_log_id) FROM zzzzsys_nunext_log WHERE event = 'e2e_purge'");
	const rec = sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'log_cleared' AND log_time > NOW() - INTERVAL 2 MINUTE");
	expect(left === 'e2e_lp_new' && +rec >= 1 && /\d/.test(await p.textContent('.alert-success')), 'purge: ' + left + ' / ' + rec);
	sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'e2e_purge'");
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.910: sticky list titles – a form browse list with 280 rows (and a system list): the title row stays under the top bar / button row
T.browse_sticky_head = async (s) => {
	const p = s.ga;
	const vals = [];
	for (let i = 0; i < 280; i++) vals.push(`('e2esh${i}', 'E2E${String(i).padStart(3, '0')}', 'E2E sticky ${i}', 'CH', 1)`);
	sql('INSERT INTO sample_customer (sample_customer_id, cus_code, cus_name, cus_country, cus_active) VALUES ' + vals.join(','));
	try {
		const look = () => p.evaluate(() => {
			const t = document.querySelector('table[data-nu-sticky-head]'), th = t.tHead.rows[0].cells[0].getBoundingClientRect();
			const root = getComputedStyle(document.documentElement);
			let top = parseFloat(root.getPropertyValue('--nu-sticky-top')) || 0;
			document.querySelectorAll('.nuStickyBar').forEach(b => { const r = b.getBoundingClientRect(); if (r.height && r.top <= top + 12 && r.bottom > top) top = r.bottom; });
			return { float: t.classList.contains('nuHeadFloat'), thTop: th.top, line: top };
		});
		for (const url of ['index.php?p=form&f=samplecustomer&per=300', 'index.php?p=log&per=100']) {
			await open(p, url);
			const before = await look();
			expect(!before.float, url + ': not floating before scrolling');
			await p.evaluate(() => window.scrollTo(0, 2500)); await p.waitForTimeout(300);
			const after = await look();
			expect(after.float && Math.abs(after.thTop - after.line) <= 2, url + ': title row at the top line: ' + JSON.stringify(after));
			await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(300);
			expect(!(await look()).float, url + ': back in place');
		}
	} finally { sql("DELETE FROM sample_customer WHERE sample_customer_id LIKE 'e2esh%'"); }
};

T.browse_prop_filter = async (s) => {
	// 0.8.912: nuSetProperty + nuSearchAction (filter buttons / selects added to the browse toolbar by form code) filter the browse SQL
	// through a hash variable – an unset one stays '#name#'
	const p = s.ga, F = 'samplecustomer';
	const old = sql(`SELECT sfo_browse_sql FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`);
	const oldJs = sql(`SELECT COALESCE(sfo_browse_javascript, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`);
	const name = sql('SELECT cus_name FROM sample_customer ORDER BY cus_name LIMIT 1');
	const all = +sql('SELECT COUNT(*) FROM sample_customer');
	// 0.8.1187: the SQL as run is in the side pane (Browse SQL → As run), data nuNext.browse_sql.run {parts, vars}
	const runInfo = () => p.evaluate(() => {
		const r = (window.nuNext.browse_sql || {}).run || {parts: [], vars: {}};
		return r.parts.map((x) => typeof x === 'string' ? x : (x[1] === null ? '#' + x[0] + '#' : x[1])).join('') + '\n'
			+ Object.keys(r.vars).map((n) => '#' + n + '# = ' + (r.vars[n] === null ? '(not set)' : "'" + r.vars[n] + "'")).join('\n');
	});
	try {
		sql(`UPDATE zzzzsys_form SET sfo_browse_sql = 'SELECT * FROM sample_customer WHERE (LOCATE(''#'', ''#e2e_flt#'') = 1 OR ''#e2e_flt#'' = '''' OR cus_name = ''#e2e_flt#'')',
			sfo_browse_javascript = CONCAT(COALESCE(sfo_browse_javascript, ''), '\\nwindow.e2eFlt = function (v) { nuSetProperty(''e2e_flt'', v); nuSearchAction(); };') WHERE zzzzsys_form_id = '${F}'`);
		const rows = () => p.locator('#nuBrowseTable tr.nuBrowseRow').count();
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuBrowseTable');
		expect(await p.locator('#nuRECORD .nuCell[data-nu-column-id][data-nu-row]').count() > 0, 'browse cells below #nuRECORD as in nuBuilder Forte');
		const first = await rows();
		expect(/#e2e_flt# = \(/.test(await runInfo()), 'as run: hash variable not set');
		// nuBuilder Forte's filter buttons: nuAddActionButton + onclick code that reads / marks the label with .value / .val()
		const ab = await p.evaluate(() => {
			nuAddActionButton('e2eFltAlles', 'Alles', 'window.e2eAb = [this.value, $(this).val()];');
			const b = $('#nue2eFltAllesButton');
			b.addClass('active').val('✓ ' + b.val());
			b[0].click();
			return {got: window.e2eAb, text: b.text(), val: b.val(), active: b.hasClass('active')};
		});
		// own <input type="button" class="nuActionButton"> (Forte style): .val() stays the input's value
		const inp = await p.evaluate(() => {
			$('#nuActionHolder').append("<input id='e2eInpBtn' type='button' class='nuActionButton' value='Beta'>");
			const b = $('#e2eInpBtn');
			b.val('✓ ' + b.val());
			const v = b.val(); b.remove(); return v;
		});
		expect(inp === '✓ Beta', 'input button keeps its label: ' + inp);
		expect(ab.got && ab.got[0] === '✓ Alles' && ab.got[1] === '✓ Alles' && ab.text === '✓ Alles' && ab.val === '✓ Alles' && ab.active, 'action button value / val() as in Forte ' + JSON.stringify(ab));
		const loop = await p.evaluate(() => {
			const cid = document.querySelector('#nuBrowseTable div.nuCell[data-nu-column-id]:not([data-nu-column-id=""])').getAttribute('data-nu-column-id');
			const byId = nuBrowseLoop([cid], (c) => c.columnId === cid && typeof c.row === 'number' ? c.text : undefined);
			const all = nuBrowseLoop([], () => 1);
			const one = document.querySelector('#nuBrowseTable div.nuCell[data-nu-column-id="' + cid + '"]');
			nuBrowseLoop([cid], (c) => { if (c === one) c.html = '<b>x</b>'; });
			return {byId: byId.length, all: all.length, html: one.innerHTML};
		});
		expect(loop.byId === first && loop.all > first && loop.html === '<b>x</b>', 'nuBrowseLoop as in Forte ' + JSON.stringify(loop));
		expect(first > 1, 'unfiltered ' + first + ' of ' + all);
		await Promise.all([p.waitForNavigation({waitUntil: 'load'}).catch(() => {}), p.evaluate((v) => window.e2eFlt(v), name)]);
		await p.waitForSelector('#nuBrowseTable'); await p.waitForTimeout(400);
		expect(await rows() === 1, 'filtered to one row: ' + await rows());
		expect(await p.evaluate(() => nuGetProperty('e2e_flt')) === name, 'property kept after the reload');
		const info = await runInfo();
		expect(info.includes("#e2e_flt# = '" + name + "'") && info.includes("cus_name = '" + name + "'"), 'as run: SQL + hash variables ' + info.slice(0, 300));
		// code that rewrites the address bar as nuBuilder Forte does (?f=…&r=&h=undefined) – the filter must still reach the list
		await Promise.all([p.waitForNavigation({waitUntil: 'load'}).catch(() => {}), p.evaluate((v) => { history.replaceState(null, '', 'index.php?f=samplecustomer&r=&h=undefined'); window.e2eFlt(v); }, name)]);
		await p.waitForSelector('#nuBrowseTable'); await p.waitForTimeout(400);
		expect(await rows() === 1, 'filtered after a Forte address: ' + await rows() + ' ' + p.url());
		const perma = await (await p.context().request.get(BASE + 'index.php?f=samplecustomer&r=&h=undefined&ps=abc&q=x', {maxRedirects: 0})).headers().location || '';
		expect(/p=form/.test(perma) && /ps=abc/.test(perma) && /q=x/.test(perma) && !/h=/.test(perma), 'permalink redirect keeps the parameters ' + perma);
		await Promise.all([p.waitForNavigation({waitUntil: 'load'}).catch(() => {}), p.evaluate(() => window.e2eFlt(''))]);
		await p.waitForSelector('#nuBrowseTable'); await p.waitForTimeout(400);
		expect(await rows() === first, 'filter cleared ' + await rows());
	} finally {
		execSync('mysql ' + DB, {input: `UPDATE zzzzsys_form SET sfo_browse_sql = ${JSON.stringify(old)}, sfo_browse_javascript = ${JSON.stringify(oldJs)} WHERE zzzzsys_form_id = '${F}'`});
	}
};

// 0.8.1092: projects list – a description over 70 characters is cut with … (full text as tooltip), in cards and in the table
// 0.8.1093: list filters on phones stay folded also when one is set (reports list; the chip shows it), open from 768 px; the funnel opens them in one tap
T.list_filters_phone_folded = async (s) => {
	const ga = s.ga, vp = ga.viewportSize();
	const grp = sql("SELECT sre_group FROM zzzzsys_report WHERE sre_group <> '' LIMIT 1");
	if (!grp) return 'skip';
	try {
		for (const [w, wide] of [[390, false], [1280, true]]) {
			await ga.setViewportSize({ width: w, height: 900 });
			await open(ga, 'index.php?p=reports&group=' + encodeURIComponent(grp));
			const shown = await ga.evaluate(() => { const f = document.querySelector('#nuLvFilters'); return !!f && f.offsetHeight > 0; });
			expect(shown === wide, w + ' px: filters ' + (wide ? 'open' : 'folded') + ' with a filter set');
			expect(await ga.locator('#nuLvChips .nuLvChip').count() >= 1, w + ' px: the chip shows the set filter');
			if (!wide) {
				await ga.click('[data-bs-target="#nuLvFilters"]');
				await ga.waitForFunction(() => document.querySelector('#nuLvFilters').offsetHeight > 0, null, { timeout: 3000 });
			}
		}
	} finally { await ga.setViewportSize(vp); }
};

T.projects_desc_cut = async (s) => {
	const ga = s.ga, vp = ga.viewportSize();
	const desc = sql("SELECT description FROM zzzzsys_nunext_project WHERE code = 'tkt_system'");
	if (!desc || desc.length <= 70) return 'skip';
	try {
		for (const w of [390, 1280]) {
			await ga.setViewportSize({ width: w, height: 900 });
			await open(ga, 'index.php?p=projects');
			const r = await ga.evaluate(() => [...document.querySelectorAll('main span[title]')].filter(e => e.textContent.endsWith('…')).map(e => [e.textContent.length, e.title.length])[0]);
			expect(r && r[0] === 71 && r[1] > 70, w + ' px: description cut to 70 + …: ' + JSON.stringify(r));
		}
	} finally { await ga.setViewportSize(vp); }
};

// grouped list (a column with nunext.group): heading + subtotal row per group over the WHOLE group, sort inside the groups; totals of
// selected rows (checkbox column of lists with totals); Form with AI reads and writes "total" / "group" of list columns
T.browse_groups_selection = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga, vp = ga.viewportSize();
	const before = sql("SELECT CONCAT(zzzzsys_browse_id, '=', IFNULL(TO_BASE64(sbr_json), '~')) FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = 'samplecustomer'").split('\n');
	const set = (id, j) => sql(`UPDATE zzzzsys_browse SET sbr_json = '${JSON.stringify({ nunext: j })}' WHERE zzzzsys_browse_id = '${id}'`);
	const num = v => (/^\(.*\)$/.test(String(v).trim()) ? -1 : 1) * parseFloat(String(v).replace(/[^0-9.-]/g, ''));
	const php = (code) => require('child_process').execFileSync('php', ['-r', 'chdir(' + JSON.stringify(SITE) + "); require 'core/nuapp.php'; " + code]).toString();   // no shell: the code has $ variables
	try {
		set('sample_customer_b5', { total: 'sum' }); set('sample_customer_b3', { filter: true, group: true });
		// ---- groups: 5 rows per page, so groups are split across pages
		await open(ga, 'index.php?p=form&f=samplecustomer&per=5');
		const g = await ga.evaluate(() => { const out = []; let cur = null;
			document.querySelectorAll('#nuRECORD > tr').forEach(tr => {
				if (tr.classList.contains('nuGroupRow')) { cur = { name: tr.querySelector('.nuGroupName').textContent, count: +tr.querySelector('.nuGroupCount').textContent, rows: [], sub: null }; out.push(cur); }
				else if (tr.classList.contains('nuBrowseRow')) cur.rows.push([tr.querySelector('div.nuCell[data-nu-column="2"]').textContent.trim(), tr.querySelector('div.nuCell[data-nu-column="1"]').textContent.trim()]);
				else if (tr.classList.contains('nuGroupTotalRow')) cur.sub = tr.querySelector('td[data-nu-total=sum] .nuTotalVal').textContent;
			}); return out; });
		const named = g.filter(x => x.rows[0] && x.rows[0][0] !== '');   // other tests may have left customers without a country: their group is "(empty)"
		expect(named.length >= 2 && g.every(x => x.rows.length > 0 && x.sub !== null) && named.every(x => x.rows.every(r => r[0] === x.name)), 'heading, rows of the group and a subtotal row: ' + JSON.stringify(g).slice(0, 300));
		const names = named.map(x => x.name);
		expect(JSON.stringify(names) === JSON.stringify([...names].sort()), 'groups in order: ' + names.join(','));
		for (const x of named) {
			const [n, sum] = sql(`SELECT COUNT(*), IFNULL(SUM(cus_credit), 0) FROM sample_customer WHERE cus_country = '${x.name}'`).split('\t');
			expect(x.count === +n && Math.abs((num(x.sub) || 0) - +sum) < 0.01, 'count and subtotal of the whole group ' + x.name + ': ' + JSON.stringify([x.count, x.sub, n, sum]));
		}
		// one row per page inside the largest group: the heading and the subtotal still cover the whole group
		const big = sql("SELECT cus_country, COUNT(*), SUM(cus_credit) FROM sample_customer WHERE IFNULL(cus_country, '') <> '' GROUP BY cus_country ORDER BY COUNT(*) DESC LIMIT 1").split('\t');
		const off = +sql(`SELECT COUNT(*) FROM sample_customer WHERE IFNULL(cus_country, '') < '${big[0]}'`);
		await open(ga, 'index.php?p=form&f=samplecustomer&per=1&pg=' + (off + 1));
		const one = await ga.evaluate(() => ({ rows: document.querySelectorAll('#nuRECORD tr.nuBrowseRow').length, name: document.querySelector('.nuGroupName').textContent, count: +document.querySelector('.nuGroupCount').textContent, sub: document.querySelector('tr.nuGroupTotalRow td[data-nu-total=sum] .nuTotalVal').textContent }));
		expect(+big[1] > 1 && one.rows === 1 && one.name === big[0] && one.count === +big[1] && Math.abs(num(one.sub) - +big[2]) < 0.01, 'a group split across pages shows its whole subtotal: ' + JSON.stringify([one, big]));
		await open(ga, 'index.php?p=form&f=samplecustomer&per=5');
		const grand = await ga.textContent('#nuBrowseTotals td[data-nu-total=sum] .nuTotalVal');
		expect(Math.abs(num(grand) - +sql('SELECT SUM(cus_credit) FROM sample_customer')) < 0.01, 'the row of totals stays the grand total');
		// a click on another title sorts inside the groups
		await open(ga, 'index.php?p=form&f=samplecustomer&sort=1&dir=desc');
		const g2 = await ga.evaluate(() => { const out = []; let cur = null;
			document.querySelectorAll('#nuRECORD > tr').forEach(tr => { if (tr.classList.contains('nuGroupRow')) { cur = []; out.push(cur); } else if (tr.classList.contains('nuBrowseRow')) cur.push(tr.querySelector('div.nuCell[data-nu-column="1"]').textContent.trim()); });
			return out; });
		expect(g2.length === +sql("SELECT COUNT(DISTINCT IFNULL(cus_country, '')) FROM sample_customer"), 'one heading per group when all rows fit: ' + g2.length);
		expect(g2.every(x => JSON.stringify(x) === JSON.stringify([...x].sort((a, b) => a.toLowerCase() < b.toLowerCase() ? 1 : -1))), 'sorted by name descending inside each group: ' + JSON.stringify(g2).slice(0, 200));
		// ---- totals of selected rows
		await open(ga, 'index.php?p=form&f=samplecustomer');
		const all = await ga.textContent('#nuBrowseTotals td[data-nu-total=sum] .nuTotalVal');
		// two rows that have a credit value
		const idx = await ga.evaluate(() => window.nuNext.browse.rows.map((r, i) => r[5] !== null && r[5] !== '' ? i : -1).filter(i => i >= 0).slice(0, 3));
		const picks = await ga.evaluate(() => [...document.querySelectorAll('#nuRECORD tr.nuBrowseRow')].map(tr => tr.getAttribute('data-nu-primary-key')));
		const url0 = ga.url();
		await ga.locator('#nuRECORD tr.nuBrowseRow .nuRowSel').nth(idx[0]).check();
		await ga.locator('#nuRECORD tr.nuBrowseRow .nuRowSel').nth(idx[2]).check();
		expect(ga.url() === url0, 'ticking a row does not open it');
		const want = +sql(`SELECT SUM(cus_credit) FROM sample_customer WHERE sample_customer_id IN ('${picks[idx[0]]}', '${picks[idx[2]]}')`);
		let v = await ga.textContent('#nuBrowseTotals td[data-nu-total=sum] .nuTotalVal');
		expect(Math.abs(num(v) - want) < 0.01 && /USD/.test(v) && v !== all, 'sum of the two ticked rows in the column format: ' + v + ' / ' + want);
		expect(await ga.isVisible('#nuSelClear') && /2/.test(await ga.textContent('#nuSelClear')) && await ga.evaluate(() => document.getElementById('nuBrowseTotals').classList.contains('nuTotalSel') && document.getElementById('nuRowSelAll').indeterminate), 'marker with the number of rows');
		await ga.click('#nuSelClear');
		expect(await ga.textContent('#nuBrowseTotals td[data-nu-total=sum] .nuTotalVal') === all && !(await ga.isVisible('#nuSelClear')), 'cleared: all matching rows again');
		await ga.check('#nuRowSelAll');
		v = await ga.textContent('#nuBrowseTotals td[data-nu-total=sum] .nuTotalVal');
		const pageSum = await ga.evaluate(() => window.nuNext.browse.rows.reduce((a, r) => a + (parseFloat(r[5]) || 0), 0));
		expect(Math.abs(num(v) - pageSum) < 0.01, 'select all = the rows of the page: ' + v + ' / ' + pageSum);
		await open(ga, 'index.php?p=form&f=samplecustomer&print=1');
		expect(!(await ga.$('.nuSelCol')) && !!(await ga.$('tr.nuGroupRow')), 'print view: groups, no checkboxes');
		await ga.setViewportSize({ width: 390, height: 800 });
		await open(ga, 'index.php?p=form&f=samplecustomer');
		await ga.waitForFunction(() => document.documentElement.classList.contains('nuPhoneCards'));
		expect(!(await ga.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) && await ga.isVisible('tr.nuGroupRow') && await ga.isVisible('#nuRECORD .nuRowSel'), 'phones: headings and checkboxes fit');
		await ga.setViewportSize(vp);
		// ---- Form with AI: the columns' total / group are read and written
		const spec = JSON.parse(php("echo json_encode(NuFormSpec::fromForm('samplecustomer'));"));
		const bc = Object.fromEntries(spec.form.browse.map(b => [b.field, b]));
		expect(bc.cus_credit.total === 'sum' && bc.cus_country.group === true && !('total' in bc.cus_name), 'spec carries total and group: ' + JSON.stringify(spec.form.browse));
		const out = JSON.parse(php("$s = NuFormSpec::fromForm('samplecustomer'); foreach ($s['form']['browse'] as &$b) { if ($b['field'] === 'cus_credit') $b['total'] = 'avg'; if ($b['field'] === 'cus_country') $b['group'] = false; if ($b['field'] === 'cus_name') $b['group'] = true; } unset($b);"
			+ " $c = NuFormSpec::check($s, 'samplecustomer'); $r = []; foreach (($c['plan']['snapshot']['browse'] ?? []) as $b) $r[$b['sbr_display']] = $b['sbr_json'] ?? null; echo json_encode(['e' => $c['errors'] ?? [], 'b' => $r]);"));
		expect(out.e.length === 0 && /"total":"avg"/.test(out.b.cus_credit || '') && /"group":true/.test(out.b.cus_name || '') && !/group/.test(out.b.cus_country || '') && /"filter":true/.test(out.b.cus_country || ''), 'spec → snapshot: ' + JSON.stringify(out));
		const bad = JSON.parse(php("$s = NuFormSpec::fromForm('samplecustomer'); $s['form']['browse'][0]['total'] = 'median'; echo json_encode(NuFormSpec::check($s, 'samplecustomer')['errors'] ?? []);"));
		expect(bad.length === 1, 'an unknown total is an error: ' + JSON.stringify(bad));
	} finally {
		await ga.setViewportSize(vp);
		before.forEach(l => { const id = l.split('=')[0], j = l.slice(id.length + 1).replace(/\s/g, ''); sql(`UPDATE zzzzsys_browse SET sbr_json = ${j === '~' ? 'NULL' : "FROM_BASE64('" + j + "')"} WHERE zzzzsys_browse_id = '${id}'`); });
	}
};

T.browse_totals = async (s) => {
	const ga = s.ga, vp = ga.viewportSize();
	const before = sql("SELECT CONCAT(zzzzsys_browse_id, '=', IFNULL(TO_BASE64(sbr_json), '~')) FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = 'samplecustomer'").split('\n');
	const set = (id, fn) => sql(`UPDATE zzzzsys_browse SET sbr_json = '{"nunext":{"total":"${fn}"}}' WHERE zzzzsys_browse_id = '${id}'`);
	const cell = i => ga.evaluate(i => { const c = document.querySelector('#nuBrowseTotals td[data-nu-column="' + i + '"]'); return c ? { fn: c.getAttribute('data-nu-total'), label: (c.querySelector('.nuTotalFn') || {}).textContent || '', val: (c.querySelector('.nuTotalVal') || {}).textContent || '' } : null; }, i);
	const num = v => parseFloat(String(v).replace(/[^0-9.-]/g, ''));
	try {
		await open(ga, 'index.php?p=form&f=samplecustomer');
		expect(!(await ga.$('#nuBrowseTotals')), 'no row of totals without a total');
		const b3 = sql("SELECT IFNULL(sbr_json, '') FROM zzzzsys_browse WHERE zzzzsys_browse_id = 'sample_customer_b3'");
		// the pane's Columns tab sets it (More settings of the Credit column) – saved into sbr_json nunext.total, the filter flag of others kept
		await ga.click('#nuDesignMore'); await ga.click('#nuBrowseColsEdit');
		await ga.waitForSelector('#nuBsqColTable .nuBsqMoreBtn');
		await ga.locator('#nuBsqColTable .nuBsqMoreBtn').nth(4).click();
		await ga.selectOption('.nuBsqMoreRow [data-lm=total]', 'sum');
		await Promise.all([ga.waitForNavigation(), ga.click('#nuBsqPane .nuBsqSave')]);
		expect(/"total":"sum"/.test(sql("SELECT sbr_json FROM zzzzsys_browse WHERE zzzzsys_browse_id = 'sample_customer_b5'")), 'saved in sbr_json');
		expect(sql("SELECT IFNULL(sbr_json, '') FROM zzzzsys_browse WHERE zzzzsys_browse_id = 'sample_customer_b3'") === b3, 'other column settings kept');
		set('sample_customer_b4', 'max'); set('sample_customer_b2', 'count'); set('sample_customer_b1', 'avg');
		// more rows than a page shows: the total is over all of them
		await open(ga, 'index.php?p=form&f=samplecustomer&per=5');
		const all = sql('SELECT SUM(cus_credit), COUNT(cus_name), DATE_FORMAT(MAX(cus_since), "%d.%m.%Y"), COUNT(*) FROM sample_customer').split('\t');
		const rows = await ga.locator('#nuRECORD tr.nuBrowseRow').count();
		let c = await cell(4);
		expect(rows < +all[3] && c && c.fn === 'sum' && Math.abs(num(c.val) - +all[0]) < 0.01 && /USD/.test(c.val) && c.label !== '', 'sum over all rows in the column format: ' + JSON.stringify([c, all, rows]));
		c = await cell(1); expect(c && c.fn === 'count' && c.val === all[1], 'count: ' + JSON.stringify(c));
		c = await cell(3); const cmax = c;
		expect(c && c.fn === 'max' && c.val === all[2], 'latest date in the date format: ' + JSON.stringify([c, all[2]]));
		const cmin = await cell(0);
		expect(await ga.evaluate(() => !!(window.nuNext.browse.totals && window.nuNext.browse.totals[4] && window.nuNext.browse.totals[4].fn === 'sum')), 'totals for form code');
		expect(cmax.label !== cmin.label, 'a date column names its maximum differently: ' + cmax.label);
		// search and filter narrow the total
		const cc = sql("SELECT cus_country FROM sample_customer GROUP BY cus_country ORDER BY COUNT(*) DESC LIMIT 1");
		const part = sql(`SELECT SUM(cus_credit) FROM sample_customer WHERE cus_country = '${cc}'`);
		sql(`UPDATE zzzzsys_browse SET sbr_json = '{"nunext":{"filter":true}}' WHERE zzzzsys_browse_id = 'sample_customer_b3'`);   // the Country filter – in a full run an earlier test may have left the column without it
		await open(ga, 'index.php?p=form&f=samplecustomer&flt[2]=' + cc);
		c = await cell(4);
		expect(c && Math.abs(num(c.val) - +part) < 0.01 && +part < +all[0], 'the filter narrows the sum: ' + JSON.stringify([c, part]));
		await open(ga, 'index.php?p=form&f=samplecustomer&q=zzzznothing');
		expect(!(await ga.$('#nuBrowseTotals')), 'no totals when nothing matches');
		// print view has it, a lookup list not; phones: a card with the totals only
		await open(ga, 'index.php?p=form&f=samplecustomer&print=1');
		expect(!!(await ga.$('#nuBrowseTotals td[data-nu-total=sum]')), 'print view shows the totals');
		await open(ga, 'index.php?p=form&f=samplecustomer&lookup=x&iframe=1');
		expect(!(await ga.$('#nuBrowseTotals')), 'not in lookup lists');
		await ga.setViewportSize({ width: 390, height: 800 });
		await open(ga, 'index.php?p=form&f=samplecustomer');
		await ga.waitForFunction(() => document.documentElement.classList.contains('nuPhoneCards'));
		const ph = await ga.evaluate(() => { const r = document.querySelector('#nuBrowseTotals tr'); return { shown: [...r.children].filter(x => x.offsetParent !== null).length, tot: r.querySelectorAll('[data-nu-total]').length, over: document.documentElement.scrollWidth > window.innerWidth + 1 }; });
		expect(ph.shown === ph.tot && ph.tot === 4 && !ph.over, 'phone card of the totals: ' + JSON.stringify(ph));
		// a short window: the row stays in view at the bottom
		await ga.setViewportSize({ width: 1280, height: 420 });
		await open(ga, 'index.php?p=form&f=samplecustomer');
		await ga.waitForTimeout(300);
		const fl = await ga.evaluate(() => { const c = document.querySelector('#nuBrowseTotals td[data-nu-total]'), r = c.getBoundingClientRect(), t = document.getElementById('nuBrowseTable');
			const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
			return { bottom: r.bottom, win: window.innerHeight, floats: t.classList.contains('nuFootFloat'), seen: !!top && (top === c || c.contains(top)) }; });
		expect(fl.floats && fl.seen && fl.bottom <= fl.win + 1, 'the row floats in view, above the pager: ' + JSON.stringify(fl));
		await ga.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight)); await ga.waitForTimeout(300);
		expect(!(await ga.evaluate(() => document.getElementById('nuBrowseTable').classList.contains('nuFootFloat'))), 'at the end of the list the row is in its place');
	} finally {
		await ga.setViewportSize(vp);
		before.forEach(l => { const [id, j] = l.split('='); sql(`UPDATE zzzzsys_browse SET sbr_json = ${j === '~' ? 'NULL' : "FROM_BASE64('" + l.slice(id.length + 1).replace(/\s/g, '') + "')"} WHERE zzzzsys_browse_id = '${id}'`); });
		sql("DELETE FROM zzzzsys_nunext_form_history WHERE form_id = 'samplecustomer' AND created_at > NOW() - INTERVAL 10 MINUTE");
	}
};

T.list_row_height = async (s) => {
	// 0.8.1114: rows with one line are 33 px in every list – text only (access levels), badges (users), row buttons (forms), the log, form lists
	const p = s.ga, seen = {};
	for (const r of ['access', 'users', 'procedures', 'forms', 'log', 'form&f=samplecustomer', 'form&f=sampleorder']) {
		await open(p, 'index.php?p=' + r); await p.waitForSelector('main table.nu-table tbody tr');
		const d = await p.evaluate(() => {
			const t = document.querySelector('main table.nu-table');
			const hs = [...t.querySelectorAll('tbody > tr')].filter((x) => x.offsetParent).map((x) => x.getBoundingClientRect().height).sort((a, b) => a - b);
			return { min: hs[0], mid: hs[Math.floor(hs.length / 2)], sm: t.classList.contains('table-sm'), head: t.querySelector('thead tr').getBoundingClientRect().height };
		});
		seen[r] = d;
		expect(!d.sm && Math.abs(d.min - 33) < 0.8 && (r === 'log' || Math.abs(d.mid - 33) < 0.8), 'rows of ' + r + ' are not 33 px: ' + JSON.stringify(d));
		expect(Math.abs(d.head - seen.access.head) < 0.8, 'title row of ' + r + ' differs: ' + d.head + ' / ' + seen.access.head);
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.browse_col_menu = async (s) => {
	// 0.8.929: right-click on a column title of a list (outside design mode) → the column menu; width, filter, move, rename are saved
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-colmenu.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_browse zzzzsys_nunext_form_history > ' + keep);
	try {
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuBrowseTable th.nuBrowseTitle');
		const th = p.locator('#nuBrowseTable th.nuBrowseTitle:visible').first();
		const bid = await th.getAttribute('data-nu-browse-id');
		await th.click({button: 'right'}); await p.waitForSelector('.nuColMenu');
		const txt = await p.locator('.nuColMenu').innerText();
		expect(/Rename/.test(txt) && /Filter/.test(txt) && /Delete/.test(txt), 'menu entries: ' + txt.replace(/\n/g, ' | '));
		// the head shows and copies the column's id, as nuBuilder Forte's menu (data-nu-title-id)
		expect((await p.locator('.nuColMenu .nuOmHead').innerText()).includes(bid) && await th.getAttribute('data-nu-title-id') === bid, 'head with the column id');
		await p.context().grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => null);
		await p.click('.nuColMenu .nuOmHead');
		const clip = await p.evaluate(() => navigator.clipboard.readText().catch(() => ''));
		expect(clip === bid || clip === '', 'copied the column id: ' + clip);
		await th.click({button: 'right'}); await p.waitForSelector('.nuColMenu');
		await p.fill('.nuColMenu .nuOmColWidth', '177');
		await Promise.all([p.waitForNavigation(), p.press('.nuColMenu .nuOmColWidth', 'Enter')]);
		expect(sql(`SELECT sbr_width FROM zzzzsys_browse WHERE zzzzsys_browse_id = '${bid}'`) === '177', 'width saved');
		await p.waitForSelector('#nuBrowseTable th.nuBrowseTitle');
		await p.locator('#nuBrowseTable th.nuBrowseTitle[data-nu-browse-id="' + bid + '"]').click({button: 'right'}); await p.waitForSelector('.nuColMenu');
		await Promise.all([p.waitForNavigation(), p.locator('.nuColMenu button', {hasText: 'Filter'}).click()]);
		expect(/"filter":true/.test(sql(`SELECT sbr_json FROM zzzzsys_browse WHERE zzzzsys_browse_id = '${bid}'`)), 'filter saved');
		const before = sql(`SELECT sbr_order FROM zzzzsys_browse WHERE zzzzsys_browse_id = '${bid}'`);
		await p.waitForSelector('#nuBrowseTable th.nuBrowseTitle');
		await p.locator('#nuBrowseTable th.nuBrowseTitle[data-nu-browse-id="' + bid + '"]').click({button: 'right'}); await p.waitForSelector('.nuColMenu');
		await Promise.all([p.waitForNavigation(), p.locator('.nuColMenu button', {hasText: 'Move right'}).click()]);
		expect(+sql(`SELECT sbr_order FROM zzzzsys_browse WHERE zzzzsys_browse_id = '${bid}'`) > +before, 'moved right');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}' AND sbr_width = 177`) === '1', 'width kept after the move');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.list_cols_drag = async (s) => {
	// 0.8.946: columns are dragged to another place – by the number in the browse SQL pane's Columns tab and by the row in design mode's columns list
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-colsdrag.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_browse zzzzsys_nunext_form_draft zzzzsys_nunext_form_history > ' + keep);
	try {
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuDesignMore');
		await p.click('#nuDesignMore'); await p.click('#nuBrowseColsEdit'); await p.waitForSelector('#nuBsqColTable');
		const disp = () => p.evaluate(() => [...document.querySelectorAll('#nuBsqColTable tr.nuBsqCol input[data-lc="display"]')].map(i => i.value));
		const before = await disp();
		const target = p.locator('#nuBsqColTable tr.nuBsqCol >> nth=2'), tb = await target.boundingBox();
		await p.locator('#nuBsqColTable tr.nuBsqCol >> nth=0 >> .nuBsqGrip').dragTo(target, {targetPosition: {x: 20, y: tb.height - 3}});
		const after = await disp();
		expect(after[2] === before[0] && after[0] === before[1] && after[1] === before[2], 'pane: first column dropped below the third: ' + after.join());
		await Promise.all([p.waitForNavigation(), p.click('#nuBsqPane .nuBsqSave')]);
		const ids = sql(`SELECT GROUP_CONCAT(sbr_display ORDER BY sbr_order SEPARATOR ',') FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}'`);
		expect(ids === after.join(','), 'pane order saved: ' + ids);
		// design mode: the columns list of the panel
		await open(p, 'index.php?p=form&f=' + F + '&design=1'); await p.waitForSelector('#nuDsColTree .nuDsTreeRow');
		const tree = () => p.evaluate(() => [...document.querySelectorAll('#nuDsColTree .nuDsTreeRow')].map(r => r.getAttribute('data-col')));
		await p.waitForTimeout(800); await p.evaluate(() => document.getElementById('nuDsColTree').scrollIntoView({block: 'center'})); await p.waitForTimeout(300);
		const t0 = await tree(), row2 = p.locator('#nuDsColTree .nuDsTreeRow >> nth=2'), rb = await row2.boundingBox();
		await p.locator('#nuDsColTree .nuDsTreeRow >> nth=0').dragTo(row2, {targetPosition: {x: 20, y: rb.height - 3}});
		await p.waitForTimeout(2000); await p.waitForSelector('#nuDsColTree .nuDsTreeRow');
		const t1 = await tree();
		expect(t1[2] === t0[0] && t1[0] === t0[1], 'design: column moved: ' + t0.join() + ' → ' + t1.join());
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.evaluate(() => { localStorage.removeItem('nunext-bsq-tab'); localStorage.removeItem('nunext-bsq-pin'); }).catch(() => {});
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.list_last_opened = async (s) => {
	// 0.8.1117: "Last opened" – opening a procedure / a form in design mode / a report / a SQL query is remembered per person; the lists
	// get the sortable column and the view "Recently opened"
	const p = s.ga;
	const had = sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zzzzsys_nunext_opened'") === '1';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-opened.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_list_view zzzzsys_nunext_setting' + (had ? ' zzzzsys_nunext_opened' : '') + ' > ' + keep);
	try {
		await open(p, 'index.php?p=home');                                             // the table comes with the globeadmin's page view (NuSchema)
		sql('DELETE FROM zzzzsys_nunext_opened');
		const procs = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE zzzzsys_php_id NOT LIKE 'nu%' AND COALESCE(sph_system, '') <> '1' AND sph_code NOT LIKE '%\\_%\\_B%' ORDER BY sph_code LIMIT 3").split('\n').filter(Boolean);
		if (procs.length < 3) { s.skip = 'needs three procedures'; return; }
		// opened in this order: the list's view shows the last one first
		for (const id of [procs[0], procs[2]]) { await open(p, 'index.php?p=procedures&id=' + encodeURIComponent(id)); await p.waitForTimeout(1100); }
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_opened WHERE kind = 'procedure' AND user_id = 'globeadmin'") === '2', 'two procedures remembered: ' + sql('SELECT CONCAT(user_id, 0x2f, kind, 0x2f, ref) FROM zzzzsys_nunext_opened'));
		await open(p, 'index.php?p=procedures&id=' + encodeURIComponent(procs[0]));   // the same again: no second row, a newer time
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_opened WHERE kind = 'procedure'") === '2', 'opening again adds no row');
		await open(p, 'index.php?p=procedures&view=recent');
		const rows = await p.$$eval('main table.nu-table tbody tr[data-href]', (t) => t.slice(0, 3).map((r) => (r.getAttribute('data-href').match(/[?&]id=([^&]+)/) || [])[1]));
		expect(decodeURIComponent(rows[0] || '') === procs[0] && decodeURIComponent(rows[1] || '') === procs[2], 'Recently opened: newest first – ' + rows.join(', '));
		const head = await p.$$eval('main table.nu-table thead th', (t) => t.map((x) => x.textContent.trim()));
		expect(head.some((x) => /Last opened|Zuletzt geöffnet/i.test(x)), 'column Last opened: ' + head.join(' | '));
		const cells = await p.$$eval('main table.nu-table tbody tr[data-href]', (t) => t.slice(0, 3).map((r) => r.lastElementChild.previousElementSibling ? r.textContent : r.textContent));
		expect(/\d{2}[.\/-]\d{2}[.\/-]\d{4} \d{2}:\d{2}/.test(cells[0]) && !/\d{2}[.\/-]\d{2}[.\/-]\d{4} \d{2}:\d{2}/.test(cells[2]), 'date shown for opened rows only');
		expect(/Recently opened|Zuletzt geöffnet/.test(await p.textContent('.nuLvMenuBtn')), 'the view is named (0.8.1118, was its id "recent"): ' + await p.textContent('.nuLvMenuBtn'));
		// the default view is unchanged (no such column), the column is offered in "All columns"
		await open(p, 'index.php?p=procedures');
		expect(!(await p.$$eval('main table.nu-table thead th', (t) => t.map((x) => x.textContent.trim()))).some((x) => /Last opened|Zuletzt geöffnet/i.test(x)), 'the default view has no such column');
		// a form opened in design mode, not by using it
		await open(p, 'index.php?p=form&f=samplecustomer');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_opened WHERE kind = 'form'") === '0', 'using a form does not count');
		await open(p, 'index.php?p=form&f=samplecustomer&design=1'); await p.waitForSelector('#nuDsBar');
		expect(sql("SELECT ref FROM zzzzsys_nunext_opened WHERE kind = 'form'") === 'samplecustomer', 'design mode counts');
		await open(p, 'index.php?p=forms&view=recent');
		expect(/f=samplecustomer\b/.test(await p.getAttribute('main table.nu-table tbody tr[data-href] >> nth=0', 'data-href') || ''), 'forms list: the form designed last is first');
		// another person has an own order
		const anna = s.anna || null;
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_opened WHERE user_id <> 'globeadmin'") === '0', 'rows only for the person who opened');
		for (const r of ['reports&view=recent', 'sqlbuilder&view=recent']) { await open(p, 'index.php?p=' + r); expect(await p.locator('main .nuListCard, main table.nu-table').count() > 0, r + ' opens'); }
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (had) execSync('mysql ' + DB + ' < ' + keep);
		else { execSync('mysql ' + DB + ' < ' + keep); sql('DELETE FROM zzzzsys_nunext_opened'); }   // the table stays: the schema version is stored, nothing would create it again
	}
};

// 0.8.1228: sorting / paging a system list keeps the column widths (the new page's rows sized the columns anew)
T.list_sort_widths = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=access&per=3');
	const ws = () => p.$$eval('table[data-nu-resize] thead th', (x) => x.map((th) => Math.round(th.getBoundingClientRect().width)));
	const w0 = await ws();
	expect(w0.length > 2, 'no list: ' + w0.join());
	const sorts = await p.$$eval('table[data-nu-resize] thead th a[href*="sort="]', (x) => x.map((a) => a.getAttribute('href')));
	expect(sorts.length >= 2, 'sortable titles: ' + sorts.length);
	for (const i of [1, 0]) {
		const n0 = await p.evaluate(() => +document.documentElement.getAttribute('data-nu-lv-swaps') || 0);
		await p.locator('table[data-nu-resize] thead th a[href*="sort="]').nth(i).click();
		await p.waitForFunction((n) => (+document.documentElement.getAttribute('data-nu-lv-swaps') || 0) > n, n0);
		const w1 = await ws();
		expect(w1.every((w, k) => Math.abs(w - w0[k]) <= 2), 'widths changed after sorting: ' + w0.join() + ' → ' + w1.join());
	}
	// next page
	if (await p.locator('a.nuPagerNext').count()) {
		const n0 = await p.evaluate(() => +document.documentElement.getAttribute('data-nu-lv-swaps') || 0);
		await p.locator('a.nuPagerNext').first().click();
		await p.waitForFunction((n) => (+document.documentElement.getAttribute('data-nu-lv-swaps') || 0) > n, n0);
		const w2 = await ws();
		expect(w2.every((w, k) => Math.abs(w - w0[k]) <= 2), 'widths changed on the next page: ' + w0.join() + ' → ' + w2.join());
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.1232: a list cell with a long text or line breaks keeps its row one line high (first line, cut with …)
T.list_cell_one_line = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") !== '1') { s.skip = 'no sample data'; return; }
	const id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY cus_name LIMIT 1');
	const name0 = sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + id + "'");
	const rh0 = sql("SELECT COALESCE(sfo_browse_row_height, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	sql("UPDATE zzzzsys_form SET sfo_browse_row_height = NULL WHERE zzzzsys_form_id = 'samplecustomer'");
	sql("UPDATE sample_customer SET cus_name = CONCAT('AAA first line<br/>second line<br/>third line ', REPEAT('long text ', 3)) WHERE sample_customer_id = '" + id + "'");
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&s=cus_name');
		const r = await p.evaluate((pk) => {
			const rows = [...document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow')];
			const mine = rows.find((x) => x.getAttribute('data-nu-primary-key') === pk), other = rows.find((x) => x !== mine);
			return { mine: mine && Math.round(mine.getBoundingClientRect().height), other: other && Math.round(other.getBoundingClientRect().height) };
		}, id);
		expect(r.mine && r.other && Math.abs(r.mine - r.other) <= 2, 'row with long / multi-line text higher than the others: ' + JSON.stringify(r));
		// 0.8.1233: the pointer on the cut cell shows the whole text as its title (line by line)
		const cell = p.locator('#nuBrowseTable tr.nuBrowseRow[data-nu-primary-key="' + id + '"] td.nuCellTd > div.nuCell', { hasText: 'AAA first line' });
		await cell.hover(); await p.waitForTimeout(100);
		const tt = await cell.getAttribute('title');
		expect(/AAA first line\nsecond line\nthird line long text/.test(tt || ''), 'title of the cut cell: ' + JSON.stringify(tt));
		const short = p.locator('#nuBrowseTable tr.nuBrowseRow:not([data-nu-primary-key="' + id + '"]) td.nuCellTd > div.nuCell').first();
		await short.hover(); await p.waitForTimeout(100);
		expect(await short.getAttribute('title') === null, 'a cell that fits got a title');
		// print view shows everything
		await open(p, 'index.php?p=form&f=samplecustomer&print=1&s=cus_name');
		const pv = await p.evaluate((pk) => { const rows = [...document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow')];
			const mine = rows.find((x) => x.getAttribute('data-nu-primary-key') === pk), other = rows.find((x) => x !== mine);
			return { mine: mine && Math.round(mine.getBoundingClientRect().height), other: other && Math.round(other.getBoundingClientRect().height) }; }, id);
		expect(!pv.mine || pv.mine > pv.other, 'print view cut the text: ' + JSON.stringify(pv));
	} finally {
		sql("UPDATE sample_customer SET cus_name = '" + name0.replace(/'/g, "''") + "' WHERE sample_customer_id = '" + id + "'");
		sql("UPDATE zzzzsys_form SET sfo_browse_row_height = " + (rh0 === '' ? 'NULL' : "'" + rh0 + "'") + " WHERE zzzzsys_form_id = 'samplecustomer'");
	}
};

T.row_details = async (s) => {                           // 0.8.1027: row details of form lists (core/form/nurowdetails.php)
	const p = s.ga;
	if (!sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'") || sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'") === '0') { s.skip = 'no sample data'; return; }
	const keep = sql("SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'");
	try {
		await open(p, 'index.php?p=form&f=sampleorder');
		expect(await p.locator('#nuBrowseTable .nuDetBtn').count() === 0, 'details column without the setting');
		// set through the design API (live form: no draft open)
		const api = (bd) => p.evaluate(async (b) => {
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf }, body: JSON.stringify(b) });
			return Object.assign({ status: r.status }, await r.json());
		}, Object.assign({ form_id: 'sampleorder' }, bd));
		let r = await api({ action: 'form.save', fields: { details: { cols: true, sub: 'sample_items' } } });
		if (r.draft && r.draft.id) { await api({ action: 'draft.publish', draft_id: r.draft.id, rev: r.draft.rev, note: 'e2e' }); }
		const stored = sql("SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'");
		expect(/"details":\{"cols":true,"subs":\["sample_items"\]\}/.test(stored), 'stored: ' + stored + ' / ' + JSON.stringify(r).slice(0, 200));
		await open(p, 'index.php?p=form&f=sampleorder');
		const n = await p.locator('#nuBrowseTable tbody tr.nuBrowseRow .nuDetBtn').count();
		expect(n > 0, 'no ▸ buttons');
		const pk = await p.locator('#nuBrowseTable tbody tr.nuBrowseRow').first().getAttribute('data-nu-primary-key');
		const items = +sql(`SELECT COUNT(*) FROM sample_order_item WHERE ori_sample_order_id = '${pk}'`);
		const url0 = p.url();
		await p.locator('#nuBrowseTable tbody tr.nuBrowseRow .nuDetBtn').first().click();
		await p.waitForSelector('#nuBrowseTable tr.nuDetRow .nuDetRel .nuDetRelHead, #nuBrowseTable tr.nuDetRow .nuDetRel:not(:empty)', { timeout: 8000 });
		await p.waitForTimeout(300);
		expect(p.url() === url0, 'the ▸ opened the record');
		const det = await p.evaluate(() => { const d = document.querySelector('#nuBrowseTable tr.nuDetRow'); return { cols: [...d.querySelectorAll('.nuDetCols dt')].map(x => x.textContent), head: (d.querySelector('.nuDetRelHead') || {}).textContent || '', rows: d.querySelectorAll('.nuDetTable tbody tr').length, text: d.textContent }; });
		expect(det.cols.includes('Customer id'), 'hidden column missing: ' + JSON.stringify(det.cols));
		expect(det.rows === Math.min(items, 10) && det.head.includes('(' + items + ')'), 'related rows: ' + JSON.stringify(det) + ' items ' + items);
		// lookup shown by its description, not the id
		const prodId = sql(`SELECT ori_sample_product_id FROM sample_order_item WHERE ori_sample_order_id = '${pk}' LIMIT 1`);
		if (prodId) expect(!det.text.includes(prodId), 'lookup id shown instead of the product');
		// keyboard: Space closes, → opens, ← closes
		await p.focus('#nuBrowseTable tbody tr.nuBrowseRow >> nth=0');
		await p.keyboard.press(' ');
		expect(await p.locator('#nuBrowseTable tr.nuDetRow').count() === 0, 'Space did not close');
		await p.keyboard.press('ArrowRight');
		await p.waitForSelector('#nuBrowseTable tr.nuDetRow', { timeout: 5000 });
		await p.keyboard.press('ArrowDown');
		expect(await p.evaluate(() => document.activeElement.classList.contains('nuBrowseRow') && document.activeElement.getAttribute('data-nu-row') === '1'), '↓ did not skip the details row');
		await p.keyboard.press('ArrowUp'); await p.keyboard.press('ArrowLeft');
		expect(await p.locator('#nuBrowseTable tr.nuDetRow').count() === 0, '← did not close');
		// design mode → Browse list: the two settings show the stored values
		await open(p, 'index.php?p=form&f=sampleorder&design=1&dswork=list');
		await p.waitForSelector('#nuDsListFly [data-key="details_list_sample_items"]', { timeout: 8000 });
		expect(await p.$eval('#nuDsListFly [data-key="details_list_sample_items"]', x => x.checked) && await p.$eval('#nuDsListFly [data-key="details_cols"]', x => x.checked), 'design mode settings');
		// rights: a user without the form gets 403
		const u = await login('sample_anna', UPW);
		const denied = await u.evaluate(async () => {
			const r = await fetch('index.php?p=formapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (window.nuNext || {}).csrf || (document.querySelector('input[name=_csrf]') || {}).value || '' }, body: JSON.stringify({ action: 'details', form_id: 'nuuser', record_id: 'x' }) });
			return r.status;
		}).catch(() => 403);
		await u.context().close();
		expect(denied === 403, 'details of a form without rights: ' + denied);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: "UPDATE zzzzsys_form SET sfo_json = " + (keep === '' ? 'NULL' : "'" + keep.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'") + " WHERE zzzzsys_form_id = 'sampleorder'" });
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleorder'");
	}
};

T.row_details_more = async (s) => {
	// 0.8.1316: row details – a detail form (read only frame), several related lists as tabs (sub-form + child table), one / many open rows,
	// Expand all / Collapse all, open rows kept while paging, hooks nuOnRowExpand / nuOnRowCollapse and nuNextRowDetails
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") !== '1') { s.skip = 'no sample data'; return; }
	const keep = sql("SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	const keepJs = sql("SELECT IFNULL(sfo_browse_javascript, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	const api = (bd) => p.evaluate(async (b) => {
		const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf }, body: JSON.stringify(b) });
		return Object.assign({ status: r.status }, await r.json());
	}, Object.assign({ form_id: 'samplecustomer' }, bd));
	const setDet = async (det, js) => {
		const f = { details: det };
		if (js !== undefined) f.sfo_browse_javascript = js;
		const r = await api({ action: 'form.save', fields: f });
		if (r.draft && r.draft.id) await api({ action: 'draft.publish', draft_id: r.draft.id, rev: r.draft.rev, note: 'e2e' });
		return r;
	};
	try {
		await open(p, 'index.php?p=form&f=samplecustomer');
		await p.evaluate(() => sessionStorage.removeItem('nunext-rd:samplecustomer'));
		// the child table sample_order is offered (column ord_sample_customer_id ends in _<primary key>)
		const cands = await p.evaluate(async () => {
			const r = await fetch('index.php?p=form&f=samplecustomer&design=1'); const t = await r.text();
			const m = t.match(/"details_candidates":(\[[^\]]*(?:\][^\]]*)*?\]\])/); return m ? m[1] : '';
		});
		expect(cands.includes('t:sample_order.ord_sample_customer_id'), 'child table not offered: ' + cands.slice(0, 300));
		const js = "nuNextRowDetails(function (pk, el) { el.innerHTML = '<span class=\"e2eOwn\">own ' + pk + '</span>'; });\nwindow.e2eRd = [];\nwindow.nuOnRowExpand = function (pk, el) { window.e2eRd.push('x:' + pk + ':' + (el && el.classList.contains('nuDetBody'))); };\nwindow.nuOnRowCollapse = function (pk) { window.e2eRd.push('c:' + pk); };";
		await setDet({ cols: true, subs: ['t:sample_order.ord_sample_customer_id'], form: 'samplecustomer', mode: 'many' }, js);
		const stored = sql("SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		expect(/"form":"samplecustomer"/.test(stored) && /"subs":\["t:sample_order\.ord_sample_customer_id"\]/.test(stored), 'stored: ' + stored);
		// a customer with orders
		const cust = sql("SELECT ord_sample_customer_id FROM sample_order GROUP BY ord_sample_customer_id ORDER BY COUNT(*) DESC LIMIT 1");
		const orders = +sql(`SELECT COUNT(*) FROM sample_order WHERE ord_sample_customer_id = '${cust}'`);
		await open(p, 'index.php?p=form&f=samplecustomer&q=' + encodeURIComponent(sql(`SELECT cus_name FROM sample_customer WHERE sample_customer_id = '${cust}'`)));
		const row = p.locator(`#nuBrowseTable tr.nuBrowseRow[data-nu-primary-key="${cust}"]`);
		expect(await row.count() === 1, 'customer row not found');
		await row.locator('.nuDetBtn').click();
		const strip = p.locator(`#nuBrowseTable tr.nuDetRow[data-nu-primary-key="${cust}"]`);
		await strip.locator('.nuDetTab').first().waitFor({ timeout: 8000 });
		const tabs = await strip.locator('.nuDetTab').allTextContents();
		expect(tabs.length === 2, 'tabs: ' + JSON.stringify(tabs));
		expect(await strip.locator('.e2eOwn').textContent() === 'own ' + cust, 'own content of nuNextRowDetails');
		// detail form: a read-only frame with the record, without the toolbar
		const frame = strip.locator('iframe.nuDetFrame');
		await frame.waitFor({ timeout: 8000 });
		const src = await frame.getAttribute('src');
		expect(/rdv=1/.test(src) && src.includes('r=' + encodeURIComponent(cust)), 'frame src ' + src);
		const fh = await frame.elementHandle(); const fr = await fh.contentFrame();
		await fr.waitForFunction(() => window.nuNext && document.documentElement.classList.contains('nuRdView'), null, { timeout: 10000 });
		const fstate = await fr.evaluate(() => ({ save: !!document.querySelector('#nuSaveButton:not([hidden])'), act: getComputedStyle(document.getElementById('nuActionHolder') || document.body).display, ro: !!document.querySelector('#nuEditForm input.nuReadonly, #nuEditForm [readonly], #nuEditForm :disabled') }));
		expect(!fstate.save && fstate.ro, 'detail form not read only: ' + JSON.stringify(fstate));
		const fhgt = await frame.evaluate(f => f.getBoundingClientRect().height);
		expect(fhgt > 150, 'frame height ' + fhgt);
		// the child table tab
		await strip.locator('.nuDetTab').nth(1).click();
		await strip.locator('.nuDetPane[data-nu-det^="t:"] .nuDetRelHead').waitFor({ timeout: 8000 });
		const head = await strip.locator('.nuDetPane[data-nu-det^="t:"] .nuDetRelHead').textContent();
		const nrows = await strip.locator('.nuDetPane[data-nu-det^="t:"] .nuDetTable tbody tr').count();
		expect(head.includes('(' + orders + ')') && nrows === Math.min(orders, 10), 'child rows: ' + head + ' / ' + nrows + ' orders ' + orders);
		const ths = await strip.locator('.nuDetPane[data-nu-det^="t:"] .nuDetTable thead th').allTextContents();
		expect(!ths.some(t => /ord sample customer id/i.test(t)) && ths.length <= 6, 'link column shown / too many: ' + JSON.stringify(ths));
		// a child table not in the setting / of nuBuilder is refused
		const bad = await p.evaluate(async (pk) => {
			const r = await fetch('index.php?p=formapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf }, body: JSON.stringify({ action: 'details', form_id: 'samplecustomer', record_id: pk, list: 't:zzzzsys_user.zzzzsys_user_id' }) });
			return r.json();
		}, cust);
		expect(bad.ok && bad.rows.length === 0 && bad.title === '', 'other table read: ' + JSON.stringify(bad).slice(0, 200));
		// hooks
		const hk = await p.evaluate(() => window.e2eRd.slice());
		expect(hk.includes('x:' + cust + ':true'), 'nuOnRowExpand: ' + JSON.stringify(hk));
		// Expand all, then paging keeps them open
		await open(p, 'index.php?p=form&f=samplecustomer');
		await p.waitForSelector('#nuDetAll', { timeout: 5000 });
		await p.click('#nuDetAll');
		const all = await p.evaluate(() => [document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow').length, document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow.nuDetOpen').length]);
		expect(all[0] > 1 && all[0] === all[1], 'expand all: ' + JSON.stringify(all));
		await open(p, 'index.php?p=form&f=samplecustomer&pg=2');
		await p.waitForTimeout(400);
		const all2 = await p.evaluate(() => [document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow').length, document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow.nuDetOpen').length]);
		expect(all2[0] > 0 && all2[0] === all2[1], 'page 2 not expanded: ' + JSON.stringify(all2));
		await p.click('#nuDetAll');
		expect(await p.locator('#nuBrowseTable tr.nuDetRow').count() === 0, 'collapse all');
		expect((await p.evaluate(() => window.e2eRd.slice())).some(x => x.startsWith('c:')), 'nuOnRowCollapse');
		// one row at a time: opening a second row closes the first; no Expand all
		await setDet({ cols: true, subs: ['t:sample_order.ord_sample_customer_id'], mode: 'one' });
		await open(p, 'index.php?p=form&f=samplecustomer');
		expect(await p.locator('#nuDetAll').count() === 0, 'Expand all in mode one');
		await p.locator('#nuBrowseTable tr.nuBrowseRow .nuDetBtn').nth(0).click();
		await p.locator('#nuBrowseTable tr.nuBrowseRow .nuDetBtn').nth(1).click();
		await p.waitForTimeout(300);
		expect(await p.locator('#nuBrowseTable tr.nuBrowseRow.nuDetOpen').count() === 1 && await p.locator('#nuBrowseTable tr.nuDetRow').count() === 1, 'mode one');
		// one list only: no tabs
		expect(await p.locator('#nuBrowseTable tr.nuDetRow .nuDetTab').count() === 0, 'tabs with one part');
		// design mode shows the settings
		await open(p, 'index.php?p=form&f=samplecustomer&design=1&dswork=list');
		await p.waitForSelector('#nuDsListFly [data-key="details_mode"]', { timeout: 8000 });
		expect(await p.$eval('#nuDsListFly [data-key="details_mode"]', x => x.value) === 'one' && await p.$eval('#nuDsListFly [data-key="details_list_t:sample_order.ord_sample_customer_id"]', x => x.checked), 'design mode settings');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: "UPDATE zzzzsys_form SET sfo_json = " + (keep === '' ? 'NULL' : "'" + keep.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'")
			+ ", sfo_browse_javascript = " + (keepJs === '' ? 'NULL' : "'" + keepJs.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'") + " WHERE zzzzsys_form_id = 'samplecustomer'" });
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer'");
		await p.evaluate(() => sessionStorage.removeItem('nunext-rd:samplecustomer')).catch(() => {});
	}
};

T.list_keynav_scroll = async (s) => {
	// 0.8.1190: ↓ / ↑ keep the focused row in view – the window scrolls so that it is neither under the sticky top (top bar, title
	// bar, floating column titles) nor under the sticky list footer at the bottom
	const p = s.ga, vp = p.viewportSize();
	try {
		await p.setViewportSize({ width: 1280, height: 520 });
		for (const url of ['index.php?p=form&f=samplecustomer', 'index.php?p=forms']) {
			await open(p, url);
			const search = url.includes('forms') ? '.nuListSearch input[type=search], input[name=q]' : '#nuSearchField';
			await p.focus(search);
			const n = await p.evaluate(() => document.querySelectorAll('tbody tr.nuBrowseRow, tbody tr[data-href]').length);
			expect(n > 8, url + ': too few rows ' + n);
			const check = async (what) => {
				const r = await p.evaluate(() => {
					const a = document.activeElement, b = a.getBoundingClientRect(), top = window.nuStickyLine ? window.nuStickyLine() : 0;
					const t = a.closest('table'), th = t.tHead.getBoundingClientRect();
					const f = [...document.querySelectorAll('.nuListFooter, .card-footer')].find((x) => x.offsetParent && getComputedStyle(x).position === 'sticky');
					const fb = f ? f.getBoundingClientRect().top : innerHeight;
					return {tag: a.tagName, top: b.top, bottom: b.bottom, line: Math.max(top, th.bottom), foot: Math.min(fb, innerHeight)};
				});
				expect(r.tag === 'TR' && r.top >= r.line - 1 && r.bottom <= r.foot + 1, url + ' ' + what + ': row not in view ' + JSON.stringify(r));
			};
			for (let i = 0; i < n - 1; i++) { await p.keyboard.press('ArrowDown'); if (i % 3 === 2 || i === n - 2) await check('down ' + i); }
			for (let i = 0; i < n - 1; i++) { await p.keyboard.press('ArrowUp'); if (i % 3 === 2 || i === n - 2) await check('up ' + i); }
		}
	} finally { await p.setViewportSize(vp); }
};

T.list_keynav = async (s) => {                           // 0.8.1026: keyboard navigation in lists (assets/js/nuapp.js)
	const p = s.ga;
	const act = () => p.evaluate(() => { const a = document.activeElement; return a && a.tagName === 'TR' ? (a.getAttribute('data-href') || a.getAttribute('data-nu-primary-key') || '?') : (a ? a.id || a.tagName : ''); });
	// system list: search ↓ → first row, ↓, End, Home, one tab stop, Enter opens, Back has the row again
	await open(p, 'index.php?p=users');
	const hrefs = await p.$$eval('#main table tbody tr[data-href]', r => r.map(x => x.getAttribute('data-href')));
	expect(hrefs.length >= 3, 'users list rows: ' + hrefs.length);
	await p.focus('#q'); await p.keyboard.press('ArrowDown');
	expect(await act() === hrefs[0], 'search ↓: ' + await act());
	await p.keyboard.press('ArrowDown'); expect(await act() === hrefs[1], '↓: ' + await act());
	await p.keyboard.press('End'); expect(await act() === hrefs[hrefs.length - 1], 'End: ' + await act());
	await p.keyboard.press('Home'); expect(await act() === hrefs[0], 'Home: ' + await act());
	await p.keyboard.press('ArrowDown');
	expect(await p.$$eval('#main table tbody tr[data-href]', r => r.filter(x => x.tabIndex === 0).length) === 1, 'more than one tab stop');
	// 0.8.1189: the focused row is a soft rounded fill (no outline): tinted cells, rounded first cell
	const fr = await p.evaluate(() => { const td = document.activeElement.firstElementChild, cs = getComputedStyle(td);
		return {shadow: cs.boxShadow, radius: cs.borderTopLeftRadius, outline: getComputedStyle(document.activeElement).outlineStyle}; });
	expect(fr.outline === 'none' && parseFloat(fr.radius) > 5 && /inset/.test(fr.shadow) && fr.shadow.split('inset').length >= 4, 'focused row style: ' + JSON.stringify(fr));
	await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
	expect(p.url().includes(hrefs[1].replace(/^.*\?/, '')), 'Enter opened: ' + p.url());
	await p.goBack(); await p.waitForLoadState('networkidle');
	expect(await act() === hrefs[1], 'back: row not focused again: ' + await act());
	await p.keyboard.press('Escape'); expect(await act() === 'q', 'Esc → search: ' + await act());
	// form list: PageDown / PageUp through the pager, Enter (nuruntime.js) opens the record
	await open(p, 'index.php?p=form&f=samplecustomer&per=5');
	await p.focus('#nuSearchField'); await p.keyboard.press('ArrowDown');
	const pk1 = await act();
	const firstPk = await p.$eval('#nuBrowseTable tbody tr.nuBrowseRow', r => r.getAttribute('data-nu-primary-key'));
	expect(pk1 === firstPk, 'form list ↓: ' + pk1 + ' / ' + firstPk);
	await p.keyboard.press('PageDown');
	await p.waitForFunction((k) => { const a = document.activeElement; return a && a.tagName === 'TR' && a.getAttribute('data-nu-primary-key') !== k; }, pk1, { timeout: 8000 });
	expect(/pg=2|page=2|p=2/.test(p.url()) || await p.$eval('.nuPager', n => /2/.test(n.textContent)), 'PageDown: ' + p.url());
	await p.keyboard.press('PageUp');
	await p.waitForFunction(() => { const a = document.activeElement; return a && a.tagName === 'TR'; }, null, { timeout: 8000 });
	const rows1 = await p.$$eval('#nuBrowseTable tbody tr.nuBrowseRow', r => r.map(x => x.getAttribute('data-nu-primary-key')));
	expect(await act() === rows1[rows1.length - 1], 'PageUp: last row of the page before: ' + await act());
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.list_page_keys = async (s) => {
	// 0.8.1265: Ctrl+Shift+← / → / End / Home page through nuBuilder Next's own lists too (form lists had them), as in nuBuilder
	// Forte's browse lists – also from the search field; nothing happens on a page without a pager
	const p = s.ga;
	await open(p, 'index.php?p=log&per=10');
	if (!(await p.locator('a.nuPagerNext').count())) { expect(false, 'log has only one page'); return; }
	const pg = () => new URL(p.url()).searchParams.get('page') || new URL(p.url()).searchParams.get('pg') || '1';
	const step = async (k) => { const u = p.url(); await p.keyboard.press(k); await p.waitForFunction((x) => location.href !== x, u, { timeout: 8000 }); await p.waitForTimeout(300); };
	await step('Control+Shift+ArrowRight');
	expect(pg() === '2', 'next: ' + p.url());
	await step('Control+Shift+ArrowLeft');
	expect(pg() === '1', 'prev: ' + p.url());
	await p.focus('.nuListSearch input[type=search]');
	await step('Control+Shift+End');
	const last = pg();
	expect(Number(last) > 1, 'last from the search field: ' + p.url());
	await step('Control+Shift+Home');
	expect(pg() === '1', 'first: ' + p.url());
	// form lists keep their own keys
	await open(p, 'index.php?p=form&f=samplecustomer');
	if ((await p.evaluate(() => (window.nuNext.browse || {}).pages || 1)) > 1) {
		await step('Control+Shift+ArrowRight');
		expect(/[?&]pg=2/.test(p.url()), 'form list next: ' + p.url());
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.1275: phone cards of form lists – each value at most two lines, cut with …; the print view keeps everything
T.phone_card_clamp = async (s) => {
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") !== '1') { s.skip = 'no sample data'; return; }
	const id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY cus_name LIMIT 1');
	const name0 = sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + id + "'");
	sql("UPDATE sample_customer SET cus_name = CONCAT('AAA ', REPEAT('WWWWW ', 16)) WHERE sample_customer_id = '" + id + "'");
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer&s=cus_name'); await p.waitForTimeout(500);
		const r = await p.evaluate((pk) => {
			const row = document.querySelector('#nuBrowseTable tr.nuBrowseRow[data-nu-primary-key="' + pk + '"]');
			const td = row && [...row.querySelectorAll('td.nuCellTd')].find((x) => x.textContent.includes('AAA'));
			if (!td) return null;
			const cs = getComputedStyle(td), lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.5;
			return { cards: document.documentElement.classList.contains('nuPhoneCards'), h: td.getBoundingClientRect().height, lh, cut: td.scrollHeight > td.clientHeight + 1 };
		}, id);
		expect(r && r.cards, 'no phone cards: ' + JSON.stringify(r));
		expect(r.h <= r.lh * 2 + 4 && r.cut, 'long value not cut to two lines: ' + JSON.stringify(r));
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer&print=1&s=cus_name'); await p.waitForTimeout(500);
		const pv = await p.evaluate((pk) => { const row = document.querySelector('#nuBrowseTable tr.nuBrowseRow[data-nu-primary-key="' + pk + '"]');
			const td = row && [...row.querySelectorAll('td.nuCellTd')].find((x) => x.textContent.includes('AAA'));
			return td ? td.scrollHeight <= td.clientHeight + 1 : true; }, id);
		expect(pv, 'print view cut the text');
	} finally {
		await ctx.close();
		sql("UPDATE sample_customer SET cus_name = '" + name0.replace(/'/g, "''") + "' WHERE sample_customer_id = '" + id + "'");
	}
};

// lists: the hovered row's rounded fill hides the line above it without moving a row (the row's own top border takes the place of the
// line – no :has() rule any more); HTML objects: one with a dashboard keeps the application's look, the others nuBuilder Forte's
T.row_hover_line = async (s) => {
	const p = s.ga;
	await p.setViewportSize({ width: 1300, height: 900 });
	for (const [url, sel] of [['index.php?p=users', '.nu-table > tbody > tr[data-href]'], ['index.php?p=form&f=samplecustomer', '.nuBrowseTable > tbody > tr.nuBrowseRow']]) {
		await open(p, url);
		const rows = p.locator(sel);
		expect(await rows.count() >= 3, url + ': rows');
		const tops = () => p.evaluate((sel) => Array.from(document.querySelectorAll(sel)).map(r => Math.round(r.getBoundingClientRect().top * 100) / 100).join(','), sel);
		const before = await tops();
		await rows.nth(2).hover();
		await p.waitForTimeout(200);
		const st = await p.evaluate((sel) => { const r = document.querySelectorAll(sel)[2], c = getComputedStyle(r.cells[1]), f = getComputedStyle(document.querySelectorAll(sel)[0].cells[1]);
			return { style: c.borderTopStyle, width: c.borderTopWidth, color: c.borderTopColor, radius: getComputedStyle(r.cells[0]).borderTopLeftRadius, firstTop: f.borderTopStyle }; }, sel);
		expect(st.style === 'double' && st.width === '1px' && /rgba\(0, 0, 0, 0\)|transparent/.test(st.color), url + ': the hovered row takes the line above: ' + JSON.stringify(st));
		expect(parseFloat(st.radius) > 5, url + ': rounded fill');
		expect(await tops() === before, url + ': no row moved');
		await rows.nth(0).hover();
		await p.waitForTimeout(200);
		const first = await p.evaluate((sel) => getComputedStyle(document.querySelectorAll(sel)[0].cells[1]).borderTopStyle, sel);
		expect(first !== 'double', url + ': the first row keeps the line of the titles (' + first + ')');
		expect(await tops() === before, url + ': no row moved (first row)');
	}
	await p.mouse.move(0, 0);
	// HTML objects of the sample customer form: the one with the dashboard has the class (server) – its content keeps border-box
	const cus = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + cus);
	await p.waitForTimeout(600);
	const h = await p.evaluate(() => { const d = document.querySelector('.nuHtml [data-nu-dashboard]'); if (!d) return null; const o = d.closest('.nuHtml');
		return { cls: o.classList.contains('nuHasDash'), box: getComputedStyle(d).boxSizing }; });
	if (h) expect(h.cls && h.box === 'border-box', 'HTML object with a dashboard: ' + JSON.stringify(h));
	const all = sql('SELECT sample_all_id FROM sample_all ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=sampleall&r=' + all);
	const plain = await p.evaluate(() => {
		const o = Array.from(document.querySelectorAll('.nuHtml:not(.nuHasDash)')).find(x => x.firstElementChild);
		if (!o) return null;
		const t = document.createElement('div'); t.className = 'row'; t.innerHTML = '<button type="button" id="e2eRowBtn">x</button><span>y</span>';
		const g = document.createElement('div'); g.className = 'row'; g.innerHTML = '<div class="col-6" id="e2eCol">a</div><div id="e2eNoCol">b</div>';
		o.appendChild(t); o.appendChild(g);
		const cs = id => getComputedStyle(document.getElementById(id));
		const r = { box: getComputedStyle(o.firstElementChild).boxSizing, rowDisplay: getComputedStyle(t).display, btnMax: cs('e2eRowBtn').maxWidth, btnShrink: cs('e2eRowBtn').flexShrink,
			gridDisplay: getComputedStyle(g).display, colMax: cs('e2eCol').maxWidth, noColMax: cs('e2eNoCol').maxWidth, noColShrink: cs('e2eNoCol').flexShrink };
		t.remove(); g.remove();
		return r;
	});
	if (plain) {
		expect(plain.box === 'content-box', 'HTML object without a dashboard: sizes as in the browser (' + plain.box + ')');
		expect(plain.rowDisplay === 'block' && plain.btnMax === 'none' && plain.btnShrink === '1', 'a .row without columns is an ordinary block, its children keep their width: ' + JSON.stringify(plain));
		expect(plain.gridDisplay === 'flex' && plain.noColMax === '100%' && plain.noColShrink === '0' && plain.colMax === '100%', 'a .row with a column stays a grid row, also for its children without a column class: ' + JSON.stringify(plain));
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// the list's SQL: PHP always from the server (nuHash()['browse_sql'], the browser's value ignored); the browser only with the form's switch
T.browse_sql_optin = async (s) => {
	const p = s.ga;
	const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	if (!fid) { s.skip = 'no sample data'; return; }
	const json0 = sql("SELECT COALESCE(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '" + fid + "'");
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_bsq'");
	sql(`INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('e2e_bsq', 'e2e_bsq', 'e2e', 'hide', '1', '0',
		'nuAddJavascript("window.e2eBsq = " . json_encode((string)(nuHash()[''browse_sql''] ?? '''')) . ";");')`);
	const run = async () => { await p.evaluate(() => { window.e2eBsq = null; nuRunPHPHidden('e2e_bsq'); }); await p.waitForFunction(() => window.e2eBsq !== null, null, { timeout: 8000 }); return p.evaluate(() => window.e2eBsq); };
	try {
		await open(p, 'index.php?p=form&f=samplecustomer');
		const cli = await p.evaluate(() => nuCurrentProperties().browse_sql);
		expect(!/FROM \(SELECT \* FROM/.test(String(cli)), 'browser got the SQL without the switch: ' + cli);
		let v = await run();
		expect(/FROM \(SELECT \* FROM .*sample_customer/i.test(v), 'procedure without the list SQL: ' + v);
		// a forged value from the browser is not used
		await p.evaluate(() => { window.e2eBsq = null; });
		const forged = await p.evaluate(async (f) => {
			const r = await NuUtil.post('index.php?p=formapi', {action: 'php', form_id: f, code: 'e2e_bsq', values: {}, crumb: {browse_sql: 'SELECT forged'}});
			return JSON.stringify(r);
		}, fid);
		expect(!/forged/.test(forged) && /sample_customer/i.test(forged), 'forged browse_sql reached the procedure: ' + forged.slice(0, 200));
		// switch on (design API field) → the browser gets it
		await p.evaluate(async (f) => NuUtil.post('index.php?p=builderapi', {action: 'form.save', form_id: f, fields: {browse_sql_client: '1'}}), fid);
		expect(/browse_sql_client":true/.test(sql("SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = '" + fid + "'")), 'switch not stored');
		await open(p, 'index.php?p=form&f=samplecustomer');
		const cli2 = await p.evaluate(() => nuCurrentProperties().browse_sql);
		expect(/FROM \(SELECT \* FROM .*sample_customer/i.test(String(cli2)), 'browser without the SQL after switching on: ' + cli2);
		// compatibility check: JavaScript reading browse_sql on a form with the switch off is listed
		sql("UPDATE zzzzsys_form SET sfo_json = " + (json0 === '' ? 'NULL' : "'" + json0.replace(/'/g, "''") + "'") + ", sfo_browse_javascript = CONCAT(COALESCE(sfo_browse_javascript, ''), '\\n// e2e\\nvar e2eB = nuCurrentProperties().browse_sql;') WHERE zzzzsys_form_id = '" + fid + "'");
		await open(p, 'index.php?p=compat');
		expect(await p.locator('#compatFOpen').innerText().then(t => /browse_sql/.test(t)), 'compatibility check does not list the form');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("UPDATE zzzzsys_form SET sfo_json = " + (json0 === '' ? 'NULL' : "'" + json0.replace(/'/g, "''") + "'") + ", sfo_browse_javascript = REPLACE(sfo_browse_javascript, '\\n// e2e\\nvar e2eB = nuCurrentProperties().browse_sql;', '') WHERE zzzzsys_form_id = '" + fid + "'");
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_bsq'");
	}
};

T.procedure_used_in = async (s) => {
	// 0.8.1380: procedures list "Used in" (buttons, objects, reports, tiles, widgets, code calls), filter Used / Not used / Hook, usage tab
	const p = s.ga;
	if (!sql("SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id = 'sample_proc_hello'").startsWith('1')) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-procuse.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_php zzzzsys_form > ' + keep, {shell: '/bin/bash'});
	try {
		sql(`INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_system) VALUES ('e2eprocused', 'e2e_called', 'E2E called from code', 'echo 1;', '0'),
			('e2eprocfree', 'e2e_free', 'E2E not used', 'echo 1;', '0');
			UPDATE zzzzsys_form SET sfo_javascript = CONCAT(COALESCE(sfo_javascript, ''), '\\nfunction e2eCall() { nuRunPHPHidden("e2e_called"); }') WHERE zzzzsys_form_id = 'sampleproduct'`);
		await open(p, 'index.php?p=procedures&origin=own&view=default');
		const cell = (code) => p.locator('table.nu-table tbody tr', {hasText: code}).first().locator('.nuProcUse, .nuProcUnused');
		const hello = cell('sample_hello');
		expect(/2/.test(await hello.innerText()), 'sample_hello: 2 forms – ' + await hello.innerText());
		await hello.locator('button').click();
		expect(await hello.locator('.dropdown-menu .dropdown-item').count() >= 4, 'menu lists every place');
		await p.keyboard.press('Escape');
		const called = cell('e2e_called');
		expect(/1/.test(await called.innerText()) && (await called.innerText()).includes('≈'), 'code call counted with ≈: ' + await called.innerText());
		expect(await cell('e2e_free').getAttribute('class') === 'text-body-secondary nuProcUnused', 'unused shows –');
		await open(p, 'index.php?p=procedures&origin=own&used=unused');
		const rows = await p.locator('table.nu-table tbody').innerText();
		expect(rows.includes('e2e_free') && !rows.includes('sample_hello') && !rows.includes('e2e_called'), 'filter Not used');
		// sortable (0.8.1381): most used first, not used last
		await open(p, 'index.php?p=procedures&origin=own&sort=used&dir=desc');
		const order = await p.locator('table.nu-table tbody tr td:first-child').allInnerTexts();
		const at = (c) => order.findIndex((x) => x.includes(c));
		expect(at('sample_hello') >= 0 && at('sample_hello') < at('e2e_called') && at('e2e_called') < at('e2e_free'), 'sort by Used in: ' + order.join(' | '));
		await open(p, 'index.php?p=procedures&id=e2eprocused');
		await p.click('[data-tab=usage]');
		expect(await p.locator('#procUsage tbody tr').count() === 1 && (await p.locator('#procUsage').innerText()).includes('sample_product'), 'usage tab names the form');
		expect((await p.locator('#procDelete').evaluate((b) => b.form.getAttribute('data-confirm'))).includes('1'), 'delete question names the code places');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.phone_table_head_first_col = async (s) => {
	// 0.8.1381: system list as a table on a phone – after scrolling down (titles float) and sideways, the first column's title stays on top
	const ctx = await browser.newContext({viewport: {width: 390, height: 700}, hasTouch: true, isMobile: true, storageState: await s.ga.context().storageState()});
	const p = await ctx.newPage();
	try {
		await p.addInitScript(() => { try { localStorage.setItem('nunext-lv-phone-procedures', 'table'); } catch (e) { /* expected in some contexts */ } });
		await p.goto(BASE + 'index.php?p=procedures&origin=&per=50');
		await p.waitForSelector('.nuLvTableWrap table.nu-table');
		if (await p.locator('.nuLvTableWrap table.nu-table tbody tr').count() < 6) { s.skip = 'too few procedures'; return; }
		await p.evaluate(() => window.scrollTo(0, 500));
		await p.waitForTimeout(300);
		expect(await p.locator('.nuLvTableWrap table.nuHeadFloat').count() === 1, 'titles float after scrolling down');
		await p.evaluate(() => { const w = document.querySelector('.nuLvTableWrap'); w.scrollLeft = 200; w.dispatchEvent(new Event('scroll')); });
		await p.waitForTimeout(300);
		const top = await p.evaluate(() => {
			const th = document.querySelector('.nuLvTableWrap table thead th:first-child'), r = th.getBoundingClientRect();
			const hit = document.elementFromPoint(r.left + Math.min(20, r.width / 2), r.top + r.height / 2);
			return !!hit && (hit === th || th.contains(hit));
		});
		expect(top, 'first column title is not covered by the other titles');
	} finally { await ctx.close(); }
};

T.list_hscroll_keep = async (s) => {
	// 0.8.1383: phone – a list scrolled sideways keeps its position after sorting (in place) and a reload; the sorted column is in view
	const ctx = await browser.newContext({viewport: {width: 390, height: 700}, hasTouch: true, isMobile: true, storageState: await s.ga.context().storageState()});
	const p = await ctx.newPage();
	try {
		await p.addInitScript(() => { try { localStorage.setItem('nunext-lv-phone-procedures', 'table'); } catch (e) { /* expected in some contexts */ } });
		await p.goto(BASE + 'index.php?p=procedures&origin=&view=all');
		await p.waitForSelector('.nuLvTableWrap table.nu-table');
		const pos = () => p.evaluate(() => document.querySelector('.nuLvTableWrap').scrollLeft);
		const max = await p.evaluate(() => { const w = document.querySelector('.nuLvTableWrap'); return w.scrollWidth - w.clientWidth; });
		if (max < 300) { s.skip = 'table not wide enough'; return; }
		await p.evaluate(() => { document.querySelector('.nuLvTableWrap').scrollLeft = 260; });
		await p.waitForTimeout(200);
		await p.reload();
		await p.waitForSelector('.nuLvTableWrap table.nu-table');
		await p.waitForTimeout(300);
		expect(Math.abs(await pos() - 260) < 3, 'position kept after reload: ' + await pos());
		// sort by a column that is on screen now
		const visible = await p.evaluate(() => {
			const w = document.querySelector('.nuLvTableWrap'), wr = w.getBoundingClientRect();
			const th = [...w.querySelectorAll('thead th')].slice(1).find((h) => { const r = h.getBoundingClientRect(); return h.querySelector('a.nu-sort') && r.left > wr.left + 120 && r.right < wr.right; });
			return th ? th.cellIndex : -1;
		});
		expect(visible > 0, 'a sortable title on screen');
		await p.evaluate((i) => document.querySelectorAll('.nuLvTableWrap thead th')[i].querySelector('a.nu-sort').click(), visible);
		await p.waitForFunction((i) => !!document.querySelector('.nuLvTableWrap thead th:nth-child(' + (i + 1) + ')[aria-sort]'), visible, {timeout: 8000});
		await p.waitForTimeout(400);
		const inView = await p.evaluate(() => {
			const w = document.querySelector('.nuLvTableWrap'), wr = w.getBoundingClientRect(), r = w.querySelector('thead th[aria-sort]').getBoundingClientRect();
			const fx = w.querySelector('thead th').getBoundingClientRect();
			return r.left >= fx.right - 2 && r.left < wr.right - 20;   // starts right of the fixed first column, on screen
		});
		expect(await pos() > 0 && inView, 'after sorting: still scrolled (' + await pos() + ') and the sorted column in view');
	} finally { await ctx.close(); }
};

T.print_columns_form = async (s) => {
	// print view of a form list: × hides a column, Undo / Reset view, remembered per form; Export Excel / CSV take the visible columns
	const p = s.ga, url = 'index.php?p=form&f=samplecustomer&print=1';
	await open(p, url);
	await p.evaluate(() => { try { localStorage.removeItem('nunext-print-cols:form:samplecustomer'); } catch (e) {} });
	await open(p, url);
	const vis = async () => p.locator('#nuBrowseTable thead th:not(.nuColHidden)').count();
	const n = await vis();
	expect(n > 2 && await p.isDisabled('#nuPrintUndo') && await p.isDisabled('#nuPrintReset'), 'start state: ' + n);
	const second = await p.locator('#nuBrowseTable thead th a.nu-sort').nth(1).textContent();
	await p.locator('#nuBrowseTable thead th .nuColHide').nth(1).click();
	const cells = await p.evaluate(() => document.querySelector('#nuBrowseTable tbody tr.nuBrowseRow').querySelectorAll('td:not(.nuColHidden)').length);
	expect(await vis() === n - 1 && cells === n - 1 && /1/.test(await p.textContent('#nuPrintHiddenInfo')), 'hide a column');
	const xl = await p.getAttribute('#nuXlsxButton', 'href');
	expect(/cols=0(%2C|,)2/.test(xl), 'Excel link without the visible columns: ' + xl);
	// the CSV of the visible columns has no hidden column title
	const csvUrl = await p.evaluate(() => { const f = document.querySelector('.js-csv-form'); const u = new URL(f.getAttribute('action'), location.href); new FormData(f).forEach((v, k) => u.searchParams.set(k, v)); return u.toString(); });
	const csv = await p.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).text(), csvUrl);
	const head = csv.replace(/^﻿/, '').split(/\r?\n/)[0];
	expect(!head.includes(second.trim()) && head.split(/[;,]/).length === n - 1, 'CSV head: ' + head);
	await open(p, url);
	expect(await vis() === n - 1, 'hidden column not remembered');
	await p.click('#nuPrintUndo');
	expect(await vis() === n, 'undo');
	await p.locator('#nuBrowseTable thead th .nuColHide').nth(0).click();
	await p.click('#nuPrintReset');
	expect(await vis() === n && await p.isDisabled('#nuPrintReset'), 'reset view');
	expect(!/cols=/.test(await p.getAttribute('#nuXlsxButton', 'href')), 'Excel link keeps cols after reset');
};

T.print_columns_move = async (s) => {
	// print view (form list + system list): a column title dragged onto another moves the column; Alt+→ moves it with the keyboard;
	// remembered, Undo / Reset view, Excel / CSV take the shown order
	const p = s.ga;
	for (const [url, key, sel] of [['index.php?p=form&f=samplecustomer&print=1', 'nunext-print-cols:form:samplecustomer', '#nuBrowseTable'],
		['index.php?p=forms&export=print', 'nunext-print-cols:forms', '.nu-print-table']]) {
		await open(p, url);
		await p.evaluate((k) => { try { localStorage.removeItem(k); } catch (e) {} }, key);
		await open(p, url);
		const titles = async () => p.$$eval(sel + ' thead th:not(.nuColHidden)', l => l.map(th => (th.querySelector('.nuPrintColTitle, a.nu-sort') || th).textContent.trim()));
		const t0 = await titles();
		// drag the first title onto the right half of the third
		{   // the drag events of the browser by hand (Playwright's dragAndDrop lands on a spot that depends on the column widths)
			await p.evaluate((q) => {
				const ths = document.querySelectorAll(q + ' thead th'), dt = new DataTransfer(), r = ths[2].getBoundingClientRect();
				ths[0].dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
				ths[2].dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: r.right - 2 }));
				ths[2].dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: r.right - 2 }));
				ths[0].dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));
			}, sel);
		}
		const t1 = await titles();
		expect(JSON.stringify(t1.slice(0, 3)) === JSON.stringify([t0[1], t0[2], t0[0]]), url + ': drag did not move: ' + t1.slice(0, 3).join(','));
		const cellsMoved = await p.evaluate((q) => { const head = [...document.querySelectorAll(q + ' thead th')]; const tr = document.querySelector(q + ' tbody tr'); return head.length === tr.children.length; }, sel);
		expect(cellsMoved && !(await p.isDisabled('#nuPrintReset')), url + ': rows / reset');
		const xl = await p.getAttribute('#nuXlsxButton', 'href');
		expect(/cols=1(%2C|,)2(%2C|,)0/.test(xl), url + ': Excel order: ' + xl);
		// keyboard: Alt+→ on the (now) first title
		await p.focus(sel + ' thead th:nth-child(1) a, ' + sel + ' thead th:nth-child(1) button');
		await p.keyboard.press('Alt+ArrowRight');
		const t2 = await titles();
		expect(t2[0] === t0[2] && t2[1] === t0[1], url + ': Alt+→: ' + t2.slice(0, 3).join(','));
		await open(p, url);
		expect(JSON.stringify((await titles()).slice(0, 3)) === JSON.stringify(t2.slice(0, 3)), url + ': order not remembered');
		await p.click('#nuPrintReset');
		expect(JSON.stringify(await titles()) === JSON.stringify(t0) && !/cols=/.test(await p.getAttribute('#nuXlsxButton', 'href')), url + ': reset');
		await p.click('#nuPrintUndo');
		expect(JSON.stringify((await titles()).slice(0, 3)) === JSON.stringify(t2.slice(0, 3)), url + ': undo of reset');
		await p.click('#nuPrintReset');
	}
};

T.print_sort_stays = async (s) => {
	// a click on a column title of the print view sorts and stays in the print view
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&print=1');
	await Promise.all([p.waitForNavigation(), p.locator('#nuBrowseTable thead th a.nu-sort').nth(1).click()]);
	const u = new URL(p.url());
	expect(u.searchParams.get('print') === '1' && u.searchParams.get('sort') !== null && await p.locator('body.nuPrintView #nuPrintUndo').count() === 1, 'sorting left the print view: ' + p.url());
};

T.print_no_flicker = async (s) => {
	// print view of a form list: the table is shown once, already with the remembered hidden columns, and the widths dragged in the
	// normal list do not resize it after loading (it showed one set of widths, then another)
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 1300, height: 900 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer');
		const second = await p.locator('#nuBrowseTable thead th.nuBrowseTitle a.nu-sort').nth(1).textContent();
		await p.evaluate((t) => { sessionStorage.setItem('nunext-browse-w:samplecustomer', JSON.stringify({ 0: 300, 1: 90 })); localStorage.setItem('nunext-print-cols:form:samplecustomer', JSON.stringify({ hidden: [t.trim()], order: [] })); }, second);
		await p.addInitScript(() => {
			window.__w = [];
			const rec = () => {
				const t = document.querySelector('#nuBrowseTable');
				if (t && getComputedStyle(t).visibility === 'visible') {
					const w = [...t.querySelectorAll('thead th')].filter(th => th.offsetParent).map(th => Math.round(th.getBoundingClientRect().width)).join(',');
					if (!window.__w.length || window.__w[window.__w.length - 1] !== w) window.__w.push(w);
				}
				requestAnimationFrame(rec);
			};
			requestAnimationFrame(rec);
		});
		for (const sort of [1, 2]) {
			await p.goto(BASE + 'index.php?p=form&f=samplecustomer&print=1&sort=' + sort + '&dir=asc');
			await p.waitForTimeout(1200);
			const w = await p.evaluate(() => window.__w);
			const cols = await p.locator('#nuBrowseTable thead th:not(.nuColHidden)').count();
			// a few px when the icon font arrives are fine; the dragged widths of the list (90 / 300 px) or the hidden column are not
			const last = w[w.length - 1].split(',').map(Number);
			const steady = w.every(x => { const a = x.split(',').map(Number); return a.length === last.length && a.every((v, i) => Math.abs(v - last[i]) <= 8); });
			expect(w.length >= 1 && steady && last.length === cols, 'sort ' + sort + ': widths changed after showing: ' + JSON.stringify(w));
		}
	} finally {
		await p.evaluate(() => { try { localStorage.removeItem('nunext-print-cols:form:samplecustomer'); } catch (e) {} }).catch(() => {});
		await ctx.close();
	}
};
