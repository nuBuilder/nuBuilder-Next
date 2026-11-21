/* Access level form: "Add form…" as a searchable Tom Select. The rights grid itself is in nuapp.js – it reacts to the
   select's change event (adds the row, empties the select); here the picked item is cleared again and forms already in the grid
   are greyed out whenever the list opens. */
(function () {
	'use strict';
	// Users card: Active (default) · Archived · All
	// + search over login and name: every word must occur; Enter does not send the form, Esc clears
	var uf = document.getElementById('accUserFilter'), ul = document.getElementById('accUserList'), us = document.getElementById('accUserSearch');
	var apply = function () {
		var v = (uf.querySelector('input:checked') || {}).value || 'active', n = 0;
		var words = us ? us.value.toLowerCase().trim().split(/\s+/).filter(Boolean) : [];
		Array.prototype.forEach.call(ul.querySelectorAll('li[data-st]'), function (li) {
			var q = li.getAttribute('data-q') || '';
			li.hidden = (v !== 'all' && li.getAttribute('data-st') !== v) || words.some(function (w) { return q.indexOf(w) < 0; });
			if (!li.hidden) n++;
		});
		var none = ul.querySelector('.accUserNone');
		if (none) none.hidden = n > 0;
	};
	if (uf && ul) {
		uf.addEventListener('change', apply);
		if (us) {
			us.addEventListener('input', apply);
			us.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') e.preventDefault();
				else if (e.key === 'Escape' && us.value !== '') { e.preventDefault(); e.stopPropagation(); us.value = ''; apply(); }
			});
		}
	}
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

/* "Use two-factor authentication": the note on $nuConfig2FAUser (and its switch-on button) only while the switch is on */
(function () {
	'use strict';
	var sw = document.getElementById('sal_use_2fa'), note = document.getElementById('nu2faCfgNote');
	if (!sw || !note) return;
	function sync() { note.hidden = !sw.checked; }
	sw.addEventListener('change', sync);
	sync();
})();

// the button next to Home form opens the chosen form in a new tab (as the access level button of the user form)
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
	// no Home form chosen = the tile page: clearing the field switches the start page back to Tiles
	sel.addEventListener('change', function () {
		var tiles = document.getElementById('accStartTiles');
		if (sel.value === '' && tiles && !tiles.checked) { tiles.checked = true; tiles.dispatchEvent(new Event('change', {bubbles: true})); }
	});
	sync();
})();

// Form rights: sortable columns – form name, each right (ticked first), data mode / form type; the order is kept per browser
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
			nuStore.set(KEY, col + '|' + dir);
		});
	});
	try {
		var keep = (localStorage.getItem(KEY) || '').split('|');
		if (keep[0]) sortBy(keep[0], keep[1] === 'desc' ? 'desc' : 'asc');
	} catch (e) { /* no storage */ }
})();

// tab Start page: the note "users land on the Home form" follows the choice
(function () {
	'use strict';
	var note = document.getElementById('accStartFormNote'), form = document.getElementById('accStartForm'), tiles = document.getElementById('accStartTiles');
	if (!note || !form || !tiles) return;
	function sync() { note.hidden = !form.checked; }
	form.addEventListener('change', sync);
	tiles.addEventListener('change', sync);
	sync();
})();

// a new access level on a database from nuBuilder Forte: picking a Home form also picks "Home form" as start page, as in
// Forte – until the user chooses the start page himself
(function () {
	'use strict';
	var box = document.getElementById('accStartPage'), sel = document.getElementById('sal_zzzzsys_form_id');
	var form = document.getElementById('accStartForm'), tiles = document.getElementById('accStartTiles');
	if (!box || box.getAttribute('data-forte-new') !== '1' || !sel || !form || !tiles) return;
	var own = false;
	[form, tiles].forEach(function (r) { r.addEventListener('click', function () { own = true; }); });
	sel.addEventListener('change', function () {
		if (own) return;
		var on = sel.value !== '' && sel.value !== 'nuhome';
		(on ? form : tiles).checked = true;
		(on ? form : tiles).dispatchEvent(new Event('change', {bubbles: true}));
	});
})();
