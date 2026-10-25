/* To do page (?p=todo, 0.8.467): every "## " section folds; one button folds / opens all; folded sections are remembered per device
   (localStorage nunext-todo-closed = keys of the folded sections, from their heading). */
(function () {
	'use strict';
	var secs = Array.prototype.slice.call(document.querySelectorAll('.nuTodoSec'));
	if (!secs.length) return;
	var KEY = 'nunext-todo-closed', btn = document.getElementById('nuTodoAll');
	var closed = [];
	try { closed = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { closed = []; }
	if (!Array.isArray(closed)) closed = [];
	secs.forEach(function (d) { if (closed.indexOf(d.getAttribute('data-nu-todo-key')) >= 0) d.open = false; });

	function save() {
		var keys = secs.filter(function (d) { return !d.open; }).map(function (d) { return d.getAttribute('data-nu-todo-key'); });
		try { localStorage.setItem(KEY, JSON.stringify(keys)); } catch (e) { /* storage blocked */ }
	}
	/** All open → "Collapse all"; otherwise "Expand all". */
	function label() {
		if (!btn) return;
		var allOpen = secs.every(function (d) { return d.open; });
		btn.querySelector('span').textContent = btn.getAttribute(allOpen ? 'data-collapse' : 'data-expand');
		var ic = btn.querySelector('i');
		ic.className = 'bi ' + (allOpen ? 'bi-arrows-collapse' : 'bi-arrows-expand') + ' me-1';
		btn.setAttribute('aria-pressed', allOpen ? 'false' : 'true');
	}
	secs.forEach(function (d) { d.addEventListener('toggle', function () { save(); label(); }); });
	if (btn) btn.addEventListener('click', function () {
		var open = !secs.every(function (d) { return d.open; });
		secs.forEach(function (d) { d.open = open; });
		save(); label();
	});
	label();
})();
