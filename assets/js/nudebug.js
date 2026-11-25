/* global NuUtil */
/* Debug results: select / delete (no question – Undo instead), expand long messages, copy, auto refresh (remembered per browser). */
(function () {
	'use strict';
	var all = document.getElementById('debugAll'), delBtn = document.getElementById('debugDeleteSel');
	function sync() { if (delBtn) delBtn.disabled = !document.querySelector('.debugSel:checked'); }
	if (all) all.addEventListener('change', function () { document.querySelectorAll('.debugSel').forEach(function (c) { c.checked = all.checked; }); sync(); });
	document.querySelectorAll('.debugSel').forEach(function (c) { c.addEventListener('change', sync); });
	// every message folds after a few lines (as the Log's details), "more" / "less" below it – measured, so short ones stay as they are
	document.querySelectorAll('.debugMsg').forEach(function (pre) {
		pre.classList.add('debugCollapsed');
		if (pre.scrollHeight <= pre.clientHeight + 2) { pre.classList.remove('debugCollapsed'); return; }
		var b = document.createElement('button'), more = pre.getAttribute('data-more') || '…';
		b.type = 'button'; b.className = 'btn btn-sm btn-link px-0 debugMore'; b.textContent = more; b.setAttribute('aria-expanded', 'false');
		b.addEventListener('click', function () {
			var open = !pre.classList.toggle('debugCollapsed');
			b.textContent = open ? (pre.getAttribute('data-less') || '…') : more;
			b.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
		pre.insertAdjacentElement('afterend', b);
	});
	document.querySelectorAll('.debugCopy').forEach(function (b) {
		b.addEventListener('click', function () {
			var row = b.closest('.debugRow'), text = row.querySelector('.debugFirst').textContent + '\n' + row.querySelector('.debugMsg').textContent;
			if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { b.innerHTML = '<i class="bi bi-clipboard-check"></i>'; });
		});
	});
	// delete: × of a message, Delete selected, Delete all – at once, no question; the message offers Undo (core/nudebugpage.php json())
	var list = document.getElementById('debugList');
	function rowsGone(ids) {
		var rows = ids ? ids.map(function (id) { var b = document.querySelector('.debugDel[data-id="' + CSS.escape(id) + '"]'); return b && b.closest('.debugRow'); })
			: Array.prototype.slice.call(document.querySelectorAll('.debugRow'));
		rows.forEach(function (r) { if (r) r.remove(); });
		if (!document.querySelector('.debugRow')) {
			var lg = document.querySelector('#debugList .list-group');
			if (lg && !lg.querySelector('.debugNone')) lg.appendChild(NuUtil.el('div', {'class': 'list-group-item text-center text-body-secondary py-5 debugNone'}, list.getAttribute('data-empty') || ''));
			if (all) { all.checked = false; all.disabled = true; }
		}
		sync();
	}
	function send(fd) { fd.append('json', '1'); return NuUtil.post(location.pathname + location.search, fd); }
	function undo() {
		var fd = new FormData(); fd.append('action', 'undo');
		send(fd).then(function (j) {
			NuUtil.toast(j.message || '', !!j.ok);
			setTimeout(function () { location.reload(); }, 600);
		});
	}
	function del(ids, force) {
		var fd = new FormData();
		fd.append('action', ids ? 'delete_selected' : 'delete_all');
		(ids || []).forEach(function (id) { fd.append('ids[]', id); });
		if (force) fd.append('force', '1');
		send(fd).then(function (j) {
			if (j.confirm) {   // too much for Undo: ask once
				if (typeof window.nuConfirmAsk === 'function') window.nuConfirmAsk(j.message, function () { del(ids, true); }, null, 'btn btn-danger');
				return;
			}
			if (!j.ok) { NuUtil.toast(j.message || j.error || '', false); return; }
			rowsGone(ids);
			NuUtil.toast(j.message, true, j.undo ? {action: [list.getAttribute('data-undo') || 'Undo', undo], ms: 10000} : {});
		});
	}
	document.querySelectorAll('.debugDel').forEach(function (b) {
		b.addEventListener('click', function () { del([b.getAttribute('data-id')]); });
	});
	if (delBtn) delBtn.addEventListener('click', function (e) {
		e.preventDefault();
		var ids = Array.prototype.map.call(document.querySelectorAll('.debugSel:checked'), function (c) { return c.value; });
		if (ids.length) del(ids);
	});
	var allForm = document.getElementById('debugDelAllForm');
	if (allForm) {
		allForm.removeAttribute('data-confirm');   // the script deletes without asking (Undo); without it the form asks as before
		allForm.addEventListener('submit', function (e) { e.preventDefault(); e.stopImmediatePropagation(); del(null); }, true);
	}
	var auto = document.getElementById('debugAuto'), key = 'nuNextDebugAuto', timer = null;
	function plan() {
		clearTimeout(timer);
		if (auto.checked) timer = setTimeout(function () { if (!document.querySelector('.debugSel:checked')) window.location.reload(); else plan(); }, 5000);
	}
	if (auto) {
		try { auto.checked = localStorage.getItem(key) === '1'; } catch (e) { /* private mode */ }
		auto.addEventListener('change', function () { nuStore.set(key, auto.checked ? '1' : '0'); plan(); });
		plan();
	}
})();
