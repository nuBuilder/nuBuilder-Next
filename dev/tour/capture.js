/*
 * Takes the screenshots of the tour (dev/tour/index.php) from a test installation with the sample applications.
 *
 *   BASE=http://127.0.0.1:8099/nunext/ DB="-unu -pnu nutest" SITE=<site folder> NODE_PATH=$(npm root -g) node dev/tour/capture.js
 *
 * BASE = the test site (globeadmin / nu, sample users with the password in PW, default Test1234!).
 * SITE = its folder: the installer shot copies it to a sibling folder without nuconfig.php (removed again afterwards).
 * ONLY=01,05 takes single shots. The database is saved first and put back at the end (notifications are added for the bell).
 * Writes dev/tour/img/<nn>-<name>.webp (Chromium screenshots as PNG, converted with cwebp or Python PIL) and dev/tour/tour.json
 * (version + date, only on a full run). Update the tour only when the owner asks.
 */
'use strict';
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8099/nunext/';
const DB = process.env.DB || '-unu -pnu nutest';
const SITE = process.env.SITE || '';
const PW = process.env.PW || 'Test1234!';
const GA = ['globeadmin', process.env.GAPW || 'nu'];
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const OUT = path.join(__dirname, 'img');
const DESK = { width: 1440, height: 900 };
const PHONE = { width: 390, height: 844 };
const sql = q => execSync('mysql -N --default-character-set=utf8mb4 ' + DB, { input: q }).toString().trim();
let browser;

async function ctx(opt = {}) {
	const c = await browser.newContext(Object.assign({ locale: 'en-GB', viewport: DESK, deviceScaleFactor: 1 }, opt));
	if (opt.dark) await c.addInitScript(() => { try { localStorage.setItem('nunext-theme', 'dark'); } catch (e) { /* ignore */ } });
	const p = await c.newPage();
	p.on('dialog', d => d.accept());
	return p;
}
async function login(user, pw, opt = {}) {
	const p = await ctx(opt);
	await p.goto(BASE + 'index.php?p=login');
	await p.fill('#username', user);
	await p.fill('#password', pw);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
	for (let i = 0; i < 3 && await p.locator('.nuBellConfirm.show .btn-primary').count(); i++) { await p.click('.nuBellConfirm.show .btn-primary'); await p.waitForTimeout(500); }
	return p;
}
// banners of the test site that a fresh installation does not show (existing database, tutorial invitation)
async function tidy(p) {
	await p.evaluate(() => document.querySelectorAll('.nuTutBanner, .alert:has(input[value=hide_dbwarn])').forEach(e => e.remove()));
}
async function open(p, url) { await p.goto(BASE + url); await p.waitForLoadState('networkidle'); await p.waitForTimeout(700); await tidy(p); }
async function shot(p, name, opt = {}) {
	await p.mouse.move(2, 2);
	await p.waitForTimeout(500);
	const png = path.join(OUT, name + '.png');
	await p.screenshot(Object.assign({ path: png }, opt));
	webp(png);
	console.log('  ' + name);
}
function webp(png) {
	const out = png.replace(/\.png$/, '.webp');
	try { execSync('cwebp -quiet -q 82 ' + JSON.stringify(png) + ' -o ' + JSON.stringify(out), { stdio: 'ignore' }); }
	catch (e) { execSync('python3 -I -c "import sys; from PIL import Image; Image.open(sys.argv[1]).save(sys.argv[2], \'WEBP\', quality=82, method=6)" ' + JSON.stringify(png) + ' ' + JSON.stringify(out)); }
	fs.unlinkSync(png);
}

const S = {};      // id → async fn (in this order)

S['01-installer'] = async () => {
	if (!SITE) { console.log('  01-installer skipped (SITE not set)'); return; }
	const copy = SITE.replace(/\/+$/, '') + '_tour';
	const url = BASE.replace(/\/[^/]+\/?$/, '/' + path.basename(copy) + '/');
	execSync('rm -rf ' + JSON.stringify(copy) + ' && rsync -a --exclude /nuconfig.php --exclude _backup --exclude /vendor/packs ' + JSON.stringify(SITE + '/') + ' ' + JSON.stringify(copy + '/'));
	try {
		const p = await ctx();
		await p.goto(url + 'index.php'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
		// the test server's PHP build carries a CVE note – show what an up-to-date PHP shows
		await p.evaluate(() => { const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); const hit = []; while (w.nextNode()) if (/CVE-/.test(w.currentNode.nodeValue)) hit.push(w.currentNode); hit.forEach(n => { n.parentNode.textContent = 'ok'; }); });
		await shot(p, '01-installer');
		await p.context().close();
	} finally { execSync('rm -rf ' + JSON.stringify(copy)); }
};

S['02-home'] = async (s) => { await open(s.ga, 'index.php?p=home'); await shot(s.ga, '02-home'); };

S['03-users'] = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=users');
	const btn = p.locator('[data-bs-target="#nuLvFilters"], #nuLvFilterBtn').first();
	if (await btn.count()) { await btn.click(); await p.waitForTimeout(500); }
	await shot(p, '03-users');
};

S['04-user'] = async (s) => { await open(s.ga, 'index.php?p=user&id=sample_carla'); await shot(s.ga, '04-user'); };

S['05-access'] = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer');
	await p.evaluate(() => window.nuFormAccessOpen && window.nuFormAccessOpen('samplecustomer'));
	await p.waitForTimeout(1200);
	await shot(p, '05-access');
};

