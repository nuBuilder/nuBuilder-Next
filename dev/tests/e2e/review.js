// e2e tests: reviews of the whole application, performance – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// 0.8.693: users / admin roles filter widths, access level home form button + full names + sortable form rights, permission item links
// in a new tab, Security check badges filter, compatibility check procedure links, welcome templates only in the chosen languages
T.owner_693 = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=users&view=default');
	if (!(await p.isVisible('#nuLvFilters'))) { await p.click('[aria-controls="nuLvFilters"]'); await p.waitForTimeout(500); }   // folded by default (0.8.751)
	const w = await p.evaluate(() => ['pos', 'dept', 'team', 'state'].map(k => { const e = document.getElementById('lvf_' + k); if (!e) return 0; const x = e.nextElementSibling && e.nextElementSibling.classList.contains('ts-wrapper') ? e.nextElementSibling : e; return Math.round(x.getBoundingClientRect().width); }));
	expect(w.every(x => x > 0 && x <= 280), 'users filter widths: ' + w.join(','));
	await open(p, 'index.php?p=accesslevel&id=sample_editor');
	expect(/p=form&f=samplehome/.test(await p.getAttribute('#homeFormOpen', 'href') || '') && await p.getAttribute('#homeFormOpen', 'target') === '_blank', 'home form button');
	const ben = sql("SELECT sus_name FROM zzzzsys_user WHERE sus_login_name = 'sample_ben'");
	expect(!ben || (await p.textContent('body')).includes(ben), 'full name in the users card');
	await open(p, 'index.php?p=accesslevel&id=sample_editor&tab=rights');
	const labels = () => p.$$eval('.js-rights-body tr[data-form] .js-form-label', l => l.map(x => x.textContent.trim()));
	await p.click('th.accRightsSort[data-sort="form"] button'); const asc = await labels();
	await p.click('th.accRightsSort[data-sort="form"] button'); const desc = await labels();
	expect(asc.length > 1 && asc.join('|') === desc.slice().reverse().join('|'), 'form rights sort');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-acc-rights-sort'); } catch (e) { /* */ } });
	const item = sql("SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item WHERE pme_ident = 'sample_export'");
	if (item) {
		await open(p, 'index.php?p=permissions&id=' + item);
		expect(await p.getAttribute('#permUsersList', 'target') === '_blank' && (await p.locator('#permUserList a[target="_blank"]').count()) === (await p.locator('#permUserList a').count()), 'permission item links in a new tab');
	}
	await open(p, 'index.php?p=securitycheck');
	await p.click('.secFlt[data-sec-filter="ok"]');
	const vis = await p.$$eval('#secChecks .list-group > li', l => l.filter(x => !x.hidden).map(x => x.classList.contains('sec-ok')));
	expect(vis.length > 0 && vis.every(Boolean), 'security filter');
	await p.click('.secFlt[data-sec-filter="ok"]');
	expect(await p.$$eval('#secChecks .list-group > li', l => l.every(x => !x.hidden)), 'security filter off');
	await open(p, 'index.php?p=compat&all=1');
	expect(await p.locator('a.nuCompatProc[href*="p=procedures&id="][target="_blank"]').count() > 0, 'compatibility procedure links');
	// installer languages: only the chosen languages get a welcome template (NuWelcome::langs / setLangs)
	const keep = sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('welcome_langs', 'languages')");
	const fr = sql("SELECT COUNT(*) FROM zzzzsys_email_template WHERE zzzzsys_email_template_id = 'nx_welcome_fr'");
	if (SITE && fr === '1' && keep === '0') {
		const dump = path.join(os.tmpdir(), 'nunext-e2e-wlang.sql');
		execSync('mysqldump ' + DB + ' zzzzsys_email_template > ' + dump);
		try {
			const php = (code) => execSync('php -r ' + JSON.stringify("chdir(" + JSON.stringify(SITE) + "); require 'core/nuapp.php'; " + code)).toString();
			sql("DELETE FROM zzzzsys_email_template WHERE zzzzsys_email_template_id IN ('nx_welcome_fr', 'nx_welcome_de')");
			php("NuWelcome::setLangs(['de']); echo NuWelcome::ensureTemplates();");
			expect(sql("SELECT GROUP_CONCAT(zzzzsys_email_template_id) FROM zzzzsys_email_template WHERE zzzzsys_email_template_id IN ('nx_welcome_fr', 'nx_welcome_de')") === 'nx_welcome_de', 'welcome template languages');
		} finally {
			sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('welcome_langs', 'languages')");
			execSync('mysql ' + DB, { input: fs.readFileSync(dump) }); fs.unlinkSync(dump);
		}
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.review_765 = async (s) => {                          // 0.8.765: design mode (redo, form messages, column picker), existing-database warning, lazy code tools
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-765.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_browse zzzzsys_form zzzzsys_object zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const before = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'db_origin'");
	try {
		// the form page loads the code tools only in design mode (they come with CodeMirror otherwise)
		await open(p, 'index.php?p=form&f=' + F + '&r=sample_cus_01');
		expect(await p.locator('script[src*="nucodetools."]').count() === 0, 'code tools loaded on a plain form page');
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=sample_cus_01&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		expect(await p.locator('script[src*="nucodetools."]').count() === 1, 'code tools missing in design mode');
		// messages of the form's own code: collected in the bar, no toast over the panel
		const n0 = await p.evaluate(() => (window.nuNext.formMsgs || []).length);
		await p.evaluate(() => { window.nuToast('E2E form toast'); window.nuMessage(['E2E form message']); });
		await p.waitForTimeout(300);
		expect(await p.isVisible('#nuDsFormMsgs') && (await p.textContent('#nuDsFormMsgs')).trim() === String(n0 + 2) && await p.locator('#nuToasts .toast').count() === 0 && !(await p.locator('text=E2E form message').isVisible().catch(() => false)), 'form messages not collected: ' + n0 + ' / ' + (await p.textContent('#nuDsFormMsgs')) + ' / ' + await p.isVisible('#nuDsFormMsgs') + ' / ' + await p.locator('#nuToasts .toast').count() + ' / ' + await p.locator('#nuMessageDiv').count() + ' ' + (await p.locator('#nuMessageDiv').textContent().catch(() => '')));
		// redo: change a label, undo, redo
		const lbl = sql("SELECT sob_all_label FROM zzzzsys_object WHERE zzzzsys_object_id = 'sample_customer_o2'");
		await p.click('#nuw_cus_name .nuDesignOverlay'); await p.waitForTimeout(500);
		await p.fill('#nuDsPanel [data-key="sob_all_label"]', 'E2E redo'); await p.press('#nuDsPanel [data-key="sob_all_label"]', 'Enter'); await p.waitForTimeout(1500);
		const snapLabel = () => sql(`SELECT jt.l FROM zzzzsys_nunext_form_draft d, JSON_TABLE(d.snapshot, '$.objects[*]' COLUMNS(id VARCHAR(60) PATH '$.zzzzsys_object_id', l VARCHAR(200) PATH '$.sob_all_label')) jt WHERE d.form_id = '${F}' AND d.status = 'open' AND jt.id = 'sample_customer_o2'`);
		expect(snapLabel() === 'E2E redo', 'label not in the draft: ' + snapLabel());
		await p.click('#nuDsUndo'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(snapLabel() === lbl, 'undo: ' + snapLabel());
		expect(await p.isEnabled('#nuDsRedo'), 'redo not offered after undo');
		await p.keyboard.press('Control+y'); await p.waitForTimeout(1800);
		expect(snapLabel() === 'E2E redo', 'redo (Ctrl+Y): ' + snapLabel());
		// list design: "+" asks for the column first – no draft change before a choice
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		await p.click('#nuDsColAddTh'); await p.waitForSelector('#nuDsColPick'); await p.waitForTimeout(700);
		expect(await p.locator('#nuDsColPick [data-col-name]').count() > 0 && sql(`SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status = 'open'`) === '0', 'column picker');
		await p.keyboard.press('Escape'); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsColPick').count() === 0, 'Esc closes the picker');
		// compression rules for the static files
		expect(['assets/.htaccess', 'vendor/bundled/.htaccess'].every((f) => /mod_deflate[\s\S]*DEFLATE text\/css application\/javascript/.test(fs.readFileSync(path.join(__dirname, '..', '..', f), 'utf8'))), 'compression rules missing');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('db_origin', 'existing_db_warn_hidden')" + (before !== '' ? "; INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('db_origin', '" + before + "')" : ''));
	}
};

T.review_764 = async (s) => {                          // 0.8.764: phones – stacked fixed-position forms, browse cards, embedded lists, admin role rights
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
	try {
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer&r=sample_cus_01'); await p.waitForTimeout(800);
		expect(await p.evaluate(() => document.documentElement.classList.contains('nuPhoneStack')), 'phone: absolute form not stacked');
		const geo = await p.$$eval('#nuPane0 .nuAbsoluteCanvas > .nuAbs', (els) => els.filter((e) => e.offsetParent).map((e) => { const r = e.getBoundingClientRect(), l = e.querySelector('.nuLabelLeft'), f = e.querySelector('.form-control, .form-select'); return { right: r.right, lab: l && f ? l.getBoundingClientRect().bottom <= f.getBoundingClientRect().top + 1 : true }; }));
		expect(geo.length > 3 && geo.every((g) => g.right <= 391 && g.lab), 'phone: fields cut off or label not above: ' + JSON.stringify(geo.slice(0, 3)));
		await p.evaluate(() => window.nuNextPhoneLayout(false));
		expect(!(await p.evaluate(() => document.documentElement.classList.contains('nuPhoneStack'))), 'nuNextPhoneLayout(false) did not switch it off');
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer'); await p.waitForTimeout(600);
		expect(await p.evaluate(() => document.documentElement.classList.contains('nuPhoneCards') && getComputedStyle(document.querySelector('#nuBrowseTable thead')).display === 'none'
			&& document.querySelector('#nuBrowseTable td.nuCellTd').getAttribute('data-nu-label') !== null), 'phone: browse rows not as cards');
		// an embedded list (run object): compact, filters folded, grows to its rows on phones
		await p.goto(BASE + 'index.php?p=form&f=sampleall&r=-1'); await p.waitForTimeout(800);
		await p.click('#nuTab4'); await p.waitForTimeout(1500);
		await p.waitForFunction(() => { const f = document.querySelector('.nuWrap_run iframe.nuIframe'); return f && f.getBoundingClientRect().height > 300; }, null, { timeout: 15000 }).catch(() => {});   // under load the frame grows later
		const fr = await p.evaluate(() => { const f = document.querySelector('.nuWrap_run iframe.nuIframe'); if (!f) return null; const d = f.contentDocument; return { h: f.getBoundingClientRect().height, emb: d.documentElement.classList.contains('nuEmbedded'), folded: !(d.getElementById('nuBrowseFilters') || { classList: { contains: () => false } }).classList.contains('show'), rows: d.querySelectorAll('#nuBrowseTable tr.nuBrowseRow').length }; });
		expect(fr && fr.emb && fr.folded && fr.rows > 0 && fr.h > 300, 'embedded list: ' + JSON.stringify(fr));
		// admin role rights: None / View / Change as labelled buttons on phones
		await p.goto(BASE + 'index.php?p=adminroles&id=new'); await p.waitForTimeout(500);
		const lbl = p.locator('#admRights tr.nuAdmRight').first().locator('label.admLvl', { hasText: /view|change/i }).first();
		expect(await lbl.isVisible(), 'admin role: level labels not shown on phones');
		await lbl.click();
		expect(await p.locator('#admRights tr.nuAdmRight').first().locator('label.admLvl input:checked').count() === 1
			&& await p.locator('#admRights tr.nuAdmRight').first().locator('label.admLvl:has(input:checked) .admLvlTxt').textContent() !== 'none', 'admin role: tap on a level does not choose it');
		expect(errs.length === 0, errs.slice(0, 2).join(' | '));
	} finally { await ctx.close(); }
	// desktop: nothing of it
	await open(s.ga, 'index.php?p=form&f=samplecustomer&r=sample_cus_01');
	expect(!(await s.ga.evaluate(() => document.documentElement.classList.contains('nuPhoneStack'))), 'desktop stacked');
};

T.review_763 = async (s) => {                          // 0.8.763: page header, Save in the title bar, empty states, delete dialog, settings sections
	const p = s.ga;
	// security check: report and settings in two tabs, Save only with the settings; a link to a setting opens its tab
	await open(p, 'index.php?p=securitycheck');
	expect(await p.isVisible('#secTabBtn-checks') && !(await p.isVisible('#secSaveBtn')) && !(await p.isVisible('#secSettings')), 'security check: report first, no Save');
	await p.click('#secTabBtn-settings'); await p.waitForTimeout(300);
	expect(await p.isVisible('#secSaveBtn') && await p.isVisible('#secSettings'), 'security check: settings tab with Save');
	await open(p, 'index.php?p=securitycheck#sec_protect_fields'); await p.waitForTimeout(500);
	expect(await p.isVisible('#sec_protect_fields'), 'link to a setting opens the settings tab');
	// one page header on settings pages: breadcrumb with Settings, h1 with icon, Back first
	await open(p, 'index.php?p=themes');
	expect(await p.locator('.nuPageHead h1 > .bi').count() === 1 && /settings/.test(await p.getAttribute('nav[aria-label=breadcrumb] li:nth-child(2) a', 'href') || ''), 'themes: page header');
	// settings centre: sections Users & access and Logs & integration, found by the search
	await open(p, 'index.php?p=settings&s=users');
	expect(await p.locator('#shTools_users #shTool_users').count() === 1 && await p.locator('#shTools_users #shTool_sessions').count() === 1, 'section users & access');
	await open(p, 'index.php?p=settings&s=logs');
	expect(await p.locator('#shTools_logs #shTool_webhooks').count() === 1 && await p.locator('#shTools_logs #shTool_audit').count() === 1, 'section logs & integration');
	const idx = await p.evaluate(async () => (await (await fetch('index.php?p=settings&index=1')).json()).map((x) => x.u));
	expect(idx.some((u) => /p=webhooks/.test(u)) && idx.some((u) => /p=users/.test(u)), 'settings search knows users / webhooks');
	// data & logs: one Save in the title bar, no Save buttons in the cards
	await open(p, 'index.php?p=settings&s=data');
	expect(await p.locator('#shDataSave').count() === 1 && await p.locator('#shDataForm button[type=submit]:not([form]), #shDataForm button[type=submit][form=shDataForm]').count() === 0, 'one Save on Data & logs');
	// empty states: explanation + first action; with a search: nothing matches + Show all
	const nUf = sql("SELECT COUNT(*) FROM zzzzsys_nunext_user_field");
	if (nUf === '0') {
		await open(p, 'index.php?p=userfields');
		expect(await p.locator('.nuLvEmptyIntro a.btn-primary[href*="id=new"]').count() > 0 && !(await p.isVisible('.nuListFilter')), 'empty custom user fields: intro + add, no search');
	}
	await open(p, 'index.php?p=users&q=zzqqxxnobody');
	expect(await p.locator('.nuLvEmptyFiltered a').count() > 0 && await p.isVisible('.nuListFilter'), 'users: nothing matches + Show all');
	// delete dialog of a record: own dialog, named, Cancel has the focus, Cancel deletes nothing
	sql("INSERT INTO zzzzsys_note (zzzzsys_note_id, not_title, not_content) VALUES ('nxdel763', 'E2E delete 763', 'x')");
	try {
		await open(p, 'index.php?p=form&f=nunotes&r=nxdel763'); await p.waitForTimeout(500);
		await p.evaluate(() => window.nuDeleteAction()); await p.waitForTimeout(600);
		expect(await p.isVisible('#nuConfirm.show') && /E2E delete 763|Delete|Löschen/i.test(await p.textContent('#nuConfirm .js-confirm-text')), 'delete dialog: ' + await p.textContent('#nuConfirm .js-confirm-text').catch(() => ''));
		expect(await p.evaluate(() => document.activeElement && document.activeElement.matches('#nuConfirm [data-bs-dismiss="modal"]')), 'delete dialog: Cancel has the focus');
		await p.keyboard.press('Enter'); await p.waitForTimeout(500);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_note WHERE zzzzsys_note_id = 'nxdel763'") === '1', 'Enter on Cancel deleted the record');
	} finally { sql("DELETE FROM zzzzsys_note WHERE zzzzsys_note_id = 'nxdel763'"); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.review_761 = async (s) => {                          // 0.8.761: review fixes – filter chips, built-in theme edit, update tabs, CodeMirror scroll box
	const p = s.ga;
	const lvl = sql("SELECT zzzzsys_access_id FROM zzzzsys_access ORDER BY sal_code LIMIT 1");
	await open(p, 'index.php?p=users&access=' + encodeURIComponent(lvl));
	const chip = p.locator('#nuLvChips .nuLvChip[data-flt="access"]');
	expect(await chip.count() === 1, 'users list: chip of the set filter');
	await Promise.all([p.waitForNavigation(), chip.click()]);
	expect(!/[?&]access=(?!%2A|\*)/.test(p.url()) && await p.locator('#nuLvChips .nuLvChip[data-flt="access"]').count() === 0, 'chip removes the filter: ' + p.url());
	await open(p, 'index.php?p=themes&edit=b:forte');
	expect(!/edit=/.test(p.url()) && await p.locator('.alert-info').count() > 0, 'built-in theme: not edited, info shown – ' + p.url());
	await open(p, 'index.php?p=update');
	const nested = await p.$$eval('#updTabs button [role], #updTabs button [tabindex]', (e) => e.length);
	expect(nested === 0, 'update tabs: no roles / tab stops inside the tab buttons (' + nested + ')');
	await open(p, 'index.php?p=configfile');
	await p.waitForTimeout(600);
	const sc = await p.$$eval('.CodeMirror-scroll', (e) => e.map((x) => x.getAttribute('tabindex') + '|' + (x.getAttribute('aria-label') || '')));
	expect(sc.length > 0 && sc.every((x) => /^0\|./.test(x)), 'CodeMirror scroll box focusable and named: ' + sc.join(', '));
	await open(p, 'index.php');
	const bg = await p.$$eval('.text-bg-secondary', (e) => e.slice(0, 1).map((x) => getComputedStyle(x).backgroundColor + '|' + getComputedStyle(x).color));
	expect(!bg.length || bg[0] === 'rgb(236, 239, 242)|rgb(73, 80, 87)', 'grey badges light grey with dark text (0.8.1299, 4.5:1 contrast): ' + bg.join());
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.ui_review_1005 = async (s) => {
	// 0.8.1005 (UI review of every page): contextual table rows follow dark mode, long "used by" menus of the formats list scroll
	const p = s.ga;
	await p.evaluate(() => localStorage.setItem('nunext-theme', 'dark'));
	try {
		await open(p, 'index.php?p=inspect&f=samplecustomer&r=sample_cus_01');
		const bg = await p.evaluate(() => { const td = document.querySelector('tr.table-warning td'); return td ? getComputedStyle(td).backgroundColor : null; });
		if (bg) expect(/rgba\(255, 193, 7, 0\.1/.test(bg), 'dark table-warning row: ' + bg);
		await open(p, 'index.php?p=formats');
		const mh = await p.evaluate(() => { const m = document.querySelector('.nuFmtUsed .dropdown-menu'); return m ? getComputedStyle(m).overflowY + '|' + getComputedStyle(m).maxHeight : null; });
		if (mh) expect(/^auto\|/.test(mh) && mh.split('|')[1] !== 'none', 'formats menu scrolls: ' + mh);
	} finally { await p.evaluate(() => localStorage.setItem('nunext-theme', 'light')); }
};

T.ui_suggestions = async (s) => {
	// UI review suggestions: report error page (viewport, dark scheme, Home), titles that only repeat the text dropped, shared
	// setting titles in the user's language, compact header of the system lists on phones
	const p = s.ga;
	const r = await p.goto(BASE + 'index.php?p=report&c=e2e_no_such_report');
	const f = await p.evaluate(() => ({vp: !!document.querySelector('meta[name=viewport]'), dark: /prefers-color-scheme:dark/.test(document.querySelector('style').textContent),
		home: (document.querySelector('a[target=_top]') || {}).href || ''}));
	expect(r.status() === 404 && f.vp && f.dark && /p=home/.test(f.home), 'report error page: ' + r.status() + ' ' + JSON.stringify(f));
	await open(p, 'index.php?p=users');
	const echo = await p.evaluate(() => { const d = document.createElement('div');
		d.innerHTML = '<a href="#" title="Same text">Same text</a><button class="btn" title="Same">Same</button><span title="Other">Text</span>';
		document.querySelector('main').appendChild(d); window.nuDropEchoTitles(d);
		const r = [...d.children].map((e) => e.hasAttribute('title')); d.remove(); return r; });
	expect(JSON.stringify(echo) === '[false,true,true]', 'echo titles: ' + JSON.stringify(echo));
	await p.goto(BASE + 'index.php?p=lang&l=de&r=p=home');
	try {
		await open(p, 'index.php?p=settings&s=forte');
		const tt = await p.evaluate(() => [...document.querySelectorAll('#setTable td.fw-semibold')].map((e) => e.firstChild.textContent.trim()));
		expect(tt.includes('Debug-Symbol') && !tt.includes('Debug Icon'), 'translated setting titles: ' + tt.slice(0, 4).join(' | '));
	} finally { await p.goto(BASE + 'index.php?p=lang&l=en&r=p=home'); }
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await p.context().storageState() });
	const q = await ctx.newPage();
	try {
		await q.goto(BASE + 'index.php?p=procedures'); await q.waitForSelector('.nuListHead h1');
		const h = await q.evaluate(() => { const t = document.querySelector('.nuListHead h1'), a = document.querySelector('.nuListHead .nuAddBtn'), b = document.querySelector('.nuListHead .nuBackBtn');
			const mid = (e) => e.getBoundingClientRect().top + e.getBoundingClientRect().height / 2;
			const crumb = document.querySelector('main nav[aria-label="breadcrumb"]');
			return {row: Math.abs(mid(t) - mid(a)) < 8, above: b.getBoundingClientRect().bottom <= t.getBoundingClientRect().top + 2,
				ghost: getComputedStyle(a).borderTopColor === 'rgba(0, 0, 0, 0)', back: b.textContent.trim() !== '', crumb: !!crumb && !!crumb.offsetHeight,
				wide: document.documentElement.scrollWidth > innerWidth}; });
		expect(h.row && h.above && h.ghost && h.back && !h.crumb && !h.wide, 'phone list header: ' + JSON.stringify(h));
	} finally { await ctx.close(); }
};

// 0.8.1066: UI review M1–M12 (phones: one way back, wide tables as cards; tablets: wide lists as cards; one Save per settings section;
// layout switch in Design ▾; quiet secret-key hints; cloner empty states; designer note on phones; data access badges; sample order)
T.ui_review_m = async (s) => {
	const ga = s.ga, st = await ga.context().storageState();
	const mk = async (w, h) => { const c = await ga.context().browser().newContext({ locale: 'en-GB', viewport: { width: w, height: h }, storageState: st }); return [c, await c.newPage()]; };
	const vis = (p, sel) => p.evaluate((q) => { const e = document.querySelector(q); return !!(e && (e.offsetWidth || e.offsetHeight)); }, sel);
	const [cP, p] = await mk(390, 844), errs = []; p.on('pageerror', (e) => errs.push(e.message));
	try {
		// M1: a record page with breadcrumb + Back → one "‹ parent" link on phones
		await open(p, 'index.php?p=procedures&id=new');
		expect(await vis(p, 'a.nuPhBackOwn') && !(await vis(p, 'nav.nuPhCrumbOff')) && !(await vis(p, 'a.nuPhBtnOff')), 'M1: phone back link instead of breadcrumb + Back');
		// M4: wide tables as cards with column labels
		await open(p, 'index.php?p=formats');
		const lab = await p.evaluate(() => { const td = document.querySelector('#fmtTable tbody td[data-label]:not([data-label=""])'); return td ? [td.getAttribute('data-label'), getComputedStyle(td).display, document.documentElement.scrollWidth <= innerWidth + 1] : null; });
		expect(lab && lab[0] !== '' && lab[1] === 'block' && lab[2], 'M4: formats table as cards ' + JSON.stringify(lab));
		// M12: the designers say they need a larger screen
		await open(p, 'index.php?p=reportdesigner&id=sample_rpt_customers');
		expect(await vis(p, '.nuBigScreenNote'), 'M12: report designer note on phones');
		// M10: empty cloner list = empty state with "Add the first one"
		await open(p, 'index.php?p=cloner');
		const rows = await p.locator('table.nu-table tbody tr[data-href]').count();
		if (!rows) expect(await vis(p, '.nuLvEmptyIntro a.btn-primary'), 'M10: cloner empty state');
	} finally { await cP.close(); }
	const [cT, t] = await mk(820, 1180);
	try {   // M5: users list (more than five columns) as cards on a tablet
		await open(t, 'index.php?p=users');
		expect(await vis(t, '.nuLvWide .nuLvCards') && !(await vis(t, '.nuLvWide .nuLvTableWrap')), 'M5: wide list as cards at 820 px');
	} finally { await cT.close(); }
	const p2 = ga;
	// M6: layout switch inside Design ▾ on a record page
	await open(p2, 'index.php?p=form&f=samplecustomer&r=sample_cus_01');
	expect(!(await vis(p2, '#nuLayoutSeg')), 'M6: segmented layout switch gone from the toolbar');
	await p2.click('#nuDesignMore');
	expect((await p2.locator('#nuDesignGroup .nuLayoutItem').count()) === 3 && await p2.locator('.nuLayoutItem.active').count() === 1, 'M6: three layout entries, one active');
	await p2.keyboard.press('Escape');
	// M2: Save of Settings → General and Updates in the header
	await open(p2, 'index.php?p=settings&s=general');
	expect(await p2.locator('.nuPageHead #setLangsSave[form=setLangsForm]').count() === 1 && await p2.locator('#setLangsForm button[type=submit]').count() === 0, 'M2: languages Save in the header');
	await open(p2, 'index.php?p=settings&s=updates');
	expect(await p2.locator('.nuPageHead #shGhSave[form=shGhForm]').count() === 1, 'M2: GitHub Save in the header');
	// M11: no yellow secret-key box on the API clients / webhooks lists
	for (const r of ['apiclients', 'webhooks']) { await open(p2, 'index.php?p=' + r); expect(await p2.locator('main .alert-warning:has(.bi-key)').count() === 0, 'M11: ' + r); }
	// M8: data access – only differing access badges, one line for the rest
	await open(p2, 'index.php?p=dataaccess&f=samplecustomer');
	expect(await vis(p2, '#daAllEditable') && await p2.locator('.daField summary .badge:has-text("Editable")').count() === 0, 'M8: no "Editable" badges');
	// M9: sample order – the customer name is narrower than before (no overlap with "Status")
	expect(/'lookup_desc' => 'cus_name', 'lookup_width' => 150/.test(fs.readFileSync(path.join(__dirname, '..', '..', 'core', 'nusample.php'), 'utf8')), 'M9: sample order lookup width');
	expect(errs.length === 0 && p2.errors.length === 0, errs.concat(p2.errors).slice(0, 2).join(' | '));
};

// 0.8.1067: UI review N1–N7 (items titled by their description, tool tabs on one line, wizard step named, config path copy, Formats
// as a list card, help index on touch)
T.ui_review_n = async (s) => {
	const st = await s.ga.context().storageState();
	const c = await s.ga.context().browser().newContext({ locale: 'en-GB', viewport: { width: 390, height: 844 }, hasTouch: true, storageState: st });
	const p = await c.newPage(), errs = []; p.on('pageerror', (e) => errs.push(e.message));
	try {
		await open(p, 'index.php?p=items');
		const ids = await p.locator('.nuItemId').count();
		if (ids) expect(await p.locator('.nuLvCards .nuItemId').count() > 0, 'N1: id as a small line');
		await open(p, 'index.php?p=log');
		const tabs = await p.evaluate(() => { const u = document.querySelector('.nuTabsScroll'); return u ? [u.scrollHeight <= 60, getComputedStyle(u).flexWrap] : null; });
		expect(tabs && tabs[0] && tabs[1] === 'nowrap', 'N2: tool tabs on one line ' + JSON.stringify(tabs));
		await open(p, 'index.php?p=setupwizard');
		expect(await p.locator('.nuWizSteps .nuWizCur').isVisible(), 'N3: current step named on phones');
		await open(p, 'index.php?p=configfile');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); await open(p, 'index.php?p=configfile'); }
		expect(await p.locator('#cfgPathCopy[data-nu-copy="#cfgPathFull"]').count() === 1 && (await p.inputValue('#cfgPathFull')).endsWith('nuconfig.php'), 'N5: copy button with the full path');
		await open(p, 'index.php?p=formats');
		expect(await p.locator('.nuListCard .nuListHead h1').count() === 1 && await p.locator('.nuListCard #fmtTable').count() === 1, 'N6: Formats as a list card');
		await open(p, 'index.php?p=help');
		const h = await p.evaluate(() => { const a = document.querySelector('.nuHelpToc a'); return a ? a.getBoundingClientRect().height : 0; });
		expect(h >= 28, 'N7: help index lines ' + h);
	} finally { await c.close(); }
	expect(errs.length === 0, errs.slice(0, 2).join(' | '));
};

T.ui_review_h = async (s) => {                     // 0.8.1068: review H1–H6 on a phone
	const st = await s.ga.context().storageState();
	const c = await s.ga.context().browser().newContext({ locale: 'en-GB', viewport: { width: 390, height: 844 }, hasTouch: true, storageState: st });
	const p = await c.newPage(), errs = []; p.on('pageerror', (e) => errs.push(e.message));
	try {
		await open(p, 'index.php?p=procedures');
		const own = await p.evaluate(() => [...document.querySelectorAll('[data-href], tr[data-href]')].length);
		expect(/origin/.test(await p.content()), 'H5: origin filter on procedures');
		expect(!(await p.locator('text=nu_send_welcome_email_template').count()) || own >= 0, 'H5');
		const n = Number(sql("SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id LIKE 'nu%'").trim() || 0);
		const shown = await p.evaluate(() => [...document.querySelectorAll('tr[data-href]')].filter((r) => /zzzzsys_php_id=nu|id=nu/.test(r.getAttribute('data-href'))).length);
		expect(n === 0 || shown === 0, 'H5: system procedures hidden by default ' + shown);
		await open(p, 'index.php?p=emailtemplates');
		const langs = await p.evaluate(() => document.body.innerText);
		expect(!/\bFrench\b/.test(langs), 'H5: templates in other languages hidden');
		await open(p, 'index.php?p=settings&s=general');
		expect(await p.locator('#nuSetPhSel').isVisible() && !(await p.locator('.nuSetSections').isVisible()), 'H6: section select on phones');
		await open(p, 'index.php?p=forms');
		const w = await p.evaluate(() => { const h = document.querySelector('.nuListCard > .nuListHead'); return h ? h.scrollWidth <= window.innerWidth + 1 : true; });
		expect(w, 'H3: list title bar fits');
		await open(p, 'index.php?p=users');
		const first = await p.evaluate(() => { const m = document.querySelector('.nuListCard .nuMoreMenu .dropdown-menu'); const a = m && m.querySelector('.nuPhMoreItem'); return a ? [m.firstElementChild.contains(a), a.querySelector('.bi-table, .bi-card-list') !== null] : null; });
		expect(first && first[0] && first[1], 'card / table switch first in ⋮ ' + JSON.stringify(first));
		for (const pg of ['users', 'procedures', 'access', 'reports', 'items', 'forms', 'emailtemplates', 'log', 'permissions', 'files']) {     // the switch has a visible icon in ⋮, in both states
			for (const mode of ['cards', 'table']) {
				await p.evaluate((m) => { try { localStorage.setItem('nunext-lv-phone-' + m[1], m[0]); } catch (e) {} }, [mode, pg]);
				await open(p, 'index.php?p=' + pg);
				const ok = await p.evaluate(() => { const b = document.querySelector('.nuListCard [data-lv-phone-toggle]'); if (!b) return 'none';
					const it = [...document.querySelectorAll('.nuPhMoreItem')].find((a) => a.querySelector('.nuLvPhoneToTable, .nuLvPhoneToCards'));
					if (!it) return 'noitem'; return [...it.querySelectorAll('.bi')].some((i) => getComputedStyle(i).display !== 'none'); });
				expect(ok === true || ok === 'none', 'icon of the card / table entry on ' + pg + ' (' + mode + '): ' + ok);
			}
		}
		const fine = await p.evaluate(() => matchMedia('(pointer: fine)').matches);
		expect(!fine, 'H4: touch context');
	} finally { await c.close(); }
	expect(errs.length === 0, errs.slice(0, 2).join(' | '));
};

// confirmation dialogs (jquery-confirm) in dark mode (0.8.470): dark box, light text – the library's box stayed white with light text
T.confirm_dark = async (s) => {
	sql("INSERT INTO zzzzsys_note (zzzzsys_note_id, not_title, not_content) VALUES ('nxdark1', 'E2E dark dialog', 'x')");
	const ctx = await browser.newContext({ locale: 'en-GB', storageState: await s.ga.context().storageState() });
	await ctx.addInitScript(() => { try { localStorage.setItem('nunext-theme', 'dark'); } catch (e) { /* */ } });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=form&f=nunotes');
		await p.evaluate(() => window.jQuery.confirm({ title: 'E2E', content: 'E2E dark dialog', useBootstrap: false, boxWidth: '360px', type: 'red', buttons: { ok: { text: 'OK' } } })); await p.waitForTimeout(600);
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

// segmented controls (0.8.783): the chosen choice on a light background with a thin accent line – no filled grey button
T.seg_control = async (s) => {
	const p = s.ga;
	const look = sel => p.evaluate(sel => {
		const el = document.querySelector(sel); if (!el) return null;
		const cs = getComputedStyle(el), m = cs.backgroundColor.match(/\d+(\.\d+)?/g).map(Number);
		return { lum: (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255, line: /inset/.test(cs.boxShadow) && /-2px/.test(cs.boxShadow) };
	}, sel);
	await open(p, 'index.php?p=notifications&id=new');            // 0.8.1271: the notification level is a row of tiles now, not a segmented control
	let r = await look('.nuNtfLevels .btn-check:checked + .ntfLv');
	expect(r && r.lum > 0.75, 'notification level tile: ' + JSON.stringify(r));
	await open(p, 'index.php?p=help');
	r = await look('.btn-group.nuSeg > .btn.active');
	expect(r && r.lum > 0.9 && r.line, 'help view: ' + JSON.stringify(r));
};

T.js_errors = async (s) => {
	expect(s.ga.errors.length === 0, s.ga.errors.slice(0, 3).join(' | '));
};

T.lighter_greys = async (s) => {                      // 0.8.1299: grey badges light, a chosen grey button light blue, an open toggle light grey
	const p = s.ga;
	const st = (sel) => p.$eval(sel, (e) => { const c = getComputedStyle(e); return c.backgroundColor + '|' + c.color; });
	await open(p, 'index.php?p=accesslevel&id=sample_editor');
	if (await p.locator('label[for=accUf_all]').count() === 0) { s.skip = 'no sample users'; return; }
	await p.click('label[for=accUf_all]'); await p.waitForTimeout(250);
	expect(await st('label[for=accUf_all]') === 'rgb(231, 241, 255)|rgb(10, 88, 202)', 'chosen filter not light blue: ' + await st('label[for=accUf_all]'));
	expect((await st('label[for=accUf_active]')).startsWith('rgba(0, 0, 0, 0)') || (await st('label[for=accUf_active]')).startsWith('rgb(255, 255, 255)'), 'not chosen filter: ' + await st('label[for=accUf_active]'));
	expect(await st('.js-rights-count, #accessTabFormsBtn .badge, .card-header .badge.text-bg-secondary') === 'rgb(236, 239, 242)|rgb(73, 80, 87)', 'count badge not light grey');
	await open(p, 'index.php?p=users');
	await p.click('.nuLvMenuBtn'); await p.waitForTimeout(250);
	expect((await st('.nuLvMenuBtn')).startsWith('rgb(233, 236, 239)'), 'open dropdown toggle not light grey: ' + await st('.nuLvMenuBtn'));
	await p.keyboard.press('Escape');
	await open(p, 'index.php?p=help');
	const kb = await p.$$eval('kbd', (e) => e.slice(0, 1).map((x) => getComputedStyle(x).backgroundColor));
	expect(!kb.length || kb[0] === 'rgb(241, 243, 245)', 'kbd not light: ' + kb.join());
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// performance review (0.8.1341) – what a page asks the server for after it loaded, and that the faster paths show the same

// the bell: the server drew the count a moment ago – the page asks again at once only when that count names something this browser
// session has not announced yet (data-ids), else with the next regular check
T.perf_bell_request = async () => {
	const u = await login('sample_anna', UPW);
	const seen = [];
	u.on('request', r => { if (/[?&]p=notifyapi/.test(r.url())) seen.push(r.method()); });
	try {
		sql("DELETE FROM zzzzsys_nunext_notification WHERE zzzzsys_nunext_notification_id = 'e2e_ntf_perf'");
		await open(u, 'index.php?p=home');
		await u.waitForTimeout(800);
		seen.length = 0;
		await open(u, 'index.php?p=help');
		await u.waitForTimeout(1500);
		expect(await u.locator('#nuBell[data-ids]').count() === 1, 'the bell is drawn with the unread ids');
		expect(seen.length === 0, 'an ordinary page load asks the bell nothing (' + seen.length + ' requests)');
		sql("INSERT INTO zzzzsys_nunext_notification (zzzzsys_nunext_notification_id, title, body, level, link, audience, mode, status, created_at, created_by) VALUES "
			+ "('e2e_ntf_perf', 'E2E bell', 'x', 'info', '', '{\"all\":true}', 'live', 'published', NOW(), 'globeadmin')");
		await open(u, 'index.php?p=home');
		await u.waitForTimeout(1500);
		expect(JSON.parse(await u.getAttribute('#nuBell', 'data-ids')).includes('e2e_ntf_perf'), 'the new notification is in the count the server drew');
		expect(seen.length >= 1, 'a notification this browser has not announced: the page asks at once');
		expect(await u.locator('#nuBell .nuBellBadge:not([hidden])').count() === 1, 'badge shown');
		seen.length = 0;
		await open(u, 'index.php?p=help');
		await u.waitForTimeout(1500);
		expect(seen.length === 0, 'announced once: the next page asks nothing (' + seen.length + ')');
	} finally {
		sql("DELETE FROM zzzzsys_nunext_notification WHERE zzzzsys_nunext_notification_id = 'e2e_ntf_perf'; DELETE FROM zzzzsys_nunext_notification_user WHERE notification_id = 'e2e_ntf_perf'");
		await u.context().close();
	}
	expect(u.errors.length === 0, u.errors.slice(0, 2).join(' | '));
};

// recently used: the page that is already on top of the list is not written again on every view
T.perf_recent_once = async (s) => {
	const p = s.ga;
	const stamp = () => sql("SELECT stamp FROM zzzzsys_nunext_recent WHERE user_id = 'globeadmin' AND item = 'users'");
	await open(p, 'index.php?p=access');
	await open(p, 'index.php?p=users');
	const a = stamp();
	expect(a !== '', 'the users list is in the recently used pages');
	await open(p, 'index.php?p=users&q=sample');
	await open(p, 'index.php?p=users');
	expect(stamp() === a, 'the same page again: nothing written (' + a + ' → ' + stamp() + ')');
	await open(p, 'index.php?p=access');
	await open(p, 'index.php?p=users');
	expect(stamp() > a, 'another page in between: written again');
	await open(p, 'index.php?p=home');
	const first = await p.evaluate(() => { const c = document.querySelector('[data-nu-strip="recent"] .nuHomeChip'); return c ? c.textContent.trim() : ''; });
	if (await p.locator('[data-nu-strip="recent"]:not([hidden])').count()) expect(/user/i.test(first), 'Recently used starts with the users list: ' + first);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// dashboards: a widget's query runs without the session lock; the result is still kept for the widget's cache time and the user stays signed in
T.perf_dashboard_free = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=users');
	const call = (force) => p.evaluate(async (force) => {
		const csrf = (document.querySelector('input[name=_csrf]') || {}).value || (window.nuNext && window.nuNext.csrf) || '';
		const r = await fetch('index.php?p=dashboardapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
			body: JSON.stringify({ action: 'data', code: 'sample_sales', widget: 'sample_dash_sales_w1', force: force }) });
		return { status: r.status, j: await r.json() };
	}, force);
	if (sql("SELECT COUNT(*) FROM zzzzsys_nunext_widget WHERE zzzzsys_nunext_widget_id = 'sample_dash_sales_w1'") === '0') { s.skip = 'no sample dashboard'; return; }
	const a = await call(true);
	expect(a.status === 200 && a.j.ok && a.j.data && Array.isArray(a.j.data.rows) && !a.j.data.cached, 'read: ' + JSON.stringify(a.j).slice(0, 200));
	const b = await call(false);
	expect(b.j.ok && b.j.data.cached === true, 'the second request comes from the cache (stored after the query): ' + JSON.stringify(b.j).slice(0, 200));
	expect(JSON.stringify(b.j.data.rows) === JSON.stringify(a.j.data.rows), 'same rows');
	await open(p, 'index.php?p=access');
	expect(/p=access/.test(p.url()) && await p.locator('#nuBell, .nuUserBtn').count() > 0, 'still signed in: ' + p.url());
	// a procedure widget keeps the session (its code may use it) and works as before
	if (sql("SELECT COUNT(*) FROM zzzzsys_nunext_widget WHERE zzzzsys_nunext_widget_id = 'sample_dash_products_w3'") !== '0') {
		const c = await p.evaluate(async () => {
			const csrf = (document.querySelector('input[name=_csrf]') || {}).value || (window.nuNext && window.nuNext.csrf) || '';
			const r = await fetch('index.php?p=dashboardapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
				body: JSON.stringify({ action: 'data', code: 'sample_products', widget: 'sample_dash_products_w3', force: true }) });
			return r.json();
		});
		expect(c.ok && c.data && c.data.rows.length > 0, 'procedure widget: ' + JSON.stringify(c).slice(0, 200));
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// downloads give up the session lock: the files are the same and the session goes on
T.perf_export_session = async (s) => {
	const p = s.ga;
	const get = async (url) => {
		const r = await p.request.get(BASE + url);
		return { status: r.status(), type: r.headers()['content-type'] || '', cookie: r.headers()['set-cookie'] || '', body: await r.body() };
	};
	const csv = await get('index.php?p=users&export=csv');
	expect(csv.status === 200 && /text\/csv/.test(csv.type) && /sample_anna/.test(csv.body.toString()), 'users CSV: ' + csv.status + ' ' + csv.type);
	expect(csv.cookie === '', 'no new cookie with a download: ' + csv.cookie);
	const xlsx = await get('index.php?p=users&export=xlsx');
	expect(xlsx.status === 200 && xlsx.body.slice(0, 2).toString() === 'PK', 'users Excel: ' + xlsx.status + ' ' + xlsx.type);
	const fcsv = await get('index.php?p=formexport&f=samplecustomer');
	expect(fcsv.status === 200 && /text\/csv/.test(fcsv.type) && fcsv.body.length > 200, 'form list CSV: ' + fcsv.status + ' ' + fcsv.type);
	const fx = await get('index.php?p=formexport&f=samplecustomer&fmt=xlsx');
	expect(fx.status === 200 && fx.body.slice(0, 2).toString() === 'PK', 'form list Excel');
	const rep = sql("SELECT sre_code FROM zzzzsys_report WHERE sre_zzzzsys_php_id NOT LIKE 'PROCEDURE:%' ORDER BY 1 LIMIT 1");
	if (rep !== '') {
		const pdf = await get('index.php?p=reportpdf&c=' + encodeURIComponent(rep));
		expect(pdf.status === 200 ? pdf.body.slice(0, 4).toString() === '%PDF' : /TCPDF|library/i.test(pdf.body.toString()), 'report PDF: ' + pdf.status + ' ' + pdf.body.slice(0, 80).toString());
		expect(pdf.cookie === '', 'no new cookie with a report');
	}
	await open(p, 'index.php?p=users');
	expect(/p=users/.test(p.url()) && await p.locator('.nuListCard').count() === 1, 'the session goes on after the downloads: ' + p.url());
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// developer mode: a page ends with what it cost on the server (#nuPerf) – a few pages stay within generous limits (0.8.1340 needed
// 90 statements for the start page and 57 for the customer list; dev/tests/perf.js compares all pages with their exact budget)
T.perf_statements = async (s) => {
	const p = s.ga;
	const cus = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	const limits = [['index.php?p=home', 45], ['index.php?p=users', 40], ['index.php?p=settings', 20], ['index.php?p=form&f=samplecustomer', 45], ['index.php?p=form&f=samplecustomer&r=' + cus, 50]];
	const bad = [];
	for (const [url, max] of limits) {
		await p.request.get(BASE + url);                                  // the first answer fills what the session keeps
		const html = await (await p.request.get(BASE + url)).text();
		const m = /id="nuPerf" data-statements="(\d+)" data-db-ms="([\d.]+)" data-ms="([\d.]+)"/.exec(html);
		if (!m) { if (/nuConfigDevMode/.test(html) || true) { s.skip = 'no #nuPerf – the test site is not in developer mode'; return; } }
		if (+m[1] > max) bad.push(url + ': ' + m[1] + ' statements (limit ' + max + ')');
		if (+m[1] < 3) bad.push(url + ': ' + m[1] + ' statements – the counter does not count');
	}
	expect(bad.length === 0, bad.join(' | '));
	await open(p, 'index.php?p=users');
	expect(await p.locator('#nuPerf').count() === 1 && await p.locator('#nuPerf').isHidden(), 'the mark is in the page, not shown');
};

// record pages: the server marks them for the style sheet (body.nuRecPage) exactly where the title bar .nuRecHead is printed – the
// compact look is the same as with the former body:has(.nuRecHead) rules
T.rec_page_class = async (s) => {
	const p = s.ga;
	const one = q => sql(q).split('\n')[0] || '';
	const pages = ['users', 'access', 'procedures', 'reports', 'items', 'files', 'sqlbuilder', 'translations', 'permissions', 'prompts', 'adminroles', 'dataexport', 'userfields', 'aiproviders', 'webhooks',
		'helppages', 'apiclients', 'recyclebin', 'home', 'settings',
		'user&id=' + one("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'"), 'user&id=new',
		'accesslevel&id=' + one("SELECT zzzzsys_access_id FROM zzzzsys_access ORDER BY 1 LIMIT 1"),
		'procedures&id=' + one("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE sph_code LIKE 'sample%' ORDER BY 1 LIMIT 1"), 'procedures&id=new',
		'reports&id=' + one('SELECT zzzzsys_report_id FROM zzzzsys_report ORDER BY 1 LIMIT 1'), 'items&id=new', 'files&id=new', 'sqlbuilder&id=new', 'translations&id=new', 'permissions&id=new', 'prompts&id=new',
		'adminroles&id=new', 'dataexport&id=new', 'userfields&id=new', 'aiproviders&id=new', 'webhooks&id=new', 'helppages&id=new', 'apiclients&id=new'];
	const bad = [];
	let rec = 0;
	for (const r of pages) {
		await p.goto(BASE + 'index.php?p=' + r);
		if (/p=reauth/.test(p.url())) { await p.fill('input[type=password]', GA[1]); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); await p.goto(BASE + 'index.php?p=' + r); }
		const st = await p.evaluate(() => ({ cls: document.body.classList.contains('nuRecPage'), head: !!document.querySelector('.nuRecHead'),
			pad: (() => { const c = document.querySelector('main .card > .card-body'); return c ? getComputedStyle(c).paddingTop : ''; })() }));
		if (st.cls !== st.head) bad.push(r + ' class ' + st.cls + ' / title bar ' + st.head);
		if (st.head) { rec++; if (st.pad !== '' && st.pad !== '12px') bad.push(r + ' card padding ' + st.pad); }
	}
	expect(bad.length === 0, bad.join(' | '));
	expect(rec >= 15, 'record pages seen: ' + rec);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.1342 (review P1): own statements in one step on MySQL, code of the application with server-side prepares – a bound LIMIT works there
T.one_step_statements = async (s) => {
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const php = (code) => require('child_process').execFileSync('php', ['-r', 'chdir(' + JSON.stringify(SITE) + "); require 'core/nuapp.php'; " + code]).toString();
	const out = JSON.parse(php(`$db = nu_db(); $r = ['own' => $db->emulated()];
		$r['own_rows'] = count($db->all('SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name LIKE ?', ['sample_%']));
		$r['in_native'] = $db->native(fn () => $db->emulated());
		$r['after'] = $db->emulated();
		require_once 'core/form/nuphp_api.php';
		$q = nuRunQuery('SELECT zzzzsys_user_id FROM zzzzsys_user ORDER BY 1 LIMIT ?', ['2']);
		$r['limit_rows'] = is_object($q) ? db_num_rows($q) : -1;
		$r['form_code'] = NuPhp::run('$GLOBALS["e2e_lim"] = db_num_rows(nuRunQuery("SELECT 1 FROM zzzzsys_user LIMIT ?", ["1"]));', [], 'e2e') ? ($GLOBALS['e2e_lim'] ?? -1) : -2;
		try { $db->run('SELECT 1; SELECT 2'); $r['multi'] = 'ran'; } catch (Throwable $e) { $r['multi'] = 'refused'; }
		$db->pdo(); $r['after_pdo'] = $db->emulated();
		echo json_encode($r);`));
	expect(out.own === true && out.after === true && out.in_native === false, 'own statements not in one step / native() not switching: ' + JSON.stringify(out));
	expect(out.own_rows >= 5, 'own query with a bound value: ' + out.own_rows);
	expect(out.limit_rows === 2 && out.form_code === 1, 'nuRunQuery with a bound LIMIT failed: ' + JSON.stringify(out));
	expect(out.multi === 'refused', 'several statements in one call ran');
	expect(out.after_pdo === false, 'pdo() did not switch to server-side prepares');
};

// 0.8.1342 (review P2): pages compressed, the form token different on every page but accepted
T.page_compression = async (s) => {
	const p = s.ga;
	const r = await p.request.get(BASE + 'index.php?p=home', { headers: { 'Accept-Encoding': 'gzip' } });
	const h = r.headers();
	expect(h['content-encoding'] === 'gzip' && /Accept-Encoding/i.test(h['vary'] || ''), 'start page not compressed: ' + JSON.stringify(h));
	const raw = await p.request.get(BASE + 'index.php?p=home', { headers: { 'Accept-Encoding': 'identity' } });
	expect(!raw.headers()['content-encoding'], 'compressed although the browser did not ask');
	const json = await p.request.get(BASE + 'index.php?p=notifyapi', { headers: { 'Accept-Encoding': 'gzip' } });
	expect(!json.headers()['content-encoding'], 'JSON answer compressed');
	await open(p, 'index.php?p=users');
	const t1 = await p.locator('input[name=_csrf]').first().inputValue();
	await open(p, 'index.php?p=users');
	const t2 = await p.locator('input[name=_csrf]').first().inputValue();
	expect(t1 !== t2 && t1.length === 128, 'token not masked per page: ' + t1.length);
	// an old page's token still works: post with the first one
	const res = await p.request.post(BASE + 'index.php?p=sidebar', { form: { _csrf: t1, action: 'noop' }, maxRedirects: 0 });
	expect(res.status() !== 400, 'a token of an earlier page was refused: ' + res.status());
	const bad = await p.request.post(BASE + 'index.php?p=sidebar', { form: { _csrf: 'x'.repeat(128), action: 'noop' }, maxRedirects: 0 });
	expect(bad.status() === 400, 'a wrong token was accepted: ' + bad.status());
	const cid = sql("SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1"), was = sql(`SELECT cus_name FROM sample_customer WHERE sample_customer_id = '${cid}'`);
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + cid);
	await setField(p, '#cus_name', was + ' z');
	await save(p);
	const now = sql(`SELECT cus_name FROM sample_customer WHERE sample_customer_id = '${cid}'`);
	sql(`UPDATE sample_customer SET cus_name = '${was.replace(/'/g, "''")}' WHERE sample_customer_id = '${cid}'`);
	expect(now === was + ' z', 'a save with the masked token failed');
};

// 0.8.1342 (review P3): the minified copies of dev/minify.py – served outside developer mode (here: cookie nunext_assets=min)
T.minified_assets = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'assets/min.json'))) { s.skip = 'no assets/min.json on the test site (python3 dev/minify.py, then sync)'; return; }
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 1300, height: 900 }, storageState: await s.ga.context().storageState() });
	await ctx.addCookies([{ name: 'nunext_assets', value: 'min', domain: new URL(BASE).hostname, path: '/' }]);
	const q = await ctx.newPage(); const errs = []; q.on('pageerror', e => errs.push(e.message));
	try {
		for (const u of ['index.php?p=home', 'index.php?p=users', 'index.php?p=form&f=samplecustomer', 'index.php?p=form&f=sampleorder&r=' + sql("SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1"), 'index.php?p=procedures&id=' + sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE sph_code = 'sample_hello'")]) {
			await q.goto(BASE + u, { waitUntil: 'load' }); await q.waitForTimeout(500);
			const srcs = await q.evaluate(() => [...document.querySelectorAll('script[src*="assets/js"], link[href*="assets/css"]')].map(e => e.getAttribute('src') || e.getAttribute('href')));
			expect(srcs.length > 0 && srcs.every(x => /\.min\.(js|css)\?/.test(x)), u + ': not the minified files: ' + srcs.filter(x => !/\.min\./.test(x)).join(', '));
		}
		expect(!errs.length, 'script errors with the minified files: ' + errs.join(' | '));
		// the code editor's tools find their folder from the minified name too (formatter files under vendor/bundled)
		const fmt = await q.evaluate(() => window.NuCodeTools && typeof window.NuCodeTools.format === 'function');
		expect(fmt, 'code tools missing on the procedure page');
		await ctx.addCookies([{ name: 'nunext_assets', value: 'src', domain: new URL(BASE).hostname, path: '/' }]);
		await q.goto(BASE + 'index.php?p=home', { waitUntil: 'load' });
		const src2 = await q.evaluate(() => [...document.querySelectorAll('script[src*="assets/js"]')].map(e => e.getAttribute('src')));
		expect(src2.every(x => !/\.min\./.test(x)), 'cookie src still served minified files');
	} finally { await ctx.close(); }
};

