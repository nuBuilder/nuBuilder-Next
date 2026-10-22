/* Item editor: object lookup (Tom Select, server search), icon preview, remembered tab, delete confirmation. */
(function () {
	'use strict';
	var sel = document.getElementById('itm_object_id');
	if (sel && window.TomSelect) {
		new TomSelect(sel, {
			valueField: 'value', labelField: 'text', searchField: ['text', 'label'], maxOptions: 50, preload: 'focus', allowEmptyOption: true,
			load: function (q, cb) {
				fetch(sel.getAttribute('data-url') + encodeURIComponent(q), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(cb, function () { cb(); });
			},
			render: {
				option: function (d, esc) { return '<div><div>' + esc(d.text) + '</div>' + (d.label ? '<div class="small text-body-secondary">' + esc(d.label) + (d.type ? ' · ' + esc(d.type) : '') + '</div>' : '') + '</div>'; }
			}
		});
	}
	var icon = document.getElementById('itm_icon'), prev = document.getElementById('itemIconPreview');
	if (icon && prev) icon.addEventListener('input', function () { prev.innerHTML = ''; var i = document.createElement('i'); i.className = icon.value; prev.appendChild(i); });
	var tab = document.getElementById('itemTab');
	document.querySelectorAll('[data-tab]').forEach(function (b) { b.addEventListener('shown.bs.tab', function () { if (tab) tab.value = b.getAttribute('data-tab'); }); });
	document.querySelectorAll('[data-confirm-submit]').forEach(function (b) {
		b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm-submit'))) e.preventDefault(); });
	});
})();
