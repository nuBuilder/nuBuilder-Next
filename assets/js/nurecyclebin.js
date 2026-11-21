/* Recycle bin list: the Restore button of a row posts #rbRowRestore (the row's own click opens the entry). */
(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('.rbRestore') : null;
		if (!b) return;
		e.preventDefault();
		e.stopPropagation();
		var f = document.getElementById('rbRowRestore');
		if (!f) return;
		f.querySelector('[name=id]').value = b.getAttribute('data-id') || '';
		b.disabled = true;
		f.submit();
	}, true);
}());
