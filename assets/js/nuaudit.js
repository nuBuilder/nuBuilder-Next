/* Audit trail (0.8.351): the Delete dialog – counts the rows of the chosen selection, a date field for "before a date",
   typing DELETE for the whole audit trail. The form posts to the list's URL, so the server sees the same search and filters. */
(function () {
	'use strict';
	var form = document.getElementById('audDelForm');
	if (!form) return;
	var modal = document.getElementById('audDelModal');
	var older = document.getElementById('audDelOlder');
	var dateBox = document.getElementById('audDelDateBox');
	var before = document.getElementById('audDelBefore');
	var countBox = document.getElementById('audDelCount');
	var allBox = document.getElementById('audDelAllBox');
	var confirmAll = document.getElementById('audDelConfirm');
	var go = document.getElementById('audDelGo');
	var state = {rows: 0, all: false};
	var seq = 0;

	function update() {
		go.disabled = !(state.rows > 0 && (!state.all || confirmAll.value.trim() === 'DELETE'));
	}

	function count() {
		dateBox.classList.toggle('d-none', older.value !== 'date');
		var my = ++seq;
		var fd = new FormData(form);
		fd.set('action', 'audit_count');
		countBox.textContent = '…';
		go.disabled = true;
		fetch(form.getAttribute('action'), {method: 'POST', body: fd, headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (my !== seq) return;
				state = {rows: j.ok ? j.rows : 0, all: !!j.all};
				countBox.textContent = j.ok ? j.text : (j.error || j.message || '');
				countBox.className = 'alert py-2 mb-2 ' + (state.rows > 0 ? 'alert-warning' : 'alert-secondary');
				allBox.classList.toggle('d-none', !state.all || state.rows === 0);
				update();
			})
			.catch(function () { if (my === seq) countBox.textContent = '?'; });
	}

	form.addEventListener('change', function (e) { if (e.target !== confirmAll) count(); });
	confirmAll.addEventListener('input', update);
	before.addEventListener('input', count);
	modal.addEventListener('show.bs.modal', count);
})();
