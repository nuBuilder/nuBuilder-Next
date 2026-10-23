/* Globeadmin start page (pages/nuhome.php, core/nuhome.php): the view (tile styles, descriptions, navigation – View panel, 0.8.420),
   tile colours, procedure tiles, the nuHome API, search, edit mode (Customise). */
(function () {
	'use strict';
	var D;
	try { D = JSON.parse(document.getElementById('nuHomeData').textContent); } catch (e) { return; }
	var T = D.t || {};
	function t(k) { return T[k] || k; }
	/** t() with arguments: %s in order, or %1$s %2$s … (as PHP's vsprintf). */
	function tf(k) {
		var a = Array.prototype.slice.call(arguments, 1), i = 0;
		return t(k).replace(/%(?:(\d+)\$)?[sd]/g, function (m, n) { var v = n ? a[n - 1] : a[i++]; return v === undefined ? '' : String(v); });
	}
	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v;
			else if (k === 'class') e.className = v;
			else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
			else e.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c !== null && c !== undefined) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function icon(cls) { return el('i', {'class': 'bi ' + cls, 'aria-hidden': 'true'}); }
	function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } return null; }
	function sstore(k, v) { try { if (v === undefined) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); } catch (e) { return null; } return null; }
	function clone(o) { return JSON.parse(JSON.stringify(o)); }
	function typing() {
		var a = document.activeElement, tag = (a && a.tagName) || '';
		return /^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (a && a.isContentEditable);
	}

	var viewEl = document.getElementById('nuHomeView'), wrapEl = document.getElementById('nuHomeWrap');

	// own colours (no inline style attributes – CSP): the variables are set through the DOM – tiles (--nu-c), groups (--nu-gc)
	function colours(root) {
		$$('[data-nu-color]', root).forEach(function (s) { s.style.setProperty('--nu-c', s.getAttribute('data-nu-color')); });
		$$('[data-nu-gcolor]', root).forEach(function (s) { s.style.setProperty('--nu-gc', s.getAttribute('data-nu-gcolor')); });
	}
	colours(document);
	$$('[data-nu-chip]').forEach(function (c) {                     // favourite chips: the colour of their group
		var g = viewEl && viewEl.querySelector('.nuHomeGroup[data-nu-group="' + c.getAttribute('data-nu-cgroup') + '"]');
		if (g) c.style.setProperty('--nu-gc', g.getAttribute('data-nu-gcolor'));
	});

	function toast(msg, ok, undo) {
		var box = $('#nuHomeToast') || document.body.appendChild(el('div', {id: 'nuHomeToast', 'class': 'position-fixed bottom-0 end-0 p-3 nuHomeToast'}));
		var a = el('div', {'class': 'alert d-flex align-items-center gap-3 py-2 px-3 small shadow-sm mb-2 ' + (ok ? 'alert-success' : 'alert-danger'), role: ok ? 'status' : 'alert'}, [el('span', {text: msg})]);
		if (undo) a.appendChild(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-success py-0 ms-auto', text: t('home_pf_undo'), onclick: function () { a.remove(); undo(); }}));
		box.appendChild(a);
		setTimeout(function () { a.remove(); }, undo ? 8000 : (ok ? 2500 : 7000));
	}

	// procedure tiles: run like the procedure page (window = output in a new tab, hidden = message only)
	$$('.nuHomeProc').forEach(function (b) {
		b.addEventListener('click', function () {
			var mode = b.getAttribute('data-nu-run') === 'hidden' ? 'hidden' : 'window';
			var w = mode === 'window' ? window.open('', '_blank') : null;
			if (w) w.document.write('<p>' + t('home_running') + '</p>');
			var fd = new FormData();
			fd.append('_csrf', D.csrf); fd.append('action', 'run'); fd.append('props', '{}');
			b.disabled = true;
			fetch(D.procUrl.replace('__ID__', encodeURIComponent(b.getAttribute('data-nu-proc'))), {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					var errs = (res.errors || []).map(function (e) { return e.message || String(e); });
					if (w) {
						w.document.open();
						w.document.write(res.output || errs.map(function (m) { return '<p>' + String(m).replace(/[<&]/g, function (c) { return c === '<' ? '&lt;' : '&amp;'; }) + '</p>'; }).join('') || '<p>' + t('home_proc_done') + '</p>');
						w.document.close();
					}
					if (!res.ok) toast(t('home_proc_failed') + (errs.length ? ' ' + errs.join(' ') : ''), false);
					else if (!w) toast(t('home_proc_done'), true);
				})
				.catch(function (e) { if (w) w.close(); toast(t('home_proc_failed') + ' ' + e.message, false); })
				.finally(function () { b.disabled = false; });
		});
	});

	// ================================================================ nuHome – JavaScript API of the start page (0.8.379)
	// For the JavaScript of the procedure nunext_home (served as ?p=homejs, loaded after this file): nuHome.on('tile' | 'ready' |
	// 'search' | 'tab' | 'favourites' | 'customize' | 'view', fn), off, tiles(filter), tile(key), data (nuHomeSetData() of the PHP hook),
	// view (0.8.420: the view in effect, a copy). 'tile' and 'ready' are replayed for callbacks added later; every callback runs in
	// try/catch (console + toast for the globeadmin).
	var H = (function () {
		var handlers = {}, objs = [], byEl = (typeof WeakMap === 'function') ? new WeakMap() : null, isReady = false;
		var told = {};
		function report(ev, err) {
			var msg = 'nuHome ' + ev + ': ' + (err && err.message ? err.message : String(err));
			if (window.console) console.error(msg, err);   // eslint-disable-line no-console
			if (D.ga && !told[msg]) { told[msg] = true; toast(msg, false); }   // the same error once, not once per tile
		}
		function call(ev, fn, args) { try { return fn.apply(null, args); } catch (e) { report(ev, e); return undefined; } }
		function emit(ev, args) { (handlers[ev] || []).slice().forEach(function (fn) { call(ev, fn, args || []); }); }
		function wrap(a) {
			if (byEl && byEl.has(a)) return byEl.get(a);
			var key = a.getAttribute('data-nu-tile');
			var iconEl = a.querySelector('.nuHomeIcon i'), colEl = a.querySelector('[data-nu-color]');
			var tile = {
				key: key, el: a, source: a.getAttribute('data-nu-source') || 'builtin', kind: a.getAttribute('data-nu-kind') || 'page',
				label: a.getAttribute('data-nu-label') || '', hint: a.getAttribute('data-nu-desc') || '',
				icon: iconEl ? (iconEl.className.match(/\bbi-[a-z0-9-]+/) || [''])[0] : '', color: colEl ? colEl.getAttribute('data-nu-color') : '',
				group: a.getAttribute('data-nu-group') || '', tab: a.getAttribute('data-nu-tab') || '', size: (D.layout && D.layout.size) || 'large',
				fav: a.hasAttribute('data-nu-fav'), href: a.tagName === 'A' ? a.getAttribute('href') : null
			};
			var b = a.querySelector('.nuHomeBadge');
			tile.badge = b ? b.textContent : '';
			tile.setBadge = function (text, color) {
				var cur = a.querySelector('.nuHomeBadge');
				text = text === null || text === undefined ? '' : String(text);
				if (!text) { if (cur) cur.remove(); tile.badge = ''; return tile; }
				if (!cur) { cur = el('span', {'class': 'badge nuHomeBadge'}); (a.querySelector('.nuHomeLabelRow') || a).appendChild(cur); }
				cur.className = 'badge nuHomeBadge text-bg-' + (/^(primary|secondary|success|danger|warning|info|light|dark)$/.test(color || '') ? color : 'secondary');
				cur.textContent = text;
				cur.title = text;
				tile.badge = text;
				return tile;
			};
			tile.setHint = function (text) {
				text = text === null || text === undefined ? '' : String(text);
				var h = a.querySelector('.nuHomeHint');
				if (!h) { h = el('span', {'class': 'nuHomeHint'}); (a.querySelector('.nuHomeText') || a).appendChild(h); }
				h.textContent = text;
				a.setAttribute('data-nu-hint', tile.label + (text ? ' – ' + text : ''));
				a.setAttribute('data-nu-desc', text);
				tile.hint = text;
				return tile;
			};
			tile.hide = function () { a.setAttribute('data-nu-js-hidden', '1'); box(a).hidden = true; groups(); return tile; };
			tile.show = function () { a.removeAttribute('data-nu-js-hidden'); box(a).hidden = false; groups(); return tile; };
			tile.onClick = function (fn) {
				a.addEventListener('click', function (e) {
					if (call('click', fn, [e, tile]) === false) { e.preventDefault(); e.stopImmediatePropagation(); }
				}, true);
				return tile;
			};
			if (byEl) byEl.set(a, tile);
			objs.push(tile);
			return tile;
		}
		/** groups whose tiles are all hidden by code are hidden too */
		function groups() {
			if (!viewEl) return;
			$$('.nuHomeGroup', viewEl).forEach(function (g) {
				var all = $$('[data-nu-tile]', g);
				if (all.length && all.every(function (a) { return a.hasAttribute('data-nu-js-hidden'); })) g.hidden = true;
				else if (!viewEl.classList.contains('nuHomeSearching')) g.hidden = false;
			});
		}
		function scan(root) { return $$('[data-nu-tile]', root || viewEl || document).map(wrap); }
		var api = {
			data: D.hookData && typeof D.hookData === 'object' ? D.hookData : {},
			on: function (ev, fn) {
				if (typeof fn !== 'function') return api;
				(handlers[ev] = handlers[ev] || []).push(fn);
				if (ev === 'tile') scan().forEach(function (tl) { call('tile', fn, [tl]); });   // replay: tiles already drawn
				if (ev === 'ready' && isReady) call('ready', fn, []);
				return api;
			},
			off: function (ev, fn) { if (handlers[ev]) handlers[ev] = handlers[ev].filter(function (f) { return f !== fn; }); return api; },
			tiles: function (filter) {
				var list = scan();
				if (typeof filter === 'function') return list.filter(function (tl) { try { return filter(tl); } catch (e) { report('tiles', e); return false; } });
				if (filter && typeof filter === 'object') return list.filter(function (tl) { return Object.keys(filter).every(function (k) { return tl[k] === filter[k]; }); });
				return list;
			},
			tile: function (key) { var a = viewEl && viewEl.querySelector('[data-nu-tile="' + String(key).replace(/["\\]/g, '') + '"]'); return a ? wrap(a) : null; }
		};
		Object.defineProperty(api, 'view', {enumerable: true, get: function () { return clone(eff(size)); }});
		// tiles added later (e.g. by other code) get the 'tile' callbacks too
		if (viewEl && typeof MutationObserver === 'function') {
			new MutationObserver(function (muts) {
				muts.forEach(function (m) {
					Array.prototype.forEach.call(m.addedNodes, function (n) {
						if (n.nodeType !== 1) return;
						var found = (n.matches && n.matches('[data-nu-tile]') ? [n] : []).concat($$('[data-nu-tile]', n));
						found.forEach(function (a) { if (!(byEl && byEl.has(a))) emit('tile', [wrap(a)]); });
					});
				});
			}).observe(viewEl, {childList: true, subtree: true});
		}
		function ready() { if (isReady) return; isReady = true; emit('ready', []); }
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else setTimeout(ready, 0);
		window.nuHome = api;
		return {emit: function (ev) { emit(ev, Array.prototype.slice.call(arguments, 1)); }, wrap: wrap, groups: groups};
	})();
	/** The box of a tile that is hidden / shown (search, favourites, tile.hide()). */
	function box(a) { return a.closest('.nuHomeTileWrap') || a; }

	// ================================================================ view (0.8.420): style, descriptions, navigation, look
	// The layout's view (VD: main values, tabStyle, sizes.tablet / sizes.phone), the person's own changes over it (VO: view, sizes) and
	// built-in phone values when the layout has none. Precedence of a value: own (this size) > own (all sizes) > layout (this size) >
	// layout (all sizes) > defaults; the style of a tab sits between layout (this size) and layout (all sizes) – tabs / sidebar only.
	var VS = D.view || {};
	var DEF = VS.defaults || {};
	var SIZES = ['tablet', 'phone'];
	var ROOM = {large: true, cards: true, list: true, table: true};       // styles that show the description on the tile (Auto)
	function obj(x) { return x && typeof x === 'object' && !Array.isArray(x) ? Object.assign({}, x) : {}; }
	function normView(v) {
		v = obj(v);
		v.tabStyle = obj(v.tabStyle);
		var s = obj(v.sizes);
		v.sizes = {};
		SIZES.forEach(function (z) { if (Object.keys(obj(s[z])).length) v.sizes[z] = obj(s[z]); });
		return v;
	}
	function normOv(o) {
		if (!o || typeof o !== 'object') return null;
		var s = obj(o.sizes);
		return {view: obj(o.view), sizes: {tablet: obj(s.tablet), phone: obj(s.phone)}};
	}
	function pick(v) { var out = {}; Object.keys(DEF).forEach(function (k) { if (v && v[k] !== undefined) out[k] = v[k]; }); return out; }
	var VD = normView(VS.layout), VO = normOv(VS.overlay);
	var size = 'desktop';
	/** The layout's own values for a screen size; phones without any: list in one column, sidebar instead of tabs. */
	function layoutSize(z) {
		if (z === 'desktop') return {};
		var own = VD.sizes[z];
		if (own && Object.keys(own).length) return own;
		if (z !== 'phone') return {};
		var p = {style: 'list', listCols: '1'};
		if (VD.nav === 'tabs' || VD.nav === 'sidebar') p.nav = 'sidebar';
		return p;
	}
	function eff(z) {
		return Object.assign({}, DEF, pick(VD), layoutSize(z), VO ? VO.view : {}, VO && z !== 'desktop' ? VO.sizes[z] : {});
	}
	function styleFor(unit, z, v) {
		if (VO && z !== 'desktop' && VO.sizes[z].style) return VO.sizes[z].style;
		if (VO && VO.view.style) return VO.view.style;
		var ls = layoutSize(z);
		if (ls.style) return ls.style;
		if (unit && v && (v.nav === 'tabs' || v.nav === 'sidebar') && VD.tabStyle[unit]) return VD.tabStyle[unit];
		return VD.style || DEF.style;
	}

	// ---------------------------------------------------------------- tabs and sidebar
	var tabBtns = viewEl ? $$('[data-nu-tabbtn]', viewEl) : [], panes = viewEl ? $$('.nuHomePane', viewEl) : [];
	var sideItems = viewEl ? $$('[data-nu-side]', viewEl) : [], sideSel = viewEl ? $('.nuHomeSideSelect', viewEl) : null;
	var curUnit = (store('nunext.homeTab') || '').replace(/^nuHomeTab_/, ''), curGroup = sstore('nunext.homeSide') || '';
	function unitShown(p) { return !p.hidden && !(viewEl.classList.contains('nuHomeFavOnly') && !viewEl.classList.contains('nuHomeSearching') && !p.querySelector('[data-nu-fav]')); }
	function showUnit(id) {
		var p = panes.filter(function (x) { return x.getAttribute('data-nu-tab') === id && unitShown(x); })[0] || panes.filter(unitShown)[0] || panes[0];
		if (!p) return;
		curUnit = p.getAttribute('data-nu-tab');
		panes.forEach(function (x) { x.classList.toggle('nuHomeActive', x === p); });
		tabBtns.forEach(function (b) {
			var on = b.getAttribute('data-nu-tabbtn') === curUnit;
			b.classList.toggle('active', on);
			b.setAttribute('aria-selected', on ? 'true' : 'false');
			b.tabIndex = on ? 0 : -1;
		});
	}
	function groupShown(g) { return !g.hidden && !(viewEl.classList.contains('nuHomeFavOnly') && !viewEl.classList.contains('nuHomeSearching') && !g.querySelector('[data-nu-fav]')); }
	function showSide(id) {
		var all = $$('.nuHomeGroup', viewEl);
		var g = all.filter(function (x) { return x.getAttribute('data-nu-group') === id && groupShown(x); })[0] || all.filter(groupShown)[0] || all[0];
		if (!g) return;
		curGroup = g.getAttribute('data-nu-group');
		all.forEach(function (x) { x.classList.toggle('nuHomeSideOn', x === g); });
		sideItems.forEach(function (b) {
			var on = b.getAttribute('data-nu-side') === curGroup;
			b.classList.toggle('active', on);
			if (on) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
		});
		if (sideSel) sideSel.value = curGroup;
	}
	/** The tab of the panel's "Only …": the open tab, or the tab of the group open in the sidebar. */
	function panelUnit() {
		var v = eff(size);
		if (v.nav === 'sidebar') { var b = sideItems.filter(function (x) { return x.getAttribute('data-nu-side') === curGroup; })[0]; return b ? b.getAttribute('data-nu-unit') : curUnit; }
		return curUnit;
	}
	function unitTitle(id) { var u = (VS.units || []).filter(function (x) { return x.id === id; })[0]; return u ? u.title : ''; }
	/** Favourites only: tabs and sidebar entries without a favourite are hidden. */
	function favTabs() {
		if (!viewEl) return;
		tabBtns.forEach(function (b) {
			var p = panes.filter(function (x) { return x.getAttribute('data-nu-tab') === b.getAttribute('data-nu-tabbtn'); })[0];
			b.parentNode.hidden = !!p && !unitShown(p);
		});
		sideItems.forEach(function (b) {
			var g = viewEl.querySelector('.nuHomeGroup[data-nu-group="' + b.getAttribute('data-nu-side') + '"]');
			b.hidden = !!g && !groupShown(g);
		});
		if (sideSel) Array.prototype.forEach.call(sideSel.options, function (o) {
			var g = viewEl.querySelector('.nuHomeGroup[data-nu-group="' + o.value + '"]');
			o.hidden = !!g && !groupShown(g);
		});
		showUnit(curUnit);
		showSide(curGroup);
	}
	tabBtns.forEach(function (b, i) {
		b.addEventListener('click', function () {
			showUnit(b.getAttribute('data-nu-tabbtn'));
			store('nunext.homeTab', 'nuHomeTab_' + curUnit);
			hideTip(true);
			applyView();
			H.emit('tab', curUnit);
		});
		b.addEventListener('keydown', function (e) {                 // arrow keys move along the tabs (ARIA tabs pattern)
			var vis = tabBtns.filter(function (x) { return !x.parentNode.hidden; }), j = vis.indexOf(b);
			var to = e.key === 'ArrowRight' ? vis[(j + 1) % vis.length] : e.key === 'ArrowLeft' ? vis[(j - 1 + vis.length) % vis.length] : e.key === 'Home' ? vis[0] : e.key === 'End' ? vis[vis.length - 1] : null;
			if (document.documentElement.dir === 'rtl' && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) to = vis[(j + (e.key === 'ArrowLeft' ? 1 : -1) + vis.length) % vis.length];
			if (!to) return;
			e.preventDefault();
			to.focus();
			to.click();
		});
		return i;
	});
	function sideGo(id) {
		showSide(id);
		sstore('nunext.homeSide', curGroup);
		hideTip(true);
		applyView();
		H.emit('tab', panelUnit());
	}
	sideItems.forEach(function (b) { b.addEventListener('click', function () { sideGo(b.getAttribute('data-nu-side')); }); });
	if (sideSel) sideSel.addEventListener('change', function () { sideGo(sideSel.value); });

	// ---------------------------------------------------------------- table style: header row with sorting (not saved)
	var sortBy = {};                                          // container id → [column, direction]
	function sortKey(w, col) {
		var a = w.querySelector('[data-nu-tile]');
		if (!a) return '';
		if (col === 'label') return (a.getAttribute('data-nu-label') || '').toLowerCase();
		if (col === 'group') return (w.closest('.nuHomeGroup') || {}).getAttribute ? w.closest('.nuHomeGroup').getAttribute('aria-label').toLowerCase() : '';
		var b = a.querySelector('.nuHomeBadge');
		return b ? '0' + b.textContent.toLowerCase() : '1';
	}
	function tableHeads() {
		$$('.nuHomeMain, .nuHomePane', viewEl).forEach(function (c) {
			var on = /\bnuHomeStyle-table\b/.test(c.className), head = $(':scope > .nuHomeTHead', c), wraps = $$('.nuHomeTileWrap', c);
			if (!on) {
				if (head) head.remove();
				wraps.forEach(function (w) { if (!w.closest('.nuHomeStyle-table')) w.style.order = ''; });   // not the rows of a table around it
				return;
			}
			var id = c.id || 'main', s = sortBy[id] || ['', 1];
			if (!head) {
				var th = function (col, cls, label) {
					return el('button', {type: 'button', 'class': cls, 'data-nu-sort': col, title: label, onclick: function () {
						var cur = sortBy[id] || ['', 1];
						sortBy[id] = [col, cur[0] === col ? -cur[1] : 1];
						tableHeads();
					}}, [label]);
				};
				head = el('div', {'class': 'nuHomeTHead'}, [el('span', {'aria-hidden': 'true'}), th('label', 'nuHomeThName', t('home_v_sort_name')),
					el('span', {'class': 'nuHomeThHint', text: t('home_v_col_desc')}), th('group', 'nuHomeThGroup', t('home_v_sort_group')), th('status', 'nuHomeThStatus', t('home_v_sort_status'))]);
				c.insertBefore(head, c.firstChild);
			}
			$$('[data-nu-sort]', head).forEach(function (b) {
				var on2 = b.getAttribute('data-nu-sort') === s[0];
				b.setAttribute('aria-pressed', on2 ? 'true' : 'false');
				var ic = $('i', b);
				if (ic) ic.remove();
				if (on2) b.appendChild(icon('bi-arrow-' + (s[1] > 0 ? 'down' : 'up') + ' ms-1'));
			});
			if (!s[0]) { wraps.forEach(function (w) { w.style.order = ''; }); return; }
			wraps.map(function (w, i) { return [sortKey(w, s[0]), i, w]; })
				.sort(function (x, y) { return (x[0] < y[0] ? -1 : x[0] > y[0] ? 1 : x[1] - y[1]) * (x[0] === y[0] ? 1 : s[1]); })
				.forEach(function (r, i) { r[2].style.order = String(i + 1); });
		});
	}

	// ---------------------------------------------------------------- recently used (the search's history, assets/js/nupalette.js)
	function fillRecent(strip) {
		var box2 = $('.nuHomeChips', strip);
		box2.textContent = '';
		var ids = [];
		try { ids = JSON.parse(store('nunext.palRecent') || '[]'); } catch (e) { ids = []; }
		ids.filter(function (id) { return /^t:/.test(id); }).forEach(function (id) {
			if (box2.children.length >= 6) return;
			var a = viewEl.querySelector('[data-nu-tile="' + id.slice(2).replace(/["\\]/g, '') + '"]');
			if (!a || a.hasAttribute('data-nu-js-hidden')) return;
			var ic = a.querySelector('.nuHomeIcon');
			var kids = [ic ? ic.cloneNode(true) : null, el('span', {'class': 'nuHomeChipLabel', text: a.getAttribute('data-nu-label')})];
			var chip = a.tagName === 'A'
				? el('a', {'class': 'nuHomeChip', href: a.getAttribute('href'), target: a.getAttribute('target'), rel: a.getAttribute('rel'), title: a.getAttribute('data-nu-hint'), 'data-nu-recent': a.getAttribute('data-nu-tile')}, kids)
				: el('button', {type: 'button', 'class': 'nuHomeChip', title: a.getAttribute('data-nu-hint'), 'data-nu-recent': a.getAttribute('data-nu-tile'), onclick: function () { a.click(); }}, kids);
			var g = a.closest('.nuHomeGroup');
			if (g) chip.style.setProperty('--nu-gc', g.getAttribute('data-nu-gcolor'));
			colours(chip);
			box2.appendChild(chip);
		});
	}
	// opening a tile counts as "used" (the search box's list of last opened entries)
	if (viewEl) viewEl.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('[data-nu-tile], [data-nu-chip]');
		var k = a && (a.getAttribute('data-nu-tile') || a.getAttribute('data-nu-chip'));
		if (k && window.nuPalette && window.nuPalette.remember) window.nuPalette.remember('t:' + k);
	}, true);

	// ---------------------------------------------------------------- apply the view (idempotent: load, resize, tab, every panel change)
	var lastView = '';
	function applyView() {
		if (!viewEl) return;
		var v = eff(size), searching = viewEl.classList.contains('nuHomeSearching');
		var nav = searching && v.nav !== 'none' ? 'groups' : v.nav;
		var keep = viewEl.className.split(/\s+/).filter(function (c) { return c && !/^nuHome(Nav|Desc|Dens|Icn|ListCols)-|^nuHome(Gcol|NoBadges|NoActions|ListFull)$/.test(c); });
		keep.push('nuHomeNav-' + nav, 'nuHomeDesc-' + v.desc, 'nuHomeDens-' + v.density, 'nuHomeIcn-' + v.icon, 'nuHomeListCols-' + v.listCols);
		if (v.listWidth === 'full') keep.push('nuHomeListFull');
		if (v.groupColors) keep.push('nuHomeGcol');
		if (!v.badges) keep.push('nuHomeNoBadges');
		if (!v.actions) keep.push('nuHomeNoActions');
		viewEl.className = keep.join(' ');
		viewEl.style.setProperty('--nu-lines', String(+v.lines === 0 ? 99 : +v.lines));
		viewEl.setAttribute('data-nu-size', size);
		var tabbed = nav === 'tabs' || nav === 'sidebar', main = $('.nuHomeMain', viewEl);
		var setSt = function (e, st) { if (e) e.className = e.className.replace(/\s*\bnuHomeStyle-\w+/g, '') + (st ? ' nuHomeStyle-' + st : ''); };
		setSt(main, tabbed ? null : styleFor('', size, {nav: 'groups'}));
		panes.forEach(function (p) { setSt(p, tabbed ? styleFor(p.getAttribute('data-nu-tab'), size, v) : null); });
		var fs = $('[data-nu-strip="fav"]', viewEl);
		if (fs) fs.hidden = !v.favStrip || !$('.nuHomeChip', fs);
		var rs = $('[data-nu-strip="recent"]', viewEl);
		if (rs) { if (v.recentStrip) fillRecent(rs); rs.hidden = !v.recentStrip || !$('.nuHomeChip', rs); }
		favTabs();
		tableHeads();
		var now = JSON.stringify(v);
		if (now !== lastView) { lastView = now; H.emit('view', clone(v)); }
	}
	// screen sizes by the width of the start page (not the device): phone < 576, tablet < 992, else desktop
	function measure() {
		if (!wrapEl) return;
		var w = wrapEl.clientWidth, z = w < 576 ? 'phone' : w < 992 ? 'tablet' : 'desktop';
		if (z === size) return;
		size = z;
		hideTip(true);
		applyView();
		if (VP.open) drawPanel();
	}

	// ---------------------------------------------------------------- descriptions on hover / focus / long press: tooltip, hover card, ⓘ
	var tip = el('div', {'class': 'nuHomeTip', id: 'nuHomeTip', role: 'tooltip', hidden: true});
	document.body.appendChild(tip);
	var tipFor = null, showT = 0, hideT = 0, pressT = 0, suppress = false;
	function styleOf(a) {
		var c = a.closest('.nuHomePane[class*="nuHomeStyle-"], .nuHomeMain[class*="nuHomeStyle-"]');
		var m = c && c.className.match(/\bnuHomeStyle-(\w+)/);
		return m ? m[1] : 'large';
	}
	function cut(e) { return !!e && e.offsetParent !== null && (e.scrollWidth > e.clientWidth + 1 || e.scrollHeight > e.clientHeight + 1); }
	function hoverKind(a) {
		if (!a || !a.hasAttribute('data-nu-tile')) return null;
		var d = eff(size).desc, lab = a.querySelector('.nuHomeLabel'), hint = a.getAttribute('data-nu-desc') || '';
		var trunc = cut(lab) || cut(a.querySelector('.nuHomeHint'));
		if (d === 'tooltip') return 'tip';
		if (d === 'hovercard') return 'card';
		if (d === 'auto') return (!ROOM[styleOf(a)] && (hint || cut(lab))) || trunc ? 'tip' : null;
		if (d === 'inline') return trunc ? 'tip' : null;
		if (d === 'off') return cut(lab) ? 'name' : null;
		return null;
	}
	function tipBody(a, kind) {
		var w = box(a), label = a.getAttribute('data-nu-label') || '', hint = a.getAttribute('data-nu-desc') || '', b = a.querySelector('.nuHomeBadge');
		var badge = b && eff(size).badges ? el('span', {'class': b.className.replace('nuHomeBadge', '')}, [b.textContent]) : null;
		if (kind !== 'card') {
			return [el('b', {text: label}), kind === 'name' || !hint ? null : el('span', {text: hint}), badge ? el('span', {'class': 'nuHomeTipBadge'}, [badge]) : null];
		}
		var g = a.closest('.nuHomeGroup'), ic = a.querySelector('.nuHomeIcon');
		var icc = ic ? ic.cloneNode(true) : null;
		if (icc && ic.getAttribute('data-nu-color')) icc.style.setProperty('--nu-c', ic.getAttribute('data-nu-color'));
		var links = [];
		$$('.dropdown-menu .dropdown-item', w).forEach(function (it) {
			var ii = it.querySelector('.bi'), txt = it.textContent.trim(), ic = ((ii && ii.className.match(/\bbi-[a-z0-9-]+/)) || ['bi-arrow-return-right'])[0];
			if (it.tagName === 'A') links.push(el('a', {'class': 'nuHomeHcLink', href: it.getAttribute('href')}, [icon(ic), txt]));
			else links.push(el('button', {type: 'button', 'class': 'nuHomeHcLink', onclick: function () { hideTip(true); it.click(); }}, [icon(ic), txt]));
		});
		var add = $(':scope > a.nuHomeAction', w);
		return [el('div', {'class': 'nuHomeHc'}, [
			el('div', {'class': 'nuHomeHcHead'}, [icc, el('div', {}, [el('b', {text: label}), g ? el('span', {'class': 'nuHomeHcGroup', text: g.getAttribute('aria-label')}) : null])]),
			hint ? el('p', {text: hint}) : null,
			badge ? el('div', {'class': 'nuHomeHcBadge'}, [badge]) : null,
			links.length ? el('div', {'class': 'nuHomeHcLinks'}, links) : null,
			el('div', {'class': 'nuHomeHcFoot'}, [el('span', {}, [el('kbd', {text: 'Enter'}), ' ' + t('home_v_hc_open')]),
				add ? el('a', {'class': 'nuHomeHcLink', href: add.getAttribute('href')}, [icon('bi-plus-lg'), add.getAttribute('title') || '']) : null])
		])];
	}
	function placeTip(anchor) {
		var r = anchor.getBoundingClientRect(), tw = tip.offsetWidth, th = tip.offsetHeight, m = 8;
		var left = Math.min(Math.max(m, r.left + (tip.classList.contains('nuHomeTipTip') ? (r.width - tw) / 2 : 0)), window.innerWidth - tw - m);
		var top = r.bottom + 8, above = false;
		if (top + th > window.innerHeight - m && r.top - th - 8 > m) { top = r.top - th - 8; above = true; }
		tip.style.left = left + 'px';
		tip.style.top = top + 'px';
		tip.classList.toggle('nuHomeTipAbove', above);
	}
	function showTip(a, kind, anchor) {
		clearTimeout(hideT);
		if (!a || !kind) return;
		if (tipFor && tipFor !== a) tipFor.removeAttribute('aria-describedby');
		tipFor = a;
		tip.className = 'nuHomeTip ' + (kind === 'card' ? 'nuHomeTipCard' : 'nuHomeTipTip');
		tip.textContent = '';
		tipBody(a, kind).forEach(function (c) { if (c) tip.appendChild(c); });
		tip.hidden = false;
		a.setAttribute('aria-describedby', 'nuHomeTip');
		placeTip(anchor || a);
		requestAnimationFrame(function () { tip.classList.add('nuHomeTipIn'); });
	}
	function hideTip(now) {
		clearTimeout(showT);
		var done = function () { tip.classList.remove('nuHomeTipIn'); tip.hidden = true; if (tipFor) tipFor.removeAttribute('aria-describedby'); tipFor = null; };
		if (now) { done(); return; }
		hideT = setTimeout(done, tip.classList.contains('nuHomeTipCard') ? 160 : 0);
	}
	function tileOf(e) { return e.closest ? e.closest('[data-nu-tile]') : null; }
	if (viewEl) {
		viewEl.addEventListener('mouseover', function (e) {
			var info = e.target.closest && e.target.closest('[data-nu-descinfo]');
			if (info) { clearTimeout(showT); showTip(box(info).querySelector('[data-nu-tile]'), 'tip', info); return; }
			if (e.target.closest && e.target.closest('.nuHomeAction')) return;
			var a = tileOf(e.target);
			if (!a || a === tipFor) { if (a) clearTimeout(hideT); return; }
			var kind = hoverKind(a);
			clearTimeout(showT);
			if (!kind) { if (tipFor) hideTip(); return; }
			showT = setTimeout(function () { showTip(a, kind); }, tipFor ? 0 : +eff(size).delay);
		});
		viewEl.addEventListener('mouseout', function (e) {
			var a = e.target.closest && e.target.closest('[data-nu-tile], [data-nu-descinfo]');
			if (!a) return;
			if (e.relatedTarget && (a.contains(e.relatedTarget) || tip.contains(e.relatedTarget))) return;
			hideTip();
		});
		viewEl.addEventListener('focusin', function (e) {
			var info = e.target.closest && e.target.closest('[data-nu-descinfo]');
			var a = info ? box(info).querySelector('[data-nu-tile]') : tileOf(e.target);
			if (!a) return;
			var kind = info ? 'tip' : (hoverKind(a) || (eff(size).desc === 'info' ? 'tip' : null));
			if (kind) showTip(a, kind, info || a);
		});
		viewEl.addEventListener('focusout', function (e) { if (!(e.relatedTarget && tip.contains(e.relatedTarget))) hideTip(true); });
		viewEl.addEventListener('click', function (e) {
			if (suppress) { suppress = false; e.preventDefault(); e.stopImmediatePropagation(); return; }
			var info = e.target.closest && e.target.closest('[data-nu-descinfo]');
			if (info) { e.preventDefault(); clearTimeout(showT); showTip(box(info).querySelector('[data-nu-tile]'), 'tip', info); }   // touch: tap ⓘ; Esc / tap elsewhere closes
		}, true);
		// touch: a long press shows the description (hover card in that mode) and does not open the tile or the browser's link menu
		viewEl.addEventListener('pointerdown', function (e) {
			if (e.pointerType !== 'touch') return;
			var a = tileOf(e.target);
			if (!a || (e.target.closest && e.target.closest('.nuHomeAction, [data-nu-descinfo]')) || eff(size).desc === 'off') return;
			pressT = setTimeout(function () { suppress = true; showTip(a, eff(size).desc === 'hovercard' ? 'card' : 'tip'); if (navigator.vibrate) navigator.vibrate(10); }, 500);
		});
		['pointerup', 'pointercancel'].forEach(function (ev) { viewEl.addEventListener(ev, function () { clearTimeout(pressT); }); });
		viewEl.addEventListener('pointermove', function (e) { if (e.pointerType === 'touch' && Math.abs(e.movementX) + Math.abs(e.movementY) > 4) clearTimeout(pressT); });
		viewEl.addEventListener('contextmenu', function (e) { if (suppress || tipFor) e.preventDefault(); });
	}
	tip.addEventListener('mouseover', function () { clearTimeout(hideT); });
	tip.addEventListener('mouseout', function (e) { if (!tip.contains(e.relatedTarget)) hideTip(); });
	document.addEventListener('pointerdown', function (e) { if (tipFor && !tip.contains(e.target) && !(e.target.closest && e.target.closest('[data-nu-descinfo]')) && tileOf(e.target) !== tipFor) hideTip(true); });
	window.addEventListener('scroll', function (e) { if (tipFor && !(e.target && e.target.nodeType === 1 && tip.contains(e.target))) hideTip(true); }, true);
	window.addEventListener('resize', function () { if (tipFor) hideTip(true); });

	// ---------------------------------------------------------------- "Favourites only" (0.8.353): saved per login
	var favBtn = document.getElementById('nuHomeFavOnly');
	if (favBtn && viewEl) {
		favBtn.addEventListener('click', function () {
			var on = !viewEl.classList.contains('nuHomeFavOnly');
			viewEl.classList.toggle('nuHomeFavOnly', on);
			favBtn.classList.toggle('active', on);
			favBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
			favBtn.querySelector('i').className = 'bi ' + (on ? 'bi-star-fill' : 'bi-star');
			applyView();
			post({action: 'fav_only', on: on}).catch(function () { /* the view changed anyway; kept for this page only */ });
			H.emit('favourites', on);
		});
	}

	// ================================================================ the View panel (0.8.420)
	// Where a change goes: a personal layout → the layout; a shared / the built-in layout → the person's own view (overlay), for
	// "Start page layout" admins also the layout itself ("Everyone" – chosen anew each time the panel opens).
	var vp = document.getElementById('nuHomeViewPanel'), viewBtn = document.getElementById('nuHomeViewBtn');
	var VP = {open: false, everyone: false, scopeDev: 'all', scopeTab: 'all', more: false, snap: null, undone: false, saveT: 0};
	var STYLES = ['large', 'cards', 'compact', 'list', 'launcher', 'panels', 'table'];
	var DESCS = [['auto', 'bi-magic'], ['inline', 'bi-text-paragraph'], ['tooltip', 'bi-chat-square-text'], ['hovercard', 'bi-window'], ['info', 'bi-info-circle'], ['off', 'bi-eye-slash']];
	var NAVS = ['groups', 'tabs', 'sidebar', 'none'];
	var KEYNAME = {style: 'home_v_style', listCols: 'home_v_list_cols', listWidth: 'home_v_list_width', desc: 'home_v_desc', lines: 'home_v_lines', delay: 'home_v_delay',
		nav: 'home_v_nav', density: 'home_v_density', icon: 'home_v_icons', groupColors: 'home_v_group_colors', favStrip: 'home_v_fav_row', recentStrip: 'home_v_recent_row',
		badges: 'home_v_badges', actions: 'home_v_actions'};
	function sizeName(z) { return t('home_v_size_' + z); }
	function target() { return VS.scope === 'user' ? 'layout' : (VS.editable && VP.everyone ? 'layout' : 'me'); }
	function bag(z) {
		if (target() === 'layout') {
			if (!z) return VD;
			if (!VD.sizes[z] || !Object.keys(VD.sizes[z]).length) VD.sizes[z] = Object.assign({}, layoutSize(z));   // phone: the built-in values become own ones
			return VD.sizes[z];
		}
		VO = VO || {view: {}, sizes: {tablet: {}, phone: {}}};
		return z ? VO.sizes[z] : VO.view;
	}
	function setOpt(k, val) {
		if (VP.scopeDev === 'this' && size !== 'desktop') { bag(size)[k] = val; viewChanged(); return; }
		var before = {};
		if (VP.scopeDev === 'this') SIZES.forEach(function (z) { before[z] = eff(z)[k]; });   // only the desktop: tablet and phone keep what they show
		bag(null)[k] = val;
		if (VP.scopeDev === 'this') SIZES.forEach(function (z) { if (eff(z)[k] !== before[z]) bag(z)[k] = before[z]; });
		else if (size !== 'desktop') delete bag(size)[k];                                    // all sizes: this size follows again
		viewChanged();
	}
	function setStyle(val) {
		var v = eff(size), tabbed = v.nav === 'tabs' || v.nav === 'sidebar';
		if (VP.scopeDev === 'this' && size !== 'desktop') { setOpt('style', val); return; }
		if (tabbed && target() === 'layout') {
			if (VP.scopeTab === 'this') { VD.tabStyle[panelUnit()] = val; viewChanged(); return; }
			VD.tabStyle = {};
		}
		setOpt('style', val);
	}
	function ownKeys(z) {
		if (z === 'desktop') return [];
		var k = Object.keys(target() === 'layout' ? (VD.sizes[z] || {}) : {}).concat(VO ? Object.keys(VO.sizes[z]) : []);
		return k.filter(function (x, i) { return k.indexOf(x) === i && KEYNAME[x]; });
	}
	function ovCount() { return VO ? Object.keys(VO.view).length + Object.keys(VO.sizes.tablet).length + Object.keys(VO.sizes.phone).length : 0; }
	function viewChanged() {
		applyView();
		drawPanel();
		clearTimeout(VP.saveT);
		var tg = target();
		VP.saveT = setTimeout(function () { saveView(tg); }, 400);
	}
	function saveView(tg) {
		var body = tg === 'layout' ? {action: 'view_layout', view: VD} : {action: 'view_me', view: VO ? VO.view : {}, sizes: VO ? VO.sizes : {}};
		return post(body).then(function (j) {
			if (!j.ok) { toast(j.message || t('home_saved_err'), false); return; }
			if (tg === 'me') VO = normOv(j.overlay);
			else if (j.view) D.layout.view = j.view;
			var s = vp && $('.nuHomeVpSaved', vp);
			if (s) { s.classList.add('nuHomeVpShow'); setTimeout(function () { s.classList.remove('nuHomeVpShow'); }, 1600); }
			if (tg === 'layout' && VS.scope !== 'user' && !VP.undone) {       // "for everyone": once per opening, with Undo
				VP.undone = true;
				var snap = VP.snap;
				toast(t('home_v_saved_everyone'), true, function () { VD = normView(snap); applyView(); drawPanel(); saveView('layout'); });
			}
		}).catch(function (e) { toast(t('home_saved_err') + ' ' + e.message, false); });
	}
	function clearMine() {
		VO = null;
		applyView(); drawPanel();
		post({action: 'view_me_clear'}).then(function (j) { if (!j.ok) toast(j.message || t('home_saved_err'), false); });
	}
	function openPanel() {
		if (!vp) return;
		VP.open = true; VP.everyone = false; VP.scopeDev = 'all'; VP.scopeTab = 'all'; VP.snap = clone(VD); VP.undone = false;
		vp.hidden = false;
		document.body.classList.add('nuHomeVpOpen');
		if (viewBtn) viewBtn.setAttribute('aria-expanded', 'true');
		drawPanel();
		var h = $('#nuHomeVpTitle', vp);
		if (h) h.focus();
		setTimeout(measure, 250);                                   // the page got narrower (drawer)
	}
	function closePanel() {
		if (!vp || !VP.open) return;
		VP.open = false;
		vp.hidden = true;
		document.body.classList.remove('nuHomeVpOpen');
		if (viewBtn) { viewBtn.setAttribute('aria-expanded', 'false'); viewBtn.focus(); }
		setTimeout(measure, 250);
	}
	if (viewBtn) viewBtn.addEventListener('click', function () { if (VP.open) closePanel(); else openPanel(); });
	document.addEventListener('keydown', function (e) {
		if (e.ctrlKey || e.metaKey || e.altKey || typing() || document.body.classList.contains('nuHomeEditing') || $('.modal.show')) return;
		if ((e.key === 'v' || e.key === 'V') && viewBtn) { e.preventDefault(); if (VP.open) closePanel(); else openPanel(); }
		else if (e.key === 'Escape') { if (tipFor) hideTip(true); else if (VP.open) closePanel(); }
	});
	if (vp) vp.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !typing()) { e.stopPropagation(); closePanel(); } });

	// ---------------------------------------------------------------- drawing the panel (again after every change; focus is kept)
	function R(x, y, w, h, rx, o) { return '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="' + (rx || 1) + '" opacity="' + (o || 1) + '"/>'; }
	function card(x, y, w, h) { return '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="2" class="nuHomeThCard"/>'; }
	var THUMB = {
		large: [4, 32].map(function (x) { return [4, 22].map(function (y) { return card(x, y, 24, 14) + R(x + 3, y + 4, 6, 6, 1.5, 0.55) + R(x + 11, y + 4.5, 10, 2) + R(x + 11, y + 8.5, 8, 1.5, 1, 0.4); }).join(''); }).join(''),
		cards: [4, 22.5, 41].map(function (x) { return card(x, 5, 15, 30) + R(x + 2.5, 8, 6, 6, 1.5, 0.55) + R(x + 2.5, 18, 9, 2) + R(x + 2.5, 22, 10, 1.5, 1, 0.4) + R(x + 2.5, 25.5, 7, 1.5, 1, 0.4); }).join(''),
		compact: [5, 14, 23, 32].map(function (y) { return [4, 23, 42].map(function (x) { return card(x, y, 15, 6.5) + R(x + 1.5, y + 1.5, 3.5, 3.5, 1, 0.55) + R(x + 6.5, y + 2.5, 6, 1.5); }).join(''); }).join(''),
		list: card(10, 4, 40, 32) + [7, 15, 23, 30].map(function (y) { return R(13, y, 4.5, 4.5, 1, 0.55) + R(20, y + 0.4, 14, 1.8) + R(20, y + 3.2, 20, 1.2, 1, 0.4); }).join(''),
		launcher: [6, 23].map(function (y) { return [6, 19, 32, 45].map(function (x) { return R(x, y, 9, 9, 2.5, 0.6) + R(x + 1, y + 11, 7, 1.5, 1, 0.45); }).join(''); }).join(''),
		panels: card(4, 4, 25, 32) + R(7, 7, 12, 2.2) + [13, 18, 23, 28].map(function (y) { return R(7, y, 3, 3, 0.8, 0.5) + R(12, y + 0.7, 12, 1.5, 1, 0.5); }).join('')
			+ card(31, 4, 25, 20) + R(34, 7, 10, 2.2) + [13, 18].map(function (y) { return R(34, y, 3, 3, 0.8, 0.5) + R(39, y + 0.7, 12, 1.5, 1, 0.5); }).join(''),
		table: card(4, 4, 52, 32) + R(7, 7, 46, 2, 0.5, 0.35) + [12, 17, 22, 27, 32].map(function (y) { return R(7, y, 3, 3, 0.8, 0.55) + R(12, y + 0.7, 9, 1.5) + R(24, y + 0.7, 16, 1.5, 1, 0.4) + R(44, y + 0.5, 8, 2, 1, 0.3); }).join(''),
		groups: R(4, 4, 14, 1.8, 0.5, 0.5) + [4, 22, 40].map(function (x) { return card(x, 8, 16, 9); }).join('') + R(4, 22, 14, 1.8, 0.5, 0.5) + [4, 22].map(function (x) { return card(x, 26, 16, 9); }).join(''),
		tabs: R(4, 4, 12, 3, 0.8) + R(18, 4, 12, 3, 0.8, 0.35) + R(4, 8.5, 52, 0.8, 0.3, 0.3) + [4, 22, 40].map(function (x) { return card(x, 13, 16, 9); }).join('') + [4, 22].map(function (x) { return card(x, 25, 16, 9); }).join(''),
		sidebar: R(4, 5, 13, 3, 0.8, 0.55) + R(4, 11, 13, 2, 0.8, 0.3) + R(4, 16, 13, 2, 0.8, 0.3) + R(4, 21, 13, 2, 0.8, 0.3) + R(4, 26, 13, 2, 0.8, 0.3) + [21, 39].map(function (x) { return card(x, 5, 16, 13); }).join('') + card(21, 21, 16, 13),
		none: [4, 22, 40].map(function (x) { return [5, 17, 29].map(function (y) { return card(x, y, 16, 8); }).join(''); }).join('')
	};
	function thumb(id) {
		var s = el('span', {'aria-hidden': 'true'});
		s.innerHTML = '<svg class="nuHomeTh" viewBox="0 0 60 40" focusable="false">' + THUMB[id] + '</svg>';   // our own fixed drawing, no data
		return s.firstChild;
	}
	/** Segmented buttons: items [value, label, icon]; fn(value). */
	function seg(id, items, cur, fn, cls) {
		return el('div', {'class': 'nuHomeSeg' + (cls ? ' ' + cls : ''), role: 'group'}, items.map(function (it) {
			var on = String(cur) === String(it[0]);
			return el('button', {type: 'button', 'data-f': id + ':' + it[0], 'aria-pressed': on ? 'true' : 'false', title: it[3] || null, onclick: function () { if (!on) fn(it[0]); }},
				[it[2] ? icon(it[2]) : null, it[1]]);
		}));
	}
	function sw(id, label, on, hint, fn) {
		var inp = el('input', {'class': 'form-check-input', type: 'checkbox', role: 'switch', id: 'nuHomeVp_' + id, 'data-f': 'sw:' + id, checked: on ? 'checked' : null});
		inp.addEventListener('change', function () { fn(inp.checked); });
		return el('label', {'class': 'nuHomeVpSw form-switch', 'for': 'nuHomeVp_' + id}, [el('span', {}, [el('b', {text: label}), hint ? el('small', {text: hint}) : null]), inp]);
	}
	function ownDot(k) { return ownKeys(size).indexOf(k) >= 0 ? el('span', {'class': 'nuHomeVpOwn', title: tf('home_v_own_one', sizeName(size))}, [sizeName(size)]) : null; }
	function lbl(key, k) { return el('div', {'class': 'nuHomeVpLbl'}, [t(key), k ? ownDot(k) : null]); }
	function note(kids, warn) { return el('p', {'class': 'nuHomeVpNote' + (warn ? ' nuHomeVpWarn' : '')}, kids); }
	function link(text, fn, f) { return el('button', {type: 'button', 'class': 'nuHomeVpLink', 'data-f': f || null, onclick: fn}, [text]); }

	function drawPanel() {
		if (!vp || !VP.open) return;
		var focus = document.activeElement && vp.contains(document.activeElement) ? document.activeElement.getAttribute('data-f') : null;
		var old = $('.nuHomeVpBody', vp), top = old ? old.scrollTop : 0;
		var v = eff(size), tabbed = v.nav === 'tabs' || v.nav === 'sidebar', unit = panelUnit(), st = styleFor(unit, size, v), tg = target();
		var secs = [];

		// layout: the saved layouts, Save as, ⋯ (name / visibility, delete, default for everyone); where changes go
		var sel = el('select', {'class': 'form-select form-select-sm', id: 'nuHomeVpLayout', 'data-f': 'layout'});
		var groupsOf = {shared: el('optgroup', {label: t('home_layout_scope_shared')}), user: el('optgroup', {label: t('home_v_personal')})};
		(VS.layouts || []).forEach(function (l) {
			var o = el('option', {value: l.id, text: l.name + (l.isDefault ? ' (' + t('home_v_default') + ')' : '')});
			if (l.id === VS.id) o.selected = true;
			(l.scope === 'user' ? groupsOf.user : groupsOf.shared).appendChild(o);
		});
		[groupsOf.shared, groupsOf.user].forEach(function (g) { if (g.children.length) sel.appendChild(g); });
		sel.addEventListener('change', function () { layoutPost({action: 'layout_select', id: sel.value}); });
		var more = [];
		if (VS.canMeta) more.push(['meta', 'bi-pencil-square', t('home_layout_meta')]);
		if (VS.canDefault) more.push(['default', 'bi-pin-angle', t('home_layout_make_default')]);
		if (VS.canDelete) more.push(['delete', 'bi-trash', t('home_layout_delete')]);
		var moreBox = more.length ? el('div', {'class': 'dropdown'}, [
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', title: t('home_v_more'), 'aria-label': t('home_v_more'), 'data-f': 'more'}, [icon('bi-three-dots')]),
			el('ul', {'class': 'dropdown-menu dropdown-menu-end shadow-sm'}, more.map(function (m) {
				return el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item' + (m[0] === 'delete' ? ' text-danger' : ''), onclick: function () {
					if (m[0] === 'meta') layoutDialog('meta');
					else if (m[0] === 'delete') { if (window.confirm(t('home_layout_delete_confirm'))) layoutPost({action: 'layout_delete'}); }
					else layoutPost({action: 'layout_default'});
				}}, [icon(m[1] + ' me-2'), m[2]])]);
			}))]) : null;
		var store2 = [], sc = VS.scope;
		if (sc === 'user') store2.push(el('span', {text: t('home_v_store_personal')}));
		else if (!VS.editable) {
			store2.push(el('span', {text: t('home_v_store_shared_user') + ' '}));
			if (ovCount()) store2.push(link(tf('home_v_undo_mine', ovCount()), clearMine, 'undomine'));
		} else store2.push(el('span', {text: tf('home_v_store_shared_admin', VS.name)}));
		var layoutSec = [lbl('home_v_layout'), el('div', {'class': 'nuHomeVpRow'}, [el('label', {'class': 'visually-hidden', 'for': 'nuHomeVpLayout', text: t('home_v_layout')}), sel,
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', title: t('home_layout_saveas'), 'aria-label': t('home_layout_saveas'), 'data-f': 'saveas', onclick: function () { layoutDialog('saveas'); }}, [icon('bi-plus-lg')]), moreBox]),
			el('p', {'class': 'nuHomeVpStore'}, [icon(sc === 'user' ? 'bi-person' : 'bi-people')].concat(store2))];
		if (sc !== 'user' && VS.editable) {                         // admins of shared layouts: only me (default) or everyone using it
			layoutSec.push(el('div', {'class': 'nuHomeVpTarget'}, [el('span', {text: t('home_v_applies_to')}),
				seg('target', [['me', t('home_v_only_me')], ['all', tf('home_v_everyone', VS.name)]], VP.everyone ? 'all' : 'me', function (x) { VP.everyone = x === 'all'; drawPanel(); }, 'nuHomeSeg2')]));
			if (VP.everyone) layoutSec.push(note([icon('bi-exclamation-triangle'), tf('home_v_everyone_note', VS.name)], true));
			else if (ovCount()) layoutSec.push(note([tf('home_v_mine_count', ovCount()) + ' ', link(t('home_v_make_layout'), makeLayoutView, 'mklayout'), ' · ', link(tf('home_v_undo_mine', ovCount()), clearMine, 'undomine')]));
		}
		secs.push(el('section', {'class': 'nuHomeVpSec'}, layoutSec));

		// settings for: all screen sizes / only this one
		var own = ownKeys(size), dev = {desktop: 'bi-display', tablet: 'bi-tablet', phone: 'bi-phone'}[size];
		var scope = [lbl('home_v_settings_for'), seg('scopeDev', [['all', t('home_v_all_sizes'), 'bi-aspect-ratio'], ['this', tf('home_v_only_size', sizeName(size)), dev]], VP.scopeDev,
			function (x) { VP.scopeDev = x; drawPanel(); }, 'nuHomeSeg2')];
		if (own.length) scope.push(note([icon(dev), tf('home_v_own_settings', sizeName(size), own.map(function (k) { return t(KEYNAME[k]); }).join(', ')) + ' ',
			link(t('home_v_same_all_sizes'), function () {
				if (target() === 'layout') delete VD.sizes[size];
				if (VO) VO.sizes[size] = {};
				VP.scopeDev = 'all';
				viewChanged();
			}, 'sameall')]));
		else if (VP.scopeDev === 'this') scope.push(note([tf('home_v_only_size_note', sizeName(size))]));
		secs.push(el('section', {'class': 'nuHomeVpSec'}, scope));

		// tile style (per tab when the layout is the target)
		var stl = [lbl('home_v_style', 'style'), el('div', {'class': 'nuHomeVpPick', role: 'group', 'aria-label': t('home_v_style')}, STYLES.map(function (s) {
			return el('button', {type: 'button', 'data-f': 'style:' + s, 'aria-pressed': st === s ? 'true' : 'false', title: t('home_v_style_' + s + '_hint'), onclick: function () { setStyle(s); }},
				[thumb(s), el('span', {text: t('home_v_style_' + s)})]);
		})), el('p', {'class': 'nuHomeVpHelp', text: t('home_v_style_' + st + '_hint')})];
		if (st === 'list') {
			stl.push(el('div', {'class': 'nuHomeVpSub'}, [el('span', {text: t('home_v_list_cols')}), seg('listCols', [['1', '1'], ['2', '2'], ['auto', t('home_v_auto')]], v.listCols, function (x) { setOpt('listCols', x); })]));
			if (v.listCols === '1') stl.push(el('div', {'class': 'nuHomeVpSub'}, [el('span', {text: t('home_v_list_width')}), seg('listWidth', [['readable', t('home_v_readable')], ['full', t('home_v_full')]], v.listWidth, function (x) { setOpt('listWidth', x); })]));
		}
		if (tabbed && VP.scopeDev === 'all' && (VS.units || []).length > 1) {
			var tabOwn = (VS.units || []).filter(function (u) { return VD.tabStyle[u.id]; });
			if (tg === 'layout') {
				stl.push(el('div', {'class': 'nuHomeVpSub nuHomeVpTabScope'}, [el('span', {text: t('home_v_use_for')}),
					seg('scopeTab', [['all', t('home_v_all_tabs')], ['this', tf('home_v_only_tab', unitTitle(unit)), null, unitTitle(unit)]], VP.scopeTab, function (x) { VP.scopeTab = x; drawPanel(); })]));
				if (tabOwn.length) stl.push(note([icon('bi-layout-three-columns'), tabOwn.map(function (u) { return '“' + u.title + '”: ' + t('home_v_style_' + VD.tabStyle[u.id]); }).join(' · ') + ' ',
					link(t('home_v_same_all_tabs'), function () { VD.tabStyle = {}; VP.scopeTab = 'all'; viewChanged(); }, 'samealltabs')]));
			} else if (tabOwn.length) stl.push(note([t('home_v_tab_owner')]));
		}
		var sizeStyle = (VO && VO.sizes[size] && VO.sizes[size].style) || (size !== 'desktop' && layoutSize(size).style);
		if (sizeStyle && tabbed && size !== 'desktop') stl.push(note([icon('bi-exclamation-circle'), tf('home_v_size_beats_tab', sizeName(size), t('home_v_style_' + sizeStyle))], true));
		secs.push(el('section', {'class': 'nuHomeVpSec'}, stl));

		// descriptions
		var ds = [lbl('home_v_desc', 'desc'), seg('desc', DESCS.map(function (d) { return [d[0], t('home_v_desc_' + d[0]), d[1], t('home_v_desc_' + d[0] + '_hint')]; }), v.desc, function (x) { setOpt('desc', x); }, 'nuHomeSeg3'),
			el('p', {'class': 'nuHomeVpHelp', text: t('home_v_desc_' + v.desc + '_hint')})];
		if (v.desc !== 'off' && v.desc !== 'info') ds.push(el('div', {'class': 'nuHomeVpSub'}, [el('span', {text: t('home_v_lines')}), seg('lines', [[1, '1'], [2, '2'], [3, '3'], [0, t('home_v_all')]], v.lines, function (x) { setOpt('lines', +x); })]));
		if (['auto', 'tooltip', 'hovercard'].indexOf(v.desc) >= 0) ds.push(el('div', {'class': 'nuHomeVpSub'}, [el('span', {text: t('home_v_delay')}),
			seg('delay', [[0, t('home_v_delay_none')], [300, t('home_v_delay_short')], [800, t('home_v_delay_long')]], v.delay, function (x) { setOpt('delay', +x); })]));
		secs.push(el('section', {'class': 'nuHomeVpSec'}, ds));

		// navigation
		secs.push(el('section', {'class': 'nuHomeVpSec'}, [lbl('home_v_nav', 'nav'), el('div', {'class': 'nuHomeVpPick', role: 'group', 'aria-label': t('home_v_nav')}, NAVS.map(function (n) {
			return el('button', {type: 'button', 'data-f': 'nav:' + n, 'aria-pressed': v.nav === n ? 'true' : 'false', title: t('home_v_nav_' + n + '_hint'), onclick: function () { if (v.nav !== n) setOpt('nav', n); }},
				[thumb(n), el('span', {text: t('home_v_nav_' + n)})]);
		})), el('p', {'class': 'nuHomeVpHelp', text: t('home_v_nav_' + v.nav + '_hint')})]));

		// on top of the page
		secs.push(el('section', {'class': 'nuHomeVpSec'}, [lbl('home_v_on_top'),
			sw('favStrip', t('home_v_fav_row'), v.favStrip, t('home_v_fav_row_hint'), function (x) { setOpt('favStrip', x); }),
			sw('recentStrip', t('home_v_recent_row'), v.recentStrip, t('home_v_recent_row_hint'), function (x) { setOpt('recentStrip', x); })]));

		// look (collapsed)
		var look = [el('button', {type: 'button', 'class': 'nuHomeVpMore', 'data-f': 'look', 'aria-expanded': VP.more ? 'true' : 'false', onclick: function () { VP.more = !VP.more; drawPanel(); }},
			[el('span', {'class': 'nuHomeVpLbl', text: t('home_v_look')}), icon('bi-chevron-' + (VP.more ? 'up' : 'down'))])];
		if (VP.more) {
			look.push(el('div', {'class': 'nuHomeVpSub'}, [el('span', {text: t('home_v_density')}),
				seg('density', [['comfortable', t('home_v_roomy')], ['cozy', t('home_v_medium')], ['dense', t('home_v_dense')]], v.density, function (x) { setOpt('density', x); })]));
			look.push(el('div', {'class': 'nuHomeVpSub nuHomeVpCol'}, [el('span', {text: t('home_v_icons')}), el('div', {'class': 'nuHomeVpPick nuHomeVpPickIcon', role: 'group', 'aria-label': t('home_v_icons')},
				['soft', 'solid', 'outline', 'mono', 'none'].map(function (i) {
					var sample = el('span', {'class': 'nu-tile-icon nuHomeIcon'}, [icon('bi-people')]);
					return el('button', {type: 'button', 'class': 'nuHomeIcn-' + i, 'data-f': 'icon:' + i, 'aria-pressed': v.icon === i ? 'true' : 'false', onclick: function () { setOpt('icon', i); }},
						[sample, el('span', {text: t('home_v_icon_' + i)})]);
				}))]));
			look.push(sw('groupColors', t('home_v_group_colors'), v.groupColors, '', function (x) { setOpt('groupColors', x); }));
			look.push(sw('badges', t('home_v_badges'), v.badges, t('home_v_badges_hint'), function (x) { setOpt('badges', x); }));
			look.push(sw('actions', t('home_v_actions'), v.actions, t('home_v_actions_hint'), function (x) { setOpt('actions', x); }));
		}
		secs.push(el('section', {'class': 'nuHomeVpSec'}, look));

		vp.textContent = '';
		vp.appendChild(el('div', {'class': 'nuHomeVpHead'}, [el('h2', {id: 'nuHomeVpTitle', tabindex: '-1'}, [icon('bi-sliders2'), t('home_v_view')]),
			el('button', {type: 'button', 'class': 'btn-close', 'aria-label': t('close'), title: t('close'), 'data-f': 'close', onclick: closePanel})]));
		var body = el('div', {'class': 'nuHomeVpBody'}, secs);
		vp.appendChild(body);
		vp.appendChild(el('div', {'class': 'nuHomeVpFoot'}, [
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-f': 'reset', onclick: resetView}, [icon('bi-arrow-counterclockwise me-1'), t('home_v_reset')]),
			el('span', {'class': 'nuHomeVpSaved', role: 'status'}, [icon('bi-check2 me-1'), t('home_v_saved')])]));
		body.scrollTop = top;
		if (focus) { var f = vp.querySelector('[data-f="' + focus.replace(/["\\]/g, '') + '"]'); if (f) f.focus({preventScroll: true}); }
		// the icon samples: the group colour of the first group
		var g0 = viewEl && $('.nuHomeGroup', viewEl);
		$$('.nuHomeVpPickIcon .nuHomeIcon', vp).forEach(function (s) { s.style.setProperty('--nu-ic', g0 ? g0.getAttribute('data-nu-gcolor') : '#2f6fd6'); });
	}
	function makeLayoutView() {                                    // an admin's own view becomes the layout's view (for everyone)
		if (!VO) return;
		Object.assign(VD, VO.view);
		SIZES.forEach(function (z) { if (Object.keys(VO.sizes[z]).length) VD.sizes[z] = Object.assign({}, layoutSize(z), VO.sizes[z]); });
		VO = null;
		applyView(); drawPanel();
		saveView('layout').then(function () { post({action: 'view_me_clear'}); });
	}
	function resetView() {
		if (target() === 'me') { clearMine(); toast(t('home_v_reset_done'), true); return; }
		if (VS.scope !== 'user' && !window.confirm(tf('home_v_reset_everyone', VS.name))) return;
		VD = normView(Object.assign({}, DEF));
		VP.scopeDev = 'all'; VP.scopeTab = 'all';
		viewChanged();
		toast(t('home_v_reset_done'), true);
	}

	if (viewEl) {
		showUnit(curUnit);
		showSide(curGroup);
		applyView();
		if (typeof ResizeObserver === 'function' && wrapEl) new ResizeObserver(measure).observe(wrapEl); else window.addEventListener('resize', measure);
		measure();
	}

	// ================================================================ edit mode (0.8.263): the start page itself is edited
	// Customize turns the page into an editable copy of itself: tiles and groups are dragged (mouse; on touch screens hold
	// briefly, then drag), group and tab names are typed in place, Done saves the whole layout, Cancel throws it away.
	var btn = $('#nuHomeCustomize'), view = $('#nuHomeView'), edit = $('#nuHomeEdit');
	if (!edit) return;
	var btnBox = $('.nuHomeTools') || document.createElement('span');   // View and Customise (in the search row, hidden with it)
	var L = null;                        // layout being edited (copy)
	var dirty = false;
	var activeTab = '';                  // tab shown while editing (display tabs)
	var editFav = false;                 // Customise: show only the favourite tiles (0.8.354) – a view filter, not saved
	var baseL = null;                    // the saved layout when Customise opened (own tiles for "Start from", 0.8.385)
	var prevEdit = null;                 // before the last profile was loaded: {L, dirty, activeTab} – Undo
	var pfNote = null;                   // strip under the bar after loading a profile: {icon, text}
	var keepOwn = true;                  // Start from: take own tiles along

	function info(key) {
		if (D.catalog[key]) return {icon: D.catalog[key].icon, label: D.catalog[key].label, hint: D.catalog[key].hint || '', own: false, color: ''};
		var c = L.custom[key];
		return c ? {icon: c.icon || 'bi-star', label: c.label, hint: c.hint || '', own: true, color: c.color || ''} : null;
	}
	function newId(prefix) { return prefix + Math.random().toString(36).slice(2, 9); }
	function removeKey(key) {
		var drop = function (list) { var i; while ((i = list.indexOf(key)) >= 0) list.splice(i, 1); };
		L.groups.forEach(function (g) { drop(g.tiles); });
		drop(L.hidden);
	}
	function changed() { dirty = true; }
	function tabsOn() { return L.display === 'tabs' && L.tabs.length > 0; }
	function groupsOfTab() { return tabsOn() ? L.groups.filter(function (g) { return g.tab === activeTab; }) : L.groups; }
	/** The group new or shown tiles go to: the last group of the open tab (created when there is none). */
	function targetGroup(first) {
		var list = groupsOfTab();
		if (!list.length) { var g = {id: newId('g_'), title: '', tab: tabsOn() ? activeTab : '', tiles: []}; L.groups.push(g); return g; }
		return first ? list[0] : list[list.length - 1];
	}

	function open() {
		L = clone(D.layout);
		if (Array.isArray(L.custom)) L.custom = {};
		L.fav = Array.isArray(L.fav) ? L.fav : [];
		L.tabs = L.tabs || [];
		dirty = false;
		baseL = clone(L); prevEdit = null; pfNote = null; keepOwn = true;
		var shown = $('.nuHomeTabs .nav-link.active');          // keep the tab that was open in the view
		var id = shown ? shown.id.replace('nuHomeTab_', '') : '';
		activeTab = L.tabs.some(function (tb) { return tb.id === id; }) ? id : (L.tabs[0] ? L.tabs[0].id : '');
		editFav = !!(viewEl && viewEl.classList.contains('nuHomeFavOnly')) && L.fav.length > 0;   // as on the start page
		clearSearch();
		closePanel();
		hideTip(true);
		view.hidden = true;
		btnBox.hidden = true;
		if (searchRow) searchRow.hidden = true;
		edit.hidden = false;
		document.body.classList.add('nuHomeEditing');
		render();
		H.emit('customize', true);
	}
	function close() {
		edit.hidden = true;
		edit.textContent = '';
		view.hidden = false;
		btnBox.hidden = false;
		if (searchRow) searchRow.hidden = false;
		document.body.classList.remove('nuHomeEditing');
		dirty = false;
		prevEdit = null; pfNote = null;
		H.emit('customize', false);
	}
	if (btn) btn.addEventListener('click', open);
	window.addEventListener('beforeunload', function (e) { if (dirty && !edit.hidden) { e.preventDefault(); e.returnValue = ''; } });


	// ---------------------------------------------------------------- pin in a tile's ▾ menu (0.8.321): that page as a tile of its own
	$$('[data-nu-pin]').forEach(function (b) {
		b.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			b.disabled = true;
			post({action: 'pin', key: b.getAttribute('data-nu-pin')}).then(function (j) {
				if (!j.ok) { b.disabled = false; window.alert(j.message || t('home_saved_err')); return; }
				toast(t(j.pinned ? 'home_pinned' : 'home_unpinned'), true);
				setTimeout(function () { window.location.href = D.url; }, 600);
			}).catch(function (e2) { b.disabled = false; window.alert(t('home_saved_err') + ' ' + e2.message); });
		});
	});

	// ---------------------------------------------------------------- search (0.8.321): filters the tiles as you type, "More results"
	// lists pages, actions, forms … that are no tile (same list as Ctrl+K – assets/js/nupalette.js); Enter opens the first hit, / focuses
	var searchRow = $('.nuHomeSearchRow'), search = $('#nuHomeSearch');
	var moreBox = $('#nuHomeSearchMore'), moreList = $('.nuHomeSearchList'), noneBox = $('#nuHomeSearchNone');
	var palItems = null, firstMore = null;
	function clearSearch() { if (search && search.value) { search.value = ''; filter(); } }
	function filter() {
		var q = search.value, P = window.nuPalette, words = (P ? P.norm(q) : q.toLowerCase()).split(/\s+/).filter(Boolean);
		var byId = {};
		(palItems || []).forEach(function (it) { byId[it.id] = it; });
		var shown = 0, first = null, onPage = {}, shownEls = [];
		var was = view.classList.contains('nuHomeSearching');
		view.classList.toggle('nuHomeSearching', words.length > 0);
		if (was !== words.length > 0) { hideTip(true); applyView(); }   // while searching: all groups in the layout's style, no tabs
		$$('[data-nu-tile]', view).forEach(function (a) {
			var key = a.getAttribute('data-nu-tile'), it = byId['t:' + key];
			onPage['t:' + key] = true;
			var hay = it ? it.hay : (P ? P.norm(a.textContent) : a.textContent.toLowerCase());
			var ok = words.every(function (w) { return hay.indexOf(w) >= 0; });
			ok = ok && !a.hasAttribute('data-nu-js-hidden');                  // hidden by nuHome tile.hide() (0.8.379)
			box(a).hidden = !ok;
			if (ok) { shown++; if (!first) first = a; shownEls.push(a); }
		});
		$$('.nuHomeGroup', view).forEach(function (g) { g.hidden = words.length > 0 && !$$('[data-nu-tile]', g).some(function (a) { return !box(a).hidden; }); });
		// more results: what matches and is not a tile here
		moreList.textContent = '';
		firstMore = null;
		var more = words.length && P && palItems ? P.match(palItems, q).filter(function (it) { return !onPage[it.id]; }).slice(0, 8) : [];
		more.forEach(function (it) {
			var a = el('a', {'class': 'list-group-item list-group-item-action d-flex align-items-center gap-3', href: it.url || '#'}, [
				el('span', {'class': 'nu-tile-icon'}, [icon(it.icon)]),
				el('span', {'class': 'flex-grow-1 min-w-0'}, [el('span', {'class': 'd-block text-body', text: it.label}), el('span', {'class': 'd-block small text-body-secondary', text: it.group})])]);
			a.addEventListener('click', function (e) { e.preventDefault(); P.go(it, e.ctrlKey || e.metaKey); });
			moreList.appendChild(a);
			if (!firstMore) firstMore = it;
		});
		moreBox.hidden = !more.length;
		noneBox.hidden = !words.length || shown > 0 || more.length > 0;
		search.setAttribute('data-first', first ? first.getAttribute('data-nu-tile') : '');
		if (!words.length) H.groups();
		H.emit('search', q, shownEls.map(H.wrap));
	}
	if (search) {
		search.addEventListener('input', function () {
			filter();
			if (!palItems && window.nuPalette && search.value.trim()) window.nuPalette.load().then(function (list) { palItems = list; filter(); }).catch(function () { /* tiles only */ });
		});
		search.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { search.value = ''; filter(); return; }
			if (e.key !== 'Enter') return;
			e.preventDefault();
			var k = search.getAttribute('data-first'), a = k ? $('[data-nu-tile="' + k + '"]', view) : null;
			if (a) a.click();
			else if (firstMore) window.nuPalette.go(firstMore, e.ctrlKey || e.metaKey);
		});
		document.addEventListener('keydown', function (e) {
			if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || !edit.hidden) return;
			var tag = (document.activeElement && document.activeElement.tagName) || '';
			if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (document.activeElement && document.activeElement.isContentEditable)) return;
			e.preventDefault();
			search.focus();
		});
	}

	// saved layouts (0.8.333, in the View panel since 0.8.420): switch at once; Save as / name and visibility in a small dialog
	var SV = D.saved || {};
	function layoutPost(body) {
		post(body).then(function (j) {
			if (j.ok) { window.location.href = D.url; return; }
			window.alert(j.message || t('home_saved_err'));
		}).catch(function (e) { window.alert(t('home_saved_err') + ' ' + e.message); });
	}
	function layoutDialog(mode) {
		var cur = SV.current || {}, isNew = mode === 'saveas';
		var scope = isNew ? 'user' : cur.scope, roles = isNew ? [] : (cur.roles || []);
		var wrap = document.createElement('div');
		wrap.className = 'modal fade';
		wrap.tabIndex = -1;
		var rolesHtml = (SV.roles || []).map(function (r) {
			return '<div class="form-check form-check-inline"><input class="form-check-input nuHlRole" type="checkbox" id="nuHlR' + r.id + '" value="' + r.id + '"' + (roles.indexOf(r.id) !== -1 ? ' checked' : '') + '>'
				+ '<label class="form-check-label" for="nuHlR' + r.id + '"></label></div>';
		}).join('');
		// Save as → Based on (0.8.385): the current layout (as before) or a profile, own tiles optionally along
		var P = D.presets || {}, own = isNew ? ownNames(D.layout) : '';
		var baseHtml = isNew ? '<div class="mb-3 nuHlBaseBox"><label class="form-label" for="nuHlBase"></label><select class="form-select" id="nuHlBase"></select>'
			+ '<div class="small mt-2 nuHlBaseInfo"></div>'
			+ (own ? '<div class="form-check form-switch mt-2 nuHlKeepRow"><input class="form-check-input" type="checkbox" role="switch" id="nuHlKeep" checked><label class="form-check-label" for="nuHlKeep"></label></div>' : '')
			+ '</div>' : '';
		wrap.innerHTML = '<div class="modal-dialog' + (isNew ? ' modal-lg' : '') + '"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5"></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
			+ '<div class="modal-body">' + baseHtml + '<label class="form-label" for="nuHlName"></label><input type="text" class="form-control" id="nuHlName" maxlength="100">'
			+ (SV.manage ? '<div class="mt-3"><div class="form-label nuHlScopeLbl"></div><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="nuHlScope" id="nuHlSu" value="user"><label class="form-check-label" for="nuHlSu"></label></div>'
				+ '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="nuHlScope" id="nuHlSs" value="shared"><label class="form-check-label" for="nuHlSs"></label></div></div>'
				+ '<div class="mt-3 nuHlRoles"><div class="form-label nuHlRolesLbl mb-0"></div><div class="small text-body-secondary mb-1 nuHlRolesAll"></div>' + rolesHtml + '</div>' : '')
			+ '<div class="alert alert-danger d-none mt-3 mb-0 nuHlErr"></div></div>'
			+ '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"></button><button type="button" class="btn btn-primary nuHlSave"></button></div></div></div>';
		var q = function (s2) { return wrap.querySelector(s2); };
		q('.modal-title').textContent = isNew ? t('home_layout_saveas') : t('home_layout_meta');
		q('label[for="nuHlName"]').textContent = t('home_layout_name');
		q('#nuHlName').value = isNew ? t('home_layout_copy').replace('%s', cur.name || '') : (cur.name || '');
		q('.modal-footer .btn-outline-secondary').textContent = t('cancel');
		q('.nuHlSave').textContent = t('save');
		if (isNew) {
			var base = q('#nuHlBase'), baseInfo = q('.nuHlBaseInfo'), keepRow = q('.nuHlKeepRow'), nameEl = q('#nuHlName'), touched = false;
			q('label[for="nuHlBase"]').textContent = t('home_layout_based_on');
			base.appendChild(el('option', {value: '', text: tf('home_layout_based_current', cur.name || '')}));
			var og = el('optgroup', {label: t('home_layout_based_profiles')});
			Object.keys(P).forEach(function (id) { og.appendChild(el('option', {value: id, text: P[id].label})); });
			base.appendChild(og);
			if (keepRow) q('label[for="nuHlKeep"]').textContent = tf('home_layout_keep_own', own);
			nameEl.addEventListener('input', function () { touched = true; });
			var syncBase = function () {
				var p = P[base.value];
				baseInfo.textContent = '';
				baseInfo.appendChild(p
					? el('div', {'class': 'd-flex gap-2 align-items-start'}, [icon(p.icon + ' text-primary mt-1'), el('div', {}, [
						el('div', {'class': 'fw-semibold', text: p.label}), el('div', {'class': 'text-body-secondary', text: p.hint}),
						el('div', {'class': 'text-body-secondary', text: countsText(p.counts)})])])
					: el('span', {'class': 'text-body-secondary', text: t('home_layout_based_copy')}));
				if (keepRow) keepRow.hidden = !p;
				if (!touched) nameEl.value = p ? p.label : t('home_layout_copy').replace('%s', cur.name || '');
			};
			base.addEventListener('change', syncBase);
			syncBase();
		}
		if (SV.manage) {
			q('.nuHlScopeLbl').textContent = t('home_layout_scope');
			q('label[for="nuHlSu"]').textContent = t('home_layout_scope_user');
			q('label[for="nuHlSs"]').textContent = t('home_layout_scope_shared');
			q('.nuHlRolesLbl').textContent = t('home_layout_roles');
			q('.nuHlRolesAll').textContent = t('home_layout_roles_all');
			(SV.roles || []).forEach(function (r) { q('label[for="nuHlR' + r.id + '"]').textContent = r.name; });
			q(scope === 'shared' ? '#nuHlSs' : '#nuHlSu').checked = true;
			var sync = function () { q('.nuHlRoles').classList.toggle('d-none', !q('#nuHlSs').checked); };
			wrap.addEventListener('change', sync);
			sync();
		}
		document.body.appendChild(wrap);
		wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); });
		var m = nuBs.modal(wrap);
		m.show();
		wrap.addEventListener('shown.bs.modal', function () { q(isNew ? '#nuHlBase' : '#nuHlName').focus(); });
		q('.nuHlSave').addEventListener('click', function () {
			var sc = wrap.querySelector('input[name="nuHlScope"]:checked');
			var body = {action: isNew ? 'layout_saveas' : 'layout_meta', name: q('#nuHlName').value, scope: sc ? sc.value : (isNew ? 'user' : cur.scope),
				roles: $$('.nuHlRole', wrap).filter(function (c) { return c.checked; }).map(function (c) { return c.value; }),
				preset: isNew ? q('#nuHlBase').value : '', keep_own: isNew && q('#nuHlKeep') ? q('#nuHlKeep').checked : false};
			post(body).then(function (j) {
				if (j.ok) { window.location.href = D.url; return; }
				var a = q('.nuHlErr'); a.textContent = j.message || t('home_saved_err'); a.classList.remove('d-none');
			});
		});
	}
	function post(body) {
		return fetch(D.url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf}, body: JSON.stringify(body)})
			.then(function (r) { return r.json(); });
	}

	// ---------------------------------------------------------------- drawing (the same markup as the page, plus tools)
	function toolBtn(ic, label, fn, cls) {
		return el('button', {type: 'button', 'class': 'btn nuHeToolBtn' + (cls ? ' ' + cls : ''), title: label, 'aria-label': label, onclick: function (e) { e.stopPropagation(); fn(); }}, [icon(ic)]);
	}
	function tileTools(key, i, hidden) {
		return el('div', {'class': 'nuHeTools'}, [
			hidden ? null : toolBtn('bi-chevron-left', t('home_move_left'), function () { move(key, -1); }),
			hidden ? null : toolBtn('bi-chevron-right', t('home_move_right'), function () { move(key, 1); }),
			i.own ? toolBtn('bi-pencil', t('home_edit_tile'), function () { tileDialog(key); }) : null,
			hidden ? null : (L.fav.indexOf(key) >= 0
				? toolBtn('bi-star-fill', t('home_unfav'), function () { L.fav.splice(L.fav.indexOf(key), 1); changed(); render(); }, 'nuHeToolFav')
				: toolBtn('bi-star', t('home_fav_add'), function () { L.fav.push(key); changed(); render(); })),
			hidden ? toolBtn('bi-eye', t('home_show'), function () { show(key); })
				: toolBtn('bi-eye-slash', t('home_hide'), function () { removeKey(key); L.hidden.push(key); changed(); render(); }),
			i.own ? toolBtn('bi-trash', t('home_delete_tile'), function () {
				if (!window.confirm(t('home_delete_tile_confirm').replace('%s', i.label))) return;
				removeKey(key); delete L.custom[key]; changed(); render();
			}, 'nuHeToolDel') : null
		]);
	}
	function tileIcon(i, small) {
		var s = el('span', {'class': 'nu-tile-icon nuHomeIcon' + (small ? ' nuHomeIconSm' : '')}, [icon(i.icon)]);
		if (i.color) { s.style.color = i.color; s.style.backgroundColor = i.color + '1f'; }
		return s;
	}
	/** One tile as the page shows it in the chosen size; the dragged element is the column (grid) or the row (list). */
	function tileEl(key, hidden) {
		var i = info(key);
		if (!i) return null;
		var size = L.size, tools = tileTools(key, i, hidden);
		var fav = L.fav.indexOf(key) >= 0;
		var attrs = {'data-key': key, 'data-drag': 'tile', 'aria-label': i.label + (fav ? ' ★' : '') + ' – ' + t('home_drag'), tabindex: '0'};
		if (fav) attrs['data-fav'] = '1';
		// favourite: a star in the corner that stays visible (the tools with ★ show on hover)
		var mark = fav && !hidden ? el('span', {'class': 'nuHeFavMark', title: t('home_fav'), 'aria-hidden': 'true'}, [icon('bi-star-fill')]) : null;
		if (isList(size)) {
			return el('div', Object.assign({'class': 'list-group-item d-flex align-items-center gap-3 nuHeItem'}, attrs), [tileIcon(i, false),
				el('span', {'class': 'flex-grow-1 min-w-0'}, [el('span', {'class': 'd-block text-body'}, [i.label, fav ? el('i', {'class': 'bi bi-star-fill ms-2 nuHeFavInline', 'aria-hidden': 'true'}) : null]), i.hint ? el('span', {'class': 'd-block small text-body-secondary', text: i.hint}) : null]), tools]);
		}
		var body = size === 'compact'
			? el('div', {'class': 'card-body d-flex align-items-center gap-2 py-2'}, [tileIcon(i, true), el('span', {'class': 'flex-grow-1 text-body text-truncate', text: i.label})])
			: el('div', {'class': 'card-body d-flex align-items-center gap-3'}, [tileIcon(i, false), el('div', {'class': 'flex-grow-1'}, [
				el('div', {'class': 'fs-5 fw-normal lh-sm text-body', text: i.label}), i.hint ? el('div', {'class': 'small text-body-secondary mt-1', text: i.hint}) : null])]);
		return el('div', Object.assign({'class': (size === 'compact' ? 'col-6 col-md-4 col-xl-3' : 'col-sm-6 col-lg-4') + ' nuHeItem'}, attrs), [
			el('div', {'class': 'card nu-tile h-100 shadow-sm' + (i.own ? ' nuHeOwn' : '')}, [body, mark, tools])]);
	}
	/** Tiles shown as list rows: List, Wide list, List in 2 columns (0.8.363). */
	function isList(size) { return size === 'list' || size === 'listwide' || size === 'list2'; }
	/** Container of tiles (group or hidden); data-list = group id or "__hidden". */
	function tileList(listId, keys, hidden) {
		var list = isList(L.size);
		var box = el('div', {'class': (list ? 'list-group shadow-sm nuHomeList' + (L.size === 'listwide' ? ' nuHomeListWide' : L.size === 'list2' ? ' nuHomeListWide nuHomeList2' : '') : 'row g-3') + ' nuHeDrop', 'data-list': listId});
		keys.forEach(function (k) { var c = tileEl(k, hidden); if (c) box.appendChild(c); });
		box.appendChild(el('div', {'class': (list ? 'list-group-item ' : 'col-12 ') + 'nuHeEmpty small text-body-secondary', text: hidden ? t('home_hidden_hint') : t('home_drop_here')}));
		return box;
	}
	function groupEl(g) {
		var title = el('input', {'class': 'form-control nuHeGroupTitle', value: g.title, maxlength: '60', placeholder: t('home_group_untitled'), 'aria-label': t('home_group_title')});
		title.addEventListener('input', function () { g.title = title.value; changed(); });
		var tabSel = null;
		if (tabsOn()) {
			tabSel = el('select', {'class': 'form-select form-select-sm w-auto nuHeGroupTab', 'aria-label': t('home_group_tab'), title: t('home_group_tab')},
				L.tabs.map(function (tb) { return el('option', {value: tb.id, text: tb.title || t('home_group_untitled')}); }));
			tabSel.value = g.tab;
			tabSel.addEventListener('change', function () { g.tab = tabSel.value; changed(); render(); });
		}
		var mine = groupsOfTab(), gi = mine.indexOf(g);
		return el('section', {'class': 'nuHomeGroup nuHeGroup mb-4', 'data-id': g.id, 'data-drag': 'group'}, [
			el('div', {'class': 'nuHeGroupHead'}, [
				el('button', {type: 'button', 'class': 'btn nuHeGrip', title: t('home_drag'), 'aria-label': t('home_drag')}, [icon('bi-grip-vertical')]),
				title, tabSel,
				toolBtn('bi-arrow-up', t('home_move_up'), function () { swapGroup(g, -1); }, gi <= 0 ? 'disabled' : ''),
				toolBtn('bi-arrow-down', t('home_move_down'), function () { swapGroup(g, 1); }, gi < 0 || gi >= mine.length - 1 ? 'disabled' : ''),
				toolBtn('bi-trash', t('home_group_delete'), function () {
					if (g.tiles.length && !window.confirm(t('home_group_delete_confirm'))) return;
					L.hidden = L.hidden.concat(g.tiles); L.groups.splice(L.groups.indexOf(g), 1); changed(); render();
				}, 'nuHeToolDel')
			]),
			tileList(g.id, g.tiles, false)
		]);
	}
	function swapGroup(g, dir) {
		var mine = groupsOfTab(), i = mine.indexOf(g), other = mine[i + dir];
		if (!other) return;
		var a = L.groups.indexOf(g), b = L.groups.indexOf(other);
		L.groups[a] = other; L.groups[b] = g;
		changed(); render();
	}
	function groupsBox(tab) {
		var box = el('div', {'class': 'nuHeGroups', 'data-tab': tab});
		(tabsOn() ? L.groups.filter(function (g) { return g.tab === tab; }) : L.groups).forEach(function (g) { box.appendChild(groupEl(g)); });
		return box;
	}
	function addGroup() {
		var g = {id: newId('g_'), title: t('home_group_new'), tab: tabsOn() ? activeTab : '', tiles: []};
		L.groups.push(g);
		changed(); render();
		var inp = $('.nuHeGroup[data-id="' + g.id + '"] .nuHeGroupTitle', edit);
		if (inp) { inp.focus(); inp.select(); inp.scrollIntoView({block: 'center'}); }
	}
	function addTab() {
		var id = newId('t_');
		if (!L.tabs.length) L.groups.forEach(function (g) { g.tab = id; });   // the first tab takes all groups
		L.tabs.push({id: id, title: t('home_tab_new')});
		activeTab = id;
		changed(); render();
		var inp = $('.nuHeTab.active .nuHeTabInput', edit);
		if (inp) { inp.focus(); inp.select(); }
	}
	function tabBar() {
		var ul = el('ul', {'class': 'nav nav-tabs nuHomeTabs nuHeTabs', role: 'tablist', 'data-list': '__tabs'});
		L.tabs.forEach(function (tb, ti) {
			var on = tb.id === activeTab;
			var inner;
			if (on) {
				var inp = el('input', {'class': 'nuHeTabInput', value: tb.title, maxlength: '40', placeholder: t('home_group_untitled'), 'aria-label': t('home_tab_title'), size: String(Math.max(4, (tb.title || '').length + 1))});
				inp.addEventListener('input', function () { tb.title = inp.value; inp.size = Math.max(4, inp.value.length + 1); changed(); });
				inp.addEventListener('change', function () { $$('.nuHeGroupTab option[value="' + tb.id + '"]', edit).forEach(function (o) { o.textContent = tb.title || t('home_group_untitled'); }); });
				inner = [el('span', {'class': 'nuHeGrip nuHeTabGrip', title: t('home_drag')}, [icon('bi-grip-vertical')]), inp,
					toolBtn('bi-chevron-left', t('home_move_left'), function () { if (ti > 0) { L.tabs.splice(ti - 1, 0, L.tabs.splice(ti, 1)[0]); changed(); render(); } }, ti === 0 ? 'disabled' : ''),
					toolBtn('bi-chevron-right', t('home_move_right'), function () { if (ti < L.tabs.length - 1) { L.tabs.splice(ti + 1, 0, L.tabs.splice(ti, 1)[0]); changed(); render(); } }, ti === L.tabs.length - 1 ? 'disabled' : ''),
					toolBtn('bi-x-lg', t('home_tab_delete'), function () {
						var mine = L.groups.filter(function (g) { return g.tab === tb.id; });
						if (mine.length && L.tabs.length > 1 && !window.confirm(t('home_tab_delete_confirm'))) return;
						L.tabs.splice(ti, 1);
						mine.forEach(function (g) { g.tab = L.tabs.length ? L.tabs[0].id : ''; });
						activeTab = L.tabs.length ? L.tabs[Math.max(0, ti - 1)].id : '';
						changed(); render();
					}, 'nuHeToolDel')];
			} else {
				inner = [el('span', {'class': 'nuHeGrip nuHeTabGrip', 'aria-hidden': 'true'}, [icon('bi-grip-vertical')]), el('span', {text: tb.title || t('home_group_untitled')})];
			}
			var link = el('div', {'class': 'nav-link d-flex align-items-center gap-1' + (on ? ' active' : ''), role: 'tab', tabindex: on ? '-1' : '0', 'aria-selected': on ? 'true' : 'false'}, inner);
			var li = el('li', {'class': 'nav-item nuHeTab' + (on ? ' active' : ''), 'data-id': tb.id, 'data-drag': 'tab'}, [link]);
			if (!on) {
				var go = function () { activeTab = tb.id; render(); };
				link.addEventListener('click', go);
				link.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
			}
			ul.appendChild(li);
		});
		ul.appendChild(el('li', {'class': 'nav-item nuHeTabAdd'}, [el('button', {type: 'button', 'class': 'nav-link', id: 'nuHomeAddTab', title: t('home_add_tab'), 'aria-label': t('home_add_tab'), onclick: addTab}, [icon('bi-plus-lg')])]));
		return ul;
	}

	function render() {
		var y = window.scrollY;
		edit.textContent = '';
		var displaySel = el('select', {'class': 'form-select form-select-sm w-auto', id: 'nuHomeDisplay', 'aria-label': t('home_display')}, [
			el('option', {value: 'groups', text: t('home_display_groups')}), el('option', {value: 'tabs', text: t('home_display_tabs')})]);
		displaySel.value = L.display;
		displaySel.addEventListener('change', function () { L.display = displaySel.value; if (!activeTab && L.tabs[0]) activeTab = L.tabs[0].id; changed(); render(); });
		var sizeSel = el('select', {'class': 'form-select form-select-sm w-auto', id: 'nuHomeSize', 'aria-label': t('home_size')}, [
			el('option', {value: 'large', text: t('home_size_large')}), el('option', {value: 'compact', text: t('home_size_compact')}), el('option', {value: 'list', text: t('home_size_list')}), el('option', {value: 'listwide', text: t('home_size_listwide')}), el('option', {value: 'list2', text: t('home_size_list2')})]);
		sizeSel.value = L.size;
		sizeSel.addEventListener('change', function () { L.size = sizeSel.value; changed(); render(); });
		if (editFav && !L.fav.length) editFav = false;
		var favSel = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary nuHomeFavBtn' + (editFav ? ' active' : ''), id: 'nuHomeEditFav',
			'aria-pressed': editFav ? 'true' : 'false', disabled: L.fav.length ? null : 'disabled', title: L.fav.length ? t('home_fav_only') : t('home_fav_none_edit'),
			'aria-label': t('home_fav_only'), onclick: function () { editFav = !editFav; render(); }}, [icon(editFav ? 'bi-star-fill me-sm-1' : 'bi-star me-sm-1'), el('span', {'class': 'd-none d-sm-inline', text: t('home_fav')})]);

		edit.classList.toggle('nuHeFavOnly', editFav);
		edit.appendChild(el('div', {'class': 'nuHeBar'}, [
			el('div', {'class': 'nuHeBarRow'}, [
				el('span', {'class': 'fw-semibold me-1 text-nowrap'}, [icon('bi-pencil-square me-2 text-primary'), el('span', {'class': 'd-none d-xxl-inline', text: t('home_editing')}),
					el('span', {'class': 'nuInfoTip ms-1 fw-normal', tabindex: '0', role: 'img', 'data-bs-toggle': 'tooltip', 'data-bs-custom-class': 'nuTipWide', title: t('home_edit_hint'), 'aria-label': t('home_edit_hint')}, [icon('bi-info-circle')])]),
				el('label', {'class': 'visually-hidden', 'for': 'nuHomeDisplay', text: t('home_display')}), displaySel,
				el('label', {'class': 'visually-hidden', 'for': 'nuHomeSize', text: t('home_size')}), sizeSel, favSel,
				el('span', {'class': 'flex-grow-1'}),
				el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuHomeAddGroup', title: t('home_add_group'), 'aria-label': t('home_add_group'), onclick: addGroup}, [icon('bi-collection me-sm-1'), el('span', {'class': 'd-none d-sm-inline', text: t('home_add_group')})]),
				el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuHomeAddTile', title: t('home_add_tile'), 'aria-label': t('home_add_tile'), onclick: function () { tileDialog(null); }}, [icon('bi-plus-square me-sm-1'), el('span', {'class': 'd-none d-sm-inline', text: t('home_add_tile')})]),
				startFromMenu(),                                                 // Start from ▾ – profiles (0.8.385, replaces Reset)
				el('span', {'class': 'd-inline-flex gap-2 ms-auto'}, [                   // Cancel and Done stay together
					el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuHomeCancel', onclick: cancel}, [t('cancel')]),
					el('button', {type: 'button', 'class': 'btn btn-sm btn-primary', id: 'nuHomeSave', 'data-nu-save': true, onclick: save}, [icon('bi-check-lg me-1'), t('home_done')])])
			]),
			pfNote ? pfNoteEl() : null
		]));

		if (L.display === 'tabs') {
			edit.appendChild(tabBar());
			if (!L.tabs.length) edit.appendChild(el('div', {'class': 'small text-body-secondary pt-2', text: t('home_tabs_hint')}));
			var panes = el('div', {'class': 'pt-3'});
			if (tabsOn()) L.tabs.forEach(function (tb) { var p = groupsBox(tb.id); p.hidden = tb.id !== activeTab; panes.appendChild(p); });
			else panes.appendChild(groupsBox(''));
			edit.appendChild(panes);
		} else {
			edit.appendChild(groupsBox(''));
		}

		edit.appendChild(el('section', {'class': 'nuHeHidden mb-3'}, [
			el('h2', {'class': 'nuHomeGroupTitle'}, [icon('bi-eye-slash me-2'), t('home_hidden')]),
			tileList('__hidden', L.hidden, true)
		]));
		$$('[data-bs-toggle="tooltip"]', edit).forEach(function (e) { nuBs.tooltip(e); });
		window.scrollTo(0, y);
	}

	// ---------------------------------------------------------------- moving with buttons
	function show(key) {
		removeKey(key);
		targetGroup(true).tiles.push(key);
		changed(); render();
	}
	function move(key, dir) {
		L.groups.forEach(function (g) {
			var i = g.tiles.indexOf(key), j = i + dir;
			if (i < 0 || j < 0 || j >= g.tiles.length) return;
			g.tiles.splice(i, 1); g.tiles.splice(j, 0, key);
		});
		changed(); render();
		var t2 = $('.nuHeItem[data-key="' + key + '"]', edit);
		if (t2) t2.focus();
	}

	// ---------------------------------------------------------------- drag and drop (mouse, pen and touch – pointer events)
	// Tiles move between groups and Hidden, groups within / between tabs (drop on a tab title), tabs along the tab bar.
	// Touch: hold for a moment, then drag (a quick swipe still scrolls). The element itself moves through the page while
	// dragging (a copy follows the pointer), the layout is read back from the page when it is dropped.
	var P = null;                        // pending press: {x, y, item, type, id, timer, touch}
	var G = null;                        // active drag: {item, type, ghost, dx, dy, tabTimer, overTab, lastX, lastY, scroll}
	var HOLD = 280, SLOP_TOUCH = 8, SLOP_MOUSE = 5;

	function dragItem(target) {
		if (!target.closest) return null;
		var tab = target.closest('.nuHeTab[data-drag]');
		if (tab) return target.closest('input') && !target.closest('.nuHeGrip') ? null : tab;
		var grip = target.closest('.nuHeGrip');
		if (grip) return grip.closest('[data-drag="group"]');
		if (target.closest('button, input, select, a, .dropdown-menu')) return null;
		return target.closest('[data-drag="tile"]');
	}
	edit.addEventListener('pointerdown', function (e) {
		if (e.button !== 0 || G) return;
		var item = dragItem(e.target);
		if (!item) return;
		var touch = e.pointerType === 'touch';
		P = {x: e.clientX, y: e.clientY, item: item, type: item.getAttribute('data-drag'), id: e.pointerId, touch: touch, timer: null};
		if (touch) P.timer = setTimeout(function () { if (P) { begin(P.x, P.y); if (navigator.vibrate) navigator.vibrate(15); } }, HOLD);
		else if (P.type !== 'tab') e.preventDefault();          // no text selection while dragging with the mouse
	});
	document.addEventListener('pointermove', function (e) {
		if (P && e.pointerId === P.id && !G) {
			var d = Math.abs(e.clientX - P.x) + Math.abs(e.clientY - P.y);
			if (P.touch) { if (d > SLOP_TOUCH) cancelPress(); return; }   // moved before the hold: the page scrolls
			if (d > SLOP_MOUSE) begin(e.clientX, e.clientY);
			else return;
		}
		if (G && e.pointerId === G.id) { e.preventDefault(); moveTo(e.clientX, e.clientY); }
	}, {passive: false});
	document.addEventListener('touchmove', function (e) { if (G) e.preventDefault(); }, {passive: false});   // no scrolling while a tile is held
	document.addEventListener('pointerup', function (e) { if (G && e.pointerId === G.id) finish(true); else cancelPress(); });
	document.addEventListener('pointercancel', function () { if (G) finish(true); else cancelPress(); });
	document.addEventListener('keydown', function (e) { if (G && e.key === 'Escape') { e.preventDefault(); finish(false); } });
	edit.addEventListener('contextmenu', function (e) { if (G || (P && P.touch)) e.preventDefault(); });   // long press on touch screens
	function cancelPress() { if (P && P.timer) clearTimeout(P.timer); P = null; }

	function begin(x, y) {
		var item = P.item, type = P.type, id = P.id;
		if (P.timer) clearTimeout(P.timer);
		P = null;
		var r = item.getBoundingClientRect(), ghost;
		if (type === 'group') {                                   // a small label instead of the whole group
			var gt = $('.nuHeGroupTitle', item);
			ghost = el('div', {'class': 'nuHeGhost nuHeGhostLabel'}, [icon('bi-collection me-2'), (gt && gt.value) || t('home_group_untitled')]);
			r = {left: x - 20, top: y - 18, width: 0, height: 0};
		} else {
			ghost = item.cloneNode(true);
			ghost.classList.add('nuHeGhost');
			ghost.removeAttribute('data-drag');
			ghost.style.width = r.width + 'px';
			ghost.style.height = r.height + 'px';
		}
		document.body.appendChild(ghost);
		G = {item: item, type: type, id: id, ghost: ghost, dx: x - r.left, dy: y - r.top, tabTimer: null, overTab: null, lastX: x, lastY: y, scroll: 0};
		item.classList.add('nuHePlaceholder');
		document.body.classList.add('nuHeDragging', 'nuHeDrag-' + type);
		moveTo(x, y);
		autoScroll();
	}
	function autoScroll() {
		if (!G) return;
		var edge = 70, v = 0;
		if (G.lastY < edge) v = -Math.ceil((edge - G.lastY) / 5);
		else if (G.lastY > window.innerHeight - edge) v = Math.ceil((G.lastY - (window.innerHeight - edge)) / 5);
		if (v) { window.scrollBy(0, v); moveTo(G.lastX, G.lastY); }
		G.scroll = requestAnimationFrame(autoScroll);
	}
	/** Where the dragged element goes: before the first sibling that lies after the pointer (reading order), else at the end. */
	function insertIn(box, items, x, y, axis) {
		var before = null;
		for (var i = 0; i < items.length; i++) {
			var it = items[i];
			if (it === G.item) continue;
			var r = it.getBoundingClientRect();
			var after = axis === 'x' ? x < r.left + r.width / 2
				: axis === 'y' ? y < r.top + r.height / 2
				: (y < r.top || (y <= r.bottom && x < r.left + r.width / 2));
			if (after) { before = it; break; }
		}
		if (before) { if (G.item.nextElementSibling !== before) box.insertBefore(G.item, before); }
		else {
			var tail = $(':scope > .nuHeEmpty', box);              // the hint stays last
			if (tail) { if (G.item.nextElementSibling !== tail) box.insertBefore(G.item, tail); }
			else if (box.lastElementChild !== G.item) box.appendChild(G.item);
		}
	}
	function moveTo(x, y) {
		G.lastX = x; G.lastY = y;
		G.ghost.style.left = (x - G.dx) + 'px';
		G.ghost.style.top = (y - G.dy) + 'px';
		var under = document.elementFromPoint(x, y);
		if (!under) return;
		$$('.nuHeOver', edit).forEach(function (b) { b.classList.remove('nuHeOver'); });
		// over a tab title: tiles and groups open that tab after a moment (a group dropped there moves to it)
		var tabLi = G.type !== 'tab' && under.closest ? under.closest('.nuHeTab:not(.active)') : null;
		if (tabLi !== G.overTab) {
			clearTimeout(G.tabTimer);
			G.overTab = tabLi;
			if (tabLi) G.tabTimer = setTimeout(function () { if (G && G.overTab === tabLi) openTabWhileDragging(tabLi.getAttribute('data-id')); }, 450);
		}
		if (tabLi) { tabLi.classList.add('nuHeOver'); return; }
		var box;
		if (G.type === 'tile') {
			box = under.closest ? under.closest('.nuHeDrop') : null;
			if (!box) return;
			box.classList.add('nuHeOver');
			insertIn(box, $$('.nuHeItem', box), x, y, L.size === 'list' || L.size === 'listwide' ? 'y' : 'grid');   // list2: row by row like the grid
		} else if (G.type === 'group') {
			box = under.closest ? under.closest('.nuHeGroups') : null;
			if (!box || box.hidden) return;
			insertIn(box, $$(':scope > .nuHeGroup', box), x, y, 'y');
		} else {
			box = under.closest ? under.closest('.nuHeTabs') : null;
			if (!box) return;
			insertIn(box, $$(':scope > .nuHeTab', box), x, y, 'x');
			var add = $('.nuHeTabAdd', box);
			if (add && box.lastElementChild !== add) box.appendChild(add);
		}
	}
	function openTabWhileDragging(id) {
		$$('.nuHeTab', edit).forEach(function (li) {
			var on = li.getAttribute('data-id') === id;
			li.classList.toggle('active', on);
			var a = $('.nav-link', li); if (a) a.classList.toggle('active', on);
		});
		$$('.nuHeGroups[data-tab]', edit).forEach(function (p) { if (p.getAttribute('data-tab') !== '') p.hidden = p.getAttribute('data-tab') !== id; });
		activeTab = id;
		if (G && G.type === 'group') {                            // the group goes to the end of the opened tab
			var pane = $('.nuHeGroups[data-tab="' + id + '"]', edit);
			if (pane) pane.appendChild(G.item);
		}
	}
	function finish(keep) {
		var g = G;
		G = null;
		cancelAnimationFrame(g.scroll);
		clearTimeout(g.tabTimer);
		g.ghost.remove();
		g.item.classList.remove('nuHePlaceholder');
		document.body.classList.remove('nuHeDragging', 'nuHeDrag-tile', 'nuHeDrag-group', 'nuHeDrag-tab');
		if (keep) readBack(g);
		render();
		var focus = g.type === 'tile' ? $('.nuHeItem[data-key="' + g.item.getAttribute('data-key') + '"]', edit) : null;
		if (focus) focus.focus({preventScroll: true});
	}
	/** The page after a drop → the layout (tiles per group and hidden, group order and tab, tab order). */
	function readBack(g) {
		if (g.type === 'tile') {
			var key = g.item.getAttribute('data-key'), box = g.item.parentNode;
			if (!box || !box.getAttribute) return;
			var keys = $$('.nuHeItem', box).map(function (i) { return i.getAttribute('data-key'); });
			removeKey(key);
			var listId = box.getAttribute('data-list');
			var target = listId === '__hidden' ? L.hidden : (L.groups.filter(function (x) { return x.id === listId; })[0] || {}).tiles;
			if (!target) return;
			target.length = 0;
			keys.forEach(function (k) { target.push(k); });
		} else if (g.type === 'group') {
			var order = [];
			$$('.nuHeGroups', edit).forEach(function (p) {
				var tab = p.getAttribute('data-tab');
				$$(':scope > .nuHeGroup', p).forEach(function (s) {
					var grp = L.groups.filter(function (x) { return x.id === s.getAttribute('data-id'); })[0];
					if (!grp) return;
					if (tabsOn()) grp.tab = tab;
					order.push(grp);
				});
			});
			L.groups.forEach(function (x) { if (order.indexOf(x) < 0) order.push(x); });
			L.groups = order;
		} else {
			var ids = $$('.nuHeTab', edit).map(function (li) { return li.getAttribute('data-id'); });
			L.tabs.sort(function (a, b) { return ids.indexOf(a.id) - ids.indexOf(b.id); });
		}
		changed();
	}

	// ---------------------------------------------------------------- Done / Cancel / Reset
	/** Tile size / display chosen in Customise → the view (0.8.420: size and display are derived from it on the server). */
	var LEGACY = {large: {style: 'large'}, compact: {style: 'compact'}, list: {style: 'list', listCols: '1', listWidth: 'readable'},
		listwide: {style: 'list', listCols: '1', listWidth: 'full'}, list2: {style: 'list', listCols: '2'}};
	function save() {
		var b = $('#nuHomeSave');
		b.disabled = true;
		L.view = Object.assign({}, D.layout.view || {}, L.view || {});
		if (L.size !== D.layout.size && LEGACY[L.size]) Object.assign(L.view, LEGACY[L.size]);
		if (L.display !== D.layout.display) L.view.nav = L.display === 'tabs' ? 'tabs' : 'groups';
		post({action: 'layout', layout: L}).then(function (j) {
			if (j.ok) { dirty = false; window.location.href = D.url; return; }
			b.disabled = false;
			if (j.demo) { window.alert(j.message); return; }              // demo mode: "Not available in the demo."
			var msgs = Object.keys(j.errors || {}).map(function (k) { return ((L.custom[k] || {}).label || k) + ': ' + j.errors[k]; });
			window.alert(t('home_saved_err') + '\n' + msgs.join('\n'));
		}).catch(function (e) { b.disabled = false; window.alert(t('home_saved_err') + ' ' + e.message); });
	}
	function cancel() {
		if (dirty && !window.confirm(t('home_discard_confirm'))) return;
		close();
	}
	// ---------------------------------------------------------------- profiles: Start from (0.8.385, in place of Reset)
	/** A profile of D.presets as an edit copy – own tiles of "from" along with keep (same rules as NuHome::presetLayout()). */
	function presetLayout(id, from, keep) {
		var l = clone(D.presets[id].layout);
		if (Array.isArray(l.custom)) l.custom = {};
		l.fav = Array.isArray(l.fav) ? l.fav : [];
		l.tabs = l.tabs || [];
		var custom = keep && from && from.custom && !Array.isArray(from.custom) ? from.custom : {};
		var own = Object.keys(custom);
		if (own.length) {
			l.custom = clone(custom);
			var box = l.groups.filter(function (g) { return g.id === 'g_own'; })[0];
			if (!box) { box = {id: 'g_own', title: t('home_pf_g_own'), tab: l.tabs[0] ? l.tabs[0].id : '', tiles: []}; l.groups.unshift(box); }
			own.forEach(function (k) { if ((from.hidden || []).indexOf(k) >= 0) l.hidden.push(k); else box.tiles.push(k); });
			(from.fav || []).forEach(function (k) { if (own.indexOf(k) >= 0 && l.fav.indexOf(k) < 0) l.fav.push(k); });
		}
		return l;
	}
	/** Own tiles to take along: those of the edit copy, else those of the saved layout (a profile without them was loaded before). */
	function ownSource() { return Object.keys(L.custom || {}).length ? L : baseL; }
	function ownNames(l) {
		var c = l && l.custom && !Array.isArray(l.custom) ? l.custom : {}, n = Object.keys(c).map(function (k) { return c[k].label; });
		return n.length > 3 ? n.slice(0, 3).join(', ') + ', …' : n.join(', ');
	}
	/** Numbers of the edit copy for the strip (tiles that can be drawn). */
	function layoutCounts(l) {
		var groups = l.groups.filter(function (g) { return g.tiles.some(function (k) { return !!info(k); }); });
		var tiles = 0;
		groups.forEach(function (g) { g.tiles.forEach(function (k) { if (info(k)) tiles++; }); });
		var tabs = l.display !== 'tabs' ? 0 : (l.tabs.length ? l.tabs.filter(function (tb) { return groups.some(function (g) { return g.tab === tb.id; }); }).length : groups.length);
		return {tabs: tabs, groups: groups.length, tiles: tiles, hidden: l.hidden.length};
	}
	function countsText(c) { return c.tabs > 1 ? tf('home_pf_counts_tabs', c.tabs, c.groups, c.tiles, c.hidden) : tf('home_pf_counts', c.groups, c.tiles, c.hidden); }
	/** Customise → Start from ▾: the profiles; a click replaces the edit copy – Undo in the strip, Done saves. */
	function startFromMenu() {
		var names = ownNames(ownSource());
		var toggle = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary dropdown-toggle', id: 'nuHomeStartFrom', 'data-bs-toggle': 'dropdown',
			'data-bs-auto-close': 'outside', 'aria-expanded': 'false', title: t('home_start_from_tip')},
			[icon('bi-layers me-sm-1'), el('span', {'class': 'd-none d-sm-inline', text: t('home_start_from')})]);
		var items = [el('li', {}, [el('h6', {'class': 'dropdown-header', text: t('home_pf_menu_head')})])];
		Object.keys(D.presets || {}).forEach(function (id) {
			var p = D.presets[id];
			items.push(el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item nuHeStartItem', 'data-preset': id, onclick: function () {
				var dd = nuBs.dropdown(toggle);
				if (dd) dd.hide();
				applyPreset(id);
			}}, [
				el('span', {'class': 'nuHeStartIcon'}, [icon(p.icon)]),
				el('span', {'class': 'min-w-0 flex-grow-1'}, [el('span', {'class': 'd-block', text: p.label}), el('span', {'class': 'd-block small text-body-secondary', text: p.hint})]),
				el('span', {'class': 'small text-body-secondary text-nowrap ms-2', text: tf('home_pf_n_tiles', p.counts.tiles)})])]));
		});
		if (names) {
			var cb = el('input', {'class': 'form-check-input', type: 'checkbox', role: 'switch', id: 'nuHomeKeepOwn', checked: keepOwn ? 'checked' : null});
			cb.addEventListener('change', function () { keepOwn = cb.checked; });
			items.push(el('li', {}, [el('hr', {'class': 'dropdown-divider'})]));
			items.push(el('li', {'class': 'px-3 py-1'}, [el('div', {'class': 'form-check form-switch mb-0'}, [cb,
				el('label', {'class': 'form-check-label small', 'for': 'nuHomeKeepOwn', text: tf('home_pf_keep_own', names)})])]));
		}
		return el('div', {'class': 'dropdown'}, [toggle, el('ul', {'class': 'dropdown-menu dropdown-menu-end shadow nuHeStartMenu'}, items)]);
	}
	function applyPreset(id) {
		var p = D.presets[id];
		if (!p) return;
		prevEdit = {L: clone(L), dirty: dirty, activeTab: activeTab};
		L = presetLayout(id, ownSource(), keepOwn);
		activeTab = L.tabs[0] ? L.tabs[0].id : '';
		editFav = false;                                         // the favourites filter could hide everything
		changed();
		pfNote = {icon: p.icon, text: tf('home_pf_loaded', p.label, countsText(layoutCounts(L)))
			+ (Object.keys(L.custom).length ? ' ' + t('home_pf_loaded_own') : '') + ' ' + t('home_pf_not_saved')};
		render();
		window.scrollTo(0, 0);
	}
	function undoPreset() {
		if (!prevEdit) return;
		L = prevEdit.L; dirty = prevEdit.dirty; activeTab = prevEdit.activeTab;
		prevEdit = null; pfNote = null;
		render();
	}
	function pfNoteEl() {
		return el('div', {'class': 'alert alert-info d-flex align-items-start gap-2 py-2 px-3 small mt-2 mb-0 nuHeNote', role: 'status'}, [
			icon(pfNote.icon + ' mt-1'), el('div', {'class': 'flex-grow-1', text: pfNote.text}),
			prevEdit ? el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary py-0 text-nowrap', id: 'nuHomeUndo', onclick: undoPreset}, [icon('bi-arrow-90deg-left me-1'), t('home_pf_undo')]) : null,
			el('button', {type: 'button', 'class': 'btn-close btn-sm ms-1', 'aria-label': t('close'), onclick: function () { pfNote = null; render(); }})]);
	}

	// ---------------------------------------------------------------- own tile dialog
	var modal = null;
	function tileDialog(key) {
		var c = key ? clone(L.custom[key]) : {label: '', hint: '', icon: 'bi-star', color: '', kind: 'builtin', target: '', record: '', run: 'window', newtab: false};
		if (modal) { modal.dispose(); $('#nuHomeTileModal').remove(); }
		var field = function (id, label, control, hint) {
			return el('div', {'class': 'mb-2 nuHomeField', 'data-for': id}, [el('label', {'class': 'form-label small mb-1', 'for': id, text: label}), control,
				hint ? el('div', {'class': 'form-text', text: hint}) : null]);
		};
		var inp = function (id, v, attrs) { return el('input', Object.assign({'class': 'form-control form-control-sm', id: id, value: v || ''}, attrs || {})); };
		var sel = function (id, opts, v) {
			var s = el('select', {'class': 'form-select form-select-sm', id: id}, opts.map(function (o) { return el('option', {value: o[0], text: o[1]}); }));
			s.value = v || (opts[0] ? opts[0][0] : ''); return s;
		};
		var label = inp('htLabel', c.label, {maxlength: '60', required: true});
		var hint = inp('htHint', c.hint, {maxlength: '120'});
		var dl = el('datalist', {id: 'htIcons'}, D.icons.map(function (i) { return el('option', {value: i}); }));
		var ic = inp('htIcon', c.icon, {list: 'htIcons', spellcheck: 'false', autocomplete: 'off'});
		var preview = el('span', {'class': 'input-group-text'}, [icon(c.icon)]);
		ic.addEventListener('input', function () { preview.firstChild.className = 'bi ' + ic.value.trim(); });
		var color = inp('htColor', c.color || '#2563eb', {type: 'color'});
		color.className = 'form-control form-control-color form-control-sm';
		var noColor = el('input', {'class': 'form-check-input', type: 'checkbox', id: 'htNoColor'});
		noColor.checked = !c.color;
		var kinds = [['form', t('home_kind_form')], ['page', t('home_kind_page')], ['procedure', t('home_kind_procedure')], ['url', t('home_kind_url')]];
		if (!key) kinds.unshift(['builtin', t('home_kind_builtin')]);   // a page / action of nuBuilder Next that is not on the page yet (0.8.321)
		var kind = sel('htKind', kinds, c.kind);
		var placed = {};
		L.groups.forEach(function (g) { g.tiles.forEach(function (k) { placed[k] = true; }); });
		var builtin = el('select', {'class': 'form-select form-select-sm', id: 'htBuiltin'});
		var byGroup = {};
		Object.keys(D.catalog).forEach(function (k) {
			if (placed[k]) return;
			var e = D.catalog[k], g = e.sub ? e.hint : t('home_builtin_tiles');
			(byGroup[g] = byGroup[g] || []).push(el('option', {value: k, text: e.label}));
		});
		Object.keys(byGroup).forEach(function (g) { builtin.appendChild(el('optgroup', {label: g}, byGroup[g])); });
		var form = sel('htForm', D.forms.map(function (f) { return [f.id, f.label]; }), c.kind === 'form' ? c.target : '');
		var record = inp('htRecord', c.record, {maxlength: '25', placeholder: '-1'});
		var page = sel('htPage', D.pages.map(function (p) { return [p, p]; }), c.kind === 'page' ? c.target : '');
		var proc = sel('htProc', D.procedures.map(function (p) { return [p.id, p.label]; }), c.kind === 'procedure' ? c.target : '');
		var run = sel('htRun', [['window', t('home_run_window')], ['hidden', t('home_run_hidden')]], c.run);
		var url = inp('htUrl', c.kind === 'url' ? c.target : '', {maxlength: '500', placeholder: 'https://…'});
		var newtab = el('input', {'class': 'form-check-input', type: 'checkbox', id: 'htNewtab'});
		newtab.checked = !!c.newtab;
		var err = el('div', {'class': 'alert alert-danger small py-2 mb-0', hidden: true});

		var own = el('div', {}, [
			field('htLabel', t('home_tile_label') + ' *', label), field('htHint', t('home_tile_hint'), hint),
			el('div', {'class': 'row g-2'}, [
				el('div', {'class': 'col-8'}, [field('htIcon', t('home_tile_icon'), el('div', {'class': 'input-group input-group-sm'}, [preview, ic, dl]))]),
				el('div', {'class': 'col-4'}, [field('htColor', t('home_tile_color'), el('div', {'class': 'd-flex gap-2 align-items-center'}, [color,
					el('div', {'class': 'form-check mb-0'}, [noColor, el('label', {'class': 'form-check-label small', 'for': 'htNoColor', text: t('home_tile_no_color')})])]))])
			])]);
		var body = el('div', {'class': 'modal-body'}, [
			field('htKind', t('home_tile_kind'), kind),
			field('htBuiltin', t('home_tile_builtin'), builtin.options.length ? builtin : el('div', {'class': 'small text-body-secondary', text: t('home_builtin_none')})),
			own,
			field('htForm', t('home_tile_form'), form), field('htRecord', t('home_tile_record'), record, t('home_tile_record_hint')),
			field('htPage', t('home_tile_page'), page),
			field('htProc', t('home_tile_procedure'), proc), field('htRun', t('home_tile_run'), run),
			field('htUrl', t('home_tile_url'), url, t('home_tile_url_hint')),
			el('div', {'class': 'form-check mb-2 nuHomeField', 'data-for': 'htNewtab'}, [newtab, el('label', {'class': 'form-check-label small', 'for': 'htNewtab', text: t('home_tile_newtab')})]),
			err
		]);
		function sync() {
			var k = kind.value, showFor = {builtin: ['htBuiltin'], form: ['htForm', 'htRecord', 'htNewtab'], page: ['htPage', 'htNewtab'], procedure: ['htProc', 'htRun'], url: ['htUrl', 'htNewtab']}[k];
			$$('.nuHomeField', body).forEach(function (f) {
				var id = f.getAttribute('data-for');
				if (['htBuiltin', 'htForm', 'htRecord', 'htPage', 'htProc', 'htRun', 'htUrl', 'htNewtab'].indexOf(id) >= 0) f.hidden = showFor.indexOf(id) < 0;
			});
			own.hidden = k === 'builtin';
			color.disabled = noColor.checked;
		}
		kind.addEventListener('change', sync);
		noColor.addEventListener('change', sync);

		var ok = el('button', {type: 'button', 'class': 'btn btn-primary btn-sm'}, [t('ok')]);
		var m = el('div', {'class': 'modal fade', id: 'nuHomeTileModal', tabindex: '-1', 'aria-labelledby': 'nuHomeTileTitle', 'aria-hidden': 'true'}, [
			el('div', {'class': 'modal-dialog modal-dialog-scrollable'}, [el('div', {'class': 'modal-content'}, [
				el('div', {'class': 'modal-header py-2'}, [el('h2', {'class': 'modal-title h6', id: 'nuHomeTileTitle', text: key ? t('home_edit_tile') : t('home_tile_new')}),
					el('button', {type: 'button', 'class': 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': t('close')})]),
				body,
				el('div', {'class': 'modal-footer py-2'}, [el('button', {type: 'button', 'class': 'btn btn-outline-secondary btn-sm', 'data-bs-dismiss': 'modal', text: t('cancel')}), ok])
			])])
		]);
		document.body.appendChild(m);
		sync();
		modal = nuBs.modal(m);
		m.addEventListener('shown.bs.modal', function () { (kind.value === 'builtin' ? builtin : label).focus(); });
		ok.addEventListener('click', function () {
			var k = kind.value;
			if (k === 'builtin') {                                  // built-in page: just place its key (last group of the open tab)
				if (!builtin.value) return;
				removeKey(builtin.value);
				targetGroup(false).tiles.push(builtin.value);
				changed(); modal.hide(); render();
				return;
			}
			var tile = {label: label.value.trim(), hint: hint.value.trim(), icon: ic.value.trim() || 'bi-star', color: noColor.checked ? '' : color.value,
				kind: k, target: {form: form.value, page: page.value, procedure: proc.value, url: url.value.trim()}[k], record: k === 'form' ? record.value.trim() : '',
				run: run.value, newtab: k !== 'procedure' && newtab.checked};
			var problem = !tile.label ? t('home_tile_label') : (!tile.target ? ({form: t('home_tile_form'), page: t('home_tile_page'), procedure: t('home_tile_procedure'), url: t('home_tile_url')}[k]) : '');
			if (!problem && k === 'url' && !/^(https?:\/\/\S+|index\.php\?\S*)$/i.test(tile.target)) problem = t('home_tile_url_hint');
			if (!/^bi-[a-z0-9-]{1,40}$/.test(tile.icon)) problem = t('home_tile_icon');
			if (problem) { err.hidden = false; err.textContent = problem; return; }
			var id = key || newId('c_');
			L.custom[id] = tile;
			if (!key) targetGroup(false).tiles.push(id);           // a new tile: last group of the open tab
			changed();
			modal.hide();
			render();
		});
		modal.show();
	}
})();
