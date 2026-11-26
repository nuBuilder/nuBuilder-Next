// e2e tests: update page, backups, library packs, database set-up – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// 0.8.698: integrity check of the database – Update → System → Check the database, the package check (problems need the tick,
// the server refuses the install without it), php nucron.php integrity (exit code 1 on problems)
T.integrity = async (s) => {
	const p = s.ga, keep = path.join(os.tmpdir(), 'nunext-e2e-ic.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_user zzzzsys_object > ' + keep);
	try {
		sql(`INSERT INTO zzzzsys_user (zzzzsys_user_id, sus_login_name, sus_name, sus_json) VALUES ('e2e_ic_u1', 'e2e_ic_dup', 'IC 1', '{bad'), ('e2e_ic_u2', 'e2e_ic_dup', 'IC 2', NULL);
			INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_id, sob_all_type) VALUES ('e2e_ic_o1', 'e2e_no_form', 'x', 'input');
			INSERT INTO zzzzsys_nunext_migration (zzzzsys_nunext_migration_id) VALUES ('9999-e2e-future')`);
		await open(p, 'index.php?p=update&tab=system&integrity=1');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update&tab=system&integrity=1'); }
		const ids = await p.$$eval('#updIntegrityNow [data-ic]', e => e.map(x => x.getAttribute('data-ic') + ':' + x.getAttribute('data-level')));
		for (const want of ['dup_login:error', 'json:warn', 'orphans:warn', 'migration_newer:warn']) expect(ids.includes(want), 'missing ' + want + ': ' + ids.join(','));
		expect(/e2e_ic_dup/.test(await p.textContent('[data-ic=dup_login]')) && /e2e_ic_o1/.test(await p.textContent('[data-ic=orphans]')), 'details');
		expect(sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zzzzsys_nunext_ichk'") === '0', 'scratch table dropped');
		if (SITE) {
			let code = 0;
			try { execSync('php nucron.php integrity', { cwd: SITE, stdio: 'pipe' }); } catch (e) { code = e.status; }
			expect(code === 1, 'nucron.php integrity exit code: ' + code);
		}
		const pkg = process.env.PKG;
		if (pkg && fs.existsSync(pkg)) {
			await open(p, 'index.php?p=update');
			if (!(await p.locator('#updOther').isVisible())) { await p.click('#updOtherBtn'); await p.waitForTimeout(500); }
			await p.setInputFiles('#updPackage', pkg);
			await Promise.all([p.waitForLoadState('networkidle'), p.click('#updUpload')]);
			await p.waitForTimeout(500);
			const pk = await p.$$eval('#updIntegrity [data-ic]', e => e.map(x => x.getAttribute('data-ic') + ':' + x.getAttribute('data-level')));
			expect(pk.includes('dup_login:error') && pk.includes('migration_newer:error'), 'package check: ' + pk.join(','));
			expect(await p.getAttribute('#nuIcAck', 'form') === 'updInstallForm' && await p.evaluate(() => document.getElementById('nuIcAck').required), 'tick needed');
			// the server refuses without the tick (reinstall set, so only the integrity check can stop it)
			const txt = await p.evaluate(async () => { const f = document.getElementById('updInstallForm'), fd = new FormData(f); fd.delete('integrity_ack'); fd.set('reinstall', '1');
				return (await fetch(f.getAttribute('action'), { method: 'POST', body: fd })).text(); });
			const msgs = ['en', 'de'].map(l => (fs.readFileSync(path.resolve(__dirname, '../../lang/' + l + '.php'), 'utf8').match(/'ic_ack_needed' => '((?:[^'\\]|\\.)*)'/) || [])[1]).filter(Boolean)
				.map(m => m.replace(/\\'/g, "'").replace(/"/g, '&quot;').slice(0, 40));
			expect(msgs.some(m => txt.includes(m)), 'install refused without the tick');
			expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'integrity_ignored'") === '0', 'not installed');
			await open(p, 'index.php?p=update');
			if (await p.locator('#updCancel').count()) await Promise.all([p.waitForLoadState('networkidle'), p.click('#updCancel')]);
		}
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(keep) }); fs.unlinkSync(keep);
		sql("DELETE FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '9999-e2e-future'");
	}
};

