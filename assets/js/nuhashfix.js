/* nuBuilder Next – Compatibility check → put hash variables in quotes (pages/nuhashfix.php): Tick all / none, the count on the button. */
(function () {
	'use strict';
	var form = document.getElementById('hfForm'), n = document.getElementById('hfGoN'), go = document.getElementById('hfGo');
	if (!form) return;
	function ticks() { return form.querySelectorAll('.hfTick'); }
	function count() {
		var c = 0;
		ticks().forEach(function (t) { if (t.checked) c++; t.closest('.hfSpot').classList.toggle('hfOff', !t.checked); });
		if (n) n.textContent = c;
		if (go) go.disabled = c === 0;
	}
	function all(on) { ticks().forEach(function (t) { t.checked = on; }); count(); }
	form.addEventListener('change', count);
	var a = document.getElementById('hfAll'), z = document.getElementById('hfNoneBtn');
	if (a) a.addEventListener('click', function () { all(true); });
	if (z) z.addEventListener('click', function () { all(false); });
	count();
})();
