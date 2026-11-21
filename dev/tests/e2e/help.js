// e2e tests: help, tutorial, To do – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// 0.8.528: F1 opens the documentation page of the page that is open (new tab); pages without one leave F1 alone
T.help_f1 = async (s) => {
	const p = s.ga;
	const f1 = async (path, want) => {
		await open(p, path);
		const [tab] = await Promise.all([p.context().waitForEvent('page', { timeout: 5000 }), p.keyboard.press('F1')]);
		await tab.waitForLoadState('domcontentloaded');
		const ok = /p=help/.test(tab.url()) && tab.url().endsWith('#' + want);
		await tab.close();
		expect(ok, path + ' → ' + tab.url());
	};
	await f1('index.php?p=users', 'users');
	await f1('index.php?p=form&f=samplecustomer', 'forms-use');
	await f1('index.php', 'home-page');
	await open(p, 'index.php?p=help');
	expect(await p.evaluate(() => document.body.getAttribute('data-nu-help')) === null, 'no F1 link on the help page');
	expect(await p.locator('.dropdown-menu a[href$="p=help"][target=_blank]').count() === 1, 'user menu: documentation opens in a new tab (0.8.541)');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.622: tutorial in two versions – choice on first opening (a lesson link keeps the lesson), Basic leaves the advanced lessons out,
// an advanced lesson opened from Basic has a note + switch, the switch keeps the progress
T.tutorial_tracks = async (s) => {
	const p = s.ga;
	const old = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'tutorial_ga'");
	const put = v => sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'tutorial_ga';"
		+ (v && v !== 'NULL' ? " INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value, updated_at, updated_by) VALUES ('tutorial_ga', '" + v.replace(/'/g, "''") + "', NOW(), 'e2e')" : ''));
	try {
		put('{"done":["welcome"],"hidden":false}');
		await open(p, 'index.php?p=tutorial');
		expect(await p.locator('#tutChoose .tutChoice').count() === 3 && await p.locator('#tutTrack').count() === 0, 'no choice screen with three tutorials on first opening');
		await open(p, 'index.php?p=tutorial&l=adv-drafts');
		expect(await p.locator('#tutChoose input[name=l]').first().inputValue() === 'adv-drafts', 'lesson link: the lesson is not kept on the choice screen');
		await open(p, 'index.php?p=tutorial');
		await Promise.all([p.waitForNavigation(), p.click('#tutChoose_basic')]);
		expect(/[?&]l=start-page/.test(p.url()), 'Basic: not on the first open lesson: ' + p.url());
		expect(await p.locator('#tutTrack button[data-track=basic][aria-pressed=true]').count() === 1, 'switch does not show Basic');
		const basic = await p.locator('.tutLessonLink').count();
		expect(await p.locator('.tutLessonLink[href*="l=adv-lifecycle"]').count() === 0 && await p.locator('.tutLessonLink[href*="l=sessions"]').count() === 0, 'Basic lists advanced lessons');
		expect(await p.locator('.tutLessonLink[href*="l=next-steps"]').count() === 1, 'Basic: no Next steps');
		await open(p, 'index.php?p=tutorial&l=adv-lifecycle');
		expect(await p.locator('#tutOutside').count() === 1 && await p.locator('.tutLesson .tutAdvBadge').count() === 1, 'advanced lesson in Basic: no note / badge');
		await Promise.all([p.waitForNavigation(), p.click('#tutOutside button[type=submit]')]);
		expect(/[?&]l=adv-lifecycle/.test(p.url()) && await p.locator('#tutOutside').count() === 0, 'switch from the note: not on the lesson in Advanced ' + p.url());
		const adv = await p.locator('.tutLessonLink').count();
		expect(adv > basic + 10 && await p.locator('.tutLessonLink[href*="l=adv-lifecycle"]').count() === 1, 'Advanced: lessons ' + basic + ' / ' + adv);
		const st = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'tutorial_ga'"));
		expect(st.track === 'advanced' && st.done.includes('welcome'), 'progress / track not kept: ' + JSON.stringify(st));
		await Promise.all([p.waitForNavigation(), p.click('#tutTrack button[data-track=basic]')]);
		expect(/[?&]l=adv-lifecycle/.test(p.url()) && await p.locator('#tutOutside').count() === 1, 'switch back to Basic on an advanced lesson ' + p.url());
		// 0.8.635: third tutorial "Coming from nuBuilder Forte" – own chapters (parts What works differently / New), none of the others
		await Promise.all([p.waitForNavigation(), p.click('#tutTrack button[data-track=forte]')]);
		await open(p, 'index.php?p=tutorial');
		expect(await p.locator('.tutLessonLink[href*="l=forte-welcome"]').count() === 1 && await p.locator('.tutLessonLink[href*="l=forte-new-ops"]').count() === 1, 'Forte tutorial: lessons missing');
		expect(await p.locator('.tutLessonLink[href*="l=start-page"]').count() === 0 && await p.locator('.tutLessonLink[href*="l=adv-lifecycle"]').count() === 0, 'Forte tutorial lists other lessons');
		expect(await p.locator('.tutLessonLink').count() < basic, 'Forte tutorial longer than Basic');
		await Promise.all([p.waitForNavigation(), p.click('#tutTrack button[data-track=basic]')]);
		await open(p, 'index.php?p=tutorial');
		expect(await p.locator('.tutLessonLink[href*="l=forte-welcome"]').count() === 0, 'Basic lists a lesson of the Forte tutorial');
		await open(p, 'index.php?p=tutorial&l=forte-code');
		expect(await p.locator('#tutOutside input[name=track][value=forte]').count() === 1, 'Forte lesson in Basic: no switch to the Forte tutorial');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { put(old); }
};

T.help_fnref = async (s) => {                           // 0.8.399: PHP / JavaScript function reference in the help, with search and A–Z index
	const p = s.ga;
	await open(p, 'index.php?p=help&tab=ref'); await p.waitForSelector('#ref-php .nuFn', { state: 'attached' });
	const php = await p.locator('#ref-php .nuFn').count(), js = await p.locator('#ref-js .nuFn').count();
	expect(php > 150 && js > 300, 'reference entries missing: ' + php + ' / ' + js);
	expect(await p.locator('#helpTocRef a[href="#ref-php-h"]').count() === 1 && await p.locator('#helpTocRef a[href="#ref-js-h"]').count() === 1, 'tabs not in the contents');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-help-fntab'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=help#ref'); await p.waitForSelector('#ref-php .nuFn', { state: 'attached' }); await p.waitForTimeout(300);
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
	await p.goto(p.url().split('#')[0] + '#fn-js-nuGetValue'); await p.waitForTimeout(1200);
	expect(await p.locator('#fn-js-nuGetValue').isVisible(), 'link to a JS function does not open the JS tab');
	await open(p, 'index.php?p=help&doc=user');
	expect(await p.locator('#ref-php, #ref-js').count() === 0 && await p.locator('#helpTabs').count() === 0, 'reference in the user view');
};

T.help_tabs = async (s) => {                              // 0.8.464: guide and code reference as two tabs, the reference loaded on demand
	const p = s.ga;
	const loads = []; const on = r => { if (/part=ref/.test(r.url())) loads.push(r.url()); };
	p.on('request', on);
	try {
		await open(p, 'index.php?p=help');
		expect(await p.locator('#helpTabs [data-help-tab]').count() === 2 && await p.locator('#helpBody #install-h').count() === 1, 'no tabs / guide');
		expect(await p.locator('#helpBody #dev-h, #helpBody #ref-php').count() === 0 && loads.length === 0, 'code chapters in the guide or reference loaded at once');
		await p.click('#helpTabRef'); await p.waitForSelector('#helpRef #dev-h', { state: 'attached' });
		expect(/tab=ref/.test(p.url()) && await p.locator('#helpPaneGuide').isHidden() && await p.locator('#helpTocRef a').count() > 10, 'code reference tab');
		await open(p, 'index.php?p=help#compat-h'); await p.waitForTimeout(1500);
		expect(await p.locator('#compat-h').isVisible() && await p.locator('#helpTabRef').getAttribute('aria-selected') === 'true', 'link into the reference does not open its tab');
		await p.click('#helpTabGuide'); await p.waitForTimeout(300);
		await p.fill('#helpSearch', 'nuSetNuDataValue'); await p.waitForTimeout(500);
		expect(await p.locator('#helpNone').isVisible(), 'guide search should find nothing');
		await p.check('#helpAll'); await p.waitForTimeout(1200);
		expect(await p.locator('#helpTabRef').getAttribute('aria-selected') === 'true' && await p.locator('#helpRef mark.nuHelpHit').count() > 0, 'search in both tabs');
		await p.fill('#helpSearch', ''); await p.uncheck('#helpAll');
		const r = await p.evaluate(async () => (await fetch('index.php?p=help&part=ref&doc=user')).text());
		expect(!/id="dev-h"|id="ref-php"/.test(r), 'reference for the user preview contains admin chapters');
		const anna = await login('sample_anna', UPW);
		await open(anna, 'index.php?p=help');
		expect(await anna.locator('#helpTabs').count() === 0, 'user without rights sees the code reference tab');
		await anna.context().close();
	} finally {
		p.off('request', on);
	}
};

// help as one file per page with toc.json (0.8.493): chapter → page index, numbering, reading level (0.8.884: Essentials / Standard /
// Everything instead of the switch "Show advanced topics"; the old switch's "on" is taken over), a link into an advanced page shows it, old ids work
T.help_pages = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=help');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-help-level'); localStorage.setItem('nunext-help-advanced', '0'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=help'); await p.waitForTimeout(300);
	expect(await p.locator('#helpLevel').count() === 1 && await p.locator('#helpLvl_standard').isChecked(), 'no reading level / not Standard');
	expect(await p.evaluate(() => document.documentElement.classList.contains('nuHelpBasic')) && await p.locator('#save-flow').isHidden(), 'advanced page shown at Standard');
	expect(await p.locator('#forms-use').count() === 1 && await p.locator('#lists').count() === 1, 'old ids gone');
	await p.click('label[for="helpLvl_everything"]'); await p.waitForTimeout(300);
	expect(await p.locator('#save-flow').isVisible(), 'advanced page hidden at Everything');
	await open(p, 'index.php?p=help'); await p.waitForTimeout(300);
	expect(await p.locator('#helpLvl_everything').isChecked(), 'level not remembered');
	await p.click('label[for="helpLvl_standard"]'); await p.waitForTimeout(200);
	await open(p, 'index.php?p=help#save-flow'); await p.waitForTimeout(800);
	expect(await p.locator('#save-flow').isVisible(), 'link into an advanced page did not show it');
	// the old switch: "on" becomes Everything
	await p.evaluate(() => { try { localStorage.removeItem('nunext-help-level'); localStorage.setItem('nunext-help-advanced', '1'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=help'); await p.waitForTimeout(300);
	expect(await p.locator('#helpLvl_everything').isChecked(), 'old advanced switch not taken over');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-help-level'); localStorage.removeItem('nunext-help-advanced'); } catch (e) { /* */ } });
};

// reading level Essentials (0.8.884): detail passages hidden with a "…" button that opens them in place, source boxes hidden,
// a search finds hidden passages, Standard shows them again
T.help_level = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=help');
	await p.evaluate(() => { try { localStorage.setItem('nunext-help-level', 'essentials'); } catch (e) { /* */ } });
	try {
		await open(p, 'index.php?p=home');
		await open(p, 'index.php?p=help#object-types'); await p.waitForTimeout(600);
		const st = await p.evaluate(() => {
			const det = Array.from(document.querySelectorAll('#helpBody [data-level="detail"]'));
			const src = document.querySelector('#helpBody details.doc-src:not(.doc-keep)');
			return { lite: document.documentElement.classList.contains('nuHelpLite'), n: det.length, shown: det.filter(e => e.offsetParent !== null).length,
				btns: document.querySelectorAll('#helpBody .nuDetailMore:not([hidden]) .nuDetailBtn').length, src: src ? src.offsetParent !== null : null };
		});
		expect(st.lite && st.n > 20 && st.shown === 0 && st.btns > 0 && st.src === false, 'Essentials: ' + JSON.stringify(st));
		// the first … of the object types page opens its group in place
		const sec = p.locator('#object-types-p');
		const btn = sec.locator('.nuDetailMore .nuDetailBtn').first();
		await btn.scrollIntoViewIfNeeded(); await btn.click(); await p.waitForTimeout(200);
		const opened = await p.evaluate(() => { const b = document.querySelector('#object-types-p .nuDetailMore'); let e = b.previousElementSibling;
			return { hidden: b.hidden, vis: e && e.getAttribute('data-level') === 'detail' && e.offsetParent !== null }; });
		expect(opened.hidden && opened.vis, 'the … did not open the passage: ' + JSON.stringify(opened));
		// a search finds a hidden passage (text only in a detail of the upload section)
		await p.fill('#helpSearch', 'x.php.pdf'); await p.keyboard.press('Enter'); await p.waitForTimeout(500);
		const hit = await p.evaluate(() => { const m = Array.from(document.querySelectorAll('#helpBody mark')).find(x => /x\.php\.pdf/i.test(x.closest('[data-level="detail"]') ? x.closest('[data-level="detail"]').textContent : ''));
			return m ? m.offsetParent !== null : false; });
		expect(hit, 'search did not show the hidden passage');
		await p.fill('#helpSearch', ''); await p.keyboard.press('Enter'); await p.waitForTimeout(300);
		await p.click('label[for="helpLvl_standard"]'); await p.waitForTimeout(300);
		expect(await p.evaluate(() => !document.documentElement.classList.contains('nuHelpLite')
			&& Array.from(document.querySelectorAll('#helpBody [data-level="detail"]')).every(e => e.offsetParent !== null || e.closest('[hidden]') || e.closest('[data-level="advanced"]'))), 'Standard hides details');
	} finally {
		await p.evaluate(() => { try { localStorage.removeItem('nunext-help-level'); } catch (e) { /* */ } });
	}
};

T.help_dev = async (s) => {                               // 0.8.408: chapter "Development with Claude" only in developer mode
	const p = s.ga;
	await open(p, 'index.php?p=help&tab=ref'); await p.waitForSelector('#helpRef #dev-h', { state: 'attached' });
	const dev = (await p.evaluate(async () => (await fetch('index.php?p=selftest')).status)) !== 404;
	expect((await p.locator('#devflow-h').count() === 1) === dev, 'developer chapter shown: ' + !dev);
	if (dev) expect(await p.locator('#devflow-key, #devflow-gate, #devflow-manual, #devflow-zips').count() === 4, 'developer chapter incomplete');
	await open(p, 'index.php?p=help&doc=user');
	expect(await p.locator('#devflow-h').count() === 0, 'developer chapter in the user preview');
};

// To do page (0.8.467): sections fold, one button folds / opens all, folded sections remembered per device
T.todo_fold = async (s) => {
	const p = s.ga;
	const r = await open(p, 'index.php?p=todo');
	if (r.status() === 404) { s.skip = 'developer mode off'; return; }
	if (!(await p.locator('#nuTodoAll').count())) { s.skip = 'TODO.md missing or one section'; return; }
	const n = await p.locator('.nuTodoSec').count(), openN = () => p.locator('.nuTodoSec[open]').count();
	expect(n > 1 && await openN() === 0 && /Expand all/.test(await p.textContent('#nuTodoAll')), 'sections not all folded at first (0.8.509): ' + n);
	expect(await p.locator('.nuTodoSec .nuTodoOpen').count() > 0, 'no count of open points');
	await p.click('.nuTodoSec >> nth=0 >> summary'); await p.waitForTimeout(200);
	expect(await openN() === 1 && /Expand all/.test(await p.textContent('#nuTodoAll')), 'one section');
	await p.click('#nuTodoAll'); await p.waitForTimeout(200);
	expect(await openN() === n && /Collapse all/.test(await p.textContent('#nuTodoAll')), 'expand all');
	await p.click('#nuTodoAll'); await p.waitForTimeout(200);
	expect(await openN() === 0 && /Expand all/.test(await p.textContent('#nuTodoAll')), 'collapse all');
	await open(p, 'index.php?p=todo');
	expect(await openN() === 0, 'not folded again after opening the page');
};

// To do page (0.8.712): upload nunext-dev.zip → only TODO.md is taken (missing or replaced, no question), the upload is not kept
T.todo_upload = async (s) => {
	const p = s.ga;
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const r = await open(p, 'index.php?p=todo');
	if (r.status() === 404) { s.skip = 'developer mode off'; return; }
	const file = path.join(SITE, 'TODO.md'), keep = fs.existsSync(file) ? fs.readFileSync(file) : null;
	const tmp = fs.mkdtempSync(path.join(require('os').tmpdir(), 'e2etodo-'));
	const zipWith = (name, body) => {   // a zip as nunext-dev.zip: nunext/TODO.md + another file
		fs.mkdirSync(path.join(tmp, 'z', 'nunext'), { recursive: true });
		if (body !== null) fs.writeFileSync(path.join(tmp, 'z', 'nunext', 'TODO.md'), body); else fs.rmSync(path.join(tmp, 'z', 'nunext', 'TODO.md'), { force: true });
		fs.writeFileSync(path.join(tmp, 'z', 'nunext', 'README.md'), '# x');
		const z = path.join(tmp, name); fs.rmSync(z, { force: true });
		require('child_process').execFileSync('python3', ['-c', 'import shutil,sys; shutil.make_archive(sys.argv[1][:-4], "zip", sys.argv[2])', z, path.join(tmp, 'z')]);
		return z;
	};
	try {
		fs.rmSync(file, { force: true });
		await open(p, 'index.php?p=todo');
		expect(await p.locator('.alert-warning').count() === 1 && /nunext-dev\.zip/.test(await p.textContent('#nuTodoUpload')), 'missing: no upload button');
		await Promise.all([p.waitForNavigation(), p.setInputFiles('#nuTodoFile', zipWith('nunext-dev.zip', '# To do\n\n## One\n- [ ] e2e first\n\n## Two\n- [x] done\n'))]);
		expect(fs.existsSync(file) && /e2e first/.test(fs.readFileSync(file, 'utf8')) && await p.locator('text=e2e first').count() === 1, 'TODO.md not taken from the zip');
		const left = fs.readdirSync(SITE).filter(n => /\.zip$|TODO\.md\.part/.test(n));
		expect(left.length === 0, 'files left in the folder: ' + left.join(', '));
		// replace without asking
		await Promise.all([p.waitForNavigation(), p.setInputFiles('#nuTodoFile', zipWith('nunext-dev.zip', '# To do\n\n## One\n- [ ] e2e second\n'))]);
		expect(/e2e second/.test(fs.readFileSync(file, 'utf8')), 'not replaced');
		// a zip without TODO.md → error, file unchanged
		await Promise.all([p.waitForNavigation(), p.setInputFiles('#nuTodoFile', zipWith('nunext.zip', null))]);
		expect(await p.locator('.alert-danger').filter({ hasText: 'TODO.md' }).count() > 0 && /e2e second/.test(fs.readFileSync(file, 'utf8')), 'zip without TODO.md');
		expect(/todo_uploaded/.test(sql("SELECT GROUP_CONCAT(event) FROM zzzzsys_nunext_log WHERE event = 'todo_uploaded'")), 'not logged');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (keep) fs.writeFileSync(file, keep); else fs.rmSync(file, { force: true });
		fs.rmSync(tmp, { recursive: true, force: true });
		sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'todo_uploaded'");
	}
};

T.help_link_forms = async (s) => {
	// 0.8.1054: help page editor → "Used on": Add to a form… (form / tab / field), replace asks, × removes; an open draft gets the change too (no publish conflict)
	const p = s.ga;
	let step = 'start';
	if (sql("SELECT COUNT(*) FROM zzzzsys_nunext_help WHERE code = 'tkt_help_kb' AND lang = ''") !== '1') { s.skip = 'ticket sample not installed'; return; }
	const meta = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_nunext_form_history', 'zzzzsys_nunext_form_draft'];
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-uhlink.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	try {
		await open(p, 'index.php?p=helppages&id=tkt_help_005');
		const api = (body) => p.evaluate(async (bd) => {
			const csrf = document.querySelector('input[name=_csrf]').value;
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(bd) });
			return r.json();
		}, body);
		let r = await api({ action: 'draft.start', form_id: 'tktcategory', note: 'e2e' });
		expect(r.ok && r.draft, 'draft start: ' + JSON.stringify(r).slice(0, 200));
		const D = r.draft;
		await open(p, 'index.php?p=helppages&id=tkt_help_005');
		const items = () => p.locator('#uhUsage li').count();
		const n0 = await items();
		const pick = async (form, place, replace) => {
			step = 'add ' + place;
			await p.click('#uhLinkAdd', { timeout: 8000 });
			await p.locator('#uhLinkDlg.show').waitFor();
			await p.waitForTimeout(400);                         // the modal takes the focus at the end of its fade
			await p.locator('#uhLinkDlg .ts-wrapper .ts-control').click();
			await p.locator('#uhLinkDlg .ts-dropdown input.dropdown-input').waitFor();
			await p.keyboard.type(form);
			await p.waitForTimeout(300);
			await p.keyboard.press('Enter');
			await p.waitForFunction(() => !document.getElementById('uhLinkPlace').disabled);
			const vals = await p.evaluate(() => [...document.getElementById('uhLinkPlace').options].map((o) => o.value));
			expect(vals.includes(place), 'place ' + place + ' not offered: ' + vals.join(', ') + ' / form ' + await p.inputValue('#uhLinkForm'));
			await p.selectOption('#uhLinkPlace', place);
			if (replace) expect(await p.getAttribute('#uhLinkGo', 'class') === 'btn btn-warning' && !(await p.locator('#uhLinkCur').isHidden()), 'no replace question');
			step = 'go ' + place + ' ' + await p.evaluate(() => document.activeElement.id + '.' + document.activeElement.className + ' open=' + (document.querySelector('#uhLinkDlg .ts-dropdown').offsetParent !== null));
			await p.click('#uhLinkGo', { timeout: 8000 });
		};
		// a field of the category form (has an open draft)
		const obj = sql("SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'tktcategory' AND sob_all_id = 'cat_name'");
		await pick('categories', 'field:' + obj);
		await p.locator('#uhLinkDlg').waitFor({ state: 'hidden' });
		expect(await items() === n0 + 1, 'usage list not updated');
		expect(sql("SELECT sob_all_json FROM zzzzsys_object WHERE zzzzsys_object_id = '" + obj + "'").includes('tkt_help_kb'), 'field not linked');
		const dr = sql("SELECT CONCAT(snapshot LIKE '%tkt_help_kb%', base_snapshot LIKE '%tkt_help_kb%') FROM zzzzsys_nunext_form_draft WHERE zzzzsys_nunext_form_draft_id = '" + D.id + "'");
		expect(dr === '11', 'open draft / its base not changed: ' + dr);
		// the whole ticket form shows another page → asked, then replaced
		await pick('tkt_ticket', 'form', true);
		await p.locator('#uhLinkDlg').waitFor({ state: 'hidden' });
		expect(/"help":"tkt_help_kb"/.test(sql("SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = 'tktticket'")), 'form help not replaced');
		// × removes the field link again
		await p.locator('.uhUnlink[data-place="field:' + obj + '"]').click();
		await p.locator('.modal.show .btn-danger').click();
		await p.waitForFunction((o) => !document.querySelector('.uhUnlink[data-place="field:' + o + '"]'), obj);
		expect(!sql("SELECT IFNULL(sob_all_json, '') FROM zzzzsys_object WHERE zzzzsys_object_id = '" + obj + "'").includes('tkt_help_kb'), 'field link not removed');
		// publishing the draft: no conflict (its base followed the live form)
		r = await api({ action: 'draft.publish', form_id: 'tktcategory', draft_id: D.id, note: 'e2e' });
		expect(r.ok && !r.conflict, 'publish after linking: ' + JSON.stringify(r).slice(0, 200));
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'userhelp_linked' AND record_id = 'tktticket'") !== '0', 'not logged');
	} catch (e) { await p.screenshot({ path: path.join(require('os').tmpdir(), 'uhlink-fail.png') }); throw new Error(step + ': ' + e.message.split('\n')[0]); } finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(keep) });
	}
};

