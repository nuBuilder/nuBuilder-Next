// Print view of the lists (?p=<list>&export=print, core/nulistexport.php) and of form lists (?p=form&f=…&print=1, pages/nuform.php):
// the × after a column title hides that column, a column title dragged onto another moves the column there (Alt+← / Alt+→ on a
// title with the keyboard); Undo takes back the last step, Reset view shows all columns in their first order. Hidden columns and
// the order are remembered per list in this browser (localStorage, by column title); Copy table, printing, Export Excel and
// Export CSV take the visible columns in the shown order.
(function () {
	'use strict';
	var table = document.querySelector('.nu-print-table') || document.querySelector('body.nuPrintView #nuBrowseTable');
	if (!table) return;
	table.classList.add('nuPrintCols');
	var undoBtn = document.getElementById('nuPrintUndo');
	var resetBtn = document.getElementById('nuPrintReset');
	var info = document.getElementById('nuPrintHiddenInfo');
	var headRow = table.querySelector('thead tr');
	var heads = Array.prototype.slice.call(headRow.children);
	var n = heads.length;
	var titles = heads.map(function (th) { var t = th.querySelector('.nuPrintColTitle, a.nu-sort'); return (t ? t.textContent : th.textContent).trim(); });
	var qs = new URLSearchParams(location.search);
	var key = 'nunext-print-cols:' + (qs.get('p') || '') + (qs.get('f') ? ':' + qs.get('f') : '');   // form lists: per form
	var rows = Array.prototype.filter.call(table.querySelectorAll('tr'), function (tr) { return tr.children.length === n; });   // group headings keep their cell
	rows.forEach(function (tr) { Array.prototype.forEach.call(tr.children, function (c, i) { c.nuCol = i; }); });
	var start = []; for (var k = 0; k < n; k++) start.push(k);
	var order = start.slice();   // original column indexes in the shown order
	var hidden = [];             // hidden original indexes, in the order they were hidden
	var history = [];            // steps for Undo: {order, hidden} before the step

	function same(a, b) { return a.length === b.length && a.every(function (v, i) { return v === b[i]; }); }
	function step() { history.push({order: order.slice(), hidden: hidden.slice()}); }
	function apply() {
		rows.forEach(function (tr) {
			var cells = {};
			Array.prototype.forEach.call(tr.children, function (c) { cells[c.nuCol] = c; });
			order.forEach(function (i) { tr.appendChild(cells[i]); });
			order.forEach(function (i) { cells[i].classList.toggle('nuColHidden', hidden.indexOf(i) !== -1); });
		});
		var moved = !same(order, start);
		undoBtn.disabled = history.length === 0 && hidden.length === 0;   // after a reload: Undo shows the last hidden column again
		resetBtn.disabled = !hidden.length && !moved;
		info.textContent = hidden.length ? info.getAttribute('data-text').replace('%s', String(hidden.length)) : '';
		info.classList.toggle('d-none', !hidden.length);
		try {
			localStorage.setItem(key, JSON.stringify({hidden: hidden.map(function (i) { return titles[i]; }), order: moved ? order.map(function (i) { return titles[i]; }) : []}));
		} catch (e) { /* private mode */ }
		// Export Excel / Export CSV take the visible columns in the shown order (?cols=2,0,…)
		var cols = hidden.length || moved ? order.filter(function (i) { return hidden.indexOf(i) === -1; }).join(',') : '';
		var xl = document.getElementById('nuXlsxButton');
		if (xl) {
			var u = new URL(xl.getAttribute('href'), location.href);
			if (cols) u.searchParams.set('cols', cols); else u.searchParams.delete('cols');
			xl.setAttribute('href', u.pathname.split('/').pop() + u.search);
		}
		document.querySelectorAll('.js-csv-form').forEach(function (f) {
			var inp = f.querySelector('input[name="cols"]');
			if (!inp) { inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'cols'; f.appendChild(inp); }
			inp.value = cols;
			inp.disabled = !cols;
		});
	}
	function move(from, to, after) {           // original indexes: column from goes before (after) column to
		if (from === to) return;
		var o = order.filter(function (i) { return i !== from; });
		o.splice(o.indexOf(to) + (after ? 1 : 0), 0, from);
		if (same(o, order)) return;
		step(); order = o; apply();
	}

	try {
		var saved = JSON.parse(localStorage.getItem(key) || '[]');
		var sh = Array.isArray(saved) ? saved : (saved && Array.isArray(saved.hidden) ? saved.hidden : []);   // older: an array of hidden titles
		var so = saved && Array.isArray(saved.order) ? saved.order : [];
		if (so.length) {
			var o2 = [];
			so.forEach(function (t) { var i = titles.indexOf(t); if (i !== -1 && o2.indexOf(i) === -1) o2.push(i); });
			start.forEach(function (i) { if (o2.indexOf(i) === -1) o2.push(i); });   // columns the list got since keep their place at the end
			order = o2;
		}
		sh.forEach(function (t) {
			var i = titles.indexOf(t);
			if (i !== -1 && hidden.indexOf(i) === -1 && hidden.length < n - 1) hidden.push(i);
		});
	} catch (e) { order = start.slice(); hidden = []; }

	heads.forEach(function (th, i) {
		var b = th.querySelector('.nuColHide');
		if (b) b.addEventListener('click', function () {
			if (hidden.indexOf(i) !== -1 || hidden.length >= n - 1) return;   // one column always stays
			step(); hidden.push(i); apply();
		});
		// drag and drop of the titles (links inside do not start a drag of their own)
		th.draggable = true;
		th.classList.add('nuPrintDrag');
		th.title = th.title || info.getAttribute('data-move') || '';
		th.querySelectorAll('a, img').forEach(function (a) { a.draggable = false; });
		th.addEventListener('dragstart', function (e) {
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData('text/plain', String(i)); } catch (x) { /* expected in old browsers */ }
			headRow.nuDragFrom = i;
			th.classList.add('nuPrintDragging');
		});
		th.addEventListener('dragend', function () {
			th.classList.remove('nuPrintDragging');
			heads.forEach(function (h) { h.classList.remove('nuPrintDropBefore', 'nuPrintDropAfter'); });
			headRow.nuDragFrom = null;
		});
		th.addEventListener('dragover', function (e) {
			if (headRow.nuDragFrom === null || headRow.nuDragFrom === undefined) return;
			e.preventDefault();
			var r = th.getBoundingClientRect(), after = e.clientX > r.left + r.width / 2;
			heads.forEach(function (h) { h.classList.remove('nuPrintDropBefore', 'nuPrintDropAfter'); });
			if (headRow.nuDragFrom !== i) th.classList.add(after ? 'nuPrintDropAfter' : 'nuPrintDropBefore');
		});
		th.addEventListener('drop', function (e) {
			e.preventDefault();
			var from = headRow.nuDragFrom, r = th.getBoundingClientRect();
			heads.forEach(function (h) { h.classList.remove('nuPrintDropBefore', 'nuPrintDropAfter'); });
			if (from !== null && from !== undefined) move(from, i, e.clientX > r.left + r.width / 2);
		});
		// keyboard: Alt+← / Alt+→ on the title (or its link) moves the column one place
		th.addEventListener('keydown', function (e) {
			if (!e.altKey || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
			e.preventDefault();
			var vis = order.filter(function (x) { return hidden.indexOf(x) === -1; }), p = vis.indexOf(i), to = vis[p + (e.key === 'ArrowLeft' ? -1 : 1)];
			if (to === undefined) return;
			move(i, to, e.key === 'ArrowRight');
			var f = th.querySelector('a, button'); if (f) f.focus();
		});
	});
	undoBtn.addEventListener('click', function () {
		var h = history.pop();
		if (h) { order = h.order; hidden = h.hidden; } else if (hidden.length) hidden.pop(); else return;
		apply();
	});
	resetBtn.addEventListener('click', function () {
		if (!hidden.length && same(order, start)) return;
		step(); order = start.slice(); hidden = []; apply();
	});
	apply();
}());
