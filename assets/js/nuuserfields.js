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

/* Default (0.8.1074): Insert ▾ puts a keyword / template at the cursor; below the field an example of the worked-out value, checked by the server. */
(function () {
	'use strict';
	var inp = document.getElementById('ufDefault'), ex = document.getElementById('ufDefaultEx');
	if (!inp || !ex) return;
	var form = inp.form, timer = 0, seq = 0;
	document.querySelectorAll('.ufdIns').forEach(function (b) {
		b.addEventListener('click', function () {
			var t = b.getAttribute('data-ins'), v = inp.value;
			var dyn = /^(=|proc:|next:)/.test(t) || /^(today|yesterday|tomorrow|now|month_|year_)/.test(t);
			if (dyn || v === '') { inp.value = t; } else {
				var s = inp.selectionStart != null ? inp.selectionStart : v.length, e = inp.selectionEnd != null ? inp.selectionEnd : v.length;
				inp.value = v.slice(0, s) + t + v.slice(e);
			}
			inp.focus();
			var pos = t === 'next:{0000}' ? 5 : inp.value.length;
			try { inp.setSelectionRange(pos, pos); } catch (x) { /* type without selection */ }
			inp.dispatchEvent(new Event('input', {bubbles: true}));
		});
	});
	function val(id) { var el = document.getElementById(id); return el ? (el.getAttribute('data-type') || el.value || '') : ''; }
	function show() {
		var my = ++seq, d = inp.value.trim();
		if (d === '') { ex.textContent = ''; ex.className = 'form-text'; inp.classList.remove('is-invalid'); return; }
		var fd = new FormData();
		fd.append('action', 'default_preview');
		fd.append('default_value', d);
		fd.append('type', val('ufType'));
		fd.append('options', val('ufOptions'));
		fd.append('code', val('ufCode'));
		var tok = form && form.querySelector('input[name="_csrf"]');
		if (tok) fd.append('_csrf', tok.value);
		fetch(inp.getAttribute('data-preview'), {method: 'POST', body: fd, credentials: 'same-origin'})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (my !== seq) return;
				if (j.error) { ex.textContent = j.error; ex.className = 'form-text text-danger ufdErr'; inp.classList.add('is-invalid'); return; }
				inp.classList.remove('is-invalid');
				ex.className = 'form-text ufdOk';
				ex.textContent = j.note ? j.note : (j.kind === 'fixed' ? '' : '→ ' + (j.value === '' ? '∅' : j.value));
			})
			.catch(function () { /* offline: no example */ });
	}
	inp.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(show, 350); });
	var ty = document.getElementById('ufType');
	if (ty) ty.addEventListener('change', show);
	show();
})();
