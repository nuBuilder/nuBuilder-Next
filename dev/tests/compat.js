/*
 * nuBuilder Next – cross-tests against nuBuilder itself (0.8.364): the same database opened in nuBuilder and in nuBuilder Next.
 *     NODE_PATH=$(npm root -g) BASE=http://127.0.0.1:8099/nunext/ NUB=http://127.0.0.1:8099/ DB="-unu -pnu nutest" node dev/tests/compat.js
 * Env: BASE (nuBuilder Next), NUB (nuBuilder on the same database), DB (mysql / mysqldump arguments), ADMIN_USER / ADMIN_PW (globeadmin
 * of both), FORMS (comma separated form ids – default: every browse / edit form that is not one of nuBuilder's own nu… forms),
 * RECORDS (records per form, default 3), WRITE=0 (read-only checks only), ONLY (test names or name prefixes), KEEP=1 (no restore),
 * GAPKIT=0 (without the test application of the gap analysis, dev/tests/gapkit/ – loaded by default unless FORMS is set).
 *
 * Generic checks for every form, so the suite also runs against a COPY of a real nuBuilder 4.x database with its own applications:
 *   browse:<form>   page 1 of the browse list: same records in the same order, same cell texts
 *   edit:<form>     the first RECORDS records: the same value in every field (main form and sub-form rows, lookups with code and description)
 *   save:<form>     save the first record without a change in nuBuilder, then (from the same start) in nuBuilder Next: the rows of the
 *                   form's table and of its sub-forms' tables must end up the same (nuBuilder Next must not write values differently)
 * Sample application only (skipped when it is not installed):
 *   entry_parity    the same typed input (numbers in two formats, dates, checkbox, select, text) as a new record in both: same row
 *   subform_cross   a sub-form row added in nuBuilder Next shows in nuBuilder; a row deleted in nuBuilder is gone in nuBuilder Next
 *   report_cross    a report runs as PDF in both
 * Accepted differences (nuBuilder Next on purpose shows more than nuBuilder) are listed in dev/tests/compat-known.json with the reason.
 * The suite saves the whole database first and puts it back at the end. Never run it against a database with real data – use a copy.
 */
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8099/nunext/';
const NUB = process.env.NUB || 'http://127.0.0.1:8099/';
const DB = process.env.DB || '-unu -pnu nutest';
const GA = [process.env.ADMIN_USER || 'globeadmin', process.env.ADMIN_PW || 'nu'];
const RECORDS = Number(process.env.RECORDS || 3);
const WRITE = process.env.WRITE !== '0';
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const KNOWN = JSON.parse(fs.readFileSync(path.join(__dirname, 'compat-known.json'), 'utf8')).known;
const sql = q => execSync('mysql -N --raw --default-character-set=utf8mb4 ' + DB, { input: q, maxBuffer: 64 << 20 }).toString().replace(/\n$/, '');
const q = s => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const results = [];
let browser, nub, nx;

function expect(cond, msg) { if (!cond) throw new Error(msg); }
const norm = v => v === undefined || v === null ? '' : String(v).replace(/\s+/g, ' ').trim();

/** Accepted difference? kind 'value' | 'cell' | 'column' | 'records' (browse list rows, field 'rows'); matched by form and field (* = any), optional values (regex on both sides). */
function known(kind, form, field, a, b) {
	return KNOWN.find(k => k.kind === kind && (k.form === '*' || k.form === form) && new RegExp('^(' + k.field + ')$').test(field)
		&& (!k.nub || new RegExp(k.nub).test(a)) && (!k.next || new RegExp(k.next).test(b)));
}

// ------------------------------------------------------------------ sessions

async function newPage() {
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 1300, height: 900 } });
	const p = await ctx.newPage();
	p.errors = [];
	p.on('pageerror', e => p.errors.push(e.message));
	p.on('dialog', d => d.accept());
	return p;
}
async function nubLogin() {
	const p = await newPage();
	await p.goto(NUB + 'index.php');
	await p.fill('#nuusername', GA[0]); await p.fill('#nupassword', GA[1]); await p.press('#nupassword', 'Enter');
	await p.waitForFunction(() => { try { return !!nuCurrentProperties().form_id; } catch (e) { return false; } }, null, { timeout: 20000 });
	return p;
}
async function nxLogin() {
	const p = await newPage();
	await p.goto(BASE + 'index.php?p=login');
	await p.fill('#username', GA[0]); await p.fill('#password', GA[1]);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
	for (let i = 0; i < 3 && await p.locator('.nuBellConfirm.show .btn-primary').count(); i++) { await p.click('.nuBellConfirm.show .btn-primary'); await p.waitForTimeout(500); }
	return p;
}

