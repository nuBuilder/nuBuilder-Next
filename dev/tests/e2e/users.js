// e2e tests: users, rights, sign-in and security – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

      // name → async fn; run in this order

T.login_refused = async () => {
	const ctx = await browser.newContext(); const p = await ctx.newPage();
	await p.goto(BASE + 'index.php?p=login'); await p.fill('#username', GA[0]); await p.fill('#password', 'wrong-' + Date.now());
	await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('form button[type=submit]:not([name=demo_user])')]);
	expect(/p=login/.test(p.url()) && await p.locator('.alert-danger, .alert-warning').count() > 0, 'wrong password not refused');
	sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
	await ctx.close();
};

// 0.8.687: users ↔ access levels ↔ permission items – filters and links; admin roles as a system list; logged-in users filters / groups
// user stamps (0.8.759): sus_json NUNEXT_CREATED / NUNEXT_CHANGED {t, by} written by the user form (create / change), shown in the (i) panel and
// in the users list columns Created / Last changed (view "all"); a user from before gets the created stamp from the log on the next change
// 0.8.1074: defaults of custom user fields worked out when a user is created – keywords, counter, templates, SQL, procedure; Insert ▾ + example
T.user_field_defaults = async (s) => {
	const p = s.ga;
	const go = async (u) => {
		await open(p, u);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, u); }
	};
	const add = async (label, code, type, def) => {
		await go('index.php?p=userfields&id=new');
		await p.fill('#ufLabel', label); await p.fill('#ufCode', code); await p.selectOption('#ufType', type);
		await p.fill('#ufDefault', def);
		await p.waitForFunction(() => /→|text-danger/.test((document.getElementById('ufDefaultEx') || {}).outerHTML || ''), null, { timeout: 5000 }).catch(() => {});
		const ex = await p.textContent('#ufDefaultEx');
		await Promise.all([p.waitForNavigation(), p.click('#ufSave')]);
		const bad = await p.evaluate(() => [...document.querySelectorAll('.invalid-feedback, .alert-danger')].map(e => e.textContent.trim()).filter(Boolean).join(' | '));
		expect(!bad, 'field ' + code + ' not saved: ' + bad);
		return ex;
	};
	const today = new Date(); const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	const t30 = new Date(today.getTime() + 30 * 86400000);
	sql("DELETE FROM zzzzsys_user WHERE sus_login_name IN ('e2e_ufd1', 'e2e_ufd2')");
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_ufd_proc'");
	sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_global) VALUES ('e2e_ufd_proc', 'e2e_ufd_proc', 'e2e', '$result = \"P-\" . \"#NEW_LOGIN_NAME#\";', '1')");
	try {
		// Insert ▾ and example
		await go('index.php?p=userfields&id=new');
		await p.selectOption('#ufType', 'date');
		await p.click('#ufDefaultIns');
		await p.click('.ufdIns[data-ins="today+30d"]');
		await p.waitForFunction(() => /→/.test(document.getElementById('ufDefaultEx').textContent), null, { timeout: 5000 });
		expect((await p.inputValue('#ufDefault')) === 'today+30d' && (await p.textContent('#ufDefaultEx')).includes(ymd(t30)), 'Insert + example: ' + await p.textContent('#ufDefaultEx'));
		await p.selectOption('#ufType', 'integer'); await p.fill('#ufDefault', 'today'); await p.waitForTimeout(900);
		expect(await p.locator('#ufDefaultEx.text-danger').count() === 1, 'today on a number field not refused');
		expect(await p.locator('.ufdIns[data-ins="= SELECT "]').count() === 1, 'SQL entry missing for the globeadmin');
		// fields
		const ex1 = await add('E2E start', 'e2e_ufd_start', 'date', 'today+30d');
		expect(ex1.includes(ymd(t30)), 'example start: ' + ex1);
		await add('E2E nick', 'e2e_ufd_nick', 'text', '{first_name}.{last_name}');
		await add('E2E staff no', 'e2e_ufd_no', 'text', 'next:EMP-{000}');
		const exs = await add('E2E sqlv', 'e2e_ufd_sql', 'text', "= SELECT CONCAT('S', '-1')");
		expect(exs.includes('S-1'), 'SQL example: ' + exs);
		await add('E2E procv', 'e2e_ufd_proc', 'text', 'proc:e2e_ufd_proc');
		expect(sql("SELECT default_value FROM zzzzsys_nunext_user_field WHERE code = 'e2e_ufd_start'") === 'today+30d', 'keyword stored as typed');
		sql("INSERT INTO zzzzsys_nunext_user_ext (zzzzsys_user_id, e2e_ufd_no) VALUES ('e2e_ufd_other', 'EMP-007')");
		// new user form: prefilled, template follows the name fields
		await open(p, 'index.php?p=user');
		expect((await p.inputValue('#uf_e2e_ufd_start')) === ymd(t30), 'date prefilled: ' + await p.inputValue('#uf_e2e_ufd_start'));
		expect((await p.inputValue('#uf_e2e_ufd_no')) === 'EMP-008', 'counter prefilled: ' + await p.inputValue('#uf_e2e_ufd_no'));
		expect((await p.inputValue('#uf_e2e_ufd_sql')) === 'S-1', 'SQL prefilled');
		await p.fill('#sus_login_name', 'e2e_ufd1'); await p.fill('#sus_name', 'Ada Lovelace');
		await p.locator('#sus_name').blur(); await p.waitForTimeout(200);   // the name splits into first / last name
		expect((await p.inputValue('#uf_e2e_ufd_nick')) === 'Ada.Lovelace', 'template not filled while typing: ' + await p.inputValue('#uf_e2e_ufd_nick'));
		await p.fill('#sus_email', 'e2e_ufd1@example.com');
		await p.fill('#password', UPW); await p.fill('#password_confirm', UPW);
		await p.selectOption('#sus_zzzzsys_access_id', { index: 1 });
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		const id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'e2e_ufd1'");
		expect(id !== '', 'user not created: ' + p.url());
		const row = sql("SELECT CONCAT_WS('|', e2e_ufd_start, e2e_ufd_nick, e2e_ufd_no, e2e_ufd_sql, IFNULL(e2e_ufd_proc, '-')) FROM zzzzsys_nunext_user_ext WHERE zzzzsys_user_id = '" + id + "'");
		expect(row === ymd(t30) + '|Ada.Lovelace|EMP-008|S-1|P-e2e_ufd1', 'stored values: ' + row);
		// REST-like path: worked out with the row (procedure gets #NEW_…#, counter goes on)
		const out = execSync('php -r ' + JSON.stringify("chdir('" + SITE + "'); require 'core/nuapp.php'; NuAuth::cliGlobeadmin(); echo json_encode(NuUserDefault::forNew([], ['sus_login_name' => 'zed', 'sus_first_name' => 'Zed', 'sus_last_name' => 'Ray']));"), { cwd: SITE }).toString();
		const j = JSON.parse(out.slice(out.indexOf('{')));
		expect(j.e2e_ufd_no === 'EMP-009' && j.e2e_ufd_nick === 'Zed.Ray' && j.e2e_ufd_proc === 'P-zed', 'forNew: ' + out);
	} finally {
		sql("DELETE FROM zzzzsys_nunext_user_ext WHERE zzzzsys_user_id IN (SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name LIKE 'e2e_ufd%') OR zzzzsys_user_id = 'e2e_ufd_other'");
		sql("DELETE FROM zzzzsys_user WHERE sus_login_name LIKE 'e2e_ufd%'");
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_ufd_proc'");
		for (const c of ['e2e_ufd_start', 'e2e_ufd_nick', 'e2e_ufd_no', 'e2e_ufd_sql', 'e2e_ufd_proc']) {
			if (sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zzzzsys_nunext_user_ext' AND COLUMN_NAME = '" + c + "'") === '1') sql('ALTER TABLE zzzzsys_nunext_user_ext DROP COLUMN ' + c);
		}
		sql("DELETE FROM zzzzsys_nunext_user_field WHERE code LIKE 'e2e_ufd%'");
	}
};

T.user_stamps = async (s) => {
	const p = s.ga;
	sql("DELETE FROM zzzzsys_user WHERE sus_login_name = 'e2e_stamp'");
	try {
		await open(p, 'index.php?p=user');
		await p.fill('#sus_login_name', 'e2e_stamp'); await p.fill('#sus_name', 'E2E Stamp'); await p.fill('#sus_email', 'e2e_stamp@example.com');
		await p.fill('#password', UPW); await p.fill('#password_confirm', UPW);
		await p.selectOption('#sus_zzzzsys_access_id', { index: 1 });
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		const id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'e2e_stamp'");
		expect(id !== '', 'user created: ' + p.url());
		const j1 = JSON.parse(sql("SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '" + id + "'") || '{}');
		expect(j1.NUNEXT_CREATED && j1.NUNEXT_CREATED.by === GA[0] && j1.NUNEXT_CREATED.via === 'form' && /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(j1.NUNEXT_CREATED.t) && j1.NUNEXT_CHANGED && j1.NUNEXT_CHANGED.t === j1.NUNEXT_CREATED.t, 'created stamp: ' + JSON.stringify(j1));
		// (i) panel: Created / Last changed from the stamps
		await open(p, 'index.php?p=user&id=' + id);
		const created = await p.textContent('#nuRecInfo .nuFormInfoRow[data-key=created] dd');
		expect(created.includes(GA[0]) && /\d\d[./-]\d\d[./-]\d{4}/.test(created), '(i) created: ' + created);
		// a change → changed stamp moves; a user from before (stamps removed) gets created from the log
		sql("UPDATE zzzzsys_user SET sus_json = JSON_REMOVE(sus_json, '$.NUNEXT_CREATED', '$.NUNEXT_CHANGED') WHERE zzzzsys_user_id = '" + id + "'");
		await open(p, 'index.php?p=user&id=' + id);
		await p.fill('#sus_name', 'E2E Stamp 2');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		const j2 = JSON.parse(sql("SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '" + id + "'") || '{}');
		expect(j2.NUNEXT_CHANGED && j2.NUNEXT_CHANGED.via === 'form' && j2.NUNEXT_CREATED && j2.NUNEXT_CREATED.via === 'log' && j2.NUNEXT_CREATED.t === j1.NUNEXT_CREATED.t, 'changed + created from the log: ' + JSON.stringify(j2));
		// a save without a change leaves the stamps alone
		await open(p, 'index.php?p=user&id=' + id);
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		expect(sql("SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '" + id + "'") === JSON.stringify(j2) || JSON.parse(sql("SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '" + id + "'")).NUNEXT_CHANGED.t === j2.NUNEXT_CHANGED.t, 'unchanged save keeps the stamp');
		// list columns in the view "all"
		await open(p, 'index.php?p=users&view=all&q=e2e_stamp');
		const heads = await p.$$eval('table.nu-table thead th', th => th.map(x => x.textContent.trim()));
		const row = await p.textContent('table.nu-table tbody tr');
		expect(heads.some(h => /^Created|^Erstellt/.test(h)) && row.includes(GA[0]), 'list columns: ' + heads.join('|') + ' / ' + row.slice(0, 200));
		await open(p, 'index.php?p=users&view=all&sort=created&dir=desc');
		expect(/\/users\b|p=users/.test(p.url()) && await p.locator('table.nu-table tbody tr').count() > 0, 'sort by created');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_user WHERE sus_login_name = 'e2e_stamp'");
		sql("DELETE FROM zzzzsys_nunext_log WHERE object = 'zzzzsys_user' AND details LIKE '%e2e_stamp%'");
	}
};

T.users_access_links = async (s) => {
	const p = s.ga;
	const ben = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	const item = sql("SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item WHERE pme_ident = 'sample_export'");
	if (!ben || !item) { s.skip = 'no sample data'; return; }
	const total = async () => +(((await p.textContent('body')).match(/Total:\s*(\d+)/) || [])[1] || -1);
	// users list: Department / Team shown, permission item filter, access badge → access level
	await open(p, 'index.php?p=users');
	expect(await p.locator('#lvf_dept').count() === 1 && await p.locator('#lvf_team').count() === 1, 'department / team filters');
	const acc = p.locator('a.nuAccessLink').first();
	expect(/p=accesslevel&id=/.test(await acc.getAttribute('href') || '') && await acc.getAttribute('target') === '_blank', 'access badge link (new tab)');
	await open(p, 'index.php?p=users&perm=' + item + '&state=all');
	const nPerm = +sql(`SELECT COUNT(DISTINCT upe_zzzzsys_user_id) FROM zzzzsys_user_permission WHERE upe_ident = '${item}' AND upe_active = '1'`);
	expect(await total() === nPerm && await p.locator('#lvf_perm').count() === 1, 'perm filter: ' + await total() + ' / ' + nPerm);
	// user form (i): links to the log and sessions
	await open(p, 'index.php?p=user&id=' + ben);
	expect(await p.locator('#nuRecInfo .nuFormInfoLinks a').count() >= 2, 'user (i) links');
	// access levels: form filter, counts as links, users list link
	await open(p, 'index.php?p=access&form=samplecustomer');
	const nAcc = +sql("SELECT COUNT(DISTINCT slf_zzzzsys_access_id) FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = 'samplecustomer'");
	expect(await total() === nAcc, 'access form filter');
	expect(await p.locator('a[href*="tab=rights"]').count() > 0, 'form rights count link');
	await open(p, 'index.php?p=accesslevel&id=sample_editor');
	await Promise.all([p.waitForNavigation(), p.click('#accUsersList')]);
	expect(/anyacc=sample_editor/.test(p.url()) && await total() >= 1, 'access level → users list');
	// permission items: users count → users list; item page: search in its users
	await open(p, 'index.php?p=permissions');
	expect(await p.locator('a[href*="perm=' + item + '"]').count() === 1, 'permission users link');
	await open(p, 'index.php?p=permissions&id=' + item);
	await p.fill('#permUserSearch', 'zzz-none');
	expect(await p.locator('#permUserList li[data-st]:visible').count() === 0, 'permission users search');
	// admin roles: a system list (search, export)
	await open(p, 'index.php?p=adminroles');
	expect(await p.locator('.nuListCard #nuPrintButton, .nuListCard [data-nu-export], .nuListCard .dropdown-toggle').count() > 0 && await total() >= 1, 'admin roles list');
	// logged-in users: filters, grouped rows
	await open(p, 'index.php?p=sessions');
	expect(await p.locator('#sessFltUser').count() === 1 && await p.locator('#sessFltState').count() === 1, 'session filters');
	const g = p.locator('tr.nuSessGroup').first();
	if (await g.count()) {
		const id = await g.getAttribute('data-grp');
		expect(await p.locator(`tr[data-grp-of="${id}"]`).first().isHidden(), 'group folded');
		await g.locator('.nuSessFold').click();
		expect(await p.locator(`tr[data-grp-of="${id}"]`).first().isVisible(), 'group unfolded');
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.709: Logged-in users – phones show the filters only after the funnel next to the search; the nuBuilder Forte card starts folded
T.sessions_layout = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=sessions');
	expect(await p.locator('#sessFltUser').isVisible(), 'desktop: filters not shown at once');
	if (await p.locator('#sessForte').count()) {
		expect(await p.locator('#sessForte').isHidden(), 'Forte card not folded');
		await p.click('#sessForteBtn'); await p.waitForTimeout(500);
		expect(await p.locator('#sessForte').isVisible(), 'Forte card does not unfold');
	}
	const ph = await login(GA[0], GA[1], { viewport: { width: 390, height: 800 } });
	await open(ph, 'index.php?p=sessions');
	const same = await ph.evaluate(() => { const q = document.getElementById('q').getBoundingClientRect(), b = document.querySelector('#sessSearch .nuFilterToggle').getBoundingClientRect();
		return Math.abs(q.top - b.top) < 8; });
	expect(await ph.locator('#sessFltState').isHidden() && same, 'phone: filters shown / funnel not next to the search');
	await ph.click('#sessSearch .nuFilterToggle'); await ph.waitForTimeout(500);
	expect(await ph.locator('#sessFltState').isVisible(), 'phone: funnel does not show the filters');
	await open(ph, 'index.php?p=sessions&state=active');
	expect(await ph.locator('#sessFltState').isVisible(), 'phone: filters hidden while one is set');
	expect(ph.errors.length === 0 && p.errors.length === 0, [...ph.errors, ...p.errors].slice(0, 2).join(' | '));
	await ph.context().close();
};

T.permission_item = async (s) => {                      // sampleorder_BS + nuUserHasPermission: ben may not change the status, carla may
	const order = sql("SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1"), st = () => sql("SELECT ord_status FROM sample_order WHERE sample_order_id = '" + order + "'");
	const before = st();
	const change = async (user) => {
		const p = await login(user, UPW);
		await open(p, 'index.php?p=form&f=sampleorder&r=' + order);
		const ok = await p.evaluate(b => { const e = document.getElementById('ord_status'); if (!e || e.disabled) return false; const o = [...e.options].find(x => x.value && x.value !== b); if (!o) return false; e.value = o.value; e.dispatchEvent(new Event('change', { bubbles: true })); return true; }, st());
		if (ok) { await p.click('#nuSaveButton'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1000); }
		await p.context().close();
	};
	await change('sample_ben');
	expect(st() === before, 'sample_ben changed the status without the permission item');
	await change('sample_carla');
	expect(st() !== before, 'sample_carla (permission item sample_order_status) could not change the status');
};

T.rights_viewer = async (s) => {
	// sample_anna: access level sample_viewer (view only); an admin role of an earlier test is taken away for this run
	const viewer = 'sample_anna';
	sql("DELETE FROM zzzzsys_nunext_admin_user WHERE zzzzsys_nunext_admin_user_id = 'sample_anna'");
	const p = await login(viewer, UPW);
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + s.cust);
	expect(await p.locator('#nuSaveButton').count() === 0, 'viewer sees Save');
	const r = await open(p, 'index.php?p=builder&f=samplecustomer');
	expect(r.status() === 403, 'viewer opens the form builder (' + r.status() + ')');
	const r2 = await open(p, 'index.php?p=notifications&tab=manage');
	expect(r2.status() === 403, 'viewer opens notification management');
	await p.context().close();
};

// user form tabs General / Permission items (0.8.438): the tab is kept in the address and after Save; the count follows the ticks
T.user_tabs = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!id || +sql('SELECT COUNT(*) FROM zzzzsys_permission_item') === 0) { s.skip = 'no sample user or permission items'; return; }
	await open(p, 'index.php?p=user&id=' + id);
	await p.evaluate(() => { const s = document.getElementById('userPermState'); if (s) { s.value = 'all'; s.dispatchEvent(new Event('change')); } });   // 0.8.642: the list opens on "Assigned"
	expect(await p.isVisible('#userTabGeneral') && !await p.isVisible('#userPerms'), 'General first');

	await p.click('#userTabPermBtn'); await p.waitForTimeout(300);
	expect(new URL(p.url()).searchParams.get('tab') === 'perm' && await p.isVisible('#userPerms'), 'Permission items tab: ' + p.url());
	const n0 = +await p.textContent('#userPermTabCount');
	// sortable columns (0.8.611): ident ascending / descending, selected first; the order is kept in the browser
	const idents = () => p.$$eval('#userPerms tr.userPermItem .userPermIdent label', ls => ls.map(l => l.textContent));
	const cmp = (a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true });
	await p.click('th.userPermSort[data-sort="ident"] button');
	let li = await idents();
	expect(li.join() === li.slice().sort(cmp).join() && await p.getAttribute('th[data-sort="ident"]', 'aria-sort') === 'ascending', 'sort by ident');
	await p.click('th.userPermSort[data-sort="ident"] button');
	li = await idents();
	expect(li.join() === li.slice().sort(cmp).reverse().join(), 'ident descending');
	await p.click('th.userPermSort[data-sort="sel"] button');
	const sel = await p.$$eval('#userPerms tr.userPermItem input[name="perm[]"]', bs => bs.map(b => b.checked));
	expect(sel.indexOf(false) < 0 || sel.lastIndexOf(true) < sel.indexOf(false), 'selected first: ' + sel.join());
	await p.reload(); await p.click('#userTabPermBtn'); await p.waitForTimeout(300); await p.evaluate(() => { const s = document.getElementById('userPermState'); if (s) { s.value = 'all'; s.dispatchEvent(new Event('change')); } });
	expect(await p.getAttribute('th[data-sort="sel"]', 'aria-sort') === 'ascending', 'sort kept after reload');
	await p.click('th.userPermSort[data-sort="group"] button');   // back to the default order (group, ident)
	// link next to the ident (0.8.612): the item's edit page in a new tab; the row is not ticked by it
	const first = p.locator('#userPerms tr.userPermItem').first(), wasOn = await first.locator('input').isChecked();
	const ident0 = (await first.locator('.userPermIdent label').textContent()).trim();
	expect(await first.locator('a.userPermOpen').getAttribute('target') === '_blank', 'link opens a new tab');
	const [tab] = await Promise.all([p.context().waitForEvent('page'), first.locator('a.userPermOpen').click()]);
	await tab.waitForLoadState();
	expect(/p=permissions/.test(tab.url()) && /[?&]id=/.test(tab.url()) && await tab.inputValue('[name="pme_ident"]') === ident0, 'item opened for editing: ' + tab.url());
	await tab.close();
	expect(await first.locator('input').isChecked() === wasOn, 'the link does not tick the row');
	await p.evaluate(() => localStorage.removeItem('nunext-user-perm-sort'));
	// table (0.8.458): one row per item; a click on the description toggles the row's checkbox, a second one undoes it
	expect(await p.locator('#userPerms table.userPermTable tbody tr.userPermItem').count() === +sql('SELECT COUNT(*) FROM zzzzsys_permission_item'), 'one table row per item');
	const row = p.locator('#userPerms tr.userPermItem').first(), rowWas = await row.locator('input').isChecked();
	await row.locator('td.userPermDesc').click();
	expect(await row.locator('input').isChecked() !== rowWas, 'a click on the row toggles it');
	await row.locator('td.userPermDesc').click();
	expect(await row.locator('input').isChecked() === rowWas && +await p.textContent('#userPermTabCount') === n0, 'second click back, count unchanged');
	const box = p.locator('#userPerms input[name="perm[]"]').first(), was = await box.isChecked();
	await box.click();
	expect(+await p.textContent('#userPermTabCount') === n0 + (was ? -1 : 1), 'tab count');
	await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
	expect(new URL(p.url()).searchParams.get('tab') === 'perm' && await p.isVisible('#userPerms'), 'after Save on the tab: ' + p.url());
	await p.locator('#userPerms input[name="perm[]"]').first().click();   // back as it was
	await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
	await p.evaluate(() => { document.getElementById('sus_login_name').value = ''; });
	await p.click('[data-nu-save]', { noWaitAfter: true }); await p.waitForTimeout(400);
	expect(await p.isVisible('#userTabGeneral'), 'an invalid field opens General');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// user form password (0.8.587): existing user – placeholder "(unchanged)", no hint below; "Password last changed" in the (i) panel
T.user_pw_info = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!id) { s.skip = 'no sample user'; return; }
	const keep = sql(`SELECT IFNULL(HEX(sus_json), '-') FROM zzzzsys_user WHERE zzzzsys_user_id = '${id}'`);
	try {
		sql(`UPDATE zzzzsys_user SET sus_json = JSON_SET(COALESCE(NULLIF(sus_json, ''), '{}'), '$.PASSWORD_CHANGED_TIME', 1767225600, '$.PASSWORD_CHANGED_SOURCE', 'admin') WHERE zzzzsys_user_id = '${id}'`);
		await open(p, 'index.php?p=user&id=' + id);
		expect(/\S/.test(await p.getAttribute('#password', 'placeholder') || '') && !await p.isVisible('#passwordHint'), 'placeholder, no hint');
		expect(await p.locator('label[for^="sus_expires_on"] + .nuInfoTip').count() === 1, 'Valid until: hint on the (i)');
		await p.click('#nuRecInfoBtn'); await p.waitForTimeout(300);
		expect(/01[./]01[./]2026/.test(await p.textContent('#nuRecInfo [data-key="pw_changed"]')), '(i): password last changed');
		await open(p, 'index.php?p=user');
		expect(!(await p.getAttribute('#password', 'placeholder')) && await p.isVisible('#passwordHint'), 'new user: hint, no placeholder');
	} finally {
		sql(`UPDATE zzzzsys_user SET sus_json = ${keep === '-' ? 'NULL' : `UNHEX('${keep}')`} WHERE zzzzsys_user_id = '${id}'`);
	}
};

// 0.8.626: the user code is unique (trimmed, case-insensitive; empty never clashes) – user form and REST validation
T.user_code_unique = async (s) => {
	const p = s.ga;
	const ben = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'"), anna = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	if (!ben || !anna) { s.skip = 'no sample users'; return; }
	const old = sql(`SELECT CONCAT(IFNULL(sus_code, '~'), '|', (SELECT IFNULL(sus_code, '~') FROM zzzzsys_user WHERE zzzzsys_user_id = '${anna}')) FROM zzzzsys_user WHERE zzzzsys_user_id = '${ben}'`).split('|');
	const code = id => sql(`SELECT IFNULL(sus_code, '') FROM zzzzsys_user WHERE zzzzsys_user_id = '${id}'`);
	try {
		sql(`UPDATE zzzzsys_user SET sus_code = 'E2EBEN' WHERE zzzzsys_user_id = '${ben}'`);
		await open(p, 'index.php?p=user&id=' + anna);
		await p.fill('#sus_code', ' e2eben ');
		await Promise.all([p.waitForNavigation(), p.click('.nuBtnSave')]);
		expect(await p.locator('#sus_code.is-invalid').count() === 1 && /Ben/.test(await p.textContent('#sus_code ~ .invalid-feedback')), 'duplicate code accepted / no message');
		expect(code(anna) !== 'e2eben' && code(anna).trim().toLowerCase() !== 'e2eben', 'duplicate code saved');
		await open(p, 'index.php?p=user&id=' + anna);
		await p.fill('#sus_code', 'E2EANNA');
		await Promise.all([p.waitForNavigation(), p.click('.nuBtnSave')]);
		expect(code(anna) === 'E2EANNA', 'unique code not saved: ' + code(anna));
		sql(`UPDATE zzzzsys_user SET sus_code = NULL WHERE zzzzsys_user_id IN ('${ben}', '${anna}')`);
		await open(p, 'index.php?p=user&id=' + anna);
		await Promise.all([p.waitForNavigation(), p.click('.nuBtnSave')]);
		expect(await p.locator('#sus_code.is-invalid').count() === 0, 'empty codes clash');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql(`UPDATE zzzzsys_user SET sus_code = ${old[0] === '~' ? 'NULL' : `'${old[0]}'`} WHERE zzzzsys_user_id = '${ben}'; UPDATE zzzzsys_user SET sus_code = ${old[1] === '~' ? 'NULL' : `'${old[1]}'`} WHERE zzzzsys_user_id = '${anna}'`);
	}
};

// permission items: Undo after All / None (0.8.584) – back to the ticks before; a change by hand ends it
// permission item page (0.8.613): how to check the item in PHP / JavaScript and in SQL (#USER_PERMISSIONS# and the sub-query); the SQL runs
T.perm_sql_hint = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item WHERE pme_ident = 'sample_export'");
	if (!id) { s.skip = 'no sample permission items'; return; }
	await open(p, 'index.php?p=permissions&id=' + id);
	expect(!(await p.getAttribute('#permUsage', 'open') !== null) && !(await p.isVisible('#permUsage pre')), 'Usage in code folded by default (0.8.614)');
	await p.click('#permUsage > summary');
	expect(await p.isVisible('#permUsage pre'), 'a click shows the examples');
	const codes = await p.$$eval('#permUsage pre code', cs => cs.map(c => c.textContent));
	expect(codes.length === 3 && /nuUserHasPermission\('sample_export'\)/.test(codes[0]) && /#USER_PERMISSIONS#/.test(codes[1]) && /#GLOBAL_ACCESS#/.test(codes[1]) && /EXISTS/.test(codes[2]), 'three code examples: ' + codes.join(' | '));
	const ben = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	const has = sql("SELECT COUNT(*) FROM zzzzsys_user_permission WHERE upe_zzzzsys_user_id = '" + ben + "' AND upe_ident = '" + id + "' AND upe_active = '1'");
	const perms = sql("SELECT GROUP_CONCAT(pme_ident ORDER BY pme_ident SEPARATOR ', ') FROM zzzzsys_user_permission JOIN zzzzsys_permission_item ON zzzzsys_permission_item_id = upe_ident WHERE upe_zzzzsys_user_id = '" + ben + "' AND upe_active = '1'").replace('NULL', '');
	const run = (c) => sql('SELECT CASE WHEN EXISTS (SELECT 1 FROM zzzzsys_user ' + c.replace('#USER_PERMISSIONS#', perms).replace('#GLOBAL_ACCESS#', '0').replace('#USER_ID#', ben) + ') THEN 1 ELSE 0 END');
	expect(run(codes[1]) === has && run(codes[2]) === has, 'the SQL examples find the item of sample_ben: ' + [has, run(codes[1]), run(codes[2])].join('/'));
	expect(await p.locator('a[href*="#permissions-sql"]').count() === 1, 'link to the help');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.perm_undo = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!id || +sql('SELECT COUNT(*) FROM zzzzsys_permission_item') < 2) { s.skip = 'no sample user or permission items'; return; }
	await open(p, 'index.php?p=user&id=' + id + '&tab=perm');
	await p.evaluate(() => { const s = document.getElementById('userPermState'); if (s) { s.value = 'all'; s.dispatchEvent(new Event('change')); } });   // 0.8.642: the list opens on "Assigned"
	const states = () => p.$$eval('#userPerms input[name="perm[]"]', a => a.map(c => c.checked).join(''));
	const undoShown = () => p.isVisible('#userPermUndo');
	const s0 = await states();
	expect(!await undoShown(), 'no Undo at first');
	await p.click('[data-perm-all="0"]');
	if (s0.includes('true')) {
		expect(!(await states()).includes('true') && await undoShown(), 'None: all off, Undo shown');
		await p.click('#userPermUndo');
		expect(await states() === s0 && !await undoShown(), 'Undo after None restores');
	}
	await p.click('[data-perm-all="1"]'); await p.waitForTimeout(400);
	if (await p.locator('#nuConfirm.show .js-confirm-ok').count()) { await p.click('#nuConfirm.show .js-confirm-ok'); await p.waitForTimeout(300); }
	expect(!(await states()).includes('false') && await undoShown(), 'All: all on, Undo shown');
	await p.click('#userPermUndo');
	expect(await states() === s0 && !await undoShown(), 'Undo after All restores');
	await p.click('[data-perm-all="1"]'); await p.waitForTimeout(400);
	if (await p.locator('#nuConfirm.show .js-confirm-ok').count()) { await p.click('#nuConfirm.show .js-confirm-ok'); await p.waitForTimeout(300); }
	await p.locator('#userPerms input[name="perm[]"]').first().click();
	expect(!await undoShown(), 'a change by hand hides Undo');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.657: "Send welcome e-mail" on the user form is always shown – greyed out with "Set up e-mail first" and a link to the E-mail
