#!/usr/bin/env python3
"""
Builds nuFiles for nuBuilder Forte – ONE php file (drop-in for third_party/tinyfilemanager/tinyfilemanager.php) from nuBuilder Next's
file manager apps/nufiles/ (lib/*.php, assets/app.js / app.css, the page of index.php) plus dev/forte/head.php (settings, Forte sign-in,
the page context). Run after a change of apps/nufiles:   python3 -I dev/forte/build_nufiles_forte.py [out.php]
Default output: ../nufiles-forte/tinyfilemanager.php (next to the project).

Adaptations: Forte's libraries (Bootstrap 5.3 of third_party/bootstrap, Font Awesome of third_party/fontawesome instead of Bootstrap
Icons, Ace of third_party/ace instead of CodeMirror – a small CodeMirror-like shim), assets served by the file itself (?asset=js|css),
password confirmation in a dialogue (no separate window), the {FORTE} path placeholder, grants for $nuConfigFileMangerUsers / logins.
"""
import hashlib, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
APP = os.path.join(ROOT, 'apps', 'nufiles')
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(ROOT), 'nufiles-forte', 'tinyfilemanager.php')


def rd(p):
    # a build script of the development package: the paths are files of this project (APP, HERE), never request data
    with open(p, encoding='utf-8') as src:  # skipcq: PTC-W6004
        return src.read()


def must(s, old, new, count=1):
    if s.count(old) < 1:
        raise SystemExit('build: not found: ' + old[:90])
    return s.replace(old, new) if count == 0 else s.replace(old, new, count)


NEXT = re.search(r"define\('NU_VERSION', '([^']+)'\)", rd(os.path.join(ROOT, 'core', 'nuapp.php'))).group(1)
VERSION = '1.0.0-forte'

# ------------------------------------------------------------------ icons: Bootstrap Icons → Font Awesome (Forte ships FA 7)
FA = {
    'arrow-clockwise': 'fa-solid fa-rotate-right', 'arrow-right-square': 'fa-solid fa-arrow-right', 'clock-history': 'fa-solid fa-clock-rotate-left',
    'cloud-arrow-up': 'fa-solid fa-cloud-arrow-up', 'copy': 'fa-regular fa-copy', 'download': 'fa-solid fa-download',
    'exclamation-triangle': 'fa-solid fa-triangle-exclamation', 'file-earmark-plus': 'fa-solid fa-file-circle-plus',
    'file-earmark-zip': 'fa-regular fa-file-zipper', 'floppy': 'fa-solid fa-floppy-disk', 'folder-fill': 'fa-solid fa-folder',
    'folder-symlink-fill': 'fa-solid fa-folder', 'folder-plus': 'fa-solid fa-folder-plus', 'hdd-network': 'fa-solid fa-server',
    'journal-text': 'fa-solid fa-book', 'link-45deg': 'fa-solid fa-link', 'list': 'fa-solid fa-bars', 'lock': 'fa-solid fa-lock',
    'search': 'fa-solid fa-magnifying-glass', 'text-wrap': 'fa-solid fa-paragraph', 'trash3': 'fa-regular fa-trash-can',
    'upload': 'fa-solid fa-upload', 'x-lg': 'fa-solid fa-xmark', 'arrow-90deg-up': 'fa-solid fa-turn-up',
    'arrow-counterclockwise': 'fa-solid fa-rotate-left', 'clipboard': 'fa-regular fa-clipboard', 'shield-lock': 'fa-solid fa-shield-halved',
    'three-dots-vertical': 'fa-solid fa-ellipsis-vertical', 'x-octagon': 'fa-solid fa-circle-xmark',
    'file-earmark': 'fa-regular fa-file', 'file-earmark-code': 'fa-regular fa-file-code', 'file-earmark-image': 'fa-regular fa-file-image',
    'file-earmark-music': 'fa-regular fa-file-audio', 'file-earmark-pdf': 'fa-regular fa-file-pdf', 'file-earmark-play': 'fa-regular fa-file-video',
    'file-earmark-slides': 'fa-regular fa-file-powerpoint', 'file-earmark-spreadsheet': 'fa-regular fa-file-excel',
    'file-earmark-text': 'fa-regular fa-file-lines', 'file-earmark-word': 'fa-regular fa-file-word', 'info-circle': 'fa-solid fa-circle-info',
    'pencil': 'fa-solid fa-pencil', 'code-square': 'fa-solid fa-code', 'folder2': 'fa-regular fa-folder', 'folder2-open': 'fa-regular fa-folder-open',
    'box-arrow-up': 'fa-solid fa-arrow-up-from-bracket', 'input-cursor-text': 'fa-solid fa-i-cursor', 'pencil-square': 'fa-regular fa-pen-to-square', 'eye': 'fa-regular fa-eye', 'key': 'fa-solid fa-key', 'file-earmark-arrow-down': 'fa-solid fa-file-arrow-down', 'chevron-left': 'fa-solid fa-chevron-left', 'chevron-down': 'fa-solid fa-chevron-down', 'three-dots': 'fa-solid fa-ellipsis', 'clock': 'fa-regular fa-clock', 'hdd': 'fa-solid fa-hard-drive', 'grid-3x3-gap': 'fa-solid fa-table-cells', 'list-ul': 'fa-solid fa-list', 'moon-stars': 'fa-solid fa-moon', 'sun': 'fa-solid fa-sun',
}