T.libs_github_links = async (s) => {                     // 0.8.891: Update → Libraries – "GitHub" in Available opens the packs release, names in the package their project
	const p = s.ga;
	sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_libs_e2e'; UPDATE zzzzsys_nunext_setting SET zzzzsys_nunext_setting_id = 'github_libs_e2e' WHERE zzzzsys_nunext_setting_id = 'github_libs'");   // kept aside, put back below
	const st = { repo: 'nuBuilder/nuBuilder-Next', checked_at: Math.floor(Date.now() / 1000), built: '', error: '',
		libs: [{ name: 'adminer', version: '99.9.9', title: 'Adminer', file: 'nunext-lib-adminer-99.9.9.zip', size: 1, sha256: '0'.repeat(64), url: 'https://github.com/nuBuilder/nuBuilder-Next/releases/download/libraries/nunext-lib-adminer-99.9.9.zip' }] };
	sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('github_libs', '" + JSON.stringify(st).replace(/'/g, "''") + "')");
	try {
		await open(p, 'index.php?p=update&tab=libraries');
		const gh = await p.evaluate(() => { const a = document.querySelector('#lpTable tr[data-slot="adminer"] a.nuLpGhLink'); return a ? [a.getAttribute('href'), a.target, a.rel] : null; });
		expect(gh && gh[0] === 'https://github.com/nuBuilder/nuBuilder-Next/releases/tag/libraries' && gh[1] === '_blank' && /noopener/.test(gh[2]), 'GitHub link in Available: ' + JSON.stringify(gh));
		const prj = await p.evaluate(() => [...document.querySelectorAll('#lpTable tr[data-slot] a.nuLpProject')].map(a => [a.closest('tr').dataset.slot, a.getAttribute('href'), a.target]));   // 0.8.892
		const pm = prj.find(x => x[0] === 'phpmailer');
		expect(prj.length === await p.locator('#lpTable tr[data-slot]').count() && pm && pm[1] === 'https://github.com/PHPMailer/PHPMailer' && pm[2] === '_blank', 'pack project links: ' + JSON.stringify(prj));
		await p.locator('#lpTable tr[data-slot="phpmailer"] label').click();
		expect(await p.locator('#lpSel-phpmailer').isChecked(), 'the name still ticks the row');
		const proj = await p.evaluate(() => [...document.querySelectorAll('#updLibraries a.nuLibProject')].map(a => [a.textContent.trim(), a.getAttribute('href'), a.target]));
		const bs = proj.find(x => x[0] === 'Bootstrap');
		expect(proj.length >= 15 && bs && bs[1] === 'https://github.com/twbs/bootstrap' && bs[2] === '_blank', 'project links in the package: ' + JSON.stringify(proj.slice(0, 3)) + ' (' + proj.length + ')');
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_libs'; UPDATE zzzzsys_nunext_setting SET zzzzsys_nunext_setting_id = 'github_libs' WHERE zzzzsys_nunext_setting_id = 'github_libs_e2e'");
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.1065: Update → Libraries, ⋯ → "Remove older versions…" – a dialogue lists the versions that are not in use, the ticked ones go
T.libs_remove_older = async (s) => {
	const p = s.ga;
	if (!SITE) return 'skip';
	const packs = path.join(SITE, 'vendor', 'packs');
	let state = {};
	try { state = JSON.parse(fs.readFileSync(path.join(packs, 'nunext-libs.php'), 'utf8').replace(/^<\?php[^\n]*\n/, '')); } catch (e) { /* none */ }
	const slot = ['uppy', 'phpmailer'].find(x => state[x] && fs.existsSync(path.join(packs, x + '-' + state[x].active, 'nunext-lib.json')));
	if (!slot) return 'skip';                                 // needs an installed pack to copy
	const active = state[slot].active, src = path.join(packs, slot + '-' + active);
	const fake = ['0.0.1', '0.0.2'];
	const mk = v => { const d = path.join(packs, slot + '-' + v); fs.mkdirSync(d, { recursive: true });
		const m = JSON.parse(fs.readFileSync(path.join(src, 'nunext-lib.json'), 'utf8')); m.version = v;
		fs.writeFileSync(path.join(d, 'nunext-lib.json'), JSON.stringify(m)); fs.writeFileSync(path.join(d, 'x.txt'), 'x'); return d; };
	const dirs = fake.map(mk);
	try {
		await open(p, 'index.php?p=update&tab=libraries');
		const row = p.locator('#lpTable tr[data-slot="' + slot + '"]');
		await row.locator('.dropdown > button').click();
		await row.locator('.nuLpOldOpen').click();
		const dlg = p.locator('#lpOld-' + slot);
		await dlg.waitFor({ state: 'visible' });
		const list = await dlg.evaluate(d => [...d.querySelectorAll('.nuLpOldChk')].map(c => [c.value, c.checked]));
		expect(fake.every(v => list.some(x => x[0] === v && x[1])) && !list.some(x => x[0] === active), 'older versions listed and ticked, not the one in use: ' + JSON.stringify(list));
		expect((await dlg.locator('.modal-body p').innerText()).includes(active), 'the hint names the version in use');
		for (const x of list) if (x[0] !== '0.0.1') await dlg.locator('.nuLpOldChk[value="' + x[0] + '"]').uncheck();
		await dlg.locator('.nuLpOldChk[value="0.0.1"]').uncheck();
		expect(await dlg.locator('.nuLpOldGo').isDisabled(), 'nothing ticked: the button is off');
		await dlg.locator('.nuLpOldChk[value="0.0.1"]').check();
		await Promise.all([p.waitForNavigation(), dlg.locator('.nuLpOldGo').click()]);
		if (/p=reauth/.test(p.url())) { await p.fill('input[type=password]', GA[1]); await Promise.all([p.waitForNavigation(), p.locator('form button[type=submit]').first().click()]); }
		expect(!fs.existsSync(dirs[0]) && fs.existsSync(dirs[1]) && fs.existsSync(src), 'only the ticked version is gone: ' + JSON.stringify(dirs.map(d => fs.existsSync(d))));
		expect(/removed 0\.0\.1/.test(await p.evaluate(() => document.body.innerText)), 'the notice names it');
		const st2 = JSON.parse(fs.readFileSync(path.join(packs, 'nunext-libs.php'), 'utf8').replace(/^<\?php[^\n]*\n/, ''));
		expect(st2[slot].active === active, 'the version in use is unchanged');
		// the active version cannot be removed this way, even when posted
		const tok = await p.evaluate(() => (document.querySelector('input[name=_csrf]') || {}).value || '');
		const r = await p.context().request.post(BASE + 'index.php?p=update', { form: { _csrf: tok, action: 'lp_remove_old', slot: slot, 'versions[]': active }, maxRedirects: 0 });
		expect(fs.existsSync(src) && r.status() < 500, 'posting the version in use removes nothing (' + r.status() + ')');
	} finally {
		for (const d of dirs) fs.rmSync(d, { recursive: true, force: true });
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.update_folder = async (s) => {                         // 0.8.372: packages in <site>/_update/ – listed, only signed ones checked
	if (!SITE) { s.skip = 'SITE not set'; return; }
	const dir = path.join(SITE, '_update');
	fs.mkdirSync(dir, { recursive: true });
	const made = [];
	const py = (code) => execSync('python3 -', { input: code });
	py("import zipfile\nwith zipfile.ZipFile(" + JSON.stringify(path.join(dir, 'e2e-other.zip')) + ",'w') as z: z.writestr('readme.txt','x')\n"); made.push('e2e-other.zip');
	if (process.env.PKG && fs.existsSync(process.env.PKG)) {
		fs.copyFileSync(process.env.PKG, path.join(dir, 'e2e-signed.zip')); made.push('e2e-signed.zip');
		py("import zipfile, json\nsrc=" + JSON.stringify(process.env.PKG) + "\ndst=" + JSON.stringify(path.join(dir, 'e2e-unsigned.zip')) + "\nwith zipfile.ZipFile(src) as zi, zipfile.ZipFile(dst,'w',zipfile.ZIP_DEFLATED) as zo:\n  for it in zi.infolist():\n    d=zi.read(it.filename)\n    if it.filename.endswith('nunext-manifest.json'):\n      m=json.loads(d); m['signature']=''; d=json.dumps(m).encode()\n    zo.writestr(it, d)\n");
		made.push('e2e-unsigned.zip');
	}
	try {
		const p = s.ga;
		await open(p, 'index.php?p=update');
		const rows = await p.evaluate(() => Object.fromEntries([...document.querySelectorAll('#updFolder label[data-file]')].map(l => [l.dataset.file, l.querySelector('input').disabled])));
		expect(rows['e2e-other.zip'] === true, 'a zip that is no package is not refused: ' + JSON.stringify(rows));
		for (const bad of ['e2e-other.zip', '../index.php', 'e2e-unsigned.zip']) {
			const st = await csrfPost(p, 'index.php?p=update', { action: 'folder_check', name: bad });
			await open(p, 'index.php?p=update');
			expect(!/check=/.test(p.url()), 'folder_check accepted ' + bad + ' (' + st + ')');
		}
		if (made.includes('e2e-signed.zip')) {
			expect(rows['e2e-signed.zip'] === false && rows['e2e-unsigned.zip'] === true, 'signed / unsigned: ' + JSON.stringify(rows));
			await p.check('#updFolder input[value="e2e-signed.zip"]');
			await Promise.all([p.waitForNavigation(), p.click('#updFolderCheck')]); await p.waitForLoadState('networkidle');
			expect(/check=/.test(p.url()) && /e2e-signed\.zip/.test(await p.locator('#updFromFolder').textContent()), 'no check page for the folder package');
			await Promise.all([p.waitForNavigation(), p.click('#updCancel')]);
			expect(fs.existsSync(path.join(dir, 'e2e-signed.zip')), 'Cancel removed the package from the folder');
		}
	} finally { for (const f of made) try { fs.unlinkSync(path.join(dir, f)); } catch (e) { /* gone */ } }
};

T.github_update = async (s) => {                        // 0.8.374: newest release on GitHub – badge, download button, settings (offline; GITHUB=1 asks GitHub)
	const p = s.ga;
	const put = (o) => sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update';" + (o ? " INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value, updated_at, updated_by) VALUES ('github_update', '" + JSON.stringify(o).replace(/'/g, "''") + "', NOW(), 'e2e')" : ''));
	try {
		put({ repo: 'nuBuilder/nuBuilder-Next', pre: true, auto: false, checked_at: Math.floor(Date.now() / 1000), error: '',
			latest: { version: '9.9.9', tag: 'v9.9.9', name: 'e2e', url: 'https://github.com/nuBuilder/nuBuilder-Next/releases/download/v9.9.9/nunext.zip', size: 1, published: '2026-10-22T00:00:00Z', pre: true, page: 'https://github.com/nuBuilder/nuBuilder-Next/releases/tag/v9.9.9' } });
		await open(p, 'index.php?p=home');
		expect(/New: 9\.9\.9/.test(await p.content()), 'no "New" badge on the Update tile');
		await open(p, 'index.php?p=update');
		expect(/9\.9\.9/.test(await p.locator('#updGitHubLatest').textContent()) && await p.locator('#updGitHubDownload').count() === 1, 'newer release not offered');
		// 0.8.673: interval select – a row of older versions (auto false) reads as Never; Settings button right after the title
		expect(await p.locator('#ghEvery').inputValue() === 'never' && !/checks/.test(await p.locator('#updGitHubChecked').textContent()), 'old auto:false row is not "Never"');
		const tb = await p.locator('#updGitHubToggle').boundingBox(), sb = await p.locator('#updGitHubSetBtn').boundingBox();
		expect(sb.x - (tb.x + tb.width) < 80, 'Settings button far from the title: ' + Math.round(sb.x - tb.x - tb.width) + ' px');
		await csrfPost(p, 'index.php?p=update', { action: 'github_settings', repo: 'a/../b', pre: '1' });
		expect(/9\.9\.9/.test(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'")), 'an invalid repository was saved');
		await csrfPost(p, 'index.php?p=update', { action: 'github_settings', repo: 'someone/fork', pre: '1' });
		const st = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'"));
		expect(st.repo === 'someone/fork' && st.latest === null && st.auto === false, 'settings: ' + JSON.stringify(st));
		await csrfPost(p, 'index.php?p=update', { action: 'github_settings', repo: 'someone/fork', pre: '1', every: 'week' });
		const sw = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'"));
		expect(sw.every === 'week' && sw.auto === true, 'interval not saved: ' + JSON.stringify(sw));
		await open(p, 'index.php?p=update');
		expect(await p.locator('#ghEvery').inputValue() === 'week' && /checks once a week/.test(await p.locator('#updGitHubChecked').textContent()), 'interval not shown');
		await csrfPost(p, 'index.php?p=update', { action: 'github_settings', repo: 'someone/fork', pre: '1', every: 'bogus' });
		expect(JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'")).every === 'week', 'unknown interval changed the setting');
		await open(p, 'index.php?p=update');
		expect(await p.locator('#updGitHubDownload').count() === 0 && /someone\/fork/.test(await p.locator('#updGitHubRepo').textContent()), 'changed repository still offers the old release');
		if (process.env.GITHUB === '1') {
			put(null);
			await open(p, 'index.php?p=update');
			await Promise.all([p.waitForNavigation(), p.click('#updGitHubCheck')]); await p.waitForLoadState('networkidle');
			expect(await p.locator('#updGitHubError').count() === 0 && await p.locator('#updGitHubLatest').count() === 1, 'GitHub check failed');
			expect(await p.locator('.nuFlashToast.show').count() === 1, 'no toast with the answer of GitHub');   // 0.8.377
		}
	} finally { put(null); }
};

T.docs_newer = async (s) => {                           // 0.8.393: help and tutorial say when GitHub has a newer version
	const prev = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'");
	const put = (v) => sql(`REPLACE INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('github_update', '{"repo":"nuBuilder/nuBuilder-Next","pre":true,"every":"never","checked_at":${Math.floor(Date.now() / 1000)},"latest":{"version":"${v}","tag":"v${v}","name":"${v}","url":"","size":1,"published":"","page":"","pre":false},"error":""}')`);
	try {
		put('9.9.9');
		for (const r of ['help', 'tutorial']) {
			await open(s.ga, 'index.php?p=' + r);
			expect(await s.ga.locator('#nuDocsNewer').count() === 1 && /9\.9\.9/.test(await s.ga.locator('#nuDocsNewer').textContent()), r + ': no notice of the newer version');
		}
		const anna = await login('sample_anna', UPW);
		await open(anna, 'index.php?p=help');
		expect(await anna.locator('#nuDocsNewer').count() === 0, 'notice for a user without the update right');
		await anna.context().close();
		put('0.0.1');
		await open(s.ga, 'index.php?p=help');
		expect(await s.ga.locator('#nuDocsNewer').count() === 0, 'notice although GitHub has no newer version');
	} finally {
		if (prev === '') sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'github_update'");
		else sql("UPDATE zzzzsys_nunext_setting SET setting_value = '" + prev.replace(/'/g, "''") + "' WHERE zzzzsys_nunext_setting_id = 'github_update'");
	}
};

T.update_layout = async (s) => {                        // 0.8.376: GitHub first, other ways folded; open / closed remembered in the browser
	const p = s.ga;
	await p.evaluate(() => { try { localStorage.removeItem('nunext-upd-other'); } catch (e) { /* */ } });
	await open(p, 'index.php?p=update');
	const order = await p.evaluate(() => { const g = document.getElementById('updGitHub'), o = document.getElementById('updOther'); return !!(g && o && (g.compareDocumentPosition(o) & Node.DOCUMENT_POSITION_FOLLOWING)); });
	expect(order, 'GitHub is not above the other ways');
	expect(!(await p.locator('#updOther').isVisible()), 'other ways are open at first');
	await p.click('#updOtherBtn'); await p.waitForTimeout(600);
	await open(p, 'index.php?p=update');
	expect(await p.locator('#updOther').isVisible() && await p.locator('#updOtherBtn').getAttribute('aria-expanded') === 'true', 'open state not remembered');
	await p.click('#updOtherBtn'); await p.waitForTimeout(600);
	await open(p, 'index.php?p=update');
	expect(!(await p.locator('#updOther').isVisible()), 'closed state not remembered');
	// tabs (0.8.392): the tab is part of the address; a form post in a tab comes back to it; old anchors open their tab
	const active = async () => p.locator('#updTabs .nav-link.active').getAttribute('data-nu-tab');
	expect(await active() === 'update' && !(await p.locator('#updLibraries').isVisible()), 'update tab not first');
	await p.click('[data-nu-tab="system"]'); await p.waitForTimeout(400);
	expect(/#system$/.test(p.url()) && await p.locator('#updRequirements').isVisible(), 'system tab');
	await p.click('[data-nu-tab="libraries"]'); await p.waitForTimeout(400);
	expect(/#libraries$/.test(p.url()) && await p.locator('#updLibraries').isVisible() && await p.locator('#updLibPacks').isVisible(), 'libraries tab (0.8.405)');
	await open(p, 'index.php?p=update#backups'); await p.waitForTimeout(400);
	expect(await active() === 'backups', 'address #backups');
	const keep = await p.inputValue('#updKeep');
	await Promise.all([p.waitForNavigation(), p.click('#updKeepForm button[type=submit]')]); await p.waitForTimeout(400);
	expect(await active() === 'backups' && /#backups$/.test(p.url()), 'after saving the keep rule: ' + p.url());
	await p.fill('#updKeep', keep);
	await open(p, 'index.php?p=update#updHistory'); await p.waitForTimeout(400);
	expect(await active() === 'history', 'old anchor #updHistory');
	await p.click('[data-nu-tab="update"]'); await p.waitForTimeout(300);
	expect(!/#/.test(p.url()), 'first tab keeps a # in the address');
};

// Update page → Backups (0.8.451): several deleted at once, the newest and the safety backup protected (also against a forged request),
// the clean-up rule with preview, saved rule; paging the list opens the Backups tab from the server (?tab=) without hiding it first
// backups (0.8.615): "nuBuilder tables" = every zzzzsys_* table (nuBuilder Forte's and nuBuilder Next's, all an update can change), no app tables
T.backup_scope = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const p = s.ga, dir = path.join(SITE, '_backup');
	const list = () => fs.existsSync(dir) ? fs.readdirSync(dir).filter(f => /^nunext-.*\.zip$/.test(f)) : [];
	const before = list();
	await open(p, 'index.php?p=update&tab=backups#backups');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update&tab=backups#backups'); }
	const opts = await p.$$eval('#updBkScope option', os => os.map(o => o.value));
	expect(opts.join() === 'none,nubuilder,all', 'database choices: ' + opts);
	await p.selectOption('#updBkScope', 'nubuilder');
	let made = [];
	try {
		await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#updBackupForm button[type=submit]')]);
		made = list().filter(f => !before.includes(f));
		expect(made.length === 1, 'one backup made: ' + made);
		const tables = execSync('python3 -', { input: "import zipfile, gzip, re\nz=zipfile.ZipFile(" + JSON.stringify(path.join(dir, made[0])) + ")\nsql=gzip.decompress(z.read('db/database.sql.gz')).decode('utf-8','replace')\nprint(' '.join(sorted(set(re.findall(r'CREATE TABLE `([^`]+)`', sql)))))" }).toString().trim().split(' ');
		const db = sql("SELECT GROUP_CONCAT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT LIKE 'zzzzsys\\_%' AND TABLE_NAME NOT LIKE '\\_\\_\\_nu%'").split(',').filter(Boolean);
		expect(tables.length > 20 && tables.every(t => t.startsWith('zzzzsys_')), 'only zzzzsys_ tables: ' + tables.filter(t => !t.startsWith('zzzzsys_')).join(', '));
		expect(['zzzzsys_php', 'zzzzsys_object', 'zzzzsys_config', 'zzzzsys_nunext_setting'].every(t => tables.includes(t)), 'nuBuilder Forte and nuBuilder Next tables in it');
		expect(db.length === 0 || db.every(t => !tables.includes(t)), 'no application table');
		await open(p, 'index.php?p=update&tab=backups#backups');
		expect(await p.locator('#updBackups .badge.text-bg-success[title*="zzzzsys_"], #updBackups .badge.text-bg-success[data-bs-original-title*="zzzzsys_"]').count() >= 1, 'SQL badge names the scope');
	} finally {
		for (const f of made) fs.rmSync(path.join(dir, f), { force: true });
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// Restore the database part of a backup (0.8.1031): a whole-database backup with a view, a trigger and a procedure (DELIMITER blocks
// in the dump), numbers written without quotes; changes afterwards are undone, the safety backup is made first.
T.backup_restore = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const p = s.ga, dir = path.join(SITE, '_backup');
	const list = () => fs.existsSync(dir) ? fs.readdirSync(dir).filter(f => /^nunext-.*\.zip$/.test(f)) : [];
	const before = list();
	const sessDump = execSync('mysqldump --no-create-info --insert-ignore ' + DB + ' zzzzsys_session zzzzsys_nunext_session zzzzsys_nunext_token').toString();
	const reauth = async (url) => {
		await open(p, url);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, url); }
	};
	sql("DROP VIEW IF EXISTS e2e_rst_v; DROP TABLE IF EXISTS e2e_rst, e2e_rst_new; DROP PROCEDURE IF EXISTS e2e_rst_p;\n"
		+ "CREATE TABLE e2e_rst (id int PRIMARY KEY, n decimal(10,2), s varchar(30));\nINSERT INTO e2e_rst VALUES (1, -12.50, 'a;b'), (2, NULL, 'it''s');\n"
		+ "CREATE VIEW e2e_rst_v AS SELECT id FROM e2e_rst;\nDELIMITER $$\nCREATE TRIGGER e2e_rst_tr BEFORE INSERT ON e2e_rst FOR EACH ROW BEGIN SET NEW.s = CONCAT(NEW.s, ';x'); END $$\n"
		+ "CREATE PROCEDURE e2e_rst_p() BEGIN SELECT 1; SELECT 2; END $$\nDELIMITER ;\n");
	let made = [];
	try {
		await reauth('index.php?p=update&tab=backups#backups');
		await p.selectOption('#updBkScope', 'all');
		await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#updBackupForm button[type=submit]')]);
		made = list().filter(f => !before.includes(f));
		expect(made.length === 1, 'one backup made: ' + made);
		const dump = execSync('python3 -', { input: "import zipfile, gzip\nz=zipfile.ZipFile(" + JSON.stringify(path.join(dir, made[0])) + ")\nprint(gzip.decompress(z.read('db/database.sql.gz')).decode('utf-8','replace'))", maxBuffer: 256 << 20 }).toString();
		expect(/\(1, -12\.50, 'a;b'\)/.test(dump), 'numbers without quotes in the dump');
		expect(/DELIMITER \$\$\nCREATE TRIGGER `?e2e_rst_tr`? /.test(dump), 'trigger between DELIMITER lines');
		// changes after the backup
		sql("UPDATE e2e_rst SET s = 'changed' WHERE id = 2; DROP PROCEDURE e2e_rst_p; CREATE TABLE e2e_rst_new (a int);");
		await reauth('index.php?p=update&tab=backups#backups');
		const link = p.locator('#updBackups a.updRestore[href*="' + made[0] + '"]');
		expect(await link.count() === 1, 'Restore database button on the backup row');
		await Promise.all([p.waitForNavigation(), link.click()]);
		await p.waitForLoadState('networkidle');
		expect(await p.locator('#updRestoreCard #updRestoreForm').count() === 1, 'restore card with the form (' + p.url() + '): ' + (await p.textContent('#updRestoreCard').catch(() => '')).slice(0, 200));
		expect(/\d+ table/.test(await p.textContent('#updRestoreInfo')), 'what the dump creates');
		expect(await p.locator('#rstSafetyNote').count() === 1 && await p.locator('#rstVerifyNote').count() === 1, 'notes: backup first, row counts compared');
		await p.click('#rstRun');
		await p.waitForTimeout(400);
		await Promise.all([p.waitForNavigation({ timeout: 180000 }), p.click('#nuConfirm.show .js-confirm-ok')]);
		await p.waitForLoadState('networkidle');
		const flash = (await p.locator('.alert-success').allTextContents()).join(' | ');
		expect(/restored/i.test(flash), 'success message: ' + flash);
		expect(/Row counts checked/.test(flash), 'row counts compared: ' + flash);
		expect(sql("SELECT s FROM e2e_rst WHERE id = 2") === "it's", 'row back as it was');
		expect(sql("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'e2e_rst_p'") === '1', 'procedure back');
		expect(sql("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'e2e_rst_tr'") === '1', 'trigger back');
		expect(sql("SELECT COUNT(*) FROM e2e_rst_v") === '2', 'view back');
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e2e_rst_new'") === '0', 'table made afterwards is gone');
		expect(list().filter(f => !before.includes(f)).length === 2, 'safety backup made first');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'database_restore'") !== '0', 'logged');
		await open(p, 'index.php?p=home');
		expect(!/p=login/.test(p.url()), 'still signed in');
	} finally {
		execSync('mysql ' + DB, { input: sessDump });   // the other test users stay signed in
		sql('DROP VIEW IF EXISTS e2e_rst_v; DROP TABLE IF EXISTS e2e_rst, e2e_rst_new; DROP PROCEDURE IF EXISTS e2e_rst_p;');
		for (const f of list().filter(f => !before.includes(f))) fs.rmSync(path.join(dir, f), { force: true });
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// A restore that fails after the tables were dropped (0.8.1032): the backup made first is put back by itself.
T.backup_restore_fail = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const p = s.ga, dir = path.join(SITE, '_backup');
	const list = () => fs.existsSync(dir) ? fs.readdirSync(dir).filter(f => /^nunext-.*\.zip$/.test(f)) : [];
	const before = list();
	const sessDump = execSync('mysqldump --no-create-info --insert-ignore ' + DB + ' zzzzsys_session zzzzsys_nunext_session zzzzsys_nunext_token').toString();
	const reauth = async (url) => {
		await open(p, url);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, url); }
	};
	sql("DROP TABLE IF EXISTS e2e_rst, e2e_rst_new; CREATE TABLE e2e_rst (id int PRIMARY KEY, s varchar(30)); INSERT INTO e2e_rst VALUES (1, 'a'), (2, 'b');");
	const broken = 'nunext-0.0.1-broken-' + Date.now() + '.zip';
	try {
		await reauth('index.php?p=update&tab=backups#backups');
		await p.selectOption('#updBkScope', 'all');
		await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#updBackupForm button[type=submit]')]);
		const made = list().filter(f => !before.includes(f));
		expect(made.length === 1, 'one backup made: ' + made);
		// a copy whose dump fails half way: a duplicate key just before the end marker (complete and allowed, but it cannot run)
		execSync('python3 -', { input: "import zipfile, gzip\nsrc=" + JSON.stringify(path.join(dir, made[0])) + "\ndst=" + JSON.stringify(path.join(dir, broken))
			+ "\nzi=zipfile.ZipFile(src)\nzo=zipfile.ZipFile(dst,'w')\nfor i in zi.infolist():\n  d=zi.read(i.filename)\n  if i.filename=='db/database.sql.gz':\n"
			+ "    t=gzip.decompress(d).decode('utf-8')\n    k=t.rindex('\\n-- nuBuilder Next SQL dump completed')\n    t=t[:k]+\"\\nINSERT INTO `e2e_rst` (`id`, `s`) VALUES (1, 'dup');\\n\"+t[k:]\n    d=gzip.compress(t.encode('utf-8'))\n"
			+ "    zo.writestr(i, d, compress_type=zipfile.ZIP_STORED)\n  else: zo.writestr(i, d, compress_type=zipfile.ZIP_DEFLATED)\nzo.close()\n", maxBuffer: 64 << 20 });
		sql("UPDATE e2e_rst SET s = 'changed' WHERE id = 2; CREATE TABLE e2e_rst_new (a int);");
		await reauth('index.php?p=update&tab=backups&restore=' + broken + '#backups');
		expect(await p.locator('#updRestoreForm').count() === 1, 'the broken copy passes the check');
		await p.click('#rstRun');
		await p.waitForTimeout(400);
		await Promise.all([p.waitForNavigation({ timeout: 180000 }), p.click('#nuConfirm.show .js-confirm-ok')]);
		await p.waitForLoadState('networkidle');
		const err = (await p.locator('.alert-danger').allTextContents()).join(' | ');
		expect(/put back/.test(err) && /Duplicate/i.test(err), 'error names the failure and the way back: ' + err);
		expect(sql("SELECT s FROM e2e_rst WHERE id = 2") === 'changed', 'database as it was before the restore');
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e2e_rst_new'") === '1', 'tables made since are back too');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'database_restore_failed'") !== '0', 'logged');
		expect(!fs.existsSync(path.join(dir, 'maintenance.json')), 'no maintenance mode left');
	} finally {
		execSync('mysql ' + DB, { input: sessDump });
		sql('DROP TABLE IF EXISTS e2e_rst, e2e_rst_new;');
		for (const f of list().filter(f => !before.includes(f))) fs.rmSync(path.join(dir, f), { force: true });
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.backups_bulk = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'nuconfig.php'))) { s.skip = 'SITE not set'; return; }
	const p = s.ga, dir = path.join(SITE, '_backup');
	const list = () => fs.existsSync(dir) ? fs.readdirSync(dir).filter(f => /^nunext-.*\.zip$/.test(f)) : [];
	const before = list();
	const keepRule = sql("SELECT IFNULL((SELECT HEX(setting_value) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'backup_cleanup'), '-')");
	const go = async (q) => {                            // a real load every time (the same address with #backups would only scroll)
		q = q.replace('#', '&_t=' + Date.now() + '#');
		await open(p, 'index.php?p=update' + q);
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update' + q); }
	};
	let made = [];
	try {
		await go('&tab=backups#backups');
		await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#updBackupForm button[type=submit]')]);
		const real = list().filter(f => !before.includes(f));
		expect(real.length === 1, 'one real backup made: ' + real);
		made = made.concat(real);
		const fake = require('child_process').execFileSync('python3', [path.join(__dirname, 'fakebackups.py'), dir, path.join(dir, real[0]), '14']).toString().trim().split('\n');
		made = made.concat(fake);
		// paging: the server opens the tab, nothing hidden while the page loads
		await p.addInitScript(() => {
			window.__nuPend = null;
			new MutationObserver(function (m, o) { if (document.getElementById('updPanes')) { window.__nuPend = document.documentElement.classList.contains('nuTabsPending'); o.disconnect(); } })
				.observe(document, { childList: true, subtree: true });
		});
		await go('&tab=backups#backups');
		const total = before.length + 15;
		expect(await p.locator('#updBackups .updBkSelCol .bi-lock').count() >= 1, 'newest backup locked');
		await p.click('.updBackupsNav a.nuPagerNext');
		await p.waitForURL(/pg=2/); await p.waitForLoadState('load');
		expect(/tab=backups/.test(p.url()) && /pg=2/.test(p.url()), 'pager keeps the tab: ' + p.url());
		expect(await p.evaluate(() => window.__nuPend) === false, 'paging: tab not hidden while loading');
		expect(await p.locator('#updPane-backups.active').count() === 1, 'backups tab open');
		// select three on page 1 and delete them
		await go('&tab=backups#backups');
		const boxes = p.locator('.updBkSel');
		const names = [];
		for (let i = 0; i < 3; i++) { await boxes.nth(i).check(); names.push(await boxes.nth(i).getAttribute('value')); }
		expect(/\(3\)/.test(await p.textContent('#updBkDelSel')), 'button counts the selection');
		expect(/^Delete 3 backups/.test(await p.getAttribute('#updBkBulk', 'data-confirm')), 'confirm text: ' + await p.getAttribute('#updBkBulk', 'data-confirm'));
		await p.click('#updBkDelSel');
		const ok = p.locator('#nuConfirm .js-confirm-ok');
		await ok.waitFor({ state: 'visible' });
		await Promise.all([p.waitForNavigation(), ok.click()]);
		expect(names.every(n => !list().includes(n)) && list().length === total - 3, 'three deleted: ' + list().length);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'nunext_backups_deleted' AND details LIKE '%\"count\":3%'") !== '0', 'logged');
		// a forged request with the newest (protected) backup: skipped
		const newest = real[0];
		await p.evaluate((n) => { const f = document.getElementById('updBkBulk'); const i = document.createElement('input'); i.type = 'hidden'; i.name = 'names[]'; i.value = n; f.appendChild(i); f.dataset.confirmed = '1'; f.submit(); }, newest);
		await p.waitForLoadState('networkidle');
		expect(list().includes(newest), 'protected backup kept');
		// clean-up rule: keep the newest 4 (+ the protected safety backup if older)
		await p.click('#updBkCleanBtn'); await p.waitForTimeout(400);
		await p.fill('#updBkKeepN', '4');
		const prev = await p.textContent('#updBkCleanPreview');
		const n = Number((prev.match(/Would delete (\d+)/) || [])[1]);
		expect(n > 0, 'preview: ' + prev);
		const left = list().length;
		await p.click('#updBkCleanRun');
		await ok.waitFor({ state: 'visible' });
		await Promise.all([p.waitForNavigation(), ok.click()]);
		expect(list().length === left - n, 'rule deleted what the preview said: ' + (left - list().length) + ' / ' + n);
		// save the rule only (no confirm, nothing deleted)
		await p.click('#updBkCleanBtn'); await p.waitForTimeout(400);
		await p.check('#updBkModeAge'); await p.fill('#updBkDays', '30'); await p.check('#updBkAuto');
		const left2 = list().length;
		await Promise.all([p.waitForNavigation(), p.click('#updBkCleanSave')]);
		const rule = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'backup_cleanup'"));
		expect(rule.mode === 'age' && rule.n === 30 && rule.auto === true && list().length === left2, 'rule saved: ' + JSON.stringify(rule));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		for (const f of made) fs.rmSync(path.join(dir, f), { force: true });
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'backup_cleanup'");
		if (keepRule !== '-') sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('backup_cleanup', UNHEX('" + keepRule + "'))");
	}
};

// database not set up (0.8.645, NuDbState / ?p=dbsetup): a table missing → the sign-in page says so, users cannot sign in, the globeadmin
// lands on the set-up page ("partial"), "Create missing tables" adds it; a nuBuilder Forte column missing → "old", nothing changed
T.db_setup = async (s) => {
	const has = () => sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zzzzsys_file'") === '1';
	sql('RENAME TABLE zzzzsys_file TO e2e_file_keep');
	let ga = null, ga2 = null;
	try {
		const anon = await (await browser.newContext({ locale: 'en-GB' })).newPage();
		await anon.goto(BASE + 'index.php?p=login');
		expect(await anon.locator('#nuLoginDbSetup').count() === 1, 'sign-in page: no hint that the database is not set up');
		await anon.fill('#username', 'sample_ben'); await anon.fill('#password', UPW);
		await Promise.all([anon.waitForLoadState('networkidle'), anon.click('form button[type=submit]')]);
		expect(/not set up/i.test(await anon.textContent('.alert-danger')), 'a user could sign in while the database is not set up');
		await anon.context().close();
		ga = await login(GA[0], GA[1]);
		expect(/p=dbsetup/.test(ga.url()) && await ga.getAttribute('#dbsPage', 'data-state') === 'partial', 'globeadmin not sent to the set-up: ' + ga.url());
		expect((await ga.locator('#dbsMissing li').allTextContents()).join() === 'zzzzsys_file', 'missing tables listed');
		await open(ga, 'index.php?p=users');
		expect(/p=dbsetup/.test(ga.url()), 'other pages open while the database is not set up');
		await Promise.all([ga.waitForNavigation(), ga.click('#dbsRepair')]);
		expect(await ga.getAttribute('#dbsPage', 'data-state') === 'done' && has(), 'missing table not created');
		await open(ga, 'index.php?p=users');
		expect(/p=users/.test(ga.url()), 'users after the repair: ' + ga.url());
		// nuBuilder Forte older than 4.9: explained only
		sql('ALTER TABLE zzzzsys_form CHANGE sfo_status e2e_sfo_status varchar(1) DEFAULT NULL');
		try {
			ga2 = await login(GA[0], GA[1]);
			expect(await ga2.getAttribute('#dbsPage', 'data-state') === 'old' && /zzzzsys_form\.sfo_status/.test(await ga2.textContent('#dbsPage')), 'old database not explained');
			expect(await ga2.locator('#dbsSetup, #dbsRepair').count() === 0, 'old database: an action is offered');
		} finally {
			sql('ALTER TABLE zzzzsys_form CHANGE e2e_sfo_status sfo_status varchar(1) DEFAULT NULL');
		}
		expect(ga.errors.length === 0, ga.errors.slice(0, 2).join(' | '));
	} finally {
		if (has()) sql('DROP TABLE zzzzsys_file');                  // the one the repair created – the original comes back with its rows
		sql('RENAME TABLE e2e_file_keep TO zzzzsys_file');
		if (ga) await ga.context().close();
		if (ga2) await ga2.context().close();
	}
};

T.existing_db = async (s) => {                          // 0.8.1008: an existing nuBuilder Forte database is no longer blocked or warned about
	const before = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'db_origin'");
	const origin = v => sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'db_origin';" + (v ? " INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value, updated_at, updated_by) VALUES ('db_origin', '" + v + "', NOW(), 'e2e')" : ''));
	const p = s.ga;
	try {
		for (const o of ['nubuilder', 'legacy']) {
			origin(o);
			let r = await open(p, 'index.php?p=home&tiles=1');
			expect(r.status() === 200 && await p.locator('#nuExistingDbWarn').count() === 0, o + ': start page blocked or warned (' + r.status() + ')');
			r = await open(p, 'index.php?p=users');
			expect(r.status() === 200, o + ': users list ' + r.status());
		}
	} finally {
		origin(before || null);
	}
};

T.update_quick = async (s) => {
	const pkg = process.env.PKG;
	if (!pkg || !fs.existsSync(pkg)) { s.skip = 'PKG not set'; return; }
	const p = s.ga;
	const ver = (f) => execSync('python3 -', { input: "import zipfile, json\nz=zipfile.ZipFile(" + JSON.stringify(f) + ")\nn=[x for x in z.namelist() if x.endswith('nunext-manifest.json')][0]\nprint(json.loads(z.read(n))['version'])" }).toString().trim();
	const pv = ver(pkg);
	await open(p, 'index.php?p=update');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update'); }
	const installed = SITE ? (fs.readFileSync(path.join(SITE, 'core/nuapp.php'), 'utf8').match(/define\('NU_VERSION', '([^']+)'\)/) || ['', ''])[1] : '';
	if (!installed) { s.skip = 'SITE not set'; return; }
	if (!(await p.locator('#updOther').isVisible())) { await p.click('#updOtherBtn'); await p.waitForTimeout(500); }
	expect(await p.locator('#updQuick').count() === 1 && await p.locator('#updQuick').isHidden(), 'Check & install hidden until a file is chosen');
	await p.setInputFiles('#updPackage', pkg); await p.waitForTimeout(300);
	expect(await p.locator('#updQuick').isVisible() && /backup|Sicherung/i.test(await p.getAttribute('#updQuick', 'data-bs-original-title') || await p.getAttribute('#updQuick', 'title') || ''), 'Check & install with its tooltip');
	const newer = pv.localeCompare(installed, undefined, { numeric: true }) > 0;
	sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'nunext_update_quick'");
	// the "do not interrupt" notice of Install also shows for Check & install – only for that button of the upload form
	expect(await p.getAttribute('#updQuick', 'data-nu-progress') === '#updQuickProgress' && await p.getAttribute('#updUpload', 'data-nu-progress') === null
		&& await p.locator('#updQuickProgress').count() === 1 && await p.locator('#updQuickProgress').isHidden(), 'progress notice of Check & install missing or already open');
	// the request is held back once (a listener after the page's own), so the page is still there to look at
	await p.evaluate(() => window.addEventListener('submit', (e) => e.preventDefault(), { once: true }));
	await p.click('#updQuick', { noWaitAfter: true });
	await p.waitForTimeout(900);
	expect(await p.locator('#updQuickProgress.show').count() === 1 && /Keep this page open|Seite geöffnet/.test(await p.textContent('#updQuickProgress').catch(() => '')), 'Check & install shows no notice while it runs');
	expect(await p.locator('#updQuickProgress .btn-close, #updQuickProgress [data-bs-dismiss]').count() === 0, 'the notice can be closed');
	await p.evaluate(() => { const f = document.querySelector('#updQuick').form; f.dataset.nuSent = ''; const b = document.querySelector('#updQuick'); b.disabled = false; });
	await Promise.all([p.waitForURL(/check=|finish=1/, { timeout: 90000 }), p.evaluate(() => document.querySelector('#updQuick').click())]);   // the notice covers the page: no real click possible
	await p.waitForLoadState('networkidle');
	await p.waitForTimeout(800);
	if (!newer) {
		expect(/check=/.test(p.url()) && await p.locator('#updInstallForm').count() + await p.locator('.alert-danger').count() > 0 && /Not installed at once|Nicht sofort installiert/.test(await p.textContent('body')), 'not newer: normal check with the reason: ' + p.url());
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'nunext_update_quick'") === '0', 'installed although not newer');
		if (await p.locator('#updCancel').count()) await Promise.all([p.waitForLoadState('networkidle'), p.click('#updCancel')]);
	} else {
		expect(/finish=1|p=update/.test(p.url()) && sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'nunext_update_quick'") === '1', 'quick install not logged: ' + p.url());
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event LIKE 'nunext_update%' AND details LIKE " + "'%" + pv + "%'") !== '0', 'installed version not logged');
		if (SITE) expect(fs.readFileSync(path.join(SITE, 'core/nuapp.php'), 'utf8').includes("'" + pv + "'"), 'files of ' + pv + ' not installed');
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.update_check = async (s) => {
	const pkg = process.env.PKG;
	if (!pkg || !fs.existsSync(pkg)) { s.skip = 'PKG not set'; return; }
	const p = s.ga;
	await open(p, 'index.php?p=update');
	if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update'); }
	if (!(await p.locator('#updOther').isVisible())) { await p.click('#updOtherBtn'); await p.waitForTimeout(500); }   // other ways are folded (0.8.376)
	await p.setInputFiles('#updPackage', pkg);
	await p.waitForTimeout(300);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('#updUpload')]);
	await p.waitForTimeout(800);
	expect(await p.locator('#updSignature .bi-patch-check-fill').count() === 1, 'package not shown as signed: ' + ((await p.textContent('#updSignature').catch(() => '')) || '').trim());
	// fast releases (0.8.389): a package without the release gate is marked "not fully tested"
	const gated = execSync('python3 -', { input: "import zipfile, json\nz=zipfile.ZipFile(" + JSON.stringify(pkg) + ")\nn=[x for x in z.namelist() if x.endswith('nunext-manifest.json')][0]\nprint(json.dumps(json.loads(z.read(n)).get('gated')))" }).toString().trim();
	expect((await p.locator('#updNotGated').count() === 1) === (gated === 'false'), 'not-gated note: manifest gated=' + gated);
	// 0.8.615: the backup before the update holds nuBuilder's zzzzsys_* tables by default (not the whole database)
	if (await p.locator('#updBkDb').count()) expect(await p.inputValue('#updBkDb') === 'nubuilder', 'default database part of the backup: ' + await p.inputValue('#updBkDb'));
	// page forms (0.8.388): packages with page_api in the manifest check the installed pages
	const hasApi = execSync('python3 -', { input: "import zipfile, json, sys\nz=zipfile.ZipFile(" + JSON.stringify(pkg) + ")\nn=[x for x in z.namelist() if x.endswith('nunext-manifest.json')][0]\nprint('1' if 'page_api' in json.loads(z.read(n)) else '0')" }).toString().trim() === '1';
	if (hasApi && SITE) {
		const F = 'e2eupg' + Date.now().toString(36), id = 'e2eupg' + Date.now().toString(36).slice(-6), dir = path.join(SITE, 'custom', 'pages', F);
		fs.mkdirSync(dir, { recursive: true });
		fs.writeFileSync(path.join(dir, 'index.php'), "<?php\ndefined('NU_PAGE') || exit;\n$nu->api(1);\n$nu->noSuchMethod();\n");
		sql(`INSERT INTO zzzzsys_form (zzzzsys_form_id, sfo_type, sfo_code, sfo_description, sfo_status) VALUES ('${id}', 'page', '${F}', 'E2E update page', '1')`);
		try {
			await p.reload(); await p.waitForTimeout(500);
			expect(await p.locator(`#updPagesList [data-page="${F}"] .bg-danger`).count() === 1, 'update check: page that would break not listed');
		} finally {
			sql(`DELETE FROM zzzzsys_form WHERE zzzzsys_form_id = '${id}'`);
			fs.rmSync(dir, { recursive: true, force: true });
		}
	}
};

// library packs (0.8.405): build two signed PHPMailer packs (dev/libpack.php, the owner's key), install from _update/, upload,
// roll back, self-test, refuse a changed pack, remove – and the developer page builds one with the key uploaded. Needs SITE and the key.
T.libpacks = async (s) => {
	const key = process.env.NUNEXT_SIGNING_KEY && fs.existsSync(process.env.NUNEXT_SIGNING_KEY) ? process.env.NUNEXT_SIGNING_KEY : path.resolve(__dirname, '../../../nunext-signing.key');
	if (!SITE || !fs.existsSync(key)) { s.skip = 'SITE or signing key missing'; return; }
	const src = [path.join(path.dirname(SITE), 'third_party/phpmailer'), path.join(SITE, 'lib/PHPMailer')].find(d => fs.existsSync(path.join(d, 'PHPMailer.php')));
	if (!src) { s.skip = 'no PHPMailer source'; return; }
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nulp-'));
	const stateFile = path.join(SITE, 'vendor/packs/nunext-libs.php');
	const stateBefore = fs.existsSync(stateFile) ? fs.readFileSync(stateFile) : null;
	const tp = path.join(SITE, 'vendor/packs');
	const keep = path.join(tmp, 'keep');                     // PHPMailer packs installed before the test: put aside, back afterwards
	fs.mkdirSync(keep);
	for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpmailer-/.test(d)) fs.renameSync(path.join(tp, d), path.join(keep, d));
	const cleanup = () => {
		for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpmailer-99\.0\./.test(d)) fs.rmSync(path.join(tp, d), { recursive: true, force: true });
		for (const d of fs.readdirSync(keep)) fs.renameSync(path.join(keep, d), path.join(tp, d));
		for (const f of fs.readdirSync(path.join(SITE, '_update'))) if (/^nunext-lib-phpmailer-99\.0\./.test(f)) fs.unlinkSync(path.join(SITE, '_update', f));
		if (stateBefore) fs.writeFileSync(stateFile, stateBefore); else if (fs.existsSync(stateFile)) fs.unlinkSync(stateFile);
		fs.rmSync(tmp, { recursive: true, force: true });
	};
	try {
		execSync(`php dev/libpack.php ${JSON.stringify(tmp)} ${JSON.stringify(key)} phpmailer=${JSON.stringify(src)}@99.0.1`, { cwd: path.resolve(__dirname, '../..') });
		execSync(`php dev/libpack.php ${JSON.stringify(tmp + '/b')} ${JSON.stringify(key)} phpmailer=${JSON.stringify(src)}@99.0.2`, { cwd: path.resolve(__dirname, '../..') });
		fs.mkdirSync(path.join(SITE, '_update'), { recursive: true });
		fs.copyFileSync(path.join(tmp, 'b/nunext-lib-phpmailer-99.0.2.zip'), path.join(SITE, '_update/nunext-lib-phpmailer-99.0.2.zip'));
		const p = s.ga;
		const go = async () => {                                // a new address each time: a change of the #hash alone does not load the page
			await open(p, 'index.php?p=update&lp=' + Date.now() + '#libraries');
			if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update&lp=' + Date.now() + '#libraries'); }
		};
		const row = async () => (await p.locator('tr[data-slot=phpmailer]').innerText()).replace(/\s+/g, ' ');
		await go();
		expect(/99\.0\.2/.test(await p.locator('tr[data-slot=phpmailer] .nuLpAvail').innerText()), 'pack in _update/ not offered: ' + await row());
		await Promise.all([p.waitForNavigation(), p.click('tr[data-slot=phpmailer] .nuLpInstall')]);
		expect(fs.existsSync(path.join(tp, 'phpmailer-99.0.2/PHPMailer.php')) && fs.existsSync(path.join(tp, 'phpmailer-99.0.2/.htaccess')), 'not installed into vendor/packs/phpmailer-99.0.2/');
		expect(!fs.existsSync(path.join(SITE, '_update/nunext-lib-phpmailer-99.0.2.zip')), 'used pack left in _update/');
		expect(/99\.0\.2/.test(await p.locator('tr[data-slot=phpmailer] .nuLpInUse').innerText()), 'row after install: ' + await row());
		// upload the other version → active, the first one can be rolled back to
		await p.setInputFiles('#lpUpload', path.join(tmp, 'nunext-lib-phpmailer-99.0.1.zip'));
		await Promise.all([p.waitForNavigation(), p.click('#lpUploadBtn')]);
		expect(/99\.0\.1/.test(await p.locator('tr[data-slot=phpmailer] .nuLpInUse').innerText()) && /99\.0\.2/.test(await p.locator('tr[data-slot=phpmailer] .nuLpInUse').innerText()), 'after upload: ' + await row());
		await p.click('tr[data-slot=phpmailer] [data-bs-toggle=dropdown]');
		await p.click('tr[data-slot=phpmailer] button[form="lpF-lp_rollback-phpmailer"]');
		await p.waitForTimeout(400);
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm .js-confirm-ok')]);
		expect(/^99\.0\.2/.test((await p.locator('tr[data-slot=phpmailer] .nuLpInUse .fw-semibold').innerText()).trim()), 'roll back: ' + await row());
		// self-test with the pack
		await p.click('tr[data-slot=phpmailer] [data-bs-toggle=dropdown]');
		await Promise.all([p.waitForNavigation(), p.click('tr[data-slot=phpmailer] button[form="lpF-lp_test-phpmailer"]')]);
		expect(await p.locator('tr[data-slot=phpmailer] .nuLpTest-ok').count() === 1, 'self-test: ' + await row());
		// a changed pack is refused (one byte of the manifest's signature)
		execSync('python3 -', { input: `import zipfile, json\nsrc = zipfile.ZipFile(${JSON.stringify(path.join(tmp, 'nunext-lib-phpmailer-99.0.1.zip'))})\nout = zipfile.ZipFile(${JSON.stringify(path.join(tmp, 'bad.zip'))}, 'w', zipfile.ZIP_DEFLATED)\nfor n in src.namelist():\n    d = src.read(n)\n    if n == 'nunext-lib.json':\n        m = json.loads(d); m['version'] = '99.0.9'; d = json.dumps(m).encode()\n    out.writestr(n, d)\nout.close()\n` });
		await p.setInputFiles('#lpUpload', path.join(tmp, 'bad.zip'));
		await Promise.all([p.waitForNavigation(), p.click('#lpUploadBtn')]);
		expect(await p.locator('.alert-danger').count() >= 1, 'changed pack not refused');
		expect(/^99\.0\.2/.test((await p.locator('tr[data-slot=phpmailer] .nuLpInUse .fw-semibold').innerText()).trim()), 'changed pack changed the installation');
		// developer page: build a pack with the key uploaded
		if ((await open(p, 'index.php?p=libpack')).status() !== 404) {
			if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=libpack'); }
			for (const b of await p.locator('#lpBuildForm input[name="slots[]"]').all()) if (await b.isChecked()) await b.uncheck();
			await p.check('#lpB-phpmailer');
			await p.fill('input[name="ver[phpmailer]"]', '99.0.3');
			await p.setInputFiles('#lpKey', key);
			await Promise.all([p.waitForNavigation(), p.click('#lpBuildBtn')]);
			expect(/nunext-lib-phpmailer-99\.0\.3\.zip/.test(await p.locator('#lpResult').innerText().catch(() => '')), 'developer page: no pack built');
			const idx = await p.evaluate(async (u) => (await fetch(u)).json(), await p.locator('#lpIndexLink').getAttribute('href'));
			expect(idx.format === 'nunext-libs-1' && idx.signature && idx.libs.some(l => l.version === '99.0.3'), 'developer page: index');
			const d = await p.locator('form input[name=action][value=discard]').count();
			if (d) await Promise.all([p.waitForNavigation(), p.click('form:has(input[value=discard]) button')]);
		}
		// remove: all PHPMailer packs gone
		await go();
		await p.click('tr[data-slot=phpmailer] [data-bs-toggle=dropdown]');
		await p.click('tr[data-slot=phpmailer] button[form="lpF-lp_remove-phpmailer"]');
		await p.waitForTimeout(400);
		await Promise.all([p.waitForNavigation(), p.click('#nuConfirm .js-confirm-ok')]);
		expect(!fs.readdirSync(tp).some(d => /^phpmailer-99\./.test(d)), 'remove left folders');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event IN ('nunext_lib_install', 'nunext_lib_rollback', 'nunext_lib_remove') AND log_time > NOW() - INTERVAL 10 MINUTE") >= 4, 'log events missing');
	} finally {
		cleanup();
	}
};

// library versions a release needs (0.8.406): a package whose manifest asks for PHPMailer ≥ 99.0.2 → Check package lists it with the pack
// in _update/; "Install first" installs it and comes back to the checked package, which then shows nothing more. Needs PKG, SITE and the key.
T.libreq = async (s) => {
	const pkg = process.env.PKG;
	const key = process.env.NUNEXT_SIGNING_KEY && fs.existsSync(process.env.NUNEXT_SIGNING_KEY) ? process.env.NUNEXT_SIGNING_KEY : path.resolve(__dirname, '../../../nunext-signing.key');
	if (!pkg || !fs.existsSync(pkg) || !SITE || !fs.existsSync(key)) { s.skip = 'PKG, SITE or signing key missing'; return; }
	const src = [path.join(path.dirname(SITE), 'third_party/phpmailer'), path.join(SITE, 'lib/PHPMailer')].find(d => fs.existsSync(path.join(d, 'PHPMailer.php')));
	if (!src) { s.skip = 'no PHPMailer source'; return; }
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nulr-'));
	const tp = path.join(SITE, 'vendor/packs'), stateFile = path.join(tp, 'nunext-libs.php');
	const stateBefore = fs.existsSync(stateFile) ? fs.readFileSync(stateFile) : null;
	const keep = path.join(tmp, 'keep'); fs.mkdirSync(keep);
	for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpmailer-/.test(d)) fs.renameSync(path.join(tp, d), path.join(keep, d));
	const p = s.ga;
	try {
		execSync(`php dev/libpack.php ${JSON.stringify(tmp)} ${JSON.stringify(key)} phpmailer=${JSON.stringify(src)}@99.0.2`, { cwd: path.resolve(__dirname, '../..') });
		fs.mkdirSync(path.join(SITE, '_update'), { recursive: true });
		fs.copyFileSync(path.join(tmp, 'nunext-lib-phpmailer-99.0.2.zip'), path.join(SITE, '_update/nunext-lib-phpmailer-99.0.2.zip'));
		// the package with the requirement (requires_libs is not part of the signed payload – the signature stays valid)
		execSync('python3 -', { input: `import zipfile, json\nsrc = zipfile.ZipFile(${JSON.stringify(pkg)})\nout = zipfile.ZipFile(${JSON.stringify(path.join(tmp, 'pkg.zip'))}, 'w', zipfile.ZIP_DEFLATED)\nfor n in src.namelist():\n    d = src.read(n)\n    if n.endswith('nunext-manifest.json'):\n        m = json.loads(d); m['requires_libs'] = {'phpmailer': {'min': '99.0.2', 'needed': True}}; d = json.dumps(m).encode()\n    out.writestr(n, d)\nout.close()\n` });
		await open(p, 'index.php?p=update&lr=' + Date.now());
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=update'); }
		if (!(await p.locator('#updOther').isVisible())) { await p.click('#updOtherBtn'); await p.waitForTimeout(500); }
		await p.setInputFiles('#updPackage', path.join(tmp, 'pkg.zip'));
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#updUpload')]);
		await p.waitForTimeout(500);
		expect(await p.locator('#updLibReq li[data-slot=phpmailer]').count() === 1, 'requirement not listed');
		expect(/99\.0\.2/.test(await p.locator('#updLibReq li[data-slot=phpmailer]').innerText()), 'pack in _update/ not named');
		const checkUrl = p.url();
		await Promise.all([p.waitForNavigation(), p.click('#updLibReqInstall')]);
		await p.waitForTimeout(400);
		expect(/check=/.test(p.url()) && p.url().split('check=')[1] === checkUrl.split('check=')[1], 'not back on the checked package: ' + p.url());
		expect(await p.locator('#updLibReq').count() === 0, 'requirement still listed after installing the pack');
		expect(fs.existsSync(path.join(tp, 'phpmailer-99.0.2/PHPMailer.php')), 'pack not installed');
		await Promise.all([p.waitForNavigation(), p.click('#updCancel')]);
	} finally {
		for (const d of fs.existsSync(tp) ? fs.readdirSync(tp) : []) if (/^phpmailer-99\./.test(d)) fs.rmSync(path.join(tp, d), { recursive: true, force: true });
		for (const d of fs.readdirSync(keep)) fs.renameSync(path.join(keep, d), path.join(tp, d));
		for (const f of fs.readdirSync(path.join(SITE, '_update'))) if (/^nunext-lib-phpmailer-99\./.test(f)) fs.unlinkSync(path.join(SITE, '_update', f));
		if (stateBefore) fs.writeFileSync(stateFile, stateBefore); else if (fs.existsSync(stateFile)) fs.unlinkSync(stateFile);
		fs.rmSync(tmp, { recursive: true, force: true });
	}
};

T.uppy_upload = async (s) => {
	if (!SITE || !fs.existsSync(path.join(SITE, 'vendor/packs')) || !fs.readdirSync(path.join(SITE, 'vendor/packs')).some(d => /^uppy-/.test(d))) { s.skip = 'Uppy library pack not installed on the test site'; return; }
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nunext-e2e-uppy-'));
	fs.chmodSync(dir, 0o777);
	const php = k => execSync(`php -r 'define("NU_ROOT", "."); require "core/nuupload.php"; echo NuUpload::${k};'`).toString();
	const hex = t => Buffer.from(t, 'utf8').toString('hex');
	const proc = php('PROCEDURE').replace("$targetDirectory = dirname($_SERVER['DOCUMENT_ROOT']) . '/nu_uploads/';", `$targetDirectory = '${dir}/';`);
	const html = php('HTML').replace("procedure: 'nu_upload_file_template'", "procedure: 'e2e_upload'")
		.replace("$(document).ready(function () {", "window.e2eUppy = [];\nwindow.nuOnFileUploadComplete = function (t, id, r, m) { window.e2eUppy.push(t + '|' + id + '|' + m); };\n$(document).ready(function () {");
	expect(proc.includes(dir) && html.includes("'e2e_upload'"), 'template text not as expected');
	const tab = sql("SELECT zzzzsys_tab_id FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = 'samplecustomer' ORDER BY syt_order LIMIT 1");
	const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
	const src = fs.mkdtempSync(path.join(os.tmpdir(), 'nunext-e2e-uppysrc-'));
	const p = s.ga;
	try {
		sql(`INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_php, sph_run, sph_global, sph_system, sph_status) VALUES ('e2e_upload', 'e2e_upload', 'e2e', UNHEX('${hex(proc)}'), 'hide', '1', '0', '1')`);
		sql(`INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_zzzzsys_tab_id, sob_all_type, sob_all_id, sob_all_label, sob_all_order, sob_all_top, sob_all_left, sob_all_width, sob_all_height, sob_all_access, sob_all_validate, sob_input_type, sob_input_file_target, sob_html_code)
			VALUES ('e2e_uppy_obj', 'samplecustomer', '${tab}', 'input', 'e2e_uppy', 'Upload', 997, 900, 20, 500, 320, '0', '1', 'file', '1', UNHEX('${hex(html)}'))`);
		const cust = sql('SELECT MIN(sample_customer_id) FROM sample_customer');
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust);
		await p.waitForSelector('#e2e_uppy_uppy_div .uppy-Dashboard', { timeout: 10000 });
		expect(await p.locator('#e2e_uppy[data-nu-field]').count() === 0 && await p.locator('#e2e_uppy.nuFileUppy').count() === 1, 'Uppy object rendered as a field');
		expect(await p.locator('#nuUppyMissing').count() === 0, 'missing notice although the pack is installed');
		const upload = async (name, body) => {
			const f = path.join(src, name); fs.writeFileSync(f, body);
			const before = await p.evaluate(() => window.e2eUppy.length);
			await p.setInputFiles('#e2e_uppy_uppy_div input.uppy-Dashboard-input', f);
			await p.click('#e2e_uppy_uppy_div .uppy-StatusBar-actionBtn--upload');
			await p.waitForFunction(n => window.e2eUppy.length > n, before, { timeout: 15000 });
			const msg = await p.evaluate(() => window.e2eUppy[window.e2eUppy.length - 1]);
			return msg;
		};
		// each upload on a freshly opened record (one Uppy dashboard per page load)
		let m = await upload('pic.png', PNG);
		expect(/^FS\|e2e_uppy\|The file pic\.png has been uploaded\./.test(m) && fs.existsSync(path.join(dir, 'pic.png')), 'png upload: ' + m);
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust); await p.waitForSelector('#e2e_uppy_uppy_div .uppy-Dashboard');
		m = await upload('pic.png', PNG);
		expect(/pic-1\.png has been uploaded/.test(m) && fs.existsSync(path.join(dir, 'pic-1.png')), 'second upload overwrote or failed: ' + m);
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust); await p.waitForSelector('#e2e_uppy_uppy_div .uppy-Dashboard');
		// refused files: nuBuilder's template shows only "Error uploading file(s)." (its onAfterResponse swallows the 401 message) –
		// the route's own answer is checked directly below
		for (const [name, body] of [['evil.php', PNG], ['page.html', '<html><body><script>alert(1)</script></body></html>'], ['fake.pdf', PNG]]) {
			await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust); await p.waitForSelector('#e2e_uppy_uppy_div .uppy-Dashboard');
			m = await upload(name, body);
			expect(/Error uploading|Invalid file type/.test(m) && !fs.existsSync(path.join(dir, name)), name + ' not refused: ' + m);
		}
		const direct = (name, b64) => p.evaluate(async ([name, b64]) => {
			const fd = new FormData(); fd.append('file', new Blob([Uint8Array.from(atob(b64), c => c.charCodeAt(0))]), name); fd.append('procedure', 'e2e_upload'); fd.append('form_id', 'samplecustomer');
			const r = await fetch('index.php?p=upload', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': window.nuNext.csrf } }); return r.status + ' ' + JSON.stringify(await r.json()); }, [name, b64]);
		const r1 = await direct('x.php.png', PNG.toString('base64')), r2 = await direct('fake.pdf', PNG.toString('base64')), r3 = await direct('ok.png', PNG.toString('base64'));
		expect(/^401 .*"message":"Invalid file type"/.test(r1), 'route guard (x.php.png): ' + r1);
		expect(/^401 .*"message":"Invalid file type"/.test(r2), 'template type check (fake.pdf): ' + r2);
		expect(/^201 .*"file":"ok.png"/.test(r3) && fs.existsSync(path.join(dir, 'ok.png')), 'direct upload: ' + r3);
		expect(/"url":"ok\.png"/.test(r3) && !r3.includes(dir), 'the answer shows the server path: ' + r3);   // 0.8.478 (security assessment C1)
		expect(fs.existsSync(path.join(dir, '.htaccess')) && /Require all denied/.test(fs.readFileSync(path.join(dir, '.htaccess'), 'utf8')), 'upload folder without deny-all .htaccess');
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'file_upload' AND record_id = 'e2e_upload'") === 3
			&& +sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'file_upload_refused' AND record_id = 'e2e_upload'") === 5, 'log rows: '
			+ sql("SELECT GROUP_CONCAT(event) FROM zzzzsys_nunext_log WHERE record_id = 'e2e_upload'"));
		// without the CSRF token: nuBuilder's error answer, nothing stored
		const noCsrf = await p.evaluate(async () => { const fd = new FormData(); fd.append('file', new Blob(['x'], { type: 'text/plain' }), 'a.txt'); fd.append('procedure', 'e2e_upload');
			const r = await fetch('index.php?p=upload', { method: 'POST', body: fd }); return r.status + ' ' + (await r.json()).message; });
		expect(/^400 /.test(noCsrf) && !fs.existsSync(path.join(dir, 'a.txt')), 'upload without CSRF token: ' + noCsrf);
		// the record saves without the object (no column, "required" ignored), the Uppy input never marks the form as edited
		expect(await p.locator('.nuSaveEdited').count() === 0, 'Uppy marked the form as edited');
		await p.locator('[data-nu-field="cus_name"]').first().fill('E2E Uppy save'); await p.locator('[data-nu-field="cus_name"]').first().dispatchEvent('change');
		await save(p);
		expect(sql(`SELECT cus_name FROM sample_customer WHERE sample_customer_id = '${cust}'`) === 'E2E Uppy save', 'record not saved with an Uppy object on the form');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
		// update step 0020 on a database without nuBuilder's rows: the hardened procedure and nuBuilder's snippet are added
		const keep = sql("SELECT CONCAT(zzzzsys_php_id, '|', sph_code) FROM zzzzsys_php WHERE sph_code = 'nu_upload_file_template'");
		sql("UPDATE zzzzsys_php SET sph_code = 'e2e_kept_upload' WHERE sph_code = 'nu_upload_file_template'; UPDATE zzzzsys_php SET zzzzsys_php_id = 'e2e_kept_upload_id' WHERE zzzzsys_php_id = 'nu62da72900a02b9a';"
			+ " UPDATE zzzzsys_code_snippet SET cot_code = 'e2e_kept_snippet', zzzzsys_code_snippet_id = 'e2e_kept_snippet_id' WHERE cot_code = 'nu_upload_file_uppy_code_template'; DELETE FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0020-upload-template'");
		try {
			const q = await login(GA[0], GA[1]);
			expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_migration WHERE zzzzsys_nunext_migration_id = '0020-upload-template'") === '1', 'step 0020 not run');
			expect(/never overwritten/.test(sql("SELECT sph_php FROM zzzzsys_php WHERE sph_code = 'nu_upload_file_template' AND zzzzsys_php_id = 'nu62da72900a02b9a' AND sph_global = '1'")), 'hardened procedure not added');
			expect(sql("SELECT HEX(cot_source_code) FROM zzzzsys_code_snippet WHERE cot_code = 'nu_upload_file_uppy_code_template'") === hex(php('HTML')).toUpperCase(), 'snippet not added as nuBuilder\'s');
			await q.context().close();
		} finally {
			sql("DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'nu62da72900a02b9a'; DELETE FROM zzzzsys_code_snippet WHERE zzzzsys_code_snippet_id = 'nu6866e0177fc5399';"
				+ " UPDATE zzzzsys_php SET zzzzsys_php_id = 'nu62da72900a02b9a' WHERE zzzzsys_php_id = 'e2e_kept_upload_id'; UPDATE zzzzsys_php SET sph_code = 'nu_upload_file_template' WHERE sph_code = 'e2e_kept_upload';"
				+ " UPDATE zzzzsys_code_snippet SET cot_code = 'nu_upload_file_uppy_code_template', zzzzsys_code_snippet_id = 'nu6866e0177fc5399' WHERE zzzzsys_code_snippet_id = 'e2e_kept_snippet_id'");
			expect(sql("SELECT CONCAT(zzzzsys_php_id, '|', sph_code) FROM zzzzsys_php WHERE sph_code = 'nu_upload_file_template'") === keep, 'template row not restored');
		}
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'e2e_uppy_obj'; DELETE FROM zzzzsys_php WHERE zzzzsys_php_id = 'e2e_upload'; DELETE FROM zzzzsys_nunext_log WHERE record_id = 'e2e_upload'");
		fs.rmSync(dir, { recursive: true, force: true }); fs.rmSync(src, { recursive: true, force: true });
	}
};

// jQuery 4 preparation (0.8.741): Settings → Code → jQuery – versions, own file in custom/jquery/, Migrate warnings collected per form,
// the globeadmin's test (users keep theirs), point J4 of the Compatibility check
T.jquery_settings = async (s) => {
	const p = s.ga;
	const own = SITE ? SITE + '/custom/jquery/jquery-e2e-4.0.0.min.js' : '';
	const before = sql("SELECT IFNULL((SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'jquery'), '')");
	const goto = async () => {
		await open(p, 'index.php?p=jquery');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=jquery'); }
	};
	const save = async (o) => {
		await goto();
		for (const [k, v] of Object.entries(o)) { if (k === 'ga_warn') await p.setChecked('#jqGaWarn', v); else await p.selectOption(k, v); }
		if (process.env.JQDBG) console.log(p.url(), await p.evaluate(() => document.getElementById('jqForm') && new URLSearchParams(new FormData(document.getElementById('jqForm'))).toString()));
		await Promise.all([p.waitForNavigation(), p.click('#jqSave')]);
		await p.waitForLoadState('networkidle');
		if (process.env.JQDBG) console.log(p.url(), (await p.locator('.alert').allTextContents()).join('|'));
	};
	const jqOf = async (page, url) => { await open(page, url); return page.evaluate(() => ({ v: window.jQuery && jQuery.fn.jquery, mig: !!(window.jQuery && jQuery.migrateVersion), migV: window.jQuery && jQuery.migrateVersion, msgs: (window.jQuery && (jQuery.migrateMessages || jQuery.migrateWarnings) || []).length })); };
	ttInstall();
	sql("UPDATE zzzzsys_form SET sfo_javascript = CONCAT(IFNULL(sfo_javascript, ''), '\nvar nuE2eTrim = $.trim(\" a \");') WHERE zzzzsys_form_id = 'ttcus'");
	sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'jquery_warnings'");
	let u = null;
	try {
		if (own) { fs.mkdirSync(SITE + '/custom/jquery', { recursive: true }); fs.copyFileSync('vendor/bundled/jquery-4.0.0/jquery.min.js', own); }
		await goto();
		expect(await p.locator('#jqUse option[value="b3"]').count() === 1 && await p.locator('#jqUse option[value="b4"]').count() === 1, 'bundled versions offered');
		if (own) {
			expect(await p.locator('#jqUse option[value="c:jquery-e2e-4.0.0.min.js"]').count() === 1, 'own file offered');
			expect(await p.locator('#jqFiles tr[data-file="jquery-e2e-4.0.0.min.js"]').textContent().then(t => /4\.0\.0/.test(t)), 'own file listed with its version');
		}
		// everyone 3.7.1 + Migrate warnings: Migrate 3 on jQuery 3 reports $.trim (deprecated, removed in 4)
		await save({ '#jqUse': 'b3', '#jqMigrate': 'warn', '#jqGaUse': '', ga_warn: false });
		let r = await jqOf(p, 'index.php?p=form&f=ttcus&r=-1');
		expect(r.v === '3.7.1' && /^3\./.test(r.migV || ''), 'jQuery 3.7.1 + Migrate 3: ' + JSON.stringify(r));
		expect(r.msgs > 0, 'Migrate 3 reports $.trim: ' + JSON.stringify(r));
		await p.evaluate(() => window.nuNextJqMigrateFlush && window.nuNextJqMigrateFlush());
		await p.waitForTimeout(700);
		await goto();
		const rows = await p.locator('#jqWarnings tr.jqWarnRow').allTextContents();
		expect(rows.some(t => /ttcus/.test(t) && /trim/i.test(t)), 'message collected for ttcus: ' + rows.join(' | '));
		// the globeadmin's test: own file (jQuery 4) + warnings for the globeadmin, users keep 3.7.1 without Migrate
		await save({ '#jqUse': 'b3', '#jqMigrate': 'off', '#jqGaUse': own ? 'c:jquery-e2e-4.0.0.min.js' : 'b4', ga_warn: true });
		expect(await p.locator('#jqTestBadge').count() === 1, 'test badge: ' + sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'jquery'") + ' ' + (await p.locator('.alert').allTextContents()).join(' / '));
		r = await jqOf(p, 'index.php?p=form&f=ttcus&r=-1');
		expect(r.v === '4.0.0' && /^4\./.test(r.migV || ''), 'globeadmin: jQuery 4 + Migrate 4: ' + JSON.stringify(r));
		expect(p.errors.length === 0, 'no page errors on jQuery 4: ' + p.errors.join(' | '));
		if (own) {
			const src = await p.evaluate(() => [...document.scripts].map(x => x.src).find(x => /p=jqfile/.test(x)) || '');
			expect(/f=jquery-e2e-4\.0\.0\.min\.js/.test(src), 'own file served through ?p=jqfile: ' + src);
			const res = await p.request.get(src);
			expect(res.status() === 200 && /javascript/.test(res.headers()['content-type'] || ''), 'jqfile answers JS: ' + res.status());
			expect((await p.request.get(BASE + 'index.php?p=jqfile&f=..%2Fnuconfig.php')).status() === 404, 'jqfile refuses other names');
		}
		const ben = sql("SELECT a.slf_zzzzsys_form_id FROM zzzzsys_user us JOIN zzzzsys_access_form a ON a.slf_zzzzsys_access_id = us.sus_zzzzsys_access_id WHERE us.sus_login_name = 'sample_ben' AND a.slf_zzzzsys_form_id NOT LIKE 'nu%' LIMIT 1");
		if (ben) {
			u = await login('sample_ben', UPW);
			const rb = await jqOf(u, 'index.php?p=form&f=' + ben);
			expect(rb.v === '3.7.1' && !rb.mig, 'user keeps jQuery 3.7.1 without Migrate: ' + JSON.stringify(rb));
			expect((await u.goto(BASE + 'index.php?p=jquery')).status() === 403 || !(await u.locator('#jqForm').count()), 'user cannot open the jQuery page');
		}
		// J4 in the Compatibility check (grey, preparation)
		await open(p, 'index.php?p=compat');
		const j4 = p.locator('#compat_findings tr[data-finding="J4"]');
		expect(await j4.count() === 1 && await j4.evaluate(r => r.classList.contains('compat-prep')), 'J4 shown grey');
		expect(/ttcus/.test(await j4.textContent()) && /\$\.trim\(\)/.test(await j4.textContent()), 'J4 lists $.trim of ttcus');
		// clear the messages
		await goto();
		await p.click('#jqClear');
		await Promise.all([p.waitForNavigation(), p.click('.modal.show .btn-danger')]);
		await p.waitForLoadState('networkidle');
		expect(await p.locator('#jqNoWarnings').count() === 1, 'messages cleared');
	} finally {
		if (u) await u.context().close();
		sql(before === '' ? "DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'jquery'"
			: "UPDATE zzzzsys_nunext_setting SET setting_value = '" + before.replace(/'/g, "''") + "' WHERE zzzzsys_nunext_setting_id = 'jquery'");
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'jquery_warnings'");
		if (own) try { fs.unlinkSync(own); } catch (x) { /* gone */ }
		ttRemove();
	}
};

// Bootstrap 6 preparation (0.8.740): point BS6 of the Compatibility check (developer mode – the test site runs in it), grey, not open
T.bs6_compat = async (s) => {
	ttInstall();
	try {
		sql("INSERT INTO zzzzsys_object (zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_id, sob_all_type, sob_html_code) VALUES ('tto_e2e_bs6', 'ttcus', 'e2e_bs6', 'html', '<div class=\"d-none d-md-block col-lg-6 btn-sm\">x</div><script>window.bootstrap.Modal.getInstance(1)</script>')");
		const before = await s.ga.evaluate(async (u) => (await (await fetch(u)).text()), BASE + 'index.php?p=compat');
		await open(s.ga, 'index.php?p=compat');
		const row = s.ga.locator('#compat_findings tr[data-finding="BS6"]');
		expect(await row.count() === 1, 'BS6 row shown in developer mode');
		expect(await row.evaluate(r => r.classList.contains('compat-prep') && !!r.querySelector('.badge.text-bg-secondary')), 'BS6 is grey (preparation)');
		const txt = await row.locator('li').evaluateAll(lis => lis.map(l => l.textContent).join(' | '));
		expect(/e2e_bs6 · HTML/.test(txt) && /d-md-block/.test(txt) && /col-lg-6/.test(txt) && !/btn-sm/.test(txt) && /window\.bootstrap/.test(txt), 'BS6 places: ' + txt);
		expect((txt.match(/e2e_bs6/g) || []).length === 1, 'HTML object listed once: ' + txt);
		// not counted as open: the open badge counts only red rows
		const red = await s.ga.locator('#compat_findings tr.compat-missing').count();
		const badge = await s.ga.locator('#compat_findings .badge.text-bg-danger, #compat_findings .badge.text-bg-success').first().textContent();
		expect(new RegExp('\\b' + red + '\\b').test(badge) || red === 0, 'open count = red rows (' + red + '): ' + badge);
		expect(before.length > 0, 'page loads');
	} finally {
		sql("DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = 'tto_e2e_bs6'");
		ttRemove();
	}
};

// Reset database (0.8.628, developer mode): a complete dump of the test database uploaded, checked, a wrong database name refused,
// then all tables deleted and the dump imported – a table not in the dump is gone, the admin stays signed in; the database is put back
T.db_reset = async (s) => {
	const p = s.ga;
	const r = await open(p, 'index.php?p=dbreset');
	if (r.status() === 404) { s.skip = 'developer mode off / not MySQL'; return; }
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nudbr-')), dump = path.join(tmp, 'e2e-full.sql');
	execSync('mysqldump ' + DB + ' > ' + dump);
	const forms = sql('SELECT COUNT(*) FROM zzzzsys_form');
	try {
		sql('CREATE TABLE e2e_dbr_marker (id INT PRIMARY KEY)');
		await open(p, 'index.php');
		expect(await p.locator('a[href*="p=dbreset"]').count() > 0, 'no Reset database tile in developer mode');
		await open(p, 'index.php?p=dbreset');
		await p.setInputFiles('#dbrUpload', dump);
		await p.uncheck('#dbrBackup0');
		await Promise.all([p.waitForNavigation(), p.click('#dbrCheckBtn')]);
		expect(await p.locator('#dbrWarn').count() === 1, 'check: no warning / ' + (await p.locator('.alert').allTextContents()).join(' | '));
		expect(await p.locator('#dbrTables code', { hasText: 'e2e_dbr_marker' }).count() === 1, 'marker table not listed as deleted');
		// 0.8.1290: the reset shows a progress window that cannot be closed (as Install on the update page)
		expect(await p.locator('#dbrRunForm[data-nu-progress="#dbrProgress"]').count() === 1 && await p.locator('#dbrProgress[data-bs-backdrop="static"][data-bs-keyboard="false"]').count() === 1, 'no progress window');
		await p.fill('#dbrConfirm', 'not-the-db');
		await p.evaluate(() => { window.__dbrShown = false; document.getElementById('dbrProgress').addEventListener('show.bs.modal', () => { sessionStorage.setItem('e2e-dbr-shown', '1'); }); });
		await Promise.all([p.waitForNavigation(), p.click('#dbrRun')]);
		expect(await p.evaluate(() => { const v = sessionStorage.getItem('e2e-dbr-shown'); sessionStorage.removeItem('e2e-dbr-shown'); return v === '1'; }), 'progress window not shown while running');
		if (/p=reauth/.test(p.url())) { await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await open(p, 'index.php?p=dbreset'); }
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e2e_dbr_marker'") === '1', 'wrong database name: tables deleted');
		await p.fill('#dbrConfirm', sql('SELECT DATABASE()'));
		// the reset runs in steps (?p=dbresetstep) – listen before the click: a small dump can finish its first steps
		// before the navigation promise resolves
		const steps = [];
		const onStep = (rr) => { if (/p=dbresetstep/.test(rr.url())) steps.push(rr.status()); };
		p.on('response', onStep);
		await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#dbrRun')]);
		await p.waitForSelector('#dbrDone, #nuFlash .alert-danger, .alert-danger', { timeout: 180000 });
		p.off('response', onStep);
		expect(steps.length > 0 && steps.every((x) => x === 200), 'steps: ' + steps.join(','));
		if (SITE) expect(!fs.readdirSync(path.join(SITE, '_update')).some((f) => /^\.dbreset-work/.test(f)), 'work copy left in _update/');
		expect(await p.locator('#dbrDone').count() === 1, 'no result: ' + (await p.locator('.alert').allTextContents()).join(' | '));
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e2e_dbr_marker'") === '0', 'table not in the dump still there');
		expect(sql('SELECT COUNT(*) FROM zzzzsys_form') === forms, 'forms after the import: ' + sql('SELECT COUNT(*) FROM zzzzsys_form') + ' of ' + forms);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'database_reset'") !== '0', 'reset not logged');
		if (SITE) expect(!fs.readdirSync(path.join(SITE, '_update')).some((f) => /^dbreset-/.test(f)), 'upload left in _update/ (0.8.847)');
		await Promise.all([p.waitForNavigation(), p.click('#dbrWizard')]);
		expect(/p=setupwizard/.test(p.url()), 'not in the setup wizard: ' + p.url());
		const u = await open(p, 'index.php?p=users');
		expect(u.status() === 200 && /p=users/.test(p.url()), 'signed out after the reset: ' + p.url());
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(dump) });
		sql('DROP TABLE IF EXISTS e2e_dbr_marker');
		if (SITE) for (const f of fs.readdirSync(path.join(SITE, '_update'))) if (/^dbreset-/.test(f)) fs.unlinkSync(path.join(SITE, '_update', f));
		fs.rmSync(tmp, { recursive: true, force: true });
	}
};

// Reset database from a package (0.8.844): a signed nunext.zip of the installed version – files installed, packs replaced by the
// package's own, every table deleted and the database built from the install scripts; the next request (new code) runs the steps
// and the sample data; custom/ stays. Database, vendor/packs put back. Needs PKG (same version as the site) and SITE.
T.db_reset_package = async (s) => {
	// pages right after a database reset / a new copy of the program files compile every file anew – on the PHP test server under a full
	// run one took longer than the 30 s of open() (gate 0.8.1221): one more try with a longer wait
	const go = (u) => open(s.ga, u).catch(() => s.ga.goto(BASE + u, {timeout: 90000}));
	const p = s.ga, PKG = process.env.PKG ? path.resolve(process.env.PKG) : '';
	if (!PKG || !SITE || !fs.existsSync(PKG)) { s.skip = 'needs PKG and SITE'; return; }
	const r = await go('index.php?p=dbreset');
	if (r.status() === 404) { s.skip = 'developer mode off / not MySQL'; return; }
	const ver = (fs.readFileSync(path.join(SITE, 'core/nuapp.php'), 'utf8').match(/NU_VERSION',\s*'([^']+)'/) || [])[1];
	const pkgVer = (execSync('unzip -p ' + JSON.stringify(PKG) + ' nunext/nunext-manifest.json', {maxBuffer: 256 * 1024 * 1024}).toString().match(/"version":\s*"([^"]+)"/) || [])[1];
	if (!ver || ver !== pkgVer) { s.skip = 'PKG ' + pkgVer + ' is not the installed ' + ver; return; }
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nudbp-')), dump = path.join(tmp, 'full.sql'), packs = path.join(SITE, 'vendor/packs'), packsBak = path.join(tmp, 'packs');
	execSync('mysqldump ' + DB + ' > ' + dump);
	if (fs.existsSync(packs)) fs.cpSync(packs, packsBak, { recursive: true });
	const marker = path.join(SITE, 'custom', 'e2e-fresh-marker.txt');
	fs.writeFileSync(marker, 'x');
	try {
		sql('CREATE TABLE e2e_dbp_marker (id INT PRIMARY KEY)');
		await go('index.php?p=dbreset');
		expect(await p.locator('#dbrFreshHint').count() === 1, 'no hint for packages');
		await p.setInputFiles('#dbrUpload', PKG);
		await p.uncheck('#dbrBackup0');
		await p.check('#dbrSample0');
		await Promise.all([p.waitForNavigation(), p.click('#dbrCheckBtn')]);
		expect(await p.locator('#dbrCheck[data-kind=package]').count() === 1, 'not checked as a package: ' + (await p.locator('.alert').allTextContents()).join(' | '));
		expect((await p.textContent('#dbrPkgVersion')).includes(ver), 'version not shown');
		expect((await p.textContent('#dbrRun')).length > 0, 'no run button');
		await p.fill('#dbrConfirm', sql('SELECT DATABASE()'));
		await Promise.all([p.waitForNavigation({ timeout: 180000 }), p.click('#dbrRun')]);
		if (/p=reauth/.test(p.url())) {
			await p.fill('#reauthPassword', GA[1]); await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]);
			await go('index.php?p=dbreset');
			await p.fill('#dbrConfirm', sql('SELECT DATABASE()'));
			await Promise.all([p.waitForNavigation({ timeout: 180000 }), p.click('#dbrRun')]);
		}
		await p.waitForLoadState('networkidle');
		expect(await p.locator('#dbrFreshDone').count() === 1, 'no result: ' + p.url() + ' ' + (await p.locator('.alert').allTextContents()).join(' | '));
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e2e_dbp_marker'") === '0', 'old table still there');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_migration") !== '0', 'database steps not run');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name LIKE 'sample_%'") !== '0', 'no sample data');
		expect(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'db_origin'") === 'nunext', 'origin not nunext');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'database_fresh'") !== '0', 'not logged');
		expect(!fs.readdirSync(path.join(SITE, '_update')).some((f) => /^dbreset-/.test(f)), 'upload left in _update/ (0.8.847)');
		expect(fs.existsSync(marker), 'custom/ file removed');
		expect(fs.readdirSync(packs).some(d => /^phpmailer-/.test(d)), 'package pack not installed: ' + fs.readdirSync(packs).join(','));
		expect(!fs.existsSync(path.join(SITE, '_backup/maintenance.json')), 'maintenance mode still on');
		const u = await go('index.php?p=users');
		expect(u && u.status() === 200 && /p=users/.test(p.url()), 'signed out after the fresh installation: ' + p.url());
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(dump) });
		sql('DROP TABLE IF EXISTS e2e_dbp_marker');
		if (fs.existsSync(packsBak)) { fs.rmSync(packs, { recursive: true, force: true }); fs.cpSync(packsBak, packs, { recursive: true }); }
		try { fs.unlinkSync(marker); } catch (e) { /* gone */ }
		try { fs.unlinkSync(path.join(SITE, '_backup/maintenance.json')); } catch (e) { /* none */ }
		for (const f of fs.readdirSync(path.join(SITE, '_update'))) if (/^dbreset-/.test(f)) fs.unlinkSync(path.join(SITE, '_update', f));
		fs.rmSync(tmp, { recursive: true, force: true });
	}
};

// 0.8.852: database tools open through a one-time GET link (a POST with target=_blank loses its body in an installed app's window)
T.vendor_open_link = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=vendor');
	const j = await p.evaluate(async () => {
		const tok = (document.querySelector('input[name=_csrf]') || {}).value || '';
		const fd = new FormData(); fd.set('_csrf', tok); fd.set('app', 'ADM'); fd.set('open', 'link');
		const r = await fetch('index.php?p=vendor', {method: 'POST', body: fd, credentials: 'same-origin', headers: {Accept: 'application/json'}});
		return r.json();
	});
	expect(j && /p=(vendor&open=[0-9a-f]{32}|reauth)/.test(j.url), 'no link: ' + JSON.stringify(j));
	if (/p=reauth/.test(j.url)) return;
	const r1 = await p.goto(BASE + j.url, {waitUntil: 'commit'});
	await p.waitForURL((u) => !/open=/.test(String(u)), {timeout: 5000}).catch(() => {});   // 0.8.854: a page that moves on by itself
	const u1 = p.url();
	expect(r1.status() < 500 && (/nudbadmin\.php|phpmyadmin|nudb/i.test(u1) || /p=vendor/.test(u1)), 'first open: ' + u1);
	await p.goto(BASE + j.url, {waitUntil: 'commit'});
	await p.waitForURL((u) => !/open=/.test(String(u)), {timeout: 5000}).catch(() => {});
	expect(p.url() === u1, 'second load of the link (0.8.853: an app window may load it twice): ' + p.url() + ' / ' + u1);
	await p.goto(BASE + 'index.php?p=vendor&open=' + '0'.repeat(32));
	expect(/p=vendor/.test(p.url()) && !/open=/.test(p.url()) && await p.locator('.alert-danger').count() > 0, 'unknown link accepted: ' + p.url());
	// the forms on the start page / status page carry target=_blank and are caught by nuapp.js
	expect(await p.evaluate(() => typeof window.fetch === 'function'), 'fetch');
};

