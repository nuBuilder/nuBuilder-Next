/* Update page → System → Database steps: search and area chips over the grouped steps. */
(function () {
	'use strict';
	var box = document.getElementById('updSteps');
	if (!box) return;
	var q = document.getElementById('updStQ'), none = document.getElementById('updStNone');
	var chips = box.querySelectorAll('.updStChip'), area = '';

	function apply() {
		var words = (q ? q.value : '').toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
		box.querySelectorAll('.updStGrp').forEach(function (g) {
			var inArea = !area || g.dataset.area === area, n = 0;
			g.querySelectorAll('.updStRow').forEach(function (r) {
				var hay = r.dataset.q || '';
				var ok = inArea && words.every(function (w) { return hay.indexOf(w) >= 0; });
				r.hidden = !ok;
				if (ok) n++;
			});
			g.hidden = n === 0;
			var c = g.querySelector('.updStGrpN');
			if (c) c.textContent = n;
			shown += n;
		});
		if (none) none.hidden = shown > 0;
	}

	chips.forEach(function (c) {
		c.addEventListener('click', function () {
			area = c.dataset.area || '';
			chips.forEach(function (o) {
				var on = o === c;
				o.classList.toggle('active', on);
				o.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
			apply();
		});
	});
	if (q) {
		q.addEventListener('input', apply);
		q.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && q.value) { q.value = ''; apply(); e.stopPropagation(); }
			if (e.key === 'Enter') e.preventDefault();
		});
	}
	var reset = document.getElementById('updStReset');
	if (reset) reset.addEventListener('click', function () { if (q) q.value = ''; chips[0].click(); });
})();
