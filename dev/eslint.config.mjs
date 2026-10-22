// ESLint for assets/js (B3 of the 0.8.207 review):  npx eslint -c dev/eslint.config.mjs assets/js
// Only real mistakes: undefined names, duplicate keys, unreachable code … – no style rules. The files are plain browser scripts.
// The nuBuilder API (nuGetValue, nuSetProperty …) and the shared helpers (NuFormatLib …) are globals that the runtime defines
// with api('nu…', …) / window.Nu… = …; they are collected from the sources here, so the list never goes stale.
import fs from 'node:fs';
import path from 'node:path';
import globals from 'globals';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const runtime = {};
const scan = dir => fs.readdirSync(dir, { withFileTypes: true }).forEach(e => {
	const p = path.join(dir, e.name);
	if (e.isDirectory()) { if (e.name !== 'vendor') scan(p); return; }
	if (!p.endsWith('.js')) return;
	const src = fs.readFileSync(p, 'utf8');
	for (const re of [/api\('(nu\w+)'/g, /window\.([Nn]u\w+)\s*=/g, /proto\(\w+, '(nu\w+)'/g])
		for (const m of src.matchAll(re)) runtime[m[1]] = 'writable';
});
scan(path.join(root, 'assets/js'));

export default [
	{
		files: ['**/*.js'],
		ignores: ['assets/vendor/**'],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: {
				...globals.browser, ...runtime,
				bootstrap: 'readonly', nuBs: 'readonly', ApexCharts: 'readonly', tinymce: 'readonly', CodeMirror: 'readonly', qrcode: 'readonly', TomSelect: 'readonly',
				google: 'readonly', js_beautify: 'readonly', css_beautify: 'readonly', html_beautify: 'readonly', sqlFormatter: 'readonly',
				$: 'readonly', jQuery: 'readonly',
				// nuBuilder variables that form code may define (nuBuilder sets them on its pages)
				nuFORM: 'writable', nuDebugMode: 'writable', nuUserA11Y: 'writable', nuGlobalAccess: 'writable', nuCurrentProperties: 'writable',
			},
		},
		rules: {
			'no-undef': 'error', 'no-dupe-keys': 'error', 'no-unreachable': 'error', 'no-dupe-args': 'error', 'no-func-assign': 'error',
			'no-self-assign': 'error', 'valid-typeof': 'error', 'no-cond-assign': ['error', 'except-parens'], 'use-isnan': 'error',
			'no-unsafe-finally': 'error', 'no-sparse-arrays': 'error',
		},
		linterOptions: { reportUnusedDisableDirectives: 'off' },
	},
];
