// e2e tests: start page, navigation, branding, settings – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// 0.8.701: languages of the installation – Settings → Languages; only they are offered (language menu, user form, ?p=lang), an added
// language gets its welcome template, a removed one loses it; at least one stays
// variants of English / German (0.8.782): Settings → General → Languages offers UK / Australia / US and DE / CH; only the ticked
// ones are in the language menu and the user form; a variant not offered falls back to the default (UK / DE)
T.lang_variants = async (s) => {
	const p = s.ga;
	const before = sql("SELECT IFNULL(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'language_variants'");
	const hadLangs = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'languages'") !== '0';   // Save also stores the languages
	try {
		await open(p, 'index.php?p=settings&s=general');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=settings&s=general'); }
		expect(await p.locator('#setLangVar_en_GB').count() === 1 && await p.locator('#setLangVar_de_CH').count() === 1, 'variant boxes shown');
		await p.check('#setLangVar_en_GB'); await p.check('#setLangVar_en_US'); await p.uncheck('#setLangVar_en_AU');
		await p.check('#setLangVar_de_DE'); await p.uncheck('#setLangVar_de_CH');
		await p.click('#setLangVar_de_DE');                                        // the last one of a language stays
		expect(await p.isChecked('#setLangVar_de_DE'), 'last variant cannot be unticked');
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#setLangsSave')]);
		const v = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'language_variants'"));
		expect(JSON.stringify(v.en) === '["en-GB","en-US"]' && JSON.stringify(v.de) === '["de-DE"]', 'stored: ' + JSON.stringify(v));
		const menu = await p.$$eval('a[href*="p=lang&l="]', a => [...new Set(a.map(x => new URL(x.href).searchParams.get('l')))]);
		expect(menu.includes('en-GB') && menu.includes('en-US') && !menu.includes('en-AU') && !menu.includes('de-CH') && (menu.includes('de-DE') || menu.includes('de')), 'language menu: ' + menu.join(','));
		await open(p, 'index.php?p=user&id=' + sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'"));
		const opts = await p.$$eval('#sus_language option', o => o.map(x => x.value));
		expect(opts.includes('English|en-US') && !opts.includes('English|en-AU') && !opts.includes('German|de-CH'), 'user form: ' + opts.join(','));
		await open(p, 'index.php?p=lang&l=de-CH&r=p%3Dhome');                       // not offered → German (DE)
		expect(await p.evaluate(() => document.documentElement.lang) === 'de-DE', 'de-CH refused: ' + await p.evaluate(() => document.documentElement.lang));
		await open(p, 'index.php?p=lang&l=en-US&r=p%3Dhome');
		expect(await p.evaluate(() => document.documentElement.lang) === 'en-US', 'en-US chosen');
	} finally {
		if (before === '') sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'language_variants'");
		else sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + before.replace(/'/g, "''") + "' WHERE zzzzsys_nunext_setting_id = 'language_variants'");
		if (!hadLangs) sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'languages'");
		await open(p, 'index.php?p=lang&l=en-GB&r=p%3Dhome');
	}
};

T.languages = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('welcome_langs', 'languages')") !== '0') { s.skip = 'languages already limited'; return; }
	const dump = path.join(os.tmpdir(), 'nunext-e2e-langs.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_email_template > ' + dump);
	await open(p, 'index.php?p=home');
	const langBefore = await p.evaluate(() => document.documentElement.lang) || 'en-GB';
	try {
		await open(p, 'index.php?p=settings&s=general');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=settings&s=general'); }
		expect(await p.locator('#setLangs input[name="langs[]"]:checked').count() === 11, 'all languages before');
		for (const c of ['it', 'es', 'pt', 'nl', 'pl', 'ru', 'zh', 'ar', 'fr']) await p.uncheck('#setLang_' + c);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#setLangsSave')]);
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'languages'") === '["de","en"]', 'stored');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_email_template WHERE zzzzsys_email_template_id IN ('nx_welcome_fr', 'nx_welcome_it')") === '0', 'templates of removed languages gone');
		const menu = await p.$$eval('a[href*="p=lang&l="], a[href*="p=lang&amp;l="]', a => [...new Set(a.map(x => new URL(x.href).searchParams.get('l')))]);
		expect(menu.length > 0 && menu.every(l => /^(de|en)/.test(l)), 'language menu: ' + menu.join(','));
		await open(p, 'index.php?p=user&id=' + sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'"));
		const opts = await p.$$eval('#sus_language option', o => o.map(x => x.value));
		expect(!opts.some(v => /^(French|Italian|Arabic)/.test(v)), 'user form languages: ' + opts.join(','));
		// a language that is not installed cannot be chosen
		await open(p, 'index.php?p=lang&l=fr&r=p%3Dsettings%26s%3Dgeneral');
		expect(['de', 'en'].includes(await p.evaluate(() => document.documentElement.lang.slice(0, 2))), 'fr refused: ' + await p.evaluate(() => document.documentElement.lang));
		// add French again: its welcome template comes back
		await open(p, 'index.php?p=settings&s=general');
		await p.check('#setLang_fr');
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#setLangsSave')]);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_email_template WHERE zzzzsys_email_template_id = 'nx_welcome_fr'") === '1', 'template of an added language');
		// none ticked: refused
		for (const c of ['de', 'en', 'fr']) await p.uncheck('#setLang_' + c);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#setLangsSave')]);
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'languages'") === '["de","en","fr"]', 'none refused');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('welcome_langs', 'languages')");
		execSync('mysql ' + DB, { input: fs.readFileSync(dump) }); fs.unlinkSync(dump);
		await open(p, 'index.php?p=lang&l=' + encodeURIComponent(langBefore) + '&r=p%3Dhome');
	}
};

