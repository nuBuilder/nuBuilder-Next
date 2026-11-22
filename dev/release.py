#!/usr/bin/env python3
"""
nuBuilder Next – make a release (run from the nunext folder):

    python3 dev/release.py <version> "<title>" <changes.txt> [--gate] [--no-checks]

  --gate       full release gate: also the browser suites (smoke, e2e, compat – see browser_tests()); the manifest says "gated": true.
               Without it (0.8.389, the usual fast release) the browser suites are skipped, the package says "gated": false and the
               update page shows "Not fully tested". The owner says when to gate (CLAUDE.md "How work is delivered"); the script
               prints how many releases came since the last gate.

  version      e.g. 0.8.202 (must be higher than core/nuapp.php's NU_VERSION)
  title        short title, lower case start, e.g. "user form: welcome e-mail hint behind an (i) icon"
  changes.txt  the changelog text: "- " bullet lines, continuation lines indented by two spaces, lines max. ~130 characters

What it does (the same steps every release):
  0. lang/en-US.php and lang/de-CH.php built by dev/lang_variants.php, assets/js/nu/nuruntime.js joined by dev/nuruntime_join.py; checks (skip with --no-checks): php -l of every PHP file, dev/check_lang.php, dev/tests/run.php,
     and with NUNEXT_TEST_BASE set the browser suites smoke.js, e2e.js and (NUNEXT_TEST_NUB) compat.js – see browser_tests()
  1. README.md: version in the first line; CHANGELOG.md: new section "## <version> – <title>" above the newest one
  2. core/nuapp.php: NU_VERSION
  3. nunext-manifest.json: version, built, sha256 + size of every DEPLOYED file, the "changes" entry (shown on the update page),
     signature (Ed25519, dev/sign.php) when the signing key is found: $NUNEXT_SIGNING_KEY, ../nunext-signing.key or
     ~/.nunext-signing.key – without it the package is unsigned (the update page warns)
  4. ../nunext.zip      – the package to install (folder nunext/ inside): without CLAUDE.md, TODO.md, dev/ and .github/
     ../nunext-dev.zip  – everything, to continue the development (send this one to a new session)
     nunext.zip also gets the signed library packs of dev/libsrc/ in _install/ (0.8.409, PHPMailer – installed by the installer)
Then check the package on a test installation: NuUpdate::inspect('…/nunext.zip') must report no errors.
"""
import datetime, hashlib, json, os, re, shlex, shutil, subprocess, sys, time, zipfile

sys.stdout.reconfigure(line_buffering=True)                    # 0.8.768: progress reaches a log file line by line

# never deployed to a server (readable there, and not needed): development notes, open points, tools, CI
DEV_ONLY = ('CLAUDE.md', 'TODO.md', 'FEATURES.md', '.gitignore', '.deepsource.toml', '.dockerignore')
DEV_DIRS = ('dev/', '.github/', 'docker/')


def deployed(p):
    return p not in DEV_ONLY and not p.startswith(DEV_DIRS)


def all_files():
    out = []
    for dirpath, dirnames, filenames in os.walk('.'):
        dirnames[:] = [d for d in dirnames if d not in ('__pycache__', '.git', '_backup', '_install', '_update', '_libsrc', 'temp', 'third_party', '_data')
                       and os.path.relpath(os.path.join(dirpath, d), '.').replace(os.sep, '/') != 'vendor/packs']   # packs: the server's (0.8.409)
        for f in filenames:
            p = os.path.relpath(os.path.join(dirpath, f), '.').replace(os.sep, '/')
            if p == 'nunext-manifest.json' or p.endswith('.pyc') or p == 'nuconfig.php' or p == 'install.allow':
                continue
            out.append(p)
    return sorted(out)


def exe(name):
    """Full path of a program (DeepSource BAN-B607: never start a process by a partial name); None when it is not installed."""
    if os.path.isabs(name) or os.sep in name:
        return os.path.abspath(name)
    return shutil.which(name)