/** nuBuilder: open a form (record '' = browse list, '-1' = new) inside its single-page client and wait until it is drawn. */
async function nubOpen(form, record) {
	await nub.evaluate(([f, r]) => { window.nuTestDone = false; nuForm(f, r, '', '', 0); }, [form, record]);
	await nub.waitForFunction(([f, r]) => { try { const c = nuCurrentProperties(); return c.form_id === f && (r === '' ? !!c.browse_columns : c.record_id === r); } catch (e) { return false; } },
		[form, record], { timeout: 20000 });
	await nub.waitForLoadState('networkidle'); await nub.waitForTimeout(900);
}
async function nxOpen(form, record) {
	await nx.goto(BASE + 'index.php?p=form&f=' + encodeURIComponent(form) + (record !== '' ? '&r=' + encodeURIComponent(record) : ''));
	await nx.waitForLoadState('networkidle'); await nx.waitForTimeout(500);
}
async function nubSave() {
	const r = nub.waitForResponse(x => /nuapi\.php/.test(x.url()) && x.request().method() === 'POST', { timeout: 20000 });
	await nub.evaluate(() => nuSaveAction());
	await r; await nub.waitForLoadState('networkidle'); await nub.waitForTimeout(1200);
}
async function nxSave() {
	await nx.evaluate(() => nuSaveAction());
	await nx.waitForLoadState('networkidle'); await nx.waitForTimeout(1000);
}

// ------------------------------------------------------------------ what a page shows

/** Field values by element id (the same ids in both: cus_name, sample_items000ori_qty, lookups with …code / …description). */
function grabFields(ids) {
	const re = new RegExp('^(?:[A-Za-z0-9_]+\\d{3})?(' + ids.join('|') + ')(code|description)?$');
	const o = {};
	document.querySelectorAll('input[id], select[id], textarea[id]').forEach(e => {
		if (!re.test(e.id) || /^title_/.test(e.id) || ['button', 'submit', 'file'].includes(e.type)) return;
		o[e.id] = e.type === 'checkbox' ? (e.checked ? '1' : '0') : e.tagName === 'SELECT' && e.multiple ? [...e.selectedOptions].map(x => x.value).join(',') : e.value;
	});
	return o;
}
/** Browse page 1: record ids in order and the text of every cell. */
function grabBrowse() {
	const rows = [], cells = {};
	document.querySelectorAll('[id^="nucell_"]').forEach(c => {
		const m = c.id.match(/^nucell_(\d+)_(\d+)$/);
		if (!m) return;
		const pk = c.getAttribute('data-nu-primary-key') || '';
		if (!pk) return;
		if (m[2] === '0') rows[Number(m[1])] = pk;
		cells[c.id] = c.textContent;
	});
	return { rows: rows.filter(x => x !== undefined), cells };
}

// ------------------------------------------------------------------ database helpers

function formInfo(id) {
	const f = sql('SELECT sfo_table, sfo_primary_key, sfo_type FROM zzzzsys_form WHERE zzzzsys_form_id = ' + q(id)).split('\t');
	const subs = sql("SELECT o.sob_all_id, o.sob_subform_foreign_key, s.sfo_table, s.sfo_primary_key FROM zzzzsys_object o JOIN zzzzsys_form s ON s.zzzzsys_form_id = o.sob_subform_zzzzsys_form_id"
		+ " WHERE o.sob_all_type = 'subform' AND o.sob_all_zzzzsys_form_id = " + q(id)).split('\n').filter(Boolean).map(l => l.split('\t'));
	const forms = [id, ...sql("SELECT sob_subform_zzzzsys_form_id FROM zzzzsys_object WHERE sob_all_type = 'subform' AND sob_all_zzzzsys_form_id = " + q(id)).split('\n').filter(Boolean)];
	const ids = sql("SELECT DISTINCT sob_all_id FROM zzzzsys_object WHERE sob_all_type IN ('input','select','textarea','lookup','calc') AND sob_all_zzzzsys_form_id IN ("
		+ forms.map(q).join(',') + ')').split('\n').filter(Boolean).map(s => s.replace(/[^A-Za-z0-9_]/g, ''));
	return { table: f[0], pk: f[1], type: f[2], subs, ids };
}
const tableExists = t => t && t !== 'NULL' && sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " + q(t)) === '1';
const columns = t => sql("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = " + q(t) + ' ORDER BY ordinal_position').split('\n');
/** Rows of a record and its sub-form rows as {table: [ {col: value} ]} – columns that change on every save are left out. */
function snapshot(info, id) {
	const skip = c => /(_nulog|^updated_at|^updated_by|^modified_at|^modified_by|_updated_at|_updated_by)$/.test(c);
	const read = (t, where) => {                       // one JSON object per row: NULL stays null, line breaks cannot shift columns
		const cols = columns(t).filter(c => !skip(c));
		const out = sql('SELECT JSON_OBJECT(' + cols.map(c => q(c) + ', CAST(`' + c + '` AS CHAR)').join(', ') + ') FROM `' + t + '` WHERE ' + where + ' ORDER BY `' + cols[0] + '`').split('\n').filter(Boolean);
		return out.map(l => JSON.parse(l));
	};
	const s = { [info.table]: read(info.table, '`' + info.pk + '` = ' + q(id)) };
	for (const [, fk, t] of info.subs) if (tableExists(t)) s[t] = read(t, '`' + fk + '` = ' + q(id));
	return s;
}
/** Differences a (nuBuilder) / b (nuBuilder Next). With `before`: only where nuBuilder Next changed a value AND not the way nuBuilder did –
 *  a value nuBuilder Next leaves as it was is fine even when nuBuilder rewrites it (e.g. its editor turns <b> into <strong>). */
