// e2e tests: e-mail, notifications, webhooks – read by dev/tests/e2e.js into its scope (helpers: login, open, sql, expect, BASE, SITE …)
/* eslint-disable no-undef */

// e-mail settings → "Check connection" (0.8.432): server, encryption, login and sender are checked against a local fake
// SMTP server (dev/tests/fakesmtp.py); nothing is sent (no RCPT / DATA)
T.mail_check = async (s) => {
	const p = s.ga, port = 2600 + Math.floor(Math.random() * 300), log = path.join(os.tmpdir(), 'e2e-smtp-' + Date.now() + '.log');
	const cols = 'set_smtp_host,set_smtp_port,set_smtp_username,set_smtp_password,set_smtp_from_address,set_smtp_use_ssl,set_smtp_use_authentication';
	const old = sql('SELECT CONCAT_WS(\',\',' + cols.split(',').map(c => "IFNULL(HEX(CAST(" + c + " AS CHAR)),'-')").join(',') + ') FROM zzzzsys_setup LIMIT 1').split(',');
	const set = (pw, from) => sql("UPDATE zzzzsys_setup SET set_smtp_host='127.0.0.1', set_smtp_port=" + port + ", set_smtp_username='u', set_smtp_password='" + pw + "', set_smtp_from_address='" + from + "', set_smtp_use_ssl='0', set_smtp_use_authentication='1'");
	const srv = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), log, String(port)], { stdio: 'ignore' });
	const steps = () => p.evaluate(() => Object.fromEntries([...document.querySelectorAll('#mailCheckResult [data-step]')].map(l => [l.dataset.step, l.dataset.ok])));
	const check = async (btn, tab) => {
		await open(p, 'index.php?p=email&tab=' + tab);
		await Promise.all([p.waitForNavigation(), p.click(btn)]); await p.waitForLoadState('networkidle');
		return steps();
	};
	try {
		await new Promise(r => setTimeout(r, 600));
		set('p', 'noreply@example.com');
		let r = await check('#nuMailCheckBtn', 'test');
		expect(r.server === '1' && r.login === '1' && r.sender === '1', 'check ok: ' + JSON.stringify(r));
		const cmds = fs.readFileSync(log, 'utf8').split('\n');
		expect(cmds.includes('MAIL') && !cmds.includes('RCPT') && !cmds.includes('DATA') && cmds.includes('QUIT'), 'nothing sent: ' + cmds.join(' '));
		set('wrong', 'noreply@example.com');
		r = await check('#nuMailCheckBtn2', 'settings');
		expect(r.server === '1' && r.login === '0' && !r.sender, 'wrong password: ' + JSON.stringify(r));
		set('p', 'bad@example.com');
		r = await check('#nuMailCheckBtn', 'test');
		expect(r.login === '1' && r.sender === '0', 'sender refused: ' + JSON.stringify(r));
		srv.kill(); await new Promise(r => setTimeout(r, 300));
		r = await check('#nuMailCheckBtn', 'test');
		expect(r.server === '0', 'server down: ' + JSON.stringify(r));
		expect(+sql("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event IN ('email_check','email_check_failed')") > 0, 'logged');
	} finally {
		srv.kill();
		sql('UPDATE zzzzsys_setup SET ' + cols.split(',').map((c, i) => c + '=' + (old[i] === '-' ? 'NULL' : "UNHEX('" + old[i] + "')")).join(','));
		fs.rmSync(log, { force: true });
	}
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.mail_xfer = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=email');
	if (!await p.locator('#nuMailXferBtn').count()) { s.skip = 'no settings tab'; return; }
	expect(!await p.isVisible('#nuMailCopy'), 'menu items shown without opening the menu');
	await p.click('#nuMailXferBtn');
	expect(await p.isVisible('#nuMailCopy') && await p.isVisible('#nuMailPaste') && await p.isVisible('#nuMailDownload'), 'menu: copy / paste / download missing');
	const [dl] = await Promise.all([p.waitForEvent('download'), p.click('#nuMailDownload')]);
	const file = await dl.path(), j = JSON.parse(fs.readFileSync(file, 'utf8'));
	expect(/^smtp-settings.*\.json$/.test(dl.suggestedFilename()), 'file name: ' + dl.suggestedFilename());
	expect(j.type === 'nunext-smtp' && j.host === await p.inputValue('#m_host') && !('password' in j), 'file content: ' + JSON.stringify(j));
	expect(/./.test(await p.textContent('#nuMailClipStatus')), 'no status after the download');
	const before = await p.inputValue('#m_fromname');
	await p.click('#nuMailXferBtn');
	await p.click('#nuMailPaste');
	await p.waitForSelector('#nuMailPasteModal.show', );
	await p.fill('#nuMailPasteText', JSON.stringify(Object.assign({}, j, { from_name: 'E2E xfer' })));
	await p.click('#nuMailPasteApply');
	expect(await p.inputValue('#m_fromname') === 'E2E xfer', 'pasted file content not applied');
	await p.fill('#m_fromname', before);
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

