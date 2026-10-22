/*
 * Installer with an uploaded nubuilder4.sql (0.8.384): a fresh copy of the package in NUB_DIR/nuinst (served by the PHP server that serves
 * NUB_DIR at NUB), an empty database, the wizard in the browser with the dump uploaded, then a look at the database and the login.
 * A dump with a foreign statement must be refused. Cleans up (folder, database) at the end.
 *
 *   NUB=http://127.0.0.1:8099/ NUB_DIR=<nuBuilder folder> DUMP=<nubuilder4.sql of any 4.x> DB="-unu -pnu" NODE_PATH=$(npm root -g) node dev/tests/install.js
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
		expect(await p.locator('#insNotice').count() === 1, 'dump not accepted: ' + await p.locator('.invalid-feedback').allTextContents());
		await p.check('#choice_up');
		await next();
		await p.fill('#user', 'globeadmin'); await p.fill('#password', 'Install-Test-1'); await p.fill('#password2', 'Install-Test-1');
		await next();
		await next();                                                                                          // options
		await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#insInstall')]);
		const done = await p.locator('main').textContent();
		expect(!/alert-danger/.test(await p.content()) || /insUpgraded/.test(await p.content()), 'install failed: ' + done.slice(0, 300));
		// the structure of 4.9 (columns of every version up to 4.9 present)
		const cols = sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" + NAME + "' AND ((TABLE_NAME = 'zzzzsys_object' AND COLUMN_NAME IN ('sob_all_access_condition','sob_select_procedure','sob_all_json')) OR (TABLE_NAME = 'zzzzsys_app_cloner' AND COLUMN_NAME = 'cla_copy_files_mode') OR (TABLE_NAME = 'zzzzsys_form' AND COLUMN_NAME = 'sfo_json'))");
		expect(cols === '5', '4.9 columns missing (' + cols + ' of 5)');
		expect(Number(sql('SELECT COUNT(*) FROM ' + NAME + '.zzzzsys_form')) > 50, 'nuBuilder forms not imported');
		expect(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" + NAME + "' AND TABLE_TYPE = 'VIEW'") === '0', 'old views left');
		expect(!fs.readdirSync(path.join(SITE, '_install')).some((f) => /\.sql|\.zip|\.gz/.test(f)), 'uploaded file left in _install/');
		// signs in
		await p.goto(BASE + 'index.php?p=login');
		await p.fill('input[name=username]', 'globeadmin'); await p.fill('input[name=password]', 'Install-Test-1');
		await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
		expect(!/p=login/.test(p.url()), 'login failed after the install');
		expect(errs.length === 0, 'JavaScript errors: ' + errs.join(' · '));
		console.log('install: ok (' + path.basename(DUMP) + ') – ' + ((await p.locator('body').count()) ? 'signed in' : ''));
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
