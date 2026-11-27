// e2e tests: design mode, new forms, drafts, the list side pane – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// 0.8.688: design mode – a list column as badge with colour rules (sbr_json nunext.badge), drawn before nuOnLoad
T.design_list_badge = async (s) => {
	const p = s.ga, F = 'samplecustomer', keep = path.join(os.tmpdir(), 'nunext-e2e-badge.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_browse zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		const first = (await p.textContent('#nuBrowseTable div.nuCell[data-nu-column="0"] >> nth=0')).trim();
		await p.click('#nuBrowseTable th.nuBrowseTitle >> nth=0'); await p.waitForTimeout(400);
		await p.check('#nuDsPanel input[data-key="badge"]'); await p.waitForTimeout(1500);
		await p.waitForSelector('#nuDsBadgeAdd');
		expect(await p.locator('#nuBrowseTable div.nuCell[data-nu-column="0"] .badge').count() > 0, 'badge column without rules');
		await p.click('#nuDsBadgeAdd'); await p.waitForTimeout(1500);
		await p.fill('#nuDsBadgeRules .nuDsBrValue >> nth=0', first); await p.press('#nuDsBadgeRules .nuDsBrValue >> nth=0', 'Tab'); await p.waitForTimeout(1500);
		const js = sql(`SELECT jt.j FROM zzzzsys_nunext_form_draft d, JSON_TABLE(d.snapshot, '$.browse[*]' COLUMNS(o INT PATH '$.sbr_order', j TEXT PATH '$.sbr_json')) jt WHERE d.form_id = '${F}' AND d.status = 'open' ORDER BY jt.o LIMIT 1`);
		expect(/"badge":\[\{"equals"/.test(js), 'rule in the draft: ' + js);
		const bg = await p.evaluate(() => { const b = document.querySelector('#nuBrowseTable div.nuCell[data-nu-column="0"] .badge'); return b ? b.style.backgroundColor : ''; });
		expect(bg === 'rgb(13, 110, 253)', 'rule colour: ' + bg);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { execSync('mysql ' + DB, { input: fs.readFileSync(keep) }); fs.unlinkSync(keep); }
};

T.bsq_as_run = async (s) => {
	// 0.8.1187: the side pane's Browse SQL tab has Written | As run – the SQL this page ran, hash variables marked (filled / not set)
	// and listed, read only (Save off); the choice is remembered; Form info of a list has no SQL any more
	const p = s.ga, F = 'samplecustomer', q = (v) => v.replace(/\\/g, '\\\\').replace(/'/g, "''");
	const old = sql(`SELECT sfo_browse_sql FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`);
	try {
		sql(`UPDATE zzzzsys_form SET sfo_browse_sql = 'SELECT * FROM sample_customer WHERE (''#e2e_none#'' = ''#e2e_none#'' OR 1) AND ''#USER_ID#'' <> ''''' WHERE zzzzsys_form_id = '${F}'`);
		await p.evaluate(() => { try { localStorage.removeItem('nunext-bsq-run'); } catch (e) { /* */ } });
		await open(p, 'index.php?p=form&f=' + F);
		await p.keyboard.press('Control+Shift+B'); await p.waitForSelector('#nuBsqPane .nuBsqMode', { timeout: 8000 });
		expect(await p.locator('.nuBsqMode [data-mode="written"].active').count() === 1 && await p.isVisible('#nuBsqPane .nuBsqBody[data-code="sql"] .cm-content'), 'starts on Written');
		await p.click('.nuBsqMode [data-mode="run"]'); await p.waitForTimeout(200);
		const r = await p.evaluate(() => {
			const box = document.querySelector('#nuBsqPane .nuBsqRun');
			return {shown: !box.hidden, editor: !document.querySelector('#nuBsqPane .nuBsqBody[data-code="sql"]').hidden,
				fill: [...box.querySelectorAll('mark.nuBsqFill')].map((m) => m.textContent), unset: [...box.querySelectorAll('mark.nuBsqUnset')].map((m) => m.textContent),
				vars: [...box.querySelectorAll('.nuBsqRunVar')].map((v) => v.dataset.var + (v.classList.contains('nuBsqRunNo') ? ':no' : ':yes')),
				save: document.querySelector('#nuBsqPane .nuBsqSave').disabled, copy: box.querySelector('.nuBsqRunCopy').getAttribute('data-nu-copy-text')};
		});
		expect(r.shown && !r.editor && r.fill.length === 1 && r.unset.join() === '#e2e_none#,#e2e_none#', 'marks: ' + JSON.stringify(r));
		expect(r.vars.join() === 'e2e_none:no,USER_ID:yes' && r.save && /'#e2e_none#'/.test(r.copy) && !/#USER_ID#/.test(r.copy), 'variables / Save / copy: ' + JSON.stringify(r));
		// remembered on the next list; Written brings the editor back with Save
		await open(p, 'index.php?p=form&f=' + F);
		await p.keyboard.press('Control+Shift+B'); await p.waitForSelector('#nuBsqPane .nuBsqMode');
		expect(await p.isVisible('#nuBsqPane .nuBsqRun'), 'As run remembered');
		await p.click('.nuBsqMode [data-mode="written"]'); await p.waitForTimeout(300);
		expect(!(await p.isVisible('#nuBsqPane .nuBsqRun')) && await p.isVisible('#nuBsqPane .nuBsqBody[data-code="sql"] .cm-content') && !(await p.isDisabled('#nuBsqPane .nuBsqSave')), 'Written again');
		await p.click('#nuBsqPane .nuBsqClose');
		await p.click('#nuFormInfoBtn');
		expect(await p.locator('#nuFormInfo [data-key="browse_sql_run"], #nuFormInfo [data-key="browse_hash"]').count() === 0, 'Form info without the SQL as run');
	} finally {
		await p.evaluate(() => { try { localStorage.removeItem('nunext-bsq-run'); } catch (e) { /* */ } });
		sql(`UPDATE zzzzsys_form SET sfo_browse_sql = '${q(old)}' WHERE zzzzsys_form_id = '${F}'`);
	}
};

// stacking as in nuBuilder (0.8.567): absolute layout objects have no z-index – an element with z-index:-1 inside an HTML object (a
// framed box drawn by the form's CSS) lies behind the other objects, also behind ones drawn before it; content boxes too; both stay
// in front of the form's background
T.object_stacking = async (s) => {
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	if (!tab) { s.skip = 'no sample data'; return; }
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_html_code)
		VALUES ('e2estkbox', 'samplecustomer', '${tab}', 'html', 'e2e_stkbox', '', 999, 5, 20, 40, 20, '0', '<div class="e2eStkBox" style="position:absolute;z-index:-1;top:0;left:0;width:600px;height:260px;background:#fde;border:1px solid #c9c">box</div>'),
		('e2estkcb', 'samplecustomer', '${tab}', 'contentbox', 'e2e_stkcb', 'E2E box', 998, 0, 0, 700, 300, '0', NULL)`);
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
		const r = await p.evaluate(() => {
			const top = (id) => { const e = document.getElementById(id); const b = e.getBoundingClientRect(); const x = document.elementFromPoint(b.left + 6, b.top + b.height / 2); return x === e || e.contains(x); };
			const box = document.querySelector('.e2eStkBox'), bb = box.getBoundingClientRect();
			const hit = document.elementFromPoint(bb.right - 10, bb.bottom - 10);   // a free spot of the box: the box, not the card behind it
			return { name: top('cus_name'), email: top('cus_email'), boxVisible: !!hit && !!hit.closest('.e2eStkBox, .nuContentBoxContainer'), wrapZ: getComputedStyle(document.getElementById('nuw_e2e_stkbox') || box.closest('.nuAbs')).zIndex };
		});
		expect(r.name && r.email, 'objects covered by the HTML object\'s box: ' + JSON.stringify(r));
		expect(r.boxVisible && r.wrapZ === 'auto', 'box hidden behind the form or wrapper with z-index: ' + JSON.stringify(r));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id IN ('e2estkbox', 'e2estkcb')");
	}
};

// label menu (0.8.562): right-click on a label or a button opens it (nuBuilder 4.x), a left click on a label focuses the field,
// Ctrl + right-click leaves the browser's menu, from the keyboard (contextmenu without coordinates) at the element; not for other users
T.label_menu_rightclick = async (s) => {
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	if (!tab) { s.skip = 'no sample data'; return; }
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_input_type, sob_run_zzzzsys_form_id, sob_run_method, sob_run_type, sob_run_target)
		VALUES ('e2elmbtn', 'samplecustomer', '${tab}', 'input', 'e2e_lmbtn', 'Menu button', 991, 60, 700, 140, 30, '0', 'button', NULL, NULL, NULL, NULL),
		('e2elmrun', 'samplecustomer', '${tab}', 'run', 'e2e_lmrun', 'Menu run', 990, 100, 700, 140, 30, '0', NULL, 'sampleproduct', 'b', 'F', '0')`);
	// 0.8.569: an HTML object that draws its own box with a title
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_html_code)
		VALUES ('e2elmhtml', 'samplecustomer', '${tab}', 'html', 'e2e_lmhtml', '', 989, 150, 700, 300, 80, '0', '<div class="e2eBox"><div class="title" id="e2eBoxTitle">Box title</div><p id="e2eBoxText">Text of the box</p></div>')`);
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
		const head = () => p.locator('.nuObjMenu .nuOmHead b').textContent().catch(() => null);
		const shown = () => p.locator('.nuObjMenu').count();
		await p.click('#label_cus_name'); await p.waitForTimeout(200);
		expect(await shown() === 0 && await p.evaluate(() => document.activeElement && document.activeElement.id) === 'cus_name', 'left click: menu or no focus');
		await p.click('#label_cus_name', { button: 'right' }); await p.waitForTimeout(200);
		expect(await head() === 'cus_name', 'right-click on the label: ' + await head());
		await p.keyboard.press('Escape'); await p.waitForTimeout(100);
		for (const id of ['e2e_lmbtn', 'e2e_lmrun']) {
			await p.click('#' + id, { button: 'right' }); await p.waitForTimeout(200);
			expect(await head() === id, 'right-click on the button ' + id + ': ' + await head());
			await p.keyboard.press('Escape'); await p.waitForTimeout(100);
		}
		await p.click('#e2eBoxTitle', { button: 'right' }); await p.waitForTimeout(200);
		expect(await head() === 'e2e_lmhtml', 'right-click on the title an HTML object draws: ' + await head());
		await p.keyboard.press('Escape'); await p.waitForTimeout(100);
		const textPrev = await p.evaluate(() => { const ev = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 5, clientY: 5 }); document.getElementById('e2eBoxText').dispatchEvent(ev); return ev.defaultPrevented; });
		expect(!textPrev && await shown() === 0, 'the text of an HTML object: browser menu');
		const prevented = await p.evaluate(() => { const ev = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, ctrlKey: true, clientX: 50, clientY: 50 });
			document.getElementById('label_cus_name').dispatchEvent(ev); return ev.defaultPrevented; });
		expect(!prevented && await shown() === 0, 'Ctrl + right-click: own menu');
		await p.evaluate(() => document.getElementById('e2e_lmbtn').dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true })));
		await p.waitForTimeout(200);
		const pos = await p.evaluate(() => { const m = document.querySelector('.nuObjMenu'), b = document.getElementById('e2e_lmbtn').getBoundingClientRect(); return m && Math.abs(m.getBoundingClientRect().top - b.bottom) < 40; });
		expect(pos, 'keyboard: menu not at the button');
		await p.keyboard.press('Escape');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
		// a normal user: no menu, the browser's own
		const u = await login('sample_ben', 'Test1234!');
		await open(u, 'index.php?p=form&f=samplecustomer&r=-1');
		if (await u.locator('#label_cus_name').count()) {
			const prev = await u.evaluate(() => { const ev = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 50, clientY: 50 });
				document.getElementById('label_cus_name').dispatchEvent(ev); return ev.defaultPrevented; });
			expect(!prev && await u.locator('.nuObjMenu').count() === 0, 'user: label menu');
		}
		await u.context().close();
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id IN ('e2elmbtn', 'e2elmrun', 'e2elmhtml')");
	}
};

// 0.8.662: design mode on the form page (globeadmin): Design button → bar + properties panel; a change starts a draft (live unchanged),
// Esc = form settings, Ctrl+click = selection panel, Delete asks, Discard drops the draft, Done leaves
// 0.8.797: design mode – Done asks with unpublished changes (Publish now / Leave as draft / Cancel), tooltips on Publish / Done,
// Publish options: "Ask for a note" off = one click, "Publish with a note…" always asks
T.design_done_publish = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec || sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_name'`) !== '1') { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-done.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const label = () => sql(`SELECT sob_all_label FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_name'`);
	const openDraft = () => sql(`SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status IN ('open', 'review')`);
	const design = async () => { await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800); };
	const change = async (v) => {
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(400);
		const inp = '#nuDsPanel input[data-key="sob_all_label"]';
		await p.fill(inp, v); await p.press(inp, 'Enter'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
	};
	const before = label();
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await p.evaluate(() => localStorage.removeItem('nunext-design-pubask'));
		await design();
		const tip = sel => p.evaluate(q => { const e = document.querySelector(q); return e ? (e.getAttribute('title') || e.getAttribute('data-bs-original-title') || '') : ''; }, sel);
		expect(/users keep seeing/i.test(await tip('#nuDsDone')), 'Done tooltip: ' + await tip('#nuDsDone'));
		// no changes: Done leaves at once
		await p.click('#nuDsDone'); await p.waitForTimeout(900);
		expect(await p.locator('#nuDsBar').count() === 0, 'Done without changes did not leave');
		await design(); await change('E2E done 1');
		expect(/every user/i.test(await tip('#nuDsPublish')), 'Publish tooltip: ' + await tip('#nuDsPublish'));
		await p.click('#nuDsDone'); await p.waitForSelector('#nuDsDoneDlg.show');
		expect(/not published \(1\)/.test(await p.textContent('#nuDsDoneText')), 'Done text: ' + await p.textContent('#nuDsDoneText'));
		await p.click('#nuDsDoneDlg [data-bs-dismiss="modal"].btn'); await p.waitForTimeout(500);
		expect(await p.locator('#nuDsBar').count() === 1, 'Cancel left design mode');
		await p.click('#nuDsDone'); await p.waitForSelector('#nuDsDoneDlg.show');
		await p.click('#nuDsDoneKeep'); await p.waitForTimeout(1200);
		expect(await p.locator('#nuDsBar').count() === 0, 'Leave as draft did not leave');
		expect(openDraft() === '1' && label() === before, 'Leave as draft changed the live form or dropped the draft');
		// one click: switch the note off in the Publish menu
		await design();
		await p.click('#nuDsPublishMore'); await p.waitForTimeout(300);
		await p.click('#nuDsPubAsk'); await p.waitForTimeout(200);
		expect(await p.evaluate(() => localStorage.getItem('nunext-design-pubask')) === '0', 'pref not stored');
		await p.keyboard.press('Escape');
		await p.click('#nuDsPublish'); await p.waitForTimeout(2500);
		expect(await p.locator('#nuDsPublishDlg.show').count() === 0, 'one-click publish showed the window: ' + await p.textContent('#nuDsPubInfo').catch(() => '?'));
		expect(label() === 'E2E done 1' && openDraft() === '0', 'one-click publish did not publish: ' + label());
		expect(sql(`SELECT note FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status = 'published' ORDER BY published_at DESC LIMIT 1`) === '1 change (published without a note)', 'automatic note missing');
		// Done → Publish now (one click) → published and out of design mode
		await change('E2E done 2');
		await p.click('#nuDsDone'); await p.waitForSelector('#nuDsDoneDlg.show');
		await p.click('#nuDsDonePublish'); await p.waitForTimeout(2500);
		expect(label() === 'E2E done 2' && openDraft() === '0', 'Publish now did not publish: ' + label());
		expect(await p.locator('#nuDsBar').count() === 0, 'Publish now did not leave design mode');
		// "Publish with a note…" always asks
		await design(); await change('E2E done 3');
		await p.click('#nuDsPublishMore'); await p.waitForTimeout(300);
		await p.click('#nuDsPubWithNote'); await p.waitForSelector('#nuDsPublishDlg.show');
		await p.fill('#nuDsPubNote', 'E2E note'); await p.click('#nuDsPubOk'); await p.waitForTimeout(2500);
		expect(label() === 'E2E done 3', 'publish with a note failed');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer' AND note = 'E2E note'") !== '0', 'note not stored');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.evaluate(() => localStorage.removeItem('nunext-design-pubask')).catch(() => {});
		execSync('mysql ' + DB + ' < ' + keep); fs.unlinkSync(keep);
	}
};

T.design_mode = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec || sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id IN ('cus_name', 'cus_code')`) !== '2') { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const label = () => sql(`SELECT sob_all_label FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_name'`);
	const before = label();
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F);
		expect(await p.locator('#nuDesignButton').count() === 1, 'Design button on the browse list (0.8.669)');
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec);
		await p.click('#nuDesignButton'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		expect(/no changes/i.test(await p.textContent('#nuDsStatus')), 'status: ' + await p.textContent('#nuDsStatus'));
		expect(/Form/.test(await p.textContent('#nuDsPanel .nuDsKicker')), 'form settings first');
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(400);
		expect((await p.textContent('#nuDsPanel .nuDsName')).trim() === 'cus_name', 'panel shows the object');
		const inp = '#nuDsPanel input[data-key="sob_all_label"]';
		await p.fill(inp, 'E2E design'); await p.press(inp, 'Enter');
		await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(/draft/i.test(await p.textContent('#nuDsStatus')), 'draft started: ' + await p.textContent('#nuDsStatus'));
		expect(label() === before, 'live form changed: ' + label());
		expect(/E2E design/.test(await p.textContent('#nuw_cus_name')), 'the page shows the draft');
		expect((await p.textContent('#nuDsPanel .nuDsName').catch(() => '')).trim() === 'cus_name', 'selection back after the reload');
		await p.keyboard.press('Escape'); await p.waitForTimeout(300);
		expect(/Form/.test(await p.textContent('#nuDsPanel .nuDsKicker')), 'Esc: form settings');
		await p.click('#nuw_cus_code .nuDesignOverlay', { position: { x: 5, y: 5 } });
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 }, modifiers: ['Control'] }); await p.waitForTimeout(300);
		expect(/2/.test(await p.textContent('#nuDsPanel .nuDsName')), 'selection panel');
		await p.locator('body').press('Delete'); await p.waitForTimeout(400);
		expect(/cus_code/.test(await p.textContent('#nuConfirm.show .modal-body').catch(() => '')), 'Delete asks');
		await p.click('#nuConfirm.show [data-bs-dismiss="modal"]'); await p.waitForTimeout(300);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_code'`) === '1', 'Cancel deleted');
		await p.click('#nuDsDiscard'); await p.waitForTimeout(300);
		await p.click('#nuConfirm.show .js-confirm-ok'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1200);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status IN ('open', 'review')`) === '0', 'draft not discarded');
		expect(!/E2E design/.test(await p.textContent('#nuw_cus_name')), 'discarded change still shown');
		await p.click('#nuDsDone'); await p.waitForTimeout(800);
		expect(await p.locator('#nuDsBar').count() === 0 && await p.locator('#nuDesignButton').count() === 1, 'Done leaves design mode');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.663: design mode – Structure (list, select), Add (an element at the bottom of the tab), Ctrl+D duplicates; all in the draft
T.design_structure_add = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design2.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const liveCount = () => sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}'`);
	const n0 = liveCount();
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(700);
		await p.click('#nuDsRailStructure'); await p.waitForTimeout(300);
		expect(await p.locator('#nuDsTree .nuDsTreeRow').count() === +n0, 'structure rows ' + await p.locator('#nuDsTree .nuDsTreeRow').count() + ' / ' + n0);
		await p.fill('#nuDsStructQ', 'cus_email'); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsTree .nuDsTreeRow').count() === 1, 'search');
		await p.click('#nuDsTree .nuDsTreeRow'); await p.waitForTimeout(400);
		expect((await p.textContent('#nuDsPanel .nuDsName')).trim() === 'cus_email', 'structure selects');
		await p.click('#nuDsRailAdd'); await p.waitForTimeout(300);
		await p.click('#nuDsElGrid [data-type="html"]'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(await p.locator('#nuw_new_html').count() === 1, 'new object on the page');
		expect((await p.textContent('#nuDsPanel .nuDsName').catch(() => '')).trim() === 'new_html', 'new object selected');
		expect(liveCount() === n0, 'live form changed');
		await p.keyboard.press('Escape'); await p.waitForTimeout(200);
		await p.click('#nuw_new_html .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(300);
		await p.keyboard.press('Control+d'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(await p.locator('#nuw_new_html_2').count() === 1, 'Ctrl+D duplicate');
		expect(/draft/i.test(await p.textContent('#nuDsStatus')), 'in a draft');
		// 0.8.679: a new sub-form object in the draft (no form, no foreign key yet) – the server answered with a PHP warning before
		await p.click('#nuDsRailAdd'); await p.waitForTimeout(300);
		if (!(await p.locator('#nuDsElGrid').isVisible())) { await p.click('#nuDsRailAdd'); await p.waitForTimeout(300); }
		await p.locator('#nuDsElGrid [data-type="subform"]').scrollIntoViewIfNeeded();
		await p.click('#nuDsElGrid [data-type="subform"]'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect((await p.textContent('#nuDsPanel .nuDsName').catch(() => '')).trim() === 'new_subform', 'new sub-form not added');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.846: Add → Input opens a second page with the kind of input – a date added, page stays, "All input types" lists the rest, Back returns
T.design_add_input_kind = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-kind.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(700);
		await p.click('#nuDsRailAdd'); await p.waitForTimeout(300);
		await p.click('#nuDsElGrid [data-type="input"]'); await p.waitForTimeout(300);
		expect(await p.locator('#nuDsAddInput').count() === 1 && await p.locator('#nuDsKindGrid .nuDsKind').count() === 9, 'second page / 9 frequent kinds');
		expect(await p.locator('#nuDsKindChips').count() === 0, 'other kinds open at first');
		await p.click('#nuDsKindMore'); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsKindChips .nuDsKindChip').count() === 11, 'other kinds: ' + await p.locator('#nuDsKindChips .nuDsKindChip').count());
		await p.click('#nuDsKindGrid [data-input="nuDate"]'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(await p.locator('#nuw_new_nudate').count() === 1, 'date input not on the page');
		const d = await p.evaluate(() => { const o = Object.values(window.nuNext.objects || {}).find((x) => x && (x.sob_all_id === 'new_nudate' || x.id === 'new_nudate')); return o ? [o.sob_input_type, o.sob_all_label] : null; });
		const draft = sql(`SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		expect(draft !== '0', 'no draft');
		expect(await p.locator('#nuDsAddInput').count() === 1 && await p.locator('#nuDsKindDone').count() === 1, 'not on the kind page after adding');
		if (d) expect(d[0] === 'nuDate', 'input type ' + d.join(' / '));
		await p.click('#nuDsAddBack'); await p.waitForTimeout(300);
		expect(await p.locator('#nuDsElGrid [data-type="input"]').count() === 1 && await p.locator('#nuDsAddInput').count() === 0, 'Back');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.663: design mode – tabs on the form: double-click renames, "+" adds, right-click → Hide; events dialog; Style section
T.design_tabs_events = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec || +sql(`SELECT COUNT(*) FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}'`) < 2) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design3.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const t1 = sql(`SELECT syt_title FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' ORDER BY syt_order LIMIT 1 OFFSET 1`);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(700);
		await p.dblclick('#nuTab1'); await p.waitForTimeout(200);
		await p.fill('.nuDsTabInput', 'E2E tab'); await p.press('.nuDsTabInput', 'Enter');
		await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(/E2E tab/.test(await p.textContent('#nuTab1')), 'renamed: ' + await p.textContent('#nuTab1'));
		expect(sql(`SELECT syt_title FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' ORDER BY syt_order LIMIT 1 OFFSET 1`) === t1, 'live tab renamed');
		const n = await p.locator('#nuTabHolder .nuTab').count();
		await p.click('#nuDsTabAdd'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(await p.locator('#nuTabHolder .nuTab').count() === n + 1, 'tab added');
		await p.click('#nuTab1', { button: 'right' }); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsTabMenu').count() === 1, 'tab menu');
		await p.locator('#nuDsTabMenu .dropdown-item').nth(1).click(); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(await p.locator('#nuTabHolder .nuDsTabHidden').count() >= 1, 'hidden tab shown faded');
		// events dialog + Style section
		await p.click('#nuTab0'); await p.waitForTimeout(300);
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(300);
		await p.click('#nuDsEditEvents'); await p.waitForSelector('#nuDsEventsDlg.show');
		await p.click('#nuDsEvAdd'); await p.locator('#nuDsEvRows textarea').last().fill('console.log("e2e")');
		await p.click('#nuDsEvSave'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(/onchange/.test(await p.textContent('#nuDsPanel .nuDsEvents')), 'event saved');
		await p.click('#nuDsPanel [data-sec="style"]'); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsPanel select[data-key="sob_all_style_type"]').count() === 1, 'Style section');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.1198: unique tab names – a new tab gets a free name (New tab, New tab 2 …), renaming to the name of another tab is refused (field and
// builder API, 409); two tabs a nuBuilder Forte form already has with the same name do not block other changes of the tabs
T.design_tab_unique = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec || +sql(`SELECT COUNT(*) FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}'`) < 2) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-tabuniq.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const titles = () => p.$$eval('#nuTabHolder .nuTab', (x) => x.map((e) => e.textContent.trim()));      // design mode works in a draft
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(700);
		const base = await p.evaluate(() => window.nuNext.design.texts.ds_tab_new);
		for (let i = 0; i < 2; i++) { await p.click('#nuDsTabAdd'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500); }
		const t = await titles();
		expect(t.includes(base) && t.includes(base + ' 2'), 'free names for new tabs: ' + t.join(' | '));
		// rename the last tab to the first tab's name (other case): refused in the field
		const n = await p.locator('#nuTabHolder .nuTab').count();
		await p.dblclick('#nuTab' + (n - 1)); await p.waitForTimeout(200);
		await p.fill('.nuDsTabInput', t[0].toUpperCase()); await p.press('.nuDsTabInput', 'Enter'); await p.waitForTimeout(800);
		const dbg = await p.evaluate(() => ({inp: document.querySelectorAll('.nuDsTabInput').length, inv: document.querySelectorAll('.nuDsTabInput.is-invalid').length, msg: (document.getElementById('nuDsMsg') || {}).textContent, tabs: [...document.querySelectorAll('#nuTabHolder .nuTab')].map((e) => e.textContent.trim())}));
		expect(dbg.inv === 1 && dbg.inp === 1 && !!dbg.msg, 'duplicate refused, the field stays open with a message ' + JSON.stringify(dbg) + ' first=' + t[0]);
		await p.press('.nuDsTabInput', 'Escape'); await p.waitForTimeout(200);
		expect((await titles())[n - 1] === base + ' 2', 'Esc keeps the old name');
		// the builder API refuses it too
		const r = await p.evaluate(async (a) => {
			const tabs = (await (await fetch('index.php?p=builderapi', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.nuNext.csrf},
				body: JSON.stringify({action: 'state', form_id: a.F})})).json()).tabs || [];
			const rows = tabs.map((x, i) => ({id: x.zzzzsys_tab_id, title: i === tabs.length - 1 ? ' <b>' + a.first + '</b> ' : x.syt_title, access: x.syt_access || '0'}));
			const res = await fetch('index.php?p=builderapi', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.nuNext.csrf},
				body: JSON.stringify({action: 'tabs.save', form_id: a.F, tabs: rows, live_ok: true})});
			return {status: res.status, j: await res.json()};
		}, {F, first: sql(`SELECT syt_title FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' ORDER BY syt_order LIMIT 1`)});
		expect(r.status === 409 && !r.j.ok, 'builder API: 409 for a duplicate ' + JSON.stringify(r).slice(0, 200));
		// a form that already has two tabs with the same name (nuBuilder Forte allows it): moving tabs still works
		{	// plain SQL (no UPDATE … JOIN: runs on every database of the tests)
			const second = sql(`SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' ORDER BY syt_order LIMIT 1 OFFSET 1`);
			const firstTitle = sql(`SELECT syt_title FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' ORDER BY syt_order LIMIT 1`);
			sql(`UPDATE zzzzsys_tab SET syt_title = '${String(firstTitle).replace(/'/g, "''")}' WHERE zzzzsys_tab_id = '${second}'`);
		}
		const r2 = await p.evaluate(async (F) => {
			const tabs = (await (await fetch('index.php?p=builderapi', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.nuNext.csrf},
				body: JSON.stringify({action: 'state', form_id: F})})).json()).tabs || [];
			const rows = tabs.map((x) => ({id: x.zzzzsys_tab_id, title: x.syt_title, access: x.syt_access || '0'}));
			rows.push(rows.splice(1, 1)[0]);
			const res = await fetch('index.php?p=builderapi', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.nuNext.csrf},
				body: JSON.stringify({action: 'tabs.save', form_id: F, tabs: rows, live_ok: true})});
			return res.status;
		}, F);
		expect(r2 === 200, 'old duplicates do not block other changes: ' + r2);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.663: New form in three steps (Form · Table and fields · Layout and access); Next checks each step; the form opens in design mode
T.new_form_pick = async (s) => {                        // 0.8.675: New form on an existing table – chosen columns, own labels, list columns, order
	const p = s.ga, code = 'e2ePick' + Date.now().toString(36);
	let id = '';
	try {
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		await p.fill('#nb_desc', 'E2E pick'); await p.fill('#nb_code', code);
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await p.waitForFunction(() => document.querySelector('#nb_table option[value="sample_customer"]'));
		await p.evaluate(() => { const x = document.getElementById('nb_table'); if (x.tomselect) x.tomselect.setValue('sample_customer'); else { x.value = 'sample_customer'; x.dispatchEvent(new Event('change')); } });
		await p.waitForSelector('#nbPickRows tr[data-name="cus_notes"]', { state: 'attached' });
		expect(await p.isVisible('#nbPickWrap') && !(await p.isVisible('#nbPickRows')), 'column list not folded');
		expect(/10 of 10/.test(await p.locator('#nbPickCount').textContent()), 'count: ' + await p.locator('#nbPickCount').textContent());
		await p.click('#nbPickBtn'); await p.waitForTimeout(400);
		const row = (n) => p.locator(`#nbPickRows tr[data-name="${n}"]`);
		await row('cus_email').locator('.nbPickOn').uncheck(); await row('cus_notes').locator('.nbPickOn').uncheck();
		await row('cus_name').locator('.nbPickUp').click();
		await row('cus_phone').locator('.nbPickLabel').fill('Telefon');
		await row('cus_code').locator('.nbPickList').uncheck();
		expect(await row('cus_code').locator('.nbPickFlt').isDisabled(), 'filter box of a column not in the list enabled');
		await row('cus_phone').locator('.nbPickFlt').check();   // 0.8.688: column filter of the list
		await row('cus_since').locator('.nbPickLabel').focus(); await p.keyboard.press('Alt+ArrowUp');
		expect(await row('cus_email').locator('.nbPickList').isDisabled(), 'list box of an unticked column enabled');
		const order = await p.$$eval('#nbPickRows tr[data-name]', (r) => r.map((x) => x.getAttribute('data-name')));
		expect(order.indexOf('cus_name') < order.indexOf('cus_code') && order.indexOf('cus_since') < order.indexOf('cus_active'), 'order: ' + order.join(','));
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await Promise.all([p.waitForURL(/design=1/), p.click('#nbCreate')]);
		id = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}'`);
		const objs = sql(`SELECT GROUP_CONCAT(sob_all_id ORDER BY sob_all_order SEPARATOR ',') FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${id}'`).split(',');
		const want = order.filter((n) => n !== 'cus_email' && n !== 'cus_notes');
		expect(objs.join(',') === want.join(','), 'objects: ' + objs.join(',') + ' / ' + want.join(','));
		expect(sql(`SELECT sob_all_label FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${id}' AND sob_all_id = 'cus_phone'`) === 'Telefon', 'own label not used');
		const br = sql(`SELECT GROUP_CONCAT(sbr_display ORDER BY sbr_order SEPARATOR ',') FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${id}'`).split(',');
		expect(br.indexOf('cus_code') < 0 && br.indexOf('cus_email') < 0 && br[0] === want.filter((n) => n !== 'cus_code')[0], 'list columns: ' + br.join(','));
		expect(/"filter":true/.test(sql(`SELECT sbr_json FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${id}' AND sbr_display = 'cus_phone'`))
			&& sql(`SELECT COUNT(*) FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${id}' AND sbr_display <> 'cus_phone' AND sbr_json LIKE '%filter%'`) === '0', 'list filter of cus_phone');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (!id) id = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}'`);
		if (id) {
			sql(`DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${id}'`); sql(`DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${id}'`);
			sql(`DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${id}'`); sql(`DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = '${id}'`);
			sql(`DELETE FROM zzzzsys_php WHERE zzzzsys_php_id LIKE '${id}%'`); sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${id}'`); sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`);
		}
	}
};

T.new_form_view = async (s) => {                        // 0.8.721: New form on a view – marked "(view)", key column suggested, read-only view → Browse
	const p = s.ga, code = 'e2eView' + Date.now().toString(36);
	let id = '';
	const pickT = (t) => p.evaluate((v) => { const x = document.getElementById('nb_table'); if (x.tomselect) x.tomselect.setValue(v); else { x.value = v; x.dispatchEvent(new Event('change')); } }, t);
	try {
		sql('CREATE OR REPLACE VIEW e2e_vw_cust AS SELECT sample_customer_id, cus_code, cus_name FROM sample_customer');
		sql('CREATE OR REPLACE VIEW e2e_vw_cnt AS SELECT cus_country, COUNT(*) AS n FROM sample_customer GROUP BY cus_country');
		sql('CREATE OR REPLACE VIEW e2e_vw_dup AS SELECT ord_sample_customer_id, ord_total FROM sample_order');
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		await p.fill('#nb_desc', 'E2E view'); await p.fill('#nb_code', code);
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await p.waitForFunction(() => document.querySelector('#nb_table option[value="e2e_vw_cnt"]'));
		const lbl = await p.$eval('#nb_table option[value="e2e_vw_cnt"]', (o) => o.textContent);
		const tlbl = await p.$eval('#nb_table option[value="sample_customer"]', (o) => o.textContent);
		const grp = await p.$eval('#nb_table option[value="e2e_vw_cnt"]', (o) => o.parentElement.tagName === 'OPTGROUP' ? o.parentElement.label : '');
		const tgrp = await p.$eval('#nb_table option[value="sample_customer"]', (o) => o.parentElement.tagName === 'OPTGROUP' ? o.parentElement.label : '');
		expect(lbl === 'e2e_vw_cnt' && tlbl === 'sample_customer' && grp !== '' && tgrp !== '' && grp !== tgrp, 'groups (0.8.851): ' + lbl + ' in ' + grp + ' / ' + tlbl + ' in ' + tgrp);
		await pickT('e2e_vw_cnt');
		await p.waitForSelector('#nbViewInfo:not([hidden])');
		expect(await p.$eval('#nb_type', (x) => x.value) === 'browse', 'type not set to Browse');
		expect(await p.$eval('#nbViewInfo', (x) => x.classList.contains('alert-warning') && /cannot save/.test(x.textContent)), 'read-only notice: ' + await p.textContent('#nbViewInfo'));
		expect(await p.$eval('#nb_pk', (x) => x.value) === 'cus_country', 'key of the GROUP BY view');
		if (SQLITE) return;                                     // SQLite's views are never updatable – the rest is about updatable ones
		await p.evaluate(() => { const x = document.getElementById('nb_type'); x.value = 'browseedit'; x.dispatchEvent(new Event('change')); });   // step 1 is hidden now
		await pickT('e2e_vw_dup');
		await p.waitForFunction(() => /ord_sample_customer_id/.test(document.getElementById('nbViewInfo').textContent));
		expect(await p.$eval('#nbViewInfo', (x) => /repeated or empty/.test(x.textContent) && x.classList.contains('alert-warning')), 'duplicate key warning: ' + await p.textContent('#nbViewInfo'));
		expect(await p.$eval('#nb_type', (x) => x.value) === 'browseedit', 'type changed for an updatable view');
		await pickT('e2e_vw_cust');
		await p.waitForFunction(() => /sample_customer_id/.test(document.getElementById('nbViewInfo').textContent));
		expect(await p.$eval('#nbViewInfo', (x) => x.classList.contains('alert-info') && /can save/.test(x.textContent)), 'updatable notice: ' + await p.textContent('#nbViewInfo'));
		expect(await p.$eval('#nb_pk', (x) => x.value) === 'sample_customer_id', 'suggested key');
		await pickT('sample_customer');
		await p.waitForTimeout(600);
		expect(await p.$eval('#nbViewInfo', (x) => x.hidden), 'notice stays for a table');
		await pickT('e2e_vw_cust');
		await p.waitForFunction(() => document.getElementById('nb_pk').value === 'sample_customer_id');
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await Promise.all([p.waitForURL(/design=1/), p.click('#nbCreate')]);
		id = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}'`);
		expect(sql(`SELECT CONCAT(sfo_table, '|', sfo_primary_key, '|', sfo_type) FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`) === 'e2e_vw_cust|sample_customer_id|browseedit', 'form row');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (!id) id = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}'`);
		if (id) {
			sql(`DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${id}'`); sql(`DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${id}'`);
			sql(`DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${id}'`); sql(`DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = '${id}'`);
			sql(`DELETE FROM zzzzsys_php WHERE zzzzsys_php_id LIKE '${id}%'`); sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${id}'`); sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`);
		}
		sql('DROP VIEW IF EXISTS e2e_vw_cust, e2e_vw_cnt, e2e_vw_dup');
	}
};