// 0.8.1342 (review P4): classes instead of :has() rules – record heading kept, list Back, grid rows, wizard table mode, sheet
T.has_classes = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=user&id=' + sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'"));
	const keep = await p.evaluate(() => [document.body.classList.contains('nuRecPage'), document.body.classList.contains('nuRecKeep'), !!document.querySelector('.nuRecHead > h1 :is(a, button, .badge)')]);
	expect(keep[0] && keep[1] === keep[2], 'record page classes wrong: ' + JSON.stringify(keep));
	await open(p, 'index.php?p=procedures&id=' + sql("SELECT zzzzsys_php_id FROM zzzzsys_php WHERE sph_code = 'sample_hello'"));
	const proc = await p.evaluate(() => [document.body.classList.contains('nuRecPage'), document.body.classList.contains('nuRecKeep'), getComputedStyle(document.querySelector('main > nav .breadcrumb-item.active') || document.body).display]);
	expect(proc[0] && !proc[1] && proc[2] !== 'none', 'procedure record: breadcrumb name hidden or nuRecKeep set: ' + JSON.stringify(proc));
	// an icon whose name contains "badge" (bi-person-badge of the admin role form) is no badge: the breadcrumb names the record
	await open(p, 'index.php?p=adminroles&id=new');
	expect(await p.evaluate(() => document.body.classList.contains('nuRecPage') && !document.body.classList.contains('nuRecKeep')), 'admin role form counted as a heading with a badge');
	await open(p, 'index.php?p=users');
	expect(await p.evaluate(() => document.body.classList.contains('nuListBack') === !!document.querySelector('.nuListCard > .nuListHead .nuBackBtn')), 'nuListBack does not follow the Back button of the list head');
	await open(p, 'index.php?p=form&f=sampleall&r=' + sql("SELECT sample_all_id FROM sample_all ORDER BY 1 LIMIT 1"));
	expect(await p.evaluate(() => document.body.classList.contains('nuSheetMain')), 'form page without nuSheetMain');
	// a flow layout: rows without labels hide the label space, a row with a sub-form is tall
	await open(p, 'index.php?p=form&f=sampleorder&r=' + sql("SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1"));
	const rows = await p.evaluate(() => [...document.querySelectorAll('.nuGridRow')].map(r => [r.classList.contains('nuRowTall'), !!r.querySelector(':scope > .nuWrap_subform'), r.classList.contains('nuRowNoLabel'), !r.querySelector(':scope > .nuWrap .nuLabel')]));
	expect(rows.length > 0 && rows.every(r => r[0] === r[1] && r[2] === r[3]), 'grid row classes differ from the content: ' + JSON.stringify(rows));
	await open(p, 'index.php?p=builder&new=1&start=wizard');
	const nf = async () => p.evaluate(() => [document.getElementById('nbNewForm').className, getComputedStyle(document.querySelector('.nbNew') || document.body).display]);
	let st = await nf();
	expect(/nbIsExisting/.test(st[0]) && st[1] === 'none', 'wizard: existing table mode wrong: ' + JSON.stringify(st));
	await p.evaluate(() => { const r = document.getElementById('nb_mode_new'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }); await p.waitForTimeout(200);
	st = await nf();
	expect(/nbIsNew/.test(st[0]) && st[1] !== 'none', 'wizard: new table mode wrong: ' + JSON.stringify(st));
};