T.mail_oauth = async (s) => {
	const p = s.ga, sport = 2600 + Math.floor(Math.random() * 300), oport = sport + 400;
	const slog = path.join(os.tmpdir(), 'e2e-osmtp-' + Date.now() + '.log'), olog = path.join(os.tmpdir(), 'e2e-oauth-' + Date.now() + '.log');
	const cols = 'set_smtp_host,set_smtp_port,set_smtp_username,set_smtp_password,set_smtp_from_address,set_smtp_use_ssl,set_smtp_use_authentication';
	const old = sql('SELECT CONCAT_WS(\',\',' + cols.split(',').map(c => "IFNULL(HEX(CAST(" + c + " AS CHAR)),'-')").join(',') + ') FROM zzzzsys_setup LIMIT 1').split(',');
	const keepSet = sql("SELECT CONCAT_WS(',', IFNULL((SELECT HEX(setting_value) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'mail_auth_type'), '-'), IFNULL((SELECT HEX(setting_value) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'mail_oauth'), '-'))").split(',');
	const smtp = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), slog, String(sport)], { stdio: 'ignore' });
	const prov = require('child_process').spawn('python3', [path.join(__dirname, 'fakeoauth.py'), olog, String(oport)], { stdio: 'ignore' });
	const settings = async () => { await open(p, 'index.php?p=email&tab=settings'); };
	try {
		await new Promise(r => setTimeout(r, 700));
		sql("UPDATE zzzzsys_setup SET set_smtp_host='127.0.0.1', set_smtp_port=" + sport + ", set_smtp_username='', set_smtp_password='', set_smtp_from_address='noreply@example.com', set_smtp_use_ssl='0', set_smtp_use_authentication='1'");
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('mail_oauth')");
		await settings();
		expect(!await p.isVisible('#nuMailOAuth') || (await p.inputValue('#m_authtype')) === 'XOAUTH2', 'OAuth block hidden for other methods');
		await p.selectOption('#m_authtype', 'XOAUTH2');
		expect(await p.isVisible('#nuMailOAuth'), 'OAuth block shown');
		expect((await p.inputValue('#m_oredir')).endsWith('/oauth.php'), 'redirect URI');
		await p.selectOption('#m_oprov', 'other');
		await p.fill('#m_ocid', 'cid'); await p.fill('#m_osec', 'csec');
		await p.fill('#m_oauth', 'http://127.0.0.1:' + oport + '/authorize'); await p.fill('#m_otok', 'http://127.0.0.1:' + oport + '/token'); await p.fill('#m_oscope', 'smtp');
		await Promise.all([p.waitForURL(/nuoauth\.php|p=email/, { timeout: 15000 }), p.click('#nuMailOAuthConnect')]);
		await p.waitForURL(/p=email/, { timeout: 15000 }); await p.waitForLoadState('networkidle');
		const state = (await p.textContent('#nuMailOAuthState')).trim();
		expect(/tester@example\.com/.test(state), 'connected: ' + state);
		const stored = sql("SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'mail_oauth'");
		if (stored.includes('"plain:')) expect(await p.locator('#nuMailOAuth .alert-warning').count() === 1, 'no $nuConfigSecretKey: stored plain, with a warning');
		else expect(!/csec|rt-1|tok-/.test(stored) && stored.includes('"enc:'), 'secrets encrypted');
		// Check connection: login with OAuth 2, token hidden in the conversation
		await open(p, 'index.php?p=email&tab=test');
		await Promise.all([p.waitForNavigation(), p.click('#nuMailCheckBtn')]); await p.waitForLoadState('networkidle');
		const login = await p.$eval('#mailCheckResult [data-step="login"]', l => l.dataset.ok + ' ' + l.textContent.replace(/\s+/g, ' '));
		expect(/^1 .*OAuth 2/.test(login), 'check: ' + login);
		const conv = await p.evaluate(() => (document.querySelector('#mailCheckResult pre, #mailCheckResult details') || {}).textContent || '');
		expect(!/tok-\d/.test(conv) && !/dG9rLT/.test(conv) && (/AUTH XOAUTH2 \*{8}/.test(conv) || !/AUTH XOAUTH2/.test(conv)), 'token hidden in the conversation');
		let sl = fs.readFileSync(slog, 'utf8');
		expect(/XOAUTH2 tester@example\.com/.test(sl), 'XOAUTH2 with the mailbox: ' + sl.split('\n').join(' '));
		// the cached access token ran out → refreshed with the refresh token before the next login (here a test mail)
		sql("UPDATE zzzzsys_nunext_setting SET setting_value = JSON_SET(setting_value, '$.access_expires', 0) WHERE zzzzsys_nunext_setting_id = 'mail_oauth'");
		await open(p, 'index.php?p=email&tab=test');
		await p.fill('#t_to', 'someone@example.com');
		await Promise.all([p.waitForNavigation(), p.locator('#t_to').evaluate(i => i.form.requestSubmit())]); await p.waitForLoadState('networkidle');
		const ol = fs.readFileSync(olog, 'utf8');
		expect(/grant_type=authorization_code/.test(ol) && /grant_type=refresh_token/.test(ol), 'token requests: ' + ol.split('\n').join(' | '));
		sl = fs.readFileSync(slog, 'utf8');
		expect(/^DATA$/m.test(sl), 'test mail sent with OAuth 2: ' + sl.split('\n').join(' '));
		// an answer that does not belong to this session
		await open(p, 'oauth.php?code=code-1&state=foreign');
		expect(/p=email/.test(p.url()) && /\.alert-danger|toast/.test(await p.innerHTML('body')), 'foreign state refused');
		// disconnect
		await settings();
		await p.click('#nuMailOAuthDisconnect');
		const ok = p.locator('.modal.show .modal-footer .btn-danger').first();
		await ok.waitFor({ state: 'visible', timeout: 5000 });
		await Promise.all([p.waitForNavigation(), ok.click()]);
		await p.waitForLoadState('networkidle');
		expect(!/tester@/.test(await p.textContent('#nuMailOAuthState')), 'disconnected');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		smtp.kill(); prov.kill();
		sql('UPDATE zzzzsys_setup SET ' + cols.split(',').map((c, i) => c + '=' + (old[i] === '-' ? 'NULL' : "UNHEX('" + old[i] + "')")).join(','));
		sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('mail_auth_type', 'mail_oauth')");
		if (keepSet[0] !== '-') sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('mail_auth_type', UNHEX('" + keepSet[0] + "'))");
		if (keepSet[1] !== '-') sql("INSERT INTO zzzzsys_nunext_setting (zzzzsys_nunext_setting_id, setting_value) VALUES ('mail_oauth', UNHEX('" + keepSet[1] + "'))");
		for (const f of [slog, olog]) fs.rmSync(f, { force: true });
	}
};

// e-mail templates (0.8.432): code … Bcc fold away, the summary shows code · description · To, the state is remembered,
// an invalid field opens it again
T.emt_fold = async (s) => {
	const p = s.ga, id = sql("SELECT zzzzsys_email_template_id FROM zzzzsys_email_template WHERE COALESCE(emt_code,'') <> '' AND COALESCE(emt_template,'') <> '1' ORDER BY emt_code LIMIT 1");
	expect(id, 'a template');
	const url = 'index.php?p=emailtemplates&id=' + encodeURIComponent(id);
	await open(p, url);
	await p.evaluate(() => { try { localStorage.removeItem('nunext-emt-head-open'); } catch (e) { /* ignore */ } });
	await open(p, url);
	const isOpen = () => p.$eval('#emtHead', d => d.open);
	expect(await isOpen(), 'open at first');
	await p.click('#emtHead > summary'); await p.waitForTimeout(200);
	const info = await p.textContent('#emtHeadInfo');
	expect(!await isOpen() && info.includes(await p.inputValue('#emt_code')), 'folded, summary: ' + info);
	await open(p, url);
	expect(!await isOpen(), 'remembered');
	await p.evaluate(() => { document.getElementById('emt_code').value = ''; });
	await p.evaluate(() => document.getElementById('emt_code').form.checkValidity()); await p.waitForTimeout(200);
	expect(await isOpen(), 'an invalid field opens it');
	await p.evaluate(() => { try { localStorage.removeItem('nunext-emt-head-open'); } catch (e) { /* ignore */ } });
	expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
};