T.design_mobile = async (s) => {                       // 0.8.831: design mode on phones – one-line bar + ⋯ menu, bottom sheet peek / half / full, quick actions
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage(); const tap = (sel) => p.tap(sel, { timeout: 5000 }).catch(() => { throw new Error('cannot tap ' + sel); }); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
	try {
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer&r=sample_cus_01&design=1'); await p.waitForTimeout(1500);
		const bh = await p.evaluate(() => document.getElementById('nuDsBar').getBoundingClientRect().height);
		expect(bh <= 56, 'bar not one line: ' + bh);
		expect(await p.isVisible('#nuDsPhMore') && await p.isVisible('#nuDsDot') && !(await p.isVisible('#nuDsReload')), 'phone bar: ⋯ / dot missing or Reload still shown');
		await tap('#nuDsPhMore'); await p.waitForTimeout(300);
		expect(await p.locator('#nuDsPhMenu button[data-ph-for="nuDsReload"]').isVisible() && await p.locator('#nuDsPhMenu button[data-ph-for="code"]').count() === 1, 'phone menu entries');
		await tap('#nuDsPhMore'); await p.waitForTimeout(200);
		const ph = async () => p.evaluate(() => [document.getElementById('nuDsPanel').getAttribute('data-sheet'), document.getElementById('nuDsPanel').getBoundingClientRect().height]);
		let st = await ph();
		expect(st[0] === 'peek' && st[1] < 120, 'sheet not peeking: ' + st);
		await tap('#nuw_cus_name'); await p.waitForTimeout(600);
		expect(await p.isVisible('#nuDsQuick') && await p.isVisible('#nuDsQUp'), 'quick actions not shown');
		const q = await p.evaluate(() => { const a = document.getElementById('nuDsQuick').getBoundingClientRect(), b = document.getElementById('nuw_cus_name').getBoundingClientRect(); return a.bottom <= b.top + 1 || a.top >= b.bottom - 1; });
		expect(q, 'quick actions cover the object');
		await tap('#nuDsSheetHandle'); await p.waitForTimeout(400);
		st = await ph(); expect(st[0] === 'half' && st[1] > 350, 'sheet not half: ' + st);
		await tap('#nuDsSheetHandle'); await p.waitForTimeout(400);
		st = await ph(); expect(st[0] === 'full' && st[1] > 650 && !(await p.isVisible('#nuDsQuick')), 'sheet not full: ' + st);
		await tap('#nuDsSheetHandle'); await p.waitForTimeout(400);
		st = await ph(); expect(st[0] === 'peek', 'sheet back to peek: ' + st);
		await tap('#nuDsQMore'); await p.waitForTimeout(400);
		expect((await ph())[0] === 'half', 'All settings does not open the sheet');
		const pad = await p.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.nuFormContainer')).paddingBottom));
		expect(pad > 350, 'form padding does not follow the sheet: ' + pad);
		// the bar slides away while scrolling down
		await p.evaluate(() => { document.body.style.minHeight = '3000px'; window.scrollTo(0, 0); }); await p.waitForTimeout(100);
		await p.evaluate(() => window.scrollTo(0, 400)); await p.waitForTimeout(300);
		expect(await p.evaluate(() => document.documentElement.classList.contains('nuDsBarAway')), 'bar does not hide on scroll down');
		await p.evaluate(() => window.scrollTo(0, 200)); await p.waitForTimeout(300);
		expect(!(await p.evaluate(() => document.documentElement.classList.contains('nuDsBarAway'))), 'bar does not come back on scroll up');
		expect(errs.length === 0, errs.slice(0, 2).join(' | '));
	} finally { await ctx.close(); }
	await open(s.ga, 'index.php?p=form&f=samplecustomer&r=sample_cus_01&design=1');
	expect(!(await s.ga.isVisible('#nuDsPhMore')) && !(await s.ga.isVisible('#nuDsQuick')) && await s.ga.isVisible('#nuDsReload'), 'desktop shows phone parts');
};

T.new_form_levels = async (s) => {                      // 0.8.674: New form → access levels: Select all (levels shown) + group filter (sal_group)
	const p = s.ga, old = sql("SELECT sal_group FROM zzzzsys_access WHERE zzzzsys_access_id = 'sample_secure'");
	try {
		sql("UPDATE zzzzsys_access SET sal_group = 'e2e_grp' WHERE zzzzsys_access_id = 'sample_secure'");
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		await p.fill('#nb_desc', 'E2E levels'); await p.fill('#nb_code', 'e2eLv' + Date.now().toString(36));
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await p.click('label[for="nb_mode_new"]'); await p.fill('#nb_new_table', 'e2e_lv_x'); await p.locator('#nbColRows input[data-f="name"]').first().fill('lv_name');
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		const total = await p.locator('.nbLevel').count();
		await p.selectOption('#nbLvGroup', 'sample');
		const shown = await p.locator('.nbLevelRow:not([hidden])').count();
		const inSample = +sql("SELECT COUNT(*) FROM zzzzsys_access WHERE sal_group = 'sample'");   // other samples (tkt) may have levels too
		expect(shown === inSample && await p.locator('.nbLevelRow[data-group="e2e_grp"]').isHidden(), 'group filter: ' + shown + ' of ' + total + ' (sample: ' + inSample + ')');
		await p.check('#nbLvAll');
		expect(await p.locator('.nbLevel:checked').count() === shown && !(await p.locator('.nbLevel[value="sample_secure"]').isChecked()), 'Select all ticked hidden levels');
		expect(new RegExp('^' + shown + ' of ' + total).test(await p.locator('#nbLvCount').textContent()), 'count: ' + await p.locator('#nbLvCount').textContent());
		await p.selectOption('#nbLvGroup', 'e2e_grp');
		expect(!(await p.locator('#nbLvAll').isChecked()), 'Select all still ticked for an unticked group');
		await p.selectOption('#nbLvGroup', '*');
		expect(await p.evaluate(() => document.getElementById('nbLvAll').indeterminate), 'all groups: not indeterminate');
		await p.check('#nbLvAll');
		expect(await p.locator('.nbLevel:checked').count() === total, 'Select all with every group');
		await p.uncheck('#nbLvAll');
		expect(await p.locator('.nbLevel:checked').count() === 0, 'clear all');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { sql("UPDATE zzzzsys_access SET sal_group = '" + old.replace(/'/g, "''") + "' WHERE zzzzsys_access_id = 'sample_secure'"); }
};

T.new_form_steps = async (s) => {
	const p = s.ga, code = 'e2eSteps' + Date.now().toString(36), tbl = 'e2e_steps_' + Date.now().toString(36);
	let id = '';
	try {
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		expect(await p.isVisible('.nbStep[data-step="1"]') && !(await p.isVisible('#nbCreate')), 'starts on step 1');
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		expect(await p.isVisible('.nbStep[data-step="1"]'), 'Next without a description');
		await p.fill('#nb_desc', 'E2E steps'); await p.fill('#nb_code', code);
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		expect(await p.isVisible('.nbStep[data-step="2"]'), 'step 2');
		await p.click('label[for="nb_mode_new"]'); await p.click('#nbStepNext'); await p.waitForTimeout(200);
		expect(await p.isVisible('#nbNewError'), 'Next without a table name');
		await p.fill('#nb_new_table', tbl); await p.locator('#nbColRows input[data-f="name"]').first().fill('stp_name');
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		expect(await p.isVisible('#nbCreate'), 'step 3');
		await Promise.all([p.waitForURL(/design=1/), p.click('#nbCreate')]);
		await p.waitForSelector('#nuDsBar');
		id = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}'`);
		expect(id !== '' && await p.locator('#nuw_stp_name').count() === 1, 'new form in design mode');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		if (!id) id = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}'`);
		if (id) {
			sql(`DELETE FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN (SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${id}' OR sob_run_zzzzsys_form_id = '${id}')`);
			sql(`DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${id}' OR sob_run_zzzzsys_form_id = '${id}'`);
			sql(`DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${id}'`); sql(`DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${id}'`);
			sql(`DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = '${id}'`); sql(`DELETE FROM zzzzsys_php WHERE zzzzsys_php_id LIKE '${id}%'`);
			sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${id}'`); sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`);
		}
		sql(`DROP TABLE IF EXISTS ${tbl}`);
	}
};

// 0.8.664: design mode – the label changes on the form while typing (Esc = back, saved once on Enter); spacing with a fixed gap
// (vertical: box to box, horizontal: to the next label); the right-click object menu changes the draft
T.design_live_gap_menu = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec || sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id IN ('cus_name', 'cus_code', 'cus_email', 'cus_phone')`) !== '4') { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design4.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const live = (id, col) => sql(`SELECT ${col} FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = '${id}'`);
	const label0 = live('cus_name', 'sob_all_label'), acc0 = live('cus_phone', 'sob_all_access');
	let saves = 0;
	const count = (r) => { if (/builderapi/.test(r.url()) && /object\.save/.test(r.postData() || '')) saves++; };
	p.on('request', count);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(700);
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(300);
		const lab = '#nuDsPanel input[data-key="sob_all_label"]';
		await p.click(lab); await p.keyboard.press('End'); await p.keyboard.type(' live');
		expect(/live/.test(await p.textContent('#label_cus_name')) && saves === 0, 'label while typing: ' + await p.textContent('#label_cus_name') + ' saves ' + saves);
		await p.keyboard.press('Escape'); await p.waitForTimeout(200);
		expect(!/live/.test(await p.textContent('#label_cus_name')) && saves === 0, 'Esc');
		await p.click(lab); await p.keyboard.press('End'); await p.keyboard.type(' E2E'); await p.keyboard.press('Enter'); await p.waitForTimeout(1500);
		expect(saves === 1 && /E2E/.test(await p.textContent('#label_cus_name')) && live('cus_name', 'sob_all_label') === label0, 'saved once in the draft: ' + saves);
		// fixed gaps
		await p.keyboard.press('Escape');
		await p.click('#nuw_cus_code .nuDesignOverlay', { position: { x: 5, y: 5 } });
		await p.click('#nuw_cus_email .nuDesignOverlay', { position: { x: 5, y: 5 }, modifiers: ['Control'] });
		await p.click('#nuw_cus_phone .nuDesignOverlay', { position: { x: 5, y: 5 }, modifiers: ['Control'] }); await p.waitForTimeout(300);
		await p.fill('#nuDsGapV', '20'); await p.click('[data-ds-gap="vgap"]'); await p.waitForTimeout(1500);
		const v = await p.evaluate(() => ['cus_code', 'cus_email', 'cus_phone'].map((i) => { const w = document.getElementById('nuw_' + i); return [w.offsetTop, w.offsetHeight]; }).sort((a, b) => a[0] - b[0]));
		expect(v[1][0] === v[0][0] + v[0][1] + 20 && v[2][0] === v[1][0] + v[1][1] + 20, 'vertical gap ' + JSON.stringify(v));
		await p.fill('#nuDsGapH', '10'); await p.click('[data-ds-gap="hgap"]'); await p.waitForTimeout(1500);
		const h = await p.evaluate(() => ['cus_code', 'cus_email', 'cus_phone'].map((i) => { const w = document.getElementById('nuw_' + i), l = document.getElementById('label_' + i);
			return [w.offsetLeft, w.offsetWidth, l ? Math.round(w.getBoundingClientRect().left - l.getBoundingClientRect().left) : 0]; }).sort((a, b) => a[0] - b[0]));
		expect(h[1][0] === h[0][0] + h[0][1] + 10 + h[1][2] && h[2][0] === h[1][0] + h[1][1] + 10 + h[2][2], 'horizontal gap to the label ' + JSON.stringify(h));
		expect(await p.evaluate(() => JSON.parse(localStorage.getItem('nunext-arrange-gap') || '{}').v) === 20, 'gap remembered');
		// object menu on the overlay
		await p.keyboard.press('Escape');
		await p.click('#nuw_cus_phone .nuDesignOverlay', { button: 'right', position: { x: 5, y: 5 } }); await p.waitForTimeout(300);
		expect(await p.locator('.nuObjMenu').count() === 1, 'object menu');
		expect(await p.locator('.nuObjMenu #nuOmSettings, .nuObjMenu .bi-sliders').count() === 0, 'Properties / Button settings in design mode (the panel shows them)');
		await p.locator('.nuObjMenu > li > button.nuOmHasSub').nth(1).click(); await p.waitForTimeout(200);
		await p.locator('.nuObjMenu .nuOmSubWrap.nuOmOpen .nuOmSub button').nth(1).click();
		await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(await p.inputValue('#nuDsPanel select[data-key="sob_all_access"]') === '1' && live('cus_phone', 'sob_all_access') === acc0, 'menu change in the draft');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		p.off('request', count);
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.1143 (a tester's report): a text message of a browser extension does not stop the designer's message handler; the
// attribute editor's Apply saves the field (the change handler read the input-group wrapper's value)
T.design_attr_editor_save = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec || sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_name'`) !== '1') { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design1143.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	let saves = [];
	const count = (r) => { if (/builderapi/.test(r.url()) && /object\.save/.test(r.postData() || '')) saves.push(r.postData()); };
	p.on('request', count);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(700);
		await p.evaluate(() => { window.postMessage('extension text', '*'); window.postMessage(42, '*'); });
		await p.waitForTimeout(200);
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(400);
		if (await p.locator('.nuDsSecHead[data-sec="style"][aria-expanded="false"]').count()) { await p.click('.nuDsSecHead[data-sec="style"]'); await p.waitForTimeout(200); }
		if (!await p.locator('#nuDsAttrBtn').count()) { await p.click('.nuDsAdv[data-adv="style"]'); await p.waitForTimeout(200); }
		expect(await p.locator('#nuDsAttrBtn').count() === 1, 'attribute editor button');
		await p.evaluate(() => { window.nuAttributeEditor.open = (v, cb) => cb('data-e2e="1143"'); });
		await p.click('#nuDsAttrBtn'); await p.waitForTimeout(1500);
		expect(saves.length === 1 && /data-e2e/.test(saves[0]), 'object.save sent: ' + saves.length);
		// typed into the field + Enter (the same handler)
		await p.waitForSelector('#nuDsPanel input[data-key="sob_input_attribute"]');
		await p.fill('#nuDsPanel input[data-key="sob_input_attribute"]', 'data-typed="1"');
		await p.press('#nuDsPanel input[data-key="sob_input_attribute"]', 'Enter'); await p.waitForTimeout(1500);
		expect(saves.length === 2 && /data-typed/.test(saves[1]), 'typed value saved: ' + saves.length);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		p.off('request', count);
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.665: design mode – changes draw the form again without a page reload; a content box is picked and dragged by its frame /
// title; code fields open a code window (CodeMirror + toolbar)
// 0.8.667: design mode for users with the save right on the form builder (not only the globeadmin) – tabs not renamed, no Publish
// without the admin right formpublish; safe mode (?safe=1) opens the form without its own code
T.design_rights_safe = async (s) => {
	const F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-rights.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_access_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const label = () => sql(`SELECT sob_all_label FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_name'`);
	const before = label();
	let p;
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}';
			UPDATE zzzzsys_form SET sfo_edit_javascript = 'document.documentElement.dataset.e2eSafe = "ran";' WHERE zzzzsys_form_id = '${F}';
			INSERT INTO zzzzsys_access_form (zzzzsys_access_form_id, slf_zzzzsys_access_id, slf_zzzzsys_form_id, slf_add_button, slf_save_button, slf_delete_button, slf_clone_button)
			VALUES ('e2edsright', 'sample_editor', 'nuform', '1', '1', '1', '1')`);
		p = await login('sample_ben', UPW);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec);
		expect(await p.locator('#nuDesignButton').count() === 1, 'Design button for a user with form builder rights');
		expect(await p.evaluate(() => document.documentElement.dataset.e2eSafe) === 'ran', 'form code runs normally');
		await p.click('#nuDesignButton'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		expect(await p.evaluate(() => document.documentElement.dataset.e2eSafe) === 'ran', 'form code runs in design mode');
		// tabs: rename / delete are the globeadmin's
		await p.click('#nuTabHolder .nav-link', { button: 'right' }); await p.waitForTimeout(300);
		expect(await p.locator('#nuDsTabMenu').count() === 1, 'tab menu');
		expect(await p.locator('#nuDsTabMenu button').first().isDisabled(), 'Rename disabled');
		expect(await p.locator('#nuDsTabMenu .nuDsMenuNote').count() === 1, 'note on the tab menu');
		await p.keyboard.press('Escape'); await p.mouse.click(5, 600); await p.waitForTimeout(200);
		// a change goes into a draft; Publish needs the admin right formpublish
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(400);
		const inp = '#nuDsPanel input[data-key="sob_all_label"]';
		await p.fill(inp, 'E2E rights'); await p.press(inp, 'Enter'); await p.waitForTimeout(1500);
		expect(/draft|Entwurf/i.test(await p.textContent('#nuDsStatus')), 'draft started: ' + await p.textContent('#nuDsStatus'));
		expect(label() === before, 'live form changed');
		expect(await p.locator('#nuDsPublish').isHidden(), 'Publish without the right formpublish');
		// safe mode
		await p.click('#nuDsSafe'); await p.waitForSelector('#nuDsSafeBadge'); await p.waitForTimeout(800);
		expect(await p.evaluate(() => document.documentElement.dataset.e2eSafe) === undefined, 'form code ran in safe mode');
		expect(/[?&]safe=1/.test(p.url()), 'safe url ' + p.url());
		expect(/E2E rights/.test(await p.textContent('#nuw_cus_name')), 'safe mode shows the draft');
		await p.click('#nuDsSafe'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		expect(await p.locator('#nuDsSafeBadge').count() === 0 && await p.evaluate(() => document.documentElement.dataset.e2eSafe) === 'ran', 'leave safe mode');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
		// the browse list / a user without builder rights: no Design button
		sql(`DELETE FROM zzzzsys_access_form WHERE zzzzsys_access_form_id = 'e2edsright'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1');
		expect(await p.locator('#nuDsBar').count() === 0 && await p.locator('#nuDesignButton').count() === 0, 'no design mode without the right');
	} finally {
		if (p) await p.context().close();
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.668: design mode workspace – Code (CSS saved into the draft, Ctrl+S), Changes of the draft, Publish with summary, History (compare, restore)
T.design_code_history = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-code.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_php zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const style = () => sql(`SELECT COALESCE(sfo_style, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`);
	const before = style();
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'; DELETE FROM zzzzsys_nunext_form_history WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(700);
		await p.click('#nuDsRailCode'); await p.waitForSelector('#nuDsWork .CodeMirror'); await p.waitForTimeout(300);
		expect(await p.locator('.nuDsCodeItem').count() === 11, 'code parts ' + await p.locator('.nuDsCodeItem').count());
		await p.click('.nuDsCodeItem[data-code="sfo_style"]'); await p.waitForTimeout(200);
		await p.evaluate(() => { const cm = document.querySelector('#nuDsWork .CodeMirror').CodeMirror; cm.setValue(cm.getValue() + '\n.e2eCssMark { color: red; }'); });
		expect(await p.locator('.nuDsCodeItem.nuDsCodeDirty').count() === 1 && !(await p.isDisabled('#nuDsCodeSave')), 'dirty mark + Save');
		await p.click('#nuDsWork .CodeMirror'); await p.keyboard.press('Control+s'); await p.waitForTimeout(1500);
		expect(await p.locator('.nuDsCodeItem.nuDsCodeDirty').count() === 0, 'saved');
		expect(style() === before, 'live CSS changed');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status = 'open' AND snapshot LIKE '%e2eCssMark%'`) === '1', 'CSS in the draft');
		await p.click('#nuDsWorkClose'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1200);
		expect(/e2eCssMark/.test(await p.content()), 'the reloaded page uses the draft CSS');
		// Changes of the draft
		await p.click('#nuDsChangesBtn'); await p.waitForSelector('#nuDsChanges .nuDsDiff'); await p.waitForTimeout(300);
		expect(/sfo_style/.test(await p.textContent('#nuDsChanges')), 'changes list sfo_style');
		// Publish with the summary
		await p.click('#nuDsWorkPublish'); await p.waitForSelector('#nuDsPublishDlg.show'); await p.waitForTimeout(600);
		expect(/sfo_style/.test(await p.textContent('#nuDsPubSum')), 'publish summary');
		await p.fill('#nuDsPubNote', 'e2e code'); await p.click('#nuDsPubOk'); await p.waitForTimeout(2000);
		expect(/e2eCssMark/.test(style()), 'published');
		// History: compare and restore "before publish"
		await p.click('#nuDsRailHistory'); await p.waitForSelector('#nuDsHistory .nuDsHistItem'); await p.waitForTimeout(300);
		const items = p.locator('#nuDsHistory .nuDsHistItem');
		const n = await items.count();
		let idx = -1;
		for (let i = 0; i < n; i++) if (/before publish/.test(await items.nth(i).textContent())) { idx = i; break; }
		expect(idx >= 0, 'history has "before publish"');
		await items.nth(idx).locator('.nuDsHistCmp').click(); await p.waitForTimeout(800);
		expect(/sfo_style/.test(await p.textContent('#nuDsHistDiff')), 'compare shows the CSS change');
		await items.nth(idx).locator('.nuDsHistRestore').click(); await p.waitForTimeout(300);
		await p.click('#nuConfirm.show .js-confirm-ok'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(style() === before, 'restored: ' + style().slice(-40));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.669: design mode – the remaining form settings and object properties (in the draft), tools of the panel
T.design_props_tools = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-props.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_php zzzzsys_browse zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const snap = () => sql(`SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status = 'open'`);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsPanel .nuDsKicker'); await p.waitForTimeout(700);
		// form settings: group, type / status lists, data and list sections, tools
		for (const k of ['sfo_group', 'sfo_type', 'sfo_status', 'sfo_table', 'sfo_primary_key']) {
			expect(await p.locator(`#nuDsPanel [data-key="${k}"]`).count() === 1, 'form field ' + k);
		}
		// 0.8.820: the list's settings are in the List flyout
		await p.click('#nuDsRailList'); await p.waitForSelector('#nuDsWork:not([hidden]) #nuDsListFly');
		for (const k of ['sfo_browse_rows_per_page', 'sfo_browse_redirect_form_id']) expect(await p.locator(`#nuDsListFly [data-key="${k}"]`).count() === 1, 'list field ' + k);
		await p.click('#nuDsWorkClose'); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsAi').count() === 1 && await p.locator('#nuDsDataAccess').count() === 1 && await p.locator('#nuDsDeleteForm').count() === 1, 'tools');
		await p.fill('#nuDsPanel [data-key="sfo_group"]', 'e2egroup'); await p.press('#nuDsPanel [data-key="sfo_group"]', 'Tab'); await p.waitForTimeout(1500);
		expect(/"sfo_group":"e2egroup"/.test(snap()), 'group in the draft');
		expect(sql(`SELECT sfo_group FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`) !== 'e2egroup', 'live group changed');
		// tab order numbers
		await p.check('#nuDsTabOrder'); await p.waitForTimeout(300);
		expect(await p.locator('.nuDesignOrder').count() > 0, 'tab order numbers');
		await p.uncheck('#nuDsTabOrder'); await p.waitForTimeout(200);
		// object: text mask format (+ / pencil), datalist, attribute editor, Change column…
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(400);
		expect(await p.locator('#nuDsPanel [data-key="sob_input_format"]').count() === 1 && await p.locator('#nuDsPanel [data-ds-fmt="new"]').count() === 1, 'text mask with the format designer');
		await p.click('#nuDsPanel [data-adv="content"]'); await p.waitForTimeout(200);
		await p.fill('#nuDsPanel [data-key="sob_input_datalist"]', 'Red, Green'); await p.locator('#nuDsPanel [data-key="sob_input_datalist"]').dispatchEvent('change'); await p.waitForTimeout(1500);
		expect(/"sob_input_datalist":"Red, Green"/.test(snap()), 'datalist in the draft');
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(400);
		if (!(await p.locator('#nuDsPanel [data-sec="style"][aria-expanded="true"]').count())) await p.click('#nuDsPanel [data-sec="style"]');
		await p.waitForTimeout(200);
		if (await p.locator('#nuDsPanel [data-adv="style"]').count()) await p.click('#nuDsPanel [data-adv="style"]');
		await p.waitForTimeout(200);
		await p.click('#nuDsAttrBtn'); await p.waitForTimeout(500);
		expect(await p.locator('.modal.show').count() === 1, 'attribute editor');
		await p.keyboard.press('Escape'); await p.waitForTimeout(400);
		await p.click('#nuDsColChange'); await p.waitForSelector('#nuDsColDlg.show'); await p.waitForTimeout(1200);
		expect(/varchar|text/i.test(await p.textContent('#nuDsColCurType')), 'column info ' + await p.textContent('#nuDsColCurType'));
		expect(/ALTER TABLE/i.test(await p.textContent('#nuDsColPlan')), 'ALTER plan');
		await p.keyboard.press('Escape'); await p.waitForTimeout(400);
		// a lookup: table, JavaScript and After Browse PHP (saved through lookup_php)
		const lk = sql(`SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleorder' AND sob_all_type = 'lookup' LIMIT 1`);
		const orec = sql('SELECT sample_order_id FROM sample_order ORDER BY 1 LIMIT 1');
		if (lk && orec) {
			sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleorder'`);
			await open(p, 'index.php?p=form&f=sampleorder&r=' + orec + '&design=1'); await p.waitForSelector('#nuDsPanel .nuDsKicker'); await p.waitForTimeout(700);
			await p.click(`#nuw_${lk} .nuDesignOverlay`, { position: { x: 5, y: 5 } }); await p.waitForTimeout(400);
			if (!(await p.locator('#nuDsPanel [data-key="lookup_php"]').count())) await p.click('#nuDsPanel [data-adv="content"]');
			await p.waitForTimeout(200);
			expect(await p.locator('#nuDsPanel [data-key="sob_lookup_table"]').count() === 1 && await p.locator('#nuDsPanel [data-key="sob_lookup_javascript"]').count() === 1, 'lookup fields');
			await p.fill('#nuDsPanel [data-key="lookup_php"]', '// e2e ab'); await p.locator('#nuDsPanel [data-key="lookup_php"]').dispatchEvent('change'); await p.waitForTimeout(1500);
			expect(/e2e ab/.test(sql(`SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleorder' AND status = 'open'`)), 'After Browse PHP in the draft');
			const sub = sql(`SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleorder' AND sob_all_type = 'subform' LIMIT 1`);
			if (sub) {
				await p.click('#nuDsRailStructure'); await p.waitForTimeout(300);
				await p.click(`#nuDsTree .nuDsTreeRow[data-id="${sub}"]`); await p.waitForTimeout(500);
				await p.click('#nuDsFly .btn-close');
				expect(await p.locator('#nuDsDesignSub').count() === 1, 'Design the sub-form link');
			}
		}
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.669: design mode on the list – select a column, change its title, move it, add and remove columns (in the draft)
T.design_list = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-list.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_browse zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const snapCols = () => sql(`SELECT CONCAT(jt.id, '|', COALESCE(jt.t, ''), '|', COALESCE(jt.o, 0)) FROM zzzzsys_nunext_form_draft d, JSON_TABLE(d.snapshot, '$.browse[*]' COLUMNS(id VARCHAR(60) PATH '$.zzzzsys_browse_id',
		t VARCHAR(200) PATH '$.sbr_title', o INT PATH '$.sbr_order')) jt WHERE d.form_id = '${F}' AND d.status = 'open' ORDER BY jt.o`).split('\n').filter(Boolean)
		.map(l => { const x = l.split('|'); return { zzzzsys_browse_id: x[0], sbr_title: x[1], sbr_order: +x[2] }; });
	const liveN = sql(`SELECT COUNT(*) FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}'`);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F);
		expect(await p.locator('#nuDesignButton').count() === 1, 'Design button on the list');
		await p.click('#nuDesignButton'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		expect(await p.locator('#nuDsRailStructure').count() === 0 && await p.locator('#nuDsColTree .nuDsTreeRow').count() === +liveN, 'columns list');
		const url = p.url();
		await p.click('#nuBrowseTable th.nuBrowseTitle >> nth=1'); await p.waitForTimeout(400);
		expect(p.url() === url, 'title click sorted the list');
		expect(await p.locator('#nuDsPanel [data-key="sbr_title"]').count() === 1, 'column panel');
		const id = await p.getAttribute('#nuBrowseTable th.nuDsColSel', 'data-nu-browse-id');
		await p.fill('#nuDsPanel [data-key="sbr_title"]', 'E2E title'); await p.press('#nuDsPanel [data-key="sbr_title"]', 'Enter');
		await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1200);
		let cols = snapCols();
		expect(cols.some(c => c.zzzzsys_browse_id === id && c.sbr_title === 'E2E title'), 'title in the draft');
		expect(/E2E title/i.test(await p.textContent('#nuBrowseTable thead')), 'the list shows the draft');
		expect(await p.getAttribute('#nuBrowseTable th.nuDsColSel', 'data-nu-browse-id') === id, 'selection kept');
		await p.click('#nuDsPanel [data-ds-col="right"]'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1200);
		cols = snapCols();
		const order = cols.slice().sort((a, b) => a.sbr_order - b.sbr_order).map(c => c.zzzzsys_browse_id);
		expect(order.indexOf(id) === 2, 'moved right: ' + order.indexOf(id));
		await p.click('#nuDsColAddTh'); await p.waitForSelector('#nuDsColPick'); await p.waitForTimeout(300);   // 0.8.765: choose first
		expect(snapCols().length === +liveN, 'the "+" added a column before a choice');
		await p.click('#nuDsColPickExpr'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1200);
		expect(snapCols().length === +liveN + 1, 'column added');
		await p.click('#nuDsColDelete'); await p.waitForTimeout(300); await p.click('#nuConfirm.show .js-confirm-ok'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1200);
		expect(snapCols().length === +liveN, 'column removed');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}'`) === liveN, 'live list changed');
		// the column menu (0.8.672, right-click on a title as in nuBuilder Forte): Align → Right, Width + Enter
		await p.click('#nuBrowseTable th.nuBrowseTitle >> nth=0', { button: 'right' }); await p.waitForSelector('#nuDsColMenu');
		const cid = await p.getAttribute('#nuBrowseTable th.nuBrowseTitle >> nth=0', 'data-nu-browse-id');
		expect(/nuBrowseTitle0/.test(await p.textContent('#nuDsColMenu .nuOmHead')), 'menu head: ' + await p.textContent('#nuDsColMenu .nuOmHead'));
		await p.hover('#nuDsColMenu .nuOmHasSub'); await p.waitForTimeout(200);
		await p.click('#nuDsColMenu .nuOmSub button >> nth=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1200);
		const al = () => sql(`SELECT jt.a FROM zzzzsys_nunext_form_draft d, JSON_TABLE(d.snapshot, '$.browse[*]' COLUMNS(id VARCHAR(60) PATH '$.zzzzsys_browse_id', a VARCHAR(5) PATH '$.sbr_align', w VARCHAR(10) PATH '$.sbr_width')) jt WHERE d.form_id = '${F}' AND d.status = 'open' AND jt.id = '${cid}'`);
		expect(al() === 'r', 'menu: align right in the draft: ' + al());
		await p.click('#nuBrowseTable th.nuBrowseTitle >> nth=0', { button: 'right' }); await p.waitForSelector('#nuDsColMenu');
		await p.fill('#nuDsColMenuWidth', '155'); await p.press('#nuDsColMenuWidth', 'Enter'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1200);
		expect(/155/.test(sql(`SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status = 'open'`)), 'menu: width in the draft');
		await p.keyboard.press('Escape'); await p.waitForTimeout(300);
		expect(await p.locator('#nuDsDesignEdit').count() === 1, 'link to the edit screen');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.671: design mode – Structure filters (tab, object type) and folded tab sections; shortcuts F2, Ctrl+C / Ctrl+V, Tab, Ctrl+arrows,
// Ctrl+Z, Ctrl+S, ? (the list)
T.design_shortcuts = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-keys.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_php zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const snap = () => sql(`SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status = 'open'`);
	const LEFT = { position: { x: 5, y: 5 } };
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(700);
		// Structure: type filter, tab filter, folding
		await p.click('#nuDsRailStructure'); await p.waitForTimeout(300);
		const all = await p.locator('#nuDsTree .nuDsTreeRow').count();
		await p.selectOption('#nuDsStructType', 'input'); await p.waitForTimeout(200);
		const inputs = +sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_type = 'input'`);
		expect(await p.locator('#nuDsTree .nuDsTreeRow').count() === inputs, 'type filter: ' + await p.locator('#nuDsTree .nuDsTreeRow').count() + ' / ' + inputs);
		await p.selectOption('#nuDsStructType', ''); await p.waitForTimeout(200);
		const tab1 = sql(`SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' ORDER BY syt_order LIMIT 1`);
		const n1 = +sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_zzzzsys_tab_id = '${tab1}'`);
		await p.selectOption('#nuDsStructTab', tab1); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsTree .nuDsTreeRow').count() === n1 && await p.locator('#nuDsTree .nuDsTreeTab').count() === 1, 'tab filter');
		await p.selectOption('#nuDsStructTab', ''); await p.waitForTimeout(200);
		await p.click(`#nuDsTree [data-fold="${tab1}"]`); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsTree .nuDsTreeRow').count() === all - n1 && await p.getAttribute(`#nuDsTree [data-fold="${tab1}"]`, 'aria-expanded') === 'false', 'folded tab');
		await p.click(`#nuDsTree [data-fold="${tab1}"]`); await p.waitForTimeout(200);
		expect(await p.locator('#nuDsTree .nuDsTreeRow').count() === all, 'unfolded');
		await p.click('#nuDsFly .btn-close');
		// Ctrl+arrows: 20 px
		await p.click('#nuw_cus_name .nuDesignOverlay', LEFT); await p.waitForTimeout(300);
		const top = () => p.evaluate(() => parseInt(document.getElementById('nuw_cus_name').style.top, 10));
		const t0 = await top();
		await p.keyboard.press('Control+ArrowDown'); await p.waitForTimeout(100);
		expect(await top() === t0 + 20, 'Ctrl+ArrowDown: ' + t0 + ' → ' + await top());
		await p.keyboard.press('Control+ArrowUp'); await p.waitForTimeout(1500);
		expect(await top() === t0, 'Ctrl+ArrowUp');
		// F2: rename dialog
		await p.keyboard.press('F2'); await p.waitForSelector('#nuDsRenameDlg.show'); await p.waitForTimeout(300);
		await p.fill('#nuDsRenLabel', 'E2E F2'); await p.press('#nuDsRenLabel', 'Enter'); await p.waitForTimeout(1500);
		expect(/"sob_all_label":"E2E F2"/.test(snap()) && /E2E F2/.test(await p.textContent('#label_cus_name')), 'F2 rename');
		// Tab: the next object in tab order
		await p.click('#nuw_cus_code .nuDesignOverlay', LEFT); await p.waitForTimeout(300);
		await p.keyboard.press('Tab'); await p.waitForTimeout(300);
		const order = sql(`SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_zzzzsys_tab_id = '${tab1}' ORDER BY sob_all_order`).split('\n');
		expect((await p.textContent('#nuDsPanel .nuDsName')).trim() === order[order.indexOf('cus_code') + 1], 'Tab: ' + await p.textContent('#nuDsPanel .nuDsName'));
		// Ctrl+C / Ctrl+V: a copy with a new ID on the same tab
		await p.click('#nuw_cus_email .nuDesignOverlay', LEFT); await p.waitForTimeout(300);
		await p.keyboard.press('Control+c'); await p.waitForTimeout(200);
		await p.keyboard.press('Control+v'); await p.waitForSelector('#nuw_cus_email_2', { timeout: 8000 }); await p.waitForTimeout(800);
		expect(/"sob_all_id":"cus_email_2"/.test(snap()), 'pasted copy in the draft');
		expect((await p.textContent('#nuDsPanel .nuDsName')).trim() === 'cus_email_2', 'pasted copy selected');
		// Ctrl+Z: the paste is undone
		await p.keyboard.press('Control+z'); await p.waitForTimeout(2500);
		expect(!/"sob_all_id":"cus_email_2"/.test(snap()), 'Ctrl+Z');
		// Ctrl+S: no browser dialog, a message; ?: the list of shortcuts
		await p.keyboard.press('Control+s'); await p.waitForTimeout(200);
		expect((await p.textContent('#nuDsMsg')).length > 5, 'Ctrl+S message');
		await p.keyboard.press('Shift+?'); await p.waitForSelector('#nuDsKeysDlg.show');
		expect(await p.locator('#nuDsKeysDlg kbd').count() > 10, 'shortcut list');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
		await p.evaluate(() => { try { localStorage.removeItem('nunext-design-clip'); } catch (e) { /* */ } });
	}
};

