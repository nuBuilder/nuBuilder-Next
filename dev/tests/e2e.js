/*
 * nuBuilder Next – end-to-end browser tests (0.8.361) against a running TEST installation with the sample application:
 *     NODE_PATH=$(npm root -g) BASE=http://127.0.0.1:8099/nunext/ DB="-unu -pnu nutest" PKG=../nunext.zip node dev/tests/e2e.js
 * Env: BASE (installation URL), DB (mysql / mysqldump arguments of its database – the suite saves the whole database first and puts it
 * back at the end, also when a test fails), ADMIN_USER / ADMIN_PW (globeadmin, default globeadmin / nu), USER_PW (password of the sample
 * users, default Test1234! – set by the suite for every sample user), PKG (a package for the update page check, optional),
 * ONLY (comma separated test names), KEEP=1 (do not restore the database), SITE (folder of the test installation: demo mode test).
 * Covers: login, form save / validation / change / clone / delete, sub-form rows, rights of access levels, audit trail and restore,
 * notifications, list views, start page favourites and list in 2 columns, CSV import, update package check, the self-test (developer mode), CSRF and the
 * include guards. Exit code 1 when a test fails. Never run it against a database with real data.
 */
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8099/nunext/';
const DB = process.env.DB || '-unu -pnu nutest';
const GA = [process.env.ADMIN_USER || 'globeadmin', process.env.ADMIN_PW || 'nu'];
const UPW = process.env.USER_PW || 'Test1234!';
const SITE = process.env.SITE || '';      // folder of the test installation (its nuconfig.php) – for the demo mode test
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const sql = q => execSync('mysql -N ' + DB, { input: q }).toString().trim();
const results = [];
let browser;

async function login(user, pw, opt = {}) {
	const ctx = await browser.newContext(Object.assign({ locale: 'en-GB', viewport: { width: 1300, height: 900 } }, opt));
	const p = await ctx.newPage();
	p.errors = [];
	p.on('pageerror', e => p.errors.push(e.message));
	p.on('dialog', d => d.accept());
	await p.goto(BASE + 'index.php?p=login');
	await p.fill('#username', user);
	await p.fill('#password', pw);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
	for (let i = 0; i < 3 && await p.locator('.nuBellConfirm.show .btn-primary').count(); i++) { await p.click('.nuBellConfirm.show .btn-primary'); await p.waitForTimeout(500); }
	return p;
}
async function open(p, url) { const r = await p.goto(BASE + url); await p.waitForLoadState('networkidle'); return r; }
async function confirmDelete(p) {
	await p.waitForTimeout(400);
	if (await p.locator('.btn-red').count()) await p.click('.btn-red');
	await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
}
async function setField(p, sel, v) { await p.fill(sel, v); await p.locator(sel).dispatchEvent('change'); }
async function save(p) { await p.click('#nuSaveButton'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800); }
function expect(cond, msg) { if (!cond) throw new Error(msg); }

const T = {};      // name → async fn; run in this order

T.login_refused = async () => {
	const ctx = await browser.newContext(); const p = await ctx.newPage();
	await p.goto(BASE + 'index.php?p=login'); await p.fill('#username', GA[0]); await p.fill('#password', 'wrong-' + Date.now());
	await Promise.all([p.waitForLoadState('load'), p.click('form button[type=submit]:not([name=demo_user])')]);
	expect(/p=login/.test(p.url()) && await p.locator('.alert-danger, .alert-warning').count() > 0, 'wrong password not refused');
	sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
	await ctx.close();
};

T.form_insert = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
	await setField(p, '#cus_code', 'E2E1'); await setField(p, '#cus_name', 'E2E Customer');
	expect(await p.locator('#nuSaveButton.nuSaveEdited').count() === 1, 'Save button not red after a change');
	await save(p);
	s.cust = sql("SELECT sample_customer_id FROM sample_customer WHERE cus_code = 'E2E1'");
	expect(s.cust, 'record not saved');
};

