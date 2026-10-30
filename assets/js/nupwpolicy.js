/* nuBuilder Next – Settings → Password policy (0.8.745, pages/nupwpolicy.php): "Try a password" asks the server (action=test, nothing stored). */
(function () {
	'use strict';
	var f = document.getElementById('pwpTestForm'), out = document.getElementById('pwpTestOut');
	if (!f || !out) return;
	f.addEventListener('submit', function (e) {
		e.preventDefault();
		var fd = new FormData();
		fd.append('_csrf', f.getAttribute('data-csrf'));
		fd.append('action', 'test');
		fd.append('pw', document.getElementById('pwpTestPw').value);
		fd.append('login', document.getElementById('pwpTestLogin').value);
		out.textContent = '…';
		fetch(f.getAttribute('data-url'), {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
			out.className = 'mt-3 small ' + (j.ok ? 'text-success-emphasis' : 'text-danger-emphasis');
			out.textContent = j.message;
			out.setAttribute('data-ok', j.ok ? '1' : '0');
		}, function () { out.textContent = ''; });
	});
})();
