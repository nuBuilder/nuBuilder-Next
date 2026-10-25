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
	await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('form button[type=submit]:not([name=demo_user])')]);
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

// back to all records (0.8.428): the × button (search words + column filters go, sort stays), the field's own ×, Esc; no button without a search
T.browse_reset = async (s) => {
	const p = s.ga;
	const total = sql('SELECT COUNT(*) FROM sample_customer');
	const rows = () => p.locator('td.nuCell[data-nu-column="1"]').count();
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

// e-mail settings → "Check connection" (0.8.432): server, encryption, login and sender are checked against a local fake
// SMTP server (dev/tests/fakesmtp.py); nothing is sent (no RCPT / DATA)
T.mail_check = async (s) => {
	const p = s.ga, port = 2600 + Math.floor(Math.random() * 300), log = path.join(os.tmpdir(), 'e2e-smtp-' + Date.now() + '.log');
	const cols = 'set_smtp_host,set_smtp_port,set_smtp_username,set_smtp_password,set_smtp_from_address,set_smtp_use_ssl,set_smtp_use_authentication';
	const old = sql('SELECT CONCAT_WS(\',\',' + cols.split(',').map(c => "IFNULL(HEX(CAST(" + c + " AS CHAR)),'-')").join(',') + ') FROM zzzzsys_setup LIMIT 1').split(',');
	const set = (pw, from) => sql("UPDATE zzzzsys_setup SET set_smtp_host='127.0.0.1', set_smtp_port=" + port + ", set_smtp_username='u', set_smtp_password='" + pw + "', set_smtp_from_address='" + from + "', set_smtp_use_ssl='0', set_smtp_use_authentication='1'");
	const srv = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), log, String(port)], { stdio: 'ignore' });
	const steps = () => p.evaluate(() => Object.fromEntries([...document.querySelectorAll('#mailCheckResult [data-step]')].map(l => [l.dataset.step, l.dataset.ok])));
	const check = async (btn, tab) => {
		await open(p, 'index.php?p=email&tab=' + tab);
		await Promise.all([p.waitForNavigation(), p.click(btn)]); await p.waitForLoadState('networkidle');
		return steps();
	};
	try {
		await new Promise(r => setTimeout(r, 600));
		set('p', 'noreply@example.com');
		let r = await check('#nuMailCheckBtn', 'test');
		expect(r.server === '1' && r.login === '1' && r.sender === '1', 'check ok: ' + JSON.stringify(r));
		const cmds = fs.readFileSync(log, 'utf8').split('\n');
		expect(cmds.includes('MAIL') && !cmds.includes('RCPT') && !cmds.includes('DATA') && cmds.includes('QUIT'), 'nothing sent: ' + cmds.join(' '));
		set('wrong', 'noreply@example.com');
		r = await check('#nuMailCheckBtn2', 'settings');
		expect(r.server === '1' && r.login === '0' && !r.sender, 'wrong password: ' + JSON.stringify(r));
		set('p', 'bad@example.com');
		r = await check('#nuMailCheckBtn', 'test');
		expect(r.login === '1' && r.sender === '0', 'sender refused: ' + JSON.stringify(r));
		srv.kill(); await new Promise(r => setTimeout(r, 300));
		r = await check('#nuMailCheckBtn', 'test');
		expect(r.server === '0', 'server down: ' + JSON.stringify(r));
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event IN ('email_check','email_check_failed')") > 0, 'logged');
	} finally {
		srv.kill();
		sql('UPDATE zzzzsys_setup SET ' + cols.split(',').map((c, i) => c + '=' + (old[i] === '-' ? 'NULL' : "UNHEX('" + old[i] + "')")).join(','));
		fs.rmSync(log, { force: true });
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// OAuth 2 for the SMTP login (0.8.445): "Other provider" against a local fake provider (dev/tests/fakeoauth.py) and fake SMTP server with
// XOAUTH2 – Save and connect → consent → oauth.php → connected mailbox, Check connection and a test mail log in with the access token,
// the token is refreshed when it ran out, a foreign state is refused, Disconnect
T.mail_oauth = async (s) => {
	const p = s.ga, sport = 2600 + Math.floor(Math.random() * 300), oport = sport + 400;
	const slog = path.join(os.tmpdir(), 'e2e-osmtp-' + Date.now() + '.log'), olog = path.join(os.tmpdir(), 'e2e-oauth-' + Date.now() + '.log');
	const cols = 'set_smtp_host,set_smtp_port,set_smtp_username,set_smtp_password,set_smtp_from_address,set_smtp_use_ssl,set_smtp_use_authentication';
	const old = sql('SELECT CONCAT_WS(\',\',' + cols.split(',').map(c => "IFNULL(HEX(CAST(" + c + " AS CHAR)),'-')").join(',') + ') FROM zzzzsys_setup LIMIT 1').split(',');
	const keepSet = sql("SELECT CONCAT_WS(',', IFNULL((SELECT HEX(setting_value) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'mail_auth_type'), '-'), IFNULL((SELECT HEX(setting_value) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'mail_oauth'), '-'))").split(',');
	const smtp = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), slog, String(sport)], { stdio: 'ignore' });
	const prov = require('child_process').spawn('python3', [path.join(__dirname, 'fakeoauth.py'), olog, String(oport)], { stdio: 'ignore' });
	const settings = async () => { await open(p, 'index.php?p=email&tab=settings'); };
	try {
		await new Promise(r => setTimeout(r, 700));
		sql("UPDATE zzzzsys_setup SET set_smtp_host='127.0.0.1', set_smtp_port=" + sport + ", set_smtp_username='', set_smtp_password='', set_smtp_from_address='noreply@example.com', set_smtp_use_ssl='0', set_smtp_use_authentication='1'");
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('mail_oauth')");
		await settings();
		expect(!await p.isVisible('#nuMailOAuth') || (await p.inputValue('#m_authtype')) === 'XOAUTH2', 'OAuth block hidden for other methods');
		await p.selectOption('#m_authtype', 'XOAUTH2');
		expect(await p.isVisible('#nuMailOAuth'), 'OAuth block shown');
		expect((await p.inputValue('#m_oredir')).endsWith('/oauth.php'), 'redirect URI');
		await p.selectOption('#m_oprov', 'other');
		await p.fill('#m_ocid', 'cid'); await p.fill('#m_osec', 'csec');
		await p.fill('#m_oauth', 'http://127.0.0.1:' + oport + '/authorize'); await p.fill('#m_otok', 'http://127.0.0.1:' + oport + '/token'); await p.fill('#m_oscope', 'smtp');
		await Promise.all([p.waitForURL(/oauth\.php|p=email/, { timeout: 15000 }), p.click('#nuMailOAuthConnect')]);
		await p.waitForURL(/p=email/, { timeout: 15000 }); await p.waitForLoadState('networkidle');
		const state = (await p.textContent('#nuMailOAuthState')).trim();
		expect(/tester@example\.com/.test(state), 'connected: ' + state);
		const stored = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'mail_oauth'");
		if (stored.includes('"plain:')) expect(await p.locator('#nuMailOAuth .alert-warning').count() === 1, 'no $nuConfigSecretKey: stored plain, with a warning');
		else expect(!/csec|rt-1|tok-/.test(stored) && stored.includes('"enc:'), 'secrets encrypted');
		// Check connection: login with OAuth 2, token hidden in the conversation
		await open(p, 'index.php?p=email&tab=test');
		await Promise.all([p.waitForNavigation(), p.click('#nuMailCheckBtn')]); await p.waitForLoadState('networkidle');
		const login = await p.$eval('#mailCheckResult [data-step="login"]', l => l.dataset.ok + ' ' + l.textContent.replace(/\s+/g, ' '));
		expect(/^1 .*OAuth 2/.test(login), 'check: ' + login);
		const conv = await p.evaluate(() => (document.querySelector('#mailCheckResult pre, #mailCheckResult details') || {}).textContent || '');
		expect(!/tok-\d/.test(conv) && !/dG9rLT/.test(conv) && (/AUTH XOAUTH2 \*{8}/.test(conv) || !/AUTH XOAUTH2/.test(conv)), 'token hidden in the conversation');
		let sl = fs.readFileSync(slog, 'utf8');
		expect(/XOAUTH2 tester@example\.com/.test(sl), 'XOAUTH2 with the mailbox: ' + sl.split('\n').join(' '));
		// the cached access token ran out → refreshed with the refresh token before the next login (here a test mail)
		sql("UPDATE zzzzsys_nunext_setting SET setting_value = JSON_SET(setting_value, '$.access_expires', 0) WHERE zzzzsys_nunext_setting_id = 'mail_oauth'");
		await open(p, 'index.php?p=email&tab=test');
		await p.fill('#t_to', 'someone@example.com');
		await Promise.all([p.waitForNavigation(), p.locator('#t_to').evaluate(i => i.form.requestSubmit())]); await p.waitForLoadState('networkidle');
		const ol = fs.readFileSync(olog, 'utf8');
		expect(/grant_type=authorization_code/.test(ol) && /grant_type=refresh_token/.test(ol), 'token requests: ' + ol.split('\n').join(' | '));
		sl = fs.readFileSync(slog, 'utf8');
		expect(/^DATA$/m.test(sl), 'test mail sent with OAuth 2: ' + sl.split('\n').join(' '));
		// an answer that does not belong to this session
		await open(p, 'oauth.php?code=code-1&state=foreign');
		expect(/p=email/.test(p.url()) && /\.alert-danger|toast/.test(await p.innerHTML('body')), 'foreign state refused');
		// disconnect
		await settings();
		await p.click('#nuMailOAuthDisconnect');
		const ok = p.locator('.modal.show .modal-footer .btn-danger').first();
		await ok.waitFor({ state: 'visible', timeout: 5000 });
		await Promise.all([p.waitForNavigation(), ok.click()]);
		await p.waitForLoadState('networkidle');
		expect(!/tester@/.test(await p.textContent('#nuMailOAuthState')), 'disconnected');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		smtp.kill(); prov.kill();
		sql('UPDATE zzzzsys_setup SET ' + cols.split(',').map((c, i) => c + '=' + (old[i] === '-' ? 'NULL' : "UNHEX('" + old[i] + "')")).join(','));
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('mail_auth_type', 'mail_oauth')");
		if (keepSet[0] !== '-') sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('mail_auth_type', UNHEX('" + keepSet[0] + "'))");
		if (keepSet[1] !== '-') sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('mail_oauth', UNHEX('" + keepSet[1] + "'))");
		for (const f of [slog, olog]) fs.rmSync(f, { force: true });
	}
};

// e-mail templates (0.8.432): code … Bcc fold away, the summary shows code · description · To, the state is remembered,
// an invalid field opens it again
T.emt_fold = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_email_template_id FROM zzzzsys_email_template WHERE COALESCE(emt_code,'') <> '' AND COALESCE(emt_template,'') <> '1' ORDER BY emt_code LIMIT 1");
	expect(id, 'a template');
	const url = 'index.php?p=emailtemplates&id=' + encodeURIComponent(id);
	await open(p, url);
	await p.evaluate(() => { try { localStorage.removeItem('nunext-emt-head-open'); } catch (e) { /* ignore */ } });
	await open(p, url);
	const isOpen = () => p.$eval('#emtHead', d => d.open);
	expect(await isOpen(), 'open at first');
	await p.click('#emtHead > summary'); await p.waitForTimeout(200);
	const info = await p.textContent('#emtHeadInfo');
	expect(!await isOpen() && info.includes(await p.inputValue('#emt_code')), 'folded, summary: ' + info);
	await open(p, url);
	expect(!await isOpen(), 'remembered');
	await p.evaluate(() => { document.getElementById('emt_code').value = ''; });
	await p.evaluate(() => document.getElementById('emt_code').form.checkValidity()); await p.waitForTimeout(200);
	expect(await isOpen(), 'an invalid field opens it');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-emt-head-open'); } catch (e) { /* ignore */ } });
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
	expect(await p.locator('.nuLfPerBtn').count() === 0 || !/\b7 /.test(await p.textContent('.nuLfPerBtn')), 'per=7 is not a choice');
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
		return p.evaluate(() => ({ names: [...document.querySelectorAll('td.nuCell[data-nu-column="1"]')].map(t => t.textContent.trim()),
			marks: [...document.querySelectorAll('td.nuCell mark.nuHighlight')].map(m => m.textContent), cols: [...new Set([...document.querySelectorAll('td.nuCell mark.nuHighlight')].map(m => m.closest('td').dataset.nuColumn))] }));
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

// form info (0.8.435): the globeadmin's (i) next to Delete – form data in browse, plus record ID and created / changed in edit; copy buttons
T.form_info = async (s) => {
	const p = s.ga, id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	const keys = () => p.$$eval('#nuFormInfo .nuFormInfoRow', rs => rs.map(r => r.dataset.key));
	const val = k => p.textContent('#nuFormInfo .nuFormInfoRow[data-key="' + k + '"] dd').then(t => t.trim());
	await open(p, 'index.php?p=form&f=samplecustomer');
	await p.click('#nuFormInfoBtn');
	let k = await keys();
	expect(['code', 'description', 'type', 'form_id', 'table', 'pk', 'browse_sql'].every(x => k.includes(x)) && !k.includes('record_id'), 'browse: ' + k);
	expect(await val('table') === 'sample_customer' && await val('form_id') === 'samplecustomer', 'browse values');
	// created / changed from the audit trail (rows written here and removed again)
	const aid = 'e2efi' + Date.now().toString(36);
	const ins = (n, at, act, login) => sql("INSERT INTO zzzzsys_nunext_audit (zzzzsys_nunext_audit_id, change_id, changed_at, login, action, table_name, record_id, column_name) VALUES ('" + aid + n + "', '" + aid + n + "', '" + at + "', '" + login + "', '" + act + "', 'sample_customer', '" + id + "', '')");
	try {
		ins('a', '2026-01-02 03:04:05', 'insert', 'e2e_maker'); ins('b', '2099-02-03 04:05:06', 'update', 'e2e_changer');   // later than any change of earlier tests
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + encodeURIComponent(id));
		await p.click('#nuFormInfoBtn');
		expect(await val('record_id') === id, 'record id: ' + await val('record_id'));
		expect(/02\.01\.2026 03:04:05 · e2e_maker/.test(await val('created')) && /03\.02\.2099 04:05:06 · e2e_changer/.test(await val('changed')), 'created / changed: ' + await val('created') + ' | ' + await val('changed'));
		const copied = await p.evaluate(() => { const b = document.querySelector('#nuFormInfo [data-key="record_id"] [data-nu-copy-text]'); return b && b.getAttribute('data-nu-copy-text'); });
		const all = await p.getAttribute('#nuFormInfoCopyAll', 'data-nu-copy-text');
		expect(copied === id && all.includes('Table: sample_customer') && all.includes(id), 'copy texts: ' + all);
	} finally { sql("DELETE FROM zzzzsys_nunext_audit WHERE zzzzsys_nunext_audit_id LIKE '" + aid + "%'"); }
	await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
	expect(await p.locator('#nuFormInfo [data-key="created"]').count() === 0, 'new record: no created / changed');
	// not for other users
	sql("DELETE FROM zzzzsys_nunext_admin_user WHERE zzzzsys_nunext_admin_user_id = 'sample_ben'");
	const u = await login('sample_ben', UPW);
	await open(u, 'index.php?p=form&f=samplecustomer&r=' + encodeURIComponent(id));
	expect(await u.locator('#nuFormInfoBtn').count() === 0, 'a normal user sees the form info');
	await u.context().close();
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// user form tabs General / Permission items (0.8.438): the tab is kept in the address and after Save; the count follows the ticks
T.user_tabs = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!id || +sql('SELECT COUNT(*) FROM zzzzsys_permission_item') === 0) { s.skip = 'no sample user or permission items'; return; }
	await open(p, 'index.php?p=user&id=' + id);
	expect(await p.isVisible('#userTabGeneral') && !await p.isVisible('#userPerms'), 'General first');
	await p.click('#userTabPermBtn'); await p.waitForTimeout(300);
	expect(new URL(p.url()).searchParams.get('tab') === 'perm' && await p.isVisible('#userPerms'), 'Permission items tab: ' + p.url());
	const n0 = +await p.textContent('#userPermTabCount');
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

// form builder (0.8.439): the preview of a Browse and edit form shows the list or a record – remembered per form, browse settings switch to the list
T.builder_preview_mode = async (s) => {
	const p = s.ga;
	const rec = () => p.$eval('#nbPreview', f => new URL(f.getAttribute('src') || 'x', location.href).searchParams.get('r'));   // record of the preview (null = list)
	await open(p, 'index.php?p=builder&f=samplecustomer');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-builder-pvmode-samplecustomer'); } catch (e) { /* ignore */ } });
	await open(p, 'index.php?p=builder&f=samplecustomer'); await p.waitForTimeout(800);
	expect(await p.isVisible('#nbPreviewMode') && (await rec()), 'edit first');
	await p.click('#nbPreviewMode [data-mode=browse]'); await p.waitForTimeout(1200);
	expect(!(await rec()) && await p.$eval('#nbPreviewRecord', i => i.disabled) && await p.frameLocator('#nbPreview').locator('tbody tr').count() > 0, 'browse: list in the preview');
	await open(p, 'index.php?p=builder&f=samplecustomer'); await p.waitForTimeout(800);
	expect(!(await rec()), 'remembered');
	await p.click('#nbPreviewMode [data-mode=edit]'); await p.waitForTimeout(500);
	await p.click('.nbSections [data-nb-section=browse]'); await p.waitForTimeout(500);
	expect(!(await rec()), 'browse columns switch to the list');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-builder-pvmode-samplecustomer'); } catch (e) { /* ignore */ } });
	await open(p, 'index.php?p=builder&f=sampleorderitem'); await p.waitForTimeout(500);
	expect(!await p.isVisible('#nbPreviewMode'), 'no switch for a subform');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// nuconfig.php editor (0.8.442): Insert variable lists variables the text does not set, inserts at the cursor / the end, own variables;
// nothing is saved (the file stays as it is)
T.config_insert = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=configfile');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=configfile'); }
	if (!await p.locator('#cfgInsertBtn').count()) { s.skip = 'nuconfig.php not writable'; return; }
	const val = () => p.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.getValue());
	const before = await val();
	const set = await p.evaluate(() => { const m = {}; document.querySelector('.CodeMirror').CodeMirror.getValue().replace(/^[ \t]*\$(\w+)\s*=/gm, (a, n) => { m[n] = 1; }); return m; });
	await p.click('#cfgInsertBtn'); await p.waitForTimeout(400);
	const offered = await p.$$eval('#cfgInsList .cfgInsItem', b => b.map(x => x.dataset.name));
	expect(offered.length > 0 && offered.every(n => !set[n]), 'only variables not set: ' + offered.filter(n => set[n]));
	expect(!offered.includes('nuConfigAllowExistingNuDB'), 'hidden switch offered');
	expect(offered.includes('nuConfigCSVSeparator') || set.nuConfigCSVSeparator, 'code-only variable missing');
	// search, then insert one (no cursor placed → at the end, before a closing ?>)
	const pick = offered.includes('nuConfigCSVSeparator') ? 'nuConfigCSVSeparator' : offered[0];
	await p.fill('#cfgInsSearch', pick);
	expect(await p.locator('#cfgInsList .cfgInsItem').count() >= 1, 'search');
	await p.click('#cfgInsList .cfgInsItem[data-name="' + pick + '"]'); await p.waitForTimeout(200);
	let v = await val();
	expect(new RegExp('^\\$' + pick + ' = .*;', 'm').test(v) && !/\?>\s*$/.test(v.split('$' + pick)[1].split('\n')[0]), 'inserted: ' + pick);
	expect(await p.locator('#cfgInsList .cfgInsItem[data-name="' + pick + '"]').count() === 0, 'no longer offered');
	// own variable: bad name, then a text value with a quote
	await p.fill('#cfgInsName', '1bad'); await p.click('#cfgInsAdd');
	expect((await p.textContent('#cfgInsErr')).length > 0, 'bad name refused');
	await p.fill('#cfgInsName', 'e2eOwnVar'); await p.selectOption('#cfgInsType', 'text'); await p.fill('#cfgInsValue', "it's"); await p.fill('#cfgInsComment', 'from the test ?> end');
	await p.click('#cfgInsAdd'); await p.waitForTimeout(200);
	v = await val();
	expect(v.includes("$e2eOwnVar = 'it\\'s';") && !/e2eOwnVar.*\?>/.test(v), 'own variable: ' + (v.match(/.*e2eOwnVar.*/) || [''])[0]);
	// the editor's check accepts the result
	const chk = await p.evaluate(async () => { const f = document.getElementById('cfgForm'); document.querySelector('.CodeMirror').CodeMirror.save(); const fd = new FormData(f); fd.set('action', 'precheck'); const r = await fetch(f.getAttribute('action'), { method: 'POST', body: fd, headers: { Accept: 'application/json' } }); return r.json(); });
	expect(chk.ok, 'precheck: ' + JSON.stringify(chk));
	await p.evaluate(b => document.querySelector('.CodeMirror').CodeMirror.setValue(b), before);   // leave without changes
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
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
		out.top = w.style.top === '400px' && $('#cus_name').css('top') === '400px' && !document.getElementById('cus_name').style.top && !document.getElementById('label_cus_name').style.top;
		$('#cus_name').css('left', 250);
		out.left = w.style.left === '250px';
		out.other = $('#nuSaveButton').css('top') === '300px';   // elements outside a wrapper keep their own position
		return out;
	});
	expect(r.btn && r.top && r.left && r.other, 'save button / css mapping: ' + JSON.stringify(r));
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

