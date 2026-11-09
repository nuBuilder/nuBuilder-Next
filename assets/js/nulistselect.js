/* List filters: searchable select (Tom Select) for <select class="nuListTomSelect">; options may carry data-sub (second line, also searched).
   Choosing a value fires "change" on the select, so .js-autosubmit submits the filter form as before. */
(function () {
	'use strict';
	if (!window.TomSelect) return;
	/** Searchable selects inside root (default: the page) – also for selects added later (window.nuListSelects, 0.8.758). */
	window.nuListSelects = function (root) { (root || document).querySelectorAll('select.nuListTomSelect').forEach(init); };
	function init(sel) {
		if (sel.tomselect) return;
		new TomSelect(sel, {
			maxOptions: null, allowEmptyOption: true, create: false, searchField: ['text', 'sub'],
			plugins: sel.multiple ? ['remove_button'] : (sel.hasAttribute('data-ts-search') ? ['dropdown_input'] : []), hidePlaceholder: sel.multiple,   // several values (0.8.633: Form with AI → tables)
			// data-ts-search (0.8.1050): a search field at the top of the open list (single selects in dialogues – plainly searchable);
			// a pick closes the list (0.8.1054 – the search field took the focus back and the list opened again over the next field)
			onItemAdd: sel.hasAttribute('data-ts-search') && !sel.multiple ? function () { this.close(); this.blur(); } : undefined,
			render: {
				option: function (d, esc) { return '<div><div>' + esc(d.text) + '</div>' + (d.sub ? '<div class="small text-body-secondary">' + esc(d.sub) + '</div>' : '') + '</div>'; },
				item: function (d, esc) { return '<div title="' + esc(d.sub || '') + '">' + esc(d.text) + '</div>'; }
			}
		});
	}
	window.nuListSelects(document);
})();