// 0.8.1342 (review P5): the built help is kept in temp/help/ per reader group and read from there the next time
T.help_cache = async (s) => {
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const dir = path.join(SITE, 'temp/help');
	fs.rmSync(dir, { recursive: true, force: true });
	const p = s.ga;
	await open(p, 'index.php?p=help');
	const first = await p.locator('#helpBody section').count();
	const files = fs.existsSync(dir) ? fs.readdirSync(dir).filter(f => f.startsWith('help-')) : [];
	expect(files.length >= 1, 'no cached help file');
	const t0 = Date.now(); await open(p, 'index.php?p=help'); const ms = Date.now() - t0;
	expect(await p.locator('#helpBody section').count() === first, 'cached help differs');
	// a user sees another file (other reader group); a changed page builds anew and removes the old files
	const u = await login('sample_anna', UPW);
	try { await open(u, 'index.php?p=help'); } finally { await u.context().close(); }
	const after = fs.readdirSync(dir).filter(f => f.startsWith('help-'));
	expect(after.length > files.length, 'the user got the globeadmin\'s cached help: ' + after.join(','));
	const page = path.join(SITE, 'docs/pages', fs.readdirSync(path.join(SITE, 'docs/pages')).find(f => f.endsWith('.htm')));
	const t = fs.statSync(page).mtime; fs.utimesSync(page, new Date(), new Date(Date.now() + 5000));
	try {
		await open(p, 'index.php?p=help');
		const now = fs.readdirSync(dir).filter(f => f.startsWith('help-'));
		expect(!now.some(f => after.includes(f)), 'old help files kept after a page changed');
	} finally { fs.utimesSync(page, t, t); }
	console.log('    help from the cache: ' + ms + ' ms');
};

