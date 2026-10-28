/* List filters: searchable select (Tom Select) for <select class="nuListTomSelect">; options may carry data-sub (second line, also searched).
   Choosing a value fires "change" on the select, so .js-autosubmit submits the filter form as before. */
(function () {
	'use strict';
	if (!window.TomSelect) return;
	document.querySelectorAll('select.nuListTomSelect').forEach(function (sel) {
		if (sel.tomselect) return;
		new TomSelect(sel, {
			maxOptions: null, allowEmptyOption: true, create: false, searchField: ['text', 'sub'],
			plugins: sel.multiple ? ['remove_button'] : [], hidePlaceholder: sel.multiple,   // several values (0.8.633: Form with AI → tables)
			render: {
				option: function (d, esc) { return '<div><div>' + esc(d.text) + '</div>' + (d.sub ? '<div class="small text-body-secondary">' + esc(d.sub) + '</div>' : '') + '</div>'; },
				item: function (d, esc) { return '<div title="' + esc(d.sub || '') + '">' + esc(d.text) + '</div>'; }
			}
		});
	});
})();