def fa(name):
    if name not in FA:
        raise SystemExit('build: no Font Awesome icon for bi-' + name)
    return 'bi ' + FA[name]


# ------------------------------------------------------------------ PHP libraries
def lib(name):
    s = rd(os.path.join(APP, 'lib', name + '.php'))
    s = re.sub(r"^<\?php\s*\ndefined\('NU_PAGE'\) \|\| exit;\s*\n", '', s)
    s = s.replace('NuPageContext', 'NufCtx')
    return '// ------------------------------------------------------------------ lib/' + name + '.php\n' + s.strip() + '\n'


cfg = lib('config')
cfg = cfg.replace("defined('NU_ROOT') ? dirname(NU_ROOT) : ''", 'NUF_FORTE_ROOT').replace("defined('NU_ROOT') ? NU_ROOT : ''", 'NUF_FORTE_ROOT')
cfg = must(cfg, "'{DOCUMENT_ROOT}' => (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),", "'{DOCUMENT_ROOT}' => (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),\n\t\t\t'{FORTE}' => NUF_FORTE_ROOT,")
cfg = must(cfg, "foreach (['{NUNEXT}' => NUF_FORTE_ROOT,", "foreach (['{FORTE}' => NUF_FORTE_ROOT, '{NUNEXT}' => NUF_FORTE_ROOT,")
cfg = must(cfg, "\t\t\t\t\t$match = false;\n", "\t\t\t\t\t$match = false;\n"
           "\t\t\t\t\tif (!empty($g['tfm_users']) && !empty($user['tfm'])) $match = true;                 // Forte: $nuConfigFileMangerUsers\n"
           "\t\t\t\t\tforeach ((array)($g['login'] ?? []) as $lg) if (strcasecmp(trim((string)$lg), (string)$user['login']) === 0) $match = true;\n")

i18n = lib('i18n')
api = lib('api')
libs = lib('guard') + cfg + lib('fs') + lib('log') + i18n + api

# ------------------------------------------------------------------ the page (body of index.php)
idx = rd(os.path.join(APP, 'index.php'))
page = idx[idx.index('?><!doctype html>') + 2:]
LINES = {"$v('NU_BS', 'vendor/bundled/bootstrap-5.3.9', 'css/bootstrap.min.css')": '<link rel="stylesheet" href="<?= $nu->h(NUF_FORTE_URL . \'third_party/bootstrap/bootstrap.min.css\') ?>">',
         "$v('NU_BI',": '<link rel="stylesheet" href="<?= $nu->h(NUF_FORTE_URL . \'third_party/fontawesome/css/all.min.css\') ?>">',
         "$v('NU_CODEMIRROR', 'vendor/bundled/codemirror-6.43.15', 'codemirror.css')": None,
         "'js/bootstrap.bundle.min.js')": '<script src="<?= $nu->h(NUF_FORTE_URL . \'third_party/bootstrap/bootstrap.bundle.min.js\') ?>"></script>',
         "'codemirror-bundle.js')": '<script src="<?= $nu->h(NUF_FORTE_URL . \'third_party/ace/src-min-noconflict/ace.js\') ?>"></script>'}