function diffSnap(a, b, before) {
	const d = [];
	for (const t of Object.keys(a)) {
		if ((a[t] || []).length !== (b[t] || []).length) { d.push(t + ': ' + (a[t] || []).length + ' rows in nuBuilder, ' + (b[t] || []).length + ' in nuBuilder Next'); continue; }
		a[t].forEach((r, i) => { for (const c of Object.keys(r)) {
			if (r[c] === b[t][i][c]) continue;
			if (before && before[t] && before[t][i] && b[t][i][c] === before[t][i][c]) continue;
			d.push(t + '.' + c + ': nuBuilder ' + JSON.stringify(r[c]) + ' · nuBuilder Next ' + JSON.stringify(b[t][i][c]));
		} });
	}
	return d;
}
function dumpTables(tables, file) { execSync('mysqldump ' + DB + ' ' + tables.join(' ') + ' > ' + file); }
function restore(file) { execSync('mysql ' + DB + ' < ' + file); }

// ------------------------------------------------------------------ tests

const T = [];          // [name, async fn]

function formsToTest() {
	if (process.env.FORMS) return process.env.FORMS.split(',').filter(Boolean);
	return sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_type IN ('browse','browseedit','edit') AND zzzzsys_form_id NOT LIKE 'nu%'"
		+ " AND sfo_table IS NOT NULL AND sfo_table <> '' ORDER BY sfo_code").split('\n').filter(Boolean);
}

// the whole database is saved first and put back at the end (also the test application of the gap analysis, loaded here – GAPKIT=0
// leaves it out; it has the kit's access levels, global hooks, calc sub-form and events, 0.8.462)
const DUMP = path.join(os.tmpdir(), 'nunext-compat-' + process.pid + '.sql');
const TABLES_BEFORE = sql('SHOW TABLES').split('\n');
execSync('mysqldump ' + DB + ' > ' + DUMP);
if (process.env.GAPKIT !== '0' && !process.env.FORMS) {
	for (const f of ['testapp.sql', 't08_fixture.sql']) execSync('mysql ' + DB, { input: fs.readFileSync(path.join(__dirname, 'gapkit', f)) });
}

