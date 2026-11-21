#!/usr/bin/env python3
"""Minified copies of nuBuilder Next's own scripts and style sheets (0.8.1342, review P3).

    python3 dev/minify.py          writes assets/**/<name>.min.js / .min.css next to each source and assets/min.json
    python3 dev/minify.py --clean  removes them again

Run by dev/release.py before the manifest, so both the readable source and the minified copy are in the package. nu_asset()
(core/nuapp.php) serves the copy listed in assets/min.json while the source still has the size recorded there – developer mode
always gets the source. Tools (outside the project, like ESLint): ../node_modules/.bin/terser and ../node_modules/.bin/lightningcss
  npm install --no-save --prefix .. eslint@9.39.5 globals@15.14.0 terser@5.36.0 lightningcss-cli@1.28.2
Without them the copies and assets/min.json are removed, so every page uses the sources.
"""
import json
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BIN = os.path.join(os.path.dirname(ROOT), 'node_modules', '.bin')
TERSER = os.path.join(BIN, 'terser')
LCSS = os.path.join(BIN, 'lightningcss')
LIST = os.path.join(ROOT, 'assets', 'min.json')


def sources():
    out = []
    for d, dirs, files in os.walk(os.path.join(ROOT, 'assets')):
        dirs[:] = [x for x in dirs if x not in ('vendor', 'img', 'fonts')]
        for f in files:
            if (f.endswith('.js') and not f.endswith('.min.js')) or (f.endswith('.css') and not f.endswith('.min.css')):
                out.append(os.path.relpath(os.path.join(d, f), ROOT).replace(os.sep, '/'))
    return sorted(out)


def min_path(p):
    base, ext = os.path.splitext(p)
    return base + '.min' + ext


def clean():
    n = 0
    for d, _dirs, files in os.walk(os.path.join(ROOT, 'assets')):
        for f in files:
            if f.endswith('.min.js') or f.endswith('.min.css'):
                os.remove(os.path.join(d, f))
                n += 1
    if os.path.exists(LIST):
        os.remove(LIST)
    return n


def minify(p):
    src = os.path.join(ROOT, p)
    dst = os.path.join(ROOT, min_path(p))
    if p.endswith('.js'):
        # global names stay (form code of nuBuilder Forte calls the functions of nuruntime.js / nucompat.js by name): no top-level mangling
        cmd = [TERSER, src, '--compress', 'passes=1', '--mangle', '--comments', 'false', '--output', dst]
    else:
        cmd = [LCSS, '--minify', src, '-o', dst]
    r = subprocess.run(cmd, capture_output=True, text=True, check=False)
    if r.returncode != 0 or not os.path.isfile(dst):
        raise RuntimeError(f'{p}: ' + (r.stderr or r.stdout).strip())
    return os.path.getsize(src), os.path.getsize(dst)


def main():
    if '--clean' in sys.argv:
        print(f'minify: {clean()} file(s) removed')
        return 0
    if not (os.path.isfile(TERSER) and os.path.isfile(LCSS)):
        n = clean()
        print(f'minify: terser / lightningcss not found in {BIN} – sources only ({n} old copies removed)')
        return 0
    clean()
    listing, before, after = {}, 0, 0
    for p in sources():
        a, b = minify(p)
        if b >= a:                      # nothing gained (a tiny file): no copy
            os.remove(os.path.join(ROOT, min_path(p)))
            continue
        listing[p] = a                  # the size of the source the copy was made from (nu_asset: a source changed since = no copy)
        before += a
        after += b
    with open(LIST, 'w', encoding='utf-8') as f:
        json.dump(listing, f, indent=0, sort_keys=True)
        f.write('\n')
    print(f'minify: {len(listing)} files, {before // 1024} KB → {after // 1024} KB')
    return 0


if __name__ == '__main__':
    sys.exit(main())
