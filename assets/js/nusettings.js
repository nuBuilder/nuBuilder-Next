/* nuBuilder settings (pages/nusettings.php): filter, changed values, Save / Import state. */
(function () {
	'use strict';
	var table = document.getElementById('setTable');
	if (!table) return;
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
	var rows = $$('tbody tr[data-category]', table);
	var find = document.getElementById('setFind'), cat = document.getElementById('setCategory'), status = document.getElementById('setStatus');
	var statusValue = function () { var r = status.querySelector('input:checked'); return r ? r.value : ''; };
	var save = document.getElementById('setSave'), imp = document.getElementById('setImportBtn'), count = document.getElementById('setCount');

	// ---------------------------------------------------------------- filter (search in title, setting, value and description)
	function filter() {
		var words = (find.value || '').toLowerCase().split(/\s+/).filter(Boolean), c = cat.value, n = 0;
		rows.forEach(function (tr) {
			var field = tr.querySelector('.setValue');
			var text = (tr.textContent + ' ' + (field ? field.value : '')).toLowerCase();
			var show = (!c || tr.getAttribute('data-category') === c) && (!statusValue() || tr.getAttribute('data-status') === statusValue())
				&& words.every(function (w) { return text.indexOf(w) >= 0; });
			tr.hidden = !show;
			if (show) n++;
		});
		table.querySelector('.setNone').hidden = n > 0;
		count.textContent = count.getAttribute('data-of').replace('%s', n).replace('%s', rows.length);
	}
	[find, cat, status].forEach(function (el) { el.addEventListener(el === find ? 'input' : 'change', filter); });

	// ---------------------------------------------------------------- changed values: marked, Save red, Import only without changes
	var fields = $$('.setValue', table);
	function dirty() {
		var n = 0;
		fields.forEach(function (f) {
			var changed = f.value.trim() !== f.getAttribute('data-org');
			f.classList.toggle('setChanged', changed);
			if (changed) n++;
		});
		save.classList.toggle('nuSaveEdited', n > 0);                    // red while there are changes (assets/css/nuapp.css)
		imp.disabled = n > 0;
		return n;
	}
	fields.forEach(function (f) { f.addEventListener(f.tagName === 'SELECT' ? 'change' : 'input', dirty); });

	var submitting = false;
	document.getElementById('setForm').addEventListener('submit', function (e) {
		// only the changed values are sent (the fields belong to this form through form="setForm")
		e.preventDefault();
		var form = this;
		$$('input[data-copy]', form).forEach(function (i) { i.remove(); });
		fields.forEach(function (f) {
			if (f.value.trim() === f.getAttribute('data-org')) return;
			var i = document.createElement('input');
			i.type = 'hidden'; i.name = f.name; i.value = f.value; i.setAttribute('data-copy', '1');
			form.appendChild(i);
		});
		submitting = true;
		save.disabled = true;
		form.submit();
	});
	// leaving with changed values: Save / Discard / Keep editing (nuLeaveGuard in assets/js/nuapp.js, form[data-nu-guard])
	document.getElementById('setForm').nuGuardDirty = function () { return !submitting && dirty() > 0; };

	dirty();
	filter();
})();
