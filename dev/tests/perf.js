/*
 * nuBuilder Next – how much does each page ask the database? (0.8.1341)
 *
 *   BASE=http://127.0.0.1:8099/nunext/ DB="-unu -pnu nutest" NODE_PATH=$(npm root -g) node dev/tests/perf.js [--write] [--rounds=5]
 *
 * Needs developer mode ($nuConfigDevMode = true): every page then ends with <span hidden id="nuPerf" data-statements data-db-ms data-ms>
 * (core/nulayout.php nu_perf_mark – statements through NuDB, their time, the time of the whole request). The script opens the pages
 * below as the globeadmin and as a sample user (the sample application must be installed), takes the second and later answers
 * (the first fills the session's caches) and compares the number of statements with dev/tests/perf-budget.json:
 *   more than budget × 1.15 + 2 → FAIL (exit 1) – a page started to ask the database more; look at what changed.
 *   --write  stores what was measured as the new budget (after a change that is meant to add or save statements).
 * Times are printed for information only (they depend on the computer). Reads only – nothing is changed.
 *
 * Why: 0.8.1340 → 0.8.1341 took the pages below from 1,535 to 884 statements (settings in one read, per-request memo NuMemo,
 * lookups of sub-form rows in one statement …). A statement is a round trip to the database – with the database on another host
 * each costs a fraction of a millisecond to a few milliseconds.
 */
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8099/nunext/';
const DB = process.env.DB || '-unu -pnu nutest';
const GA = [process.env.ADMIN_USER || 'globeadmin', process.env.ADMIN_PW || 'nu'];
const USER = [process.env.TEST_USER || 'sample_anna', process.env.USER_PW || 'Test1234!'];
const WRITE = process.argv.includes('--write');
const ROUNDS = +((process.argv.find(a => a.startsWith('--rounds=')) || '=5').split('=')[1]);
const FILE = path.join(__dirname, 'perf-budget.json');
const one = q => (execSync('mysql -N ' + DB, { input: q }).toString().trim().split('\n')[0] || '');

const id = {
	user: one("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'"),
	access: one("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE zzzzsys_access_id LIKE 'sample%' ORDER BY 1 LIMIT 1"),
	customer: one('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1'),
	order: one('SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1'),
	all: one('SELECT sample_all_id FROM sample_all ORDER BY 1 LIMIT 1'),
};
const P = 'index.php?p=';
// [key of the budget, address, who]
const PAGES = [
	['home', P + 'home', 'ga'], ['home_user', P + 'home', 'user'], ['users', P + 'users', 'ga'], ['user', P + 'user&id=' + id.user, 'ga'], ['accesslevel', P + 'accesslevel&id=' + id.access, 'ga'],
	['forms', P + 'forms', 'ga'], ['procedures', P + 'procedures', 'ga'], ['reports', P + 'reports', 'ga'], ['log', P + 'log', 'ga'], ['settings', P + 'settings', 'ga'], ['notifications', P + 'notifications', 'ga'],
	['list_customer', P + 'form&f=samplecustomer', 'ga'], ['list_customer_user', P + 'form&f=samplecustomer', 'user'],
	['rec_customer', P + 'form&f=samplecustomer&r=' + id.customer, 'ga'], ['rec_customer_user', P + 'form&f=samplecustomer&r=' + id.customer, 'user'],
	['rec_order', P + 'form&f=sampleorder&r=' + id.order, 'ga'], ['rec_order_user', P + 'form&f=sampleorder&r=' + id.order, 'user'],
	['rec_all', P + 'form&f=sampleall&r=' + id.all, 'ga'], ['design_customer', P + 'form&f=samplecustomer&r=' + id.customer + '&design=1', 'ga'],
];

(async () => {
	if (id.customer === '') { console.log('The sample application is not installed (Settings → Sample data) – nothing to measure.'); process.exit(2); }
	const browser = await chromium.launch();
	const ctx = {};
	for (const [who, [login, pw]] of [['ga', GA], ['user', USER]]) {
		ctx[who] = await browser.newContext({ locale: 'en-GB' });
		const p = await ctx[who].newPage();
		await p.goto(BASE + 'index.php?p=login');
		await p.fill('#username', login);
		await p.fill('#password', pw);
		await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('form button[type=submit]:not([name=demo_user])')]);
		if (/p=login/.test(p.url())) { console.log('Sign-in of ' + login + ' failed.'); process.exit(2); }
		await p.close();
	}
	const budget = fs.existsSync(FILE) ? JSON.parse(fs.readFileSync(FILE, 'utf8')) : {};
	const now = {};
	let fails = 0, total = 0, totalBudget = 0;
	const median = a => a.slice().sort((x, y) => x - y)[Math.floor(a.length / 2)];
	console.log('page                  statements  budget   database ms   request ms');
	for (const [key, url, who] of PAGES) {
		const st = [], dbms = [], ms = [];
		for (let i = 0; i <= ROUNDS; i++) {
			const r = await ctx[who].request.get(BASE + url);
			const m = /id="nuPerf" data-statements="(\d+)" data-db-ms="([\d.]+)" data-ms="([\d.]+)"/.exec(await r.text());
			if (!m) { console.log(key + ': no #nuPerf in the page (status ' + r.status() + ') – is developer mode on ($nuConfigDevMode = true)?'); process.exit(2); }
			if (i === 0) continue;                                 // the first answer fills what the session keeps
			st.push(+m[1]); dbms.push(+m[2]); ms.push(+m[3]);
		}
		const n = median(st), b = budget[key];
		now[key] = n;
		total += n; totalBudget += b || 0;
		const over = b !== undefined && n > b * 1.15 + 2;
		if (over) fails++;
		console.log(key.padEnd(22) + String(n).padStart(8) + String(b === undefined ? '–' : b).padStart(9) + median(dbms).toFixed(1).padStart(13) + median(ms).toFixed(1).padStart(13) + (over ? '   FAIL – more than the budget allows' : ''));
	}
	console.log('total'.padEnd(22) + String(total).padStart(8) + String(totalBudget || '–').padStart(9));
	await browser.close();
	if (WRITE) { fs.writeFileSync(FILE, JSON.stringify(now, null, '\t') + '\n'); console.log('budget written: ' + FILE); process.exit(0); }
	console.log(fails ? fails + ' page(s) over the budget' : 'all pages within the budget');
	process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