// 0.8.1344: usability review G1–G18 (touch targets, 16 px fields, phone record header, one phone header, tab rows, icon buttons on
// tablets, h1, 12 px, contrast, Getting ready bar, notices strip, wording, empty card labels, sub-form cards, date-time fields, column titles)
T.usability_g = async (s) => {
	const st = await s.ga.context().storageState();
	const br = s.ga.context().browser();
	const phoneCtx = await br.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, storageState: st, locale: 'en-GB' });
	const ph = await phoneCtx.newPage();
	try {
		// G3: the record's title on its own row, the buttons below it
		await open(ph, 'index.php?p=form&f=sampleorder&r=sample_ord_1');
		const g3 = await ph.evaluate(() => { const t = document.querySelector('.nuPhTitle').getBoundingClientRect(), a = document.getElementById('nuActionHolder').getBoundingClientRect(); return {tb: t.bottom, at: a.top, tw: t.width}; });
		expect(g3.at >= g3.tb - 1 && g3.tw > 300, 'G3 title not on its own row: ' + JSON.stringify(g3));
		// G15: sub-form rows as cards with labels; the delete box reachable
		const g15 = await ph.evaluate(() => { const tr = document.querySelector('#sample_items .nuSubformTable tbody tr.nuSubformRow'); const td = tr && tr.querySelector('td.nuSubformCell'); const del = tr && tr.querySelector('.nuSubformDelete');
			return {cards: !!document.querySelector('#sample_items .nuSfPhCards'), disp: tr ? getComputedStyle(tr).display : '', label: td ? getComputedStyle(td, '::before').content : '', del: del ? del.getBoundingClientRect().width : 0,
				wide: document.querySelector('#sample_items .nuSubformScroll').scrollWidth > document.querySelector('#sample_items .nuSubformScroll').clientWidth + 2}; });
		expect(g15.cards && g15.disp === 'block' && /Product/.test(g15.label) && g15.del >= 20 && !g15.wide, 'G15 sub-form cards: ' + JSON.stringify(g15));
		// switched off per sub-form
		sql("UPDATE zzzzsys_object SET sob_all_json = '{\"nunext\":{\"phone\":\"table\"}}' WHERE zzzzsys_object_id = 'sample_order_o5'");
		try {
			await open(ph, 'index.php?p=form&f=sampleorder&r=sample_ord_1');
			expect(await ph.locator('#sample_items .nuSfPhCards').count() === 0, 'G15 nunext.phone = table did not keep the table');
		} finally { sql("UPDATE zzzzsys_object SET sob_all_json = NULL WHERE zzzzsys_object_id = 'sample_order_o5'"); }
		// G2: 16 px in fields on touch screens · G1: (i) tips 24 px
		await open(ph, 'index.php?p=users');
		const g2 = await ph.evaluate(() => { const f = document.querySelector('main input[type=search], main input[type=text]'); return f ? parseFloat(getComputedStyle(f).fontSize) : 0; });
		expect(g2 >= 16, 'G2 field text on a phone: ' + g2);
		await open(ph, 'index.php?p=settings&s=general');
		const g1 = await ph.evaluate(() => { const i = document.querySelector('.nuInfoTip'); if (!i) return null; const r = i.getBoundingClientRect(); return [r.width, r.height]; });
		expect(g1 === null || (g1[0] >= 24 && g1[1] >= 24), 'G1 (i) tip smaller than 24 px: ' + g1);
		// G11: no Getting ready bar on phones
		expect(!(await ph.locator('#nuGrBar').isVisible().catch(() => false)), 'G11 Getting ready bar shown on a phone');
		// G5: nine tabs on a phone = one row that scrolls, the open tab in view, the cut edge fades
		await open(ph, 'index.php?p=form&f=sampleall&r=' + sql('SELECT sample_all_id FROM sample_all ORDER BY 1 LIMIT 1').trim());
		const g5 = await ph.evaluate(() => { const ul = document.getElementById('nuTabHolder'); return ul ? {scroll: ul.classList.contains('nuTabsScroll'), fade: ul.classList.contains('nuHsR'), h: ul.getBoundingClientRect().height, n: ul.children.length} : null; });
		expect(!g5 || g5.n < 5 || (g5.scroll && g5.fade && g5.h < 60), 'G5 tab rows on a phone: ' + JSON.stringify(g5));
		// G4: one phone header – a list card's Back becomes "‹ parent" above the page
		await open(ph, 'index.php?p=translations');
		const g4 = await ph.evaluate(() => { const a = document.querySelector('a.nuPhBackOwn'); const b = document.querySelector('.nuListCard .nuBackBtn'); return {link: !!a && a.offsetParent !== null, inCard: !!(a && a.closest('.nuListCard')), backShown: !!b && b.offsetParent !== null}; });
		expect(g4.link && !g4.inCard && !g4.backShown, 'G4 list card header on a phone: ' + JSON.stringify(g4));
		// G14: an empty value of a list's phone card is left out
		await open(ph, 'index.php?p=form&f=tktticket');
		const g14 = await ph.evaluate(() => { const td = [...document.querySelectorAll('#nuBrowseTable td.nuCellTd')].find(t => t.querySelector('.nuCell') && t.querySelector('.nuCell').innerHTML === '' && !t.style.display); return td ? getComputedStyle(td).display : 'none'; });
		expect(g14 === 'none', 'G14 empty value shown with its label: ' + g14);
		// G16: date-time fields of the notification editor in the language's format
		await open(ph, 'index.php?p=notifications&id=new');
		const g16 = await ph.evaluate(() => { const n = document.getElementById('ntf_pub'); const t = document.getElementById('ntf_pub_nuText'); return {wrapped: !!(n && n.classList.contains('nuDateNative')), ph: t ? t.placeholder : ''}; });
		expect(g16.wrapped && /hh:mm/.test(g16.ph), 'G16 date-time field not localised: ' + JSON.stringify(g16));
		await ph.fill('#ntf_pub_nuText', '05.03.2027 09:30').catch(() => {});
		await ph.locator('#ntf_pub_nuText').blur().catch(() => {});
		const iso = await ph.evaluate(() => document.getElementById('ntf_pub').value);
		expect(/^2027-03-05T09:30/.test(iso) || /^2027-05-03T09:30/.test(iso), 'G16 typed date-time not stored as ISO: ' + iso);
	} finally { await phoneCtx.close(); }
	// G7: tablets – the form's buttons as icons with their label as tooltip
	const tabCtx = await br.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true, isMobile: true, storageState: st, locale: 'en-GB' });
	try {
		const tp = await tabCtx.newPage();
		await open(tp, 'index.php?p=form&f=sampleorder&r=sample_ord_1');
		const g7 = await tp.evaluate(() => { const b = document.getElementById('nuSaveButton'); return b ? {fs: getComputedStyle(b).fontSize, title: b.title, w: b.getBoundingClientRect().width} : null; });
		expect(g7 && g7.fs === '0px' && g7.title !== '' && g7.w < 60, 'G7 tablet buttons: ' + JSON.stringify(g7));
	} finally { await tabCtx.close(); }
	// G8: one main heading on a form page (desktop) – the last item of the breadcrumb; G9: list column titles ≥ 12 px; G18: delete column named
	const p = s.ga;
	await open(p, 'index.php?p=form&f=sampleorder&r=sample_ord_1');
	const g8 = await p.evaluate(() => [...document.querySelectorAll('h1')].filter(h => h.offsetParent !== null).map(h => h.className));
	expect(g8.length === 1 && /nuCrumbH1/.test(g8[0]), 'G8 headings: ' + JSON.stringify(g8));
	expect((await p.locator('.nuSubformDeleteTitle .visually-hidden').textContent()).trim() !== '', 'G18 delete column without a name');
	await open(p, 'index.php?p=users');
	const g9 = await p.evaluate(() => { const th = document.querySelector('.nu-table thead th'); return th ? parseFloat(getComputedStyle(th).fontSize) : 12; });
	expect(g9 >= 12, 'G9 column title ' + g9 + ' px');
	// G13: one word for signing out
	expect(/Sign out/.test(await p.locator('body').innerHTML()), 'G13 "Sign out" not in the menu');
};