// settings while no SMTP host is set (and no $nuConfigMailLog), usable with one
T.user_welcome_switch = async (s) => {
	const p = s.ga, host = sql('SELECT set_smtp_host FROM zzzzsys_setup LIMIT 1');
	const state = () => p.evaluate(() => { const c = document.getElementById('user_send_welcome_email'); return c && { disabled: c.disabled, link: !!document.getElementById('userWelcomeMailLink'), tip: document.getElementById('welcomeHint').textContent }; });
	try {
		sql("UPDATE zzzzsys_setup SET set_smtp_host = ''");
		await open(p, 'index.php?p=user');
		const off = await state();
		expect(off && off.disabled && off.link && /Set up e-mail first/.test(off.tip), 'without e-mail: ' + JSON.stringify(off));
		if (!/nuConfigMailLog\s*=\s*"[^"]/.test(fs.readFileSync(path.join(SITE || '.', 'nuconfig.php'), 'utf8').replace(/\/\/.*$/gm, ''))) {
			expect(await p.getAttribute('#userWelcomeMailLink', 'href') === 'index.php?p=email&tab=settings', 'link to the E-mail settings');
		}
		sql("UPDATE zzzzsys_setup SET set_smtp_host = 'smtp.example.com'");
		await open(p, 'index.php?p=user');
		const on = await state();
		expect(on && !on.disabled && !on.link && !/Set up e-mail first/.test(on.tip), 'with e-mail: ' + JSON.stringify(on));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql(`UPDATE zzzzsys_setup SET set_smtp_host = '${String(host).replace(/'/g, "''")}'`);
	}
};

// 0.8.901: password reset by an administrator – link by e-mail (old password still works), link shown once + old password blocked
// (sessions end, older link replaced), the user sets the password with the link, confirmation e-mail, rate limit
T.user_new_page = async (s) => {
	// 0.8.976: the new-user form (no id) showed PHP warnings "Undefined array key nu_pwr_link" (an empty id matched the missing link row)
	const p = s.ga;
	await open(p, 'index.php?p=user'); await p.waitForSelector('#nuEditForm, form', {state: 'attached'});
	const txt = await p.evaluate(() => document.body.innerText);
	expect(!/Warning:|Notice:|Deprecated:/.test(txt), 'new user page without PHP messages: ' + (txt.match(/(Warning|Notice|Deprecated):[^\n]{0,120}/) || [''])[0]);
};

T.pw_reset_admin = async (s) => {
	const p = s.ga, port = 2600 + Math.floor(Math.random() * 300), slog = path.join(os.tmpdir(), 'e2e-pwr-' + Date.now() + '.log'), sdata = slog + '.eml';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-pwr.sql');
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_eva'");
	if (!uid) { s.skip = 'no sample user'; return; }
	execSync('mysqldump ' + DB + ' zzzzsys_setup zzzzsys_email_log zzzzsys_nunext_token zzzzsys_user zzzzsys_nunext_session > ' + keep);
	const srv = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), slog, String(port), sdata], { stdio: 'ignore' });
	const last = () => {
		const msgs = fs.existsSync(sdata) ? fs.readFileSync(sdata, 'utf8').split('=====END=====').filter(x => x.trim()) : [];
		if (!msgs.length) return { subject: '', body: '' };
		return JSON.parse(execSync('python3 -I -c "import sys,email,email.policy,json;m=email.message_from_string(sys.stdin.read(),policy=email.policy.default);b=m.get_body((\'html\',\'plain\'));print(json.dumps({\'subject\':str(m[\'subject\']),\'body\':b.get_content() if b else \'\'}))"', { input: msgs[msgs.length - 1].replace(/^\s+/, '') }).toString());
	};
	const hash = () => sql(`SELECT sus_login_password FROM zzzzsys_user WHERE zzzzsys_user_id = '${uid}'`);
	const reauth = async (url) => { if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, url); return true; } return false; };
	const send = async (how, block, other) => {
		const url = 'index.php?p=user&id=' + uid;
		for (let i = 0; i < 2; i++) {
			await open(p, url);
			await p.click('#pwrOpen'); await p.waitForSelector('#pwrDlg.show');
			await p.check(how === 'mail' ? '#pwrHowMail' : '#pwrHowLink');
			if (block) await p.check('#pwrBlock');
			if (other) { await p.fill('#pwrEmail', other.email); await p.selectOption('#pwrLang', other.lang); }
			await Promise.all([p.waitForNavigation(), p.click('#pwrSend')]); await p.waitForLoadState('networkidle');
			if (!(await reauth(url))) return;
		}
	};
	let ev = null, anon = null;
	try {
		await new Promise(r => setTimeout(r, 600));
		sql("UPDATE zzzzsys_setup SET set_smtp_host = '127.0.0.1', set_smtp_port = " + port + ", set_smtp_use_ssl = '0', set_smtp_use_authentication = '0', set_smtp_from_address = 'noreply@example.com'");
		const pwHash = execSync(`php -r 'echo password_hash("Test1234!", PASSWORD_BCRYPT);'`).toString();
		sql(`UPDATE zzzzsys_user SET sus_login_password = '${pwHash}', sus_email = 'eva@example.com', sus_language = '', sus_expires_on = NULL, sus_change_password = '0' WHERE zzzzsys_user_id = '${uid}'`);
		sql(`DELETE FROM zzzzsys_nunext_log WHERE event = 'password_reset_link' AND record_id = '${uid}'`);
		ev = await login('sample_eva', 'Test1234!');
		expect(!/p=login/.test(ev.url()), 'Eva signed in');
		// preview of the e-mail (placeholder instead of the link)
		const pv = await p.evaluate(async (u) => (await fetch(u)).text(), BASE + 'index.php?p=user&id=' + uid + '&pwreset_frame=1');
		expect(/Set new password/.test(pv) && /#SET_PASSWORD_URL#/.test(pv), 'preview: ' + pv.slice(0, 120));
		// 1. by e-mail: the old password keeps working
		const h0 = hash();
		await send('mail', false);
		let m = last();
		expect(/eva@example\.com/.test(await p.textContent('body')) && m.subject === 'Set a new password' && /p=reset/.test(m.body), 'mail sent: ' + JSON.stringify(m).slice(0, 200));
		const link1 = (m.body.match(/href="([^"]*p=reset[^"]*)"/) || [])[1].replace(/&amp;/g, '&');
		expect(hash() === h0, 'password unchanged after the mail');
		// 1b. another address and language: sent there in German, the user's row unchanged
		await send('mail', false, { email: 'other@example.com', lang: 'de-DE' });
		m = last();
		const raw = fs.readFileSync(sdata, 'utf8').split('=====END=====').filter(x => x.trim()).pop();
		expect(/other@example\.com/.test(raw) && m.subject !== 'Set a new password' && /p=reset/.test(m.body), 'other address + German: ' + m.subject);
		expect(sql(`SELECT CONCAT(sus_email, '|', IFNULL(sus_language, '')) FROM zzzzsys_user WHERE zzzzsys_user_id = '${uid}'`) === 'eva@example.com|', 'user row unchanged');
		// 2. link shown once + block: hash changed, Eva signed out, the first link replaced
		await send('link', true);
		const link2 = await p.inputValue('#pwrLinkUrl');
		expect(/p=reset/.test(link2) && hash() !== h0, 'link shown, password blocked');
		await ev.goto(BASE + 'index.php?p=home'); await ev.waitForLoadState('networkidle');
		expect(/p=login/.test(ev.url()) || await ev.locator('#username').count() === 1, 'Eva signed out: ' + ev.url());
		anon = await (await browser.newContext({ locale: 'en-GB' })).newPage();
		await anon.goto(link1); await anon.waitForLoadState('networkidle');
		expect(await anon.locator('#pw_new').count() === 0, 'first link no longer valid');
		// 3. Eva sets the password with the shown link → confirmation e-mail, sign-in works
		await anon.goto(link2); await anon.waitForLoadState('networkidle');
		await anon.fill('#pw_new', 'NewPass-4711x'); await anon.fill('#pw_confirm', 'NewPass-4711x');
		await Promise.all([anon.waitForNavigation(), anon.click('form button[type=submit]')]);
		m = last();
		expect(m.subject === 'Your password was changed' && /sample_eva/.test(m.body), 'confirmation: ' + JSON.stringify(m).slice(0, 200));
		const ev2 = await login('sample_eva', 'NewPass-4711x');
		expect(!/p=login/.test(ev2.url()), 'sign-in with the new password');
		await ev2.context().close();
		// 4. rate limit (log_time ahead: the site counts in its own time zone, the mysql client's NOW() may be UTC)
		for (let i = 0; i < 5; i++) sql(`INSERT INTO zzzzsys_nunext_log (zzzzsys_nunext_log_id, log_time, user_id, login, ip, event, object, record_id, details) VALUES ('e2epwr${i}', NOW() + INTERVAL 14 HOUR, 'globeadmin', 'globeadmin', '127.0.0.1', 'password_reset_link', 'zzzzsys_user', '${uid}', '{}')`);
		await send('link', false);
		expect(/Too many reset links/.test(await p.textContent('body')), 'rate limit');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		srv.kill();
		if (ev) await ev.context().close().catch(() => {});
		if (anon) await anon.context().close().catch(() => {});
		sql("DELETE FROM zzzzsys_nunext_log WHERE zzzzsys_nunext_log_id LIKE 'e2epwr%'");
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.649: Copy from user… – another user's active items are ticked (Add keeps the ticked ones, Replace = exactly theirs), Undo, saved with Save
T.perm_copy = async (s) => {
	const p = s.ga, uid = (l) => sql(`SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = '${l}'`);
	const ben = uid('sample_ben'), dario = uid('sample_dario');
	if (!ben || !dario) { s.skip = 'no sample users'; return; }
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-permcopy.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_user_permission > ' + keep);
	const active = (u) => sql(`SELECT GROUP_CONCAT(upe_ident ORDER BY upe_ident) FROM zzzzsys_user_permission WHERE upe_zzzzsys_user_id = '${u}' AND upe_active = '1'`);
	try {
		sql(`DELETE FROM zzzzsys_user_permission WHERE upe_zzzzsys_user_id IN ('${ben}', '${dario}')`);
		sql(`INSERT INTO zzzzsys_user_permission (zzzzsys_user_permission_id, upe_zzzzsys_user_id, upe_ident, upe_active) VALUES
			('e2epc1', '${ben}', 'sample_pi_export', '1'), ('e2epc2', '${dario}', 'sample_pi_order_status', '1'), ('e2epc3', '${dario}', 'sample_pi_discount', '1'),
			('e2epc4', '${dario}', 'sample_pi_export', '0')`);   // an inactive row is not copied
		await open(p, 'index.php?p=user&id=' + ben + '&tab=perm');
		const ticked = () => p.$$eval('#userPerms input[name="perm[]"]:checked', a => a.map(c => c.value).sort().join(','));
		const pick = async () => {
			await p.click('#userPermCopy'); await p.waitForSelector('#userPermCopyDlg.show'); await p.waitForTimeout(300);
			await p.fill('#userPermCopyDlg .ts-control input', 'sample_dario'); await p.waitForTimeout(300); await p.keyboard.press('Enter');
			await p.waitForFunction(() => !document.getElementById('userPermCopyReplace').disabled, null, { timeout: 5000 });
			return p.textContent('#userPermCopyInfo');
		};
		expect(/2\D+2/.test(await pick()), 'info: 2 items, 2 new');
		await p.click('#userPermCopyAdd'); await p.waitForSelector('#userPermCopyDlg', { state: 'hidden' });
		expect(await ticked() === 'sample_pi_discount,sample_pi_export,sample_pi_order_status', 'Add keeps the ticked item: ' + await ticked());
		expect(await p.textContent('#userPermCount') === '3' && await p.isVisible('#userPermUndo'), 'count 3, Undo shown');
		await p.click('#userPermUndo');
		expect(await ticked() === 'sample_pi_export', 'Undo after Add');
		await pick();
		await p.click('#userPermCopyReplace'); await p.waitForSelector('#userPermCopyDlg', { state: 'hidden' });
		expect(await ticked() === 'sample_pi_discount,sample_pi_order_status', 'Replace = exactly the other user\'s active items: ' + await ticked());
		expect(await p.locator('.userPermItem:not(.d-none)').count() === 2, 'the "Assigned" list shows the result');
		expect(active(ben) === 'sample_pi_export', 'nothing saved before Save');
		await Promise.all([p.waitForNavigation(), p.click('button.nuBtnSave[data-nu-save]')]);
		expect(active(ben) === 'sample_pi_discount,sample_pi_order_status', 'saved: ' + active(ben));
		const bad = await p.evaluate(async (url) => {   // an unknown user is refused; the form's own CSRF token
			const fd = new FormData(); fd.append('_csrf', document.getElementById('userPermCopyDlg').getAttribute('data-csrf')); fd.append('action', 'perm_of'); fd.append('from', 'no_such_user');
			return (await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })).json();
		}, await p.getAttribute('#userPermCopyDlg', 'data-url'));
		expect(bad && bad.ok === false, 'unknown user refused: ' + JSON.stringify(bad));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.877: passkeys with Chrome's virtual authenticator (the site as localhost – browsers refuse passkeys for IP addresses): add one
// under Security, use it as the second factor, sign in without a password (button; the user name field offers it too), the admin's
// view on the user form, remove it; on 127.0.0.1 the card explains why passkeys cannot be used
T.passkeys = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	if (!/127\.0\.0\.1|localhost/.test(BASE)) { s.skip = 'BASE is not local'; return; }
	const LB = BASE.replace('127.0.0.1', 'localhost');
	const anna = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	if (!anna) { s.skip = 'no sample users'; return; }
	const acc = sql(`SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE zzzzsys_user_id = '${anna}'`);
	const use2fa = sql(`SELECT IFNULL(sal_use_2fa, '') FROM zzzzsys_access WHERE zzzzsys_access_id = '${acc}'`);
	const cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	const secRow = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'security'");
	const setSec = (o) => { const v = Object.assign(JSON.parse(secRow || '{}'), o); sql(`REPLACE INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('security', '${JSON.stringify(v)}')`); };
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 1200, height: 900 } });
	const p = await ctx.newPage();
	p.errors = [];
	p.on('pageerror', e => p.errors.push(e.message));
	p.on('dialog', d => d.accept());
	const cdp = await ctx.newCDPSession(p);
	await cdp.send('WebAuthn.enable');
	const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal',
		hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
	const pwLogin = async () => {
		await p.goto(LB + 'index.php?p=login'); await p.waitForLoadState('networkidle');
		await p.fill('#username', 'sample_anna'); await p.fill('#password', UPW);
		await Promise.all([p.waitForNavigation(), p.click('form button[type=submit]:not([name=demo_user])')]);
	};
	sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
	try {
		sql(`DELETE FROM zzzzsys_nunext_passkey WHERE user_id = '${anna}'`);
		// add a passkey under Security
		await pwLogin();
		expect(/p=home/.test(p.url()), 'password sign-in: ' + p.url());
		await p.goto(LB + 'index.php?p=security'); await p.waitForLoadState('networkidle');
		expect(await p.locator('#secPasskeys #nuPkAdd').count() === 1, 'Add a passkey on the security page');
		await p.fill('#pkName', 'E2E key');
		await Promise.all([p.waitForNavigation(), p.click('#nuPkAdd')]);
		await p.waitForLoadState('networkidle');
		expect(await p.locator('#secPkList li').count() === 1 && /E2E key/.test(await p.textContent('#secPkList')), 'passkey listed');
		const row = sql(`SELECT CONCAT(name, '|', alg, '|', sign_count) FROM zzzzsys_nunext_passkey WHERE user_id = '${anna}'`);
		expect(/^E2E key\|-7\|\d+$/.test(row), 'stored: ' + row);
		// second factor: the access level asks for 2FA → the passkey is offered first
		sql(`UPDATE zzzzsys_access SET sal_use_2fa = '1' WHERE zzzzsys_access_id = '${acc}'`);
		fs.writeFileSync(cfg, orig.replace(/\?>\s*$/, '') + '\n$nuConfig2FAUser = true;\n');
		await new Promise(r => setTimeout(r, 3500));                // OPcache checks the file every 2 s
		await ctx.clearCookies();
		await pwLogin();
		expect(/p=twofa/.test(p.url()) && await p.locator('#nuPk2fa').count() === 1, 'second factor page with the passkey: ' + p.url());
		await Promise.all([p.waitForNavigation(), p.click('#nuPk2fa')]);
		expect(/p=home/.test(p.url()), 'signed in with the passkey as second factor: ' + p.url());
		expect(/"2fa":"passkey"/.test(sql(`SELECT details FROM zzzzsys_nunext_log WHERE event = 'login' AND user_id = '${anna}' ORDER BY log_time DESC LIMIT 1`)), 'log: 2fa passkey');
		fs.writeFileSync(cfg, orig);
		sql(`UPDATE zzzzsys_access SET sal_use_2fa = '${use2fa}' WHERE zzzzsys_access_id = '${acc}'`);
		await new Promise(r => setTimeout(r, 3500));
		// without a password: the button (the offer in the user name field waits while the authenticator needs a touch)
		setSec({ passkey_login: true });
		await ctx.clearCookies();
		await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: false });
		await p.goto(LB + 'index.php?p=login'); await p.waitForLoadState('networkidle');
		expect(await p.locator('#nuPkLogin').count() === 1 && /webauthn/.test(await p.getAttribute('#username', 'autocomplete')), 'passkey sign-in offered');
		const before = +sql(`SELECT sign_count FROM zzzzsys_nunext_passkey WHERE user_id = '${anna}'`);
		await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: true });
		await Promise.all([p.waitForNavigation({ timeout: 15000 }), p.click('#nuPkLogin')]);
		expect(/p=home/.test(p.url()), 'signed in without a password: ' + p.url());
		expect(/"via":"passkey"/.test(sql(`SELECT details FROM zzzzsys_nunext_log WHERE event = 'login' AND user_id = '${anna}' ORDER BY log_time DESC LIMIT 1`)), 'log: via passkey');
		expect(+sql(`SELECT sign_count FROM zzzzsys_nunext_passkey WHERE user_id = '${anna}'`) > before, 'counter went up');
		// the admin sees it on the user form
		await open(s.ga, 'index.php?p=user&id=' + anna);
		expect(await s.ga.locator('#userPasskeys li').count() === 1, 'user form lists the passkey');
		// 127.0.0.1: no passkeys (browsers refuse IP addresses) – the card says why
		await open(s.ga, 'index.php?p=security');
		expect(await s.ga.locator('#secPkProblem').count() === 1 && await s.ga.locator('#nuPkAdd').count() === 0, 'IP address: passkeys explained, no Add');
		// remove it
		await p.goto(LB + 'index.php?p=security'); await p.waitForLoadState('networkidle');
		await p.click('#secPkList form button[type=submit]');
		await p.waitForTimeout(400);
		if (await p.locator('#nuConfirm.show .js-confirm-ok').count()) await Promise.all([p.waitForNavigation(), p.click('#nuConfirm.show .js-confirm-ok')]);
		await p.waitForLoadState('networkidle');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_passkey WHERE user_id = '${anna}'`) === '0', 'passkey removed');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		fs.writeFileSync(cfg, orig);
		sql(`UPDATE zzzzsys_access SET sal_use_2fa = '${use2fa}' WHERE zzzzsys_access_id = '${acc}'`);
		if (secRow) sql(`REPLACE INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('security', '${secRow.replace(/'/g, "''")}')`);
		else sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'security'");
		sql(`DELETE FROM zzzzsys_nunext_passkey WHERE user_id = '${anna}'`);
		await ctx.close();
		await new Promise(r => setTimeout(r, 2500));
	}
};

// single sign-on (0.8.863): settings, Test sign-in, button on the sign-in page, existing user, refusals (no user, bad signature,
// unverified e-mail, provider error, foreign state), new user by the group map, "SSO only" refuses the password – fake provider dev/tests/fakeoidc.py
T.sso_login = async (s) => {
	const p = s.ga, anna = 'sample_anna', port = 3100 + Math.floor(Math.random() * 400), iss = 'http://127.0.0.1:' + port;
	const olog = path.join(os.tmpdir(), 'e2e-oidc-' + Date.now() + '.log');
	const lvl = sql(`SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE sus_login_name = '${anna}'`);
	const oldMail = sql(`SELECT IFNULL(HEX(sus_email), '-') FROM zzzzsys_user WHERE sus_login_name = '${anna}'`);
	const prov = require('child_process').spawn('python3', [path.join(__dirname, 'fakeoidc.py'), olog, String(port)], { stdio: 'ignore' });
	const person = async (j) => { const r = await fetch(iss + '/set', { method: 'POST', body: JSON.stringify(j) }); expect(r.ok, 'fake provider /set'); };
	const fresh = async () => {                          // a visitor in English
		const ctx = await browser.newContext(); const q = await ctx.newPage();
		await q.goto(BASE + 'index.php?p=lang&l=en-GB&r=p%3Dlogin');
		return q;
	};
	const ssoLogin = async () => {
		const q = await fresh();
		expect(await q.locator('#nuSsoBtn').count() === 1, 'no SSO button on the sign-in page');
		await Promise.all([q.waitForLoadState('networkidle'), q.click('#nuSsoBtn')]);
		await q.waitForLoadState('networkidle');
		return q;
	};
	const save = async () => { await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]); await p.waitForLoadState('networkidle'); };
	try {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('sso', 'sso_cache')");
		sql("DELETE FROM zzzzsys_nunext_log WHERE event IN ('login_failed', 'login_locked')");
		sql(`UPDATE zzzzsys_user SET sus_email = 'anna.sso@example.com' WHERE sus_login_name = '${anna}'`);
		await new Promise(r => setTimeout(r, 800));
		await open(p, 'index.php?p=sso');
		expect((await p.inputValue('#ssoRedirect')).endsWith('/oauth.php'), 'redirect URI');
		expect(await p.locator('#ssoTestBtn.disabled').count() === 1, 'Test sign-in enabled without a provider');
		// errors: on without issuer / client
		await p.check('#ssoOn');
		await save();
		expect(await p.locator('#ssoIssuer.is-invalid').count() === 1 && await p.locator('#ssoClientId.is-invalid').count() === 1, 'missing fields not marked');
		await p.selectOption('#ssoKind', 'other');
		await p.fill('#ssoIssuer', iss + '/'); await p.fill('#ssoClientId', 'cid'); await p.fill('#ssoSecret', 'csec');
		await p.uncheck('#ssoOn');                       // first test while still off
		await save();
		let st = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'sso'"));
		expect(st.issuer === iss && st.client_id === 'cid' && st.on === false, 'stored: ' + JSON.stringify(st));
		expect(st.client_secret !== 'csec' && /^(enc|plain):/.test(st.client_secret), 'secret stored as is');
		// Test sign-in (off): result for anna, nothing changed, still the globeadmin
		await person({ email: 'Anna.SSO@example.com', name: 'Anna', groups: [] });
		await Promise.all([p.waitForURL(/p=sso/, { timeout: 15000 }), p.click('#ssoTestBtn')]);
		await p.waitForLoadState('networkidle');
		expect(await p.getAttribute('#ssoTestResult .alert', 'data-ok') === '1' && /sample_anna/.test(await p.textContent('#ssoTestResult')), 'test result: ' + await p.textContent('#ssoTestCard'));
		expect(/anna\.sso@example\.com/i.test(await p.textContent('#ssoTestClaims')), 'claims shown');
		const ol = fs.readFileSync(olog, 'utf8');
		expect(/authorize ok=True/.test(ol) && /token code=ok/.test(ol) && !/refused/.test(ol), 'PKCE / client: ' + ol.split('\n').join(' | '));
		// off: no button on the sign-in page
		let q = await fresh();
		expect(await q.locator('#nuSsoBtn').count() === 0, 'SSO button while off');
		await q.context().close();
		// on: anna signs in with SSO
		await open(p, 'index.php?p=sso'); await p.check('#ssoOn'); await save();
		q = await ssoLogin();
		expect(!/p=login/.test(q.url()) && await q.locator('#nuSsoBtn').count() === 0, 'anna not signed in: ' + q.url() + ' ' + (await q.textContent('body')).slice(0, 300));
		expect(+sql(`SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'login' AND details LIKE '%sso%' AND user_id = (SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = '${anna}')`) >= 1, 'login via sso not logged');
		await q.context().close();
		// refusals
		const refused = async (j, re, what) => {
			await person(j);
			const r = await ssoLogin();
			expect(/p=login/.test(r.url()) && re.test(await r.textContent('body')), what + ': ' + r.url() + ' ' + (await r.textContent('body')).replace(/\s+/g, ' ').slice(0, 300));
			await r.context().close();
		};
		await refused({ email: 'nobody@example.com', name: 'Nobody' }, /no user with this e-mail/, 'unknown user');
		await refused({ email: 'anna.sso@example.com', bad_sig: true }, /could not be verified \(signature\)/, 'bad signature');
		await refused({ email: 'anna.sso@example.com', email_verified: false }, /no verified e-mail/, 'unverified e-mail');
		await refused({ email: 'anna.sso@example.com', aud: 'other-client' }, /could not be verified \(audience\)/, 'audience');
		await refused({ email: 'anna.sso@example.com', nonce: 'x' }, /could not be verified \(nonce\)/, 'nonce');
		await refused({ email: 'anna.sso@example.com', deny: true }, /refused the sign-in: access_denied/, 'provider error');
		q = await fresh();
		await q.goto(BASE + 'oauth.php?code=x&state=sso.foreign'); await q.waitForLoadState('networkidle');
		expect(/p=login/.test(q.url()) && /too long or was started in another window/.test(await q.textContent('body')), 'foreign state');
		await q.context().close();
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'sso_refused'") >= 6, 'refusals not logged');
		// new users: by the group map (first match), with the domain check
		await open(p, 'index.php?p=sso');
		await p.check('#ssoCreate');
		expect(await p.locator('#ssoDefaultWrap').isVisible(), 'default level hidden');
		await p.fill('#ssoDomains', 'example.com');
		await p.locator('#ssoMap tr.ssoMapRow input').first().fill('Staff');
		await p.locator('#ssoMap tr.ssoMapRow select').first().selectOption(lvl);
		await save();
		st = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'sso'"));
		expect(st.create && st.group_map.length === 1 && st.group_map[0].access === lvl && st.client_secret !== '', 'map / secret kept: ' + JSON.stringify(st));
		await refused({ email: 'new@other.org', groups: ['staff'] }, /this e-mail domain/, 'domain');
		await person({ email: 'new.sso@example.com', name: 'New Person', groups: ['other', 'staff'] });
		q = await ssoLogin();
		expect(!/p=login/.test(q.url()), 'new user not signed in: ' + (await q.textContent('body')).slice(0, 200));
		await q.context().close();
		expect(sql("SELECT CONCAT(sus_zzzzsys_access_id, '|', sus_name) FROM zzzzsys_user WHERE sus_email = 'new.sso@example.com'") === lvl + '|New Person', 'created user');
		// SSO only for anna's level: the password is refused, the globeadmin is not affected
		await open(p, 'index.php?p=sso');
		await p.locator(`#ssoOnlyCard input[value="${lvl}"]`).check();
		await save();
		q = await fresh();
		await q.fill('#username', anna); await q.fill('#password', UPW);
		await Promise.all([q.waitForLoadState('networkidle'), q.click('form button[type=submit]:not([name=demo_user])')]);
		expect(/p=login/.test(q.url()) && /sign in with single sign-on/i.test(await q.textContent('body')), 'password not refused: ' + q.url());
		await q.context().close();
		await open(p, 'index.php?p=sso');
		expect(/p=sso/.test(p.url()), 'globeadmin lost the page');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		prov.kill();
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('sso', 'sso_cache')");
		sql("DELETE FROM zzzzsys_user WHERE sus_email = 'new.sso@example.com'");
		sql(`UPDATE zzzzsys_user SET sus_email = ${oldMail === '-' ? 'NULL' : "UNHEX('" + oldMail + "')"} WHERE sus_login_name = '${anna}'`);
		sql("DELETE FROM zzzzsys_nunext_log WHERE event IN ('login_failed', 'login_locked', 'sso_refused', 'sso_test', 'sso_user_created', 'sso_access_changed')");
		fs.rmSync(olog, { force: true });
	}
};