// 0.8.804: page numbers – "P100" + Enter in the start page search and in Ctrl+K opens the page; a tab suffix; an unknown number comes back
T.page_numbers = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home');
	await p.fill('#nuHomeSearch', 'P110');
	await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.press('#nuHomeSearch', 'Enter')]);
	expect(/p=access\b/.test(p.url()), 'start page search: P110 did not open the access levels: ' + p.url());
	await open(p, 'index.php?p=users');
	await p.keyboard.press('Control+k'); await p.waitForSelector('#nuPaletteInput', { state: 'visible' });
	await p.fill('#nuPaletteInput', 'p 700.2');
	await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.press('#nuPaletteInput', 'Enter')]);
	expect(/p=update/.test(p.url()) && /tab=backups/.test(p.url()), 'Ctrl+K: P700.2 did not open Update → Backups: ' + p.url());
	await open(p, 'index.php?p=users');
	await p.keyboard.press('Control+k'); await p.waitForSelector('#nuPaletteInput', { state: 'visible' });
	await p.fill('#nuPaletteInput', 'P999');
	await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.press('#nuPaletteInput', 'Enter')]);
	expect(/p=users/.test(p.url()) && /P999/.test(await p.textContent('body')), 'unknown number: not back with a message: ' + p.url());
	await open(p, 'index.php?p=home');
	await p.fill('#nuHomeSearch', 'P001'); await p.press('#nuHomeSearch', 'Enter'); await p.waitForLoadState('load');
	expect(/p=home/.test(p.url()), 'P001: ' + p.url());
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.home_search_list = async (s) => {                     // 0.8.723: start page search = one list (tiles first, then pages / forms / procedures …), kinds filter
	const p = s.ga;
	await open(p, 'index.php?p=home'); await p.evaluate(() => { try { localStorage.removeItem('nunext-home-search-kinds'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=home');
	await p.fill('#nuHomeSearch', 'update'); await p.waitForTimeout(800);
	const keys = await p.$$eval('#nuHomeSearchMore [data-nu-res]', (a) => a.map((x) => x.getAttribute('data-nu-res')));
	expect(keys[0] === 't:update' && await p.locator('#nuHomeView').isHidden() && !(await p.locator('#nuHomeSearchMore h2').count()), 'tile not first / view shown: ' + keys.join(','));
	// customers: the sample form (f:) comes after the tiles; without forms it is gone
	await p.fill('#nuHomeSearch', 'customer'); await p.waitForTimeout(500);
	let k2 = await p.$$eval('#nuHomeSearchMore [data-nu-res]', (a) => a.map((x) => x.getAttribute('data-nu-res')));
	const lastTile = k2.map((k) => k.startsWith('t:')).lastIndexOf(true), firstForm = k2.findIndex((k) => k.startsWith('f:'));
	expect(firstForm >= 0 && (lastTile < 0 || lastTile < firstForm || k2.slice(0, firstForm).every((k) => k.startsWith('t:'))), 'order: ' + k2.join(','));
	await p.click('#nuHomeSearchKinds'); await p.waitForTimeout(200);
	await p.uncheck('#nuHomeSk_forms'); await p.waitForTimeout(200);
	k2 = await p.$$eval('#nuHomeSearchMore [data-nu-res]', (a) => a.map((x) => x.getAttribute('data-nu-res')));
	expect(!k2.some((k) => k.startsWith('f:')) && await p.locator('#nuHomeSearchKinds.nuHomeSkOn').count() === 1, 'forms still listed: ' + k2.join(','));
	await open(p, 'index.php?p=home');
	expect(!(await p.isChecked('#nuHomeSk_forms')), 'kinds not kept');
	await p.click('#nuHomeSearchKinds'); await p.click('#nuHomeSkAll'); await p.waitForTimeout(150);
	expect(await p.isChecked('#nuHomeSk_forms') && await p.locator('#nuHomeSearchKinds.nuHomeSkOn').count() === 0, 'include everything');
	await p.keyboard.press('Escape');
	// ↓ / ↑ move the selection (first one selected), Enter opens the selected one (0.8.724)
	await p.fill('#nuHomeSearch', 'update');  await p.waitForTimeout(400);
	const sel = () => p.$eval('#nuHomeSearchMore [data-nu-res].active', (a) => a.getAttribute('data-nu-res'));
	expect(await sel() === 't:update', 'first result not selected');
	await p.press('#nuHomeSearch', 'ArrowDown'); const second = await sel();
	await p.press('#nuHomeSearch', 'ArrowUp'); expect(await sel() === 't:update' && second !== 't:update', 'arrows: ' + second);
	await p.press('#nuHomeSearch', 'ArrowUp'); expect(await sel() === 't:update', 'up past the first');
	await Promise.all([p.waitForURL(/p=update/), p.press('#nuHomeSearch', 'Enter')]);
	await open(p, 'index.php?p=home');
	await p.fill('#nuHomeSearch', 'update');  await p.waitForTimeout(400);
	await p.press('#nuHomeSearch', 'ArrowDown');
	const target = await p.$eval('#nuHomeSearchMore [data-nu-res].active', (a) => a.getAttribute('href'));
	await Promise.all([p.waitForURL((u) => u.href.includes(target.replace(/^.*\?/, ''))), p.press('#nuHomeSearch', 'Enter')]);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.home_search_ctx = async (s) => {                     // 0.8.855: right-click / long press on a tile result of the start page search = the tile's menu
	const p = s.ga;
	await open(p, 'index.php?p=home');
	const wasFav = await p.$eval('[data-nu-tile="update"]', (a) => a.getAttribute('data-nu-fav') === '1');
	await p.fill('#nuHomeSearch', 'update'); await p.waitForTimeout(600);
	await p.click('#nuHomeSearchMore [data-nu-res="t:update"]', { button: 'right' });
	await p.waitForTimeout(200);
	expect(await p.locator('.nuHomeCtx').count() === 1, 'no menu on a tile result');
	const items = await p.$$eval('.nuHomeCtx .dropdown-item', (a) => a.map((x) => x.textContent.trim()));
	expect(items.length >= 1, 'menu without entries');
	await Promise.all([p.waitForNavigation(), p.click('.nuHomeCtx .dropdown-item >> nth=0')]);
	await p.waitForTimeout(800);
	expect(await p.inputValue('#nuHomeSearch') === 'update' && await p.locator('#nuHomeSearchMore [data-nu-res="t:update"]').count() === 1, 'search not kept after the reload');
	const isFav = await p.$eval('[data-nu-tile="update"]', (a) => a.getAttribute('data-nu-fav') === '1');
	expect(isFav !== wasFav, 'favourite not toggled');
	// back as it was
	await p.click('#nuHomeSearchMore [data-nu-res="t:update"]', { button: 'right' }); await p.waitForTimeout(200);
	await Promise.all([p.waitForNavigation(), p.click('.nuHomeCtx .dropdown-item >> nth=0')]);
	await p.waitForTimeout(500);
	expect(await p.$eval('[data-nu-tile="update"]', (a) => a.getAttribute('data-nu-fav') === '1') === wasFav, 'favourite not back');
	// a result that is no tile: no own menu
	await p.fill('#nuHomeSearch', 'customer'); await p.waitForTimeout(600);
	const f = p.locator('#nuHomeSearchMore [data-nu-res^="f:"]').first();
	if (await f.count()) {
		await f.click({ button: 'right' }); await p.waitForTimeout(200);
		expect(await p.locator('.nuHomeCtx').count() === 0, 'menu on a form result');
		await p.keyboard.press('Escape');
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.nav_crumbs_noflash = async (s) => {                 // 0.8.836: with the compact bar the page's breadcrumb never shows below it (hidden by CSS before nuapp.js runs)
	const had = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'") !== '0';
	if (had) { s.skip = 'branding set'; return; }
	sql(`INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('branding', '{"logo_show":"login"}')`);
	const ctx = await browser.newContext({ viewport: { width: 1300, height: 800 }, javaScriptEnabled: false, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		for (const u of ['index.php?p=form&f=samplecustomer', 'index.php?p=form&f=samplecustomer&r=sample_cus_01']) {
			await p.goto(BASE + u);
			const r = await p.evaluate(() => { const n = document.querySelector('#main nav[aria-label="breadcrumb"]'); return { slot: !!document.getElementById('nuNavCrumbs'), has: !!n, shown: n ? getComputedStyle(n).display !== 'none' : false }; });
			expect(r.slot && r.has && !r.shown, u + ': breadcrumb shown in the page before the script: ' + JSON.stringify(r));
		}
		await p.setViewportSize({ width: 390, height: 800 });
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer&r=sample_cus_01');
		// phones (0.8.1003, mockup B): the breadcrumb is replaced by the back link "‹ <list>" above the title
		expect(await p.evaluate(() => { const b = document.querySelector('.nuPhBack'); return !!b && getComputedStyle(b).display !== 'none'; }), 'phone: no back link instead of the breadcrumb');
	} finally { await ctx.close(); sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'"); }
};

// options menu (0.8.446): add, print, search, form info, form properties and object list only as keyboard shortcuts – they still work
T.options_menu = async (s) => {
	const p = s.ga;
	const items = () => p.$$eval('.nuOptionsList [data-nu-shortcut]', b => b.map(x => x.textContent.trim()));
	const keys = () => p.$$eval('.nuOptionsList [data-nu-shortcut]', b => b.map(x => x.getAttribute('data-nu-shortcut')));
	await open(p, 'index.php?p=form&f=samplecustomer');
	await p.click('#nuOptions'); await p.waitForTimeout(200);
	let k = await keys();
	expect(!k.includes('a') && !k.includes('p') && !k.includes('s') && !k.includes('f') && !k.includes('o') && !k.includes('m') && !k.includes('r') && !k.includes('e') && !k.includes('j'), 'browse menu: ' + await items());
	// 0.8.818: Browse SQL (lists only) → design mode, Code, the browse SQL; no Refresh / Database / Logged-in users / File Manager
	const its = await items();
	// 0.8.1111: editing the list is in Design ▾ only – no Browse SQL / PHP Before Browse here
	expect(!its.some((x) => /Browse SQL|Before Browse/.test(x)) && !k.includes('b') && !its.includes('File Manager') && !its.includes('Refresh'), 'browse menu items: ' + its);
	expect(!its.some((x) => /^Version info/.test(x)) && its.some((x) => /^Hash variables/.test(x)), 'Version info is no menu entry (0.8.1109): ' + its);
	// 0.8.1112: the manual is called Documentation here as in the user menu; "Help" is only the form's own help page
	expect(its.some((x) => /^Documentation/.test(x)) && !its.some((x) => /^Help/.test(x)), 'the manual entry is called Documentation: ' + its);
	expect(await p.locator('#nuMenuDocs').count() === 1 && await p.locator('#nuMenuUserHelp').count() === 0, 'user menu of the globeadmin: Documentation, no Help');
	await p.keyboard.press('Escape');
	const before = await p.evaluate(() => document.querySelectorAll('.jconfirm, .modal.show, .nuMessage').length);
	await p.keyboard.press('Control+Shift+V'); await p.waitForTimeout(400);                      // 0.8.1110: no shortcut either
	expect(await p.evaluate(() => document.querySelectorAll('.jconfirm, .modal.show, .nuMessage').length) === before && !/PHP \d+\.\d+\.\d+ · /.test(await p.evaluate(() => document.body.innerText)), 'Ctrl+Shift+V still shows the version');
	await p.keyboard.press('Escape'); await p.waitForTimeout(300);
	await p.evaluate(() => { document.querySelectorAll('.jconfirm .jconfirm-buttons button, .nuMessage .btn-close, .modal.show [data-bs-dismiss]').forEach((b) => b.click()); });
	await p.waitForTimeout(300);
	await Promise.all([p.waitForNavigation(), p.keyboard.press('Control+Shift+A')]);
	expect(/[?&]r=-1\b/.test(p.url()), 'Ctrl+Shift+A still adds: ' + p.url());
	const id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + encodeURIComponent(id));
	await p.click('#nuOptions'); await p.waitForTimeout(200);
	k = await keys();
	expect(!k.includes('m') && !k.includes('f') && !k.includes('o') && !k.includes('a') && !k.includes('s') && !k.includes('y') && !k.includes('c') && !k.includes('l') && !k.includes('r'), 'edit menu: ' + await items());
	expect(!(await items()).includes('Browse SQL'), 'Browse SQL in the edit menu');
	// 0.8.517: no "Arrange objects", Save, Delete, Clone in the menu (buttons on the page do the same; the shortcuts stay) – the form
	// builder button opens a new tab
	if (await p.locator('#nuFormBuilderBtn').count()) expect(await p.getAttribute('#nuFormBuilderBtn', 'target') === '_blank', 'form builder button not in a new tab');
	await p.keyboard.press('Escape'); await p.waitForTimeout(200);
	await p.keyboard.press('Control+Shift+M'); await p.waitForTimeout(300);
	expect(await p.isVisible('#nuFormInfo'), 'Ctrl+Shift+M opens the form info panel');
	await open(p, 'index.php?p=form&f=samplecustomer');
	await p.keyboard.press('Control+Shift+B');                                                    // the shortcut still opens the pane
	await p.waitForSelector('#nuBsqPane .CodeMirror');
	expect(/SELECT/i.test(await p.locator('#nuBsqPane .CodeMirror').first().textContent()), 'Ctrl+Shift+B did not open the pane');
	await p.click('#nuBsqPane .nuBsqClose');
	await p.click('#nuDesignMore'); await p.waitForTimeout(200);
	expect(/Ctrl\+Shift\+B/.test(await p.textContent('#nuBrowseSqlEdit')) && await p.isVisible('#nuBrowsePhpEdit'), 'Design ▾ has the entries');
	await p.keyboard.press('Escape');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
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

// 0.8.1203: start page per access level – Tile page (default) or Home form
T.start_page_form = async (s) => {
	const p = s.ga;
	const home0 = sql("SELECT IFNULL(sal_zzzzsys_form_id, '') FROM zzzzsys_access WHERE zzzzsys_access_id = 'sample_viewer'");
	const set0 = sql("SELECT IFNULL(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'");
	const form = sql("SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'sample_viewer' AND slf_zzzzsys_form_id LIKE 'sample%' AND slf_zzzzsys_form_id IN (SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_type <> 'subform') ORDER BY 1 LIMIT 1");
	if (!form) { s.skip = 'sample application missing'; return; }
	const anna = await login('sample_anna', UPW);
	try {
		// default: the tile page, no Tile page entry in the user menu
		await open(anna, 'index.php?p=home');
		expect(/p=home/.test(anna.url()) && await anna.locator('#nuMenuTiles').count() === 0, 'default is not the tile page: ' + anna.url());
		// the tab Start page: the choice, the Home form and a read-only preview of the level's tile page with a link to its editor
		await open(p, 'index.php?p=accesslevel&id=sample_viewer');
		expect(await p.locator('#accessTabGeneral #accStartPage').count() === 0 && await p.locator('#accessTabStart #accStartPage').count() === 1 && await p.locator('#accessTabStart #sal_zzzzsys_form_id').count() === 1, 'choice and Home form on the Start page tab');
		await p.click('#accessTabStartBtn'); await p.waitForSelector('#accStartPreview');
		const pv = await p.evaluate(() => ({ src: document.getElementById('accStartPreview').getAttribute('data-source'), tiles: document.querySelectorAll('#accStartPreview .accPvTile').length, edit: (document.getElementById('accStartEdit') || {}).href || '', note: document.getElementById('accStartFormNote').hidden }));
		expect(['auto', 'default', 'level'].includes(pv.src) && pv.tiles >= 1 && /p=home&for=sample_viewer/.test(pv.edit) && pv.note, 'preview: ' + JSON.stringify(pv));
		expect(await p.isChecked('#accStartTiles'), 'Tile page not chosen by default');
		// 0.8.1269 (a tester): Home form chosen as start page but no Home form = the tile page, no error (it left no way back to the tiles)
		await p.evaluate(() => { const e = document.getElementById('sal_zzzzsys_form_id'); if (e.tomselect) e.tomselect.clear(); else e.value = ''; });
		await p.click('label[for=accStartForm]');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		expect(await p.locator('#sal_zzzzsys_form_id.is-invalid, .ts-wrapper.is-invalid').count() === 0, 'error without a Home form');
		await open(p, 'index.php?p=accesslevel&id=sample_viewer&tab=start');
		expect(await p.isChecked('#accStartTiles'), 'no Home form did not save as the tile page');
		// clearing the Home form switches the choice back to Tile page
		await p.evaluate((f) => { const e = document.getElementById('sal_zzzzsys_form_id'); if (e.tomselect) e.tomselect.setValue(f); else e.value = f; }, form);
		await p.click('label[for=accStartForm]');
		await p.evaluate(() => { const e = document.getElementById('sal_zzzzsys_form_id'); if (e.tomselect) e.tomselect.clear(); else { e.value = ''; e.dispatchEvent(new Event('change', {bubbles: true})); } });
		expect(await p.isChecked('#accStartTiles'), 'clearing the Home form left Home form chosen');
		// with a Home form
		expect(await p.locator('#accessTabStart.active').count() === 1, 'the error opens the Start page tab');
		await open(p, 'index.php?p=accesslevel&id=sample_viewer&tab=start');
		await p.evaluate((f) => { const e = document.getElementById('sal_zzzzsys_form_id'); if (e.tomselect) e.tomselect.setValue(f); else e.value = f; }, form);
		await p.click('label[for=accStartForm]');
		await Promise.all([p.waitForLoadState('load'), p.click('[data-nu-save]')]);
		expect(JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'")).levels.sample_viewer === 'form', 'setting not stored');
		expect(await p.isChecked('#accStartForm') && await p.locator('#accessTabStart.active').count() === 1 && !(await p.evaluate(() => document.getElementById('accStartFormNote').hidden)), 'Home form not kept after save / tab not kept');
		// the user opens the form, the tiles stay in the user menu
		await anna.goto(BASE + 'index.php?p=home'); await anna.waitForLoadState('networkidle');
		expect(anna.url().includes('p=form') && anna.url().includes('f=' + form), 'Home form not opened: ' + anna.url());
		expect(await anna.locator('#nuMenuTiles').count() === 1, 'no Tile page entry in the user menu');
		// 0.8.1206: no "Home" breadcrumb that only leads back to the form; other forms start with the Home form
		expect(await anna.locator('#nuBreadcrumbHolder a[href*="p=home"]').count() === 0, 'Home breadcrumb on the Home form');
		const other = sql(`SELECT slf_zzzzsys_form_id FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'sample_viewer' AND slf_zzzzsys_form_id LIKE 'sample%' AND slf_zzzzsys_form_id <> '${form}' AND slf_zzzzsys_form_id IN (SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_type IN ('browse', 'browseedit')) ORDER BY 1 LIMIT 1`);
		if (other) {
			await open(anna, 'index.php?p=form&f=' + other);
			const c0 = await anna.locator('#nuBreadcrumb0').getAttribute('href');
			expect(c0 && c0.includes('f=' + form) && !c0.includes('p=home'), 'first breadcrumb is not the Home form: ' + c0);
		}
		await anna.goto(BASE + 'index.php?p=home&tiles=1'); await anna.waitForLoadState('networkidle');
		expect(/p=home/.test(anna.url()) && await anna.locator('.nuHomeTile, .nuHomeChip, .nuHomeEmpty').count() >= 1, 'tile page with tiles=1: ' + anna.url());
		// the editor of the tile page says so
		await open(p, 'index.php?p=home&for=sample_viewer');
		expect(await p.locator('#nuHomeForStartForm').count() === 1, 'no note in the tile page editor');
		// a form the user may not open: the tile page again
		sql("UPDATE zzzzsys_access SET sal_zzzzsys_form_id = 'nuuser' WHERE zzzzsys_access_id = 'sample_viewer'");
		await anna.goto(BASE + 'index.php?p=home'); await anna.waitForLoadState('networkidle');
		expect(/p=home/.test(anna.url()), 'admin form opened as Home form: ' + anna.url());
		// 0.8.1204: a Home form without a row in the form rights opens all the same (as nuBuilder Forte), without Save / Add
		sql(`DROP TABLE IF EXISTS e2e_af_keep; CREATE TABLE e2e_af_keep AS SELECT * FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'sample_viewer' AND slf_zzzzsys_form_id = '${form}';
			DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = 'sample_viewer' AND slf_zzzzsys_form_id = '${form}'; UPDATE zzzzsys_access SET sal_zzzzsys_form_id = '${form}' WHERE zzzzsys_access_id = 'sample_viewer'`);
		try {
			const r = await anna.goto(BASE + 'index.php?p=home'); await anna.waitForLoadState('networkidle');
			expect(anna.url().includes('f=' + form) && r.status() === 200, 'Home form without a rights row not opened: ' + anna.url() + ' ' + r.status());
			expect(await anna.locator('#nuAddButton:visible, #nuSaveButton:visible').count() === 0, 'buttons on a Home form without rights');
		} finally {
			sql("INSERT INTO zzzzsys_access_form SELECT * FROM e2e_af_keep; DROP TABLE e2e_af_keep");
		}
		// back to Tile page removes the entry
		sql(`UPDATE zzzzsys_access SET sal_zzzzsys_form_id = '${form}' WHERE zzzzsys_access_id = 'sample_viewer'`);
		await open(p, 'index.php?p=accesslevel&id=sample_viewer&tab=start');
		await p.click('label[for=accStartTiles]');
		await Promise.all([p.waitForLoadState('load'), p.click('[data-nu-save]')]);
		expect(!JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'")).levels.sample_viewer, 'entry not removed');
	} finally {
		sql(`UPDATE zzzzsys_access SET sal_zzzzsys_form_id = ${home0 === '' ? 'NULL' : "'" + home0 + "'"} WHERE zzzzsys_access_id = 'sample_viewer'`);
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'start_page'");
		if (set0) sql(`INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('start_page', '${set0.replace(/'/g, "''")}')`);
		await anna.context().close();
	}
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

// 0.8.637: phones – pin of the ☰ menu keeps it open on the next page; unpinned it is closed again
// 0.8.706: compact top bar – Branding → Show logo "only on the sign-in pages": on a phone the icons stay in the bar, no ☰ toggler and
// no pin, the user menu's button is the ☰; Home = a button at the very left of the bar (0.8.707, every width)
T.add_btn_phone_icon = async (s) => {
	// 0.8.994: "+ Add" of form lists and system lists shows only its icon below 576 px; the text is the tooltip and accessible name
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	const look = (sel) => p.$eval(sel, (b) => ({ w: b.offsetWidth, fs: getComputedStyle(b).fontSize, name: b.getAttribute('aria-label'), title: b.getAttribute('title') }));
	try {
		for (const [url, sel] of [['index.php?p=form&f=samplecustomer', '#nuAddButton'], ['index.php?p=users', '.nuAddBtn']]) {
			await p.setViewportSize({ width: 390, height: 800 });
			await p.goto(BASE + url); await p.waitForTimeout(300);
			const ph = await look(sel);
			expect(ph.w <= 50 && ph.fs === '0px' && ph.name && ph.title, url + ' phone: ' + JSON.stringify(ph));
			await p.setViewportSize({ width: 1200, height: 800 }); await p.waitForTimeout(200);
			const pc = await look(sel);
			expect(pc.w > 60 && pc.fs !== '0px', url + ' desktop: ' + JSON.stringify(pc));
		}
	} finally {
		await ctx.close();
	}
};

T.phone_form_header = async (s) => {
	// 0.8.1003 (mockup B): phones – "‹ parent" + title row with plain icon buttons, no breadcrumb / Back, Add next to the search
	const id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	if (!id) { s.skip = 'no sample data'; return; }
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuOptions');
		const l = await p.evaluate(() => { const v = (q) => { const e = document.querySelector(q); return !!e && !!(e.offsetWidth || e.offsetHeight); };
			const t = document.querySelector('.nuPhTitle'), o = document.getElementById('nuOptions');
			return {back: v('.nuPhBack'), title: v('.nuPhTitle'), crumb: v('#nuBreadcrumbHolder'), oldBack: v('#nuBackButton'), add: v('.nuPhAdd'), oldAdd: v('#nuAddButton'),
				row: Math.abs(t.getBoundingClientRect().top + t.offsetHeight / 2 - (o.getBoundingClientRect().top + o.offsetHeight / 2)) < 8,
				ghost: getComputedStyle(o).borderTopColor === 'rgba(0, 0, 0, 0)' || getComputedStyle(o).borderTopColor === 'transparent'}; });
		expect(l.back && l.title && !l.crumb && !l.oldBack && l.add && !l.oldAdd && l.row && l.ghost, 'list header: ' + JSON.stringify(l));
		await Promise.all([p.waitForNavigation(), p.click('.nuPhAdd')]);
		expect(/r=-1/.test(p.url()), 'Add next to the search opens a new record: ' + p.url());
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
		const r = await p.evaluate(() => ({back: document.querySelector('.nuPhBack').getAttribute('href'), title: document.querySelector('.nuPhTitle').textContent.trim()}));
		expect(/f=samplecustomer/.test(r.back) && !/r=/.test(r.back) && r.title !== '', 'record header: ' + JSON.stringify(r));
		await p.evaluate(() => { const f = document.querySelector('#nuEditForm input[data-nu-field]:not([type=hidden]):not([readonly])'); $(f).val($(f).val() + ' ').change(); });
		await p.waitForTimeout(300);
		const red = await p.evaluate(() => { const b = document.getElementById('nuSaveButton'); return {cls: b.classList.contains('nuSaveEdited'), border: getComputedStyle(b).borderTopColor}; });
		expect(red.cls && red.border !== 'rgba(0, 0, 0, 0)', 'edited Save keeps its red frame: ' + JSON.stringify(red));
	} finally { await ctx.close(); }
};

T.phone_back_button = async (s) => {
	// 0.8.1002: shared setting nuPhoneBackButton (own row of zzzzsys_config; here as nuUXOptions line of nuconfig.php) – text / icon / hidden below 576 px
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	// form pages on phones (0.8.1003): the "‹ parent" link .nuPhBack is the Back button – icon = only the chevron
	const look = () => p.evaluate(() => { const b = window.innerWidth < 576 ? document.querySelector('.nuPhBack') : document.getElementById('nuBackButton'); if (!b) return null;
		const sp = b.querySelector('span');
		return {cls: document.documentElement.className, shown: !!(b.offsetWidth || b.offsetHeight), fs: sp ? (sp.offsetWidth > 1 ? '15px' : '0px') : getComputedStyle(b).fontSize,
			label: b.getAttribute('aria-label') || (sp ? sp.textContent : '')}; });
	try {
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('.nuPhBack');
		const a = await look();
		expect(a.shown && a.fs !== '0px', 'default: icon and text ' + JSON.stringify(a));
		for (const [v, want] of [['icon', (x) => x.shown && x.fs === '0px' && x.label !== '' && /nuBack-icon/.test(x.cls)], ['hidden', (x) => !x.shown && /nuBack-hidden/.test(x.cls)]]) {
			fs.writeFileSync(cfg, orig + `\n$nuUXOptions['nuPhoneBackButton'] = '${v}';\n`); await p.waitForTimeout(1500);
			await p.goto(BASE + 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuOptions');
			const x = await look();
			expect(want(x), v + ': ' + JSON.stringify(x));
			await p.goto(BASE + 'index.php?p=users'); await p.waitForTimeout(300);
			const own = await p.evaluate(() => { const b = [...document.querySelectorAll('a.nuPhBackOwn, .nuBackBtn, .btn.nuBtnAct:has(> .bi-arrow-left)')]; return b.length ? b.some(x => !!(x.offsetWidth || x.offsetHeight)) : null; });   // 0.8.1344 (G4): the ‹ link takes the Back button's place
			if (own !== null) expect(v === 'hidden' ? !own : own, 'own pages follow (' + v + ')');
		}
		await p.setViewportSize({ width: 1200, height: 800 }); await p.goto(BASE + 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuBackButton');
		const d = await look();
		expect(!(await p.isVisible('.nuPhBack')) && !(await p.isVisible('.nuPhAdd')), 'desktop: no phone header');
		expect(d.shown && d.fs !== '0px', 'desktop unchanged: ' + JSON.stringify(d));
	} finally { fs.writeFileSync(cfg, orig); await ctx.close(); }
};

// 0.8.1195: the install offer – kept for the menu entry; preventDefault() only on touch screens (Chrome logged "Banner not shown" on every page)
T.pwa_install_prompt = async (s) => {
	const fire = (pg) => pg.evaluate(() => {
		const e = new Event('beforeinstallprompt', {cancelable: true}); let asked = 0;
		e.prompt = () => { asked++; return Promise.resolve(); }; e.userChoice = Promise.resolve({outcome: 'dismissed'});
		window.dispatchEvent(e);
		const li = document.getElementById('nuMenuInstallLi');
		return {prevented: e.defaultPrevented, shown: !!li && !li.hidden && !li.classList.contains('d-none'), ask: () => asked};
	});
	const p = s.ga;
	await open(p, 'index.php?p=home');
	const d = await p.evaluate(() => { const e = new Event('beforeinstallprompt', {cancelable: true}); window.__asked = 0;
		e.prompt = () => { window.__asked++; return Promise.resolve(); }; e.userChoice = Promise.resolve({outcome: 'dismissed'});
		window.dispatchEvent(e); return e.defaultPrevented; });
	expect(d === false, 'desktop: the install offer is not blocked (no console message)');
	await p.evaluate(() => document.getElementById('nuMenuInstall').click());
	expect(await p.evaluate(() => window.__asked) === 1, 'desktop: the menu entry still shows the install prompt');
	const ctx = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, storageState: await p.context().storageState()});
	try {
		const m = await ctx.newPage();
		await m.goto(BASE + 'index.php?p=home');
		const r = await fire(m);
		expect(r.prevented === true, 'touch screen: Chrome\'s own mini bar is still stopped');
	} finally { await ctx.close(); }
};

// 0.8.1197: the edit form's code in a side pane (Design ▾ → Code, Ctrl+Shift+B) – grouped part list, one save for several parts
// 0.8.1202: the access level choice of the start page editor – searchable, never wider than a phone screen (long level names)
T.home_for_select_phone = async (s) => {
	const p = s.ga;
	const id = 'e2e_long_level_name';
	sql("DELETE FROM zzzzsys_access WHERE zzzzsys_access_id = '" + id + "'");
	sql("INSERT INTO zzzzsys_access (zzzzsys_access_id, sal_code, sal_description) VALUES ('" + id + "', 'e2eVeryLongAccessLevelCodeName', 'A very long access level description that is far wider than any phone screen')");
	try {
		await p.setViewportSize({ width: 390, height: 800 });
		await open(p, 'index.php?p=home&for=' + id);
		await p.waitForSelector('.nuHomeForBar .ts-wrapper');
		const w = await p.evaluate(() => { const ts = document.querySelector('.nuHomeForBar .ts-wrapper').getBoundingClientRect(), card = document.querySelector('.nuHomeForBar').getBoundingClientRect();
			return { right: ts.right, card: card.right, page: document.documentElement.scrollWidth, vw: window.innerWidth }; });
		expect(w.right <= w.card + 1 && w.page <= w.vw, 'select wider than the screen: ' + JSON.stringify(w));
		// choosing another level by typing still opens its start page
		await p.click('.nuHomeForBar .ts-control'); await p.keyboard.type('sample_viewer'); await p.waitForTimeout(300);
		await Promise.all([p.waitForURL(/for=sample_viewer/), p.keyboard.press('Enter')]);
	} finally {
		await p.setViewportSize({ width: 1280, height: 900 });
		sql("DELETE FROM zzzzsys_access WHERE zzzzsys_access_id = '" + id + "'");
	}
};

// 0.8.1222: the level select's list opens above the sticky search row below it
T.home_for_select_over_search = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home&for=*');
	await p.waitForSelector('.nuHomeForBar .ts-wrapper');
	await p.click('.nuHomeForBar .ts-control');
	await p.waitForSelector('.ts-dropdown .option');
	const r = await p.evaluate(() => {
		const row = document.querySelector('.nuHomeSearchRow'), dd = document.querySelector('.nuHomeForBar .ts-dropdown') || document.querySelector('.ts-dropdown');
		if (!row || !dd) return { skip: true };
		const a = row.getBoundingClientRect(), b = dd.getBoundingClientRect();
		const y = Math.max(a.top, b.top) + 5, x = Math.min(a.right, b.right) - 20;
		if (y > Math.min(a.bottom, b.bottom)) return { skip: true, a: a.top, b: b.bottom };
		const el = document.elementFromPoint(x, y);
		return { inside: !!(el && el.closest('.ts-dropdown')), el: el ? el.className : '' };
	});
	await p.keyboard.press('Escape');
	expect(r.skip || r.inside, 'the select list lies under the search row: ' + JSON.stringify(r));
	expect(!r.skip, 'list and search row do not overlap – test setup');
};

T.brand_title_phone = async (s) => {
	// 0.8.996: Branding → Title on phones – shown below 576 px instead of the title; empty = no title on phones; saved by the form
	const had = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'") !== '0';
	if (had) { s.skip = 'branding set'; return; }
	const p = s.ga;
	const vp = p.viewportSize();
	const shown = () => p.evaluate(() => [...document.querySelectorAll('.navbar-brand .nu-brand-title')].filter((e) => e.offsetParent !== null).map((e) => e.textContent.trim()).join('|'));
	try {
		await open(p, 'index.php?p=branding');
		await p.fill('#title', 'E2E Long Application Title');
		await p.fill('#title_phone', 'E2E');
		if (!(await p.locator('#show_title').isChecked())) await p.click('label[for="show_title"]');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		const b = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'"));
		expect(b.title_phone === 'E2E' && b.show_title === true, 'saved: ' + JSON.stringify(b));
		await p.setViewportSize({ width: 1200, height: 800 }); await open(p, 'index.php?p=users');
		expect(await shown() === 'E2E Long Application Title', 'desktop title: ' + await shown());
		await p.setViewportSize({ width: 390, height: 800 }); await p.waitForTimeout(200);
		expect(await shown() === 'E2E', 'phone title: ' + await shown());
		// 0.8.997: own switch for phones – off: no title on phones, the desktop keeps its title; and the other way round
		await p.setViewportSize({ width: 1200, height: 800 });
		await open(p, 'index.php?p=branding');
		expect(await p.locator('#show_title_phone').isChecked(), 'phone switch on by default');
		await p.click('label[for="show_title_phone"]');
		await Promise.all([p.waitForNavigation(), p.click('[data-nu-save]')]);
		await open(p, 'index.php?p=users');
		expect(await shown() === 'E2E Long Application Title', 'desktop title with the phone switch off: ' + await shown());
		await p.setViewportSize({ width: 390, height: 800 }); await p.waitForTimeout(200);
		expect(await shown() === '', 'phone switch off still shows: ' + await shown());
		sql(`UPDATE zzzzsys_nunext_setting SET setting_value = JSON_SET(setting_value, '$.show_title', false, '$.show_title_phone', true) WHERE zzzzsys_nunext_setting_id = 'branding'`);
		await open(p, 'index.php?p=users');
		expect(await shown() === 'E2E', 'phone title with the desktop switch off: ' + await shown());
		sql(`UPDATE zzzzsys_nunext_setting SET setting_value = JSON_SET(setting_value, '$.title_phone', '') WHERE zzzzsys_nunext_setting_id = 'branding'`);
		await open(p, 'index.php?p=users');
		expect(await shown() === '', 'empty phone title still shows: ' + await shown());
	} finally {
		if (vp) await p.setViewportSize(vp);
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
	}
};

T.nav_phone_prefs = async (s) => {
	// 0.8.990: compact top bar on a phone with an environment label – theme and language leave the bar for the user menu, the bar fits
	const had = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'") !== '0';
	if (had) { s.skip = 'branding set'; return; }
	sql(`INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('branding', '{"logo_show":"login","env_label":"E2E-TEST"}')`);
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=update');
		const fit = await p.evaluate(() => { const bar = document.querySelector('.nuNavInner'); const u = document.querySelector('.nuUserBtn').getBoundingClientRect();
			return { over: bar.scrollWidth - bar.clientWidth, right: Math.round(u.right), w: innerWidth }; });
		const hidden = await p.evaluate(() => ['.js-theme-icon', '.nuNavIcons [aria-label] .bi-translate'].map((q) => { const e = document.querySelector('.nuNavIcons ' + q.replace('.nuNavIcons ', '')); return !e || e.offsetParent === null; }));
		expect(fit.over <= 0 && fit.right <= fit.w && hidden.every(Boolean), 'bar fits, theme / language hidden: ' + JSON.stringify({ fit, hidden }));
		await p.click('.nuUserBtn'); await p.waitForTimeout(300);
		expect(await p.isVisible('#nuMenuPrefs [data-theme-set="dark"]'), 'theme in the user menu');
		await p.click('#nuMenuPrefs [data-theme-set="dark"]');
		expect(await p.evaluate(() => document.documentElement.getAttribute('data-bs-theme')) === 'dark' && await p.isVisible('#nuMenuPrefs'), 'theme set, menu stays open');
		if (await p.locator('#nuMenuLang').count()) {
			const other = await p.$eval('#nuMenuLang', (sel) => [...sel.options].find((o) => !o.selected).value);
			await Promise.all([p.waitForNavigation(), p.selectOption('#nuMenuLang', other)]);
			const hl = await p.evaluate(() => document.documentElement.lang);
			expect(/p=update/.test(p.url()) && hl.slice(0, 2) === other.slice(0, 2), 'language changed: ' + p.url() + ' ' + hl + ' ' + other);
			// with a referer of the site, as a click: the globeadmin's choice is stored (lang_ga, 0.8.1205) – put back to English
			await p.goto(BASE + 'index.php?p=lang&l=en&r=p%3Dupdate', { referer: BASE + 'index.php?p=update' });
		}
		await p.setViewportSize({ width: 1200, height: 800 });
		expect(await p.isVisible('.nuNavIcons .js-theme-icon') && !(await p.isVisible('#nuMenuPrefs')), 'desktop: theme in the bar again');
	} finally {
		await ctx.close();
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
	}
};

T.nav_compact = async (s) => {
	const had = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'") !== '0';
	if (had) { s.skip = 'branding set'; return; }
	sql(`INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('branding', '{"logo_show":"login"}')`);
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=users');
		expect(await p.locator('.navbar-toggler').count() === 0 && await p.locator('#nuNavPin').count() === 0, 'toggler / pin in the compact bar');
		expect(await p.isVisible('#nuPaletteBtn') && await p.isVisible('.nuUserBtn .nuUserHam'), 'icons and ☰ in the bar');
		await p.click('.nuUserBtn'); await p.waitForTimeout(300);
		expect(await p.isVisible('.nuUserBtn + .dropdown-menu .text-danger') && await p.locator('#nuMenuHome').count() === 0, 'user menu (without Home)');
		await p.keyboard.press('Escape');
		const first = await p.evaluate(() => { const b = document.getElementById('nuHomeBtn'); return !!b && b === b.parentElement.firstElementChild; });
		expect(first, 'Home button at the very left');
		await Promise.all([p.waitForNavigation(), p.click('#nuHomeBtn')]);
		await p.mouse.move(600, 600); await p.waitForTimeout(300);
		const bg = (sel) => p.$eval(sel, (b) => getComputedStyle(b).backgroundColor);
		expect(await bg('#nuHomeBtn') === await bg('#nuPaletteBtn'), 'Home button not like the other tool buttons on the start page');   // 0.8.728
		expect(/p=home/.test(p.url()), 'Home: ' + p.url());
		await p.setViewportSize({ width: 1200, height: 800 });
		expect(await p.isVisible('.nuUserBtn .nu-user-name') && !(await p.isVisible('.nuUserBtn .nuUserHam')) && await p.isVisible('#nuHomeBtn'), 'desktop: name, no ☰, Home button');
		// 0.8.730: desktop – the page's breadcrumb next to the Home button (start page step hidden), phones – back in the page
		await p.goto(BASE + 'index.php?p=ipaccess'); await p.waitForTimeout(300);
		const crumbs = () => p.evaluate(() => { const n = document.getElementById('nuNavCrumbs'); const m = document.querySelector('#main nav[aria-label="breadcrumb"]');
			return { nav: !!n && !n.hidden && n.querySelectorAll('.breadcrumb-item').length, shown: n ? [...n.querySelectorAll('.breadcrumb-item')].filter((li) => li.offsetParent).map((li) => li.textContent.trim()) : [],
				inPage: !!m && !m.hidden && !!m.querySelector('.breadcrumb') }; });
		let cb = await crumbs();
		expect(cb.nav >= 2 && !cb.inPage && cb.shown.length === cb.nav - 1 && /IP/.test(cb.shown[cb.shown.length - 1]), 'desktop breadcrumb in the bar: ' + JSON.stringify(cb));
		const mid = (sel) => p.$eval(sel, (e) => { const r = e.getBoundingClientRect(); return r.top + r.height / 2; });
		expect(Math.abs(await mid('#nuNavCrumbs ol') - await mid('#nuHomeBtn')) < 2, 'breadcrumb not centred with the Home button');   // 0.8.737
		await p.setViewportSize({ width: 390, height: 800 }); await p.waitForTimeout(200);
		cb = await crumbs();
		expect(!cb.nav && cb.inPage, 'phone: breadcrumb in the page: ' + JSON.stringify(cb));
		await p.setViewportSize({ width: 1200, height: 800 });
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
		await p.setViewportSize({ width: 390, height: 800 });
		await p.goto(BASE + 'index.php?p=users');
		expect(await p.locator('.navbar-toggler').count() === 1 && await p.locator('#nuHomeBtn').count() === 0, 'with the logo: the ☰ toggler again, no Home button');
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
		await ctx.close();
	}
};

// 0.8.734: right-click on the logo (globeadmin) → confirmation → hidden in the top bar (Branding: only sign-in pages) + message
T.logo_hide = async (s) => {
	const had = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'") !== '0';
	if (had) { s.skip = 'branding set'; return; }
	const p = s.ga;
	try {
		await open(p, 'index.php?p=users');
		expect(await p.locator('.navbar-brand[data-nu-logo-hide]').count() === 1, 'logo without the right-click action');
		await p.click('.navbar-brand', { button: 'right' }); await p.waitForTimeout(500);
		expect(await p.isVisible('#nuConfirm') && /sign-in|Anmelde/i.test(await p.textContent('#nuConfirm .js-confirm-text')), 'no confirmation');
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm .js-confirm-ok')]);
		await p.waitForLoadState('networkidle');
		expect(/p=users/.test(p.url()) && /Branding/.test(await p.textContent('body')), 'back on the page with the message: ' + p.url());
		expect(JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'")).logo_show === 'login', 'logo_show not login');
		expect(await p.locator('#nuHomeBtn').count() === 1 && await p.locator('.navbar-brand[data-nu-logo-hide]').count() === 0, 'top bar without logo');
		const u = await login('sample_carla', UPW);
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
		await open(u, 'index.php?p=home');
		expect(await u.locator('[data-nu-logo-hide]').count() === 0 && await u.locator('#nuLogoHideForm').count() === 0, 'a user gets the right-click action');
		await u.context().close();
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
	}
};

// 0.8.736: Branding – own title field (wins over $nuConfigTitle), "Show the title" label follows Show logo
T.pwa_install = async (s) => {
	// 0.8.1136: installable app – manifest (name, icons, shortcuts of the user), service worker (static files cached, pages never), offline page, switch in Branding
	const keep = path.join(os.tmpdir(), 'nunext-e2e-pwa.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_setting > ' + keep);
	const p = await login(GA[0], GA[1]);
	try {
		const head = await p.evaluate(() => ({ m: (document.querySelector('link[rel=manifest]') || {}).href || '', theme: !!document.querySelector('meta[name=theme-color]'), apple: !!document.querySelector('link[rel=apple-touch-icon]') }));
		expect(/p=pwa&a=manifest/.test(head.m) && head.theme && head.apple, 'head tags: ' + JSON.stringify(head));
		const man = await p.evaluate(async (u) => (await fetch(u, { credentials: 'include' })).json(), head.m);
		expect(man.display === 'standalone' && man.scope === './' && man.icons.length === 3 && man.icons.some(i => i.purpose === 'maskable'), 'manifest: ' + JSON.stringify(man).slice(0, 300));
		const ic = await p.evaluate(async (u) => { const r = await fetch(new URL(u, location.href)); const b = new Uint8Array(await r.arrayBuffer()); return [r.headers.get('content-type'), b[1], b[2], b[3]]; }, man.icons[1].src);
		expect(ic[0] === 'image/png' && String.fromCharCode(ic[1], ic[2], ic[3]) === 'PNG', 'icon is a PNG: ' + ic);
		// 0.8.1139: the browser fetches these by itself – they must never set a cookie (a new session cookie signed the user out in CI)
		for (const a of ['sw', 'offline', 'manifest', 'icon&s=192']) {
			const r = await p.request.get(BASE + 'index.php?p=pwa&a=' + a, { headers: { cookie: '' } });
			expect(!r.headers()['set-cookie'], 'no cookie from ?p=pwa&a=' + a + ': ' + r.headers()['set-cookie']);
		}
		// the worker: registered for the folder, controls the page after a reload
		await p.evaluate(() => navigator.serviceWorker.ready);
		await open(p, 'index.php?p=home');
		const ctl = await p.evaluate(async () => { const r = await navigator.serviceWorker.getRegistration(); return [!!navigator.serviceWorker.controller, r ? r.scope : '']; });
		expect(ctl[0] && /\/nunext\/$/.test(ctl[1]), 'worker controls the page, scope = folder: ' + ctl);
		const cached = await p.evaluate(async () => { const ks = await caches.keys(); const urls = []; for (const k of ks) for (const r of await (await caches.open(k)).keys()) urls.push(r.url); return [ks, urls]; });
		expect(cached[0].length === 1 && /^nunext-/.test(cached[0][0]), 'one cache: ' + cached[0]);
		expect(cached[1].some(u => /assets\/css\/nuapp(\.min)?\.css\?v=/.test(u)) && cached[1].some(u => /p=pwa&a=offline/.test(u)), 'static files + offline page cached');
		expect(!cached[1].some(u => /p=home|p=form|p=users/.test(u)), 'no page cached: ' + cached[1].filter(u => /index\.php/.test(u)).join(' '));
		// offline: a page gives the offline page, not the browser's error
		await p.context().setOffline(true);
		await p.goto(BASE + 'index.php?p=users').catch(() => {});
		const off = await p.evaluate(() => [!!document.getElementById('nuOffline'), (document.querySelector('.nuOffBtn') || {}).textContent || '']);
		expect(off[0] && /Try again/.test(off[1]), 'offline page: ' + off);
		await p.context().setOffline(false);
		await open(p, 'index.php?p=home');
		expect(!(await p.locator('#nuOffline').count()) && !/p=login/.test(p.url()), 'back online: the page itself, still signed in: ' + p.url());
		// favourites → shortcuts
		const favs = await p.locator('.nuHomeStrip[data-nu-strip="fav"] a[href]').count();
		if (favs) { const m2 = await p.evaluate(async (u) => (await fetch(u, { credentials: 'include' })).json(), head.m); expect(Array.isArray(m2.shortcuts) && m2.shortcuts.length > 0, 'shortcuts from favourites'); }
		// switched off in Branding: no manifest, the worker goes, the cache is emptied
		await open(p, 'index.php?p=branding');
		await p.uncheck('#pwa');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		await open(p, 'index.php?p=home');
		expect(!(await p.locator('link[rel=manifest]').count()) && await p.getAttribute('meta[name=nu-pwa]', 'content') === 'off', 'off: no manifest');
		let gone = false;
		for (let i = 0; i < 20 && !gone; i++) { await p.waitForTimeout(300); gone = await p.evaluate(async () => !(await navigator.serviceWorker.getRegistration()) && (await caches.keys()).filter(k => k.indexOf('nunext-') === 0).length === 0); }
		expect(gone, 'worker and cache removed');
		expect(p.errors.length === 0, 'no JS errors: ' + p.errors.join(' | '));
	} finally {
		await p.context().close();
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.branding_title = async (s) => {
	const had = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'") !== '0';
	if (had) { s.skip = 'branding set'; return; }
	const p = s.ga;
	try {
		await open(p, 'index.php?p=branding');
		const lbl = () => p.textContent('#show_title_lbl');
		const withLogo = await lbl();
		await p.selectOption('#logo_show', 'none');
		const alone = await lbl();
		expect(withLogo !== alone && /top bar|oberen Leiste/i.test(alone), 'label: ' + withLogo + ' / ' + alone);
		await p.fill('#title', 'E2E Title App');
		await p.check('#show_title');
		expect((await p.textContent('#prevTitle')).trim() === 'E2E Title App' && await p.isVisible('#prevTitle'), 'preview title');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		const st = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'"));
		expect(st.title === 'E2E Title App' && st.logo_show === 'none' && st.show_title === true, 'stored: ' + JSON.stringify(st));
		await open(p, 'index.php?p=users');
		expect(/E2E Title App/.test(await p.title()) && (await p.textContent('.nu-navbar .nu-brand-title')).trim() === 'E2E Title App', 'title in tab / top bar: ' + await p.title());
		const ctx = await browser.newContext(); const q = await ctx.newPage();
		await q.goto(BASE + 'index.php?p=login');
		expect(/E2E Title App/.test(await q.textContent('body')), 'title on the sign-in page');
		await ctx.close();
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
	}
};

T.nav_pin = async (s) => {
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=home');
		expect(!(await p.isVisible('#nuNavPin')), 'pin visible while the menu is closed');
		await p.click('.navbar-toggler'); await p.waitForTimeout(500);
		expect(await p.isVisible('#nuNavPin') && await p.getAttribute('#nuNavPin', 'aria-pressed') === 'false', 'pin not shown in the open menu');
		expect(await p.locator('.nuUserBtn .nu-user-name').isHidden(), 'user name shown on a phone');
		await p.click('#nuNavPin');
		await p.goto(BASE + 'index.php?p=users');
		expect(await p.locator('#nuNav.show').count() === 1 && await p.getAttribute('#nuNavPin', 'aria-pressed') === 'true', 'pinned menu closed on the next page');
		await p.click('#nuNavPin');
		await p.goto(BASE + 'index.php?p=home');
		expect(await p.locator('#nuNav.show').count() === 0, 'unpinned menu still open');
	} finally { await ctx.close(); }
};

// 0.8.825: the entry files got the nu prefix – the old names are gone, the globeadmin sees a notice after the update until hidden
T.entry_files_renamed = async (s) => {
	const p = s.ga;
	// 0.8.826: cron.php / dbadmin.php got the nu prefix; api.php / oauth.php keep their names (addresses other systems call)
	if (SITE) {
		for (const f of ['cron', 'dbadmin']) expect(fs.existsSync(path.join(SITE, 'nu' + f + '.php')) && !fs.existsSync(path.join(SITE, f + '.php')), f + '.php not renamed');
		for (const f of ['api', 'oauth']) expect(fs.existsSync(path.join(SITE, f + '.php')) && !fs.existsSync(path.join(SITE, 'nu' + f + '.php')), f + '.php renamed');
	}
	for (const [keep, gone] of [['api.php/v1/users', 'nuapi.php/v1/users'], ['oauth.php', 'nuoauth.php']]) {
		const a = await p.request.get(BASE + keep, {maxRedirects: 0}), b = await p.request.get(BASE + gone, {maxRedirects: 0});
		expect(a.status() !== 404 && b.status() === 404, keep + ' ' + a.status() + ' / ' + gone + ' ' + b.status());
	}
	sql("REPLACE INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value, updated_at, updated_by) VALUES ('entry_files_notice', 'e2e', NOW(), 'e2e')");
	try {
		await open(p, 'index.php?p=home');
		{ const tx = await p.textContent('#nuEntryNotice'); expect(/nucron\.php/.test(tx) && !/api\.php|oauth\.php/.test(tx), 'notice text: ' + tx); }
		{	// 0.8.845: the long crontab path wraps on phones
			const ph = await login(GA[0], GA[1], { viewport: { width: 360, height: 800 } });
			await open(ph, 'index.php?p=home');
			const w = await ph.evaluate(() => [document.documentElement.scrollWidth, document.getElementById('nuEntryNotice').getBoundingClientRect().right]);
			expect(w[0] <= 360 && w[1] <= 360, 'notice wider than the phone: ' + w.join(' / '));
			await ph.context().close();
		}
		await Promise.all([p.waitForNavigation(), p.click('#nuEntryNoticeHide')]);
		expect(await p.locator('#nuEntryNotice').count() === 0 && sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'entry_files_notice'") === '0', 'notice not hidden');
	} finally { sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'entry_files_notice'"); }
};

T.settings_hint_phone = async (s) => {                // 0.8.1084: the (i) of a settings page opens its hint on phones too
	const p = s.ga;
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await p.context().storageState() });
	const ph = await ctx.newPage();
	try {
		await ph.goto(BASE + 'index.php?p=configfile');
		await ph.click('button.nuInfoBtn[data-bs-target="#cfgHint"]');
		await ph.waitForTimeout(500);
		expect(await ph.isVisible('#cfgHint'), 'hint shown after the tap');
		// 0.8.1085: the warning can be hidden (×) and comes back with the ⚠ next to the title, also after a reload
		await ph.click('#cfgWarnHide');
		expect(!(await ph.isVisible('#cfgWarn')) && await ph.isVisible('#cfgWarnShow'), 'warning hidden, ⚠ shown');
		await ph.reload();
		expect(!(await ph.isVisible('#cfgWarn')) && await ph.isVisible('#cfgWarnShow'), 'stays hidden after a reload');
		await ph.click('#cfgWarnShow');
		expect(await ph.isVisible('#cfgWarn') && !(await ph.isVisible('#cfgWarnShow')), 'shown again');
	} finally { await ctx.close(); }
};

T.home_favourites = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home&tiles=1');
	await homeEdit(p); await p.waitForTimeout(400);
	await p.locator('.nuHeItem[data-key="users"] .nuHeTools button').filter({ has: p.locator('.bi-star') }).first().click();
	await p.click('#nuHomeSave'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
	// 0.8.806: no ☆ filter button any more – the row Favourites shows the starred tiles
	expect(await p.locator('#nuHomeFavOnly').count() === 0, 'favourites-only button still there');
	const st = '[data-nu-strip="fav"]', rs = '[data-nu-strip="recent"]';
	expect(await p.locator(st).isVisible(), 'row Favourites not shown');
	expect(await p.locator(st + ' .nuHomeChip').count() === 1, 'row Favourites: chips');
	expect(await p.locator('#nuHomeFavEdit').count() === 0, 'Edit link still there');
	// 0.8.807: right-click on the row → Edit favourites / Hide this row
	const head = p.locator(st + ' .nuHomeStripToggle');
	await head.click({button: 'right'}); await p.waitForTimeout(300);
	const menu = p.locator('.nuHomeCtx');
	const items = await menu.locator('.dropdown-item').allTextContents();
	expect(items.length === 3, 'row menu: ' + items.join(' | '));
	await menu.locator('.dropdown-item').first().click(); await p.waitForTimeout(500);
	expect(await p.locator('#nuHomeEditFav').getAttribute('aria-pressed') === 'true', 'edit mode not filtered to favourites');
	await p.click('#nuHomeCancel'); await p.waitForTimeout(400);
	// 0.8.828: Auto collapse – folds at once, stays folded after a reload, picking a chip folds the opened row again
	await head.click({button: 'right'}); await p.waitForTimeout(300);
	await p.locator('.nuHomeCtx .nuHomeAutoFold').click(); await p.waitForTimeout(300);
	expect(await p.locator(st + '.nuHomeStripFolded').count() === 1, 'auto collapse did not fold');
	await p.reload(); await p.waitForTimeout(600);
	expect(await p.locator(st + '.nuHomeStripFolded').count() === 1, 'not folded after reload');
	await head.click(); await p.waitForTimeout(200);
	expect(await p.locator(st + '.nuHomeStripFolded').count() === 0, 'heading does not open the row');
	await head.click({button: 'right'}); await p.waitForTimeout(300);
	expect(await p.locator('.nuHomeCtx .nuHomeAutoFold').getAttribute('aria-checked') === 'true', 'menu entry not checked');
	await p.keyboard.press('Escape');
	await p.evaluate(sel => { const c = document.querySelector(sel + ' .nuHomeChip'); c.addEventListener('click', e => e.preventDefault(), {once: true}); c.click(); }, st);
	await p.waitForTimeout(200);
	expect(await p.locator(st + '.nuHomeStripFolded').count() === 1, 'picking an entry did not fold the row');
	await p.evaluate(() => { localStorage.removeItem('nunext-home-strip-auto-fav'); localStorage.removeItem('nunext-home-strip-fav'); });
	await p.reload(); await p.waitForTimeout(600);
	// a chip's menu carries the row's entries below its own
	await p.locator(st + ' .nuHomeChip').first().click({button: 'right'}); await p.waitForTimeout(300);
	expect(await p.locator('.nuHomeCtx .dropdown-item').count() >= 3, 'chip menu without row entries');
	await p.keyboard.press('Escape');
	// Hide this row → hidden, message names the way back, stays hidden after a reload; Appearance switch shows it again
	await head.click({button: 'right'}); await p.waitForTimeout(300);
	await p.locator('.nuHomeCtx .dropdown-item').last().click(); await p.waitForTimeout(800);
	expect(await p.locator(st).isHidden(), 'row not hidden');
	const msg = await p.locator('#nuHomeToast').textContent();
	expect(/→/.test(msg), 'no hint how to show it again: ' + msg);
	await p.reload(); await p.waitForTimeout(600);
	expect(await p.locator(st).isHidden(), 'row back after reload');
	await homeTab(p, 'appearance');
	await p.locator('label[for="nuHomeVp_favRow"]').click(); await p.waitForTimeout(800);
	expect(await p.locator(st).isVisible(), 'switch does not show the row again');
	await p.click('#nuHomeViewBtn'); await p.waitForTimeout(300);
	// Recently used: Clear / Hide this row in its menu
	await open(p, 'index.php?p=users'); await open(p, 'index.php?p=home&tiles=1');
	if (await p.locator(rs).isHidden()) { await homeTab(p, 'appearance'); await p.locator('label[for="nuHomeVp_recentStrip"]').click(); await p.waitForTimeout(800); await p.click('#nuHomeViewBtn'); await p.waitForTimeout(300); }
	expect(await p.locator(rs).isVisible(), 'row Recently used not shown');
	{
		await p.locator(rs + ' .nuHomeStripToggle').click({button: 'right'}); await p.waitForTimeout(300);
		const r = await p.locator('.nuHomeCtx .dropdown-item').allTextContents();
		expect(r.length === 3, 'recent row menu: ' + r.join(' | '));
		await p.locator('.nuHomeCtx .dropdown-item').last().click(); await p.waitForTimeout(800);
		expect(await p.locator(rs).isHidden(), 'recent row not hidden');
		await p.locator('#nuHomeToast button').last().click(); await p.waitForTimeout(800);   // Undo
		expect(await p.locator(rs).isVisible(), 'Undo did not show the row');
	}
	// unstar again → no row
	await homeEdit(p); await p.waitForTimeout(400);
	await p.locator('.nuHeItem[data-key="users"] .nuHeTools button').filter({ has: p.locator('.bi-star-fill') }).first().click();
	await p.click('#nuHomeSave'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
	expect(await p.locator(st).isHidden(), 'row Favourites shown without favourites');
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

// Branding → Show logo (0.8.630): everywhere / sign-in pages only / nowhere; the title stands in the top bar without a logo
T.branding_logo_show = async (s) => {
	const p = s.ga;
	const keep = sql("SELECT IFNULL(setting_value, '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
	const save = async (w, title) => {
		await open(p, 'index.php?p=branding');
		await p.selectOption('#logo_show', w);
		if (title !== undefined) { if (title) await p.check('#show_title'); else await p.uncheck('#show_title'); }
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#brandSave')]);
	};
	const loginLogo = async () => { const c = await p.context().browser().newContext(); const q = await c.newPage(); await q.goto(BASE + 'index.php?p=login'); const n = await q.locator('.nu-brand').count(); await c.close(); return n; };
	try {
		await save('login');
		await open(p, 'index.php?p=home');
		expect(await p.locator('.nuNavInner .nu-brand').count() === 0, 'login only: logo still in the top bar');
		expect(await loginLogo() === 1, 'login only: no logo on the sign-in page');
		await open(p, 'index.php?p=branding');
		expect(await p.locator('#prevLogo').isHidden(), 'preview shows the logo');
		await save('none', true);
		await open(p, 'index.php?p=home');
		expect(await p.locator('.nuNavInner .nu-brand').count() === 0, 'none: logo in the top bar');
		expect(await loginLogo() === 0, 'none: logo on the sign-in page');
		const t = await (async () => { await open(p, 'index.php?p=branding'); const v = (await p.textContent('#prevTitle')).trim(); await open(p, 'index.php?p=home'); return v; })();
		if (t) expect(await p.locator('.nuNavInner .nu-brand-title').count() === 1, 'none: no title in the top bar');
		await save('all', false);
		await open(p, 'index.php?p=home');
		expect(await p.locator('.nuNavInner .nu-brand').count() === 1, 'all: no logo in the top bar');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (keep) sql(`UPDATE zzzzsys_nunext_setting SET setting_value = '${keep.replace(/'/g, "''")}' WHERE zzzzsys_nunext_setting_id = 'branding'`);
		else sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
	}
};

// Themes (0.8.632): duplicate a built-in theme, edit and save, use for everyone, per access level, the user's own pick, export, delete
// themes (0.8.643): the globeadmin picks a theme for himself ("Use for me", badge "Yours") – also without "users may choose";
// the export of a theme holds every colour, light and dark (also the built-in nuBuilder Next theme)
T.theme_mine = async (s) => {
	const p = s.ga;
	const keepGa = sql("SELECT IFNULL(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'theme_ga'");
	try {
		await open(p, 'index.php?p=themes');
		const mine = () => p.$$eval('.thMine', a => a.map(e => e.closest('.thCard').dataset.theme));
		expect(await p.locator('.thCard[data-theme="b:corporate"] .thUseMe').count() === 1, 'Use for me on a built-in theme');
		await Promise.all([p.waitForNavigation(), p.click('.thCard[data-theme="b:corporate"] .thUseMe')]);
		expect((await mine()).join() === 'b:corporate', 'badge Yours on Corporate: ' + (await mine()).join());
		expect(await p.$eval('.nu-navbar', e => getComputedStyle(e).backgroundColor) === 'rgb(27, 54, 93)', 'Corporate top bar');
		expect(await p.locator('[data-nu-theme-pick="b:corporate"].active').count() === 1, 'chosen in the light / dark menu');
		await p.click('.thCard[data-theme="b:next"] [data-bs-toggle=dropdown]');
		const [dl] = await Promise.all([p.waitForEvent('download'), p.click('.thCard[data-theme="b:next"] .thExport')]);
		const j = JSON.parse(require('fs').readFileSync(await dl.path(), 'utf8'));
		expect(Object.keys(j.values).length >= 26 && Object.keys(j.dark).length >= 26 && j.values.accent === '#2f6fd6' && j.dark.page_bg === '#14171c', 'complete export: ' + JSON.stringify(j).slice(0, 120));
		await p.goto(BASE + 'index.php?p=themes');
		await p.click('.thCard[data-theme="b:corporate"] [data-bs-toggle=dropdown]');
		await Promise.all([p.waitForNavigation(), p.click('.thCard[data-theme="b:corporate"] .thUseMeOff')]);
		expect(!(await mine()).includes('b:corporate'), 'Stop using for me');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + keepGa.replace(/'/g, "''") + "' WHERE zzzzsys_nunext_setting_id = 'theme_ga'");
	}
};

// 0.8.694: theme names are unique – an import / a copy of a name in use gets " (2)", saving a name in use is refused
T.theme_names = async (s) => {
	const p = s.ga, tmp = path.join(os.tmpdir(), 'nunext-e2e-theme.json');
	const names = () => sql("SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR '|') FROM zzzzsys_nunext_theme WHERE name LIKE 'E2E unique%'");
	try {
		fs.writeFileSync(tmp, JSON.stringify({ type: 'nunext-theme', version: 1, name: 'E2E unique', values: { accent: '#224466' }, dark: {} }));
		for (let i = 0; i < 2; i++) {
			await open(p, 'index.php?p=themes');
			await Promise.all([p.waitForNavigation(), p.setInputFiles('#thImport', tmp)]);
		}
		expect(names() === 'E2E unique|E2E unique (2)', 'imported names: ' + names());
		const id2 = sql("SELECT zzzzsys_nunext_theme_id FROM zzzzsys_nunext_theme WHERE name = 'E2E unique (2)'");
		await open(p, 'index.php?p=themes');
		const builtin = (await p.locator('.thCard[data-theme^="b:"] .thCardName').first().textContent()).trim();
		await open(p, 'index.php?p=themes&edit=' + id2);
		for (const nm of ['e2e UNIQUE ', builtin]) {                   // the field refuses it while typing …
			await p.fill('#thName', nm);
			expect(await p.evaluate(() => !document.getElementById('thName').checkValidity()) && await p.isVisible('#thNameMsg'), 'name field accepts ' + nm);
		}
		await p.fill('#thName', 'E2E unique (2)');
		expect(await p.evaluate(() => document.getElementById('thName').checkValidity()), 'own name refused');
		// … and the server too (a POST without the browser check)
		const st = await p.evaluate(async (tid) => { const fd = new FormData(document.getElementById('thForm')); fd.set('name', 'E2E unique'); fd.set('action', 'save'); fd.set('id', tid);
			const r = await fetch('index.php?p=themes', { method: 'POST', body: fd, redirect: 'follow' }); return r.status; }, id2);
		expect(names() === 'E2E unique|E2E unique (2)', 'a name in use was saved: ' + names() + ' (' + st + ')');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_theme WHERE name LIKE 'E2E unique%'");
		try { fs.unlinkSync(tmp); } catch (e) { /* */ }
	}
};

T.themes = async (s) => {
	const p = s.ga;
	const keep = sql("SELECT IFNULL(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'theme'");
	const themeOf = async (q) => q.evaluate(() => { const l = document.getElementById('nuThemeCss'); return l ? new URL(l.href).searchParams.get('t') : ''; });
	const anna = () => login('sample_anna', UPW);
	try {
		await open(p, 'index.php?p=themes');
		expect(await p.locator('.thCard[data-theme^="b:"]').count() === 5, 'not five built-in themes');
		await Promise.all([p.waitForNavigation(), p.locator('.thCard[data-theme="b:soft"] .thDup').click()]);
		expect(/edit=/.test(p.url()), 'duplicate did not open the editor: ' + p.url());
		await p.fill('#thName', 'E2E theme');
		await p.fill('#v_accent', '#123456');
		await p.waitForFunction(() => { const f = document.getElementById('thPreview'); const d = f && f.contentDocument; return d && getComputedStyle(d.documentElement).getPropertyValue('--nu-accent').trim() === '#123456'; }, null, { timeout: 8000 });
		await Promise.all([p.waitForNavigation(), p.click('#thSave')]);
		const id = sql("SELECT zzzzsys_nunext_theme_id FROM zzzzsys_nunext_theme WHERE name = 'E2E theme'");
		expect(id !== '', 'theme not saved');
		await Promise.all([p.waitForNavigation(), p.locator(`.thCard[data-theme="${id}"] .thUse`).click()]);
		await open(p, 'index.php?p=users');
		expect(await themeOf(p) === id, 'theme for everyone not used: ' + await themeOf(p));
		expect((await p.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--nu-accent'))).trim() === '#123456', 'accent of the theme not applied');
		// per access level
		const viewer = sql("SELECT zzzzsys_access_id FROM zzzzsys_access WHERE sal_code = 'sample_viewer'");
		await open(p, 'index.php?p=themes');
		expect(await p.isHidden('#thLevelsBox'), 'Per access level not folded');   // folded by default (0.8.695)
		await p.click('#thLevelsBtn'); await p.waitForSelector('#thLevelsBox.show');
		await p.selectOption(`select[name="level[${viewer}]"]`, 'b:contrast');
		await p.check('#thChoice');
		await p.check('#thA_b_forte');
		await Promise.all([p.waitForNavigation(), p.click('#thWhoSave')]);
		let u = await anna();
		await open(u, 'index.php?p=home');
		expect(await themeOf(u) === 'b:contrast', 'theme of the access level not used: ' + await themeOf(u));
		// the user's own pick (menu next to light / dark)
		expect(await u.locator('[data-nu-theme-pick="b:forte"]').count() === 1 && await u.locator('[data-nu-theme-pick="b:soft"]').count() === 0, 'menu: only the allowed themes');
		await Promise.all([u.waitForNavigation(), u.evaluate(() => document.querySelector('[data-nu-theme-pick="b:forte"]').form.submit())]);
		expect(await themeOf(u) === 'b:forte', 'own pick not used: ' + await themeOf(u));
		await Promise.all([u.waitForNavigation(), u.evaluate(() => document.querySelector('[data-nu-theme-pick=""]').form.submit())]);
		expect(await themeOf(u) === 'b:contrast', 'back to the access level\'s theme: ' + await themeOf(u));
		await u.context().close();
		// export, delete (refused while in use)
		await open(p, 'index.php?p=themes');
		const exp = await p.evaluate(async (tid) => { const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value); fd.append('action', 'export'); fd.append('id', tid);
			return (await fetch('index.php?p=themes', { method: 'POST', body: fd })).text(); }, id);
		expect(/"type": "nunext-theme"/.test(exp) && /#123456/.test(exp), 'export: ' + exp.slice(0, 100));
		await Promise.all([p.waitForNavigation(), p.evaluate((tid) => { const f = [...document.querySelectorAll('input[name=action][value=delete]')].map(i => i.form).find(f => f.querySelector('input[name=id]').value === tid); f.removeAttribute('data-confirm'); f.submit(); }, id)]);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_theme WHERE zzzzsys_nunext_theme_id = '${id}'`) === '1', 'a theme in use was deleted');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (keep) sql(`UPDATE zzzzsys_nunext_setting SET setting_value = '${keep.replace(/'/g, "''")}' WHERE zzzzsys_nunext_setting_id = 'theme'`);
		else sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('theme', 'theme_ga')");
		sql("DELETE FROM zzzzsys_nunext_theme WHERE name LIKE 'E2E theme%' OR name LIKE '%(copy)%' OR name LIKE '%(Kopie)%'");
	}
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
		expect(await p.locator('#nuHomeSearchMore [data-nu-res="t:x_e2e_tile"]').isVisible() && !(await p.locator('#nuHomeSearchMore [data-nu-res="t:users"]').count()), 'search does not find the dynamic tile');
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
		const srch = await p.evaluate(() => window.__search);              // other tiles may mention notes (Projects); the hidden one must not come back
		expect(/^notes:/.test(srch) && !srch.slice(6).split(',').includes('notes'), 'a tile hidden by code is found by the search: ' + srch);
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
	expect(await ph.locator('.nuHomeCtx .dropdown-item').count() === 2, 'long press shows no menu (favourite + hide, 0.8.652)');
	await ph.close();
};

// 0.8.652: Hide in the tile menu – admin start page: into the layout's hidden tiles, message with Undo (back to its place); user start page:
// hidden for the user (as Personalise), Undo; the message shows once
T.home_ctx_hide = async (s) => {
	const p = s.ga, keep = path.join(os.tmpdir(), 'nunext-e2e-ctxhide.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_list_view zzzzsys_nunext_setting zzzzsys_user > ' + keep);
	const order = (pg) => pg.$$eval('#nuHomeView .nuHomeMain [data-nu-tile]', (a) => a.filter((x) => x.offsetParent !== null).map((x) => x.getAttribute('data-nu-tile')).join(','));
	const hideUndo = async (pg, who) => {
		const before = await order(pg), key = before.split(',')[1];
		await pg.locator('#nuHomeView .nuHomeMain [data-nu-tile="' + key + '"]').first().click({ button: 'right' }); await pg.waitForTimeout(200);
		expect(await pg.locator('.nuHomeCtx .dropdown-item:has(.bi-eye-slash)').count() === 1, who + ': no Hide in the menu');
		await Promise.all([pg.waitForNavigation(), pg.click('.nuHomeCtx .dropdown-item:has(.bi-eye-slash)')]); await pg.waitForLoadState('networkidle');
		expect(!(await order(pg)).split(',').includes(key), who + ': tile still shown');
		await pg.waitForSelector('#nuHomeHideUndo.show', { timeout: 5000 }).catch(() => {});
		expect(await pg.isVisible('#nuHomeHideUndo'), who + ': no message with Undo');
		await Promise.all([pg.waitForNavigation(), pg.click('#nuHomeHideUndoBtn')]); await pg.waitForLoadState('networkidle');
		expect(await order(pg) === before, who + ': Undo did not put it back in its place');
		expect(await pg.locator('#nuHomeHideUndo').count() === 0, who + ': message shown again');
		return key;
	};
	try {
		await open(p, 'index.php?p=home&tiles=1');
		const key = await hideUndo(p, 'admin');
		const lv = sql("SELECT definition FROM zzzzsys_nunext_list_view WHERE list_key = 'home' ORDER BY updated_at DESC LIMIT 1");
		expect(lv && !JSON.parse(lv).hidden.includes(key), 'admin: still in the layout\'s hidden tiles after Undo');
		const u = await login('sample_ben', UPW);
		try {
			await open(u, 'index.php?p=home');
			if (await u.locator('#nuHomeView .nuHomeMain [data-nu-tile]').count() < 2) { s.skip = 'sample_ben has fewer than two tiles'; return; }
			await hideUndo(u, 'user');
			expect(u.errors.length === 0, u.errors.slice(0, 2).join(' | '));
		} finally { await u.context().close(); }
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
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
		// the chip's own entries (add to favourites 0.8.955, remove, clear) + the row's below a divider (0.8.807: Hide this row)
		expect(await p.locator('.nuHomeCtx .dropdown-item:has(.bi-dash-circle)').count() === 1 && await p.locator('.nuHomeCtx .dropdown-item:has(.bi-x-circle)').count() === 1 && /bi-eye-slash/.test(await p.locator('.nuHomeCtx .dropdown-item').last().innerHTML()), 'start page: menu without remove / clear / hide row');
		expect(await p.locator('.nuHomeCtx .dropdown-item').first().locator('.bi-star-fill, .bi-star').count() === 1, 'start page: no favourites entry first');
		await Promise.all([p.waitForNavigation(), p.locator('.nuHomeCtx .dropdown-item:has(.bi-dash-circle)').click()]);
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

// start page (0.8.452): one Customise button, panel tabs Content · Appearance · Layouts; the tile style only on Appearance
T.home_customise = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-home-cust-tab'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=home');
	const shown = await p.$$eval('.nuHomeTools button', b => b.filter(x => !x.hidden).map(x => x.id));
	expect(shown.filter(x => x !== 'nuHfToggle').join() === 'nuHomeViewBtn', 'one button: ' + shown);   // + the Start pages button (0.8.1289)
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
	await p.click('#nuHomeVpTab-content'); await p.waitForSelector('body.nuHomeEditing'); await p.waitForTimeout(400);
	expect(await p.evaluate(() => document.body.classList.contains('nuHomeEditing')) && await p.locator('#nuHomeSize').count() === 0, 'edit mode without the tile size');
	await p.click('#nuHomeCancel'); await p.waitForTimeout(300);
	await p.keyboard.press('v'); await p.waitForTimeout(300);
	expect(await p.getAttribute('#nuHomeVpTab-appearance', 'aria-selected') === 'true', 'V opens Appearance');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.733: developer mode – "Check & install" next to Check package: a package that is not newer goes to the normal check with the reason,
// a newer one is installed at once (no SQL in the backup, sample data refreshed)
// 0.8.1208: a dialogue closes open tooltips, and the page behind it shows none (Check & install on phones)
T.tooltip_modal_hidden = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=update');
	const r = await p.evaluate(async () => {
		const wait = (ms) => new Promise((ok) => setTimeout(ok, ms));
		const b = document.getElementById('updQuick'), m = document.getElementById('updQuickProgress');
		if (!b || !m) return 'skip';
		b.classList.remove('d-none'); b.setAttribute('data-bs-toggle', 'tooltip'); if (!b.title) b.title = 'x';
		const tip = nuBs.tooltip(b); tip.show(); await wait(400);
		const before = document.querySelectorAll('.tooltip.show').length;
		const md = nuBs.modal(m); md.show(); await wait(600);
		const during = document.querySelectorAll('.tooltip.show').length;
		tip.show(); await wait(400);
		const again = document.querySelectorAll('.tooltip.show').length;
		md.hide(); await wait(400);
		return { before, during, again };
	});
	if (r === 'skip') { s.skip = 'Check & install not on this site'; return; }
	expect(r.before === 1 && r.during === 0 && r.again === 0, 'tooltips with a dialogue open: ' + JSON.stringify(r));
};

// Uppy uploads (0.8.459): a file object with target "File System" and nuBuilder's Uppy template (library pack uppy) uploads through
// ?p=upload into the folder of the procedure; executable names refused before any procedure, the hardened template checks type +
// extension and never overwrites; nothing of it is saved with the record; update step 0020 adds the template rows when missing
// side bar pinned (0.8.556): the pin keeps it open as a column next to the page from 992 px, on every page, per user; narrower = drawer;
// the button left of the logo unpins it
T.sidebar_pin = async (s) => {
	const p = s.ga, benId = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	if (!benId) { s.skip = 'no sample users'; return; }
	const keepBen = sql(`SELECT IFNULL(HEX(sus_json), '-') FROM zzzzsys_user WHERE zzzzsys_user_id = '${benId}'`);
	const cleanup = () => {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('sidebar_ga', 'sidebar_default', 'sidebar_pin_ga')");
		sql(`UPDATE zzzzsys_user SET sus_json = ${keepBen === '-' ? 'NULL' : `UNHEX('${keepBen}')`} WHERE zzzzsys_user_id = '${benId}'`);
	};
	cleanup();
	sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('sidebar_ga', '1'), ('sidebar_default', '1')");
	const state = (pg) => pg.evaluate(() => { const sb = document.getElementById('nuSidebar'), m = document.getElementById('main'), pin = document.getElementById('nuSbPin');
		return { pinned: !!document.querySelector('.nuShell.nuSbPinned'), drawer: document.body.classList.contains('nuSbOpen'), shown: !!sb && getComputedStyle(sb).visibility === 'visible',
			mainX: Math.round(m.getBoundingClientRect().left), pinVisible: !!pin && pin.offsetParent !== null, backdrop: !!document.querySelector('.nuSbBackdrop') }; });
	try {
		await p.setViewportSize({ width: 1300, height: 900 });
		await open(p, 'index.php?p=home&tiles=1');
		let st = await state(p);
		expect(!st.pinned && !st.shown && st.mainX < 100, 'not pinned at first: ' + JSON.stringify(st));
		await p.click('#nuSbOpen'); await p.waitForTimeout(300);
		expect((await state(p)).pinVisible, 'pin button in the drawer');
		await p.click('#nuSbPin'); await p.waitForTimeout(400);
		st = await state(p);
		expect(st.pinned && st.shown && !st.drawer && !st.backdrop && st.mainX >= 250, 'pinned: column next to the page ' + JSON.stringify(st));
		await open(p, 'index.php?p=users');
		st = await state(p);
		expect(st.pinned && st.shown && st.mainX >= 250, 'still pinned on the next page ' + JSON.stringify(st));
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'sidebar_pin_ga'") === '1', 'globeadmin: stored in the setting row');
		await p.setViewportSize({ width: 800, height: 900 }); await p.waitForTimeout(400);
		st = await state(p);
		expect(!st.shown && !st.pinVisible && st.mainX < 100, 'narrow: drawer again ' + JSON.stringify(st));
		await p.setViewportSize({ width: 1300, height: 900 }); await p.waitForTimeout(400);
		expect((await state(p)).shown, 'wide again: the column is back');
		await p.click('#nuSbOpen'); await p.waitForTimeout(400);
		st = await state(p);
		expect(!st.pinned && !st.shown, 'the button left of the logo unpins ' + JSON.stringify(st));
		await p.waitForTimeout(300);
		await open(p, 'index.php?p=users');
		expect(!(await state(p)).pinned, 'unpinned after reload');
		// a normal user: his own choice in sus_json
		const u = await login('sample_ben', 'Test1234!');
		await open(u, 'index.php?p=home');
		await u.click('#nuSbOpen'); await u.waitForTimeout(300);
		await u.click('#nuSbPin'); await u.waitForTimeout(500);
		expect(/NUNEXT_SIDEBAR_PIN"\s*:\s*"1"/.test(sql(`SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '${benId}'`)), 'user: stored in sus_json');
		await open(u, 'index.php?p=home');
		expect((await state(u)).pinned, 'user: pinned after reload');
		expect(p.errors.length === 0 && u.errors.length === 0, p.errors.concat(u.errors).slice(0, 2).join(' | '));
		await u.context().close();
	} finally {
		await p.setViewportSize({ width: 1300, height: 900 });
		cleanup();
	}
};

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
		// 0.8.708: no longer in the user menu (nor Security / System status) – start page → Customise → Appearance
		await p.click('.nu-navbar .nu-user-name');
		const menu = await p.evaluate(() => [!!document.getElementById('nuSidebarToggle'), !!document.querySelector('.nuUserBtn + .dropdown-menu > li:not(.d-md-none) a[href$="p=security"]'), !!document.querySelector('.nuUserBtn + .dropdown-menu > li:not(.d-md-none) a .bi-activity')]);
		expect(JSON.stringify(menu) === '[false,false,false]', 'user menu still has side bar / security / status: ' + JSON.stringify(menu));
		await p.keyboard.press('Escape');
		await homeTab(p, 'appearance');
		expect(!await p.locator('#nuHomeVp_sidebar').isChecked(), 'switch on while the side bar is off');
		await Promise.all([p.waitForNavigation(), p.click('label[for="nuHomeVp_sidebar"]')]);
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
		await open(p, 'index.php?p=settings&s=look');   // 0.8.702: in the settings centre (no longer in the user menu)
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
		await homeTab(ben, 'appearance');
		expect(await ben.locator('#nuHomeVp_sidebar').isChecked(), 'sample_ben: switch off while the default is on');
		await Promise.all([ben.waitForNavigation(), ben.click('label[for="nuHomeVp_sidebar"]')]);
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

// start page (0.8.746): on desktop the search row stays below the top bar while scrolling; phones scroll it away
T.home_search_sticky = async (s) => {
	const p = s.ga;
	await p.setViewportSize({ width: 1300, height: 600 });
	await open(p, 'index.php?p=home');
	await p.mouse.wheel(0, 1500); await p.waitForTimeout(400);
	const r = await p.evaluate(() => ({ y: scrollY, top: document.querySelector('.nuHomeSearchRow').getBoundingClientRect().top, nav: document.querySelector('.nu-navbar').getBoundingClientRect().bottom }));
	expect(r.y > 200 && Math.abs(r.top - Math.round(r.nav)) <= 2, 'search row below the top bar: ' + JSON.stringify(r));
	await p.setViewportSize({ width: 390, height: 700 });
	await open(p, 'index.php?p=home');
	await p.mouse.wheel(0, 1500); await p.waitForTimeout(400);
	const m = await p.evaluate(() => document.querySelector('.nuHomeSearchRow').getBoundingClientRect().bottom);
	expect(m < 0, 'phones: the row scrolls away (' + m + ')');
	await p.setViewportSize({ width: 1300, height: 900 });
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

// 0.8.774: the Home button of the top bar puts the cursor into the start page search (globeadmin and a user's tile page); a plain visit does not
T.home_btn_focus = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=users');
	await Promise.all([p.waitForNavigation(), p.click('.nu-navbar .navbar-brand')]);   // with a logo: the logo
	await p.waitForTimeout(300);
	expect(await p.evaluate(() => document.activeElement && document.activeElement.id) === 'nuHomeSearch', 'logo: focus in the search');
	// touch screens (phone): no focus – the on-screen keyboard would pop up
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 800 }, isMobile: true, hasTouch: true, storageState: await p.context().storageState() });
	try {
		const m = await ctx.newPage();
		await m.goto(BASE + 'index.php?p=users'); await m.waitForLoadState('networkidle');
		await m.evaluate(() => sessionStorage.setItem('nunext-home-focus', '1'));
		await m.goto(BASE + 'index.php?p=home'); await m.waitForLoadState('networkidle'); await m.waitForTimeout(300);
		expect(await m.evaluate(() => document.activeElement && document.activeElement.id) !== 'nuHomeSearch', 'phone: search focused (keyboard pops up)');
		expect(await m.evaluate(() => window.nuHomeReset(location.href) && document.activeElement && document.activeElement.id) !== 'nuHomeSearch', 'phone: Home on the start page focused the search');
	} finally { await ctx.close(); }
	// 0.8.1046: a phone that reports a mouse-like pointer (hover / fine) but has a touch screen – no focus either
	const ctx2 = await p.context().browser().newContext({ viewport: { width: 390, height: 800 }, screen: { width: 390, height: 800 }, isMobile: false, hasTouch: true, storageState: await p.context().storageState() });
	try {
		const m = await ctx2.newPage();
		await m.addInitScript(() => {                         // such a phone: hover / fine pointer reported although it is a touch screen
			const mm = window.matchMedia.bind(window);
			window.matchMedia = (q) => { const r = mm(q); return /hover: hover/.test(q) ? { matches: true, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} } : r; };
		});
		await m.goto(BASE + 'index.php?p=users'); await m.waitForLoadState('networkidle');
		await Promise.all([m.waitForNavigation(), m.evaluate(() => { const b = document.getElementById('nuHomeBtn') || document.querySelector('.navbar-brand'); b.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 })); if (!b.href) location.href = 'index.php?p=home'; })]);
		await m.waitForLoadState('networkidle'); await m.waitForTimeout(300);
		expect(await m.evaluate(() => [window.nuHasKeyboard(), document.activeElement && document.activeElement.id].join()) === 'false,', 'touch phone with hover: ' + await m.evaluate(() => [window.nuHasKeyboard(), matchMedia('(hover: hover) and (pointer: fine)').matches, navigator.maxTouchPoints, document.activeElement && document.activeElement.id].join()));
	} finally { await ctx2.close(); }
	const had = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'") !== '0';
	if (had) return;                                         // compact bar only on a site without own branding
	sql(`INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('branding', '{"logo_show":"login"}')`);
	try { await home_btn_compact(s); } finally { sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'"); }
};

// 0.8.702: settings centre – overview, side bar on the editor pages, search over every setting (also nuconfig.php variables and
// nuBuilder Forte's settings), the simple settings edited in place (audit, notifications, backups, GitHub)
T.settings_hub = async (s) => {
	const p = s.ga;
	const keep = path.join(os.tmpdir(), 'nunext-e2e-sethub.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_setting > ' + keep);
	try {
		await open(p, 'index.php?p=settings');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=settings'); }
		const secs = await p.$$eval('#shOverview [data-sh]', e => e.map(x => x.getAttribute('data-sh')));
		expect(['general', 'look', 'security', 'mail', 'data', 'code', 'updates', 'forte', 'configfile'].every(x => secs.includes(x)), 'sections: ' + secs.join(','));
		for (const r of ['branding', 'themes', 'securitycheck', 'globalcode', 'configfile', 'email&tab=settings']) {
			await open(p, 'index.php?p=' + r);
			expect(await p.locator('.nuSetFrame .nuSetNav .nav-link.active').count() >= 1, 'frame on ' + r);
		}
		// search: a security setting, a nuconfig.php variable, a nuBuilder Forte setting
		await open(p, 'index.php?p=settings');
		const search = async (q) => { await p.fill('#nuSetSearch', ''); await p.type('#nuSetSearch', q); await p.waitForTimeout(500);
			return p.$$eval('#nuSetResults a', a => a.map(x => x.getAttribute('href'))); };
		let h = await search('reauth');
		expect(h.some(u => /p=securitycheck#sec_reauth_minutes/.test(u)), 'security setting found: ' + h.slice(0, 3).join(' '));
		h = await search('nuConfigDBHost');
		expect(h.some(u => /p=configfile&find=nuConfigDBHost/.test(u)), 'nuconfig variable found: ' + h.slice(0, 3).join(' '));
		h = await search('nuDebugMode');
		expect(h.some(u => /s=forte&q=nuDebugMode/.test(u)), 'Forte setting found: ' + h.slice(0, 3).join(' '));
		await p.keyboard.press('Enter'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
		expect(/s=forte/.test(p.url()) && await p.evaluate(() => document.getElementById('setFind').value) === 'nuDebugMode', 'Forte list searched: ' + p.url());
		// in place: audit keep days, notifications, backups, GitHub
		await open(p, 'index.php?p=settings&s=data');
		await p.selectOption('#shAudKeep', '365');
		await Promise.all([p.waitForNavigation(), p.click('#shDataSave')]);
		expect(JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'audit'") || '{}').keep_days === 365, 'audit keep days');
		await p.selectOption('#shNtfKeep', '30');
		await Promise.all([p.waitForNavigation(), p.click('#shDataSave')]);
		expect(JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'notify'") || '{}').keep_days === 30, 'notification keep days');
		await p.fill('#shBkKeep', '7'); await p.check('#shBkModeAge'); await p.fill('input[name=days]', '45');
		await Promise.all([p.waitForNavigation(), p.click('#shDataSave')]);
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'backup_keep_sql'") === '7'
			&& JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'backup_cleanup'") || '{}').n === 45, 'backup rules');
		await open(p, 'index.php?p=settings&s=updates');
		await p.selectOption('#shGhEvery', 'week');
		await Promise.all([p.waitForNavigation(), p.click('#shGhSave')]);
		expect(/"every":"week"/.test(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'")), 'GitHub check every week');
		// the user menu no longer has "for everyone"; the setup wizard links to the settings
		expect(await p.locator('.nu-navbar #nuSidebarDefault').count() === 0, 'user menu without the default');
		await open(p, 'index.php?p=setupwizard&step=basics');
		if (await p.locator('#wizForm').count()) expect(/s=general/.test(await p.getAttribute('#wizInSettings', 'href') || ''), 'wizard link');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(keep) }); fs.unlinkSync(keep);
	}
};

T.settings_used = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=settings&s=forte');
	if (!await p.locator('#setTable').count()) { s.skip = 'no settings page'; return; }
	const st = await p.evaluate(() => ({ checked: (document.querySelector('#setStatus input:checked') || {}).value,
		shown: [...document.querySelectorAll('#setTable tbody tr[data-category]')].filter(r => !r.hidden).map(r => r.getAttribute('data-status')) }));
	expect(st.checked === 'next', 'opens on "Used": ' + st.checked);
	expect(st.shown.length > 0 && st.shown.every(x => x === 'next'), 'only used settings shown: ' + st.shown.join(','));
	await p.click('label[for="setSt_all"]');
	const all = await p.locator('#setTable tbody tr[data-category]:not([hidden])').count();
	expect(all > st.shown.length, 'All shows more rows');
};

// start page (0.8.786): Home / the title of the top bar on the start page itself resets it in place – no reload
T.home_btn_inplace = async (s) => {
	const p = s.ga;
	const homeLink = async () => (await p.locator('#nuHomeBtn').count()) ? '#nuHomeBtn' : '.nu-navbar .navbar-brand';   // Home button (no logo) or the logo / title
	await open(p, 'index.php?p=home');
	await p.evaluate(() => { window.__nuMark = 1; });
	await p.fill('#nuHomeSearch', 'user'); await p.waitForTimeout(300);
	await p.mouse.wheel(0, 800); await p.waitForTimeout(300);
	await p.click(await homeLink()); await p.waitForTimeout(700);
	const r = await p.evaluate(() => ({ mark: window.__nuMark, q: document.getElementById('nuHomeSearch').value, focus: document.activeElement && document.activeElement.id, y: window.scrollY }));
	expect(r.mark === 1 && r.q === '' && r.focus === 'nuHomeSearch' && r.y < 5, 'Home on the start page: ' + JSON.stringify(r));
	await open(p, 'index.php?p=users');
	await Promise.all([p.waitForNavigation(), p.click(await homeLink())]);
	expect(/p=home/.test(p.url()), 'from another page: the start page loads');
};

// a link to the open page (0.8.787): the active tab / menu entry does not load the page again; other tabs still do
T.active_link_noreload = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=email&tab=test');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=email&tab=test'); }
	await p.evaluate(() => { window.__nuMark = 1; });
	await p.click('.nav-tabs a.nav-link.active'); await p.waitForTimeout(600);
	expect(await p.evaluate(() => window.__nuMark) === 1, 'active tab: no reload');
	await open(p, 'index.php?p=email');                                    // the side bar's E-mail entry points here
	await p.evaluate(() => { window.__nuMark = 1; });
	const side = p.locator('.nuSetNav a.nav-link.active').last();
	if (await side.count()) { await side.click(); await p.waitForTimeout(600); expect(await p.evaluate(() => window.__nuMark) === 1, 'active entry of the settings side bar: no reload'); }
	await p.click('.nav-tabs a.nav-link.active'); await p.waitForTimeout(600);
	expect(await p.evaluate(() => window.__nuMark) === 1, 'tab Settings of index.php?p=email: no reload');
	await Promise.all([p.waitForNavigation(), p.click('.nav-tabs a.nav-link[href*="tab=log"]')]);
	expect(/tab=log/.test(p.url()), 'another tab still loads');
};

T.content_sheet = async (s) => {
	// 0.8.1053: from 1680 px the content sits in one framed sheet (max 1600 px) on a darker page; narrower windows and design mode unchanged
	const p = s.ga, vp = p.viewportSize();
	try {
	const look = () => p.evaluate(() => { const m = document.getElementById('main'), cs = getComputedStyle(m);
		return { border: parseFloat(cs.borderTopWidth), w: Math.round(m.getBoundingClientRect().width), main: cs.backgroundColor, body: getComputedStyle(document.body).backgroundColor }; });
	await p.setViewportSize({ width: 2200, height: 900 });
	for (const u of ['index.php?p=users', 'index.php?p=settings', 'index.php?p=form&f=nuuserhome']) {
		await open(p, u);
		const l = await look();
		expect(l.border === 1 && l.w === 1600 && l.main !== l.body, 'no sheet on ' + u + ': ' + JSON.stringify(l));
	}
	await p.setViewportSize({ width: 1500, height: 900 });
	await open(p, 'index.php?p=users');
	{ const l = await look(); expect(l.border === 0, 'sheet below 1680 px: ' + JSON.stringify(l)); }
	} finally { await p.setViewportSize(vp); }
};

T.settings_aud_keep_width = async (s) => {
	// 0.8.953: Settings → Data & logs – the audit trail's Keep select is at most 170 px wide
	const p = s.ga;
	await open(p, 'index.php?p=settings&s=data'); await p.waitForSelector('#shAudKeep');
	const w = await p.evaluate(() => document.getElementById('shAudKeep').getBoundingClientRect().width);
	expect(w > 80 && w <= 170, 'Keep width: ' + w);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.home_rows_first_paint = async (s) => {
	// 0.8.995: Recently used is drawn by the server (not hidden until nuhome.js), and a folded row is folded before nuhome.js runs
	const p = s.ga, keep = path.join(os.tmpdir(), 'nunext-e2e-rowspaint.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_list_view zzzzsys_nunext_setting zzzzsys_nunext_recent > ' + keep);
	try {
		await open(p, 'index.php?p=home');
		await homeTab(p, 'appearance');
		if (!(await p.locator('#nuHomeVp_recentStrip').isChecked())) { await p.click('label[for="nuHomeVp_recentStrip"]'); await p.waitForTimeout(800); }
		await open(p, 'index.php?p=permissions');
		await p.evaluate(() => { localStorage.removeItem('nunext-home-strip-recent'); localStorage.removeItem('nunext-home-strip-auto-recent'); });
		await p.route(/nuhome(\.min)?\.js/, (r) => r.abort());
		await p.goto(BASE + 'index.php?p=home'); await p.waitForTimeout(300);
		const shown = await p.evaluate(() => { const r = document.querySelector('[data-nu-strip="recent"]'); const c = r && r.querySelector('.nuHomeChips');
			return !!r && !r.hidden && !!c && c.offsetHeight > 0; });
		expect(shown, 'Recently used not shown before nuhome.js');
		await p.evaluate(() => localStorage.setItem('nunext-home-strip-recent', 'folded'));
		await p.goto(BASE + 'index.php?p=home'); await p.waitForTimeout(300);
		const folded = await p.evaluate(() => { const c = document.querySelector('[data-nu-strip="recent"] .nuHomeChips'); return !!c && c.offsetHeight === 0; });
		expect(folded, 'saved fold not applied before nuhome.js');
		await p.unroute(/nuhome(\.min)?\.js/);
		await p.goto(BASE + 'index.php?p=home'); await p.waitForTimeout(500);
		const after = await p.evaluate(() => ({ cls: document.documentElement.classList.contains('nuHomeFold-recent'), f: document.querySelector('[data-nu-strip="recent"]').classList.contains('nuHomeStripFolded') }));
		expect(!after.cls && after.f, 'nuhome.js takes over the fold: ' + JSON.stringify(after));
		await p.click('[data-nu-strip="recent"] .nuHomeStripToggle'); await p.waitForTimeout(200);
		expect(await p.evaluate(() => document.querySelector('[data-nu-strip="recent"] .nuHomeChips').offsetHeight > 0), 'unfold by a click');
	} finally {
		await p.unroute(/nuhome(\.min)?\.js/).catch(() => {});
		await p.evaluate(() => localStorage.removeItem('nunext-home-strip-recent')).catch(() => {});
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.recent_fav = async (s) => {
	// 0.8.955: right-click on a Recently used chip → Add to favourites / Remove from favourites
	const p = s.ga, keep = path.join(os.tmpdir(), 'nunext-e2e-recentfav.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_list_view zzzzsys_nunext_setting zzzzsys_nunext_recent > ' + keep);
	try {
		sql('DELETE FROM zzzzsys_nunext_recent');
		await open(p, 'index.php?p=home');
		await homeTab(p, 'appearance');
		if (!(await p.locator('#nuHomeVp_recentStrip').isChecked())) { await p.click('label[for="nuHomeVp_recentStrip"]'); await p.waitForTimeout(800); }
		await open(p, 'index.php?p=permissions');
		await open(p, 'index.php?p=home');
		const chip = '[data-nu-strip="recent"] [data-nu-recent="permissions"]';
		if (await p.locator(chip + '[data-nu-canfav="1"]').count() === 0) { s.skip = 'permissions not a tile of this layout'; return; }
		const wasFav = await p.locator(chip).getAttribute('data-nu-fav') === '1';
		await p.locator(chip).click({ button: 'right' }); await p.waitForTimeout(200);
		const item = p.locator('.nuHomeCtx .dropdown-item').first();
		expect(await item.locator(wasFav ? '.bi-star' : '.bi-star-fill').count() === 1, 'no favourites entry in the chip menu');
		await Promise.all([p.waitForNavigation(), item.click()]); await p.waitForLoadState('networkidle');
		const inFav = await p.locator('[data-nu-strip="fav"] [data-nu-chip="permissions"]').count() > 0;
		expect(inFav === !wasFav, 'favourite not toggled (was ' + wasFav + ')');
		expect(await p.locator(chip).getAttribute('data-nu-fav') === (wasFav ? null : '1'), 'chip does not show the new state');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.flash_autoclose = async (s) => {
	// 0.8.1119: a "done" message at the top closes by itself (time by its length), holds while the pointer is on it; errors stay
	const p = s.ga, keep = path.join(os.tmpdir(), 'nunext-e2e-flashauto.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_list_view zzzzsys_nunext_setting > ' + keep);
	try {
		await open(p, 'index.php?p=procedures&view=compact');
		await p.click('.nuLvMenuBtn'); await p.waitForTimeout(200);
		const form = p.locator('.dropdown-menu.show form:has(input[name="action"][value="my_default"]):not(:has(input[name="clear"]))').first();
		if (await form.count() === 0) { s.skip = 'no "Set as my default" in the view menu'; return; }
		await Promise.all([p.waitForNavigation(), form.locator('button[type=submit]').first().click()]);
		const al = p.locator('.alert.alert-success[data-nu-autoclose]');
		expect(await al.count() === 1, 'the message says when it closes');
		const ms = Number(await al.getAttribute('data-nu-autoclose'));
		expect(ms >= 4000 && ms <= 12000 && await al.getAttribute('role') === 'status', 'time by length: ' + ms);
		await al.hover(); await p.waitForTimeout(ms + 800);
		expect(await al.count() === 1, 'it stays while the pointer is on it');
		await p.mouse.move(5, 600); await p.waitForTimeout(ms + 1200);
		expect(await p.locator('.alert.alert-success').count() === 0, 'it closes by itself after ' + ms + ' ms');
		// an error stays
		const html = await (await p.request.get(BASE + 'index.php?p=pageno&n=99999&back=' + encodeURIComponent('index.php?p=home'))).text();
		expect(/class="alert alert-(warning|danger|info)[^"]*"[^>]*>/.test(html) && !/alert-(warning|danger)[^>]*data-nu-autoclose/.test(html), 'a warning / error has no timer');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.recent_no_fav = async (s) => {
	// 0.8.1115: Customise → Appearance → "Without favourites" below Recently used: a page that is a favourite is left out of the row (and of the side bar)
	const p = s.ga, keep = path.join(os.tmpdir(), 'nunext-e2e-recentnofav.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_list_view zzzzsys_nunext_setting zzzzsys_nunext_recent > ' + keep);
	const chip = '[data-nu-strip="recent"] [data-nu-recent="permissions"]', sw = async (id, on) => {
		if ((await p.locator('#nuHomeVp_' + id).isChecked()) !== on) { await p.click('label[for="nuHomeVp_' + id + '"]', { timeout: 6000 }).catch((e) => { throw new Error('switch ' + id + ' → ' + on + ' not possible'); }); await p.waitForTimeout(1100); }
	};
	const off = (sel) => p.evaluate((q) => { const e = document.querySelector(q); return !e || e.hidden; }, sel);   // the hidden attribute: the row may be folded
	try {
		sql('DELETE FROM zzzzsys_nunext_recent');
		await open(p, 'index.php?p=home'); await homeTab(p, 'appearance');
		await sw('favRow', true); await sw('recentStrip', true);
		expect(await p.locator('#nuHomeVp_recentNoFav').count() === 1 && !(await p.locator('#nuHomeVp_recentNoFav').isChecked()), 'the switch exists and is off by default');
		expect(/Without favourites|Ohne Favoriten/.test(await p.textContent('label[for="nuHomeVp_recentNoFav"]')), 'the switch has its text');
		// 0.8.1116: the explanation only behind the (i); the panel does not scroll sideways; a click on (i) leaves the switch as it is
		const pv = await p.evaluate(() => { const l = document.querySelector('label[for="nuHomeVp_recentNoFav"]'); let sc = l.parentElement; while (sc && sc.scrollWidth <= sc.clientWidth && sc.id !== 'nuHomeViewPanel') sc = sc.parentElement;
			return { small: !!l.querySelector('small'), tip: !!l.querySelector('.nuInfoTip[title], .nuInfoTip[data-bs-original-title]'), over: !!sc && sc.scrollWidth > sc.clientWidth + 1, right: l.querySelector('input').getBoundingClientRect().right <= document.querySelector('#nuHomeVp_recentStrip').getBoundingClientRect().right + 1 }; });
		expect(!pv.small && pv.tip && !pv.over && pv.right, 'explanation as (i), no sideways scrolling: ' + JSON.stringify(pv));
		await p.click('label[for="nuHomeVp_recentNoFav"] .nuInfoTip'); await p.waitForTimeout(300);
		expect(!(await p.locator('#nuHomeVp_recentNoFav').isChecked()) && await p.locator('.tooltip.show').count() > 0, 'a click on (i) shows the text and does not flip the switch');
		await p.keyboard.press('Escape'); await p.mouse.move(5, 400);
		await open(p, 'index.php?p=permissions'); await open(p, 'index.php?p=access'); await open(p, 'index.php?p=home');
		if (await p.locator(chip + '[data-nu-canfav="1"]').count() === 0) { s.skip = 'permissions not a tile of this layout'; return; }
		if (await p.locator(chip).getAttribute('data-nu-fav') !== '1') {                        // make it a favourite
			await p.locator(chip).click({ button: 'right' }); await p.waitForTimeout(200);
			await Promise.all([p.waitForNavigation(), p.locator('.nuHomeCtx .dropdown-item').first().click()]); await p.waitForLoadState('networkidle');
		}
		expect(!(await off(chip)) && await p.locator('[data-nu-strip="fav"] [data-nu-chip="permissions"]').count() > 0, 'favourite shown in both rows while the option is off');
		const before = Number(await p.textContent('[data-nu-strip="recent"] .nuHomeStripCount'));
		await homeTab(p, 'appearance');
		// (the first star on the built-in layout stores a copy of it: the personal view – Recently used on – must come along)
		expect(await p.locator('#nuHomeVp_recentStrip').isChecked(), 'the personal view was lost when the layout was stored');
		await sw('recentNoFav', true);
		expect(await off(chip) && !(await off('[data-nu-strip="recent"] [data-nu-recent="access"]')), 'the favourite leaves Recently used at once, others stay');
		expect(Number(await p.textContent('[data-nu-strip="recent"] .nuHomeStripCount')) === before - 1, 'the count of the row follows');
		// kept after a reload, drawn by the server (no flash): hidden attribute in the page source
		const html = await (await p.request.get(BASE + 'index.php?p=home')).text();
		expect(/data-nu-recent="permissions"[^>]* hidden/.test(html), 'the server does not hide the favourite');
		await open(p, 'index.php?p=home');
		expect(await off(chip), 'hidden after a reload');
		// side bar: the same
		const sb = await (await p.request.get(BASE + 'index.php?p=users')).text();
		if (/nuSbSec-recent/.test(sb)) {
			const rec = sb.split('nuSbSec-recent')[1].split('</section>')[0];
			expect(!/p=permissions\b/.test(rec) && /p=access\b/.test(rec), 'side bar: Recently used still lists the favourite');
		}
		// the switch is off (greyed) while the Favourites row is hidden, and the page shows again
		await homeTab(p, 'appearance'); await sw('favRow', false);
		expect(await p.locator('#nuHomeVp_recentNoFav').isDisabled() && !(await off(chip)), 'without the Favourites row nothing is left out');
		await sw('favRow', true); await sw('recentNoFav', false);
		expect(!(await off(chip)), 'switched off: the favourite is back in Recently used');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.back_trail = async (s) => {
	// 0.8.1017: user form (i) → "Changes to this user (log)" opens the log with its filter set but folded (?nf=1); Back leads to
	// the page before (sessionStorage nunext-trail), not to the fixed parent; the same page again (paging) does not add a step
	const p = s.ga;
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name LIKE 'sample_%' ORDER BY sus_login_name LIMIT 1");
	if (!uid) { s.skip = 'no sample users'; return; }
	await open(p, 'index.php?p=users');
	await open(p, 'index.php?p=user&id=' + uid);
	const userUrl = await p.evaluate(() => location.pathname + location.search);
	const link = await p.evaluate(() => [...document.querySelectorAll('a')].map(a => a.getAttribute('href') || '').find(h => /p=log/.test(h) && /[?&]q=/.test(h)) || '');
	expect(/[?&]nf=1/.test(link), 'record info link without nf=1: ' + link);
	await open(p, link.replace(/^\/?/, ''));
	const f = await p.evaluate(() => { const c = document.getElementById('nuLvFilters'); return c ? {show: c.classList.contains('show'), open: !!(c.offsetWidth || c.offsetHeight), min: c.getAttribute('data-nu-open-min')} : null; });
	expect(f && !f.show && !f.open && !f.min, 'log filters shown at first: ' + JSON.stringify(f));
	let back = await p.evaluate(() => document.querySelector('a.nuBackBtn')?.getAttribute('href'));
	expect(back === userUrl, 'Back of the log is not the user form: ' + back + ' / ' + userUrl);
	// the same page again (search / page 2) is no extra step
	await open(p, 'index.php?p=log&q=zz_no_hit_e2e');
	back = await p.evaluate(() => document.querySelector('a.nuBackBtn')?.getAttribute('href'));
	expect(back === userUrl, 'a second log page became a step: ' + back);
	await Promise.all([p.waitForNavigation(), p.click('a.nuBackBtn')]);
	expect(/p=user&id=/.test(p.url()), 'Back did not open the user form: ' + p.url());
	// back on the user form: its Back leads to the users list (the page before it), the log is no longer in the trail
	back = await p.evaluate(() => [...document.querySelectorAll('a.btn.nuBtnAct')].find(a => a.firstElementChild?.classList.contains('bi-arrow-left'))?.getAttribute('href'));
	expect(/p=users(&|$)/.test(back || ''), 'user form Back: ' + back);
	// form runtime: a list → record → the record's Back = that list page as it was (here page 2 / a search)
	await open(p, 'index.php?p=form&f=samplecustomer&q=a');
	const rid = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1");
	if (rid) {
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + rid);
		back = await p.evaluate(() => document.getElementById('nuBackButton')?.getAttribute('href'));
		expect(/f=samplecustomer/.test(back || '') && /[?&]q=a/.test(back || ''), 'form Back: ' + back);
		// a plain link to a page visited before is a new step, not a jump back: Back of the users list = this record
		await open(p, 'index.php?p=users');
		back = await p.evaluate(() => document.querySelector('a.nuBackBtn')?.getAttribute('href'));
		expect(/f=samplecustomer&r=/.test(back || ''), 'users list opened again: Back ' + back);
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.1225: start page → list → record → Back → Back ends on the start page (a record's Back whose link already was the list
// counted as a step forward, so Back on the list led to the record again)
T.back_trail_home = async (s) => {
	const p = await s.ga.context().newPage();
	try {
		const clickTo = async (sel) => Promise.all([p.waitForNavigation(), p.locator(sel).first().evaluate((e) => e.click())]);
		const where = () => new URL(p.url()).searchParams.get('p') || 'home';
		await p.goto(BASE + 'index.php?p=home');
		const flows = [['a[href$="p=access"]', 'access', 'tr[data-href]', 'accesslevel', 'a.btn.nuBtnAct:has(> .bi-arrow-left:first-child)']];
		if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") === '1') flows.push(['a[href$="p=users"]', 'users', 'tr[data-href]', 'user', 'a.btn.nuBtnAct:has(> .bi-arrow-left:first-child)']);
		for (const [tile, list, row, rec, recBack] of flows) {
			await p.goto(BASE + 'index.php?p=home');
			await clickTo(tile); expect(where() === list, 'tile → ' + p.url());
			await clickTo(row); expect(where() === rec, 'row → ' + p.url());
			await clickTo(recBack); expect(where() === list, rec + ' Back → ' + p.url());
			await clickTo('a.nuBackBtn'); expect(where() === 'home', list + ' Back → ' + p.url());
		}
		// form runtime: list → record → Back → Back
		if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") === '1') {
			await p.goto(BASE + 'index.php?p=home');
			await p.evaluate(() => { const a = document.createElement('a'); a.href = 'index.php?p=form&f=samplecustomer'; a.id = 'e2eGo'; document.body.appendChild(a); });
			await clickTo('#e2eGo');
			await clickTo('tr.nuBrowseRow');
			expect(new URL(p.url()).searchParams.get('r'), 'record not opened: ' + p.url());
			await clickTo('#nuBackButton'); expect(!new URL(p.url()).searchParams.get('r') && /f=samplecustomer/.test(p.url()), 'form Back → ' + p.url());
			const b2 = await p.evaluate(() => { const a = document.querySelector('a#nuBackButton, a.nuBackBtn'); return a && a.getAttribute('href'); });
			expect(/p=home/.test(b2 || ''), 'Back of the list does not lead home: ' + b2);
		}
	} finally { await p.close(); }
};

// 0.8.1226 / 0.8.1227: page width – per device (start page → Customise → Appearance, localStorage) and the default of Settings → Look (<html data-nu-pw>)
T.page_width = async (s) => {
	const p = await s.ga.context().newPage();
	const before = sql("SELECT COALESCE(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'page_width'");
	try {
		await p.setViewportSize({ width: 2560, height: 1000 });
		const w = () => p.evaluate(() => Math.round(document.getElementById('main').getBoundingClientRect().width));
		await p.goto(BASE + 'index.php?p=users');
		expect(await w() === 1600, 'standard width: ' + await w());
		expect(await p.locator('#nuPwSel').count() === 0, 'still in the user menu');
		// 0.8.1227: start page → Customise → Appearance → This device
		await p.goto(BASE + 'index.php?p=home'); await homeTab(p, 'appearance');
		await p.waitForSelector('#nuHomeVpPw', {state: 'visible'});
		await p.selectOption('#nuHomeVpPw', 'wide');
		const mw = () => p.evaluate(() => getComputedStyle(document.getElementById('main')).maxWidth);   // the open panel takes room beside the page
		expect(await mw() === '2400px' && await p.isVisible('#nuHomeVpPw'), 'wide at once, panel open: ' + await mw());
		await p.selectOption('#nuHomeVpPw', 'custom'); await p.fill('#nuHomeVpPwPx', '2000'); await p.press('#nuHomeVpPwPx', 'Enter');
		expect(await mw() === '2000px', 'custom 2000: ' + await mw());
		await p.goto(BASE + 'index.php?p=home'); await homeTab(p, 'appearance');
		expect(await p.inputValue('#nuHomeVpPw') === 'custom' && await p.inputValue('#nuHomeVpPwPx') === '2000', 'the panel shows the choice of this device');
		// the device's choice before the first paint, also on form pages
		await p.addInitScript(() => { window.__pw = []; const t = () => { const m = document.getElementById('main'); if (m) window.__pw.push(Math.round(m.getBoundingClientRect().width)); if (window.__pw.length < 30) requestAnimationFrame(t); }; requestAnimationFrame(t); });
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer'); await p.waitForTimeout(800);
		const seen = await p.evaluate(() => window.__pw);
		expect(seen.length && seen.every((x) => x === 2000), 'form page not at 2000 from the first paint: ' + seen.slice(0, 5).join());
		// the default of Settings → Look; "Default" in the menu follows it
		await p.evaluate(() => localStorage.removeItem('nunext-page-width'));
		await p.goto(BASE + 'index.php?p=settings&s=look');
		await p.selectOption('#shPwMode', 'full');
		await Promise.all([p.waitForNavigation(), p.click('#shPwSave')]);
		await p.goto(BASE + 'index.php?p=home'); await homeTab(p, 'appearance');
		expect(/Full width|Volle Breite/.test(await p.$eval('#nuHomeVpPw option', (o) => o.textContent)), '"Default" names the default');
		await p.goto(BASE + 'index.php?p=users');
		const full = await p.evaluate(() => ({ w: Math.round(document.getElementById('main').getBoundingClientRect().width), vw: document.documentElement.clientWidth, attr: document.documentElement.getAttribute('data-nu-pw'), sheet: getComputedStyle(document.getElementById('main')).borderTopStyle }));
		expect(full.attr === 'full' && full.w >= full.vw - 20 && full.sheet === 'none', 'default full width: ' + JSON.stringify(full));
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'page_width_changed'") !== '0', 'change not logged');
	} finally {
		await p.close();
		if (before === '') sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'page_width'");
		else sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + before.replace(/'/g, "''") + "' WHERE zzzzsys_nunext_setting_id = 'page_width'");
	}
};

// 0.8.1237: user menu → System info… – dialog with nuBuilder Next, server, database (?p=sysinfo) and the browser, Copy for support
T.system_info = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home');
	expect(await p.locator('#nuVersionLink').count() === 0, 'old version link still there');
	await p.click('.nuUserBtn'); await p.click('#nuSysInfoBtn');
	await p.waitForSelector('#nuSysInfoDlg.show .nuSiSec[data-si="browser"]');
	const r = await p.evaluate(() => {
		const sec = (id) => { const x = document.querySelector('.nuSiSec[data-si="' + id + '"]'); return x ? x.innerText : ''; };
		return { next: sec('next'), server: sec('server'), db: sec('db'), browser: sec('browser') };
	});
	expect(r.next.includes(await p.evaluate(() => document.querySelector('meta[name=nu-version]')?.content || '') || '0.8.'), 'version: ' + r.next);
	expect(/PHP\s*\n?\s*8\./.test(r.server) && /(MariaDB|MySQL) \d/.test(r.db) && /\d+ × \d+/.test(r.browser), 'sections: ' + JSON.stringify(r));
	const all = Object.values(r).join(' ');
	expect(!/nu\b.*password|127\.0\.0\.1|DBPassword/i.test(all), 'secrets or the host shown');
	await p.click('#nuSysInfoCopy'); await p.waitForTimeout(200);
	const copied = await p.getAttribute('#nuSysInfoDlg', 'data-copied');
	expect(/nuBuilder Next\n  .+: 0\.8\./.test(copied || '') && /PHP: 8\./.test(copied || ''), 'copied text: ' + (copied || '').slice(0, 200));
	await p.keyboard.press('Escape');
	// JSON only for admins with the right update
	const u = await login('sample_ben', UPW);
	try {
		const st = await u.evaluate(async () => (await fetch('index.php?p=sysinfo')).status);
		expect(st === 403 || st === 302 || st === 200 && false, 'sysinfo for a user without the right: ' + st);
		expect(await u.locator('#nuSysInfoBtn').count() === 0, 'menu entry shown to a user');
	} finally { await u.context().close(); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.settings_compact = async (s) => {
	// 0.8.1240 (the owner picked mockup A): switch "Compact" next to the title of the settings overview / sections hides the descriptions;
	// per device (localStorage nunext-settings-compact), applied before the first paint (nutheme.js)
	const p = s.ga;
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 844 }, storageState: await p.context().storageState() });
	const m = await ctx.newPage();
	try {
		await m.goto(BASE + 'index.php?p=settings');
		const shown = () => m.evaluate(() => [...document.querySelectorAll('.nuSetDesc')].filter(e => e.offsetParent).length);
		const before = await shown();
		if (!before) throw new Error('no descriptions shown at first');
		const h0 = await m.evaluate(() => document.getElementById('shOverview').offsetHeight);
		await m.click('label[for=nuSetCompact]');
		const after = await shown(), h1 = await m.evaluate(() => document.getElementById('shOverview').offsetHeight);
		if (after || h1 >= h0) throw new Error('compact did not hide: ' + JSON.stringify({ after, h0, h1 }));
		const side = await m.evaluate(() => { const h = document.querySelector('.nuSetHead h1').getBoundingClientRect(), w = document.querySelector('.nuSetCompactSw').getBoundingClientRect(); return Math.abs((h.top + h.bottom) / 2 - (w.top + w.bottom) / 2) < 16; });
		if (!side) throw new Error('switch not next to the title on a phone');
		// remembered: a section page, scripts blocked = the class comes from the head
		await m.route(/nusettingshub(\.min)?\.js/, r => r.abort());
		await m.goto(BASE + 'index.php?p=settings&s=general');
		const sec = await m.evaluate(() => ({ cls: document.documentElement.classList.contains('nuSetCompact'), desc: [...document.querySelectorAll('.nuSetDesc')].filter(e => e.offsetParent).length }));
		if (!sec.cls || sec.desc) throw new Error('not kept: ' + JSON.stringify(sec));
		await m.unroute(/nusettingshub(\.min)?\.js/);
		await m.reload();
		if (!(await m.isChecked('#nuSetCompact'))) throw new Error('switch not checked after reload');
		await m.click('label[for=nuSetCompact]');
		const off = await m.evaluate(() => [localStorage.getItem('nunext-settings-compact'), [...document.querySelectorAll('.nuSetDesc')].filter(e => e.offsetParent).length]);
		if (off[0] !== null || !off[1]) throw new Error('switch off failed: ' + JSON.stringify(off));
		s.result = { before, h0, h1, sec };
	} finally { await ctx.close(); }
};

T.home_no_twin_tiles = async (s) => {
	// 0.8.934: "Clone form" pinned from the Forms ▾ menu and the tile Form cloner open the same page – the start page shows it once
	const p = s.ga;
	const keep = path.join(os.tmpdir(), 'nunext-e2e-twin.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_list_view > ' + keep);
	try {
		await open(p, 'index.php?p=home'); await p.waitForSelector('[data-nu-tile]');
		const has = async (k) => p.locator('[data-nu-tile="' + k + '"]').count();
		if (!(await has('s_form_clone'))) {
			const j = await p.evaluate(() => fetch(location.href, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('input[name=_csrf]').value}, body: JSON.stringify({action: 'pin', key: 's_form_clone'})}).then(r => r.json()));
			expect(j.ok && j.pinned, 'pinned: ' + JSON.stringify(j));
			await open(p, 'index.php?p=home'); await p.waitForSelector('[data-nu-tile]');
		}
		expect(await has('cloner') === 1 && await has('s_form_clone') === 0, 'one tile for the Form cloner: ' + await has('cloner') + ' / ' + await has('s_form_clone'));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.recent_tile_default_layout = async (s) => {
	// 0.8.940 (bug report): a start page tile opened while the built-in default layout is in use (custom tiles = an empty object) was not
	// recorded as recently used – "Cannot use object of type stdClass as array" in the server log
	const p = s.ga;
	const keep = path.join(os.tmpdir(), 'nunext-e2e-recentdef.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_nunext_list_view zzzzsys_nunext_recent zzzzsys_nunext_setting > ' + keep);
	try {
		sql("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = 'home'; DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'listview_defaults'; DELETE FROM zzzzsys_nunext_recent WHERE item = 'users'");
		await open(p, 'index.php?p=home'); await p.waitForSelector('[data-nu-tile="users"]');
		const st = await p.evaluate(async () => {
			const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value); fd.append('action', 'used'); fd.append('key', 'users');
			return (await fetch('index.php?p=sidebar', {method: 'POST', body: fd, credentials: 'same-origin'})).status;
		});
		expect(st < 500, 'status ' + st);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_recent WHERE item = 'users'") === '1', 'tile recorded as recently used');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

// 0.8.1205: the globeadmin's language survives a sign-in of another user in the same browser (setting lang_ga)
T.lang_ga_kept = async (s) => {
	const lang0 = sql("SELECT IFNULL(sus_language, '') FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	const set0 = sql("SELECT IFNULL(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'lang_ga'");
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 1300, height: 900 } });
	const p = await ctx.newPage();
	const signIn = async (u, pw) => {
		await p.goto(BASE + 'index.php?p=login'); await p.fill('#username', u); await p.fill('#password', pw);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('form button[type=submit]:not([name=demo_user])')]);
	};
	const signOut = async () => {
		await Promise.all([p.waitForURL(/p=login/), p.evaluate(() => { const f = document.createElement('form'); f.method = 'post'; f.action = 'index.php?p=logout';
			const i = document.createElement('input'); i.name = '_csrf'; i.value = document.querySelector('input[name=_csrf]').value; f.appendChild(i); document.body.appendChild(f); f.submit(); })]);
	};
	const htmlLang = () => p.evaluate(() => document.documentElement.lang);
	try {
		sql("UPDATE zzzzsys_user SET sus_language = 'German' WHERE sus_login_name = 'sample_anna'; DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		await signIn('globeadmin', 'nu');
		await p.goto(BASE + 'index.php?p=lang&l=en-GB&r=p%3Dhome', { referer: BASE + 'index.php?p=home' }); await p.waitForLoadState('networkidle');
		expect(/^en/.test(await htmlLang()), 'globeadmin not English: ' + await htmlLang());
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'lang_ga'") === 'en-GB', 'lang_ga not stored');
		await signOut();
		await signIn('sample_anna', UPW);
		expect(/^de/.test(await htmlLang()), 'user not German: ' + await htmlLang());
		await signOut();
		await signIn('globeadmin', 'nu');
		expect(/^en/.test(await htmlLang()), 'globeadmin got the language of the user before: ' + await htmlLang());
	} finally {
		sql(`UPDATE zzzzsys_user SET sus_language = ${lang0 === '' ? 'NULL' : "'" + lang0 + "'"} WHERE sus_login_name = 'sample_anna'`);
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'lang_ga'");
		if (set0) sql(`INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('lang_ga', '${set0}')`);
		await ctx.close();
	}
};

// 0.8.1310: Getting ready – bar below the top bar, start page card, the checklist page (steps, marks, hide for now / for good, red points bring it back)
T.getting_ready = async (s) => {
	const p = s.ga;
	const old = sql("SELECT IFNULL(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'getting_ready'");
	try {
		grSet(null);
		await p.evaluate(() => fetch('index.php?p=gettingready')).catch(() => {});   // the bar's count is kept in the session: the page refreshes it
		await open(p, 'index.php?p=home');
		expect(await p.locator('#nuGrCard').isVisible(), 'start page card missing');
		expect(await p.locator('#nuGrBar').count() === 0, 'bar on the start page (the card is there)');
		expect(await p.locator('#nuGrCard .nuGrNext').count() >= 1, 'next points on the card');
		// the hide menu of the card lies above the sticky search row (0.8.1311: it was half covered)
		await p.click('#nuGrCardHide');
		await p.waitForTimeout(250);
		const cover = await p.evaluate(() => [...document.querySelectorAll('#nuGrCard .dropdown-menu.show [data-gr-hide]')].map(b => {
			const r = b.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
			return b.contains(hit);
		}));
		expect(cover.length === 3 && cover.every(Boolean), 'card hide menu covered: ' + JSON.stringify(cover));
		await p.keyboard.press('Escape');
		await open(p, 'index.php?p=users');
		expect(await p.locator('#nuGrBar').isVisible(), 'bar missing on the users page');
		await open(p, 'index.php?p=gettingready');
		const count = async () => (await p.locator('#grCount').textContent()).match(/\d+/)[0];
		expect(await p.locator('.nuGrStep').count() >= 7, 'steps');
		expect(await p.locator('.nuGrStep.active').count() === 1, 'one active step');
		const before = +(await count());
		// a point ticked by hand
		await open(p, 'index.php?p=gettingready&g=security');
		const it = p.locator('[data-gr-item="pwpolicy"]');
		expect(await it.getAttribute('data-gr-st') === 'todo', 'manual point state');
		await Promise.all([p.waitForNavigation(), it.locator('[data-gr-mark="done"]').click()]);
		expect(await p.locator('[data-gr-item="pwpolicy"]').getAttribute('data-gr-mark') === 'done', 'not marked done');
		expect(+(await count()) === before + 1, 'count did not go up');
		await Promise.all([p.waitForNavigation(), p.locator('[data-gr-item="pwpolicy"] [data-gr-mark=""]').click()]);
		expect(+(await count()) === before, 'undo did not bring the count back');
		// "not needed" on an automatic point that is not green
		const auto = p.locator('.nuGrItem[data-gr-st="bad"], .nuGrItem[data-gr-st="warn"]').first();
		const autoId = await auto.getAttribute('data-gr-item');
		await Promise.all([p.waitForNavigation(), auto.locator('[data-gr-mark="na"]').click()]);
		expect(await p.locator(`[data-gr-item="${autoId}"]`).getAttribute('data-gr-mark') === 'na', 'not needed not stored');
		// Next / Back walk the groups
		await Promise.all([p.waitForNavigation(), p.click('#grNext')]);
		expect(await p.locator('#grPane').getAttribute('data-gr-group') === 'server', 'Next did not open the second group');
		await Promise.all([p.waitForNavigation(), p.click('#grBack')]);
		expect(await p.locator('#grPane').getAttribute('data-gr-group') === 'security', 'Back');
		// hide until the next sign-in (0.8.1312): only this session, nothing stored
		await open(p, 'index.php?p=users');
		await p.click('#nuGrBarHide');
		await Promise.all([p.waitForNavigation(), p.click('#nuGrBar [data-gr-hide="session"]')]);
		expect(await p.locator('#nuGrBar').count() === 0, 'bar still shown after hiding for the session');
		expect(sql("SELECT IFNULL(MAX(setting_value), '') FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'getting_ready'").indexOf('"hide":{') < 0, 'session hide stored in the setting');
		const p2 = await login(GA[0], GA[1]);
		await open(p2, 'index.php?p=users');
		expect(await p2.locator('#nuGrBar').isVisible(), 'a new sign-in does not show the bar again');
		await p2.close();
		await open(p, 'index.php?p=gettingready');
		await Promise.all([p.waitForNavigation(), p.click('#grShowAgain')]);
		await open(p, 'index.php?p=users');
		expect(await p.locator('#nuGrBar').isVisible(), 'Show again after the session hide');
		// hide for now from the bar: away on other pages and from the start page, note on the checklist
		await open(p, 'index.php?p=users');
		await p.click('#nuGrBarHide');
		await Promise.all([p.waitForNavigation(), p.click('#nuGrBar [data-gr-hide="later"]')]);
		expect(/p=users/.test(p.url()), 'did not return to the page: ' + p.url());
		expect(await p.locator('#nuGrBar').count() === 0, 'bar still shown after hiding');
		await open(p, 'index.php?p=home');
		expect(await p.locator('#nuGrCard').count() === 0, 'card still shown after hiding');
		const st = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'getting_ready'"));
		expect(st.hide.mode === 'later' && st.hide.until > Date.now() / 1000 + 6 * 86400, 'hide later stored: ' + JSON.stringify(st.hide));
		await open(p, 'index.php?p=gettingready');
		expect(await p.locator('#grHiddenNote').isVisible() && await p.locator('#grShowAgain').isVisible(), 'hidden note / Show again');
		await Promise.all([p.waitForNavigation(), p.click('#grShowAgain')]);
		await open(p, 'index.php?p=users');
		expect(await p.locator('#nuGrBar').isVisible(), 'Show again did not bring the bar back');
		// hidden for good, but a red security point that was not red then brings it back
		const red = await p.evaluate(() => fetch('index.php?p=gettingready&g=security').then(r => r.text()).then(t => (t.match(/data-gr-item="([a-z0-9]+)" data-gr-st="bad" data-gr-mark=""/) || [])[1] || ''));
		if (red) {
			grSet(JSON.stringify({ marks: {}, hide: { mode: 'forever', until: 0, bad: grIds().filter(x => x !== red) } }));
			await open(p, 'index.php?p=users');
			expect(await p.locator('#nuGrBar').isVisible(), 'a new red security point did not bring the bar back (' + red + ')');
			grSet(JSON.stringify({ marks: {}, hide: { mode: 'forever', until: 0, bad: grIds() } }));
			await open(p, 'index.php?p=users');
			expect(await p.locator('#nuGrBar').count() === 0, 'hidden for good still shows the bar');
		}
		// phone width: steps as one row, the page no wider than the screen
		await p.setViewportSize({ width: 390, height: 800 });
		await open(p, 'index.php?p=gettingready&g=look');
		const w = await p.evaluate(() => ({ doc: document.documentElement.scrollWidth, row: document.querySelector('.nuGrSteps ol').getBoundingClientRect().height }));
		expect(w.doc <= 390 && w.row < 80, 'phone layout: ' + JSON.stringify(w));
		expect(await p.locator('.nuGrStep.active').isVisible(), 'current step scrolled into view');
	} finally {
		await p.setViewportSize({ width: 1300, height: 900 });
		if (old) grSet(old); else grHideAll();
		await p.evaluate(() => fetch('index.php?p=gettingready')).catch(() => {});
	}
};

T.home_forms_panel = async (s) => {
	// 0.8.1264 (the owner picked B + A's gestures): ▾ next to Home (globeadmin) lists every Home form of the access levels with its
	// levels (dashed chip = the level starts on the tile page), Open / Design, search; right-click on the Home button opens it too
	const p = s.ga;
	const db = (sql) => execSync('mysql ' + DB + ' -N -B', { input: sql }).toString().trim();
	// 0.8.1268: nuBuilder Forte's empty form nublank is no Home form of the list, and is named "Blank page (system)" elsewhere
	db("INSERT INTO zzzzsys_access (zzzzsys_access_id, sal_code, sal_description, sal_zzzzsys_form_id) VALUES ('e2e_hf_blank', 'e2e_hf_blank', 'Blank level', 'nublank')");
	// 0.8.1270: a Home form that no longer exists is not listed either
	db("INSERT INTO zzzzsys_access (zzzzsys_access_id, sal_code, sal_description, sal_zzzzsys_form_id) VALUES ('e2e_hf_gone', 'e2e_hf_gone', 'Gone level', 'e2e_no_such_form')");
	try {
		await open(p, 'index.php?p=access&view=all&q=e2e_hf_blank');
		expect(/Blank page \(system\)/.test(await p.locator('.nuAccHomeLink').first().innerText()), 'access list label');
	} catch (e) { db("DELETE FROM zzzzsys_access WHERE zzzzsys_access_id IN ('e2e_hf_blank', 'e2e_hf_gone')"); throw e; }
	// 0.8.1289 (the owner): the button sits on the start page, right of Customise – not in the top bar any more
	await open(p, 'index.php?p=users');
	expect(await p.locator('#nuHfToggle').count() === 0, 'Start pages button still in the top bar');
	await open(p, 'index.php?p=home');
	expect(await p.evaluate(() => { const c = document.getElementById('nuHomeViewBtn'), t = document.getElementById('nuHfToggle');
		return !!(c && t && t.closest('.nuHomeTools') && (c.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING)); }), 'not right of Customise');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-hf-tab'); } catch (e) { /* none */ } });
	await p.click('#nuHfToggle');
	await p.waitForSelector('#nuHfPanel.show .nuHfRow');
	const want = db("SELECT COUNT(DISTINCT sal_zzzzsys_form_id) FROM zzzzsys_access WHERE COALESCE(sal_zzzzsys_form_id, '') NOT IN ('', 'nuhome', 'nublank', 'nuuserhome') AND sal_zzzzsys_form_id IN (SELECT zzzzsys_form_id FROM zzzzsys_form)");
	// 0.8.1282 (the owner picked H): User Home is the button in the panel head, not a row of the list
	expect(/f=nuuserhome/.test(await p.getAttribute('#nuHfPanel .nuHfHead #nuHfUserHome', 'href') || '') && /design=1/.test(await p.getAttribute('#nuHfUserHomeDesign', 'href') || '')
		&& await p.locator('#nuHfPanel .nuHfRow[data-nu-hf="nuuserhome"]').count() === 0, 'User Home in the head');
	const r = await p.evaluate(() => {
		const rows = [...document.querySelectorAll('#nuHfList_forms .nuHfRow')];
		const row = rows.find(x => x.getAttribute('data-nu-hf') === 'samplehome');
		return { tab: document.querySelector('.nuHfTab.active') && document.querySelector('.nuHfTab.active').getAttribute('data-tab'), tilesHidden: document.getElementById('nuHfList_tiles').hidden, n: rows.length, blank: rows.some(x => x.getAttribute('data-nu-hf') === 'nublank'), gone: rows.some(x => x.getAttribute('data-nu-hf') === 'e2e_no_such_form'), focus: document.activeElement && document.activeElement.id,
			chips: row ? [...row.querySelectorAll('.nuHfChip')].map(c => c.textContent + (c.classList.contains('nuHfChipTiles') ? '~' : '')) : null,
			hidden: row ? row.querySelector('.nuHfDetail').hidden : null,
			open: row ? row.querySelector('.nuHfOpen').getAttribute('href') : '', design: row ? !!row.querySelector('.nuHfDesign') : false,
			chipHref: row ? row.querySelector('.nuHfChip').getAttribute('href') : '' };
	});
	db("DELETE FROM zzzzsys_access WHERE zzzzsys_access_id IN ('e2e_hf_blank', 'e2e_hf_gone')");
	expect(String(r.n) === want && !r.blank && !r.gone, 'rows ' + r.n + ' of ' + want + (r.blank ? ' (nublank listed)' : '') + (r.gone ? ' (missing form listed)' : ''));
	expect(r.chips && r.chips.length >= 1 && /f=samplehome/.test(r.open) && r.design && /p=accesslevel.*tab=start/.test(r.chipHref), 'sample row: ' + JSON.stringify(r));
	expect(r.focus === 'nuHfSearch', 'search not focused: ' + r.focus);
	expect(r.tab === 'forms' && r.tilesHidden, 'Home forms tab not first: ' + r.tab);
	expect(await p.locator('#nuHfPanel .nuHfHead .nuHfCount').count() === 0, 'number of access levels shown in the head');   // 0.8.1280: the owner
	// 0.8.1266: rows start collapsed (title + count); the title button folds out code and access levels
	expect(r.hidden === true, 'details shown at first');
	await p.click('.nuHfRow[data-nu-hf="samplehome"] .nuHfExp');
	expect(await p.isVisible('.nuHfRow[data-nu-hf="samplehome"] .nuHfChip') && await p.getAttribute('.nuHfRow[data-nu-hf="samplehome"] .nuHfExp', 'aria-expanded') === 'true', 'row did not fold out');
	await p.click('.nuHfRow[data-nu-hf="samplehome"] .nuHfExp');
	expect(!(await p.isVisible('.nuHfRow[data-nu-hf="samplehome"] .nuHfChip')), 'row did not fold in');
	await p.fill('#nuHfSearch', 'tkt');
	const shown = await p.evaluate(() => [...document.querySelectorAll('#nuHfList_forms .nuHfRow')].filter(x => !x.hidden).map(x => x.getAttribute('data-nu-hf')));
	expect(shown.length >= 1 && !shown.includes('samplehome'), 'search: ' + shown.join(','));
	expect(await p.isVisible('.nuHfRow:not([hidden]) .nuHfChip'), 'hits of a search not folded out');
	await p.fill('#nuHfSearch', 'zzqq_nothing');
	expect(await p.isVisible('#nuHfList_forms .nuHfNoMatch'), 'no-match text');
	await p.fill('#nuHfSearch', '');
	// 0.8.1276 (the owner picked A): tab Tile pages – the default one first (for=*, the levels without a tile page of their own), then
	// every saved tile page with its levels; each level counted once; chips open the level's tile page in the editor; the tab is remembered
	await p.click('#nuHfTab_tiles');
	const tp = await p.evaluate(() => {
		const rows = [...document.querySelectorAll('#nuHfList_tiles .nuHfRow')];
		return { forms: document.getElementById('nuHfList_forms').hidden, ph: document.getElementById('nuHfSearch').placeholder,
			rows: rows.map(x => ({ id: x.getAttribute('data-nu-hf'), kind: x.getAttribute('data-nu-hf-kind'), open: x.querySelector('.nuHfOpen').getAttribute('href'),
				chips: [...x.querySelectorAll('.nuHfChip')].map(c => c.getAttribute('href')) })) };
	});
	const levels = +db('SELECT COUNT(*) FROM zzzzsys_access') + 2;   // the panel was loaded with the two test levels above
	const chips = tp.rows.reduce((a, x) => a + x.chips.length, 0);
	expect(tp.forms && tp.rows.length >= 1 && tp.rows[0].id === '*' && /^(default|auto)$/.test(tp.rows[0].kind) && /p=home&for=(%2A|\*)/.test(tp.rows[0].open), 'tile pages tab: ' + JSON.stringify(tp).slice(0, 300));
	expect(chips === levels && tp.rows.every(x => x.chips.every(h => /p=home&for=(?!%2A)/.test(h))), 'tile page chips ' + chips + ' of ' + levels + ' levels');
	expect(tp.rows.slice(1).every(x => x.kind === 'level' && x.chips.length > 0 && /p=home&for=/.test(x.open)), 'own tile pages: ' + JSON.stringify(tp.rows.slice(1)).slice(0, 300));
	expect(/tile page/i.test(tp.ph), 'placeholder of the tab: ' + tp.ph);
	await p.keyboard.press('Escape');
	await p.reload();
	await p.click('#nuHfToggle');
	await p.waitForSelector('#nuHfPanel.show .nuHfList:not([hidden]) .nuHfRow');
	expect(await p.getAttribute('.nuHfTab.active', 'data-tab') === 'tiles', 'tab not remembered');
	await p.click('#nuHfTab_forms');
	await p.keyboard.press('Escape');
	await p.waitForSelector('#nuHfPanel.show', { state: 'detached', timeout: 3000 }).catch(() => {});
	// compact top bar (no logo): right-click on Home on another page goes to the start page with the panel open (?hf=1, removed from the address)
	const before = db("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'");
	const set = (v) => db(v === null ? "DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'"
		: "DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'branding'; INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('branding', '" + v.replace(/'/g, "''") + "')");
	try {
		const cur = before ? JSON.parse(before) : {};
		set(JSON.stringify(Object.assign({}, cur, { logo_show: 'login' })));
		await open(p, 'index.php?p=users');
		await Promise.all([p.waitForNavigation(), p.click('#nuHomeBtn', { button: 'right' })]);
		await p.waitForSelector('#nuHfPanel.show .nuHfRow');
		expect(/p=home/.test(p.url()) && !/hf=1/.test(p.url()), 'right-click: ' + p.url());
		await p.keyboard.press('Escape');
		// on the start page itself it opens in place
		await p.click('#nuHomeBtn', { button: 'right' });
		await p.waitForSelector('#nuHfPanel.show .nuHfRow');
		await p.keyboard.press('Escape');
	} finally { set(before || null); }
	const u = await login('sample_ben', UPW);
	try {
		const st = await u.evaluate(async () => (await fetch('index.php?p=homeforms')).status);
		expect(st === 403, 'homeforms for a user: ' + st);
		expect(await u.locator('#nuHfToggle').count() === 0, 'toggle shown to a user');
	} finally { await u.context().close(); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.top_bar_aligned = async (s) => {
	// 0.8.1267 (the owner picked B of the canvas "Top bar width"): from 1680 px the bar's contents line up with the content sheet
	// (the white bar stays full width); below that, with "Full width" and on tool pages nothing changes
	const p = s.ga;
	const ctx = await p.context().browser().newContext({ viewport: { width: 1920, height: 1000 }, storageState: await p.context().storageState() });
	const w = await ctx.newPage();
	const errs = []; w.on('pageerror', e => errs.push(e.message));
	const m = () => w.evaluate(() => {
		const r = (el) => el ? el.getBoundingClientRect() : null;
		const inner = r(document.querySelector('.nu-navbar > .nuNavInner')), main = r(document.querySelector('main#main'));
		const bar = r(document.querySelector('.nu-navbar'));
		return { il: Math.round(inner.left), ir: Math.round(inner.right), ml: Math.round(main.left), mr: Math.round(main.right), bw: Math.round(bar.width), vw: document.documentElement.clientWidth };
	});
	try {
		await w.goto(BASE + 'index.php?p=users');
		let a = await m();
		expect(Math.abs(a.il - a.ml) <= 1 && Math.abs(a.ir - a.mr) <= 1 && a.bw >= a.vw - 16 && a.ml > 100, '1920: ' + JSON.stringify(a));
		await w.evaluate(() => { try { localStorage.setItem('nunext-page-width', 'full'); } catch (e) {} });
		await w.goto(BASE + 'index.php?p=users');
		a = await m();
		expect(a.il <= 1 && a.ir >= a.bw - 1, 'full width: ' + JSON.stringify(a));
		await w.evaluate(() => { try { localStorage.setItem('nunext-page-width', '1300'); } catch (e) {} });
		await w.goto(BASE + 'index.php?p=users');
		a = await m();
		expect(Math.abs(a.il - a.ml) <= 1 && Math.abs(a.ir - a.mr) <= 1 && a.mr - a.ml <= 1301, 'page width 1300: ' + JSON.stringify(a));
		await w.evaluate(() => { try { localStorage.removeItem('nunext-page-width'); } catch (e) {} });
		await w.setViewportSize({ width: 1366, height: 800 });
		await w.goto(BASE + 'index.php?p=users');
		a = await m();
		expect(a.il <= 1 && a.ir >= a.bw - 1, '1366 unchanged: ' + JSON.stringify(a));
		expect(errs.length === 0, errs.slice(0, 2).join(' | '));
	} finally { await ctx.close(); }
};

// 0.8.1277 / 0.8.1289: phones – the Start pages button (start page, right of Customise) opens a panel that stays on the screen
T.nav_home_phone_pair = async (s) => {
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 760 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=home'); await p.waitForTimeout(400);
		const r = await p.evaluate(() => { const a = document.getElementById('nuHfToggle').getBoundingClientRect(), b = document.getElementById('nuHomeViewBtn').getBoundingClientRect();
			return { a: [a.x, a.y, a.width, a.height].map(Math.round), b: [b.x, b.y, b.width, b.height].map(Math.round) }; });
		expect(Math.abs(r.a[1] - r.b[1]) <= 2 && r.a[3] === r.b[3] && r.a[0] > r.b[0] && r.a[0] + r.a[2] <= 390, 'not beside Customise: ' + JSON.stringify(r));
		await p.click('#nuHfToggle');
		await p.waitForSelector('#nuHfPanel.show');
		const pr = await p.evaluate(() => { const x = document.getElementById('nuHfPanel').getBoundingClientRect(); return [Math.round(x.left), Math.round(x.right)]; });
		expect(pr[0] >= 0 && pr[1] <= 390, 'panel off the screen: ' + pr);
		// 0.8.1278 (the owner picked D): one line per row – Open / Design as icon buttons beside the title, not below it
		await p.waitForSelector('#nuHfPanel .nuHfList:not([hidden]) .nuHfRow');
		const rows = await p.evaluate(() => [...document.querySelectorAll('#nuHfPanel .nuHfList:not([hidden]) .nuHfRow')].filter(r => !r.hidden).map(r => {
			const a = r.getBoundingClientRect(), o = r.querySelector('.nuHfOpen').getBoundingClientRect(), t = r.querySelector('.nuHfExp').getBoundingClientRect();
			return { h: Math.round(a.height), sameLine: Math.abs((o.top + o.height / 2) - (t.top + t.height / 2)) < 6, label: r.querySelector('.nuHfOpen').getAttribute('aria-label') }; }));
		expect(rows.length && rows.every(r => r.h <= 48 && r.sameLine && /: /.test(r.label)), 'rows not one line: ' + JSON.stringify(rows.slice(0, 3)));
	} finally { await ctx.close(); }
};

// 0.8.1279 (the owner: C): an own tile page without tiles is marked "own, empty" (editor + Start pages panel), and saving a level's tile page
// with no tiles removes it – the level uses the default again
T.home_tiles_empty = async (s) => {
	const p = s.ga;
	const db = (q) => execSync('mysql ' + DB + ' -N -B', { input: q }).toString().trim();
	const lv = 'sample_viewer';
	if (db("SELECT COUNT(*) FROM zzzzsys_access WHERE zzzzsys_access_id = '" + lv + "'") !== '1') { s.skip = 'no sample data'; return; }
	const own = () => db("SELECT COUNT(*) FROM zzzzsys_nunext_list_view WHERE list_key = 'home_user' AND access_ids LIKE '%\"" + lv + "\"%'");
	expect(own() === '0', 'the level has a tile page already');
	db("INSERT INTO zzzzsys_nunext_list_view (zzzzsys_nunext_list_view_id, list_key, scope, owner, name, access_ids, definition, sort_order) VALUES ('e2e_empty_tp', 'home_user', 'shared', '', 'e2e empty', '[\"" + lv + "\"]', '{\"groups\":[],\"fav\":[],\"hidden\":[],\"custom\":{}}', 0)");
	try {
		await open(p, 'index.php?p=home&for=' + lv);
		const r = await p.evaluate((l) => ({ opt: [...document.querySelectorAll('#nuHomeFor option')].find(o => o.value === l)?.textContent || '', src: document.getElementById('nuHomeForSrc')?.textContent || '' }), lv);
		expect(/own, empty/.test(r.opt) && /no tiles/.test(r.src), 'editor: ' + JSON.stringify(r));
		await p.evaluate(() => { try { localStorage.setItem('nunext-hf-tab', 'tiles'); } catch (e) { /* none */ } });
		await open(p, 'index.php?p=home');
		await p.click('#nuHfToggle');
		await p.waitForSelector('#nuHfPanel.show .nuHfList:not([hidden]) .nuHfRow');
		expect(await p.locator('#nuHfList_tiles .nuHfRow[data-nu-hf="e2e_empty_tp"] .nuHfEmptyTag').count() === 1, 'panel: no empty mark');
		await p.keyboard.press('Escape');
		// saving the level's tile page without tiles removes it
		await open(p, 'index.php?p=home&for=' + lv);
		const j = await p.evaluate(async () => { const csrf = document.querySelector('input[name=_csrf]').value;
			const x = await fetch(location.href, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ action: 'layout', layout: { groups: [], fav: [], hidden: [], custom: {} } }) });
			return x.json(); });
		expect(j.ok && own() === '0', 'empty save kept the tile page: ' + JSON.stringify(j) + ' rows ' + own());
	} finally { db("DELETE FROM zzzzsys_nunext_list_view WHERE zzzzsys_nunext_list_view_id = 'e2e_empty_tp'"); await p.evaluate(() => { try { localStorage.removeItem('nunext-hf-tab'); } catch (e) { /* none */ } }); }
};

T.home_search_star = async (s) => {                    // 0.8.1399: ☆ at the right of a tile result toggles the favourite at once, message + Undo, Ctrl+Shift+F
	const p = s.ga;
	await open(p, 'index.php?p=home');
	const isFav = () => p.$eval('[data-nu-tile="update"]', (a) => a.getAttribute('data-nu-fav') === '1');
	const was = await isFav();
	await p.fill('#nuHomeSearch', 'update'); await p.waitForTimeout(800);
	const star = '#nuHomeSearchMore .nuHomeResStar[data-nu-star="t:update"]';
	expect(await p.locator(star).count() === 1, 'no star on the tile result');
	const look = await p.$eval(star, (b) => ({op: getComputedStyle(b).opacity, focus: b.tabIndex, hidden: b.getAttribute('aria-hidden')}));
	expect(look.op === '1' && look.focus === -1 && look.hidden === 'true', 'star of the selected row: ' + JSON.stringify(look));
	const other = await p.$$eval('#nuHomeSearchMore .nuHomeRes', (r) => r.filter((x) => !x.querySelector('[data-nu-res^="t:"]')).map((x) => !!x.querySelector('.nuHomeResStar')));
	expect(!other.some(Boolean), 'a result that is no tile has a star');
	await p.click(star); await p.waitForTimeout(800);
	expect(/p=home/.test(p.url()) && await isFav() === !was && await p.$eval(star, (b) => b.classList.contains('on')) === !was, 'click did not toggle (or opened the row)');
	expect(await p.locator('#nuHomeFavToast .alert').count() === 1, 'no message');
	await p.click('#nuHomeFavToast .alert button'); await p.waitForTimeout(800);
	expect(await isFav() === was, 'Undo did not put it back');
	await p.press('#nuHomeSearch', 'Control+Shift+F'); await p.waitForTimeout(800);
	expect(await isFav() === !was, 'Ctrl+Shift+F did not toggle');
	// cleared search: the page reloads with the stored state
	await Promise.all([p.waitForNavigation(), p.fill('#nuHomeSearch', '')]);
	await p.waitForTimeout(300);
	expect(await isFav() === !was, 'not stored');
	await p.fill('#nuHomeSearch', 'update'); await p.waitForTimeout(800);
	await p.click(star); await p.waitForTimeout(800);
	expect(await isFav() === was, 'not put back');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.getting_ready_find_bad = async (s) => {               // 0.8.1405: red / amber dots on the steps, "n to fix" leads to the next red point
	const p = s.ga;
	await open(p, 'index.php?p=gettingready');
	const st = await p.evaluate(() => [...document.querySelectorAll('.nuGrStep')].map((a) => ({g: a.dataset.grGroup,
		red: a.querySelector('.nuGrStepCount').classList.contains('nuGrStepRed'), dot: (a.querySelector('.nuGrDot') || {}).className || ''})));
	expect(st.every((x) => !x.red || /nuGrSt-bad/.test(x.dot)), 'a step with a red point has no red dot: ' + JSON.stringify(st));
	if (!(await p.locator('a#grBad').count())) { s.skip = 'no red point on this site'; return; }
	const seen = [];
	for (let i = 0; i < 3; i++) {
		await Promise.all([p.waitForNavigation(), p.click('a#grBad')]);
		await p.waitForTimeout(300);
		const t = await p.evaluate(() => { const x = document.querySelector(':target'); return x ? x.dataset.grSt + '|' + x.dataset.grItem : ''; });
		expect(/^bad\|/.test(t), 'the link did not lead to a red point: ' + t + ' ' + p.url());
		seen.push(t);
	}
	const reds = st.filter((x) => x.red).length;
	expect(reds < 2 || new Set(seen.map((x) => x)).size > 1, 'always the same point: ' + seen.join(','));
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.menu_no_select = async (s) => {                      // 0.8.1415: menus never select their text on a long press (fields in them still can)
	const p = s.ga;
	await open(p, 'index.php?p=home');
	await p.click('[data-nu-tile="update"]', { button: 'right' });
	await p.waitForTimeout(200);
	const us = (sel) => p.$eval(sel, (a) => getComputedStyle(a).userSelect);
	expect(await p.locator('.nuHomeCtx').count() === 1, 'no tile menu');
	expect(await us('.nuHomeCtx .dropdown-item') === 'none', 'tile menu entry selectable');
	if (await p.locator('.nuHomeCtxHead').count()) expect(await us('.nuHomeCtxHead') === 'none', 'tile menu head selectable');
	await p.keyboard.press('Escape');
	expect(await us('#nuUserMenu .dropdown-item, .nu-navbar .dropdown-menu .dropdown-item') === 'none', 'user menu entry selectable');
	await p.fill('#nuHomeSearch', 'x');
	expect(await us('#nuHomeSearch') !== 'none', 'search field not selectable');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.home_fav_order = async (s) => {
	// the row Favourites in an order of its own: the mouse drags a chip, Alt+← / → on a chip, Move left / right in its menu;
	// saved at once (?p=sidebar action fav_order) – also the side bar's order
	const p = s.ga;
	await open(p, 'index.php?p=home&tiles=1');
	const keys = await p.evaluate(() => [...document.querySelectorAll('[data-nu-tile]')].map(e => e.getAttribute('data-nu-tile')).filter((k, i, a) => a.indexOf(k) === i).slice(0, 3));
	const favNow = () => p.evaluate(() => [...document.querySelectorAll('#nuHomeStripFav [data-nu-chip]')].map(e => e.getAttribute('data-nu-chip')));
	for (const k of keys) if (!(await favNow()).includes(k)) {
		await p.evaluate(async (k) => { const fd = new FormData(); fd.append('_csrf', window.NuUtil.csrf()); fd.append('action', 'fav'); fd.append('key', k); fd.append('json', '1');
			await fetch('index.php?p=sidebar', {method: 'POST', body: fd, credentials: 'same-origin'}); }, k);
		await p.reload(); await p.waitForTimeout(300);
	}
	await p.evaluate(() => { localStorage.removeItem('nunext-home-strip-fav'); localStorage.removeItem('nunext-home-strip-auto-fav'); });
	await p.reload(); await p.waitForTimeout(500);
	const before = await favNow();
	expect(before.length >= 3, 'three favourites ' + JSON.stringify(before));
	// drag the first chip behind the last
	const chips = p.locator('#nuHomeStripFav [data-nu-chip]');
	const a = await chips.first().boundingBox(), z = await chips.last().boundingBox();
	await p.mouse.move(a.x + 20, a.y + a.height / 2); await p.mouse.down();
	for (let i = 1; i <= 12; i++) await p.mouse.move(a.x + 20 + (z.x + z.width - 10 - a.x - 20) * i / 12, a.y + a.height / 2);
	await p.mouse.up(); await p.waitForTimeout(600);
	const url = p.url();
	expect(url.includes('p=home'), 'the drag did not open the chip: ' + url);
	const dragged = await favNow();
	expect(dragged[dragged.length - 1] === before[0] && dragged[0] === before[1], 'dragged order ' + JSON.stringify([before, dragged]));
	await p.reload(); await p.waitForTimeout(500);
	expect(JSON.stringify(await favNow()) === JSON.stringify(dragged), 'order kept after a reload');
	// keyboard: Alt+← on the last chip
	await chips.last().focus(); await p.keyboard.press('Alt+ArrowLeft'); await p.waitForTimeout(500);
	const kb = await favNow();
	expect(kb[kb.length - 2] === before[0], 'Alt+← moved the chip ' + JSON.stringify(kb));
	// menu: Move right on that chip again
	await p.reload(); await p.waitForTimeout(500);
	await p.locator('#nuHomeStripFav [data-nu-chip="' + before[0] + '"]').click({button: 'right'}); await p.waitForTimeout(300);
	await p.locator('.nuHomeCtx .dropdown-item', {hasText: 'Move right'}).click(); await p.waitForTimeout(600);
	await p.reload(); await p.waitForTimeout(500);
	const mv = await favNow();
	expect(mv[mv.length - 1] === before[0], 'Move right saved ' + JSON.stringify(mv));
};

// tile page of a user: the greeting without the access level line
T.home_user_no_level = async () => {
	const old = sql("SELECT sus_login_password FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	sql(`UPDATE zzzzsys_user SET sus_login_password = '${execSync(`php -r 'echo password_hash("Test1234!", PASSWORD_BCRYPT);'`).toString()}' WHERE sus_login_name = 'sample_anna'`);
	let q;
	try {
		q = await login('sample_anna', 'Test1234!');
		await open(q, 'index.php?p=home');
		// no greeting and no access level: the h1 is only for screen readers
		const h1 = await q.locator('main h1').first().evaluate((h) => ({ cls: h.className, w: h.getBoundingClientRect().width, t: h.textContent }));
		expect(h1.cls.includes('visually-hidden') && h1.w <= 1, 'greeting still shown: ' + JSON.stringify(h1));
	} finally {
		if (q) await q.context().close();
		sql(`UPDATE zzzzsys_user SET sus_login_password = '${old}' WHERE sus_login_name = 'sample_anna'`);
	}
};

T.home_mine_order = async () => {
	// Personalise: a user puts the tiles of a group into an own order (grip + arrow key, mouse drag); Done keeps it, Back to the standard drops it
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
	const old = sql("SELECT sus_login_password FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'");
	const oldJson = sql("SELECT IFNULL(sus_json, '') FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'");
	sql(`UPDATE zzzzsys_user SET sus_login_password = '${execSync(`php -r 'echo password_hash("Test1234!", PASSWORD_BCRYPT);'`).toString()}' WHERE zzzzsys_user_id = '${uid}'`);
	let q;
	const orderOf = (pg, gid) => pg.$$eval('[data-nu-group="' + gid + '"] .nuHomeTiles > [data-nu-wrap]', l => l.map(w => w.getAttribute('data-nu-wrap')));
	try {
		q = await login('sample_anna', 'Test1234!');
		await open(q, 'index.php?p=home');
		const gid = await q.evaluate(() => { const g = [...document.querySelectorAll('[data-nu-group]')].find(x => x.querySelectorAll('.nuHomeTiles > [data-nu-wrap]').length >= 3); return g ? g.getAttribute('data-nu-group') : ''; });
		expect(gid !== '', 'no group with three tiles');
		const before = await orderOf(q, gid);
		await homeMine(q); await q.waitForTimeout(300);
		// keyboard: the first tile one place to the right
		await q.focus('[data-nu-wrap="' + before[0] + '"] .nuHomeMineGrip');
		await q.keyboard.press('ArrowRight');
		let now = await orderOf(q, gid);
		expect(now[0] === before[1] && now[1] === before[0], 'arrow key did not move the tile: ' + now.join(','));
		// mouse: the last tile dragged onto the first place
		const last = now[now.length - 1];
		await q.evaluate((g) => document.querySelector('[data-nu-group="' + g + '"]').scrollIntoView({block: 'center'}), gid); await q.waitForTimeout(150);   // the panel makes the page narrower and longer
		const a = await q.locator('[data-nu-wrap="' + last + '"] .nuHomeMineGrip').boundingBox();
		const b = await q.locator('[data-nu-wrap="' + now[0] + '"]').boundingBox();
		await q.mouse.move(a.x + a.width / 2, a.y + a.height / 2); await q.mouse.down();
		for (let i = 1; i <= 8; i++) await q.mouse.move(a.x + (b.x + 4 - a.x) * i / 8, a.y + (b.y + b.height / 4 - a.y) * i / 8);
		const cur = await q.evaluate(([x, y]) => getComputedStyle(document.elementFromPoint(x, y)).cursor, [b.x + 4, b.y + b.height / 4]);
		expect(cur === 'grabbing', 'cursor while dragging: ' + cur);
		await q.mouse.up();
		expect(await q.evaluate(() => !document.documentElement.classList.contains('nuHomeGrabbing')), 'grabbing cursor left after the drag');
		const idle = await q.$eval('[data-nu-wrap="' + now[1] + '"] a, [data-nu-wrap="' + now[1] + '"] button.nuHomeTile', e => getComputedStyle(e).cursor).catch(() => 'grab');
		expect(idle === 'grab', 'tile cursor while personalising: ' + idle);
		now = await orderOf(q, gid);
		expect(now[0] === last, 'drag did not move the tile: ' + now.join(','));
		await Promise.all([q.waitForNavigation(), q.click('#nuHomeMineDone')]); await q.waitForLoadState('networkidle');
		const saved = await orderOf(q, gid);
		expect(JSON.stringify(saved) === JSON.stringify(now), 'order not kept after Done: ' + saved.join(',') + ' / ' + now.join(','));
		expect(/"order":\{"/.test(sql("SELECT sus_json FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'")), 'order not stored');
		// Back to the standard: the page's order again
		await homeMine(q); await q.waitForTimeout(300);
		await Promise.all([q.waitForNavigation(), q.click('#nuHomeMineReset')]); await q.waitForLoadState('networkidle');
		const back = await orderOf(q, gid);
		expect(JSON.stringify(back) === JSON.stringify(before), 'Back to the standard kept the order: ' + back.join(','));
	} finally {
		if (q) await q.context().close();
		execSync('mysql ' + DB, { input: "UPDATE zzzzsys_user SET sus_login_password = '" + old + "', sus_json = " + (oldJson === '' ? 'NULL' : "'" + oldJson.replace(/\\/g, '\\\\').replace(/'/g, "''") + "'") + " WHERE zzzzsys_user_id = '" + uid + "'" });
	}
};

T.home_search_no_dup_page = async (s) => {
	// the start page search lists a page once: a tile and an entry of the palette (or two entries) that open the same page are one result
	const g = s.ga;
	for (const q of ['Configuration', 'Settings']) {
		await open(g, 'index.php?p=home');
		await g.fill('#nuHomeSearch', q); await g.waitForTimeout(1200);
		const urls = await g.$$eval('#nuHomeSearchMore [data-nu-res]', l => l.map(a => { try { return new URL(a.getAttribute('href'), location.href).href; } catch (e) { return ''; } }).filter(u => u && !u.endsWith('#')));
		const dup = urls.filter((u, i) => urls.indexOf(u) !== i);
		expect(urls.length > 0 && dup.length === 0, q + ': same page listed twice: ' + dup.join(', '));
	}
	await g.fill('#nuHomeSearch', '');
};

T.home_tip_touch = async (s) => {
	// touch screens: no tile tooltip from the focus a tap / Back leaves or from the mouse events a tap fakes (long press = tile menu)
	const ctx = await browser.newContext({ ...devices['iPhone 13'], storageState: await s.ga.context().storageState(), locale: 'en-GB' });
	const ph = await ctx.newPage();
	try {
		await open(ph, 'index.php?p=home');
		await ph.waitForSelector('[data-nu-tile]');
		const shown = await ph.evaluate(async () => {
			const tip = () => { const t = document.getElementById('nuHomeTip'); return !!t && !t.hidden; };
			const tiles = Array.from(document.querySelectorAll('a[data-nu-tile]')).filter(a => a.offsetParent).slice(0, 6);
			const out = [];
			for (const a of tiles) {
				a.focus(); await new Promise(r => setTimeout(r, 300)); out.push(tip()); a.blur();
				a.dispatchEvent(new PointerEvent('pointerdown', {bubbles: true, pointerType: 'touch'}));
				a.dispatchEvent(new PointerEvent('pointerup', {bubbles: true, pointerType: 'touch'}));
				a.dispatchEvent(new MouseEvent('mouseover', {bubbles: true}));
				await new Promise(r => setTimeout(r, 1000)); out.push(tip());
			}
			return out;
		});
		expect(shown.length > 0 && !shown.some(Boolean), 'tooltip on a touch screen: ' + JSON.stringify(shown));
	} finally { await ctx.close(); }
};

T.home_content_editor = async (s) => {
	// Customise → Content is the editor: the page becomes editable, the panel holds the tools and Save; leaving with changes asks inside the panel
	const p = s.ga;
	await open(p, 'index.php?p=home');
	await p.click('#nuHomeViewBtn'); await p.waitForTimeout(300);
	await p.click('#nuHomeVpTab-content'); await p.waitForSelector('body.nuHomeEditing');
	expect(await p.locator('.nuHeBar').count() === 0, 'edit bar on the page');
	for (const id of ['#nuHomeAddSearch', '#nuHomeAddGroup', '#nuHomeDisplay', '#nuHomeAddTile', '#nuHomeSave', '#nuHomeCancel'])
		expect(await p.locator('#nuHomeViewPanel ' + id).count() === 1, 'not in the panel: ' + id);
	expect((await p.textContent('#nuHomeEditState')).trim() === 'No changes', 'state at start');
	// no changes: another tab ends the edit mode without asking
	await p.click('#nuHomeVpTab-appearance'); await p.waitForTimeout(300);
	expect(await p.evaluate(() => !document.body.classList.contains('nuHomeEditing')) && await p.locator('#nuHomeLeaveKeep').count() === 0, 'left without a question');
	// hide a tile → it is listed under Add tiles, the state says unsaved
	await p.click('#nuHomeVpTab-content'); await p.waitForSelector('body.nuHomeEditing');
	const key = await p.$eval('#nuHomeEdit .nuHeItem[data-key]', e => e.getAttribute('data-key'));
	await p.click('#nuHomeEdit .nuHeItem[data-key="' + key + '"] .nuHeToolBtn:has(.bi-eye-slash)', {force: true}); await p.waitForTimeout(200);
	expect(await p.locator('#nuHomeAddList [data-key="' + key + '"]').count() === 1, 'hidden tile not in Add tiles');
	expect((await p.textContent('#nuHomeEditState')).trim() === 'Unsaved changes', 'state after a change');
	// search filters the list
	await p.fill('#nuHomeAddSearch', 'zzqq-nothing'); await p.waitForTimeout(100);
	expect(await p.locator('#nuHomeAddNone').isVisible(), 'no-match text');
	await p.fill('#nuHomeAddSearch', '');
	// leaving with changes asks: Keep editing stays, Discard ends without saving
	await p.click('#nuHomeVpTab-appearance'); await p.waitForTimeout(200);
	expect(await p.locator('#nuHomeLeaveKeep').isVisible(), 'no question when leaving');
	await p.click('#nuHomeLeaveKeep'); await p.waitForTimeout(200);
	expect(await p.evaluate(() => document.body.classList.contains('nuHomeEditing')) && await p.locator('#nuHomeVpTab-content.active').count() === 1, 'Keep editing left');
	await p.click('#nuHomeCancel'); await p.waitForTimeout(200);
	await p.click('#nuHomeLeaveDiscard'); await p.waitForTimeout(300);
	expect(await p.evaluate(() => !document.body.classList.contains('nuHomeEditing')) && await p.locator('#nuHomeViewPanel').isHidden(), 'Discard did not close');
	expect(await p.locator('#nuHomeView [data-nu-wrap="' + key + '"], #nuHomeView [data-nu-tile="' + key + '"]').count() > 0, 'discarded change kept');
	// + brings a hidden tile back; Save stores it (the page is put back afterwards)
	await p.click('#nuHomeViewBtn'); await p.waitForTimeout(200);
	expect(await p.locator('#nuHomeVpTab-appearance.active').count() === 1, 'panel did not reopen on Appearance');
	await p.click('#nuHomeViewBtn'); await p.waitForTimeout(200);
	// phone: the panel is a lower sheet and leaves room above it
	await p.setViewportSize({width: 390, height: 800});
	await open(p, 'index.php?p=home');
	await p.click('#nuHomeViewBtn'); await p.waitForTimeout(300);
	await p.click('#nuHomeVpTab-content'); await p.waitForSelector('body.nuHomeEditing');
	const h = await p.$eval('#nuHomeViewPanel', e => e.getBoundingClientRect().height);
	expect(h > 380 && h < 440, 'phone sheet height ' + h);
	await p.click('#nuHomeCancel'); await p.waitForTimeout(300);
	expect(await p.evaluate(() => !document.body.classList.contains('nuHomeEditing')), 'phone Cancel');
	await p.setViewportSize({width: 1400, height: 900});
};

T.logo_no_select = async (s) => {
	// the logo of the top bar (and the sign-in page) cannot be selected
	const p = s.ga;
	await open(p, 'index.php?p=home');
	const v = await p.$$eval('.nu-brand img', (l) => l.map((i) => getComputedStyle(i).userSelect));
	expect(v.length > 0 && v.every((x) => x === 'none'), 'logo selectable: ' + v.join());
};

T.nav_toggler_small = async (s) => {
	// phones: the ☰ button is smaller than Bootstrap's 56 × 40 px, but still a tap target of at least 32 px
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true, storageState: await s.ga.context().storageState() });
	try {
		const q = await ctx.newPage();
		await q.goto(BASE + 'index.php?p=home'); await q.waitForLoadState('networkidle');
		const r = await q.evaluate(() => { const b = document.querySelector('.navbar-toggler'); if (!b) return null; const x = b.getBoundingClientRect(); return { w: Math.round(x.width), h: Math.round(x.height) }; });
		expect(r && r.w <= 46 && r.h <= 38 && r.w >= 32 && r.h >= 32, 'toggler size: ' + JSON.stringify(r));
	} finally { await ctx.close(); }
};
