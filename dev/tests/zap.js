/*
 * nuBuilder Next – OWASP ZAP scan of a running test installation (0.8.883): a baseline (spider + passive rules) of the public pages,
 * then, signed in as the globeadmin, spider + passive rules + a time-capped active scan of the admin pages and forms.
 *     NODE_PATH=$(npm root -g) BASE=http://127.0.0.1:8099/nunext/ DB="-unu -pnu nutest" node dev/tests/zap.js
 * Env: BASE, DB (as e2e.js – the whole database is saved first and put back afterwards: the active scan submits every form),
 *      ADMIN_USER / ADMIN_PW (globeadmin / nu), ZAP (zap.sh; default ../zap/zap.sh or ../zap/ZAP_<version>/zap.sh next to the project, else
 *      zap.sh on the PATH – without ZAP the suite is skipped), ZAP_MINS (cap of the active scan, default 15; 0 = passive only),
 *      ZAP_SPIDER_MINS (default 5), ZAP_OUT (report folder, default <tmp>/nunext-zap), ZAP_STRICT=1 (unknown Low alerts fail too),
 *      KEEP=1 (do not restore the database).
 * Known alerts – false positives and accepted findings – live in dev/tests/zap-known.json (id = plugin id, optional url / param
 * regex, a reason each). An alert of risk Medium or High that is not listed fails the suite; Low and Informational are reported.
 * Pages that change the installation itself or run code by design (nuconfig.php, Update, Reset database, cloners, File Manager,
 * custom pages, procedures, Global code, e-mail, webhooks, sign-in and session pages) are out of the scan's scope – see EXCLUDE.
 * Output as the other suites: "ok   " / "FAIL " lines, the last line is the summary; exit code 1 when an unknown alert fails.
 */