T.help_panel_resize = async (s) => {
	// 0.8.1052: the help panel of form pages gets a splitter on its left edge – drag, keys, double-click; width kept per browser
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_nunext_help WHERE code = 'tkt_help_ticket' AND lang = ''") !== '1') { s.skip = 'ticket sample not installed'; return; }
	const vp = p.viewportSize();
	try {
	await p.setViewportSize({ width: 1300, height: 850 });
	await p.evaluate(() => { try { localStorage.removeItem('nunext-uh-w'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=form&f=tktticket&r=-1');
	await p.click('#nuHelpButton');
	await p.waitForSelector('#nuUhPanel:not([hidden]) .nuUhSplit');
	const w = () => p.evaluate(() => Math.round(document.getElementById('nuUhPanel').getBoundingClientRect().width));
	expect(await w() === 440, 'default width ' + await w());
	const box = await p.locator('.nuUhSplit').boundingBox();
	await p.mouse.move(box.x + 4, box.y + 200);
	await p.mouse.down();
	await p.mouse.move(box.x - 150, box.y + 200, { steps: 6 });
	await p.mouse.up();
	const dragged = await w();
	expect(dragged >= 580 && dragged <= 600, 'dragged width ' + dragged);
	expect(await p.evaluate(() => localStorage.getItem('nunext-uh-w')) === String(dragged), 'width not stored');
	await p.focus('.nuUhSplit'); await p.keyboard.press('ArrowRight');
	expect(await w() === dragged - 20, 'arrow key');
	await p.mouse.move(box.x - 300, box.y + 400);
	await open(p, 'index.php?p=form&f=tktticket&r=-1');
	await p.click('#nuHelpButton');
	await p.waitForSelector('#nuUhPanel:not([hidden])');
	expect(await w() === dragged - 20, 'width not kept after reload: ' + await w());
	await p.dblclick('.nuUhSplit');
	expect(await w() === 440 && await p.evaluate(() => localStorage.getItem('nunext-uh-w')) === null, 'double-click = default');
	await p.setViewportSize({ width: 390, height: 800 });
	expect(await w() === 390 && !(await p.locator('.nuUhSplit').isVisible()), 'phones: full width, no splitter');
	} finally {
		await p.setViewportSize(vp);   // the shared page: a phone width left here broke every later test (gate 0.8.1060)
		await p.evaluate(() => { try { localStorage.removeItem('nunext-uh-w'); } catch (e) { /* */ } });
	}
};
