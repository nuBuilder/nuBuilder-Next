/* nuBuilder Next – Admin tools → Themes (pages/nuthemes.php, 0.8.632): colour swatches, import, and the editor's live preview –
 * the values go to the session (POST action=draft, JSON) and the preview iframe reloads its theme stylesheet (?p=brand&a=theme&t=draft). */
(function () {
	'use strict';
	document.querySelectorAll('.thSw[data-c]').forEach(function (el) { el.style.backgroundColor = el.getAttribute('data-c'); });
	var imp = document.getElementById('thImport');
	if (imp) imp.addEventListener('change', function () { if (imp.files && imp.files.length) imp.form.submit(); });

	var form = document.getElementById('thForm');
	if (!form) return;
	var frame = document.getElementById('thPreview'), warn = document.getElementById('thWarn');
	var mode = 'light', timer = null, seq = 0;
	var hexRe = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;

	function norm(v) {
		v = String(v || '').trim();
		if (!hexRe.test(v)) return '';
		v = v.replace('#', '');
		if (v.length === 3) v = v.split('').map(function (c) { return c + c; }).join('');
		return '#' + v.toLowerCase();
	}

	// colour rows: the picker writes the text field, the text field (empty = default) moves the picker
	form.querySelectorAll('.thColor').forEach(function (g) {
		var pick = g.querySelector('.thPick'), hex = g.querySelector('.thHex');
		var sync = function () { var n = norm(hex.value); hex.classList.toggle('is-invalid', hex.value.trim() !== '' && !n); if (n) pick.value = n; g.classList.toggle('thEmpty', !n); };
		pick.addEventListener('input', function () { hex.value = pick.value; sync(); changed(); });
		hex.addEventListener('input', function () { sync(); changed(); });
		hex.addEventListener('blur', function () { var n = norm(hex.value); if (n) hex.value = n; });
		sync();
	});
	form.querySelectorAll('.thVal').forEach(function (el) { el.addEventListener('change', changed); });

	function values() {
		var v = {}, d = {};
		form.querySelectorAll('[name^="v_"]').forEach(function (el) { v[el.name.slice(2)] = el.value; });
		form.querySelectorAll('[name^="d_"]').forEach(function (el) { d[el.name.slice(2)] = el.value; });
		return { v: v, d: d };
	}

	function reloadCss() {
		var doc = frame.contentDocument;
		if (!doc) return;
		var link = doc.getElementById('nuThemeCss');
		if (link) link.href = link.href.replace(/([?&]v=)[^&]*/, '$1' + Date.now());
		doc.documentElement.setAttribute('data-bs-theme', mode);
	}

	function send() {
		var my = ++seq;
		fetch(form.getAttribute('data-draft-url'), {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': form.getAttribute('data-csrf') },
			body: JSON.stringify(Object.assign({ action: 'draft' }, values()))
		}).then(function (r) { return r.json(); }).then(function (j) {
			if (my !== seq) return;
			reloadCss();
			warn.textContent = '';
			(j.warnings || []).forEach(function (w) {
				var p = document.createElement('div');
				p.className = 'text-warning-emphasis';
				var i = document.createElement('i'); i.className = 'bi bi-exclamation-triangle me-1';
				p.appendChild(i); p.appendChild(document.createTextNode(w.text));
				warn.appendChild(p);
			});
		}).catch(function () { /* the preview stays as it was */ });
	}

	function changed() { clearTimeout(timer); timer = setTimeout(send, 250); }

	document.querySelectorAll('[data-th-mode]').forEach(function (b) {
		b.addEventListener('click', function () {
			mode = b.getAttribute('data-th-mode');
			document.querySelectorAll('[data-th-mode]').forEach(function (x) { var on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
			if (frame.contentDocument) frame.contentDocument.documentElement.setAttribute('data-bs-theme', mode);
		});
	});
	frame.addEventListener('load', function () { if (frame.contentDocument) frame.contentDocument.documentElement.setAttribute('data-bs-theme', mode); send(); });
})();
