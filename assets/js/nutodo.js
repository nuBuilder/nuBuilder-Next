/* To do page (?p=todo, 0.8.467): every "## " section folds; one button folds / opens all. All sections start folded (0.8.509 –
   the folded state is no longer remembered). */
// upload (0.8.712): the button opens the file picker, a chosen file is sent at once (also when TODO.md exists – no question)
(function () {
	'use strict';
	var form = document.getElementById('nuTodoUpForm'), inp = document.getElementById('nuTodoFile'), btn = document.getElementById('nuTodoUpload');
	if (!form || !inp || !btn) return;
	btn.addEventListener('click', function () { inp.click(); });
	inp.addEventListener('change', function () {
		var f = inp.files && inp.files[0], max = parseInt(form.getAttribute('data-max'), 10) || 0;
		if (!f) return;
		var err = document.getElementById('nuTodoUpErr');
		if (max && f.size > max) { if (err) { err.textContent = form.getAttribute('data-big'); err.hidden = false; } inp.value = ''; return; }
		btn.disabled = true;
		btn.querySelector('i').className = 'spinner-border spinner-border-sm me-1';
		form.submit();
	});
})();

(function () {
	'use strict';
	var secs = Array.prototype.slice.call(document.querySelectorAll('.nuTodoSec'));
	if (!secs.length) return;
	var btn = document.getElementById('nuTodoAll');
	try { localStorage.removeItem('nunext-todo-closed'); } catch (e) { /* storage blocked */ }   // remembered state of 0.8.467–0.8.508
	/** All open → "Collapse all"; otherwise "Expand all". */
	function label() {
		if (!btn) return;
		var allOpen = secs.every(function (d) { return d.open; });
		btn.querySelector('span').textContent = btn.getAttribute(allOpen ? 'data-collapse' : 'data-expand');
		var ic = btn.querySelector('i');
		ic.className = 'bi ' + (allOpen ? 'bi-arrows-collapse' : 'bi-arrows-expand') + ' me-1';
		btn.setAttribute('aria-pressed', allOpen ? 'false' : 'true');
	}
	secs.forEach(function (d) { d.addEventListener('toggle', label); });
	if (btn) btn.addEventListener('click', function () {
		var open = !secs.every(function (d) { return d.open; });
		secs.forEach(function (d) { d.open = open; });
		label();
	});
	label();
})();
