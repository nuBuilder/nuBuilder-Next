/* Settings centre (0.8.702, core/nusettingshub.php): search over every setting in the side bar (index loaded once from
   ?p=settings&index=1), the target of a result highlighted (#anchor), ?q= fills the search of nuBuilder Forte's list,
   ?find= jumps to a variable in the nuconfig.php editor. */
(function () {
	'use strict';
	var input = document.getElementById('nuSetSearch'), box = document.getElementById('nuSetResults');
	var index = null, loading = null, hits = [], cur = -1;

	function load() {
		if (index || loading) return loading;
		loading = fetch(input.getAttribute('data-url'), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
			.then(function (r) { return r.ok ? r.json() : []; })
			.then(function (d) { index = Array.isArray(d) ? d : []; return index; })
			.catch(function () { index = []; return index; });
		return loading;
	}
	function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
	function score(e, words) {
		var t = norm(e.t), k = norm(e.k), d = norm(e.d), s = norm(e.s), total = 0;
		for (var i = 0; i < words.length; i++) {
			var w = words[i], p = 0;
			if (t.indexOf(w) === 0) p = 8; else if (t.indexOf(w) >= 0) p = 6;
			if (k.indexOf(w) >= 0) p = Math.max(p, 5);
			if (!p && s.indexOf(w) >= 0) p = 2;
			if (!p && d.indexOf(w) >= 0) p = 1;
			if (!p) return 0;
			total += p;
		}
		return total;
	}
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
	function render() {
		var q = norm(input.value).trim();
		box.textContent = '';
		cur = -1;
		if (!q || !index) { box.hidden = true; input.setAttribute('aria-expanded', 'false'); return; }
		var words = q.split(/\s+/);
		hits = index.map(function (e) { return {e: e, s: score(e, words)}; }).filter(function (x) { return x.s > 0; })
			.sort(function (a, b) { return b.s - a.s; }).slice(0, 30).map(function (x) { return x.e; });
		if (!hits.length) box.appendChild(el('div', 'list-group-item small text-body-secondary', input.getAttribute('data-none')));
		hits.forEach(function (e, i) {
			var a = el('a', 'list-group-item list-group-item-action nuSetHitItem');
			a.href = e.u; a.setAttribute('role', 'option'); a.id = 'nuSetHit' + i;
			var top = el('div', 'd-flex gap-2 align-items-baseline');
			top.appendChild(el('span', 'fw-semibold text-truncate', e.t));
			top.appendChild(el('span', 'badge text-bg-light border ms-auto flex-shrink-0', e.s));
			a.appendChild(top);
			if (e.v) a.appendChild(el('div', 'small font-monospace text-truncate nuSetHitVal', e.v));
			if (e.d) a.appendChild(el('div', 'small text-body-secondary nuSetHitDesc', e.d));
			box.appendChild(a);
		});
		box.hidden = false;
		input.setAttribute('aria-expanded', 'true');
	}
	function move(d) {
		var items = box.querySelectorAll('a');
		if (!items.length) return;
		cur = (cur + d + items.length) % items.length;
		items.forEach(function (a, i) { a.classList.toggle('active', i === cur); });
		input.setAttribute('aria-activedescendant', items[cur].id);
		items[cur].scrollIntoView({block: 'nearest'});
	}
	if (input && box) {
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-expanded', 'false');
		input.addEventListener('focus', load);
		input.addEventListener('input', function () { (load() || Promise.resolve()).then(render); });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
			else if (e.key === 'Enter') {
				var items = box.querySelectorAll('a');
				if (items.length) { e.preventDefault(); location.href = (items[cur >= 0 ? cur : 0]).href; }
			} else if (e.key === 'Escape') { input.value = ''; render(); }
		});
		document.addEventListener('click', function (e) { if (!e.target.closest('.nuSetSearch')) { box.hidden = true; input.setAttribute('aria-expanded', 'false'); } });
		// "/" anywhere on a settings page focuses the search (not while typing)
		document.addEventListener('keydown', function (e) {
			if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
			var t = e.target;
			if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.closest('.CodeMirror'))) return;
			e.preventDefault(); input.focus();
		});
	}

	// the target of a search result: highlighted once the page is there
	function highlight(target) {
		if (!target) return;
		var host = target.closest('.card, .form-check, .col-sm-6, .col-lg-4, .list-group-item, tr') || target;
		host.scrollIntoView({block: 'center'});
		host.classList.add('nuSetHit');
		setTimeout(function () { host.classList.remove('nuSetHit'); }, 2600);
	}
	window.addEventListener('load', function () {
		// phones: the sections are a row that scrolls sideways – the current one in view
		var act = document.querySelector('.nuSetSections > .nav-item > .nav-link.active');
		var row = act && act.closest('.nuSetSections');
		if (row && row.scrollWidth > row.clientWidth) row.scrollLeft = act.offsetLeft - 12;
		if (location.hash.length > 1) {
			var t = document.getElementById(decodeURIComponent(location.hash.slice(1)));
			if (t) setTimeout(function () { highlight(t); }, 150);
		}
		var params = new URLSearchParams(location.search);
		// nuBuilder Forte's settings list: ?q=<setting> → search there, all statuses
		var q = params.get('q'), find = document.getElementById('setFind');
		if (q && find) {
			var all = document.getElementById('setSt_all');
			if (all && !all.checked) { all.checked = true; all.dispatchEvent(new Event('change', {bubbles: true})); }
			find.value = q;
			find.dispatchEvent(new Event('input', {bubbles: true}));
			setTimeout(function () {
				var row = Array.prototype.find.call(document.querySelectorAll('#setTable tbody tr'), function (r) { return !r.hidden && r.textContent.indexOf(q) >= 0; });
				if (row) highlight(row);
			}, 100);
		}
		// nuconfig.php editor: ?find=<variable> → the line of the variable (assets/js/nuconfigfile.js exposes the editor)
		var v = params.get('find');
		if (v && window.nuConfigEditor) {
			var cm = window.nuConfigEditor, lines = cm.getValue().split('\n'), re = new RegExp('\\$' + v.replace(/[^A-Za-z0-9_]/g, '') + '\\b');
			for (var i = 0; i < lines.length; i++) {
				if (re.test(lines[i])) {
					cm.focus(); cm.setCursor({line: i, ch: 0}); cm.setSelection({line: i, ch: 0}, {line: i, ch: lines[i].length});
					cm.scrollIntoView({line: i, ch: 0}, 120);
					break;
				}
			}
		}
	});
})();