T.ip_access = async (s) => {                            // 0.8.722: IP access – default list, exception per level, globeadmin rule, lock-out guard, session check, test, cron off
	const p = s.ga, anna = 'sample_anna';
	const lvl = sql(`SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE sus_login_name = '${anna}'`);
	const set = (j) => sql(`REPLACE INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('ip_access', '${JSON.stringify(j)}')`);
	const tryLogin = async () => {
		const ctx = await browser.newContext(); const q = await ctx.newPage();
		await q.goto(BASE + 'index.php?p=login'); await q.fill('#username', anna); await q.fill('#password', UPW);
		await Promise.all([q.waitForLoadState('networkidle'), q.click('form button[type=submit]:not([name=demo_user])')]);
		return q;
	};
	try {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'ip_access'");
		sql("DELETE FROM zzzzsys_nunext_log WHERE event IN ('login_failed', 'ip_refused')");
		await open(p, 'index.php?p=ipaccess');
		expect(await p.textContent('#ipaMine') === '127.0.0.1', 'own address: ' + await p.textContent('#ipaMine'));
		// lock-out guard: globeadmin "same as all access levels" with a list without 127.0.0.1
		await p.check('#ipaOn'); await p.fill('#ipaDefault', '10.99.0.0/16  # office\n203.0.113.7');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]); await p.waitForLoadState('networkidle');
		expect(await p.locator('#ipaSelfErr').count() === 1, 'lock-out not refused');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'ip_access'") === '0', 'saved although locked out');
		// invalid entry
		await p.fill('#ipaDefault', '10.99.0.0/16\nnot-an-ip');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]); await p.waitForLoadState('networkidle');
		expect(await p.locator('#ipaDefault.is-invalid').count() === 1 && /not-an-ip/.test(await p.textContent('#ipaDefaultCard')), 'invalid rule not marked');
		// own ranges field only for "Own ranges"; globeadmin any address → saved
		expect(await p.locator('#ipaGaRangesWrap').isHidden(), 'ga ranges shown for default');
		await p.selectOption('#ipaGaMode', 'ranges');
		expect(await p.locator('#ipaGaRangesWrap').isVisible(), 'ga ranges not shown');
		await p.selectOption('#ipaGaMode', 'any');
		await p.fill('#ipaDefault', '10.99.0.0/16  # office\n203.0.113.7');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]); await p.waitForLoadState('networkidle');
		const st = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'ip_access'"));
		expect(st.on === true && st.default.join(',') === '10.99.0.0/16,203.0.113.7' && st.ga.mode === 'any', 'stored: ' + JSON.stringify(st));
		// a user of a level without an exception is refused at sign-in (and logged)
		let q = await tryLogin();
		expect(/p=login/.test(q.url()) && /not allowed from this network/.test(await q.textContent('body')), 'user not refused: ' + q.url());
		await q.context().close();
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'ip_refused'") >= 1, 'no ip_refused log row');
		// exception for the user's level: own ranges with 127.0.0.0/8 → signs in
		await open(p, 'index.php?p=ipaccess');
		const sel = p.locator(`tr[data-ipa-level="${lvl}"] select.ipaMode`);
		await sel.selectOption('ranges');
		await p.locator(`tr[data-ipa-level="${lvl}"] textarea`).fill('127.0.0.0/8');
		expect(await p.textContent('#ipaExcCount') === '1', 'exception count');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]); await p.waitForLoadState('networkidle');
		expect(JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'ip_access'")).levels[lvl].ranges[0] === '127.0.0.0/8', 'level exception not stored');
		sql("DELETE FROM zzzzsys_nunext_log WHERE event IN ('login_failed')");
		q = await tryLogin();
		expect(!/p=login/.test(q.url()), 'user with exception refused: ' + q.url());
		// the running session ends when its address is no longer allowed
		set({ on: true, default: ['10.99.0.0/16'], levels: {}, ga: { mode: 'any', ranges: [] } });
		await open(q, 'index.php?p=home');
		expect(/p=login/.test(q.url()) && /not allowed from this network/.test(await q.textContent('body')), 'session not ended: ' + q.url());
		await q.context().close();
		// test an address (saved rules)
		await open(p, 'index.php?p=ipaccess&test=10.99.3.4');
		expect(await p.getAttribute('#ipaTestGa', 'data-ok') === '1' && (await p.textContent('#ipaTestAllowed')).length > 0, 'test 10.99.3.4');
		await open(p, 'index.php?p=ipaccess&test=8.8.8.8');
		expect(await p.textContent('#ipaTestAllowed') === '' && /sample/.test(await p.textContent('#ipaTestRefused')), 'test 8.8.8.8');
		// security check line and the shell switch
		await open(p, 'index.php?p=securitycheck');
		expect(/IP access/.test(await p.textContent('#secChecks')), 'security check line');
		if (SITE) {
			execSync('php nucron.php iprules off', { cwd: SITE });
			expect(JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'ip_access'")).on === false, 'cron off');
		}
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'ip_access'");
		sql("DELETE FROM zzzzsys_nunext_log WHERE event IN ('login_failed', 'ip_refused', 'ip_access_off')");
	}
};

T.users_filter_width = async (s) => {                   // 0.8.729: users list filters on desktop – 250 px each, the same gap between them
	const p = s.ga;
	await p.setViewportSize({ width: 1400, height: 900 });
	await open(p, 'index.php?p=users');
	await p.waitForTimeout(400);
	expect(!(await p.isVisible('#nuLvFilters')), 'users list: filters shown by default on desktop (0.8.751)');
	await open(p, 'index.php?p=users&access=' + encodeURIComponent(sql("SELECT zzzzsys_access_id FROM zzzzsys_access LIMIT 1").trim()));
	await p.waitForTimeout(400);
	expect(await p.isVisible('#nuLvFilters'), 'users list: filters folded while one is set');
	await open(p, 'index.php?p=users');
	if (!(await p.isVisible('#nuLvFilters'))) { await p.click('[aria-controls="nuLvFilters"]'); await p.waitForTimeout(500); }
	const box = await p.$$eval('#nuLvFilters .row > div', (d) => d.map((x) => { const r = x.getBoundingClientRect(); return [r.left, r.top, r.width]; }));
	const row = box.filter((b) => Math.abs(b[1] - box[0][1]) < 2), gaps = row.slice(1).map((b, i) => Math.round(b[0] - row[i][0] - row[i][2]));
	expect(box.length >= 4 && box.every((b) => Math.round(b[2]) === 250) && new Set(gaps).size === 1, 'widths / gaps: ' + JSON.stringify(box) + ' ' + gaps.join(','));
	// 0.8.732: a chosen value keeps the searchable select one line high (the search input wrapped below it)
	const lvl = sql("SELECT zzzzsys_access_id FROM zzzzsys_access ORDER BY sal_code LIMIT 1");
	await open(p, 'index.php?p=users&access=' + encodeURIComponent(lvl));
	const hs = await p.evaluate(() => [document.querySelector('#lvf_access + .ts-wrapper').getBoundingClientRect().height, document.getElementById('lvf_pos').getBoundingClientRect().height]);
	expect(Math.abs(hs[0] - hs[1]) < 2, 'access level filter height with a value: ' + hs.join(' / '));
	await p.click('#lvf_access + .ts-wrapper .ts-control'); await p.keyboard.type(lvl.slice(0, 4)); await p.waitForTimeout(300);
	expect(await p.locator('#lvf_access + .ts-wrapper .ts-dropdown .option').count() > 0, 'typing in the searchable select');
	await p.keyboard.press('Escape');
	await p.setViewportSize({ width: 1300, height: 900 });
};

T.access_list_no_clone = async (s) => {                // 0.8.834: no clone button per row in the access levels list; Clone stays on the form
	await open(s.ga, 'index.php?p=access');
	expect(await s.ga.locator('#nuLvData, table.nu-table').count() > 0 && await s.ga.locator('table.nu-table a[href*="clone="]').count() === 0, 'access levels list still has clone buttons');
	const id = sql("SELECT zzzzsys_access_id FROM zzzzsys_access LIMIT 1").trim();
	await open(s.ga, 'index.php?p=accesslevel&id=' + encodeURIComponent(id));
	expect(await s.ga.locator('a[href*="clone="], button[name="action"][value="clone"]').count() > 0, 'access level form lost its Clone button');
};

// users list (0.8.555): with all columns a long e-mail keeps a readable width – at most two lines, then …, the address as tooltip
T.users_email_col = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!id) { s.skip = 'no sample user'; return; }
	const was = sql("SELECT COALESCE(sus_email, '') FROM zzzzsys_user WHERE zzzzsys_user_id = '" + id + "'");
	const long = 'sample_ben.a.very.long.address.for.the.list@subdomain.example-company-domain.example';
	sql("UPDATE zzzzsys_user SET sus_email = '" + long + "' WHERE zzzzsys_user_id = '" + id + "'");
	try {
		await open(p, 'index.php?p=users&view=all&q=sample_ben');
		const r = await p.evaluate(() => { const c = document.querySelector('table.nu-table tbody .nuClamp2'); if (!c) return null;
			const td = c.closest('td'), lh = parseFloat(getComputedStyle(c).lineHeight) || 24;
			return { w: td.getBoundingClientRect().width, lines: Math.round(c.getBoundingClientRect().height / lh), title: c.title }; });
		expect(r && r.w >= 180 && r.lines <= 2 && r.title === long, 'e-mail cell: ' + JSON.stringify(r));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("UPDATE zzzzsys_user SET sus_email = " + (was === '' ? 'NULL' : "'" + was + "'") + " WHERE zzzzsys_user_id = '" + id + "'");
	}
};

// user form (0.8.552): Code right after E-mail; "All" asks first above 5 shown items (Cancel changes nothing, a filter to ≤ 5 does
// not ask); phones: switch "Descriptions" hides them and is remembered
T.user_perm_all = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!id) { s.skip = 'no sample user'; return; }
	sql("INSERT INTO zzzzsys_permission_item (zzzzsys_permission_item_id, pme_ident, pme_description, pme_group) VALUES " +
		"('e2e_pa1', 'e2e_pa1', 'E2E item one', 'e2e'), ('e2e_pa2', 'e2e_pa2', 'E2E item two', 'e2e'), ('e2e_pa3', 'e2e_pa3', '" + 'E2E long description '.repeat(12).trim() + "', 'e2e')");
	try {
		await open(p, 'index.php?p=user&id=' + id);
		await p.evaluate(() => { const s = document.getElementById('userPermState'); if (s) { s.value = 'all'; s.dispatchEvent(new Event('change')); } });   // 0.8.642: the list opens on "Assigned"
		const order = await p.$$eval('#userTabGeneral input[id^="sus_"]', a => a.map(e => e.id));
		expect(order.indexOf('sus_code') === order.indexOf('sus_email') + 1, 'Code after E-mail: ' + order.join(','));
		await p.click('#userTabPermBtn'); await p.waitForTimeout(300);
		const checked = () => p.locator('#userPerms input[name="perm[]"]:checked').count();
		const n0 = await checked(), all = await p.locator('#userPerms input[name="perm[]"]').count();
		// 0.8.560: columns Ident · Group · Description; 0.8.561: the description on one line, as much as fits, at most 750 px, full text as tooltip
		const heads = await p.$$eval('#userPerms thead th', a => a.map(e => e.textContent.trim().toLowerCase()).slice(1));
		expect(heads.length === 3 && /ident/.test(heads[0]) && /group/.test(heads[1]) && /desc/.test(heads[2]), 'column order: ' + heads.join(','));
		const long = await p.$eval('#userPerms tr.userPermItem:has(#perm_e2e_pa3) td.userPermDesc .userPermDescLine', e => ({ t: e.textContent, title: e.title,
			w: e.clientWidth, cut: e.scrollWidth > e.clientWidth, h: e.getBoundingClientRect().height }));
		expect(long.t === 'E2E long description '.repeat(12).trim() && long.title === long.t && long.cut && long.w <= 750 && long.w > 300 && long.h < 30, 'long description: ' + JSON.stringify(long));
		expect(await p.$eval('#userPerms tr.userPermItem:has(#perm_e2e_pa1) td.userPermDesc .userPermDescLine', e => e.textContent === 'E2E item one' && e.scrollWidth <= e.clientWidth), 'short description cut');
		expect(await p.evaluate(() => { const w = document.querySelector('#userPerms .table-responsive'); return w.scrollWidth <= w.clientWidth + 1; }), 'the table scrolls sideways');
		expect(all > 5, 'more than 5 items: ' + all);
		await p.click('[data-perm-all="1"]'); await p.waitForTimeout(400);
		expect(await p.isVisible('#nuConfirm') && (await p.textContent('#nuConfirm .js-confirm-text')).includes(String(all)), 'All asks with the count');
		await p.click('#nuConfirm [data-bs-dismiss="modal"]'); await p.waitForTimeout(400);
		expect(await checked() === n0, 'Cancel changes nothing');
		await p.click('[data-perm-all="1"]'); await p.waitForTimeout(400);
		await p.click('#nuConfirm .js-confirm-ok'); await p.waitForTimeout(400);
		expect(await checked() === all && +await p.textContent('#userPermTabCount') === all, 'OK ticks all');
		await p.click('[data-perm-all="0"]'); await p.waitForTimeout(300);
		expect(!await p.isVisible('#nuConfirm') && await checked() === 0, 'None does not ask');
		await p.selectOption('#userPermGroup', 'e2e');
		await p.click('[data-perm-all="1"]'); await p.waitForTimeout(300);
		expect(!await p.isVisible('#nuConfirm') && await checked() === 3, '3 shown: no question');
		expect(!await p.isVisible('#userPermDescToggle'), 'description switch only on phones');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
		await open(p, 'index.php?p=home');   // nothing saved
		const m = await login(GA[0], GA[1], { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
		await open(m, 'index.php?p=user&id=' + id + '&tab=perm');
		await m.evaluate(() => { const s = document.getElementById('userPermState'); if (s) { s.value = 'all'; s.dispatchEvent(new Event('change')); } });
		const desc = m.locator('#userPerms .userPermDesc').first();
		expect(await m.isVisible('#userPermDescToggle') && await desc.isVisible(), 'phone: switch and descriptions shown');
		await m.click('label[for="userPermDescToggle"]'); await m.waitForTimeout(200);
		expect(!await desc.isVisible() && !await m.locator('[data-nu-save].nuSaveEdited').count(), 'switch off: hidden, Save not red');
		await open(m, 'index.php?p=user&id=' + id + '&tab=perm');
		await m.evaluate(() => { const s = document.getElementById('userPermState'); if (s) { s.value = 'all'; s.dispatchEvent(new Event('change')); } });
		expect(!await m.isChecked('#userPermDescToggle') && !await desc.isVisible(), 'remembered after reload');
		await m.click('label[for="userPermDescToggle"]');
		expect(m.errors.length === 0, m.errors.slice(0, 2).join(' | '));
		await m.context().close();
	} finally {
		sql("DELETE FROM zzzzsys_user_permission WHERE upe_ident LIKE 'e2e_pa%'");
		sql("DELETE FROM zzzzsys_permission_item WHERE zzzzsys_permission_item_id LIKE 'e2e_pa%'");
	}
};

T.rights_editor = async (s) => {
	const p = await login('sample_ben', UPW);
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + s.cust);
	expect(await p.locator('#nuSaveButton').count() === 1, 'editor has no Save');
	await p.context().close();
};

// nuBuilder Forte's User Home (0.8.602): the New form wizard adds a run button to nuuserhome (as Forte's Fast Form) and gives access
// levels the rights (+ start page tile); tile User Home on the admin start page; a level with home form nuuserhome may open it
// User Home is created when missing (0.8.618): as nuBuilder Forte's installation – on installation and with every update (NuMigrate::run)
T.user_home_ensure = async (s) => {
	if (!SITE) { s.skip = 'SITE not set'; return; }
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-uh.sql');
	execSync('mysqldump ' + DB + " --no-create-info --replace zzzzsys_form --where=\"zzzzsys_form_id='nuuserhome'\" > " + keep);
	execSync('mysqldump ' + DB + " --no-create-info --replace zzzzsys_tab --where=\"syt_zzzzsys_form_id='nuuserhome'\" >> " + keep);
	const run = () => execSync(`php -r 'chdir(${JSON.stringify(SITE)}); require "core/nuapp.php"; NuMigrate::run(); echo "ok";'`).toString().trim();
	try {
		const js0 = sql("SELECT MD5(CONCAT_WS('|', sfo_javascript, sfo_description)) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'");
		run();
		expect(sql("SELECT MD5(CONCAT_WS('|', sfo_javascript, sfo_description)) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'") === js0, 'an existing User Home is not changed');
		sql("DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'nuuserhome'");
		sql("DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'");
		expect(run() === 'ok', 'migration run');
		expect(sql("SELECT CONCAT(sfo_type, '/', sfo_description) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'") === 'launch/User Home', 'User Home created');
		expect(sql("SELECT GROUP_CONCAT(zzzzsys_tab_id ORDER BY syt_order) FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'nuuserhome'") === 'nufastforms,nu5bad6cb374207e3', 'its tabs Fast Forms and Main');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'userhome_created'") >= 1, 'logged');
		const p = s.ga;
		await open(p, 'index.php?p=home');
		expect(await p.locator('#nuHomeUserHome').count() === 0, 'User Home button in the search row (removed in 0.8.1281)');
		expect(await p.locator('#nuMenuUserHome').count() === 0 && await p.locator('#nuHfToggle').count() === 1, 'User Home in the globeadmin\'s user menu (in the Start pages panel since 0.8.1283)');
		await open(p, 'index.php?p=form&f=nuuserhome');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'nuuserhome'");
		execSync('mysql ' + DB + ' < ' + keep);
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'userhome_created'");
		fs.rmSync(keep, { force: true });
	}
};

T.user_home = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'") !== '1') { s.skip = 'no nuuserhome'; return; }
	const code = 'e2euh' + Date.now().toString(36);
	const home0 = sql("SELECT IFNULL(sal_zzzzsys_form_id, '') FROM zzzzsys_access WHERE zzzzsys_access_id = 'sample_viewer'");
	let id = '';
	const anna = await login('sample_anna', UPW);
	try {
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		expect(await p.isChecked('#nb_userhome'), 'User Home switch not on by default');
		await p.fill('#nb_desc', 'E2E user home'); await p.fill('#nb_code', code); await p.selectOption('#nb_type', 'subform');
		expect(!(await p.locator('.nbAfter').evaluate((e) => !e.classList.contains('d-none'))), 'section shown for a sub-form');
		await p.selectOption('#nb_type', 'launch');
		expect(await p.locator('.nbAfter').evaluate((e) => !e.classList.contains('d-none')), 'after-creating section hidden');
		await p.click('#nbStepNext'); await p.click('#nbStepNext');        // three steps (0.8.663)
		await p.check('.nbLevel[value="sample_viewer"]');
		await Promise.all([p.waitForURL(/design=1/), p.click('#nbCreate')]);     // the new form opens in design mode (0.8.670)
		id = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}'`);
		expect(id !== '', 'form not created');
		expect((await p.locator('.nuFlashToast').allTextContents()).join(' ').includes('User Home'), 'no message about the User Home button (0.8.603)');
		const btn = sql(`SELECT CONCAT(sob_all_zzzzsys_tab_id, '|', sob_all_type, '|', sob_all_label, '|', IFNULL(sob_run_id, '')) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'nuuserhome' AND sob_run_zzzzsys_form_id = '${id}'`);
		expect(btn === 'nufastforms|run|E2E user home|-1', 'run button: ' + btn);
		expect(sql("SELECT syt_title FROM zzzzsys_tab WHERE zzzzsys_tab_id = 'nufastforms'") === 'Fast Forms', 'tab Fast Forms');
		expect(sql(`SELECT CONCAT(slf_add_button, slf_save_button, slf_delete_button, slf_print_button) FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'sample_viewer' AND slf_zzzzsys_form_id = '${id}'`) === '1111', 'rights for sample_viewer');
		// the user sees the form on the start page
		await open(anna, 'index.php?p=home');
		expect(await anna.locator(`a[href*="f=${id}"], a[href*="f=${code}"]`).count() >= 1, 'no tile for the new form on the user start page');
		// admin start page: tile User Home
		await open(p, 'index.php?p=home&tiles=1');
		expect(await p.locator('[data-nu-tile="userhome"]').count() === 1, 'no User Home tile');
		expect(await p.locator('#nuMenuUserHome').count() === 0, 'user menu entry for the globeadmin (Start pages panel since 0.8.1283)');
		await open(anna, 'index.php?p=home');
		expect(await anna.locator('#nuHomeUserHome, #nuMenuUserHome').count() === 0, 'house icon for a user without User Home');
		// home form nuuserhome: the user may open it and finds it on the automatic start page
		expect((await anna.goto(BASE + 'index.php?p=form&f=nuuserhome')).status() === 403 || /forbidden|not allowed|keine/i.test(await anna.textContent('body')), 'nuuserhome open without being the home form');
		sql("UPDATE zzzzsys_access SET sal_zzzzsys_form_id = 'nuuserhome' WHERE zzzzsys_access_id = 'sample_viewer'");
		const r = await anna.goto(BASE + 'index.php?p=form&f=nuuserhome'); await anna.waitForLoadState('networkidle');
		expect(r.status() === 200 && (await anna.textContent('body')).includes('E2E user home'), 'nuuserhome as home form not shown: ' + r.status());
		await open(anna, 'index.php?p=home');
		expect(await anna.locator('a[href*="f=nuuserhome"]').count() >= 1, 'no User Home tile for the home form');
		expect(await anna.locator('#nuMenuUserHome').count() === 1, 'no User Home menu entry for the user with User Home as home form');
	} finally {
		sql(`UPDATE zzzzsys_access SET sal_zzzzsys_form_id = ${home0 === '' ? 'NULL' : "'" + home0 + "'"} WHERE zzzzsys_access_id = 'sample_viewer'`);
		if (id) sql(`DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'nuuserhome' AND sob_run_zzzzsys_form_id = '${id}'; DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = '${id}';
			DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${id}'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`);
		await anna.context().close();
	}
};