T.form_required = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
	await setField(p, '#cus_code', 'E2E2'); await setField(p, '#cus_name', '');
	await p.click('#nuSaveButton'); await p.waitForTimeout(1200);
	expect(sql("SELECT COUNT(*) FROM sample_customer WHERE cus_code = 'E2E2'") === '0', 'saved without the required name');
	expect(await p.locator('.is-invalid, .nuValidationError, .jconfirm, .nuMessage, .alert-danger').count() > 0, 'no error shown');
};

T.form_unique = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
	await setField(p, '#cus_code', 'E2E1'); await setField(p, '#cus_name', 'Duplicate');
	await p.click('#nuSaveButton'); await p.waitForTimeout(1200);
	expect(sql("SELECT COUNT(*) FROM sample_customer WHERE cus_code = 'E2E1'") === '1', 'duplicate code saved');
};

T.form_update = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + s.cust);
	await setField(p, '#cus_name', 'E2E Customer changed');
	await save(p);
	expect(sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + s.cust + "'") === 'E2E Customer changed', 'change not saved');
};

T.form_clone = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + s.cust);
	await p.evaluate(() => window.nuCloneAction()); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
	await setField(p, '#cus_code', 'E2E3');
	await save(p);
	expect(sql("SELECT cus_name FROM sample_customer WHERE cus_code = 'E2E3'") === 'E2E Customer changed', 'clone not saved with the copied values');
};

T.subform_change = async (s) => {
	const p = s.ga;
	const item = sql("SELECT sample_order_item_id, ori_sample_order_id, ori_qty FROM sample_order_item ORDER BY sample_order_item_id LIMIT 1").split('\t');
	s.item = item;
	await open(p, 'index.php?p=form&f=sampleorder&r=' + item[1]);
	const q = p.locator('input[data-nu-field="ori_qty"]').first();
	await q.fill('9'); await q.dispatchEvent('change');
	await save(p);
	expect(Number(sql("SELECT ori_qty FROM sample_order_item WHERE sample_order_item_id = '" + item[0] + "'")) === 9, 'sub-form row not changed');
};

T.form_delete = async (s) => {
	const p = s.ga;
	const id = sql("SELECT sample_customer_id FROM sample_customer WHERE cus_code = 'E2E3'");
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + id);
	await p.click('#nuDeleteButton'); await confirmDelete(p);
	expect(sql("SELECT COUNT(*) FROM sample_customer WHERE cus_code = 'E2E3'") === '0', 'record not deleted');
};

T.browse_search = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer&q=E2E');
	const n = await p.locator('table.nuBrowseTable tbody tr, .nuBrowseTable tbody tr').count();
	expect(n >= 1, 'search found nothing');
};

// ------------------------------------------------------------------ nuBuilder hooks, lookups, permission items, events (0.8.365)
const msgText = p => p.evaluate(() => { const m = document.getElementById('nuMessageDiv'); return m ? m.textContent : ''; });

T.hook_before_save = async (s) => {                       // sampleall_BS: nuDisplayError stops the save
	const p = s.ga, id = sql('SELECT sample_all_id FROM sample_all ORDER BY 1 LIMIT 1'), before = sql("SELECT all_text FROM sample_all WHERE sample_all_id = '" + id + "'");
	await open(p, 'index.php?p=form&f=sampleall&r=' + id);
	await setField(p, '#all_text', 'x'.repeat(101));
	await p.click('#nuSaveButton'); await p.waitForTimeout(1500);
	expect(sql("SELECT all_text FROM sample_all WHERE sample_all_id = '" + id + "'") === before, 'saved although Before Save refused');
	expect(/at most 100/.test(await msgText(p) + await p.textContent('body')), 'the Before Save message is not shown');
};

