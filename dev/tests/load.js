/*
 * nuBuilder Next – behaviour with large data (0.8.366): fills the test database with realistic volumes, measures pages and the bell
 * as the globeadmin and as a normal user, and lists slow queries / queries without an index from MariaDB's slow query log.
 *     NODE_PATH=$(npm root -g) BASE=http://127.0.0.1:8099/nunext/ DB="-unu -pnu nutest" node dev/tests/load.js
 * Env: SCALE (1 = 500 users, 100,000 customers and orders, 100,000 audit rows, 1,000 notifications, 200,000 log rows), RUNS (requests
 * per page, default 5 – the median counts), LIMIT_MS (page budget, default 1500), POLL_MS (budget of one bell check, default 150),
 * ROOT_DB (mysql arguments of an account that may SET GLOBAL – for the slow query log; default "-uroot"), SITE (folder of the test
 * installation: housekeeping is timed too), KEEP=1 (keep the data).
 * The sample application must be installed (Admin tools → Sample data). The database is saved first and put back at the end.
 * Exit code 1 when a page is over its budget. Never run it against a database with real data.
 */
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8099/nunext/';
const DB = process.env.DB || '-unu -pnu nutest';
const ROOT = process.env.ROOT_DB || '-uroot';
const SCALE = Number(process.env.SCALE || 1);
const RUNS = Number(process.env.RUNS || 5);
const LIMIT = Number(process.env.LIMIT_MS || 1500);
const POLL = Number(process.env.POLL_MS || 150);
const GA = [process.env.ADMIN_USER || 'globeadmin', process.env.ADMIN_PW || 'nu'];
const UPW = 'Load-Test-1!';
const sql = q => execSync('mysql -N --raw --default-character-set=utf8mb4 ' + DB, { input: q, maxBuffer: 256 << 20 }).toString().replace(/\n$/, '');
const n = x => Math.round(x * SCALE);
const pad = (p, i) => p + String(i).padStart(9, '0');               // ids like nuBuilder's: 25 characters at most
const q = s => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const pick = (a, i) => a[i % a.length];
const dt = (daysBack, i) => new Date(Date.now() - daysBack * 86400000 * ((i * 7919) % 1000) / 1000).toISOString().slice(0, 19).replace('T', ' ');

/** Bulk insert in chunks of 2,000 rows through one mysql call per table. */
function bulk(table, cols, count, row) {
	const file = path.join(os.tmpdir(), 'nunext-load-' + table + '.sql');
	const out = fs.openSync(file, 'w');
	for (let i = 0; i < count; i += 2000) {
		const vals = [];
		for (let j = i; j < Math.min(count, i + 2000); j++) vals.push('(' + row(j).map(v => v === null ? 'NULL' : q(v)).join(',') + ')');
		fs.writeSync(out, 'INSERT INTO `' + table + '` (' + cols.map(c => '`' + c + '`').join(',') + ') VALUES\n' + vals.join(',\n') + ';\n');
	}
	fs.closeSync(out);
	execSync('mysql --default-character-set=utf8mb4 ' + DB + ' < ' + file, { maxBuffer: 64 << 20 });
	fs.unlinkSync(file);
}