T.design_box_code_refresh = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec || sql(`SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_type = 'select'`) === '0') { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design5.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	const selId = sql(`SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_type = 'select' ORDER BY sob_all_order LIMIT 1`);
	let navs = 0;
	const nav = (f) => { if (f === p.mainFrame()) navs++; };
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(700);
		p.on('framenavigated', nav);
		await p.click('#nuDsRailAdd'); await p.waitForTimeout(300);
		await p.click('#nuDsElGrid [data-type="contentbox"]'); await p.waitForTimeout(2000);
		expect(navs === 0 && await p.locator('#nuw_box').count() === 1, 'box added without a reload: ' + navs);
		await p.click('#nuDsFly .btn-close'); await p.keyboard.press('Escape'); await p.waitForTimeout(200);
		const tb = await p.locator('#nuw_box .nuDesignEdgeT').boundingBox();
		await p.mouse.click(tb.x + 40, tb.y + 8); await p.waitForTimeout(300);
		expect((await p.textContent('#nuDsPanel .nuDsName')).trim() === 'box', 'title selects the box');
		const t0 = await p.evaluate(() => document.getElementById('nuw_box').offsetTop);
		await p.mouse.move(tb.x + 40, tb.y + 8); await p.mouse.down(); await p.mouse.move(tb.x + 60, tb.y + 30, { steps: 5 }); await p.mouse.move(tb.x + 60, tb.y + 58, { steps: 5 }); await p.mouse.up();
		await p.waitForTimeout(1200);
		expect(await p.evaluate(() => document.getElementById('nuw_box').offsetTop) === t0 + 50, 'box dragged');
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(300);
		await p.selectOption('#nuDsPanel select[data-key="sob_all_access"]', '1'); await p.waitForTimeout(2000);
		expect(navs === 0 && (await p.textContent('#nuDsPanel .nuDsName')).trim() === 'cus_name', 'access change without a reload, selection kept');
		expect(await p.evaluate(() => { const i = document.getElementById('cus_name'); return !!i && (i.readOnly || i.disabled); }), 'form drawn again (read only)');
		await p.click('#nuw_' + selId + ' .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(300);
		await p.click('#nuDsPanel .nuDsCodeBtn'); await p.waitForSelector('#nuDsCodeDlg.show'); await p.waitForTimeout(400);
		expect(await p.locator('#nuDsCodeDlg .CodeMirror').count() === 1 && await p.locator('#nuDsCodeDlg .nuDsCodeTools button').count() > 3, 'code window with toolbar');
		await p.evaluate(() => { const cm = document.querySelector('#nuDsCodeDlg .CodeMirror').CodeMirror; cm.setValue('1|One|2|Two'); });
		await p.click('#nuDsCodeOk'); await p.waitForTimeout(2000);
		expect(await p.inputValue('#nuDsPanel textarea[data-key="sob_select_sql"]') === '1|One|2|Two' && navs === 0, 'code saved');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		p.off('framenavigated', nav);
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.659: the label menu also on nuBuilder Forte's User Home (its objects are edited in the builder, 0.8.605) – right-click a run button,
// Rename… changes its label
T.userhome_obj_menu = async (s) => {
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'nuuserhome' ORDER BY syt_order LIMIT 1");
	if (!tab) { s.skip = 'no User Home'; return; }
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_run_zzzzsys_form_id, sob_run_method, sob_run_type, sob_run_target)
		VALUES ('e2euhrun', 'nuuserhome', '${tab}', 'run', 'e2e_uhrun', 'UH run', 990, 60, 60, 160, 30, '0', 'samplecustomer', 'b', 'F', '0')`);
	try {
		await open(p, 'index.php?p=form&f=nuuserhome');
		await p.click('#e2e_uhrun', { button: 'right' }); await p.waitForTimeout(250);
		expect(await p.locator('.nuObjMenu .nuOmHead b').textContent().catch(() => null) === 'e2e_uhrun', 'no menu on User Home');
		await p.evaluate(() => { window.prompt = () => 'UH renamed'; });   // the page accepts dialogs already (login helper)
		await Promise.all([p.waitForNavigation().catch(() => {}), p.locator('.nuObjMenu button', { hasText: 'Rename' }).first().click()]);
		await p.waitForTimeout(500);
		expect(sql("SELECT sob_all_label FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2euhrun'") === 'UH renamed', 'not renamed');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2euhrun'");
		sql("DELETE FROM zzzzsys_nunext_form_history WHERE form_id = 'nuuserhome' AND created_at > NOW() - INTERVAL 1 HOUR");
	}
};

// object icons (0.8.559, nuBuilder's sob_input_icon): inside input buttons (now <button> with .nuButtonLabel, HTML label), run buttons
// and words – icon class (fa / bi-) or HTML, "left|right"; an empty run label is an empty button named after its target; the builder's
// Icon field only for those types, saved to sob_input_icon, the preview shows it
T.object_icons = async (s) => {
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	if (!tab) { s.skip = 'no sample data'; return; }
	const desc = sql("SELECT sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleproduct'");
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_input_type, sob_input_icon, sob_run_zzzzsys_form_id, sob_run_method, sob_run_type, sob_run_target)
		VALUES ('e2eicbtn', 'samplecustomer', '${tab}', 'input', 'e2e_icbtn', 'Go <b>now</b>', 994, 900, 20, 140, 30, '0', 'button', 'fa fa-table', NULL, NULL, NULL, NULL),
		('e2eicrun', 'samplecustomer', '${tab}', 'run', 'e2e_icrun', '', 993, 900, 180, 40, 30, '0', NULL, 'bi-star', 'sampleproduct', 'b', 'F', '0'),
		('e2eicword', 'samplecustomer', '${tab}', 'word', 'e2e_icword', 'Note', 992, 900, 240, 140, 30, '0', NULL, 'fa fa-check|<b class="e2eR">!</b>', NULL, NULL, NULL, NULL)`);
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
		const d = await p.evaluate(() => {
			const q = (id) => document.getElementById(id);
			const b = q('e2e_icbtn'), r = q('e2e_icrun'), w = q('e2e_icword');
			const first = (el) => el && el.firstElementChild ? el.firstElementChild.className : '';
			return { btnTag: b && b.tagName, btnFirst: first(b), btnBold: !!(b && b.querySelector('.nuButtonLabel b')), btnIconAttr: b && b.getAttribute('nu-data-icon'),
				runText: r && r.textContent.trim(), runIcon: !!(r && r.querySelector('i.bi.bi-star')), runAria: r && r.getAttribute('aria-label'),
				wordFirst: first(w), wordLast: w && w.lastElementChild ? w.lastElementChild.innerHTML : '', wordText: w && w.textContent.replace(/\s+/g, ' ').trim() };
		});
		expect(d.btnTag === 'BUTTON' && /fa-table/.test(d.btnFirst) && d.btnBold && d.btnIconAttr === 'fa fa-table', 'input button: ' + JSON.stringify(d));
		expect(d.runText === '' && d.runIcon && d.runAria === desc, 'run button with empty label: ' + JSON.stringify(d));
		expect(/fa-check/.test(d.wordFirst) && /e2eR/.test(d.wordLast) && d.wordText === 'Note !', 'word: ' + JSON.stringify(d));
		// nuAddInputIcon() from form code: inside, as nuBuilder
		const js = await p.evaluate(() => { nuAddInputIcon('e2e_icword', 'bi-heart'); const w = document.getElementById('e2e_icword'); return !!w.querySelector('i.bi.bi-heart') && w.parentNode.querySelector(':scope > i.bi-heart') === null; });
		expect(js, 'nuAddInputIcon: icon not inside the object');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
		// design mode (0.8.670, before: the builder): Icon only for button / run / word; saved into the draft; the form shows it
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer'");
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1&design=1'); await p.waitForSelector('#nuDsPanel .nuDsKicker'); await p.waitForTimeout(600);
		const style = async () => { if (!(await p.locator('#nuDsPanel [data-sec="style"][aria-expanded="true"]').count())) { await p.click('#nuDsPanel [data-sec="style"]'); await p.waitForTimeout(200); } };
		await p.click('#nuw_cus_name .nuDesignOverlay', { position: { x: 5, y: 5 } }); await p.waitForTimeout(400); await style();
		expect(await p.locator('#nuDsPanel [data-key=sob_input_icon]').count() === 0, 'Icon field on a text input');
		await p.click('#nuw_e2e_icrun .nuDesignOverlay', { position: { x: 3, y: 3 } }); await p.waitForTimeout(400); await style();
		expect(await p.locator('#nuDsPanel [data-key=sob_input_icon]').inputValue() === 'bi-star', 'Icon field of the run object');
		await p.fill('#nuDsPanel [data-key=sob_input_icon]', 'fa fa-gear'); await p.press('#nuDsPanel [data-key=sob_input_icon]', 'Enter');
		await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(1500);
		expect(/fa fa-gear/.test(sql("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer' AND status = 'open'")), 'icon not saved in the draft');
		expect(await p.locator('#e2e_icrun i.fa-gear').count() === 1, 'the form without the new icon');
		await p.click('#nuw_e2e_icrun .nuDesignOverlay', { position: { x: 3, y: 3 } }); await p.waitForTimeout(400); await style();
		await p.fill('#nuDsPanel [data-key=sob_input_icon]', 'x'.repeat(51)); await p.press('#nuDsPanel [data-key=sob_input_icon]', 'Enter'); await p.waitForTimeout(1200);
		expect(!/x{51}/.test(sql("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer' AND status = 'open'")), 'too long icon saved');
		expect(/50/.test(await p.textContent('#nuDsMsg')), 'no message for the too long icon: ' + await p.textContent('#nuDsMsg'));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id IN ('e2eicbtn', 'e2eicrun', 'e2eicword'); DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer'");
	}
};

// form drafts (0.8.386): start, change, preview (read-only, banner), other users keep live, compare, publish (version, history),
// restore, discard, conflict (live changed meanwhile → overwrite only with the typed code), form status Draft
T.form_drafts = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const meta = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_event', 'zzzzsys_browse', 'zzzzsys_php', 'zzzzsys_nunext_form_history'];
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-drafts.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	const label = () => sql("SELECT sob_all_label FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '" + F + "' AND sob_all_id = 'cus_name'");
	const cust = s.cust || sql('SELECT MIN(sample_customer_id) FROM sample_customer');
	const before = label();
	try {
		await open(p, 'index.php?p=builder&f=' + F);
		const api = (body) => p.evaluate(async (bd) => {
			const csrf = (window.nuNext && window.nuNext.csrf) || document.querySelector('input[name=_csrf]').value;
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(bd) });
			return Object.assign({ status: r.status }, await r.json());
		}, Object.assign({ form_id: F }, body));
		let r = await api({ action: 'draft.start', note: 'e2e' });
		expect(r.ok && r.draft && r.draft.rev === 0, 'start: ' + JSON.stringify(r).slice(0, 200));
		const D = r.draft, obj = r.objects.find(o => o.sob_all_id === 'cus_name');
		r = await api({ action: 'object.save', draft_id: D.id, rev: 0, object_id: obj.zzzzsys_object_id, fields: { sob_all_label: 'E2E draft label' } });
		expect(r.ok && r.draft.rev === 1 && r.draft.changes === 1, 'draft change: ' + JSON.stringify(r.draft));
		r = await api({ action: 'form.save', draft_id: D.id, rev: 1, php: { BE: "nuSetProperty('e2e_draft_be', 'draft');" } });
		expect(r.ok && r.draft.changes === 2, 'draft PHP: ' + (r.error || JSON.stringify(r.draft)));
		expect(label() === before, 'live changed by the draft');
		r = await api({ action: 'object.save', draft_id: D.id, rev: 1, object_id: obj.zzzzsys_object_id, fields: { sob_all_label: 'stale' } });
		expect(!r.ok && r.code === 'draft_rev', 'stale revision accepted');
		r = await api({ action: 'object.save', object_id: obj.zzzzsys_object_id, fields: { sob_all_label: 'live' } });
		expect(!r.ok && r.code === 'draft_open_confirm', 'live change while a draft is open: ' + r.code);
		// preview: banner, the draft's label, saving refused
		await open(p, 'index.php?p=form&f=' + F + '&r=' + cust + '&draft=' + D.id);
		expect(await p.locator('.nuDraftBanner').count() === 1, 'no preview banner');
		expect(/E2E draft label/.test(await p.locator('#label_cus_name').textContent()), 'preview does not show the draft');
		const sv = await p.evaluate(async () => { const r = await fetch('index.php?p=formapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf },
			body: JSON.stringify({ action: 'save', form_id: nuNext.form.id, record_id: nuNext.record_id, values: {}, edited: [] }) }); return r.status; });
		expect(sv === 403, 'saving in a read-only preview: ' + sv);
		// another user keeps the live form
		const a = await login('sample_anna', UPW);
		await open(a, 'index.php?p=form&f=' + F + '&r=' + cust);
		expect(!/E2E draft label/.test(await a.locator('#label_cus_name').textContent()) && await a.locator('.nuDraftBanner').count() === 0, 'another user sees the draft');
		// compare, publish
		await open(p, 'index.php?p=builder&f=' + F + '&draft=1');
		r = await api({ action: 'draft.diff', draft_id: D.id });
		expect(r.diff.counts.objects === 1 && r.diff.counts.php === 1 && r.diff.objects.changed[0].sob_all_id === 'cus_name', 'compare: ' + JSON.stringify(r.diff.counts));
		// a note is optional since 0.8.797 (design_done_publish checks the automatic one)
		r = await api({ action: 'draft.publish', draft_id: D.id, note: 'E2E publish' });
		if (!r.ok && r.warnings) r = await api({ action: 'draft.publish', draft_id: D.id, note: 'E2E publish', warnings_ok: true });
		expect(r.ok && r.version >= 1 && label() === 'E2E draft label', 'publish: ' + JSON.stringify(r).slice(0, 200));
		expect(sql("SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = '" + F + "_BE'").includes('e2e_draft_be'), 'published PHP missing');
		await open(a, 'index.php?p=form&f=' + F + '&r=' + cust);
		expect(/E2E draft label/.test(await a.locator('#label_cus_name').textContent()), 'users do not see the published version');
		await a.context().close();
		const hist = sql("SELECT GROUP_CONCAT(action ORDER BY created_at DESC, zzzzsys_nunext_form_history_id SEPARATOR '|') FROM zzzzsys_nunext_form_history WHERE form_id = '" + F + "'");
		expect(/(^|\|)published v\d/.test(hist) && hist.includes('before publish'), 'history: ' + hist.slice(0, 120));
		// restore "before publish" → the old label
		const bp = sql("SELECT zzzzsys_nunext_form_history_id FROM zzzzsys_nunext_form_history WHERE form_id = '" + F + "' AND action = 'before publish' ORDER BY created_at DESC LIMIT 1");
		r = await api({ action: 'history.restore', id: bp });
		expect(r.ok && label() === before, 'restore before publish: ' + (r.error || label()));
		// discard
		r = await api({ action: 'draft.start' });
		const D2 = r.draft;
		r = await api({ action: 'draft.discard', draft_id: D2.id });
		expect(r.ok && sql("SELECT status FROM zzzzsys_nunext_form_draft WHERE zzzzsys_nunext_form_draft_id = '" + D2.id + "'") === 'discarded' && label() === before, 'discard');
		// conflict: live changed after the draft started
		r = await api({ action: 'draft.start' });
		const D3 = r.draft;
		r = await api({ action: 'object.save', draft_id: D3.id, rev: 0, object_id: obj.zzzzsys_object_id, fields: { sob_all_label: 'E2E conflict draft' } });
		expect(r.ok, 'save in the draft: ' + JSON.stringify(r).slice(0, 160));
		sql("UPDATE zzzzsys_object SET sob_all_label = 'Changed in nuBuilder' WHERE zzzzsys_object_id = '" + obj.zzzzsys_object_id + "'");
		r = await api({ action: 'draft.publish', draft_id: D3.id, note: 'conflict' });
		expect(!r.ok && r.conflict && r.changes.counts.objects === 1, 'conflict not found: ' + JSON.stringify(r).slice(0, 160));
		r = await api({ action: 'draft.publish', draft_id: D3.id, note: 'conflict', force: true, confirm_code: 'wrong', warnings_ok: true });
		expect(!r.ok && label() === 'Changed in nuBuilder', 'overwrite without the code');
		r = await api({ action: 'draft.publish', draft_id: D3.id, note: 'conflict', force: true, confirm_code: sql("SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'"), warnings_ok: true });
		expect(r.ok && label() === 'E2E conflict draft', 'overwrite: ' + JSON.stringify(r).slice(0, 160));
		// a form never published (status Draft): designers only, "Publish form" makes it active
		sql("UPDATE zzzzsys_form SET sfo_status = '0' WHERE zzzzsys_form_id = '" + F + "'");
		const a2 = await login('sample_anna', UPW);
		const rs = await open(a2, 'index.php?p=form&f=' + F);
		expect(rs.status() === 403, 'a Draft form opens for users: ' + rs.status());
		await a2.context().close();
		r = await api({ action: 'form.publishnew' });
		expect(r.ok && sql("SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'") === '1', 'publish new form');
		await open(p, 'index.php?p=form&f=' + F + '&draft=off');
	} finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(keep) });
		fs.unlinkSync(keep);
		sql('DELETE FROM zzzzsys_nunext_form_draft');
	}
};

// form drafts (0.8.386): the same builder actions once live and once in a draft (then published) give the same form
T.form_drafts_parity = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const meta = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_event', 'zzzzsys_browse', 'zzzzsys_php', 'zzzzsys_nunext_form_history'];
	const keep = path.join(os.tmpdir(), 'nunext-e2e-parity.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	const restore = () => execSync('mysql ' + DB, { input: fs.readFileSync(keep) });
	// the form without generated ids: objects by sob_all_id, tabs by title, browse columns by display
	const raw = (q) => execSync('mysql -N -r -B ' + DB, { input: q }).toString().trim();     // raw: no escaping of line ends in the JSON
	const norm = () => JSON.parse(raw(`SELECT JSON_OBJECT(
		'form', (SELECT JSON_OBJECT('d', sfo_description, 'js', sfo_javascript, 'json', sfo_json) FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'),
		'tabs', (SELECT JSON_ARRAYAGG(JSON_ARRAY(syt_title, syt_order, syt_access)) FROM (SELECT * FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' ORDER BY syt_order) x),
		'objects', (SELECT JSON_ARRAYAGG(JSON_ARRAY(o.sob_all_id, o.sob_all_label, o.sob_all_type, o.sob_input_type, o.sob_all_order, t.syt_title, o.sob_all_json, o.sob_all_access, o.sob_all_validate, o.sob_all_cloneable, o.sob_all_table,
			(SELECT JSON_ARRAYAGG(JSON_ARRAY(e.sev_event, e.sev_javascript)) FROM zzzzsys_event e WHERE e.sev_zzzzsys_object_id = o.zzzzsys_object_id)))
			FROM (SELECT * FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' ORDER BY sob_all_id) o LEFT JOIN zzzzsys_tab t ON t.zzzzsys_tab_id = o.sob_all_zzzzsys_tab_id),
		'browse', (SELECT JSON_ARRAYAGG(JSON_ARRAY(sbr_title, sbr_display, sbr_order, sbr_width, sbr_align, sbr_format, sbr_json)) FROM (SELECT * FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}' ORDER BY sbr_order) x),
		'php', (SELECT JSON_ARRAYAGG(JSON_ARRAY(SUBSTRING(zzzzsys_php_id, LENGTH('${F}') + 2), sph_php, sph_system, sph_hide)) FROM (SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id LIKE '${F}\\_%' ORDER BY zzzzsys_php_id) x))`));
	try {
		await open(p, 'index.php?p=builder&f=' + F);
		const api = (body) => p.evaluate(async (bd) => {
			const csrf = (window.nuNext && window.nuNext.csrf) || document.querySelector('input[name=_csrf]').value;
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(bd) });
			return r.json();
		}, Object.assign({ form_id: F }, body));
		const run = async (draft) => {
			let rev = 0;
			const call = async (b) => {
				const r = await api(Object.assign({}, b, draft ? { draft_id: draft, rev } : {}));
				if (!r.ok) throw new Error(b.action + ': ' + r.error);
				if (r.draft) rev = r.draft.rev;
				return r;
			};
			let st = await call({ action: 'state' });
			const id = (fid) => st.objects.find(o => o.sob_all_id === fid).zzzzsys_object_id;
			const tab0 = st.tabs[0].zzzzsys_tab_id;
			st = await call({ action: 'object.save', object_id: '', fields: { sob_all_type: 'input', sob_all_id: 'e2e_par', sob_all_label: 'Parity', sob_input_type: 'text', sob_all_zzzzsys_tab_id: tab0, sob_all_top: 300, sob_all_left: 100 },
				events: [{ event: 'onchange', js: 'nuHasBeenEdited();' }] });
			st = await call({ action: 'object.save', object_id: id('cus_name'), fields: { sob_all_label: 'Name (parity)', sob_all_validate: '1' }, events: [{ event: 'onblur', js: 'void 0;' }] });
			st = await call({ action: 'object.order', ids: st.objects.map(o => o.zzzzsys_object_id).reverse() });
			st = await call({ action: 'tabs.save', tabs: st.tabs.map(t => ({ id: t.zzzzsys_tab_id, title: t.syt_title, access: t.syt_access || '0' })).concat([{ id: '', title: 'Parity tab', access: '0' }]) });
			st = await call({ action: 'browse.save', columns: st.browse.map(b => ({ id: b.zzzzsys_browse_id, title: b.sbr_title, display: b.sbr_display, align: b.sbr_align || 'l', format: b.sbr_format || '', width: b.sbr_width }))
				.concat([{ id: '', title: 'Parity col', display: 'cus_code', align: 'c', width: 80, filter: true }]) });
			st = await call({ action: 'form.save', fields: { sfo_javascript: '// parity' }, php: { BB: '$x = 1;' } });
			st = await call({ action: 'layout.convert' });
			st = await call({ action: 'object.delete', object_id: id('cus_notes') });
			return st;
		};
		await run('');
		const live = norm();
		restore();
		let r = await api({ action: 'draft.start' });
		await run(r.draft.id);
		r = await api({ action: 'draft.publish', draft_id: r.draft.id, note: 'parity', warnings_ok: true });
		expect(r.ok, 'publish: ' + JSON.stringify(r).slice(0, 200));
		const pub = norm();
		for (const x of [live, pub]) for (const k of ['objects', 'tabs', 'browse', 'php']) if (Array.isArray(x[k])) x[k].sort((m, n) => JSON.stringify(m) < JSON.stringify(n) ? -1 : 1);   // JSON_ARRAYAGG has no order
		for (const k of Object.keys(live)) expect(JSON.stringify(live[k]) === JSON.stringify(pub[k]), 'live and published draft differ in ' + k + ':\n' + JSON.stringify(live[k]).slice(0, 600) + '\n' + JSON.stringify(pub[k]).slice(0, 600));
	} finally {
		restore();
		fs.unlinkSync(keep);
		sql('DELETE FROM zzzzsys_nunext_form_draft');
	}
};

T.custom_page_create = async (s) => {               // 0.8.861: custom pages have their own card + dialogue, the list Custom pages, no wizard type
	const p = s.ga;
	const code = 'e2ecp' + Date.now().toString(36);
	try {
		await open(p, 'index.php?p=builder&new=1');
		expect(await p.locator('#nuNfC_page').count() === 1, 'no Custom page card');
		expect(/form or page/i.test(await p.locator('h1').first().textContent()), 'heading not "New form or page"');
		await p.click('#nuNfC_page'); await p.waitForSelector('#nuNfPageDlg.show');
		await p.click('#nuNfPageCreate'); await p.waitForTimeout(200);
		expect(await p.locator('#nuNfPageDesc.is-invalid').count() === 1, 'empty description accepted');
		await p.fill('#nuNfPageDesc', 'E2E Pagé ' + code);
		expect(await p.inputValue('#nuNfPageCode') === ('e2e_page_' + code), 'code not proposed: ' + await p.inputValue('#nuNfPageCode'));
		await p.fill('#nuNfPageCode', code);
		await p.selectOption('#nuNfPageMode', 'iframe');
		await Promise.all([p.waitForURL(/p=builder&f=/), p.click('#nuNfPageCreate')]);
		const fid = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}' AND sfo_type = 'page'`);
		expect(fid !== '', 'custom page not created');
		expect(sql(`SELECT open_mode FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${fid}'`) === 'iframe', 'open mode not saved');
		// the wizard no longer offers the type
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		expect(await p.locator('#nb_type option[value="page"]').count() === 0, 'wizard still offers Custom page');
		// the list Custom pages: title, only pages, Add opens the dialogue
		await open(p, 'index.php?p=forms');
		expect(await p.locator('.dropdown-menu a[href*="type=page"]').count() >= 1, 'Tools without Custom pages');
		await open(p, 'index.php?p=forms&type=page');
		expect(/Custom pages/.test(await p.locator('h1').first().textContent()), 'list title not Custom pages');
		expect(await p.locator(`text=${code}`).count() >= 1, 'new page not listed');
		await p.click('#nuFormsAdd'); await p.waitForSelector('#nuNfPageDlg.show', { timeout: 5000 });
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql(`DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id IN (SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}')`);
		sql(`DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id IN (SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}')`);
		sql(`DELETE FROM zzzzsys_form WHERE sfo_code = '${code}'`);
	}
};

T.page_forms = async (s) => {
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const F = 'e2epg' + Date.now().toString(36), dir = path.join(SITE, 'custom', 'pages', F);
	const id = 'e2epage' + Date.now().toString(36).slice(-6);
	const page = `<?php
defined('NU_PAGE') || exit;
switch ($nu->action()) {
	case 'info':
		$nu->json(['user' => $nu->user(), 'real' => $nu->realUser(), 'rights' => $nu->rights(), 'perm' => $nu->hasPermission('e2e_none'), 'dept' => $nu->inDepartment('sample', 'x'),
			'pos' => $nu->hasPosition('Trainee'), 'team' => $nu->inTeam('Lead'), 'parent' => $nu->parent(), 'record' => $nu->recordId(), 'colour' => $nu->setting('a.colour'),
			'secret' => $nu->secret('token'), 'ro' => $nu->readOnly(), 'api' => $nu->apiVersion(), 'hash' => $nu->hash('#USER_ID#|#RECORD_ID#'), 'cwd' => basename(getcwd()),
			'n' => count($nu->users(['department' => 'Sample'])), 'url' => $nu->url(['action' => 'x'])]);
	case 'save':
		$nu->log('e2e page log', ['v' => $nu->param('v')]);
		$nu->json(['ok' => $nu->isPost(), 'echo' => $nu->param('v')]);
	case 'boom':
		throw new RuntimeException('e2e page boom');
	case 'bad':
		$nu->insert('x; DROP TABLE y', ['a' => 1]);
		break;
	case 'deny':
		$nu->require($nu->hasPermission('e2e_none'));
		break;
	default:
		echo '<!doctype html><title>e2e page</title><p id="pg">page ' . $nu->h($nu->slug()) . '</p>' . $nu->sdk();
}
`;
	fs.mkdirSync(path.join(dir, 'assets'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'index.php'), page);
	fs.writeFileSync(path.join(dir, 'secrets.php'), "<?php\ndefined('NU_PAGE') || exit;\nreturn ['token' => 'e2e-secret'];\n");
	fs.writeFileSync(path.join(dir, 'assets', 'a.css'), 'body{color:red}');
	fs.writeFileSync(path.join(dir, 'assets', 'x.php'), '<?php echo "x";');
	sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_status) VALUES ('${id}', 'page', '${F}', 'E2E page', '1');
		INSERT INTO zzzzsys_nunext_page (zzzzsys_nunext_page_id, open_mode, iframe_height, settings) VALUES ('${id}', 'tab', 'auto', '{"a":{"colour":"teal"}}');
		INSERT INTO zzzzsys_access_form (zzzzsys_access_form_id, slf_zzzzsys_access_id, slf_zzzzsys_form_id, slf_save_button) VALUES ('${id}a', 'sample_viewer', '${id}', '1')`);
	const anna = await login('sample_anna', UPW), ben = await login('sample_ben', UPW);
	const get = (p, q, headers = {}) => p.evaluate(async ([u, h]) => { const r = await fetch(u, { headers: h, redirect: 'manual' }); const t = await r.text(); let j = null; try { j = JSON.parse(t); } catch (e) {} return { status: r.status, j, t, ct: r.headers.get('content-type') || '', h: Object.fromEntries(r.headers.entries()) }; }, [BASE + q, headers]);
	const J = { Accept: 'application/json' };
	try {
		// signed out: the login (HTML) or 401 JSON
		const out = await browser.newContext(); const o = await out.newPage();
		await o.goto(BASE + 'index.php?p=page&f=' + F);
		expect(/p=login/.test(o.url()), 'signed out: no login page');
		const o401 = await o.evaluate(async (u) => { const r = await fetch(u, { headers: { Accept: 'application/json' } }); return [r.status, await r.json()]; }, BASE + 'index.php?p=page&f=' + F + '&action=info');
		expect(o401[0] === 401 && o401[1].error === 'login', 'signed out JSON: ' + JSON.stringify(o401));
		// direct request of the file: the guard ends it (the PHP server runs it – Apache denies it)
		const direct = await o.evaluate(async (u) => { const r = await fetch(u); return (await r.text()).trim(); }, BASE + 'custom/pages/' + F + '/index.php');
		expect(direct === '' || /denied|forbidden/i.test(direct), 'direct call gives output: ' + direct.slice(0, 80));
		await out.close();
		// user with the view right: $nu
		let r = await get(anna, 'index.php?p=page&f=' + F + '&action=info&r=7&pf=samplecustomer&pr=3', J);
		expect(r.status === 200 && r.j, 'info: ' + r.status + ' ' + r.t.slice(0, 200));
		const u = r.j.user;
		expect(u.login === 'sample_anna' && u.position === 'Trainee' && u.department === 'Sample' && !u.globeadmin && !u.impersonated, 'user(): ' + JSON.stringify(u));
		expect(r.j.rights.view && r.j.rights.save && !r.j.rights.delete, 'rights(): ' + JSON.stringify(r.j.rights));
		expect(!r.j.perm && r.j.dept && r.j.pos && !r.j.team, 'access helpers: ' + JSON.stringify([r.j.perm, r.j.dept, r.j.pos, r.j.team]));
		expect(r.j.parent && r.j.parent.form_id === 'samplecustomer' && r.j.parent.record_id === '3' && r.j.record === '7', 'parent / record: ' + JSON.stringify(r.j.parent));
		expect(r.j.colour === 'teal' && r.j.secret === 'e2e-secret' && r.j.api === 1 && r.j.cwd === F && !r.j.ro, 'settings / secret / cwd: ' + JSON.stringify(r.j));
		expect(r.j.hash === u.id + '|7' && r.j.n >= 5 && /p=page/.test(r.j.url) && /r=7/.test(r.j.url), 'hash / users / url: ' + JSON.stringify([r.j.hash, r.j.n, r.j.url]));
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=info&pf=nuuser', J);
		expect(r.j && r.j.parent === null, 'parent without the right on it');
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=deny', J);
		expect(r.status === 403 && r.j.error === 'forbidden', '$nu->require(false): ' + r.status);
		// headers of the page route
		r = await get(anna, 'index.php?p=page&f=' + F);
		expect(r.status === 200 && r.h['x-frame-options'] === 'SAMEORIGIN' && /^frame-ancestors 'self'$/.test(r.h['content-security-policy']) && /no-store/.test(r.h['cache-control']), 'page headers: ' + JSON.stringify(r.h));
		expect(/nupage(\.min)?\.js/.test(r.t) && /data-csrf=/.test(r.t), 'sdk() missing');
		// no view right: 403 (HTML and JSON) – before any page code
		r = await get(ben, 'index.php?p=page&f=' + F, J);
		expect(r.status === 403 && r.j.error === 'forbidden', 'without the right: ' + r.status);
		expect((await open(ben, 'index.php?p=page&f=' + F)).status() === 403, 'without the right (HTML)');
		// CSRF: JSON 400 / HTML 400; with the token the POST works
		const post = (p, q, token, json) => p.evaluate(async ([u, tk, js]) => { const fd = new FormData(); fd.append('v', 'hi'); if (tk) fd.append('_csrf', document.querySelector('script[data-csrf]') ? document.querySelector('script[data-csrf]').dataset.csrf : '');
			const r = await fetch(u, { method: 'POST', body: fd, headers: js ? { Accept: 'application/json' } : {} }); const t = await r.text(); let j = null; try { j = JSON.parse(t); } catch (e) {} return { status: r.status, j }; }, [BASE + q, token, json]);
		await open(anna, 'index.php?p=page&f=' + F);
		r = await post(anna, 'index.php?p=page&f=' + F + '&action=save', false, true);
		expect(r.status === 400 && r.j && r.j.error === 'csrf', 'POST without token (JSON): ' + JSON.stringify(r));
		r = await post(anna, 'index.php?p=page&f=' + F + '&action=save', false, false);
		expect(r.status === 400 && !r.j, 'POST without token (HTML): ' + r.status);
		r = await post(anna, 'index.php?p=page&f=' + F + '&action=save', true, true);
		expect(r.status === 200 && r.j.ok && r.j.echo === 'hi', 'POST with token: ' + JSON.stringify(r));
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_debug WHERE deb_message LIKE 'e2e page log%' AND deb_flag = 'page:" + F + "'") >= 1, '$nu->log() wrote nothing');
		r = await anna.evaluate(async () => { const r = await NuPage.fetch(location.href.replace(/&action=[^&]*/, '') + '&action=save', { method: 'POST', body: new URLSearchParams({ v: 'sdk' }) }); return r.json(); });
		expect(r.echo === 'sdk', 'NuPage.fetch: ' + JSON.stringify(r));
		// errors: details only for the globeadmin; bad table names write nothing
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=boom', J);
		expect(r.status === 500 && r.j.error === 'server' && !/boom|index\.php/.test(JSON.stringify(r.j)), 'error for a user: ' + JSON.stringify(r.j));
		r = await get(s.ga, 'index.php?p=page&f=' + F + '&action=boom', J);
		expect(r.status === 500 && /e2e page boom/.test(r.j.detail || '') && /index\.php:\d+/.test(r.j.detail || ''), 'error for the globeadmin: ' + JSON.stringify(r.j));
		r = await get(s.ga, 'index.php?p=page&f=' + F + '&action=bad', J);
		expect(r.status === 500 && /Invalid table or column name/.test(r.j.detail || ''), 'bad table name: ' + JSON.stringify(r.j));
		// assets: only assets/, whitelisted types
		r = await get(anna, 'index.php?p=pageasset&f=' + F + '&file=assets/a.css');
		expect(r.status === 200 && /text\/css/.test(r.ct) && r.h['x-content-type-options'] === 'nosniff' && /private/.test(r.h['cache-control'] || ''), 'asset: ' + r.status + ' ' + r.ct);
		for (const bad of ['assets/../index.php', '../index.php', 'index.php', 'assets/x.php', 'secrets.php', '/etc/passwd', 'assets/./a.css', 'assets\\a.css'])
			expect((await get(anna, 'index.php?p=pageasset&f=' + F + '&file=' + encodeURIComponent(bad))).status === 404, 'asset not refused: ' + bad);
		// open modes: tab = redirect to the raw URL, iframe = wrapper with the page in an iframe (breadcrumb, host script)
		await open(anna, 'index.php?p=form&f=' + F + '&r=5');
		expect(/p=page/.test(anna.url()) && /r=5/.test(anna.url()), 'tab mode: ' + anna.url());
		sql(`UPDATE zzzzsys_nunext_page SET open_mode = 'iframe', iframe_height = 'auto' WHERE zzzzsys_nunext_page_id = '${id}'`);
		await open(anna, 'index.php?p=form&f=' + F + '&r=5');
		expect(/p=form/.test(anna.url()) && await anna.locator('iframe.nuPageFrame').count() === 1, 'iframe mode: no wrapper');
		expect(/p=page/.test(await anna.locator('iframe.nuPageFrame').getAttribute('src')), 'iframe src');
		await anna.waitForTimeout(800);
		const fr = anna.frameLocator('iframe.nuPageFrame');
		expect(/page /.test(await fr.locator('#pg').textContent()), 'page in the iframe');
		expect(await fr.locator('body').evaluate(() => window.NuPage && window.NuPage.inFrame), 'NuPage.inFrame');
		await fr.locator('body').evaluate(() => NuPage.toast('e2e toast', 'success'));
		await anna.waitForTimeout(500);
		expect(/e2e toast/.test(await anna.locator('#nuPageToasts').textContent()), 'toast in the parent');
		// the globeadmin as anna (view only): user() = anna, realUser() = the globeadmin, readOnly(), POST refused
		const g = await login(GA[0], GA[1]);
		const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
		await open(g, 'index.php?p=user&id=' + uid);
		expect(await csrfPost(g, 'index.php?p=impersonate', { action: 'start', id: uid, mode: 'view' }) < 400, 'impersonation not started');
		r = await get(g, 'index.php?p=page&f=' + F + '&action=info', J);
		expect(r.j && r.j.user.login === 'sample_anna' && r.j.user.impersonated && r.j.real && r.j.real.globeadmin && r.j.ro, 'impersonated: ' + JSON.stringify([r.j && r.j.user.login, r.j && r.j.real, r.j && r.j.ro]));
		await open(g, 'index.php?p=page&f=' + F);
		r = await post(g, 'index.php?p=page&f=' + F + '&action=save', true, true);
		expect(r.status === 403 && r.j.error === 'read_only', 'POST while viewing as a user: ' + JSON.stringify(r));
		await g.context().close();
		// page API versions (0.8.388): an unsupported $nu->api() stops before the page runs; unknown calls are listed in the builder and the forms list
		expect(await s.ga.evaluate(async (u) => { await fetch(u); return true; }, BASE + 'index.php?p=page&f=' + F), 'page before the API test');
		fs.writeFileSync(path.join(dir, 'index.php'), page.replace("defined('NU_PAGE') || exit;", "defined('NU_PAGE') || exit;\n$nu->api(99);"));
		r = await get(s.ga, 'index.php?p=page&f=' + F + '&action=info', J);
		expect(r.status === 500 && r.j && r.j.error === 'page_api' && /99/.test(r.j.detail || ''), 'unsupported API version: ' + JSON.stringify(r.j));
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=info', J);
		expect(r.status === 500 && r.j.error === 'page_api' && !r.j.detail, 'API error details for a user: ' + JSON.stringify(r.j));
		fs.writeFileSync(path.join(dir, 'index.php'), page.replace("defined('NU_PAGE') || exit;", "defined('NU_PAGE') || exit;\n$nu->api(1);"));
		r = await get(anna, 'index.php?p=page&f=' + F + '&action=info', J);
		expect(r.status === 200 && r.j && r.j.api === 1, '$nu->api(1): ' + r.status);
		fs.writeFileSync(path.join(dir, 'more.php'), "<?php\ndefined('NU_PAGE') || exit;\n$nu->notAMethod();\n");
		fs.writeFileSync(path.join(dir, 'assets', 'app.js'), "NuPage.toast('x'); NuPage.gone();");
		await open(s.ga, 'index.php?p=builder&f=' + id);
		expect(/\b1\b/.test(await s.ga.locator('#pgApi').textContent()) && await s.ga.locator('.pgApiIssue').count() === 2, 'builder API issues: ' + await s.ga.locator('#pgFolder').textContent());
		await open(s.ga, 'index.php?p=forms&q=' + F);
		expect(await s.ga.locator('.nuPageApiWarn.text-danger').count() >= 1, 'forms list: no API warning');
		fs.rmSync(path.join(dir, 'more.php')); fs.rmSync(path.join(dir, 'assets', 'app.js'));
		await open(s.ga, 'index.php?p=builder&f=' + id);
		expect(await s.ga.locator('#pgApiOk').count() === 1, 'builder: clean page not shown as ok');
		// settings keys the page reads and settings.example.json (0.8.391)
		fs.writeFileSync(path.join(dir, 'settings.example.json'), '{"a": {"colour": "blue"}, "extra": 1}');
		await open(s.ga, 'index.php?p=builder&f=' + id);
		const keyRow = s.ga.locator('#pgKeys tr.pgKey[data-key="a.colour"]');
		expect(await keyRow.count() === 1 && /text-bg-success/.test(await keyRow.locator('.pgKeyState').getAttribute('class')), 'key a.colour not listed as set');
		await s.ga.fill('#pg_settings', '{"zz": 1}');
		await s.ga.waitForTimeout(200);
		expect(/zz/.test(await s.ga.locator('#pgKeysUnused').textContent()) && !/text-bg-success/.test(await keyRow.locator('.pgKeyState').getAttribute('class')), 'unused key / not set not shown');
		await s.ga.click('#pgExamplesBtn'); await s.ga.waitForTimeout(200);
		await s.ga.click('#pgExample');
		expect(/"colour": "blue"/.test(await s.ga.inputValue('#pg_settings')) && /extra/.test(await s.ga.locator('#pgKeysUnused').textContent()), 'Insert example');
		fs.rmSync(path.join(dir, 'settings.example.json'));
		// examples (0.8.394): a JSON from the page's keys and built-in samples; unsaved changes switch Open page / Copy AI prompt off
		expect(await s.ga.locator('#pgOpen.disabled').count() === 1 && await s.ga.locator('#pgPrompt[disabled]').count() === 1 && await s.ga.locator('#pgSaveFirst:not(.d-none)').count() === 1, 'unsaved: Open page / Copy AI prompt still active');
		await s.ga.click('#pgExamplesBtn'); await s.ga.waitForTimeout(200);
		await s.ga.click('#pgExampleKeys');
		expect(JSON.parse(await s.ga.inputValue('#pg_settings')).a.colour === null, 'example from the keys: ' + await s.ga.inputValue('#pg_settings'));
		await s.ga.click('#pgExamplesBtn'); await s.ga.waitForTimeout(200);
		await s.ga.locator('.pgSample').nth(1).click();
		expect(JSON.parse(await s.ga.inputValue('#pg_settings')).rows_per_page === 50, 'built-in sample');
		await open(s.ga, 'index.php?p=builder&f=' + id);
		expect(await s.ga.locator('#pgOpen.disabled').count() === 0 && await s.ga.locator('#pgSave').count() === 1, 'fresh builder: Open page off / no Save in the title bar');
		// the form builder of a page form: code rules, settings JSON
		await open(s.ga, 'index.php?p=builder&f=' + id);
		expect(await s.ga.locator('#pgForm').count() === 1 && await s.ga.locator('#pgFileFound').count() === 1, 'page builder');
		await s.ga.fill('#pg_settings', '{"a":');
		await s.ga.click('#pgSave');
		expect(await s.ga.locator('#pg_settings.is-invalid').count() === 1, 'invalid JSON not shown');
		for (const bad of ['../x', '_template', 'a b', 'a.b']) {
			const st = await csrfPost(s.ga, 'index.php?p=builder&f=' + id, { action: 'save', description: 'E2E page', code: bad, status: '1', open_mode: 'tab', iframe_height: 'auto', settings: '{}' });
			expect(st === 200 && sql(`SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`) === F, 'code accepted: ' + bad);
		}
		const st = await csrfPost(s.ga, 'index.php?p=builder&f=' + id, { action: 'save', description: 'E2E page 2', code: F, status: '1', open_mode: 'self', iframe_height: '600px', settings: '{"b":2}' });
		expect((st === 303 || st === 0) && sql(`SELECT CONCAT(open_mode, '|', iframe_height, '|', settings) FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'`) === 'self|600px|{"b":2}', 'page settings not saved: ' + st + ' ' + sql(`SELECT CONCAT(open_mode, '|', iframe_height, '|', settings) FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'`));
		// run objects of another form: an iframe shows the page itself with pf / pr; a button opens a tab page in a new tab
		const tab = sql("SELECT syt_zzzzsys_form_id, zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1").split('\t')[1];
		sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_run_zzzzsys_form_id, sob_run_method, sob_run_type, sob_run_target, sob_run_id)
			VALUES ('${id}i', 'samplecustomer', '${tab}', 'run', 'e2e_pgframe', 'Page frame', 999, 900, 20, 400, 200, '0', '${id}', 'i', 'F', '0', '#RECORD_ID#'),
			('${id}b', 'samplecustomer', '${tab}', 'run', 'e2e_pgbtn', 'Page button', 998, 1120, 20, 120, 30, '0', '${id}', 'b', 'F', '0', '')`);
		sql(`UPDATE zzzzsys_nunext_page SET open_mode = 'tab' WHERE zzzzsys_nunext_page_id = '${id}'`);
		try {
			const cust = s.cust || sql('SELECT MIN(sample_customer_id) FROM sample_customer');
			await open(anna, 'index.php?p=form&f=samplecustomer&r=' + cust);
			const src = await anna.locator('iframe.nuPageFrame').getAttribute('src');
			expect(/p=page/.test(src) && /pf=samplecustomer/.test(src) && new RegExp('pr=' + cust).test(src), 'iframe run object: ' + src);
			expect(await anna.locator('[data-nu-run-form="' + id + '"]').getAttribute('data-nu-run-target') === '2', 'run button of a tab page not in a new tab');
		} finally { sql(`DELETE FROM zzzzsys_object WHERE zzzzsys_object_id IN ('${id}i', '${id}b')`); }
		// cloner: the settings row goes along, the files do not (a hint says so)
		await open(s.ga, 'index.php?p=cloner&f=' + id);
		const cl = await s.ga.evaluate(async ([src, code]) => { const fd = new FormData(); fd.append('_csrf', document.querySelector('input[name=_csrf]').value);
			for (const [k, v] of Object.entries({ action: 'run', source: src, dest_mode: 'new', code, description: 'E2E page clone', objects: '1' })) fd.append(k, v);
			const r = await fetch('index.php?p=cloner', { method: 'POST', body: fd }); return r.text(); }, [id, F + 'c']);
		const cid = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${F}c'`);
		expect(cid !== '' && sql(`SELECT settings FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${cid}'`) === '{"b":2}', 'cloned page form without its settings');
		expect(/custom\/pages\/[^ ]+c\//.test(cl), 'no hint about the files');
		sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${cid}'; DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${cid}'; DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${cid}'`);
		await anna.context().close(); await ben.context().close();
	} finally {
		sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = '${id}'`);
		sql("DELETE FROM zzzzsys_debug WHERE deb_message LIKE 'e2e page log%'");
		fs.rmSync(dir, { recursive: true, force: true });
	}
};

// nuBuilder Forte's nuBrowseRowsPerPageFilter(options) (0.8.604): the browse footer's page size menu gets the given sizes; any size in ?per=
T.rows_per_page_filter = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer');
	expect(await p.locator('.nuLfPerMenu').count() === 1, 'no page size menu');
	await p.evaluate(() => nuBrowseRowsPerPageFilter([25, 50, 100]));
	const items = await p.locator('.nuLfPerMenu a.dropdown-item').allTextContents();
	expect(items.length === 4 && /25/.test(items[1] + items[0]) && /100/.test(items[3]), 'menu sizes: ' + items.join(' | '));
	const href = await p.locator('.nuLfPerMenu a.dropdown-item', { hasText: '50' }).getAttribute('href');
	expect(/per=50/.test(href) && /f=samplecustomer/.test(href), 'link: ' + href);
	await p.evaluate(() => nuBrowseRowsPerPageFilter());
	expect(await p.locator('.nuLfPerMenu a.dropdown-item').count() >= 12, 'Forte default sizes');
	// any size is taken from the address on form lists
	await open(p, 'index.php?p=form&f=samplecustomer&per=3');
	expect(/\b3\b/.test(await p.textContent('.nuLfPerBtn')), 'per=3 not used: ' + await p.textContent('.nuLfPerBtn'));
	expect(p.errors.length === 0, 'JS errors: ' + p.errors.join(' | '));
};

// User Home in design mode (0.8.605, design mode 0.8.670): tabs, objects and Before Edit editable, form settings / browse / delete
// refused; launch forms show their title in the breadcrumb, not "New record"
T.userhome_builder = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'") !== '1') { s.skip = 'no nuuserhome'; return; }
	const meta = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_event', 'zzzzsys_php', 'zzzzsys_nunext_form_history', 'zzzzsys_nunext_form_draft'];
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-uh.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	const desc = sql("SELECT sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'");
	try {
		await open(p, 'index.php?p=form&f=nuuserhome');
		const crumb = (await p.textContent('.breadcrumb .breadcrumb-item.active')).trim();
		expect(crumb === desc && !/new record/i.test(crumb), 'breadcrumb: ' + crumb);
		expect(await p.locator('#nuDesignButton').count() === 1, 'no Design button on User Home');
		await open(p, 'index.php?p=builder&f=nuuserhome');          // the old builder link: design mode
		await p.waitForSelector('#nuDsRail');
		expect(/design=1/.test(p.url()) && await p.locator('#nuDsRailAdd').count() === 1, 'design mode not opened: ' + p.url());
		expect(await p.locator('#nuDsPanel [data-key="sfo_description"]').count() === 0 && await p.locator('#nuDsPanel [data-key="layout"]').count() === 0, 'form settings offered on User Home');
		await p.click('#nuDsRailCode'); await p.waitForSelector('#nuDsWork .CodeMirror');
		expect(await p.locator('.nuDsCodeItem').count() === 1 && await p.getAttribute('.nuDsCodeItem', 'data-code') === 'php:BE', 'code parts on User Home');
		await p.click('#nuDsWorkClose'); await p.waitForTimeout(300);
		const api = (body) => p.evaluate(async (bd) => {
			const csrf = (window.nuNext && window.nuNext.csrf) || document.querySelector('input[name=_csrf]').value;
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(bd) });
			return Object.assign({ status: r.status }, await r.json());
		}, Object.assign({ form_id: 'nuuserhome', live_ok: true }, body));
		let r = await api({ action: 'form.save', fields: { sfo_description: 'Changed', sfo_javascript: 'alert(1)' }, php: { BE: '// e2e BE', BS: '// e2e BS' } });
		expect(r.ok, 'form.save: ' + JSON.stringify(r).slice(0, 200));
		expect(sql("SELECT sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'") === desc, 'form settings changed');
		expect(sql("SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = 'nuuserhome_BE'") === '// e2e BE', 'Before Edit not saved');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id = 'nuuserhome_BS'") === '0', 'Before Save saved');
		r = await api({ action: 'object.save', object_id: '', fields: { sob_all_type: 'run', sob_all_id: 'e2euhrun', sob_all_label: 'E2E run', sob_all_zzzzsys_tab_id: sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'nuuserhome' ORDER BY syt_order LIMIT 1") } });
		expect(r.ok && sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'nuuserhome' AND sob_all_id = 'e2euhrun'") === '1', 'object not added: ' + JSON.stringify(r).slice(0, 200));
		for (const a of ['browse.save', 'form.delete', 'layout.convert']) {
			r = await api({ action: a });
			expect(!r.ok && r.status === 403, a + ' not refused: ' + r.status);
		}
		r = await api({ action: 'draft.start', note: 'e2e' });
		expect(r.ok && r.draft, 'draft not started: ' + JSON.stringify(r).slice(0, 200));
		r = await api({ action: 'draft.discard', draft_id: r.draft.id });
		expect(r.ok, 'draft discard: ' + JSON.stringify(r).slice(0, 200));
		expect(p.errors.length === 0, 'JS errors: ' + p.errors.join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
		fs.rmSync(keep, { force: true });
	}
};

// page form draft and versions (0.8.599): draft in _draft/ for designers only (?nudraft=1, session), publish keeps a version, restore
T.page_draft = async (s) => {
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const p = s.ga;
	const F = 'e2edr' + Date.now().toString(36), dir = path.join(SITE, 'custom', 'pages', F), id = 'e2edr' + Date.now().toString(36).slice(-6);
	const body = (v) => `<?php\ndefined('NU_PAGE') || exit;\necho '<!doctype html><title>d</title><body><p id="v">${v}|' . $nu->h((string)$nu->secret('k')) . '</p></body>';\n`;
	fs.mkdirSync(path.join(dir, 'assets'), { recursive: true });
	fs.mkdirSync(path.join(dir, '_data'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'index.php'), body('live'));
	fs.writeFileSync(path.join(dir, 'secrets.php'), "<?php\ndefined('NU_PAGE') || exit;\nreturn ['k' => 'S1'];\n");
	fs.writeFileSync(path.join(dir, '_data', 'keep.txt'), 'data');
	fs.writeFileSync(path.join(dir, 'assets', 'a.css'), 'a{}');
	sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_status) VALUES ('${id}', 'page', '${F}', 'E2E draft page', '1');
		INSERT INTO zzzzsys_nunext_page (zzzzsys_nunext_page_id, open_mode, iframe_height, settings) VALUES ('${id}', 'tab', 'auto', '{}');
		INSERT INTO zzzzsys_access_form (zzzzsys_access_form_id, slf_zzzzsys_access_id, slf_zzzzsys_form_id) VALUES ('${id}a', 'sample_viewer', '${id}')`);
	const anna = await login('sample_anna', UPW);
	const text = async (pg, q) => { const r = await pg.goto(BASE + 'index.php?p=page&f=' + F + q); return [r.status(), await pg.textContent('body').catch(() => '')]; };
	const ok = async () => { await p.waitForTimeout(400); await p.click('#nuConfirm.show .js-confirm-ok'); await p.waitForLoadState('networkidle'); };
	try {
		await open(p, 'index.php?p=builder&f=' + id);
		expect(await p.isVisible('#pgDraft #pgDraftCreate') && await p.locator('#pgVersionsNone').count() === 1, 'draft card');
		await Promise.all([p.waitForNavigation(), p.click('#pgDraftCreate')]);
		const d = path.join(dir, '_draft');
		expect(fs.existsSync(path.join(d, 'index.php')) && fs.existsSync(path.join(d, 'assets', 'a.css')), 'draft copy');
		expect(!fs.existsSync(path.join(d, 'secrets.php')), 'secrets.php copied');
		expect(fs.readFileSync(path.join(d, '_data', 'keep.txt'), 'utf8') === 'data', 'the draft gets its own copy of _data');
		expect(await p.locator('#pgDraftSame').count() === 1, 'no differences yet');
		fs.writeFileSync(path.join(d, 'index.php'), body('draft'));
		fs.writeFileSync(path.join(d, 'assets', 'b.css'), 'b{}');
		await new Promise(r => setTimeout(r, 1500));
		await open(p, 'index.php?p=builder&f=' + id);
		const diff = await p.textContent('#pgDraftDiff');
		expect(/index\.php/.test(diff) && /assets\/b\.css/.test(diff), 'diff: ' + diff);
		// live for everyone, the draft for the designer after ?nudraft=1 (also on the next request), secrets of the live folder
		let [st, t] = await text(p, '');
		expect(st === 200 && t.includes('live|S1'), 'live: ' + t);
		[st, t] = await text(p, '&nudraft=1');
		expect(t.includes('draft|S1') && await p.locator('#nuPageDraftBar').count() === 1, 'draft view: ' + t);
		[st, t] = await text(p, '');
		expect(t.includes('draft|S1'), 'draft view not remembered');
		expect((await p.goto(BASE + 'index.php?p=pageasset&f=' + F + '&file=assets/b.css')).status() === 200, 'draft asset');
		[st, t] = await text(anna, '&nudraft=1');
		expect(t.includes('live|S1') && await anna.locator('#nuPageDraftBar').count() === 0, 'user sees the draft: ' + t);
		expect((await anna.goto(BASE + 'index.php?p=pageasset&f=' + F + '&file=assets/b.css')).status() === 404, 'user gets a draft asset');
		await text(p, '');
		await p.click('#nuPageDraftBar a'); await p.waitForLoadState('networkidle');
		expect((await p.textContent('body')).includes('live|S1'), 'back to live');
		// publish: the draft is live, the old files a version, _data and secrets stay
		await open(p, 'index.php?p=builder&f=' + id);
		await p.click('#pgDraftPublish'); await ok();
		[st, t] = await text(anna, '');
		expect(t.includes('draft|S1') && !fs.existsSync(d) && fs.existsSync(path.join(dir, 'assets', 'b.css')), 'published: ' + t);
		expect(fs.existsSync(path.join(dir, '_data', 'keep.txt')) && fs.existsSync(path.join(dir, 'secrets.php')), '_data / secrets moved');
		await open(p, 'index.php?p=builder&f=' + id);
		expect(await p.locator('#pgVersions tr').count() === 1 && await p.isVisible('#pgDraftCreate'), 'one version, no draft');
		// restore: the old files are back, the published ones a second version
		await p.click('#pgVersions .pgRestore'); await ok();
		[st, t] = await text(anna, '');
		expect(t.includes('live|S1') && !fs.existsSync(path.join(dir, 'assets', 'b.css')), 'restored: ' + t);
		await open(p, 'index.php?p=builder&f=' + id);
		expect(await p.locator('#pgVersions tr').count() === 2, 'two versions');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE record_id = '${F}' AND event IN ('page_draft_created', 'page_draft_published', 'page_version_restored')`) === '3', 'log');
		// discard
		await Promise.all([p.waitForNavigation(), p.click('#pgDraftCreate')]);
		await p.click('#pgDraftDiscard'); await ok();
		expect(!fs.existsSync(d) && await p.isVisible('#pgDraftCreate'), 'discarded');
	} finally {
		sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'; DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = '${id}'`);
		await anna.context().close();
		fs.rmSync(dir, { recursive: true, force: true });
	}
};

