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
	fs.writeFileSync(evil, plain + "\nCREATE TABLE customers (a int);\n");
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
		await Promise.all([p.waitForNavigation(), p.click('main form button[type=submit]')]);           // start (local server: no token)
		await p.selectOption('#type', 'mysql');
		for (const [id, v] of [['host', '127.0.0.1'], ['port', '3306'], ['name', NAME], ['user', 'nu'], ['password', 'nu']]) await p.fill('#' + id, v);
		// 0.8.703: "Test connection" answers on the installer page (0.8.701–0.8.702 stopped with "nuconfig.php not found")
		await Promise.all([p.waitForNavigation(), p.click('#insTest')]);
		expect(await p.locator('#insDbForm').count() === 1 && !/nuconfig-sample\.php/.test(await p.content()), 'test connection: ' + (await p.locator('main').textContent()).slice(0, 200));
		await next();
		expect(await p.locator('#choice_up').count() === 1, 'no upload option');
		// a dump with a statement on another table is refused
		await p.check('#choice_up');
		await p.setInputFiles('#dump', evil);
		await Promise.all([p.waitForNavigation(), p.click('#insCheckDump')]);
		expect(/customers/.test(await p.locator('.invalid-feedback').first().textContent()), 'foreign statement not refused');
		// the real one
		await p.check('#choice_up');
		await p.setInputFiles('#dump', DUMP);
		await Promise.all([p.waitForNavigation(), p.click('#insCheckDump')]);
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
		const ticked = {};
		for (const r of await p.locator('.nuInsLib').all()) ticked[await r.getAttribute('data-slot')] = await r.locator('input').isChecked();
		if (packs.length) {
			const has = (s) => packs.some((f) => f.startsWith('nunext-lib-' + s + '-'));
			if (has('tcpdf')) expect(ticked.tcpdf, 'TCPDF not ticked');
			if (has('phpmyadmin')) expect(!ticked.phpmyadmin, 'phpMyAdmin ticked');
			expect(await p.locator('.nuInsLib .text-danger').count() >= 1, 'changed pack not refused');
		}
		await next();                                                                                          // libraries
		if (packs.length) expect(/TCPDF|PHPMailer/.test(await p.locator('#insSumLibs').textContent()), 'summary without libraries');
		await Promise.all([p.waitForNavigation({ timeout: 300000 }), p.click('#insInstall')]);
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
		console.log('install: ok (' + path.basename(DUMP) + ') – ' + ((await p.locator('body').count()) ? 'signed in' : ''));
		// 0.8.705: the same database again, now an existing nuBuilder Forte database (nuBuilder Next's own tables dropped, no nuconfig.php):
		// refused without the marker file _install/allow-existing-db; with it the content step shows the integrity check and the written
		// nuconfig.php allows the existing database ($nuConfigAllowExistingNuDB = true)
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
		expect(await q.locator('#insDbForm').count() === 1 && /nuBuilder Forte/.test(await q.locator('main').textContent()), 'existing database not refused without the marker');
		fs.mkdirSync(path.join(SITE, '_install'), { recursive: true });
		fs.writeFileSync(path.join(SITE, '_install', 'allow-existing-db'), '');
		await dbStep();
		expect(await q.locator('#insIntegrity').count() === 1, 'no integrity check on the content step: ' + (await q.locator('main').textContent()).slice(0, 200));
		if (await q.locator('#nuIcAck').count()) await q.check('#nuIcAck');
		await next2();
		await q.fill('#user', 'globeadmin'); await q.fill('#password', 'Install-Test-2'); await q.fill('#password2', 'Install-Test-2');
		await next2();                                                                                         // admin
		await next2();                                                                                         // options
		await next2();                                                                                         // libraries
		await Promise.all([q.waitForNavigation({ timeout: 300000 }), q.click('#insInstall')]);
		const cfg = fs.readFileSync(path.join(SITE, 'nuconfig.php'), 'utf8');
		expect(/\$nuConfigAllowExistingNuDB\s*=\s*true;/.test(cfg), 'nuconfig.php without $nuConfigAllowExistingNuDB = true');
		expect(!fs.existsSync(path.join(SITE, '_install', 'allow-existing-db')), 'marker file left');
		expect(sql("SELECT setting_value FROM " + NAME + ".zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'db_origin'") === 'nubuilder', 'origin');
		await q.goto(BASE + 'index.php?p=login');
		await q.fill('input[name=username]', 'globeadmin'); await q.fill('input[name=password]', 'Install-Test-2');
		await Promise.all([q.waitForNavigation(), q.click('button[type=submit]')]);
		expect(!/p=login/.test(q.url()), 'login failed after the install on the existing database');
		await ctx2.close();
		console.log('install on an existing database: ok');
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
