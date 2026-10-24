/* Custom user fields (0.8.454): the settings of one field – choices only for "Choice list", "Unique" only for the types that support it. */
(function () {
	'use strict';
	var type = document.getElementById('ufType');
	if (!type) return;
	var UNIQUE = ['text', 'integer', 'email', 'phone', 'url'];
	function sync() {
		var t = type.getAttribute('data-type') || type.value;
		document.querySelectorAll('.ufOnlySelect').forEach(function (e) { e.hidden = t !== 'select'; });
		document.querySelectorAll('.ufOnlyUnique').forEach(function (e) { e.hidden = UNIQUE.indexOf(t) < 0; });
	}
	type.addEventListener('change', sync);
	sync();
})();
