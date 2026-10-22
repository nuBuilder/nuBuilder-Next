// Template page: posts to the "hello" action with NuPage.fetch() (CSRF token and JSON headers included) and shows the answer.
(function () {
	'use strict';
	var btn = document.getElementById('hello');
	var out = document.getElementById('answer');
	if (!btn) return;
	btn.addEventListener('click', function () {
		btn.disabled = true;
		NuPage.fetch(btn.getAttribute('data-url'), {method: 'POST'})
			.then(function (r) { return r.json(); })
			.then(function (d) {
				out.textContent = d.message || d.error || '';
				if (d.message) NuPage.toast(d.message, 'success');
			})
			.catch(function () { out.textContent = 'Request failed.'; })
			.then(function () { btn.disabled = false; });
	});
}());