function fill() {
	const t0 = Date.now();
	const levels = sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE sal_code LIKE 'sample\\_%'").split('\n').filter(Boolean);
	const products = sql('SELECT sample_product_id, pro_price FROM sample_product').split('\n').filter(Boolean).map(l => l.split('\t'));
	if (!levels.length || !products.length) throw new Error('install the sample application first (Admin tools → Sample data)');
	const hash = execSync('php -r ' + JSON.stringify('echo password_hash(' + JSON.stringify(UPW) + ', PASSWORD_BCRYPT);')).toString().trim();
	const deps = ['Sales', 'Support', 'Finance', 'IT', 'Logistics', 'HR', 'Marketing', 'Production'], teams = ['North', 'South', 'East', 'West'];
	const users = n(500);
	bulk('zzzzsys_user', ['zzzzsys_user_id', 'sus_zzzzsys_access_id', 'sus_name', 'sus_first_name', 'sus_last_name', 'sus_code', 'sus_position', 'sus_department', 'sus_team',
		'sus_email', 'sus_login_name', 'sus_login_password', 'sus_language', 'sus_change_password'], users,
		i => [pad('lu', i), pick(levels, i), 'Load User ' + i, 'Load', 'User ' + i, 'L' + i, pick(['Clerk', 'Lead', 'Manager'], i), pick(deps, i), pick(teams, i),
			'load' + i + '@example.com', 'load_' + i, hash, '', '0']);
	const cust = n(100000), orders = n(100000);
	const countries = ['CH', 'DE', 'AT', 'FR', 'IT', 'US', 'GB', 'NL'];
	bulk('sample_customer', ['sample_customer_id', 'cus_code', 'cus_name', 'cus_email', 'cus_phone', 'cus_country', 'cus_active', 'cus_since', 'cus_credit', 'cus_notes'], cust,
		i => [pad('lc', i), 'L' + String(i).padStart(7, '0'), 'Load Customer ' + i + ' ' + pick(['AG', 'GmbH', 'Ltd', 'SA'], i), 'c' + i + '@example.com', '+41 44 ' + (100000 + i),
			pick(countries, i), String(i % 5 ? 1 : 0), dt(3000, i).slice(0, 10), String((i * 37) % 50000), i % 10 ? null : 'Note for customer ' + i]);
	const item = j => { const p = pick(products, j * 13); return [p[0], 1 + j % 9, Number(p[1])]; };   // three items per order
	bulk('sample_order', ['sample_order_id', 'ord_number', 'ord_sample_customer_id', 'ord_date', 'ord_status', 'ord_total', 'created_at', 'created_by'], orders,
		i => [pad('lo', i), 'L-' + String(i).padStart(7, '0'), pad('lc', (i * 7) % cust), dt(1500, i).slice(0, 10), pick(['open', 'paid', 'shipped', 'cancelled'], i),
			[0, 1, 2].reduce((t, k) => { const it = item(i * 3 + k); return t + it[1] * it[2]; }, 0).toFixed(2), dt(1500, i), 'load']);
	bulk('sample_order_item', ['sample_order_item_id', 'ori_sample_order_id', 'ori_sample_product_id', 'ori_qty', 'ori_price'], orders * 3,
		i => { const it = item(i); return [pad('li', i), pad('lo', Math.floor(i / 3)), it[0], String(it[1]), String(it[2])]; });
	// audit trail: one change = several rows (columns), customers and orders, all users
	const audit = n(100000), cols = ['cus_name', 'cus_email', 'cus_phone', 'cus_credit'];
	bulk('zzzzsys_nunext_audit', ['zzzzsys_nunext_audit_id', 'change_id', 'changed_at', 'user_id', 'login', 'ip', 'action', 'source', 'table_name', 'record_id', 'form_id', 'form_code', 'column_name', 'old_value', 'new_value'], audit,
		i => { const c = Math.floor(i / 4); return [pad('la', i), pad('lx', c), dt(400, c), pad('lu', c % users), 'load_' + (c % users), '10.0.0.' + (c % 250), pick(['update', 'update', 'insert', 'delete'], c),
			'form', 'sample_customer', pad('lc', (c * 11) % cust), 'samplecustomer', 'sample_customer', pick(cols, i), 'old ' + i, 'new ' + i]; });
	// notifications: live (all / departments / access levels) and fixed (recipient rows), read states
	const ntf = n(1000), aud = i => JSON.stringify(i % 3 === 0 ? { all: true } : i % 3 === 1 ? { departments: [pick(deps, i)] } : { access: [pick(levels, i)] });
	bulk('zzzzsys_nunext_notification', ['zzzzsys_nunext_notification_id', 'title', 'body', 'level', 'link', 'audience', 'mode', 'publish_at', 'pinned', 'must_confirm', 'email', 'status', 'created_at', 'created_by', 'created_by_id'], ntf,
		i => [pad('ln', i), 'Load notification ' + i, 'Text of notification ' + i + '\nwith a second line', pick(['info', 'success', 'warning', 'danger'], i), '', aud(i),
			i % 4 === 0 ? 'fixed' : 'live', dt(200, i), i % 97 === 0 ? '1' : '0', '0', '0', 'published', dt(200, i), 'globeadmin', 'globeadmin']);
	const fixed = []; for (let i = 0; i < ntf; i += 4) fixed.push(i);
	const per = Math.max(1, Math.floor(users / 4));                       // a quarter of the users per fixed notification
	bulk('zzzzsys_nunext_notification_user', ['zzzzsys_nunext_notification_user_id', 'notification_id', 'user_id', 'recipient', 'read_at'], fixed.length * per,
		i => { const f = fixed[Math.floor(i / per)], u = (i % per) * 4 + (f % 4); return [pad('lr', i), pad('ln', f), pad('lu', u % users), '1', i % 2 ? dt(100, i) : null]; });
	// log: logins, saves, …
	bulk('zzzzsys_nunext_log', ['zzzzsys_nunext_log_id', 'log_time', 'user_id', 'login', 'ip', 'event', 'object', 'record_id', 'details'], n(200000),
		i => [pad('ll', i), dt(365, i), pad('lu', i % users), 'load_' + (i % users), '10.0.' + (i % 200) + '.' + (i % 250), pick(['login', 'logout', 'record_saved', 'login', 'report_run', 'login_failed'], i),
			'sample_customer', pad('lc', i % cust), i % 6 === 5 ? null : '{"via":"load test"}']);
	sql('ANALYZE TABLE zzzzsys_user, sample_customer, sample_order, sample_order_item, zzzzsys_nunext_audit, zzzzsys_nunext_notification, zzzzsys_nunext_notification_user, zzzzsys_nunext_log');
	console.log('data: ' + users + ' users, ' + cust + ' customers, ' + orders + ' orders (' + orders * 3 + ' items), ' + audit + ' audit rows, ' + ntf
		+ ' notifications (' + fixed.length * per + ' recipient rows), ' + n(200000) + ' log rows – ' + Math.round((Date.now() - t0) / 1000) + ' s');
}