T.vendor_form_table = async (s) => {
	// 0.8.925: the database tool opened from the user menu / side bar on a form page with a table gets that table
	const p = s.ga;
	const rec = sql('SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1');
	if (!rec) { s.skip = 'no sample data'; return; }
	const sent = async (url) => {
		await open(p, url);
		return p.evaluate(() => new Promise((done) => {
			const of = window.fetch, ow = window.open;
			window.open = () => null;
			window.fetch = (u, o) => { const t = o && o.body instanceof FormData ? o.body.get('table') : null; window.fetch = of; window.open = ow; done(t); return new Promise(() => {}); };
			const f = document.createElement('form');
			f.method = 'post'; f.action = 'index.php?p=vendor'; f.target = '_blank';
			f.innerHTML = '<input type="hidden" name="app" value="PMA"><button type="submit">go</button>';
			document.body.appendChild(f);
			f.querySelector('button').click();
		}));
	};
	expect(await sent('index.php?p=form&f=samplecustomer&r=' + rec) === 'sample_customer', 'edit form: table passed');
	expect(await sent('index.php?p=form&f=samplecustomer') === 'sample_customer', 'browse list: table passed');
	expect(await sent('index.php?p=home') === null, 'start page: no table');
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.1207: Update → System, card Database steps folded while every step is applied
T.update_steps_folded = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=update&tab=system');
	const shown = await p.locator('#updSteps').isVisible();
	const hasPend = await p.locator('#updSteps .bi-hourglass').count() > 0;
	expect(shown === hasPend, 'Database steps open = ' + shown + ' with pending = ' + hasPend);
	if (!shown) {
		await p.click('#updStepsToggle'); await p.waitForTimeout(500);
		expect(await p.locator('#updSteps').isVisible() && await p.locator('#updSteps .nuUpdStep').count() > 10, 'steps not shown after a click');
	}
};

