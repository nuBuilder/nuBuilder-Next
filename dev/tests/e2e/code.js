// e2e tests: code editors, Global code, procedures, configuration – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

T.proc_fields_layout = async (s) => {                 // 0.8.837: procedure details in two rows, fields sized to their content; Start from hint as (i)
	await open(s.ga, 'index.php?p=procedures&id=sample_proc_xlsx');
	await s.ga.evaluate(() => { document.getElementById('procFields').open = true; }); await s.ga.waitForTimeout(200);
	const g = await s.ga.evaluate(() => { const w = (sel) => { const e = document.querySelector('#procTabPhp ' + sel); return e ? Math.round(e.getBoundingClientRect().width) : -1; };
		const top = (sel) => Math.round(document.querySelector('#procTabPhp ' + sel).getBoundingClientRect().top);
		return { code: w('.pf_code'), desc: w('.pf_description'), status: w('.pf_status'), launch: w('.pf_zzzzsys_form_id'), row1: top('.pf_code') === top('.pf_status'), row2: top('.pf_group') === top('.pf_zzzzsys_form_id'),
			tip: !!document.querySelector('#procTabPhp .pf_zzzzsys_form_id .nuInfoTip'), hint: !!document.querySelector('#procTabPhp .procHint') }; });
	expect(g.code <= 360 && g.desc <= 560 && g.status <= 200 && g.launch <= 360 && g.row1 && g.row2 && g.tip && !g.hint, 'procedure fields: ' + JSON.stringify(g));
};

// nuconfig.php editor (0.8.442): Insert variable lists variables the text does not set, inserts at the cursor / the end, own variables;
// nothing is saved (the file stays as it is)
// nuconfig.php editor (0.8.780): fills the window below it, at any window height
T.config_editor_fill = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=configfile');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=configfile'); }
	const m = () => p.evaluate(() => { const r = document.querySelector('.cfgCM').getBoundingClientRect(); return { bottom: r.bottom, h: innerHeight, page: document.documentElement.scrollHeight }; });
	try {
		for (const h of [800, 1100]) {
			await p.setViewportSize({ width: 1300, height: h }); await p.waitForTimeout(500);
			const r = await m();
			expect(r.bottom > h - 60 && r.page <= h + 2, 'height ' + h + ': ' + JSON.stringify(r));
		}
	} finally { await p.setViewportSize({ width: 1300, height: 900 }); }
};

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

