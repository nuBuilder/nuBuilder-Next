/* global nuBs */
/* nuBuilder Next: New form – how to start (0.8.848, pages/nubuilder.php ?new=1 without start=wizard). The last choice is marked
   (localStorage nunext-newform-last) and gets the focus. */
(function () {
	'use strict';
	var KEY = 'nunext-newform-last', last = '';
	try { last = localStorage.getItem(KEY) || ''; } catch (e) { /* storage off */ }
	var cards = document.querySelectorAll('.nuNfChoice[data-nf]');
	cards.forEach(function (c) {
		var k = c.getAttribute('data-nf');
		if (k === last) {
			c.classList.add('nuNfLast');
			var b = c.querySelector('.nuNfLastBadge');
			if (b) b.hidden = false;
			c.focus({preventScroll: true});
		}
		c.addEventListener('click', function () { try { localStorage.setItem(KEY, k); } catch (e) { /* storage off */ } });
	});
	// Custom page (0.8.861): a short dialogue – description, code (= folder custom/pages/<code>/), open mode – then the page's own editor
	var start = document.getElementById('nuNfStart'), dlg = document.getElementById('nuNfPageDlg'), pcard = document.getElementById('nuNfC_page');
	if (!start || !dlg || !pcard) return;
	var form = document.getElementById('nuNfPageForm'), desc = document.getElementById('nuNfPageDesc'), code = document.getElementById('nuNfPageCode');
	var err = document.getElementById('nuNfPageErr'), btn = document.getElementById('nuNfPageCreate'), touched = false;
	function show() { err.classList.add('d-none'); nuBs.modal(dlg).show(); }
	dlg.addEventListener('shown.bs.modal', function () { desc.focus(); });
	pcard.addEventListener('click', function (e) { e.preventDefault(); show(); });
	// the code follows the description until it is typed by hand
	code.addEventListener('input', function () { touched = code.value !== ''; code.classList.remove('is-invalid'); });
	desc.addEventListener('input', function () {
		desc.classList.remove('is-invalid');
		if (touched) return;
		code.value = desc.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 64);
	});
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var bad = false;
		if (!desc.value.trim()) { desc.classList.add('is-invalid'); bad = true; }
		if (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/.test(code.value)) { code.classList.add('is-invalid'); bad = true; }
		if (bad) return;
		btn.disabled = true;
		fetch(start.getAttribute('data-api'), {method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-CSRF-Token': start.getAttribute('data-csrf')},
			body: JSON.stringify({action: 'form.create', type: 'page', code: code.value, description: desc.value.trim(), page_open_mode: document.getElementById('nuNfPageMode').value})})
			.then(function (r) { return r.json().catch(function () { return {ok: false, error: 'HTTP ' + r.status}; }); })
			.then(function (j) {
				if (!j.ok) throw new Error(j.error || 'Error');
				try { localStorage.setItem(KEY, 'page'); } catch (x) { /* storage off */ }
				window.location.href = 'index.php?p=builder&f=' + encodeURIComponent(j.form_id);
			})
			.catch(function (x) { err.textContent = x.message; err.classList.remove('d-none'); btn.disabled = false; });
	});
	if (start.getAttribute('data-open') === 'page') show();
})();
