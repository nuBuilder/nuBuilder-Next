/* Inspect record (pages/nuinspect.php): sort, filter, search, copy, show masked values, expand long values / JSON. */
(function () {
	'use strict';
	var card = document.getElementById('inspCard');
	if (!card) return;
	var table = document.getElementById('inspTable'), tbody = table.tBodies[0], none = document.getElementById('inspNone');
	var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-pos]'));
	var search = document.getElementById('inspSearch'), filter = document.getElementById('inspFilter'), shown = document.getElementById('inspShown');
	var copied = card.getAttribute('data-copied') || 'Copied';

	// ------------------------------------------------------------------ filter + search
	function apply() {
		var q = (search.value || '').trim().toLowerCase(), f = filter.value, n = 0;
		rows.forEach(function (r) {
			var st = r.getAttribute('data-state');
			var ok = (f === 'all' || (f === 'filled' ? st === 'filled' : st !== 'filled'))
				&& (q === '' || r.getAttribute('data-name').indexOf(q) >= 0 || r.getAttribute('data-value').indexOf(q) >= 0 || r.getAttribute('data-type').indexOf(q) >= 0);
			r.classList.toggle('d-none', !ok);
			if (ok) n++;
		});
		none.classList.toggle('d-none', n > 0);
		shown.textContent = n + ' / ' + rows.length;
	}
	search.addEventListener('input', apply);
	filter.addEventListener('change', apply);
	try { var saved = localStorage.getItem('nuInspectFilter'); if (saved) filter.value = saved; } catch (e) { /* storage off */ }
	filter.addEventListener('change', function () { try { localStorage.setItem('nuInspectFilter', filter.value); } catch (e) { /* ignore */ } });

	// ------------------------------------------------------------------ sort (# · column · type · value), click again = reverse
	var collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
	table.querySelectorAll('th.inspSort').forEach(function (th) {
		th.querySelector('button').addEventListener('click', function () {
			var key = th.getAttribute('data-sort'), asc = th.getAttribute('aria-sort') !== 'ascending';
			table.querySelectorAll('th.inspSort').forEach(function (o) { o.removeAttribute('aria-sort'); });
			th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
			rows.sort(function (a, b) {
				var r;
				if (key === 'pos') r = Number(a.getAttribute('data-pos')) - Number(b.getAttribute('data-pos'));
				else if (key === 'value') {            // empty and NULL values last
					var ea = a.getAttribute('data-state') !== 'filled', eb = b.getAttribute('data-state') !== 'filled';
					if (ea !== eb) return ea ? 1 : -1;
					r = collator.compare(a.getAttribute('data-value'), b.getAttribute('data-value'));
				} else r = collator.compare(a.getAttribute('data-' + key), b.getAttribute('data-' + key));
				return asc ? r : -r;
			});
			rows.forEach(function (r) { tbody.insertBefore(r, none); });
		});
	});

	// ------------------------------------------------------------------ copy
	function copy(text, btn) {
		var done = function () {
			if (!btn) return;
			var icon = btn.querySelector('i'), old = icon ? icon.className : '';
			if (icon) icon.className = 'bi bi-check2';
			btn.setAttribute('title', copied);
			setTimeout(function () { if (icon) icon.className = old; btn.removeAttribute('title'); }, 1200);
		};
		if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
		else { fallback(text); done(); }
	}
	function fallback(text) {
		var ta = document.createElement('textarea');
		ta.value = text; ta.setAttribute('readonly', ''); ta.className = 'position-fixed top-0 start-0 opacity-0';
		document.body.appendChild(ta); ta.select();
		try { document.execCommand('copy'); } catch (e) { /* ignore */ }
		ta.remove();
	}
	tbody.addEventListener('click', function (e) {
		var b = e.target.closest('button');
		var row = e.target.closest('tr');
		if (b && b.classList.contains('inspCopy')) { var v = row.querySelector('.inspValue'); if (v) copy(v.getAttribute('data-raw') || v.textContent, b); return; }
		if (b && b.classList.contains('inspReveal')) {
			var m = row.querySelector('.inspValue'), hidden = m.classList.toggle('inspMasked');
			b.querySelector('i').className = hidden ? 'bi bi-eye' : 'bi bi-eye-slash';
			b.setAttribute('aria-label', card.getAttribute(hidden ? 'data-show' : 'data-hide'));
			b.setAttribute('title', card.getAttribute(hidden ? 'data-show' : 'data-hide'));
			return;
		}
		var val = e.target.closest('.inspValue');
		if (val && !val.classList.contains('inspMasked') && !window.getSelection().toString()) toggle(val);
	});
	tbody.addEventListener('keydown', function (e) {
		var val = e.target.closest('.inspValue');
		if (val && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); if (!val.classList.contains('inspMasked')) toggle(val); }
	});
	// long values: one line with … ; click / Enter shows all of it (JSON pretty printed)
	function toggle(val) {
		var open = val.classList.toggle('inspOpen');
		if (val.getAttribute('data-json')) {
			if (!val.hasAttribute('data-raw')) val.setAttribute('data-raw', val.textContent);
			var raw = val.getAttribute('data-raw');
			try { val.textContent = open ? JSON.stringify(JSON.parse(raw), null, 2) : raw; } catch (e) { val.textContent = raw; }
		}
	}

	var cj = document.getElementById('inspCopyJson');
	if (cj) cj.addEventListener('click', function () { copy(card.getAttribute('data-json') || '{}', cj); });

	apply();
})();
