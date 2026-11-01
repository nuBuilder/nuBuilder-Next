/* Lists refreshed in place (0.8.776, core/nulistview.php data-lv-smooth): a filter, the search, a sort title, a page, the page size or a
   chip of a system list fetches the list page and swaps only its card – no page reload, so nothing flickers. The address follows
   (history.pushState), Back / Forward swap again. Anything unusual (another page, a view change, an error, a redirect elsewhere, a
   card without data-lv-smooth) falls back to a normal page load. After the swap the card's scripts run again: tooltips, searchable
   selects, filters open on wide screens, confirm forms, page jump, search marks, column widths, phone switch, "more", advanced search. */
(function () {
	'use strict';
	var card = document.querySelector('.nuListCard[data-lv-smooth]');
	if (!card || !window.fetch || !window.history || !window.DOMParser) return;
	var viewParam = card.getAttribute('data-lv-smooth') || 'view';
	var ctrl = null;

	/** The list a URL shows: same file, same route and fixed parameters (tab …), same view, not a record / action / export. */
	function listKey(u) {
		try {
			var x = new URL(u, window.location.href), s = x.searchParams;
			if (x.origin !== window.location.origin) return '';
			if (s.get('id') || s.get('r') || s.get('action') || s.get('export') || s.get('print') || s.get('fmt')) return '';
			return x.pathname + '|' + (s.get('p') || '') + '|' + (s.get('tab') || '') + '|' + (s.get('s') || '') + '|' + (s.get(viewParam) || '');
		} catch (e) { return ''; }
	}
	function sameList(u) { var k = listKey(u); return k !== '' && k === listKey(window.location.href); }

	function rerun(root) {
		document.querySelectorAll('body > .tooltip').forEach(function (t) { t.remove(); });   // a tooltip of the old card
		if (window.nuBs && window.nuBs.ready()) root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { window.nuBs.tooltip(el); });
		[['nuListSelects', root], ['nuOpenMin', root], ['nuBindConfirm', root], ['nuBindPagerJump', root], ['nuHighlightLists', root], ['nuInitResize'], ['nuAdvSearchInit']].forEach(function (f) {
			if (typeof window[f[0]] === 'function') { try { window[f[0]](f[1]); } catch (e) { /* one helper must not stop the others */ } }
		});
		(window.nuLvInits || []).forEach(function (f) { try { f(); } catch (e) { /* see above */ } });
		document.dispatchEvent(new CustomEvent('nu:listrefresh', {detail: {card: root}}));
	}

	/** Fetches url and swaps the card; false = not handled (the caller loads the page normally). */
	function go(url, push) {
		if (!sameList(url)) return false;
		if (ctrl) ctrl.abort();
		ctrl = window.AbortController ? new AbortController() : null;
		var old = document.querySelector('.nuListCard[data-lv-smooth]');
		if (!old) return false;
		var act = document.activeElement, focusId = act && act.id && old.contains(act) ? act.id : '', caret = null;
		try { if (act && typeof act.selectionStart === 'number') caret = act.selectionStart; } catch (e) { /* not a text field */ }
		var slow = setTimeout(function () { old.classList.add('nuLvLoading'); old.setAttribute('aria-busy', 'true'); }, 150);
		fetch(url, {credentials: 'same-origin', headers: {'X-Nu-List': '1'}, signal: ctrl ? ctrl.signal : undefined}).then(function (r) {
			if (!r.ok || (r.redirected && !sameList(r.url)) || !/text\/html/.test(r.headers.get('content-type') || '')) throw new Error('fallback');
			return r.text().then(function (html) { return [html, r.url || url]; });
		}).then(function (res) {
			clearTimeout(slow);
			var doc = new DOMParser().parseFromString(res[0], 'text/html');
			var fresh = doc.querySelector('.nuListCard[data-lv-smooth]');
			if (!fresh) throw new Error('fallback');
			var cur = document.querySelector('.nuListCard[data-lv-smooth]');
			if (!cur) throw new Error('fallback');
			var node = document.importNode(fresh, true);
			cur.replaceWith(node);
			['nuLvData'].forEach(function (id) {             // the view editor's data next to the card
				var a = document.getElementById(id), b = doc.getElementById(id);
				if (a && b) a.replaceWith(document.importNode(b, true));
			});
			if (push !== false) history.pushState({nuLv: 1}, '', res[1]);
			if (doc.title) document.title = doc.title;
			rerun(node);
			if (focusId) {
				var f = document.getElementById(focusId);
				if (f) { f.focus({preventScroll: true}); try { if (caret !== null) f.setSelectionRange(caret, caret); } catch (e) { /* select */ } }
			}
			var top = node.getBoundingClientRect().top;
			if (top < 0) node.scrollIntoView({block: 'start'});   // a page further down: back to the top of the list
			document.documentElement.setAttribute('data-nu-lv-swaps', String((+document.documentElement.getAttribute('data-nu-lv-swaps') || 0) + 1));   // for the tests
		}).catch(function (e) {
			clearTimeout(slow);
			if (e && e.name === 'AbortError') return;
			window.location.href = url;                        // anything unexpected: the normal way
		});
		return true;
	}
	window.nuListGo = function (url) { return go(url, true); };

	history.replaceState(Object.assign({}, history.state || {}, {nuLv: 1}), '');
	window.addEventListener('popstate', function (e) {
		if (!e.state || !e.state.nuLv) return;
		if (!go(window.location.href, false)) window.location.reload();
	});

	function inCard(el) { return el && el.closest && el.closest('.nuListCard[data-lv-smooth]'); }

	// links of the card: sort titles, pages, page size, chips, Show all, the × of the search …
	document.addEventListener('click', function (e) {
		if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
		var a = e.target.closest && e.target.closest('a[href]');
		if (!a || !inCard(a) || a.target || a.hasAttribute('download') || a.getAttribute('href').charAt(0) === '#') return;
		// menus too (page size of the footer); the view menu, exports and links to other pages fail sameList() and load normally
		if (go(a.href, true)) e.preventDefault();
	});

	// the search / filter form (Enter, a filter select through .js-autosubmit → requestSubmit, the × in the search field)
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (e.defaultPrevented || !f.classList || !f.classList.contains('nuListFilter') || !inCard(f) || (f.method || 'get').toLowerCase() !== 'get') return;
		var fd = new FormData(f), qs = new URLSearchParams();
		fd.forEach(function (v, k) { if (typeof v === 'string') qs.append(k, v); });
		var url = new URL(f.getAttribute('action') || 'index.php', window.location.href);
		url.search = qs.toString();
		if (go(url.toString(), true)) e.preventDefault();
	});
})();
