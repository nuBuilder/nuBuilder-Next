// Print view of the lists (?p=<list>&export=print, core/nulistexport.php – 0.8.397): the × after a column title hides that column;
// Undo shows the last hidden one again, Reset view shows all. The hidden columns are remembered per list in this browser
// (localStorage, by column title); Copy table, printing, Export Excel and Export CSV take only the visible columns.
(function () {
	'use strict';
	var table = document.querySelector('.nu-print-table');
	if (!table) return;
	var undoBtn = document.getElementById('nuPrintUndo');
	var resetBtn = document.getElementById('nuPrintReset');
	var info = document.getElementById('nuPrintHiddenInfo');
	var heads = Array.prototype.slice.call(table.querySelectorAll('thead th'));
	var titles = heads.map(function (th) { var t = th.querySelector('.nuPrintColTitle'); return t ? t.textContent : th.textContent; });
	var key = 'nunext-print-cols:' + (new URLSearchParams(location.search).get('p') || '');
	var stack = [];          // hidden column indexes, in the order they were hidden

	function apply() {
		var hidden = {};
		stack.forEach(function (i) { hidden[i] = 1; });
		table.querySelectorAll('tr').forEach(function (tr) {
			Array.prototype.forEach.call(tr.children, function (c, i) { c.classList.toggle('nuColHidden', !!hidden[i]); });
		});
		undoBtn.disabled = resetBtn.disabled = stack.length === 0;
		info.textContent = stack.length ? info.getAttribute('data-text').replace('%s', String(stack.length)) : '';
		info.classList.toggle('d-none', !stack.length);
		try { localStorage.setItem(key, JSON.stringify(stack.map(function (i) { return titles[i]; }))); } catch (e) { /* private mode */ }
		// Export Excel / Export CSV take the visible columns only (?cols=0,2,… – 0.8.398)
		var cols = stack.length ? titles.map(function (t, i) { return i; }).filter(function (i) { return !hidden[i]; }).join(',') : '';
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

	try {
		var saved = JSON.parse(localStorage.getItem(key) || '[]');
		if (Array.isArray(saved)) saved.forEach(function (t) {
			var i = titles.indexOf(t);
			if (i !== -1 && stack.indexOf(i) === -1 && stack.length < titles.length - 1) stack.push(i);
		});
	} catch (e) { stack = []; }

	heads.forEach(function (th, i) {
		var b = th.querySelector('.nuColHide');
		if (!b) return;
		b.addEventListener('click', function () {
			if (stack.indexOf(i) !== -1 || stack.length >= titles.length - 1) return;   // one column always stays
			stack.push(i);
			apply();
		});
	});
	undoBtn.addEventListener('click', function () { stack.pop(); apply(); });
	resetBtn.addEventListener('click', function () { stack = []; apply(); });
	apply();
}());
