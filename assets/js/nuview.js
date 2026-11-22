/* View mode of a record (core/nurecordview.php): a read-only data sheet of everything the page shows – every tab at once, fields as
 * text, sub-forms as small tables, no inputs, buttons or links. Built from the page itself, so what the form's code hides stays out,
 * masked fields stay masked. Opened by #nuViewButton, Ctrl+Shift+V or ?view=1; Esc goes back to editing, ← / → step through the
 * records of the list the record was opened from (nuapp.js keeps that list in sessionStorage nunext-rv-list).
 * Form pages: built after the form's nuOnLoad (event nu:pageload). Own record pages: the form with data-nu-view-root. */
(function () {
	'use strict';
	var btn = document.getElementById('nuViewButton');
	if (!btn) return;
	var cfg = {};
	try { cfg = JSON.parse(btn.getAttribute('data-nu-view') || '{}') || {}; } catch (e) { cfg = {}; }
	var T = cfg.texts || {};
	var isForm = !!(document.body && document.body.dataset.nuPage === 'form');
	var layer = null, lastFocus = null;
	var EMPTY_KEY = 'nunext-rv-empty';
	var EDIT_KEY = 'nunext-rv-edit';

	function t(k, a, b) { return String(T[k] || k).replace('{n}', a === undefined ? '' : a).replace('{m}', b === undefined ? '' : b); }
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined && text !== null) e.textContent = text;
		return e;
	}
	function store(k, v) {
		try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* no storage: per page */ }
		return null;
	}
	store.session = function (k, v) {
		try { if (v === undefined) return sessionStorage.getItem(k); if (v === null) sessionStorage.removeItem(k); else sessionStorage.setItem(k, v); } catch (e) { /* no storage */ }
		return null;
	};
		function svg(path) {
		var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		s.setAttribute('viewBox', '0 0 16 16'); s.setAttribute('width', '16'); s.setAttribute('height', '16'); s.setAttribute('aria-hidden', 'true');
		s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '1.6');
		var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		p.setAttribute('d', path); s.appendChild(p);
		return s;
	}

	// ------------------------------------------------------------------ reading values

	function htmlText(v) {
		if (!/<[a-z!/][\s\S]*>/i.test(v)) return v;
		try {
			var d = new DOMParser().parseFromString(v.replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div|li|h[1-6]|tr)>/gi, '\n$&'), 'text/html');
			return (d.body.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
		} catch (e) { return v; }
	}
	/** Shown value of one control: {v: text, bool?: true|false, long?: true} or null = not a value (button, password …). */
	function readControl(c) {
		if (!c) return null;
		var tag = c.tagName, type = (c.getAttribute('type') || '').toLowerCase();
		if (tag === 'SELECT') {
			var txt = [];
			Array.prototype.forEach.call(c.options, function (o) { if (o.selected && o.value !== '') txt.push((o.textContent || '').trim()); });
			return {v: txt.join(', ')};
		}
		if (tag === 'TEXTAREA') { var v = htmlText(String(c.value || '')); return {v: v, long: v.length > 80 || v.indexOf('\n') >= 0}; }
		if (tag !== 'INPUT') return null;
		if (/^(hidden|password|button|submit|reset|file|image|radio)$/.test(type)) return null;
		if (type === 'checkbox') return {v: c.checked ? t('yes') : t('no'), bool: c.checked};
		if (c.classList.contains('nuDateNative')) {                  // own pages: the text twin shows the language's date format
			var tw = document.getElementById(c.id + '_nuText');
			if (tw) return {v: tw.value};
		}
		return {v: String(c.value || '')};
	}
	/** A container that may hold a lookup (code + description), else its first control. */
	function readIn(box) {
		var code = box.querySelector('.nuLookupCode'), desc = box.querySelector('.nuLookupDescription');
		if (code) {
			var cv = (code.value || '').trim(), dv = desc && !desc.classList.contains('d-none') ? (desc.value || '').trim() : '';
			return {v: cv && dv ? (dv === cv ? dv : dv + ' (' + cv + ')') : (dv || cv)};
		}
		var c = box.querySelector('[data-nu-field]:not(.nuSubformDelete), select, textarea, input:not([type=hidden])');
		return readControl(c);
	}
	function hiddenUpTo(e, stop) {
		for (; e && e !== stop; e = e.parentElement) {
			if (e.hidden || e.style.display === 'none' || e.style.visibility === 'hidden' || e.classList.contains('nuHolderHidden')) return true;
		}
		return false;
	}
	function labelText(lab) {
		if (!lab) return '';
		var c = lab.cloneNode(true);
		Array.prototype.forEach.call(c.querySelectorAll('i, svg, sup, a, button, .badge, .nuMarks, .nuMark, .nuUhTip, .nuInfoTip, .text-danger, .visually-hidden'), function (x) { x.remove(); });
		return (c.textContent || '').replace(/\s+/g, ' ').replace(/[:*\s]+$/, '').trim();
	}

	// ------------------------------------------------------------------ form pages

	function subTable(sf) {
		var head = [], rows = [];
		var table = sf.querySelector('table');
		if (table) {
			var ths = table.tHead ? Array.prototype.slice.call(table.tHead.rows[0].cells) : [];
			var cols = [];
			ths.forEach(function (th, i) {
				if (th.classList.contains('nuSubformDeleteCell') || th.style.display === 'none' || th.hidden) return;
				var name = labelText(th);
				if (!name && !th.querySelector('[data-nu-obj]') && i === ths.length - 1) return;
				cols.push(i); head.push(name);
			});
			Array.prototype.forEach.call(table.querySelectorAll('tbody tr.nuSubformRow:not(.nuNewRow)'), function (tr) {
				var del = tr.querySelector('.nuSubformDelete');
				if (del && del.checked) return;
				var row = cols.map(function (i) { var td = tr.cells[i]; var r = td ? readIn(td) : null; return r ? r.v : ''; });
				if (row.join('') !== '') rows.push(row);
			});
			return {head: head, rows: rows};
		}
		var cards = sf.querySelectorAll('.nuSubformCard:not(.nuNewRow)');
		Array.prototype.forEach.call(cards, function (card, ci) {
			var del = card.querySelector('.nuSubformDelete');
			if (del && del.checked) return;
			var row = [];
			Array.prototype.forEach.call(card.querySelectorAll('[data-nu-field]:not(.nuSubformDelete):not(.nuLookupDescription)'), function (c) {
				if (c.classList.contains('nuLookupId') || hiddenUpTo(c, card)) return;
				var lab = card.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(c.id) : c.id) + '"]');
				var r = c.classList.contains('nuLookupCode') ? readIn(c.closest('.nuLookupGroup') || c.parentElement) : readControl(c);
				if (!r) return;
				if (ci === 0 || head.length < row.length + 1) head.push(labelText(lab));
				row.push(r.v);
			});
			if (row.join('') !== '') rows.push(row);
		});
		return {head: head, rows: rows};
	}

	function collectForm() {
		var N = window.nuNext || {}, objs = N.objects || {};
		var root = document.getElementById('nuRECORD');
		if (!root) return [];
		var abs = root.classList.contains('nuLayout-absolute');
		var holder = document.getElementById('nuTabHolder');
		var oneTab = !holder || holder.classList.contains('d-none');
		var sections = [];
		Array.prototype.forEach.call(root.children, function (pane) {
			if (!pane.classList.contains('tab-pane')) return;
			var tab = holder ? holder.querySelector('[aria-controls="' + pane.id + '"]') : null;
			if (tab && hiddenUpTo(tab, holder)) return;
			var wraps = Array.prototype.filter.call(pane.querySelectorAll('.nuWrap'), function (w) { return !w.parentElement.closest('.nuSubform'); });
			if (abs) {
				wraps.sort(function (a, b) {
					var ta = parseFloat(a.style.top) || 0, tb = parseFloat(b.style.top) || 0;
					return Math.abs(ta - tb) > 6 ? ta - tb : (parseFloat(a.style.left) || 0) - (parseFloat(b.style.left) || 0);
				});
			}
			var sec = {title: oneTab ? '' : (tab ? (tab.textContent || '').trim() : ''), items: []};
			wraps.forEach(function (w) {
				if (hiddenUpTo(w, pane)) return;
				var m = /\bnuWrap_(\w+)/.exec(w.className), type = m ? m[1] : '', id = w.id.replace(/^nuw_/, '');
				var o = objs[id] || {};
				var label = labelText(document.getElementById('label_' + id)) || o.label || '';
				if (type === 'subform') {
					var sf = w.querySelector('.nuSubform');
					if (sf) sec.items.push({label: label, table: subTable(sf), id: id});
					return;
				}
				if (!/^(input|select|textarea|lookup|display|calc)$/.test(type)) return;
				if (type === 'input' && /^(button|file|password)$/.test(o.input || '')) return;
				var r = readIn(w);
				if (!r) return;
				sec.items.push({label: label || id, v: r.v, bool: r.bool, long: r.long, id: id});
			});
			if (sec.items.length) sections.push(foldChecks(sec));
		});
		return sections;
	}

	// ------------------------------------------------------------------ own record pages

	function collectPage() {
		var root = document.querySelector('[data-nu-view-root]');
		if (!root) return [];
		var cards = Array.prototype.filter.call(root.querySelectorAll('.card'), function (c) { return !c.parentElement.closest('.card'); });
		var sections = [];
		cards.forEach(function (card) {
			if (card.closest('aside, .modal, [data-nu-view-skip]') || hiddenUpTo(card, root)) return;
			var h = card.querySelector('.userSecTitle, .card-header, h2, h3, legend');
			var sec = {title: h ? labelText(h) : '', items: []};
			var seen = {};
			Array.prototype.forEach.call(card.querySelectorAll('label[for]'), function (lab) {
				if (lab.closest('[data-nu-view-skip]')) return;
				var c = document.getElementById(lab.getAttribute('for'));
				if (c && /-ts-control$/.test(c.id)) c = document.getElementById(c.id.replace(/-ts-control$/, '')) || c;   // Tom Select moves the label to its input
				if (c && c.closest('[data-nu-noguard], [data-nu-view-skip]')) return;   // switches of the page itself, actions
				if (!c || seen[c.id] || !card.contains(c) || hiddenUpTo(c, card) || c.closest('.collapse:not(.show)')) return;
				var r = readControl(c);
				if (!r) return;
				seen[c.id] = true;
				sec.items.push({label: labelText(lab) || c.id, v: r.v, bool: r.bool, long: r.long, id: c.id});
			});
			if (sec.items.length) sections.push(foldChecks(sec));
		});
		return sections;
	}

	/** Many tick boxes in one section (permission items …): one line with the ticked ones instead of a Yes / No per box. */
	function foldChecks(sec) {
		var checks = sec.items.filter(function (it) { return it.bool !== undefined; });
		if (checks.length <= 5) return sec;
		var on = checks.filter(function (it) { return it.bool; }).map(function (it) { return it.label; });
		sec.items = sec.items.filter(function (it) { return it.bool === undefined; });
		sec.items.push({label: sec.title || '', v: on.join(', '), long: true, list: true});
		return sec;
	}

	// ------------------------------------------------------------------ list order (← / →)

	function keyOf(href) {
		try {
			var u = new URL(href, location.href), q = u.searchParams, p = q.get('p') || '';
			return p === 'form' ? 'form|' + (q.get('f') || '') + '|' + (q.get('r') || '') : p + '|' + (q.get('id') || q.get('r') || '');
		} catch (e) { return ''; }
	}
	function neighbours() {
		var list = null;
		try { list = JSON.parse(sessionStorage.getItem('nunext-rv-list') || 'null'); } catch (e) { list = null; }
		if (!list || !Array.isArray(list.items)) return null;
		var me = keyOf(location.href), at = -1;
		list.items.forEach(function (it, i) { if (it.k === me) at = i; });
		if (at < 0) return null;
		return {at: at, n: list.items.length, prev: at > 0 ? list.items[at - 1].u : '', next: at < list.items.length - 1 ? list.items[at + 1].u : ''};
	}
	function go(u) {
		if (!u) return;
		var x = new URL(u, location.href);
		x.searchParams.set('view', '1');
		location.href = x.toString();
	}

	// ------------------------------------------------------------------ the sheet

	function item(it, showEmpty) {
		var empty = it.v === undefined || String(it.v).trim() === '';
		if (empty && !showEmpty) return null;
		var d = el('div', 'nuRvItem' + (it.long ? ' nuRvWide' : ''));
		d.appendChild(el('dt', '', it.label));
		var dd = el('dd', empty ? 'nuRvEmpty' : (it.bool === true ? 'nuRvYes' : it.bool === false ? 'nuRvNo' : ''), empty ? '—' : it.v);
		if (it.long) dd.classList.add('nuRvLong');
		d.appendChild(dd);
		return d;
	}
	function table(it) {
		var box = el('div', 'nuRvSub');
		var h = el('h3', 'nuRvSubHead', it.label || '');
		h.appendChild(el('span', 'nuRvCount', t('rv_rows_n', it.table.rows.length)));
		box.appendChild(h);
		if (!it.table.rows.length) { box.appendChild(el('div', 'nuRvEmpty', '—')); return box; }
		var wrap = el('div', 'nuRvTableWrap'), tb = el('table', 'nuRvTable');
		var thead = el('thead'), tr = el('tr');
		it.table.head.forEach(function (x) { tr.appendChild(el('th', '', x)); });
		thead.appendChild(tr); tb.appendChild(thead);
		var body = el('tbody');
		it.table.rows.forEach(function (r) {
			var row = el('tr');
			r.forEach(function (v, i) { var td = el('td', '', v); td.setAttribute('data-label', it.table.head[i] || ''); row.appendChild(td); });
			body.appendChild(row);
		});
		tb.appendChild(body); wrap.appendChild(tb); box.appendChild(wrap);
		return box;
	}

	function render(sheet, sections, showEmpty) {
		sheet.textContent = '';
		var head = el('header', 'nuRvHead');
		if (cfg.kind) head.appendChild(el('div', 'nuRvKind', cfg.kind));
		var h1 = el('h1', 'nuRvTitle', cfg.title || document.title);
		h1.id = 'nuRvTitle';
		head.appendChild(h1);
		sheet.appendChild(head);
		// key facts: fields the form names (sfo_json nunext.view.head)
		var all = {};
		sections.forEach(function (s) { s.items.forEach(function (it) { if (it.id) all[it.id] = it; }); });
		var facts = (cfg.head || []).map(function (id) { return all[id]; }).filter(function (it) { return it && !it.table; });
		if (facts.length) {
			var fs = el('div', 'nuRvFacts');
			facts.forEach(function (it) {
				var f = el('div', 'nuRvFact');
				f.appendChild(el('div', 'nuRvFactL', it.label));
				f.appendChild(el('div', 'nuRvFactV', String(it.v || '').trim() || '—'));
				fs.appendChild(f);
			});
			sheet.appendChild(fs);
		}
		var hidden = 0, shown = 0;
		sections.forEach(function (s) {
			var sec = el('section', 'nuRvSec');
			if (s.title) sec.appendChild(el('h2', 'nuRvSecHead', s.title));
			var dl = null;
			s.items.forEach(function (it) {
				if (it.table) { dl = null; sec.appendChild(table(it)); shown++; return; }
				var d = item(it, showEmpty);
				if (!d) { hidden++; return; }
				if (!dl) { dl = el('dl', 'nuRvGrid'); sec.appendChild(dl); }
				dl.appendChild(d); shown++;
			});
			if (sec.querySelector('.nuRvItem, .nuRvSub')) sheet.appendChild(sec);
		});
		if (!shown) sheet.appendChild(el('p', 'nuRvNothing', t('rv_nothing')));
		var foot = el('footer', 'nuRvFoot');
		if (hidden || showEmpty) {
			if (hidden) foot.appendChild(el('span', '', t('rv_empty_n', hidden) + ' · '));
			var tg = el('button', 'btn btn-link btn-sm p-0 nuRvEmptyToggle', showEmpty ? t('rv_hide_empty') : t('rv_show_empty'));
			tg.type = 'button';
			tg.addEventListener('click', function () { store(EMPTY_KEY, showEmpty ? null : '1'); render(sheet, sections, !showEmpty); });
			foot.appendChild(tg);
		}
		foot.appendChild(el('span', 'nuRvKeys', t('rv_keys')));
		sheet.appendChild(foot);
	}

	function open() {
		if (layer) return;
		var sections = isForm ? collectForm() : collectPage();
		lastFocus = document.activeElement;
		layer = el('div', 'nuRv');
		layer.id = 'nuRecView';
		layer.setAttribute('role', 'dialog'); layer.setAttribute('aria-modal', 'true'); layer.setAttribute('aria-labelledby', 'nuRvTitle'); layer.tabIndex = -1;
		var bar = el('div', 'nuRvBar');
		var badge = el('span', 'nuRvBadge');
		badge.appendChild(svg('M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8ZM8 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'));
		badge.appendChild(el('span', 'nuRvTxt', t('rv_mode')));
		bar.appendChild(badge);
		var nb = neighbours();
		if (nb && nb.n > 1) {
			var nav = el('div', 'nuRvNav');
			var pv = el('button', 'btn btn-outline-secondary nuRvPrev'); pv.type = 'button'; pv.setAttribute('aria-label', t('rv_prev')); pv.title = t('rv_prev') + ' (←)';
			pv.appendChild(svg('M10 3 5 8l5 5')); pv.disabled = !nb.prev; pv.addEventListener('click', function () { go(nb.prev); });
			var nx = el('button', 'btn btn-outline-secondary nuRvNext'); nx.type = 'button'; nx.setAttribute('aria-label', t('rv_next')); nx.title = t('rv_next') + ' (→)';
			nx.appendChild(svg('m6 3 5 5-5 5')); nx.disabled = !nb.next; nx.addEventListener('click', function () { go(nb.next); });
			nav.appendChild(pv); nav.appendChild(el('span', 'nuRvPos', t('rv_pos', nb.at + 1, nb.n))); nav.appendChild(nx);
			bar.appendChild(nav);
			layer._nb = nb;
		}
		if (cfg.key && cfg.url) bar.insertBefore(firstSwitch(), badge.nextSibling);   // left of the arrows and Edit
		var ed = el('button', 'btn btn-outline-secondary nuRvEdit'); ed.type = 'button';
		ed.appendChild(svg(cfg.edit === false ? 'm4 4 8 8M12 4l-8 8' : 'M11 2.5 13.5 5 5.5 13H3v-2.5Z'));
		ed.appendChild(el('span', 'nuRvTxt', cfg.edit === false ? t('close') : t('edit')));
		ed.setAttribute('aria-label', cfg.edit === false ? t('close') : t('edit'));
		ed.title = 'Esc';
		ed.addEventListener('click', close);
		bar.appendChild(ed);
		layer.appendChild(bar);
		var scroll = el('div', 'nuRvScroll'), sheet = el('article', 'nuRvSheet');
		scroll.appendChild(sheet); layer.appendChild(scroll);
		render(sheet, sections, store(EMPTY_KEY) === '1');
		document.body.appendChild(layer);
		document.documentElement.classList.add('nuRvOpen');
		btn.setAttribute('aria-pressed', 'true');
		layer.focus();
	}

	// "Always open in view mode" of this form, for me (core/nurecordview.php, ?p=viewfirst); stored on the server
	function firstSwitch() {
		var lab = el('label', 'nuRvFirst form-check form-switch mb-0');
		var cb = el('input', 'form-check-input');
		cb.type = 'checkbox'; cb.setAttribute('role', 'switch'); cb.id = 'nuRvFirst'; cb.checked = !!cfg.first;
		lab.title = t('rv_first_tip', cfg.kind || '');
		lab.appendChild(cb);
		lab.appendChild(el('span', 'nuRvFirstL d-none d-sm-inline', t('rv_first')));
		lab.appendChild(el('span', 'nuRvFirstL d-sm-none', t('rv_first_short')));
		cb.addEventListener('change', function () {
			var on = cb.checked;
			var body = new URLSearchParams({action: 'set', key: cfg.key, on: on ? '1' : '0'});
			window.NuUtil.post(cfg.url, body).then(function (r) {
				if (!r || !r.ok) { cb.checked = !on; return; }
				cfg.first = !!r.on;
				window.NuUtil.toast(t(cfg.first ? 'rv_first_on' : 'rv_first_off', cfg.kind || ''), true);
			}).catch(function () { cb.checked = !on; });
		});
		return lab;
	}

	function close() {
		if (!layer) return;
		// view first: this record stays open for editing (also after Save reloads it), until another record is opened
		if (cfg.first) store.session(EDIT_KEY, keyOf(location.href));
		layer.remove(); layer = null;
		document.documentElement.classList.remove('nuRvOpen');
		btn.setAttribute('aria-pressed', 'false');
		try {
			var u = new URL(location.href);
			if (u.searchParams.has('view')) { u.searchParams.delete('view'); history.replaceState(history.state, '', u.toString()); }
		} catch (e) { /* old browser: the address keeps view=1 */ }
		(lastFocus && document.contains(lastFocus) ? lastFocus : btn).focus();
	}

	btn.addEventListener('click', function () { if (layer) close(); else open(); });
	document.addEventListener('keydown', function (e) {
		var k = (e.key || '').toLowerCase();
		if ((e.ctrlKey || e.metaKey) && e.shiftKey && !e.altKey && k === 'v') {
			if (document.querySelector('.modal.show')) return;
			e.preventDefault(); e.stopPropagation();
			if (layer) close(); else open();
			return;
		}
		if (!layer || e.ctrlKey || e.metaKey || e.altKey) return;
		if (k === 'escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
		var nb = layer._nb;
		if (nb && (k === 'arrowleft' || k === 'arrowright') && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target && e.target.tagName) || '')) {
			e.preventDefault();
			go(k === 'arrowleft' ? nb.prev : nb.next);
		}
	}, true);

	var want = /[?&]view=1(&|$)/.test(location.search);
	if (!want && cfg.first && !/[?&]view=0(&|$)/.test(location.search)) {
		var me = keyOf(location.href);
		if (store.session(EDIT_KEY) === me) want = false;   // Edit was chosen for this record
		else { store.session(EDIT_KEY, null); want = true; }
	}
	if (want) {
		if (isForm) {
			var opened = false;
			document.addEventListener('nu:pageload', function () { if (!opened) { opened = true; open(); } });
			setTimeout(function () { if (!opened) { opened = true; open(); } }, 3000);   // a form whose code stopped before nuOnLoad ended
		} else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', open); else open();
	}
	window.nuRecordView = {open: open, close: close};
})();