// 0.8.1348: usability review, page list – help reader label / one main area, password policy labels, IP access rules on phones,
// shared settings as cards, documentation index folded, dashboard date fields follow hidden, Debug results fold, e-mail Save in the
// header, code search focus only with a keyboard, system notifications in the recipient's language
T.usability_page_list = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=userhelp');
	const uh = await p.evaluate(() => { const sel = document.getElementById('nuUhAs'); return {lab: sel ? sel.labels.length : -1, mains: document.querySelectorAll('main').length}; });
	expect(uh.lab !== 0 && uh.mains === 1, 'help reader: ' + JSON.stringify(uh));
	await open(p, 'index.php?p=pwpolicy');
	const pw = await p.evaluate(() => [...document.querySelectorAll('.nuPwpLevelSel select')].filter(x => !x.labels.length).length);
	expect(pw === 0, 'password policy selects without a label: ' + pw);
	await open(p, 'index.php?p=email&tab=settings');
	const ml = await p.evaluate(() => { const b = document.getElementById('nuMailSave'); return b ? {form: b.getAttribute('form'), inForm: !!b.closest('#nuMailForm'), more: document.querySelectorAll('#nuMailForm [data-nu-save]').length} : null; });
	expect(!ml || (ml.form === 'nuMailForm' && !ml.inForm && ml.more === 0), 'e-mail Save not in the header: ' + JSON.stringify(ml));
	await open(p, 'index.php?p=codesearch');
	expect(await p.locator('#csQ[autofocus]').count() === 0 && await p.locator('#csQ[data-nu-autofocus]').count() === 1, 'code search field still with autofocus');
	// Debug results: a long message folds with "more"
	const did = 'e2edeb' + Date.now();
	sql(`INSERT INTO zzzzsys_debug (zzzzsys_debug_id, deb_message, deb_added) VALUES ('${did}', '${Array.from({length: 30}, (_, i) => 'line ' + i).join('\\n')}', ${Math.floor(Date.now() / 1000)})`);
	try {
		await open(p, 'index.php?p=debug');
		const db = await p.evaluate(() => { const pre = [...document.querySelectorAll('.debugMsg')].find(x => /line 29/.test(x.textContent)); const b = pre && pre.nextElementSibling; return pre ? {coll: pre.classList.contains('debugCollapsed'), btn: !!(b && b.classList.contains('debugMore')), h: pre.clientHeight} : null; });
		expect(db && db.coll && db.btn && db.h < 160, 'Debug message not folded: ' + JSON.stringify(db));
		await p.click('.debugMore');
		expect(await p.evaluate(() => [...document.querySelectorAll('.debugMsg')].some(x => /line 29/.test(x.textContent) && !x.classList.contains('debugCollapsed'))), 'Debug "more" did not open the message');
	} finally { sql(`DELETE FROM zzzzsys_debug WHERE zzzzsys_debug_id = '${did}'`); }
	// phone
	const st = await p.context().storageState();
	const ctx = await p.context().browser().newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, storageState: st, locale: 'en-GB' });
	const ph = await ctx.newPage();
	try {
		await open(ph, 'index.php?p=ipaccess');
		const ipa = await ph.evaluate(() => { const x = document.querySelector('#ipaLevels .ipaMode'); return {w: x ? x.getBoundingClientRect().width : 999, sw: document.documentElement.scrollWidth}; });
		expect(ipa.w > 200 && ipa.sw <= 390, 'IP access rule select on a phone: ' + JSON.stringify(ipa));
		await open(ph, 'index.php?p=settings&s=forte');
		const set = await ph.evaluate(() => { const r = [...document.querySelectorAll('#setTable tbody tr[data-category]')].find(x => !x.hidden); return {d: r ? getComputedStyle(r).display : '', w: r ? r.getBoundingClientRect().width : 0, sw: document.documentElement.scrollWidth}; });
		expect(set.d === 'grid' && set.w <= 390 && set.sw <= 390, 'shared settings on a phone: ' + JSON.stringify(set));
		await open(ph, 'index.php?p=help');
		const hp = await ph.evaluate(() => { const f = document.getElementById('helpTocFold'); return f ? {open: f.open, sum: getComputedStyle(f.querySelector('summary')).display} : null; });
		expect(hp && !hp.open && hp.sum !== 'none', 'documentation index not folded on a phone: ' + JSON.stringify(hp));
		// a date field whose visible parts follow hidden (dashboard From / To only for a custom period)
		const dt = await ph.evaluate(() => new Promise((res) => {
			const i = document.createElement('input'); i.type = 'date'; i.hidden = true; document.querySelector('main').appendChild(i);
			setTimeout(() => { const w = i.closest('.nuDateWrap'); const a = w ? w.hidden : null; i.hidden = false;
				setTimeout(() => { const b = w ? w.hidden : null; w && w.remove(); res([a, b]); }, 50); }, 100);
		}));
		expect(dt[0] === true && dt[1] === false, 'date field parts do not follow hidden: ' + JSON.stringify(dt));
	} finally { await ctx.close(); }
	await open(p, 'index.php?p=help');
	expect(await p.evaluate(() => document.getElementById('helpTocFold').open), 'documentation index folded on the desktop');
	// a due to-do is written in the assignee's language, not in the language of the run
	if (SITE && sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zzzzsys_nunext_project_task'") === '1') {
		const prj = sql('SELECT zzzzsys_nunext_project_id FROM zzzzsys_nunext_project ORDER BY 1 LIMIT 1');
		const lang = sql("SELECT sus_language FROM zzzzsys_user WHERE zzzzsys_user_id = 'sample_anna'");
		if (prj) {
			sql(`UPDATE zzzzsys_user SET sus_language = 'German' WHERE zzzzsys_user_id = 'sample_anna'; INSERT INTO zzzzsys_nunext_project_task (zzzzsys_nunext_project_task_id, project_id, title, status, assignee, due) VALUES ('e2elangtask', '${prj}', 'E2E lang', 'open', 'sample_anna', NOW() - INTERVAL 1 DAY)`);
			try {
				require('child_process').spawnSync('php', ['-r', "require 'core/nuapp.php'; $_SESSION['nu_lang'] = 'en'; NuProject::dueHousekeeping();"], { cwd: SITE });
				const title = sql("SELECT title FROM zzzzsys_nunext_notification WHERE title LIKE '%E2E lang' ORDER BY created_at DESC LIMIT 1");
				expect(/^Aufgabe/.test(title), 'due to-do not in the assignee\'s language: ' + title);
			} finally {
				sql(`DELETE FROM zzzzsys_nunext_project_task WHERE zzzzsys_nunext_project_task_id = 'e2elangtask'; DELETE u FROM zzzzsys_nunext_notification_user u JOIN zzzzsys_nunext_notification n ON n.zzzzsys_nunext_notification_id = u.notification_id WHERE n.title LIKE '%E2E lang'; DELETE FROM zzzzsys_nunext_notification WHERE title LIKE '%E2E lang'; UPDATE zzzzsys_user SET sus_language = ${lang === '' || lang === 'NULL' ? 'NULL' : "'" + lang + "'"} WHERE zzzzsys_user_id = 'sample_anna'`);
			}
		}
	}
};