for (const form of formsToTest()) {
	const info = formInfo(form);
	if (!tableExists(info.table)) continue;
	if (info.type !== 'edit') T.push(['browse:' + form, async (s) => {
		await nubOpen(form, ''); const a = await nub.evaluate(grabBrowse);
		await nxOpen(form, ''); const b = await nx.evaluate(grabBrowse);
		expect(a.rows.join(',') === b.rows.join(',') || known('records', form, 'rows', a.rows.join(','), b.rows.join(',')), 'records differ – nuBuilder: ' + a.rows.slice(0, 5).join(',') + ' · nuBuilder Next: ' + b.rows.slice(0, 5).join(','));
		const d = Object.keys(a.cells).filter(k => norm(a.cells[k]) !== norm(b.cells[k]) && !known('cell', form, k, norm(a.cells[k]), norm(b.cells[k])));
		expect(!d.length, d.slice(0, 4).map(k => k + ' ' + JSON.stringify(norm(a.cells[k])) + ' / ' + JSON.stringify(norm(b.cells[k]))).join(' · '));
		s.rows[form] = a.rows;
	}]);
	T.push(['edit:' + form, async (s) => {
		const recs = (s.rows[form] || sql('SELECT `' + info.pk + '` FROM `' + info.table + '` ORDER BY 1 LIMIT ' + RECORDS).split('\n')).filter(Boolean).slice(0, RECORDS);
		if (!recs.length) { s.skip = 'no records'; return; }
		const bad = [];
		for (const r of recs) {
			await nubOpen(form, r); const a = await nub.evaluate(grabFields, info.ids);
			await nxOpen(form, r); const b = await nx.evaluate(grabFields, info.ids);
			for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
				if (a[k] === undefined || b[k] === undefined) { if (!known('value', form, k, String(a[k]), String(b[k]))) bad.push(r + ' ' + k + (a[k] === undefined ? ' only in nuBuilder Next' : ' only in nuBuilder')); continue; }
				if (norm(a[k]) !== norm(b[k]) && !known('value', form, k, norm(a[k]), norm(b[k]))) bad.push(r + ' ' + k + ' ' + JSON.stringify(norm(a[k])) + ' / ' + JSON.stringify(norm(b[k])));
			}
		}
		expect(!bad.length, bad.length + ' difference(s): ' + bad.slice(0, 5).join(' · '));
	}]);
	if (WRITE && info.type !== 'browse') T.push(['save:' + form, async (s) => {
		const r = (s.rows[form] || [])[0] || sql('SELECT `' + info.pk + '` FROM `' + info.table + '` ORDER BY 1 LIMIT 1');
		if (!r) { s.skip = 'no records'; return; }
		const tables = [info.table, ...info.subs.map(x => x[2]).filter(tableExists)];
		const file = path.join(os.tmpdir(), 'nunext-compat-save-' + process.pid + '.sql');
		dumpTables(tables, file);
		try {
			const before = snapshot(info, r);
			await nubOpen(form, r); await nubSave(); const a = snapshot(info, r);
			restore(file);
			await nxOpen(form, r); await nxSave(); const b = snapshot(info, r);
			const d = diffSnap(a, b, before).filter(x => { const c = x.split(':')[0].split('.')[1]; return !c || !known('column', form, c, '', ''); });
			expect(!d.length, d.slice(0, 5).join(' · '));
			const self = diffSnap(before, a);
			if (self.length) s.note = 'nuBuilder itself changes data on a save without a change: ' + self.slice(0, 2).map(x => x.replace(': nuBuilder ', ': was ').replace(' · nuBuilder Next ', ' → ')).join(' · ');
		} finally { restore(file); fs.unlinkSync(file); }
	}]);
}

const hasSample = () => sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id IN ('sampleall','sampleorder')") === '2' && tableExists('sample_all');

T.push(['entry_parity', async (s) => {
	if (!hasSample() || !WRITE) { s.skip = 'sample application not installed'; return; }
	// typed like a user: number formats of the field (USD 1,000.00 / € 1.000,00 / 1000.0 %), date dd.mm.yyyy, checkbox, select, two lines of text
	const input = { all_number: '42', all_nunumber: '-1,234.5', all_nunumber_eur: '1.234,5', all_percent: '7.25', all_nudate: '03.02.2026', all_textarea: 'line 1\nline 2',
		all_email: 'parity@example.com', all_date: '2026-02-03', all_time: '13:45' };
	const fill = async (p, tag) => {
		await p.evaluate(([inp, t]) => {
			const set = (id, v) => { const e = document.getElementById(id); if (!e) return; e.focus(); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); e.blur(); };
			set('all_text', 'Parity ' + t); set('all_unique', 'parity-' + t);
			for (const k in inp) set(k, inp[k]);
			const c = document.getElementById('all_checkbox'); if (c && !c.checked) c.click();
			const sel = document.getElementById('all_select'); if (sel && sel.options.length > 1) { sel.value = sel.options[1].value; sel.dispatchEvent(new Event('change', { bubbles: true })); }
		}, [input, tag]);
		await p.waitForTimeout(300);
	};
	await nubOpen('sampleall', '-1'); await fill(nub, 'nub'); await nubSave();
	await nxOpen('sampleall', '-1'); await fill(nx, 'next'); await nxSave();
	const row = t => { const id = sql("SELECT sample_all_id FROM sample_all WHERE all_text = 'Parity " + t + "'"); return id ? snapshot(formInfo('sampleall'), id) : null; };
	const a = row('nub'), b = row('next');
	expect(a && b, 'not saved – nuBuilder: ' + !!a + ', nuBuilder Next: ' + !!b);
	const d = diffSnap(a, b).filter(x => !/\.(sample_all_id|all_text|all_unique|all_autonumber|created_at|created_by):/.test(x));
	expect(!d.length, d.slice(0, 6).join(' · '));
}]);

