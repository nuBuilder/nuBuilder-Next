/* Admin role form (0.8.739, own columns 0.8.1022): sortable rights table – by name, area, full access or the chosen level
   (None / View / Change: ticked first). The third click on a title goes back to the order of the groups. Kept per browser. */
(function () {
	'use strict';
	var table = document.getElementById('admRights');
	if (!table) return;
	var body = table.tBodies[0], KEY = 'nunext-adm-rights-sort';
	var original = Array.prototype.slice.call(body.children);
	var level = function (tr) { var c = tr.querySelector('input[type=radio]:checked'); return c ? c.value : ''; };
	var cmp = function (a, b) { return a.localeCompare(b, undefined, {sensitivity: 'base', numeric: true}); };
	function key(tr, col) {
		if (col === 'name') return tr.getAttribute('data-name') || '';
		if (col === 'area') return tr.getAttribute('data-area') || '';
		if (col === 'full') return tr.getAttribute('data-full') === '1' ? '0' : '1';   // full access first
		return level(tr) === col.slice(4) ? '0' : '1';           // lvl:<level> – rows with that level first
	}
	function apply(col, dir) {
		table.querySelectorAll('th.admSort').forEach(function (o) { o.removeAttribute('aria-sort'); });
		if (!col) {                                                // back to the order of the groups
			original.forEach(function (tr) { body.appendChild(tr); });
			return;
		}
		var rows = original.slice();
		rows.sort(function (a, b) {
			return cmp(key(a, col), key(b, col)) * (dir === 'desc' ? -1 : 1) || cmp(key(a, 'name'), key(b, 'name'));
		});
		rows.forEach(function (tr) { body.appendChild(tr); });
		var th = table.querySelector('th.admSort[data-sort="' + col + '"]');
		if (th) th.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
	}
	function store(v) { try { if (v) localStorage.setItem(KEY, v); else localStorage.removeItem(KEY); } catch (e) { /* no storage */ } }
	table.querySelectorAll('th.admSort').forEach(function (th) {
		th.querySelector('button').addEventListener('click', function () {
			var col = th.getAttribute('data-sort'), s = th.getAttribute('aria-sort');
			if (s === 'descending') { apply('', ''); store(''); return; }
			var dir = s === 'ascending' ? 'desc' : 'asc';
			apply(col, dir);
			store(col + '|' + dir);
		});
	});
	try {
		var keep = (localStorage.getItem(KEY) || '').split('|');
		if (keep[0] && table.querySelector('th.admSort[data-sort="' + keep[0] + '"]')) apply(keep[0], keep[1] === 'desc' ? 'desc' : 'asc');
	} catch (e) { /* no storage */ }
})();