T.page_draft_data = async (s) => {
	// the draft works on its own copy of _data, its own secrets.php wins, $nu->isDraft(); Reset draft data copies live data again
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const p = s.ga;
	const F = 'e2edd' + Date.now().toString(36), dir = path.join(SITE, 'custom', 'pages', F), id = 'e2edd' + Date.now().toString(36).slice(-6);
	const body = `<?php\ndefined('NU_PAGE') || exit;\n$f = $nu->dataDir() . '/n.txt';\n$n = (int)@file_get_contents($f) + 1;\nfile_put_contents($f, (string)$n);\n`
		+ `echo '<!doctype html><title>d</title><body><p id="v">' . ($nu->isDraft() ? 'draft' : 'live') . '|' . $n . '|' . $nu->h((string)$nu->secret('k')) . '</p></body>';\n`;
	fs.mkdirSync(path.join(dir, '_data'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'index.php'), body);
	fs.writeFileSync(path.join(dir, 'secrets.php'), "<?php\ndefined('NU_PAGE') || exit;\nreturn ['k' => 'LIVE'];\n");
	fs.writeFileSync(path.join(dir, '_data', 'n.txt'), '10');
	sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_status) VALUES ('${id}', 'page', '${F}', 'E2E draft data', '1');
		INSERT INTO zzzzsys_nunext_page (zzzzsys_nunext_page_id, open_mode, iframe_height, settings) VALUES ('${id}', 'tab', 'auto', '{}')`);
	const v = async (q) => { await p.goto(BASE + 'index.php?p=page&f=' + F + q); return (await p.textContent('#v').catch(() => '')).trim(); };
	const ok = async () => { await p.waitForTimeout(400); await p.click('#nuConfirm.show .js-confirm-ok'); await p.waitForLoadState('networkidle'); };
	try {
		await open(p, 'index.php?p=builder&f=' + id);
		await Promise.all([p.waitForNavigation(), p.click('#pgDraftCreate')]);
		const d = path.join(dir, '_draft');
		expect(await v('&nudraft=0') === 'live|11|LIVE', 'live counts in the live data');
		const dv = await v('&nudraft=1');
		expect(dv === 'draft|11|LIVE', 'draft starts from a copy and reads the live secrets: ' + dv);
		expect(await v('') === 'draft|12|LIVE' && fs.readFileSync(path.join(dir, '_data', 'n.txt'), 'utf8') === '11', 'live data untouched');
		fs.writeFileSync(path.join(d, 'secrets.php'), "<?php\ndefined('NU_PAGE') || exit;\nreturn ['k' => 'TEST'];\n");
		expect(await v('') === 'draft|13|TEST', 'own secrets.php of the draft wins');
		await open(p, 'index.php?p=builder&f=' + id + '#pgDraft');
		await p.click('#pgDraftDataReset'); await ok();
		expect(fs.readFileSync(path.join(d, '_data', 'n.txt'), 'utf8') === '11', 'reset copies the live data again');
		expect(await v('') === 'draft|12|TEST', 'draft goes on from the fresh copy');
		await open(p, 'index.php?p=builder&f=' + id);
		await p.click('#pgDraftPublish'); await ok();
		expect(fs.readFileSync(path.join(dir, '_data', 'n.txt'), 'utf8') === '11' && !fs.existsSync(d), 'publish keeps the live data');
		expect(await v('') === 'live|12|LIVE', 'live after publish');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'`);
		fs.rmSync(dir, { recursive: true, force: true });
	}
};

T.page_package = async (s) => {
	// the code files of a custom page travel in the application package / folder; the page's data, draft and secrets stay
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const F = 'e2epk' + Date.now().toString(36), dir = path.join(SITE, 'custom', 'pages', F), id = 'e2epk' + Date.now().toString(36).slice(-6);
	const out = fs.mkdtempSync(path.join(os.tmpdir(), 'nunext-e2e-pgpk-'));
	const cron = (a) => execSync('php nucron.php ' + a, {cwd: SITE, encoding: 'utf8'});
	fs.mkdirSync(path.join(dir, 'assets'), { recursive: true });
	fs.mkdirSync(path.join(dir, '_data'), { recursive: true });
	fs.writeFileSync(path.join(dir, 'index.php'), "<?php\ndefined('NU_PAGE') || exit;\necho 'v1';\n");
	fs.writeFileSync(path.join(dir, 'assets', 'app.css'), 'a{}');
	fs.writeFileSync(path.join(dir, 'assets', 'logo.png'), Buffer.from([0x89, 0x50, 0x4e, 0x47, 0, 1, 2, 255]));
	fs.writeFileSync(path.join(dir, 'secrets.php'), "<?php\nreturn ['k' => 'S'];\n");
	fs.writeFileSync(path.join(dir, '_data', 'd.txt'), 'data');
	sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_status) VALUES ('${id}', 'page', '${F}', 'E2E page package', '1');
		INSERT INTO zzzzsys_nunext_page (zzzzsys_nunext_page_id, open_mode, iframe_height, settings) VALUES ('${id}', 'tab', 'auto', '{}')`);
	try {
		cron('app export --dir=' + out);
		const pf = path.join(out, 'pages', F, 'files');
		expect(fs.readFileSync(path.join(pf, 'index.php'), 'utf8').includes('v1') && fs.readFileSync(path.join(pf, 'assets', 'logo.png'))[7] === 255, 'files in the folder, binary as is');
		expect(!fs.existsSync(path.join(pf, 'secrets.php')) && !fs.existsSync(path.join(pf, '_data')), 'secrets / data not exported');
		// change here: the folder's version comes back, a new file goes, the replaced files become a version
		fs.writeFileSync(path.join(dir, 'index.php'), "<?php\ndefined('NU_PAGE') || exit;\necho 'v2';\n");
		fs.writeFileSync(path.join(dir, 'assets', 'extra.js'), '1;');
		const rows = JSON.parse(cron('app import --dir=' + out + ' --json'));
		const r = rows.find((x) => x.key === 'pagefiles|' + F) || {};
		expect(r.state === 'different' && /^0 \D+, 1 \D+, 1 /.test(r.note), 'diff of the page files: ' + JSON.stringify(r));
		cron('app import --dir=' + out + ' --yes');
		expect(fs.readFileSync(path.join(dir, 'index.php'), 'utf8').includes('v1') && !fs.existsSync(path.join(dir, 'assets', 'extra.js')), 'files of the package live');
		expect(fs.readFileSync(path.join(dir, '_data', 'd.txt'), 'utf8') === 'data' && fs.existsSync(path.join(dir, 'secrets.php')), 'data / secrets kept');
		const vers = fs.readdirSync(path.join(dir, '_versions')).filter((n) => /^\d{8}-/.test(n));
		expect(vers.length === 1 && fs.readFileSync(path.join(dir, '_versions', vers[0], 'index.php'), 'utf8').includes('v2'), 'replaced files kept as a version');
		// a new installation: page folder missing → added
		fs.rmSync(dir, { recursive: true, force: true });
		const r2 = JSON.parse(cron('app import --dir=' + out + ' --json')).find((x) => x.key === 'pagefiles|' + F) || {};
		expect(r2.state === 'new', 'missing folder = new: ' + r2.state);
		cron('app import --dir=' + out + ' --yes');
		expect(fs.readFileSync(path.join(dir, 'index.php'), 'utf8').includes('v1'), 'page folder created');
	} finally {
		sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'; DELETE FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = '${id}'`);
		fs.rmSync(dir, { recursive: true, force: true });
		fs.rmSync(out, { recursive: true, force: true });
		for (const b of fs.readdirSync(path.join(SITE, '_backup')).filter((n) => n.startsWith('project-restore-'))) fs.rmSync(path.join(SITE, '_backup', b));
	}
};

// 0.8.518: input buttons and run buttons of forms look the same (class nuFormBtn); an input button keeps the object's height
// 0.8.621: solid in the accent colour with white text; no icons of our own on run buttons (new tab = tooltip); text colour on a background
// of the form's CSS (white, dark on light ones; a colour of the form's CSS stays); one line, cut-off label as tooltip; HTML object headings
T.form_buttons = async (s) => {
	const p = s.ga;
	const oldTarget = sql("SELECT sob_run_target FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'sampleall' AND sob_all_id = 'all_run_form'");
	try {
		sql("UPDATE zzzzsys_object SET sob_run_target = '2' WHERE sob_all_zzzzsys_form_id = 'sampleall' AND sob_all_id = 'all_run_form'");
		await open(p, 'index.php?p=form&f=sampleall&r=sample_all_1');
		await p.locator('.nuTab', { hasText: 'Miscellaneous' }).click(); await p.waitForTimeout(300);
		const st = id => p.evaluate(i => { const e = document.getElementById(i), c = getComputedStyle(e), r = e.getBoundingClientRect();
			return { cls: e.classList.contains('nuFormBtn'), bg: c.backgroundColor, border: c.borderTopColor, radius: c.borderTopLeftRadius, color: c.color, h: Math.round(r.height),
				ws: c.whiteSpace, accent: getComputedStyle(document.documentElement).getPropertyValue('--nu-accent').trim(), ink: e.className }; }, id);
		const a = await st('all_spin_wait'), b = await st('all_spin_run');
		expect(a.cls && b.cls, 'class nuFormBtn missing: ' + JSON.stringify([a, b]));
		expect(a.bg === b.bg && a.border === b.border && a.radius === b.radius && a.color === b.color, 'input and run buttons differ: ' + JSON.stringify([a, b]));
		expect(a.h === 30 && b.h === 30, 'heights (object 30 px): ' + JSON.stringify([a.h, b.h]));
		const hex = a.accent.replace('#', ''), acc = 'rgb(' + [0, 2, 4].map(i => parseInt(hex.substr(i, 2), 16)).join(', ') + ')';
		expect(a.bg === acc && a.color === 'rgb(255, 255, 255)' && a.ws === 'nowrap', 'not solid accent / white / one line: ' + JSON.stringify(a) + ' ' + acc);
		// a background of the form's CSS: light → dark text, dark → white; a text colour of the form's CSS stays
		const ink = await p.evaluate(() => {
			const st = document.createElement('style');
			st.textContent = '#all_spin_wait { background-color: #ffd54a; } #all_spin_auto { background-color: #4cae4c; } #all_spin_run { background-color: #2e7d32; color: rgb(255, 255, 0); }';
			document.head.appendChild(st);
			window.nuNext.fitButtons(document);
			const c = id => { const e = document.getElementById(id); return [e.classList.contains('nuBtnInkDark') ? 'dark' : e.classList.contains('nuBtnInkLight') ? 'light' : '', getComputedStyle(e).color]; };
			const r = { wait: c('all_spin_wait'), auto: c('all_spin_auto'), run: c('all_spin_run') };
			st.remove(); window.nuNext.fitButtons(document);
			r.after = c('all_spin_wait');
			return r;
		});
		expect(ink.wait[0] === 'dark' && ink.wait[1] === 'rgb(30, 41, 59)', 'light background: no dark text ' + JSON.stringify(ink));
		expect(ink.auto[0] === 'light' && ink.auto[1] === 'rgb(255, 255, 255)', 'green background: no white text ' + JSON.stringify(ink));
		expect(ink.run[0] === '' && ink.run[1] === 'rgb(255, 255, 0)', 'own text colour replaced ' + JSON.stringify(ink));
		expect(ink.after[0] === '' && ink.after[1] === 'rgb(255, 255, 255)', 'class kept after the colour went ' + JSON.stringify(ink));
		// a label too long for its button: … and the full label as tooltip; wide again: no tooltip
		const cut = await p.evaluate(async () => {
			const e = document.getElementById('all_spin_wait'), w = e.parentNode.style.width;
			e.parentNode.style.width = '70px'; await new Promise(r => setTimeout(r, 250));
			const t1 = e.getAttribute('title'), ov = getComputedStyle(e).textOverflow;
			e.parentNode.style.width = w; await new Promise(r => setTimeout(r, 250));
			return { t1, ov, t2: e.getAttribute('title'), label: e.querySelector('.nuButtonLabel').textContent.trim() };
		});
		expect(cut.t1 === cut.label && cut.ov === 'ellipsis' && cut.t2 === null, 'cut-off label: ' + JSON.stringify(cut));
		// HTML objects: a heading is bold in the browser's size (13 px text in the pixel layout, as nuBuilder Forte), the sample's fs-6 heading keeps 16 px
		const hd = await p.evaluate(() => {
			const box = document.querySelector('#nuw_all_new_h_spin .nuHtml'), h = document.createElement('h3');
			h.textContent = 'e2e'; box.appendChild(h);
			const c = getComputedStyle(h), r = { w: c.fontWeight, fs: c.fontSize, six: getComputedStyle(box.querySelector('h6')).fontSize };
			h.remove(); return r;
		});
		expect(+hd.w >= 700 && Math.abs(parseFloat(hd.fs) - 15.21) < 0.2 && hd.six === '16px', 'HTML object headings: ' + JSON.stringify(hd));
		// run buttons: no icon of our own; a new tab is said in the tooltip
		await p.locator('.nuTab', { hasText: /^\s*Run\s*$/ }).click(); await p.waitForTimeout(300);
		const run = await p.evaluate(() => ['all_run_form', 'all_run_proc', 'all_run_report'].map(id => { const e = document.getElementById(id);
			return { id, icons: e.querySelectorAll('i.bi').length, hint: e.getAttribute('data-nu-run-hint') || '', title: e.getAttribute('title') || '' }; }));
		expect(run.every(r => r.icons === 0), 'icons on run buttons: ' + JSON.stringify(run));
		expect(run[0].hint !== '' && run[0].title === run[0].hint && run[1].hint === '' && run[1].title === '', 'new-tab hint: ' + JSON.stringify(run));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("UPDATE zzzzsys_object SET sob_run_target = " + (oldTarget === 'NULL' || oldTarget === '' ? "'0'" : "'" + oldTarget + "'") + " WHERE sob_all_zzzzsys_form_id = 'sampleall' AND sob_all_id = 'all_run_form'");
	}
};