out_lines: list[str] = []
for line in page.split('\n'):
    hit = [k for k in LINES if k in line]
    if hit:
        repl = LINES.pop(hit[0])
        if repl is not None: out_lines.append(repl)
        continue
    out_lines.append(line)
if LINES: raise SystemExit('build: page lines not found: ' + ', '.join(LINES))
page = '\n'.join(out_lines)
page = re.sub(r"<\?php if \(function_exists\('nu_asset'\)\).*?<\?php endif; \?>\n", '', page, flags=re.S)
page = must(page, "<?= $nu->sdk() ?>\n", '')
page = must(page, "<?= $nu->h($nu->asset('assets/app.js')) ?>", "<?= $nu->h($nu->url(['asset' => 'js', 'v' => NUF_ASSET_V])) ?>")
# Forte has no light / dark switch of its own: one at the foot of the side bar (kept in this browser)
page = must(page, """			<button type="button" class="nav-link nuf-side-link d-none" id="nufLogBtn">""",
            """			<button type="button" class="nav-link nuf-side-link" id="nufThemeBtn" aria-pressed="false"><i class="bi bi-moon-stars nuf-ic-dark" aria-hidden="true"></i><i class="bi bi-sun nuf-ic-light" aria-hidden="true"></i> <span id="nufThemeLbl"></span></button>
			<button type="button" class="nav-link nuf-side-link d-none" id="nufLogBtn">""")
page = re.sub(r'bi bi-([a-z0-9-]+)', lambda m: fa(m.group(1)), page)
page = must(page, '<body class="nuf">', '<body class="nuf" data-ace="<?= $nu->h(NUF_FORTE_URL . \'third_party/ace/src-min-noconflict\') ?>">')

# ------------------------------------------------------------------ JavaScript
js = rd(os.path.join(APP, 'assets', 'app.js'))
js = must(js, "function icon(name) { return el('i', {'class': 'bi bi-' + name, 'aria-hidden': 'true'}); }",
          "var FA = " + json.dumps(FA, separators=(',', ':')) + ";\n"
          "\tfunction bi(name) { return 'bi ' + (FA[name] || 'fa-regular fa-file'); }   // Forte: Font Awesome instead of Bootstrap Icons\n"
          "\tfunction icon(name) { return el('i', {'class': bi(name), 'aria-hidden': 'true'}); }")
js = must(js, "'bi bi-' + fileIcon(it) + (it.type === 'dir'", "bi(fileIcon(it)) + (it.type === 'dir'")
js = must(js, "'bi bi-' + (m.type === 'dir' ? 'folder-fill nuf-ico-dir' : fileIcon({name: m.name, type: 'file'}))", "(m.type === 'dir' ? bi('folder-fill') + ' nuf-ico-dir' : bi(fileIcon({name: m.name, type: 'file'})))")
js = re.sub(r"'bi bi-([a-z0-9-]+)([^']*)'", lambda m: "bi('" + m.group(1) + "')" + (" + '" + m.group(2) + "'" if m.group(2) else ''), js)
if 'bi bi-' in js:
    raise SystemExit('build: a Bootstrap icon is left in app.js')
js = js.replace('NuPage.fetch(', 'nufFetch(').replace('NuPage.csrf', 'boot.csrf')
js = re.sub(r'NuPage\.setTitle\(([^;]*)\);', r'document.title = \1;', js)
if 'NuPage' in js:
    raise SystemExit('build: NuPage left in app.js')