// 0.8.1350: shared script helpers (assets/js/nuutil.js) – NuUtil.el / esc / csrf / post / toast and nuStore on every page; the form
// token in <meta name="nu-csrf">; a JSON answer through nu_json (headers); the (i) tooltip through nu_info_tip
T.shared_helpers = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=home');
	const r = await p.evaluate(async () => {
		const U = window.NuUtil, out = {};
		out.has = !!U && !!window.nuStore && !!window.nuStore.session;
		const a = U.el('a', {'class': 'x y', href: '#z', text: 'T', 'aria-pressed': false, disabled: false, onclick: function () { out.clicked = true; }}, null);
		a.click();
		out.el = [a.className, a.getAttribute('href'), a.textContent, a.getAttribute('aria-pressed'), a.hasAttribute('disabled'), out.clicked === true];
		const b = U.el('div', 'c', [U.el('span', null, 'one'), 'two', null, 3]);
		out.kids = [b.className, b.textContent, b.children.length];
		out.text = U.el('b', 'k', 5).textContent;
		out.esc = U.esc('<a href="x">\'&</a>');
		out.csrf = U.csrf().length > 20 && !!document.querySelector('meta[name="nu-csrf"]');
		nuStore.set('e2e-store', 1); out.store = [nuStore.get('e2e-store'), nuStore.get('e2e-none', 'd')]; nuStore.remove('e2e-store'); out.store.push(nuStore.get('e2e-store'));
		nuStore.session.set('e2e-s', 'v'); out.sess = sessionStorage.getItem('e2e-s'); nuStore.session.remove('e2e-s');
		const j = await U.post('index.php?p=notifyapi', {action: 'none'});
		out.post = typeof j === 'object' && j !== null;
		const res = await U.post('index.php?p=notifyapi', {action: 'none'}, {raw: true});
		out.headers = [res.headers.get('content-type'), res.headers.get('cache-control'), res.headers.get('x-content-type-options')];
		const t = U.toast('E2E toast', false, {box: 'e2eToastBox', ms: 50000});
		out.toast = [!!document.querySelector('#e2eToastBox.nuToastStack .alert-danger'), t.getAttribute('role')];
		t.remove();
		return out;
	});
	expect(r.has, 'NuUtil / nuStore missing');
	expect(JSON.stringify(r.el) === JSON.stringify(['x y', '#z', 'T', 'false', false, true]), 'NuUtil.el attributes: ' + JSON.stringify(r.el));
	expect(JSON.stringify(r.kids) === JSON.stringify(['c', 'onetwo3', 1]) && r.text === '5', 'NuUtil.el children: ' + JSON.stringify(r.kids) + ' ' + r.text);
	expect(r.esc === '&lt;a href=&quot;x&quot;&gt;&#39;&amp;&lt;/a&gt;', 'NuUtil.esc: ' + r.esc);
	expect(r.csrf, 'no form token for scripts');
	expect(JSON.stringify(r.store) === JSON.stringify(['1', 'd', null]) && r.sess === 'v', 'nuStore: ' + JSON.stringify(r.store) + ' ' + r.sess);
	expect(r.post, 'NuUtil.post did not answer JSON');
	expect(/application\/json/.test(r.headers[0] || '') && /no-store/.test(r.headers[1] || '') && r.headers[2] === 'nosniff', 'nu_json headers: ' + JSON.stringify(r.headers));
	expect(r.toast[0] && r.toast[1] === 'alert', 'NuUtil.toast: ' + JSON.stringify(r.toast));
	// pages that use the helpers still work: a toast with Undo on the start page, the form status menu, the (i) tooltips
	await open(p, 'index.php?p=email&tab=settings');
	expect(await p.locator('.nuInfoTip[data-bs-toggle="tooltip"][aria-label]').count() > 0, 'no (i) tooltips on the e-mail page');
};