def run(cmd):
    prog = exe(cmd[0])
    if not prog:
        return 127, cmd[0] + ': not found'
    r = subprocess.run([prog, *cmd[1:]], capture_output=True, text=True, check=False)
    return r.returncode, (r.stdout + r.stderr).strip()


def lint_php(files):
    bad = []
    for p in files:
        if p.endswith('.php') and not p.startswith('vendor/bundled/'):
            code, out = run(['php', '-l', p])
            if code != 0:
                bad.append(out)
    return bad


def phpstan():
    for phar in ('../phpstan.phar', os.path.expanduser('~/phpstan.phar')):      # static analysis when PHPStan is at hand (not in the project)
        if os.path.isfile(phar):
            code, out = run(['php', phar, 'analyse', '-c', 'dev/phpstan.neon', '--no-progress', '--memory-limit=1G', '--error-format=raw'])
            print('phpstan: ' + ('ok' if code == 0 else 'errors'))
            return [out] if code != 0 else []
    return []


def eslint():
    tool = os.path.abspath('../node_modules/.bin/eslint')
    if not (os.path.exists('dev/eslint.config.mjs') and os.path.isfile(tool) and exe('node')):
        return []
    code, out = run([tool, '-c', 'dev/eslint.config.mjs', 'assets/js'])
    print('eslint: ' + ('ok' if code == 0 else 'errors'))
    return [out] if code != 0 else []


def unit_tests():
    if not os.path.exists('dev/tests/run.php'):
        return []
    code, out = run(['php', 'dev/tests/run.php'])
    last = out.splitlines()[-1] if out else 'tests: no output'
    print(last)
    if code != 0 or not re.match(r'all \d+ tests passed$', last.strip()):   # 0.8.714: a script that died early (exit 0, an error page) fails too
        return [out]
    return []


def checks(files):
    bad = lint_php(files)
    code, out = run(['php', 'dev/check_lang.php'])
    if code != 0:
        bad.append(out)
    bad += library_problems() + phpstan() + eslint() + unit_tests()
    if not bad and GATE:
        bad += browser_tests()
    elif not GATE:
        print('browser suites: skipped (fast release – run with --gate for the full release gate)')
    if bad:
        sys.exit('checks failed:\n' + '\n'.join(bad))


def sync_site(site):
    run(['rsync', '-a', '--exclude', '/nuconfig.php', '--exclude', '/vendor/packs', '--exclude', '/_libsrc', '--exclude', '_backup', '--exclude', 'temp', '--exclude', 'third_party',
         '--exclude', 'dev', '--exclude', '.github', '--exclude', 'CLAUDE.md', '--exclude', 'TODO.md', '--exclude', '.deepsource.toml', './', site.rstrip('/') + '/'])
    time.sleep(3)                                                       # OPcache


def test_env(base, db, nub, site):
    env = dict(os.environ, BASE=base, DB=db, NUB=nub, SITE=site)
    if not env.get('PKG') and os.path.exists('../nunext.zip'):                 # the last built package: update_check / libreq run too (0.8.679)
        env['PKG'] = os.path.abspath('../nunext.zip')
    code, out = run(['npm', 'root', '-g'])
    if code == 0:
        env['NODE_PATH'] = out.splitlines()[-1].strip()
    return env


def suite_list(nub):
    on = os.environ.get
    return [('smoke', 'dev/tests/smoke.js'), ('e2e', 'dev/tests/e2e.js')] + ([('compat', 'dev/tests/compat.js')] if nub else []) \
        + ([('load', 'dev/tests/load.js')] if on('NUNEXT_TEST_LOAD') == '1' else []) \
        + ([('a11y', 'dev/tests/a11y.js')] if on('NUNEXT_TEST_A11Y') == '1' else []) \
        + ([('zap', 'dev/tests/zap.js')] if on('NUNEXT_TEST_ZAP', '1') != '0' else [])   # large data / accessibility: ~4 minutes each, on request; ZAP (0.8.883): skipped by itself without ../zap


