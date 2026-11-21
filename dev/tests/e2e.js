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
	p.on('pageerror', e => { p.errors.push(e.message); console.log('    pageerror: ' + e.message.split('\n')[0] + ' @ ' + p.url()); });   // the address says which page threw
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
	if (await p.locator('#nuConfirm.show .js-confirm-ok').count()) await p.click('#nuConfirm.show .js-confirm-ok');   // own dialog (0.8.763)
	else if (await p.locator('.btn-red').count()) await p.click('.btn-red');
	await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
}
async function setField(p, sel, v) { await p.fill(sel, v); await p.locator(sel).dispatchEvent('change'); }
async function save(p) { await p.click('#nuSaveButton'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800); }
function expect(cond, msg) { if (!cond) throw new Error(msg); }

const T = {};

// ---------------------------------------------------------------- shared helpers of the tests

// ------------------------------------------------------------------ nuBuilder hooks, lookups, permission items, events (0.8.365)
const msgText = p => p.evaluate(() => { const m = document.getElementById('nuMessageDiv'); return m ? m.textContent : ''; });

// 0.8.810: Form with AI – code in the spec, a small app, Ask AI in design mode, "Describe it" of the New form wizard
const aiMeta = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_event', 'zzzzsys_browse', 'zzzzsys_php', 'zzzzsys_nunext_form_history', 'zzzzsys_nunext_form_draft'];

async function aiKeep(fn, tables) {
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-ai.sql');
	execSync('mysqldump ' + DB + ' ' + aiMeta.join(' ') + ' > ' + keep);
	try { await fn(); } finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(keep) }); fs.unlinkSync(keep);
		for (const tb of tables) sql('DROP TABLE IF EXISTS ' + tb);
	}
}

async function faCheck(p, answer) {
	await p.evaluate(() => window.nuFormAiStep(3));
	await p.fill('#faAnswer', typeof answer === 'string' ? answer : JSON.stringify(answer));
	await p.click('#faCheck'); await p.waitForTimeout(800);
	return { ok: await p.locator('#faOk').count() === 1, msg: await p.textContent('#faMessages'), plan: await p.textContent('#faPlan') };
}

// 0.8.812: validation rules (core/form/nurules.php, assets/js/nu/nurules.js, design mode → Validation rules)
function vrSet(form, rules) {
	const j = JSON.stringify({nunext: {rules: rules}}).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
	sql("UPDATE zzzzsys_form SET sfo_json = " + (rules ? "'" + j + "'" : 'NULL') + " WHERE zzzzsys_form_id = '" + form + "'");
}

const vrType = (p, id, v) => p.evaluate(([i, x]) => { const e = document.getElementById(i); e.value = x; e.classList.add('nuEdited'); e.dispatchEvent(new Event('input', {bubbles: true})); e.dispatchEvent(new Event('change', {bubbles: true})); }, [id, v]);

const csrfPost = (p, url, fields) => p.evaluate(async ([u, f]) => {
	const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value);
	for (const k in f) fd.append(k, f[k]);
	const r = await fetch(u, { method: 'POST', body: fd, redirect: 'manual' }); return r.status;
}, [url, fields]);

// start page hook nu_home (0.8.378): PHP changes of the tile start page – badge, hint, hidden tile, dynamic group / tiles, banner
const homeHook = (php, js = '') => {
	const q = v => "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";
	sql("DELETE FROM zzzzsys_php WHERE sph_code = 'nu_home'");
	if (php !== null) execSync('mysql ' + DB, { input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_javascript, sph_javascript_active, sph_status, sph_global) VALUES ('e2e_hook', 'nu_home', 'e2e', "
		+ q(php) + ', ' + q(js) + ", '1', '1', '0')" });
};

// start page (0.8.452): one Customise button – its panel has the tabs Content (edit mode, Personalise), Appearance, Layouts
async function homeTab(pg, tab) {
	if (await pg.locator('#nuHomeViewPanel').isHidden()) { await pg.click('#nuHomeViewBtn'); await pg.waitForTimeout(300); }
	if (await pg.locator('#nuHomeVpTab-' + tab).count()) { await pg.click('#nuHomeVpTab-' + tab); await pg.waitForTimeout(200); }
}

async function homeEdit(pg) { await homeTab(pg, 'content'); await pg.click('#nuHomeVpEdit'); }

async function homeMine(pg) { await homeTab(pg, 'content'); await pg.click('#nuHomeVpMine'); }

