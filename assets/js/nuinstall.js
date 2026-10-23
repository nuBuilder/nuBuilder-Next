/* nuBuilder Next installer (pages/nuinstall.php) – no inline scripts (CSP) */
(function () {
	'use strict';
	var $ = function (sel) { return document.querySelector(sel); };

	// ---------------------------------------------------------------- database: type → port, MSSQL-only options
	var type = $('#type'), port = $('#port');
	function syncType() {
		if (!type) return;
		var ms = type.value === 'sqlsrv';
		document.querySelectorAll('.insMssqlOnly').forEach(function (el) { el.hidden = !ms; });
	}
	if (type) {
		type.addEventListener('change', function () {
			if (port && (port.value === '' || port.value === '3306' || port.value === '1433')) port.value = type.value === 'sqlsrv' ? '1433' : '3306';
			syncType();
		});
		syncType();
	}

	// ---------------------------------------------------------------- administrator password: strength + match
	var pw = $('#password'), pw2 = $('#password2'), bar = $('#insStrength'), txt = $('#insStrengthText');
	if (pw && bar) {
		var labels = [];
		try { labels = JSON.parse(bar.getAttribute('data-labels') || '[]'); } catch (e) {}
		var rules = txt ? txt.textContent : '';
		var score = function (v) {
			if (v.length < 10) return 0;
			var s = 1;
			var kinds = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter(function (r) { return r.test(v); }).length;
			if (v.length >= 14) s++;
			if (kinds >= 3) s++;
			if (v.length >= 18 && kinds >= 3) s++;
			if (/^(.)\1+$/.test(v) || /^(?:0123456789|1234567890|password|passwort)/i.test(v)) s = 0;
			return Math.min(s, 3);
		};
		var colors = ['bg-danger', 'bg-warning', 'bg-info', 'bg-success'];
		var update = function () {
			var v = pw.value;
			bar.className = 'progress-bar';
			if (v === '') { bar.style.width = '0'; if (txt) txt.textContent = rules; return; }
			var s = score(v);
			bar.classList.add(colors[s]);
			bar.style.width = ((s + 1) * 25) + '%';
			if (txt) txt.textContent = v.length < 10 ? bar.getAttribute('data-short') : (labels[s] || '');
			match();
		};
		var match = function () {
			if (!pw2) return;
			var bad = pw2.value !== '' && pw2.value !== pw.value;
			pw2.setCustomValidity(bad ? pw2.getAttribute('data-mismatch') || 'x' : '');
			pw2.classList.toggle('is-invalid', bad);
		};
		pw.addEventListener('input', update);
		if (pw2) pw2.addEventListener('input', match);
	}

	// ---------------------------------------------------------------- install: busy state (the import can take a minute)
	var form = $('#insInstallForm');
	if (form) {
		form.addEventListener('submit', function (e) {
			var btn = e.submitter;
			if (!btn || btn.id !== 'insInstall') return;
			setTimeout(function () {                 // after the form data (with the button's value) is taken
				btn.disabled = true;
				btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>';
				btn.appendChild(document.createTextNode(btn.getAttribute('data-busy') || '…'));
			}, 0);
		});
	}

	// administrator: with two-step login the e-mail is required – its star and the required attribute follow the switch (0.8.421)
	(function () {
		var tf = document.getElementById('twofa'), mail = document.getElementById('email'), star = document.getElementById('insEmailReq');
		if (!tf || !mail || !star) return;
		var sync = function () { star.hidden = !tf.checked; mail.required = tf.checked; };
		tf.addEventListener('change', sync);
		sync();
	})();
})();