def run_suite(name, f, env, db):
    """One browser suite; 0.8.768: every test line is streamed as it happens ("e2e   12  ok   home_view  812 ms")."""
    run(['mysql', *shlex.split(db), '-e', "DELETE FROM zzzzsys_nunext_log WHERE event = 'login_failed'"])   # no shell (DeepSource BAN-B602)
    start = time.time()
    print(f'{name}: started {time.strftime("%H:%M:%S")}', flush=True)
    node = exe('node')
    if not node:
        return name + ': node not found'
    with subprocess.Popen([node, f], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, env=env, bufsize=1) as p:
        out, n = [], 0
        for line in p.stdout:
            line = line.rstrip('\n')
            out.append(line)
            if line[:5] in ('ok   ', 'skip ', 'FAIL '):
                n += 1
                print(f'  {name} {n:4d}  {line}', flush=True)
        p.wait()
    out = [l for l in out if l.strip()]
    mins = int(time.time() - start) // 60
    print(name + ': ' + (out[-1] if out else 'no output') + f'  ({mins} min)', flush=True)
    if p.returncode != 0:
        return name + ':\n' + '\n'.join(l for l in out if l.startswith('FAIL'))
    return None


def browser_tests():
    """Browser suites against the test installation (0.8.364) – a failure stops the release. Env: NUNEXT_TEST_SITE (folder the code is
    synced to), NUNEXT_TEST_BASE (its URL), NUNEXT_TEST_DB (mysql arguments, default "-unu -pnu nutest"), NUNEXT_TEST_NUB (URL of
    nuBuilder on the same database – then also dev/tests/compat.js). Without NUNEXT_TEST_BASE they are skipped (with a note)."""
    base = os.environ.get('NUNEXT_TEST_BASE', '')
    if not base:
        print('browser tests: skipped (NUNEXT_TEST_BASE not set – see CLAUDE.md "Test environment")')
        return []
    site = os.environ.get('NUNEXT_TEST_SITE', '')
    if site:
        sync_site(site)
    db = os.environ.get('NUNEXT_TEST_DB', '-unu -pnu nutest')
    nub = os.environ.get('NUNEXT_TEST_NUB', '')
    env = test_env(base, db, nub, site)
    bad = [r for r in (run_suite(name, f, env, db) for name, f in suite_list(nub)) if r]
    if not nub:
        print('compat: skipped (NUNEXT_TEST_NUB not set)')
    return bad


def page_api():
    """NuPage::apiInfo() of this version (supported page API versions, $nu methods, NuPage.* names, deprecations)."""
    code, out = run(['php', '-r', "define('NU_ROOT', getcwd()); function t(string $k, mixed ...$a): string { return $k; } "
                     "require 'core/nupage.php'; echo json_encode(NuPage::apiInfo());"])
    if code != 0:
        sys.exit('page_api: ' + out)
    return json.loads(out)


def plugin_api():
    """NuPlugin::apiInfo() of this version: Check package lists the installed plugins the new version would stop."""
    code, out = run(['php', '-r', "define('NU_ROOT', getcwd()); require 'core/nuplugin.php'; echo json_encode(NuPlugin::apiInfo());"])
    if code != 0:
        sys.exit('plugin_api: ' + out)
    return json.loads(out)


LIBRARIES = 'vendor/bundled/libraries.json'


def libraries():
    """The bundled libraries (vendor/bundled/libraries.json) for the manifest: name, version, dir, license."""
    if not os.path.isfile(LIBRARIES):
        return []
    return [{k: l[k] for k in ('name', 'version', 'dir', 'license')} for l in read_json(LIBRARIES)]


REQUIRED_LIBS = 'install/libraries-required.json'
LIB_SLOTS = ('tcpdf', 'phpmailer', 'tinymce', 'phpmyadmin', 'adminer', 'uppy', 'phpspreadsheet')   # tinyfilemanager removed in 0.8.479 (nuFiles)


