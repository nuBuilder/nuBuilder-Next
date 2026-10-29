/* Permission items: users of an item (Tom Select, multiple). */
(function () {
	'use strict';
	var sel = document.getElementById('permUsers');
	if (!sel || !window.TomSelect) return;
	var ts = new TomSelect(sel, {
		plugins: ['remove_button', 'checkbox_options'], maxOptions: null, hidePlaceholder: true, closeAfterSelect: false,
		searchField: ['text', 'sub'],
		render: {
			option: function (d, esc) { return '<div><div>' + esc(d.text) + '</div>' + (d.sub ? '<div class="small text-body-secondary">' + esc(d.sub) + '</div>' : '') + '</div>'; },
			item: function (d, esc) { return '<div title="' + esc(d.sub || '') + '">' + esc(d.text) + '</div>'; }
		}
	});
	ts.on('change', function () {
		var f = sel.form;
		if (f) f.dispatchEvent(new Event('input', {bubbles: true}));      // marks the save button (nuSaveEdited)
	});
})();

/* Users that have the item (0.8.687): search and Active / Archived / All. */
(function () {
	'use strict';
	var ul = document.getElementById('permUserList'), q = document.getElementById('permUserSearch'), st = document.getElementById('permUserState');
	if (!ul) return;
	function apply() {
		var s = q ? q.value.trim().toLowerCase() : '', v = st ? ((st.querySelector('input:checked') || {}).value || 'active') : 'all', n = 0;
		Array.prototype.forEach.call(ul.querySelectorAll('li[data-st]'), function (li) {
			li.hidden = (v !== 'all' && li.getAttribute('data-st') !== v) || (s !== '' && (li.getAttribute('data-q') || '').indexOf(s) < 0);
			if (!li.hidden) n++;
		});
		var none = ul.querySelector('.permUserNone');
		if (none) none.hidden = n > 0;
	}
	if (q) q.addEventListener('input', apply);
	if (st) st.addEventListener('change', apply);
})();
