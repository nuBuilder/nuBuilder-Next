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

	// ---------------------------------------------------------------- content: a chosen dump file ticks "nubuilder4.sql …"; another choice clears it
	var up = $('#choice_up'), dumpFile = $('#dump'), dumpPick = $('#dump_pick');
	if (up) {
		var pickUp = function () { if ((dumpFile && dumpFile.value) || (dumpPick && dumpPick.value)) up.checked = true; };
		if (dumpFile) dumpFile.addEventListener('change', pickUp);
		if (dumpPick) dumpPick.addEventListener('change', pickUp);
		document.querySelectorAll('input[name="choice"]').forEach(function (r) {
			r.addEventListener('change', function () {
				if (r === up || !r.checked) return;
				if (dumpFile) dumpFile.value = '';
				if (dumpPick) dumpPick.value = '';
			});
		});
	}

	// ---------------------------------------------------------------- content: Check / Next with a dump – spinner on the button and a note
	// (uploading, unpacking and checking a large dump can take a minute or two; the page stays as it is until the answer comes)
	var cform = $('#insContentForm');
	if (cform && up) {
		cform.addEventListener('submit', function (e) {
			var btn = e.submitter;
			if (!btn || !up.checked || (btn.id !== 'insCheckDump' && btn.id !== 'insNext')) return;
			var text = cform.getAttribute(dumpFile && dumpFile.value ? 'data-busy-up' : 'data-busy-check') || '…';
			setTimeout(function () {                 // after the form data (with the button's value) is taken
				cform.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
				btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>';
				btn.appendChild(document.createTextNode(text));
				var note = document.createElement('div');
				note.className = 'alert alert-info d-flex align-items-center gap-3 mt-3 mb-0';
				note.id = 'insDumpBusy';
				note.setAttribute('role', 'status');
				note.innerHTML = '<span class="spinner-border spinner-border-sm flex-shrink-0" aria-hidden="true"></span>';
				var t = document.createElement('span');
				t.textContent = text + ' ' + (cform.getAttribute('data-busy-hint') || '');
				note.appendChild(t);
				var old = document.getElementById('insDumpBusy');
				if (old) old.remove();
				btn.closest('.input-group, .d-flex, div').after(note);
			}, 0);
		});
		// back to this page from the browser's history: buttons as they were
		window.addEventListener('pageshow', function (ev) { if (ev.persisted) location.reload(); });
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
		var busy = function (btn, text) {
			btn.disabled = true;
			btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>';
			btn.appendChild(document.createTextNode(text));
		};
		var stepped = false;
		form.addEventListener('submit', function (e) {
			var btn = e.submitter;
			if (!btn || btn.id !== 'insInstall') return;
			// a dump: imported in steps first (action import_slice, a few seconds each), then Install does the rest
			if (form.getAttribute('data-import-steps') === '1' && !stepped && window.fetch) {
				e.preventDefault();
				var label = form.getAttribute('data-import-text') || '%s %', tries = 0;
				busy(btn, label.replace('%s', '0'));
				var go = function () {
					var fd = new FormData(form);
					fd.set('action', 'import_slice');
					var fail = function (msg) {
						btn.disabled = false;
						btn.textContent = btn.getAttribute('data-busy') || '…';
						var box = document.createElement('div');
						box.className = 'alert alert-danger mt-3';
						box.setAttribute('role', 'alert');
						box.textContent = msg;
						form.parentNode.insertBefore(box, form);
					};
					fetch(form.getAttribute('action'), {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
						.then(function (r) { return r.json(); })
						.then(function (j) {
							tries = 0;
							if (!j.ok) { fail(j.error || 'error'); return; }
							var pct = j.size > 0 ? Math.min(99, Math.floor(j.off * 100 / j.size)) : 0;
							btn.lastChild.textContent = label.replace('%s', String(j.done ? 100 : pct));
							if (!j.done) { go(); return; }
							stepped = true;
							btn.disabled = false;
							form.requestSubmit(btn);
						}, function (err) {
							// no JSON (a step cut off by the server) or no connection: again after a pause – it goes on where the last step ended
							if (++tries <= 5) { setTimeout(go, 3000); return; }
							fail((err && err.message) || 'error');
						});
				};
				go();
				return;
			}
			setTimeout(function () { busy(btn, btn.getAttribute('data-busy') || '…'); }, 0);   // after the form data (with the button's value) is taken
		});
	}

	// administrator: with two-step login the e-mail is required – its star and the required attribute follow the switch
	(function () {
		var tf = document.getElementById('twofa'), mail = document.getElementById('email'), star = document.getElementById('insEmailReq');
		if (!tf || !mail || !star) return;
		var sync = function () { star.hidden = !tf.checked; mail.required = tf.checked; };
		tf.addEventListener('change', sync);
		sync();
	})();
})();
