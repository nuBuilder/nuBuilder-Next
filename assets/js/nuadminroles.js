/* global nuBs */
/* Admin role form (0.8.739, own columns 0.8.1022): sortable rights table – by name, area, full access or the chosen level
   (None / View / Change: ticked first). The third click on a title goes back to the order of the groups. Kept per browser. */
(function () {
	'use strict';
	var table = document.getElementById('admRights');
	if (!table) return;
	var body = table.tBodies[0], KEY = 'nunext-adm-rights-sort';
	var original = Array.prototype.slice.call(body.children);
	var level = function (tr) { var c = tr.querySelector('input[type=radio]:checked'); return c ? c.value : ''; };
	var cmp = function (a, b) { return a.localeCompare(b, undefined, {sensitivity: 'base', numeric: true}); };
	function key(tr, col) {
		if (col === 'name') return tr.getAttribute('data-name') || '';
		if (col === 'area') return tr.getAttribute('data-area') || '';
		if (col === 'full') return tr.getAttribute('data-full') === '1' ? '0' : '1';   // full access first
		return level(tr) === col.slice(4) ? '0' : '1';           // lvl:<level> – rows with that level first
	}
	function apply(col, dir) {
		table.querySelectorAll('th.admSort').forEach(function (o) { o.removeAttribute('aria-sort'); });
		if (!col) {                                                // back to the order of the groups
			original.forEach(function (tr) { body.appendChild(tr); });
			return;
		}
		var rows = original.slice();
		rows.sort(function (a, b) {
			return cmp(key(a, col), key(b, col)) * (dir === 'desc' ? -1 : 1) || cmp(key(a, 'name'), key(b, 'name'));
		});
		rows.forEach(function (tr) { body.appendChild(tr); });
		var th = table.querySelector('th.admSort[data-sort="' + col + '"]');
		if (th) th.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
	}
	function store(v) { try { if (v) localStorage.setItem(KEY, v); else localStorage.removeItem(KEY); } catch (e) { /* no storage */ } }
	table.querySelectorAll('th.admSort').forEach(function (th) {
		th.querySelector('button').addEventListener('click', function () {
			var col = th.getAttribute('data-sort'), s = th.getAttribute('aria-sort');
			if (s === 'descending') { apply('', ''); store(''); return; }
			var dir = s === 'ascending' ? 'desc' : 'asc';
			apply(col, dir);
			store(col + '|' + dir);
		});
	});
	try {
		var keep = (localStorage.getItem(KEY) || '').split('|');
		if (keep[0] && table.querySelector('th.admSort[data-sort="' + keep[0] + '"]')) apply(keep[0], keep[1] === 'desc' ? 'desc' : 'asc');
	} catch (e) { /* no storage */ }
})();

/* Test the role as a user (0.8.1318): dialogue #admTestDlg – a user must be chosen, Full access asks first, unsaved changes of the role
   form are named (the test uses the saved role); ?test=1 opens it (row button of the Admin roles list). */
(function () {
	'use strict';
	var form = document.getElementById('admTestForm');
	if (!form) return;
	var dlg = document.getElementById('admTestDlg'), mode = document.getElementById('admTestMode'), sel = document.getElementById('admTestUser');
	var dirty = function () { var b = document.getElementById('admSave'); return !!(b && b.classList.contains('nuSaveEdited')); };
	dlg.addEventListener('show.bs.modal', function () { document.getElementById('admTestDirty').classList.toggle('d-none', !dirty()); });
	form.querySelectorAll('button[data-mode]').forEach(function (b) {
		b.addEventListener('click', function (e) {
			mode.value = b.getAttribute('data-mode');
			if (!sel.value) { e.preventDefault(); if (sel.tomselect) sel.tomselect.focus(); else sel.focus(); return; }
			if (mode.value !== 'full') return;
			e.preventDefault();
			window.nuConfirmAsk(form.getAttribute('data-full-confirm'), function () { form.submit(); }, form.getAttribute('data-full-ok'), 'btn btn-danger', {cancelFirst: true});
		});
	});
	var open = document.getElementById('admTestOpen');
	if (open && open.getAttribute('data-nu-autoopen') === '1') {
		var go = function () { if (window.nuBs) nuBs.modal(dlg).show(); };
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
	}
}());

/* Changed rights marked until saved (0.8.1323): rows whose level differs from the saved role (tr data-orig) get .admChanged and a
   "was: …" note; the header shows the count, "Show changed only" and Undo (back to the saved levels). Existing roles only. */
(function () {
	'use strict';
	var table = document.getElementById('admRights');
	if (!table || table.getAttribute('data-track') !== '1') return;
	var box = document.getElementById('admChanges'), count = document.getElementById('admChgCount'),
		only = document.getElementById('admChgOnly'), undo = document.getElementById('admChgUndo');
	var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-orig]'));
	var lvName = function (v) { return table.getAttribute('data-lv-' + (v || 'none')) || v; };
	var level = function (tr) { var c = tr.querySelector('input[type=radio]:checked'); return c ? c.value : ''; };
	var onlyOn = false;
	function mark() {
		var n = 0;
		rows.forEach(function (tr) {
			var was = tr.getAttribute('data-orig'), ch = level(tr) !== was, note = tr.querySelector('.admWas');
			tr.classList.toggle('admChanged', ch);
			tr.querySelectorAll('td.admLvlCell').forEach(function (td) {
				var r = td.querySelector('input[type=radio]');
				td.classList.toggle('admLvlNew', ch && !!r && r.checked);
				td.classList.toggle('admLvlOld', ch && !!r && r.value === was);
			});
			if (ch) {
				n++;
				if (!note) { note = document.createElement('span'); note.className = 'admWas'; tr.querySelector('td.admName').appendChild(note); }
				note.textContent = table.getAttribute('data-was').replace('%s', lvName(was));
			} else if (note) note.remove();
			tr.hidden = onlyOn && !ch;
		});
		box.hidden = n === 0;
		count.textContent = count.getAttribute('data-text').replace('%d', String(n));
		if (!n && onlyOn) setOnly(false);
	}
	function setOnly(v) {
		onlyOn = v;
		only.setAttribute('aria-pressed', v ? 'true' : 'false');
		only.classList.toggle('active', v);
		only.textContent = only.getAttribute(v ? 'data-on' : 'data-off');
		mark();
	}
	table.addEventListener('change', function (e) { if (e.target && e.target.type === 'radio') mark(); });
	only.addEventListener('click', function () { setOnly(!onlyOn); });
	undo.addEventListener('click', function () {
		rows.forEach(function (tr) {
			var was = tr.getAttribute('data-orig');
			tr.querySelectorAll('input[type=radio]').forEach(function (r) { r.checked = r.value === was; });
		});
		setOnly(false);
		var f = table.closest('form');
		if (f) f.dispatchEvent(new Event('change', {bubbles: true}));     // nuapp.js: Save colour / leave guard follow
	});
	mark();
}());
