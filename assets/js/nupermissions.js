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
