/* Debug results: select / delete, expand long messages, copy, auto refresh (remembered per browser). */
(function () {
	'use strict';
	var all = document.getElementById('debugAll'), del = document.getElementById('debugDeleteSel');
	function sync() { if (del) del.disabled = !document.querySelector('.debugSel:checked'); }
	if (all) all.addEventListener('change', function () { document.querySelectorAll('.debugSel').forEach(function (c) { c.checked = all.checked; }); sync(); });
	document.querySelectorAll('.debugSel').forEach(function (c) { c.addEventListener('change', sync); });
	document.querySelectorAll('.debugMore').forEach(function (b) {
		var more = b.textContent;
		b.addEventListener('click', function () {
			var pre = b.parentNode.querySelector('.debugMsg');
			pre.classList.toggle('debugCollapsed');
			b.textContent = pre.classList.contains('debugCollapsed') ? more : b.getAttribute('data-less');
		});
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
		auto.addEventListener('change', function () { try { localStorage.setItem(key, auto.checked ? '1' : '0'); } catch (e) { /* ignore */ } plan(); });
		plan();
	}
})();