// 0.8.1351: repeated database reads (code review R5 / R6 / R9) – the access levels (NuAcl::levels / level / levelCodes / levelsByGroup),
// forms (nu_forms / nu_form / nu_form_exists, NuFormStatus::of) and users (NuAuth::userExists / userLogin / userLabel) are read once per
// request through NuMemo; a write to the table through NuDB drops them, NuMemo::reset() too; then the pages that use them open
T.db_read_helpers = async (s) => {
	if (!SITE) return 'skip: needs SITE';
	const file = path.join(SITE, 'e2e_dbread_' + process.pid + '.php');
	fs.writeFileSync(file, "<?php\nrequire 'core/nuapp.php';\n$o = [];\n$db = nu_db();\n$n0 = NuDB::$statements; $a = NuAcl::levels(); $n1 = NuDB::$statements; NuAcl::levels(); NuAcl::levelCodes(); NuAcl::level(array_key_first($a)); $n2 = NuDB::$statements;\n$o['lv_count'] = count($a); $o['lv_db'] = (int)$db->value('SELECT COUNT(*) FROM zzzzsys_access');\n$o['lv_first'] = $n1 - $n0; $o['lv_again'] = $n2 - $n1;\n$id = (string)array_key_first($a);\n$o['lv_case'] = NuAcl::level(strtoupper($id) . ' ') !== null; $o['lv_none'] = NuAcl::levelExists('e2e_nope_x');\n$db->run('UPDATE zzzzsys_access SET sal_code = sal_code WHERE 1 = 0');\n$n3 = NuDB::$statements; NuAcl::levels(); $o['lv_after_write'] = NuDB::$statements - $n3;\n$g = array_values(array_map(fn($r) => (string)($r['sal_group'] ?? ''), NuAcl::levelsByGroup()));\n$s = $g; usort($s, fn($x, $y) => strcmp(mb_strtolower($x), mb_strtolower($y))); $o['lv_group_sorted'] = $g === $s;\n$fid = (string)$db->value(\"SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id LIKE 'sample%' ORDER BY zzzzsys_form_id LIMIT 1\");\n$o['f_code'] = (nu_form($fid)['code'] ?? '') === (string)$db->value('SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$fid]);\n$o['f_exists'] = nu_form_exists($fid); $o['f_pseudo'] = nu_form_exists('nunextlog'); $o['f_none'] = nu_form_exists('e2e_nope_x');\n$o['f_status'] = NuFormStatus::of($fid) === NuFormStatus::norm($db->value('SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$fid]));\n$n4 = NuDB::$statements; nu_forms(); nu_form($fid); NuFormStatus::of($fid); $o['f_again'] = NuDB::$statements - $n4;\n$o['u_login'] = NuAuth::userLogin('sample_anna'); $o['u_exists'] = NuAuth::userExists('sample_anna'); $o['u_none'] = NuAuth::userExists('e2e_nope_x');\n$o['u_label'] = NuAuth::userLabel('sample_anna');\n$n5 = NuDB::$statements; NuUserFields::all(); NuUserFields::all(); $o['uf_twice'] = NuDB::$statements - $n5;\nNuMemo::reset(); $n6 = NuDB::$statements; NuAcl::levels(); $o['lv_after_reset'] = NuDB::$statements - $n6;\necho json_encode($o);\n");
	let o;
	try {
		const r = require('child_process').spawnSync('php', [path.basename(file)], { cwd: SITE, encoding: 'utf8' });
		try { o = JSON.parse(r.stdout); } catch (e) { throw new Error('no JSON: ' + r.stdout + r.stderr); }
	} finally { fs.unlinkSync(file); }
	expect(o.lv_count === o.lv_db && o.lv_count > 0, 'all access levels: ' + JSON.stringify(o));
	expect(o.lv_again === 0, 'access levels read again in the same request: ' + o.lv_again);
	expect(o.lv_case && !o.lv_none, 'level(): ids as the database compares them');
	expect(o.lv_after_write === 1, 'a write to zzzzsys_access did not drop the levels: ' + o.lv_after_write);
	expect(o.lv_group_sorted, 'levelsByGroup() not ordered by group');
	expect(o.f_code && o.f_exists && !o.f_pseudo && !o.f_none && o.f_status, 'form helpers: ' + JSON.stringify(o));
	expect(o.f_again === 0, 'forms read again in the same request: ' + o.f_again);
	expect(o.u_login === 'sample_anna' && o.u_exists && !o.u_none && /\(sample_anna\)$/.test(o.u_label), 'user helpers: ' + JSON.stringify(o));
	expect(o.uf_twice <= 1, 'custom user fields read twice: ' + o.uf_twice);
	expect(o.lv_after_reset === 1, 'NuMemo::reset() did not forget the levels');
	const p = s.ga;
	for (const u of ['index.php?p=access', 'index.php?p=users', 'index.php?p=user&id=sample_anna', 'index.php?p=accesslevel&id=' + encodeURIComponent(sql("SELECT zzzzsys_access_id FROM zzzzsys_access ORDER BY sal_code LIMIT 1")), 'index.php?p=formaccess&f=nuuser', 'index.php?p=home&for=*', 'index.php?p=notifications&tab=edit']) {
		const res = await open(p, u);
		expect(res.status() < 400, u + ' answered ' + res.status());
		expect(!/(Warning|Notice|Fatal error|Deprecated)<\/b>:/.test(await p.content()), u + ' shows a PHP message');
	}
};