js = js.replace('CodeMirror.fromTextArea(', 'NufAce.fromTextArea(').replace("'input, textarea, select, .CodeMirror'", "'input, textarea, select, .ace_editor'")
# password confirmation: a dialogue with a password field instead of nuBuilder Next's window
a = js.index('\tvar reauthWait = null;')
b = js.index('\t// ================================================================== shares, navigation')
js = js[:a] + r'''	var reauthWait = null;
	function reauth() {
		if (reauthWait) return reauthWait;
		reauthWait = new Promise(function (resolve) {
			var ask = function (err) {
				var inp = $('nufDlgInput');
				inp.type = 'password';
				inp.setAttribute('autocomplete', 'current-password');
				dialog({title: t('reauth_title'), text: err || t('reauth_text'), input: '',
					buttons: [{label: t('cancel'), value: null}, {label: t('reauth_open'), value: true, cls: 'btn-primary'}]}).then(function (v) {
					inp.type = 'text';
					inp.setAttribute('autocomplete', 'off');
					if (v === null || v === undefined) { reauthWait = null; resolve(false); return; }
					call('reauth', {password: v}, true).then(function () { reauthWait = null; resolve(true); })
						.catch(function (e) { ask(e instanceof ApiError ? e.message : t('unknown_error')); });
				});
			};
			ask('');
		});
		return reauthWait;
	}

''' + js[b:]
js = must(js, 'return reauth(e.data.reauth).then(', 'return reauth().then(')
js = must(js, "try { p = localStorage.getItem('nunext-theme'); } catch (e) { p = null; }", "try { p = localStorage.getItem('nufiles-theme'); } catch (e) { p = null; }")
js = must(js, "window.addEventListener('storage', function (e) { if (e.key === 'nunext-theme') set(); });",
          """window.addEventListener('storage', function (e) { if (e.key === 'nufiles-theme') { set(); label(); } });
		var tb = $('nufThemeBtn');
		function label() {
			var dark = root.getAttribute('data-bs-theme') === 'dark';
			tb.setAttribute('aria-pressed', dark ? 'true' : 'false');
			$('nufThemeLbl').textContent = t(dark ? 'theme_light' : 'theme_dark');
		}
		label();
		if (mq && mq.addEventListener) mq.addEventListener('change', label);
		tb.addEventListener('click', function () {
			var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
			try { localStorage.setItem('nufiles-theme', next); } catch (e) { root.setAttribute('data-nuf-theme', next); }
			set(); label();
		});""")