// options menu (0.8.446): add, print, search, form info, form properties and object list only as keyboard shortcuts – they still work
T.options_menu = async (s) => {
	const p = s.ga;
	const items = () => p.$$eval('.nuOptionsList [data-nu-shortcut]', b => b.map(x => x.textContent.trim()));
	const keys = () => p.$$eval('.nuOptionsList [data-nu-shortcut]', b => b.map(x => x.getAttribute('data-nu-shortcut')));
	await open(p, 'index.php?p=form&f=samplecustomer');
	await p.click('#nuOptions'); await p.waitForTimeout(200);
	let k = await keys();
	expect(!k.includes('a') && !k.includes('p') && !k.includes('s') && !k.includes('f') && !k.includes('o') && !k.includes('m') && k.includes('r'), 'browse menu: ' + await items());
	await p.keyboard.press('Escape');
	await Promise.all([p.waitForNavigation(), p.keyboard.press('Control+Shift+A')]);
	expect(/[?&]r=-1\b/.test(p.url()), 'Ctrl+Shift+A still adds: ' + p.url());
	const id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + encodeURIComponent(id));
	await p.click('#nuOptions'); await p.waitForTimeout(200);
	k = await keys();
	expect(!k.includes('m') && !k.includes('f') && !k.includes('o') && !k.includes('a') && !k.includes('s') && !k.includes('y') && !k.includes('c') && !k.includes('l') && k.includes('r'), 'edit menu: ' + await items());
	// 0.8.517: no "Arrange objects", Save, Delete, Clone in the menu (buttons on the page do the same; the shortcuts stay) – the form
	// builder button opens a new tab
	if (await p.locator('#nuFormBuilderBtn').count()) expect(await p.getAttribute('#nuFormBuilderBtn', 'target') === '_blank', 'form builder button not in a new tab');
	await p.keyboard.press('Escape'); await p.waitForTimeout(200);
	await p.keyboard.press('Control+Shift+M'); await p.waitForTimeout(300);
	expect(await p.isVisible('#nuFormInfo'), 'Ctrl+Shift+M opens the form info panel');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
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
		expect(r.ok, 'save in the draft: ' + JSON.stringify(r).slice(0, 160));
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
T.page_back = async (s) => {                        // 0.8.422: forms opened from a page form go Back to the page (or to its launch form)
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const p = s.ga;
	const F = 'e2epb' + Date.now().toString(36), dir = path.join(SITE, 'custom', 'pages', F), id = 'e2epb' + Date.now().toString(36).slice(-6);
	fs.mkdirSync(dir, { recursive: true });
	fs.writeFileSync(path.join(dir, 'index.php'), `<?php
defined('NU_PAGE') || exit;
if ($nu->action() === 'url') $nu->json(['url' => $nu->formUrl('samplecustomer')]);
echo '<!doctype html><title>pb</title><a id="go" target="_top" href="' . $nu->h($nu->formUrl('samplecustomer')) . '">go</a> <button id="js" type="button">js</button>' . $nu->sdk()
	. '<script src="' . $nu->h($nu->asset('assets/a.js')) . '"></script>';
`);
	fs.mkdirSync(path.join(dir, 'assets'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'assets', 'a.js'), "document.getElementById('js').addEventListener('click', function () { NuPage.openForm('samplecustomer', '-1'); });");
	sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_status) VALUES ('${id}', 'page', '${F}', 'E2E back page', '1');
		INSERT INTO zzzzsys_nunext_page (zzzzsys_nunext_page_id, open_mode, iframe_height, settings) VALUES ('${id}', 'iframe', 'auto', '{}')`);
	const back = async () => p.evaluate(() => ({ back: document.getElementById('nuBackButton')?.getAttribute('href') || '', crumbs: [...document.querySelectorAll('.breadcrumb-item')].map(x => x.textContent.trim()) }));
	try {
		// on its own page: a link with formUrl()
		await open(p, 'index.php?p=form&f=' + F);
		const fr = p.frameLocator('#nuPageFrame');
		await fr.locator('#go').click(); await p.waitForURL(/f=samplecustomer/, { timeout: 8000 }).catch(() => { throw new Error('link did not open the form: ' + p.url()); }); await p.waitForLoadState('networkidle');
		let b = await back();
		expect(b.back.includes('f=' + F) && b.crumbs.includes('E2E back page'), 'link: Back / breadcrumbs ' + JSON.stringify(b));
		// NuPage.openForm() through the window around the page
		await open(p, 'index.php?p=form&f=' + F);
		await p.frameLocator('#nuPageFrame').locator('#js').click(); await p.waitForURL(/f=samplecustomer/, { timeout: 8000 }).catch(() => { throw new Error('openForm did not open the form: ' + p.url()); }); await p.waitForLoadState('networkidle');
		b = await back();
		expect(b.back.includes('f=' + F) && /r=-1/.test(p.url()), 'openForm: ' + JSON.stringify(b) + ' ' + p.url());
		// in a launch form's iframe (pf): Back goes to the launch form, the breadcrumb shows its description
		const r = await p.evaluate(async (u) => (await fetch(u, { headers: { Accept: 'application/json' } })).json(), BASE + 'index.php?p=page&f=' + F + '&action=url&pf=samplecustomer&pr=-1');
		expect(/[?&]bt=[0-9a-f]{12}/.test(r.url), 'formUrl without trail: ' + r.url);
		await open(p, r.url.replace(/^index\.php/, 'index.php'));
		b = await back();
		const desc = sql("SELECT sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		expect(b.back.includes('f=samplecustomer') && b.crumbs.includes(desc), 'embedded: ' + JSON.stringify(b));
	} finally {
		sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'`);
		fs.rmSync(dir, { recursive: true, force: true });
	}
};

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
	// 0.8.402: Delete on the edit page (the list's ⋯ column was off-screen on phones – it stays visible now)
	const id = sql("SELECT zzzzsys_nunext_notification_id FROM zzzzsys_nunext_notification WHERE title = 'E2E notification'");
	await open(p, 'index.php?p=notifications&id=' + id);
	await p.click('#ntfDelete'); await p.waitForTimeout(500);
	await p.click('.js-confirm-ok'); await p.waitForLoadState('networkidle');
	expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_notification WHERE zzzzsys_nunext_notification_id = '" + id + "'") === '0', 'not deleted from the edit page');
};

