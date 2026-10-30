/* Self-test page (pages/nuselftest.php): sortable result table (0.8.704) – a click on a title sorts by that column (status:
   failed, warning, OK, skipped), a second click reverses; the original order breaks ties. */
(function () {
	'use strict';
	var table = document.getElementById('stResults');
	if (!table) return;
	var tb = table.tBodies[0], rows = Array.prototype.slice.call(tb.rows);
	rows.forEach(function (r, i) { r.setAttribute('data-pos', i); });
	var RANK = {fail: 0, warn: 1, ok: 2, skip: 3};
	var key = function (r, i, kind) {
		if (kind === 'status') return RANK[r.getAttribute('data-status')] !== undefined ? RANK[r.getAttribute('data-status')] : 9;
		var t = r.cells[i].textContent.trim();
		return kind === 'num' ? (parseFloat(t) || 0) : t.toLowerCase();
	};
	var ths = table.querySelectorAll('th.stSort');
	ths.forEach(function (th, i) {
		th.querySelector('button').addEventListener('click', function () {
			var kind = th.getAttribute('data-sort'), dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
			rows.slice().sort(function (a, b) {
				var x = key(a, i, kind), y = key(b, i, kind);
				var c = typeof x === 'number' ? x - y : x.localeCompare(y, undefined, {numeric: true, sensitivity: 'base'});
				return c * dir || (+a.getAttribute('data-pos') - +b.getAttribute('data-pos'));
			}).forEach(function (r) { tb.appendChild(r); });
			ths.forEach(function (o) { o.removeAttribute('aria-sort'); });
			th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
		});
	});
})();
