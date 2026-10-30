/* Access level form (0.8.485): "Add form…" as a searchable Tom Select. The rights grid itself is in nuapp.js – it reacts to the
   select's change event (adds the row, empties the select); here the picked item is cleared again and forms already in the grid
   are greyed out whenever the list opens. */
(function () {
	'use strict';
	// Users card (0.8.583): Active (default) · Archived · All
	var uf = document.getElementById('accUserFilter'), ul = document.getElementById('accUserList');
	if (uf && ul) uf.addEventListener('change', function () {
		var v = (uf.querySelector('input:checked') || {}).value || 'active', n = 0;
		Array.prototype.forEach.call(ul.querySelectorAll('li[data-st]'), function (li) { li.hidden = v !== 'all' && li.getAttribute('data-st') !== v; if (!li.hidden) n++; });
		var none = ul.querySelector('.accUserNone');
		if (none) none.hidden = n > 0;
	});
})();
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

// the button next to Home form opens the chosen form in a new tab (0.8.693, as the access level button of the user form)
(function () {
	'use strict';
	var sel = document.getElementById('sal_zzzzsys_form_id'), link = document.getElementById('homeFormOpen');
	if (!sel || !link) return;
	function sync() {
		var id = sel.value, off = id === '';
		link.href = off ? '#' : link.getAttribute('data-url').replace('__ID__', encodeURIComponent(id));
		link.classList.toggle('disabled', off);
		if (off) { link.setAttribute('aria-disabled', 'true'); link.setAttribute('tabindex', '-1'); }
		else { link.removeAttribute('aria-disabled'); link.removeAttribute('tabindex'); }
	}
	sel.addEventListener('change', sync);
	sync();
})();

// Form rights: sortable columns (0.8.693) – form name, each right (ticked first), data mode / form type; the order is kept per browser
(function () {
	'use strict';
	var table = document.querySelector('table.nu-rights'), body = table && table.querySelector('.js-rights-body');
	if (!table || !body) return;
	var KEY = 'nunext-acc-rights-sort';
	function keyOf(tr, col) {
		if (col === 'form') { var l = tr.querySelector('.js-form-label'); return (l ? l.textContent : '').trim().toLowerCase(); }
		if (col.indexOf('r:') === 0) { var c = tr.querySelector('input[data-col="' + col.slice(2) + '"]'); return c && c.checked ? '0' : '1'; }
		var s = tr.querySelector('select.nuAccMode-' + col.slice(4));
		return s ? (s.options[s.selectedIndex] || {text: ''}).text.toLowerCase() : '';
	}
	function sortBy(col, dir) {
		var th = table.querySelector('th.accRightsSort[data-sort="' + col + '"]');
		if (!th) return;
		var empty = body.querySelector('.js-rights-empty');
		var rows = Array.prototype.filter.call(body.children, function (tr) { return tr.tagName === 'TR' && tr !== empty; });
		var cmp = function (a, b) { return a.localeCompare(b, undefined, {sensitivity: 'base', numeric: true}); };
		rows.sort(function (a, b) { return cmp(keyOf(a, col), keyOf(b, col)) * (dir === 'desc' ? -1 : 1) || cmp(keyOf(a, 'form'), keyOf(b, 'form')); });
		rows.forEach(function (tr) { body.insertBefore(tr, empty || null); });
		table.querySelectorAll('th.accRightsSort').forEach(function (o) { o.removeAttribute('aria-sort'); });
		th.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
	}
	table.querySelectorAll('th.accRightsSort').forEach(function (th) {
		th.querySelector('button').addEventListener('click', function () {
			var col = th.getAttribute('data-sort'), dir = th.getAttribute('aria-sort') === 'ascending' ? 'desc' : 'asc';
			sortBy(col, dir);
			try { localStorage.setItem(KEY, col + '|' + dir); } catch (e) { /* no storage */ }
		});
	});
	try {
		var keep = (localStorage.getItem(KEY) || '').split('|');
		if (keep[0]) sortBy(keep[0], keep[1] === 'desc' ? 'desc' : 'asc');
	} catch (e) { /* no storage */ }
})();
