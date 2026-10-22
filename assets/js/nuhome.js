/* Globeadmin start page (pages/nuhome.php, core/nuhome.php): tile colours, procedure tiles, last tab, edit mode (Customize). */
(function () {
	'use strict';
	var D;
	try { D = JSON.parse(document.getElementById('nuHomeData').textContent); } catch (e) { return; }
	var T = D.t || {};
	function t(k) { return T[k] || k; }
	/** t() with arguments: %s in order, or %1$s %2$s … (as PHP's vsprintf). */
	function tf(k) {
		var a = Array.prototype.slice.call(arguments, 1), i = 0;
		return t(k).replace(/%(?:(\d+)\$)?s/g, function (m, n) { var v = n ? a[n - 1] : a[i++]; return v === undefined ? '' : String(v); });
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

	// ================================================================ view
	// compact tiles (0.8.355): the description as tooltip – mouse resting 800 ms or a long tap on phones; the long tap does not
	// open the tile and shows no copy / link menu. A tap elsewhere closes it.
	(function () {
		var tip = null, tipEl = null, lastTouch = 0;
		function hide() { if (tip) { tip.hide(); tip = null; tipEl = null; } }
		function show(a) {
			if (!nuBs.ready()) return;
			hide();
			tip = nuBs.tooltip(a, {trigger: 'manual', title: a.getAttribute('data-nu-hint'), customClass: 'nuTipWide', placement: 'bottom', container: 'body'});
			tip.show();
			tipEl = a;
		}
		$$('[data-nu-hint]').forEach(function (a) {
			var hoverT = 0, pressT = 0, long = false;
			// mouse (0.8.356): only when it rests 800 ms on the tile – every movement starts the wait again, passing over shows nothing
			var rest = function () { window.clearTimeout(hoverT); if (tipEl !== a) hoverT = window.setTimeout(function () { show(a); }, 800); };
			a.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') rest(); });
			a.addEventListener('pointermove', function (e) { if (e.pointerType === 'mouse') rest(); });
			a.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') { window.clearTimeout(hoverT); hide(); } });
			a.addEventListener('touchstart', function () {
				lastTouch = Date.now(); long = false;
				pressT = window.setTimeout(function () { long = true; show(a); if (navigator.vibrate) navigator.vibrate(10); }, 500);
			}, {passive: true});
			a.addEventListener('touchmove', function () { window.clearTimeout(pressT); }, {passive: true});
			a.addEventListener('touchend', function (e) { window.clearTimeout(pressT); if (long) e.preventDefault(); });
			a.addEventListener('click', function (e) { if (long) { e.preventDefault(); e.stopImmediatePropagation(); long = false; } }, true);
			a.addEventListener('contextmenu', function (e) { if (Date.now() - lastTouch < 1500) e.preventDefault(); });   // right click with a mouse stays
		});
		document.addEventListener('touchstart', function (e) { if (tip && !(e.target.closest && e.target.closest('[data-nu-hint]'))) hide(); }, {passive: true});
		window.addEventListener('scroll', hide, {passive: true});
	})();
	// own colours (no inline style attributes – CSP): set through the DOM
	$$('[data-nu-color]').forEach(function (s) { s.style.color = s.getAttribute('data-nu-color'); s.style.backgroundColor = s.getAttribute('data-nu-color') + '1f'; });

	// tabs: the last one is shown again
	var tabs = $$('.nuHomeTabs [data-bs-toggle="tab"]');
	if (tabs.length && nuBs.ready()) {
		var last = store('nunext.homeTab');
		var tb = last && tabs.filter(function (b) { return b.id === last; })[0];
		if (tb) nuBs.tab(tb).show();
		tabs.forEach(function (b) { b.addEventListener('shown.bs.tab', function () { store('nunext.homeTab', b.id); }); });
	}

	// "Favourites only" (0.8.353): CSS hides the other tiles (.nuHomeFavOnly); tabs without a favourite are hidden here; saved per login
	var favBtn = document.getElementById('nuHomeFavOnly'), favView = document.getElementById('nuHomeView');
	function favTabs() {
		if (!favView) return;
		var on = favView.classList.contains('nuHomeFavOnly') && !favView.classList.contains('nuHomeSearching');
		var first = null, activeGone = false;
		tabs.forEach(function (b) {
			var pane = document.querySelector(b.getAttribute('data-bs-target'));
			var hide = on && !(pane && pane.querySelector('[data-nu-fav]'));
			b.parentNode.hidden = hide;
			if (!hide && !first) first = b;
			if (hide && b.classList.contains('active')) activeGone = true;
		});
		if (activeGone && first && nuBs.ready()) nuBs.tab(first).show();
	}
	if (favBtn) {
		favTabs();
		favBtn.addEventListener('click', function () {
			var on = !favView.classList.contains('nuHomeFavOnly');
			favView.classList.toggle('nuHomeFavOnly', on);
			favBtn.classList.toggle('active', on);
			favBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
			var ic = favBtn.querySelector('i');
			ic.className = 'bi ' + (on ? 'bi-star-fill' : 'bi-star');
			favTabs();
			post({action: 'fav_only', on: on}).catch(function () { /* the view changed anyway; kept for this page only */ });
		});
	}

	// procedure tiles: run like the procedure page (window = output in a new tab, hidden = message only)
	function toast(msg, ok) {
		var box = $('#nuHomeToast') || document.body.appendChild(el('div', {id: 'nuHomeToast', 'class': 'position-fixed bottom-0 end-0 p-3 nuHomeToast'}));
		var a = el('div', {'class': 'alert py-2 px-3 small shadow-sm mb-2 ' + (ok ? 'alert-success' : 'alert-danger'), role: ok ? 'status' : 'alert', text: msg});
		box.appendChild(a);
		setTimeout(function () { a.remove(); }, ok ? 2500 : 7000);
	}
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
	// 'search' | 'tab' | 'favourites' | 'customize', fn), off, tiles(filter), tile(key), data (nuHomeSetData() of the PHP hook).
	// 'tile' and 'ready' are replayed for callbacks added later; every callback runs in try/catch (console + toast for the globeadmin).
	var H = (function () {
		var handlers = {}, objs = [], byEl = (typeof WeakMap === 'function') ? new WeakMap() : null, isReady = false;
		var viewEl = document.getElementById('nuHomeView');
		var told = {};
		function report(ev, err) {
			var msg = 'nuHome ' + ev + ': ' + (err && err.message ? err.message : String(err));
			if (window.console) console.error(msg, err);   // eslint-disable-line no-console
			if (D.ga && !told[msg]) { told[msg] = true; toast(msg, false); }   // the same error once, not once per tile
		}
		function call(ev, fn, args) { try { return fn.apply(null, args); } catch (e) { report(ev, e); return undefined; } }
		function emit(ev, args) { (handlers[ev] || []).slice().forEach(function (fn) { call(ev, fn, args || []); }); }
		function box(a) { return a.closest('.nuHomeList > *') || a.closest('.row > *') || a; }
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
				if (!cur) { cur = el('span', {'class': 'badge ms-1 align-middle nuHomeBadge'}); (a.querySelector('.nuHomeLabel') || a).appendChild(cur); }
				cur.className = 'badge ms-1 align-middle nuHomeBadge text-bg-' + (/^(primary|secondary|success|danger|warning|info|light|dark)$/.test(color || '') ? color : 'secondary');
				cur.textContent = text;
				tile.badge = text;
				return tile;
			};
			tile.setHint = function (text) {
				text = text === null || text === undefined ? '' : String(text);
				var h = a.querySelector('.nuHomeHint');
				if (a.hasAttribute('data-nu-hint')) a.setAttribute('data-nu-hint', tile.label + (text ? ' – ' + text : ''));   // compact: the tooltip
				else if (!h && text) {
					var lab = a.querySelector('.nuHomeLabel');
					h = el(lab && lab.tagName === 'SPAN' ? 'span' : 'div', {'class': (lab && lab.tagName === 'SPAN' ? 'd-block small' : 'small mt-1') + ' text-body-secondary nuHomeHint'});
					if (lab) lab.parentNode.insertBefore(h, lab.nextSibling);
				}
				if (h) { h.textContent = text; h.hidden = !text; }
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
	tabs.forEach(function (b) { b.addEventListener('shown.bs.tab', function () { H.emit('tab', b.id.replace('nuHomeTab_', '')); }); });
	if (favBtn) favBtn.addEventListener('click', function () { H.emit('favourites', favView.classList.contains('nuHomeFavOnly')); });

	// ================================================================ edit mode (0.8.263): the start page itself is edited
	// Customize turns the page into an editable copy of itself: tiles and groups are dragged (mouse; on touch screens hold
	// briefly, then drag), group and tab names are typed in place, Done saves the whole layout, Cancel throws it away.
	var btn = $('#nuHomeCustomize'), view = $('#nuHomeView'), edit = $('#nuHomeEdit');
	if (!edit) return;
	var btnBox = $('#nuHomeCustomizeGroup') || btn || document.createElement('span');   // Customize + ▾ (missing: admin role without "Start page layout")
	var L = null;                        // layout being edited (copy)
	var dirty = false;
	var activeTab = '';                  // tab shown while editing (display tabs)
	var editFav = false;                 // Customise: show only the favourite tiles (0.8.354) – a view filter, not saved
	var baseL = null;                    // the saved layout when Customise opened (own tiles for "Start from", 0.8.385)
	var prevEdit = null;                 // before the last profile was loaded: {L, dirty, activeTab} – Undo
	var pfNote = null;                   // strip under the bar after loading a profile: {icon, text}
	var keepOwn = true;                  // Start from: take own tiles along

	function clone(o) { return JSON.parse(JSON.stringify(o)); }
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
		editFav = !!(favView && favView.classList.contains('nuHomeFavOnly')) && L.fav.length > 0;   // as on the start page
		clearSearch();
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

	// ▾ menu at the bottom of the page (0.8.304): on phones it can open partly outside the screen – scroll it fully into view
	var sizeBtn = $('#nuHomeSizeMenu');
	if (sizeBtn) sizeBtn.addEventListener('shown.bs.dropdown', function () { setTimeout(fitMenu, 120); });   // after the browser's own scroll
	function fitMenu() {
		var menu = sizeBtn.parentNode.querySelector('.dropdown-menu');
		if (!menu || !menu.classList.contains('show')) return;
		// visible height without the browser's bars (visualViewport); the sticky top bar covers the top of the page
		var vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;
		var bar = document.querySelector('.nu-navbar');
		var topMin = bar ? bar.getBoundingClientRect().bottom + 8 : 8;
		var r = menu.getBoundingClientRect(), b = sizeBtn.getBoundingClientRect();
		var lo = Math.max(r.bottom, b.bottom) + 8, hi = Math.min(r.top, b.top);
		// instant: Bootstrap's smooth scrolling would move the page after Popper placed the menu
		if (lo > vh) window.scrollBy({top: Math.min(lo - vh, hi - topMin), behavior: 'instant'});
		else if (hi < topMin) window.scrollBy({top: hi - topMin, behavior: 'instant'});
	}

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
	function tileBox(a) { return a.closest('.nuHomeList > *') || a.closest('.row > *') || a; }
	function clearSearch() { if (search && search.value) { search.value = ''; filter(); } }
	function filter() {
		var q = search.value, P = window.nuPalette, words = (P ? P.norm(q) : q.toLowerCase()).split(/\s+/).filter(Boolean);
		var byId = {};
		(palItems || []).forEach(function (it) { byId[it.id] = it; });
		var shown = 0, first = null, onPage = {}, shownEls = [];
		view.classList.toggle('nuHomeSearching', words.length > 0);
		favTabs();
		$$('[data-nu-tile]', view).forEach(function (a) {
			var key = a.getAttribute('data-nu-tile'), it = byId['t:' + key];
			onPage['t:' + key] = true;
			var hay = it ? it.hay : (P ? P.norm(a.textContent) : a.textContent.toLowerCase());
			var ok = words.every(function (w) { return hay.indexOf(w) >= 0; });
			ok = ok && !a.hasAttribute('data-nu-js-hidden');                  // hidden by nuHome tile.hide() (0.8.379)
			tileBox(a).hidden = !ok;
			if (ok) { shown++; if (!first) first = a; shownEls.push(a); }
		});
		$$('.nuHomeGroup', view).forEach(function (g) { g.hidden = words.length > 0 && !$$('[data-nu-tile]', g).some(function (a) { return !tileBox(a).hidden; }); });
		$$('.tab-pane', view).forEach(function (p) { p.classList.toggle('nuHomeSearchHit', words.length > 0 && $$('.nuHomeGroup', p).some(function (g) { return !g.hidden; })); });
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

	// ▾ next to Customize: change the tile layout (large / compact / list) right away
	$$('[data-nu-size]').forEach(function (b) {
		b.addEventListener('click', function () {
			var size = b.getAttribute('data-nu-size');
			if (size === D.layout.size) return;
			post({action: 'size', size: size}).then(function (j) {
				if (j.ok) { window.location.href = D.url; return; }
				if (j.demo) { window.alert(j.message); return; }
				window.alert(t('home_saved_err'));
			}).catch(function (e) { window.alert(t('home_saved_err') + ' ' + e.message); });
		});
	});

	// ▾ saved layouts (0.8.333): switch at once; Save as / name and visibility in a small dialog
	var SV = D.saved || {};
	function layoutPost(body) {
		post(body).then(function (j) {
			if (j.ok) { window.location.href = D.url; return; }
			window.alert(j.message || t('home_saved_err'));
		}).catch(function (e) { window.alert(t('home_saved_err') + ' ' + e.message); });
	}
	$$('[data-nu-layout]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (b.getAttribute('data-nu-layout') === (SV.current || {}).id) return;
			layoutPost({action: 'layout_select', id: b.getAttribute('data-nu-layout')});
		});
	});
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
	$$('[data-nu-layout-act]').forEach(function (b) {
		b.addEventListener('click', function () {
			var a = b.getAttribute('data-nu-layout-act');
			if (a === 'saveas' || a === 'meta') layoutDialog(a);
			else if (a === 'delete') { if (window.confirm(t('home_layout_delete_confirm'))) layoutPost({action: 'layout_delete'}); }
			else if (a === 'default') layoutPost({action: 'layout_default'});
		});
	});

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
	function save() {
		var b = $('#nuHomeSave');
		b.disabled = true;
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
