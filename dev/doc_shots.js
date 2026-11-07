/*
 * Screenshots of the help page "Your first application" (docs/pages/04-09-first-app.htm) from a test installation with the
 * sample application, in English and light mode.
 *
 *   BASE=http://127.0.0.1:8099/nunext/ DB="-unu -pnu nutest" NODE_PATH=$(npm root -g) node dev/doc_shots.js
 *
 * ONLY=fa-list,fa-design takes single shots. Writes assets/img/help/<name>.webp (PNG from Chromium, converted with cwebp or
 * Python PIL). Changes nothing in the database except the sample users' passwords (set to PW, as the e2e tests do).
 */
'use strict';
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8099/nunext/';
const DB = process.env.DB || '-unu -pnu nutest';
const PW = process.env.PW || 'Test1234!';
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const OUT = path.join(__dirname, '..', 'assets', 'img', 'help');
const VIEW = { width: 1280, height: 760 };
const sql = q => execSync('mysql -N --default-character-set=utf8mb4 ' + DB, { input: q }).toString().trim();

let browser;
async function login(user, pw) {
	const c = await browser.newContext({ locale: 'en-GB', viewport: VIEW, deviceScaleFactor: 1 });
	await c.addInitScript(() => { try { localStorage.setItem('nunext-theme', 'light'); } catch (e) { /* ignore */ } });
	const p = await c.newPage();
	p.on('dialog', d => d.accept());
	await p.goto(BASE + 'index.php?p=login');
	await p.fill('#username', user);
	await p.fill('#password', pw);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
	for (let i = 0; i < 3 && await p.locator('.nuBellConfirm.show .btn-primary').count(); i++) { await p.click('.nuBellConfirm.show .btn-primary'); await p.waitForTimeout(500); }
	await p.goto(BASE + 'index.php?p=lang&l=en-GB&r=' + encodeURIComponent('p=home'));
	return p;
}
// banners of a test site that a fresh installation does not show
async function tidy(p) {
	await p.evaluate(() => document.querySelectorAll('.nuTutBanner, .alert:has(input[value=hide_dbwarn]), #nuEntryNotice, .toast').forEach(e => e.remove()));
}
// design mode: click the object of a field (its overlay), so the panel shows the object's settings
async function pick(p, fieldId) {
	const box = await p.evaluate(id => {
		let el = document.getElementById(id);
		while (el && !(el.querySelector && el.querySelector(':scope > .nuDesignOverlay'))) el = el.parentElement;
		const ov = el ? el.querySelector(':scope > .nuDesignOverlay') : null;
		if (!ov) return null;
		const r = ov.getBoundingClientRect();
		return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
	}, fieldId);
	if (box) { await p.mouse.click(box.x, box.y); await p.waitForTimeout(900); }
}
async function open(p, url) { await p.goto(BASE + url); await p.waitForLoadState('networkidle'); await p.waitForTimeout(900); await tidy(p); }
function webp(png) {
	const out = png.replace(/\.png$/, '.webp');
	try { execSync('cwebp -quiet -q 80 ' + JSON.stringify(png) + ' -o ' + JSON.stringify(out), { stdio: 'ignore' }); }
	catch (e) { execSync('python3 -I -c "import sys; from PIL import Image; Image.open(sys.argv[1]).save(sys.argv[2], \'WEBP\', quality=80, method=6)" ' + JSON.stringify(png) + ' ' + JSON.stringify(out)); }
	fs.unlinkSync(png);
}
async function shot(p, name, opt = {}) {
	if (ONLY.length && !ONLY.includes(name)) return;
	await p.mouse.move(2, 2);
	await p.waitForTimeout(500);
	const png = path.join(OUT, name + '.png');
	await p.screenshot(Object.assign({ path: png }, opt));
	webp(png);
	console.log('  ' + name);
}

(async () => {
	fs.mkdirSync(OUT, { recursive: true });
	const hash = execSync(`php -r 'echo password_hash("${PW}", PASSWORD_BCRYPT);'`).toString();
	sql(`UPDATE zzzzsys_user SET sus_login_password = '${hash}' WHERE sus_login_name LIKE 'sample_%'`);
	sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
	const ord = sql("SELECT sample_order_id FROM sample_order ORDER BY ord_number LIMIT 1");
	const cus = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_name LIMIT 1");
	const rpt = sql("SELECT zzzzsys_report_id FROM zzzzsys_report WHERE sre_code = 'sample_orders'");
	const look = sql("SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleorder' AND sob_all_type = 'lookup' ORDER BY sob_all_order LIMIT 1");
	const lvl = sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE sal_code = 'sample_sales'");
	browser = await chromium.launch();
	try {
		const p = await login('globeadmin', process.env.GAPW || 'nu');
		// 1. the New form wizard, first step
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		await shot(p, 'fa-wizard');
		// 2. a list (search, filters, columns)
		await open(p, 'index.php?p=form&f=samplecustomer');
		await shot(p, 'fa-list');
		// 3. a record in design mode
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cus + '&design=1');
		await p.waitForTimeout(800);
		await pick(p, 'cus_name');
		await shot(p, 'fa-design');
		// 4. the lookup of the order form selected in design mode
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord + '&design=1');
		await p.waitForTimeout(800);
		await pick(p, look + 'code');
		await shot(p, 'fa-lookup');
		// 5. the order with its items (sub-form)
		await open(p, 'index.php?p=form&f=sampleorder&r=' + ord);
		await shot(p, 'fa-subform');
		// 6. the report designer
		await open(p, 'index.php?p=reportdesigner&id=' + encodeURIComponent(rpt));
		await p.waitForTimeout(800);
		await shot(p, 'fa-report');
		// 7. the form rights of an access level
		await open(p, 'index.php?p=accesslevel&id=' + encodeURIComponent(lvl) + '&tab=rights');
		await shot(p, 'fa-access');
		// 8. what a user of that level sees
		const u = await login('sample_dario', PW);   // a user of that access level
		await open(u, 'index.php?p=home');
		await shot(u, 'fa-user');
	} finally {
		await browser.close();
	}
})().catch(e => { console.error(e); process.exit(1); });
