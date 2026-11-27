/*
 * Installer with an uploaded nubuilder4.sql (0.8.384): a fresh copy of the package in NUB_DIR/nuinst (served by the PHP server that serves
 * NUB_DIR at NUB), an empty database, the wizard in the browser with the dump uploaded, then a look at the database and the login.
 * A dump with a foreign statement must be refused. Cleans up (folder, database) at the end.
 *
 *   NUB=http://127.0.0.1:8099/ NUB_DIR=<nuBuilder folder> DUMP=<nubuilder4.sql of any 4.x> DB="-unu -pnu" NODE_PATH=$(npm root -g) node dev/tests/install.js
 *
 * Library packs (0.8.405): LIBPACKS=<folder with signed nunext-lib-*.zip> copies them into _install/ (plus a changed copy that must be
 * refused); the step "Libraries" must offer them (TCPDF / PHPMailer ticked, phpMyAdmin not) and install the ticked ones.
 * Without LIBPACKS the step is only passed.
 * OLD_DUMP=<nubuilder4.sql of a version before 4.9>: also an existing database of that version (imported as it is) – brought to 4.9.
 */
'use strict';
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const NUB = (process.env.NUB || 'http://127.0.0.1:8099/').replace(/\/?$/, '/');
const NUB_DIR = process.env.NUB_DIR;
const DUMP = process.env.DUMP;
const DB = process.env.DB || '-unu -pnu';
const NAME = 'nuinst_e2e';
const LIBPACKS = process.env.LIBPACKS || '';
const OLD_DUMP = process.env.OLD_DUMP || '';   // optional: nubuilder4.sql of a Forte version before 4.9, imported as an existing database
if (!NUB_DIR || !DUMP) { console.error('NUB_DIR and DUMP are needed'); process.exit(2); }
const SITE = path.join(NUB_DIR, 'nuinst');
const BASE = NUB + 'nuinst/';
const sql = (q) => execSync('mysql -N -B ' + DB, { input: q }).toString().trim();
const expect = (ok, msg) => { if (!ok) throw new Error(msg); };

