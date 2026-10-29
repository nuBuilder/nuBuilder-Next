/* Logged-in users (0.8.687): a user with several sessions is one folded row – the chevron shows or hides the sessions below it. */
(function () {
	'use strict';
	document.querySelectorAll('tr.nuSessGroup').forEach(function (g) {
		var btn = g.querySelector('.nuSessFold'), id = g.getAttribute('data-grp');
		if (!btn) return;
		btn.addEventListener('click', function () {
			var open = btn.getAttribute('aria-expanded') !== 'true';
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			btn.querySelector('i').className = 'bi ' + (open ? 'bi-chevron-down' : 'bi-chevron-right');
			document.querySelectorAll('tr[data-grp-of="' + id + '"]').forEach(function (r) { r.hidden = !open; });
		});
	});
})();
