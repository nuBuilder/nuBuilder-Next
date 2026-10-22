/* nuBuilder Next – "#" menu of the code editors: inserts #HASH_VARIABLES# at the cursor of a CodeMirror editor.
 * Used by the form runtime (double-click code editor) and the form builder.
 *
 *   NuHashVars.attach(cm, host, {catalog: {...}, fields: function () { return Promise|Array of [name, label] }, globals: {name: value}})
 *
 * catalog comes from the server (NuHash::catalog(): translated groups of [name, description]). No inline scripts (CSP).
 */
(function () {
	'use strict';

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') e.textContent = attrs[k];
			else if (k === 'class') e.className = attrs[k];
			else e.setAttribute(k, attrs[k]);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(c); });
		return e;
	}

	function attach(cm, host, opt) {
		opt = opt || {};
		var cat = opt.catalog;
		if (!cm || !host || !cat || !nuBs.ready()) return null;

		var wrap = el('div', {'class': 'dropdown nuHashVars'});
		var btn = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary nuHashVarsBtn', 'data-bs-toggle': 'dropdown', 'data-bs-auto-close': 'outside',
			'aria-expanded': 'false', title: cat.title, 'aria-label': cat.title, text: '#'});
		var search = el('input', {type: 'search', 'class': 'form-control form-control-sm', placeholder: cat.search, 'aria-label': cat.search, autocomplete: 'off'});
		var list = el('div', {'class': 'nuHashVarsList', role: 'listbox'});
		var menu = el('div', {'class': 'dropdown-menu dropdown-menu-end p-2 nuHashVarsMenu'}, [
			el('div', {'class': 'small text-body-secondary mb-1', text: cat.hint || ''}), search, list]);
		wrap.appendChild(btn);
		wrap.appendChild(menu);
		host.appendChild(wrap);
		var dd = nuBs.dropdown(btn);

		var groups = (cat.groups || []).slice();
		var loaded = false;

		function insert(name) {
			dd.hide();
			cm.replaceSelection('#' + name + '#');
			cm.focus();
		}

		function render() {
			var q = search.value.replace(/#/g, '').trim().toLowerCase();     // "#USER_ID#" finds USER_ID (names are stored without #)
			list.innerHTML = '';
			var count = 0;
			groups.forEach(function (g) {
				var items = (g.items || []).filter(function (it) {
					return !q || String(it[0]).toLowerCase().indexOf(q) >= 0 || String(it[1] || '').toLowerCase().indexOf(q) >= 0;
				});
				if (!items.length) return;
				list.appendChild(el('div', {'class': 'dropdown-header px-1', text: g.title}));
				items.forEach(function (it) {
					var b = el('button', {type: 'button', 'class': 'dropdown-item d-flex gap-2 align-items-baseline px-1', role: 'option', 'data-name': it[0]}, [
						el('code', {text: '#' + it[0] + '#'}),
						it[1] ? el('span', {'class': 'small text-body-secondary text-truncate', text: it[1]}) : null
					]);
					b.addEventListener('click', function () { insert(it[0]); });
					list.appendChild(b);
					count++;
				});
			});
			if (!count) list.appendChild(el('div', {'class': 'small text-body-secondary px-1 py-2', text: cat.empty}));
		}

		function loadExtras() {
			if (loaded) return Promise.resolve();
			loaded = true;
			var globals = opt.globals || {};
			var gl = Object.keys(globals).sort().map(function (k) { return [k, String(globals[k]).slice(0, 60)]; });
			var p = typeof opt.fields === 'function' ? opt.fields() : opt.fields;
			return Promise.resolve(p).then(function (fields) {
				if (fields && fields.length) groups.unshift({title: cat.fields, items: fields});
				if (gl.length) groups.push({title: cat.globals, items: gl});
			}, function () { /* fields are optional */ });
		}

		btn.addEventListener('show.bs.dropdown', function () { search.value = ''; render(); loadExtras().then(render); });
		btn.addEventListener('shown.bs.dropdown', function () { search.focus(); });
		search.addEventListener('input', render);
		search.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); var first = list.querySelector('[data-name]'); if (first) insert(first.getAttribute('data-name')); }
			if (e.key === 'ArrowDown') { e.preventDefault(); var f = list.querySelector('[data-name]'); if (f) f.focus(); }
			if (e.key === 'Escape') { e.stopPropagation(); dd.hide(); cm.focus(); }
		});
		menu.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); dd.hide(); cm.focus(); } });
		return wrap;
	}

	window.NuHashVars = {attach: attach};
})();
