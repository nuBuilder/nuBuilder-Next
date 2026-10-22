/*
 * nuBuilder Next – accessibility scan (0.8.368): axe-core (WCAG 2.1 A / AA) over every page, in light and dark mode, plus phone width
 * (390 px: no page wider than the screen, buttons reachable).
 *     NODE_PATH=$(npm root -g) BASE=http://127.0.0.1:8099/nunext/ node dev/tests/a11y.js
 * Env: AXE (path of axe.min.js, default ../node_modules/axe-core/axe.min.js – npm install --no-save --prefix .. axe-core@4.10.2),
 * ADMIN_USER / ADMIN_PW, ONLY (routes), LEVEL (impacts that fail: default "critical,serious"), SHOW=1 (print every node).
 * Exit code 1 when a failing impact is found.
 */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8099/nunext/';
const AXE = process.env.AXE || path.join(__dirname, '..', '..', '..', 'node_modules', 'axe-core', 'axe.min.js');
const GA = [process.env.ADMIN_USER || 'globeadmin', process.env.ADMIN_PW || 'nu'];
const FAIL = (process.env.LEVEL || 'critical,serious').split(',');
const ROUTES = ['login', 'home', 'users', 'user&id=new', 'access', 'accesslevel', 'permissions', 'forms', 'builder&new=1', 'items', 'files', 'formats',
	'translations', 'procedures', 'reports', 'sqlbuilder', 'prompts', 'import', 'email', 'emailtemplates', 'log', 'sessions', 'vendor', 'settings',
	'branding', 'dashboards', 'configfile', 'help', 'tutorial', 'compat', 'update', 'sample', 'securitycheck', 'audit', 'notifications',
	'notifications&tab=manage', 'apiclients', 'adminroles', 'form&f=samplecustomer', 'form&f=samplecustomer&r=-1', 'form&f=sampleorder&r=-1', 'form&f=sampleall&r=-1'];
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const src = fs.readFileSync(AXE, 'utf8');

async function scan(p) {
	await p.addScriptTag({ content: src });
	return p.evaluate(async () => {
		const r = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] }, resultTypes: ['violations'] });
		return r.violations.map(v => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map(n => n.target.join(' ')).slice(0, 6), count: v.nodes.length }));
	});
}

(async () => {
	const browser = await chromium.launch();
	const out = {};
	let fail = 0;
	for (const [mode, opt] of [['light', {}], ['dark', { colorScheme: 'dark' }], ['phone', { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }]]) {
		const ctx = await browser.newContext(Object.assign({ locale: 'en-GB', viewport: { width: 1300, height: 900 }, bypassCSP: true }, opt));   // axe is injected as a script
		// the theme as a user chooses it (assets/js/nutheme.js reads it before the page is drawn – CodeMirror, charts … follow it)
		await ctx.addInitScript(m => { try { localStorage.setItem('nunext-theme', m === 'dark' ? 'dark' : 'light'); } catch (e) { /* */ } }, mode);
		const p = await ctx.newPage();
		await p.goto(BASE + 'index.php?p=login');
		for (const v of await scan(p)) { const k = v.id + '|' + v.impact; out[k] ??= { v, pages: new Set(), modes: new Set() }; out[k].pages.add('login'); out[k].modes.add(mode); }
		await p.fill('#username', GA[0]); await p.fill('#password', GA[1]);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
		for (const r of ROUTES.slice(1)) {
			if (ONLY.length && !ONLY.includes(r)) continue;
			await p.goto(BASE + 'index.php?p=' + r); await p.waitForLoadState('networkidle'); await p.waitForTimeout(300);
			if (mode === 'phone') {                                   // nothing wider than the screen
				const w = await p.evaluate(() => document.documentElement.scrollWidth);
				if (w > 392) (out['page-wider-than-phone|serious'] ??= { v: { id: 'page-wider-than-phone', impact: 'serious', help: 'The page scrolls sideways at 390 px', nodes: [] }, pages: new Set(), modes: new Set() }).pages.add(r + ' (' + w + ' px)'), out['page-wider-than-phone|serious'].modes.add(mode);
			}
			for (const v of await scan(p)) {
				const k = v.id + '|' + v.impact;
				out[k] ??= { v, pages: new Set(), modes: new Set() };
				out[k].pages.add(r); out[k].modes.add(mode);
				if (process.env.SHOW === '1') console.log(mode, r, v.id, v.nodes.join(' · '));
			}
		}
		await ctx.close();
	}
	await browser.close();
	const list = Object.values(out).sort((a, b) => ['critical', 'serious', 'moderate', 'minor'].indexOf(a.v.impact) - ['critical', 'serious', 'moderate', 'minor'].indexOf(b.v.impact));
	for (const { v, pages, modes } of list) {
		const bad = FAIL.includes(v.impact);
		if (bad) fail++;
		console.log((bad ? 'FAIL ' : 'note ') + (v.impact || '').padEnd(9) + v.id.padEnd(28) + [...modes].join('/').padEnd(17) + [...pages].slice(0, 8).join(', ') + (pages.size > 8 ? ' … (' + pages.size + ')' : '')
			+ '\n      ' + v.help + (v.nodes.length ? ' – e.g. ' + v.nodes.slice(0, 3).join(' | ') : ''));
	}
	console.log(fail ? fail + ' kind(s) of ' + FAIL.join('/') + ' findings' : 'no ' + FAIL.join('/') + ' findings');
	process.exit(fail ? 1 : 0);
})();