const { execSync, spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = (process.env.BASE || 'http://127.0.0.1:8099/nunext/').replace(/\/?$/, '/');
const DB = process.env.DB || '-unu -pnu nutest';
const GA = [process.env.ADMIN_USER || 'globeadmin', process.env.ADMIN_PW || 'nu'];
const MINS = process.env.ZAP_MINS === undefined ? 15 : Math.max(0, parseInt(process.env.ZAP_MINS, 10) || 0);
const SPIDER_MINS = Math.max(1, parseInt(process.env.ZAP_SPIDER_MINS || '5', 10) || 5);
const OUT = process.env.ZAP_OUT || path.join(os.tmpdir(), 'nunext-zap');
const STRICT = process.env.ZAP_STRICT === '1';
const sql = q => execSync('mysql -N ' + DB, { input: q }).toString().trim();

/** Routes (?p=…) the scan never touches: they change files or the installation, run code by design, send mail / requests, or end the session. */
const EXCLUDE = ['logout', 'reauth', 'password', 'account', 'security', 'passkey', 'twofa', 'forgot', 'reset', 'sso', 'mailoauth', 'impersonate', 'sessions', 'lang',
	'configfile', 'update', 'dbreset', 'dbsetup', 'cloner', 'appcloner', 'vendor', 'filemanager', 'libpack', 'page', 'pageasset', 'todo', 'inspect', 'sample',
	'procedures', 'globalcode', 'formai', 'email', 'emailtemplates', 'webhooks', 'notifications', 'import', 'dataexport', 'setupwizard', 'selftest', 'reportpdf', 'upload'];
/** Public pages scanned without a session (spider + passive rules only). */
const PUBLIC = ['login', 'forgot', 'reset'];

function findZap() {
	const cands = [process.env.ZAP, path.resolve('../zap/zap.sh')].filter(Boolean);
	try { for (const d of fs.readdirSync(path.resolve('..'))) if (/^ZAP_|^zap/i.test(d)) cands.push(path.resolve('..', d, 'zap.sh')); } catch {}
	try { for (const d of fs.readdirSync(path.resolve('../zap'))) cands.push(path.resolve('../zap', d, 'zap.sh')); } catch {}
	for (const c of cands) if (fs.existsSync(c)) return c;
	const w = spawnSync('which', ['zap.sh'], { encoding: 'utf8' });
	return w.status === 0 ? w.stdout.trim() : null;
}

function rx(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

/** A YAML single-quoted scalar (no escape sequences – regexes with backslashes stay as they are). */
function q(s) { return "'" + String(s).replace(/'/g, "''") + "'"; }

function plan(reportDir) {
	const base = rx(BASE);
	const excl = base + 'index\\.php\\?(.*&)?p=(' + EXCLUDE.join('|') + ')(&.*)?';     // ZAP sorts the parameters: p= may follow others
	const pub = base + 'index\\.php\\?(.*&)?p=(' + PUBLIC.join('|') + ')(&.*)?';
	const y = [];
	y.push('env:', '  contexts:',
		'    - name: public', '      urls: [' + q(BASE) + ']', '      includePaths: [' + q(pub) + ']',
		'    - name: nunext', '      urls: [' + q(BASE) + ']', '      includePaths: [' + q(base + '.*') + ']',
		'      excludePaths: [' + [excl, pub, base + 'api\\.php.*', base + 'nudbadmin\\.php.*', base + 'oauth\\.php.*', base + 'nucron\\.php.*'].map(q).join(', ') + ']',
		'      authentication:', '        method: form', '        parameters:',
		'          loginPageUrl: ' + q(BASE + 'index.php?p=login'), '          loginRequestUrl: ' + q(BASE + 'index.php?p=login'),
		'          loginRequestBody: ' + q('username={%username%}&password={%password%}&_csrf=token'),
		'        verification:', '          method: poll', '          loggedInRegex: ' + q('\\Qp=logout\\E'), '          loggedOutRegex: ' + q('\\Qid="username"\\E'),
		'          pollFrequency: 60', '          pollUnits: requests', '          pollUrl: ' + q(BASE + 'index.php?p=home'),
		'      sessionManagement:', '        method: cookie',
		'      users:', '        - name: globeadmin', '          credentials:', '            username: ' + q(GA[0]), '            password: ' + q(GA[1]),
		'  parameters:', '    failOnError: true', '    failOnWarning: false', '    progressToStdout: true');
	y.push('jobs:',
		'  - type: passiveScan-config', '    parameters:', '      maxAlertsPerRule: 20', '      scanOnlyInScope: true',
		'  - type: spider', '    parameters:', '      context: public', '      maxDuration: 1', '      postForm: false', '      acceptCookies: true',
		'  - type: spider', '    parameters:', '      context: nunext', '      user: globeadmin', '      maxDuration: ' + SPIDER_MINS, '      postForm: false', '      acceptCookies: true', '      handleParameters: ignore_value',
		'  - type: passiveScan-wait', '    parameters:', '      maxDuration: 5');
	if (MINS > 0) y.push('  - type: activeScan', '    parameters:', '      context: nunext', '      user: globeadmin', '      maxScanDurationInMins: ' + MINS,
		'      maxRuleDurationInMins: 3', '      threadPerHost: 2', '      addQueryParam: false',
		'    policyDefinition:', '      defaultStrength: Medium', '      defaultThreshold: Medium', '      rules:',
		'        - id: 30001', '          threshold: Off', '        - id: 30002', '          threshold: Off', '        - id: 30003', '          threshold: Off', '        - id: 50000', '          threshold: Off',
		'  - type: passiveScan-wait', '    parameters:', '      maxDuration: 5');
	for (const t of ['traditional-json', 'traditional-html']) y.push('  - type: report', '    parameters:', '      template: ' + t, '      reportDir: ' + q(reportDir), '      reportFile: zap', '      reportTitle: nuBuilder Next');
	return y.join('\n') + '\n';
}

function loadKnown() {
	const f = path.join(__dirname, 'zap-known.json');
	const k = fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')).known : [];
	return k.map(e => ({ id: String(e.id), url: e.url ? new RegExp(e.url) : null, param: e.param ? new RegExp(e.param) : null, reason: e.reason || '' }));
}

(async () => {
	const zap = findZap();
	if (!zap) { console.log('skip zap  no ZAP found (../zap/zap.sh or ZAP=…)'); console.log('skipped (no ZAP)'); process.exit(0); }
	fs.mkdirSync(OUT, { recursive: true });
	const home = path.join(OUT, 'home'), reportDir = path.join(OUT, 'report');
	fs.rmSync(reportDir, { recursive: true, force: true }); fs.mkdirSync(reportDir, { recursive: true });
	const planFile = path.join(OUT, 'plan.yaml');
	fs.writeFileSync(planFile, plan(reportDir));
	const dump = path.join(os.tmpdir(), 'nunext-zap-' + process.pid + '.sql');
	const tablesBefore = sql('SHOW TABLES').split('\n');
	execSync('mysqldump ' + DB + ' > ' + dump);
	sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
	const t0 = Date.now();
	let run;
	try {
		run = spawnSync(zap, ['-cmd', '-silent', '-dir', home, '-config', 'api.disablekey=true', '-config', 'connection.timeoutInSecs=60', '-autorun', planFile],
			{ encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, env: Object.assign({}, process.env, { JAVA_TOOL_OPTIONS: '' }) });
		fs.writeFileSync(path.join(OUT, 'zap.log'), (run.stdout || '') + (run.stderr || ''));
	} finally {
		if (process.env.KEEP !== '1') {
			const now = sql('SHOW TABLES').split('\n').filter(t => t && !tablesBefore.includes(t));
			if (now.length) sql('DROP TABLE ' + now.join(', '));
			execSync('mysql ' + DB + ' < ' + dump);
			sql("DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'");
		}
		fs.unlinkSync(dump);
	}
	const mins = Math.round((Date.now() - t0) / 60000);
	const jsonFile = path.join(reportDir, 'zap.json');
	if (!fs.existsSync(jsonFile)) {
		const tail = ((run && run.stdout) || '').split('\n').filter(l => /error|exception|fail/i.test(l)).slice(-5).join(' | ');
		console.log('FAIL zap  no report written (' + (run ? 'exit ' + run.status : 'not started') + ') ' + tail + '  – log: ' + path.join(OUT, 'zap.log'));
		console.log('failed (no report)');
		process.exit(1);
	}
	const known = loadKnown();
	const RISK = ['info', 'low', 'medium', 'high'];
	const report = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
	const alerts = [];
	for (const site of report.site || []) for (const a of site.alerts || []) alerts.push(a);
	alerts.sort((a, b) => (+b.riskcode) - (+a.riskcode) || a.name.localeCompare(b.name));
	let fails = 0, unknownLow = 0;
	const seen = new Set();
	for (const a of alerts) {
		const risk = +a.riskcode, id = String(a.pluginid);
		const inst = a.instances || [];
		const open = inst.filter(i => !known.some(k => (k.id === id || k.id === String(a.alertRef)) && (!k.url || k.url.test(i.uri)) && (!k.param || k.param.test(i.param || ''))));
		const reason = inst.length && !open.length ? (known.find(k => k.id === id || k.id === String(a.alertRef)) || {}).reason : '';
		known.forEach(k => { if (k.id === id || k.id === String(a.alertRef)) seen.add(k.id); });
		const bad = open.length > 0 && (risk >= 2 || (STRICT && risk === 1));
		if (bad) fails++; else if (open.length && risk === 1) unknownLow++;
		const ex = open[0] || inst[0] || {};
		const where = ex.uri ? ' ' + String(ex.uri).replace(BASE, '') + (ex.param ? ' [' + ex.param + ']' : '') : '';
		console.log((bad ? 'FAIL ' : 'ok   ') + (RISK[risk] || risk).padEnd(7) + a.name + ' (' + id + ') ×' + inst.length
			+ (open.length ? (inst.length > open.length ? ' ' + open.length + ' not in zap-known.json' : '') + where : ' – known: ' + (reason.length > 110 ? reason.slice(0, 108) + '…' : reason)));
	}
	for (const k of known) if (!seen.has(k.id)) console.log('ok   note   zap-known.json entry ' + k.id + ' not seen in this run');
	console.log('report: ' + path.join(reportDir, 'zap.html') + '  (' + mins + ' min)');
	const sum = alerts.length + ' alerts (' + RISK.map((r, i) => alerts.filter(a => +a.riskcode === i).length + ' ' + r).reverse().join(', ') + ')';
	console.log(fails ? fails + ' alert(s) of risk medium or high not in zap-known.json – ' + sum : 'no unknown medium / high alerts' + (unknownLow ? ', ' + unknownLow + ' unknown low' : '') + ' – ' + sum);
	process.exit(fails ? 1 : 0);
})();
