/* Library packs (0.8.405, update page tab Libraries): "select all" and the buttons for the ticked libraries. */
(function () {
	'use strict';
	var all = document.getElementById('lpAll');
	if (!all) return;
	var boxes = Array.prototype.slice.call(document.querySelectorAll('#lpTable input[name="slots[]"]'));
	var bulk = Array.prototype.slice.call(document.querySelectorAll('[data-lp-bulk]'));
	function sync() {
		var n = boxes.filter(function (b) { return b.checked; }).length;
		bulk.forEach(function (b) { b.disabled = n === 0; });
		all.checked = n > 0 && n === boxes.length;
		all.indeterminate = n > 0 && n < boxes.length;
	}
	all.addEventListener('change', function () {
		boxes.forEach(function (b) { b.checked = all.checked; });
		sync();
	});
	boxes.forEach(function (b) { b.addEventListener('change', sync); });
	sync();
})();