// 0.8.658: welcome e-mail as in nuBuilder Forte – nuBuilder Next's read-only template nu_send_welcome_email_template (group nunext, one per
// language) is used until a clone nu_send_welcome_email exists; Forte's shipped rows ("nu…": its example procedure, its template) are not
T.welcome_templates = async (s) => {
	const p = s.ga, port = 2600 + Math.floor(Math.random() * 300), slog = path.join(os.tmpdir(), 'e2e-welcome-' + Date.now() + '.log'), sdata = slog + '.eml';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-welcome.sql');
	if (sql("SELECT COUNT(*) FROM zzzzsys_email_template WHERE zzzzsys_email_template_id = 'nx_welcome_en'") !== '1') { s.skip = 'read-only templates missing'; return; }
	const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_eva'");
	if (!uid) { s.skip = 'no sample user'; return; }
	execSync('mysqldump ' + DB + ' zzzzsys_setup zzzzsys_email_template zzzzsys_email_log zzzzsys_nunext_token zzzzsys_user > ' + keep);
	const srv = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), slog, String(port), sdata], { stdio: 'ignore' });
	// the last message the SMTP server got – the e-mail log keeps no body of system mails (the link is secret): subject + decoded HTML
	const last = () => {
		const msgs = fs.existsSync(sdata) ? fs.readFileSync(sdata, 'utf8').split('=====END=====').filter(x => x.trim()) : [];
		if (!msgs.length) return { subject: '', body: '' };
		const out = execSync('python3 -I -c "import sys,email,email.policy,json;m=email.message_from_string(sys.stdin.read(),policy=email.policy.default);b=m.get_body((\'html\',\'plain\'));print(json.dumps({\'subject\':str(m[\'subject\']),\'body\':b.get_content() if b else \'\'}))"', { input: msgs[msgs.length - 1].replace(/^\s+/, '') }).toString();
		return JSON.parse(out);
	};
	const source = () => { const d = sql("SELECT details FROM zzzzsys_nunext_log WHERE event = 'welcome_sent' ORDER BY 1 DESC LIMIT 1"); return (d.match(/"source":"([a-z]+)"/) || [])[1] || d; };
	const sendWelcome = async () => {
		await open(p, 'index.php?p=user&id=' + uid);
		await p.check('#user_send_welcome_email');
		// the toast comes with the answer of the check request – wait for it, tick once more when it did not come
		let toast = await p.waitForSelector('#nuWelcomeToasts .toast-body', { timeout: 8000 }).then(e => e.textContent()).catch(() => '');
		if (!toast) {
			await p.uncheck('#user_send_welcome_email'); await p.waitForTimeout(300); await p.check('#user_send_welcome_email');
			toast = await p.waitForSelector('#nuWelcomeToasts .toast-body', { timeout: 8000 }).then(e => e.textContent()).catch(() => '(no toast)');
		}
		await Promise.all([p.waitForNavigation(), p.click('button.nuBtnSave[data-nu-save]')]); await p.waitForLoadState('networkidle');
		return toast;
	};
	try {
		await new Promise(r => setTimeout(r, 600));
		sql("UPDATE zzzzsys_setup SET set_smtp_host = '127.0.0.1', set_smtp_port = " + port + ", set_smtp_use_ssl = '0', set_smtp_use_authentication = '0', set_smtp_from_address = 'noreply@example.com'");
		sql("UPDATE zzzzsys_user SET sus_language = '' WHERE zzzzsys_user_id = '" + uid + "'");
		sql("DELETE FROM zzzzsys_email_template WHERE emt_code = 'nu_send_welcome_email'");
		let toast = await sendWelcome();
		expect(/nu_send_welcome_email_template/.test(toast), 'toast names the read-only template: ' + toast);
		let m = last();
		expect(m.subject === 'Your new account' && /p=reset/.test(m.body) && /Set password/.test(m.body) && !/#SET_PASSWORD_URL#|#sus_name#/.test(m.body), 'read-only template: ' + JSON.stringify(m).slice(0, 300));
		expect(source() === 'standard', 'source standard: ' + source());
		// a clone nu_send_welcome_email takes over (as nuBuilder Forte's clone of the template)
		sql("INSERT INTO zzzzsys_email_template (zzzzsys_email_template_id, emt_code, emt_group, emt_language, emt_template, emt_description, emt_subject, emt_body, emt_to) VALUES ('e2e_welcome_own', 'nu_send_welcome_email', 'nunext', '', '0', 'e2e', 'Own #sus_login_name#', 'Hi #sus_name#: #SET_PASSWORD_URL#', '#sus_email#')");
		toast = await sendWelcome();
		m = last();
		expect(/nu_send_welcome_email\b(?!_template)/.test(toast) && m.subject === 'Own sample_eva' && /p=reset/.test(m.body) && source() === 'template', 'own clone: ' + toast + ' ' + JSON.stringify(m).slice(0, 200));
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		srv.kill();
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

T.cron_run_procedure = async (s) => {
	// 0.8.1135: php nucron.php run <code> – a procedure started by the server's cron: user, hash values, log entry, exit code, e-mail on failure
	if (!SITE) { s.skip = 'needs SITE (nucron.php)'; return; }
	const p = s.ga, port = 2600 + Math.floor(Math.random() * 300), slog = path.join(os.tmpdir(), 'e2e-cron-' + Date.now() + '.log'), sdata = slog + '.eml';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-cron.sql'), out = path.join(os.tmpdir(), 'e2e-cron-out-' + Date.now() + '.txt');
	execSync('mysqldump ' + DB + ' zzzzsys_setup zzzzsys_email_log zzzzsys_php zzzzsys_access_php zzzzsys_nunext_setting > ' + keep);
	const srv = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), slog, String(port), sdata], { stdio: 'ignore' });
	const mails = () => (fs.existsSync(sdata) ? fs.readFileSync(sdata, 'utf8').split('=====END=====').filter(x => x.trim()) : []);
	const run = (args) => { const r = require('child_process').spawnSync('php', ['nucron.php', 'run'].concat(args), { cwd: SITE, encoding: 'utf8' }); return { code: r.status, out: r.stdout, err: r.stderr }; };
	const logRow = (ev) => sql(`SELECT CONCAT(login, '|', details) FROM zzzzsys_nunext_log WHERE event = '${ev}' AND record_id LIKE 'e2e_cron%' ORDER BY log_time DESC, zzzzsys_nunext_log_id DESC LIMIT 1`);
	try {
		const ins = (id, php, glob) => execSync('mysql ' + DB, { input: "INSERT INTO zzzzsys_php (zzzzsys_php_id, sph_code, sph_description, sph_run, sph_global, sph_system, sph_php) VALUES ('" + id + "', '" + id + "', 'e2e cron', 'hide', '" + glob + "', '0', " + JSON.stringify(php) + ")" });
		ins('e2e_cron_ok', "$h = nuHash(); file_put_contents(" + JSON.stringify(out).replace(/"/g, "'") + ", $h['USER_ID'] . '|' . ($h['E2E_DAY'] ?? '') . '|' . ($h['NU_CRON'] ?? '')); echo 'hello cron';", '0');
		ins('e2e_cron_bad', "echo 'before'; nuRunQuery('SELECT 1'); throw new Exception('e2e boom');", '1');
		sql("UPDATE zzzzsys_setup SET set_smtp_host = '127.0.0.1', set_smtp_port = " + port + ", set_smtp_use_ssl = '0', set_smtp_use_authentication = '0', set_smtp_from_address = 'noreply@example.com'");
		await p.waitForTimeout(600);
		// wrong calls
		let r = run([]); expect(r.code === 2 && /Usage:/.test(r.err), 'no code = exit 2: ' + JSON.stringify(r));
		r = run(['e2e_cron_ok', '--nope']); expect(r.code === 2, 'unknown option = exit 2');
		r = run(['e2e_cron_ok', '--mail=not an address']); expect(r.code === 2, 'bad --mail = exit 2');
		r = run(['e2e_cron_nope']); expect(r.code === 1 && /No procedure/.test(r.err), 'unknown procedure = exit 1: ' + JSON.stringify(r));
		// a good run as the globeadmin with a hash value
		r = run(['e2e_cron_ok', 'E2E_DAY=monday']);
		expect(r.code === 0 && /hello cron/.test(r.out) && /e2e_cron_ok done/.test(r.out), 'good run: ' + JSON.stringify(r));
		expect(fs.readFileSync(out, 'utf8') === 'globeadmin|monday|1', 'user, hash value, NU_CRON: ' + fs.readFileSync(out, 'utf8'));
		expect(/"code":"e2e_cron_ok"/.test(logRow('procedure_cron')), 'log entry of the good run: ' + logRow('procedure_cron'));
		r = run(['e2e_cron_ok', '--quiet']); expect(r.code === 0 && r.out === '', '--quiet prints nothing: ' + JSON.stringify(r.out));
		// as a user: no right on the procedure = refused; with the right = runs as that user
		const uid = sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'");
		if (uid) {
			r = run(['e2e_cron_ok', '--user=sample_anna']); expect(r.code === 1, 'a user without the right is refused: ' + JSON.stringify(r));
			sql("INSERT INTO zzzzsys_access_php (zzzzsys_access_php_id, slp_zzzzsys_access_id, slp_zzzzsys_php_id) SELECT 'e2e_cron_acc', sus_zzzzsys_access_id, 'e2e_cron_ok' FROM zzzzsys_user WHERE zzzzsys_user_id = '" + uid + "'");
			r = run(['e2e_cron_ok', '--user=sample_anna']); expect(r.code === 0 && fs.readFileSync(out, 'utf8').indexOf(uid + '|') === 0, 'runs as the user: ' + JSON.stringify(r) + ' ' + fs.readFileSync(out, 'utf8'));
			r = run(['e2e_cron_ok', '--user=e2e_nobody']); expect(r.code === 1 && /No user/.test(r.err), 'unknown user = exit 1');
		}
		// a failing run: exit 1, error on stderr, log entry; no address = no e-mail
		r = run(['e2e_cron_bad']);
		expect(r.code === 1 && /e2e boom/.test(r.err) && /before/.test(r.out) && !/E-mail sent/.test(r.err), 'failing run: ' + JSON.stringify(r));
		expect(/e2e boom/.test(logRow('procedure_cron_failed')), 'log entry of the failed run: ' + logRow('procedure_cron_failed'));
		expect(mails().length === 0, 'no address, no e-mail');
		// the address of Settings → Data & logs
		await open(p, 'index.php?p=settings&s=data'); await p.waitForSelector('#shCronMail');
		await p.fill('#shCronMail', 'not-an-address'); await Promise.all([p.waitForNavigation({waitUntil: 'load'}).catch(() => {}), p.click('#shDataSave')]);
		await p.waitForSelector('.alert-danger', {timeout: 8000}).catch(() => {});
		expect(await p.locator('.alert-danger').count() > 0 && sql("SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'cron_run'") === '0', 'an invalid address is not saved');
		await p.fill('#shCronMail', 'ops@example.com; second@example.com'); await Promise.all([p.waitForNavigation({waitUntil: 'load'}).catch(() => {}), p.click('#shDataSave')]);
		if (!(await p.locator('#shCronMail').count())) throw new Error('after save: ' + p.url() + ' ' + (await p.textContent('main')).replace(/\s+/g, ' ').slice(0, 300));
		expect(await p.inputValue('#shCronMail') === 'ops@example.com, second@example.com', 'addresses saved: ' + await p.inputValue('#shCronMail'));
		r = run(['e2e_cron_bad']);
		expect(r.code === 1 && /E-mail sent to ops@example.com, second@example.com/.test(r.err), 'e-mail to the setting: ' + JSON.stringify(r));
		let m = mails(); expect(m.length === 2 && /e2e_cron_bad/.test(m[0]) && /e2e boom/.test(m[1]), 'two e-mails naming procedure and error: ' + m.length);
		// --mail replaces it, --mail= sends none; a good run never mails
		r = run(['e2e_cron_bad', '--mail=other@example.com']); expect(/E-mail sent to other@example.com\n/.test(r.err) && mails().length === 3, '--mail replaces the setting: ' + r.err);
		r = run(['e2e_cron_bad', '--mail=']); expect(r.code === 1 && mails().length === 3, '--mail= sends none');
		r = run(['e2e_cron_ok']); expect(r.code === 0 && mails().length === 3, 'a good run sends nothing');
	} finally {
		srv.kill();
		for (const f of [out, slog, sdata]) try { fs.unlinkSync(f); } catch (e) { /* not written */ }
		sql("DELETE FROM zzzzsys_nunext_log WHERE record_id LIKE 'e2e_cron%' OR (event = 'cron_run_settings')");
		execSync('mysql ' + DB + ' < ' + keep);
	}
};

// 0.8.684: welcome e-mail preview – Save shows the e-mail first (nothing saved), Cancel keeps the form, another template, edits, the
// set-password marker must stay, "Save and send" saves the user and sends the edited e-mail with the real link
T.welcome_preview = async (s) => {
	const p = s.ga, port = 2600 + Math.floor(Math.random() * 300), slog = path.join(os.tmpdir(), 'e2e-wpv-' + Date.now() + '.log'), sdata = slog + '.eml';
	const keep = path.join(os.tmpdir(), 'nunext-e2e-wpv.sql');
	execSync('mysqldump ' + DB + ' zzzzsys_setup zzzzsys_email_log zzzzsys_nunext_token zzzzsys_user zzzzsys_user_permission > ' + keep);
	const srv = require('child_process').spawn('python3', [path.join(__dirname, 'fakesmtp.py'), slog, String(port), sdata], { stdio: 'ignore' });
	const last = () => {
		const msgs = fs.existsSync(sdata) ? fs.readFileSync(sdata, 'utf8').split('=====END=====').filter(x => x.trim()) : [];
		if (!msgs.length) return { subject: '', body: '' };
		return JSON.parse(execSync('python3 -I -c "import sys,email,email.policy,json;m=email.message_from_string(sys.stdin.read(),policy=email.policy.default);b=m.get_body((\'html\',\'plain\'));print(json.dumps({\'subject\':str(m[\'subject\']),\'body\':b.get_content() if b else \'\'}))"', { input: msgs[msgs.length - 1].replace(/^\s+/, '') }).toString());
	};
	const users = () => sql("SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name = 'e2e_wpv'");
	const dlgOpen = async () => { await p.waitForSelector('#userWelcomePvDlg.show'); await p.waitForFunction(() => { const f = document.getElementById('userWpvFrame'); return f.contentDocument && f.contentDocument.body && f.contentDocument.body.innerHTML.length > 50; }); };
	try {
		await new Promise(r => setTimeout(r, 600));
		sql("UPDATE zzzzsys_setup SET set_smtp_host = '127.0.0.1', set_smtp_port = " + port + ", set_smtp_use_ssl = '0', set_smtp_use_authentication = '0', set_smtp_from_address = 'noreply@example.com'");
		await open(p, 'index.php?p=user');
		await p.evaluate(() => localStorage.removeItem('nunext-welcome-preview'));
		await open(p, 'index.php?p=user');
		await p.fill('#sus_login_name', 'e2e_wpv'); await p.fill('#sus_name', 'Wendy Preview'); await p.fill('#sus_email', 'wendy@example.com');
		const acc = sql("SELECT zzzzsys_access_id FROM zzzzsys_access ORDER BY zzzzsys_access_id LIMIT 1");
		await p.evaluate((a) => { const x = document.getElementById('sus_zzzzsys_access_id'); x.value = a; if (x.tomselect) x.tomselect.setValue(a); x.dispatchEvent(new Event('change', { bubbles: true })); }, acc);
		expect(await p.isHidden('#userWelcomePvWrap'), 'preview switch before the welcome switch');
		await p.check('#user_send_welcome_email');
		await p.evaluate(() => { const t = document.getElementById('nuWelcomeToasts'); if (t) t.remove(); });   // the toast of the switch may cover the next one
		expect(await p.isVisible('#userWelcomePvWrap') && !(await p.isChecked('#user_welcome_preview')), 'preview switch default off');
		await p.check('#user_welcome_preview');
		await p.click('button.nuBtnSave[data-nu-save]');
		await dlgOpen();
		expect(users() === '0', 'user saved before Send');
		expect(await p.inputValue('#userWpvTo') === 'wendy@example.com' && (await p.inputValue('#userWpvSubject')).length > 0, 'recipient / subject');
		expect(await p.evaluate(() => /#SET_PASSWORD_URL#/.test(document.getElementById('userWpvFrame').contentDocument.body.innerHTML) && /Wendy Preview/.test(document.getElementById('userWpvFrame').contentDocument.body.innerHTML)), 'marker / name in the e-mail');
		expect(await p.evaluate(() => document.getElementById('userWpvFrame').contentDocument.designMode === 'on'), 'not editable');
		// Cancel: back to the form, nothing saved, values kept
		await p.click('#userWpvCancel'); await p.waitForSelector('#userWelcomePvDlg', { state: 'hidden' });
		expect(users() === '0' && await p.inputValue('#sus_login_name') === 'e2e_wpv', 'Cancel');
		// again: the built-in text, the marker removed in HTML → refused, then the template again
		await p.click('button.nuBtnSave[data-nu-save]'); await dlgOpen();
		const n = await p.locator('#userWpvTpl option').count();
		expect(n >= 3, 'templates: ' + n);
		await p.selectOption('#userWpvTpl', 'builtin'); await p.waitForTimeout(800); await dlgOpen();
		await p.click('label[for="userWpvModeHtml"]');
		await p.evaluate(() => { const t = document.getElementById('userWpvSource'); t.value = t.value.split('#SET_PASSWORD_URL#').join('#'); t.dispatchEvent(new Event('input')); });
		await p.click('#userWpvSend');
		expect(/SET_PASSWORD_URL/.test(await p.textContent('#userWpvErr')) && users() === '0', 'marker removed → no refusal');
		await p.selectOption('#userWpvTpl', 'auto'); await p.waitForTimeout(800); await dlgOpen();
		await p.fill('#userWpvSubject', 'Hello Wendy E2E');
		await p.evaluate(() => { const b = document.getElementById('userWpvFrame').contentDocument.body; const x = b.ownerDocument.createElement('p'); x.textContent = 'E2E-EDITED-LINE'; b.appendChild(x); });
		await Promise.all([p.waitForNavigation(), p.click('#userWpvSend')]); await p.waitForLoadState('networkidle');
		expect(users() === '1', 'user not saved');
		await new Promise(r => setTimeout(r, 500));
		const m = last();
		expect(m.subject === 'Hello Wendy E2E' && /E2E-EDITED-LINE/.test(m.body) && /p=reset/.test(m.body) && !/#SET_PASSWORD_URL#/.test(m.body), 'sent mail: ' + JSON.stringify(m).slice(0, 300));
		expect(await p.evaluate(() => localStorage.getItem('nunext-welcome-preview')) === '1', 'switch remembered');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		srv.kill();
		execSync('mysql ' + DB + ' < ' + keep);
		try { await p.evaluate(() => localStorage.removeItem('nunext-welcome-preview')); } catch (e) { /* page gone */ }
	}
};

T.notifications = async (s) => {
	const p = s.ga;
	await open(p, 'index.php?p=notifications&id=new');
	await p.fill('#ntf_title', 'E2E notification');
	await p.click('.ntfAddRule[data-rule=users]');
	await p.locator('#aud_users').locator('xpath=..').locator('.ts-control input').fill('sample_anna');
	await p.locator('.ts-dropdown .option[data-value="sample_anna"]').click(); await p.keyboard.press('Escape');
	await p.waitForTimeout(600);
	expect(/1/.test(await p.textContent('#ntfCount')), 'recipient count wrong');
	await p.click('#ntfPublish'); await p.waitForLoadState('networkidle');
	const a = await login('sample_anna', UPW);
	await a.waitForTimeout(800);
	expect(!(await a.locator('.nuBellBadge').isHidden()), 'no red badge for the recipient');
	await a.click('#nuBellBtn'); await a.waitForTimeout(700);
	expect(await a.locator('.nuBellItem', { hasText: 'E2E notification' }).count() === 1, 'not in the panel');
	await a.locator('.nuBellAll').click(); await a.waitForTimeout(700);
	expect(await a.locator('.nuBellBadge').isHidden(), 'badge still shown after "mark all as read"');
	await a.context().close();
	const b = await login('sample_ben', UPW);
	await open(b, 'index.php?p=notifications');
	expect(await b.locator('.nuNtfItem', { hasText: 'E2E notification' }).count() === 0, 'other user got it');
	await b.context().close();
	// 0.8.402: Delete on the edit page (the list's ⋯ column was off-screen on phones – it stays visible now)
	const id = sql("SELECT zzzzsys_nunext_notification_id FROM zzzzsys_nunext_notification WHERE title = 'E2E notification'");
	await open(p, 'index.php?p=notifications&id=' + id);
	await p.click('#ntfDelete'); await p.waitForTimeout(500);
	await p.click('.js-confirm-ok'); await p.waitForLoadState('networkidle');
	expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_notification WHERE zzzzsys_nunext_notification_id = '" + id + "'") === '0', 'not deleted from the edit page');
};

// 0.8.510: "Exclude users" (also with Everybody) and the recipients as a sortable table in a dialogue
T.notify_exclude = async (s) => {
	const p = s.ga;
	try {
		await open(p, 'index.php?p=notifications&id=new');
		await p.fill('#ntf_title', 'E2E exclude');
		await p.locator('label[for=ntf_all]').click(); await p.waitForTimeout(600);
		const total = +(await p.textContent('#ntfCount')).replace(/\D+/g, '');
		await p.click('.ntfAddRule[data-rule=exclude]');
		const exWrap = p.locator('#aud_exclude').locator('xpath=..');
		expect(!(await exWrap.locator('.ts-wrapper').getAttribute('class')).includes('disabled'), 'Exclude users locked by Everybody');
		// the option comes after typing (loaded or filtered): wait for it, type again once when it does not show
		const annaOpt = p.locator('.ts-dropdown .option[data-value="' + sql("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_name = 'sample_anna'") + '"]');
		await exWrap.locator('.ts-control input').fill('sample_anna');
		try { await annaOpt.waitFor({ state: 'visible', timeout: 8000 }); } catch (e) {
			await exWrap.locator('.ts-control input').fill(''); await exWrap.locator('.ts-control input').fill('sample_anna');
			await annaOpt.waitFor({ state: 'visible', timeout: 15000 }).catch(() => { throw new Error('exclude: option sample_anna not shown'); });
		}
		await annaOpt.click(); await p.keyboard.press('Escape');
		await p.waitForTimeout(700);
		expect(+(await p.textContent('#ntfCount')).replace(/\D+/g, '') === total - 1, 'count did not drop by one: ' + await p.textContent('#ntfCount'));
		await p.click('#ntfCountShow', { timeout: 15000 }).catch(() => { throw new Error('people list: #ntfCountShow not clickable'); });
		await p.waitForSelector('#ntfPeopleDlg.show');
		const col = (i) => p.$$eval('#ntfPeopleTable tbody tr', (trs, i) => trs.map(t => t.children[i].textContent), i);
		const logins = await col(1);
		expect(logins.length === total - 1 && !logins.includes('sample_anna') && logins.includes('sample_ben'), 'table rows: ' + logins.join(','));
		const names = await col(0);
		expect(names.some(n => / /.test(n)), 'no full names in the table: ' + names.slice(0, 3).join(','));
		await p.click('#ntfPeopleTable .nuNtfSort[data-col="1"]');
		const asc = await col(1), sorted = asc.slice().sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
		expect(JSON.stringify(asc) === JSON.stringify(sorted) && await p.getAttribute('#ntfPeopleTable th:nth-child(2)', 'aria-sort') === 'ascending', 'login sort asc');
		await p.click('#ntfPeopleTable .nuNtfSort[data-col="1"]');
		expect(JSON.stringify(await col(1)) === JSON.stringify(sorted.slice().reverse()), 'login sort desc');
		await p.keyboard.press('Escape'); await p.waitForTimeout(400);
		await p.locator('#ntfPublish:visible, #ntfPublishSide:visible').first().click({ timeout: 15000 }).catch(() => { throw new Error('publish: no visible Publish button'); });
		await p.waitForLoadState('networkidle');
		const aud = JSON.parse(sql("SELECT audience FROM zzzzsys_nunext_notification WHERE title = 'E2E exclude'"));
		expect(aud.all === true && aud.exclude.length === 1, 'stored audience: ' + JSON.stringify(aud));
		const a = await login('sample_anna', UPW);
		await open(a, 'index.php?p=notifications');
		expect(await a.locator('.nuNtfItem', { hasText: 'E2E exclude' }).count() === 0, 'excluded user got it');
		await a.context().close();
		const b = await login('sample_ben', UPW);
		await open(b, 'index.php?p=notifications');
		expect(await b.locator('.nuNtfItem', { hasText: 'E2E exclude' }).count() === 1, 'other user did not get it');
		await b.context().close();
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		sql("DELETE u FROM zzzzsys_nunext_notification_user u JOIN zzzzsys_nunext_notification n ON n.zzzzsys_nunext_notification_id = u.notification_id WHERE n.title = 'E2E exclude'");
		sql("DELETE FROM zzzzsys_nunext_notification WHERE title = 'E2E exclude'");
	}
};

T.notify_format = async (s) => {                        // 0.8.400: "Formatted text" switch – TinyMCE (when installed), HTML cleaned on the server
	const p = s.ga;
	await open(p, 'index.php?p=notifications&id=new');
	await p.fill('#ntf_title', 'E2E formatted');
	await p.fill('#ntf_body', 'Line one\nLine <two>');
	await p.click('.ntfAddRule[data-rule=users]');
	await p.locator('#aud_users').locator('xpath=..').locator('.ts-control input').fill('sample_anna');
	await p.locator('.ts-dropdown .option[data-value="sample_anna"]').click(); await p.keyboard.press('Escape');
	await p.check('#ntf_format');
	const tiny = !!(await p.getAttribute('#ntf_format', 'data-tiny'));
	const bad = '<p>Hello <strong>bold</strong> <a href="javascript:alert(1)">x</a> <a href="https://example.com">ok</a></p><script>window.pwned=1</script><img src=x onerror="window.pwned=1">';
	if (tiny) {
		await p.waitForSelector('.tox-tinymce', { timeout: 10000 });
		expect(/Line one<br>Line &lt;two&gt;/.test(await p.evaluate(() => window.tinymce.activeEditor.getContent())), 'plain text not taken over into the editor');
		await p.evaluate((h) => { const ed = window.tinymce.activeEditor; ed.setContent(h); ed.fire('change'); }, bad);
	} else {
		await p.evaluate((h) => { document.getElementById('ntf_body').value = h; }, bad);
	}
	await p.click('#ntfPublish'); await p.waitForLoadState('networkidle');
	const row = sql("SELECT CONCAT(format, '|', body) FROM zzzzsys_nunext_notification WHERE title = 'E2E formatted'");
	expect(row.startsWith('html|') && /<strong>bold<\/strong>/.test(row) && !/script|onerror|javascript|<img/i.test(row) && /href="https:\/\/example.com"/.test(row), 'stored HTML not cleaned: ' + row);
	// the server cleans HTML that does not come from the editor (posted directly)
	const a = await login('sample_anna', UPW);
	await open(a, 'index.php?p=notifications');
	const item = a.locator('.nuNtfItem', { hasText: 'E2E formatted' });
	expect(await item.locator('strong', { hasText: 'bold' }).count() === 1 && await item.locator('a[href="https://example.com"][target="_blank"]').count() === 1, 'formatting not shown to the recipient');
	expect(!(await a.evaluate(() => window.pwned)), 'script ran');
	await a.click('#nuBellBtn'); await a.waitForTimeout(700);
	expect(await a.locator('.nuBellItem strong', { hasText: 'bold' }).count() >= 1, 'formatting not in the bell');
	await a.context().close();
	// back to plain text: after the question the tags are gone
	const id = sql("SELECT zzzzsys_nunext_notification_id FROM zzzzsys_nunext_notification WHERE title = 'E2E formatted'");
	await open(p, 'index.php?p=notifications&id=' + id);
	expect(await p.isChecked('#ntf_format'), 'switch not on for a formatted notification');
	if (tiny) await p.waitForSelector('.tox-tinymce', { timeout: 10000 });
	p.once('dialog', (d) => d.accept().catch(() => {}));
	await p.uncheck('#ntf_format'); await p.waitForTimeout(400);
	const txt = await p.inputValue('#ntf_body');
	expect(/Hello bold/.test(txt) && !/</.test(txt) && await p.locator('.tox-tinymce').count() === 0, 'plain text after switching off: ' + txt);
	sql("DELETE FROM zzzzsys_nunext_notification_user WHERE notification_id = '" + id + "'");
	sql("DELETE FROM zzzzsys_nunext_notification WHERE zzzzsys_nunext_notification_id = '" + id + "'");
};

// webhooks (0.8.715): a call after save / delete, signed (HMAC-SHA256 over "<timestamp>.<body>"), retries by the housekeeping (nucron.php),
// a refusal (4xx) not repeated, template, private addresses blocked unless allowed, Send test, Send again, delete with its calls
T.webhooks = async (s) => {
	const p = s.ga;
	if (!SITE) { s.skip = 'needs SITE (nucron.php)'; return; }
	const crypto = require('crypto');
	const port = 8766, log = path.join(os.tmpdir(), 'e2e-hook-' + Date.now() + '.log');
	const srv = require('child_process').spawn('python3', ['-I', path.join(__dirname, 'fakehook.py'), String(port), log], { stdio: 'ignore' });
	const cust = sql('SELECT sample_customer_id FROM sample_customer ORDER BY cus_code LIMIT 1');
	if (!cust) { srv.kill(); s.skip = 'no sample customer'; return; }
	const keepName = sql(`SELECT cus_name FROM sample_customer WHERE sample_customer_id = '${cust}'`);
	const clean = () => { sql('DELETE FROM zzzzsys_nunext_webhook_try'); sql('DELETE FROM zzzzsys_nunext_webhook_call'); sql('DELETE FROM zzzzsys_nunext_webhook'); sql("DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = 'webhook'"); };
	const lines = () => fs.existsSync(log) ? fs.readFileSync(log, 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)) : [];
	const waitLines = async (n) => { for (let i = 0; i < 40 && lines().length < n; i++) await p.waitForTimeout(150); return lines(); };
	const setUrl = async (url, extra) => {
		await p.fill('#whUrl', url);
		if (extra) await extra();
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
	};
	const saveCustomer = async (name) => { await open(p, 'index.php?p=form&f=samplecustomer&r=' + cust); await setField(p, '#cus_name', name); await save(p); };
	const lastCall = () => sql("SELECT CONCAT(status, '|', attempts) FROM zzzzsys_nunext_webhook_call ORDER BY created_at DESC, attempts DESC LIMIT 1");
	clean();
	try {
		await p.waitForTimeout(500);
		// private address refused while the setting is off
		await open(p, 'index.php?p=webhooks&id=new');
		await p.fill('#whName', 'e2e hook');
		await p.evaluate(() => document.getElementById('whForms').tomselect.addItem('samplecustomer'));
		await p.check('#whEv_delete');
		await setUrl(`http://127.0.0.1:${port}/ok`);
		expect(await p.locator('#whUrl.is-invalid').count() === 1 && +sql('SELECT COUNT(*) FROM zzzzsys_nunext_webhook') === 0, 'http / private address not refused');
		// allow it (list page → Settings), then create
		await open(p, 'index.php?p=webhooks');
		await Promise.all([p.waitForNavigation(), p.check('#whAllowPrivate')]);
		await open(p, 'index.php?p=webhooks&id=new');
		await p.fill('#whName', 'e2e hook');
		await p.evaluate(() => document.getElementById('whForms').tomselect.addItem('samplecustomer'));
		await p.check('#whEv_delete');
		await setUrl(`http://127.0.0.1:${port}/ok`);
		const secret = await p.inputValue('#whSecret');
		expect(secret.length === 48, 'secret not shown once: ' + secret);
		const hid = new URL(p.url()).searchParams.get('id');
		expect(/^enc:|^plain:/.test(sql(`SELECT secret FROM zzzzsys_nunext_webhook WHERE zzzzsys_nunext_webhook_id = '${hid}'`)), 'secret stored as it is');
		await open(p, 'index.php?p=webhooks&id=' + hid);
		expect(await p.locator('#whSecret').count() === 0, 'secret shown again');
		// a save → one signed call
		await saveCustomer('E2E hook one');
		let l = await waitLines(1);
		expect(l.length === 1 && l[0].path === '/ok', 'no call after save: ' + JSON.stringify(l));
		const sig = 'sha256=' + crypto.createHmac('sha256', secret).update(l[0].headers['x-nunext-timestamp'] + '.' + l[0].body).digest('hex');
		expect(l[0].headers['x-nunext-signature'] === sig && l[0].headers['x-nunext-event'] === 'update', 'signature / event header: ' + JSON.stringify(l[0].headers));
		const b = JSON.parse(l[0].body);
		expect(b.event === 'record.updated' && b.form.code && b.record_id === cust && b.data.cus_name === 'E2E hook one' && b.changed.cus_name && b.changed.cus_name.new === 'E2E hook one', 'payload: ' + l[0].body.slice(0, 300));
		expect(lastCall() === 'sent|1', 'call not sent: ' + lastCall());
		// flaky receiver: 500 → waiting, retried by nucron.php until sent
		await open(p, 'index.php?p=webhooks&id=' + hid);
		await setUrl(`http://127.0.0.1:${port}/flaky`);
		await saveCustomer('E2E hook two');
		await waitLines(2);
		expect(lastCall() === 'pending|1', 'after a 500: ' + lastCall());
		for (const want of ['pending|2', 'sent|3']) {
			sql("UPDATE zzzzsys_nunext_webhook_call SET next_at = '2000-01-01 00:00:00' WHERE status = 'pending'");
			execSync('php nucron.php', { cwd: SITE, stdio: 'pipe' });
			expect(lastCall() === want, 'retry: ' + lastCall() + ' (want ' + want + ')');
		}
		expect(+sql('SELECT COUNT(*) FROM zzzzsys_nunext_webhook_try t JOIN zzzzsys_nunext_webhook_call c ON c.zzzzsys_nunext_webhook_call_id = t.call_id WHERE c.status = \'sent\' AND c.attempts = 3') === 3, 'attempts not logged');
		// 403: failed at once (no retry)
		await open(p, 'index.php?p=webhooks&id=' + hid);
		await setUrl(`http://127.0.0.1:${port}/deny`);
		await saveCustomer('E2E hook three');
		await waitLines(5);
		expect(lastCall() === 'failed|1', 'refusal repeated: ' + lastCall());
		// call page: Send again (receiver still refuses → failed again, 2 attempts on the page)
		const cid = sql("SELECT zzzzsys_nunext_webhook_call_id FROM zzzzsys_nunext_webhook_call WHERE status = 'failed' LIMIT 1");
		await open(p, 'index.php?p=webhooks&call=' + cid);
		expect(/record.updated/.test(await p.textContent('#whBody')), 'call body');
		await Promise.all([p.waitForNavigation(), p.click('#whResend')]);
		expect(await p.locator('#whTries tbody tr').count() === 2 && /403/.test(await p.textContent('#whTries')), 'Send again');
		// template + Send test
		await open(p, 'index.php?p=webhooks&id=' + hid);
		await p.fill('#whUrl', `http://127.0.0.1:${port}/ok`);
		await p.fill('#whTemplate', '{"text": "#FORM_CODE#: #cus_name# (#EVENT#)"}');
		await Promise.all([p.waitForNavigation(), p.click('#whTest')]);
		expect(await p.locator('#whTestResult.alert-success').count() === 1, 'Send test: ' + await p.locator('#whTestResult').textContent().catch(() => ''));
		l = lines();
		expect(/^\{"text": "[^"]+: .+ \(record\.(updated|inserted)\)"\}$/.test(l[l.length - 1].body), 'template body: ' + l[l.length - 1].body);
		await p.fill('#whTemplate', '{"text": #cus_name#}');
		await Promise.all([p.waitForNavigation(), p.click('button[data-nu-save]')]);
		expect(await p.locator('#whTemplate.is-invalid').count() === 1, 'invalid template accepted');
		// lists: webhooks and calls
		await open(p, 'index.php?p=webhooks');
		expect(/e2e hook/.test(await p.textContent('.nuListCard')), 'webhooks list');
		await open(p, 'index.php?p=webhooks&tab=calls&status=failed');
		expect(await p.locator('.nuListCard tbody tr').count() >= 1, 'calls list (failed)');
		// delete: calls go with it
		await open(p, 'index.php?p=webhooks&id=' + hid);
		await p.click('#whDelete'); await p.click('#nuConfirm.show .js-confirm-ok'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(400);
		expect(+sql('SELECT COUNT(*) FROM zzzzsys_nunext_webhook') === 0 && +sql('SELECT COUNT(*) FROM zzzzsys_nunext_webhook_call') === 0, 'not deleted with its calls');
		expect(p.errors.length === 0, p.errors.slice(0, 2).join(' | '));
	} finally {
		srv.kill();
		fs.rmSync(log, { force: true });
		sql(`UPDATE sample_customer SET cus_name = ${keepName === '' ? 'NULL' : "'" + keepName.replace(/'/g, "''") + "'"} WHERE sample_customer_id = '${cust}'`);
		clean();
	}
};

// notifications → Manage: the Settings button shows only its icon on phones (0.8.477)
T.ntf_settings_phone = async (s) => {
	const ctx = await browser.newContext({ locale: 'en-GB', viewport: { width: 390, height: 800 }, storageState: await s.ga.context().storageState() });
	const p = await ctx.newPage();
	try {
		await p.goto(BASE + 'index.php?p=notifications&tab=manage'); await p.waitForTimeout(400);
		// 0.8.1068: on phones the button moves into ⋮ (an entry with its name)
		const r = await p.evaluate(() => { const b = document.querySelector('#ntfSettingsBtn'); if (!b) return null; const vis = b.offsetParent !== null;
			const it = [...document.querySelectorAll('.nuPhMoreItem')].find((a) => /Settings/.test(a.textContent));
			return { vis: vis, txt: vis ? b.innerText.trim() : '', aria: b.getAttribute('aria-label'), item: !!it }; });
		expect(r && (r.vis ? r.txt === '' && r.aria : r.item), 'phone: Settings shows text or has no name: ' + JSON.stringify(r));
		await p.setViewportSize({ width: 1200, height: 800 });
		expect((await p.innerText('#ntfSettingsBtn')).trim() !== '', 'desktop: Settings has no text');
	} finally { await ctx.close(); }
};

T.notify_editor_layout = async (s) => {               // 0.8.1271: notification editor – sections, recipient rows, tiles, sticky bar with summary
	const p = s.ga;
	await open(p, 'index.php?p=notifications&id=new');
	expect(await p.locator('.ntfSec').count() === 3, 'three sections expected');
	const vis = (sel) => p.locator(sel).isVisible();
	expect(!(await vis('.ntfRule[data-rule=users]')) && !(await vis('.ntfRule[data-rule=teams]')), 'empty rule rows should be hidden');
	expect(await vis('#ntfRuleNone'), 'hint for no recipients missing');
	await p.click('.ntfAddRule[data-rule=users]');
	expect(await vis('.ntfRule[data-rule=users]') && !(await vis('.ntfAddRule[data-rule=users]')), '+ Users did not show the row');
	expect(!(await vis('#ntfRuleNone')), 'hint should hide with a rule row');
	await p.locator('#aud_users').locator('xpath=..').locator('.ts-control input').fill('sample_anna');
	await p.locator('.ts-dropdown .option[data-value="sample_anna"]').click(); await p.keyboard.press('Escape');
	await p.waitForTimeout(700);
	expect(/^1\D/.test((await p.textContent('#ntfSum')).trim()), 'summary without the count: ' + await p.textContent('#ntfSum'));
	await p.locator('label.ntfOpt:has(#ntf_confirm)').click();
	expect(await p.isChecked('#ntf_confirm'), 'option tile did not tick');
	expect((await p.textContent('#ntfSum')).includes(await p.locator('label.ntfOpt:has(#ntf_confirm) b').textContent()), 'summary misses Must confirm');
	await p.click('label[for=ntf_lv_warning]');
	expect(await p.isChecked('#ntf_lv_warning'), 'level tile did not select');
	await p.click('.ntfRuleX[data-rule-x=users]'); await p.waitForTimeout(600);
	expect(!(await vis('.ntfRule[data-rule=users]')) && await vis('.ntfAddRule[data-rule=users]'), '× did not hide the row');
	expect(await p.evaluate(() => document.getElementById('aud_users').selectedOptions.length) === 0, '× did not clear the field');
	await p.locator('label[for=ntf_all]').click(); await p.waitForTimeout(200);
	expect(!(await vis('.ntfAddRule[data-rule=teams]')) && await vis('.ntfAddRule[data-rule=exclude]'), 'Everybody should leave only Exclude to add');
	const pos = await p.evaluate(() => getComputedStyle(document.getElementById('ntfBar')).position);
	expect(pos === 'sticky', 'bar not sticky: ' + pos);
	await p.setViewportSize({width: 390, height: 844});
	await p.waitForTimeout(300);
	const r = await p.evaluate(() => { const b = document.getElementById('ntfBar').getBoundingClientRect(); return {bottom: Math.round(b.bottom), sw: document.documentElement.scrollWidth}; });
	expect(r.bottom <= 844 && r.bottom > 700, 'bar not at the bottom on phones: ' + r.bottom);
	expect(r.sw <= 390, 'page wider than the phone: ' + r.sw);
	await p.setViewportSize({width: 1400, height: 1000});
	await p.evaluate(() => { const f = document.getElementById('ntfForm'); if (f) f.removeAttribute('data-nu-guard'); });
};

T.notify_wide = async (s) => {                        // 0.8.1298: notification editor on wide screens – two columns + preview / summary / buttons
	const p = s.ga;
	await p.setViewportSize({width: 1600, height: 1000});
	try {
		await open(p, 'index.php?p=notifications&id=new');
		const vis = (sel) => p.locator(sel).isVisible();
		expect(await vis('#ntfSide') && !(await vis('#ntfBar')), 'side column not shown / bottom bar not hidden at 1600 px');
		const g = await p.evaluate(() => ['ntfSecMsg', 'ntfSecAud', 'ntfSecDel', 'ntfSide'].map((id) => { const e = document.getElementById(id); const b = (e.closest('.ntfSec') || e).getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top)]; }));
		expect(g[0][0] < g[1][0] && g[1][0] < g[3][0] && g[1][0] === g[2][0] && g[2][1] > g[1][1], 'layout: ' + JSON.stringify(g));
		await p.fill('#ntf_title', 'E2E wide preview'); await p.fill('#ntf_body', 'Body of the preview');
		await p.click('label[for=ntf_lv_warning]');
		await p.click('.ntfAddRule[data-rule=users]');
		await p.locator('#aud_users').locator('xpath=..').locator('.ts-control input').fill('sample_anna');
		await p.locator('.ts-dropdown .option[data-value="sample_anna"]').click(); await p.keyboard.press('Escape');
		await p.waitForTimeout(900);
		expect(await p.textContent('#ntfPvTitle') === 'E2E wide preview' && await p.textContent('#ntfPvBody') === 'Body of the preview', 'preview does not follow the fields');
		expect(await p.locator('#ntfPv.ntfPv-warning').count() === 1, 'preview level');
		expect(/^1\D/.test(await p.textContent('#ntfSsN')) && (await p.textContent('#ntfSsNames')).trim() !== '', 'summary: ' + await p.textContent('#ntfSsN') + ' / ' + await p.textContent('#ntfSsNames'));
		await p.locator('label.ntfOpt:has(#ntf_confirm)').click();
		expect(await p.textContent('#ntfSsConfirm') === await p.evaluate(() => document.getElementById('ntfSide').getAttribute('data-yes')), 'summary: must confirm');
		await Promise.all([p.waitForNavigation(), p.click('#ntfDraftSide')]);
		expect(sql("SELECT COUNT(*) FROM zzzzsys_nunext_notification WHERE title = 'E2E wide preview' AND status = 'draft'") === '1', 'side button did not save a draft');
		await p.setViewportSize({width: 1300, height: 900});
		await open(p, 'index.php?p=notifications&id=new');
		expect(!(await vis('#ntfSide')) && await vis('#ntfBar'), 'below 1400 px: bar expected, no side column');
		expect(p.errors.length === 0, p.errors.join(' | '));
	} finally {
		sql("DELETE nu FROM zzzzsys_nunext_notification_user nu JOIN zzzzsys_nunext_notification n ON n.zzzzsys_nunext_notification_id = nu.notification_id WHERE n.title = 'E2E wide preview'");
		sql("DELETE FROM zzzzsys_nunext_notification WHERE title = 'E2E wide preview'");
		await p.setViewportSize({width: 1300, height: 900});
	}
};

T.notify_colours = async (s) => {
	// 0.8.1321: notification editor colours "Indigo & slate" – the chosen Warning tile is orange (not yellow), Publish indigo,
	// the reach box a pill (ntfReachNone / ntfReachSome instead of Bootstrap alerts)
	const p = s.ga;
	await open(p, 'index.php?p=notifications&id=new');
	await p.click('label[for=ntf_lv_warning]');
	await p.waitForFunction(() => /ntfReach(None|Some)/.test(document.getElementById('ntfCount').className), null, { timeout: 8000 });
	const r = await p.evaluate(() => { const cs = (sel, k) => getComputedStyle(document.querySelector(sel))[k];
		return { lvBorder: cs('label[for=ntf_lv_warning]', 'borderTopColor'), lvBg: cs('label[for=ntf_lv_warning]', 'backgroundColor'),
			pub: cs('#ntfPublish', 'backgroundColor'), reach: document.getElementById('ntfCount').className, alert: document.getElementById('ntfCount').classList.contains('alert') }; });
	expect(r.lvBorder === 'rgb(194, 65, 12)' && r.lvBg === 'rgb(255, 244, 236)', 'warning tile: ' + JSON.stringify(r));
	expect(r.pub === 'rgb(79, 70, 229)' && !r.alert && /ntfReach /.test(r.reach + ' '), 'publish / reach: ' + JSON.stringify(r));
	s.result = r;
};

T.mail_check_scroll_phone = async (s) => {
	// phones: after Check connection the page comes back with the result in sight (it sits below the test mail form)
	const ga = s.ga, port = 2900 + Math.floor(Math.random() * 90);
	const cols = 'set_smtp_host,set_smtp_port,set_smtp_use_ssl';
	const old = sql('SELECT CONCAT_WS(\',\',' + cols.split(',').map(c => "IFNULL(HEX(CAST(" + c + " AS CHAR)),'-')").join(',') + ') FROM zzzzsys_setup LIMIT 1').split(',');
	const ctx = await ga.context().browser().newContext({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true, storageState: await ga.context().storageState() });
	try {
		sql("UPDATE zzzzsys_setup SET set_smtp_host='127.0.0.1', set_smtp_port=" + port + ", set_smtp_use_ssl='0'");   // nothing listens: the check fails fast
		const p = await ctx.newPage();
		await p.goto(BASE + 'index.php?p=email&tab=test');
		await Promise.all([p.waitForNavigation(), p.click('#nuMailCheckBtn')]);
		await p.waitForSelector('#mailCheckResult'); await p.waitForTimeout(400);
		const r = await p.evaluate(() => { const b = document.getElementById('mailCheckResult').getBoundingClientRect(); return { top: Math.round(b.top), y: Math.round(scrollY), h: innerHeight }; });
		expect(r.y > 0 && r.top >= 0 && r.top + r.y > r.h && r.top < r.h - 100, 'result not in sight: ' + JSON.stringify(r));
	} finally {
		await ctx.close();
		sql('UPDATE zzzzsys_setup SET ' + cols.split(',').map((c, i) => c + '=' + (old[i] === '-' ? 'NULL' : "UNHEX('" + old[i] + "')")).join(','));
	}
};

T.emt_preview_frame = async (s) => {
	// e-mail template preview: also for read-only templates (their fields sit in a disabled fieldset), shown with the mail's inline styles
	const p = s.ga, csp = [];
	const onMsg = m => { if (/Content Security Policy/.test(m.text())) csp.push(m.text().slice(0, 80)); };
	p.on('console', onMsg);
	try {
		await open(p, 'index.php?p=emailtemplates&id=nx_pwreset_en');
		await p.click('#emtPreviewBtn'); await p.waitForTimeout(1200);
		const f = p.frames().find(x => /[?&]frame=/.test(x.url()));
		const r = f ? await f.evaluate(() => { const a = document.querySelector('a'); return { txt: document.body.innerText.slice(0, 40), bg: a ? getComputedStyle(a).backgroundColor : '' }; }) : null;
		expect(r && /\S/.test(r.txt), 'preview empty: ' + JSON.stringify(r));
		expect(r && r.bg === 'rgb(47, 111, 214)', 'inline style of the button not applied: ' + JSON.stringify(r));
		const head = await p.$eval('#emtPreviewHead', e => e.innerText);
		expect(/\S/.test(head), 'subject / To missing');
		expect(csp.length === 0, 'CSP: ' + csp.join(' | '));
	} finally { p.off('console', onMsg); }
};