// health / status endpoint (0.8.1335): ?p=health without a key = {status} only, no cookie; Settings → Data & logs creates a key (shown
// once) → all checks with the header X-Health-Key; a wrong key = 401; strict=1 answers 503 on a warning; thresholds are saved
T.health_check = async (s) => {
	const p = s.ga;
	try {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('health', 'housekeeping_cron')");
		sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('housekeeping_cron', UNIX_TIMESTAMP() - 30 * 86400)");   // the last cron run a month ago (earlier tests run nucron.php)
		let r = await fetch(BASE + 'index.php?p=health');
		const body0 = await r.json();
		expect(r.status === 200 && JSON.stringify(body0) === '{"status":"ok"}', 'without a key only the status: ' + JSON.stringify(body0));
		expect(!r.headers.get('set-cookie'), 'no cookie');
		await open(p, 'index.php?p=settings&s=data');
		expect(await p.locator('#shHealth').count() === 1, 'card on Settings → Data & logs');
		await Promise.all([p.waitForNavigation(), p.click('#shHealthKeyNew')]);
		if (/p=reauth/.test(p.url())) { await p.fill('input[type=password]', GA[1]); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); await open(p, 'index.php?p=settings&s=data'); await Promise.all([p.waitForNavigation(), p.click('#shHealthKeyNew')]); }
		const key = (await p.locator('#shHealthNewKey code').first().textContent()).trim();
		expect(/^[0-9a-f]{48}$/.test(key), 'key shown once: ' + key);
		expect(!sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'health'").includes(key), 'only the hash stored');
		r = await fetch(BASE + 'index.php?p=health', { headers: { 'X-Health-Key': key } });
		const full = await r.json();
		expect(r.status === 200 && full.checks && full.checks.database.status === 'ok' && full.checks.disk && full.checks.backup && full.checks.cron && full.version, 'all checks with the key: ' + JSON.stringify(full));
		expect(full.checks.cron.status === 'warn' && full.status === 'warn', 'cron a month ago → warning: ' + JSON.stringify(full.checks.cron));
		r = await fetch(BASE + 'index.php?p=health&strict=1&key=' + key);
		expect(r.status === 503, 'strict: 503 on a warning (' + r.status + ')');
		r = await fetch(BASE + 'index.php?p=health', { headers: { Authorization: 'Bearer wrong' } });
		expect(r.status === 401, 'wrong key: 401');
		execSync('php nucron.php', { cwd: SITE });
		const ch = execSync('php nucron.php health --json || true', { cwd: SITE }).toString();
		expect(JSON.parse(ch).checks.cron.status === 'ok', 'after a cron run: ok – ' + ch.slice(0, 300));
		await open(p, 'index.php?p=settings&s=data');
		await p.fill('#shHealthCron', '0'); await Promise.all([p.waitForNavigation(), p.click('#shDataSave')]);
		expect(/"cron_hours":0/.test(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'health'")), 'threshold saved');
		expect(await p.locator('#shHealthNewKey').count() === 0, 'key not shown again');
	} finally {
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('health', 'housekeeping_cron')");
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// plugins: install from the folder and by upload, switch on, page / tile / menu / JSON / asset / settings / events / REST-free parts,
// a failing plugin switched off by itself, safe mode, removal with its data, the command line
T.plugins = async (s) => {
	if (!SITE) return 'skip';
	const p = s.ga;
	const dir = path.join(SITE, 'plugins');
	const ids = ['e2e_ex', 'e2e_bad', 'e2e_up'];
	const cleanup = () => {
		for (const id of ids) fs.rmSync(path.join(dir, id), { recursive: true, force: true });
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'plugins' OR zzzzsys_nunext_setting_id LIKE 'plg:%'");
		for (const id of ids) sql('DROP TABLE IF EXISTS zzzzsys_nunext_plg_' + id + '_notes');
	};
	const copy = (id, name) => {
		const to = path.join(dir, id);
		fs.cpSync(path.join(__dirname, '..', 'plugin-skeleton'), to, { recursive: true });   // dev/plugin-skeleton (e2e.js lives in dev/tests)
		const m = JSON.parse(fs.readFileSync(path.join(to, 'plugin.json'), 'utf8'));
		m.id = id; m.name = name; m.requires.nunext = '0.8.1';
		fs.writeFileSync(path.join(to, 'plugin.json'), JSON.stringify(m, null, 1));
		return to;
	};
	const reauth = async () => {
		if (!/p=reauth/.test(p.url())) return false;
		await p.fill('input[type=password]', GA[1]);
		await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
		return true;
	};
	const act = async (id, action, before) => {   // a button of a plugin's row, through the confirmation dialogue when there is one
		await open(p, 'index.php?p=update&tab=plugins');
		if (before) await before();
		const btn = p.locator(`tr[data-plugin="${id}"] [data-plg-act="${action}"]`);
		if (await btn.count() === 0) throw new Error('no ' + action + ' button for ' + id + ': ' + ((await p.locator(`tr[data-plugin="${id}"]`).count()) ? await p.locator(`tr[data-plugin="${id}"]`).evaluate((r) => r.outerHTML.slice(0, 1500)) : (await p.locator('#updPane-plugins').innerText()).slice(0, 400)));
		const hasConfirm = await btn.evaluate((b) => !!b.closest('form').dataset.confirm);
		if (hasConfirm) {
			await btn.click();
			await p.waitForSelector('#nuConfirm.show .js-confirm-ok');
			await Promise.all([p.waitForNavigation(), p.click('#nuConfirm.show .js-confirm-ok')]);
		} else await Promise.all([p.waitForNavigation(), btn.click()]);
		if (await reauth()) return act(id, action, before);
	};
	try {
		cleanup();
		fs.mkdirSync(dir, { recursive: true });
		copy('e2e_ex', 'E2E example');
		await open(p, 'index.php?p=update&tab=plugins');
		expect(await p.locator('#updTabBtn-plugins').count() === 1, 'tab Plugins on the update page');
		const row = p.locator('tr[data-plugin="e2e_ex"]');
		expect(await row.locator('[data-status="off"]').count() === 1 && await row.locator('.nuPlgSig[data-sig="unsigned"]').count() === 1, 'listed: off, not signed');
		await act('e2e_ex', 'enable');
		expect(await p.locator('tr[data-plugin="e2e_ex"] [data-status="on"]').count() === 1, 'switched on: ' + (await p.locator('.alert').allTextContents()).join(' | '));
		expect(sql("SHOW TABLES LIKE 'zzzzsys_nunext_plg_e2e_ex_notes'") !== '', 'onEnable created its table');
		// start page tile, user menu, page, JSON, asset
		await open(p, 'index.php?p=home');
		expect(await p.locator('a[href*="p=plugin&id=e2e_ex&r=main"]').count() >= 1, 'tile and menu entry of the plugin');
		expect(await p.evaluate(() => document.documentElement.getAttribute('data-example-plugin')) === '1', 'the plugin script ran on the page');
		await open(p, 'index.php?p=plugin&id=e2e_ex&r=main');
		await p.fill('#exNote', 'First note');
		await Promise.all([p.waitForNavigation(), p.click('#exAdd')]);
		expect((await p.locator('#exNotes li').allTextContents()).join('').includes('First note'), 'note added on the plugin page');
		expect(await p.locator('h1').first().textContent() === 'Notes', 'title of the route');
		const cnt = await p.evaluate(async (u) => (await fetch(u)).json(), BASE + 'index.php?p=plugin&id=e2e_ex&r=count');
		expect(cnt.notes === 1, 'JSON route: ' + JSON.stringify(cnt));
		const asset = await p.evaluate(async (u) => { const r = await fetch(u); return [r.status, r.headers.get('content-type')]; }, BASE + 'index.php?p=pluginasset&id=e2e_ex&f=example.js');
		expect(asset[0] === 200 && /javascript/.test(asset[1]), 'asset served: ' + asset);
		const bad = await p.evaluate(async (u) => (await fetch(u)).status, BASE + 'index.php?p=pluginasset&id=e2e_ex&f=../plugin.php');
		expect(bad === 404, 'no file outside assets/ (' + bad + ')');
		// settings + the event record.before_save
		await open(p, 'index.php?p=plugins&settings=e2e_ex');
		if (await reauth()) await open(p, 'index.php?p=plugins&settings=e2e_ex');
		await p.fill('#exSetBlock', 'zzblock');
		await Promise.all([p.waitForNavigation(), p.click('button[form=plgSettingsForm]')]);
		if (await reauth()) { await p.fill('#exSetBlock', 'zzblock'); await Promise.all([p.waitForNavigation(), p.click('button[form=plgSettingsForm]')]); }
		expect(/zzblock/.test(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'plg:e2e_ex'")), 'setting stored');
		const cid = sql("SELECT sample_customer_id FROM sample_customer ORDER BY sample_customer_id LIMIT 1");
		const before = sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + cid + "'");
		await open(p, 'index.php?p=form&f=samplecustomer&r=' + cid);
		await setField(p, '#cus_name', 'Name zzblock');
		await save(p);
		expect(sql("SELECT cus_name FROM sample_customer WHERE sample_customer_id = '" + cid + "'") === before, 'the plugin refused the save');
		expect((await p.content()).includes('does not save records containing'), 'its message is shown');
		p.errors.length = 0;
		// safe mode: no plugin on the page
		await open(p, 'index.php?p=home&safe=1');
		expect(await p.locator('script[src*="p=pluginasset"]').count() === 0, 'safe mode: no plugin scripts');
		// a plugin that fails is switched off by itself
		const badDir = copy('e2e_bad', 'E2E failing');
		fs.writeFileSync(path.join(badDir, 'plugin.php'), "<?php\n/** @var NuPluginApi $plugin */\n$plugin->route('main', function () { throw new RuntimeException('e2e boom'); });\n");
		execSync('php nucron.php plugins on e2e_bad', { cwd: SITE });
		const st = await p.evaluate(async (u) => (await fetch(u)).status, BASE + 'index.php?p=plugin&id=e2e_bad&r=main');
		expect(st === 500, 'failing route: 500 (' + st + ')');
		const state = JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'plugins'"));
		expect(!state.on.e2e_bad && /e2e boom/.test(state.failed.e2e_bad.error), 'switched off with the error: ' + JSON.stringify(state));
		await open(p, 'index.php?p=update&tab=plugins');
		expect(await p.locator('tr[data-plugin="e2e_bad"] [data-status="failed"]').count() === 1, 'listed as switched off after an error');
		// upload: off by default, then on
		expect(await p.locator('#plgUploadOff').count() === 1, 'upload is off by default');
		await Promise.all([p.waitForNavigation(), p.click('#plgUploadSet')]);
		if (await reauth()) { await open(p, 'index.php?p=update&tab=plugins'); await Promise.all([p.waitForNavigation(), p.click('#plgUploadSet')]); }
		const upSrc = copy('e2e_up', 'E2E uploaded');
		const zip = path.join(os.tmpdir(), 'nunext-e2e-plugin.zip');
		fs.rmSync(zip, { force: true });
		execSync(`cd ${JSON.stringify(dir)} && python3 -c "import zipfile,os,sys; z=zipfile.ZipFile(sys.argv[1],'w'); [z.write(os.path.join(r,f), os.path.join(r,f)) for r,_,fs in os.walk('e2e_up') for f in fs]; z.close()" ${JSON.stringify(zip)}`);
		fs.rmSync(upSrc, { recursive: true, force: true });
		await open(p, 'index.php?p=update&tab=plugins');
		await p.setInputFiles('#plgZip', zip);
		await Promise.all([p.waitForNavigation(), p.click('#plgUploadBtn')]);
		expect(await p.locator('#plgCheck').count() === 1 && await p.locator('#plgUnsigned').count() === 1, 'package checked, warning for no signature');
		await Promise.all([p.waitForNavigation(), p.click('#plgCheck [data-plg-act="install"]')]);
		expect(fs.existsSync(path.join(dir, 'e2e_up', 'plugin.php')), 'installed into plugins/e2e_up');
		expect(await p.locator('tr[data-plugin="e2e_up"]').count() === 1, 'listed after the upload');
		// remove with its data
		await act('e2e_ex', 'remove', () => p.check('#plgData-e2e_ex'));
		expect(!fs.existsSync(path.join(dir, 'e2e_ex')), 'folder removed');
		expect(sql("SHOW TABLES LIKE 'zzzzsys_nunext_plg_e2e_ex_notes'") === '', 'its table dropped');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'plg:e2e_ex'") === '0', 'its stored values removed');
		// the command line
		execSync('php nucron.php plugins on e2e_up', { cwd: SITE });
		execSync('php nucron.php plugins off', { cwd: SITE });
		expect(!/"e2e_up"/.test(JSON.stringify(JSON.parse(sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'plugins'")).on)), 'plugins off from the command line');
	} finally {
		cleanup();
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// a GitHub archive of a branch (one top folder, e.g. nuBuilder-Next-main/, plus files the manifest does not list) passes Check package;
// a zip with more than one top folder does not; the button "Get branch main" is on the GitHub card
T.update_branch_archive = async (s) => {
	const pkg = process.env.PKG;
	if (!pkg || !fs.existsSync(pkg)) return 'skip';
	const p = s.ga;
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nunext-e2e-branch-'));
	const build = (name, extraTop) => {
		const out = path.join(tmp, name);
		execSync(`python3 -I -c "
import zipfile, sys
src = zipfile.ZipFile(sys.argv[1]); out = zipfile.ZipFile(sys.argv[2], 'w', zipfile.ZIP_DEFLATED)
out.writestr('nuBuilder-Next-main/', '')
for i in src.infolist():
    if i.filename.startswith('nunext/_install/'): continue
    out.writestr('nuBuilder-Next-main/' + i.filename[len('nunext/'):], src.read(i))
out.writestr('nuBuilder-Next-main/dev/not-copied.txt', 'x')
if sys.argv[3] == '1': out.writestr('README-extra.txt', 'a second top entry')
out.close()" ${JSON.stringify(pkg)} ${JSON.stringify(out)} ${extraTop ? '1' : '0'}`);
		return out;
	};
	const check = async (file) => {
		await open(p, 'index.php?p=update');
		if (!(await p.locator('#updOther').isVisible())) { await p.click('#updOtherBtn'); await p.waitForTimeout(500); }
		await p.setInputFiles('#updPackage', file);
		await Promise.all([p.waitForLoadState('networkidle'), p.click('#updUpload')]);
		await p.waitForTimeout(400);
		return p.locator('#updInstallForm').count();
	};
	try {
		expect(await p.locator('#updGitHubBranch').count() === 1 || await (async () => { await open(p, 'index.php?p=update'); return p.locator('#updGitHubBranch').count(); })() === 1,
			'button Get branch main on the GitHub card');
		expect(await check(build('main.zip', false)) === 1, 'archive with one top folder accepted: ' + (await p.locator('.alert-danger').allTextContents()).join(' | '));
		expect(await check(build('two.zip', true)) === 0, 'zip with a second top entry refused');
	} finally {
		fs.rmSync(tmp, { recursive: true, force: true });
		for (const f of fs.readdirSync(path.join(SITE || '.', '_update')).filter(f => /^(main|two)\.zip$/.test(f))) fs.rmSync(path.join(SITE, '_update', f), { force: true });
	}
};

// Reset database: clean installation (nuBuilder Next's install scripts, no file – the only choice on SQL Server; tested here on MySQL)
T.db_reset_clean = async (s) => {
	const p = s.ga;
	const r = await open(p, 'index.php?p=dbreset');
	if (r.status() === 404) { s.skip = 'developer mode off'; return; }
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nudbrc-')), dump = path.join(tmp, 'e2e-full.sql');
	execSync('mysqldump ' + DB + ' > ' + dump);
	try {
		sql('CREATE TABLE e2e_dbr_clean (id INT PRIMARY KEY)');
		await open(p, 'index.php?p=dbreset');
		const first = await p.$eval('#dbrSource option', (o) => o.value);
		expect(/clean/i.test(first), 'clean installation not the first source: ' + first);
		await p.selectOption('#dbrSource', first);
		await p.uncheck('#dbrBackup0');
		await Promise.all([p.waitForNavigation(), p.click('#dbrCheckBtn')]);
		expect(await p.locator('#dbrCheck[data-kind="clean"]').count() === 1 && await p.locator('#dbrCleanCreates').count() === 1,
			'check of the clean installation: ' + (await p.locator('.alert').allTextContents()).join(' | '));
		expect(await p.locator('#dbrTables code', { hasText: 'e2e_dbr_clean' }).count() === 1, 'marker not listed as deleted');
		await p.fill('#dbrConfirm', sql('SELECT DATABASE()'));
		await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#dbrRun')]);
		if (/p=reauth/.test(p.url())) {
			await p.fill('#reauthPassword', GA[1]);
			await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]);
			await open(p, 'index.php?p=dbreset');
			await p.fill('#dbrConfirm', sql('SELECT DATABASE()'));
			await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#dbrRun')]);
		}
		expect(await p.locator('#dbrDone').count() === 1, 'no result: ' + (await p.locator('.alert').allTextContents()).join(' | '));
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'e2e_dbr_clean'") === '0', 'marker table still there');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id LIKE 'sample%'") === '0', 'sample forms still there');
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('zzzzsys_form', 'zzzzsys_user', 'zzzzsys_nunext_setting')") === '3', 'tables of the install scripts missing');
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'database_reset'") !== '0', 'reset not logged');
		const u = await open(p, 'index.php?p=users');
		expect(u.status() === 200 && /p=users/.test(p.url()), 'signed out after the reset: ' + p.url());
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		execSync('mysql ' + DB, { input: fs.readFileSync(dump) });
		sql('DROP TABLE IF EXISTS e2e_dbr_clean');
		fs.rmSync(tmp, { recursive: true, force: true });
	}
};

T.db_reset_no_devmode = async (s) => {
	// Reset database also without developer mode: tile with the red Danger badge, the page opens with the Danger zone box
	if (!SITE) { s.skip = 'needs SITE'; return; }
	const p = s.ga, cfg = path.join(SITE, 'nuconfig.php'), orig = fs.readFileSync(cfg, 'utf8');
	try {
		fs.writeFileSync(cfg, orig.replace(/^(\$nuConfigDevMode\s*=\s*)true/m, '$1false'));
		for (let n = 0; n < 8; n++) {                                // OPcache may keep the old nuconfig.php for a moment
			await p.waitForTimeout(1000);
			await open(p, 'index.php');
			if (await p.locator('#nuPerf').count() === 0) break;
		}
		const tile = p.locator('a[data-nu-tile="dbreset"]');
		expect(await tile.count() > 0, 'no Reset database tile without developer mode');
		expect(await p.locator('#nuPerf').count() === 0, 'developer mode still on');
		const r = await open(p, 'index.php?p=dbreset');
		if (r.status() === 404) { s.skip = 'not MySQL / SQL Server'; return; }
		expect(await p.locator('#dbrDanger').isVisible(), 'no Danger zone box');
		expect((await p.locator('#dbrDanger').textContent()).includes('nutest'), 'Danger zone without the database name');
		expect(await p.locator('.nuTitleBar .badge', {hasText: 'Dev'}).count() === 0, 'Dev badge still shown');
	} finally { fs.writeFileSync(cfg, orig); await p.waitForTimeout(1500); }
};

// Update → System → Database steps: grouped by area, every step once, search and area chips filter the rows
T.update_steps_grouped = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=update&tab=system');
	// clicks through the page itself: late in a full run a toast / notice may cover the controls for a moment
	const tap = async (sel) => { const ok = await p.evaluate((q) => { const e = document.querySelector(q); if (!e) return false; e.click(); return true; }, sel); expect(ok, 'control there: ' + sel); await p.waitForTimeout(200); };
	if (!await p.locator('#updSteps').isVisible()) { await tap('#updStepsToggle'); await p.waitForTimeout(400); }
	const r = await p.evaluate(() => ({
		rows: document.querySelectorAll('#updSteps .updStRow').length,
		groups: [...document.querySelectorAll('#updSteps .updStGrp')].map((g) => g.dataset.area),
		titled: [...document.querySelectorAll('#updSteps .updStTitle')].every((t) => t.textContent.trim().length > 0 && t.textContent.length < 60),
		kinds: document.querySelectorAll('#updSteps .updStKind').length
	}));
	const total = Number(sql('SELECT COUNT(*) FROM zzzzsys_nunext_migration'));
	expect(r.rows >= total && r.groups[0] === 'next' && r.groups.includes('settings') && r.titled && r.kinds >= r.rows, 'grouped steps: ' + JSON.stringify(r));
	await p.fill('#updStQ', 'passkey'); await p.waitForTimeout(150);
	const q = await p.evaluate(() => [...document.querySelectorAll('#updSteps .updStRow:not([hidden])')].map((x) => x.dataset.step));
	expect(q.length >= 2 && q.every((id) => /passkey/.test(id)), 'search passkey: ' + q);
	await p.fill('#updStQ', '');
	await tap('.updStChip[data-area="settings"]');
	const c = await p.evaluate(() => ({
		shown: [...document.querySelectorAll('#updSteps .updStGrp:not([hidden])')].map((g) => g.dataset.area),
		pressed: document.querySelector('.updStChip[data-area="settings"]').getAttribute('aria-pressed')
	}));
	expect(c.shown.length === 1 && c.shown[0] === 'settings' && c.pressed === 'true', 'area chip: ' + JSON.stringify(c));
	await p.fill('#updStQ', 'zzzz-nothing'); await p.waitForTimeout(150);
	expect(await p.locator('#updStNone').isVisible(), 'empty message');
	await tap('#updStReset');
	expect(await p.locator('#updSteps .updStRow:not([hidden])').count() === r.rows, 'show all');
};