// 0.8.523: Custom code → Global JS & CSS – files on every page of the chosen scopes, page hook nuOnPageLoad / nu:pageload with the page
// name (form pages: after nuOnLoad), never on the editor and the Update page, ?safe=1, sign-in pages only when chosen, hook name warning
T.globalcode_tabs_group = async (s) => {             // 0.8.857: switch Form pages | All pages, short tab names; a tab from the address moves the switch
	const p = s.ga;
	await open(p, 'index.php?p=globalcode');
	const shown = () => p.$$eval('#scodeTabs li:not([hidden]) .nav-link', (a) => a.map((x) => x.getAttribute('data-nu-tab') + ':' + x.textContent.trim()));
	expect((await shown()).join(',') === 'header:JavaScript,style:CSS,include:Includes', 'form tabs: ' + (await shown()).join(','));
	expect(await p.locator('#scodeSeg [data-scode-group="forms"].active').count() === 1, 'switch not on Form pages');
	await p.click('#scodeTabBtn-style'); await p.waitForTimeout(150);
	await p.click('#scodeSeg [data-scode-group="all"]'); await p.waitForTimeout(300);
	expect((await shown()).join(',') === 'gjs:JavaScript,gcss:CSS' && await p.locator('#scodePane-gjs.active').count() === 1, 'all pages: ' + (await shown()).join(','));
	// 0.8.1147: hovering a "Runs on" switch explains it
	await p.hover('label[for="scodeScope-gjs-app"]'); await p.waitForTimeout(600);
	const tip = await p.evaluate(() => (document.querySelector('.tooltip.show') || {}).textContent || '');
	expect(/start page/i.test(tip), 'Other app pages tooltip: ' + tip);
	await p.click('#scodeSeg [data-scode-group="forms"]'); await p.waitForTimeout(300);
	expect(await p.locator('#scodePane-style.active').count() === 1, 'last tab of the group not reopened');
	await open(p, 'index.php?p=globalcode#gcss'); await p.waitForTimeout(400);
	expect(await p.locator('#scodeSeg [data-scode-group="all"].active').count() === 1 && await p.locator('#scodePane-gcss.active').count() === 1, '#gcss: switch not moved');
	// phone: switch full width, tabs on one line
	await p.setViewportSize({ width: 390, height: 800 });
	try {
		await open(p, 'index.php?p=globalcode'); await p.waitForTimeout(300);
		const r = await p.evaluate(() => { const t = [...document.querySelectorAll('#scodeTabs li:not([hidden])')].map((l) => Math.round(l.getBoundingClientRect().top)); return { tops: new Set(t).size, w: document.documentElement.scrollWidth }; });
		expect(r.tops === 1 && r.w <= 390, 'phone: ' + JSON.stringify(r));
	} finally { await p.setViewportSize({ width: 1300, height: 900 }); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.globalcode_warn_hide = async (s) => {                // 0.8.856: the warning of Global code can be hidden (localStorage), the (i) next to the title shows it again
	const p = s.ga;
	await open(p, 'index.php?p=globalcode'); await p.evaluate(() => { try { localStorage.removeItem('nunext-scode-warn-hidden'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=globalcode');
	expect(await p.isVisible('#scodeWarn') && !(await p.isVisible('#scodeWarnShow')), 'warning not shown at first');
	await p.click('#scodeWarnHide');
	expect(!(await p.isVisible('#scodeWarn')) && await p.isVisible('#scodeWarnShow'), 'not hidden');
	await open(p, 'index.php?p=globalcode');
	expect(!(await p.isVisible('#scodeWarn')) && await p.isVisible('#scodeWarnShow'), 'choice not kept');
	// 0.8.1120: a hidden warning is not drawn at all – before, it showed until the page's script ran (that script held back here)
	for (const [route, js, sel] of [['globalcode', 'nuglobalcode', '#scodeWarn'], ['configfile', 'nuconfigfile', '#cfgWarn']]) {
		await p.evaluate((k) => { try { localStorage.setItem(k, '1'); } catch (e) { /* */ } }, route === 'globalcode' ? 'nunext-scode-warn-hidden' : 'nunext-cfg-warn-hidden');
		const hold = (r) => r.abort().catch(() => {});
		await p.route(new RegExp(js + '(\\.min)?\\.js'), hold);
		await p.goto(BASE + 'index.php?p=' + route, { waitUntil: 'domcontentloaded' });
		if (/reauth/.test(p.url())) { await p.unroute(new RegExp(js + '(\\.min)?\\.js'), hold); continue; }
		expect(await p.locator(sel).count() === 1 && !(await p.isVisible(sel)), sel + ' flashes before the script hides it');
		await p.unroute(new RegExp(js + '(\\.min)?\\.js'), hold);
		if (route === 'configfile') await p.evaluate(() => { try { localStorage.removeItem('nunext-cfg-warn-hidden'); } catch (e) { /* */ } });
	}
	p.errors.length = 0;
	await open(p, 'index.php?p=globalcode');
	expect(!(await p.isVisible('#scodeWarn')) && await p.isVisible('#scodeWarnShow'), 'still hidden with the script');
	await p.click('#scodeWarnShow');
	expect(await p.isVisible('#scodeWarn') && !(await p.isVisible('#scodeWarnShow')), '(i) does not show it');
	await open(p, 'index.php?p=globalcode');
	expect(await p.isVisible('#scodeWarn'), 'shown again after the (i), not kept');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.custom_code = async (s) => {
	const p = s.ga;
	const del = () => sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('custom_js', 'custom_css', 'custom_scope')");
	const setCm = (k, v) => p.evaluate(([k, v]) => { const ta = document.getElementById('scode-' + k); ta.nextElementSibling.CodeMirror.setValue(v); }, [k, v]);
	const openPage = async (hash) => {
		await open(p, 'index.php?p=globalcode' + (hash || ''));
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=globalcode' + (hash || '')); }
	};
	const look = (u) => open(p, u).then(() => p.waitForTimeout(250)).then(() => p.evaluate(() => ({ page: document.body.dataset.nuPage, mode: document.body.dataset.nuMode || '',
		form: document.body.dataset.nuForm || '', admin: document.body.dataset.nuAdmin || '', hook: window.e2eHook || null, ev: window.e2eEv || null,
		css: getComputedStyle(document.body).getPropertyValue('--e2e-gcss').trim(), order: (window.e2eOrder || []).join('>') })));
	del();
	// 0.8.554: every tab has the editor toolbar; the editor reaches the bottom of a tall window (≥ 360 px), full screen and back
	await p.setViewportSize({ width: 1300, height: 1100 });
	try {
		await openPage('');
		const box = () => p.$eval('#scodePane-header .CodeMirror', e => { const r = e.getBoundingClientRect(); return { top: r.top, h: r.height }; });
		const b0 = await box();
		expect(b0.h >= 360 && Math.abs(b0.top + b0.h - 1100) < 40, 'editor fills the window: ' + JSON.stringify(b0));
		expect(await p.$$eval('.scodeTools', a => a.every(e => e.querySelector('.nuCtFull'))), 'toolbar on every tab');
		await p.click('#scodePane-header .nuCtFull'); await p.waitForTimeout(300);
		expect(await p.evaluate(() => document.documentElement.classList.contains('nuCodeFullOpen')), 'full screen on');
		await p.click('#scodePane-header .nuCtFull'); await p.waitForTimeout(300);
		expect(Math.abs((await box()).h - b0.h) < 2, 'height back after full screen');
		await p.click('#scodeSeg [data-scode-group="all"]'); await p.waitForTimeout(200);   // 0.8.857: tabs of the chosen group only
		await p.click('#scodeTabBtn-gcss'); await p.waitForTimeout(400);
		expect(await p.$eval('#scodePane-gcss .CodeMirror', e => e.getBoundingClientRect().height) >= 360, 'hidden tab sized when shown');
	} finally {
		await p.setViewportSize({ width: 1300, height: 900 });
	}
	try {
		await openPage('#gjs');
		expect(await p.locator('#scodePane-gjs.active').count() === 1, 'Global JS tab from the address');
		await setCm('gjs', "window.e2eOrder = [];\nfunction nuOnPageLoad(page, info) { window.e2eHook = page + '|' + (info.mode || '') + '|' + (info.form || ''); window.e2eOrder.push('page'); }\n"
			+ "document.addEventListener('nu:pageload', function (e) { window.e2eEv = e.detail.page; });\nfunction nuOnLoad() { window.e2eOrder.push('global-onload'); }");
		await p.click('#scodeTabBtn-gcss'); await p.waitForTimeout(200);
		await setCm('gcss', '<style>\nbody { --e2e-gcss: 7px; }\n</style>');   // 0.8.557: wrapped as in Form CSS – the tags are removed on save
		await Promise.all([p.waitForNavigation(), p.click('#scodeSave')]);
		expect(!/<\/?style/i.test(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'custom_css'")), 'Global CSS stored with <style>');
		expect(/style> tags were removed/.test(await p.evaluate(() => [...document.querySelectorAll('.toast, .alert')].map(e => e.textContent).join(' '))), 'no note about the removed <style> tags');
		expect(/custom_js/.test(sql("SELECT GROUP_CONCAT(zzzzsys_nunext_setting_id) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id LIKE 'custom_%'")), 'not stored');
		expect(await p.locator('.alert-warning', { hasText: 'nuOnLoad' }).count() >= 1, 'no warning about the nuBuilder hook name');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_setup WHERE set_header LIKE '%e2eHook%'") === 0, 'written into nuBuilder\'s setup');
		// the editor itself: no global code
		let r = await look('index.php?p=globalcode');
		expect(r.page === 'globalcode' && r.hook === null && r.css === '', 'editor page loads the global code: ' + JSON.stringify(r));
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
		expect((await a.goto(BASE + 'index.php?p=globalcodefile&t=js')).status() === 403, 'file readable without a session');
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
		await open(p, 'index.php?p=globalcode' + hash);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=globalcode' + hash); }
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

T.inspect_hint_close = async (s) => {
	// Inspect record (0.8.1157): the hint closes with × and stays closed; (i) shows it again
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM sample_customer WHERE sample_customer_id = 'sample_cus_01'") !== '1') { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=inspect&f=samplecustomer&r=sample_cus_01');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-insp-hint-hidden'); } catch (e) { /* none */ } });
	await open(p, 'index.php?p=inspect&f=samplecustomer&r=sample_cus_01');
	expect(await p.isVisible('#inspHint') && !(await p.isVisible('#inspHintShow')), 'hint shown');
	await p.click('#inspHintHide');
	expect(!(await p.isVisible('#inspHint')) && await p.isVisible('#inspHintShowBtn'), 'hint closed, (i) shown');
	await open(p, 'index.php?p=inspect&f=samplecustomer&r=sample_cus_01');
	expect(!(await p.isVisible('#inspHint')), 'stays closed after reload');
	await p.click('#inspHintShowBtn');
	expect(await p.isVisible('#inspHint'), 'shown again');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
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
		fs.writeFileSync(files.js2, 'window.e2eIncB = 1;\nfunction nuE2eIncFn() { return 1; }\n');
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
		// Compatibility check (0.8.578): a function defined in an include file is the application's own, not missing
		const keepJs = sql("SELECT IFNULL(HEX(sfo_javascript), '-') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
		try {
			sql("UPDATE zzzzsys_form SET sfo_javascript = CONCAT(IFNULL(sfo_javascript, ''), '\\nnuE2eIncFn(); nuE2eNoDef();') WHERE zzzzsys_form_id = 'samplecustomer'");
			await open(p, 'index.php?p=compat');
			const miss = await p.$$eval('tr.compat-missing', trs => trs.map(t => t.textContent).join(' '));
			expect(miss.includes('nuE2eNoDef') && !miss.includes('nuE2eIncFn'), 'compat: function of the include file not missing');
		} finally {
			sql(`UPDATE zzzzsys_form SET sfo_javascript = ${keepJs === '-' ? 'NULL' : `UNHEX('${keepJs}')`} WHERE zzzzsys_form_id = 'samplecustomer'`);
		}
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
		await open(p, 'index.php?p=globalcode' + (hash || ''));
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=globalcode' + (hash || '')); }
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

// procedure editor tools on a phone (0.8.475): all buttons the same size, "Insert snippet" only as icon
T.proc_tools_phone = async (s) => {
	const id = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE RIGHT(zzzzsys_php_id, 3) NOT IN ('_BB', '_BE', '_BS', '_AS', '_BD', '_AD', '_AB') ORDER BY sph_code LIMIT 1");
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=procedures&id=' + encodeURIComponent(id)); await p.waitForTimeout(600);
		const r = await p.evaluate(() => {
			const bs = Array.from(document.querySelectorAll('.procCodeTools[data-for="sph_php"] .btn')).map(b => b.getBoundingClientRect());
			const snip = document.querySelector('.procCodeTools[data-for="sph_php"] .nuCtInsert');   // Insert menu (0.8.593, was the snippet button)
			return { n: bs.length, sizes: Array.from(new Set(bs.map(x => Math.round(x.width) + 'x' + Math.round(x.height)))), rows: new Set(bs.map(x => Math.round(x.top))).size, right: Math.round(Math.max(...bs.map(x => x.right))), vw: document.documentElement.clientWidth,
				label: snip ? getComputedStyle(snip.querySelector('.nuCtInsertLabel')).display : 'no snippets', aria: snip ? snip.getAttribute('aria-label') : '' };
		});
		expect(r.n >= 9 && r.sizes.length === 1 && r.rows <= 2 && r.right <= r.vw, 'buttons differ in size, or leave the screen: ' + JSON.stringify(r));
		expect(r.label === 'none' && /snippet/i.test(r.aria), 'Insert button shows its text on a phone: ' + JSON.stringify(r));
	} finally {
		await ctx.close();
	}
};

// code editors (0.8.593): Insert menu with the help's functions (search, detail, Insert call) and suggestions while typing (show-hint)
T.code_insert = async (s) => {
	const p = s.ga;
	const r = await p.request.get(BASE + 'index.php?p=fnlist&lang=php');
	const j = await r.json();
	expect(r.status() === 200 && j.items.length > 100 && j.items.some(i => i.n === 'nuGetProperty' && /#fn-php-nuGetProperty$/.test(i.h)), 'fnlist: ' + r.status());
	const id = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE RIGHT(zzzzsys_php_id, 3) NOT IN ('_BB', '_BE', '_BS', '_AS', '_BD', '_AD', '_AB') ORDER BY sph_code LIMIT 1");
	await open(p, 'index.php?p=procedures&id=' + encodeURIComponent(id)); await p.waitForTimeout(400);
	const cm = (fn) => p.evaluate(fn);
	await cm(() => { const c = document.querySelector('#sph_php').nextElementSibling.CodeMirror; c.setValue(''); c.focus(); });
	await p.click('.procCodeTools[data-for="sph_php"] .nuCtInsert');
	await p.waitForSelector('.nuInsMenu .nuInsList .list-group-item');
	await p.fill('.nuInsMenu input[type=search]', 'nuGetProperty'); await p.waitForTimeout(200);
	expect(/nuGetProperty\(/.test(await p.textContent('.nuInsMenu .nuInsSig')) && await p.locator('.nuInsMenu .nuInsHelp').count() === 1, 'detail with signature and help link');
	await p.keyboard.press('Enter'); await p.waitForTimeout(200);
	let st = await cm(() => { const c = document.querySelector('#sph_php').nextElementSibling.CodeMirror; return [c.getValue(), c.getSelection()]; });
	expect(st[0] === 'nuGetProperty($name)' && st[1] === '$name', 'Insert call, first parameter selected: ' + JSON.stringify(st));
	expect(await p.locator('.nuInsMenu').count() === 0, 'menu closed');
	// Shift+Enter: the example
	await cm(() => { const c = document.querySelector('#sph_php').nextElementSibling.CodeMirror; c.setValue(''); c.focus(); });
	await p.click('.procCodeTools[data-for="sph_php"] .nuCtInsert'); await p.waitForSelector('.nuInsMenu .nuInsList .list-group-item');
	await p.fill('.nuInsMenu input[type=search]', 'nuGetProperty'); await p.waitForTimeout(200);
	await p.keyboard.press('Shift+Enter'); await p.waitForTimeout(200);
	st = await cm(() => document.querySelector('#sph_php').nextElementSibling.CodeMirror.getValue());
	expect(/nuGetProperty\('/.test(st), 'Insert example: ' + st);
	// suggestions while typing
	await cm(() => { const c = document.querySelector('#sph_php').nextElementSibling.CodeMirror; c.setValue(''); c.focus(); });
	await p.keyboard.type('nuGetPr'); await p.waitForTimeout(600);
	expect(await p.locator('.CodeMirror-hints li').count() >= 1, 'suggestions shown');
	await p.keyboard.press('Enter'); await p.waitForTimeout(200);
	st = await cm(() => document.querySelector('#sph_php').nextElementSibling.CodeMirror.getValue());
	expect(/^nuGetPro\w+\(/.test(st), 'suggestion inserted: ' + st);
	// inside a string: no suggestions; Ctrl+Space: suggestions anyway
	await cm(() => { const c = document.querySelector('#sph_php').nextElementSibling.CodeMirror; c.setValue("$a = 'nuGe"); c.setCursor(0, 10); c.focus(); });
	await p.keyboard.type('t'); await p.waitForTimeout(500);
	expect(await p.locator('.CodeMirror-hints').count() === 0, 'no suggestions in a string');
	// JavaScript tab: the JavaScript functions
	await p.click('#procTabs [data-bs-target="#procTabJs"]'); await p.waitForTimeout(300);
	await p.click('.procCodeTools[data-for="sph_javascript"] .nuCtInsert'); await p.waitForSelector('.nuInsMenu .nuInsList .list-group-item');
	await p.fill('.nuInsMenu input[type=search]', 'nuGetValue'); await p.waitForTimeout(200);
	expect(/nuGetValue\(/.test(await p.textContent('.nuInsMenu .nuInsSig')), 'JS functions on the JS tab');
	await p.keyboard.press('Escape');
	await p.click('#procTabs [data-bs-target="#procTabPhp"]'); await p.waitForTimeout(200);   // the page remembers the tab
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// code snippets by nuBuilder Forte's scope "Selectable in" (0.8.714): 0 Forms / Custom Code, 1 Setup / Header, 2 SQL, 3 Procedures/PHP;
// without a scope by language; tab Snippets of the Insert menu with "Manage snippets"
T.snippet_scope = async (s) => {
	const p = s.ga;
	const rows = [['e2esn_php', 'e2e_php3', 'PHP', '["3"]'], ['e2esn_hdr', 'e2e_hdr1', 'JavaScript', '["1"]'], ['e2esn_sql', 'e2e_sql2', 'SQL', '["0","2"]'], ['e2esn_none', 'e2e_none_php', 'PHP', null]];
	sql("DELETE FROM zzzzsys_code_snippet WHERE zzzzsys_code_snippet_id LIKE 'e2esn_%'");
	for (const [id, code, lang, sc] of rows) sql(`INSERT INTO zzzzsys_code_snippet (zzzzsys_code_snippet_id, cot_code, cot_description, cot_group, cot_source_code, cot_language, cot_scope) VALUES ('${id}', '${code}', 'e2e', 'e2e', '/* ${code} */', '${lang}', ${sc === null ? 'NULL' : `'${sc}'`})`);
	try {
		const names = async (scope, lang) => (await (await p.request.get(BASE + `index.php?p=snippetlist&scope=${scope}&lang=${lang}`)).json()).items.map(x => x[0]).filter(n => /^e2e_/.test(n));
		expect(JSON.stringify(await names(3, 'PHP')) === '["e2e_none_php","e2e_php3"]', 'scope 3: ' + JSON.stringify(await names(3, 'PHP')));
		expect(JSON.stringify(await names(2, 'SQL')) === '["e2e_sql2"]' && JSON.stringify(await names(1, 'JS')) === '["e2e_hdr1"]' && JSON.stringify(await names(0, 'JS')) === '["e2e_sql2"]', 'scopes 2 / 1 / 0');
		// procedure editor (PHP = scope 3): tab Snippets with the PHP snippets and the link to the snippets form
		const id = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE RIGHT(zzzzsys_php_id, 3) NOT IN ('_BB', '_BE', '_BS', '_AS', '_BD', '_AD', '_AB') ORDER BY sph_code LIMIT 1");
		await open(p, 'index.php?p=procedures&id=' + encodeURIComponent(id)); await p.waitForTimeout(400);
		await p.evaluate(() => { const c = document.querySelector('#sph_php').nextElementSibling.CodeMirror; c.setValue(''); c.focus(); });
		await p.click('.procCodeTools[data-for="sph_php"] .nuCtInsert'); await p.waitForSelector('.nuInsMenu .nuInsTabs');
		await p.click('.nuInsMenu .nuInsTabs [data-tab="sn"]'); await p.fill('.nuInsMenu input[type=search]', 'e2e_'); await p.waitForTimeout(200);
		const shown = await p.locator('.nuInsMenu .nuInsList .list-group-item .fw-semibold').allTextContents();
		expect(JSON.stringify(shown) === '["e2e_none_php","e2e_php3"]', 'procedure PHP snippets: ' + JSON.stringify(shown));
		expect(/f=nucodesnippet/.test(await p.getAttribute('.nuInsMenu .nuInsManage', 'href') || ''), 'Manage snippets link');
		await p.keyboard.press('Enter'); await p.waitForTimeout(200);
		expect(/e2e_none_php/.test(await p.evaluate(() => document.querySelector('#sph_php').nextElementSibling.CodeMirror.getValue())), 'snippet not inserted');
		// Global code (Setup / Header = scope 1)
		await open(p, 'index.php?p=globalcode'); await p.waitForTimeout(400);
		await p.locator('.nuCtInsert:visible').first().click(); await p.waitForSelector('.nuInsMenu');
		if (await p.locator('.nuInsMenu .nuInsTabs [data-tab="sn"]').count()) await p.click('.nuInsMenu .nuInsTabs [data-tab="sn"]');
		await p.fill('.nuInsMenu input[type=search]', 'e2e_'); await p.waitForTimeout(200);
		const g = await p.locator('.nuInsMenu .nuInsList .list-group-item .fw-semibold').allTextContents();
		expect(JSON.stringify(g) === '["e2e_hdr1"]', 'Global code snippets: ' + JSON.stringify(g));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_code_snippet WHERE zzzzsys_code_snippet_id LIKE 'e2esn_%'");
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

// 0.8.1058: File Manager toolbar in one row on phones (320 / 360 / 390 px): no separator, tighter gaps and buttons
// 0.8.1095: File Manager on phones – the button left of Refresh switches the column between size and modified date (remembered)
// 0.8.1096: Debug results on phones – Refresh stays in the title bar (.nuPhKeep); without records no ⋮ with one entry, with records ⋮ = Export
T.debug_phone_refresh = async (s) => {
	const ga = s.ga, vp = ga.viewportSize();
	const dump = path.join(os.tmpdir(), 'nunext-e2e-1096.sql');
	execSync(`mysqldump ${DB} zzzzsys_debug > ${dump}`);
	try {
		await ga.setViewportSize({ width: 390, height: 800 });
		const look = () => ga.evaluate(() => {
			const bar = document.querySelector('.nuListHead > .d-flex'), ref = bar.querySelector('.nuPhKeep'), more = bar.querySelector('.nuPhMore');
			return { ref: !!ref && ref.offsetParent !== null, more: !!more && more.offsetParent !== null, items: more ? more.querySelectorAll('.dropdown-item').length : 0 };
		});
		sql("INSERT INTO zzzzsys_debug (zzzzsys_debug_id, deb_message, deb_added) VALUES ('e2e_1096', 'e2e debug row', UNIX_TIMESTAMP())");
		await open(ga, 'index.php?p=debug');
		let r = await look();
		expect(r.ref && r.more && r.items >= 1, 'with records: Refresh in the bar, ⋮ for Export: ' + JSON.stringify(r));
		sql('DELETE FROM zzzzsys_debug');
		await open(ga, 'index.php?p=debug');
		r = await look();
		expect(r.ref && !r.more, 'no records: Refresh in the bar, no ⋮: ' + JSON.stringify(r));
	} finally {
		await ga.setViewportSize(vp);
		execSync(`mysql ${DB} < ${dump}`);
	}
};

// syntax check of the code editors (0.8.781): gutter marks while typing (JS / CSS / HTML in the browser, PHP through ?p=codecheck),
// the ✓ button's list, Save asks with errors (Cancel = back to the line); nothing is saved here
T.code_format = async (s) => {                           // 0.8.896: the formatters of the code editors (js-beautify 2, Prettier 3.9 + plugin-php 0.26, sql-formatter)
	const p = s.ga;
	await open(p, 'index.php?p=globalcode#gjs');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=globalcode#gjs'); }
	const r = await p.evaluate(async () => {
		const f = (c, l) => Promise.resolve(window.NuCodeTools.format(c, l, 'mysql')).then(x => x, e => 'ERR ' + (e && e.message || e));
		return {
			js: await f("function f(a,b){\nif(a){\nreturn '#RECORD_ID#'\n}\n}", 'JS'),
			html: await f('<div><p>Hi</p><script type="importmap">{"imports":{"a":"b"}}</script></div>', 'HTML'),
			css: await f('.a{color:red;margin:0}', 'CSS'),
			php: await f("$s = \"SELECT 1\";if($a){nuDisplayError('x');}\n$f = fn($x) => $x?->y;", 'PHP'),
			sql: await f('select a,b from t where id=#RECORD_ID#', 'SQL')
		};
	});
	expect(/function f\(a, b\) \{\n\s+if \(a\) \{/.test(r.js) && r.js.includes("'#RECORD_ID#'"), 'JS: ' + r.js);
	expect(/"imports": \{/.test(r.html), 'HTML (importmap as JSON): ' + r.html);
	expect(/\.a \{\n\s+color: red;/.test(r.css), 'CSS: ' + r.css);
	expect(/if \(\$a\) \{\n\s+nuDisplayError\('x'\);\n\}/.test(r.php) && r.php.includes('fn($x) => $x?->y') && !/<\?php/.test(r.php), 'PHP: ' + r.php);
	expect(/SELECT\s+a,\s+b\s+FROM\s+t/.test(r.sql) && r.sql.includes('#RECORD_ID#'), 'SQL: ' + r.sql);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.code_check = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=globalcode#gjs');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=globalcode#gjs'); }
	const setCode = (id, v) => p.evaluate(([id, v]) => { document.getElementById(id).nextElementSibling.CodeMirror.setValue(v); }, [id, v]);
	const marks = id => p.evaluate(id => Array.from(document.querySelectorAll('#' + id + ' + .CodeMirror .nuChkMark')).map(m => (m.classList.contains('nuChk-error') ? 'E:' : 'W:') + m.title), id);
	const tab = async k => { await p.click('[data-nu-tab="' + k + '"]').catch(() => {}); await p.waitForTimeout(300); };
	await tab('gjs');
	await setCode('scode-gjs', "var a = '#RECORD_ID#';\nfunction x( {\n  return 2;\n}\n");
	await p.waitForTimeout(1500);
	let m = await marks('scode-gjs');
	expect(m.length === 1 && m[0].startsWith('E:'), 'JS error marked: ' + m);
	await p.click('#scodeSave'); await p.waitForTimeout(700);
	expect(await p.locator('#nuConfirm').isVisible() && /3/.test(await p.locator('#nuConfirm .nuConfirmDetails').innerText()), 'Save asks with the error');
	await p.locator('#nuConfirm .modal-footer [data-bs-dismiss="modal"]').click(); await p.waitForTimeout(500);
	expect(/p=globalcode/.test(p.url()) && await p.evaluate(() => document.getElementById('scode-gjs').nextElementSibling.CodeMirror.getCursor().line) === 2, 'Cancel: back at the line, nothing saved');
	await setCode('scode-gjs', "var a = '#RECORD_ID#';\nif (#X# > 1) a = 2;\n");
	await p.waitForTimeout(1300);
	expect((await marks('scode-gjs')).length === 0, 'valid JS with hash variables: no marks');
	await tab('gcss');
	await setCode('scode-gcss', '.a { color: red; }\n.c { color: red;\n');
	await p.waitForTimeout(1300);
	m = await marks('scode-gcss');
	expect(m.length === 1 && m[0].startsWith('E:'), 'CSS unclosed {: ' + m);
	await setCode('scode-gcss', '.a { color: red; }\nb:nosuchpseudo { color: blue }\n');
	await p.waitForTimeout(1300);
	m = await marks('scode-gcss');
	expect(m.length === 1 && m[0].startsWith('W:') && /nosuchpseudo/.test(m[0]), 'CSS rule the browser drops = warning: ' + m);
	await p.click('#scodeSeg [data-scode-group="forms"]'); await p.waitForTimeout(300);   // Form pages / All pages switch (0.8.857)
	await tab('include');
	await setCode('scode-include', '<link rel="stylesheet" href="x.css">\n<script>\nvar q = ;\n</script>\n');
	await p.waitForTimeout(1300);
	m = await marks('scode-include');
	expect(m.length === 1 && m[0].startsWith('E:'), 'script inside HTML: ' + m);
	// PHP: the server parses (nothing runs)
	await open(p, 'index.php?p=procedures&id=new');
	await p.evaluate(() => document.getElementById('sph_php').nextElementSibling.CodeMirror.setValue("$a = '#RECORD_ID#';\n$b = ;\n"));
	await p.waitForTimeout(2500);
	m = await marks('sph_php');
	expect(m.length === 1 && m[0].startsWith('E:'), 'PHP error marked: ' + m);
	await p.locator('#sph_php ~ * .nuCtCheck, .procCodeTools[data-for="sph_php"] .nuCtCheck').first().click(); await p.waitForTimeout(900);
	expect(/2/.test(await p.locator('.nuChkPop').innerText()), 'the ✓ button lists the problem with its line');
	const r = await p.evaluate(async () => { const b = new URLSearchParams({ action: 'check', code: 'echo 1;', _csrf: document.querySelector('[data-csrf]').getAttribute('data-csrf') });
		return (await fetch('index.php?p=codecheck', { method: 'POST', body: b })).json(); });
	expect(r.ok && r.errors.length === 0, 'codecheck: valid PHP: ' + JSON.stringify(r));
};

T.sql_check = async (s) => {
	// 0.8.999: SQL of the code editors checked by the database (prepare / NOEXEC, never run) – marks in the gutter of the browse SQL
	// pane; hash variables and #TABLE_ID# do not count as errors; lists are not SQL; users without design rights get no check
	const p = s.ga, F = 'samplecustomer';
	await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuDesignMore');
	const chk = (pg, code) => pg.evaluate(async (c) => {
		const b = new URLSearchParams({action: 'check', lang: 'sql', code: c, _csrf: nuNext.csrf});
		return (await fetch('index.php?p=codecheck', {method: 'POST', body: b, credentials: 'same-origin'})).json();
	}, code);
	let r = await chk(p, "SELECT cus_name FROM sample_customer WHERE cus_code = '#cus_code#' AND sample_customer_id = #RECORD_ID#");
	expect(r.errors.length === 0 && !r.skip, 'valid SQL with hash variables: ' + JSON.stringify(r));
	r = await chk(p, 'SELECT * FROM\nsample_customer\nWHER x = 1');
	expect(r.errors.length === 1 && r.errors[0].line === 3, 'syntax error on line 3: ' + JSON.stringify(r));
	r = await chk(p, 'SELECT cus_name, e2e_nope FROM sample_customer');
	expect(r.errors.length === 1 && /e2e_nope/.test(r.errors[0].msg), 'unknown column: ' + JSON.stringify(r));
	r = await chk(p, 'SELECT * FROM #TABLE_ID#');
	expect(r.errors.length === 0, '#TABLE_ID# counted: ' + JSON.stringify(r));
	r = await chk(p, '1|One|2|Two');
	expect(r.errors.length === 0, 'a list checked as SQL: ' + JSON.stringify(r));
	const before = sql('SELECT COUNT(*) FROM sample_customer');
	r = await chk(p, "SELECT * FROM sample_customer WHERE 1 = 0 UNION SELECT * FROM sample_customer");
	expect(sql('SELECT COUNT(*) FROM sample_customer') === before, 'the check changed data');
	// the pane: a mark in the gutter while typing, gone after the fix
	await p.click('#nuDesignMore'); await p.click('#nuBrowseSqlEdit');
	await p.waitForSelector('#nuBsqPane .CodeMirror');
	const cur = await p.evaluate(() => document.querySelector('#nuBsqPane .CodeMirror').CodeMirror.getValue());
	await p.evaluate(() => document.querySelector('#nuBsqPane .CodeMirror').CodeMirror.setValue('SELECT * FROM sample_customer\nWHERE e2e_nope = 1'));
	await p.waitForSelector('#nuBsqPane .nuChkMark.nuChk-error', {timeout: 8000});
	const t = await p.getAttribute('#nuBsqPane .nuChkMark.nuChk-error', 'title');
	expect(/e2e_nope/.test(t || ''), 'gutter mark: ' + t);
	await p.evaluate((v) => document.querySelector('#nuBsqPane .CodeMirror').CodeMirror.setValue(v), cur);
	await p.waitForFunction(() => !document.querySelector('#nuBsqPane .nuChkMark'), null, {timeout: 8000});
	await p.keyboard.press('Escape');
	// a user without the save right on nuform / nuselect: no check (no answer about tables)
	const anna = await login('sample_anna', UPW);
	try {
		await anna.goto(BASE + 'index.php?p=home');
		r = await anna.evaluate(async () => {
			const tok = (document.querySelector('input[name="_csrf"]') || {}).value || (document.querySelector('[data-csrf]') || {getAttribute: () => ''}).getAttribute('data-csrf');
			const b = new URLSearchParams({action: 'check', lang: 'sql', code: 'SELECT * FROM e2e_nope', _csrf: tok});
			return (await fetch('index.php?p=codecheck', {method: 'POST', body: b, credentials: 'same-origin'})).json();
		});
		expect(r.skip === true && r.errors.length === 0, 'sample user got a check: ' + JSON.stringify(r));
	} finally {
		await anna.context().close();
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.code_search = async (s) => {
	// 0.8.1172: code search – procedures / form code, match options, filters, line links into the editors, export, saved searches, entries
	const p = s.ga;
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_cs_proc'");
	sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'codesearch'");
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php, sph_javascript) VALUES ('e2e_cs_proc', 'e2e_cs_proc', 'e2e', 'hide', '1', '0', '$a = 1;\\n$b = 2;\\n$c = \\'e2eCsMarker\\'; // e2eCsNot', 'console.log(\"e2eCsMarker\");')"});
	try {
		const summary = async (q) => {
			await open(p, 'index.php?p=codesearch&go=1&drafts=1&pages=1&' + q);
			return p.evaluate(() => { const e = document.getElementById('csSummary'); return {hits: e ? Number(e.dataset.hits) : -1, parts: e ? Number(e.dataset.parts) : -1, err: (document.getElementById('csError') || {}).textContent || '',
				groups: [...document.querySelectorAll('.csGroupTitle')].map((x) => x.textContent)}; });
		};
		let r = await summary('q=e2eCsMarker');
		expect(r.hits === 2 && r.parts === 2 && r.groups.join() === 'e2e_cs_proc', 'PHP + JavaScript of the procedure: ' + JSON.stringify(r));
		r = await summary('q=e2eCsMarker&lang[]=php');
		expect(r.hits === 1, 'language filter: ' + JSON.stringify(r));
		r = await summary('q=e2eCsMarker&x=e2eCsNot');
		expect(r.hits === 1, 'Without leaves out the PHP part: ' + JSON.stringify(r));
		r = await summary('q=e2eCsMarker+zzzzNotThere&m=all');
		expect(r.hits === 0, 'all words: ' + JSON.stringify(r));
		r = await summary('q=e2eCsMarker+zzzzNotThere&m=any');
		expect(r.hits === 2, 'any word: ' + JSON.stringify(r));
		r = await summary('q=' + encodeURIComponent('e2eCs[M]ark') + '&r=1');
		expect(r.hits === 2, 'regular expression: ' + JSON.stringify(r));
		r = await summary('q=' + encodeURIComponent('e2eCs[') + '&r=1');
		expect(r.err !== '', 'invalid regular expression: ' + JSON.stringify(r));
		r = await summary('q=E2ECSMARKER&c=1');
		expect(r.hits === 0, 'match case: ' + JSON.stringify(r));
		r = await summary('q=e2eCsMark&w=1');
		expect(r.hits === 0, 'whole word: ' + JSON.stringify(r));
		r = await summary('q=e2eCsMarker&place[]=formjs');
		expect(r.hits === 0, 'place filter: ' + JSON.stringify(r));
		// a line link of the procedure opens its editor at the line
		await summary('q=e2eCsMarker&lang[]=php');
		const href = await p.$eval('.csHit a.csNo', (a) => a.getAttribute('href'));
		expect(/p=procedures/.test(href) && /cl=3/.test(href) && /ck=sph_php/.test(href), 'line link: ' + href);
		await open(p, href);
		await p.waitForSelector('.nuCodeHitLine', {timeout: 5000}).catch(async () => {
			const st = await p.evaluate(() => ({url: location.href, tab: (document.querySelector('#procTabs .active') || {}).textContent, cms: document.querySelectorAll('.CodeMirror').length,
				php: (document.querySelector('#procTabPhp') || {}).className, err: (document.querySelector('.alert-danger') || {}).textContent}));
			throw new Error('no marked line: ' + JSON.stringify(st));
		});
		const hl = await p.$eval('.nuCodeHitLine', (e) => e.textContent);
		expect(/e2eCsMarker/.test(hl), 'the procedure editor marks line 3: ' + hl);
		// form code: design mode's Code workspace at the line
		r = await summary('q=' + encodeURIComponent("nuSetValue('ord_status'") + '&f[]=sampleorder');
		if (r.hits > 0) {
			const fh = await p.$eval('.csHit a.csNo', (a) => a.getAttribute('href'));
			expect(/p=builder/.test(fh) && /dsitem=sfo_/.test(fh), 'form code link: ' + fh);
			await open(p, fh);
			await p.waitForSelector('.nuCodeHitLine', {timeout: 8000});
			const fl = await p.$eval('.nuCodeHitLine', (e) => e.textContent);
			expect(/ord_status/.test(fl), 'design mode Code at the line: ' + fl);
		}
		// export
		await summary('q=e2eCsMarker');
		const csv = await p.evaluate(async (u) => (await fetch(u)).text(), await p.$eval('#csExpCsv', (a) => a.getAttribute('href')));
		expect(/e2eCsMarker/.test(csv) && /e2e_cs_proc/.test(csv), 'CSV export: ' + csv.slice(0, 200));
		const xl = await p.evaluate(async (u) => { const r = await fetch(u); return [r.headers.get('content-type'), (await r.arrayBuffer()).byteLength]; }, await p.$eval('#csExpXlsx', (a) => a.getAttribute('href')));
		expect(/sheet/.test(xl[0]) && xl[1] > 1000, 'Excel export: ' + xl.join(' '));
		// My searches
		await p.click('#csSavedBtn'); await p.click('#csSaveOpen');
		await p.fill('#csSaveName', 'e2e marker');
		await Promise.all([p.waitForNavigation(), p.click('#csSaveForm button[type=submit]')]);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'codesearch' AND name = 'e2e marker'") === '1', 'search saved');
		await p.click('#csSavedBtn');
		const saved = await p.$$eval('#csSavedMenu a.dropdown-item', (a) => a.map((x) => x.textContent + '|' + x.getAttribute('href')));
		expect(saved.some((x) => /^e2e marker\|.*q=e2eCsMarker/.test(x)), 'listed in My searches: ' + saved.join(', '));
		await Promise.all([p.waitForNavigation(), p.click('#csSavedMenu .csSavedDel')]);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'codesearch'") === '0', 'saved search deleted');
		// quick search: functions nuBuilder Next lacks (one regular expression, no error)
		await open(p, 'index.php?p=codesearch');
		await Promise.all([p.waitForNavigation(), p.click('#csQuickMissing')]);
		// 0.8.1173: built on the server (preset=missing) – the regular expression over all names made the address too long for nginx (414)
		const qm = await p.evaluate(() => ({chip: !!document.getElementById('csPresetChip'), err: !!document.getElementById('csError'), sum: !!document.getElementById('csSummary'), len: location.href.length}));
		expect(qm.chip && !qm.err && qm.sum && qm.len < 300, 'Functions nuBuilder Next lacks: ' + JSON.stringify(qm));
		// a Forte-only call in a procedure is found; typing an own text ends the preset
		sql("UPDATE zzzzsys_php SET sph_javascript = 'nuOpenNuDebug();' WHERE zzzzsys_php_id = 'e2e_cs_proc'");
		await p.reload();
		expect(await p.$$eval('.csGroupTitle', (g) => g.some((x) => x.textContent === 'e2e_cs_proc')), 'preset finds a call of a function nuBuilder Next lacks');
		// 0.8.1175: functions that work are not in the list (the help text above the lists names nuSetFormValue as working)
		// 0.8.1176: a declaration of the same name (a hook of the form's own) and a PHP call of a JavaScript-only name in another case are not calls of a missing function
		sql("UPDATE zzzzsys_php SET sph_javascript = 'function nuOpenNuDebug() {}', sph_php = 'nuSetFormValue(\\'x\\', 1);\\nnuAddJavaScript(\\'y\\');' WHERE zzzzsys_php_id = 'e2e_cs_proc'");
		await p.reload();
		expect(!(await p.$$eval('.csGroupTitle', (g) => g.some((x) => x.textContent === 'e2e_cs_proc'))), 'nuSetFormValue is not reported as missing');
		const qw = await p.evaluate(() => document.getElementById('csQ').getBoundingClientRect().width);
		expect(qw > 200, 'the search field keeps its width next to the preset: ' + qw);
		await p.fill('#csQ', 'e2eCsMarker');
		await Promise.all([p.waitForNavigation(), p.click('#csGo')]);
		expect(!/preset=/.test(p.url()) && !(await p.$('#csPresetChip')), 'own text ends the preset: ' + p.url());
		// entries: the form's ⋮ menu, the function reference, the start page tile
		await open(p, 'index.php?p=form&f=sampleorder');
		const tool = await p.evaluate(() => (window.nuNext.tools || {}).codesearch || '');
		expect(/p=codesearch/.test(tool) && /sampleorder/.test(tool), 'form ⋮ entry: ' + tool);
		await open(p, 'index.php?p=help&part=ref');
		expect(await p.$$eval('a.nuFnUsed', (a) => a.length) > 50, 'function reference: Where used links');
		await open(p, 'index.php?p=home');
		expect(await p.$$eval('[data-nu-tile="codesearch"], a[href*="p=codesearch"]', (a) => a.length) > 0, 'start page tile');
		expect(!s.ga.errors || !s.ga.errors.length, 'no JS errors');
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_cs_proc'");
		sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'codesearch'");
	}
};

T.code_replace = async (s) => {
	// 0.8.1174: search and replace of the code search – preview with ticks, only ticked lines, form history snapshot, undo, $1 of a
	// regular expression, mode check, "changed meanwhile"
	const p = s.ga;
	const keep = path.join(os.tmpdir(), 'nunext-e2e-cr.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_log > ' + keep);
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_cr_proc'");
	execSync('mysql ' + DB, {input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php, sph_javascript) VALUES ('e2e_cr_proc', 'e2e_cr_proc', 'e2e', 'hide', '1', '0', '$a = 1;\\n$b = \\'e2eRpOld\\';\\n$c = 3;\\n$d = \\'e2eRpOld\\';', 'console.log(\"e2eRpOld\");')"});
	execSync('mysql ' + DB, {input: "UPDATE zzzzsys_form SET sfo_javascript = CONCAT(COALESCE(sfo_javascript, ''), '\\n// e2eRpOld') WHERE zzzzsys_form_id = 'sampleorder'"});
	const php = () => sql("SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_cr_proc'");
	try {
		await open(p, 'index.php?p=codesearch&q=e2eRpOld&go=1&rmode=1&rp=e2eRpNew');
		if (/p=reauth/.test(p.url())) { await p.fill('input[type=password]', GA[1]); await Promise.all([p.waitForNavigation(), p.click('form button[type=submit]')]); await p.waitForLoadState('networkidle'); }
		const pv = await p.evaluate(() => ({form: !!document.getElementById('csRpForm'), ticks: document.querySelectorAll('.csRpTick').length,
			news: [...document.querySelectorAll('.csNewLine .csText')].map((x) => x.textContent), go: (document.getElementById('csRpGo') || {}).textContent}));
		expect(pv.form && pv.ticks === 4 && pv.news.every((x) => /e2eRpNew/.test(x) && !/e2eRpOld/.test(x)) && /4/.test(pv.go), 'preview: ' + JSON.stringify(pv));
		// leave line 4 of the PHP as it is
		await p.evaluate(() => { const c = [...document.querySelectorAll('.csRpTick')].find((x) => x.value === '4' && x.closest('.csPart').dataset.lang === 'php'); if (c) c.click(); });
		expect(/3/.test(await p.textContent('#csRpGo')), 'the button counts the ticked lines');
		await p.click('#csRpGo');
		await p.waitForSelector('#nuConfirm.show .js-confirm-ok');
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm.show .js-confirm-ok')]);
		await p.waitForLoadState('networkidle');
		const after = php();
		expect(/\$b = 'e2eRpNew'/.test(after) && /\$d = 'e2eRpOld'/.test(after), 'only the ticked PHP line replaced: ' + after);
		expect(sql("SELECT sph_javascript FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_cr_proc'") === 'console.log("e2eRpNew");', 'JavaScript replaced');
		expect(/e2eRpNew/.test(sql("SELECT sfo_javascript FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'")), 'form JavaScript replaced');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_form_history WHERE form_id = 'sampleorder' AND action = 'code search: replace'") !== '0', 'form history snapshot before the replace');
		expect(sql("SELECT CONCAT(part_count, 0x2f, line_count) FROM zzzzsys_nunext_code_replace ORDER BY created_at DESC LIMIT 1") === '3/3', 'run recorded');
		expect(await p.locator('.alert-success').count() > 0, 'success message');
		// undo
		await p.click('#csBatches summary').catch(() => {});
		await p.click('.csBatch .csUndo');
		await p.waitForSelector('#nuConfirm.show .js-confirm-ok');
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm.show .js-confirm-ok')]);
		expect(/\$b = 'e2eRpOld'/.test(php()) && /e2eRpOld/.test(sql("SELECT sph_javascript FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_cr_proc'")), 'undo put the code back: ' + php());
		expect(!/e2eRpNew/.test(sql("SELECT sfo_javascript FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'")), 'undo put the form JavaScript back');
		expect(await p.locator('.csBatch .badge').count() > 0, 'run marked as undone');
		// regular expression with $1; a mode that cannot replace
		await open(p, 'index.php?p=codesearch&q=' + encodeURIComponent('(e2eRp)Old') + '&r=1&go=1&rmode=1&rp=' + encodeURIComponent('$1X') + '&lang[]=js&place[]=proc');
		const rx = await p.$$eval('.csNewLine .csText', (a) => a.map((x) => x.textContent));
		expect(rx.length === 1 && /e2eRpX/.test(rx[0]), 'regex preview with $1: ' + rx.join(' | '));
		await open(p, 'index.php?p=codesearch&q=e2eRpOld&m=any&go=1&rmode=1&rp=x');
		expect(await p.locator('#csRpErr').count() === 1 && await p.locator('#csRpForm').count() === 0, 'Any word cannot replace');
		// changed meanwhile: nothing written
		await open(p, 'index.php?p=codesearch&q=e2eRpOld&go=1&rmode=1&rp=e2eRpNew&place[]=proc');
		sql("UPDATE zzzzsys_php SET sph_php = CONCAT(sph_php, '\\n// later') WHERE zzzzsys_php_id = 'e2e_cr_proc'");
		await p.click('#csRpGo');
		await p.waitForSelector('#nuConfirm.show .js-confirm-ok');
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm.show .js-confirm-ok')]);
		expect(!/e2eRpNew/.test(php()) && await p.locator('.alert-warning').count() > 0, 'a part changed since the preview is not replaced');
		expect(!s.ga.errors || !s.ga.errors.length, 'no JS errors');
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_cr_proc'");
		execSync('mysql ' + DB + ' < ' + keep);
		sql("DELETE FROM zzzzsys_nunext_code_replace");
	}
};

// 0.8.1229: the procedure's code editor fills the window down to its bottom edge
T.proc_editor_fill = async (s) => {
	const p = s.ga;
	const id = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE zzzzsys_php_id LIKE 'sample_proc%' ORDER BY zzzzsys_php_id LIMIT 1");
	if (!id) { s.skip = 'no procedure'; return; }
	try {
		for (const h of [900, 1200]) {
			await p.setViewportSize({ width: 1280, height: h });
			await open(p, 'index.php?p=procedures&id=' + encodeURIComponent(id));
			await p.waitForTimeout(400);
			await p.click('#procTabs [data-bs-target="#procTabPhp"]'); await p.waitForTimeout(400);   // a tab remembered by an earlier test
			const r = await p.evaluate(() => { const w = document.querySelector('#procTabPhp .procCM'); const b = w.getBoundingClientRect();
				return { bottom: Math.round(b.bottom + window.scrollY), page: document.documentElement.scrollHeight, vh: window.innerHeight, h: Math.round(b.height), scroll: document.documentElement.scrollHeight > window.innerHeight + 1 }; });
			expect(r.h > 380 && !r.scroll && r.bottom >= r.vh - 60, 'editor at ' + h + ' px: ' + JSON.stringify(r));
		}
		// Details opened: still no page scroll, the editor smaller
		await p.click('#procFields > summary'); await p.waitForTimeout(400);
		const o = await p.evaluate(() => ({ h: Math.round(document.querySelector('#procTabPhp .procCM').getBoundingClientRect().height), scroll: document.documentElement.scrollHeight > window.innerHeight + 1 }));
		expect(!o.scroll && o.h >= 352, 'with Details open: ' + JSON.stringify(o));
	} finally { await p.setViewportSize({ width: 1280, height: 900 }); }
};

T.code_search_phone = async (s) => {
	// 0.8.1241 (the owner picked mockup A): on phones one row field · Options · Filters (count), no Search button; options and filters in
	// bottom sheets inside the form; chips of what is set (× = without it); closing a sheet after a change searches again; back to the
	// normal layout from 576 px on
	const p = s.ga;
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, storageState: await p.context().storageState() });
	const m = await ctx.newPage();
	const errs = []; m.on('pageerror', e => errs.push(e.message));
	try {
		await m.goto(BASE + 'index.php?p=codesearch&q=nuSetValue&go=1&w=1&lang[]=js&drafts=1&pages=1');
		const row = await m.evaluate(() => {
			const r = (id) => document.getElementById(id).getBoundingClientRect();
			const q = r('csQ'), o = r('csOptBtn'), f = r('csFiltBtn');
			return { go: !!document.getElementById('csGo').offsetParent, sameRow: Math.abs(q.top - o.top) < 8 && Math.abs(q.top - f.top) < 8,
				fits: f.right <= innerWidth, count: document.querySelector('#csFiltBtn .csPhCount')?.textContent, wInSheet: !!document.querySelector('#csOptSheet #csW'),
				filtInSheet: !!document.querySelector('#csFiltSheet #csFilters'), ph: document.getElementById('csQ').placeholder, chips: [...document.querySelectorAll('#csChips .csChip')].map(c => c.textContent.trim()) };
		});
		if (row.go || !row.sameRow || !row.fits || row.count !== '1' || !row.wInSheet || !row.filtInSheet || row.chips.length !== 2 || !/…$/.test(row.ph) || row.ph.length > 15) throw new Error('phone row: ' + JSON.stringify(row));
		await m.click('#csOptBtn');
		await m.waitForSelector('#csOptSheet.show');
		await m.click('#csOptSheet label[for=csC]');
		await Promise.all([m.waitForNavigation(), m.click('#csOptSheet .csSheetDone')]);
		if (!/[?&]c=1/.test(m.url())) throw new Error('closing the sheet did not search again: ' + m.url());
		// chip × = the same search without it
		const href = await m.getAttribute('#csChips .csChip:first-child', 'href');
		if (/[?&]w=1/.test(href)) throw new Error('chip link keeps whole word: ' + href);
		await m.setViewportSize({ width: 1200, height: 844 });
		await m.waitForTimeout(300);
		const desk = await m.evaluate(() => ({ back: !document.querySelector('#csOptSheet #csW') && !!document.querySelector('#csForm .card-body #csW'),
			go: !!document.getElementById('csGo').offsetParent, opt: !!document.getElementById('csOptBtn').offsetParent }));
		if (!desk.back || !desk.go || desk.opt) throw new Error('desktop: ' + JSON.stringify(desk));
		if (errs.length) throw new Error('JS errors: ' + errs.join(' | '));
		s.result = { row, desk };
	} finally { await ctx.close(); }
};

T.procedures_search_code = async (s) => {
	// 0.8.1254: the procedures list searches the PHP / JavaScript code only when the box "Code" in the search field is ticked
	const p = s.ga;
	sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_psc'");
	sql("INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_javascript) VALUES ('e2e_psc', 'e2e_psc', 'E2E code search', 'echo \"zqxCodeToken\";', 'var jsOnlyTok = 1;')");
	try {
		const rows = () => p.evaluate(() => [...document.querySelectorAll('.nuListCard tbody tr')].filter(r => /e2e_psc/.test(r.textContent)).length);
		await open(p, 'index.php?p=procedures&q=zqxCodeToken');
		expect(await p.locator('.nuListSearch .nuSearchExtra input[name=qc]').count() === 1, 'no Code box in the search field');
		expect(!(await p.locator('input[name=qc]').isChecked()), 'Code box ticked by default');
		expect(await rows() === 0, 'code found without the box');
		await p.locator('input[name=qc]').check();
		await p.waitForFunction(() => /[?&]qc=1/.test(location.search) && !document.querySelector('.nuLvLoading'), null, { timeout: 15000 });
		await p.waitForFunction(() => [...document.querySelectorAll('.nuListCard tbody tr')].some(r => /e2e_psc/.test(r.textContent)), null, { timeout: 10000 }).catch(() => {});
		expect(await rows() === 1, 'PHP code not found with the box: ' + p.url());
		await open(p, 'index.php?p=procedures&qc=1&q=jsOnlyTok');
		expect(await rows() === 1, 'JavaScript not found with the box');
		const sortHref = await p.locator('.nuListCard thead a[href*="sort="]').first().getAttribute('href');
		expect(/qc=1/.test(sortHref || ''), 'sort link drops the box: ' + sortHref);
	} finally {
		sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_psc'");
	}
};

T.proc_tools_one_row_phone = async (s) => {
	// 0.8.1255: on a 360 px phone the procedure editor's tools, {*} and # stay in one row
	const p = s.ga, vp = p.viewportSize();
	try {
		await p.setViewportSize({ width: 360, height: 780 });
		await open(p, 'index.php?p=procedures&id=new');
		// every button of the toolbar (groups: Insert + #, history, search, tidy & check, view) in one row
		const tops = await p.evaluate(() => [...document.querySelectorAll('.procCodeTools[data-for=sph_php] button')].filter(b => b.offsetParent).map(c => Math.round(c.getBoundingClientRect().top)));
		expect(tops.length >= 10 && tops.every(t => Math.abs(t - tops[0]) <= 2), 'tools wrap: ' + JSON.stringify(tops));
	} finally { await p.setViewportSize(vp); }
};

// 0.8.1260: Global code – full screen of an editor hides the description and the "Runs on" switches above it
T.globalcode_full_hides_hint = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=globalcode&tab=gjs');
	await p.waitForSelector('#scodePane-gjs .nuCtFull', {timeout: 8000});
	const vis = () => p.evaluate(() => { const pn = document.getElementById('scodePane-gjs'); const v = (sel) => { const e = pn.querySelector(sel); return !!e && e.offsetParent !== null; };
		return { hint: v('.scodeHint'), scope: v('.scodeScope'), full: pn.classList.contains('nuCodeFull'), cm: (pn.querySelector('.CodeMirror') || {}).offsetHeight || 0 }; });
	const a = await vis();
	expect(a.hint && a.scope && !a.full, 'before: ' + JSON.stringify(a));
	await p.click('#scodePane-gjs .nuCtFull'); await p.waitForTimeout(300);
	const b = await vis();
	expect(b.full && !b.hint && !b.scope && b.cm > a.cm, 'full screen: ' + JSON.stringify(b));
	await p.click('#scodePane-gjs .nuCtFull'); await p.waitForTimeout(300);
	const c = await vis();
	expect(!c.full && c.hint && c.scope, 'after: ' + JSON.stringify(c));
};

// suggestions of names in the code editors (0.8.1336): ?p=codenames (tables / columns only for users who may write SQL, fields of a form);
// PHP strings: tables after FROM, columns after "alias.", "#" variables; the list pane's SQL editor: columns of the form's table
T.code_names = async (s) => {
	const p = s.ga;
	let r = await p.request.get(BASE + 'index.php?p=codenames&f=samplecustomer');
	const j = await r.json();
	expect(r.status() === 200 && j.tables.some(t => t[0] === 'zzzzsys_user') && (j.cols.zzzzsys_user || []).some(c => c[0] === 'sus_login_name'), 'tables and columns');
	expect(j.hash.some(h => h[0] === 'USER_ID') && j.fields.some(f => f[0] === 'cus_name') && j.table === 'sample_customer', 'hash variables, fields and table of the form: ' + j.table);
	const id = sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE RIGHT(zzzzsys_php_id, 3) NOT IN ('_BB', '_BE', '_BS', '_AS', '_BD', '_AD', '_AB') ORDER BY sph_code LIMIT 1");
	await open(p, 'index.php?p=procedures&id=' + encodeURIComponent(id)); await p.waitForTimeout(400);
	const val = () => p.evaluate(() => document.querySelector('#sph_php').nextElementSibling.CodeMirror.getValue());
	const hints = () => p.evaluate(() => [...document.querySelectorAll('.CodeMirror-hints li .nuHintName')].map(e => e.textContent));
	await p.evaluate(() => { const c = document.querySelector('#sph_php').nextElementSibling.CodeMirror; c.setValue('$s = "";'); c.setCursor(0, 6); c.focus(); });
	await p.keyboard.type('SELECT * FROM zzzzsys_us', { delay: 15 }); await p.waitForTimeout(600);
	let h = await hints();
	expect(h.includes('zzzzsys_user') && !h.some(x => x.startsWith('sus_')), 'tables after FROM: ' + h.slice(0, 5));
	await p.keyboard.press('Enter'); await p.waitForTimeout(200);
	expect((await val()).includes('FROM zzzzsys_user"'), 'table inserted: ' + await val());
	await p.keyboard.type(' u WHERE u.sus_lo', { delay: 15 }); await p.waitForTimeout(600);
	h = await hints();
	expect(h[0] === 'sus_login_name', 'columns after the alias: ' + h.slice(0, 5));
	await p.keyboard.press('Enter');
	await p.keyboard.type(' = #USER_', { delay: 15 }); await p.waitForTimeout(600);
	h = await hints();
	expect(h.includes('#USER_ID#'), '# variables: ' + h.slice(0, 5));
	await p.evaluate(() => { const items = [...document.querySelectorAll('.CodeMirror-hints li')]; items.find(li => li.textContent.includes('#USER_ID#')).click(); });
	await p.waitForTimeout(200);
	expect((await val()).includes('u.sus_login_name = #USER_ID#"'), 'inserted with the closing #: ' + await val());
	// outside a string: no table names (the function suggestions stay)
	await p.evaluate(() => { const c = document.querySelector('#sph_php').nextElementSibling.CodeMirror; c.setValue(''); c.focus(); });
	await p.keyboard.type('zzzzsys_us', { delay: 15 }); await p.waitForTimeout(500);
	expect(await p.locator('.CodeMirror-hints').count() === 0, 'no table names in PHP code');
	// the list pane's SQL editor: the form's columns
	await open(p, 'index.php?p=form&f=samplecustomer');
	await p.keyboard.press('Control+Shift+B'); await p.waitForSelector('#nuBsqPane .cm-content', { timeout: 8000 }); await p.waitForTimeout(300);
	await p.click('#nuBsqPane .cm-content'); await p.keyboard.press('Control+End');
	await p.keyboard.type(' cus_na', { delay: 15 }); await p.waitForTimeout(600);
	h = await hints();
	expect(h.includes('cus_name'), 'SQL editor: columns of the form\'s table: ' + h.slice(0, 5));
	await p.keyboard.press('Escape');
	// a user without the right gets no tables
	const u = await login('sample_anna', UPW);
	r = await u.request.get(BASE + 'index.php?p=codenames&f=samplecustomer');
	const j2 = await r.json();
	expect(r.status() === 200 && j2.tables.length === 0 && j2.fields.length === 0 && j2.hash.length > 5, 'without the right: only # variables');
	await u.context().close();
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// code search: Replace is an icon button in the search row next to Options / Filters (phones), with its name from 576 px
T.code_replace_button = async (s) => {
	const p = s.ga;
	const vp = p.viewportSize();
	try {
		await p.setViewportSize({width: 390, height: 800});
		await open(p, 'index.php?p=codesearch');
		const ph = await p.evaluate(() => {
			const b = document.getElementById('csRpToggle').getBoundingClientRect(), q = document.getElementById('csQ').getBoundingClientRect(),
				f = document.getElementById('csFiltBtn').getBoundingClientRect();
			return {sameRow: Math.abs(b.top - f.top) < 2 && b.top < q.bottom, w: Math.round(b.width), h: Math.round(b.height), right: b.right <= innerWidth,
				text: getComputedStyle(document.querySelector('#csRpToggle span')).display, old: !!document.querySelector('.csFiltersBtn + .csRpBtn, .d-flex.mt-2 > .csRpBtn')};
		});
		expect(ph.sameRow && ph.w === 44 && ph.h === 44 && ph.right && ph.text === 'none' && !ph.old, 'phone: icon button in the search row ' + JSON.stringify(ph));
		await p.click('#csRpToggle');
		await p.waitForSelector('#csRp:visible');
		expect(await p.getAttribute('#csRpToggle', 'aria-expanded') === 'true', 'open state');
		await p.setViewportSize({width: 1280, height: 800});
		await open(p, 'index.php?p=codesearch');
		const wide = await p.evaluate(() => getComputedStyle(document.querySelector('#csRpToggle span')).display !== 'none');
		expect(wide, 'wide screens show the name');
	} finally {
		await p.setViewportSize(vp);
	}
};

// code editor toolbar in groups by purpose: Insert + # · undo / redo · find / replace · comment / format / check · copy / invisibles / full screen
T.code_toolbar_groups = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=procedures&id=new');
	const g = await p.evaluate(() => {
		const tb = document.querySelector('.procCodeTools[data-for=sph_php] .nuCodeTools');
		return tb ? [...tb.children].filter(x => x.offsetParent).map(x => [...x.querySelectorAll('button')].map(b => b.className.match(/nuCt\w+|nuHashVarsBtn/) ? b.className.match(/nuCt\w+|nuHashVarsBtn/)[0] : '?').join(' ')) : null;
	});
	expect(g && g[0] === 'nuCtInsert nuHashVarsBtn' && g[1] === 'nuCtUndo nuCtRedo' && g[2] === 'nuCtFind nuCtReplace'
		&& /^nuCtComment nuCtFormat nuCtCheck$/.test(g[3]) && /nuCtInvisibles nuCtFull$/.test(g[4]), 'groups: ' + JSON.stringify(g));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};
