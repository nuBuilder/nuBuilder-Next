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

/* Remove older versions (0.8.1065): the button works only while a version is ticked. */
(function () {
	'use strict';
	Array.prototype.forEach.call(document.querySelectorAll('.nuLpOldDlg'), function (dlg) {
		var go = dlg.querySelector('.nuLpOldGo');
		var boxes = Array.prototype.slice.call(dlg.querySelectorAll('.nuLpOldChk'));
		function sync() { go.disabled = !boxes.some(function (b) { return b.checked; }); }
		boxes.forEach(function (b) { b.addEventListener('change', sync); });
		sync();
	});
})();