// 0.8.520: a record opened anew starts on the first tab; the last tab is kept only for the same record (reload, Save)
T.form_first_tab = async (s) => {
	const p = s.ga;
	const active = () => p.evaluate(() => { const a = document.querySelector('.nuTab.active'); return a ? a.getAttribute('data-nu-tab') : null; });
	await open(p, 'index.php?p=form&f=sampleall');
	await Promise.all([p.waitForNavigation(), p.locator('#nuBrowseTable tbody tr.nuBrowseRow').first().click()]);
	await p.locator('.nuTab', { hasText: 'Charts' }).click(); await p.waitForTimeout(300);
	const tab = await active();
	expect(tab !== '0', 'tab not switched');
	await p.reload(); await p.waitForLoadState('load'); await p.waitForTimeout(300);
	expect(await active() === tab, 'reload: tab not kept');
	await p.goBack(); await p.waitForLoadState('load');
	await open(p, 'index.php?p=form&f=sampleall');
	await Promise.all([p.waitForNavigation(), p.locator('#nuBrowseTable tbody tr.nuBrowseRow').first().click()]);
	await p.waitForTimeout(300);
	expect(await active() === '0', 'opened again from the list: not on the first tab (' + await active() + ')');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.606 / design mode 0.8.670: several objects as in nuBuilder Forte's arrange mode – Ctrl + click, box selection, Ctrl + A, group
// moves with the arrow keys, Shift + arrows = width, Arrange (align, space evenly, same size, order up / down), tab order numbers follow
// Up / Down, a text area follows its handle at once. The form is set to "never published", so every step is written to its rows directly.
T.design_arrange = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const LEFT = { position: { x: 6, y: 6 } };
	const meta = ['zzzzsys_object', 'zzzzsys_form', 'zzzzsys_nunext_form_history', 'zzzzsys_nunext_form_draft'];
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-arrange.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	const q = (id, col) => sql(`SELECT ${col} FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = '${id}'`);
	const act = async (a) => { await p.click('#nuDsPanel [data-ds-arrange="' + a + '"]'); await p.waitForTimeout(1200); };
	const nsel = () => p.locator('.nuBuilderSelected, .nuDesignSelected').count();
	try {
		sql(`UPDATE zzzzsys_object SET sob_all_left = 200 WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_name'; UPDATE zzzzsys_form SET sfo_status = '0' WHERE zzzzsys_form_id = '${F}'; DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(700);
		await p.click('#nuw_cus_code .nuDesignOverlay', LEFT);
		await p.click('#nuw_cus_name .nuDesignOverlay', { ...LEFT, modifiers: ['Control'] }); await p.waitForTimeout(300);
		expect(await nsel() === 2 && await p.locator('#nuDsArrange').count() === 1, 'two objects not selected');
		expect(await p.isDisabled('#nuDsPanel [data-ds-arrange="vertical"]'), 'Vertical with two objects');
		await act('left');
		expect(q('cus_name', 'sob_all_left') === '170' && q('cus_code', 'sob_all_left') === '170', 'Left: ' + q('cus_name', 'sob_all_left'));
		await act('widest');
		expect(q('cus_code', 'sob_all_width') === q('cus_name', 'sob_all_width'), 'Widest: ' + q('cus_code', 'sob_all_width') + ' / ' + q('cus_name', 'sob_all_width'));
		// keys: the group moves; Shift = width
		const top0 = +q('cus_code', 'sob_all_top'), top1 = +q('cus_name', 'sob_all_top'), w0 = +q('cus_name', 'sob_all_width');
		await p.locator('body').press('ArrowDown'); await p.waitForTimeout(1200);
		expect(+q('cus_code', 'sob_all_top') === top0 + 5 && +q('cus_name', 'sob_all_top') === top1 + 5, 'arrow: ' + q('cus_code', 'sob_all_top') + ' / ' + q('cus_name', 'sob_all_top'));
		await p.locator('body').press('Shift+ArrowRight'); await p.waitForTimeout(1200);
		expect(+q('cus_name', 'sob_all_width') === w0 + 5, 'Shift+arrow width: ' + q('cus_name', 'sob_all_width'));
		// order: cus_name up before cus_code; the tab order numbers follow without a reload
		await p.locator('body').press('Escape'); await p.waitForTimeout(200);
		await p.click('#nuw_cus_name .nuDesignOverlay', LEFT); await p.waitForTimeout(300);
		await act('up');
		expect(+q('cus_name', 'sob_all_order') < +q('cus_code', 'sob_all_order'), 'Up: order ' + q('cus_name', 'sob_all_order') + ' / ' + q('cus_code', 'sob_all_order'));
		await p.evaluate(() => window.postMessage({ nuBuilderTabOrder: true }, window.location.origin)); await p.waitForTimeout(400);
		const badge = (id) => p.evaluate((i) => { const b = document.querySelector('#nuw_' + i + ' .nuDesignOrder'); return b ? +b.textContent : 0; }, id);
		expect(await badge('cus_name') === 1 && await badge('cus_code') === 2, 'tab order numbers after Up: ' + await badge('cus_name') + ' / ' + await badge('cus_code'));
		await act('down');
		expect(await badge('cus_name') === 2 && await badge('cus_code') === 1, 'tab order numbers after Down: ' + await badge('cus_name') + ' / ' + await badge('cus_code'));
		await p.evaluate(() => window.postMessage({ nuBuilderTabOrder: false }, window.location.origin));
		// Ctrl + A and Esc; three objects spaced vertically
		await p.click('#nuw_cus_code .nuDesignOverlay', LEFT);
		await p.locator('body').press('Control+a'); await p.waitForTimeout(200);
		expect(await nsel() >= 5, 'Ctrl+A: ' + await nsel());
		await p.locator('body').press('Escape'); await p.waitForTimeout(200);
		expect(await nsel() === 0, 'Esc');
		sql(`UPDATE zzzzsys_object SET sob_all_top = CASE sob_all_id WHEN 'cus_code' THEN 25 WHEN 'cus_name' THEN 61 ELSE 92 END WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id IN ('cus_code', 'cus_name', 'cus_email')`);
		await p.click('#nuDsReload'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(800);
		for (const [i, id] of ['cus_code', 'cus_name', 'cus_email'].entries()) await p.click('#nuw_' + id + ' .nuDesignOverlay', i ? { ...LEFT, modifiers: ['Control'] } : LEFT);
		await p.waitForTimeout(300);
		await act('vertical');
		const t = ['cus_code', 'cus_name', 'cus_email'].map(id => +q(id, 'sob_all_top')), h = ['cus_code', 'cus_name'].map(id => +q(id, 'sob_all_height') || 22);
		const g1 = t[1] - (t[0] + h[0]), g2 = t[2] - (t[1] + h[1]);
		expect(Math.abs(g1 - g2) <= 2, 'Vertical spacing: ' + t.join(',') + ' gaps ' + g1 + '/' + g2);
		// box selection on an empty place
		await p.locator('body').press('Escape'); await p.waitForTimeout(200);
		const r1 = await p.locator('#nuw_cus_code').boundingBox(), r2 = await p.locator('#nuw_cus_name').boundingBox();
		const pr = await p.locator('#nuEditForm .tab-pane.active').boundingBox(), sx = pr.x + pr.width - 8, sy = r1.y - 4;
		await p.mouse.move(sx, sy); await p.mouse.down();
		await p.mouse.move(r1.x + 5, r2.y + r2.height - 2, { steps: 8 }); await p.mouse.up();
		await p.waitForTimeout(200);
		expect(await nsel() >= 2, 'box selection: ' + await nsel());
		// resizing a text area: the control follows at once
		await p.locator('body').press('Escape');
		await p.evaluate(() => window.nuSelectTab(1)); await p.waitForTimeout(300);
		const ta = () => p.evaluate(() => { const w = document.querySelector('#nuw_cus_notes'), c = w.querySelector('textarea'); return {w: w.offsetWidth, h: w.offsetHeight, cw: c.offsetWidth, ch: c.offsetHeight}; });
		await p.click('#nuw_cus_notes .nuDesignOverlay', LEFT);
		await p.locator('#nuw_cus_notes .nuDesignHandle').scrollIntoViewIfNeeded();
		const w1 = +q('cus_notes', 'sob_all_width'), h1 = +q('cus_notes', 'sob_all_height');
		const t0 = await ta(), hb = await p.locator('#nuw_cus_notes .nuDesignHandle').boundingBox();
		await p.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2); await p.mouse.down();
		await p.mouse.move(hb.x + hb.width / 2 + 40, hb.y + hb.height / 2 + 40, { steps: 6 });
		const t1 = await ta();
		await p.mouse.up(); await p.waitForTimeout(1200);
		expect(t1.h - t0.h === 40 && t1.ch - t0.ch === 40 && t1.w - t0.w === 40, 'text area while resizing: ' + JSON.stringify([t0, t1]));
		expect(+q('cus_notes', 'sob_all_height') === h1 + 40 && +q('cus_notes', 'sob_all_width') === w1 + 40, 'text area saved: ' + q('cus_notes', 'sob_all_width') + ' x ' + q('cus_notes', 'sob_all_height'));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
		fs.rmSync(keep, { force: true });
	}
};

// 0.8.1151: pivot of a form list – rows / columns / values, totals, filter, drill, saved pivot, export
// 0.8.1192: object menu → Properties… opens design mode on the same record with the object selected in the panel
T.obj_menu_properties = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") !== '1') { s.skip = 'no sample data'; return; }
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id DESC LIMIT 1');
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + encodeURIComponent(rec));
	await p.click('#label_cus_name', {button: 'right'}); await p.waitForSelector('.nuObjMenu');
	const [np] = await Promise.all([p.context().waitForEvent('page'), p.locator('.nuObjMenu .dropdown-item', {has: p.locator('.bi-sliders')}).first().click()]);
	let rw = null;
	try {
		await np.waitForLoadState('load');
		expect(new URL(np.url()).searchParams.get('r') === rec, 'design mode opens the record that was open: ' + np.url());
		await np.waitForFunction(() => { const x = document.querySelector('#nuDsPanel'); return x && /cus_name/.test(x.innerText.slice(0, 120)) && document.querySelector('#nuw_cus_name.nuBuilderSelected'); }, null, {timeout: 12000});
		// 0.8.1194: Global code that rewrites the address bar on every page (a nuBuilder Forte style permalink ?f=&r=&h= with pushState)
		// dropped design mode's parameters – the same address opened with such code must still select the object
		rw = await p.context().newPage();
		await rw.addInitScript(() => {
			if (!/[?&]design=1/.test(location.search)) return;
			var q = new URLSearchParams(location.search);
			history.pushState('', document.title, '?f=' + q.get('f') + '&r=' + q.get('r') + '&h=nuhome');   // before the page's scripts run
		});
		await rw.goto(np.url());
		await rw.waitForFunction(() => document.querySelector('#nuw_cus_name.nuBuilderSelected') && /cus_name/.test(document.querySelector('#nuDsPanel').innerText.slice(0, 120)), null, {timeout: 12000});
		expect(/[?&]h=nuhome/.test(rw.url()) && !/dsobj/.test(rw.url()), 'the address was rewritten by the page: ' + rw.url());
	} finally { await np.close(); if (rw) await rw.close(); }
};

// 0.8.1196: nuAttachSaveButtonTo as nuBuilder Forte – from the object's CSS top / height (also while it is hidden), one holder for
// repeated calls after form code moved the field ($('#x').css({top})), $.fn.cssNumber of older Forte forms
T.attach_save_hidden = async (s) => {
	const p = s.ga;
	const id = sql("SELECT sample_all_id FROM sample_all LIMIT 1");
	if (!id) { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=form&f=sampleall&layout=absolute&r=' + encodeURIComponent(id)); await p.waitForSelector('#nuSaveButton');
	const r = await p.evaluate(() => {
		const f = document.getElementById('all_textarea'), w = document.getElementById('nuw_all_textarea');
		const pane = w.closest('.tab-pane'); const link = document.querySelector('[data-bs-target="#' + pane.id + '"], [href="#' + pane.id + '"]'); if (link) link.click();
		const out = {cssNumber: typeof $.fn.cssNumber === 'function'};
		nuHide('all_textarea');
		const top0 = parseFloat(w.style.top), h = parseFloat(f.style.height) || f.offsetHeight;
		nuAttachSaveButtonTo('all_textarea', 0, 20, 23, 110);
		const hold = () => document.querySelector('.nuAttachedSave');
		out.hidden = {top: parseFloat(hold().style.top), want: top0 + h + 35};
		$('#all_textarea').css({top: top0 + 40});                                  // form code moves the field, shows it, attaches again
		nuShow('all_textarea');
		nuAttachSaveButtonTo('all_textarea', 0, 20, 23, 110);
		out.moved = {top: parseFloat(hold().style.top), want: top0 + 40 + h + 35, holders: document.querySelectorAll('.nuAttachedSave').length,
			readTop: $('#all_textarea').cssNumber('top'), wantRead: top0 + 40};
		return out;
	});
	expect(r.cssNumber, '$.fn.cssNumber exists');
	expect(Math.abs(r.hidden.top - r.hidden.want) < 1, 'hidden target: Save below its CSS place ' + JSON.stringify(r.hidden));
	expect(Math.abs(r.moved.top - r.moved.want) < 1 && r.moved.holders === 1 && r.moved.readTop === r.moved.wantRead, 'moved target, second call: one holder at the new place ' + JSON.stringify(r.moved));
};

// 0.8.1223: Design ▾ → Code on User Home opened nothing (the pane data lacked items)
T.edit_code_pane_userhome = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'") !== '1') { s.skip = 'no User Home'; return; }
	await open(p, 'index.php?p=form&f=nuuserhome&r=-1');
	await p.click('#nuDesignMore'); await p.click('#nuEditCodeEdit');
	await p.waitForSelector('#nuEcpPane .nuEcpItem.active', {timeout: 5000});
	const keys = await p.$$eval('#nuEcpPane .nuEcpItem', (x) => x.map((e) => e.getAttribute('data-key')));
	expect(keys.join() === 'php:BE', 'User Home parts: ' + keys.join());
	await p.keyboard.press('Escape');
};

// 0.8.1224: a record shown again opens on its remembered tab without the first tab flashing up first
T.form_tab_restore_noflash = async (s) => {
	const p = await s.ga.context().newPage();
	try {
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") !== '1') { s.skip = 'no sample data'; return; }
	const rec = sql('SELECT sample_customer_id FROM sample_customer LIMIT 1');
	await open(p, 'index.php?p=form&f=samplecustomer&r=' + encodeURIComponent(rec));
	await p.click('#nuTab1'); await p.waitForTimeout(300);
	// every frame while the page loads: was the first pane shown (active and not hidden)?
	await p.addInitScript(() => {
		window.__nuFlash = 0;
		const look = () => { const a = document.getElementById('nuPane0'), h = document.getElementById('nuRECORD');
			if (a && h && a.classList.contains('active') && getComputedStyle(h).visibility !== 'hidden' && a.getBoundingClientRect().height > 0) window.__nuFlash++;
			if (!window.__nuDone) requestAnimationFrame(look); };
		requestAnimationFrame(look);
		setTimeout(() => { window.__nuDone = true; }, 4000);
	});
	await p.reload(); await p.waitForTimeout(1500);
	const r = await p.evaluate(() => ({ flash: window.__nuFlash, active: document.querySelector('.nuTab.active') && document.querySelector('.nuTab.active').id, pending: document.documentElement.classList.contains('nuFormTabPending') }));
	expect(r.active === 'nuTab1' && !r.pending, 'tab not restored: ' + JSON.stringify(r));
	expect(r.flash === 0, 'first tab shown before the restore: ' + JSON.stringify(r));
	// a launch form (no record id in the address) – User Home with its two tabs
	if (sql("SELECT COUNT(*) FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'nuuserhome'") === '2') {
		await p.goto(BASE + 'index.php?p=form&f=nuuserhome'); await p.waitForSelector('#nuTab1');
		await p.click('#nuTab1'); await p.waitForTimeout(300);
		await p.reload(); await p.waitForTimeout(1500);
		const u = await p.evaluate(() => ({ flash: window.__nuFlash, active: document.querySelector('.nuTab.active') && document.querySelector('.nuTab.active').id }));
		expect(u.active === 'nuTab1' && u.flash === 0, 'User Home: ' + JSON.stringify(u));
	}
	} finally { await p.close(); }
};

T.edit_code_pane = async (s) => {
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'") !== '1') { s.skip = 'no sample data'; return; }
	const css0 = sql("SELECT IFNULL(sfo_style, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'");
	const ad0 = sql("SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id = 'sampleorder_AD'");
	const rec = sql('SELECT sample_order_id FROM sample_order LIMIT 1');
	try {
		await open(p, 'index.php?p=form&f=sampleorder&r=' + encodeURIComponent(rec));
		await p.click('#nuDesignMore'); await p.click('#nuEditCodeEdit');
		await p.waitForSelector('#nuEcpPane .nuEcpItem.active');
		const items = await p.$$eval('#nuEcpPane .nuEcpItem', (x) => x.map((e) => [e.getAttribute('data-key'), e.getAttribute('data-grp'), e.classList.contains('nuEcpHas')]));
		const keys = items.map((x) => x[0]);
		expect(keys.join() === 'sfo_javascript,sfo_edit_javascript,sfo_style,php:BE,php:BS,php:AS,php:BD,php:AD,sfo_browse_javascript,php:BB,sfo_browse_sql', 'the parts of the Code tab: ' + keys.join());
		expect(items.filter((x) => x[1] === 'list').length === 3 && items.find((x) => x[0] === 'sfo_browse_sql')[2], 'list parts grouped at the end, dot on parts with code');
		// 0.8.1199: each group a card with the count of parts holding code; the list's card is dashed
		const cards = await p.$$eval('#nuEcpPane .nuEcpCard', (x) => x.map((c) => [c.getAttribute('data-grp'), c.querySelectorAll('.nuEcpItem').length, c.querySelector('.nuEcpCount').textContent, getComputedStyle(c).borderTopStyle]));
		expect(cards.map((c) => c[0]).join() === 'js,style,php,list' && cards.every((c) => /^\d+ \/ \d+$/.test(c[2]) && +c[2].split(' / ')[1] === c[1]), 'one card per group with "x / y": ' + JSON.stringify(cards));
		expect(cards[3][3] === 'dashed' && cards[0][3] === 'solid', 'list card dashed');
		expect(+cards[3][2].split(' / ')[0] === items.filter((x) => x[1] === 'list' && x[2]).length, 'count = parts with code');
		await p.keyboard.press('Escape'); await p.waitForTimeout(200);
		expect(!(await p.$('#nuEcpPane')), 'Esc closes the pane');
		await p.locator('#nuEditForm input').first().click(); await p.keyboard.press('Escape');
		await p.locator('body').click({position: {x: 5, y: 600}});
		await p.keyboard.press('Control+Shift+B'); await p.waitForSelector('#nuEcpPane');
		await p.click('#nuEcpPane .nuEcpItem[data-key="sfo_style"]'); await p.waitForSelector('#nuEcpPane .nuBsqBody[data-code="sfo_style"] .cm-editor');
		await p.click('#nuEcpPane .nuBsqBody[data-code="sfo_style"] .cm-content'); await p.keyboard.press('Control+End'); await p.keyboard.type('\n/* e2e ecp */');
		await p.click('#nuEcpPane .nuEcpItem[data-key="php:AD"]'); await p.waitForSelector('#nuEcpPane .nuBsqBody[data-code="php:AD"] .cm-editor');
		await p.click('#nuEcpPane .nuBsqBody[data-code="php:AD"] .cm-content'); await p.keyboard.type('// e2e ecp ad');
		expect(await p.$eval('#nuEcpPane .nuEcpItem[data-key="php:AD"]', (e) => e.classList.contains('nuEcpHas')), 'the dot follows the typing');
		await Promise.all([p.waitForNavigation(), p.keyboard.press('Control+s')]);
		expect(sql("SELECT sfo_style FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'").includes('/* e2e ecp */'), 'CSS saved');
		expect(sql("SELECT IFNULL(sph_php, '') FROM zzzzsys_php WHERE zzzzsys_php_id = 'sampleorder_AD'").includes('// e2e ecp ad'), 'PHP After Delete saved');
	} finally {
		execSync(`mysql ${DB}`, {input: "UPDATE zzzzsys_form SET sfo_style = " + (css0 === '' ? 'NULL' : "'" + css0.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'") + " WHERE zzzzsys_form_id = 'sampleorder';"
			+ (ad0 === '0' ? "DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'sampleorder_AD';" : "UPDATE zzzzsys_php SET sph_php = REPLACE(sph_php, '// e2e ecp ad', '') WHERE zzzzsys_php_id = 'sampleorder_AD';")});
	}
};

T.button_settings = async (s) => {
	// object menu → Button settings… (0.8.1163): one dialog for run target, open in, events, label, access; one save
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_object WHERE zzzzsys_object_id = 'sample_all_o45'") !== '1') { s.skip = 'no sample data'; return; }
	const was = sql("SELECT CONCAT_WS('|', sob_run_target, sob_all_label) FROM zzzzsys_object WHERE zzzzsys_object_id = 'sample_all_o45'").split('|');
	const rec = sql('SELECT sample_all_id FROM sample_all LIMIT 1');
	try {
		await open(p, 'index.php?p=form&f=sampleall&r=' + encodeURIComponent(rec));
		await p.evaluate(() => { const b = document.querySelector('#all_run_form'); const t = b.closest('.tab-pane'); const l = document.querySelector('[data-bs-target="#' + t.id + '"], [href="#' + t.id + '"]'); if (l) l.click(); });
		await p.waitForTimeout(300);
		// right-click menu entry
		await p.click('#all_run_form', {button: 'right'}); await p.waitForSelector('#nuOmSettings');
		await p.click('#nuOmSettings'); await p.waitForSelector('#nuOmSetDlg.show'); await p.waitForTimeout(300);
		await p.click('#nuOmSetTab_run');
		expect(await p.$eval('#nuOmSetType', (x) => x.value) === 'F' && await p.$eval('#nuOmSetTarget', (x) => x.value) === 'samplecustomer', 'run type and target shown');
		// 0.8.1191: labels in a column, fields fitted (no field over the whole width)
		const lay = await p.evaluate(() => {
			const w = (sel) => document.querySelector(sel).getBoundingClientRect();
			const dlg = w('#nuOmSetDlg .modal-dialog'), lb = w('#nuOmSetPane_run .nuOmSetRow .form-label'), ty = w('#nuOmSetType'), rec = w('#nuOmSetRecord');
			return {dlg: dlg.width, sameRow: Math.abs(lb.top + lb.height / 2 - (ty.top + ty.height / 2)) < 8, labelLeft: lb.right <= ty.left, type: ty.width, rec: rec.width};
		});
		expect(lay.dlg <= 642 && lay.sameRow && lay.labelLeft && lay.type <= 222 && lay.rec <= 152, 'run tab: label column + fitted widths ' + JSON.stringify(lay));
		await p.selectOption('#nuOmSetOpen', '2');
		await p.click('#nuOmSetTab_events'); await p.click('#nuOmSetEvAdd');
		await p.locator('.nuOmSetEvJs').last().fill("console.log('e2e settings');");
		await p.click('#nuOmSetTab_look'); await p.fill('#nuOmSetLabel', 'E2E settings');
		await Promise.all([p.waitForNavigation(), p.click('#nuOmSetSave')]);
		expect(sql("SELECT CONCAT_WS('|', sob_run_target, sob_all_label) FROM zzzzsys_object WHERE zzzzsys_object_id = 'sample_all_o45'") === '2|E2E settings', 'run target + label saved');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_event WHERE sev_zzzzsys_object_id = 'sample_all_o45' AND sev_javascript LIKE '%e2e settings%'") === '1', 'event saved');
		// Alt+click opens it too
		await p.evaluate(() => { const b = document.querySelector('#all_run_form'); const t = b.closest('.tab-pane'); const l = document.querySelector('[data-bs-target="#' + t.id + '"], [href="#' + t.id + '"]'); if (l) l.click(); });
		await p.waitForTimeout(300);
		await p.click('#all_run_form', {modifiers: ['Alt']}); await p.waitForSelector('#nuOmSetDlg.show');
		expect(await p.locator('.nuOmSetEv').count() === 1, 'event listed again');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, {input: `UPDATE zzzzsys_object SET sob_run_target = '${was[0]}', sob_all_label = '${was[1].replace(/'/g, "''")}' WHERE zzzzsys_object_id = 'sample_all_o45';
			DELETE FROM zzzzsys_event WHERE sev_zzzzsys_object_id = 'sample_all_o45' AND sev_javascript LIKE '%e2e settings%'`});
	}
};

// 0.8.1165: design mode – the list's calendar view (Browse list workspace) and Add → Calendar on the edit page, both in a draft
T.design_calendar = async (s) => {
	const p = s.ga;
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec || sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'") !== '1') { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-cal.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	try {
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id IN ('sampleorder', 'samplecustomer')");
		await open(p, 'index.php?p=form&f=sampleorder&design=1&dswork=list'); await p.waitForSelector('[data-key=cal_start]');
		await p.selectOption('[data-key=cal_start]', 'sample_order_b2'); await p.waitForTimeout(1500);
		await open(p, 'index.php?p=form&f=sampleorder&design=1&dswork=list'); await p.waitForSelector('[data-key=cal_group]');
		expect(await p.inputValue('[data-key=cal_start]') === 'sample_order_b2', 'start column kept');
		await p.selectOption('[data-key=cal_group]', 'sample_order_b4'); await p.waitForTimeout(1500);
		const raw = (q) => execSync('mysql -N -r ' + DB, {input: q}).toString().trim();
		const snap = raw("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleorder' AND status = 'open' ORDER BY created_at DESC LIMIT 1");
		const cal = (JSON.parse(JSON.parse(snap).form.sfo_json || '{}').nunext || {}).calendar || {};
		expect(cal.start === 'sample_order_b2' && cal.group === 'sample_order_b4', 'calendar in the draft: ' + JSON.stringify(cal));
		// the preview of the draft shows the Calendar button on the list
		await open(p, 'index.php?p=form&f=sampleorder&design=1');
		expect(await p.locator('#nuCalendarOpen').count() === 0, 'no Calendar button while designing');
		// Add → Calendar on the customer page: an HTML object with nunext.calendar
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(500);
		await p.click('#nuDsRailAdd'); await p.click('#nuDsAddCalendar'); await p.waitForTimeout(1800);
		const s2 = JSON.parse(raw("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer' AND status = 'open' ORDER BY created_at DESC LIMIT 1"));
		const o = s2.objects.find((x) => /^calendar/.test(x.sob_all_id));
		expect(o && o.sob_all_type === 'html' && /"calendar"/.test(o.sob_all_json || ''), 'calendar object in the draft');
		await p.waitForSelector('[data-key=cal_src]', {timeout: 5000});
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.design_phone_menu = async (s) => {
	// 0.8.1001 (mockup C): on phones Design, its ▾ entries, the (i) form info and the layout switch move into the ⋮ menu; New shows its icon only
	const id = sql('SELECT sample_customer_id FROM sample_customer ORDER BY 1 LIMIT 1');
	if (!id) { s.skip = 'no sample data'; return; }
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	const vis = (sel) => p.evaluate((q) => { const e = document.querySelector(q); return !!e && !!(e.offsetWidth || e.offsetHeight); }, sel);
	const opts = () => p.evaluate(() => [...document.querySelectorAll('.nuOptionsList [data-nu-opt]')].filter(e => e.offsetParent).map(e => e.getAttribute('data-nu-opt')));
	try {
		await p.goto(BASE + 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
		expect(!(await vis('#nuDesignButton')) && !(await vis('#nuFormInfoBtn')) && !(await vis('#nuLayoutSeg')), 'designer tools hidden in the phone toolbar');
		const top = await p.evaluate(() => [...document.querySelectorAll('#nuActionHolder > *')].filter(e => e.offsetParent).map(e => e.getBoundingClientRect().top));
		expect(new Set(top.map(Math.round)).size === 1, 'one toolbar row on a phone: ' + JSON.stringify(top));
		const nb = await p.evaluate(() => { const b = document.getElementById('nuNewButton'); return b ? {fs: getComputedStyle(b).fontSize, label: b.getAttribute('aria-label')} : null; });
		if (nb) expect(nb.fs === '0px' && !!nb.label, 'New: icon only with a name: ' + JSON.stringify(nb));
		await p.click('#nuOptions'); await p.waitForTimeout(300);
		const o = await opts();
		expect(o.join() === 'design,edit_code,info,layout', 'record ⋮ items (0.8.1197: + Code): ' + o.join());
		const lay = await p.evaluate(() => [...document.querySelectorAll('.nuOptLayout > .btn')].map(e => parseFloat(getComputedStyle(e).fontSize)));
		expect(lay.length === 3 && lay.every(f => f > 10), 'layout switch labels readable (0.8.1146): ' + lay.join());
		const href = await p.getAttribute('.nuOptionsList [data-nu-opt=design]', 'href');
		expect(/design=1/.test(href || ''), 'Design link: ' + href);
		await p.click('.nuOptionsList [data-nu-opt=info]'); await p.waitForTimeout(500);
		expect(await vis('#nuMessageDiv .nuFormInfoPhone .nuFormInfoList'), 'form info as a dialogue');
		// 0.8.1145: everything inside the dialogue (copy buttons, Copy all)
		const out = await p.evaluate(() => { const m = document.querySelector('#nuMessageDiv .nuFormInfoPhone'), r = m.closest('.nuMessage').getBoundingClientRect();
			return [...m.querySelectorAll('dt, dd, .btn')].filter(e => e.offsetParent && e.getBoundingClientRect().right > r.right + 1).map(e => e.className || e.tagName); });
		expect(out.length === 0, 'form info content wider than the dialogue: ' + out.join(', '));
		await p.click('#nuMessageDiv .nuMessageClose');

		await p.goto(BASE + 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuOptions');
		await p.click('#nuOptions'); await p.waitForTimeout(300);
		const l = await opts();
		expect(l.join() === 'design,browse_sql,browse_php,browse_js,columns,info', 'list ⋮ items: ' + l.join());
		await p.click('.nuOptionsList [data-nu-opt=browse_sql]'); await p.waitForTimeout(800);
		expect(await vis('#nuBsqPane'), 'Edit browse SQL… opens the pane');
		await p.setViewportSize({ width: 1200, height: 800 }); await p.goto(BASE + 'index.php?p=form&f=samplecustomer&r=' + id); await p.waitForSelector('#nuSaveButton');
		await p.click('#nuOptions'); await p.waitForTimeout(300);
		expect(await vis('#nuDesignButton') && await vis('#nuFormInfoBtn') && (await opts()).length === 0, 'desktop: toolbar as before, no phone items');
	} finally { await ctx.close(); }
};

// 0.8.638: long tap on a tab lists its sections (cards); a tap scrolls to the card – user form, phone
T.tab_sections = async (s) => {
	const ctx = await s.ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=user&id=sample_ben');
		await p.evaluate(async () => {
			const el = document.getElementById('userTabGeneralBtn'), r = el.getBoundingClientRect();
			const t = new Touch({ identifier: 1, target: el, clientX: r.left + 10, clientY: r.top + 10 });
			el.dispatchEvent(new TouchEvent('touchstart', { touches: [t], targetTouches: [t], changedTouches: [t], bubbles: true, cancelable: true }));
			await new Promise(ok => setTimeout(ok, 700));
			el.dispatchEvent(new TouchEvent('touchend', { touches: [], targetTouches: [], changedTouches: [t], bubbles: true, cancelable: true }));
		});
		const items = await p.locator('.nuTabSections .dropdown-item').allTextContents();
		expect(items.length >= 3 && items.some(x => /Sign-in|Anmeld|Login/.test(x)), 'sections: ' + JSON.stringify(items));
		const before = await p.evaluate(() => window.scrollY);
		await p.locator('.nuTabSections .dropdown-item').nth(items.length - 1).click();
		await p.waitForTimeout(900);
		expect(await p.evaluate(() => window.scrollY) > before + 200 && await p.locator('.nuTabSections').count() === 0, 'no jump to the last section');
		// 0.8.639: the ▾ in the tab opens the list with a short tap; tabs with one card have none
		expect(await p.locator('#userTabGeneralBtn .nuTabSecArrow').count() === 1 && await p.locator('#userTabPermBtn .nuTabSecArrow').count() === 0, 'arrow in the tabs');
		await p.click('#userTabGeneralBtn .nuTabSecArrow');
		expect(await p.locator('.nuTabSections .dropdown-item').count() === items.length, 'arrow does not open the list');
		await p.keyboard.press('Escape');
		await p.click('#userTabPermBtn'); await p.waitForTimeout(300);
		await p.dispatchEvent('#userTabPermBtn', 'contextmenu');
		expect(await p.locator('.nuTabSections').count() === 0, 'menu for a tab with one section');
	} finally { await ctx.close(); }
};

T.cloner_defaults = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=cloner&id=new');
	expect(await p.isChecked('#clDump'), '"Only create SQL" not ticked by default');
	// SQL for MySQL or SQL Server (0.8.794): the header names it, SQL Server gets N'…' literals and no REPLACE INTO
	expect(await p.inputValue('#clTarget') === 'mysql' && await p.locator('#clReplaceWrap').isVisible(), 'target = this database (MySQL), REPLACE offered');
	await p.selectOption('#clSource', 'samplecustomer').catch(async () => { await p.evaluate(() => { const x = document.getElementById('clSource'); x.value = 'samplecustomer'; x.dispatchEvent(new Event('change', { bubbles: true })); }); });
	await p.waitForTimeout(800);
	await p.selectOption('#clTarget', 'sqlsrv'); await p.waitForTimeout(200);
	expect(await p.locator('#clReplaceWrap').isHidden(), 'SQL Server: no REPLACE INTO');
	await Promise.all([p.waitForNavigation(), p.click('#clRun')]);
	let dumpSql = await p.inputValue('#clSql');
	expect(/-- For: Microsoft SQL Server/.test(dumpSql) && /VALUES \(N'/.test(dumpSql) && !/REPLACE INTO/.test(dumpSql), 'SQL Server dump: ' + dumpSql.slice(0, 300));
	await p.selectOption('#clTarget', 'mysql');
	await Promise.all([p.waitForNavigation(), p.click('#clRun')]);
	dumpSql = await p.inputValue('#clSql');
	expect(/-- For: MySQL \/ MariaDB/.test(dumpSql) && !/VALUES \(N'/.test(dumpSql), 'MySQL dump');
	await open(p, 'index.php?p=home');
	expect(await p.locator('.nuHomeTile[data-key="cloner"], [data-nu-tile="cloner"], a.nuTile[href$="p=cloner"]').count() > 0 || await p.locator('a[href$="p=cloner"]').count() > 0, 'no Form cloner on the start page');
};

T.attach_save_button = async (s) => {
	const p = s.ga;
	const dump = path.join(__dirname, 'fixtures', 'attach_save_clone.sql');
	if (!fs.existsSync(dump)) { s.skip = 'fixture missing'; return; }
	try {
		sql("CREATE TABLE IF NOT EXISTS e2e_contract_capture (e2e_contract_capture_id VARCHAR(25) PRIMARY KEY, vertrags_nr VARCHAR(50)); REPLACE INTO e2e_contract_capture VALUES ('r1','5002010451')");
		sql("DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '6ae159ab6d51101'; DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '6ae159ab6d51101'; DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '6ae159ab6d51101'; DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = '6ae159ab6d51101_AS'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '6ae159ab6d51101'");
		execSync('mysql ' + DB, { input: fs.readFileSync(dump) });
		await open(p, 'index.php?p=form&f=6ae159ab6d51101&r=r1');
		await p.waitForTimeout(500);
		const r = await p.evaluate(() => { const b = document.getElementById('nuSaveButton').getBoundingClientRect(), c = document.querySelector('.nuAbsoluteCanvas').getBoundingClientRect(),
			f = document.getElementById('nuw_vertrags_nr').getBoundingClientRect(); return { bt: b.top, bb: b.bottom, bw: b.width, bh: b.height, cb: c.bottom, fb: f.bottom, fl: f.left, bl: b.left }; });
		expect(Math.round(r.bw) === 80 && Math.round(r.bh) === 24, 'size of the attached Save: ' + JSON.stringify(r));
		expect(r.bt - r.fb >= 13 && r.bt - r.fb <= 19 && Math.abs(r.bl - r.fl) < 1.5, 'place below the field: ' + JSON.stringify(r));
		expect(r.cb >= r.bb + 5, 'the tab is not high enough – Save cut off: ' + JSON.stringify(r));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '6ae159ab6d51101'; DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '6ae159ab6d51101'; DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '6ae159ab6d51101'; DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = '6ae159ab6d51101_AS'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '6ae159ab6d51101'; DROP TABLE IF EXISTS e2e_contract_capture");
	}
};

T.validation_rules = async (s) => {
	const p = s.ga, C = 'sample_cus_02';
	const keep = sql("SELECT CONCAT_WS('|', cus_credit, cus_name, IFNULL(cus_phone, '')) FROM sample_customer WHERE sample_customer_id = '" + C + "'");
	const oldJson = sql("SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	vrSet('samplecustomer', [
		{id: 'r1', on: true, name: 'Credit', kind: 'error', scope: '', msg: 'Credit too high: #cus_credit#', field: 'cus_credit', levels: [], perm: '',
			check: {op: 'and', items: [{f: 'cus_credit', o: 'le', v: '30000'}]}},
		{id: 'r2', on: true, name: 'Phone', kind: 'warn', scope: '', msg: 'No phone number', field: 'cus_phone', levels: [], perm: '',
			when: {op: 'and', items: [{f: 'cus_name', o: 'contains', v: 'e2ewarn'}]}, check: {op: 'and', items: [{f: 'cus_phone', o: 'nempty'}]}},
		{id: 'r3', on: true, name: 'Other level', kind: 'error', scope: '', msg: 'never for the globeadmin', field: '', levels: ['zz_no_level'], perm: '',
			check: {op: 'and', items: [{f: 'cus_name', o: 'eq', v: 'never'}]}},
		{id: 'r4', on: false, name: 'Off', kind: 'error', scope: '', msg: 'switched off', field: '', levels: [], perm: '', check: {op: 'and', items: [{f: 'cus_name', o: 'eq', v: 'never'}]}}]);
	try {
		// error: the browser stops at once, the field is marked live
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + C);
		expect(await p.evaluate(() => !!window.nuNextRules && window.nuNext.rules.rules.length === 2), 'rules of the user not on the page (off / other level must be left out)');
		await vrType(p, 'cus_credit', '40000'); await p.waitForTimeout(500);
		expect(await p.locator('#cus_credit.is-invalid').count() === 1, 'field not marked while typing');
		await p.click('#nuSaveButton'); await p.waitForTimeout(600);
		expect(/Credit too high: 40000/.test(await p.textContent('#nuMessageDiv')), 'no message before saving');
		// the server checks too (browser check switched off)
		await p.evaluate(() => { window.nuNextRules = null; });
		await p.click('#nuSaveButton'); await p.waitForTimeout(1200);
		expect(/Credit too high: 40000/.test(await p.textContent('#nuMessageDiv')), 'server did not refuse');
		expect(sql("SELECT cus_credit FROM sample_customer WHERE sample_customer_id = '" + C + "'") === keep.split('|')[0], 'saved despite the error rule');
		// warning: Save anyway?
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + C);
		await vrType(p, 'cus_credit', '20000'); await setField(p, '#cus_name', 'Maple e2ewarn'); await setField(p, '#cus_phone', '');
		await p.click('#nuSaveButton'); await p.waitForSelector('#nuConfirm.show');
		expect(/No phone number/.test(await p.textContent('#nuConfirm.show')), 'warning text missing');
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm.show .js-confirm-ok')]); await p.waitForLoadState('networkidle');
		expect(sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + C + "'") === 'Maple e2ewarn', 'not saved after Save anyway');
		// sub-forms: every row + count of rows (sampleorder)
		const oJson = sql("SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'");
		const base = JSON.parse(oJson || '{}').nunext || {};
		const oset = (rules) => sql("UPDATE zzzzsys_form SET sfo_json = '" + JSON.stringify({nunext: Object.assign({}, base, {rules: rules})}) + "' WHERE zzzzsys_form_id = 'sampleorder'");
		try {
			oset([{id: 'q1', on: true, name: 'Qty', kind: 'error', scope: 'sample_items', msg: 'Quantity must be 1 to 50', field: '', levels: [], perm: '',
				check: {op: 'and', items: [{f: 'ori_qty', o: 'between', v: ['1', '50']}]}},
			{id: 'q2', on: true, name: 'Rows', kind: 'error', scope: '', msg: 'At most 2 items', field: '', levels: [], perm: '', check: {op: 'and', items: [{f: 'count:sample_items', o: 'le', v: '2'}]}}]);
			const n = sql("SELECT COUNT(*) FROM sample_order_item WHERE ori_sample_order_id = 'sample_ord_1'");
			const ordCust = sql("SELECT ord_sample_customer_id FROM sample_order WHERE sample_order_id = 'sample_ord_1'");
			const res = await p.evaluate(async (oc) => {
				const b = {action: 'save', form_id: 'sampleorder', record_id: 'sample_ord_1', values: {ord_sample_customer_id: oc}, edited: [], subforms: {sample_items: {rows: [{pk: 'x1', values: {ori_qty: '0'}, edited: []}, {pk: 'x2', values: {ori_qty: '3'}, edited: []}, {pk: 'x3', values: {ori_qty: '3'}, edited: []}]}}};
				const r = await fetch('index.php?p=formapi', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': (window.nuNext || {}).csrf || ''}, body: JSON.stringify(b)});
				return r.json();
			}, ordCust);
			const msgs = (res.errors || []).map(e => e.message).join(' | ');
			expect(!res.ok && /Row 1: Quantity must be 1 to 50/.test(msgs) && /At most 2 items/.test(msgs) && !/Row 2/.test(msgs), 'sub-form rules: ' + JSON.stringify(res));
			expect(sql("SELECT COUNT(*) FROM sample_order_item WHERE ori_sample_order_id = 'sample_ord_1'") === n, 'rows changed');
		} finally { sql("UPDATE zzzzsys_form SET sfo_json = " + (oJson ? "'" + oJson.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'" : 'NULL') + " WHERE zzzzsys_form_id = 'sampleorder'"); }
	} finally {
		const [cr, nm, ph] = keep.split('|');
		sql("UPDATE sample_customer SET cus_credit = '" + cr + "', cus_name = '" + nm.replace(/'/g, "''") + "', cus_phone = " + (ph ? "'" + ph + "'" : 'NULL') + " WHERE sample_customer_id = '" + C + "'");
		sql("UPDATE zzzzsys_form SET sfo_json = " + (oldJson ? "'" + oldJson.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'" : 'NULL') + " WHERE zzzzsys_form_id = 'samplecustomer'");
	}
};

// 0.8.812: the rules editor of design mode – expression → builder, Test rule, saved into the draft
T.validation_rules_design = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = '" + F + "' AND status = 'open'");
	const oldJson = sql("SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'");
	try {
		await open(p, 'index.php?p=form&f=' + F + '&r=sample_cus_01&design=1'); await p.waitForSelector('#nuDsRailRules'); await p.waitForTimeout(400);
		await p.click('#nuDsRailRules'); await p.waitForSelector('#nuDsRuleAdd'); await p.click('#nuDsRuleAdd'); await p.waitForSelector('#nuDsRuleDlg.show');
		// 0.8.816: a gap is shown on top of the dialogue, in sight, with the field marked
		await p.fill('#nuDsRuleName', 'Credit limit');
		await p.click('#nuDsRuleSave'); await p.waitForTimeout(300);
		const errBox = await p.evaluate(() => { const e = document.getElementById('nuDsRuleErr'), b = e.closest('.modal-body').getBoundingClientRect(), r = e.getBoundingClientRect();
			return {shown: !e.hidden, inView: r.top >= b.top - 20 && r.bottom <= b.bottom, text: e.textContent, marked: document.getElementById('nuDsRuleMsg').classList.contains('is-invalid')}; });
		expect(errBox.shown && errBox.inView && errBox.marked && /Credit limit/.test(errBox.text), 'missing message: ' + JSON.stringify(errBox));
		await p.fill('#nuDsRuleMsg', 'Too much: #cus_credit#');
		await p.click('label[for=nuDsRuleCheckMexpr]');
		await p.fill('#nuDsRuleCheckExpr', 'cus_credit <= 30000 AND cus_email CONTAINS \'@\'');
		await p.click('label[for=nuDsRuleCheckMbuilder]'); await p.waitForTimeout(800);
		expect(await p.locator('#nuDsRuleCheckRows .nuDsCondRow').count() === 2, 'expression not turned into two builder rows');
		await p.click('#nuDsRuleTest'); await p.waitForSelector('#nuDsRuleTestRes [data-vr-res]');
		expect(/Too much: 50000/.test(await p.textContent('#nuDsRuleTestRes')), 'Test rule: ' + await p.textContent('#nuDsRuleTestRes'));
		// a broken expression is refused with the reason
		await p.click('label[for=nuDsRuleCheckMexpr]'); await p.fill('#nuDsRuleCheckExpr', "cus_credit <= 'x");
		await p.click('#nuDsRuleSave'); await p.waitForTimeout(800);
		expect(/quote/i.test(await p.textContent('#nuDsRuleErr')), 'broken expression not refused: ' + await p.textContent('#nuDsRuleErr'));
		await p.fill('#nuDsRuleCheckExpr', 'cus_credit <= 30000');
		await p.click('#nuDsRuleSave'); await p.waitForSelector('#nuDsRules [data-vr]');
		const snap = sql("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = '" + F + "' AND status = 'open'");
		expect(/Credit limit/.test(snap) && /cus_credit <= 30000/.test(snap), 'rule not in the draft');
		expect(sql("SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'") === oldJson, 'the live form was changed');
		await p.click('#nuDsRuleTestAll'); await p.waitForSelector('#nuDsRuleTestOut [data-vr-res="fail"]');
	} finally {
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = '" + F + "' AND status = 'open'");
	}
};

// 0.8.814: hash variables in rules – a form property set in the browser (nuSetProperty) is checked on the server and live; builder value with #NAME#
T.validation_rules_hash = async (s) => {
	const p = s.ga, C = 'sample_cus_02';
	const oldJson = sql("SELECT IFNULL(sfo_json, '') FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'");
	const keep = sql("SELECT cus_credit FROM sample_customer WHERE sample_customer_id = '" + C + "'");
	vrSet('samplecustomer', [{id: 'h1', on: true, name: 'Max', kind: 'error', scope: '', msg: 'Above #E2E_MAX# for #USER_ID#', field: 'cus_credit', levels: [], perm: '',
		check: {op: 'and', items: [{f: 'cus_credit', o: 'le', v: {h: 'E2E_MAX'}}]}}]);
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + C);
		await p.evaluate(() => nuSetProperty('E2E_MAX', '30000'));
		await vrType(p, 'cus_credit', '40000'); await p.waitForTimeout(500);
		expect(await p.locator('#cus_credit.is-invalid').count() === 1, 'live check does not use the hash cookie');
		await p.evaluate(() => { window.nuNextRules = null; });
		await p.click('#nuSaveButton'); await p.waitForTimeout(1200);
		expect(/Above 30000 for globeadmin/.test(await p.textContent('#nuMessageDiv')), 'server: ' + await p.textContent('#nuMessageDiv'));
		expect(sql("SELECT cus_credit FROM sample_customer WHERE sample_customer_id = '" + C + "'") === keep, 'saved despite the rule');
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + C);
		await p.evaluate(() => nuSetProperty('E2E_MAX', '50000'));
		await vrType(p, 'cus_credit', '40000');
		await Promise.all([p.waitForNavigation(), p.click('#nuSaveButton')]); await p.waitForLoadState('networkidle');
		expect(sql("SELECT cus_credit FROM sample_customer WHERE sample_customer_id = '" + C + "'") === '40000.00' || (SQLITE && Number(sql("SELECT cus_credit FROM sample_customer WHERE sample_customer_id = '" + C + "'")) === 40000), 'not saved below the hash value');
		// the builder: a hash variable typed into the value field (Tom Select, free text)
		vrSet('samplecustomer', null);
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = 'samplecustomer' AND status = 'open'");
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + C + '&design=1'); await p.waitForSelector('#nuDsRailRules'); await p.waitForTimeout(300);
		await p.click('#nuDsRailRules'); await p.waitForSelector('#nuDsRuleAdd'); await p.click('#nuDsRuleAdd'); await p.waitForSelector('#nuDsRuleDlg.show');
		await p.fill('#nuDsRuleMsg', 'x');
		await p.click('#nuDsRuleCheckAddRow');
		await p.selectOption('#nuDsRuleCheckRows [data-vr="f"]', 'cus_credit'); await p.selectOption('#nuDsRuleCheckRows [data-vr="o"]', 'le');
		await p.locator('#nuDsRuleCheckRows .nuDsCondRow').first().locator('.ts-wrapper').nth(1).click(); await p.keyboard.type('#USER_'); await p.waitForTimeout(300);
		expect(await p.locator('.ts-dropdown .option', {hasText: '#USER_ID#'}).count() > 0, 'hash variables not offered');
		await p.keyboard.type('MAXCR#'); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
		await p.click('label[for=nuDsRuleCheckMexpr]'); await p.waitForTimeout(200);
		expect(await p.inputValue('#nuDsRuleCheckExpr') === 'cus_credit <= #USER_MAXCR#', 'expression: ' + await p.inputValue('#nuDsRuleCheckExpr'));
		await p.click('label[for=nuDsRuleCheckMbuilder]'); await p.waitForTimeout(800);
		expect(/#USER_MAXCR#/.test(await p.textContent('#nuDsRuleCheckRows')), 'expression → builder lost the hash variable');
		// 0.8.815: a hash variable on the left – #E2E_C# = 123
		await p.click('#nuDsRuleCheckAddRow');
		const row2 = p.locator('#nuDsRuleCheckRows .nuDsCondRow').nth(1);
		await row2.locator('.ts-wrapper').first().click(); await p.waitForTimeout(300); await p.keyboard.type('#E2E_C#', {delay: 30}); await p.waitForTimeout(200); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
		await row2.locator('[data-vr="o"]').selectOption('eq');
		await row2.locator('.ts-wrapper').nth(1).click(); await p.waitForTimeout(300); await p.keyboard.type('123', {delay: 30}); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
		await p.click('label[for=nuDsRuleCheckMexpr]'); await p.waitForTimeout(200);
		expect(/#E2E_C# = 123/.test(await p.inputValue('#nuDsRuleCheckExpr')), 'left hash: ' + await p.inputValue('#nuDsRuleCheckExpr'));
		await p.click('#nuDsRuleSave'); await p.waitForSelector('#nuDsFly [data-vr]');
		expect(await p.textContent('#nuDsRailRulesN') === '1', 'rail count');
		// server + browser: #E2E_C# on the left
		vrSet('samplecustomer', [{id: 'h2', on: true, name: 'Cookie', kind: 'error', scope: '', msg: 'Cookie is #E2E_C#', field: '', levels: [], perm: '',
			check: {op: 'and', items: [{f: '#E2E_C#', o: 'ne', v: '1'}]}}]);
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = 'samplecustomer' AND status = 'open'");
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + C);
		await p.evaluate(() => { nuSetProperty('E2E_C', '1'); window.nuNextRules = null; });
		await vrType(p, 'cus_credit', '41000');
		await p.click('#nuSaveButton'); await p.waitForTimeout(1200);
		expect(/Cookie is 1/.test(await p.textContent('#nuMessageDiv')), 'left hash on the server: ' + await p.textContent('#nuMessageDiv'));
	} finally {
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = 'samplecustomer' AND status = 'open'");
		sql("UPDATE sample_customer SET cus_credit = '" + keep + "' WHERE sample_customer_id = '" + C + "'");
		sql("UPDATE zzzzsys_form SET sfo_json = " + (oldJson ? "'" + oldJson.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'" : 'NULL') + " WHERE zzzzsys_form_id = 'samplecustomer'");
	}
};

// 0.8.817: from the workspace (Code) straight to a flyout of the icon bar – the workspace closes
T.design_work_to_fly = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	await open(p, 'index.php?p=form&f=' + F + '&r=sample_cus_01&design=1'); await p.waitForSelector('#nuDsRailCode');
	await p.click('#nuDsRailCode'); await p.waitForSelector('#nuDsWork:not([hidden])');
	await p.click('#nuDsRailRules'); await p.waitForTimeout(400);
	expect(await p.locator('#nuDsWork').isHidden() && await p.locator('#nuDsFly #nuDsRuleAdd').isVisible(), 'rules flyout not reachable from Code');
	await p.click('#nuDsRailHistory'); await p.waitForSelector('#nuDsWork:not([hidden])');
	await p.click('#nuDsRailStructure'); await p.waitForTimeout(400);
	expect(await p.locator('#nuDsWork').isHidden() && await p.locator('#nuDsFly').isVisible(), 'structure not reachable from History');
	await p.click('#nuDsRailCode'); await p.waitForSelector('#nuDsWork:not([hidden])');
	await p.click('#nuDsRailForm'); await p.waitForTimeout(300);
	expect(await p.locator('#nuDsWork').isHidden(), 'form settings button does not close the workspace');
};

// 0.8.820: design mode – icon bar order, the List flyout (settings, code, columns) and nuBuilder Forte's list settings on the list
T.design_list_fly = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const old = sql("SELECT CONCAT_WS('|', IFNULL(sfo_browse_row_height, ''), IFNULL(sfo_browse_title_multiline, ''), IFNULL(sfo_browse_autoresize_columns, ''), IFNULL(sfo_browse_target, '')) FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'");
	sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = '" + F + "' AND status = 'open'");
	try {
		await open(p, 'index.php?p=form&f=' + F + '&r=sample_cus_01&design=1'); await p.waitForSelector('#nuDsRailList');
		const order = await p.$$eval('#nuDsRail .nuDsRailBtn', b => b.map(x => x.id).join(','));
		expect(order === 'nuDsRailForm,nuDsRailStructure,nuDsRailAdd,nuDsRailCode,nuDsRailRules,nuDsRailHistory,nuDsRailList,nuDsRailKeys', 'rail order: ' + order);
		expect(await p.locator('#nuDsBrowseSql').count() === 0, 'list settings still in the form panel');
		await p.click('#nuDsRailList'); await p.waitForSelector('#nuDsWork:not([hidden]) #nuDsListFly'); await p.mouse.move(700, 600); await p.waitForTimeout(300);
		expect(await p.locator('#nuDsWorkTab_list.active').count() === 1, 'Browse list not a workspace tab');
		await p.fill('#nuDsListFly [data-key="sfo_browse_row_height"]', '30'); await p.press('#nuDsListFly [data-key="sfo_browse_row_height"]', 'Tab'); await p.waitForTimeout(900);
		await p.selectOption('#nuDsListFly [data-key="sfo_browse_target"]', '2'); await p.waitForTimeout(900);
		await p.click('#nuDsListFly [data-key="sfo_browse_title_multiline"]'); await p.waitForTimeout(900);
		const t0 = p.locator('#nuDsListCols [data-lc="title"]').first();
		await t0.fill('E2E list title'); await t0.press('Tab'); await p.waitForTimeout(1200);
		const snap = sql("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = '" + F + "' AND status = 'open'");
		expect(/"sfo_browse_row_height":"?30/.test(snap) && /"sfo_browse_target":"2"/.test(snap) && /"sfo_browse_title_multiline":"1"/.test(snap) && /E2E list title/.test(snap), 'not in the draft');
		expect(await p.locator('#nuDsWork #nuDsListFly').isVisible() && await p.inputValue('#nuDsListCols [data-lc="title"] >> nth=0') === 'E2E list title', 'workspace not kept / redrawn after a change');
		// 0.8.823: drag the first column (by its number) below the third
		const titles = () => p.$$eval('#nuDsListCols [data-lc="title"]', x => x.map(i => i.value));
		const before = await titles();
		const third = p.locator('#nuDsListCols tr[data-col] >> nth=2');
		const bb = await third.boundingBox();
		await p.locator('#nuDsListCols [data-grip] >> nth=0').dragTo(third, {targetPosition: {x: 30, y: bb.height - 4}}); await p.waitForTimeout(1500);
		const after = await titles();
		expect(after[2] === before[0] && after[0] === before[1] && after[1] === before[2], 'drag and drop: ' + before.slice(0, 3) + ' → ' + after.slice(0, 3));
		await p.click('#nuDsListSql'); await p.waitForSelector('#nuDsWork:not([hidden])');
		expect(/SELECT/i.test(await p.locator('#nuDsWork .CodeMirror').first().textContent()), 'Browse SQL button');
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = '" + F + "' AND status = 'open'");
		// the list itself: row height, multi-line titles, fill the width, open in a new tab
		sql("UPDATE zzzzsys_form SET sfo_browse_row_height = 44, sfo_browse_title_multiline = '1', sfo_browse_autoresize_columns = '1', sfo_browse_target = '2' WHERE zzzzsys_form_id = '" + F + "'");
		await open(p, 'index.php?p=form&f=' + F);
		const look = await p.evaluate(() => { const tb = document.getElementById('nuBrowseTable'), tr = tb.querySelector('tr.nuBrowseRow');
			return {cls: tb.className, h: Math.round(tr.getBoundingClientRect().height), w: Math.round(tb.getBoundingClientRect().width), pw: Math.round(tb.parentElement.getBoundingClientRect().width)}; });
		expect(/nuBrowseMultiline/.test(look.cls) && /nuBrowseAutoFit/.test(look.cls) && look.h >= 43 && look.w >= look.pw - 2, 'list look: ' + JSON.stringify(look));
		const [tab] = await Promise.all([p.context().waitForEvent('page'), p.click('tr.nuBrowseRow >> nth=0')]);
		await tab.waitForURL(/[?&]r=/, {timeout: 10000}).catch(() => {}); expect(/[?&]r=/.test(tab.url()), 'new tab: ' + tab.url()); await tab.close();
		sql("UPDATE zzzzsys_form SET sfo_browse_target = '3' WHERE zzzzsys_form_id = '" + F + "'");
		await open(p, 'index.php?p=form&f=' + F);
		await p.click('tr.nuBrowseRow >> nth=0'); await p.waitForSelector('#nuPopupModal.show');
	} finally {
		const [h, m, a, tg] = old.split('|');
		const v = (x) => x === '' ? 'NULL' : "'" + x + "'";
		sql("UPDATE zzzzsys_form SET sfo_browse_row_height = " + v(h) + ", sfo_browse_title_multiline = " + v(m) + ", sfo_browse_autoresize_columns = " + v(a) + ", sfo_browse_target = " + v(tg) + " WHERE zzzzsys_form_id = '" + F + "'");
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = '" + F + "' AND status = 'open'");
	}
};

// 0.8.623: change the database column of an object – info, data check (too long, not a number), change (logged, needs the tick when
// values change), the globeadmin's own ALTER TABLE (DROP refused), the dialog in the builder
T.column_change = async (s) => {
	const p = s.ga;
	const meta = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_event', 'zzzzsys_browse', 'zzzzsys_php', 'zzzzsys_access_form'];
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-colchg.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	try {
		await open(p, 'index.php?p=builder&f=samplecustomer');
		const api = (body) => p.evaluate(async (bd) => {
			const csrf = (window.nuNext && window.nuNext.csrf) || document.querySelector('input[name=_csrf]').value;
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(bd) });
			return Object.assign({ status: r.status }, await r.json());
		}, body);
		let r = await api({ action: 'form.create', description: 'E2E colchg', code: 'e2e_colchg', type: 'browseedit', layout: 'absolute', table_mode: 'new', table: 'e2e_colchg', primary_key: '', pk_type: 'id',
			audit: false, nulog: false, columns: [{ name: 'cc_name', type: 'text', length: '50', required: false, label: 'Name' }, { name: 'cc_num', type: 'text', length: '20', required: false, label: 'Num' }], levels: [] });
		expect(r.ok, 'form.create: ' + (r.error || ''));
		const F = r.form_id;
		sql("INSERT INTO e2e_colchg (e2e_colchg_id, cc_name, cc_num) VALUES ('a1', 'short', '12'), ('a2', 'a name longer than ten', 'x7'), ('a3', NULL, '')");
		const call = (action, extra) => api(Object.assign({ action, form_id: F, column: 'cc_name' }, extra));
		r = await call('column.info');
		expect(r.ok && r.info.sql_type === 'varchar(50)' && r.info.type === 'text' && r.info.length === '50' && r.info.rows === 3 && r.free_sql === true, 'info: ' + JSON.stringify(r).slice(0, 300));
		r = await call('column.check', { type: 'text', length: '10' });
		const tl = r.ok && r.plan.checks.find(c => c.key === 'too_long');
		expect(tl && tl.n === 1 && tl.arg === '10' && tl.samples[0] === 'a name longer than ten' && /MODIFY cc_name VARCHAR\(10\) NULL/.test(r.plan.sql), 'check too long: ' + JSON.stringify(r.plan || r));
		r = await call('column.check', { type: 'int', length: '' , column: 'cc_num' });
		const nn = r.ok && r.plan.checks.find(c => c.key === 'not_number');
		expect(nn && nn.n === 1 && nn.samples[0] === 'x7', 'check not a number: ' + JSON.stringify(r.plan || r));
		r = await call('column.change', { type: 'text', length: '10' });
		expect(!r.ok && r.code === 'accept' && r.status === 409, 'loss without the tick: ' + JSON.stringify(r).slice(0, 200));
		r = await call('column.change', { type: 'text', length: '100' });
		expect(r.ok && r.info.sql_type === 'varchar(100)', 'widen: ' + JSON.stringify(r).slice(0, 200));
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'column_changed' AND object = 'e2e_colchg' AND record_id = 'cc_name'") === '1', 'not logged');
		r = await call('column.change', { type: 'text', length: '100' });
		expect(!r.ok, 'same type accepted');
		r = await call('column.check', { sql: 'ALTER TABLE e2e_colchg DROP COLUMN cc_name' });
		expect(!r.ok, 'DROP in own SQL accepted');
		r = await call('column.change', { sql: 'ALTER TABLE `e2e_colchg` MODIFY `cc_name` VARCHAR(120) NULL' });
		expect(r.ok && r.info.sql_type === 'varchar(120)', 'own SQL: ' + JSON.stringify(r).slice(0, 200));
		// the dialog (design mode, 0.8.670 – before: the builder's ⋮ menu)
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(600);
		await p.click('#nuDsRailStructure'); await p.waitForTimeout(300);
		await p.click('#nuDsTree .nuDsTreeRow[data-id="cc_num"]'); await p.waitForTimeout(500); await p.click("#nuDsFly .btn-close");
		await p.click('#nuDsColChange'); await p.waitForSelector('#nuDsColCurType'); await p.waitForTimeout(500);
		expect(await p.textContent('#nuDsColCurType') === 'varchar(20)', 'dialog: current type');
		await p.fill('#nuDsColLen', '40'); await p.waitForSelector('#nuDsColOk', { timeout: 5000 });
		expect(await p.locator('#nuDsColRun').isEnabled(), 'dialog: Run disabled without loss');
		await p.selectOption('#nuDsColType', 'int'); await p.waitForSelector('#nuDsColLoss', { timeout: 5000 });
		expect(!(await p.locator('#nuDsColRun').isEnabled()), 'dialog: Run enabled without the tick');
		await p.selectOption('#nuDsColType', 'text'); await p.fill('#nuDsColLen', '40'); await p.waitForSelector('#nuDsColOk', { timeout: 5000 });
		await p.click('#nuDsColRun'); await p.waitForTimeout(1200);
		expect(sql("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e2e_colchg' AND COLUMN_NAME = 'cc_num'") === '40', 'dialog: not changed');
		expect(await p.locator('#nuDsColDlg').count() === 0, 'dialog not closed');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
		sql("DROP TABLE IF EXISTS e2e_colchg; DELETE FROM zzzzsys_nunext_log WHERE event = 'column_changed' AND object = 'e2e_colchg'");
	}
};

// forms for everyone (0.8.784): switch "Everyone" in a form's Access levels dialog writes a row per level (as nuBuilder Forte reads them),
// the forms list shows the badge and filter, a new access level gets the form, admin-equivalent forms refuse it, gaps show in the
// Compatibility check (FE), switching off keeps the rows
T.cloner_sources = async (s) => {             // 0.8.811: only forms that can be cloned; nuBuilder Forte's own forms on request
	const p = s.ga;
	const opts = () => p.locator('#clSource option').evaluateAll(a => a.map(o => o.value).filter(Boolean));
	const fake = 'e2eclbuiltin';
	sql("INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_code, sfo_type, sfo_description) VALUES ('" + fake + "', 'nufiles', 'page', 'E2E built-in page')");
	try {
		await open(p, 'index.php?p=cloner&id=new');
		let o = await opts();
		expect(o.includes('samplecustomer'), 'application form missing');
		expect(!o.some(x => x.startsWith('nu') && x !== 'nuuserhome'), 'nuBuilder Forte forms listed by default: ' + o.filter(x => x.startsWith('nu')).slice(0, 3).join(','));
		// 0.8.1223: User Home is listed with the application's forms
		if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'nuuserhome'") === '1') expect(o.includes('nuuserhome'), 'User Home missing from the sources');
		expect(!o.includes(fake), 'built-in page form listed');
		await Promise.all([p.waitForNavigation(), p.click('#clSystemToggle')]);
		o = await opts();
		expect(o.includes('nuform') && !o.includes('nunextlog') && !o.includes('nunextadminrole'), 'with ?system=1: ' + o.filter(x => x.startsWith('nu')).length);
		const info = await p.evaluate(async (id) => (await (await fetch('index.php?p=cloner&info=' + id)).json()), fake);
		expect(info.error && /built into/.test(info.error), 'info of the built-in page: ' + JSON.stringify(info));
	} finally { sql("DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '" + fake + "'"); }
};

T.design_code_sql_nocheck = async (s) => {
	// 0.8.911: Browse SQL after a JavaScript part in the one CodeMirror of the Code workspace – no JavaScript syntax check on SQL
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(700);
	await p.click('#nuDsRailCode'); await p.waitForSelector('#nuDsWork .CodeMirror'); await p.waitForTimeout(300);
	await p.click('.nuDsCodeItem[data-code="sfo_javascript"]'); await p.waitForTimeout(300);
	await p.evaluate(() => { const cm = document.querySelector('#nuDsWork .CodeMirror').CodeMirror; cm.setValue('var a = ;'); });
	await p.waitForTimeout(1500);
	expect(await p.locator('#nuDsWork .nuChkMark.nuChk-error').count() > 0, 'JS error marked');
	await p.click('.nuDsCodeItem[data-code="sfo_browse_sql"]'); await p.waitForTimeout(300);
	await p.evaluate(() => { const cm = document.querySelector('#nuDsWork .CodeMirror').CodeMirror; cm.setValue("SELECT *\nFROM (\n  SELECT\n    ht.*,\n    c.cus_name AS c_name\n  FROM sample_customer ht LEFT JOIN sample_customer c ON c.sample_customer_id = ht.sample_customer_id\n  WHERE '#USER_NAME#' <> ''\n) T\nORDER BY 1 DESC"); });
	await p.waitForTimeout(1800);
	expect(await p.locator('#nuDsWork .nuChkMark').count() === 0, 'no marks on SQL: ' + await p.locator('#nuDsWork .nuChkMark').count());
	expect(await p.locator('#nuDsWork .nuChkLine').count() === 0, 'no error lines on SQL');
	expect(await p.evaluate(() => (document.querySelector('#nuDsWork .CodeMirror').CodeMirror.nuChk.problems || []).length) === 0, 'no problems kept');
	// back to JavaScript: checked again
	await p.click('.nuDsCodeItem[data-code="sfo_javascript"]'); await p.waitForTimeout(1500);
	expect(await p.locator('#nuDsWork .nuChkMark.nuChk-error').count() > 0, 'JS checked again');
	p.once('dialog', (d) => d.accept().catch(() => {}));
	await open(p, 'index.php?p=home');   // the edits were never saved
};

T.obj_menu_more = async (s) => {                      // 0.8.1082: the object menu also on words, images and sub-form column titles (as nuBuilder Forte)
	const p = s.ga;
	const r = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleall'");
	if (!r) { console.log('     (sampleall missing – skipped)'); return; }
	await open(p, 'index.php?p=form&f=sampleall&r=-1');
	for (const id of ['all_word', 'all_image']) {
		await p.evaluate((i) => { const e = document.getElementById(i); e.dispatchEvent(new MouseEvent('contextmenu', {bubbles: true, cancelable: true, clientX: 200, clientY: 200})); }, id);
		const head = await p.textContent('.nuObjMenu .nuOmHead').catch(() => '');
		expect(head.includes(id), id + ': menu with the object: ' + head);
		await p.keyboard.press('Escape');
	}
	await open(p, 'index.php?p=form&f=sampleorder&r=-1');
	const th = p.locator('#nuEditForm th.nuSubformTitle[data-nu-obj]:visible').first();
	await th.click({button: 'right'});
	expect(await p.locator('.nuSfColMenu').count() === 1, 'sub-form column title: menu');
	const [pop] = await Promise.all([p.context().waitForEvent('page'), p.locator('.nuSfColMenu button').nth(1).click()]);
	expect(/design=1/.test(pop.url()) && /dsobj=/.test(pop.url()) && !pop.url().includes('f=sampleorder&'), 'Properties opens the sub-form\'s form in design mode: ' + pop.url());
	await pop.close();
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.tab_context_menu = async (s) => {
	// 0.8.919: right-click on a tab of the edit form (outside design mode, globeadmin) opens the tab menu: rename, visibility, move, delete
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-tabmenu.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_tab zzzzsys_object zzzzsys_nunext_form_history > ' + keep);
	const tabs = () => sql(`SELECT GROUP_CONCAT(CONCAT(syt_title, ':', COALESCE(syt_access, '0')) ORDER BY syt_order SEPARATOR '|') FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}'`);
	try {
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec); await p.waitForSelector('#nuTabHolder .nuTab');
		const first = p.locator('#nuTabHolder .nuTab').first();
		await first.click({button: 'right'}); await p.waitForSelector('.nuTabMenu');
		const txt = await p.textContent('.nuTabMenu');
		expect(/Rename tab/.test(txt) && /Move right/.test(txt) && !/Move left/.test(txt) && /Delete tab/.test(txt) && /design mode/.test(txt), 'menu entries: ' + txt);
		// rename
		const dl = p.listeners('dialog');
		p.removeAllListeners('dialog'); p.on('dialog', (d) => d.accept(d.type() === 'prompt' ? 'E2E tab' : undefined));
		await Promise.all([p.waitForNavigation(), p.locator('.nuTabMenu button', {hasText: 'Rename tab'}).click()]);
		p.removeAllListeners('dialog'); dl.forEach((f) => p.on('dialog', f));
		await p.waitForSelector('#nuTabHolder .nuTab');
		expect(tabs().split('|')[0].startsWith('E2E tab:'), 'renamed: ' + tabs());
		// move right
		await p.locator('#nuTabHolder .nuTab').first().click({button: 'right'}); await p.waitForSelector('.nuTabMenu');
		await Promise.all([p.waitForNavigation(), p.locator('.nuTabMenu button', {hasText: 'Move right'}).click()]);
		expect(tabs().split('|')[1].startsWith('E2E tab:'), 'moved right: ' + tabs());
		// hide for users (submenu)
		await p.locator('#nuTabHolder .nuTab', {hasText: 'E2E tab'}).click({button: 'right'}); await p.waitForSelector('.nuTabMenu');
		await p.locator('.nuTabMenu .nuOmHasSub').first().click();
		await Promise.all([p.waitForNavigation(), p.locator('.nuTabMenu .nuOmSub button', {hasText: 'Hide for users'}).click()]);
		expect(tabs().split('|')[1] === 'E2E tab:3', 'hidden for users: ' + tabs());
		// Ctrl + right-click: the browser's menu (no own menu)
		await p.locator('#nuTabHolder .nuTab').first().click({button: 'right', modifiers: ['Control']}); await p.waitForTimeout(300);
		expect(await p.locator('.nuTabMenu').count() === 0, 'Ctrl + right-click keeps the browser menu');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.contentbox_height = async (s) => {
	// 0.8.920: a content box covers what nuBuilder Forte's covers – the 22 px title bar at its top plus a frame of its full height below
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	if (!tab) { s.skip = 'no sample data'; return; }
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access)
		VALUES ('e2ecbh', 'samplecustomer', '${tab}', 'contentbox', 'e2e_cbh', 'E2E box', 998, 400, 600, 300, 202, '0')`);
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
		const r = await p.evaluate(() => {
			const w = document.getElementById('nuw_e2e_cbh').getBoundingClientRect(), b = document.getElementById('e2e_cbh').getBoundingClientRect();
			const c = document.querySelector('.nuAbsoluteCanvas').getBoundingClientRect(), t = document.getElementById('label_e2e_cbh').getBoundingClientRect();
			return {frameTop: Math.round(b.top - w.top), frameBottom: Math.round(b.bottom - w.top), titleTop: Math.round(t.top - w.top), titleBottom: Math.round(t.bottom - w.top), canvasRoom: Math.round(c.bottom - b.bottom)};
		});
		expect(r.frameBottom === 224 && r.frameTop === 11 && r.titleTop >= -3 && r.titleBottom <= 26 && r.canvasRoom >= 0, 'content box geometry: ' + JSON.stringify(r));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2ecbh'");
	}
};

T.print_wide_form = async (s) => {
	// 0.8.987: printing a form wider than the paper – the tab pane is no scroll box in print, so the browser shrinks the whole form to
	// the page (its right part was cut off before)
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	if (!tab) { s.skip = 'no sample data'; return; }
	sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_html_code)
		VALUES ('e2epw', 'samplecustomer', '${tab}', 'html', 'e2e_pw', 'E2E', 998, 20, 1900, 200, 40, '0', 'RIGHT_EDGE')`);
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
		await p.emulateMedia({media: 'print'});
		const r = await p.evaluate(() => {
			const pane = document.querySelector('#nuRECORD > .tab-pane');
			return {ov: getComputedStyle(pane).overflowX, doc: document.documentElement.scrollWidth, right: Math.round(document.getElementById('e2e_pw').getBoundingClientRect().right)};
		});
		await p.emulateMedia({media: 'screen'});
		const sr = await p.evaluate(() => getComputedStyle(document.querySelector('#nuRECORD > .tab-pane')).overflowX);
		expect(r.ov === 'visible' && r.doc >= r.right && sr === 'auto', 'print pane: ' + JSON.stringify(r) + ' screen ' + sr);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2epw'");
	}
};

