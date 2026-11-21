// e2e tests: AI: form, report, prompts, providers – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

T.form_ai_tables = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=formai&f=samplecustomer');
	expect(await p.locator('#faTables option[value="sample_customer"][selected]').count() === 1 && await p.locator('#faTm_pick').isChecked(), 'the form\'s table is not preselected');
	const prompt = (tables) => p.evaluate(async (tb) => { const b = new URLSearchParams(); b.set('_csrf', document.querySelector('input[name=_csrf]').value); b.set('action', 'prompt'); b.set('request', 'add a field');
		tb.forEach(x => b.append('tables[]', x)); return (await fetch(location.href, { method: 'POST', body: b })).json(); }, tables);
	let r = await prompt(['sample_customer']);
	expect(r.ok && /\n- sample_customer: /.test(r.text) && !/\n- sample_product: /.test(r.text), 'only the chosen table: ' + (r.text || '').slice(0, 80));
	r = await prompt([]);
	expect(r.ok && /\n- sample_product: /.test(r.text), 'none chosen = all tables');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.form_ai = async (s) => {                              // 0.8.403: form with AI – prompt, check, new form as Draft, change as form draft
	const p = s.ga;
	const spec = {format: 'nunext-form-1', form: {code: 'e2e_ai_project', description: 'E2E projects', type: 'browseedit', table: 'e2e_ai_project', primary_key: 'e2e_ai_project_id'},
		tabs: [{title: 'Main', objects: [
			{id: 'prj_name', type: 'input', input: 'text', label: 'Name', required: true},
			{id: 'prj_customer_id', type: 'lookup', label: 'Customer', lookup: {form: 'sample_customer', code: 'cus_code', description: 'cus_name'}},
			{id: 'prj_start', type: 'input', input: 'nuDate', label: 'Start'},
			{id: 'prj_status', type: 'select', label: 'Status', options: [['open', 'Open'], ['done', 'Done']]}]}]};
	try {
		await open(p, 'index.php?p=forms');
		expect(await p.locator('#nuFormAiNew').count() === 0, '"With AI" button still in the forms list (0.8.848: Add asks)');
		await open(p, 'index.php?p=formai');
		await p.fill('#faRequest', 'Projects with a customer');
		await p.click('#faNext1'); await p.click('#faCopy'); await p.waitForTimeout(800);
		const prompt = await p.inputValue('#faPrompt');
		expect(/nunext-form-1/.test(prompt) && /sample_customer/.test(prompt) && /Projects with a customer/.test(prompt), 'prompt incomplete');
		// errors are listed and nothing is offered
		await p.evaluate(() => window.nuFormAiStep(3));
		await p.fill('#faAnswer', '```json\n{"format": "nunext-form-1", "form": {"code": "x y"}, "tabs": []}\n```'); await p.click('#faCheck'); await p.waitForTimeout(700);
		expect(await p.locator('#faMessages .alert-danger li').count() >= 2 && await p.locator('#faCreateBox').isHidden(), 'bad answer not refused');
		// a new form with a new table
		await p.fill('#faAnswer', 'Sure:\n```json\n' + JSON.stringify(spec) + '\n```'); await p.click('#faCheck'); await p.waitForTimeout(700);
		expect(await p.locator('#faOk').count() === 1 && /e2e_ai_project/.test(await p.textContent('#faPlan')), 'valid answer not accepted: ' + await p.textContent('#faMessages'));
		await p.click('#faCreate'); await p.waitForTimeout(500);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = 'e2e_ai_project'") === '0', 'created without ticking the table box');
		await p.check('#faDdl'); await p.click('#faCreate'); await p.waitForURL(/design=1/); await p.waitForLoadState('networkidle');
		expect(sql("SELECT sfo_status FROM zzzzsys_form WHERE sfo_code = 'e2e_ai_project'") === '0', 'new form not a Draft');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id WHERE f.sfo_code = 'e2e_ai_project'") === '4', 'objects missing');
		expect(/prj_customer_id/.test(sql("SHOW COLUMNS FROM e2e_ai_project")), 'table not created');
		// change a form: a form draft, the live form stays
		await open(p, 'index.php?p=formai&f=samplecustomer');   // 0.8.810: design mode's #nuDsAi opens the Ask AI dialog (test form_ai_design)
		await p.fill('#faRequest', 'Add Mobile after Phone'); await p.click('#faNext1'); await p.click('#faCopy'); await p.waitForTimeout(800);
		const cur = JSON.parse((await p.inputValue('#faPrompt')).match(/## The current form\n```json\n([\s\S]*?)\n```/)[1]);
		const main = cur.tabs[0];
		main.objects.splice(main.objects.findIndex(o => o.id === 'cus_phone') + 1, 0, {id: 'e2e_mobile', type: 'input', input: 'tel', label: 'Mobile'});
		await p.evaluate(() => window.nuFormAiStep(3));
		await p.fill('#faAnswer', JSON.stringify(cur)); await p.click('#faCheck'); await p.waitForTimeout(700);
		const plan = await p.textContent('#faPlan');
		expect(/e2e_mobile/.test(plan) && !/cus_name|cus_code/.test(plan.replace(/.*e2e_mobile/s, '')), 'plan of the change: ' + plan);
		await p.check('#faDdl'); await p.click('#faCreate'); await p.waitForURL(/design=1/); await p.waitForLoadState('networkidle');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_id = 'e2e_mobile'") === '0', 'the live form was changed');
		expect(/e2e_mobile/.test(sql("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer' AND status = 'open'")), 'draft without the new object');
	} finally {
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = 'samplecustomer' AND status = 'open'");
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'e2e_ai_project'");
		if (fid) sql("DELETE FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = '" + fid + "'; DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = '" + fid + "'; DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = '" + fid + "'; DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '" + fid + "'");
		sql("DROP TABLE IF EXISTS e2e_ai_project");
		try { sql("ALTER TABLE sample_customer DROP COLUMN e2e_mobile"); } catch (e) { /* not added */ }
	}
};

T.form_ai_code = async (s) => {
	const p = s.ga;
	const spec = {format: 'nunext-form-1', form: {code: 'e2e_ai_code', description: 'E2E AI code', type: 'browseedit', table: 'e2e_ai_code', primary_key: 'e2e_ai_code_id'},
		tabs: [{title: 'Main', objects: [
			{id: 'aic_name', type: 'input', input: 'text', label: 'Name', events: [{event: 'onchange', js: "nuSetValue('aic_note', 'x');"}]},
			{id: 'aic_note', type: 'textarea', label: 'Note'}]}],
		code: {javascript: "function nuOnLoad() {\n  console.log('e2e');\n}", style: '.aic_name { color: red; }', php: {BS: "if ('#aic_name#' == '') nuDisplayError('Name');"}}};
	await aiKeep(async () => {
		await open(p, 'index.php?p=formai');
		let r = await faCheck(p, Object.assign({}, spec, {code: {php: {BS: 'if ( { nope'}}}));
		expect(!r.ok && /PHP BS/.test(r.msg), 'PHP syntax error not reported: ' + r.msg);
		r = await faCheck(p, spec);
		expect(r.ok && /JavaScript/.test(r.plan) && /PHP BS/.test(r.plan) && /onchange/.test(r.plan), 'code not in the plan: ' + r.plan);
		expect(/PHP code/.test(r.msg), 'no PHP warning: ' + r.msg);
		await p.check('#faDdl'); await p.click('#faCreate'); await p.waitForURL(/design=1/); await p.waitForLoadState('networkidle');
		const fid = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'e2e_ai_code'");
		expect(/console\.log/.test(sql("SELECT sfo_javascript FROM zzzzsys_form WHERE zzzzsys_form_id = '" + fid + "'")), 'form JavaScript not saved');
		expect(/nuDisplayError/.test(sql("SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = '" + fid + "_BS'")), 'Before Save not saved');
		expect(sql("SELECT sev_event FROM zzzzsys_event e JOIN zzzzsys_object o ON o.zzzzsys_object_id = e.sev_zzzzsys_object_id WHERE o.sob_all_zzzzsys_form_id = '" + fid + "'") === 'onchange', 'event not saved');
		// the prompt of a change shows the code back to the AI
		await open(p, 'index.php?p=formai&f=' + fid);
		await p.fill('#faRequest', 'x'); await p.click('#faNext1'); await p.click('#faCopy'); await p.waitForTimeout(800);
		const pr = await p.inputValue('#faPrompt');
		expect(/"BS"/.test(pr) && /"onchange"/.test(pr), 'code missing in the prompt of the change');
	}, ['e2e_ai_code']);
};

T.form_ai_app = async (s) => {
	const p = s.ga;
	const app = {format: 'nunext-app-1', name: 'E2E projects', forms: [
		{form: {code: 'e2e_app_prj', description: 'E2E app projects', type: 'browseedit', table: 'e2e_app_prj', primary_key: 'e2e_app_prj_id'},
			tabs: [{title: 'Main', objects: [
				{id: 'ap_name', type: 'input', input: 'text', label: 'Name', required: true},
				{id: 'ap_customer_id', type: 'lookup', label: 'Customer', lookup: {form: 'sample_customer', code: 'cus_code', description: 'cus_name'}},
				{id: 'ap_tasks', type: 'subform', label: 'Tasks', subform: {form: 'e2e_app_task', foreign_key: 'at_project_id', type: 'grid'}}]}]},
		{form: {code: 'e2e_app_task', description: 'E2E app tasks', type: 'subform', table: 'e2e_app_task', primary_key: 'e2e_app_task_id', columns: [{name: 'at_project_id', type: 'id'}]},
			tabs: [{title: 'Main', objects: [
				{id: 'at_title', type: 'input', input: 'text', label: 'Title'},
				{id: 'at_due', type: 'input', input: 'nuDate', label: 'Due', same_row: true}]}]}]};
	await aiKeep(async () => {
		await open(p, 'index.php?p=formai&kind=app');
		expect(await p.locator('#faKindApp').isChecked(), 'kind=app not chosen');
		await p.fill('#faRequest', 'Projects with tasks'); await p.click('#faNext1'); await p.click('#faCopy'); await p.waitForTimeout(800);
		expect(/nunext-app-1/.test(await p.inputValue('#faPrompt')), 'app prompt without the app format');
		const r = await faCheck(p, app);
		expect(r.ok && await p.locator('.faAppForm').count() === 2, 'app plan: ' + r.msg + ' ' + r.plan);
		expect(/at_project_id/.test(r.plan), 'foreign key column not planned');
		await p.click('#faCreate'); await p.waitForTimeout(300);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code LIKE 'e2e_app_%'") === '0', 'created without ticking the table box');
		await p.check('#faDdl'); await p.click('#faCreate'); await p.waitForSelector('#faMadeList');
		expect(await p.locator('#faMadeList li').count() === 2, 'created forms not listed');
		const prj = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'e2e_app_prj'"), task = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'e2e_app_task'");
		expect(sql("SELECT sob_subform_zzzzsys_form_id FROM zzzzsys_object WHERE sob_all_id = 'ap_tasks'") === task, 'sub-form not linked to the new form');
		expect(sql("SELECT sob_lookup_zzzzsys_form_id FROM zzzzsys_object WHERE sob_all_id = 'ap_customer_id'") === 'samplecustomer', 'lookup not linked');
		expect(/at_project_id/.test(sql('SHOW COLUMNS FROM e2e_app_task')) && /ap_customer_id/.test(sql('SHOW COLUMNS FROM e2e_app_prj')), 'tables / columns missing');
		expect(sql("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e2e_app_task' AND COLUMN_NAME = 'at_project_id'") !== '0', 'no index on the foreign key');
		expect(sql("SELECT GROUP_CONCAT(sfo_status) FROM zzzzsys_form WHERE zzzzsys_form_id IN ('" + prj + "', '" + task + "')") === '0,0', 'not created as Draft');
		await p.click('#faPublishAll'); await p.waitForSelector('#faPublished');
		expect(sql("SELECT GROUP_CONCAT(sfo_status) FROM zzzzsys_form WHERE zzzzsys_form_id IN ('" + prj + "', '" + task + "')") === '1,1', 'Publish all');
		const r2 = await p.goto(BASE + 'index.php?p=form&f=' + prj + '&r=-1');
		expect(r2.status() === 200 && await p.locator('#nuEditForm').count() === 1, 'the new form does not open');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	}, ['e2e_app_prj', 'e2e_app_task']);
};

T.form_ai_tables_none = async (s) => {               // 0.8.842: "None – new tables only" sends no existing table; empty = all
	const p = s.ga;
	await open(p, 'index.php?p=formai');
	expect(await p.locator('#faTm_none').isChecked() && await p.locator('#faStep2').isHidden(), 'wizard: None not the default for a new form, or step 2 shown at once');
	await p.fill('#faRequest', 'x'); await p.click('#faNext1', {timeout: 4000}).catch(() => { throw new Error('click Next 1'); });
	expect(await p.locator('#faStep2').isVisible() && await p.locator('#faPickBox').isHidden(), 'wizard: step 2 not shown / pick box shown');
	await p.check('#faTm_pick', {timeout: 4000}).catch(() => { throw new Error('check pick'); }); await p.waitForTimeout(200);
	expect(await p.locator('#faPickBox').isVisible(), 'wizard: pick box not shown for Choose tables');
	await p.keyboard.press('Escape'); await p.click('#faStep2 [data-go="1"]', {timeout: 4000}).catch(() => { throw new Error('click back'); }); await p.fill('#faRequest', ''); await p.click('#faNext1');
	expect(await p.locator('#faStep1').isVisible() && await p.locator('#faRequest.is-invalid').count() === 1, 'wizard: empty request passed step 1');
	const prompt = async (tables) => p.evaluate(async (tb) => {
		const b = new URLSearchParams(); b.set('_csrf', document.querySelector('input[name=_csrf]').value);
		b.set('action', 'prompt'); b.set('request', 'A form for recipes'); b.set('kind', 'form');
		tb.forEach((t) => b.append('tables[]', t));
		const r = await fetch(location.href, {method: 'POST', body: b, credentials: 'same-origin', headers: {Accept: 'application/json'}});
		return (await r.json()).text || '';
	}, tables);
	const none = await prompt(['__none__']), all = await prompt([]);
	expect(/none listed/.test(none) && !/sample_customer:/.test(none), 'None still lists tables');
	expect(/sample_customer:/.test(all), 'empty choice no longer lists all tables');
};

T.form_ai_input_textarea = async (s) => {            // 0.8.841: "type": "input", "input": "textarea" is taken as a textarea (warning, not an error)
	const p = s.ga;
	const spec = {format: 'nunext-form-1', form: {code: 'e2e_ai_ta', description: 'E2E AI textarea', type: 'browseedit', table: 'e2e_ai_ta', primary_key: 'e2e_ai_ta_id'},
		tabs: [{title: 'Main', objects: [
			{id: 'ata_name', type: 'input', input: 'text', label: 'Name'},
			{id: 'ata_steps', type: 'input', input: 'textarea', label: 'Steps', height: 200}]}]};
	await aiKeep(async () => {
		await open(p, 'index.php?p=formai');
		const r = await faCheck(p, spec);
		expect(r.ok && /ata_steps/.test(r.msg) && /textarea/.test(r.msg), 'not repaired with a warning: ' + r.msg);
		await p.check('#faDdl'); await p.click('#faCreate'); await p.waitForTimeout(1500);
		expect(sql("SELECT sob_all_type FROM zzzzsys_object WHERE sob_all_id = 'ata_steps'") === 'textarea', 'object not a textarea');
		expect(/ata_steps\s+(long)?text/i.test(sql("SHOW COLUMNS FROM e2e_ai_ta LIKE 'ata_steps'")) && !/varchar/i.test(sql("SHOW COLUMNS FROM e2e_ai_ta LIKE 'ata_steps'")), 'column not a long text: ' + sql("SHOW COLUMNS FROM e2e_ai_ta LIKE 'ata_steps'"));
	}, ['e2e_ai_ta']);
};

T.form_ai_design = async (s) => {
	const p = s.ga, F = 'samplecustomer';
	await aiKeep(async () => {
		sql("UPDATE zzzzsys_nunext_form_draft SET status = 'discarded' WHERE form_id = '" + F + "' AND status = 'open'");
		await open(p, 'index.php?p=form&f=' + F + '&r=-1&design=1'); await p.waitForSelector('#nuDsAi'); await p.waitForTimeout(400);
		const step = async (newId, label) => {
			await p.click('#nuDsAi'); await p.waitForSelector('#nuDsAiDlg.show');
			await p.fill('#nuDsAiReq', 'Add ' + label);
			const pr = await p.evaluate(async () => {
				const b = new URLSearchParams({_csrf: window.nuNext.csrf, action: 'prompt', request: 'x'});
				return (await (await fetch('index.php?p=formai&f=samplecustomer', {method: 'POST', body: b})).json()).text;
			});
			const cur = JSON.parse(pr.match(/## The current form\n```json\n([\s\S]*?)\n```/)[1]);
			cur.tabs[0].objects.push({id: newId, type: 'input', input: 'text', label: label});
			await p.fill('#nuDsAiAns', JSON.stringify(cur)); await p.click('#nuDsAiCheck'); await p.waitForTimeout(800);
			expect(new RegExp(newId).test(await p.textContent('#nuDsAiPlan')), 'plan without ' + newId + ': ' + await p.textContent('#nuDsAiPlan'));
			if (await p.locator('#nuDsAiDdlBox').isVisible()) await p.check('#nuDsAiDdl');
			await Promise.all([p.waitForNavigation(), p.click('#nuDsAiApply')]); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500);
		};
		await step('e2e_ai_one', 'One');
		await step('e2e_ai_two', 'Two');
		const snap = sql("SELECT snapshot FROM zzzzsys_nunext_form_draft WHERE form_id = '" + F + "' AND status = 'open'");
		expect(/e2e_ai_one/.test(snap) && /e2e_ai_two/.test(snap), 'both steps not in one draft');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_form_draft WHERE form_id = '" + F + "' AND status = 'open'") === '1', 'more than one draft');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_id IN ('e2e_ai_one', 'e2e_ai_two')") === '0', 'the live form was changed');
		expect(await p.locator('#label_e2e_ai_two, [data-nu-field="e2e_ai_two"], #e2e_ai_two').count() > 0, 'design mode does not show the draft');
	}, []);
	for (const c of ['e2e_ai_one', 'e2e_ai_two']) { try { sql('ALTER TABLE sample_customer DROP COLUMN ' + c); } catch (e) { /* not added */ } }
};

