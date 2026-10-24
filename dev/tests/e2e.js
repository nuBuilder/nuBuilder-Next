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
	await open(p, 'index.php?p=form&f=samplecustomer&q=alpen&sort=1&dir=desc');
	expect(await rows() === 1 && await p.locator('#nuSearchReset').count() === 1, 'search + reset button');
	await Promise.all([p.waitForNavigation(), p.click('#nuSearchReset')]); await p.waitForLoadState('networkidle');
	let u = new URL(p.url());
	expect(!u.searchParams.get('q') && u.searchParams.get('sort') === '1' && u.searchParams.get('dir') === 'desc' && await count() === total, 'reset button: ' + p.url() + ' ' + await count());
	// the field's own × (event "search" with an empty field) and Esc reload without the search words
	await open(p, 'index.php?p=form&f=samplecustomer&q=alpen');
	await Promise.all([p.waitForNavigation(), p.evaluate(() => { const i = document.getElementById('nuSearchField'); i.value = ''; i.dispatchEvent(new Event('search', { bubbles: true })); })]);
	await p.waitForLoadState('networkidle');
	expect(!new URL(p.url()).searchParams.get('q') && await count() === total, 'native ×: ' + p.url());
	await open(p, 'index.php?p=form&f=samplecustomer&q=alpen');
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
	await open(p, 'index.php?p=form&f=samplecustomer&q=alpen');
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
	let r = await look('ag -alpenblick');
	expect(r.names.includes('Rheinwerk AG') && !r.names.some(n => /Alpenblick/.test(n)) && r.marks.every(m => /^ag$/i.test(m)) && r.marks.length >= 2, 'exclusion: ' + JSON.stringify(r));
	r = await look('"Mont Blanc"');
	expect(r.names.length === 1 && r.names[0] === 'Mont Blanc SA' && JSON.stringify(r.marks) === '["Mont Blanc"]', 'phrase: ' + JSON.stringify(r));
	r = await look('zurcher');
	expect(r.names.length === 1 && /^Z.rcher Druck$/.test(r.names[0]) && r.marks.length === 1 && /^Z.rcher$/.test(r.marks[0]), 'accent-insensitive: ' + JSON.stringify(r));
	r = await look('gmbh de');
	expect(r.names.length >= 2 && r.marks.filter(m => m === 'GmbH').length === r.names.length && r.marks.filter(m => m === 'DE').length === r.names.length, 'two words: ' + JSON.stringify(r));
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
	expect(!k.includes('m') && !k.includes('f') && !k.includes('o') && k.includes('s') && k.includes('y'), 'edit menu: ' + await items());
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
		await pa.click('#nuHomeViewPanel [data-f="undomine"]'); await pa.waitForTimeout(600);
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
	await open(p, 'index.php?p=help');
	const php = await p.locator('#ref-php .nuFn').count(), js = await p.locator('#ref-js .nuFn').count();
	expect(php > 150 && js > 300, 'reference entries missing: ' + php + ' / ' + js);
	expect(await p.locator('#helpToc a[href="#ref-php-h"]').count() === 1 && await p.locator('#helpToc a[href="#ref-js-h"]').count() === 1, 'tabs not in the contents');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-help-fntab'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=help#ref');
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
	await p.goto(p.url().split('#')[0] + '#fn-js-nuGetValue'); await p.waitForTimeout(400);
	expect(await p.locator('#fn-js-nuGetValue').isVisible(), 'link to a JS function does not open the JS tab');
	await open(p, 'index.php?p=help&doc=user');
	expect(await p.locator('#ref-php, #ref-js').count() === 0, 'reference in the user view');
};

T.help_dev = async (s) => {                               // 0.8.408: chapter "Development with Claude" only in developer mode
	const p = s.ga;
	await open(p, 'index.php?p=help');
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
		expect(new URL(p.url()).hash === '#include' && /Include/.test(await p.textContent('.alert-success, .toast, body')), 'saved, back on the tab: ' + p.url());
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
		const fid = sql("SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form f JOIN zzzzsys_user us ON us.sus_zzzzsys_access_id = f.slf_zzzzsys_access_id WHERE us.zzzzsys_user_id = '" + anna + "' AND slf_zzzzsys_form_id NOT LIKE 'nu%' LIMIT 1");
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