// 0.8.512: users list – with first and last name as columns of their own (view "All columns") the name column shows only the full name
T.users_fullname = async (s) => {
	const p = s.ga;
	const old = sql("SELECT sus_name FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	if (!old) { s.skip = 'no sample user'; return; }
	sql("UPDATE zzzzsys_user SET sus_name = 'E2E Full Name' WHERE sus_login_name = 'sample_anna'");
	try {
		const cell = async (v) => { await open(p, 'index.php?p=users&view=' + v);
			return p.locator('table tbody tr', { hasText: 'sample_anna' }).first().locator('td').first().innerText(); };
		const d = await cell('default');
		expect(/E2E Full Name/.test(d) && d.split('\n').length === 2, 'default view: first + last name with the full name below: ' + JSON.stringify(d));
		const a = await cell('all');
		expect(a.trim() === 'E2E Full Name', 'all columns: name column not only the full name: ' + JSON.stringify(a));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { sql("UPDATE zzzzsys_user SET sus_name = '" + old.replace(/'/g, "''") + "' WHERE sus_login_name = 'sample_anna'"); }
};

// 0.8.540: Logged-in users – search by user (login, display or full name) in both lists; End all still counts every session
T.sessions_search = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=sessions&sort=user&dir=asc');
	expect(await p.locator('#sessSearch #q').count() === 1, 'search field');
	await p.fill('#sessSearch #q', 'globeadmin');
	await Promise.all([p.waitForNavigation(), p.press('#sessSearch #q', 'Enter')]);
	const u = new URL(p.url());
	expect(u.searchParams.get('q') === 'globeadmin' && u.searchParams.get('sort') === 'user', 'search keeps the sort: ' + p.url());
	expect(await p.locator('#sessTable tbody tr').count() >= 1 && /globeadmin/i.test(await p.textContent('#sessTable tbody')), 'own session found');
	await p.fill('#sessSearch #q', 'zzz-nobody-zzz');
	await Promise.all([p.waitForNavigation(), p.press('#sessSearch #q', 'Enter')]);
	expect(await p.locator('#sessTable tbody tr').count() === 1 && await p.locator('#sessSearch .nuListResetBtn').count() === 1, 'no hit, reset button shown');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.738: user form – the admin role has an "open in a new tab" button like the access level, following the chosen role
T.user_role_open = async (s) => {
	const p = s.ga;
	const role = sql("SELECT zzzzsys_nunext_admin_role_id FROM zzzzsys_nunext_admin_role ORDER BY sort_order, name LIMIT 1");
	if (!role) { s.skip = 'no admin role'; return; }
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	await open(p, 'index.php?p=user&id=' + encodeURIComponent(uid));
	expect(await p.locator('#admRoleOpen').count() === 1, 'no open button for the admin role');
	const cur = await p.inputValue('#admin_role');
	if (cur === '') expect(await p.locator('#admRoleOpen.disabled').count() === 1, 'button enabled without a role');
	await p.selectOption('#admin_role', role);
	const href = await p.getAttribute('#admRoleOpen', 'href');
	expect(href.includes('p=adminroles') && href.includes('id=' + encodeURIComponent(role)) && await p.getAttribute('#admRoleOpen', 'target') === '_blank'
		&& await p.locator('#admRoleOpen.disabled').count() === 0, 'link: ' + href);
	await p.selectOption('#admin_role', '');
	expect(await p.locator('#admRoleOpen.disabled').count() === 1, 'not disabled again');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.739: admin role form – rights link to their page (new tab), the table sorts by name or level (flat), third click = groups again
// 0.8.796: admin role form – the table titles stay visible below the sticky button row while scrolling
T.admin_role_sticky = async (s) => {
	const p = s.ga;
	const role = sql("SELECT zzzzsys_nunext_admin_role_id FROM zzzzsys_nunext_admin_role ORDER BY sort_order, name LIMIT 1");
	if (!role) { s.skip = 'no admin role'; return; }
	await p.setViewportSize({ width: 1300, height: 800 });
	await open(p, 'index.php?p=adminroles&id=' + encodeURIComponent(role));
	await p.mouse.wheel(0, 900); await p.waitForTimeout(700);
	const r = await p.evaluate(() => {
		const th = document.querySelector('#admRights thead th').getBoundingClientRect(), bar = document.querySelector('.nuStickyBar');
		return { th: th.top, bar: bar ? bar.getBoundingClientRect().bottom : 0, y: window.scrollY, wrapped: !!document.querySelector('#admRights').closest('.table-responsive') };
	});
	expect(r.y > 300, 'the page did not scroll: ' + r.y);
	expect(!r.wrapped, 'the rights table is inside an overflow box (sticky cannot work)');
	expect(r.th >= r.bar && r.th < r.bar + 20, 'the titles are not right below the button row: ' + JSON.stringify(r));
};

T.admin_role_table = async (s) => {
	const p = s.ga;
	const role = sql("SELECT zzzzsys_nunext_admin_role_id FROM zzzzsys_nunext_admin_role WHERE code = 'developer' LIMIT 1") || sql("SELECT zzzzsys_nunext_admin_role_id FROM zzzzsys_nunext_admin_role LIMIT 1");
	if (!role) { s.skip = 'no admin role'; return; }
	await open(p, 'index.php?p=adminroles&id=' + encodeURIComponent(role));
	await p.evaluate(() => { try { localStorage.removeItem('nunext-adm-rights-sort'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=adminroles&id=' + encodeURIComponent(role));
	const links = await p.$$eval('#admRights a.admRightLink', (a) => a.map((x) => [x.getAttribute('href'), x.getAttribute('target')]));
	expect(links.length >= 20 && links.every((l) => /index\.php\?p=/.test(l[0]) && l[1] === '_blank') && links.some((l) => /p=procedures/.test(l[0])), 'links: ' + links.length);
	const names = () => p.$$eval('#admRights tr.nuAdmRight', (r) => r.map((x) => x.getAttribute('data-name')));
	const grouped = await names();
	await p.click('#admRights th[data-sort="name"] button');
	const asc = await names();
	expect(JSON.stringify(asc) === JSON.stringify([...asc].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true })))
		&& await p.getAttribute('#admRights th[data-sort="name"]', 'aria-sort') === 'ascending', 'sorted by name');
	await p.click('#admRights th[data-sort="name"] button');
	expect((await names())[0] === asc[asc.length - 1], 'descending');
	await p.click('#admRights th[data-sort="name"] button');
	expect(JSON.stringify(await names()) === JSON.stringify(grouped), 'back to the order of the groups');
	// own columns (0.8.1022): six titles, every row six cells, the shield only in the Full access column, a dash where View is missing
	const cols = await p.evaluate(() => {
		const t = document.getElementById('admRights'), rows = [...t.querySelectorAll('tbody tr.nuAdmRight')];
		return { th: t.querySelectorAll('thead th').length, cells: rows.every((r) => r.cells.length === 6), groupRows: t.querySelectorAll('tr.nuAdmGroup').length,
			badgeInName: !!t.querySelector('td.admName .nuAdmFull, td.admName .badge'), full: t.querySelectorAll('td.admFull .nuAdmFull').length,
			fullAttr: rows.filter((r) => r.dataset.full === '1').length, dash: t.querySelectorAll('td.admNoLvl').length,
			noView: rows.filter((r) => !r.querySelector('input[value=view]')).length, area: rows.every((r) => r.querySelector('td.admArea').textContent.trim() !== '') };
	});
	expect(cols.th === 6 && cols.cells && cols.groupRows === 0 && !cols.badgeInName && cols.full > 0 && cols.full === cols.fullAttr && cols.dash === cols.noView && cols.area, 'columns: ' + JSON.stringify(cols));
	await p.click('#admRights th[data-sort="full"] button');
	const fl = await p.$$eval('#admRights tr.nuAdmRight', (r) => r.map((x) => x.dataset.full));
	expect(fl.indexOf('0') === cols.full && fl.slice(cols.full).every((v) => v === '0'), 'full access first');
	await p.click('#admRights th[data-sort="area"] button');
	const ar = await p.$$eval('#admRights tr.nuAdmRight', (r) => r.map((x) => x.dataset.area));
	expect(JSON.stringify(ar) === JSON.stringify([...ar].sort()), 'sorted by area');
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await p.context().storageState() });
	const ph = await ctx.newPage();
	try {
		await ph.goto(BASE + 'index.php?p=adminroles&id=' + encodeURIComponent(role));
		const m = await ph.evaluate(() => {
			const r = [...document.querySelectorAll('#admRights tr.nuAdmRight')].find((x) => x.dataset.full === '1');
			// 0.8.1083 (mockup C): titles shown once, None / View / Change in fixed columns – a right without View keeps Change in place
			const n = r.querySelector('td.admName').getBoundingClientRect(), a = r.querySelector('td.admArea').getBoundingClientRect();
			const colX = (tr) => [...tr.querySelectorAll('td.admLvlCell')].map((c) => Math.round(c.getBoundingClientRect().left + c.getBoundingClientRect().width / 2));
			const rows = [...document.querySelectorAll('#admRights tr.nuAdmRight')];
			const noView = rows.find((x) => x.querySelector('td.admNoLvl')), withView = rows.find((x) => !x.querySelector('td.admNoLvl'));
			const th = [...document.querySelectorAll('#admRights thead th')].filter((x) => getComputedStyle(x).display !== 'none').slice(-3).map((x) => Math.round(x.getBoundingClientRect().left + x.getBoundingClientRect().width / 2));
			return { thead: getComputedStyle(document.querySelector('#admRights thead')).display, areaBelow: a.top >= n.bottom - 2, cols: colX(withView), colsNoView: noView ? colX(noView) : null, th,
				dash: noView ? getComputedStyle(noView.querySelector('td.admNoLvl')).display : 'x', wide: document.documentElement.scrollWidth <= window.innerWidth + 1 };
		});
		const same = (x, y) => x && y && x.length === 3 && y.length === 3 && x.every((v, i) => Math.abs(v - y[i]) <= 2);
		expect(m.thead !== 'none' && m.areaBelow && same(m.cols, m.colsNoView) && same(m.cols, m.th) && m.dash !== 'none' && m.wide, 'phone: ' + JSON.stringify(m));
	} finally { await ctx.close(); }
	await p.evaluate(() => { try { localStorage.removeItem('nunext-adm-rights-sort'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=adminroles&id=' + encodeURIComponent(role));
	await p.click('#admRights th[data-sort="lvl:change"] button');
	const lv = await p.$$eval('#admRights tr.nuAdmRight', (r) => r.map((x) => { const c = x.querySelector('input[type=radio]:checked'); return c ? c.value : ''; }));
	const firstOther = lv.findIndex((v) => v !== 'change');
	expect(firstOther > 0 && lv.slice(firstOther).every((v) => v !== 'change'), 'change first: ' + lv.join(','));
	await open(p, 'index.php?p=adminroles&id=' + encodeURIComponent(role));
	expect(await p.getAttribute('#admRights th[data-sort="lvl:change"]', 'aria-sort') === 'ascending', 'sort not kept');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-adm-rights-sort'); } catch (e) { /* */ } });
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.642: user form → Permission items opens on "Assigned" (0.8.644: always); "Not assigned"; a box cleared stays in the list until the filter changes
T.user_perm_state = async (s) => {
	const p = s.ga;
	const id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!id || sql('SELECT COUNT(*) FROM zzzzsys_permission_item') === '0') { s.skip = 'no items'; return; }
	await open(p, 'index.php?p=user&id=' + id + '&tab=perm');
	const vis = () => p.$$eval('#userPerms tr.userPermItem:not(.d-none) input[name="perm[]"]', a => a.map(c => c.checked));
	const total = await p.locator('#userPerms tr.userPermItem').count(), assigned = await p.locator('#userPerms input[name="perm[]"]:checked').count();
	expect(await p.inputValue('#userPermState') === 'assigned', 'default filter: ' + await p.inputValue('#userPermState'));   // 0.8.644: always, also without any
	if (!assigned) expect((await vis()).length === 0 && await p.isVisible('#userPermEmpty'), 'none assigned: the empty message');
	if (assigned) {
		let v = await vis();
		expect(v.length === assigned && v.every(Boolean), 'Assigned shows others: ' + JSON.stringify(v));
		await p.locator('#userPerms tr.userPermItem:not(.d-none) input[name="perm[]"]').first().click();
		expect((await vis()).length === assigned, 'a cleared box left the list at once');
		await p.locator('#userPerms tr.userPermItem:not(.d-none) input[name="perm[]"]').first().click();
	}
	await p.selectOption('#userPermState', 'unassigned');
	let v = await vis();
	expect(v.length === total - assigned && v.every(x => !x), 'Not assigned: ' + v.length + ' of ' + (total - assigned));
	await p.selectOption('#userPermState', 'all');
	expect((await vis()).length === total, 'All items');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.admin_role = async () => {                              // a role with "Security check: view" – nothing more
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	sql("INSERT INTO zzzzsys_nunext_admin_role (zzzzsys_nunext_admin_role_id, code, name, rights, sort_order) VALUES ('e2e_role', 'e2e', 'E2E role', '{\"securitycheck\":\"view\"}', 99)");
	sql("INSERT INTO zzzzsys_nunext_admin_user (zzzzsys_nunext_admin_user_id, role_id) VALUES ('" + uid + "', 'e2e_role')");
	try {
		const p = await login('sample_anna', UPW);
		expect((await open(p, 'index.php?p=securitycheck')).status() === 200, 'the role does not open the Security check');
		expect(await p.locator('form[action*="securitycheck"] [data-nu-save], #secSave').count() === 0 || /readonly|disabled/.test(await p.content()), 'view right shows a Save');
		expect(await csrfPost(p, 'index.php?p=securitycheck', { action: 'save' }) === 403, 'view right may change the Security check');
		// the settings centre (0.8.702) opens with the Security check in it – nuBuilder Forte's settings list and the other tools stay closed
		for (const r of ['settings&s=forte', 'settings&s=data', 'update', 'configfile']) expect((await open(p, 'index.php?p=' + r)).status() === 403, 'the role opens ' + r);
		await p.context().close();
	} finally { sql("DELETE FROM zzzzsys_nunext_admin_user WHERE role_id = 'e2e_role'; DELETE FROM zzzzsys_nunext_admin_role WHERE zzzzsys_nunext_admin_role_id = 'e2e_role'"); }
};

T.admin_role_test = async () => {                         // 0.8.1318: test an admin role as a chosen user – for the session only, nothing saved
	const ben = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	const anna = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	sql("INSERT INTO zzzzsys_nunext_admin_role (zzzzsys_nunext_admin_role_id, code, name, rights, sort_order) VALUES ('e2e_rtest', 'e2e_rtest', 'E2E test role', '{\"securitycheck\":\"view\"}', 97), "
		+ "('e2e_rmgr', 'e2e_rmgr', 'E2E manager', '{\"impersonate\":\"change\",\"adminroles\":\"change\",\"securitycheck\":\"view\"}', 98), ('e2e_rbig', 'e2e_rbig', 'E2E big', '{\"update\":\"change\"}', 99)");
	let ga = null, an = null;
	try {
		ga = await login('globeadmin', 'nu');
		await open(ga, 'index.php?p=adminroles');
		expect(await ga.locator('a.nuAdmTestRow[href*="id=e2e_rtest"]').count() === 1, 'no Test role button in the list');
		await open(ga, 'index.php?p=adminroles&id=e2e_rtest&test=1');
		await ga.waitForSelector('#admTestDlg.show', { timeout: 5000 });
		const opts = await ga.evaluate(() => Array.from(document.querySelectorAll('#admTestUser option')).map(o => o.value));
		expect(opts.includes(ben) && !opts.includes('globeadmin'), 'user list: ' + opts.slice(0, 5));
		// no user chosen: nothing starts
		await ga.click('#admTestView');
		await ga.waitForTimeout(300);
		expect(/p=adminroles/.test(ga.url()) && await ga.locator('.nuImpBar').count() === 0, 'started without a user');
		await ga.evaluate((u) => { const s = document.getElementById('admTestUser'); if (s.tomselect) s.tomselect.setValue(u); else s.value = u; }, ben);
		await Promise.all([ga.waitForNavigation(), ga.click('#admTestView')]);
		const bar = await ga.locator('#nuImpText').textContent().catch(() => '');
		expect(/E2E test role/.test(bar) && /sample_ben/.test(bar), 'banner: ' + bar);
		expect((await open(ga, 'index.php?p=securitycheck')).status() === 200, 'the tested role does not open the Security check');
		expect((await open(ga, 'index.php?p=update')).status() === 403, 'the tested role opens the update page');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_admin_user WHERE " + "zzzzsys_nunext_admin_user_id = '" + ben + "'") === '0', 'the role was saved to the user');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'impersonate_start' AND details LIKE '%e2e_rtest%'") === 1, 'start not logged with the role');
		await open(ga, 'index.php?p=home');
		await Promise.all([ga.waitForNavigation(), ga.click('.nuImpBar button[type=submit]')]);
		expect(/p=adminroles&id=e2e_rtest/.test(ga.url()) && await ga.locator('.nuImpBar').count() === 0, 'Back does not return to the role: ' + ga.url());
		expect((await open(ga, 'index.php?p=update')).status() === 200, 'globeadmin rights not back');
		// an admin-role user with Act as user + Admin roles: only roles whose rights he has himself
		sql("INSERT INTO zzzzsys_nunext_admin_user (zzzzsys_nunext_admin_user_id, role_id) VALUES ('" + anna + "', 'e2e_rmgr')");
		an = await login('sample_anna', UPW);
		await open(an, 'index.php?p=adminroles');
		expect(await an.locator('a.nuAdmTestRow[href*="id=e2e_rtest"]').count() === 1 && await an.locator('a.nuAdmTestRow[href*="id=e2e_rbig"]').count() === 0, 'role user may test a role beyond his rights');
		await csrfPost(an, 'index.php?p=impersonate', { action: 'start', id: ben, mode: 'view', role: 'e2e_rbig' });
		await open(an, 'index.php?p=home');
		expect(await an.locator('.nuImpBar').count() === 0, 'role user started a test of a bigger role');
		expect((await open(an, 'index.php?p=update')).status() === 403, 'role user reached the update page');
	} finally {
		if (ga) await ga.context().close();
		if (an) await an.context().close();
		sql("DELETE FROM zzzzsys_nunext_admin_user WHERE role_id LIKE 'e2e_r%'; DELETE FROM zzzzsys_nunext_admin_role WHERE zzzzsys_nunext_admin_role_id LIKE 'e2e_r%'");
		sql("DELETE FROM zzzzsys_nunext_log WHERE event IN ('impersonate_start', 'impersonate_end') AND details LIKE '%e2e_r%'");
	}
};

T.demo_mode = async (s) => {                              // $nuConfigDemoUser: "Try the demo", read-only, no save
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	fs.writeFileSync(cfg, orig.replace(/\?>\s*$/, '') + '\n$nuConfigDemoUser = "sample_ben";\n');
	try {
		await new Promise(r => setTimeout(r, 3500));                // OPcache checks the file every 2 s
		const ctx = await browser.newContext({ locale: 'en-GB' }); const p = await ctx.newPage();
		await p.goto(BASE + 'index.php?p=login');
		const demo = p.locator('button[name=demo_user], form:has(input[name=demo_user]) button[type=submit]').first();
		expect(await demo.count(), 'no "Try the demo" on the login page');
		await Promise.all([p.waitForLoadState('networkidle'), demo.click()]);
		const cust = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1'), name = sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + cust + "'");
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust);
		if (await p.locator('#cus_name').count()) { await p.fill('#cus_name', 'Demo change'); await p.locator('#cus_name').dispatchEvent('change'); }
		if (await p.locator('#nuSaveButton').count()) { await p.click('#nuSaveButton').catch(() => {}); await p.waitForTimeout(1200); }
		expect(sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + cust + "'") === name, 'a demo visitor saved a record');
		expect((await open(p, 'index.php?p=users')).status() >= 400 || /p=login|demo/i.test(p.url() + await p.textContent('body')), 'a demo visitor opens the users');
		await ctx.close();
	} finally { fs.writeFileSync(cfg, orig); await new Promise(r => setTimeout(r, 3500)); }
};

T.login_link = async (s) => {                             // one-time login link (nuBuilder's LOGIN_TOKEN): works once
	const p = s.ga, uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	await open(p, 'index.php?p=user&id=' + uid);
	const btn = p.locator('button[value=logintoken_create]');
	if (!await btn.count()) { s.skip = 'login tokens switched off'; return; }
	await p.evaluate(() => document.querySelector('button[value=logintoken_create]').click());   // the box sits on a tab
	await p.waitForSelector('#loginTokenUrl', { state: 'attached', timeout: 15000 }).catch(() => {});
	const m = String(await p.locator('#loginTokenUrl').inputValue().catch(() => '')).match(/index\.php\?u=[^"'<\s]+&p=[0-9A-Za-z_-]+/);
	expect(m, 'no link shown');
	const use = async () => { const c = await browser.newContext(); const q = await c.newPage(); await q.goto(BASE + m[0]); await q.waitForLoadState('networkidle');
		await q.goto(BASE + 'index.php?p=home'); const u = q.url(); await c.close(); return !/p=login/.test(u); };
	expect(await use(), 'the link does not log in');
	expect(!await use(), 'the link works a second time');
};

T.user_import_template = async (s) => {                  // 0.8.752: the template is a ZIP – the CSV header row + readme.txt explaining every column
	const p = s.ga;
	await open(p, 'index.php?p=import');                     // 0.8.870: the Templates menu also without target=users
	await p.click('#impTplBtn'); await p.waitForTimeout(300);
	const tpl = await p.evaluate(() => { const a = document.querySelector('#impTplMenu a[data-tpl="users"]'); return a ? [a.offsetParent !== null, a.getAttribute('href')] : [false, document.getElementById('impTplMenu') ? 'menu, no item' : location.href]; });
	expect(tpl[0] && /target=users/.test(tpl[1]), 'Templates menu without the users template: ' + tpl);
	const r = await p.request.get(BASE + 'index.php?p=import&target=users&action=template');
	expect(r.status() === 200 && /application\/zip/.test(r.headers()['content-type'] || '') && /users-import-template\.zip/.test(r.headers()['content-disposition'] || ''), 'template not a ZIP: ' + r.headers()['content-type']);
	const f = path.join(require('os').tmpdir(), 'e2e-tpl-' + Date.now() + '.zip');
	fs.writeFileSync(f, await r.body());
	try {
		const z = JSON.parse(execSync('python3 -I -', { input: "import zipfile, json\nz = zipfile.ZipFile(" + JSON.stringify(f) + ")\nprint(json.dumps({'names': sorted(z.namelist()), 'csv': z.read('users-import-template.csv').decode('utf-8-sig'), 'rm': z.read('readme.txt').decode('utf-8-sig')}))" }).toString());
		expect(JSON.stringify(z.names) === '["readme.txt","users-import-template.csv"]', 'ZIP files: ' + z.names);
		const heads = z.csv.trim().split(';');
		expect(heads.length >= 10 && heads.every((h) => z.rm.includes('* ' + h + ' (')), 'readme does not describe every column: ' + heads.filter((h) => !z.rm.includes('* ' + h + ' (')).join(', '));
		const lvl = sql("SELECT sal_code FROM zzzzsys_access WHERE sal_code <> '' ORDER BY sal_code LIMIT 1").trim();
		expect(z.rm.includes('users-import-template.csv') && (!lvl || z.rm.includes(lvl)) && /\r\n/.test(z.rm), 'readme without file name / level codes / CRLF');
	} finally { fs.rmSync(f, { force: true }); }
	await open(p, 'index.php?p=import&target=users');
	expect(/ZIP/.test(await p.textContent('#impTplMenu a[data-tpl="users"]') || ''), 'template entry does not mention the ZIP');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.user_import = async (s) => {                           // CSV → users with access level and password; the new user logs in
	const p = s.ga, level = sql("SELECT sal_code FROM zzzzsys_access WHERE sal_code = 'sample_viewer'");
	await open(p, 'index.php?p=import&target=users');
	const res = await p.evaluate(async (lv) => {
		const csrf = document.querySelector('input[name=_csrf]').value;
		const post = async (o, file) => { const fd = new FormData(); fd.append('_csrf', csrf); for (const k in o) fd.append(k, o[k]); if (file) fd.append('file', file); return (await fetch('index.php?p=import', { method: 'POST', body: fd })).json(); };
		const up = await post({ action: 'upload', header: '1' }, new File(['login;name;level;password\ne2e_user;E2E User;' + lv + ';Imp0rt-Pass!\n'], 'u.csv', { type: 'text/csv' }));
		if (!up.ok) return up;
		return post({ action: 'import', table: 'zzzzsys_user', mode: 'insert', delimiter: ';', header: '1',
			mapping: JSON.stringify({ sus_login_name: { src: 'csv', idx: '0' }, sus_name: { src: 'csv', idx: '1' }, access_level: { src: 'csv', idx: '2' }, password: { src: 'csv', idx: '3' } }) });
	}, level);
	expect(res.ok && res.inserted === 1, 'import: ' + JSON.stringify(res).slice(0, 200));
	const q = await login('e2e_user', 'Imp0rt-Pass!');
	expect(!/p=login/.test(q.url()), 'the imported user cannot log in');
	await q.context().close();
};

T.login_limit = async () => {                             // 5 wrong passwords for a name from one address: the right one is refused too
	try {
		const ctx = await browser.newContext(); const p = await ctx.newPage();
		for (let i = 0; i < 5; i++) { await p.goto(BASE + 'index.php?p=login'); await p.fill('#username', 'sample_eva'); await p.fill('#password', 'wrong' + i);
			await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('form button[type=submit]:not([name=demo_user])')]); }
		await p.goto(BASE + 'index.php?p=login'); await p.fill('#username', 'sample_eva'); await p.fill('#password', UPW);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
		expect(/p=login/.test(p.url()), 'logged in after 5 failed attempts');
		await ctx.close();
	} finally { sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'"); }
};

T.users_more_menu = async () => {                        // 0.8.371: users list on phones – Import and Print in a ⋮ menu right of Add
	const m = await login(GA[0], GA[1], { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
	await open(m, 'index.php?p=users');
	expect(await m.locator('#nuMoreBtn').isVisible(), 'no ⋮ button on a phone');
	expect(!await m.locator('#nuUsersImport').isVisible() && !await m.locator('#nuListPrint').isVisible(), 'Import / Print still shown as buttons on a phone');
	const order = await m.evaluate(() => { const a = document.querySelector('.nuAddBtn'), b = document.querySelector('#nuMoreBtn').closest('.dropdown'); return a.nextElementSibling === b; });
	expect(order, 'the ⋮ button is not right of Add');
	await m.click('#nuMoreBtn'); await m.waitForTimeout(300);
	const items = await m.locator('.nuMoreMenu .dropdown-item:visible').allInnerTexts();
	expect(items.length === 5 && /Card|table/i.test(items[0]) && /Import/.test(items[1]) && /HTML/.test(items[2]) && /CSV/.test(items[3]) && /Excel/.test(items[4]), 'menu items: ' + items.join(' | '));   // 0.8.398: Export entries; 0.8.1069: Card or table first
	await m.context().close();
	const d = await login(GA[0], GA[1]);
	await open(d, 'index.php?p=users');
	expect(await d.locator('#nuUsersImport').isVisible() && await d.locator('#nuListPrint').isVisible() && !await d.locator('#nuMoreBtn').isVisible(), 'desktop: buttons changed');
	await d.context().close();
};

// 0.8.503: Security check → Files on the web: readable only when the answer is the file itself – a host's own page with HTTP 200
// (bot protection, error page) counts as blocked; the PHP built-in server of the tests hands out README.md for real
T.sec_probe = async (s) => {
	const p = s.ga;
	await p.route(/\/install\/schema_mysql\.sql\?probe=/, r => r.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>This site requires Javascript to work</body></html>' }));
	await p.route(/\/docs\/toc\.json\?probe=/, r => r.fulfill({ status: 403, contentType: 'text/html', body: '403 Forbidden' }));
	try {
		await open(p, 'index.php?p=securitycheck');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=securitycheck'); }
		await p.waitForTimeout(2500);
		const state = async f => p.locator('#secProbe .js-probe-list li', { hasText: f }).first().evaluate(li => li.classList.contains('sec-open') ? 'open' : 'ok');
		expect(await state('README.md') === 'open', 'README.md served by the test server not found readable');
		expect(await state('install/schema_mysql.sql') === 'ok', 'host page with HTTP 200 counted as readable');
		expect(await state('docs/toc.json') === 'ok', '403 counted as readable');
		expect(await p.locator('#secProbe .js-probe-list a[href="README.md"]').count() === 1, 'files are no links');
	} finally {
		await p.unrouteAll();
	}
};

// logged-in users (0.8.595): "Log out idle users" with the hours from the list – older sessions end, newer ones and the own stay
T.sessions_idle = async (s) => {
	const p = s.ga;
	sql("DELETE FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id LIKE 'e2e_idle_%'");
	sql("INSERT INTO zzzzsys_nunext_session (zzzzsys_nunext_session_id, user_id, login, display, via, ip, user_agent, login_at, last_seen) VALUES "
		+ "('e2e_idle_old', 'globeadmin', 'old_e2e', '', 'login', '10.0.0.7', 'x', NOW() - INTERVAL 6 HOUR, NOW() - INTERVAL 5 HOUR), "
		+ "('e2e_idle_new', 'globeadmin', 'new_e2e', '', 'login', '10.0.0.8', 'x', NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 30 MINUTE)");
	try {
		await open(p, 'index.php?p=sessions');
		// 0.8.654: one "Log out" button – its menu has one entry per time (the confirmation names it), then all others / everyone
		const opts = await p.$$eval('#sessLogoutMenu [data-hours]', o => o.map(x => +x.getAttribute('data-hours')));
		expect(opts.includes(4) && !opts.includes(24), 'hours below the longest timeout: ' + opts.join(','));
		expect(await p.locator('#sessLogoutMenu button[type="submit"]').count() === opts.length + 2, 'menu: idle times + all others + everyone');
		const own = +sql('SELECT COUNT(*) FROM zzzzsys_nunext_session');
		const idle = +sql('SELECT COUNT(*) FROM zzzzsys_nunext_session WHERE last_seen < NOW() - INTERVAL 4 HOUR');   // the planted one + any left from earlier runs
		await p.click('#sessLogoutMenu > button'); await p.waitForTimeout(300);
		expect(/ 4 /.test(await p.locator('#sessLogoutMenu [data-hours="4"]').evaluate(b => b.form.getAttribute('data-confirm'))), 'confirmation names the hours');
		if (opts.includes(1)) {   // 0.8.655: one hour in the singular (own text)
			const one = await p.locator('#sessLogoutMenu [data-hours="1"]').evaluate(b => b.form.getAttribute('data-confirm'));
			expect(/1 hour ago/.test(one) && !/1 hours/.test(one), 'confirmation for 1 h: ' + one);
		}
		await p.click('#sessLogoutMenu [data-hours="4"]'); await p.waitForTimeout(400);
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm.show .js-confirm-ok')]);
		const left = sql("SELECT GROUP_CONCAT(zzzzsys_nunext_session_id ORDER BY 1) FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id LIKE 'e2e_idle_%'");
		expect(left === 'e2e_idle_new', 'old ended, new kept: ' + left);
		// the site counts in its own time zone (nu_db_timezone), MySQL's NOW() here may differ by hours: no exact count – fewer sessions, none
		// idle for much longer than 4 h left, and this browser still signed in
		const after = +sql('SELECT COUNT(*) FROM zzzzsys_nunext_session'), stale = +sql('SELECT COUNT(*) FROM zzzzsys_nunext_session WHERE last_seen < NOW() - INTERVAL 18 HOUR');
		expect(after < own && after >= own - idle - 30 && stale === 0 && /p=sessions/.test(p.url()), 'own session kept (only sessions idle > 4 h ended): ' + own + ' → ' + after + ', stale ' + stale);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'sessions_ended_idle'") !== '0', 'logged');
	} finally {
		sql("DELETE FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id LIKE 'e2e_idle_%'");
	}
};

// logged-in users (0.8.508): columns sortable (?sort=&dir=, aria-sort on the header)
T.sessions_sort = async (s) => {
	const p = s.ga;
	sql("INSERT INTO zzzzsys_nunext_session (zzzzsys_nunext_session_id, user_id, login, display, via, ip, user_agent, login_at, last_seen) VALUES "
		+ "('e2e_sort_a', '', 'aaa_e2e', '', 'login', '10.0.0.9', 'x', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 1 MINUTE), "
		+ "('e2e_sort_z', '', 'zzz_e2e', '', 'login', '10.0.0.1', 'x', NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 2 MINUTE)");
	try {
		const order = async (q) => { await open(p, 'index.php?p=sessions' + q);
			return p.$$eval('#sessTable tbody tr td:first-child .fw-semibold', a => a.map(x => x.textContent).filter(t => /_e2e$/.test(t)).join(',')); };
		expect(await order('&sort=user&dir=asc') === 'aaa_e2e,zzz_e2e', 'user asc');
		expect(await p.getAttribute('#sessTable thead th >> nth=0', 'aria-sort') === 'ascending', 'aria-sort missing');
		expect(await order('&sort=user&dir=desc') === 'zzz_e2e,aaa_e2e', 'user desc');
		expect(await order('&sort=ip&dir=asc') === 'zzz_e2e,aaa_e2e', 'ip asc');
		expect(await order('&sort=login&dir=asc') === 'aaa_e2e,zzz_e2e', 'login asc');
		expect(await order('') === 'aaa_e2e,zzz_e2e', 'default: last activity, newest first');
		await p.click('#sessTable thead th >> nth=1 >> a');
		expect(/sort=ip&dir=asc/.test(p.url()), 'header link: ' + p.url());
	} finally { sql("DELETE FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id LIKE 'e2e_sort_%'"); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// custom user fields (0.8.454): define fields (real columns in zzzzsys_nunext_user_ext), user form tab More with checks (choice, unique),
// log, users list column + search, My account for "edited by the user", hash / JS value on a form page, CSV import, delete field
// 0.8.798: nuBuilder Forte's own names for the user fields Code / Additional 1 / 2 ($nuConfigUserCodeLabel, $nuConfigUserAdditional1Label,
// $nuConfigUserAdditional2Label) – user form, users list, Data import; from nuconfig.php or, with $nuConfigSettingsFromDB, the settings table
T.user_field_labels = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const p = s.ga, cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	const keepCfg = sql("SELECT cfg_value FROM zzzzsys_config WHERE cfg_setting = '$nuConfigUserAdditional2Label'");
	const lbl = id => p.evaluate(i => { const l = document.querySelector('label[for="' + i + '"]'); return l ? l.textContent.trim() : ''; }, id);
	try {
		fs.writeFileSync(cfg, orig.replace(/\?>\s*$/, '') + "\n$nuConfigUserAdditional1Label = 'Cost centre';\n$nuConfigUserCodeLabel = 'Staff no.';\n");
		await new Promise(r => setTimeout(r, 3500));
		await open(p, 'index.php?p=user&id=' + encodeURIComponent(uid));
		expect(/^Cost centre/.test(await lbl('sus_additional1')), 'user form: Additional 1 not renamed: ' + await lbl('sus_additional1'));
		expect(/^Staff no\./.test(await lbl('sus_code')), 'user form: Code not renamed: ' + await lbl('sus_code'));
		expect(/^Additional 2/.test(await lbl('sus_additional2')), 'Additional 2 changed without a setting');
		await open(p, 'index.php?p=users');
		expect(/Staff no\./i.test(await p.locator('table.nu-table thead').textContent()), 'users list: Code column not renamed');
		const zr = await p.request.get(BASE + 'index.php?p=import&target=users&action=template');
		const zf = path.join(os.tmpdir(), 'nunext-e2e-ufl.zip'); fs.writeFileSync(zf, await zr.body());
		const readme = execSync('unzip -p ' + JSON.stringify(zf) + ' readme.txt').toString(); fs.unlinkSync(zf);
		expect(/Cost centre/.test(readme), 'Data import readme does not use the name');
		// the settings table wins with $nuConfigSettingsFromDB (as in nuBuilder Forte – an empty row there means the built-in text)
		fs.writeFileSync(cfg, orig.replace(/\?>\s*$/, '') + "\n$nuConfigUserAdditional1Label = 'Cost centre';\n$nuConfigSettingsFromDB = true;\n");
		sql("UPDATE zzzzsys_config SET cfg_value = 'Region' WHERE cfg_setting = '$nuConfigUserAdditional2Label'");
		await new Promise(r => setTimeout(r, 3500));
		await open(p, 'index.php?p=user&id=' + encodeURIComponent(uid));
		expect(/^Region/.test(await lbl('sus_additional2')), 'settings table: Additional 2 not renamed: ' + await lbl('sus_additional2'));
		expect(/^Additional 1/.test(await lbl('sus_additional1')), 'settings table row (empty) should win over the file: ' + await lbl('sus_additional1'));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		fs.writeFileSync(cfg, orig);
		sql("UPDATE zzzzsys_config SET cfg_value = '" + keepCfg.replace(/'/g, "''") + "' WHERE cfg_setting = '$nuConfigUserAdditional2Label'");
		await new Promise(r => setTimeout(r, 3500));
	}
};

// 0.8.799: the user form renames Code / Additional 1 / 2 in place – (i) for everyone, a pencil for the globeadmin; written to nuconfig.php
// (or nuBuilder Forte's settings table with $nuConfigSettingsFromDB); empty = built-in title
T.user_field_labels_rename = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const p = s.ga, cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	const lbl = id => p.evaluate(i => (document.querySelector('label[for="' + i + '"] .nuUserLblText') || {}).textContent || '', id);
	try {
		await open(p, 'index.php?p=user&id=' + encodeURIComponent(uid));
		expect(await p.locator('label[for="sus_additional2"] + .nuInfoTip').count() === 1, 'no (i) next to Additional 2');
		await p.click('.nuUserLblEdit[data-key="user_additional2"]'); await p.waitForSelector('#userLblDlg.show');
		await p.fill('#userLblVal', 'Region'); await p.click('#userLblSave'); await p.waitForTimeout(1500);
		expect(await lbl('sus_additional2') === 'Region', 'label not changed in place: ' + await lbl('sus_additional2'));
		expect(/\$nuConfigUserAdditional2Label\s*=\s*'Region';/.test(fs.readFileSync(cfg, 'utf8')), 'not written to nuconfig.php');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'user_label_changed'") !== '0', 'not logged');
		await new Promise(r => setTimeout(r, 3500));
		await open(p, 'index.php?p=user&id=' + encodeURIComponent(uid));
		expect(await lbl('sus_additional2') === 'Region', 'renamed title not kept: ' + await lbl('sus_additional2'));
		// empty = the built-in title again
		await p.click('.nuUserLblEdit[data-key="user_additional2"]'); await p.waitForSelector('#userLblDlg.show');
		expect(await p.inputValue('#userLblVal') === 'Region', 'dialog does not show the stored title');
		await p.fill('#userLblVal', ''); await p.click('#userLblSave'); await p.waitForTimeout(1500);
		expect(await lbl('sus_additional2') === 'Additional 2', 'empty title did not bring back the built-in one');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
		// someone else may open the user form, but has no pencil and the action is refused
		const u = await login('sample_carla', UPW);
		await open(u, 'index.php?p=user&id=' + encodeURIComponent(uid));
		if (await u.locator('#sus_additional1').count()) {
			expect(await u.locator('.nuUserLblEdit').count() === 0, 'pencil shown to a non-globeadmin');
			const st = await u.evaluate(async () => { const fd = new FormData(); fd.append('_csrf', (document.querySelector('input[name=_csrf]') || {}).value || ''); fd.append('action', 'user_label'); fd.append('key', 'user_additional1'); fd.append('value', 'X'); return (await fetch(location.href, { method: 'POST', body: fd })).status; });
			expect(st === 403, 'non-globeadmin may rename: ' + st);
		}
		await u.context().close();
	} finally {
		fs.writeFileSync(cfg, orig);
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'user_label_changed'");
		await new Promise(r => setTimeout(r, 3500));
	}
};

T.user_fields = async (s) => {
	const p = s.ga;
	const anna = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'"), bob = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name <> 'sample_anna' AND sus_login_name LIKE 'sample_%' ORDER BY 1 LIMIT 1");
	if (!anna || !bob) { s.skip = 'sample users missing'; return; }
	const go = async (u) => {
		await open(p, u);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, u); }
	};
	const add = async (f) => {
		await go('index.php?p=userfields&id=new');
		await p.fill('#ufLabel', f.label); await p.fill('#ufCode', f.code); await p.selectOption('#ufType', f.type);
		if (f.options) await p.fill('#ufOptions', f.options);
		if (f.edit) await p.selectOption('#ufEditBy', f.edit);
		if (f.unique) await p.check('#ufUnique');
		await Promise.all([p.waitForNavigation(), p.click('#ufSave')]);
	};
	try {
		// edit form wider on desktop (0.8.597): card about 39rem instead of 26rem
		await go('index.php?p=userfields&id=new');
		const cw = await p.evaluate(() => document.querySelector('.ufCard').getBoundingClientRect().width);
		expect(cw > 560 && cw <= 640, 'card width: ' + cw);
		const ew = await p.evaluate(() => document.getElementById('ufEditBy').getBoundingClientRect().width);   // 0.8.598
		expect(ew > 340 && ew <= 360, 'Edited by width: ' + ew);
		const fits = await p.evaluate(() => { const s2 = document.getElementById('ufEditBy'), cs = getComputedStyle(s2), c = document.createElement('canvas').getContext('2d'); c.font = cs.font;
			return [...s2.options].every(o => c.measureText(o.textContent).width + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight) <= s2.clientWidth); });
		expect(fits, 'an Edited by option is cut off');
		await add({ label: 'E2E location', code: 'e2e_loc', type: 'select', options: 'ZH|Zurich\nBE|Bern', edit: 'user' });
		await add({ label: 'E2E badge', code: 'e2e_badge', type: 'integer', unique: true });
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_user_field WHERE code IN ('e2e_loc', 'e2e_badge')") === '2', 'fields not stored');
		const cols = sql("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zzzzsys_nunext_user_ext'");
		expect(/e2e_loc/.test(cols) && /e2e_badge/.test(cols), 'columns: ' + cols);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_user u JOIN zzzzsys_nunext_user_ext x USING (zzzzsys_user_id)") === '0', 'join (collation) failed');
		// user form: card Additional fields on General (0.8.591, was a tab), a wrong choice is refused, then saved
		await open(p, 'index.php?p=user&id=' + anna);
		expect(await p.locator('#userTabMoreBtn').count() === 0 && await p.isVisible('#userTabGeneral #userTabMore'), 'card on General, no tab');
		expect(await p.locator('#userTabMore .nuUfType').count() >= 1, 'field labels without their type in brackets (0.8.508)');
		await p.evaluate(() => { const s2 = document.getElementById('uf_e2e_loc'); const o = document.createElement('option'); o.value = 'XX'; o.textContent = 'XX'; s2.appendChild(o); s2.value = 'XX'; });
		await p.fill('#uf_e2e_badge', '4711');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		expect(await p.locator('#userTabGeneral.active #userTabMore .invalid-feedback').count() >= 1, 'wrong choice not refused / General not shown');
		await p.selectOption('#uf_e2e_loc', 'ZH');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		expect(sql("SELECT CONCAT(e2e_loc, '/', e2e_badge) FROM zzzzsys_nunext_user_ext WHERE zzzzsys_user_id = '" + anna + "'") === 'ZH/4711', 'values not saved');
		expect(!/tab=/.test(p.url()) && await p.isVisible('#userTabMore'), 'back on General: ' + p.url());
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'user_updated' AND record_id = '" + anna + "' AND details LIKE '%field:e2e_badge%'") !== '0', 'change not logged');
		// unique
		await open(p, 'index.php?p=user&id=' + bob + '&tab=more');   // old address: opens General
		expect(await p.isVisible('#userTabMore'), 'tab=more opens General');
		await p.fill('#uf_e2e_badge', '4711');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		expect(/another user/i.test(await p.textContent('#userTabMore')), 'unique value not refused');
		// users list: column (view "All columns") and search by the value
		await open(p, 'index.php?p=users&view=all&q=ZH');
		const heads = await p.$$eval('table.nu-table thead th', t => t.map(x => x.textContent.trim().toLowerCase()));
		expect(heads.includes('e2e location'), 'no list column: ' + heads.join('|'));
		expect(/sample_anna/.test(await p.textContent('table.nu-table tbody')) && /Zurich/.test(await p.textContent('table.nu-table tbody')), 'search / value in the list');
		// the user himself: My account (only the field edited by the user), the value as hash / JS on a form page
		const u = await login('sample_anna', UPW);
		await open(u, 'index.php?p=home');
		expect(await u.locator('#nuMyAccount').count() === 1, 'no My account in the menu');
		await open(u, 'index.php?p=account');
		expect(await u.locator('#uf_e2e_loc').count() === 1 && await u.locator('#uf_e2e_badge').count() === 0, 'My account shows the wrong fields');
		await u.selectOption('#uf_e2e_loc', 'BE');
		await Promise.all([u.waitForNavigation(), u.click('#accSave')]);
		expect(sql("SELECT e2e_loc FROM zzzzsys_nunext_user_ext WHERE zzzzsys_user_id = '" + anna + "'") === 'BE', 'My account not saved');
		const fid = sql("SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form f JOIN zzzzsys_user us ON us.sus_zzzzsys_access_id = f.slf_zzzzsys_access_id JOIN zzzzsys_form fo ON fo.zzzzsys_form_id = f.slf_zzzzsys_form_id WHERE us.zzzzsys_user_id = '" + anna + "' AND slf_zzzzsys_form_id NOT LIKE 'nu%' AND COALESCE(fo.sfo_type, '') <> 'subform' ORDER BY slf_zzzzsys_form_id LIMIT 1");   // a sub-form has no page of its own
		if (fid) {
			await open(u, 'index.php?p=form&f=' + fid);
			expect(await u.evaluate(() => window.nuNextUserField('e2e_loc') + '|' + window.nuNextUserField('e2e_badge') + '|' + window.nuNextUserField('nope')) === 'BE|4711|null', 'nuNextUserField in JavaScript');
		}
		await u.context().close();
		// CSV import with the custom column; template header
		await open(p, 'index.php?p=import&target=users');
		const level = sql("SELECT sal_code FROM zzzzsys_access WHERE sal_code LIKE 'sample_%' ORDER BY 1 LIMIT 1");
		const res = await p.evaluate(async (lv) => {
			const csrf = document.querySelector('input[name=_csrf]').value;
			const post = async (o, file) => { const fd = new FormData(); fd.append('_csrf', csrf); for (const k in o) fd.append(k, o[k]); if (file) fd.append('file', file); return (await fetch('index.php?p=import', { method: 'POST', body: fd, headers: { Accept: 'application/json' } })).json(); };
			const up = await post({ action: 'upload', header: '1' }, new File(['login;name;level;loc;badge\ne2e_ufimp;E2E Uf;' + lv + ';ZH;5000\ne2e_ufimp2;E2E Uf2;' + lv + ';XX;5001\n'], 'u.csv', { type: 'text/csv' }));
			if (!up.ok) return up;
			return post({ action: 'import', table: 'zzzzsys_user', mode: 'insert', delimiter: ';', header: '1', on_error: 'skip',
				mapping: JSON.stringify({ sus_login_name: { src: 'csv', idx: '0' }, sus_name: { src: 'csv', idx: '1' }, access_level: { src: 'csv', idx: '2' }, uf_e2e_loc: { src: 'csv', idx: '3' }, uf_e2e_badge: { src: 'csv', idx: '4' } }) });
		}, level);
		expect(res.ok && res.inserted === 1 && res.failed === 1, 'import: ' + JSON.stringify(res).slice(0, 300));
		expect(sql("SELECT CONCAT(x.e2e_loc, '/', x.e2e_badge) FROM zzzzsys_user us JOIN zzzzsys_nunext_user_ext x USING (zzzzsys_user_id) WHERE us.sus_login_name = 'e2e_ufimp'") === 'ZH/5000', 'imported values');
		expect(sql("SELECT a.sal_code FROM zzzzsys_user us JOIN zzzzsys_access a ON a.zzzzsys_access_id = us.sus_zzzzsys_access_id WHERE us.sus_login_name = 'e2e_ufimp'") === level, 'imported user keeps his access level (0.8.553)');
		// delete a field: its column goes
		const bid = sql("SELECT zzzzsys_nunext_user_field_id FROM zzzzsys_nunext_user_field WHERE code = 'e2e_badge'");
		await go('index.php?p=userfields&id=' + bid);
		await p.click('#ufDeleteBtn');
		const ok = p.locator('#nuConfirm .js-confirm-ok'); await ok.waitFor({ state: 'visible' });
		await Promise.all([p.waitForNavigation(), ok.click()]);
		expect(!/e2e_badge/.test(sql("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zzzzsys_nunext_user_ext'")), 'column not dropped');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_user WHERE sus_login_name IN ('e2e_ufimp', 'e2e_ufimp2')");
		sql("DELETE FROM zzzzsys_nunext_user_field WHERE code LIKE 'e2e_%'");
		sql('DROP TABLE IF EXISTS zzzzsys_nunext_user_ext');
		if (sql("SELECT COUNT(*) FROM zzzzsys_nunext_user_field") !== '0') s.note = 'other custom fields exist – their values table was dropped';
	}
};

// password policy (0.8.745): settings page, rules on Change password (checklist + server message), Try a password, leak check online
// (fake HIBP: only 5 hex characters arrive) and offline, forced change for an expired / leaked password at sign-in, globeadmin reminder
// 0.8.768: user form – the password checklist appears only once the password field holds a character (own password page: always)
T.pw_rules_on_type = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=user&id=' + sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'"));
	const box = p.locator('.nuPwRules[data-for="password"]');
	expect(await box.count() === 1, 'checklist on the user form');
	expect(!(await box.isVisible()), 'hidden while the field is empty');
	await p.fill('#password', 'a');
	expect(await box.isVisible(), 'shown after one character');
	expect(await box.locator('li[data-rule="len"].nuPwBad').count() === 1, 'length not met');
	await p.fill('#password', '');
	expect(!(await box.isVisible()), 'hidden again when emptied');
};

T.pw_policy = async (s) => {
	const p = s.ga;
	const crypto = require('crypto');
	// saved as hex: mysql -N escapes tabs / backslashes of JSON
	const keep = sql("SELECT CONCAT(HEX(IFNULL(sus_login_password,'')), ',', IF(sus_json IS NULL, 'N', HEX(sus_json)), ',', HEX(IFNULL(sus_change_password,''))) FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!keep) { s.skip = 'no sample_ben'; return; }
	const [bHash, bJson, bChg] = keep.split(',');
	const before = sql("SELECT IFNULL(GROUP_CONCAT(CONCAT(HEX(zzzzsys_nunext_setting_id), ':', HEX(setting_value)) SEPARATOR ','), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('pw_policy', 'pw_policy_ga')");
	const port = 8790 + Math.floor(Math.random() * 100), log = path.join(os.tmpdir(), 'hibp-' + port + '.log');
	const LEAK = 'Tr0ub4dor&3-e2e';
	const srv = require('child_process').spawn('python3', ['-I', path.join(__dirname, 'fakehibp.py'), String(port), LEAK, '4242', log], { stdio: 'ignore' });
	const esc = (x) => x.replace(/\\/g, '\\\\').replace(/'/g, "''");
	let u = null, g = null;
	try {
		await new Promise(r => setTimeout(r, 600));
		await open(p, 'index.php?p=pwpolicy');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=pwpolicy'); }
		expect(await p.locator('#pwpForm').count() === 1, 'policy page');
		await p.fill('#pwp_min_len', '14');
		await p.setChecked('#pwp_digit', true);
		await p.setChecked('#pwp_not_name', true);
		await p.selectOption('#pwp_leak', 'both');
		await p.setChecked('#pwp_leak_login', true);
		await p.selectOption('#pwp_expire', '30');
		await Promise.all([p.waitForNavigation(), p.click('#pwpSave')]); await p.waitForLoadState('networkidle');
		const pol = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'pw_policy'"));
		expect(pol.min_len === 14 && pol.digit === true && pol.expire === 30 && pol.leak === 'both', 'saved: ' + JSON.stringify(pol));
		// online source = the fake (developer mode only)
		pol.leak_url = 'http://127.0.0.1:' + port + '/range/';
		sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + esc(JSON.stringify(pol)) + "' WHERE zzzzsys_nunext_setting_id = 'pw_policy'");
		const tryPw = async (pw, login) => {
			await open(p, 'index.php?p=pwpolicy');
			await p.fill('#pwpTestPw', pw); await p.fill('#pwpTestLogin', login || '');
			await p.click('#pwpTestBtn');
			await p.waitForFunction(() => document.getElementById('pwpTestOut').hasAttribute('data-ok'));
			return [await p.getAttribute('#pwpTestOut', 'data-ok'), await p.textContent('#pwpTestOut')];
		};
		let [ok, msg] = await tryPw('short1');
		expect(ok === '0' && /14/.test(msg), 'too short: ' + msg);
		[ok, msg] = await tryPw('longpasswordwithoutdigits');
		expect(ok === '0' && /digit/i.test(msg), 'digit rule: ' + msg);
		[ok, msg] = await tryPw('carla-sample_ben-2026', 'sample_ben');
		expect(ok === '0' && /login|name/i.test(msg), 'name rule: ' + msg);
		[ok, msg] = await tryPw(LEAK);
		expect(ok === '0' && /4.?242/.test(msg), 'online leak (fake HIBP): ' + msg);
		const sent = fs.existsSync(log) ? fs.readFileSync(log, 'utf8').trim().split('\n') : [];
		expect(sent.length > 0 && sent.every(l => /\/range\/[0-9A-F]{5}$/.test(l)), 'only 5 hex characters sent: ' + sent.join(' '));
		[ok, msg] = await tryPw('Correct-Horse-Battery-77');
		expect(ok === '1', 'good password: ' + msg);
		// offline list (the fake does not know it): password1234567 is not in the online answer – switch to offline only
		pol.leak = 'offline'; pol.min_len = 0;                    // the list holds short passwords: the minimum of nuconfig.php (8)
		sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + esc(JSON.stringify(pol)) + "' WHERE zzzzsys_nunext_setting_id = 'pw_policy'");
		[ok, msg] = await tryPw('1234567890');
		expect(ok === '0' && /common/i.test(msg), 'offline list: ' + msg);
		// expired password: last change 40 days ago → forced change with the reason, checklist on the page
		sql("UPDATE zzzzsys_user SET sus_json = JSON_SET(IF(JSON_VALID(sus_json), sus_json, '{}'), '$.PASSWORD_CHANGED_TIME', UNIX_TIMESTAMP() - 40 * 86400), sus_change_password = '0' WHERE sus_login_name = 'sample_ben'");
		// 0.8.827: the interval of Ben's access level alone (global 0 = never) must force the change too – NuPwPolicy::expireDays
		const benLevel = sql("SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
		pol.expire = 0; pol.levels = {}; pol.levels[benLevel] = 30;
		sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + esc(JSON.stringify(pol)) + "' WHERE zzzzsys_nunext_setting_id = 'pw_policy'");
		u = await login('sample_ben', UPW);
		await open(u, 'index.php?p=home');
		expect(/p=password/.test(await u.evaluate(() => location.href)), 'per-level expiry not applied: ' + u.url());
		await u.context().close(); u = null;
		sql("UPDATE zzzzsys_user SET sus_change_password = '0' WHERE sus_login_name = 'sample_ben'");
		pol.expire = 30; pol.levels = {};
		sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + esc(JSON.stringify(pol)) + "' WHERE zzzzsys_nunext_setting_id = 'pw_policy'");
		u = await login('sample_ben', UPW);
		await open(u, 'index.php?p=home');
		expect(/p=password/.test(u.url()) || /p=password/.test(await u.evaluate(() => location.href)), 'forced to Change password: ' + u.url());
		expect(await u.getAttribute('#pwForced', 'data-reason') === 'expired', 'reason expired');
		await u.fill('#pw_new', 'abc');
		expect(await u.locator('.nuPwRules li[data-rule="len"].nuPwBad').count() === 1, 'checklist: length not met');
		await u.fill('#pw_new', 'Abcdefghijklmn1');
		expect(await u.locator('.nuPwRules li[data-rule="len"].nuPwOk').count() === 1 && await u.locator('.nuPwRules li[data-rule="digit"].nuPwOk').count() === 1, 'checklist ticked');
		await u.fill('#pw_current', UPW); await u.fill('#pw_new', '1234567890'); await u.fill('#pw_confirm', '1234567890');
		await Promise.all([u.waitForNavigation(), u.click('button[data-nu-save]')]); await u.waitForLoadState('networkidle');
		const fb = (await u.locator('.invalid-feedback').allTextContents()).join(' / ');
		expect(/p=password/.test(u.url()) && /common|ufigsten/i.test(fb), 'server refuses the common password: ' + fb);
		await u.fill('#pw_current', UPW); await u.fill('#pw_new', 'Correct-Horse-Battery-77'); await u.fill('#pw_confirm', 'Correct-Horse-Battery-77');
		await Promise.all([u.waitForNavigation(), u.click('button[data-nu-save]')]); await u.waitForLoadState('networkidle');
		expect(!/p=password/.test(u.url()) && sql("SELECT sus_change_password FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'") === '0', 'changed: ' + u.url());
		await u.context().close(); u = null;
		// leaked at sign-in: a common password set by hand → forced change, reason leaked
		const common = 'qwertyuiop', hash = execSync('php -r ' + JSON.stringify('echo password_hash(' + JSON.stringify(common) + ', PASSWORD_BCRYPT);')).toString().trim();
		sql("UPDATE zzzzsys_user SET sus_login_password = '" + hash + "', sus_change_password = '0' WHERE sus_login_name = 'sample_ben'");
		u = await login('sample_ben', common);
		await open(u, 'index.php?p=home');
		expect(await u.getAttribute('#pwForced', 'data-reason') === 'leaked', 'reason leaked: ' + u.url());
		await u.context().close(); u = null;
		// globeadmin: reminder only (password age from pw_policy_ga.since)
		pol.ga_expire = 30;
		sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + esc(JSON.stringify(pol)) + "' WHERE zzzzsys_nunext_setting_id = 'pw_policy'");
		const fp = crypto.createHash('sha256').update('ga|' + GA[1]).digest('hex').slice(0, 16);
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'pw_policy_ga'");
		sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('pw_policy_ga', '" + JSON.stringify({ fp, since: 1, leak: { at: Math.floor(Date.now() / 1000), hit: false } }) + "')");
		g = await login(GA[0], GA[1]);
		expect(/nuconfig\.php/.test(await g.textContent('body')), 'globeadmin reminder after sign-in');
		expect(!/p=password/.test(g.url()), 'globeadmin not forced');
		expect(p.errors.length === 0, p.errors.join(' | '));
	} finally {
		try { srv.kill(); } catch (x) { /* gone */ }
		if (u) await u.context().close();
		if (g) await g.context().close();
		sql("UPDATE zzzzsys_user SET sus_login_password = UNHEX('" + bHash + "'), sus_json = " + (bJson === 'N' ? 'NULL' : "UNHEX('" + bJson + "')") + ", sus_change_password = UNHEX('" + bChg + "') WHERE sus_login_name = 'sample_ben'");
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('pw_policy', 'pw_policy_ga')");
		for (const pair of before.split(',').filter(Boolean)) {
			const [k, v] = pair.split(':');
			sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES (UNHEX('" + k + "'), UNHEX('" + v + "'))");
		}
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
	}
};

// setup wizard run again (0.8.472): the password fields say "(unchanged)" – left empty, the current globeadmin password stays
T.wizard_password = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=setupwizard&step=security');
	if (!(await p.locator('#pw1').count())) { s.skip = 'wizard has no password field'; return; }
	const ph = async () => [await p.getAttribute('#pw1', 'placeholder'), await p.getAttribute('#pw2', 'placeholder')];
	let a = await ph();
	expect(/unchanged/i.test(a[0] || '') && /unchanged/i.test(a[1] || '') && await p.inputValue('#pw1') === '', 'no "(unchanged)" placeholder: ' + JSON.stringify(a));
	await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#wizNext')]);   // empty → nothing to change
	await open(p, 'index.php?p=setupwizard&step=summary');
	expect(!/••••/.test(await p.textContent('body')), 'empty password fields count as a new password');
	await open(p, 'index.php?p=setupwizard&step=security');
	a = await ph();
	expect(/unchanged/i.test(a[0] || ''), 'placeholder gone after Next: ' + JSON.stringify(a));
	expect(await p.inputValue('#admin_email') !== null && !(await p.locator('#admin_email_clear').count()), 'e-mail field changed');
};

// 0.8.773: permission items with a type – "Data import" into chosen tables (add / update), without the admin right; list column + filter,
// user form summary, user menu, refusals (other table, forbidden mode), log names the item, the type row goes with the item
T.perm_type_import = async (s) => {
	const p = s.ga;
	const ben = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	let u, itemId = '';
	const clean = () => {
		const id = sql("SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item WHERE pme_ident = 'E2E_IMP'");
		if (id) sql(`DELETE FROM zzzzsys_user_permission WHERE upe_ident = '${id}'; DELETE FROM zzzzsys_permission_item WHERE zzzzsys_permission_item_id = '${id}'`);
		if (sql("SHOW TABLES LIKE 'zzzzsys_nunext_permission_type'")) sql(`DELETE FROM zzzzsys_nunext_permission_type WHERE zzzzsys_nunext_permission_type_id = '${id || 'none'}'`);
		sql("DELETE FROM sample_customer WHERE cus_code LIKE 'E2EIMP%'");
	};
	clean();
	try {
		// the item page: type Data import, one table, add only
		await open(p, 'index.php?p=permissions&id=new');
		await p.fill('#pme_ident', 'E2E_IMP');
		await p.click('label[for="ptype_import"]');
		expect(await p.locator('#permTypeCard [data-ptype="import"]').isVisible(), 'import settings shown');
		await p.evaluate(() => document.getElementById('ptypeTables').tomselect.addItem('sample_customer'));
		expect(await p.isChecked('#ptypeAdd') && !(await p.isChecked('#ptypeUpdate')), 'new item: add on, update off');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		itemId = sql("SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item WHERE pme_ident = 'E2E_IMP'");
		const row = JSON.parse(sql(`SELECT settings FROM zzzzsys_nunext_permission_type WHERE zzzzsys_nunext_permission_type_id = '${itemId}' AND type = 'import'`) || '{}');
		expect(itemId && JSON.stringify(row.tables) === '["sample_customer"]' && row.add === true && row.update === false, 'type row: ' + JSON.stringify(row));
		// a type without tables is refused
		await p.locator('#permTypeCard .ts-wrapper .remove').first().click();
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		expect(await p.locator('#ptypeTables.is-invalid, .invalid-feedback').count() >= 1, 'no tables refused');
		// list: type column + filter
		await open(p, 'index.php?p=permissions&ptype=import');
		expect(/E2E_IMP/.test(await p.textContent('.nuListCard')) && await p.locator('.nuListCard .badge.text-bg-primary').count() >= 1, 'list shows the type');
		// 0.8.775: the type filter is part of the default view (a select next to the group filter); plain hides the item
		await open(p, 'index.php?p=permissions');
		expect(await p.locator('#lvf_ptype').count() === 1, 'type filter in the default view');
		await open(p, 'index.php?p=permissions&ptype=plain');
		expect(!/E2E_IMP/.test(await p.textContent('.nuListCard')), 'plain filter hides the import item');
		// give it to ben
		sql(`INSERT INTO zzzzsys_user_permission (zzzzsys_user_permission_id, upe_zzzzsys_user_id, upe_ident, upe_active) VALUES ('e2e_imp_up', '${ben}', '${itemId}', '1')`);
		await open(p, 'index.php?p=user&id=' + ben);
		expect(/sample_customer/.test(await p.locator('.userPermType').allTextContents().then(a => a.join(' '))), 'user form shows the summary');
		u = await login('sample_ben', UPW);
		await open(u, 'index.php?p=home');
		expect(await u.locator('#nuMenuImport').count() === 1, 'user menu entry');
		await open(u, 'index.php?p=import');
		expect(await u.locator('#impItemsNote').count() === 1 && JSON.parse(await u.getAttribute('#impApp', 'data-tables')).join() === 'sample_customer', 'import page for ben: ' + await u.getAttribute('#impApp', 'data-tables'));
		expect(JSON.stringify(JSON.parse(await u.getAttribute('#impApp', 'data-modes'))) === '{"sample_customer":["insert"]}', 'modes: ' + await u.getAttribute('#impApp', 'data-modes'));
		const post = (body, file) => u.evaluate(async ([body, file]) => {
			const fd = new FormData(); Object.keys(body).forEach(k => fd.append(k, body[k])); fd.append('_csrf', document.querySelector('#impApp input[name="_csrf"]').value);
			if (file) fd.append('file', new Blob([file], { type: 'text/csv' }), 'x.csv');
			return (await fetch('index.php?p=import', { method: 'POST', body: fd })).json();
		}, [body, file]);
		let j = await post({ action: 'columns', table: 'sample_order' });
		expect(!j.ok, 'another table refused');
		j = await post({ action: 'upload', header: '1' }, 'code,name\nE2EIMP1,Import One\nE2EIMP2,Import Two\n');
		expect(j.ok, 'upload: ' + JSON.stringify(j).slice(0, 200));
		const map = JSON.stringify({ sample_customer_id: { src: 'nuid' }, cus_code: { src: 'csv', idx: '0' }, cus_name: { src: 'csv', idx: '1' } });
		j = await post({ action: 'check', table: 'sample_customer', mapping: map, mode: 'update', key: 'cus_code', delimiter: ',', header: '1' });
		expect(!j.ok && /not allowed|nicht erlaubt/i.test(j.error || ''), 'update refused: ' + JSON.stringify(j).slice(0, 200));
		j = await post({ action: 'import', table: 'sample_customer', mapping: map, mode: 'insert', delimiter: ',', header: '1' });
		expect(j.ok && j.inserted === 2 && +sql("SELECT COUNT(*) FROM sample_customer WHERE cus_code LIKE 'E2EIMP%'") === 2, 'import as ben: ' + JSON.stringify(j).slice(0, 200));
		expect(/E2E_IMP/.test(sql("SELECT details FROM zzzzsys_nunext_log WHERE event = 'csv_imported' ORDER BY log_time DESC LIMIT 1")), 'log names the item');
		// item switched off: no access
		sql("UPDATE zzzzsys_user_permission SET upe_active = '0' WHERE zzzzsys_user_permission_id = 'e2e_imp_up'");
		const st = (await u.request.get(BASE + 'index.php?p=import', { maxRedirects: 0 })).status();
		expect(st === 403, 'without the item: ' + st);
		// deleting the item removes its type
		await open(p, 'index.php?p=permissions&id=' + itemId);
		const onDlg = d => d.accept().catch(() => {});
		p.once('dialog', onDlg);
		await p.locator('form[data-confirm] button.nuBtnDel').click();
		await p.locator('.modal.show .btn-danger').click().catch(() => {});
		p.off('dialog', onDlg);                                   // our own dialogue, not the browser's: the listener must not reach later tests
		await p.waitForLoadState('networkidle');
		expect(+sql(`SELECT COUNT(*) FROM zzzzsys_nunext_permission_type WHERE zzzzsys_nunext_permission_type_id = '${itemId}'`) === 0, 'type row removed with the item');
	} finally {
		if (u) await u.context().close();
		clean();
	}
};

// 0.8.484: the access level form has the tabs General and Form rights; the tab survives Save and a reload, the count sits on the tab
T.access_tabs = async (s) => {
	const ga = s.ga;
	const id = sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE sal_code = 'sample_viewer'") || sql('SELECT zzzzsys_access_id FROM zzzzsys_access ORDER BY sal_code LIMIT 1');
	const rowsBefore = sql("SELECT COUNT(*) FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = '" + id + "'");
	await open(ga, 'index.php?p=accesslevel&id=' + id);
	expect(await ga.locator('#accessTabGeneral.active #sal_code, #accessTabGeneral.active [name="sal_code"]').count() === 1, 'General tab not open with the code field');
	expect(!(await ga.locator('#accessTabRights').isVisible()), 'rights visible on the General tab');
	expect((await ga.locator('#accessTabRightsBtn .js-rights-count').textContent()).trim() === rowsBefore, 'count on the tab');
	await ga.click('#accessTabRightsBtn');
	await ga.waitForTimeout(300);
	expect(await ga.locator('.js-rights-body').isVisible(), 'rights grid not shown');
	expect(ga.url().includes('tab=rights'), 'address without tab: ' + ga.url());
	await Promise.all([ga.waitForNavigation(), ga.click('[data-nu-save]')]);
	expect(ga.url().includes('tab=rights') && await ga.locator('#accessTabRights.active').count() === 1, 'tab lost after Save: ' + ga.url());
	expect(sql("SELECT COUNT(*) FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = '" + id + "'") === rowsBefore, 'Save changed the rights');
	await ga.click('#accessTabGeneralBtn');
	await ga.waitForTimeout(300);
	expect(!ga.url().includes('tab='), 'tab stays in the address');
	// 0.8.485: "Add form…" is a Tom Select – type, Enter adds the row; a form already in the grid is greyed out
	await ga.click('#accessTabRightsBtn');
	await ga.locator('.nuAddFormTs .ts-control').click();
	await ga.keyboard.type('nuuser');
	await ga.waitForTimeout(300);
	await ga.keyboard.press('Enter');
	await ga.waitForTimeout(300);
	expect(await ga.locator('.js-rights-body tr[data-form="nuuser"]').count() === 1, 'Tom Select did not add the form');
	await ga.locator('.nuAddFormTs .ts-control').click();
	await ga.keyboard.type('nuuser');
	await ga.waitForTimeout(300);
	expect(await ga.locator('.ts-dropdown [data-value="nuuser"][aria-disabled="true"]').count() === 1, 'added form not greyed out');
	await ga.keyboard.press('Escape');
};

T.csrf = async (s) => {
	const p = s.ga;
	const st = await p.evaluate(async () => (await fetch('index.php?p=notifications', { method: 'POST', body: new URLSearchParams({ action: 'readall' }) })).status);
	expect(st === 400, 'POST without CSRF token answered ' + st);
};

T.access_users_filter = async (s) => {                  // access level → Users card: Active (default) · Archived · All (0.8.583)
	const row = sql("SELECT sus_zzzzsys_access_id, zzzzsys_user_id, IFNULL(sus_expires_on, '-') FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!row) { s.skip = 'no sample users'; return; }
	const [acc, uid, keep] = row.split('\t');
	const p = s.ga;
	try {
		sql(`UPDATE zzzzsys_user SET sus_expires_on = '2020-01-01' WHERE zzzzsys_user_id = '${uid}'`);
		await open(p, 'index.php?p=accesslevel&id=' + acc);
		const vis = () => p.$$eval('#accUserList li[data-st]', lis => lis.filter(l => !l.hidden).map(l => l.textContent.trim().split(/\s+/)[0]));
		expect(await p.locator('#accUf_active:checked').count() === 1, 'Active is the default');
		expect(!(await vis()).includes('sample_ben'), 'archived user hidden by default: ' + (await vis()).join(','));
		await p.click('label[for="accUf_archived"]');
		const arc = await vis();
		expect(arc.length >= 1 && arc.includes('sample_ben'), 'Archived shows only archived: ' + arc.join(','));
		expect(await p.$$eval('#accUserList li[data-st="active"]', lis => lis.every(l => l.hidden)), 'active users hidden under Archived');
		await p.click('label[for="accUf_all"]');
		expect((await vis()).length === await p.locator('#accUserList li[data-st]').count(), 'All shows everyone');
		// 0.8.1297: search over login and name, together with the state filter; Esc clears it
		await p.fill('#accUserSearch', 'BEN');
		expect(JSON.stringify(await vis()) === '["sample_ben"]', 'search: ' + (await vis()).join(','));
		await p.click('label[for="accUf_active"]');
		expect((await vis()).length === 0 && await p.locator('#accUserList .accUserNone:not([hidden])').count() === 1, 'search + Active: archived user still shown');
		await p.fill('#accUserSearch', 'zzqq-none'); await p.click('label[for="accUf_all"]');
		expect((await vis()).length === 0, 'unknown word still shows users');
		await p.locator('#accUserSearch').press('Escape');
		expect(await p.inputValue('#accUserSearch') === '' && (await vis()).length === await p.locator('#accUserList li[data-st]').count(), 'Esc did not clear');
		expect(await p.locator('.nuBtnSave.nuSaveEdited').count() === 0, 'searching marked the form as changed');
		expect(p.errors.length === 0, p.errors.join(' | '));
	} finally {
		sql(`UPDATE zzzzsys_user SET sus_expires_on = ${keep === '-' ? 'NULL' : `'${keep}'`} WHERE zzzzsys_user_id = '${uid}'`);
	}
};

// additional access levels per user (0.8.585): dario (sample_sales) gets sample_editor on top – form sampleall opens; expired = gone
// 0.8.1091: user form, additional access levels – the level select gets the room, the date field only what the date needs
T.xa_row_widths = async (s) => {
	const ga = s.ga, vp = ga.viewportSize();
	const id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name <> 'globeadmin' LIMIT 1");
	try {
		for (const w of [390, 1280]) {
			await ga.setViewportSize({ width: w, height: 900 });
			await open(ga, 'index.php?p=user&id=' + encodeURIComponent(id));
			await ga.click('#xaAdd');
			const r = await ga.evaluate(() => {
				const row = [...document.querySelectorAll('#xaRows .xaRow')].pop();
				const sel = (row.querySelector('.ts-wrapper') || row.querySelector('select')).getBoundingClientRect().width, d = row.querySelector('.xaUntil');
				return { sel, date: d.getBoundingClientRect().width, row: row.getBoundingClientRect().width };
			});
			expect(r.sel > r.date, w + ' px: the level select is wider than the date: ' + JSON.stringify(r));
			expect(r.date >= 116 && r.date <= 150, w + ' px: date field fits a date: ' + JSON.stringify(r));
		}
	} finally { await ga.setViewportSize(vp); }
};

T.user_extra_access = async (s) => {
	const dario = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_dario'");
	if (!dario || !sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE zzzzsys_access_id = 'sample_editor'")) { s.skip = 'no sample users'; return; }
	const p = s.ga;
	const formStatus = async (pg) => (await pg.goto(BASE + 'index.php?p=form&f=sampleall')).status();
	try {
		sql(`DELETE FROM zzzzsys_nunext_user_access WHERE user_id = '${dario}'`);
		let u = await login('sample_dario', 'Test1234!');
		expect(await formStatus(u) === 403, 'without the extra level: no access');
		await u.context().close();
		// user form: Add → sample_editor → Save
		await open(p, 'index.php?p=user&id=' + dario);
		expect(await p.isVisible('#xaNone'), 'no additional level at first');
		await p.click('#xaAdd');
		await p.evaluate(() => { const s = document.querySelector('#xaRows .xaRow:last-child select'); if (s.tomselect) s.tomselect.setValue('sample_editor'); else { s.value = 'sample_editor'; s.dispatchEvent(new Event('change', {bubbles: true})); } });
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		expect(sql(`SELECT access_id FROM zzzzsys_nunext_user_access WHERE user_id = '${dario}'`) === 'sample_editor', 'saved');
		expect(/access_extra:sample_editor/.test(sql(`SELECT details FROM zzzzsys_nunext_log WHERE event = 'user_updated' AND record_id = '${dario}' ORDER BY 1 DESC LIMIT 1`)), 'logged');
		u = await login('sample_dario', 'Test1234!');
		expect(await formStatus(u) === 200, 'with the extra level: the form opens');
		await open(u, 'index.php?p=forms');
		expect(await u.locator('text=sampleall').count() > 0 || /sampleall|all objects/i.test(await u.textContent('body')), 'forms list shows the extra form');
		await u.context().close();
		// access level page: dario listed with the badge
		await open(p, 'index.php?p=accesslevel&id=sample_editor');
		expect(await p.locator('#accUserList li', { hasText: 'sample_dario' }).locator('.badge.text-bg-info').count() === 1, 'Users card: Additional badge');
		// expired: no access any more
		sql(`UPDATE zzzzsys_nunext_user_access SET valid_until = '2020-01-01' WHERE user_id = '${dario}'`);
		u = await login('sample_dario', 'Test1234!');
		expect(await formStatus(u) === 403, 'expired extra level: no access');
		await u.context().close();
		// compatibility check lists the user (G10)
		await open(p, 'index.php?p=compat');
		expect(/sample_dario/.test(await p.textContent('#compat_findings tr[data-finding="G10"]')), 'G10 lists the user');
		// removing the row in the user form deletes it
		await open(p, 'index.php?p=user&id=' + dario);
		await p.click('#xaRows .xaDel');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_user_access WHERE user_id = '${dario}'`) === '0', 'removed');
		expect(p.errors.length === 0, p.errors.join(' | '));
	} finally {
		sql(`DELETE FROM zzzzsys_nunext_user_access WHERE user_id = '${dario}'`);
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
	}
};

// all access levels in form code (0.8.867): JS nuNextAccessLevels() = main + active additional levels; an expired one is left out
T.access_levels_js = async (s) => {
	const dario = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_dario'");
	if (!dario || !sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE zzzzsys_access_id = 'sample_editor'")) { s.skip = 'no sample users'; return; }
	const main = sql(`SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE zzzzsys_user_id = '${dario}'`);
	const levels = async () => {
		const u = await login('sample_dario', 'Test1234!');
		await u.goto(BASE + 'index.php?p=form&f=sampleall'); await u.waitForLoadState('networkidle');
		const r = await u.evaluate(() => window.nuNextAccessLevels ? nuNextAccessLevels() : null);
		await u.context().close();
		return r;
	};
	try {
		sql(`DELETE FROM zzzzsys_nunext_user_access WHERE user_id = '${dario}'`);
		sql(`INSERT INTO zzzzsys_nunext_user_access (zzzzsys_nunext_user_access_id, user_id, access_id, valid_until) VALUES ('e2e_al1', '${dario}', 'sample_editor', '2099-12-31'), ('e2e_al2', '${dario}', 'sample_viewer', '2020-01-01')`);
		const r = await levels();
		expect(Array.isArray(r) && r.length === 2, 'levels: ' + JSON.stringify(r));
		expect(r[0].id === main && r[0].main === true && r[0].valid_until === null, 'main first: ' + JSON.stringify(r[0]));
		expect(r[1].id === 'sample_editor' && r[1].code === 'sample_editor' && r[1].main === false && r[1].valid_until === '2099-12-31', 'additional: ' + JSON.stringify(r[1]));
	} finally {
		sql(`DELETE FROM zzzzsys_nunext_user_access WHERE user_id = '${dario}'`);
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
	}
};

// effective rights dialog (0.8.589): the levels as entered (unsaved) – sampleall comes only from the additional level sample_editor
T.effective_rights = async (s) => {
	if (!sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE zzzzsys_user_id = 'sample_dario'")) { s.skip = 'no sample users'; return; }
	const p = s.ga;
	await open(p, 'index.php?p=user&id=sample_dario');
	expect(!await p.isVisible('#xaEff'), 'button hidden without additional levels');
	await p.click('#xaAdd');
	await p.evaluate(() => { const s = document.querySelector('#xaRows .xaRow:last-child select'); if (s.tomselect) s.tomselect.setValue('sample_editor'); else { s.value = 'sample_editor'; s.dispatchEvent(new Event('change', {bubbles: true})); } });
	await p.waitForTimeout(200);
	expect(await p.isVisible('#xaEff'), 'button shown with an additional level');
	await p.click('#xaEff');
	await p.waitForSelector('#xaEffForms tbody tr td');
	await p.waitForTimeout(300);
	const row = p.locator('#xaEffForms tbody tr', { hasText: 'Sample: all objects' }).first();
	expect(await row.locator('.badge.text-bg-warning').count() === 1, 'sampleall: "Additional"');
	expect(await row.locator('td .badge.text-bg-info', { hasText: 'sample_editor' }).count() >= 1, 'the source level next to the tick');
	const all = +await p.textContent('#xaEffNForms');
	await p.check('#xaEffOnly'); await p.waitForTimeout(200);
	const only = +await p.textContent('#xaEffNForms');
	expect(all >= 7 && only > 0 && only < all, 'only from additional levels: ' + only + ' of ' + all);
	expect(await p.locator('#xaEffLevels .badge').count() === 2, 'two levels listed');
	expect(sql("SELECT COUNT(*) FROM zzzzsys_user WHERE zzzzsys_user_id = 'sample_dario' AND sus_zzzzsys_access_id = 'sample_sales'") === '1', 'nothing saved');
	expect(p.errors.length === 0, p.errors.join(' | '));
};

T.include_guards = async (s) => {
	const p = s.ga;
	for (const f of ['core/nuapp.php', 'core/nunotify.php', 'pages/nuhome.php', 'lang/en.php']) {
		const r = await p.goto(BASE + f);
		const body = await p.textContent('body').catch(() => '');
		expect(r.status() >= 400 || body.trim() === '', f + ' gives output');
	}
};

T.adminroles_card_count = async (s) => {             // 0.8.1140: admin roles on phones – users "(n)" after the name, code · rights in one row
	const p = s.ga;
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await p.context().storageState() });
	const ph = await ctx.newPage();
	try {
		await ph.goto(BASE + 'index.php?p=adminroles');
		const m = await ph.evaluate(() => [...document.querySelectorAll('.nuLvCard')].map((c) => ({ cnt: (c.querySelector('.nuLvCardTitle .nuLvCardCount') || {}).textContent || '',
			lines: c.querySelectorAll('.nuLvCardLine').length, join: !!c.querySelector('.nuLvCardJoin code'), labels: c.querySelectorAll('.nuLvCardLabel').length })));
		expect(m.length > 0 && m.every((x) => /^\(\d+\)$/.test(x.cnt) && x.lines === 1 && x.join && x.labels === 0), 'cards: ' + JSON.stringify(m));
	} finally { await ctx.close(); }
};

// 0.8.907: access levels list – the home form is a link that opens in a new tab, the row click still opens the level
T.access_home_link = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=access&view=all');
	const a = p.locator('a.nuAccHomeLink').first();
	expect(await a.count() === 1, 'no home form link in the access levels list');
	expect(await a.getAttribute('target') === '_blank' && /p=form&f=/.test(await a.getAttribute('href')), 'link: new tab to the form');
	const [tab] = await Promise.all([p.context().waitForEvent('page'), a.click()]);
	await tab.waitForLoadState('domcontentloaded');
	expect(/p=form/.test(tab.url()) && /p=access/.test(p.url()), 'the form opened in a new tab, the list stayed: ' + tab.url() + ' / ' + p.url());
	await tab.close();
};

T.pw_edge_reveal = async (s) => {
	// 0.8.944: password fields with our eye button get .nuOwnPwToggle → Edge's own reveal button hidden there (::-ms-reveal), not elsewhere
	const p = s.ga;
	await open(p, 'index.php?p=user&id=' + sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'")); await p.waitForSelector('[data-pw-toggle]', {state: 'attached'});
	const r = await p.evaluate(() => [...document.querySelectorAll('[data-pw-toggle]')].map(b => document.querySelector(b.getAttribute('data-pw-toggle'))).every(i => i && i.classList.contains('nuOwnPwToggle')));
	expect(r, 'fields with our toggle marked');
	const css = fs.readFileSync(path.join(__dirname, '../../assets/css/nuapp.css'), 'utf8');
	expect(/input\.nuOwnPwToggle::-ms-reveal\s*\{\s*display:\s*none/.test(css), 'rule in nuapp.css');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.user_summary_phone = async (s) => {
	// 0.8.1245 (the owner picked mockup A): on phones the summary is compact – avatar 44 px beside the name, status + access level in one
	// row (a long level cut with …, full text as title), the tools side by side
	const p = s.ga;
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name LIKE 'sample_%' ORDER BY sus_login_name LIMIT 1");
	if (!uid) { s.skip = 'no sample users'; return; }
	const acc = sql("SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'");
	const oldCode = sql("SELECT sal_code FROM zzzzsys_access WHERE zzzzsys_access_id = '" + acc + "'");
	sql("UPDATE zzzzsys_access SET sal_code = 'e2e_a_rather_long_access_level_code_for_phones' WHERE zzzzsys_access_id = '" + acc + "'");
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, storageState: await p.context().storageState() });
	const m = await ctx.newPage();
	try {
		await m.goto(BASE + 'index.php?p=user&id=' + uid);
		const r = await m.evaluate(() => {
			const b = (q) => document.querySelector(q).getBoundingClientRect();
			const av = b('#userAvatar'), nm = b('#userAvatarName'), st = document.querySelector('.userSumBadges > .badge:first-child'), lv = document.querySelector('.userSumLevel');
			const sb = st.getBoundingClientRect(), lb = lv.getBoundingClientRect(), card = b('.userSummary');
			const pw = document.getElementById('pwrOpen'), lt = document.querySelector('#loginTokenBox summary');
			return { av: Math.round(av.width), beside: av.right < nm.left, oneRow: Math.abs(sb.top - lb.top) < 4, cut: lv.scrollWidth > lv.clientWidth,
				inCard: lb.right <= card.right, title: lv.title, h: Math.round(card.height),
				tools: pw && lt ? lt.getBoundingClientRect().top - pw.getBoundingClientRect().bottom < 22 : null };   // side by side where they fit, else close below
		});
		if (r.av > 48 || !r.beside || !r.oneRow || !r.cut || !r.inCard || !/rather_long/.test(r.title) || r.tools === false) throw new Error(JSON.stringify(r));
		s.result = r;
	} finally {
		await ctx.close();
		sql("UPDATE zzzzsys_access SET sal_code = " + (oldCode === '' ? 'NULL' : "'" + oldCode + "'") + " WHERE zzzzsys_access_id = '" + acc + "'");
	}
};

T.user_summary_jump = async (s) => {
	// 0.8.1238: the summary's login, code, status badge and access level badge scroll to (and mark) their fields
	const p = s.ga;
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name LIKE 'sample_%' ORDER BY sus_login_name LIMIT 1");
	if (!uid) { s.skip = 'no sample users'; return; }
	const old = sql("SELECT COALESCE(sus_code, '') FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'");
	sql("UPDATE zzzzsys_user SET sus_code = 'J1238' WHERE zzzzsys_user_id = '" + uid + "'");
	await p.setViewportSize({ width: 1280, height: 560 });
	try {
		await open(p, 'index.php?p=user&id=' + uid);
		const got = {};
		for (const f of ['sus_login_name', 'sus_code', 'sus_expires_on', 'sus_zzzzsys_access_id']) {
			await p.evaluate(() => window.scrollTo(0, 0));
			const b = p.locator('.userSummary .userSumJump[data-nu-jump="' + f + '"]');
			if (!(await b.count())) throw new Error('no jump button for ' + f);
			await b.click();
			await p.waitForTimeout(500);
			got[f] = await p.evaluate((id) => {
				const el = document.getElementById(id);
				const v = el.tomselect ? el.tomselect.wrapper : el;
				const r = v.getBoundingClientRect();
				return { inView: r.top >= 0 && r.bottom <= innerHeight, flash: !!document.querySelector('.nuJumpFlash'),
					focus: el.tomselect ? true : (document.activeElement === el || !!(el.closest('.nuDateWrap') && el.closest('.nuDateWrap').contains(document.activeElement))) };
			}, f);
			if (!got[f].inView || !got[f].flash || !got[f].focus) throw new Error(f + ': ' + JSON.stringify(got[f]));
		}
		// 0.8.1239: on a phone (touch, no keyboard) the field is scrolled to and marked but not focused – no on-screen keyboard
		const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 760 }, hasTouch: true, isMobile: true, storageState: await p.context().storageState() });
		const m = await ctx.newPage();
		try {
			await m.goto(BASE + 'index.php?p=user&id=' + uid);
			for (const f of ['sus_login_name', 'sus_code']) {
				await m.locator('.userSummary .userSumJump[data-nu-jump="' + f + '"]').tap();
				await m.waitForTimeout(600);
				const r = await m.evaluate((id) => ({ focus: document.activeElement === document.getElementById(id), flash: !!document.querySelector('.nuJumpFlash') }), f);
				if (r.focus || !r.flash) throw new Error('phone ' + f + ': ' + JSON.stringify(r));
				got['phone_' + f] = r;
			}
		} finally { await ctx.close(); }
		s.result = got;
	} finally {
		sql("UPDATE zzzzsys_user SET sus_code = " + (old === '' ? 'NULL' : "'" + old + "'") + " WHERE zzzzsys_user_id = '" + uid + "'");
		await p.setViewportSize({ width: 1280, height: 900 });
	}
};

T.user_ident_strip = async (s) => {
	// 0.8.1230 (the owner picked mockup A; before: 0.8.1018 / 0.8.1019 Person strip + three columns): summary card on the left (initials,
	// name, login, status, access level, last sign-in, sign-in tools), on the right Person · Organisation · Sign-in & access, each an even grid
	const p = s.ga;
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name LIKE 'sample_%' ORDER BY sus_login_name LIMIT 1");
	if (!uid) { s.skip = 'no sample users'; return; }
	await p.setViewportSize({ width: 1440, height: 900 });
	try {
		await open(p, 'index.php?p=user&id=' + uid);
		const r = await p.evaluate(() => {
			const box = (e) => e ? e.getBoundingClientRect() : null;
			const sum = box(document.querySelector('.userSummary')), main = box(document.querySelector('.userMain'));
			const inP = ['sus_name', 'sus_first_name', 'sus_last_name', 'sus_email', 'sus_code'].every(id => document.getElementById(id)?.closest('.userCardPerson .userGrid'));
			const inOrg = ['sus_position', 'sus_department', 'sus_team', 'sus_additional1', 'sus_additional2'].every(id => document.getElementById(id)?.closest('.userCardOrg .userGrid'));
			const inSign = ['sus_login_name', 'password', 'password_confirm', 'sus_zzzzsys_access_id', 'sus_expires_on', 'sus_language', 'sus_change_password'].every(id => document.getElementById(id)?.closest('.userCardLogin .userGrid'));
			// one grid: the three columns of every section start at the same x
			const lefts = (sel) => [...document.querySelectorAll(sel + ' .userGrid > div')].slice(0, 3).map(d => Math.round(d.getBoundingClientRect().left));
			return { inP, inOrg, inSign, side: sum && main && sum.right <= main.left && Math.abs(sum.top - main.top) < 2, sumW: sum && Math.round(sum.width),
				avatar: !!document.querySelector('.userSummary #userAvatar'), ini: document.getElementById('userAvatarTxt')?.textContent,
				meta: !!document.querySelector('.userSummary .userSumMeta'), tools: !!document.querySelector('.userSummary #pwrOpen'),
				cols: [lefts('.userCardPerson'), lefts('.userCardOrg'), lefts('.userCardLogin')] };
		});
		expect(r.inP && r.inOrg && r.inSign, 'fields not in their sections: ' + JSON.stringify(r));
		expect(r.side && r.sumW === 300 && r.avatar && r.meta && r.tools, 'summary card on the left: ' + JSON.stringify(r));
		expect(r.cols[0].join() === r.cols[1].join() && r.cols[1].join() === r.cols[2].join(), 'sections not on one grid: ' + JSON.stringify(r.cols));
		expect(/^\S{1,2}$/.test(r.ini || ''), 'initials: ' + r.ini);
		await p.fill('#sus_first_name', 'Zoe'); await p.fill('#sus_last_name', 'Quinn');
		expect(await p.textContent('#userAvatarTxt') === 'ZQ', 'initials do not follow the name');
		await p.fill('#sus_first_name', ''); await p.fill('#sus_last_name', '');
		await p.fill('#sus_name', 'Mia Lou Berg');
		expect(await p.textContent('#userAvatarTxt') === 'MB', 'initials from the full name');
		expect((await p.textContent('#userAvatarName')).trim() === 'Mia Lou Berg', 'name in the summary does not follow');
		// 0.8.1231: a wide window – sections at most 1100 px, short fields about 200 px
		await p.setViewportSize({ width: 2400, height: 900 }); await p.evaluate(() => { try { localStorage.setItem('nunext-page-width', 'full'); } catch (e) {} }); await p.reload(); await p.waitForTimeout(300);
		const wide = await p.evaluate(() => ({ main: Math.round(document.querySelector('.userMain').getBoundingClientRect().width), code: Math.round(document.getElementById('sus_code').getBoundingClientRect().width), lang: Math.round(document.getElementById('sus_language').getBoundingClientRect().width) }));
		await p.evaluate(() => { try { localStorage.removeItem('nunext-page-width'); } catch (e) {} });
		expect(wide.main <= 1100 && wide.main > 1000 && wide.code <= 200 && wide.lang <= 200, 'capped on a wide window: ' + JSON.stringify(wide));
		// below 992 px the summary sits above the sections
		await p.setViewportSize({ width: 900, height: 900 }); await p.waitForTimeout(200);
		const st = await p.evaluate(() => { const a = document.querySelector('.userSummary').getBoundingClientRect(), b = document.querySelector('.userMain').getBoundingClientRect(); return a.bottom <= b.top; });
		expect(st, 'summary not above the sections at 900 px');
	} finally { await p.setViewportSize({ width: 1300, height: 900 }); await p.reload().catch(() => {}); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.sessions_phone_cards = async (s) => {
	// 0.8.1021: Logged-in users on phones = one card per user (status, login, badges, Logged in / Last activity, log out);
	// IP and browser only on the unfolded sessions; desktop keeps the table
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 844 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=sessions');
		const r = await p.evaluate(() => {
			const tr = document.querySelector('#sessTable tbody tr:not(.nuSessChild)');
			const vis = (el) => !!el && getComputedStyle(el).display !== 'none';
			return { head: vis(document.querySelector('#sessTable thead')), grid: tr && getComputedStyle(tr).display,
				ip: vis(tr && tr.querySelector('td.sIp')), agent: vis(tr && tr.querySelector('td.sAgent')),
				label: tr ? getComputedStyle(tr.querySelector('td.sLast'), '::before').content : '', wide: document.documentElement.scrollWidth };
		});
		expect(!r.head && r.grid === 'grid' && !r.ip && !r.agent && /Last activity|Letzte/.test(r.label) && r.wide <= 390, 'phone cards: ' + JSON.stringify(r));
	} finally { await ctx.close(); }
	// desktop: still the table
	await open(s.ga, 'index.php?p=sessions');
	expect(await s.ga.evaluate(() => getComputedStyle(document.querySelector('#sessTable thead')).display) !== 'none', 'desktop lost the table head');
};

T.user_help = async (s) => {
	// 0.8.1023: user help pages – editor (TinyMCE / textarea), cleaning, access levels of a page, [[level]] / [[not]] / [[else]] parts,
	// hash variables, #help= links, translations, reader + search + view as, ? button / F1 / (?) of a field with the side panel, design
	// mode fields, palette, export / import
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-userhelp.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_object zzzzsys_nunext_form_draft zzzzsys_nunext_form_history > ' + keep);
	sql('DELETE FROM zzzzsys_nunext_help');
	const post = (url, fields) => p.evaluate(async ([u, f]) => {
		const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value);
		for (const [k, v] of Object.entries(f)) { if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x)); else fd.append(k, v); }
		const r = await fetch(u, { method: 'POST', body: fd, redirect: 'follow' }); return { status: r.status, url: r.url, text: await r.text() };
	}, [url, fields]);
	try {
		// editor: a new page through the form (visual editor when the pack is there, else the text field)
		await open(p, 'index.php?p=helppages&id=new');
		await p.fill('#uhTitle', 'E2E customers'); await p.fill('#uhCode', 'e2e_cust');
		const body = '<p>Hello #USER_FIRST_NAME#.</p><p>[[level: sample_sales]]</p><p>E2E sales part.</p><p>[[else]]</p><p>E2E other part.</p><p>[[/level]]</p>'
			+ '<p>[[not: sample_viewer]]</p><p>E2E not for viewers.</p><p>[[/not]]</p><p><a href="#help=e2e_orders">E2E link</a> <a href="#help=e2e_secret">E2E secret link</a></p>'
			+ '<script>window.e2eBad=1</script><p onclick="x()">E2E click</p><img src="javascript:alert(1)"><p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==" alt="E2E pic"></p>';
		const tiny = await p.waitForFunction(() => window.tinymce && tinymce.activeEditor && tinymce.activeEditor.initialized, null, { timeout: 8000 }).then(() => true, () => false);
		if (tiny) await p.evaluate((b) => tinymce.activeEditor.setContent(b), body); else await p.fill('#uhBody', body);
		await Promise.all([p.waitForNavigation(), p.click('#uhSave')]);
		const stored = sql("SELECT body FROM zzzzsys_nunext_help WHERE code = 'e2e_cust' AND lang = ''");
		expect(stored.includes('[[level: sample_sales]]') && !/script|onclick|javascript:/i.test(stored), 'cleaned body: ' + stored.slice(0, 300));
		const base = sql("SELECT zzzzsys_nunext_help_id FROM zzzzsys_nunext_help WHERE code = 'e2e_cust' AND lang = ''");
		// pictures become files (0.8.1024): custom/userhelp/<sha256>.png, served to signed-in users only; an upload that is no picture is refused
		const pic = (stored.match(/userhelpimg&(?:amp;)?f=([a-f0-9]{64}\.png)/) || [])[1];
		expect(pic && !stored.includes('data:image') && (!SITE || fs.existsSync(path.join(SITE, 'custom/userhelp', pic))), 'picture as file: ' + stored.slice(-300));
		const picSt = await p.evaluate(async (n) => { const r = await fetch('index.php?p=userhelpimg&f=' + n); return [r.status, r.headers.get('content-type')]; }, pic);
		expect(picSt[0] === 200 && picSt[1] === 'image/png', 'picture served: ' + picSt);
		const anon = await p.context().browser().newContext();
		try {
			const ap = await anon.newPage();
			const ar = await ap.request.get(BASE + 'index.php?p=userhelpimg&f=' + pic, { maxRedirects: 0 });
			expect(!(ar.status() === 200 && (ar.headers()['content-type'] || '').startsWith('image/')), 'picture served without a session');
		} finally { await anon.close(); }
		const bad = await p.evaluate(async () => {
			const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value); fd.append('action', 'upload_image');
			fd.append('file', new Blob(['<?php echo 1;'], { type: 'image/png' }), 'x.png');
			return (await fetch('index.php?p=helppages', { method: 'POST', body: fd })).status;
		});
		expect(bad === 400, 'non-picture upload: ' + bad);
		// more pages: a linked one, a restricted one (only sample_editor), a draft; a German translation
		let r = await post('index.php?p=helppages&id=new', { action: 'save', code: 'e2e_orders', title: 'E2E orders', body: '<p>E2E orders text with keyword zebrafish.</p>', format: 'html', parent: 'e2e_cust', active: '1', sort_order: '1' });
		expect(r.status === 200, 'second page: ' + r.status);
		await post('index.php?p=helppages&id=new', { action: 'save', code: 'e2e_secret', title: 'E2E secret', body: '<p>E2E secret text.</p>', format: 'html', active: '1', access: ['sample_editor'] });
		await post('index.php?p=helppages&id=new', { action: 'save', code: 'e2e_draft', title: 'E2E draft', body: '# E2E markdown\n\nE2E **bold** [orders](#help=e2e_orders)', format: 'md', active: '0' });
		await post('index.php?p=helppages&id=new&tr=e2e_orders&lang=de', { action: 'save', lang: 'de', title: 'E2E Bestellungen', body: '<p>E2E deutscher Text.</p>', format: 'html' });
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_help") === '5', 'rows: ' + sql("SELECT GROUP_CONCAT(code, '/', lang) FROM zzzzsys_nunext_help"));
		// errors: duplicate code, parent loop
		r = await post('index.php?p=helppages&id=new', { action: 'save', code: 'e2e_orders', title: 'x', body: '', format: 'html' });
		expect(r.text.includes('is-invalid'), 'duplicate code accepted');
		r = await post('index.php?p=helppages&id=' + base, { action: 'save', code: 'e2e_cust', title: 'E2E customers', body: stored, format: 'html', parent: 'e2e_orders', active: '1' });
		expect(r.text.includes('is-invalid'), 'parent loop accepted');
		// list
		await open(p, 'index.php?p=helppages');
		const lst = await p.evaluate(() => document.querySelector('.nuListCard tbody')?.textContent || '');
		expect(lst.includes('E2E customers') && lst.includes('E2E draft') && !lst.includes('E2E Bestellungen'), 'list: ' + lst.slice(0, 200));
		// link the pages: form + tab (live save), a field
		await open(p, 'index.php?p=form&f=' + F + '&design=1');
		const api = (b) => p.evaluate(async (bd) => {
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf }, body: JSON.stringify(bd) });
			return Object.assign({ status: r.status }, await r.json());
		}, Object.assign({ form_id: F }, b));
		await p.waitForSelector('[data-key="nunext_help"]', { timeout: 10000 });
		r = await api({ action: 'form.save', fields: { help: 'e2e_cust', help_tabs: { sample_customer_t2: 'e2e_orders' } } });
		expect(r.ok, 'form.save help: ' + (r.error || JSON.stringify(r).slice(0, 200)));
		const oid = sql("SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '" + F + "' AND sob_all_id = 'cus_email'");
		r = await api({ action: 'object.save', object_id: oid, fields: { nunext_help: 'e2e_orders' } });
		expect(r.ok, 'object.save help: ' + (r.error || ''));
		const fj = JSON.parse(sql("SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'") || '{}');
		expect(fj.nunext && fj.nunext.help === 'e2e_cust' && fj.nunext.help_tabs.sample_customer_t2 === 'e2e_orders', 'sfo_json: ' + JSON.stringify(fj));
		expect(JSON.parse(sql("SELECT sob_all_json FROM zzzzsys_object WHERE zzzzsys_object_id = '" + oid + "'")).nunext.help === 'e2e_orders', 'sob_all_json');
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1');
		await p.waitForSelector('[data-key="nunext_help"]', { timeout: 10000 });
		const dsv = await p.evaluate(() => ({ v: document.querySelector('[data-key="nunext_help"]').value, tab: document.querySelector('[data-key="nunext_help_tab_sample_customer_t2"]')?.value,
			opts: [...(document.getElementById('nuDsHelpList')?.options || [])].map(o => o.value) }));
		expect(dsv.v === 'e2e_cust' && dsv.tab === 'e2e_orders' && dsv.opts.includes('e2e_secret'), 'design fields: ' + JSON.stringify(dsv));
		// usage on the editor
		await open(p, 'index.php?p=helppages&id=' + base);
		expect((await p.textContent('#uhUsage')).length > 3, 'usage empty');
		// a user of sample_sales: panel, conditions, field mark, F1, the tab's page
		const dario = await login('sample_dario', UPW);
		try {
			await open(dario, 'index.php?p=form&f=' + F + '&r=sample_cus_01');
			expect(await dario.isVisible('#nuHelpButton'), 'no ? button: ' + (await dario.evaluate(() => (document.getElementById('nuHelpButton')?.outerHTML || 'none') + ' | ' + location.href + ' | ' + document.body.innerText.slice(0, 200))));
			await dario.click('#nuHelpButton');
			await dario.waitForSelector('#nuUhPanelBody p', { timeout: 8000 });
			const pan = await dario.evaluate(() => ({ t: document.getElementById('nuUhPanelTitle').textContent, b: document.getElementById('nuUhPanelBody').textContent,
				links: [...document.querySelectorAll('#nuUhPanelBody > p a[data-nu-uhelp]')].map(a => a.getAttribute('data-nu-uhelp')), cond: document.querySelectorAll('#nuUhPanelBody .nuUhCond').length }));
			expect(pan.t === 'E2E customers' && pan.b.includes('E2E sales part') && !pan.b.includes('E2E other part') && pan.b.includes('E2E not for viewers') && pan.cond === 0
				&& !pan.b.includes('#USER_FIRST_NAME#') && pan.links.join() === 'e2e_orders' && pan.b.includes('E2E secret link'), 'panel (sales): ' + JSON.stringify(pan));
			await dario.click('#nuUhPanelBody > p a[data-nu-uhelp="e2e_orders"]');
			await dario.waitForFunction(() => document.getElementById('nuUhPanelTitle').textContent === 'E2E orders', null, { timeout: 8000 });
			expect(await dario.isVisible('#nuUhPanelBack'), 'no back in the panel');
			await dario.keyboard.press('Escape');
			expect(await dario.isHidden('#nuUhPanel'), 'Esc did not close');
			// field mark with the first paragraph as tooltip
			const tip = await dario.evaluate(() => { const t = document.querySelector('#label_cus_email .nuUhTip'); return t ? t.getAttribute('title') : null; });
			expect(tip && tip.includes('zebrafish'), 'field mark: ' + tip);
			// the Details tab has its own page; F1 opens it
			await dario.click('.nuTab[data-nu-tab-id="sample_customer_t2"]');
			await dario.keyboard.press('F1');
			await dario.waitForFunction(() => document.getElementById('nuUhPanelTitle')?.textContent === 'E2E orders', null, { timeout: 8000 });
			// reader: no secret page, no draft; search
			await open(dario, 'index.php?p=userhelp');
			const nav = await dario.evaluate(() => [...document.querySelectorAll('.nuUhNavLink')].map(a => a.dataset.code));
			expect(nav.includes('e2e_cust') && nav.includes('e2e_orders') && !nav.includes('e2e_secret') && !nav.includes('e2e_draft'), 'reader nav: ' + nav);
			await open(dario, 'index.php?p=userhelp&c=e2e_secret');
			expect(await dario.isVisible('#nuUhNone') && !(await dario.textContent('body')).includes('E2E secret text'), 'secret page shown');
			await open(dario, 'index.php?p=userhelp&q=zebrafish');
			expect((await dario.textContent('#nuUhHits')).includes('E2E orders'), 'search');
			expect(await dario.isVisible('#nuMenuUserHelp') || (await dario.locator('#nuMenuUserHelp').count()) > 0, 'user menu entry');
			expect(dario.errors.length === 0, dario.errors.join(' | '));
		} finally { await dario.context().close(); }
		// sample_anna (viewer): the else part, not the "not for viewers" part
		const anna = await login('sample_anna', UPW);
		try {
			const j = await anna.evaluate(async () => (await fetch('index.php?p=userhelp&c=e2e_cust&frag=1')).json());
			expect(j.ok && j.html.includes('E2E other part') && !j.html.includes('E2E sales part') && !j.html.includes('E2E not for viewers'), 'viewer: ' + JSON.stringify(j).slice(0, 300));
		} finally { await anna.context().close(); }
		// editor view: marked parts, draft and secret pages, view as
		await open(p, 'index.php?p=userhelp&c=e2e_cust');
		expect(await p.locator('#nuUhArticle .nuUhCond').count() === 3, 'editor marks');
		await open(p, 'index.php?p=userhelp&c=e2e_cust&as=sample_viewer');
		const asv = await p.textContent('#nuUhArticle');
		expect(asv.includes('E2E other part') && !asv.includes('E2E sales part') && await p.locator('#nuUhArticle .nuUhCond').count() === 0, 'view as viewer');
		await open(p, 'index.php?p=userhelp&c=e2e_draft');
		expect((await p.innerHTML('#nuUhArticle')).includes('<b>bold</b>') && await p.locator('#nuUhArticle a[data-nu-uhelp="e2e_orders"]').count() === 1, 'markdown page');
		// German translation
		await p.goto(BASE + 'index.php?p=lang&l=de-DE&r=' + encodeURIComponent('p=home'));
		const de = await p.evaluate(async () => (await fetch('index.php?p=userhelp&c=e2e_orders&frag=1')).json());
		await p.goto(BASE + 'index.php?p=lang&l=en-GB&r=' + encodeURIComponent('p=home'));
		expect(de.title === 'E2E Bestellungen' && de.html.includes('deutscher'), 'translation: ' + JSON.stringify(de).slice(0, 200));
		// 0.8.1025: Draft with AI – related screens of a form (sub-form ticked, lookup offered) and their fields in the prompt
		const rel = JSON.parse((await post('index.php?p=helppages', { action: 'related', form: 'sampleorder' })).text);
		const rSf = (rel.related || []).find(x => x.id === 'sampleorderitem'), rLu = (rel.related || []).find(x => x.id === 'samplecustomer');
		expect(rel.ok && rSf && rSf.kind === 'subform' && rSf.on && rLu && rLu.kind === 'lookup' && !rLu.on && rLu.kind_text, 'related: ' + JSON.stringify(rel).slice(0, 300));
		const pr = JSON.parse((await post('index.php?p=helppages', { action: 'ai_prompt', form: 'sampleorder', related: ['sampleorderitem'] })).text);
		const prompt = pr.prompt || '';
		expect(/## Related:/.test(prompt) && prompt.split('## Related:').length === 2, 'prompt without the related screen: ' + prompt.slice(0, 400));
		await open(p, 'index.php?p=helppages&id=new');
		if (await p.locator('#uhAiForm').count()) {
			await p.evaluate(() => { const s = document.getElementById('uhAiForm'); s.value = 'sampleorder'; s.dispatchEvent(new Event('change', { bubbles: true })); if (s.tomselect) s.tomselect.setValue('sampleorder'); });
			await p.waitForSelector('#uhAiRel .uhAiRelChk', { state: 'attached', timeout: 5000 });
			expect(await p.locator('#uhAiRel .uhAiRelChk[value="sampleorderitem"]:checked').count() === 1, 'related checkbox of the sub-form not ticked');
			// 0.8.1037: language of the draft – a select in the dialog (interface language first), sent with the prompt, remembered
			expect(await p.$eval('#uhAiLang', (s) => s.value) === 'en' && await p.locator('#uhAiLang option[value="de"]').count() === 1, 'language select');
			await p.click('#uhAiBtn'); await p.waitForSelector('#uhAiLang', { state: 'visible' });
			await p.selectOption('#uhAiLang', 'de');
			const sent = p.waitForRequest((r) => r.method() === 'POST' && (r.postData() || '').includes('ai_prompt'));
			await p.click('#uhAiCopy');
			expect(/name="lang"\r\n\r\nde\r\n/.test((await sent).postData()), 'language sent with the prompt');
			await p.waitForFunction(() => document.getElementById('uhAiPrompt').value !== '');
			expect(/Language of the help page: German/.test(await p.$eval('#uhAiPrompt', (t) => t.value)), 'prompt in German');
			await open(p, 'index.php?p=helppages&id=new');
			expect(await p.$eval('#uhAiLang', (s) => s.value) === 'de', 'last language remembered');
			await p.evaluate(() => { try { localStorage.removeItem('nunext-uh-ai-lang'); } catch (e) { /* */ } });
			// 0.8.1038: an answer in Markdown becomes HTML on a page with the visual editor (it was one paragraph without line breaks)
			if (await p.locator('#uhFmtHtml:checked').count()) {
				await p.click('#uhAiBtn'); await p.waitForSelector('#uhAiAnswer', { state: 'visible' });
				await p.fill('#uhAiAnswer', '# Orders\n\n## How to use\n\n1. Open a tab.\n2. Enter **text**.\n\n- one\n- two\n\nLast line.');
				await p.click('#uhAiInsert');
				await p.waitForFunction(() => { const e = window.tinymce && window.tinymce.activeEditor; const v = e ? e.getContent() : document.getElementById('uhBody').value; return /<h2/.test(v); }, null, { timeout: 8000 });
				const v = await p.evaluate(() => { const e = window.tinymce && window.tinymce.activeEditor; return e ? e.getContent() : document.getElementById('uhBody').value; });
				expect(/<ol>\s*<li>Open a tab\.<\/li>/.test(v) && /<strong>text<\/strong>/.test(v) && /<ul>/.test(v) && /<p>Last line\.<\/p>/.test(v), 'Markdown answer converted: ' + v.slice(0, 300));
			}
			expect(/ONE code block/.test(prompt), 'prompt asks for one code block');
		}
		// palette
		const pal = await p.evaluate(async () => (await fetch('index.php?p=palette')).json());
		expect(JSON.stringify(pal).includes('h:e2e_cust'), 'palette entry missing');
		// export → delete → import
		const exp = await p.evaluate(async () => (await fetch('index.php?p=helppages&export=1')).text());
		const ej = JSON.parse(exp);
		expect(ej.pages.some(x => x.code === 'e2e_cust' && x.body.includes('data:image/png;base64,')), 'export without the picture inside');
		expect(ej.format === 'nunext-help-1' && ej.pages.length === 5 && ej.pages.some(x => x.code === 'e2e_secret' && x.access.includes('sample_editor')), 'export: ' + exp.slice(0, 200));
		sql("DELETE FROM zzzzsys_nunext_help");
		const imp = await p.evaluate(async (txt) => {
			const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value); fd.append('action', 'import');
			fd.append('file', new Blob([txt], { type: 'application/json' }), 'h.json');
			return (await fetch('index.php?p=helppages', { method: 'POST', body: fd })).status;
		}, exp);
		expect(imp === 200 && sql("SELECT COUNT(*) FROM zzzzsys_nunext_help") === '5' && sql("SELECT parent FROM zzzzsys_nunext_help WHERE code = 'e2e_orders' AND lang = ''") === 'e2e_cust'
			&& sql("SELECT access FROM zzzzsys_nunext_help WHERE code = 'e2e_secret'").includes('sample_editor'), 'import');
		expect(sql("SELECT body FROM zzzzsys_nunext_help WHERE code = 'e2e_cust'").includes('userhelpimg'), 'import: picture as file again');
		// delete a page with its translation
		const oidOrders = sql("SELECT zzzzsys_nunext_help_id FROM zzzzsys_nunext_help WHERE code = 'e2e_orders' AND lang = ''");
		await post('index.php?p=helppages&id=' + oidOrders, { action: 'delete' });
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_help WHERE code = 'e2e_orders'") === '0', 'delete with translations');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
		sql('DELETE FROM zzzzsys_nunext_help');
	}
};

T.external_user = async (s) => {                     // 0.8.1273: nuexternal.php – own PHP apps in a sub-folder use nuBuilder Next's sign-in
	if (!SITE) return 'skip';
	const dir = path.join(SITE, 'e2etools', 'extapp');
	fs.mkdirSync(dir, { recursive: true });
	fs.writeFileSync(path.join(dir, 'app.php'), `<?php
require __DIR__ . '/../../nuexternal.php';
if (($_GET['mode'] ?? '') === 'peek') { header('Content-Type: application/json'); echo json_encode(['user' => NuNextUser::current()]); exit; }
$me = NuNextUser::require();
if (($_GET['mode'] ?? '') === 'deny' && !NuNextUser::hasPermission('e2e_no_such_item')) NuNextUser::deny('No right');
header('Content-Type: application/json');
echo json_encode(['user' => $me, 'ga' => NuNextUser::isGlobeadmin(), 'perm' => NuNextUser::hasPermission(...($me['permissions'] ?: ['x'])),
	'levels' => array_column($me['access_levels'], 'code'), 'lvl' => $me['access_levels'] ? NuNextUser::hasAccessLevel($me['access_levels'][0]['code']) : null,
	'home' => NuNextUser::homeUrl(), 'session_untouched' => session_status() === PHP_SESSION_NONE]);
`);
	try {
		const ctx = await browser.newContext({ locale: 'en-GB' });
		const p = await ctx.newPage();
		// not signed in: current() null, require() → sign-in page with the way back
		let r = await p.request.get(BASE + 'e2etools/extapp/app.php?mode=peek');
		expect((await r.json()).user === null, 'user without sign-in');
		await p.goto(BASE + 'e2etools/extapp/app.php?a=1');
		expect(/p=login/.test(p.url()) && /next=e2etools%2Fextapp%2Fapp\.php%3Fa%3D1/.test(p.url()), 'no redirect to the sign-in: ' + p.url());
		await p.fill('#username', 'sample_anna'); await p.fill('#password', UPW);
		await Promise.all([p.waitForURL(/extapp\/app\.php/, { timeout: 15000 }), p.click('form button[type=submit]:not([name=demo_user])')]);
		expect(/e2etools\/extapp\/app\.php\?a=1$/.test(p.url()), 'not back in the app: ' + p.url());
		const j = JSON.parse(await p.locator('body').innerText());
		const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
		expect(j.user.id === uid && j.user.login === 'sample_anna' && !j.ga, 'wrong user: ' + JSON.stringify(j.user).slice(0, 200));
		const want = sql("SELECT COUNT(*) FROM zzzzsys_user_permission LEFT JOIN zzzzsys_permission_item ON zzzzsys_permission_item_id = upe_ident WHERE upe_zzzzsys_user_id = '" + uid + "' AND COALESCE(upe_active,'0') = '1' AND pme_ident IS NOT NULL");
		expect(String(j.user.permissions.length) === want, 'permissions ' + j.user.permissions.length + ' vs ' + want);
		expect(j.user.access_code !== '' && j.levels.includes(j.user.access_code) && j.lvl === true, 'access levels: ' + JSON.stringify(j.levels));
		expect(j.home === new URL(BASE).pathname, 'homeUrl ' + j.home);
		expect(j.session_untouched, 'the helper started a session');
		r = await p.request.get(BASE + 'e2etools/extapp/app.php?mode=deny', { headers: { Accept: 'application/json' } });
		expect(r.status() === 403 && (await r.json()).error === 'No right', 'deny() did not answer 403 JSON');
		// the helper file itself is never a page
		r = await p.request.get(BASE + 'nuexternal.php');
		expect(r.status() === 404, 'nuexternal.php served: ' + r.status());
		// signed out: no user any more
		await open(p, 'index.php?p=home');
		await Promise.all([p.waitForURL(/p=login/), p.evaluate(() => { const f = document.createElement('form'); f.method = 'post'; f.action = 'index.php?p=logout';
			const i = document.createElement('input'); i.name = '_csrf'; i.value = document.querySelector('input[name=_csrf]').value; f.appendChild(i); document.body.appendChild(f); f.submit(); })]);
		r = await p.request.get(BASE + 'e2etools/extapp/app.php?mode=peek');
		expect((await r.json()).user === null, 'user still there after signing out');
		// the globeadmin
		r = await s.ga.request.get(BASE + 'e2etools/extapp/app.php');
		const g = await r.json();
		expect(g.ga === true && g.user.id === 'globeadmin' && g.perm === true, 'globeadmin: ' + JSON.stringify(g).slice(0, 200));
		// an idle session older than the timeout no longer counts
		const key = sql("SELECT zzzzsys_nunext_session_id FROM zzzzsys_nunext_session WHERE user_id = 'globeadmin' ORDER BY seen_ts DESC LIMIT 1");
		const ts = sql("SELECT seen_ts FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id = '" + key + "'");
		expect(+ts > 0, 'seen_ts not written');
		sql("UPDATE zzzzsys_nunext_session SET seen_ts = seen_ts - 86400 WHERE zzzzsys_nunext_session_id = '" + key + "'");
		r = await s.ga.request.get(BASE + 'e2etools/extapp/app.php?mode=peek');
		const idle = (await r.json()).user;
		sql("UPDATE zzzzsys_nunext_session SET seen_ts = " + ts + " WHERE zzzzsys_nunext_session_id = '" + key + "'");
		expect(idle === null, 'idle session still counted');
		await ctx.close();
	} finally {
		fs.rmSync(path.join(SITE, 'e2etools'), { recursive: true, force: true });
	}
};

// 0.8.1284: user menu – Documentation / Tutorial in a section of their own after the database tools / File Manager, no two dividers in a row
T.user_menu_help_section = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=users');
	const r = await p.evaluate(() => {
		const docs = document.getElementById('nuMenuDocs'), ul = docs && docs.closest('ul');
		if (!ul) return null;
		const lis = [...ul.children].filter(li => !li.hidden);
		const idx = (el) => lis.indexOf(el && el.closest('li'));
		let dbl = false;
		lis.forEach((li, i) => { if (i && li.querySelector('hr') && lis[i - 1].querySelector('hr')) dbl = true; });
		const files = document.getElementById('nuMenuFiles'), db = ul.querySelector('form[action*="p=vendor"]');
		const prev = lis[idx(docs) - 1];
		return { docs: idx(docs), files: idx(files), db: idx(db), divider: !!(prev && prev.querySelector('hr')), dbl };
	});
	expect(r && !r.dbl && r.divider && (r.files < 0 || r.files < r.docs) && (r.db < 0 || r.db < r.docs), 'user menu order: ' + JSON.stringify(r));
};

T.admin_role_changes = async (s) => {
	// 0.8.1323: admin role form – changed rights are marked until saved (row, "was: …", count), Show changed only, Undo changes
	const p = s.ga;
	const id = sql("SELECT zzzzsys_nunext_admin_role_id FROM zzzzsys_nunext_admin_role WHERE code = 'support'");
	if (!id) { s.skip = 'no support role'; return; }
	await open(p, 'index.php?p=adminroles&id=' + id);
	expect(await p.locator('#admChanges').isHidden() && await p.locator('#admRights tr.admChanged').count() === 0, 'marks before a change');
	const picked = await p.evaluate(() => {
		const out = [];
		for (const tr of document.querySelectorAll('#admRights tbody tr[data-orig]')) {
			const r = [...tr.querySelectorAll('input[type=radio]:not(:disabled)')].find((x) => x.value !== tr.getAttribute('data-orig'));
			if (r) { r.click(); out.push(tr.getAttribute('data-name')); }
			if (out.length === 2) break;
		}
		return out;
	});
	const st = await p.evaluate(() => ({ n: document.querySelectorAll('#admRights tr.admChanged').length, was: document.querySelectorAll('#admRights tr.admChanged .admWas').length,
		cnt: document.getElementById('admChgCount').textContent, shown: !document.getElementById('admChanges').hidden }));
	expect(picked.length === 2 && st.n === 2 && st.was === 2 && st.shown && /2/.test(st.cnt), 'marked: ' + JSON.stringify(st));
	await p.click('#admChgOnly');
	expect(await p.evaluate(() => [...document.querySelectorAll('#admRights tbody tr')].filter((t) => !t.hidden).length) === 2, 'show changed only');
	await p.click('#admChgUndo');
	const back = await p.evaluate(() => ({ n: document.querySelectorAll('#admRights tr.admChanged').length, hidden: document.getElementById('admChanges').hidden,
		vis: [...document.querySelectorAll('#admRights tbody tr')].filter((t) => !t.hidden).length, all: document.querySelectorAll('#admRights tbody tr').length,
		same: [...document.querySelectorAll('#admRights tbody tr[data-orig]')].every((tr) => { const c = tr.querySelector('input:checked'); return (c ? c.value : '') === tr.getAttribute('data-orig'); }) }));
	expect(back.n === 0 && back.hidden && back.vis === back.all && back.same, 'undo: ' + JSON.stringify(back));
	await open(p, 'index.php?p=adminroles&id=new');
	expect(await p.locator('#admRights[data-track]').count() === 0 && await p.locator('#admChanges').count() === 0, 'no marks on a new role');
	s.result = picked;
};

// Secrets at rest (0.8.1326): with $nuConfigSecretKey the SMTP password is kept encrypted by nuBuilder Next; "only in nuBuilder Next"
// empties the shared column (nuBuilder Forte reads it there) and sending still logs in; secrets rekey --new=generate re-encrypts
// everything and writes the new key into nuconfig.php; switching off writes the password back for Forte; the Security check line
T.secrets_at_rest = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const p = s.ga, cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	const port = 2600 + Math.floor(Math.random() * 300), log = path.join(os.tmpdir(), 'e2e-smtp-sec-' + Date.now() + '.log');
	const cols = 'set_smtp_host,set_smtp_port,set_smtp_username,set_smtp_password,set_smtp_from_address,set_smtp_use_ssl,set_smtp_use_authentication';
	const old = sql('SELECT CONCAT_WS(\',\',' + cols.split(',').map(c => "IFNULL(HEX(CAST(" + c + " AS CHAR)),'-')").join(',') + ') FROM zzzzsys_setup LIMIT 1').split(',');
	const srv = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), log, String(port)], { stdio: 'ignore' });
	const key0 = 'e2e' + 'k'.repeat(61);
	const cron = (a) => { try { return { code: 0, out: execSync('php nucron.php ' + a + ' 2>&1', { cwd: SITE }).toString() }; } catch (e) { return { code: e.status, out: String(e.stdout || '') }; } };
	const checkLogin = async () => {
		await open(p, 'index.php?p=email&tab=settings');
		await Promise.all([p.waitForNavigation(), p.click('#nuMailCheckBtn2')]); await p.waitForLoadState('networkidle');
		return p.evaluate(() => (document.querySelector('#mailCheckResult [data-step="login"]') || {}).dataset?.ok);
	};
	const save = async (only) => {
		await open(p, 'index.php?p=email&tab=settings');
		const sw = p.locator('#m_only');
		if ((await sw.isChecked()) !== only) await sw.click();
		await Promise.all([p.waitForNavigation(), p.click('#nuMailForm [data-nu-save], [data-nu-save][form="nuMailForm"], button[data-nu-save]')]);
		await p.waitForLoadState('networkidle');
	};
	try {
		fs.writeFileSync(cfg, orig.replace(/\?>\s*$/, '').replace(/^\s*\$nuConfigSecretKey\s*=.*$/m, '') + "\n$nuConfigSecretKey = '" + key0 + "';\n");
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'smtp_secret'");
		sql("UPDATE zzzzsys_setup SET set_smtp_host='127.0.0.1', set_smtp_port=" + port + ", set_smtp_username='u', set_smtp_password='p', set_smtp_from_address='noreply@example.com', set_smtp_use_ssl='0', set_smtp_use_authentication='1'");
		await new Promise(r => setTimeout(r, 3500));
		await open(p, 'index.php?p=securitycheck');
		const st0 = await p.evaluate(() => { const li = document.getElementById('sec_secrets'); return li ? li.className : ''; });
		expect(/sec-warn/.test(st0), 'Security check: plain shared SMTP password is a warning: ' + st0);
		await save(true);
		expect(sql('SELECT IFNULL(set_smtp_password, \'\') FROM zzzzsys_setup LIMIT 1') === '', 'shared column emptied');
		const own = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'smtp_secret'");
		expect(/"pw":"enc:/.test(own) && !own.includes('"p"') && /"only":true/.test(own), 'own copy encrypted: ' + own);
		expect(await checkLogin() === '1', 'login works with the encrypted copy');
		await open(p, 'index.php?p=securitycheck');
		expect(/sec-ok/.test(await p.evaluate(() => document.getElementById('sec_secrets').className)), 'Security check ok after moving the password');
		const dry = cron('secrets rekey --new=generate --dry-run');
		expect(dry.code === 0 && /Check passed/.test(dry.out), 'dry run: ' + dry.out);
		expect(fs.readFileSync(cfg, 'utf8').includes(key0), 'dry run leaves the key');
		const rk = cron('secrets rekey --new=generate --yes');
		const cfgNow = fs.readFileSync(cfg, 'utf8');
		expect(rk.code === 0 && /re-encrypted/.test(rk.out) && !cfgNow.includes(key0) && /\$nuConfigSecretKey = '[0-9a-f]{64}'/.test(cfgNow), 'rekey: ' + rk.out);
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'smtp_secret'") !== own, 'copy re-encrypted');
		await new Promise(r => setTimeout(r, 3500));
		expect(await checkLogin() === '1', 'login works after the new key');
		const stat = cron('secrets status');
		expect(stat.code === 0 && /smtp\s+encrypted 1/.test(stat.out), 'status: ' + stat.out);
		const bad = cron('secrets rekey --old=' + key0 + ' --dry-run');
		expect(bad.code === 1 && /cannot be decrypted/.test(bad.out), 'a wrong old key is refused: ' + bad.out);
		await save(false);
		expect(sql('SELECT set_smtp_password FROM zzzzsys_setup LIMIT 1') === 'p', 'switched off: password written back for nuBuilder Forte');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'secrets_rekeyed'") > 0, 'rekey logged');
	} finally {
		srv.kill();
		fs.writeFileSync(cfg, orig);
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'smtp_secret'");
		sql('UPDATE zzzzsys_setup SET ' + cols.split(',').map((c, i) => c + '=' + (old[i] === '-' ? 'NULL' : "UNHEX('" + old[i] + "')")).join(','));
		fs.rmSync(log, { force: true });
		await new Promise(r => setTimeout(r, 3500));
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.access_delete_expired = async (s) => {
	// 0.8.1250: expired users do not block deleting an access level; their level is emptied, extra assignments go with it
	const p = s.ga;
	const del = () => sql(`DELETE FROM zzzzsys_user WHERE zzzzsys_user_id IN ('e2e_ad_u1', 'e2e_ad_u2'); DELETE FROM zzzzsys_access WHERE zzzzsys_access_id = 'e2e_ad_lvl';
		DELETE FROM zzzzsys_nunext_user_access WHERE zzzzsys_nunext_user_access_id = 'e2e_ad_x1'`);
	del();
	const other = sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE zzzzsys_access_id <> 'e2e_ad_lvl' ORDER BY zzzzsys_access_id LIMIT 1");
	sql(`INSERT INTO zzzzsys_access (zzzzsys_access_id, sal_code, sal_description) VALUES ('e2e_ad_lvl', 'e2eAdLvl', 'E2E delete');
		INSERT INTO zzzzsys_user (zzzzsys_user_id, sus_zzzzsys_access_id, sus_name, sus_login_name, sus_expires_on) VALUES
		('e2e_ad_u1', 'e2e_ad_lvl', 'AD 1', 'e2e_ad_u1', NULL), ('e2e_ad_u2', '${other}', 'AD 2', 'e2e_ad_u2', NULL)`);
	sql("INSERT INTO zzzzsys_nunext_user_access (zzzzsys_nunext_user_access_id, user_id, access_id, valid_until) VALUES ('e2e_ad_x1', 'e2e_ad_u2', 'e2e_ad_lvl', '2020-01-01')");
	try {
		await open(p, 'index.php?p=accesslevel&id=e2e_ad_lvl');
		expect(await p.locator('.nuBtnDel.nuBtnBlocked').count() === 1, 'Delete not blocked while an active user has the level');
		expect(/1 active user/.test(await p.getAttribute('.nuBtnDel.nuBtnBlocked', 'title')), 'reason: ' + await p.getAttribute('.nuBtnDel.nuBtnBlocked', 'title'));
		sql("UPDATE zzzzsys_user SET sus_expires_on = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE zzzzsys_user_id = 'e2e_ad_u1'");
		await open(p, 'index.php?p=accesslevel&id=e2e_ad_lvl');
		expect(await p.locator('.nuBtnDel.nuBtnBlocked').count() === 0, 'expired user (and an ended extra level) still block Delete');
		const conf = await p.locator('input[name=action][value=delete]').evaluate(i => i.form.getAttribute('data-confirm'));
		expect(/1 expired user/.test(conf), 'confirmation names the expired users: ' + conf);
		await p.click('button.nuBtnDel[type=submit]');
		await p.waitForSelector('#nuConfirm.show .js-confirm-ok');
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm.show .js-confirm-ok')]);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_access WHERE zzzzsys_access_id = 'e2e_ad_lvl'") === '0', 'level not deleted');
		expect(sql("SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE zzzzsys_user_id = 'e2e_ad_u1'") === '', 'expired user still points at the level');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_user_access WHERE access_id = 'e2e_ad_lvl'") === '0', 'extra assignment left behind');
		expect(sql("SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE zzzzsys_user_id = 'e2e_ad_u2'") === other, 'other user changed');
		// the user form asks for a level again (required field, empty)
		await open(p, 'index.php?p=user&id=e2e_ad_u1');
		expect(await p.locator('#sus_zzzzsys_access_id').evaluate(e => e.value === '' && e.required && e.validity.valueMissing), 'user form would save without an access level');
		s.result = { conf };
	} finally {
		del();
	}
};

// user code unique can be switched off in the rename dialog of the code (the owner: grouping codes such as a cost centre)
T.user_code_unique_switch = async (s) => {
	const p = s.ga;
	const ben = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	const carla = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_carla'");
	if (!ben || !carla) { s.skip = 'sample users missing'; return; }
	const oldBen = sql("SELECT COALESCE(sus_code, '') FROM zzzzsys_user WHERE zzzzsys_user_id = '" + ben + "'");
	const oldCarla = sql("SELECT COALESCE(sus_code, '') FROM zzzzsys_user WHERE zzzzsys_user_id = '" + carla + "'");
	const mark = () => p.evaluate(() => { const m = document.querySelector('label[for="sus_code"] .nuMarkUnique'); return m ? !m.hidden : null; });
	const saveCarla = async (code) => {
		await open(p, 'index.php?p=user&id=' + encodeURIComponent(carla));
		await p.fill('#sus_code', code);
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		return p.evaluate(() => !!document.querySelector('#sus_code.is-invalid'));
	};
	try {
		sql("UPDATE zzzzsys_user SET sus_code = 'E2E-CC1' WHERE zzzzsys_user_id = '" + ben + "'");
		await open(p, 'index.php?p=user&id=' + encodeURIComponent(carla));
		expect(await mark() === true, 'key mark not shown while unique');
		const tip = await p.evaluate(() => (document.querySelector('label[for="sus_code"] + .nuInfoTip') || {}).getAttribute?.('aria-label') || '');
		expect(/Staff number/.test(tip) && !/Cost centre/.test(tip), 'code tip: ' + tip);
		// the switch only for the code
		await p.click('.nuUserLblEdit[data-key="user_additional1"]'); await p.waitForSelector('#userLblDlg.show');
		expect(await p.locator('#userLblUniqueRow').isHidden(), 'switch shown for Additional 1');
		await p.click('#userLblDlg .modal-footer [data-bs-dismiss]'); await p.waitForSelector('#userLblDlg', { state: 'hidden' }); await p.waitForTimeout(300);
		await p.click('.nuUserLblEdit[data-key="user_code"]'); await p.waitForSelector('#userLblDlg.show');
		expect(await p.locator('#userLblUniqueRow').isVisible(), 'switch not shown for the code');
		expect(await p.isChecked('#userLblUnique'), 'switch not on by default');
		await p.click('#userLblDlg .modal-footer [data-bs-dismiss]'); await p.waitForSelector('#userLblDlg', { state: 'hidden' });
		expect(await saveCarla('E2E-CC1'), 'duplicate code accepted while unique');
		// switch off
		await open(p, 'index.php?p=user&id=' + encodeURIComponent(carla));
		await p.click('.nuUserLblEdit[data-key="user_code"]'); await p.waitForSelector('#userLblDlg.show');
		await p.uncheck('#userLblUnique'); await p.click('#userLblSave'); await p.waitForTimeout(1500);
		expect(await mark() === false, 'key mark still shown after switching off');
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'user_code_unique'") === '0', 'setting not stored');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'user_code_unique'") !== '0', 'not logged');
		expect(!(await saveCarla('E2E-CC1')), 'duplicate code refused while switched off');
		expect(sql("SELECT sus_code FROM zzzzsys_user WHERE zzzzsys_user_id = '" + carla + "'") === 'E2E-CC1', 'code not saved');
		const tip2 = await p.evaluate(() => (document.querySelector('label[for="sus_code"] + .nuInfoTip') || {}).getAttribute?.('aria-label') || '');
		expect(/same code/.test(tip2), 'tip does not say codes may repeat: ' + tip2);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'user_code_unique'");
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'user_code_unique'");
		sql("UPDATE zzzzsys_user SET sus_code = '" + oldBen + "' WHERE zzzzsys_user_id = '" + ben + "'");
		sql("UPDATE zzzzsys_user SET sus_code = '" + oldCarla + "' WHERE zzzzsys_user_id = '" + carla + "'");
	}
};

// long tap on an (i) on a touch screen opens its documentation (the tip's data-nu-doc, else the page's F1 chapter)
T.info_tip_long_tap = async (s) => {
	const ctx = await s.ga.context().browser().newContext({ locale: 'en-GB', hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
		await p.goto(BASE + 'index.php?p=user&id=' + encodeURIComponent(uid));
		const sel = 'label[for="sus_code"] + .nuInfoTip';
		expect(/#user-code$/.test(await p.getAttribute(sel, 'data-nu-doc') || ''), 'Code tip has no documentation link');
		const tap = async (css, ms) => p.evaluate(async ([c, wait]) => {
			const el = document.querySelector(c); el.scrollIntoView({ block: 'center' });
			const r = el.getBoundingClientRect(), t = new Touch({ identifier: 1, target: el, clientX: r.x + 4, clientY: r.y + 4 });
			el.dispatchEvent(new TouchEvent('touchstart', { touches: [t], targetTouches: [t], changedTouches: [t], bubbles: true, cancelable: true }));
			await new Promise(res => setTimeout(res, wait));
			el.dispatchEvent(new TouchEvent('touchend', { touches: [], targetTouches: [], changedTouches: [t], bubbles: true, cancelable: true }));
		}, [css, ms]);
		// short tap: no new tab
		let popup = null; const onPage = pg => { popup = pg; }; ctx.on('page', onPage);
		await tap(sel, 100); await p.waitForTimeout(800);
		expect(popup === null, 'short tap opened a tab');
		const [pg] = await Promise.all([ctx.waitForEvent('page', { timeout: 5000 }), tap(sel, 700)]);
		await pg.waitForLoadState();
		expect(/p=help/.test(pg.url()) && /#user-code$/.test(pg.url()), 'long tap opened: ' + pg.url());
		await pg.close();
		// a tip without its own link → the page's chapter (F1)
		const other = 'label[for="sus_expires_on"] + .nuInfoTip';
		if (await p.locator(other).count()) {
			const help = await p.getAttribute('body', 'data-nu-help');
			const [pg2] = await Promise.all([ctx.waitForEvent('page', { timeout: 5000 }), tap(other, 700)]);
			expect(help && pg2.url().endsWith(help.replace(/^.*index\.php/, 'index.php')), 'fallback opened: ' + pg2.url() + ' expected ' + help);
			await pg2.close();
		}
		// the (i) button of the toolbar (record information): long tap = the page's chapter, the panel stays closed
		const help = await p.getAttribute('body', 'data-nu-help');
		const [pg3] = await Promise.all([ctx.waitForEvent('page', { timeout: 5000 }), tap('#nuRecInfoBtn', 700)]);
		expect(help && pg3.url().endsWith(help.replace(/^.*index\.php/, 'index.php')), 'toolbar (i) opened: ' + pg3.url());
		await pg3.close();
		expect(!(await p.locator('#nuRecInfo.show').count()), 'record info panel opened by the long tap');
		ctx.off('page', onPage);
		// other pages: (i) tips with their own section; design mode = the chapter about designing a form
		await p.goto(BASE + 'index.php?p=email');
		expect(await p.locator('.nuInfoTip[data-nu-doc$="#email-settings"]').count() > 0, 'e-mail page tips without their section');
		await p.goto(BASE + 'index.php?p=update&tab=backups');
		expect(await p.locator('.nuInfoTip[data-nu-doc$="#update-backups"]').count() > 0, 'update page tips without their section');
		const f = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		if (f) {
			await p.goto(BASE + 'index.php?p=form&f=' + encodeURIComponent(f) + '&r=-1&design=1');
			expect(/#builder-work-p$/.test(await p.getAttribute('body', 'data-nu-help') || ''), 'design mode help: ' + await p.getAttribute('body', 'data-nu-help'));
		}
	} finally { await ctx.close(); }
};

// ?redirect: a link from another website (index.php?p=home&redirect) remembers the browser's Referer and returns there after the sign-in;
// without ?redirect, or with a Referer of nuBuilder Next itself, the sign-in ends on the page as before
T.login_redirect_referer = async () => {
	const ctx = await browser.newContext(); const p = await ctx.newPage();
	try {
		await p.route('https://intranet.example/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<title>intranet</title>back' }));
		const signIn = async (to) => {
			await p.fill('#username', GA[0]); await p.fill('#password', GA[1]);
			await Promise.all([p.waitForURL(to, { timeout: 10000 }).catch(() => null), p.click('form button[type=submit]:not([name=demo_user])')]);
		};
		await p.goto(BASE + 'index.php?p=home&redirect', { referer: 'https://intranet.example/portal/page?id=7' });
		expect(/p=login/.test(p.url()), 'sign-in page first: ' + p.url());
		await signIn(/intranet\.example/);
		expect(p.url() === 'https://intranet.example/portal/page?id=7', 'back to the referring page: ' + p.url());
		expect(/login_return/.test(sql("SELECT event FROM zzzzsys_nunext_log WHERE event = 'login_return' ORDER BY 1 DESC LIMIT 1")), 'logged');
		await ctx.clearCookies();
		await p.goto(BASE + 'index.php?p=home', { referer: 'https://intranet.example/portal/' });   // no ?redirect
		await signIn(/p=home/);
		expect(/p=home/.test(p.url()), 'without ?redirect: the start page: ' + p.url());
		await ctx.clearCookies();
		await p.goto(BASE + 'index.php?p=home&redirect', { referer: BASE + 'index.php?p=users' });   // our own page: ignored
		await signIn(/p=home/);
		expect(/p=home/.test(p.url()), 'own page as Referer: the start page: ' + p.url());
	} finally {
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_return'");
		await ctx.close();
	}
};

T.info_tip_tap_close = async (s) => {
	// touch screens: the first tap on an (i) opens its tip, a second tap on the same (i) closes it (it kept the focus and stayed open)
	const ctx = await s.ga.context().browser().newContext({ locale: 'en-GB', hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
		await p.goto(BASE + 'index.php?p=user&id=' + encodeURIComponent(uid));
		const tip = p.locator('label[for="sus_code"] + .nuInfoTip');
		await tip.scrollIntoViewIfNeeded();
		const shown = () => p.evaluate(() => !!document.querySelector('.tooltip.show'));
		await tip.tap(); await p.waitForTimeout(600);
		expect(await shown(), 'first tap did not open the tip');
		await tip.tap(); await p.waitForTimeout(600);
		expect(!(await shown()), 'second tap left the tip open');
		await tip.tap(); await p.waitForTimeout(600);
		expect(await shown(), 'third tap did not open it again');
		await p.locator('h1').first().tap(); await p.waitForTimeout(600);
		expect(!(await shown()), 'a tap elsewhere left the tip open');
	} finally { await ctx.close(); }
};

T.adminroles_access_col = async (s) => {
	// admin roles list: own column Access – a pill in every row (Full access with the causing rights as tooltip / Limited),
	// rights as a plain right-aligned count, sort and filter by access
	const p = s.ga;
	await open(p, 'index.php?p=adminroles');
	const m = await p.evaluate(() => [...document.querySelectorAll('table.nu-table tbody tr')].map(tr => {
		const a = tr.querySelector('.nuAdmAccess'); return { acc: a && a.textContent.trim(), full: !!(a && a.classList.contains('nuAdmAccessFull')), tip: a ? a.title : '',
			badgeInRights: !!tr.querySelector('.badge.text-bg-danger') }; }));
	expect(m.length > 0 && m.every(r => r.acc && !r.badgeInRights), 'every row has an access pill, no badge in Rights ' + JSON.stringify(m));
	expect(m.filter(r => r.full).every(r => /\n/.test(r.tip)), 'full access names its rights ' + JSON.stringify(m));
	const nFull = m.filter(r => r.full).length;
	await open(p, 'index.php?p=adminroles&full=yes');
	const n = await p.evaluate(() => document.querySelectorAll('table.nu-table tbody .nuAdmAccessFull').length);
	expect(n === nFull, 'filter full: ' + n + ' / ' + nFull);
};

T.user_access_ts = async (s) => {
	// user form: the focus ring of the access level (searchable select in an input group) lies above the open button; additional
	// access levels are searchable selects too (also rows added later)
	const id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name LIKE 'sample_%' LIMIT 1");
	if (!id) { s.skip = 'no sample users'; return; }
	const p = s.ga;
	await open(p, 'index.php?p=user&id=' + encodeURIComponent(id));
	await p.click('#sus_zzzzsys_access_id + .ts-wrapper .ts-control'); await p.waitForTimeout(200);
	const z = await p.evaluate(() => getComputedStyle(document.querySelector('#sus_zzzzsys_access_id + .ts-wrapper')).zIndex);
	expect(z === '5', 'focused select above the button, z-index ' + z);
	await p.keyboard.press('Escape');
	await p.click('#xaAdd'); await p.waitForTimeout(200);
	const ts = await p.evaluate(() => { const r = [...document.querySelectorAll('#xaRows .xaRow')].pop(); return { ts: !!r.querySelector('select').tomselect, groups: r.querySelectorAll('select optgroup').length }; });
	expect(ts.ts && ts.groups > 0, 'additional level row: searchable select ' + JSON.stringify(ts));
};

// access level → Form rights: chips + selects filter the grid; the column boxes tick the rows shown
T.access_rights_filter = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=accesslevel&id=sample_editor&tab=rights');
	const vis = () => p.$$eval('.js-rights-body tr[data-form]:not(.d-none)', (r) => r.length);
	const all = await vis();
	expect(all > 2, 'rows: ' + all);
	// one row without any right
	const first = p.locator('.js-rights-body tr[data-form]').first();
	for (const b of await first.locator('input[type=checkbox][data-col]').all()) if (await b.isChecked()) await b.uncheck();
	await p.click('.accFltChip[data-chip="none"]');
	expect(await vis() === 1 && /^1\D+\d+$/.test((await p.textContent('.js-rights-shown')).trim()), 'No rights ticked: ' + await vis());
	await p.click('.accFltChip[data-chip="save"]');
	expect(await vis() === all - 1, 'Can save: ' + await vis());
	// column box ticks only the rows shown
	await p.click('.accFltChip[data-chip="none"]');
	await p.check('.js-col-toggle[data-col="print"]');
	await p.click('.accFltChip[data-chip="print"]');
	expect(await vis() === 1, 'Print / view only after ticking Print in the filtered rows: ' + await vis());
	await p.selectOption('.accFltSel[data-flt="dm"]', '0');
	expect(await vis() === 0 && await p.isVisible('.accFltReset'), 'data mode filter');
	await p.click('.accFltReset');
	expect(await vis() === all && await p.isHidden('.js-rights-shown'), 'reset');
};

// setup wizard: Back · steps · Next in one bar that stays at the top; a done step is a button; Enter = Next; phones: a step menu
T.wizard_top_bar = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=setupwizard&step=security');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); await open(p, 'index.php?p=setupwizard&step=security'); }
	expect(await p.locator('#wizBar #wizBack').isVisible() && await p.locator('#wizBar #wizNext').isVisible(), 'Back / Next not in the bar');
	expect(await p.locator('#wizForm button[value="back"], #wizForm button[value="next"]:visible').count() === 0, 'buttons still at the end of the form');
	await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await p.waitForTimeout(200);
	const top = await p.$eval('#wizNext', (b) => b.getBoundingClientRect().top);
	expect(top >= 0 && top < 160, 'Next scrolled away: ' + top);
	// a done step jumps back, the answers of this step are kept
	await p.fill('#admin_email', 'e2e-wizard@example.com');
	await Promise.all([p.waitForNavigation(), p.click('.nuWizSteps button[value="go:basics"]')]);
	expect(/step=basics/.test(p.url()), 'jump to basics: ' + p.url());
	expect(await p.locator('.nuWizSteps button[value^="go:"]').count() === 0, 'a later step is a button on the first step');
	await open(p, 'index.php?p=setupwizard&step=security');
	expect(await p.inputValue('#admin_email') === 'e2e-wizard@example.com', 'answer of the step lost');
	// Enter in a field = Next (not Back)
	await Promise.all([p.waitForNavigation(), p.press('#admin_email', 'Enter')]);
	expect(/step=samples/.test(p.url()), 'Enter went to ' + p.url());
	// phones: the step menu, Back as an icon
	await p.setViewportSize({width: 390, height: 800});
	await open(p, 'index.php?p=setupwizard&step=security');
	expect(await p.locator('#wizStepBtn').isVisible() && !(await p.locator('.nuWizSteps').isVisible()), 'phone step menu');
	const w = await p.evaluate(() => document.documentElement.scrollWidth);
	expect(w <= 390, 'page wider than the phone: ' + w);
	await p.setViewportSize({width: 1400, height: 900});
	await p.evaluate(() => fetch('index.php?p=setupwizard&step=security'));
};

// The sign-in page and the other sign-in-style pages (forgot password …) show no program name and version.
T.login_no_version = async () => {
	const ctx = await browser.newContext(); const p = await ctx.newPage();
	try {
		const ver = (fs.readFileSync(path.join(__dirname, '..', '..', 'core/nuapp.php'), 'utf8').match(/NU_VERSION',\s*'([^']+)'/) || [])[1];
		expect(!!ver, 'version read');
		for (const u of ['index.php?p=login', 'index.php?p=forgot']) {
			await p.goto(BASE + u);
			const html = await p.content();
			expect(!html.includes('nuBuilder Next ' + ver), 'no "nuBuilder Next <version>" on ' + u);
			expect(!html.includes('>' + ver + '<'), 'no version text on ' + u);
		}
	} finally { await ctx.close(); }
};

// Logged-in users: the "Act as user" menu of a row floats above a short list (fixed positioning) instead of being cut off by
// the table's scroll box
T.sessions_impersonate_menu = async (s) => {
	const a = await login('sample_anna', UPW), a2 = await login('sample_anna', UPW);   // two sessions = a group row
	const p = s.ga;
	try {
		await p.setViewportSize({ width: 1300, height: 700 });
		await open(p, 'index.php?p=sessions&q=sample_anna');
		// several sessions of the same user (earlier tests in a full run) are grouped: the group row has the button too, its rows are hidden
		const btn = p.locator('#sessTable tbody tr .dropdown-toggle:has(.bi-person-fill-gear):visible').first();
		expect(await btn.count() === 1, 'Act as user button of sample_anna');
		await btn.scrollIntoViewIfNeeded(); await btn.click(); await p.waitForTimeout(300);
		const r = await p.evaluate(() => {
			const m = document.querySelector('#sessTable .dropdown-menu.show');
			if (!m) return null;
			const b = m.getBoundingClientRect(), last = m.querySelector('li:last-child button');
			const lb = last.getBoundingClientRect();
			const hit = document.elementFromPoint(lb.left + lb.width / 2, lb.top + lb.height / 2);
			return { top: b.top, bottom: b.bottom, h: innerHeight, pos: getComputedStyle(m).position, hit: !!hit && m.contains(hit) };
		});
		expect(r && r.pos === 'fixed' && r.top >= 0 && r.bottom <= r.h && r.hit, 'menu fully visible: ' + JSON.stringify(r));
		await p.keyboard.press('Escape');
	} finally { await a.context().close(); await a2.context().close(); await p.setViewportSize({ width: 1300, height: 900 }); }
};

T.access_start_col = async (s) => {
	// Access levels list: column Start page = Tiles chip + source (own / default / automatic) or Form chip + the form's name; filter Start page
	const p = s.ga;
	const lv = sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE COALESCE(sal_zzzzsys_form_id, '') <> '' ORDER BY zzzzsys_access_id LIMIT 1");
	if (!lv) { s.skip = 'no access level with a Home form'; return; }
	const old = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'");
	try {
		sql(`REPLACE INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('start_page', '{"levels":{"${lv}":"form"}}')`);
		await open(p, 'index.php?p=access');
		const cell = await p.evaluate((id) => {
			const rows = [...document.querySelectorAll('tr[data-href]')];
			const r = rows.find(x => (x.getAttribute('data-href') || '').includes('id=' + encodeURIComponent(id)));
			const c = r && r.querySelector('.nuAccStart');
			return {rows: rows.length, form: !!(c && c.querySelector('.nuAccStartForm')),
				tiles: rows.filter(x => x.querySelector('.nuAccStartTiles')).length, src: !!document.querySelector('.nuAccStartTiles + .nuAccStartSrc, .nuAccStartSrc')};
		}, lv);
		expect(cell.form, 'level in form mode shows the Form chip: ' + JSON.stringify(cell));
		expect(cell.tiles === cell.rows - 1 && cell.src, 'other levels show Tiles + source: ' + JSON.stringify(cell));
		await open(p, 'index.php?p=access&start=form');
		const nForm = await p.locator('tr[data-href]').count();
		expect(nForm === 1, 'filter form: ' + nForm);
		await open(p, 'index.php?p=access&start=tiles');
		const t = await p.evaluate(() => ({n: document.querySelectorAll('tr[data-href]').length, f: [...document.querySelectorAll('tr[data-href]')].filter(x => x.querySelector('.nuAccStartForm')).length}));
		expect(t.n === cell.rows - 1 && t.f === 0, 'filter tiles: ' + JSON.stringify(t));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (old) sql(`REPLACE INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('start_page', '${old.replace(/'/g, "''")}')`);
		else sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'");
	}
};