def required_libs():
    """Library packs this version needs (0.8.406): {slot: {min, needed}} for the manifest ("requires_libs") – Check package warns."""
    if not os.path.isfile(REQUIRED_LIBS):
        return {}
    out = {}
    for k, v in read_json(REQUIRED_LIBS).items():
        if k.startswith('_'):
            continue
        if k not in LIB_SLOTS or not isinstance(v, dict) or not re.match(r'^[0-9][0-9A-Za-z.+_-]{0,40}$', str(v.get('min', ''))):
            sys.exit(f'{REQUIRED_LIBS}: bad entry {k}')
        out[k] = {'min': str(v['min']), 'needed': bool(v.get('needed'))}
    return out


def library_problems():
    """Every library of the registry exists, and where a pattern is given the file contains the registered version."""
    bad = []
    if not os.path.isfile(LIBRARIES):
        return [LIBRARIES + ' missing']
    for l in read_json(LIBRARIES):
        f = os.path.join('vendor/bundled', l['dir'], l['file'])
        if not os.path.isfile(f):
            bad.append(f'library {l["name"]}: {f} missing')
            continue
        if l.get('detect'):
            m = re.search(l['detect'], read_text(f, errors='replace')[:400000])
            if not m or m.group(1) != l['version']:
                bad.append(f'library {l["name"]}: registry says {l["version"]}, the file says {m.group(1) if m else "nothing"}')
    print('libraries: ' + ('ok' if not bad else 'errors'))
    return bad


LIBSRC = 'dev/libsrc'          # sources of the library packs that come with nunext.zip (0.8.409): <slot>/ – today only phpmailer


def bundled_packs(keyfile):
    """Builds and signs the packs of dev/libsrc/<slot>/ (dev/libpack.php) – the installer installs them from _install/ without GitHub.
    Without the key: none (an unsigned pack would be refused anyway)."""
    if not keyfile or not os.path.isdir(LIBSRC):
        if os.path.isdir(LIBSRC):
            print('WARNING: no signing key – no library packs in nunext.zip')
        return []
    import tempfile
    out = tempfile.mkdtemp(prefix='nulibsrc-')
    args = [f'{d}={os.path.join(LIBSRC, d)}' for d in sorted(os.listdir(LIBSRC)) if os.path.isdir(os.path.join(LIBSRC, d))]
    code, msg = run(['php', 'dev/libpack.php', out, keyfile] + args)
    if code != 0:
        sys.exit('library packs: ' + msg)
    packs = sorted(os.path.join(out, f) for f in os.listdir(out) if f.startswith('nunext-lib-') and f.endswith('.zip'))
    check_github_packs(packs)
    return packs


GITHUB_LIBS = 'https://github.com/' + os.environ.get('NUNEXT_GITHUB_REPO', 'nuBuilder/nuBuilder-Next') + '/releases/download/libraries/libraries.json'


def check_github_packs(packs):
    """0.8.625: warns when a pack in nunext.zip (_install/, e.g. PHPMailer) has another version than the GitHub release "libraries" –
    new installations would get one version, existing ones (Update → Libraries) the other. Publish the pack + libraries.json
    (php dev/libpack.php <out> ../nunext-signing.key <slot>=dev/libsrc/<slot> --keep=<current libraries.json>). Never stops the release."""
    import urllib.request
    try:
        with urllib.request.urlopen(GITHUB_LIBS, timeout=20) as r:
            index = json.loads(r.read().decode('utf-8'))
        gh = {str(l.get('name')): str(l.get('version')) for l in index.get('libs', []) if isinstance(l, dict)}
    except Exception as e:                                   # offline, rate limit … – only a note
        print(f'library packs: GitHub release "libraries" not checked ({e.__class__.__name__}: {e})')
        return
    for f in packs:
        m = re.match(r'^nunext-lib-([a-z0-9]+)-(.+)\.zip$', os.path.basename(f))
        if not m:
            continue
        slot = m.group(1)
        with zipfile.ZipFile(f) as z:
            ver = str(json.loads(z.read('nunext-lib.json').decode('utf-8')).get('version', m.group(2)))
        if slot not in gh:
            print(f'WARNING: library pack {slot} {ver} is in nunext.zip but not in the GitHub release "libraries" – publish it there')
        elif gh[slot] != ver:
            print(f'WARNING: library pack {slot}: nunext.zip brings {ver}, the GitHub release "libraries" has {gh[slot]} – '
                  f'new installations and updates would differ; publish nunext-lib-{slot}-{ver}.zip and a new libraries.json')
        else:
            print(f'library packs: {slot} {ver} = GitHub release "libraries"')