async function login(browser, user, pw) {
	const ctx = await browser.newContext({ locale: 'en-GB' }); const p = await ctx.newPage();
	await p.goto(BASE + 'index.php?p=login'); await p.fill('#username', user); await p.fill('#password', pw);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
	for (let i = 0; i < 3 && await p.locator('.nuBellConfirm.show .btn-primary').count(); i++) { await p.click('.nuBellConfirm.show .btn-primary'); await p.waitForTimeout(300); }
	return p;
}
/** Median server time of a GET (whole response, as the browser waits for it). */
async function time(p, url, runs = RUNS) {
	const ms = [];
	let status = 0, bytes = 0;
	for (let i = 0; i < runs; i++) {
		const t = Date.now(); const r = await p.request.get(BASE + url, { maxRedirects: 0, timeout: 600000 }); status = r.status(); bytes = (await r.body()).length; ms.push(Date.now() - t);
	}
	ms.sort((a, b) => a - b);
	return { ms: ms[Math.floor(ms.length / 2)], status, bytes };
}

(async () => {
	const dump = path.join(os.tmpdir(), 'nunext-load-' + process.pid + '.sql');
	execSync('mysqldump ' + DB + ' > ' + dump);
	const slowLog = path.join(os.tmpdir(), 'nunext-load-slow.log');
	let slowOn = false;
	const results = [];
	let browser;
	try {
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		fill();
		try {
			execSync('mysql ' + ROOT + " -e \"SET GLOBAL slow_query_log_file = '" + slowLog + "'; SET GLOBAL long_query_time = 0.05; SET GLOBAL log_queries_not_using_indexes = 1; SET GLOBAL min_examined_row_limit = 2000; SET GLOBAL slow_query_log = 1;\"");
			try { fs.truncateSync(slowLog); } catch (e) { /* new file */ }
			slowOn = true;
		} catch (e) { console.log('slow query log: not available (ROOT_DB)'); }
		browser = await chromium.launch();
		const ga = await login(browser, GA[0], GA[1]);
		const user = await login(browser, 'load_7', UPW);
		const cust = sql("SELECT sample_customer_id FROM sample_customer WHERE sample_customer_id LIKE 'lc%' ORDER BY 1 LIMIT 1 OFFSET 777");
		const order = sql("SELECT sample_order_id FROM sample_order WHERE sample_order_id LIKE 'lo%' ORDER BY 1 LIMIT 1 OFFSET 777");
		const audRec = sql("SELECT record_id FROM zzzzsys_nunext_audit GROUP BY record_id ORDER BY COUNT(*) DESC LIMIT 1");
		const pages = [
			[ga, 'home', 'index.php?p=home'],
			[ga, 'users list', 'index.php?p=users'],
			[ga, 'users search', 'index.php?p=users&q=User+42'],
			[ga, 'user record', 'index.php?p=user&id=' + sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'load_42'")],
			[ga, 'access level', 'index.php?p=accesslevel&id=' + sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE sal_code = 'sample_viewer'")],
			[ga, 'audit trail', 'index.php?p=audit'],
			[ga, 'audit history', 'index.php?p=audithistory&f=samplecustomer&r=' + encodeURIComponent(audRec)],
			[ga, 'log', 'index.php?p=log'],
			[ga, 'log search', 'index.php?p=log&q=load_42'],
			[ga, 'notifications manage', 'index.php?p=notifications&tab=manage'],
			[ga, 'security check', 'index.php?p=securitycheck'],
			[ga, 'system status', 'index.php?p=vendor'],
			[ga, 'sessions', 'index.php?p=sessions'],
			[ga, 'customers browse', 'index.php?p=form&f=samplecustomer'],
			[ga, 'customers browse page 400', 'index.php?p=form&f=samplecustomer&pg=400'],
			[ga, 'customers search', 'index.php?p=form&f=samplecustomer&q=Customer+4242'],
			[ga, 'orders browse', 'index.php?p=form&f=sampleorder'],
			[ga, 'customer record', 'index.php?p=form&f=samplecustomer&r=' + cust],
			[ga, 'order record (sub-form)', 'index.php?p=form&f=sampleorder&r=' + order],
			[user, 'home (user)', 'index.php?p=home'],
			[user, 'notifications (user)', 'index.php?p=notifications'],
			[user, 'customers browse (user)', 'index.php?p=form&f=samplecustomer'],
			// pages and exports of later versions (0.8.761)
			[ga, 'users list, all columns', 'index.php?p=users&view=all'],
			[ga, 'users sorted by created', 'index.php?p=users&view=all&sort=created&dir=desc'],
			[ga, 'users sorted by last login', 'index.php?p=users&view=all&sort=last_login&dir=desc'],
			[ga, 'users online filter', 'index.php?p=users&online=1'],
			[ga, 'users list CSV (all)', 'index.php?p=users&export=csv'],
			[ga, 'log CSV (50,000)', 'index.php?p=log&export=csv'],
			[ga, 'log Excel (50,000)', 'index.php?p=log&export=xlsx'],
			[ga, 'audit filter by table', 'index.php?p=audit&table=sample_customer'],
			[ga, 'customers advanced search', 'index.php?p=form&f=samplecustomer&adv=' + encodeURIComponent(JSON.stringify({ m: 'all', c: [[1, 'contains', '424', '']] }))],
			[ga, 'customers browse CSV', 'index.php?p=formexport&f=samplecustomer'],
			[ga, 'customers browse Excel', 'index.php?p=formexport&f=samplecustomer&fmt=xlsx'],
			[ga, 'orders browse sort total', 'index.php?p=form&f=sampleorder&sort=4&dir=desc'],
			[ga, 'search list (Ctrl+K)', 'index.php?p=palette'],
			[ga, 'data export list', 'index.php?p=dataexport'],
			[ga, 'data export xlsx (100k + 100k)', 'index.php?p=dataexport&run=loadexp_xlsx', 1],
			[ga, 'data export csv zip (100k + 100k)', 'index.php?p=dataexport&run=loadexp_csv', 1],
			[ga, 'webhooks list', 'index.php?p=webhooks'],
			[ga, 'translations', 'index.php?p=translations'],
			[ga, 'settings centre', 'index.php?p=settings'],
			[ga, 'compatibility check', 'index.php?p=compat', 1],
			[ga, 'self-test page', 'index.php?p=selftest'],
		];
		// a data export over the two large tables (Excel: two sheets; CSV: a ZIP)
		for (const f of ['xlsx', 'csv']) {
			const ds = JSON.stringify([{ name: 'Customers', source: 'table', table: 'sample_customer', columns: [], where: { m: 'all', c: [] }, sort: [['cus_name', 'asc']], sql: '', select: '', reimport: false },
				{ name: 'Orders', source: 'sql', table: '', columns: [], where: { m: 'all', c: [] }, sort: [], sql: 'SELECT ord_number, ord_date, ord_status, ord_total FROM sample_order ORDER BY ord_date', select: '', reimport: false }]);
			sql('INSERT INTO zzzzsys_nunext_export (zzzzsys_nunext_export_id, code, name, format, options, datasets, created_by, created_at, runs) VALUES ('
				+ q('loadexp_' + f) + ', ' + q('loadexp_' + f) + ", 'Load export', " + q(f) + ", '{}', " + q(ds) + ", 'load', NOW(), 0)");
		}
		for (const [p, name, url, runs] of pages) {
			const r = await time(p, url, runs || RUNS);
			const big = /CSV|Excel|export xlsx|export csv|compatibility/.test(name);          // downloads of the whole table: an own budget
			results.push([name + (r.bytes > 200000 ? ' (' + Math.round(r.bytes / 1024) + ' KB)' : ''), r.ms, r.status, (big ? r.ms <= LIMIT * 20 : r.ms <= LIMIT) && r.status < 400 ? 'ok' : 'SLOW']);
		}
		// the bell: what every open tab does every minute
		for (const [p, name] of [[ga, 'bell check (globeadmin)'], [user, 'bell check (user)']]) {
			const r = await time(p, 'index.php?p=notifyapi', RUNS * 2);
			results.push([name, r.ms, r.status, r.ms <= POLL && r.status < 400 ? 'ok' : 'SLOW']);
		}
		if (process.env.SITE) {                                         // housekeeping of the test installation (its nuconfig.php)
			const t = Date.now(); execSync('php ' + JSON.stringify(path.join(process.env.SITE, 'cron.php')) + ' 2>&1 || true');
			results.push(['housekeeping (cron.php)', Date.now() - t, 0, 'info']);
		}
	} finally {
		if (browser) await browser.close();
		if (slowOn) execSync('mysql ' + ROOT + ' -e "SET GLOBAL slow_query_log = 0; SET GLOBAL log_queries_not_using_indexes = 0; SET GLOBAL long_query_time = 10;"');
		if (process.env.KEEP !== '1') execSync('mysql ' + DB + ' < ' + dump);
		fs.unlinkSync(dump);
	}
	for (const r of results) console.log((r[3] === 'ok' ? 'ok   ' : r[3] === 'info' ? 'info ' : 'SLOW ') + r[0].padEnd(30) + String(r[1]).padStart(7) + ' ms' + (r[2] ? '  ' + r[2] : ''));
	if (slowOn && fs.existsSync(slowLog)) {
		// group the slow log by statement shape: count, worst time, rows examined
		const txt = fs.readFileSync(slowLog, 'utf8'), out = {};
		for (const m of txt.matchAll(/# Query_time: ([\d.]+)\s+Lock_time: [\d.]+\s+Rows_sent: \d+\s+Rows_examined: (\d+)[\s\S]*?\n((?:SELECT|UPDATE|DELETE|INSERT)[\s\S]*?);\n/gi)) {
			const shape = m[3].replace(/'[^']*'/g, '?').replace(/\b\d+\b/g, 'N').replace(/\s+/g, ' ').slice(0, 220);
			const o = out[shape] = out[shape] || { n: 0, t: 0, rows: 0 };
			o.n++; o.t = Math.max(o.t, Number(m[1])); o.rows = Math.max(o.rows, Number(m[2]));
		}
		const list = Object.entries(out).sort((a, b) => b[1].rows - a[1].rows).slice(0, 15);
		if (list.length) console.log('\nslow / no index (max rows examined · max s · count):');
		for (const [k, v] of list) console.log(String(v.rows).padStart(9) + '  ' + v.t.toFixed(3) + ' s  ' + String(v.n).padStart(3) + 'x  ' + k);
		fs.unlinkSync(slowLog);
	}
	const slow = results.filter(r => r[3] === 'SLOW').length;
	console.log(slow ? '\n' + slow + ' page(s) over the budget' : '\nall pages within the budget (' + LIMIT + ' ms, bell ' + POLL + ' ms)');
	process.exit(slow ? 1 : 0);
})();
