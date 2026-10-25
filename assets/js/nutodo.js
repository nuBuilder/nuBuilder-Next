/* To do page (?p=todo, 0.8.467): every "## " section folds; one button folds / opens all. All sections start folded (0.8.509 –
   the folded state is no longer remembered). */
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