T.hook_before_delete = async (s) => {                     // samplecustomer_BD: a customer with orders is not deleted
	const p = s.ga, id = sql('SELECT ord_sample_customer_id FROM sample_order GROUP BY ord_sample_customer_id ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + id);
	await p.click('#nuDeleteButton'); await confirmDelete(p);
	expect(sql("SELECT COUNT(*) FROM sample_customer WHERE sample_customer_id = '" + id + "'") === '1', 'deleted although Before Delete refused');
	expect(/cannot be deleted/.test(await msgText(p) + await p.textContent('body')), 'the Before Delete message is not shown');
};

T.lookup_after_save = async (s) => {                     // lookup by code + sampleorilookup_AB (#ROW#) + sampleorder_AS (order total)
	const p = s.ga, order = sql('SELECT ori_sample_order_id FROM sample_order_item GROUP BY ori_sample_order_id ORDER BY 1 LIMIT 1');
	const prod = sql("SELECT sample_product_id, pro_code, pro_price FROM sample_product ORDER BY pro_code DESC LIMIT 1").split('\t');
	await open(p, 'index.php?p=form&f=sampleorder&r=' + order);
	const code = p.locator('#sample_items000ori_sample_product_idcode');
	await code.fill(prod[1]); await code.dispatchEvent('change'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
	expect(await p.inputValue('#sample_items000ori_sample_product_id') === prod[0], 'the lookup did not find code ' + prod[1]);
	expect(Number(await p.inputValue('#sample_items000ori_price').then(v => v.replace(/[^\d.-]/g, ''))) === Number(prod[2]), 'After Browse did not set the price (#ROW#)');
	await save(p);
	const want = sql("SELECT ROUND(SUM(ori_qty * ori_price), 2) FROM sample_order_item WHERE ori_sample_order_id = '" + order + "'");
	expect(Number(sql("SELECT ord_total FROM sample_order WHERE sample_order_id = '" + order + "'")) === Number(want), 'After Save did not update the total');
};

T.hook_before_edit = async (s) => {                      // sampleorder_BE: nuAddJavascript on a new record
	const p = s.ga;
	await open(p, 'index.php?p=form&f=sampleorder&r=-1'); await p.waitForTimeout(600);
	expect(/New order/.test(await msgText(p)), 'Before Edit JavaScript not run');
};

T.js_event = async (s) => {                              // an object's onchange event (nuToast + nuGetText)
	const p = s.ga, id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + id);
	await p.evaluate(() => { const e = document.getElementById('cus_country'); e.value = e.options[e.options.length - 1].value; e.dispatchEvent(new Event('change', { bubbles: true })); });
	await p.waitForTimeout(500);
	expect(/Country:/.test(await p.evaluate(() => (document.getElementById('nuToasts') || {}).textContent || '')), 'onchange event not run');
};

T.hidden_procedure = async (s) => {                      // nuRunPHPHidden → nuAddJavascript back in the form
	const p = s.ga, id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + id);
	await p.evaluate(() => nuRunPHPHidden('sample_hidden')); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
	expect(/Hidden procedure finished/.test(await msgText(p)), 'hidden procedure: no message');
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

T.rights_editor = async (s) => {
	const p = await login('sample_ben', UPW);
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + s.cust);
	expect(await p.locator('#nuSaveButton').count() === 1, 'editor has no Save');
	await p.context().close();
};

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

