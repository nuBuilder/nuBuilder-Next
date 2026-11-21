/* Debug results: select / delete, expand long messages, copy, auto refresh (remembered per browser). */
(function () {
	'use strict';
	var all = document.getElementById('debugAll'), del = document.getElementById('debugDeleteSel');
	function sync() { if (del) del.disabled = !document.querySelector('.debugSel:checked'); }
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
