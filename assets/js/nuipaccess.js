/* nuBuilder Next: Settings → Security → IP access (0.8.722, pages/nuipaccess.php) – the ranges field of a rule shows only for
 * "Own ranges", the count of exceptions follows the selects, the filter of the access levels. */
(function () {
	'use strict';
	var count = document.getElementById('ipaExcCount');
	function sync(sel) {
		var wrap = document.getElementById(sel.getAttribute('data-ipa-target') + 'Wrap');
		if (wrap) wrap.hidden = sel.value !== 'ranges';
	}
	function recount() {
		if (!count) return;
		count.textContent = String(Array.prototype.filter.call(document.querySelectorAll('#ipaLevels select.ipaMode'), function (s) { return s.value !== 'default'; }).length);
	}
	document.querySelectorAll('select.ipaMode').forEach(function (sel) {
		sel.addEventListener('change', function () {
			sync(sel);
			recount();
			var ta = document.getElementById(sel.getAttribute('data-ipa-target'));
			if (sel.value === 'ranges' && ta && !ta.value.trim()) ta.focus();
		});
	});
	var f = document.getElementById('ipaLvFilter');
	if (f) f.addEventListener('input', function () {
		var q = f.value.trim().toLowerCase();
		document.querySelectorAll('#ipaLevels tr[data-ipa-level]').forEach(function (tr) {
			tr.hidden = q !== '' && (tr.getAttribute('data-ipa-text') || '').indexOf(q) < 0;
		});
	});
}());