T.notifications = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=notifications&id=new');
	await p.fill('#ntf_title', 'E2E notification');
	await p.locator('#aud_users').locator('xpath=..').locator('.ts-control input').fill('sample_anna');
	await p.locator('.ts-dropdown .option[data-value="sample_anna"]').click(); await p.keyboard.press('Escape');
	await p.waitForTimeout(600);
	expect(/1/.test(await p.textContent('#ntfCount')), 'recipient count wrong');
	await p.click('#ntfPublish'); await p.waitForLoadState('networkidle');
	const a = await login('sample_anna', UPW);
	await a.waitForTimeout(800);
	expect(!(await a.locator('.nuBellBadge').isHidden()), 'no red badge for the recipient');
	await a.click('#nuBellBtn'); await a.waitForTimeout(700);
	expect(await a.locator('.nuBellItem', { hasText: 'E2E notification' }).count() === 1, 'not in the panel');
	await a.locator('.nuBellAll').click(); await a.waitForTimeout(700);
	expect(await a.locator('.nuBellBadge').isHidden(), 'badge still shown after "mark all as read"');
	await a.context().close();
	const b = await login('sample_ben', UPW);
	await open(b, 'index.php?p=notifications');
	expect(await b.locator('.nuNtfItem', { hasText: 'E2E notification' }).count() === 0, 'other user got it');
	await b.context().close();
};

T.list_views = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=users&view=all');
	const all = await p.locator('table.nu-table thead th').count();
	await open(p, 'index.php?p=users&view=compact');
	const compact = await p.locator('table.nu-table thead th').count();
	expect(all > compact && compact > 0, 'views show the same columns (' + all + '/' + compact + ')');
};

T.home_favourites = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home&tiles=1');
	await p.click('#nuHomeCustomize'); await p.waitForTimeout(400);
	await p.locator('.nuHeItem[data-key="users"] .nuHeTools button').filter({ has: p.locator('.bi-star') }).first().click();
	await p.click('#nuHomeSave'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
	await p.click('#nuHomeFavOnly'); await p.waitForTimeout(400);
	const shown = await p.locator('[data-nu-tile]').evaluateAll(a => a.filter(x => x.offsetParent !== null).map(x => x.getAttribute('data-nu-tile')));
	expect(shown.length === 1 && shown[0] === 'users', 'favourites only shows ' + shown.join(','));
	await p.click('#nuHomeFavOnly');
};

T.home_list2 = async (s) => {                      // 0.8.363: tiles as a list in two columns
	const p = s.ga;
	await open(p, 'index.php?p=home&tiles=1');
	const before = await p.evaluate(() => (document.querySelector('[data-nu-size][aria-current]') || {}).dataset?.nuSize || 'large');
	await Promise.all([p.waitForNavigation(), p.evaluate(() => document.querySelector('[data-nu-size="list2"]').click())]);
	await p.waitForLoadState('networkidle');
	const pos = await p.evaluate(() => [...document.querySelector('.nuHomeList2').children].slice(0, 2).map(e => { const r = e.getBoundingClientRect(); return [Math.round(r.top), Math.round(r.left)]; }));
	expect(pos.length === 2 && pos[0][0] === pos[1][0] && pos[1][1] > pos[0][1], 'two columns: ' + JSON.stringify(pos));
	await p.click('#nuHomeCustomize'); await p.waitForTimeout(400);
	expect(await p.locator('.nuHeDrop.nuHomeList2').count() > 0 && await p.inputValue('#nuHomeSize') === 'list2', 'Customise keeps the 2 columns');
	await open(p, 'index.php?p=home&tiles=1');
	if (before !== 'list2') await Promise.all([p.waitForNavigation(), p.evaluate(b => document.querySelector('[data-nu-size="' + b + '"]').click(), before)]);
};

// ------------------------------------------------------------------ admin roles, demo mode, login links, users, branding, dashboards (0.8.365)
const csrfPost = (p, url, fields) => p.evaluate(async ([u, f]) => {
	const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value);
	for (const k in f) fd.append(k, f[k]);
	const r = await fetch(u, { method: 'POST', body: fd, redirect: 'manual' }); return r.status;
}, [url, fields]);