(async () => {
	execSync('rsync -a --delete --exclude nuconfig.php --exclude _backup --exclude _install --exclude _update ' + JSON.stringify(path.resolve(__dirname, '../..') + '/') + ' ' + JSON.stringify(SITE + '/'));
	sql('DROP DATABASE IF EXISTS ' + NAME + '; CREATE DATABASE ' + NAME + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
	const evil = path.join(os.tmpdir(), 'nuinst-evil.sql');
	const plain = /\.gz$/i.test(DUMP) ? require('zlib').gunzipSync(fs.readFileSync(DUMP)).toString('utf8') : fs.readFileSync(DUMP, 'utf8');
	fs.writeFileSync(evil, plain + "\nSELECT * FROM zzzzsys_user INTO OUTFILE '/tmp/nuinst-e2e.txt';\n");
	const packs = LIBPACKS ? fs.readdirSync(LIBPACKS).filter((f) => /^nunext-lib-.*\.zip$/.test(f)) : [];
	if (packs.length) {
		fs.mkdirSync(path.join(SITE, '_install'), { recursive: true });
		for (const f of packs) fs.copyFileSync(path.join(LIBPACKS, f), path.join(SITE, '_install', f));
		fs.copyFileSync(path.join(LIBPACKS, packs[0]), path.join(SITE, '_install', packs[0].replace(/-([0-9][^-]*)\.zip$/, '-99.0.zip')));   // renamed: refused
	}
	const browser = await chromium.launch();
	let failed = false;
	try {
		const p = await browser.newPage();
		const errs = []; p.on('pageerror', (e) => errs.push(e.message));
		const next = async () => { await Promise.all([p.waitForNavigation(), p.click('#insNext')]); };
		await p.goto(BASE);
		expect(await p.locator('main.nu-install').count() === 1, 'no installer: ' + p.url());
		// 0.8.1047: server checks – passed ones folded under one summary line, problems always visible
		const rq = await p.evaluate(() => { const r = document.getElementById('insReq'), f = document.getElementById('insReqOk');
			return {ok: +r.dataset.ok, bad: +r.dataset.bad, open: !!(f && f.open), shown: [...r.querySelectorAll('.insReqRow')].filter((x) => x.checkVisibility()).length,
				all: r.querySelectorAll('.insReqRow').length}; });
		expect(rq.ok > 0 && !rq.open && rq.shown === rq.bad && rq.all === rq.ok + rq.bad, 'server checks folded: ' + JSON.stringify(rq));
		await p.click('#insReqOk > summary');
		expect(await p.evaluate(() => [...document.querySelectorAll('#insReq .insReqRow')].every((x) => x.checkVisibility())), 'Show all lists every check');
		await Promise.all([p.waitForNavigation(), p.click('main form button[type=submit]')]);           // start (local server: no token)
		await p.selectOption('#type', 'mysql');
		for (const [id, v] of [['host', '127.0.0.1'], ['port', '3306'], ['name', NAME], ['user', 'nu'], ['password', 'nu']]) await p.fill('#' + id, v);
		// 0.8.703: "Test connection" answers on the installer page (0.8.701–0.8.702 stopped with "nuconfig.php not found")
		await Promise.all([p.waitForNavigation(), p.click('#insTest')]);
		expect(await p.locator('#insDbForm').count() === 1 && !/nuconfig-sample\.php/.test(await p.content()), 'test connection: ' + (await p.locator('main').textContent()).slice(0, 200));
		// 0.8.1048: library packs in _install/ are not offered as database dumps
		const fakeLib = path.join(SITE, '_install', 'nunext-lib-e2etest-1.0.zip'), fakeSql = path.join(SITE, '_install', 'zz-e2e-pick.sql');
		fs.mkdirSync(path.join(SITE, '_install'), { recursive: true });
		fs.writeFileSync(fakeLib, 'x'); fs.writeFileSync(fakeSql, '-- x');
		await next();
		const picks = await p.locator('#dump_pick option').allTextContents();
		fs.unlinkSync(fakeLib); fs.unlinkSync(fakeSql);
		expect(picks.some((o) => o.includes('zz-e2e-pick.sql')), 'dump not offered: ' + picks.join(', '));
		expect(!picks.some((o) => o.includes('nunext-lib-')), 'library pack offered as dump: ' + picks.join(', '));
		expect(await p.locator('#choice_up').count() === 1, 'no upload option');
		// a dump with a statement on another table is refused
		await p.check('#choice_up');
		await p.setInputFiles('#dump', evil);
		await Promise.all([p.waitForNavigation(), p.click('#insCheckDump')]);
		expect(/OUTFILE/.test(await p.locator('.invalid-feedback').first().textContent()), 'foreign statement not refused');
		// the real one
		// 0.8.1398: a dump larger than 64 MB (unpacked), copied into _install/, is checked and imported as a stream
		const bigName = 'zz-e2e-big.sql.gz', pad = 'x'.repeat(2000), rows = [];
		for (let b = 0; b < 700; b++) rows.push('INSERT INTO `zzzzsys_debug` (zzzzsys_debug_id, deb_message, deb_added) VALUES ' + Array.from({length: 50}, (_, i) => `('e2ebig${b}_${i}','${pad}',1)`).join(',') + ';\n');
		fs.writeFileSync(path.join(SITE, '_install', bigName), require('zlib').gzipSync(plain + '\n' + rows.join('') + 'CREATE TABLE `e2e_app_tbl` (`a` int);\nINSERT INTO `e2e_app_tbl` VALUES (7);\n'));   // + a table of the application (0.8.1401)
		await p.reload();
		// a picked / chosen file ticks "nubuilder4.sql …" by itself (0.8.1400); another choice clears it
		await p.check('#choice_nn');
		const bigOpt = await p.locator('#dump_pick option').evaluateAll((os, n) => os.filter((o) => o.textContent.includes(n)).map((o) => o.value)[0], bigName);
		expect(!!bigOpt, 'large dump not offered');
		await p.selectOption('#dump_pick', bigOpt);
		expect(await p.isChecked('#choice_up'), 'picking a file did not choose the dump');
		await p.check('#choice_nn');
		expect(await p.$eval('#dump_pick', (x) => x.value) === '', 'another choice kept the picked file');
		await p.setInputFiles('#dump', {name: 'x.sql', mimeType: 'text/plain', buffer: Buffer.from('SELECT 1;')});
		expect(await p.isChecked('#choice_up'), 'choosing a file did not choose the dump');
		await p.setInputFiles('#dump', []);
		await p.selectOption('#dump_pick', bigOpt);
		// 0.8.1402: Check with a dump shows a spinner on the button and a note while the server unpacks and checks it
		// watched by a MutationObserver that notes it in sessionStorage – the page leaves as soon as the check is done, and older
		// Playwright versions (CI) poll waitForFunction through the page's script engine, which the CSP refuses
		await p.evaluate(() => {
			sessionStorage.removeItem('e2eBusy');
			const seen = () => { const b = document.getElementById('insCheckDump'); return !!document.getElementById('insDumpBusy') && b && b.disabled && !!b.querySelector('.spinner-border'); };
			new MutationObserver(() => { if (seen()) sessionStorage.setItem('e2eBusy', '1'); }).observe(document.body, {subtree: true, childList: true, attributes: true});
		});
		await Promise.all([p.waitForNavigation({timeout: 300000}), p.click('#insCheckDump', {noWaitAfter: true})]);
		expect(await p.evaluate(() => sessionStorage.getItem('e2eBusy')) === '1', 'no spinner / note while checking');
		expect(await p.locator('#insNotice, #insDumpOk').count() >= 1, 'dump not accepted: ' + await p.locator('.invalid-feedback').allTextContents());
		await p.check('#choice_up');
		await next();
		await p.fill('#user', 'globeadmin'); await p.fill('#password', 'Install-Test-1'); await p.fill('#password2', 'Install-Test-1');
		await next();
		// options: languages to include (0.8.693) – none is refused, then English + French
		for (const c of await p.locator('#insLangs input[name="langs[]"]').all()) await c.uncheck();
		await next();
		expect(await p.locator('#insLangs .invalid-feedback').count() === 1, 'no language accepted');
		await p.check('#lang_en'); await p.check('#lang_fr');
		await next();                                                                                          // options
		expect(await p.locator('#insLibsForm').count() === 1, 'no step Libraries');
		// 0.8.1049: every pack that can be installed is ticked by default
		expect(await p.locator('.nuInsLib input:not(:disabled):not(:checked)').count() === 0, 'pack not ticked by default');
		const ticked = {};
		for (const r of await p.locator('.nuInsLib').all()) ticked[await r.getAttribute('data-slot')] = await r.locator('input').isChecked();
		if (packs.length) {
			const has = (s) => packs.some((f) => f.startsWith('nunext-lib-' + s + '-'));
			if (has('tcpdf')) expect(ticked.tcpdf, 'TCPDF not ticked');
			if (has('phpmyadmin')) expect(ticked.phpmyadmin, 'phpMyAdmin not ticked');   // 0.8.1049: all ticked by default
			expect(await p.locator('.nuInsLib .text-danger').count() >= 1, 'changed pack not refused');
		}
		await next();                                                                                          // libraries
		if (packs.length) expect(/TCPDF|PHPMailer/.test(await p.locator('#insSumLibs').textContent()), 'summary without libraries');
		let slices = 0;                                        // 0.8.1401: a dump is imported in steps (action import_slice) before Install
		p.on('request', (r) => { if (r.method() === 'POST' && /action=import_slice|name="action"\r\n\r\nimport_slice/.test(r.postData() || '')) slices++; });
		await Promise.all([p.waitForNavigation({ timeout: 300000 }), p.click('#insInstall', { noWaitAfter: true })]);
		expect(slices >= 1, 'dump not imported in steps');
		const done = await p.locator('main').textContent();
		expect(!/alert-danger/.test(await p.content()) || /insUpgraded/.test(await p.content()), 'install failed: ' + done.slice(0, 300));
		// the structure of 4.9 (columns of every version up to 4.9 present)
		const cols = sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" + NAME + "' AND ((TABLE_NAME = 'zzzzsys_object' AND COLUMN_NAME IN ('sob_all_access_condition','sob_select_procedure','sob_all_json')) OR (TABLE_NAME = 'zzzzsys_app_cloner' AND COLUMN_NAME = 'cla_copy_files_mode') OR (TABLE_NAME = 'zzzzsys_form' AND COLUMN_NAME = 'sfo_json'))");
		expect(cols === '5', '4.9 columns missing (' + cols + ' of 5)');
		expect(Number(sql('SELECT COUNT(*) FROM ' + NAME + '.zzzzsys_form')) > 50, 'nuBuilder forms not imported');
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" + NAME + "' AND TABLE_TYPE = 'VIEW'") === '0', 'old views left');
		const wl = sql("SELECT GROUP_CONCAT(zzzzsys_email_template_id ORDER BY 1) FROM " + NAME + ".zzzzsys_email_template WHERE zzzzsys_email_template_id LIKE 'nx_welcome_%'");
		expect(wl === 'nx_welcome_en,nx_welcome_fr', 'welcome templates of the chosen languages: ' + wl);
		expect(sql("SELECT setting_value FROM " + NAME + ".zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'languages'") === '["en","fr"]', 'installed languages stored');
		expect(!fs.readdirSync(path.join(SITE, '_install')).some((f) => /\.sql|\.zip|\.gz/.test(f)), 'uploaded file left in _install/');
		expect(sql("SELECT COUNT(*) FROM " + NAME + ".zzzzsys_debug WHERE zzzzsys_debug_id LIKE 'e2ebig%'") === '35000', 'large dump not imported completely');
		expect(sql('SELECT a FROM ' + NAME + '.e2e_app_tbl') === '7', 'table of the application not imported');
		if (packs.length) {
			const want = Object.keys(ticked).filter((k) => ticked[k]);
			const got = fs.readdirSync(path.join(SITE, 'vendor/packs'));
			for (const s of want) expect(got.some((d) => d.startsWith((s === 'phpmyadmin' ? 'nudb' : s) + '-')), 'library not installed: ' + s);
			expect(await p.locator('#insLibsDone .bi-check-circle').count() === want.length, 'result list: ' + await p.locator('#insLibsDone').textContent());
			expect(fs.readFileSync(path.join(SITE, 'vendor/packs/nunext-libs.php'), 'utf8').startsWith('<?php exit; ?>'), 'state file');
		}
		// signs in
		await p.goto(BASE + 'index.php?p=login');
		await p.fill('input[name=username]', 'globeadmin'); await p.fill('input[name=password]', 'Install-Test-1');
		await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
		expect(!/p=login/.test(p.url()), 'login failed after the install');
		expect(errs.length === 0, 'JavaScript errors: ' + errs.join(' · '));
		console.log('install: ok (' + path.basename(DUMP) + ' + 70 MB, from _install/) – ' + ((await p.locator('body').count()) ? 'signed in' : ''));
		// 0.8.705 / 0.8.1008: the same database again, now an existing nuBuilder Forte database (nuBuilder Next's own tables dropped, no
		// nuconfig.php): accepted; the content step asks for a backup (tick required) and shows the integrity check
		const own = sql("SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema = '" + NAME + "' AND table_name LIKE 'zzzzsys!_nunext!_%' ESCAPE '!'");
		if (own && own !== 'NULL') sql('DROP TABLE ' + own.split(',').map((t) => NAME + '.' + t).join(', ') + ';');
		fs.unlinkSync(path.join(SITE, 'nuconfig.php'));
		const ctx2 = await browser.newContext(), q = await ctx2.newPage();
		const next2 = async () => { await Promise.all([q.waitForNavigation(), q.click('#insNext')]); };
		const dbStep = async () => {
			await q.goto(BASE);
			if (await q.locator('main form button[type=submit]').count() && !(await q.locator('#insDbForm').count())) await Promise.all([q.waitForNavigation(), q.click('main form button[type=submit]')]);
			await q.selectOption('#type', 'mysql');
			for (const [id, v] of [['host', '127.0.0.1'], ['port', '3306'], ['name', NAME], ['user', 'nu'], ['password', 'nu']]) await q.fill('#' + id, v);
			await next2();
		};
		await dbStep();
		expect(await q.locator('#insIntegrity').count() === 1 && await q.locator('#insBackup').count() === 1, 'no backup note / integrity check on the content step: ' + (await q.locator('main').textContent()).slice(0, 200));
		if (await q.locator('#nuIcAck').count()) await q.check('#nuIcAck');
		await next2();
		expect(await q.locator('#insBackupAck.is-invalid').count() === 1, 'went on without the backup tick');
		await q.check('#insBackupAck');
		if (await q.locator('#nuIcAck').count()) await q.check('#nuIcAck');
		await next2();
		await q.fill('#user', 'globeadmin'); await q.fill('#password', 'Install-Test-2'); await q.fill('#password2', 'Install-Test-2');
		await next2();                                                                                         // admin
		await next2();                                                                                         // options
		await next2();                                                                                         // libraries
		await Promise.all([q.waitForNavigation({ timeout: 300000 }), q.click('#insInstall', { noWaitAfter: true })]);
		const cfg = fs.readFileSync(path.join(SITE, 'nuconfig.php'), 'utf8');
		expect(!/nuConfigAllowExistingNuDB/.test(cfg), 'nuconfig.php still gets $nuConfigAllowExistingNuDB');
		expect(sql("SELECT setting_value FROM " + NAME + ".zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'db_origin'") === 'nubuilder', 'origin');
		await q.goto(BASE + 'index.php?p=login');
		await q.fill('input[name=username]', 'globeadmin'); await q.fill('input[name=password]', 'Install-Test-2');
		await Promise.all([q.waitForNavigation(), q.click('button[type=submit]')]);
		expect(!/p=login/.test(q.url()), 'login failed after the install on the existing database');
		await ctx2.close();
		console.log('install on an existing database: ok');
		// nuBuilder Forte itself on the database the installer just set up (its own nuconfig.php pointed there for the test): sign in,
		// open the users list and an access level, save it without a change – nuBuilder Forte's rows stay as they were. Skipped without
		// nuBuilder Forte's nuconfig.php in NUB_DIR (CI)
		const forteCfg = path.join(NUB_DIR, 'nuconfig.php');
		if (fs.existsSync(forteCfg) && fs.existsSync(path.join(NUB_DIR, 'core', 'nuapi.php'))) {
			const keep = fs.readFileSync(forteCfg, 'utf8');
			const gaPw = (keep.match(/\$nuConfigDBGlobeadminPassword\s*=\s*["']([^"']*)["']/) || [])[1] || 'nu';
			const rows = () => sql('SELECT CONCAT_WS("|", (SELECT COUNT(*) FROM ' + NAME + '.zzzzsys_access), (SELECT MD5(GROUP_CONCAT(CONCAT_WS("~", zzzzsys_access_id,'
				+ ' sal_code, sal_description, sal_zzzzsys_form_id, sal_group) ORDER BY zzzzsys_access_id)) FROM ' + NAME + '.zzzzsys_access), (SELECT MD5(GROUP_CONCAT('
				+ 'CONCAT_WS("~", zzzzsys_access_form_id, slf_zzzzsys_form_id, slf_add_button, slf_save_button, slf_delete_button, slf_clone_button, slf_print_button)'
				+ ' ORDER BY zzzzsys_access_form_id)) FROM ' + NAME + '.zzzzsys_access_form))');
			const level = sql('SELECT zzzzsys_access_id FROM ' + NAME + '.zzzzsys_access ORDER BY zzzzsys_access_id LIMIT 1');
			const ctx3 = await browser.newContext(), f = await ctx3.newPage();
			const errs = []; f.on('pageerror', (e) => errs.push(e.message)); f.on('dialog', (d) => d.accept());
			try {
				fs.writeFileSync(forteCfg, keep.replace(/(\$nuConfigDBName\s*=\s*)["'][^"']*["']/, '$1"' + NAME + '"'));
				const before = rows();
				await f.goto(NUB + 'index.php');
				await f.fill('#nuusername', 'globeadmin'); await f.fill('#nupassword', gaPw); await f.press('#nupassword', 'Enter');
				const drawn = async (form, rec) => f.waitForFunction(([fm, r]) => { try { const c = nuCurrentProperties(); return c.form_id === fm && (r === '' ? !!c.browse_columns : c.record_id === r); } catch (e) { return false; } }, [form, rec], { timeout: 20000 });
				await f.waitForFunction(() => { try { return !!nuCurrentProperties().form_id; } catch (e) { return false; } }, null, { timeout: 20000 });
				for (const [form, rec] of [['nuuser', ''], ['nuaccess', ''], ['nuaccess', level]]) {
					if (!rec && form === 'nuaccess' && !level) continue;
					await f.evaluate(([fm, r]) => nuForm(fm, r, '', '', 0), [form, rec]);
					await drawn(form, rec);
					await f.waitForLoadState('networkidle'); await f.waitForTimeout(600);
				}
				if (level) {
					const r = f.waitForResponse((x) => /nuapi\.php/.test(x.url()) && x.request().method() === 'POST', { timeout: 20000 });
					await f.evaluate(() => nuSaveAction());
					await r; await f.waitForLoadState('networkidle'); await f.waitForTimeout(1000);
				}
				expect(rows() === before, 'nuBuilder Forte changed its access level rows on a save without a change, after the install');
				expect(!errs.filter((e) => !/nuToast is not defined/.test(e)).length, 'nuBuilder Forte script errors after the install: ' + errs.join(' | '));
				console.log('nuBuilder Forte on the installed database: ok');
			} catch (e) {
				throw new Error('nuBuilder Forte on the installed database: ' + e.message.split('\n')[0] + ' – page: ' + ((await f.locator('body').textContent().catch(() => '')) || '').replace(/\s+/g, ' ').slice(0, 300) + ' – errors: ' + errs.join(' | '));
			} finally {
				fs.writeFileSync(forteCfg, keep);
				await ctx3.close();
			}
		} else console.log('nuBuilder Forte on the installed database: skipped (no nuconfig.php of nuBuilder Forte in NUB_DIR)');
		// 0.8.1418: an existing database of an older nuBuilder Forte (OLD_DUMP = e.g. the nubuilder4.sql of 4.5, imported as it is, not
		// uploaded): the content step offers to bring it to 4.9 (tick required), Install does it, the globeadmin's sign-in goes on
		if (OLD_DUMP) {
			sql('DROP DATABASE IF EXISTS ' + NAME + '; CREATE DATABASE ' + NAME + ';');
			execSync('mysql ' + DB, { input: Buffer.concat([Buffer.from('USE ' + NAME + ';\n'), fs.readFileSync(OLD_DUMP)]) });   // DB may name a database already
			fs.unlinkSync(path.join(SITE, 'nuconfig.php'));
			const ctx3 = await browser.newContext(), r = await ctx3.newPage();
			const next3 = async () => { await Promise.all([r.waitForNavigation({ timeout: 300000 }), r.click('#insNext')]); };
			await r.goto(BASE);
			if (await r.locator('main form button[type=submit]').count() && !(await r.locator('#insDbForm').count())) await Promise.all([r.waitForNavigation(), r.click('main form button[type=submit]')]);
			await r.selectOption('#type', 'mysql');
			for (const [id, v] of [['host', '127.0.0.1'], ['port', '3306'], ['name', NAME], ['user', 'nu'], ['password', 'nu']]) await r.fill('#' + id, v);
			await next3();
			expect(await r.locator('#insUpgrade49').count() === 1, 'no offer to bring the older database to 4.9');
			expect(!(await r.locator('#insIntegrity .text-danger, #insIntegrity [data-ic="views"]').count()), 'the views of 4.5 are listed as a problem');
			await r.check('#insBackupAck');
			if (await r.locator('#nuIcAck').count()) await r.check('#nuIcAck');
			await next3();
			expect(await r.locator('#insUpgradeAck.is-invalid').count() === 1, 'went on without the upgrade tick');
			await r.check('#insUpgradeAck'); await r.check('#insBackupAck');
			if (await r.locator('#nuIcAck').count()) await r.check('#nuIcAck');
			await next3();
			await r.fill('#user', 'globeadmin'); await r.fill('#password', 'Install-Test-3'); await r.fill('#password2', 'Install-Test-3');
			await next3(); await next3(); await next3();
			await Promise.all([r.waitForNavigation({ timeout: 300000 }), r.click('#insInstall', { noWaitAfter: true })]);
			expect(await r.locator('#insUpgraded').count() === 1, 'no upgrade reported: ' + (await r.locator('main').innerText()).slice(0, 300));
			expect(sql("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" + NAME + "' AND TABLE_NAME = 'zzzzsys_report_data'") === 'BASE TABLE', 'zzzzsys_report_data still a view');
			expect(sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" + NAME + "' AND TABLE_NAME = 'zzzzsys_object' AND COLUMN_NAME = 'sob_all_json'") === '1', 'columns of 4.9 missing');
			await r.goto(BASE + 'index.php?p=login');
			await r.fill('input[name=username]', 'globeadmin'); await r.fill('input[name=password]', 'Install-Test-3');
			await Promise.all([r.waitForNavigation(), r.click('button[type=submit]')]);
			expect(!/p=login|p=dbsetup/.test(r.url()), 'after the sign-in: ' + r.url());
			await ctx3.close();
			console.log('install on an existing database of ' + path.basename(path.dirname(OLD_DUMP)) + ': ok');
		}
	} catch (e) {
		failed = true;
		console.error('install: FAILED – ' + e.message);
	} finally {
		await browser.close();
		try { fs.unlinkSync(evil); } catch (x) { /* ignore */ }
		sql('DROP DATABASE IF EXISTS ' + NAME + ';');
		fs.rmSync(SITE, { recursive: true, force: true });
	}
	process.exit(failed ? 1 : 0);
})();
