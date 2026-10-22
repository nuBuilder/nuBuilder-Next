/* Notification centre – the edit form (0.8.357, pages/nunotifications.php): Tom Select for the recipient fields, "Everybody" locks
   them, "Must confirm" forces the fixed list, and the live number of recipients (action count). */
(function () {
	'use strict';
	var form = document.getElementById('ntfForm');
	if (!form) return;
	var all = document.getElementById('ntf_all'), countBox = document.getElementById('ntfCount'), showBtn = document.getElementById('ntfCountShow'), names = document.getElementById('ntfNames');
	var confirmSw = document.getElementById('ntf_confirm'), live = document.getElementById('ntf_mode_live'), fixed = document.getElementById('ntf_mode_fixed');
	var selects = Array.prototype.slice.call(form.querySelectorAll('select[data-ntf-ts]'));
	var ts = [], timer = 0, seq = 0, lastNames = [];

	selects.forEach(function (s) {
		if (!window.TomSelect) return;
		var t = new TomSelect(s, {
			plugins: ['remove_button'], maxOptions: null, hidePlaceholder: true, closeAfterSelect: false, searchField: ['text', 'sub'],
			create: s.getAttribute('data-create') === '1', persist: false,
			render: {
				option: function (d, esc) { return '<div><div>' + esc(d.text) + '</div>' + (d.sub ? '<div class="small text-body-secondary">' + esc(d.sub) + '</div>' : '') + '</div>'; },
				item: function (d, esc) { return '<div title="' + esc(d.sub || '') + '">' + esc(d.text) + '</div>'; }
			}
		});
		t.on('change', schedule);
		ts.push(t);
	});

	function lockAudience() {
		var on = !!(all && all.checked);
		ts.forEach(function (t) { if (on) t.disable(); else t.enable(); });
		if (!ts.length) selects.forEach(function (s) { s.disabled = on; });
	}
	var modeLocked = !live || live.disabled;               // published: the mode stays
	function lockMode() {
		if (modeLocked || !confirmSw) return;
		if (confirmSw.checked) { fixed.checked = true; live.disabled = true; } else live.disabled = false;
	}

	function count() {
		var fd = new FormData(form);
		fd.set('action', 'count');
		var my = ++seq;
		fetch(countBox.getAttribute('data-url'), {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (my !== seq || !j.ok) return;
				countBox.textContent = j.text;
				countBox.className = 'alert py-2 mb-0 small ' + (j.n ? 'alert-info' : 'alert-warning');
				lastNames = j.names || [];
				showBtn.hidden = !lastNames.length;
				if (!names.hidden) names.textContent = lastNames.join(', ');
			}).catch(function () { /* keep */ });
	}
	function schedule() { window.clearTimeout(timer); timer = window.setTimeout(count, 250); }

	if (all) all.addEventListener('change', function () { lockAudience(); schedule(); });
	if (confirmSw) confirmSw.addEventListener('change', lockMode);
	showBtn.addEventListener('click', function () { names.hidden = !names.hidden; names.textContent = lastNames.join(', '); });
	lockAudience();
	lockMode();
	count();
})();
