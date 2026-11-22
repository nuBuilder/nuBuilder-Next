// e2e tests: File Manager, projects, self-test, compatibility check, audit – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

T.audit_history = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zzzzsys_nunext_audit'") === '0') throw new Error('audit table missing');
	const n = sql("SELECT COUNT(*) FROM zzzzsys_nunext_audit WHERE record_id = '" + s.cust + "'");
	if (n === '0') { s.skip = 'audit trail off'; return; }
	await open(p, 'index.php?p=audithistory&f=samplecustomer&r=' + s.cust);
	expect(await p.locator('.nuAudChange').count() >= 2, 'history incomplete');
	await p.locator('.nuAudTable tr', { hasText: 'cus_name' }).first().locator('.nuAudRestore').click();
	await p.waitForSelector('.js-confirm-ok', { state: 'visible' }); await p.click('.js-confirm-ok'); await p.waitForLoadState('networkidle');
	expect(sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + s.cust + "'") === 'E2E Customer', 'restore did not put the old value back');
	await open(p, 'index.php?p=audit&table=sample_customer');
	expect(await p.locator('table.nu-table tbody tr').count() > 0, 'audit list empty');
};

// 0.8.536: searchable selects (Tom Select) for long lists – builder objects (lookup / sub-form / run form / report / display procedure)
// and own pages (start form, launch form, e-mail template form, cloner, dashboard widget, time zone); object ID: trimmed, checked while typing
T.searchable_selects = async (s) => {
	const p = s.ga;
	const ts = async (path, ids) => {
		await open(p, path);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, path); }
		for (const id of ids) expect(await p.locator(id + '.tomselected').count() === 1, path + ': ' + id + ' not searchable');
	};
	await ts('index.php?p=accesslevel&id=sample_editor', ['#sal_zzzzsys_form_id']);
	await ts('index.php?p=procedures&id=new', ['#sph_zzzzsys_form_id']);
	await ts('index.php?p=emailtemplates&id=new', ['#emt_form_id']);
	await ts('index.php?p=cloner&id=new', ['#clSource', '#clDest']);
	await ts('index.php?p=dashboards&id=sample_dash_country&w=new', ['#dSelect', '#dPhp']);
	await ts('index.php?p=setupwizard', ['#timezone']);
	// design mode (0.8.670, before: the builder): lookup / sub-form objects of "Sample: all objects", the object ID check
	sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleall'");
	await open(p, 'index.php?p=form&f=sampleall&r=-1&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(600);
	for (const [type, key] of [['lookup', 'sob_lookup_zzzzsys_form_id'], ['subform', 'sob_subform_zzzzsys_form_id']]) {
		const id = sql("SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleall' AND sob_all_type = '" + type + "' LIMIT 1");
		if (!id) continue;
		await p.click('#nuDsRailStructure'); await p.waitForTimeout(300);
		await p.click('#nuDsTree .nuDsTreeRow[data-id="' + id + '"]'); await p.waitForTimeout(600); await p.click("#nuDsFly .btn-close");
		expect(await p.locator('#nuDsPanel select[data-key=' + key + '].tomselected').count() === 1, type + ': form select not searchable');
	}
	const idf = p.locator('#nuDsPanel input[data-key=sob_all_id]');
	await idf.fill('bad id-1'); await idf.dispatchEvent('change'); await p.waitForTimeout(200);
	expect(await idf.evaluate(e => e.classList.contains('is-invalid')), 'invalid ID marked');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	await p.evaluate(() => { if (window.nuLeaveGuard) window.nuLeaveGuard.isDirty = () => false; });
};

T.audit_hidden_field = async (s) => {                    // 0.8.367: the history shows only fields the user sees; no restore of others
	const p = s.ga, id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	if (sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_credit'") !== '1') { s.skip = 'no field cus_credit'; return; }
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + id);
	await p.evaluate(() => { const e = document.getElementById('cus_credit'); e.value = '4321'; e.dispatchEvent(new Event('change', { bubbles: true })); });   // on tab 2
	await setField(p, '#cus_phone', '+1 555 000 0001');
	await save(p);
	const aid = sql("SELECT zzzzsys_nunext_audit_id FROM zzzzsys_nunext_audit WHERE record_id = '" + id + "' AND column_name = 'cus_credit' ORDER BY changed_at DESC LIMIT 1");
	if (!aid) { s.skip = 'audit trail off'; return; }
	sql("UPDATE zzzzsys_object SET sob_all_access = '3' WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_credit'");   // now admin only
	const u = await login('sample_ben', UPW);
	await open(u, 'index.php?p=audithistory&f=samplecustomer&r=' + id);
	const body = await u.textContent('body');
	expect(/cus_phone/.test(body), 'the visible field is missing in the history');
	expect(!/cus_credit|4321/.test(body), 'the admin-only field shows in the history');
	await csrfPost(u, 'index.php?p=audithistory&f=samplecustomer&r=' + id, { action: 'restore', id: aid });
	expect(Number(sql("SELECT cus_credit FROM sample_customer WHERE sample_customer_id = '" + id + "'")) === 4321, 'a user restored a field he does not see');
	await u.context().close();
};