async function pssPack(p) {
	const key = process.env.NUNEXT_SIGNING_KEY && fs.existsSync(process.env.NUNEXT_SIGNING_KEY) ? process.env.NUNEXT_SIGNING_KEY : path.resolve(__dirname, '../../../nunext-signing.key');
	const src = path.resolve(__dirname, '../../../libsrc/phpspreadsheet');
	if (!SITE || !fs.existsSync(key) || !fs.existsSync(path.join(src, 'autoload.php'))) return null;
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nupssp-'));
	const tp = path.join(SITE, 'vendor/packs');
	const stateFile = path.join(tp, 'nunext-libs.php');
	const stateBefore = fs.existsSync(stateFile) ? fs.readFileSync(stateFile) : null;
	const keep = path.join(tmp, 'keep');
	fs.mkdirSync(keep);
	for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpspreadsheet-/.test(d)) fs.renameSync(path.join(tp, d), path.join(keep, d));
	const undo = () => {
		for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpspreadsheet-/.test(d)) fs.rmSync(path.join(tp, d), { recursive: true, force: true });
		for (const d of fs.readdirSync(keep)) fs.renameSync(path.join(keep, d), path.join(tp, d));
		if (stateBefore) fs.writeFileSync(stateFile, stateBefore); else if (fs.existsSync(stateFile)) fs.unlinkSync(stateFile);
		fs.rmSync(tmp, { recursive: true, force: true });
	};
	execSync(`php dev/libpack.php ${JSON.stringify(tmp)} ${JSON.stringify(key)} phpspreadsheet=${JSON.stringify(src)}`, { cwd: path.resolve(__dirname, '../..') });
	const pack = fs.readdirSync(tmp).find((f) => /^nunext-lib-phpspreadsheet-.*\.zip$/.test(f));
	await open(p, 'index.php?p=update&lp=' + Date.now() + '#libraries');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update&lp=' + Date.now() + '#libraries'); }
	await p.setInputFiles('#lpUpload', path.join(tmp, pack));
	await Promise.all([p.waitForNavigation(), p.click('#lpUploadBtn')]);
	return undo;
}

// ---------------------------------------------------------------- gap analysis 0.8.458 (dev/tests/gapkit, 0.8.461)
const GAPKIT = path.join(__dirname, 'gapkit');

const TT_PW = 'Secret#123';

function ttInstall() {
	for (const f of ['testapp.sql', 't08_fixture.sql']) execSync('mysql ' + DB, { input: fs.readFileSync(path.join(GAPKIT, f)) });
	sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
}

function ttRemove() {
	sql("DROP TABLE IF EXISTS tt_country, tt_customer, tt_order, tt_log, tt_nopk;"
		+ ['form', 'tab', 'object', 'browse', 'event', 'php', 'access', 'access_form', 'access_php', 'access_report', 'report', 'user', 'permission_item', 'user_permission', 'file']
			.map(t => ` DELETE FROM zzzzsys_${t} WHERE zzzzsys_${t}_id LIKE 'tt%';`).join(''));
}

async function home_btn_compact(s) {
	const p = s.ga;
	await open(p, 'index.php?p=users');
	await Promise.all([p.waitForNavigation(), p.click('#nuHomeBtn')]);
	await p.waitForTimeout(300);
	expect(await p.evaluate(() => document.activeElement && document.activeElement.id) === 'nuHomeSearch', 'globeadmin: focus in the search');
	await open(p, 'index.php?p=home');
	await p.waitForTimeout(300);
	expect(await p.evaluate(() => document.activeElement && document.activeElement.id) !== 'nuHomeSearch', 'a plain visit keeps the focus where it was');
	const u = await login('sample_anna', UPW);
	try {
		await open(u, 'index.php?p=password');
		if (await u.locator('#nuHomeBtn').count()) {
			await Promise.all([u.waitForNavigation(), u.click('#nuHomeBtn')]);
			await u.waitForTimeout(300);
			if (await u.locator('#nuHomeSearch').count()) expect(await u.evaluate(() => document.activeElement && document.activeElement.id) === 'nuHomeSearch', 'user tile page: focus in the search');
		}
	} finally { await u.context().close(); }
}

const prjClean = () => sql("DELETE FROM zzzzsys_nunext_project_item WHERE project_id IN (SELECT zzzzsys_nunext_project_id FROM zzzzsys_nunext_project WHERE code LIKE 'e2e%');"
	+ " DELETE FROM zzzzsys_nunext_project_note WHERE project_id IN (SELECT zzzzsys_nunext_project_id FROM zzzzsys_nunext_project WHERE code LIKE 'e2e%');"
	+ " DELETE FROM zzzzsys_nunext_project_task WHERE project_id IN (SELECT zzzzsys_nunext_project_id FROM zzzzsys_nunext_project WHERE code LIKE 'e2e%');"
	+ " DELETE FROM zzzzsys_nunext_project WHERE code LIKE 'e2e%'");

// Getting ready checklist (0.8.1310): hidden for good with every point counted as "red when hidden" – no bar, no start page card
const grIds = () => SITE ? JSON.parse(execSync(`php -r 'chdir(${JSON.stringify(SITE)}); require "core/nuapp.php"; echo json_encode(array_keys(NuReady::ITEMS));'`).toString())
	: ['adminpw', 'admin2fa', 'https', 'secretkey', 'seccheck', 'backup', 'cleanup', 'github', 'libs'];