def signing_key():
    for p in (os.environ.get('NUNEXT_SIGNING_KEY', ''), '../nunext-signing.key', os.path.expanduser('~/.nunext-signing.key')):
        if p and os.path.isfile(p):
            return p
    return None


def read_text(path: str, **kw) -> str:
    with open(path, encoding='utf-8', **kw) as fh:
        return fh.read()


def write_text(path: str, text: str) -> None:
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write(text)


def read_json(path: str):
    with open(path, encoding='utf-8') as fh:
        return json.load(fh)


def write_json(path: str, data) -> None:
    with open(path, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, ensure_ascii=False, indent=1)


def version_key(v: str) -> tuple:
    return tuple(int(x) for x in v.split('.'))


def step(cmd: list, quiet_empty: bool = False) -> None:
    """Run a build step; print its output, stop the release when it fails."""
    code, out = run(cmd)
    if out.strip() or not quiet_empty:
        print(out.strip())
    if code != 0:
        sys.exit(out)


GATE = '--gate' in sys.argv


def changelog(version: str, title: str, text: str) -> None:
    """1. README title + CHANGELOG.md section."""
    readme, n = re.subn(r'^# nuBuilder Next \S+ –', f'# nuBuilder Next {version} –', read_text('README.md'), count=1)
    if n != 1:
        sys.exit('README.md: first line "# nuBuilder Next <version> – …" not found')
    log = read_text('CHANGELOG.md')
    first = re.search(r'^## \d+\.\d+\.\d+ – ', log, flags=re.M)
    if not first:
        sys.exit('CHANGELOG.md: no changelog section "## x.y.z – …" found')
    log = log[:first.start()] + f'## {version} – {title}\n{text}\n\n' + log[first.start():]
    write_text('README.md', readme)
    write_text('CHANGELOG.md', log)


def file_hashes(everything: list) -> dict:
    """sha256 + size of every deployed file; stops when files under custom/ would make the package uninstallable."""
    # installed versions refuse a package with files under custom/ other than these (NuUpdate::isProtected)
    bad_custom = [p for p in everything if deployed(p) and p.startswith('custom/') and p not in ('custom/.htaccess', 'custom/PAGE_GUIDE.md')
                  and not p.startswith('custom/pages/_template/')]
    if bad_custom:
        sys.exit('files under custom/ would make the package uninstallable for installed versions: ' + ', '.join(bad_custom[:5]))
    plugins = [p for p in everything if p.startswith('plugins/') and p not in ('plugins/.htaccess', 'plugins/README.md')]
    if plugins:                             # plugins belong to an installation, never to the package
        sys.exit('files under plugins/ must not be packaged: ' + ', '.join(plugins[:5]))
    files = {}
    for p in everything:
        if deployed(p):
            with open(p, 'rb') as fh:
                b = fh.read()
            files[p] = {'sha256': hashlib.sha256(b).hexdigest(), 'size': len(b)}
    return dict(sorted(files.items()))


def manifest(version: str, old: str, title: str, text: str, everything: list):
    """3. manifest (deployed files only), signed when a key is found. Returns (manifest, key file or None)."""
    man = read_json('nunext-manifest.json')
    man['files'] = file_hashes(everything)
    man['version'] = version
    man['built'] = datetime.datetime.now().strftime('%Y-%m-%d %H:%M')
    man['libraries'] = libraries()          # bundled libraries with versions: the update page shows which change
    man['requires_libs'] = required_libs()  # library packs this version needs: Check package warns
    man['page_api'] = page_api()            # custom pages: API versions and methods – "Check package" lists pages that would break
    man['plugin_api'] = plugin_api()        # plugins: API versions – "Check package" lists plugins the new version would stop
    man.pop('signature', None)
    man['gated'] = GATE                     # full release gate passed – the update page warns for packages without it
    if GATE:
        man['last_gate'] = version
    versions = [c.get('version') for c in man['changes']]
    at = versions.index(old) if old in versions else 0          # newest first (the list is not strictly sorted further down)
    man['changes'].insert(at, {'version': version, 'title': title, 'text': text})
    write_json('nunext-manifest.json', man)
    keyfile = signing_key()
    if keyfile:
        code, out = run(['php', 'dev/sign.php', 'nunext-manifest.json', keyfile])
        if code != 0:
            sys.exit('signing failed: ' + out)
        man['signature'] = out.strip()
        write_json('nunext-manifest.json', man)
    else:
        print('WARNING: no signing key found – the package is unsigned (the update page will warn)')
    return man, keyfile