T.push(['subform_cross', async (s) => {
	if (!hasSample() || !WRITE) { s.skip = 'sample application not installed'; return; }
	const order0 = sql('SELECT ori_sample_order_id FROM sample_order_item GROUP BY ori_sample_order_id ORDER BY 1 LIMIT 1');
	const prod = sql('SELECT sample_product_id FROM sample_product ORDER BY 1 LIMIT 1');
	const n0 = Number(sql('SELECT COUNT(*) FROM sample_order_item WHERE ori_sample_order_id = ' + q(order0)));
	// add a row in nuBuilder Next (the empty last row of the sub-form)
	await nxOpen('sampleorder', order0);
	const last = String(n0).padStart(3, '0');
	await nx.evaluate(([l, pr]) => { const e = document.getElementById('sample_items' + l + 'ori_sample_product_id'); e.value = pr; e.dispatchEvent(new Event('change', { bubbles: true }));
		const qn = document.getElementById('sample_items' + l + 'ori_qty'); qn.value = '5'; qn.dispatchEvent(new Event('change', { bubbles: true })); }, [last, prod]);
	await nxSave();
	expect(Number(sql('SELECT COUNT(*) FROM sample_order_item WHERE ori_sample_order_id = ' + q(order0))) === n0 + 1, 'row not added in nuBuilder Next');
	await nubOpen('sampleorder', order0);
	// the new row is found by its quantity – both order the rows the same way (checked by edit:sampleorder), not necessarily last
	const newRow = await nub.evaluate(() => { const e = [...document.querySelectorAll('[id^="sample_items"][id$="ori_qty"]')].find(x => Number(x.value) === 5); return e ? e.id.slice(12, 15) : null; });
	expect(newRow !== null, 'nuBuilder does not show the new row');
	const nxRows = async (p) => p.evaluate(() => [...document.querySelectorAll('[id^="sample_items"][id$="ori_qty"]')].map(x => x.value).join(','));
	const order = [await nxRows(nub)];
	await nxOpen('sampleorder', order0); order.push(await nxRows(nx));
	expect(order[0] === order[1], 'sub-form rows in a different order – nuBuilder ' + order[0] + ' · nuBuilder Next ' + order[1]);
	await nubOpen('sampleorder', order0);
	// delete it in nuBuilder (sub-form delete box) and check nuBuilder Next
	await nub.evaluate(l => { const d = document.getElementById('sample_items' + l + 'nuDelete'); if (d) { d.checked = true; d.dispatchEvent(new Event('change', { bubbles: true })); } }, newRow);
	await nubSave();
	expect(Number(sql('SELECT COUNT(*) FROM sample_order_item WHERE ori_sample_order_id = ' + q(order0))) === n0, 'row not deleted in nuBuilder');
	await nxOpen('sampleorder', order0);
	const rows = await nx.evaluate(() => document.querySelectorAll('[id^="sample_items"][id$="ori_qty"]').length);
	expect(rows === n0 + 1, 'nuBuilder Next shows ' + rows + ' sub-form rows, expected ' + n0 + ' + the empty one');
}]);

T.push(['report_cross', async (s) => {
	const rep = sql("SELECT sre_code FROM zzzzsys_report WHERE sre_code = 'sample_customers'");
	if (!rep) { s.skip = 'sample report not installed'; return; }
	// both open the PDF in a new window: wait for a response with a PDF (the window's body cannot always be read back)
	const pdf = async (p, run) => {
		const [resp] = await Promise.all([p.context().waitForEvent('response', { predicate: r => /application\/pdf/.test(r.headers()['content-type'] || ''), timeout: 30000 }), run()]);
		return resp.status();
	};
	expect(await pdf(nub, () => nub.evaluate(c => nuRunReport(c), rep)) === 200, 'nuBuilder: no PDF');
	await nxOpen('samplecustomer', '');
	expect(await pdf(nx, () => nx.evaluate(c => nuRunReport(c), rep)) === 200, 'nuBuilder Next: no PDF');
	for (const pg of [...nub.context().pages().slice(1), ...nx.context().pages().slice(1)]) await pg.close();
}]);

