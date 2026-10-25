/*
 * Data access of a form (0.8.480, pages/nudataaccess.php): "Add rule" clones the row template of a field / column, the subject field
 * follows the rule type (its list of access levels, users, permission items, teams or departments; none for "Everyone"), selects with
 * data-nu-autosubmit send their form, and the tab is kept in the address (#da_<tab>).
 */
(function () {
	'use strict';
	var counter = 0;

	function syncType(row) {
		var type = row.querySelector('.daType'), subj = row.querySelector('.daSubject');
		if (!type || !subj) return;
		subj.setAttribute('list', 'da_dl_' + type.value);
		subj.disabled = type.value === 'default' || type.disabled;
		if (type.value === 'default') subj.value = '';
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.daAdd');
		if (!btn) return;
		var box = btn.parentNode, tpl = box.querySelector('template.daTpl'), body = box.querySelector('table.daRules tbody');
		if (!tpl || !body) return;
		var html = tpl.innerHTML.replace(/__N__/g, 'n' + (++counter));
		var tmp = document.createElement('tbody');
		tmp.innerHTML = html;
		var row = tmp.firstElementChild;
		body.appendChild(row);
		syncType(row);
		var f = row.querySelector('select, input');
		if (f) f.focus();
		var form = btn.closest('form');
		if (form) form.dispatchEvent(new Event('change', {bubbles: true}));
	});

	document.addEventListener('change', function (e) {
		if (e.target.classList && e.target.classList.contains('daType')) syncType(e.target.closest('tr'));
		if (e.target.matches && e.target.matches('select[data-nu-autosubmit]') && e.target.form) e.target.form.submit();
	});

	document.querySelectorAll('tr.daRule').forEach(syncType);

	// tab in the address
	document.querySelectorAll('#daTabs [data-bs-toggle="tab"]').forEach(function (b) {
		b.addEventListener('shown.bs.tab', function () { try { history.replaceState(null, '', b.getAttribute('data-bs-target')); } catch (err) { /* ignore */ } });
	});
	var h = location.hash;
	if (/^#da_(fields|columns|level|preview)$/.test(h)) {
		var b = document.querySelector('#daTabs [data-bs-target="' + h + '"]');
		if (b && window.nuBs) nuBs.tab(b).show();
	}
})();