// 0.8.848: Forms → Add asks how to start – step by step (the wizard), with AI, copy (globeadmin); the last choice is marked
T.form_ai_describe = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=forms');
	await Promise.all([p.waitForNavigation(), p.click('.nuAddBtn')]);
	expect(/p=builder/.test(p.url()) && await p.locator('#nuNfStart .nuNfChoice').count() === 4, 'choice page: ' + p.url());
	expect(await p.locator('#nbDescribe, #nbAiNew').count() === 0, 'AI box still on the page');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-newform-last'); } catch (e) { /* */ } });
	await Promise.all([p.waitForNavigation(), p.click('#nuNfC_ai')]);
	expect(/p=formai/.test(p.url()) && await p.locator('#faRequest').count() === 1, 'AI card: ' + p.url());
	await open(p, 'index.php?p=builder&new=1');
	expect(await p.locator('#nuNfC_ai.nuNfLast .nuNfLastBadge:not([hidden])').count() === 1, 'last choice not marked');
	await Promise.all([p.waitForNavigation(), p.click('#nuNfC_wizard')]);
	expect(/start=wizard/.test(p.url()) && await p.locator('#nbNewForm').count() === 1 && await p.locator('#nbDescribe').count() === 0, 'wizard: ' + p.url());
	await open(p, 'index.php?p=builder&new=1');
	expect((await p.getAttribute('#nuNfC_copy', 'href') || '').includes('p=cloner'), 'copy card');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.1097: reports with AI – new report "Save and create with AI", prompt, a wrong answer, a good answer (plan), preview PDF, apply (old
// layout = a version), restore; the variant with a query written by the AI (saved as an SQL builder query)
T.report_ai = async (s) => {
	const ga = s.ga;
	const dump = path.join(os.tmpdir(), 'nunext-e2e-1097.sql');
	execSync(`mysqldump ${DB} zzzzsys_report zzzzsys_select zzzzsys_select_clause zzzzsys_nunext_report_version zzzzsys_nunext_log > ${dump}`);
	const post = (data) => ga.evaluate(async (d) => {
		const D = JSON.parse(document.getElementById('raiDlg').dataset.rai), b = new URLSearchParams(Object.assign({ _csrf: D.csrf }, d));
		const r = await fetch(D.url, { method: 'POST', body: b }), buf = await r.arrayBuffer();
		return { status: r.status, type: r.headers.get('content-type') || '', size: buf.byteLength, head: new TextDecoder().decode(buf.slice(0, 5)) };
	}, data);
	try {
		// a new report: saved with an empty layout, the dialogue opens
		await open(ga, 'index.php?p=reports&id=new');
		await ga.fill('#sre_code', 'e2e_rai');
		await ga.fill('#sre_description', 'e2e report with AI');
		await ga.evaluate(() => { const x = document.getElementById('sre_zzzzsys_php_id'); x.value = 'TABLE:sample_customer'; if (x.tomselect) x.tomselect.sync(); });
		await Promise.all([ga.waitForNavigation(), ga.click('#rptSaveAi')]);
		await ga.waitForSelector('#raiDlg.show');
		const id = sql("SELECT zzzzsys_report_id FROM zzzzsys_report WHERE sre_code = 'e2e_rai'").trim();
		expect(id !== '', 'the report row was saved before the dialogue');
		expect((await ga.textContent('#raiDlg .modal-title')).length > 5 && await ga.locator('#raiFoot').isHidden(), 'dialogue open, nothing to apply yet');
		// the prompt needs a description; it names the columns and the format
		await ga.click('#raiCopy');
		expect(await ga.locator('#raiReq.is-invalid').count() === 1, 'no prompt without a description');
		await ga.fill('#raiReq', 'Customers by country with a total');
		await ga.click('#raiCopy');
		await ga.waitForFunction(() => document.getElementById('raiPrompt').value.length > 500);
		const prompt = await ga.inputValue('#raiPrompt');
		expect(prompt.includes('nunext-report-1') && prompt.includes('cus_country') && prompt.includes('Customers by country with a total'), 'prompt: format, columns, request');
		expect(!prompt.includes('"sql"'), 'no query part while the switch is off');
		// answers
		const spec = { format: 'nunext-report-1', report: { paper: 'A4', orientation: 'landscape' }, groups: [{ field: 'cus_country', dir: 'asc' }], bands: {
			page_header: { height: 8, objects: [{ type: 'text', text: 'Customers #page#', x: 15, y: 1, w: 100, h: 5, bold: true }, { type: 'line', x: 15, y: 7, w: 260, h: 0.5 }] },
			group_header_1: { height: 7, objects: [{ type: 'field', field: 'cus_country', x: 15, y: 1, w: 80, h: 5, bold: true }] },
			detail: { height: 6, objects: [{ type: 'field', field: 'cus_name', x: 15, y: 0.5, w: 90, h: 5 }, { type: 'field', field: 'cus_credit', x: 200, y: 0.5, w: 40, h: 5, align: 'right', format: 'N|1,000.00' }] },
			report_footer: { height: 8, objects: [{ type: 'sum', field: 'cus_credit', x: 200, y: 1, w: 40, h: 5, align: 'right', bold: true }] } } };
		const answer = async (obj, raw) => {
			await ga.fill('#raiAns', raw || 'Here is the report:\n```json\n' + JSON.stringify(obj, null, 1) + '\n```');
			await ga.click('#raiCheck');
			await ga.waitForFunction(() => !document.querySelector('#raiMsgs .spinner-border'));
		};
		await answer(null, 'Sorry, I cannot help.');
		expect(await ga.locator('#raiMsgs .alert-danger').count() === 1 && await ga.locator('#raiFoot').isHidden(), 'an answer without JSON is refused');
		const bad = JSON.parse(JSON.stringify(spec));
		bad.bands.detail.objects.push({ type: 'field', field: 'no_such_column', x: 250, y: 0.5, w: 30, h: 5 });
		await answer(bad);
		expect((await ga.innerText('#raiMsgs')).includes('no_such_column') && await ga.locator('#raiFoot').isHidden(), 'an unknown column is an error');
		const withSql = JSON.parse(JSON.stringify(spec));
		withSql.source = { code: 'e2e_rai_q', description: 'x', sql: 'SELECT cus_country, cus_name, cus_credit FROM sample_customer' };
		await answer(withSql);
		expect(await ga.locator('#raiMsgs .alert-danger').count() === 1 && await ga.locator('#raiFoot').isHidden(), 'a query is refused while the switch is off');
		await answer(spec);
		expect(await ga.locator('#raiOk').count() === 1 && await ga.locator('#raiFoot').isVisible(), 'the good answer passes');
		expect((await ga.textContent('#raiPlanObjects')).trim() === '6', 'plan: 6 objects: ' + await ga.textContent('#raiPlanObjects'));
		let pv = await post({ action: 'preview', answer: JSON.stringify(spec), allow_sql: '0' });
		expect(pv.status === 200 && pv.type.includes('pdf') && pv.head === '%PDF-' && pv.size > 2000, 'preview PDF: ' + JSON.stringify(pv));
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_report_version WHERE report_id = '${id}'`).trim() === '0', 'check and preview write nothing');
		await Promise.all([ga.waitForNavigation(), ga.click('#raiApply')]);
		const lay = JSON.parse(execSync(`mysql ${DB} -N -r -e "SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = '${id}'"`).toString());
		expect(lay.orientation === 'L' && lay.groups[0].sections[0].objects.length === 2 && lay.groups[3].sortField === 'cus_country', 'layout stored in nuBuilder Forte\'s format: ' + lay.orientation + ' ' + lay.groups[3].sortField);
		expect(lay.groups[0].sections[0].objects[1].left === 800 && lay.groups[2].sections[0].objects[1].fieldName === 'KEEP EXACT HEIGHT', 'mm → px, line = exact-height label');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_report_version WHERE report_id = '${id}' AND reason = 'ai'`).trim() === '1', 'the old layout is a version');
		// the report runs
		const run = await ga.evaluate(async (u) => { const r = await fetch(u); return [r.status, r.headers.get('content-type')]; }, 'index.php?p=report&c=e2e_rai');
		expect(run[0] === 200 && String(run[1]).includes('pdf'), 'the report prints: ' + run);
		// change: the prompt holds the current layout with ids; a query by the AI
		await ga.click('#rptAi');
		await ga.waitForSelector('#raiDlg.show');
		await ga.check('#raiSql');
		expect(await ga.locator('#raiTablesBox').isVisible(), 'tables to describe shown');
		await ga.fill('#raiReq', 'Only name and number of orders');
		await ga.click('#raiCopy');
		await ga.waitForFunction(() => document.getElementById('raiPrompt').value.includes('"source"'));
		const p2 = await ga.inputValue('#raiPrompt');
		expect(p2.includes('"id"') && p2.includes('KEEP') === false && p2.includes('landscape'), 'change prompt: current layout in the short format');
		const q = { format: 'nunext-report-1', report: { paper: 'A4', orientation: 'portrait' }, groups: [],
			source: { code: 'e2e_rai_q', description: 'e2e customers with orders', sql: 'SELECT c.cus_name, COUNT(o.sample_order_id) AS orders FROM sample_customer c LEFT JOIN sample_order o ON o.ord_sample_customer_id = c.sample_customer_id GROUP BY c.cus_name;' },
			bands: { detail: { height: 6, objects: [{ type: 'field', field: 'cus_name', x: 15, y: 0.5, w: 90, h: 5 }, { type: 'field', field: 'orders', x: 160, y: 0.5, w: 30, h: 5, align: 'right' }] } } };
		const del = JSON.parse(JSON.stringify(q)); del.source.sql = 'DELETE FROM sample_customer';
		await answer(del);
		expect(await ga.locator('#raiFoot').isHidden() && sql('SELECT COUNT(*) FROM sample_customer').trim() !== '0', 'only SELECT is accepted');
		await answer(q);
		expect(await ga.locator('#raiOk').count() === 1 && (await ga.textContent('#raiPlanSql')).includes('COUNT(o.sample_order_id)'), 'query checked and shown in the plan');
		pv = await post({ action: 'preview', answer: JSON.stringify(q), allow_sql: '1' });
		expect(pv.status === 200 && pv.head === '%PDF-', 'preview with the unsaved query: ' + JSON.stringify(pv));
		expect(sql("SELECT COUNT(*) FROM zzzzsys_select WHERE sse_code = 'e2e_rai_q'").trim() === '0', 'the preview saved no query');
		await Promise.all([ga.waitForNavigation(), ga.click('#raiApply')]);
		const sel = sql("SELECT zzzzsys_select_id FROM zzzzsys_select WHERE sse_code = 'e2e_rai_q'").trim();
		expect(sel !== '' && sql(`SELECT sre_zzzzsys_php_id FROM zzzzsys_report WHERE zzzzsys_report_id = '${id}'`).trim() === 'SQL:' + sel, 'query saved in the SQL builder and set as data source');
		// restore the layout before the query: the data source comes back too
		await ga.click('a[href="#rptTabHistory"], [data-bs-target="#rptTabHistory"]');
		expect(await ga.locator('#rptVersions tbody tr').count() === 2, 'two versions listed');
		await ga.locator('.rptVerRestore').first().click();
		await ga.waitForSelector('.modal.show [data-nu-confirm-ok], .modal.show .btn-primary');
		await Promise.all([ga.waitForNavigation(), ga.locator('.modal.show .modal-footer .btn-primary').click()]);
		expect(sql(`SELECT sre_zzzzsys_php_id FROM zzzzsys_report WHERE zzzzsys_report_id = '${id}'`).trim() === 'TABLE:sample_customer', 'restored: the table is the source again');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_report_version WHERE report_id = '${id}'`).trim() === '3', 'the replaced layout became a version too');
		// the designer has the button; the context file downloads
		await open(ga, 'index.php?p=reportdesigner&id=' + id);
		await ga.waitForSelector('.rdAiBtn');
		await ga.click('.rdAiBtn');
		await ga.waitForSelector('#raiDlg.show');
		const cx = await post({ action: 'context', samples: '0' });
		expect(cx.status === 200 && cx.type.includes('markdown') && cx.size > 3000, 'context file: ' + JSON.stringify(cx));
	} finally {
		execSync(`mysql ${DB} < ${dump}`);
	}
};

// 0.8.1098: a page break on the first group headers no longer leaves page 1 empty (the first group starts under the report header);
// later groups still start a new page. Printed through the preview of Reports with AI – nothing is saved.
T.report_first_header_break = async (s) => {
	const ga = s.ga;
	await open(ga, 'index.php?p=reports&id=sample_rpt_orders');
	const pdfText = async (spec) => {
		const b64 = await ga.evaluate(async (a) => {
			const D = JSON.parse(document.getElementById('raiDlg').dataset.rai);
			const r = await fetch(D.url, { method: 'POST', body: new URLSearchParams({ _csrf: D.csrf, action: 'preview', answer: a, allow_sql: '0' }) });
			if (r.status !== 200) return '';
			const u = new Uint8Array(await r.arrayBuffer()); let t = '';
			for (let i = 0; i < u.length; i += 8192) t += String.fromCharCode.apply(null, u.subarray(i, i + 8192));
			return btoa(t);
		}, JSON.stringify(spec));
		expect(b64 !== '', 'the preview printed');
		const f = path.join(os.tmpdir(), 'e2e_fhb_' + Date.now() + '.pdf');
		fs.writeFileSync(f, Buffer.from(b64, 'base64'));
		try { return execSync(`pdftotext -layout "${f}" -`, { stdio: ['ignore', 'pipe', 'ignore'] }).toString().replace(/\f$/, '').split('\f'); }
		catch (e) { return null; } finally { fs.unlinkSync(f); }
	};
	const spec = (breakOn) => { const sp = { format: 'nunext-report-1', report: { paper: 'A4', orientation: 'portrait' }, groups: [{ field: 'cus_country', dir: 'asc' }, { field: 'cus_name', dir: 'asc' }], bands: {
		report_header: { height: 12, objects: [{ type: 'text', text: 'E2E-TITLE', x: 15, y: 2, w: 100, h: 8, size: 16 }] },
		page_header: { height: 7, objects: [{ type: 'text', text: 'E2E-PAGEHEAD', x: 15, y: 1, w: 60, h: 5 }] },
		group_header_1: { height: 7, objects: [{ type: 'text', text: 'E2E-COUNTRY', x: 15, y: 1, w: 45, h: 5 }, { type: 'field', field: 'cus_country', x: 65, y: 1, w: 40, h: 5 }] },
		group_header_2: { height: 7, objects: [{ type: 'text', text: 'E2E-CUSTOMER', x: 15, y: 1, w: 45, h: 5 }, { type: 'field', field: 'cus_name', x: 65, y: 1, w: 90, h: 5 }] },
		detail: { height: 5, objects: [{ type: 'field', field: 'ord_number', x: 15, y: 0, w: 40, h: 5 }] },
		report_footer: { height: 8, objects: [{ type: 'text', text: 'E2E-GRANDTOTAL', x: 15, y: 1, w: 60, h: 5 }] } } };
		sp.bands[breakOn].page_break = true; return sp; };
	const countries = Number(sql('SELECT COUNT(DISTINCT c.cus_country) FROM sample_customer c JOIN sample_order o ON c.sample_customer_id = o.ord_sample_customer_id').trim());
	const customers = Number(sql('SELECT COUNT(DISTINCT CONCAT(c.cus_country, c.cus_name)) FROM sample_customer c JOIN sample_order o ON c.sample_customer_id = o.ord_sample_customer_id').trim());
	let pg = await pdfText(spec('group_header_1'));
	if (pg === null) return 'skip: no pdftotext';
	expect(pg.length === countries, 'break on the country header: one page per country (' + countries + '), got ' + pg.length);
	expect(pg[0].includes('E2E-TITLE') && pg[0].includes('E2E-COUNTRY') && pg[0].includes('E2E-CUSTOMER'), 'page 1 holds the report header AND the first country: ' + JSON.stringify(pg[0]));
	expect(pg.every(t => (t.match(/E2E-COUNTRY/g) || []).length === 1 && t.includes('E2E-PAGEHEAD')), 'every page has exactly one country');
	expect(pg[pg.length - 1].includes('E2E-GRANDTOTAL'), 'the last page ends with the report footer');
	pg = await pdfText(spec('group_header_2'));
	expect(pg.length === customers, 'break on the customer header: one page per customer (' + customers + '), got ' + pg.length);
	expect(pg[0].includes('E2E-TITLE') && pg[0].includes('E2E-CUSTOMER') && pg.every(t => (t.match(/E2E-CUSTOMER/g) || []).length === 1), 'the first customer starts on page 1, each later one on its own page');
};

// 0.8.1099: AI providers – a configuration (fake service: dev/tests/fakeai.py), Fetch models, Test, "Ask AI" in Reports with AI with
// one automatic correction round, the password of a configuration (once per session), the monthly token limit, the usage rows; the
// control is offered in the other AI dialogues too. Nothing is applied by the request itself.
T.ai_providers = async (s) => {
	const ga = s.ga;
	const port = 8772, log = path.join(os.tmpdir(), 'e2e-ai-' + Date.now() + '.log'), answers = path.join(os.tmpdir(), 'e2e-ai-' + Date.now() + '.json');
	const dump = path.join(os.tmpdir(), 'nunext-e2e-1099.sql');
	execSync(`mysqldump ${DB} zzzzsys_nunext_ai_provider zzzzsys_nunext_ai_call zzzzsys_nunext_setting zzzzsys_nunext_log > ${dump}`);
	const srv = require('child_process').spawn('python3', ['-I', path.join(__dirname, 'fakeai.py'), String(port), log, answers], { stdio: 'ignore' });
	const lines = () => fs.existsSync(log) ? fs.readFileSync(log, 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)) : [];
	const reauth = async (url) => { if (/p=reauth/.test(ga.url())) { await ga.fill('#reauthPassword', GA[1]); await Promise.all([ga.waitForLoadState('networkidle'), ga.click('button[type=submit]')]); await open(ga, url); } };
	const idle = (host) => ga.waitForFunction((h) => { const m = document.querySelector(h + ' .nuAiAskMsg'); return m && m.textContent.trim() !== '' && !document.querySelector(h).classList.contains('nuAiAskBusy'); }, host, { timeout: 20000 });
	try {
		await new Promise(r => setTimeout(r, 700));
		await open(ga, 'index.php?p=aiproviders'); await reauth('index.php?p=aiproviders');
		expect(await ga.locator('#aipNew').count() === 1, 'the list with Add');
		if (!(await ga.isChecked('#aipAllowPrivate'))) await Promise.all([ga.waitForNavigation(), ga.check('#aipAllowPrivate')]);
		// a new configuration: a private address is refused while the setting is off – it is on now
		await open(ga, 'index.php?p=aiproviders&id=new');
		await ga.fill('#aipName', 'e2e fake AI');
		await ga.selectOption('#aipKind', 'anthropic');
		await ga.fill('#aipUrl', `http://127.0.0.1:${port}/anthropic`);
		await ga.fill('#aipKey', 'test-key');
		await ga.click('#aipFetch');
		await ga.waitForFunction(() => document.querySelectorAll('#aipModels option').length === 2);
		await ga.fill('#aipModel', 'fake-large');
		await ga.fill('#aipTemp', '0.2');
		await ga.fill('#aipParams', '{"top_k": 5}');
		await Promise.all([ga.waitForNavigation(), ga.click('#aipTest')]);
		expect(await ga.locator('#aipTestResult.alert-success').count() === 1, 'Test: the service answered: ' + await ga.locator('#aipTestResult').innerText().catch(() => '-'));
		const id = sql("SELECT zzzzsys_nunext_ai_provider_id FROM zzzzsys_nunext_ai_provider WHERE name = 'e2e fake AI'").trim();
		const stored = sql(`SELECT api_key FROM zzzzsys_nunext_ai_provider WHERE zzzzsys_nunext_ai_provider_id = '${id}'`).trim();
		expect(id !== '' && !stored.includes('test-key') || stored.startsWith('plain:'), 'saved; the key is sealed (or marked plain without a secret key)');
		expect(!(await ga.content()).includes('test-key'), 'the key is never shown again');
		let l = lines().filter(x => x.path.endsWith('/messages'));
		const body = JSON.parse(l[l.length - 1].body);
		expect(l[l.length - 1].headers['x-api-key'] === 'test-key' && body.model === 'fake-large' && body.temperature === 0.2 && body.top_k === 5 && body.max_tokens > 0, 'request: key header, model, temperature, extra parameter');
		// Reports with AI: "Ask AI" – the first answer has an unknown column, the correction round fixes it
		const spec = { format: 'nunext-report-1', report: { paper: 'A4', orientation: 'portrait' }, groups: [], bands: { detail: { height: 6, objects: [{ type: 'field', field: 'cus_name', x: 15, y: 0.5, w: 90, h: 5 }] } } };
		const bad = JSON.parse(JSON.stringify(spec)); bad.bands.detail.objects.push({ type: 'field', field: 'no_such_column', x: 120, y: 0.5, w: 30, h: 5 });
		fs.writeFileSync(answers, JSON.stringify(['```json\n' + JSON.stringify(bad) + '\n```', '```json\n' + JSON.stringify(spec) + '\n```']));
		const before = sql("SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = 'sample_rpt_customers'");
		await open(ga, 'index.php?p=reports&id=sample_rpt_customers');
		await ga.click('#rptAi'); await ga.waitForSelector('#raiDlg.show');
		expect(await ga.locator('#raiAsk .nuAiAskBtn').isVisible(), 'Ask AI offered in the report dialogue');
		await ga.click('#raiAsk .nuAiAskBtn');
		expect(await ga.locator('#raiReq.is-invalid').count() === 1 && lines().filter(x => x.path.endsWith('/messages')).length === l.length, 'nothing is sent without a description');
		await ga.fill('#raiReq', 'Only the names');
		await ga.click('#raiAsk .nuAiAskBtn');
		await idle('#raiAsk');
		expect(await ga.locator('#raiOk').count() === 1 && await ga.locator('#raiFoot').isVisible() && !(await ga.inputValue('#raiAns')).includes('no_such_column'), 'corrected answer in the box, it passes: ' + await ga.textContent('#raiAsk .nuAiAskMsg'));
		l = lines().filter(x => x.path.endsWith('/messages'));
		const fix = JSON.parse(l[l.length - 1].body);
		expect(fix.messages.length === 3 && fix.messages[1].role === 'assistant' && fix.messages[2].content.includes('no_such_column') && fix.messages[0].content.includes('Only the names'), 'the correction round sent prompt, answer and the errors');
		expect(sql("SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = 'sample_rpt_customers'") === before, 'the request applied nothing');
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_ai_call WHERE provider_id = '${id}' AND feature = 'report' AND status = 'ok' AND tokens_in = 120`).trim() === '2', 'two usage rows for the report');
		// offered in the other dialogues; the prompt generator shows the answer
		await open(ga, 'index.php?p=formai');
		expect(await ga.locator('#faAsk .nuAiAskBtn').count() === 1, 'Form with AI has the control');
		fs.writeFileSync(answers, JSON.stringify(['no json here', 'still no json']));
		const nBefore = lines().filter(x => x.path.endsWith('/messages')).length;
		await ga.fill('#faRequest', 'A list of books');
		await ga.click('#faNext1');
		await ga.click('#faAsk .nuAiAskBtn');
		await idle('#faAsk');
		expect(lines().filter(x => x.path.endsWith('/messages')).length === nBefore + 2 && await ga.locator('#faAsk .nuAiAskMsg.text-warning').count() === 1
			&& await ga.inputValue('#faAnswer') === 'still no json' && await ga.locator('#faCreateBox').isHidden(), 'Form with AI: one correction round, then the errors stay listed');
		await open(ga, 'index.php?p=prompts&id=new');
		expect(await ga.locator('#pgAsk .nuAiAskBtn').count() === 1, 'the prompt generator has the control');
		fs.writeFileSync(answers, JSON.stringify(['e2e prompt answer']));
		await ga.click('#pgAsk .nuAiAskBtn');
		await idle('#pgAsk');
		expect(await ga.locator('#pgAnswerBox').isVisible() && await ga.inputValue('#pgAnswer') === 'e2e prompt answer', 'the answer is shown below the prompt');
		const hp = sql("SELECT code FROM zzzzsys_nunext_help WHERE lang = '' LIMIT 1").trim();
		if (hp) { await open(ga, 'index.php?p=helppages&c=' + encodeURIComponent(hp)); expect(await ga.locator('#uhAiAsk').count() <= 1, 'help page editor opens'); }
		const sf = sql("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = 'sample_customer' OR zzzzsys_form_id = 'samplecustomer' LIMIT 1").trim();
		// a password on the configuration: asked once per session, wrong passwords are refused
		await open(ga, 'index.php?p=aiproviders&id=' + id); await reauth('index.php?p=aiproviders&id=' + id);
		await ga.check('#aipAcc_password');
		await ga.fill('#aipPw', 'short');
		await Promise.all([ga.waitForNavigation(), ga.click('[data-nu-save]')]);
		expect(await ga.locator('#aipPw.is-invalid').count() === 1, 'a short password is refused');
		await ga.check('#aipAcc_password');
		await ga.fill('#aipPw', 'e2e-secret-pw');
		await ga.fill('#aipLimit', '500');
		await Promise.all([ga.waitForNavigation(), ga.click('[data-nu-save]')]);
		const row = () => sql(`SELECT CONCAT(access, '|', COALESCE(password, ''), '|', LEFT(api_key, 3), '|', LENGTH(COALESCE(pw_salt, ''))) FROM zzzzsys_nunext_ai_provider WHERE zzzzsys_nunext_ai_provider_id = '${id}'`).trim();
		expect(row() === 'password||pw:|24', 'the key is encrypted with the password, no password hash is stored: ' + row());
		expect(await ga.locator('#aipLock[data-open="1"]').count() === 1, 'whoever set the password stays unlocked');
		// the session alone does not open it: without the browser's cookie the configuration is locked again
		const ck = (await ga.context().cookies()).find(c => c.name === 'nunext_aik');
		expect(ck && ck.httpOnly && ck.sameSite === 'Strict', 'the cookie half of the key: httpOnly, SameSite=Strict');
		await ga.context().clearCookies({ name: 'nunext_aik' });
		await open(ga, 'index.php?p=aiproviders&id=' + id); await reauth('index.php?p=aiproviders&id=' + id);
		expect(await ga.locator('#aipLock[data-open="0"]').count() === 1 && await ga.locator('#aipUnlockPw').count() === 1, 'locked on its own page');
		await Promise.all([ga.waitForNavigation(), ga.click('#aipTest')]);
		expect(await ga.locator('#aipTestResult.alert-danger').count() === 1, 'Test needs the password');
		await ga.fill('#aipKey', 'another-key');
		await Promise.all([ga.waitForNavigation(), ga.click('[data-nu-save]')]);
		expect(await ga.locator('#aipKey.is-invalid, #aipKey ~ .invalid-feedback').count() >= 1 && row() === 'password||pw:|24', 'a locked configuration takes no new key without the password');
		await open(ga, 'index.php?p=prompts&id=new');
		expect(await ga.locator('#pgAsk .nuAiAskPwBox').isVisible() && await ga.locator('#pgAsk .nuAiAskBtn').isHidden(), 'locked: the password is asked instead of Ask AI');
		const direct = await ga.evaluate(async (pid) => {
			const D = JSON.parse(document.getElementById('pgAsk').dataset.nuAi);
			const r = await fetch(D.url, { method: 'POST', body: new URLSearchParams({ _csrf: D.csrf, feature: 'prompt', provider: pid, action: 'ask', prompt: 'x' }) });
			return r.json();
		}, id);
		expect(direct.ok === false && direct.text === '', 'a locked configuration answers no request: ' + JSON.stringify(direct));
		await ga.fill('#pgAsk .nuAiAskPw', 'wrong-password');
		await ga.click('#pgAsk .nuAiAskUnlock');
		await ga.waitForFunction(() => document.querySelector('#pgAsk .nuAiAskMsg').classList.contains('text-danger'));
		await ga.fill('#pgAsk .nuAiAskPw', 'e2e-secret-pw');
		await ga.click('#pgAsk .nuAiAskUnlock');
		await ga.waitForSelector('#pgAsk .nuAiAskBtn:visible');
		await open(ga, 'index.php?p=prompts&id=new');
		expect(await ga.locator('#pgAsk .nuAiAskBtn').isVisible(), 'unlocked for the rest of the session');
		// the monthly limit (500 tokens; more than that is used)
		await ga.click('#pgAsk .nuAiAskBtn');
		await idle('#pgAsk');
		expect(await ga.locator('#pgAsk .nuAiAskMsg.text-danger').count() === 1 && /500/.test(await ga.textContent('#pgAsk .nuAiAskMsg')), 'refused at the monthly limit: ' + await ga.textContent('#pgAsk .nuAiAskMsg'));
		// back to "everyone": the key is re-sealed without the password (possible because the session is unlocked)
		await open(ga, 'index.php?p=aiproviders&id=' + id); await reauth('index.php?p=aiproviders&id=' + id);
		expect(await ga.locator('#aipLock[data-open="1"]').count() === 1, 'unlocked in the dialogue = unlocked on its page');
		await ga.check('#aipAcc_all');
		await Promise.all([ga.waitForNavigation(), ga.click('[data-nu-save]')]);
		expect(/^all\|\|(enc|pla)\|0$/.test(row()), 'password removed: the key is sealed by the server only again: ' + row());
		// a row of the first version (password hash, key not encrypted with it) converts on its first unlock
		const hash = execSync(`php -r 'echo password_hash("legacy-password-1", PASSWORD_BCRYPT);'`).toString().trim();
		sql(`UPDATE zzzzsys_nunext_ai_provider SET access = 'password', password = '${hash}', api_key = 'plain:test-key', pw_salt = '', month_limit = 0 WHERE zzzzsys_nunext_ai_provider_id = '${id}'`);
		await open(ga, 'index.php?p=aiproviders&id=' + id); await reauth('index.php?p=aiproviders&id=' + id);
		await ga.fill('#aipUnlockPw', 'legacy-password-1');
		await Promise.all([ga.waitForNavigation(), ga.click('#aipUnlock')]);
		expect(row() === 'password||pw:|24' && await ga.locator('#aipLock[data-open="1"]').count() === 1, 'converted on unlock: ' + row());
		await Promise.all([ga.waitForNavigation(), ga.click('#aipTest')]);
		expect(await ga.locator('#aipTestResult.alert-success').count() === 1, 'the converted key still works');
		// usage on the configuration page, delete
		await open(ga, 'index.php?p=aiproviders&id=' + id); await reauth('index.php?p=aiproviders&id=' + id);
		expect(await ga.locator('#aipCalls tbody tr').count() >= 6 && parseInt(await ga.textContent('#aipUsageCalls'), 10) >= 6, 'usage of the month and the last requests');
		await ga.click('#aipDelete');
		await ga.waitForSelector('.modal.show .btn-danger');
		await Promise.all([ga.waitForNavigation(), ga.locator('.modal.show .modal-footer .btn-danger').click()]);
		expect(sql(`SELECT COUNT(*) FROM zzzzsys_nunext_ai_provider WHERE zzzzsys_nunext_ai_provider_id = '${id}'`).trim() === '0' && sql(`SELECT COUNT(*) FROM zzzzsys_nunext_ai_call WHERE provider_id = '${id}'`).trim() === '0', 'deleted with its usage rows');
		await open(ga, 'index.php?p=prompts&id=new');
		expect(await ga.locator('#pgAsk').count() === 0, 'without a configuration the dialogues show no Ask AI');
		void sf;
	} finally {
		srv.kill();
		execSync(`mysql ${DB} < ${dump}`);
		for (const f of [log, answers]) if (fs.existsSync(f)) fs.unlinkSync(f);
	}
};

// 0.8.1102: prompt generator – a picked table also lists its keys over several columns, unique keys, foreign keys in both
// directions, checks, generated columns, triggers, a view's SELECT, and the relations nuBuilder knows (lookups, sub-forms)
T.prompt_table_details = async (s) => {
	const ga = s.ga;
	const drop = () => sql('DROP VIEW IF EXISTS e2e_pd_view; DROP TABLE IF EXISTS e2e_pd_child; DROP TABLE IF EXISTS e2e_pd_parent');
	drop();
	try {
		sql(`CREATE TABLE e2e_pd_parent (id INT PRIMARY KEY AUTO_INCREMENT, code VARCHAR(20) NOT NULL, region VARCHAR(10), UNIQUE KEY uq_code_region (code, region)) COMMENT = 'parents of the test';
			CREATE TABLE e2e_pd_child (id INT PRIMARY KEY AUTO_INCREMENT, parent_id INT NOT NULL, qty INT NOT NULL DEFAULT 1, price DECIMAL(10,2), total DECIMAL(12,2) AS (qty * price) STORED, a INT, b INT, KEY ix_ab (a, b),
				CONSTRAINT fk_pd_parent FOREIGN KEY (parent_id) REFERENCES e2e_pd_parent (id) ON DELETE CASCADE, CONSTRAINT chk_qty CHECK (qty > 0));
			CREATE TRIGGER e2e_pd_trg BEFORE INSERT ON e2e_pd_child FOR EACH ROW SET NEW.a = COALESCE(NEW.a, 0);
			CREATE VIEW e2e_pd_view AS SELECT c.id, p.code FROM e2e_pd_child c JOIN e2e_pd_parent p ON p.id = c.parent_id`);
		await open(ga, 'index.php?p=prompts&id=new');
		const prompt = await ga.evaluate(async (tables) => {
			const f = document.getElementById('pgForm'), d = new FormData(f);
			d.delete('pge_table[]'); d.delete('pge_table');
			const name = (f.querySelector('[name^="pge_table"]') || {}).name || 'pge_table[]';
			tables.forEach(t => d.append(name, t));
			d.set('action', 'build');
			const r = await fetch(f.getAttribute('action'), { method: 'POST', body: d, headers: { Accept: 'application/json' } });
			const j = await r.json();
			return j.prompt || j.text || j.result || JSON.stringify(j);
		}, ['e2e_pd_parent', 'e2e_pd_child', 'e2e_pd_view', 'sample_order', 'sample_customer']);
		const has = (t) => prompt.includes(t);
		expect(has('- **e2e_pd_child**') && has('Foreign keys: `parent_id` → `e2e_pd_parent`.`id` (on delete cascade)'), 'foreign key with its rule: ' + prompt.slice(0, 300));
		expect(has('Referenced by: `e2e_pd_child`.`parent_id` → `id`') && has('Unique: (`code`, `region`)') && has('Comment: parents of the test'), 'parent: referenced by, unique key, comment');
		expect(has('Indexes over several columns: (`a`, `b`)') && /Checks: `chk_qty` .*qty.* > 0/.test(prompt) && /Generated columns: `total` = /.test(prompt) && has('Triggers: `e2e_pd_trg` (before insert)'), 'index, check, generated column, trigger');
		expect(/View: select .* from .*e2e_pd_child/i.test(prompt) && !/`nutest`\./.test(prompt.split('**e2e_pd_view**')[1].split('\n- **')[0]), 'the view with its SELECT, without the schema prefix');
		expect(has('nuBuilder lookups (no database constraint): `ord_sample_customer_id` → `sample_customer`.`sample_customer_id`') && has('nuBuilder sub-forms (child rows): `sample_order_item`.`ori_sample_order_id`'), 'relations nuBuilder knows: lookup and sub-form');
		expect(/nuBuilder looked up from: .*`sample_order`\.`ord_sample_customer_id`/.test(prompt), 'and the other direction on the looked-up table');
	} finally { drop(); }
};

T.help_ai_form_search = async (s) => {
	// 0.8.1050: Draft with AI – "Form the page is about" is a Tom Select with a search field in the open list
	const p = s.ga;
	await open(p, 'index.php?p=helppages&id=new');
	if (!(await p.locator('#uhAiBtn').count())) { s.skip = 'no Draft with AI button'; return; }
	await p.click('#uhAiBtn');
	await p.locator('#uhAiDlg.show').waitFor();
	await p.waitForTimeout(400);
	await p.locator('#uhAiDlg .ts-wrapper .ts-control').click();
	expect(await p.locator('#uhAiDlg .ts-dropdown input.dropdown-input').waitFor({ timeout: 3000 }).then(() => true, () => false), 'no search field in the list');
	const all = await p.locator('#uhAiDlg .ts-dropdown .option').count();
	const first = (await p.locator('#uhAiDlg .ts-dropdown .option').nth(1).innerText()).split('\n')[0];
	await p.keyboard.type(first.slice(0, 6));
	await p.waitForTimeout(300);
	const n = await p.locator('#uhAiDlg .ts-dropdown .option').count();
	expect(n >= 1 && (n < all || all <= 2), 'search did not filter (' + n + ' of ' + all + ')');
	await p.keyboard.press('Enter');
	expect(await p.evaluate(() => document.getElementById('uhAiForm').value) !== '', 'no form chosen');
	// 0.8.1051: (i) next to Focus (optional) + example placeholder
	expect(await p.locator('label[for="uhAiNote"] + .nuInfoTip').count() === 1, 'no (i) next to Focus');
	expect((await p.getAttribute('#uhAiNote', 'placeholder') || '') !== '', 'no placeholder on Focus');
};

T.prompt_ai_context = async (s) => {
	// 0.8.991: the prompt generator downloads the AI context file too (kind "code": functions, hash variables, guide, chosen tables –
	// no request format, no example forms, no data)
	const p = s.ga;
	await open(p, 'index.php?p=prompts&id=new&table=sample_customer');
	await p.waitForSelector('#pgCtxMd');
	const [dl] = await Promise.all([p.waitForEvent('download', {timeout: 15000}), p.click('#pgCtxMd')]);
	const name = dl.suggestedFilename();
	const text = require('fs').readFileSync(await dl.path(), 'utf8');
	expect(/^nunext-context-code-\d+\.\d+\.\d+\.md$/.test(name), 'file name: ' + name);
	for (const part of ['prompt generator', 'sample_customer:', '#RECORD_ID#', 'nuRunQuery(', 'nuGetValue('])
		expect(text.includes(part), 'context file without ' + part);
	expect(!text.includes('Request format') && !text.includes('Example forms') && !/sample_order_item:/.test(text), 'form sections / other tables in the code file');
	expect(await p.isVisible('#pgCtxMsg.text-success'), 'no message after the download');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.prompt_next_links = async (s) => {
	// 0.8.1014 / 0.8.1015: the prompt generator points to nuBuilder Next's repository (+ the Forte wiki, mostly valid) (function reference, docs pages, source) – not to nuBuilder
	// Forte's wiki / repository; a record that still holds the old default instruction gets the new one
	const p = s.ga;
	await open(p, 'index.php?p=prompts&id=new&lang=' + encodeURIComponent('["nuphp","nujavascript","mysql"]'));
	await p.waitForSelector('#pgResult');
	const pr = await p.inputValue('#pgResult');
	for (const part of ['nuBuilder-Next/refs/heads/main/docs/nunext-functions.json', 'docs/pages/10-01-php-api.htm', 'assets/js/nu/nuruntime.js', 'for nuBuilder Next'])
		expect(pr.includes(part), 'prompt without ' + part);
	expect(!/nuBuilder\/nuBuilder\/|nucommon\.php/.test(pr), 'prompt still links nuBuilder Forte source files: ' + pr.slice(0, 400));
	expect(/\[nuBuilder Forte wiki\]\(https:\/\/wiki\.nubuilder\.cloud\/index\.php\?title=PHP\)/.test(pr), 'Forte wiki link missing (still mostly valid)');
	const id = 'e2e_pg_old';
	sql("DELETE FROM zzzzsys_prompt_generator WHERE zzzzsys_prompt_generator_id = '" + id + "'");
	sql("INSERT INTO zzzzsys_prompt_generator (zzzzsys_prompt_generator_id, pge_code, pge_prompt, pge_instruction, pge_language) VALUES ('" + id + "', 'e2e_pg_old', 'Test', "
		+ "'Generate code snippets and explanations following nuBuilder conventions. Use the information below when relevant to the request. This context is provided to help understand the requirements. If something is unclear or missing, say so instead of guessing.', '[\"nuphp\"]')");
	try {
		await open(p, 'index.php?p=prompts&id=' + id);
		await p.waitForSelector('#pgResult');
		const r = await p.evaluate(() => ({ins: document.getElementById('pge_instruction').value, res: document.getElementById('pgResult').value}));
		expect(/for nuBuilder Next/.test(r.ins) && /for nuBuilder Next/.test(r.res) && !/following nuBuilder conventions/.test(r.res), 'old instruction kept: ' + r.ins);
	} finally { sql("DELETE FROM zzzzsys_prompt_generator WHERE zzzzsys_prompt_generator_id = '" + id + "'"); }
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.ai_context = async (s) => {
	// 0.8.961: Form with AI / Ask AI "Download context" – one .md or a zip with the format, tables (structure only), forms, example forms,
	// hash variables, guide pages and the function reference (compact / full); no data rows; the prompt points to the file
	const p = s.ga;
	const cus = sql("SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1");
	if (!cus) { s.skip = 'no sample data'; return; }
	const old = sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + cus + "'");
	sql("UPDATE sample_customer SET cus_name = 'E2E_ROW_MARKER' WHERE sample_customer_id = '" + cus + "'");
	try {
		await open(p, 'index.php?p=formai');
		const get = (f, extra) => p.evaluate(async ([url, ex]) => {
			const b = new URLSearchParams({_csrf: document.querySelector('#faForm input[name=_csrf]').value, action: 'context', kind: 'form', ...ex});
			b.append('tables[]', 'sample_customer');
			const r = await fetch(url, {method: 'POST', body: b, credentials: 'same-origin'});
			const buf = new Uint8Array(await r.arrayBuffer());
			return {status: r.status, name: r.headers.get('X-Nu-File-Name'), disp: r.headers.get('Content-Disposition'), type: r.headers.get('Content-Type'),
				size: buf.length, head: String.fromCharCode(buf[0], buf[1]), text: new TextDecoder().decode(buf)};
		}, [f, extra]);
		let r = await get(BASE + 'index.php?p=formai', {fmt: 'md', full: '0'});
		expect(r.status === 200 && /^nunext-context-form-\d+\.\d+\.\d+\.md$/.test(r.name || '') && /attachment/.test(r.disp || '') && /markdown/.test(r.type || ''), 'md file: ' + JSON.stringify({s: r.status, n: r.name, d: r.disp, t: r.type}));
		for (const part of ['## 1. Request format "nunext-form-1"', 'sample_customer:', 'cus_name', '## 4. Example forms', '#RECORD_ID#', 'nuGetValue(', 'nuRunQuery(', 'Form lifecycle'])
			expect(r.text.includes(part), 'compact file without ' + part);
		expect(!r.text.includes('E2E_ROW_MARKER'), 'the context file contains data of a table');
		expect(!/nuConfigDBPassword|sus_login_password/.test(r.text), 'the context file names a password setting');
		const compact = r.size;
		r = await get(BASE + 'index.php?p=formai', {fmt: 'md', full: '1'});
		expect(r.size > compact && r.text.includes('  - returns:') && r.text.includes('Custom pages'), 'full file not larger / without parameters: ' + r.size + ' / ' + compact);
		r = await get(BASE + 'index.php?p=formai', {fmt: 'zip', full: '0'});
		expect(r.head === 'PK' && /\.zip$/.test(r.name || '') && r.text.includes('00-readme.md') && r.text.includes('08-js.md'), 'zip: ' + JSON.stringify({n: r.name, h: r.head}));
		// change of a form: its spec is in the file, the name carries its code
		r = await get(BASE + 'index.php?p=formai&f=samplecustomer', {fmt: 'md', full: '0'});
		expect(/^nunext-context-form-sample_customer-/.test(r.name || '') && r.text.includes('The form to change') && r.text.includes('"code": "sample_customer"'), 'change file: ' + r.name);
		// the prompt points to the file; the button downloads it
		const prompt = await p.evaluate(async () => {
			const b = new URLSearchParams({_csrf: document.querySelector('#faForm input[name=_csrf]').value, action: 'prompt', request: 'x', kind: 'form'});
			return (await (await fetch(location.href, {method: 'POST', body: b, credentials: 'same-origin'})).json()).text;
		});
		expect(prompt.includes('nunext-context-'), 'prompt without the hint to the context file');
		await p.fill('#faRequest', 'A list of projects'); await p.click('#faNext1');
		const [dl] = await Promise.all([p.waitForEvent('download', {timeout: 15000}), p.click('#faCtxMd')]);
		expect(/^nunext-context-form-.*\.md$/.test(dl.suggestedFilename()), 'download name: ' + dl.suggestedFilename());
		// design mode Ask AI
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cus + '&design=1');
		await p.click('#nuDsAi'); await p.waitForSelector('#nuDsAiCtxMd');
		const [dl2] = await Promise.all([p.waitForEvent('download', {timeout: 15000}), p.click('#nuDsAiCtxMd')]);
		expect(/^nunext-context-form-sample_customer-.*\.md$/.test(dl2.suggestedFilename()), 'design mode download: ' + dl2.suggestedFilename());
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'form_ai_context'") !== '0', 'not logged');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("UPDATE sample_customer SET cus_name = '" + old.replace(/'/g, "''") + "' WHERE sample_customer_id = '" + cus + "'");
		sql("DELETE FROM zzzzsys_nunext_form_draft WHERE form_id = 'samplecustomer'");
	}
};
