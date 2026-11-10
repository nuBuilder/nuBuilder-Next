#!/usr/bin/env python3
"""
Builds the source folder of the TCPDF 7 library pack from GitHub (no Packagist needed): TCPDF and the tc-lib-* libraries it
requires, each at the highest tag its composer.json allows, installed by Composer from local clones (path repositories), so
vendor/composer/ (autoloader, installed.json) is Composer's own.

    python3 -I dev/tcpdf_assemble.py <out folder> [TCPDF tag, default: the highest 7.x]
    php dev/libpack.php <packs folder> ../nunext-signing.key tcpdf=<out folder> --keep=<libraries.json from GitHub>

<out>/VERSION · README-nuBuilder-Next.txt · composer.json · composer.lock · vendor/ (composer/, tecnickcom/tcpdf, tecnickcom/tc-lib-*)
Needs git, composer and PHP 8.2+ (the font converter of tc-lib-pdf-font runs here).
"""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

ORG = 'tecnickcom'
REPO = {'tcpdf': 'TCPDF'}          # package name → repository name where they differ


def run(*a, cwd=None, quiet=True):
    return subprocess.run(a, cwd=cwd, check=True, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE if quiet else None).stdout


def tags(pkg):
    out = run('git', 'ls-remote', '--tags', f'https://github.com/{ORG}/{REPO.get(pkg, pkg)}.git')
    found = set()
    for line in out.splitlines():
        m = re.search(r'refs/tags/v?(\d+)\.(\d+)\.(\d+)$', line)
        if m:
            found.add(tuple(int(x) for x in m.groups()))
    return sorted(found)


def pick(pkg, rule):
    """Highest tag for a caret rule (^8.84 / ^8.84.1) or the exact tag."""
    all_tags = tags(pkg)
    m = re.match(r'^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$', rule.strip())
    if m:
        low = (int(m.group(1)), int(m.group(2) or 0), int(m.group(3) or 0))
        ok = [t for t in all_tags if t[0] == low[0] and t >= low]
    else:
        want = tuple(int(x) for x in rule.strip().lstrip('v').split('.'))
        ok = [t for t in all_tags if t == want]
    if not ok:
        sys.exit(f'{pkg}: no tag for "{rule}"')
    return '.'.join(str(x) for x in ok[-1])


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    out = os.path.abspath(sys.argv[1])
    work = tempfile.mkdtemp(prefix='nunext-tcpdf-')
    todo = {'tcpdf': sys.argv[2] if len(sys.argv) > 2 else '^7.0'}
    done = {}
    while todo:
        pkg, rule = todo.popitem()
        ver = pick(pkg, rule)
        dest = os.path.join(work, 'src', pkg)
        run('git', 'clone', '-q', '--depth', '1', '--branch', ver, f'https://github.com/{ORG}/{REPO.get(pkg, pkg)}.git', dest)
        shutil.rmtree(os.path.join(dest, '.git'))
        done[pkg] = ver
        with open(os.path.join(dest, 'composer.json'), encoding='utf-8') as f:
            req = json.load(f).get('require', {})
        for name, r in req.items():
            if name.startswith(ORG + '/'):
                short = name.split('/', 1)[1]
                if short not in done and short not in todo:
                    todo[short] = r
        print(f'  {pkg} {ver}')

    proj = os.path.join(work, 'proj')
    os.makedirs(proj)
    repos = [{'packagist.org': False}]
    for pkg, ver in sorted(done.items()):
        repos.append({'type': 'path', 'url': os.path.join(work, 'src', pkg), 'options': {'symlink': False, 'versions': {f'{ORG}/{pkg}': ver}}})
    with open(os.path.join(proj, 'composer.json'), 'w', encoding='utf-8') as f:
        json.dump({'name': 'nunext/tcpdf-pack', 'description': 'TCPDF for nuBuilder Next', 'require': {f'{ORG}/tcpdf': done['tcpdf']},
                   'repositories': repos, 'config': {'optimize-autoloader': True, 'platform-check': True}}, f, indent=4)
    env = dict(os.environ, COMPOSER_ALLOW_SUPERUSER='1', COMPOSER_DISABLE_NETWORK='1', COMPOSER_NO_INTERACTION='1')
    subprocess.run(['composer', 'install', '--no-dev', '--no-progress', '--ignore-platform-req=ext-*', '-q'], cwd=proj, check=True, env=env)

    # the pack carries no absolute paths of this computer: the lock file and installed.json keep only names and versions
    for rel in ('composer.json', 'composer.lock', os.path.join('vendor', 'composer', 'installed.json')):
        p = os.path.join(proj, rel)
        with open(p, encoding='utf-8') as fh:
            s = fh.read().replace(json.dumps(os.path.join(work, 'src'))[1:-1], 'src').replace(os.path.join(work, 'src'), 'src')
        with open(p, 'w', encoding='utf-8') as fh:
            fh.write(s)
    # the fonts (tc-lib-pdf-font/target/fonts) are built from tecnickcom/tc-font-mirror with the library's own converter; kept: the
    # families nuBuilder's reports use (core, dejavu, freefont, pdfa) - unifont and cid0 would add 25 MB
    fontlib = os.path.join(proj, 'vendor', ORG, 'tc-lib-pdf-font')
    mirror = os.path.join(fontlib, 'util', 'vendor', ORG, 'tc-font-mirror')
    mver = '.'.join(str(x) for x in tags('tc-font-mirror')[-1])
    run('git', 'clone', '-q', '--depth', '1', '--branch', mver, f'https://github.com/{ORG}/tc-font-mirror.git', mirror)
    subprocess.run(['php', 'bulk_convert.php'], cwd=os.path.join(fontlib, 'util'), check=True, stdout=subprocess.DEVNULL)
    shutil.rmtree(os.path.join(fontlib, 'util', 'vendor'))
    for extra in ('unifont', 'cid0'):
        shutil.rmtree(os.path.join(fontlib, 'target', 'fonts', extra), ignore_errors=True)
    print(f'  fonts from tc-font-mirror {mver}: ' + ', '.join(sorted(os.listdir(os.path.join(fontlib, 'target', 'fonts')))))
    # tests, examples and tooling of the libraries are not needed to make PDFs
    for pkg in done:
        base = os.path.join(proj, 'vendor', ORG, pkg)
        for extra in ('test', 'tests', 'example', 'examples', 'resources', '.github', 'doc', 'docs', 'tools', 'Makefile', 'phpunit.xml.dist', 'phpstan.neon'):
            p = os.path.join(base, extra)
            if pkg == 'tcpdf' and extra in ('tools',):
                continue
            if os.path.isdir(p):
                shutil.rmtree(p)
            elif os.path.isfile(p):
                os.remove(p)
    with open(os.path.join(proj, 'VERSION'), 'w') as f:
        f.write(done['tcpdf'] + '\n')
    with open(os.path.join(proj, 'README-nuBuilder-Next.txt'), 'w', encoding='utf-8') as f:
        f.write('TCPDF ' + done['tcpdf'] + ' for nuBuilder Next - a Composer folder built from the GitHub repositories of tecnickcom\n'
                '(dev/tcpdf_assemble.py). TCPDF 7 is a facade over tc-lib-pdf and needs PHP 8.2 or newer.\n\n'
                + ''.join(f'  {ORG}/{k} {v}\n' for k, v in sorted(done.items())))
    if os.path.exists(out):
        shutil.rmtree(out)
    shutil.copytree(proj, out)
    shutil.rmtree(work, ignore_errors=True)
    print('->', out)


if __name__ == '__main__':
    main()
