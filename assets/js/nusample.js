/* nuBuilder Next – Sample data page (0.8.742, pages/nusample.php): the list of samples selects the pane on the right without a reload
   (?s=<key> in the address, #smpSet_<key> of older links), passwords stay masked until "Show passwords", copy buttons. */
(function () {
	'use strict';
	var list = document.getElementById('smpList');
	if (!list) return;
	function select(key, push) {
		var pane = document.getElementById('smpSet_' + key);
		if (!pane) return false;
		document.querySelectorAll('.smpPane').forEach(function (p) { p.hidden = p !== pane; });
		list.querySelectorAll('[data-smp]').forEach(function (a) {
			var on = a.getAttribute('data-smp') === key;
			a.classList.toggle('active', on);
			a.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		if (push) {
			try { var u = new URL(location.href); u.searchParams.set('s', key); u.hash = ''; history.replaceState(null, '', u.toString()); } catch (x) { /* only the address */ }
		}
		return true;
	}
	list.addEventListener('click', function (e) {
		var a = e.target.closest('[data-smp]');
		if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
		if (select(a.getAttribute('data-smp'), true)) e.preventDefault();
	});
	list.addEventListener('keydown', function (e) {
		if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
		var items = Array.prototype.slice.call(list.querySelectorAll('[data-smp]'));
		var i = items.indexOf(document.activeElement);
		if (i < 0) return;
		e.preventDefault();
		var n = items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length];
		n.focus();
		select(n.getAttribute('data-smp'), true);
	});
	var m = /^#smpSet_([\w-]+)$/.exec(location.hash);
	if (m && !new URL(location.href).searchParams.get('s')) select(m[1], false);

	document.querySelectorAll('.smpPwToggle').forEach(function (b) {
		b.addEventListener('click', function () {
			var t = b.closest('.tab-pane').querySelector('.smpLogins');
			var masked = t.classList.toggle('smpPwMasked');
			b.querySelector('span').textContent = b.getAttribute(masked ? 'data-show' : 'data-hide');
			b.querySelector('i').className = 'bi ' + (masked ? 'bi-eye' : 'bi-eye-slash') + ' me-1';
		});
	});
	document.querySelectorAll('.smpPwCopy').forEach(function (b) {
		b.addEventListener('click', function () {
			var v = b.parentNode.querySelector('.smpPwVal');
			if (!v || !navigator.clipboard) return;
			navigator.clipboard.writeText(v.textContent.trim()).then(function () {
				var i = b.querySelector('i');
				i.className = 'bi bi-check2';
				b.title = b.getAttribute('data-copied');
				setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500);
			}, function () { /* no clipboard */ });
		});
	});
})();
