/* nuBuilder Next – search (0.8.321), globeadmin only: Ctrl+K (or the magnifier in the top bar) opens a box that finds pages, actions,
   own tiles, forms, procedures and reports (list from ?p=palette, core/nupalette.php). ↑ ↓ choose, Enter opens, Ctrl+Enter in a new tab,
   Esc closes. The last opened entries come first (kept in this browser). window.nuPalette.load() / .match() are used by the start page
   search too (assets/js/nuhome.js). */
(function () {
	'use strict';
	var btn = document.getElementById('nuPaletteBtn');
	if (!btn) return;
	var T = {};
	try { T = JSON.parse(btn.getAttribute('data-t') || '{}'); } catch (e) { T = {}; }
	var t = function (k) { return T[k] || k; };
	var URL = btn.getAttribute('data-url'), VENDOR = btn.getAttribute('data-vendor'), CSRF = btn.getAttribute('data-csrf');
	var RECENT_KEY = 'nunext.palRecent';
	var items = null, loading = null;

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') e.textContent = attrs[k];
			else if (k === 'class') e.className = attrs[k];
			else e.setAttribute(k, attrs[k]);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function store(v) {
		try { if (v === undefined) return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); localStorage.setItem(RECENT_KEY, JSON.stringify(v)); } catch (e) { return []; }
		return [];
	}
	/** Lower case without accents (é → e, ü → u), for matching. */
	function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

	function load() {
		if (items) return Promise.resolve(items);
		if (!loading) {
			loading = fetch(URL, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
				.then(function (r) { return r.json(); })
				.then(function (j) {
					items = (j.items || []).map(function (it) { it.hay = norm(it.label + ' ' + it.group + ' ' + (it.kw || '')); it.lab = norm(it.label); return it; });
					return items;
				})
				.catch(function (e) { loading = null; throw e; });
		}
		return loading;
	}
	/** Entries for a query: every word must appear; the label starting with it first. */
	function match(list, q) {
		var words = norm(q).split(/\s+/).filter(Boolean);
		if (!words.length) return [];
		var res = [];
		list.forEach(function (it) {
			for (var i = 0; i < words.length; i++) if (it.hay.indexOf(words[i]) < 0) return;
			var s = it.lab.indexOf(norm(q).trim()) === 0 ? 0 : (new RegExp('(^|[\\s(/–-])' + words[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).test(it.lab) ? 1 : (it.lab.indexOf(words[0]) >= 0 ? 2 : 3));
			res.push([s, it]);
		});
		res.sort(function (a, b) { return a[0] - b[0] || a[1].label.localeCompare(b[1].label); });
		return res.map(function (r) { return r[1]; });
	}
	function remember(it) {
		var r = store().filter(function (id) { return id !== it.id; });
		r.unshift(it.id);
		store(r.slice(0, 8));
	}
	/** Opens an entry: a link, or a POST to the database tool / File Manager (new tab, as the tile does). */
	function go(it, newTab) {
		remember(it);
		if (it.post) {
			var f = el('form', {method: 'post', action: VENDOR, target: '_blank'}, [el('input', {type: 'hidden', name: '_csrf', value: CSRF}), el('input', {type: 'hidden', name: 'app', value: it.post})]);
			document.body.appendChild(f);
			f.submit();
			f.remove();
			return;
		}
		if (newTab) window.open(it.url, '_blank', 'noopener');
		else window.location.href = it.url;
	}
	window.nuPalette = {load: load, match: match, go: go, norm: norm};

	// ------------------------------------------------------------------ the box
	var modal = null, input, list, status, current = [], active = 0;
	function build() {
		input = el('input', {type: 'search', 'class': 'form-control form-control-lg border-0 shadow-none', id: 'nuPaletteInput', placeholder: t('pal_placeholder'),
			'aria-label': t('pal_title'), autocomplete: 'off', spellcheck: 'false', role: 'combobox', 'aria-expanded': 'true', 'aria-controls': 'nuPaletteList'});
		list = el('div', {'class': 'list-group list-group-flush nuPaletteList', id: 'nuPaletteList', role: 'listbox'});
		status = el('div', {'class': 'small text-body-secondary px-3 py-2', 'aria-live': 'polite'});
		var m = el('div', {'class': 'modal fade', id: 'nuPalette', tabindex: '-1', 'aria-label': t('pal_title'), 'aria-hidden': 'true'}, [
			el('div', {'class': 'modal-dialog modal-lg modal-dialog-scrollable nuPaletteDialog'}, [el('div', {'class': 'modal-content'}, [
				el('div', {'class': 'modal-header py-2 px-3 gap-2'}, [el('i', {'class': 'bi bi-search text-body-secondary', 'aria-hidden': 'true'}), input,
					el('kbd', {'class': 'd-none d-sm-inline small', text: 'Esc'})]),
				el('div', {'class': 'modal-body p-0'}, [list, status]),
				el('div', {'class': 'modal-footer py-1 small text-body-secondary justify-content-start d-none d-sm-flex', text: t('pal_keys')})
			])])
		]);
		document.body.appendChild(m);
		modal = nuBs.modal(m);
		m.addEventListener('shown.bs.modal', function () { input.focus(); input.select(); });
		input.addEventListener('input', render);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (!current.length) return;
				active = (active + (e.key === 'ArrowDown' ? 1 : -1) + current.length) % current.length;
				mark();
			} else if (e.key === 'Enter') {
				e.preventDefault();
				if (current[active]) { var nt = e.ctrlKey || e.metaKey; if (!nt) modal.hide(); go(current[active], nt); }
			}
		});
	}
	function mark() {
		Array.prototype.forEach.call(list.querySelectorAll('[data-i]'), function (a) {
			var on = Number(a.getAttribute('data-i')) === active;
			a.classList.toggle('active', on);
			a.setAttribute('aria-selected', on ? 'true' : 'false');
			if (on) { a.scrollIntoView({block: 'nearest'}); input.setAttribute('aria-activedescendant', a.id); }
		});
	}
	function render() {
		var q = input.value;
		list.textContent = '';
		if (!items) { status.textContent = t('pal_loading'); return; }
		if (q.trim() === '') {
			var byId = {};
			items.forEach(function (it) { byId[it.id] = it; });
			current = store().map(function (id) { return byId[id]; }).filter(Boolean);
			status.textContent = current.length ? '' : t('pal_hint');
		} else {
			// one block per group (pages and actions first, then the group with the best hit), the best hits on top inside a group
			var found = match(items, q).slice(0, 60), order = [], blocks = {};
			found.forEach(function (it) { if (!blocks[it.group]) { blocks[it.group] = []; order.push(it.group); } blocks[it.group].push(it); });
			current = [].concat.apply([], order.map(function (g) { return blocks[g]; }));
			status.textContent = current.length ? '' : t('pal_none');
		}
		active = 0;
		var lastGroup = null;
		current.forEach(function (it, i) {
			var g = q.trim() === '' ? t('pal_recent') : it.group;
			if (g !== lastGroup) { list.appendChild(el('div', {'class': 'nuPaletteGroup', text: g})); lastGroup = g; }
			var a = el('a', {'class': 'list-group-item list-group-item-action d-flex align-items-center gap-3', href: it.url || '#', id: 'nuPal_' + i, 'data-i': String(i), role: 'option'}, [
				el('i', {'class': 'bi ' + it.icon + ' text-body-secondary', 'aria-hidden': 'true'}),
				el('span', {'class': 'flex-grow-1 text-truncate', text: it.label}),
				el('span', {'class': 'small text-body-secondary text-truncate nuPaletteKw', text: q.trim() === '' ? it.group : (it.id.charAt(0) !== 't' ? (it.kw || '').split(' ')[0] : '')})
			]);
			a.addEventListener('click', function (e) { e.preventDefault(); var nt = e.ctrlKey || e.metaKey; if (!nt) modal.hide(); go(it, nt); });
			a.addEventListener('mousemove', function () { if (active !== i) { active = i; mark(); } });
			list.appendChild(a);
		});
		mark();
	}
	function openBox() {
		if (!modal) build();
		input.value = '';
		render();
		modal.show();
		load().then(render).catch(function () { status.textContent = t('pal_failed'); });
	}
	btn.addEventListener('click', openBox);
	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K')) {
			if (document.querySelector('.modal.show') && !(modal && document.getElementById('nuPalette').classList.contains('show'))) return;   // another dialog is open
			e.preventDefault();
			openBox();
		}
	});
})();