const grSet = (v) => { sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'getting_ready'"); if (v !== null) sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('getting_ready', '" + v.replace(/'/g, "''") + "')"); };

const grHideAll = () => grSet(JSON.stringify({ marks: {}, hide: { mode: 'forever', until: 0, bad: grIds() } }));

// the tests (code review L6): one file per area in dev/tests/e2e/, read into THIS scope so they use the helpers above; the run order
// is dev/tests/e2e/order.txt, then tests not listed (file order), logout last. A new test goes into the file of its area.
for (const f of fs.readdirSync(path.join(__dirname, 'e2e')).filter(n => n.endsWith('.js')).sort()) {
	eval(fs.readFileSync(path.join(__dirname, 'e2e', f), 'utf8'));   // eslint-disable-line no-eval
}
const ORDER = fs.readFileSync(path.join(__dirname, 'e2e', 'order.txt'), 'utf8').split('\n').map(l => l.trim()).filter(l => l && !l.startsWith('#'));
const runOrder = () => {
	const pos = new Map(ORDER.map((n, i) => [n, i])), names = Object.keys(T);
	return names.map((n, i) => [n, n === 'logout' ? 1e9 : pos.has(n) ? pos.get(n) : 1e6 + i]).sort((a, b) => a[1] - b[1]).map(x => [x[0], T[x[0]]]);
};

(async () => {
	const dump = path.join(os.tmpdir(), 'nunext-e2e-full-' + process.pid + '.sql');   // not nunext-e2e-<n>: tests name their own dumps like that (review_765)
	const tablesBefore = sql('SHOW TABLES').split('\n');
	execSync('mysqldump ' + DB + ' > ' + dump);
	browser = await chromium.launch();
	// ASSETS=min (0.8.1342): every context sends the cookie nunext_assets=min – the pages load the minified scripts / style sheets of
	// dev/minify.py although the test site runs in developer mode (run it after a release, which builds them)
	if (process.env.ASSETS === 'min' || process.env.ASSETS === 'src') {
		const host = new URL(BASE).hostname, orig = browser.newContext.bind(browser);
		browser.newContext = async (o) => { const c = await orig(o); await c.addCookies([{ name: 'nunext_assets', value: process.env.ASSETS, domain: host, path: '/' }]); return c; };
	}
	const s = {};
	try {
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		// an installed sample application older than this version's is refreshed first (tests use its current data; put back at the end)
		if (SITE) {
			const st = execSync(`php -r 'chdir(${JSON.stringify(SITE)}); require "core/nuapp.php"; $s = NuSample::status(); echo ($s["installed"] && $s["version"] < $s["current"]) ? "old" : "ok";'`).toString().trim();
			if (st === 'old') execSync(`php -r 'chdir(${JSON.stringify(SITE)}); require "core/nuapp.php"; NuSample::install();'`, { stdio: 'pipe' });
		}
		// known password for every sample user (the database is put back at the end)
		const hash = execSync('php -r ' + JSON.stringify('echo password_hash(' + JSON.stringify(UPW) + ', PASSWORD_BCRYPT);')).toString().trim();
		sql("UPDATE zzzzsys_user SET sus_login_password = '" + hash + "', sus_change_password = '0' WHERE sus_login_name LIKE 'sample\\_%'");
		// the globeadmin's own language (0.8.1205, setting lang_ga): the tests expect the browser's English
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'lang_ga'");
		grHideAll();   // the Getting ready bar (0.8.1310) would move every page down – hidden for good; test getting_ready shows it
		s.ga = await login(GA[0], GA[1]);
		for (const [name, fn] of runOrder()) {
			if (ONLY.length && !ONLY.includes(name)) continue;
			const t = Date.now();
			s.skip = '';
			s.ga.errors.length = 0;   // a script error belongs to the test it happened in – one stray error must not fail every later test
			try { await fn(s); results.push([name, s.skip ? 'skip' : 'ok', s.skip]); }
			catch (e) { results.push([name, 'FAIL', e.message.split('\n')[0]]); if (process.env.E2E_STACK) console.log(e.stack); }
			// a test that leaves the shared page at another size makes later tests fail (gate 0.8.1060: a phone width) – put it back, fail it
			try {
				const v = s.ga.viewportSize();
				if (v && (v.width !== 1300 || v.height !== 900)) {
					await s.ga.setViewportSize({ width: 1300, height: 900 });
					// a phone / tablet width changes the layout of every later page; other sizes (1300 × 800 …) are only reset
					if (v.width < 992 && results[results.length - 1][1] !== 'FAIL') results[results.length - 1] = [name, 'FAIL', 'left the shared page at ' + v.width + ' × ' + v.height];
				}
			} catch (e) { /* page closed */ }
			// a test that leaves the globeadmin in another language (stored since 0.8.1205) changes every later page – put it back, fail it
			try {
				const lg = sql("SELECT IFNULL(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'lang_ga'");
				if (lg && !/^en/.test(lg)) {
					sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'lang_ga'");
					await s.ga.goto(BASE + 'index.php?p=lang&l=en-GB&r=p%3Dhome').catch(() => {});
					if (results[results.length - 1][1] !== 'FAIL') results[results.length - 1] = [name, 'FAIL', 'left the globeadmin language at ' + lg];
				}
			} catch (e) { /* database gone */ }
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