T.admin_role = async () => {                              // a role with "Security check: view" – nothing more
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	sql("INSERT INTO zzzzsys_nunext_admin_role (zzzzsys_nunext_admin_role_id, code, name, rights, sort_order) VALUES ('e2e_role', 'e2e', 'E2E role', '{\"securitycheck\":\"view\"}', 99)");
	sql("INSERT INTO zzzzsys_nunext_admin_user (zzzzsys_nunext_admin_user_id, role_id) VALUES ('" + uid + "', 'e2e_role')");
	try {
		const p = await login('sample_anna', UPW);
		expect((await open(p, 'index.php?p=securitycheck')).status() === 200, 'the role does not open the Security check');
		expect(await p.locator('form[action*="securitycheck"] [data-nu-save], #secSave').count() === 0 || /readonly|disabled/.test(await p.content()), 'view right shows a Save');
		expect(await csrfPost(p, 'index.php?p=securitycheck', { action: 'save' }) === 403, 'view right may change the Security check');
		for (const r of ['settings', 'update', 'configfile', 'users'].slice(0, 3)) expect((await open(p, 'index.php?p=' + r)).status() === 403, 'the role opens ' + r);
		await p.context().close();
	} finally { sql("DELETE FROM zzzzsys_nunext_admin_user WHERE role_id = 'e2e_role'; DELETE FROM zzzzsys_nunext_admin_role WHERE zzzzsys_nunext_admin_role_id = 'e2e_role'"); }
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

T.branding = async (s) => {                              // environment label of the branding page in every page title
	const p = s.ga;
	await open(p, 'index.php?p=branding');
	await p.fill('input[name=env_label]', 'E2E-ENV');
	await Promise.all([p.waitForLoadState('networkidle'), p.click('#brandSave')]);
	await open(p, 'index.php?p=home&tiles=1');
	expect(/E2E-ENV/.test(await p.title() + await p.textContent('body')), 'the environment label is not shown');
	await open(p, 'index.php?p=branding');
	await p.fill('input[name=env_label]', '');
	await Promise.all([p.waitForLoadState('networkidle'), p.click('#brandSave')]);
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

T.login_limit = async () => {                             // 5 wrong passwords for a name from one address: the right one is refused too
	try {
		const ctx = await browser.newContext(); const p = await ctx.newPage();
		for (let i = 0; i < 5; i++) { await p.goto(BASE + 'index.php?p=login'); await p.fill('#username', 'sample_eva'); await p.fill('#password', 'wrong' + i);
			await Promise.all([p.waitForLoadState('load'), p.click('form button[type=submit]:not([name=demo_user])')]); }
		await p.goto(BASE + 'index.php?p=login'); await p.fill('#username', 'sample_eva'); await p.fill('#password', UPW);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
		expect(/p=login/.test(p.url()), 'logged in after 5 failed attempts');
		await ctx.close();
	} finally { sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'"); }
};

T.subform_index = async () => {                         // 0.8.366: link columns of sub-forms have an index (sample application)
	const n = sql("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sample_order_item' AND column_name = 'ori_sample_order_id' AND seq_in_index = 1");
	expect(Number(n) > 0, 'sample_order_item.ori_sample_order_id has no index (Sample data → Refresh adds it)');
};

T.audit_hidden_field = async (s) => {                    // 0.8.367: the history shows only fields the user sees; no restore of others
	const p = s.ga, id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	if (sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'samplecustomer' AND sob_all_id = 'cus_credit'") !== '1') { s.skip = 'no field cus_credit'; return; }
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + id);
	await p.evaluate(() => { const e = document.getElementById('cus_credit'); e.value = '4321'; e.dispatchEvent(new Event('change', { bubbles: true })); });   // on tab 2
	await setField(p, '#cus_phone', '+41 00 000 00 01');
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

T.notification_link = async () => {                      // 0.8.367: a javascript: link written past the form never reaches the bell
	sql("INSERT INTO zzzzsys_nunext_notification (zzzzsys_nunext_notification_id, title, body, level, link, audience, mode, status, created_at, created_by) VALUES "
		+ "('e2e_ntf_js', 'E2E link', 'x', 'info', 'javascript:alert(1)', '{\"all\":true}', 'live', 'published', NOW(), 'globeadmin')");
	const u = await login('sample_anna', UPW);
	const j = await u.evaluate(() => fetch('index.php?p=notifyapi').then(r => r.json()));
	const it = (j.items || []).find(x => x.id === 'e2e_ntf_js');
	await u.context().close();
	sql("DELETE FROM zzzzsys_nunext_notification WHERE zzzzsys_nunext_notification_id = 'e2e_ntf_js'");
	expect(it, 'the notification is not in the bell');
	expect(it.link === '', 'the bell sends the javascript: link: ' + it.link);
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

T.users_more_menu = async () => {                        // 0.8.371: users list on phones – Import and Print in a ⋮ menu right of Add
	const m = await login(GA[0], GA[1], { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
	await open(m, 'index.php?p=users');
	expect(await m.locator('#nuMoreBtn').isVisible(), 'no ⋮ button on a phone');
	expect(!await m.locator('#nuUsersImport').isVisible() && !await m.locator('#nuListPrint').isVisible(), 'Import / Print still shown as buttons on a phone');
	const order = await m.evaluate(() => { const a = document.querySelector('.nuAddBtn'), b = document.querySelector('#nuMoreBtn').closest('.dropdown'); return a.nextElementSibling === b; });
	expect(order, 'the ⋮ button is not right of Add');
	await m.click('#nuMoreBtn'); await m.waitForTimeout(300);
	const items = await m.locator('.nuMoreMenu .dropdown-item:visible').allInnerTexts();
	expect(items.length === 2 && /Import/.test(items[0]) && /Print/.test(items[1]), 'menu items: ' + items.join(' | '));
	await m.context().close();
	const d = await login(GA[0], GA[1]);
	await open(d, 'index.php?p=users');
	expect(await d.locator('#nuUsersImport').isVisible() && await d.locator('#nuListPrint').isVisible() && !await d.locator('#nuMoreBtn').isVisible(), 'desktop: buttons changed');
	await d.context().close();
};

T.update_folder = async (s) => {                         // 0.8.372: packages in <site>/_update/ – listed, only signed ones checked
	if (!SITE) { s.skip = 'SITE not set'; return; }
	const dir = path.join(SITE, '_update');
	fs.mkdirSync(dir, { recursive: true });
	const made = [];
	const py = (code) => execSync('python3 -', { input: code });
	py("import zipfile\nwith zipfile.ZipFile(" + JSON.stringify(path.join(dir, 'e2e-other.zip')) + ",'w') as z: z.writestr('readme.txt','x')\n"); made.push('e2e-other.zip');
	if (process.env.PKG && fs.existsSync(process.env.PKG)) {
		fs.copyFileSync(process.env.PKG, path.join(dir, 'e2e-signed.zip')); made.push('e2e-signed.zip');
		py("import zipfile, json\nsrc=" + JSON.stringify(process.env.PKG) + "\ndst=" + JSON.stringify(path.join(dir, 'e2e-unsigned.zip')) + "\nwith zipfile.ZipFile(src) as zi, zipfile.ZipFile(dst,'w',zipfile.ZIP_DEFLATED) as zo:\n  for it in zi.infolist():\n    d=zi.read(it.filename)\n    if it.filename.endswith('nunext-manifest.json'):\n      m=json.loads(d); m['signature']=''; d=json.dumps(m).encode()\n    zo.writestr(it, d)\n");
		made.push('e2e-unsigned.zip');
	}
	try {
		const p = s.ga;
		await open(p, 'index.php?p=update');
		const rows = await p.evaluate(() => Object.fromEntries([...document.querySelectorAll('#updFolder label[data-file]')].map(l => [l.dataset.file, l.querySelector('input').disabled])));
		expect(rows['e2e-other.zip'] === true, 'a zip that is no package is not refused: ' + JSON.stringify(rows));
		for (const bad of ['e2e-other.zip', '../index.php', 'e2e-unsigned.zip']) {
			const st = await csrfPost(p, 'index.php?p=update', { action: 'folder_check', name: bad });
			await open(p, 'index.php?p=update');
			expect(!/check=/.test(p.url()), 'folder_check accepted ' + bad + ' (' + st + ')');
		}
		if (made.includes('e2e-signed.zip')) {
			expect(rows['e2e-signed.zip'] === false && rows['e2e-unsigned.zip'] === true, 'signed / unsigned: ' + JSON.stringify(rows));
			await p.check('#updFolder input[value="e2e-signed.zip"]');
			await Promise.all([p.waitForNavigation(), p.click('#updFolderCheck')]); await p.waitForLoadState('networkidle');
			expect(/check=/.test(p.url()) && /e2e-signed\.zip/.test(await p.locator('#updFromFolder').textContent()), 'no check page for the folder package');
			await Promise.all([p.waitForNavigation(), p.click('#updCancel')]);
			expect(fs.existsSync(path.join(dir, 'e2e-signed.zip')), 'Cancel removed the package from the folder');
		}
	} finally { for (const f of made) try { fs.unlinkSync(path.join(dir, f)); } catch (e) { /* gone */ } }
};

T.github_update = async (s) => {                        // 0.8.374: newest release on GitHub – badge, download button, settings (offline; GITHUB=1 asks GitHub)
	const p = s.ga;
	const put = (o) => sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update';" + (o ? " INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value, updated_at, updated_by) VALUES ('github_update', '" + JSON.stringify(o).replace(/'/g, "''") + "', NOW(), 'e2e')" : ''));
	try {
		put({ repo: 'nuBuilder/nuBuilder-Next', pre: true, auto: false, checked_at: Math.floor(Date.now() / 1000), error: '',
			latest: { version: '9.9.9', tag: 'v9.9.9', name: 'e2e', url: 'https://github.com/nuBuilder/nuBuilder-Next/releases/download/v9.9.9/nunext.zip', size: 1, published: '2026-10-22T00:00:00Z', pre: true, page: 'https://github.com/nuBuilder/nuBuilder-Next/releases/tag/v9.9.9' } });
		await open(p, 'index.php?p=home');
		expect(/New: 9\.9\.9/.test(await p.content()), 'no "New" badge on the Update tile');
		await open(p, 'index.php?p=update');
		expect(/9\.9\.9/.test(await p.locator('#updGitHubLatest').textContent()) && await p.locator('#updGitHubDownload').count() === 1, 'newer release not offered');
		await csrfPost(p, 'index.php?p=update', { action: 'github_settings', repo: 'a/../b', pre: '1' });
		expect(/9\.9\.9/.test(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'")), 'an invalid repository was saved');
		await csrfPost(p, 'index.php?p=update', { action: 'github_settings', repo: 'someone/fork', pre: '1' });
		const st = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'"));
		expect(st.repo === 'someone/fork' && st.latest === null && st.auto === false, 'settings: ' + JSON.stringify(st));
		await open(p, 'index.php?p=update');
		expect(await p.locator('#updGitHubDownload').count() === 0 && /someone\/fork/.test(await p.locator('#updGitHubRepo').textContent()), 'changed repository still offers the old release');
		if (process.env.GITHUB === '1') {
			put(null);
			await open(p, 'index.php?p=update');
			await Promise.all([p.waitForNavigation(), p.click('#updGitHubCheck')]); await p.waitForLoadState('networkidle');
			expect(await p.locator('#updGitHubError').count() === 0 && await p.locator('#updGitHubLatest').count() === 1, 'GitHub check failed');
		}
	} finally { put(null); }
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

T.update_check = async (s) => {
	const pkg = process.env.PKG;
	if (!pkg || !fs.existsSync(pkg)) { s.skip = 'PKG not set'; return; }
	const p = s.ga;
	await open(p, 'index.php?p=update');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update'); }
	await p.setInputFiles('#updPackage', pkg);
	await p.waitForTimeout(300);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('#updUpload')]);
	await p.waitForTimeout(800);
	expect(await p.locator('#updSignature .bi-patch-check-fill').count() === 1, 'package not shown as signed: ' + ((await p.textContent('#updSignature').catch(() => '')) || '').trim());
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

T.csrf = async (s) => {
	const p = s.ga;
	const st = await p.evaluate(async () => (await fetch('index.php?p=notifications', { method: 'POST', body: new URLSearchParams({ action: 'readall' }) })).status);
	expect(st === 400, 'POST without CSRF token answered ' + st);
};

T.include_guards = async (s) => {
	const p = s.ga;
	for (const f of ['core/nuapp.php', 'core/nunotify.php', 'pages/nuhome.php', 'lang/en.php']) {
		const r = await p.goto(BASE + f);
		const body = await p.textContent('body').catch(() => '');
		expect(r.status() >= 400 || body.trim() === '', f + ' gives output');
	}
};

T.js_errors = async (s) => {
	expect(s.ga.errors.length === 0, s.ga.errors.slice(0, 3).join(' | '));
};

T.logout = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home&tiles=1');
	await Promise.all([p.waitForURL(/p=login/), p.evaluate(() => { const f = document.createElement('form'); f.method = 'post'; f.action = 'index.php?p=logout';
		const i = document.createElement('input'); i.name = '_csrf'; i.value = document.querySelector('input[name=_csrf]').value; f.appendChild(i); document.body.appendChild(f); f.submit(); })]);
	await open(p, 'index.php?p=home');
	expect(/p=login/.test(p.url()), 'still signed in after logout');
};

(async () => {
	const dump = path.join(os.tmpdir(), 'nunext-e2e-' + process.pid + '.sql');
	const tablesBefore = sql('SHOW TABLES').split('\n');
	execSync('mysqldump ' + DB + ' > ' + dump);
	browser = await chromium.launch();
	const s = {};
	try {
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		// known password for every sample user (the database is put back at the end)
		const hash = execSync('php -r ' + JSON.stringify('echo password_hash(' + JSON.stringify(UPW) + ', PASSWORD_BCRYPT);')).toString().trim();
		sql("UPDATE zzzzsys_user SET sus_login_password = '" + hash + "', sus_change_password = '0' WHERE sus_login_name LIKE 'sample\\_%'");
		s.ga = await login(GA[0], GA[1]);
		for (const [name, fn] of Object.entries(T)) {
			if (ONLY.length && !ONLY.includes(name)) continue;
			const t = Date.now();
			s.skip = '';
			try { await fn(s); results.push([name, s.skip ? 'skip' : 'ok', s.skip]); }
			catch (e) { results.push([name, 'FAIL', e.message.split('\n')[0]]); }
			const r = results[results.length - 1];
			console.log((r[1] === 'ok' ? 'ok   ' : r[1] === 'skip' ? 'skip ' : 'FAIL ') + name.padEnd(18) + String(Date.now() - t).padStart(6) + ' ms  ' + (r[2] || ''));
		}
	} finally {
		await browser.close();
		if (process.env.KEEP !== '1') {
			const now = sql('SHOW TABLES').split('\n').filter(t => t && !tablesBefore.includes(t));
			if (now.length) sql('DROP TABLE ' + now.join(', '));
			execSync('mysql ' + DB + ' < ' + dump);
		}
		fs.unlinkSync(dump);
	}
	const failed = results.filter(r => r[1] === 'FAIL').length;
	console.log(failed ? failed + ' of ' + results.length + ' tests failed' : 'all ' + results.length + ' tests passed (' + results.filter(r => r[1] === 'skip').length + ' skipped)');
	process.exit(failed ? 1 : 0);
})();