// 0.8.510: "Exclude users" (also with Everybody) and the recipients as a sortable table in a dialogue
T.notify_exclude = async (s) => {
	const p = s.ga;
	try {
		await open(p, 'index.php?p=notifications&id=new');
		await p.fill('#ntf_title', 'E2E exclude');
		await p.locator('label[for=ntf_all]').click(); await p.waitForTimeout(600);
		const total = +(await p.textContent('#ntfCount')).replace(/\D+/g, '');
		const exWrap = p.locator('#aud_exclude').locator('xpath=..');
		expect(!(await exWrap.locator('.ts-wrapper').getAttribute('class')).includes('disabled'), 'Exclude users locked by Everybody');
		await exWrap.locator('.ts-control input').fill('sample_anna');
		await p.locator('.ts-dropdown .option[data-value="' + sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'") + '"]').click(); await p.keyboard.press('Escape');
		await p.waitForTimeout(700);
		expect(+(await p.textContent('#ntfCount')).replace(/\D+/g, '') === total - 1, 'count did not drop by one: ' + await p.textContent('#ntfCount'));
		await p.click('#ntfCountShow'); await p.waitForSelector('#ntfPeopleDlg.show');
		const col = (i) => p.$$eval('#ntfPeopleTable tbody tr', (trs, i) => trs.map(t => t.children[i].textContent), i);
		const logins = await col(1);
		expect(logins.length === total - 1 && !logins.includes('sample_anna') && logins.includes('sample_ben'), 'table rows: ' + logins.join(','));
		const names = await col(0);
		expect(names.some(n => / /.test(n)), 'no full names in the table: ' + names.slice(0, 3).join(','));
		await p.click('#ntfPeopleTable .nuNtfSort[data-col="1"]');
		const asc = await col(1), sorted = asc.slice().sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
		expect(JSON.stringify(asc) === JSON.stringify(sorted) && await p.getAttribute('#ntfPeopleTable th:nth-child(2)', 'aria-sort') === 'ascending', 'login sort asc');
		await p.click('#ntfPeopleTable .nuNtfSort[data-col="1"]');
		expect(JSON.stringify(await col(1)) === JSON.stringify(sorted.slice().reverse()), 'login sort desc');
		await p.keyboard.press('Escape'); await p.waitForTimeout(400);
		await p.click('#ntfPublish'); await p.waitForLoadState('networkidle');
		const aud = JSON.parse(sql("SELECT audience FROM zzzzsys_nunext_notification WHERE title = 'E2E exclude'"));
		expect(aud.all === true && aud.exclude.length === 1, 'stored audience: ' + JSON.stringify(aud));
		const a = await login('sample_anna', UPW);
		await open(a, 'index.php?p=notifications');
		expect(await a.locator('.nuNtfItem', { hasText: 'E2E exclude' }).count() === 0, 'excluded user got it');
		await a.context().close();
		const b = await login('sample_ben', UPW);
		await open(b, 'index.php?p=notifications');
		expect(await b.locator('.nuNtfItem', { hasText: 'E2E exclude' }).count() === 1, 'other user did not get it');
		await b.context().close();
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE u FROM zzzzsys_nunext_notification_user u JOIN zzzzsys_nunext_notification n ON n.zzzzsys_nunext_notification_id = u.notification_id WHERE n.title = 'E2E exclude'");
		sql("DELETE FROM zzzzsys_nunext_notification WHERE title = 'E2E exclude'");
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

// 0.8.511: access level – the note on $nuConfig2FAUser (and its switch-on button) only while "Use two-factor authentication" is on
T.access_2fa_note = async (s) => {
	const p = s.ga;
	const id = sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE COALESCE(sal_use_2fa, '0') <> '1' ORDER BY sal_code LIMIT 1");
	if (!id) { s.skip = 'no access level without 2FA'; return; }
	await open(p, 'index.php?p=accesslevel&id=' + encodeURIComponent(id));
	expect(await p.locator('#nu2faCfgNote').count() === 1 && !(await p.locator('#nu2faCfgNote').isVisible()), 'note shown while the switch is off');
	await p.locator('label[for=sal_use_2fa]').click(); await p.waitForTimeout(200);
	expect(await p.locator('#nu2faCfgNote').isVisible(), 'note not shown after switching on');
	await p.locator('label[for=sal_use_2fa]').click(); await p.waitForTimeout(200);
	expect(!(await p.locator('#nu2faCfgNote').isVisible()), 'note still shown after switching off');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
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

// 0.8.518: input buttons and run buttons of forms look the same (class nuFormBtn); an input button keeps the object's height
T.form_buttons = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=sampleall&r=sample_all_1');
	await p.locator('.nuTab', { hasText: 'Miscellaneous' }).click(); await p.waitForTimeout(300);
	const st = id => p.evaluate(i => { const e = document.getElementById(i), c = getComputedStyle(e), r = e.getBoundingClientRect();
		return { cls: e.classList.contains('nuFormBtn'), bg: c.backgroundColor, border: c.borderTopColor, radius: c.borderTopLeftRadius, color: c.color, h: Math.round(r.height) }; }, id);
	const a = await st('all_spin_wait'), b = await st('all_spin_run');
	expect(a.cls && b.cls, 'class nuFormBtn missing: ' + JSON.stringify([a, b]));
	expect(a.bg === b.bg && a.border === b.border && a.radius === b.radius && a.color === b.color, 'input and run buttons differ: ' + JSON.stringify([a, b]));
	expect(a.h === 30 && b.h === 30, 'heights (object 30 px): ' + JSON.stringify([a.h, b.h]));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.520: a record opened anew starts on the first tab; the last tab is kept only for the same record (reload, Save)
T.form_first_tab = async (s) => {
	const p = s.ga;
	const active = () => p.evaluate(() => { const a = document.querySelector('.nuTab.active'); return a ? a.getAttribute('data-nu-tab') : null; });
	await open(p, 'index.php?p=form&f=sampleall');
	await Promise.all([p.waitForNavigation(), p.locator('#nuBrowseTable tbody tr.nuBrowseRow').first().click()]);
	await p.locator('.nuTab', { hasText: 'Charts' }).click(); await p.waitForTimeout(300);
	const tab = await active();
	expect(tab !== '0', 'tab not switched');
	await p.reload(); await p.waitForLoadState('load'); await p.waitForTimeout(300);
	expect(await active() === tab, 'reload: tab not kept');
	await p.goBack(); await p.waitForLoadState('load');
	await open(p, 'index.php?p=form&f=sampleall');
	await Promise.all([p.waitForNavigation(), p.locator('#nuBrowseTable tbody tr.nuBrowseRow').first().click()]);
	await p.waitForTimeout(300);
	expect(await active() === '0', 'opened again from the list: not on the first tab (' + await active() + ')');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.521: form builder – "Show tab order" numbers the preview's objects in Tab key order; off again; remembered in this browser
T.builder_taborder = async (s) => {
	const p = s.ga;
	await p.evaluate(() => { try { localStorage.removeItem('nunext-builder-taborder'); } catch (e) {} });
	await open(p, 'index.php?p=builder&f=samplecustomer');
	const frame = async () => { for (let i = 0; i < 40; i++) { const f = p.frames().find(x => /builder=1/.test(x.url())); if (f && await f.locator('#nuEditForm').count()) return f; await p.waitForTimeout(250); } return null; };
	let f = await frame();
	expect(f && await f.locator('.nuDesignOrder').count() === 0, 'numbers shown before the button');
	await p.click('#nbTabOrder'); await p.waitForTimeout(400);
	const nums = await f.locator('.nuDesignOrder').allTextContents();
	expect(nums.length >= 3 && nums.every((n, i) => n === String(i + 1)), 'numbers: ' + JSON.stringify(nums));
	const first = await f.evaluate(() => document.querySelector('.nuDesignOrder').closest('.nuWrap').id);
	expect(first === 'nuw_cus_code', 'first stop: ' + first);
	expect(await p.getAttribute('#nbTabOrder', 'aria-pressed') === 'true', 'button not pressed');
	await p.click('#nbPreviewReload'); await p.waitForTimeout(1500); f = await frame();
	await p.waitForTimeout(500);
	expect(await f.locator('.nuDesignOrder').count() === nums.length, 'numbers gone after reloading the preview');
	await p.click('#nbTabOrder'); await p.waitForTimeout(300);
	expect(await f.locator('.nuDesignOrder').count() === 0, 'numbers not removed');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.523: Custom code → Global JS & CSS – files on every page of the chosen scopes, page hook nuOnPageLoad / nu:pageload with the page
// name (form pages: after nuOnLoad), never on the editor and the Update page, ?safe=1, sign-in pages only when chosen, hook name warning
T.custom_code = async (s) => {
	const p = s.ga;
	const del = () => sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('custom_js', 'custom_css', 'custom_scope')");
	const setCm = (k, v) => p.evaluate(([k, v]) => { const ta = document.getElementById('scode-' + k); ta.nextElementSibling.CodeMirror.setValue(v); }, [k, v]);
	const openPage = async (hash) => {
		await open(p, 'index.php?p=setupcode' + (hash || ''));
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=setupcode' + (hash || '')); }
	};
	const look = (u) => open(p, u).then(() => p.waitForTimeout(250)).then(() => p.evaluate(() => ({ page: document.body.dataset.nuPage, mode: document.body.dataset.nuMode || '',
		form: document.body.dataset.nuForm || '', admin: document.body.dataset.nuAdmin || '', hook: window.e2eHook || null, ev: window.e2eEv || null,
		css: getComputedStyle(document.body).getPropertyValue('--e2e-gcss').trim(), order: (window.e2eOrder || []).join('>') })));
	del();
	try {
		await openPage('#gjs');
		expect(await p.locator('#scodePane-gjs.active').count() === 1, 'Global JS tab from the address');
		await setCm('gjs', "window.e2eOrder = [];\nfunction nuOnPageLoad(page, info) { window.e2eHook = page + '|' + (info.mode || '') + '|' + (info.form || ''); window.e2eOrder.push('page'); }\n"
			+ "document.addEventListener('nu:pageload', function (e) { window.e2eEv = e.detail.page; });\nfunction nuOnLoad() { window.e2eOrder.push('global-onload'); }");
		await p.click('#scodeTabBtn-gcss'); await p.waitForTimeout(200);
		await setCm('gcss', 'body { --e2e-gcss: 7px; }');
		await Promise.all([p.waitForNavigation(), p.click('#scodeSave')]);
		expect(/custom_js/.test(sql("SELECT GROUP_CONCAT(zzzzsys_nunext_setting_id) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id LIKE 'custom_%'")), 'not stored');
		expect(await p.locator('.alert-warning', { hasText: 'nuOnLoad' }).count() >= 1, 'no warning about the nuBuilder hook name');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_setup WHERE set_header LIKE '%e2eHook%'") === 0, 'written into nuBuilder\'s setup');
		// the editor itself: no global code
		let r = await look('index.php?p=setupcode');
		expect(r.page === 'setupcode' && r.hook === null && r.css === '', 'editor page loads the global code: ' + JSON.stringify(r));
		// an app page: hook, event, CSS
		r = await look('index.php?p=users');
		expect(r.page === 'users' && r.hook === 'users||' && r.ev === 'users' && r.css === '7px' && r.admin === '1', 'users page: ' + JSON.stringify(r));
		r = await look('index.php?p=update');
		expect(r.page === 'update' && r.hook === null && r.css === '', 'update page: ' + JSON.stringify(r));
		r = await look('index.php?p=users&safe=1');
		expect(r.hook === null && r.css === '', 'safe mode: ' + JSON.stringify(r));
		// a form page: mode and form code; the form's own nuOnLoad wins, nuOnPageLoad comes after it
		r = await look('index.php?p=form&f=samplecustomer');
		expect(r.page === 'form' && r.mode === 'browse' && r.form === 'sample_customer' && r.hook === 'form|browse|sample_customer' && r.css === '7px', 'form page: ' + JSON.stringify(r));
		// scope: form pages off → no code there, still on other pages; sign-in pages only when switched on
		await openPage('#gjs');
		await p.locator('#scodeScope-gjs-forms').uncheck();
		await Promise.all([p.waitForNavigation(), p.click('#scodeSave')]);
		r = await look('index.php?p=form&f=samplecustomer');
		expect(r.hook === null && r.css === '', 'forms switched off: ' + JSON.stringify(r));
		const anon = await browser.newContext(); const a = await anon.newPage();
		await a.goto(BASE + 'index.php?p=login'); await a.waitForTimeout(250);
		let lg = await a.evaluate(() => ({ page: document.body.dataset.nuPage, hook: window.e2eHook || null }));
		expect(lg.page === 'login' && lg.hook === null, 'sign-in page without the scope: ' + JSON.stringify(lg));
		expect((await a.goto(BASE + 'index.php?p=customcode&t=js')).status() === 403, 'file readable without a session');
		await openPage('#gcss');
		await p.locator('#scodeScope-gcss-login').check();
		expect(await p.locator('#scodeScope-gjs-login').isChecked(), 'switches not in step');
		await Promise.all([p.waitForNavigation(), p.click('#scodeSave')]);
		await a.goto(BASE + 'index.php?p=login'); await a.waitForTimeout(250);
		lg = await a.evaluate(() => ({ page: document.body.dataset.nuPage, hook: window.e2eHook || null }));
		expect(lg.hook === 'login||', 'sign-in page with the scope: ' + JSON.stringify(lg));
		await anon.close();
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { del(); }
};

// 0.8.524: Custom code templates – ▾ Templates on every tab inserts at the cursor (red Save), "Preview all" shows description + code
T.custom_code_templates = async (s) => {
	const p = s.ga;
	const openPage = async (hash) => {
		await open(p, 'index.php?p=setupcode' + hash);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=setupcode' + hash); }
	};
	await openPage('#gjs');
	for (const tab of ['header', 'style', 'include', 'gjs', 'gcss']) {
		if (!await p.locator('#scodePane-' + tab).count()) continue;
		expect(await p.locator(`#scodePane-${tab} .scodeTplMenu .dropdown-item`).count() >= 3, 'no templates on ' + tab);
	}
	const val = () => p.evaluate(() => document.getElementById('scode-gjs').value);
	const before = await val();
	await p.click('#scodePane-gjs .scodeTpl .dropdown-toggle');
	await p.locator('#scodePane-gjs .scodeTplMenu .dropdown-item', { hasText: /skeleton|Gerüst/ }).first().click();
	await p.waitForTimeout(200);
	expect((await val()).includes('function nuOnPageLoad(page, info)') && await p.locator('#scodeSave.nuSaveEdited').count() === 1, 'template not inserted / Save not red');
	await p.click('#scodePane-gjs .scodeTpl .dropdown-toggle');
	await p.locator('#scodePane-gjs .scodeTplMenu .dropdown-item').last().click();
	await p.waitForSelector('#scodeTplDlg.show');
	const items = await p.locator('#scodeTplList .list-group-item-action').count();
	await p.locator('#scodeTplList .list-group-item-action').nth(1).click();
	const code = await p.textContent('#scodeTplCode');
	expect(items >= 5 && code.length > 20 && (await p.textContent('#scodeTplDesc')).length > 5, 'preview dialogue: ' + items);
	await p.click('#scodeTplInsert'); await p.waitForTimeout(400);
	expect((await val()).includes(code.trim().split('\n')[0]) && !(await p.locator('#scodeTplDlg.show').count()), 'Insert from the dialogue');
	expect(before === '' || (await val()).startsWith(before.trim().split('\n')[0]), 'existing code changed');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	await p.evaluate(() => { window.nuLeaveGuard.isDirty = () => false; });   // leave without saving (no beforeunload question)
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

// 0.8.528: F1 opens the documentation page of the page that is open (new tab); pages without one leave F1 alone
T.help_f1 = async (s) => {
	const p = s.ga;
	const f1 = async (path, want) => {
		await open(p, path);
		const [tab] = await Promise.all([p.context().waitForEvent('page', { timeout: 5000 }), p.keyboard.press('F1')]);
		await tab.waitForLoadState('domcontentloaded');
		const ok = /p=help/.test(tab.url()) && tab.url().endsWith('#' + want);
		await tab.close();
		expect(ok, path + ' → ' + tab.url());
	};
	await f1('index.php?p=users', 'users');
	await f1('index.php?p=form&f=samplecustomer', 'forms-use');
	await f1('index.php', 'home-page');
	await open(p, 'index.php?p=help');
	expect(await p.evaluate(() => document.body.getAttribute('data-nu-help')) === null, 'no F1 link on the help page');
	expect(await p.locator('.dropdown-menu a[href$="p=help"][target=_blank]').count() === 1, 'user menu: documentation opens in a new tab (0.8.541)');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.533: form builder – Style shows an example that follows the style type (CSS / Class), HTML attributes an example placeholder
T.builder_placeholders = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=builder&f=samplecustomer');
	await p.click('#nbObjectList li:has-text("cus_name")'); await p.waitForTimeout(600);
	const st = p.locator('[data-key=sob_all_style_type]'), sf = p.locator('[data-key=sob_all_style]');
	await st.selectOption('CSS');
	const css = await sf.getAttribute('placeholder');
	await st.selectOption('Class');
	const cls = await sf.getAttribute('placeholder');
	await st.selectOption('');
	expect(/;/.test(css) && /fw-bold/.test(cls) && !(await sf.getAttribute('placeholder')), 'style placeholder: ' + css + ' | ' + cls);
	expect(/spellcheck=false/.test(await p.locator('[data-key=sob_input_attribute]').getAttribute('placeholder') || ''), 'attributes placeholder');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	await p.evaluate(() => { if (window.nuLeaveGuard) window.nuLeaveGuard.isDirty = () => false; });
};

// 0.8.535: form builder → Form settings → "Open records in form" is searchable (Tom Select); choosing a form marks the panel edited
T.builder_redirect_search = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=builder&f=samplecustomer');
	await p.click('.nbSections [data-nb-section=form]'); await p.waitForTimeout(500);
	const sel = p.locator('select[data-key=sfo_browse_redirect_form_id]');
	expect(await sel.evaluate(e => e.classList.contains('tomselected')), 'redirect form is a Tom Select');
	const before = await sel.inputValue();
	await p.click('.nbFormTabs .nav-link[data-tab=browse]');
	await p.waitForTimeout(300);
	const ctl = p.locator('select[data-key=sfo_browse_redirect_form_id] + .ts-wrapper .ts-control');
	await ctl.click(); await p.keyboard.type('order');
	const opt = p.locator('.ts-dropdown .option').filter({ hasText: /order/i }).first();
	await opt.waitFor(); await opt.click(); await p.waitForTimeout(300);
	const after = await sel.inputValue();
	expect(after && after !== before, 'value taken over by the select: ' + before + ' → ' + after);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	await p.evaluate(() => { if (window.nuLeaveGuard) window.nuLeaveGuard.isDirty = () => false; });
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
	// builder: lookup / sub-form objects of "Sample: all objects", the object ID check
	await open(p, 'index.php?p=builder&f=sampleall');
	for (const [type, key] of [['lookup', 'sob_lookup_zzzzsys_form_id'], ['subform', 'sob_subform_zzzzsys_form_id']]) {
		const id = sql("SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleall' AND sob_all_type = '" + type + "' LIMIT 1");
		if (!id) continue;
		await p.click('#nbObjectList li:has-text("' + id + '")'); await p.waitForTimeout(500);
		expect(await p.locator('select[data-key=' + key + '].tomselected').count() === 1, type + ': form select not searchable');
	}
	const idf = p.locator('input[data-key=sob_all_id]');
	const old = await idf.inputValue();
	await idf.fill('bad id-1');
	expect(await idf.evaluate(e => e.classList.contains('is-invalid')), 'invalid ID marked');
	await idf.fill('  ' + old + '  '); await idf.blur(); await p.waitForTimeout(200);
	expect(await idf.inputValue() === old && !(await idf.evaluate(e => e.classList.contains('is-invalid'))), 'ID trimmed on blur: "' + await idf.inputValue() + '"');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	await p.evaluate(() => { if (window.nuLeaveGuard) window.nuLeaveGuard.isDirty = () => false; });
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
		const cells = await p.locator('td.nuCell[data-nu-column="2"]').allTextContents();
		expect(cells.length > 0 && cells.every(c => c.trim() === cells[0].trim()), 'rows filtered: ' + cells.join(','));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("UPDATE zzzzsys_browse SET sbr_json = NULL WHERE zzzzsys_browse_id = 'sample_customer_b3'");
	}
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

// 0.8.543: setup wizard – picking a language switches the wizard (session language) at once, the choice stays selected
T.wizard_lang = async (s) => {
	const p = s.ga;
	const go = async () => {
		await open(p, 'index.php?p=setupwizard&step=basics');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=setupwizard&step=basics'); }
	};
	await go();
	const before = await p.evaluate(() => document.documentElement.lang);
	const orig = await p.inputValue('#lang');
	try {
		await Promise.all([p.waitForNavigation(), p.selectOption('#lang', 'fr')]);
		expect(await p.evaluate(() => document.documentElement.lang) === 'fr' && await p.inputValue('#lang') === 'fr' && /step=basics/.test(p.url()), 'wizard in French: ' + p.url());
	} finally {
		await Promise.all([p.waitForNavigation(), p.selectOption('#lang', orig)]);
		await open(p, 'index.php?p=lang&l=' + encodeURIComponent(before));
		await p.evaluate(() => fetch('index.php?p=setupwizard&step=basics'));
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.546: custom user fields as a system list – view menu, export, filters, (i) with the SQL / hash variable hint, move buttons
T.userfields_list = async (s) => {
	const p = s.ga;
	const T = 'zzzzsys_nunext_user_field';
	const had = sql("SELECT COUNT(*) FROM " + T) || '0';
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

T.notify_format = async (s) => {                        // 0.8.400: "Formatted text" switch – TinyMCE (when installed), HTML cleaned on the server
	const p = s.ga;
	await open(p, 'index.php?p=notifications&id=new');
	await p.fill('#ntf_title', 'E2E formatted');
	await p.fill('#ntf_body', 'Line one\nLine <two>');
	await p.locator('#aud_users').locator('xpath=..').locator('.ts-control input').fill('sample_anna');
	await p.locator('.ts-dropdown .option[data-value="sample_anna"]').click(); await p.keyboard.press('Escape');
	await p.check('#ntf_format');
	const tiny = !!(await p.getAttribute('#ntf_format', 'data-tiny'));
	const bad = '<p>Hello <strong>bold</strong> <a href="javascript:alert(1)">x</a> <a href="https://example.com">ok</a></p><script>window.pwned=1</script><img src=x onerror="window.pwned=1">';
	if (tiny) {
		await p.waitForSelector('.tox-tinymce', { timeout: 10000 });
		expect(/Line one<br>Line &lt;two&gt;/.test(await p.evaluate(() => window.tinymce.activeEditor.getContent())), 'plain text not taken over into the editor');
		await p.evaluate((h) => { const ed = window.tinymce.activeEditor; ed.setContent(h); ed.fire('change'); }, bad);
	} else {
		await p.evaluate((h) => { document.getElementById('ntf_body').value = h; }, bad);
	}
	await p.click('#ntfPublish'); await p.waitForLoadState('networkidle');
	const row = sql("SELECT CONCAT(format, '|', body) FROM zzzzsys_nunext_notification WHERE title = 'E2E formatted'");
	expect(row.startsWith('html|') && /<strong>bold<\/strong>/.test(row) && !/script|onerror|javascript|<img/i.test(row) && /href="https:\/\/example.com"/.test(row), 'stored HTML not cleaned: ' + row);
	// the server cleans HTML that does not come from the editor (posted directly)
	const a = await login('sample_anna', UPW);
	await open(a, 'index.php?p=notifications');
	const item = a.locator('.nuNtfItem', { hasText: 'E2E formatted' });
	expect(await item.locator('strong', { hasText: 'bold' }).count() === 1 && await item.locator('a[href="https://example.com"][target="_blank"]').count() === 1, 'formatting not shown to the recipient');
	expect(!(await a.evaluate(() => window.pwned)), 'script ran');
	await a.click('#nuBellBtn'); await a.waitForTimeout(700);
	expect(await a.locator('.nuBellItem strong', { hasText: 'bold' }).count() >= 1, 'formatting not in the bell');
	await a.context().close();
	// back to plain text: after the question the tags are gone
	const id = sql("SELECT zzzzsys_nunext_notification_id FROM zzzzsys_nunext_notification WHERE title = 'E2E formatted'");
	await open(p, 'index.php?p=notifications&id=' + id);
	expect(await p.isChecked('#ntf_format'), 'switch not on for a formatted notification');
	if (tiny) await p.waitForSelector('.tox-tinymce', { timeout: 10000 });
	p.once('dialog', (d) => d.accept().catch(() => {}));
	await p.uncheck('#ntf_format'); await p.waitForTimeout(400);
	const txt = await p.inputValue('#ntf_body');
	expect(/Hello bold/.test(txt) && !/</.test(txt) && await p.locator('.tox-tinymce').count() === 0, 'plain text after switching off: ' + txt);
	sql("DELETE FROM zzzzsys_nunext_notification_user WHERE notification_id = '" + id + "'");
	sql("DELETE FROM zzzzsys_nunext_notification WHERE zzzzsys_nunext_notification_id = '" + id + "'");
};

T.edit_conflict = async (s) => {                        // 0.8.401: concurrent edits – field check, dialog, sub-form rows, delete, "also open", off in Before Edit
	const A = s.ga, B = await login('sample_carla', UPW);
	const cus = 'index.php?p=form&f=samplecustomer&r=sample_cus_01';
	const val = (c) => sql("SELECT " + c + " FROM sample_customer WHERE sample_customer_id = 'sample_cus_01'");
	const save = async (p) => { await p.click('#nuSaveButton'); await p.waitForTimeout(1200); };
	try {
		await open(A, cus); await open(B, cus); await A.waitForTimeout(800);
		await open(A, cus); await A.waitForTimeout(800);                              // A sees B (the first ping of B is done)
		expect(/sample_carla/.test(await A.locator('#nuPresence').textContent().catch(() => '')), 'A does not see that B has the record open');
		// 1. both change the name, B also the e-mail, A the phone: dialog for the name only; "Mine" → B's name, A's phone, B's e-mail
		await A.fill('#cus_name', 'CC name A'); await A.fill('#cus_phone', '+41 1'); await save(A);
		await B.fill('#cus_name', 'CC name B'); await B.fill('#cus_email', 'b@example.com'); await save(B);
		expect(await B.locator('#nuConflict').count() === 1, 'no conflict dialog');
		const rows = await B.locator('#nuConflict tbody tr').allTextContents();
		expect(rows.length === 1 && /CC name A/.test(rows[0]) && /CC name B/.test(rows[0]), 'dialog rows: ' + JSON.stringify(rows));
		await B.click('.jconfirm-buttons button:first-child'); await B.waitForLoadState('networkidle'); await B.waitForTimeout(800);
		expect(val('cus_name') === 'CC name B' && val('cus_phone') === '+41 1' && val('cus_email') === 'b@example.com', 'after "Mine": ' + [val('cus_name'), val('cus_phone'), val('cus_email')]);
		// 2. "Theirs": the other value stays, B's other field is saved; the same value on both sides is no conflict
		await open(A, cus); await open(B, cus);
		await A.fill('#cus_name', 'CC name A2'); await A.fill('#cus_code', 'C001X'); await save(A);
		await B.fill('#cus_name', 'CC name B2'); await B.fill('#cus_code', 'C001X'); await B.fill('#cus_phone', '+41 2'); await save(B);
		expect(await B.locator('#nuConflict tbody tr').count() === 1, 'same value counted as conflict');
		await B.click('#nuConflict input[value="theirs"]');
		await B.click('.jconfirm-buttons button:first-child'); await B.waitForLoadState('networkidle'); await B.waitForTimeout(800);
		expect(val('cus_name') === 'CC name A2' && val('cus_phone') === '+41 2', 'after "Theirs": ' + [val('cus_name'), val('cus_phone')]);
		// 3. no change by others → saves at once
		await open(B, cus); await B.fill('#cus_phone', '+41 3'); await save(B);
		expect(await B.locator('#nuConflict').count() === 0 && val('cus_phone') === '+41 3', 'plain save blocked');
		// 4. sub-form rows: same field of a row; a row deleted by someone else
		const ord = 'index.php?p=form&f=sampleorder&r=sample_ord_1';
		const q1 = sql("SELECT ori_qty FROM sample_order_item WHERE sample_order_item_id = 'sample_ori_1'");
		await open(A, ord); await open(B, ord);
		const rowOf = (p, pk) => p.evaluate((k) => document.querySelector('#sample_items .nuSubformRow[data-nu-primary-key="' + k + '"]').getAttribute('data-nu-prefix'), pk);
		const pa = await rowOf(A, 'sample_ori_1'), pb = await rowOf(B, 'sample_ori_1'), pb2 = await rowOf(B, 'sample_ori_2');
		await A.fill('#' + pa + 'ori_qty', '7'); await save(A);
		sql("DELETE FROM sample_order_item WHERE sample_order_item_id = 'sample_ori_2'");
		await B.fill('#' + pb + 'ori_qty', '9'); await B.fill('#' + pb2 + 'ori_qty', '5'); await save(B);
		const txt = await B.locator('#nuConflict').textContent().catch(() => '');
		expect(/row 1/i.test(txt) && /deleted by someone else/.test(txt), 'sub-form conflict: ' + txt.slice(0, 300));
		await B.click('.jconfirm-buttons button:first-child'); await B.waitForLoadState('networkidle'); await B.waitForTimeout(800);
		expect(Number(sql("SELECT ori_qty FROM sample_order_item WHERE sample_order_item_id = 'sample_ori_1'")) === 9 && q1 !== '', 'sub-form row after "Mine"');
		// 5. delete after someone else changed the record: asked (the test accepts) – then deleted
		const cus7 = 'index.php?p=form&f=samplecustomer&r=sample_cus_07';
		await open(A, cus7); await open(B, cus7);
		await A.fill('#cus_phone', '+41 99'); await save(A);
		const asked = [];
		B.removeAllListeners('dialog'); B.on('dialog', d => { asked.push(d.message()); d.accept(); });
		await B.evaluate(() => { window.jQuery.confirm = null; });                   // the plain browser question for "Delete?"
		await B.evaluate(() => nuDeleteAction()); await B.waitForTimeout(1500);
		expect(asked.some(m => /Delete it anyway/.test(m)), 'no question before deleting a changed record: ' + JSON.stringify(asked));
		expect(sql("SELECT COUNT(*) FROM sample_customer WHERE sample_customer_id = 'sample_cus_07'") === '0', 'not deleted after "yes"');
		// 6. switched off in Before Edit: no signatures, no hint
		sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php) VALUES ('samplecustomer_BE', 'samplecustomer_BE', 'e2e', 'nuNextConflictCheck(false); nuNextPresence(false);')");
		await open(B, 'index.php?p=form&f=samplecustomer&r=sample_cus_02');
		expect(await B.evaluate(() => window.nuNext.cc === null && window.nuNext.presence === false), 'conflict check / hint still on after nuNextConflictCheck(false) / nuNextPresence(false)');
		await open(B, 'index.php?p=form&f=samplecustomer&r=sample_cus_03');
		expect(await B.locator('#nuPresence').count() === 0, 'hint although switched off');
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_BE'");
		await open(B, 'index.php?p=form&f=samplecustomer&r=sample_cus_03');
		expect(await B.evaluate(() => !!(window.nuNext.cc && window.nuNext.cc.h) && window.nuNext.presence === true), 'conflict check not on by default');
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_BE'");
		await B.context().close();
	}
};

T.form_ai = async (s) => {                              // 0.8.403: form with AI – prompt, check, new form as Draft, change as form draft
	const p = s.ga;
	const spec = {format: 'nunext-form-1', form: {code: 'e2e_ai_project', description: 'E2E projects', type: 'browseedit', table: 'e2e_ai_project', primary_key: 'e2e_ai_project_id'},
		tabs: [{title: 'Main', objects: [
			{id: 'prj_name', type: 'input', input: 'text', label: 'Name', required: true},
			{id: 'prj_customer_id', type: 'lookup', label: 'Customer', lookup: {form: 'sample_customer', code: 'cus_code', description: 'cus_name'}},
			{id: 'prj_start', type: 'input', input: 'nuDate', label: 'Start'},
			{id: 'prj_status', type: 'select', label: 'Status', options: [['open', 'Open'], ['done', 'Done']]}]}]};
	try {
		await open(p, 'index.php?p=forms');
		expect(await p.locator('#nuFormAiNew').count() === 1, 'no "With AI" button in the forms list');
		await open(p, 'index.php?p=formai');
		await p.fill('#faRequest', 'Projects with a customer');
		await p.click('#faCopy'); await p.waitForTimeout(800);
		const prompt = await p.inputValue('#faPrompt');
		expect(/nunext-form-1/.test(prompt) && /sample_customer/.test(prompt) && /Projects with a customer/.test(prompt), 'prompt incomplete');
		// errors are listed and nothing is offered
		await p.fill('#faAnswer', '```json\n{"format": "nunext-form-1", "form": {"code": "x y"}, "tabs": []}\n```'); await p.click('#faCheck'); await p.waitForTimeout(700);
		expect(await p.locator('#faMessages .alert-danger li').count() >= 2 && await p.locator('#faCreateBox').isHidden(), 'bad answer not refused');
		// a new form with a new table
		await p.fill('#faAnswer', 'Sure:\n```json\n' + JSON.stringify(spec) + '\n```'); await p.click('#faCheck'); await p.waitForTimeout(700);
		expect(await p.locator('#faOk').count() === 1 && /e2e_ai_project/.test(await p.textContent('#faPlan')), 'valid answer not accepted: ' + await p.textContent('#faMessages'));
		await p.click('#faCreate'); await p.waitForTimeout(500);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = 'e2e_ai_project'") === '0', 'created without ticking the table box');
		await p.check('#faDdl'); await p.click('#faCreate'); await p.waitForURL(/p=builder/); await p.waitForLoadState('networkidle');
		expect(sql("SELECT sfo_status FROM zzzzsys_form WHERE sfo_code = 'e2e_ai_project'") === '0', 'new form not a Draft');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id WHERE f.sfo_code = 'e2e_ai_project'") === '4', 'objects missing');
		expect(/prj_customer_id/.test(sql("SHOW COLUMNS FROM e2e_ai_project")), 'table not created');
		// change a form: a form draft, the live form stays
		await open(p, 'index.php?p=builder&f=samplecustomer'); await p.waitForTimeout(800);
		await p.click('#nbAi'); await p.waitForLoadState('networkidle');
		await p.fill('#faRequest', 'Add Mobile after Phone'); await p.click('#faCopy'); await p.waitForTimeout(800);
		const cur = JSON.parse((await p.inputValue('#faPrompt')).match(/## The current form\n```json\n([\s\S]*?)\n```/)[1]);
		const main = cur.tabs[0];
		main.objects.splice(main.objects.findIndex(o => o.id === 'cus_phone') + 1, 0, {id: 'e2e_mobile', type: 'input', input: 'tel', label: 'Mobile'});
		await p.fill('#faAnswer', JSON.stringify(cur)); await p.click('#faCheck'); await p.waitForTimeout(700);
		const plan = await p.textContent('#faPlan');
		expect(/e2e_mobile/.test(plan) && !/cus_name|cus_code/.test(plan.replace(/.*e2e_mobile/s, '')), 'plan of the change: ' + plan);
		await p.check('#faDdl'); await p.click('#faCreate'); await p.waitForURL(/draft=1/); await p.waitForLoadState('networkidle');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_id = 'e2e_mobile'") === '0', 'the live form was changed');
		expect(/e2e_mobile/.test(sql("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer' AND status = 'open'")), 'draft without the new object');
	} finally {
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = 'samplecustomer' AND status = 'open'");
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'e2e_ai_project'");
		if (fid) sql("DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '" + fid + "'; DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '" + fid + "'; DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '" + fid + "'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '" + fid + "'");
		sql("DROP TABLE IF EXISTS e2e_ai_project");
		try { sql("ALTER TABLE sample_customer DROP COLUMN e2e_mobile"); } catch (e) { /* not added */ }
	}
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
	await homeEdit(p); await p.waitForTimeout(400);
	await p.locator('.nuHeItem[data-key="users"] .nuHeTools button').filter({ has: p.locator('.bi-star') }).first().click();
	await p.click('#nuHomeSave'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
	await p.click('#nuHomeFavOnly'); await p.waitForTimeout(400);
	const shown = await p.locator('[data-nu-tile]').evaluateAll(a => a.filter(x => x.offsetParent !== null).map(x => x.getAttribute('data-nu-tile')));
	expect(shown.length === 1 && shown[0] === 'users', 'favourites only shows ' + shown.join(','));
	await p.click('#nuHomeFavOnly');
};

T.home_list2 = async (s) => {                      // 0.8.363: tiles as a list in two columns (0.8.420: View panel → List, 2 columns)
	const p = s.ga;
	const keep = sql("SELECT HEX(setting_value) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'home_view_ga'");
	try {
		await open(p, 'index.php?p=home&tiles=1');
		await homeTab(p, 'appearance');
		await p.click('#nuHomeViewPanel [data-f="style:list"]'); await p.waitForTimeout(200);
		await p.click('#nuHomeViewPanel [data-f="listCols:2"]'); await p.waitForTimeout(700);
		const pos = await p.evaluate(() => [...document.querySelector('.nuHomeGroup .nuHomeTiles').children].slice(0, 2).map(e => { const r = e.getBoundingClientRect(); return [Math.round(r.top), Math.round(r.left)]; }));
		expect(pos.length === 2 && pos[0][0] === pos[1][0] && pos[1][1] > pos[0][1], 'two columns: ' + JSON.stringify(pos));
		await open(p, 'index.php?p=home&tiles=1');                         // kept (own view of the globeadmin)
		expect(await p.evaluate(() => nuHome.view.style + '/' + nuHome.view.listCols) === 'list/2', 'own view not kept');
	} finally {
		if (keep && keep !== 'NULL') sql("UPDATE zzzzsys_nunext_setting SET setting_value = UNHEX('" + keep + "') WHERE zzzzsys_nunext_setting_id = 'home_view_ga'");
		else sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'home_view_ga'");
	}
};

T.home_view = async (s) => {                       // 0.8.420: View panel – styles, legacy layouts, own view on shared layouts, everyone, rights
	const p = s.ga;
	const keepIds = sql("SELECT GROUP_CONCAT(zzzzsys_nunext_list_view_id) FROM zzzzsys_nunext_list_view WHERE list_key = 'home'");
	const keepDefs = sql("SELECT HEX(GROUP_CONCAT(CONCAT(zzzzsys_nunext_list_view_id, CHAR(1), definition) SEPARATOR 0x02)) FROM zzzzsys_nunext_list_view WHERE list_key = 'home'");
	const keepSet = sql("SELECT HEX(GROUP_CONCAT(CONCAT(zzzzsys_nunext_setting_id, CHAR(1), setting_value) SEPARATOR 0x02)) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('listview_ga', 'listview_defaults', 'home_view_ga')");
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	const ujson = sql("SELECT HEX(sus_json) FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'");
	const errs = [];
	p.on('console', m => { if (/Content.Security|Refused to/.test(m.text())) errs.push(m.text()); });
	const post = (body) => p.evaluate(async b => { const D = JSON.parse(document.getElementById('nuHomeData').textContent);
		const r = await fetch(D.url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf }, body: JSON.stringify(b) }); return { status: r.status, j: await r.json() }; }, body);
	const panel = async () => { await homeTab(p, 'appearance'); };
	const pick = async f => { await p.click('#nuHomeViewPanel [data-f="' + f + '"]'); await p.waitForTimeout(200); };
	try {
		// a layout saved before 0.8.420 (size compact, display tabs) looks as before: compact tiles, tabs, the 800 ms tooltip
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'home_view_ga'");
		const def = JSON.stringify({ v: 2, display: 'tabs', size: 'compact', tabs: [{ id: 't_a', title: 'A' }, { id: 't_b', title: 'B' }],
			groups: [{ id: 'g_a', title: 'Ga', tab: 't_a', tiles: ['users', 'access'] }, { id: 'g_b', title: 'Gb', tab: 't_b', tiles: ['forms'] }], hidden: [], custom: {}, fav: [] });
		sql("INSERT INTO zzzzsys_nunext_list_view (zzzzsys_nunext_list_view_id, list_key, scope, owner, name, access_ids, definition, sort_order) VALUES ('e2e_hv', 'home', 'shared', '', 'E2E view', '[]', UNHEX('"
			+ Buffer.from(def).toString('hex') + "'), 99)");
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'listview_ga'");
		await open(p, 'index.php?p=home&tiles=1');
		await post({ action: 'layout_select', id: 'e2e_hv' });
		await open(p, 'index.php?p=home&tiles=1');
		let st = await p.evaluate(() => ({ view: document.getElementById('nuHomeView').className, pane: document.querySelector('.nuHomePane.nuHomeActive')?.className, tabs: document.querySelectorAll('[data-nu-tabbtn]').length,
			v: nuHome.view }));
		expect(/nuHomeNav-tabs/.test(st.view) && /nuHomeStyle-compact/.test(st.pane) && st.tabs === 2 && st.v.delay === 800, 'legacy layout: ' + JSON.stringify(st));
		// every style with every navigation draws without errors; the table can be sorted
		await panel();
		for (const nav of ['groups', 'tabs', 'sidebar', 'none']) for (const sty of ['large', 'cards', 'compact', 'list', 'launcher', 'panels', 'table']) {
			await p.click('#nuHomeViewPanel [data-f="nav:' + nav + '"]').catch(() => {}); await p.click('#nuHomeViewPanel [data-f="style:' + sty + '"]').catch(() => {});
		}
		await p.waitForTimeout(300);
		st = await p.evaluate(() => ({ head: !!document.querySelector('.nuHomeStyle-table > .nuHomeTHead'), nav: nuHome.view.nav, style: nuHome.view.style }));
		expect(st.head && st.nav === 'none' && st.style === 'table', 'table / none: ' + JSON.stringify(st));
		await p.click('.nuHomeTHead [data-nu-sort="label"]'); await p.waitForTimeout(150); await p.click('.nuHomeTHead [data-nu-sort="label"]'); await p.waitForTimeout(150);
		const order = await p.evaluate(() => [...document.querySelectorAll('.nuHomeTileWrap')].sort((a, b) => (+a.style.order) - (+b.style.order)).map(w => w.querySelector('[data-nu-tile]').dataset.nuLabel));
		expect(order.length > 3 && order[0].localeCompare(order[order.length - 1]) > 0, 'table sort (descending): ' + order.join(','));
		// "Only me" (default): the shared layout stays, the globeadmin's own view is stored
		await pick('nav:groups'); await pick('style:cards'); await p.waitForTimeout(700);
		expect(!/"view"/.test(sql("SELECT definition FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id = 'e2e_hv'")), 'only me changed the shared layout');
		expect(/"e2e_hv"/.test(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'home_view_ga'")) , 'own view of the globeadmin not stored');
		// "Everyone": the layout gets the view, a note and an Undo toast; reopening the panel is back on "Only me"
		await pick('target:all');
		expect(await p.locator('#nuHomeViewPanel .nuHomeVpWarn').count() === 1, 'no note for everyone');
		await pick('desc:tooltip'); await p.waitForTimeout(800);
		expect(/"desc":"tooltip"/.test(sql("SELECT definition FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id = 'e2e_hv'")), 'everyone: layout not changed');
		expect(await p.locator('#nuHomeToast .alert button').count() >= 1, 'no Undo toast');
		await p.click('#nuHomeViewBtn'); await p.waitForTimeout(200); await panel();
		expect(await p.getAttribute('#nuHomeViewPanel [data-f="target:me"]', 'aria-pressed') === 'true', '"Everyone" was remembered');
		// tooltip mode: hovering a tile shows its description
		await p.click('#nuHomeViewBtn'); await p.waitForTimeout(200);
		await p.hover('[data-nu-tile="users"]'); await p.waitForTimeout(1200);
		expect(await p.locator('#nuHomeTip').isVisible() && /\S/.test(await p.locator('#nuHomeTip').textContent()), 'no tooltip');
		// Customise keeps the view
		await p.mouse.move(0, 0);
		await homeEdit(p); await p.waitForTimeout(400);
		await Promise.all([p.waitForNavigation(), p.click('#nuHomeSave')]); await p.waitForLoadState('networkidle');
		expect(/"desc":"tooltip"/.test(sql("SELECT definition FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id = 'e2e_hv'")), 'Customise lost the view');
		// invalid payloads are cleaned or refused
		let r = await post({ action: 'view_me', view: { style: 'bogus', desc: 'auto', tabStyle: { t_a: 'cards' } }, sizes: { phone: { nav: 'x' }, watch: { style: 'list' } } });
		expect(r.j.ok && JSON.stringify(r.j.overlay.view) === '{"desc":"auto"}' && !Object.keys(r.j.overlay.sizes).length, 'view_me not cleaned: ' + JSON.stringify(r.j));
		r = await post({ action: 'view_me', view: { desc: 'x'.repeat(10000) } });
		expect(r.status === 400 && !r.j.ok, 'a 10 KB view was taken: ' + JSON.stringify(r));
		r = await post({ action: 'view_layout', view: { style: 'nope', tabStyle: { t_missing: 'cards', t_a: 'panels' } } });
		const d = JSON.parse(sql("SELECT definition FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id = 'e2e_hv'")).view;
		expect(r.j.ok && d.style === 'large' && JSON.stringify(d.tabStyle) === '{"t_a":"panels"}', 'view_layout not cleaned: ' + JSON.stringify(d));
		await post({ action: 'view_me_clear' });
		// a user with an admin role but without "Start page layout": own view only, the shared layout stays; view_layout → 403
		sql("UPDATE zzzzsys_user SET sus_json = NULL WHERE zzzzsys_user_id = '" + uid + "'");    // no own layout choice (put back below)
		sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('listview_defaults', '{\"home\":\"e2e_hv\"}') ON DUPLICATE KEY UPDATE setting_value = JSON_SET(COALESCE(NULLIF(setting_value, ''), '{}'), '$.home', 'e2e_hv')");
		sql("INSERT INTO zzzzsys_nunext_admin_role (zzzzsys_nunext_admin_role_id, code, name, rights, sort_order) VALUES ('e2e_vrole', 'e2ev', 'E2E view role', '{\"securitycheck\":\"view\"}', 99)");
		sql("INSERT INTO zzzzsys_nunext_admin_user (zzzzsys_nunext_admin_user_id, role_id) VALUES ('" + uid + "', 'e2e_vrole')");
		const before = sql("SELECT definition FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id = 'e2e_hv'");
		const pa = await login('sample_anna', UPW);
		await open(pa, 'index.php?p=home');
		await homeTab(pa, 'appearance');
		expect(await pa.locator('#nuHomeViewPanel [data-f="target:all"]').count() === 0 && await pa.locator('#nuHomeCustomize').count() === 0, 'a user without the right may write the layout');
		await pa.click('#nuHomeViewPanel [data-f="style:launcher"]'); await pa.waitForTimeout(800);
		expect(sql("SELECT definition FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id = 'e2e_hv'") === before, 'the user changed the shared layout');
		expect(/NUNEXT_HOME_VIEW.*launcher/.test(sql("SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'")), 'own view not in sus_json');
		await open(pa, 'index.php?p=home');
		expect(await pa.evaluate(() => nuHome.view.style) === 'launcher', 'own view not shown again');
		const r2 = await pa.evaluate(async () => { const D = JSON.parse(document.getElementById('nuHomeData').textContent);
			return (await fetch(D.url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf }, body: JSON.stringify({ action: 'view_layout', view: { style: 'table' } }) })).status; });
		expect(r2 === 403, 'view_layout without the right: ' + r2);
		await homeTab(pa, 'appearance');
		await pa.click('#nuHomeViewPanel [data-f="undomine"]'); await pa.waitForTimeout(400); await pa.click('#nuConfirm .js-confirm-ok'); await pa.waitForTimeout(700);   // asks first (0.8.504)
		expect(!/launcher/.test(sql("SELECT COALESCE(sus_json, '') FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'")), 'Undo my changes kept the own view');
		await pa.context().close();
		expect(errs.length === 0, 'CSP: ' + errs.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_admin_user WHERE role_id = 'e2e_vrole'; DELETE FROM zzzzsys_nunext_admin_role WHERE zzzzsys_nunext_admin_role_id = 'e2e_vrole'");
		sql("UPDATE zzzzsys_user SET sus_json = " + (ujson && ujson !== 'NULL' ? "UNHEX('" + ujson + "')" : 'NULL') + " WHERE zzzzsys_user_id = '" + uid + "'");
		const ids = keepIds && keepIds !== 'NULL' ? keepIds.split(',') : [];
		sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'home'" + (ids.length ? " AND zzzzsys_nunext_list_view_id NOT IN ('" + ids.join("','") + "')" : ''));
		if (keepDefs && keepDefs !== 'NULL') for (const row of Buffer.from(keepDefs, 'hex').toString('utf8').split('\x02')) {
			const [id, def] = row.split('\x01');
			sql("UPDATE zzzzsys_nunext_list_view SET definition = UNHEX('" + Buffer.from(def, 'utf8').toString('hex') + "') WHERE zzzzsys_nunext_list_view_id = '" + id + "'");
		}
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('listview_ga', 'listview_defaults', 'home_view_ga')");
		if (keepSet && keepSet !== 'NULL') for (const row of Buffer.from(keepSet, 'hex').toString('utf8').split('\x02')) {
			const [id, v] = row.split('\x01');
			sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('" + id + "', UNHEX('" + Buffer.from(v, 'utf8').toString('hex') + "'))");
		}
	}
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
	await homeEdit(p); await p.waitForTimeout(400);
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
	await homeTab(p, 'layouts');           // Save as: in the View panel (0.8.420)
	await p.click('#nuHomeViewPanel [data-f="saveas"]');
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
// user start pages (0.8.425): automatic page, the editor per access level (report tile, conditions of a tile and a group), Personalise,
// the hook nu_home_user, refused admin actions, "Use the default", the rights card while acting as the user
T.home_user = async (s) => {
	const g = s.ga;
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	const keep = sql("SELECT HEX(CONCAT_WS(CHAR(1), COALESCE(sus_json, ''), COALESCE(sus_department, ''))) FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'");
	const homeJson = (p, body) => p.evaluate(async b => { const D = JSON.parse(document.getElementById('nuHomeData').textContent);
		const r = await fetch(D.url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf }, body: JSON.stringify(b) }); return { status: r.status, j: await r.json().catch(() => null) }; }, body);
	const labels = p => p.$$eval('#nuHomeView [data-nu-tile]', a => a.map(x => x.dataset.nuLabel));
	const rpt = sql("SELECT r.zzzzsys_report_id FROM zzzzsys_report r JOIN zzzzsys_access_report a ON a.sre_zzzzsys_report_id = r.zzzzsys_report_id WHERE a.sre_zzzzsys_access_id = 'sample_viewer' LIMIT 1");
	sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'home_user' AND access_ids = '[\"sample_viewer\"]'");
	sql("UPDATE zzzzsys_user SET sus_json = NULL, sus_department = 'Sample' WHERE zzzzsys_user_id = '" + uid + "'");
	let anna;
	try {
		// automatic page: the forms and reports of the access level, no rights table
		anna = await login('sample_anna', UPW);
		let l = await labels(anna);
		expect(l.includes('Sample: customers') && l.some(x => /orders by customer|customers by country/.test(x)), 'automatic page: ' + l.join(', '));
		expect(!/My forms and rights/.test(await anna.textContent('body')) && await anna.locator('#nuHomeRights').count() === 0, 'rights table shown to the user');
		// the editor: Customise → a report tile only for department "Sample", a group only for team "NoSuchTeam"
		await open(g, 'index.php?p=home&for=sample_viewer');
		expect(await g.locator('#nuHomeFor').inputValue() === 'sample_viewer', 'editor: access level not chosen');
		await homeEdit(g); await g.waitForTimeout(300);
		await g.click('#nuHomeAddTile'); await g.waitForTimeout(500);
		await g.selectOption('#htKind', 'report');
		await g.selectOption('#htReport', rpt);
		await g.fill('#htLabel', 'E2E report');
		await g.fill('#htCond_department', 'Sample, Other');
		await g.click('#nuHomeTileModal .modal-footer .btn-primary'); await g.waitForTimeout(500);
		await g.click('#nuHomeAddGroup'); await g.waitForTimeout(200);
		await g.fill('.nuHeGroup:last-of-type .nuHeGroupTitle', 'E2E team group');
		await g.click('#nuHomeAddTile'); await g.waitForTimeout(500);
		await g.selectOption('#htKind', 'url'); await g.fill('#htLabel', 'E2E link'); await g.fill('#htUrl', 'https://example.com/');
		await g.click('#nuHomeTileModal .modal-footer .btn-primary'); await g.waitForTimeout(500);
		await g.click('.nuHeGroup:last-of-type .nuHeGroupHead .nuHeToolBtn:has(.bi-funnel)'); await g.waitForTimeout(500);
		await g.fill('.modal.show #htCond_team', 'NoSuchTeam');
		await g.click('.modal.show .modal-footer .btn-primary'); await g.waitForTimeout(500);
		await Promise.all([g.waitForNavigation(), g.click('#nuHomeSave')]); await g.waitForLoadState('networkidle');
		const row = sql("SELECT CONCAT(access_ids, CHAR(1), definition) FROM zzzzsys_nunext_list_view WHERE list_key = 'home_user' AND access_ids = '[\"sample_viewer\"]'").split('\x01');
		const def = JSON.parse(row[1]);
		const own = Object.values(def.custom);
		expect(row[0] === '["sample_viewer"]' && own.some(c => c.kind === 'report' && c.label === 'E2E report' && JSON.stringify(c.cond) === '{"department":["Sample","Other"]}')
			&& def.groups.some(x => x.title === 'E2E team group' && JSON.stringify(x.cond) === '{"team":["NoSuchTeam"]}'), 'not saved: ' + row.join(' | '));
		expect(!def.groups.some(x => x.tiles.some(k => !def.custom[k])), 'built-in tiles in a user layout');
		expect(await g.locator('.nuHomeCondMark').count() >= 2, 'editor: conditions not marked');
		// the user: the report tile (department matches), not the team group; another department hides the tile
		await open(anna, 'index.php?p=home');
		l = await labels(anna);
		expect(l.includes('E2E report') && !l.includes('E2E link') && await anna.locator('.nuHomeCondMark').count() === 0, 'user page: ' + l.join(', '));
		sql("UPDATE zzzzsys_user SET sus_department = 'Elsewhere' WHERE zzzzsys_user_id = '" + uid + "'");
		await open(anna, 'index.php?p=home');
		expect(!(await labels(anna)).includes('E2E report'), 'department condition ignored');
		sql("UPDATE zzzzsys_user SET sus_department = 'Sample' WHERE zzzzsys_user_id = '" + uid + "'");
		// a user may not change the layout or switch layouts
		let r = await homeJson(anna, { action: 'layout', layout: { groups: [] } });
		expect(r.status === 403, 'user saved a layout: ' + r.status);
		r = await homeJson(anna, { action: 'layout_select', id: 'x' });
		expect(r.status === 403 || r.status === 400, 'user switched layouts: ' + r.status);
		// Personalise: hide "Sample: orders", star "E2E report", Done; Back to the standard
		await open(anna, 'index.php?p=home');
		await homeMine(anna); await anna.waitForTimeout(300);
		const ordersKey = await anna.$eval('[data-nu-tile][data-nu-label="Sample: orders"]', a => a.dataset.nuTile);
		const rptKey = await anna.$eval('[data-nu-tile][data-nu-label="E2E report"]', a => a.dataset.nuTile);
		await anna.click('[data-nu-wrap="' + ordersKey + '"] .nuHomeMineEye');
		await anna.click('[data-nu-wrap="' + rptKey + '"] .nuHomeMineStar');
		await Promise.all([anna.waitForNavigation(), anna.click('#nuHomeMineDone')]); await anna.waitForLoadState('networkidle');
		l = await labels(anna);
		expect(!l.includes('Sample: orders') && await anna.locator('[data-nu-tile="' + rptKey + '"][data-nu-fav]').count() === 1, 'Personalise not applied: ' + l.join(', '));
		expect(/"hide":\["c_/.test(sql("SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'")), 'own tiles not stored in sus_json');
		await homeMine(anna); await anna.waitForTimeout(300);
		expect(await anna.locator('#nuHomeMineHidden [data-nu-unhide="' + ordersKey + '"]').isVisible(), 'hidden tile not listed');
		await Promise.all([anna.waitForNavigation(), anna.click('#nuHomeMineReset')]); await anna.waitForLoadState('networkidle');
		expect((await labels(anna)).includes('Sample: orders'), 'Back to the standard did not show the tile');
		// hook nu_home_user: a banner with the access level; nu_home does not run on user pages
		execSync('mysql ' + DB, { input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_status, sph_global) VALUES ('e2e_uhook', 'nu_home_user', 'e2e', 'nuHomeBanner(\"E2E user hook #HOME_ACCESS#\");', '1', '0'), ('e2e_ahook', 'nu_home', 'e2e', 'nuHomeBanner(\"E2E admin hook\");', '1', '0')" });
		await open(anna, 'index.php?p=home');
		const body = await anna.textContent('body');
		expect(/E2E user hook sample_viewer/.test(body) && !/E2E admin hook/.test(body), 'user hook: ' + (body.match(/E2E[^\n]*/g) || []).join(' | '));
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id IN ('e2e_uhook', 'e2e_ahook')");
		// "Use the default": the access level's own page goes – the automatic page again
		await open(g, 'index.php?p=home&for=sample_viewer');
		await g.click('#nuHomeUseDefault'); await g.waitForTimeout(500);
		await Promise.all([g.waitForNavigation(), g.click('.modal.show .btn-danger')]);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'home_user' AND access_ids = '[\"sample_viewer\"]'") === '0', 'own page not removed');
		// the globeadmin acting as anna: the rights card (collapsed)
		const g2 = await login(GA[0], GA[1]);
		await open(g2, 'index.php?p=user&id=' + uid);
		expect(await csrfPost(g2, 'index.php?p=impersonate', { action: 'start', id: uid, mode: 'view' }) < 400, 'impersonation not started');
		await open(g2, 'index.php?p=home');
		expect(await g2.locator('#nuHomeRights').count() === 1 && !(await g2.getAttribute('#nuHomeRights', 'open')) && await g2.locator('#nuHomeMine').count() === 0, 'rights card while acting');
		await g2.context().close();
		// the sample start pages (sample data 15): sales desk with a group for the permission item sample_discount, the manager's tabs
		// with a group for team Lead, data entry with a report for positions Clerk / Manager
		if (sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id LIKE 'sample\\_uh\\_%'") === '3') {
			const page = async (login) => { const q = await login2(login); const r = { l: await labels(q), tabs: await q.$$eval('[data-nu-tabbtn]', x => x.length), style: await q.$eval('#nuHomeView', v => v.innerHTML.match(/nuHomeStyle-(\w+)/)[1]) }; await q.context().close(); return r; };
			const login2 = (u) => login(u, UPW);
			let pg = await page('sample_dario');
			expect(pg.l.includes('New order') && pg.l.includes('Products and prices') && pg.style === 'cards', 'sales desk: ' + JSON.stringify(pg));
			sql("UPDATE zzzzsys_user_permission SET upe_active = '0' WHERE upe_zzzzsys_user_id = 'sample_dario' AND upe_ident = 'sample_pi_discount'");
			pg = await page('sample_dario');
			sql("UPDATE zzzzsys_user_permission SET upe_active = '1' WHERE upe_zzzzsys_user_id = 'sample_dario' AND upe_ident = 'sample_pi_discount'");
			expect(!pg.l.includes('Products and prices'), 'Pricing shown without sample_discount');
			pg = await page('sample_carla');
			expect(pg.tabs === 2 && pg.l.includes('Run a procedure') && pg.l.includes('Customers by country'), 'manager: ' + JSON.stringify(pg));
			pg = await page('sample_ben');
			expect(pg.l.includes('New customer') && pg.l.includes('Customers by country') && pg.style === 'list', 'data entry: ' + JSON.stringify(pg));
		}
		expect(anna.errors.length === 0, 'JS errors: ' + anna.errors.slice(0, 2).join(' | '));
	} finally {
		if (anna) await anna.context().close();
		sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'home_user' AND access_ids = '[\"sample_viewer\"]'");
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id IN ('e2e_uhook', 'e2e_ahook')");
		const [j, dep] = Buffer.from(keep, 'hex').toString().split('\x01');
		execSync('mysql ' + DB, { input: "UPDATE zzzzsys_user SET sus_json = " + (j === '' ? 'NULL' : "UNHEX('" + Buffer.from(j).toString('hex') + "')") + ", sus_department = UNHEX('" + Buffer.from(dep).toString('hex') + "') WHERE zzzzsys_user_id = '" + uid + "'" });
	}
};

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
			await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('form button[type=submit]:not([name=demo_user])')]); }
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

T.help_fnref = async (s) => {                           // 0.8.399: PHP / JavaScript function reference in the help, with search and A–Z index
	const p = s.ga;
	await open(p, 'index.php?p=help&tab=ref'); await p.waitForSelector('#ref-php .nuFn', { state: 'attached' });
	const php = await p.locator('#ref-php .nuFn').count(), js = await p.locator('#ref-js .nuFn').count();
	expect(php > 150 && js > 300, 'reference entries missing: ' + php + ' / ' + js);
	expect(await p.locator('#helpTocRef a[href="#ref-php-h"]').count() === 1 && await p.locator('#helpTocRef a[href="#ref-js-h"]').count() === 1, 'tabs not in the contents');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-help-fntab'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=help#ref'); await p.waitForSelector('#ref-php .nuFn', { state: 'attached' }); await p.waitForTimeout(300);
	expect(await p.locator('#ref-php').isVisible() && !(await p.locator('#ref-js').isVisible()), 'PHP tab not first');
	expect(await p.locator('#fn-php-nuRunQuery .nuFnCode-php').count() === 1 && await p.locator('#fn-php-nuGetUserAccess .nuFnOut, #fn-php-nuHash .nuFnOut').count() > 0, 'example / result missing');
	await p.fill('#ref-php .nuFnSearch', 'fetch object'); await p.waitForTimeout(400);
	const shown = await p.locator('#ref-php .nuFn:visible').count();
	expect(shown > 0 && shown < 10 && await p.locator('#fn-php-db_fetch_object').isVisible(), 'own search does not filter: ' + shown);
	expect(/\d/.test(await p.locator('#ref-php .nuFnCount').textContent()), 'no count');
	await p.fill('#ref-php .nuFnSearch', 'xyzxyz'); await p.waitForTimeout(400);
	expect(await p.locator('#ref-php .nuFnNone').isVisible(), 'no "nothing found"');
	await p.fill('#ref-php .nuFnSearch', ''); await p.waitForTimeout(300);
	await p.click('[data-nu-fn-tab="ref-js"]'); await p.waitForTimeout(200);
	expect(await p.locator('#ref-js').isVisible() && !(await p.locator('#ref-php').isVisible()), 'JS tab does not switch');
	expect(await p.locator('#fn-js-nuIsDisabled .nuFnOut').count() === 1 && await p.locator('#fn-js-nuIsDisabled .nuFnParams li').count() > 0, 'JS example / parameters missing');
	await p.click('#ref-js .nuFnIndex summary');
	await p.click('#ref-js .nuFnIndex a[href="#fn-js-nuSubformObject"]'); await p.waitForTimeout(300);
	expect(await p.evaluate(() => location.hash) === '#fn-js-nuSubformObject' && await p.locator('#fn-js-nuSubformObject').isVisible(), 'index link');
	await p.fill('#helpSearch', 'nuRunQueryNoDebug'); await p.waitForTimeout(600);
	expect(await p.locator('.nuFn:visible').count() === 1 && !(await p.locator('#ref-php .nuFnIndex').isVisible()), 'help search does not narrow the functions');
	await p.fill('#helpSearch', ''); await p.waitForTimeout(300);
	await p.click('[data-nu-fn-tab="ref-php"]');
	await p.goto(p.url().split('#')[0] + '#fn-js-nuGetValue'); await p.waitForTimeout(1200);
	expect(await p.locator('#fn-js-nuGetValue').isVisible(), 'link to a JS function does not open the JS tab');
	await open(p, 'index.php?p=help&doc=user');
	expect(await p.locator('#ref-php, #ref-js').count() === 0 && await p.locator('#helpTabs').count() === 0, 'reference in the user view');
};

T.help_tabs = async (s) => {                              // 0.8.464: guide and code reference as two tabs, the reference loaded on demand
	const p = s.ga;
	const loads = []; const on = r => { if (/part=ref/.test(r.url())) loads.push(r.url()); };
	p.on('request', on);
	try {
		await open(p, 'index.php?p=help');
		expect(await p.locator('#helpTabs [data-help-tab]').count() === 2 && await p.locator('#helpBody #install-h').count() === 1, 'no tabs / guide');
		expect(await p.locator('#helpBody #dev-h, #helpBody #ref-php').count() === 0 && loads.length === 0, 'code chapters in the guide or reference loaded at once');
		await p.click('#helpTabRef'); await p.waitForSelector('#helpRef #dev-h', { state: 'attached' });
		expect(/tab=ref/.test(p.url()) && await p.locator('#helpPaneGuide').isHidden() && await p.locator('#helpTocRef a').count() > 10, 'code reference tab');
		await open(p, 'index.php?p=help#compat-h'); await p.waitForTimeout(1500);
		expect(await p.locator('#compat-h').isVisible() && await p.locator('#helpTabRef').getAttribute('aria-selected') === 'true', 'link into the reference does not open its tab');
		await p.click('#helpTabGuide'); await p.waitForTimeout(300);
		await p.fill('#helpSearch', 'nuSetNuDataValue'); await p.waitForTimeout(500);
		expect(await p.locator('#helpNone').isVisible(), 'guide search should find nothing');
		await p.check('#helpAll'); await p.waitForTimeout(1200);
		expect(await p.locator('#helpTabRef').getAttribute('aria-selected') === 'true' && await p.locator('#helpRef mark.nuHelpHit').count() > 0, 'search in both tabs');
		await p.fill('#helpSearch', ''); await p.uncheck('#helpAll');
		const r = await p.evaluate(async () => (await fetch('index.php?p=help&part=ref&doc=user')).text());
		expect(!/id="dev-h"|id="ref-php"/.test(r), 'reference for the user preview contains admin chapters');
		const anna = await login('sample_anna', UPW);
		await open(anna, 'index.php?p=help');
		expect(await anna.locator('#helpTabs').count() === 0, 'user without rights sees the code reference tab');
		await anna.context().close();
	} finally {
		p.off('request', on);
	}
};

// help as one file per page with toc.json (0.8.493): chapter → page index, numbering, "Show advanced topics" switch (remembered),
// a link into an advanced page turns it on, old ids keep working
T.help_pages = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=help');
	await p.evaluate(() => { try { localStorage.setItem('nunext-help-advanced', '0'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=help'); await p.waitForTimeout(300);
	expect(await p.locator('#helpAdvanced').count() === 1 && !(await p.locator('#helpAdvanced').isChecked()), 'no advanced switch / not off');
	expect(await p.evaluate(() => document.documentElement.classList.contains('nuHelpBasic')) && await p.locator('#save-flow').isHidden(), 'advanced page shown in basic mode');
	expect(await p.locator('#forms-use').count() === 1 && await p.locator('#lists').count() === 1, 'old ids gone');
	await p.click('label[for="helpAdvanced"]'); await p.waitForTimeout(300);
	expect(await p.locator('#save-flow').isVisible(), 'advanced page hidden with the switch on');
	await open(p, 'index.php?p=help'); await p.waitForTimeout(300);
	expect(await p.locator('#helpAdvanced').isChecked(), 'switch not remembered');
	await p.click('label[for="helpAdvanced"]'); await p.waitForTimeout(200);
	await open(p, 'index.php?p=help#save-flow'); await p.waitForTimeout(800);
	expect(await p.locator('#save-flow').isVisible(), 'link into an advanced page did not show it');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-help-advanced'); } catch (e) { /* */ } });
};

T.help_dev = async (s) => {                               // 0.8.408: chapter "Development with Claude" only in developer mode
	const p = s.ga;
	await open(p, 'index.php?p=help&tab=ref'); await p.waitForSelector('#helpRef #dev-h', { state: 'attached' });
	const dev = (await p.evaluate(async () => (await fetch('index.php?p=selftest')).status)) !== 404;
	expect((await p.locator('#devflow-h').count() === 1) === dev, 'developer chapter shown: ' + !dev);
	if (dev) expect(await p.locator('#devflow-key, #devflow-gate, #devflow-manual, #devflow-zips').count() === 4, 'developer chapter incomplete');
	await open(p, 'index.php?p=help&doc=user');
	expect(await p.locator('#devflow-h').count() === 0, 'developer chapter in the user preview');
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
	expect(/#system$/.test(p.url()) && await p.locator('#updRequirements').isVisible(), 'system tab');
	await p.click('[data-nu-tab="libraries"]'); await p.waitForTimeout(400);
	expect(/#libraries$/.test(p.url()) && await p.locator('#updLibraries').isVisible() && await p.locator('#updLibPacks').isVisible(), 'libraries tab (0.8.405)');
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

// start page hook nu_home (0.8.378): PHP changes of the tile start page – badge, hint, hidden tile, dynamic group / tiles, banner
const homeHook = (php, js = '') => {
	const q = v => "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";
	sql("DELETE FROM zzzzsys_php WHERE sph_code = 'nu_home'");
	if (php !== null) execSync('mysql ' + DB, { input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_javascript, sph_javascript_active, sph_status, sph_global) VALUES ('e2e_hook', 'nu_home', 'e2e', "
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
			await homeEdit(p); await p.waitForTimeout(500);
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
		sql("DELETE FROM zzzzsys_nunext_admin_user WHERE role_id = 'e2e_hrole'; DELETE FROM zzzzsys_nunext_admin_role WHERE zzzzsys_nunext_admin_role_id = 'e2e_hrole'; DELETE FROM zzzzsys_debug WHERE deb_message LIKE 'nu_home%'");
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
			notes: document.querySelector('[data-nu-tile="notes"]') ? document.querySelector('[data-nu-tile="notes"]').closest('.nuHomeTileWrap').hidden : true,
			toasts: [...document.querySelectorAll('#nuHomeToast .alert')].filter(a => /E2E js boom/.test(a.textContent)).length }));
		expect(/tiles=1/.test(st.url) && st.clicked === 1 && st.dyn === 'dynamic/page/x_e2e' && st.ready > 5 && st.one, 'API: ' + JSON.stringify(st));
		expect(st.badge === 'JS 5' && st.hint === 'JS hint' && st.notes === true && /^:.*x_e2e_tile/.test(st.search || ''), 'tile changes: ' + JSON.stringify(st));
		expect(st.toasts === 1, 'error toast once for the globeadmin: ' + st.toasts);
		await p.fill('#nuHomeSearch', 'notes'); await p.waitForTimeout(250);
		expect(await p.evaluate(() => window.__search) === 'notes:', 'a tile hidden by code is found by the search');
		await p.fill('#nuHomeSearch', ''); await p.waitForTimeout(200);
		if (await p.locator('#nuHomeCustomize').count()) { await homeEdit(p); await p.waitForTimeout(400); expect(await p.evaluate(() => window.__cust) === true, 'no customize event'); }
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

// nuBuilder's include files (0.8.443): $nuConfigIncludeJS / CSS on form pages (nuBuilder Next's folder, then nuBuilder's – "../"),
// $nuConfigIncludePHP from nuBuilder's folder, missing files on the Security check
T.config_includes = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8'), nub = path.dirname(SITE);
	const files = { js1: path.join(SITE, 'e2e_inc_a.js'), js2: path.join(nub, 'e2e_inc_b.js'), css: path.join(nub, 'e2e_inc.css'), php: path.join(nub, 'e2e_inc.php') };
	const p = s.ga;
	try {
		fs.writeFileSync(files.js1, 'window.e2eIncA = (typeof nuGetValue === "function") ? "runtime" : "early";');
		fs.writeFileSync(files.js2, 'window.e2eIncB = 1;');
		fs.writeFileSync(files.css, ':root { --e2e-inc: 7px; }');
		fs.writeFileSync(files.php, '<?php function e2eIncPhp() { return "from nuBuilder folder"; }');
		fs.writeFileSync(cfg, orig.replace(/\?>\s*$/, '') + "\n$nuConfigIncludeJS = ['e2e_inc_a.js', 'e2e_inc_b.js'];\n$nuConfigIncludeCSS = 'e2e_inc.css';\n$nuConfigIncludePHP = ['e2e_inc.php', 'e2e_missing.php'];\n");
		sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_system) VALUES ('samplecustomer_BE', 'samplecustomer_BE', 'e2e', 'nuAddJavascript(\"window.e2eIncPhp = \" . json_encode(e2eIncPhp()) . \";\");', '1')");
		await new Promise(r => setTimeout(r, 3500));
		const id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForTimeout(400);
		const st = await p.evaluate(() => ({ a: window.e2eIncA, b: window.e2eIncB, css: getComputedStyle(document.documentElement).getPropertyValue('--e2e-inc').trim(), php: window.e2eIncPhp,
			srcB: (document.querySelector('script[data-nu-source="nuConfigIncludeJS"][src*="e2e_inc_b"]') || {}).getAttribute ? document.querySelector('script[src*="e2e_inc_b"]').getAttribute('src') : '' }));
		expect(st.a === 'runtime' && st.b === 1, 'JS includes: ' + JSON.stringify(st));
		expect(/^\.\.\/e2e_inc_b\.js\?v=/.test(st.srcB), 'nuBuilder folder as ../ with ?v=: ' + st.srcB);
		expect(st.css === '7px', 'CSS include: ' + st.css);
		expect(st.php === 'from nuBuilder folder', 'PHP include from nuBuilder folder: ' + st.php);
		await open(p, 'index.php?p=securitycheck');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=securitycheck'); }
		const txt = await p.textContent('body');
		expect(txt.includes('e2e_missing.php') && !txt.includes('IncludePHP: e2e_inc.php'), 'Security check names the missing file only');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		fs.writeFileSync(cfg, orig);
		for (const f of Object.values(files)) fs.rmSync(f, { force: true });
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'samplecustomer_BE' AND sph_description = 'e2e'");
		await new Promise(r => setTimeout(r, 3500));
	}
};

// Admin tools → Setup code (0.8.444): nuBuilder's Setup → Header / Style / Include edited in zzzzsys_setup; Include on form pages
// (only its <link> / <script> / <style> elements), a field changed by someone else meanwhile is not overwritten
T.setup_code = async (s) => {
	const p = s.ga;
	const keep = sql("SELECT CONCAT_WS(',', IFNULL(HEX(set_header),'-'), IFNULL(HEX(set_style),'-'), IFNULL(HEX(set_include),'-')) FROM zzzzsys_setup LIMIT 1").split(',');
	const setCm = (k, v) => p.evaluate(([k, v]) => { const ta = document.getElementById('scode-' + k); ta.nextElementSibling.CodeMirror.setValue(v); }, [k, v]);
	const openPage = async (hash) => {
		await open(p, 'index.php?p=setupcode' + (hash || ''));
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=setupcode' + (hash || '')); }
	};
	try {
		await openPage('#include');
		expect(await p.locator('#scodePane-include.active').count() === 1, 'tab from the address');
		const header = sql("SELECT set_header FROM zzzzsys_setup LIMIT 1");
		await setCm('include', '// Add external CSS/JS file here\n<!-- <script src="nothere.js"></script> -->\n<script>window.e2eSetInc = 1;</script>\n<style>:root { --e2e-setinc: 5px; }</style>\nsome text');
		await Promise.all([p.waitForNavigation(), p.click('#scodeSave')]);
		expect(new URL(p.url()).hash === '#include' && /include/i.test(await p.textContent('.alert-success, .toast, body')), 'saved, back on the tab: ' + p.url());
		expect(sql("SELECT set_header FROM zzzzsys_setup LIMIT 1") === header, 'header untouched');
		// a field changed elsewhere since the page was opened: refused, the typed text stays
		await openPage('#style');
		sql("UPDATE zzzzsys_setup SET set_style = '/* changed in nuBuilder */'");
		await setCm('style', '.mine { color: red; }');
		await Promise.all([p.waitForNavigation(), p.click('#scodeSave')]);
		expect(sql("SELECT set_style FROM zzzzsys_setup LIMIT 1") === '/* changed in nuBuilder */', 'conflict: not overwritten');
		expect(/mine/.test(await p.evaluate(() => document.getElementById('scode-style').value)), 'conflict: text kept');
		// the form page: Include's elements only
		const id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + id);
		const st = await p.evaluate(() => ({ js: window.e2eSetInc, css: getComputedStyle(document.documentElement).getPropertyValue('--e2e-setinc').trim(), text: document.body.innerText.includes('Add external CSS/JS'), none: !!document.querySelector('script[src*="nothere"]') }));
		expect(st.js === 1 && st.css === '5px' && !st.text && !st.none, 'form page: ' + JSON.stringify(st));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		const v = x => x === '-' ? 'NULL' : "UNHEX('" + x + "')";
		sql('UPDATE zzzzsys_setup SET set_header = ' + v(keep[0]) + ', set_style = ' + v(keep[1]) + ', set_include = ' + v(keep[2]));
	}
};

// Update page → Backups (0.8.451): several deleted at once, the newest and the safety backup protected (also against a forged request),
// the clean-up rule with preview, saved rule; paging the list opens the Backups tab from the server (?tab=) without hiding it first
T.backups_bulk = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const p = s.ga, dir = path.join(SITE, '_backup');
	const list = () => fs.existsSync(dir) ? fs.readdirSync(dir).filter(f => /^nunext-.*\.zip$/.test(f)) : [];
	const before = list();
	const keepRule = sql("SELECT IFNULL((SELECT HEX(setting_value) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'backup_cleanup'), '-')");
	const go = async (q) => {                            // a real load every time (the same address with #backups would only scroll)
		q = q.replace('#', '&_t=' + Date.now() + '#');
		await open(p, 'index.php?p=update' + q);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update' + q); }
	};
	let made = [];
	try {
		await go('&tab=backups#backups');
		await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#updBackupForm button[type=submit]')]);
		const real = list().filter(f => !before.includes(f));
		expect(real.length === 1, 'one real backup made: ' + real);
		made = made.concat(real);
		const fake = require('child_process').execFileSync('python3', [path.join(__dirname, 'fakebackups.py'), dir, path.join(dir, real[0]), '14']).toString().trim().split('\n');
		made = made.concat(fake);
		// paging: the server opens the tab, nothing hidden while the page loads
		await p.addInitScript(() => {
			window.__nuPend = null;
			new MutationObserver(function (m, o) { if (document.getElementById('updPanes')) { window.__nuPend = document.documentElement.classList.contains('nuTabsPending'); o.disconnect(); } })
				.observe(document, { childList: true, subtree: true });
		});
		await go('&tab=backups#backups');
		const total = before.length + 15;
		expect(await p.locator('#updBackups .updBkSelCol .bi-lock').count() >= 1, 'newest backup locked');
		await p.click('.updBackupsNav a.nuPagerNext');
		await p.waitForURL(/pg=2/); await p.waitForLoadState('load');
		expect(/tab=backups/.test(p.url()) && /pg=2/.test(p.url()), 'pager keeps the tab: ' + p.url());
		expect(await p.evaluate(() => window.__nuPend) === false, 'paging: tab not hidden while loading');
		expect(await p.locator('#updPane-backups.active').count() === 1, 'backups tab open');
		// select three on page 1 and delete them
		await go('&tab=backups#backups');
		const boxes = p.locator('.updBkSel');
		const names = [];
		for (let i = 0; i < 3; i++) { await boxes.nth(i).check(); names.push(await boxes.nth(i).getAttribute('value')); }
		expect(/\(3\)/.test(await p.textContent('#updBkDelSel')), 'button counts the selection');
		expect(/^Delete 3 backups/.test(await p.getAttribute('#updBkBulk', 'data-confirm')), 'confirm text: ' + await p.getAttribute('#updBkBulk', 'data-confirm'));
		await p.click('#updBkDelSel');
		const ok = p.locator('#nuConfirm .js-confirm-ok');
		await ok.waitFor({ state: 'visible' });
		await Promise.all([p.waitForNavigation(), ok.click()]);
		expect(names.every(n => !list().includes(n)) && list().length === total - 3, 'three deleted: ' + list().length);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'nunext_backups_deleted' AND details LIKE '%\"count\":3%'") !== '0', 'logged');
		// a forged request with the newest (protected) backup: skipped
		const newest = real[0];
		await p.evaluate((n) => { const f = document.getElementById('updBkBulk'); const i = document.createElement('input'); i.type = 'hidden'; i.name = 'names[]'; i.value = n; f.appendChild(i); f.dataset.confirmed = '1'; f.submit(); }, newest);
		await p.waitForLoadState('networkidle');
		expect(list().includes(newest), 'protected backup kept');
		// clean-up rule: keep the newest 4 (+ the protected safety backup if older)
		await p.click('#updBkCleanBtn'); await p.waitForTimeout(400);
		await p.fill('#updBkKeepN', '4');
		const prev = await p.textContent('#updBkCleanPreview');
		const n = Number((prev.match(/Would delete (\d+)/) || [])[1]);
		expect(n > 0, 'preview: ' + prev);
		const left = list().length;
		await p.click('#updBkCleanRun');
		await ok.waitFor({ state: 'visible' });
		await Promise.all([p.waitForNavigation(), ok.click()]);
		expect(list().length === left - n, 'rule deleted what the preview said: ' + (left - list().length) + ' / ' + n);
		// save the rule only (no confirm, nothing deleted)
		await p.click('#updBkCleanBtn'); await p.waitForTimeout(400);
		await p.check('#updBkModeAge'); await p.fill('#updBkDays', '30'); await p.check('#updBkAuto');
		const left2 = list().length;
		await Promise.all([p.waitForNavigation(), p.click('#updBkCleanSave')]);
		const rule = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'backup_cleanup'"));
		expect(rule.mode === 'age' && rule.n === 30 && rule.auto === true && list().length === left2, 'rule saved: ' + JSON.stringify(rule));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		for (const f of made) fs.rmSync(path.join(dir, f), { force: true });
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'backup_cleanup'");
		if (keepRule !== '-') sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('backup_cleanup', UNHEX('" + keepRule + "'))");
	}
};

// start page (0.8.452): one Customise button – its panel has the tabs Content (edit mode, Personalise), Appearance, Layouts
async function homeTab(pg, tab) {
	if (await pg.locator('#nuHomeViewPanel').isHidden()) { await pg.click('#nuHomeViewBtn'); await pg.waitForTimeout(300); }
	if (await pg.locator('#nuHomeVpTab-' + tab).count()) { await pg.click('#nuHomeVpTab-' + tab); await pg.waitForTimeout(200); }
}
async function homeEdit(pg) { await homeTab(pg, 'content'); await pg.click('#nuHomeVpEdit'); }
async function homeMine(pg) { await homeTab(pg, 'content'); await pg.click('#nuHomeVpMine'); }

// start page rows Favourites / Recently used fold (0.8.465): remembered per device, count when folded, phones start with Recently used folded
T.home_strips = async (s) => {
	const p = s.ga;
	// 0.8.492: the row shows the side bar's list (zzzzsys_nunext_recent, per user) – pages opened are recorded also with the side bar off
	sql('DELETE FROM zzzzsys_nunext_recent');
	for (const u of ['permissions', 'access', 'users']) await open(p, 'index.php?p=' + u);
	const tiles = ['users', 'access', 'permissions'];
	await open(p, 'index.php?p=home');
	await p.evaluate(() => localStorage.removeItem('nunext-home-strip-recent'));
	await homeTab(p, 'appearance');
	const sw = '#nuHomeVp_recentStrip', was = await p.locator(sw).isChecked();
	try {
		if (!was) { await p.click('label[for="nuHomeVp_recentStrip"]'); await p.waitForTimeout(800); }
		await open(p, 'index.php?p=home');
		const st = '[data-nu-strip="recent"]';
		const shown = await p.locator(st + ' .nuHomeChip').evaluateAll(e => e.map(x => x.getAttribute('data-nu-recent')));
		expect(await p.locator(st).isVisible() && JSON.stringify(shown) === JSON.stringify(tiles), 'recently used row: ' + JSON.stringify(shown));
		expect(await p.locator(st + ' .nuHomeStripToggle').getAttribute('aria-expanded') === 'true', 'row folded on a wide screen at first');
		await p.click(st + ' .nuHomeStripToggle'); await p.waitForTimeout(300);
		expect(await p.locator(st + ' .nuHomeChips').isHidden() && await p.locator(st + ' .nuHomeStripCount').textContent() === String(tiles.length), 'fold / count');
		await open(p, 'index.php?p=home');
		expect(await p.locator(st + ' .nuHomeChips').isHidden() && await p.locator(st + ' .nuHomeStripToggle').getAttribute('aria-expanded') === 'false', 'folded state not remembered');
		await p.click(st + ' .nuHomeStripToggle'); await p.waitForTimeout(300);
		expect(await p.locator(st + ' .nuHomeChips').isVisible(), 'unfold');
		// phone without a choice: Recently used starts folded
		const ph = await p.context().newPage();
		await ph.setViewportSize({ width: 390, height: 800 });
		await ph.goto(BASE + 'index.php?p=home');
		await ph.evaluate(() => localStorage.removeItem('nunext-home-strip-recent'));
		await ph.reload(); await ph.waitForTimeout(400);
		expect(await ph.locator(st + ' .nuHomeChips').isHidden(), 'phone: recently used not folded at first');
		await ph.close();
		// tiles opened on the start page are sent with a beacon (action used, answer 204); an unknown key is not recorded
		const before = sql("SELECT COUNT(*) FROM zzzzsys_nunext_recent");
		const status = await p.evaluate(async () => {
			const D = JSON.parse(document.getElementById('nuHomeData').textContent), fd = new FormData();
			fd.append('_csrf', D.csrf); fd.append('action', 'used'); fd.append('key', 'no-such-tile');
			return (await fetch(D.usedUrl, { method: 'POST', body: fd, credentials: 'same-origin' })).status;
		});
		expect(status === 204 && sql("SELECT COUNT(*) FROM zzzzsys_nunext_recent") === before, 'used: unknown tile recorded or wrong status ' + status);
		// ✕ next to the heading: empties the user's list (side bar too) and hides the row
		await Promise.all([p.waitForNavigation(), p.click(st + ' .nuHomeStripClear')]);
		expect(await p.locator(st).isHidden(), 'row still shown after ✕');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_recent") === '0', 'clear did not empty the list');
	} finally {
		await p.evaluate(() => localStorage.removeItem('nunext-home-strip-recent'));
		if (!was) { await open(p, 'index.php?p=home'); await homeTab(p, 'appearance'); if (await p.locator(sw).isChecked()) { await p.click('label[for="nuHomeVp_recentStrip"]'); await p.waitForTimeout(800); } }
		sql('DELETE FROM zzzzsys_nunext_recent');
	}
};


// start page (0.8.497): right-click / long press on a tile → name, description and "Add to favourites" / "Remove from favourites"
T.home_ctx_fav = async (s) => {
	const p = s.ga;
	const tile = () => p.locator('#nuHomeView .nuHomeMain [data-nu-tile="users"]').first();
	await open(p, 'index.php?p=home&tiles=1');
	const was = (await tile().getAttribute('data-nu-fav')) === '1';
	const toggle = async () => {
		await tile().click({ button: 'right' }); await p.waitForTimeout(200);
		expect(await p.locator('.nuHomeCtx .nuHomeCtxHead').count() === 1, 'no menu with the tile name');
		await Promise.all([p.waitForNavigation(), p.click('.nuHomeCtx .dropdown-item')]);
		await p.waitForTimeout(300);
	};
	await toggle();
	expect(((await tile().getAttribute('data-nu-fav')) === '1') !== was, 'right-click did not change the favourite');
	await toggle();
	expect(((await tile().getAttribute('data-nu-fav')) === '1') === was, 'second change did not undo');
	await tile().click({ button: 'right' }); await p.waitForTimeout(200);
	await p.keyboard.press('Escape');
	expect(await p.locator('.nuHomeCtx').count() === 0, 'Esc does not close the menu');
	// phone: a long press opens the same menu
	const ph = await p.context().newPage();
	await ph.setViewportSize({ width: 390, height: 800 });
	await ph.goto(BASE + 'index.php?p=home&tiles=1'); await ph.waitForTimeout(400);
	await ph.evaluate(() => {
		const a = document.querySelector('#nuHomeView .nuHomeMain [data-nu-tile="users"]'), r = a.getBoundingClientRect();
		a.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch', clientX: r.left + 20, clientY: r.top + 20 }));
	});
	await ph.waitForTimeout(700);
	expect(await ph.locator('.nuHomeCtx .dropdown-item').count() === 1, 'long press shows no menu');
	await ph.close();
};

// 0.8.498: right-click / long press on an entry of Recently used (start page row and side bar) → Remove from recently used
T.recent_forget = async (s) => {
	const p = s.ga;
	const keys = () => sql("SELECT GROUP_CONCAT(item ORDER BY stamp DESC) FROM zzzzsys_nunext_recent") || '';
	sql('DELETE FROM zzzzsys_nunext_recent');
	sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'sidebar_ga'");
	await open(p, 'index.php?p=home');
	await homeTab(p, 'appearance');
	const sw = '#nuHomeVp_recentStrip', was = await p.locator(sw).isChecked();
	try {
		if (!was) { await p.click('label[for="nuHomeVp_recentStrip"]'); await p.waitForTimeout(800); }
		for (const u of ['permissions', 'access', 'users']) await open(p, 'index.php?p=' + u);
		await open(p, 'index.php?p=home');
		const chip = '[data-nu-strip="recent"] [data-nu-recent="access"]';
		await p.locator(chip).click({ button: 'right' }); await p.waitForTimeout(200);
		expect(await p.locator('.nuHomeCtx .dropdown-item').count() === 2, 'start page: menu without remove / clear');
		await Promise.all([p.waitForNavigation(), p.locator('.nuHomeCtx .dropdown-item').first().click()]);
		expect(keys() === 'users,permissions', 'start page: not removed: ' + keys());
		expect(await p.locator(chip).count() === 0, 'start page: chip still shown');
		// side bar (switched on for the globeadmin): right-click on an entry
		sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('sidebar_ga', '1')");
		await open(p, 'index.php?p=forms');
		await p.click('#nuSbOpen'); await p.waitForTimeout(300);   // a drawer on every width (0.8.513)
		await p.locator('#nuSidebar .nuSbSec-recent [data-nu-sb="permissions"] a').click({ button: 'right' }); await p.waitForTimeout(200);
		expect(await p.locator('.nuSbMenu .dropdown-item').count() === 2, 'side bar: menu without remove / clear');
		await Promise.all([p.waitForNavigation(), p.locator('.nuSbMenu .dropdown-item').first().click()]);
		expect(!keys().split(',').includes('permissions') && keys().split(',').includes('users'), 'side bar: not removed: ' + keys());
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'sidebar_ga'");
		sql('DELETE FROM zzzzsys_nunext_recent');
		if (!was) { await open(p, 'index.php?p=home'); await homeTab(p, 'appearance'); if (await p.locator(sw).isChecked()) { await p.click('label[for="nuHomeVp_recentStrip"]'); await p.waitForTimeout(800); } }
	}
};

// 0.8.500: user and procedure pages – (i) shows the record ID (with copy buttons) instead of "ID …" in the title bar
T.record_info = async (s) => {
	const p = s.ga;
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	const pid = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE zzzzsys_php_id = 'sample_proc_hello'");
	if (!uid || !pid) { s.skip = 'no sample data'; return; }
	for (const [url, id] of [['index.php?p=user&id=' + uid, uid], ['index.php?p=procedures&id=' + pid, pid]]) {
		await open(p, url);
		await p.click('#nuRecInfoBtn'); await p.waitForTimeout(200);
		expect(await p.locator('#nuRecInfo [data-key="record_id"] code').textContent() === id, 'record ID not shown on ' + url);
		expect((await p.getAttribute('#nuRecInfoCopyAll', 'data-nu-copy-text')).includes(id), 'Copy all without the ID on ' + url);
	}
	await open(p, 'index.php?p=user&id=new');
	expect(await p.locator('#nuRecInfoBtn').count() === 0, 'new user has a record info');
	// 0.8.501: access levels, permission items, admin roles, reports, dashboards, translations, items, user fields, files
	sql("INSERT INTO zzzzsys_translate (zzzzsys_translate_id, trl_language, trl_english, trl_translation) VALUES ('e2erectrl', 'German', 'E2E info', 'E2E Info')");
	sql("INSERT INTO zzzzsys_item (zzzzsys_item_id, itm_value, itm_group, itm_sort_order) VALUES ('e2erecitm', 'E2E info', 'e2e', 0)");
	try {
		const pages = [['accesslevel', "SELECT zzzzsys_access_id FROM zzzzsys_access ORDER BY sal_code LIMIT 1"],
			['permissions', 'SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item LIMIT 1'],
			['adminroles', 'SELECT zzzzsys_nunext_admin_role_id FROM zzzzsys_nunext_admin_role LIMIT 1'],
			['reports', 'SELECT zzzzsys_report_id FROM zzzzsys_report LIMIT 1'],
			['dashboards', 'SELECT zzzzsys_nunext_dashboard_id FROM zzzzsys_nunext_dashboard LIMIT 1'],
			['translations', "SELECT 'e2erectrl'"], ['items', "SELECT 'e2erecitm'"],
			['userfields', 'SELECT zzzzsys_nunext_user_field_id FROM zzzzsys_nunext_user_field LIMIT 1'],
			['files', "SELECT zzzzsys_file_id FROM zzzzsys_file WHERE zzzzsys_file_id NOT LIKE 'nu%' LIMIT 1"]];
		for (const [route, q] of pages) {
			const id = sql(q);
			if (!id) continue;                                       // no record of that kind in this database
			await open(p, 'index.php?p=' + route + '&id=' + encodeURIComponent(id));
			await p.click('#nuRecInfoBtn'); await p.waitForTimeout(150);
			expect(await p.locator('#nuRecInfo [data-key="record_id"] code').textContent() === id, 'record ID not shown on ' + route);
		}
	} finally {
		sql("DELETE FROM zzzzsys_translate WHERE zzzzsys_translate_id = 'e2erectrl'");
		sql("DELETE FROM zzzzsys_item WHERE zzzzsys_item_id = 'e2erecitm'");
	}
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

// 0.8.504: Customise → Appearance, "Only me": the links "Make my view the layout's view" and "Undo my changes" ask first (dialog)
T.home_view_confirm = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home');
	await homeTab(p, 'appearance');
	if (await p.locator('.nuHomeVpLink[data-f="undomine"]').count()) {          // start without own changes
		await p.click('.nuHomeVpLink[data-f="undomine"]'); await p.waitForTimeout(400);
		await p.click('#nuConfirm .js-confirm-ok'); await p.waitForTimeout(600);
	}
	await p.click('label[for="nuHomeVp_recentStrip"]'); await p.waitForTimeout(800);
	const undo = '.nuHomeVpLink[data-f="undomine"]';
	if (!(await p.locator(undo).count())) { s.skip = 'layout not editable here'; return; }
	expect(/1/.test(await p.locator('.nuHomeVpLink[data-f="mklayout"]').locator('xpath=..').textContent()), 'own change not counted');
	// make layout → dialog, Cancel keeps everything
	await p.click('.nuHomeVpLink[data-f="mklayout"]'); await p.waitForTimeout(400);
	expect(await p.locator('#nuConfirm.show').count() === 1 && /everyone|alle/i.test(await p.locator('#nuConfirmTitle').textContent()), 'no dialog for Make my view the layout’s view');
	await p.click('#nuConfirm [data-bs-dismiss="modal"].btn'); await p.waitForTimeout(500);
	expect(await p.locator(undo).count() === 1, 'Cancel changed something');
	// undo → dialog, OK undoes the own change
	await p.click(undo); await p.waitForTimeout(400);
	expect(await p.locator('#nuConfirm.show').count() === 1, 'no dialog for Undo my changes');
	await p.click('#nuConfirm .js-confirm-ok'); await p.waitForTimeout(700);
	expect(await p.locator(undo).count() === 0, 'OK did not undo the own change');
	expect((await p.locator('#nuConfirm .js-confirm-ok').textContent()).trim() !== '', 'OK button text lost');
};

// sample data 16 (0.8.506): tab Upload of sample_all = Uppy object (file system), full width also when opened on a hidden tab
T.sample_upload = async (s) => {
	// the test database may hold an older sample version: build the forms of this version (the database is put back after the run)
	if (sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_id = 'all_upload' AND sob_all_zzzzsys_form_id = 'sampleall'") === '0') {
		if (!SITE) { s.skip = 'sample data older than 16 (SITE needed to refresh it)'; return; }
		execSync(`php -r 'chdir(${JSON.stringify(SITE)}); require "core/nuapp.php"; NuSample::install();'`, { stdio: 'pipe' });
		const hash = execSync('php -r ' + JSON.stringify('echo password_hash(' + JSON.stringify(UPW) + ', PASSWORD_BCRYPT);')).toString().trim();
		sql("UPDATE zzzzsys_user SET sus_login_password = '" + hash + "', sus_change_password = '0' WHERE sus_login_name LIKE 'sample\\_%'");
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

// start page (0.8.452): one Customise button, panel tabs Content · Appearance · Layouts; the tile style only on Appearance
T.home_customise = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-home-cust-tab'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=home');
	const shown = await p.$$eval('.nuHomeTools button', b => b.filter(x => !x.hidden).map(x => x.id));
	expect(shown.length === 1 && shown[0] === 'nuHomeViewBtn', 'one button: ' + shown);
	await p.click('#nuHomeViewBtn'); await p.waitForTimeout(300);
	const tabs = await p.$$eval('.nuHomeVpTabs .nav-link', b => b.map(x => x.id.replace('nuHomeVpTab-', '') + (x.classList.contains('active') ? '*' : '')));
	expect(tabs.join(',') === 'appearance*,content,layouts', 'tabs: ' + tabs);
	await p.click('#nuHomeVpTab-layouts'); await p.waitForTimeout(200);
	expect(await p.locator('#nuHomeVpLayout').count() === 1 && await p.locator('#nuHomeViewPanel [data-f^="style:"]').count() === 0, 'layouts tab');
	await p.click('#nuHomeVpTab-appearance'); await p.waitForTimeout(200);
	expect(await p.locator('#nuHomeViewPanel [data-f^="style:"]').count() > 0 && await p.locator('#nuHomeVpLayout').count() === 0, 'appearance tab');
	await p.keyboard.press('Escape'); await p.waitForTimeout(200);
	await open(p, 'index.php?p=home');
	await p.click('#nuHomeViewBtn'); await p.waitForTimeout(300);
	expect(await p.getAttribute('#nuHomeVpTab-appearance', 'aria-selected') === 'true', 'last tab remembered');
	await p.click('#nuHomeVpTab-content'); await p.click('#nuHomeVpEdit'); await p.waitForTimeout(400);
	expect(await p.evaluate(() => document.body.classList.contains('nuHomeEditing')) && await p.locator('#nuHomeSize').count() === 0, 'edit mode without the tile size');
	await p.click('#nuHomeCancel'); await p.waitForTimeout(300);
	await p.keyboard.press('v'); await p.waitForTimeout(300);
	expect(await p.getAttribute('#nuHomeVpTab-appearance', 'aria-selected') === 'true', 'V opens Appearance');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// custom user fields (0.8.454): define fields (real columns in zzzzsys_nunext_user_ext), user form tab More with checks (choice, unique),
// log, users list column + search, My account for "edited by the user", hash / JS value on a form page, CSV import, delete field
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
		await add({ label: 'E2E location', code: 'e2e_loc', type: 'select', options: 'ZH|Zurich\nBE|Bern', edit: 'user' });
		await add({ label: 'E2E badge', code: 'e2e_badge', type: 'integer', unique: true });
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_user_field WHERE code IN ('e2e_loc', 'e2e_badge')") === '2', 'fields not stored');
		const cols = sql("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zzzzsys_nunext_user_ext'");
		expect(/e2e_loc/.test(cols) && /e2e_badge/.test(cols), 'columns: ' + cols);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_user u JOIN zzzzsys_nunext_user_ext x USING (zzzzsys_user_id)") === '0', 'join (collation) failed');
		// user form: tab More, a wrong choice is refused, then saved
		await open(p, 'index.php?p=user&id=' + anna);
		expect(await p.locator('#userTabMoreBtn').count() === 1, 'no tab More');
		await p.click('#userTabMoreBtn'); await p.waitForTimeout(200);
		expect(await p.locator('#userTabMore .nuUfType').count() >= 1, 'field labels without their type in brackets (0.8.508)');
		await p.evaluate(() => { const s2 = document.getElementById('uf_e2e_loc'); const o = document.createElement('option'); o.value = 'XX'; o.textContent = 'XX'; s2.appendChild(o); s2.value = 'XX'; });
		await p.fill('#uf_e2e_badge', '4711');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		expect(await p.locator('#userTabMore.active .invalid-feedback').count() >= 1, 'wrong choice not refused / tab More not opened');
		await p.selectOption('#uf_e2e_loc', 'ZH');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		expect(sql("SELECT CONCAT(e2e_loc, '/', e2e_badge) FROM zzzzsys_nunext_user_ext WHERE zzzzsys_user_id = '" + anna + "'") === 'ZH/4711', 'values not saved');
		expect(/tab=more/.test(p.url()), 'not back on the tab More: ' + p.url());
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'user_updated' AND record_id = '" + anna + "' AND details LIKE '%field:e2e_badge%'") !== '0', 'change not logged');
		// unique
		await open(p, 'index.php?p=user&id=' + bob + '&tab=more');
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
	// 0.8.499: in developer mode "Back up the whole database too" is not ticked at first
	const devMode = (await p.evaluate(async () => (await fetch('index.php?p=selftest')).status)) !== 404;
	if (devMode && await p.locator('#updBkSql').count()) expect(!(await p.locator('#updBkSql').isChecked()), 'developer mode: whole-database backup ticked');
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

// library packs (0.8.405): build two signed PHPMailer packs (dev/libpack.php, the owner's key), install from _update/, upload,
// roll back, self-test, refuse a changed pack, remove – and the developer page builds one with the key uploaded. Needs SITE and the key.
T.libpacks = async (s) => {
	const key = process.env.NUNEXT_SIGNING_KEY && fs.existsSync(process.env.NUNEXT_SIGNING_KEY) ? process.env.NUNEXT_SIGNING_KEY : path.resolve(__dirname, '../../../nunext-signing.key');
	if (!SITE || !fs.existsSync(key)) { s.skip = 'SITE or signing key missing'; return; }
	const src = [path.join(path.dirname(SITE), 'third_party/phpmailer'), path.join(SITE, 'lib/PHPMailer')].find(d => fs.existsSync(path.join(d, 'PHPMailer.php')));
	if (!src) { s.skip = 'no PHPMailer source'; return; }
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nulp-'));
	const stateFile = path.join(SITE, 'vendor/packs/nunext-libs.php');
	const stateBefore = fs.existsSync(stateFile) ? fs.readFileSync(stateFile) : null;
	const tp = path.join(SITE, 'vendor/packs');
	const keep = path.join(tmp, 'keep');                     // PHPMailer packs installed before the test: put aside, back afterwards
	fs.mkdirSync(keep);
	for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpmailer-/.test(d)) fs.renameSync(path.join(tp, d), path.join(keep, d));
	const cleanup = () => {
		for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpmailer-99\.0\./.test(d)) fs.rmSync(path.join(tp, d), { recursive: true, force: true });
		for (const d of fs.readdirSync(keep)) fs.renameSync(path.join(keep, d), path.join(tp, d));
		for (const f of fs.readdirSync(path.join(SITE, '_update'))) if (/^nunext-lib-phpmailer-99\.0\./.test(f)) fs.unlinkSync(path.join(SITE, '_update', f));
		if (stateBefore) fs.writeFileSync(stateFile, stateBefore); else if (fs.existsSync(stateFile)) fs.unlinkSync(stateFile);
		fs.rmSync(tmp, { recursive: true, force: true });
	};
	try {
		execSync(`php dev/libpack.php ${JSON.stringify(tmp)} ${JSON.stringify(key)} phpmailer=${JSON.stringify(src)}@99.0.1`, { cwd: path.resolve(__dirname, '../..') });
		execSync(`php dev/libpack.php ${JSON.stringify(tmp + '/b')} ${JSON.stringify(key)} phpmailer=${JSON.stringify(src)}@99.0.2`, { cwd: path.resolve(__dirname, '../..') });
		fs.mkdirSync(path.join(SITE, '_update'), { recursive: true });
		fs.copyFileSync(path.join(tmp, 'b/nunext-lib-phpmailer-99.0.2.zip'), path.join(SITE, '_update/nunext-lib-phpmailer-99.0.2.zip'));
		const p = s.ga;
		const go = async () => {                                // a new address each time: a change of the #hash alone does not load the page
			await open(p, 'index.php?p=update&lp=' + Date.now() + '#libraries');
			if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update&lp=' + Date.now() + '#libraries'); }
		};
		const row = async () => (await p.locator('tr[data-slot=phpmailer]').innerText()).replace(/\s+/g, ' ');
		await go();
		expect(/99\.0\.2/.test(await p.locator('tr[data-slot=phpmailer] .nuLpAvail').innerText()), 'pack in _update/ not offered: ' + await row());
		await Promise.all([p.waitForNavigation(), p.click('tr[data-slot=phpmailer] .nuLpInstall')]);
		expect(fs.existsSync(path.join(tp, 'phpmailer-99.0.2/PHPMailer.php')) && fs.existsSync(path.join(tp, 'phpmailer-99.0.2/.htaccess')), 'not installed into vendor/packs/phpmailer-99.0.2/');
		expect(!fs.existsSync(path.join(SITE, '_update/nunext-lib-phpmailer-99.0.2.zip')), 'used pack left in _update/');
		expect(/99\.0\.2/.test(await p.locator('tr[data-slot=phpmailer] .nuLpInUse').innerText()), 'row after install: ' + await row());
		// upload the other version → active, the first one can be rolled back to
		await p.setInputFiles('#lpUpload', path.join(tmp, 'nunext-lib-phpmailer-99.0.1.zip'));
		await Promise.all([p.waitForNavigation(), p.click('#lpUploadBtn')]);
		expect(/99\.0\.1/.test(await p.locator('tr[data-slot=phpmailer] .nuLpInUse').innerText()) && /99\.0\.2/.test(await p.locator('tr[data-slot=phpmailer] .nuLpInUse').innerText()), 'after upload: ' + await row());
		await p.click('tr[data-slot=phpmailer] [data-bs-toggle=dropdown]');
		await p.click('tr[data-slot=phpmailer] button[form="lpF-lp_rollback-phpmailer"]');
		await p.waitForTimeout(400);
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm .js-confirm-ok')]);
		expect(/^99\.0\.2/.test((await p.locator('tr[data-slot=phpmailer] .nuLpInUse .fw-semibold').innerText()).trim()), 'roll back: ' + await row());
		// self-test with the pack
		await p.click('tr[data-slot=phpmailer] [data-bs-toggle=dropdown]');
		await Promise.all([p.waitForNavigation(), p.click('tr[data-slot=phpmailer] button[form="lpF-lp_test-phpmailer"]')]);
		expect(await p.locator('tr[data-slot=phpmailer] .nuLpTest-ok').count() === 1, 'self-test: ' + await row());
		// a changed pack is refused (one byte of the manifest's signature)
		execSync('python3 -', { input: `import zipfile, json\nsrc = zipfile.ZipFile(${JSON.stringify(path.join(tmp, 'nunext-lib-phpmailer-99.0.1.zip'))})\nout = zipfile.ZipFile(${JSON.stringify(path.join(tmp, 'bad.zip'))}, 'w', zipfile.ZIP_DEFLATED)\nfor n in src.namelist():\n    d = src.read(n)\n    if n == 'nunext-lib.json':\n        m = json.loads(d); m['version'] = '99.0.9'; d = json.dumps(m).encode()\n    out.writestr(n, d)\nout.close()\n` });
		await p.setInputFiles('#lpUpload', path.join(tmp, 'bad.zip'));
		await Promise.all([p.waitForNavigation(), p.click('#lpUploadBtn')]);
		expect(await p.locator('.alert-danger').count() >= 1, 'changed pack not refused');
		expect(/^99\.0\.2/.test((await p.locator('tr[data-slot=phpmailer] .nuLpInUse .fw-semibold').innerText()).trim()), 'changed pack changed the installation');
		// developer page: build a pack with the key uploaded
		if ((await open(p, 'index.php?p=libpack')).status() !== 404) {
			if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=libpack'); }
			for (const b of await p.locator('#lpBuildForm input[name="slots[]"]').all()) if (await b.isChecked()) await b.uncheck();
			await p.check('#lpB-phpmailer');
			await p.fill('input[name="ver[phpmailer]"]', '99.0.3');
			await p.setInputFiles('#lpKey', key);
			await Promise.all([p.waitForNavigation(), p.click('#lpBuildBtn')]);
			expect(/nunext-lib-phpmailer-99\.0\.3\.zip/.test(await p.locator('#lpResult').innerText().catch(() => '')), 'developer page: no pack built');
			const idx = await p.evaluate(async (u) => (await fetch(u)).json(), await p.locator('#lpIndexLink').getAttribute('href'));
			expect(idx.format === 'nunext-libs-1' && idx.signature && idx.libs.some(l => l.version === '99.0.3'), 'developer page: index');
			const d = await p.locator('form input[name=action][value=discard]').count();
			if (d) await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=discard]) button')]);
		}
		// remove: all PHPMailer packs gone
		await go();
		await p.click('tr[data-slot=phpmailer] [data-bs-toggle=dropdown]');
		await p.click('tr[data-slot=phpmailer] button[form="lpF-lp_remove-phpmailer"]');
		await p.waitForTimeout(400);
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm .js-confirm-ok')]);
		expect(!fs.readdirSync(tp).some(d => /^phpmailer-99\./.test(d)), 'remove left folders');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event IN ('nunext_lib_install', 'nunext_lib_rollback', 'nunext_lib_remove') AND log_time > NOW() - INTERVAL 10 MINUTE") >= 4, 'log events missing');
	} finally {
		cleanup();
	}
};

// library versions a release needs (0.8.406): a package whose manifest asks for PHPMailer ≥ 99.0.2 → Check package lists it with the pack
// in _update/; "Install first" installs it and comes back to the checked package, which then shows nothing more. Needs PKG, SITE and the key.
T.libreq = async (s) => {
	const pkg = process.env.PKG;
	const key = process.env.NUNEXT_SIGNING_KEY && fs.existsSync(process.env.NUNEXT_SIGNING_KEY) ? process.env.NUNEXT_SIGNING_KEY : path.resolve(__dirname, '../../../nunext-signing.key');
	if (!pkg || !fs.existsSync(pkg) || !SITE || !fs.existsSync(key)) { s.skip = 'PKG, SITE or signing key missing'; return; }
	const src = [path.join(path.dirname(SITE), 'third_party/phpmailer'), path.join(SITE, 'lib/PHPMailer')].find(d => fs.existsSync(path.join(d, 'PHPMailer.php')));
	if (!src) { s.skip = 'no PHPMailer source'; return; }
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nulr-'));
	const tp = path.join(SITE, 'vendor/packs'), stateFile = path.join(tp, 'nunext-libs.php');
	const stateBefore = fs.existsSync(stateFile) ? fs.readFileSync(stateFile) : null;
	const keep = path.join(tmp, 'keep'); fs.mkdirSync(keep);
	for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpmailer-/.test(d)) fs.renameSync(path.join(tp, d), path.join(keep, d));
	const p = s.ga;
	try {
		execSync(`php dev/libpack.php ${JSON.stringify(tmp)} ${JSON.stringify(key)} phpmailer=${JSON.stringify(src)}@99.0.2`, { cwd: path.resolve(__dirname, '../..') });
		fs.mkdirSync(path.join(SITE, '_update'), { recursive: true });
		fs.copyFileSync(path.join(tmp, 'nunext-lib-phpmailer-99.0.2.zip'), path.join(SITE, '_update/nunext-lib-phpmailer-99.0.2.zip'));
		// the package with the requirement (requires_libs is not part of the signed payload – the signature stays valid)
		execSync('python3 -', { input: `import zipfile, json\nsrc = zipfile.ZipFile(${JSON.stringify(pkg)})\nout = zipfile.ZipFile(${JSON.stringify(path.join(tmp, 'pkg.zip'))}, 'w', zipfile.ZIP_DEFLATED)\nfor n in src.namelist():\n    d = src.read(n)\n    if n.endswith('nunext-manifest.json'):\n        m = json.loads(d); m['requires_libs'] = {'phpmailer': {'min': '99.0.2', 'needed': True}}; d = json.dumps(m).encode()\n    out.writestr(n, d)\nout.close()\n` });
		await open(p, 'index.php?p=update&lr=' + Date.now());
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update'); }
		if (!(await p.locator('#updOther').isVisible())) { await p.click('#updOtherBtn'); await p.waitForTimeout(500); }
		await p.setInputFiles('#updPackage', path.join(tmp, 'pkg.zip'));
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#updUpload')]);
		await p.waitForTimeout(500);
		expect(await p.locator('#updLibReq li[data-slot=phpmailer]').count() === 1, 'requirement not listed');
		expect(/99\.0\.2/.test(await p.locator('#updLibReq li[data-slot=phpmailer]').innerText()), 'pack in _update/ not named');
		const checkUrl = p.url();
		await Promise.all([p.waitForNavigation(), p.click('#updLibReqInstall')]);
		await p.waitForTimeout(400);
		expect(/check=/.test(p.url()) && p.url().split('check=')[1] === checkUrl.split('check=')[1], 'not back on the checked package: ' + p.url());
		expect(await p.locator('#updLibReq').count() === 0, 'requirement still listed after installing the pack');
		expect(fs.existsSync(path.join(tp, 'phpmailer-99.0.2/PHPMailer.php')), 'pack not installed');
		await Promise.all([p.waitForNavigation(), p.click('#updCancel')]);
	} finally {
		for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpmailer-99\./.test(d)) fs.rmSync(path.join(tp, d), { recursive: true, force: true });
		for (const d of fs.readdirSync(keep)) fs.renameSync(path.join(keep, d), path.join(tp, d));
		for (const f of fs.readdirSync(path.join(SITE, '_update'))) if (/^nunext-lib-phpmailer-99\./.test(f)) fs.unlinkSync(path.join(SITE, '_update', f));
		if (stateBefore) fs.writeFileSync(stateFile, stateBefore); else if (fs.existsSync(stateFile)) fs.unlinkSync(stateFile);
		fs.rmSync(tmp, { recursive: true, force: true });
	}
};

// Uppy uploads (0.8.459): a file object with target "File System" and nuBuilder's Uppy template (library pack uppy) uploads through
// ?p=upload into the folder of the procedure; executable names refused before any procedure, the hardened template checks type +
// extension and never overwrites; nothing of it is saved with the record; update step 0020 adds the template rows when missing
// side bar (0.8.460, core/nusidebar.php): off by default, on per user (user menu) or for everyone (globeadmin); favourites = the start page's ☆,
// recently used pages and forms on the server (order, rights), ☆ in the side bar, clear; a drawer on every width (0.8.513: no icon rail)
T.sidebar = async (s) => {
	const p = s.ga;
	const benId = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!benId) { s.skip = 'no sample users'; return; }
	const keepBen = sql(`SELECT IFNULL(HEX(sus_json), '-') FROM zzzzsys_user WHERE zzzzsys_user_id = '${benId}'`);
	const cleanup = () => {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('sidebar_ga', 'sidebar_default')");
		sql(`UPDATE zzzzsys_user SET sus_json = ${keepBen === '-' ? 'NULL' : `UNHEX('${keepBen}')`} WHERE zzzzsys_user_id = '${benId}'`);
		sql('DELETE FROM zzzzsys_nunext_recent');
	};
	cleanup();
	const items = sec => p.locator(`#nuSidebar .nuSbSec-${sec} [data-nu-sb]`).evaluateAll(e => e.map(x => x.getAttribute('data-nu-sb')));
	const openSb = async () => { if (!await p.evaluate(() => document.body.classList.contains('nuSbOpen'))) { await p.click('#nuSbOpen'); await p.waitForTimeout(300); } };
	try {
		await open(p, 'index.php?p=home&tiles=1');
		expect(await p.locator('#nuSidebar').count() === 0, 'side bar on by default');
		await p.click('.nu-navbar .nu-user-name');
		await Promise.all([p.waitForNavigation(), p.click('#nuSidebarToggle')]);
		expect(await p.locator('#nuSidebar').count() === 1 && /tiles=1/.test(p.url()), 'side bar not switched on (back to the page): ' + p.url());
		for (const u of ['userfields', 'forms', 'form&f=samplecustomer', 'update', 'users']) await open(p, 'index.php?p=' + u);
		let r = await items('recent');
		expect(JSON.stringify(r) === JSON.stringify(['users', 'update', 'form:samplecustomer', 'forms', 'userfields']), 'recently used: ' + JSON.stringify(r));
		// the current page is marked in each section it is in (Users may also be a favourite, e.g. after home_favourites)
		expect(await p.locator('#nuSidebar .nuSbSec-recent [data-nu-sb="users"].active a[aria-current="page"]').count() === 1, 'current page not marked');
		// ☆ on a recently used tile → favourite of the start page; again → gone
		// desktop (0.8.513): no column next to the page, no icon rail – the drawer opens from the button left of the logo
		expect(!await p.locator('#nuSidebar').isVisible() && await p.locator('#nuSbCollapse').count() === 0, 'desktop: side bar shown in the page / icon rail button');
		const leftOfLogo = await p.evaluate(() => { const b = document.getElementById('nuSbOpen').getBoundingClientRect(), l = document.querySelector('.nu-navbar .navbar-brand').getBoundingClientRect();
			return b.width > 0 && b.right <= l.left + 1; });
		expect(leftOfLogo, 'desktop: open button not left of the logo');
		// 0.8.515: the × sits where the open button is – open and close without moving the mouse
		const ob = await p.locator('#nuSbOpen').boundingBox();
		await openSb();
		const cb = await p.locator('#nuSbClose').boundingBox();
		expect(Math.abs(cb.x - ob.x) < 24 && Math.abs(cb.y - ob.y) < 24, 'close button not at the open button: ' + JSON.stringify([ob, cb]));
		await p.click('#nuSbClose'); await p.waitForTimeout(300);
		expect(!await p.evaluate(() => document.body.classList.contains('nuSbOpen')), 'close button did not close the drawer');
		const wasFav = (await items('fav')).includes('update');
		await openSb();
		await Promise.all([p.waitForNavigation(), p.click('#nuSidebar .nuSbSec-recent [data-nu-sb="update"] .nuSbFav', { force: true })]);
		expect((await items('fav')).includes('update') !== wasFav, 'star did not change the favourite');
		await open(p, 'index.php?p=home&tiles=1');
		expect((await p.locator('[data-nu-tile="update"][data-nu-fav="1"]').count() > 0) !== wasFav, 'start page does not show the same favourite');
		await openSb();
		await Promise.all([p.waitForNavigation(), p.click('#nuSidebar .nuSbSec-recent [data-nu-sb="update"] .nuSbFav', { force: true })]);
		expect((await items('fav')).includes('update') === wasFav, 'second star click did not undo');
		expect(await p.locator('#nuSidebar [data-nu-sb="form:samplecustomer"] .nuSbFav').count() === 0, 'a form that is no tile offers a star');
		await openSb();
		// 0.8.489: right-click on "Recently used" → menu "Clear recently used" (same POST as the ✕ button)
		await p.click('#nuSidebar .nuSbSec-recent .nuSbSecTitle .nuSbText', { button: 'right' });
		expect(await p.locator('.nuSbMenu .dropdown-item').count() === 1, 'no context menu on Recently used');
		await p.keyboard.press('Escape');
		expect(await p.locator('.nuSbMenu').count() === 0, 'context menu not closed by Esc');
		await openSb();
		await p.click('#nuSidebar .nuSbSec-recent .nuSbSecTitle .nuSbText', { button: 'right' });
		await Promise.all([p.waitForNavigation(), p.click('.nuSbMenu .dropdown-item')]);
		r = await items('recent');
		expect(r.length <= 1, 'clear: ' + JSON.stringify(r));
		// default for everyone → sample_ben sees it with his start page's favourites; a form he may not open is left out
		await p.click('.nu-navbar .nu-user-name');
		await Promise.all([p.waitForNavigation(), p.click('#nuSidebarDefault')]);
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'sidebar_default'") === '1', 'default not stored');
		const ben = await login('sample_ben', UPW);
		expect(await ben.locator('#nuSidebar').count() === 1, 'default on: sample_ben has no side bar');
		const benForm = sql("SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form af JOIN zzzzsys_user u ON u.sus_zzzzsys_access_id = af.slf_zzzzsys_access_id WHERE u.zzzzsys_user_id = '" + benId + "' AND slf_zzzzsys_form_id NOT LIKE 'nu%' LIMIT 1");
		if (benForm) await open(ben, 'index.php?p=form&f=' + benForm);
		sql(`INSERT INTO zzzzsys_nunext_recent (zzzzsys_nunext_recent_id, user_id, item, used_at, stamp) VALUES ('e2esb1', '${benId}', 'form:nuform', NOW(), '20990101000000000000')`);
		await open(ben, 'index.php?p=home');
		const benRecent = await ben.locator('#nuSidebar .nuSbSec-recent [data-nu-sb]').evaluateAll(e => e.map(x => x.getAttribute('data-nu-sb')));
		expect(!benRecent.includes('form:nuform') && (!benForm || benRecent.includes('form:' + benForm)), 'sample_ben recent: ' + JSON.stringify(benRecent));
		// his own choice wins over the default
		await ben.click('.nu-navbar .nu-user-name');
		await Promise.all([ben.waitForNavigation(), ben.click('#nuSidebarToggle')]);
		expect(await ben.locator('#nuSidebar').count() === 0 && /"NUNEXT_SIDEBAR":"0"/.test(sql(`SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '${benId}'`)), 'own choice off not kept');
		expect(ben.errors.length === 0, ben.errors.slice(0, 2).join(' | '));
		await ben.context().close();
		// phone: a drawer from the top bar, Esc closes it, nothing wider than the screen
		const ph = await login(GA[0], GA[1], { viewport: { width: 390, height: 800 } });
		await open(ph, 'index.php?p=users');
		expect(!await ph.locator('#nuSidebar').isVisible() && await ph.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'phone: side bar in the page');
		await ph.click('#nuSbOpen'); await ph.waitForTimeout(300);
		expect(await ph.locator('#nuSidebar').isVisible(), 'drawer not opened');
		await ph.keyboard.press('Escape'); await ph.waitForTimeout(300);
		expect(!await ph.evaluate(() => document.body.classList.contains('nuSbOpen')), 'Esc did not close the drawer');
		await ph.context().close();
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		cleanup();
	}
};

T.uppy_upload = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'vendor/packs')) || !fs.readdirSync(path.join(SITE, 'vendor/packs')).some(d => /^uppy-/.test(d))) { s.skip = 'Uppy library pack not installed on the test site'; return; }
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nunext-e2e-uppy-'));
	fs.chmodSync(dir, 0o777);
	const php = k => execSync(`php -r 'define("NU_ROOT", "."); require "core/nuupload.php"; echo NuUpload::${k};'`).toString();
	const hex = t => Buffer.from(t, 'utf8').toString('hex');
	const proc = php('PROCEDURE').replace("$targetDirectory = dirname($_SERVER['DOCUMENT_ROOT']) . '/nu_uploads/';", `$targetDirectory = '${dir}/';`);
	const html = php('HTML').replace("procedure: 'nu_upload_file_template'", "procedure: 'e2e_upload'")
		.replace("$(document).ready(function () {", "window.e2eUppy = [];\nwindow.nuOnFileUploadComplete = function (t, id, r, m) { window.e2eUppy.push(t + '|' + id + '|' + m); };\n$(document).ready(function () {");
	expect(proc.includes(dir) && html.includes("'e2e_upload'"), 'template text not as expected');
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	const files = { png: path.join(dir, 'src-pic.png'), php: path.join(dir, 'src-evil.php'), html: path.join(dir, 'src-page.html') };
	const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
	const src = fs.mkdtempSync(path.join(os.tmpdir(), 'nunext-e2e-uppysrc-'));
	const p = s.ga;
	try {
		sql(`INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_run, sph_global, sph_system, sph_status) VALUES ('e2e_upload', 'e2e_upload', 'e2e', UNHEX('${hex(proc)}'), 'hide', '1', '0', '1')`);
		sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_all_validate, sob_input_type, sob_input_file_target, sob_html_code)
			VALUES ('e2e_uppy_obj', 'samplecustomer', '${tab}', 'input', 'e2e_uppy', 'Upload', 997, 900, 20, 500, 320, '0', '1', 'file', '1', UNHEX('${hex(html)}'))`);
		const cust = sql('SELECT MIN(sample_customer_id) FROM sample_customer');
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust);
		await p.waitForSelector('#e2e_uppy_uppy_div .uppy-Dashboard', { timeout: 10000 });
		expect(await p.locator('#e2e_uppy[data-nu-field]').count() === 0 && await p.locator('#e2e_uppy.nuFileUppy').count() === 1, 'Uppy object rendered as a field');
		expect(await p.locator('#nuUppyMissing').count() === 0, 'missing notice although the pack is installed');
		const upload = async (name, body) => {
			const f = path.join(src, name); fs.writeFileSync(f, body);
			const before = await p.evaluate(() => window.e2eUppy.length);
			await p.setInputFiles('#e2e_uppy_uppy_div input.uppy-Dashboard-input', f);
			await p.click('#e2e_uppy_uppy_div .uppy-StatusBar-actionBtn--upload');
			await p.waitForFunction(n => window.e2eUppy.length > n, before, { timeout: 15000 });
			const msg = await p.evaluate(() => window.e2eUppy[window.e2eUppy.length - 1]);
			return msg;
		};
		// each upload on a freshly opened record (one Uppy dashboard per page load)
		let m = await upload('pic.png', PNG);
		expect(/^FS\|e2e_uppy\|The file pic\.png has been uploaded\./.test(m) && fs.existsSync(path.join(dir, 'pic.png')), 'png upload: ' + m);
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust); await p.waitForSelector('#e2e_uppy_uppy_div .uppy-Dashboard');
		m = await upload('pic.png', PNG);
		expect(/pic-1\.png has been uploaded/.test(m) && fs.existsSync(path.join(dir, 'pic-1.png')), 'second upload overwrote or failed: ' + m);
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust); await p.waitForSelector('#e2e_uppy_uppy_div .uppy-Dashboard');
		// refused files: nuBuilder's template shows only "Error uploading file(s)." (its onAfterResponse swallows the 401 message) –
		// the route's own answer is checked directly below
		for (const [name, body] of [['evil.php', PNG], ['page.html', '<html><body><script>alert(1)</script></body></html>'], ['fake.pdf', PNG]]) {
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust); await p.waitForSelector('#e2e_uppy_uppy_div .uppy-Dashboard');
			m = await upload(name, body);
			expect(/Error uploading|Invalid file type/.test(m) && !fs.existsSync(path.join(dir, name)), name + ' not refused: ' + m);
		}
		const direct = (name, b64) => p.evaluate(async ([name, b64]) => {
			const fd = new FormData(); fd.append('file', new Blob([Uint8Array.from(atob(b64), c => c.charCodeAt(0))]), name); fd.append('procedure', 'e2e_upload'); fd.append('form_id', 'samplecustomer');
			const r = await fetch('index.php?p=upload', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': window.nuNext.csrf } }); return r.status + ' ' + JSON.stringify(await r.json()); }, [name, b64]);
		const r1 = await direct('x.php.png', PNG.toString('base64')), r2 = await direct('fake.pdf', PNG.toString('base64')), r3 = await direct('ok.png', PNG.toString('base64'));
		expect(/^401 .*"message":"Invalid file type"/.test(r1), 'route guard (x.php.png): ' + r1);
		expect(/^401 .*"message":"Invalid file type"/.test(r2), 'template type check (fake.pdf): ' + r2);
		expect(/^201 .*"file":"ok.png"/.test(r3) && fs.existsSync(path.join(dir, 'ok.png')), 'direct upload: ' + r3);
		expect(/"url":"ok\.png"/.test(r3) && !r3.includes(dir), 'the answer shows the server path: ' + r3);   // 0.8.478 (security assessment C1)
		expect(fs.existsSync(path.join(dir, '.htaccess')) && /Require all denied/.test(fs.readFileSync(path.join(dir, '.htaccess'), 'utf8')), 'upload folder without deny-all .htaccess');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'file_upload' AND record_id = 'e2e_upload'") === 3
			&& +sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'file_upload_refused' AND record_id = 'e2e_upload'") === 5, 'log rows: '
			+ sql("SELECT GROUP_CONCAT(event) FROM zzzzsys_nunext_log WHERE record_id = 'e2e_upload'"));
		// without the CSRF token: nuBuilder's error answer, nothing stored
		const noCsrf = await p.evaluate(async () => { const fd = new FormData(); fd.append('file', new Blob(['x'], { type: 'text/plain' }), 'a.txt'); fd.append('procedure', 'e2e_upload');
			const r = await fetch('index.php?p=upload', { method: 'POST', body: fd }); return r.status + ' ' + (await r.json()).message; });
		expect(/^400 /.test(noCsrf) && !fs.existsSync(path.join(dir, 'a.txt')), 'upload without CSRF token: ' + noCsrf);
		// the record saves without the object (no column, "required" ignored), the Uppy input never marks the form as edited
		expect(await p.locator('.nuSaveEdited').count() === 0, 'Uppy marked the form as edited');
		await p.locator('[data-nu-field="cus_name"]').first().fill('E2E Uppy save'); await p.locator('[data-nu-field="cus_name"]').first().dispatchEvent('change');
		await save(p);
		expect(sql(`SELECT cus_name FROM sample_customer WHERE sample_customer_id = '${cust}'`) === 'E2E Uppy save', 'record not saved with an Uppy object on the form');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
		// update step 0020 on a database without nuBuilder's rows: the hardened procedure and nuBuilder's snippet are added
		const keep = sql("SELECT CONCAT(zzzzsys_php_id, '|', sph_code) FROM zzzzsys_php WHERE sph_code = 'nu_upload_file_template'");
		sql("UPDATE zzzzsys_php SET sph_code = 'e2e_kept_upload' WHERE sph_code = 'nu_upload_file_template'; UPDATE zzzzsys_php SET zzzzsys_php_id = 'e2e_kept_upload_id' WHERE zzzzsys_php_id = 'nu62da72900a02b9a';"
			+ " UPDATE zzzzsys_code_snippet SET cot_code = 'e2e_kept_snippet', zzzzsys_code_snippet_id = 'e2e_kept_snippet_id' WHERE cot_code = 'nu_upload_file_uppy_code_template'; DELETE FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0020-upload-template'");
		try {
			const q = await login(GA[0], GA[1]);
			expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0020-upload-template'") === '1', 'step 0020 not run');
			expect(/never overwritten/.test(sql("SELECT sph_php FROM zzzzsys_php WHERE sph_code = 'nu_upload_file_template' AND zzzzsys_php_id = 'nu62da72900a02b9a' AND sph_global = '1'")), 'hardened procedure not added');
			expect(sql("SELECT HEX(cot_source_code) FROM zzzzsys_code_snippet WHERE cot_code = 'nu_upload_file_uppy_code_template'") === hex(php('HTML')).toUpperCase(), 'snippet not added as nuBuilder\'s');
			await q.context().close();
		} finally {
			sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'nu62da72900a02b9a'; DELETE FROM zzzzsys_code_snippet WHERE zzzzsys_code_snippet_id = 'nu6866e0177fc5399';"
				+ " UPDATE zzzzsys_php SET zzzzsys_php_id = 'nu62da72900a02b9a' WHERE zzzzsys_php_id = 'e2e_kept_upload_id'; UPDATE zzzzsys_php SET sph_code = 'nu_upload_file_template' WHERE sph_code = 'e2e_kept_upload';"
				+ " UPDATE zzzzsys_code_snippet SET cot_code = 'nu_upload_file_uppy_code_template', zzzzsys_code_snippet_id = 'nu6866e0177fc5399' WHERE zzzzsys_code_snippet_id = 'e2e_kept_snippet_id'");
			expect(sql("SELECT CONCAT(zzzzsys_php_id, '|', sph_code) FROM zzzzsys_php WHERE sph_code = 'nu_upload_file_template'") === keep, 'template row not restored');
		}
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2e_uppy_obj'; DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_upload'; DELETE FROM zzzzsys_nunext_log WHERE record_id = 'e2e_upload'");
		fs.rmSync(dir, { recursive: true, force: true }); fs.rmSync(src, { recursive: true, force: true });
	}
};

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
// sign-in sets them up, other users wait for it; a database with nuBuilder's tables stays untouched while it is blocked
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
	const config = allow => {
		const c = fs.readFileSync(path.join(SITE, 'nuconfig.php'), 'utf8').replace(/(\$nuConfigDBName\s*=\s*)"[^"]*"/, `$1"${DBN}"`).replace(/\?>\s*$/, '');
		fs.writeFileSync(cfgFile, c + (allow ? '\n$nuConfigAllowExistingNuDB = true;\n' : '\n'));
	};
	const B2 = BASE.replace(/:\d+\//, ':' + PORT + '/');
	const signIn = async (user, pw) => {
		const ctx = await browser.newContext({ locale: 'en-GB' }); const p = await ctx.newPage();
		const r0 = await p.goto(B2 + 'index.php?p=login');
		await p.fill('#username', user); await p.fill('#password', pw);
		const [r] = await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('form button[type=submit]:not([name=demo_user])')]);
		const out = { url: p.url(), status: r ? r.status() : 0, first: r0.status(), text: (await p.textContent('body')).replace(/\s+/g, ' ') };
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
		fresh(); config(true); await serve();
		let r = await signIn('nobody', 'wrong');
		expect(own() === 0, 'anonymous requests created ' + own() + ' own tables');
		r = await signIn('b2user', TT_PW);
		expect(/p=login/.test(r.url) && /globeadmin/i.test(r.text) && own() === 0, 'a user signed in before the globeadmin: ' + r.url + ' ' + own());
		r = await signIn(GA[0], GA[1]);
		expect(/p=home/.test(r.url) && own() > 10, 'the globeadmin\'s first sign-in did not set up the tables: ' + own());
		r = await signIn('b2user', TT_PW);
		expect(!/p=login/.test(r.url), 'user cannot sign in after the set-up: ' + r.url);
		// blocked (existing nuBuilder database without $nuConfigAllowExistingNuDB): nothing is written, not even by the globeadmin
		fresh(); config(false); await serve();
		r = await signIn(GA[0], GA[1]);
		expect(r.status === 503 && own() === 0, 'blocked database: status ' + r.status + ', own tables ' + own());
	} finally {
		if (srv) srv.kill();
		try { fs.unlinkSync(cfgFile); } catch (e) { /* gone */ }
		execSync('mysql', { input: `DROP DATABASE IF EXISTS ${DBN};` });
	}
};

// smaller differences of the gap analysis (0.8.462): #USER_NAME# = sus_name, nuLookupRecord() with ID, nuFORMdata with
// nuGetNuDataValue / nuSetNuDataValue in Before Save, a blank group footer for an empty (NULL) report group
T.gap_small = async (s) => {
	ttInstall();
	let p;
	try {
		sql("UPDATE zzzzsys_php SET sph_php = CONCAT(sph_php, '\n$d = nuHash()[''nuFORMdata''];\nnuSetNuDataValue($d, '''', ''cus_name'', nuGetNuDataValue($d, '''', ''cus_name'') . '' *'');\nnuSetNuDataValue($d, ''tt_sf'', ''ord_item'', ''first row'');') WHERE zzzzsys_php_id = 'ttcus_BS'");
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
		sql("UPDATE zzzzsys_php SET sph_php = CONCAT(sph_php, '\n$g = ''#USER_GROUP_ID#'';') WHERE zzzzsys_php_id = 'ttcus_BS'");
		const r = await open(s.ga, 'index.php?p=compat');
		expect(r.status() === 200, 'compatibility page: ' + r.status());
		const ids = await s.ga.$$eval('#compat_findings tbody tr td:first-child', tds => tds.map(t => t.textContent.trim()));
		const want = ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'];
		expect(want.every(id => ids.includes(id)), 'findings missing: ' + want.filter(id => !ids.includes(id)).join(', ') + ' (have ' + ids.join(' ') + ')');
	} finally {
		ttRemove();
	}
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

// To do page (0.8.467): sections fold, one button folds / opens all, folded sections remembered per device
T.todo_fold = async (s) => {
	const p = s.ga;
	const r = await open(p, 'index.php?p=todo');
	if (r.status() === 404) { s.skip = 'developer mode off'; return; }
	if (!(await p.locator('#nuTodoAll').count())) { s.skip = 'TODO.md missing or one section'; return; }
	const n = await p.locator('.nuTodoSec').count(), openN = () => p.locator('.nuTodoSec[open]').count();
	expect(n > 1 && await openN() === 0 && /Expand all/.test(await p.textContent('#nuTodoAll')), 'sections not all folded at first (0.8.509): ' + n);
	expect(await p.locator('.nuTodoSec .nuTodoOpen').count() > 0, 'no count of open points');
	await p.click('.nuTodoSec >> nth=0 >> summary'); await p.waitForTimeout(200);
	expect(await openN() === 1 && /Expand all/.test(await p.textContent('#nuTodoAll')), 'one section');
	await p.click('#nuTodoAll'); await p.waitForTimeout(200);
	expect(await openN() === n && /Collapse all/.test(await p.textContent('#nuTodoAll')), 'expand all');
	await p.click('#nuTodoAll'); await p.waitForTimeout(200);
	expect(await openN() === 0 && /Expand all/.test(await p.textContent('#nuTodoAll')), 'collapse all');
	await open(p, 'index.php?p=todo');
	expect(await openN() === 0, 'not folded again after opening the page');
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

// Notes (0.8.469): delete button at the very left of each row – asks, deletes, the list stays; no button without the delete right
T.notes_row_delete = async (s) => {
	const p = s.ga;
	if (!sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nunotes'") || !+sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zzzzsys_note'")) { s.skip = 'no notes form'; return; }
	sql("INSERT INTO zzzzsys_note (zzzzsys_note_id, not_title, not_content) VALUES ('nxdel1', 'E2E delete me', 'x'), ('nxdel2', 'E2E keep me', 'y')");
	try {
		await open(p, 'index.php?p=form&f=nunotes');
		const cells = await p.$$eval('#nuBrowseTable tbody tr.nuBrowseRow', trs => trs.map(t => t.firstElementChild.querySelector('.nuRowDel') ? 1 : 0));
		expect(cells.length > 1 && cells.every(Boolean), 'delete button is not the first cell of every row: ' + JSON.stringify(cells));
		const btn = '#nuBrowseTable .nuRowDel[data-nu-pk="nxdel1"]';
		expect(await p.locator(btn).count() === 1, 'no button for the test note (search?)');
		await p.click(btn); await p.waitForTimeout(400);
		await p.click('.jconfirm .btn-red'); await p.waitForLoadState('load'); await p.waitForTimeout(800);
		expect(/f=nunotes/.test(p.url()) && !/r=/.test(p.url()), 'did not stay on the list: ' + p.url());
		expect(sql("SELECT COUNT(*) FROM zzzzsys_note WHERE zzzzsys_note_id IN ('nxdel1', 'nxdel2')") === '1' && sql("SELECT zzzzsys_note_id FROM zzzzsys_note WHERE zzzzsys_note_id LIKE 'nxdel%'") === 'nxdel2', 'wrong note deleted');
		expect(await p.locator('#nuBrowseTable .nuRowDel[data-nu-pk="nxdel1"]').count() === 0, 'deleted note still listed');
		// a cancelled question deletes nothing, a click on the row still opens the note
		await p.click('#nuBrowseTable .nuRowDel[data-nu-pk="nxdel2"]'); await p.waitForTimeout(400);
		await p.click('.jconfirm .btn-default, .jconfirm button:not(.btn-red)'); await p.waitForTimeout(400);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_note WHERE zzzzsys_note_id = 'nxdel2'") === '1', 'cancel deleted the note');
		// user without the delete right (sample application, if it has a notes right): no buttons
		const anna = await login('sample_anna', UPW);
		const r = await open(anna, 'index.php?p=form&f=nunotes');
		if (r.status() === 200 && !(await anna.evaluate(() => !!(window.nuNext && window.nuNext.rights && window.nuNext.rights.delete)))) expect(await anna.locator('.nuRowDel').count() === 0, 'button without the delete right');
		await anna.context().close();
	} finally {
		sql("DELETE FROM zzzzsys_note WHERE zzzzsys_note_id LIKE 'nxdel%'");
	}
};

// Notes (0.8.508): a note with an empty editor but text on the tab Additional opens on that tab; a note with content on the first
T.notes_open_tab = async (s) => {
	const p = s.ga;
	if (!sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nunotes'") || !+sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zzzzsys_note'")) { s.skip = 'no notes form'; return; }
	sql("INSERT INTO zzzzsys_note (zzzzsys_note_id, not_title, not_content, not_text) VALUES ('nxtab1', 'E2E only additional', '<p>&nbsp;</p>', 'some text'), ('nxtab2', 'E2E both', '<p>Hi</p>', 'more')");
	try {
		const activeTab = async (r) => { await open(p, 'index.php?p=form&f=nunotes&r=' + r); await p.waitForTimeout(500);
			return p.evaluate(() => { const a = document.querySelector('.nuTab.active'); return a ? a.getAttribute('data-nu-tab') : null; }); };
		expect(await activeTab('nxtab1') === '1', 'note with only additional text: not on the tab Additional');
		expect(await activeTab('nxtab2') === '0', 'note with content: not on the first tab');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { sql("DELETE FROM zzzzsys_note WHERE zzzzsys_note_id LIKE 'nxtab%'"); }
};

// confirmation dialogs (jquery-confirm) in dark mode (0.8.470): dark box, light text – the library's box stayed white with light text
T.confirm_dark = async (s) => {
	sql("INSERT INTO zzzzsys_note (zzzzsys_note_id, not_title, not_content) VALUES ('nxdark1', 'E2E dark dialog', 'x')");
	const ctx = await browser.newContext({ locale: 'en-GB', storageState: await s.ga.context().storageState() });
	await ctx.addInitScript(() => { try { localStorage.setItem('nunext-theme', 'dark'); } catch (e) { /* */ } });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=form&f=nunotes');
		await p.click('#nuBrowseTable .nuRowDel[data-nu-pk="nxdark1"]'); await p.waitForTimeout(600);
		const c = await p.evaluate(() => {
			const lum = rgb => { const m = rgb.match(/\d+(\.\d+)?/g).map(Number).slice(0, 3).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]; };
			const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
			const box = document.querySelector('.jconfirm-box'), bg = getComputedStyle(box).backgroundColor;
			return { dark: document.documentElement.getAttribute('data-bs-theme') === 'dark', title: ratio(getComputedStyle(box.querySelector('.jconfirm-title')).color, bg), text: ratio(getComputedStyle(box.querySelector('.jconfirm-content')).color, bg) };
		});
		expect(c.dark && c.title >= 4.5 && c.text >= 4.5, 'dialog text not readable in dark mode: ' + JSON.stringify(c));
	} finally {
		await ctx.close();
		sql("DELETE FROM zzzzsys_note WHERE zzzzsys_note_id = 'nxdark1'");
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
		expect(v.cards && !v.table && await p.locator('[data-lv-phone-toggle]').isVisible(), 'phone: cards by default: ' + JSON.stringify(v));
		await p.click('[data-lv-phone-toggle]'); await p.waitForTimeout(200);
		v = await vis();
		expect(v.table && !v.cards && await p.locator('[data-lv-phone-toggle]').getAttribute('aria-pressed') === 'true', 'toggle to table: ' + JSON.stringify(v));
		const pos = await p.evaluate(() => getComputedStyle(document.querySelector('.nuLvTableWrap td:first-child')).position);
		const wide = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
		expect(pos === 'sticky' && !wide, 'phone table: first column sticky, page not wider than the screen: ' + pos + ' ' + wide);
		await p.reload(); await p.waitForTimeout(200);
		v = await vis();
		expect(v.table && !v.cards, 'table not remembered: ' + JSON.stringify(v));
		await p.click('[data-lv-phone-toggle]'); await p.waitForTimeout(200);
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

// title bars (0.8.474): heading, optional subtitle, then ONE row with the buttons – never a button or text beside them (the procedure
// page showed its ID left of Back)
T.title_bars = async (s) => {
	const p = s.ga;
	const proc = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE RIGHT(zzzzsys_php_id, 3) NOT IN ('_BB', '_BE', '_BS', '_AS', '_BD', '_AD', '_AB') ORDER BY sph_code LIMIT 1");
	const pages = ['index.php?p=procedures&id=' + encodeURIComponent(proc), 'index.php?p=procedures&id=new',
		'index.php?p=sqlbuilder&id=new', 'index.php?p=items&id=new', 'index.php?p=translations&id=new', 'index.php?p=accesslevel&id=new',
		'index.php?p=permissions&id=new', 'index.php?p=user&id=new'];
	for (const u of pages) {
		const r = await open(p, u);
		if (r.status() !== 200) continue;
		const bad = await p.evaluate(() => {
			const tb = document.querySelector('.nuTitleBar');
			if (!tb) return 'no title bar';
			const kids = Array.from(tb.children).filter(c => !(c.matches('h1, h2') || c.classList.contains('nuTitleSub')));
			if (kids.length !== 1 || kids[0].tagName !== 'DIV') return 'beside the heading: ' + kids.map(c => c.tagName + '.' + c.className).join(', ');
			const back = kids[0].querySelector('.btn');
			return back && /bi-arrow-left/.test(back.innerHTML) ? '' : 'Back is not the first button';
		});
		expect(bad === '', u + ': ' + bad);
	}
	await p.setViewportSize({ width: 390, height: 800 });
	try {
		await open(p, pages[0]);
		const pos = await p.evaluate(() => { const sub = document.querySelector('.nuTitleBar .nuTitleSub'), back = document.querySelector('.nuTitleBar .btn'); return sub && back ? sub.getBoundingClientRect().bottom <= back.getBoundingClientRect().top + 1 : null; });
		// since 0.8.501 the ID is in the (i) record info panel – no subtitle; when a page still has one it sits above the buttons
		expect(pos !== false, 'phone: the subtitle is not above the buttons');
		if (pos === null) expect(await p.locator('.nuTitleBar #nuRecInfoBtn').count() === 1, 'phone: no (i) record info with the ID');
	} finally {
		await p.setViewportSize({ width: 1300, height: 900 });
	}
};

// procedure editor tools on a phone (0.8.475): all buttons the same size, "Insert snippet" only as icon
T.proc_tools_phone = async (s) => {
	const id = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE RIGHT(zzzzsys_php_id, 3) NOT IN ('_BB', '_BE', '_BS', '_AS', '_BD', '_AD', '_AB') ORDER BY sph_code LIMIT 1");
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=procedures&id=' + encodeURIComponent(id)); await p.waitForTimeout(600);
		const r = await p.evaluate(() => {
			const bs = Array.from(document.querySelectorAll('.procCodeTools[data-for="sph_php"] .btn')).map(b => b.getBoundingClientRect());
			const snip = document.querySelector('.procCodeTools[data-for="sph_php"] .procSnippetBtn');
			return { n: bs.length, sizes: Array.from(new Set(bs.map(x => Math.round(x.width) + 'x' + Math.round(x.height)))), rows: new Set(bs.map(x => Math.round(x.top))).size,
				label: snip ? getComputedStyle(snip.querySelector('.procSnippetLabel')).display : 'no snippets', aria: snip ? snip.getAttribute('aria-label') : '' };
		});
		expect(r.n >= 9 && r.sizes.length === 1 && r.rows === 1, 'buttons differ in size or wrap: ' + JSON.stringify(r));
		expect(r.label === 'no snippets' || (r.label === 'none' && /snippet/i.test(r.aria)), 'snippet button shows its text on a phone: ' + JSON.stringify(r));
	} finally {
		await ctx.close();
	}
};

// code editors: the label opens Copy / Paste / Paste (replace existing) (0.8.476); http here → Paste falls back to a box to paste into
T.code_clip_menu = async (s) => {
	const p = s.ga;
	const id = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE RIGHT(zzzzsys_php_id, 3) NOT IN ('_BB', '_BE', '_BS', '_AS', '_BD', '_AD', '_AB') ORDER BY sph_code LIMIT 1");
	await open(p, 'index.php?p=procedures&id=' + encodeURIComponent(id)); await p.waitForTimeout(500);
	const cmOf = () => p.evaluate(() => document.querySelector('#sph_php').nextElementSibling.CodeMirror.getValue());
	const before = await cmOf();
	await p.click('label[for="sph_php"]');
	const items = await p.$$eval('.nuClipMenu [role=menuitem]', bs => bs.map(b => b.className.match(/nuClip\w+/)[0] + (b.disabled ? '-off' : '')));
	expect(items.join() === 'nuClipCopy,nuClipPaste,nuClipPasteAll', 'menu items: ' + items.join());
	await p.keyboard.press('Escape');
	expect(!(await p.$('.nuClipMenu')), 'Esc does not close the menu');
	// Paste (replace existing) through the fallback box, then Undo
	await p.click('label[for="sph_php"]'); await p.click('.nuClipPasteAll'); await p.waitForTimeout(200);
	await p.fill('.nuClipManual textarea', '// replaced by e2e'); await p.click('.nuClipManual .btn-primary');
	expect(await cmOf() === '// replaced by e2e', 'replace did not set the code');
	await p.evaluate(() => document.querySelector('#sph_php').nextElementSibling.CodeMirror.undo());
	expect(await cmOf() === before, 'Undo did not bring the code back');
	// Paste at the cursor
	await p.evaluate(() => { const cm = document.querySelector('#sph_php').nextElementSibling.CodeMirror; cm.setCursor(0, 0); });
	await p.click('label[for="sph_php"]'); await p.click('.nuClipPaste'); await p.waitForTimeout(200);
	await p.fill('.nuClipManual textarea', 'X1'); await p.click('.nuClipManual .btn-primary');
	expect(await cmOf() === 'X1' + before, 'paste was not inserted at the cursor');
	// Copy (execCommand on http) shows the badge
	await p.click('label[for="sph_php"]'); await p.click('.nuClipCopy'); await p.waitForTimeout(200);
	expect(!!(await p.$('.nuClipFlash')), 'no feedback after Copy');
	// editor without a label (nuconfig.php editor or builder): a clipboard button instead
	await open(p, 'index.php?p=configfile'); await p.waitForTimeout(500);
	const n = await p.evaluate(() => document.querySelectorAll('.nuClipLabel, .nuCtClip').length);
	expect(n >= 1, 'nuconfig.php editor has no copy / paste menu');
};

// notifications → Manage: the Settings button shows only its icon on phones (0.8.477)
T.ntf_settings_phone = async (s) => {
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=notifications&tab=manage'); await p.waitForTimeout(400);
		const r = await p.evaluate(() => { const b = document.querySelector('#ntfSettingsBtn'); return b ? { txt: b.innerText.trim(), aria: b.getAttribute('aria-label') } : null; });
		expect(r && r.txt === '' && r.aria, 'phone: Settings shows text or has no name: ' + JSON.stringify(r));
		await p.setViewportSize({ width: 1200, height: 800 });
		expect((await p.innerText('#ntfSettingsBtn')).trim() !== '', 'desktop: Settings has no text');
	} finally { await ctx.close(); }
};

// security quick wins of the 0.8.460 assessment (0.8.478): K2 script removed from title / message helpers, K3 file route scoped,
// C1 the unchanged upload template replaced by step 0022, Security check points for record_check / protect_fields / the template
T.sec_quick_wins = async (s) => {
	const p = s.ga;
	const cust = sql("SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1");
	// K2
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust);
	const r = await p.evaluate(async () => {
		window.__x = 0;
		nuSetTitle('<b>T</b><img src="x" onerror="window.__x=1">');
		nuSetLabelText('cus_name', 'Name <svg><animate onbegin="window.__x=2" attributeName="x" dur="1s"/></svg><a href="javascript:window.__x=3">x</a>');
		nuMessage('Title <img src=y onerror="window.__x=4">', ['<i>line</i><script>window.__x=5<\/script>']);
		await new Promise(ok => setTimeout(ok, 600));
		const bc = $('.nuBreadcrumb').last()[0];
		const out = { x: window.__x, bold: !!(bc && bc.querySelector('b')), onerr: document.querySelectorAll('[onerror],[onbegin]').length,
			js: document.querySelectorAll('a[href^="javascript"]').length, italic: !!document.querySelector('#nuMessageDiv i'), title: document.title };
		nuNextSafeHtml(false);
		nuMessage(['<button id="e2eOld" onclick="window.__x=9">old</button>']);
		out.kept = !!document.querySelector('#e2eOld[onclick]');
		nuNextSafeHtml(true);
		out.safe = nuSafeHtml('<p onclick="x()">a</p>');
		return out;
	});
	expect(r.x === 0 && r.onerr === 0 && r.js === 0, 'script ran or stayed: ' + JSON.stringify(r));
	expect(r.bold && r.italic && /^T/.test(r.title), 'formatting lost: ' + JSON.stringify(r));
	expect(r.kept && r.safe === '<p>a</p>', 'nuNextSafeHtml(false) / nuSafeHtml: ' + JSON.stringify(r));
	// K3: a file of an image object on sampleall (not granted to sample_sales) – a file no object uses stays open
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'sampleall' ORDER BY syt_order LIMIT 1");
	const png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	const json = JSON.stringify({ file: Buffer.from(png).toString('base64'), name: 'e2e.png', type: 'image/png', size: 68 });
	sql(`INSERT INTO zzzzsys_file (zzzzsys_file_id, sfi_code, sfi_description, sfi_json) VALUES ('e2e_f_obj', 'e2e_f_obj', 'e2e', '${json}'), ('e2e_f_free', 'e2e_f_free', 'e2e', '${json}')`);
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_image_zzzzsys_file_id)
		VALUES ('e2e_img_obj', 'sampleall', '${tab}', 'image', 'e2e_img', 'Img', 998, 950, 20, 50, 50, 'e2e_f_obj')`);
	const st = async (pg, id) => (await pg.request.get(BASE + 'index.php?p=file&id=' + id)).status();
	let sales, carla;
	try {
		sales = await login('sample_dario', UPW);
		carla = await login('sample_carla', UPW);
		expect(await st(sales, 'e2e_f_obj') === 404, 'file of a form the user cannot open was served');
		expect(await st(sales, 'e2e_f_free') === 200, 'file without image object refused');
		expect(await st(carla, 'e2e_f_obj') === 200, 'file of a granted form refused');
		expect(await st(p, 'e2e_f_obj') === 200, 'globeadmin refused');
	} finally {
		if (sales) await sales.context().close();
		if (carla) await carla.context().close();
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2e_img_obj'; DELETE FROM zzzzsys_file WHERE zzzzsys_file_id IN ('e2e_f_obj', 'e2e_f_free')");
	}
	// C1: step 0022 replaced the unchanged template (the test database has nuBuilder 4.9's); Security check points
	expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0022-upload-template-folder'") === 1, 'step 0022 not applied');
	expect(sql("SELECT sph_php FROM zzzzsys_php WHERE sph_code = 'nu_upload_file_template'").includes('nu_uploads'), 'upload template not replaced');
	await open(p, 'index.php?p=securitycheck');
	const ids = await p.$$eval('[id^="sec_"]', els => els.map(e => e.id + ':' + (e.className.match(/sec-(\w+)/) || [])[1]));
	expect(ids.includes('sec_uploadtpl:ok') && ids.some(i => i.startsWith('sec_recordcheck:')) && ids.some(i => i.startsWith('sec_protectfields:')), 'Security check points: ' + ids.join(' '));
};

