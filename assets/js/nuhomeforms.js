/* global nuBs */
/*
 * Home forms of the access levels (0.8.1264, core/nuhomeforms.php): the ▾ next to the Home button (globeadmin) opens a panel with
 * one row per Home form – its access levels as chips (dashed = the level starts on the tile page), Open / Design – and, in a second tab
 * (0.8.1276), the tile pages of users with their levels; loaded once from ?p=homeforms. Right-click or a long press on the Home button opens the same panel; a plain click still goes Home.
 */
(function () {
	'use strict';
	var tog = document.getElementById('nuHfToggle');
	var panel = document.getElementById('nuHfPanel');
	if (!tog || !panel) return;
	var data = null, loading = false;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined && text !== null) e.textContent = text;
		return e;
	}
	function fmt(s) {
		var a = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(s).replace(/%[sd]/g, function () { return String(a[i++]); });
	}
	function norm(s) { return String(s || '').toLowerCase(); }

	var TAB_KEY = 'nunext-hf-tab';
	function savedTab() { try { return localStorage.getItem(TAB_KEY) === 'tiles' ? 'tiles' : 'forms'; } catch (e) { return 'forms'; } }

	// one list row (a Home form or a tile page): title + count, folded code / chips, Open (+ Design)
	function makeRow(f, T, chipUrl) {
		var row = el('div', 'nuHfRow');
		row.setAttribute('data-nu-hf', f.id);
		if (f.kind) row.setAttribute('data-nu-hf-kind', f.kind);
		var hay = [f.title, f.code];
		var main = el('div', 'nuHfMain');
		// collapsed by default (0.8.1266): the title only; the button folds out the code and the access levels
		var t = el('button', 'nuHfName nuHfExp');
		t.type = 'button';
		t.setAttribute('aria-expanded', 'false');
		t.appendChild(el('i', 'bi bi-chevron-right nuHfChev'));
		if (f.kind) t.appendChild(el('i', 'bi bi-grid-3x3-gap nuHfTilesIco'));
		var tt = el('span', 'nuHfFormTitle', f.title);
		tt.title = f.title;
		t.appendChild(tt);
		if (f.empty) {   // 0.8.1279: an own tile page without tiles
			var em = el('span', 'nuHfEmptyTag', T.hf_empty);
			em.title = T.hf_empty_tip;
			t.appendChild(em);
		}
		var cnt = el('span', 'nuHfLvCount', String(f.levels.length));
		cnt.title = T.hf_levels_link;
		t.appendChild(cnt);
		main.appendChild(t);
		var det = el('div', 'nuHfDetail');
		det.hidden = true;
		if (f.code) det.appendChild(el('code', 'nuHfCode', f.code));
		if (f.note) det.appendChild(el('div', 'nuHfNote', f.note));
		var chips = el('div', 'nuHfChips');
		f.levels.forEach(function (l) {
			var c = el('a', 'nuHfChip' + (l.mode === 'form' ? '' : ' nuHfChipTiles'), l.code || l.title);
			c.href = chipUrl(l);
			c.title = (l.title ? l.title + ' – ' : '') + (l.mode === 'form' ? T.hf_mode_form : T.hf_mode_tiles) + ' · ' + fmt(T.hf_users, l.users);
			c.setAttribute('data-nu-hf-level', l.id);
			chips.appendChild(c);
			hay.push(l.code, l.title);
		});
		det.appendChild(chips);
		main.appendChild(det);
		t.addEventListener('click', function () { setOpen(row, det.hidden); row.setAttribute('data-nu-hf-open', det.hidden ? '' : '1'); });
		row.appendChild(main);
		var acts = el('div', 'nuHfActs');
		if (f.open) {
			// icon buttons (0.8.1278): the row stays one line on phones too
			var o = el('a', 'btn btn-sm btn-primary nuHfOpen nuHfIcon');
			o.href = f.open;
			o.title = T.hf_open; o.setAttribute('aria-label', T.hf_open + ': ' + f.title);
			o.appendChild(el('i', 'bi bi-box-arrow-up-right')).setAttribute('aria-hidden', 'true');
			acts.appendChild(o);
		}
		if (f.design) {
			var d = el('a', 'btn btn-sm btn-outline-secondary nuHfDesign nuHfIcon');
			d.href = f.design;
			d.title = T.hf_design; d.setAttribute('aria-label', T.hf_design + ': ' + f.title);
			d.appendChild(el('i', 'bi bi-pencil')).setAttribute('aria-hidden', 'true');
			acts.appendChild(d);
		}
		row.appendChild(acts);
		row.setAttribute('data-nu-hf-hay', norm(hay.join(' ')));
		return row;
	}

	function render() {
		var T = data.texts;
		panel.textContent = '';
		var head = el('div', 'nuHfHead');
		head.appendChild(el('b', 'nuHfTitle', T.hf_panel_title));
		if (data.userhome) {   // User Home in the head (0.8.1282): one tap on both tabs, never scrolled away
			var uh = el('a', 'btn btn-sm btn-outline-primary nuHfUh');
			uh.id = 'nuHfUserHome'; uh.href = data.userhome.open;
			uh.appendChild(el('i', 'bi bi-window')).setAttribute('aria-hidden', 'true');
			uh.appendChild(el('span', '', T.home_userhome));
			head.appendChild(uh);
			if (data.userhome.design) {
				var ud = el('a', 'btn btn-sm btn-outline-secondary nuHfUhDesign');
				ud.id = 'nuHfUserHomeDesign'; ud.href = data.userhome.design;
				ud.title = T.hf_design; ud.setAttribute('aria-label', T.hf_design + ': ' + T.home_userhome);
				ud.appendChild(el('i', 'bi bi-pencil')).setAttribute('aria-hidden', 'true');
				head.appendChild(ud);
			}
		}
		var q = el('input', 'form-control form-control-sm nuHfSearch');
		q.type = 'search'; q.id = 'nuHfSearch';
		// tabs Home forms / Tile pages (0.8.1276) – the last one is remembered on the device
		var tabs = el('div', 'nuHfTabs');
		tabs.setAttribute('role', 'tablist');
		var pages = data.pages || [];
		var defs = {forms: [T.hf_title, data.forms.length], tiles: [T.hf_tab_tiles, pages.length]};
		var lists = {}, btns = {}, cur = savedTab();
		Object.keys(defs).forEach(function (k) {
			var b = el('button', 'nuHfTab');
			b.type = 'button'; b.id = 'nuHfTab_' + k;
			b.setAttribute('role', 'tab');
			b.setAttribute('data-tab', k);
			b.setAttribute('aria-controls', 'nuHfList_' + k);
			b.appendChild(el('span', '', defs[k][0]));
			b.appendChild(el('span', 'nuHfTabCount', String(defs[k][1])));
			b.addEventListener('click', function () { show(k, true); });
			tabs.appendChild(b);
			btns[k] = b;
		});
		head.appendChild(tabs);
		head.appendChild(q);
		panel.appendChild(head);

		function list(k, rows, chipUrl, emptyText) {
			var l = el('div', 'nuHfList');
			l.id = 'nuHfList_' + k;
			l.setAttribute('role', 'tabpanel');
			l.setAttribute('aria-labelledby', 'nuHfTab_' + k);
			if (!rows.length) l.appendChild(el('div', 'nuHfEmpty', emptyText));
			rows.forEach(function (f) { l.appendChild(makeRow(f, T, chipUrl)); });
			var none = el('div', 'nuHfEmpty nuHfNoMatch', T.hf_nomatch);
			none.hidden = true;
			l.appendChild(none);
			panel.appendChild(l);
			lists[k] = l;
		}
		list('forms', data.forms, function (l) { return l.url; }, T.hf_none);
		list('tiles', pages.map(function (p) {
			return {id: p.id, title: p.title, kind: p.kind, open: p.open, levels: p.levels, empty: !!p.empty,
				note: p.kind === 'default' ? T.hf_tiles_default : (p.kind === 'auto' ? T.hf_tiles_auto : '')};
		}), function (l) { return l.tiles; }, '');

		var foot = el('div', 'nuHfFoot');
		var lv = el('a', '', T.hf_levels_link); lv.href = data.levels_url; foot.appendChild(lv);
		var keysHint = el('span', 'nuHfKeys d-none d-md-inline', T.hf_keys);
		foot.appendChild(keysHint);
		panel.appendChild(foot);

		function filter() {
			var v = norm(q.value.trim()), l = lists[cur], n = 0;
			l.querySelectorAll('.nuHfRow').forEach(function (r) {
				var on = !v || r.getAttribute('data-nu-hf-hay').indexOf(v) >= 0;
				r.hidden = !on;
				if (on) n++;
				setOpen(r, v !== '' ? on : r.getAttribute('data-nu-hf-open') === '1');   // searching shows the code and levels of the hits
			});
			l.querySelector('.nuHfNoMatch').hidden = n > 0 || !l.querySelector('.nuHfRow');
		}
		function show(k, focus) {
			cur = k;
			Object.keys(lists).forEach(function (x) {
				lists[x].hidden = x !== k;
				btns[x].classList.toggle('active', x === k);
				btns[x].setAttribute('aria-selected', x === k ? 'true' : 'false');
				btns[x].tabIndex = x === k ? 0 : -1;
			});
			var ph = k === 'tiles' ? T.hf_search_tiles : T.hf_search;
			q.placeholder = ph; q.setAttribute('aria-label', ph);
			keysHint.textContent = k === 'tiles' ? T.hf_keys_tiles : T.hf_keys;
			try { localStorage.setItem(TAB_KEY, k); } catch (e) { /* storage blocked */ }
			filter();
			if (focus) focusSearch();
		}
		tabs.addEventListener('keydown', function (e) {
			if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
			e.preventDefault();
			var k = cur === 'forms' ? 'tiles' : 'forms';
			show(k, false);
			btns[k].focus();
		});
		q.addEventListener('input', filter);
		panel.addEventListener('keydown', keys);
		show(cur, false);
		focusSearch();
	}

	function setOpen(row, on) {
		var d = row.querySelector('.nuHfDetail'), b = row.querySelector('.nuHfExp');
		if (!d || !b) return;
		d.hidden = !on;
		b.setAttribute('aria-expanded', on ? 'true' : 'false');
		row.classList.toggle('nuHfOpenRow', on);
	}
	function opens() { return Array.prototype.filter.call(panel.querySelectorAll('.nuHfOpen'), function (a) { return !a.closest('.nuHfRow').hidden && !a.closest('.nuHfList').hidden; }); }
	function keys(e) {
		if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
		var all = opens();
		if (!all.length) return;
		var i = all.indexOf(document.activeElement);
		e.preventDefault();
		if (e.key === 'ArrowDown') (all[i + 1] || all[0]).focus();
		else if (i <= 0) document.getElementById('nuHfSearch').focus();
		else all[i - 1].focus();
	}
	function focusSearch() {
		var q = document.getElementById('nuHfSearch');
		if (q && (!window.nuHasKeyboard || window.nuHasKeyboard())) q.focus();
	}

	function load() {
		if (data) { focusSearch(); return; }
		if (loading) return;
		loading = true;
		fetch(tog.getAttribute('data-url'), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (j) { if (j && j.ok) { data = j; render(); } })
			.catch(function () { /* the panel keeps its "…" */ })
			.then(function () { loading = false; });
	}
	tog.addEventListener('show.bs.dropdown', load);
	function open() { nuBs.dropdown(tog).show(); }
	window.nuHomeFormsOpen = open;

	// right-click / long press on the Home button (compact top bar) opens the panel
	var home = document.getElementById('nuHomeBtn');
	if (home && home.closest('.nuHfGroup')) {
		home.addEventListener('contextmenu', function (e) { e.preventDefault(); open(); });
		var timer = null, fired = false;
		home.addEventListener('touchstart', function () {
			fired = false;
			timer = setTimeout(function () { fired = true; open(); }, 550);
		}, {passive: true});
		['touchend', 'touchmove', 'touchcancel'].forEach(function (ev) {
			home.addEventListener(ev, function () { clearTimeout(timer); }, {passive: true});
		});
		home.addEventListener('click', function (e) { if (fired) { e.preventDefault(); fired = false; } });
	}
})();