T.wide_form_margins = async (s) => {
	// 0.8.988: a fixed-layout form wider than the window loses the page margins (html.nuWideForm, form card to the window edges, no
	// pane padding, canvas only as wide as its objects) – a form that just fits nuBuilder Forte's window needs no sideways scroll
	const p = s.ga;
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	if (!tab) { s.skip = 'no sample data'; return; }
	const vp = p.viewportSize();
	try {
		await p.setViewportSize({width: 1400, height: 900});
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
		const plain = await p.evaluate(() => document.documentElement.classList.contains('nuWideForm'));
		const left = await p.evaluate(() => { const m = document.querySelector('main'); return Math.round(m.clientWidth - 6 - 200); });   // ends 6 px before main's inner edge
		sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_html_code)
			VALUES ('e2ewide', 'samplecustomer', '${tab}', 'html', 'e2e_wide', 'E2E', 998, 20, ${left}, 200, 40, '0', 'RIGHT')`);
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1');
		const r = await p.evaluate(() => { const pane = document.querySelector('#nuRECORD > .tab-pane'); return {wide: document.documentElement.classList.contains('nuWideForm'), scroll: pane.scrollWidth - pane.clientWidth}; });
		await p.setViewportSize({width: 1900, height: 900});
		await p.waitForTimeout(500);
		const big = await p.evaluate(() => document.documentElement.classList.contains('nuWideForm'));
		expect(!plain && r.wide && r.scroll <= 0 && !big, 'wide form: ' + JSON.stringify({plain, r, big}));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2ewide'");
		if (vp) await p.setViewportSize(vp);
	}
};

T.obj_menu_geometry = async (s) => {
	// 0.8.921: the object menu has Top / Left / Width / Height (as nuBuilder Forte); the form changes at once, the database once on
	// Enter / leaving the field – and for the spin arrows only when the mouse button is released
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-omgeo.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_nunext_form_history > ' + keep);
	const geo = () => sql(`SELECT CONCAT(sob_all_top, ',', sob_all_left, ',', sob_all_width, ',', sob_all_height) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_name'`);
	const before = geo().split(',').map(Number);
	let calls = 0;
	const count = (r) => { if (/p=builderapi/.test(r.url()) && /objects\.geometry/.test(r.postData() || '')) calls++; };
	p.on('request', count);
	try {
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec); await p.waitForSelector('#label_cus_name');
		await p.click('#label_cus_name', {button: 'right'}); await p.waitForSelector('.nuObjMenu .nuOmGeoInput');
		const vals = await p.$$eval('.nuOmGeoInput', (l) => l.map((x) => +x.value));
		expect(vals.join(',') === before.join(','), 'menu shows the stored geometry ' + vals + ' / ' + before);
		// typed width + Enter: one save, the form changes
		await p.fill('.nuOmGeoInput[data-geo="width"]', '333'); await p.press('.nuOmGeoInput[data-geo="width"]', 'Enter'); await p.waitForTimeout(800);
		expect(geo().split(',')[2] === '333' && calls === 1, 'width saved once: ' + geo() + ' calls ' + calls);
		expect(Math.round(await p.evaluate(() => document.getElementById('nuw_cus_name').getBoundingClientRect().width)) === 333, 'width applied');
		// spinning (mouse button held): live, no save until the release
		const top = p.locator('.nuOmGeoInput[data-geo="top"]');
		await top.dispatchEvent('pointerdown');
		for (let i = 0; i < 5; i++) { await top.press('ArrowUp'); await top.dispatchEvent('change'); }
		await p.waitForTimeout(400);
		expect(calls === 1 && geo().split(',')[0] === String(before[0]), 'no save while spinning: calls ' + calls + ' ' + geo());
		expect(await p.evaluate(() => document.getElementById('nuw_cus_name').style.top) === (before[0] + 5) + 'px', 'top applied live');
		await p.evaluate(() => document.dispatchEvent(new PointerEvent('pointerup', {bubbles: true}))); await p.waitForTimeout(300);
		// a second click right after the first: still one save (700 ms after the last release), no message
		await top.dispatchEvent('pointerdown'); await top.press('ArrowUp'); await top.dispatchEvent('change');
		await p.evaluate(() => document.dispatchEvent(new PointerEvent('pointerup', {bubbles: true}))); await p.waitForTimeout(1200);
		expect(calls === 2 && geo().split(',')[0] === String(before[0] + 6), 'saved once after the clicks: calls ' + calls + ' ' + geo());
		expect(await p.locator('.toast:visible, .nuToast:visible').count() === 0, 'no message after saving');
		expect(await p.locator('.nuObjMenu').count() === 1, 'menu stays open');
		// ⋮ menu: the form's table in the database tool (0.8.923)
		await p.keyboard.press('Escape');
		await p.click('#nuOptions'); await p.waitForTimeout(300);
		const found = await p.evaluate(() => { HTMLFormElement.prototype.submit = function () { window.e2ePosted = Array.from(new FormData(this)).filter((kv) => kv[0] !== '_csrf'); }; const b = document.querySelector('.nuOptionsList [data-nu-act="db_table"]'); if (!b) return false; window.e2eDbLabel = b.textContent.trim(); b.click(); return true; });
		if (found) expect(/^Database$/.test(await p.evaluate(() => window.e2eDbLabel)), 'the entry is named Database: ' + await p.evaluate(() => window.e2eDbLabel));
		await p.waitForTimeout(500);
		const posted = found ? await p.evaluate(() => window.e2ePosted || null) : 'none';
		// without phpMyAdmin / Adminer on the test site the entry is not offered (as Database)
		if (posted !== 'none') expect(JSON.stringify(posted) === JSON.stringify([['app', 'DB'], ['table', 'sample_customer']]), 'database tool with the table: ' + JSON.stringify(posted));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		p.off('request', count);
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.obj_menu_clone = async (s) => {
	// 0.8.927: object menu → Actions → Clone (first entry) copies the object: <id>_2, below the original, same events
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-omclone.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_event zzzzsys_php zzzzsys_nunext_form_history > ' + keep);
	const src = sql(`SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = 'cus_email'`);
	sql(`INSERT INTO zzzzsys_event (zzzzsys_event_id, sev_zzzzsys_object_id, sev_event, sev_javascript) VALUES ('e2eomclev', '${src}', 'onchange', 'window.e2eEv = 1;')`);
	try {
		await open(p, 'index.php?p=form&f=' + F + '&r=' + rec); await p.waitForSelector('#label_cus_email');
		await p.click('#label_cus_email', {button: 'right'}); await p.waitForSelector('.nuObjMenu');
		await p.locator('.nuObjMenu .nuOmHasSub').first().click();
		const first = (await p.locator('.nuObjMenu .nuOmSub').first().locator('button').first().textContent()).trim();
		expect(first === 'Clone', 'Clone is the first action: ' + first);
		await Promise.all([p.waitForNavigation(), p.locator('.nuObjMenu .nuOmSub').first().locator('button').first().click()]);
		await p.waitForSelector('#label_cus_email');
		const row = sql(`SELECT CONCAT(o.sob_all_type, '|', o.sob_all_label, '|', o.sob_all_top - s.sob_all_top > 0, '|', o.sob_all_left = s.sob_all_left) FROM zzzzsys_object o, zzzzsys_object s WHERE o.sob_all_zzzzsys_form_id = '${F}' AND o.sob_all_id = 'cus_email_2' AND s.zzzzsys_object_id = '${src}'`);
		const srcRow = sql(`SELECT CONCAT(sob_all_type, '|', sob_all_label) FROM zzzzsys_object WHERE zzzzsys_object_id = '${src}'`);
		expect(row === srcRow + '|1|1', 'copy cus_email_2: ' + row + ' / ' + srcRow);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_event e JOIN zzzzsys_object o ON o.zzzzsys_object_id = e.sev_zzzzsys_object_id WHERE o.sob_all_id = 'cus_email_2' AND e.sev_javascript = 'window.e2eEv = 1;'`) === '1', 'event copied');
		expect(await p.locator('#cus_email_2').count() === 1, 'copy on the form');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

// design mode sets a column's total into the draft; the designed list shows the row at once
T.design_list_total = async (s) => {
	const p = s.ga, F = 'samplecustomer', keep = path.join(os.tmpdir(), 'nunext-e2e-total.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_browse zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await open(p, 'index.php?p=form&f=' + F + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		await p.click('#nuBrowseTable th.nuBrowseTitle >> nth=4'); await p.waitForTimeout(400);
		await p.selectOption('#nuDsPanel select[data-key="total"]', 'avg'); await p.waitForTimeout(1500);
		const js = sql(`SELECT jt.j FROM zzzzsys_nunext_form_draft d, JSON_TABLE(d.snapshot, '$.browse[*]' COLUMNS(o INT PATH '$.sbr_order', j TEXT PATH '$.sbr_json')) jt WHERE d.form_id = '${F}' AND d.status = 'open' ORDER BY jt.o DESC LIMIT 1`);
		expect(/"total":"avg"/.test(js), 'total in the draft: ' + js);
		await p.waitForSelector('#nuBrowseTotals td[data-nu-total=avg]');
		const v = await p.textContent('#nuBrowseTotals td[data-nu-total=avg] .nuTotalVal'), avg = +sql('SELECT AVG(cus_credit) FROM sample_customer');
		expect(Math.abs(parseFloat(v.replace(/[^0-9.-]/g, '')) - avg) < 0.01, 'average shown while designing: ' + v + ' / ' + avg);
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally { execSync('mysql ' + DB, { input: fs.readFileSync(keep) }); fs.unlinkSync(keep); }
};

T.browse_pane_settings_fold = async (s) => {
	const ga = s.ga;
	const openCols = async () => { await open(ga, 'index.php?p=form&f=samplecustomer'); await ga.click('#nuDesignMore'); await ga.click('#nuBrowseColsEdit'); await ga.waitForSelector('#nuBsqColTable', { state: 'visible' }); };
	try {
		await open(ga, 'index.php?p=form&f=samplecustomer');
		await ga.evaluate(() => { try { localStorage.removeItem('nunext-bsq-settings'); localStorage.removeItem('nunext-bsq-pin'); } catch (e) { /* */ } });
		await openCols();
		expect(await ga.isVisible('#nuBsq_rows') && await ga.getAttribute('#nuBsqSetToggle', 'aria-expanded') === 'true', 'settings open at first');
		const y0 = (await ga.locator('#nuBsqColTable').boundingBox()).y;
		await ga.click('#nuBsqSetToggle');
		expect(!(await ga.isVisible('#nuBsq_rows')) && !(await ga.isVisible('#nuBsq_multi')) && await ga.getAttribute('#nuBsqSetToggle', 'aria-expanded') === 'false', 'a click on the heading folds the settings');
		expect((await ga.locator('#nuBsqColTable').boundingBox()).y < y0 - 60 && await ga.isVisible('#nuBsq_dcols'), 'the columns move up, row details stay');
		await openCols();
		expect(!(await ga.isVisible('#nuBsq_rows')), 'remembered after a reload');
		await ga.focus('#nuBsqSetToggle'); await ga.keyboard.press('Enter');
		expect(await ga.isVisible('#nuBsq_rows'), 'opens again by keyboard');
		expect(await ga.evaluate(() => localStorage.getItem('nunext-bsq-settings')) === '1', 'state stored');
	} finally { await ga.evaluate(() => { try { localStorage.removeItem('nunext-bsq-settings'); } catch (e) { /* */ } }).catch(() => {}); }
};

T.design_menu_heading = async (s) => {
	const ga = s.ga, vp = ga.viewportSize();
	try {
		await open(ga, 'index.php?p=form&f=samplecustomer');
		await ga.click('#nuDesignMore');
		await ga.waitForSelector('#nuBrowseSqlEdit', { state: 'visible' });
		const v = await ga.evaluate(() => { const li = [...document.querySelectorAll('#nuDesignGroup .dropdown-menu > li')];
			const txt = id => { const b = document.getElementById(id).cloneNode(true); b.querySelectorAll('.nuBsqKey').forEach(k => k.remove()); return b.textContent.replace(/\s+/g, ' ').trim(); };
			return { head: li[0].querySelector('h6.dropdown-header') ? li[0].textContent.trim() : '', sql: txt('nuBrowseSqlEdit'), php: txt('nuBrowsePhpEdit'), js: txt('nuBrowseJsEdit'), cols: txt('nuBrowseColsEdit'), full: document.getElementById('nuBrowseSqlEdit').getAttribute('data-nu-full') }; });
		expect(v.head !== '' && v.full !== v.sql && /…$/.test(v.full), 'a heading carries the verb: ' + JSON.stringify(v));
		expect(![v.sql, v.php, v.js, v.cols].some(x => /…/.test(x) || x.indexOf(v.head) === 0) && /^PHP \(Before Browse\)$/.test(v.php) && v.js === 'JavaScript', 'entries are the plain names: ' + JSON.stringify(v));
		await ga.click('#nuBrowseColsEdit');
		await ga.waitForSelector('#nuBsqPane', { state: 'visible' });
		expect(await ga.getAttribute('#nuBsqTabCols', 'aria-selected') === 'true', 'the entry still opens its tab');
		// phones: the ⋮ menu has no heading, so its copies keep the whole sentence
		await ga.setViewportSize({ width: 390, height: 800 });
		await open(ga, 'index.php?p=form&f=samplecustomer');
		const ph = await ga.evaluate(() => { const x = document.querySelector('[data-nu-opt=browse_sql]'); return x ? x.textContent.replace(/\s+/g, ' ').trim() : null; });
		expect(ph === null || ph.indexOf(v.full) === 0, 'phone ⋮ entry keeps the full text: ' + ph);
	} finally { await ga.setViewportSize(vp); }
};

T.design_btn_neutral = async (s) => {
	// 0.8.1107: the Design button looks like its neighbours (no accent outline); only the pencil keeps the accent colour
	const p = s.ga;
	for (const u of ['index.php?p=form&f=samplecustomer', 'index.php?p=form&f=samplecustomer&r=-1']) {
		await open(p, u); await p.waitForSelector('#nuDesignButton');
		const d = await p.evaluate(() => {
			const cs = (e) => { const c = getComputedStyle(e); return [c.borderTopColor, c.color, c.backgroundColor].join('|'); };
			const b = document.getElementById('nuDesignButton'), m = document.getElementById('nuDesignMore');
			const other = document.querySelector('#nuActionHolder .btn-outline-secondary:not(#nuDesignButton):not(#nuDesignMore)');
			return { b: cs(b), m: m ? cs(m) : null, o: other ? cs(other) : null, cls: b.className + ' ' + (m ? m.className : ''), pen: getComputedStyle(b.querySelector('.bi')).color, txt: getComputedStyle(b).color };
		});
		expect(!/btn-outline-primary/.test(d.cls), 'Design still an accent button: ' + d.cls);
		expect(d.o && d.b === d.o && (d.m === null || d.m === d.o), 'Design does not look like its neighbours on ' + u + ': ' + JSON.stringify(d));
		expect(d.pen !== d.txt, 'the pencil lost its accent colour');
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.obj_menu_focus_bg = async (s) => {
	// 0.8.1106: the focused item of the right-click menus shows its background only (no outline), darker than a hovered one
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuBrowseTable th.nuBrowseTitle');
	await p.locator('#nuBrowseTable th.nuBrowseTitle:visible').first().click({button: 'right'}); await p.waitForSelector('.nuColMenu');
	const look = (sel) => p.evaluate((q) => { const e = document.querySelector(q), c = getComputedStyle(e); return { o: c.outlineStyle + ' ' + c.outlineWidth, sh: c.boxShadow, bg: c.backgroundColor }; }, sel);
	await p.keyboard.press('ArrowDown'); await p.waitForTimeout(250);
	const foc = await p.evaluate(() => { const e = document.activeElement, c = getComputedStyle(e); return { item: e.classList.contains('dropdown-item') && !!e.closest('.nuColMenu'), vis: e.matches(':focus-visible'), o: c.outlineStyle, w: c.outlineWidth, sh: c.boxShadow, bg: c.backgroundColor }; });
	expect(foc.item && foc.vis, 'the arrow key did not focus a menu item');
	expect((foc.o === 'none' || foc.w === '0px') && foc.sh === 'none', 'focused item still has an outline: ' + JSON.stringify(foc));
	expect(foc.bg !== 'rgba(0, 0, 0, 0)' && foc.bg !== 'transparent', 'focused item without a background');
	const other = p.locator('.nuColMenu .dropdown-item:not(:focus)').last();
	await other.hover(); await p.waitForTimeout(250);
	const hov = await other.evaluate((e) => getComputedStyle(e).backgroundColor);
	expect(hov !== foc.bg && hov !== 'rgba(0, 0, 0, 0)', 'hover and focus look the same: ' + hov + ' / ' + foc.bg);
	await p.keyboard.press('Escape');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.browse_sql_edit = async (s) => {
	// 0.8.930: ▾ of the list's Design button → Edit browse SQL… → pane on the right, Ctrl+S saves sfo_browse_sql and reloads
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-bsq.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_nunext_form_history > ' + keep);
	try {
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuDesignMore');
		await p.click('#nuDesignMore'); await p.click('#nuBrowseSqlEdit');
		await p.waitForSelector('#nuBsqPane .CodeMirror');
		const box = await p.locator('#nuBsqPane').boundingBox(), vw = await p.evaluate(() => document.documentElement.clientWidth);
		expect(box && Math.abs(box.x + box.width - vw) < 16, 'pane on the right: ' + JSON.stringify(box) + ' ' + vw);
		const cur = await p.evaluate(() => document.querySelector('#nuBsqPane .CodeMirror').CodeMirror.getValue());
		expect(cur === sql(`SELECT sfo_browse_sql FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`), 'pane shows the SQL');
		await p.evaluate((v) => document.querySelector('#nuBsqPane .CodeMirror').CodeMirror.setValue(v), cur + '\n-- e2e bsq');
		await p.locator('#nuBsqPane .CodeMirror').click();
		await Promise.all([p.waitForNavigation(), p.keyboard.press('Control+s')]);
		expect(/-- e2e bsq$/.test(sql(`SELECT sfo_browse_sql FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`)), 'SQL saved');
		await p.waitForSelector('#nuBrowseTable tbody tr');
		expect(await p.locator('#nuBsqPane').count() === 0, 'pane closed after the reload');
		// 0.8.931: pinned = open again after a save, a page change and on another list; Close ends it
		await p.keyboard.press('Control+Shift+B'); await p.waitForSelector('#nuBsqPane .CodeMirror');   // 0.8.932: the shortcut opens it
		await p.click('#nuBsqPin');
		expect(await p.getAttribute('#nuBsqPin', 'aria-pressed') === 'true', 'pin on');
		// 0.8.941: the top bar's menus open above the pane
		await p.click('.nuUserBtn'); await p.waitForTimeout(300);
		const onTop = await p.evaluate(() => { const m = document.querySelector('.nuUserBtn + .dropdown-menu, .nuUserBtn ~ .dropdown-menu.show');
			const it = m && [...m.querySelectorAll('.dropdown-item')].find(x => x.getBoundingClientRect().right > document.getElementById('nuBsqPane').getBoundingClientRect().left + 10);
			if (!it) return 'no item over the pane';
			const r = it.getBoundingClientRect(), el = document.elementFromPoint(r.right - 8, r.top + r.height / 2);
			return m.contains(el) ? '' : 'covered by ' + (el && (el.id || el.className)); });
		expect(onTop === '', 'user menu above the pane: ' + onTop);
		await p.keyboard.press('Escape');
		await p.evaluate(() => { const c = document.querySelector('#nuBsqPane .CodeMirror').CodeMirror; c.setValue(c.getValue().replace(/\n-- e2e bsq$/, '')); });
		await Promise.all([p.waitForNavigation(), p.click('#nuBsqPane .nuBsqSave')]);
		await p.waitForSelector('#nuBsqPane .CodeMirror');
		expect(!/-- e2e bsq/.test(sql(`SELECT sfo_browse_sql FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`)), 'second save');
		await open(p, 'index.php?p=form&f=sampleorder'); await p.waitForSelector('#nuBsqPane .CodeMirror');
		expect(/sample_order/i.test(await p.evaluate(() => document.querySelector('#nuBsqPane .CodeMirror').CodeMirror.getValue())), 'pinned pane on another list shows its SQL');
		await p.click('#nuBsqPane .nuBsqClose');
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuBrowseTable');
		expect(await p.locator('#nuBsqPane').count() === 0, 'closed = no longer kept open');
		// 0.8.931: fixed column widths – the same on page 1 and 2
		const w = async () => p.evaluate(() => Array.from(document.querySelectorAll('#nuBrowseTable thead th')).map(t => Math.round(t.getBoundingClientRect().width)).join(','));
		const w1 = await w();
		await open(p, 'index.php?p=form&f=' + F + '&pg=2'); await p.waitForSelector('#nuBrowseTable');
		expect(w1 === await w(), 'widths page 1 / 2: ' + w1);
		expect(await p.evaluate(() => getComputedStyle(document.getElementById('nuBrowseTable')).tableLayout) === 'fixed', 'fixed table layout');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.evaluate(() => localStorage.removeItem('nunext-bsq-pin')).catch(() => {});
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.form_selects_searchable = async (s) => {             // 0.8.1079: selects of forms are Tom Selects (browse SQL pane, start page tile editor)
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer'); await p.waitForSelector('#nuDesignMore');
	await p.click('#nuDesignMore'); await p.click('#nuBrowseColsEdit'); await p.waitForSelector('#nuBsqColTable');
	expect(await p.evaluate(() => !!document.getElementById('nuBsq_redirect').tomselect), 'Open records in form: Tom Select');
	await p.click('#nuBsq_redirect + .ts-wrapper .ts-control'); await p.waitForTimeout(200);
	const dd = await p.evaluate(() => { const d = [...document.querySelectorAll('body > .ts-dropdown')].find((x) => x.offsetParent !== null); return d ? [d.querySelectorAll('.option').length, +getComputedStyle(d).zIndex] : [0, 0]; });
	expect(dd[0] > 0 && dd[1] > 1019, 'its list opens above the pane: ' + dd.join(' / '));
	await p.keyboard.press('Escape');
	await open(p, 'index.php?p=home');
	expect(await p.evaluate(() => typeof window.nuListSelects === 'function'), 'start page loads Tom Select');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.pane_tab_code_dot = async (s) => {
	// 0.8.1133: a dot on the side pane's PHP / JavaScript tab while it holds code (follows typing); the procedure form's JS tab likewise
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-ptcd.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_php > ' + keep);
	const has = (id) => p.evaluate((id) => { const b = document.getElementById(id); return [b.classList.contains('nuHasCode'), getComputedStyle(b, '::after').content !== 'none', b.title, b.getAttribute('aria-label') || '']; }, id);
	try {
		execSync('mysql ' + DB, {input: "UPDATE zzzzsys_form SET sfo_browse_javascript = '// e2e dot' WHERE zzzzsys_form_id = '" + F + "'; DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = '" + F + "_BB';"});
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuDesignMore');
		await p.click('#nuDesignMore'); await p.click('#nuBrowsePhpEdit'); await p.waitForSelector('#nuBsqPane .nuBsqBody[data-code="php"] .CodeMirror');
		let php = await has('nuBsqTabPhp'), js = await has('nuBsqTabJs');
		expect(!php[0] && !php[1] && php[2] === '', 'empty PHP tab without a dot: ' + JSON.stringify(php));
		expect(js[0] && js[1] && js[2] !== '' && js[3].indexOf('JavaScript, ') === 0, 'JavaScript tab with code has the dot: ' + JSON.stringify(js));
		for (const id of ['nuBsqTabSql', 'nuBsqTabCols']) expect(!(await has(id))[0], id + ' never marked');
		await p.evaluate(() => { document.querySelector('#nuBsqPane .nuBsqBody[data-code="php"] .CodeMirror').CodeMirror.setValue('// x'); });
		expect((await has('nuBsqTabPhp'))[0], 'the dot appears while typing');
		await p.evaluate(() => { document.querySelector('#nuBsqPane .nuBsqBody[data-code="php"] .CodeMirror').CodeMirror.setValue('  \n'); });
		expect(!(await has('nuBsqTabPhp'))[0], 'blank code = no dot');
		await p.evaluate(() => { document.querySelector('#nuBsqPane .nuBsqBody[data-code="php"] .CodeMirror').CodeMirror.setValue(''); });
		// procedure form
		await open(p, 'index.php?p=procedures&id=new'); await p.waitForSelector('#procTabJsBtn');
		expect(!(await has('procTabJsBtn'))[0] && (await p.locator('#procTabJsBtn .badge').count()) === 0, 'new procedure: JS tab without dot and without the old badge');
		await p.click('#procTabJsBtn'); await p.waitForTimeout(300);
		await p.evaluate(() => { document.querySelector('#procTabJs .CodeMirror').CodeMirror.setValue('function nuOnProcedureRun() {}'); });
		const pj = await has('procTabJsBtn');
		expect(pj[0] && pj[1] && pj[3].indexOf('JS, ') === 0, 'procedure JS tab gets the dot while typing: ' + JSON.stringify(pj));
		await p.evaluate(() => { window.onbeforeunload = null; document.querySelectorAll('form[data-nu-guard]').forEach((f) => f.removeAttribute('data-nu-guard')); });
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.browse_pane_more = async (s) => {
	// 0.8.1108: the list's side pane – tabs PHP (Before Browse) and JavaScript, more settings per column (format, filter, badge, click), row details
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-bsqm.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_browse zzzzsys_php zzzzsys_nunext_form_history > ' + keep);
	const setCode = (k, v) => p.evaluate(([k, v]) => { document.querySelector('#nuBsqPane .nuBsqBody[data-code="' + k + '"] .CodeMirror').CodeMirror.setValue(v); }, [k, v]);
	try {
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuDesignMore');
		await p.click('#nuDesignMore');
		expect(await p.locator('#nuBrowseSqlEdit, #nuBrowsePhpEdit, #nuBrowseJsEdit, #nuBrowseColsEdit').count() === 4, 'four entries in Design ▾');
		await p.click('#nuBrowsePhpEdit');
		await p.waitForSelector('#nuBsqPane .nuBsqBody[data-code=php] .CodeMirror');
		expect(await p.getAttribute('#nuBsqTabPhp', 'aria-selected') === 'true' && await p.locator('#nuBsqPane .nuBsqBody[data-code=sql]').isHidden(), 'PHP tab shown alone');
		const bb0 = sql(`SELECT COALESCE(MAX(sph_php), '') FROM zzzzsys_php WHERE zzzzsys_php_id = '${F}_BB'`);
		await setCode('php', bb0 + "\n// e2e-pane-bb\nnuSetProperty('E2E_PANE', '1');");
		// JavaScript tab by the arrow key from PHP
		await p.focus('#nuBsqTabPhp'); await p.keyboard.press('ArrowRight');
		await p.waitForSelector('#nuBsqPane .nuBsqBody[data-code=js] .CodeMirror');
		expect(await p.getAttribute('#nuBsqTabJs', 'aria-selected') === 'true', 'arrow key goes to the JavaScript tab');
		const js0 = sql(`SELECT COALESCE(sfo_browse_javascript, '') FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`);
		await setCode('js', js0 + "\nwindow.e2ePaneJs = 'yes';\nfunction e2ePaneClick(o) { window.e2ePaneClicked = o.pk; }");
		// columns: more settings of the first column
		await p.click('#nuBsqTabCols'); await p.waitForSelector('#nuBsqColTable');
		const first = sql(`SELECT zzzzsys_browse_id FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}' ORDER BY sbr_order LIMIT 1`);
		await p.click('#nuBsqColTable tr.nuBsqCol >> nth=0 >> .nuBsqMoreBtn');
		await p.waitForSelector('tr.nuBsqMoreRow');
		expect(await p.locator('tr.nuBsqMoreRow').count() === 1 && await p.getAttribute('tr.nuBsqMoreRow', 'data-more') === first, 'more settings open below the first column');
		await p.check('tr.nuBsqMoreRow [data-lm=badge]');
		await p.selectOption('tr.nuBsqMoreRow [data-lm=action_t]', 'js');
		await p.waitForSelector('tr.nuBsqMoreRow [data-lm=action_id]');
		expect(await p.locator('tr.nuBsqMoreRow .nuBsqActNoTarget').count() === 1, 'hint while the click action has no target');
		await p.fill('tr.nuBsqMoreRow [data-lm=action_id]', 'e2ePaneClick');
		expect(await p.locator('tr.nuBsqMoreRow [data-lm=format] option').count() > 3, 'formats offered');
		// row details and the link to the column access
		await p.check('#nuBsq_dcols');
		expect(/p=dataaccess/.test(await p.getAttribute('#nuBsqAccess', 'href') || ''), 'link to the column access');
		await Promise.all([p.waitForNavigation(), p.click('#nuBsqPane .nuBsqSave')]);
		await p.waitForSelector('#nuBrowseTable');
		expect(/e2e-pane-bb/.test(sql(`SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = '${F}_BB'`)), 'PHP Before Browse saved');
		expect(await p.evaluate(() => window.e2ePaneJs) === 'yes', 'the saved JavaScript runs on the list');
		const j = JSON.parse(sql(`SELECT sbr_json FROM zzzzsys_browse WHERE zzzzsys_browse_id = '${first}'`) || '{}').nunext || {};
		expect(j.badge === true && j.action && j.action.t === 'js' && j.action.id === 'e2ePaneClick', 'badge and click action saved: ' + JSON.stringify(j));
		expect(/"details"/.test(sql(`SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`)), 'row details saved');
		expect(await p.locator('#nuBrowseTable .nuDetBtn').count() > 0, 'rows show the details button');
		await p.locator('#nuBrowseTable .nuCellAct').first().click(); await p.waitForTimeout(300);
		expect(!!(await p.evaluate(() => window.e2ePaneClicked)), 'the click action calls the function');
		// a save without touching the column keeps what the pane does not edit (badge, action)
		await p.click('#nuDesignMore'); await p.click('#nuBrowseColsEdit'); await p.waitForSelector('#nuBsqColTable');
		expect(await p.locator('#nuBsqColTable tr.nuBsqCol >> nth=0 >> .nuBsqMoreBtn.text-primary').count() === 1, 'a column with more settings is marked');
		await p.fill('#nuBsqColTable tbody tr.nuBsqCol >> nth=1 >> input[data-lc="title"]', 'E2e other');
		await Promise.all([p.waitForNavigation(), p.click('#nuBsqPane .nuBsqSave')]);
		await p.waitForSelector('#nuBrowseTable');
		const j2 = JSON.parse(sql(`SELECT sbr_json FROM zzzzsys_browse WHERE zzzzsys_browse_id = '${first}'`) || '{}').nunext || {};
		expect(j2.badge === true && j2.action && j2.action.id === 'e2ePaneClick', 'badge / action kept by a later save: ' + JSON.stringify(j2));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
		await p.evaluate(() => { try { localStorage.removeItem('nunext-bsq-pin'); localStorage.removeItem('nunext-bsq-tab'); } catch (e) { /* ignore */ } }).catch(() => {});
	}
};

