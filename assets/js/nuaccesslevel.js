/* Access level form (0.8.485): "Add form…" as a searchable Tom Select. The rights grid itself is in nuapp.js – it reacts to the
   select's change event (adds the row, empties the select); here the picked item is cleared again and forms already in the grid
   are greyed out whenever the list opens. */
(function () {
	'use strict';
	var sel = document.getElementById('addForm'), body = document.querySelector('.js-rights-body');
	if (!sel || !body || typeof TomSelect === 'undefined') return;
	var ts = new TomSelect(sel, {
		maxOptions: null, allowEmptyOption: false, hidePlaceholder: true, searchField: ['text', 'value'],
		dropdownParent: 'body', lockOptgroupOrder: true, dropdownClass: 'ts-dropdown nuAddFormDd'
	});
	ts.wrapper.classList.add('nuAddFormTs');
	var used = function () {
		Object.keys(ts.options).forEach(function (v) {
			if (v === '') return;
			ts.options[v].disabled = !!body.querySelector('tr[data-form="' + CSS.escape(v) + '"]');
		});
		ts.clearCache();
	};
	ts.on('focus', used);
	ts.on('dropdown_open', function () { used(); ts.refreshOptions(false); });
	ts.on('item_add', function () { window.setTimeout(function () { ts.clear(true); ts.blur(); }, 0); });
})();

/* "Use two-factor authentication" (0.8.511): the note on $nuConfig2FAUser (and its switch-on button) only while the switch is on */
(function () {
	'use strict';
	var sw = document.getElementById('sal_use_2fa'), note = document.getElementById('nu2faCfgNote');
	if (!sw || !note) return;
	function sync() { note.hidden = !sw.checked; }
	sw.addEventListener('change', sync);
	sync();
})();