// File Manager = the page nuFiles (0.8.479, handover nuFiles C1–C5 / C9): Tiny File Manager removed by step 0023, ?p=filemanager sets up the
// page form for the globeadmin, entries in the start page / ⋮ menu, page API vendor / recentAuth / reauthUrl / dataDir / dangerousName
// 0.8.771: File Manager – a right-click on a file or folder opens its ⋮ menu at the pointer (desktop); the ⋮ button still works afterwards
T.file_manager_ctx = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga;
	let made = false, dir = '', created = [];
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
		const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
		dir = set.shares.find(x => x.id === 'uploads').path;
		const f = path.join(dir, 'e2e_ctx.txt'), d = path.join(dir, 'e2e_ctx_dir');
		if (!fs.existsSync(f)) { fs.writeFileSync(f, 'hello'); created.push(f); }
		if (!fs.existsSync(d)) { fs.mkdirSync(d); created.push(d); }
		await open(ga, 'index.php?p=filemanager');
		await ga.evaluate(() => { location.hash = '#/uploads/'; });   // the globeadmin starts in the web root (0.8.1315)
		await ga.waitForSelector('#nufRows tr[data-key="e2e_ctx.txt"]');
		const row = ga.locator('#nufRows tr[data-key="e2e_ctx.txt"]');
		const box = await row.locator('.nuf-c-name').boundingBox();
		const x = box.x + 40, y = box.y + box.height / 2;
		await ga.mouse.click(x, y, { button: 'right' });
		const menu = row.locator('.dropdown-menu');
		await menu.waitFor({ state: 'visible' });
		const mb = await menu.boundingBox();
		expect(Math.abs(mb.x - x) < 30 && mb.y >= y - 5 && mb.y < y + 40, 'menu at the pointer: ' + JSON.stringify(mb) + ' / ' + x + ',' + y);
		expect(await row.evaluate(r => r.classList.contains('table-active')), 'the row is selected');
		expect(await menu.locator('.dropdown-item').count() >= 3, 'menu entries');
		await ga.keyboard.press('Escape');
		await menu.waitFor({ state: 'hidden' });
		// a folder too; then the ⋮ button opens the menu next to itself again
		const drow = ga.locator('#nufRows tr[data-key="e2e_ctx_dir"]');
		await drow.locator('.nuf-c-size').click({ button: 'right' });
		await drow.locator('.dropdown-menu').waitFor({ state: 'visible' });
		expect(await ga.locator('#nufRows tr.table-active').count() === 1, 'only the right-clicked row selected');
		await ga.keyboard.press('Escape');
		await row.locator('.nuf-more').click();
		await menu.waitFor({ state: 'visible' });
		const bb = await row.locator('.nuf-more').boundingBox(), mb2 = await menu.boundingBox();
		expect(Math.abs((mb2.x + mb2.width) - (bb.x + bb.width)) < 30, 'the ⋮ menu sits at its button again: ' + JSON.stringify(mb2));
	} finally {
		created.reverse().forEach(x => fs.rmSync(x, { recursive: true, force: true }));
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

// 0.8.1056: File Manager on phones – the shares side bar closes without choosing anything: tap outside (backdrop), Esc, swipe left
T.file_manager_side_close = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga;
	let made = false;
	const vp = ga.viewportSize();
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		await ga.setViewportSize({ width: 390, height: 800 });
		await open(ga, 'index.php?p=filemanager');
		await ga.waitForSelector('#nufSideToggle', { state: 'visible' });
		const isOpen = () => ga.evaluate(() => document.getElementById('nufApp').classList.contains('nuf-side-open'));
		const shown = () => ga.evaluate(() => document.getElementById('nufSide').getBoundingClientRect().right > 10);
		const openSide = async () => {
			await ga.click('#nufSideToggle');
			await ga.waitForTimeout(350);
			expect(await isOpen() && await shown(), 'side bar open');
			expect(await ga.getAttribute('#nufSideToggle', 'aria-expanded') === 'true', 'aria-expanded true');
		};
		// tap outside the side bar
		await openSide();
		await ga.mouse.click(370, 400);
		await ga.waitForTimeout(350);
		expect(!(await isOpen()) && !(await shown()), 'closed by a tap outside');
		expect(await ga.getAttribute('#nufSideToggle', 'aria-expanded') === 'false', 'aria-expanded false');
		// Esc
		await openSide();
		await ga.keyboard.press('Escape');
		await ga.waitForTimeout(350);
		expect(!(await isOpen()), 'closed by Esc');
		// swipe to the left inside the side bar
		await openSide();
		await ga.evaluate(() => {
			const el = document.getElementById('nufSide');
			const tp = (x) => new Touch({ identifier: 1, target: el, clientX: x, clientY: 300 });
			el.dispatchEvent(new TouchEvent('touchstart', { touches: [tp(200)], changedTouches: [tp(200)], bubbles: true }));
			el.dispatchEvent(new TouchEvent('touchend', { touches: [], changedTouches: [tp(80)], bubbles: true }));
		});
		await ga.waitForTimeout(350);
		expect(!(await isOpen()), 'closed by a swipe to the left');
		// the toggle still closes it, and wide screens show no backdrop
		await openSide();
		await ga.click('#nufSideToggle', { force: true }).catch(() => {});
		await ga.setViewportSize(vp);
		expect(await ga.evaluate(() => getComputedStyle(document.getElementById('nufSideBackdrop')).display) === 'none', 'no backdrop on wide screens');
	} finally {
		await ga.setViewportSize(vp);
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

// 0.8.1057: File Manager path – phones: ‹ parent + folder name ▾ (the whole path as a list); wider: folders in between fold into "…"
T.file_manager_path = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga;
	let made = false, base = '';
	const vp = ga.viewportSize();
	const deep = ['e2e_path', 'projects', '2026', 'customer-letters', 'scans-before-migration', 'originals-second-round'];
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
		const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
		base = path.join(set.shares.find(x => x.id === 'uploads').path, 'e2e_path');
		fs.mkdirSync(path.join(base, ...deep.slice(1)), { recursive: true });
		await ga.setViewportSize({ width: 390, height: 800 });
		await open(ga, 'index.php?p=filemanager');
		await ga.waitForSelector('#nufCrumbs', { state: 'attached' });
		const url = ga.url().split('#')[0];
		await ga.goto(url + '#/uploads/' + deep.join('/'));
		await ga.waitForFunction(() => (document.querySelector('#nufPhPath .nuf-ph-title span') || {}).textContent === 'originals-second-round');
		expect(await ga.isHidden('#nufCrumbs'), 'phone: no breadcrumb');
		await ga.waitForTimeout(300);
		const t = await ga.locator('#nufPhPath .nuf-ph-title').boundingBox(), sp = await ga.locator('#nufPhPath .nuf-ph-title span');
		expect(await sp.evaluate(e => e.scrollWidth <= e.clientWidth + 1), 'phone: the folder name is not cut');
		const tg = await ga.locator('#nufSideToggle').boundingBox();
		expect(t && tg, 'phone: title / ☰ shown: ' + JSON.stringify([t, tg]));
		expect(Math.abs((t.y + t.height / 2) - (tg.y + tg.height / 2)) < 8, 'phone: path in the row of ☰');
		expect((await ga.getAttribute('#nufPhPath .nuf-ph-up', 'href')).endsWith('/scans-before-migration'), 'phone: ‹ goes to the parent');
		await ga.click('#nufPhPath .nuf-ph-title');
		await ga.waitForSelector('#nufPhPath .nuf-ph-menu.show');
		const n = await ga.locator('#nufPhPath .nuf-ph-menu .dropdown-item').count();
		expect(n === deep.length + 1, 'phone: every level in the list: ' + n);
		const mb = await ga.locator('#nufPhPath .nuf-ph-menu').boundingBox();
		expect(mb.x >= 0 && mb.x + mb.width <= 391, 'phone: the list fits the screen: ' + JSON.stringify(mb));
		await ga.locator('#nufPhPath .nuf-ph-menu a.dropdown-item', { hasText: '2026' }).click();
		await ga.waitForFunction(() => (document.querySelector('#nufPhPath .nuf-ph-title span') || {}).textContent === '2026');
		await ga.click('#nufPhPath .nuf-ph-up', { timeout: 6000 });
		await ga.waitForFunction(() => (document.querySelector('#nufPhPath .nuf-ph-title span') || {}).textContent === 'projects');
		// wider: the breadcrumb folds the middle into "…"
		await ga.setViewportSize({ width: 700, height: 800 });
		await ga.goto(url + '#/uploads/' + deep.join('/'));
		await ga.waitForFunction(() => document.querySelector('#nufCrumbs .nuf-crumb-more'));
		await ga.waitForTimeout(300);                          // the resize of setViewportSize fits the breadcrumb 120 ms later
		const last = ga.locator('#nufCrumbs li[data-i]:last-child');
		expect(await last.evaluate(e => e.scrollWidth <= e.clientWidth + 1 && e.textContent === 'originals-second-round'), 'wide: the current folder is not cut');
		expect(await ga.locator('#nufCrumbs li[data-i="0"]').isVisible(), 'wide: the share stays');
		await ga.click('#nufCrumbs .nuf-crumb-more-btn', { timeout: 7000 });
		const hid = await ga.locator('#nufCrumbs .nuf-crumb-more .dropdown-item').allTextContents();
		expect(hid.length >= 1 && hid[0].trim() === 'e2e_path', 'wide: hidden folders in the … menu: ' + JSON.stringify(hid));
		await ga.evaluate(() => window.dispatchEvent(new Event('resize')));
		await ga.waitForTimeout(300);
		expect(await ga.locator('#nufCrumbs .nuf-crumb-more .dropdown-menu.show').count() === 1, 'wide: a resize keeps the open … menu');
		const mm = await ga.locator('#nufCrumbs .nuf-crumb-more .dropdown-menu').boundingBox();
		expect(mm && mm.height > 20, 'wide: the … menu is not clipped: ' + JSON.stringify(mm));
		await ga.locator('#nufCrumbs .nuf-crumb-more .dropdown-item').first().click({ timeout: 7000 });
		await ga.waitForFunction(() => (document.querySelector('#nufCrumbs li[data-i]:last-child') || {}).textContent === 'e2e_path');
		expect(await ga.locator('#nufCrumbs .nuf-crumb-more').count() === 0, 'wide: no … for a short path');
	} finally {
		await ga.setViewportSize(vp);
		if (base) fs.rmSync(base, { recursive: true, force: true });
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

T.file_manager_phone_col = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga, vp = ga.viewportSize();
	let made = false, file = '';
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
		const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
		const dir = set.shares.find(x => x.id === 'uploads').path;
		fs.mkdirSync(dir, { recursive: true });
		file = path.join(dir, 'e2e_phone_col.txt');
		fs.writeFileSync(file, 'x'.repeat(2048));
		await ga.setViewportSize({ width: 390, height: 800 });
		await open(ga, 'index.php?p=filemanager');
		await ga.waitForSelector('#nufCrumbs', { state: 'attached' });
		await ga.evaluate(() => { try { localStorage.removeItem('nufPhoneCol'); } catch (e) { /* */ } });
		await ga.goto(ga.url().split('#')[0] + '#/uploads/');
		await ga.reload(); await ga.waitForLoadState('networkidle');
		await ga.waitForFunction(() => [...document.querySelectorAll('tbody tr')].some(x => /e2e_phone_col/.test(x.textContent)));
		const vis = () => ga.evaluate(() => { const r = [...document.querySelectorAll('#nufBody tr, tbody tr')].find(x => /e2e_phone_col/.test(x.textContent));
			return { size: r.querySelector('.nuf-c-size').offsetParent !== null, time: r.querySelector('.nuf-c-time').offsetParent !== null }; });
		let v = await vis();
		expect(v.size && !v.time, 'phone: size first: ' + JSON.stringify(v));
		const b = await ga.locator('#nufColToggle').boundingBox(), rb = await ga.locator('#nufRefreshBtn').boundingBox();
		expect(b && rb && b.x < rb.x && Math.abs(b.y - rb.y) < 4, 'toggle left of Refresh: ' + JSON.stringify([b, rb]));
		await ga.click('#nufColToggle');
		v = await vis();
		expect(!v.size && v.time && await ga.getAttribute('#nufColToggle', 'aria-pressed') === 'true', 'phone: date after the toggle: ' + JSON.stringify(v));
		await ga.reload(); await ga.waitForFunction(() => [...document.querySelectorAll('tbody tr')].some(x => /e2e_phone_col/.test(x.textContent)));
		v = await vis();
		expect(v.time && !v.size, 'remembered after a reload: ' + JSON.stringify(v));
		await ga.setViewportSize({ width: 1280, height: 800 });
		await ga.waitForTimeout(200);
		v = await vis();
		expect(v.time && v.size && !(await ga.isVisible('#nufColToggle')), 'wide: both columns, no toggle: ' + JSON.stringify(v));
		await ga.evaluate(() => { try { localStorage.removeItem('nufPhoneCol'); } catch (e) { /* */ } });
	} finally {
		await ga.setViewportSize(vp);
		if (file) fs.rmSync(file, { force: true });
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

T.file_manager_grid = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga, vp = ga.viewportSize();
	let made = false; const files = [];
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
		const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
		const dir = set.shares.find(x => x.id === 'uploads').path;
		fs.mkdirSync(dir, { recursive: true });
		const put = (n, data) => { const f = path.join(dir, n); fs.writeFileSync(f, data); files.push(f); };
		// a 600 x 300 PNG made by PHP's GD (the test needs GD like the feature)
		const png = path.join(dir, 'e2e_grid_pic.png'); files.push(png);
		execSync(`php -r '$i = imagecreatetruecolor(600, 300); imagefill($i, 0, 0, imagecolorallocate($i, 200, 60, 60)); imagepng($i, $argv[1]);' ${JSON.stringify(png)}`);
		put('e2e_grid_note.txt', 'hello');
		put('e2e_grid_vec.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20"><rect width="40" height="20" fill="green"/></svg>');
		put('e2e_grid_fake.jpg', 'this is not a picture');
		await open(ga, 'index.php?p=filemanager');
		await ga.waitForSelector('#nufCrumbs', { state: 'attached' });
		await ga.evaluate(() => { try { localStorage.removeItem('nufLayout'); } catch (e) { /* */ } });
		await ga.goto(ga.url().split('#')[0] + '#/uploads/');
		await ga.reload(); await ga.waitForLoadState('networkidle');
		await ga.waitForFunction(() => [...document.querySelectorAll('#nufRows tr')].some(x => /e2e_grid_pic/.test(x.textContent)));
		expect(await ga.isVisible('#nufTable') && !(await ga.isVisible('#nufGrid')), 'the list is the default');
		await ga.click('#nufLayoutBtn');
		await ga.waitForSelector('#nufGrid .nuf-tile[data-key="e2e_grid_pic.png"]');
		expect(!(await ga.isVisible('#nufTable')) && await ga.getAttribute('#nufLayoutBtn', 'aria-pressed') === 'true', 'tiles instead of the table');
		await ga.locator('.nuf-tile[data-key="e2e_grid_pic.png"]').scrollIntoViewIfNeeded();
		await ga.waitForFunction(() => { const i = document.querySelector('.nuf-tile[data-key="e2e_grid_pic.png"] img'); return i && i.complete && i.naturalWidth > 0; });
		const pic = await ga.evaluate(() => { const i = document.querySelector('.nuf-tile[data-key="e2e_grid_pic.png"] img'); return { w: i.naturalWidth, h: i.naturalHeight, shown: i.offsetParent !== null, src: i.getAttribute('src') }; });
		expect(pic.w === 256 && pic.h === 128 && pic.shown && /action=thumb/.test(pic.src), 'thumbnail 256 x 128 from the server: ' + JSON.stringify(pic));
		// pictures load lazily: bring the tiles into view
		await ga.locator('.nuf-tile[data-key="e2e_grid_vec.svg"]').scrollIntoViewIfNeeded();
		await ga.locator('.nuf-tile[data-key="e2e_grid_fake.jpg"]').scrollIntoViewIfNeeded();
		await ga.waitForFunction(() => { const i = document.querySelector('.nuf-tile[data-key="e2e_grid_vec.svg"] img'); return i && i.complete && i.naturalWidth > 0; });
		await ga.waitForFunction(() => !document.querySelector('.nuf-tile[data-key="e2e_grid_fake.jpg"] img'));
		const ico = await ga.evaluate(() => ['e2e_grid_fake.jpg', 'e2e_grid_note.txt'].map(k => { const t = document.querySelector('.nuf-tile[data-key="' + k + '"]'); return !t.querySelector('img') && t.querySelector('.nuf-thumb .bi').offsetParent !== null; }));
		expect(ico[0] && ico[1], 'a broken picture and a text file show the type icon: ' + JSON.stringify(ico));
		expect(fs.existsSync(path.join(SITE, 'custom/pages/nufiles/_data/thumbs/uploads')) && fs.readdirSync(path.join(SITE, 'custom/pages/nufiles/_data/thumbs/uploads')).length >= 1, 'the thumbnail is kept in the data folder');
		// the answer of the server: picture type, cached privately; no thumbnail = an empty answer
		const r = await ga.evaluate(async (src) => { const a = await fetch(src); const b = await fetch(src.replace('e2e_grid_pic.png', 'e2e_grid_fake.jpg')); return [a.status, a.headers.get('content-type'), a.headers.get('cache-control'), b.status]; }, pic.src);
		expect(r[0] === 200 && r[1] === 'image/png' && /private/.test(r[2]) && r[3] === 204, 'thumb answers: ' + JSON.stringify(r));
		// selection, right-click menu, sort
		await ga.click('.nuf-tile[data-key="e2e_grid_note.txt"] .nuf-thumb');
		await ga.click('.nuf-tile[data-key="e2e_grid_pic.png"] .nuf-thumb', { modifiers: ['Control'] });
		expect(await ga.locator('#nufGrid .nuf-tile.nuf-sel').count() === 2 && !(await ga.isDisabled('#nufDownloadBtn')), 'click + Ctrl+click select two tiles');
		await ga.click('.nuf-tile[data-key="e2e_grid_vec.svg"] .nuf-thumb', { button: 'right' });
		await ga.waitForSelector('.nuf-tile[data-key="e2e_grid_vec.svg"] .dropdown-menu.show');
		expect(await ga.locator('#nufGrid .nuf-tile.nuf-sel').count() === 1, 'right-click selects the tile');
		await ga.keyboard.press('Escape');
		await ga.click('#nufGridBar .nuf-sort[data-sort=size]');
		const order = await ga.evaluate(() => [...document.querySelectorAll('#nufGrid .nuf-tile[data-key^=e2e_grid]')].map(x => x.getAttribute('data-key')));
		expect(order[0] === 'e2e_grid_pic.png' || order[order.length - 1] === 'e2e_grid_pic.png', 'sorted by size from the bar: ' + order.join(','));
		await ga.click('#nufGridBar .nuf-sort[data-sort=name]');
		await ga.reload(); await ga.waitForSelector('#nufGrid .nuf-tile[data-key="e2e_grid_pic.png"]');
		expect(!(await ga.isVisible('#nufTable')), 'remembered after a reload');
		await ga.setViewportSize({ width: 390, height: 800 });
		await ga.waitForTimeout(200);
		const ph = await ga.evaluate(() => { const t = [...document.querySelectorAll('#nufGrid .nuf-tile')].slice(0, 3).map(x => x.getBoundingClientRect()); return { same: Math.abs(t[0].top - t[1].top) < 2, next: t[2].top > t[0].top + 10, over: document.documentElement.scrollWidth > window.innerWidth + 1 }; });
		expect(ph.same && ph.next && !ph.over, 'phones: two tiles per row: ' + JSON.stringify(ph));
		await ga.setViewportSize(vp);
		await ga.click('#nufLayoutBtn');
		await ga.waitForFunction(() => [...document.querySelectorAll('#nufRows tr')].some(x => /e2e_grid_pic/.test(x.textContent)));
		expect(await ga.isVisible('#nufTable') && !(await ga.isVisible('#nufGrid')), 'back to the list');
	} finally {
		await ga.setViewportSize(vp);
		await ga.evaluate(() => { try { localStorage.removeItem('nufLayout'); localStorage.removeItem('nufSort'); } catch (e) { /* */ } }).catch(() => {});
		files.forEach(f => fs.rmSync(f, { force: true }));
		if (SITE) fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data/thumbs'), { recursive: true, force: true });
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

T.file_manager_toolbar_row = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga;
	let made = false, selFile = '';
	const vp = ga.viewportSize();
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		await open(ga, 'index.php?p=filemanager');
		await ga.waitForSelector('#nufUploadBtn', { state: 'visible' });
		for (const w of [320, 360, 390]) {
			await ga.setViewportSize({ width: w, height: 700 });
			await ga.waitForTimeout(250);
			const r = await ga.evaluate(() => {
				const items = [...document.querySelectorAll('#nufTools > .btn, #nufTools > .btn-group')].filter(e => e.offsetParent);
				const tops = [...new Set(items.map(e => Math.round(e.getBoundingClientRect().top)))];
				const sep = [...document.querySelectorAll('#nufTools .nuf-sep')].some(e => e.offsetParent);
				const right = Math.max(...items.map(e => e.getBoundingClientRect().right));
				return { n: items.length, tops, sep, right, h: Math.min(...items.map(e => e.getBoundingClientRect().height)) };
			});
			expect(r.n >= 5 && r.tops.length === 1, w + ' px: one row: ' + JSON.stringify(r));
			expect(!r.sep && r.right <= w, w + ' px: no separator, inside the screen: ' + JSON.stringify(r));
			expect(r.h >= 28, w + ' px: buttons still easy to tap: ' + r.h);
		}
		await ga.setViewportSize({ width: 390, height: 700 });
		await ga.waitForTimeout(200);
		const wNone = await ga.evaluate(() => ['nufUploadBtn', 'nufMkdirBtn', 'nufNewFileBtn', 'nufLayoutBtn', 'nufRefreshBtn']
			.map(id => document.getElementById(id)).filter(e => e && e.offsetParent).map(e => Math.round(e.getBoundingClientRect().width)));
		// 0.8.1309: the phone toolbar follows the selection – none: Upload / New …, no Download … Delete; some: × n + Download … Delete only
		const vis = () => ga.evaluate(() => [...document.querySelectorAll('#nufTools > .btn, #nufTools > .btn-group')].filter(e => e.offsetParent)
			.map(e => e.id || (e.querySelector('[id]') || {}).id || '?'));
		let ids = await vis();
		expect(!ids.includes('nufDeleteBtn') && !ids.includes('nufDownloadBtn') && ids.includes('nufMkdirBtn') && !ids.includes('nufSelClear'), 'no selection: ' + ids);
		if (!(await ga.locator('#nufRows .nuf-c-check input').count())) {
			const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
			const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
			selFile = path.join(set.shares.find(x => x.id === 'uploads').path, 'e2e_phone_sel.txt');
			fs.writeFileSync(selFile, 'x');
			await ga.click('#nufRefreshBtn');
			await ga.waitForSelector('#nufRows .nuf-c-check input');
		}
		await ga.locator('#nufRows .nuf-c-check input').first().check();
		await ga.waitForTimeout(150);
		ids = await vis();
		expect(ids.includes('nufDeleteBtn') && ids.includes('nufSelClear') && !ids.includes('nufMkdirBtn') && !ids.includes('nufUploadBtn')
			&& !ids.includes('nufRefreshBtn'), 'selection: ' + ids);
		const one = await ga.evaluate(() => {
			const it = [...document.querySelectorAll('#nufTools > .btn, #nufTools > .btn-group')].filter(e => e.offsetParent);
			return { tops: [...new Set(it.map(e => Math.round(e.getBoundingClientRect().top)))].length, right: Math.max(...it.map(e => e.getBoundingClientRect().right)),
				count: document.getElementById('nufSelCount').textContent };
		});
		expect(one.tops === 1 && one.right <= 390 && one.count === '1', 'selection bar in one row with the count: ' + JSON.stringify(one));
		// the buttons without a selection are as wide as those with one (when there is room)
		const wSel = await ga.evaluate(() => Math.round(document.getElementById('nufCopyBtn').getBoundingClientRect().width));
		expect(wNone.length >= 4 && wNone.every(x => Math.abs(x - wSel) <= 2), 'same button width with and without a selection: ' + wNone + ' / ' + wSel);
		await ga.click('#nufSelClear');
		await ga.waitForTimeout(150);
		ids = await vis();
		expect(!ids.includes('nufDeleteBtn') && ids.includes('nufMkdirBtn'), 'cleared: ' + ids);
		await ga.setViewportSize({ width: 1200, height: 800 });
		await ga.waitForTimeout(200);
		ids = await vis();
		expect(ids.includes('nufDeleteBtn') && ids.includes('nufMkdirBtn') && !ids.includes('nufSelClear'), 'wide screen unchanged: ' + ids);
	} finally {
		await ga.setViewportSize(vp);
		if (selFile) fs.rmSync(selFile, { force: true });
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

// 0.8.772: File Manager – the globeadmin sets Unix permissions of a file / folder (grid ⇄ number, recursive: files without execute,
// links refused, setuid / owner without read refused)
T.file_manager_chmod = async (s) => {
	if (!SITE || process.platform === 'win32') return 'skip';
	const ga = s.ga;
	let made = false, created = [];
	const mode = f => (fs.statSync(f).mode & 0o777).toString(8);
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
		const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
		const dir = set.shares.find(x => x.id === 'uploads').path;
		const f = path.join(dir, 'e2e_chmod.txt'), d = path.join(dir, 'e2e_chmod_dir'), inner = path.join(d, 'inner.txt'), lnk = path.join(dir, 'e2e_chmod_link');
		fs.writeFileSync(f, 'x'); fs.chmodSync(f, 0o644); created.push(f);
		fs.mkdirSync(d, { recursive: true }); fs.writeFileSync(inner, 'y'); fs.chmodSync(inner, 0o664); created.push(d);
		try { fs.symlinkSync(f, lnk); created.push(lnk); } catch (e) { /* no symlinks here */ }
		// a fresh password confirmation (the action always asks for it)
		await open(ga, 'index.php?p=configfile');
		if (/p=reauth/.test(ga.url())) { await ga.fill('#reauthPassword', GA[1]); await Promise.all([ga.waitForLoadState('networkidle'), ga.click('button[type=submit]')]); }
		await open(ga, 'index.php?p=filemanager');
		await ga.evaluate(() => { location.hash = '#/uploads/'; });   // the globeadmin starts in the web root (0.8.1315)
		const row = ga.locator('#nufRows tr[data-key="e2e_chmod.txt"]');
		await row.waitFor();
		await row.locator('.nuf-more').click();
		await row.locator('.dropdown-menu .dropdown-item', { hasText: /Permissions|Rechte/ }).click();
		await ga.waitForSelector('#nufDlg .nuf-chmod-grid');
		const checked = await ga.$$eval('#nufDlg .nuf-chmod-grid input[data-bit]', b => b.filter(x => x.checked).map(x => +x.dataset.bit).reduce((a, x) => a | x, 0));
		expect(checked === 0o644 && await ga.inputValue('#nufDlg .nuf-chmod-num') === '644', 'grid shows 644: ' + checked.toString(8));
		await ga.fill('#nufDlg .nuf-chmod-num', '600');
		expect(!(await ga.locator(`#nufDlg input[data-bit="${0o004}"]`).isChecked()), 'number → grid');
		await ga.locator(`#nufDlg input[data-bit="${0o002}"]`).check();
		expect(await ga.inputValue('#nufDlg .nuf-chmod-num') === '602' && /Others|Andere/.test(await ga.textContent('#nufDlg .nuf-chmod-warn')), 'grid → number + warning for others write');
		await ga.fill('#nufDlg .nuf-chmod-num', '640');
		await ga.click('#nufDlgFoot .btn-primary');
		await ga.waitForTimeout(800);
		expect(mode(f) === '640', 'file mode after the dialog: ' + mode(f));
		// API: recursive on a folder, refusals
		const call = (params) => ga.evaluate(async (params) => {
			const fd = new FormData(); Object.keys(params).forEach(k => fd.append(k, params[k]));
			const r = await NuPage.fetch(location.href.replace(/#.*/, '') + '&action=chmod', { method: 'POST', body: fd }); return [r.status, await r.json()];
		}, Object.assign({ share: 'uploads' }, params));
		let [st, j] = await call({ path: 'e2e_chmod_dir', mode: '750', recursive: '1' });
		expect(st === 200 && j.ok && mode(d) === '750' && mode(inner) === '640', 'recursive: ' + JSON.stringify(j) + ' ' + mode(d) + '/' + mode(inner));
		[st, j] = await call({ path: 'e2e_chmod.txt', mode: '4755' });
		expect(!j.ok && j.error === 'err_chmod_mode', 'setuid refused: ' + JSON.stringify(j));
		[st, j] = await call({ path: 'e2e_chmod.txt', mode: '044' });
		expect(!j.ok && j.error === 'err_chmod_mode', 'owner without read refused');
		[st, j] = await call({ path: 'e2e_chmod_dir', mode: '644' });
		expect(!j.ok && j.error === 'err_chmod_mode', 'folder without open refused');
		if (created.includes(lnk)) { [st, j] = await call({ path: 'e2e_chmod_link', mode: '600' }); expect(!j.ok && j.error === 'err_chmod_link' && mode(f) === '640', 'link refused: ' + JSON.stringify(j)); }
		const log = sql("SELECT COUNT(*) FROM nufiles_log WHERE action = 'chmod' AND path LIKE 'e2e_chmod%'");
		expect(+log >= 2, 'logged: ' + log);
	} finally {
		created.reverse().forEach(x => { try { fs.rmSync(x, { recursive: true, force: true }); } catch (e) { /* gone */ } });
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

T.file_manager = async (s) => {
	if (!SITE) return 'skip';
	const old = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
	if (old) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${old}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${old}'`);
	// leftovers of Tiny File Manager: a hand copy and a library pack – step 0023 deletes them on the next sign-in
	const hand = path.join(SITE, 'third_party/tinyfilemanager'), pack = path.join(SITE, 'vendor/packs/tinyfilemanager-2.6');
	fs.mkdirSync(hand, { recursive: true }); fs.writeFileSync(path.join(hand, 'tinyfilemanager.php'), '<?php // e2e');
	fs.mkdirSync(pack, { recursive: true }); fs.writeFileSync(path.join(pack, 'tinyfilemanager.php'), '<?php // e2e');
	sql("DELETE FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0023-retire-tfm'");
	const ga = await login('globeadmin', 'nu');
	let anna;
	try {
		expect(!fs.existsSync(hand) && !fs.existsSync(pack), 'step 0023 left Tiny File Manager');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0023-retire-tfm'") === 1, 'step 0023 not recorded');
		// entries: start page tile and admin menu point to ?p=filemanager
		await open(ga, 'index.php?p=home');
		expect(await ga.locator('a[href*="p=filemanager"]').count() >= 1, 'no File Manager entry on the start page / menu');
		// 0.8.516: the start page tile opens it in a new tab
		const tfmTile = ga.locator('[data-nu-tile="tfm"] a[href*="p=filemanager"], a[data-nu-tile="tfm"][href*="p=filemanager"]');
		if (await tfmTile.count()) expect(await tfmTile.first().getAttribute('target') === '_blank', 'File Manager tile does not open a new tab');
		// not set up: the globeadmin creates it
		await open(ga, 'index.php?p=filemanager');
		expect(await ga.locator('#fmSetup').count() === 1, 'setup button missing');
		await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
		await ga.waitForLoadState('networkidle');
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
		expect(fid !== '', 'page form not created');
		const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
		expect(set.reauth === 'code' && set.shares && set.shares.map(x => x.id).join() === 'uploads,webroot', 'default settings: ' + JSON.stringify(set).slice(0, 200));
		await open(ga, 'index.php?p=filemanager');
		expect(/p=page/.test(ga.url()) && await ga.locator('#nufApp').count() === 1, 'File Manager page not opened: ' + ga.url());
		const html = await ga.content();
		expect(/assets\/js\/nu\/nucodetools(\.min)?\.js/.test(html) && html.includes('id="nufCodeTools"'), 'editor without the code tool buttons (0.8.482)');
		expect(/codemirror-6\.43\.15\/codemirror-bundle\.js\?v=/.test(html) && !/\?v=0\.8\./.test(html.match(/codemirror-bundle\.js\?v=[^"]*/)[0]), 'vendor() not used for the libraries');
		const r = await ga.evaluate(async () => { const x = await fetch(location.href.replace(/#.*/, '') + '&action=list&share=uploads&path=', { headers: { Accept: 'application/json' } }); return x.status + ' ' + (await x.text()).slice(0, 120); });
		expect(/^200 \{"ok":true/.test(r), 'list of the uploads share: ' + r);
		expect(!(await ga.locator('.nuf-problems, .alert-warning').filter({ hasText: 'data folder' }).count()), 'data folder problem');
		expect(fs.existsSync(path.join(SITE, 'custom/pages/nufiles/_data/.htaccess')) && !fs.existsSync(path.join(SITE, 'custom/pages/nufiles/index.php')), 'data folder without .htaccess / page not the built-in one');
		// a user without the admin right "filemanager": refused
		anna = await login('sample_anna', UPW);
		const st = (await anna.request.get(BASE + 'index.php?p=filemanager', { maxRedirects: 0 })).status();
		expect(st === 403, 'user without the right: ' + st);
	} finally {
		await ga.context().close();
		if (anna) await anna.context().close();
		const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
		if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
		fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		fs.rmSync(hand, { recursive: true, force: true }); fs.rmSync(pack, { recursive: true, force: true });
	}
};

T.selftest = async (s) => {
	const p = s.ga;
	const r = await open(p, 'index.php?p=selftest');
	if (r.status() === 404) { s.skip = 'developer mode off'; return; }
	const res = await p.evaluate(async () => {
		const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value); fd.append('action', 'run'); fd.append('format', 'json');
		return (await fetch('index.php?p=selftest', { method: 'POST', body: fd })).json();
	});
	const failed = res.results.filter(x => x.status === 'fail').map(x => x.id + ': ' + x.detail);
	expect(res.ok, 'self-test: ' + failed.join(' | '));
};

// 0.8.704: self-test page – Dev badge in the title next to (i), sortable result table, e-mail not set up = skipped (no warning)
T.selftest_page = async (s) => {                       // 0.8.760: the results as a system list (temp table) – sort, filters, search, phone cards
	const p = s.ga;
	const r = await open(p, 'index.php?p=selftest');
	if (r.status() === 404) { s.skip = 'developer mode off'; return; }
	// 0.8.766: no temporary table any more (a derived table of the session's results) – every view opens, also before a run
	const ctx2 = await browser.newContext({ storageState: await p.context().storageState() }), q = await ctx2.newPage();
	try {
		await q.goto(BASE + 'index.php?p=selftest'); await q.evaluate(() => fetch('index.php?p=selftest', { method: 'HEAD' }));
		for (const v of ['default', 'problems', 'all']) { const x = await q.goto(BASE + 'index.php?p=selftest&view=' + v + '&q=x&status=fail'); expect(x.status() === 200, 'view ' + v + ': ' + x.status()); }
	} finally { await ctx2.close(); }
	expect(!/run\("CREATE TEMPORARY TABLE/.test(fs.readFileSync(path.join(__dirname, '..', '..', 'core', 'nuselftest.php'), 'utf8')), 'temporary table still used');
	expect(await p.locator('h1 #stDevBadge').count() === 1 && await p.locator('.nuLv[data-lv-list="selftest"]').count() === 1, 'Dev badge in the title, list view');
	for (const g of ['data', 'forms', 'rights', 'features']) if (await p.locator('#st_g_' + g).count()) await p.uncheck('#st_g_' + g);
	await p.check('#st_g_system');
	await Promise.all([p.waitForNavigation(), p.click('#stRun')]);
	expect(await p.locator('#stSummary .nuStBadge').count() >= 1, 'summary with counts');
	const mailRow = p.locator('table.nu-table tbody tr:has([data-st="mail"])');
	const mail = await mailRow.locator('.nuStBadge').textContent();
	expect(/OK|skipped|übersprungen/i.test(mail), 'e-mail check: ' + mail);
	const total = () => p.$$eval('table.nu-table tbody tr', rs => rs.length);
	const n = await total();
	// sort by ms (header link), both directions
	await open(p, 'index.php?p=selftest&sort=ms&dir=asc');
	const col = () => p.$$eval('table.nu-table tbody tr', rs => rs.map(r => Number(r.cells[r.cells.length - 1].textContent.trim())));
	const ms = await col();
	expect(ms.length === n && ms.join() === [...ms].sort((a, b) => a - b).join(), 'sorted by ms: ' + ms.join(','));
	await open(p, 'index.php?p=selftest&sort=ms&dir=desc');
	const msd = await col();
	expect(msd.join() === [...msd].sort((a, b) => b - a).join(), 'reversed: ' + msd.join(','));
	// status filter, search, the built-in view "problems"
	await open(p, 'index.php?p=selftest&status=ok');
	const okRows = await total();
	expect(okRows > 0 && okRows <= n && (await p.$$eval('table.nu-table tbody .nuStBadge', b => b.every(x => /OK/i.test(x.textContent)))), 'status filter: ' + okRows);
	await open(p, 'index.php?p=selftest&q=mail');
	expect(await p.locator('table.nu-table tbody tr:has([data-st="mail"])').count() === 1 && await total() < n, 'search');
	await open(p, 'index.php?p=selftest&view=problems');
	expect(await p.locator('#lvf_status').inputValue() === 'fail', 'view problems filters failed');
	// phone: cards
	await p.setViewportSize({ width: 390, height: 800 });
	await open(p, 'index.php?p=selftest');
	expect(await p.locator('.nuLvCards .nuLvCard').count() === n && await p.evaluate(() => document.documentElement.scrollWidth <= 390), 'phone cards');
	await p.setViewportSize({ width: 1280, height: 900 });
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// Compatibility check (0.8.791): SQL that SQL Server does not understand – only on SQL Server (developer mode: ?mssql=1 on MySQL)
T.compat_phone_counts = async (s) => {               // 0.8.1086: compatibility check cards on phones – counts on their own row, no badges
	const p = s.ga;
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await p.context().storageState() });
	const ph = await ctx.newPage();
	try {
		await ph.goto(BASE + 'index.php?p=compat');
		const m = await ph.evaluate(() => {
			const h = document.querySelector('#compat_js .card-header'), d = h.querySelector('.compatDots'), t = h.querySelector('.fw-semibold');
			return { badges: [...h.querySelectorAll('.compatBdg')].filter((b) => getComputedStyle(b).display !== 'none').length, dots: d ? d.querySelectorAll('.compatDot').length : 0,
				below: d ? d.getBoundingClientRect().top > t.getBoundingClientRect().bottom - 2 : false, wide: document.documentElement.scrollWidth <= window.innerWidth + 1 };
		});
		expect(m.badges === 0 && m.dots === 4 && m.below && m.wide, 'phone: ' + JSON.stringify(m));
	} finally { await ctx.close(); }
	await open(p, 'index.php?p=compat');
	expect(await p.evaluate(() => getComputedStyle(document.querySelector('#compat_js .compatDots')).display === 'none' && document.querySelectorAll('#compat_js .compatBdg').length === 4), 'desktop keeps the badges');
};

T.compat_mssql = async (s) => {
	const p = s.ga;
	const F = 'e2e_mssql_form';
	sql("INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_code, sfo_description, sfo_table, sfo_primary_key, sfo_type, sfo_browse_sql) VALUES ('" + F + "', 'e2e_mssql', 'E2E MSSQL', 'sample_customer', 'sample_customer_id', 'browse', 'SELECT * FROM sample_customer WHERE IFNULL(cus_name, '''') <> '''' LIMIT 50')");
	try {
		await open(p, 'index.php?p=compat');
		expect(await p.locator('#compat_mssql').count() === 0, 'MySQL: no SQL Server section');
		await open(p, 'index.php?p=compat&mssql=1');
		expect(await p.locator('#compat_mssql').count() === 1, 'section with ?mssql=1 (developer mode)');
		expect(await p.locator('#compat_mssql tr[data-ms-rule="limit"]', { hasText: 'e2e_mssql' }).count() === 1
			&& await p.locator('#compat_mssql tr[data-ms-rule="ifnull"]', { hasText: 'e2e_mssql' }).count() === 1, 'LIMIT and IFNULL of the browse SQL listed with the form');
	} finally { sql("DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'"); }
};

T.projects = async (s) => {
	// 0.8.1035: projects – create, add parts, Suggest related, Markdown note, to-do with a notification, done, working on → list filter
	const p = s.ga;
	if (!sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'").startsWith('1')) { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=projects');
	expect(await p.locator('.nuListCard').count() === 1, 'list of projects');
	prjClean();
	const user = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	try {
		await open(p, 'index.php?p=projects&id=new');
		await p.fill('#prjName', 'E2E Shop');
		await Promise.all([p.waitForNavigation(), p.click('#prjSave')]);
		const pid = sql("SELECT zzzzsys_nunext_project_id FROM zzzzsys_nunext_project WHERE code = 'e2e_shop'");
		expect(pid !== '', 'project saved with the code made from the name');
		// add a form
		await p.click('#prjTab-items'); await p.click('#prjAddBtn');
		await p.waitForSelector('#prjAddList input[value="form|sampleorder"]');
		await p.fill('#prjAddQ', 'sample_order');
		await p.waitForFunction(() => document.querySelectorAll('#prjAddList input').length > 0 && !document.querySelector('#prjAddList input[value="form|sampleproduct"]'));
		await p.check('#prjAddList input[value="form|sampleorder"]');
		expect((await p.textContent('#prjAddCount')).includes('1'), 'count of picks');
		await Promise.all([p.waitForNavigation(), p.click('#prjAddGo')]);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_project_item WHERE project_id = '${pid}'`) === '1', 'one part');
		// suggest related
		await p.click('#prjSuggestBtn');
		await p.waitForSelector('#prjSuggestList input[value="form|samplecustomer"]');
		for (const v of ['form|sampleorderitem', 'table|sample_order']) expect(await p.locator(`#prjSuggestList input[value="${v}"]`).count() === 1, 'suggested ' + v);
		expect(await p.locator('#prjSuggestList input[value="form|sampleorder"]').count() === 0, 'members are not suggested');
		await Promise.all([p.waitForNavigation(), p.click('#prjSuggestGo')]);
		const n = +sql(`SELECT COUNT(*) FROM zzzzsys_nunext_project_item WHERE project_id = '${pid}'`);
		expect(n >= 4, 'suggested parts added: ' + n);
		expect(await p.locator('#prjKind-form tr[data-ref="samplecustomer"] a.prjItemLink').count() === 1, 'link to the form');
		// note
		await open(p, 'index.php?p=projects&id=' + pid + '&tab=notes');
		await p.fill('#prjNoteNew input[name=title]', 'Plan');
		await p.fill('#prjNoteNew textarea[name=body]', 'Check **totals** <script>x</script>');
		await p.check('#prjNoteNew input[name=pinned]');
		await Promise.all([p.waitForNavigation(), p.click('#prjNoteNew button[type=submit]')]);
		expect(await p.locator('.prjNoteView b').first().textContent() === 'totals', 'Markdown shown');
		expect(await p.locator('.prjNoteView script').count() === 0, 'HTML escaped');
		// to-do for a user, due today → notification
		await open(p, 'index.php?p=projects&id=' + pid + '&tab=tasks');
		await p.fill('#prjTaskTitle', 'Check the totals');
		await p.evaluate((u) => { const s = document.getElementById('prjTaskAssignee'); s.value = u; if (s.tomselect) s.tomselect.setValue(u, true); }, user);
		await p.fill('#prjTaskDue', new Date().toISOString().slice(0, 10));
		await Promise.all([p.waitForNavigation(), p.click('#prjTaskSave')]);
		const kid = sql(`SELECT zzzzsys_nunext_project_task_id FROM zzzzsys_nunext_project_task WHERE project_id = '${pid}'`);
		expect(kid !== '', 'to-do saved');
		const ntf = sql(`SELECT COUNT(*) FROM zzzzsys_nunext_notification n JOIN zzzzsys_nunext_notification_user u ON u.notification_id = n.zzzzsys_nunext_notification_id WHERE n.title LIKE '%Check the totals%' AND u.user_id = '${user}'`);
		expect(+ntf >= 1, 'assignee notified: ' + ntf);
		await open(p, 'index.php?p=projects&id=' + pid);
		expect((await p.textContent('#prjOverviewTasks')).includes('Check the totals'), 'open to-do on the overview');
		expect((await p.textContent('#prjPinned')).includes('totals'), 'pinned note on the overview');
		await Promise.all([p.waitForNavigation(), p.check('#prjOverviewTasks input[name=done]')]);
		expect(sql(`SELECT status FROM zzzzsys_nunext_project_task WHERE zzzzsys_nunext_project_task_id = '${kid}'`) === 'done', 'to-do done');
		// working on → the forms list shows only the project's forms
		await Promise.all([p.waitForNavigation(), p.click('#prjWork')]);
		await open(p, 'index.php?p=forms');
		const forms = await p.$$eval('.nuListCard tbody tr[data-href]', (rs) => rs.map((r) => r.getAttribute('data-href')));
		expect(forms.some((h) => h.includes('sampleorder')) && !forms.some((h) => h.includes('sampleproduct')), 'forms filtered by the project: ' + forms.length);
		await open(p, 'index.php?p=forms&project=');
		const all = await p.$$eval('.nuListCard tbody tr[data-href]', (rs) => rs.length);
		expect(all > forms.length, 'filter cleared shows more: ' + all);
		await open(p, 'index.php?p=projects&id=' + pid);
		await Promise.all([p.waitForNavigation(), p.click('#prjWork')]);
		await open(p, 'index.php?p=forms');
		expect(await p.$$eval('.nuListCard tbody tr[data-href]', (rs) => rs.length) === all, 'working off');
		// remove a part
		await open(p, 'index.php?p=projects&id=' + pid + '&tab=items');
		await Promise.all([p.waitForNavigation(), p.locator('#prjKind-form tr[data-ref="samplecustomer"] .prjRemove').click()]);
		expect(+sql(`SELECT COUNT(*) FROM zzzzsys_nunext_project_item WHERE project_id = '${pid}'`) === n - 1, 'part removed');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		prjClean();
		sql("DELETE u FROM zzzzsys_nunext_notification_user u JOIN zzzzsys_nunext_notification n ON n.zzzzsys_nunext_notification_id = u.notification_id WHERE n.title LIKE '%Check the totals%'; DELETE FROM zzzzsys_nunext_notification WHERE title LIKE '%Check the totals%'");
	}
};

T.project_package = async (s) => {
	// 0.8.1035: export a project as a package, delete form + table + project, import it again (check table, choices)
	const p = s.ga;
	if (!sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleproduct'").startsWith('1')) { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=projects');
	prjClean();
	const keep = path.join(os.tmpdir(), 'nunext-e2e-prjpkg.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_tab zzzzsys_object zzzzsys_browse zzzzsys_event zzzzsys_php sample_product zzzzsys_nunext_form_history > ' + keep);
	const pid = 'e2eprjpkg';
	sql(`INSERT INTO zzzzsys_nunext_project (zzzzsys_nunext_project_id, code, name, description, status) VALUES ('${pid}', 'e2e_pkg', 'E2E package', 'For the test', 'active');
INSERT INTO zzzzsys_nunext_project_item (zzzzsys_nunext_project_item_id, project_id, kind, ref) VALUES ('e2eprjpi1', '${pid}', 'form', 'sampleproduct'), ('e2eprjpi2', '${pid}', 'table', 'sample_product');
INSERT INTO zzzzsys_nunext_project_note (zzzzsys_nunext_project_note_id, project_id, title, body, pinned) VALUES ('e2eprjpn1', '${pid}', 'Read me', 'Hello', '0')`);
	try {
		await open(p, 'index.php?p=projects&id=' + pid);
		await p.click('#prjExportBtn');
		const [dl] = await Promise.all([p.waitForEvent('download'), p.click('#prjExportData')]);
		const file = await dl.path();
		const pkg = JSON.parse(fs.readFileSync(file, 'utf8'));
		expect(pkg.format === 'nunext-project-1' && pkg.forms && pkg.tables, 'package format: ' + Object.keys(pkg).join(','));
		const rows = +sql('SELECT COUNT(*) FROM sample_product');
		// remove what the package holds
		sql("DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleproduct'; DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'sampleproduct'; DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = 'sampleproduct';"
			+ " DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleproduct'; RENAME TABLE sample_product TO e2e_sample_product_keep");
		prjClean();
		// import
		await open(p, 'index.php?p=projects');
		await p.click('#prjImportBtn'); await p.waitForSelector('#prjImportFile', {state: 'visible'});
		await p.setInputFiles('#prjImportFile', file);
		await Promise.all([p.waitForNavigation(), p.click('#prjImportCheck')]);
		await p.waitForSelector('#prjImportTable');
		const states = await p.$$eval('#prjImportTable tr[data-key]', (rs) => rs.map((r) => r.getAttribute('data-key') + '=' + r.getAttribute('data-state')));
		for (const k of ['form|sampleproduct=new', 'table|sample_product=new', 'project|e2e_pkg=new']) expect(states.includes(k), k + ' in ' + states.join(' '));
		p.removeAllListeners('dialog'); p.on('dialog', (d) => d.accept());
		await p.click('#prjImportRun');
		const ok = p.locator('.modal.show .modal-footer .btn-primary');
		await ok.first().waitFor({timeout: 5000});
		await Promise.all([p.waitForNavigation(), ok.first().click()]);
		await p.waitForLoadState('networkidle');
		if ((await p.url()).includes('p=reauth')) { await p.fill('input[type=password]', 'nu'); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); await p.waitForLoadState('networkidle'); }
		expect(sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleproduct'") === '1', 'form imported');
		expect(+sql('SELECT COUNT(*) FROM sample_product') === rows, 'table with its rows imported');
		const np = sql("SELECT zzzzsys_nunext_project_id FROM zzzzsys_nunext_project WHERE code = 'e2e_pkg'");
		expect(np !== '' && sql(`SELECT COUNT(*) FROM zzzzsys_nunext_project_item WHERE project_id = '${np}'`) === '2' && sql(`SELECT COUNT(*) FROM zzzzsys_nunext_project_note WHERE project_id = '${np}'`) === '1', 'project with parts and note');
		await open(p, 'index.php?p=form&f=sampleproduct');
		expect(await p.locator('#nuBrowseTable tbody tr').count() > 0, 'imported form opens');
		// a second check of the same package: everything the same / exists
		await open(p, 'index.php?p=projects');
		await p.click('#prjImportBtn'); await p.waitForSelector('#prjImportFile', {state: 'visible'});
		await p.setInputFiles('#prjImportFile', file);
		await Promise.all([p.waitForNavigation(), p.click('#prjImportCheck')]);
		const again = await p.$$eval('#prjImportTable tr[data-key]', (rs) => rs.map((r) => r.getAttribute('data-key') + '=' + r.getAttribute('data-state')));
		expect(again.includes('form|sampleproduct=same') && again.includes('table|sample_product=exists'), 'second check: ' + again.join(' '));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		prjClean();
		sql('DROP TABLE IF EXISTS sample_product');
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
		sql('DROP TABLE IF EXISTS e2e_sample_product_keep');
	}
};

// keep this test LAST (also after tests defined below the runner): it signs the shared globeadmin page out (tests added after it failed at the gate 0.8.1216)
T.file_manager_edit_nokbd = async (s) => {
	// 0.8.1253: opening a file in the editor focuses it only with a real keyboard – on a phone no on-screen keyboard pops up
	if (!SITE) return 'skip';
	const ga = s.ga;
	let made = false, file = '', ctx = null;
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
		const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
		const dir = set.shares.find(x => x.id === 'uploads').path;
		fs.mkdirSync(dir, { recursive: true });
		file = path.join(dir, 'e2e_edit_nokbd.txt');
		fs.writeFileSync(file, 'hello\n');
		const edit = async (pg, tap) => {
			await open(pg, 'index.php?p=filemanager');
			await pg.waitForSelector('#nufCrumbs', { state: 'attached' });
			await pg.goto(pg.url().split('#')[0] + '#/uploads/');
			await pg.reload(); await pg.waitForLoadState('networkidle');
			const row = pg.locator('tbody tr', { hasText: 'e2e_edit_nokbd' }).first();
			await row.waitFor();
			await row.locator('.nuf-c-size').dblclick();
			await pg.waitForSelector('#nufEditor.show');
			await pg.waitForTimeout(700);
			const r = await pg.evaluate(() => ({ inEditor: !!document.activeElement && !!document.activeElement.closest('#nufEditor .CodeMirror'), tag: document.activeElement && document.activeElement.className }));
			await pg.keyboard.press('Escape').catch(() => {});
			return r;
		};
		ctx = await ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true, storageState: await ga.context().storageState() });
		const ph = await ctx.newPage();
		const phone = await edit(ph);
		expect(!phone.inEditor, 'phone: the editor took the focus (on-screen keyboard): ' + JSON.stringify(phone));
		const desk = await edit(ga);
		expect(desk.inEditor, 'desktop: the editor did not take the focus: ' + JSON.stringify(desk));
		s.result = { phone, desk };
	} finally {
		if (ctx) await ctx.close();
		if (file) fs.rmSync(file, { force: true });
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

// 0.8.1314: File Manager – ticking several files: a click next to a checkbox (its cell) adds the row instead of replacing the selection;
// on a touch screen a tap on a row adds / removes while files are ticked; a plain click on a row with the mouse still selects only it
T.file_manager_multi_select = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga;
	let made = false, files = [];
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles' AND sfo_type = 'page'");
		const set = JSON.parse(sql(`SELECT REPLACE(REPLACE(settings, CHAR(10), ' '), CHAR(9), ' ') FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) || '{}');
		const dir = set.shares.find(x => x.id === 'uploads').path;
		files = [1, 2, 3, 4, 5].map((i) => path.join(dir, `e2e_multi_${i}.txt`));
		files.forEach((f) => fs.writeFileSync(f, 'x'));
		const run = async (p) => {
			await open(p, 'index.php?p=filemanager');
			await p.evaluate(() => { location.hash = '#/uploads/'; });   // the globeadmin starts in the web root (0.8.1315)
			await p.waitForSelector('#nufRows tr[data-key*="e2e_multi_5"]');
			const row = (i) => p.locator(`#nufRows tr[data-key*="e2e_multi_${i}"]`);
			const n = () => p.locator('#nufRows tr.table-active').count();
			return { row, n };
		};
		// mouse: three checkboxes, then a click in the 4th row's checkbox cell beside the box
		let { row, n } = await run(ga);
		for (const i of [1, 2, 3]) await row(i).locator('input[type=checkbox]').check();
		const cell = await row(4).locator('td.nuf-c-check').boundingBox();
		await ga.mouse.click(cell.x + 3, cell.y + cell.height - 3);
		expect(await n() === 4, 'a click beside the 4th checkbox replaced the selection: ' + await n());
		await row(5).locator('td.nuf-c-size').click();
		expect(await n() === 1, 'a plain click on a row selects only that row: ' + await n());
		// touch screen: taps on rows add while files are ticked
		const ctx = await ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true, storageState: await ga.context().storageState() });
		const ph = await ctx.newPage();
		try {
			({ row, n } = await run(ph));
			await row(1).locator('input[type=checkbox]').tap();
			await row(2).locator('td.nuf-c-size').tap();
			await row(3).locator('td.nuf-c-size').tap();
			expect(await n() === 3, 'phone: taps did not add to the selection: ' + await n());
			await row(2).locator('td.nuf-c-size').tap();
			expect(await n() === 2, 'phone: a second tap did not untick: ' + await n());
		} finally { await ctx.close(); }
	} finally {
		files.forEach((f) => fs.rmSync(f, { force: true }));
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

// 0.8.1315: File Manager – the globeadmin starts in the web root (not in the share used last)
T.file_manager_ga_webroot = async (s) => {
	if (!SITE) return 'skip';
	const ga = s.ga;
	let made = false;
	try {
		if (!sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'")) {
			await open(ga, 'index.php?p=filemanager');
			await Promise.all([ga.waitForNavigation(), ga.click('#fmSetup')]);
			made = true;
		}
		await open(ga, 'index.php?p=filemanager');
		await ga.waitForSelector('#nufUploadBtn', { state: 'attached' });
		await ga.evaluate(() => { try { localStorage.setItem('nufLast', 'uploads'); } catch (e) { /* none */ } });
		await open(ga, 'index.php?p=filemanager');
		await ga.waitForFunction(() => /#\/webroot\//.test(location.hash) || /#\/[a-z]+\//.test(location.hash));
		expect(/#\/webroot\//.test(await ga.evaluate(() => location.hash)), 'globeadmin did not start in the web root: ' + await ga.evaluate(() => location.hash));
	} finally {
		if (made) {
			const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'nufiles'");
			if (id) sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DROP TABLE IF EXISTS nufiles_log`);
			fs.rmSync(path.join(SITE, 'custom/pages/nufiles/_data'), { recursive: true, force: true });
		}
	}
};

// Compatibility check (0.8.1330): nuBuilder Forte 4.9 features – only the ones not supported yet are shown, the supported ones fold
// away in #compatFSupported (open with Show all)
T.compat_supported_fold = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=compat');
	const r = await p.evaluate(() => ({
		openOk: document.querySelectorAll('#compatFOpen tr.compat-ok').length,
		openOther: document.querySelectorAll('#compatFOpen tr[data-finding]').length,
		allOk: !!document.getElementById('compatFAllOk'),
		det: !!document.getElementById('compatFSupported'),
		detOpen: !!(document.getElementById('compatFSupported') || {}).open,
		done: document.querySelectorAll('#compatFSupported tr.compat-ok').length,
		doneOther: document.querySelectorAll('#compatFSupported tr[data-finding]:not(.compat-ok)').length,
		visibleDone: [...document.querySelectorAll('#compatFSupported tr.compat-ok')].some(tr => tr.checkVisibility()) }));
	expect(r.openOk === 0, 'no supported feature in the main table: ' + JSON.stringify(r));
	expect(r.openOther > 0 || r.allOk, 'open features or the all-supported message: ' + JSON.stringify(r));
	expect(r.det && !r.detOpen && r.done > 0 && r.doneOther === 0 && !r.visibleDone, 'supported features folded away: ' + JSON.stringify(r));
	await p.click('#compatFSupported > summary'); await p.waitForTimeout(150);
	expect(await p.evaluate(() => [...document.querySelectorAll('#compatFSupported tr.compat-ok')].some(tr => tr.checkVisibility())), 'summary opens the supported features');
	await open(p, 'index.php?p=compat&all=1');
	expect(await p.evaluate(() => !!document.getElementById('compatFSupported').open), 'Show all opens them');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// Compatibility check (0.8.1331): every place of a function opens its code – an object event = design mode with the object selected
// and its events dialog open; events of an object that no longer exists are left out (they never run)
T.compat_place_links = async (s) => {
	const p = s.ga;
	execSync('mysql ' + DB, { input: "DELETE FROM zzzzsys_event WHERE zzzzsys_event_id IN ('e2e_cpl1', 'e2e_cpl2');"
		+ "INSERT INTO zzzzsys_event (zzzzsys_event_id, sev_zzzzsys_object_id, sev_event, sev_javascript) VALUES ('e2e_cpl1', 'sample_order_o5', 'clickdelete', 'nuE2eCompatLinkFn(1);'),"
		+ "('e2e_cpl2', 'e2e_no_such_object', 'clickdelete', 'nuE2eCompatOrphanFn(1);');" });
	try {
		await open(p, 'index.php?p=compat');
		const r = await p.evaluate(() => {
			const row = [...document.querySelectorAll('#compat_js tr')].find(tr => /nuE2eCompatLinkFn/.test(tr.textContent));
			const a = row ? row.querySelector('a.nuCompatPlace') : null;
			return { row: !!row, href: a ? a.getAttribute('href') : '', text: a ? a.textContent : '', orphan: /nuE2eCompatOrphanFn/.test(document.body.textContent) };
		});
		expect(r.row && /p=builder/.test(r.href) && /f=sampleorder/.test(r.href) && /obj=sample_order_o5/.test(r.href) && /ev=1/.test(r.href), 'place is a link to the event: ' + JSON.stringify(r));
		expect(/sample_items · clickdelete/.test(r.text), 'link text names the object and event: ' + r.text);
		expect(!r.orphan, 'event of a deleted object left out');
		await open(p, r.href);
		await p.waitForSelector('#nuDsEventsDlg.show', { timeout: 15000 });
		expect(/sample_items/.test(await p.locator('#nuDsEventsDlg .modal-title').textContent()), 'events dialog of the object opened');
	} finally {
		execSync('mysql ' + DB, { input: "DELETE FROM zzzzsys_event WHERE zzzzsys_event_id IN ('e2e_cpl1', 'e2e_cpl2');" });
		await p.evaluate(() => { if (window.nuLeaveGuard) window.nuLeaveGuard.isDirty = () => false; }).catch(() => {});
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.logout = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home&tiles=1');
	await Promise.all([p.waitForURL(/p=login/), p.evaluate(() => { const f = document.createElement('form'); f.method = 'post'; f.action = 'index.php?p=logout';
		const i = document.createElement('input'); i.name = '_csrf'; i.value = document.querySelector('input[name=_csrf]').value; f.appendChild(i); document.body.appendChild(f); f.submit(); })]);
	await open(p, 'index.php?p=home');
	expect(/p=login/.test(p.url()), 'still signed in after logout');
};