// File Manager = the page nuFiles (0.8.479, handover nuFiles C1–C5 / C9): Tiny File Manager removed by step 0023, ?p=filemanager sets up the
// page form for the globeadmin, entries in the start page / ⋮ menu, page API vendor / recentAuth / reauthUrl / dataDir / dangerousName
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
		expect(/assets\/js\/nu\/nucodetools\.js/.test(html) && html.includes('id="nufCodeTools"'), 'editor without the code tool buttons (0.8.482)');
		expect(/codemirror-5\.65\.21\/codemirror-bundle\.js\?v=/.test(html) && !/\?v=0\.8\./.test(html.match(/codemirror-bundle\.js\?v=[^"]*/)[0]), 'vendor() not used for the libraries');
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

// field data access + list column visibility (0.8.480, handover specs 04 / 07): modes enforced on render, save, list, export; rules by
// access level, team, user; the Data access page (rules, preview)
T.field_access = async (s) => {
	const F = 'samplecustomer', T_ = 'sample_customer', id = 'e2efa_rec1';
	const ins = (o, type, subj, mode, mask) => sql(`INSERT INTO zzzzsys_nunext_object_access (zzzzsys_nunext_object_access_id, noa_object_id, noa_form_id, noa_subject_type, noa_subject, noa_mode, noa_mask)
		VALUES ('e2efa_${o}_${type}', 'sample_customer_${o}', '${F}', '${type}', '${subj}', '${mode}', ${mask ? `'${mask}'` : 'NULL'})`);
	const oldJson = sql("SELECT COALESCE(sbr_json, '') FROM zzzzsys_browse WHERE zzzzsys_browse_id = 'sample_customer_b3'");
	sql(`DELETE FROM ${T_} WHERE sample_customer_id LIKE 'e2efa%' OR cus_code IN ('E2EFA', 'NEWX'); INSERT INTO ${T_} (sample_customer_id, cus_code, cus_name, cus_email, cus_phone, cus_country, cus_credit, cus_notes)
		VALUES ('${id}', 'E2EFA', 'Field Access', 'fa@example.com', '0791234567', 'CH', 98765.43, 'WH-MARK-7731')`);
	ins('o9', 'default', '', 'wh'); ins('o9', 'team', 'Lead', 'edit');          // notes: withheld, team Lead edits
	ins('o4', 'default', '', 'mask', 'last:4');                                  // phone: masked
	ins('o3', 'access', 'sample_editor', 'prot');                                // e-mail: protected for editors
	ins('o1', 'default', '', 'ins');                                             // code: insert only
	ins('o8', 'access', 'sample_editor', 'wh');                                  // credit: withheld for editors (list column too)
	sql(`UPDATE zzzzsys_browse SET sbr_json = '{"nunext":{"access":{"default":"hide","rules":[{"type":"team","subject":"Lead","mode":"show"}]}}}' WHERE zzzzsys_browse_id = 'sample_customer_b3'`);
	let ben, carla;
	try {
		ben = await login('sample_ben', UPW);
		await open(ben, `index.php?p=form&f=${F}&r=${id}`);
		let html = await ben.content();
		expect(!html.includes('WH-MARK-7731') && !html.includes('98765') && await ben.locator('#cus_notes, #cus_credit').count() === 0, 'withheld value / control in the form');
		expect(!html.includes('0791234567') && await ben.inputValue('#cus_phone') === '•••• 4567', 'phone not masked: ' + await ben.inputValue('#cus_phone'));
		expect(await ben.locator('#cus_email[readonly]').count() === 1 && await ben.locator('#cus_code[readonly]').count() === 1, 'protected / insert only not locked');
		// forged save: only the editable field changes
		const save = (rid, values) => ben.evaluate(async ([rid, values, F]) => { const r = await fetch('index.php?p=formapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf },
			body: JSON.stringify({ action: 'save', form_id: F, record_id: rid, values, edited: Object.keys(values) }) }); return r.json(); }, [rid, values, F]);
		let r = await save(id, { cus_name: 'Changed', cus_email: 'hack@x.ch', cus_notes: 'hack', cus_phone: 'hack', cus_code: 'HACK', cus_credit: '1' });
		const row = () => sql(`SELECT CONCAT_WS('|', cus_name, cus_email, cus_notes, cus_phone, cus_code, cus_credit) FROM ${T_} WHERE sample_customer_id = '${id}'`);
		expect(r.ok && row() === 'Changed|fa@example.com|WH-MARK-7731|0791234567|E2EFA|98765.43', 'forged save: ' + JSON.stringify(r) + ' ' + row());
		r = await save('-1', { cus_code: 'NEWX', cus_name: 'New', cus_email: 'new@x.ch' });
		expect(r.ok && sql(`SELECT CONCAT_WS('|', cus_code, COALESCE(cus_email, '-')) FROM ${T_} WHERE sample_customer_id = '${r.record_id}'`) === 'NEWX|-', 'insert only / protected on insert');
		// list: no credit / country column, no values, sort on a hidden column ignored
		await open(ben, `index.php?p=form&f=${F}&q=Changed&sort=4&dir=desc`);
		html = await ben.content();
		const heads = await ben.$$eval('#nuBrowseTable thead th, .nuBrowseTable thead th', ths => ths.map(t => t.textContent.trim()).join('|'));
		expect(!/Credit|Country/.test(heads) && !html.includes('98765') && html.includes('Changed'), 'list columns: ' + heads);
		const csv = await ben.evaluate(async F => (await fetch('index.php?p=formexport&f=' + F)).text(), F);
		expect(!/Credit|98765|Country/.test(csv), 'export: ' + csv.slice(0, 200));
		// team Lead: notes editable and country column visible
		carla = await login('sample_carla', UPW);
		await open(carla, `index.php?p=form&f=${F}&r=${id}`);
		expect(await carla.inputValue('#cus_notes') === 'WH-MARK-7731', 'team rule did not open the notes');
		await open(carla, `index.php?p=form&f=${F}&q=Changed`);
		expect(/Country/.test(await carla.$$eval('thead th', ths => ths.map(t => t.textContent).join('|'))), 'country column hidden for team Lead');
		// the globeadmin: everything, and the Data access page
		const ga = s.ga;
		await open(ga, `index.php?p=form&f=${F}&r=${id}`);
		expect(await ga.inputValue('#cus_notes') === 'WH-MARK-7731' && await ga.inputValue('#cus_phone') === '0791234567', 'globeadmin restricted');
		const ben_id = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
		await open(ga, `index.php?p=dataaccess&f=${F}&tab=preview&as=${ben_id}`);
		const modes = await ga.$$eval('#daPreviewTable tr[data-field]', trs => Object.fromEntries(trs.map(t => [t.dataset.field, t.dataset.mode])));
		expect(modes.cus_notes === 'wh' && modes.cus_phone === 'mask' && modes.cus_email === 'prot' && modes.cus_name === 'edit', 'preview: ' + JSON.stringify(modes));
		await open(ga, `index.php?p=dataaccess&f=${F}`);
		await ga.click('#da_o_sample_customer_o6 summary');
		await ga.click('#da_o_sample_customer_o6 .daAdd');
		await ga.selectOption('#da_o_sample_customer_o6 tr.daRule:last-child select.daMode', 'prot');
		await Promise.all([ga.waitForNavigation(), ga.click('#daSaveFields')]);
		expect(sql("SELECT CONCAT(noa_subject_type, ':', noa_mode) FROM zzzzsys_nunext_object_access WHERE noa_object_id = 'sample_customer_o6'") === 'default:prot'
			&& sql("SELECT sob_all_access FROM zzzzsys_object WHERE zzzzsys_object_id = 'sample_customer_o6'") === '1', 'rule saved through the page');
	} finally {
		if (ben) await ben.context().close();
		if (carla) await carla.context().close();
		sql(`DELETE FROM zzzzsys_nunext_object_access WHERE noa_form_id = '${F}'; UPDATE zzzzsys_object SET sob_all_access = '0' WHERE zzzzsys_object_id = 'sample_customer_o6';
			DELETE FROM ${T_} WHERE sample_customer_id = '${id}' OR cus_code IN ('E2EFA', 'NEWX');
			UPDATE zzzzsys_browse SET sbr_json = ${oldJson === '' ? 'NULL' : `'${oldJson}'`} WHERE zzzzsys_browse_id = 'sample_customer_b3'`);
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
		// an installed sample application older than this version's is refreshed first (tests use its current data; put back at the end)
		if (SITE) {
			const st = execSync(`php -r 'chdir(${JSON.stringify(SITE)}); require "core/nuapp.php"; $s = NuSample::status(); echo ($s["installed"] && $s["version"] < $s["current"]) ? "old" : "ok";'`).toString().trim();
			if (st === 'old') execSync(`php -r 'chdir(${JSON.stringify(SITE)}); require "core/nuapp.php"; NuSample::install();'`, { stdio: 'pipe' });
		}
		// known password for every sample user (the database is put back at the end)
		const hash = execSync('php -r ' + JSON.stringify('echo password_hash(' + JSON.stringify(UPW) + ', PASSWORD_BCRYPT);')).toString().trim();
		sql("UPDATE zzzzsys_user SET sus_login_password = '" + hash + "', sus_change_password = '0' WHERE sus_login_name LIKE 'sample\\_%'");
		s.ga = await login(GA[0], GA[1]);
		for (const [name, fn] of Object.entries(T)) {
			if (ONLY.length && !ONLY.includes(name)) continue;
			const t = Date.now();
			s.skip = '';
			try { await fn(s); results.push([name, s.skip ? 'skip' : 'ok', s.skip]); }
			catch (e) { results.push([name, 'FAIL', e.message.split('\n')[0]]); if (process.env.E2E_STACK) console.log(e.stack); }
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
