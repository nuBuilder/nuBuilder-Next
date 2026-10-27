/*
 * nuBuilder Next – browser smoke test against a running test installation (MySQL / MariaDB or SQL Server):
 *     NODE_PATH=$(npm root -g) BASE=http://127.0.0.1:8099/nunext/ ADMIN_USER=globeadmin ADMIN_PW=nu node dev/tests/smoke.js
 * Opens every page as globeadmin (no 500, no PHP warnings), the security check, a read-only SQL preview, the login rate limit
 * for an unknown name (no cookies), the include guards of PHP files. Exit code 1 when something fails.
 */
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8099/nunext/';
const USER = process.env.ADMIN_USER || 'globeadmin';
const PW = process.env.ADMIN_PW || 'nu';
let fails = 0;
const ok = (name, cond, info = '') => { console.log((cond ? 'ok   ' : 'FAIL ') + name + (info ? '  ' + info : '')); if (!cond) fails++; };
const ROUTES = ['home', 'security', 'log', 'sessions', 'vendor', 'apiclients', 'settings', 'branding', 'setupwizard', 'dashboards', 'configfile', 'globalcode', 'userfields', 'help', 'tutorial', 'compat', 'update', 'sample', 'procedures',
	'reports', 'sqlbuilder', 'prompts', 'import', 'email', 'emailtemplates', 'users', 'access', 'forms', 'formats', 'items', 'permissions', 'files',
	'translations', 'securitycheck', 'audit', 'notifications', 'selftest'];

async function login(ctx, user, pw) {
	const p = await ctx.newPage();
	await p.goto(BASE + 'index.php?p=login');
	await p.fill('#username', user);
	await p.fill('#password', pw);
	await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('button[type=submit]')]);
	return p;
}

(async () => {
	const b = await chromium.launch();
	const ctx = await b.newContext({ locale: 'en-GB' });
	const p = await login(ctx, USER, PW);
	ok('login', /p=home/.test(p.url()), p.url());
	const errors = [];
	p.on('pageerror', e => errors.push(e.message));
	for (const r of ROUTES) {
		const resp = await p.goto(BASE + 'index.php?p=' + r);
		if (/p=reauth/.test(p.url())) {                                       // password confirmation (dangerous pages)
			await p.fill('#reauthPassword', PW);
			await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('button[type=submit]')]);
		}
		const body = (await p.textContent('body')).replace(/\s+/g, ' ');
		ok('page ' + r, resp.status() < 500 && !/(Fatal error|Warning:|Notice:|Deprecated:) /.test(body), String(resp.status()));
	}
	ok('no JavaScript errors', errors.length === 0, errors.slice(0, 3).join(' | '));

	// list print (0.8.267): the Print button opens the print view, not the list again
	await p.goto(BASE + 'index.php?p=users');
	const printHref = await p.getAttribute('#nuListPrint', 'data-print-url');
	ok('print link of lists', /export=print/.test(printHref || ''), String(printHref));
	await p.goto(BASE + printHref);
	ok('print view opens', await p.evaluate(() => document.body.classList.contains('nu-print-list')));

	await p.goto(BASE + 'index.php?p=securitycheck');
	await p.waitForTimeout(1500);
	ok('security check lists checks', (await p.$$('#secChecks li')).length > 5);
	await p.goto(BASE + 'index.php?p=sqlbuilder');
	const preview = sql => p.evaluate(async sql => {
		const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value); fd.append('action', 'preview'); fd.append('sql', sql);
		return (await fetch('index.php?p=sqlbuilder', { method: 'POST', body: fd })).json();
	}, sql);
	ok('SQL preview reads', (await preview('SELECT COUNT(*) AS n FROM zzzzsys_form')).ok === true);
	ok('SQL preview refuses writes', (await preview('WITH x AS (SELECT 1 AS a) DELETE FROM zzzzsys_form WHERE 1 = 0')).ok === false);

	for (const f of ['core/nuauth.php', 'pages/nuupdate.php', 'lang/en.php']) {
		const r = await p.request.get(BASE + f);
		ok('no output from ' + f, r.status() !== 200 || (await r.text()).trim() === '', String(r.status()));
	}

	let last = '';
	const name = 'nosuchuser' + Date.now();
	for (let i = 0; i < 6; i++) {
		const c = await b.newContext({ locale: 'en-GB' });
		const q = await login(c, name, 'wrong' + i);
		last = await q.textContent('body');
		await c.close();
	}
	ok('login rate limit without cookies', /Too many failed attempts/.test(last), /Too many failed attempts/.test(last) ? '' : last.replace(/\s+/g, ' ').slice(0, 300));

	await b.close();
	console.log(fails ? fails + ' FAILED' : 'smoke test passed');
	process.exit(fails ? 1 : 0);
})();