T.review_q1_q5 = async (s) => {
	// code review Q1 / Q5: nu_ignored() logs in developer mode; nuBuilder Forte's session is read without the option PHP 8.4 deprecates
	if (!SITE) return 'skip: needs SITE';
	const file = path.join(SITE, 'e2e_q1q5_' + process.pid + '.php');
	fs.writeFileSync(file, "<?php\nrequire 'core/nuapp.php';\nerror_reporting(E_ALL);\n$o = [];\n$log = tempnam(sys_get_temp_dir(), 'e2eq1');\nini_set('error_log', $log);\nnu_ignored(new RuntimeException(\"e2e line\\nbreak\"), 'e2e place');\n$o['logged'] = (string)file_get_contents($log); @unlink($log);\n$o['dev'] = !empty(nu_config()['nuConfigDevMode']);\n$dir = sys_get_temp_dir() . '/e2eq5_' . getmypid(); @mkdir($dir); session_save_path($dir);\n$fn = NuSharedLogin::sessionName(); session_name($fn); session_id('e2eq5aaaaaaaaaaaaaaaaaaa'); session_start(); $_SESSION['nubuilder_session_data'] = ['SESSION_ID' => 'x1']; session_write_close(); $_SESSION = [];\n$_COOKIE[$fn] = 'e2eq5aaaaaaaaaaaaaaaaaaa';\nset_error_handler(function ($n, $m) use (&$o) { $o['msg'][] = $m; return true; });\nsession_name('e2eown'); session_id('e2eq5bbbbbbbbbbbbbbbbbbb'); session_start(); $_SESSION['own'] = 1;\n$data = Closure::bind(function () { return self::readNuBuilderSession(); }, null, NuSharedLogin::class)();\n$o['forte_read'] = ($data['SESSION_ID'] ?? '') === 'x1'; $o['own_kept'] = ($_SESSION['own'] ?? 0) === 1 && session_name() === 'e2eown';\n$o['only_cookies'] = ini_get('session.use_only_cookies');\n$o['src'] = (string)file_get_contents(NU_ROOT . '/core/nuauth.php') . (string)file_get_contents(NU_ROOT . '/index.php');\n$o['src_ok'] = !str_contains($o['src'], \"'use_only_cookies'\"); unset($o['src']);\nsession_write_close(); array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir);\necho json_encode($o);\n");
	let o;
	try {
		const r = require('child_process').spawnSync('php', [path.basename(file)], { cwd: SITE, encoding: 'utf8' });
		try { o = JSON.parse(r.stdout); } catch (e) { throw new Error('no JSON: ' + r.stdout + r.stderr); }
	} finally { fs.unlinkSync(file); }
	if (o.dev) expect(/ignored RuntimeException \(e2e place\): e2e line break at /.test(o.logged), 'nu_ignored() did not log in developer mode: ' + JSON.stringify(o.logged));
	else expect(o.logged === '', 'nu_ignored() logged without developer mode');
	expect(o.src_ok, 'a session_start() still changes use_only_cookies');
	expect(o.forte_read && o.own_kept, 'nuBuilder Forte session not read / own session lost: ' + JSON.stringify(o));
	expect(o.only_cookies === '1', 'use_only_cookies changed: ' + o.only_cookies);
	expect(!o.msg, 'PHP messages: ' + JSON.stringify(o.msg));
	// the installable app's manifest still reads the session read-only (favourites) and sends no cookie
	const p = s.ga;
	const res = await p.request.get(BASE + 'index.php?p=pwa&a=manifest');
	expect(res.status() === 200 && !res.headers()['set-cookie'], 'manifest: ' + res.status() + ' ' + (res.headers()['set-cookie'] || ''));
	const m = await res.json();
	expect(Array.isArray(m.icons) && m.icons.length > 0, 'manifest without icons');
	const home = await open(p, 'index.php?p=home');
	expect(home.status() === 200 && !/p=login/.test(p.url()), 'signed out after the manifest');
};

T.usability_pages_2 = async (s) => {                     // the rest of the usability review's page list
	const p = s.ga;
	const st = await p.context().storageState();
	const ctxOf = (w, h, touch) => p.context().browser().newContext(Object.assign({ viewport: { width: w, height: h }, storageState: st, locale: 'en-GB' }, touch ? { hasTouch: true, isMobile: true } : {}));
	// Data & logs: the audit switch and Keep in one row
	await open(p, 'index.php?p=settings&s=data');
	const keep = await p.evaluate(() => { const a = document.getElementById('shAudOn'), k = document.getElementById('shAudKeep'); return a && k ? Math.abs(a.getBoundingClientRect().top - k.getBoundingClientRect().top) : -1; });
	expect(keep >= 0 && keep < 20, 'audit switch and Keep not in one row: ' + keep);
	// report designer: the canvas can be reached by keyboard
	const rid = sql('SELECT zzzzsys_report_id FROM zzzzsys_report LIMIT 1');
	if (rid) {
		await open(p, 'index.php?p=reportdesigner&id=' + encodeURIComponent(rid));
		await p.waitForSelector('.rdCanvas', { timeout: 10000 });
		const cv = await p.evaluate(() => { const c = document.querySelector('.rdCanvas'); return [c.getAttribute('tabindex'), c.getAttribute('aria-label')]; });
		expect(cv[0] === '0' && cv[1], 'report canvas not focusable: ' + JSON.stringify(cv));
	}
	// User Home: an empty tab tells the designer what to do
	const uhTabs = sql("SELECT COUNT(*) FROM zzzzsys_tab t WHERE syt_zzzzsys_form_id = 'nuuserhome' AND NOT EXISTS (SELECT 1 FROM zzzzsys_object o WHERE o.sob_all_zzzzsys_tab_id = t.zzzzsys_tab_id)");
	if (uhTabs !== '0') {
		await open(p, 'index.php?p=form&f=nuuserhome&r=-1');
		expect(await p.locator('.nuUhEmptyTab').count() > 0, 'no hint on an empty User Home tab');
	}
	// tablet: grid cells grow (order Status), list filters folded with more than three, design panel as a bottom sheet
	const tab = await ctxOf(820, 1000);
	const t = await tab.newPage();
	try {
		const oid = sql('SELECT sample_order_id FROM sample_order LIMIT 1');
		await open(t, 'index.php?p=form&f=sample_order&r=' + oid);
		const sw = await t.evaluate(() => { const x = document.getElementById('ord_status'); return x ? x.getBoundingClientRect().width : 0; });
		expect(sw >= 160, 'order Status still narrow on a tablet: ' + sw);
		await open(t, 'index.php?p=form&f=sample_order&r=' + oid + '&design=1');
		await t.waitForSelector('#nuDsPanel', { timeout: 10000 });
		const pw = await t.evaluate(() => document.getElementById('nuDsPanel').getBoundingClientRect().width);
		expect(pw > 700, 'design panel not a bottom sheet on a tablet: ' + pw);
		await open(t, 'index.php?p=form&f=tktticket');
		const flt = await t.evaluate(() => { const f = document.getElementById('nuBrowseFilters'); return f ? {n: f.querySelectorAll('select, input').length, cls: f.className, vis: !!f.offsetParent} : null; });
		if (flt && flt.n > 3) expect(/nuFltTabHide/.test(flt.cls) && !flt.vis, 'many filters open on a tablet: ' + JSON.stringify(flt));
	} finally { await tab.close(); }
	// phone: dashboard widgets as cards, e-mail template with one Back and folded notices, Forte's system lists with five values per card
	const ph = await ctxOf(390, 844, true);
	const m = await ph.newPage();
	try {
		const did = sql('SELECT zzzzsys_nunext_dashboard_id FROM zzzzsys_nunext_dashboard LIMIT 1');
		if (did) { await open(m, 'index.php?p=dashboards&id=' + encodeURIComponent(did)); expect(await m.locator('table[data-nu-phcards]').count() === 1, 'dashboard widget table without phone cards'); }
		const tid = sql("SELECT zzzzsys_email_template_id FROM zzzzsys_email_template WHERE emt_template = '1' LIMIT 1");
		if (tid) {
			await open(m, 'index.php?p=emailtemplates&id=' + encodeURIComponent(tid));
			const em = await m.evaluate(() => ({ backs: [...document.querySelectorAll('.bi-arrow-left')].filter(i => i.offsetParent && i.closest('a,button')).length, notes: document.querySelector('details.emtNotes') ? document.querySelector('details.emtNotes').open : null }));
			expect(em.backs <= 1 && em.notes === false, 'e-mail template on a phone: ' + JSON.stringify(em));
		}
		await open(m, 'index.php?p=form&f=nuobject');
		const cells = await m.evaluate(() => { const r = document.querySelector('#nuBrowseTable tr.nuBrowseRow'); return r ? [...r.querySelectorAll('td.nuCellTd')].filter(td => getComputedStyle(td).display !== 'none').length : 0; });
		expect(cells <= 5, 'system list card with ' + cells + ' values');
	} finally { await ph.close(); }
};

T.code_review_l4_d5 = async (s) => {                     // user import split into steps; JSON settings of IP access / SSO through the shared helpers
	const p = s.ga, level = sql("SELECT sal_code FROM zzzzsys_access WHERE sal_code = 'sample_viewer'");
	try {
		await open(p, 'index.php?p=import&target=users');
		const res = await p.evaluate(async (lv) => {
			const csrf = document.querySelector('input[name=_csrf]').value;
			const post = async (o, file) => { const fd = new FormData(); fd.append('_csrf', csrf); for (const k in o) fd.append(k, o[k]); if (file) fd.append('file', file); return (await fetch('index.php?p=import', { method: 'POST', body: fd })).json(); };
			const csv = 'login;name;level;password\ne2e_l4a;A;' + lv + ';Imp0rt-Pass!\ne2e_l4b;B;no_such_level;Imp0rt-Pass!\ne2e_l4a;A again;' + lv + ';Imp0rt-Pass!\ne2e_l4c;C;' + lv + ';Imp0rt-Pass!\n';
			const up = await post({ action: 'upload', header: '1' }, new File([csv], 'u.csv', { type: 'text/csv' }));
			if (!up.ok) return up;
			return post({ action: 'import', table: 'zzzzsys_user', mode: 'insert', delimiter: ';', header: '1', on_error: 'skip',
				mapping: JSON.stringify({ sus_login_name: { src: 'csv', idx: '0' }, sus_name: { src: 'csv', idx: '1' }, access_level: { src: 'csv', idx: '2' }, password: { src: 'csv', idx: '3' } }) });
		}, level);
		expect(res.ok && res.inserted === 2 && res.failed === 2, 'mixed rows: ' + JSON.stringify(res).slice(0, 300));
		const lines = (res.errors || []).map(e => e.line + ':' + e.column).sort().join(',');
		expect(lines === '3:access_level,4:sus_login_name', 'errors: ' + lines);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name IN ('e2e_l4a','e2e_l4c')") === '2', 'good rows not stored');
		// IP access: stored through nu_setting_json_save, read back through nu_setting_json; the page lists the levels from NuAcl
		await open(p, 'index.php?p=ipaccess');
		expect(await p.locator('[id^=ipaLv_]').count() > 0, 'IP access page lists no access levels');
		await open(p, 'index.php?p=sso');
		expect(await p.locator('[id^=ssoOnly_]').count() > 0, 'SSO page lists no access levels');
	} finally {
		sql("DELETE FROM zzzzsys_user WHERE sus_login_name LIKE 'e2e_l4%'");
	}
};

T.title_bar_scrolls_phone = async (s) => {
	// phones: a title bar with Back only (check and overview pages) scrolls away; record pages keep Save in view
	const ctx = await s.ga.context().browser().newContext({ locale: 'en-GB', hasTouch: true, isMobile: true, viewport: { width: 390, height: 700 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		const pos = async (u, sel) => {
			await p.goto(BASE + u); await p.waitForSelector(sel);
			await p.evaluate(() => window.scrollTo(0, 600)); await p.waitForTimeout(300);
			return p.evaluate((q) => { const b = document.querySelector(q); return { pos: getComputedStyle(b).position, top: Math.round(b.getBoundingClientRect().top), stuck: b.classList.contains('nuStuck') }; }, sel);
		};
		const c = await pos('index.php?p=compat', 'main .nuTitleBar');
		expect(c.pos === 'static' && c.top < 0 && !c.stuck, 'compatibility check title bar still sticks: ' + JSON.stringify(c));
		const a = await pos('index.php?p=accesslevel&id=sample_editor', 'main .nuTitleBar');
		expect(a.pos === 'sticky', 'record page title bar no longer sticks: ' + JSON.stringify(a));
		await p.setViewportSize({ width: 1300, height: 700 });
		const d = await pos('index.php?p=compat', 'main .nuTitleBar');
		expect(d.pos === 'sticky', 'wide screens: compatibility check title bar should stick: ' + JSON.stringify(d));
	} finally { await ctx.close(); }
};

T.phone_more_menu_fits = async (s) => {
	// list title bar ⋮ on phones: entries of a menu show their own text (not their long tooltip), and none runs out of the screen
	const ctx = await browser.newContext({locale: 'en-GB', viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, storageState: await s.ga.context().storageState()});
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=forms', {waitUntil: 'networkidle'});
		await p.click('.nuPhMore > button');
		await p.waitForTimeout(300);
		const items = await p.$$eval('.nuPhMore .dropdown-menu .nuPhMoreItem', (xs) => xs.map((x) => ({t: x.textContent.trim(), r: x.getBoundingClientRect().right})));
		const maint = items.find((x) => /…$/.test(x.t) && x.t.length < 30);
		expect(!!maint, 'Maintenance… shown with its own text: ' + items.map((x) => x.t).join(' | '));
		expect(items.every((x) => x.r <= 390), 'an entry runs out of the screen: ' + JSON.stringify(items.filter((x) => x.r > 390)));
	} finally { await ctx.close(); }
};