INSTALL_HTACCESS = ('# nuBuilder Next – never served directly\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n'
                    '<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n')


def zips(root: str, everything: list, keyfile) -> None:
    """4. nunext.zip (install package) and nunext-dev.zip (everything) next to the project folder."""
    parent = os.path.dirname(root)
    packs = bundled_packs(keyfile)
    for name, sel in (('nunext.zip', [p for p in everything if deployed(p)]), ('nunext-dev.zip', everything)):
        out = os.path.join(parent, name)
        if os.path.exists(out):
            os.remove(out)
        with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
            for p in ['nunext-manifest.json'] + sel:
                z.write(p, 'nunext/' + p)
            if name == 'nunext.zip':
                for f in packs:                  # library packs for the installer: not in the manifest – _install/ is never updated
                    z.write(f, 'nunext/_install/' + os.path.basename(f))
                if packs:                        # the folder is closed to the web from the start
                    z.writestr('nunext/_install/.htaccess', INSTALL_HTACCESS)
                    z.writestr('nunext/_install/index.html', '')
        extra = f' + {len(packs)} library pack(s) in _install/' if name == 'nunext.zip' and packs else ''
        print(f'{name}: {len(sel)} files, {os.path.getsize(out) // 1024} KB' + extra)


def main():
    args = [a for a in sys.argv[1:] if a not in ('--no-checks', '--gate')]
    if len(args) != 3:
        sys.exit(__doc__)
    version, title, changes_file = args[0], args[1].strip(), os.path.abspath(args[2])
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    os.chdir(root)
    text = read_text(changes_file).strip('\n')
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        sys.exit('version must look like 0.8.202')

    app = read_text('core/nuapp.php')
    m = re.search(r"define\('NU_VERSION', '([^']+)'\);", app)
    old = m.group(1)
    if version_key(version) <= version_key(old):
        sys.exit(f'{version} is not higher than the current {old}')
    step(['php', 'dev/lang_variants.php'])                        # lang/en-US.php from lang/en.php, lang/de-CH.php from lang/de.php
    step(['python3', '-I', 'dev/nuruntime_join.py'], True)        # assets/js/nu/nuruntime.js from dev/src/nuruntime/
    if '--no-checks' not in sys.argv:
        checks(all_files())

    changelog(version, title, text)
    write_text('core/nuapp.php', app.replace(m.group(0), f"define('NU_VERSION', '{version}');"))     # 2. version
    step(['php', 'dev/db_changes.php', version])     # 2b. database changes of this version for the update preview (install/db_changes.json)
    step(['python3', '-I', 'dev/minify.py'])         # 2c. minified copies of assets/: nu_asset() serves them outside developer mode

    everything = all_files()
    man, keyfile = manifest(version, old, title, text, everything)
    zips(root, everything, keyfile)
    print(f'{old} → {version}' + (' (signed)' if keyfile else ' (UNSIGNED)') + (' – gated' if GATE else ' – not gated'))
    if not GATE:      # the owner's rule: no gate reminders, only the count
        last = man.get('last_gate', '')
        since = [c['version'] for c in man['changes'] if not last or version_key(c['version']) > version_key(last)]
        print(f'releases since the last gate ({last or "none"}): {len(since)}')


if __name__ == '__main__':
    main()