T.browse_sql_cols = async (s) => {
	// 0.8.942: tabs in the browse SQL pane – Columns = settings + columns of the list (as design mode's Browse list), saved with Save
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-bsqc.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_browse zzzzsys_nunext_form_history > ' + keep);
	try {
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuDesignMore');
		await p.click('#nuDesignMore'); await p.click('#nuBrowseColsEdit');
		await p.waitForSelector('#nuBsqColTable');
		expect(await p.getAttribute('#nuBsqTabCols', 'aria-selected') === 'true', 'Edit columns… opens the Columns tab');
		expect(await p.locator('#nuBsqPane .nuBsqBody[data-code=sql]').isHidden() && await p.locator('#nuBsqColTable').isVisible(), 'Columns tab shown');
		const n = sql(`SELECT COUNT(*) FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}'`);
		expect(String(await p.locator('#nuBsqColTable tbody tr.nuBsqCol').count()) === String(n), 'one row per column');
		const first = sql(`SELECT zzzzsys_browse_id FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}' ORDER BY sbr_order LIMIT 1`);
		await p.fill('#nuBsqColTable tbody tr.nuBsqCol >> nth=0 >> input[data-lc="title"]', 'E2e title');
		await p.click('#nuBsqColTable tbody tr.nuBsqCol >> nth=0 >> button[data-act="bi-arrow-down"]');
		await p.fill('#nuBsq_rows', '7');
		await p.check('#nuBsq_multi');
		await p.click('#nuBsqAddCol');
		await p.fill('#nuBsqColTable tbody tr.nuBsqCol >> nth=-1 >> input[data-lc="display"]', 'sample_customer_id');
		await Promise.all([p.waitForNavigation(), p.click('#nuBsqPane .nuBsqSave')]);
		await p.waitForSelector('#nuBrowseTable');
		expect(sql(`SELECT sbr_title FROM zzzzsys_browse WHERE zzzzsys_browse_id = '${first}'`) === 'E2e title', 'title saved');
		expect(sql(`SELECT zzzzsys_browse_id FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}' ORDER BY sbr_order LIMIT 1 OFFSET 1`) === first, 'moved down');
		expect(String(sql(`SELECT COUNT(*) FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${F}'`)) === String(+n + 1), 'column added');
		expect(String(sql(`SELECT sfo_browse_rows_per_page FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`)) === '7', 'rows per page saved');
		expect(String(sql(`SELECT sfo_browse_title_multiline FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`)) === '1', 'multi-line titles saved');
		expect(await p.locator('#nuBrowseTable tbody tr').count() <= 7, 'list shows 7 rows');
		// the tab is remembered; unsaved changes ask before closing
		// 0.8.943: Edit browse SQL… opens the SQL tab, Edit columns… the Columns tab (also while the pane is open)
		await p.click('#nuDesignMore'); await p.click('#nuBrowseSqlEdit');
		await p.waitForSelector('#nuBsqPane .CodeMirror');
		expect(await p.getAttribute('#nuBsqTabSql', 'aria-selected') === 'true', 'Edit browse SQL… = SQL tab');
		await p.click('#nuDesignMore'); await p.click('#nuBrowseColsEdit');
		await p.waitForSelector('#nuBsqColTable', {state: 'visible'});
		expect(await p.getAttribute('#nuBsqTabCols', 'aria-selected') === 'true', 'Edit columns… = Columns tab');
		await p.fill('#nuBsq_rows', '9');
		let asked = false;
		p.once('dialog', () => { asked = true; });                         // accepted by the suite's handler
		await p.click('#nuBsqPane .nuBsqClose'); await p.waitForTimeout(200);
		expect(asked && await p.locator('#nuBsqPane').count() === 0, 'unsaved settings ask');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.evaluate(() => { localStorage.removeItem('nunext-bsq-tab'); localStorage.removeItem('nunext-bsq-pin'); }).catch(() => {});
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.bsq_resize = async (s) => {
	// 0.8.947: the browse SQL pane has a splitter on its left edge – drag = new width (kept per browser), the list makes room, double-click = default
	const p = s.ga, F = 'samplecustomer';
	try {
		await p.setViewportSize({width: 1400, height: 900});
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuDesignMore');
		await p.click('#nuDesignMore'); await p.click('#nuBrowseSqlEdit'); await p.waitForSelector('#nuBsqPane .CodeMirror');
		const w = () => p.evaluate(() => Math.round(document.getElementById('nuBsqPane').getBoundingClientRect().width));
		const w0 = await w(), sp = await p.locator('#nuBsqPane .nuBsqSplit').boundingBox();
		await p.mouse.move(sp.x + 4, sp.y + 200); await p.mouse.down();
		for (let i = 1; i <= 8; i++) await p.mouse.move(sp.x + 4 - 25 * i, sp.y + 200);
		await p.mouse.up(); await p.waitForTimeout(200);
		const w1 = await w();
		expect(Math.abs(w1 - (w0 + 200)) <= 6, 'dragged 200 px wider: ' + w0 + ' → ' + w1);
		const margin = await p.evaluate(() => Math.round(parseFloat(getComputedStyle(document.getElementById('main')).marginRight)));
		expect(Math.abs(margin - w1) <= 2, 'list makes room: ' + margin);
		const cmw = await p.evaluate(() => Math.round(document.querySelector('#nuBsqPane .CodeMirror').getBoundingClientRect().width));
		expect(cmw > w0, 'editor follows: ' + cmw);
		await p.click('#nuBsqPane .nuBsqClose');
		await open(p, 'index.php?p=form&f=' + F); await p.waitForSelector('#nuDesignMore');
		await p.click('#nuDesignMore'); await p.click('#nuBrowseSqlEdit'); await p.waitForSelector('#nuBsqPane .CodeMirror');
		expect(Math.abs(await w() - w1) <= 2, 'width kept after a reload');
		await p.dblclick('#nuBsqPane .nuBsqSplit'); await p.waitForTimeout(200);
		expect(Math.abs(await w() - 560) <= 2, 'double-click = default width: ' + await w());
		await p.focus('#nuBsqPane .nuBsqSplit'); await p.keyboard.press('ArrowLeft');
		expect(Math.abs(await w() - 580) <= 2, 'arrow key: ' + await w());
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.evaluate(() => { localStorage.removeItem('nunext-bsq-w'); localStorage.removeItem('nunext-bsq-pin'); localStorage.removeItem('nunext-bsq-tab'); }).catch(() => {});
	}
};

T.textarea_raise = async (s) => {
	// 0.8.1177: a textarea of the fixed layout that is clicked into / resized lies above the objects below it until the user clicks elsewhere
	const p = s.ga;
	let info = null;
	for (const f of ['sampleorder', 'samplecustomer', 'sampleall']) {
		await open(p, 'index.php?p=form&f=' + f + '&r=-1&layout=absolute');
		info = await p.evaluate(() => {
			const ta = [...document.querySelectorAll('.nuAbsoluteCanvas textarea')].find((t) => t.getBoundingClientRect().height > 0 && t.closest('.nuAbs'));
			if (!ta) return null;
			const tw = ta.closest('.nuAbs');
			const ow = [...tw.parentElement.querySelectorAll(':scope > .nuAbs')].find((w) => w !== tw && w.getBoundingClientRect().height > 0 && w.querySelector('input:not([type=hidden])'));
			if (!ow) return null;
			const other = ow.querySelector('input:not([type=hidden])');
			// a field right below the textarea, then the textarea made taller over it – what dragging its corner does
			tw.after(ow); // an object that comes later in the layout (as one placed below usually does)
			ow.style.top = (tw.offsetTop + tw.offsetHeight + 8) + 'px';
			ow.style.left = tw.offsetLeft + 'px';
			ta.style.height = (ta.offsetHeight + 80) + 'px';
			const b = other.getBoundingClientRect();
			return {x: b.left + 5, y: b.top + b.height / 2, ta: ta.id, other: other.id};
		});
		if (info) break;
	}
	if (!info) { s.skip = 'no sample form in fixed layout with a textarea'; return; }
	const topAt = () => p.evaluate((pt) => { const e = document.elementFromPoint(pt.x, pt.y); return e ? e.id : ''; }, info);
	expect(await topAt() === info.other, 'before: the field lies above the grown textarea (layer order of the layout)');
	await p.click('#' + info.ta, {position: {x: 10, y: 10}});
	const during = await topAt();
	expect(during === info.ta, 'the textarea in use lies above: ' + during);
	await p.mouse.click(5, 300);
	await p.waitForTimeout(100);
	const after = await topAt();
	expect(after === info.other && !(await p.$('.nuAbsRaised')), 'back to its layer after a click elsewhere: ' + after);
};

T.form_tags = async (s) => {
	// 0.8.1020: department / team of a form (sfo_json nunext) – saved through the draft, published, undo, other nunext keys kept,
	// design panel fields with suggestions, forms list columns + filters, form spec of Form with AI
	const p = s.ga, F = 'samplecustomer';
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-formtags.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_nunext_form_draft zzzzsys_nunext_form_history > ' + keep);
	const json = () => { const j = sql("SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = '" + F + "'"); try { return JSON.parse(j || '{}'); } catch (e) { return {}; } };
	sql("UPDATE zzzzsys_form SET sfo_json = '{\"forte\":1,\"nunext\":{\"e2e_keep\":\"yes\"}}' WHERE zzzzsys_form_id = '" + F + "'");
	try {
		await open(p, 'index.php?p=form&f=' + F + '&design=1');
		const api = (body) => p.evaluate(async (bd) => {
			const r = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': nuNext.csrf }, body: JSON.stringify(bd) });
			return Object.assign({ status: r.status }, await r.json());
		}, Object.assign({ form_id: F }, body));
		let r = await api({ action: 'draft.start', note: 'e2e tags' });
		if (!r.ok && r.draft) r = { ok: true, draft: r.draft };
		expect(r.ok && r.draft, 'draft.start: ' + JSON.stringify(r).slice(0, 200));
		const D = r.draft;
		r = await api({ action: 'form.save', draft_id: D.id, rev: D.rev, fields: { department: '  E2E   Sales ', team: 'E2E North' } });
		expect(r.ok, 'draft form.save: ' + (r.error || ''));
		let j = json();
		expect(!j.nunext.department, 'the draft changed the live form');
		r = await api({ action: 'form.save', draft_id: D.id, rev: r.draft.rev, fields: { team: '' } });
		r = await api({ action: 'draft.undo', draft_id: D.id, rev: r.draft.rev });
		expect(r.ok, 'undo: ' + (r.error || ''));
		r = await api({ action: 'draft.publish', draft_id: D.id, note: 'E2E tags' });
		if (!r.ok && r.warnings) r = await api({ action: 'draft.publish', draft_id: D.id, note: 'E2E tags', warnings_ok: true });
		expect(r.ok, 'publish: ' + (r.error || JSON.stringify(r).slice(0, 200)));
		j = json();
		expect(j.forte === 1 && j.nunext.e2e_keep === 'yes' && j.nunext.department === 'E2E Sales' && j.nunext.team === 'E2E North', 'published JSON: ' + JSON.stringify(j));
		// design panel: the fields show the values, the suggestions list them
		await open(p, 'index.php?p=form&f=' + F + '&design=1');
		await p.waitForSelector('[data-key="nunext_department"]', { timeout: 10000 });
		const ui = await p.evaluate(() => { const d = document.querySelector('[data-key="nunext_department"]'), dl = d && document.getElementById(d.getAttribute('list'));
			return { v: d && d.value, t: document.querySelector('[data-key="nunext_team"]')?.value, opts: dl ? [...dl.options].map(o => o.value) : [] }; });
		expect(ui.v === 'E2E Sales' && ui.t === 'E2E North' && ui.opts.includes('E2E Sales'), 'design panel: ' + JSON.stringify(ui));
		// live save without a draft (empty = removed)
		const live = await api({ action: 'form.save', fields: { team: '' } });
		if (live.ok) { j = json(); expect(!('team' in j.nunext) && j.nunext.department === 'E2E Sales' && j.nunext.e2e_keep === 'yes', 'live save: ' + JSON.stringify(j)); }
		// forms list: column + filter
		await open(p, 'index.php?p=forms&view=all&department=' + encodeURIComponent('E2E Sales'));
		const lst = await p.evaluate(() => ({ rows: document.querySelectorAll('.nuListCard tbody tr[data-href], .nuListCard tbody tr').length, txt: document.querySelector('.nuListCard tbody')?.textContent || '',
			opts: [...document.querySelectorAll('select[name="department"] option')].map(o => o.value) }));
		expect(lst.txt.includes('E2E Sales') && lst.opts.includes('E2E Sales') && lst.opts.includes('-'), 'forms list filter: ' + JSON.stringify(lst).slice(0, 300));
		await open(p, 'index.php?p=forms&view=all&department=-');
		expect(!(await p.evaluate(() => document.querySelector('.nuListCard tbody')?.textContent || '')).includes('E2E Sales'), '(none) filter shows the tagged form');
		// Form with AI: the spec carries them
		await open(p, 'index.php?p=formai&f=' + F);
		const spec = await p.evaluate(async (f) => {
			const b = new URLSearchParams({ _csrf: document.querySelector('#faForm input[name=_csrf]').value, action: 'context', kind: 'form', fmt: 'md', full: '0' });
			return (await fetch('index.php?p=formai&f=' + f, { method: 'POST', body: b, credentials: 'same-origin' })).text();
		}, F);
		expect(/"department":\s*"E2E Sales"/.test(spec), 'form spec without department');
	} finally {
		execSync('mysql ' + DB, { input: require('fs').readFileSync(keep) });
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.responsive_boxes = async (s) => {
	// 0.8.959: content boxes with a direction (side by side / one under the other / grid), widths in the box, hide per screen size
	const p = s.ga;
	const F = 'samplecustomer';
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '" + F + "' ORDER BY syt_order LIMIT 1");
	const cus = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1");
	if (!tab || !cus) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-resp.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_object zzzzsys_nunext_form_history > ' + keep);
	const nj = (o) => JSON.stringify({nunext: o}).replace(/'/g, "''");
	try {
		sql("UPDATE zzzzsys_form SET sfo_json = '" + nj({layout: 'grid'}) + "' WHERE zzzzsys_form_id = '" + F + "'");
		sql("INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_id, sob_all_label, sob_all_type, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_order, sob_all_json) VALUES ('e2e_rbox', '" + F + "', '" + tab + "', 'e2e_rbox', 'Box', 'contentbox', 5000, 0, 600, 100, 1, '" + nj({row: 1, span: 12}) + "')");
		sql("UPDATE zzzzsys_object SET sob_all_json = '" + nj({box: 'e2e_rbox', row: 1, col: 10, size: {w: 'fixed', px: 120}}) + "' WHERE sob_all_zzzzsys_form_id = '" + F + "' AND sob_all_id = 'cus_code'");
		sql("UPDATE zzzzsys_object SET sob_all_json = '" + nj({box: 'e2e_rbox', row: 1, col: 20, size: {w: 'fill', grow: 2}}) + "' WHERE sob_all_zzzzsys_form_id = '" + F + "' AND sob_all_id = 'cus_name'");
		sql("UPDATE zzzzsys_object SET sob_all_json = '" + nj({box: 'e2e_rbox', row: 1, col: 30, hide: ['sm']}) + "' WHERE sob_all_zzzzsys_form_id = '" + F + "' AND sob_all_id = 'cus_phone'");
		// the box's direction through the design API (live), as the panel saves it
		await open(p, 'index.php?p=form&f=' + F + '&r=' + cus);
		const r = await p.evaluate(async (bd) => {
			const csrf = (window.nuNext && window.nuNext.csrf) || document.querySelector('input[name=_csrf]').value;
			const x = await fetch('index.php?p=builderapi', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(bd) });
			return x.json();
		}, {form_id: F, action: 'object.save', object_id: 'e2e_rbox', fields: {}, grid: {stack: {dir: 'h', gap: 10}}});
		expect(r.ok, 'object.save: ' + (r.error || ''));
		const js = JSON.parse(sql("SELECT sob_all_json FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2e_rbox'"));
		expect(js.nunext.stack && js.nunext.stack.dir === 'h' && js.nunext.stack.phone_v === true && js.nunext.row === 1 && js.nunext.span === 12, 'stored: ' + JSON.stringify(js));
		const look = async (w) => {
			await p.setViewportSize({width: w, height: 900});
			await open(p, 'index.php?p=form&f=' + F + '&r=' + cus);
			await p.waitForSelector('#nuw_e2e_rbox .nuStack');
			return p.evaluate(() => {
				const st = document.querySelector('#nuw_e2e_rbox .nuStack'), cs = getComputedStyle(st);
				const wd = (id) => { const e = document.getElementById('nuw_' + id); return e ? Math.round(e.getBoundingClientRect().width) : -1; };
				return {dir: cs.flexDirection, gap: cs.columnGap, code: wd('cus_code'), name: wd('cus_name'), phone: getComputedStyle(document.getElementById('nuw_cus_phone')).display,
					inBox: !!st.querySelector('#nuw_cus_code') && !!st.querySelector('#nuw_cus_name')};
			});
		};
		let v = await look(1280);
		expect(v.inBox && v.dir === 'row' && v.gap === '10px' && v.code === 120 && v.name > 200 && v.phone !== 'none', 'desktop: ' + JSON.stringify(v));
		v = await look(390);
		expect(v.dir === 'column' && v.phone === 'none', 'phone: ' + JSON.stringify(v));
		// grid with columns per screen size
		sql("UPDATE zzzzsys_object SET sob_all_json = '" + nj({row: 1, span: 12, stack: {dir: 'g', cols: {lg: 3, md: 2, sm: 1}}}) + "' WHERE zzzzsys_object_id = 'e2e_rbox'");
		const cols = async (w) => { await p.setViewportSize({width: w, height: 900}); await open(p, 'index.php?p=form&f=' + F + '&r=' + cus);
			await p.waitForSelector('#nuw_e2e_rbox .nuStack-g');
			return p.evaluate(() => getComputedStyle(document.querySelector('#nuw_e2e_rbox .nuStack-g')).gridTemplateColumns.split(' ').length); };
		const c = [await cols(1280), await cols(800), await cols(390)];
		expect(c.join(',') === '3,2,1', 'grid columns: ' + c.join(','));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.setViewportSize({width: 1280, height: 900});
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.grid_row_fit = async (s) => {
	// 0.8.1088: grid layout – three wide fields of one row stay in one line (spans scaled to 12), a row follows the column numbers
	const p = s.ga;
	const F = 'samplecustomer';
	const cus = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1");
	if (!cus) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-gridfit.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_object > ' + keep);
	const nj = (o) => JSON.stringify({nunext: o}).replace(/'/g, "''");
	const ids = ['cus_code', 'cus_name', 'cus_phone'];
	try {
		sql("UPDATE zzzzsys_form SET sfo_json = '" + nj({layout: 'grid'}) + "' WHERE zzzzsys_form_id = '" + F + "'");
		// same top, 700 px each: rounded one by one they gave 5 + 5 + 5 = 15 columns
		ids.forEach((id, i) => sql("UPDATE zzzzsys_object SET sob_all_top = 4000, sob_all_left = " + (10 + i * 710) + ", sob_all_width = 700, sob_all_json = NULL WHERE sob_all_zzzzsys_form_id = '" + F + "' AND sob_all_id = '" + id + "'"));
		const tops = async () => { await open(p, 'index.php?p=form&f=' + F + '&r=' + cus);
			return p.evaluate((l) => l.map((id) => { const e = document.getElementById('nuw_' + id); return e ? [Math.round(e.getBoundingClientRect().top), Math.round(e.getBoundingClientRect().left)] : null; }), ids); };
		let t = await tops();
		expect(t.every(Boolean) && t[0][0] === t[1][0] && t[1][0] === t[2][0], 'one line: ' + JSON.stringify(t));
		// grid row 10: column numbers in the opposite order of the left positions (as after dragging) → shown by column
		ids.forEach((id, i) => sql("UPDATE zzzzsys_object SET sob_all_json = '" + nj({row: 10, col: 30 - i * 10}) + "' WHERE sob_all_zzzzsys_form_id = '" + F + "' AND sob_all_id = '" + id + "'"));
		t = await tops();
		expect(t.every(Boolean) && t[2][1] < t[1][1] && t[1][1] < t[0][1] && t[0][0] === t[2][0], 'by column: ' + JSON.stringify(t));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.responsive_wrap_box = async (s) => {
	// 0.8.959: design mode – two selected objects → "Put in a new box" = a content box with the direction "one under the other" around them
	const p = s.ga;
	const F = 'samplecustomer';
	const cus = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1");
	if (!cus) { s.skip = 'no sample data'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-wrapbox.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_form zzzzsys_object zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	try {
		sql("UPDATE zzzzsys_form SET sfo_json = '{\"nunext\":{\"layout\":\"auto\"}}' WHERE zzzzsys_form_id = '" + F + "'");
		await open(p, 'index.php?p=form&f=' + F + '&r=' + cus);
		await open(p, 'index.php?p=form&f=' + F + '&r=' + cus + '&design=1');
		await p.waitForSelector('#nuw_cus_name');
		await p.click('#nuw_cus_code', {position: {x: 5, y: 5}});
		await p.click('#nuw_cus_name', {position: {x: 5, y: 5}, modifiers: ['Control']});
		await p.waitForSelector('#nuDsWrapBox');
		await p.click('#nuDsWrapBox');
		await p.waitForSelector('#nuConfirm.show .js-confirm-ok', {timeout: 5000}).catch(() => {});   // the form becomes a grid layout first
		if (await p.locator('#nuConfirm.show .js-confirm-ok').count()) await p.click('#nuConfirm.show .js-confirm-ok');
		await p.waitForSelector('.nuStack-v #nuw_cus_code', {state: 'attached', timeout: 15000});
		const r = await p.evaluate(() => { const st = document.querySelector('.nuStack-v'); return {code: !!st.querySelector('#nuw_cus_code'), name: !!st.querySelector('#nuw_cus_name'), box: st.closest('.nuWrap_contentbox').id}; });
		expect(r.code && r.name && /^nuw_box/.test(r.box), 'wrapped: ' + JSON.stringify(r));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep, {shell: '/bin/bash'});
	}
};

T.save_target_label = async (s) => {                   // 0.8.1300: the code side panels name where Save goes – Live / Live, draft open / Draft (rev n)
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'sampleorder'") !== '1') { s.skip = 'no sample data'; return; }
	const rec = sql('SELECT sample_order_id FROM sample_order LIMIT 1');
	const api = (body) => p.evaluate((b) => fetch(window.nuNext.base + '?p=builderapi', {method: 'POST', credentials: 'same-origin',
		headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.nuNext.csrf, 'Accept': 'application/json'}, body: JSON.stringify(b)}).then((r) => r.json()), body);
	const badge = async () => {
		await open(p, 'index.php?p=form&f=sampleorder&r=' + encodeURIComponent(rec));
		await p.keyboard.press('Control+Shift+B'); await p.waitForSelector('#nuEcpPane .nuSaveTarget');
		const b = await p.$eval('#nuEcpPane .nuSaveTarget', (e) => [e.getAttribute('data-mode'), e.textContent.trim(), e.getAttribute('aria-label') || '']);
		await p.keyboard.press('Escape');
		return b;
	};
	sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleorder'");
	try {
		let b = await badge();
		expect(b[0] === 'live' && b[1] !== '' && b[2].length > b[1].length, 'live label: ' + JSON.stringify(b));
		const st = await api({action: 'draft.start', form_id: 'sampleorder', note: 'e2e save target'});
		expect(st.ok, 'draft.start: ' + JSON.stringify(st).slice(0, 200));
		b = await badge();
		expect(b[0] === 'open', 'open draft not shown: ' + JSON.stringify(b));
		const id = sql("SELECT zzzzsys_nunext_form_draft_id FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleorder' AND status = 'open'");
		await api({action: 'draft.discard', form_id: 'sampleorder', draft_id: id});
		await api({action: 'draft.start', form_id: 'sampleorder', note: 'e2e save target', preview: true});
		b = await badge();
		expect(b[0] === 'draft' && /\d/.test(b[1]), 'previewed draft not shown: ' + JSON.stringify(b));
		await open(p, 'index.php?p=form&f=sampleorder');
		await p.keyboard.press('Control+Shift+B'); await p.waitForSelector('#nuBsqPane .nuSaveTarget');
		expect(await p.$eval('#nuBsqPane .nuSaveTarget', (e) => e.getAttribute('data-mode')) === 'draft', 'list pane: label missing');
		await p.keyboard.press('Escape');
		const id2 = sql("SELECT zzzzsys_nunext_form_draft_id FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleorder' AND status = 'open'");
		if (id2) await api({action: 'draft.discard', form_id: 'sampleorder', draft_id: id2});
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'sampleorder'");
	}
};

T.design_order_by_pos = async (s) => {                // 0.8.1301: design mode "Set order by position" – tab order follows the positions, one Undo step
	const p = s.ga;
	if (sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = 'samplecustomer'") !== '1') { s.skip = 'no sample data'; return; }
	const keep = sql("SELECT GROUP_CONCAT(CONCAT(zzzzsys_object_id, ':', sob_all_order)) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = 'samplecustomer'");
	sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer'");
	// Country (top 164) before Code (top 20) – out of position
	sql("UPDATE zzzzsys_object SET sob_all_order = 5 WHERE zzzzsys_object_id = 'sample_customer_o5'");
	try {
		await open(p, 'index.php?p=form&f=samplecustomer&r=-1&design=1');
		await p.waitForSelector('#nuDsOrderPos');
		await p.locator('label[for=nuDsTabOrder]').click(); await p.waitForTimeout(400);
		expect(await p.locator('#nuDsOrderFloat #nuDsOrderPosFloat').isVisible(), 'floating button missing while the numbers are shown');
		const nums = () => p.$$eval('#nuEditForm .tab-pane.active .nuWrap', (ws) => ws.filter((w) => w.querySelector(':scope > .nuDesignOrder')).map((w) => [w.id, +w.querySelector(':scope > .nuDesignOrder').textContent]));
		const before = await nums();
		expect(before[0] && before[0][0] === 'nuw_cus_country', 'set-up: country first – ' + JSON.stringify(before));
		await p.click('#nuDsOrderPosFloat'); await p.waitForTimeout(1500);
		const after = await nums();
		expect(after.map((x) => x[0]).join() === 'nuw_cus_code,nuw_cus_name,nuw_cus_email,nuw_cus_phone,nuw_cus_country,nuw_cus_active', 'order not by position: ' + JSON.stringify(after));
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer' AND status = 'open'") === '1', 'change not in a draft');
		await p.click('#nuDsOrderPos'); await p.waitForTimeout(600);
		expect((await p.textContent('#nuDsMsg')).trim() !== '', 'second click: no message');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer'");
		keep.split(',').forEach((x) => { const [id, o] = x.split(':'); sql(`UPDATE zzzzsys_object SET sob_all_order = ${+o} WHERE zzzzsys_object_id = '${id}'`); });
	}
};

T.design_box_select = async (s) => {                  // 0.8.1302: a selected content box stays behind its objects, tints them and moves them along
	const p = s.ga, F = 'samplecustomer';
	if (sql(`SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`) !== '1') { s.skip = 'no sample data'; return; }
	const meta = ['zzzzsys_object', 'zzzzsys_form', 'zzzzsys_nunext_form_history', 'zzzzsys_nunext_form_draft'];
	const keep = path.join(require('os').tmpdir(), 'nunext-e2e-boxsel.sql');
	execSync('mysqldump ' + DB + ' ' + meta.join(' ') + ' > ' + keep);
	const q = (id) => sql(`SELECT sob_all_left FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${F}' AND sob_all_id = '${id}'`);
	try {
		sql(`CREATE TEMPORARY TABLE e2e_bx AS SELECT * FROM zzzzsys_object WHERE zzzzsys_object_id = 'sample_customer_o1';
			UPDATE e2e_bx SET zzzzsys_object_id = 'e2e_box1', sob_all_id = 'e2e_box', sob_all_type = 'contentbox', sob_all_label = 'Box', sob_all_top = 5, sob_all_left = 20, sob_all_width = 620, sob_all_height = 230, sob_all_order = 1;
			INSERT INTO zzzzsys_object SELECT * FROM e2e_bx; UPDATE zzzzsys_form SET sfo_status = '0' WHERE zzzzsys_form_id = '${F}'; DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		const n0 = q('cus_name');
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuw_e2e_box .nuDesignEdgeL'); await p.waitForTimeout(700);
		const r = await (await p.$('#nuw_e2e_box .nuDesignEdgeL')).boundingBox();
		await p.mouse.click(r.x + 3, r.y + r.height / 2); await p.waitForTimeout(400);
		const st = await p.evaluate(() => { const n = document.getElementById('nuw_cus_name').getBoundingClientRect(), el = document.elementFromPoint(n.x + n.width / 2, n.y + n.height / 2);
			return {sel: document.getElementById('nuw_e2e_box').classList.contains('nuBuilderSelected'), top: el && el.closest('.nuWrap') ? el.closest('.nuWrap').id : '', tint: document.querySelectorAll('.nuDesignInBox').length}; });
		expect(st.sel, 'box not selected');
		expect(st.top === 'nuw_cus_name', 'a field inside the selected box is hidden: ' + st.top);
		expect(st.tint === 6, 'objects of the box not tinted: ' + st.tint);
		await p.locator('body').press('ArrowRight'); await p.waitForTimeout(1500);
		expect(q('e2e_box') === '25' && +q('cus_name') === +n0 + 5, 'arrow did not move the box with its objects: ' + q('e2e_box') + ' / ' + q('cus_name'));
		await p.click('#nuw_cus_email .nuDesignOverlay', {position: {x: 6, y: 6}}); await p.waitForTimeout(300);
		expect(await p.locator('#nuw_cus_email.nuBuilderSelected').count() === 1 && await p.locator('.nuDesignInBox').count() === 0, 'a field inside the box is not selectable');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.design_discard_empty = async (s) => {               // 0.8.1303: Discard enabled only with changes; an empty draft goes away with Done
	const p = s.ga, F = 'samplecustomer';
	if (sql(`SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`) !== '1') { s.skip = 'no sample data'; return; }
	const desc0 = sql(`SELECT sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`), st0 = sql(`SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`);
	const api = (body) => p.evaluate((b) => fetch(window.nuNext.base + '?p=builderapi', {method: 'POST', credentials: 'same-origin',
		headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.nuNext.csrf, 'Accept': 'application/json'}, body: JSON.stringify(b)}).then((r) => r.json()), body);
	sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'; UPDATE zzzzsys_form SET sfo_status = '1' WHERE zzzzsys_form_id = '${F}'`);
	try {
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsBar');
		const d = await api({action: 'draft.start', form_id: F, preview: true});
		const id = sql(`SELECT zzzzsys_nunext_form_draft_id FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status = 'open'`);
		expect(d.ok && id, 'draft.start');
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsDiscard');
		expect(await p.isVisible('#nuDsDiscard') && await p.isDisabled('#nuDsDiscard'), 'empty draft: Discard should be shown but disabled');
		const sv = await api({action: 'form.save', form_id: F, draft_id: id, rev: +sql(`SELECT rev FROM zzzzsys_nunext_form_draft WHERE zzzzsys_nunext_form_draft_id = '${id}'`), fields: {sfo_description: desc0 + ' e2e'}});
		expect(sv.ok, 'form.save in the draft: ' + JSON.stringify(sv).slice(0, 200));
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsDiscard');
		expect(!(await p.isDisabled('#nuDsDiscard')) && /1/.test(await p.getAttribute('#nuDsDiscard', 'title')), 'with a change Discard should be enabled (tooltip with the number)');
		// 0.8.1304: "Leave preview" of the draft preview bar explains itself on hover
		await open(p, 'index.php?p=form&f=' + F + '&r=-1');
		expect((await p.getAttribute('#nuDraftLeave', 'title') || '').length > 10, 'Leave preview without a tooltip');
		await api({action: 'draft.undo', form_id: F, draft_id: id});
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsDiscard');
		expect(await p.isDisabled('#nuDsDiscard'), 'after Undo back to the live form: Discard disabled');
		await Promise.all([p.waitForNavigation(), p.click('#nuDsDone')]);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status IN ('open', 'review')`) === '0', 'empty draft left behind after Done');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'; UPDATE zzzzsys_form SET sfo_status = '${st0}' WHERE zzzzsys_form_id = '${F}'`);
	}
};

T.design_panel_chips = async (s) => {                 // 0.8.1305: properties panel – section chips jump and mark, fold all, one-line summaries
	const p = s.ga, F = 'samplecustomer';
	if (sql(`SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`) !== '1') { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsChips');
	await p.click('#nuw_cus_name .nuDesignOverlay', {position: {x: 6, y: 6}}); await p.waitForTimeout(500);
	const chips = await p.$$eval('#nuDsChips .nuDsChip', (x) => x.map((c) => c.getAttribute('data-chip')));
	expect(chips.length >= 4 && chips.includes('layout') && chips.includes('style'), 'chips: ' + chips.join());
	await p.click('#nuDsChips [data-chip=style]'); await p.waitForTimeout(400);
	const st = await p.evaluate(() => { const pn = document.getElementById('nuDsPanel'), sec = pn.querySelector('section[data-sec-key=style]'), bar = document.getElementById('nuDsChips');
		return {active: (pn.querySelector('.nuDsChip.active') || {}).getAttribute && pn.querySelector('.nuDsChip.active').getAttribute('data-chip'), barTop: Math.round(bar.getBoundingClientRect().top - pn.getBoundingClientRect().top),
			secTop: Math.round(sec.getBoundingClientRect().top - bar.getBoundingClientRect().bottom), open: !!sec.querySelector('.nuDsSecBody')}; });
	expect(st.active === 'style' && st.barTop <= 1 && st.open && st.secTop > -4, 'jump to Style: ' + JSON.stringify(st));
	await p.click('#nuDsFoldAll'); await p.waitForTimeout(300);
	const folded = await p.$$eval('#nuDsPanel section[data-sec-key]', (x) => x.map((sec) => [sec.getAttribute('data-sec-key'), !!sec.querySelector('.nuDsSecBody'), (sec.querySelector('.nuDsSecSum') || {}).textContent || '']));
	expect(folded.every((x) => !x[1]), 'fold all: ' + JSON.stringify(folded));
	const lay = folded.find((x) => x[0] === 'layout');
	expect(lay && /\d/.test(lay[2]), 'folded Layout without a summary: ' + JSON.stringify(lay));
	// all folded fits the panel: the chips are hidden, only "unfold all" stays (chipsFit)
	const min = await p.evaluate(() => ({min: document.getElementById('nuDsPanel').classList.contains('nuDsChipsMin'), all: document.getElementById('nuDsFoldAll').offsetHeight > 0}));
	expect(min.min && min.all, 'all folded and in view: only unfold-all: ' + JSON.stringify(min));
	await p.click(min.min ? '#nuDsPanel .nuDsSecHead[data-sec=behaviour]' : '#nuDsChips [data-chip=behaviour]'); await p.waitForTimeout(300);
	expect(await p.locator('#nuDsPanel section[data-sec-key=behaviour] .nuDsSecBody').count() === 1, 'a folded section opens');
	await p.click('#nuDsFoldAll'); await p.waitForTimeout(300);           // one open: the button folds all …
	await p.click('#nuDsFoldAll'); await p.waitForTimeout(300);           // … all folded: it unfolds all
	expect(await p.locator('#nuDsPanel section[data-sec-key] .nuDsSecBody').count() >= 4, 'unfold all');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.design_done_publish_direct = async (s) => {
	// Done → Publish now publishes at once (no second publish window), also on User Home, with the default "ask" setting
	const p = s.ga, F = 'nuuserhome';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-done-direct.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		await p.evaluate(() => localStorage.removeItem('nunext-design-pubask'));
		await open(p, 'index.php?p=form&f=' + F + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		const tab = await p.evaluate(() => { const t = document.querySelectorAll('#nuTabHolder .nuTab'); return t.length ? t[t.length - 1].textContent.trim() : ''; });
		expect(tab !== '', 'no tab on User Home');
		await p.locator('#nuTabHolder .nuTab').last().dblclick(); await p.waitForTimeout(400);
		await p.keyboard.press('End'); await p.keyboard.type('E2E'); await p.keyboard.press('Enter');
		await p.waitForTimeout(1500);
		await p.click('#nuDsDone'); await p.waitForSelector('#nuDsDoneDlg.show');
		await p.click('#nuDsDonePublish');
		await p.waitForURL(u => !/design=1/.test(String(u)), { timeout: 10000 }).catch(() => {});
		await p.waitForTimeout(800);
		expect(await p.locator('#nuDsPublishDlg.show').count() === 0, 'a second publish window opened');
		expect(await p.locator('#nuDsBar').count() === 0, 'Publish now did not leave design mode');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' AND syt_title = '${tab}E2E'`) === '1', 'tab name not published');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}' AND status IN ('open', 'review')`) === '0', 'draft still open');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep); fs.unlinkSync(keep);
	}
};

T.design_publish_old_problems = async (s) => {
	// a run button to a form that no longer exists (left over on an older database) must not stop publishing an unrelated change:
	// it is a warning, shown on top of the publish window, and "Publish anyway" publishes
	const p = s.ga, F = 'nuuserhome';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-design-oldprob.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_object zzzzsys_tab zzzzsys_form zzzzsys_nunext_form_history zzzzsys_nunext_form_draft > ' + keep);
	try {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${F}'`);
		sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_type, sob_all_id, sob_all_label, sob_all_zzzzsys_tab_id, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_run_type, sob_run_zzzzsys_form_id) VALUES ('e2e_oldrun', '${F}', 'run', 'e2e_oldrun', 'Old', 'nufastforms', 99, 10, 10, 120, 30, 'F', 'e2e_form_gone')`);
		await p.evaluate(() => localStorage.removeItem('nunext-design-pubask'));
		await open(p, 'index.php?p=form&f=' + F + '&design=1'); await p.waitForSelector('#nuDsBar'); await p.waitForTimeout(800);
		const tab = await p.evaluate(() => { const t = document.querySelectorAll('#nuTabHolder .nuTab'); return t.length ? t[t.length - 1].textContent.trim() : ''; });
		await p.locator('#nuTabHolder .nuTab').last().dblclick(); await p.waitForTimeout(400);
		await p.keyboard.press('End'); await p.keyboard.type('E2E'); await p.keyboard.press('Enter');
		await p.waitForTimeout(1500);
		await p.click('#nuDsPublish'); await p.waitForSelector('#nuDsPublishDlg.show');
		await p.click('#nuDsPubOk'); await p.waitForSelector('#nuDsPubInfo .alert-warning', { timeout: 8000 });
		expect(/e2e_form_gone/.test(await p.textContent('#nuDsPubInfo')), 'warning text: ' + await p.textContent('#nuDsPubInfo'));
		const vis = await p.evaluate(() => { const b = document.querySelector('#nuDsPubInfo').getBoundingClientRect(), m = document.querySelector('#nuDsPublishDlg .modal-body').getBoundingClientRect(); return b.top >= m.top - 1 && b.top < m.bottom; });
		expect(vis, 'the answer is not in view');
		await p.click('#nuDsPubOk'); await p.waitForTimeout(2500);
		expect(await p.locator('#nuDsPublishDlg.show').count() === 0, 'window stayed: ' + await p.textContent('#nuDsPubInfo').catch(() => '?'));
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${F}' AND syt_title = '${tab}E2E'`) === '1', 'not published');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB + ' < ' + keep); fs.unlinkSync(keep);
	}
};

// 0.8.1343 (review P6): sub-forms and lookup forms of a page loaded together, hooks in one statement – same definitions as one by one
T.form_prime = async (s) => {
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const php = (code) => require('child_process').execFileSync('php', ['-r', 'chdir(' + JSON.stringify(SITE) + "); require 'core/nuapp.php'; " + code]).toString();
	const out = JSON.parse(php(`$ids = ['samplecustomer', 'sampleorderitem', 'sampleproduct'];
		$dump = static function (string $id): string { $m = NuFormMeta::load($id); return $m ? json_encode([$m->form, $m->tabs, $m->objects, $m->events, $m->browse,
			$m->php('BB'), $m->php('BE'), $m->php('BS')]) : 'null'; };
		$one = []; foreach ($ids as $id) { NuFormMeta::reset(); NuMemo::reset(); $one[$id] = $dump($id); }
		NuFormMeta::reset(); NuMemo::reset();
		NuFormMeta::load('sampleorder'); $a = NuDB::$statements;
		$sub = NuFormMeta::load('sampleorderitem'); $b = NuDB::$statements;
		NuFormMeta::load('samplecustomer'); $c = NuDB::$statements;
		$sub->php('BE'); NuFormMeta::load('sampleorder')->php('BS'); $d = NuDB::$statements;
		NuFormMeta::load('sampleproduct'); $e = NuDB::$statements;
		$same = []; foreach ($ids as $id) $same[$id] = $dump($id) === $one[$id];
		echo json_encode(['round' => $b - $a, 'second' => $c - $b, 'hooks' => $d - $c, 'nested' => $e - $d, 'same' => $same]);`));
	expect(out.round === 5, 'sub-form + lookup form not loaded together (statements ' + out.round + ')');
	expect(out.second === 0, 'the second form of the round was read again: ' + out.second);
	expect(out.hooks === 1, 'hooks of the loaded forms not read in one statement: ' + out.hooks);
	expect(out.nested === 5, 'lookup inside the sub-form: ' + out.nested);
	for (const [id, ok] of Object.entries(out.same)) expect(ok, id + ': definition loaded together differs from loaded alone');
	// the order record draws the same page and asks fewer statements
	await open(s.ga, 'index.php?p=form&f=sampleorder&r=sample_ord_1');
	const n = +(await s.ga.locator('#nuPerf').getAttribute('data-statements').catch(() => '0'));
	expect(n > 0 && n <= 52, 'order record: ' + n + ' statements');
	expect(await s.ga.locator('[data-nu-type=display]').count() >= 1, 'display object missing');
};

// 0.8.1344: nuForm('code:<form code>', …) as nuBuilder Forte – the form is found by its code
T.form_code_prefix = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer');
	await p.evaluate(() => nuForm('code:' + nuNext.form.code, '', '', '', '0'));
	await p.waitForURL(/f=code/, { timeout: 15000 });
	await p.waitForLoadState('load');
	expect(await p.locator('#nuBrowseTable').count() === 1, 'form opened with code: not found');
	const r = await p.request.get(BASE + 'index.php?p=form&f=code:no_such_form_e2e');
	expect(r.status() === 404, 'unknown code: ' + r.status());
};

T.new_form_shown = async (s) => {                      // 0.8.1412: New form wizard – "Shown as" per column (select with options, lookup, Yes / No)
	const p = s.ga, code = 'e2eShown' + Date.now().toString(36), tbl = 'e2e_shown_' + Date.now().toString(36), code2 = code + 'x';
	const ids = [];
	const pickShown = (row, v) => row.locator('select.nbShown').selectOption(v);
	try {
		// new table: a select with options and a lookup on the sample customers
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		await p.fill('#nb_desc', 'E2E shown'); await p.fill('#nb_code', code);
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await p.click('label[for="nb_mode_new"]');
		await p.fill('#nb_new_table', tbl);
		let rows = p.locator('#nbColRows > tr:not(.nbExtra)');
		await rows.nth(0).locator('[data-f=name]').fill('e2e_status');
		await rows.nth(0).locator('[data-f=type]').selectOption('int');
		expect((await rows.nth(0).locator('select.nbShown option').allTextContents()).length === 4, 'int offers four types');
		await pickShown(rows.nth(0), 'select');
		const x0 = p.locator('#nbColRows > tr.nbExtra').nth(0);
		expect(await x0.isVisible() && await x0.locator('.nbShownOpts').count() === 1, 'no options line below a select');
		await x0.locator('.nbShownOpts').fill('1|Open|2|Closed');
		await p.click('#nbAddCol');
		rows = p.locator('#nbColRows > tr:not(.nbExtra)');
		await rows.nth(1).locator('[data-f=name]').fill('e2e_customer_id');
		await rows.nth(1).locator('[data-f=type]').selectOption('id');
		await pickShown(rows.nth(1), 'lookup');
		const x1 = rows.nth(1).locator('xpath=following-sibling::tr[1]');
		expect(await x1.evaluate((e) => e.classList.contains('nbExtra')), 'settings line not right below its row');
		await x1.locator('select.nbShownLookup').evaluate((e) => { if (e.tomselect) e.tomselect.setValue('samplecustomer'); else { e.value = 'samplecustomer'; e.dispatchEvent(new Event('change')); } });
		// moving the row up takes the settings line along
		await rows.nth(1).locator('button[aria-label]').first().click();
		const seq = await p.$$eval('#nbColRows > tr', (r) => r.map((x) => x.classList.contains('nbExtra') ? 'x' : x.querySelector('[data-f=name]').value));
		expect(seq.join(',') === 'e2e_customer_id,x,e2e_status,x', 'order after moving: ' + seq.join(','));
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await Promise.all([p.waitForURL(/design=1/), p.click('#nbCreate')]);
		ids.push(sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code}'`));
		const o = (col, f) => sql(`SELECT ${col} FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${ids[0]}' AND sob_all_id = '${f}'`);
		expect(o('sob_all_type', 'e2e_status') === 'select' && o('sob_select_sql', 'e2e_status') === '1|Open|2|Closed' && o('sob_select_multiple', 'e2e_status') === '0', 'select not made');
		expect(o('sob_all_type', 'e2e_customer_id') === 'lookup' && o('sob_lookup_zzzzsys_form_id', 'e2e_customer_id') === 'samplecustomer'
			&& o('sob_lookup_code', 'e2e_customer_id') !== '' && o('sob_lookup_description', 'e2e_customer_id') !== '', 'lookup not made');
		// existing table: a Yes / No select for the checkbox column, a multiselect without options
		await open(p, 'index.php?p=builder&new=1&start=wizard');
		await p.fill('#nb_desc', 'E2E shown 2'); await p.fill('#nb_code', code2);
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await p.waitForFunction(() => document.querySelector('#nb_table option[value="sample_customer"]'));
		await p.evaluate(() => { const x = document.getElementById('nb_table'); if (x.tomselect) x.tomselect.setValue('sample_customer'); else { x.value = 'sample_customer'; x.dispatchEvent(new Event('change')); } });
		await p.waitForSelector('#nbPickRows tr[data-name="cus_active"]', {state: 'attached'});
		await p.click('#nbPickBtn'); await p.waitForTimeout(400);
		const row = (n) => p.locator(`#nbPickRows tr[data-name="${n}"]`);
		expect((await row('cus_active').locator('select.nbShown').inputValue()) === 'checkbox', 'tinyint(1) not a checkbox by default');
		await pickShown(row('cus_active'), 'yesno');
		await pickShown(row('cus_country'), 'multiselect');
		await row('cus_country').locator('.nbPickOn').uncheck();
		expect(await row('cus_country').locator('select.nbShown').isDisabled(), 'shown as of an unticked column enabled');
		await row('cus_country').locator('.nbPickOn').check();
		await p.click('#nbStepNext'); await p.waitForTimeout(200);
		await Promise.all([p.waitForURL(/design=1/), p.click('#nbCreate')]);
		ids.push(sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${code2}'`));
		const o2 = (col, f) => sql(`SELECT ${col} FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${ids[1]}' AND sob_all_id = '${f}'`);
		expect(o2('sob_all_type', 'cus_active') === 'select' && /^1\|.+\|0\|.+$/.test(o2('sob_select_sql', 'cus_active')), 'yes / no select not made');
		expect(o2('sob_all_type', 'cus_country') === 'select' && o2('sob_select_multiple', 'cus_country') === '1', 'multiselect not made');
		expect(o2('sob_all_type', 'cus_name') === 'input', 'an unchanged column changed');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		for (const c of [code, code2]) { const id = sql(`SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = '${c}'`); if (id) ids.push(id); }
		for (const id of [...new Set(ids.filter(Boolean))]) {
			sql(`DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '${id}'`); sql(`DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '${id}'`);
			sql(`DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '${id}'`); sql(`DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = '${id}'`);
			sql(`DELETE FROM zzzzsys_php WHERE zzzzsys_php_id LIKE '${id}%'`); sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${id}'`); sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`);
		}
		sql(`DROP TABLE IF EXISTS ${tbl}`);
	}
};

T.design_list_work_stays = async (s) => {               // 0.8.1414: editing the Browse list workspace on a list page – no reload per field, the focus stays
	const p = s.ga, f = 'samplecustomer';
	const sel = (q) => sql(q);
	sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}'`);
	let loads = 0;
	const onLoad = () => { loads++; };
	try {
		await open(p, `index.php?p=form&f=${f}&design=1&dswork=list`);
		await p.waitForSelector('#nuDsListCols tr.nuDsListCol');
		p.on('load', onLoad);
		const first = p.locator('#nuDsListCols tr.nuDsListCol').first();
		const was = await first.locator('input[data-lc=title]').inputValue();
		await first.locator('input[data-lc=title]').fill(was + ' X');
		await p.keyboard.press('Tab');                      // to the next field: display
		await p.waitForFunction(() => /saved|gespeichert/i.test(document.getElementById('nuDsMsg').textContent), null, {timeout: 8000}).catch(() => {});
		await p.waitForTimeout(600);
		expect(loads === 0, 'the page reloaded after a field (' + loads + ')');
		expect(await p.locator('#nuDsWork').isVisible(), 'workspace closed');
		expect(await p.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-lc')) === 'display', 'focus not on the next field: '
			+ await p.evaluate(() => document.activeElement && document.activeElement.outerHTML.slice(0, 80)));
		// a second field in a row
		await p.keyboard.press('Tab');                      // width
		await p.keyboard.type('77');
		await p.keyboard.press('Tab');
		await p.waitForTimeout(1500);
		expect(loads === 0 && await p.locator('#nuDsWork').isVisible(), 'second change closed the workspace');
		const draft = sel(`SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}'`);
		expect(draft.includes(was + ' X') && /"sbr_width":"?\d*77/.test(draft), 'changes not in the draft');
		// back to the form: the list is drawn again with the change
		await Promise.all([p.waitForEvent('load'), p.click('#nuDsWorkClose')]);
		await p.waitForSelector('#nuBrowseTable');
		expect((await p.locator('#nuBrowseTable thead th').allTextContents()).some((x) => x.includes(was + ' X')), 'list not drawn again after closing');
	} finally {
		p.off('load', onLoad);
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}'`);
	}
};

T.design_audit_switch = async (s) => {                  // 0.8.1417: design mode → Data → "Audit columns" shows / sets sfo_json nunext.audit
	const p = s.ga, f = 'samplecustomer';
	if (sql(`SELECT sfo_table FROM zzzzsys_form WHERE zzzzsys_form_id = '${f}'`) !== 'sample_customer') { s.skip = 'no sample customers'; return; }
	const keep = path.join(os.tmpdir(), 'nunext-e2e-aud-' + process.pid + '.sql');
	execSync('mysqldump ' + DB + ' sample_customer zzzzsys_form > ' + keep);
	sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}'`);
	const sw = '#nuDsPanel input[data-key="audit"]';
	const openData = async () => {
		await open(p, `index.php?p=form&f=${f}&r=sample_cus_01&design=1`);
		await p.waitForSelector('#nuDsPanel');
		await p.evaluate(() => { const b = [...document.querySelectorAll('#nuDsPanel [data-sec-key="data"]')][0]; if (b && b.tagName !== 'SECTION' && b.getAttribute('aria-expanded') === 'false') b.click(); });
		await p.waitForSelector(sw, { state: 'attached' });
	};
	try {
		await openData();
		expect(await p.$eval(sw, (a) => a.disabled && !a.checked), 'switch not disabled on a table without the columns');
		sql("ALTER TABLE sample_customer ADD created_at DATETIME NULL, ADD created_by VARCHAR(50) NULL, ADD updated_at DATETIME NULL, ADD updated_by VARCHAR(50) NULL");
		await openData();
		expect(await p.$eval(sw, (a) => !a.disabled && !a.checked), 'switch not usable with the columns');
		expect(/all four|alle vier/i.test(await p.$eval(sw, (a) => a.parentNode.querySelector('.nuInfoTip').getAttribute('aria-label'))), 'tip does not say the columns are there');
		await p.$eval(sw, (a) => a.click());
		await p.waitForTimeout(1500);
		const snap = sql(`SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}' AND status = 'open'`) || '';
		expect(/audit[\\"]*:\s*true/.test(snap), 'draft has no audit = true');
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}'`);
		// stored on the live form: the switch shows it, and a save fills the columns
		sql(`UPDATE zzzzsys_form SET sfo_json = '{"nunext":{"audit":true}}' WHERE zzzzsys_form_id = '${f}'`);
		await openData();
		expect(await p.$eval(sw, (a) => a.checked), 'switch off although the form has audit = true');
		await open(p, `index.php?p=form&f=${f}&r=sample_cus_01`);
		await p.fill('#cus_email', 'audit' + Date.now() + '@example.com');
		await save(p);
		expect(sql("SELECT updated_by FROM sample_customer WHERE sample_customer_id = 'sample_cus_01'") === 'globeadmin', 'updated_by not filled: ' + sql("SELECT CONCAT_WS('/', IFNULL(updated_by,'-'), cus_email) FROM sample_customer WHERE sample_customer_id = 'sample_cus_01'") + ' ' + p.url());
	} finally {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}'`);
		sql('DROP TABLE IF EXISTS sample_customer');
		execSync('mysql ' + DB + ' < ' + keep); fs.unlinkSync(keep);
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.design_list_width_zero = async (s) => {               // 0.8.1419: Browse list workspace – typing width 0 hides the column (it became 100 before)
	const p = s.ga, f = 'samplecustomer';
	sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}'`);
	try {
		await open(p, `index.php?p=form&f=${f}&design=1&dswork=list`);
		await p.waitForSelector('#nuDsListCols tr.nuDsListCol');
		// a column that is shown (width input enabled)
		const rows = p.locator('#nuDsListCols tr.nuDsListCol');
		let i = 0;
		while (i < await rows.count() && await rows.nth(i).locator('input[data-lc=width]').isDisabled()) i++;
		const disp = await rows.nth(i).locator('[data-lc=display]').inputValue();
		const w = rows.nth(i).locator('input[data-lc=width]');
		await w.click(); await w.fill('0'); await p.keyboard.press('Tab');
		await p.waitForFunction((n) => { const r = [...document.querySelectorAll('#nuDsListCols tr.nuDsListCol')].find((x) => (x.querySelector('[data-lc=display]') || {}).value === n);
			return r && r.querySelector('button i.bi-eye-slash'); }, disp, { timeout: 8000 }).catch(() => {});
		const shown = await p.evaluate((n) => { const r = [...document.querySelectorAll('#nuDsListCols tr.nuDsListCol')].find((x) => (x.querySelector('[data-lc=display]') || {}).value === n);
			return r ? { slash: !!r.querySelector('button i.bi-eye-slash'), off: r.querySelector('input[data-lc=width]').disabled } : null; }, disp);
		expect(shown && shown.slash && shown.off, 'column ' + disp + ' not hidden after width 0: ' + JSON.stringify(shown));
	} finally {
		sql(`DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = '${f}'`);
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// design mode: the properties panel gets wider by dragging its left edge (stored per browser, double-click = standard width);
// from 620 px its sections flow into two columns
T.design_panel_resize = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	if (sql(`SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`) !== '1') { s.skip = 'no sample data'; return; }
	await p.setViewportSize({width: 1600, height: 900});
	try {
		await p.evaluate(() => { try { localStorage.removeItem('nunext-ds-panel-w'); } catch (e) { /* none */ } });
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsSplit');
		const info = () => p.evaluate(() => { const pn = document.getElementById('nuDsPanel'), c = pn.querySelector('.nuDsSecCols');
			return {w: Math.round(pn.getBoundingClientRect().width), cols: c ? getComputedStyle(c).columnCount : 'none',
				form: Math.round(document.querySelector('.nuFormContainer').getBoundingClientRect().right)}; });
		const a = await info();
		expect(a.w === 340 && a.cols !== '2', 'standard: one column: ' + JSON.stringify(a));
		const b0 = await p.$eval('#nuDsSplit', (x) => { const r = x.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; });
		await p.mouse.move(b0[0], b0[1]); await p.mouse.down(); await p.mouse.move(b0[0] - 200, b0[1], {steps: 6}); await p.mouse.move(b0[0] - 380, b0[1], {steps: 6}); await p.mouse.up();
		await p.waitForTimeout(300);
		const b = await info();
		expect(Math.abs(b.w - 720) <= 4 && b.cols === '2' && b.form <= 1600 - 720 + 2, 'dragged wider: two columns, form makes room: ' + JSON.stringify(b));
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsSplit');
		const c = await info();
		expect(Math.abs(c.w - 720) <= 4, 'width kept after a reload: ' + JSON.stringify(c));
		await p.click('#nuw_cus_name .nuDesignOverlay', {position: {x: 6, y: 6}}); await p.waitForTimeout(500);
		const chipsShown = () => p.evaluate(() => { const ch = document.querySelector('#nuDsChips .nuDsChip'); return !!ch && ch.offsetHeight > 0; });
		const fits = await p.evaluate(() => { const pn = document.getElementById('nuDsPanel'); return pn.scrollHeight <= pn.clientHeight + 1; });
		expect(fits && !(await chipsShown()), 'everything fits: no section chips (fits ' + fits + ')');
		await p.dblclick('#nuDsSplit'); await p.waitForTimeout(200);
		const d = await info();
		expect(d.w === 340 && d.cols !== '2', 'double-click: standard width: ' + JSON.stringify(d));
		expect(await chipsShown(), 'narrow panel scrolls: chips back');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		await p.evaluate(() => { try { localStorage.removeItem('nunext-ds-panel-w'); } catch (e) { /* none */ } });
		await p.setViewportSize({width: 1300, height: 900});
	}
};

// design mode Code workspace: switching parts never shows the marks of a check in the previous part's language
// (swapDoc ran the JavaScript check on the PHP text for a moment – a red line that vanished again)
T.design_code_switch_nomarks = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	await open(p, 'index.php?p=form&f=' + F + '&r=' + rec + '&design=1'); await p.waitForSelector('#nuDsRail'); await p.waitForTimeout(700);
	await p.click('#nuDsRailCode'); await p.waitForSelector('#nuDsWork .CodeMirror'); await p.waitForTimeout(300);
	const cmSet = (v) => p.evaluate((x) => { document.querySelector('#nuDsWork .CodeMirror').CodeMirror.setValue(x); }, v);
	await p.click('.nuDsCodeItem[data-code="php:AS"]'); await p.waitForTimeout(200);
	await cmSet("$a = ['k' => 1];\necho \"#x#\" . $a['k'];\n");          // valid PHP, invalid JavaScript
	await p.click('.nuDsCodeItem[data-code="sfo_javascript"]'); await p.waitForTimeout(200);
	await cmSet('var ok = 1;\n');
	await p.waitForTimeout(1200);                                     // checks settle
	await p.evaluate(() => {
		window.__marks = 0;
		new MutationObserver(() => { if (document.querySelector('#nuDsWork .nuChkMark.nuChk-error')) window.__marks++; })
			.observe(document.getElementById('nuDsWork'), {subtree: true, childList: true});
	});
	for (let i = 0; i < 3; i++) {
		await p.click('.nuDsCodeItem[data-code="php:AS"]'); await p.waitForTimeout(400);
		await p.click('.nuDsCodeItem[data-code="sfo_javascript"]'); await p.waitForTimeout(400);
	}
	await p.click('.nuDsCodeItem[data-code="php:AS"]'); await p.waitForTimeout(2500);   // the PHP check (server) has answered
	const n = await p.evaluate(() => [window.__marks, document.querySelectorAll('#nuDsWork .nuChkMark.nuChk-error').length]);
	expect(n[0] === 0 && n[1] === 0, 'no error marks while switching (seen ' + n[0] + ', now ' + n[1] + ')');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// ▶ Run in the browse SQL editors: the SQL as written runs read only, a window shows the rows with paging; errors with their line;
// only a SELECT; only for those who may design the form (pages/nubrowserun.php)
T.browse_sql_run = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	if (sql(`SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = '${F}'`) !== '1') { s.skip = 'no sample data'; return; }
	const n = +sql('SELECT COUNT(*) FROM sample_customer');
	await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsRail');
	await p.click('#nuDsRailCode'); await p.waitForSelector('#nuDsWork .CodeMirror');
	await p.click('.nuDsCodeItem[data-code="sfo_browse_sql"]'); await p.waitForTimeout(300);
	const setSql = (v) => p.evaluate((x) => document.querySelector('#nuDsWork .CodeMirror').CodeMirror.setValue(x), v);
	await setSql('SELECT * FROM sample_customer ORDER BY cus_code');
	await p.click('#nuDsWork .nuCtRun');
	await p.waitForFunction(() => /\d/.test((document.getElementById('nuBrunInfo') || {}).textContent || ''), null, {timeout: 8000});
	const a = await p.evaluate(() => ({rows: document.querySelectorAll('#nuBrunTable tbody tr').length, count: document.getElementById('nuBrunCount').textContent,
		first: (document.querySelector('#nuBrunTable tbody td:nth-child(2)') || {}).textContent}));
	expect(a.rows === Math.min(10, n) && a.count.includes(String(n)), 'rows and count: ' + JSON.stringify(a) + ' of ' + n);
	if (n > 10) {
		await p.click('#nuBrunNext'); await p.waitForTimeout(800);
		expect(await p.locator('#nuBrunTable tbody tr').count() === Math.min(10, n - 10), 'second page');
	}
	await p.click('#nuBrunDlg .btn-close'); await p.waitForTimeout(500);
	expect(await p.locator('#nuDsWork .CodeMirror').isVisible(), 'the Code workspace stays open after closing the result');
	// an error names its line; Ctrl+Enter runs too
	await setSql('SELECT *\nFROM sample_customer\nWHERE no_such_column = 1');
	await p.click('#nuDsWork .CodeMirror'); await p.keyboard.press('Control+Enter');
	await p.waitForSelector('#nuBrunErr:not([hidden])', {timeout: 8000});
	expect(/no_such_column/.test(await p.textContent('#nuBrunErr')), 'error text: ' + await p.textContent('#nuBrunErr'));
	await p.click('#nuBrunDlg .btn-close'); await p.waitForTimeout(500);
	// only a SELECT: nothing is changed
	const before = sql("SELECT cus_name FROM sample_customer ORDER BY sample_customer_id LIMIT 1");
	const r = await p.evaluate(async (f) => {
		const b = new URLSearchParams({f, sql: "UPDATE sample_customer SET cus_name = 'x'", page: '1', per: '10', props: '{}'});
		return (await NuUtil.post('index.php?p=browserun', b));
	}, F);
	expect(r && r.ok === false && sql("SELECT cus_name FROM sample_customer ORDER BY sample_customer_id LIMIT 1") === before, 'UPDATE refused: ' + JSON.stringify(r));
	// a user who may not design the form
	const u = await login('sample_ben', UPW);
	try {
		await open(u, 'index.php?p=form&f=' + F);
		const st = await u.evaluate(async (f) => (await fetch('index.php?p=browserun', {method: 'POST', body: new URLSearchParams({f, sql: 'SELECT 1', _csrf: (window.nuNext || {}).csrf || ''})})).status, F);
		expect(st === 403, 'user without design right: ' + st);
	} finally { await u.context().close(); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// Design ▾ of a list is one split button: no gap, square inner corners (the shared toolbar look rounded both halves)
T.design_split_joined = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=form&f=samplecustomer');
	const r = await p.evaluate(() => {
		const a = document.getElementById('nuDesignButton'), b = document.getElementById('nuDesignMore');
		if (!a || !b) return null;
		const ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect(), ca = getComputedStyle(a), cb = getComputedStyle(b);
		return { gap: Math.round(rb.left - ra.right), aR: ca.borderTopRightRadius, bL: cb.borderTopLeftRadius, aL: ca.borderTopLeftRadius, bR: cb.borderTopRightRadius };
	});
	expect(r && r.gap <= 0 && r.aR === '0px' && r.bL === '0px' && r.aL !== '0px' && r.bR !== '0px', 'split button joined: ' + JSON.stringify(r));
};