SHIM = r'''/* nuFiles for nuBuilder Forte: fetch with the CSRF token, and Ace (third_party/ace of Forte) behind the few CodeMirror calls app.js makes. */
(function () {
	'use strict';
	window.nufFetch = function (u, opts) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = Object.assign({'X-CSRF-Token': (JSON.parse(document.getElementById('nufBoot').textContent || '{}').csrf || '')}, opts.headers || {});
		return fetch(u, opts);
	};
	var MODES = {'application/x-httpd-php': 'php', 'text/javascript': 'javascript', 'text/typescript': 'typescript', 'application/json': 'javascript',
		'text/css': 'css', 'text/x-scss': 'css', 'text/x-less': 'css', 'text/html': 'html', 'application/xml': 'html', 'text/x-mysql': 'mysql', 'text/plain': 'text'};
	window.NufAce = {fromTextArea: function (ta, opt) {
		var base = document.body.getAttribute('data-ace');
		if (base) window.ace.config.set('basePath', base);
		var host = document.createElement('div');
		host.className = 'nuf-ace';
		ta.parentNode.insertBefore(host, ta);
		ta.style.display = 'none';
		var ed = window.ace.edit(host, {useWorker: false, showPrintMargin: false, tabSize: 4, useSoftTabs: false, fontSize: 13});
		var rev = 0, handlers = {};
		var dark = function () { return document.documentElement.getAttribute('data-bs-theme') === 'dark'; };
		var theme = function () { ed.setTheme(dark() ? 'ace/theme/tomorrow_night' : 'ace/theme/chrome'); };
		theme();
		new MutationObserver(theme).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});
		var fire = function (n) { (handlers[n] || []).forEach(function (f) { f(); }); };
		ed.session.on('change', function () { rev++; fire('changes'); });
		ed.selection.on('changeCursor', function () { fire('cursorActivity'); });
		var keys = (opt && opt.extraKeys) || {};
		ed.commands.addCommand({name: 'nufSave', bindKey: {win: 'Ctrl-S', mac: 'Command-S'}, exec: function () { if (keys['Ctrl-S']) keys['Ctrl-S'](); }});
		ed.commands.addCommand({name: 'nufEsc', bindKey: {win: 'Esc', mac: 'Esc'}, exec: function () { if (keys.Esc) keys.Esc(); }});
		return {
			on: function (n, f) { (handlers[n] = handlers[n] || []).push(f); },
			refresh: function () { ed.resize(true); },
			focus: function () { ed.focus(); },
			setOption: function (k, v) {
				if (k === 'lineWrapping') ed.session.setUseWrapMode(!!v);
				else if (k === 'readOnly') ed.setReadOnly(!!v);
				else if (k === 'mode') ed.session.setMode('ace/mode/' + (MODES[v] || 'text'));
				else if (k === 'lineSeparator') ed.session.setNewLineMode(v === '\r\n' ? 'windows' : 'unix');
			},
			setValue: function (v) { ed.session.setValue(v); },
			getValue: function () { return ed.session.getValue(); },
			clearHistory: function () { ed.session.getUndoManager().reset(); },
			changeGeneration: function () { return rev; },
			isClean: function (g) { return g === rev; },
			markClean: function () { /* the caller takes changeGeneration() afterwards */ },
			getCursor: function () { var p = ed.getCursorPosition(); return {line: p.row, ch: p.column}; },
			setCursor: function (p) { ed.gotoLine(p.line + 1, p.ch || 0, false); },
			scrollIntoView: function () { ed.renderer.scrollCursorIntoView(null, 0.5); },
			ace: ed
		};
	}};
}());
'''
js = SHIM + js

css = rd(os.path.join(APP, 'assets', 'app.css'))
css += '''
/* nuBuilder Forte edition: Ace editor fills the editor dialogue; Font Awesome icons in the places of Bootstrap Icons */
.nuf-editor-body { position: relative; }
.nuf-ace { position: absolute; inset: 0; }
.nuf .bi { display: inline-block; width: 1.15em; text-align: center; }
#nufThemeBtn .nuf-ic-light, [data-bs-theme="dark"] #nufThemeBtn .nuf-ic-dark { display: none; }
[data-bs-theme="dark"] #nufThemeBtn .nuf-ic-light { display: inline-block; }
'''