S['06-browse'] = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer');
	const adv = p.locator('#nuAdvButton');
	if (await adv.count()) {
		await adv.click(); await p.waitForTimeout(600);
		const sel = p.locator('#nuAdvPanel select').first();
		await sel.selectOption({ label: 'Name' }).catch(() => {});
		await p.locator('#nuAdvPanel input[type=text], #nuAdvPanel input:not([type])').first().fill('Ltd').catch(() => {});
		await p.waitForTimeout(300);
	}
	await shot(p, '06-browse');
};

S['07-edit'] = async (s) => {
	const id = sql('SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1');
	await open(s.ga, 'index.php?p=form&f=sampleorder&r=' + encodeURIComponent(id));
	await shot(s.ga, '07-edit');
};

S['08-design'] = async (s) => {
	const p = s.ga;
	const id = sql('SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=sampleorder&r=' + encodeURIComponent(id) + '&design=1');
	await p.waitForTimeout(1200);
	const lbl = p.locator('.nuDesignOverlay').nth(1);
	if (await lbl.count()) { await lbl.click(); await p.waitForTimeout(800); }
	await shot(p, '08-design');
};

S['09-dashboard'] = async () => {
	const p = await login('tkt_lea', PW);
	await open(p, 'index.php?p=home'); await p.waitForTimeout(1800);
	await shot(p, '09-dashboard');
	await p.context().close();
};

S['10-notifications'] = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home');
	const bell = p.locator('#nuBell, .nuBellBtn').first();
	if (await bell.count()) { await bell.click(); await p.waitForTimeout(900); }
	await shot(p, '10-notifications');
};

S['11-audit'] = async (s) => {
	const p = s.ga;
	const save = async () => { await p.click('#nuSaveButton'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(900); };
	const cus = sql("SELECT sample_customer_id FROM sample_customer WHERE cus_code = 'C003'");
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + encodeURIComponent(cus));
	await p.fill('#cus_phone', '+44 20 7946 0123'); await p.locator('#cus_phone').dispatchEvent('change');
	await p.fill('#cus_email', 'orders@harbourlane.example'); await p.locator('#cus_email').dispatchEvent('change');
	await save();
	const ord = sql('SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1 OFFSET 1');
	await open(p, 'index.php?p=form&f=sampleorder&r=' + encodeURIComponent(ord));
	const st = p.locator('#ord_status');
	const opts = await st.locator('option').evaluateAll(o => o.map(x => x.value).filter(Boolean));
	const cur = await st.inputValue();
	await st.selectOption(opts.find(v => v !== cur) || cur);
	await p.fill('#ord_note', 'Customer asked for delivery before Friday.'); await p.locator('#ord_note').dispatchEvent('change');
	await save();
	await open(p, 'index.php?p=audit');
	await shot(p, '11-audit');
};
S['12-settings'] = async (s) => { await open(s.ga, 'index.php?p=settings'); await shot(s.ga, '12-settings'); };
S['13-security'] = async (s) => { await open(s.ga, 'index.php?p=securitycheck'); await shot(s.ga, '13-security'); };

S['14-phone'] = async () => {
	const p = await login(GA[0], GA[1], { viewport: PHONE, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
	await open(p, 'index.php?p=home');
	await shot(p, '14-phone-a');
	await open(p, 'index.php?p=form&f=samplecustomer');
	await shot(p, '14-phone-b');
	await p.context().close();
};

S['15-dark'] = async () => {
	const p = await login(GA[0], GA[1], { dark: true });
	await open(p, 'index.php?p=form&f=samplecustomer');
	await shot(p, '15-dark');
	await p.context().close();
};

(async () => {
	fs.mkdirSync(OUT, { recursive: true });
	const dump = '/tmp/nunext-tour-' + process.pid + '.sql';
	execSync('mysqldump ' + DB + ' > ' + dump);
	browser = await chromium.launch();
	try {
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		const n = (id, t, b, lvl, min) => "('tour_" + id + "', '" + t + "', '" + b + "', '" + lvl + "', '', '{\"all\":true}', 'live', 'published', NOW() - INTERVAL " + min + " MINUTE, NOW() - INTERVAL " + min + " MINUTE, 'globeadmin')";
		sql('INSERT INTO zzzzsys_nunext_notification (zzzzsys_nunext_notification_id, title, body, level, link, audience, mode, status, publish_at, created_at, created_by) VALUES '
			+ [n('1', 'Maintenance on Saturday', 'The application is offline from 7 to 8 am for a database update.', 'warning', 12),
				n('2', 'New report: sales by country', 'Find it under Reports – it can also be sent by e-mail every Monday.', 'info', 95),
				n('3', 'Price list 2027 is online', 'The products form shows the new prices from 1 January.', 'success', 1500)].join(', '));
		const s = { ga: await login(GA[0], GA[1]) };
		for (const [id, fn] of Object.entries(S)) {
			if (ONLY.length && !ONLY.some(o => id.startsWith(o))) continue;
			try { await fn(s); } catch (e) { console.log('  ' + id + ' FAILED: ' + e.message.split('\n')[0]); }
		}
		// version and date of these screenshots for index.php (the tour folder may be copied away from the project)
		const ver = (fs.readFileSync(path.join(__dirname, '../../core/nuapp.php'), 'utf8').match(/define\('NU_VERSION', '([^']+)'\)/) || [])[1] || '';
		if (!ONLY.length) fs.writeFileSync(path.join(__dirname, 'tour.json'), JSON.stringify({ version: ver, date: new Date().toISOString().slice(0, 10) }, null, '\t') + '\n');
	} finally {
		await browser.close();
		execSync('mysql ' + DB + ' < ' + dump);
		fs.unlinkSync(dump);
	}
})();