// ------------------------------------------------------------------ metadata saved in nuBuilder Next's own editors (0.8.364)
// Open a record in nuBuilder Next's editor, save it without a change: nuBuilder's rows must stay the same (JSON columns compared by
// meaning – same keys, values and types) and nuBuilder must still open the record in its own system form.
const META = [
	['user', 'index.php?p=user&id=', '[data-nu-save]', 'zzzzsys_user', 'zzzzsys_user_id', "SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'", [], 'nuuser'],
	['access', 'index.php?p=accesslevel&id=', '[data-nu-save]', 'zzzzsys_access', 'zzzzsys_access_id', "SELECT zzzzsys_access_id FROM zzzzsys_access ORDER BY sal_code LIMIT 1",
		[['zzzzsys_access_form', 'slf_zzzzsys_access_id'], ['zzzzsys_access_php', 'slp_zzzzsys_access_id'], ['zzzzsys_access_report', 'sre_zzzzsys_access_id']], 'nuaccess'],
	['procedure', 'index.php?p=procedures&id=', '[data-nu-save]', 'zzzzsys_php', 'zzzzsys_php_id', "SELECT zzzzsys_php_id FROM zzzzsys_php WHERE zzzzsys_php_id NOT LIKE 'nu%' AND IFNULL(sph_system, '0') <> '1' AND IFNULL(sph_template, '0') <> '1' ORDER BY 1 LIMIT 1", [], 'nuphp'],
	['sqlbuilder', 'index.php?p=sqlbuilder&id=', '[data-nu-save]', 'zzzzsys_select', 'zzzzsys_select_id', "SELECT zzzzsys_select_id FROM zzzzsys_select WHERE zzzzsys_select_id NOT LIKE 'nu%' ORDER BY 1 LIMIT 1",
		[['zzzzsys_select_clause', 'ssc_zzzzsys_select_id']], 'nuselect'],
	['report', 'index.php?p=reportdesigner&id=', '.nuBtnSave', 'zzzzsys_report', 'zzzzsys_report_id', "SELECT zzzzsys_report_id FROM zzzzsys_report WHERE zzzzsys_report_id NOT LIKE 'nu%' ORDER BY 1 LIMIT 1", [], 'nubuildreport'],
	['permission', 'index.php?p=permissions&id=', '[data-nu-save]', 'zzzzsys_permission_item', 'zzzzsys_permission_item_id', 'SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item ORDER BY 1 LIMIT 1', [], 'nupermissionitem'],
	['emailtemplate', 'index.php?p=emailtemplates&id=', '[data-nu-save]', 'zzzzsys_email_template', 'zzzzsys_email_template_id', "SELECT zzzzsys_email_template_id FROM zzzzsys_email_template WHERE emt_code NOT LIKE '%_template' ORDER BY 1 LIMIT 1", [], 'nuemailtemplate'],
];
/** Rows by primary key (first column); JSON values parsed, so the comparison is by meaning, not by spacing or key order. */
function metaRows(table, where) {
	const cols = columns(table).filter(c => !/(_nulog|_updated_on|_updated_at|_updated_by)$/.test(c));
	const lines = sql('SELECT JSON_OBJECT(' + cols.map(c => q(c) + ', CAST(`' + c + '` AS CHAR)').join(', ') + ') FROM `' + table + '` WHERE ' + where).split('\n').filter(Boolean);
	const out = {};
	for (const l of lines) {
		const r = JSON.parse(l);
		for (const k in r) if (typeof r[k] === 'string' && /^\s*[[{]/.test(r[k])) { try { r[k] = JSON.parse(r[k]); } catch (e) { /* text */ } }
		out[r[cols[0]]] = r;
	}
	return out;
}
/** First place two values differ: path + both values. */
function deepDiff(a, b, p = '') {
	if (a && b && typeof a === 'object' && typeof b === 'object') {
		for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) if (!same(a[k], b[k])) return deepDiff(a[k], b[k], p + (Array.isArray(a) ? '[' + k + ']' : '.' + k));
	}
	if (typeof a === 'string' && typeof b === 'string') {          // texts: show where they start to differ
		let i = 0; while (i < a.length && a[i] === b[i]) i++;
		return (p || 'value') + ' at character ' + i + ': ' + JSON.stringify(a.slice(Math.max(0, i - 15), i + 25)) + ' → ' + JSON.stringify(b.slice(Math.max(0, i - 15), i + 25));
	}
	return (p || 'value') + ' ' + JSON.stringify(a === undefined ? '(missing)' : a).slice(0, 60) + ' → ' + JSON.stringify(b === undefined ? '(missing)' : b).slice(0, 60);
}
const same = (a, b) => JSON.stringify(sortKeys(a)) === JSON.stringify(sortKeys(b));
function sortKeys(v) { return Array.isArray(v) ? v.map(sortKeys) : v && typeof v === 'object' ? Object.fromEntries(Object.keys(v).sort().map(k => [k, sortKeys(v[k])])) : v; }
function metaDiff(a, b, table) {
	const d = [];
	for (const id of new Set([...Object.keys(a), ...Object.keys(b)])) {
		if (!a[id] || !b[id]) { d.push(table + ' ' + id + (a[id] ? ' removed' : ' added')); continue; }
		for (const c of Object.keys(a[id])) if (!same(a[id][c], b[id][c])) d.push(table + '.' + c + ' (' + id + '): ' + deepDiff(a[id][c], b[id][c]));
	}
	return d;
}
for (const [kind, url, btn, table, pk, pick, children, nubForm] of META) {
	T.push(['meta:' + kind, async (s) => {
		if (!WRITE) { s.skip = 'WRITE=0'; return; }
		const id = tableExists(table) ? sql(pick) : '';
		if (!id) { s.skip = 'no record'; return; }
		const snap = () => {
			const o = { [table]: metaRows(table, '`' + pk + '` = ' + q(id)) };
			for (const [t, fk] of children) if (tableExists(t)) o[t] = metaRows(t, '`' + fk + '` = ' + q(id));
			return o;
		};
		const before = snap();
		await nx.goto(BASE + url + encodeURIComponent(id)); await nx.waitForLoadState('networkidle'); await nx.waitForTimeout(600);
		const b = nx.locator(btn).first();
		expect(await b.count(), 'no Save button (' + btn + ')');
		if (await b.isDisabled()) { s.skip = 'Save is off until something changes'; return; }
		await b.click(); await nx.waitForLoadState('networkidle'); await nx.waitForTimeout(1200);
		const after = snap();
		const d = Object.keys(before).flatMap(t => metaDiff(before[t], after[t], t));
		expect(!d.length, d.length + ' change(s): ' + d.slice(0, 4).join(' · '));
		const n0 = nub.errors.length;
		await nubOpen(nubForm, id);
		expect(nub.errors.length === n0, 'nuBuilder: ' + nub.errors.slice(n0).join(' | '));
	}]);
}

// ------------------------------------------------------------------ design mode (0.8.670, replaces the builder's save-without-change)
// A draft of a form whose change is undone again (label → other text → back) is published: nuBuilder's rows of the form stay as they
// were, and nuBuilder still opens the form in its own form editor.
T.push(['meta:design', async (s) => {
	if (!WRITE) { s.skip = 'WRITE=0'; return; }
	const id = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' AND sfo_type = 'browseedit' AND sfo_status = '1' ORDER BY 1 LIMIT 1");
	if (!id) { s.skip = 'no form'; return; }
	const obj = sql(`SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ${q(id)} AND sob_all_type = 'input' ORDER BY 1 LIMIT 1`);
	if (!obj) { s.skip = 'no input object'; return; }
	const children = [['zzzzsys_object', 'sob_all_zzzzsys_form_id'], ['zzzzsys_tab', 'syt_zzzzsys_form_id'], ['zzzzsys_browse', 'sbr_zzzzsys_form_id']];
	const snap = () => {
		const o = { zzzzsys_form: metaRows('zzzzsys_form', '`zzzzsys_form_id` = ' + q(id)) };
		for (const [t, fk] of children) o[t] = metaRows(t, '`' + fk + '` = ' + q(id));
		return o;
	};
	const before = snap();
	sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = ${q(id)}`);
	await nx.goto(BASE + 'index.php?p=form&f=' + encodeURIComponent(id) + '&r=-1&design=1'); await nx.waitForLoadState('networkidle'); await nx.waitForTimeout(800);
	expect(await nx.locator('#nuDsBar').count() === 1, 'design mode did not open');
	const res = await nx.evaluate(async ([formId, objectId]) => {
		const call = (body) => fetch('index.php?p=builderapi', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf },
			body: JSON.stringify(Object.assign({ form_id: formId }, body)) }).then(r => r.json());
		let j = await call({ action: 'draft.start', preview: true });
		if (!j.ok) return 'start: ' + j.error;
		const orig = (j.objects.filter(o => o.zzzzsys_object_id === objectId)[0] || {}).sob_all_label;
		j = await call({ action: 'object.save', draft_id: j.draft.id, rev: j.draft.rev, object_id: objectId, fields: { sob_all_label: 'compat design' } });
		if (!j.ok) return 'save 1: ' + j.error;
		j = await call({ action: 'object.save', draft_id: j.draft.id, rev: j.draft.rev, object_id: objectId, fields: { sob_all_label: orig } });
		if (!j.ok) return 'save 2: ' + j.error;
		j = await call({ action: 'draft.publish', draft_id: j.draft.id, note: 'compat', warnings_ok: true });
		return j.ok ? '' : 'publish: ' + (j.error || JSON.stringify(j.errors || j.warnings || j.conflict));
	}, [id, obj]);
	expect(res === '', res);
	const after = snap();
	const d = Object.keys(before).flatMap(t => metaDiff(before[t], after[t], t));
	expect(!d.length, d.length + ' change(s): ' + d.slice(0, 4).join(' · '));
	const n0 = nub.errors.length;
	await nubOpen('nuform', id);
	expect(nub.errors.length === n0, 'nuBuilder: ' + nub.errors.slice(n0).join(' | '));
}]);

// ------------------------------------------------------------------ logins across both (0.8.365)
T.push(['login_cross', async (s) => {
	if (!WRITE) { s.skip = 'WRITE=0'; return; }
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	if (!uid) { s.skip = 'sample users not installed'; return; }
	// a password set in nuBuilder Next works in nuBuilder
	await nx.goto(BASE + 'index.php?p=user&id=' + uid); await nx.waitForLoadState('networkidle');
	await nx.fill('#password', 'Cross-Pass1!'); await nx.fill('#password_confirm', 'Cross-Pass1!');
	await nx.locator('#password').dispatchEvent('change');
	await Promise.all([nx.waitForLoadState('networkidle'), nx.click('[data-nu-save]')]); await nx.waitForTimeout(800);
	const ctx = await browser.newContext(); const p = await ctx.newPage();
	await p.goto(NUB + 'index.php'); await p.fill('#nuusername', 'sample_anna'); await p.fill('#nupassword', 'Cross-Pass1!'); await p.press('#nupassword', 'Enter');
	const ok = await p.waitForFunction(() => { try { return !!nuCurrentProperties().form_id; } catch (e) { return false; } }, null, { timeout: 15000 }).then(() => true, () => false);
	await ctx.close();
	expect(ok, 'nuBuilder refuses the password set in nuBuilder Next');
	// a one-time login link of nuBuilder Next logs in to nuBuilder (same LOGIN_TOKEN)
	await nx.goto(BASE + 'index.php?p=user&id=' + uid); await nx.waitForLoadState('networkidle');
	if (!await nx.locator('button[value=logintoken_create]').count()) { s.note = 'login links switched off'; return; }
	await nx.evaluate(() => document.querySelector('button[value=logintoken_create]').click());
	await nx.waitForSelector('#loginTokenUrl', { state: 'attached', timeout: 15000 });
	const q = String(await nx.locator('#loginTokenUrl').inputValue()).split('?')[1];
	const c2 = await browser.newContext(); const p2 = await c2.newPage();
	await p2.goto(NUB + 'index.php?' + q);
	const ok2 = await p2.waitForFunction(() => { try { return !!nuCurrentProperties().form_id; } catch (e) { return false; } }, null, { timeout: 15000 }).then(() => true, () => false);
	await c2.close();
	expect(ok2, 'nuBuilder does not accept the login link of nuBuilder Next');
}]);

T.push(['js_errors', async (s) => {                  // errors of nuBuilder itself are only noted
	expect(!nx.errors.length, nx.errors.slice(0, 3).join(' | '));
	if (nub.errors.length) s.note = 'nuBuilder: ' + [...new Set(nub.errors)].slice(0, 2).join(' | ');
}]);

// ------------------------------------------------------------------ run

(async () => {
	const dump = DUMP, tablesBefore = TABLES_BEFORE;
	browser = await chromium.launch();
	const s = { rows: {} };
	try {
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		nub = await nubLogin();
		nx = await nxLogin();
		for (const [name, fn] of T) {
			if (ONLY.length && !ONLY.some(o => name === o || name.startsWith(o))) continue;
			const t = Date.now();
			s.skip = ''; s.note = '';
			try { await fn(s); results.push([name, s.skip ? 'skip' : 'ok', s.skip || s.note]); }
			catch (e) { results.push([name, 'FAIL', e.message.split('\n')[0]]); }
			const r = results[results.length - 1];
			console.log((r[1] === 'ok' ? 'ok   ' : r[1] === 'skip' ? 'skip ' : 'FAIL ') + name.padEnd(26) + String(Date.now() - t).padStart(6) + ' ms  ' + (r[2] || ''));
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
