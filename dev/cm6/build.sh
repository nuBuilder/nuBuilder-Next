#!/bin/sh
# Builds vendor/bundled/codemirror-<version>/ from dev/cm6/ (CodeMirror 6 + the CodeMirror 5 compatible adapter adapter.js).
# Needs Node + npm (registry.npmjs.org). Usage: sh dev/cm6/build.sh  (run from the project folder; work folder: $TMPDIR or /tmp)
set -e
SRC=$(cd "$(dirname "$0")" && pwd); ROOT=$(cd "$SRC/../.." && pwd)
W=${TMPDIR:-/tmp}/nunext-cm6-build; rm -rf "$W"; mkdir -p "$W"
cp "$SRC/package.json" "$W/"
(cd "$W" && npm install --no-audit --no-fund --silent && npm install --no-save --no-audit --no-fund --silent esbuild@0.28.2)
# strict CSP (style-src 'self'): style-mod puts CodeMirror's base styles into a <style> element on a document, which the CSP blocks –
# use a constructed style sheet (document.adoptedStyleSheets, not subject to the CSP) on documents too
for f in "$W/node_modules/style-mod/src/style-mod.js" "$W/node_modules/style-mod/dist/style-mod.cjs"; do
	grep -q '!root.head && root.adoptedStyleSheets' "$f" || { echo "style-mod changed – check the CSP patch in dev/cm6/build.sh" >&2; exit 1; }
	sed -i 's/!root.head && root.adoptedStyleSheets/root.adoptedStyleSheets/' "$f"
done
VER=$(node -p "require('$W/node_modules/@codemirror/view/package.json').version")
VERS=$(node -e "const p=require('$W/package.json');const o={};for(const k of Object.keys(p.dependencies))o[k]=require('$W/node_modules/'+k+'/package.json').version;console.log(JSON.stringify(o))")
OUT="$ROOT/vendor/bundled/codemirror-$VER"; mkdir -p "$OUT"
cp "$SRC/adapter.js" "$W/adapter.js"
"$W/node_modules/.bin/esbuild" "$W/adapter.js" --bundle --minify --format=iife --target=es2017 --legal-comments=none \
	"--define:__CM6_VERSIONS__=$VERS" --outfile="$W/bundle.js" --log-level=warning
{ echo "/*! CodeMirror $VER (MIT) – CodeMirror 6 packages $VERS, with the CodeMirror 5 compatible adapter of nuBuilder Next (dev/cm6/adapter.js) */"; cat "$W/bundle.js"; } > "$OUT/codemirror-bundle.js"
cp "$SRC/codemirror.css" "$OUT/codemirror.css"
echo "/* CodeMirror $VER: everything is in codemirror-bundle.js – this file stays for pages that still load it (CodeMirror 5 had its add-ons here). */" > "$OUT/codemirror-addons.js"
cp "$W/node_modules/@codemirror/view/LICENSE" "$OUT/LICENSE"
echo "→ $OUT"
