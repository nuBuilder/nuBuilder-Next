/*
 * nuBuilder Next – end-to-end browser tests (0.8.361) against a running TEST installation with the sample application:
 *     NODE_PATH=$(npm root -g) BASE=http://127.0.0.1:8099/nunext/ DB="-unu -pnu nutest" PKG=../nunext.zip node dev/tests/e2e.js
 * Env: BASE (installation URL), DB (mysql / mysqldump arguments of its database – the suite saves the whole database first and puts it
 * back at the end, also when a test fails), ADMIN_USER / ADMIN_PW (globeadmin, default globeadmin / nu), USER_PW (password of the sample
 * users, default Test1234! – set by the suite for every sample user), PKG (a package for the update page check, optional),
 * ONLY (comma separated test names), KEEP=1 (do not restore the database), SITE (folder of the test installation: demo mode test).
 * Covers: login, form save / validation / change / clone / delete, sub-form rows, rights of access levels, audit trail and restore,
 * nuBuilder 4.9 compatibility (nub49_app, nub49_js), notifications, list views, start page favourites and list in 2 columns, CSV import, update package check, the self-test (developer mode), CSRF and the
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

// form drafts (0.8.386): start, change, preview (read-only, banner), other users keep live, compare, publish (version, history),
// restore, discard, conflict (live changed meanwhile → overwrite only with the typed code), form status Draft
T.form_drafts = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const meta = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_event', 'zzzzsys_browse', 'zzzzsys_php', 'zzzzsys_nunext_form_history'];
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-drafts.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	const label = () => sql("SELECT sob_all_label FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '" + F + "' AND sob_all_id = 'cus_name'");
	const cust = s.cust || sql('SELECT MIN(sample_customer_id) FROM sample_customer');
	const before = label();
	try {
		await open(p, 'index.php?p=builder&f=' + F);
		const api = (body) => p.evaluate(async (bd) => {
			const csrf = JSON.parse(document.getElementById('nuBuilderData').textContent).csrf;
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(bd) });
			return Object.assign({ status: r.status }, await r.json());
		}, Object.assign({ form_id: F }, body));
		let r = await api({ action: 'draft.start', note: 'e2e' });
		expect(r.ok && r.draft && r.draft.rev === 0, 'start: ' + JSON.stringify(r).slice(0, 200));
		const D = r.draft, obj = r.objects.find(o => o.sob_all_id === 'cus_name');
		r = await api({ action: 'object.save', draft_id: D.id, rev: 0, object_id: obj.zzzzsys_object_id, fields: { sob_all_label: 'E2E draft label' } });
		expect(r.ok && r.draft.rev === 1 && r.draft.changes === 1, 'draft change: ' + JSON.stringify(r.draft));
		r = await api({ action: 'form.save', draft_id: D.id, rev: 1, php: { BE: "nuSetProperty('e2e_draft_be', 'draft');" } });
		expect(r.ok && r.draft.changes === 2, 'draft PHP: ' + (r.error || JSON.stringify(r.draft)));
		expect(label() === before, 'live changed by the draft');
		r = await api({ action: 'object.save', draft_id: D.id, rev: 1, object_id: obj.zzzzsys_object_id, fields: { sob_all_label: 'stale' } });
		expect(!r.ok && r.code === 'draft_rev', 'stale revision accepted');
		r = await api({ action: 'object.save', object_id: obj.zzzzsys_object_id, fields: { sob_all_label: 'live' } });
		expect(!r.ok && r.code === 'draft_open_confirm', 'live change while a draft is open: ' + r.code);
		// preview: banner, the draft's label, saving refused
		await open(p, 'index.php?p=form&f=' + F + '&r=' + cust + '&draft=' + D.id);
		expect(await p.locator('.nuDraftBanner').count() === 1, 'no preview banner');
		expect(/E2E draft label/.test(await p.locator('#label_cus_name').textContent()), 'preview does not show the draft');
		const sv = await p.evaluate(async () => { const r = await fetch('index.php?p=formapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf },
			body: JSON.stringify({ action: 'save', form_id: nuNext.form.id, record_id: nuNext.record_id, values: {}, edited: [] }) }); return r.status; });
		expect(sv === 403, 'saving in a read-only preview: ' + sv);
		// another user keeps the live form
		const a = await login('sample_anna', UPW);
		await open(a, 'index.php?p=form&f=' + F + '&r=' + cust);
		expect(!/E2E draft label/.test(await a.locator('#label_cus_name').textContent()) && await a.locator('.nuDraftBanner').count() === 0, 'another user sees the draft');
		// compare, publish
		await open(p, 'index.php?p=builder&f=' + F + '&draft=1');
		r = await api({ action: 'draft.diff', draft_id: D.id });
		expect(r.diff.counts.objects === 1 && r.diff.counts.php === 1 && r.diff.objects.changed[0].sob_all_id === 'cus_name', 'compare: ' + JSON.stringify(r.diff.counts));
		r = await api({ action: 'draft.publish', draft_id: D.id, note: '' });
		expect(!r.ok && r.errors, 'publish without a note');
		r = await api({ action: 'draft.publish', draft_id: D.id, note: 'E2E publish' });
		if (!r.ok && r.warnings) r = await api({ action: 'draft.publish', draft_id: D.id, note: 'E2E publish', warnings_ok: true });
		expect(r.ok && r.version >= 1 && label() === 'E2E draft label', 'publish: ' + JSON.stringify(r).slice(0, 200));
		expect(sql("SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = '" + F + "_BE'").includes('e2e_draft_be'), 'published PHP missing');
		await open(a, 'index.php?p=form&f=' + F + '&r=' + cust);
		expect(/E2E draft label/.test(await a.locator('#label_cus_name').textContent()), 'users do not see the published version');
		await a.context().close();
		const hist = sql("SELECT GROUP_CONCAT(action ORDER BY created_at DESC, zzzzsys_nunext_form_history_id SEPARATOR '|') FROM zzzzsys_nunext_form_history WHERE form_id = '" + F + "'");
		expect(/(^|\|)published v\d/.test(hist) && hist.includes('before publish'), 'history: ' + hist.slice(0, 120));
		// restore "before publish" → the old label
		const bp = sql("SELECT zzzzsys_nunext_form_history_id FROM zzzzsys_nunext_form_history WHERE form_id = '" + F + "' AND action = 'before publish' ORDER BY created_at DESC LIMIT 1");
		r = await api({ action: 'history.restore', id: bp });
		expect(r.ok && label() === before, 'restore before publish: ' + (r.error || label()));
		// discard
		r = await api({ action: 'draft.start' });
		const D2 = r.draft;
		r = await api({ action: 'draft.discard', draft_id: D2.id });
		expect(r.ok && sql("SELECT status FROM zzzzsys_nunext_form_draft WHERE zzzzsys_nunext_form_draft_id = '" + D2.id + "'") === 'discarded' && label() === before, 'discard');
		// conflict: live changed after the draft started
		r = await api({ action: 'draft.start' });
		const D3 = r.draft;
		r = await api({ action: 'object.save', draft_id: D3.id, rev: 0, object_id: obj.zzzzsys_object_id, fields: { sob_all_label: 'E2E conflict draft' } });
		sql("UPDATE zzzzsys_object SET sob_all_label = 'Changed in nuBuilder' WHERE zzzzsys_object_id = '" + obj.zzzzsys_object_id + "'");
		r = await api({ action: 'draft.publish', draft_id: D3.id, note: 'conflict' });
		expect(!r.ok && r.conflict && r.changes.counts.objects === 1, 'conflict not found: ' + JSON.stringify(r).slice(0, 160));
		r = await api({ action: 'draft.publish', draft_id: D3.id, note: 'conflict', force: true, confirm_code: 'wrong', warnings_ok: true });
		expect(!r.ok && label() === 'Changed in nuBuilder', 'overwrite without the code');
		r = await api({ action: 'draft.publish', draft_id: D3.id, note: 'conflict', force: true, confirm_code: sql("SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'"), warnings_ok: true });
		expect(r.ok && label() === 'E2E conflict draft', 'overwrite: ' + JSON.stringify(r).slice(0, 160));
		// a form never published (status Draft): designers only, "Publish form" makes it active
		sql("UPDATE zzzzsys_form SET sfo_status = '0' WHERE zzzzsys_form_id = '" + F + "'");
		const a2 = await login('sample_anna', UPW);
		const rs = await open(a2, 'index.php?p=form&f=' + F);
		expect(rs.status() === 403, 'a Draft form opens for users: ' + rs.status());
		await a2.context().close();
		r = await api({ action: 'form.publishnew' });
		expect(r.ok && sql("SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'") === '1', 'publish new form');
		await open(p, 'index.php?p=form&f=' + F + '&draft=off');
	} finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(keep) });
		fs.unlinkSync(keep);
		sql('DELETE FROM zzzzsys_nunext_form_draft');
	}
};

// form drafts (0.8.386): the same builder actions once live and once in a draft (then published) give the same form
T.form_drafts_parity = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const meta = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_event', 'zzzzsys_browse', 'zzzzsys_php', 'zzzzsys_nunext_form_history'];
	const keep = path.join(os.tmpdir(), 'nunext-e2e-parity.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	const restore = () => execSync('mysql ' + DB, { input: fs.readFileSync(keep) });
	// the form without generated ids: objects by sob_all_id, tabs by title, browse columns by display
	const raw = (q) => execSync('mysql -N -r -B ' + DB, { input: q }).toString().trim();     // raw: no escaping of line ends in the JSON
	const norm = () => JSON.parse(raw(`SELECT JSON_OBJECT(
		'form', (SELECT JSON_OBJECT('d', sfo_description, 'js', sfo_javascript, 'json', sfo_json) FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'),
		'tabs', (SELECT JSON_ARRAYAGG(JSON_ARRAY(syt_title, syt_order, syt_access)) FROM (SELECT * FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' ORDER BY syt_order) x),
		'objects', (SELECT JSON_ARRAYAGG(JSON_ARRAY(o.sob_all_id, o.sob_all_label, o.sob_all_type, o.sob_input_type, o.sob_all_order, t.syt_title, o.sob_all_json, o.sob_all_access, o.sob_all_validate, o.sob_all_cloneable, o.sob_all_table,
			(SELECT JSON_ARRAYAGG(JSON_ARRAY(e.sev_event, e.sev_javascript)) FROM zzzzsys_event e WHERE e.sev_zzzzsys_object_id = o.zzzzsys_object_id)))
			FROM (SELECT * FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' ORDER BY sob_all_id) o LEFT JOIN zzzzsys_tab t ON t.zzzzsys_tab_id = o.sob_all_zzzzsys_tab_id),
		'browse', (SELECT JSON_ARRAYAGG(JSON_ARRAY(sbr_title, sbr_display, sbr_order, sbr_width, sbr_align, sbr_format, sbr_json)) FROM (SELECT * FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}' ORDER BY sbr_order) x),
		'php', (SELECT JSON_ARRAYAGG(JSON_ARRAY(SUBSTRING(zzzzsys_php_id, LENGTH('${F}') + 2), sph_php, sph_system, sph_hide)) FROM (SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id LIKE '${F}\\_%' ORDER BY zzzzsys_php_id) x))`));
	try {
		await open(p, 'index.php?p=builder&f=' + F);
		const api = (body) => p.evaluate(async (bd) => {
			const csrf = JSON.parse(document.getElementById('nuBuilderData').textContent).csrf;
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(bd) });
			return r.json();
		}, Object.assign({ form_id: F }, body));
		const run = async (draft) => {
			let rev = 0;
			const call = async (b) => {
				const r = await api(Object.assign({}, b, draft ? { draft_id: draft, rev } : {}));
				if (!r.ok) throw new Error(b.action + ': ' + r.error);
				if (r.draft) rev = r.draft.rev;
				return r;
			};
			let st = await call({ action: 'state' });
			const id = (fid) => st.objects.find(o => o.sob_all_id === fid).zzzzsys_object_id;
			const tab0 = st.tabs[0].zzzzsys_tab_id;
			st = await call({ action: 'object.save', object_id: '', fields: { sob_all_type: 'input', sob_all_id: 'e2e_par', sob_all_label: 'Parity', sob_input_type: 'text', sob_all_zzzzsys_tab_id: tab0, sob_all_top: 300, sob_all_left: 100 },
				events: [{ event: 'onchange', js: 'nuHasBeenEdited();' }] });
			st = await call({ action: 'object.save', object_id: id('cus_name'), fields: { sob_all_label: 'Name (parity)', sob_all_validate: '1' }, events: [{ event: 'onblur', js: 'void 0;' }] });
			st = await call({ action: 'object.order', ids: st.objects.map(o => o.zzzzsys_object_id).reverse() });
			st = await call({ action: 'tabs.save', tabs: st.tabs.map(t => ({ id: t.zzzzsys_tab_id, title: t.syt_title, access: t.syt_access || '0' })).concat([{ id: '', title: 'Parity tab', access: '0' }]) });
			st = await call({ action: 'browse.save', columns: st.browse.map(b => ({ id: b.zzzzsys_browse_id, title: b.sbr_title, display: b.sbr_display, align: b.sbr_align || 'l', format: b.sbr_format || '', width: b.sbr_width }))
				.concat([{ id: '', title: 'Parity col', display: 'cus_code', align: 'c', width: 80, filter: true }]) });
			st = await call({ action: 'form.save', fields: { sfo_javascript: '// parity' }, php: { BB: '$x = 1;' } });
			st = await call({ action: 'layout.convert' });
			st = await call({ action: 'object.delete', object_id: id('cus_notes') });
			return st;
		};
		await run('');
		const live = norm();
		restore();
		let r = await api({ action: 'draft.start' });
		await run(r.draft.id);
		r = await api({ action: 'draft.publish', draft_id: r.draft.id, note: 'parity', warnings_ok: true });
		expect(r.ok, 'publish: ' + JSON.stringify(r).slice(0, 200));
		const pub = norm();
		for (const x of [live, pub]) for (const k of ['objects', 'tabs', 'browse', 'php']) if (Array.isArray(x[k])) x[k].sort((m, n) => JSON.stringify(m) < JSON.stringify(n) ? -1 : 1);   // JSON_ARRAYAGG has no order
		for (const k of Object.keys(live)) expect(JSON.stringify(live[k]) === JSON.stringify(pub[k]), 'live and published draft differ in ' + k + ':\n' + JSON.stringify(live[k]).slice(0, 600) + '\n' + JSON.stringify(pub[k]).slice(0, 600));
	} finally {
		restore();
		fs.unlinkSync(keep);
		sql('DELETE FROM zzzzsys_nunext_form_draft');
	}
};

// page forms (0.8.387): login / right before the page code, wrapper modes, $nu (user, rights, parent, settings, secrets), JSON answers
// (401 login, 400 csrf, 403, 500 with details only for the globeadmin), assets confined to assets/, bad table names, direct calls, impersonation
T.page_forms = async (s) => {
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const F = 'e2epg' + Date.now().toString(36), dir = path.join(SITE, 'custom', 'pages', F);
	const id = 'e2epage' + Date.now().toString(36).slice(-6);
	const page = `<?php
defined('NU_PAGE') || exit;
switch ($nu->action()) {
	case 'info':
		$nu->json(['user' => $nu->user(), 'real' => $nu->realUser(), 'rights' => $nu->rights(), 'perm' => $nu->hasPermission('e2e_none'), 'dept' => $nu->inDepartment('sample', 'x'),
			'pos' => $nu->hasPosition('Trainee'), 'team' => $nu->inTeam('Lead'), 'parent' => $nu->parent(), 'record' => $nu->recordId(), 'colour' => $nu->setting('a.colour'),
			'secret' => $nu->secret('token'), 'ro' => $nu->readOnly(), 'api' => $nu->apiVersion(), 'hash' => $nu->hash('#USER_ID#|#RECORD_ID#'), 'cwd' => basename(getcwd()),
			'n' => count($nu->users(['department' => 'Sample'])), 'url' => $nu->url(['action' => 'x'])]);
	case 'save':
		$nu->log('e2e page log', ['v' => $nu->param('v')]);
		$nu->json(['ok' => $nu->isPost(), 'echo' => $nu->param('v')]);
	case 'boom':
		throw new RuntimeException('e2e page boom');
	case 'bad':
		$nu->insert('x; DROP TABLE y', ['a' => 1]);
		break;
	case 'deny':
		$nu->require($nu->hasPermission('e2e_none'));
		break;
	default:
		echo '<!doctype html><title>e2e page</title><p id="pg">page ' . $nu->h($nu->slug()) . '</p>' . $nu->sdk();
}
`;
	fs.mkdirSync(path.join(dir, 'assets'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'index.php'), page);
	fs.writeFileSync(path.join(dir, 'secrets.php'), "<?php\ndefined('NU_PAGE') || exit;\nreturn ['token' => 'e2e-secret'];\n");
	fs.writeFileSync(path.join(dir, 'assets', 'a.css'), 'body{color:red}');
	fs.writeFileSync(path.join(dir, 'assets', 'x.php'), '<?php echo "x";');
	sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_status) VALUES ('${id}', 'page', '${F}', 'E2E page', '1');
		INSERT INTO zzzzsys_nunext_page (zzzzsys_nunext_page_id, open_mode, iframe_height, settings) VALUES ('${id}', 'tab', 'auto', '{"a":{"colour":"teal"}}');
		INSERT INTO zzzzsys_access_form (zzzzsys_access_form_id, slf_zzzzsys_access_id, slf_zzzzsys_form_id, slf_save_button) VALUES ('${id}a', 'sample_viewer', '${id}', '1')`);
	const anna = await login('sample_anna', UPW), ben = await login('sample_ben', UPW);
	const get = (p, q, headers = {}) => p.evaluate(async ([u, h]) => { const r = await fetch(u, { headers: h, redirect: 'manual' }); const t = await r.text(); let j = null; try { j = JSON.parse(t); } catch (e) {} return { status: r.status, j, t, ct: r.headers.get('content-type') || '', h: Object.fromEntries(r.headers.entries()) }; }, [BASE + q, headers]);
	const J = { Accept: 'application/json' };
	try {
		// signed out: the login (HTML) or 401 JSON
		const out = await browser.newContext(); const o = await out.newPage();
		await o.goto(BASE + 'index.php?p=page&f=' + F);
		expect(/p=login/.test(o.url()), 'signed out: no login page');
		const o401 = await o.evaluate(async (u) => { const r = await fetch(u, { headers: { Accept: 'application/json' } }); return [r.status, await r.json()]; }, BASE + 'index.php?p=page&f=' + F + '&action=info');
		expect(o401[0] === 401 && o401[1].error === 'login', 'signed out JSON: ' + JSON.stringify(o401));
		// direct request of the file: the guard ends it (the PHP server runs it – Apache denies it)
		const direct = await o.evaluate(async (u) => { const r = await fetch(u); return (await r.text()).trim(); }, BASE + 'custom/pages/' + F + '/index.php');
		expect(direct === '' || /denied|forbidden/i.test(direct), 'direct call gives output: ' + direct.slice(0, 80));
		await out.close();
		// user with the view right: $nu
		let r = await get(anna, 'index.php?p=page&f=' + F + '&action=info&r=7&pf=samplecustomer&pr=3', J);
		expect(r.status === 200 && r.j, 'info: ' + r.status + ' ' + r.t.slice(0, 200));
		const u = r.j.user;
		expect(u.login === 'sample_anna' && u.position === 'Trainee' && u.department === 'Sample' && !u.globeadmin && !u.impersonated, 'user(): ' + JSON.stringify(u));
		expect(r.j.rights.view && r.j.rights.save && !r.j.rights.delete, 'rights(): ' + JSON.stringify(r.j.rights));
		expect(!r.j.perm && r.j.dept && r.j.pos && !r.j.team, 'access helpers: ' + JSON.stringify([r.j.perm, r.j.dept, r.j.pos, r.j.team]));
		expect(r.j.parent && r.j.parent.form_id === 'samplecustomer' && r.j.parent.record_id === '3' && r.j.record === '7', 'parent / record: ' + JSON.stringify(r.j.parent));
		expect(r.j.colour === 'teal' && r.j.secret === 'e2e-secret' && r.j.api === 1 && r.j.cwd === F && !r.j.ro, 'settings / secret / cwd: ' + JSON.stringify(r.j));
		expect(r.j.hash === u.id + '|7' && r.j.n >= 5 && /p=page/.test(r.j.url) && /r=7/.test(r.j.url), 'hash / users / url: ' + JSON.stringify([r.j.hash, r.j.n, r.j.url]));
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=info&pf=nuuser', J);
		expect(r.j && r.j.parent === null, 'parent without the right on it');
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=deny', J);
		expect(r.status === 403 && r.j.error === 'forbidden', '$nu->require(false): ' + r.status);
		// headers of the page route
		r = await get(anna, 'index.php?p=page&f=' + F);
		expect(r.status === 200 && r.h['x-frame-options'] === 'SAMEORIGIN' && /^frame-ancestors 'self'$/.test(r.h['content-security-policy']) && /no-store/.test(r.h['cache-control']), 'page headers: ' + JSON.stringify(r.h));
		expect(/nupage\.js/.test(r.t) && /data-csrf=/.test(r.t), 'sdk() missing');
		// no view right: 403 (HTML and JSON) – before any page code
		r = await get(ben, 'index.php?p=page&f=' + F, J);
		expect(r.status === 403 && r.j.error === 'forbidden', 'without the right: ' + r.status);
		expect((await open(ben, 'index.php?p=page&f=' + F)).status() === 403, 'without the right (HTML)');
		// CSRF: JSON 400 / HTML 400; with the token the POST works
		const post = (p, q, token, json) => p.evaluate(async ([u, tk, js]) => { const fd = new FormData(); fd.append('v', 'hi'); if (tk) fd.append('_csrf', document.querySelector('script[data-csrf]') ? document.querySelector('script[data-csrf]').dataset.csrf : '');
			const r = await fetch(u, { method: 'POST', body: fd, headers: js ? { Accept: 'application/json' } : {} }); const t = await r.text(); let j = null; try { j = JSON.parse(t); } catch (e) {} return { status: r.status, j }; }, [BASE + q, token, json]);
		await open(anna, 'index.php?p=page&f=' + F);
		r = await post(anna, 'index.php?p=page&f=' + F + '&action=save', false, true);
		expect(r.status === 400 && r.j && r.j.error === 'csrf', 'POST without token (JSON): ' + JSON.stringify(r));
		r = await post(anna, 'index.php?p=page&f=' + F + '&action=save', false, false);
		expect(r.status === 400 && !r.j, 'POST without token (HTML): ' + r.status);
		r = await post(anna, 'index.php?p=page&f=' + F + '&action=save', true, true);
		expect(r.status === 200 && r.j.ok && r.j.echo === 'hi', 'POST with token: ' + JSON.stringify(r));
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_debug WHERE deb_message LIKE 'e2e page log%' AND deb_flag = 'page:" + F + "'") >= 1, '$nu->log() wrote nothing');
		r = await anna.evaluate(async () => { const r = await NuPage.fetch(location.href.replace(/&action=[^&]*/, '') + '&action=save', { method: 'POST', body: new URLSearchParams({ v: 'sdk' }) }); return r.json(); });
		expect(r.echo === 'sdk', 'NuPage.fetch: ' + JSON.stringify(r));
		// errors: details only for the globeadmin; bad table names write nothing
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=boom', J);
		expect(r.status === 500 && r.j.error === 'server' && !/boom|index\.php/.test(JSON.stringify(r.j)), 'error for a user: ' + JSON.stringify(r.j));
		r = await get(s.ga, 'index.php?p=page&f=' + F + '&action=boom', J);
		expect(r.status === 500 && /e2e page boom/.test(r.j.detail || '') && /index\.php:\d+/.test(r.j.detail || ''), 'error for the globeadmin: ' + JSON.stringify(r.j));
		r = await get(s.ga, 'index.php?p=page&f=' + F + '&action=bad', J);
		expect(r.status === 500 && /Invalid table or column name/.test(r.j.detail || ''), 'bad table name: ' + JSON.stringify(r.j));
		// assets: only assets/, whitelisted types
		r = await get(anna, 'index.php?p=pageasset&f=' + F + '&file=assets/a.css');
		expect(r.status === 200 && /text\/css/.test(r.ct) && r.h['x-content-type-options'] === 'nosniff' && /private/.test(r.h['cache-control'] || ''), 'asset: ' + r.status + ' ' + r.ct);
		for (const bad of ['assets/../index.php', '../index.php', 'index.php', 'assets/x.php', 'secrets.php', '/etc/passwd', 'assets/./a.css', 'assets\\a.css'])
			expect((await get(anna, 'index.php?p=pageasset&f=' + F + '&file=' + encodeURIComponent(bad))).status === 404, 'asset not refused: ' + bad);
		// open modes: tab = redirect to the raw URL, iframe = wrapper with the page in an iframe (breadcrumb, host script)
		await open(anna, 'index.php?p=form&f=' + F + '&r=5');
		expect(/p=page/.test(anna.url()) && /r=5/.test(anna.url()), 'tab mode: ' + anna.url());
		sql(`UPDATE zzzzsys_nunext_page SET open_mode = 'iframe', iframe_height = 'auto' WHERE zzzzsys_nunext_page_id = '${id}'`);
		await open(anna, 'index.php?p=form&f=' + F + '&r=5');
		expect(/p=form/.test(anna.url()) && await anna.locator('iframe.nuPageFrame').count() === 1, 'iframe mode: no wrapper');
		expect(/p=page/.test(await anna.locator('iframe.nuPageFrame').getAttribute('src')), 'iframe src');
		await anna.waitForTimeout(800);
		const fr = anna.frameLocator('iframe.nuPageFrame');
		expect(/page /.test(await fr.locator('#pg').textContent()), 'page in the iframe');
		expect(await fr.locator('body').evaluate(() => window.NuPage && window.NuPage.inFrame), 'NuPage.inFrame');
		await fr.locator('body').evaluate(() => NuPage.toast('e2e toast', 'success'));
		await anna.waitForTimeout(500);
		expect(/e2e toast/.test(await anna.locator('#nuPageToasts').textContent()), 'toast in the parent');
		// the globeadmin as anna (view only): user() = anna, realUser() = the globeadmin, readOnly(), POST refused
		const g = await login(GA[0], GA[1]);
		const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
		await open(g, 'index.php?p=user&id=' + uid);
		expect(await csrfPost(g, 'index.php?p=impersonate', { action: 'start', id: uid, mode: 'view' }) < 400, 'impersonation not started');
		r = await get(g, 'index.php?p=page&f=' + F + '&action=info', J);
		expect(r.j && r.j.user.login === 'sample_anna' && r.j.user.impersonated && r.j.real && r.j.real.globeadmin && r.j.ro, 'impersonated: ' + JSON.stringify([r.j && r.j.user.login, r.j && r.j.real, r.j && r.j.ro]));
		await open(g, 'index.php?p=page&f=' + F);
		r = await post(g, 'index.php?p=page&f=' + F + '&action=save', true, true);
		expect(r.status === 403 && r.j.error === 'read_only', 'POST while viewing as a user: ' + JSON.stringify(r));
		await g.context().close();
		// page API versions (0.8.388): an unsupported $nu->api() stops before the page runs; unknown calls are listed in the builder and the forms list
		expect(await s.ga.evaluate(async (u) => { await fetch(u); return true; }, BASE + 'index.php?p=page&f=' + F), 'page before the API test');
		fs.writeFileSync(path.join(dir, 'index.php'), page.replace("defined('NU_PAGE') || exit;", "defined('NU_PAGE') || exit;\n$nu->api(99);"));
		r = await get(s.ga, 'index.php?p=page&f=' + F + '&action=info', J);
		expect(r.status === 500 && r.j && r.j.error === 'page_api' && /99/.test(r.j.detail || ''), 'unsupported API version: ' + JSON.stringify(r.j));
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=info', J);
		expect(r.status === 500 && r.j.error === 'page_api' && !r.j.detail, 'API error details for a user: ' + JSON.stringify(r.j));
		fs.writeFileSync(path.join(dir, 'index.php'), page.replace("defined('NU_PAGE') || exit;", "defined('NU_PAGE') || exit;\n$nu->api(1);"));
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=info', J);
		expect(r.status === 200 && r.j && r.j.api === 1, '$nu->api(1): ' + r.status);
		fs.writeFileSync(path.join(dir, 'more.php'), "<?php\ndefined('NU_PAGE') || exit;\n$nu->notAMethod();\n");
		fs.writeFileSync(path.join(dir, 'assets', 'app.js'), "NuPage.toast('x'); NuPage.gone();");
		await open(s.ga, 'index.php?p=builder&f=' + id);
		expect(/\b1\b/.test(await s.ga.locator('#pgApi').textContent()) && await s.ga.locator('.pgApiIssue').count() === 2, 'builder API issues: ' + await s.ga.locator('#pgFolder').textContent());
		await open(s.ga, 'index.php?p=forms&q=' + F);
		expect(await s.ga.locator('.nuPageApiWarn.text-danger').count() >= 1, 'forms list: no API warning');
		fs.rmSync(path.join(dir, 'more.php')); fs.rmSync(path.join(dir, 'assets', 'app.js'));
		await open(s.ga, 'index.php?p=builder&f=' + id);
		expect(await s.ga.locator('#pgApiOk').count() === 1, 'builder: clean page not shown as ok');
		// settings keys the page reads and settings.example.json (0.8.391)
		fs.writeFileSync(path.join(dir, 'settings.example.json'), '{"a": {"colour": "blue"}, "extra": 1}');
		await open(s.ga, 'index.php?p=builder&f=' + id);
		const keyRow = s.ga.locator('#pgKeys tr.pgKey[data-key="a.colour"]');
		expect(await keyRow.count() === 1 && /text-bg-success/.test(await keyRow.locator('.pgKeyState').getAttribute('class')), 'key a.colour not listed as set');
		await s.ga.fill('#pg_settings', '{"zz": 1}');
		await s.ga.waitForTimeout(200);
		expect(/zz/.test(await s.ga.locator('#pgKeysUnused').textContent()) && !/text-bg-success/.test(await keyRow.locator('.pgKeyState').getAttribute('class')), 'unused key / not set not shown');
		await s.ga.click('#pgExamplesBtn'); await s.ga.waitForTimeout(200);
		await s.ga.click('#pgExample');
		expect(/"colour": "blue"/.test(await s.ga.inputValue('#pg_settings')) && /extra/.test(await s.ga.locator('#pgKeysUnused').textContent()), 'Insert example');
		fs.rmSync(path.join(dir, 'settings.example.json'));
		// examples (0.8.394): a JSON from the page's keys and built-in samples; unsaved changes switch Open page / Copy AI prompt off
		expect(await s.ga.locator('#pgOpen.disabled').count() === 1 && await s.ga.locator('#pgPrompt[disabled]').count() === 1 && await s.ga.locator('#pgSaveFirst:not(.d-none)').count() === 1, 'unsaved: Open page / Copy AI prompt still active');
		await s.ga.click('#pgExamplesBtn'); await s.ga.waitForTimeout(200);
		await s.ga.click('#pgExampleKeys');
		expect(JSON.parse(await s.ga.inputValue('#pg_settings')).a.colour === null, 'example from the keys: ' + await s.ga.inputValue('#pg_settings'));
		await s.ga.click('#pgExamplesBtn'); await s.ga.waitForTimeout(200);
		await s.ga.locator('.pgSample').nth(1).click();
		expect(JSON.parse(await s.ga.inputValue('#pg_settings')).rows_per_page === 50, 'built-in sample');
		await open(s.ga, 'index.php?p=builder&f=' + id);
		expect(await s.ga.locator('#pgOpen.disabled').count() === 0 && await s.ga.locator('#pgSave').count() === 1, 'fresh builder: Open page off / no Save in the title bar');
		// the form builder of a page form: code rules, settings JSON
		await open(s.ga, 'index.php?p=builder&f=' + id);
		expect(await s.ga.locator('#pgForm').count() === 1 && await s.ga.locator('#pgFileFound').count() === 1, 'page builder');
		await s.ga.fill('#pg_settings', '{"a":');
		await s.ga.click('#pgSave');
		expect(await s.ga.locator('#pg_settings.is-invalid').count() === 1, 'invalid JSON not shown');
		for (const bad of ['../x', '_template', 'a b', 'a.b']) {
			const st = await csrfPost(s.ga, 'index.php?p=builder&f=' + id, { action: 'save', description: 'E2E page', code: bad, status: '1', open_mode: 'tab', iframe_height: 'auto', settings: '{}' });
			expect(st === 200 && sql(`SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`) === F, 'code accepted: ' + bad);
		}
		const st = await csrfPost(s.ga, 'index.php?p=builder&f=' + id, { action: 'save', description: 'E2E page 2', code: F, status: '1', open_mode: 'self', iframe_height: '600px', settings: '{"b":2}' });
		expect((st === 303 || st === 0) && sql(`SELECT CONCAT(open_mode, '|', iframe_height, '|', settings) FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'`) === 'self|600px|{"b":2}', 'page settings not saved: ' + st + ' ' + sql(`SELECT CONCAT(open_mode, '|', iframe_height, '|', settings) FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'`));
		// run objects of another form: an iframe shows the page itself with pf / pr; a button opens a tab page in a new tab
		const tab = sql("SELECT syt_zzzzsys_form_id, zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1").split('\t')[1];
		sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_run_zzzzsys_form_id, sob_run_method, sob_run_type, sob_run_target, sob_run_id)
			VALUES ('${id}i', 'samplecustomer', '${tab}', 'run', 'e2e_pgframe', 'Page frame', 999, 900, 20, 400, 200, '0', '${id}', 'i', 'F', '0', '#RECORD_ID#'),
			('${id}b', 'samplecustomer', '${tab}', 'run', 'e2e_pgbtn', 'Page button', 998, 1120, 20, 120, 30, '0', '${id}', 'b', 'F', '0', '')`);
		sql(`UPDATE zzzzsys_nunext_page SET open_mode = 'tab' WHERE zzzzsys_nunext_page_id = '${id}'`);
		try {
			const cust = s.cust || sql('SELECT MIN(sample_customer_id) FROM sample_customer');
			await open(anna, 'index.php?p=form&f=samplecustomer&r=' + cust);
			const src = await anna.locator('iframe.nuPageFrame').getAttribute('src');
			expect(/p=page/.test(src) && /pf=samplecustomer/.test(src) && new RegExp('pr=' + cust).test(src), 'iframe run object: ' + src);
			expect(await anna.locator('[data-nu-run-form="' + id + '"]').getAttribute('data-nu-run-target') === '2', 'run button of a tab page not in a new tab');
		} finally { sql(`DELETE FROM zzzzsys_object WHERE zzzzsys_object_id IN ('${id}i', '${id}b')`); }
		// cloner: the settings row goes along, the files do not (a hint says so)
		await open(s.ga, 'index.php?p=cloner&f=' + id);
		const cl = await s.ga.evaluate(async ([src, code]) => { const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value);
			for (const [k, v] of Object.entries({ action: 'run', source: src, dest_mode: 'new', code, description: 'E2E page clone', objects: '1' })) fd.append(k, v);
			const r = await fetch('index.php?p=cloner', { method: 'POST', body: fd }); return r.text(); }, [id, F + 'c']);
		const cid = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${F}c'`);
		expect(cid !== '' && sql(`SELECT settings FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${cid}'`) === '{"b":2}', 'cloned page form without its settings');
		expect(/custom\/pages\/[^ ]+c\//.test(cl), 'no hint about the files');
		sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${cid}'; DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${cid}'; DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${cid}'`);
		await anna.context().close(); await ben.context().close();
	} finally {
		sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = '${id}'`);
		sql("DELETE FROM zzzzsys_debug WHERE deb_message LIKE 'e2e page log%'");
		fs.rmSync(dir, { recursive: true, force: true });
	}
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
	expect(await vis() === n && await p.isDisabled('#nuPrintUndo'), 'reset view');
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

T.home_profiles = async (s) => {                  // 0.8.385: Customise → Start from (profiles, Undo, Done) and Save as → Based on
	const p = s.ga;
	// the globeadmin's layout and layout choice are put back afterwards – later tests expect the tiles of his normal layout
	const keepIds = sql("SELECT GROUP_CONCAT(zzzzsys_nunext_list_view_id) FROM zzzzsys_nunext_list_view WHERE list_key = 'home'");
	const keepDefs = sql("SELECT HEX(GROUP_CONCAT(CONCAT(zzzzsys_nunext_list_view_id, CHAR(1), definition) SEPARATOR 0x02)) FROM zzzzsys_nunext_list_view WHERE list_key = 'home'");
	const keepChoice = sql("SELECT HEX(setting_value) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'listview_ga'");
	try {
	const visible = () => p.locator('[data-nu-tile]').evaluateAll(a => a.filter(x => x.offsetParent !== null).map(x => x.getAttribute('data-nu-tile')).filter(k => !/^[cx]_/.test(k)));
	await open(p, 'index.php?p=home&tiles=1');
	await p.click('#nuHomeCustomize'); await p.waitForTimeout(400);
	expect(await p.locator('#nuHomeReset').count() === 0, 'Reset still there');
	await p.click('#nuHomeStartFrom'); await p.waitForTimeout(300);
	expect(await p.locator('.nuHeStartItem').count() === 10, 'Start from: ' + await p.locator('.nuHeStartItem').count() + ' profiles');
	const before = await p.inputValue('#nuHomeDisplay');
	await p.click('.nuHeStartItem[data-preset="developer"]'); await p.waitForTimeout(400);
	expect(await p.inputValue('#nuHomeDisplay') === 'tabs' && /3/.test(await p.locator('.nuHeNote').textContent()), 'Developer not loaded');
	await p.click('#nuHomeUndo'); await p.waitForTimeout(300);
	expect(await p.locator('.nuHeNote').count() === 0 && await p.inputValue('#nuHomeDisplay') === before, 'Undo did not go back');
	await p.click('#nuHomeStartFrom'); await p.waitForTimeout(300);
	await p.click('.nuHeStartItem[data-preset="minimal"]'); await p.waitForTimeout(300);
	await p.click('#nuHomeSave'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
	const shown = (await visible()).sort().join(',');
	expect(shown === 'debug,forms,procedures,reports,settings,users', 'Minimal shows ' + shown);
	// Save as → Based on "Users & security": a new layout, the current one stays
	await p.evaluate(() => document.querySelector('[data-nu-layout-act="saveas"]').click());
	await p.waitForSelector('#nuHlBase', { state: 'visible' });
	await p.selectOption('#nuHlBase', 'security');
	expect(await p.inputValue('#nuHlName') !== '' && await p.locator('.nuHlBaseInfo').textContent() !== '', 'Based on: no name / description');
	await Promise.all([p.waitForNavigation(), p.click('.nuHlSave')]); await p.waitForLoadState('networkidle');
	const sec = await visible();
	expect(['users', 'access', 'permissions', 'sessions'].every(k => sec.includes(k)) && !sec.includes('forms'), 'Users & security shows ' + sec.join(','));
	// an unknown profile is refused and stores nothing
	const n = sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'home'");
	const r = await p.evaluate(async () => { const D = JSON.parse(document.getElementById('nuHomeData').textContent);
		return (await fetch(D.url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf }, body: JSON.stringify({ action: 'layout_saveas', name: 'x', scope: 'user', preset: 'nope' }) })).json(); });
	expect(!r.ok && sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'home'") === n, 'unknown profile stored: ' + JSON.stringify(r));
	} finally {
		const ids = keepIds && keepIds !== 'NULL' ? keepIds.split(',') : [];
		sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'home'" + (ids.length ? " AND zzzzsys_nunext_list_view_id NOT IN ('" + ids.join("','") + "')" : ''));
		if (keepDefs && keepDefs !== 'NULL') for (const row of Buffer.from(keepDefs, 'hex').toString('utf8').split('\x02')) {
			const [id, def] = row.split('\x01');
			sql("UPDATE zzzzsys_nunext_list_view SET definition = UNHEX('" + Buffer.from(def, 'utf8').toString('hex') + "') WHERE zzzzsys_nunext_list_view_id = '" + id + "'");
		}
		if (keepChoice && keepChoice !== 'NULL') sql("UPDATE zzzzsys_nunext_setting SET setting_value = UNHEX('" + keepChoice + "') WHERE zzzzsys_nunext_setting_id = 'listview_ga'");
		else sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'listview_ga'");
	}
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
	expect(items.length === 4 && /Import/.test(items[0]) && /HTML/.test(items[1]) && /CSV/.test(items[2]) && /Excel/.test(items[3]), 'menu items: ' + items.join(' | '));   // 0.8.398: Export entries
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
			expect(await p.locator('.nuFlashToast.show').count() === 1, 'no toast with the answer of GitHub');   // 0.8.377
		}
	} finally { put(null); }
};

T.docs_newer = async (s) => {                           // 0.8.393: help and tutorial say when GitHub has a newer version
	const prev = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'");
	const put = (v) => sql(`REPLACE INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('github_update', '{"repo":"nuBuilder/nuBuilder-Next","pre":true,"auto":false,"checked_at":${Math.floor(Date.now() / 1000)},"latest":{"version":"${v}","tag":"v${v}","name":"${v}","url":"","size":1,"published":"","page":"","pre":false},"error":""}')`);
	try {
		put('9.9.9');
		for (const r of ['help', 'tutorial']) {
			await open(s.ga, 'index.php?p=' + r);
			expect(await s.ga.locator('#nuDocsNewer').count() === 1 && /9\.9\.9/.test(await s.ga.locator('#nuDocsNewer').textContent()), r + ': no notice of the newer version');
		}
		const anna = await login('sample_anna', UPW);
		await open(anna, 'index.php?p=help');
		expect(await anna.locator('#nuDocsNewer').count() === 0, 'notice for a user without the update right');
		await anna.context().close();
		put('0.0.1');
		await open(s.ga, 'index.php?p=help');
		expect(await s.ga.locator('#nuDocsNewer').count() === 0, 'notice although GitHub has no newer version');
	} finally {
		if (prev === '') sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'");
		else sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + prev.replace(/'/g, "''") + "' WHERE zzzzsys_nunext_setting_id = 'github_update'");
	}
};

T.update_layout = async (s) => {                        // 0.8.376: GitHub first, other ways folded; open / closed remembered in the browser
	const p = s.ga;
	await p.evaluate(() => { try { localStorage.removeItem('nunext-upd-other'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=update');
	const order = await p.evaluate(() => { const g = document.getElementById('updGitHub'), o = document.getElementById('updOther'); return !!(g && o && (g.compareDocumentPosition(o) & Node.DOCUMENT_POSITION_FOLLOWING)); });
	expect(order, 'GitHub is not above the other ways');
	expect(!(await p.locator('#updOther').isVisible()), 'other ways are open at first');
	await p.click('#updOtherBtn'); await p.waitForTimeout(600);
	await open(p, 'index.php?p=update');
	expect(await p.locator('#updOther').isVisible() && await p.locator('#updOtherBtn').getAttribute('aria-expanded') === 'true', 'open state not remembered');
	await p.click('#updOtherBtn'); await p.waitForTimeout(600);
	await open(p, 'index.php?p=update');
	expect(!(await p.locator('#updOther').isVisible()), 'closed state not remembered');
	// tabs (0.8.392): the tab is part of the address; a form post in a tab comes back to it; old anchors open their tab
	const active = async () => p.locator('#updTabs .nav-link.active').getAttribute('data-nu-tab');
	expect(await active() === 'update' && !(await p.locator('#updLibraries').isVisible()), 'update tab not first');
	await p.click('[data-nu-tab="system"]'); await p.waitForTimeout(400);
	expect(/#system$/.test(p.url()) && await p.locator('#updLibraries').isVisible() && await p.locator('#updRequirements').isVisible(), 'system tab');
	await open(p, 'index.php?p=update#backups'); await p.waitForTimeout(400);
	expect(await active() === 'backups', 'address #backups');
	const keep = await p.inputValue('#updKeep');
	await Promise.all([p.waitForNavigation(), p.click('#updKeepForm button[type=submit]')]); await p.waitForTimeout(400);
	expect(await active() === 'backups' && /#backups$/.test(p.url()), 'after saving the keep rule: ' + p.url());
	await p.fill('#updKeep', keep);
	await open(p, 'index.php?p=update#updHistory'); await p.waitForTimeout(400);
	expect(await active() === 'history', 'old anchor #updHistory');
	await p.click('[data-nu-tab="update"]'); await p.waitForTimeout(300);
	expect(!/#/.test(p.url()), 'first tab keeps a # in the address');
};

// start page hook nunext_home (0.8.378): PHP changes of the tile start page – badge, hint, hidden tile, dynamic group / tiles, banner
const homeHook = (php, js = '') => {
	const q = v => "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";
	sql("DELETE FROM zzzzsys_php WHERE sph_code = 'nunext_home'");
	if (php !== null) execSync('mysql ' + DB, { input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_javascript, sph_javascript_active, sph_status, sph_global) VALUES ('e2e_hook', 'nunext_home', 'e2e', "
		+ q(php) + ', ' + q(js) + ", '1', '1', '0')" });
};
T.home_hook = async (s) => {
	const p = s.ga;
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	try {
		homeHook(`nuHomeSetBadge('users', 'E2E <b>', 'danger');
nuHomeSetHint('access', 'Hint by hook');
nuHomeHideTile('forms');
nuHomeAddGroup('x_e2e', 'E2E group');
nuHomeAddTile('x_e2e_tile', ['group' => 'x_e2e', 'label' => 'E2E dynamic', 'icon' => 'bi-archive', 'page' => 'update']);
nuHomeAddTile('x_e2e_url', ['group' => 'x_e2e', 'label' => 'E2E url', 'url' => 'https://example.com/', 'newtab' => true]);
if ('#ADMIN_ROLE#' === 'E2E hook role') nuHomeSetBadge('securitycheck', 'role seen', 'info');
nuHomeBanner('E2E banner <script>x</script>', 'warning');
nuHomeSetData('e2eCount', 42);
echo 'e2e output';`);
		await open(p, 'index.php?p=home&tiles=1');
		const st = await p.evaluate(() => ({
			badge: document.querySelector('[data-nu-tile="users"] .nuHomeBadge')?.textContent, hint: document.querySelector('[data-nu-tile="access"]')?.textContent,
			forms: !!document.querySelector('#nuHomeView [data-nu-tile="forms"]'), dyn: [...document.querySelectorAll('#nuHomeView [data-nu-tile^="x_"]')].map(a => a.dataset.nuTile),
			banner: document.querySelector('.nuHomeHookBanner')?.textContent, scripts: document.querySelectorAll('.nuHomeHookBanner script').length,
			data: JSON.parse(document.getElementById('nuHomeData').textContent).hookData }));
		expect(st.badge === 'E2E <b>' && /Hint by hook/.test(st.hint || '') && !st.forms, 'badge / hint / hidden: ' + JSON.stringify(st));
		expect(st.dyn.join() === 'x_e2e_tile,x_e2e_url' && /E2E banner <script>/.test(st.banner || '') && st.scripts === 0 && st.data.e2eCount === 42, 'dynamic tiles / banner / data: ' + JSON.stringify(st));
		expect(/e2e output/.test(sql("SELECT deb_message FROM zzzzsys_debug ORDER BY deb_added DESC LIMIT 1")), 'echo not in Debug results');
		await p.fill('#nuHomeSearch', 'E2E dynamic'); await p.waitForTimeout(300);
		expect(await p.locator('[data-nu-tile="x_e2e_tile"]').isVisible() && !(await p.locator('[data-nu-tile="users"]').isVisible()), 'search does not find the dynamic tile');
		await p.fill('#nuHomeSearch', ''); await p.waitForTimeout(200);
		if (await p.locator('#nuHomeCustomize').count()) {
			await p.click('#nuHomeCustomize'); await p.waitForTimeout(500);
			expect(await p.locator('#nuHomeEdit [data-key^="x_"]').count() === 0, 'dynamic tiles appear in Customize');
		}
		// safe mode: nothing of the hook
		await open(p, 'index.php?p=home&tiles=1&safe=1');
		expect(await p.locator('#nuHomeSafe').count() === 1 && await p.locator('[data-nu-tile^="x_"]').count() === 0 && await p.locator('.nuHomeHookBanner').count() === 0, 'safe mode runs the hook');
		// a refused tile and a throwing hook: the page is drawn without the changes, the globeadmin sees why
		for (const bad of [`nuHomeAddTile('x_bad', ['label' => 'Bad', 'url' => 'javascript:alert(1)']);`, `nuHomeSetBadge('users', 'x'); throw new Exception('E2E boom');`]) {
			homeHook(bad);
			await open(p, 'index.php?p=home&tiles=1');
			expect(await p.locator('#nuHomeHookError').count() === 1 && await p.locator('[data-nu-tile="users"] .nuHomeBadge').count() === 0 && await p.locator('[data-nu-tile="users"]').count() === 1, 'broken hook: ' + bad);
		}
		// a user with an admin role sees the result (and #ADMIN_ROLE#), but never the error
		homeHook(`nuHomeAddGroup('x_e2e', 'E2E group'); nuHomeAddTile('x_e2e_url', ['group' => 'x_e2e', 'label' => 'E2E url', 'url' => 'https://example.com/']);
if ('#ADMIN_ROLE#' === 'E2E hook role') nuHomeSetBadge('securitycheck', 'role seen', 'info');`);
		sql("INSERT INTO zzzzsys_nunext_admin_role (zzzzsys_nunext_admin_role_id, code, name, rights, sort_order) VALUES ('e2e_hrole', 'e2eh', 'E2E hook role', '{\"securitycheck\":\"view\"}', 99)");
		sql("INSERT INTO zzzzsys_nunext_admin_user (zzzzsys_nunext_admin_user_id, role_id) VALUES ('" + uid + "', 'e2e_hrole')");
		const pa = await login('sample_anna', UPW);
		await open(pa, 'index.php?p=home');
		expect(await pa.locator('[data-nu-tile="x_e2e_url"]').count() === 1 && /role seen/.test(await pa.locator('[data-nu-tile="securitycheck"]').textContent()), 'admin role: hook result not shown');
		homeHook(`throw new Exception('E2E boom');`);
		await open(pa, 'index.php?p=home');
		expect(await pa.locator('#nuHomeHookError').count() === 0 && await pa.locator('[data-nu-tile="securitycheck"]').count() === 1, 'admin role sees the error');
		await pa.context().close();
	} finally {
		homeHook(null);
		sql("DELETE FROM zzzzsys_nunext_admin_user WHERE role_id = 'e2e_hrole'; DELETE FROM zzzzsys_nunext_admin_role WHERE zzzzsys_nunext_admin_role_id = 'e2e_hrole'; DELETE FROM zzzzsys_debug WHERE deb_message LIKE 'nunext_home%'");
	}
};

T.home_hook_js = async (s) => {                         // 0.8.379: nuHome JavaScript API from the procedure's JavaScript (?p=homejs)
	const p = s.ga;
	try {
		homeHook(`nuHomeSetData('n', 5); nuHomeAddGroup('x_e2e', 'E2E group'); nuHomeAddTile('x_e2e_tile', ['group' => 'x_e2e', 'label' => 'E2E dynamic', 'page' => 'update']);`,
			`nuHome.on('tile', function (t) {
  if (t.key === 'users') t.setBadge('JS ' + nuHome.data.n, 'success');
  if (t.key === 'access') t.setHint('JS hint');
  if (t.key === 'x_e2e_tile') { window.__dyn = t.source + '/' + t.kind + '/' + t.group; t.onClick(function () { window.__clicked = 1; return false; }); }
  if (t.key === 'notes') t.hide();
});
nuHome.on('ready', function () { window.__ready = nuHome.tiles().length; window.__one = !!nuHome.tile('users'); });
nuHome.on('search', function (q, shown) { window.__search = q + ':' + shown.map(function (t) { return t.key; }).join(','); });
nuHome.on('customize', function (on) { window.__cust = on; });
nuHome.on('tile', function () { throw new Error('E2E js boom'); });`);
		await open(p, 'index.php?p=home&tiles=1'); await p.waitForTimeout(300);
		await p.fill('#nuHomeSearch', 'E2E dynamic'); await p.waitForTimeout(250);
		await p.fill('#nuHomeSearch', 'notes'); await p.waitForTimeout(250);
		await p.fill('#nuHomeSearch', ''); await p.waitForTimeout(250);
		await p.click('[data-nu-tile="x_e2e_tile"]'); await p.waitForTimeout(300);
		const st = await p.evaluate(() => ({ url: location.search, ready: window.__ready, one: window.__one, dyn: window.__dyn, clicked: window.__clicked, search: window.__search,
			badge: document.querySelector('[data-nu-tile="users"] .nuHomeBadge')?.textContent, hint: document.querySelector('[data-nu-tile="access"] .nuHomeHint')?.textContent,
			notes: document.querySelector('[data-nu-tile="notes"]') ? document.querySelector('[data-nu-tile="notes"]').closest('.row > *, .nuHomeList > *').hidden : true,
			toasts: [...document.querySelectorAll('#nuHomeToast .alert')].filter(a => /E2E js boom/.test(a.textContent)).length }));
		expect(/tiles=1/.test(st.url) && st.clicked === 1 && st.dyn === 'dynamic/page/x_e2e' && st.ready > 5 && st.one, 'API: ' + JSON.stringify(st));
		expect(st.badge === 'JS 5' && st.hint === 'JS hint' && st.notes === true && /^:.*x_e2e_tile/.test(st.search || ''), 'tile changes: ' + JSON.stringify(st));
		expect(st.toasts === 1, 'error toast once for the globeadmin: ' + st.toasts);
		await p.fill('#nuHomeSearch', 'notes'); await p.waitForTimeout(250);
		expect(await p.evaluate(() => window.__search) === 'notes:', 'a tile hidden by code is found by the search');
		await p.fill('#nuHomeSearch', ''); await p.waitForTimeout(200);
		if (await p.locator('#nuHomeCustomize').count()) { await p.click('#nuHomeCustomize'); await p.waitForTimeout(400); expect(await p.evaluate(() => window.__cust) === true, 'no customize event'); }
		// safe mode: no script; the file needs a tile page user
		await open(p, 'index.php?p=home&tiles=1&safe=1');
		expect(await p.evaluate(() => [...document.scripts].some(x => /homejs/.test(x.src))) === false, 'safe mode loads the JavaScript');
		const pb = await login('sample_ben', UPW);
		expect((await pb.request.get(BASE + 'index.php?p=homejs&v=x')).status() === 403, 'a normal user gets the hook JavaScript');
		await pb.context().close();
	} finally { homeHook(null); }
};

T.home_hook_off = async (s) => {                        // $nuConfigHomeHooks = false: the hook does not run
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	try {
		homeHook(`nuHomeBanner('E2E off test');`, `window.__offjs = 1;`);
		fs.writeFileSync(cfg, orig.replace(/\?>\s*$/, '') + '\n$nuConfigHomeHooks = false;\n');
		await new Promise(r => setTimeout(r, 3500));
		await open(s.ga, 'index.php?p=home&tiles=1');
		expect(await s.ga.locator('.nuHomeHookBanner').count() === 0 && !(await s.ga.evaluate(() => window.__offjs)), '$nuConfigHomeHooks = false: the hook still runs');
	} finally { fs.writeFileSync(cfg, orig); homeHook(null); await new Promise(r => setTimeout(r, 3500)); }
};

T.existing_db = async (s) => {                          // 0.8.380: existing nuBuilder database – older install warned, new one blocked, $nuConfigAllowExistingNuDB allows
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	const before = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'db_origin'");
	const origin = v => sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'db_origin';" + (v ? " INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value, updated_at, updated_by) VALUES ('db_origin', '" + v + "', NOW(), 'e2e')" : ''));
	const p = s.ga;
	try {
		origin('legacy');
		await open(p, 'index.php?p=home&tiles=1');
		expect(await p.locator('#nuExistingDbWarn').count() === 1, 'older installation: no warning on the start page');
		origin('nubuilder');
		let r = await open(p, 'index.php?p=home&tiles=1');
		expect(r.status() === 503 && /existing nuBuilder database/i.test(await p.textContent('body')), 'new installation on an existing database is not blocked');
		r = await open(p, 'index.php?p=users');
		expect(r.status() === 503, 'blocked installation opens the users');
		fs.writeFileSync(cfg, orig.replace(/\?>\s*$/, '') + '\n$nuConfigAllowExistingNuDB = true;\n');
		await new Promise(res => setTimeout(res, 3500));
		r = await open(p, 'index.php?p=home&tiles=1');
		expect(r.status() === 200 && await p.locator('#nuExistingDbWarn').count() === 0, '$nuConfigAllowExistingNuDB = true does not allow it');
		origin('legacy');
		await open(p, 'index.php?p=home&tiles=1');
		expect(await p.locator('#nuExistingDbWarn').count() === 0, '$nuConfigAllowExistingNuDB = true still warns');
	} finally {
		fs.writeFileSync(cfg, orig);
		origin(before || null);
		await new Promise(res => setTimeout(res, 3500));
	}
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
		// the form builder offers access 9 with its condition, and saving the object keeps the condition
		await open(p, 'index.php?p=builder&f=tadv'); await p.waitForTimeout(600);
		await p.locator('.nbObj', { hasText: 'cus_name' }).locator('.nbObjMain').click(); await p.waitForTimeout(400);
		expect(await p.locator('select[data-key=sob_all_access]').inputValue() === '9', 'builder: access 9 not shown');
		expect(await p.locator('[data-key=sob_all_access_condition]').count() === 1, 'builder: no condition field');
		await p.click('#nbSave'); await p.waitForTimeout(1000);
		expect(/globeadmin/.test(sql("SELECT sob_all_access_condition FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'tadv' AND sob_all_id = 'cus_name'")), 'builder: condition lost on save');
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
			edited: nuHasBeenEdited(), red: document.getElementById('nuSaveButton').classList.contains('nuSaveEdited') }));
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
	if (!(await p.locator('#updOther').isVisible())) { await p.click('#updOtherBtn'); await p.waitForTimeout(500); }   // other ways are folded (0.8.376)
	await p.setInputFiles('#updPackage', pkg);
	await p.waitForTimeout(300);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('#updUpload')]);
	await p.waitForTimeout(800);
	expect(await p.locator('#updSignature .bi-patch-check-fill').count() === 1, 'package not shown as signed: ' + ((await p.textContent('#updSignature').catch(() => '')) || '').trim());
	// fast releases (0.8.389): a package without the release gate is marked "not fully tested"
	const gated = execSync('python3 -', { input: "import zipfile, json\nz=zipfile.ZipFile(" + JSON.stringify(pkg) + ")\nn=[x for x in z.namelist() if x.endswith('nunext-manifest.json')][0]\nprint(json.dumps(json.loads(z.read(n)).get('gated')))" }).toString().trim();
	expect((await p.locator('#updNotGated').count() === 1) === (gated === 'false'), 'not-gated note: manifest gated=' + gated);
	// page forms (0.8.388): packages with page_api in the manifest check the installed pages
	const hasApi = execSync('python3 -', { input: "import zipfile, json, sys\nz=zipfile.ZipFile(" + JSON.stringify(pkg) + ")\nn=[x for x in z.namelist() if x.endswith('nunext-manifest.json')][0]\nprint('1' if 'page_api' in json.loads(z.read(n)) else '0')" }).toString().trim() === '1';
	if (hasApi && SITE) {
		const F = 'e2eupg' + Date.now().toString(36), id = 'e2eupg' + Date.now().toString(36).slice(-6), dir = path.join(SITE, 'custom', 'pages', F);
		fs.mkdirSync(dir, { recursive: true });
		fs.writeFileSync(path.join(dir, 'index.php'), "<?php\ndefined('NU_PAGE') || exit;\n$nu->api(1);\n$nu->noSuchMethod();\n");
		sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_status) VALUES ('${id}', 'page', '${F}', 'E2E update page', '1')`);
		try {
			await p.reload(); await p.waitForTimeout(500);
			expect(await p.locator(`#updPagesList [data-page="${F}"] .bg-danger`).count() === 1, 'update check: page that would break not listed');
		} finally {
			sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`);
			fs.rmSync(dir, { recursive: true, force: true });
		}
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
