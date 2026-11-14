#!/usr/bin/env python3
"""FullCalendar for nuBuilder Next (0.8.1165): python3 -I dev/fullcalendar_build.py <npm fullcalendar package dir> <core package dir> <out dir>

FullCalendar 6 puts its CSS into the page with a <style> element at run time – the strict CSP (style-src 'self') blocks that.
This script takes the five CSS strings out into fullcalendar.css, makes injectStyles / ensureElHasStyles do nothing and
minifies the result (npx terser), plus locales-all.global.min.js of @fullcalendar/core and the licence."""
import json, re, shutil, subprocess, sys
from pathlib import Path

src, core, out = Path(sys.argv[1]), Path(sys.argv[2]), Path(sys.argv[3])
out.mkdir(parents=True, exist_ok=True)
js = (src / 'index.global.js').read_text(encoding='utf-8')
css = [json.loads(m.group(1)) for m in re.finditer(r'var css_248z(?:\$\d+)? = ("(?:[^"\\]|\\.)*");', js)]
if len(css) < 5:
    sys.exit('CSS strings not found')
(out / 'fullcalendar.css').write_text('/* FullCalendar ' + json.loads((src / 'package.json').read_text())['version'] + ' (MIT) – CSS taken out of the JS for the strict CSP */\n' + '\n'.join(css) + '\n', encoding='utf-8')
for fn in ('function injectStyles(styleText) {', 'function ensureElHasStyles(el) {'):
    if fn not in js:
        sys.exit('not found: ' + fn)
    js = js.replace(fn, fn + ' return;', 1)
tmp = out / '_fc.js'
tmp.write_text(js, encoding='utf-8')
npx = shutil.which('npx')
if npx is None:
    sys.exit('npx not found (Node.js is needed to minify)')
res = subprocess.run([npx, '--yes', 'terser@5', str(tmp), '-c', '-m', '--comments', '/^!/'], capture_output=True, text=True, check=True)
(out / 'fullcalendar.min.js').write_text(res.stdout, encoding='utf-8')
tmp.unlink()
shutil.copy(core / 'locales-all.global.min.js', out / 'locales-all.min.js')
shutil.copy(src / 'LICENSE.md', out / 'LICENSE.md')
print('ok', out)