# ------------------------------------------------------------------ the run (after the libraries)
RUN = r'''
// ====================================================================================================================== run

$nu = new NufCtx($NUF_SETTINGS);

if (isset($_GET['asset'])) {                     // the page's own script and style (CSP 'self', no inline code)
	$a = (string)$_GET['asset'];
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: private, max-age=86400');
	if ($a === 'js') { header('Content-Type: text/javascript; charset=utf-8'); echo NUF_JS; exit; }
	if ($a === 'css') { header('Content-Type: text/css; charset=utf-8'); echo NUF_CSS; exit; }
	http_response_code(404);
	exit;
}

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; "
	. "media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_name('NUFILESFORTE');
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/') . '/',
	'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$S = [];
try {
	$nu->connect();
	$why = $nu->signIn();
} catch (Throwable $e) {
	error_log('nuFiles: ' . $e->getMessage());
	$why = 'err_server';
}
$S = NufI18n::strings($nu->lang());
$de = str_starts_with($nu->lang(), 'de');
$S = array_replace($S, $de ? [
	'reauth_text' => 'Code-Ordner enthalten das Programm dieses Servers. Bitte bestätige dein Passwort.',
	'err_password' => 'Das Passwort stimmt nicht.', 'err_password_many' => 'Zu viele Versuche – warte ein paar Minuten.',
	'err_session' => 'Die Sitzung ist abgelaufen – melde dich bei nuBuilder an und öffne den Datei-Manager von dort.',
	'err_access' => 'Kein Zugriff auf den Datei-Manager.', 'err_csrf' => 'Die Seite ist veraltet – bitte neu laden.',
] : [
	'reauth_text' => 'Code folders hold the program of this server. Please confirm your password.',
	'err_password' => 'The password is not correct.', 'err_password_many' => 'Too many tries – wait a few minutes.',
	'err_session' => 'The session has ended – sign in to nuBuilder and open the File Manager from there.',
	'err_access' => 'No access to the File Manager.', 'err_csrf' => 'The page is out of date – please reload it.',
]);

if ($why !== null) {
	if ($nu->action() !== '') $nu->json(['ok' => false, 'error' => $why, 'message' => $S[$why] ?? $why], $why === 'err_server' ? 500 : 401);
	http_response_code($why === 'err_server' ? 500 : 403);
	header('Content-Type: text/html; charset=utf-8');
	echo '<!doctype html><meta charset="utf-8"><title>' . $nu->h($S['title'] ?? 'Files') . '</title><body style="font-family:system-ui,sans-serif;padding:2rem"><p>'
		. $nu->h($S[$why] ?? $why) . '</p><p><a href="' . $nu->h($nu->homeUrl()) . '">nuBuilder</a></p></body>';
	exit;
}

$sess = &$nu->session();
if (empty($sess['csrf'])) $sess['csrf'] = bin2hex(random_bytes(32));
if ($nu->isPost()) {
	$tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
	if (!hash_equals((string)$sess['csrf'], $tok)) $nu->json(['ok' => false, 'error' => 'csrf', 'message' => $S['err_csrf']], 403);
}

if ($nu->action() === 'reauth') {               // password confirmation (code shares, chmod): 5 tries per 15 minutes
	if (!$nu->isPost()) $nu->json(['ok' => false, 'error' => 'err_bad_request', 'message' => $S['err_bad_request'] ?? 'Bad request'], 405);
	$tries = array_values(array_filter((array)($sess['pw_tries'] ?? []), static fn($t) => (int)$t > time() - 900));
	if (count($tries) >= 5) $nu->json(['ok' => false, 'error' => 'err_password_many', 'message' => $S['err_password_many']], 429);
	if (!$nu->checkPassword((string)($_POST['password'] ?? ''))) {
		$tries[] = time();
		$sess['pw_tries'] = $tries;
		usleep(400000);
		$nu->json(['ok' => false, 'error' => 'err_password', 'message' => $S['err_password']], 403);
	}
	$sess['pw_tries'] = [];
	$sess['auth_at'] = time();
	session_regenerate_id(true);
	$nu->json(['ok' => true]);
}

NufConfig::load($nu);
NufFs::housekeeping(NufConfig::$limits);
if ($nu->action() !== '') NufApi::handle($nu, $S);

$boot = NufApi::boot($nu, $S) + ['csrf' => (string)$sess['csrf']];
$t = static fn(string $k): string => $nu->h($S[$k] ?? $k);
$theme = $nu->theme();
?>'''

head = rd(os.path.join(HERE, 'head.php')).replace('%%VERSION%%', VERSION).replace('%%NEXT%%', NEXT).replace('%%ASSETV%%', hashlib.sha256((js + css).encode('utf-8')).hexdigest()[:12])


def nowdoc(name, text):
    tag = 'NUF' + name
    if ('\n' + tag) in text:
        raise SystemExit('build: nowdoc tag in content')
    return "const NUF_" + name + " = <<<'" + tag + "'\n" + text + "\n" + tag + ";\n"


out = head.rstrip() + '\n\n' + libs + '\n' + nowdoc('JS', js) + nowdoc('CSS', css) + RUN + page
os.makedirs(os.path.dirname(OUT), exist_ok=True)
with open(OUT, 'w', encoding='utf-8') as f:
    f.write(out)
print(OUT, len(out), 'bytes')
